<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;

/**
 * Вопрос с выбором одного ответа.
 *
 * config: {"correct": "b"} — value_key верного варианта.
 */
final class SingleChoiceType implements QuestionTypeInterface
{
    public function key(): string
    {
        return 'single_choice';
    }

    public function title(): string
    {
        return 'Один вариант ответа';
    }

    public function defaultConfig(): array
    {
        return ['correct' => ''];
    }

    public function validateConfig(array $config, array $options): void
    {
        $correct = (string) ($config['correct'] ?? '');

        if ($correct === '') {
            throw new InvalidAnswerException('Не указан правильный ответ');
        }

        $keys = array_column($options, 'value_key');
        if (!in_array($correct, $keys, true)) {
            throw new InvalidAnswerException('Правильный ответ ссылается на несуществующий вариант');
        }

        if (count($options) < 2) {
            throw new InvalidAnswerException('Нужно минимум два варианта ответа');
        }
    }

    public function payload(Question $question): array
    {
        $options = $question->optionsOfKind(Question::KIND_OPTION);

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

    public function normalizeAnswer(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_string($raw)) {
            throw new InvalidAnswerException('Ожидалась строка с ключом варианта');
        }

        return $raw;
    }

    public function grade(Question $question, mixed $answer): QuestionGrade
    {
        $max = $question->maxScore();
        $expected = $this->expectedText($question);

        if ($answer === null || $answer === '') {
            return QuestionGrade::wrong($max, $expected, ['chosen' => null]);
        }

        $correct = (string) $question->configValue('correct', '');

        return $answer === $correct
            ? QuestionGrade::correct($max, $max, $expected)
            : QuestionGrade::wrong($max, $expected, ['chosen' => $answer]);
    }

    public function expectedText(Question $question): string
    {
        $correct = (string) $question->configValue('correct', '');
        $option = $question->optionByKey(Question::KIND_OPTION, $correct);

        return $option === null ? '' : (string) $option['text'];
    }

    public function answerText(Question $question, mixed $answer): string
    {
        if ($answer === null || $answer === '') {
            return '— не выбран —';
        }

        $option = $question->optionByKey(Question::KIND_OPTION, (string) $answer);

        return $option === null ? (string) $answer : (string) $option['text'];
    }
}