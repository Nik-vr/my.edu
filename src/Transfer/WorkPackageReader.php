<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

/**
 * Разбор пакета переноса: что в нём и с чем оно сойдётся.
 *
 * Разбор идёт раньше записи в базу и ничего не меняет: учитель должен увидеть
 * состав пакета и решить, стоит ли его вносить, пока ещё ничего не создано.
 * Запись — отдельным шагом.
 */
final class WorkPackageReader
{
    private const MANIFEST = 'manifest.json';
    private const WORK = 'work.json';

    /**
     * Разобрать архив.
     *
     * @return array{manifest:array<string,mixed>,work:array<string,mixed>,media:array<string,string>}
     */
    public static function read(string $path): array
    {
        $files = Zip::read($path);

        if (!isset($files[self::MANIFEST]) || !isset($files[self::WORK])) {
            throw new TransferException('Это не пакет переноса работы: нет manifest.json или work.json');
        }

        $manifest = json_decode($files[self::MANIFEST], true);
        $work = json_decode($files[self::WORK], true);

        if (!is_array($manifest) || !is_array($work)) {
            throw new TransferException('Пакет переноса повреждён: json не читается');
        }

        if (($manifest['format'] ?? '') !== 'myedu-work') {
            throw new TransferException('Файл не является пакетом переноса работы');
        }

        if ((int) ($manifest['version'] ?? 0) > WorkPackageBuilder::FORMAT_VERSION) {
            throw new TransferException('Пакет сделан более новой версией системы — обновите программу на новом месте');
        }

        if (!isset($work['title']) || trim((string) $work['title']) === '') {
            throw new TransferException('В пакете нет названия работы');
        }

        $media = [];

        foreach ($files as $name => $content) {
            if (str_starts_with($name, 'media/')) {
                $media[substr($name, 6)] = $content;
            }
        }

        return ['manifest' => $manifest, 'work' => $work, 'media' => $media];
    }
}