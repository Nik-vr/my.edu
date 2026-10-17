$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
# Shows the rendered output of a panel page under the owner's session.
# Login: owner from the seeded test database.
$base = "http://127.0.0.1:8098"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$page = Invoke-WebRequest -Uri "$base/login" -WebSession $session -UseBasicParsing
$token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
$email = $env:MYEDU_OWNER_EMAIL
$password = $env:MYEDU_OWNER_PASSWORD

if (-not $email) { "  set MYEDU_OWNER_EMAIL / MYEDU_OWNER_PASSWORD"; exit 1 }

$null = Invoke-WebRequest -Uri "$base/login" -Method Post -WebSession $session -UseBasicParsing -Body @{
    _token = $token; identifier = $email; password = $password
}

$target = $args[0]
if (-not $target) { $target = '/classes' }

$r = Invoke-WebRequest -Uri "$base$target" -WebSession $session -UseBasicParsing
"  " + $target + " -> " + $r.StatusCode + ", " + $r.Content.Length + " байт"
$m = [regex]::Match($r.Content, '<main[\s\S]*?</main>')
if ($m.Success) {
    $body = $m.Value
    $body = [regex]::Replace($body, '\s+', ' ')
    if ($body.Length -gt 1500) { $body = $body.Substring(0, 1500) }
    "  " + $body
} else {
    "  <main> не найден; первые 800 символов:"
    $h = $r.Content
    if ($h.Length -gt 800) { $h = $h.Substring(0, 800) }
    "  " + $h
}