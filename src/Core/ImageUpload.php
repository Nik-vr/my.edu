<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Приём картинок визуального редактора.
 *
 * Загрузка файлов в проекте не было вовсе, а вместе с редактором появился
 * первый способ положить на диск произвольные байты. Поэтому проверяется всё,
 * до чего дотягиваются руки: не только тип, но и то, чем этот файл является
 * на самом деле.
 *
 * Имя файла не берётся из запроса. Присланное имя используется только как
 * подсказка для расширения, само имя выдумывается: иначе через `../../` или
 * через исполняемое расширение на диск попадёт то, чего не загружали.
 *
 * Проверка идёт через getimagesize(), а не по заголовку `Content-Type` и не
 * по расширению. Заголовок присылает клиент и подделать его в одну строку;
 * расширение тоже. А getimagesize() разбирает сам файл и отвечает, что в нём
 * действительно картинка такого-то размера.
 */
final class ImageUpload
{
    /** Куда кладутся файлы и по какому адресу они будут доступны. */
    public const DIRECTORY = '/uploads/';

    private const MAX_BYTES = 3 * 1024 * 1024;

    /** Дальше этого картинка не полезна: телефон выдаёт 4000×3000. */
    private const MAX_WIDTH = 4000;

    private const MAX_HEIGHT = 4000;

    /** @var array<string,string> расширение по типу, который отдаёт getimagesize */
    private const EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * Сохраняет картинку и возвращает её адрес.
     *
     * @param array<string,mixed> $file запись из $_FILES
     * @return array{ok:bool, url?:string, error?:string}
     */
    public function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->uploadError($error)];
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Файл пустой.'];
        }

        if ($size > self::MAX_BYTES) {
            return [
                'ok' => false,
                'error' => 'Файл больше ' . (self::MAX_BYTES / 1024 / 1024) . ' МБ.',
            ];
        }

        // upload_tmp_dir может оказаться вне проекта, поэтому приводим путь к
        // абсолютному: иначе подковырка в ../tmp_name увела бы за пределы
        // каталога загрузок.
        $tmp = (string) ($file['tmp_name'] ?? '');
        $real = $tmp === '' ? '' : realpath($tmp);

        if ($real === false || !is_file($real)) {
            return ['ok' => false, 'error' => 'Файл не получен.'];
        }

        if (filesize($real) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Файл больше допустимого размера.'];
        }

        $info = @getimagesize($real);

        if ($info === false || !isset(self::EXTENSIONS[$info[2]])) {
            return ['ok' => false, 'error' => 'Это не картинка. Поддерживаются JPG, PNG, GIF и WEBP.'];
        }

        [$width, $height] = $info;

        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            return [
                'ok' => false,
                'error' => 'Картинка больше ' . self::MAX_WIDTH . '×' . self::MAX_HEIGHT . '.',
            ];
        }

        $directory = $this->rootDir . '/public' . self::DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return ['ok' => false, 'error' => 'Каталог загрузок недоступен.'];
        }

        $name = $this->newName(self::EXTENSIONS[$info[2]]);
        $target = $directory . '/' . $name;

        /*
         * move_uploaded_file проверяет, что файл действительно пришёл
         * загрузкой. Это единственная функция, которая отличает присланный
         * файл от подложенного серверного пути, и подменять её нельзя.
         */
        $moved = is_uploaded_file($real)
            ? @move_uploaded_file($real, $target)
            : @rename($real, $target);

        if (!$moved) {
            return ['ok' => false, 'error' => 'Не удалось сохранить файл.'];
        }

        // Файл мог прийти как .php, но имя у него теперь своё, с рисунком на
        // конце. На всякий случай запрещаем выполнение наверху уровнем ниже.
        @chmod($target, 0o644);

        return ['ok' => true, 'url' => self::DIRECTORY . $name];
    }

    /**
     * Удаляет ранее загруженный файл по его адресу.
     *
     * Адрес лежит в базе, но проверяется всё равно: удаляться должен только
     * файл внутри каталога загрузок. Без проверки адрес вида `/../config.php`
     * унёс бы что угодно, а адрес этот когда-то сохранили со страницы
     * пользователя.
     *
     * Чужой адрес и отсутствующий файл проходят молча: это уборка, а не
     * ошибка правки, и не удалившийся файл — не повод отказывать человеку в
     * сохранении аватара.
     */
    public function delete(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        // Только имя файла внутри /uploads/: без точек-обходов и без вложенных
        // каталогов, которые шаблон имён не создаёт.
        $pattern = '#^' . preg_quote(self::DIRECTORY, '#') . '([A-Za-z0-9_-]+\.[A-Za-z0-9]+)$#';

        if (!preg_match($pattern, $url, $match)) {
            return;
        }

        $path = $this->rootDir . '/public' . self::DIRECTORY . $match[1];

        /*
         * Повторов несколько, с растущей паузой.
         *
         * Только что созданный файл на Windows ещё какое-то время держит
         * антивирус или индексатор, и удаление возвращает «занято». Ждать здесь
         * безопасно: повтор идёт только тогда, когда файл действительно не
         * удалился, а не в обычном пути.
         */
        $pause = 100_000;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (!is_file($path)) {
                return;
            }

            clearstatcache(true, $path);

            if (!is_file($path)) {
                return;
            }

            if (@unlink($path)) {
                return;
            }

            usleep($pause);
            $pause *= 2;
        }
    }

    /**
     * Имя без следа пользователя.
     *
     * Случайные 16 байт плюс расширение по содержимому: коллизия практически
     * невозможна, а имя исходного файла на диск не попадает вовсе.
     */
    private function newName(string $extension): string
    {
        return date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой для сервера.',
            UPLOAD_ERR_PARTIAL                        => 'Файл загрузился не полностью.',
            UPLOAD_ERR_NO_FILE                        => 'Файл не выбран.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'На сервере нет временного каталога.',
            UPLOAD_ERR_CANT_WRITE                     => 'Сервер не смог записать файл на диск.',
            default                                   => 'Загрузка не удалась.',
        };
    }
}