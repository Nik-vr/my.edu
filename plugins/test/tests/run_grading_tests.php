<?php

declare(strict_types=1);

/**
 * Автономные тесты движка проверки ответов.
 *
 * Запуск: php plugins/test/tests/run_grading_tests.php
 *
 * PHPUnit не используется намеренно: проект не должен требовать Composer
 * ни при разработке, ни на сервере. Свой раннер держит зависимости равными нулю.
 *
 * Здесь нет подключения к базе — проверяется чистая логика плагина.
 */

use MyEdu\Core\Config;
use MyEdu\Grading\GradeAggregator;
use MyEdu\Grading\GradeScaleValidator;
use MyEduPlugin\Test\Grading\AttemptGrader;
use MyEduPlugin\Test\Grading\GradeCalculator;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;
use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\NumberParser;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot . '/src/Core/Autoloader.php';

// Ядро и плагин в одном загрузчике: тесты проверяют плагин, но используют
// и общие части ядра.
$autoloader = MyEdu\Core\Autoloader::create($projectRoot);
$autoloader->addNamespace('MyEduPlugin\\Test\\', dirname(__DIR__) . '/src');

// ------------------------------------------------------------------ мини-раннер

final class TestRunner
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

 public function is(bool $condition, string $description): void
 {
 if ($condition) {
 $this->passed++;
 echo " \033[32m✓\033[0m {$description}\n";
 return;
 }

 $this->failures[] = $this->group . ' → ' . $description;
 echo " \033[31m✗ {$description}\033[0m\n";
 }

 public function equals(mixed $expected, mixed $actual, string $description): void
 {
 $ok = $expected === $actual;

 if (!$ok) {
 $description .= sprintf(
 ' (ожидалось %s, получено %s)',
 var_export($expected, true),
 var_export($actual, true)
 );
 }

 $this->is($ok, $description);
 }

 /**
 * Проверка, что код отверг настройки и объяснил почему.
 *
 * Проверяется именно класс исключения: молчаливое принятие неверной
 * настройки хуже явной ошибки — ошибку заметят, а молчание приведёт к
 * тому, что вопрос будет проверяться не так, как задумано.
 */
 public function throws(callable $callback, string $expectedClass, string $description): void
 {
 try {
 $callback();
 } catch (\Throwable $e) {
 $this->is(
 $e instanceof $expectedClass,
 $description . ($e instanceof $expectedClass ? '' : ' (получено ' . $e::class . ')')
 );

 return;
 }

 $this->is(false, $description . ' (исключения не было)');
 }

 public function report(): int
 {
 $total = $this->passed + count($this->failures);

 echo "\n" . str_repeat('─', 60) . "\n";

 if ($this->failures === []) {
 echo "\033[32mВсе проверки пройдены: {$this->passed}/{$total}\033[0m\n";
 return 0;
 }

 echo "\033[31mПровалено: " . count($this->failures) . "/{$total}\033[0m\n";
 foreach ($this->failures as $failure) {
 echo " - {$failure}\n";
 }

 return 1;
 }
}

$t = new TestRunner();
$registry = QuestionTypeRegistry::instance();
$config = new Config([
 'grading' => [
 'default_scale' => [
 ['grade' => 5, 'min_percent' => 85.0, 'label' => 'отлично'],
 ['grade' => 4, 'min_percent' => 70.0, 'label' => 'хорошо'],
 ['grade' => 3, 'min_percent' => 50.0, 'label' => 'удовл.'],
 ['grade' => 2, 'min_percent' => 0.0, 'label' => 'неудовл.'],
 ],
 'minimum_pass_percent' => 25.0,
 ],
]);

$grader = new AttemptGrader($registry, 4000);

/**
 * Сборка вопроса нужного типа без обращения к БД.
 *
 * id обязателен: AttemptGrader ищет ответы по нему, поэтому у всех вопросов
 * в наборе должны быть разные идентификаторы.
 *
 * @param array<string,mixed> $config
 * @param list<array<string,mixed>> $options
 */
$makeQuestion = static function (
 string $type,
 array $config,
 array $options,
 float $weight = 1.0,
 int $id = 1,
 bool $required = true
): Question {
 return new Question(
 id: $id,
 type: $type,
 prompt: 'Вопрос',
 weight: $weight,
 isRequired: $required,
 explanation: null,
 config: $config,
 options: $options
 );
};

/** @param list<string> $keys */
$opts = static function (array $keys, string $kind = 'option'): array {
 $result = [];
 foreach ($keys as $i => $key) {
 $result[] = [
 'kind' => $kind,
 'value_key' => $key,
 'text' => "текст {$key}",
 'position' => $i,
 'pair_key' => null,
 ];
 }

 return $result;
};

// ------------------------------------------------------------------ 0. Обращение по имени

$t->group('Обращение пользователя по имени');

$makeUser = static function (string $fullName): \MyEdu\Auth\User {
 return \MyEdu\Auth\User::fromRow([
 'id' => 1,
 'login' => 'x@y.ru',
 'email' => 'x@y.ru',
 'full_name' => $fullName,
 'is_owner' => 0,
 'status' => 'active',
 ], ['teacher']);
};

$t->equals('Николай Юрьевич', $makeUser('Петроченко Николай Юрьевич')->shortName(),
 'обращение строится из имени и отчества');
$t->equals('Иван', $makeUser('Петров Иван')->shortName(),
 'без отчества остаётся только имя — выдумывать нечего');
$t->equals('Иван', $makeUser('Петров Иван ')->shortName(),
 'лишние пробелы в конце не ломают разбор');
$t->equals('Мария Сергеевна', $makeUser("Петрова\nМария\nСергеевна")->shortName(),
 'переводы строк вместо пробелов разбираются как разделители');
$t->equals('Петров', $makeUser('Петров')->shortName(),
 'одна часть — показывается полное имя');

// ------------------------------------------------------------------ 1. Один ответ

$t->group('Вопрос с выбором одного ответа');

$q = $makeQuestion('single_choice', ['correct' => 'b'], $opts(['a', 'b', 'c']));
$g = $q->payload();

$t->equals('b', $q->expectedText() === 'текст b' ? 'b' : '?', 'правильный ответ находится среди вариантов');
$t->equals(false, array_key_exists('correct', $g), 'в payload нет поля correct');
$t->equals(3, count($g['options']), 'в payload три варианта');

$t->equals(true, $registry->get('single_choice')->grade($q, 'b')->isCorrect, 'верный вариант засчитан');
$t->equals(1.0, $registry->get('single_choice')->grade($q, 'b')->score, 'полный балл за верный вариант');
$t->equals(false, $registry->get('single_choice')->grade($q, 'a')->isCorrect, 'неверный вариант не засчитан');
$t->equals(0.0, $registry->get('single_choice')->grade($q, 'a')->score, 'ноль за неверный вариант');
$t->equals(0.0, $registry->get('single_choice')->grade($q, null)->score, 'ноль за отсутствие ответа');
$t->equals(0.0, $registry->get('single_choice')->grade($q, 'z')->score, 'неизвестный ключ даёт ноль, а не исключение');
$t->equals(false, $registry->get('single_choice')->grade($q, 'z')->isCorrect, 'неизвестный ключ не считается верным');

