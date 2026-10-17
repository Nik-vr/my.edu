<?php

declare(strict_types=1);

namespace MyEdu\Grading;

/**
 * Итог работы: баллы, процент и оценка.
 *
 * Отдельный объект вместо массива, потому что итог возвращается сразу в
 * контроллер, в шаблон и в базу, а поля у него разные по смыслу: процент
 * может быть неизвестен (работа ждёт проверки), а сумма баллов — нет.
 */
final class AssignmentTotal
{
    /**
     * @param list<array<string,mixed>> $items сводка по позициям для показа
     * @param list<int>                $pendingPositions номера позиций, ждущих учителя
     */
    public function __construct(
        public readonly float $score,
        public readonly float $maxScore,
        public readonly ?float $percent,
public readonly ?int $grade,
    /**
     * Цвет оценки — ключ токена темы, а не готовый код.
     *
     * Раньше здесь была подпись словами («отлично»). Её место занял цвет:
     * журнал читается по нему с одного взгляда, а результат ученику
     * показывается той же краской, какой автор выбрал для этой оценки.
     */
    public readonly string $color,
        public readonly bool $complete,
        public readonly array $items,
        public readonly array $pendingPositions
    ) {
    }

    /** Работа ждёт проверки учителем: итог пока неполный. */
    public function isPending(): bool
    {
        return !$this->complete;
    }

    /** @return list<array<string,mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<int> */
    public function pendingPositions(): array
    {
        return $this->pendingPositions;
    }
}
