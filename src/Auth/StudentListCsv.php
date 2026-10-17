<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Список учеников класса в табличном формате.
 *
 * Формат фиксированный: три колонки — Фамилия; Имя; Отчество.
 *
 * Разделитель определяется автоматически: русский Excel по умолчанию пишет
 * через точку с запятой, многие сохраняют через запятую. Отдельно снимается
 * BOM — без этого Excel отдаёт первую фамилию с невидимым символом в начале,
 * и ученик потом не сможет войти под своим логином.
 */
final class StudentListCsv
{
    public const DELIMITER = ';';

    public const HEADERS = ['Фамилия', 'Имя', 'Отчество'];

    /**
     * Разбирает содержимое файла.
     *
     * @return array{rows:list<array{family:string,name:string,middle:string,line:int}>,
     *               errors:list<array{line:int,message:string}>}
     */
    public function parse(string $content): array
    {
        $content = $this->stripBom($content);
        $delimiter = $this->detectDelimiter($content);

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $headerLine = null;

        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $headerLine = $index;
                break;
            }
        }

        $rows = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            // Номер остаётся номером физической строки даже при пустых строках
            // и строке заголовка.
            $lineNumber = $index + 1;

            // Значение escape задаётся явно: иначе PHP 8.4 пишет
            // предупреждение, которое попадает прямо в тело ответа и ломает
            // редирект после импорта.
            $cells = str_getcsv($line, $delimiter, '"', '\\');

            if ($cells === [null]) {
                continue;
            }

            $cells = array_map(
                fn (?string $cell): string => trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $cell)),
                $cells
            );

            // Строка заголовка: пропускаем без проверки содержимого.
            if ($index === $headerLine && $this->looksLikeHeader($cells)) {
                continue;
            }

            $family = $cells[0] ?? '';
            $name = $cells[1] ?? '';
            $middle = $cells[2] ?? '';

            if ($family === '' && $name === '') {
                $errors[] = ['line' => $lineNumber, 'message' => 'пустая строка'];

                continue;
            }

            $valid = true;

            if ($family === '') {
                $errors[] = ['line' => $lineNumber, 'message' => 'не указана фамилия'];
                $valid = false;
            }

            if ($name === '') {
                $errors[] = ['line' => $lineNumber, 'message' => 'не указано имя'];
                $valid = false;
            }

            if (mb_strlen($family, 'UTF-8') > 64 || mb_strlen($name, 'UTF-8') > 64) {
                $errors[] = ['line' => $lineNumber, 'message' => 'слишком длинное значение'];
                $valid = false;
            }

            if (count($cells) > 3) {
                $errors[] = ['line' => $lineNumber, 'message' => 'ожидалось ровно три колонки'];
                $valid = false;
            }

            if ($valid) {
                $rows[] = [
                    'family' => $family,
                    'name'   => $name,
                    'middle' => $middle,
                    'line'   => $lineNumber,
                ];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Готовое содержимое файла-шаблона.
     *
     * BOM обязателен: без него русские буквы в Excel превращаются в мусор.
     */
    public function template(): string
    {
        $rows = [self::HEADERS];

        $rows[] = ['Петров', 'Иван', 'Ильич'];
        $rows[] = ['Сидорова', 'Мария', 'Алексеевна'];
        $rows[] = ['Кузнецов', 'Пётр', ''];

        return "\xEF\xBB\xBF" . $this->toCsv($rows);
    }

    /**
     * Разделитель по первой строке с данными: сравниваем число ячеек
     * при точке с запятой и при запятой.
     */
    private function detectDelimiter(string $content): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $semicolons = substr_count($line, ';');
            $commas = substr_count($line, ',');

            if ($semicolons > 0 && $semicolons >= $commas) {
                return ';';
            }

            if ($commas > 0) {
                return ',';
            }

            return self::DELIMITER;
        }

        return self::DELIMITER;
    }

    /** @param list<string> $cells */
    private function looksLikeHeader(array $cells): bool
    {
        $first = mb_strtolower($cells[0] ?? '', 'UTF-8');
        $second = mb_strtolower($cells[1] ?? '', 'UTF-8');

        return str_contains($first, 'фамил') || str_contains($first, 'surname')
            || str_contains($second, 'имя') || str_contains($second, 'name');
    }

    /** @param list<list<string>> $rows */
    private function toCsv(array $rows): string
    {
        $out = '';

        foreach ($rows as $cells) {
            $escaped = array_map(
                static function (string $cell): string {
                    return str_contains($cell, self::DELIMITER) || str_contains($cell, '"')
                        ? '"' . str_replace('"', '""', $cell) . '"'
                        : $cell;
                },
                $cells
            );

            $out .= implode(self::DELIMITER, $escaped) . "\r\n";
        }

        return $out;
    }

    private function stripBom(string $content): string
    {
        foreach (["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"] as $bom) {
            if (str_starts_with($content, $bom)) {
                return substr($content, strlen($bom));
            }
        }

        return $content;
    }
}
