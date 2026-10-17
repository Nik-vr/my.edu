' ============================================================================
'  Запуск сервера разработки — рабочая база.
'
'  Двойной щелчок в проводнике. Окна консоли не появляется вовсе:
'  wscript.exe запускается без неё, а сервер уходит в фон через
'  run-detached.vbs.
'
'  Почему не .cmd и не .ps1: любой из них создаёт окно PowerShell, а
'  `php -S` в этом окне живёт. Закрыли окно — потеряли сервер.
'
'  Этот файл не должен начинаться с BOM: VBScript, в отличие от
'  Windows PowerShell, такую метку не переваривает и падает на строке 1.
'  По той же причине сообщения здесь только латиницей — без BOM файл
'  читается как системная кодировка, и русский текст стал бы мусором.
'  Весь подробный вывод пишет PowerShell в var\dev-server-status.log.
' ============================================================================

Option Explicit

Dim shell, fso, root, ps1, log

Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

' Папка tools лежит рядом с этим файлом, поэтому проект ищется от него,
' а не от текущего каталога: двойной щелчок запускает из произвольного места.
root = fso.GetParentFolderName(WScript.ScriptFullName)
ps1 = root & "\dev-server.ps1"
log = fso.GetParentFolderName(root) & "\var\dev-server-status.log"

If Not fso.FileExists(ps1) Then
    MsgBox "dev-server.ps1 not found next to this script.", 16, "my.edu"
    WScript.Quit 2
End If

' -Wait=True: без него щелчок не дал бы увидеть результат запуска, а
' PowerShell всё равно успевает отработать за считанные секунды.
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & ps1 & """ -Detach -LogFile """ & log & """", 0, True
