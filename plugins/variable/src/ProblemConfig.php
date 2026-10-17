<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

/**
 * Настройка вариативной задачи: какие переменные, какие у них значения и по
 * какой формуле считается ответ.
 *
 * Всё, что приводит авторский ввод к единому виду, живёт здесь: редактор
 * отдаёт строки из формы, проверка читает готовые числа, а разбор ответа
 * должен понимать ровно то же самое. Одна точка правды — иначе сохранённое
 * задание и проверенный ответ разойдутся.
 *
 * Вид настройки:
 *
 * variables: [
 * {name: 'a', mode: 'list', values: '6, 9, 12'},
 * {name: 'b', mode: 'range', min: 2, max: 10, step: 1},
 * ]
 * formula: 'a * b - 4'
 * tolerance: 0.01
 * unit: 'см'
 *
 * Формула одна на всю задачу, а не по одной на переменную: ученик решает задачу
 * и вписывает один ответ. Переменные в условии и в формуле — одни и те же имена,
 * но в условии на месте «{a}» стоит выпавшее число, а в формуле «a» при
 * проверке означает то же число.
 */
final class ProblemConfig
{
 /**
 * Больше шести переменных не берём: каждой нужен отдельный ответ в форме,
 * а длинная полоса чисел на телефоне не помещается и ученик её не увидит.
 */
 public const MAX_VARIABLES = 6;

 public const DEFAULT_TOLERANCE = 0.01;

 /** @return array<string,mixed> */
 public static function defaultConfig(): array
 {
 return [
 'variables' => [],
 'formula' => '',
 'tolerance' => self::DEFAULT_TOLERANCE,
 'unit' => '',
 ];
 }

 /**
 * Формула ответа — одна на всю задачу.
 *
 * @param array<string,mixed> $config
 */
 public static function formula(array $config): string
 {
 return trim((string) ($config['formula'] ?? ''));
 }

 /**
 * Переменные из настройки в нормализованном виде.
 *
 * Мусорные записи (не массив, пустое имя) молча пропускаются: разбор
 * страницы результата не должен падать на старом или чужом задании.
 *
 * @param array<string,mixed> $config
 *
 * @return list<array<string,mixed>>
 */
 public static function variables(array $config): array
 {
 $raw = $config['variables'] ?? [];

 if (!is_array($raw)) {
 return [];
 }

 $out = [];

 foreach ($raw as $variable) {
 if (is_array($variable) && trim((string) ($variable['name'] ?? '')) !== '') {
 $out[] = $variable;
 }
 }

 return $out;
 }

 /** @param array<string,mixed> $config */
 public static function tolerance(array $config): float
 {
 $tolerance = $config['tolerance'] ?? self::DEFAULT_TOLERANCE;

 return is_numeric($tolerance) ? abs((float) $tolerance) : self::DEFAULT_TOLERANCE;
 }

 /** @param array<string,mixed> $config */
 public static function unit(array $config): string
 {
 return trim((string) ($config['unit'] ?? ''));
 }

 /**
 * Проверка настройки перед сохранением.
 *
 * Здесь ловятся вещи, из-за которых задание не работает: неизвестное имя
 * переменной, формула, которая ссылается на незаданную переменную или не
 * считается на краях диапазона, и переменная, которой нет в условии.
 * Последнее — самое неприятное: пример сохранился бы с местом, откуда
 * неоткуда взяться число.
 *
 * @param array<string,mixed> $config
 *
 * @throws InvalidProblemException
 */
 public static function validate(array $config, string $prompt): void
 {
 $variables = self::variables($config);

 if ($variables === []) {
 throw new InvalidProblemException('Задайте хотя бы одну переменную');
 }

 if (count($variables) > self::MAX_VARIABLES) {
 throw new InvalidProblemException(
 'Переменных не больше ' . self::MAX_VARIABLES . ', иначе пример не помещается в условие'
 );
 }

 $names = [];
 $edges = [];

 foreach ($variables as $variable) {
 $name = trim((string) ($variable['name'] ?? ''));

 if (!FormulaEvaluator::isValidName($name)) {
 throw new InvalidProblemException(
 'Имя переменной «' . $name . '» неверно: латинская буква, дальше буквы, цифры или _'
 );
 }

 if (isset($names[$name])) {
 throw new InvalidProblemException('Переменная «' . $name . '» задана дважды');
 }

 $names[$name] = true;

 $values = VariableGenerator::valuesOf($variable);

 if ($values === []) {
 throw new InvalidProblemException('У переменной «' . $name . '» нет допустимых значений');
 }

 // Края каждой переменной понадобятся для проверки формулы.
 $edges[$name] = [$values[0], $values[count($values) - 1]];
 }

 $declared = array_keys($names);
 $formula = self::formula($config);

 if ($formula === '') {
 throw new InvalidProblemException('Укажите формулу, по которой считается ответ');
 }

 foreach (self::namesInFormula($formula) as $referenced) {
 if (!isset($names[$referenced])) {
 throw new InvalidProblemException(
 'В формуле есть переменная «' . $referenced . '», которой не заданы значения'
 );
 }
 }

 /*
 * Формула обязана считаться на краях. Именно на них она обычно и ломается:
 * деление на ноль при x = 0, ноль в знаменателе на нижней границе.
 * Если не проверить сейчас, ошибку увидит ученик на своей работе.
 *
 * Берутся сочетания «каждая переменная на своём краю» и «все на нижнем»:
 * формула вроде «a - b» ломается только во втором, а «a / b» — когда b
 * на нуле, что и даёт первая.
 */
 $samples = [];

 foreach ($declared as $name) {
 $sample = [];

 foreach ($declared as $other) {
 $sample[$other] = $edges[$other][1];
 }

 $samples[] = $sample;

 $sample[$name] = $edges[$name][0];
 $samples[] = $sample;
 }

 $sample = [];

 foreach ($declared as $other) {
 $sample[$other] = $edges[$other][0];
 }

 $samples[] = $sample;

 foreach ($samples as $sampleValues) {
 try {
 FormulaEvaluator::evaluate($formula, $sampleValues);
 } catch (InvalidProblemException $e) {
 $where = [];

 foreach ($sampleValues as $name => $value) {
 $where[] = $name . ' = ' . $value;
 }

 throw new InvalidProblemException(
 'Формула не считается при ' . implode(', ', $where) . ': ' . $e->getMessage()
 );
 }
 }

 self::validatePrompt($prompt, $declared);
 }

 /**
 * Согласованность условия и списка переменных.
 *
 * @param list<string> $declared
 *
 * @throws InvalidProblemException
 */
 public static function validatePrompt(string $prompt, array $declared): void
 {
 foreach (VariableGenerator::namesIn($prompt) as $used) {
 if (!in_array($used, $declared, true)) {
 throw new InvalidProblemException(
 'В условии есть «{' . $used . '}», но такая переменная не задана'
 );
 }
 }

 $used = VariableGenerator::namesIn($prompt);

 foreach ($declared as $name) {
 if (!in_array($name, $used, true)) {
 throw new InvalidProblemException(
 'Переменная «' . $name . '» задана, но в условии не встречается — '
 . 'она не подставится, и пример останется с фигурными скобками'
 );
 }
 }
 }

 /**
 * Имена переменных, на которые ссылается формула.
 *
 * @return list<string>
 */
 public static function namesInFormula(string $formula): array
 {
 preg_match_all('/[a-zA-Z][a-zA-Z0-9_]{0,15}/', $formula, $matches);

 $names = array_values(array_unique($matches[0] ?? []));

 sort($names);

 return $names;
 }
}