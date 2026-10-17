' ============================================================================
'  Остановка сервера разработки.
'
'  Двойной щелчок. Окна консоли нет: всё происходит скрыто, результат
'  пишется в var\dev-server-status.log.
'
'  Перенаправление вывода делает сам PowerShell, а не оболочка через >>:
'  WshShell.Run не передаёт перенаправление в cmd, и сообщение о
'  результате молча терялось бы.
'
'  Без BOM и с латинскими сообщениями — как и в start-dev-server.vbs.
' ============================================================================

Option Explicit

Dim shell, fso, root, ps1, log

Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

root = fso.GetParentFolderName(WScript.ScriptFullName)
ps1 = root & "\stop-dev-server.ps1"
log = fso.GetParentFolderName(root) & "\var\dev-server-status.log"

If Not fso.FileExists(ps1) Then
    MsgBox "stop-dev-server.ps1 not found next to this script.", 16, "my.edu"
    WScript.Quit 2
End If

' -Wait=True: иначе щелчок по «стоп» ничего не сделал бы, потому что
' процесс ушёл бы в фон раньше, чем успел остановить сервер.
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & ps1 & """ -Port 8088 -LogFile """ & log & """", 0, True
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & ps1 & """ -Port 8098 -LogFile """ & log & """", 0, True
