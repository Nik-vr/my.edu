<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

/**
 * Ссылка ядра на задание, принадлежащее плагину.
 *
 * Идентификаторы заданий у разных плагинов лежат в разных таблицах и могут
 * совпадать, поэтому ссылка состоит из трёх частей: кто владелец, что за вид
 * задания и какой именно номер.
 */
final class TaskRef
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $kind,
        public readonly int $taskId
    ) {
    }

    /** @param array<string,mixed> $row строка assignment_items */
    public static function fromItemRow(array $row): self
    {
        return new self(
            (string) $row['plugin_id'],
            (string) $row['task_kind'],
            (int) $row['task_id']
        );
    }

    public function equals(self $other): bool
    {
        return $this->pluginId === $other->pluginId
            && $this->kind === $other->kind
            && $this->taskId === $other->taskId;
    }

    /** Строковый ключ для сравнения и поиска. */
    public function key(): string
    {
        return $this->pluginId . ':' . $this->kind . ':' . $this->taskId;
    }
}