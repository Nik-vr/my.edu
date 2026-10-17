<#
    Полный прогон проверок: копия рабочей базы, сервер, все наборы.

    Порядок важен и повторяет документированный:

      1. Остановить тестовый сервер. Он держит соединения с базой, а копия
         пересоздаёт её целиком; сервер на старой базе продолжил бы отдавать
         данные из удалённых таблиц.
      2. Скопировать рабочую базу в тестовую. Наборы удаляют пользователей и
         классы, поэтому стартовать надо ровно с того, что есть в рабочей базе:
         так проверяется то же содержимое, с которым работает человек.
      3. Поднять тестовый сервер на 8098.
      4. Прогнать наборы. Первыми идут те, что чистят, — иначе later наборы
         увидят данные предыдущих.

    Использование:
        powershell -ExecutionPolicy Bypass -File tools\run_tests.ps1
        powershell -ExecutionPolicy Bypass -File tools\run_tests.ps1 -NoSync
        powershell -ExecutionPolicy Bypass -File tools\run_tests.ps1 -Only http,work
#>

param(
    # Не копировать рабочую базу. Полезно, когда данные в ней не менялись:
    # копирование занимает секунды, но каждый раз обнуляет состояние тестовой.
    [switch]$NoSync,

    [string]$PhpPath = '',

    [string]$TestPort = '8098',

    # Какие наборы прогнать. По умолчанию — все, в правильном порядке.
    [string]$Only = ''
)

$ErrorActionPreference = 'Stop'

# Русский текст в консоли PowerShell.
#
# Раньше сводка печаталась через `cmd /c` с перенаправлением в файл, и файл
# писался в кодировке текущей консоли — обычно cp866. Читать такой отчёт было
# невозможно: вместо «Провалено» получалось «Провалено» в другой кодировке, а
# при перенаправлении в файл русские буквы превращались в «кракозябры», и
# половина сообщений о падении становилась нечитаемой.
#
# Кодировка вывода задаётся явно, и сам файл отчёта тоже: иначе «прочитать файл
# в UTF-8» из инструкции не работает.
$OutputEncoding = [Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)

$projectRoot = Split-Path -Parent $PSScriptRoot
$baseUrl = "http://127.0.0.1:$TestPort"

function Write-Step {
    param([string]$Message)
    Write-Host ''
    Write-Host "=== $Message ===" -ForegroundColor Cyan
}

# --------------------------------------------------------------------- PHP

if (-not $PhpPath) {
    $PhpPath = 'c:\Tools\php\php.exe'
}

if (-not (Test-Path -LiteralPath $PhpPath)) {
    throw "Не найден PHP: $PhpPath. Укажите путь через -PhpPath."
}

# --------------------------------------------------------------- наборы

# Порядок не произвольный: run_auth_tests.php и run_school_tests.php очищают
# пользователей и классы, а run_http_tests.php требует засеянный демо-тест,
# поэтому он идёт после них.
$suites = @(
    @{ Name = 'work'; Path = 'tests/run_work_tests.php'; Http = $true },
    @{ Name = 'auth'; Path = 'tests/run_auth_tests.php'; Http = $true },
    @{ Name = 'school'; Path = 'tests/run_school_tests.php'; Http = $true },
    @{ Name = 'grading'; Path = 'plugins/test/tests/run_grading_tests.php'; Http = $false },
    @{ Name = 'variable'; Path = 'plugins/variable/tests/run_variable_tests.php'; Http = $false },
    @{ Name = 'aggregation'; Path = 'tests/run_grade_aggregation_tests.php'; Http = $false },
    @{ Name = 'schema'; Path = 'tests/run_schema_tests.php'; Http = $false },
    @{ Name = 'transfer'; Path = 'tests/run_transfer_tests.php'; Http = $false },
    @{ Name = 'plugin'; Path = 'tests/run_plugin_tests.php'; Http = $false },
    @{ Name = 'ui'; Path = 'tests/run_ui_rules_tests.php'; Http = $false },
    @{ Name = 'docs'; Path = 'tests/run_docs_tests.php'; Http = $false },
    @{ Name = 'http'; Path = 'tests/run_http_tests.php'; Http = $true; Seed = $true }
)

# Наборы, для которых перед прогоном нужна демо-работа. Засев идёт после
# копирования и до сервера: работа должна быть в базе, когда набор к ней
# пойдёт по HTTP.
$seedBefore = @($suites | Where-Object { $_.Seed } | ForEach-Object { $_.Name })

if ($Only) {
    $wanted = $Only -split ',' | ForEach-Object { $_.Trim() }
    $suites = @($suites | Where-Object { $wanted -contains $_.Name })
}

