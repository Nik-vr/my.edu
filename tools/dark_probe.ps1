# Проверяет читаемость оформления в обеих темах и снимает страницы.
#
# Зачем это браузером, а не разбором CSS: контраст считается по итоговым
# цветам, которые рисует экран. Правило может задать цвет, а каскад — перебить
# его; страница может лежать на своей подложке, а не на общей. По исходному
# коду это не видно, и проверка «в токенах темы цвет тёмный» ничего не говорит
# о том, что человек на самом деле видит.
#
# Проверяются те места, которые волна 1 назвала тёмными:
#   V1  — заголовок карточки работы на /works
#   V4  — кнопки пагинации таблицы
#   V10 — заголовок группы работ в списке
#   V13 — бледные подписи в списке людей
#
# Порог — 4.5:1, тот же, что и в наборе правил интерфейса: это минимум для
# читаемого текста, и ниже него подпись не подпись.
#
# ЗАПУСКАТЬ ТОЛЬКО НА ТЕСТОВОЙ БАЗЕ: страница входит в оболочку без пароля,
# временный вход создаёт tools\shot_login.ps1, и он тоже отказывается работать
# не с тестовой базой.
#
# Запуск:
#   powershell -File tools\dark_probe.ps1
param(
    [string]$Base = "http://127.0.0.1:8098",
    [string]$Out = "var/dark-probe.txt",
    [string]$Shots = "var/shots",
    [double]$Min = 4.5
)