// ------------------------------------------------------------------ 2. Несколько ответов

$t->group('Вопрос с выбором нескольких ответов');

$q = $makeQuestion('multiple_choice', ['correct' => ['a', 'c', 'd'], 'partial' => true, 'miss_penalty' => 0.5], $opts(['a', 'b', 'c', 'd', 'e']));
$type = $registry->get('multiple_choice');

$t->equals(1.0, $type->grade($q, ['a', 'c', 'd'])->score, 'полное совпадение = полный балл');
$t->equals(true, $type->grade($q, ['a', 'c', 'd'])->isCorrect, 'полное совпадение отмечено верным');

// 2 верных из 3, без лишних: 2 / 3 = 0.6667 → 0.67
$t->equals(0.67, $type->grade($q, ['a', 'c'])->score, 'частично верно, без штрафа');
$t->equals(0.0, $type->grade($q, ['a', 'c'])->penalty, 'без лишних штрафа нет');

/*
 * 2 верных + 1 лишний, штраф 0.5.
 *
 * Штраф отделён от балла: заработано 2 / 3 = 0.67, штраф за лишний выбор
 * (0.5 / 3) = 0.17, а вместе — те же 0.5, что и раньше, когда штраф резал балл
 * начисто. Разница в том, что теперь видно, из чего сложился результат.
 */
$missed = $type->grade($q, ['a', 'c', 'b']);

$t->equals(0.67, $missed->score, 'балл за верные варианты не срезан штрафом');
$t->equals(0.17, $missed->penalty, 'штраф за лишний выбор посчитан отдельно');
$t->equals(
    0.5,
    round($missed->score - $missed->penalty, 2),
    'вместе балл и штраф дают прежние 0.5'
);

// 3 лишних: заработано 0, штраф упирается в заработанное — итог не отрицательный
$t->equals(0.0, $type->grade($q, ['b', 'e', 'a'])->score, 'слишком много лишних даёт ноль, а не отрицательный балл');
$t->equals(0.0, $type->grade($q, ['b', 'e', 'a'])->penalty, 'штраф не превышает заработанного');

$t->equals(0.0, $type->grade($q, [])->score, 'пустой ответ даёт ноль');

$strict = $makeQuestion('multiple_choice', ['correct' => ['a', 'c'], 'partial' => false], $opts(['a', 'b', 'c', 'd']));
$t->equals(0.0, $registry->get('multiple_choice')->grade($strict, ['a'])->score, 'при partial=false только «всё или ничего»');
$t->equals(1.0, $registry->get('multiple_choice')->grade($strict, ['a', 'c'])->score, 'при partial=false полный набор даёт балл');

/*
 * В тесте, который видит ученик, не должно быть числа правильных
 * вариантов.
 *
 * Подсказка «Правильных: N» выдавала вопрос целиком: зная, что верных
 * два, и увидев три оставшихся варианта, ученик искал эти два, не читая
 * условие. Счётчик убран и из показа, и из подсказки — и то и другое
 * проверяется здесь, потому что ключ в payload и текст в шаблоне
 * связаны: второй без первого проверить негде.
 */
$mcPayload = $makeQuestion(
    'multiple_choice',
    ['correct' => ['a', 'c'], 'partial' => true],
    $opts(['a', 'b', 'c', 'd'])
)->payload();

$t->is(
    array_key_exists('correct_count', $mcPayload) === false,
    'в показе задания нет числа правильных вариантов'
);

$renderTemplate = (string) @file_get_contents($projectRoot . '/plugins/test/templates/render.php');

$t->is(
    str_contains($renderTemplate, 'Правильных:') === false,
    'в подсказке ученику не названо число правильных вариантов'
);

$weight = $makeQuestion('multiple_choice', ['correct' => ['a', 'c'], 'partial' => true, 'miss_penalty' => 0.5], $opts(['a', 'b', 'c', 'd']), 3.0);
// 1 из 2 верных → доля 0.5, вес 3.0 → 1.5
$t->equals(1.5, $registry->get('multiple_choice')->grade($weight, ['a'])->score, 'вес вопроса масштабирует частичный балл');

// ------------------------------------------------------------------ 3. Последовательность

$t->group('Вопрос с установлением последовательности');

$items = $opts(['c', 'a', 'd', 'b'], 'item');
$type = $registry->get('ordering');

$qExact = $makeQuestion('ordering', ['correct_order' => ['c', 'a', 'd', 'b'], 'partial' => 'positional'], $items);
$t->equals(1.0, $type->grade($qExact, ['c', 'a', 'd', 'b'])->score, 'точный порядок = полный балл');

// один элемент сдвинут на одну позицию: 2 из 4 на месте → 0.5
$t->equals(0.5, $type->grade($qExact, ['a', 'c', 'd', 'b'])->score, 'частичный порядок по позициям');

// полностью перевёрнутый порядок: 0 на месте
$t->equals(0.0, $type->grade($qExact, ['b', 'd', 'a', 'c'])->score, 'перевёрнутый порядок даёт ноль');

$t->equals(0.0, $type->grade($qExact, ['c', 'a'])->score, 'неполный ответ даёт ноль, а не частичный балл');
$t->equals(0.0, $type->grade($qExact, [])->score, 'пустой порядок даёт ноль');

$qAdj = $makeQuestion('ordering', ['correct_order' => ['c', 'a', 'd', 'b'], 'partial' => 'adjacent'], $items);
// верная соседняя пара только одна — (d,b) — из трёх: 1/3 ≈ 0.33
$t->equals(0.33, $type->grade($qAdj, ['a', 'c', 'd', 'b'])->score, 'режим adjacent считает верные соседние пары');

// правильная последовательность без одного элемента неполна и даёт ноль,
// независимо от режима частичного зачтения
$t->equals(0.0, $type->grade($qAdj, ['a', 'c', 'd'])->score, 'неполный порядок даёт ноль в любом режиме');

$qNone = $makeQuestion('ordering', ['correct_order' => ['c', 'a', 'd', 'b'], 'partial' => 'none'], $items);
$t->equals(0.0, $type->grade($qNone, ['a', 'c', 'd', 'b'])->score, 'режим none не даёт частичного балла');

// Безопасность: показанный ученику (перемешанный) порядок не должен проходить как верный.
$payload = $qExact->withRuntime(12345, false)->payload();
$shownOrder = array_column($payload['items'], 'value');
$t->equals(false, $shownOrder === ['c', 'a', 'd', 'b'], 'в payload элементы перемешаны, верный порядок не виден');
$t->equals(false, $type->grade($qExact, $shownOrder)->isCorrect, 'порядок «как показали» не засчитывается как верный');
$t->equals(1.0, $type->grade($qExact, ['c', 'a', 'd', 'b'])->score, 'верный порядок по-прежнему засчитывается полностью');

