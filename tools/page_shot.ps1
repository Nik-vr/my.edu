# Снимает страницу панели в браузере под тестовым владельцем.
#
# Нужен, чтобы увидеть вёрстку глазами: ширину колонок, наложения и обрезку по
# разметке не определить. Браузер открывает временный вход, тот ставит сессию
# и уводит на нужный адрес.
#
# Страница копируется на диск не браузером, а скриптом, и адреса стилей
# переписываются на абсолютные: на файловой странице относительные адреса искали
# бы файлы рядом с ней, и страница пришла бы без оформления.
param(
    [string]$Path = "/panel/users",
    [string]$Out = "var/shot.png",
    [string]$Width = "1560",
    [string]$Height = "1000",

    # Тема страницы: light или dark. Без ключа берётся то, что сохранил
    # браузер, — а у нового профиля ничего не сохранено, и снимок выходит тем,
    # что выпадет по умолчанию.
    [ValidateSet('light', 'dark')]
    [string]$Theme = ''
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:8098"
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$shot = Join-Path (Get-Location) $Out

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"

# Свежий профиль и метка в адресе: иначе браузер берёт страницу из кэша и
# снимок показывает старую вёрстку — при правках это выглядит как «ничего не
# изменилось», хотя изменилось всё.
$profile = Join-Path $env:TEMP ("myedu-profile-" + [guid]::NewGuid().ToString("N").Substring(0, 8))

$separator = if ($Path.Contains('?')) { '&' } else { '?' }
$fresh = $Path + $separator + '_shot=' + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

if (Test-Path $shot) { Remove-Item $shot -Force }

# Тема идёт отдельным параметром: снимок в тёмной теме отличается от
# светлого не только фоном, и сравнивать их на глаз, держа в голове, какая
# тема была, — верный способ сравнить не то.
$shotQuery = 'path=' + [uri]::EscapeDataString($fresh)

if ($Theme -ne '') {
    $shotQuery += '&theme=' + $Theme
}

$url = "$base/_shot_tmp.php?" + $shotQuery

& $edge --headless=new --disable-gpu --no-sandbox --hide-scrollbars `
    --user-data-dir="$profile" `
    --window-size="$Width,$Height" --virtual-time-budget=8000 `
    --screenshot="$shot" $url 2>&1 | Out-Null

Start-Sleep -Seconds 5

Remove-Item $profile -Recurse -Force -ErrorAction SilentlyContinue

# Временный вход удаляется сразу: лежать в каталоге, который отдаётся наружу,
# он не должен.
Remove-Item $login -Force -ErrorAction SilentlyContinue

if (Test-Path $shot) {
    "  $Path" + $(if ($Theme -ne '') { " [$Theme]" } else { '' }) + " -> " + [int]((Get-Item $shot).Length / 1KB) + " КБ"
} else {
    "  снимок не создан"
    exit 1
}