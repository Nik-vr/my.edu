<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

/**
 * Что нашлось в папке plugins/.
 *
 * Описание плагина получается из манифеста до подключения кода: чтобы показать
 * в панели список и включить плагин, запускать его PHP необязательно. Это
 * позволяет увидеть битый плагин в списке, а не молчаливую пустую страницу.
 */
final class PluginDescriptor
{
/**
 * @param array<string,mixed> $manifest содержимое plugin.json
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $dir,
        public readonly string $namespace,
        public readonly string $entry,
        public readonly string $description = '',
        public readonly string $author = '',
        public readonly string $requiresCore = '',
        public readonly string $urlPrefix = '',
        public readonly ?string $error = null,
        /*
         * Даёт ли плагин виды заданий. Читается из манифеста, а не из кода:
         * выключенный плагин не подключается, и узнать о нём что-то можно
         * только по описанию. Редактор работы говорит об этом прямо: тип
         * задания есть, а кнопки нет — иначе плагин, забытый включить,
         * выглядит как ошибка ядра.
         */
        public readonly bool $providesTasks = false
    ) {
    }

    /**
     * Префикс публичных адресов плагина.
     *
     * Свои маршруты плагин объявляет относительно префикса, чтобы не знать,
     * где именно он установлен: /tests/{id} собирается из префикса tests и
     * маршрута /{id}.
     */
    public function urlPrefix(): string
    {
        return $this->urlPrefix !== '' ? $this->urlPrefix : $this->id;
    }

    /** Плагин не прочитался или не проходит проверки — показать в списке как битый. */
    public function isBroken(): bool
    {
        return $this->error !== null;
    }

    public function errorMessage(): string
    {
        return $this->error ?? '';
    }

    /** Абсолютный путь к файлу точки входа. */
    public function entryPath(): string
    {
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($this->entry, '/'));
    }
}