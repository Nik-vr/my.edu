<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

/**
 * Вычисление формулы из переменных и чисел.
 *
 * Формулу задаёт учитель, а считается она на сервере, поэтому выражение
 * НЕЛЬЗЯ отдавать в eval(): это выполнение произвольного кода из базы.
 * Здесь свой разбор — рекурсивный спуск по грамматике:
 *
 * выражение := член (('+' | '-') член)*
 * член := множитель (('*' | '/' | '%') множитель)*
 * множитель := ('-' | '+') множитель | степень
 * степень := ('число' | 'переменная' | '(' выражение ')') ('^' множитель)?
 *
 * Скобки и возведение в степень нужны для школьных задач («(a + b) / 2»),
 * остальное — арифметика. Функций, индексов и вызовов здесь нет вовсе: не
 * потому что «пока не понадобились», а потому что вычисляемые значения
 * приходят из формулы учителя.
 *
 * Деление на ноль и переполнение считаются ошибкой формулы, а не нулём:
 * тихо превратить деление на ноль в ноль значило бы показать ученику
 * «правильный» ответ, которого нет.
 */
final class FormulaEvaluator
{
 /** Ограничение длины: длинная формула — это ошибка или попытка нагрузить сервер. */
 private const MAX_LENGTH = 300;

 /** Ограничение глубины вложенности скобок. */
 private const MAX_DEPTH = 24;

 private const OPERATORS = ['+', '-', '*', '/', '%', '^'];

 private string $input = '';
 private int $pos = 0;

 /** @var array<string,float> */
 private array $values;

 /**
 * @param array<string,float|int|string> $values значения переменных
 */
 private function __construct(array $values)
 {
 $this->values = [];

 foreach ($values as $name => $value) {
 if (is_numeric($value)) {
 $this->values[(string) $name] = (float) $value;
 }
 }
 }

 /**
 * Значение формулы.
 *
 * @param array<string,float|int|string> $values
 *
 * @throws InvalidProblemException формула не разобрана или считает не то
 */
 public static function evaluate(string $formula, array $values): float
 {
 $formula = trim($formula);

 if ($formula === '') {
 throw new InvalidProblemException('Пустая формула');
 }

 if (mb_strlen($formula, 'UTF-8') > self::MAX_LENGTH) {
 throw new InvalidProblemException('Формула слишком длинная');
 }

 $evaluator = new self($values);
 $evaluator->input = str_replace(' ', '', $evaluator->placeholders($formula));
 $evaluator->pos = 0;

 /*
 * Осталась неподставленная подстановка — значит, имя переменной не
 * совпало ни с одной заданной. Сообщение про «недопустимый символ»
 * бесполезно: автор видит в нём фигурную скобку вместо своей формулы и
 * не понимает, что не так. Здесь называем проблему своими словами.
 */
 if (preg_match('/\{([a-zA-Z][a-zA-Z0-9_]{0,15})\}/', $evaluator->input, $left) === 1) {
 throw new InvalidProblemException(
 'В формуле есть «{' . $left[1] . '}», но такой переменной не заданы значения'
 );
 }

 $result = $evaluator->expression(0);

 if ($evaluator->pos < strlen($evaluator->input)) {
 throw new InvalidProblemException('В формуле лишние символы после «' . substr($formula, 0, 40) . '»');
 }

 if (!is_finite($result)) {
 throw new InvalidProblemException('Формула даёт нечисловой результат');
 }

 return $result;
 }

 /**
 * Подстановка `{имя}` в формулу.
 *
 * В условии переменная пишется в фигурных скобках — `{m}` — и учитель
 * естественно пишет так же в формуле: `4200*{m}*{t}`. Раньше такая формула
 * падала с «Недопустимый символ: {» — причём не только при сохранении
 * задания, но и при проверке каждой работы ученика, то есть формула была
 * вовсе непригодна, если в ней есть хоть одна переменная в скобках.
 *
 * Подстановка живёт здесь, а не в вызывающем коде, потому что формулу
 * считают два места — проверка при сохранении и выставление оценки, — и
 * правило, записанное в одном из них, рано или поздно забудется во втором.
 *
 * Неизвестное имя остаётся на месте: так сообщение об ошибке показывает
 * автору, что он написал, а не молчит про внутренности разбора.
 */
 private function placeholders(string $formula): string
 {
 if (!str_contains($formula, '{')) {
 return $formula;
 }

 return (string) preg_replace_callback(
 '/\{([a-zA-Z][a-zA-Z0-9_]{0,15})\}/',
 function (array $matches): string {
 $name = $matches[1];

 if (!array_key_exists($name, $this->values)) {
 return $matches[0];
 }

 return $this->literal($this->values[$name]);
 },
 $formula
 );
 }

 /**
 * Число в виде, понятном формуле: точка как разделитель дробной части и без
 * хвостовых нулей.
 *
 * Своя запись, а не `Number::format()`, потому что та форматирует для чтения
 * человеком — с запятой в дробной части, — а здесь нужно число, которое
 * разберёт сам же калькулятор.
 */
 private function literal(float $value): string
 {
 $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

 return $formatted === '' || $formatted === '-' ? '0' : $formatted;
 }

