<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Доступ к конфигурации с поддержкой локального переопределения файла.
 *
 * Порядок: config/config.php (общие значения) <- config/config.local.php (локальные).
 * config.local.php не коммитится и на сервере создаётся хостингом/администратором.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    /** @param array<string,mixed> $items */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function load(string $configDir): self
    {
        $base = require rtrim($configDir, '/\\') . '/config.php';

        $localFile = rtrim($configDir, '/\\') . '/config.local.php';
        if (is_file($localFile)) {
            $local = require $localFile;
            if (!is_array($local)) {
                throw new \RuntimeException('config.local.php должен возвращать массив');
            }
            $base = self::mergeDeep($base, $local);
        }

        $base = self::applyEnvOverrides($base);

        return new self($base);
    }

    /**
     * Перекрытия из переменных окружения.
     *
     * Нужны тестам: они очищают базу, поэтому должны работать на отдельной
     * базе `my_edu_test`, а рабочая база разработчика остаётся нетронутой.
     * MYEDU_DB_NAME меняет только имя базы — доступы и прочее из конфигурации.
     */
    private static function applyEnvOverrides(array $items): array
    {
        $dbName = self::env('MYEDU_DB_NAME');

        if ($dbName !== '') {
            $items['database'] = is_array($items['database'] ?? null) ? $items['database'] : [];
            $items['database']['name'] = $dbName;
        }

        return $items;
    }

    /**
     * Чтение переменной окружения, безопасное для хостинга.
     *
     * На части шаред-хостингов `getenv()` выключен через `disable_functions`.
     * Прямой вызов обрывает страницу фатальной ошибкой — на любом адресе и
     * задолго до обращения к базе, — поэтому сначала пробуем `$_SERVER`, куда
     * переменные окружения попадают при обычных настройках веб-сервера, и
     * только потом сам `getenv()`, если он доступен.
     */
    public static function env(string $name): string
    {
        $fromServer = $_SERVER[$name] ?? '';

        if (is_string($fromServer) && trim($fromServer) !== '') {
            return trim($fromServer);
        }

        if (!function_exists('getenv')) {
            return '';
        }

        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->resolve($key);

        return $found ? $value : $default;
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Разрешает ключ вида 'grading.default_scale'.
     *
     * Сначала проверяется буквальное совпадение — на случай, если в ключе
     * действительно есть точка, — затем поиск по вложенным массивам.
     *
     * @return array{0:bool,1:mixed}
     */
    private function resolve(string $key): array
    {
        if (array_key_exists($key, $this->items)) {
            return [true, $this->items[$key]];
        }

        $current = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeDeep($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}