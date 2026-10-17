<?php

declare(strict_types=1);

/**
 * Подготовка отдельной базы для тестов.
 *
 * Интеграционные тесты удаляют пользователей и классы, поэтому работают не
 * в рабочей базе, а в my_edu_test. Рабочая база при этом не затрагивается.
 *
 * Запуск:
 *   php database/install_test_db.php            — создать, если нет
 *   php database/install_test_db.php --reset    — пересоздать с нуля
 *   php database/install_test_db.php --name=my_other_test
 *
 * Схема берётся из database/schema.sql, то есть ровно та же, что и на боевой.
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Core\Config;

// Доступы к серверу берём из рабочей конфигурации: отдельные настройки для
// тестовой базы не нужны, отличается только имя.
$config = Config::load($rootDir . '/config');

$dbConfig = $config->array('database');

// По умолчанию — та же база, что и у тестового сервера. Имя из прошлой
// установки (my_edu_test) осталось в обращениях и в головах: скрипт молча
// готовил не ту базу, а --reset без --name удалял не ту.
$name = 'my_edu_review_test';
$reset = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--reset') {
        $reset = true;
        continue;
    }

    if (str_starts_with($arg, '--name=')) {
        $name = substr($arg, 7);
    }
}

if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    fwrite(STDERR, "Недопустимое имя базы: {$name}\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) ($dbConfig['host'] ?? '127.0.0.1'),
    (int) ($dbConfig['port'] ?? 3306),
    (string) ($dbConfig['charset'] ?? 'utf8mb4')
);

$user = (string) ($dbConfig['user'] ?? 'root');
$password = (string) ($dbConfig['password'] ?? '');

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Не удалось подключиться к серверу базы данных: " . $e->getMessage() . "\n");
    exit(1);
}

/*
 * Отказ для рабочей базы.
 *
 * Скрипт удаляет базу целиком, а --reset без --name взял бы имя по умолчанию
 * и стёр не то. Здесь имя проверяется до DROP, а не после: необратимое
 * действие не должно ждать, пока дело дойдёт до середины скрипта.
 *
 * Обойти можно осознанно — MYEDU_ALLOW_DESTRUCTIVE=1, как и в остальных
 * разрушительных наборах. Одинаковое имя проверки и одинаковый обход
 * позволяют запомнить одно правило вместо двух.
 */
if ($reset && stripos($name, 'test') === false && getenv('MYEDU_ALLOW_DESTRUCTIVE') !== '1') {
    fwrite(
        STDERR,
        "ОТКАЗ: --reset удаляет базу «{$name}» целиком, а это не тестовая.\n"
        . "Укажите тестовую базу явно: --name=my_edu_review_test\n"
        . "либо рабочую осознанно:\n"
        . "    MYEDU_ALLOW_DESTRUCTIVE=1 php database/install_test_db.php --name={$name} --reset\n"
    );
    exit(2);
}

if ($reset) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    echo "База {$name} удалена.\n";
}

$exists = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($name))->fetchColumn();

if ($exists !== false && !$reset) {
    /*
     * Ранний выход молчал, и повторный запуск выглядел как «ничего не
     * сделал»: плагин не включался, демо-данные не засевались. Теперь
     * сказано прямо, что делать.
     */
    echo "База {$name} уже существует. Для пересоздания укажите --reset,\n";
    echo "либо скопируйте рабочую базу: php database/sync_test_db.php\n";
    exit(0);
}

$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "База {$name} создана.\n";

// Наполнение идёт в тестовую базу, а не в рабочую.
putenv('MYEDU_DB_NAME=' . $name);

/*
 * Схема применяется в уже созданную базу: подключение к серверу идёт без
 * имени базы, иначе «USE» в схеме некуда переключаться.
 */
$sql = (string) file_get_contents($rootDir . '/database/schema.sql');

