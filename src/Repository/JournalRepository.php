<?php

declare(strict_types=1);

namespace MyEdu\Repository;

use MyEdu\Core\Database;

/**
 * Журнал результатов: сводки по выданным работам.
 *
 * Отдельный репозиторий, а не раздел AssignmentRepository: журнал — своя
 * вертикаль. У него свой круг доступа (круг работ зависит от того, кто
 * смотрит: администратору — все, учителю и завучу — выданные ими) и свои
 * источники — выдачи плюс попытки, объединённые так, чтобы отзыв выдачи не
 * прятал уже сданные работы. Методы только читают: писать в попытки и
 * выдачи журналу незачем.
 *
 * Класс берётся из `assignment_issue_students` — это класс на момент выдачи;
 * попытки соединяются со `students` по ученической колонке, поэтому
 * самопроверка учителя в сводках не значится.
 */
final class JournalRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Журнал: выданные работы, сгруппированные по классам.
     *
     * В журнале попадают только те работы, которые кому-то выдавали: работа,
     * которую никто не получал, результатов не имеет и в журнале была бы
     * пустым разделом.
     *
     * Класс берётся из `assignment_issue_students` — это класс на момент
     * выдачи, и он может отличаться от того, где ученик учится сейчас.
     *
     * Круг работ зависит от того, кто смотрит. Все работы школы ($authorId
     * = 0) видит только администратор, учителю и завучу — только свои, которые
     * выдали они: иначе раздел показал бы ему результаты чужих классов, к
     * которым у него нет доступа. Та же граница действует на странице работы
     * и на проверке попыток.
     *
     * Счётчики «сдано» и «ждут проверки» считают только ученические попытки
     * (student_id заполнен): рядом с ними стоит «выдано», которое считает
     * учеников, и самопроверка учителя завышала бы первое число, делая его
     * больше второго.
     *
     * @param int $authorId автор работ или 0, если видны все
     * @return list<array<string,mixed>> строки «класс + работа»
     */
    public function journalIssuedWorks(int $authorId = 0): array
    {
        $where = $authorId > 0 ? ' WHERE a.author_id = :journal_author' : '';

        /*
         * Пары «работа + класс» собираются из двух источников, а не только из
         * выдач.
         *
         * Первая половина — прежняя: кому работу выдали, с классом на момент
         * выдачи. Вторая — попытки: ученик, прошедший работу, остаётся в
         * журнале со своим текущим классом, даже когда выдачу отозвали.
         * Отзыв удаляет `assignment_issues`, и работа целиком пропадала бы из
         * журнала вместе с результатами всех, кто её сдал, — вопреки
         * обещанию «Результаты учеников сохранены».
         *
         * UNION, а не UNION DISTINCT: DISTINCT в конце и так уберёт дубли
         * пары, а сам он в подзапросе объединения запрещён в этой
         * конфигурации MySQL.
         */
        return $this->db->all(
            "SELECT DISTINCT c.id AS class_id, c.name AS class_name, c.title AS class_title,
                    a.id AS assignment_id, a.title AS assignment_title,
                    a.published_at, a.status AS assignment_status,
                    (SELECT COUNT(DISTINCT u2.student_id)
                       FROM (
                           SELECT s2.student_id, s2.class_id, i2.assignment_id
                             FROM assignment_issue_students s2
                             JOIN assignment_issues i2 ON i2.id = s2.issue_id
                           UNION
                           SELECT t2.student_id, st2.class_id, t2.assignment_id
                             FROM assignment_attempts t2
                             JOIN students st2 ON st2.id = t2.student_id
                            WHERE t2.student_id IS NOT NULL
                              AND st2.class_id IS NOT NULL
                       ) u2
                      WHERE u2.assignment_id = a.id
                        AND u2.class_id = c.id) AS issued_count,
                    (SELECT COUNT(*)
                       FROM assignment_attempts t
                      WHERE t.assignment_id = a.id
                        AND t.status = 'finished'
                        AND t.student_id IS NOT NULL) AS finished_count,
                    (SELECT COUNT(*)
                       FROM assignment_attempts t
                      WHERE t.assignment_id = a.id
                        AND t.manual = 1
                        AND t.status = 'finished'
                        AND t.student_id IS NOT NULL) AS review_count
               FROM (
                   SELECT i.assignment_id, s.class_id
                     FROM assignment_issue_students s
                     JOIN assignment_issues i ON i.id = s.issue_id
                   UNION
                   SELECT t.assignment_id, st.class_id
                     FROM assignment_attempts t
                     JOIN students st ON st.id = t.student_id
                    WHERE t.student_id IS NOT NULL
                      AND st.class_id IS NOT NULL
               ) pairs
               JOIN assignments a ON a.id = pairs.assignment_id
               JOIN classes c ON c.id = pairs.class_id
               {$where}
              ORDER BY c.name, a.title",
            $authorId > 0 ? ['journal_author' => $authorId] : []
        );
    }

    /**
     * Классы, с которыми работа связана: вкладки на странице работы.
     *
     * Как и в общем списке журнала, берётся объединение двух источников:
     * кому работу выдали (с классом на момент выдачи) и кто по ней проходил
     * (с текущим классом ученика). Вторая половина нужна после отзыва
     * выдачи — без неё страница работы отвечала бы 404 «Работа никому не
     * выдавалась», и результаты становились бы недоступны, хотя попытки
     * и оценки остались в базе.
     *
     * @return list<array<string,mixed>>
     */
    public function journalWorkClasses(int $assignmentId): array
    {
        return $this->db->all(
            "SELECT c.id, c.name, c.title,
                    COUNT(DISTINCT u.student_id) AS student_count
               FROM (
                   SELECT s.student_id, s.class_id
                     FROM assignment_issue_students s
                     JOIN assignment_issues i ON i.id = s.issue_id
                    WHERE i.assignment_id = :assignment1
                   UNION
                   SELECT t.student_id, st.class_id
                     FROM assignment_attempts t
                     JOIN students st ON st.id = t.student_id
                    WHERE t.assignment_id = :assignment2
                      AND t.student_id IS NOT NULL
                      AND st.class_id IS NOT NULL
               ) u
               JOIN classes c ON c.id = u.class_id
              GROUP BY c.id, c.name, c.title
              ORDER BY c.name",
            ['assignment1' => $assignmentId, 'assignment2' => $assignmentId]
        );
    }

    /**
     * Ученики класса и их последняя попытка по работе.
     *
     * Показывается последняя попытка: пересдачи не должны превращать журнал в
     * список дублей, а учителю важен итоговый результат.
     *
     * Срок выдачи нужен для отметки опоздания: работа, сданная после срока,
     * помечается в таблице отдельно.
     *
     * Список идёт по `students`: соединение по ученической колонке попыток,
     * поэтому самопроверка учителя в журнал не попадает — его попытка пишется
     * в другую колонку и здесь просто не сопоставляется.
     *
     * @return list<array<string,mixed>>
     */
    public function journalWorkStudents(int $assignmentId, int $classId): array
    {
        /*
         * Ученики берутся из тех же двух источников, что и вкладки классов:
         * кто получил работу (класс на момент выдачи) и кто по ней проходил
         * (класс ученика сейчас). Вторая половина отвечает за период после
         * отзыва выдачи, когда строк выдачи в базе уже нет.
         *
         * EXISTS, а не UNION по ученикам: так проще читается условие «есть
         * выдача этому классу ИЛИ есть попытка у нынешнего ученика этого
         * класса», а проекция результата — попытки с последним счётом —
         * остаётся прежней.
         */
        return $this->db->all(
            "SELECT DISTINCT u.id AS student_id, u.full_name,
                    t.id AS attempt_id, t.attempt_no, t.status, t.score, t.max_score,
                    t.percent, t.grade, t.penalty, t.late_penalty, t.manual_penalty,
                    t.penalty_comment, t.started_at, t.finished_at, t.manual
               FROM students u
          LEFT JOIN assignment_attempts t
                 ON t.student_id = u.id
                AND t.assignment_id = :assignment
              WHERE EXISTS (
                        SELECT 1
                          FROM assignment_issue_students s
                          JOIN assignment_issues i ON i.id = s.issue_id
                         WHERE i.assignment_id = :assignment_issued
                           AND s.student_id = u.id
                           AND s.class_id = :class
                    )
                 OR (t.id IS NOT NULL AND u.class_id = :class_now)
              ORDER BY u.full_name, t.attempt_no",
            [
                'assignment'         => $assignmentId,
                'assignment_issued'  => $assignmentId,
                'class'              => $classId,
                'class_now'          => $classId,
            ]
        );
    }

    /**
     * Сроки выдачи работы: ученик и срок, под которым он её получил.
     *
     * Срок нужен для отметки опоздания: работа, сданная после него, помечается
     * в таблице отдельным значком. У ученика может быть несколько выдач, и
     * берётся самый поздний срок — последний, когда работа была доступна.
     *
     * В отличие от списка классов и учеников, этот метод намеренно читает
     * только выдачи. Срок живёт в самой выдаче, в попытке его копии нет, и
     * после отзыва выдачи отметить опоздание не по чему — в журнале такие
     * ученики показываются без значка, а не с выдуманным сроком. Подменять
     * срок датой попытки нельзя: это уже другое правило, и оно перестало бы
     * совпадать с тем, что видел ученик при прохождении.
     *
     * @return array<int,string> срок по ученику
     */
    public function journalWorkDeadlines(int $assignmentId): array
    {
        $rows = $this->db->all(
            "SELECT s.student_id, MAX(i.available_until) AS deadline
               FROM assignment_issue_students s
               JOIN assignment_issues i ON i.id = s.issue_id
              WHERE i.assignment_id = :assignment
                AND i.available_until IS NOT NULL
              GROUP BY s.student_id",
            ['assignment' => $assignmentId]
        );

        $deadlines = [];

        foreach ($rows as $row) {
            $deadlines[(int) $row['student_id']] = (string) $row['deadline'];
        }

        return $deadlines;
    }

    /**
     * Результаты позиций по попыткам.
     *
     * Позиции отдаются в порядке редактора, а не в том, в каком их видел
     * ученик: при перемешивании порядок выдачи другой, а учителю нужен
     * порядок работы — тот, в котором задания стоят в редакторе.
     *
     * @param list<int> $attemptIds
     * @return list<array<string,mixed>>
     */
    public function journalItemResults(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $placeholders = implode(',', array_map(static fn (int $id): string => (string) $id, $attemptIds));

        return $this->db->all(
            "SELECT r.attempt_id, r.item_id, r.score, r.max_score, r.penalty, r.status,
                    r.percent, r.snapshot, r.needs_review,
                    i.position, i.weight, i.task_kind, i.plugin_id, i.task_id
               FROM assignment_item_results r
               JOIN assignment_items i ON i.id = r.item_id
              WHERE r.attempt_id IN ($placeholders)
              ORDER BY i.position"
        );
    }

    /**
     * Позиции работы в порядке редактора.
     *
     * @return list<array<string,mixed>>
     */
    public function journalWorkItems(int $assignmentId): array
    {
        return $this->db->all(
            "SELECT id, position, weight, task_kind, plugin_id, task_id
               FROM assignment_items
              WHERE assignment_id = :assignment
              ORDER BY position",
            ['assignment' => $assignmentId]
        );
    }
}
