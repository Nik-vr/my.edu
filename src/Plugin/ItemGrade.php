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
     * @param float                $penalty     штраф за задание, отдельно от балла
     * @param bool                 $skipped     ученик задание не открывал — «пропущено»
     */
    public function __construct(
        public readonly float $score,
        public readonly float $maxScore,
        public readonly ?int $grade = null,
        public readonly bool $needsReview = false,
        public readonly array $snapshot = [],
        public readonly float $penalty = 0.0,
        public readonly bool $skipped = false
    ) {
        if (!is_finite($score) || !is_finite($maxScore) || $maxScore < 0.0
            || $score < 0.0 || $score > $maxScore) {
            throw new InvalidArgumentException('Баллы задания должны быть конечными и лежать в диапазоне от 0 до максимума');
        }

        /*
         * Штраф — величина отдельная от балла, и она не может быть больше
         * максимума: снять больше, чем стоит задание, нельзя. Проверяется здесь,
         * а не при разборе, потому что при разборе ошибка уже записана.
         */
        if (!is_finite($penalty) || $penalty < 0.0) {
            throw new InvalidArgumentException('Штраф задания должен быть конечным и не меньше нуля');
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

    /**
     * Задание пропущено: ученик его не открывал.
     *
     * Решение владельца от 24.10.2026 (волна C): нераскрытое задание — это
     * «пропущено», а не «ждёт проверки». Раньше оно уходило в `forReview`, и
     * попытка целиком получала отметку «ручной проверки» — интерфейс врал:
     * проверять нечего, ответа не существует, и учитель видел чужую работу
     * в очереди среди настоящих. Теперь:
     *
     *  - балл 0 из обычного максимума (максимум задания остаётся в итоге
     *    работы, и процент ученика падает ровно на долю пропуска — так и
     *    должно быть);
     *  - `needsReview` не ставится, поэтому попытка не помечается как
     *    ручная и не попадает в счётчик «ждут проверки»;
     *  - статус позиции ядро пишет как `skipped`, и страница результата
     *    показывает «пропущено».
     *
     * Ручная проверка от этого не исчезает: она остаётся там, где есть что
     * проверять, — у текстовых вопросов со стратегией `manual`.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function skipped(float $maxScore, array $snapshot = []): self
    {
        return new self(0.0, $maxScore, null, false, $snapshot, 0.0, true);
    }

    /** @param array<string,mixed> $snapshot */
    public static function scored(
        float $score,
        float $maxScore,
        array $snapshot = [],
        ?int $grade = null,
        float $penalty = 0.0
    ): self {
        return new self($score, $maxScore, $grade, false, $snapshot, $penalty);
    }

    /**
     * Балл в процентах от максимума задания.
     *
     * Задание без максимума не считается невыполненным: иначе работа, где
     * у одного задания не задан балл, молча уронила бы процент у всего ученика.
     *
     * Процент считается по итогу без штрафа: штраф за лишние варианты — это
     * отдельная величина, и в проценте задания она уже учтена.
     */
    public function percent(): ?float
    {
        if ($this->maxScore <= 0.0) {
            return null;
        }

        $net = max(0.0, $this->score - $this->penalty);

        return round($net / $this->maxScore * 100, 2);
    }
}
