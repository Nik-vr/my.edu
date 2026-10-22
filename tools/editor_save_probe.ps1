# Проверяет в браузере две вещи в редакторе задания:
#
#   1. Текст задания не теряется, если переключиться на вкладку «Настройки»,
#      изменить там опцию и сохранить. Симптом был такой: сервер отвечал
#      «Введите текст задания», хотя текст был набран и стоял на экране.
#
#   2. Поле редактора не растёт в ширину от длинной строки, а с ним и окно.
#      Симптом: без пробелов и переносов строка растягивала поле, а вместе с
#      ним и окно редактора.
#
# Зачем это отдельный инструмент: обе поломки живут в порядке событий и в
# размерах элементов — по разметке и по коду их не видно. Воспроизводятся
# только настоящими событиями в настоящем браузере.
#
# Страница копируется в public/, проба — внешним файлом: политика безопасности
# запрещает код прямо в разметке (script-src 'self').
#
# Запуск:
#   powershell -File tools\editor_save_probe.ps1
param(
    [string]$Out = "var/editor-save-probe.txt",
    [string]$Window = "800,600"
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
$null = Invoke-WebRequest -Uri "$base/_shot_tmp.php" -WebSession $session -UseBasicParsing

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
        -Body @{ '_token' = $token; 'title' = 'Работа для пробы сохранения' }

    $workId = [regex]::Match($created.BaseResponse.ResponseUri.AbsolutePath, '/panel/assignments/(\d+)/').Groups[1].Value

    if (-not $workId) {
        return ''
    }

    $workToken = [regex]::Match(
        (Invoke-WebRequest -Uri "$Base/panel/assignments/$workId/edit" -WebSession $Session -UseBasicParsing).Content,
        'name="_token" value="([^"]+)"'
    ).Groups[1].Value

    if (-not $workToken) {
        return ''
    }

    $null = Invoke-WebRequest -Uri "$Base/panel/assignments/$workId/tasks/new" -Method Post -WebSession $Session -UseBasicParsing `
        -Body @{
            '_token'    = $workToken
            'plugin_id' = 'test'
            'task_kind' = 'single_choice'
            'title'     = 'Вопрос для пробы сохранения'
        }

    return $workId
}

$workId = New-ProbeWork -Session $session -Base $base

if (-not $workId) {
    "  не удалось подготовить работу с заданием"
    exit 1
}

$Path = "/panel/assignments/$workId/edit?tab=items"

$html = (Invoke-WebRequest -Uri "$base$Path" -WebSession $session -UseBasicParsing).Content

# Номер задания: открыть надо именно то, которое проба завела, а не первое
# попавшееся на доске.
$taskId = [regex]::Match($html, 'data-task-id="(\d+)"').Groups[1].Value

if (-not $taskId) {
    "  не нашлось задание на доске"
    exit 1
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

  window.fetch('/_shot_tmp.php?path=/panel/assignments', { credentials: 'same-origin' })
    .then(function () {
      waitForForm(0);
    })
    .catch(function (error) {
      say('LOGIN_FAILED=' + error);
    });

  function waitForForm(attempt) {
    var form = document.querySelector('form[data-fixed-type]');

    if (form) {
      run(form);
      return;
    }

    if (attempt === 3) {
      /* Открываем нужное задание: у каждой карточки свой карандаш. */
      var openers = document.querySelectorAll('[data-editor-open]');
      var opener = null;

      for (var i = 0; i < openers.length; i++) {
        if (openers[i].getAttribute('data-task-id') === '__TASK_ID__') {
          opener = openers[i];
          break;
        }
      }

      if (!opener && openers.length) {
        opener = openers[0];
      }

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

    var host = form.querySelector('.ql-editor-host');
    var hidden = form.querySelector('input[type="hidden"][name="prompt"]');

    say('QUILL=' + (typeof window.Quill !== 'undefined' ? 'yes' : 'no'));
    say('HIDDEN_FOUND=' + (hidden ? 'yes' : 'no'));

    if (!host || !hidden) {
      return;
    }

    /* Ответ сервера перехватываем: он и есть разбор — что именно он сказал. */
    var answers = [];
    var nativeFetch = window.fetch;

    window.fetch = function (input, init) {
      var call = nativeFetch.apply(window, arguments);

      call.then(function (response) {
        var copy = response.clone();

        copy.text().then(function (text) {
          answers.push(text.slice(0, 300));
        });
      }).catch(function () {});

      return call;
    };

    var quill = window.Quill.find ? window.Quill.find(host) : null;

    say('INSTANCE=' + (quill ? 'yes' : 'no'));

    if (!quill) {
      return;
    }

    /*
     * Варианты ответа: без них клиентская проверка не даст отправить форму,
     * и проба measures nothing — до сервера дело не доходит.
     */
    var rows = form.querySelectorAll('.option-row input[type="text"]');
    var filled = 0;

    for (var r = 0; r < rows.length && filled < 2; r++) {
      if (rows[r].value === '') {
        rows[r].value = 'Вариант ' + (filled + 1);
        filled++;
      }
    }

    var radio = form.querySelector('.option-row input[type="radio"]');

    if (radio) {
      radio.checked = true;
    }

    say('OPTIONS_FILLED=' + filled);

    var modal = document.querySelector('[data-editor-shell]') || document.querySelector('[data-modal]');
    var dialog = modal ? modal.querySelector('.modal-dialog') : null;
    var body = dialog ? dialog.querySelector('.modal-body') : null;
    var editorBox = document.querySelector('[data-editor-for]');

    say('DRAFT_URL=' + (editorBox ? editorBox.getAttribute('data-draft-url') : 'нет'));
    say('EDITOR_FOR=' + (editorBox ? editorBox.getAttribute('data-editor-for') : 'нет'));

    function width(node) {
      return node ? Math.round(node.getBoundingClientRect().width) : 0;
    }

    /* Набираем текст задания. */
    quill.setText('Вопрос для проверки сохранения');
    quill.root.focus();

    say('MODAL_W_BEFORE=' + width(dialog));
    say('EDITOR_W_BEFORE=' + width(host));

    /* Длинная строка без пробелов: из-за неё поле и окно растут. */
    var long = new Array(3200).join('Я');

    quill.setText(long);
    quill.root.focus();

    say('MODAL_W_AFTER=' + width(dialog));
    say('BODY_W_AFTER=' + width(body));
    say('EDITOR_W_AFTER=' + width(host));
    say('HOST_SCROLL=' + (host ? host.scrollWidth : 0));
    say('HOST_CLIENT=' + (host ? host.clientWidth : 0));
    say('DOC_SCROLL=' + document.documentElement.scrollWidth);
    say('WINDOW_W=' + window.innerWidth);

    /* Самый широкий элемент: кто именно распирает окно. */
    var widest = null;
    var widestWidth = 0;

    document.querySelectorAll('*').forEach(function (node) {
      var w = node.scrollWidth;

      if (w > widestWidth) {
        widestWidth = w;
        widest = node;
      }
    });

    say('WIDEST=' + (widest ? widest.tagName + '.' + (widest.className || '') : 'нет')
        + ':' + widestWidth);

    /* Возвращаем обычный текст и идём дальше. */
    quill.setText('Вопрос для проверки сохранения');
    quill.root.focus();

    say('HIDDEN_AFTER_TYPE=' + hidden.value.length);

    /* Переключаемся на вкладку «Настройки» и меняем опцию. */
    var tab = document.querySelector('[data-task-pane-tab="settings"]');

    if (tab) {
      tab.click();
    }

    var required = form.querySelector('input[name="is_required"]');

    if (required) {
      required.checked = !required.checked;
      required.dispatchEvent(new Event('change', { bubbles: true }));
    }

    say('OPTION_CHANGED=' + (required ? 'yes' : 'no'));
    say('HIDDEN_AT_SAVE=' + hidden.value.length);

    /* Отметка «обязательно показывать»: что уедет в запрос. */
    var always = form.querySelector('input[name="option_always_show[]"]');

    if (always) {
      always.checked = true;
      always.dispatchEvent(new Event('change', { bubbles: true }));

      var data = new FormData(form);

      say('ALWAYS_SENT=' + data.getAll('option_always_show[]').length);
    } else {
      say('ALWAYS_SENT=нет');
    }

    /* Сохраняем. */
    var save = form.querySelector('button[type="submit"]');

    if (!save) {
      say('SAVE_BUTTON=no');
      return;
    }

    save.click();

    window.setTimeout(function () {
      var status = document.querySelector('[data-ajax-status]');
      var toast = document.querySelector('.toast-host');

      say('STATUS=' + (status ? status.textContent.trim() : ''));
      say('TOAST=' + (toast ? toast.textContent.trim() : ''));
      say('ANSWER_1=' + (answers[answers.length - 1] || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim());
      say('HIDDEN_AFTER_SAVE=' + hidden.value.length);

      /*
       * Второй шаг: правим текст на один символ и сохраняем снова. Так
       * выглядит обход автора: после отказа он трогает текст — и сохранение
       * проходит. Проверяем, что это действительно так, и смотрим, что уедет
       * в запрос на этот раз.
       */
      quill.insertText(quill.getLength() - 1, '!');
      quill.root.focus();

      var data2 = new FormData(form);

      say('PROMPT_IN_REQUEST=' + String(data2.get('prompt') || '').length);
      say('HIDDEN_IN_REQUEST=' + String(data2.get('prompt') || '').length);

      save.click();

      window.setTimeout(function () {
        var status2 = document.querySelector('[data-ajax-status]');

        say('STATUS_2=' + (status2 ? status2.textContent.trim() : ''));
        say('ANSWER_2=' + (answers[answers.length - 1] || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim());

        /*
         * Открываем задание заново: так автор и проверяет, сохранилась ли
         * отметка. Смотрим на флажок в форме — он должен стоять.
         */
        var opener = document.querySelector('[data-editor-open][data-task-id="__TASK_ID__"]');

        if (opener) {
          opener.click();
        }

        window.setTimeout(function () {
          var form2 = document.querySelector('form[data-fixed-type]');
          var box = form2 ? form2.querySelector('input[name="option_always_show[]"]') : null;

          say('REOPENED=' + (form2 ? 'yes' : 'no'));
          say('ALWAYS_AFTER_SAVE=' + (box && box.checked ? 'yes' : 'no'));
        }, 2500);
      }, 2500);
    }, 3000);
  }
});
'@

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
$html = $html.Replace('__TASK_ID__', $taskId)
$probeJs = $probeJs.Replace('__TASK_ID__', $taskId)
[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

# Признаки читаются по английским меткам: инструмент запускается Windows
# PowerShell, который без метки порядка байтов читает файл как ANSI.
$expect = @{
    'FORM_FOUND'   = 'yes'
    'QUILL'        = 'yes'
    'INSTANCE'     = 'yes'
    'OPTIONS_FILLED' = '2'
    'ALWAYS_SENT'  = '1'
    'TOAST'        = 'Задание сохранено'
    'STATUS'       = ''
    'ALWAYS_AFTER_SAVE' = 'yes'
    'REOPENED'     = 'yes'
}

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-save-" + [guid]::NewGuid().ToString("N").Substring(0, 8))

$null = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" `
    --window-size="$Window" --virtual-time-budget=4000 --dump-dom "$base/_shot_tmp.php?path=/panel/assignments" 2>$null | Out-String

$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --window-size="$Window" `
    --virtual-time-budget=20000 --dump-dom "$base/_probe_page.html" 2>$null | Out-String

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

    # Маркеры разбираются в словарь: «KEY=VALUE» через разделитель.
    $marks = @{}

    foreach ($part in ($text -split '\s\|\s')) {
        if ($part -match '^([A-Z_0-9]+)=(.*)$') {
            $marks[$Matches[1]] = $Matches[2]
        }
    }

    $bad = @()

    foreach ($key in $expect.Keys) {
        if (-not $marks.ContainsKey($key)) {
            $bad += "$key нет"
            continue
        }

        if ($marks[$key] -ne $expect[$key]) {
            $bad += "$key=$($expect[$key]) (получено: $($marks[$key]))"
        }
    }

    # Длинная строка не должна распирать ни поле, ни окно.
    foreach ($pair in @(@('MODAL_W_BEFORE', 'MODAL_W_AFTER', 'окно'), @('EDITOR_W_BEFORE', 'EDITOR_W_AFTER', 'поле'))) {
        if (-not $marks.ContainsKey($pair[0]) -or -not $marks.ContainsKey($pair[1])) {
            $bad += "ширина $($pair[2]) не измерена"
            continue
        }

        if ($marks[$pair[0]] -ne $marks[$pair[1]]) {
            $bad += "$($pair[2]) расширилось: $($marks[$pair[0]]) → $($marks[$pair[1]])"
        }
    }

    # Адрес черновика — с номером позиции, а не с нулём.
    if ($marks.ContainsKey('DRAFT_URL') -and $marks['DRAFT_URL'] -match '/items/(\d+)/draft') {
        if ($Matches[1] -eq '0') {
            $bad += 'адрес черновика без номера позиции'
        }
    } else {
        $bad += 'адрес черновика не измерен'
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
