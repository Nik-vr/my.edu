<?php

declare(strict_types=1);

/**
 * Проверка правил интерфейса: какие способы показать сообщение в проекте
 * запрещены.
 *
 * Запуск: php tests/run_ui_rules_tests.php
 *
 * Зачем это отдельным набором. Пять способов показать одну ошибку не
 * появляются за один день: каждая приходит из своего места — форма,
 * модальное окно, тост, баннер, нативное окно браузера, — и пока их не
 * видно сразу, новые добавляются молча. Ревью 2026-10-18 нашло два нативных
 * `window.confirm`, пять мест с двойным баннером и две разные разметки ошибки
 * поля; все они выглядят нелепо по отдельности, и все три заметны только
 * поиском по проекту.
 *
 * Как это работает с уже существующими нарушениями. Правило не может начать
 * с красного: иначе им перестанут пользоваться в тот же день. Поэтому у
 * каждого правила есть список известных нарушений — с файлом и строкой. Новое
 * нарушение валит набор, а исправление старого требует убрать строку из
 * списка: список уменьшается вместе с долгом, а не тает молча.
 */

require __DIR__ . '/support/TestKit.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$runner = new TestRunner();

/*
 * Ядро нужно для настоящей разметки хуков: правило про подпись поля
 * проверяет не текст исходника, а то, что поле на самом деле рисует.
 */
require $root . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $root . '/src');
$autoloader->register();

/**
 * Все файлы проекта по расширениям, с путями через прямой слэш.
 *
 * @param list<string> $dirs
 * @param list<string> $exts
 * @return array<string, string> «путь» → содержимое
 */
function readFiles(string $root, array $dirs, array $exts): array
{
    $files = [];

    foreach ($dirs as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

        foreach ($rii as $f) {
            if (!$f->isFile() || !in_array(strtolower((string) $f->getExtension()), $exts, true)) {
                continue;
            }

            $path = str_replace('\\', '/', (string) $f->getPathname());
            $files[str_replace($root . '/', '', $path)] = (string) file_get_contents($path);
        }
    }

    ksort($files);

    return $files;
}

/**
 * Найти вхождения с номером строки.
 *
 * @return list<array{0: string, 1: int, 2: string}> «путь», строка, текст строки
 */
function findAll(array $files, string $pattern): array
{
    $found = [];

    foreach ($files as $path => $src) {
        if (preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($m[0] as $hit) {
            $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
            $lines = explode("\n", $src);
            $found[] = [$path, $line, trim($lines[$line - 1] ?? '')];
        }
    }

    return $found;
}

/**
 * Сравнить найденное с известным и сказать, что именно нового.
 *
 * @param list<array{0: string, 1: int, 2: string}> $found
 * @param array<string, string> $known «путь:строка» → почему это известно
 * @return list<array{0: string, 1: int, 2: string}>
 */
function newOnes(array $found, array $known): array
{
    $new = [];

    foreach ($found as $hit) {
        $key = $hit[0] . ':' . $hit[1];

        if (!isset($known[$key])) {
            $new[] = $hit;
        }
    }

    return $new;
}

/**
 * Убрать комментарии из CSS.
 *
 * Иначе правило ищется в тексте, который это правило описывает: строка
 * «значения живут в правиле [data-theme="dark"]» выглядит как само правило, и
 * проверка радостно сообщает, что тёмная тема объявлена верно.
 */
function stripCssComments(string $css): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', $css);
}

/**
 * Убрать комментарии из JavaScript, сохранив номера строк.
 *
 * Причина та же, что у CSS: правило ищется в тексте, который сам это правило
 * описывает. После исправления M1 слова «window.confirm» остались в комментариях
 * — там они и должны остаться, объясняя, почему вызов запрещён, — но проверка
 * видела в них нарушение и требовала вычистить комментарии вместо кода.
 *
 * Комментарии заменяются пробелами, а не удаляются: номера строк в отчёте о
 * нарушении должны указывать на настоящее место.
 */
function stripJsComments(string $src): string
{
    $blank = static fn (string $chunk): string => (string) preg_replace('/[^\n]/', ' ', $chunk);

    $src = (string) preg_replace_callback('#/\*.*?\*/#s', static fn (array $m): string => $blank($m[0]), $src);

    // `//` не считается комментарием после двоеточия и после слэша: так выглядят
    // `https://` в строке и `//` в регулярном выражении внутри скрипта.
    return (string) preg_replace_callback(
        '#(^|[^:"\'\\\\])//[^\n]*#m',
        static fn (array $m): string => $m[1] . $blank(substr($m[0], strlen($m[1]))),
        $src
    );
}

/**
 * Текст функции по имени.
 *
 * Ищется открывающая скобка и считаются парные скобки до конца тела: так
 * проверка не зависит от отступа и не обрывается на первой же внутренней
 * скобке, из-за чего она молча проверяла бы не то место.
 */
