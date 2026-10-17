<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Work;

use MyEduPlugin\Test\Question\AnswerText;
use MyEdu\Core\Config;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\View;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\ItemGrade;
use MyEduPlugin\Test\Grading\AttemptGrader;
use MyEduPlugin\Test\Grading\GradeCalculator;
use MyEduPlugin\Test\Grading\GradingResult;
use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;
use MyEduPlugin\Test\Repository\TestRepository;
use MyEduPlugin\Test\Repository\WorkItemRepository;

/**
 * Задание «тест с вопросами» внутри работы.
 *
 * Тест здесь — одно задание работы: ядро держит попытку по всей работе и
 * навигацию между позициями, плагин отвечает за один экран со своими
 * вопросами, за сохранение ответов и за их проверку.
 *
 * В отличие от отдельного теста все вопросы показываются на одной странице:
 * внутри работы переключение вопроса было бы вторым уровнем навигации поверх
 * навигации ядра, и ученик терялся бы в двух списках подряд.
 *
 * Переиспользуется готовая логика AttemptGrader и типы вопросов. Правила
 * проверки у отдельного теста и у теста внутри работы одни и те же; писать их
 * второй раз — прямой путь к тому, что баллы начнут расходиться.
 */
final class WorkItemHandler
{
 public function __construct(
 private readonly View $view,
 private readonly Config $config,
 private readonly QuestionTypeRegistry $registry,
 private readonly TestRepository $tests,
 private readonly WorkItemRepository $items,
 private readonly AttemptGrader $grader
 ) {
 }

 /**
 * Экран задания для ученика.
 *
 * Ответы восстанавливаются из базы ровно в том виде, в каком пришли из
 * формы, поэтому возврат к заданию сводится к отметке нужных полей.
 */
 public function render(AttemptContext $context): Response
 {
 $testId = $context->task->taskId;
 $test = $this->tests->find($testId);

 if ($test === null) {
 throw new \RuntimeException('Тест не найден: ' . $testId);
 }

 $questions = $this->tests->questions($testId);

 if ($questions === []) {
 return Response::html($this->view->render('test/work_empty', [
 'title' => 'Задание №' . (string) ($context->item['position'] ?? '?'),
 'position' => $context->position,
 'total' => $context->totalItems,
 'attemptUrl' => $context->attemptUrl(),
 ]));
 }

 $itemAttempt = $this->items->open($context->attemptId, (int) $context->item['id'], $testId);

 if ($itemAttempt === null) {
 throw new \RuntimeException('Не удалось открыть прохождение задания');
 }

  $itemAttemptId = (int) $itemAttempt['id'];
  $drafts = $this->items->draftAnswers($itemAttemptId);
  $answered = $this->items->answeredMap($itemAttemptId);

  $returnedAnswers = $context->submittedInput['answers'] ?? [];

  if (is_array($returnedAnswers)) {
  $questionIds = [];

  foreach ($questions as $question) {
  $questionIds[$question->id] = true;
  }

  foreach ($returnedAnswers as $questionId => $answer) {
  $questionId = (int) $questionId;

  if (isset($questionIds[$questionId])) {
  $drafts[$questionId] = $answer;
  }
  }
  }

 return Response::html($this->view->render('test/work_task', [
 'title' => 'Задание №' . (string) ($context->item['position'] ?? '?'),
 'test' => $test,
 'questions' => $this->prepare($questions, $context, (bool) ($test['shuffle_options'] ?? 0)),
 'drafts' => $drafts,
 'answeredCount' => count($answered),
 'maxScore' => $this->tests->maxScore($testId),
 'position' => $context->position,
 'positions' => $context->totalItems,
 'weight' => $context->weight(),
 'itemId' => (int) $context->item['id'],
 'attemptId' => $context->attemptId,
 'token' => $context->token(),
 'attemptUrl' => $context->attemptUrl(),
 'isLast' => $context->position >= $context->totalItems,
 ]));
 }

