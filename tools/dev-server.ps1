<#
    Запуск приложения на встроенном сервере PHP для локальной разработки.

    Обычный запуск — в текущем окне, остановка через Ctrl+C:

        powershell -ExecutionPolicy Bypass -File tools\dev-server.ps1

    Фоновый запуск — без окна консоли совсем, сервер переживает закрытие
    терминала. Именно так его и надо запускать в повседневной работе:

        powershell -ExecutionPolicy Bypass -File tools\dev-server.ps1 -Detach

        # сервер для запуска тестов: отдельная база и письма в журнал
        powershell -ExecutionPolicy Bypass -File tools\dev-server.ps1 -Detach -ForTests

    Готовые варианты без единого параметра — просто двойной щелчок:

        tools\start-dev-server.vbs      рабочая база
        tools\start-dev-server-tests.vbs тестовая база

    Остановить:

        tools\stop-dev-server.vbs
#>

param(
    # Не $Host: в PowerShell это встроенная неизменяемая переменная,
    # и параметр с таким именем не создаётся.
    [string]$BindAddress = '127.0.0.1',
    [int]$Port = 8088,
    [string]$PhpPath = '',

    # Тестовый режим: отдельная база и драйвер писем log.
    # Интеграционные тесты удаляют данные, поэтому на этой машине
    # используйте my_edu_review_test, не my_edu.
    [switch]$ForTests,

    [string]$TestDatabase = 'my_edu_review_test',
    [int]$TestPort = 8098,

    # Фоновый запуск без окна консоли. Сервер переживает закрытие
    # терминала, из которого запущен, — иначе он умирает вместе с ним.
    [switch]$Detach,

    # Куда писать ход запуска при -Detach. Через VBS вывода не видно
    # никуда, а молча поднявшийся сервер на другой базе — источник
    # неразбираемых ошибок в тестах.
    [string]$LogFile = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$publicDir = Join-Path $projectRoot 'public'
$entryPoint = Join-Path $publicDir 'index.php'
$listenPort = if ($ForTests) { $TestPort } else { $Port }

if (-not (Test-Path -LiteralPath $entryPoint)) {
    throw "Не найдена точка входа: $entryPoint"
}

# --------------------------------------------------------------------------
#  Поиск PHP
#
#  Порядок: явно заданный путь, c:\Tools\php, OSPanel, OpenServer, PATH.
#  На этой машине стоят разные стеки, и полагаться на один из них нельзя:
#  запуск должен работать везде, где есть проект.
# --------------------------------------------------------------------------

function Find-Php {
    param([string]$Explicit)

    if ($Explicit) {
        return $Explicit
    }

    $candidates = @('c:\Tools\php\php.exe')

    foreach ($panel in @('C:\OSPanel\modules', 'D:\OpenServer\modules')) {
        if (-not (Test-Path -LiteralPath $panel)) {
            continue
        }

        $versions = Get-ChildItem -LiteralPath $panel -Directory -Filter 'PHP-*' -ErrorAction SilentlyContinue |
            Sort-Object Name -Descending

        foreach ($version in $versions) {
            $candidates += (Join-Path $version.FullName 'php.exe')
        }
    }

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    $command = Get-Command php -ErrorAction SilentlyContinue

    return if ($command) { $command.Source } else { '' }
}

$PhpPath = Find-Php -Explicit $PhpPath

if (-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath)) {
    throw 'PHP не найден. Укажите путь к php.exe через -PhpPath.'
}

# --------------------------------------------------------------------------
#  Переменные окружения для сервера
#
#  Перекрытия читает Config: MYEDU_DB_NAME меняет только имя базы, а
#  MYEDU_MAIL_DRIVER — только драйвер письма. Всё остальное берётся из
#  config/config.local.php, поэтому сервер и тесты видят одно и то же
#  приложение.
# --------------------------------------------------------------------------

$environment = @{}

if ($ForTests) {
    $environment['MYEDU_DB_NAME'] = $TestDatabase
    $environment['MYEDU_MAIL_DRIVER'] = 'log'

    # Обрезка токенов в журнале выключается: на стенде письма не уходят, и ссылка
    # подтверждения берётся из журнала. На боевой установке обрезка включена, и
    # токен на диске не остаётся.
    $environment['MYEDU_LOG_REDACT_TOKENS'] = '0'
}

# --------------------------------------------------------------------------
#  Фоновый запуск
# --------------------------------------------------------------------------

