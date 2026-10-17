# Breadcrumb audit: every reachable panel page must carry exactly one
# breadcrumb trail, and it must sit in the header rather than in the page body.
$base = "http://127.0.0.1:8098"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$page = Invoke-WebRequest -Uri "$base/login" -WebSession $session -UseBasicParsing
$token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
$null = Invoke-WebRequest -Uri "$base/login" -Method Post -WebSession $session -UseBasicParsing -Body @{
    _token = $token; identifier = $env:MYEDU_OWNER_EMAIL; password = $env:MYEDU_OWNER_PASSWORD
}

$list = Invoke-WebRequest -Uri "$base/panel/assignments" -WebSession $session -UseBasicParsing
$classId = [regex]::Match($list.Content, '/classes/(\d+)').Groups[1].Value
$assignmentId = [regex]::Match($list.Content, '/panel/assignments/(\d+)').Groups[1].Value

$urls = @(
    '/panel', '/classes', '/classes/new', "/classes/$classId", "/classes/$classId?tab=roster",
    "/classes/$classId/delete", '/panel/subjects', '/panel/users', '/panel/users/1',
    '/panel/plugins', '/panel/settings', '/panel/ratings', '/panel/results',
    '/panel/journal', '/panel/assignments', '/panel/assignments/new', '/panel/assignments/import'
)
if ($assignmentId) {
    $urls += "/panel/assignments/$assignmentId"
    $urls += "/panel/assignments/$assignmentId/edit"
    $urls += "/panel/assignments/$assignmentId/edit/items"
    $urls += "/panel/assignments/$assignmentId/edit/start"
    $urls += "/panel/assignments/$assignmentId/edit/result"
    $urls += "/panel/assignments/$assignmentId/delete"
    $urls += "/panel/assignments/$assignmentId/students"
}

$bad = 0
foreach ($u in $urls) {
    try {
        $r = Invoke-WebRequest -Uri "$base$u" -WebSession $session -UseBasicParsing
    } catch {
        "  SKIP $u : " + $_.Exception.Response.StatusCode
        continue
    }

    $total = ([regex]::Matches($r.Content, '<nav class="breadcrumb"')).Count
    if ($r.Content -match '(?s)<main[\s\S]*?<nav class="breadcrumb"') {
        $note = "DUPLICATE IN BODY"
    } else {
        $note = "header ok"
    }

    if ($total -ne 1) { $bad++ }
    "  {0,-42} HTTP {1}  crumbs: {2}  {3}" -f $u, $r.StatusCode, $total, $note
}

""
"  pages with wrong crumb count: $bad"