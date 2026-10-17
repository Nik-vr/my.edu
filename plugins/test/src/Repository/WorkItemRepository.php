<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Repository;

use MyEdu\Core\Database;
use MyEduPlugin\Test\Grading\GradingResult;
use MyEduPlugin\Test\Question\Question;

/**
 * Прохождение задания-теста внутри работы.
 *
 * У работы своя попытка со своим токеном, свои сроки и свой итог, поэтому
 * прохождение отдельного задания плагин держит у себя, а не в общей таблице
 * попыток. Таблицы attempts и answers, где раньше жили самостоятельные тесты,
 * сняты миграцией 013 — смешивать две несовместимые сущности в одной таблице
   * было ровно то, чего здесь избегают.
 *
 * Ссылок на таблицы ядра здесь нет: плагин не должен ни мешать удалению
 * работы, ни ломаться от её перестройки.
 */
final class WorkItemRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Прохождение задания внутри попытки работы.
     *
     * Создаётся при первом открытии и дальше переиспользуется: ученик может
     * возвращаться к заданию сколько угодно раз, и ответы должны накапливаться,
     * а не начинаться заново.
     *
     * @return array<string,mixed>|null
     */
    public function open(int $assignmentAttemptId, int $itemId, int $testId): ?array
    {
        $existing = $this->find($assignmentAttemptId, $itemId);

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->db->insert('test_item_attempts', [
            'assignment_attempt_id' => $assignmentAttemptId,
            'item_id'               => $itemId,
            'test_id'               => $testId,
            'status'                => 'in_progress',
            'started_at'            => date('Y-m-d H:i:s'),
        ]);

        return $this->findById($id);
    }

    /** @return array<string,mixed>|null */
    public function find(int $assignmentAttemptId, int $itemId): ?array
    {
        return $this->db->one(
            'SELECT * FROM test_item_attempts
              WHERE assignment_attempt_id = :attempt AND item_id = :item',
            ['attempt' => $assignmentAttemptId, 'item' => $itemId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM test_item_attempts WHERE id = :id', ['id' => $id]);
    }

    /**
     * Прохождения всех позиций попытки работы.
     *
     * @return array<int,array<string,mixed>> ключ — item_id
     */
    public function allForAttempt(int $assignmentAttemptId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM test_item_attempts WHERE assignment_attempt_id = :attempt',
            ['attempt' => $assignmentAttemptId]
        );

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row['item_id']] = $row;
        }

        return $byItem;
    }

    /**
     * Ответ как черновик: сохраняется при каждом переходе между заданиями.
     *
     * Пустой ответ удаляется, а не сохраняется пустой строкой: список
     * заданий должен показывать незаполненное задание как незаполненное.
     *
     * @param mixed $raw
     */
    public function saveDraft(int $itemAttemptId, int $questionId, mixed $raw): void
    {
        if ($raw === null || $raw === '' || $raw === []
            || (is_string($raw) && trim($raw) === '')
        ) {
            $this->db->run(
                'DELETE FROM test_item_answers WHERE item_attempt_id = :attempt AND question_id = :question',
                ['attempt' => $itemAttemptId, 'question' => $questionId]
            );

            return;
        }

        $this->db->upsert('test_item_answers', [
            'item_attempt_id' => $itemAttemptId,
            'question_id'     => $questionId,
            'answer_text'     => is_string($raw) ? trim($raw) : null,
            'answer_data'     => self::encode($raw),
            'score_awarded'   => 0,
            'is_correct'      => null,
            'detail'          => null,
        ], ['item_attempt_id', 'question_id'], ['answer_text', 'answer_data', 'score_awarded', 'is_correct', 'detail']);
    }

    /**
     * Ответы, набранные к текущему моменту.
     *
     * @return array<int,mixed> ключ — id вопроса
     */
    public function draftAnswers(int $itemAttemptId): array
    {
        $rows = $this->db->all(
            'SELECT question_id, answer_data FROM test_item_answers
              WHERE item_attempt_id = :attempt
           ORDER BY question_id',
            ['attempt' => $itemAttemptId]
        );

        $answers = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['answer_data'], true);

            if ($decoded !== null || $row['answer_data'] === 'null') {
                $answers[(int) $row['question_id']] = $decoded;
            }
        }

        return $answers;
    }

    /**
     * Какие задания ученик уже ответил — для отметок в списке.
     *
     * @return array<int,bool>
     */
    public function answeredMap(int $itemAttemptId): array
    {
        $rows = $this->db->all(
            "SELECT question_id FROM test_item_answers
              WHERE item_attempt_id = :attempt
                AND (answer_data IS NOT NULL AND answer_data <> :empty)",
            ['attempt' => $itemAttemptId, 'empty' => '""']
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['question_id']] = true;
        }

        return $map;
    }

    /**
     * Проверенные ответы с их оценкой.
     *
     * @return list<array<string,mixed>>
     */
    public function checkedAnswers(int $itemAttemptId): array
    {
        return $this->db->all(
            'SELECT * FROM test_item_answers
              WHERE item_attempt_id = :attempt AND is_correct IS NOT NULL
           ORDER BY question_id',
            ['attempt' => $itemAttemptId]
        );
    }

    /**
     * Ответ ученика одним куском — для показа учителю при ручной проверке.
     *
     * Возвращается всё, что ученик написал или отметил, независимо от того,
     * проверено оно или нет: проверять нужно видя ответ.
     *
     * @return list<array<string,mixed>>
     */
    public function submittedAnswers(int $itemAttemptId): array
    {
        return $this->db->all(
            'SELECT * FROM test_item_answers WHERE item_attempt_id = :attempt ORDER BY question_id',
            ['attempt' => $itemAttemptId]
        );
    }

    /**
     * Сохранение проверки.
     *
     * Всё в одной транзакции: ответы и итог прохождения либо сохранены, либо
     * ничего. Иначе ученик увидел бы «проверено», а разбор — пустым.
     */
    public function saveGrading(int $itemAttemptId, GradingResult $result): void
    {
        $this->db->transaction(function (Database $db) use ($itemAttemptId, $result): void {
            $db->update('test_item_attempts', [
                'status'      => 'graded',
                'finished_at' => date('Y-m-d H:i:s'),
                'score'       => $result->score,
                'max_score'   => $result->maxScore,
            ], ['id' => $itemAttemptId]);

            foreach ($result->items as $item) {
                $this->storeAnswer($itemAttemptId, $item);
            }
        });
    }

    /**
     * @param array<string,mixed> $item
     */
    private function storeAnswer(int $itemAttemptId, array $item): void
    {
        $this->db->upsert('test_item_answers', [
            'item_attempt_id' => $itemAttemptId,
            'question_id'     => (int) $item['question_id'],
            'answer_text'     => (string) ($item['answer_text'] ?? ''),
            'answer_data'     => self::encode($item['answer_data'] ?? null),
            'score_awarded'   => (float) ($item['score'] ?? 0.0),
            'is_correct'      => ($item['is_correct'] ?? false) ? 1 : 0,
            'detail'          => self::encode($item['detail'] ?? []),
        ], ['item_attempt_id', 'question_id'], [
            'answer_text',
            'answer_data',
            'score_awarded',
            'is_correct',
            'detail',
        ]);
    }

    /**
     * Вопросы задания, к которым в этом прохождении есть ответы.
     *
     * @param array<int,mixed> $answers
     * @return list<Question>
     */
    public function questionsWithAnswers(int $testId, array $answers): array
    {
        $questions = [];

        foreach ($this->db->all(
            'SELECT id, type, prompt, position, weight, is_required, explanation, config
               FROM questions WHERE test_id = :id ORDER BY position, id',
            ['id' => $testId]
        ) as $row) {
            $id = (int) $row['id'];

            if (!array_key_exists($id, $answers)) {
                continue;
            }

            $questions[] = Question::fromRow($row, []);
        }

        return $questions;
    }

    /**
     * Удаление прохождений позиции.
     *
     * Вызывается ядром при удалении позиции из работы, чтобы прохождения
     * удалённого задания не остались в базе плагина.
     *
     * Ответы удаляются каскадом: test_item_answers ссылается на
     * test_item_attempts внешним ключом, поэтому достаточно убрать сами
     * прохождения.
     */
    public function deleteForItem(int $itemId): void
    {
        $this->db->run(
            'DELETE FROM test_item_attempts WHERE item_id = :item',
            ['item' => $itemId]
        );
    }

    /**
     * Удаление всех прохождений задания.
     *
     * Нужно перед удалением самого теста. Номера хранятся числами, а не
     * внешними ключами: снятое задание освобождает свой номер, и без этой
     * очистки следующая работа на том же номере показала бы ученику чужие
     * ответы — или даже чужой разбор.
     */
    public function deleteForTask(int $testId): void
    {
        $this->db->run(
            'DELETE FROM test_item_attempts WHERE test_id = :test',
            ['test' => $testId]
        );
    }

    private static function encode(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