 /**
 * Приём ответов.
 *
 * Ориентируемся на маркер answer_present, а не на наличие ключа в answers:
 * браузер не отправляет снятый флажок вообще, и «очистить ответ» без
 * маркера не отличить от обычного перехода.
 */
 public function saveAnswer(AttemptContext $context, Request $request): void
 {
 $itemAttempt = $this->items->open(
 $context->attemptId,
 (int) $context->item['id'],
 $context->task->taskId
 );

 if ($itemAttempt === null) {
 throw new \RuntimeException('Не удалось открыть прохождение задания');
 }

$itemAttemptId = (int) $itemAttempt['id'];
 $present = $request->postArray('answer_present');
 $answers = $request->postArray('answers');

 foreach ($present as $questionId => $_) {
 if (!is_scalar($questionId)) {
 continue;
 }

 $questionId = (int) $questionId;
 $answer = $answers[$questionId] ?? null;

 $this->items->saveDraft($itemAttemptId, $questionId, $answer);
 }
 }

 /**
 * Проверка задания.
 *
 * Плагин отвечает только за своё задание: сколько набрано баллов и нужен
 * ли учитель. Итог по всей работе с весами позиций считает ядро.
 */
 public function grade(AttemptContext $context): ItemGrade
 {
 $testId = $context->task->taskId;
 $test = $this->tests->find($testId);
 $questions = $this->tests->questions($testId);

 if ($test === null || $questions === []) {
 return ItemGrade::forReview(0.0, ['error' => 'Тест не найден или пуст']);
 }

 $itemAttempt = $this->items->find($context->attemptId, (int) $context->item['id']);

 if ($itemAttempt === null) {
 return ItemGrade::forReview($this->tests->maxScore($testId), [
 'error' => 'Ученик не открывал это задание',
 ]);
 }

 $itemAttemptId = (int) $itemAttempt['id'];

 /*
 * Калькулятору передаётся пусто: своей шкалы у задания больше нет, она
 * принадлежит работе и применяется ядром. Раньше сюда шёл
 * TestRepository::criteria(), который читался из test_criteria — таблицы,
 * куда не писал никто, то есть всегда отдавал пустоту, и калькулятор так
 * же молча переходил на шкалу по умолчанию. Читать стало нечего:
 * таблица снята миграцией 016.
 */
 $calculator = GradeCalculator::create($this->config);

 try {
 /*
 * Обязательность не проверяется: не заполненный один вопрос не должен
 * блокировать всю работу. Пустой вопрос получает ноль, и это видно
 * в разборе вместе со списком номеров незаполненных.
 */
 $result = $this->grader->grade(
 $questions,
 $this->items->draftAnswers($itemAttemptId),
 $calculator,
 false
 );
 } catch (InvalidAnswerException $e) {
 return ItemGrade::forReview($this->tests->maxScore($testId), [
 'error' => $e->getMessage(),
 ]);
 }

 $this->items->saveGrading($itemAttemptId, $result);

 $snapshot = [
 'result' => $result->toArray(),
 'counts' => [
 'correct' => $result->countCorrect(),
 'partial' => $result->countPartial(),
 'wrong' => $result->countWrong(),
 ],
 ];

 /*
 * needsReview всплывает наверх: если хотя бы один вопрос внутри теста
 * ждёт учителя, то и задание целиком нельзя считать проверенным.
 */
 if ($result->hasUnreviewed()) {
 return ItemGrade::forReview($result->maxScore, $snapshot);
 }

 return ItemGrade::scored(
 $result->score,
 $result->maxScore,
 $snapshot,
 $result->grade
 );
 }

/**
 * Разбор задания для страницы результата работы.
 *
 * Строится из снимка, сохранённого при проверке: правка вопроса автором
 * не должна менять то, что ученик видел тогда.
 *
 * Это фрагмент, а не страница: разбор встраивается в результат работы
 * рядом с другими заданиями. Оболочка приходит извне, и если завернуть
 * фрагмент ещё и здесь, то внутри готовой страницы появится вторая
 * копия шапки и меню — ровно то, что видели при проверке пробной работы.
 */
 public function renderReview(AttemptContext $context, ItemGrade $grade): Response
 {
 return Response::html($this->view->render('test/work_review', [
 'title' => 'Задание №' . (string) ($context->item['position'] ?? '?'),
 'position' => $context->position,
 'total' => $context->totalItems,
 'result' => $this->resultFromSnapshot($grade->snapshot),
 'score' => $grade->score,
 'maxScore' => $grade->maxScore,
 'needsReview' => $grade->needsReview,
 'error' => is_string($grade->snapshot['error'] ?? null) ? $grade->snapshot['error'] : null,
 ], ''));
 }

