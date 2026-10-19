<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

use ZipArchive;

/**
 * Чтение и запись zip-файла переноса.
 *
 * Расширение zip есть не везде: на локальном сервере оно есть, а на дешёвом
 *shared-хостинге может быть выключено, и перенос работ перестал бы работать
 * именно там, где он нужнее всего. Поэтому при расширении используется оно,
 * а без него — свой разбор и сборка архива.
 *
 * Свой вариант умеет ровно то, что нужно формату переноса: записать и прочитать
 * файлы, сжать или положить как есть. Он не претендует на совместимость с
 * другими архивами — принимать отсюда можно только пакет, собранный системой,
 * и в этом ограничении нет ничего рискованного.
 */
final class Zip
{
    /** Сжатый пакет, который разрешено загрузить на разбор. */
    public const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024;

    /** Без ZipArchive сырой архив целиком находится в памяти во время разбора. */
    private const MAX_FALLBACK_BYTES = 40 * 1024 * 1024;

    /** Объём распакованного содержимого ограничен независимо от размера архива. */
    private const MAX_TOTAL_UNCOMPRESSED = 32 * 1024 * 1024;

    private const MAX_ENTRY_UNCOMPRESSED = 16 * 1024 * 1024;

    private const MAX_ENTRIES = 512;

    /**
     * Расширение выдаваемого файла — всегда zip.
     *
     * Без zip-расширения архив собирается и разбирается своими руками, но
     * формат остаётся zip: те же сигнатуры, локальные заголовки и центральный
     * каталог. Поэтому файл открывается обычным архиватором и без
     * расширения PHP, а называть его иначе незачем.
     */
    public static function extension(): string
    {
        return 'zip';
    }

    /** Тип содержимого для заголовка ответа. */
    public static function contentType(): string
    {
        return 'application/zip';
    }

    /** Максимальный размер архива, который реально поддержан этим PHP. */
    public static function maxUploadBytes(): int
    {
        return class_exists(ZipArchive::class) ? self::MAX_ARCHIVE_BYTES : self::MAX_FALLBACK_BYTES;
    }

    /**
     * Собрать архив в файле.
     *
     * @param array<string,string> $files путь внутри архива => содержимое
     */
    public static function write(array $files): string
    {
        if (count($files) > self::MAX_ENTRIES) {
            throw new TransferException('Работа содержит слишком много файлов для переноса');
        }

        $total = 0;

        foreach ($files as $name => $content) {
            self::validateEntryName((string) $name);
            $total = self::addUncompressedSize($total, strlen($content));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'myedu_zip_');

        if ($temporary === false) {
            throw new TransferException('Не удалось создать временный файл для архива');
        }

        if (class_exists(ZipArchive::class)) {
            self::writeWithExtension($temporary, $files);
        } else {
            self::writeByHand($temporary, $files);
        }

        return $temporary;
    }

    /**
     * Прочитать архив в файле.
     *
     * @return array<string,string> путь внутри архива => содержимое
     */
    public static function read(string $path): array
    {
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size <= 0) {
            throw new TransferException('Файл переноса пустой или не найден');
        }

        if ($size > self::MAX_ARCHIVE_BYTES) {
            throw new TransferException('Файл переноса слишком большой: ' . round($size / 1024 / 1024) . ' МБ');
        }

        if (class_exists(ZipArchive::class)) {
            return self::readWithExtension($path);
        }

        if ($size > self::MAX_FALLBACK_BYTES) {
            throw new TransferException(
                'Без расширения zip можно разобрать пакет размером не более '
                . (int) (self::MAX_FALLBACK_BYTES / 1024 / 1024) . ' МБ'
            );
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new TransferException('Файл переноса не читается');
        }

