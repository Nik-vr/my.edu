<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Auth\User;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Grading\GradeAggregator;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;

/**
 * Прохождение работы учеником: список позиций, начало попытки, переходы
 * между заданиями, завершение и результат.
 *
 * Ядро отвечает за навигацию по работе и за то, чтобы попытка была честной:
 * кто её начал, какая позиция сейчас, что ученик уже ответил. Содержимое
 * задания целиком рисует плагин — ядро не знает, что это за задание.
 *
 * Ошибка одного плагина не должна убирать у ученика остальные задания: позиция
 * с неработающим плагином помечается в списке и пропускается, а остальные
 * проходятся как обычно.
 */
final class WorkController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Database $db,
        private readonly AssignmentRepository $assignments,
        private readonly PluginManager $plugins
    ) {
    }

    /**
     * Число позиций работы, уже посчитанное в этом запросе.
     *
     * Число заданий у работы одно, а context() собирается заново на каждую
     * позицию — на результате работы с двадцатью заданиями это двадцать
     * одинаковых запросов. Жизнь контроллера совпадает с запросом, поэтому
     * кэш не может устареть: позиции посреди отрисовки не меняются.
     *
     * @var array<int,int>
     */
    private array $itemCountCache = [];

    private function itemCount(int $assignmentId): int
    {
        $id = (int) $assignmentId;

        return $this->itemCountCache[$id]
            ??= $this->assignments->countItems($id);
    }

    /**
     * Список опубликованных работ — то, что ученику доступно.
     *
     * Показывается и сотрудникам: они ходят по тем же адресам.
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        return $this->render('work/index', [
            'title' => 'Работы',
            'rows'  => $this->withProgress(
                $this->publishedWorks($user),
                $user?->id
            ),
            'notice' => $this->session->pull('flash_notice'),
            'error'  => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Опубликованные работы, доступные пользователю.
     *
     * @return list<array<string,mixed>>
     */
    private function publishedWorks(?User $user): array
    {
        return $this->assignments->allPublished(
            $user?->id,
            $user !== null && $this->auth->can(Role::TESTS_MANAGE)
        );
    }

    /**
     * Сама работа: список позиций с прогрессом и кнопкой «Начать».
     */
    public function show(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');
        $assignment = $this->requirePublished($id);

        $user = $this->auth->user();

        /*
         * Сотрудник видит всё, ученик — только то, что ему лично выдали и что
         * ещё не закрыто по сроку. Проверка здесь, а не в маршруте: маршрут
         * защищает вход, а видность решается по выдаче, и правило должно быть
         * одно для показа и для начала попытки.
         */
        if ($user !== null
            && !$this->auth->can(Role::TESTS_MANAGE)
            && !$this->assignments->visibleTo($id, $user->id)
        ) {
            throw new HttpException(404, 'Работа не найдена');
        }

        $attempt = $user === null ? null : $this->assignments->findLastAttempt($id, $user->id);
        $rows = $this->positionRows($assignment, $attempt);

        return $this->render('work/show', [
            'title'      => (string) $assignment['title'],
            'assignment' => $assignment,
            'items'      => $rows,
            'attempt'    => $attempt,
            'totalItems' => count($rows),
            'doneCount'  => count(array_filter($rows, static fn (array $r): bool => $r['done'])),
            'attemptsLeft' => $this->attemptsLeft($id, $user),
            // Сотрудник проходит работу сколько угодно раз: он проверяет её
            // после правок, и ограничение мешало бы ему это делать.
            'mayRepeat'    => $this->auth->can(Role::TESTS_REPEAT),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Сколько попыток у ученика осталось.
     *
     * null означает «без ограничения». У сотрудников ограничение не действует,
     * поэтому им показывается то же самое, что и при лимите 1, — бесконечность.
     */
private function attemptsLeft(int $assignmentId, ?User $user): ?int
{
    if ($user === null) {
        return null;
    }

        /*
         * Лимит принадлежит выдаче, а не работе: одну и ту же работу можно
         * выдать двум классам с разным числом попыток, и спрашивать его надо
         * у той выдачи, по которой ученик её получил.
         */
        $limit = $this->assignments->attemptLimit($assignmentId, $user->id);

        if ($limit === null || $this->auth->can(Role::TESTS_REPEAT)) {
            return null;
        }

        return max(0, $limit - $this->assignments->countAttempts($assignmentId, $user->id));
    }

    /**
     * Начало попытки.
     *
     * Токен выдаётся здесь и живёт в адресе: без него нельзя ни открыть
     * чужую попытку, ни посмотреть чужой результат простым подбором номера.
     */
    public function start(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $assignment = $this->requirePublished($id);

        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(403, 'Войдите в систему, чтобы начать работу');
        }

        /*
         * Видимость проверяется и здесь, а не только при показе работы.
         * Иначе выдача держится на одной странице: ученик, которому работу не
         * выдавали, получает 404 на /work/{id}, но прямым POST на
         * /work/{id}/start всё равно заводит попытку, то есть начинает работу,
         * которую видеть не должен. Правило одно и то же в обоих местах.
         */
        if (!$this->auth->can(Role::TESTS_MANAGE) && !$this->assignments->visibleTo($id, $user->id)) {
            throw new HttpException(404, 'Работа не найдена');
        }

        /*
         * Ограничение числа попыток относится к ученикам: учитель должен
         * прогонять работу сколько угодно раз, иначе после правки задания
         * нельзя было бы проверить, что всё сошлось. Завучу и администратору
         * ограничение тоже не нужно.
         */
        if (!$this->auth->can(Role::TESTS_REPEAT)) {
            $limit = $this->assignments->attemptLimit($id, $user->id);

            if ($limit !== null && $this->assignments->countAttempts($id, $user->id) >= $limit) {
                $this->session->set('flash_error', 'Вы уже выполнили эту работу максимальное число раз.');

                return $this->redirect('work/' . $id);
            }
        }

        $started = $this->assignments->startAttempt(
            $id,
            $user->id,
            bin2hex(random_bytes(32)),
            $this->auth->can(Role::TESTS_REPEAT)
        );

        if (!$started['ok']) {
            $this->session->set('flash_error', $started['message']);

            return $this->redirect('work/' . $id);
        }

        /** @var array<string,mixed> $attempt */
        $attempt = $started['attempt'];

        /*
         * Начинаем с первой позиции — той карточки первой строки, которая
         * выпала именно этой попытке.
         *
         * Раньше здесь бралась просто первая карточка строки. Со строками из
         * вариантов это отправляло ученика на задание, которого он выполнять
         * не будет: проверка считает выпавший вариант, и ответы ученика
         * попадали бы не туда, куда он их вводил.
         */
        $first = $this->assignments->itemByStudentNumber($id, (int) $attempt['id'], 1);

        return $this->redirect(sprintf(
            'work/%d/item/%d?t=%s',
            (int) $attempt['id'],
            (int) ($first['id'] ?? 0),
            (string) $attempt['token']
        ));
    }

    /**
     * Экран одного задания работы.
     *
     * Внутри рисует плагин: ядро передаёт ему позицию, номер в последовательности
     * и попытку, а само отвечает только за рамку — куда идти дальше.
     */
    public function item(Request $request, array $params): Response
    {
        $attemptId = $this->intParam($params, 'attemptId');
        $itemId = $this->intParam($params, 'itemId');

        $attempt = $this->requireOwnAttempt($attemptId, $request->queryParam('t'));
        $item = $this->requireItem($attempt, $itemId);

        if ($this->isFinished($attempt)) {
            return $this->resultRedirect($attempt);
        }

        $context = $this->context($attempt, $item, true);

        // Битый плагин не должен ронять страницу целиком.
        try {
            return $this->plugins->get($context->task->pluginId)?->renderTask($context)
                ?? $this->itemUnavailable($attempt, $item);
        } catch (\Throwable $e) {
            $this->plugins->recordRuntimeFailure($context->task->pluginId, $e);
            $this->reportPluginError($e, $attempt, $item);

            return $this->itemUnavailable($attempt, $item);
        }
    }

    /**
     * Приём ответа и переход к следующему заданию.
     *
     * Формы отправляются по одной позиции за раз, поэтому ядро знает, какое
     * задание сейчас, и не зависит от того, что прислал браузер.
     */
    public function saveAnswer(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $attemptId = $this->intParam($params, 'attemptId');
        $itemId = $this->intParam($params, 'itemId');

        $attempt = $this->requireOwnAttempt($attemptId, $request->postParam('t'));
        $item = $this->requireItem($attempt, $itemId);

        if ($this->isFinished($attempt)) {
            return $this->resultRedirect($attempt);
        }

        /*
 * Время вышло.
 *
 * Раньше на истечении работа просто завершалась, и ответ на последнее задание
 * пропадал: таймер на странице отправляет форму уже после истечения, то есть
 * ровно тогда, когда ученик дописал ответ и нажал «Далее» или не успел нажать
 * ничего. Работа уходила на проверку без последнего ответа, а человек узнавал
 * об этом только на странице результата — и уже не мог ничего вернуть.
 *
 * Поэтому ответ сохраняется ДО завершения, и ученику говорится, что произошло.
 * Дедлайн при этом не обходится: сервер и раньше не принимал ответ после
 * истечения, и здесь он тоже не принимает — просто не выбрасывает уже отправленное.
 */
if ($this->expired($attempt)) {
    $failed = $this->saveAnswerThroughPlugin($request, $attempt, $item);

    $this->session->set('flash_notice', $failed === null
        ? 'Время вышло. Ответ на последнее задание сохранён, работа отправлена на проверку.'
        : 'Время вышло. Работа отправлена на проверку, но ответ на последнее задание '
            . 'сохранить не удалось: ' . $failed . ' Обратитесь к учителю.');

    return $this->finish($request, $params);
}

        $failed = $this->saveAnswerThroughPlugin($request, $attempt, $item);

        if ($failed !== null) {
            $this->session->set('flash_error', $failed);

            return $this->attemptRedirect($attempt, $item);
        }

        if ($request->postParam('action') === 'finish') {
            return $this->finish($request, $params);
        }

        $step = $request->postParam('action') === 'prev' ? -1 : 1;

        return $this->attemptRedirect($attempt, $this->nextItem($attempt, $item, $step));
    }

    /**
     * Сохранить ответ через плагин, не роняя страницу.
     *
     * Общая часть для обычного перехода и для истечения времени: раньше она
     * была написана в одном месте, и второе место — истечение — её обходило,
     * теряя ответ. Отдельный метод нужен ещё и потому, что у двух вызовов
     * разные последствия отказа: обычный переход возвращает человека к
     * заданию, истечение же всё равно завершает работу.
     *
     * Возвращает null, если ответ сохранён, иначе — что сказать человеку.
     */
    private function saveAnswerThroughPlugin(Request $request, array $attempt, array $item): ?string
    {
        $attemptId = (int) $attempt['id'];
        $itemId = (int) $item['id'];
        $context = $this->context($attempt, $item);
        $plugin = $this->plugins->get($context->task->pluginId);

        if ($plugin === null) {
            $this->rememberFailedAnswer($request, $attemptId, $itemId);

            return 'плагин этого задания сейчас недоступен, ответ оставлен только в этой сессии';
        }

        try {
            $this->db->savepoint(function () use ($plugin, $context, $request): void {
                $plugin->saveAnswer($context, $request);
            });
        } catch (\Throwable $e) {
            // плагин остаётся включённым. Подробности — в журнале плагинов.
            // Ответ ученика или его разбор, а не поломка плагина:
            $this->plugins->recordItemFailure($context->task->pluginId, $e);
            $this->reportPluginError($e, $attempt, $item);
            $this->rememberFailedAnswer($request, $attemptId, $itemId);

            return 'не удалось сохранить ответ, попробуйте ещё раз или обратитесь к учителю';
        }

        $this->clearFailedAnswer($attemptId, $itemId);

        return null;
    }

    /**
     * Возврат к списку позиций без сохранения ответа.
     */
    public function pause(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $attempt = $this->requireOwnAttempt(
            $this->intParam($params, 'attemptId'),
            $request->postParam('t')
        );

        return $this->attemptRedirect($attempt);
    }

    /**
     * Завершение работы.
     *
     * Проверяются все позиции, и каждая отдаёт ядру ItemGrade. Хотя бы одна
     * позиция без автопроверки помечает попытку как ждущую учителя.
     */
    public function finish(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $attemptId = $this->intParam($params, 'attemptId');
        $attempt = $this->requireOwnAttempt($attemptId, $request->postParam('t') ?? $request->queryParam('t'));

        if ($this->isFinished($attempt)) {
            return $this->resultRedirect($attempt);
        }

        /*
         * Штраф за опоздание фиксируется до подсчёта итога: он должен попасть
         * в тот же пересчёт, что и баллы, иначе сводка показала бы «набрано»
         * без вычета.
         */
        $this->applyLatePenalty($attempt);

        $this->gradeAttempt($attempt);

        return $this->resultRedirect($this->assignments->findAttempt($attemptId) ?? $attempt);
    }

    /**
     * Штраф за сдачу после срока.
     *
     * Срок берётся у выдачи, по которой ученик получил работу: у разных
     * выдач сроки разные, и срок «всей работы» не значил бы ничего.
     *
     * Штраф фиксируется один раз — в момент сдачи. Срок потом может быть
     * изменён, и опоздавшая работа не должна от этого вдруг стать вовремя.
     *
     * @param array<string,mixed> $attempt
     */
    private function applyLatePenalty(array $attempt): void
    {
        $assignmentId = (int) $attempt['assignment_id'];
        $assignment = $this->assignments->find($assignmentId);
        $penalty = max(0.0, (float) ($assignment['late_penalty'] ?? 0.0));

        if ($penalty <= 0.0) {
            return;
        }

        $deadline = $this->assignments->deadlineForStudent(
            $assignmentId,
            (int) $attempt['student_id']
        );

        if ($deadline === null || $deadline === '' || date('Y-m-d H:i:s') <= $deadline) {
            return;
        }

        $this->assignments->updateAttempt(
            ['late_penalty' => $penalty],
            ['id' => (int) $attempt['id']]
        );
    }

    /**
     * Результат работы и разбор по позициям.
     */
    public function result(Request $request, array $params): Response
    {
        $attempt = $this->requireOwnAttempt(
            $this->intParam($params, 'attemptId'),
            $request->queryParam('t')
        );

        if (!$this->isFinished($attempt)) {
            throw new HttpException(404, 'Работа ещё не завершена');
        }

        $assignment = $this->requireAssignment($attempt);
        $rows = $this->assignments->resultsForAttempt((int) $attempt['id']);

        $total = GradeAggregator::create($this->config, $this->criteria($assignment))
            ->aggregate(
                $rows,
                (string) $assignment['grade_mode'],
                (string) ($assignment['criteria_mode'] ?? 'percent'),
                max(0.0, (float) ($attempt['late_penalty'] ?? 0.0))
                    + max(0.0, (float) ($attempt['manual_penalty'] ?? 0.0))
            );

        $showResults = (int) $assignment['show_results'] === 1;
        $reviews = $this->renderReviews($attempt, $rows, $showResults);

        return $this->render('work/result', [
            'title'       => 'Результат: ' . (string) $assignment['title'],
            'assignment'  => $assignment,
            'attempt'     => $attempt,
            'total'       => $total,
            'items'       => $reviews,
            'showResults' => $showResults,
            'notice'      => $this->session->pull('flash_notice'),
            'error'       => $this->session->pull('flash_error'),
        ]);
    }

    // ---------------------------------------------------------------- проверка

/**
     * Проверка попытки.
     *
     * Проверяются только задания, которые эта попытка видела: по одному на
     * строку. Проверять альтернативы нельзя — у них нет ни ответа, ни
     * результата, и в итог работы попали бы строки, которых ученик не
     * выполнял.
     *
     * Каждая позиция проверяется отдельно и результат кладётся в базу сразу:
     * если один плагин упадёт, остальные позиции останутся проверенными, и
     * учитель увидит, что именно сломалось.
     *
     * @param array<string,mixed> $attempt
     */
    private function gradeAttempt(array $attempt): void
    {
        $assignmentId = (int) $attempt['assignment_id'];
        $attemptId = (int) $attempt['id'];

        $needsReview = false;

        foreach ($this->assignments->itemsForAttempt($assignmentId, $attemptId) as $item) {
            $plugin = $this->plugins->get((string) $item['plugin_id']);

            if ($plugin === null) {
                $needsReview = true;

                $this->assignments->saveResult($attemptId, (int) $item['id'], [
                    'status'       => 'pending',
                    'needs_review' => 1,
                    'max_score'    => null,
                ]);

                continue;
            }

            $context = $this->context($attempt, $item);

            try {
                $grade = $plugin->grade($context);
            } catch (\Throwable $e) {
            // плагин остаётся включённым. Подробности — в журнале плагинов.
            // Ответ ученика или его разбор, а не поломка плагина:
                $this->plugins->recordItemFailure((string) $item['plugin_id'], $e);
                $this->reportPluginError($e, $attempt, $item);

                $needsReview = true;

                $this->assignments->saveResult($attemptId, (int) $item['id'], [
                    'status'       => 'pending',
                    'needs_review' => 1,
                    'max_score'    => null,
                    'comment'      => 'Автоматическая проверка временно недоступна. Задание проверит учитель.',
                ]);

                continue;
            }

            $needsReview = $needsReview || $grade->needsReview;

            /*
             * Штраф позиции пишется отдельно от балла.
             *
             * Балл — это то, что заработано за верную часть ответа, а штраф —
             * то, что снято за лишнее. В сводке работы они показываются порознь:
             * «набрано, штраф, итого».
             */
            $this->assignments->saveResult($attemptId, (int) $item['id'], [
                'score'        => $grade->score,
                'penalty'      => $grade->penalty,
                'max_score'    => $grade->maxScore,
                'percent'      => $grade->percent(),
                'grade'        => $grade->grade,
                'status'       => $grade->needsReview
                    ? 'pending'
                    : $this->itemStatus(
                        max(0.0, $grade->score - $grade->penalty),
                        $grade->maxScore,
                        $grade->needsReview
                    ),
                'needs_review' => $grade->needsReview ? 1 : 0,
                'snapshot'     => self::encode($grade->snapshot),
                'checked_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $this->updateTotals($attemptId, $needsReview);
    }

    /**
     * Пересчёт итога после проверки или после правки учителем.
     *
     * @param array<string,mixed> $attempt
     */
    public function updateTotals(int $attemptId, bool $needsReview): void
    {
        $attempt = $this->assignments->findAttempt($attemptId);

        if ($attempt === null) {
            return;
        }

        $rows = $this->assignments->resultsForAttempt($attemptId);

        /*
         * Штраф попытки складывается из двух частей: за опоздание (оно
         * фиксируется при сдаче и с тех пор не меняется) и ручной (его ставит
         * учитель при проверке). Штраф за лишние варианты сидит в позициях и
         * приходит из них — здесь считается только то, что относится к самой
         * попытке.
         */
        $attemptPenalty = max(0.0, (float) ($attempt['late_penalty'] ?? 0.0))
            + max(0.0, (float) ($attempt['manual_penalty'] ?? 0.0));

        $total = GradeAggregator::create($this->config, $this->criteria($attempt))
            ->aggregate(
                $rows,
                (string) $attempt['grade_mode'],
                (string) ($attempt['criteria_mode'] ?? 'percent'),
                $attemptPenalty
            );

        $pending = $needsReview || $total->isPending();
        $now = date('Y-m-d H:i:s');

        $data = [
            'score'     => $total->maxScore > 0.0 ? $total->score : null,
            'max_score' => $total->maxScore > 0.0 ? $total->maxScore : null,
            'percent'   => $total->percent,
            'grade'     => $total->grade,
            'penalty'   => $total->penalty,
            'manual'    => $pending ? 1 : 0,
            'status'    => AssignmentRepository::ATTEMPT_FINISHED,
            'checked_at' => $pending ? null : $now,
            'checked_by' => $pending ? null : $this->auth->user()?->id,
        ];

        /*
         * Время завершения ставится один раз. Пересчёт итога учителем не должен
         * переписывать момент, когда ученик закончил работу, поэтому поле
         * просто не попадает в обновление, если попытка уже закрыта.
         */
        if ((string) ($attempt['finished_at'] ?? '') === '') {
            $data['finished_at'] = $now;
        }

        $this->assignments->updateAttempt($data, ['id' => $attemptId]);
    }

    /**
     * Статус позиции по её баллу.
     */
    private function itemStatus(float $score, float $maxScore, bool $needsReview): string
    {
        if ($needsReview) {
            return 'pending';
        }

        if ($maxScore <= 0.0) {
            return 'skipped';
        }

        if ($score >= $maxScore) {
            return 'correct';
        }

        return $score > 0.0 ? 'partial' : 'wrong';
    }

    // ---------------------------------------------------------------- контекст для плагина

    /**
     * Данные заданий, собранные заранее для всей страницы.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $prefetchedTaskData = [];

    /**
     * Собирает данные всех позиций до того, как начнётся обход.
     *
     * Ключ — «плагин:задание»: сами номера заданий у разных плагинов могут
     * совпадать, и без префикса одно задание одного вида выдало бы данные
     * другого.
     *
     * @param list<array<string,mixed>> $rows позиции работы
     */
    private function prefetchTaskData(array $rows): void
    {
        $byPlugin = [];

        foreach ($rows as $row) {
            $ref = TaskRef::fromItemRow($row);
            $byPlugin[$ref->pluginId][] = $ref;
        }

        foreach ($byPlugin as $pluginId => $refs) {
            $plugin = $this->plugins->get($pluginId);

            if ($plugin === null) {
                continue;
            }

            try {
                $loaded = $this->plugins->taskDataBatch((string) $pluginId, $refs);
            } catch (\Throwable $e) {
                $this->plugins->recordRuntimeFailure((string) $pluginId, $e);
                continue;
            }

            foreach ($refs as $index => $ref) {
                $this->prefetchedTaskData[$pluginId . ':' . (int) $ref->taskId] = $loaded[$index] ?? [];
            }
        }
    }

    /**
     * Контекст позиции для плагина.
     *
     * Данные задания тянутся у плагина же: ядро не знает, где они лежат, и
     * поэтому не читает их само. Сначала смотрим в предзагрузку, и только
     * затем идём к плагину по одной позиции.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     */
    private function context(array $attempt, array $item, bool $includeSubmittedInput = false): AttemptContext
    {
        $ref = TaskRef::fromItemRow($item);

        $key = $ref->pluginId . ':' . (int) $ref->taskId;
        $taskData = array_key_exists($key, $this->prefetchedTaskData)
            ? $this->prefetchedTaskData[$key]
            : $this->plugins->taskData($ref);

        return new AttemptContext(
            (int) $attempt['id'],
            $ref,
            // Токен попытки кладётся в позицию: плагин рисует форму, и без
            // токена её нельзя отправить. Само значение знает только ядро.
            $item + ['assignment_token' => (string) ($attempt['token'] ?? '')],
            $taskData,
            /*
             * Номер задания — тот, под которым его видит ученик.
             *
             * При перемешивании он не совпадает с номером позиции в редакторе, и
             * ученик должен видеть сплошную нумерацию своего порядка: «Задание 3
             * из 5» в его работе и «Задание 1 из 5» у учителя — разные вещи, и
             * путать их нельзя.
             */
            $this->assignments->studentNumber(
                (int) $attempt['assignment_id'],
                (int) $attempt['id'],
                (int) $item['position']
            ),
            $this->itemCount((int) $item['assignment_id']),
            $this->auth->user(),
            $this->view,
            $this->csrf,
            $this->auth,
            $includeSubmittedInput ? $this->failedAnswer($attempt, $item) : [],
            $this->remainingSeconds($attempt),
            (int) ($attempt['no_copy'] ?? 0) === 1
        );
    }

    /**
     * Сколько секунд осталось на попытку.
     *
     * null — ограничения нет. Число считается здесь, потому что знание о
     * лимите и о начале попытки есть у ядра, а рисует форму плагин: отдать
     * плагину готовый остаток проще, чем отдать ещё и правило, как его считать.
     *
     * Остаток не может быть отрицательным: истёкшее время — это ноль, а не
     * минус. Иначе таймер показывал бы «-00:03», а сдача работы уходила бы в
     * ту же ветку, что и при нуле, но выглядела бы поломанной.
     *
     * @param array<string,mixed> $attempt
     */
    private function remainingSeconds(array $attempt): ?int
    {
        $limit = (int) ($attempt['time_limit'] ?? 0);

        if ($limit <= 0) {
            return null;
        }

        $started = strtotime((string) ($attempt['started_at'] ?? '')) ?: time();

        return max(0, $started + $limit * 60 - time());
    }

    /**
     * Данные ответа, сохранение которого завершилось ошибкой.
     *
     * Значения остаются в сессии до успешного сохранения того же задания; при
     * повторном открытии формы ученик не набирает ответ заново.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function failedAnswer(array $attempt, array $item): array
    {
        $saved = $this->session->get('attempt_answer_old', []);
        $key = (int) $attempt['id'] . ':' . (int) $item['id'];

        if (!is_array($saved) || !is_array($saved[$key] ?? null)
            || !is_array($saved[$key]['input'] ?? null)) {
            return [];
        }

        return $saved[$key]['input'];
    }

    private function rememberFailedAnswer(Request $request, int $attemptId, int $itemId): void
    {
        $input = [];

        foreach ($request->post() as $name => $value) {
            $name = (string) $name;

            if ($name === '_token' || $name === 't' || $name === 'action') {
                continue;
            }

            $safe = $this->safeAnswerValue($value);

            if ($safe !== null) {
                $input[$name] = $safe;
            }
        }

        $encoded = json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE);

        $key = $attemptId . ':' . $itemId;
        $saved = $this->session->get('attempt_answer_old', []);
        $saved = is_array($saved) ? $saved : [];

        if ($encoded === false || strlen($encoded) > 65536) {
            // Большая форма не занимает неограниченное место в хранилище сессий.
            $this->clearFailedAnswer($attemptId, $itemId);

            return;
        }

        $saved[$key] = ['input' => $input];
        $encodedSaved = json_encode($saved, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encodedSaved === false || strlen($encodedSaved) > 262144) {
            unset($saved[$key]);
        }

        $this->session->set('attempt_answer_old', $saved);
    }

    private function safeAnswerValue(mixed $value, int $depth = 0): mixed
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (!is_array($value) || $depth >= 5 || count($value) > 500) {
            return null;
        }

        $out = [];

        foreach ($value as $key => $child) {
            $safe = $this->safeAnswerValue($child, $depth + 1);

            if ($safe !== null) {
                $out[$key] = $safe;
            }
        }

        return $out;
    }

    private function clearFailedAnswer(int $attemptId, int $itemId): void
    {
        $saved = $this->session->get('attempt_answer_old', []);
        $key = $attemptId . ':' . $itemId;

        if (is_array($saved) && array_key_exists($key, $saved)) {
            unset($saved[$key]);

            if ($saved === []) {
                $this->session->remove('attempt_answer_old');
            } else {
                $this->session->set('attempt_answer_old', $saved);
            }
        }
    }

    // ---------------------------------------------------------------- список позиций

    /**
     * Позиции работы с прогрессом конкретного ученика.
     *
     * Начало условия берётся у плагина: ученик видит то же, что и автор в
     * списке работы, — расходиться тут нечему.
     *
     * @param array<string,mixed> $assignment
     * @param array<string,mixed>|null $attempt
     * @return list<array<string,mixed>>
     */
    private function positionRows(array $assignment, ?array $attempt): array
    {
        $results = $attempt === null
            ? []
            : $this->assignments->resultsForAttempt((int) $attempt['id']);

        $byItem = [];

        foreach ($results as $row) {
            $byItem[(int) $row['id']] = $row;
        }

        $rows = [];
        $available = true;

        /*
         * Позиция работы — это строка, а задание в ней то, что выпало этой
         * попытке. Список берётся из строк и вариант выбирается один раз на
         * попытку, поэтому все страницы попытки показывают одно и то же и
         * прогресс не прыгает между перезагрузками.
         *
         * Попытки ещё нет — работа отрыта, но не начата: тогда вариант
         * считается от нулевой попытки, и список детерминирован, но
         * изменять его нельзя — он пересчитается при первой попытке.
         */
        foreach ($this->attemptItems($assignment, $attempt) as $item) {
            $result = $byItem[(int) $item['id']] ?? null;
            $plugin = $this->plugins->get((string) $item['plugin_id']);

            $rows[] = [
                'id'          => (int) $item['id'],
                'position'    => (int) $item['position'],
                'title'       => $this->safeTitle($plugin, TaskRef::fromItemRow($item)),
                'kind'        => (string) $item['task_kind'],
                'weight'      => (float) $item['weight'],
                'available'   => $available && $plugin !== null,
                'done'        => $result !== null && (string) ($result['result_status'] ?? 'pending') !== 'pending',
                'score'       => $result['score'] ?? null,
                'max_score'   => $result['max_score'] ?? null,
                'percent'     => $result['percent'] ?? null,
                'grade'       => $result['grade'] ?? null,
                'status'      => $result['result_status'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Задания, которые видит эта попытка: по одному на строку.
     *
     * @param array<string,mixed>      $assignment
     * @param array<string,mixed>|null $attempt
     *
     * @return list<array<string,mixed>>
     */
    private function attemptItems(array $assignment, ?array $attempt): array
    {
        $assignmentId = (int) $assignment['id'];
        $attemptId = $attempt === null ? 0 : (int) $attempt['id'];

        return $this->assignments->itemsForAttempt($assignmentId, $attemptId);
    }

    /**
     * Список работ с прогрессом ученика.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withProgress(array $rows, ?int $userId): array
    {
        if ($userId === null) {
            return array_map(static fn (array $row): array => $row + ['my_status' => null], $rows);
        }

        $attempts = $this->db->all(
            'SELECT assignment_id, status, score, max_score, percent, grade, attempt_no
               FROM assignment_attempts
              WHERE student_id = :user
           ORDER BY id',
            ['user' => $userId]
        );

        $latest = [];

        foreach ($attempts as $attempt) {
            $latest[(int) $attempt['assignment_id']] = $attempt;
        }

        $result = [];

        foreach ($rows as $row) {
            $mine = $latest[(int) $row['id']] ?? null;

            $result[] = $row + [
                'my_status' => $mine['status'] ?? null,
                'my_percent' => $mine['percent'] ?? null,
                'my_grade'  => $mine['grade'] ?? null,
                'my_attempt' => $mine['attempt_no'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Разбор по позициям: снимок из базы, отрисованный плагином.
     *
     * Снимок не пересчитывается: правка задания после сдачи не должна менять то,
     * что ученик видел при проверке.
     *
     * @param array<string,mixed>              $attempt
     * @param list<array<string,mixed>>        $rows
     * @return list<array<string,mixed>>
     */
    private function renderReviews(array $attempt, array $rows, bool $showResults): array
    {
        $out = [];

        // Все данные страницы собираются здесь, одним проходом по плагину.
        $this->prefetchTaskData($rows);

        foreach ($rows as $row) {
            $plugin = $this->plugins->get((string) $row['plugin_id']);

            $review = null;
            $error = null;

            if ($showResults && $plugin !== null && ($row['snapshot'] ?? null) !== null) {
                try {
                    $review = $plugin->renderReview(
                        $this->context($attempt, $row),
                        $this->itemGrade($row)
                    );
                } catch (\Throwable $e) {
            // плагин остаётся включённым. Подробности — в журнале плагинов.
            // Ответ ученика или его разбор, а не поломка плагина:
                    $this->plugins->recordItemFailure((string) $row['plugin_id'], $e);
                    $this->reportPluginError($e, $attempt, $row);
                    $error = 'Не удалось показать разбор. Учитель сможет проверить это задание.';
                }
            }

            $out[] = [
                'position'    => (int) $row['position'],
                'title'       => $this->safeTitle($plugin, TaskRef::fromItemRow($row)),
                'kind'        => (string) $row['task_kind'],
                'weight'      => (float) $row['weight'],
                'score'       => $showResults ? $row['score'] : null,
                'max_score'   => $showResults ? $row['max_score'] : null,
                'percent'     => $showResults ? $row['percent'] : null,
                'grade'       => $showResults ? $row['grade'] : null,
                'status'      => (string) ($row['result_status'] ?? 'pending'),
                'needs_review' => (int) ($row['needs_review'] ?? 0) === 1,
                'comment'     => (string) ($row['comment'] ?? ''),
                'review'      => $review,
                'error'       => $error,
            ];
        }

        return $out;
    }

    /**
     * Собранный обратно ItemGrade из сохранённой строки результата.
     *
     * Ядро не помнит объект плагина между запросами, поэтому разбор строится
     * из того, что он записал в снимок.
     *
     * @param array<string,mixed> $row
     */
    private function itemGrade(array $row): \MyEdu\Plugin\ItemGrade
    {
        $snapshot = json_decode((string) ($row['snapshot'] ?? 'null'), true);

        return new \MyEdu\Plugin\ItemGrade(
            (float) ($row['score'] ?? 0.0),
            (float) ($row['max_score'] ?? 0.0),
            $row['grade'] === null ? null : (int) $row['grade'],
            (int) ($row['needs_review'] ?? 0) === 1,
            is_array($snapshot) ? $snapshot : []
        );
    }

    // ---------------------------------------------------------------- переходы

    /**
     * Соседняя позиция работы.
     *
     * За пределы последовательности не выходим: на первом задании «назад» и на
     * последнем «дальше» ведут на список позиций, а не в пустоту.
     *
     * Переход идёт по строкам, а не по адресу позиции: у строки может быть
     * несколько заданий, и «дальше» должно приводить к следующей строке, а не
     * к соседней карточке этой же — иначе ученик получил бы чужой вариант или
     * ушёл бы за пределы работы.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function nextItem(array $attempt, array $item, int $step): ?array
    {
        /*
         * Номер — тот, под которым задание видит ученик.
         *
         * При перемешивании он не совпадает с номером в редакторе, и «дальше»
         * обязано идти по ученическому порядку: иначе после третьего задания
         * ученик возвращался бы к тому, что уже проходил.
         */
        $number = (int) $item['position'] + $step;

        return $this->assignments->itemByStudentNumber(
            (int) $attempt['assignment_id'],
            (int) $attempt['id'],
            max(1, $number)
        );
    }

    /** @param array<string,mixed> $attempt */
    private function attemptRedirect(array $attempt, ?array $item = null): Response
    {
        $token = (string) $attempt['token'];

        if ($item === null) {
            return $this->redirect(sprintf('work/attempt/%d?t=%s', (int) $attempt['id'], $token));
        }

        return $this->redirect(sprintf(
            'work/%d/item/%d?t=%s',
            (int) $attempt['id'],
            (int) $item['id'],
            $token
        ));
    }

    /** @param array<string,mixed> $attempt */
    private function resultRedirect(array $attempt): Response
    {
        return $this->redirect(sprintf(
            'work/%d/result?t=%s',
            (int) $attempt['id'],
            (string) $attempt['token']
        ));
    }

    /**
     * Список позиций попытки — тот же экран работы, но с уже начатой попыткой.
     *
     * @param array<string,mixed> $attempt
     */
    public function attempt(Request $request, array $params): Response
    {
        $attempt = $this->requireOwnAttempt(
            $this->intParam($params, 'attemptId'),
            $request->queryParam('t')
        );

        $assignment = $this->requireAssignment($attempt);
        $rows = $this->positionRows($assignment, $attempt);

        return $this->render('work/show', [
            'title'      => (string) $assignment['title'],
            'assignment' => $assignment,
            'items'      => $rows,
            'attempt'    => $attempt,
            'totalItems' => count($rows),
            'doneCount'  => count(array_filter($rows, static fn (array $r): bool => $r['done'])),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

    // ---------------------------------------------------------------- доступ

    /** @return array<string,mixed> */
    private function requirePublished(int $id): array
    {
        $assignment = $this->assignments->find($id);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        if ((string) $assignment['status'] !== AssignmentRepository::STATUS_PUBLISHED) {
            throw new HttpException(404, 'Работа ещё не опубликована');
        }

        return $assignment;
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    private function requireAssignment(array $attempt): array
    {
        $assignment = $this->assignments->find((int) $attempt['assignment_id']);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        return $assignment;
    }

    /**
     * Попытка по токену.
     *
     * Токен обязателен: без него результат можно было бы открыть перебором
     * номеров попыток. Сотрудникам дополнительно разрешается смотреть чужую
     * попытку — им работа проверяют.
     *
     * @return array<string,mixed>
     */
    private function requireOwnAttempt(int $attemptId, ?string $token): array
    {
        if ($token === null || $token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new HttpException(404, 'Попытка не найдена');
        }

        $attempt = $this->assignments->findAttempt($attemptId);

        if ($attempt === null || !hash_equals((string) $attempt['token'], $token)) {
            throw new HttpException(404, 'Попытка не найдена');
        }

        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(403, 'Войдите в систему');
        }

        $isOwner = (int) $attempt['student_id'] === $user->id;

        if (!$isOwner && !$this->auth->can(Role::TESTS_MANAGE)) {
            throw new HttpException(403, 'Это попытка другого ученика');
        }

        return $attempt;
    }

    /**
     * Позиция должна принадлежать той же работе, что и попытка.
     *
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    private function requireItem(array $attempt, int $itemId): array
    {
        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== (int) $attempt['assignment_id']) {
            throw new HttpException(404, 'Задание не найдено');
        }

        return $item;
    }

    /** @param array<string,mixed> $attempt */
    private function isFinished(array $attempt): bool
    {
        return (string) $attempt['status'] !== AssignmentRepository::ATTEMPT_IN_PROGRESS;
    }

    /**
     * Время вышло.
     *
     * @param array<string,mixed> $attempt
     */
    private function expired(array $attempt): bool
    {
        $limit = (int) ($attempt['time_limit'] ?? 0);

        if ($limit <= 0) {
            return false;
        }

        $started = strtotime((string) $attempt['started_at']) ?: time();

        return time() >= $started + $limit * 60;
    }

    /**
     * Экран позиции, когда её плагин недоступен.
     *
     * Ученик видит, что задание пропущено и почему, и может идти дальше:
     * одно сломанное задание не должно останавливать всю работу.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     */
    private function itemUnavailable(array $attempt, array $item): Response
    {
        $this->session->set('flash_error', 'Это задание сейчас недоступно, пропускаем его.');

        return $this->attemptRedirect($attempt, $this->nextItem($attempt, $item, +1))
            ->withHeader('X-MyEdu-Skipped', (string) $item['id']);
    }

    /**
     * Ошибка плагина попадает в журнал приложения.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     */
    private function reportPluginError(\Throwable $e, array $attempt, array $item): void
    {
        $line = sprintf(
            "[%s] Плагин %s, задание %d, попытка %d: %s в %s:%d\n",
            date('Y-m-d H:i:s'),
            (string) $item['plugin_id'],
            (int) $item['task_id'],
            (int) $attempt['id'],
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        $dir = \MyEdu\Core\App::instance()->rootDir() . '/var/log';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/plugins-' . date('Y-m-d') . '.log', $line, FILE_APPEND);
        }

        error_log(trim($line));
    }

    /**
     * Критерии оценки работы.
     *
     * @param array<string,mixed> $assignment
     * @return list<array<string,mixed>>|null
     */
    private function criteria(array $assignment): ?array
    {
        $raw = (string) ($assignment['criteria'] ?? '');

        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Начало условия задания для показа ученику.
     *
     * Ученик видит то же, что и автор в списке работы, — текущее начало
     * текста. Раньше здесь показывался снимок заголовка на момент выдачи, и
     * после переноса заголовка ученик видел бы старое имя, которого в системе
     * уже нет.
     *
     * Пустая строка — честный ответ: условия может не быть, и выдуманное имя
     * в списке заданий выглядело бы как настоящее.
     */
    private function safeTitle(?AssessmentPlugin $plugin, TaskRef $ref): string
    {
        if ($plugin === null) {
            return '';
        }

        try {
            return $this->plugins->taskPreview($ref) ?? '';
        } catch (\Throwable $e) {
            $this->plugins->recordRuntimeFailure($ref->pluginId, $e);

            return '';
        }
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
