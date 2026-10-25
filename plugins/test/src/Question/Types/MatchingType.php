<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;

/**
 * Вопрос на установление соответствия между двумя списками (А-1, Б-2, ...).
 *
 * config:
 *   {"pairs": {"l1": "r3", "l2": "r1"}, "partial": true}
 *
 * Элементы:
 *   kind = 'left'  — левая колонка, value_key вида "l1", "l2", ...;
 *   kind = 'right' — правая колонка, value_key вида "r1", "r2", ...;
 *   pair_key у правой колонки дублирует config.pairs и нужен редактору (этап 2),
 *   при проверке используется config.pairs как единственный источник истины.
 *
 * Соответствие взаимно однозначное: один элемент правой колонки не может быть
 * назначен двум элементам левой.
 */
final class MatchingType implements QuestionTypeInterface
{
    public function key(): string
    {
        return 'matching';
    }

    public function title(): string
    {
        return 'Соответствие А-1, Б-2';
    }

    public function defaultConfig(): array
    {
        return ['pairs' => [], 'partial' => true];
    }

    public function validateConfig(array $config, array $options): void
    {
        $pairs = $config['pairs'] ?? [];

        if (!is_array($pairs) || $pairs === []) {
            throw new InvalidAnswerException('Не заданы пары соответствия');
        }

        $leftKeys = $this->keysOfKind($options, Question::KIND_LEFT);
        $rightKeys = $this->keysOfKind($options, Question::KIND_RIGHT);

        if (count($leftKeys) < 2) {
            throw new InvalidAnswerException('В левой колонке нужно минимум два элемента');
        }

        if (count($leftKeys) !== count($rightKeys)) {
            throw new InvalidAnswerException('В левой и правой колонках должно быть одинаковое количество элементов');
        }

        $usedRight = [];

        foreach ($pairs as $left => $right) {
            if (!in_array((string) $left, $leftKeys, true)) {
                throw new InvalidAnswerException('Пара ссылается на несуществующий элемент левой колонки');
            }
            if (!in_array((string) $right, $rightKeys, true)) {
                throw new InvalidAnswerException('Пара ссылается на несуществующий элемент правой колонки');
            }
            if (isset($usedRight[(string) $right])) {
                throw new InvalidAnswerException(
                    'Элемент правой колонки назначен двум элементам левой: ' . $right
                );
            }
            $usedRight[(string) $right] = true;
        }

        if (count($pairs) !== count($leftKeys)) {
            throw new InvalidAnswerException('Нужно сопоставить все элементы левой колонки');
        }
    }