 /**
 * Ответ ученика для ручной проверки учителем.
 *
 * Показывается всё, что ученик написал или отметил, независимо от того,
 * проверено оно или нет: проверять не видя ответа бессмысленно.
 */
 public function renderSubmitted(AttemptContext $context): ?Response
 {
 $itemAttempt = $this->items->find($context->attemptId, (int) $context->item['id']);

 if ($itemAttempt === null) {
 return null;
 }

 $answers = $this->items->submittedAnswers((int) $itemAttempt['id']);

 if ($answers === []) {
 return null;
 }

 $questions = $this->byId($this->tests->questions($context->task->taskId));
 $rows = [];

 foreach ($answers as $answer) {
 $questionId = (int) $answer['question_id'];
 $question = $questions[$questionId] ?? null;
 $type = $question === null ? null : $this->registry->get($question->type);

 $rows[] = [
 'prompt' => $question?->prompt ?? ('Вопрос #' . $questionId),
 'type' => $type?->title() ?? '',
 'answer' => AnswerText::format($type, $question, $answer),
 'checked' => $answer['is_correct'] !== null,
 'correct' => $answer['is_correct'] !== null && (int) $answer['is_correct'] === 1,
 ];
 }

 return Response::html($this->view->render('test/work_submitted', [
 'title' => 'Задание №' . (string) ($context->item['position'] ?? '?'),
 'rows' => $rows,
 ], ''));
 }

 // ---------------------------------------------------------------- служебное

 /**
 * Вопросы в виде, пригодном для отрисовки.
 *
 * Перемешивание привязано к паре «попытка + позиция» и делается на сервере:
 * при обновлении страницы порядок не меняется, но восстановить его из
 * исходного кода нельзя.
 *
 * @param list<Question> $questions
 * @return list<array<string,mixed>>
 */
 private function prepare(array $questions, AttemptContext $context, bool $shuffleOptions): array
 {
 $seed = crc32($context->attemptId . ':' . (int) $context->item['id']);

 $prepared = [];

foreach ($questions as $index => $question) {
 $runtime = $question->withRuntime($seed + $question->id, $shuffleOptions);

 $prepared[] = [
 'number' => $index + 1,
 'id' => $runtime->id,
 'type' => $runtime->type,
 'typeTitle' => $this->registry->get($runtime->type)->title(),
 'prompt' => $runtime->prompt,
 'weight' => $runtime->weight,
 'required' => $runtime->isRequired,
 'payload' => $runtime->payload(),
 ];
 }

 if ((bool) ($context->taskData['shuffle_questions'] ?? false)) {
 $prepared = $this->shuffle($prepared, $seed);

 foreach ($prepared as $index => $_) {
 $prepared[$index]['number'] = $index + 1;
 }
 }

 return $prepared;
 }

 /**
 * GradingResult из снимка ItemGrade.
 *
 * Числа берутся из снимка, а не из самого ItemGrade: балл мог поправить
 * учитель, а разбор всё равно описывает то, что было проверено.
 */
 private function resultFromSnapshot(array $snapshot): ?GradingResult
 {
 $raw = $snapshot['result'] ?? null;

 if (!is_array($raw)) {
 return null;
 }

 $items = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];

 return GradingResult::fromArray(
 $items,
 (float) ($raw['score'] ?? 0.0),
 (float) ($raw['max_score'] ?? 0.0)
 );
 }

 /**
 * @param list<Question> $questions
 * @return array<int,Question>
 */
 private function byId(array $questions): array
 {
 $map = [];

 foreach ($questions as $question) {
 $map[$question->id] = $question;
 }

 return $map;
 }

 /**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
 private function shuffle(array $items, int $seed): array
 {
 mt_srand($seed);
 $count = count($items);

 for ($i = $count - 1; $i > 0; $i--) {
 $j = mt_rand(0, $i);
 [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
 }

 mt_srand();

 return $items;
 }
}
