# Проверяет в браузере редактор задания: добавляются ли строки вариантов ответа,
# можно ли у новой строки отметить правильный вариант и удаляются ли строки.
#
# Зачем это отдельный инструмент: поломка «кнопка нажимается и ничего не делает»
# по разметке и по коду не видна. Она возникает, когда скрипт вышел раньше, чем
# дошёл до своих строк, — страница при этом выглядит исправной, все поля на
# месте, а кнопка просто молчит. Воспроизводится только настоящими событиями в
# настоящем браузере.
#
# Страница копируется в public/, проба — внешним файлом: политика безопасности
# запрещает код прямо в разметке (script-src 'self').
#
# Запуск:
#   powershell -File tools\editor_probe.ps1
#   powershell -File tools\editor_probe.ps1 -Path "/panel/assignments/1/edit/items?edit=2"
param(
    [string]$Path = "",
    [string]$Out = "var/editor-probe.txt"
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

# Адрес редактора задания берётся из самой страницы списка: номер задания у
# каждой работы свой, и жёстко зашитый адрес рано или поздно перестанет
# существовать.
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri "$base/_shot_tmp.php" -WebSession $session -UseBasicParsing

if (-not $Path) {
    $workPath = ''

    # Черновик и опубликованная работа лежат под разными фильтрами списка, и у
    # готовой работы заданий может не быть вовсе — берём первую, где они есть.
    foreach ($state in @('drafts', 'published')) {
        $listHtml = (Invoke-WebRequest -Uri "$base/panel/assignments?state=$state" -WebSession $session -UseBasicParsing).Content
        $candidate = [regex]::Match($listHtml, '/panel/assignments/(\d+)/edit').Groups[1].Value

        if (-not $candidate) {
            continue
        }

        $itemsHtml = (Invoke-WebRequest -Uri "$base/panel/assignments/$candidate/edit/items" -WebSession $session -UseBasicParsing).Content
        $taskId = [regex]::Match($itemsHtml, 'items\?edit=(\d+)').Groups[1].Value

        if ($taskId) {
            $workPath = $candidate
            break
        }
    }

    if (-not $workPath) {
        "  не нашлась работа с заданием: добавьте одиночный или множественный выбор"
        exit 1
    }

    $itemsHtml = (Invoke-WebRequest -Uri "$base/panel/assignments/$workPath/edit/items" -WebSession $session -UseBasicParsing).Content
    $taskId = [regex]::Match($itemsHtml, 'items\?edit=(\d+)').Groups[1].Value

    $Path = "/panel/assignments/$workPath/edit/items?edit=$taskId"
}

$html = (Invoke-WebRequest -Uri "$base$Path" -WebSession $session -UseBasicParsing).Content

# Признаки читаются по английским меткам: инструмент запускается Windows
# PowerShell, который без метки порядка байтов читает файл как ANSI.
#
# Число строк не жёсткое: у вопроса их столько, сколько вариантов придумал
# автор. Проверяется изменение — две строки добавились, одна убралась.
$expect = @{
    'FORM_FOUND'    = 'yes'
    'QUILL'         = 'yes'
    'RADIO_NAME'    = 'correct_*'
    'ROWS_DELTA'    = '2'
    'DROP_DELTA'    = '-1'
    'RADIO_IN_FORM' = 'yes'
    'CHECKED_NEW'   = 'yes'
    'VALUE_KEPT'    = 'yes'
}

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

  var form = document.querySelector('form[data-fixed-type]');
  say('FORM_FOUND=' + (form ? 'yes' : 'no'));

  if (!form) {
    return;
  }

  say('QUILL=' + (typeof window.Quill !== 'undefined' ? 'yes' : 'no'));

  var rowsBox = form.querySelector('[data-option-rows]');
  var addButton = form.querySelector('[data-option-add]');

  if (!rowsBox || !addButton) {
    say('ROWS_BEFORE=нет');
    return;
  }

  var count = function () {
    return rowsBox.querySelectorAll('.option-row').length;
  };

  var mark = rowsBox.querySelector('.option-row input[type="radio"], .option-row input[type="checkbox"]');
  say('ROWS_BEFORE=' + count());
  say('RADIO_NAME=' + (mark ? mark.name : 'нет'));

  addButton.click();
  addButton.click();

  var afterAdd = count();
  say('ROWS_DELTA=' + (afterAdd - count() + 2));

  var rows = rowsBox.querySelectorAll('.option-row');
  var last = rows[rows.length - 1];
  var fresh = last.querySelector('input[type="radio"], input[type="checkbox"]');
  var field = last.querySelector('input[type="text"]');

  if (fresh) {
    fresh.checked = true;

    var data = new FormData(form);
    var sent = data.getAll(fresh.name);

    say('RADIO_IN_FORM=' + (sent.length ? 'yes' : 'no'));
    say('SENT_COUNT=' + sent.length);

    /*
     * Отметка новой строки должна доезжать в запрос: у одиночного выбора в форме
     * лежит один номер, у множественного — несколько, и добавленный к ним.
     * Проверяется присутствие номера новой строки, а не их количество.
     */
    say('CHECKED_NEW=' + (sent.indexOf(String(rows.length - 1)) >= 0 ? 'yes' : 'no'));
  }

  // Введённое в новую строку должно уехать с формой: иначе вариант пришлось бы
  // вводить заново после каждой правки.
  if (field) {
    field.value = 'Пробный вариант';
    var values = new FormData(form).getAll(field.name);
    say('VALUE_KEPT=' + (values.indexOf('Пробный вариант') >= 0 ? 'yes' : 'no'));
  }

  var drops = rowsBox.querySelectorAll('[data-option-drop]');
  drops[drops.length - 1].click();
  say('DROP_DELTA=' + (count() - afterAdd));
});
'@

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-editor-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --virtual-time-budget=12000 `
    --dump-dom "$base/_probe_page.html" 2>$null | Out-String

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
        # Звёздочка в ожидании — «любой хвост»: вид отметки у вопроса бывает и
        # одиночным, и множественным, и проверять надо само правило, а не одно
        # из двух его написаний.
        $pattern = '\b' + $key + '=' + [regex]::Escape($expect[$key]).Replace('\*', '.*') + '\b'

        if ($text -notmatch $pattern) {
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