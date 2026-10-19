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
        return self::wantsJsonRequest($this->server);
    }

    /**
     * Хочет ли клиент данные вместо страницы — по тем же заголовкам.
     *
     * Правило одно на всё ядро, и метод статический не по прихоти: обработчик
     * ошибок разбирает запрос, когда объект запроса ещё не собран, и раньше
     * повторял правило у себя. Повторение разошлось с оригиналом — в одном
     * месте «text/html» в Accept отменял ответ данными, в другом нет, — и
     * человек получал то страницу ошибки, то данные, в зависимости от того, где
     * именно ошибка случилась.
     *
     * @param array<string,mixed> $server
     */
    public static function wantsJsonRequest(array $server): bool
    {
        if (strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower((string) ($server['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

/*
 * Адрес клиента.
 *
 * За `REMOTE_ADDR` стоит обратный прокси, а не человек: за ним все клиенты
 * выглядят одним адресом. Наивное чтение `X-Forwarded-For` без белого списка
 * хуже отсутствия поддержки прокси вообще — заголовок подделывает кто угодно,
 * и человек подставлял бы себе чужие адреса, обходя лимиты на неудачные входы.
 *
 * Поэтому заголовок читается только когда соединение действительно пришло от
 * адреса, которому владелец разрешил доверять. Список пуст по умолчанию: без
 * явного решения адрес всегда тот, кто подключился.
 */
/**
 * Адреса, которым можно верить насчёт клиента.
 *
 * Задаётся один раз при разборе конфигурации: подставлять список в
 * конструктор нельзя, объект запроса создаётся и в тестах, и в проверках
 * маршрутов, где конфигурации может не быть вовсе.
 *
 * @var list<string>
 */
private static array $trustedProxies = [];

public static function setTrustedProxies(array $proxies): void
{
    $clean = [];

    foreach ($proxies as $proxy) {
        $proxy = trim((string) $proxy);

        if ($proxy !== '') {
            $clean[] = $proxy;
        }
    }

    self::$trustedProxies = $clean;
}

public function ip(): string
{
    $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

    if (!self::isTrusted($remote, self::$trustedProxies)) {
        return $remote;
    }

    /*
     * В цепочке `X-Forwarded-For` адреса идут от клиента к прокси, а прокси,
     * получив заголовок, дописывает в конец того, кто пришёл к нему. Значит
     * слева стоит то, что клиент написал сам, и доверять этому нельзя: правая
     * часть добавлена тем, кому мы доверяем.
     *
     * Поэтому цепочка читается справа налево: пропускаются адреса, которые сами
     * стоят в списке доверенных (внутренняя цепочка из нескольких прокси), и
     * берётся первый недоверенный. Это и есть адрес человека.
     */
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
        $value = (string) ($this->server[$header] ?? '');

        if ($value === '') {
            continue;
        }

        $chain = array_reverse(array_map('trim', explode(',', $value)));

        foreach ($chain as $candidate) {
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (!self::isTrusted($candidate, self::$trustedProxies)) {
                return $candidate;
            }
        }
    }

    return $remote;
}

/**
 * Сравнение адреса с записью из списка.
 *
 * Запись может быть адресом целиком или сетью: `10.0.0.0/8`. Сравнение строк
 * здесь было бы обманчивым — `10.0.0.1` и `10.0.0.10` начинаются одинаково, и
 * доверие к одному выдалось бы другому.
 */
private static function isTrusted(string $remote, array $proxies): bool
{
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach ($proxies as $proxy) {
        if (str_contains($proxy, '/')) {
            if (self::inSubnet($remote, $proxy)) {
                return true;
            }

            continue;
        }

        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false
            && inet_pton($proxy) === inet_pton($remote)) {
            return true;
        }
    }

    return false;
}

/** Попадает ли адрес в сеть: поддерживаются IPv4 и IPv6. */
private static function inSubnet(string $ip, string $cidr): bool
{
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

    if ($bits === null || !is_numeric($bits)) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton((string) $network);

    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    $rest = $bits % 8;

    if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
        return false;
    }

    if ($rest === 0) {
        return true;
    }

    $mask = chr((0xFF << (8 - $rest)) & 0xFF);

    return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
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