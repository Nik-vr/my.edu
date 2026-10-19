<?php

declare(strict_types=1);

/**
 * Единственная точка входа приложения.
 *
 * На сервере это должен быть index.php в каталоге public/ (public_html).
 * Шаблоны, конфигурация и код лежат уровнем выше и недоступны из браузера
 * благодаря тому, что корень сайта смотрит именно в public/.
 */

use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\ErrorHandler;
use MyEdu\Core\Request;
use MyEdu\Core\SchemaMigrator;
use MyEdu\Http\Kernel;
use MyEdu\Http\SchemaGate;

// Встроенный сервер PHP (используется только при локальной разработке):
// отдаём статические файлы как есть, всё остальное — в роутер.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    if (is_string($path) && $path !== '/' && $path !== '') {
        $candidate = __DIR__ . '/' . ltrim(rawurldecode($path), '/');

        // is_file внутри public/ — защита от выхода за пределы каталога
        // через ../ в URL.
        $real = realpath($candidate);
        $root = realpath(__DIR__);

        if ($real !== false && $root !== false && str_starts_with($real, $root) && is_file($real)) {
            return false;
        }
    }
}

$rootDir = dirname(__DIR__);

/*
 * Адреса, которые браузер запрашивает сам, без ссылки на странице: иконка,
 * robots.txt, иконки для iOS и Windows. Файлов для них в проекте нет, а
 * запрос приходит на каждый просмотр страницы.
 *
 * Если такой адрес не обслужил веб-сервер статикой, он попадает во front
 * controller и проходит весь путь приложения: сессия, схема базы, маршрутизация.
 * Это не только лишняя работа. Сессия берёт файл-блокировку, а страница держит
 * её до конца отрисовки — закрывать её никто не торопится, потому что
 * session_write_close() в проекте не вызывается ни разу. Под HTTP/2 такие
 * запросы идут параллельно по одному соединению, и ожидание блокировки
 * заканчивается обрывом потока: браузер показывает ERR_HTTP2_PROTOCOL_ERROR,
 * а повтор через пару секунд проходит, потому что блокировка уже снята.
 *
 * Отсюда и «через несколько секунд открывается корректно»: пострадавшая
 * страница успела дорисоваться и отпустить сессию.
 *
 * На быстрых страницах вроде входа ожидание не успевает стать заметным —
 * поэтому страдает именно панель, где страница рисуется долго.
 *
 * Список короткий и только из имён файлов: адрес с расширением не должен
 * превращаться в страницу приложения ни при каких настройках.
 */
$implicitAsset = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

if (in_array($implicitAsset, [
    'favicon.ico',
    'robots.txt',
    'sitemap.xml',
    'browserconfig.xml',
    'manifest.json',
    'site.webmanifest',
], true) || str_starts_with($implicitAsset, 'apple-touch-icon')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";

    return;
}

require $rootDir . '/src/Core/Autoloader.php';

// Загрузчик нужен до всего остального: без него не загрузится даже App.
MyEdu\Core\Autoloader::create($rootDir);

/*
 * Страховка от белого экрана ставится здесь — до загрузки конфигурации.
 *
 * Именно до неё и нужно: ошибается чаще всего `config.local.php`, который
 * читается внутри `App::boot()`. Обработчик, поставленный позже, такую ошибку
 * уже не увидит, а человек вместо причины получит пустую страницу.
 */
ErrorHandler::register($rootDir . '/var/log/error.log');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Требуется PHP 8.1 или новее. Текущая версия: ' . PHP_VERSION);
}

// Проверяем требования заранее: на хостинге с отключённым расширением
// иначе ошибка всплыла бы в неожиданном месте.
$missing = [];

if (!extension_loaded('pdo_mysql')) {
    $missing[] = 'расширение pdo_mysql';
}

if (!extension_loaded('mbstring')) {
    $missing[] = 'расширение mbstring';
}

if ($missing !== []) {
    http_response_code(500);
    exit(
        "Приложению не хватает:\n - " . implode("\n - ", $missing)
        . "\n\nВключите их в настройках PHP в панели управления хостингом."
    );
}

$app = App::boot($rootDir);

/*
 * Кому можно верить насчёт адреса клиента.
 *
 * Обратный прокси не является клиентом, и без доверия к нему все, кто пришёл
 * через него, делят один адрес — а вместе с ним лимиты на неудачные входы.
 * Список пуст по умолчанию: `X-Forwarded-For` подделывается без труда, и
 * читать его без явного разрешения владельца нельзя.
 */
Request::setTrustedProxies($app->config()->array('security.trusted_proxies'));

$request = Request::fromGlobals($app->basePath());

/*
 * Структура базы приводится к новой версии здесь, а не отдельной командой.
 *
 * Выкатка на шаред-хостинг — это копирование файлов по FTP: консоли у владельца
 * нет, и забытый `database/migrate.php` означал бы падение каждой страницы с
 * «Table ... doesn't exist» без всякой подсказки. Здесь та же логика, что и в
 * команде, — класс общий, — но молчаливого отказа нет: если применить не
 * удалось, страница объясняет это и пишет подробности в var/log/schema.log.
 *
 * На пустой базе это не сработает, и правильно: первую установку делает
 * install.php импортом schema.sql. Миграций на диске сейчас нет, и когда они
 * появятся, номера у них будут начинаться с 001.
 */
if ($app->config()->bool('schema.auto_migrate', true)) {
    $gate = new SchemaGate(
        /*
         * Сервис копии передан именованным аргументом: он последний в списке
         * необязательных, и передавать вместо него заглушки значило бы
         * повторять умолчания журнала и блокировки.
         */
        new SchemaMigrator(
            $app->get(Database::class),
            $rootDir . '/database/migrations',
            backup: $app->get(DatabaseDump::class)
        ),
        $request,
        $rootDir . '/var/log/schema.log'
    );

    $blocked = $gate->check();

    if ($blocked !== null) {
        $blocked->send();

        return;
    }
}

$kernel = new Kernel($app);

// Базовый путь передаётся явно: без него адрес вида /public/login остался бы
// для роутера таким, каким пришёл, и поиск маршрута закончился бы 404.
$kernel->handle($request)->send();