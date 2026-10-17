<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

use InvalidArgumentException;

/**
 * Результат проверки одного задания.
 *
 * Ядро складывает эти значения в итог работы: в режиме «единая оценка» —
 * баллы и процент, в режиме «отдельная оценка за задание» — ещё и оценки
 * с весами.
 *
 * snapshot — то, что плагин хочет показать в разборе. Ядро сохраняет его
 * как есть и больше не пересчитывает: если автор правит вопрос, ученик должен
 * видеть тот разбор, который получили при проверке.
 */
final class ItemGrade
{
    /**
     * @param float                $score       балл за задание
     * @param float                $maxScore    максимум задания
     * @param int|null             $grade       оценка 1..5, если у плагина есть свои критерии
     * @param bool                 $needsReview ждёт учителя: автопроверки нет
     * @param array<string,mixed>  $snapshot    данные для разбора
     */
    public function __construct(
        public readonly float $score,
        public readonly float $maxScore,
        public readonly ?int $grade = null,
        public readonly bool $needsReview = false,
        public readonly array $snapshot = []
    ) {
        if (!is_finite($score) || !is_finite($maxScore) || $maxScore < 0.0
            || $score < 0.0 || $score > $maxScore) {
            throw new InvalidArgumentException('Баллы задания должны быть конечными и лежать в диапазоне от 0 до максимума');
        }

        if ($grade !== null && ($grade < 1 || $grade > 5)) {
            throw new InvalidArgumentException('Оценка задания должна быть от 1 до 5');
        }

        if (json_encode($snapshot) === false) {
            throw new InvalidArgumentException('Снимок разбора задания должен быть сериализуем в JSON');
        }
    }

    /**
     * Задание ждёт проверки учителем.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function forReview(float $maxScore, array $snapshot = []): self
    {
        return new self(0.0, $maxScore, null, true, $snapshot);
    }

    /** @param array<string,mixed> $snapshot */
    public static function scored(float $score, float $maxScore, array $snapshot = [], ?int $grade = null): self
    {
        return new self($score, $maxScore, $grade, false, $snapshot);
    }

    /**
     * Балл в процентах от максимума задания.
     *
     * Задание без максимума не считается невыполненным: иначе работа, где у
     * одного задания не задан балл, молча уронила бы процент у всего ученика.
     */
    public function percent(): ?float
    {
        if ($this->maxScore <= 0.0) {
            return null;
        }

        return round($this->score / $this->maxScore * 100, 2);
    }
}
