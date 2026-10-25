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

/*
 * Имена колонок `information_schema` читаются по порядку, а не по имени.
 *
 * Сервер отдаёт имя колонки в своём регистре, и на части шаред-хостингов оно
 * приходит заглавными буквами. Обращение по ключу тогда даёт пустую строку, а
 * пустое имя таблицы в `SHOW CREATE TABLE` обрывается ошибкой «Incorrect table
 * name ''» — и человек видит сообщение про миграцию, хотя дело в чтении
 * служебной таблицы.
 *
 * Здесь проверяется и сам порядок чтения, и то, что хрупкой формы в коде
 * больше нет.
 */
$runner->group('Схема: чтение служебных таблиц не зависит от регистра');

require dirname(__DIR__) . '/src/Core/Database.php';

$runner->equals(
    'users',
    \MyEdu\Core\Database::firstValue(['TABLE_NAME' => 'users']),
    'значение берётся по порядку, а не по имени колонки'
);
$runner->equals(
    'users',
    \MyEdu\Core\Database::firstValue(['table_name' => 'users']),
    'и в том случае, когда имя пришло в своём регистре'
);
$runner->equals(
    null,
    \MyEdu\Core\Database::firstValue([]),
    'пустая строка не превращается в имя таблицы'
);

$coreSources = '';

foreach (['src/Core/SchemaState.php', 'src/Core/PhpDatabaseDump.php'] as $file) {
    $coreSources .= (string) file_get_contents(dirname(__DIR__) . '/' . $file);
}

$runner->is(
    !str_contains($coreSources, "['table_name']") && !str_contains($coreSources, "['column_name']"),
    'имена таблиц и колонок не читаются по имени колонки'
);

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
    'classes', 'students', 'subjects', 'plugins', 'user_plugins',
    'auth_tokens', 'user_roles',
] as $required) {
    $runner->is(isset($byName[$required]), 'таблица ' . $required . ' описана в схеме');
}

/*
 * Ученики живут в отдельной таблице, а прежнее членство в классах
 * (`class_students`) упразднено: у ученика ровно один класс —
 * `students.class_id`, NULL означает «вне класса». Возврат `class_students`
 * в снимок означал бы два источника правды о классе ученика, и свежая
 * установка собралась бы с рассинхроном, который поймают только живые
 * данные.
 */
$runner->is(
    !isset($byName['class_students']),
    'таблицы class_students в схеме нет: класс у ученика один, через students.class_id'
);

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

$runner->group('Схема: ученики — отдельная таблица');

/*
 * Ученик — не сотрудник: у него свой логин, статус без «ожидает
 * подтверждения адреса» и никаких ролей — справочник ролей относится только
 * к сотрудникам. Возврат ученика в `users` или добавление ему `pending`
 * означало бы, что вход ученика и панель класса написаны на иную схему, чем
 * собрана свежая установка.
 */
$students = $byName['students']['body'] ?? '';

$runner->is(
    preg_match('/`login`\s+VARCHAR\(64\)\s+NOT\s+NULL/i', $students) === 1,
    'логин ученика обязателен: лист с паролем печатают по нему'
);
$runner->is(
    preg_match('/`status`\s+ENUM\(\s*[\'"]active[\'"]\s*,\s*[\'"]blocked[\'"]\s*\)/i', $students) === 1,
    'у ученика только active и blocked: почты для подтверждения адреса нет'
);
$runner->is(
    preg_match('/UNIQUE\s+KEY\s+`uq_students_login`\s*\(\s*`login`\s*\)/i', $students) === 1,
    'логин ученика уникален'
);

/*
 * Класс у ученика один, и удаление класса не должно уносить ученика вместе
 * с его историей попыток: запись остаётся, только без класса.
 */