 /**
 * Допустимое имя переменной.
 *
 * Латинская буква и дальше буквы, цифры или подчёркивание. Русские имена
 * сознательно не разрешены: разбор формулы идёт по ASCII, а «а» и «a»
 * выглядели бы одинаково, но означали бы разное.
 */
 public static function isValidName(string $name): bool
 {
 return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,15}$/', $name) === 1;
 }

 /**
 * Сработает ли деление на ноль при этих значениях.
 *
 * Учитель может проверить формулу ещё до выдачи работы: подсказка
 * «деление на ноль при x = 3» полезнее, чем ученик, столкнувшийся с
 * ошибкой проверки.
 *
 * @param array<string,float> $values
 *
 * @throws InvalidProblemException
 */
 public static function assertDefined(string $formula, array $values): float
 {
 return self::evaluate($formula, $values);
 }

 // ---------------------------------------------------------------- разбор

 private function expression(int $depth): float
 {
 $this->guardDepth($depth);

 $value = $this->term($depth);

 while ($this->peek() !== null && in_array($this->peek(), ['+', '-'], true)) {
 $operator = $this->consume();
 $right = $this->term($depth);

 $value = $operator === '+' ? $value + $right : $value - $right;
 }

 return $value;
 }

 private function term(int $depth): float
 {
 $this->guardDepth($depth);

 $value = $this->power($depth);

 while ($this->peek() !== null && in_array($this->peek(), ['*', '/', '%'], true)) {
 $operator = $this->consume();
 $right = $this->power($depth);

 $value = match ($operator) {
 '*' => $value * $right,
 '/' => $this->divide($value, $right),
 '%' => $this->modulo($value, $right),
 };
 }

 return $value;
 }

 private function power(int $depth): float
 {
 $this->guardDepth($depth);

 $base = $this->unary($depth);

 if ($this->peek() === '^') {
 $this->consume();

 // Возведение в степень правоассоциативно: 2^3^2 = 2^(3^2).
 $exponent = $this->power($depth);

 // Огромная степень даёт INF, и это ловится проверкой is_finite выше.
 return $base ** $exponent;
 }

 return $base;
 }

 private function unary(int $depth): float
 {
 $this->guardDepth($depth);

 $next = $this->peek();

 if ($next === '-') {
 $this->consume();

 return -$this->unary($depth);
 }

 if ($next === '+') {
 $this->consume();

 return $this->unary($depth);
 }

 return $this->primary($depth);
 }

 private function primary(int $depth): float
 {
 $this->guardDepth($depth);

 $char = $this->peek();

 if ($char === null) {
 throw new InvalidProblemException('Формула оборвалась');
 }

 if ($char === '(') {
 $this->consume();
 $value = $this->expression($depth + 1);

 if ($this->peek() !== ')') {
 throw new InvalidProblemException('В формуле не закрыта скобка');
 }

 $this->consume();

 return $value;
 }

 if (ctype_digit($char) || ($char === '.' && ctype_digit(substr($this->input, $this->pos + 1, 1)))) {
 return $this->number();
 }

 if (ctype_alpha($char) || $char === '_') {
 return $this->variable();
 }

 throw new InvalidProblemException('Недопустимый символ в формуле: ' . $char);
 }

 private function number(): float
 {
 $start = $this->pos;

 while ($this->pos < strlen($this->input) && (ctype_digit($this->input[$this->pos]) || $this->input[$this->pos] === '.')) {
 $this->pos++;
 }

 $raw = substr($this->input, $start, $this->pos - $start);

 if (!is_numeric($raw)) {
 throw new InvalidProblemException('В формуле не число: ' . $raw);
 }

 return (float) $raw;
 }

 private function variable(): float
 {
 $start = $this->pos;

 while ($this->pos < strlen($this->input)
 && (ctype_alnum($this->input[$this->pos]) || $this->input[$this->pos] === '_')) {
 $this->pos++;
 }

 $name = substr($this->input, $start, $this->pos - $start);

 if (!array_key_exists($name, $this->values)) {
 throw new InvalidProblemException('В формуле нет переменной «' . $name . '»');
 }

 return $this->values[$name];
 }

 // ---------------------------------------------------------------- помощники

 private function divide(float $left, float $right): float
 {
 if (abs($right) < 1e-12) {
 throw new InvalidProblemException('В формуле деление на ноль');
 }

 return $left / $right;
 }

 private function modulo(float $left, float $right): float
 {
 if (abs($right) < 1e-12) {
 throw new InvalidProblemException('В формуле остаток от деления на ноль');
 }

 return fmod($left, $right);
 }

 private function guardDepth(int $depth): void
 {
 if ($depth > self::MAX_DEPTH) {
 throw new InvalidProblemException('В формуле слишком много вложенных скобок');
 }
 }

 private function peek(): ?string
 {
 return $this->pos < strlen($this->input) ? $this->input[$this->pos] : null;
 }

 private function consume(): string
 {
 $char = $this->input[$this->pos] ?? '';
 $this->pos++;

 return $char;
 }

 /** Операторы объявлены для документации: набор поддерживается грамматикой выше. */
 public static function operators(): array
 {
 return self::OPERATORS;
 }
}
