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
// Пункт M1 ревью 2026-10-18.
$runner->group('Правила интерфейса: подтверждения');

$known = [
    'public/assets/js/assignment-items.js:31' => 'M1, переводится на data-confirm в волне 2',
    'public/assets/js/attempt.js:270' => 'M1, переводится на data-confirm в волне 2',
];

$found = findAll($js, '/window\.confirm|(?<![\w.])confirm\(/');
$new = newOnes($found, $known);

$runner->equals(
    [],
    $new,
    'нативных window.confirm больше нет: ' . (count($found) > count($known) ? describeHits($new) : '')
);

$runner->is(
    count($found) === count($known),
    'число известных нарушений совпадает со списком (' . count($found) . ' из ' . count($known) . '): '
    . 'список разошёлся с кодом, и это тоже поломка проверки'
);

// ---------------------------------------------------------------------------
// 2. Инлайновые стили в разметке.
//
// Причина одна: правило в таблице стилей перекрывается стилем Gentelella
// позже по умолчанию, а инлайновый — никогда. Находилось ноль, и правило
// остаётся как запрет на возврат.
$runner->group('Правила интерфейса: разметка');

$inline = findAll($templates, '/\sstyle="[^"]{1,200}"/');
$runner->equals([], $inline, 'инлайновых style= в шаблонах нет: ' . describeHits($inline));

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
    'templates/panel/subjects.php' => 'M2, вывод убирается в волне 2',
    'templates/panel/users.php' => 'M2, вывод убирается в волне 2',
    'templates/panel/plugins.php' => 'M2, вывод убирается в волне 2',
    'templates/panel/user_edit.php' => 'M2, вывод убирается в волне 2',
    'templates/auth/profile.php' => 'M2, вывод убирается в волне 2',
    'plugins/test/templates/work_review.php' => 'M2, страница плагина в той же оболочке',
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
$errorNodes = findAll($js, '/banner\s+form-error/');
$newErrorNodes = newOnes($errorNodes, ['public/assets/js/form-validate.js:243' => 'M3, приводится к div.form-error в волне 2']);

$runner->equals(
    [],
    $newErrorNodes,
    'разметка ошибки поля в скриптах не разошлась: ' . describeHits($newErrorNodes)
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
// 7. Тост об ошибке объявляется как тревога.
//
// `role="status"` читается вспомогательными технологиями и не прерывает
// чтение. Для сообщения об ошибке это неверно: пункт M5.
$toasts = findAll($js, '/role=["\']status["\']/');

$runner->equals(
    [],
    newOnes($toasts, []),
    'роль status в тостах не используется: ' . describeHits($toasts)
);

// ---------------------------------------------------------------------------
// 8. Тёмная тема объявлена селектором, который срабатывает.
//
// Найдено при подготовке волны 1, а не в самом отчёте.
//
// В разметке стоит `data-theme="dark"`, и скрипт в шапке тоже всегда ставит
// атрибут — «dark» по умолчанию либо сохранённый выбор. А тёмные значения
// объявлены в `theme.css` под селектором `html:not([data-theme])`, то есть
// рассчитаны на страницу, у которой атрибута нет. Такой страницы не бывает:
// либо скрипт выполнился и атрибут стоит, либо скрипты запрещены — и тогда
// атрибут остаётся из разметки. В обоих случаях тёмные правила не
// применяются, и сайт остаётся светлым при любом выборе темы.
//
// Из этого следует сразу несколько пунктов волны 1 (V1 — светлая карточка на
// белом фоне, V4, V10, V13 — контраст в тёмной теме): лечить их по одному
// бессмысленно, пока тёмная тема не включается.
//
// Проверка держит долг: пока селекторы те же, набор зелёный, и запись видна
// всем, кто его запускает. Любое новое расхождение — например появление
// `html:not([data-theme])` в новом месте или смена атрибута в разметке — падает
// сразу.
$runner->group('Правила интерфейса: тёмная тема');

/*
 * Комментарии убираются: иначе правило ищется в тексте, который его
 * описывает. В theme.css есть строка «в правиле [data-theme="dark"]» — и
 * проверка радостно сообщала бы, что тёмная тема объявлена верно, хотя
 * правила нет нигде.
 */
$themeCss = stripCssComments((string) file_get_contents($root . '/public/assets/css/theme.css'));
$appCss = stripCssComments((string) file_get_contents($root . '/public/assets/css/app.css'));
$shellText = '';

foreach (readFiles($root, ['templates'], ['php']) as $src) {
    $shellText .= $src . "\n";
}

$runner->is(
    preg_match('/<html[^>]*data-theme="dark"/', $shellText) === 1,
    'тема объявлена в разметке явно: дальше ищем правила под неё'
);

$themeProblems = [];

if (preg_match('/\[data-theme="dark"\]/', $themeCss) !== 1) {
    $themeProblems[] = 'в theme.css нет правила под [data-theme="dark"]';
}

if (preg_match('/html:not\(\[data-theme\]\)/', $themeCss) === 1) {
    $themeProblems[] = 'тёмные значения лежат в html:not([data-theme]) — правило не срабатывает никогда';
}

if (preg_match('/\[data-theme="light"\]/', $themeCss . $appCss) !== 1) {
    $themeProblems[] = 'светлая тема не объявлена явно';
}

$knownThemeProblems = [
    'в theme.css нет правила под [data-theme="dark"]',
    'тёмные значения лежат в html:not([data-theme]) — правило не срабатывает никогда',
    'светлая тема не объявлена явно',
];

$runner->equals(
    $knownThemeProblems,
    $themeProblems,
    'состав известного долга по теме не изменился (пустой список — долг снят)'
);

exit($runner->report());