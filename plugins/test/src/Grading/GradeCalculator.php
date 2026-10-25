<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Grading;

use MyEdu\Core\Config;
use MyEdu\Grading\GradeAggregator;

/**
 * Перевод процента выполнения в оценку по критериям автора.
 *
 * Решение об оценке принимает ядро: класс держит `GradeAggregator` с теми же
 * критериями и спрашивает у него. Раньше здесь жила вторая копия правила —
 * сортировка шкалы, порог «минимального проходного», запасная двойка, — и
 * любое изменение правил оценивания приходилось вносить в два места: в ядро
 * и сюда. Разошлись бы они тихо: работа в журнале и оценка в разборе задания
 * считались бы по-разному.
 *
 * Подпись оценки — остаток прежнего показа, который заменил цвет. В разборе
 * она больше не показывается, но поле снимка сохраняется; когда-нибудь поле
 * уйдёт из снимка вместе с подписью, и тогда метод вернёт только оценку.
 *
 * Критерии задаёт автор теста; если он их не задал, берётся шкала из
 * конфигурации.
 */
final class GradeCalculator
{
    private GradeAggregator $aggregator;

    /** @var list<array{grade:int,min_percent:float,label:?string}> */
    private array $scale;

    private float $minimumPassPercent;

    /**
     * @param list<array{grade:int,min_percent:float,label:?string}> $rows
     */
    private function __construct(
        Config $config,
        ?array $criteria,
        array $rows,
        float $minimumPassPercent
    ) {
        $this->aggregator = GradeAggregator::create($config, $criteria);
        $this->scale = $rows;
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

        return new self($config, $criteria, $rows, (float) $config->float('grading.minimum_pass_percent', 0.0));
    }

    /**
     * Оценка и подпись для процента выполнения.
     *
     * Оценка приходит из ядра — то же правило, что сводит работу в журнале.
     * Подпись ищется в своих критериях по той же границе, ниже которой ядро
     * ставит двойку: подпись и оценка расходиться не должны даже тогда, когда
     * подпись никем не показана.
     *
     * @return array{grade:int,label:?string}
     */
    public function gradeFor(float $percent): array
    {
        if ($percent < $this->minimumPassPercent) {
            return ['grade' => 2, 'label' => null];
        }

        foreach ($this->scale as $row) {
            if ($percent >= $row['min_percent']) {
                return [
                    'grade' => $this->aggregator->gradeFor($percent)['grade'],
                    'label' => $row['label'],
                ];
            }
        }

        // Ни один критерий не покрыл процент — работа не сдана.
        return ['grade' => $this->aggregator->gradeFor($percent)['grade'], 'label' => null];
    }

    /**
     * Балл с округлением до сотых.
     *
     * Правило одно на проект и живёт в ядре; вызов отсюда оставлен, чтобы
     * плагин не тянул класс ядра в каждый свой файл.
     */
    public static function roundScore(float $value): float
    {
        return GradeAggregator::roundScore($value);
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
