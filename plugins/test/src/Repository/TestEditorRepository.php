<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Repository;

use MyEdu\Core\Database;
use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeInterface;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;

/**
 * Создание и правка тестов с вопросами.
 *
 * Запись проверяется типом вопроса: тип сам решает, какие элементы нужны и
 * где в них верный ответ. Поэтому редактор может быть обычными формами, а
 * добавление нового типа вопросов не потребует ни правок этого класса, ни
 * правок шаблона.
 */
final class TestEditorRepository
{
    private Database $db;

    private QuestionTypeRegistry $registry;

    public function __construct(Database $db, QuestionTypeRegistry $registry)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    /**
     * Пустой тест.
     *
     * Создаётся сразу после «создать задание» в редакторе работы, поэтому
     * статус остаётся черновиком: тест появляется у ученика только после
     * публикации.
     */
    public function create(int $authorId, string $title, ?int $subjectId = null): int
    {
        return $this->db->insert('tests', [
            'author_id'         => $authorId,
            'title'             => $title !== '' ? $title : 'Новый тест',
            'subject_id'        => $subjectId,
            'status'            => 'draft',
            'time_limit_min'    => 0,
            'max_attempts'      => 0,
            'show_results'      => 1,
            'shuffle_questions' => 0,
            'shuffle_options'   => 0,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function updateTest(int $testId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('tests', $data, ['id' => $testId]);
    }

/**
     * Есть ли в задании вопрос, на который ученик может ответить.
     *
     * Пустой текст не считается ответом, как и вопрос с одним вариантом
     * выбора: выбрать из одного варианта нельзя, и проверять нечего.
     */
    private function hasAnswerableQuestion(int $testId): bool
    {
        $answerable = (int) $this->db->value(
            "SELECT EXISTS(
                     SELECT 1 FROM questions q
                      WHERE q.test_id = :id
                        AND TRIM(COALESCE(q.prompt, '')) <> ''
                        AND (
                              q.type IN ('numeric', 'text')
                           OR (
                                  q.type IN ('single_choice', 'multiple_choice', 'ordering', 'matching')
                              AND (SELECT COUNT(*) FROM question_options o
                                    WHERE o.question_id = q.id AND TRIM(o.text) <> '') >= 2
                               )
                            )
                    )",
            ['id' => $testId]
        );

        return $answerable === 1;
    }

    /**
     * Почему задание ещё нельзя выпускать ученикам; null — можно.
     *
     * Проверяется не только число вопросов: задание создаётся плагином сразу с
     * черновым вопросом, поэтому счётчик проходил бы и для задания, в котором
     * автор ничего не заполнил, а ученик получил бы пустой вопрос без единого
     * варианта ответа.
     *
     * Отдельной публикации задания больше нет, и вызывается это при публикации
     * работы: сообщение показывается автору работы, а не ученику.
     */
    public function unfinishedTaskReason(int $testId): ?string
    {
        $questions = (int) $this->db->value(
            'SELECT COUNT(*) FROM questions WHERE test_id = :id',
            ['id' => $testId]
        );

        if ($questions === 0) {
            return 'В задании нет вопроса';
        }

        if (!$this->hasAnswerableQuestion($testId)) {
            return 'В задании не заполнен вопрос: нужен текст и хотя бы два варианта ответа';
        }

        return null;
    }

    // ---------------------------------------------------------------- вопросы

    /**
     * Добавление вопроса.
     *
     * position считается по максимуму, а не по количеству: после удаления
     * середины новая позиция всё равно должна оказаться последней.
     *
     * @param list<array<string,mixed>> $options
     * @param array<string,mixed>       $config
     */
    public function addQuestion(
        int $testId,
        string $type,
        string $prompt,
        float $weight,
        bool $required,
        ?string $explanation,
        array $config,
        array $options
    ): int {
        $type = $this->registry->has($type) ? $type : $this->registry->keys()[0];
        $questionType = $this->registry->get($type);

        $this->validate($questionType, $prompt, $config, $options);

        return $this->insertQuestion($testId, $type, $prompt, $weight, $required, $explanation, $config, $options);
    }

    /**
     * Заготовка вопроса под конкретный тип задания.
     *
     * Проверки намеренно пропущены: заготовка по определению пустая, и
     * требовать от неё текста вопроса и верного ответа нельзя — иначе
     * задание не создалось бы вовсе и учитель увидел бы в работе позицию,
     * которой нечего проверять.
     *
     * Валидность заготовки проверяется позже, при сохранении: редактор ведёт
     * к каждому заданию и не даёт закрыть работу с незаполненными.
     *
     * @param list<array<string,mixed>> $options
     * @param array<string,mixed>       $config
     */
    public function addDraftQuestion(int $testId, string $type, array $config, array $options): int
    {
        $type = $this->registry->has($type) ? $type : $this->registry->keys()[0];

        return $this->insertQuestion($testId, $type, '', 1.0, true, null, $config, $options, true);
    }

    /**
     * @param list<array<string,mixed>> $options
     * @param array<string,mixed>       $config
     */
    private function insertQuestion(
        int $testId,
        string $type,
        string $prompt,
        float $weight,
        bool $required,
        ?string $explanation,
        array $config,
        array $options,
        bool $keepEmptyOptions = false
    ): int {
        return $this->db->transaction(function (Database $db) use (
            $testId,
            $type,
            $prompt,
            $weight,
            $required,
            $explanation,
            $config,
            $options,
            $keepEmptyOptions
        ): int {
            $position = ((int) $db->value(
                'SELECT COALESCE(MAX(position), 0) FROM questions WHERE test_id = :id',
                ['id' => $testId]
            )) + 1;

            $questionId = $db->insert('questions', [
                'test_id'     => $testId,
                'type'        => $type,
                'prompt'      => $prompt,
                'position'    => $position,
                'weight'      => $weight > 0.0 ? $weight : 1.0,
                'is_required' => $required ? 1 : 0,
                'explanation' => $explanation,
                'config'      => self::encode($config) ?? '{}',
            ]);

            $this->storeOptions($db, $questionId, $options, $keepEmptyOptions);

            return $questionId;
        });
    }

    /**
     * Правка вопроса.
     *
     * @param list<array<string,mixed>> $options
     * @param array<string,mixed>       $config
     */
    public function updateQuestion(
        int $testId,
        int $questionId,
        string $type,
        string $prompt,
        float $weight,
        bool $required,
        ?string $explanation,
        array $config,
        array $options
    ): void {
        if (!$this->registry->has($type)) {
            $type = $this->registry->keys()[0];
        }

        $questionType = $this->registry->get($type);

        $this->validate($questionType, $prompt, $config, $options);

        $this->db->transaction(function (Database $db) use (
            $testId,
            $questionId,
            $type,
            $prompt,
            $weight,
            $required,
            $explanation,
            $config,
            $options
        ): void {
            $owned = $db->value(
                'SELECT id FROM questions WHERE id = :id AND test_id = :test',
                ['id' => $questionId, 'test' => $testId]
            );

            if ($owned === null) {
                throw new InvalidAnswerException('Вопрос не найден в этом тесте');
            }

            $db->update('questions', [
                'type'        => $type,
                'prompt'      => $prompt,
                'weight'      => $weight > 0.0 ? $weight : 1.0,
                'is_required' => $required ? 1 : 0,
                'explanation' => $explanation,
                'config'      => self::encode($config) ?? '{}',
            ], ['id' => $questionId]);

            /*
             * Элементы переписываются целиком: редактор присылает весь список,
             * и частичное обновление оставило бы удалённые автором варианты
             * висеть в базе — правильный ответ мог бы ссылаться на несуществующий.
             */
            $db->run('DELETE FROM question_options WHERE question_id = :id', ['id' => $questionId]);

            $this->storeOptions($db, $questionId, $options);
        });
    }

    public function deleteQuestion(int $testId, int $questionId): bool
    {
        return $this->db->run(
            'DELETE FROM questions WHERE id = :id AND test_id = :test',
            ['id' => $questionId, 'test' => $testId]
        )->rowCount() > 0;
    }

    /**
     * Перестановка вопроса внутри теста.
     *
     * Порядок вопросов — часть содержания задания, поэтому он меняется так же,
     * как порядок позиций работы.
     */
    public function moveQuestion(int $testId, int $questionId, int $newPosition): bool
    {
        return $this->db->transaction(function (Database $db) use ($testId, $questionId, $newPosition): bool {
            $row = $db->one(
                'SELECT id, position FROM questions WHERE id = :id AND test_id = :test',
                ['id' => $questionId, 'test' => $testId]
            );

            if ($row === null) {
                return false;
            }

            $max = (int) $db->value(
                'SELECT COALESCE(MAX(position), 0) FROM questions WHERE test_id = :test',
                ['test' => $testId]
            );

            $old = (int) $row['position'];
            $new = max(1, min($newPosition, max(1, $max)));

            if ($old === $new) {
                return true;
            }

            $db->run('UPDATE questions SET position = 0 WHERE id = :id', ['id' => $questionId]);

            /*
             * Вопрос, стоявший на новом месте, освобождает его: при ходе вниз
             * сдвигаются позиции строго после старой и до новой включительно.
             * Без включительной границы на новом месте оказались бы сразу два
             * вопроса с одинаковым номером, и порядок стал бы случайным.
             */
            if ($old < $new) {
                $db->run(
                    'UPDATE questions SET position = position - 1
                      WHERE test_id = :test AND position > :old AND position <= :new',
                    ['test' => $testId, 'old' => $old, 'new' => $new]
                );
            } else {
                $db->run(
                    'UPDATE questions SET position = position + 1
                      WHERE test_id = :test AND position >= :new AND position < :old',
                    ['test' => $testId, 'old' => $old, 'new' => $new]
                );
            }

            $db->run(
                'UPDATE questions SET position = :new WHERE id = :id',
                ['new' => $new, 'id' => $questionId]
            );

            return true;
        });
    }

    // ---------------------------------------------------------------- служебное

    /**
     * @param array<string,mixed>       $config
     * @param list<array<string,mixed>> $options
     */
    private function validate(
        QuestionTypeInterface $type,
        string $prompt,
        array $config,
        array $options
    ): void {
        if (trim($prompt) === '') {
            throw new InvalidAnswerException('Введите текст задания');
        }

        $type->validateConfig($config, $options);
    }

    /**
     * @param bool $keepEmpty оставить строки с пустым текстом.
     *
     * Обычно пустые строки выбрасываются: форма присылает столько полей,
     * сколько нарисовал автор, и незаполненные хвосты в базу попадать не
     * должны. Для заготовки задания наоборот — пустые строки нужны: это те
     * поля, которые учитель будет заполнять, и без них редактор показывает
     * пустоту вместо формы.
     *
     * @param list<array<string,mixed>> $options
     */
    private function storeOptions(Database $db, int $questionId, array $options, bool $keepEmpty = false): void
    {
        $position = 0;

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $text = trim((string) ($option['text'] ?? ''));

            if ($text === '' && !$keepEmpty) {
                continue;
            }

            $kind = (string) ($option['kind'] ?? Question::KIND_OPTION);
            $position++;

            $db->insert('question_options', [
                'question_id' => $questionId,
                'kind'        => $kind,
                'value_key'   => (string) ($option['value_key'] ?? 'v' . $position),
                'text'        => $text,
                'position'    => $position,
                'pair_key'    => $option['pair_key'] ?? null,
                'always_show' => (int) ($option['always_show'] ?? 0) === 1 ? 1 : 0,
            ]);
        }
    }

    private static function encode(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
