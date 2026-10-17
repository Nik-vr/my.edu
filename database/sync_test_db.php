<?php

declare(strict_types=1);

/**
 * Копирование рабочей базы в тестовую перед прогоном наборов.
 *
 * Зачем копия, а не пустая база. Тесты удаляют пользователей и классы, поэтому
 * работать им приходится в отдельной базе. Но пустая база, собранная из
 * schema.sql, не содержит того, что появилось в рабочей базе руками: новых
 * классов, предметов, работ, заведённых учителей. Наборы такой базе не видят,
 * и поломка, связанная с данными, обнаруживается только у человека — на
 * рабочей базе, где эти данные есть.
 *
 * Поэтому перед прогоном рабочая база копируется целиком: тестовый набор
 * стартует ровно с тем же содержимым, что и рабочая, и ломает его у себя.
 *
 * Запуск:
 *   php database/sync_test_db.php                 — копия рабочей в тестовую
 *   php database/sync_test_db.php --dry-run       — только показать, что будет
 *   php database/sync_test_db.php --name=my_other_test
 *
 * Тестовый сервер при этом лучше не держать поднятым: копия пересоздаёт базу,
 * и соединения к старой базе падают. tools/run_tests.ps1 останавливает сервер,
 * копирует и поднимает заново.
 */

use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\DatabaseDump;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Скрипт запускается только из командной строки.\n");
}

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

/*
 * Источник — именно рабочая база, поэтому перекрытие MYEDU_DB_NAME на время
 * чтения конфигурации снимается. Иначе скрипт, запущенный из окружения тестов
 * (где переменная указывает на тестовую базу), скопировал бы базу в саму себя:
 * отказ «источник и цель совпадают» срабатывал, но сообщать об этом должен
 * тот, кто ошибся, а не посторонний скрипт.
 */
$envOverride = getenv('MYEDU_DB_NAME');

if (is_string($envOverride) && trim($envOverride) !== '') {
    putenv('MYEDU_DB_NAME');
}

try {
    $config = Config::load($rootDir . '/config');
} finally {
    if (is_string($envOverride) && trim($envOverride) !== '') {
        putenv('MYEDU_DB_NAME=' . $envOverride);
    }
}

$dbConfig = $config->array('database');

$source = (string) ($dbConfig['name'] ?? 'my_edu');
$target = getenv('MYEDU_TEST_DB') ?: 'my_edu_review_test';
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if (str_starts_with($arg, '--name=')) {
        $target = substr($arg, 7);
    }
}

/*
 * Отказ, если цель похожа на рабочую. Копирование сюда уничтожило бы данные,
 * ради которых всё и затевалось, а сделано это было бы одной командой.
 * Проверка грубая и намеренно: «не похоже на рабочую» пропускает любую явно
 * тестовую базу, а «не test» отсекает всё остальное.
 */
if (strcasecmp($source, $target) === 0) {
    fwrite(STDERR, "ОТКАЗ: источник и цель совпадают ({$source}).\n");
    exit(2);
}

if (stripos($target, 'test') === false) {
    fwrite(
        STDERR,
        "ОТКАЗ: цель «{$target}» не похожа на тестовую базу.\n"
        . "Копия пересоздаёт базу целиком. Если это действительно другая база,\n"
        . "назовите её так, чтобы в имени было «test», или отключите проверку:\n"
        . "    MYEDU_ALLOW_DESTRUCTIVE=1 php database/sync_test_db.php --name={$target}\n"
    );
    exit(2);
}

foreach ([$source, $target] as $name) {
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        fwrite(STDERR, "Недопустимое имя базы: {$name}\n");
        exit(1);
    }
}

$host = (string) ($dbConfig['host'] ?? '127.0.0.1');
$port = (int) ($dbConfig['port'] ?? 3306);
$user = (string) ($dbConfig['user'] ?? 'root');
$password = (string) ($dbConfig['password'] ?? '');
$charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');

$dumpTool = new DatabaseDump(new App($config), $config, blocking: false);

$dump = $dumpTool->dumpBinary();
$client = $dumpTool->clientBinary();

/*
 * Путь к утилитам ищется рядом с работающим сервером базы, а не в PATH:
 * на этой машине MariaDB стоит из OpenServer, и PATH про неё ничего не знает.
 * Если утилиты не нашлись — отказ с понятным текстом, а не PHP-предупреждение
 * о ненайденном процессе. Ищет `DatabaseDump`: копирование рабочей базы и
 * резервная копия перед миграциями ходят в одни и те же утилиты, и искать их
 * в двух местах — значит получить две разные причины отказа на одной машине.
 */
if ($dump === null || $client === null) {
    fwrite(
        STDERR,
        "ОТКАЗ: не найдены утилиты MariaDB.\n"
        . "Укажите каталог вручную: MYSQLDUMP=D:\\путь\\bin\\mariadb-dump.exe\n"
        . "Копия рабочей базы: tools\\run_tests.ps1 делает это сам.\n"
    );
    exit(3);
}

