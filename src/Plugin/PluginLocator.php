<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

/**
 * Реестр с точки зрения одного плагина.
 *
 * Плагину нужен собственный префикс — чтобы строить свои адреса тем же
 * правилом, что и ядро, — и собственный каталог миграций. Больше ничего из
 * реестра ему не полагается: список плагинов, их настройки и включённость
 * относятся к чужой части системы, и трогать их из плагина нельзя.
 *
 * Название метода `ownPlugin()` у `PluginServices` отвечает этому же: не
 * «дай реестр», а «дай сведения обо мне».
 */
final class PluginLocator
{
    public function __construct(
        private readonly PluginManager $manager,
        private readonly string $id
    ) {
    }

    /**
     * Публичный адрес плагина с его префиксом: `/tests/task/5`.
     *
     * Собственный префикс вместо придуманного плагином: после смены `url_prefix`
     * в манифесте адреса поедут вместе с ним, а не останутся там, где были.
     */
    public function url(string $path = ''): string
    {
        return '/' . trim($this->manager->urlPrefix($this->id), '/')
            . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * Каталог миграций плагина.
     *
     * `null` — плагина нет на диске. Плагин обязан на это отреагировать и не
     * идти дальше: иначе он отметится установленным, ничего не применив.
     */
    public function migrationsDir(): ?string
    {
        return $this->manager->migrationsDir($this->id);
    }
}