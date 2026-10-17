<#
    Остановка встроенного сервера PHP.

    Сервер ищется по порту, а не по имени процесса: процессов php на
    машине может быть несколько (фоновый сервер, тесты, Artisan-подобные
    разовые вызовы), и убить все — значит убить лишнее.

    Использование:
        powershell -ExecutionPolicy Bypass -File tools\stop-dev-server.ps1
        powershell -ExecutionPolicy Bypass -File tools\stop-dev-server.ps1 -Port 8099
#>

param(
    [int]$Port = 8088,
    [string]$BindAddress = '127.0.0.1',

    # Ничего не печатать: так же удобно вызывать из других скриптов.
    [switch]$Quiet,

    # Куда писать результат, когда скрипт запущен двойным щелчком и
    # видеть вывод негде. Перенаправлять вывод средствами оболочки здесь
    # нельзя: WshShell.Run не передаёт >> в cmd, и сообщение о том, что
    # сервер остановлен, просто терялось бы.
    [string]$LogFile = ''
)

$ErrorActionPreference = 'Stop'

function Write-Status {
    param([string]$Message)

    if ($LogFile) {
        try {
            $directory = Split-Path -Parent $LogFile

            if ($directory -and -not (Test-Path -LiteralPath $directory)) {
                New-Item -ItemType Directory -Path $directory -Force | Out-Null
            }

            Add-Content -LiteralPath $LogFile -Value $Message -Encoding UTF8
        }
        catch {
            # Недоступный журнал не должен мешать остановке сервера.
        }
    }

    if (-not $Quiet) {
        Write-Host $Message
    }
}

# Соединения в состоянии LISTEN на порту — это и есть наш сервер.
# Берутся OwningProcess, а не имя образа: под портом может сидеть что-то
# другое, и убивать это вслепую нельзя.
$listeners = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
    Where-Object { $BindAddress -eq '0.0.0.0' -or $_.LocalAddress -eq $BindAddress }

if (-not $listeners) {
    Write-Status "На порту $Port никто не слушает."
    exit 0
}

$stopped = 0

foreach ($listener in $listeners) {
    $processId = $listener.OwningProcess

    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue

    if ($process -and $process.ProcessName -notlike 'php*') {
        Write-Status "Порт $Port занят не PHP ($($process.ProcessName), pid $processId). Не трогаю."
        continue
    }

    # php -S держит дочерние процессы под Windows: сначала родитель,
    # иначе дочерние останутся висеть на порту.
    Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
    $stopped++
}

Start-Sleep -Milliseconds 400

# Проверка: порт мог освободиться не сразу, а «остановить» сервер, который
# продолжает отвечать, — значит ничего не остановить.
$stillListening = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue

if ($stillListening) {
    Write-Status "Порт $Port всё ещё занят. Что-то держит его помимо этого скрипта."
    exit 1
}

Write-Status "Сервер на порту $Port остановлен (процессов: $stopped). Сейчас: $(Get-Date -Format 'HH:mm:ss')."
exit 0
