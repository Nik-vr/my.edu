<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Роутер с поддержкой именованных параметров: /tests/{id}/result
 */
final class Router
{
    /** Требуется любой вошедший пользователь. */
    public const AUTH_REQUIRED = '@auth';

    /** Страница доступна только гостю (вход, регистрация). */
    public const GUEST_ONLY = '@guest';

    /** @var list<array{method:string,pattern:string,params:list<string>,handler:callable|string,capability:?string}> */
    private array $routes = [];

    public function get(string $pattern, callable|string $handler, ?string $capability = null): void
    {
        $this->add('GET', $pattern, $handler, $capability);
    }

    public function post(string $pattern, callable|string $handler, ?string $capability = null): void
    {
        $this->add('POST', $pattern, $handler, $capability);
    }

    /**
     * Регистрация произвольным методом.
     *
     * Нужна подключённым плагинам: они объявляют свои маршруты списком и не
     * должны знать про устройство роутера.
     */
    public function addRoute(
        string $method,
        string $pattern,
        callable|string $handler,
        ?string $capability = null
    ): void {
        $this->add($method, $pattern, $handler, $capability);
    }

    private function add(string $method, string $pattern, callable|string $handler, ?string $capability): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => '/' . trim($pattern, '/'),
'params'     => $this->extractParams($pattern),
            'handler'    => $handler,
            'capability' => $capability,
            ];
        }

    /**
     * Вся карта маршрутов в том порядке, в каком объявлена.
     *
     * Порядок — часть карты, а не случайность: разбор берёт первое совпадение,
     * поэтому литеральный адрес обязан идти раньше адреса с `{id}`. Проверка,
     * которая сравнивает карту, обязана видеть именно этот порядок, иначе
     * перестановка `/panel/issues/new` и `/panel/issues/{id}` осталась бы для
     * неё незаметной.
     *
     * Обработчики не отдаются: это живые объекты, и в сравнении карт они дали бы
     * шум вместо пользы. Кто на какой адрес отвечает, видно в самих файлах
     * маршрутов.
     *
     * @return list<array{method:string,pattern:string,params:list<string>,capability:?string}>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method'     => $route['method'],
                'pattern'    => $route['pattern'],
                'params'     => $route['params'],
                'capability' => $route['capability'],
            ],
            $this->routes
        );
    }

    /**
     * @return array{handler:callable|string,params:array<string,string>,capability:?string}|null
     */
    public function match(string $method, string $path): ?array
    {
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (!$this->matchesPath($route, $path)) {
                continue;
            }

            if ($route['method'] !== strtoupper($method)) {
                $pathMatchedOtherMethod = true;
                continue;
            }

            return [
                'handler'    => $route['handler'],
                'params'     => $this->extractValues($route, $path),
                'capability' => $route['capability'],
            ];
        }

        if ($pathMatchedOtherMethod) {
            throw new HttpException(405, 'Метод не поддерживается');
        }

        return null;
    }

    /**
     * @param array{method:string,pattern:string,params:list<string>,handler:callable|string} $route
     */
    private function matchesPath(array $route, string $path): bool
    {
        $regex = '#^' . $this->toRegex($route['pattern']) . '$#u';

        return (bool) preg_match($regex, $path);
    }

    /**
     * @param array{method:string,pattern:string,params:list<string>,handler:callable|string} $route
     * @return array<string,string>
     */
    private function extractValues(array $route, string $path): array
    {
        $values = [];

        if ($route['params'] === []) {
            return $values;
        }

        if (preg_match('#^' . $this->toRegex($route['pattern']) . '$#u', $path, $matches) !== 1) {
            return $values;
        }

        foreach ($route['params'] as $index => $name) {
            $values[$name] = $matches[$index + 1] ?? '';
        }

        return $values;
    }

    private function toRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '#');

        // preg_quote экранирует { }, поэтому восстанавливаем их как плейсхолдеры
        $quoted = str_replace(['\{', '\}'], ['{', '}'], $quoted);

        return (string) preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $quoted);
    }

    /** @return list<string> */
    private function extractParams(string $pattern): array
    {
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $pattern, $matches);

        return $matches[1];
    }
}