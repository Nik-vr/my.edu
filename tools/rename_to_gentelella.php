<?php

declare(strict_types=1);

/**
 * Перевод шаблонов на классы сборки gentelella.
 *
 * Одноразовый инструмент: переименования между прежним словарём проекта и
 * словарём шаблона. Смысл сохраняется, поэтому карта составлена так, чтобы
 * замена была механической и её не пришлось бы проверять глазами в каждом
 * шаблоне.
 *
 * Чего карта не делает намеренно: не подбирает «похожий на вид» класс там, где
 * смысл разный. Значок статуса и метка — не одно и то же, и подменять их
 * друг другом значило бы потерять различие: в таблице зелёная точка читается
 * как «в порядке», а метка без точки — как просто слово.
 *
 * Пустой результат означает «класса в шаблоне нет, имя уходит». Так снят
 * прежний .inline-form: в шаблоне там просто форма с одной кнопкой, и никакой
 * особой раскладки не требуется.
 */

$root = dirname(__DIR__);

/**
 * Составные замены. Идут первыми, потому что снимают и базовое имя класса:
 * иначе `badge badge--ok` стал бы `badge status status-green`, и квадратный
 * значок остался бы висеть рядом с круглой точкой статуса — два знака одного
 * смысла рядом, что читается как «активно дважды».
 */
$composed = [
    'badge badge--ok'    => 'status status-green',
    'badge badge--good'  => 'status status-green',
    'badge badge--warn'  => 'status status-yellow',
    'badge badge--bad'   => 'status status-red',
    'badge badge--err'   => 'status status-red',
    'badge badge--info'  => 'status status-blue',
    'badge badge--muted' => 'chip',

    'alert alert--error' => 'banner banner-danger',
    'alert alert--bad'   => 'banner banner-danger',
    'alert alert--warn'  => 'banner banner-warning',
    'alert alert--ok'    => 'banner',
];

$map = [
    // Кнопки. Разделитель в шаблоне один, а не два: .btn--primary не существует.
    'btn--primary' => 'btn-primary',
    'btn--danger'  => 'btn-danger',
    'btn--warning' => 'btn-warning',
    'btn--warn'    => 'btn-warning',
    'btn--success' => 'btn-success',
    'btn--ok'      => 'btn-success',
    'btn--ghost'   => 'btn-ghost',
    'btn--quiet'   => 'btn-ghost',
    'btn--link'    => 'btn-ghost',
    'btn--lg'      => 'btn-lg',
    'btn--sm'      => 'btn-sm',
    'btn--icon'    => 'btn-icon',
    'btn--block'   => 'btn-block',

    // Карточка: шаблон называет части header / title / subtitle.
    'card__title' => 'card-title',
    'card__lead'  => 'card-subtitle',
    'card__head'  => 'card-header',
    'card__body'  => 'card-body',
    'card__foot'  => 'card-footer',

    // Поля формы. Прежнее .field — это .form-group шаблона.
    'field__label' => 'form-label',
    'field__hint'  => 'form-help',
    'field__error' => 'form-error',
    'field'        => 'form-group',

    // Таблица. Прокручивающаяся обёртка в шаблоне называется иначе.
    'table-wrap' => 'table-responsive',
    'data-table' => 'table',

    // Формы с одной кнопкой. Собственного класса «форма в строку» в шаблоне
    // нет, но .btn-group — это ровно он: display:inline-flex. Без него форма
    // становится блочным элементом и каждая кнопка действия уезжает на
    // отдельную строку, а колонка действий рассыпается.
    'inline-form' => 'btn-group',
    'form-inline' => 'btn-group',

    // Флажок с подписью. В шаблоне это .form-check, и он же красит квадратик.
    'role-check' => 'form-check',

    // Показатель: число и подпись под ним.
    'stat__value' => 'stat-value',
    'stat__label' => 'stat-label',

    // Заголовок страницы. Прежний .hero был самодельной полосой над содержимым.
    'hero__title' => 'page-title',
    'hero__text'  => 'muted',
    'hero'        => 'page-header',

    // Заголовок раздела внутри карточки: в шаблоне заголовок карточки один.
    'section-title' => 'card-title',

    // Ряд «слева подпись — справа значение». В шаблоне это .settings-row:
    // сетка из двух колонок с разделителем.
    //
    // Сам контейнер и значение остаются собственными классами: правила
    // .facts dt / .facts dd задают приглушённую подпись и жирное значение,
    // прижатое вправо, а .settings-row рассчитан на dt/dd внутри формы. Без
    // контейнера подпись и значение слипаются в одну строку.
    'facts__row'   => 'settings-row',
    'facts__label' => 'form-label',
    'facts__value' => 'facts__value',
    'facts'        => 'facts',

    // Ряд кнопок. Разметка совпадает с .page-actions, хотя класс называет
    // действия в шапке страницы: там flex, промежуток и перенос — то же самое.
    'actions-row' => 'page-actions',

    // Список отметок: у шаблона нет, но .row с колонками — его сетка, и без
    // обёртки флажки встают в одну колонку во всю ширину карточки. Своё
    // правило .check-list (auto-fill по 240px) в app.css остаётся в силе.
    'check-list' => 'check-list',

    // Крошки — те же, что в шаблоне.
    'breadcrumbs' => 'breadcrumb',

    // Форма без оформления полей: .form-group на полях достаточно. Собственное
    // правило .form задавало только ширину 460px, а на страницах панели её
    // задаёт сетка .settings-row и колонки шаблона.
    'form' => '',

    // Карточка по центру: класс не используется ни на одной странице панели —
    // ширину там задаёт сетка шаблона. Правило .card--centered в app.css
    // удалено как мёртвое.
    'card--centered' => '',
];

