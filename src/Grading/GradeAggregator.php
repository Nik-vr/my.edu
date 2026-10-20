<?php

declare(strict_types=1);

namespace MyEdu\Grading;

use MyEdu\Core\Config;

/**
 * Сведение оценок по позициям работы.
 *
 * Решение владельца проекта: итог работы — среднее по весам, а не по
 * количеству позиций. Работа из одного задания с весом 3 и одного с весом 1
 * не должна давать 50% только потому, что позиций две.
 *
 * Два режима, выбирается автором работы в assignments.grade_mode:
 *
 *   weighted — единая оценка за всю работу. Считается взвешенный процент
 *              по всем позициям, и он один раз переводится в оценку по
 *              критериям работы.
 *
 *   per_item — отдельная оценка за каждое задание. У каждой позиции своя
 *              оценка по её собственному проценту, а общая выводится как
 *              среднее по весам из этих оценок.
 *
 * Позиции без результата в сведении не участвуют, но делают итог неполным:
 * пока учитель не проверил текстовый ответ, назвать работу проверенной нельзя.
 *
 * Ядро ничего не знает про способы проверки: на входе строки позиций с уже
 * посчитанными баллами, которые вернул плагин через ItemGrade.
 */
final class GradeAggregator
{
    /** @var list<array{grade:int,min_percent:float,color:string}> */
    private array $scale;

    private float $minimumPassPercent;

    /** @param list<array{grade:int,min_percent:float,color:string}> $scale */
    private function __construct(array $scale, float $minimumPassPercent)
    {
        $this->scale = $scale;
        $this->minimumPassPercent = $minimumPassPercent;
    }

