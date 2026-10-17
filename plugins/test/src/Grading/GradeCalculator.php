<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Grading;

use MyEdu\Core\Config;

/**
 * Перевод процента выполнения в оценку по критериям автора.
 *
 * Критерии задаёт автор теста; если он их не задал, берётся шкала из
 * конфигурации. Решение принимает первая подходящая строка отсортированной
 * шкалы, поэтому строки могут идти в любом порядке.
 */
final class GradeCalculator
{
    /** @var list<array{grade:int,min_percent:float,label:?string}> */
    private array $scale;

    private float $minimumPassPercent;

    /**
     * @param list<array{grade:int,min_percent:float,label:?string}> $scale
     */
    private function __construct(array $scale, float $minimumPassPercent)
    {
        $this->scale = $scale;
        $this->minimumPassPercent = $minimumPassPercent;
    }

    /**
     * @param list<array<string,mixed>>|null $criteria готовая шкала: строки
     *        min_percent с оценкой. При пустой берётся `grading.default_scale`
     */
    public static function create(Config $config, ?array $criteria = null): self
    {
        $rows = self::readCriteria($criteria ?? []);

        if ($rows === []) {
            $rows = self::readCriteria($config->array('grading.default_scale'));
        }

        usort($rows, static fn (array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);

        return new self($rows, (float) $config->float('grading.minimum_pass_percent', 0.0));
    }

    /**
     * Оценка и подпись для процента выполнения.
     *
     * @return array{grade:int,label:?string}
     */
    public function gradeFor(float $percent): array
    {
        // Ниже минимального процента оценка двойка ставится принудительно:
        // иначе работа с почти пустыми ответами могла бы получить тройку
        // по критерию, который автор писал под другой случай.
        if ($percent < $this->minimumPassPercent) {
            return ['grade' => 2, 'label' => null];
        }

        foreach ($this->scale as $row) {
            if ($percent >= $row['min_percent']) {
                return ['grade' => $row['grade'], 'label' => $row['label']];
            }
        }

        // Ни один критерий не покрыл процент — работа не сдана.
        return ['grade' => 2, 'label' => null];
    }

    /**
     * Балл с округлением до сотых.
     *
     * Эпсилон перед округлением спасает от 1,9999999999 → 1.99: сложение
     * дробей даёт такое значение почти всегда.
     */
    public static function roundScore(float $value): float
    {
        return round($value + 1e-9, 2);
    }

    /**
     * @param list<array<string,mixed>> $criteria
     * @return list<array{grade:int,min_percent:float,label:?string}>
     */
    private static function readCriteria(array $criteria): array
    {
        $rows = [];

        foreach ($criteria as $row) {
            if (!is_array($row)) {
                continue;
            }

            $grade = (int) ($row['grade'] ?? 0);

            // Оценка вне 1..5 — опечатка автора, а не критерий.
            if ($grade < 1 || $grade > 5) {
                continue;
            }

            $label = $row['label'] ?? null;

            $rows[] = [
                'grade'       => $grade,
                'min_percent' => (float) ($row['min_percent'] ?? 0),
                'label'       => $label === null ? null : (string) $label,
            ];
        }

        return $rows;
    }
}