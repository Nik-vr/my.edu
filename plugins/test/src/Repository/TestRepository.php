<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Repository;

use MyEdu\Core\Database;
use MyEduPlugin\Test\Question\Question;

/**
 * Доступ к тестам, вопросам и критериям оценивания.
 */
final class TestRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT t.*, s.name AS subject_name, u.full_name AS author_name
               FROM tests t
          LEFT JOIN subjects s ON s.id = t.subject_id
          LEFT JOIN users u    ON u.id = t.author_id
              WHERE t.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Тест вместе со счётчиком вопросов и их суммарным весом — одним запросом.
     *
     * Эти три величины нужны ядру на каждой позиции работы: и список заданий,
     * и результат, и разбор. Считать их тремя отдельными выборками значило
     * утроить число запросов на страницу: на результате работы с шестью
     * заданиями это 18 обращений вместо шести — при том, что данные заведомо
     * одни и те же для всех страниц про задание.
     *
     * Считается подзапросами, а не соединением с группировкой: у вопроса может
     * не быть ни одного варианта ответа, и JOIN по questions проглотил бы
     * задание без вопросов.
     *
     * @return array<string,mixed>|null
     */
    public function findWithData(int $id): ?array
    {
        return $this->db->one(
            'SELECT t.*, s.name AS subject_name, u.full_name AS author_name,
                    (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) AS question_count,
                    (SELECT COALESCE(SUM(q.weight), 0) FROM questions q WHERE q.test_id = t.id) AS max_score
               FROM tests t
          LEFT JOIN subjects s ON s.id = t.subject_id
          LEFT JOIN users u    ON u.id = t.author_id
              WHERE t.id = :id',
            ['id' => $id]
        );
    }

/**
     * Данные сразу нескольких тестов — те же поля, что у findWithData().
     *
     * Повторяет его условие и его подзапросы один в один. Расхождение между
     * двумя методами было бы хуже самого запроса: страница показала бы одну
     * цифру, а плагин при сохранении опирался бы на другую.
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>> ключ — номер теста, без пропусков
     */
    public function findManyWithData(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $rows = $this->db->all(
            "SELECT t.*, s.name AS subject_name, u.full_name AS author_name,
                    (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) AS question_count,
                    (SELECT COALESCE(SUM(q.weight), 0) FROM questions q WHERE q.test_id = t.id) AS max_score
               FROM tests t
          LEFT JOIN subjects s ON s.id = t.subject_id
          LEFT JOIN users u    ON u.id = t.author_id
              WHERE t.id IN ($placeholders)",
            $ids
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * Удаление теста вместе с вопросами.
     *
     * Попытки самостоятельных тестов, которые эта таблица чистила прежде,
     * сняты миграцией 013: попытка работы живёт в assignment_attempts,
     * прохождение задания — в таблицах плагина, и удаляет их владелец данных.
     */
    public function delete(int $testId): void
    {
        $this->db->transaction(function (Database $db) use ($testId): void {
            $db->run('DELETE FROM question_options WHERE question_id IN (SELECT id FROM questions WHERE test_id = :id)', ['id' => $testId]);
            $db->run('DELETE FROM questions WHERE test_id = :id', ['id' => $testId]);
            $db->run('DELETE FROM tests WHERE id = :id', ['id' => $testId]);
        });
    }

    /**
     * Вопросы теста с элементами, собранные в объекты Question.
     *
     * @return list<Question>
     */
    public function questions(int $testId): array
    {
        $rows = $this->db->all(
            'SELECT id, type, prompt, position, weight, is_required, explanation, config
               FROM questions
              WHERE test_id = :id
           ORDER BY position, id',
            ['id' => $testId]
        );

        if ($rows === []) {
            return [];
        }

        $optionsByQuestion = $this->optionsForQuestions(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows
        ));

        $questions = [];
        foreach ($rows as $row) {
            $questions[] = Question::fromRow($row, $optionsByQuestion[(int) $row['id']] ?? []);
        }

        return $questions;
    }

    /**
     * @param list<int> $questionIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function optionsForQuestions(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

        $rows = $this->db->all(
            "SELECT question_id, kind, value_key, text, position, pair_key, always_show
               FROM question_options
              WHERE question_id IN ($placeholders)
           ORDER BY question_id, position, id",
            $questionIds
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['question_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Суммарный максимальный балл теста.
     */
    public function maxScore(int $testId): float
    {
        $value = $this->db->value(
            'SELECT COALESCE(SUM(weight), 0) FROM questions WHERE test_id = :id',
            ['id' => $testId]
        );

        return (float) $value;
    }

    /** Сколько вопросов в тесте. */
    public function countQuestions(int $testId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM questions WHERE test_id = :id',
            ['id' => $testId]
        );
    }
}