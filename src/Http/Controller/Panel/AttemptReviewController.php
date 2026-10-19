<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Grading\GradeAggregator;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Http\Controller\WorkController;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;

/**
 * Проверка работы учителем.
 *
 * Нужна там, где у плагина нет автопроверки: текстовый ответ, развёрнутое
 * рассуждение. Такое задание возвращает needsReview = true, работа помечается
 * как ждущая проверки, и учитель ставит балл и комментарий руками.
 *
 * Пересчёт итога делегирован WorkController: правило сведения одно на проект,
 * и учительская правка не должна считаться по другому алгоритму, чем автопроверка.
 */
final class AttemptReviewController
{
    use ControllerHelpers;

    /**
     * Отметки, которые вправе прислать форма проверки.
     *
     * Список, а не любая строка: отметка попадает в результат и показывается
     * ученику как его достижение, а приходит из браузера учителя.
     */
    private const REVIEW_STATUSES = ['correct', 'partial', 'wrong', 'skipped'];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly AssignmentRepository $assignments,
        private readonly PluginManager $plugins,
        private readonly WorkController $works
    ) {
    }

    /**
     * Число позиций работы, уже посчитанное в этом запросе.
     *
     * Число заданий у работы одно, а контекст собирается заново на каждую
     * позицию: на проверке работы с двадцатью заданиями это двадцать
     * одинаковых запросов вместо одного. Контроллер живёт один запрос,
     * протухнуть кэшу негде.
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

    public function show(Request $request, array $params): Response
    {
        $assignmentId = $this->intParam($params, 'id');
        $attemptId = $this->intParam($params, 'attemptId');

        $assignment = $this->requireOwned($assignmentId);
        $attempt = $this->requireAttempt($assignmentId, $attemptId);

        $rows = $this->assignments->resultsForAttempt($attemptId);

        $total = GradeAggregator::create($this->config, $this->criteria($assignment))
            ->aggregate($rows, (string) $assignment['grade_mode'], (string) ($assignment['criteria_mode'] ?? 'percent'));

        return $this->render('panel/attempt_review', [
            'title'      => 'Проверка: ' . (string) $assignment['title'],
            'assignment' => $assignment,
            'attempt'    => $attempt,
            'items'      => $this->reviewRows($attempt, $rows),
            'total'      => $total,
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Сохранение проверки и пересчёт итога.
     *
     * Поля приходят массивами по позициям: одна форма на всю работу, а не по
     * форме на задание — иначе учитель на каждый балл отправлял бы страницу.
     */
    public function save(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $attemptId = $this->intParam($params, 'attemptId');

        $this->requireOwned($assignmentId);
        $this->requireAttempt($assignmentId, $attemptId);

        $teacherId = $this->auth->user()?->id ?? 0;
        $now = date('Y-m-d H:i:s');

        $scores = $request->postArray('score');
        $maxScores = $request->postArray('max_score');
        $comments = $request->postArray('comment');
        $marks = $request->postArray('mark');

        /*
         * Проверяются задания этой попытки — по одному на строку. Альтернативы
         * ученику не выпадали, результатов у них нет, и править их нечего:
         * в форме их попросту не будет.
         */
        $items = $this->assignments->itemsForAttempt($assignmentId, $attemptId);
        $checked = 0;

        foreach ($items as $item) {
            $itemId = (int) $item['id'];

            // Учитель правит только то, что ждёт проверки.
            $existing = $this->assignments->findResult($attemptId, $itemId);

            if ($existing !== null && (int) ($existing['needs_review'] ?? 0) !== 1) {
                continue;
            }

            $score = $this->number($scores[$itemId] ?? null);

            /*
             * Максимум приходит из формы — и это по замыслу, а не по недосмотру.
             *
             * У позиции может не быть своего максимума, и тогда его называет
             * учитель здесь, в момент проверки: поле «Максимум» в форме для
             * того и есть. Отнимать его у учителя означало бы запретить задавать
             * стоимость текстового задания, а стоимость задаёт тот, кто проверяет.
             *
             * Отсекается другое: балл всё равно срезается до максимума, а отметка
             * сверяется со списком — в результат попадает строка из браузера, и
             * она показывается ученику.
             */
            $score = $this->number($scores[$itemId] ?? null);
            $maxScore = $this->number($maxScores[$itemId] ?? null) ?? (float) ($existing['max_score'] ?? 0.0);
            $comment = isset($comments[$itemId]) && is_scalar($comments[$itemId])
                ? trim((string) $comments[$itemId])
                : '';

            if ($score === null) {
                continue;
            }

            $score = max(0.0, min($score, $maxScore > 0.0 ? $maxScore : $score));
            $percent = $maxScore > 0.0 ? round($score / $maxScore * 100, 2) : null;

            /*
             * Отметка приходит из формы, но из запроса не принимается что угодно:
             * она попадает в результат и показывается ученику. Значение сверяется
             * со списком, а неизвестное считается вычисленным по баллу.
             */
            $mark = isset($marks[$itemId]) && is_scalar($marks[$itemId])
                ? (string) $marks[$itemId]
                : '';
            $mark = in_array($mark, self::REVIEW_STATUSES, true) ? $mark : '';

            $this->assignments->saveResult($attemptId, $itemId, [
                'score'        => GradeAggregator::roundScore($score),
                'max_score'    => $maxScore > 0.0 ? $maxScore : null,
                'percent'      => $percent,
                'status'       => $mark !== '' ? $mark : $this->statusOf($score, $maxScore),
                'needs_review' => 0,
                'comment'      => $comment,
                'checked_at'   => $now,
                'checked_by'   => $teacherId,
                // Снимок сохраняем: по нему плагин покажет ответ ученика.
                'snapshot'     => $existing['snapshot'] ?? null,
            ]);

            $checked++;
        }

        if ($checked === 0) {
            $this->session->set('flash_error', 'Нечего сохранять: нет позиций, ждущих проверки.');
        } else {
            // Итог пересчитывается тем же правилом, что и при автопроверке.
            $this->works->updateTotals($attemptId, false);

            $this->session->set('flash_notice', 'Проверка сохранена, итог пересчитан');
        }

        return $this->redirect(sprintf(
            'panel/assignments/%d/attempts/%d',
            $assignmentId,
            $attemptId
        ));
    }

    // ---------------------------------------------------------------- служебное

    /**
     * Позиции с ответами для показа учителю.
     *
     * Ответ ученика показывается всегда: проверять вслепую бессмысленно.
     *
     * @param array<string,mixed>       $attempt
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function reviewRows(array $attempt, array $rows): array
    {
        $out = [];

        // Все данные страницы собираются здесь, одним проходом по плагину.
        $this->prefetchTaskData($rows);

        foreach ($rows as $row) {
            $ref = TaskRef::fromItemRow($row);
            $plugin = $this->plugins->get($ref->pluginId);
            $answer = null;
            $error = null;

            if ($plugin !== null) {
                try {
                    $answer = $plugin->renderSubmitted($this->context($attempt, $row));
                } catch (\Throwable $e) {
                    $this->plugins->recordRuntimeFailure($ref->pluginId, $e);
                    $error = 'Не удалось загрузить ответ плагина. Проверьте журнал ошибок.';
                }
            }

            $out[] = [
                'id'           => (int) $row['id'],
                'position'     => (int) $row['position'],
                'title'        => $this->titleOf($plugin, $ref),
                'weight'       => (float) $row['weight'],
                'score'        => $row['score'] ?? null,
                'max_score'    => $row['max_score'] ?? null,
                'status'       => (string) ($row['result_status'] ?? 'pending'),
                'needs_review' => (int) ($row['needs_review'] ?? 0) === 1,
                'comment'      => (string) ($row['comment'] ?? ''),
                'answer'       => $answer,
                'error'        => $error,
                'saved'        => (string) ($row['checked_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Данные заданий, собранные заранее для всей страницы.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $prefetchedTaskData = [];

    /**
     * Ключ — «плагин:задание»: сами номера заданий у разных плагинов могут
     * совпадать, и без префикса одно задание одного вида выдало бы данные
     * другого.
     *
     * @param list<array<string,mixed>> $rows позиции попытки
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
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $item
     */
    private function context(array $attempt, array $item): AttemptContext
    {
        $ref = TaskRef::fromItemRow($item);

        $key = $ref->pluginId . ':' . (int) $ref->taskId;
        $taskData = array_key_exists($key, $this->prefetchedTaskData)
            ? $this->prefetchedTaskData[$key]
            : $this->plugins->taskData($ref);

        return new AttemptContext(
            (int) $attempt['id'],
            $ref,
            $item,
            $taskData,
            (int) $item['position'],
            $this->itemCount((int) $item['assignment_id']),
            $this->auth->user(),
            $this->view,
            $this->csrf,
            $this->auth
        );
    }

    /** @return array<string,mixed> */
    private function requireOwned(int $id): array
    {
        $assignment = $this->assignments->find($id);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        if ((int) $assignment['author_id'] !== ($this->auth->user()?->id ?? 0)) {
            throw new HttpException(403, 'Это работа другого автора');
        }

        return $assignment;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireAttempt(int $assignmentId, int $attemptId): array
    {
        $attempt = $this->assignments->findAttempt($attemptId);

        if ($attempt === null || (int) $attempt['assignment_id'] !== $assignmentId) {
            throw new HttpException(404, 'Попытка не найдена');
        }

        return $attempt;
    }

    private function statusOf(float $score, float $maxScore): string
    {
        if ($maxScore <= 0.0) {
            return 'skipped';
        }

        if ($score >= $maxScore) {
            return 'correct';
        }

        return $score > 0.0 ? 'partial' : 'wrong';
    }

    private function number(mixed $value): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * Начало условия задания для показа при разборе.
     *
     * Тот же текст, что и в списке работы, и у ученика: разбор и выдача
     * расходятся только числами. Битый плагин не должен ронять разбор —
     * позиция остаётся узнаваемой по номеру.
     */
    private function titleOf(?AssessmentPlugin $plugin, TaskRef $ref): string
    {
        if ($plugin === null) {
            return '';
        }

        try {
            $preview = $this->plugins->taskPreview($ref);
        } catch (\Throwable $e) {
            $this->plugins->recordRuntimeFailure($ref->pluginId, $e);

            return '';
        }

        return $preview ?? '';
    }

    /**
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
}
