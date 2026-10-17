@echo off
rem ============================================================
rem  my.edu - publish current version to GitHub
rem  Double-click this file, or run it from a command prompt.
rem  All arguments are passed to publish.ps1 as-is, for example:
rem      publish.cmd -WhatIf
rem      publish.cmd -RepoUrl https://github.com/owner/name.git
rem      publish.cmd -NoPush -SkipTests
rem  NOTE: this launcher is ASCII-only on purpose, so it displays
rem  correctly in any console code page. Details are printed in
rem  Russian by publish.ps1.
rem ============================================================
setlocal

set "SCRIPT=%~dp0publish.ps1"

if not exist "%SCRIPT%" (
    echo ERROR: publish.ps1 not found next to this file.
    echo        Folder: %~dp0
    echo.
    pause
    exit /b 1
)

where powershell >nul 2>&1
if errorlevel 1 (
    echo ERROR: Windows PowerShell not found in PATH.
    echo.
    pause
    exit /b 1
)

if not "%~1"=="" goto run

echo.
echo   my.edu - publishing
echo   ================================================
echo    1  check only, change nothing
echo    2  commit + tag + push
echo    3  same, but ask nothing and skip tests
echo    4  overwrite GitHub history (--force-with-lease)
echo.
set /p CHOICE="   Choose 1-4 (Enter = 2): "

if "%CHOICE%"=="1" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -WhatIf
) else if "%CHOICE%"=="3" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -NoPush -SkipTests
) else if "%CHOICE%"=="4" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -ForceSync
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%"
)
goto done

:run
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" %*
if errorlevel 1 goto error

:done
echo.
pause
exit /b 0

:error
echo.
echo   Finished with errors - see the messages above.
echo.
pause
exit /b 1