$repeat = $qExact->withRuntime(12345, false)->payload();
$t->equals($shownOrder, array_column($repeat['items'], 'value'), 'перемешивание детерминировано одним seed');

$otherOrder = array_column($qExact->withRuntime(999, false)->payload()['items'], 'value');
$sortedShown = $shownOrder; sort($sortedShown);
$sortedOther = $otherOrder; sort($sortedOther);
$t->equals($sortedShown, $sortedOther, 'перемешивание не теряет и не дублирует элементы');

// ------------------------------------------------------------------ 4. Соответствие

$t->group('Вопрос на установление соответствия');

$left = $opts(['l1', 'l2', 'l3', 'l4'], 'left');
$right = [];
foreach ([['r1', 'l3'], ['r2', 'l1'], ['r3', 'l2'], ['r4', 'l4']] as $i => [$key, $pair]) {
 $right[] = ['kind' => 'right', 'value_key' => $key, 'text' => "текст {$key}", 'position' => $i, 'pair_key' => $pair];
}
$matchOptions = array_merge($left, $right);

$type = $registry->get('matching');
$correctPairs = ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r1', 'l4' => 'r4'];
$q = $makeQuestion('matching', ['pairs' => $correctPairs, 'partial' => true], $matchOptions);

$t->equals(1.0, $type->grade($q, $correctPairs)->score, 'полное соответствие = полный балл');
$t->equals(4, count($type->grade($q, $correctPairs)->detail['hits'] ?? []), 'разбор отмечает все верные пары');