$runner->is(
    preg_match(
        '/CONSTRAINT\s+`fk_students_class`\s+FOREIGN\s+KEY\s*\(\s*`class_id`\s*\)\s*'
        . 'REFERENCES\s+`classes`\s*\(\s*`id`\s*\)\s*ON\s+DELETE\s+SET\s+NULL/i',
        $students
    ) === 1,
    'класс ученика ссылается на classes, и удаление класса не уносит ученика'
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

$runner->group('Схема: попытка работы принадлежит ученику или сотруднику');

/*
 * Попытка — всегда чья-то: ученика или сотрудника, начавшего работу сам,
 * чтобы проверить её. Ровно одна из двух колонок заполнена, и ведут они в
 * разные таблицы: номера учеников и сотрудников нумеруются независимо, и
 * одна колонка на двоих отдала бы чужую работу при совпадении номера.
 */
$attempts = $byName['assignment_attempts']['body'] ?? '';

$runner->is(
    preg_match('/`student_id`\s+INT\s+UNSIGNED\s+NULL/i', $attempts) === 1,
    'у попытки есть необязательная колонка student_id'
);
$runner->is(
    preg_match('/`staff_id`\s+INT\s+UNSIGNED\s+NULL/i', $attempts) === 1,
    'у попытки есть необязательная колонка staff_id'
);
$runner->is(
    preg_match(
        '/CONSTRAINT\s+`fk_attempts_student`\s+FOREIGN\s+KEY\s*\(\s*`student_id`\s*\)\s*'
        . 'REFERENCES\s+`students`\s*\(\s*`id`\s*\)/i',
        $attempts
    ) === 1,
    'ученик попытки берётся из students, а не из users'
);
$runner->is(
    preg_match(
        '/CONSTRAINT\s+`fk_attempts_staff`\s+FOREIGN\s+KEY\s*\(\s*`staff_id`\s*\)\s*'
        . 'REFERENCES\s+`users`\s*\(\s*`id`\s*\)/i',
        $attempts
    ) === 1,
    'сотрудник попытки берётся из users'
);

/*
 * Номер попытки уникален по той колонке, которая заполнена. Без пары таких
 * ключей два захода одного человека перезаписывали бы нумерацию друг друга:
 * вторая попытка падала бы по первичному ключу или, хуже, считалась бы
 * первой.
 */
$runner->is(
    preg_match(
        '/UNIQUE\s+KEY\s+`uq_attempt_number`\s*'
        . '\(\s*`assignment_id`\s*,\s*`student_id`\s*,\s*`attempt_no`\s*\)/i',
        $attempts
    ) === 1,
    'номер попытки ученика уникален по тройке работа-ученик-попытка'
);
$runner->is(
    preg_match(
        '/UNIQUE\s+KEY\s+`uq_attempt_number_staff`\s*'
        . '\(\s*`assignment_id`\s*,\s*`staff_id`\s*,\s*`attempt_no`\s*\)/i',
        $attempts
    ) === 1,
    'номер самопроверки сотрудника уникален по тройке работа-сотрудник-попытка'
);
$runner->is(
    preg_match('/KEY\s+`idx_attempts_student`\s*\(\s*`student_id`\s*\)/i', $attempts) === 1,
    'по ученику попытки есть индекс'
);
$runner->is(
    preg_match('/KEY\s+`idx_attempts_staff`\s*\(\s*`staff_id`\s*\)/i', $attempts) === 1,
    'по сотруднику попытки есть индекс'
);

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

$runner->group('Схема: личный размер шрифта');

/*
 * Размер шрифта хранится у обоих — у сотрудника и у ученика.
 *
 * Разные таблицы, а свойство одно, и проверяется оно здесь по снимку, а не
 * по живой базе: снимок собирает новую установку, и колонка, пропущенная в
 * нём, обнаружилась бы только там, где человек впервые открыл настройки.
 */
$usersBody = $byName['users']['body'] ?? '';
$studentsBody = $byName['students']['body'] ?? '';

foreach (['users' => $usersBody, 'students' => $studentsBody] as $table => $body) {
    $runner->is(
        preg_match('/`font_size`\s+VARCHAR\(8\)\s+NOT\s+NULL\s+DEFAULT\s+\'normal\'/i', $body) === 1,
        $table . ' хранит личный размер шрифта с нормальным значением по умолчанию'
    );
}

/*
 * Значение — слово, а не число.
 *
 * Тип VARCHAR, а не ENUM: три размера общаются и с формой, и с базой, и с
 * атрибутом страницы, и слово в базе читается без перевода. ENUM же сделал бы
 * расширение списка (новый размер) правкой типа в двух местах сразу.
 *
 * Ограничение списка живёт в коде — `User::fontSizes()` — и проверяется в
 * наборе авторизации: база хранит, что положили, и не обязана знать список.
 */
$runner->is(
    preg_match('/`font_size`\s+(?:ENUM|TINYINT|INT)/i', $usersBody . $studentsBody) !== 1,
    'размер шрифта не заведён как перечисление или число'
);

/*
 * Миграция 008 обязана приводить базу к состоянию снимка.
 *
 * Снимок и миграция — два места, где описано одно и то же, и расхождение
 * между ними ловится только здесь: наборы идут по базе, собранной импортом
 * снимка, а у работающей инсталляции колонки добавляет миграция.
 */
$runner->is(
    str_contains((string) file_get_contents(dirname(__DIR__) . '/database/migrations/008_font_size.sql'), 'font_size'),
    'миграция 008 добавляет колонку font_size'
);

$runner->group('Схема: личная тема оформления');

/*
 * Тема хранится у обоих — у сотрудника и у ученика, как и размер шрифта:
 * разные таблицы, свойство одно. Проверка по снимку, а не по живой базе —
 * та же причина: колонка, пропущенная в снимке, обнаружилась бы там, где
 * человек впервые нажал «Сменить тему» и обнаружил, что сохранять некуда.
 *
 * Решение владельца от 24.10.2026 (волна E): тема живёт в профиле, а не в
 * localStorage браузера, и без колонки оно физически не работает.
 */
foreach (['users' => $usersBody, 'students' => $studentsBody] as $table => $body) {
    $runner->is(
        preg_match('/`theme`\s+VARCHAR\(8\)\s+NOT\s+NULL\s+DEFAULT\s+\'dark\'/i', $body) === 1,
        $table . ' хранит личную тему с тёмным значением по умолчанию'
    );
}

/*
 * Слово, а не число — та же причина, что у размера шрифта: dark/light
 * читается в атрибуте страницы без перевода.
 */
$runner->is(
    preg_match('/`theme`\s+(?:ENUM|TINYINT|INT)/i', $usersBody . $studentsBody) !== 1,
    'тема не заведена как перечисление или число'
);

/*
 * Снимок и миграция 010 — два места с одним описанием: наборы идут по базе,
 * собранной импортом снимка, а у работающей инсталляции колонку добавляет
 * миграция. Расхождение ловится только здесь.
 */
$runner->is(
    str_contains((string) file_get_contents(dirname(__DIR__) . '/database/migrations/010_theme.sql'), 'theme'),
    'миграция 010 добавляет колонку theme'
);

exit($runner->report());