# Проверяет в браузере, что опасные действия ученика спрашивают подтверждение
# окном сайта, и возвращает код возврата, пригодный для запуска как проверки.
#
# Зачем это отдельный инструмент, а не проверка в наборе тестов: по разметке
# поведение не определить. Кнопка может нести data-confirm и при этом ничего не
# спрашивать — так было с «Убрать из класса»: скрипт окна на странице не доехал,
# форма ушла сразу, и подтверждения не было ни на одном шаге. Здесь видно
# настоящий браузер: нажата кнопка, окно открылось, поля ушли в форму окна, и
# «Отмена» ничего не отправила.
#
# Работает так же, как tools\validate_probe.ps1: страница копируется в public/, а
# проба — внешним файлом, потому что политика безопасности запрещает код прямо в
# разметке.
#
# ЗАПУСКАТЬ ТОЛЬКО НА ТЕСТОВОЙ БАЗЕ: страница входит в оболочку без пароля,
# временный вход создаёт tools\shot_login.ps1, и он тоже отказывается работать
# не с тестовой базой.
#
# Запуск:
#   powershell -File tools\confirm_probe.ps1
param(
    [string]$Out = "var/confirm-probe.txt",
    [string]$Base = "http://127.0.0.1:8098"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# Адрес сервера задаётся параметром, а не вшит: раннер наборов и обычный
# локальный сервер поднимают разные сети, и проба молча проверяла не тот сайт.
# Правило простое — чужой сервер здесь не считается нашим.
$base = $Base.TrimEnd('/')
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$page = Join-Path (Get-Location) "public\_probe_page.html"
$script = Join-Path (Get-Location) "public\_probe.js"

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri "$base/_shot_tmp.php?path=/classes" -WebSession $session -UseBasicParsing
$list = (Invoke-WebRequest -Uri "$base/classes" -WebSession $session -UseBasicParsing).Content

$roster = $null
$rosterUrl = ''

# Класс ищется перебором: не у каждого есть ученики, а кнопки блокировки
# живут именно в списке ученика. Пустой класс — не поломка пробы, поэтому он
# пропускается молча.
foreach ($match in [regex]::Matches($list, 'href="(/classes/(\d+))"')) {
    $candidate = $match.Groups[1].Value

    try {
        $body = (Invoke-WebRequest -Uri "$base$candidate" -WebSession $session -UseBasicParsing).Content
    } catch {
        continue
    }

    if ($body -match 'students/status') {
        $roster = $body
        $rosterUrl = $candidate
        break
    }
}

if ($null -eq $roster) {
    "  не нашёлся класс с учениками: заведите хотя бы одного и запустите пробы заново"
    exit 1
}

# Ожидания перечислены здесь же и читаются в порядке выполнения:
#   BLOCK_MODAL=yes     — нажатие «Заблокировать ученика» открыло окно
#   BLOCK_TITLE=yes     — заголовок окна — ровно текст из data-confirm кнопки
#   BLOCK_FIELDS=yes    — форма окна несёт student_id и status=blocked
#   BLOCK_STAY=yes      — страница не ушла: без согласия ничего не отправлено
#   BLOCK_CANCEL=yes    — «Отмена» закрыла окно
#   PASS_MODAL=yes      — «Выдать новые пароли» тоже спрашивает
#   PASS_ACTION=yes     — окно отправит форму на /credentials/reveal
#   PASS_FIELDS=yes     — форма окна несёт отмеченных учеников
#
# Русских слов в пробе нет намеренно: файл пишется без метки порядка байтов, и
# браузер может прочитать его не в UTF-8 — тогда сравнение строк молча врало бы.
# Все ожидаемые тексты берутся из самой страницы.
$probeJs = @'
/*
 * Страница не должна никуда уходить.
 *
 * Раньше этого не было, и проба врала молча. Если окно подтверждения по
 * какой-то причине не открылось — например, скрипты страницы заблокированы
 * политикой безопасности, — нажатие отправляло форму, браузер переходил на
 * новый документ, и все метки пробы исчезали вместе со старой страницей.
 * Проба печатала «probe did not run», то есть упрекала саму себя: на самом
 * деле она работала, просто её работа уехала на другую страницу.
 *
 * Теперь отправка запрещена намертво. Проба отвечает на свой вопрос — открылось
 * ли окно — и не может ответить вместо него «страница сменилась».
 */
document.addEventListener('submit', function (event) {
  event.preventDefault();
}, true);

/*
 * Метка «скрипт вообще выполнился» ставится до всего остального и не зависит
 * от обработчика загрузки. Без неё «не выполнилось» и «выполнилось, но ничего
 * не нашлось» выглядят одинаково, и непонятно, куда смотреть.
 */
document.documentElement.setAttribute('data-probe-js', 'yes');

window.addEventListener('load', function () {
  var log = [];
  var out = document.createElement('pre');
  out.id = 'probe';

  function say(text) {
    log.push(text);
    out.textContent = 'PROBE:' + log.join(' | ');
    document.body.appendChild(out);
  }

  function click(node) {
    if (node) {
      node.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }
  }

  function backdrop() {
    var all = document.querySelectorAll('.modal-backdrop');

    return all.length ? all[all.length - 1] : null;
  }

  function dialog() {
    var box = backdrop();

    return box ? box.querySelector('.modal-dialog') : null;
  }

  function isOpen() {
    var box = backdrop();

    return box !== null && box.classList.contains('show');
  }

  function title() {
    var node = dialog() ? dialog().querySelector('.modal-title') : null;

    return node ? node.textContent.trim() : '';
  }

  function action() {
    var form = dialog() ? dialog().querySelector('form') : null;

    return form ? form.getAttribute('action') || '' : '';
  }

  function field(name) {
    var form = dialog() ? dialog().querySelector('form') : null;
    var node = form ? form.querySelector('[name="' + name + '"]') : null;

    return node ? node.value : '';
  }

  function hiddenCount() {
    var form = dialog() ? dialog().querySelector('form') : null;

    return form ? form.querySelectorAll('input[type="hidden"]').length : 0;
  }

  function cancel() {
    var box = dialog();

    click(box ? box.querySelector('.modal-footer .btn-outline') : null);
  }

  var blockForm = null;
  var forms = document.querySelectorAll('form[action*="/students/status"]');

  Array.prototype.forEach.call(forms, function (form) {
    var status = form.querySelector('[name="status"]');

    if (status && status.value === 'blocked' && !blockForm) {
      blockForm = form;
    }
  });

  var startUrl = location.pathname;

  if (!blockForm) {
    say('BLOCK_MODAL=no-button');

    return;
  }

  var blockButton = blockForm.querySelector('button');
  var wanted = blockButton ? blockButton.getAttribute('data-confirm') || '' : '';

  click(blockButton);

  var blockBox = backdrop();

  say('BLOCK_MODAL=' + (blockBox && dialog() ? 'yes' : 'no'));
  say('BLOCK_TITLE=' + (wanted !== '' && title() === wanted.trim() ? 'yes' : 'no'));
  say('BLOCK_FIELDS=' + (field('student_id') !== '' && field('status') === 'blocked' ? 'yes' : 'no'));
  say('BLOCK_STAY=' + (location.pathname === startUrl ? 'yes' : 'no'));
  cancel();
  say('BLOCK_CANCEL=' + (blockBox && !blockBox.classList.contains('show') ? 'yes' : 'no'));

  var pass = document.querySelector('button[formaction*="/credentials/reveal"]');

  if (!pass) {
    say('PASS_MODAL=no-button');

    return;
  }

  var pick = document.querySelector('input[name="students[]"]');

  if (pick) {
    pick.checked = true;
  }

  click(pass);

  var passBox = backdrop();

  say('PASS_MODAL=' + (passBox && dialog() ? 'yes' : 'no'));
  say('PASS_ACTION=' + (action().indexOf('/credentials/reveal') >= 0 ? 'yes' : 'no'));
  say('PASS_FIELDS=' + (hiddenCount() > 1 ? 'yes' : 'no'));
  say('PASS_STAY=' + (location.pathname === startUrl ? 'yes' : 'no'));
});
'@

# Файл скрипта пишется БЕЗ метки порядка байтов, поэтому в нём не должно быть ни
# одной кириллицы: браузер читает .js без charset по кодировке документа, а
# документ может быть прочитан не как UTF-8. Разбор такого файла падает целиком
# (не выполняется даже первая строка), и проба молча пишет «probe did not run».
# Русские объяснения живут здесь, в PowerShell-части, а не внутри JavaScript.
$roster = $roster.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $roster)
[System.IO.File]::WriteAllText($script, $probeJs)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$dom = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=10000 `
    --dump-dom "$base/_probe_page.html" 2>$null | Out-String

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue

# Признаки читаются по английским меткам, а не по русским словам: инструмент
# запускается Windows PowerShell, который без метки порядка байтов читает файл
# как ANSI, и русский текст в нём может превратиться в мусор.
$expect = @{
    'BLOCK_MODAL'   = 'yes'
    'BLOCK_TITLE'   = 'yes'
    'BLOCK_FIELDS'  = 'yes'
    'BLOCK_STAY'    = 'yes'
    'BLOCK_CANCEL'  = 'yes'
    'PASS_MODAL'    = 'yes'
    'PASS_ACTION'   = 'yes'
    'PASS_FIELDS'   = 'yes'
    'PASS_STAY'     = 'yes'
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

    "  страница: $rosterUrl"
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
} elseif ($dom -match 'data-probe-js') {
    # Скрипт выполнился, но не дошёл до конца: исключение внутри обработчика.
    # Раньше это тоже выглядело как «probe did not run», и искать причину пришлось
    # вручную — по одному этому сообщению непонятно, ломался ли браузер или код.
    "  скрипт пробы выполнился, но упал на полпути — смотрите консоль браузера"
    exit 1
} elseif ($dom -notmatch '_probe_page\.html') {
    "  браузер открыл не ту страницу: отдался документ без внедрённого скрипта"
    exit 1
} else {
    "  скрипт пробы не выполнился: браузер его не загрузил или не смог разобрать"
    exit 1
}
