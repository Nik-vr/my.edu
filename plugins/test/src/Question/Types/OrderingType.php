<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;

/**
 * Вопрос на установление правильной последовательности.
 *
 * config:
 *   {
 *     "correct_order": ["c", "a", "d", "b"],  // value_key в правильном порядке
 *     "partial": "positional"                 // none | positional | adjacent
 *   }
 *
 * Режимы частичного зачтения:
 *   none       — только полное совпадение;
 *   positional — доля элементов, стоящих на своих местах;
 *   adjacent   — доля соседних пар, стоящих верно (мягче к сдвигам).
 *
 * Элементы отправляются в браузер перемешанными (shuffle — на стороне сервера),
 * иначе ответ можно было бы прочитать из исходного кода страницы.
 */
final class OrderingType implements QuestionTypeInterface
{
    public function key(): string
    {
        return 'ordering';
    }

    public function title(): string
    {
        return 'Правильная последовательность';
    }

    public function defaultConfig(): array
    {
        return ['correct_order' => [], 'partial' => 'positional'];
    }

    /**
     * Режимы частичного балла — для формы автора.
     *
     * @return list<string>
     */
    public static function partialModes(): array
    {
        return ['none', 'positional', 'adjacent'];
    }

    public static function partialModeTitle(string $mode): string
    {
        return match ($mode) {
            'none'      => 'только полный балл за верный порядок',
            'positional' => 'частичный балл за элементы на своих местах',
            'adjacent'  => 'частичный балл за соседние элементы',
            default     => $mode,
        };
    }

    public function validateConfig(array $config, array $options): void
    {
        $order = $config['correct_order'] ?? [];
        if (!is_array($order) || count($order) < 2) {
            throw new InvalidAnswerException('Нужно указать последовательность минимум из двух элементов');
        }

        $itemKeys = [];
        foreach ($options as $option) {
            if ((string) $option['kind'] === Question::KIND_ITEM) {
                $itemKeys[] = (string) $option['value_key'];
            }
        }

        if (count($order) !== count($itemKeys)) {
            throw new InvalidAnswerException('Последовательность должна включать все элементы вопроса');
        }

        foreach ($order as $value) {
            if (!in_array((string) $value, $itemKeys, true)) {
                throw new InvalidAnswerException('Последовательность ссылается на несуществующий элемент');
            }
        }

        if (count(array_unique(array_map('strval', $order))) !== count($order)) {
            throw new InvalidAnswerException('В последовательности есть повторяющиеся элементы');
        }
    }

    public function payload(Question $question): array
    {
        $items = $question->shuffled($question->optionsOfKind(Question::KIND_ITEM));

        return [
            'items' => array_map(
                static fn (array $o): array => ['value' => (string) $o['value_key'], 'text' => (string) $o['text']],
                $items
            ),
        ];
    }

    /**
     * Ответ принимается в двух видах:
     *
     *   список   ["c", "a", "d", "b"] — порядок элементов как есть
     *            (так шлёт JSON-клиент);
     *
     *   карта    {"a": 1, "c": 2, ...} — элемент => позиция
     *            (так приходит обычная HTML-форма со списками выбора,
     *            благодаря чему задание решается без JavaScript).
     */
    public function normalizeAnswer(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ($raw === '' ? [] : [$raw]);
        }

        if (!is_array($raw)) {
            throw new InvalidAnswerException('Ожидался массив элементов в порядке ученика');
        }

        if ($raw === []) {
            return [];
        }