        return self::readByHand($raw);
    }

    /** @param array<string,string> $files */
    private static function writeWithExtension(string $target, array $files): void
    {
        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new TransferException('Не удалось создать архив переноса');
        }

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    /**
     * @param array<string,string> $files
     *
     * Сжатие здесь не применяется: картинки и так уже сжаты, а json почти не
     * сжимается. Свой writer без сжатия зато на три экрана короче и не может
     * расходиться с тем, что умеет распаковать наш же reader.
     */
    private static function writeByHand(string $target, array $files): void
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = crc32($content);
            $uncompressed = strlen($content);
            $compressed = $uncompressed;

            $header = "\x50\x4b\x03\x04"
                . pack('v', 20)          // версия
                . pack('v', 0)           // флаги
                . pack('v', 0)           // метод: без сжатия
                . pack('v', 0)           // время
                . pack('v', 0x21)        // дата: 1 января 1980, произвольная
                . pack('V', $crc)
                . pack('V', $compressed)
                . pack('V', $uncompressed)
                . pack('v', strlen($name))
                . pack('v', 0)
                . $name;

            $local .= $header . $content;

            $central .= "\x50\x4b\x01\x02"
                . pack('v', 20)          // версия создателя
                . pack('v', 20)          // версия для распаковки
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0x21)
                . pack('V', $crc)
                . pack('V', $compressed)
                . pack('V', $uncompressed)
                . pack('v', strlen($name))
                . pack('v', 0)           // дополнительные поля
                . pack('v', 0)           // комментарий
                . pack('v', 0)           // номер диска
                . pack('v', 0)           // внутренние атрибуты
                . pack('V', 32)          // внешние атрибуты: обычный файл
                . pack('V', $offset)
                . $name;

            $offset += strlen($header) + $uncompressed;
        }

        $end = "\x50\x4b\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', count($files))
            . pack('v', count($files))
            . pack('V', strlen($central))
            . pack('V', strlen($local))
            . pack('v', 0);

        if (@file_put_contents($target, $local . $central . $end) === false) {
            throw new TransferException('Не удалось записать архив переноса');
        }
    }

    /** @return array<string,string> */
    private static function readWithExtension(string $path): array
    {
        /*
         * Сверка заголовков — до распаковки.
         *
         * ZipArchive доверяет локальному заголовку файла, а проверяет размер,
         * объявленный в центральном каталоге. В нормальном архиве они совпадают,
         * а в бомбе — нет: каталог обещает килобайт, а по локальному заголовку
         * там терабайты. Проверка `strlen($content) !== $size` срабатывает уже
         * после того, как память занята и процесс упал, — то есть слишком
         * поздно, чтобы что-то предотвратить.
         *
         * Поэтому файл читается как обычный набор байт и сравнивается то, что
         * обещают центральный каталог и локальные заголовки. Расхождение — отказ
         * до распаковки; совпадение означает, что заявленный размер верен и
         * следующая проверка ограничит его сверху.
         */
        self::assertHeadersAgree($path);

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new TransferException('Файл переноса не распаковывается');
        }

        $files = [];
        $total = 0;

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new TransferException('В архиве слишком много файлов');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if (!is_array($stat)) {
                    throw new TransferException('Не удалось прочитать состав архива');
                }

                $name = (string) ($stat['name'] ?? '');

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                self::validateEntryName($name);

                if (array_key_exists($name, $files)) {
                    throw new TransferException('В архиве повторяется файл «' . $name . '»');
                }

                $size = (int) ($stat['size'] ?? -1);
                $method = (int) ($stat['comp_method'] ?? -1);
                $total = self::addUncompressedSize($total, $size);

                if (!in_array($method, [0, 8], true)) {
                    throw new TransferException('Метод сжатия файла «' . $name . '» не поддерживается');
                }

                $content = $zip->getFromIndex($index);

                if (!is_string($content) || strlen($content) !== $size) {
                    throw new TransferException('Файл «' . $name . '» повреждён или превышает допустимый размер');
                }

                if (sprintf('%u', crc32($content)) !== sprintf('%u', (int) ($stat['crc'] ?? 0))) {
                    throw new TransferException('Контрольная сумма файла «' . $name . '» неверна');
                }

                $files[$name] = $content;
            }
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }

        $zip->close();

        return $files;
    }

    /**
     * @return array<string,string>
     *
     * Разбор идёт по центральному каталогу в конце архива: он перечисляет все
     * файлы и их смещения, поэтому содержимое искать не приходится.
     */
    private static function readByHand(string $raw): array
    {
        // Смещение каталога берётся из завершающей записи, а не ищется в
        // сжатых данных: содержимое файла может случайно содержать сигнатуры ZIP.
        $endOffset = strrpos($raw, "\x50\x4b\x05\x06");

        if ($endOffset === false || $endOffset + 22 > strlen($raw)) {
            throw new TransferException('Файл переноса не похож на архив');
        }

        $end = unpack(
            'vdisk/vdirectoryDisk/ventriesOnDisk/ventries/VdirectorySize/VdirectoryOffset/vcommentLength',
            substr($raw, $endOffset + 4, 18)
        );

        if ($end === false
            || (int) $end['disk'] !== 0
            || (int) $end['directoryDisk'] !== 0
            || (int) $end['entriesOnDisk'] !== (int) $end['entries']
            || (int) $end['entries'] > self::MAX_ENTRIES
            || $endOffset + 22 + (int) $end['commentLength'] !== strlen($raw)) {
            throw new TransferException('Структура ZIP-архива не поддерживается или повреждена');
        }

        $directoryOffset = (int) $end['directoryOffset'];
        $directoryEnd = $directoryOffset + (int) $end['directorySize'];

        if ($directoryOffset < 0 || $directoryEnd > $endOffset) {
            throw new TransferException('Каталог ZIP-архива повреждён');
        }

        $files = [];
        $cursor = $directoryOffset;
        $total = 0;

        for ($index = 0; $index < (int) $end['entries']; $index++) {
            if ($cursor + 46 > $directoryEnd || substr($raw, $cursor, 4) !== "\x50\x4b\x01\x02") {
                throw new TransferException('Запись в каталоге ZIP-архива повреждена');
            }

            $header = unpack(
                'vversion/vversionNeeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
                substr($raw, $cursor + 4, 42)
            );

            if ($header === false) {
                break;
            }

            $recordEnd = $cursor + 46 + (int) $header['nameLength']
                + (int) $header['extraLength'] + (int) $header['commentLength'];

            if ($recordEnd > $directoryEnd) {
                throw new TransferException('Заголовок файла в ZIP-архиве обрезан');
            }

            $name = substr($raw, $cursor + 46, (int) $header['nameLength']);

            if ($name !== '' && !str_ends_with($name, '/')) {
                self::validateEntryName($name);

                if (array_key_exists($name, $files)) {
                    throw new TransferException('В архиве повторяется файл «' . $name . '»');
                }

                $method = (int) $header['method'];
                $compressedSize = (int) $header['compressedSize'];
                $uncompressedSize = (int) $header['size'];
                $total = self::addUncompressedSize($total, $uncompressedSize);

                if (!in_array($method, [0, 8], true)) {
                    throw new TransferException('Метод сжатия файла «' . $name . '» не поддерживается');
                }

                $local = (int) $header['offset'];

                if ($local < 0 || $local + 30 > $directoryOffset
                    || substr($raw, $local, 4) !== "\x50\x4b\x03\x04") {
                    throw new TransferException('Локальный заголовок файла «' . $name . '» повреждён');
                }

                $nameAtLocal = unpack('vnameLength/vextraLength', substr($raw, $local + 26, 4));

                $start = $local + 30 + (int) ($nameAtLocal['nameLength'] ?? 0) + (int) ($nameAtLocal['extraLength'] ?? 0);
                $compressedEnd = $start + $compressedSize;

                if ($start > $directoryOffset || $compressedEnd > $directoryOffset) {
                    throw new TransferException('Содержимое файла «' . $name . '» выходит за пределы архива');
                }

                $compressed = substr($raw, $start, $compressedSize);

                if (strlen($compressed) !== $compressedSize) {
                    throw new TransferException('Содержимое файла «' . $name . '» обрезано');
                }

                if ($method === 8) {
                    $content = @gzinflate($compressed, max(1, $uncompressedSize + 1));

                    if (!is_string($content)) {
                        throw new TransferException('Не удалось распаковать файл «' . $name . '»');
                    }
                } else {
                    $content = $compressed;
                }

                if (strlen($content) !== $uncompressedSize
                    || sprintf('%u', crc32($content)) !== sprintf('%u', (int) $header['crc'])) {
                    throw new TransferException('Размер или контрольная сумма файла «' . $name . '» неверны');
                }

                $files[$name] = $content;
            }

            $cursor = $recordEnd;
        }

        if ($cursor !== $directoryEnd) {
            throw new TransferException('Размер центрального каталога ZIP-архива не совпадает');
        }

        if ($files === []) {
            throw new TransferException('В архиве нет файлов переноса');
        }

        return $files;
    }

    /**
     * Центральный каталог и локальные заголовки обязаны говорить одно и то же.
     *
     * Файл читается напрямую, без ZipArchive: сверять надо то, что распаковщик
     * возьмёт на веру, — то есть локальные заголовки, — с тем, что видно в
     * каталоге. Бомба выглядит именно так: каталог обещает малый размер, а
     * заголовок — огромный, и распаковщик верит заголовку.
     *
     * Проверяются размер и контрольная сумма по каждому файлу, а также сам
     * объём распаковки по заголовкам: даже если каталог врёт только про сумму,
     * сумма по заголовкам уже покажет настоящий порядок величины.
     */
    private static function assertHeadersAgree(string $path): void
    {
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            throw new TransferException('Файл переноса не читается');
        }

        if (strlen($raw) > self::maxUploadBytes()) {
            throw new TransferException('Файл переноса слишком велик');
        }

        $endOffset = strrpos($raw, "\x50\x4b\x05\x06");

        if ($endOffset === false || $endOffset + 22 > strlen($raw)) {
            throw new TransferException('Файл переноса не распаковывается');
        }

        $end = unpack(
            /*
             * Размер центрального каталога — четыре байта. Объявить его двухбайтным
             * «по ошибке» не выдаёт себя: значение помещается в два байта и читается
             * верно, а вот следующее поле начинается на два байта раньше, и смещение
             * каталога приходит случайным числом. Ошибка выглядит как «архив
             * повреждён» на любом файле, и диагностируется только сверкой сырых байт.
             */
            'vdisk/vstartDisk/ventriesOnDisk/ventries/VdirectorySize/VdirectoryOffset/vcommentLength',
            substr($raw, $endOffset + 4, 18)
        );

        if ($end === false) {
            throw new TransferException('Файл переноса не распаковывается');
        }

        $entries = (int) $end['entriesOnDisk'];
        $cursor = (int) $end['directoryOffset'];
        $directoryEnd = $cursor + (int) $end['directorySize'];

        if ($entries > self::MAX_ENTRIES || $cursor < 0 || $directoryEnd > $endOffset) {
            throw new TransferException('Состав архива не читается');
        }

        $total = 0;

        for ($index = 0; $index < $entries; $index++) {
            if ($cursor + 46 > $directoryEnd || substr($raw, $cursor, 4) !== "\x50\x4b\x01\x02") {
                throw new TransferException('Состав архива не читается');
            }

            /*
             * Окно начинается с размера и заканчивается смещением локального
             * заголовка, а между ними лежат четыре поля: номер диска, внутренние
             * и внешние атрибуты. Их нельзя перепрыгивать — unpack читает подряд,
             * и без них «смещение» приходит из атрибутов. Окно — все 26 байт от
             * третьего до конца заголовка, то есть ровно до начала имени.
             */
            $header = unpack(
                'VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength'
                    . '/vdiskStart/vinternalAttributes/VexternalAttributes/Voffset',
                substr($raw, $cursor + 20, 26)
            );

            if ($header === false) {
                throw new TransferException('Состав архива не читается');
            }

            $cursor += 46 + (int) $header['nameLength']
                + (int) $header['extraLength'] + (int) $header['commentLength'];

            $size = (int) $header['size'];
            $total = self::addUncompressedSize($total, $size);

            $local = (int) $header['offset'];

            if ($local < 0 || $local + 30 > $directoryEnd || substr($raw, $local, 4) !== "\x50\x4b\x03\x04") {
                throw new TransferException('Локальный заголовок файла повреждён');
            }

            $localSizes = unpack('VcompressedSize/Vsize', substr($raw, $local + 18, 8));

            if ($localSizes === false
                || (int) $localSizes['size'] !== $size
                || (int) $localSizes['compressedSize'] !== (int) $header['compressedSize']) {
                /*
                 * Расхождение — и есть признак бомбы: каталог обещает одно,
                 * а распаковщик возьмёт другое. Отказ до распаковки — единственное
                 * место, где он ещё что-то значит.
                 */
                throw new TransferException(
                    'В архиве расходятся заголовки файла: возможно, это архив-бомба'
                );
            }
        }
    }

    private static function addUncompressedSize(int $total, int $size): int
    {
        if ($size < 0 || $size > self::MAX_ENTRY_UNCOMPRESSED) {
            throw new TransferException('Файл в архиве превышает допустимые 16 МБ');
        }

        $total += $size;

        if ($total > self::MAX_TOTAL_UNCOMPRESSED) {
            throw new TransferException('Распакованный пакет превышает допустимые 32 МБ');
        }

        return $total;
    }

    private static function validateEntryName(string $name): void
    {
        if (str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('#(^|/)\.\.?(/|$)#', $name) === 1) {
            throw new TransferException('В архиве найден недопустимый путь файла');
        }
    }
}
