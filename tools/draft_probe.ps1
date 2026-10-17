# Проверка черновика правки задания: пишет в форму, ждёт запись в браузере,
# портит форму так, как это сделала бы перезагрузка, и смотрит, что вернулось.
#
# Зачем отдельный инструмент, если есть набор проверок. Поведение черновика —
# целиком браузерное: отложенная запись, хранение по именам полей, отметки
# галочек, списки вариантов по позициям и живой текст визуального редактора.
# Проверкой по тексту файла это не поймать: строка может называться верно и при
# этом ничего не делать. Здесь форма настоящая, набор тот же, что и на странице.
#
# Страница кладётся в public/, а не в документы приложения: проверяется не
# приложение, а скрипты, и подмена ответа сервера мешала бы — скрипт отдаёт
# public/ без заголовков, inline-скрипты не запрещены (script-src 'self').
#
# Запуск:
#   powershell -File tools\draft_probe.ps1
#   powershell -File tools\draft_probe.ps1 -Port 8098
param(
    [int]$Port = 8098,
    [string]$Out = "var/draft-probe.txt"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:$Port"
$page = Join-Path (Get-Location) "public\_draft_probe.html"
$script = Join-Path (Get-Location) "public\_draft_probe.js"

# Страница повторяет форму редактора задания: те же метки, тот же визуальный
# редактор, те же имена полей. Отличия от боевой формы — в другом наборе полей:
# здесь важно проверить все виды значений сразу, а не повторить задание целиком.
$html = @'
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Проба черновика</title></head>
<body>
<div class="task-editor" data-editor-for="888" data-editor-body>
  <form data-ajax-save action="/tests/task/2" method="post" data-fixed-type="text">
    <input type="hidden" name="_token" value="одноразовый токен">
    <input type="hidden" name="action" value="save_task">
    <input type="text" name="hint" value="обычное поле">
    <textarea name="description">описание с сервера</textarea>
    <label><input type="checkbox" name="required" value="1"> обязательно</label>
    <select name="mode"><option value="exact" selected>точное</option><option value="range">диапазон</option></select>
    <input type="text" name="option_text[]" value="первый с сервера">
    <input type="text" name="option_text[]" value="второй с сервера">
    <div class="ql-host">
      <div class="ql-editor-host" data-upload-url="" data-placeholder=""></div>
      <input type="hidden" name="question_text" id="question_text-field" value="вопрос с сервера">
    </div>
    <button type="submit">Сохранить</button>
  </form>
</div>
<script src="/assets/vendor/quill/quill.js"></script>
<script src="/assets/js/editor.js"></script>
<script src="/assets/js/task-draft.js"></script>
<script src="/_draft_probe.js"></script>
</body>
</html>
'@

$expect = [ordered]@{
    QUILL_READY    = 'yes'
    DRAFT_WRITTEN  = 'yes'
    NO_TOKEN       = 'yes'
    PROMPT_LIVE    = 'yes'
    CHECKBOX_FLAG  = 'yes'
    LIST_BY_INDEX  = 'yes'
    RESTORED       = 'yes'
    PROMPT_BACK    = 'yes'
    RESTORE_SAID   = 'yes'
    CANCEL_DROPS   = 'yes'
    EMPTY_AFTER    = 'yes'
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

  var form = document.querySelector('form[data-ajax-save]');
  var KEY = 'myedu-task-draft:888';

  var quill = form.myEduPromptEditor;

  say('QUILL_READY=' + (quill ? 'yes' : 'no'));

  if (!quill) {
    return;
  }

  window.MyEduTaskDraft.init(document);

  var prompt = form.querySelector('input[name="hint"]');
  var description = form.querySelector('textarea[name="description"]');
  var required = form.querySelector('input[name="required"]');
  var mode = form.querySelector('select[name="mode"]');
  var options = form.querySelectorAll('input[name="option_text[]"]');

  /* Правка так, как её делает человек. */
  quill.setText('вопрос, который ещё не сохранён');
  prompt.value = 'обычное поле изменено';
  prompt.dispatchEvent(new Event('input', { bubbles: true }));
  description.value = 'описание из черновика';
  description.dispatchEvent(new Event('input', { bubbles: true }));
  required.checked = true;
  required.dispatchEvent(new Event('change', { bubbles: true }));
  mode.value = 'range';
  mode.dispatchEvent(new Event('change', { bubbles: true }));
  options[0].value = 'первый изменённый';
  options[1].value = 'второй изменённый';
  options[1].dispatchEvent(new Event('input', { bubbles: true }));

  /*
   * Ждём отложенную запись: модуль пишет через полсекунды после последнего
   * изменения, и проверка сразу после ввода читала бы пустое хранилище.
   */
  window.setTimeout(function () {
    var raw = window.sessionStorage.getItem(KEY);
    var fields = raw ? JSON.parse(raw).fields : null;

    say('DRAFT_WRITTEN=' + (raw ? 'yes' : 'no'));

    if (!fields) {
      return;
    }

    /* Одноразовые поля возвращать нельзя: они относятся к сохранению. */
    say('NO_TOKEN=' + (fields._token === undefined && fields.action === undefined ? 'yes' : 'no'));

    /*
     * Вопрос берётся из визуального редактора, а не из скрытого поля: в поле
     * лежит сохранённое, и черновик сохранил бы прежний текст вместо набранного.
     */
    var taken = fields.question_text || '';

    say('PROMPT_LIVE=' + (taken.indexOf('ещё не сохранён') !== -1 && taken !== 'вопрос с сервера' ? 'yes' : 'no'));
    say('CHECKBOX_FLAG=' + (fields.required === true ? 'yes' : 'no'));
    say('LIST_BY_INDEX=' + (
      Array.isArray(fields['option_text[]'])
      && fields['option_text[]'][0] === 'первый изменённый'
      && fields['option_text[]'][1] === 'второй изменённый' ? 'yes' : 'no'
    ));

    /* Портим форму так, как это сделала бы перезагрузка страницы. */
    quill.setText('вопрос с сервера');
    prompt.value = 'обычное поле';
    description.value = 'описание с сервера';
    required.checked = false;
    mode.value = 'exact';
    options[0].value = 'первый с сервера';
    options[1].value = 'второй с сервера';

    var said = window.MyEduTaskDraft.reopen(document);
    var back = window.MyEduEditor.promptHtml(form) || '';

    say('RESTORED=' + (prompt.value === 'обычное поле изменено'
      && description.value === 'описание из черновика'
      && required.checked === true
      && mode.value === 'range'
      && options[0].value === 'первый изменённый'
      && options[1].value === 'второй изменённый' ? 'yes' : 'no'));
    say('PROMPT_BACK=' + (back.indexOf('ещё не сохранён') !== -1 ? 'yes' : 'no'));
    say('RESTORE_SAID=' + (said === true ? 'yes' : 'no'));

    /* Отмена значит отмену: черновик удаляется. */
    window.MyEduTaskDraft.drop(form);

    say('CANCEL_DROPS=' + (window.sessionStorage.getItem(KEY) === null ? 'yes' : 'no'));
    say('EMPTY_AFTER=' + (window.MyEduTaskDraft.exists(form) === false ? 'yes' : 'no'));
  }, 900);
});
'@

[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-draft-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --virtual-time-budget=12000 `
    --dump-dom "$base/_draft_probe.html" 2>$null | Out-String

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