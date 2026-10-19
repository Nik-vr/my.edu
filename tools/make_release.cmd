@echo off
rem ============================================================================
rem  Сборка архива релиза.
rem
rem  Двойной щелчок по файлу — и в var\release\ появляется архив с тем, что
rem  нужно на боевом сервере. Всю работу делает tools\make_release.ps1: этот
rem  файл только проверяет, что PowerShell есть, и зовёт скрипт.
rem
rem  Что попадёт в архив и что останется снаружи, перечислено в самом скрипте.
rem
rem  Из командной строки:
rem      tools\make_release.cmd                 собрать архив
rem      tools\make_release.cmd -List           только показать состав
rem ============================================================================

chcp 65001 > nul
setlocal
cd /d "%~dp0.."

set "SCRIPT=tools\make_release.ps1"

if not exist "%SCRIPT%" (
    echo ERROR: make_release.ps1 not found next to this file.
    echo Folder: %~dp0
    goto error
)

where powershell >nul 2>nul
if errorlevel 1 (
    echo ERROR: Windows PowerShell not found in PATH.
    goto error
)

echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" %*
if errorlevel 1 goto error

echo.
echo Finished.
echo.
exit /d 0

:error
echo.
echo Finished with errors - see the messages above.
echo.
pause
exit /d 1