    /**
     * @param list<array<string,mixed>>|null $criteria критерии работы автора
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
     * Сведение по позициям работы.
     *
     * @param list<array<string,mixed>> $items позиции вместе с их результатами
     *                                         (строки AssignmentRepository::resultsForAttempt)
     * @param string                     $mode  weighted или per_item
     * @param string                     $criteriaMode percent или score: по чему
     *                                              выбирается оценка
     * @param float                      $attemptPenalty штраф самой попытки: за опоздание
     *                                                и ручной
     */
    public function aggregate(
        array $items,
        string $mode = 'weighted',
        string $criteriaMode = 'percent',
        float $attemptPenalty = 0.0
    ): AssignmentTotal {
        /*
         * Баллы и максимум копятся дважды, и это не избыточность.
         *
         * Суммы нужны для показа учителю в привычном виде «10 из 20 баллов».
         * Взвешенные — для процента: он и есть результат работы, и считать
         * его по сумме баллов нельзя, иначе веса перестали бы что-то значить.
         *
         * Поэтому percent не равен score / maxScore, и это не ошибка:
         * процент хранится отдельно именно для того, чтобы быть взвешенным.
         */
        $score = 0.0;
        $maxScore = 0.0;

        $weightedScore = 0.0;
        $weightedMax = 0.0;

        $gradeWeight = 0.0;
        $gradeSum = 0.0;

        $complete = true;
        $pending = [];
        $rows = [];

        /*
         * Первый проход: собираются позиции и их результаты.
         *
         * Штраф позиции вычитается из её балла, но остаётся отдельным числом:
         * в сводке видно, что ученик заработал и сколько за это отняли.
         */
        $prepared = [];
        $itemPenaltyTotal = 0.0;

        foreach ($items as $item) {
            $position = (int) ($item['position'] ?? 0);
            $weight = self::weightOf($item);

            $resultId = $item['result_id'] ?? null;
            $needsReview = (int) ($item['needs_review'] ?? 0) === 1
                || $resultId === null
                || ($item['result_status'] ?? null) === 'pending';

            if ($needsReview) {
                $complete = false;
                $pending[] = $position;
            }

            $itemMax = (float) ($item['max_score'] ?? 0.0);
            $itemScore = $needsReview ? 0.0 : (float) ($item['score'] ?? 0.0);
            $itemPenalty = $needsReview ? 0.0 : max(0.0, (float) ($item['penalty'] ?? 0.0));

            $prepared[] = [
                'position'     => $position,
                'weight'       => $weight,
                'score'        => $itemScore,
                'max_score'    => $itemMax,
                'penalty'      => $itemPenalty,
                'net'          => max(0.0, $itemScore - $itemPenalty),
                'needs_review' => $needsReview,
                'grade'        => $item['grade'] ?? null,
                'comment'      => (string) ($item['comment'] ?? ''),
                'plugin_id'    => (string) ($item['plugin_id'] ?? ''),
                'task_kind'    => (string) ($item['task_kind'] ?? ''),
                'task_id'      => (int) ($item['task_id'] ?? 0),
            ];

            $itemPenaltyTotal += $itemPenalty;
        }

        /*
         * Штраф попытки — за опоздание и ручной — распределяется по позициям
         * пропорционально заработанному.
         *
         * Процент работы взвешенный, а штраф задан в баллах, и делить одно на
         * другое напрямую нельзя. Распределение по заработанному решает это:
         * каждый балл штрафа срезает свою долю из каждой позиции, поэтому и
         * сумма баллов, и процент уменьшаются на одну и ту же долю, и в сводке
         * всё сходится: «набрано, штраф, итого».
         */
        $attemptPenalty = max(0.0, $attemptPenalty);
        $netTotal = 0.0;

        foreach ($prepared as $position) {
            $netTotal += $position['net'];
        }

        foreach ($prepared as $position) {
            $itemMax = $position['max_score'];
            $itemScore = $position['score'];
            $needsReview = $position['needs_review'];

            $share = $attemptPenalty > 0.0 && $netTotal > 0.0
                ? $attemptPenalty * ($position['net'] / $netTotal)
                : 0.0;

            $penalty = $position['penalty'] + $share;
            $net = max(0.0, $position['net'] - $share);

            $rows[] = [
                'position'     => $position['position'],
                'weight'       => $position['weight'],
                'score'        => $needsReview ? null : $itemScore,
                'max_score'    => $itemMax > 0.0 ? $itemMax : null,
                'penalty'      => $penalty,
                'net_score'    => $needsReview ? null : $net,
                'percent'      => $itemMax > 0.0 ? round($net / $itemMax * 100, 2) : null,
                'grade'        => $needsReview ? null : $this->asGrade($position['grade']),
                'needs_review' => $needsReview,
                'comment'      => $position['comment'],
                'plugin_id'    => $position['plugin_id'],
                'task_kind'    => $position['task_kind'],
                'task_id'      => $position['task_id'],
            ];

            /*
             * Позиция без балла не должна ронять процент у всех остальных:
             * пропущенный текстовый ответ — это ноль по своей позиции, а не
             * минус вес в знаменателе. Поэтому в сведении участвуют только
             * позиции, у которых максимум известен.
             */
            if ($needsReview || $itemMax <= 0.0) {
                continue;
            }

            $score += $itemScore;
            $maxScore += $itemMax;

            $weightedScore += $net * $position['weight'];
            $weightedMax += $itemMax * $position['weight'];

            if ($mode === 'per_item') {
                $verdict = $this->gradeFor(round($net / $itemMax * 100, 2));

                $gradeSum += $verdict['grade'] * $position['weight'];
                $gradeWeight += $position['weight'];
            }
        }

        $percent = $weightedMax > 0.0
            ? round($weightedScore / $weightedMax * 100, 2)
            : null;

        $grade = null;
        $color = GradeScaleValidator::normalizeColor(null, 2);

        if ($criteriaMode === 'score') {
            /*
             * Режим баллов: оценка берётся из набранных баллов, а не из
             * процента.
             *
             * Взвешивание при этом не выключается — оно решает, сколько баллов
             * стоит каждое задание в работе, и сумма баллов уже отражает эти
             * веса. Процент здесь показывается рядом как справочная величина,
             * но оценку не определяет: иначе выбранная учителем шкала в баллах
             * означала бы ровно то же, что процентная, и различаться они
             * перестали бы вовсе.
             */
            $net = max(0.0, $score - $itemPenaltyTotal - $attemptPenalty);
            $verdict = $this->gradeForScore($net);
            $grade = $verdict['grade'];
            $color = $verdict['color'];
        } elseif ($percent !== null) {
            $verdict = $mode === 'per_item' && $gradeWeight > 0.0
                ? $this->gradeFromGrades((int) round($gradeSum / $gradeWeight))
                : $this->gradeFor($percent);

            $grade = $verdict['grade'];
            $color = $verdict['color'];
        }

        return new AssignmentTotal(
            round($score, 2),
            round($maxScore, 2),
            $percent,
            $grade,
            $color,
            $complete,
            $rows,
            $pending,
            round($itemPenaltyTotal + $attemptPenalty, 2)
        );
    }

    /**
     * Оценка по проценту выполнения.
     *
     * @return array{grade:int,color:string}
     */
    public function gradeFor(float $percent): array
    {
        if ($percent < $this->minimumPassPercent) {
            return ['grade' => 2, 'color' => GradeScaleValidator::normalizeColor(null, 2)];
        }

        foreach ($this->scale as $row) {
            if ($percent >= $row['min_percent']) {
                return ['grade' => $row['grade'], 'color' => $row['color']];
            }
        }

        return ['grade' => 2, 'color' => GradeScaleValidator::normalizeColor(null, 2)];
    }

