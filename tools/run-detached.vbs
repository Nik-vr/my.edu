' ============================================================================
'  Запуск встроенного сервера PHP без окна консоли.
'
'  Нужен потому, что `php -S` живёт в консоли, из которой запущен. Стоит
'  закрыть это окно — и вместе с ним умирает сервер: остаётся
'  ERR_CONNECTION_REFUSED, хотя всё остальное настроено верно.
'
'  Решение: WshShell.Run со стилем окна 0 запускает процесс скрытым и
'  отвязанным. Консоли не создаётся вовсе, поэтому её нечему закрывать, а
'  вывод сервера уходит в файл журнала.
'
'  Вызывается из dev-server.ps1 с ключом -Detach, поэтому параметры
'  (порт, база, режим писем) выбирает PowerShell, а этот скрипт только
'  запускает. Никакой логики здесь быть не должно.
'
'  ДВА ОГРАНИЧЕНИЯ, ОБА ПРОВЕРЕНЫ НА ПРАКТИКЕ:
'
'    1. Файл НЕ должен начинаться с BOM. В отличие от Windows
'       PowerShell, VBScript так не умеет: wscript падает с
'       «Ошибка в строке 1 символ 1». Русские комментарии при этом
'       безвредны — они не разбираются.
'
'    2. Пользовательские строки (WScript.Echo) должны быть ASCII.
'       Без BOM файл читается как системная кодировка, поэтому русский
'       текст в сообщениях превращается в мусор. По той же причине
'       файлы .ps1 из этой папки, наоборот, обязаны быть с BOM.
'
'  Запуск напрямую (для проверки):
'      cscript //nologo tools\run-detached.vbs "php.exe" "127.0.0.1" "8088" "C:\public" "C:\public\index.php" "C:\var\dev-server.log" "MYEDU_DB_NAME=my_edu_review_test;MYEDU_MAIL_DRIVER=log"
' ============================================================================

Option Explicit

Dim shell, i, php, bind, port, docRoot, router, logPath, cmd, envVars, parts

If WScript.Arguments.Count < 6 Then
    WScript.Echo "run-detached.vbs: expected 6-7 arguments:"
    WScript.Echo "  php, bind address, port, document root, router, log file, [env vars]"
    WScript.Echo "Example:"
    WScript.Echo "  cscript //nologo run-detached.vbs php.exe 127.0.0.1 8088 C:\public C:\public\index.php C:\log.txt MYEDU_DB_NAME=my_edu_review_test"
    WScript.Quit 2
End If

php     = WScript.Arguments(0)
bind    = WScript.Arguments(1)
port    = WScript.Arguments(2)
docRoot = WScript.Arguments(3)
router  = WScript.Arguments(4)
logPath = WScript.Arguments(5)

' Переменные окружения приходят одной строкой через точку с запятой:
' PowerShell не умеет передать в VBScript список имён отдельно от значений,
' а строка разбирается здесь без лишних файлов.
envVars = ""
If WScript.Arguments.Count >= 7 Then
    envVars = WScript.Arguments(6)
End If

Set shell = CreateObject("WScript.Shell")

' Переменные окружения разбираются один раз, а не на каждом шаге цикла:
' Split внутри For вызывался бы заново на каждую итерацию.
' Повторный Dim здесь недопустим: parts уже объявлен в списке Dim выше,
' а VBScript (в отличие от VBA) считает повторное объявление синтаксической
' ошибкой, а не избыточностью.
parts = Split(envVars, ";")

cmd = ""

For i = 0 To UBound(parts)
    If Len(Trim(parts(i))) > 0 Then
        ' set "ПЕРЕМЕННАЯ=ЗНАЧЕНИЕ" — кавычки обязательны: иначе пробелы
        ' в значении (например, в пути к журналу) разорвут команду.
        cmd = cmd & " set """ & Trim(parts(i)) & """ &&"
    End If
Next

cmd = cmd & " " & q(php) & " -S " & bind & ":" & port _
    & " -t " & q(docRoot) & " " & q(router) & " >> " & q(logPath) & " 2>&1"

' Внешние кавычки удваиваются — из-за особенности разбора cmd.exe: строка,
' начинающаяся с кавычки, обрабатывается по отдельным правилам, и одиночная
' внешняя кавычка приводит к тому, что команда не запускается вовсе.
' Собранная строка выглядит так:
'     cmd.exe /c ""C:\php\php.exe" -S 127.0.0.1:8088 ... >> "log" 2>&1"
'
' Префикс cmd.exe /c добавляется именно здесь, последним шагом: если
' добавить его раньше, а затем обернуть всю строку в кавычки, получится
' cmd внутри cmd, и выводится «Ошибка при запуске вложенного командного
' файла».
cmd = "cmd.exe /c " & q(cmd)

' 0 — скрытое окно, False — не ждать завершения. Процесс переживает и
' wscript.exe, и ту оболочку, из которой запущен.
shell.Run cmd, 0, False

' Кавычки для одного аргумента командной строки Windows.
Function q(value)
    q = """" & value & """"
End Function
