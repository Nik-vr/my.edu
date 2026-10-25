<?php

declare(strict_types=1);

/**
 * Модульные проверки сведения оценок работы.
 *
 * Сведение — единственное место, где легко тихо испортить математику, поэтому
 * оно проверяется без базы и без HTTP: правила владельца проекта (итог —
 * среднее по весам, а не по количеству позиций) должны выполняться всегда,
 * независимо от того, как написан конкретный плагин.
 *
 * Запуск: php tests/run_grade_aggregation_tests.php
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Core\Config;
use MyEdu\Grading\GradeAggregator;
use MyEdu\Plugin\ItemGrade;

final class Runner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public function is(bool $ok, string $description): void
    {
        if ($ok) {
            $this->passed++;
            echo "  \033[32m+\033[0m {$description}\n";
            return;
        }

        $this->failures[] = $this->group . ' → ' . $description;
        echo "  \033[31m-\033[0m {$description}\033[0m\n";
    }

    /**
     * Числа сравниваются с допуском: округление до сотых даёт расхождения
     * в последнем разряде, и это не ошибка, а округление.
     */
    public function near(float $expected, ?float $actual, string $description): void
    {
        $ok = $actual !== null && abs($actual - $expected) < 0.02;

        $this->is($ok, $description . ($ok ? '' : ' (ожидалось ' . $expected . ', получено ' . var_export($actual, true) . ')'));
    }

    public function report(): int
    {
        $total = $this->passed + count($this->failures);

        echo "\n" . str_repeat('-', 62) . "\n";

        if ($this->failures === []) {
            echo "\033[32mВсе проверки пройдены: {$this->passed}/{$total}\033[0m\n";
            return 0;
        }

        echo "\033[31mПровалено: " . count($this->failures) . "/{$total}\033[0m\n";
        foreach ($this->failures as $failure) {
            echo "  - {$failure}\n";
        }

        return 1;
    }
}

$t = new Runner();

$config = new Config([
    'grading' => [
        'default_scale' => [
            ['grade' => 5, 'min_percent' => 85.0, 'color' => 'success'],
            ['grade' => 4, 'min_percent' => 70.0, 'color' => 'primary'],
            ['grade' => 3, 'min_percent' => 50.0, 'color' => 'warning'],
            ['grade' => 2, 'min_percent' => 0.0,  'color' => 'danger'],
        ],
        'minimum_pass_percent' => 25.0,
    ],
]);

$aggregator = GradeAggregator::create($config);

/**
 * Позиция работы с результатом.
 *
 * @return array<string,mixed>
 */
$item = static function (
    int $position,
    float $weight,
    ?float $score,
    float $maxScore,
    bool $needsReview = false,
    ?int $grade = null
): array {
    return [
        'id'            => $position,
        'position'      => $position,
        'weight'        => $weight,
        'max_score'     => $maxScore,
        'score'         => $score,
        'grade'         => $grade,
        'result_id'     => $needsReview ? null : $position,
        'result_status' => $needsReview ? 'pending' : ($score >= $maxScore ? 'correct' : 'partial'),
        'needs_review'  => $needsReview ? 1 : 0,
        'comment'       => '',
        'plugin_id'     => 'test',
        'task_kind'     => 'test',
        'task_id'       => $position,
    ];
};

// ------------------------------------------------------------------ веса

$t->group('Итог — среднее по весам, а не по количеству');

/*
 * Решающий пример: одно задание на 10 баллов с весом 3 и одно на 10 с весом 1.
 * Среднее по количеству дало бы 50%, среднее по весам — 75%: (10·3 + 0·1) /
 * (10·3 + 10·1) = 30/40.
 */
$weighted = $aggregator->aggregate([
    $item(1, 3.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
]);

$t->near(75.0, $weighted->percent, 'вес 3 против веса 1 даёт 75%, а не 50%');
$t->is($weighted->grade === 4, 'по критериям 75% — это четвёрка');
$t->near(10.0, $weighted->score, 'баллы позиций сложены: 10 + 0');
$t->near(20.0, $weighted->maxScore, 'максимум работы — сумма максимумов позиций');

$equal = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
]);

