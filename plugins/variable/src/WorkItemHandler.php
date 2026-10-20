<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\View;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\ItemGrade;
use MyEduPlugin\Variable\Repository\AttemptRepository;
use MyEduPlugin\Variable\Repository\TaskRepository;

/**
 * Прохождение вариативной задания учеником.
 *
 * Ядро держит попытку по всей работе и навигацию между позициями, плагин
 * отвечает за один экран, приём ответа и проверку.
 *
 * Значения варианта выбираются из seed'а, который выводится ядром из пары
 * «попытка + позиция»: обновление страницы не меняет пример. Но при проверке
 * seed'ом не пользуются — берутся значения, сохранённые вместе с ответом.
 * Иначе правка учителем перечня значений изменила бы пример под тем, кто
 * уже ответил, и верный ответ стал бы неверным.
 */
final class WorkItemHandler
{
    /** Сколько знаков после запятой показывать в примере. */
    private const MAX_TEXT = 32;

    public function __construct(
        private readonly View $view,
        private readonly TaskRepository $tasks,
        private readonly AttemptRepository $attempts,
        private readonly ProblemGrader $grader
    ) {
    }

    /**
     * Экран задания для ученика.
     *
     * Ответы восстанавливаются из базы ровно в том виде, в каком пришли из
     * формы, поэтому возврат к заданию сводится к заполнению полей.
     */
    public function render(AttemptContext $context): Response
    {
        $task = $this->loadTask($context->task->taskId);
        $config = is_array($task['config'] ?? null) ? $task['config'] : [];
        $variables = ProblemConfig::variables($config);

        $itemAttempt = $this->attempts->open(
            $context->attemptId,
            (int) $context->item['id'],
            $context->task->taskId
        );

        if ($itemAttempt === null) {
            throw new \RuntimeException('Не удалось открыть прохождение задания');
        }

        $itemAttemptId = (int) $itemAttempt['id'];
        $stored = $this->attempts->answer($itemAttemptId);
        $answer = $this->grader->normalize($config, $stored['answer_data'] ?? null);

        if ($context->submittedInput !== []) {
            $returned = $this->grader->normalize($config, [
                'answer' => $context->submittedInput['answer'] ?? null,
                'values' => $context->submittedInput['values'] ?? [],
            ]);

            if ($returned['values'] !== []) {
                $answer = $returned;
            }
        }

        /*
         * Показанные значения: сохранённые, если ответ уже есть, иначе новые.
         *
         * Иначе после возврата к заданию ученик увидел бы одни числа, а в
         * проверку ушли бы другие — и верный ответ превратился бы в неверный
         * по собственной инициативе плагина.
         */
        $values = $answer['values'];
        $fresh = $this->pick($variables, $this->seed($context));

        if ($values === []) {
            $values = $fresh;
        }

        return Response::html($this->view->render('variable/render', [
            'title'      => 'Задание №' . (string) ($context->item['position'] ?? '?'),
            'prompt'     => VariableGenerator::substitute((string) $task['prompt'], $values),
            'values'     => $values,
            'answer'     => $answer['answer'],
            'unit'       => ProblemConfig::unit($config),
            'maxLength'  => self::MAX_TEXT,
            'position'   => $context->position,
            'total'      => $context->totalItems,
            'weight'     => $context->weight(),
            'itemId'     => (int) $context->item['id'],
            'attemptId'  => $context->attemptId,
            'token'      => $context->token(),
            'attemptUrl' => $context->attemptUrl(),
            'nextUrl'    => $context->itemUrl(),
            'isLast'     => $context->position >= $context->totalItems,
            'remaining'  => $context->remainingSeconds,
            'noCopy'     => $context->noCopy,
        ]));
    }

    /**
     * Приём ответа.
     *
     * Ориентируемся на маркер answer_present, а не на наличие ключа в answers:
     * браузер не отправляет снятый флажок вообще, и «очистить ответ» без
     * маркера не отличить от обычного перехода.
     */
    public function saveAnswer(AttemptContext $context, Request $request): void
    {
        $itemAttempt = $this->attempts->open(
            $context->attemptId,
            (int) $context->item['id'],
            $context->task->taskId
        );

        if ($itemAttempt === null) {
            throw new \RuntimeException('Не удалось открыть прохождение задания');
        }

        $task = $this->loadTask($context->task->taskId);
        $config = is_array($task['config'] ?? null) ? $task['config'] : [];

        $present = $request->postArray('answer_present');

        if ($present === []) {
            // Ученик ничего не отмечал: считать нечего, и затирать прежний
            // ответ нельзя — он мог прийти из другого перехода.
            return;
        }

        $this->attempts->saveAnswer(
            (int) $itemAttempt['id'],
            $this->grader->normalize($config, [
                'answer' => $request->postParam('answer'),
                'values' => $request->postArray('values'),
            ])
        );
    }

