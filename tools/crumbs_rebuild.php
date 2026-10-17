<?php

declare(strict_types=1);

/**
 * Пересобирает объявление хлебных крошек в шаблонах панели.
 *
 * Блок не правится на месте, а собирается заново: прежние попытки редактировали
 * его по кускам и получили все возможные сочетания — потерянный элемент,
 * потерянный адрес, закрывающий тег не там. Данные лежат в tools/crumbs.tsv,
 * чтобы выражения PHP с кавычками писались дословно.
 *
 * Заодно убирается закрывающий тег между докблоком и страницей: он переводит
 * шаблон в режим вывода, и код пролога ниже печатается текстом, а не
 * выполняется — страница отдаётся пустой.
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;

foreach (file($root . '/tools/crumbs.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
    $cols = explode("\t", $row);
    $name = $cols[0];
    $path = $root . '/templates/panel/' . $name;

    if (!is_file($path)) {
        echo '  НЕТ ФАЙЛА: ' . $name . "\n";

        continue;
    }

    $raw = (string) file_get_contents($path);
    $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
    $lines = explode($eol, $raw);

    // Проход 1: убрать прежний блок целиком.
    for ($i = 0; $i < count($lines); $i++) {
        if (!str_contains($lines[$i], '$view->crumbs(')) {
            continue;
        }

        $end = $i;

        for ($k = $i; $k < count($lines); $k++) {
            if (str_contains($lines[$k], ']);')) {
                $end = $k;
                break;
            }
        }

        $lines = array_merge(
            array_slice($lines, 0, $i),
            array_slice($lines, $end + 1)
        );

        break;
    }

    // Опора: конец вводного докблока.
    $docEnd = null;

    foreach ($lines as $i => $line) {
        if (trim($line) === '*/') {
            $docEnd = $i;
            break;
        }
    }

    if ($docEnd === null) {
        echo '  НЕТ ДОКБЛОКА: ' . $name . "\n";

        continue;
    }

    // Проход 2: пролог без закрывающих тегов, до первой строки разметки.
    $clean = array_slice($lines, 0, $docEnd + 1);
    $pageStart = null;

    for ($i = $docEnd + 1; $i < count($lines); $i++) {
        $trimmed = ltrim($lines[$i]);

        if (str_starts_with($trimmed, '<') && !str_starts_with($trimmed, '<?')) {
            $pageStart = $i;
            break;
        }

        if (trim($lines[$i]) === '?>') {
            continue;
        }

        $clean[] = $lines[$i];
    }

    if ($pageStart === null) {
        echo '  НЕТ СТРАНИЦЫ: ' . $name . "\n";

        continue;
    }

    // Проход 3: собрать блок.
    $block = ['$view->crumbs(['];

    for ($c = 1; $c < count($cols); $c += 2) {
        $line = '        [\'label\' => ' . $cols[$c];

        if (($cols[$c + 1] ?? '') !== '') {
            $line .= ', \'url\' => ' . $cols[$c + 1];
        }

        $block[] = $line . '],';
    }

    $block[] = '    ]);';
    $block[] = '?>';
    $block[] = '';

    $out = array_merge($clean, $block, array_slice($lines, $pageStart));

    file_put_contents($path, implode($eol, $out));

    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out2, $code);

    echo '  ' . $name . ' :: ' . trim(($code === 0 ? 'ok' : implode(' ', $out2))) . "\n";
    $out2 = [];
}