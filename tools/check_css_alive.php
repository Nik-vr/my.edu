<?php

declare(strict_types=1);

/**
 * Какие правила app.css всё ещё на что-то влияют.
 *
 * app.css подключается после сборки шаблона, поэтому любое правило, класс
 * которого ещё встречается в разметке, перебивает стиль шаблона. Польза от
 * такого правила нулевая: перевод на классы шаблона как раз убирал эти имена,
 * и правило осталось впереди только потому, что файл не трогали.
 *
 * Правила без совпадений — мёртвые: они занимают место в ответе и вводят в
 * заблуждение того, кто будет читать стили дальше.
 */

$root = dirname(__DIR__);

$css = (string) file_get_contents($root . '/public/assets/css/app.css');

/*
 * Только селекторы перед блоком правил: @media и @keyframes в середине файла
 * разбивают разбор на части, и класс из одной части не должен считаться
 * найденным по селектору из другой.
 */
$css = (string) preg_replace('#@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}#s', '', $css);

preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $css, $m, PREG_SET_ORDER);

$used = [];

foreach (['templates', 'plugins'] as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

    foreach ($rii as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }

        $src = (string) file_get_contents($f->getPathname());

        if (preg_match_all('/class="([^"$<]*)"/', $src, $mm)) {
            foreach ($mm[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) as $c) {
                    if ($c !== '' && !str_contains($c, '$') && !str_contains($c, "'")) {
                        $used[$c] = true;
                    }
                }
            }
        }
    }
}

$alive = [];

foreach ($m as $hit) {
    foreach (explode(',', $hit[2]) as $selector) {
        $selector = trim($selector);

        if ($selector === '' || str_contains($selector, '{')) {
            continue;
        }

        if (preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $selector, $cls)) {
            foreach ($cls[1] as $c) {
                if (isset($used[$c])) {
                    $alive[$selector] = true;

                    break;
                }
            }
        }
    }
}

printf("  селекторов всего: %d\n", count($m));
printf("  всё ещё на что-то влияют: %d\n", count($alive));

foreach (array_slice(array_keys($alive), 0, 40) as $s) {
    echo '  ' . $s . "\n";
}