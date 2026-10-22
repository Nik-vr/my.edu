# Собирает архив релиза: только то, что нужно на боевом сервере.
#
# Зачем: на GitHub должен лежать релиз, который можно распаковать в корень
# сайта и получить работающее приложение. А в проекте рядом лежит всё, что
# нужно для разработки: наборы проверок, инструменты, собранные стили, дампы
# баз, логи. Всё это в релизе не только не нужно, но и вредно: чужие пароли в
# `config/config.local.php` и тестовые базы на сервере — это то, чего на
# публичном релизе быть не должно.
#
# Что попадает в архив, перечислено списками ниже и только там. Правила
# исключения заданы отдельно от правил включения, и после сборки архив
# проверяется: если внутрь просочилось что-то запрещённое, скрипт это
# называет и архив не считает готовым. Молчаливый архив с чужими секретами
# хуже, чем его отсутствие.
#
# Запуск:
#   powershell -File tools\make_release.ps1
#
# Архив кладётся в var\release\ и называется по версии из config\config.php:
# my.edu-0.2-beta.zip. Версия в названии совпадает с тегом релиза, поэтому
# Release на GitHub и архив находят друг друга без уговоров.
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # Куда положить архив. Пусто — var\release рядом с проектом.
    [string]$Out = '',

    # Версия для имени архива. Пусто — взять из config\config.php.
    [string]$Version = '',

    # Только показать, что попадёт в архив, и ничего не собирать
    [switch]$List
)

$ErrorActionPreference = 'Stop'
$script:Root = Split-Path -Parent $PSScriptRoot

try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

