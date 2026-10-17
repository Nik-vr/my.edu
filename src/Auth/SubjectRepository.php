<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Каталог учебных предметов.
 *
 * Отдельный класс, а не часть ClassRepository: предметы перестали быть делом
 * класса. Ими пользуются составление работ и журнал, а класс лишь выбирает из
 * списка. Пока предмет создавался как побочный эффект правки класса, каталог
 * нельзя было посмотреть целиком — предмет появлялся только вместе с классом.
 */
final class SubjectRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Все предметы с числом классов и работ.
     *
     * Числа нужны, чтобы понимать, чем обернутся правки: переименование
     * предмета меняет его в работах и в расписании, а «предметов: 0» снимает
     * вопрос «а не сломаю ли я что-то».
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->all(
            "SELECT s.id, s.name, s.is_active,
                    (SELECT COUNT(*) FROM class_subjects cs WHERE cs.subject_id = s.id) AS class_count,
                    (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = s.id) AS work_count
               FROM subjects s
           ORDER BY s.name"
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): array|null
    {
        return $this->db->one('SELECT * FROM subjects WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{name:string} $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('subjects', [
            'name'       => $data['name'],
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Переименование.
     *
     * Код не трогается: он уже попал в ссылки и отчёты, и менять его вместе с
     * названием значило бы ломать то, что было сделано раньше. Если нужен
     * другой код — это другой предмет.
     *
     * Колонки updated_at у предметов нет, и она здесь не выдумывается:
     * список меняют раз в год, а лишняя миграция ради одной даты — это
     * миграция, которую придётся тащить на каждой новой базе.
     */
    public function rename(int $id, string $name): void
    {
        $this->db->update('subjects', ['name' => $name], ['id' => $id]);
    }

    /**
     * Включение и выключение.
     *
     * Выключенный предмет не удаляется: на него ссылаются прошлые работы.
     * Он просто исчезает из списков, из которых его выбирают сейчас, и
     * остаётся в старых работах и в журнале.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->db->run(
            'UPDATE subjects SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $id]
        );
    }

    /**
     * Сколько раз предмет встречается в классах и в работах.
     *
     * Считается здесь, а не в контроллере: решение «можно ли удалять» это
     * свойство предмета, и правило должно быть одно и то же, откуда бы его ни
     * спросили.
     *
     * Работы считаются отдельно от классов: у них нет внешнего ключа на
     * subjects, и проверка на каскад прошла бы, а работа осталась бы без
     * предмета.
     *
     * @return array{classes:int,works:int}
     */
    public function usage(int $id): array
    {
        return [
            'classes' => (int) $this->db->value(
                'SELECT COUNT(*) FROM class_subjects WHERE subject_id = :id',
                ['id' => $id]
            ),
            'works'   => (int) $this->db->value(
                'SELECT COUNT(*) FROM assignments WHERE subject_id = :id',
                ['id' => $id]
            ),
        ];
    }

    /**
     * Удаление предмета.
     *
     * Связи убираются явно, а не каскадом: на предмет ссылаются работы, у
     * которых внешнего ключа нет, и молчаливая потеря привязки была бы хуже
     * самого удаления. Тесты и классы предмета чистятся своими каскадами, но
     * полагаться на них здесь нельзя — правило удаления должно быть видно целиком.
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id): void {
            $db->run('DELETE FROM class_subjects WHERE subject_id = :id', ['id' => $id]);
            $db->run('DELETE FROM tests WHERE subject_id = :id', ['id' => $id]);
            $db->run('DELETE FROM subjects WHERE id = :id', ['id' => $id]);
        });
    }

    // ------------------------------------------------------- связь с классами

    /** @return list<int> */
    public function subjectIds(int $classId): array
    {
        $rows = $this->db->all(
            'SELECT subject_id FROM class_subjects WHERE class_id = :id ORDER BY subject_id',
            ['id' => $classId]
        );

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }

    /** @param list<int> $subjectIds */
    public function setSubjects(int $classId, array $subjectIds): void
    {
        $this->db->transaction(function (Database $db) use ($classId, $subjectIds): void {
            $db->run('DELETE FROM class_subjects WHERE class_id = :id', ['id' => $classId]);

            foreach (array_unique($subjectIds) as $subjectId) {
                $db->run(
                    'INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (:class, :subject)',
                    ['class' => $classId, 'subject' => $subjectId]
                );
            }
        });
    }

    /**
     * Активные предметы с отметкой, изучается ли он в этом классе.
     *
     * Выключенные не показываются: их нельзя выбрать заново, но в работах они
     * остаются.
     *
     * @return list<array<string,mixed>>
     */
    public function subjectsForClass(int $classId): array
    {
        return $this->db->all(
            'SELECT s.id, s.name,
                    (cs.subject_id IS NOT NULL) AS attached
               FROM subjects s
          LEFT JOIN class_subjects cs ON cs.subject_id = s.id AND cs.class_id = :id
              WHERE s.is_active = 1
           ORDER BY s.name',
            ['id' => $classId]
        );
    }
}
