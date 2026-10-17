$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$php = "c:\Tools\php\php.exe"
foreach ($f in (Get-ChildItem -Filter *.php -Path "templates" -Recurse | Sort-Object Name)) {
    $r = & $php -l $f.FullName 2>&1
    if ($r -match "No syntax errors") { continue }
    $msg = ($r | Select-Object -First 2) -join ' '
    $line = 0
    if ($msg -match 'line (\d+)') { $line = [int]$Matches[1] }
    "--- " + $f.Name + "  (строка " + $line + ")"
    "    " + $msg.Trim()
    $all = [System.IO.File]::ReadAllLines($f.FullName)
    $from = [Math]::Max(0, $line - 5)
    $to = [Math]::Min($all.Length - 1, $line + 2)
    for ($i = $from; $i -le $to; $i++) {
        $mark = if ($i + 1 -eq $line) { " >>" } else { "   " }
        $mark + " " + ($i + 1) + ": " + $all[$i]
    }
}