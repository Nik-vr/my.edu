<?php

declare(strict_types=1);

/**
 * Диагностика входа и почты на боевом сервере.
 *
* Одноразовая: показывает состояние и удаляет себя. Загрузите в тот же
 * `public/`, откройте один раз с ключом и удалите руками, если не удалился сам.
 *
 * Что выясняет. Вход не проходит по одной из четырёх причин, и по странице их
 * не различить — почти все дают один и тот же отказ:
 *
 *   1. запрос не доходит до приложения: `SchemaGate` отвечает своей страницей
 *      миграции ещё до разбора маршрута, поэтому не работают ни вход, ни
 *      восстановление пароля, а форма восстановления сообщает об успехе, которого
 *      не было;
 *   2. учётная запись заблокирована: `findRowByIdentifier()` исключает
 *      `status = 'blocked'`, и такой пользователь выглядит как человек с
 *      неверным паролем;
 *   3. вход заблокирован счётчиком попыток: `login_throttle` считает неудачи по
 *      паре «логин + адрес», и после порога верный пароль тоже не принимается;
 *   4. почта не уходит: драйвер `mail` зовёт `mail()`, которая на части
 *      хостингов возвращает false, а форма восстановления отвечает одинаково
 *      независимо от того, ушло письмо или нет.
 *
 * Пароль не спрашивает и не проверяет: в отчёте о нём нет нужды, а лишний след
 * на сервере — риск. Про хэш сообщается только то, что и так видно в панели:
 * алгоритм, стоимость и длина. Этого хватает, чтобы отличить рабочий хэш от
 * обрезанного или отданного в неподдерживаемом виде.
 *
 * КЛЮЧ ДОСТУПА СВЕРЯЕТСЯ С СЕКРЕТОМ ИЗ КОНФИГУРАЦИИ.
 *
 * Раньше проверялось только «есть ли параметр», и этого хватало, чтобы страницу
 * открыл любой, кто дописал к адресу `&key=1`: она показывает состояние учётной
 * записи по её адресу, запускает миграции и умеет отправлять письмо.
 *
 * Ключ задаётся в `config/config.local.php`:
 *
 *     'diagnostics' => ['key' => 'длинный случайный набор знаков'],
 *
 * Пока ключ не задан, страница отвечает 403 любому запросу, включая пустой.
 * Пустое значение секретом не считается: забытый `key => ''` открыл бы страницу
 * всем, кто пришлёт пустую строку.
 *
 * Из обычного запроса страница ничего не меняет: миграции не применяет, письмо
 * не отправляет. Миграции и так применяются на каждом обращении к сайту
 * (`SchemaGate`), а письмо уходит только по явному `&send=1`.
 *
 * На localhost файл не удаляется: иначе инструмент невозможно проверить в
 * разработке, а проверять его надо именно до отправки на сервер.
 */

$rootDir = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once $rootDir . '/src/Core/Config.php';

    $expectedKey = (string) MyEdu\Core\Config::load($rootDir . '/config')->string('diagnostics.key', '');
    $givenKey = (string) ($_GET['key'] ?? '');

    if ($expectedKey === '' || $givenKey === '' || !hash_equals($expectedKey, $givenKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');

        exit(
            "Нужен ключ диагностики.\n"
            . "Задайте его в config/config.local.php:\n"
            . "    'diagnostics' => ['key' => 'длинный случайный набор знаков'],\n"
            . "и откройте адрес вида check_login.php?key=ваш_ключ\n"
        );
    }
}

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\MailerInterface;
use MyEdu\Core\MailMessage;
use MyEdu\Core\SchemaMigrator;

$report = [];
$problems = [];

/**
 * Строка отчёта. Пометка означает проблему: то, чего не видно, диагностикой не
 * считается.
 */
function line(string $text, bool $bad = false): void
{
    global $report, $problems;

    if ($bad) {
        $problems[] = $text;
    }

    $report[] = [$bad ? 'bad' : 'ok', $text];
}

$escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// С localhost скрипт себя не удаляет — см. шапку файла.
$isLoopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

// -------------------------------------------------------- состояние схемы

/*
 * Схема только читается: миграции не применяются.
 *
 * Раньше здесь стоял `SchemaMigrator::apply()`, то есть страница, открытая из
 * браузера, могла изменить базу — и делала это до всякой проверки ключа, если
 * ключ не был задан. Применять миграции незачем и здесь: то же самое делает
 * `SchemaGate` на каждом обращении к сайту, и если миграция не применилась, то
 * сайт всё равно не отвечает — это и видно по строке ниже.
 */
$app = null;

try {
    $app = App::boot($rootDir);
    $db = $app->get(Database::class);
    $config = $app->config();

    $state = (new SchemaMigrator($db, $rootDir . '/database/migrations'))->status();

    if (!$state['schemaExists']) {
        line('В базе нет таблиц: нужна установка через install.php.', true);
    } elseif ($state['pending'] !== []) {
        line('Неприменённых миграций: ' . count($state['pending'])
            . '. Они применятся при первом обращении к сайту.', true);
    } elseif ($state['stale'] !== []) {
        line('В журнале остались записи об удалённых миграциях: '
            . count($state['stale']) . '. Уберутся при следующем обращении к сайту.');
    } else {
        line('Схема базы соответствует снимку, неприменённых миграций нет.');
    }

    foreach ($state['missing'] as $what) {
        line('База отстаёт от снимка схемы: ' . $what . '.', true);
    }
} catch (Throwable $e) {
    $db = null;
    $config = null;
    line('Приложение не запускается: ' . $e->getMessage(), true);
}

// ----------------------------------------------------------- учётная запись

if ($db !== null) {
    $email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';

    if ($email === '') {
        line('Учётная запись не проверялась: добавьте к адресу &email=ваш_адрес', true);
    } else {
        $row = $db->one(
            'SELECT id, login, email, status, is_owner, email_verified_at, password_hash
               FROM users WHERE email = :e OR login = :l LIMIT 1',
            ['e' => mb_strtolower($email, 'UTF-8'), 'l' => $email]
        );

        if ($row === null) {
            line('Учётной записи с таким адресом нет. Проверьте адрес и раскладку.', true);
        } else {
            line('Учётная запись найдена: #' . (int) $row['id']
                . ', владелец: ' . ((int) $row['is_owner'] === 1 ? 'да' : 'нет') . '.');

            if ((string) $row['status'] === 'blocked') {
                line('Запись заблокирована (status = blocked). Вход невозможен, хотя выглядит '
                    . 'как неверный пароль. Снять блокировку: UPDATE users SET status = '
                    . "'active' WHERE id = " . (int) $row['id'] . ';', true);
            } elseif ((string) $row['status'] === 'pending') {
                line('Адрес не подтверждён (status = pending): сначала подтвердите почту.', true);
            } else {
                line('Статус записи: ' . (string) $row['status'] . ' — вход разрешён.');
            }

            if ((string) ($row['email_verified_at'] ?? '') === '') {
                line('Адрес не подтверждён: поле email_verified_at пустое.', true);
            }

            line('В базе: login = ' . $escape((string) ($row['login'] ?? ''))
                . ', email = ' . $escape((string) ($row['email'] ?? '')));

            /*
             * Регистр адреса. `findRowByIdentifier()` сравнивает как есть, а
             * `findRowByEmail()` приводит к нижнему регистру: при сравнении без
             * учёта регистра это неважно, при сравнении с учётом — вход перестаёт
             * работать от одной раскладки. Расхождение стоит увидеть заранее.
             */
            $stored = (string) ($row['email'] ?? '');

            if ($stored !== '' && $stored !== mb_strtolower($stored, 'UTF-8')) {
                line('В базе адрес записан с заглавными буквами. Чтобы вход не зависел от '
                    . 'регистра, приведите к нижнему: UPDATE users SET email = '
                    . $escape(mb_strtolower($stored, 'UTF-8')) . ' WHERE id = ' . (int) $row['id'] . ';', true);
            }

            $hash = (string) $row['password_hash'];
            $info = password_get_info($hash);

            line('Хэш пароля: алгоритм ' . ($info['algoName'] ?? 'неизвестен')
                . ', стоимость ' . ($info['options']['cost'] ?? '—')
                . ', длина ' . mb_strlen($hash) . ' символов.');

            if (preg_match('/^\$(2[axy]|argon2i?d?)\$/', $hash) !== 1) {
                line('Хэш не похож на пароль PHP: скорее всего обрезан или записан вручную. '
                    . 'Восстановите пароль администратором.', true);
            }
        }
    }

    // ------------------------------------------------------ счётчик попыток

    $throttled = (int) $db->value('SELECT COUNT(*) FROM login_throttle');

    if ($throttled > 0) {
        line('В login_throttle строк: ' . $throttled . '.', true);

        foreach ($db->all(
            'SELECT failures, first_failed_at, last_failed_at FROM login_throttle
              ORDER BY failures DESC LIMIT 5'
        ) as $item) {
            line('  неудач: ' . (int) $item['failures']
                . ', первая ' . (string) $item['first_failed_at']
                . ', последняя ' . (string) $item['last_failed_at']);
        }

        line('Пока в счётчике есть строки, верный пароль может не приниматься. '
            . 'Сбросить: TRUNCATE TABLE login_throttle;', true);
    } else {
        line('Счётчик попыток входа пуст — вход не заблокирован.');
    }
}

