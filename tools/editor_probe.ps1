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

<#
.SYNOPSIS
Готовит работу с заданием, если готовой нет.

.DESCRIPTION
База тестовая, её пересоздают, и работы с заданиями в ней не остаётся. Без
подготовки проба молча ничего не проверяла, а её код возврата говорил, что
всё в порядке: работа найдена, заданий в ней нет, проверять нечего.

Работа и задание заводятся через сам сервер — так же, как это делает человек.
Проба не должна обходить приложение своими запросами к базе: тогда она
проверяла бы одно, а работать было бы с другим.
#>
function New-ProbeWork {
    param(
        [Parameter(Mandatory = $true)]$Session,
        [Parameter(Mandatory = $true)][string]$Base
    )

    $token = [regex]::Match(
        (Invoke-WebRequest -Uri "$Base/panel/assignments/new" -WebSession $Session -UseBasicParsing).Content,
        'name="_token" value="([^"]+)"'
    ).Groups[1].Value

    if (-not $token) {
        return ''
    }

    $created = Invoke-WebRequest -Uri "$Base/panel/assignments/new/start" -Method Post -WebSession $Session -UseBasicParsing `
        -Body @{ '_token' = $token; 'title' = 'Работа для пробы редактора' }

    $workId = [regex]::Match($created.BaseResponse.ResponseUri.AbsolutePath, '/panel/assignments/(\d+)/').Groups[1].Value

    if (-not $workId) {
        return ''
    }

    # Токен на странице работы другой: он выдаётся на каждую страницу заново.
    $workToken = [regex]::Match(
        (Invoke-WebRequest -Uri "$Base/panel/assignments/$workId/edit" -WebSession $Session -UseBasicParsing).Content,
        'name="_token" value="([^"]+)"'
    ).Groups[1].Value

    if (-not $workToken) {
        return ''
    }

    # Задание с одиночным выбором: у него есть строки вариантов, а именно их
    # проба и проверяет — добавляет строку, отмечает правильную и удаляет.
    $null = Invoke-WebRequest -Uri "$Base/panel/assignments/$workId/tasks/new" -Method Post -WebSession $Session -UseBasicParsing `
        -Body @{
            '_token'    = $workToken
            'plugin_id' = 'test'
            'task_kind' = 'single_choice'
            'title'     = 'Вопрос для пробы'
        }

    $itemsHtml = (Invoke-WebRequest -Uri "$Base/panel/assignments/$workId/edit?tab=items" -WebSession $Session -UseBasicParsing).Content
    $taskId = [regex]::Match($itemsHtml, 'data-task-id="(\d+)"').Groups[1].Value

    if (-not $taskId) {
        return ''
    }

    return $workId
}

if (-not $Path) {
    $workPath = ''

    # Черновик и опубликованная работа лежат под разными фильтрами списка, и у
    # готовой работы заданий может не быть вовсе — берём первую, где они есть.
    foreach ($state in @('drafts', 'published')) {
        $listHtml = (Invoke-WebRequest -Uri "$base/panel/assignments?state=$state" -WebSession $session -UseBasicParsing).Content
        $candidates = [regex]::Matches($listHtml, '/panel/assignments/(\d+)/edit')

        foreach ($found in $candidates) {
            $candidate = $found.Groups[1].Value
            $itemsHtml = (Invoke-WebRequest -Uri "$base/panel/assignments/$candidate/edit?tab=items" -WebSession $session -UseBasicParsing).Content
            $taskId = [regex]::Match($itemsHtml, 'data-task-id="(\d+)"').Groups[1].Value

            if ($taskId) {
                $workPath = $candidate
                break
            }
        }

        if ($workPath) {
            break
        }
    }

    if (-not $workPath) {
        # Готовой работы с заданием может не быть вовсе: база тестовая, её
        # пересоздают, и работы с заданиями в ней не остаётся. Тогда работа
        # готовится на месте — иначе проба молча ничего не проверяет, а её
        # код возврата говорит, что всё в порядке.
        $workPath = New-ProbeWork -Session $session -Base $base
    }

    if (-not $workPath) {
        "  не нашлась работа с заданием: добавьте одиночный или множественный выбор"
        exit 1
    }

    # Адрес вкладки, а не `?edit=`: редактор открывается по вкладкам, и
    # номер задания передаётся в саму форму, а не в адрес страницы.
    $Path = "/panel/assignments/$workPath/edit?tab=items"
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

  /*
   * Сессия берётся здесь же, из скрипта.
   *
   * Копия страницы лежит в public/ и открывается браузером без сессии: временный
   * вход ставит её своему профилю, а второй запуск Edge в тот же профиль сессию
   * не сохраняет. Из-за этого запрос формы редактора отвечал 401, и проба
   * решила бы, что форма не появилась.
   *
   * Вход идёт запросом: ответ переадресует на страницу работ, и кука с
   * сессией остаётся в браузере — после этого запросы идут от своего имени.
   */
  window.fetch('/_shot_tmp.php?path=/panel/assignments', { credentials: 'same-origin' })
    .then(function () {
      waitForForm(0);
    })
    .catch(function (error) {
      say('LOGIN_FAILED=' + error);
    });

  /*
   * Форма редактора приходит скриптом: карточка на доске ссылается на адрес
   * загрузки формы, и форма появляется после запроса. Раньше она была в
   * разметке страницы, и проба находила её сразу; теперь нужно щёлкнуть по
   * карандашу и дождаться формы.
   */
  function waitForForm(attempt) {
    var form = document.querySelector('form[data-fixed-type]');

    if (form) {
      run(form);
      return;
    }

    if (attempt === 3) {
      // Карандаш на доске открывает редактор задания: без него формы нет.
      var opener = document.querySelector('[data-editor-open]');

      if (opener) {
        opener.click();
      }
    }

    if (attempt > 60) {
      say('FORM_FOUND=no');
      return;
    }

    window.setTimeout(function () {
      waitForForm(attempt + 1);
    }, 250);
  }

  function run(form) {
    say('FORM_FOUND=yes');

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
  }
});
'@

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-editor-" + [guid]::NewGuid().ToString("N").Substring(0, 8))

# Браузер сначала заходит через временный вход: тот ставит сессию в его
# профиле. Без этого копия страницы открылась бы гостем, и запрос формы
# редактора отвечал бы 401 — а проба решила бы, что форма не появилась.
$null = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" `
    --virtual-time-budget=4000 --dump-dom "$base/_shot_tmp.php?path=/panel/assignments" 2>$null | Out-String

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