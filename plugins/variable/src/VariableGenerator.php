<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

/**
 * Выбор значений переменных для конкретного варианта задачи.
 *
 * Значения выбираются детерминированно из seed'а позиции, который уже
 * передаётся вопросу при отрисовке. Отсюда два свойства, без которых такой
 * тип заданий нерабочий:
 *
 * — обновление страницы не меняет числа: ученик не увидит, что задача вдруг
 * стала другой;
 * — при проверке те же значения получаются снова, потому что seed выводится
 * из пары «попытка + позиция».
 *
 * При этом проверка опирается не на повторный розыгрыш, а на значения,
 * сохранённые вместе с ответом: учитель мог изменить допустимые значения
 * после того, как ученик ответил, и повторный розыгрыш дал бы ему другой
 * пример. Сохранённые значения — единственный источник правды.
 */
final class VariableGenerator
{
 public const MODE_LIST = 'list';
 public const MODE_RANGE = 'range';

 /**
 * Допустимые значения одной переменной.
 *
 * list — прямой перечень: {"mode":"list","values":"3, 5, 8"}
 * range — границы и шаг: {"mode":"range","min":"1","max":"9","step":"1"}
 *
 * @return list<float> непустой список чисел по возрастанию
 *
 * @throws InvalidProblemException
 */
 public static function valuesOf(array $variable): array
 {
 $mode = (string) ($variable['mode'] ?? self::MODE_LIST);

 if ($mode === self::MODE_RANGE) {
 return self::fromRange($variable);
 }

 return self::fromList((string) ($variable['values'] ?? ''));
 }

 /**
 * Разбор перечисления.
 *
 * Разделители — запятая, точка с запятой, пробел и вертикальная черта:
 * список набирают в спешке, и требовать единственный разделитель значило
 * бы заставлять переписывать его после каждой опечатки.
 *
 * @return list<float>
 *
 * @throws InvalidProblemException
 */
 private static function fromList(string $raw): array
 {
 $parts = preg_split('/[,;\s|]+/u', trim($raw)) ?: [];

 $values = [];

 foreach ($parts as $part) {
 if ($part === '') {
 continue;
 }

 $value = Number::parse(str_replace(',', '.', $part));

 if ($value === null) {
 throw new InvalidProblemException('В списке значений не число: ' . $part);
 }

 $values[$value] = $value;
 }

 if ($values === []) {
 throw new InvalidProblemException('Список значений пуст');
 }

 $values = array_values($values);
 sort($values);

 return $values;
 }

 /**
 * Разбор диапазона со шагом.
 *
 * Границы включаются: диапазон «от 1 до 9 с шагом 2» — это 1, 3, 5, 7, 9.
 *
 * @return list<float>
 *
 * @throws InvalidProblemException
 */
 private static function fromRange(array $variable): array
 {
 $min = $variable['min'] ?? null;
 $max = $variable['max'] ?? null;
 $step = $variable['step'] ?? 1;

 if (!is_numeric($min) || !is_numeric($max)) {
 throw new InvalidProblemException('Для диапазона нужны обе границы — числами');
 }

 $min = (float) $min;
 $max = (float) $max;
 $step = abs(is_numeric($step) ? (float) $step : 0.0);

 if ($step < 1e-9) {
 $step = 1.0;
 }

 if ($min > $max) {
 throw new InvalidProblemException('Нижняя граница больше верхней — диапазон пустой');
 }

 /*
 * Число шагов считается до округления: (0.3 - 0.1) / 0.1 в двоичной
 * арифметике даёт 1,9999..., и наивный цикл по float ушёл бы за
 * границу на единицу.
 */
 $count = (int) floor(($max - $min) / $step + 1e-9);

 $values = [];

 for ($i = 0; $i <= $count; $i++) {
 $values[] = self::round($min + $i * $step);
 }

 if ($values === []) {
 throw new InvalidProblemException('Диапазон не содержит ни одного значения');
 }

 return $values;
 }

 /**
 * Значения всех переменных по seed'у.
 *
 * @param list<array<string,mixed>> $variables
 *
 * @return array<string,float> имя переменной → значение
 *
 * @throws InvalidProblemException
 */
 public static function pick(array $variables, int $seed): array
 {
 mt_srand($seed);

 $out = [];

 try {
 foreach ($variables as $variable) {
 $name = (string) ($variable['name'] ?? '');

 if (!FormulaEvaluator::isValidName($name)) {
 throw new InvalidProblemException('Не задано имя переменной');
 }

 $values = self::valuesOf($variable);
 $out[$name] = $values[mt_rand(0, count($values) - 1)];
 }
 } finally {
 // ГПСЧ общий для процесса: оставлять его на чужом seed'е значило
 // бы испортить перемешивание вариантов в других типах.
 mt_srand();
 }

 return $out;
 }

 /**
 * Подстановка значений в условие.
 *
 * @param array<string,float> $values
 */
 public static function substitute(string $prompt, array $values): string
 {
 return (string) preg_replace_callback(
 '/\{([a-zA-Z][a-zA-Z0-9_]{0,15})\}/',
 static function (array $matches) use ($values): string {
 if (!array_key_exists($matches[1], $values)) {
 // Переменная объявлена в тексте, но не задана: оставляем
 // как есть, чтобы автор увидел опечатку, а не пустоту.
 return $matches[0];
 }

 return Number::format($values[$matches[1]]);
 },
 $prompt
 );
 }

 /**
 * Имена переменных, встречающихся в тексте.
 *
 * @return list<string>
 */
 public static function namesIn(string $prompt): array
 {
 preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]{0,15})\}/', $prompt, $matches);

 $names = array_values(array_unique($matches[1] ?? []));

 sort($names);

 return $names;
 }

 /**
 * Округление до разумной точности.
 *
 * Иначе (0.1 + 0.2) попадало бы в условие как 0,30000000000000004.
 */
 public static function round(float $value): float
 {
 return round($value, 6);
 }
}
