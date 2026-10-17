# Вход под тестовым владельцем для снимков и замеров.
#
# Headless-браузер не умеет заполнять форму входа, а куки сессии из PowerShell
# в него не переносятся. Временный вход нужен и для снимка, и для замера
# оформления, поэтому лежит в tools/ рядом с обоими сценариями и кладётся в
# public/ только на время запуска.
#
# ЗАПУСКАТЬ ТОЛЬКО НА ТЕСТОВОЙ БАЗЕ.
#
# Страница входит в оболочку без пароля. На тестовом стенде это единственный
# способ снять экран, на боевом сервере это открытый вход под владельцем,
# причём остающийся навсегда: сервер копируется целиком вместе с public/, и
# страница уезжает вместе с ним. Отсюда проверка ниже — проба отказывается
# работать с любой базой, в имени которой нет «test».
#
# Проверка выполняется до создания файла, а не после: страница появляется в
# public/ только тогда, когда право её создать уже подтверждено.
#
# Отдельная предосторожность для выкатки: на боевой сервер этот скрипт
# выкладывать не нужно. Он лежит рядом с рабочим кодом и требует сознательного
# запуска, но «сознательный» — не то же самое, что «невозможно».
param(
    [string]$Out = "public/_shot_tmp.php"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$testDb = [string]$env:MYEDU_DB_NAME

if ($testDb -notmatch 'test') {
    Write-Host "  Временный вход не создаётся: база «$testDb» не похожа на тестовую." -ForegroundColor Red
    Write-Host '  Страница входит в оболочку без пароля, поэтому допустима только' -ForegroundColor Red
    Write-Host '  на тестовой базе. Запустите тестовый сервер:' -ForegroundColor Red
    Write-Host '      tools\dev-server.ps1 -ForTests' -ForegroundColor Red
    exit 1
}

$target = Join-Path (Get-Location) $Out

# Без BOM. Out-File в Windows PowerShell пишет метку порядка байтов, а из-за неё
# «<?php» перестаёт быть первой строкой и declare(strict_types=1) даёт ошибку.
$code = @'
<?php

/*
 * Временный вход для снимка страницы и замера оформления.
 *
 * Ложится в public/ на время запуска и удаляется сразу после: лежать в
 * каталоге, который отдаётся наружу, он не должен.
 *
 * Пароль здесь не проверяется. Снимки и замеры идут один за другим, а вход
 * ограничивает число попыток на пару минут, — на третьем снимке троттл уже
 * не пускает. Скрипт кладётся рядом со стендом и удаляется вместе с ним, а
 * работает на тестовой базе, где пароль и не нужен.
 */

$root = dirname(__DIR__);

require $root . '/src/Core/Autoloader.php';

MyEdu\Core\Autoloader::create($root);

$app = MyEdu\Core\App::boot($root);

$identity = $app->get(MyEdu\Auth\Auth::class);
$users = $app->get(MyEdu\Auth\UserRepository::class);

$session = $app->get(MyEdu\Core\Session::class);
$session->start();

$row = $users->findRowByEmail('director@school.test') ?? $users->findRow(4);

if ($row === null) {
    http_response_code(500);
    echo 'Тестовый владелец не найден';

    return;
}

/*
 * Адрес приходит из строки запроса и уходит в заголовок Location. Без проверки
 * это открытый редирект, пусть и во временном файле: адрес вида //host уводит
 * браузер на чужой сайт.
 */
$path = (string) ($_GET['path'] ?? '/panel/users');

if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\n")) {
    $path = '/panel/users';
}

$identity->login($users->hydrate($row));

/*
 * Режим пробы: тот же ответ ставит сессию и показывает страницу во фрейме.
 * Так кука гарантированно успевает записаться до запроса во фрейм — при
 * переходе через Location браузер начинает второй запрос раньше, чем
 * предыдущий ответ долетел, и страница приходит без сессии.
 */
if (isset($_GET['probe'])) {
    $selector = (string) ($_GET['selector'] ?? '.cell-avatar');
    $props = (string) ($_GET['props'] ?? 'background-color');

    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PROBE:ожидание</title></head><body>'
        . '<pre id="probe">ожидание</pre>'
        . '<script>'
        . 'var target=' . json_encode($path) . ';'
        . 'var selector=' . json_encode($selector) . ';'
        . 'var props=' . json_encode($props) . '.split(",");'
        . 'var f=document.createElement("iframe");f.width=1500;f.height=900;'
        . 'f.onload=function(){var d;try{d=f.contentDocument}catch(e){d=null}'
        . 'var el=d&&d.querySelector(selector);'
        . 'if(!el){document.getElementById("probe").textContent="PROBE:not-found;url="+(d?d.location.pathname:"?");return}'
        . 'var cs=f.contentWindow.getComputedStyle(el);var b=el.getBoundingClientRect();'
        . 'var out=props.map(function(p){return p+"="+cs.getPropertyValue(p)});'
        . 'out.unshift("box="+Math.round(b.width)+"x"+Math.round(b.height));'
        . 'document.getElementById("probe").textContent="PROBE:"+out.join("; ");};'
        . 'f.src=target;document.body.appendChild(f);'
        . '<\/script></body></html>';

    return;
}

/*
 * Тема страницы.
 *
 * Выбор темы лежит в localStorage, и до первой зарисовки его ставит скрипт в
 * шапке. Снимок нужен в обеих темах, а переключатель на странице для этого не
 * годится: он перерисовывает уже снятую страницу.
 *
 * Поэтому при заданной теме ответом идёт не редирект, а крошечная страница,
 * которая записывает выбор в хранилище и уходит на нужный адрес. Файл берётся
 * из того же каталога, что и остальные скрипты проекта, а не из строки
 * запроса: политика безопасности запрещает инлайновые скрипты, и страница без
 * своего файла тихо осталась бы светлой.
 */
$theme = (string) ($_GET['theme'] ?? '');

if ($theme === 'light' || $theme === 'dark') {
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<script src="/assets/js/theme-boot.js"></script></head><body>'
        . '<script>window.localStorage.setItem("myedu-theme", ' . json_encode($theme) . ');'
        . 'window.location.replace(' . json_encode($path) . ');</script>'
        . '<noscript><a href="' . htmlspecialchars($path, ENT_QUOTES) . '">Перейти</a></noscript>'
        . '</body></html>';

    return;
}

header('Location: ' . $path, true, 302);
'@

[System.IO.File]::WriteAllText($target, $code, (New-Object System.Text.UTF8Encoding $false))

"  временный вход: $Out"