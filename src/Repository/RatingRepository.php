<?php

declare(strict_types=1);

namespace MyEdu\Repository;

use MyEdu\Core\Database;
use MyEdu\Grading\RatingPeriod;

/**
 * Средний балл и рейтинги.
 *
 * Считается на лету из попыток, отдельной таблицы для него нет и она не нужна:
 * итог попытки — `score`, `percent`, `grade` — уже посчитан при завершении
 * работы и пересчитывается после проверки. Хранить рядом ещё и «перенос в средний
 * балл» было бы второй копией одного и того же числа, которая разошлась бы с
 * оригиналом при первой же правке шкалы.
 *
 * Что попадает в счёт, по решениям владельца:
 *
 * - **Только проверенные попытки** (`checked_at` заполнен). Пока учитель не
 *   проверил ручные позиции, попытка учтена с нулём на месте каждой такой
 *   позиции, и средний по классу был бы занижен — а потом поехал вверх сам
 *   собой. Число, которое само меняется после проверки, читалось бы как
 *   «учитель испортил оценки».
 * - **Каждая выдача — отдельный вклад.** Работа, выданная дважды с разными
 *   сроками, попадает в оба периода: за это отвечает связь через
 *   `assignment_issue_students`, а не сама работа.
 * - **Период — срок выдачи**, а не время сдачи.
 * - **Выдача «навсегда»** относится к учебному году: см. `RatingPeriod`.
 *
 * Повторная попытка той же работы — тоже отдельное выполнение и считается
 * отдельно: иначе ученик, которому разрешили несколько попыток, попал бы в
 * рейтинг с худшей или с лучшей из них по негласному правилу.
 */
final class RatingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Средние по классам.
     *
     * Класс берётся тот, что был у ученика **на момент выдачи**
     * (`assignment_issue_students.class_id`), а не текущий: переведённый после
     * выдачи ученик остаётся в своём классе по всем показателям той выдачи, и
     * иначе его работа переехала бы в чужой класс задним числом.
     *
     * @return list<array{class_id:int|null,class_name:string,class_title:string,works:int,percent:?float,grade:?float}>
     */
    public function byClass(RatingPeriod $period, int $authorId = 0): array
    {
        [$where, $params] = $this->filter($period, $authorId);

        $sql = 'SELECT ais.class_id, c.name AS class_name, c.title AS class_title,
                       COUNT(*) AS works,
                       AVG(at.percent) AS avg_percent,
                       AVG(at.grade) AS avg_grade
                ' . $this->from()
                . ' LEFT JOIN classes c ON c.id = ais.class_id
                ' . $where . '
                GROUP BY ais.class_id, c.name, c.title
                ORDER BY c.name IS NULL, c.name, c.title';

        return array_map(
            static fn (array $row): array => [
                'class_id' => $row['class_id'] === null ? null : (int) $row['class_id'],
                'class_name' => (string) $row['class_name'],
                'class_title' => (string) $row['class_title'],
                'works' => (int) $row['works'],
                'percent' => $row['avg_percent'] === null ? null : round((float) $row['avg_percent'], 1),
                'grade' => $row['avg_grade'] === null ? null : round((float) $row['avg_grade'], 2),
            ],
            $this->db->all($sql, $params)
        );
    }

    /**
     * Средние по предметам.
     *
     * @return list<array{subject_id:int|null,name:string,works:int,percent:?float,grade:?float}>
     */
    public function bySubject(RatingPeriod $period, int $authorId = 0): array
    {
        [$where, $params] = $this->filter($period, $authorId);

        $sql = 'SELECT w.subject_id, s.name, COUNT(*) AS works,
                       AVG(at.percent) AS avg_percent,
                       AVG(at.grade) AS avg_grade
                ' . $this->from()
                . ' LEFT JOIN subjects s ON s.id = w.subject_id
                ' . $where . '
                GROUP BY w.subject_id, s.name
                ORDER BY s.name IS NULL, s.name';

        return array_map(
            static fn (array $row): array => [
                'subject_id' => $row['subject_id'] === null ? null : (int) $row['subject_id'],
                'name' => (string) $row['name'],
                'works' => (int) $row['works'],
                'percent' => $row['avg_percent'] === null ? null : round((float) $row['avg_percent'], 1),
                'grade' => $row['avg_grade'] === null ? null : round((float) $row['avg_grade'], 2),
            ],
            $this->db->all($sql, $params)
        );
    }

    /**
     * Средние по ученикам одного класса.
     *
     * @return list<array{student_id:int,full_name:string,works:int,percent:?float,grade:?float}>
     */
    public function studentsOfClass(int $classId, RatingPeriod $period, int $authorId = 0): array
    {
        [$where, $params] = $this->filter($period, $authorId, 'ais.class_id = :rating_class');

        $params['rating_class'] = $classId;

        $sql = 'SELECT at.student_id, u.full_name, COUNT(*) AS works,
                       AVG(at.percent) AS avg_percent,
                       AVG(at.grade) AS avg_grade
                ' . $this->from()
                . ' JOIN users u ON u.id = at.student_id
                ' . $where . '
                GROUP BY at.student_id, u.full_name
                ORDER BY avg_percent DESC, u.full_name';

        return array_map(
            static fn (array $row): array => [
                'student_id' => (int) $row['student_id'],
                'full_name' => (string) $row['full_name'],
                'works' => (int) $row['works'],
                'percent' => $row['avg_percent'] === null ? null : round((float) $row['avg_percent'], 1),
                'grade' => $row['avg_grade'] === null ? null : round((float) $row['avg_grade'], 2),
            ],
            $this->db->all($sql, $params)
        );
    }

    /**
     * Всего за период: работ, средний балл, средняя оценка.
     *
     * @return array{works:int,percent:?float,grade:?float}
     */
    public function overall(RatingPeriod $period, int $authorId = 0): array
    {
        [$where, $params] = $this->filter($period, $authorId);

        $row = $this->db->one(
            'SELECT COUNT(*) AS works, AVG(at.percent) AS avg_percent, AVG(at.grade) AS avg_grade '
            . $this->from() . ' ' . $where,
            $params
        ) ?? [];

        return [
            'works' => (int) ($row['works'] ?? 0),
            'percent' => ($row['avg_percent'] ?? null) === null ? null : round((float) $row['avg_percent'], 1),
            'grade' => ($row['avg_grade'] ?? null) === null ? null : round((float) $row['avg_grade'], 2),
        ];
    }

