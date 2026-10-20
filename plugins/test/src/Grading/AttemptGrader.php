<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Grading;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;

/**
 * Проверяет одну попытку целиком: прогоняет ответы через стратегии типов
 * вопросов, суммирует баллы и выставляет оценку по критериям автора.
 *
 * Класс не знает про БД и HTTP: на входе список вопросов и массив ответов,
 * на выходе GradingResult. Благодаря этому его можно вызывать и из обычного
 * теста, и из будущих интерактивных игр с другой схемой оценивания.
 */
final class AttemptGrader
{
 public function __construct(
 private readonly QuestionTypeRegistry $registry,
 private readonly int $maxTextLength = 4000
 ) {
 }

 /**
 * @param list<Question> $questions
 * @param array<int,mixed> $rawAnswers ответы, ключ — id вопроса
 * @param bool $enforceRequired требовать ответ на все обязательные вопросы
 *
 * @throws InvalidAnswerException если не заполнен обязательный вопрос
 */
 public function grade(
 array $questions,
 array $rawAnswers,
 GradeCalculator $calculator,
 bool $enforceRequired = true
 ): GradingResult {
  $items = [];
  $score = 0.0;
  $maxScore = 0.0;
  $penalty = 0.0;
  $missing = [];

 foreach ($questions as $index => $question) {
 $max = $question->maxScore();
 $maxScore += $max;

 $rawAnswer = $rawAnswers[$question->id] ?? null;
 $isEmpty = self::isEmpty($rawAnswer);

 if ($isEmpty && $question->isRequired) {
 /*
 * Ученику показываем порядковый номер вопроса в работе,
 * а не внутренний идентификатор записи в базе.
 */
 $missing[] = $index + 1;
 }

 $type = $this->registry->get($question->type);

 /*
 * Неверный формат ответа не должен ронять всю проверку:
 * считаем его пустым и отмечаем в разборе. Иначе один
 * неосторожно отправленный ответ уронил бы работу целиком.
 */
 $answer = null;
 $formatError = null;

 if (!$isEmpty) {
 try {
 $answer = $this->sanitize($type->normalizeAnswer($rawAnswer));
 } catch (InvalidAnswerException $e) {
 $formatError = $e->getMessage();
 $answer = null;
 }
 }

  $grade = $type->grade($question, $answer);
  $itemScore = min($grade->score, $max);
  $itemScore = max(0.0, $itemScore);
  $itemPenalty = max(0.0, $grade->penalty);

  $score += $itemScore;
  $penalty += $itemPenalty;

  $items[] = [
  'question_id' => $question->id,
  'prompt' => $question->prompt,
  'type' => $question->type,
  'type_title' => $type->title(),
  'answer_text' => $type->answerText($question, $answer),
  'answer_data' => $answer,
  'expected_text' => $grade->expectedText !== '' ? $grade->expectedText : $type->expectedText($question),
  'explanation' => $question->explanation,
  'is_correct' => $grade->isCorrect,
  'needs_review' => $grade->needsReview(),
  'score' => GradeCalculator::roundScore($itemScore),
  'max_score' => $max,
  'penalty' => GradeCalculator::roundScore($itemPenalty),
  'detail' => $formatError !== null
  ? ['format_error' => $formatError] + $grade->detail
  : $grade->detail,
  ];
  }

 if ($enforceRequired && $missing !== []) {
 throw new InvalidAnswerException(
 'Ответьте на задания: ' . implode(', ', $missing)
 );
 }

  $score = GradeCalculator::roundScore($score);
  $maxScore = GradeCalculator::roundScore($maxScore);
  $penalty = GradeCalculator::roundScore($penalty);

  /*
   * Процент и оценка считаются по итогу без штрафа.
   *
   * Штраф — величина отдельная, но решение о оценке принимается по тому, что
   * ученик реально принёс: набрал за вычетом штрафа. Иначе штраф был бы
   * показан в сводке, но не влиял бы на оценку, и две разные величины
   * назывались бы одним словом.
   */
  $net = max(0.0, $score - $penalty);
  $percent = $maxScore > 0.0 ? round($net / $maxScore * 100, 2) : 0.0;

  $verdict = $calculator->gradeFor($percent);

  return new GradingResult(
  $items,
  $score,
  $maxScore,
  $percent,
  $verdict['grade'],
  $verdict['label'],
  $penalty
  );
  }

 /**
 * Сколько вопросов каждого типа в работе.
 *
 * @param list<Question> $questions
 * @return array<string,int>
 */
 public function typeSummary(array $questions): array
 {
 $summary = [];

 foreach ($questions as $question) {
 $summary[$question->type] = ($summary[$question->type] ?? 0) + 1;
 }

 return $summary;
 }

 /**
 * Ответ пустой: нет значения, пустая строка или пустой массив.
 *
 * @param mixed $value
 */
 private static function isEmpty(mixed $value): bool
 {
 return $value === null
 || $value === ''
 || $value === []
 || (is_string($value) && trim($value) === '');
 }

 /**
 * Обрезка слишком длинного текста.
 *
 * Ученик может прислать что угодно, а работать с мегабайтом текста в
 * разборе незачем.
 *
 * @param mixed $answer
 * @return mixed
 */
 private function sanitize(mixed $answer)
 {
 if (!is_string($answer)) {
 return $answer;
 }

 if (mb_strlen($answer, 'UTF-8') <= $this->maxTextLength) {
 return $answer;
 }

 return mb_substr($answer, 0, $this->maxTextLength, 'UTF-8');
 }
}