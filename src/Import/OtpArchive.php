<?php

declare(strict_types=1);

namespace MyEdu\Import;

use MyEdu\Transfer\TransferException;
use MyEdu\Transfer\Zip;

/**
 * Чтение офлайн-выгрузки OnlineTestPad.
 *
 * В архиве лежит один нужный файл — `test.js`, и это весь тест целиком в виде
 * JSON: `var test = {...};`. Разбирается именно он.
 *
 * Почему не PDF: в PDF правильный ответ закодирован глифами встроенного
 * икон-шрифта (`\ue996`/`\ue997` у переключателя, `\ue981`/`\ue980` у
 * флажка) и цветом текста, а принадлежность отметки варианту восстанавливается
 * только по координатам. Это не данные, а вид вёрстки: смена сборки сдвинет
 * коды и разбор молча перестанет видеть правильные ответы, оставив внешне
 * целую страницу. В test.js те же ответы лежат числами в поле `Score`.
 *
 * Две ловушки самого файла, из-за которых наивный разбор не работает:
 *
 * - в начале `test.js` стоит BOM, а json_decode его не терпит;
 * - после объекта идёт `var testCss = "";` — простое «взять от первой `{` до
 *   конца файла» даёт синтаксическую ошибку.
 */
final class OtpArchive
{
    /** Имя файла с тестом. Может лежать в подкаталоге — ищем по концу пути. */
    private const TEST_FILE = 'test.js';

    /**
     * Разобрать архив и вернуть тест как массив.
     *
     * @return array<string,mixed>
     *
     * @throws ImportException
     */
    public static function read(string $path): array
    {
        $files = self::openArchive($path);
        $source = self::findTestFile($files);
        $test = self::decode($source);

        if (!isset($test['Questions']) || !is_array($test['Questions'])) {
            throw new ImportException(
                'В файле нет списка заданий: это, похоже, не офлайн-версия теста OnlineTestPad.'
            );
        }

        return $test;
    }

    /**
     * Проверить, что приложенный файл похож на выгрузку.
     *
     * PDF отвергается здесь же и словами о том, какой файл нужен: человек
     * скачивает тест в двух форматах, и молчаливый отказ по расширению заставил
     * бы его гадать.
     *
     * Имя, а не путь: расширение приходится проверять у присланного имени
     * файла, потому что временный файл называет сам PHP и расширения не имеет.
     * Пустое имя проверку не проходит — тогда решение принимает разбор архива,
     * который смотрит на содержимое, а не на ярлык.
     *
     * @return string|null причина отказа; null — по виду годится
     */
    public static function refuseIfForeign(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $name = strtolower(basename($name));

        if (str_ends_with($name, '.pdf')) {
            return 'PDF не разбирается: в нём правильные ответы отмечены значками вёрстки, '
                . 'а не записаны данными. Скачайте тест в OnlineTestPad как офлайн-версию '
                . '(кнопка «Скачать тест» → .zip) и приложите этот файл.';
        }

        if (!str_ends_with($name, '.zip')) {
            return 'Нужен файл офлайн-версии теста OnlineTestPad с расширением .zip.';
        }

        return null;
    }

    /**
     * @return array<string,string>
     *
     * @throws ImportException
     */
    private static function openArchive(string $path): array
    {
        try {
            return Zip::read($path);
        } catch (TransferException $e) {
            // Свой разбор zip нужен для чужих архивов ровно так же, как для
            // наших, но его сообщения написаны про перенос работ.
            throw new ImportException('Файл не распаковывается: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,string> $files
     *
     * @throws ImportException
     */
    private static function findTestFile(array $files): string
    {
        foreach ($files as $name => $content) {
            $name = strtolower(str_replace('\\', '/', $name));

            // Файл лежит либо в корне архива, либо в подкаталоге — в зависимости
            // от того, чем именно скачивали тест.
            if ($name === self::TEST_FILE || str_ends_with($name, '/' . self::TEST_FILE)) {
                return $content;
            }
        }

        throw new ImportException(
            'В архиве нет файла ' . self::TEST_FILE . ' — значит, это не офлайн-версия теста. '
            . 'Нужен архив, который OnlineTestPad собирает при скачивании теста.'
        );
    }

    /**
     * Достать JSON-объект теста из обёртки `var test = …; var testCss = "";`.
     *
     * @return array<string,mixed>
     *
     * @throws ImportException
     */
    private static function decode(string $source): array
    {
        $source = self::withoutBom($source);
        $start = strpos($source, '{');

        if ($start === false) {
            throw new ImportException('Файл ' . self::TEST_FILE . ' не содержит описания теста.');
        }

        $end = self::lastBrace($source, $start);

        if ($end === null) {
            throw new ImportException('Файл ' . self::TEST_FILE . ' обрывается посреди описания теста.');
        }

        $decoded = json_decode(substr($source, $start, $end - $start + 1), true);

        if (!is_array($decoded)) {
            throw new ImportException(
                'Не удалось прочитать описание теста: ' . json_last_error_msg() . '.'
            );
        }

        return $decoded;
    }

    private static function withoutBom(string $source): string
    {
        return str_starts_with($source, "\xEF\xBB\xBF") ? substr($source, 3) : $source;
    }

    /**
     * Позиция закрывающей скобки объекта.
     *
     * Считается со счётом глубины, а не через strrpos: закрывающая скобка может
     * встретиться внутри строки (например, в тексте вопроса или в css), и тогда
     * последняя скобка файла указала бы за пределы теста — на `var testCss`.
     */
    private static function lastBrace(string $source, int $start): ?int
    {
        $depth = 0;
        $length = strlen($source);
        $inString = false;
        $escaped = false;

        for ($at = $start; $at < $length; $at++) {
            $char = $source[$at];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $at;
            }
        }

        return null;
    }
}