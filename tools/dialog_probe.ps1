# Проигрывает действия человека и возвращает текст: что оказалось поверх
# кнопки диалога и закрывается ли окно.
#
# По разметке это не определить: событие может не дойти до кнопки, а кнопка —
# лежать под другим элементом. Поэтому страница открывается в браузере и по
# ней проходятся настоящие клики, а результат читается из отрисованного текста.
#
# Работает так же, как tools\measure.ps1: страница копируется в public/, адреса
# стилей и скриптов становятся абсолютными (иначе на файловой странице они искали
# бы файлы рядом с ней), а проба — внешним файлом, потому что политика
# безопасности запрещает код прямо в разметке.
#
# Запуск:
#   powershell -File tools\dialog_probe.ps1 -Path /panel/users -Needle Заблокировать
param(
    [string]$Path = "/panel/users",
    [string]$Needle = "Заблокировать",
    [string]$Out = "var/dialog-probe.txt"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:8098"
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$page = Join-Path (Get-Location) "public\_probe_page.html"
$script = Join-Path (Get-Location) "public\_probe.js"

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginUrl = "$base/_shot_tmp.php?path=" + [uri]::EscapeDataString($Path)
$null = Invoke-WebRequest -Uri $loginUrl -WebSession $session -UseBasicParsing
$html = (Invoke-WebRequest -Uri "$base$Path" -WebSession $session -UseBasicParsing).Content

$needleJson = ConvertTo-Json @($Needle) -Compress

$probeJs = @"
window.addEventListener('load', function () {
  var needles = $needleJson;
  var log = [];
  var out = document.createElement('pre');
  out.id = 'probe';

  function say(text) {
    log.push(text);
    out.textContent = 'PROBE:' + log.join(' | ');
    document.body.appendChild(out);
  }

  function pickTrigger() {
    var all = document.querySelectorAll('[data-confirm]');
    for (var i = 0; i < all.length; i++) {
      for (var j = 0; j < needles.length; j++) {
        if ((all[i].getAttribute('data-confirm') || '').indexOf(needles[j]) >= 0) { return all[i]; }
      }
    }
    return null;
  }

  var summary = document.querySelector('details.row-menu > summary');

  // Меню строки есть не везде: на списке работ кнопки стоят прямо в строке.
  // Тогда окно вызывается без меню, и ждать его не нужно.
  if (summary) {
    summary.click();
    var popover = document.querySelector('.menu-popover');
    say('меню=' + (popover ? getComputedStyle(popover).display : 'нет'));
  } else {
    say('меню=нет, кнопка прямо в строке');
  }

  var trigger = pickTrigger();
  if (!trigger) { say('пункт не найден'); return; }
  say('пункт=' + trigger.tagName + ' href=' + trigger.href);

  trigger.click();

  var backdrop = document.querySelector('.modal-backdrop');
  say('окно=' + (backdrop ? 'есть' : 'нет'));
  if (!backdrop) { return; }

  var style = getComputedStyle(backdrop);
  say('backdrop z=' + style.zIndex + ' pos=' + style.position);

  var buttons = backdrop.querySelectorAll('.modal-footer button');
  say('кнопок=' + buttons.length);

  for (var k = 0; k < buttons.length; k++) {
    var rect = buttons[k].getBoundingClientRect();
    var top = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    say('«' + buttons[k].textContent.trim() + '» сверху=' + (top ? top.tagName + '.' + (top.className || '-') : 'нет'));
  }

  buttons[0].click();
  say('клик по первой кнопке отправлен');

  setTimeout(function () {
    var left = document.querySelector('.modal-backdrop');
    say('CANCEL=' + (left ? 'stuck' : 'closed'));
    say('CANCEL_WORKS=' + (left ? 'NO' : 'YES'));

    // Окно открывают заново: так видно, что собирает форма подтверждения.
    var summaryAgain = document.querySelector('details.row-menu > summary');
    if (summaryAgain) { summaryAgain.click(); }

    var trigger = pickTrigger();
    if (trigger) { trigger.click(); }

    setTimeout(function () {
      var backdrop = document.querySelector('.modal-backdrop');
      var form = backdrop ? backdrop.querySelector('form') : null;
      var names = form
        ? Array.prototype.map.call(form.querySelectorAll('input'), function (i) { return i.name + '=' + i.value; }).join(',')
        : '-';
      say('form action=' + (form ? form.getAttribute('action') : '-') + ' fields: ' + names);
    }, 600);
  }, 800);
});
"@

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$dom = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=12000 `
    --dump-dom "$base/_probe_page.html" 2>$null | Out-String

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue

# Возвращает 1, если окно не закрылось кнопкой отмены: такой код возврата
# позволяет прогнать пробу как проверку, а не только как глазок.
#
# Признаки читаются по английским меткам, а не по русским словам: инструмент
# запускается Windows PowerShell, который без метки порядка байтов читает файл
# как ANSI, и русский текст в нём может превратиться в мусор — вместе с кавычками
# внутри регулярных выражений.
if ($dom -match 'PROBE:([^<]*)') {
    $text = $Matches[1]

    if ($Out) {
        [System.IO.File]::WriteAllText((Join-Path (Get-Location) $Out), $text, (New-Object System.Text.UTF8Encoding($false)))
    }

    $text

    if ($text -match 'CANCEL_WORKS=NO') {
        exit 1
    }

    exit 0
} else {
    "  probe did not run"
    exit 1
}