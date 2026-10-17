<?php

declare(strict_types=1);

use MyEdu\Core\Database;

/**
 * Общий HTTP-клиент для интеграционных тестов.
 *
 * Cookies хранятся вручную, что позволяет проверять сессии и «сменить
 * браузер» без внешних зависимостей.
 */

final class TestClient
{
    /** @var array<string,string> */
    private array $cookies = [];

    private string $base;

    public function __construct(string $base = 'http://127.0.0.1:8088')
    {
        $this->base = rtrim($base, '/');
    }

    /**
     * Запрос так, как его отправляет скрипт: с признаком AJAX.
     *
     * Отдельный метод, а не необязательный заголовок у post(): признак AJAX
     * меняет ответ сервера — вместо страницы приходят данные, — и проверять
     * это поведение, передавая заголовок вручную, значило бы забыть его в
     * половине проверок.
     *
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     *
     * @return array{status:int,location:string,body:string,headers:list<string>,json:mixed}
     */
    public function postAjax(string $path, array $fields, array $extraHeaders = []): array
    {
        $response = $this->request('POST', $path, $fields, [], array_merge($extraHeaders, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
        ]));

        $response['json'] = json_decode($response['body'], true);

        return $response;
    }

    /**
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     * @param array<string,string>                                    $files
     *        multipart-поля; значение — путь к временному файлу
     *
     * @return array{status:int,location:string,body:string,headers:list<string>}
     */
    public function request(
        string $method,
        string $path,
        array $fields = [],
        array $files = [],
        array $extraHeaders = []
    ): array {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;

        /*
         * Свой Accept заменяет умолчание, а не дополняет его: браузер и fetch
         * присылают ровно один заголовок, и два одинаковых по имени приводят к
         * тому, что сервер видит первый — а проверяли мы второй.
         */
        $headers = ['Accept: text/html'];

        foreach ($extraHeaders as $header) {
            if (stripos($header, 'accept:') === 0) {
                $headers = [];
                break;
            }
        }

        $headers = array_merge($headers, $extraHeaders);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $body = '';

        if ($files !== []) {
            [$body, $contentType] = self::multipart($fields, $files);
            $headers[] = 'Content-Type: ' . $contentType;
            $headers[] = 'Content-Length: ' . strlen($body);
        } else {
            $body = self::encode($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body,
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => 30,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        $status = 0;
        $location = '';
        $setCookies = [];

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }

            if (preg_match('#^Location:\s*(.+)$#i', $header, $m) === 1) {
                $location = trim($m[1]);
                continue;
            }

            if (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $header, $m) === 1) {
                $name = trim($m[1]);
                $value = trim($m[2]);
                $setCookies[] = $name . '=' . $value;
                $this->cookies[$name] = $value;
            }
        }

        return [
            'status'     => $status,
            'location'   => $location,
            'body'       => (string) $content,
            'headers'    => $http_response_header ?? [],
        ];
    }

    /**
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * GET с требованием отдать данные.
     *
     * Так спрашивает скрипт, открывающий окно редактора: сервер должен ответить
     * разметкой формы, а не страницей. Заголовок здесь — не украшение, а часть
     * проверки: без него тот же адрес вернул бы страницу, и ошибка была бы
     * неочевидна.
     *
     * @param list<string> $extraHeaders
     */
    public function getAjax(string $path, array $extraHeaders = []): array
    {
        $response = $this->request('GET', $path, [], [], array_merge(
            ['Accept: application/json'],
            $extraHeaders
        ));

        $response['json'] = json_decode($response['body'], true);

        return $response;
    }

    /**
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     * @param list<string>                                          $extraHeaders
     */
    public function post(string $path, array $fields, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $fields, [], $extraHeaders);
    }

    /**
     * @param array<string,string> $fields
     * @param array<string,string> $files
     */
    public function upload(string $path, array $fields, array $files): array
    {
        return $this->request('POST', $path, $fields, $files);
    }

    /** Забыть сессию — эмулирует другой браузер. */
    public function forgetCookies(): void
    {
        $this->cookies = [];
    }

    /**
     * Значение поля формы по имени.
     */
    public function token(string $html): ?string
    {
        return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * Токен со страницы, полученной ранее.
     */
    public function tokenFrom(array $response): ?string
    {
        return $this->token($response['body']);
    }

/**
 * Кодирование полей формы.
 *
 * Значение-массив разворачивается в несколько одноимённых полей — так браузер
 * отправляет набор флажков (roles[]=admin&roles[]=teacher), и без этого
 * сервер получил бы только последнее значение.
 *
 * @param array<string,mixed>|list<array{0:string,1:string}> $fields
 */
private static function encode(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $name => $value) {
            // Явная пара [имя, значение] — для повторяющихся имён.
            if (is_int($name)) {
                $pairs[] = rawurlencode((string) $value[0]) . '=' . rawurlencode((string) $value[1]);
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
                }

                continue;
            }

            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * Сборка multipart/form-data вручную.
     *
     * @param array<string,string> $fields
     * @param array<string,string> $files
     *
     * @return array{0:string,1:string}
     */
    private static function multipart(array $fields, array $files): array
    {
        $boundary = '----myedu' . bin2hex(random_bytes(8));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                . $value . "\r\n";
        }

        foreach ($files as $name => $path) {
            $filename = basename($path);
            $content = (string) @file_get_contents($path);

            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: text/csv\r\n\r\n"
                . $content . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }
}

