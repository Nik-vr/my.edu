<?php

declare(strict_types=1);

/**
 * Проверки целостности схемы без БД и без HTTP.
 *
 * Запуск: php tests/run_schema_tests.php
 *
 * Схема проверяется по файлу `database/schema.sql`, а не по живой базе.
 * Причина практическая: наборы копируют рабочую базу, поэтому база всегда
 * «догнавшая» миграции, и расхождение схемы с миграциями по ним не видно.
 * А именно такое расхождение однажды осталось незамеченным — установка с нуля
 * отказывалась собирать базу, и ни один набор об этом не говорил.
 *
 * Отдельно проверяется единообразие ссылочной целостности: каждая таблица с
 * `author_id` объявляет внешний ключ на `users`. Именно здесь расходились
 * `assignment_issues` и `tests` (ключ есть) с `assignments` (ключа не было), и
 * на этом держалась проверка отката при вносе.
 */

require __DIR__ . '/support/TestKit.php';

$runner = new TestRunner();
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

// Разбор на таблицы: начало CREATE TABLE и следующее `) ENGINE=` — это её конец.
//
// `IF NOT EXISTS` допускается: снимок создаёт таблицы, которых ещё нет, и не
// трогает те, что уже созданы. Раньше здесь стоял `CREATE TABLE ` без этой
// части, и после правки снимка разбор перестал находить что бы то ни было —
// все проверки ниже падали с «таблица не описана».
preg_match_all(
    '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([a-z0-9_]+)`\s*\((.*?)\)\s*ENGINE=/is',
    $schema,
    $tables,
    PREG_SET_ORDER
);

/** @var array<string,array{body:string}> $byName */
$byName = [];
foreach ($tables as $table) {
    $byName[$table[1]] = ['body' => $table[2]];
}

$runner->group('Схема: разбор database/schema.sql');

$runner->is(count($tables) > 15, 'схема разобрана, таблиц найдено: ' . count($tables));

// Схема обязана описывать то, на чём работает программа, иначе свежая
// установка поднимется неполной.
foreach ([
    'users', 'assignments', 'assignment_items', 'assignment_attempts',
    'assignment_issues', 'tests', 'questions', 'question_options',
    'classes', 'class_students', 'subjects', 'plugins', 'user_plugins',
    'auth_tokens', 'user_roles',
] as $required) {
    $runner->is(isset($byName[$required]), 'таблица ' . $required . ' описана в схеме');
}

// Таблицы создаются, если их нет, и не трогаются, если они уже есть. Раньше в
// файле стояло `DROP TABLE IF EXISTS`, и импорт в базу с данными стирал её
// целиком. Здесь и в data-вставках проверяется именно это.
$runner->group('Схема: файл не разрушает базу с данными');

$codeWithoutComments = (string) preg_replace('/^\s*--.*$/m', '', $schema);

$runner->notContains(
    $codeWithoutComments,
    'DROP TABLE',
    'в снимке нет ни одного удаления таблицы'
);

$runner->notContains(
    $codeWithoutComments,
    'TRUNCATE',
    'в снимке нет очистки таблиц'
);

// Каждая таблица объявляется через IF NOT EXISTS: иначе повторный импорт в
// базу, где таблица уже создана, падал бы с «Table already exists».
preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(`[a-z0-9_]+`)/i', $codeWithoutComments, $creates, PREG_SET_ORDER);

$withoutGuard = [];

foreach ($creates as $create) {
    if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . preg_quote($create[1], '/') . '/i', $codeWithoutComments) !== 1) {
        $withoutGuard[] = $create[1];
    }
}

$runner->equals([], $withoutGuard, 'каждая таблица объявлена через IF NOT EXISTS: ' . implode(', ', $withoutGuard));

// Вставки данных повторяемые: роли и настройки должны пережить повторный
// импорт, а не обрывать его ошибкой «Duplicate entry».
$plainInserts = preg_match_all('/(?<!IGNORE )\bINSERT\s+INTO/iu', $codeWithoutComments);

$runner->equals(
    0,
    $plainInserts,
    'вставки данных в снимке повторяемые: обычного INSERT INTO не осталось'
);

$runner->group('Схема: ссылочная целостность author_id');

// Столбцы, которые ссылаются на пользователя, обязаны быть защищены ключом.
$authorTables = ['assignments', 'assignment_issues', 'tests'];

