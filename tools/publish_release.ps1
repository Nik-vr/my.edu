<#
.SYNOPSIS
Выкладывает релиз на GitHub: создаёт или обновляет его, прикрепляет архив и
помечает релиз как последний.

.DESCRIPTION
Релиз без отметки «последний» система обновления не видит. Адрес
`/releases/latest` отдаёт релиз, который GitHub считает последним, и
определяется он не по дате, а отдельной отметкой: релиз, созданный через API
без `make_latest`, в список последних не попадает. Тогда страница обновления
говорит «установлена последняя версия», хотя на GitHub лежит версия новее, и
обновление не предлагается вовсе.

Отметка «предрелиз» (prerelease) — другое дело: она означает «не предлагать
для установки». Беты проекта — они и есть предрелизы по смыслу версии, но на
сервере их ставить можно, поэтому отметку снимать нельзя: с ней система
обновления их не увидит.

.EXAMPLE
powershell -File tools\publish_release.ps1
powershell -File tools\publish_release.ps1 -Version '0.3.7 beta' -Tag '0.3.7-beta'
#>
[CmdletBinding()]
param(
    # Версия как она записана в настройках, например «0.3.7 beta».
    [string]$Version = '',

    # Тег релиза. По умолчанию — из номера версии: пробелы become дефисы.
    [string]$Tag = '',

    # Адрес репозитория.
    [string]$Repo = '',

    # Куда положить архив. По умолчанию — var\release.
    [string]$Out = ''
)

$ErrorActionPreference = 'Stop'

try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$script:Root = Split-Path -Parent $PSScriptRoot

function Write-Step { param([string]$Text) Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok { param([string]$Text) Write-Host "  $Text" -ForegroundColor Green }
function Write-Note { param([string]$Text) Write-Host "  $Text" -ForegroundColor DarkGray }
function Fail { param([string]$Text) throw $Text }

# ------------------------------------------------------------------ версия

if (-not $Version) {
    $config = Join-Path $script:Root 'config\config.php'

    if (-not (Test-Path $config)) { Fail "Нет $config." }

    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $config
    $match = [regex]::Match($text, "'version'\s*=>\s*'([^']+)'")

    if (-not $match.Success) { Fail 'В config/config.php нет app.version.' }

    $Version = $match.Groups[1].Value.Trim()
}

if (-not $Tag) {
    $Tag = ($Version.Trim() -replace '\s+', '-')
}

if (-not $Repo) {
    $config = Join-Path $script:Root 'config\config.php'
    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $config
    $match = [regex]::Match($text, "'repository'\s*=>\s*'([^']+)'")

    if (-not $match.Success) { Fail 'В config/config.php нет app.update.repository.' }

    $Repo = $match.Groups[1].Value.Trim()
}

Write-Step "Релиз $Version"
Write-Ok "тег: $Tag"
Write-Ok "репозиторий: $Repo"

# ------------------------------------------------------------------ архив

$archiveName = "my.edu-$Tag.zip"
$archiveDir = if ($Out) { $Out } else { Join-Path $script:Root 'var\release' }
$archive = Join-Path $archiveDir $archiveName

if (-not (Test-Path $archive)) {
    Fail "Нет архива $($archive). Соберите его скриптом tools\make_release.ps1 — он и кладёт архив в var\release."
}

Write-Ok "архив: $archive"

# ------------------------------------------------------------- учётные данные

$git = 'C:\Program Files\Git\cmd\git.exe'

if (-not (Test-Path $git)) {
    $found = Get-Command git -ErrorAction SilentlyContinue

    if (-not $found) { Fail 'Не найден git: без него не взять учётные данные.' }

    $git = $found.Source
}

$credentials = @("protocol=https`nhost=github.com`n" | & $git -C $script:Root credential fill 2>$null)
$token = $null

foreach ($line in $credentials) {
    if ($line -like 'password=*') { $token = $line.Substring(9) }
}

if (-not $token) { Fail 'Не удалось взять учётные данные у помощника git.' }

$headers = @{
    'Authorization'        = "Bearer $token"
    'Accept'               = 'application/vnd.github+json'
    'X-GitHub-Api-Version' = '2022-11-28'
    'User-Agent'           = 'my.edu-publish'
}

# ------------------------------------------------------------------ текст

$changelog = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $script:Root 'changelog.md')
$section = [regex]::Match($changelog, '(?ms)^##\s+' + [regex]::Escape($Version) + '.*?(?=^##\s|\z)')

if (-not $section.Success) { Fail "В changelog.md нет раздела $Version." }

$body = $section.Value.Trim()

# ------------------------------------------------------------------ релиз

Write-Step 'Релиз на GitHub'

$existing = $null

try {
    $existing = Invoke-RestMethod -Method Get -Uri "https://api.github.com/repos/$Repo/releases/tags/$Tag" -Headers $headers
} catch {
    Write-Note 'релиза с таким тегом ещё нет'
}

$payload = @{
    name        = $Version
    body        = $body
    draft       = $false
    prerelease  = $false
    make_latest = 'true'
}

$json = [Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json))

if ($existing) {
    Write-Note "релиз уже есть (id $($existing.id)) — обновляем"

    $release = Invoke-RestMethod -Method Patch -Uri "https://api.github.com/repos/$Repo/releases/$($existing.id)" `
        -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $json
} else {
    $payload['tag_name'] = $Tag

    $json = [Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json))

    $release = Invoke-RestMethod -Method Post -Uri "https://api.github.com/repos/$Repo/releases" `
        -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $json
}

Write-Ok "релиз: $($release.html_url)"

# ------------------------------------------------------------------ вложение

$uploadUrl = ($release.upload_url -replace '\{.*\}$', '')

foreach ($asset in @($release.assets)) {
    if ($asset.name -eq $archiveName) {
        Write-Note "старое вложение $archiveName удаляем"

        Invoke-RestMethod -Method Delete -Uri "https://api.github.com/repos/$Repo/releases/assets/$($asset.id)" `
            -Headers $headers | Out-Null
    }
}

$asset = Invoke-RestMethod -Method Post -Uri "$uploadUrl`?name=$archiveName" -Headers $headers `
    -InFile $archive -ContentType 'application/zip'

Write-Ok "вложение: $($asset.name), $($asset.size) байт"

# ------------------------------------------------------------------ проверка

Write-Step 'Проверка'

$check = Invoke-RestMethod -Method Get -Uri "https://api.github.com/repos/$Repo/releases/tags/$Tag" -Headers $headers
$latest = Invoke-RestMethod -Method Get -Uri "https://api.github.com/repos/$Repo/releases/latest" -Headers $headers

Write-Ok "тег: $($check.tag_name), черновик: $($check.draft), предрелиз: $($check.prerelease)"
Write-Ok "вложений: $(@($check.assets).Count)"

if ($latest.tag_name -ne $Tag) {
    Fail "GitHub считает последним релизом $($latest.tag_name), а не $($Tag). Система обновления не увидит новой версии."
}

Write-Ok "последним релизом на GitHub теперь считается $Tag"

Write-Host ''
Write-Host '  Готово. Обновление с сервера теперь увидит эту версию.' -ForegroundColor Green
Write-Host ''