$OutputEncoding = [Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$base = $Base.TrimEnd('/')
$login = Join-Path (Get-Location) "public\_shot_tmp.php"
$page = Join-Path (Get-Location) "public\_probe_page.html"
$script = Join-Path (Get-Location) "public\_probe.js"

if (-not (Test-Path $login)) {
    "  нет временного входа: public/_shot_tmp.php (запусти tools\shot_login.ps1)"
    exit 1
}

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"

if (-not (Test-Path $edge)) {
    "  не найден браузер: $edge"
    exit 1
}

# Что проверяем: страница, селектор, название пункта ревью.
$targets = @(
    @{ Path = "/works"; Selector = ".test-card__title"; What = "V1 заголовок карточки работы" },
    @{ Path = "/panel/users"; Selector = ".dt-paging-button"; What = "V4 кнопка пагинации" },
    @{ Path = "/panel/assignments"; Selector = ".work-folder__title"; What = "V10 заголовок группы работ" },
    @{ Path = "/panel/users"; Selector = ".cell-muted"; What = "V13 подпись в списке людей" }
)

$themes = @('light', 'dark')

# Внутри скрипта нет ни одного русского слова: файл кладётся в public/ и
# читается браузером как обычный .js, а метки порядка байтов у него нет.
$probeJs = @'
window.addEventListener('load', function () {
  var out = document.createElement('pre');
  out.id = 'probe';

  function parse(color) {
    var m = /rgba?\(([^)]+)\)/.exec(color || '');

    if (!m) {
      return null;
    }

    var parts = m[1].split(',').map(function (p) { return parseFloat(p); });

    return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
  }

  function over(fg, bg) {
    var a = fg.a;

    return {
      r: fg.r * a + bg.r * (1 - a),
      g: fg.g * a + bg.g * (1 - a),
      b: fg.b * a + bg.b * (1 - a),
      a: 1
    };
  }

  function lum(c) {
    var f = function (v) {
      v = v / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };

    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  }

  function ratio(a, b) {
    var l1 = lum(a);
    var l2 = lum(b);

    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  }

  /* Подложка ищется вверх по дереву: непрозрачный цвет ближайшего предка. */
  function backgroundOf(el) {
    var node = el;

    while (node && node.nodeType === 1) {
      var bg = parse(getComputedStyle(node).backgroundColor);

      if (bg && bg.a > 0) {
        return bg;
      }

      node = node.parentElement;
    }

    return { r: 255, g: 255, b: 255, a: 1 };
  }

  var el = document.querySelector(window.__probeSelector);
  var theme = window.__probeTheme;

  document.documentElement.setAttribute('data-theme', theme);

  if (!el) {
    out.textContent = 'PROBE:not-found';
    document.body.appendChild(out);
    return;
  }

  /*
   * Кто именно задаёт тему виденному куску страницы: если тёмная тема
   * досталась подписи из вложенного контейнера, а не от корня, то и цвет у
   * неё тёмный — и в светлой теме это ровно тот случай, когда «правило
   * изменилось, а выглядит так же».
   */
  var chain = [];
  var node = el;

  while (node && node.nodeType === 1) {
    var attr = node.getAttribute ? node.getAttribute('data-theme') : null;

    if (attr) {
      chain.push((node.tagName || '?') + '[' + attr + ']');
    }

    node = node.parentElement;
  }

  var cs = getComputedStyle(el);
  var fg = parse(cs.color);
  var bg = backgroundOf(el);

  if (!fg) {
    out.textContent = 'PROBE:no-color';
    document.body.appendChild(out);
    return;
  }

  var shown = over(fg, bg);
  var r = ratio(shown, bg);

  /*
   * Какое правило рисует этот цвет: иначе «не совпало с ожиданием» остаётся
   * загадкой — тёмный цвет в светлой теме означает, что правило выбрано не то,
   * и переписывать придётся не ту строку.
   *
   * Показывается всё, что подходит элементу, вместе с важностью: `!important`
   * побеждает не по порядку, а по важности, и без неё в списке не видно, кто
   * именно рисует.
   */
  var rules = [];
  var sheets = document.styleSheets;

  for (var s = 0; s < sheets.length; s++) {
    var list;

    try {
      list = sheets[s].cssRules;
    } catch (e) {
      continue;
    }

    for (var ri = 0; ri < list.length; ri++) {
      var rule = list[ri];

      if (!rule.selectorText || !rule.style) {
        continue;
      }

      var color = rule.style.getPropertyValue('color');

      if (!color) {
        continue;
      }

      try {
        if (el.matches(rule.selectorText)) {
          rules.push(rule.selectorText + '{' + color + '}'
            + (rule.style.getPropertyPriority('color') === 'important' ? '!important' : ''));
        }
      } catch (e) {
        // Селектор не разобрался — пропускаем.
      }
    }
  }

  /* Цвет родителя: если он тёмный, а свой у кнопки — тёмный, то красит родитель. */
  var parentColor = '';
  var parentTag = '';

  if (el.parentElement) {
    parentColor = getComputedStyle(el.parentElement).color;
    parentTag = el.parentElement.tagName + '.' + (el.parentElement.className || '');
  }

  out.textContent = 'PROBE:contrast=' + r.toFixed(2)
    + ';fg=' + cs.color
    + ';bg=' + 'rgb(' + Math.round(bg.r) + ', ' + Math.round(bg.g) + ', ' + Math.round(bg.b) + ')'
    + ';theme=' + theme
    + ';attr=' + document.documentElement.getAttribute('data-theme')
    + ';cls=' + (el.className || '-')
    + ';muted=' + getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim()
    + ';own=' + getComputedStyle(el).getPropertyValue('--text-muted').trim()
    + ';chain=' + (chain.length ? chain.join('<') : '-')
    + ';rules=' + (rules.length ? rules.join(' ') : '-')
    + ';parent=' + parentTag + ':' + parentColor
    + ';vars=' + ['--dt-paging-button_color-disabled', '--text-disabled', '--text-secondary', '--text-muted']
        .map(function (v) { return v + '=' + getComputedStyle(el).getPropertyValue(v).trim(); }).join(',')
    + ';size=' + cs.fontSize;

  document.body.appendChild(out);
});
'@

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri "$base/_shot_tmp.php?path=/panel/users" -WebSession $session -UseBasicParsing

# Один профиль браузера на весь прогон.
#
# Временный вход кладёт сессию в куки браузера, и копия страницы открывается
# под тем же профилем — с сессией. Без неё таблица подтягивает строки
# запросом к серверу, не получает их и остаётся пустой: пустой таблице не на
# чем показать пагинацию, и проба молча ничего не находит.
#
# Свежий профиль заодно снимает кэш стилей: иначе проба меряла бы старую
# вёрстку и выглядело бы так, будто правило не сработало.
$profile = Join-Path $env:TEMP ("myedu-dark-probe-" + [guid]::NewGuid().ToString("N").Substring(0, 8))

$results = @()
$failed = $false

