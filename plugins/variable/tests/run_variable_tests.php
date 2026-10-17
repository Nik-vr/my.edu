<?php

declare(strict_types=1);

/**
 * Автономные тесты плагина «Вариативная задача».
 *
 * Запуск: php plugins/variable/tests/run_variable_tests.php
 *
 * PHPUnit не используется намеренно: проект не должен требовать Composer
 * ни при разработке, ни на сервере. Свой раннер держит зависимости равными нулю.
 *
 * Здесь нет подключения к базе — проверяется чистая логика плагина: разбор
 * формул, выбор значений, приём ответа и подсчёт балла.
 */

use MyEduPlugin\Variable\FormulaEvaluator;
use MyEduPlugin\Variable\InvalidProblemException;
use MyEduPlugin\Variable\Number;
use MyEduPlugin\Variable\ProblemConfig;
use MyEduPlugin\Variable\ProblemGrader;
use MyEduPlugin\Variable\VariableGenerator;

$projectRoot = dirname(__DIR__, 3);

require $projectRoot . '/src/Core/Autoloader.php';

// Ядро и плагин в одном загрузчике: тесты проверяют плагин, но используют
// и общие части ядра.
$autoloader = MyEdu\Core\Autoloader::create($projectRoot);
$autoloader->addNamespace('MyEduPlugin\\Variable\\', dirname(__DIR__) . '/src');

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
 * Проверка, что код отверг настройку и объяснил почему.
 *
 * Проверяется именно класс исключения: молчаливое принятие неверной
 * настройки хуже явной ошибки — ошибку заметят, а молчание приведёт к
 * заданию, которое не работает.
 *
 * @param callable $action
 */
 public function throws(callable $action, string $description): void
 {
 try {
 $action();
 } catch (InvalidProblemException) {
 $this->is(true, $description);
 return;
 } catch (\Throwable $e) {
 $this->is(false, $description . ' (брошено ' . $e::class . ')');
 return;
 }

 $this->is(false, $description . ' (ничего не брошено)');
 }

 public function report(): int
 {
 $total = $this->passed + count($this->failures);

 echo "\n";

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

// ------------------------------------------------------------------ числа

$t->group('Числа: как плагин понимает ответ ученика');

$t->equals(12.0, Number::parse('12'), 'целое');
$t->equals(-3.0, Number::parse(' −3 '), 'минус и пробелы вокруг');
$t->equals(2.5, Number::parse('2,5'), 'запятая считается десятичной');
$t->equals(4.0, Number::parse('≈4'), 'ведущий знак отбрасывается');
$t->equals(5.0, Number::parse('5 кг'), 'единица после числа не мешает');
$t->equals(null, Number::parse(''), 'пустая строка — не число');
$t->equals(null, Number::parse('два'), 'слово «два» — не число');
$t->equals(null, Number::parse('минус два'), 'слово «минус» — не число');

/*
 * Ноль и пустое поле различаются намеренно: незаполненный ответ должен
 * отличаться от ответа «0».
 */
$t->is(Number::parse('0') !== null, 'ноль — это число, а не пустой ответ');

$t->equals('6', Number::format(6.0), 'целое печатается без запятой');
$t->equals('2.5', Number::format(2.5), 'дробное печатается с точкой');

/*
 * Без округления (0.1 + 0.2) попало бы в условие как 0,30000000000000004, и это
 * выглядело бы ошибкой плагина, а не арифметики.
 */
$t->equals('0.3', Number::format(0.1 + 0.2), 'двоичный хвост не попадает в текст');

// ------------------------------------------------------------------ формулы

$t->group('Формулы: собственный разбор вместо eval()');

/*
 * Формулу задаёт учитель, а считается она на сервере, поэтому отдавать её в
 * eval() нельзя: это выполнение произвольного кода из базы.
 */
$formulaCases = [
 ['a * b', ['a' => 3, 'b' => 7], 21.0, 'умножение'],
 ['(a + b) / 2', ['a' => 3, 'b' => 7], 5.0, 'скобки и деление'],
 ['-a + 2 ^ 3', ['a' => 3], 5.0, 'минус и степень'],
 ['2 ^ 3 ^ 2', [], 512.0, 'степень правоассоциативна'],
 ['a % 4', ['a' => 10], 2.0, 'остаток от деления'],
 ['10 - 2 - 3', [], 5.0, 'вычитание слева направо'],
 ['a', ['a' => 4.5], 4.5, 'просто переменная'],
];

foreach ($formulaCases as [$formula, $values, $expected, $what]) {
 $t->equals(
 $expected,
 round(FormulaEvaluator::evaluate($formula, $values), 6),
 'формула считается: ' . $what
 );
}

$rejected = [
 ['a / b', ['a' => 1, 'b' => 0], 'деление на ноль не превращается в ноль'],
 ['(1 + 2', [], 'незакрытая скобка'],
 ['a + ', [], 'оборванная формула'],
 ['a $ b', ['a' => 1], 'посторонний символ'],
 ['system("ls")', [], 'вызов функции не выполняется'],
 ['a', ['b' => 1], 'нет такой переменной'],
 ['', [], 'пустая формула'],
 [str_repeat('(', 40) . '1' . str_repeat(')', 40), [], 'слишком глубокие скобки'],
];

foreach ($rejected as [$formula, $values, $what]) {
 $t->throws(
 static fn () => FormulaEvaluator::evaluate($formula, $values),
 'формула отклонена: ' . $what
 );
}

$t->is(FormulaEvaluator::isValidName('a'), 'короткое имя допустимо');
$t->is(FormulaEvaluator::isValidName('speed_2'), 'имя с цифрой и подчёркиванием допустимо');
$t->is(!FormulaEvaluator::isValidName('1a'), 'имя не может начинаться с цифры');
$t->is(!FormulaEvaluator::isValidName('а'), 'русское имя не допускается: «а» и «a» выглядели бы одинаково');

/*
 * Переменная в фигурных скобках — так же, как в условии.
 *
 * Учитель пишет «{m}» в условии и по инерции ту же запись повторяет в
 * формуле: «4200*{m}*{t}». Раньше такая формула падала с «Недопустимый символ:
 * {» — причём не только при сохранении задания, но и при проверке каждой
 * работы ученика, то есть формула была непригодна целиком.
 *
 * Разбор живёт в вычислителе, а не в вызывающем коде: формулу считают и
 * проверка при сохранении, и выставление оценки, и правило, записанное в
 * одном из этих мест, рано или поздно забылось бы во втором.
 */
$t->equals(
 2772000.0,
 FormulaEvaluator::evaluate('4200*{m}*{t}', ['m' => 12, 't' => 55]),
 'формула со скобками считается так же, как без них'
);
$t->equals(
 round(FormulaEvaluator::evaluate('4200*m*t', ['m' => 12, 't' => 55]), 6),
 round(FormulaEvaluator::evaluate('4200*{m}*{t}', ['m' => 12, 't' => 55]), 6),
 'оба написания дают одно число'
);
$t->equals(
 2.5,
 FormulaEvaluator::evaluate('({a} + {b}) / 2', ['a' => 2, 'b' => 3]),
 'скобки и скобки-переменные вместе'
);
$t->equals(
 1.5,
 FormulaEvaluator::evaluate('m', ['m' => 1.5]),
 'дробное значение подставляется без запятой в дробной части'
);

// Неизвестное имя должно называть себя, а не жаловаться на символ.
$unresolved = null;

try {
 FormulaEvaluator::evaluate('2 * {zz}', ['m' => 1]);
} catch (Throwable $e) {
 $unresolved = $e->getMessage();
}

$t->is(
 is_string($unresolved) && str_contains($unresolved, '{zz}'),
 'неизвестная переменная в формуле названа прямо: ' . (string) $unresolved
);

// ------------------------------------------------------------------ значения

$t->group('Значения переменных');

$t->equals(
 [3.0, 5.0, 8.0],
 VariableGenerator::valuesOf(['mode' => 'list', 'values' => '8, 3, 5, 3']),
 'перечисление читается и сортируется, повторы убираются'
);

$t->equals(
 [3.0, 5.0, 8.0],
 VariableGenerator::valuesOf(['mode' => 'list', 'values' => '8; 3 5']),
 'разделители: запятая, точка с запятой, пробел'
);

$t->equals(
 [1.0, 3.0, 5.0, 7.0, 9.0],
 VariableGenerator::valuesOf(['mode' => 'range', 'min' => '1', 'max' => '9', 'step' => '2']),
 'диапазон со шагом, обе границы включаются'
);

/*
 * Границы диапазона — самое частое место, где накапливается ошибка:
 * (0.3 − 0.1) / 0.1 в двоичной арифметике даёт 1,9999..., и наивный цикл ушёл
 * бы за границу на единицу.
 */
$t->equals(
 [0.1, 0.2, 0.3],
 VariableGenerator::valuesOf(['mode' => 'range', 'min' => '0.1', 'max' => '0.3', 'step' => '0.1']),
 'дробный диапазон не теряет последнее значение'
);

foreach ([
 [['mode' => 'list', 'values' => ''], 'пустой перечень'],
 [['mode' => 'list', 'values' => 'два, три'], 'не числа в перечне'],
 [['mode' => 'range', 'min' => '5', 'max' => '1'], 'нижняя граница выше верхней'],
 [['mode' => 'range', 'min' => 'x'], 'границы не числа'],
] as [$variable, $what]) {
 $t->throws(
 static fn () => VariableGenerator::valuesOf($variable),
 'значения отклонены: ' . $what
 );
}

// ------------------------------------------------------------------ подстановка

$t->group('Подстановка в условие');

$variables = [
 ['name' => 'a', 'mode' => 'list', 'values' => '6, 9'],
 ['name' => 'b', 'mode' => 'range', 'min' => '2', 'max' => '10', 'step' => '1'],
];

$t->equals(
 ['a', 'b'],
 VariableGenerator::namesIn('Число {a} втрое больше {b}'),
 'имена переменных читаются из условия'
);

$t->equals(
 'Число 9 втрое больше 6',
 VariableGenerator::substitute('Число {a} втрое больше {b}', ['a' => 9.0, 'b' => 6.0]),
 'в условии вместо скобок — числа'
);

$t->equals(
 'Найдите {z}',
 VariableGenerator::substitute('Найдите {z}', ['a' => 9.0]),
 'незаданная переменная остаётся как есть, чтобы автор увидел опечатку'
);

/*
 * Один и тот же seed — те же числа. Иначе ученик обновлял бы страницу и видел
 * другую задачу, а проверка считала бы по третьей.
 */
$t->equals(
 VariableGenerator::pick($variables, 11),
 VariableGenerator::pick($variables, 11),
 'один seed даёт те же значения'
);

$t->is(
 VariableGenerator::pick($variables, 11) !== VariableGenerator::pick($variables, 12),
 'разные seed дают разные значения'
);

// ------------------------------------------------------------------ настройка

$t->group('Проверка настройки перед сохранением');

$goodConfig = [
 'variables' => $variables,
 // Одна формула на всю задачу: ученик решает и вписывает один ответ.
 'formula' => 'a - b',
 'tolerance' => 0.01,
 'unit' => 'шт',
];

$goodPrompt = 'Число {a} больше {b} на сколько?';

$t->is(true, 'корректная настройка принимается');

ProblemConfig::validate($goodConfig, $goodPrompt);
$t->is(true, 'корректная настройка действительно проходит проверку');

/*
 * Задача из жалобы владельца — целиком, как её вводили: условие с
 * подстановками, перечисление и диапазон, формула со скобками вокруг имён.
 * Проверяется сохранение, а не только вычисление: ошибка вылезала именно при
 * сохранении задания, и человек уходил чинить формулу вместо того, чтобы
 * отличить опечатку от недоделки.
 */
$reportedConfig = [
 'prompt' => 'Рассчитайте количество теплоты, необходимое для нагревания {m} кг воды на {t} °С.',
 'variables' => [
 ['name' => 'm', 'mode' => 'list', 'values' => '2, 4, 6, 8, 10, 12'],
 ['name' => 't', 'mode' => 'range', 'min' => '35', 'max' => '55', 'step' => '5'],
 ],
 'formula' => '4200*{m}*{t}',
 'tolerance' => 0.01,
 'unit' => 'Дж',
];

$reportedSaved = true;
$reportedError = '';

try {
 ProblemConfig::validate($reportedConfig, $reportedConfig['prompt']);
} catch (Throwable $e) {
 $reportedSaved = false;
 $reportedError = $e->getMessage();
}

$t->is($reportedSaved, 'задача с формулой «4200*{m}*{t}» сохраняется: ' . $reportedError);
$t->equals(
 2772000.0,
 FormulaEvaluator::evaluate($reportedConfig['formula'], ['m' => 12, 't' => 55]),
 'такая формула считается на самом краю диапазона'
);

/*
 * Формула, которая ломается на краях диапазона, — самая частая ошибка автора:
 * деление на ноль при b = 0. Ловится сейчас, а не у ученика на работе.
 */
foreach ([
 [['variables' => []], '', 'без переменных'],
 [
 ['variables' => [['name' => '1a', 'mode' => 'list', 'values' => '1']], 'formula' => 'a'],
 'Сколько {1a}?',
 'имя начинается с цифры',
 ],
 [
 ['variables' => [['name' => 'a', 'mode' => 'list', 'values' => '1']], 'formula' => ''],
 'Сколько {a}?',
 'без формулы ответа',
 ],
 [
 ['variables' => [['name' => 'a', 'mode' => 'list', 'values' => '1']], 'formula' => 'b'],
 'Сколько {a}?',
 'формула ссылается на необъявленную переменную',
 ],
 [
 ['variables' => [
 ['name' => 'a', 'mode' => 'list', 'values' => '1, 2'],
 ['name' => 'a', 'mode' => 'list', 'values' => '1'],
 ], 'formula' => 'a'],
 'Сколько {a}?',
 'переменная задана дважды',
 ],
 [
 ['variables' => [['name' => 'a', 'mode' => 'list', 'values' => '1']], 'formula' => 'a'],
 'Сколько {a} и сколько {z}?',
 'в условии есть необъявленная переменная',
 ],
 [
 ['variables' => [
 ['name' => 'a', 'mode' => 'list', 'values' => '1'],
 ['name' => 'b', 'mode' => 'list', 'values' => '1'],
 ], 'formula' => 'a'],
 'Сколько {a}?',
 'переменная задана, но в условии не встречается',
 ],
 [
 ['variables' => [['name' => 'a', 'mode' => 'range', 'min' => '0', 'max' => '5', 'step' => '1']], 'formula' => '1 / a'],
 'Сколько {a}?',
 'формула делит на ноль на нижней границе',
 ],
 [
 ['variables' => [['name' => 'a', 'mode' => 'range', 'min' => '1', 'max' => '5', 'step' => '1'], ['name' => 'b', 'mode' => 'range', 'min' => '0', 'max' => '5', 'step' => '1']], 'formula' => 'a / b'],
 'Сколько {a} по {b}?',
 'формула делит на ноль при нулевом знаменателе',
 ],
] as [$config, $prompt, $what]) {
 $t->throws(
 static fn () => ProblemConfig::validate($config, $prompt),
 'настройка отклонена: ' . $what
 );
}

// ------------------------------------------------------------------ проверка

$t->group('Проверка ответа');

$grader = new ProblemGrader();
$task = [
 'prompt' => $goodPrompt,
 'config' => $goodConfig,
];

/*
 * Пример, который ученик видел. Проверка обязана считать по нему, а не
 * разыгрывать новый.
 */
$shownValues = ['a' => 9.0, 'b' => 6.0];

// Правильный ответ — a - b = 3 по числам этого варианта.
$rightAnswer = $grader->normalize($goodConfig, [
 'answer' => '3',
 'values' => $shownValues,
]);

$rightGrade = $grader->grade($task, $rightAnswer, 2.0);

$t->is($rightGrade->score >= 2.0, 'верный ответ засчитан');
$t->equals(2.0, $rightGrade->maxScore, 'максимум равен цене задания');
$t->equals(3.0, (float) $rightGrade->snapshot['expected'], 'правильный ответ посчитан по формуле');
$t->is(str_contains((string) $rightGrade->snapshot['expected_text'], '3'), 'в разборе показан правильный ответ этого варианта');

$wrongGrade = $grader->grade(
 $task,
 $grader->normalize($goodConfig, ['answer' => '1', 'values' => $shownValues]),
 2.0
);

$t->is($wrongGrade->score === 0.0, 'неверный ответ не засчитан');

/*
 * Ответ с погрешностью меньше допуска засчитывается: округление на одном из
 * шагов решения — это верный ответ, а не ошибка.
 */
$nearGrade = $grader->grade(
 $task,
 $grader->normalize($goodConfig, ['answer' => '3.005', 'values' => $shownValues]),
 2.0
);

$t->is($nearGrade->score >= 2.0, 'ответ в пределах допуска засчитан');

$t->is(
 $grader->grade(
 $task,
 $grader->normalize($goodConfig, ['answer' => '', 'values' => $shownValues]),
 2.0
 )->score === 0.0,
 'пустой ответ даёт ноль, а не ошибку'
);

/*
 * Главное свойство: учитель вправе изменить допустимые значения после того,
 * как ученик ответил. Если бы проверка пересчитывала по новому перечню,
 * верный ответ стал бы неверным по чужой вине.
 */
$changedConfig = $goodConfig;
$changedConfig['variables'][0]['values'] = '50, 60, 70';

$afterEdit = $grader->grade(
 ['prompt' => $goodPrompt, 'config' => $changedConfig],
 $grader->normalize($goodConfig, ['answer' => '3', 'values' => $shownValues]),
 2.0
);

$t->is($afterEdit->score >= 2.0, 'правка перечня не ломает уже сданный ответ');

$t->is(str_contains((string) $afterEdit->snapshot['prompt'], '9'), 'в разборе остаётся тот пример, который решал ученик');

$t->group('Ответ, которого нет или он не число');

$t->equals('— ответ не введён —', $grader->answerText(null), 'пустой ответ читается словами');
$t->equals('— ответ не введён —', $grader->answerText(null), 'не-число не превращается в ноль');

$t->equals(
 3.5,
 $grader->normalize($goodConfig, ['answer' => '3,5', 'values' => ['a' => '9', 'b' => '6']])['answer'],
 'дробный ответ с запятой принимается'
);

$t->equals(
 null,
 $grader->normalize($goodConfig, ['answer' => 'не число', 'values' => ['a' => '9']])['answer'],
 'не-число не проходит как ответ'
);

/*
 * Значения приходят из браузера, поэтому в проверку не должны попасть ничейные
 * поля: подставив «чужое», ученик не мог бы изменить пример, но мусорный ключ
 * в разборе сбивал бы с толку.
 */
$withJunk = $grader->normalize($goodConfig, [
 'answer' => '3',
 'values' => ['a' => '9', 'b' => '6', 'чужое' => '999'],
]);

$t->equals(['a' => 9.0, 'b' => 6.0], $withJunk['values'], 'чужие значения отбрасываются');

exit($t->report());