/**
 * Проверка, что разрушительный тест запущен на отдельной базе.
 *
 * Наборы чистят пользователей и классы, перезаписывают пароли и переключают
 * состояние плагинов. На рабочей базе это портит настоящие данные, поэтому
 * запуск в ней запрещён: имя базы должно содержать «test». Отключается
 * переменной MYEDU_ALLOW_DESTRUCTIVE=1 — нужно осознанно, при отладке.
 */
function assertTestDatabase(Database $db): void
{
    $name = (string) $db->value('SELECT DATABASE()');

    if (str_contains(strtolower($name), 'test')) {
        return;
    }

    if (getenv('MYEDU_ALLOW_DESTRUCTIVE') === '1') {
        fwrite(STDOUT, "ВНИМАНИЕ: тест меняет данные в рабочей базе {$name}.\n");
        return;
    }

    /*
     * Без перечисления таблиц: страховка стоит у наборов с разным набором
     * правок, и текст «удаляет пользователей и классы» увёл бы читателя туда,
     * где правок нет вовсе.
     */
    fwrite(
        STDERR,
        "ОТКАЗ: этот тест меняет данные, а база называется «{$name}».\n"
        . "Запустите сервер и тест с MYEDU_DB_NAME=my_edu_review_test,\n"
        . "либо поставьте MYEDU_ALLOW_DESTRUCTIVE=1, если правки нужны.\n"
    );

    exit(2);
}

/**
 * Раннер тестов с группами и итогом.
 */
final class TestRunner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public function is(bool $ok, string $description): void
    {
        if ($ok) {
            $this->passed++;
            echo "  \033[32m+\033[0m {$description}\n";
            return;
        }

        $this->failures[] = $this->group . ' → ' . $description;
        echo "  \033[31m-\033[0m {$description}\033[0m\n";
    }

    public function equals(mixed $expected, mixed $actual, string $description): void
    {
        $ok = $expected === $actual;

        if (!$ok) {
            $description .= sprintf(
                ' (ожидалось %s, получено %s)',
                var_export($expected, true),
                var_export($actual, true)
            );
        }

        $this->is($ok, $description);
    }

    /**
     * Числа сравниваются с допуском: округление до сотых даёт расхождения
     * в последнем разряде, и это не ошибка, а округление.
     */
    public function near(float $expected, float $actual, string $description): void
    {
        $ok = abs($actual - $expected) < 0.02;

        $this->is(
            $ok,
            $description . ($ok ? '' : sprintf(' (ожидалось %s, получено %s)', $expected, $actual))
        );
    }

    public function contains(string $haystack, string $needle, string $description): void
    {
        $this->is(str_contains($haystack, $needle), $description);
    }

    public function notContains(string $haystack, string $needle, string $description): void
    {
        $this->is(!str_contains($haystack, $needle), $description);
    }

    /**
     * Проверка, что код отверг настройки и объяснил почему.
     *
     * Проверяется именно класс исключения: молчаливое принятие неверной
     * настройки хуже явной ошибки — ошибку заметят, а молчание приведёт к
     * тому, что вопрос будет проверяться не так, как задумано.
     */
    public function throws(callable $callback, string $expectedClass, string $description): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->is(
                $e instanceof $expectedClass,
                $description . ($e instanceof $expectedClass ? '' : ' (получено ' . $e::class . ')')
            );

            return;
        }

        $this->is(false, $description . ' (исключения не было)');
    }

    public function report(): int
    {
        $total = $this->passed + count($this->failures);

        echo "\n" . str_repeat('-', 62) . "\n";

        if ($this->failures === []) {
            echo "\033[32mВсе проверки пройдены: {$this->passed}/{$total}\033[0m\n";
            return 0;
        }

        echo "\033[31mПровалено: " . count($this->failures) . "/{$total}\033[0m\n";
        foreach ($this->failures as $failure) {
            echo "  - {$failure}\n";
        }

        return 1;
    }
}