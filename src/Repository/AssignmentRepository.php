<?php

declare(strict_types=1);

namespace MyEdu\Repository;

use MyEdu\Auth\Actor;
use MyEdu\Core\Database;
use MyEdu\Plugin\TaskRef;

/**
 * Работы, позиции, попытки и результаты.
 *
 * Репозиторий ничего не знает про тесты, игры и тренажёры: плагин приходит
 * сюда только как plugin_id + task_id. Поэтому добавление нового способа
 * проверки знаний не требует ни одной правки в этом файле.
 *
 * Слой данных намеренно не хранит «итог» работы. Итог считает GradeAggregator
 * из результатов позиций — иначе при проверке учителем пришлось бы
 * пересчитывать его вручную, а правило сведения живёт в одном месте.
 */
final class AssignmentRepository
    {
    // Статусы работы и попытки продублированы значениями ENUM в схеме.
    // Константы нужны, чтобы не искать строки по проекту.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const ATTEMPT_IN_PROGRESS = 'in_progress';
    public const ATTEMPT_FINISHED = 'finished';

    /** Режим сведения: единая оценка за всю работу, среднее по весам. */
    public const MODE_WEIGHTED = 'weighted';

    /** Режим сведения: отдельная оценка за каждое задание, затем веса. */
    public const MODE_PER_ITEM = 'per_item';

    /**
     * Сколько карточек может быть в одной строке работы.
     *
     * Столько же, сколько ячеек на доске. Правило живёт здесь, а не в форме:
     * добавление приходит и кнопкой палитры, и перетаскиванием, и забытый
     * четвёртый вариант просто исчез бы с доски вместе со своей ценой.
     */
    public const MAX_VARIANTS = 3;

    private Database $db;

    /**
     * Перемешиваются ли задания работы — по одному разу на работу.
     *
     * @var array<int,bool>
     */
    private array $shuffleFlags = [];

    /**
     * Порядок заданий у ученика — по работе и попытке.
     *
     * Список позиций пересобирается с сортировкой и запросом позиций на каждый
     * вызов, а вызывают его часто: список позиций попытки, номер задания в
     * шапке каждого задания, переход «дальше». На результате работы с двадцатью
     * заданиями без кэша это двадцать одинаковых выборок и сортировок.
     *
     * Жизнь кэша совпадает с жизнью репозитория — один запрос. Посреди запроса
     * порядок меняют только правки редактора, и они сбрасывают его целиком
     * (see forgetPositionOrders()).
     *
     * @var array<string,list<int>>
     */
    private array $positionOrders = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ---------------------------------------------------------------- работы

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT a.*, s.name AS subject_name
               FROM assignments a
          LEFT JOIN subjects s ON s.id = a.subject_id
              WHERE a.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Работы конкретного автора.
     *
     * Счётчики позиций и попыток берутся подзапросами, а не отдельными
     * выборками на строку: список работ открывают часто, и N+1 здесь
     * заметен.
     *
     * @return list<array<string,mixed>>
     */
    public function allByAuthor(int $authorId): array
    {
        return $this->db->all(
            "SELECT a.*, s.name AS subject_name,
                    (SELECT COUNT(*) FROM assignment_items i WHERE i.assignment_id = a.id) AS item_count,
                    (SELECT COUNT(*) FROM assignment_attempts t WHERE t.assignment_id = a.id) AS attempt_count,
                    (SELECT COUNT(*) FROM assignment_issue_students ais
                       JOIN assignment_issues i ON i.id = ais.issue_id
                      WHERE i.assignment_id = a.id) AS issued_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = a.id AND t.status = 'finished') AS finished_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = a.id AND t.manual = 1 AND t.status = 'finished') AS review_count
               FROM assignments a
          LEFT JOIN subjects s ON s.id = a.subject_id
              WHERE a.author_id = :author
           ORDER BY a.parallel IS NULL, a.parallel, a.updated_at DESC, a.id DESC",
            ['author' => $authorId]
        );
    }

    /**
     * Разделение списка работ по состоянию.
     *
     * Черновик и опубликованная работа — разные вещи, и смешивать их в одну
     * таблицу было неудобно: в списке вперемешку стояли готовые работы, за
     * которые уже сдали ученики, и наброски, которые автор видел каждый раз и
     * не мог отличить от готовых по одному названию.
     *
     * Разделение сделано на стороне PHP, а не двумя запросами: состояния три,
     * а фильтр по ним — это раскладка уже полученных строк. Второй запрос означал
     * бы сортировку и подсчёт позиций дважды, а строки одинаковые.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array{published:list<array<string,mixed>>,drafts:list<array<string,mixed>>}
     */
    public static function splitByStatus(array $rows): array
    {
        $published = [];
        $drafts = [];

        foreach ($rows as $row) {
            // Закрытая работа опубликованной не бывает: она уже была выпущена,
            // ученики её проходили, и возвращать её в черновики незачем.
            if ((string) $row['status'] === self::STATUS_DRAFT) {
                $drafts[] = $row;
            } else {
                $published[] = $row;
            }
        }

        return ['published' => $published, 'drafts' => $drafts];
    }

    /**
     * Все работы — то, что видят завуч и администратор.
     *
     * Имя автора здесь обязательно: список показывает чужие работы, и без
     * подписи «Работы» два одинаково названных черновика от разных учителей
     * неразличимы. Учитель этого списка не получает — он у себя и автор,
     * и единственный, кто имеет право их править.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithAuthor(): array
    {
        return $this->db->all(
            "SELECT a.*, s.name AS subject_name, u.full_name AS author_name,
                    (SELECT COUNT(*) FROM assignment_items i WHERE i.assignment_id = a.id) AS item_count,
                    (SELECT COUNT(*) FROM assignment_attempts t WHERE t.assignment_id = a.id) AS attempt_count,
                    (SELECT COUNT(*) FROM assignment_issue_students ais
                       JOIN assignment_issues i ON i.id = ais.issue_id
                      WHERE i.assignment_id = a.id) AS issued_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = a.id AND t.status = 'finished') AS finished_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = a.id AND t.manual = 1 AND t.status = 'finished') AS review_count
               FROM assignments a
          LEFT JOIN subjects s ON s.id = a.subject_id
          LEFT JOIN users u    ON u.id = a.author_id
           ORDER BY a.parallel IS NULL, a.parallel, a.updated_at DESC, a.id DESC"
        );
    }

    /**
 * Опубликованные работы — то, что ядро показывает пользователю.
     *
     * Ученик видит только то, что ему лично выдали, и только пока срок не
     * истёк. Сотрудник — всё: он и выдаёт работу, и проверяет её, и не должен
     * терять из виду выданное только потому, что класс ещё не заведён.
     *
     * @return list<array<string,mixed>>
     */
    public function allPublished(?int $studentId = null, bool $isStaff = false): array
    {
        if ($studentId === null || $isStaff) {
            return $this->db->all(
                "SELECT a.*, s.name AS subject_name,
                        (SELECT COUNT(*) FROM assignment_items i WHERE i.assignment_id = a.id) AS item_count,
                        (SELECT COUNT(*) FROM assignment_attempts t
                          WHERE t.assignment_id = a.id AND t.status = 'finished') AS finished_count
                   FROM assignments a
              LEFT JOIN subjects s ON s.id = a.subject_id
                  WHERE a.status = 'published'
               ORDER BY a.published_at DESC, a.id DESC"
            );
        }

        /*
         * Ученику идёт выданное ему. EXISTS, а не JOIN: выдать одну работу
         * можно дважды — разным классам и в разные сроки, — и при JOIN такая
         * работа пришла бы ученику двумя одинаковыми строками.
         */
        return $this->db->all(
            "SELECT a.*, s.name AS subject_name,
                    (SELECT COUNT(*) FROM assignment_items i WHERE i.assignment_id = a.id) AS item_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = a.id AND t.status = 'finished') AS finished_count
               FROM assignments a
          LEFT JOIN subjects s ON s.id = a.subject_id
               WHERE a.status = 'published'
                 AND EXISTS (
                        SELECT 1
                          FROM assignment_issues i
                          JOIN assignment_issue_students ais ON ais.issue_id = i.id
                         WHERE i.assignment_id = a.id
                           AND ais.student_id = :student
                           AND " . self::issueWindowSql('i') . "
                    )
            ORDER BY a.published_at DESC, a.id DESC",
            ['student' => $studentId]
        );
    }

    /**
     * Видна ли работа ученику прямо сейчас.
     *
     * Проверяется и статус работы, и выдача, и срок. Ученик, которому работу
     * выдали, но автор снял её с публикации, видеть её не должен.
     */
    public function visibleTo(int $assignmentId, int $studentId): bool
    {
        return $this->db->value(
            "SELECT 1
               FROM assignments a
               JOIN assignment_issues i ON i.assignment_id = a.id
               JOIN assignment_issue_students ais ON ais.issue_id = i.id
              WHERE a.id = :id
                AND a.status = 'published'
                AND ais.student_id = :student
                AND " . self::issueWindowSql('i') . "
              LIMIT 1",
            ['id' => $assignmentId, 'student' => $studentId]
        ) !== null;
    }

    /**
     * Условие «срок выдачи не истёк».
     *
     * NULL в обеих колонках — «всегда». Проверяется по текущему времени, а не по
     * флагу: иначе просроченную работу пришлось бы закрывать отдельной
     * операцией, и она забывалась бы.
     */
    private static function issueWindowSql(string $alias): string
    {
        return '(' . $alias . ".available_from IS NULL OR " . $alias . '.available_from <= NOW())'
            . ' AND (' . $alias . '.available_until IS NULL OR ' . $alias . '.available_until >= NOW())';
    }

    /** @param array<string,mixed> $data @return int */
    public function create(array $data): int
    {
        return $this->db->insert('assignments', $data + [
            'status'     => self::STATUS_DRAFT,
            'grade_mode' => self::MODE_WEIGHTED,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(array $data, array $where): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->update('assignments', $data, $where);
    }

    public function delete(int $id): int
    {
        return $this->db->run('DELETE FROM assignments WHERE id = :id', ['id' => $id])->rowCount();
    }

    /**
     * Публикация работы.
     *
     * Черновик без позиций публиковать незачем: ученик увидит пустую работу.
     * Проверка сделана здесь, а не в контроллере, потому что тем же путём
     * публикуют и другие точки входа.
     */
    public function publish(int $id): string
    {
        $assignment = $this->find($id);

        if ($assignment === null) {
            return 'Работа не найдена';
        }

        if ($this->countItems($id) === 0) {
            return 'Добавьте в работу хотя бы одно задание';
        }

        $this->update(
            [
                'status'       => self::STATUS_PUBLISHED,
                'published_at' => date('Y-m-d H:i:s'),
            ],
            ['id' => $id]
        );

        return 'Работа опубликована и доступна ученикам';
    }

    public function archive(int $id): string
    {
        $this->update(['status' => self::STATUS_ARCHIVED], ['id' => $id]);

        return 'Работа закрыта, новые попытки не принимаются';
    }

    /** Возврат черновика для правки. Попытки учеников при этом сохраняются. */
    public function toDraft(int $id): string
    {
        $this->update(['status' => self::STATUS_DRAFT], ['id' => $id]);

        return 'Работа снова черновик';
    }

    // ---------------------------------------------------------------- выдачи

    /**
     * Выдать работу.
     *
     * Ученики пишутся списком с их классом на момент выдачи: класс нужен
     * сводной таблице в «Просмотре результатов», и он должен остаться тем,
     * кому работа досталась, даже если ученика переведут.
     *
     * @param array<int,int> $students [studentId => classId]
     */
    public function createIssue(
        int $assignmentId,
        int $authorId,
        array $students,
        ?string $availableFrom = null,
        ?string $availableUntil = null,
        int $maxAttempts = 0
    ): int {
        if ($students === []) {
            return 0;
        }

        return $this->db->transaction(function (Database $db) use (
            $assignmentId,
            $authorId,
            $students,
            $availableFrom,
            $availableUntil,
            $maxAttempts
        ): int {
            $issueId = $db->insert('assignment_issues', [
                'assignment_id'   => $assignmentId,
                'author_id'       => $authorId,
                /*
                 * Попытки ограничивает выдача, а не работа: одну работу можно
                 * выдать двум классам по-разному, и одна цифра у работы
                 * описывала бы только половину случаев.
                 */
                'max_attempts'    => max(0, $maxAttempts),
                'available_from'  => $availableFrom,
                'available_until' => $availableUntil,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $now = date('Y-m-d H:i:s');

            foreach ($students as $studentId => $classId) {
                $db->run(
                    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
                    . ' VALUES (:i, :s, :c, :t)',
                    [
                        'i' => $issueId,
                        's' => $studentId,
                        'c' => $classId ?: null,
                        't' => $now,
                    ]
                );
            }

            return $issueId;
        });
    }

    /** Выдача по номеру — вместе с работой и именем выдавшего. */
    public function findIssue(int $issueId): ?array
    {
        return $this->db->one(
            "SELECT i.*, a.title AS assignment_title, a.status AS assignment_status,
                    a.criteria_mode, a.grade_mode, a.time_limit,
                    a.author_id AS assignment_author, u.full_name AS author_name
               FROM assignment_issues i
               JOIN assignments a ON a.id = i.assignment_id
               JOIN users u      ON u.id = i.author_id
              WHERE i.id = :id",
            ['id' => $issueId]
        );
    }

    /**
     * Выдачи одной работы — для обзора самой работы.
     *
     * Отдельный запрос, а не фильтрация в PHP: у обзора работы и у раздела
     * результатов разный круг прав, и фильтр на странице пришлось бы
     * проверять дважды — в SQL и ещё раз в шаблоне.
     *
     * @return list<array<string,mixed>>
     */
    public function issuesForAssignment(int $assignmentId): array
    {
        return $this->db->all(
            self::issueSelectSql() . ' WHERE i.assignment_id = :a ORDER BY i.created_at DESC, i.id DESC',
            ['a' => $assignmentId]
        );
    }

    /**
     * Общий SELECT для обоих списков выдач.
     *
     * Вынесено, потому что три счётчика — длинные подзапросы, и в двух местах
     * они разошлись бы при первой же правке: в списке результатов «кто ждёт
     * проверки» и в обзоре работы «кто ждёт проверки» — это обязано быть одно и
     * то же число.
     */
    private static function issueSelectSql(): string
    {
        return "SELECT i.*, a.title AS assignment_title, a.status AS assignment_status,
                    a.parallel, a.criteria_mode, a.grade_mode, u.full_name AS author_name,
                    (SELECT COUNT(*) FROM assignment_issue_students s WHERE s.issue_id = i.id) AS issued_count,
                    (SELECT COUNT(DISTINCT t.student_id) FROM assignment_attempts t
                      WHERE t.assignment_id = i.assignment_id
                        AND EXISTS (SELECT 1 FROM assignment_issue_students s2
                                     WHERE s2.issue_id = i.id AND s2.student_id = t.student_id)
                        AND t.status = 'finished') AS finished_count,
                    (SELECT COUNT(*) FROM assignment_attempts t
                      WHERE t.assignment_id = i.assignment_id
                        AND EXISTS (SELECT 1 FROM assignment_issue_students s3
                                     WHERE s3.issue_id = i.id AND s3.student_id = t.student_id)
                        AND t.manual = 1 AND t.status = 'finished') AS pending_review_count
               FROM assignment_issues i
               JOIN assignments a ON a.id = i.assignment_id
               JOIN users u      ON u.id = i.author_id";
    }

    /**
     * Сводная таблица выдачи: кому выдано и что с этим вышло.
     *
     * LEFT JOIN на попытку: выданный, но не начавший ученик в таблице нужен —
     * по нему видно, что работа у него есть и ждёт своего часа. Попытка
     * берётся последняя по номеру, а не любая: ученик мог пройти работу
     * несколько раз, и в сводке должна быть именно последняя.
     *
     * @return list<array<string,mixed>>
     */
    public function issueStudents(int $issueId): array
    {
        /*
         * Номер работы берётся из самой строки выдачи, а не подставляется
         * вторым :issue в подзапрос: именованный параметр нельзя использовать в
         * одном запросе дважды при отключённой эмуляции prepares, и запрос
         * падал бы с ошибкой подготовки вместо пустой сводки.
         */
        return $this->db->all(
            "SELECT s.student_id, s.class_id, s.assigned_at,
                    u.full_name AS full_name,
                    c.name AS class_name,
                    t.id AS attempt_id, t.status AS attempt_status,
                    t.score, t.max_score, t.percent, t.grade,
                    t.started_at, t.finished_at, t.manual
               FROM assignment_issue_students s
               JOIN assignment_issues i ON i.id = s.issue_id
               JOIN students u          ON u.id = s.student_id
           LEFT JOIN classes c          ON c.id = s.class_id
           LEFT JOIN assignment_attempts t
                  ON t.id = (
                        SELECT t2.id FROM assignment_attempts t2
                         WHERE t2.assignment_id = i.assignment_id
                           AND t2.student_id = s.student_id
                         ORDER BY t2.attempt_no DESC, t2.id DESC LIMIT 1
                    )
              WHERE s.issue_id = :issue
           ORDER BY c.name, u.full_name",
            ['issue' => $issueId]
        );
    }

    // ---------------------------------------------------------------- позиции

    /**
 * Позиции работы по порядку показа.
 *
 * Сначала по месту в работе, потом по номеру варианта: без второго ключа
 * порядок внутри строки отдавала бы база, и альтернативы могли бы читаться
 * не слева направо. На доске это выглядело бы так, будто автор перепутал
 * столбцы, — а переставлять их было бы нечем.
 *
 * @return list<array<string,mixed>>
 */
    public function items(int $assignmentId): array
    {
    return $this->db->all(
        'SELECT i.*
           FROM assignment_items i
          WHERE i.assignment_id = :id
       ORDER BY i.position, i.variant, i.id',
        ['id' => $assignmentId]
    );
    }

    /**
 * Карточки одной строки слева направо.
 *
 * @return list<array<string,mixed>>
 */
    public function variantsAt(int $assignmentId, int $position): array
    {
    return $this->db->all(
        'SELECT *
           FROM assignment_items
          WHERE assignment_id = :id AND position = :pos
       ORDER BY variant',
        ['id' => $assignmentId, 'pos' => $position]
    );
    }

    /** @return array<string,mixed>|null */
    public function findItem(int $itemId): ?array
    {
        return $this->db->one('SELECT * FROM assignment_items WHERE id = :id', ['id' => $itemId]);
    }

    /**
     * Позиция по её номеру в работе: это то, что стоит в адресе.
     *
     * Номер строки, а не карточки: в строке может быть до трёх заданий, и
     * возвращать «какое-то» из них значило бы отдать ученику не то, что он
     * выполняет. За позицией работы стоит строка — то, что видит ученик, —
     * и по нему выбирается вариант: see pickVariantFor().
     */
    public function findItemByPosition(int $assignmentId, int $position): ?array
    {
        return $this->db->one(
            'SELECT * FROM assignment_items
              WHERE assignment_id = :id AND position = :pos
              ORDER BY variant
              LIMIT 1',
            ['id' => $assignmentId, 'pos' => $position]
        );
    }

    /**
     * Строки работы для редактора: позиция и её карточки слева направо.
     *
     * Сортировка по позиции и варианту и есть тот порядок, который видит
     * учитель на канбане. Пустых мест в строке быть не должно, но если
     * строки станут варианты из разных наборов, приведение к 1, 2, 3 живёт
     * в normalizeRow() — здесь оно уже сделано.
     *
     * @return list<array{position:int,variants:list<array<string,mixed>>}>
     */
    public function rows(int $assignmentId): array
    {
        $items = $this->items($assignmentId);
        $rows = [];

        foreach ($items as $item) {
            $position = (int) $item['position'];

            if (!isset($rows[$position])) {
                $rows[$position] = ['position' => $position, 'variants' => []];
            }

            $rows[$position]['variants'][] = $item;
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Задание, которое ученик выполняет в этой строке.
     *
     * Выбор случаен, но закреплён за попыткой: вариант считается от номера
     * попытки и номера строки, поэтому один и тот же ученик в одной попытке
     * всегда видит одно и то же задание — а прогресс, адреса и сохранённые
     * ответы не разъезжаются между страницами. Следующая попытка может
     * дать другой вариант, и это ровно то, чего ждёт человек, пересдающий
     * работу заново.
     *
     * Пустой строки быть не может: строка без карточек удаляется. Страховка
     * всё же стоит — при пустом наборе выбирается единственная карточка, а
     * при их отсутствии возвращается null, и ученик увидит «задание не
     * найдено» вместо белой страницы.
     */
    public function itemForAttempt(int $assignmentId, int $attemptId, int $position): ?array
    {
        $variants = $this->variantsAt($assignmentId, $position);

        if ($variants === []) {
            return null;
        }

        return $this->pickVariantFor($variants, $attemptId, $position);
    }

    /**
     * Позиции работы в том порядке, в котором их видит ученик.
     *
     * Обычный порядок — тот, в котором задания расставлены в редакторе.
     * Перемешанный — случайный, но один и тот же на всю попытку: порядок
     * считается от номера попытки, поэтому задания не ездят при переходе туда-
     * обратно, а у разных учеников и у разных попыток порядок разный.
     *
     * Считается один раз на запрос и запоминается: сборка читает все позиции
     * работы и сортирует их, а вызывают метод по разу на каждое задание
     * страницы.
     *
     * @return list<int> номера позиций по порядку для ученика
     */
    public function positionOrder(int $assignmentId, int $attemptId): array
    {
        $key = $assignmentId . ':' . $attemptId;

        if (!array_key_exists($key, $this->positionOrders)) {
            $this->positionOrders[$key] = $this->buildPositionOrder($assignmentId, $attemptId);
        }

        return $this->positionOrders[$key];
    }

    /**
     * Сбросить запомненный порядок.
     *
     * Нужен правкам редактора: они меняют позиции, и прочитанный до правки
     * порядок был бы устаревшим. Сбрасывается целиком, а не по работе: правки
     * приходят по одной, и точечная инвалидация не стоит лишнего места
     * для ошибки.
     */
    private function forgetPositionOrders(): void
    {
        $this->positionOrders = [];
    }

    /**
     * Сборка порядка ученика: позиции работы плюс перемешивание, если оно
     * включено.
     *
     * @return list<int>
     */
    private function buildPositionOrder(int $assignmentId, int $attemptId): array
    {
        $positions = array_map(
            static fn (array $row): int => (int) $row['position'],
            $this->rows($assignmentId)
        );

        if (!$this->shufflesItems($assignmentId)) {
            return $positions;
        }

        usort(
            $positions,
            static fn (int $a, int $b): int => strcmp(
                self::shuffleKey($attemptId, $a),
                self::shuffleKey($attemptId, $b)
            )
        );

        return $positions;
    }

    /**
     * Задание по номеру, под которым его видит ученик.
     *
     * Номер у ученика — это место в его порядке, а не номер позиции в
     * редакторе. При перемешивании они не совпадают, и путать их нельзя:
     * «дальше» ходит по номеру ученика, а хранить надо номер в редакторе.
     *
     * @param int $position номер у ученика, начиная с 1
     */
    public function itemByStudentNumber(int $assignmentId, int $attemptId, int $position): ?array
    {
        $order = $this->positionOrder($assignmentId, $attemptId);
        $editorPosition = $order[$position - 1] ?? null;

        if ($editorPosition === null) {
            return null;
        }

        $variants = $this->variantsAt($assignmentId, $editorPosition);

        if ($variants === []) {
            return null;
        }

        $item = $this->pickVariantFor($variants, $attemptId, $editorPosition);

        if ($item === null) {
            return null;
        }

        /*
         * Номер позиции заменяется на номер у ученика.
         *
         * Именно он печатается в шапке задания («Задание 2 из 5») и в списке
         * работы, и ученик должен видеть сплошную нумерацию своего порядка.
         * Номер в редакторе остаётся в базе: по нему учитель правит задание.
         */
        $item['position'] = $position;

        return $item;
    }

    /**
     * Номер позиции в порядке ученика.
     *
     * Обратная задача к positionOrder(): «какое место у этого задания у
     * ученика». Нужна там, где задание известно по номеру, а показать надо
     * номер ученика: в шапке задания и в списке работы.
     *
     * @param int $editorPosition номер позиции в редакторе
     */
    public function studentNumber(int $assignmentId, int $attemptId, int $editorPosition): int
    {
        $order = $this->positionOrder($assignmentId, $attemptId);
        $number = array_search($editorPosition, $order, true);

        return $number === false ? $editorPosition : (int) $number + 1;
    }

    /**
     * Перемешиваются ли задания этой работы.
     *
     * Флаг читается один и запоминается: без этого каждое обращение к списку
     * позиций ходило бы в базу лишний раз, а список позиций читают часто.
     */
    private function shufflesItems(int $assignmentId): bool
    {
        if (!array_key_exists($assignmentId, $this->shuffleFlags)) {
            $this->shuffleFlags[$assignmentId] = (int) $this->db->value(
                'SELECT shuffle_items FROM assignments WHERE id = :id',
                ['id' => $assignmentId]
            ) === 1;
        }

        return $this->shuffleFlags[$assignmentId];
    }

    /**
     * Ключ сортировки для перемешанного порядка.
     *
     * md5 — не криптография, а ровно то, что нужно: одинаковые попытка и
     * позиция всегда дают один ключ, а разные ученики и разные позиции
     * раздаются по-разному. Порядок не должен зависеть от того, как задания
     * лежали в редакторе, поэтому номер позиции входит в ключ своим значением.
     */
    private static function shuffleKey(int $attemptId, int $position): string
    {
        return md5(sprintf('%d:%d', $attemptId, $position));
    }

    /**
     * Позиции работы, которые видит конкретный ученик.
     *
     * По одной на строку: та самая карточка, что выпала этой попытке.
     * Внутри попытки список не меняется, поэтому «дальше» и «назад» ходят
     * по нему же и не натыкаются на чужое задание.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsForAttempt(int $assignmentId, int $attemptId): array
    {
        $rows = [];
        $number = 0;

        foreach ($this->positionOrder($assignmentId, $attemptId) as $editorPosition) {
            $variants = $this->variantsAt($assignmentId, $editorPosition);

            if ($variants === []) {
                continue;
            }

            $chosen = $this->pickVariantFor($variants, $attemptId, $editorPosition);

            if ($chosen === null) {
                continue;
            }

            /*
             * Номер позиции заменяется на номер у ученика: по нему ученик
             * считает свои задания, и при перемешивании он идёт сплошняком.
             */
            $number++;
            $chosen['position'] = $number;

            $rows[] = $chosen;
        }

        return $rows;
    }

    /**
     * Число позиций работы.
     *
     * Считаются строки, а не карточки: ученик выполняет по одной позиции в
     * строке, и «3 из 5» на странице работы должно означать три строки, а не
     * три задания из семи карточек.
     */
    public function countItems(int $assignmentId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(DISTINCT position) FROM assignment_items WHERE assignment_id = :id',
            ['id' => $assignmentId]
        );
    }

    /**
 * Выбор карточки строки для попытки.
 *
 * @param list<array<string,mixed>> $variants
 *
 * @return array<string,mixed>|null
 */
    private function pickVariantFor(array $variants, int $attemptId, int $position): ?array
    {
        if ($variants === []) {
            return null;
        }

        $index = self::variantSeed($attemptId, $position) % count($variants);

        return $variants[$index] ?? $variants[0];
    }

    /**
     * Число, из которого получается выбор варианта.
     *
     * crc32 — не криптография, а ровно то, что нужно: одинаковые попытка и
     * строка всегда дают одно число, а разные ученики и разные строки
     * раздаются по-разному. Порядок выбора от перестановки строк не должен
     * зависеть, поэтому номер строки входит в число своим значением.
     */
    private static function variantSeed(int $attemptId, int $position): int
    {
        return (int) sprintf('%u', crc32(sprintf('%d:%d', $attemptId, $position)));
    }

    /**
     * Добавление карточки в работу.
     *
     * position считается по MAX, а не по количеству строк: если из середины
     * удалили строку, новая всё равно должна оказаться последней, иначе
     * при следующем добавлении номера столкнутся.
     *
     * row — номер строки, в которую кладётся карточка. Ноль или отсутствие
     * означает «новая строка в конце». В существующую строку карточка
     * становится следующим вариантом, а цену и вес получает от первой
     * карточки строки: варианты одного места делят цену и вес, иначе сумма
     * цен перестала бы совпадать с тем, что ученик может набрать.
     *
     * maxScore — цена задания в баллах, задаёт учитель. NULL означает, что
     * максимум назначит плагин: не каждый вид задания вообще оценивается в
     * баллах, и требовать цену там, где её нет, значило бы заставлять
     * учителя вписывать бессмысленное число.
     *
     * @return int
     */
    public function addItem(
        int $assignmentId,
        TaskRef $task,
        float $weight,
        ?float $maxScore = null,
        int $row = 0,
        int $variant = 0
    ): int {
        $position = $row > 0 ? $row : $this->nextPosition($assignmentId);
        $siblings = $this->variantsAt($assignmentId, $position);

        if ($siblings !== []) {
            $first = $siblings[0];
            $weight = (float) $first['weight'];
            $maxScore = $first['max_score'] === null ? null : (float) $first['max_score'];
        }

        if ($variant < 1) {
            $variant = 1;

            foreach ($siblings as $sibling) {
                $variant = max($variant, (int) $sibling['variant'] + 1);
            }
        }

        /*
         * Больше трёх вариантов у строки быть не может: доска показывает
         * три ячейки, и четвёртая карточка просто исчезла бы с неё вместе с
         * ценой и весом. Ловить это здесь, а не в форме, — единственное место,
         * где правило нельзя обойти: и кнопка палитры, и перетаскивание
         * приходят сюда одинаково.
         */
        if ($variant > self::MAX_VARIANTS) {
            throw new \DomainException('У строки работы уже есть три варианта');
        }

        $itemId = $this->db->insert('assignment_items', [
            'assignment_id' => $assignmentId,
            'position'      => $position,
            'variant'       => $variant,
            'plugin_id'     => $task->pluginId,
            'task_kind'     => $task->kind,
            'task_id'       => $task->taskId,
            'weight'        => $weight,
            'max_score'     => $maxScore,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // Карточку бросили в середину строки: сдвигаем соседей, чтобы между
        // ними не осталось пустого места.
        $this->normalizeRow($assignmentId, $position);

        $this->forgetPositionOrders();

        return $itemId;
    }

    /**
     * Перестановка строки.
     *
     * Двигается строка целиком, со всеми её вариантами: место в работе задаёт
     * строка, а не карточка. Перетаскивание альтернативы в другое место —
     * это moveCard().
     *
     * UNIQUE (assignment_id, position, variant) не даёт соседям делить номер, и
     * освободить его можно только одним движением: строка целиком уходит на
     * временный номер выше всех, а потом занимает своё место.
     */
    public function moveRow(int $assignmentId, int $fromRow, int $toRow): bool
    {
        return $this->db->transaction(function () use ($assignmentId, $fromRow, $toRow): bool {
            $rows = $this->rows($assignmentId);
            // Номера приходят из базы строками, а сравнение строгое: без
            // приведения к целым перестановка строки отказывала бы работать
            // всегда, и кнопки со стрелками были бы мёртвыми.
            $numbers = array_map(static fn (array $row): int => (int) $row['position'], $rows);
            $maxRow = $rows === [] ? 0 : max($numbers);

            if ($fromRow < 1 || $fromRow > $maxRow || !in_array($fromRow, $numbers, true)) {
                return false;
            }

            $toRow = min(max(1, $toRow), $maxRow);

            if ($fromRow === $toRow) {
                return true;
            }

            $parked = $maxRow + 1;

            $this->db->run(
                'UPDATE assignment_items SET position = :parked
                  WHERE assignment_id = :id AND position = :row',
                ['parked' => $parked, 'id' => $assignmentId, 'row' => $fromRow]
            );

            if ($fromRow < $toRow) {
                $this->db->run(
                    'UPDATE assignment_items SET position = position - 1
                      WHERE assignment_id = :id AND position > :old AND position <= :new',
                    ['id' => $assignmentId, 'old' => $fromRow, 'new' => $toRow]
                );
            } else {
                $this->db->run(
                    'UPDATE assignment_items SET position = position + 1
                      WHERE assignment_id = :id AND position >= :new AND position < :old',
                    ['id' => $assignmentId, 'old' => $fromRow, 'new' => $toRow]
                );
            }

            $this->db->run(
                'UPDATE assignment_items SET position = :new
                  WHERE assignment_id = :id AND position = :parked',
                ['new' => $toRow, 'id' => $assignmentId, 'parked' => $parked]
            );

            $this->forgetPositionOrders();

            return true;
        });
    }

/**
 * Переставить строки целиком по присланному порядку.
     *
     * Список содержит номера строк в том виде, в каком они были до перестановки:
     * ровно так его собирает доска из `data-row`. У строки нет отдельного
     * номера, который не сдвигался бы, — строки опознаются по позиции, — поэтому
     * последовательные вызовы moveRow() здесь не годятся: первая же перестановка
     * меняет номера, и следующий вызов по старому номеру тронет не ту строку.
     * Именно это и было: доска отправляла порядок, сервер применял его неверно, а
     * результат совпадал с исходным — то есть перетаскивание выглядело
     * исправным и ничего не меняло.
     *
     * Поэтому порядок проставляется напрямую и в два прохода: сначала все строки
     * уезжают выше любой занятой позиции, потом занимают свои места по порядку.
     * Промежуточные значения за пределами рабочего диапазона — по той же
     * причине, по которой они понадобились в moveCard: прямой сдвиг соседей
     * наезжает на уникальный индекс.
     *
     * @param list<int> $order номера строк в желаемом порядке
     *
     * @return list<int> порядок, каким строки стали; пустой список — перестановка не применена
     */
    public function reorderRows(int $assignmentId, array $order): array
    {
        return $this->db->transaction(function () use ($assignmentId, $order): array {
            $known = array_map(
                static fn (array $row): int => (int) $row['position'],
                $this->rows($assignmentId)
            );

            /*
             * Порядок применяется только целиком.
             *
             * Частичный список означал бы, что доска и сервер видят разный набор
             * строк: кто-то удалил или добавил строку в другой вкладке. Тогда любая
             * перестановка по частичному списку стёрла бы недостающие строки из
             * порядка, и ученик увидел бы работу, которой автор не касался.
             */
            if ($order === [] || count($order) !== count($known)) {
                return [];
            }

            $sortedOrder = $order;
            $sortedKnown = $known;
            sort($sortedOrder);
            sort($sortedKnown);

            if ($sortedOrder !== $sortedKnown) {
                return [];
            }

            $parked = max($known) + 1;

            foreach ($order as $index => $row) {
                $this->db->run(
                    'UPDATE assignment_items SET position = :new
                      WHERE assignment_id = :id AND position = :row',
                    ['new' => $parked + $index, 'id' => $assignmentId, 'row' => $row]
                );
            }

            foreach ($order as $index => $row) {
                $this->db->run(
                    'UPDATE assignment_items SET position = :new
                      WHERE assignment_id = :id AND position = :parked',
                    ['new' => $index + 1, 'id' => $assignmentId, 'parked' => $parked + $index]
                );
            }

            $this->forgetPositionOrders();

            return array_map(
                static fn (array $row): int => (int) $row['position'],
                $this->rows($assignmentId)
            );
        });
    }

/**
   * Перенос карточки в другую строку и столбец.
      *
      * Нужна редактору канбана: перетащили карточку в соседний столбец или в
      * другую строку — она должна там оказаться, а её прежнее место освободиться.
      *
      * Занятый слот означает обмен, а не выталкивание. Учитель тянет карточку на
      * соседнюю, чтобы они поменялись местами; выталкивание соседа за край строки
      * либо упёрлось бы в потолок в три варианта, либо спрятало карточку в столбец,
      * которого на доске нет.
      *
      * Все шаги идут через промежуточные значения. Прямое «сдвинь соседей вправо
      * одним UPDATE» приводит к столкновению на уникальном индексе: карточка,
      * двигаемая из слота 2 в слот 1, на пути наезжает на занятый слот 3 прежде,
      * чем тот сдвинется. Ровно это и происходило: обмен первого и третьего
      * вариантов одной строки заканчивался «Duplicate entry '1-1-3'».
      *
      * Нулевая позиция в базе не задерживается: всё переписывается в одной
      * транзакции.
      */
    public function moveCard(int $assignmentId, int $itemId, int $targetRow, int $targetVariant = 1): bool
    {
        return $this->db->transaction(function () use ($assignmentId, $itemId, $targetRow, $targetVariant): bool {
            $item = $this->findItem($itemId);

            if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
                return false;
            }

            $fromRow = (int) $item['position'];
            $fromVariant = (int) $item['variant'];
            $maxRow = $this->maxPosition($assignmentId);

            /*
             * Целевая строка может быть на одну дальше последней: доска всегда
             * кончается пустой строкой, и бросок карточки в неё означает «поставь
             * это задание в конец работы». Без этого лишнего ряда карточка
             * молча прижалась бы к последней строке и стала её альтернативой —
             * учитель уронил бы её на «свободное место» и получил совсем другую
             * работу, не заметив подмены.
             */
            $targetRow = min(max(1, $targetRow), max(1, $maxRow) + 1);
            $targetVariant = min(max(1, $targetVariant), self::MAX_VARIANTS);

            $this->db->run(
                'UPDATE assignment_items SET variant = 0 WHERE id = :id',
                ['id' => $itemId]
            );

            if ($fromRow === $targetRow) {
                /*
                 * Перестановка внутри строки.
                 *
                 * Занятый слот означает обмен, а не выталкивание: учитель тянет
                 * карточку на соседнюю, чтобы они поменялись местами, и вытолкнуть
                 * соседа за край строки некуда — в строке помещается три варианта.
                 *
                 * Раньше здесь стояло «сдвинь соседей вправо», и обмен первого и
                 * третьего вариантов заканчивался «Duplicate entry '1-1-3'»:
                 * сдвигать было нечего, слот 3 — последний, и карточка наезжала на
                 * того, кто там стоял.
                 */
                $displaced = null;
                $stay = [];

                foreach ($this->db->all(
                    'SELECT id, variant FROM assignment_items
                      WHERE assignment_id = :id AND position = :row AND variant > 0
                      ORDER BY variant',
                    ['id' => $assignmentId, 'row' => $targetRow]
                ) as $occupant) {
                    $original = (int) $occupant['variant'];

                    if ($original === $targetVariant) {
                        $displaced = (int) $occupant['id'];

                        continue;
                    }

                    $stay[(int) $occupant['id']] = $original;
                }

                if ($displaced !== null) {
                    /*
                     * Вытесненный занимает освободившееся место движущейся, а
                     * остальные остаются на своих номерах: сдвиг загнал бы одну из
                     * них ровно на освободившийся слот.
                     */
                    $this->db->run(
                        'UPDATE assignment_items SET variant = :var WHERE id = :id',
                        ['var' => $fromVariant, 'id' => $displaced]
                    );
                }

                foreach ($stay as $id => $original) {
                    $final = $displaced === null && $original > $targetVariant ? $original + 1 : $original;

                    $this->db->run(
                        'UPDATE assignment_items SET variant = :var WHERE id = :id',
                        ['var' => $final, 'id' => $id]
                    );
                }
            } else {
                /*
                 * Перенос в другую строку: карточка становится ещё одним
                 * вариантом целевой строки, а прежняя строка теряет свою.
                 *
                 * Карточки целевой строки сдвигаются вправо, и порядок важен:
                 * сначала освобождаем, потом занимаем. Нумерация в запасные
                 * номера здесь не нужна — сдвиг идёт одним оператором, и MySQL
                 * применяет его по строке, обходя столкновение.
                 */
                $this->db->run(
                    'UPDATE assignment_items SET variant = variant + 1
                      WHERE assignment_id = :id AND position = :row AND variant >= :var AND variant < :max',
                    ['id' => $assignmentId, 'row' => $targetRow, 'var' => $targetVariant, 'max' => self::MAX_VARIANTS]
                );
            }

            $this->db->run(
                'UPDATE assignment_items SET position = :row, variant = :var WHERE id = :id',
                ['row' => $targetRow, 'var' => $targetVariant, 'id' => $itemId]
            );

            /*
             * Пустая строка исчезает, и оставшиеся строки сдвигаются: без этого
             * в работе остался бы номер без карточек, а адрес по такой позиции
             * показывал бы «задание не найдено».
             *
             * Номер целевой строки при этом тоже уезжает на единицу. Забыть
             * об этом — значит причесать пустую строку вместо той, где карточка
             * оказалась: карточка встала бы вторым или третьим вариантом строки,
             * где вариантов быть не должно.
             */
            if ($fromRow !== $targetRow && $this->variantsAt($assignmentId, $fromRow) === []) {
                $this->db->run(
                    'UPDATE assignment_items SET position = position - 1
                      WHERE assignment_id = :id AND position > :row',
                    ['id' => $assignmentId, 'row' => $fromRow]
                );

                if ($targetRow > $fromRow) {
                    $targetRow--;
                }
            }

            $this->normalizeRow($assignmentId, $targetRow);

            $this->forgetPositionOrders();

            return true;
        });
    }

    /**
     * Приведение строки к виду 1, 2, 3 — без пропусков.
     *
     * Карточки, у которых в середине оказалась дыра, сдвигаются влево: слева
     * основное задание, справа альтернативы, и ученику не достаётся строка,
     * где основного нет.
     */
    public function normalizeRow(int $assignmentId, int $position): void
    {
        $this->db->transaction(function () use ($assignmentId, $position): void {
            $index = 1;

            foreach ($this->variantsAt($assignmentId, $position) as $item) {
                if ((int) $item['variant'] === $index) {
                    $index++;

                    continue;
                }

                $this->db->run(
                    'UPDATE assignment_items SET variant = 0 WHERE id = :id',
                    ['id' => (int) $item['id']]
                );

                $this->db->run(
                    'UPDATE assignment_items SET variant = :var WHERE id = :id',
                    ['var' => $index, 'id' => (int) $item['id']]
                );

                $index++;
            }
        });
    }

    /**
     * Перестановка позиции.
     *
     * UNIQUE (assignment_id, position) запрещает две позиции с одним номером,
     * поэтому сначала освобождаем номер, потом занимает его сосед.
     */
    public function moveItem(int $assignmentId, int $itemId, int $newPosition): bool
    {
        $item = $this->findItem($itemId);

        return $item !== null
            && (int) $item['assignment_id'] === $assignmentId
            && $this->moveRow($assignmentId, (int) $item['position'], $newPosition);
    }

    /**
     * Удаление карточки.
     *
     * Пустая строка исчезает, и оставшиеся строки сдвигаются: иначе в работе
     * остался бы номер без карточек, а адрес по такой позиции показывал бы
     * «задание не найдено». Если в строке остались карточки, сдвигаются влево
     * варианты, а номер строки прежний — место в работе не меняется тем, что
     * убрали одну из альтернатив.
     *
     * Результаты попыток удаляются каскадом — они без позиции бессмысленны.
     */
    public function deleteItem(int $assignmentId, int $itemId): bool
    {
        return $this->db->transaction(function () use ($assignmentId, $itemId): bool {
            $item = $this->findItem($itemId);

            if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
                return false;
            }

            $position = (int) $item['position'];

            $this->db->run('DELETE FROM assignment_items WHERE id = :id', ['id' => $itemId]);

            if ($this->variantsAt($assignmentId, $position) === []) {
                $this->db->run(
                    'UPDATE assignment_items SET position = position - 1
                      WHERE assignment_id = :id AND position > :pos',
                    ['id' => $assignmentId, 'pos' => $position]
                );

                $this->forgetPositionOrders();

                return true;
            }

            $this->normalizeRow($assignmentId, $position);

            $this->forgetPositionOrders();

            return true;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateItem(array $data, int $itemId): int
    {
    return $this->db->update('assignment_items', $data, ['id' => $itemId]);
    }

    // ---------------------------------------------------------------- попытки

    /** @return array<string,mixed>|null */
    public function findAttempt(int $id): ?array
    {
        return $this->db->one(
            'SELECT t.*, a.title AS assignment_title, a.subject_id, a.status AS assignment_status,
                    a.grade_mode, a.criteria_mode, a.show_results, a.criteria, a.time_limit, a.no_copy,
                    s.name AS subject_name
               FROM assignment_attempts t
               JOIN assignments a ON a.id = t.assignment_id
          LEFT JOIN subjects s ON s.id = a.subject_id
              WHERE t.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Текущая незавершённая попытка — по действующему лицу.
     *
     * Условие собирается по колонке, заполняемой этим лицом: ученик пишется в
     * student_id, сотрудник, начавший работу сам, — в staff_id. Общей колонки
     * для них нет: номера в двух таблицах независимы.
     */
    public function findActiveAttempt(int $assignmentId, Actor $actor): ?array
    {
        [$where, $params] = $this->actorCondition('t', $actor);

        return $this->db->one(
            "SELECT * FROM assignment_attempts t
              WHERE t.assignment_id = :id AND {$where} AND t.status = 'in_progress'
           ORDER BY t.id DESC
              LIMIT 1",
            ['id' => $assignmentId] + $params
        );
    }

    /** Последняя попытка — чтобы не плодить их. */
    public function findLastAttempt(int $assignmentId, Actor $actor): ?array
    {
        [$where, $params] = $this->actorCondition('t', $actor);

        return $this->db->one(
            "SELECT * FROM assignment_attempts t
              WHERE t.assignment_id = :id AND {$where}
           ORDER BY t.attempt_no DESC
              LIMIT 1",
            ['id' => $assignmentId] + $params
        );
    }

    /**
     * Все попытки по работе — для автора.
     *
     * Соединение идёт со `students`, а не с `users`: попытки учеников живут в
     * своей таблице. Самопроверки учителей в этот список не попадают — они не
     * результат ученика, и автору работы показывать их незачем.
     *
     * @return list<array<string,mixed>>
     */
    public function attemptsForAssignment(int $assignmentId): array
    {
        return $this->db->all(
            'SELECT t.*, u.full_name
               FROM assignment_attempts t
               JOIN students u ON u.id = t.student_id
              WHERE t.assignment_id = :id
           ORDER BY t.attempt_no DESC, u.full_name',
            ['id' => $assignmentId]
        );
    }

    /**
     * Сколько попыток автора ждут проверки.
     *
     * Число приводится к int явно: Database::value() отдаёт строку из MySQL, и
     * без приведения «0» сравнивался бы с 0 как ложь — значок в меню не показал
     * бы «нулевые» ожидания, хотя это как раз самый частый случай.
     *
     * manual = 1 ставится, когда хотя бы одна позиция вернула needsReview:
     * автопроверки у плагина нет, и итог без учителя посчитать нельзя.
     *
     * Считаются только ученические попытки: самопроверка учителя на своей же
     * работе — не чужая работа, ждать проверки она не может, и в счётчик
     * попадать не должна.
     */
    public function countPendingReview(int $teacherId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*)
               FROM assignment_attempts t
               JOIN assignments a ON a.id = t.assignment_id
              WHERE t.manual = 1 AND t.status = 'finished'
                AND t.student_id IS NOT NULL
                AND a.author_id = :teacher",
            ['teacher' => $teacherId]
        );
    }

    /**
     * Сколько выдач ждут проверки.
     *
     * Считаются выдачи, а не попытки: в меню помещается одно число, и оно
     * должно отвечать на вопрос, который задаёт человек, — «сколько у меня
     * выдано такого, что надо посмотреть». Попыток внутри одной выдачи может
     * быть несколько, и число попыток сбивало бы масштаб.
     *
     * Круг выдач зависит от того, кто смотрит: завуч и администратор видят все
     * работы школы ($authorId = 0), учитель — только по своим работам. Счётчик
     * стоит на пункте «Результаты» и на колокольчике в шапке, поэтому он обязан
     * считать ровно то, что журнал покажет по нажатию: цифра на кнопке и
     * раздел за ней считаются одним и тем же правилом, иначе учитель увидел бы
     * чужие работы в счётчике — и не нашёл бы их в журнале.
     *
     * @param int $authorId автор работ или 0, если видны все
     */
    public function countIssuesPendingReview(int $authorId = 0): int
    {
        $where = $authorId > 0 ? ' AND a.author_id = :review_author' : '';

        /*
         * Соединение идёт по student_id, и у самопроверки учителя эта колонка
         * пуста: его попытка — не результат ученика, и в счётчик проверки она
         * попадать не должна. Ровно того же результата можно было бы добиться
         * добавлением t.student_id IS NOT NULL, но при JOIN это избыточно: при
         * NULL условие сопоставления не выполнится.
         */
        return (int) $this->db->value(
            "SELECT COUNT(DISTINCT i.id)
               FROM assignment_issues i
               JOIN assignment_attempts t ON t.assignment_id = i.assignment_id
               JOIN assignment_issue_students s ON s.issue_id = i.id AND s.student_id = t.student_id
               JOIN assignments a ON a.id = i.assignment_id
              WHERE t.manual = 1 AND t.status = 'finished'{$where}",
            $authorId > 0 ? ['review_author' => $authorId] : []
        );
    }

    /**
     * Начало попытки.
     *
     * Незавершённая попытка возвращается вместо новой: перезагрузка страницы
     * не должна съедать лимит попыток и терять ответы. Номер попытки берётся
     * из последней существующей, а не из COUNT: прерванные попытки тоже
     * считаются.
     *
     * Лимит проверяется здесь же, потому что это единственное место, где
     * попытка появляется. Проверка в контроллере обошла бы любой другой вход.
     *
     * @return array{ok:bool,message:string,attempt:array<string,mixed>|null}
     */
    public function startAttempt(int $assignmentId, Actor $actor, string $token, bool $mayRepeat = false): array
    {
        return $this->db->transaction(function () use ($assignmentId, $actor, $token, $mayRepeat): array {
            $active = $this->findActiveAttempt($assignmentId, $actor);

            if ($active !== null) {
                return ['ok' => false, 'message' => 'Попытка уже начата', 'attempt' => $active];
            }

            $last = $this->findLastAttempt($assignmentId, $actor);
            $attemptNo = $last === null ? 1 : ((int) $last['attempt_no'] + 1);

            $limit = $this->attemptLimit($assignmentId, $actor);

            if (!$mayRepeat && $limit !== null && $attemptNo > $limit) {
                return [
                    'ok'      => false,
                    'message' => 'Вы уже выполнили эту работу максимальное число раз.',
                    'attempt' => $last,
                ];
            }

            try {
                /*
                 * Заполняется ровно одна колонка: ученик пишется в student_id,
                 * сотрудник — в staff_id. Это держит репозиторий, а не база —
                 * CHECK-ограничение здесь не годится, потому что снимок схемы
                 * его не разбирает и свежая установка разошлась бы с файлом.
                 */
                $id = $this->db->insert('assignment_attempts', [
                    'assignment_id' => $assignmentId,
                    'student_id'    => $actor->studentId,
                    'staff_id'      => $actor->staffId,
                    'token'         => $token,
                    'attempt_no'    => $attemptNo,
                    'status'        => self::ATTEMPT_IN_PROGRESS,
                    'started_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $e) {
                /*
                 * Гонка двух одновременных POST: оба успели прочитать один и тот
                 * же последний номер попытки. Уникальный индекс не даёт записать
                 * лишнее, но нарушение приходит исключением, и без перехвата
                 * человек увидел бы 500 вместо понятного «уже начато».
                 *
                 * Важно: откатывается вся транзакция, поэтому уже вставленная
                 * первым запросом попытка остаётся в силе — её и показываем.
                 */
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                return [
                    'ok'      => false,
                    'message' => 'Попытка уже начата',
                    'attempt' => $this->findActiveAttempt($assignmentId, $actor) ?? $last,
                ];
            }

            return [
                'ok'      => true,
                'message' => 'Попытка начата',
                'attempt' => $this->findAttempt($id),
            ];
        });
    }

    /**
     * Условие выборки попыток по действующему лицу.
     *
     * Возвращает фрагмент WHERE и его параметры. Ученик сопоставляется с
     * student_id, сотрудник — с staff_id: в каждой попытке заполнена ровно
     * одна из колонок, и сверх неё сопоставлять нечего.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function actorCondition(string $alias, Actor $actor): array
    {
        return $actor->isStudent()
            ? ["{$alias}.student_id = :actor_student", ['actor_student' => $actor->studentId]]
            : ["{$alias}.staff_id = :actor_staff", ['actor_staff' => $actor->staffId]];
    }

    /**
     * Нарушение уникального индекса, а не что-то другое.
     *
     * MySQL и MariaDB отдают эту ошибку по-разному, поэтому проверяются оба
     * варианта. Остальные нарушения целостности сюда попадать не должны и
     * уходят наружу: молча превращать их в «уже начата» нельзя.
     */
    private function isDuplicateKey(\PDOException $e): bool
    {
        if ($e->getCode() === '23000' && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
            return true;
        }

        // Драйвер может не заполнить errorInfo, а в тексте остаётся код.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), '1062');
    }

    /**
     * Сколько попыток разрешено по этой работе.
     *
     * null — без ограничения. Лимит берётся из выдачи: работа может быть выдана
     * двум классам по-разному, и ограничение у неё своей быть не может.
     *
     * Сотрудник лимитом не ограничен вовсе: он начинает работу сам, чтобы её
     * проверить, и ограничивать его числом, придуманным для учеников, нельзя —
     * иначе завуч не смог бы перепроверить работу после правки. Поэтому для
     * сотрудника здесь всегда null, и выдача даже не спрашивается.
     */
    public function attemptLimit(int $assignmentId, Actor $actor): ?int
    {
        if (!$actor->isStudent()) {
            return null;
        }

        $issue = $this->findIssueForStudent($assignmentId, (int) $actor->studentId);
        $value = (int) ($issue['max_attempts'] ?? 0);

        return $value > 0 ? $value : null;
    }

    /**
     * Выдача, по которой ученик получил работу.
     *
     * Именно она решает, сколько попыток разрешено: лимит живёт в выдаче, а не в
     * работе. Одна работа может быть выдана дважды и с разными числами попыток,
     * поэтому берётся последняя подходящая выдача — та, что действует сейчас.
     * Если выдач несколько, ученик видит условия самой свежей: она и есть его
     * текущая договорённость с учителем.
     *
     * Ссылка идёт на students: выдача всегда ученическая, у сотрудника её нет.
     *
     * null — выдачи нет. В этом случае попыток не ограничено, а проверка видимости
     * работы всё равно не пустит ученика к попытке.
     */
    public function findIssueForStudent(int $assignmentId, int $studentId): ?array
    {
    return $this->db->one(
        "SELECT i.*
           FROM assignment_issues i
           JOIN assignment_issue_students ais ON ais.issue_id = i.id
          WHERE i.assignment_id = :id
            AND ais.student_id = :student
            AND " . self::issueWindowSql('i') . "
       ORDER BY i.id DESC
          LIMIT 1",
        ['id' => $assignmentId, 'student' => $studentId]
    );
    }

    /**
     * Сколько попыток уже сделано — по действующему лицу.
     *
     * Считается по той же колонке, в которую попытка записывается, поэтому
     * самопроверки учителя не смешиваются с работами учеников.
     */
    public function countAttempts(int $assignmentId, Actor $actor): int
    {
        [$where, $params] = $this->actorCondition('t', $actor);

        return (int) $this->db->value(
            "SELECT COUNT(*) FROM assignment_attempts t WHERE t.assignment_id = :id AND {$where}",
            ['id' => $assignmentId] + $params
        );
    }

    /**
     * Сколько попыток сделали все, вместе взятые.
     *
     * Отдельный метод, а не countAttempts() с нулевым пользователем: на
     * странице подтверждения удаления показывается, сколько чужих результатов
     * пропадёт вместе с работой, и посчитать это надо по всем сразу.
     */
    public function countAllAttempts(int $assignmentId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id',
            ['id' => $assignmentId]
        );
    }

    /**
     * Количество выдач работы.
     */
    public function countIssued(int $assignmentId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM assignment_issue_students ais
             JOIN assignment_issues i ON i.id = ais.issue_id
             WHERE i.assignment_id = :id',
            ['id' => $assignmentId]
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function updateAttempt(array $data, array $where): int
    {
        return $this->db->update('assignment_attempts', $data, $where);
    }

    /**
     * Результаты позиций попытки вместе с самими позициями.
     *
     * Позиции — те, что выпали именно этой попытке, то есть по одной карточке
     * на строку: то же, что itemsForAttempt(). Раньше здесь шёл полный список
     * карточек работы, и у вариантов, не выпавших этой попытке, результата
     * не было вовсе. Свод считал такое за непроверенную позицию, поэтому
     * работа с вариантами навсегда оставалась «ждущей проверки», хотя всё
     * было посчитано автоматически, а в списке учителя мелькали чужие
     * варианты.
     *
     * LEFT JOIN заменён на выборку результата по уже известным позициям.
     * Колонки назначаются явно, а не через «запись + запись»: у позиции и у
     * результата есть своя max_score, и названия колонок в выборке
     * совпадали — побеждала последняя, то есть результата. Здесь это же
     * правило выражено словами: максимум берётся из результата.
     *
     * @return list<array<string,mixed>>
     */
    public function resultsForAttempt(int $attemptId): array
    {
        $attempt = $this->findAttempt($attemptId);

        if ($attempt === null) {
            return [];
        }

        $items = $this->itemsForAttempt((int) $attempt['assignment_id'], $attemptId);

        if ($items === []) {
            return [];
        }

        $results = $this->resultsByItem(
            $attemptId,
            array_map(static fn (array $item): int => (int) $item['id'], $items)
        );

        $rows = [];

        foreach ($items as $item) {
            $result = $results[(int) $item['id']] ?? null;

            /*
             * array_merge, а не «массив + массив»: у позиции и результата
             * своя max_score, и «+» оставил бы значение позиции — то есть
             * максимум всей карточки вместо максимума проверенного ответа.
             * На слиянии побеждает правая часть, и максимум берётся из
             * результата, как и раньше в запросе с двумя одноимёнными
             * колонками.
             */
            $rows[] = array_merge($item, [
                'result_id'    => $result['id'] ?? null,
                'score'        => $result['score'] ?? null,
                'max_score'    => $result['max_score'] ?? null,
                'percent'      => $result['percent'] ?? null,
                'grade'        => $result['grade'] ?? null,
                'penalty'      => $result['penalty'] ?? null,
                'result_status' => $result['status'] ?? null,
                'needs_review' => $result['needs_review'] ?? 0,
                'comment'      => $result['comment'] ?? null,
                'checked_at'   => $result['checked_at'] ?? null,
                'checked_by'   => $result['checked_by'] ?? null,
                'snapshot'     => $result['snapshot'] ?? null,
            ]);
        }

        return $rows;
    }

    /**
     * Результаты указанных позиций у одной попытки, по позиции.
     *
     * Эмуляция prepares отключена, поэтому имя колонки IN собирается из
     * разных имён параметра: одинаковый :имя дважды в запросе PDO считает
     * лишним и отвечает «Invalid parameter number».
     *
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>>
     */
    private function resultsByItem(int $attemptId, array $itemIds): array
    {
        $placeholders = [];
        $params = ['attempt' => $attemptId];

        foreach (array_values($itemIds) as $index => $itemId) {
            $key = 'item' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $itemId;
        }

        $byItem = [];

        foreach ($this->db->all(
            'SELECT * FROM assignment_item_results
              WHERE attempt_id = :attempt AND item_id IN (' . implode(', ', $placeholders) . ')',
            $params
        ) as $row) {
            $byItem[(int) $row['item_id']] = $row;
        }

        return $byItem;
    }

    /** @return array<string,mixed>|null результат по позиции, если он уже есть */
    public function findResult(int $attemptId, int $itemId): ?array
    {
        return $this->db->one(
            'SELECT * FROM assignment_item_results WHERE attempt_id = :attempt AND item_id = :item',
            ['attempt' => $attemptId, 'item' => $itemId]
        );
    }

    /**
     * Сохранение результата позиции.
     *
     * UNIQUE (attempt_id, item_id) делает запись идемпотентной: и автопроверка,
     * и учитель пишут в одну и ту же строку, и повтор не плодит дубли.
     *
     * @param array<string,mixed> $data
     */
    public function saveResult(int $attemptId, int $itemId, array $data): void
    {
        $this->db->upsert(
            'assignment_item_results',
            $data + [
                'attempt_id' => $attemptId,
                'item_id'    => $itemId,
            ],
            ['attempt_id', 'item_id']
        );
    }

    // --------------------------------------------- удаление попыток

    /**
     * Удалить одну попытку.
     *
     * Решение владельца от 24.10.2026 (задача 6): результаты тестового
     * прогона существуют только, пока открыта вкладка с ним, — при закрытии
     * вкладки (скрипт шлёт beacon) и при новом тестовом старте попытка
     * убирается, в базе она не должна оставаться. Результаты позиций уходят
     * каскадом по внешнему ключу вместе с попыткой.
     *
     * Данные плагинов здесь не убираются: репозиторий не знает их таблиц, и
     * вписывать в ядро имя `test_item_attempts` значило бы добавлять каждому
     * новому плагину свою строку сюда — и забывать её. Плагины, хранящие
     * данные по попытке, реализуют AttemptAwarePlugin; уборку вызывает
     * контроллер прохождения перед этим удалением.
     */
    public function deleteAttempt(int $attemptId): void
    {
        $this->db->run('DELETE FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]);
    }

    /**
     * Номера всех попыток ученика.
     *
     * Страховка перед новым тестовым проходом: виртуальный ученик один на
     * всю систему (StudentRepository::TRIAL_ID), и прежние его попытки
     * принадлежат прошлым проходам (возможно, уже закрытым вкладкам). Новый
     * старт чистит всё прошлое — но удаляет его сам контроллер, вызывая для
     * каждого номера и уборку данных плагинов.
     *
     * @return list<int>
     */
    public function attemptIdsOfStudent(int $studentId): array
    {
        $ids = [];

        foreach ($this->db->all(
            'SELECT id FROM assignment_attempts WHERE student_id = :student',
            ['student' => $studentId]
        ) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }


    /**
     * Срок выдачи работы для ученика — независимо от того, истёк он или нет.
     *
     * Для штрафа за опоздание срок нужен именно истёкшим: адрес, который
     * отдаёт только действующие выдачи, истёкшую не находит — и «опоздал» и
     * «срок ещё не наступил» оказались бы одним и тем же.
     *
     * Ученика может быть несколько выдач, и берётся самый поздний срок:
     * последний, когда работа была доступна.
     */
    public function deadlineForStudent(int $assignmentId, int $studentId): ?string
    {
        $deadline = $this->db->value(
            "SELECT MAX(i.available_until)
               FROM assignment_issues i
               JOIN assignment_issue_students s ON s.issue_id = i.id
              WHERE i.assignment_id = :assignment
                AND s.student_id = :student
                AND i.available_until IS NOT NULL",
            ['assignment' => $assignmentId, 'student' => $studentId]
        );

        return $deadline === null ? null : (string) $deadline;
    }


    // ---------------------------------------------------------------- служебное

    private function nextPosition(int $assignmentId): int
    {
        return $this->maxPosition($assignmentId) + 1;
    }

    private function maxPosition(int $assignmentId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(MAX(position), 0) FROM assignment_items WHERE assignment_id = :id',
            ['id' => $assignmentId]
        );
    }
}