$files = [];

foreach (['templates', 'plugins'] as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

$touched = 0;
$replacements = 0;

foreach ($files as $file) {
    $out = (string) file_get_contents($file);
    $src = $out;

    foreach ($composed as $from => $to) {
        if (str_contains($out, $from)) {
            $replacements += substr_count($out, $from);
            $out = str_replace($from, $to, $out);
        }
    }

    foreach ($map as $from => $to) {
        if (!str_contains($out, $from)) {
            continue;
        }

        $count = 0;

        /*
         * Классы ищутся только внутри атрибута class="…".
         *
         * Поиск по всему файлу бьёт по коду: класс `form` встречается и как
         * `$form`, и как ключ `'form' =>`, и такая замена ломает файл — причём
         * тихо, ошибкой в синтаксисе на следующей же строке. Ограничение
         * атрибутом делает замену безопасной: вне class="…" классов нет по
         * построению.
         *
         * Классы, которые в карте ведут в пустую строку, уходят вместе с
         * атрибутом, а не остаются в виде class="". Пустой атрибут виден в
         * разметке и выглядит как недописанный класс: по нему нельзя понять,
         * что здесь ничего не должно быть.
         */
        $out = preg_replace_callback(
            '/ ?class="([^"]*)"/',
            static function (array $hit) use ($map): string {
                $classes = preg_split('/\s+/', trim($hit[1]));
                $out = [];

                foreach ($classes as $class) {
                    if ($class !== '') {
                        $out[] = $map[$class] ?? $class;
                    }
                }

                /*
                 * Пробел перед атрибутом съеден самой регуляркой (` ?class=`),
                 * поэтому возвращать его надо здесь. Без этого `<span class=…>`
                 * склеивается в `<spanclass=…`, и страница перестаёт быть
                 * размеченной вовсе, а не просто неоформленной.
                 */
                return $out === []
                    ? ''
                    : ' class="' . implode(' ', $out) . '"';
            },
            $out
        );
        $count = preg_match_all('/(?<![\w-])' . preg_quote($from, '/') . '(?![\w-])/', $src);
    }

    if ($out !== $src) {
        file_put_contents($file, $out);
        $touched++;
        echo '  ' . str_replace($root . '/', '', $file) . "\n";
    }
}

/*
 * Что осталось: классы, которых в сборке шаблона нет. Список нужен, чтобы
 * понимать объём ручной работы, а не чтобы импортёр всё исправлял молча.
 */
$left = [];

foreach ($files as $file) {
    $src = (string) file_get_contents($file);

    if (preg_match_all('/class="([^"$<]*)"/', $src, $m)) {
        foreach ($m[1] as $attr) {
            foreach (preg_split('/\s+/', trim($attr)) as $c) {
                if ($c === '' || str_contains($c, '$') || str_contains($c, "'")) {
                    continue;
                }

                $left[$c] = ($left[$c] ?? 0) + 1;
            }
        }
    }
}

arsort($left);
$rest = [];

foreach ($left as $c => $n) {
    if ($n >= 2) {
        $rest[] = sprintf('  %-28s %d', $c, $n);
    }
}

echo "\n  файлов изменено: $touched, замен: $replacements\n";
echo '  осталось классов (по 2 и более): ' . count($rest) . "\n";
echo implode("\n", array_slice($rest, 0, 40)) . "\n";