<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Результат проверки одного вопроса.
 *
 * score и maxScore — дробные, потому что веса вопросов и частичное
 * зачтение дают нецелые значения; округление фиксируется в GradeCalculator.
 */
final class QuestionGrade
{
    /**
     * @param list<string>          $correctKeys  ключи вариантов, которые автор считает верными
     * @param array<string,mixed>   $detail       разбор для показа ученику
     */
    public function __construct(
        public readonly float $score,
        public readonly float $maxScore,
        public readonly bool $isCorrect,
        public readonly string $expectedText = '',
        public readonly array $correctKeys = [],
        public readonly array $detail = []
    ) {
    }

    /** @param array<string,mixed> $detail */
    public static function correct(float $score, float $maxScore, string $expected = '', array $detail = []): self
    {
        return new self($score, $maxScore, true, $expected, [], $detail);
    }

    /**
     * Частично верный ответ: балл больше нуля, но не полный.
     *
     * @param array<string,mixed> $detail
     */
    public static function partial(float $score, float $maxScore, string $expected, array $detail = []): self
    {
        return new self($score, $maxScore, false, $expected, [], $detail);
    }

    /** @param array<string,mixed> $detail */
    public static function wrong(float $maxScore, string $expected, array $detail = []): self
    {
        return new self(0.0, $maxScore, false, $expected, [], $detail);
    }

    /**
     * Ответ нельзя проверить автоматически (стратегия 'manual').
     * Балл пока 0, попытка требует вмешательства учителя.
     */
    public static function forManualReview(float $maxScore, string $reason): self
    {
        return new self(
            0.0,
            $maxScore,
            false,
            '',
            [],
            ['review' => true, 'reason' => $reason]
        );
    }

    public function needsReview(): bool
    {
        return ($this->detail['review'] ?? false) === true;
    }
}