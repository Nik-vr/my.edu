# Проверка перестановки строк доски: не уходит ли страница и не плодит ли
# запросы быстрое перетаскивание.
#
# Зачем проба, если есть набор проверок. Проверка по тексту файла не отличает
# работающую очередь от красивой: строка «пока отправка в полёте, следующая ждёт»
# может ничего не делать. Здесь два перетаскивания подряд, а ответы отдаются
# по одному — и видно, сколько запросов ушло на самом деле.
#
# Настоящий перезапуск проверить нельзя: он увёл бы пробу с доски, поэтому
# отправка подменена. Что проверяется — решение скрипта: сколько раз он дёргает
# сеть, что уходит в тело запроса и какие номера остаются на строках.
#
# Запуск:
#   powershell -File tools\board_probe.ps1
#   powershell -File tools\board_probe.ps1 -Port 8098
param(
    [int]$Port = 8098,
    [string]$Out = "var/board-probe.txt"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:$Port"
$page = Join-Path (Get-Location) "public\_board_probe.html"
$script = Join-Path (Get-Location) "public\_board_probe.js"

$html = @'
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Проба перестановки строк</title></head>
<body>
<ul id="task-list">
  <li class="board-row" data-row="1"><span class="board-row__num">1</span><div class="work-card" data-item-id="11"></div></li>
  <li class="board-row" data-row="2"><span class="board-row__num">2</span><div class="work-card" data-item-id="22"></div></li>
  <li class="board-row" data-row="3"><span class="board-row__num">3</span><div class="work-card" data-item-id="33"></div></li>
  <li class="board-row board-row--spare" data-spare-row="1"><span class="board-row__num"></span></li>
</ul>
<form method="post" id="task-order-form" action="/panel/assignments/1/edit/items/reorder">
  <input type="hidden" name="_token" value="токен">
</form>
<script src="/assets/js/assignment-items.js"></script>
<script src="/_board_probe.js"></script>
</body>
</html>
'@

$expect = [ordered]@{
    HAS_LIST        = 'yes'
    HAS_FORM        = 'yes'
    TOKEN_SENT      = 'yes'
    CALLS_AFTER_TWO = '1'
    CALLS_TOTAL     = '2'
    BODY_HAS_ORDER  = 'yes'
    NO_FORM_INPUTS  = 'yes'
}

$probeJs = @'
(function () {
    'use strict';

    var log = [];
    var out = document.createElement('pre');
    out.id = 'probe';

    function say(text) {
        log.push(text);
        out.textContent = 'PROBE:' + log.join(' | ');
        document.body.appendChild(out);
    }

    var calls = [];
    var pending = [];

    /* Ответ сервера: номера строк подряд, как он их и присылает. */
    window.fetch = function (url, options) {
        calls.push({ url: String(url), body: String((options && options.body) || '') });

        return new Promise(function (resolve) {
            pending.push(function () {
                resolve({
                    status: 200,
                    redirected: false,
                    json: function () {
                        return Promise.resolve({ ok: true, order: [1, 2, 3] });
                    },
                });
            });
        });
    };

    window.myEduToast = function () {};

    function rows() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#task-list .board-row:not(.board-row--spare)')
        );
    }

    function drop(row) {
        row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: new DataTransfer() }));
        document.getElementById('task-list').dispatchEvent(new DragEvent('dragend', { bubbles: true }));
    }

    window.addEventListener('load', function () {
        var list = document.getElementById('task-list');
        var form = document.getElementById('task-order-form');

        say('HAS_LIST=' + (list ? 'yes' : 'no'));
        say('HAS_FORM=' + (form ? 'yes' : 'no'));
        say('TOKEN_SENT=' + (form.querySelector('input[name="_token"]') ? 'yes' : 'no'));

        /* Две перестановки подряд, как при быстром перетаскивании. */
        drop(rows()[0]);
        drop(rows()[2]);

        say('CALLS_AFTER_TWO=' + calls.length);

        var answer = pending.shift();

        if (answer) {
            answer();
        }

        window.setTimeout(function () {
            var queued = pending.shift();

            if (queued) {
                queued();
            }

            window.setTimeout(function () {
                say('CALLS_TOTAL=' + calls.length);
                say('BODY_HAS_ORDER=' + (calls.length > 0 && calls[0].body.indexOf('order%5B%5D=1') !== -1 ? 'yes' : 'no'));
                say('NO_FORM_INPUTS=' + (form.querySelectorAll('input[name="order[]"]').length === 0 ? 'yes' : 'no'));
            }, 50);
        }, 50);
    });
})();
'@

[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-board-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --virtual-time-budget=12000 `
    --dump-dom "$base/_board_probe.html" 2>$null | Out-String

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue
Remove-Item $profile -Recurse -Force -ErrorAction SilentlyContinue

if ($dom -match 'PROBE:([^<]*)') {
    $text = $Matches[1]

    if ($Out) {
        [System.IO.File]::WriteAllText(
            (Join-Path (Get-Location) $Out),
            $text,
            (New-Object System.Text.UTF8Encoding($false))
        )
    }

    $text

    $bad = @()

    foreach ($key in $expect.Keys) {
        if ($text -notmatch ('\b' + $key + '=' + $expect[$key] + '\b')) {
            $bad += "$key=$($expect[$key])"
        }
    }

    if ($bad) {
        "  не совпало: " + ($bad -join ', ')
        exit 1
    }

    exit 0
} else {
    "  проба не отработала: сервер на $base не отвечает или страница не загрузилась"
    exit 1
}