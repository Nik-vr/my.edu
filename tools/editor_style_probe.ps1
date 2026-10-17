# Проверка оформления редактора в окне задания и подвала.
#
# Обе проблемы видны только в браузере, и обе связаны с тем, что страницу
# открывают по-разному:
#
#   - окно задания открывают щелчком по карандашу, и форму в него приносит
#     скрипт уже после загрузки страницы; если стили редактора приехали только
#     вместе с формой, их в документе не окажется, и редактор покажет сырые
#     кнопки вместо своей панели;
#   - подвал проверяется по смещению от левого края: он должен начинаться там
#     же, где начинается содержимое страницы, и не уезжать под сайдбар.
#
# Проверяется не наличие тегов в разметке, а посчитанные браузером размеры:
# ссылка на стиль в разметке ничего не говорит о том, что её применили.
#
# Вход делает временный файл в public/, который живёт только на время пробы.
# Он нужен, чтобы страницу проверки загрузил браузер с сессией: сама страница
# открывается во встроенном окне (iframe) того же адреса, и без этого запрос
# ушёл бы на страницу входа, а окно задания — то, что и требуется проверить, —
# так и не наполнилось бы.
#
# ЗАПУСКАТЬ ТОЛЬКО НА ТЕСТОВОЙ БАЗЕ.
#
# Временный файл входит в оболочку без пароля. На тестовом стенде это нужно,
# на боевом сервере это открытый вход под владельцем, причём остающийся навсегда:
# сервер копируется целиком вместе с public/, и страница уезжает вместе с ним.
# Поэтому проба отказывается работать, если в имени базы нет «test», — проверка
# идёт по переменной MYEDU_DB_NAME, которую выставляет тестовый запуск
# (tools\dev-server.ps1 -ForTests, tools\run_tests.ps1). Обычный сервер на 8088
# её не выставляет, и проба откажется.
#
# Запуск:
#   powershell -File tools\editor_style_probe.ps1
#   powershell -File tools\editor_style_probe.ps1 -Work 29 -Email nik_vr@mail.ru
param(
    [int]$Work = 0,
    [string]$Email = "director@school.test",
    [string]$Port = "8098"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:$Port"
$probeFile = Join-Path (Get-Location) "public\_style_probe_tmp.php"

$testDb = [string]$env:MYEDU_DB_NAME

if ($testDb -notmatch 'test') {
    Write-Host "  Проба не запускается: база «$testDb» не похожа на тестовую." -ForegroundColor Red
    Write-Host '  Временный вход в оболочку без пароля допустим только на тестовой' -ForegroundColor Red
    Write-Host '  базе. Запустите тестовый сервер: tools\dev-server.ps1 -ForTests' -ForegroundColor Red
    exit 1
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

# Работа ищется сама: берётся первая, чей редактор эта сессия может открыть.
# Чужие работы видны в списке, но не редактируются, и такой номер не годится.
if ($Work -eq 0) {
    foreach ($state in @('drafts', 'published', 'archived')) {
        $listHtml = (Invoke-WebRequest -Uri "$base/panel/assignments?state=$state" -WebSession $session -UseBasicParsing).Content
        $match = [regex]::Match($listHtml, 'href="/panel/assignments/(\d+)"')

        if (-not $match.Success) {
            continue
        }

        $candidate = $match.Groups[1].Value

        try {
            $opened = (Invoke-WebRequest -Uri "$base/panel/assignments/$candidate/edit/items" -WebSession $session -UseBasicParsing).Content
        } catch {
            continue
        }

        if ($opened -match 'data-editor-open') {
            $Work = [int] $candidate

            break
        }
    }
}

if ($Work -eq 0) {
    "  Не нашлась работа, которую эта сессия может открыть в редакторе."
    "  Создайте черновик под этой учётной записью или укажите номер: -Work 1"
    exit 1
}

$php = @'
<?php

/*
 * Временный помощник пробы оформления: входит под тестовой учётной записью и
 * отдаёт страницу с измерениями. Живёт только во время пробы и удаляется ею же.
 */

$root = dirname(__DIR__);

require $root . '/src/Core/Autoloader.php';

MyEdu\Core\Autoloader::create($root);

$app = MyEdu\Core\App::boot($root);
$identity = $app->get(MyEdu\Auth\Auth::class);
$users = $app->get(MyEdu\Auth\UserRepository::class);
$app->get(MyEdu\Core\Session::class)->start();

$row = $users->findRowByEmail('<?= $probeEmail ?>') ?? $users->findRow(4);

if ($row === null) {
    http_response_code(500);

    return;
}

$identity->login($users->hydrate($row));

$work = (int) ($_GET['work'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Проба оформления</title></head>
<body>
<pre id="probe">PROBE:waiting</pre>
<iframe id="page" width="1500" height="620" src="/panel/assignments/<?= $work ?>/edit/items"></iframe>
<script>
var log = [];
var frame = document.getElementById('page');
var probe = document.getElementById('probe');

function say(text) {
    log.push(text);
    probe.textContent = 'PROBE:' + log.join(' | ');
}

function box(node) {
    return node ? node.getBoundingClientRect() : null;
}

frame.onload = function () {
    var doc;

    try {
        doc = frame.contentDocument;
    } catch (e) {
        doc = null;
    }

    if (!doc) {
        say('IFRAME=no');

        return;
    }

    var win = frame.contentWindow;
    var footer = box(doc.querySelector('.footer'));
    var wrapper = box(doc.querySelector('.page-wrapper'));

/*
 * Прокрутка страницы до и после открытия окна — обоими входами.
 *
 * Страница доводится до середины: вверху разница не видна, там и так
 * scrollY = 0. Отдельно отмечено, что приём Ctrl+R прокрутку возвращает, а
 * переход по ссылке — нет, поэтому проверяется именно щелчок.
 *
 * Входов два: карандаш и название задания. Раньше они вели себя по-разному:
 * карандаш открывал окно на месте, а название было обычной ссылкой и
 * перезагружало страницу — автор возвращался наверх доски и терял место.
 */
win.scrollTo(0, 600);

var link = doc.querySelector('[data-editor-open]');
var title = doc.querySelector('[data-task-title][data-editor-open]');

/*
 * Обычная ссылка, то есть именно та, что перезагружает страницу. Ищется среди
 * ссылок: пустая подпись задания — это span без адреса, и обычным «не
 * перехваченным элементом» она не является.
 */
var bareTitle = doc.querySelector('a[data-task-title]:not([data-editor-open])');

var quillMeasured = false;

/*
 * Подвал начинается там же, где содержимое: сдвиг влево означал бы, что он
 * оказался под сайдбаром.
 */
say('FOOTER_AT=' + (footer && wrapper
    ? (Math.abs(footer.left - wrapper.left) < 2 ? 'content' : 'off')
    : 'none'));
say('TITLE_PLAIN=' + (bareTitle ? 'yes' : 'no'));

function openVia(node, label, done) {
    if (!node) {
        say(label + '=none');

        return done();
    }

    var before = Math.round(win.scrollY || doc.documentElement.scrollTop || 0);

    node.click();

    var deadline = Date.now() + 8000;

    (function wait() {
        var form = doc.querySelector('[data-editor-body] form');

        if (!form && Date.now() < deadline) {
            win.setTimeout(wait, 120);

            return;
        }

        if (!form) {
            say(label + '_FORM=no');

            return done();
        }

        var after = Math.round(win.scrollY || doc.documentElement.scrollTop || 0);
        var body = doc.querySelector('.modal-body');
        var active = doc.activeElement;

        /*
         * Разделитель — дефис, а не «>»: страница отдаётся из выгрузки
         * браузера с экранированными знаками, и «>» в проверке просто не
         * нашлось бы.
         */
        say(label + '_SCROLL=' + before + '-' + after);
        say(label + '_FORM=yes');
        say(label + '_MODAL_SCROLL=' + (body ? Math.round(body.scrollTop) : -1));
        say(label + '_ACTIVE=' + (active ? active.tagName.toLowerCase() + '.' + (active.className || '-') : 'none'));

        /*
         * Оформление редактора меряется на первом открытом окне — в каком бы
         * оно ни было. Привязка к названию задания была бы ошибкой: на работе
         * без текстовых названий ссылки там нет вовсе, и проверка падала бы на
         * отсутствии того, чего и не должно быть.
         */
        if (!quillMeasured) {
            var sheets = 0;
            var i;

            for (i = 0; i < doc.styleSheets.length; i++) {
                if ((doc.styleSheets[i].href || '').indexOf('quill') !== -1) {
                    sheets++;
                }
            }

            quillMeasured = true;

            say('QUILL_SHEETS=' + sheets);
            say('BUTTON_H=' + Math.round((box(doc.querySelector('.ql-toolbar button')) || { height: 0 }).height));
            say('EDITOR_PAD=' + Math.round(
                parseFloat(win.getComputedStyle(doc.querySelector('.ql-editor')).paddingLeft) || 0
            ));
        }

        // Окно закрывается тем же путём, каким его открывает человек.
        var closer = doc.querySelector('[data-modal-close]');

        if (closer) {
            closer.click();
        }

        win.setTimeout(done, 400);
    })();
}

/*
 * Отсутствие кнопки редактирования само по себе ещё ничего не значит: на
 * странице может не быть карточек вовсе — например, работа удалена наборами
 * проверок, и её номер освободился. Об этом нужно сказать прямо, иначе пустая
 * страница выглядит как поломка продукта и сбивает с толку.
 */
if (!link) {
    var cards = doc.querySelectorAll('[data-item-id]').length;

    if (cards === 0) {
        say('NO_CARDS=1');
    } else {
        say('OPEN_LINK=no');
    }

    return;
}

say('OPEN_LINK=yes');

openVia(title, 'TITLE', function () {
    openVia(link, 'PENCIL', function () {});
});
};
</script>
</body>
</html>
'@

$php = $php.Replace('<?= $probeEmail ?>', $Email.Replace("'", "''"))

[System.IO.File]::WriteAllText($probeFile, $php, (New-Object System.Text.UTF8Encoding($false)))

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"

if (-not (Test-Path $edge)) {
    Remove-Item $probeFile -Force -ErrorAction SilentlyContinue

    "  Не найден браузер для проверки: $edge"
    exit 1
}

$profile = Join-Path $env:TEMP ("myedu-style-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --virtual-time-budget=20000 `
    --dump-dom "$base/_style_probe_tmp.php?work=$Work" 2>$null | Out-String

Remove-Item $probeFile -Force -ErrorAction SilentlyContinue
Remove-Item $profile -Recurse -Force -ErrorAction SilentlyContinue

if ($dom -notmatch 'PROBE:([^<]*)') {
    "  Проба не отработала: результата на странице нет."
    exit 1
}

$text = $Matches[1]
$text

if ($text -match 'NO_CARDS=1') {
    "  Проверять нечего: на работе $Work нет ни одной карточки задания."
    "  Назвать такую работу не ошибка продукта — номера освобождаются, когда"
    "  наборы проверок удаляют свои работы. Укажите ту, где задания есть."
    exit 0
}

$bad = @()

# Проверяется не «высота вообще», а то, что задаёт сама тема: у кнопки панели
# 24px, у поля редактора отступ 15px. Оба числа взяты из quill.snow.css, а не
# выбраны на глаз: иначе проверка ловила бы не поломку, а собственное
# представление о красоте.
#
# Прокрутка сверяется по обоим входам в редактор: уехать вверх должна была бы
# страница, открытая ссылкой, и не должна ни одна.
foreach ($rule in @(
    @{ Key = 'FOOTER_AT'; Want = 'content'; What = 'подвал начинается не там же, где содержимое страницы: уезжает под сайдбар' },
    @{ Key = 'TITLE_PLAIN'; Want = 'no'; What = 'название задания осталось обычной ссылкой и перезагружает страницу' },
    @{ Key = 'PENCIL_FORM'; Want = 'yes'; What = 'окно не открылось по кнопке «Редактировать»' },
    @{ Key = 'QUILL_SHEETS'; Want = '2'; What = 'стили редактора не в документе' },
    @{ Key = 'BUTTON_H'; Want = '24'; What = 'кнопки панели инструментов не те, что рисует Quill' },
    @{ Key = 'EDITOR_PAD'; Want = '15'; What = 'поле редактора без отступа темы: стили не применились' }
)) {
    if ($text -notmatch ($rule.Key + '=' + $rule.Want)) {
        $bad += $rule.What
    }
}

# Прокрутка сверяется по обоим входам в редактор: уехать вверх должна была бы
# страница, открытая ссылкой, и не должна ни одна.
#
# Вход по названию проверяется только там, где название есть: на работе без
# текстовых названий ссылки на карточке нет, и требовать её было бы требованием
# несуществующего.
foreach ($key in @('PENCIL_SCROLL')) {
    $moved = [regex]::Match($text, $key + '=(\d+)-(\d+)')

    if (-not $moved.Success -or $moved.Groups[1].Value -ne $moved.Groups[2].Value) {
        $bad += "страница уехала вверх при открытии окна ($key)"
    }
}

if ($text -match 'TITLE_SCROLL=(\d+)-(\d+)') {
    if ($Matches[1] -ne $Matches[2]) {
        $bad += 'страница уехала вверх при открытии окна по названию задания'
    }

    if ($text -notmatch 'TITLE_FORM=yes') {
        $bad += 'окно не открылось по названию задания'
    }
}

if ($bad.Count -gt 0) {
    ""
    "НАЙДЕНО: " + ($bad -join '; ')
    exit 1
}

"  Проверка пройдена: подвал на месте, редактор оформлен."