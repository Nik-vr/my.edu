<?php

declare(strict_types=1);

/**
 * Поиск мест, куда имя переменной попало не туда.
 *
 * Инструмент восстановления возвращал `$form` на место каждой `$`, за которой
 * не шёл буква или цифра. Так вернулись настоящие `$form`, но заодно и
 * одиночные `$` внутри регулярных выражений и строк: `/^[a-z]$/` стал
 * `/^[a-z]$form/`, и проверка молча перестала совпадать.
 *
 * Молча — самое плохое здесь: такой код синтаксически правилен, страница
 * открывается, а правильный ответ перестаёт сохраняться. Поэтому и нужен
 * отдельный проход: PHP такой код не считает ошибкой, а человек без прогона
 * проверок и не заметит.
 */

$root = dirname(__DIR__);

$files = [
    $root . '/plugins/test/src/Http/TestEditorController.php',
    $root . '/plugins/test/templates/_question_fields.php',
    $root . '/plugins/test/templates/_task_form.php',
];

$hits = 0;

foreach ($files as $file) {
    $lines = (string) file_get_contents($file);

    foreach (explode("\n", $lines) as $i => $line) {
        $trimmed = ltrim($line);

        /*
         * Настоящая переменная: $form с чем-то после неё или концом строки.
         * Всё, где после $form идёт знак, который может закрывать строку или
         * регулярное выражение, — подозрительно.
         */
        if (preg_match_all('/\$form(?![A-Za-z0-9_])/', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $after = $line[$hit[1] + strlen($hit[0])] ?? '';

                // Дальше пробел, точка, запятая, скобка — обычное обращение.
                if (in_array($after, [' ', '.', ',', ')', '[', '', ';', "\r"], true)) {
                    continue;
                }

                printf(
                    "  %s:%d  %s\n",
                    str_replace($root . '/', '', $file),
                    $i + 1,
                    trim($line)
                );
                $hits++;
            }
        }

        // Одиночная $ там, где её быть не должно: конец регулярки или строки.
        if (preg_match_all("/(?<![A-Za-z0-9_\\\\\$])\\\$(?![A-Za-z0-9_{])/", $trimmed, $m2, PREG_OFFSET_CAPTURE)) {
            $before = substr($trimmed, max(0, $m2[0][0][1] - 1), 1);

            if (in_array($before, ['/', "'", '"', '`', '-'], true)) {
                printf(
                    "  ! %s:%d  %s\n",
                    str_replace($root . '/', '', $file),
                    $i + 1,
                    trim($line)
                );
                $hits++;
            }
        }
    }
}

echo $hits === 0 ? "  подозрительных мест не найдено\n" : "  подозрительных мест: $hits\n";