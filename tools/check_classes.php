<?php

declare(strict_types=1);

/**
 * Проверка: какие классы разметки отсутствуют в сборке шаблона.
 *
 * После перевода на gentelella в шаблонах не должно остаться имён, которых
 * нет в сборке. Каждое такое имя — это либо забытый перевод, либо класс,
 * о котором шаблон не знает и который нужно либо найти в нём под другим
 * именем, либо добавить в собственный слой с объяснением, почему.
 *
 * Молча пропустить их нельзя: страница с таким классом выглядит как страница
 * без оформления, и заметить это можно только глазами.
 */

$root = dirname(__DIR__);

// Читаются все таблицы стилей приложения, а не только сборка шаблона и тема.
// app.css подключается первым и несёт блоки предметной области (карточки
// работ, списки вопросов, поля редактора); раньше он здесь не читался, и
// инструмент называл без стилей всё, что в нём нарисовано.
// print-credentials.css тоже учитывается: страница листа для печати
// подключает его вместо общей темы, и её блоки иначе выглядели бы без оформления.
$css = '';

foreach ([
    '/public/assets/css/app.css',
    '/public/assets/css/theme.css',
    '/public/assets/css/print-credentials.css',
    '/public/assets/vendor/gentelella/gentelella.css',
    '/public/assets/vendor/datatables/dataTables.min.css',
] as $sheet) {
    $css .= (string) file_get_contents($root . $sheet);
}

$used = [];

foreach (['templates', 'plugins'] as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

    foreach ($rii as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }

        $src = (string) file_get_contents($f->getPathname());
        $file = str_replace($root . '/', '', $f->getPathname());

        // Классы выписываются по одному: class="a b" и class="a" — одно и то же.
        if (preg_match_all('/class="([^"$<]*)"/', $src, $m)) {
            foreach ($m[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) as $c) {
                    if ($c === '' || str_contains($c, '$') || str_contains($c, "'")) {
                        continue;
                    }

                    $used[$c][$file] = ($used[$c][$file] ?? 0) + 1;
                }
            }
        }
    }
}

$missing = [];

foreach ($used as $class => $where) {
    if (!str_contains($css, '.' . $class)) {
        $missing[$class] = $where;
    }
}

arsort($missing);

$total = 0;

foreach ($missing as $class => $where) {
    $n = array_sum($where);
    $total += $n;
    printf("  %-26s %3d  %s\n", $class, $n, implode(', ', array_slice(array_keys($where), 0, 2)));
}

echo "\n  классов без стилей: " . count($missing) . ", вхождений: $total\n";