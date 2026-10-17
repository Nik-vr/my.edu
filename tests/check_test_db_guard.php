<?php

declare(strict_types=1);

/**
 * Проверка защиты: разрушительный тест не должен идти по рабочей базе.
 *
 * Запуск:
 *   php tests/check_test_db_guard.php                        — ждём отказ
 *   MYEDU_DB_NAME=my_edu_test php tests/check_test_db_guard.php — ждём пропуск
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

require $rootDir . '/tests/support/TestKit.php';

use MyEdu\Core\App;
use MyEdu\Core\Database;

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

echo 'база по умолчанию: ' . $db->value('SELECT DATABASE()') . "\n";

try {
    assertTestDatabase($db);
} catch (\Throwable $e) {
    echo 'исключение: ' . $e->getMessage() . "\n";
    exit(1);
}

// Сюда попадаем, только если проверка разрешила работу: база тестовая.
echo "защита пропустила: база помечена как тестовая\n";
exit(0);