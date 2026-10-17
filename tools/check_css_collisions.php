<?php

declare(strict_types=1);

/**
 * Правила app.css, которые перебивают стиль шаблона.
 *
 * app.css подключается после сборки gentelella, и правило с тем же именем
 * класса, что у шаблона, выигрывает — то есть побеждает не шаблон, а прежние
 * стили проекта. Это молчаливый возврат к старому виду: класс в разметке
 * шаблонный, а выглядит всё равно не как в шаблоне.
 *
 * Правило с уникальным именем класса — просто мёртвый код, он ничего не
 * перебивает и вычищается отдельно. Этот список — только опасная половина.
 */

$root = dirname(__DIR__);

$ours = (string) file_get_contents($root . '/public/assets/css/app.css');
$theirs = (string) file_get_contents($root . '/public/assets/vendor/gentelella/gentelella.css');

$theirsClasses = [];

if (preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $theirs, $m)) {
    foreach ($m[1] as $c) {
        $theirsClasses[$c] = true;
    }
}

$clean = (string) preg_replace('#@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}#s', '', $ours);
preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $clean, $blocks, PREG_SET_ORDER);

$collisions = [];

foreach ($blocks as $hit) {
    foreach (explode(',', $hit[2]) as $selector) {
        $selector = trim($selector);

        if ($selector === '' || str_contains($selector, '{')) {
            continue;
        }

        preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $selector, $cls);

        foreach ($cls[1] as $c) {
            if (isset($theirsClasses[$c])) {
                $collisions[$selector] = $c;

                break;
            }
        }
    }
}

echo '  селекторов app.css, перебивающих шаблон: ' . count($collisions) . "\n";

foreach ($collisions as $selector => $class) {
    printf("  %-42s → .%s\n", str_replace("\n", ' ', $selector), $class);
}