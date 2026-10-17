<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\NumberParser;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;

/**
 * Задание с числовым ответом.
 *
 * Нужна для задач, где ученик получает число, а текст ответа — лишний шум:
 * «сколько будет 7 умножить на 8», «найдите массу», «оцените расстояние».
 *
 * Способ проверки выбирает автор — их два:
 *
 * exact — строгое совпадение с эталоном. Подходит там, где ответ единственный.
 * range — попадание в допустимый диапазон. Нужен для задач, где число
 * округляется на одном из этапов решения: ученик приносит 4,8 см
 * вместо 5 см, и это верный ответ, а не ошибка округления.
 *
 * config:
 * {
 * "mode": "exact", "expected": 56, "tolerance": 0.01,
 * "min": 4.7, "max": 5.3, "unit": "см", "step": 0.1
 * }
 */
final class NumericType implements QuestionTypeInterface
{
 public const MODE_EXACT = 'exact';
 public const MODE_RANGE = 'range';

 public function key(): string
 {
 return 'numeric';
 }

 public function title(): string
 {
 return 'Задание с числовым ответом';
 }

 public function defaultConfig(): array
 {
 return [
 'mode' => self::MODE_EXACT,
 'expected' => '',
 'tolerance' => 0.01,
 'min' => '',
 'max' => '',
 'unit' => '',
 ];
 }

 /** @return list<string> */
 public static function modes(): array
 {
 return [self::MODE_EXACT, self::MODE_RANGE];
 }

 public static function modeTitle(string $mode): string
 {
 return match ($mode) {
 self::MODE_RANGE => 'допустимый диапазон',
 default => 'строгое совпадение числа',
 };
 }

 public function validateConfig(array $config, array $options): void
 {
 $mode = (string) ($config['mode'] ?? self::MODE_EXACT);

 if (!in_array($mode, self::modes(), true)) {
 throw new InvalidAnswerException('Неизвестный способ проверки числа: ' . $mode);
 }

 if ($mode === self::MODE_EXACT) {
 if (!is_numeric($config['expected'] ?? null)) {
 throw new InvalidAnswerException('Укажите правильный ответ числом');
 }

 return;
 }

 $min = $config['min'] ?? null;
 $max = $config['max'] ?? null;

 if (!is_numeric($min) || !is_numeric($max)) {
 throw new InvalidAnswerException('Для диапазона нужны обе границы — числами');
 }

 if ((float) $min > (float) $max) {
 throw new InvalidAnswerException('Нижняя граница больше верхней — диапазон пустой');
 }
 }

 public function payload(Question $question): array
 {
 return [
 'mode' => (string) $question->configValue('mode', self::MODE_EXACT),
 'unit' => trim((string) $question->configValue('unit', '')),
 'step' => $this->step($question),
 'min' => (float) $question->configValue('min', 0),
 'max' => (float) $question->configValue('max', 0),
 'integer' => (bool) $question->configBool('integer', true),
 ];
 }

 public function normalizeAnswer(mixed $raw): string
 {
 if ($raw === null) {
 return '';
 }

 if (is_array($raw)) {
 $raw = implode(' ', array_filter(array_map(
 static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
 $raw
 ), static fn (string $v): bool => $v !== ''));
 }

 if (!is_string($raw)) {
 throw new InvalidAnswerException('Ожидалась строка');
 }

 // Хранится то, что ученик ввёл: разбор идёт при проверке, иначе
 // исправленная формула не пересчитала бы уже отвеченное задание.
 return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], ' ', $raw)));
 }

 public function grade(Question $question, mixed $answer): QuestionGrade
 {
 $max = $question->maxScore();
 $mode = (string) $question->configValue('mode', self::MODE_EXACT);
 $given = NumberParser::strict(is_string($answer) ? $answer : '');

 $detail = [
 'mode' => $mode,
 'given' => $given,
 'unit' => trim((string) $question->configValue('unit', '')),
 ];

 if ($given === null) {
 return QuestionGrade::wrong($max, $this->expectedText($question), $detail);
 }

 return $mode === self::MODE_RANGE
 ? $this->gradeRange($question, $given, $max, $detail)
 : $this->gradeExact($question, $given, $max, $detail);
 }

 public function expectedText(Question $question): string
 {
 $unit = trim((string) $question->configValue('unit', ''));
 $suffix = $unit === '' ? '' : ' ' . $unit;
 $mode = (string) $question->configValue('mode', self::MODE_EXACT);

 if ($mode === self::MODE_RANGE) {
 $min = (float) $question->configValue('min', 0);
 $max = (float) $question->configValue('max', 0);

 return 'от ' . self::formatNumber($min) . ' до ' . self::formatNumber($max) . $suffix;
 }

 return self::formatNumber((float) $question->configValue('expected', 0)) . $suffix;
 }

 public function answerText(Question $question, mixed $answer): string
 {
 $text = is_string($answer) ? trim($answer) : '';

 if ($text === '') {
 return '— ответ не введён —';
 }

 $value = NumberParser::strict($text);

 if ($value === null) {
 // Показываем как есть: значит, введено не число, и это видно.
 return $text;
 }

 return self::formatNumber($value);
 }

 // ---------------------------------------------------------------- проверка

 private function gradeExact(Question $question, float $given, float $max, array $detail): QuestionGrade
 {
 $expected = (float) $question->configValue('expected', 0);
 $tolerance = $this->tolerance($question, $expected);

 $detail['expected'] = $expected;
 $detail['tolerance'] = $tolerance;

 // Сравниваем с небольшим запасом: числа дробные, и 0,1 может храниться
 // как 0,09999999999999998.
 if (abs($given - $expected) <= $tolerance + 1e-9) {
 return QuestionGrade::correct($max, $max, $this->expectedText($question), $detail);
 }

 return QuestionGrade::wrong($max, $this->expectedText($question), $detail);
 }

 private function gradeRange(Question $question, float $given, float $max, array $detail): QuestionGrade
 {
 $min = (float) $question->configValue('min', 0);
 $maxValue = (float) $question->configValue('max', 0);
 $tolerance = $this->tolerance($question, $min);

 $detail['min'] = $min;
 $detail['max'] = $maxValue;
 $detail['tolerance'] = $tolerance;

 if ($given >= $min - $tolerance && $given <= $maxValue + $tolerance) {
 return QuestionGrade::correct($max, $max, $this->expectedText($question), $detail);
 }

 return QuestionGrade::wrong($max, $this->expectedText($question), $detail);
 }

 /**
 * Допуск к сравнению.
 *
 * Ноль означает «сравнивать как есть»: для целого ответа этого достаточно,
 * а для дробного автор всё равно задаёт step или tolerance явно.
 */
 private function tolerance(Question $question, float $reference): float
 {
 $configured = $question->configFloat('tolerance', -1);

 if ($configured >= 0) {
 return abs($configured);
 }

 $step = $this->step($question);

 return $step > 0 ? $step / 2 : 0.0;
 }

 /**
 * Шаг ответа: для дробных задач берётся из настроек, для целых равен 1.
 */
 private function step(Question $question): float
 {
 if ($question->configBool('integer', true)) {
 return 1.0;
 }

 $step = abs($question->configFloat('step', 0.0));

 return $step > 0 ? $step : 0.01;
 }

 /**
 * Число в виде строки без лишних нулей: 56 остаётся «56», а не «56.00».
 */
 public static function formatNumber(float $value): string
 {
 $rounded = round($value, 6);

 if (abs($rounded - round($rounded)) < 1e-9) {
 return (string) (int) round($rounded);
 }

 return rtrim(rtrim(number_format($rounded, 6, '.', ''), '0'), '.');
 }
}