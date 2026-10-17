<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Учебные классы: список, создание, предметы и учителя.
 *
 * В базе хранится только год начала — 2026. Российский учебный год идёт
 * с 1 сентября по 31 мая следующего календарного года, поэтому показывать
 * «2026» вредно: это читается как календарный год. Видимая строка
 * «2026–2027» собирается методом academicYear().
 */
final class ClassRepository
{
    /** Длина названия класса ограничена колонкой. */
    public const NAME_MAX = 32;
    public const TITLE_MAX = 40;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Учебный год в виде «2026-2027».
     *
     * Разделитель — дефис без пробелов: так его пишут в журналах и справках,
     * и в таком виде он не путается с диапазоном номеров классов.
     * Пустой год показывается пустой строкой, а не «-».
     */
    public static function academicYear(mixed $startYear): string
    {
        if ($startYear === null || $startYear === '') {
            return '';
        }

        $start = (int) $startYear;

        if ($start < 1900 || $start > 2999) {
            return '';
        }

        return $start . '-' . ($start + 1);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM classes WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->db->one('SELECT * FROM classes WHERE name = :name', ['name' => $name]);
    }

    /**
     * Список классов со счётчиками: сколько учеников, предметов и учителей.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->db->all(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) AS student_count,
                    (SELECT COUNT(*) FROM class_subjects sub WHERE sub.class_id = c.id) AS subject_count,
                    (SELECT COUNT(*) FROM class_teachers t WHERE t.class_id = c.id) AS teacher_count
               FROM classes c
           ORDER BY c.name'
        );
    }

    /** @param array{name:string,title:?string,year:?int} $data */
    public function create(array $data): int
    {
        return $this->db->insert('classes', [
            'name'       => $data['name'],
            'title'      => $data['title'],
            'year'       => $data['year'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('classes', $data, ['id' => $id]);
    }

    // ---------------------------------------------------------------- учителя

    /** @return list<int> */
    public function teacherIds(int $classId): array
    {
        $rows = $this->db->all(
            'SELECT user_id FROM class_teachers WHERE class_id = :id ORDER BY user_id',
            ['id' => $classId]
        );

        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /** @param list<int> $userIds */
    public function setTeachers(int $classId, array $userIds): void
    {
        $this->db->transaction(function (Database $db) use ($classId, $userIds): void {
            $db->run('DELETE FROM class_teachers WHERE class_id = :id', ['id' => $classId]);

            foreach (array_unique($userIds) as $userId) {
                $db->run(
                    'INSERT IGNORE INTO class_teachers (class_id, user_id, assigned_at)
                     VALUES (:class, :user, NOW())',
                    ['class' => $classId, 'user' => $userId]
                );
            }
        });
    }

/**
     * Состав класса перед удалением.
     *
     * Считается заранее, потому что каскад снесёт класс вместе со связями, и
     * после этого спросить уже некого. На странице подтверждения это же
     * значение показывается человеку — он должен видеть, сколько учеников
     * пропадёт, до того как нажал «удалить».
     *
     * @return array{students:int,subjects:int,teachers:int,attempts:int}
     */
    public function removalSummary(int $classId): array
    {
        /*
         * Имена параметров разные, хотя значение одно: повтор одного
         * именованного параметра PDO принимает не всегда, и запрос падал с
         * «Invalid parameter number».
         */
        $row = $this->db->one(
            'SELECT
                (SELECT COUNT(*) FROM class_students WHERE class_id = :c1) AS students,
                (SELECT COUNT(*) FROM class_subjects  WHERE class_id = :c2) AS subjects,
                (SELECT COUNT(*) FROM class_teachers  WHERE class_id = :c3) AS teachers,
                (SELECT COUNT(*) FROM assignment_attempts t
                   WHERE t.student_id IN (SELECT student_id FROM class_students WHERE class_id = :c4)
                 ) AS attempts',
            ['c1' => $classId, 'c2' => $classId, 'c3' => $classId, 'c4' => $classId]
        );

        return [
            'students' => (int) ($row['students'] ?? 0),
            'subjects' => (int) ($row['subjects'] ?? 0),
            'teachers' => (int) ($row['teachers'] ?? 0),
            'attempts' => (int) ($row['attempts'] ?? 0),
        ];
    }

    /**
     * Удаление класса вместе с его учениками.
     *
     * Снос класса уносит его учеников, и оставить их было бы хуже, чем
     * удалить: в списке пользователей их нет, в списке классов — тоже, а в
     * базе они висели бы без единого адреса, куда их можно посмотреть.
     * Владелец класса предупреждается об этом до удаления.
     *
     * Ученик, который состоит ещё и в другом классе, не удаляется, а просто
     * открепляется: он виден и нужен там. Модель допускает такое членство
     * (заведённого ученика можно добавить в другой класс), поэтому считать,
     * что класс всегда один, нельзя.
     *
     * Попытки удаляются явно: внешнего ключа на student_id в попытках нет,
     * иначе они остались бы висеть на несуществующем ученике.
     *
     * @return array{deletedStudents:int,keptStudents:int}
     */
    public function deleteWithStudents(int $classId): array
    {
        return $this->db->transaction(function (Database $db) use ($classId): array {
            $members = array_map(
                static fn (array $r): int => (int) $r['student_id'],
                $db->all('SELECT student_id FROM class_students WHERE class_id = :c', ['c' => $classId])
            );

            $deleted = 0;
            $kept = 0;

            if ($members !== []) {
                $list = implode(',', $members);

                // Попытки снимаем со всех классов сразу: ученик мог сдавать
                // работы, выданные не только этому классу.
                $db->run("DELETE FROM assignment_attempts WHERE student_id IN ({$list})");

                $others = array_map(
                    static fn (array $r): int => (int) $r['student_id'],
                    $db->all("SELECT DISTINCT student_id FROM class_students WHERE class_id <> :c", ['c' => $classId])
                );

                $onlyHere = array_values(array_diff($members, $others));

                if ($onlyHere !== []) {
                    $deletedList = implode(',', $onlyHere);

                    $db->run("DELETE FROM users WHERE id IN ({$deletedList})");
                    $deleted = count($onlyHere);
                }

                $kept = count($members) - $deleted;
            }

            // Связи с предметами, учителями и выдачей работ уходят каскадом.
            $db->run('DELETE FROM classes WHERE id = :c', ['c' => $classId]);

            return ['deletedStudents' => $deleted, 'keptStudents' => $kept];
        });
    }
}
