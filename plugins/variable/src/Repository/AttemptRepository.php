<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable\Repository;

use MyEdu\Core\Database;

/**
 * Прохождение вариативной задания внутри работы.
 *
 * Ядро держит попытку по всей работе, плагину нужна своя на каждое задание:
 * только у него есть выпавший пример и ответ ученика именно к нему.
 *
 * Ответ хранится одним JSON: рядом с числами ученика лежат те значения
 * переменных, которые он видел. Это единственный источник правды при проверке —
 * см. пояснение в миграции.
 */
final class AttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Открыть прохождение, если его ещё нет.
     *
     * Повторный вызов возвращает то же прохождение: ученик возвращается к
     * заданию и перезагружает страницу, и каждый раз заводить новую запись
     * означало бы терять ответы.
     *
     * @return array<string,mixed>|null
     */
    public function open(int $assignmentAttemptId, int $itemId, int $taskId): ?array
    {
        $existing = $this->find($assignmentAttemptId, $itemId);

        if ($existing !== null) {
            return $existing;
        }

        $this->db->run(
            "INSERT INTO variable_item_attempts (assignment_attempt_id, item_id, task_id, status, started_at)
             VALUES (:a, :i, :t, 'in_progress', NOW())
             ON DUPLICATE KEY UPDATE id = id",
            ['a' => $assignmentAttemptId, 'i' => $itemId, 't' => $taskId]
        );

        return $this->find($assignmentAttemptId, $itemId);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $assignmentAttemptId, int $itemId): ?array
    {
        return $this->db->one(
            'SELECT * FROM variable_item_attempts
              WHERE assignment_attempt_id = :a AND item_id = :i',
            ['a' => $assignmentAttemptId, 'i' => $itemId]
        );
    }

    /**
     * Ответ по прохождению.
     *
     * @return array<string,mixed>|null
     */
    public function answer(int $itemAttemptId): ?array
    {
        return $this->db->one(
            'SELECT * FROM variable_item_answers WHERE item_attempt_id = :a',
            ['a' => $itemAttemptId]
        );
    }

    /**
     * @param array{answers:array<string,float|null>,values:array<string,float>} $answer
     */
    public function saveAnswer(int $itemAttemptId, array $answer): void
    {
        $this->db->upsert(
            'variable_item_answers',
            [
                'item_attempt_id' => $itemAttemptId,
                'answer_data'     => json_encode($answer, JSON_UNESCAPED_UNICODE),
                'score_awarded'   => 0,
                'is_correct'      => null,
            ],
            ['item_attempt_id'],
            ['answer_data', 'score_awarded', 'is_correct']
        );
    }

    /**
     * Записать результат проверки.
     *
     * @param array<string,mixed> $snapshot
     */
    public function saveGrade(int $itemAttemptId, float $score, float $max, bool $correct, array $snapshot): void
    {
        $this->db->upsert(
            'variable_item_answers',
            [
                'item_attempt_id' => $itemAttemptId,
                'answer_data'     => null,
                'score_awarded'   => $score,
                'is_correct'      => $correct ? 1 : 0,
                'detail'          => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ],
            ['item_attempt_id'],
            ['score_awarded', 'is_correct', 'detail']
        );

        $this->db->update(
            'variable_item_attempts',
            [
                'status'      => 'graded',
                'finished_at' => date('Y-m-d H:i:s'),
                'score'       => $score,
                'max_score'   => $max,
            ],
            ['id' => $itemAttemptId]
        );
    }

    /**
     * Позиция работы снята — прохождение по ней больше не нужно.
     *
     * Ответы хранятся номерами позиции, а не внешним ключом: снятая позиция
     * освобождает номер, и без удаления следующая работа на том же номере
     * показала бы ученику чужие ответы.
     */
    public function deleteForItem(int $itemId): void
    {
        $this->db->run(
            'DELETE FROM variable_item_attempts WHERE item_id = :i',
            ['i' => $itemId]
        );
    }

    public function deleteForTask(int $taskId): void
    {
        $this->db->run(
            'DELETE FROM variable_item_attempts WHERE task_id = :t',
            ['t' => $taskId]
        );
    }

    /**
     * Удаление прохождений попытки работы.
     *
     * Таблица не ссылается на попытку ядра внешним ключом, и при удалении
     * попытки (тестовое прохождение) строки обязан убрать сам плагин.
     */
    public function deleteForAttempt(int $assignmentAttemptId): void
    {
        $this->db->run(
            'DELETE FROM variable_item_attempts WHERE assignment_attempt_id = :attempt',
            ['attempt' => $assignmentAttemptId]
        );
    }
}