    /**
     * Проверка задания.
     *
     * Максимум берётся из позиции работы: цену задания задаёт учитель в
     * мастере, и ядро сводит итог именно по ней.
     */
    public function grade(AttemptContext $context): ItemGrade
    {
        $task = $this->loadTask($context->task->taskId);
        $config = is_array($task['config'] ?? null) ? $task['config'] : [];

        $itemMax = (float) ($context->item['max_score'] ?? 0.0);
        $max = $itemMax > 0.0 ? $itemMax : 1.0;

        $itemAttempt = $this->attempts->find($context->attemptId, (int) $context->item['id']);

        if ($itemAttempt === null) {
            return ItemGrade::forReview($max, ['error' => 'Ученик не открывал это задание']);
        }

        $itemAttemptId = (int) $itemAttempt['id'];
        $stored = $this->attempts->answer($itemAttemptId);

        if ($stored === null) {
            return ItemGrade::scored(0.0, $max, [
                'prompt'        => (string) $task['prompt'],
                'answer_text'   => '— ответ не введён —',
                'values'        => [],
                'expected'      => null,
                'given'         => null,
                'unit'          => ProblemConfig::unit($config),
                'expected_text' => '',
            ]);
        }

        $answer = $this->grader->normalize($config, $stored['answer_data'] ?? null);
        $grade = $this->grader->grade($task, $answer, $max);

        $this->attempts->saveGrade(
            $itemAttemptId,
            $grade->score,
            $grade->maxScore,
            $grade->score >= $grade->maxScore && $grade->maxScore > 0,
            $grade->snapshot
        );

        return $grade;
    }

    /**
     * Разбор задания для страницы результата работы.
     *
     * Это фрагмент, а не страница: разбор встраивается в результат работы
     * рядом с другими заданиями, и оболочка приходит извне.
     */
    public function renderReview(AttemptContext $context, ItemGrade $grade): Response
    {
        $snapshot = $grade->snapshot;

        return Response::html($this->view->render('variable/review', [
            'title'    => 'Задание №' . (string) ($context->item['position'] ?? '?'),
            'position' => $context->position,
            'total'    => $context->totalItems,
            'score'    => $grade->score,
            'maxScore' => $grade->maxScore,
            'correct'  => $grade->score > 0.0 && $grade->score >= $grade->maxScore,
            'error'    => is_string($snapshot['error'] ?? null) ? $snapshot['error'] : null,
            'prompt'   => (string) ($snapshot['prompt'] ?? ''),
            'values'   => is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [],
            'unit'     => (string) ($snapshot['unit'] ?? ''),
            'answerText' => (string) ($snapshot['answer_text'] ?? ''),
            'expectedText' => (string) ($snapshot['expected_text'] ?? ''),
        ], ''));
    }

    /**
     * Ответ ученика для ручной проверки учителем.
     *
     * Показывается всё, что ученик ввёл, независимо от того, проверено оно или
     * нет: проверять не видя ответа бессмысленно.
     */
    public function renderSubmitted(AttemptContext $context): ?Response
    {
        $itemAttempt = $this->attempts->find($context->attemptId, (int) $context->item['id']);

        if ($itemAttempt === null) {
            return null;
        }

        $stored = $this->attempts->answer((int) $itemAttempt['id']);

        if ($stored === null) {
            return null;
        }

        $task = $this->loadTask($context->task->taskId);
        $config = is_array($task['config'] ?? null) ? $task['config'] : [];
        $answer = $this->grader->normalize($config, $stored['answer_data'] ?? null);

        if ($answer['answer'] === null) {
            return null;
        }

        return Response::html($this->view->render('variable/submitted', [
            'title'      => 'Задание №' . (string) ($context->item['position'] ?? '?'),
            'answerText' => $this->grader->answerText($answer['answer'], ProblemConfig::unit($config)),
            'prompt'     => VariableGenerator::substitute(
                (string) $task['prompt'],
                $answer['values']
            ),
        ], ''));
    }

    /**
     * Seed варианта.
     *
     * Ядро передаёт его в позиции работы; выводится из пары «попытка + позиция»,
     * поэтому числа у ученика не меняются ни при обновлении страницы, ни при
     * возврате к заданию.
     */
    private function seed(AttemptContext $context): int
    {
        return $context->attemptId * 1000003 + (int) $context->item['id'];
    }

    /**
     * @param list<array<string,mixed>> $variables
     *
     * @return array<string,float>
     */
    private function pick(array $variables, int $seed): array
    {
        try {
            return VariableGenerator::pick($variables, $seed);
        } catch (InvalidProblemException) {
            /*
             * Задание сохранено с настройкой, которая сейчас не читается
             * (например, после правки вручную). Показывать пустые значения
             * хуже, чем первые допустимые.
             */
            return $this->grader->firstValues($variables);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadTask(int $taskId): array
    {
        $task = $this->tasks->find($taskId);

        if ($task === null) {
            throw new \RuntimeException('Задание не найдено: ' . $taskId);
        }

        return $task;
    }
}
