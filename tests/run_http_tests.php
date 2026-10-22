<?php

declare(strict_types=1);

/**
 * Интеграционные тесты веб-слоя: реальные HTTP-запросы к запущенному серверу.
 *
 * Запуск:
 *   1) запустить тестовый сервер:     powershell -File tools\dev-server.ps1 -ForTests
 *   2) выполнить тесты:                php tests/run_http_tests.php http://127.0.0.1:8098
 *
 * Проверяется то, что не ловится модульными тестами: роутинг, CSRF,
 * разграничение доступа по токену попытки, постраничное прохождение с
 * сохранением ответов и полный цикл проверки работы.
 *
 * Cookies обрабатываются вручную, поэтому скрипту не нужны ни curl,
 * ни сторонние расширения.
 *
 * С этапа 2 тест доступен только вошедшему ученику, поэтому скрипт
 * создаёт собственного ученика и входит под ним.
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

/*
 * Подключается ради assertTestDatabase(). Свой раннер и свой HTTP-клиент у
 * этого набора остались (см. замечания ревью), но страховку рабочей базы
 * дублировать нельзя: одна копия рано или поздно разойдётся со второй и
 * перестанет защищать.
 */
require_once $rootDir . '/tests/support/TestKit.php';

use MyEdu\Auth\Role;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Database;
use MyEdu\Core\MailerFactory;
use MyEdu\Core\Request;
use MyEdu\Core\Router;
use MyEdu\Http\Controller\HealthController;
use MyEdu\Http\Routes\AuthRoutes;
use MyEdu\Http\Routes\Controllers;
use MyEdu\Http\Routes\PanelRoutes;
use MyEdu\Http\Routes\PublicRoutes;
use MyEdu\Http\Routes\RouteFile;
use MyEdu\Http\Routes\SchoolRoutes;
use MyEdu\Http\Routes\WorkRoutes;

final class UnavailableHealthStatement extends PDOStatement
{
    public function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        throw new PDOException('private connection detail');
    }
}

final class UnavailableHealthPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new UnavailableHealthStatement();
    }
}

/*
 * Скрипт заводит ученика, поэтому база обязана совпадать с той, на которой
 * работает сервер под тестами: tools\dev-server.ps1 -ForTests поднимает
 * MYEDU_DB_NAME из среды запуска. Рабочая база не затрагивается.
 */
if (getenv('MYEDU_DB_NAME') === false || getenv('MYEDU_DB_NAME') === '') {
    putenv('MYEDU_DB_NAME=my_edu_review_test');
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8098', '/');

/** Логин и пароль тестового ученика. */
$studentEmail = 'uchebka@example.edu';
$studentPassword = 'uchebka2025';

/**
 * HTTP-клиент с собственной банкой cookie.
 */
final class Client
{
    /** @var array<string,string> */
    private array $cookies = [];

    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /**
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     *        Ассоциативный массив или список пар. Списком передаются
     *        повторяющиеся имена (answers[7][]=a&answers[7][]=c),
     *        как их отправляет браузер с несколькими флажками.
     *
     * @return array{status:int,location:string,body:string,setCookies:list<string>}
     */
    public function request(string $method, string $path, array $fields = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $body = '';

        $headers = ['Accept: text/html'];

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        if ($fields !== [] || $method === 'POST') {
            $body = self::encode($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $body,
                // Редиректы разбираем сами, иначе не видно Location.
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => 20,
            ],
        ]);

        $responseHeaders = [];

        $content = @file_get_contents($url, false, $context);

        if (isset($http_response_header)) {
            $responseHeaders = $http_response_header;
        }

        $status = 0;
        $location = '';
        $setCookies = [];
        $raw = [];

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }

            if (preg_match('#^([^:]+):\s*(.*)$#', $header, $m) === 1) {
                $raw[strtolower(trim($m[1]))] = trim($m[2]);
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
            'body'       => $content === false ? '' : $content,
            'setCookies' => $setCookies,
            'headers'    => $raw,
        ];
    }

    /** @param array<string,string>|list<array{0:string,1:string}> $fields */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,string>|list<array{0:string,1:string}> $fields */
    public function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    /**
     * CSRF-токен со страницы-ответа.
     *
     * Повторяет одноимённый метод общего клиента набора. Здесь набор держит
     * свою копию клиента (она умеет повторяющиеся имена так, как их шлёт
     * браузер), но держать ещё и расхождение в способе доставать токен незачем:
     * одинаковое имя метода читается ожидаемо, а ''.$field.'' в каждой строке
     * выглядело бы как писаный от руки разбор.
     */
    public function tokenFrom(array $response): ?string
    {
        return preg_match('/name="_token"\s+value="([^"]+)"/', $response['body'], $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * @param array<string,string>|list<array{0:string,1:string}> $fields
     */
    private static function encode(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $name => $value) {
            if (is_int($name)) {
                $pairs[] = rawurlencode((string) $value[0]) . '=' . rawurlencode((string) $value[1]);
                continue;
            }

            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }
}

// ------------------------------------------------------------------ раннер

final class Runner
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

    /**
     * Признак в тексте страницы.
     *
     * Отдельный метод, а не просьба вида is(str_contains(...)): «на странице
     * видно то-то» — самая частая проверка здесь, и без неё каждая такая
     * строка выглядела бы как утверждение о синтаксисе строк, а не о странице.
     */
    public function contains(string $haystack, string $needle, string $description): void
    {
        $this->is(str_contains($haystack, $needle), $description);
    }

    /**
     * Признака в тексте быть не должно.
     *
     * Отдельный метод по той же причине, что и `contains`: «на странице нет
     * такого-то» — утверждение о странице, и читаться оно должно как
     * утверждение, а не как проверка строкового выражения.
     */
    public function notContains(string $haystack, string $needle, string $description): void
    {
        $this->is(!str_contains($haystack, $needle), $description);
    }

    /**
     * Точное совпадение, а с расхождением — в тексте проверки.
     *
     * Значения подставляются через var_export: без них провал выглядел бы как
     * «проверка не прошла» без следа, и искать расхождение пришлось бы заново
     * тем же запросом.
     */
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

$t = new Runner();

/** Достаёт значение поля формы по имени. */
$field = static function (string $html, string $name): ?string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) === 1
        ? $m[1]
        : null;
};

