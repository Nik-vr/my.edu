<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

use MyEdu\Auth\SubjectRepository;
use MyEdu\Core\Database;
use MyEdu\Plugin\PortablePlugin;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;

/**
 * Внос пакета переноса в базу.
 *
 * Порядок: сначала заготовка (работа целиком в одной транзакции), затем
 * содержимое — по одной позиции. Так упавшая на середине позиция не оставляет
 * работы, у которой половина заданий есть, а половины — нет: у такой работы
 * не существует автора, по которому её можно было бы открыть.
 *
 * Если плагина на новом месте нет или он не умеет переносить задания, работа всё
 * равно создаётся — без такой позиции и с записью об этом в отчёт. Иначе
 * перенос перестал бы работать из-за одного старого плагина: работа со
 * списанными работами важнее, чем её полнота, а автор увидит, чего не хватило.
 */
final class WorkImporter
{
    public function __construct(
        private readonly Database $db,
        private readonly PluginManager $plugins,
        private readonly SubjectRepository $subjects
    ) {
    }

    /**
     * Внести работу.
     *
     * @param array{manifest:array<string,mixed>,work:array<string,mixed>,media:array<string,string>} $package
     *
     * @return array{assignmentId:int,created:list<string>,skipped:list<string>,subject:string,media:int}
     */
    public function import(array $package, int $authorId, string $uploadsDir): array
    {
        $work = $package['work'];

        $subjectName = trim((string) ($work['subject'] ?? ''));
        $mediaMap = $this->saveMedia($package['media'], $uploadsDir);

        $created = [];
        $skipped = [];

        try {
            $assignmentId = (int) $this->db->transaction(
                function (Database $db) use ($work, $authorId, $subjectName, $mediaMap, &$created, &$skipped): int {
                // Новый предмет создаётся внутри той же транзакции: неудачный
                // импорт не должен оставлять в каталоге предмет без работы.
                $subjectId = $subjectName === '' ? null : $this->resolveSubject($subjectName);

                // Занятые ячейки строк: (позиция:вариант). Нужны, чтобы
                // повторяющийся номер из чужого файла не уронил перенос
                // нарушением уникального индекса посреди всей работы.
                $taken = [];

                $id = $db->insert('assignments', [
                    'title'         => mb_substr(trim((string) $work['title']), 0, 191),
                    'description'   => $this->rewrite((string) ($work['description'] ?? ''), $mediaMap),
                    'subject_id'    => $subjectId,
                    'parallel'      => (int) ($work['parallel'] ?? 0) ?: null,
                    'author_id'     => $authorId,
                    // Перенесённая работа всегда начинается черновиком: на новом
                    // месте её никто не видел, и публиковать её должен человек.
                    'status'        => 'draft',
                    'grade_mode'    => (string) ($work['grade_mode'] ?? 'weighted'),
                    'criteria_mode' => (string) ($work['criteria_mode'] ?? 'percent'),
                    'show_results'  => (int) ($work['show_results'] ?? 1),
                    'time_limit'    => $work['time_limit'] === null ? null : (int) $work['time_limit'],
                    // Попыток у перенесённой работы нет: их задаёт выдача на новом месте.
                    'criteria'      => $this->rewrite(
                        $work['criteria'] === null || $work['criteria'] === []
                            ? null
                            : json_encode($work['criteria'], JSON_UNESCAPED_UNICODE),
                        $mediaMap
                    ),
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);

                foreach ($this->sortedItems($work) as $item) {
                    $label = 'задание №' . (string) ($item['position'] ?? '?');

                    /*
                     * Номер варианта из файла, но не как есть: чужой пакет может
                     * принести четвёртую карточку в строке или повторяющийся
                     * номер. Такую работу доска не покажет, а ученик не выполнит,
                     * поэтому лишнее пропускается — с именем, чтобы автор видел,
                     * куда делось его задание.
                     */
                    $position = max(1, (int) ($item['position'] ?? 1));
                    $variant = (int) ($item['variant'] ?? 1);

                    if ($variant < 1) {
                        $variant = 1;
                    }

                    if ($variant > AssignmentRepository::MAX_VARIANTS || isset($taken[$position . ':' . $variant])) {
                        $skipped[] = $label . ' — в переносе больше трёх вариантов в одной строке';

                        continue;
                    }

                    $outcome = $this->importItem($item, $authorId, $subjectId);

                    if ($outcome['ref'] === null) {
                        $skipped[] = $label . ' — ' . $outcome['reason'];

                        continue;
                    }

                    $taken[$position . ':' . $variant] = true;

                    $db->insert('assignment_items', [
                        'assignment_id'  => $id,
                        'position'       => $position,
                        'variant'        => $variant,
                        'plugin_id'      => $outcome['ref']->pluginId,
                        'task_kind'      => $outcome['ref']->kind,
                        'task_id'        => $outcome['ref']->taskId,
                        'weight'         => (float) ($item['weight'] ?? 1),
                        'max_score'      => $item['max_score'] === null ? null : (float) $item['max_score'],
                        'created_at'     => date('Y-m-d H:i:s'),
                    ]);

                    $created[] = $label;
                }

                    return $id;
                }
            );
        } catch (\Throwable $e) {
            $this->removeMedia($uploadsDir, $mediaMap);
            throw $e;
        }

        return [
            'assignmentId' => $assignmentId,
            'created'      => $created,
            'skipped'      => $skipped,
            'subject'      => $subjectName === '' ? 'без предмета' : $subjectName,
            'media'        => count($mediaMap),
        ];
    }

    /**
     * Позиции по порядку, каким их видел учитель.
     *
     * Сортировка идёт по паре «место, вариант», а не только по месту. Одного
     * места мало: в одной строке может быть до трёх вариантов, и порядок их
     * обхода определяет, какая карточка выживет при совпадении места и
     * варианта — остальные уходят в отчёт как дубли. Без вторичного ключа
     * этот выбор зависел от порядка ключей в разобранном JSON, то есть один и
     * тот же пакет мог дать разный результат.
     *
     * @param array<string,mixed> $work
     *
     * @return list<array<string,mixed>>
     */
    private function sortedItems(array $work): array
    {
        $items = [];

        foreach ($work['items'] ?? [] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        usort($items, static function (array $a, array $b): int {
            $byPosition = (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);

            return $byPosition !== 0
                ? $byPosition
                : (int) ($a['variant'] ?? 1) <=> (int) ($b['variant'] ?? 1);
        });

        return $items;
    }

    /**
     * Создать задание позиции.
     *
     * Причина пропуска возвращается рядом с результатом, а не кладётся в
     * позицию: массив в PHP передаётся по значению, и записанная причина
     * осталась бы в копии. Отчёт о пропуске — единственное место, где человек
     * узнает, чего в переносе не хватило, и «плагин недоступен» вместо
     * настоящей причины сбил бы с толку.
     *
     * @param array<string,mixed> $item
     *
     * @return array{ref:TaskRef|null,reason:string}
     */
    private function importItem(array $item, int $authorId, ?int $subjectId): array
    {
        $pluginId = (string) ($item['plugin'] ?? '');
        $plugin = $pluginId === '' ? null : $this->plugins->get($pluginId);

        if (!$plugin instanceof PortablePlugin) {
            return [
                'ref'    => null,
                'reason' => $pluginId === ''
                    ? 'в пакете не указан плагин'
                    : 'плагин «' . $pluginId . '» на этом месте выключен или не умеет переносить задания',
            ];
        }

        $payload = $item['payload'] ?? null;

        if (!is_array($payload)) {
            return ['ref' => null, 'reason' => 'в пакете нет содержимого задания'];
        }

        $kind = (string) ($item['kind'] ?? '');

        try {
            $taskId = $this->db->savepoint(function () use ($plugin, $payload, $kind, $authorId, $subjectId): int {
                $taskId = $plugin->importTask($payload, $kind, $authorId, $subjectId);

                if ($taskId <= 0) {
                    throw new \RuntimeException('плагин не создал задание');
                }

                return $taskId;
            });
        } catch (\Throwable $e) {
            return ['ref' => null, 'reason' => 'плагин не смог создать задание: ' . $e->getMessage()];
        }

        return ['ref' => new TaskRef($pluginId, $kind, $taskId), 'reason' => ''];
    }

    /**
     * Предмет по названию: на новом месте он либо есть, либо его заводим.
     *
     * Номер предмета на новом месте другой, а работа без предмета теряет
     * половину своего смысла — в списке работ и в среднем балле она окажется
     * отдельно от остальных.
     */
    private function resolveSubject(string $name): int
    {
        $existing = $this->db->one('SELECT id FROM subjects WHERE name = :n LIMIT 1', ['n' => $name]);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->subjects->create([
            'name' => mb_substr($name, 0, 191),
        ]);
    }

    /**
     * Разложить картинки по каталогу загрузок.
     *
     * Имя файла выдумывается здесь, а не берётся из пакета: пакет приходит
     * извне, и имя из него — это готовый путь к файлу, который не прислали.
     * Настоящее имя проверяется тем же, чем проверялось при загрузке через
     * редактор.
     *
     * @param array<string,string> $media
     *
     * @return array<string,string> имя из пакета => новое имя на диске
     */
    private function saveMedia(array $media, string $uploadsDir): array
    {
        if ($media === []) {
            return [];
        }

        if (!is_dir($uploadsDir)) {
            throw new TransferException('На этом месте нет каталога загрузок — картинки перенести некуда');
        }

        $map = [];
        $createdPaths = [];

        try {
            foreach ($media as $name => $content) {
                $name = basename($name);
                $info = @getimagesizefromstring($content);

                if ($info === false || !isset($info[2])) {
                    // Не картинка: молча пропускаем, пакет разберётся дальше и
                    // покажет это в отчёте. Битые байты в файле переноса не должны
                    // останавливать перенос всей работы.
                    continue;
                }

                $extensions = [
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_GIF => 'gif',
                    IMAGETYPE_WEBP => 'webp',
                ];

                $extension = $extensions[$info[2]] ?? 'png';
                $stored = bin2hex(random_bytes(8)) . '.' . $extension;
                $target = $uploadsDir . '/' . $stored;
                $createdPaths[] = $target;
                $written = @file_put_contents($target, $content);

                if ($written === false || $written !== strlen($content)) {
                    throw new TransferException('Не удалось сохранить картинку ' . $name);
                }

                $map[$name] = $stored;
            }

            return $map;
        } catch (\Throwable $e) {
            foreach ($createdPaths as $path) {
                @unlink($path);
            }

            throw $e;
        }
    }

    /** Удалить только файлы, имена которых создал этот импорт. */
    private function removeMedia(string $uploadsDir, array $mediaMap): void
    {
        foreach ($mediaMap as $name) {
            $name = (string) $name;

            if (preg_match('/^[a-f0-9]{16}\.(?:jpg|png|gif|webp)$/', $name) === 1) {
                @unlink($uploadsDir . '/' . $name);
            }
        }
    }

    /**
     * Переписать ссылки на картинки на новые имена.
     *
     * @param array<string,string> $map
     */
    private function rewrite(?string $text, array $map): ?string
    {
        if ($text === null || $text === '' || $map === []) {
            return $text;
        }

        return (string) preg_replace_callback(
            '#media/([A-Za-z0-9._-]+)#',
            static function (array $matches) use ($map): string {
                $new = $map[$matches[1]] ?? null;

                // Неизвестная картинка остаётся ссылкой на media/: работа не
                // потеряет текст из-за одной картинки, а поломанная ссылка
                // видна автору при открытии.
                return $new === null ? 'media/' . $matches[1] : '/uploads/' . $new;
            },
            $text
        );
    }
}
