<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Plugin\PluginManager;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\JournalRepository;

/**
 * Результаты: что сдали ученики и с каким результатом.
 *
 * Раздел отвечает на два вопроса — «кому что выдавали» и «как сдали».
 * Сверху — список выданных работ, собранный по классам: учитель открывает
 * раздел и видит, кому что выдавали. Внутри работы — вкладки с классами,
 * которым её выдавали, и на каждой вкладке таблица результатов: ученик, его
 * задания и итог.
 *
 * Раньше здесь была страница журнала по классу (список работ по ученикам
 * одного класса): она отвечала на вопрос «что делал класс», а раздел отвечает
 * на два других. Держать в системе три разных ответа на один вопрос «кто
 * сдал» не нужно.
 *
 * Здесь же учитель смотрит глазами ученика, что было выдано: оценка за
 * чужую работу без этого экрана была бы догадкой.
 */
final class JournalController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Auth $auth,
        private readonly AssignmentRepository $assignments,
        private readonly JournalRepository $journals,
        private readonly PluginManager $plugins
    ) {
    }

    /**
     * Журнал: выданные работы, сгруппированные по классам.
     *
     * Круг видимых работ один и тот же везде в разделе: администратору — все
     * работы школы, учителю и завучу — работы, которые выдал он сам. Решение
     * владельца: отдельной логики для завуча нет, статистика своя у каждого,
     * кто ведёт работы.
     */
    public function index(Request $request): Response
    {
        $groups = [];

        foreach ($this->journals->journalIssuedWorks($this->auth->journalAuthorId()) as $row) {
            $classId = (int) $row['class_id'];

            $groups[$classId] ??= [
                'id'    => $classId,
                'name'  => (string) $row['class_name'],
                'title' => (string) $row['class_title'],
                'works' => [],
            ];

            $groups[$classId]['works'][] = $row;
        }

        return $this->render('panel/journal_index', [
            'title'  => 'Результаты',
            'groups' => array_values($groups),
            'notice' => $this->session->pull('flash_notice'),
            'error'  => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Журнал одной работы: вкладки классов и результаты учеников.
     *
     * Вкладка — это класс, которому работу выдали. Классов у работы может
     * быть несколько, и у каждого своя выдача со своим сроком и своим составом
     * учеников, поэтому вкладки и показывают именно классы, а не «все ученики
     * подряд».
     */
    public function work(Request $request, array $params): Response
    {
        $assignmentId = $this->intParam($params, 'id');
        $assignment = $this->assignments->find($assignmentId);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        /*
         * Раздел открыт правом reports.view, и у учителя оно есть — но только
         * на работы, которые выдал он сам. Проверка та же, что на странице
         * проверки попыток: чужая работа остаётся закрытой, как и раньше, когда
         * журнал был доступен только завучу и администратору. Завучу теперь
         * тоже показываются только его работы — см. `journalAuthorId()`.
         */
        $authorId = $this->auth->journalAuthorId();

        if ($authorId > 0 && (int) $assignment['author_id'] !== $authorId) {
            throw new HttpException(403, 'Это работа другого автора');
        }

        $classes = $this->journals->journalWorkClasses($assignmentId);

        if ($classes === []) {
            throw new HttpException(404, 'Работа никому не выдавалась');
        }

        $classId = (int) $request->queryParam('class', '0');
        $current = null;

        foreach ($classes as $class) {
            if ((int) $class['id'] === $classId) {
                $current = $class;
            }
        }

        // Класс без ссылки берётся первый: страница должна открываться чем-то
        // осмысленным, а не пустой таблицей.
        $current ??= $classes[0];

        $students = $this->journals->journalWorkStudents($assignmentId, (int) $current['id']);
        $deadlines = $this->journals->journalWorkDeadlines($assignmentId);
        $items = $this->journals->journalWorkItems($assignmentId);

        $attemptIds = [];

        foreach ($students as $student) {
            if ((int) ($student['attempt_id'] ?? 0) > 0) {
                $attemptIds[] = (int) $student['attempt_id'];
            }
        }

        $results = $this->journals->journalItemResults($attemptIds);

        $rows = $this->rows($students, $deadlines, $items, $results);

        return $this->render('panel/journal_work', [
            'title'     => 'Результаты: ' . (string) $assignment['title'],
            'assignment' => $assignment,
            'classes'   => $classes,
            'current'   => $current,
            'items'     => $items,
            'rows'      => $rows,
            'notice'    => $this->session->pull('flash_notice'),
            'error'     => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Строки таблицы: ученик, его задания, итог.
     *
     * Задания идут в порядке редактора, а не в том, в каком их видел ученик:
     * при перемешивании порядок выдачи другой, а учителю нужен порядок самой
     * работы — тот, в котором задания стоят в редакторе.
     *
     * @param list<array<string,mixed>> $students
     * @param array<int,string>         $deadlines срок выдачи по ученику
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    private function rows(array $students, array $deadlines, array $items, array $results): array
    {
        $byAttempt = [];

        foreach ($results as $result) {
            $byAttempt[(int) $result['attempt_id']][(int) $result['item_id']] = $result;
        }

        /*
         * Показывается последняя завершённая попытка.
         *
         * Пересдачи не должны превращать журнал в список дублей, а учителю
         * важен результат, а не начатая попытка: если ученик уже сдал, а потом
         * начал новую, в таблице должен стоять его результат, а не пустота.
         * Если завершённых попыток нет — показывается последняя, чтобы по
         * ученику было видно, что работа начата.
         */
        $last = [];
        $lastFinished = [];

        foreach ($students as $student) {
            $studentId = (int) $student['student_id'];

            $last[$studentId] = $student;

            if ((string) ($student['status'] ?? '') === 'finished') {
                $lastFinished[$studentId] = $student;
            }
        }

        $rows = [];

        foreach ($last as $studentId => $student) {
            $student = $lastFinished[$studentId] ?? $student;
            $attemptId = (int) ($student['attempt_id'] ?? 0);
            $cells = [];

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $result = $attemptId > 0 ? ($byAttempt[$attemptId][$itemId] ?? null) : null;

                $cells[] = $this->cell($item, $result);
            }

            $student['deadline'] = $deadlines[$studentId] ?? '';

            $rows[] = [
                'student'  => $student,
                'cells'    => $cells,
                'late'     => $this->isLate($student),
                'penalty'  => (float) ($student['penalty'] ?? 0.0),
                'score'    => $student['score'] === null ? null : (float) $student['score'],
                'max'      => $student['max_score'] === null ? null : (float) $student['max_score'],
                'net'      => $student['score'] === null
                    ? null
                    : max(0.0, (float) $student['score'] - (float) ($student['penalty'] ?? 0.0)),
                'percent'  => $student['percent'] === null ? null : (float) $student['percent'],
                'grade'    => $student['grade'] === null ? null : (int) $student['grade'],
                'attempt'  => $attemptId > 0,
                'finished' => (string) ($student['status'] ?? '') === 'finished',
                'comment'  => (string) ($student['penalty_comment'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Ячейка с одним заданием: балл, цвет и подсказка с ответом.
     *
     * Цвет говорит о результате задания: зелёный — полный балл, оранжевый —
     * балл меньше максимального, красный — ноль или минус. Задание, до которого
     * ученик не дошёл, остаётся серым: это не «неверно», а «не выполнялось».
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $result
     * @return array<string,mixed>
     */
    private function cell(array $item, ?array $result): array
    {
        $position = (int) $item['position'];

        if ($result === null) {
            return [
                'position' => $position,
                'tone'     => 'none',
                'score'    => null,
                'max'      => null,
                'answer'   => null,
                'comment'  => '',
            ];
        }

        $max = (float) ($result['max_score'] ?? 0.0);
        $penalty = (float) ($result['penalty'] ?? 0.0);
        $score = (float) ($result['score'] ?? 0.0);
        $net = max(0.0, $score - $penalty);

        if ($max <= 0.0) {
            $tone = 'none';
        } elseif ($net >= $max) {
            $tone = 'good';
        } elseif ($net > 0.0) {
            $tone = 'mid';
        } else {
            $tone = 'bad';
        }

        return [
            'position' => $position,
            'tone'     => $tone,
            'score'    => $score,
            'max'      => $max,
            'penalty'  => $penalty,
            'answer'   => $this->answerSummary($result),
            'comment'  => (string) ($result['comment'] ?? ''),
        ];
    }

    /**
     * Текст ответа ученика — из снимка позиции.
     *
     * Снимок разбирает плагин: ядро не знает, как устроен ответ в чужой
     * системе проверки, и спрашивать об этом у самого плагина — единственный
     * способ не зашивать в ядро чужие форматы.
     *
     * @param array<string,mixed> $result
     */
    private function answerSummary(array $result): ?string
    {
        $snapshot = (string) ($result['snapshot'] ?? '');

        if (trim($snapshot) === '') {
            return null;
        }

        $plugin = $this->plugins->get((string) ($result['plugin_id'] ?? ''));

        if ($plugin === null) {
            return null;
        }

        try {
            return $plugin->workAnswerSummary($snapshot);
        } catch (\Throwable $e) {
            // Битый плагин не должен ронять журнал: ячейка без подсказки
            // полезнее, чем страница ошибки.
            $this->plugins->recordRuntimeFailure((string) ($result['plugin_id'] ?? ''), $e);

            return null;
        }
    }

    /**
     * Сдана ли работа вовремя.
     *
     * Опоздание видно по сроку выдачи: работа, сданная после него, помечается
     * в таблице отдельным значком.
     *
     * @param array<string,mixed> $student
     */
    private function isLate(array $student): bool
    {
        $deadline = (string) ($student['deadline'] ?? '');
        $finished = (string) ($student['finished_at'] ?? '');

        if ($deadline === '' || $finished === '') {
            return false;
        }

        return $finished > $deadline;
    }
}