$t->near(50.0, $equal->percent, 'при равных весах это обычное среднее');
$t->is($equal->grade === 3, '50% по шкале автора — тройка');

$threeWeights = $aggregator->aggregate([
    $item(1, 3.0, 10.0, 10.0),
    $item(2, 2.0, 5.0, 10.0),
    $item(3, 1.0, 0.0, 10.0),
]);

// (30 + 10 + 0) / (30 + 20 + 10) = 40 / 60 = 66.67%
$t->near(66.67, $threeWeights->percent, 'три позиции разного веса считаются по весам');
$t->is($threeWeights->grade === 3, '66.67% — это тройка по критериям автора');

// ------------------------------------------------------------------ дробные веса

$t->group('Дробные веса');

$fractions = $aggregator->aggregate([
    $item(1, 0.5, 10.0, 10.0),
    $item(2, 1.5, 0.0, 10.0),
]);

// (5 + 0) / (5 + 15) = 25%
$t->near(25.0, $fractions->percent, 'веса 0.5 и 1.5 дают 25%');

// ------------------------------------------------------------------ позиции без балла

$t->group('Позиции без балла не роняют итог');

/*
 * Пропущенный текстовый ответ с максимумом 10 б. не должен уменьшать процент
 * у позиций, которые проверены: иначе один незаполненный вопрос обнулял бы
 * всю работу.
 */
$skipped = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
]);

$t->near(50.0, $skipped->percent, 'оценённая позиция учитывается полностью');

/*
 * Позиция, у которой вообще не задан максимум, исключается из знаменателя:
 * иначе работа, где у одного задания не указан балл, молча уронила бы процент
 * у всего ученика.
 */
$noMax = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    ['id' => 2, 'position' => 2, 'weight' => 1.0, 'max_score' => 0.0, 'score' => null,
     'grade' => null, 'result_id' => 2, 'result_status' => 'correct',
     'needs_review' => 0, 'comment' => '', 'plugin_id' => 'test', 'task_kind' => 'test', 'task_id' => 2],
]);

$t->near(100.0, $noMax->percent, 'позиция без максимума не входит в знаменатель');

// ------------------------------------------------------------------ ожидание проверки

$t->group('Ожидание проверки учителем');

$pending = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 1.0, null, 10.0, true),
]);

$t->is($pending->isPending(), 'работа с непроверенной позицией считается незавершённой');
$t->is($pending->pendingPositions() === [2], 'видно, какая именно позиция ждёт');
$t->near(100.0, $pending->percent, 'непроверенная позиция не искажает проверенный итог');
$t->is($pending->items()[1]['score'] === null, 'балл непроверенной позиции не показывается');

/*
 * Позиция в статусе pending, но без needs_review, — тот же случай: результат
 * есть, проверки нет.
 */
$pendingStatus = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    [
        'id' => 2, 'position' => 2, 'weight' => 1.0, 'max_score' => 10.0, 'score' => 5.0,
        'grade' => null, 'result_id' => 2, 'result_status' => 'pending', 'needs_review' => 0,
        'comment' => '', 'plugin_id' => 'test', 'task_kind' => 'test', 'task_id' => 2,
    ],
]);

$t->is($pendingStatus->isPending(), 'позиция в статусе pending тоже ждёт проверки');

// ------------------------------------------------------------------ отдельная оценка

$t->group('Режим «отдельная оценка за задание»');

/*
 * Первая позиция на 4 балла, вторая на 4. Среднее по весам позиций равно 50%,
 * но у каждого задания своя процентная доля 50%, то есть тройка каждая, и их
 * среднее тоже тройка. Разница видна на другой паре.
 */
/*
 * Одно задание на 10 баллов с весом 1 и одно на 10 с весом 1: 10 из 20.
 * Взвешенный процент один и тот же в обоих режимах — веса равны.
 */
$equalWeights = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
], 'per_item');

$t->near(50.0, $equalWeights->percent, 'при равных весах процент одинаков в обоих режимах');

