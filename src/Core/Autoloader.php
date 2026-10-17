<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Минимальный PSR-4 автозагрузчик.
 *
 * Composer на шаед-хостинге не нужен: приложение не имеет внешних зависимостей.
 * Пространство имён MyEdu\ мапится на каталог src/, пространства имён
 * плагинов добавляются сюда же — отсюда плагин и получает свои классы.
 */
final class Autoloader
{
    private const PREFIX = 'MyEdu\\';

    /** @var array<string,string> */
    private array $prefixes = [];

    /** @var self|null общий экземпляр на процесс */
    private static ?self $instance = null;

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = rtrim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->prefixes[$prefix] = $baseDir;
    }

    /**
     * Загрузчик приложения: создаётся один раз на процесс.
     *
     * Нужен и точке входа, и контейнеру: точке входа он нужен раньше всего,
     * потому что без него невозможно даже загрузить класс App, а контейнеру —
     * чтобы плагины дописывали в тот же загрузчик свои пространства имён.
     */
    public static function create(string $rootDir): self
    {
        if (self::$instance === null) {
            $autoloader = new self();
            $autoloader->addNamespace(self::PREFIX, $rootDir . '/src');
            $autoloader->register();

            self::$instance = $autoloader;
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Автозагрузчик не создан: вызовите Autoloader::create()');
        }

        return self::$instance;
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}