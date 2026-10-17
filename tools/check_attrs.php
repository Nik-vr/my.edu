<?php

declare(strict_types=1);

/**
 * Поиск атрибутов, потерявших имя.
 *
 * При переводе на классы шаблона имя `form` совпало с тегом `<form>` и с
 * одноимённым атрибутом `form="…"` — привязкой поля к форме по идентификатору.
 * Замена задела и то и другое: `form="roster-pick"` стал `="roster-pick"`.
 *
 * На вид такой атрибут неотличим от обычного: страница открывается, форма
 * отправляется, а привязка молча пропала — то есть кнопка отправляет сама
 * себя, без отметок. Поэтому ищется отдельно, а не глазами по разметке.
 */

$root = dirname(__DIR__);

$files = [];

foreach (['templates', 'plugins'] as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));

    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

$hits = 0;

foreach ($files as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
            continue;
        }

        /*
         * Равенство без имени слева — но только в разметке и только перед
         * кавычкой. В PHP `$x = 'значение'` — это присваивание, и искать его
         * здесь незачем: проверка должна смотреть на HTML, иначе она находит
         * тысячу строк кода вместо нескольких испорченных атрибутов.
         */
        if (str_contains($line, '<') && preg_match('/(^|[\s>])="(?!\s*$)/', $line)) {
            printf(
                "  %s:%d  %s\n",
                str_replace($root . '/', '', $file),
                $i + 1,
                $trimmed
            );
            $hits++;
        }
    }
}

echo $hits === 0 ? "  потерянных имён атрибутов не найдено\n" : "  потерянных имён атрибутов: $hits\n";