/*
 * Оценка в per_item — среднее по оценкам позиций: здесь (5 + 2) / 2 = 3.5,
 * то есть четвёрка. В weighted тот же набор даёт тройку по 50%.
 */
$t->is($equalWeights->grade === 4, 'per_item: среднее оценок 5 и 2 — четвёрка');
$t->is($aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
], 'weighted')->grade === 3, 'weighted: те же 50% — тройка');

/*
 * Настоящее различие режимов: первое задание на 8 из 10 (четвёрка), второе
 * ноль (двойка).
 *
 *   weighted — взвешенный процент 24/40 = 60%, это тройка;
 *   per_item — среднее оценок (4·3 + 2·1)/4 = 3.5, это четвёрка.
 *
 * Режимы обязаны расходиться здесь: иначе второй был бы незаметной копией
 * первого, а автор работы выбирал бы между ними вслепую.
 */
$differing = [
    $item(1, 3.0, 8.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
];

$perItemMode = $aggregator->aggregate($differing, 'per_item');
$weightedMode = $aggregator->aggregate($differing, 'weighted');

$t->near(60.0, $weightedMode->percent, 'взвешенный процент одинаков в обоих режимах');
$t->near(60.0, $perItemMode->percent, 'процент работы от режима не зависит');
$t->is($weightedMode->grade === 3, 'weighted: 60% — тройка');
$t->is($perItemMode->grade === 4, 'per_item: среднее по оценкам 3.5 — четвёрка');

/*
 * Взвешенность работает и в per_item: сильное задание с весом 3 тянет среднее
 * вверх, хотя второе задание провалено.
 */
/*
 * (5·3 + 2·1) / 4 = 4.25 — то есть четвёрка, а не пятёрка: взвешенность в
 * per_item работает, но сильный вес не отменяет проваленного задания.
 */
$weightedPull = $aggregator->aggregate([
    $item(1, 3.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
], 'per_item');

$t->is($weightedPull->grade === 4, 'в per_item среднее по оценкам тоже учитывает вес');

/*
 * Тот же набор с перенесённым весом: (5·1 + 2·3) / 4 = 2.75, то есть тройка.
 * Итог перестал зависеть от числа позиций — изменился только вес.
 */
$pulledDown = $aggregator->aggregate([
    $item(1, 1.0, 10.0, 10.0),
    $item(2, 3.0, 0.0, 10.0),
], 'per_item');

$t->is($pulledDown->grade === 3, 'перенос веса на проваленное задание меняет итог');

/*
 * Среднее оценок может округлиться до оценки, которой в шкале автора нет.
 *
 * Шкала ниже без двойки, обе позиции провалены: gradeFor ниже всех границ
 * отдаёт двойку, среднее (2 + 2) / 2 — тоже двойка, и её в шкале искать
 * бесполезно. Раньше colorFor() возвращал null из метода, объявленного
 * строкой, и сведение падало TypeError'ом вместо страницы результата.
 */
$noTwo = GradeAggregator::create($config, [
    ['grade' => 5, 'min_percent' => 95.0, 'color' => 'success'],
    ['grade' => 4, 'min_percent' => 75.0, 'color' => 'primary'],
    ['grade' => 3, 'min_percent' => 0.0,  'color' => 'warning'],
]);

$t->is($noTwo->colorFor(1) === 'primary' && $noTwo->colorFor(2) === 'danger',
    'оценке вне шкалы автора достаётся запасной цвет, а не null');

$outOfScale = $noTwo->aggregate([
    $item(1, 1.0, 0.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
], 'per_item');

$t->is($outOfScale->grade === 2, 'среднее оценок ниже границ шкалы — двойка, а не сбой');
$t->is($outOfScale->color === 'danger', 'цвет итога вне шкалы — запасной, сведение не падает');

// ------------------------------------------------------------------ шкала

$t->group('Шкала оценок автора');

$custom = GradeAggregator::create($config, [
    ['grade' => 5, 'min_percent' => 95.0, 'color' => 'success'],
    ['grade' => 4, 'min_percent' => 75.0, 'color' => 'primary'],
    ['grade' => 3, 'min_percent' => 0.0,  'color' => 'warning'],
]);

$customResult = $custom->aggregate([
    $item(1, 1.0, 9.0, 10.0),
]);

$t->is($customResult->grade === 4, 'критерии работы важнее шкалы по умолчанию');
$t->is($customResult->color === 'primary', 'цвет оценки взят из критериев автора');

$defaultResult = $aggregator->aggregate([$item(1, 1.0, 9.0, 10.0)]);
$t->is($defaultResult->grade === 5, 'без критериев берётся шкала по умолчанию');
$t->is($defaultResult->color === 'success', 'цвет по умолчанию соответствует оценке');

/*
 * Цвет приезжает из базы, а не из формы, поэтому приводится к палитре.
 *
 * Старые работы цвета не содержат, а править их ради нового поля никто не
 * станет: пустое и незнакомое значение должно давать тот же цвет, что и у
 * новой работы с дефолтной шкалой, иначе половина журнала выглядела бы
 * покрашенной, а половина — нет. Значение не из палитры в класс не попадает:
 * оно пришло из чужой правки в JSON, и такой строкой в `class` можно было бы
 * подсунуть в разметку что угодно.
 */
$legacy = GradeAggregator::create($config, [
    ['grade' => 5, 'min_percent' => 85.0],
    ['grade' => 4, 'min_percent' => 70.0],
    ['grade' => 3, 'min_percent' => 50.0],
    ['grade' => 2, 'min_percent' => 0.0,  'color' => 'не из палитры'],
]);

$t->is($legacy->aggregate([$item(1, 1.0, 9.0, 10.0)])->color === 'success',
    'работа без цвета в критериях красится как раньше');

$t->is($legacy->aggregate([$item(1, 1.0, 4.0, 10.0)])->color === 'danger',
    'незнакомый цвет не попадает в разметку: подставляется цвет оценки');

/*
 * Ниже минимального процента двойка ставится принудительно: критерий,
 * написанный автором под другой случай, не должен выдавать тройку за пустую
 * работу.
 */
/*
 * Ниже минимального процента двойка ставится принудительно. Критерий,
 * написанный автором под другой случай, не должен выдавать тройку за работу,
 * где ученик сделал пятую часть.
 */
$belowPass = $aggregator->aggregate([$item(1, 1.0, 2.0, 10.0)]);
$t->is($belowPass->grade === 2, '20% при минимальном проходе 25% — двойка');

$atPass = $aggregator->aggregate([$item(1, 1.0, 2.5, 10.0)]);
$t->is($atPass->grade === 2, '25% — граница прохода, двойка');

$abovePass = $aggregator->aggregate([$item(1, 1.0, 2.6, 10.0)]);
$t->is($abovePass->grade === 2, '26% выше прохода, но ниже 50% — двойка по шкале');

/*
 * Проверка минимального прохода сдвигает только нижнюю границу: шкала выше
 * прохода работает как обычно.
 */
$t->is($aggregator->aggregate([$item(1, 1.0, 5.0, 10.0)])->grade === 3, '50% — тройка по шкале');

// ------------------------------------------------------------------ границы

$t->group('Граничные значения');

$t->near(85.0, $aggregator->aggregate([$item(1, 1.0, 8.5, 10.0)])->percent, 'процент округляется');
$t->is($aggregator->aggregate([$item(1, 1.0, 8.5, 10.0)])->grade === 5, 'ровно 85% — пятёрка');
$t->is($aggregator->aggregate([$item(1, 1.0, 8.49, 10.0)])->grade === 4, 'чуть ниже 85% — четвёрка');

/*
 * Нулевой и отрицательный вес недопустимы: при нулевом позиция выпала бы из
 * сведения, а отрицательный переворачивал бы смысл балла. Считается 1.
 */
$zeroWeight = $aggregator->aggregate([
    $item(1, 0.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
]);

$t->near(50.0, $zeroWeight->percent, 'нулевой вес заменяется на 1');

$negativeWeight = $aggregator->aggregate([
    $item(1, -5.0, 10.0, 10.0),
    $item(2, 1.0, 0.0, 10.0),
]);

$t->near(50.0, $negativeWeight->percent, 'отрицательный вес заменяется на 1');

$empty = $aggregator->aggregate([]);
$t->is($empty->percent === null, 'пустая работа не имеет процента');
$t->is($empty->grade === null, 'пустая работа не имеет оценки');
$t->is($empty->maxScore === 0.0, 'максимум пустой работы равен нулю');

// ------------------------------------------------------------------ округление

$t->group('Округление баллов');

$t->near(2.0, GradeAggregator::roundScore(1.9999999999), 'эпсилон спасает от 1.99 вместо 2.00');
$t->near(2.01, GradeAggregator::roundScore(2.005), 'округление до сотых');
$t->near(0.0, GradeAggregator::roundScore(0.0), 'ноль остаётся нулём');

/*
 * Дробные баллы одного знаменателя почти всегда дают значение чуть меньше
 * нужного: 0.1 + 0.2 не равно 0.3 в двоичной записи, и без эпсилона процент
 * уезжал бы на сотые вниз.
 *
 * 3 из 9 — это ровно треть, то есть 33.33%, и ошибка на одну сотую была бы
 * видна ученику как «33.32%».
 */
$thirds = $aggregator->aggregate([
    $item(1, 1.0, 1.0, 3.0),
    $item(2, 1.0, 1.0, 3.0),
    $item(3, 1.0, 1.0, 3.0),
]);

$t->near(33.33, $thirds->percent, 'три трети дают ровно треть, а не 33.32%');
$t->near(3.0, $thirds->score, 'баллы сложены: 1 + 1 + 1');
$t->near(9.0, $thirds->maxScore, 'максимум сложен: 3 + 3 + 3');

/*
 * Проценты позиций при разных максимумах не должны складываться «в среднем
 * по количеству»: работа с заданиями на 1 и 9 баллов — это 10 из 20, то есть
 * ровно половина, а не (100% + 100%) / 2 по числу позиций.
 */
$unevenMax = $aggregator->aggregate([
    $item(1, 1.0, 1.0, 1.0),
    $item(2, 1.0, 9.0, 9.0),
]);

$t->near(100.0, $unevenMax->percent, 'полные ответы по обоим заданиям дают 100%');
$t->near(10.0, $unevenMax->maxScore, 'максимум работы — сумма максимумов позиций');

$unevenPartial = $aggregator->aggregate([
    $item(1, 1.0, 1.0, 1.0),
    $item(2, 1.0, 0.0, 9.0),
]);

// 1 из 10, а не «среднее из 100% и 0%».
$t->near(10.0, $unevenPartial->percent, 'сложение идёт по баллам, а не по среднему проценту');

// ------------------------------------------------------------------ сводка

$t->group('Сводка по позициям');

$rows = $aggregator->aggregate([
    $item(1, 2.0, 4.0, 5.0),
    $item(2, 1.0, null, 5.0, true),
])->items();

$t->is(count($rows) === 2, 'сводка содержит все позиции, включая непроверенную');
$t->near(80.0, $rows[0]['percent'], 'в сводке виден процент позиции');
$t->is($rows[1]['needs_review'] === true, 'в сводке помечена позиция на проверке');
$t->is($rows[1]['max_score'] === 5.0, 'максимум позиции известен даже до проверки');

$t->group('Контракт ItemGrade проверяет границы');

$invalidGradeRejected = false;

try {
    ItemGrade::scored(2.0, 1.0);
} catch (InvalidArgumentException) {
    $invalidGradeRejected = true;
}

$t->is($invalidGradeRejected, 'балл выше максимума отклоняется');

$nonFiniteRejected = false;

try {
    ItemGrade::scored(INF, 1.0);
} catch (InvalidArgumentException) {
    $nonFiniteRejected = true;
}

$t->is($nonFiniteRejected, 'бесконечный балл отклоняется');

$badGradeRejected = false;

try {
    ItemGrade::scored(0.0, 1.0, [], 6);
} catch (InvalidArgumentException) {
    $badGradeRejected = true;
}

$t->is($badGradeRejected, 'оценка вне диапазона 1–5 отклоняется');

exit($t->report());
