<?php

declare(strict_types=1);

/**
 * Поиск тегов PHP, которые попали в разметку как обычный текст.
 *
 * Ошибка выглядит так: строка с `<?= ... ?>` закрывает PHP, а через несколько
 * строк стоит ещё один `?>`. Второй закрывающий тег печатается на странице
 * буквально: на странице плагинов так выводилось «Установка: положить папку с
 * плагином в каталог plugins/ ?> и включить его здесь».
 *
 * PHP такой код не считает ошибкой, а страница открывается, поэтому и нужен
 * отдельный проход. Ищется не текст `?>` во всём файле — он там законен
 * сотни раз, — а только такой, что оказался в кусках, которые PHP считает
 * обычным текстом: их показывает token_get_all().
 *
 * Запуск: php tools/check_stray_tags.php
 */

$root = dirname(__DIR__);

$targets = [];

foreach ([
    $root . '/templates',
    $root . '/plugins',
] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $targets[] = $file->getPathname();
        }
    }
}

sort($targets);

$hits = 0;

foreach ($targets as $file) {
    $code = (string) file_get_contents($file);

    /*
     * Ищется не текст `?>` во всём файле — он там законен сотни раз, — а
     * только такой, что оказался в кусках, которые PHP считает обычным текстом
     * шаблона: их показывает token_get_all() отдельными токенами T_INLINE_HTML.
     *
     * Тег PHP не может лежать в разметке законно: чтобы он там появился, его
     * пришлось бы закрыть, а потом закрыть ещё раз. Второе закрытие и есть
     * ошибка.
     */
    foreach (token_get_all($code) as $token) {
        if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
            continue;
        }

        $text = $token[1];

        if (!preg_match('#<\?php|<\?=|\?>#', $text, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $hits++;

        $line = (int) $token[2] + substr_count(substr($text, 0, $match[0][1]), "\n");

        // После тега показывается, что шло в разметке: по этому сразу видно,
        // тег лишний или его кто-то специально закрыл дважды.
        $tail = trim(preg_replace('/\s+/', ' ', substr($text, $match[0][1] + strlen($match[0][0]), 48)));
        $snippet = $tail === '' ? 'ничего, конец разметки' : $tail;

        printf(
            "  %s:%d  тег %s в разметке, дальше: %s\n",
            str_replace($root . '/', '', $file),
            $line,
            $match[0][0],
            $snippet
        );
    }
}

echo $hits === 0
    ? "Тегов PHP в разметке нет.\n"
    : "Подозрительных мест: {$hits}.\n";

exit($hits === 0 ? 0 : 1);