@echo off
rem ==========================================================================
rem  Запуск сервера проекта на основной рабочей базе.
rem
rem  Двойной щелчок по файлу — и сервер поднимается в фоне на 127.0.0.1:8088.
rem  Браузер открывается сам. Остановить — stop-server.bat.
rem
rem  Всю работу делает tools\dev-server.ps1: этот файл только проверяет, что
rem  база данных жива, и вызывает его. Логика запуска намеренно не дублируется:
rem  копия в bat-файле рано или поздно разошлась бы с PowerShell-скриптом,
rem  и чинить пришлось бы два места сразу.
rem
rem Из командной строки:
rem      start-server.bat            запустить и открыть браузер
rem      start-server.bat --no-open  запустить, браузер не открывать
rem      start-server.bat -NoPause   запустить и сразу выйти, не ждать нажатия
rem ==========================================================================

chcp 65001 > nul
setlocal
cd /d "%~dp0"

set "PORT=8088"
set "OPEN_BROWSER=1"
set "PAUSE_AT_END=1"

if /I "%~1"=="--no-open" set "OPEN_BROWSER=0"
if /I "%~1"=="-NoPause" set "PAUSE_AT_END=0"
if /I "%~2"=="-NoPause" set "PAUSE_AT_END=0"

echo ============================================================
echo   Запуск сервера my.edu
echo ============================================================
echo.

rem --------------------------------------------------------------------------
rem  База данных.
rem
rem  Сервер поднимается и без базы — он не проверяет её при старте, — но
rem  каждая страница после этого падает. Так выглядит «сервер не доступен»,
rem  хотя на самом деле не поднялась именно база. Поэтому проверяем её
rem  заранее и поднимаем, если она остановлена.
rem --------------------------------------------------------------------------

netstat -ano -p TCP | findstr /R /C:"LISTENING" | findstr /C:":3306 " > nul

if not errorlevel 1 goto :database_ready

echo [1/2] База данных не отвечает на 3306, поднимаю...

rem MariaDB на этой машине стоит из OpenServer, и PATH про неё ничего не
rem знает: искать надо в каталогах модулей, как это делает sync_test_db.php.
set "MARIADBD="
set "MARIADB_INI="

rem Шаблон в кавычках cmd не разворачивает: в цикле остаётся сам текст
rem "MariaDB-*", и каталога с таким именем нет. Поэтому шаблон без кавычек,
rem а кавычки стоят на подставляемое значение.
for /d %%D in (D:\OpenServer\modules\database\MariaDB-*) do call :consider_db "%%~fD"
for /d %%D in (C:\OSPanel\modules\database\MariaDB-*) do call :consider_db "%%~fD"

if not defined MARIADBD goto :no_database

start "" /b "%MARIADBD%" --defaults-file="%MARIADB_INI%"

rem Ждать надо, а не сразу идти дальше: сервер успевает подняться раньше базы,
rem и первый же запрос упал бы с «MySQL server has gone away».
for /l %%I in (1,1,40) do (
    netstat -ano -p TCP | findstr /R /C:"LISTENING" | findstr /C:":3306 " > nul
    if not errorlevel 1 goto :database_ready
    rem Пауза без консоли: `timeout` требует её и сыплет ошибками, когда ввод
    rem перенаправлен, а `ping` ждёт ту же секунду и молчит.
    ping -n 2 127.0.0.1 > nul
)

:no_database
echo.
echo НЕ УДАЛОСЬ: MariaDB не найдена в D:\OpenServer или C:\OSPanel.
echo Запустите её вручную и повторите.
echo.

if "%PAUSE_AT_END%"=="1" pause

exit /d 1

:database_ready
echo [1/2] База данных отвечает.
echo.

rem --------------------------------------------------------------------------
rem  Сервер.
rem --------------------------------------------------------------------------

echo [2/2] Поднимаю сервер на порту %PORT%...

powershell -ExecutionPolicy Bypass -File "%~dp0tools\dev-server.ps1" -Detach -Port %PORT%

if errorlevel 1 goto :failed

echo.
echo Готово: http://127.0.0.1:%PORT%/
echo Остановить: stop-server.bat
echo.

if "%OPEN_BROWSER%"=="1" start "" "http://127.0.0.1:%PORT%/"

rem --------------------------------------------------------------------------
rem  Пауза в конце.
rem
rem  `pause` в конце нужен только при двойном щелчке: окно должно не исчезнуть,
rem  иначе человек не прочитает «порт занят» и не поймёт, что произошло. При
rem  запуске из командной строки или из сценария пауза превращалась в
rem  «программа зависла» — и приходилось нажимать клавишу, чтобы продолжить.
rem  Поэтому `-NoPause` отключает её.
rem --------------------------------------------------------------------------

if "%PAUSE_AT_END%"=="1" pause

exit /d 0

:failed
echo.
echo СЕРВЕР НЕ ПОДНЯЛСЯ. Подробности — в var\dev-server.log
echo Чаще всего порт %PORT% занят чем-то другим.
echo.

if "%PAUSE_AT_END%"=="1" pause

exit /d 1

rem Проверка каталога MariaDB и запоминание исполняемого файла и конфига.
:consider_db
if defined MARIADBD exit /b 0
if not exist "%~1\bin\mariadbd.exe" exit /b 0
if not exist "%~1\my.ini" exit /b 0
set "MARIADBD=%~1\bin\mariadbd.exe"
set "MARIADB_INI=%~1\my.ini"
exit /b 0