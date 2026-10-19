<?php

declare(strict_types=1);

/*
 * Уборка временных таблиц в тестовой базе.
 *
 * Наборы создают служебные таблицы (tmp_*, php_dump_values_*) и убирают их за
 * собой, но упавший прогон оставляет их в базе. Дальше сверка схемы видит
 * лишние таблицы и начинает считать базу не той, какой она должна быть, —
 * то есть один прерванный прогон ломает следующие.
 *
 * Запуск: php database\clean_temp_tables.php [имя базы]
 */

$db = $argv[1] ?? 'my_edu_review_test';

$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4",
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND (table_name LIKE 'tmp\\_%' OR table_name LIKE 'php\\_dump\\_values\\_%')"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
    echo "  убрана: {$table}\n";
}

echo '  осталось служебных: ' . count($tables) . "\n";