// ------------------------------------------------------------------- почта

if ($config !== null) {
    $driver = (string) $config->string('mail.driver', 'log');

    if ($driver === 'log') {
        line('Драйвер почты: log. Письма только пишутся в var/log/mail-*.log и не отправляются. '
            . 'Для боевого сервера нужен mail или smtp.', true);
    } else {
        line('Драйвер почты: ' . $driver . '.');
    }

    $from = (string) $config->string('mail.from_email', '');

    if ($from === '') {
        line('Адрес отправителя mail.from_email НЕ ЗАДАН — mail() без него письма не отправляет.', true);
    } else {
        line('Адрес отправителя: ' . $from);

        $probe = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
        $probe = filter_var($probe, FILTER_VALIDATE_EMAIL) === false ? '' : $probe;

        /*
         * Письмо отправляется только по явному `&send=1`.
         *
         * Раньше проверочное письмо уходило при каждом открытии страницы с
         * адресом в параметре. Страница одноразовая и удаляет себя, но между
         * открытием и удалением её успевает загрузить предпросмотр ссылки в
         * почтовом клиенте или расширение браузера — и письмо уходит дважды,
         * уже без всякой диагностики на руках.
         */
        $wantsSend = ($_GET['send'] ?? '') === '1';

        if ($driver === 'log' || $probe === '') {
            line('Проверка отправки не выполнялась: добавьте &email=ваш_адрес.');
        } elseif (!$wantsSend) {
            line('Проверочное письмо не отправлялось. Добавьте &send=1, чтобы отправить: '
                . 'check_login.php?key=ваш_ключ&email=' . rawurlencode($probe) . '&send=1');
        } else {
            try {
                $sent = $app->get(MailerInterface::class)->send(new MailMessage(
                    $probe,
                    'Проверка почты my.edu',
                    "Это проверка отправки писем с вашего сервера.\n\nЕсли письмо пришло — почта работает."
                ));

                line('Проверочное письмо на ' . $probe . ': ' . ($sent
                    ? 'принято почтовым сервером.'
                    : 'НЕ ПРИНЯТО, причина в журнале ошибок PHP.'), !$sent);

                if (!$sent) {
                    line('Причина отказа записана в журнал ошибок PHP (error_log) на сервере.', true);

                    if ($driver === 'mail') {
                        line('Частая причина на шаред-хостинге — запрещён пятый параметр mail(). '
                            . 'Попробуйте в config/config.local.php: \'envelope_from\' => false.', true);
                    } elseif ($driver === 'smtp') {
                        line('Проверьте настройки smtp: хост, порт, логин и пароль, а также что '
                            . 'сервер исходящей почты не требует TLS на указанном порту.', true);
                    }
                }
            } catch (Throwable $e) {
                line('Отправка упала с ошибкой: ' . $e->getMessage(), true);
            }
        }
    }
}

