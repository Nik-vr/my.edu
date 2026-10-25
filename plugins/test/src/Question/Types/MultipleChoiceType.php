<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;

/**
 * Вопрос с выбором нескольких ответов.
 *
 * config:
 *   {
 *     "correct": ["a", "c", "d"],
 *     "partial": true,          // частичное зачтение
 *     "miss_penalty": 0.5       // сколько отнимается за каждый лишний выбор
 *   }
 *
 * При partial = false — только «всё или ничего».
 * При partial = true доля зачтённого считается так:
 *   (верных_выбрано − лишних_выбрано × miss_penalty) / всего_верных, не меньше 0.
 */
final class MultipleChoiceType implements QuestionTypeInterface
{
    public function key(): string
    {
        return 'multiple_choice';
    }

    public function title(): string
    {
        return 'Несколько вариантов ответа';
    }

    public function defaultConfig(): array
    {
        return ['correct' => [], 'partial' => true, 'miss_penalty' => 0.5];
    }

    public function validateConfig(array $config, array $options): void
    {
        $correct = $config['correct'] ?? [];
        if (!is_array($correct) || $correct === []) {
            throw new InvalidAnswerException('Не указаны правильные варианты');
        }

        $keys = array_column($options, 'value_key');
        foreach ($correct as $value) {
            if (!in_array((string) $value, $keys, true)) {
                throw new InvalidAnswerException('Правильный ответ ссылается на несуществующий вариант');
            }
        }

        if (count($correct) < 2) {
            throw new InvalidAnswerException('Для этого типа нужно минимум два правильных варианта');
        }

        if (count($options) <= count($correct)) {
            throw new InvalidAnswerException('Нужно больше вариантов, чем правильных ответов');
        }
    }

    public function payload(Question $question): array
    {
        $options = $question->visibleOptionsOfKind(
            Question::KIND_OPTION,
            $this->correctKeys($question)
        );

        if ($question->shouldShuffleOptions()) {
            $options = $question->shuffled($options);
        }

        return [
            'options' => array_map(
                static fn (array $o): array => ['value' => (string) $o['value_key'], 'text' => (string) $o['text']],
                $options
            ),
        ];
    }

    public function normalizeAnswer(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }

        if (!is_array($raw)) {
            throw new InvalidAnswerException('Ожидался массив ключей вариантов');
        }

        $values = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                throw new InvalidAnswerException('Ключ варианта должен быть строкой');
            }
            if ($item !== '' && !in_array($item, $values, true)) {
                $values[] = $item;
            }
        }

        return $values;
    }

    public function grade(Question $question, mixed $answer): QuestionGrade
    {
        $max = $question->maxScore();
        $expected = $this->expectedText($question);
        $correctKeys = $this->correctKeys($question);
        $chosen = is_array($answer) ? $answer : [];

        /*
         * Вырожденный вопрос не делится: вес нулевой (база правлена руками)
         * или правильные варианты не заданы. Доли и штрафы считаются делением
         * на вес и на число правильных — без этой строки ответ уронил бы
         * проверку попытки вместо честного «не отвечено».
         */
        if ($max <= 0.0 || $correctKeys === []) {
            return QuestionGrade::wrong($max, $expected);
        }

        $hits = array_values(array_intersect($chosen, $correctKeys));
        $misses = array_values(array_diff($chosen, $correctKeys));
        $missed = array_values(array_diff($correctKeys, $chosen));

        $detail = [
            'hits'   => $hits,
            'misses' => $misses,
            'missed' => $missed,
            // Тексты вариантов нужны разбору: ученику бессмысленно показывать
            // внутренний ключ «b» вместо «Извержение вулкана».
            'hits_text'   => $this->textsOf($question, $hits),
            'misses_text' => $this->textsOf($question, $misses),
            'missed_text' => $this->textsOf($question, $missed),
        ];

        if ($chosen === []) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        $isExact = $misses === [] && $missed === [];

        if ($isExact) {
            return QuestionGrade::correct($max, $max, $expected, $detail);
        }

        if (!$question->configBool('partial', true)) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        /*
         * Штраф за лишний вариант отделяется от балла.
         *
         * Раньше он резал балл задания начисто, и по результату нельзя было
         * понять, что именно произошло: ученик ответил верно, но зацепил лишнее,
         * — а это видно только в разборе. Теперь балл задания — это то, что
         * заработано за верные варианты, а штраф лежит рядом отдельным числом и
         * попадает в сводку работы: «набрано, штраф, итого».
         *
         * Штраф не может стать больше заработанного: снять больше, чем стоит
         * задание, нельзя, и итог не уходит в минус.
         */
        $missPenalty = $question->configFloat('miss_penalty', 0.5);
        $correctCount = count($correctKeys);
        $earned = (count($hits) / $correctCount) * $max;
        $penalty = min((count($misses) * $missPenalty / $correctCount) * $max, $earned);
        $detail['ratio'] = round($earned / $max, 4);
        $detail['penalty'] = round($penalty, 2);

        if ($earned - $penalty <= 0.0) {
            // При нулевом результате всё равно показываем частичную разбивку,
            // чтобы ученик понял, что было не так.
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        return QuestionGrade::partial(round($earned, 2), $max, $expected, $detail, round($penalty, 2));
    }

    public function expectedText(Question $question): string
    {
        $texts = [];

        foreach ($this->correctKeys($question) as $key) {
            $option = $question->optionByKey(Question::KIND_OPTION, $key);
            if ($option !== null) {
                $texts[] = (string) $option['text'];
            }
        }

        return implode('; ', $texts);
    }

    public function answerText(Question $question, mixed $answer): string
    {
        $chosen = is_array($answer) ? $answer : [];

        if ($chosen === []) {
            return '— ничего не выбрано —';
        }

        $texts = [];
        foreach ($chosen as $key) {
            $option = $question->optionByKey(Question::KIND_OPTION, (string) $key);
            $texts[] = $option === null ? (string) $key : (string) $option['text'];
        }

        return implode('; ', $texts);
    }

    /** @return list<string> */
    private function correctKeys(Question $question): array
    {
        $raw = $question->configValue('correct', []);

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
     * Переводит ключи вариантов в их тексты — для показа в разборе.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function textsOf(Question $question, array $keys): array
    {
        $texts = [];

        foreach ($keys as $key) {
            $option = $question->optionByKey(Question::KIND_OPTION, (string) $key);
            $texts[] = $option === null ? (string) $key : (string) $option['text'];
        }

        return $texts;
    }
}