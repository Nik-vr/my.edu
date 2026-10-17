# Проверяет в браузере, как form-validate.js ведёт себя с полем и с группой
# «отметьте хоть что-то», и возвращает код возврата, пригодный для запуска как
# проверки.
#
# Зачем это отдельный инструмент, а не проверка в наборе тестов: по разметке
# поведение не определить. Сводка ошибок может исчезнуть раньше, чем исправлено
# последнее плохое поле; форма, добавленная скриптом, может остаться без
# проверки; введённое значение может стереться. Здесь всё это видно только в
# настоящем браузере — по отрисованной странице и реальным событиям.
#
# Работает так же, как tools\dialog_probe.ps1: страница копируется в public/, а
# проба — внешним файлом, потому что политика безопасности запрещает код прямо в
# разметке.
#
# Запуск:
#   powershell -File tools\validate_probe.ps1
#   powershell -File tools\validate_probe.ps1 -Path /panel/issues/new
param(
    [string]$Path = "/classes/new",
    [string]$Out = "var/validate-probe.txt"
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

# Разметка пробы повторяет шаблоны: data-required-group стоит на контейнере
# (HTML требует выбор только у радиокнопок, а «отметьте хоть одного ученика» —
# это список флажков), а сообщение задаётся через data-hint.
#
# Ожидания перечислены здесь же и читаются в порядке выполнения:
#   BOUND=yes         — форма, добавленная после загрузки, тоже под проверкой
#   BLOCK_EMPTY=yes   — пустая форма не отправляется
#   SUMMARY_EMPTY=yes — сводка появилась
#   FIELD_MARKED=yes  — поле подсвечено и под ним написано, что не так
#   GROUP_TEXT=yes    — у группы своё сообщение
#   AFTER_FIELD=yes   — сводка осталась: группа-то ещё пуста
#   FIELD_CLEARED=yes — ошибка поля снялась после исправления
#   FINAL=no          — всё исправлено, форма отправляется
#   VALUE=x           — введённое не стёрто
$probeJs = @'
window.addEventListener('load', function () {
  var log = [];
  var out = document.createElement('pre');
  out.id = 'probe';

  function say(text) {
    log.push(text);
    out.textContent = 'PROBE:' + log.join(' | ');
    document.body.appendChild(out);
  }

  var form = document.createElement('form');
  form.innerHTML =
    '<label>Title<input name="title" required></label>' +
    '<div data-required-group data-hint="Pick one">' +
    '<label><input type="checkbox" name="pick" value="1"></label>' +
    '</div>' +
    '<button type="submit">Go</button>';
  document.body.appendChild(form);

  setTimeout(function () {
    var title = form.querySelector('[name="title"]');
    var pick = form.querySelector('[name="pick"]');
    var group = form.querySelector('[data-required-group]');
    var blocked = null;

    function summary() {
      var node = form.querySelector('.form-summary');
      return node ? node.textContent.trim() : '';
    }

    /*
     * Слушатель добавляется после модуля и читает defaultPrevented до своего
     * собственного preventDefault: так в флаге видно, останавливал ли отправку
     * сам модуль, а не проба.
     */
    form.addEventListener('submit', function (event) {
      blocked = event.defaultPrevented;
      event.preventDefault();
    });

    function submit() {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      return blocked;
    }

    say('BOUND=' + (form.hasAttribute('data-validate-ready') ? 'yes' : 'no'));
    say('BLOCK_EMPTY=' + (submit() ? 'yes' : 'no'));
    say('SUMMARY_EMPTY=' + (summary() ? 'yes' : 'no'));
    say('FIELD_MARKED=' + (title.className.indexOf('is-invalid') >= 0 ? 'yes' : 'no'));
    say('GROUP_TEXT=' + (group.querySelector('.form-error') ? 'yes' : 'no'));

    // Человек дописал название и ушёл из поля: срабатывает blur.
    title.value = 'x';
    title.dispatchEvent(new FocusEvent('blur', { bubbles: false }));
    say('FIELD_CLEARED=' + (title.className.indexOf('is-invalid') >= 0 ? 'no' : 'yes'));
    say('AFTER_FIELD=' + (summary() ? 'yes' : 'no'));
    say('BLOCK_AFTER_FIELD=' + (submit() ? 'yes' : 'no'));

    pick.checked = true;
    pick.dispatchEvent(new Event('change', { bubbles: true }));
    say('FINAL=' + (submit() ? 'yes' : 'no'));
    say('VALUE=' + title.value);
  }, 600);
});
'@

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$dom = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=10000 `
    --dump-dom "$base/_probe_page.html" 2>$null | Out-String

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue

# Признаки читаются по английским меткам, а не по русским словам: инструмент
# запускается Windows PowerShell, который без метки порядка байтов читает файл
# как ANSI, и русский текст в нём может превратиться в мусор.
$expect = @{
    'BOUND'           = 'yes'
    'BLOCK_EMPTY'     = 'yes'
    'SUMMARY_EMPTY'   = 'yes'
    'FIELD_MARKED'    = 'yes'
    'GROUP_TEXT'      = 'yes'
    'FIELD_CLEARED'   = 'yes'
    'AFTER_FIELD'     = 'yes'
    'BLOCK_AFTER_FIELD' = 'yes'
    'FINAL'           = 'no'
    'VALUE'           = 'x'
}

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
        if ($text -notmatch ("\b" + $key + "=" + [regex]::Escape($expect[$key]) + "\b")) {
            $bad += "$key=$($expect[$key])"
        }
    }

    if ($bad) {
        "  не совпало: " + ($bad -join ', ')
        exit 1
    }

    exit 0
} else {
    "  probe did not run"
    exit 1
}