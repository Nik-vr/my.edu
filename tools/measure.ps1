# Замеряет оформление элементов страницы прямо в браузере.
#
# По разметке нельзя узнать, применилось ли правило: CSS каскадит, и класс
# может быть перебит чем-то, что и не упоминает его имени. Замер снимает
# итоговые значения — те самые, которые рисует экран.
#
# Страница копируется в public/ и открывается с того же адреса, что и сам
# сайт: иначе относительные адреса стилей не найдут файлы. Проба — отдельный
# файл, потому что политика безопасности запрещает код прямо в странице.
param(
    [string]$Path = "/panel/users",
    [string]$Selector = ".cell-avatar",
    [string]$Props = "border-radius,background-color,font-size,color,width,height"
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = "http://127.0.0.1:8098"
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$page = Join-Path (Get-Location) "public\_probe_page.html"
$script = Join-Path (Get-Location) "public\_probe.js"

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginUrl = "$base/_shot_tmp.php?path=" + [uri]::EscapeDataString($Path)
$null = Invoke-WebRequest -Uri $loginUrl -WebSession $session -UseBasicParsing
$html = (Invoke-WebRequest -Uri "$base$Path" -WebSession $session -UseBasicParsing).Content

[System.IO.File]::WriteAllText($script, @"
window.addEventListener('load', function () {
  var el = document.querySelector('$Selector');
  var out = document.createElement('pre');
  out.id = 'probe';
  if (!el) { out.textContent = 'PROBE:not-found'; document.body.appendChild(out); return; }
  var cs = getComputedStyle(el);
  var box = el.getBoundingClientRect();
  var parts = '$Props'.split(',').map(function (p) { return p + '=' + cs.getPropertyValue(p); });
  parts.unshift('box=' + Math.round(box.width) + 'x' + Math.round(box.height));
  parts.push('inline=' + (el.getAttribute('style') || 'НЕТ'));
  parts.push('purple=' + getComputedStyle(document.documentElement).getPropertyValue('--purple'));
  out.textContent = 'PROBE:' + parts.join('; ');
  document.body.appendChild(out);
});
"@)

$html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
[System.IO.File]::WriteAllText($page, $html)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$out = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=8000 `
    --dump-dom "$base/_probe_page.html" 2>$null | Out-String

Remove-Item $login, $page, $script -Force -ErrorAction SilentlyContinue

if ($out -match 'PROBE:([^<"]*)') {
    "  " + $Matches[1]
} else {
    "  проба не отработала"
}