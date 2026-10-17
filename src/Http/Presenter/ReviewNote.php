<?php

declare(strict_types=1);

namespace MyEdu\Http\Presenter;

/**
 * Человекочитаемое пояснение к разбору ответа.
 *
 * Каждый тип вопроса складывает в detail свои подробности; этот класс
 * превращает их в одну-две фразы для ученика. Появление нового типа
 * вопроса не потребует правок здесь, если он заполнит detail понятными
 * ученику ключами, либо вернёт пустую строку.
 */
final class ReviewNote
{
    /**
     * @param array<string,mixed> $detail
     */
    public static function make(array $detail): string
    {
        $notes = [];

        if (!empty($detail['format_error'])) {
            return 'Ответ пришёл в неожиданном формате и не засчитан: '
                . self::scalar($detail['format_error']);
        }

        if (!empty($detail['reason'])) {
            return self::scalar($detail['reason']);
        }

        $missing = self::stringList($detail['missing'] ?? null);
        if ($missing !== []) {
            $notes[] = 'Не найдены ключевые слова: ' . implode(', ', $missing);
        }

        if (!empty($detail['forbidden_found'])) {
            $notes[] = 'В ответе есть недопустимое слово: ' . self::scalar($detail['forbidden_found']);
        }

        // Тексты вариантов приходят вместе с ключами; если их нет (старый снимок
        // попытки), показываем ключи — так разбор не станет пустым.
        $misses = self::stringList($detail['misses_text'] ?? null) ?: self::stringList($detail['misses'] ?? null);
        if ($misses !== []) {
            $notes[] = 'Лишние варианты: ' . implode(', ', $misses);
        }

        $missed = self::stringList($detail['missed_text'] ?? null) ?: self::stringList($detail['missed'] ?? null);
        if ($missed !== []) {
            $notes[] = 'Пропущены верные варианты: ' . implode(', ', $missed);
        }

        $found = self::stringList($detail['found'] ?? null);
        if ($missing === [] && $found !== [] && self::stringList($detail['required'] ?? null) !== []) {
            $notes[] = 'Найдены ключевые слова: ' . implode(', ', $found);
        }

        if (isset($detail['errors']) && is_array($detail['errors']) && $detail['errors'] !== []) {
            $notes[] = 'Неверных соответствий: ' . count($detail['errors']);

            $duplicates = 0;
            foreach ($detail['errors'] as $error) {
                if (is_array($error) && ($error['is_duplicate'] ?? false) === true) {
                    $duplicates++;
                }
            }

            if ($duplicates > 0) {
                $notes[] = 'один элемент использован для нескольких пунктов';
            }
        }

        // Нулевой процент не поясняет ничего: про отсутствие баллов и так видно.
        if (isset($detail['ratio']) && is_numeric($detail['ratio']) && (float) $detail['ratio'] > 0.0) {
            $notes[] = 'засчитано примерно ' . round((float) $detail['ratio'] * 100) . '% от максимума';
        }

        if (isset($detail['chosen_count'], $detail['expected_count'])) {
            $notes[] = sprintf(
                'указано элементов: %d из %d',
                (int) $detail['chosen_count'],
                (int) $detail['expected_count']
            );
        }

        return implode('. ', $notes);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}