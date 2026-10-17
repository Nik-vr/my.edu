<#
.SYNOPSIS
    Публикация текущей версии my.edu в репозиторий GitHub.

.DESCRIPTION
    Проверяет версию, синтаксис, секреты и полный прогон тестов, затем
    создаёт коммит и тег и отправляет ветку с тегами в origin.

    Отличия от обычного «залить и забыть», и почему они здесь:

    * версия читается из config/config.php — единственного места, где она
      написана, и оттуда же подставляется в README;
    * требует, чтобы в changelog.md был раздел текущей версии. Текст раздела
      пишет владелец: сокращение changelog до значимого — решение человека, и
      молча сгенерированный раздел был бы выдумкой, а не записью;
    * проверяет, что в индекс не попадут доступы к базе, журналы и ключи, и
      делает это ДО коммита, а не после: удалить ключ из истории уже нельзя
      простым коммитом;
    * при первой публикации сам создаёт репозиторий: git init, ветка main,
      remote. Адрес передаётся ключом -RepoUrl или спрашивается, и вшивать его
      в скрипт нельзя — зашитый адрес разъезжается с реальным репозиторием
      молча.

.EXAMPLE
    .\publish.ps1 -WhatIf

.EXAMPLE
    .\publish.ps1 -RepoUrl https://github.com/Nik-vr/my.edu.git

.EXAMPLE
    .\publish.ps1 -NoPush -SkipTests

.NOTES
    Обычно достаточно запустить publish.cmd двойным кликом.
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # Адрес репозитория. При пустом значении и отсутствии remote спрашивается
    # один раз и запоминается как origin.
    [string]$RepoUrl = '',

    # Имя удалённого репозитория
    [string]$Remote = 'origin',

    # Ветка, в которую отправляем
    [string]$Branch = 'main',

    # Перезаписать историю на GitHub, если там другая история или чужие коммиты
    [switch]$ForceSync,

    # Подготовить всё локально, не отправляя
    [switch]$NoPush,

    # Не запускать полный прогон тестов (быстро, но публиковать так не стоит)
    [switch]$SkipTests,

    # Коммит и отправка через окна TortoiseGit, если git недоступен
    [switch]$Gui,

    # Путь к php.exe; если не задан, берётся из PATH
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$script:Root = $PSScriptRoot

try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

