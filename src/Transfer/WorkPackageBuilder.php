<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

use RuntimeException;

/**
 * Сборка пакета переноса: содержимое и картинки, без архива.
 *
 * Архив — это упаковка, а содержимое пакета собирается отдельно: так один и тот
 * же набор файлов можно и отдать учителю файлом, и показать на странице импорта
 * до того, как что-либо запишется в базу.
 *
 * Формат — zip с двумя файлами:
 *
 *   manifest.json — что это, какой версии формат и сколько чего внутри;
 *   work.json     — сама работа: настройки, шкала и задания;
 *   media/…       — картинки, на которые ссылается текст заданий.
 *
 * Формат текста в work.json — тот же, что и сейчас в системе: `/uploads/имя.png`.
 * Имя файла при выгрузке сохраняется, при импорте картинка получает новое имя и
 * ссылки переписываются. Само имя из пакета не используется как имя на диске:
 * пакет приходит извне, и имя из него — это путь, который кто-то может задать.
 */
final class WorkPackageBuilder
{
    /** Версия формата. Меняется, только если меняется смысл полей. */
    public const FORMAT_VERSION = 1;

    private const MANIFEST = 'manifest.json';
    private const WORK = 'work.json';
    private const MEDIA_DIR = 'media/';

    /** @var array<string,string> файл внутри архива => содержимое */
    private array $files = [];

    /** @var array<string,string> имя на диске => содержимое */
    private array $media = [];

    /** @var array<string,string> имя на диске => имя внутри пакета */
    private array $mediaUsed = [];

    /**
     * Добавить содержимое работы.
     *
     * @param array<string,mixed> $data работа с заданиями
     */
    public function withWork(array $data): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Работа не собралась в json');
        }

        $this->files[self::WORK] = $this->rewriteUploads($json);

        return $this;
    }

    /**
     * Забрать картинки, на которые ссылается уже добавленное содержимое.
     *
     * Порядок важен: сперва текст переписывается на `media/…`, и только потом
     * добавляются сами файлы — иначе пришлось бы угадывать, какие ссылки
     * останутся в готовом содержимом. И берутся файлы по тем именам, что уже
     * записаны в mediaUsed: переписывание их и объявило.
     */
    public function collectMedia(string $uploadsDir): self
    {
        foreach (array_keys($this->mediaUsed) as $file) {
            if (isset($this->media[$file])) {
                continue;
            }

            $bytes = @file_get_contents($uploadsDir . '/' . $file);

            if ($bytes !== false) {
                $this->media[$file] = $bytes;
            }
        }

        foreach ($this->mediaUsed as $file => $path) {
            if (isset($this->media[$file])) {
                $this->files[$path] = $this->media[$file];
            }
        }

        return $this;
    }

    /**
     * Записать манифест и отдать содержимое архива.
     *
     * @param array<string,mixed> $data работа, как она ушла в work.json
     */
    public function finish(array $data): array
    {
        $manifest = [
            'format'       => 'myedu-work',
            'version'      => self::FORMAT_VERSION,
            'exported_at'  => date('c'),
            'title'        => (string) ($data['title'] ?? ''),
            'items'        => count($data['items'] ?? []),
            'media'        => array_keys($this->mediaUsed),
            'without_media' => array_values(array_diff(
                array_keys($this->mediaUsed),
                array_keys($this->media)
            )),
        ];

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Манифест не собрался в json');
        }

        $files = $this->files;
        $files[self::MANIFEST] = $json;

        // Манифест идёт первым: в списке файлов архива он виден глазом, и по
        // нему понятно, что это за файл, ещё до распаковки.
        return [self::MANIFEST => $files[self::MANIFEST]] + $files;
    }

    /** @return array<string,string> */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Заменить ссылки на картинки на пути внутри пакета.
     */
    private function rewriteUploads(string $text): string
    {
        return (string) preg_replace_callback(
            '#/uploads/([A-Za-z0-9._-]+)#',
            function (array $matches): string {
                $this->mediaUsed[$matches[1]] = self::MEDIA_DIR . $matches[1];

                return 'media/' . $matches[1];
            },
            $text
        );
    }
}