        return array_is_list($raw)
            ? $this->normalizeOrderedList($raw)
            : $this->normalizePositionMap($raw);
    }

    /**
     * @param array<mixed> $raw
     * @return list<string>
     */
    private function normalizeOrderedList(array $raw): array
    {
        $values = [];

        foreach ($raw as $item) {
            if (!is_string($item)) {
                throw new InvalidAnswerException('Ключ элемента должен быть строкой');
            }

            if ($item !== '' && !in_array($item, $values, true)) {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $raw
     * @return list<string>
     */
    private function normalizePositionMap(array $raw): array
    {
        $slots = [];

        foreach ($raw as $itemKey => $position) {
            if (!is_string($itemKey) || !is_numeric($position)) {
                throw new InvalidAnswerException('Ожидалась пара «элемент — позиция»');
            }

            $position = (int) $position;

            if ($position < 1) {
                throw new InvalidAnswerException('Позиция должна быть больше нуля');
            }

            // Две позиции с одним номером делают ответ неоднозначным:
            // порядок восстановить нельзя, поэтому ответ считается некорректным.
            if (isset($slots[$position])) {
                throw new InvalidAnswerException(
                    'У элементов «' . $slots[$position] . '» и «' . $itemKey . '» указана одна позиция'
                );
            }

            $slots[$position] = $itemKey;
        }

        ksort($slots, SORT_NUMERIC);

        return array_values($slots);
    }

    public function grade(Question $question, mixed $answer): QuestionGrade
    {
        $max = $question->maxScore();
        $expected = $this->expectedText($question);
        $correctOrder = $this->correctOrder($question);
        $chosen = is_array($answer) ? array_values($answer) : [];

        // Пустой или неполный ответ — это ноль, а не «частично верно».
        if ($chosen === [] || count($chosen) !== count($correctOrder)) {
            return QuestionGrade::wrong($max, $expected, [
                'chosen_count' => count($chosen),
                'expected_count' => count($correctOrder),
            ]);
        }

        if ($chosen === $correctOrder) {
            return QuestionGrade::correct($max, $max, $expected, [
                'chosen'       => $chosen,
                'correct_order'=> $correctOrder,
            ]);
        }

        $mode = $question->configValue('partial', 'positional');
        $mode = is_string($mode) ? $mode : 'positional';

        if ($mode === 'none') {
            return QuestionGrade::wrong($max, $expected, ['chosen' => $chosen]);
        }

        $ratio = $mode === 'adjacent'
            ? $this->adjacentRatio($chosen, $correctOrder)
            : $this->positionalRatio($chosen, $correctOrder);

        $detail = [
            'mode'         => $mode,
            'ratio'        => round($ratio, 4),
            'chosen'       => $chosen,
            'correct_order' => $correctOrder,
        ];

        if ($ratio <= 0.0) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        return QuestionGrade::partial(round($ratio * $max, 2), $max, $expected, $detail);
    }

    public function expectedText(Question $question): string
    {
        $texts = [];

        foreach ($this->correctOrder($question) as $key) {
            $option = $question->optionByKey(Question::KIND_ITEM, $key);
            $texts[] = $option === null ? (string) $key : (string) $option['text'];
        }

        return implode(' → ', $texts);
    }

    public function answerText(Question $question, mixed $answer): string
    {
        $chosen = is_array($answer) ? $answer : [];

        if ($chosen === []) {
            return '— порядок не задан —';
        }

        $texts = [];
        foreach ($chosen as $key) {
            $option = $question->optionByKey(Question::KIND_ITEM, (string) $key);
            $texts[] = $option === null ? (string) $key : (string) $option['text'];
        }

        return implode(' → ', $texts);
    }

    /** @return list<string> */
    private function correctOrder(Question $question): array
    {
        $raw = $question->configValue('correct_order', []);

        if (!is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $value) {
            if (is_scalar($value)) {
                $keys[] = (string) $value;
            }
        }

        return $keys;
    }

    /**
     * Доля элементов, оказавшихся на своём месте.
     *
     * @param list<string> $chosen
     * @param list<string> $correct
     */
    private function positionalRatio(array $chosen, array $correct): float
    {
        $inPlace = 0;
        $count = count($correct);

        for ($i = 0; $i < $count; $i++) {
            if (($chosen[$i] ?? null) === $correct[$i]) {
                $inPlace++;
            }
        }

        return $count === 0 ? 0.0 : $inPlace / $count;
    }

    /**
     * Доля верных соседних пар.
     *
     * Более мягкий режим: ученик, переставивший один элемент, теряет не 1/n,
     * а 2/(n-1) — логичнее, ведь по сути порядок почти соблюдён.
     *
     * @param list<string> $chosen
     * @param list<string> $correct
     */
    private function adjacentRatio(array $chosen, array $correct): float
    {
        $pairs = count($correct) - 1;

        if ($pairs <= 0) {
            return 0.0;
        }

        $good = 0;
        for ($i = 0; $i < $pairs; $i++) {
            if (($chosen[$i] ?? null) === $correct[$i]
                && ($chosen[$i + 1] ?? null) === $correct[$i + 1]
            ) {
                $good++;
            }
        }

        return $good / $pairs;
    }
}