    /**
     * Оценка по набранным баллам.
     *
     * Отдельный метод, а не переключение внутри gradeFor(): в режиме баллов
     * границы лежат в другом поле, и работа, где их нет, обязана получить
     * шкалу по умолчанию, а не молчаливые нули. Считать по проценту в этом
     * случае нельзя: сумма баллов может быть и 8 из 8, и 8 из 80, и процент
     * здесь сказал бы не о том, о чём говорит выбранная учителем шкала.
     *
     * Верхняя граница учитывается, когда она задана. Без неё поведение прежнее:
     * подходит первая строка, нижняя граница которой не превышена.
     *
     * @return array{grade:int,color:string}
     */
    public function gradeForScore(float $score): array
    {
        $rows = [];

        foreach ($this->scale as $row) {
            if ($row['min_score'] > 0.0) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            // Балловых границ нет — работа заведена в этом режиме, а шкала
            // осталась процентной. Считать по ней нельзя: процент посчитал бы
            // не то, что выбрал учитель. Берётся шкала по умолчанию, и это
            // единственный случай, где молчание оправдано.
            return ['grade' => 2, 'color' => GradeScaleValidator::normalizeColor(null, 2)];
        }

        foreach ($rows as $row) {
            if ($score < (float) $row['min_score']) {
                continue;
            }

            if ($row['max_score'] !== null && $score > (float) $row['max_score']) {
                // Выше верхней границы этой оценки: строка с меньшей нижней
                // границей подойдёт, а эта — нет.
                continue;
            }

            return ['grade' => $row['grade'], 'color' => $row['color']];
        }

        return ['grade' => 2, 'color' => GradeScaleValidator::normalizeColor(null, 2)];
    }

    /**
     * Цвет оценки — по шкале автора.
     */
    public function colorFor(int $grade): string
    {
        foreach ($this->scale as $row) {
            if ($row['grade'] === $grade) {
                return $row['color'];
            }
        }

        return null;
    }

    /** @return list<array{grade:int,min_percent:float,color:string}> */
    public function scale(): array
    {
        return $this->scale;
    }

    /** Балл с округлением до сотых, эпсилон спасает от 1.9999999999 → 1.99. */
    public static function roundScore(float $value): float
    {
        return round($value + 1e-9, 2);
    }

    /**
     * Средняя оценка по весам переводится обратно в шкалу автора.
     *
     * Так итог остаётся оценкой из той же шкалы, а не дробным числом:
     * 4.33 — не оценка, а среднее, которое никто не поставит в журнал.
     *
     * @return array{grade:int,color:string}
     */
    private function gradeFromGrades(float $average): array
    {
        $rounded = (int) round($average);
        $rounded = max(1, min(5, $rounded));

        return ['grade' => $rounded, 'color' => $this->colorFor($rounded)];
    }

    /**
     * Вес позиции.
     *
     * Ноль и отрицательные веса недопустимы: при нулевом весе позиция выпала бы
     * из сведения совсем, а отрицательный переворачивал бы смысл балла. Такое
     * считаем обычным весом 1, а не молчаливый ноль.
     *
     * @param array<string,mixed> $item
     */
    private static function weightOf(array $item): float
    {
        $weight = (float) ($item['weight'] ?? 1.0);

        return $weight > 0.0 ? $weight : 1.0;
    }

    private function asGrade(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $grade = (int) $value;

        return $grade >= 1 && $grade <= 5 ? $grade : null;
    }

    /**
     * @param list<array<string,mixed>> $criteria
     * @return list<array{grade:int,min_percent:float,min_score:float,color:string}>
     */
    private static function readCriteria(array $criteria): array
    {
        $rows = [];

        foreach ($criteria as $row) {
            if (!is_array($row)) {
                continue;
            }

            $grade = (int) ($row['grade'] ?? 0);

            if ($grade < 1 || $grade > 5) {
                continue;
            }

            $color = GradeScaleValidator::normalizeColor($row['color'] ?? null, $grade);

            $rows[] = [
                'grade'       => $grade,
                'min_percent' => (float) ($row['min_percent'] ?? 0),
                /*
                 * Граница в баллах хранится под своим именем и по умолчанию
                 * ноль. Ноль здесь не «нет границы», а «границы в баллах у этой
                 * строки нет» — такой критерий в режиме баллов не применяется,
                 * и это видно, а не спрятано значением минус один.
                 */
                'min_score'   => array_key_exists('min_score', $row) ? (float) $row['min_score'] : 0.0,
                /*
                 * Верхняя граница в баллах. Её может не быть: работы, заведённые
                 * до появления явных диапазонов, хранят только нижнюю. Тогда
                 * верхняя берётся у следующей, более высокой оценки — как и
                 * раньше, иначе старая работа осталась бы без верхней границы
                 * и получала бы двойку за полный балл.
                 */
                'max_score'   => array_key_exists('max_score', $row) ? (float) $row['max_score'] : null,
                'color'       => $color,
            ];
        }

        return $rows;
    }
}
