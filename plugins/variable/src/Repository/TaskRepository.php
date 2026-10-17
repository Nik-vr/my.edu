<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable\Repository;

use MyEdu\Core\Database;

/**
 * Задания вариативной задачи.
 *
 * Ядро знает только номер задания в позиции работы и больше ничего, поэтому
 * всё, из чего задание собирается на экране учителя и на экране ученика, лежит
 * здесь.
 */
final class TaskRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return int номер созданного задания
     */
    public function create(int $authorId, string $title, ?int $subjectId, string $prompt = ''): int
    {
        return $this->db->insert('variable_tasks', [
            'author_id'  => $authorId,
            'subject_id' => $subjectId,
            'title'      => $title !== '' ? $title : 'Вариативная задача',
            'prompt'     => $prompt,
            'config'     => json_encode([], JSON_UNESCAPED_UNICODE),
            'status'     => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM variable_tasks WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Несколько заданий одним запросом.
     *
     * Ядро обходит позиции работы и по каждой спрашивает данные. По одному
     * обращению на позицию страница с двадцатью заданиями делала двадцать
     * одинаковых по форме запросов к одним и тем же строкам.
     *
     * @param list<int> $ids
     *
     * @return array<int,array<string,mixed>> ключ — номер задания
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];

        foreach ($ids as $position => $_) {
            $placeholders[] = ':id' . $position;
        }

        $params = [];

        foreach ($ids as $position => $id) {
            $params['id' . $position] = $id;
        }

        $rows = $this->db->all(
            'SELECT * FROM variable_tasks WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = $this->decode($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        if (array_key_exists('config', $data)) {
            $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('variable_tasks', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM variable_tasks WHERE id = :id', ['id' => $id]);
    }

    /**
     * Сколько позиций работы ссылается на задание.
     *
     * Задание может лежать в нескольких работах, и удалять его из одной нельзя:
     * чужая работа осталась бы с заданием, которого больше нет.
     */
    public function references(int $id): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM assignment_items WHERE plugin_id = 'variable' AND task_id = :task",
            ['task' => $id]
        );
    }

    /**
     * Строка задания с разобранной настройкой.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['author_id'] = (int) $row['author_id'];
        $row['subject_id'] = $row['subject_id'] === null ? null : (int) $row['subject_id'];

        $config = json_decode((string) ($row['config'] ?? ''), true);
        $row['config'] = is_array($config) ? $config : [];

        return $row;
    }
}