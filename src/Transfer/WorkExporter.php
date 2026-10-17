<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

use MyEdu\Core\Database;
use MyEdu\Plugin\PortablePlugin;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;

/**
 * Выгрузка работы в пакет переноса.
 *
 * Переносится ровно то, что собирает мастер: настройки работы, шкала, задания и
 * то, что в них набрано — вопросы, варианты, формулы. Данные о выдаче работы
 * (кому выдана, кто и когда открывал) не переносятся: на новом месте учеников
 * может не быть вовсе, и перенос пытался бы создавать их или искать по
 * логинам, которых там ещё никто не заводил.
 *
 * Картинки вывозятся вместе с текстом. Без них перенос выглядел бы удачным, а
 * работа на новом месте осталась бы с пустыми условиями — это заметят ученики и
 * не заметит никто из взрослых, пока не придёт проверять работы.
 */
final class WorkExporter
{
    public function __construct(
        private readonly Database $db,
        private readonly PluginManager $plugins
    ) {
    }

    /**
     * Содержимое пакета без архива: путём внутри архива => содержимое.
     *
     * @return array{files:array<string,string>,skipped:list<string>}
     */
    public function export(int $assignmentId, string $uploadsDir): array
    {
        $assignment = $this->db->one('SELECT * FROM assignments WHERE id = :id', ['id' => $assignmentId]);

        if ($assignment === null) {
            throw new TransferException('Работа не найдена');
        }

        $subject = $this->subjectName($assignment);
        $items = [];
        $skipped = [];

        foreach ($this->db->all(
            'SELECT * FROM assignment_items WHERE assignment_id = :id ORDER BY position, variant',
            ['id' => $assignmentId]
        ) as $item) {
            $exported = $this->exportItem($item, $subject, $skipped);

            if ($exported !== null) {
                $items[] = $exported;
            }
        }

        $work = [
            'title'         => (string) $assignment['title'],
            'description'   => (string) ($assignment['description'] ?? ''),
            'subject'       => $subject,
            'parallel'      => (int) ($assignment['parallel'] ?? 0) ?: null,
            'status'        => (string) $assignment['status'],
            'grade_mode'    => (string) $assignment['grade_mode'],
            'criteria_mode' => (string) $assignment['criteria_mode'],
            'show_results'  => (int) $assignment['show_results'],
            'time_limit'    => $assignment['time_limit'] === null ? null : (int) $assignment['time_limit'],
            /*
             * Числа попыток в пакете нет: ограничение принадлежит выдаче, а
             * выдача — это отдельное действие учителя на новом месте. Переносить
             * работу и переносить её раздачу — разные вещи, и второе учитель
             * делает осознанно, зная своих учеников.
             */
            'criteria'      => json_decode((string) ($assignment['criteria'] ?? 'null'), true),
            'items'         => $items,
        ];

        $builder = (new WorkPackageBuilder())->withWork($work)->collectMedia($uploadsDir);

        return [
            'files'   => $builder->finish($work),
            'skipped' => $skipped,
        ];
    }

    /**
     * Позиция работы вместе с содержимым задания.
     *
     * @param list<string> $skipped
     *
     * @return array<string,mixed>|null
     */
    private function exportItem(array $item, ?string $subject, array &$skipped): ?array
    {
        $ref = TaskRef::fromItemRow($item);
        $plugin = $this->plugins->get($ref->pluginId);

        if (!$plugin instanceof PortablePlugin) {
            $skipped[] = 'задание №' . (string) ($item['position'] ?? '?')
                . ' — плагин «' . $ref->pluginId . '» не умеет переносить задания';

            return null;
        }

        $payload = $plugin->exportTask($ref);

        if ($payload === null) {
            $skipped[] = 'задание №' . (string) ($item['position'] ?? '?') . ' — плагин не отдал содержимое';

            return null;
        }

return [
        'position' => (int) $item['position'],
        // Номер варианта обязателен: без него перенесённая работа рассыпалась бы
        // на отдельные позиции — три задания одной строки стали бы тремя
        // разными заданиями работы, и ученик получил бы все три сразу.
        'variant'  => (int) ($item['variant'] ?? 1),
'plugin'   => $ref->pluginId,
            'kind'     => $ref->kind,
            'weight'   => (float) $item['weight'],
        'max_score' => $item['max_score'] === null ? null : (float) $item['max_score'],
        'subject'  => $subject,
        'payload'  => $payload,
        ];
    }

    /** Название предмета, а не его номер: номера на новом месте другие. */
    private function subjectName(array $assignment): ?string
    {
        $subjectId = (int) ($assignment['subject_id'] ?? 0);

        if ($subjectId <= 0) {
            return null;
        }

        $name = $this->db->value('SELECT name FROM subjects WHERE id = :id', ['id' => $subjectId]);

        return is_scalar($name) && (string) $name !== '' ? (string) $name : null;
    }
}