/**
 * Разбирает страницу задания на текущий вопрос и список заданий.
 *
 * @return array{qid:int,type:string,body:string,nav:list<array{number:int,answered:bool}>,total:int,current:int}
 */
$parsePage = static function (string $html): array {
    preg_match('/data-question-id="(\d+)" data-type="(\w+)"/', $html, $q);
    preg_match('#class="attempt__progress"[^>]*>.*?(\d+)\s*из\s*(\d+)#s', $html, $p);
    preg_match_all('/<button[^>]*name="goto"[^>]*value="(\d+)"[^>]*class="qnav__item([^"]*)"/s', $html, $navItems, PREG_SET_ORDER);

    $nav = [];

    foreach ($navItems as $item) {
        $nav[] = [
            'number'   => (int) $item[1],
            'answered' => str_contains($item[2], 'is-answered'),
        ];
    }

    return [
        'qid'     => (int) ($q[1] ?? 0),
        'type'    => $q[2] ?? '',
        'body'    => $html,
        'nav'     => $nav,
        'total'   => (int) ($p[2] ?? 0),
        'current' => (int) ($p[1] ?? 0),
    ];
};

$client = new Client($baseUrl);

// ------------------------------------------------------------------ ученик

/*
 * Прохождение теста доступно только вошедшему ученику, поэтому создаём
 * собственного ученика с ролью student и входим под ним.
 */
$t->group('Вход ученика');

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

/*
 * Набор перезаписывает пароль существующего пользователя (ниже) и правит
 * состояние плагина, поэтому ему нужна та же страховка, что и чистящим
 * наборам. Раньше проверки здесь не было, и ручной запуск этого файла без
 * MYEDU_DB_NAME переписал бы пароль в рабочей базе.
 */
assertTestDatabase($db);

$users = new UserRepository($db);

$studentRow = $users->findRowByIdentifier($studentEmail);