function Write-Step { param([string]$Text) Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok { param([string]$Text) Write-Host "    $Text" -ForegroundColor Green }
function Write-Note { param([string]$Text) Write-Host "    $Text" -ForegroundColor DarkGray }
function Write-WarnMsg { param([string]$Text) Write-Host "    ! $Text" -ForegroundColor Yellow }
function Fail { param([string]$Text) throw $Text }

# ---------------------------------------------------------------- инструменты

function Get-GitExe {
    $cmd = Get-Command git -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    @(
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files\Git\bin\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe'
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
}

function Get-TortoiseExe {
    @(
        'C:\Program Files\TortoiseGit\bin\TortoiseGitProc.exe',
        'C:\Program Files (x86)\TortoiseGit\bin\TortoiseGitProc.exe',
        (Join-Path $env:LOCALAPPDATA 'TortoiseGit\bin\TortoiseGitProc.exe')
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
}

function Get-PhpExe {
    if ($PhpPath) {
        if (-not (Test-Path $PhpPath)) { Fail "php не найден по указанному пути: $PhpPath" }
        return $PhpPath
    }

    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    @(
        'c:\Tools\php\php.exe',
        'C:\php\php.exe',
        (Join-Path $env:LOCALAPPDATA 'Programs\php\php.exe')
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
}

# Команда git без проверки кода возврата: он нужен там, где ненулевой код —
# ожидаемый результат (fetch без сети, ls-remote без remote).
function Invoke-GitRaw {
    param([string[]]$GitArgs)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $script:GitExe -C $script:Root @GitArgs 2>&1
        $script:GitExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $prev
    }

    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($l in @($out)) {
        if ($null -eq $l) { continue }
        # При 2>&1 PowerShell оборачивает stderr в ErrorRecord, и в сообщение
        # об ошибке без этого попадают слова про RemoteException.
        $text = if ($l -is [System.Management.Automation.ErrorRecord]) { $l.Exception.Message } else { [string]$l }
        foreach ($part in ($text -split "`r?`n")) {
            if ($part.Trim()) { $lines.Add($part) }
        }
    }
    return , $lines.ToArray()
}

# Массив аргументов передаётся без splatting: при splatting PowerShell отдаёт
# функции только первый элемент, и git получает неполную команду.
function Invoke-Git {
    param([string[]]$GitArgs)
    $out = Invoke-GitRaw $GitArgs
    if ($script:GitExitCode -ne 0) {
        Fail ("git $($GitArgs -join ' ') — код выхода $($script:GitExitCode)`n" + ($out -join "`n"))
    }
    return $out
}

function Get-GitLine {
    param([string[]]$GitArgs)
    $out = Invoke-Git $GitArgs
    if (-not $out) { return '' }
    return (@($out)[0]).Trim()
}

function Invoke-Tortoise {
    param([string]$Exe, [string[]]$TArgs)
    $proc = Start-Process -FilePath $Exe -ArgumentList $TArgs -Wait -PassThru
    if ($proc.ExitCode -ne 0) { Fail "TortoiseGit вернул код $($proc.ExitCode)." }
}

# Список удалённых репозиториев. Без `.git` (например, в режиме -WhatIf, где
# репозиторий не создаётся) git отвечает ошибкой, а не пустым списком, и она
# прерывала бы весь разбор: «не настроено» и «есть ошибка» — разные вещи.
function Get-Remotes {
    if (-not (Test-Path (Join-Path $script:Root '.git'))) { return @() }
    return @(Invoke-Git @('remote'))
}

# ------------------------------------------------------------------ версия

function Get-SystemVersion {
    $path = Join-Path $script:Root 'config\config.php'

    if (-not (Test-Path $path)) { Fail "Нет файла $path." }

    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $path
    $m = [regex]::Match($text, "'version'\s*=>\s*'([^']+)'")

    if (-not $m.Success) { Fail "В config/config.php нет ключа app.version." }

    return $m.Groups[1].Value.Trim()
}

# Тег git не может содержать пробел, а версия может: `0.2 beta` → `0.2-beta`.
function ConvertTo-TagName {
    param([string]$Version)
    return ($Version.Trim() -replace '\s+', '-')
}

# ------------------------------------------------------------------ проверки

# Есть ли путь под контролем git.
#
# Отдельно от остальных обращений: `ls-files --error-unmatch` для неизвестного
# пути печатает в stderr и выходит с кодом 1, а это здесь не ошибка, а ответ
# «нет». При ErrorActionPreference = Stop такая строка рвёт весь разбор, поэтому
# stderr гасится, а решение принимается по коду возврата.
function Test-Tracked {
    param([string]$Path)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $null = & $script:GitExe -C $script:Root ls-files --error-unmatch -- $Path 2>$null
        return $LASTEXITCODE -eq 0
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Test-NoSecrets {
    # Самая важная проверка из всех. Ключ или доступы к базе, однажды попавшие в
    # коммит, удаляются оттуда только переписыванием истории — то есть силой.
    $forbidden = @(
        @{ Path = 'config\config.local.php'; What = 'настройки с доступами к базе' },
        @{ Path = 'var';            What = 'рабочий каталог: журналы, копии, база' },
        @{ Path = 'resources';       What = 'заимствованные исходники и сборщик стилей' },
        @{ Path = 'импорт из OnlineTestPad'; What = 'архив с данными' }
    )

    # Проверка через git возможна только в репозитории. Без него остаётся
    # файловая проверка ниже — она ловит то же самое, просто не видит истории.
    $inRepo = Test-Path (Join-Path $script:Root '.git')

    foreach ($item in $forbidden) {
        $path = Join-Path $script:Root $item.Path

        if (-not (Test-Path $path)) { continue }

        if ($inRepo) {
            if (Test-Tracked $item.Path) {
                Fail ("В индексе есть $($item.Path) — $($item.What).`n" +
                      "Файл уже попал бы в коммит. Уберите его из индекса:`n" +
                      "    git rm -r --cached `"$($item.Path)`"")
            }
        }

        Write-Ok "$($item.Path) не попадёт в коммит"
    }

    $keys = Get-ChildItem -LiteralPath $script:Root -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Extension -match '^\.(pem|key|p12|pfx|crx)$' -and $_.FullName -notmatch '\\resources\\' }

    if ($keys) {
        Fail "В проекте лежат ключи или подписи: $(($keys | ForEach-Object { $_.Name }) -join ', ')"
    }

    Write-Ok 'ключей и подписей в проекте нет'
}

function Test-PhpSyntax {
    $php = Get-PhpExe

    if (-not $php) {
        Write-WarnMsg 'php не найден — синтаксис не проверялся (укажите -PhpPath)'
        return
    }

    $files = @(Get-ChildItem -LiteralPath $script:Root -Recurse -Filter '*.php' -File |
        Where-Object { $_.FullName -notmatch '\\var\\|\\resources\\' })

    foreach ($f in $files) {
        $out = & $php -l $f.FullName 2>&1

        if ($LASTEXITCODE -ne 0) {
            Fail "Синтаксическая ошибка в $($f.FullName.Substring($script:Root.Length + 1)):`n$($out -join "`n")"
        }
    }

    Write-Ok "синтаксис в порядке (файлов PHP: $($files.Count))"
}

function Test-Tests {
    if ($SkipTests) {
        Write-WarnMsg 'полный прогон пропущен (-SkipTests)'
        return
    }

    $runner = Join-Path $script:Root 'tools\run_tests.ps1'

    if (-not (Test-Path $runner)) { Fail "Нет $runner." }

    $script:TestsLog = [System.IO.Path]::Combine($env:TEMP, "myedu-publish-tests.log")

    $args = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $runner)

    if ($PhpPath) { $args += @('-PhpPath', $PhpPath) }

    Write-Note 'идёт полный прогон тестов, это занимает несколько минут'
    $out = @(& powershell @args 2>&1)
    $code = $LASTEXITCODE

    if ($out) { $out | Set-Content -Encoding UTF8 -LiteralPath $script:TestsLog }

    if ($code -ne 0) {
        $summary = @($out | Select-String -Pattern 'Провалено|Все проверки пройдены' |
            ForEach-Object { $_.ToString() })

        Fail ("Тесты не пройдены (код $code).`n" +
              ($summary -join "`n") + "`nПолный отчёт: $script:TestsLog")
    }

    foreach ($line in @($out | Select-String -Pattern 'Все проверки пройдены' | ForEach-Object { $_.ToString() })) {
        Write-Ok (($line -replace "`e\[[0-9;]*m", '').Trim())
    }

    Write-Note "отчёт прогона: $script:TestsLog"
}

function Test-ChangelogEntry {
    $path = Join-Path $script:Root 'changelog.md'

    if (-not (Test-Path $path)) { Fail 'Нет changelog.md.' }

    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $path
    $heading = '^##\s+' + [regex]::Escape($script:Version) + '(\s|$)'

    if ($text -notmatch "(?m)$heading") {
        Fail ("В changelog.md нет раздела для версии $($script:Version).`n" +
              "Добавьте раздел вручную и повторите.`nПорядок такой: сначала кратко о том,`n" +
              "что изменилось и что это значит для пользователя, без имён функций и`n" +
              "файлов, затем — публикация. Подробности разработки — в ПРОДОЛЖИТЬ_РАБОТУ.md.")
    }

    Write-Ok "changelog.md: раздел $($script:Version) на месте"
}

# -------------------------------------------------------- README и changelog

function Sync-Readme {
    $path = Join-Path $script:Root 'README.md'

    if (-not (Test-Path $path)) { Write-Note 'README.md не найден — пропускаю'; return }

    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $path
    $before = $text

    $text = [regex]::Replace($text, '(?<=Текущая версия: \*\*)[^*]+(?=\*\*)', $script:Version)

    if ($text -eq $before) {
        Write-Note "README.md уже содержит версию $($script:Version)"
        return
    }

    if ($PSCmdlet.ShouldProcess('README.md', "обновить версию до $($script:Version)")) {
        [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
        Write-Ok "README.md: версия $($script:Version)"
    }
}

function Show-ChangelogEntry {
    $path = Join-Path $script:Root 'changelog.md'

    if (-not (Test-Path $path)) { return }

    $text = Get-Content -Raw -Encoding UTF8 -LiteralPath $path
    $m = [regex]::Match(
        $text,
        '(?ms)^##\s+' + [regex]::Escape($script:Version) + '.*?(?=^##\s|\z)'
    )

    if ($m.Success) {
        Write-Note 'раздел changelog этой версии:'
        foreach ($line in ($m.Value -split "`r?`n" | Select-Object -First 12)) {
            if ($line.Trim()) { Write-Host "      $($line.Trim())" -ForegroundColor DarkGray }
        }
    }
}

# ------------------------------------------------------------------ git-состояние

function Initialize-Repository {
    if (Test-Path (Join-Path $script:Root '.git')) {
        Write-Ok 'репозиторий уже создан'
        return
    }

    if (-not $PSCmdlet.ShouldProcess($script:Root, 'git init')) {
        Write-Note '-WhatIf: репозиторий не создан'
        return
    }

    Invoke-Git @('init', '--initial-branch', $Branch) | Out-Null
    Write-Ok "репозиторий создан, ветка $Branch"

    $cfg = @(
        @{ Key = 'user.name';  Value = 'Nik-vr' },
        @{ Key = 'user.email'; Value = 'nik_vr@mail.ru' }
    )

    foreach ($item in $cfg) {
        if (-not (Get-GitLine -GitArgs @('config', $item.Key))) {
            Invoke-Git @('config', $item.Key, $item.Value) | Out-Null
            Write-Ok "$($item.Key) = $($item.Value)"
        }
    }
}

function Ensure-Remote {
    $remotes = Get-Remotes

    if ($remotes -contains $Remote) {
        $current = Get-GitLine -GitArgs @('remote', 'get-url', $Remote)

        if ($RepoUrl -and $current -ne $RepoUrl) {
            if ($PSCmdlet.ShouldProcess($Remote, "обновить адрес на $RepoUrl")) {
                Invoke-Git @('remote', 'set-url', $Remote, $RepoUrl) | Out-Null
                Write-Ok "адрес $Remote обновлён"
            }
        } else {
            Write-Ok "remote ${Remote}: $current"
        }

        return
    }

    if (-not $RepoUrl) {
        if (-not $Host.UI -or -not $Host.UI.RawUI) { return }

        $answer = Read-Host "    Адрес репозитория (например https://github.com/владелец/имя.git)"

        if (-not $answer) {
            Write-WarnMsg 'адрес не задан — отправка невозможна'
            return
        }

        $script:RepoUrl = $answer.Trim()
    }

    if ($PSCmdlet.ShouldProcess($Remote, "добавить адрес $RepoUrl")) {
        Invoke-Git @('remote', 'add', $Remote, $RepoUrl) | Out-Null
        Write-Ok "remote $Remote добавлен: $RepoUrl"
    }
}

function Get-RemoteBranchState {
    $state = [ordered]@{ Exists = $false; Related = $false; Behind = 0; Ahead = 0; Tip = '' }

    $null = Invoke-GitRaw @('fetch', $Remote, '--quiet')
    $ls = @(Invoke-GitRaw @('ls-remote', '--heads', $Remote, "refs/heads/$Branch"))
    $line = @($ls | Where-Object { $_ -match "\trefs/heads/$Branch$" })[0]

    if (-not $line) { return [pscustomobject]$state }

    $state.Exists = $true
    $state.Tip = @((([string]$line) -split '\s+'))[0]
    $remoteRef = "$Remote/$Branch"

    $base = @(Invoke-GitRaw @('merge-base', 'HEAD', $remoteRef))[0]
    $state.Related = [bool]$base

    if (-not $state.Related) { return [pscustomobject]$state }

    $state.Behind = [int]@($args = Invoke-Git @('rev-list', '--count', $remoteRef, '^HEAD'))[0]
    $state.Ahead = [int]@($args = Invoke-Git @('rev-list', '--count', 'HEAD', "^$remoteRef"))[0]
    return [pscustomobject]$state
}

# ---------------------------------------------------------------------- основной

Write-Host ''
Write-Host '  Публикация my.edu' -ForegroundColor White
Write-Host "  папка:       $script:Root" -ForegroundColor DarkGray
Write-Host "  репозиторий: $(if ($RepoUrl) { $RepoUrl } else { 'будет спрошен' })" -ForegroundColor DarkGray
Write-Host ''

Write-Step 'Инструменты'
$script:GitExe = Get-GitExe
$script:TortoiseExe = Get-TortoiseExe

if ($script:GitExe) {
    Write-Ok "git: $script:GitExe"
} elseif ($script:TortoiseExe) {
    Write-WarnMsg 'git не найден в PATH — будет использован TortoiseGit'
    Write-Ok "TortoiseGit: $script:TortoiseExe"
} else {
    Fail 'Не найден ни git, ни TortoiseGit. Установите Git for Windows или TortoiseGit.'
}

$php = Get-PhpExe

if ($php) {
    Write-Ok "php: $php"
} else {
    Write-WarnMsg 'php не найден — синтаксис и тесты пропускаются (укажите -PhpPath)'
}

Write-Step 'Проверки'
$script:Version = Get-SystemVersion
$tag = ConvertTo-TagName $script:Version

Write-Ok "версия $($script:Version), тег $tag"

# Репозиторий создаётся до проверок, а не после: проверка секретов спрашивает у
# git, что попадёт в индекс, и без репозитория спрашивать не о чем.
Initialize-Repository
Test-NoSecrets
Test-PhpSyntax
Test-ChangelogEntry
Test-Tests

Write-Step 'Репозиторий'
Ensure-Remote

$script:Pushed = $false
$script:ResumeHint = ''
$remoteState = $null

if ((Get-Remotes) -contains $Remote) {
    $remoteState = Get-RemoteBranchState

    if ($remoteState.Exists) {
        $tip = $remoteState.Tip.Substring(0, 7)

        if ($remoteState.Related) {
            Write-Ok "ветка $Branch на GitHub: $tip (с вашей историей)"
            Write-Note "впереди: $($remoteState.Ahead), отсутствует локально: $($remoteState.Behind)"
        } else {
            Write-WarnMsg "ветка $Branch на GitHub: $tip — история другая, общих коммитов нет"
        }
    } else {
        Write-Note "ветки $Branch на GitHub нет — будет создана при отправке"
    }
} else {
    Write-Note 'remote не настроен — отправка невозможна'
}

Write-Step 'Документация'
Sync-Readme
Show-ChangelogEntry

# Дальше всё требует репозитория. В режиме -WhatIf его нет — и это не ошибка,
# а суть режима: показать, что будет сделано, ничего не делая.
$inRepo = Test-Path (Join-Path $script:Root '.git')

if (-not $inRepo) {
    Write-Host ''
    Write-Host '  Репозиторий не создан, поэтому дальше идти некуда.' -ForegroundColor Yellow
    Write-Host '  Проверка завершена, ничего не изменено.' -ForegroundColor Green
    Write-Host ''
    exit 0
}

Write-Step 'Коммит'
$changes = @(Invoke-Git @('status', '--porcelain'))

if ($changes) {
    $changes | Select-Object -First 40 | ForEach-Object { Write-Note $_ }
    $ignored = @(Invoke-Git @('status', '--porcelain', '--ignored'))

    foreach ($name in @('config/config.local.php', 'var/', 'resources/')) {
        $hit = @($ignored | Where-Object { $_ -match [regex]::Escape($name) })

        if ($hit) { Write-Note "в коммит не попадёт: $name ($($hit.Count) шт.)" }
    }
}

$staged = @()

if ($PSCmdlet.ShouldProcess('файлы проекта', 'git add -A')) {
    Invoke-Git @('add', '-A') | Out-Null
    $staged = @(Invoke-Git @('diff', '--cached', '--name-only'))

    if ($staged) {
        Write-Ok "в коммите файлов: $($staged.Count)"

        # Последний рубеж перед историей: проверяются конкретные пути, а не
        # подозрительные слова в именах файлов.
        #
        # Слова ищутся по шаблону — так проще, но ловит и безобидное:
        # `config/config.local.example.php` — это образец с заглушками, и он в
        # репозитории должен быть. Ошибка, которая всегда срабатывает, хуже
        # отсутствующей проверки: её начинают обходить и перестают читать.
        $forbiddenInIndex = @(
            @{ Pattern = '^config/config\.local\.php$';            What = 'доступы к базе' },
            @{ Pattern = '^var/';                                   What = 'журналы и копии' },
            @{ Pattern = '^resources/';                             What = 'заимствованные исходники' },
            @{ Pattern = '\.(pem|key|p12|pfx|crx)$';               What = 'ключ или подпись' }
        )

        $caught = @()

        foreach ($rule in $forbiddenInIndex) {
            $hit = @($staged | Where-Object { $_ -match $rule.Pattern })

            foreach ($file in $hit) {
                $caught += ('  ' + $file + '  <- ' + $rule.What)
            }
        }

        if ($caught.Count -gt 0) {
            # Сообщение собирается из строк, а не склеивается подстановками: в
            # строке с обратными слэшами и кавычками внутри кавычек легко
            # ошибиться, а ошибка здесь выглядит как поломка скрипта, а не как
            # опечатка в тексте сообщения.
            $lines = @('В индексе есть то, что попасть в репозиторий не должно:')
            $lines += $caught
            $lines += 'Прервано: из индекса это убирается командой'
            $lines += '    git rm -r --cached <путь>'

            Fail ($lines -join [Environment]::NewLine)
        }

        Write-Ok 'в индексе нет ни доступов, ни журналов, ни ключей'
    }
} elseif ($changes) {
    Write-Note '-WhatIf: файлы не добавлены в индекс, коммит не создаётся'
}

$message = if ($script:Version) { $script:Version } else { '0.2 beta' }

if ($staged.Count -gt 0) {
    if ($Gui -and $script:TortoiseExe) {
        if ($PSCmdlet.ShouldProcess('коммит', $message)) {
            Invoke-Tortoise $script:TortoiseExe @(
                '/command:commit', "/path:`"$($script:Root)`"",
                "/message:`"$message`"", '/closeonclick:1'
            )
            Write-Ok 'коммит создан через TortoiseGit'
        }
    } elseif ($script:GitExe) {
        if ($PSCmdlet.ShouldProcess('коммит', $message)) {
            Invoke-Git @('commit', '-m', $message) | Out-Null
            Write-Ok "коммит создан: $message"
        }
    }
} elseif (-not $WhatIfPreference) {
    Write-Note 'изменений нет — коммит не создаётся'
}

Write-Step 'Тег'

if (@(Invoke-Git @('tag', '--list', $tag)) -contains $tag) {
    Write-Note "тег $tag уже существует"
} elseif ($PSCmdlet.ShouldProcess('тег', $tag)) {
    Invoke-Git @('tag', '-a', $tag, '-m', $tag) | Out-Null
    Write-Ok "создан тег $tag"
}

if ($NoPush) {
    Write-Note '-NoPush: отправка пропущена'
} elseif (-not ((Get-Remotes) -contains $Remote)) {
    Write-WarnMsg 'remote не настроен — отправка не выполнена'
} elseif ($remoteState -and $remoteState.Exists -and (-not $remoteState.Related -or $remoteState.Behind -gt 0)) {
    if ($remoteState.Related) {
        Write-WarnMsg "на GitHub в ветке $Branch есть $($remoteState.Behind) коммитов, которых нет локально"
        Write-Host '   продолжить историю:' -ForegroundColor Yellow
        Write-Host "        git -C `"$($script:Root)`" pull --rebase $($Remote) $Branch" -ForegroundColor Yellow
    } else {
        Write-WarnMsg "история на GitHub другая: общих коммитов с веткой $Branch нет"
        Write-Host '   Локальная история полнее (в ней первый релиз и все исправления),' -ForegroundColor Yellow
        Write-Host '   поэтому её нужно отправить с перезаписью. Старый коммит останется' -ForegroundColor Yellow
        Write-Host "   доступен по своему хешу $($remoteState.Tip.Substring(0, 7))." -ForegroundColor Yellow
    }

    $script:ResumeHint = "powershell -ExecutionPolicy Bypass -File `"$($MyInvocation.MyCommand.Path)`" -ForceSync -SkipTests"
    Write-Host ''
    Write-Host '   ОТПРАВКА НЕ ВЫПОЛНЕНА. Повторить с перезаписью (--force-with-lease):' -ForegroundColor Red
    Write-Host "        $script:ResumeHint" -ForegroundColor Red
    Write-Host ''
} elseif ($Gui -and $script:TortoiseExe) {
    if ($PSCmdlet.ShouldProcess('отправка', "$Remote $tag")) {
        if ($ForceSync) { Write-WarnMsg 'перезапись истории (в TortoiseGit: Force Push)' }
        Invoke-Tortoise $script:TortoiseExe @(
            '/command:push', "/path:`"$($script:Root)`"",
            "/branchname:$Branch", '/autoload:1'
        )
        $script:Pushed = $true
        Write-Ok 'отправка через TortoiseGit завершена'
    }
} else {
    # Явное значение lease: «я видел на сервере именно этот коммит». Без него
    # git опирается на remote-tracking ref, которого может не быть, и отвечает
    # «no upstream branch». Плюс защита сильнее: если на сервере что-то изменили
    # после нашей проверки, push не пройдёт.
    $refspec = "HEAD:refs/heads/$Branch"
    $pushArgs = @('push', '-u', $Remote, $refspec)

    if ($ForceSync) {
        $lease = "--force-with-lease=refs/heads/${Branch}:$($remoteState.Tip)"
        $pushArgs = @('push', $lease, '-u', $Remote, $refspec)
        Write-Note "ожидаемый коммит на сервере: $($remoteState.Tip.Substring(0, 7))"
    }

    $pushArgs += @('--follow-tags')

    if ($PSCmdlet.ShouldProcess('отправка', "$Remote $Branch $tag")) {
        if ($ForceSync) { Write-WarnMsg 'перезапись истории на GitHub (--force-with-lease)' }
        Invoke-Git $pushArgs | ForEach-Object { Write-Note $_ }
        $script:Pushed = $true
        Write-Ok "ветка $Branch и теги отправлены в $Remote"
    }
}

$head = Get-GitLine -GitArgs @('rev-parse', '--short', 'HEAD')
$branchNow = Get-GitLine -GitArgs @('rev-parse', '--abbrev-ref', 'HEAD')

Write-Host ''
if ($WhatIfPreference) {
    Write-Host '  Проверка завершена, ничего не изменено' -ForegroundColor Green
} elseif ($NoPush) {
    Write-Host '  Локально всё готово, отправка не выполнялась (-NoPush)' -ForegroundColor Yellow
} elseif (-not $script:Pushed) {
    Write-Host '  ОТПРАВКА НЕ ВЫПОЛНЕНА — на GitHub ничего не изменилось' -ForegroundColor Red
} else {
    Write-Host '  Готово, изменения на GitHub' -ForegroundColor Green
}

Write-Host "    версия:  $($script:Version)" -ForegroundColor DarkGray
Write-Host "    тег:     $tag" -ForegroundColor DarkGray
Write-Host "    коммит:  $head ($branchNow)" -ForegroundColor DarkGray

if (-not $script:Pushed -and -not $WhatIfPreference -and -not $NoPush) {
    Write-Host ''
    if ($script:ResumeHint) {
        Write-Host '  Что сделать дальше:' -ForegroundColor Red
        Write-Host "   $script:ResumeHint" -ForegroundColor Red
        Write-Host '   или запустите publish.cmd и выберите пункт с перезаписью.' -ForegroundColor Red
    } else {
        Write-Host '  Настройте remote и повторите запуск — смотрите раздел «Отправка».' -ForegroundColor Red
    }
}

Write-Host ''
Write-Host '  Дальше на GitHub: Repository → Releases → Create a new release' -ForegroundColor DarkGray
Write-Host "  выберите тег $tag и напишите то, что уже есть в разделе changelog." -ForegroundColor DarkGray
Write-Host ''