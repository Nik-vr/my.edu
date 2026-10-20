<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Grading;

/**
 * Итог проверки одной работы.
 *
 * Неизменяемый: снимок результата сохраняется в базу целиком, и если позже
 * автор правит вопрос или критерии, ученик продолжает видеть ту оценку,
 * которую реально получил.
 */
final class GradingResult
{
    /**
     * @param list<array<string,mixed>> $items разбор по вопросам
     */
    public function __construct(
        public readonly array $items,
        public readonly float $score,
        public readonly float $maxScore,
        public readonly float $percent,
        public readonly ?int $grade,
        public readonly ?string $gradeLabel,
        public readonly float $penalty = 0.0
    ) {
    }

    /**
     * Снимок из базы.
     *
     * Восстанавливается по сохранённым числам, а не пересчитывается: вопросы
     * могли измениться, а оценка ученика — нет.
     *
     * @param list<array<string,mixed>> $items
     */
    public static function fromArray(array $items, float $score, float $maxScore): self
    {
        $percent = $maxScore > 0.0 ? round($score / $maxScore * 100, 2) : 0.0;

        return new self(array_values($items), $score, $maxScore, $percent, null, null);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'items'       => $this->items,
            'score'       => $this->score,
            'max_score'   => $this->maxScore,
            'percent'     => $this->percent,
            'grade'       => $this->grade,
            'grade_label' => $this->gradeLabel,
            'penalty'     => $this->penalty,
        ];
    }

    /** Полностью верные вопросы. */
    public function countCorrect(): int
    {
        return $this->countWhere(static fn (array $item): bool => ($item['is_correct'] ?? false) === true);
    }

    /** Вопросы, за которые начислена часть балла. */
    public function countPartial(): int
    {
        return $this->countWhere(static function (array $item): bool {
            $score = (float) ($item['score'] ?? 0.0);
            $max = (float) ($item['max_score'] ?? 0.0);

            return ($item['is_correct'] ?? false) !== true && $score > 0.0 && $score < $max;
        });
    }

    /** Вопросы без балла. */
    public function countWrong(): int
    {
        return $this->countWhere(static function (array $item): bool {
            return ($item['is_correct'] ?? false) !== true
                && (float) ($item['score'] ?? 0.0) <= 0.0
                && ($item['needs_review'] ?? false) !== true;
        });
    }

    /** Есть ли вопросы, ждущие учителя. */
    public function hasUnreviewed(): bool
    {
        foreach ($this->items as $item) {
            if (($item['needs_review'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(array<string,mixed>):bool $predicate
     */
    private function countWhere(callable $predicate): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            if ($predicate($item)) {
                $count++;
            }
        }

        return $count;
    }
}