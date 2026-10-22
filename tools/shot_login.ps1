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

/*
 * Кем входить.
 *
 * Сначала — владелец демонстрационной базы, потом любой сотрудник: база
 * набора меняется от прогона к прогону, и человек с номером 4 или с адресом
 * director@school.test на ней есть не всегда. Прежний выбор из двух строк
 * оставлял пробы без входа — и они молча измеряли страницу входа, ничего не
 * находя, — поэтому берётся тот, у кого есть право видеть панель.
 *
 * Администратор идёт первым: список сотрудников открыт только ему, и проба
 * смотрит именно его.
 *
 * Порядок именно такой. Сначала адрес, потом тот, у кого есть роль с
 * доступом к панели, и только потом человек с номером 4 — как последний
 * довод. Номер 4 в разных базах достаётся разным людям: на одной это
 * сотрудник, на другой — ученик, и вход под учеником давал «недостаточно
 * прав» на любой странице панели. Проба при этом не падала, а молча
 * смотрела на страницу отказа.
 */
$row = $users->findRowByEmail('director@school.test');

if ($row === null) {
    foreach ([MyEdu\Auth\Role::ADMIN, MyEdu\Auth\Role::HEAD_TEACHER, MyEdu\Auth\Role::TEACHER] as $wanted) {
        foreach ($users->allWithRoles() as $candidate) {
            if (in_array($wanted, $users->rolesOf((int) $candidate['id']), true)) {
                $row = $candidate;
                break 2;
            }
        }
    }
}

if ($row === null) {
    $row = $users->findRow(4);
}

if ($row === null) {
    http_response_code(500);
    echo 'В базе нет сотрудника: временный вход нужен только для проверок на тестовой базе';

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

    /*
     * Окно страницы стоит в разметке, а не создаётся скриптом.
     *
     * Проба запускается браузером с выгрузкой DOM по виртуальному времени: он
     * читает страницу, когда виртуальное время кончилось. Созданное скриптом
     * окно к этому моменту успевает загрузиться не всегда, и проба молча
     * остаётся со словом «ожидание» — выглядит как поломка страницы, хотя
     * ничего не сломано. Окно в разметке грузится вместе с документом, и
     * событие загрузки на него всегда приходит.
     */
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PROBE:ожидание</title></head><body>'
        . '<pre id="probe">ожидание</pre>'
        . '<iframe id="page" width="1500" height="900" src="' . htmlspecialchars($path, ENT_QUOTES) . '"></iframe>'
        . '<script>'
        . 'var selector=' . json_encode($selector) . ';'
        . 'var props=' . json_encode($props) . '.split(",");'
        . 'var frame=document.getElementById("page");'
        . 'var probe=document.getElementById("probe");'
        . 'frame.onload=function(){var d;try{d=frame.contentDocument}catch(e){d=null}'
        . 'if(!d){probe.textContent="PROBE:no-document";return}'
        . 'var el=d.querySelector(selector);'
        . 'if(!el){probe.textContent="PROBE:not-found;url="+d.location.pathname;return}'
        . 'var cs=frame.contentWindow.getComputedStyle(el);var b=el.getBoundingClientRect();'
        . 'var out=props.map(function(p){return p+"="+cs.getPropertyValue(p).trim()});'
        . 'out.unshift("box="+Math.round(b.width)+"x"+Math.round(b.height));'
        . 'probe.textContent="PROBE:"+out.join("; ");};'
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