/**
     * Общая часть запроса: откуда берём.
     *
     * Связь попытки с выдачей в схеме прямой нет: в `assignment_attempts` есть
     * работа и ученик, но нет номера выдачи. Приходится выводить её по времени —
     * попытка принадлежит той выдаче, под которой ученик её начал.
     *
     * Почему нельзя просто соединить по ученику и работе: тогда одна попытка
     * попала бы в каждую выдачу этой работы, и ученик, которому работу выдали
     * три раза, получил бы тройной вес. Проверено на стенде: счётчик работ уезжал
     * вместе с числом выдач, а среднее — нет, и цифры в отчёте расходились с
     * суммой по классам.
     *
     * Из подходящих выдач берётся одна — самая поздняя по началу срока. Иначе
     * повторная выдача с тем же самым сроком снова размножила бы попытку.
     *
     * Выдача «навсегда» считается открытой с момента создания: иначе попытка,
     * сданная после бессрочной выдачи, не досталась бы ни одной.
     */
    private function from(): string
    {
        return 'FROM assignment_attempts at
                JOIN (
                    SELECT at2.id AS attempt_id,
                           (
                               SELECT i3.id
                                 FROM assignment_issues i3
                                WHERE i3.assignment_id = at2.assignment_id
                                  AND at2.started_at >= COALESCE(i3.available_from, i3.created_at)
                                  AND (i3.available_until IS NULL OR at2.started_at <= i3.available_until)
                                ORDER BY COALESCE(i3.available_from, i3.created_at) DESC, i3.id DESC
                                LIMIT 1
                           ) AS issue_id
                      FROM assignment_attempts at2
                ) pair ON pair.attempt_id = at.id
                JOIN assignment_issues i ON i.id = pair.issue_id
                JOIN assignment_issue_students ais ON ais.issue_id = i.id AND ais.student_id = at.student_id
                JOIN assignments w ON w.id = at.assignment_id';
    }

    /**
     * Что считаем попыткой, попавшей в счёт.
     *
     * @param string $extra дополнительное условие, например по классу
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filter(RatingPeriod $period, int $authorId, string $extra = ''): array
    {
        [$periodSql, $params] = $period->issueSql('i');

        $sql = 'WHERE at.status = \'finished\'
                  AND at.checked_at IS NOT NULL
                  AND at.percent IS NOT NULL
                  AND ' . $periodSql;

        if ($authorId > 0) {
            $sql .= ' AND w.author_id = :rating_author';
            $params['rating_author'] = $authorId;
        }

        if ($extra !== '') {
            $sql .= ' AND ' . $extra;
        }

        return [$sql, $params];
    }
}