if ($suites.Count -eq 0) {
    throw "Не выбрано ни одного набора: -Only '$Only' не совпало ни с одним именем."
}

# ------------------------------------------------------------------- запуск

Write-Step 'Останавливаю тестовый сервер'
& (Join-Path $PSScriptRoot 'stop-dev-server.ps1') -Port ([int]$TestPort) -Quiet

if (-not $NoSync) {
    Write-Step 'Копирую рабочую базу в тестовую'

    # MYEDU_DB_NAME здесь намеренно не задаётся: переменная перекрывает имя
    # базы в конфигурации, и источником копии стал бы сам тестовый запуск.
    # Скрипт копирования снимает перекрытие сам — источником он считает
    # рабочую базу по определению.
    & $PhpPath (Join-Path $projectRoot 'database/sync_test_db.php')

    if ($LASTEXITCODE -ne 0) {
        throw "Копирование не удалось (код $LASTEXITCODE)."
    }
}
else {
    Write-Step 'Копирование пропущено (-NoSync)'
}

# Дальше переменная нужна наборам: они читают базу напрямую, а ходят по HTTP
# на сервер ниже.
$env:MYEDU_DB_NAME = 'my_edu_review_test'

Write-Step 'Поднимаю тестовый сервер'
& (Join-Path $PSScriptRoot 'dev-server.ps1') -Detach -ForTests -PhpPath $PhpPath -Port ([int]$TestPort) -TestPort ([int]$TestPort)

$health = Invoke-WebRequest -Uri "$baseUrl/health" -UseBasicParsing -TimeoutSec 5
$healthData = $health.Content | ConvertFrom-Json

# Сверка базы обязательна: наборы ходят по HTTP на этот сервер, а читают базу
# напрямую, и при несовпадении они падают десятками проверок с невнятными
# сообщениями — а виноват оказывается код.
if ($healthData.database_name -ne 'my_edu_review_test') {
    throw "Сервер смотрит в базу $($healthData.database_name), а не в my_edu_review_test. Прогон остановлен."
}

Write-Host "Сервер: $baseUrl, база: $($healthData.database_name)"

# Перед разрушительными наборами проверяется ещё и владелец: без него
# наборы прав проверяют неправила на другой базе и часть сценариев молча
# теряет смысл.
if (-not $healthData.owner_exists) {
    Write-Warning 'В копии нет владельца сайта. Часть проверок прав будет ослаблена.'
}

# ------------------------------------------------------------------- наборы

$failed = @()

foreach ($suite in $suites) {
    Write-Step "Набор $($suite.Name)"

    # Демо-работа засевается прямо перед набором, которому она нужна, а не
    # один раз в начале: промежуточные наборы чистят работы, и засеянное
    # заранее пропало бы молча, а набор упал бы с «нет опубликованных работ».
    if ($suite.Seed) {
        Write-Host '  Засеиваю демо-работу'

        & $PhpPath (Join-Path $projectRoot 'database/seed_demo_test.php') --force 2>&1 |
            Select-Object -Last 1 | ForEach-Object { Write-Host "  $_" }
    }

    $arguments = @((Join-Path $projectRoot $suite.Path))

    if ($suite.Http) {
        $arguments += $baseUrl
    }

    # Вывод набора пишется целиком, иначе падение не видно.
    #
    # Раньше здесь стоял Select-Object -Last 3, и это стоило реального времени:
    # набор ронялся на упавшем SQL, а в сводке оставался зелёный итог
    # предыдущего набора — провал выглядел как успех. Три последние строки
    # годятся, только когда набор точно прошёл.
    #
    # $ErrorActionPreference временно снимается: PowerShell считает любой вывод
    # нативной программы в stderr исключением, и при 'Stop' на этом прерывается
    # весь прогон. Так и случилось с набором plugin — он печатает в stderr
    # отказы драйвера письма (error_log в CLI идёт именно туда), эти строки
    # ожидаемы, а скрипт на них падал и доходил только до середины набора.
    # Признак падения — код возврата набора, а не наличие вывода в stderr.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $suiteOutput = & $PhpPath @arguments 2>&1
        $suiteExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }

    $suiteOutput | ForEach-Object { Write-Host $_ }

    if ($suiteExitCode -ne 0) {
        $failed += $suite.Name
    }
}

Write-Step 'Итог'

if ($failed.Count -gt 0) {
    Write-Host "Провалены наборы: $($failed -join ', ')" -ForegroundColor Red
    exit 1
}

Write-Host 'Все наборы пройдены.' -ForegroundColor Green
exit 0