foreach ($authorTables as $table) {
    $body = $byName[$table]['body'] ?? '';

    $runner->is(
        preg_match('/`author_id`\s+INT\s+UNSIGNED/i', $body) === 1,
        'у ' . $table . ' объявлен author_id'
    );

    $runner->is(
        preg_match(
            '/CONSTRAINT\s+`fk_[a-z0-9_]*author`\s+FOREIGN\s+KEY\s*\(\s*`author_id`\s*\)\s*'
            . 'REFERENCES\s+`users`\s*\(\s*`id`\s*\)/i',
            $body
        ) === 1,
        'у ' . $table . ' объявлен внешний ключ на users по author_id'
    );
}

/*
 * Правило удаления у всех трёх НЕ одинаковое, и это осознанно:
 *
 * - `assignments` — RESTRICT. Работа с её попытками и результатами самая ценная
 *   вещь в системе, и удаление сотрудника не должно её уносить: пусть удаление
 *   откажется, а разбираться будет человек.
 * - `tests` — SET NULL. Автор задания необязателен, и задание должно пережить
 *   удаление автора, потеряв автора, а не исчезнуть.
 * - `assignment_issues` — CASCADE. Выдача живёт недолго, и её исчезновение
 *   вместе с сотрудником не считается потерей.
 *
 * Единообразия тут добиваться нельзя: единое правило означало бы либо потерю
 * работ, либо выдач. Проверяется поэтому главное — ключ есть везде, а у работ
 * он именно RESTRICT. Правила остальных двух сверяются с ожиданием, чтобы
 * смена была заметна: расхождение вылезло бы здесь, а не потерей данных.
 */
$deleteRule = static function (string $body): string {
    return preg_match(
        '/CONSTRAINT\s+`fk_[a-z0-9_]*author`\s+FOREIGN\s+KEY\s*\(\s*`author_id`\s*\)\s*'
        . 'REFERENCES\s+`users`\s*\(\s*`id`\s*\)\s*ON\s+DELETE\s+([A-Z\s]+)/i',
        $body,
        $m
    ) === 1 ? trim(strtoupper($m[1])) : 'не объявлено';
};

$expectedRules = [
    'assignments'       => 'RESTRICT',
    'assignment_issues' => 'CASCADE',
    'tests'             => 'SET NULL',
];

foreach ($expectedRules as $table => $expected) {
    $runner->is(
        $deleteRule($byName[$table]['body'] ?? '') === $expected,
        'у ' . $table . ' удаление автора объявлено как ' . $expected
    );
}

$runner->group('Схема: личный набор плагинов');

// Личные настройки хранятся рядом с пользователем и обязаны убираться вместе
// с ним: строка о выключенном плагине без человека — мусор.
$userPlugins = $byName['user_plugins']['body'] ?? '';

$runner->is(
    preg_match('/PRIMARY\s+KEY\s*\(\s*`user_id`\s*,\s*`plugin_id`\s*\)/i', $userPlugins) === 1,
    'user_plugins различает пользователя и плагин'
);

$runner->is(
    preg_match(
        '/CONSTRAINT\s+`fk_user_plugins_user`\s+FOREIGN\s+KEY\s*\(\s*`user_id`\s*\)\s*'
        . 'REFERENCES\s+`users`\s*\(\s*`id`\s*\)\s*ON\s+DELETE\s+CASCADE/i',
        $userPlugins
    ) === 1,
    'удаление пользователя уносит его настройки плагинов'
);

$runner->group('Схема: предметные ограничения');

// Числа и перечисления, на которые код опирается при разборе и проверках.
$runner->is(
    preg_match('/`assignment_items`\s*\((?:(?!ENGINE=).)*?UNIQUE\s+KEY\s+`uq_assignment_position_variant`\s*'
        . '\(\s*`assignment_id`\s*,\s*`position`\s*,\s*`variant`\s*\)/is', $schema) === 1,
    'позиции работы уникальны по тройке работа-место-вариант'
);

$runner->is(
    preg_match('/`variant`\s+TINYINT\s+UNSIGNED\s+NOT\s+NULL\s+DEFAULT\s+1/i', $schema) === 1,
    'вариант по умолчанию равен 1 — старые работы не требуют правки'
);

exit($runner->report());