' ============================================================================
'  Запуск сервера для интеграционных тестов — база my_edu_review_test,
'  порт 8098. Отличается от обычного сервера тестовой БД и отдельным портом,
'  чтобы очистительные прогоны не затрагивали рабочее приложение.
'
'  Без BOM и с латинскими сообщениями — как и в start-dev-server.vbs,
'  причины объяснены там.
' ============================================================================

Option Explicit

Dim shell, fso, root, ps1, log

Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

root = fso.GetParentFolderName(WScript.ScriptFullName)
ps1 = root & "\dev-server.ps1"
log = fso.GetParentFolderName(root) & "\var\dev-server-status.log"

If Not fso.FileExists(ps1) Then
    MsgBox "dev-server.ps1 not found next to this script.", 16, "my.edu"
    WScript.Quit 2
End If

shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & ps1 & """ -Detach -ForTests -LogFile """ & log & """", 0, True
