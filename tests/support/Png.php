<?php

declare(strict_types=1);

/**
 * Картинка для проверок, собранная без расширения GD.
 *
 * GD в PHP не входит в ядро: на машине без него набор проверок падал с
 * «Call to undefined function imagecreatetruecolor()», хотя сервер GD не
 * нужен вовсе — он разбирает файл функцией getimagesize(), которая есть всегда.
 * Значит, требовать GD от проверки незачем: картинка собирается здесь вручную,
 * в виде тех же чанков, из которых состоит настоящий PNG.
 *
 * PNG устроен просто: сигнатура, затем чанки — длина, тип, данные, контрольная
 * сумма CRC32 от типа и данных. Данные картинки — строки пикселей, перед
 * каждой байт фильтра; сами строки сжимаются, и zlib в PHP есть всегда.
 *
 * Проверять, что получился годный PNG, не нужно: getimagesize() в самом
 * тесте и скажет, если чанки собраны неверно.
 */

/**
 * @param int $width  ширина в пикселях
 * @param int $height высота в пикселях
 *
 * @return string байты PNG
 */
function test_png_bytes(int $width = 12, int $height = 12): string
{
    $width = max(1, $width);
    $height = max(1, $height);

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    // Каждая строка пикселей: байт фильтра (ноль — без преобразования) и
    // тройка каналов на пиксель, RGB.
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00" . str_repeat("\x20\x78\xC8", $width);
    }

    return "\x89PNG\r\n\x1a\n"
        // IHDR: ширина, высота, 8 бит на канал, цвет 2 (truecolor), без
        // альфа-канала и без профиля.
        . $chunk('IHDR', pack('NNccccc', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw))
        . $chunk('IEND', '');
}

/** Записать картинку на диск и вернуть путь: удобнее, чем байты, в multipart. */
function test_write_png(string $path, int $width = 12, int $height = 12): string
{
    file_put_contents($path, test_png_bytes($width, $height));

    return $path;
}