$schemaPdo = new PDO($dsn . ';dbname=' . $name, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Импорт одним куском: в файле есть комментарии с двоеточиями, из-за чего
// подготовленные запросы принимают их за параметры и ломают импорт.
$schemaPdo->exec($sql);

echo "Схема применена.\n";

/*
 * Сверка снимка схемы с тем, что он сам создал.
 *
 * Раньше здесь сверяли schema.sql с миграциями: пока схема собиралась
 * накатыванием файлов, миграции были её историей, и снимок проверялся на
 * соответствие им. Теперь всё наоборот — снимок и есть схема, а история её
 * сборки удалена. Проверять больше нечего в ту сторону, но появилось то, чего
 * раньше не могло случиться: файл может разойтись с базой сам с собой.
 *
 * Способы такой расхождения, оба настоящие:
 *
 *   * в файле объявлена колонка, которой в базе нет, — опечатка в имени, и
 *     импорт её молча пропустил;
 *   * в базе есть колонка, которой нет в файле, — остаток от прежней миграции,
 *     и свежая установка собирается отличной от рабочих баз.
 *
 * Именно так нашлась `assignment_items.title_snapshot`: в снимке её не было, а
 * на инсталляциях она осталась. На новом сервере такой базы не было бы, и любая
 * миграция, написанная по такой базе, просила бы несуществующий объект.
 *
 * Журнал миграций при этом остаётся пустым: файлов миграций нет, отмечать
 * нечего, а `schema_migrations` создана тем же импортом.
 */
$state = MyEdu\Core\SchemaState::fromSchemaFile($rootDir . '/database/schema.sql');

$testApp = MyEdu\Core\App::boot($rootDir);
$testDb = $testApp->get(MyEdu\Core\Database::class);

if (!$state->isParsed()) {
    fwrite(STDERR, "\nОТКАЗ: database/schema.sql не читается или в нём нет ни одной таблицы.\n");
    exit(3);
}

$missing = $state->missingIn($testDb)['missing'];

/*
 * Таблицы плагинов в сверке не участвуют: по устройству проекта их создаёт сам
 * плагин, и в снимке ядра их нет. На момент сверки они ещё и не появились бы —
 * плагины включаются ниже, — но перечислить их явно надёжнее, чем полагаться на
 * порядок шагов: сверка должна быть верной и при перестановке.
 */
$extra = $state->extraIn($testDb, [
    'plugin_migrations',
    'test_item_attempts',
    'test_item_answers',
    'variable_tasks',
    'variable_item_attempts',
    'variable_item_answers',
]);

echo 'Проверено объектов в снимке: ' . $state->missingIn($testDb)['checked'] . ".\n";

if ($missing !== []) {
    fwrite(
        STDERR,
        "\nОТКАЗ: в собранной базе нет того, что объявлено в schema.sql:\n"
        . implode("\n", array_map(static fn (string $m): string => '  ' . $m, $missing))
        . "\n\nСхема и файл разошлись. Правьте database/schema.sql.\n"
    );
    exit(3);
}

if ($extra !== []) {
    fwrite(
        STDERR,
        "\nОТКАЗ: в собранной базе есть то, чего нет в schema.sql:\n"
        . implode("\n", array_map(static fn (string $m): string => '  ' . $m, $extra))
        . "\n\nСвежая установка собирается иначе, чем стоят рабочие базы.\n"
        . "Уберите объект из schema.sql или верните его в файл — молча мимо\n"
        . "расхождения проходить нельзя.\n"
    );
    exit(3);
}

echo "Расхождений со schema.sql не найдено.\n";

// Демо-тест нужен интеграционным тестам: они проходят его целиком.
// Плагины включаются после сверки схемы: таблицы попыток создаёт сам плагин,
// и на момент сверки их в базе ещё нет.
require_once $rootDir . '/tests/support/TestKit.php';

$pluginManager = $testApp->get(MyEdu\Plugin\PluginManager::class);
$pluginResult = $pluginManager->enable('test');

if (!$pluginResult['ok']) {
    fwrite(STDERR, 'Не удалось включить плагин test: ' . $pluginResult['message'] . "\n");
    exit(1);
}

// Запускается отдельным процессом, потому что скрипт наполнения сам выходит
// по завершении.
$seedScript = $rootDir . '/database/seed_demo_test.php';

$process = proc_open(
    [PHP_BINARY, $seedScript, '--force'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

if (!is_resource($process)) {
    fwrite(STDERR, "Не удалось запустить наполнение демо-тестом.\n");
    exit(1);
}

echo (string) stream_get_contents($pipes[1]);
echo (string) stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

exit(proc_close($process) === 0 ? 0 : 1);