if ($studentRow === null) {
    $users->create([
        'login'          => $studentEmail,
        'email'          => $studentEmail,
        'password_hash'  => password_hash($studentPassword, PASSWORD_DEFAULT),
        'full_name'      => 'Петров Иван',
        'is_owner'       => 0,
        'status'         => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $studentRow = $users->findRowByIdentifier($studentEmail);
    $users->setRole((int) $studentRow['id'], Role::STUDENT);
} else {
    $db->run(
        "UPDATE users SET password_hash = :hash, status = 'active', email_verified_at = NOW() WHERE id = :id",
        ['hash' => password_hash($studentPassword, PASSWORD_DEFAULT), 'id' => (int) $studentRow['id']]
    );

    $users->setRole((int) $studentRow['id'], Role::STUDENT);
}

/*
 * Демо-работа выдаётся ученику лично.
 *
 * Засев выдаёт её тем, кто уже был в классе к его моменту, а этот ученик
 * создаётся позже — и без выдачи список работ у него был бы пуст, и проверка
 * дальше проверяла бы не прохождение работы, а ничего.
 */
$demoWorkTitle = 'Демонстрационная работа: наука о природе';

$demoWorkId = (int) ($db->value('SELECT id FROM assignments WHERE title = :t', ['t' => $demoWorkTitle]) ?? 0);

$t->is($demoWorkId > 0, 'демо-работа создана');

if ($demoWorkId > 0) {
    $studentId = (int) $studentRow['id'];

    $alreadyIssued = (int) $db->value(
        'SELECT COUNT(*)
           FROM assignment_issue_students s
           JOIN assignment_issues i ON i.id = s.issue_id
          WHERE i.assignment_id = :a AND s.student_id = :u',
        ['a' => $demoWorkId, 'u' => $studentId]
    );

    if ($alreadyIssued === 0) {
        $issueId = $db->insert('assignment_issues', [
            'assignment_id'   => $demoWorkId,
            'author_id'       => (int) ($db->value('SELECT author_id FROM assignments WHERE id = :id', ['id' => $demoWorkId]) ?? 0),
            'available_from'  => null,
            'available_until' => null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $db->run(
            'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
            . ' VALUES (:i, :u, NULL, :t)',
            ['i' => $issueId, 'u' => $studentId, 't' => date('Y-m-d H:i:s')]
        );
    }

    $t->is((int) $db->value(
        'SELECT COUNT(*)
           FROM assignment_issue_students s
           JOIN assignment_issues i ON i.id = s.issue_id
          WHERE i.assignment_id = :a AND s.student_id = :u',
        ['a' => $demoWorkId, 'u' => $studentId]
    ) === 1, 'демо-работа выдана ученику');
}

$guestClient = new Client($baseUrl);
$guestTestPage = $guestClient->get('/tests/1');
$t->is($guestTestPage['status'] === 302 && str_contains($guestTestPage['location'], '/login'),
    'гость не может открыть тест');

$loginPage = $client->get('/login');
$login = $client->post('/login', [
    '_token'     => $field($loginPage['body'], '_token') ?? '',
    'identifier' => $studentEmail,
    'password'   => $studentPassword,
]);

$t->is($login['status'] === 302, 'ученик вошёл в систему');
$t->is($client->get('/portal')['status'] === 200, 'личный кабинет ученика открыт');

// ------------------------------------------------------------------ доступность

$t->group('Служебные страницы');

$health = $client->get('/health');
$t->is($health['status'] === 200, 'GET /health отвечает 200');

$healthData = json_decode($health['body'], true);
$t->is(is_array($healthData) && ($healthData['database'] ?? '') === 'подключено', 'база данных подключена');
$t->is(is_array($healthData) && isset($healthData['database_name']), 'локальная проверка содержит имя базы');
$t->is(is_array($healthData) && isset($healthData['users']), 'локальная проверка содержит счётчик пользователей');

$loopbackHealth = new Request([], [], [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/health',
    'REMOTE_ADDR' => '127.0.0.1',
]);
$t->is($loopbackHealth->isLoopback(), 'IPv4 loopback распознаётся как локальный');

$ipv6LoopbackHealth = new Request([], [], [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/health',
    'REMOTE_ADDR' => '::1',
]);
$t->is($ipv6LoopbackHealth->isLoopback(), 'IPv6 loopback распознаётся как локальный');

$publicHealth = new Request([], [], [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/health',
    'REMOTE_ADDR' => '203.0.113.10',
]);
$t->is(!$publicHealth->isLoopback(), 'внешний адрес не считается локальным для подробной диагностики');

/*
 * Срез базового пути.
 *
 * Сайт на шаред-хостинге открывают по адресу /public/, и без среза этого
 * префикса роутер искал бы маршрут «/public/login» и отвечал 404 собственной
 * страницей. Наоборот, префикс не должен срезаться у похожих адресов:
 * /publications — это другой адрес, а не раздел внутри public.
 */
$t->group('Базовый путь вырезается из адреса');

$basePathCases = [
    ['/public/login', '/public', '/login'],
    ['/public/', '/public', '/'],
    ['/public', '/public', '/'],
    ['/public/panel/users', '/public', '/panel/users'],
    ['/login', '/public', '/login'],
    ['/publications', '/public', '/publications'],
    ['/login', '', '/login'],
    ['/', '', '/'],
];

foreach ($basePathCases as [$uri, $base, $expected]) {
    $caseRequest = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI'    => $uri,
    ], [], $base);

    $t->is(
        $caseRequest->path() === $expected,
        sprintf('адрес %s при базе «%s» даёт %s', $uri, $base === '' ? 'корень' : $base, $expected)
    );
}
$healthController = new HealthController(
    $app,
    $db,
    $app->get(Config::class),
    new MailerFactory($app->get(Config::class), $rootDir . '/var/log')
);
$publicHealthResponse = $healthController->show($publicHealth);
$publicHealthData = json_decode($publicHealthResponse->body(), true);
$t->is($publicHealthResponse->status() === 200, 'внешняя проверка состояния отвечает 200 при доступной базе');
$t->is($publicHealthData === ['status' => 'ok'], 'внешняя проверка отдаёт только безопасный статус');

$unavailableDbReflection = new ReflectionClass(Database::class);
$unavailableDb = $unavailableDbReflection->newInstanceWithoutConstructor();
$pdoProperty = $unavailableDbReflection->getProperty('pdo');
$pdoProperty->setValue($unavailableDb, new UnavailableHealthPdo());
$unavailableHealthResponse = (new HealthController(
    $app,
    $unavailableDb,
    $app->get(Config::class),
    new MailerFactory($app->get(Config::class), $rootDir . '/var/log')
))->show($publicHealth);
$unavailableHealthData = json_decode($unavailableHealthResponse->body(), true);
$t->is($unavailableHealthResponse->status() === 503, 'внешняя проверка отвечает 503 при недоступной базе');
$t->is($unavailableHealthData === ['status' => 'unavailable'], 'ошибка базы с внешнего адреса не раскрывает детали');

/*
 * Режим "never" — для площадок за прокси.
 *
 * Там запрос к PHP приходит с 127.0.0.1, и проверка по адресу считает его
 * локальным: версия PHP, имя базы и число пользователей ушли бы наружу.
 * Поэтому режим запрещает подробности независимо от адреса, и именно его
 * проверяют на локальном адресе — иначе ошибка была бы незаметна ровно там,
 * где опаснее всего.
 */
$neverConfig = new Config(['app' => ['health_public_details' => 'never']]);

$neverDetailsResponse = (new HealthController(
    $app,
    $db,
    $neverConfig,
    new MailerFactory($app->get(Config::class), $rootDir . '/var/log')
))->show($loopbackHealth);
$neverDetailsData = json_decode($neverDetailsResponse->body(), true);

$t->is($neverDetailsResponse->status() === 200, 'при "never" проверка состояния продолжает работать');
$t->is($neverDetailsData === ['status' => 'ok'], 'при "never" локальный запрос тоже не получает подробностей');

$neverUnavailableResponse = (new HealthController(
    $app,
    $unavailableDb,
    $neverConfig,
    new MailerFactory($app->get(Config::class), $rootDir . '/var/log')
))->show($loopbackHealth);
$neverUnavailableData = json_decode($neverUnavailableResponse->body(), true);

$t->is($neverUnavailableResponse->status() === 503, 'при "never" недоступная база всё равно видна как 503');
$t->is($neverUnavailableData === ['status' => 'unavailable'], 'при "never" текст ошибки базы не раскрывается локальному запросу');

// Без явного значения остаётся умолчание с подробностями для локального.
$defaultDetailsConfig = new Config([]);

$t->is(
    $defaultDetailsConfig->string('app.health_public_details', 'local') !== 'never',
    'умолчание не превращает локальную проверку в слепую'
);

$notFound = $client->get('/nesushchestvuyushchiy-adres');
$t->is($notFound['status'] === 404, 'неизвестный маршрут отдаёт 404');

/*
 * Страница ошибки — обычная карточка.
 *
 * В классе был лишний пробел: `class="card "`. На вид это ничего не меняет,
 * но класс становится другим строкой, и правило, написанное на `.card` через
 * совпадение по классу целиком, перестаёт срабатывать — а визуально страница
 * остаётся прежней, и поломку видно только в отладчике.
 *
 * Намеренно ищется именно пробел: опечатку в разметке должен ловить прогон,
 * а не просмотр исходника.
 */
$t->notContains((string) $notFound['body'], 'class="card "',
    'в классе карточки на странице ошибки нет лишнего пробела');
$t->contains((string) $notFound['body'], '<div class="card">',
    'страница ошибки обёрнута в обычную карточку');

// --------------------------------------------------------- прохождение работы

/*
 * Дальше идёт прохождение работы по HTTP, тем же путём, каким пойдёт ученик:
 * список работ, старт попытки, задание, ответ, сдача.
 *
 * Раньше здесь стоял прогон самостоятельного теста — с отсчитыванием номера
 * из ссылки на главной и адресами /tests/{id}/start. Таких адресов больше
 * нет, и заменить их нечем было: проверять там было нечего.
 */

$t->group('Список работ');

$worksList = $client->get('/works');

$t->is($worksList['status'] === 200, 'список работ открыт ученику');
$t->contains($worksList['body'], 'Демонстрационная работа', 'выданная работа видна ученику');

preg_match('#href="/work/(\d+)"#', $worksList['body'], $workMatch);
$demoWorkId = (int) ($workMatch[1] ?? 0);

$t->is($demoWorkId > 0, 'у работы есть номер');

$workPage = $client->get('/work/' . $demoWorkId);

$t->is($workPage['status'] === 200, 'страница работы открыта');
$t->contains($workPage['body'], 'Какая планета', 'видно первое задание работы');

// ------------------------------------------------------------------ CSRF

$t->group('Защита от подделки запроса (CSRF)');

$t->is($client->post('/work/' . $demoWorkId . '/start', [])['status'] === 419,
    'старт без CSRF-токена отклоняется (419)');

$t->is($client->post('/work/' . $demoWorkId . '/start', [
    '_token' => str_repeat('0', 64),
])['status'] === 419, 'старт с неверным CSRF-токеном отклоняется (419)');

// ------------------------------------------------------------ начало работы

$t->group('Начало работы');

$startPage = $client->get('/work/' . $demoWorkId);
$start = $client->post('/work/' . $demoWorkId . '/start', [
    '_token' => $client->tokenFrom($startPage) ?? '',
]);

$t->is($start['status'] === 302, 'старт работы перенаправляет (302)');
$t->is(str_contains($start['location'], '/work/') && str_contains($start['location'], '/item/'),
    'редирект ведёт на первое задание');

preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', $start['location'], $m);

$attemptId = (int) ($m[1] ?? 0);
$firstItemId = (int) ($m[2] ?? 0);
$attemptToken = (string) ($m[3] ?? '');

$t->is($attemptId > 0 && $firstItemId > 0 && $attemptToken !== '', 'в адресе есть попытка, задание и токен');

/*
 * Попытка защищена токеном. Без него по номеру чужую работу не открыть, и
 * это проверяется здесь же, на настоящем HTTP-запросе, а не по коду.
 */
$t->is($client->get('/work/' . $attemptId . '/item/' . $firstItemId)['status'] === 404,
    'задание без токена недоступно');

$t->is($client->get('/work/' . $attemptId . '/item/' . $firstItemId . '?t=' . str_repeat('0', 64))['status'] === 404,
    'с чужим токеном недоступно');

// ------------------------------------------------------------- ответ на задание

$t->group('Ответ на задание');

$itemUrl = '/work/' . $attemptId . '/item/' . $firstItemId;
$itemPage = $client->get($itemUrl . '?t=' . $attemptToken);

$t->is($itemPage['status'] === 200, 'страница задания отвечает 200');
$t->contains($itemPage['body'], 'Какая планета', 'виден текст вопроса');

/*
 * Форма не должна запираться браузером.
 *
 * Раньше это стояло здесь же, для самостоятельного теста: без скриптов и без
 * поддержки новых полей форма обязана оставаться отправляемой. Проверяется
 * отправкой, а не чтением разметки.
 *
 * Имя поля — answers[номер вопроса], а не answers[номер задания]: у работы
 * несколько заданий, и вопросы внутри каждого нумеруются заново.
 */
$questionId = $parsePage($itemPage['body'])['qid'];
$answerField = 'answers[' . $questionId . ']';

/** Отмечен ли переключатель с указанным значением. Порядок атрибутов не важен. */
$isChecked = static function (string $html, string $field, string $value): bool {
    if (preg_match_all('#<input\b[^>]*>#i', $html, $inputs) === false) {
        return false;
    }

    foreach ($inputs[0] as $input) {
        if (preg_match('/name="' . preg_quote($field, '/') . '"/', $input) !== 1) {
            continue;
        }

        if (preg_match('/value="' . preg_quote($value, '/') . '"/', $input) !== 1) {
            continue;
        }

        return preg_match('/\bchecked\b/', $input) === 1;
    }

    return false;
};

$t->is($questionId > 0, 'в задании виден номер вопроса');

$saved = $client->post($itemUrl, [
    '_token' => $client->tokenFrom($itemPage) ?? '',
    't' => $attemptToken,
    'answer_present[' . $questionId . ']' => '1',
    $answerField => 'b',
]);

$t->is($saved['status'] === 302, 'ответ принимается и возвращает на страницу');

$reloaded = $client->get($itemUrl . '?t=' . $attemptToken);

$t->is($reloaded['status'] === 200, 'после сохранения страница открывается заново');

$t->is($isChecked($reloaded['body'], $answerField, 'b'), 'ответ сохранился и виден при перезагрузке');

// Снятие ответа: без него ученик не смог бы исправить ошибочный выбор.
$cleared = $client->post($itemUrl, [
    '_token' => $client->tokenFrom($reloaded) ?? '',
    't' => $attemptToken,
    'answer_present[' . $questionId . ']' => '1',
]);

$t->is($cleared['status'] === 302, 'очистка принята сервером');

$afterClear = $client->get($itemUrl . '?t=' . $attemptToken);

$t->is(!$isChecked($afterClear['body'], $answerField, 'b'), 'снятый ответ больше не отмечен');

// ------------------------------------------------------------- последовательность

$t->group('Правильная последовательность');

/*
 * Перетаскивание держится на трёх вещах: список помечен, библиотека
 * подключена и у формы есть идентификатор, с которого начинает работу скрипт.
 *
 * Раньше проверок не было ни на одну из них, и страница выглядела исправной,
 * когда перетаскивание молча не работало: без #attempt-form скрипт выходил на
 * второй строке, и молчали отключались вместе с ним перетаскивание, таймер и
 * защита от повторной отправки.
 */
$orderingItemId = (int) $db->value(
    "SELECT i.id FROM assignment_items i
       JOIN questions q ON q.test_id = i.task_id AND q.type = 'ordering'
      WHERE i.assignment_id = (SELECT id FROM assignments WHERE title = 'Демонстрационная работа: наука о природе' LIMIT 1)
      ORDER BY i.position LIMIT 1"
);

$t->is($orderingItemId > 0, 'в демонстрационной работе есть задание с последовательностью');

if ($orderingItemId > 0) {
    $orderingUrl = '/work/' . $attemptId . '/item/' . $orderingItemId;
    $orderingPage = $client->get($orderingUrl . '?t=' . $attemptToken);

    $t->is($orderingPage['status'] === 200, 'страница последовательности открыта');
    $t->contains($orderingPage['body'], 'id="attempt-form"', 'у формы задания есть идентификатор для скрипта');
    $t->contains($orderingPage['body'], 'data-ordering-list', 'список последовательности помечен для скрипта');
    $t->contains($orderingPage['body'], 'data-ordering-item', 'элементы последовательности помечены');
    $t->contains($orderingPage['body'], 'ordering__handle', 'у элемента есть ручка перетаскивания');
    $t->contains($orderingPage['body'], 'sortable.min.js', 'библиотека перетаскивания подключена');
    $t->contains($orderingPage['body'], 'attempt.js', 'скрипт прохождения подключен');

    // Списки позиций при этом остаются в разметке: без скриптов задание
    // решается выбором позиции вручную, и ученик не остаётся без ответа.
    $t->contains($orderingPage['body'], 'data-ordering-select', 'есть запасной выбор позиции без перетаскивания');
}

// ------------------------------------------------------------- заголовки и CSP

$t->group('Заголовки безопасности');

/*
 * Заголовки ставит приложение, а не веб-сервер.
 *
 * Раньше они жили только в public/.htaccess: под Apache работали, под nginx
 * — документированным способом развёртывания — молча пропадали. Проверяется
 * по ответу, а не по файлу конфигурации: то, что не уходит клиенту, не
 * защищает, сколько бы ни лежало в конфиге.
 */
/*
 * Заголовки проверяются гостем, а не вошедшим учеником: на /login после входа
 * идёт редирект, и проверка смотрела бы не страницу, а отказ.
 */
$guardPage = $guestClient->get('/login');
$guardHeaders = $guardPage['headers'] ?? [];

$t->is($guardPage['status'] === 200, 'страница входа отвечает');
$t->is(($guardHeaders['x-content-type-options'] ?? '') === 'nosniff', 'задан X-Content-Type-Options');
$t->is(($guardHeaders['x-frame-options'] ?? '') === 'SAMEORIGIN', 'задан X-Frame-Options');
$t->is(($guardHeaders['referrer-policy'] ?? '') === 'same-origin', 'задан Referrer-Policy');

$csp = $guardHeaders['content-security-policy'] ?? '';

$t->is($csp !== '', 'задана Content-Security-Policy');
$t->is(!str_contains($csp, 'unsafe-inline'), 'в политике нет unsafe-inline');
$t->is(!str_contains($csp, 'unsafe-eval'), 'в политике нет unsafe-eval');

/*
 * Политика без unsafe-inline держится только пока в разметке нет инлайн-кода.
 * Возвращать его нельзя: страница станет выглядеть исправной и молча
 * развалится под политикой — скрипты просто не выполнятся.
 */
foreach (['/login', '/works'] as $cspPath) {
    $cspBody = (string) ($guestClient->get($cspPath)['body'] ?? '');

    $t->is(preg_match('/<script(?![^>]*\bsrc=)/i', $cspBody) === 0, 'на ' . $cspPath . ' нет инлайн-скриптов');
    $t->is(preg_match('/\son(?:click|change|submit|load|input)\s*=/i', $cspBody) === 0, 'на ' . $cspPath . ' нет инлайн-обработчиков');
}

// ------------------------------------------------- диагностика и установщик

/*
 * Страница диагностики входа и почты (`check_login.php`) показывает состояние
 * учётной записи по её адресу и умеет отправлять письмо, поэтому она не должна
 * открываться «просто так».
 *
 * Раньше ключ проверялся только на наличие параметра: `&key=1` открывал страницу
 * целиком. Теперь ключ сверяется с секретом из конфигурации, и обе проверки ниже
 * закрывают именно тот случай — «параметр есть, значения нет».
 */
$t->group('Диагностика входа не открыта без настоящего ключа');

$noKey = $client->get('/check_login.php');
$t->equals(403, $noKey['status'], 'страница диагностики без ключа не отвечает');

$wrongKey = $client->get('/check_login.php?key=1');
$t->equals(403, $wrongKey['status'], 'страница диагностики с чужим ключом не отвечает');

$emptyKey = $client->get('/check_login.php?key=');
$t->equals(403, $emptyKey['status'], 'пустой ключ секретом не считается');

// Проверяется и сам код страницы: ключ обязан сверяться, а не просто спрашиваться,
// и из обычного запроса ничего не применяться.
$checkSource = (string) file_get_contents($rootDir . '/public/check_login.php');

$t->contains(
    $checkSource,
    'hash_equals($expectedKey, $givenKey)',
    'ключ диагностики сверяется через hash_equals'
);

$t->notContains(
    $checkSource,
    'SchemaMigrator(' . "\n"
        . '        $db',
    'страница диагностики не применяет миграции'
);

$t->notContains(
    $checkSource,
    '->apply()',
    'из диагностики не вызывается применение миграций'
);

$t->contains(
    $checkSource,
    "(\$_GET['send'] ?? '') === '1'",
    'проверочное письмо отправляется только по явному &send=1'
);

/*
 * Установщик базы доступен по адресу, который владелец публикует один раз и
 * забывает удалить. Раньше страница открывалась всем и показывала имена
 * таблиц базы и текст ошибок от сервера MySQL, где есть имя базы, логин и путь
 * к хостингу.
 */
$t->group('Установщик базы закрыт ключом и не раскрывает детали');

$installer = $client->get('/install.php');
$t->equals(403, $installer['status'], 'установщик без ключа не отвечает');

$installerWrong = $client->get('/install.php?key=1');
$t->equals(403, $installerWrong['status'], 'установщик с чужим ключом не отвечает');

$installSource = (string) file_get_contents($rootDir . '/public/install.php');

$t->contains(
    $installSource,
    'hash_equals($expectedKey, $givenKey)',
    'ключ установки сверяется через hash_equals'
);

$t->notContains(
    $installSource,
    "'First: <code>'",
    'установщик не перечисляет имена таблиц базы'
);

$t->notContains(
    $installSource,
    'count($tables)',
    'установщик не считает таблицы в отчёте'
);

$t->contains(
    $installSource,
    'function databaseError(',
    'текст ошибки сервера базы выводится через отдельную функцию'
);

// Ошибка базы наружу не показывается при выключенной отладке: иначе страница
// доступна из интернета и отдаёт имя базы вместе с логином.
$t->is(
    preg_match(
        '/if \(\$debug\) \{\s*return .*getMessage\(\).*?\}\s*return/s',
        $installSource
    ) === 1,
    'подробности ошибки базы показываются только при включённой отладке'
);

// ------------------------------------------------- адреса, которые нельзя закрывать

$t->group('Публичные адреса не перекрыты запретом в .htaccess');

/*
 * Адреса плагинов живут в корне сайта, а служебные каталоги — рядом с ними.
 * Закрыв каталог целиком, можно закрыть и публичный адрес: /tests/... это не
 * каталог на сервере, а префикс плагина «Тест», и /tests/task/{id} принимает
 * сохранение задания.
 *
 * Ошибка выглядит как «Сохранить не работает»: запрос не доходит до PHP, и
 * правка теряется. Встроенный сервер проверок .htaccess не читает, поэтому
 * на интеграционных наборах это не поймать — проверяется сам файл.
 */
$htaccessFiles = [
    $rootDir . '/public/.htaccess',
    $rootDir . '/.htaccess',
];

$pluginPrefixes = [];

foreach ((glob($rootDir . '/plugins/*/plugin.json') ?: []) as $manifest) {
    $data = json_decode((string) @file_get_contents($manifest), true);
    $prefix = (string) ($data['url_prefix'] ?? $data['id'] ?? '');

    if ($prefix !== '') {
        $pluginPrefixes[] = $prefix;
    }
}

$t->is($pluginPrefixes !== [], 'префиксы плагинов прочитаны из манифестов');

foreach ($htaccessFiles as $htaccessFile) {
    $fileName = dirname($htaccessFile) === $rootDir . '/public' ? 'public/.htaccess' : '.htaccess';
    $rules = [];

    foreach (explode("\n", (string) @file_get_contents($htaccessFile)) as $line) {
        if (stripos(ltrim($line), 'RewriteRule') === 0) {
            $rules[] = $line;
        }
    }

    // Правило закрывает каталог целиком, когда перечисляет его в группе:
    // «^(?:src|config|…)» — это запрет на весь префикс, а не на файл.
    $closedDirectories = [];

    foreach ($rules as $rule) {
        if (preg_match('#\^\(\?:([^)]*)\)#', $rule, $group) === 1) {
            preg_match_all('#[A-Za-z0-9_.]+#', $group[1], $names);

            foreach ($names[0] as $closed) {
                $closedDirectories[] = $closed;
            }
        }
    }

    foreach ($pluginPrefixes as $prefix) {
        $t->is(
            !in_array($prefix, $closedDirectories, true),
            $fileName . ': префикс плагина «' . $prefix . '» не закрыт правилом каталогов'
        );
    }
}

// ------------------------------------------------------------------ сдача

$t->group('Сдача работы');

$finishPage = $client->get($itemUrl . '?t=' . $attemptToken);

$finished = $client->post('/work/' . $attemptId . '/finish', [
    '_token' => $client->tokenFrom($finishPage) ?? '',
    't' => $attemptToken,
]);

$t->is($finished['status'] === 302, 'работа сдаётся');

$result = $client->get('/work/' . $attemptId . '/result?t=' . $attemptToken);

$t->is($result['status'] === 200, 'результат доступен по токену попытки');
$t->contains($result['body'], 'Демонстрационная работа', 'в результате видна работа');
$t->is($client->get('/work/' . (int) $attemptId . '/result')['status'] === 404,
    'результат без токена недоступен');

// ------------------------------------------------------------------ разметка

$t->group('Разметка не разъезжается');

/*
 * Склеенный тег — самая дорогая поломка из возможных в шаблонах.
 *
 * При массовом переводе классов на классы шаблона регулярка съедала пробел перед
 * атрибутом и не возвращала его: `<span class="…">` превращался в
 * `<spanclass="…">`. Такой тег браузер не узнаёт — он не элемент, а текст, —
 * и страница теряет оформление молча, без ошибок в PHP и без ответа сервера.
 * Ни один из прежних наборов этого не ловил: они проверяют слова в ответе, а не
 * форму ответа.
 *
 * Проверка идёт по всему ответу и ловит оба вида склейки: слипшийся с именем
 * тега и слипшийся с закрывающей кавычкой другого атрибута.
 */
$sheets = [
    '/portal'         => 'личный кабинет ученика',
    '/work/' . (int) $attemptId . '/result?t=' . $attemptToken => 'результат работы',
];

foreach ($sheets as $url => $what) {
    $page = $client->get($url);
    $body = (string) ($page['body'] ?? '');

    $t->is($page['status'] === 200, $what . ' открыт');

    // `<spanclass="…`, `<divclass="…` и прочие: имя тега и атрибут слиплись.
    $t->is(
        preg_match('/<[a-z][a-z0-9]*class="[^"]*"/i', $body) === 0,
        'в «' . $what . '» нет тега, слипшегося с атрибутом class'
    );

    // `<th scope="col"class="…`: закрывающая кавычка предыдущего атрибута и class.
    $t->is(
        preg_match('/"class="[^"]*"/', $body) === 0,
        'в «' . $what . '» нет атрибута class, слипшегося с кавычкой'
    );

    // Портной класс от перевода: атрибут есть, а класс в нём исчез.
    if (preg_match('/[^<>]{0,70}class=""[^<>]{0,20}/', $body, $hit)) {
        $t->is(false, 'в «' . $what . '» нет пустого class — ' . preg_replace('/\s+/', ' ', $hit[0]));
    } else {
        $t->is(true, 'в «' . $what . '» нет пустого class');
    }
}

/*
 * Окно подтверждения должно закрываться «Отменой» и отправлять форму туда,
 * куда надо.
 *
 * Обе поломки были молчаливыми: страница отвечала 200, слова в ответе стояли на
 * месте, а окно не закрывалось, и подтверждение уходило в никуда. И то и другое
 * видно только в браузере: событие может не дойти до кнопки, а форма может
 * уйти не туда. Здесь проверяется сам скрипт — выполнить его в наборе нечем; живой
 * прогон делает tools\dialog_probe.ps1, он проходит по странице настоящими
 * кликами и возвращает код возврата.
 */
$confirmJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/confirm-modal.js');

$t->contains($confirmJs, 'openBackdrop = backdrop',
    'окно запоминает себя, иначе «Отмена» его не закроет');
$t->is(
    !str_contains($confirmJs, 'action: trigger.href'),
    'адрес формы не берётся у кнопки: у кнопки нет href'
);
$t->contains($confirmJs, "trigger.form || trigger.closest('form')",
    'адрес и поля берутся у формы, в которой лежит кнопка');

/*
 * Сохранение задания без перезагрузки шло не туда.
 *
 * В форме задания есть скрытое поле с именем `action`, а именованные элементы
 * формы перекрывают её свойства: `form.action` отдавал тот самый input, то есть
 * строку `[object HTMLInputElement]`. Запрос уходил на 404 страницу вместо
 * сохранения, ответ приходил HTML, разобрать его как данные было нельзя — и
 * скрипт откатывался к обычной отправке. Страница перезагружалась, введённое
 * терялось, а ни подсветки полей, ни сообщения об ошибке не появлялось: всё
 * выглядело как «кнопка не работает».
 *
 * Проверяется сам приём: адрес берётся атрибутом, а не свойством.
 */
$itemsJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/assignment-items.js');

$t->contains($itemsJs, "form.getAttribute('action')",
    'адрес отправки берётся атрибутом формы, а не свойством');
$t->is(
    preg_match('#fetch\(\s*form\.action#', $itemsJs) !== 1,
    'сохранение не идёт на form.action: его перекрывает поле с именем action'
);

/*
 * Ошибка сохранения должна указывать поле, а не только говорить о себе.
 *
 * Сообщение «отметьте правильный вариант» само по себе не годится: вариантов
 * несколько, и у каждого свой, — человек обходит форму заново, не зная, где
 * ошибка. Сервер возвращает имя поля, скрипт отмечает его и ставит на него фокус.
 */
$t->contains($itemsJs, "data.field",
    'скрипт передаёт имя ошибочного поля в отметку');
$t->contains($itemsJs, "field.classList.add('is-invalid')",
    'поле, в котором ошибка, помечается для человека и для программ чтения');

$testEditor = (string) file_get_contents(
    dirname(__DIR__) . '/plugins/test/src/Http/TestEditorController.php'
);

$t->contains($testEditor, "'field' => \$field",
    'ответ сервера называет поле, в котором автор ошибся');

/*
 * Значок выхода должен читаться как выход.
 *
 * Стоял значок, похожий на колесо: ни дверь, ни стрелка, ни крест — человек,
 * который искал выход, не находил его и решал, что функции выхода нет вовсе.
 * Проверяется не сама картинка, а то, что в значке есть дверная створка и
 * стрелка наружу.
 */
$iconSet = (string) file_get_contents(dirname(__DIR__) . '/templates/_icon.php');

$t->is(
    preg_match("#'logout' => '<svg[^>]*>(?:(?!</svg>).)*polyline#s", $iconSet) === 1,
    'в значке выхода есть стрелка наружу'
);
$t->is(
    preg_match("#'logout' => '<svg[^>]*>(?:(?!</svg>).)*M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4#s", $iconSet) === 1,
    'иконка выхода нарисована дверью с стрелкой'
);

/*
 * Оформление: правила не должны дублироваться и не должны оставаться для
 * того, чего в разметке нет.
 *
 * Здесь важна не красота, а размер правки: лишнее правило в stylesheet стоит
 * во всех страницах и ни на одной не работает, а правило без разметки выглядит
 * как готовая возможность — именно так и вышло со ссылкой «к содержимому»:
 * отчёт внешней проверки хвалил проект за skip-link, которого в нём не было.
 */
$appCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$themeCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/theme.css');
$gentelellaCss = (string) file_get_contents(
    dirname(__DIR__) . '/public/assets/vendor/gentelella/gentelella.css'
);

$t->equals(
    1,
    preg_match_all('/^\.visually-hidden\s*[,{]/m', $appCss),
    'утилита для скрытой подписи объявлена в app.css один раз'
);
$t->is(
    preg_match('/^\.nowrap\s*\{/m', $appCss) === 0,
    'общая утилита объявлена в theme.css, а не продублирована в app.css'
);
$t->contains($themeCss, '.card > .card-body:only-child',
    'лишний отступ карточки с внутренним блоком снят');
$t->is(
    preg_match('/^\s*\.skip-link\s*[,{]/m', $gentelellaCss) === 0,
    'правила ссылки «к содержимому» нет: разметки для него нет тоже'
);
$t->is(
    preg_match('/^\s*\.wizard-steps?\s*[,{]/m', $gentelellaCss) === 0,
    'полосы этапов мастера из сборки не осталось: она заменена своей'
);

/*
 * Отступы карточки не должны накладываться.
 *
 * Отступ задаётся в двух местах — самой карточкой и её внутренним блоком, — и
 * карточка с блоком получала оба. Отсюда и правило, которое это снимает:
 * проверяется, что оно есть и что действительно точечное.
 */
$t->is(
    preg_match('/\.card\s*\{[^}]*padding:/', $appCss) === 1,
    'отступ карточки задаётся в app.css'
);
$t->contains($gentelellaCss, '.card-body {',
    'сборка задаёт отступ внутреннему блоку — отсюда и риск двойного отступа');

/*
 * Карта маршрутов разнесена по файлам, и в этом её главный риск: потерянный
 * адрес выглядит как «страница не открылась», а переставленный — как «новая
 * страница открылась не та». Ниже карта собирается из файлов ровно так, как её
 * собирает `Kernel`, и проверяется на три вещи, которые ломаются тихо.
 */
$t->group('Карта маршрутов разнесена по файлам');

$routeControllers = new Controllers($app);
$map = new Router();

$connected = [PublicRoutes::class, AuthRoutes::class, WorkRoutes::class, PanelRoutes::class, SchoolRoutes::class];

/** @var array<string,int> сколько адресов даёт каждый файл */
$perFile = [];

foreach ($connected as $routeFile) {
    $before = count($map->all());
    $routeFile::register($map, $routeControllers);
    $perFile[substr(strrchr($routeFile, '\\'), 1)] = count($map->all()) - $before;
}

ksort($perFile);

$table = $map->all();

/*
 * Числа — не украшение: они заставляют видеть, что адрес добавился.
 *
 * Черновики правки позиции добавили два адреса в панель: взять черновик и
 * записать его. Молчаливое изменение числа означало бы, что карта разрослась
 * незаметно, — а карта это то, что открывается наружу.
 */
/*
 * Числа — не украшение: они заставляют видеть, что адрес добавился.
 *
 * Вкладки публикации в редакторе работы больше нет: публикация стала
 * переключателем в шапке, и два адреса редактора ушли вместе с ней. Зато
 * вернулись два адреса, без которых работа не публикуется и не оценивается:
 * сохранение шкалы и сама публикация.
 */
$t->equals(126, count($table), 'в карте 126 адресов ядра');
$t->equals(
    ['AuthRoutes' => 19, 'PanelRoutes' => 72, 'PublicRoutes' => 3, 'SchoolRoutes' => 23, 'WorkRoutes' => 9],
    $perFile,
    'каждый файл карты даёт свою часть адресов'
);

/*
 * Подключены должны быть все файлы, а не только те, что вспомнились.
 *
 * Новый файл в папке, который никто не вызвал, выглядит написанной работой:
 * адреса в нём есть, а открыть их нельзя. Проверка сравнивает содержимое папки
 * со списком подключения — расхождение означает, что файл забыли.
 */
$onDisk = [];

foreach ((array) glob($rootDir . '/src/Http/Routes/*.php') as $path) {
    $class = 'MyEdu\\Http\\Routes\\' . basename((string) $path, '.php');

    if (!class_exists($class)) {
        continue;
    }

    if ((new ReflectionClass($class))->implementsInterface(RouteFile::class)) {
        $onDisk[] = $class;
    }
}

sort($onDisk);
$connectedSorted = $connected;
sort($connectedSorted);

$t->equals($onDisk, $connectedSorted, 'подключены все файлы карты из src/Http/Routes');

// Один адрес и метод объявляются один раз: иначе первое совпадение выигрывает молча.
$seen = [];

foreach ($table as $route) {
    $key = $route['method'] . ' ' . $route['pattern'];
    $seen[$key] = ($seen[$key] ?? 0) + 1;
}

$t->is(max($seen) === 1, 'ни один адрес не объявлен дважды');

/*
 * Литеральный адрес раньше адреса с номером.
 *
 * `Router::match()` берёт первое совпадение, поэтому `/panel/issues/new`,
 * объявленный после `/panel/issues/{id}`, открыл бы мастер выдачи только по
 * номеру «new» — то есть страницу выдачи, которой нет.
 */
$positionOf = [];

foreach ($table as $index => $route) {
    $positionOf[$route['method'] . ' ' . $route['pattern']] = $index;
}

$literalBeforePlaceholder = [
    ['POST /panel/users/role', 'GET /panel/users/{id}'],
    ['POST /panel/subjects', 'POST /panel/subjects/{id}'],
    ['GET /panel/assignments/import', 'GET /panel/assignments/{id}'],
    ['GET /panel/assignments/new', 'GET /panel/assignments/{id}'],
    ['GET /panel/issues/new', 'GET /panel/issues/{id}'],
    ['GET /classes/new', 'GET /classes/{id}'],
];

foreach ($literalBeforePlaceholder as [$literal, $placeholder]) {
    $t->is(
        ($positionOf[$literal] ?? -1) < ($positionOf[$placeholder] ?? PHP_INT_MAX),
        '«' . $literal . '» объявлен раньше «' . $placeholder . '»'
    );
}

/*
 * Право на адрес — часть адреса.
 *
 * Список выборочный, но по разным правилам: гостевая страница, страница для
 * вошедших, право по роли, право другого куска и адрес вообще без права
 * (выход и проверка состояния открыты всем).
 */
$capabilityOf = [];

foreach ($table as $route) {
    $capabilityOf[$route['method'] . ' ' . $route['pattern']] = $route['capability'];
}

/*
 * Право «обязательное» — это отсутствие права, и по разнице между `isset()`
 * и `array_key_exists()` его легко перепутать с «адрес не объявлен». Поэтому
 * читается одним помощником, который различает и то и другое.
 */
$rightOf = static function (string $address) use ($capabilityOf): string {
    if (!array_key_exists($address, $capabilityOf)) {
        return 'адреса нет';
    }

    return $capabilityOf[$address] ?? 'без права';
};

$t->equals('@guest', $rightOf('GET /login'), 'вход открыт гостю и только гостю');
$t->equals('@auth', $rightOf('GET /portal'), 'кабинет требует входа');
$t->equals(Role::USERS_MANAGE, $rightOf('GET /panel/users'), 'список пользователей — дело администратора');
$t->equals(Role::TESTS_TAKE, $rightOf('GET /works'), 'список работ открыт и ученику');
$t->equals(Role::REPORTS_VIEW, $rightOf('GET /panel/journal'), 'журнал открыт тому, кто видит результаты');
$t->equals('без права', $rightOf('POST /logout'), 'выход не требует права: он у того, кто уже вошёл');
$t->equals('без права', $rightOf('GET /health'), 'проверка состояния открыта всем');

exit($t->report());
