<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

use MyEdu\Plugin\ItemGrade;

/**
 * Проверка ответа по вариативной задаче.
 *
 * У задачи одна формула и один ответ.
 *
 * Условие и формула используют одни и те же имена переменных, но живут по
 * разные стороны задачи: в условии на месте «{a}» ученик видит число, а в
 * формуле «a» — то же самое число при проверке. Поэтому правильный ответ не
 * показывается и не вводится — ученик сам решает и вписывает результат.
 *
 * Считается по тем значениям, которые ученик видел, а не по первым допустимым.
 * Учитель вправе изменить перечень после того, как ученик ответил, и пересчёт
 * по новому перечню превратил бы верный ответ в неверный по чужой вине.
 *
 * Балл целиком: верный ответ — полный, любой другой — ноль.
 */
final class ProblemGrader
{
 /**
 * Правильный ответ по значениям варианта.
 *
 * Список переменных не нужен: формула и есть правило, а значения в неё
 * подставляет проверка по именам, объявленным в условии.
 *
 * @param array<string,float> $values показанные ученику значения
 *
 * @throws InvalidProblemException формула не считается
 */
 public function expected(array $values, string $formula): float
 {
 if (trim($formula) === '') {
 throw new InvalidProblemException('Не указана формула ответа');
 }

 return FormulaEvaluator::evaluate($formula, $values);
 }

 /**
 * Ответ ученика и значения того же варианта.
 *
 * @param array<string,mixed> $config
 * @param mixed $raw то, что пришло из формы или из базы
 *
 * @return array{answer:float|null,values:array<string,float>}
 */
 public function normalize(array $config, mixed $raw): array
 {
 if (is_string($raw)) {
 $raw = trim($raw) === '' ? [] : json_decode($raw, true);
 }

 if (!is_array($raw)) {
 $raw = [];
 }

 $given = $raw['answer'] ?? null;

 return [
 'answer' => is_scalar($given) ? Number::parse((string) $given) : null,
 'values' => $this->cleanValues($raw['values'] ?? null, $this->names($config)),
 ];
 }

 /**
 * Проверка ответа.
 *
 * @param array<string,mixed> $task строка задания: prompt + config
 * @param array<string,mixed> $answer результат normalize()
 * @param float $max сколько баллов стоит задание
 */
 public function grade(array $task, array $answer, float $max): ItemGrade
 {
 $config = is_array($task['config'] ?? null) ? $task['config'] : [];
 $formula = (string) ($config['formula'] ?? '');
 $variables = ProblemConfig::variables($config);
 $values = is_array($answer['values'] ?? null) ? $answer['values'] : [];

 if ($values === []) {
 /*
 * Значений нет только у ответа, сохранённого до их появления в
 * плагине. Берём первые допустимые и честно помечаем это в разборе:
 * молча списать проверку в ноль значило бы обвинить ученика.
 */
 $values = $this->firstValues($variables);
 }

 try {
 $expected = $this->expected($values, $formula);
 } catch (InvalidProblemException $e) {
 return ItemGrade::forReview($max, ['error' => $e->getMessage()]);
 }

 $tolerance = ProblemConfig::tolerance($config);
 $given = is_float($answer['answer'] ?? null) ? $answer['answer'] : null;

 // Пустой ответ — это «не ответил», а не «ответил неверно»: разница
 // видна в разборе, иначе она тонет среди обычных ошибок.
 $ok = $given !== null && abs($given - $expected) <= $tolerance + 1e-9;
 $unit = ProblemConfig::unit($config);
 $suffix = $unit === '' ? '' : ' ' . $unit;

 /*
 * Снимок разбора собирается здесь и больше не пересчитывается: правка
 * задания автором не должна менять то, что стояло в проверке. В частности
 * правильный ответ здесь считается по тем значениям, которые видел
 * ученик, а не по первым допустимым.
 */
 $snapshot = [
 'prompt' => VariableGenerator::substitute((string) ($task['prompt'] ?? ''), $values),
 'values' => $values,
 'expected' => $expected,
 'given' => $given,
 'unit' => $unit,
 'tolerance' => $tolerance,
 'ok' => $ok,
 'answer_text' => $this->answerText($given, $suffix),
 'expected_text' => Number::format($expected) . $suffix,
 ];

 return ItemGrade::scored($ok ? $max : 0.0, $max, $snapshot);
 }

 /**
 * Что ввёл ученик — для разбора и для показа учителю.
 */
 public function answerText(?float $given, string $suffix = ''): string
 {
 if ($given === null) {
 return '— ответ не введён —';
 }

 return Number::format($given) . $suffix;
 }

 /**
 * Текст правильного ответа по тем значениям, которые видел ученик.
 *
 * @param array<string,float> $values
 */
 public function expectedText(array $values, string $formula, string $unit = ''): string
 {
 try {
 $expected = $this->expected($values, $formula);
 } catch (InvalidProblemException) {
 return '';
 }

 return Number::format($expected) . ($unit === '' ? '' : ' ' . $unit);
 }

 /**
 * Первые допустимые значения — запасной вариант, если значения варианта не
 * сохранились вместе с ответом.
 *
 * @param list<array<string,mixed>> $variables
 *
 * @return array<string,float>
 */
 public function firstValues(array $variables): array
 {
 $out = [];

 foreach ($variables as $variable) {
 try {
 $values = VariableGenerator::valuesOf($variable);
 $out[(string) $variable['name']] = $values[0];
 } catch (InvalidProblemException) {
 continue;
 }
 }

 return $out;
 }

 /**
 * Значения из скрытых полей — только те, что объявлены переменными.
 *
 * Поля приходят из браузера, поэтому берутся по списку переменных самого
 * задания: иначе в проверку попали бы числа из чужого поля.
 *
 * @param list<string> $names
 *
 * @return array<string,float>
 */
 private function cleanValues(mixed $values, array $names): array
 {
 if (!is_array($values)) {
 return [];
 }

 $out = [];

 foreach ($names as $name) {
 $raw = $values[$name] ?? null;

 if (!is_scalar($raw)) {
 continue;
 }

 $parsed = Number::parse((string) $raw);

 if ($parsed !== null) {
 $out[$name] = $parsed;
 }
 }

 return $out;
 }

 /**
 * @param array<string,mixed> $config
 *
 * @return list<string>
 */
 private function names(array $config): array
 {
 $out = [];

 foreach (ProblemConfig::variables($config) as $variable) {
 $out[] = (string) $variable['name'];
 }

 return $out;
 }
}