# Пути закрытия окна: крестик, Escape, щелчок по фону и повторное открытие.
#
# Скрипт modal-open.js общий для всех окон, поэтому проверяется он целиком.
# Повторное открытие — не «для галочки»: закрытие убирало подложку вместе с
# окном, и второе открытие молча не срабатывало. Ни один набор этого не видит:
# событие приходит из браузера, а не из разметки.
#
# Работает как tools\dialog_probe.ps1: страница копируется в public/, проба —
# внешним файлом, потому что политика безопасности запрещает код в разметке.
#
# Запуск:
#   powershell -File tools\shot_login.ps1
#   powershell -File tools\close_probe.ps1
#
# Возвращает 1, если окно не проходит какой-либо путь: код возврата позволяет
# прогнать пробу как проверку, а не только как глазок.
param(
    [string]$Path = "/panel/subjects",
    [string]$OpenSelector = '[data-modal-open="subject-add"]'
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:8098"
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$page = Join-Path (Get-Location) "public\_close_probe.html"
$script = Join-Path (Get-Location) "public\_close_probe.js"

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

# Селектор кнопки открытия подставляется в пробу плейсхолдером: строка JS
# пишется в here-string, где переменные PowerShell не раскрываются, а кавычки
# внутри [data-modal-open="..."] нужно экранировать. Плейсхолдер решает обе
# задачи разом.
$openSelectorJson = ConvertTo-Json $OpenSelector -Compress

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginUrl = "$base/_shot_tmp.php?path=" + [uri]::EscapeDataString($Path)
$null = Invoke-WebRequest -Uri $loginUrl -WebSession $session -UseBasicParsing
$html = (Invoke-WebRequest -Uri "$base$Path" -WebSession $session -UseBasicParsing).Content

$probeJs = @'
window.addEventListener('load', function () {
  var selector = __OPEN_SELECTOR__;
  var log = [];
  var out = document.createElement('pre');
  out.id = 'probe';

  function say(t) { log.push(t); out.textContent = 'PROBE:' + log.join(' | '); document.body.appendChild(out); }
  function isOpen() { return !!document.querySelector('.modal-backdrop'); }
  function open() {
    var b = document.querySelector(selector);
    if (b) { b.click(); }
    return !!b;
  }
  function wait(ms, then) { setTimeout(function () { try { then(); } catch (e) { say('ERROR=' + e.message); } }, ms); }

  say('start_open=' + isOpen());
  say('opened=' + open());
  wait(300, function () {
    var dialogs = document.querySelectorAll('.modal-dialog');
    say('dialogs=' + dialogs.length);

    // Крестик.
    var x = document.querySelector('[data-modal] [data-modal-close]');
    say('close_button=' + (x ? 'есть' : 'нет'));
    if (x) { x.click(); }
    wait(400, function () {
      say('after_x_closed=' + (!isOpen()));

      // Escape.
      open();
      wait(300, function () {
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        wait(400, function () {
          say('after_escape_closed=' + (!isOpen()));

          // Щелчок по фону окна.
          open();
          wait(300, function () {
            var backdrop = document.querySelector('[data-modal-backdrop]');
            say('backdrop_found=' + !!backdrop);
            if (!backdrop) { say('VERDICT=FAIL нет подложки'); return; }
            var r = backdrop.getBoundingClientRect();
            say('backdrop_box=' + Math.round(r.width) + 'x' + Math.round(r.height));
            var target = document.elementFromPoint(r.left + 8, r.top + 8);
            say('point_hits=' + (target ? target.tagName + '.' + (target.className || '-') : 'нет'));
            var ev = new MouseEvent('click', { bubbles: true, clientX: r.left + 8, clientY: r.top + 8 });
            backdrop.dispatchEvent(ev);
            wait(400, function () {
              say('after_backdrop_closed=' + (!isOpen()));

              // Повторное открытие после всех закрытий.
              open();
              wait(400, function () {
                say('reopen_open=' + isOpen());
                var body = document.querySelector('.modal-body');
                say('body=' + (body ? 'есть' : 'нет'));
                say('form_fields=' + document.querySelectorAll('.modal-backdrop input, .modal-backdrop select').length);
                say('VERDICT=' + (
                  log.join(' ').indexOf('after_x_closed=true') >= 0
                  && log.join(' ').indexOf('after_escape_closed=true') >= 0
                  && log.join(' ').indexOf('after_backdrop_closed=true') >= 0
                  && log.join(' ').indexOf('reopen_open=true') >= 0
                  && log.join(' ').indexOf('dialogs=1') >= 0
                  ? 'OK' : 'FAIL'
                ));
              });
            });
          });
        });
      });
    });
  });
});
'@

$html = $html.Replace('</body>', '<script src="/_close_probe.js"></script></body>')
$probeJs = $probeJs.Replace('__OPEN_SELECTOR__', $openSelectorJson)
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$dom = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=20000 `
    --dump-dom "$base/_close_probe.html" 2>$null | Out-String

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue

if ($dom -match 'PROBE:([^<]*)') {
    $Matches[1] -replace ' \| ', "`n"
    if ($Matches[1] -match 'VERDICT=OK') { exit 0 } else { exit 1 }
} else {
    "  проба не отработала"
    exit 1
}