function Write-Step { param([string]$Text) Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok { param([string]$Text) Write-Host "  $Text" -ForegroundColor Green }
function Write-Note { param([string]$Text) Write-Host "  $Text" -ForegroundColor DarkGray }
function Write-WarnMsg { param([string]$Text) Write-Host "  ! $Text" -ForegroundColor Yellow }
function Fail { param([string]$Text) throw $Text }

# --------------------------------------------------------------------- утилиты

function Get-SevenZip {
    $local = Join-Path $script:Root 'tools\7zip\7z.exe'

    if (Test-Path $local) {
        return $local
    }

    $fromPath = Get-Command 7z.exe -ErrorAction SilentlyContinue

    if ($fromPath) {
        return $fromPath.Source
    }

    Fail 'Архиватор не найден: положите 7z.exe в tools\7zip\ (см. tools\7zip\readme.txt).'
}

function Get-Version {
    $text = (Get-Content (Join-Path $script:Root 'config\config.php') -Raw -Encoding UTF8)
    $m = [regex]::Match($text, "'version'\s*=>\s*'([^']+)'")

    if (-not $m.Success) {
        Fail 'В config\config.php не найдена версия.'
    }

    return $m.Groups[1].Value.Trim()
}

# --------------------------------------------------------------------- состав

# Каталоги, которые уходят в релиз целиком.
#
# Ядро, точки входа и оформление, шаблоны, примеры для nginx, плагины со
# способами проверки и документ с ревью релиза: без этого части не работает.
$includeDirs = @(
    'src',
    'public',
    'templates',
    'deploy',
    'plugins',
    'docs'
)

# Файлы, которые уходят в релиз поимённо.
#
# Их список задан файлами, а не каталогами, потому что рядом с ними лежит то,
# что на сервере не нужно: `config.local.php` с паролями и скрипты, которыми
# готовят тестовую базу.
$includeFiles = @(
    '.htaccess',
    'index.php',
    'README.md',
    'LICENSE',
    'changelog.md',
    'config\config.php',
    'config\config.local.example.php',
    'database\schema.sql',
    'database\migrate.php',
    'database\create_user.php',
    'database\set_owner.php',
    'database\find_orphans.php'
)

# Каталоги внутри включаемых, которые уходят целиком.
$includeDirFiles = @(
    'database\migrations'
)

# Что не должно попасть в архив ни при каких условиях.
#
# `-xr!` ищет по всему дереву, `-x!` — только в своём каталоге. Имена
# проверяются и после сборки: правило, которое не сработало, должно сказать
# об этом само.
#
# Файлы `public\_*` — временные, их создают браузерные пробы
# (`tools\shot_login.ps1` кладёт `public\_shot_tmp.php`): это вход в панель без
# пароля, и лежать ему в каталоге, который отдаётся наружу, нельзя. В `.gitignore`
# они есть, но архив собирается не из git, а из рабочего дерева, — поэтому
# правило повторяется здесь. `check_login.php` — одноразовая диагностика
# входа: на сервер её выкладывают руками и с ключом, в постоянный состав
# релиза она не входит.
#
# Каталог `public\uploads` — данные школы: загруженные картинки. В релизе
# им не место, и правило это повторяет: загрузки не едут с архивом на сервер,
# где у школы свои. Исключение одно — `public\uploads\.htaccess`: он не картинка,
# а защита каталога (отключает выполнение PHP и запрещает догадывать тип
# файла), и без него на боевом сервере каталог загрузок останется без защиты.
# Поэтому каталог исключается целиком, а `.htaccess` кладётся обратно отдельным
# шагом — после сборки.
$excludes = @(
    '-xr!tests',
    '-xr!var',
    '-xr!tools',
    '-xr!resources',
    '-xr!Импорт из OnlineTestPad',
    '-xr!.git',
    '-xr!.gitignore',
    '-x!config\config.local.php',
    '-x!public\_*',
    '-x!public\check_login.php',
    '-xr!public\uploads',
    '-x!*.log'
)

# То же самое словами: по этим признакам содержимое архива проверяется.
$forbidden = @(
    '(^|[\\/])tests[\\/]',
    '(^|[\\/])var[\\/]',
    '(^|[\\/])tools[\\/]',
    '(^|[\\/])resources[\\/]',
    'config\.local\.php$',
    '(^|[\\/])\.git',
    'OnlineTestPad',
    '\.log$',
    '^public/_',
    '^public/check_login\.php$',
    '^public/uploads/(?!\.htaccess$)'
)

# --------------------------------------------------------------------- сборка

$sevenZip = Get-SevenZip

if (-not $Version) {
    $Version = Get-Version
}

$fileVersion = ($Version -replace '\s+', '-')
$archiveName = "my.edu-$fileVersion.zip"

if (-not $Out) {
    $Out = Join-Path $script:Root "var\release\$archiveName"
} elseif (-not [IO.Path]::IsPathRooted($Out)) {
    $Out = Join-Path $script:Root $Out
}

Write-Host ''
Write-Host '  Релиз my.edu' -ForegroundColor White
Write-Host "  версия:  $Version" -ForegroundColor DarkGray
Write-Host "  архиватор: $sevenZip" -ForegroundColor DarkGray
Write-Host ''

Write-Step 'Состав релиза'

$inputs = @()

foreach ($dir in $includeDirs) {
    $path = Join-Path $script:Root $dir

    if (-not (Test-Path $path)) {
        Fail "Каталог $dir не найден — состав релиза изменился, а скрипт об этом не знает."
    }

    $inputs += $dir
    Write-Note "каталог: $dir"
}

foreach ($file in $includeFiles) {
    $path = Join-Path $script:Root $file

    if (-not (Test-Path $path)) {
        Fail "Файл $file не найден — состав релиза изменился, а скрипт об этом не знает."
    }

    if ((Get-Item $path).Length -eq 0) {
        Fail "Файл $file пуст — в релизе он бесполезен, а молчаливый пустой файл выглядит как рабочий."
    }

    $inputs += $file
    Write-Note "файл:   $file"
}

foreach ($dir in $includeDirFiles) {
    $path = Join-Path $script:Root $dir

    if (-not (Test-Path $path)) {
        Fail "Каталог $dir не найден — состав релиза изменился, а скрипт об этом не знает."
    }

    $inputs += $dir
    Write-Note "каталог: $dir"
}

Write-Note "исключается: наборы проверок, инструменты, логи, resources, локальная конфигурация"

if ($List) {
    Write-Host ''
    Write-Ok 'список показан, архив не собирался (-List)'
    exit 0
}

if (-not $PSCmdlet.ShouldProcess($archiveName, 'собрать архив релиза')) {
    Write-Host ''
    Write-Ok 'сборка отменена, ничего не изменено'
    exit 0
}

$outDir = Split-Path -Parent $Out

if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

if (Test-Path $Out) {
    Remove-Item $Out -Force
}

Write-Step 'Сборка архива'

# `-tzip` — формат zip: его открывает хостинг без всяких распаковщиков.
# `-mx=9` — максимальное сжатие: релиз выкладывают по FTP, и лишние мегабайты
# там стоят минут ожидания.
$args = @(
    'a',
    '-tzip',
    '-mx=9',
    '-bso0',
    '-bsp0',
    $Out
) + $inputs + $excludes

Push-Location $script:Root

try {
    & $sevenZip @args
    $code = $LASTEXITCODE
} finally {
    Pop-Location
}

if ($code -ne 0) {
    Fail "Архиватор вернул код $code — архив не собран."
}

# Защита каталога загрузок возвращается отдельным шагом.
#
# Каталог исключён из сборки целиком: загруженные картинки — данные школы, и
# в релизе им не место. Но `public\uploads\.htaccess` — не картинка, а защита
# каталога (гасит выполнение PHP и запрещает догадывать тип файла), и на
# боевом сервере она нужна: без неё каталог, который создаст приложение при
# первой загрузке, останется открытым. Кладётся отдельным вызовом, уже без
# исключений, — иначе правило на `uploads` вычеркнуло бы и его.
$uploadsHtaccess = 'public\uploads\.htaccess'

if (-not (Test-Path (Join-Path $script:Root $uploadsHtaccess))) {
    Fail "Нет $uploadsHtaccess — каталог загрузок останется без защиты."
}

$uploadsArgs = @(
    'a',
    '-tzip',
    '-mx=9',
    '-bso0',
    '-bsp0',
    $Out,
    $uploadsHtaccess
)

Push-Location $script:Root

try {
    & $sevenZip @uploadsArgs
    $code = $LASTEXITCODE
} finally {
    Pop-Location
}

if ($code -ne 0) {
    Fail "Защита каталога загрузок не добавлена: архиватор вернул код $code."
}

Write-Note "добавлено: $uploadsHtaccess"

if (-not (Test-Path $Out)) {
    Fail 'Архиватор отработал, а архива нет.'
}

# --------------------------------------------------------------------- проверка

Write-Step 'Проверка состава'

$listing = & $sevenZip l -slt $Out 2>&1 | Out-String
$entries = @([regex]::Matches($listing, '(?m)^Path = (.+)$') | ForEach-Object { $_.Groups[1].Value.Trim() })

# Пути приводятся к прямому слэшу: архиватор на Windows перечисляет их с
# обратным, и сравнение с тем, что записано в списках выше, иначе ничего бы не
# нашлось.
$entries = @($entries | Where-Object { $_ -and $_ -notmatch '^[A-Za-z]:' } | ForEach-Object { $_ -replace '\\', '/' })

$leaked = @($entries | Where-Object { $entry = $_; @($forbidden | Where-Object { $entry -match $_ }).Count -gt 0 })

if ($leaked.Count -gt 0) {
    Write-WarnMsg 'в архив просочилось то, чего там быть не должно:'
    $leaked | Select-Object -First 20 | ForEach-Object { Write-WarnMsg "  $_" }

    Fail 'Архив не готов: в нём лишнее.'
}

$has = @($entries | Where-Object { $_ -in @('src', 'public', 'templates', 'index.php', 'README.md', 'LICENSE', '.htaccess', 'config/config.php') })

if ($has.Count -lt 8) {
    Fail 'Архив не похож на релиз: в нём нет основных частей приложения.'
}

# Число файлов — тоже проверка.
#
# Релиз, из которого выпала половина, выглядит снаружи так же, как целый: тот же
# `src`, тот же `public`. Считать надо то, что действительно попало внутрь, и
# сравнивать с ожиданием. Порог взят с запасом: в архиве сейчас около трёхсот
# файлов, а двадцать — это уже не приложение, а его огрызок.
if ($entries.Count -lt 200) {
    Fail "В архиве всего $($entries.Count) записей — это не приложение, а его часть."
}

Write-Ok "в архиве файлов: $($entries.Count)"

$size = [int] (Get-Item $Out).Length

Write-Ok ("размер: {0:N1} МБ" -f ($size / 1MB))
Write-Ok "путь: $Out"

Write-Host ''
Write-Ok 'архив готов'
Write-Host ''
Write-Host '  Дальше: загрузить архив в релиз на GitHub и распаковать в корень' -ForegroundColor DarkGray
Write-Host '  сайта, затем заполнить config\config.local.php и открыть public\install.php.' -ForegroundColor DarkGray
Write-Host ''