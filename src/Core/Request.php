<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Доступ к входным данным HTTP-запроса.
 *
 * Адрес приводится к виду, каким его видит роутер: префикс размещения
 * приложения убирается. Без этого сайт, открытый по адресу `/public/`,
 * искал бы маршрут `/public/login` и отвечал 404 собственной страницей.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $post;

    /** @var array<string,mixed> */
    private array $server;

    private string $method;

    private string $path;

    /** @var array<string,mixed> */
    private array $files;

    public function __construct(
        array $query = [],
        array $post = [],
        array $server = [],
        array $files = [],
        string $basePath = ''
    ) {
        $this->files = $files;
        $this->query = $query;
        $this->post = $post;
        $this->server = $server;

        $this->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $parsed = parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim(is_string($parsed) ? $parsed : '/', '/');

        $this->path = self::stripBasePath($path, $basePath);
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $basePath);
    }

    /**
     * Убирает префикс размещения приложения из адреса.
     *
     * Сравнение идёт по целым сегментам: префикс `/public` не должен срезаться
     * у адреса `/publications`, у которого с таким префиксом ничего общего.
     */
    private static function stripBasePath(string $path, string $basePath): string
    {
        $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');

        if ($basePath === '/') {
            return $path;
        }

        if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
            return $path;
        }

        $stripped = substr($path, strlen($basePath));

        return $stripped === '' ? '/' : rtrim($stripped, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Значение из адреса как список.
     *
     * Форма с флажками приходит массивом: `?classes[]=5&classes[]=6`. Такой
     * массив queryParam() не возвращает — отдаёт значение по умолчанию, и
     * отмеченное молча исчезает. Списком читать такие значения надо отдельно,
     * иначе шаг, где что-то отмечают флажками, через GET не проходится:
     * сервер решает, что ничего не отмечено.
     *
     * @return array<mixed>
     */
    public function queryList(string $key): array
    {
        $value = $this->query[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function post(): array
    {
        return $this->post;
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Запись одного поля файла из $_FILES.
     *
     * $_FILES для одного файла имеет вид ['name' => …, 'tmp_name' => …], а для
     * нескольких — ['name' => ['a', 'b'], …]. Возвращается плоская запись
     * только для одиночного поля: загрузка картинки всегда одна, и разбирать
     * вложенный вид здесь незачем — он всё равно пришёл бы мимо проверки.
     *
     * @return array<string,mixed>
     */
    public function file(string $key): array
    {
        $value = $this->files[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return isset($value['name']) && is_string($value['name']) ? $value : [];
    }

    public function postParam(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<mixed> */
    public function postArray(string $key): array
    {
        $value = $this->post[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Значения поля, пришедшего несколько раз: roles[]=a&roles[]=b.
     *
     * postParam() здесь не подходит — он возвращает только скаляры, поэтому
     * список флажков через него всегда выглядел бы пустым.
     *
     * @return list<string>
     */
    public function postList(string $key): array
    {
        $value = $this->post[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Ждёт ли клиент JSON в ответ.
     *
     * Отдельный метод, а не проверка заголовка в контроллере: правило «когда
     * отвечать данными, а когда страницей» одно на всё ядро, и разойтись оно
     * должно было бы ровно там, где забыли его проверить.
     *
     * Заголовок X-Requested-With ставит скрипт сам и не подделывается формами:
     * обычная отправка формы сюда никогда не попадёт, и страница продолжит
     * работать без скриптов. Заголовок Accept — запасной путь для клиентов,
     * которые его шлют.
     */
    public function wantsJson(): bool
    {
        if ($this->header('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains((string) $this->header('Accept'), 'application/json');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isLoopback(): bool
    {
        $ip = $this->ip();

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && inet_pton($ip) === inet_pton('::1');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}