echo 'Источник: ' . $source . "\n";
echo 'Цель:     ' . $target . "\n";

if ($dryRun) {
    echo "\n--dry-run: база не изменялась.\n";
    exit(0);
}

/*
 * Дамп снимается во временный файл, а не в память и не по конвейеру.
 * Причина практическая: конвейер на Windows ломает кодировку кириллицы,
 * и русские ФИО в копии превращаются в мусор — вследствие чего тесты падают
 * на сравнении строк, а человек ищет причину не в копии.
 */
$dumpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . sprintf('myedu-sync-%d.sql', getmypid());
$errFile = $dumpFile . '.err';

/*
 * Запуск утилиты без оболочки.
 *
 * Через cmd.exe пришлось бы удваивать внешние кавычки: строка, начинающаяся
 * с кавычки, разбирается cmd по отдельным правилам, и одиночная внешняя
 * кавычка даёт «не является внутренней или внешней командой». Ошибка
 * непонятная и выглядит как проблема с утилитой, хотя дело в кавычках.
 *
 * Файловые дескрипторы передаются процессу напрямую — без `>`, `<` и `2>`,
 * поэтому кодировка не зависит от кодовой страницы консоли и кириллица
 * в копии остаётся кириллицей.
 *
 * Запуск, реквизиты доступа и разбор вывода делает `DatabaseDump`, тот же
 * сервис, который снимает резервную копию перед миграциями. Своя копия этого
 * кода в двух скриптах разошлась бы при первом же исправлении, и отказ в
 * утилите выглядел бы по-разному в зависимости от того, откуда запустили.
 */
$runTool = static fn (array $command, ?string $input, string $output, string $errors): int
    => $dumpTool->runTool($command, $input, $output, $errors);

$outFile = $dumpFile . '.out';

/*
 * Реквизиты передаются файлом, а не ключом `-p` в командной строке: строка
 * запуска видна в списке процессов всей машины, и пароль базы там оседать не
 * должен. Файл удаляется в `finally` — раньше он не удалялся никогда.
 */
$common = [
    '--defaults-extra-file=' . $dumpTool->credentialsFile(),
    '--default-character-set=' . $charset,
];

$started = microtime(true);

$dumpCode = 1;
$loadCode = 1;
$toolError = '';

try {
    $dumpCode = $runTool(
        array_merge([$dump], $common, ['--single-transaction', '--add-drop-table', $source]),
        null,
        $dumpFile,
        $errFile
    );

    // Текст отказа читается до уборки: без него сообщение «дамп не снят»
    // бесполезно — причину знает только утилита.
    $toolError = trim((string) @file_get_contents($errFile));

    if ($dumpCode === 0 && (int) @filesize($dumpFile) === 0) {
        $dumpCode = 1;
    }

    if ($dumpCode === 0) {
        $size = filesize($dumpFile);
        echo 'Дамп:     ' . number_format($size / 1024, 0, ',', ' ') . ' КБ за '
            . round(microtime(true) - $started, 1) . " с\n";

        // Цель пересоздаётся целиком: в копии не должно остаться ничего от прежней.
        $started = microtime(true);

        $loadCode = $runTool(
            array_merge([$client], $common, ['--database=' . $target]),
            $dumpFile,
            $outFile,
            $errFile
        );

        $toolError = trim((string) @file_get_contents($errFile));
    }
} finally {
    /*
     * Файл с паролем удаляется всегда, в том числе после отказа: он лежит в
     * общем каталоге временных файлов, и оставить его значит оставить пароль
     * базы на диске до следующей чистки системы.
     */
    $dumpTool->forgetCredentials();

    @unlink($dumpFile);
    @unlink($errFile);
    @unlink($outFile);
}

if ($dumpCode !== 0) {
    fwrite(STDERR, "ОТКАЗ: дамп не снят.\n");

    if ($toolError !== '') {
        fwrite(STDERR, $toolError . "\n");
    }

    exit(4);
}

if ($loadCode !== 0) {
    fwrite(STDERR, "ОТКАЗ: копия не загружена.\n");

    if ($toolError !== '') {
        fwrite(STDERR, $toolError . "\n");
    }

    exit(5);
}

echo 'Загружено: ' . round(microtime(true) - $started, 1) . " с\n";

// Проверка, что копия действительно на месте и отвечает.
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    $port,
    $target,
    $charset
);

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote($target))->fetchColumn();
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    echo "\nПроверка копии:\n";
    echo '  таблиц:      ' . $tables . "\n";
    echo '  пользователей:' . $users . "\n";

    if ($tables === 0) {
        fwrite(STDERR, "ОТКАЗ: в копии нет ни одной таблицы.\n");
        exit(6);
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'ОТКАЗ: копия не отвечает: ' . $e->getMessage() . "\n");
    exit(6);
}

echo "\nГотово. Тестовый сервер: tools\\start-dev-server-tests.vbs\n";