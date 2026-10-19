<?php

declare(strict_types=1);

namespace MyEdu\Repository;

use MyEdu\Core\Database;

/**
 * Черновики правки позиции работы.
 *
 * Что хранится: значения полей, которые учитель набрал, но не сохранил.
 * Раньше они жили в sessionStorage и умирали вместе со вкладкой — работа на
 * пять минут могла уйти из-за того, что окно закрылось или завис браузер.
 *
 * Кому принадлежит черновик: автору, а не позиции. У одного задания может
 * быть несколько редакторов, и у каждого своя недописанная правка; сменился
 * автор — сменился и список его черновиков. Поэтому ключ здесь «автор + позиция»,
 * и на чужой черновик этот класс не смотрит вовсе.
 *
 * Чего класс не делает: не публикует. Строка лежит отдельно от задания, ученик
 * её не видит, и «Сохранить» остаётся единственным способом опубликовать.
 */
final class AssignmentDraftRepository
{
    /**
     * Больше, чем нужно для правки одного задания.
     *
     * Ограничение не формальное: без него правка позиции могла бы расти
     * бесконечно (визуальный редактор в описании задания легко набирает
     * сотни килобайт), и черновики незаметно съедали бы место в базе.
     */
    private const MAX_FIELDS_BYTES = 512 * 1024;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Черновик автора по позиции.
     *
     * `fields` приходит строкой JSON и разбирается наверху: репозиторию нечего
     * знать про форму, а разбор JSON на отказе должен говорить с человеком, а
     * не молча отдавать пустые значения.
     *
     * @return array{fields:array<string,mixed>,savedAt:string}|null
     */
    public function find(int $authorId, int $itemId): ?array
    {
        $row = $this->db->one(
            'SELECT fields, updated_at FROM assignment_drafts
              WHERE author_id = :author AND item_id = :item
              LIMIT 1',
            ['author' => $authorId, 'item' => $itemId]
        );

        if (!is_array($row)) {
            return null;
        }

        $fields = json_decode((string) $row['fields'], true);

        if (!is_array($fields)) {
            /*
             * Битая строка не должна ломать открытие задания.
             *
             * Черновик — подстраховка, а не часть работы: если он нечитаем,
             * правильный поступок не «открыть пустую форму и молчать», а
             * убрать мусор и сказать об этом.
             */
            $this->drop($authorId, $itemId);

            return null;
        }

        return [
            'fields' => $fields,
            'savedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * Положить черновик или обновить уже существующий.
     *
     * Ключ уникален по паре «автор + позиция», поэтому повторная запись того же
     * черновика обновляет его, а не плодит копии: автосохранение срабатывает
     * на каждое движение после паузы, и копии разошлись бы очень быстро.
     */
    public function save(int $authorId, int $assignmentId, int $itemId, array $fields): void
    {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        if (strlen($json) > self::MAX_FIELDS_BYTES) {
            /*
             * Слишком большой черновик не пишется молча.
             *
             * Обычно это значит, что в позицию попал не её текст: например,
             * целый файл, вставленный в поле. Записать его можно было бы и
             * так, но такой черновик вернулся бы при следующем открытии и занял
             * место, которого никто не ждёт.
             */
            return;
        }

        $this->db->run(
            'INSERT INTO assignment_drafts (assignment_id, item_id, author_id, fields, updated_at)
                  VALUES (:assignment, :item, :author, :fields, :now)
             ON DUPLICATE KEY UPDATE
                  fields = VALUES(fields),
                  assignment_id = VALUES(assignment_id),
                  updated_at = VALUES(updated_at)',
            [
                'assignment' => $assignmentId,
                'item'       => $itemId,
                'author'     => $authorId,
                'fields'     => $json,
                'now'        => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Убрать черновик.
     *
     * Вызывается по кнопке «Отмена» и после успешного сохранения: черновик,
     * переживший сохранение, вернулся бы при следующем открытии задания и
     * выглядел бы как откат уже сделанной правки.
     */
    public function drop(int $authorId, int $itemId): void
    {
        $this->db->run(
            'DELETE FROM assignment_drafts WHERE author_id = :author AND item_id = :item',
            ['author' => $authorId, 'item' => $itemId]
        );
    }
}