function functionBody(string $src, string $needle): string
{
    $start = strpos($src, $needle);

    if ($start === false) {
        return '';
    }

    $open = strpos($src, '{', $start);

    if ($open === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($src);

    for ($i = $open; $i < $length; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

/**
 * @param list<array{0: string, 1: int, 2: string}> $hits
 * @return string
 */
function describeHits(array $hits): string
{
    return implode(
        "\n      ",
        array_map(static fn (array $h): string => $h[0] . ':' . $h[1] . ' — ' . $h[2], array_slice($hits, 0, 10))
    );
}

$js = readFiles($root, ['public/assets/js'], ['js']);
$jsCode = array_map(static fn (string $src): string => stripJsComments($src), $js);
$templates = readFiles($root, ['templates', 'plugins'], ['php']);
$scripts = readFiles($root, ['tools'], ['ps1', 'php']);

// ---------------------------------------------------------------------------
// 1. Нативное окно подтверждения браузера.
//
// Проектный модальный диалог и `window.confirm` выглядят совершенно
// по-разному, и ученик на одном экране видит оба. Подтверждение «завершить
// работу» вообще необратимо, поэтому оно обязано выглядеть как часть
// интерфейса.
//
// Пункт M1 ревью 2026-10-18. Список известных нарушений опустел: оба вызова
// переведены на окно сайта.
$runner->group('Правила интерфейса: подтверждения');

$known = [];

$found = findAll($jsCode, '/window\.confirm|(?<![\w.])confirm\(/');
$new = newOnes($found, $known);

$runner->equals(
    [],
    $new,
    'нативных window.confirm больше нет: ' . (count($found) > 0 ? describeHits($new) : '')
);

$runner->is(
    count($found) === count($known),
    'число известных нарушений совпадает со списком (' . count($found) . ' из ' . count($known) . '): '
    . 'список разошёлся с кодом, и это тоже поломка проверки'
);

/*
 * Запрет вызова — половина правила: вызов можно просто стереть, и тогда ученик
 * завершит работу без вопроса, а правка задания закроется вместе с половиной
 * работы. Поэтому проверяется ещё и то, чем вызов заменён.
 */
$runner->is(
    str_contains($jsCode['public/assets/js/confirm-modal.js'] ?? '', 'window.MyEduConfirm'),
    'у окна подтверждения есть способ спросить из скрипта'
);

$runner->is(
    str_contains($jsCode['public/assets/js/attempt.js'] ?? '', 'MyEduConfirm.open'),
    'завершение работы с незаполненными заданиями спрашивает окном сайта'
);

$runner->is(
    str_contains($jsCode['public/assets/js/assignment-items.js'] ?? '', 'MyEduConfirm.open'),
    'отмена правки задания спрашивает окном сайта'
);

// ---------------------------------------------------------------------------
// 2. Инлайновые стили в разметке.
//
// Причина одна: правило в таблице стилей перекрывается стилем Gentelella
// позже по умолчанию, а инлайновый — никогда. Находилось ноль, и правило
// остаётся как запрет на возврат.
/*
 * В CSS не потеряны кавычки.
 *
 * Незакрытая строка в селекторе не ломает один правило, а съедает файл до
 * следующей кавычки: дальше браузер не понимает ни одного правила, и страница
 * остаётся без оформления — притом что разметка и стиль-файл на месте. Так
 * сломалось оформление списка работ и редактора заданий: в селекторе
 * потерялась одна кавычка, и половина стилей перестала применяться.
 *
 * Проверка простая: в строке число кавычек должно быть чётным.
 */
$cssBroken = [];

foreach (readFiles($root, ['public/assets/css'], ['css']) as $path => $source) {
    foreach (explode("
", str_replace("
", "
", (string) $source)) as $number => $line) {
        $clean = (string) preg_replace('#/\*.*?\*/#', '', $line);

        if (substr_count($clean, '"') % 2 !== 0) {
            $cssBroken[] = $path . ':' . ($number + 1);
        }
    }
}

$runner->equals([], $cssBroken, 'в стилях не потеряны кавычки: ' . implode(', ', $cssBroken));
/*
 * Правила из app.css, которые перебивают сборку, — с адресом.
 *
 * app.css подключается раньше gentelella.css, и правило равной силы проигрывает
 * сборке: так окно редактора задания осталось 480 пикселей, хотя в app.css
 * было написано другое. Правило перестало действовать молча.
 *
 * Проверяются те правила, что перекрывают размеры сборки: с адресом они
 * работают, без адреса — нет.
 */
$overridden = [];

if (preg_match('/^\.modal-dialog--task\s*\{/m', (string) file_get_contents($root . '/public/assets/css/app.css'))) {
    $overridden[] = 'modal-dialog--task: правило без адреса проиграет сборке';
}

$runner->equals([], $overridden, 'правила, перебивающие сборку, написаны с адресом: ' . implode('; ', $overridden));
$runner->group('Правила интерфейса: разметка');

$inline = findAll($templates, '/\sstyle="[^"]{1,200}"/');
$runner->equals([], $inline, 'инлайновых style= в шаблонах нет: ' . describeHits($inline));

/*
 * Теги в шаблоне закрыты все.
 *
 * Незакрытый тег браузер закрывает сам — и встраивает содержимое куда попало
 * не туда. Так на странице «Предметы» шапка карточки осталась незакрытой, таблица
 * и счётчик оказались внутри неё, а шапка — flex-строкой: кнопка слева, таблица
 * посередине, счётчик справа. Страница выглядела сломанной, и в коде это нигде
 * не было видно: ошибка жила в вложенности тегов, а не в логике.
 *
 * Считаются только теги вне PHP-блоков: код внутри них на разметку не влияет.
 */
$unbalanced = [];

foreach ($templates as $path => $src) {
    $html = (string) preg_replace('/<\?php.*?\?>/s', '', $src);

    $open = preg_match_all('/<div\b/', (string) $html);
    $close = preg_match_all('#</div>#', (string) $html);

    if ($open !== $close) {
        $unbalanced[] = $path . ' (' . $open . ' открытых, ' . $close . ' закрытых)';
    }
}

$runner->equals([], $unbalanced, 'в шаблонах все div закрыты: ' . implode('; ', $unbalanced));

/*
 * Таблица не лежит внутри шапки карточки.
 *
 * Шапка карточки — flex-строка: заголовок слева, кнопка справа. Таблица,
 * попавшая в неё, встаёт рядом с ними, а не под ними.
 *
 * Проверяется не «где таблица в тексте», а вложенность: между открытием
 * шапки и ближайшим её закрытием таблицы быть не должно. Иначе ругается на
 * любой шаблон, где шапка закрыта правильно, а таблица стоит ниже.
 */
$tableInHeader = [];

foreach ($templates as $path => $src) {
    $text = (string) $src;
    $offset = 0;

    while (($open = strpos($text, '<div class="card-header">', $offset)) !== false) {
        $close = strpos($text, '</div>', $open);
        $table = strpos($text, '<table', $open);

        if ($close === false) {
            break;
        }

        if ($table !== false && $table < $close) {
            $tableInHeader[] = $path;

            break;
        }

        $offset = $close + 6;
    }
}

$runner->equals([], $tableInHeader, 'таблица не лежит в шапке карточки: ' . implode('; ', $tableInHeader));

// ---------------------------------------------------------------------------
// 3. btn-outline только у кнопки отмены в модальном окне.
//
// Побочный вариант кнопки в обычной странице выглядит как второе главное
// действие рядом с главным. В модальном окне «Отмена» — это отмена, и там
// вариант уместен.
$outline = findAll($templates, '/btn-outline/');
$wrongOutline = [];

foreach ($outline as $hit) {
    if (str_contains($hit[2], 'data-modal-close') || str_contains($hit[2], 'data-confirm')) {
        continue;
    }

    $wrongOutline[] = $hit;
}

$runner->equals(
    [],
    $wrongOutline,
    'btn-outline только на кнопке отмены в модальном окне: ' . describeHits($wrongOutline)
);

// ---------------------------------------------------------------------------
// 4. Сообщение выводит оболочка, а не страница.
//
// Каждая страница, которая выводит `$notice`/`$error` сама, рисует второй
// баннер поверх баннера оболочки: на пустом названии предмета это два
// одинаковых блока «Ошибка». Пункт M2 ревью 2026-10-18.
$runner->group('Правила интерфейса: сообщения');

$knownBanners = [
    // Разбор задания рендерится без оболочки (`render(..., '')`), и его
    // сообщение — не флеш, а ошибка оценивания самого задания. Печатать его
    // некому, кроме самой страницы.
    'plugins/test/templates/work_review.php' => 'M2, страница без оболочки, сообщение своё',
];

$duplicated = [];

foreach ($templates as $path => $src) {
    if (str_contains($path, '_shell') || str_contains($path, 'show.php') || str_contains($path, 'error')) {
        continue;
    }

    if (preg_match('/banner(?:Ok|Error)?\(\s*\(?(?:string\)\s*)?\$(?:notice|error)/', $src) === 1) {
        $duplicated[] = $path;
    }
}

$newBanners = array_values(array_diff($duplicated, array_keys($knownBanners)));
$runner->equals([], $newBanners, 'страниц с собственным выводом сообщений не добавилось: ' . implode(', ', $newBanners));
$runner->is(
    count($duplicated) === count($knownBanners),
    'число страниц с двойным баннером совпадает со списком (' . count($duplicated) . ' из '
    . count($knownBanners) . ')'
);

// ---------------------------------------------------------------------------
// 5. Разметка ошибки поля — одна.
//
// Сервер рисует `div.form-error`, клиентский скрипт — `p.banner.form-error`.
// Выглядят они по-разному, а стоят рядом на одной форме. Пункт M3.
$runner->group('Правила интерфейса: ошибка поля');

$errorNodes = findAll($jsCode, '/banner\s+form-error/');
$runner->equals([], $errorNodes, 'разметка ошибки поля в скриптах одна: ' . describeHits($errorNodes));

/*
 * Проверяется не только запрет: скрипт должен создавать тот же узел, какой
 * рисует сервер, иначе первая же ошибка в браузере окажется чужой разметкой.
 */
$createsErrorNode = str_contains($jsCode['public/assets/js/form-validate.js'] ?? '', "document.createElement('div');\n            node.className = ERROR_NODE;");

$runner->is($createsErrorNode, 'браузер рисует ошибку тем же узлом, что и сервер');

$runner->is(
    str_contains((string) file_get_contents($root . '/src/Ui/Ui.php'), 'class="form-error"'),
    'сервер рисует ошибку полем .form-error'
);

/*
 * Подпись под полем объявлена для чтения с экрана.
 *
 * Поле ссылается на подпись через `aria-describedby`, а подпись отвечает
 * своим `id`. Без этой связи подсказка и ошибка читаются с экрана только как
 * текст рядом с полем: человек, который не видит страницу, узнаёт об ошибке
 * лишь тогда, когда программа чтения дойдёт до подписи, — а до подписи она
 * не доходит.
 *
 * Проверяется на настоящей разметке хуков: адрес на поле и адрес на подписи
 * должны совпадать, и подпись с таким адресом обязана быть на странице.
 */
$runner->group('Правила интерфейса: подпись поля');

$uiSource = (string) file_get_contents($root . '/src/Ui/Ui.php');

$runner->is(
    str_contains($uiSource, "'aria-describedby' => \$this->descriptionId(\$id, \$o) ?: null"),
    'поле объявляет подпись под собой'
);

$runner->is(
    str_contains($uiSource, "return \$id . '-error';") && str_contains($uiSource, "return \$id . '-help';"),
    'адрес подписи выводится из идентификатора поля'
);

$uiObject = (new MyEdu\Core\View($root . '/templates'))->ui();

foreach (['title' => 'Подсказка', 'email' => 'Ошибка'] as $field => $text) {
    $markup = $field === 'title'
        ? $uiObject->field($field, ['label' => 'Поле', 'help' => $text])
        : $uiObject->field($field, ['label' => 'Поле', 'error' => $text]);

    $runner->is(
        preg_match('/aria-describedby="([^"]+)"/', $markup, $ref) === 1
        && str_contains($markup, 'id="' . $ref[1] . '"'),
        'адрес с поля указывает на подпись, которая есть на странице: ' . $field
    );
}

$runner->is(
    !str_contains($uiObject->field('plain', ['label' => 'Поле']), 'aria-describedby'),
    'у поля без подписи адрес не выставляется'
);

// ---------------------------------------------------------------------------
// 6. Скрипт, выдающий вход в обход формы, обязан быть защищён стендом.
//
// Так работал `tools/shot_login.ps1` и работает `tools/editor_style_probe.ps1`:
// страница в `public/` логинит в оболочку без пароля. На тестовом стенде это
// нужно — иначе снимок экрана требует вводить пароль руками. На боевом сервере
// это открытый вход под владельцем, и страница остаётся там навсемя, потому
// что запускают скрипт один раз.
//
// Правило требует не запрета, а защиты: такой скрипт обязан отказываться
// работать с любой базой, в имени которой нет `test`. Проверка выполняется по
// коду скрипта, и потому не срабатывает от того, что его забыли.
$runner->group('Правила интерфейса: временный вход');

$loginScripts = [];

foreach ($scripts as $path => $src) {
    if (str_contains($src, '->login(')) {
        $loginScripts[$path] = preg_match('/MYEDU_DB_NAME/', $src) === 1
            && preg_match('/test/i', $src) === 1;
    }
}

$knownLogin = [
    'tools/shot_login.ps1' => false,
    'tools/editor_style_probe.ps1' => true,
];

$runner->equals(
    [],
    array_diff(array_keys($loginScripts), array_keys($knownLogin)),
    'скриптов со входом в обход формы не добавилось: ' . implode(', ', array_diff(array_keys($loginScripts), array_keys($knownLogin)))
);

$unguarded = [];

foreach ($loginScripts as $path => $guarded) {
    if (!$guarded && ($knownLogin[$path] ?? false) !== false) {
        $unguarded[] = $path;
    }
}

$runner->equals([], $unguarded, 'все скрипты со входом в обход формы защищены проверкой тестовой базы: ' . implode(', ', $unguarded));

// ---------------------------------------------------------------------------
// 7. Ошибка объявляется тревогой и гаснет сама, как обещает подсказка.
//
// `role="status"` на контейнере означал одно и то же отношение ко всем
// сообщениям: ошибка произносилась так же вежливо, как «сохранено». Плюс её
// таймер вообще не запускался, хотя подсказка к `showToast` обещает вдвое
// большее время. Пункт M5.
$runner->group('Правила интерфейса: сообщения скрипта');

$toasts = findAll($jsCode, '/role=["\']status["\']/');

$runner->equals(
    [],
    $toasts,
    'роль status объявляется на самом сообщении, а не на контейнере: ' . describeHits($toasts)
);

$toastCode = $jsCode['public/assets/js/toast.js'] ?? '';

$runner->is(
    str_contains($toastCode, "node.setAttribute('role', kind === 'error' ? 'alert' : 'status')"),
    'ошибка объявляется тревогой, остальное — обычным сообщением'
);

$runner->is(
    str_contains($toastCode, 'setTimeout(dismiss, duration || (kind === \'error\' ? ERROR_DURATION : DURATION))'),
    'любое сообщение гаснет само, ошибка — вдвое дольше'
);

$runner->is(
    preg_match('/var DURATION = (\d+)/', $toastCode, $plain) === 1
    && preg_match('/var ERROR_DURATION = (\d+)/', $toastCode, $err) === 1
    && (int) $err[1] === (int) $plain[1] * 2,
    'ошибка держится ровно вдвое дольше обычного сообщения'
);

// ---------------------------------------------------------------------------
// 8. Тёмная тема объявлена селектором, который срабатывает.
//
// Тёмная тема — не «другая окраска», а вторая половина оформления. Если у
// токена есть светлое значение и нет тёмного, в тёмной теме он остаётся
// светлым: карточка на белом фоне, белый заголовок на белом, рамка, которой не
// видно. Именно так и вышло — карточка работы на `/works` была нечитаемой, и
// стоило это одного селектора.
//
// Проверяется три вещи: тёмная тема объявлена тем селектором, который стоит в
// разметке; светлая тема — это базовые значения `:root`; и каждый цветовой
// токен приложения объявлен в обеих темах.
$runner->group('Правила интерфейса: тёмная тема');

$themeCss = stripCssComments((string) file_get_contents($root . '/public/assets/css/theme.css'));
$appCss = stripCssComments((string) file_get_contents($root . '/public/assets/css/app.css'));

$runner->is(
    preg_match('/\[data-theme=dark\]/', $themeCss) === 1,
    'тёмная тема объявлена правилом, которое срабатывает при data-theme="dark"'
);

$runner->notContains(
    $themeCss,
    'html:not([data-theme])',
    'тёмная тема больше не спрятана под селектор страницы без атрибута — такой страницы не бывает'
);

$runner->is(
    preg_match('/^\s*(?::root|html)\s*\{/m', $appCss) === 1,
    'светлая тема — базовые значения :root, они применяются всегда'
);

$rootBlock = preg_match('/^\s*(?::root|html)\s*\{(.*?)\n\}/ms', $appCss, $rootMatch) === 1
    ? $rootMatch[1]
    : '';

$darkBlock = preg_match('/\[data-theme=dark\]\s*\{(.*?)\n\}/s', $themeCss, $darkMatch) === 1
    ? $darkMatch[1]
    : '';

$colors = static function (string $block): array {
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*(#[0-9a-f]{3,8}|rgba?\([^)]*\))/i', $block, $m, PREG_SET_ORDER);

    $out = [];

    foreach ($m as $hit) {
        $out[strtolower($hit[1])] = $hit[2];
    }

    return $out;
};

$lightColors = $colors($rootBlock);
$darkColors = $colors($darkBlock);

/*
 * Заливки, которым тёмное значение не нужно: по ним лежит белый текст, и
 * осветление сделало бы его нечитаемым. Текстовые варианты тех же цветов
 * объявлены отдельно — `--accent-link`, `--good-text` и так далее.
 */
$fillsStayDark = ['--accent', '--accent-dark', '--good', '--mid', '--bad'];

$unthemed = [];

foreach ($lightColors as $token => $value) {
    if (!isset($darkColors[$token]) && !in_array($token, $fillsStayDark, true)) {
        $unthemed[] = $token . ' (' . $value . ')';
    }
}

$runner->equals(
    [],
    $unthemed,
    'каждый цветовой токен объявлен в тёмной теме тоже: ' . implode(', ', $unthemed)
);

foreach (['--surface', '--bg', '--text', '--text-muted', '--border'] as $critical) {
    $runner->is(
        isset($lightColors[$critical], $darkColors[$critical]),
        $critical . ' задан в обеих темах'
    );
}

// ---------------------------------------------------------------------------
// 9. Контраст приглушённого текста и ссылок в обеих темах.
//
// Приглушённый текст — подписи, пояснения, «ещё не заходил». Именно он первым
// перестаёт читаться: основной текст виден всегда, а подпись можно не заметить
// и списать на своё зрение.
//
// Порог 4.5:1 — требование WCAG AA к обычному тексту. Считается по формуле
// относительной яркости, а не на глаз: на своём мониторе человек контраст не
// оценивает, и проверить его можно только расчётом.
$runner->group('Правила интерфейса: контраст подписей');

$luminance = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    $parts = [];

    foreach ([0, 2, 4] as $i) {
        $c = hexdec(substr($hex, $i, 2)) / 255;
        $parts[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
};

$contrast = static function (string $a, string $b) use ($luminance): float {
    $l1 = $luminance($a);
    $l2 = $luminance($b);

    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
};

$checked = [
    'приглушённый текст в светлой теме' => ['--text-muted', '--surface', 'светлая'],
    'приглушённый текст в тёмной теме' => ['--text-muted', '--surface', 'тёмная'],
    'ссылка в светлой теме' => ['--accent-link', '--surface', 'светлая'],
    'ссылка в тёмной теме' => ['--accent-link', '--surface', 'тёмная'],
];

foreach ($checked as $what => [$token, $background, $theme]) {
    $palette = $theme === 'тёмная' ? $darkColors : $lightColors;
    $text = $palette[$token] ?? '';
    $base = $palette[$background] ?? '';

    if ($text === '' || $base === '' || str_contains($text, 'rgba')) {
        $runner->is(false, $what . ': токены заданы плотным цветом');
        continue;
    }

    $ratio = $contrast($text, $base);

    $runner->is(
        $ratio >= 4.5,
        $what . ' читается: ' . round($ratio, 2) . ':1'
    );
}

// ---------------------------------------------------------------------------
// 10. Заголовок страницы один и тот же.
//
// Заголовок стоял то над карточкой, то внутри неё, то первым уровнем, то
// вторым: при переходе на каждую следующую страницу взгляд начинал заново, и
// страницы панели выглядели сделанными разными людьми. Теперь он собирается
// одним компонентом, и правило требует им пользоваться.
//
// Проверяются только настоящие страницы: файлы, начинающиеся с подчёркивания,
// — это части, и заголовка у них нет по замыслу.
$runner->group('Правила интерфейса: заголовок страницы');

$panelPages = [];

foreach (readFiles($root, ['templates/panel'], ['php']) as $path => $src) {
    $name = basename($path);

    if (str_starts_with($name, '_')) {
        continue;
    }

    $panelPages[$name] = $src;
}

$pagesWithoutHeader = [];

foreach ($panelPages as $name => $src) {
    // Заголовок может стоять прямо в шаблоне или собираться компонентом.
    $hasHeader = str_contains($src, 'page-header')
        || str_contains($src, 'page-title')
        || str_contains($src, '_page_header.php');
    $hasCardTitle = str_contains($src, 'card-title');

    if ($hasHeader || ! $hasCardTitle) {
        continue;
    }

    $pagesWithoutHeader[] = $name;
}

$runner->equals(
    [],
    $pagesWithoutHeader,
    'у каждой страницы панели есть заголовок: без него — ' . implode(', ', $pagesWithoutHeader)
);

$runner->is(
    is_file($root . '/templates/panel/_page_header.php'),
    'заголовок собирается одним компонентом'
);

$headerTemplate = (string) file_get_contents($root . '/templates/panel/_page_header.php');

$runner->is(
    str_contains($headerTemplate, 'page-title') && str_contains($headerTemplate, 'page-header'),
    'компонент отдаёт и заголовок, и подпись под ним'
);

// ---------------------------------------------------------------------------
// 11. У пустой колонки нет значка сортировки.
//
// Значок в колонке действий обещал сортировку, которой нет: клик по нему
// переставлял строки целиком и ничего не менял в состоянии таблицы.
$runner->group('Правила интерфейса: колонки таблиц');

$sortableEmpty = [];

foreach (readFiles($root, ['templates', 'plugins'], ['php']) as $path => $src) {
    if (!str_contains($src, 'data-datatable')) {
        continue;
    }

    if (preg_match_all('/<th\b[^>]*>\s*<\/th>/', $src, $heads, PREG_SET_ORDER) > 0) {
        foreach ($heads[0] as $head) {
            if (!str_contains($head, 'data-orderable="false"')) {
                $sortableEmpty[] = basename($path);
                break;
            }
        }
    }
}

$runner->equals(
    [],
    $sortableEmpty,
    'пустая колонка не сортируется: ' . implode(', ', $sortableEmpty)
);

/*
 * Библиотека таблиц стоит только там, где списку она нужна.
 *
 * DataTables — спискам, которые переросли одну страницу: классам, предметам и
 * сотрудникам. Подключать её к таблице на двадцать строк незачем, а своя
 * разметка под библиотекой рискует получить чужие правила оформления. Правило
 * записано в файле для разработчика; здесь оно проверяется, чтобы новая
 * таблица с библиотекой не появилась молча.
 *
 * Список разрешённых мест — с именем файла, а не числом: файл переименуют, и
 * проверка должна сказать об этом, а не молчать о неожиданном числе.
 */
$runner->group('Правила интерфейса: библиотека таблиц');

$datatableAllowed = [
    'templates/panel/classes.php',
    'templates/panel/subjects.php',
    'templates/panel/users.php',
];

$datatableUnexpected = [];

foreach (readFiles($root, ['templates', 'plugins'], ['php']) as $path => $src) {
    if (str_contains($src, 'data-datatable') && !in_array($path, $datatableAllowed, true)) {
        $datatableUnexpected[] = $path;
    }
}

$runner->equals(
    [],
    $datatableUnexpected,
    'библиотека таблиц стоит только в списках, которые переросли страницу: '
    . implode(', ', $datatableUnexpected)
);

foreach ($datatableAllowed as $path) {
    $runner->is(
        str_contains((string) (readFiles($root, ['templates/panel'], ['php'])[$path] ?? ''), 'data-datatable'),
        'таблица со списком на всю школу осталась с библиотекой: ' . basename($path)
    );
}

// ---------------------------------------------------------------------------
// 12. Подсказка поля поиска начинается за значком.
//
// Значок рисует шаблон и оставляет под него 32 пикселя. Библиотека таблиц
// задаёт свои пять, и подсказка «Найти в таблице…» начиналась прямо на
// значке. Отступ задаётся переменной библиотеки, потому что её правило
// перебивает всё, что написано после него, — молча и без ошибок.
$runner->group('Правила интерфейса: поле поиска');

$runner->is(
    str_contains($themeCss, '--dt-input_padding: 7px 12px 7px 32px'),
    'у поля поиска свой отступ слева — под значок'
);

// ---------------------------------------------------------------------------
// 13. Подписи состояний берут цвета приложения.
//
// «Активный», «Не активный», «Заблокирован» — первое, на что смотрит учитель в
// списке сотрудников. В сборке их цвета заданы мимо токенов приложения:
// проверить такой контраст нечем, и в тёмной теме он не выдерживается.
$runner->group('Правила интерфейса: подписи состояний');

foreach (['green' => '--good-text', 'yellow' => '--mid-text', 'red' => '--bad-text'] as $class => $token) {
    $runner->is(
        str_contains($themeCss, '.status-' . $class . " {\n  color: var(" . $token . ')'),
        'подпись состояния ' . $class . ' берёт цвет из ' . $token
    );
}

// ---------------------------------------------------------------------------
// 14. Число и слово печатаются общим хелпером.
//
// Раньше пробел между ними появлялся только потому, что между тегами стоял
// перенос строки, который браузер показывает пробелом. Убрали перенос при
// правке вёрстки — и «8классы» слиплось.
$runner->group('Правила интерфейса: число и слово');

$countHelper = str_contains((string) file_get_contents($root . '/src/Core/View.php'), 'function count(')
    && str_contains((string) file_get_contents($root . '/src/Core/View.php'), "\$number . ' ' . \$this->plural(");

$runner->is($countHelper, 'у вида есть хелпер, который ставит пробел сам');

$splitPairs = [];

foreach (readFiles($root, ['templates'], ['php']) as $path => $src) {
    // Число отдельной строкой, сразу под ним — plural(): так и выглядит пара,
    // у которой пробел держится на переносе строки.
    if (preg_match('/\$view->e\((?:[^()\n]|\([^()\n]*\))+\)\s*\?>\s*\r?\n\s*<\?=\s*\$view->e\(\$view->plural\(/', $src) === 1) {
        $splitPairs[] = basename($path);
    }
}

$runner->equals(
    [],
    $splitPairs,
    'число и слово не печатаются раздельно: ' . implode(', ', $splitPairs)
);

// ---------------------------------------------------------------------------
// 15. Об отказе сообщают один раз.
//
// Отказ при сохранении печатался и всплывающим сообщением, и строкой в самом
// редакторе — то есть дважды, причём вторая копия живёт дольше первой. Человек
// успевает прочитать обе и решить, что сохранить получилось дважды или что
// система сомневается. Пункт M6.
//
// Правило смотрит на тело функции отчёта: в тех же файлах есть отчёты, где
// единственное место сообщения — тост, и запрещать его нельзя.
$runner->group('Правила интерфейса: отказ редактора');

$twiceReported = [];

foreach (['inline-edit.js', 'assignment-items.js'] as $name) {
    $body = functionBody($jsCode['public/assets/js/' . $name] ?? '', 'function notify(');

    if ($body !== '' && str_contains($body, "'error'")) {
        $twiceReported[] = $name;
    }
}

$runner->equals(
    [],
    $twiceReported,
    'об отказе при сохранении сообщают один раз: ' . implode(', ', $twiceReported)
);

// ---------------------------------------------------------------------------
// 16. Браузер и сервер объясняют одно и то же одними словами.
//
// Одно условие проверяется дважды: браузером, пока человек на странице, и
// сервером, когда форма ушла. Формулировки расходились, и на пустое поле
// браузер писал «Заполните «Фамилия»», а сервер отвечал «Укажите фамилию».
// Хуже всего выходило с почтой: человек правил адрес по совету браузера и
// получал тот же отказ по совету сервера. Пункт M8.
//
// Словарь лежит в двух файлах — читать PHP из браузера нельзя, — поэтому
// проверка сравнивает их списки. Иначе через месяц в одном появится новая
// формулировка, а в другом нет, и расхождение вернётся молча.
$runner->group('Правила интерфейса: словарь сообщений');

require_once $root . '/src/Ui/Messages.php';

$phpDictionary = \MyEdu\Ui\Messages::dictionary();
$jsDictionary = [];

if (preg_match('/var MESSAGES = \{(.*?)\n    \}/s', $jsCode['public/assets/js/form-validate.js'] ?? '', $block) === 1) {
    preg_match_all('/^\s*([A-Z_]+):\s*\'((?:[^\'\\\\]|\\\\.)*)\'/m', $block[1], $pairs, PREG_SET_ORDER);

    foreach ($pairs as $pair) {
        $jsDictionary[$pair[1]] = str_replace("\\'", "'", $pair[2]);
    }
}

$runner->equals(
    array_keys($phpDictionary),
    array_keys($jsDictionary),
    'в словарях один и тот же список условий: сервер знает то, чего нет в браузере, или наоборот'
);

$mismatched = [];

foreach ($phpDictionary as $key => $phrase) {
    if (isset($jsDictionary[$key]) && $jsDictionary[$key] !== $phrase) {
        $mismatched[] = $key . ': сервер «' . $phrase . '», браузер «' . $jsDictionary[$key] . '»';
    }
}

$runner->equals([], $mismatched, 'формулировки совпадают слово в слово: ' . implode('; ', $mismatched));

/*
 * Проверяется и то, что словарь к серверу притронулся: иначе правило зелёное,
 * а расхождение на месте — просто потому, что сервер продолжает писать свои
 * фразы мимо словаря.
 */
$serverUsers = [];
$withoutImport = [];

foreach (readFiles($root, ['src'], ['php']) as $path => $src) {
    if (!str_contains($src, 'Messages::')) {
        continue;
    }

    $serverUsers[] = basename($path);

    if (!str_contains($src, 'use MyEdu\Ui\Messages;')) {
        $withoutImport[] = basename($path);
    }
}

$runner->is(
    count($serverUsers) >= 5,
    'словарь используют контроллеры и сервисы, а не только он сам: ' . implode(', ', $serverUsers)
);

/*
 * И то, что у каждого файла есть на него ссылка. Словарь живёт в своём
 * пространстве имён, и без `use` вызов `Messages::` тихо уезжает в
 * `MyEdu\Http\Controller\Panel\Messages`, которого нет: страница отдаёт 500, и
 * сообщение пропадает. Это случилось с двумя контроллерами, и заметили оба
 * только по одному отказу в двух наборах — там, где проверяли текст.
 */
$runner->equals(
    [],
    $withoutImport,
    'у каждого файла, который берёт фразу из словаря, есть ссылка на него: ' . implode(', ', $withoutImport)
);

// ---------------------------------------------------------------------------
// 17. Обязательное поле чем-то отмечено.
//
// Пока подписи у всех полей были одинаковыми, человек узнавал об
// обязательности только после нажатия «Сохранить». В форме, где половина полей
// необязательная, это означало, что лишние вопросы задаёт сервер. Пункт M9.
//
// Отметка сделана стилями, а не разметкой: поля пишутся и руками в шаблонах, и
// через Ui, и правило в разметке рано или поздно разошлось бы с правилом в
// стилях. Требуется поэтому не «звёздочка в каждом шаблоне», а само правило и
// пояснение к нему — иначе отметка есть, а что она значит, догадываются.
$runner->group('Правила интерфейса: обязательные поля');

$runner->is(
    str_contains($appCss, '.form-group:has(> [required]) > .form-label::after'),
    'у обязательного поля помечена подпись'
);

$runner->is(
    str_contains($appCss, 'content: "Поля, отмеченные звёздочкой, обязательны"'),
    'рядом с формой сказано, что означает отметка'
);

$requiredFields = 0;
$markedByHand = [];

foreach (readFiles($root, ['templates'], ['php']) as $path => $src) {
    if (preg_match_all('/\brequired\b/', $src) > 0) {
        $requiredFields++;
    }

    // Ручная отметка в разметке — это вторая копия правила, которая со временем
    // разойдётся с правилом в стилях.
    if (preg_match('/form-label[^>]*>[^<]*[✱*]/u', $src) === 1) {
        $markedByHand[] = basename($path);
    }
}

$runner->is($requiredFields > 0, 'в шаблонах есть обязательные поля, ради которых всё затевалось');

$runner->equals(
    [],
    $markedByHand,
    'обязательность отмечена стилями, а не разметкой в каждом шаблоне: ' . implode(', ', $markedByHand)
);

// ---------------------------------------------------------------------------
// 18. Опасные действия ученика спрашивают подтверждения.
//
// Блокировка ученика и выдача новых паролей необратимы: первый закрывает
// человеку вход, второй отнимает прежние пароли у всех отмеченных сразу. Обе
// кнопки стояли без подтверждения, хотя такое же действие в списке сотрудников
// спрашивалось. Пункт M4.
//
// Отдельно проверяется адрес у «Выдать новые пароли»: кнопка объявлена формой
// списка и отправляет её на другой адрес, поэтому окно подтверждения без
// data-confirm-action отправило бы форму туда, откуда она и так уходит, —
// просто перезаписав отметки. По разметке это не видно, поэтому проба
// tools/confirm_probe.ps1 проверяет всё это в браузере нажатием.
$runner->group('Правила интерфейса: подтверждения ученика');

$roster = readFiles($root, ['templates/panel'], ['php'])['templates/panel/class.php'] ?? '';

$runner->is(
    preg_match('/name="status" value="blocked"\s*>\s*<button[^>]*data-confirm="/s', $roster) === 1,
    'блокировка ученика спрашивает подтверждения'
);

$runner->is(
    str_contains($roster, 'formaction') && str_contains($roster, 'data-confirm-action'),
    'выдача новых паролей спрашивает подтверждения и знает свой адрес'
);

$probe = readFiles($root, ['tools'], ['ps1'])['tools/confirm_probe.ps1'] ?? '';

$runner->is(
    str_contains($probe, 'students/status') && str_contains($probe, '/credentials/reveal'),
    'есть браузерная проба обоих подтверждений'
);

$runner->is(
    str_contains($probe, 'MYEDU_DB_NAME') === false
    && str_contains($probe, '_shot_tmp.php'),
    'проба идёт через временный вход, а не входит сама'
);

/*
 * Страховка от белого экрана.
 *
 * Проверяется и порядок, и поведение. Порядок — потому что обработчик, поставленный
 * после загрузки конфигурации, бесполезен ровно в том случае, ради которого он и
 * нужен: ошибается-то чаще всего `config.local.php`. Поведение — потому что
 * «обработчик поставлен» ничего не говорит о том, что он делает: показать белую
 * страницу с текстом ошибки и путём до файла он тоже умеет.
 */
$runner->group('Ошибки не доходят до белого экрана');

$index = (string) file_get_contents($root . '/public/index.php');
$handlerAt = strpos($index, 'ErrorHandler::register(');
/*
 * Ищется именно присваивание, а не любое упоминание: `App::boot()` есть и в
 * пояснении рядом с постановкой обработчика, и сравнение нашло бы его вместо
 * вызова — проверка прошла бы или упала не по тому поводу.
 */
$bootAt = strpos($index, '= App::boot(');

$runner->is(
    $handlerAt !== false && $bootAt !== false && $handlerAt < $bootAt,
    'обработчик ошибок ставится до загрузки конфигурации'
);

$handlerSource = (string) file_get_contents($root . '/src/Core/ErrorHandler.php');

$runner->contains(
    $handlerSource,
    'register_shutdown_function',
    'фатальные ошибки тоже попадают в журнал, а не только исключения'
);

$runner->contains(
    $handlerSource,
    'headers_sent',
    'ответ не досылается, если начат раньше'
);

/*
 * Настоящий прогон в отдельном процессе: обработчик ставится, исключение
 * бросается, и проверяется, что в журнал оно попало, а в ответ — нет.
 *
 * Отдельный процесс нужен потому, что обработчик менять глобальное состояние
 * интерпретатора: внутри набора он снял бы исключения у самого набора.
 */
$errorLog = sys_get_temp_dir() . '/myedu-error-' . bin2hex(random_bytes(4)) . '.log';
$probeFile = sys_get_temp_dir() . '/myedu-error-' . bin2hex(random_bytes(4)) . '.php';
$secret = str_repeat('a1b2c3d4', 6);

file_put_contents($probeFile, '<?php' . "\n"
    . 'require ' . var_export($root . '/src/Core/Autoloader.php', true) . ";\n"
    . 'MyEdu\\Core\\Autoloader::create(' . var_export($root, true) . ");\n"
    . 'MyEdu\\Core\\ErrorHandler::register(' . var_export($errorLog, true) . ");\n"
    . 'throw new RuntimeException(' . var_export('сбой при чтении адреса /auth/reset?token=' . $secret, true) . ");\n");

$probeCommand = escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d error_log= ' . escapeshellarg($probeFile) . ' 2>&1';
$probeOutput = (string) shell_exec($probeCommand);

$runner->is(is_file($errorLog), 'обработчик пишет в журнал даже при ошибке до загрузки конфигурации');

$logText = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';

$runner->contains($logText, 'RuntimeException', 'в журнале видно, что именно упало');
$runner->contains($logText, 'сбой при чтении адреса', 'в журнале виден текст ошибки');

/*
 * Токен из адреса в журнал попадать не должен: это готовый ключ сброса пароля,
 * а журнал на сервере читают не только для отладки.
 */
$runner->is(
    str_contains($logText, $secret) === false,
    'токен из адреса в журнале укорачивается'
);

/*
 * Наружу не отдаётся ничего: ни текста ошибки, ни пути к файлу. Путь рассказывает
 * о машине, а текст исключения — о внутренностях, и то и другое попадает в
 * браузер любого, кто откроет страницу.
 */
$runner->is(
    str_contains($probeOutput, 'RuntimeException') === false
    && str_contains($probeOutput, 'myedu-error-') === false
    && str_contains($probeOutput, $secret) === false,
    'наружу не уходит ни текст ошибки, ни путь, ни токен'
);

$runner->contains($probeOutput, 'Страница не открылась', 'вместо пустого экрана объяснение');

@unlink($probeFile);
@unlink($errorLog);

/*
 * Слои не зависят друг от друга снизу вверх.
 *
 * Ядро — самый нижкий слой, и знать про `Auth`, плагины и меню панели ему не
 * полагается: стоило бы убрать любой из них, и контейнер перестал бы собираться.
 * Проверка идёт по исходникам, а не по поведению, — иначе она ловила бы только
 * уже сломанное приложение, а не причину.
 */
$runner->group('Слои: ядро не знает верхних');

$coreFiles = glob($root . '/src/Core/*.php') ?: [];

foreach ($coreFiles as $coreFile) {
    $code = (string) file_get_contents($coreFile);

    foreach (['MyEdu\\Auth\\', 'MyEdu\\Http\\', 'MyEdu\\Repository\\', 'MyEdu\\Plugin\\'] as $upperLayer) {
        $runner->is(
            str_contains($code, $upperLayer) === false,
            'ядро не ссылается на верхний слой ' . rtrim($upperLayer, '\\')
            . ' (' . basename($coreFile) . ')'
        );
    }
}

/*
 * Вид получает адреса и защитное поле при создании.
 *
 * Раньше он брал контейнер сам в шести местах, и из-за этого не собирался вне
 * запроса: вид, расставляющий ссылки, зависел от всего приложения.
 */
$viewCode = (string) file_get_contents($root . '/src/Core/View.php');

$runner->is(
    str_contains($viewCode, 'App::instance()->') === false,
    'вид не достаёт контейнер сам'
);

/*
 * Одно и то же письмо не должно уходить в два разных места.
 *
 * Фабрика дописывает к каталогу `log` сама, а контроллеры собирали свою копию
 * с уже готовым `var/log`. Письма о регистрации и сбросе пароля оказывались в
 * `var/log/log/` — каталоге, который никто не открывает, — и на боевом сервере
 * это выглядело бы как «письма не отправляются вовсе».
 */
$runner->is(
    str_contains((string) file_get_contents($root . '/src/Http/Routes/Controllers.php'), 'new MailerFactory(') === false,
    'контроллеры берут отправку писем из контейнера, а не собирают свою'
);

/*
 * Плагину отдаётся перечень сервисов, а не контейнер.
 *
 * Контейнер в руках у чужого кода — это «можно всё»: поменять настройку,
 * дотянуться до соседнего плагина. Формально плагину и так доступно всё, и об
 * этом честно написано на странице плагинов, но держать в руках то, что не
 * нужно, — значит отдавать это по недосмотру, а не по замыслу.
 */
$runner->group('Плагин получает список сервисов, а не контейнер');

foreach (glob($root . '/plugins/*/src/*Plugin.php') ?: [] as $pluginFile) {
    $code = (string) file_get_contents($pluginFile);

    $runner->is(
        str_contains($code, 'private readonly App $app') === false,
        'плагин ' . basename($pluginFile) . ' не держит контейнер'
    );

    $runner->is(
        str_contains($code, '$this->app->') === false,
        'плагин ' . basename($pluginFile) . ' не достаёт сервисы из контейнера'
    );
}

/*
 * Название класса должно называть то, что происходит. `PluginRemovalUnavailable`
 * обещало удаление плагина, которого в системе нет: отказ возникает только
 * при снятии позиции работы, и следующий человек искал в коде то, чего нет.
 */
$runner->is(
    is_file($root . '/src/Http/Controller/Panel/TaskRemovalBlocked.php'),
    'отказ снять позицию работы назван тем, чем является'
);

$runner->is(
    is_file($root . '/src/Http/Controller/Panel/PluginRemovalUnavailable.php') === false,
    'класса с вводящим в заблуждение именем больше нет'
);

$runner->is(
    str_contains((string) file_get_contents($root . '/src/Http/Controller/Panel/AssignmentController.php'), 'PluginRemovalUnavailable') === false,
    'и в контроллере он больше не упоминается'
);

/*
 * Путь к миграциям — из каталога плагина, а не из его id.
 *
 * Папку плагина переименовывают, копируя заготовку, и `id` при этом остаётся
 * прежним. Путь `plugins/{id}/migrations` вёл бы в несуществующий каталог, и
 * плагин молча отмечался бы установленным, оставив базу без своих таблиц:
 * включается, появляется в палитре, и падает на первой попытке ученика.
 */
$runner->is(
    str_contains((string) file_get_contents($root . '/src/Plugin/PluginManager.php'), 'function migrationsDir') === true,
    'каталог миграций берётся из описания плагина'
);

/*
 * Браузерные пробы лежат рядом с набором, а не в сторонке.
 *
 * Проверить оформление по коду нельзя: контраст считается по итоговым цветам,
 * которые рисует экран, и каскад решает всё. Поэтому пробы — браузерные, и
 * задача набора — не дать им потеряться при переносе на другую машину.
 *
 * Тёмная тема проверяется отдельно от светлой: в тёмной читается то, чего
 * не видно в светлой, и наоборот. Проба меряет обе и требует 4.5:1 — тот же
 * порог, что и в наборе правил.
 */
$runner->group('Правила интерфейса: браузерные пробы');

$darkProbe = (string) @file_get_contents($root . '/tools/dark_probe.ps1');
$confirmProbe = (string) @file_get_contents($root . '/tools/confirm_probe.ps1');

$runner->is($darkProbe !== '', 'проба тёмной темы на месте');
$runner->is($confirmProbe !== '', 'проба подтверждений на месте');

$runner->contains($darkProbe, '4.5', 'проба тёмной темы требует читаемости 4.5:1');
$runner->contains($darkProbe, 'data-theme', 'проба переключает тему и меряет каждую');
$runner->contains($darkProbe, 'contrast', 'проба считает контраст, а не смотрит на цвет');

/*
 * Места, которые проба смотрит, — это те самые, что называла волна 1.
 *
 * Список задан в пробе, и он должен совпадать с пунктами ревью: иначе проба
 * молча перестала бы смотреть то, ради чего её писали.
 */
foreach (['V1', 'V4', 'V10', 'V13'] as $item) {
    $runner->contains($darkProbe, $item, 'проба тёмной темы смотрит пункт ' . $item);
}

/*
 * Правило неактивной кнопки пагинации остаётся на месте.
 *
 * Оно снимает с кнопки гашение прозрачностью, и без него неактивная кнопка
 * читается как 1.9:1. Правило легко потерять среди правок оформления, а
 * заметить это можно только глазами — то есть в самый неподходящий момент.
 */
$runner->contains(
    (string) file_get_contents($root . '/public/assets/css/theme.css'),
    '.dt-paging .dt-paging-button.disabled',
    'неактивная кнопка пагинации не гасится прозрачностью'
);
/*
 * Радиокнопка — это группа, а не поле.
 *
 * Обязательным помечается одна кнопка группы, а выбрать человек может любую.
 * Если проверка смотрит на конкретную кнопку, то отмеченная вторая кнопка не
 * спасает форму: человек получает отказ по полю, которое он как раз заполнил.
 * В мастере выдачи работы это и происходило — выбор делался в строке, а жаловались
 * на первую.
 */
$runner->group('Правила интерфейса: группа радиокнопок');

$formValidate = (string) file_get_contents($root . '/public/assets/js/form-validate.js');

$runner->contains(
    $formValidate,
    "field.type === 'radio'",
    'проверка понимает, что радиокнопка — это группа'
);
$runner->contains(
    $formValidate,
    'radio.name === field.name',
    'кнопки группы ищутся по имени, а не по месту в разметке'
);
$runner->contains(
    $formValidate,
    'radios.some(function (radio) {',
    'группа считается заполненной, если отмечена любая её кнопка'
);

/*
 * Пояснение про звёздочку не должно появляться в строке таблицы.
 *
 * Форма правки значения в строке — одно поле, и строка «поля, отмеченные
 * звёздочкой» рядом с ней не говорит ничего, а сдвигает правку вправо.
 */
$runner->contains(
    (string) file_get_contents($root . '/public/assets/css/app.css'),
    'form:not(.inline-form):has([required])::before',
    'пояснение про звёздочку не рисуется в форме-строке'
);

/*
 * Отказ сессии разбирается по коду ответа.
 *
 * Истёкшая сессия отвечает не данными, а страницей входа: разобрать её как
 * JSON нельзя, и запрос падал в общую ветку — человек видел «сервер ответил
 * не так, как ожидалось» или вовсе молчание. Код 401, 403 и 419 называют
 * причину словами, поэтому разбор должен быть у каждого запроса, а не у
 * одного на файл.
 *
 * Проверяется по кускам между запросами: разбор стоит сразу за тем запросом,
 * к которому он относится, и в куске до следующего `fetch(` его быть не
 * может. Так пропуск виден и там, где разбор есть, но не у того запроса.
 *
 * Пункт A–C ревью 2026-10-21.
 */
$runner->group('Правила интерфейса: отказ сессии');

$withoutDenied = [];

foreach ($jsCode as $path => $src) {
    $positions = [];
    $offset = 0;

    while (($at = strpos($src, 'fetch(', $offset)) !== false) {
        $positions[] = $at;
        $offset = $at + 6;
    }

    foreach ($positions as $index => $at) {
        $end = $positions[$index + 1] ?? strlen($src);
        $chunk = substr($src, $at, $end - $at);

        if (!str_contains($chunk, 'status === 401')) {
            $withoutDenied[] = $path . ' (запрос ' . ($index + 1) . ')';
        }
    }
}

$runner->equals(
    [],
    $withoutDenied,
    'каждый запрос разбирает отказ по коду ответа'
    . (count($withoutDenied) > 0 ? ': ' . implode(', ', $withoutDenied) : '')
);

/*
 * Один значок — одно место, где он нарисован.
 *
 * Пять значков действий повторялись в шести шаблонах по два-три раза. Копия
 * значка — это копия его начертания: поправили в одном месте, в остальных
 * осталось старое, и кнопки в разных таблицах выглядели по-разному. Теперь
 * начертание лежит в карте `templates/_action_icons.php`, а шаблоны зовут его
 * по имени.
 *
 * Проверяется по разметке, а не по файлам карты: одинаковый svg в двух
 * шаблонах — это и есть та самая копия, которую ищут. Совпадения сравниваются
 * без учёта пробелов: в разметке один и тот же значок написан с разными
 * отступами, и из-за отступов копия прошла бы незамеченной.
 *
 * Пункт H ревью 2026-10-21.
 */
$runner->group('Правила интерфейса: значки действий');

$iconMap = (string) file_get_contents($root . '/templates/_action_icons.php');
$iconFiles = readFiles($root, ['templates', 'plugins'], ['php']);

$svgSeen = [];
$copiedIcons = [];

foreach ($iconFiles as $path => $src) {
    if (preg_match_all('#<svg\b.*?</svg>#s', $src, $matches) === 0) {
        continue;
    }

    foreach ($matches[0] as $svg) {
        $plain = (string) preg_replace('/\s+/', ' ', trim($svg));

        if (isset($svgSeen[$plain])) {
            $copiedIcons[] = $path . ' (тот же значок, что в ' . $svgSeen[$plain] . ')';
            continue;
        }

        $svgSeen[$plain] = $path;
    }
}

$runner->equals(
    [],
    $copiedIcons,
    'значок нарисован в одном месте: ' . implode(', ', $copiedIcons)
);

$runner->is(
    $iconMap !== '' && preg_match_all("#'([a-z_]+)' => '#", $iconMap) >= 5,
    'в карте значков действий есть все пять значков'
);

$runner->is(
    is_file($root . '/templates/_action_icon.php')
    && str_contains((string) file_get_contents($root . '/templates/_action_icon.php'), '_action_icons.php'),
    'значок рисуется через общий вызов, а не копируется в шаблон'
);

exit($runner->report());