// Две верные пары, две неверные, каждый элемент правой колонки использован один раз
$t->equals(0.5, $type->grade($q, ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r4', 'l4' => 'r1'])->score, '2 из 4 пары засчитаны');

// Один элемент правой колонки назначен дважды: обе пары с ним неверны.
// Без этого правила здесь было бы 3/4 = 0.75, то есть ученик получил бы
// балл за случайное угадывание.
$t->equals(0.5, $type->grade($q, ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r1', 'l4' => 'r2'])->score, 'дублирование элемента не засчитывается дважды');

$t->equals(0.0, $type->grade($q, ['l1' => 'r3', 'l2' => 'r4', 'l3' => 'r2', 'l4' => 'r1'])->score, 'полностью неверное соответствие даёт ноль');
$t->equals(0.0, $type->grade($q, [])->score, 'пустое соответствие даёт ноль');

$qMatchStrict = $makeQuestion('matching', ['pairs' => $correctPairs, 'partial' => false], $matchOptions);
$t->equals(0.0, $type->grade($qMatchStrict, ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r4', 'l4' => 'r1'])->score, 'при partial=false нужны все пары');
$t->equals(1.0, $type->grade($qMatchStrict, $correctPairs)->score, 'при partial=false полное соответствие даёт балл');

$detail = $type->grade($q, ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r4', 'l4' => 'r1'])->detail;
$t->equals(true, $detail['errors'][0]['left'] === 'l3' ? true : ($detail['errors'][1]['left'] === 'l3'), 'в разборе указано, какие пары неверны');

/*
 * Настройки показа действуют и на соответствие.
 *
 * Поле «показывать N вариантов» раньше читалось только у одиночного и
 * множественного выбора: у соответствия автор видел его в настройках, а
 * ученик получал всю правую колонку подряд — настройка ничего не делала.
 * Галочка «перемешивать варианты ответа» читалась, но в показ не
 * попадала, так что тоже была обещанием, которое не выполняется.
 *
 * Лимит урезает правую колонку: левая — это условие, её урезать нельзя,
 * иначе пару составить не из чего. Поэтому верные партнёры видимых левых
 * элементов включаются всегда, а отсекаются только отвлекающие.
 */
$keptLeft = $opts(['l1', 'l2'], 'left');
$keptRight = [];

foreach ([['r1', 'l1'], ['r2', 'l2'], ['r3', null], ['r4', null], ['r5', null]] as $i => [$key, $pair]) {
    $keptRight[] = [
        'kind' => 'right',
        'value_key' => $key,
        'text' => "текст {$key}",
        'position' => $i,
        'pair_key' => $pair,
    ];
}

$qLimited = $makeQuestion('matching', [
    'pairs' => ['l1' => 'r1', 'l2' => 'r2'],
    'random_options' => 3,
], array_merge($keptLeft, $keptRight));

$limited = $qLimited->payload();
$limitedRight = array_column($limited['right'], 'value');

$t->equals(['l1', 'l2'], array_column($limited['left'], 'value'),
    'условие не урезается: оно и есть вопрос');
$t->equals(3, count($limitedRight),
    'правая колонка урезается до указанного числа');
$t->is(in_array('r1', $limitedRight, true) && in_array('r2', $limitedRight, true),
    'верные партнёры видимых условий показаны всегда — без них пару не составить');

$limitSorted = $limitedRight;
sort($limitSorted);

$t->is(
    count(array_diff($limitSorted, ['r1', 'r2', 'r3', 'r4', 'r5'])) === 0,
    'показаны только варианты этого вопроса'
);

/*
 * Перемешивание действует: порядок правой колонки меняется, состав —
 * тот же, а условие остаётся в порядке автора.
 *
 * Здесь лимита уже нет: с ним набор отвлекающих вариантов зависит от
 * seed'а, и состав показанного менялся бы закономерно — сверять его надо
 * было бы с полным списком, а не с прошлым разом. Проверяется несколько
 * seed'ами сразу: на пяти вариантах совпадение порядка возможно, и одна
 * проверка была бы проверкой на удачу.
 */
$qFree = $makeQuestion('matching', [
    'pairs' => ['l1' => 'r1', 'l2' => 'r2'],
], array_merge($keptLeft, $keptRight));

$plainRight = array_column($qFree->payload()['right'], 'value');

$allSorted = ['r1', 'r2', 'r3', 'r4', 'r5'];
$moved = false;

foreach ([777, 1001, 2002, 3003] as $seed) {
    $seeded = $qFree->withRuntime($seed, true)->payload();
    $seededRight = array_column($seeded['right'], 'value');
    $seededSorted = $seededRight;
    sort($seededSorted);

    $t->equals($allSorted, $seededSorted,
        'перемешивание не теряет и не дублирует варианты ответа');
    $t->equals(['l1', 'l2'], array_column($seeded['left'], 'value'),
        'условие остаётся в порядке автора');

    if ($seededRight !== $plainRight) {
        $moved = true;
    }
}

$t->is($moved, 'варианты ответа перемешиваются, а не показываются как написаны');

// ------------------------------------------------------------------ 5. Открытый ответ

$t->group('Открытый ответ: нормализация текста');

$type = $registry->get('text');

$qExact = $makeQuestion('text', [
 'strategy' => 'exact',
 'expected' => 'Фотосинтез',
 'variants' => ['фотосинтез', 'ФОТОСИНТЕЗ'],
 'ignore_case' => true,
], []);

$t->equals(true, $type->grade($qExact, 'Фотосинтез')->isCorrect, 'точное совпадение');
$t->equals(true, $type->grade($qExact, ' фотосинтез ')->isCorrect, 'лишние пробелы не мешают');
$t->equals(true, $type->grade($qExact, 'фотосинтез.')->isCorrect, 'точка в конце не мешает');
$t->equals(true, $type->grade($qExact, "ФОТОСИНТЕЗ\n")->isCorrect, 'регистр и перевод строки не мешают');
$t->equals(false, $type->grade($qExact, 'дыхание')->isCorrect, 'другое слово неверно');

$qYo = $makeQuestion('text', ['strategy' => 'exact', 'expected' => 'Ель', 'ignore_case' => true], []);
$t->equals(true, $type->grade($qYo, 'ЕЛЬ')->isCorrect, 'регистр кириллицы обрабатывается');
$t->equals(true, $type->grade($qYo, 'ёль')->isCorrect, 'ё приводится к е');
$t->equals(true, $type->grade($qYo, 'ЁЛЬ')->isCorrect, 'ё приводится к е независимо от регистра');

$qCase = $makeQuestion('text', ['strategy' => 'exact', 'expected' => 'ДНК', 'ignore_case' => false], []);
$t->equals(true, $type->grade($qCase, 'ДНК')->isCorrect, 'при ignore_case=false регистр значим (совпадение)');
$t->equals(false, $type->grade($qCase, 'днк')->isCorrect, 'при ignore_case=false регистр значим (различие)');

$qOrder = $makeQuestion('text', [
 'strategy' => 'exact',
 'expected' => 'кислород O2',
 'ignore_order'=> true,
], []);
$t->equals(true, $type->grade($qOrder, 'O2 кислород')->isCorrect, 'ignore_order допускает перестановку слов');
$t->equals(false, $type->grade($qOrder, 'O2 азот')->isCorrect, 'перестановка не маскирует другой ответ');

$t->group('Открытый ответ: стратегия keywords');

$qKw = $makeQuestion('text', [
 'strategy' => 'keywords',
 'expected' => 'Углекислый газ и вода',
 'required_keywords' => ['углекислый газ', 'вода'],
 'forbidden_keywords'=> ['кислород'],
 'ignore_case' => true,
 'partial' => true,
], []);

$t->equals(true, $type->grade($qKw, 'Углекислый газ и вода')->isCorrect, 'эталонный ответ верный');
$t->equals(true, $type->grade($qKw, 'вода и углекислый газ')->isCorrect, 'порядок ключевых слов не важен');
$t->equals(true, $type->grade($qKw, 'Для фотосинтеза нужны вода и углекислый газ.')->isCorrect, 'ключевые слова внутри предложения');
$t->equals(true, $type->grade($qKw, 'УГЛЕКИСЛЫЙ ГАЗ, ВОДА')->isCorrect, 'регистр ключевых слов не важен');

// Одно ключевое слово из двух: 1/2 = 0.5
$t->equals(0.5, $type->grade($qKw, 'Растению нужна вода')->score, 'одно ключевое слово из двух = половина балла');

$t->equals(0.0, $type->grade($qKw, 'Растению нужен кислород и вода')->score, 'запрещённое слово обнуляет балл, несмотря на воду');

// Граница слова: «вода» не должно находиться внутри «вода表» и наоборот;
// проверяем, что «вода» не ловится в слове «подводный» как отдельное.
$t->equals(false, $type->grade($qKw, 'подводный углекислый')->isCorrect, 'ключевое слово ищется по границам слов');

$t->group('Открытый ответ: стратегия contains');

$qContains = $makeQuestion('text', [
 'strategy' => 'contains',
 'variants' => ['Миролюбный'],
 'ignore_case' => true,
], []);
$t->equals(true, $type->grade($qContains, 'Человек Миролюбный.')->isCorrect, 'ответ содержит нужную фразу');
$t->equals(true, $type->grade($qContains, 'МИРОЛЮБНЫЙ народ')->isCorrect, 'регистр фразы не важен');
$t->equals(false, $type->grade($qContains, 'Он был миролюбен!')->isCorrect, 'окончание слова отличается — стемминг не применяется');
$t->equals(false, $type->grade($qContains, 'Он был злым.')->isCorrect, 'ответ без нужной фразы неверен');

// Обходной путь для учителя: явно перечислить допустимые окончания в variants.
$qContainsVariants = $makeQuestion('text', [
 'strategy' => 'contains',
 'variants' => ['миролюбный', 'миролюбен', 'миролюбна'],
], []);
$t->equals(true, $type->grade($qContainsVariants, 'Он был миролюбен!')->isCorrect, 'варианты окончаний перечисляются автором явно');

$t->group('Текстовый ответ: стратегия manual');

$qManual = $makeQuestion('text', ['strategy' => 'manual', 'expected' => 'что-то'], []);
$manual = $type->grade($qManual, 'мой ответ');
$t->equals(true, $manual->needsReview(), 'ручная проверка помечает вопрос как ожидающий учителя');
$t->equals(0.0, $manual->score, 'до проверки учителем балл не начисляется');
$t->equals(1.0, $type->grade($qManual, '')->maxScore, 'максимальный балл сохраняется');

$t->group('Текстовый ответ: ограничения ввода');

$t->equals("привет\nмир", $type->normalizeAnswer("привет\r\nмир"), 'CRLF приводится к LF');

// ------------------------------------------------------------------ 6. Числовой ответ

$t->group('Числовой ответ: строгое совпадение');

$numeric = $registry->get('numeric');

$qNum = $makeQuestion('numeric', [
 'mode' => 'exact',
 'expected' => 9.81,
 'tolerance' => 0.1,
 'unit' => 'м/с²',
], []);

$t->equals(true, $numeric->grade($qNum, '9.8')->isCorrect, 'число в пределах допуска');
$t->equals(true, $numeric->grade($qNum, '9,81 м/с²')->isCorrect, 'русская десятичная запятая и единица');
$t->equals(true, $numeric->grade($qNum, '≈ 9.9')->isCorrect, 'знак приблизительно не мешает разбору');
$t->equals(false, $numeric->grade($qNum, '10.5')->isCorrect, 'число вне допуска неверно');
$t->equals(false, $numeric->grade($qNum, 'много')->isCorrect, 'текст без числа неверно');
$t->equals(false, $numeric->grade($qNum, '')->isCorrect, 'пустой ответ неверно');

$t->group('Числовой ответ: строгое совпадение без допуска');

$qInt = $makeQuestion('numeric', ['mode' => 'exact', 'expected' => 56, 'tolerance' => 0, 'unit' => 'м/с'], []);

$t->equals(true, $numeric->grade($qInt, '56')->isCorrect, 'целое совпало');
$t->equals(true, $numeric->grade($qInt, '56 м/с')->isCorrect, 'целое с единицей измерения');
$t->equals(false, $numeric->grade($qInt, '56,5')->isCorrect, 'без допуска дробное не принимается');
$t->equals(false, $numeric->grade($qInt, '55')->isCorrect, 'соседнее целое не принимается');

$t->group('Числовой ответ: допустимый диапазон');

$qRange = $makeQuestion('numeric', [
 'mode' => 'range',
 'min' => 4.7,
 'max' => 5.3,
 'tolerance' => 0.05,
 'unit' => 'см',
], []);

$t->equals(true, $numeric->grade($qRange, '5')->isCorrect, 'значение внутри диапазона');
$t->equals(true, $numeric->grade($qRange, '4,75 см')->isCorrect, 'граница диапазона со знаком и единицей');
$t->equals(true, $numeric->grade($qRange, '5,28')->isCorrect, 'почти у верхней границы');
$t->equals(false, $numeric->grade($qRange, '4,6')->isCorrect, 'ниже диапазона неверно');
$t->equals(false, $numeric->grade($qRange, '5,5')->isCorrect, 'выше диапазона неверно');
$t->equals('от 4.7 до 5.3 см', $numeric->expectedText($qRange), 'в разборе показан диапазон, а не ответ');

$t->group('Числовой ответ: округление на этапах решения');

/*
 * Задача, где ученик округляет промежуточные значения: 200 − 100 + 40 = 140,
 * но при округлении до десятков на шаге вычитания получается 150. Диапазон
 * с запасом засчитывает и такой ответ — в этом весь смысл режима.
 */
$qRounded = $makeQuestion('numeric', [
 'mode' => 'range',
 'min' => 139,
 'max' => 141,
 'tolerance' => 0.5,
 'unit' => 'мл',
], []);

$t->equals(true, $numeric->grade($qRounded, '140')->isCorrect, 'точный ответ принят');
$t->equals(true, $numeric->grade($qRounded, '140,4 мл')->isCorrect, 'ответ с округлением внутри диапазона принят');
$t->equals(false, $numeric->grade($qRounded, '150')->isCorrect, 'ответ вне диапазона неверно');

$t->group('Числовой ответ: разбор ответа');

$t->equals(9.81, NumberParser::extract('9.81'), 'extract находит дробное число');
$t->equals(-3.0, NumberParser::extract('температура -3 градуса'), 'extract понимает отрицательное число');
$t->equals(20.0, NumberParser::extract('Ответ: 20'), 'extract берёт число из произвольного текста');
$t->equals(null, NumberParser::extract('нет чисел'), 'нет чисел — null');
$t->equals(20.0, NumberParser::strict('20 кг'), 'strict отбрасывает единицу измерения');
$t->equals(20.0, NumberParser::strict('20,0'), 'strict понимает запятую');
$t->equals(null, NumberParser::strict('20 кг и 30 кг'), 'strict не берёт первое из нескольких чисел');

$t->group('Числовой ответ: проверка настроек');

$t->throws(
 fn () => $numeric->validateConfig(['mode' => 'range', 'min' => 10, 'max' => 1], []),
 InvalidAnswerException::class,
 'диапазон с перепутанными границами отклонён'
);

$t->throws(
 fn () => $numeric->validateConfig(['mode' => 'exact', 'expected' => 'много'], []),
 InvalidAnswerException::class,
 'нечисловой эталон отклонён'
);

$t->equals('56 м/с', $numeric->expectedText($qInt), 'в разборе показан правильный ответ с единицей');
$t->equals('9.81 м/с²', $numeric->expectedText($qNum), 'дробный ответ без лишних нулей');
$t->equals('140', $numeric->answerText($qRounded, '140 мл'), 'ответ ученика показан числом');
$t->equals('— ответ не введён —', $numeric->answerText($qInt, ''), 'пустой ответ помечен');

// ------------------------------------------------------------------ 6. Проверка попытки

$t->group('Проверка попытки целиком');

$calculator = GradeCalculator::create($config);

$questions = [
 $makeQuestion('single_choice', ['correct' => 'b'], $opts(['a', 'b', 'c']), 1.0, 1),
 $makeQuestion('multiple_choice', ['correct' => ['a', 'c'], 'partial' => true, 'miss_penalty' => 0.5], $opts(['a', 'b', 'c', 'd']), 1.0, 2),
 $makeQuestion('ordering', ['correct_order' => ['c', 'a'], 'partial' => 'positional'], $opts(['c', 'a'], 'item'), 1.0, 3),
 $makeQuestion('text', ['strategy' => 'keywords', 'required_keywords' => ['вода'], 'expected' => 'вода'], [], 1.0, 4),
 $makeQuestion('matching', ['pairs' => ['l1' => 'r2', 'l2' => 'r1'], 'partial' => true],
 array_merge($opts(['l1', 'l2'], 'left'), $opts(['r1', 'r2'], 'right')), 1.0, 5),
];

// 5 вопросов по 1 баллу = 5 максимум
$answers = [
 1 => 'b', // верно → 1.0
 2 => ['a'], // 1 из 2 → 0.5
 3 => ['c', 'a'], // верно → 1.0
 4 => 'растениям нужна вода', // верно → 1.0
 5 => ['l1' => 'r2'], // 1 из 2 → 0.5
];
$result = $grader->grade($questions, $answers, $calculator);

// Вопросы 1, 3, 4 закрыты полностью; вопросы 2 и 5 — наполовину.
$t->equals(5.0, $result->maxScore, 'максимальный балл = сумма весов');
$t->equals(4.0, $result->score, 'итоговый балл с учётом частичных ответов');
$t->equals(80.0, $result->percent, 'процент выполнения');
$t->equals(4, $result->grade, '80% по шкале автора = оценка 4');
$t->equals(3, $result->countCorrect(), 'полностью верных вопросов');
$t->equals(2, $result->countPartial(), 'частично верных вопросов');
$t->equals(0, $result->countWrong(), 'неверных вопросов');
$t->equals(5, count($result->items), 'разбор содержит все вопросы');

$allRight = $grader->grade($questions, [
 1 => 'b',
 2 => ['a', 'c'],
 3 => ['c', 'a'],
 4 => 'вода',
 5 => ['l1' => 'r2', 'l2' => 'r1'],
], $calculator);
$t->equals(5.0, $allRight->score, 'все ответы верны = полный балл');
$t->equals(5, $allRight->grade, '100% = оценка 5');

$t->group('Проверка попытки: граничные случаи');

$threw = false;
try {
 $grader->grade($questions, [1 => 'b'], $calculator);
} catch (\MyEduPlugin\Test\Question\InvalidAnswerException) {
 $threw = true;
}
$t->equals(true, $threw, 'незаполненные обязательные вопросы приводят к исключению');

$lenient = $grader->grade($questions, [1 => 'b'], $calculator, false);
$t->equals(1.0, $lenient->score, 'без принудительного режима незаполненные вопросы дают 0');

// Мусорный формат не должен ронять проверку
$messy = $grader->grade($questions, [
 1 => ['unexpected' => 'array'],
 2 => 'a',
 3 => ['c', 'a'],
 4 => 'вода',
 5 => ['l1' => 'r2', 'l2' => 'r1'],
], $calculator, false);
$t->equals(3.5, $messy->score, 'неверный формат ответа считается пустым, проверка не падает');

$t->group('Проверка попытки: время и текст');

$longAnswer = str_repeat('я', 5000);
$trimmed = $grader->grade(
 [$makeQuestion('text', ['strategy' => 'manual', 'expected' => 'x'], [], 1.0, 1)],
 [1 => $longAnswer],
 $calculator
);
$t->equals(4000, mb_strlen((string) ($trimmed->items[0]['answer_data'] ?? ''), 'UTF-8'), 'слишком длинный текст обрезается до лимита');

// ------------------------------------------------------------------ 7. Оценки

$t->group('Расчёт оценки по критериям автора');

$t->equals(5, $calculator->gradeFor(100.0)['grade'], '100% = 5');
$t->equals(5, $calculator->gradeFor(85.0)['grade'], 'ровно порог 85% = 5');
$t->equals(4, $calculator->gradeFor(84.99)['grade'], 'чуть ниже порога = 4');
$t->equals(4, $calculator->gradeFor(70.0)['grade'], 'ровно порог 70% = 4');
$t->equals(3, $calculator->gradeFor(50.0)['grade'], 'ровно порог 50% = 3');
// Шкала по умолчанию покрывает 30% критерием «0% и выше = 2»: между 25% и 50%
// отдельного критерия нет, поэтому 30% — это двойка.
$t->equals(2, $calculator->gradeFor(30.0)['grade'], '30% по шкале по умолчанию = 2');
$t->equals(2, $calculator->gradeFor(25.0)['grade'], 'на минимальном пороге = 2');
$t->equals(2, $calculator->gradeFor(24.99)['grade'], 'ниже минимального порога = 2 принудительно');
$t->equals(2, GradeCalculator::create($config, [
 ['grade' => 5, 'min_percent' => 85.0, 'label' => null],
 ['grade' => 4, 'min_percent' => 70.0, 'label' => null],
 ['grade' => 3, 'min_percent' => 50.0, 'label' => null],
 ['grade' => 2, 'min_percent' => 40.0, 'label' => null],
])->gradeFor(30.0)['grade'], 'если ни один критерий не покрывает процент, выставляется 2');
$t->equals(2, $calculator->gradeFor(0.0)['grade'], '0% = 2');
$t->equals('отлично', $calculator->gradeFor(90.0)['label'], 'подпись оценки берётся из критериев');

$custom = GradeCalculator::create($config, [
 ['grade' => 5, 'min_percent' => 95.0, 'label' => 'отлично'],
 ['grade' => 4, 'min_percent' => 80.0, 'label' => 'хорошо'],
 ['grade' => 3, 'min_percent' => 60.0, 'label' => 'удовл.'],
 ['grade' => 2, 'min_percent' => 0.0, 'label' => 'неудовл.'],
]);
$t->equals(5, $custom->gradeFor(96.0)['grade'], 'свои критерии автора важнее шкалы по умолчанию');
$t->equals(4, $custom->gradeFor(90.0)['grade'], 'свои критерии: 90% = 4 при пороге 95%');
$t->equals(4, $custom->gradeFor(80.0)['grade'], 'свои критерии: порог 80%');
$t->equals(3, $custom->gradeFor(60.0)['grade'], 'свои критерии: порог 60%');
$t->equals(2, $custom->gradeFor(20.0)['grade'], 'свои критерии: ниже последнего порога = 2');

$t->group('Расчёт оценки: защита от нулевого максимума');

$empty = $grader->grade([], [], $calculator);
$t->equals(0.0, $empty->maxScore, 'пустой тест даёт нулевой максимум');
$t->equals(0.0, $empty->percent, 'нулевой максимум не вызывает деления на ноль');
$t->equals(2, $empty->grade, 'пустой тест даёт оценку 2, а не исключение');

$t->group('Округление баллов');

$t->equals(1.23, GradeCalculator::roundScore(1.2345), 'округление до сотых');
$t->equals(2.0, GradeCalculator::roundScore(1.9999999999), 'сложение с эпсилон защищает от 1.99 вместо 2.00');

// ------------------------------------------------------- проверка шкалы оценок

$t->group('Диапазоны шкалы оценок');

/*
 * Проверка идёт по правилам, а не по вёрстке: форма может подсветить поле, но
 * смысл в том, что неверная шкала не сохранится вовсе. Поэтому проверяется
 * сам валидатор на тех сочетаниях, которые учитель может набрать руками.
 */
$t->is(
 GradeScaleValidator::check(GradeScaleValidator::percentDefaults(), false)['ok'],
 'шкала по умолчанию в процентах проходит проверку'
);

$t->is(
 GradeScaleValidator::check(GradeScaleValidator::scoreDefaults(20.0), true, 20.0)['ok'],
 'шкала по умолчанию в баллах проходит проверку'
);

$t->equals(
 ['85', '70', '50', '0'],
 array_map(static fn (array $row): string => GradeScaleValidator::formatNumber($row['from']), GradeScaleValidator::percentDefaults()),
 'значения по умолчанию — те же, что были в подсказке'
);

/*
 * Равные границы дают пустой диапазон: оценка недостижима вовсе. Раньше форма
 * давала и такое, и оценка просто не ставилась никогда — без единого слова
 * учителю.
 */
$equalBounds = GradeScaleValidator::check([
 ['grade' => 5, 'from' => 70.0, 'to' => null],
 ['grade' => 4, 'from' => 70.0, 'to' => null],
 ['grade' => 3, 'from' => 50.0, 'to' => null],
 ['grade' => 2, 'from' => 0.0, 'to' => null],
], false);

$t->is(!$equalBounds['ok'], 'равные границы двух оценок не проходят');
$t->is(in_array(4, $equalBounds['badFrom'], true), 'подсвечено поле той оценки, чей диапазон пуст');

// Дыра внизу: ни одна оценка не покрывает результат ниже порога двойки.
$gapAtBottom = GradeScaleValidator::check([
 ['grade' => 5, 'from' => 85.0, 'to' => null],
 ['grade' => 4, 'from' => 70.0, 'to' => null],
 ['grade' => 3, 'from' => 50.0, 'to' => null],
 ['grade' => 2, 'from' => 30.0, 'to' => null],
], false);

$t->is(!$gapAtBottom['ok'], 'двойка не с нуля не проходит: под 30% не попадёт ни одна оценка');
$t->is(in_array(2, $gapAtBottom['badFrom'], true), 'подсвечено поле двойки');

// В баллах: зазор между диапазонами больше шага — дыра в половину балла.
$scoreGap = GradeScaleValidator::check([
 ['grade' => 5, 'from' => 15.0, 'to' => 20.0],
 ['grade' => 4, 'from' => 9.0, 'to' => 14.0],
 ['grade' => 3, 'from' => 5.0, 'to' => 8.5],
 ['grade' => 2, 'from' => 0.0, 'to' => 4.5],
], true, 20.0);

$t->is(!$scoreGap['ok'], 'зазор больше половины балла не проходит');
$t->is(in_array(4, $scoreGap['badTo'], true), 'подсвечена верхняя граница четвёрки');

// В баллах: пересечение диапазонов.
$scoreOverlap = GradeScaleValidator::check([
 ['grade' => 5, 'from' => 15.0, 'to' => 20.0],
 ['grade' => 4, 'from' => 10.0, 'to' => 16.0],
 ['grade' => 3, 'from' => 5.0, 'to' => 9.5],
 ['grade' => 2, 'from' => 0.0, 'to' => 4.5],
], true, 20.0);

$t->is(!$scoreOverlap['ok'], 'пересечение диапазонов не проходит');
$t->is(in_array(4, $scoreOverlap['badTo'], true), 'подсвечена верхняя граница пересекающейся оценки');

/*
 * Верхняя граница пятёрки обязана доставать до суммы цен: иначе работа,
 * выполненная полностью, не получала бы пятёрки, и это заметно было бы
 * только по разборам.
 */
$shortTop = GradeScaleValidator::check([
 ['grade' => 5, 'from' => 12.0, 'to' => 18.0],
 ['grade' => 4, 'from' => 9.0, 'to' => 11.5],
 ['grade' => 3, 'from' => 5.0, 'to' => 8.5],
 ['grade' => 2, 'from' => 0.0, 'to' => 4.5],
], true, 20.0);

$t->is(!$shortTop['ok'], 'верхняя граница пятёрки ниже суммы не проходит');
$t->is(in_array(5, $shortTop['badTo'], true), 'подсвечена верхняя граница пятёрки');

$t->is(!in_array(5, GradeScaleValidator::check([
 ['grade' => 5, 'from' => 15.0, 'to' => 20.0],
 ['grade' => 4, 'from' => 10.0, 'to' => 14.5],
 ['grade' => 3, 'from' => 5.0, 'to' => 9.5],
 ['grade' => 2, 'from' => 0.0, 'to' => 4.5],
], true, 20.0)['badTo'], true), 'корректная шкала ничем не подсвечена');

/*
 * Сумма в один балл: четыре диапазона не помещаются в половину балла, и
 * дефолт всё равно обязан быть допустимым — иначе учитель не смог бы сохранить
 * ничего и должен был бы догадаться, какие границы вбить.
 */
foreach ([0.0, 0.5, 1.0, 2.0, 2.5, 7.5, 20.0, 37.5] as $total) {
 $t->is(
 GradeScaleValidator::check(GradeScaleValidator::scoreDefaults($total), true, $total)['ok'],
 'шкала по умолчанию при сумме ' . GradeScaleValidator::formatNumber($total) . ' балла допустима'
 );
}

/*
 * Границы по умолчанию обязаны быть кратны шагу поля.
 *
 * Это не про «красивые числа», а про то, что форма их принимает. Поле в баллах
 * объявлено с шагом, браузер и проверка браузера отвергают число, не кратное
 * шагу, — и на заполненной форме человек получал «не отправлено: проверьте 4
 * пол.», отмечать было нечего, а значения стояли те, что подставил сервер.
 *
 * Деление суммы на четыре части давало четверти: при сумме 17 это 12.75 и 4.25,
 * а сверху ещё 12.25 и 3.75 — ровно четыре поля на любой работе, чья сумма не
 * делится на два.
 */
$gridOffenders = [];

foreach (range(1, 200) as $cents) {
 $total = $cents / 10;
 $grid = GradeScaleValidator::scoreGrid($total);

 foreach (GradeScaleValidator::scoreDefaults($total) as $row) {
  foreach (['from', 'to'] as $edge) {
   $steps = (float) $row[$edge] / $grid;

   if ((float) $row[$edge] < 0.0) {
    $gridOffenders[] = sprintf(
     'сумма %s: %s=%s отрицательно',
     GradeScaleValidator::formatNumber($total),
     $edge,
     GradeScaleValidator::formatNumber((float) $row[$edge])
    );
   } elseif (abs($steps - round($steps)) > 0.0001) {
    $gridOffenders[] = sprintf(
     'сумма %s: %s=%s не кратно шагу %s',
     GradeScaleValidator::formatNumber($total),
     $edge,
     GradeScaleValidator::formatNumber((float) $row[$edge]),
     GradeScaleValidator::formatNumber($grid)
    );
   }
  }

  if ($row['from'] > $row['to']) {
   $gridOffenders[] = sprintf(
    'сумма %s: диапазон оценки %s вывернут',
    GradeScaleValidator::formatNumber($total),
    GradeScaleValidator::formatNumber((float) $row['grade'])
   );
  }
 }
}

$t->is(
 $gridOffenders === [],
 'все границы по умолчанию кратны шагу своего поля, неотрицательны и не вывернуты'
 . ($gridOffenders === [] ? '' : '; ' . implode('; ', array_slice($gridOffenders, 0, 3)))
);

/*
 * Нижний диапазон начинается с нуля.
 *
 * Накопление округляет шаг вверх, и на суммах, не делящихся ровно на четыре
 * шага, остаток не съедался: у работы на 2.1 балла диапазон двойки начинался с
 * 0.1, и всё, что набрано ниже, не попадало ни в одну оценку.
 */
$bottomIsZero = true;
$bottomOffender = '';

foreach (range(1, 200) as $cents) {
 $total = $cents / 10;
 $rows = GradeScaleValidator::scoreDefaults($total);
 $bottom = $rows[count($rows) - 1];

 if ((float) $bottom['from'] !== 0.0) {
  $bottomIsZero = false;
  $bottomOffender = GradeScaleValidator::formatNumber($total) . ' → от ' . GradeScaleValidator::formatNumber((float) $bottom['from']);
 }
}

$t->is($bottomIsZero, 'диапазон самой низкой оценки начинается с нуля' . ($bottomOffender === '' ? '' : '; не так при ' . $bottomOffender));

/*
 * Верхняя граница самой высокой оценки равна сумме цен — это обещает подпись
 * под набором, и проверять надо обещание.
 */
$topIsTotal = true;
$topOffender = '';

foreach (range(1, 200) as $cents) {
 $total = $cents / 10;
 $rows = GradeScaleValidator::scoreDefaults($total);

 if (abs((float) $rows[0]['to'] - $total) > 0.0001) {
  $topIsTotal = false;
  $topOffender = GradeScaleValidator::formatNumber($total) . ' → до ' . GradeScaleValidator::formatNumber((float) $rows[0]['to']);
 }
}

$t->is($topIsTotal, 'верхняя граница пятёрки равна сумме цен' . ($topOffender === '' ? '' : '; не так при ' . $topOffender));

/* Шаг обязан делить сумму: верхняя граница равна сумме, и вне шага она не влезает. */
$gridDivides = true;
$gridOffender = '';

foreach (range(1, 200) as $cents) {
 $total = $cents / 10;
 $grid = GradeScaleValidator::scoreGrid($total);

 if (abs($total / $grid - round($total / $grid)) > 0.0001) {
  $gridDivides = false;
  $gridOffender = GradeScaleValidator::formatNumber($total) . ' не делится на ' . GradeScaleValidator::formatNumber($grid);
 }
}

$t->is($gridDivides, 'шаг поля делит сумму цен' . ($gridOffender === '' ? '' : '; не так: ' . $gridOffender));

// ------------------------------------------------------- оценка по баллам

$t->group('Оценка по явным диапазонам');

$appendedConfig = new Config(['grading' => ['default_scale' => [
 ['grade' => 5, 'min_percent' => 85.0, 'min_score' => 15.0, 'max_score' => 20.0],
 ['grade' => 4, 'min_percent' => 70.0, 'min_score' => 10.0, 'max_score' => 14.5],
 ['grade' => 3, 'min_percent' => 50.0, 'min_score' => 5.0, 'max_score' => 9.5],
 ['grade' => 2, 'min_percent' => 0.0, 'min_score' => 0.0, 'max_score' => 4.5],
]]]);

$appended = GradeAggregator::create($appendedConfig);

$t->equals(5, $appended->gradeForScore(20.0)['grade'], 'полный балл даёт пятёрку');
$t->equals(5, $appended->gradeForScore(15.0)['grade'], 'ровно нижняя граница пятёрки — пятёрка');
$t->equals(4, $appended->gradeForScore(14.5)['grade'], 'верхняя граница четвёрки — четвёрка');
$t->equals(4, $appended->gradeForScore(10.0)['grade'], 'ровно нижняя граница четвёрки — четвёрка');
$t->equals(3, $appended->gradeForScore(9.5)['grade'], 'верхняя граница тройки — тройка');
$t->equals(2, $appended->gradeForScore(4.5)['grade'], 'верхняя граница двойки — двойка');
$t->equals(2, $appended->gradeForScore(0.0)['grade'], 'ноль баллов — двойка');

/*
 * Без верхних границ поведение прежнее: подходит первая строка, нижняя
 * граница которой не превышена. Работы, заведённые до появления явных
 * диапазонов, не должны вдруг начать ставить двойки за полный балл.
 */
$legacyConfig = new Config(['grading' => ['default_scale' => [
 ['grade' => 5, 'min_percent' => 85.0, 'min_score' => 15.0],
 ['grade' => 4, 'min_percent' => 70.0, 'min_score' => 10.0],
 ['grade' => 3, 'min_percent' => 50.0, 'min_score' => 5.0],
 ['grade' => 2, 'min_percent' => 0.0, 'min_score' => 0.0],
]]]);

$legacy = GradeAggregator::create($legacyConfig);

$t->equals(5, $legacy->gradeForScore(20.0)['grade'], 'старая шкала без верхних границ даёт пятёрку за полный балл');
$t->equals(4, $legacy->gradeForScore(12.0)['grade'], 'старая шкала даёт четвёрку между границами');

/*
 * Сколько вариантов показывать ученику.
 *
 * Правило: показывается ровно N вариантов, но отмеченные как «обязательно
 * показывать» и правильный ответ попадают в выбор в любом случае. Иначе вопрос
 * останется без верного варианта и станет неразрешимым, а это молча ломает
 * проверку.
 */
$t->group('Выбор случайных вариантов ответа');

$variantOptions = [
    ['kind' => 'option', 'value_key' => 'a', 'text' => 'А', 'position' => 1, 'always_show' => 1],
    ['kind' => 'option', 'value_key' => 'b', 'text' => 'Б', 'position' => 2, 'always_show' => 0],
    ['kind' => 'option', 'value_key' => 'c', 'text' => 'В', 'position' => 3, 'always_show' => 0],
    ['kind' => 'option', 'value_key' => 'd', 'text' => 'Г', 'position' => 4, 'always_show' => 0],
];

$limited = new Question(
    1,
    'single_choice',
    'Вопрос',
    1.0,
    true,
    null,
    ['random_options' => 2, 'correct' => 'c'],
    $variantOptions
);

$shown = array_column($limited->visibleOptionsOfKind(Question::KIND_OPTION, ['c']), 'value_key');

$t->equals(2, count($shown), 'показано ровно столько вариантов, сколько велено');
$t->is(in_array('a', $shown, true), 'вариант с отметкой «обязательно показывать» не выпал');
$t->is(in_array('c', $shown, true), 'правильный ответ не выпал');
$t->is(count($shown) === count(array_unique($shown)), 'варианты не повторяются');

$all = new Question(1, 'single_choice', 'Вопрос', 1.0, true, null, ['random_options' => 0], $variantOptions);

$t->equals(
    4,
    count($all->visibleOptionsOfKind(Question::KIND_OPTION, ['c'])),
    'без числа показываются все варианты'
);

$t->equals(
    4,
    count($all->visibleOptionsOfKind(Question::KIND_OPTION, [])),
    'без числа показываются все варианты, даже если правильный не отмечен'
);

/*
 * Порядок показа — по правилу владельца (24.10.2026, задача 3):
 * «обязательно показываемые» идут в первую очередь, среди остальных
 * выбираются случайные.
 *
 * Без «перемешать варианты» выбранные стоят в исходном порядке — только
 * отбор случайный, а раскладка авторская. С перемешиванием состав тот же,
 * но порядок закрепляется за парой «попытка + задание» и не меняется от
 * перезагрузки: два открытия с одним seed обязаны дать один результат.
 *
 * withRuntime обязателен: и отбор, и порядок берут seed из рантайма — без
 * него выбор случайных падал бы на обращении к несуществующему ключу.
 */
$threeOfFour = new Question(
    1,
    'single_choice',
    'Вопрос',
    1.0,
    true,
    null,
    ['random_options' => 3, 'correct' => 'c'],
    $variantOptions
);

$plainPick = $threeOfFour->withRuntime(7, false)->visibleOptionsOfKind(Question::KIND_OPTION, ['c']);
$plainKeys = array_column($plainPick, 'value_key');

$t->equals(3, count($plainKeys), 'при лимите три показывается ровно три');
$t->equals('a', $plainKeys[0] ?? '', 'отмеченный «обязательно показывать» вариант идёт первым');
$t->is(in_array('c', $plainKeys, true), 'правильный ответ в показе');
$t->equals(
    count($plainKeys),
    count(array_unique($plainKeys)),
    'варианты не повторяются'
);
$t->equals(
    1,
    4 - count($plainKeys),
    'ровно один вариант из четырёх отпал'
);

$shuffledFirst = array_column(
    $threeOfFour->withRuntime(7, true)->visibleOptionsOfKind(Question::KIND_OPTION, ['c']),
    'value_key'
);
$shuffledAgain = array_column(
    $threeOfFour->withRuntime(7, true)->visibleOptionsOfKind(Question::KIND_OPTION, ['c']),
    'value_key'
);

$plainSorted = $plainKeys;
$shuffledSorted = $shuffledFirst;
sort($plainSorted);
sort($shuffledSorted);

$t->equals($plainSorted, $shuffledSorted,
    'перемешивание меняет порядок, но не состав выбора');
$t->equals($shuffledFirst, $shuffledAgain,
    'при одном seed порядок воспроизводим — перезагрузка не двигает варианты');

exit($t->report());