if ($Detach) {
    $logDir = Join-Path $projectRoot 'var'

    # Журнал вывода сервера — свой у каждого профиля.
    #
    # Оба профиля не могут писать в один файл: перенаправление через `>>`
    # открывает файл на дозапись, и второй процесс, увидев занятый файл,
    # падает. При этом сообщение об ошибке уходит в stderr, а stderr тоже
    # перенаправлен в этот же файл, — то есть падение выглядит как «сервер
    # просто не поднялся», без единого слова в журнале. Именно так ведёт себя
    # рабочий сервер, пока поднят тестовый на соседнем порту.
    $logPath = if ($ForTests) {
        Join-Path $logDir 'dev-server-tests.log'
    } else {
        Join-Path $logDir 'dev-server.log'
    }

    # Статус и вывод сервера разведены по разным файлам намеренно:
    # перенаправление средствами cmd пишет ANSI-байты, а PowerShell
    # пишет UTF-8. В одном файле русские сообщения гарантированно
    # превращаются в мусор.
    if ($LogFile) {
        $statusDirectory = Split-Path -Parent $LogFile

        if ($statusDirectory -and -not (Test-Path -LiteralPath $statusDirectory)) {
            New-Item -ItemType Directory -Path $statusDirectory -Force | Out-Null
        }

        # Пишется через .NET, а не через Write-Host: перенаправление
        # Write-Host в файле появилось только в PowerShell 5.0 и работает
        # неодинаково в разных версиях.
        function Write-Status {
            param([string]$Message)

            Add-Content -LiteralPath $LogFile -Value $Message -Encoding UTF8
            Write-Host $Message
        }
    }
    else {
        function Write-Status {
            param([string]$Message)
            Write-Host $Message
        }
    }

    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    $runner = Join-Path $PSScriptRoot 'run-detached.vbs'

    if (-not (Test-Path -LiteralPath $runner)) {
        throw "Не найден запускатель: $runner"
    }

    # Прекратить прошлый сервер на том же порту: иначе новый молча не
    # поднимется, а в журнале будет «Address already in use».
    & (Join-Path $PSScriptRoot 'stop-dev-server.ps1') -Port $listenPort -Quiet

    # Значения переменных склеиваются точкой с запятой: иначе VBScript
    # не различает «нет переменных» и «одна пустая».
    $envString = ($environment.GetEnumerator() |
        ForEach-Object { "$($_.Key)=$($_.Value)" }) -join ';'

    $arguments = @(
        $PhpPath,
        $BindAddress,
        [string]$listenPort,
        $publicDir,
        $entryPoint,
        $logPath,
        $envString
    )

    # wscript вызывается напрямую, а не через Start-Process: у того нельзя
    # перенаправить вывод в два разных файла, а нужен он тут только ради
    # скрытого окна, которое даёт сам VBS.
    & 'wscript.exe' //nologo $runner $PhpPath $BindAddress ([string]$listenPort) $publicDir $entryPoint $logPath $envString

    # wscript возвращается мгновенно, а сервер поднимается не сразу.
    # Без этой паузы проверка ниже всегда говорила бы «порт свободен».
    $ready = $false

    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 250

        try {
            $response = Invoke-WebRequest -Uri "http://${BindAddress}:${listenPort}/health" -TimeoutSec 2 -UseBasicParsing

            if ($response.StatusCode -eq 200) {
                $ready = $true
                break
            }
        }
        catch {
            # Сервер ещё не слушает — это ожидаемо на первых попытках.
        }
    }

    if (-not $ready) {
        Write-Status "$(Get-Date -Format 'HH:mm:ss') СЕРВЕР НЕ ПОДНЯЛСЯ за 10 секунд. Адрес занят или PHP не запустился."
        Write-Status "Журнал сервера: $logPath"
        exit 1
    }

    $database = if ($ForTests) { "$TestDatabase (тестовая)" } else { 'рабочая, из config.local.php' }
    $mailer = if ($ForTests) { 'log' } else { 'smtp (из config.local.php)' }

    Write-Status "$(Get-Date -Format 'HH:mm:ss') Сервер запущен в фоне: http://${BindAddress}:${listenPort}/"
    Write-Status "  PHP:    $PhpPath"
    Write-Status "  База:   $database"
    Write-Status "  Письма: $mailer"
    Write-Status "  Журнал: $logPath"
    Write-Status '  Остановить: tools\stop-dev-server.vbs'
    exit 0
}

# --------------------------------------------------------------------------
#  Обычный запуск в текущем окне
# --------------------------------------------------------------------------

Write-Host "PHP:   $PhpPath"
Write-Host "Корень: $publicDir"
Write-Host "Адрес:  http://${BindAddress}:${listenPort}/"

if ($ForTests) {
    $env:MYEDU_DB_NAME = $TestDatabase
    $env:MYEDU_MAIL_DRIVER = 'log'
    $env:MYEDU_LOG_REDACT_TOKENS = '0'

    Write-Host "База:  $TestDatabase (тестовая)"
    Write-Host 'Письма: журнал var/log/mail-*.log (токены не обрезаются)'
}
else {
    Remove-Item Env:\MYEDU_DB_NAME -ErrorAction SilentlyContinue
    Remove-Item Env:\MYEDU_MAIL_DRIVER -ErrorAction SilentlyContinue
    Remove-Item Env:\MYEDU_LOG_REDACT_TOKENS -ErrorAction SilentlyContinue
}

Write-Host 'Остановка: Ctrl+C'
Write-Host ''

& $PhpPath -S "${BindAddress}:${listenPort}" -t $publicDir $entryPoint
