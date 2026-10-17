<?php

declare(strict_types=1);

/**
 * Применение миграций из командной строки.
 *
 * Запуск:
 *   php database/migrate.php            — применить неприменённые
 *   php database/migrate.php --status   — только показать состояние
 *   php database/migrate.php --repair   — только починить журнал, ничего не применяя
 *   php database/migrate.php --skip-backup — сознательно обойтись без копии базы
 *
 * Миграции берутся из database/migrations/*.sql и отмечаются в таблице
 * schema_migrations. Каждый файл применяется один раз. Сейчас файлов нет:
 * схема опубликована снимком database/schema.sql, а нумерация новых миграций
 * начинается с 001. Записи журнала об удалённых миграциях команда убирает сама,
 * если база соответствует снимку.
 *
 * Перед применением снимается полная копия базы в var/backup: DDL в MySQL не
 * откатывается, и точка отката появляется только здесь. Если копия не снялась,
 * миграция не применяется, а `--skip-backup` (или MYEDU_SKIP_BACKUP=1) позволяет
 * пропустить её сознательно — оба случая пишутся в var/log/backup-*.log.
 *
 * Логика живёт не здесь: она в `src/Core/SchemaMigrator.php` — там же, откуда её
 * берёт автоприменение при выкатке на хостинг. Копия в этом файле рано или поздно
 * разошлась бы с ней, и разошлась бы молча: на сервере применялась бы одна
 * версия правил, а в разработке проверялась бы другая.
 *
 * Для чистой установки миграции не нужны: достаточно импортировать
 * database/schema.sql через public/install.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Скрипт запускается только из командной строки.\n");
}

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

MyEdu\Core\Autoloader::create($rootDir);

use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\SchemaMigrator;

$app = App::boot($rootDir);
$db = $app->get(Database::class);

/*
 * Копия базы снимается перед изменением схемы, и в команде отказ на ней
 * останавливает миграцию. Здесь миграцию запускает человек, который смотрит на
 * экран: сказать «копия не снята, ничего не применено» — правильный ответ, а на
 * сайте тот же отказ означал бы недоступность (см. `DatabaseDump`).
 *
 * `--skip-backup` и MYEDU_SKIP_BACKUP=1 пропускают копию сознательно; оба
 * случая пишутся в var/log/backup-*.log, чтобы пропуск был виден потом.
 */
$dump = new DatabaseDump($app, $app->config(), $db, blocking: true);

if (in_array('--skip-backup', $argv, true) || getenv('MYEDU_SKIP_BACKUP') === '1') {
    $dump->disable(in_array('--skip-backup', $argv, true) ? 'ключ --skip-backup' : 'MYEDU_SKIP_BACKUP=1');
}

$migrator = new SchemaMigrator(
    $db,
    $rootDir . '/database/migrations',
    backup: $dump
);

echo 'База: ' . $db->value('SELECT DATABASE()') . "\n\n";

$statusOnly = in_array('--status', $argv, true);
$repairOnly = in_array('--repair', $argv, true);

$available = $migrator->available();

/*
 * Файлов миграций сейчас нет, и это не «нечего делать», а нормальное
 * состояние: схема опубликована снимком `database/schema.sql`.
 *
 * Раньше на этом месте стоял ранний выход, и он молча пропускал всё остальное:
 * сверку базы со снимком и уборку записей журнала об удалённых миграциях.
 * Теперь дальше выполняется то же, что и при обычном запуске.
 */
if ($available === []) {
    echo "Файлов миграций нет: схема опубликована снимком database/schema.sql.\n\n";
}

/*
 * Пустая база — это не «применить миграции», а первая установка: первая же
 * миграция рассчитана на уже созданные таблицы и упадёт. Говорим, что делать,
 * вместо того чтобы показать ошибку ALTER.
 */
if (!$migrator->schemaExists()) {
    echo "В базе нет таблиц — это первая установка, а не обновление.\n";
    echo "Импортируйте database/schema.sql через public/install.php.\n";
    exit(1);
}

$state = $migrator->status();
$done = $state['applied'];

foreach ($available as $name) {
    $applied = in_array($name, $done, true);

    echo sprintf("  %-40s %s\n", $name, $applied ? 'применена' : ($statusOnly ? 'ОЖИДАЕТ' : 'применяется… '));

    if ($statusOnly || $applied) {
        continue;
    }
}

/*
 * Расхождение журнала с базой показывается сразу, а не только в отчёте после
 * применения: иначе владелец увидит «применено 20 миграций» и не поймёт, что
 * на самом деле ничего не применялось, а журнал просто отставал.
 */