    public function payload(Question $question): array
    {
        $left = $question->optionsOfKind(Question::KIND_LEFT);

        /*
         * «Показать N вариантов» действует только на правую колонку.
         *
         * Левая колонка остаётся полной: она и есть условие, и урезать её —
         * значит сделать часть вопроса неразрешимой. Зато правая колонка
         * состоит из ответов и отвлекающих вариантов, и урезать её можно —
         * при одном условии: верные партнёры видимых левых элементов
         * показываются в любом случае. Без них пару составить было бы
         * нечем, а лишние (отвлекающие) как раз и отсекаются.
         *
         * Раньше настройка для этого типа вообще не читалась: автор видел
         * поле в настройках, а ученик получал все варианты подряд.
         */
        $pairs = $question->configValue('pairs', []);

        if (!is_array($pairs)) {
            $pairs = [];
        }

        $partners = [];

        foreach ($left as $option) {
            $right = $pairs[(string) ($option['value_key'] ?? '')] ?? null;

            if ($right !== null) {
                $partners[] = (string) $right;
            }
        }

        $right = $question->visibleOptionsOfKind(Question::KIND_RIGHT, $partners);

        /*
         * Перемешиваются варианты ответа, то есть правая колонка.
         *
         * Левая колонка — условие, а не ответ: её порядок задаёт автор и
         * может нести смысл (перечисление, нумерация), и трогать его
         * настройка «перемешивать варианты ответа» не должна. Раньше эта
         * настройка для соответствия не применялась вовсе, и галочка в
         * редакторе ничего не меняла.
         */
        if ($question->shouldShuffleOptions()) {
            $right = $question->shuffled($right);
        }

        $column = static fn (array $options): array => array_map(
            static fn (array $o): array => ['value' => (string) $o['value_key'], 'text' => (string) $o['text']],
            $options
        );

        return [
            'left'  => $column($left),
            'right' => $column($right),
        ];
    }

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
            throw new InvalidAnswerException('Ожидался объект соответствий');
        }

        $pairs = [];
        foreach ($raw as $left => $right) {
            if (!is_string($left) || !is_string($right)) {
                throw new InvalidAnswerException('Ключи и значения соответствий должны быть строками');
            }
            if ($left !== '' && $right !== '') {
                $pairs[$left] = $right;
            }
        }

        return $pairs;
    }

    public function grade(Question $question, mixed $answer): QuestionGrade
    {
        $max = $question->maxScore();
        $expected = $this->expectedText($question);
        $correct = $this->pairs($question);
        $chosen = is_array($answer) ? $answer : [];

        /*
         * Вырожденный вопрос не делится: вес нулевой или пары не заданы
         * (сломанный конфиг). Доля считается делением на число пар — без этой
         * строки ответ уронил бы проверку попытки вместо честного «не отвечено».
         */
        if ($max <= 0.0 || $correct === []) {
            return QuestionGrade::wrong($max, $expected);
        }

        // Считаем, сколько раз каждый элемент правой колонки использован:
        // один и тот же элемент дважды не считается за две верные пары.
        $usage = array_count_values(array_map('strval', array_values($chosen)));

        $hits = [];
        $errors = [];

        foreach ($correct as $left => $right) {
            $given = isset($chosen[$left]) ? (string) $chosen[$left] : null;

            if ($given === $right && ($usage[$right] ?? 0) === 1) {
                $hits[] = ['left' => $left, 'right' => $right];
                continue;
            }

            $errors[] = [
                'left'         => $left,
                'given'        => $given,
                'expected'     => $right,
                'is_duplicate' => $given !== null && ($usage[$given] ?? 0) > 1,
            ];
        }

        $detail = ['hits' => $hits, 'errors' => $errors];

        if ($chosen === []) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        if ($errors === []) {
            return QuestionGrade::correct($max, $max, $expected, $detail);
        }

        if (!$question->configBool('partial', true)) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        $ratio = count($hits) / count($correct);
        $detail['ratio'] = round($ratio, 4);

        return $ratio <= 0.0
            ? QuestionGrade::wrong($max, $expected, $detail)
            : QuestionGrade::partial(round($ratio * $max, 2), $max, $expected, $detail);
    }

    public function expectedText(Question $question): string
    {
        $parts = [];

        foreach ($this->pairs($question) as $left => $right) {
            $leftOption = $question->optionByKey(Question::KIND_LEFT, (string) $left);
            $rightOption = $question->optionByKey(Question::KIND_RIGHT, (string) $right);

            $leftLabel = $leftOption === null ? (string) $left : (string) $leftOption['text'];
            $rightLabel = $rightOption === null ? (string) $right : (string) $rightOption['text'];

            $parts[] = $leftLabel . ' → ' . $rightLabel;
        }

        return implode('; ', $parts);
    }

    public function answerText(Question $question, mixed $answer): string
    {
        $chosen = is_array($answer) ? $answer : [];

        if ($chosen === []) {
            return '— соответствия не указаны —';
        }

        $parts = [];

        foreach ($chosen as $left => $right) {
            $leftOption = $question->optionByKey(Question::KIND_LEFT, (string) $left);
            $rightOption = $question->optionByKey(Question::KIND_RIGHT, (string) $right);

            $leftLabel = $leftOption === null ? (string) $left : (string) $leftOption['text'];
            $rightLabel = $rightOption === null ? (string) $right : (string) $rightOption['text'];

            $parts[] = $leftLabel . ' → ' . $rightLabel;
        }

        return implode('; ', $parts);
    }

    /** @return array<string,string> */
    private function pairs(Question $question): array
    {
        $raw = $question->configValue('pairs', []);

        if (!is_array($raw)) {
            return [];
        }

        $pairs = [];
        foreach ($raw as $left => $right) {
            if (is_scalar($left) && is_scalar($right)) {
                $pairs[(string) $left] = (string) $right;
            }
        }

        return $pairs;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return list<string>
     */
    private function keysOfKind(array $options, string $kind): array
    {
        $keys = [];

        foreach ($options as $option) {
            if ((string) ($option['kind'] ?? '') === $kind) {
                $keys[] = (string) $option['value_key'];
            }
        }

        return $keys;
    }
}