foreach ($target in $targets) {
    foreach ($theme in $themes) {
        $path = [string]$target.Path
        $selector = [string]$target.Selector
        $what = [string]$target.What

        try {
            $html = (Invoke-WebRequest -Uri "$base$path" -WebSession $session -UseBasicParsing).Content
        } catch {
            $results += [pscustomobject]@{ What = $what; Theme = $theme; Ratio = 0; State = 'страница не открылась' }
            $failed = $true
            continue
        }

        [System.IO.File]::WriteAllText($script, $probeJs.Replace('window.__probeSelector', "'" + $selector.Replace("'", "\'") + "'").Replace('window.__probeTheme', "'" + $theme + "'"))
        $html = $html.Replace('</body>', '<script src="/_probe.js"></script></body>')
        [System.IO.File]::WriteAllText($page, $html)

        # Тема и сессия — через временный вход в том же профиле браузера.
        #
        # Сессия нужна самой таблице: библиотека подтягивает строки запросом к
        # серверу, и без сессии таблица остаётся пустой, а пустая таблица не
        # имеет кнопок пагинации. Тема ставится тем же входом: он пишет выбор в
        # хранилище браузера и уходит на нужный адрес.
        $null = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=6000 `
            --user-data-dir="$profile" `
            --dump-dom "$base/_shot_tmp.php?path=$([uri]::EscapeDataString($path))&theme=$theme" 2>$null | Out-String

        $dom = & $edge --headless=new --disable-gpu --no-sandbox --virtual-time-budget=10000 `
            --user-data-dir="$profile" `
            --dump-dom "$base/_probe_page.html" 2>$null | Out-String

        if ($dom -match 'PROBE:([^<"]*)') {
            $text = $Matches[1]

            if ($text -match 'contrast=([0-9.]+)') {
                $value = [double]$Matches[1]
                $state = if ($value -ge $Min) { 'ok' } else { 'ниже порога' }

                if ($value -lt $Min) {
                    $failed = $true
                }

                # Цвета показываются рядом с числом: само число не говорит, в какой
                # теме мерили, а тема задаётся скриптом, и ошибка в нём выглядела
                # бы как «всё хорошо» — просто потому, что чёрное на белом читается.
                $colors = ''

                if ($text -match 'fg=([^;]+);bg=([^;]+);') {
                    $colors = $Matches[1] + ' на ' + $Matches[2]
                }

                $label = $state

                if ($colors -ne '') {
                    $label = $state + " ($colors)"
                }

                # Полная строка пробы идёт в отчёт: по ней видно, чем именно
                # нарисована страница, — цветом, рамкой или отсутствием.
                $label = $label + ' [' + $text + ']'

                $results += [pscustomobject]@{
                    What = $what
                    Theme = $theme
                    Ratio = $value
                    State = $label
                }
            } else {
                $results += [pscustomobject]@{ What = $what; Theme = $theme; Ratio = 0; State = $text }
            }
        } else {
            $results += [pscustomobject]@{ What = $what; Theme = $theme; Ratio = 0; State = 'проба не отработала' }
            $failed = $true
        }
    }
}

# Снимки: те же страницы в обеих темах, чтобы результат можно было посмотреть
# глазами, а не только по числу.
$shotsDir = Join-Path (Get-Location) $Shots

if (-not (Test-Path $shotsDir)) {
    New-Item -ItemType Directory -Path $shotsDir -Force | Out-Null
}

$shotPages = @('/works', '/panel/users', '/panel/assignments')

foreach ($path in $shotPages) {
    foreach ($theme in $themes) {
        $name = ($path.Trim('/') -replace '/', '-') + '-' + $theme + '.png'
        $shot = Join-Path $shotsDir $name

        & $edge --headless=new --disable-gpu --no-sandbox --hide-scrollbars `
            --window-size=1560,1000 --virtual-time-budget=8000 `
            --screenshot="$shot" "$base/_shot_tmp.php?path=$([uri]::EscapeDataString($path))&theme=$theme" 2>&1 | Out-Null
    }
}

Remove-Item $page, $script -Force -ErrorAction SilentlyContinue
Remove-Item $profile -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item $login -Force -ErrorAction SilentlyContinue

$lines = @()

foreach ($row in $results) {
    $line = "{0,-34} {1,-6} {2,6}:1  {3}" -f $row.What, $row.Theme, $row.Ratio, $row.State
    $lines += $line
    $line
}

if ($Out) {
    [System.IO.File]::WriteAllText(
        (Join-Path (Get-Location) $Out),
        ($lines -join "`n"),
        (New-Object System.Text.UTF8Encoding($false))
    )
}

"  снимки: $Shots"

if ($failed) {
    "  есть места, где контраст ниже $Min"
    exit 1
}

exit 0