if ($state['pending'] !== [] && $state['missing'] === [] && $state['checked'] > 0) {
    echo "Журнал отстал от базы: неприменённых файлов " . count($state['pending'])
        . ", а база уже соответствует схеме.\n";

    if ($statusOnly) {
        echo "Починить журнал: php database/migrate.php --repair\n";
    }

    echo "\n";
}

/*
 * Записи журнала о файлах, которых на диске нет, показываются отдельно.
 *
 * На работу они не влияют: неприменённые файлы считаются по диску, а не по
 * журналу. Но в общем списке «применённых» они неотличимы от настоящих, и
 * отчёт вводит в заблуждение — двадцать четыре записи выглядят так, будто
 * столько же миграций было применено.
 */
if ($state['stale'] !== []) {
    echo 'Записей об удалённых миграциях в журнале: ' . count($state['stale']) . ".\n";

    foreach (array_slice($state['stale'], 0, 10) as $name) {
        echo '  ' . $name . "\n";
    }

    if (count($state['stale']) > 10) {
        echo '  … и ещё ' . (count($state['stale']) - 10) . "\n";
    }

    if ($statusOnly) {
        echo "Убрать из журнала: php database/migrate.php\n";
    }

    echo "\n";
}

foreach ($state['missing'] as $what) {
    echo '  не хватает: ' . $what . "\n";
}

if ($statusOnly) {
    exit(0);
}

/*
 * Починка журнала без применения миграций.
 *
 * Отдельный режим нужен, чтобы можно было посмотреть, что скажет сверка, до
 * того как что-то записать. На хостинге консоли нет и эта команда недоступна —
 * там то же самое делает `SchemaGate` сам, — но дома или у разработчика она
 * позволяет разобраться, не создавая риска на боевой базе.
 */
if ($repairOnly) {
    $repair = $migrator->reconcile();

    if ($repair['repaired'] !== []) {
        echo "Журнал приведён в соответствие с базой. Отмечено: "
            . count($repair['repaired']) . ".\n";
        foreach ($repair['repaired'] as $name) {
            echo '  ' . $name . "\n";
        }
        echo "\nНи одной миграции не применялось: база уже была новее журнала.\n";
        exit(0);
    }

    if ($repair['alreadyCurrent']) {
        echo "Журнал и база в порядке: расхождений нет.\n";
        exit(0);
    }

    if ($repair['checked'] === 0) {
        echo "Сверка не дала вердикта — не удалось прочитать database/schema.sql.\n";
        echo 'Ничего не изменено.\n';
        exit(1);
    }

    echo "База не совпадает с database/schema.sql, не хватает объектов: "
        . count($repair['missing']) . "\n";

    foreach (array_slice($repair['missing'], 0, 20) as $what) {
        echo '  ' . $what . "\n";
    }

    if (count($repair['missing']) > 20) {
        echo '  … и ещё ' . (count($repair['missing']) - 20) . "\n";
    }

    echo "\nЭто настоящая отсталость базы, а не потерянный журнал:\n";
    echo "примените миграции обычным запуском без --repair.\n";
    exit(1);
}

$result = $migrator->apply();

if ($result['status'] === 'failed') {
    echo "ОШИБКА\n";
    echo '    файл: ' . $result['file'] . "\n";
    echo '    ' . $result['message'] . "\n";
    exit(1);
}

echo "\n";

/*
 * Починка журнала: миграций не применялось, база была новее журнала. Формулировка
 * обязана это прямо говорить, иначе владелец решит, что откатывал базу.
 */
if ($result['status'] === 'repaired') {
    echo "Журнал приведён в соответствие с базой. Отмечено как применённые: "
        . count($result['repaired']) . "\n";

    foreach ($result['repaired'] as $name) {
        echo '  ' . $name . "\n";
    }

    echo "\nНи одной миграции не применялось: база уже была новее журнала.\n";
    exit(0);
}

/*
 * Убранные записи журнала — тоже результат работы, и о нём говорится: иначе
 * владелец решит, что команда ничего не сделала, а на самом деле привела
 * журнал в соответствие с диском.
 */
if ($result['pruned'] !== []) {
    echo "Журнал приведён в соответствие с диском: убрано записей — "
        . count($result['pruned']) . "\n\n";
}

if ($result['applied'] === []) {
    echo "Применять нечего: неприменённых миграций нет, а база соответствует снимку.\n";
    exit(0);
}

echo 'Применено миграций: ' . count($result['applied']) . "\n";

/*
 * Пропущенные операторы печатаются, а не прячутся: миграция нашла базу не в том
 * состоянии, на которое рассчитана, и это стоит увидеть при выкатке, а не
 * месяцем позже, когда что-то поведёт себя неожиданно.
 */
foreach ($result['notes'] as $note) {
    echo '  пропущено: ' . $note . "\n";
}

exit(0);