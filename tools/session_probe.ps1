# Проверяет в браузере, как ведут себя действия при истёкшей сессии.
#
# Что это за сценарий. Сессия заканчивается сама: вкладка открыта давно, её
# закрыли на сервере, компьютер уснул. Человек жмёт «удалить» или открывает
# задание — и получает страницу входа вместо ответа. Раньше скрипт не
# разбирал код ответа, и человек видел «Связь с сервером прервалась»: виноват
# оказывался браузер, а на самом деле кончилась сессия.
#
# Теперь отказ называют словами. Проба смотрит ровно на это: что человек видит
# после нажатия, когда сервер отвечает 401.
#
# Зачем это отдельный инструмент, а не проверка в наборе. Поведение целиком
# браузерное: показать сообщение, не отправить форму, не уйти со страницы.
# По разметке этого не видно — видно только в настоящем браузере, где нажатие
# настоящее.
#
# Сервер отдаёт 401 подменой: страница с формой и скрипт, который отвечает
# кодом 401 вместо обычного ответа. Так сценарий воспроизводится на любой
# странице и не зависит от того, что в базе.
#
# ЗАПУСКАТЬ ТОЛЬКО НА ТЕСТОВОЙ БАЗЕ: страница кладётся в public/, и её скрипт
# отвечает вместо приложения. На боевом сервере это подмена ответа сервера.
#
# Запуск:
#   powershell -File tools\session_probe.ps1
#   powershell -File tools\session_probe.ps1 -Port 8098
param(
    [int]$Port = 8098,
    [string]$Out = "var/session-probe.txt"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:$Port"
$page = Join-Path (Get-Location) "public\_session_probe.html"
$script = Join-Path (Get-Location) "public\_session_probe.js"

$testDb = [string]$env:MYEDU_DB_NAME

if ($testDb -notmatch 'test') {
    Write-Host "  Проба не запускается: база «$testDb» не похожа на тестовую." -ForegroundColor Red
    Write-Host '  Подмена ответа сервера допустима только на тестовой базе.' -ForegroundColor Red
    Write-Host '  Запустите тестовый сервер: tools\dev-server.ps1 -ForTests' -ForegroundColor Red
    exit 1
}

# Ожидания перечислены здесь же и читаются в порядке выполнения:
#   DELETE_SEEN=yes   — отказ показан, а не «связь с сервером прервалась»
#   DELETE_TEXT=yes  — в сообщении сказано про сессию
#   NOT_SENT=yes     — работа не удалена: запрос ушёл, но сервер его отверг
#   OPEN_SEEN=yes    — открытие задания тоже называет причину
#   OPEN_TEXT=yes   — и тоже говорит про сессию
#   NO_RELOAD=yes    — страница не перезагрузилась сама
$expect = [ordered]@{
    DELETE_SEEN  = 'yes'
    DELETE_TEXT  = 'yes'
    NOT_SENT     = 'yes'
    OPEN_SEEN    = 'yes'
    OPEN_TEXT    = 'yes'
    NO_RELOAD   = 'yes'
}

# Страница повторяет кнопку удаления работы и ссылку открытия задания.
# Настоящие обработчики не вызваны: скрипт ниже подменяет fetch и смотрит, что
# делает с ответом страница.
$html = @'
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Проба отказа по сессии</title></head>
<body>
<div id="log"></div>
<div data-work-list>
  <div data-work-row>
    <a class="btn btn-sm btn-danger" data-work-delete href="/panel/assignments/1/delete">Удалить</a>
  </div>
</div>
<a data-editor-open data-task-id="1" href="/panel/assignments/1/edit?edit=1">Карандаш</a>
<div data-editor-shell data-editor-url="/panel/assignments/1/edit/items/editor">
  <div data-editor-title>Задание</div>
  <div data-editor-body></div>
</div>
<script>
/*
 * Окно подтверждения нужно скрипту удаления работы до его загрузки: без него
 * модуль не доходит до запроса, и проба проверяла бы не отказ, а молчание.
 * Подтверждение согласием считается: отказ по сессии — это то, что происходит
 * уже после того, как человек подтвердил.
 */
window.MyEduConfirm = { open: function () { return Promise.resolve(true); } };
</script>
<script src="/assets/js/toast.js"></script>
<script src="/assets/js/work-list.js"></script>
<script src="/assets/js/assignment-items.js"></script>
<script src="/_session_probe.js"></script>
</body>
</html>
'@

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

  var status = 401;
  var sent = 0;
  var reloaded = false;
  var realFetch = window.fetch;

  window.addEventListener('beforeunload', function () {
    reloaded = true;
  });

  /*
   * Ответ сервера подменён: 401 вместо обычного ответа. Тело — как у настоящего
   * отказа ядра: причина названа и приходит под обоими именами.
   */
  window.fetch = function (url, options) {
    sent++;

    return Promise.resolve({
      ok: false,
      status: status,
      redirected: false,
      json: function () {
        return Promise.resolve({
          ok: false,
          error: 'Нужно войти в систему',
          message: 'Нужно войти в систему',
          status: status
        });
      }
    });
  };

  var link = document.querySelector('[data-work-delete]');
  link.click();

  window.setTimeout(function () {
    var note = document.querySelector('[data-work-delete-error]');
    var text = note ? note.textContent : '';

    say('DELETE_SEEN=' + (text !== '' ? 'yes' : 'no'));
    say('DELETE_TEXT=' + (/сесси/i.test(text) ? 'yes' : 'no'));
    say('NOT_SENT=' + (sent === 1 ? 'yes' : 'no'));

    /* Теперь открытие задания — второй запрос, отказ тот же. */
    var opener = document.querySelector('[data-editor-open]');
    opener.click();

    window.setTimeout(function () {
      var toast = document.querySelector('.toast-host');
      var toastText = toast ? toast.textContent : '';

      say('OPEN_SEEN=' + (toastText !== '' ? 'yes' : 'no'));
      say('OPEN_TEXT=' + (/сесси/i.test(toastText) ? 'yes' : 'no'));
      say('NO_RELOAD=' + (reloaded === false ? 'yes' : 'no'));
    }, 600);
  }, 600);
});
'@

[System.IO.File]::WriteAllText($page, $html)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$profile = Join-Path $env:TEMP ("myedu-session-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$dom = & $edge --headless=new --disable-gpu --no-sandbox --user-data-dir="$profile" --virtual-time-budget=12000 `
    --dump-dom "$base/_session_probe.html" 2>$null | Out-String

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