// ------------------------------------------------------- копия базы перед миграцией

/*
 * Ответ на вопрос «снимется ли копия перед миграцией на этом сервере».
 *
 * Проверка идёт тем же кодом, что и сама копия (`DatabaseDump`), поэтому
 * результат здесь — не предположение о хостинге, а фактический ответ: те же
 * имена, те же каталоги, тот же порядок поиска. Иначе страница сообщала бы
 * «всё в порядке», а миграция потом тихо осталась бы без точки отката.
 *
 * Здесь важно не то, что утилита найдена (с этим всё просто), а то, что при
 * её отсутствии видно, где искали: иначе человек получает отказ и не знает,
 * куда посмотреть.
 */
if ($app !== null) {
    $dumpTools = new DatabaseDump($app, $app->config(), null, blocking: false);
    $found = $dumpTools->dumpBinary();

    if ($found !== null) {
        line('Утилита снятия копии базы найдена: ' . $found . '. Перед изменением схемы копия снимется автоматически.');
    } else {
        /*
         * Отсутствие утилиты — не поломка: копия снимается средствами PHP. Но
         * сказать об этом стоит прямо, потому что способ медленнее и это видно
         * по времени открытия страницы в момент выкатки.
         */
        line('Утилиты mariadb-dump на сервере нет. Копия базы перед изменением схемы всё равно снимется — средствами PHP, медленнее.', true);
        line('Проверенные места:');

        foreach ($dumpTools->searchedIn() as $where => $what) {
            line('  ' . $where . ': ' . ($what === [] ? 'ничего' : implode(', ', $what)), true);
        }

        line('Если утилита всё-таки есть, укажите её путь в окружении сайта: MYSQLDUMP=/полный/путь/mariadb-dump — тогда копия пойдёт быстрее.', true);
    }
}

// ------------------------------------------------------------- удаление файла

$removed = false;

if (PHP_SAPI !== 'cli' && !$isLoopback) {
    $removed = @unlink(__FILE__);
}

// -------------------------------------------------------------------- вывод

$html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Проверка входа</title><style>'
    . 'body{font:15px/1.6 system-ui,sans-serif;margin:2rem auto;max-width:60rem;padding:0 1rem;color:#1b1b1b}'
    . 'h1{font-size:1.3rem}ul{padding-left:1.2rem}li{margin:.35rem 0}'
    . '.bad{color:#a00;font-weight:600}.ok{color:#333}code{background:#f2f2f2;padding:.1em .35em}'
    . '.warn{background:#fff4e5;border:1px solid #e0b070;padding:.8rem;margin:1rem 0}'
    . '</style></head><body><h1>Проверка входа и почты</h1>';

if ($problems !== []) {
    $html .= '<p class="warn"><strong>Найдено проблем: ' . count($problems) . '.</strong> '
        . 'Их причины перечислены ниже.</p>';
}

$html .= '<ul>';

foreach ($report as [$kind, $text]) {
    $html .= '<li class="' . $kind . '">' . $escape($text) . '</li>';
}

$html .= '</ul>';

if (PHP_SAPI !== 'cli') {
    $html .= '<p class="warn"><strong>' . ($removed
        ? 'Этот файл удалён автоматически.'
        : ($isLoopback
            ? 'Файл оставлен: запрос с localhost. Удалите <code>check_login.php</code> с сервера вручную.'
            : 'Удалите <code>check_login.php</code> из каталога <code>public/</code> вручную.'))
        . '</strong></p>';
}

$html .= '</body></html>';

header('Content-Type: text/html; charset=utf-8');
echo $html;