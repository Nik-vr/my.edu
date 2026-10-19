<?php

declare(strict_types=1);

/**
 * Поиск сирот в таблицах плагинов.
 *
 * Запуск:
 *   php database/find_orphans.php          — только показать
 *   php database/find_orphans.php --fix    — удалить найденное
 *
 * Что ищется. Плагины хранят прохождения рядом со своими заданиями и ссылаются
 * на попытку работы и позицию работы числами. Внешних ключей на таблицы ядра у
 * них нет, и это сделано намеренно: миграции плагина могут прийти на базу, где
 * таблиц ядра ещё нет, а каждая плагина — это отдельная программа, которой не
 * полагается знать про внутренности чужой части системы.
 *
 * Цена этого решения — сироты. Попытка работы удалилась, а её прохождение в
 * таблице плагина осталось: строка про ответ, которого больше нет, и работа,
 * которой тоже нет. Такое случается при восстановлении из копии, снятой не с
 * той базы, при удалении класса и при ручной правке. Само по себе оно не мешает
 * работать — мешает тогда, когда про такое не знаешь.
 *
 * Почему нужен отдельный инструмент. Съехавшую базу можно заметить и иначе, но
 * по симптомам: «средний балл не тот», «в журнале лишнее», и всё это далеко от
 * причины. Здесь причина называется прямо и списком, а `--fix` убирает её
 * молчаливое последствие.
 *
 * Что удаляется и что нет. `--fix` сносит только строки прохождений и ответов,
 * у которых не существует попытки работы. Позиции работы не проверяются
 * удалением: у задания может не быть ни одного прохождения, и тогда отсутствие
 * строки — это норма, а не сирота.
 */

require dirname(__DIR__) . '/src/Core/Autoloader.php';

MyEdu\Core\Autoloader::create(dirname(__DIR__));

use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Plugin\PluginManager;

$root = dirname(__DIR__);
$fix = in_array('--fix', array_slice($argv, 1), true);

$app = App::boot($root);
$db = $app->get(Database::class);

$say = static function (string $line) use ($fix): void {
    fwrite($fix ? STDERR : STDOUT, $line . "\n");
};

/*
 * Таблицы берутся у самих плагинов, а не перечисляются здесь.
 *
 * Список в команде разошёлся бы с реальностью при первом же новом плагине, и
 * пропущенный плагин выглядел бы как «сирот нет», то есть как хорошая новость.
 */
$plugins = $app->get(PluginManager::class);

$known = [];

foreach ($db->all(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
) as $row) {
    $known[strtolower((string) $row['table_name'])] = true;
}

$total = 0;

/*
 * Две таблицы у каждого плагина проверяются по-разному, и общего обхода здесь
 * быть не может: у прохождений есть ссылка на попытку работы, у ответов — только
 * на прохождение.
 */
foreach (array_keys($plugins->all()) as $id) {
    $id = (string) $id;
    $attempts = $id . '_item_attempts';
    $answers = $id . '_item_answers';

    if (isset($known[$attempts])) {
        $orphans = $db->all(
            'SELECT p.id FROM `' . $attempts . '` p
             LEFT JOIN `assignment_attempts` a ON a.id = p.assignment_attempt_id
             WHERE a.id IS NULL'
        );

        if ($orphans !== []) {
            $total += count($orphans);
            $say('Плагин «' . $id . '»: ' . count($orphans)
                . ' прохождений без попытки работы');

            if ($fix) {
                foreach ($orphans as $row) {
                    $db->run('DELETE FROM `' . $attempts . '` WHERE id = :id', ['id' => (int) $row['id']]);
                }
            }
        }
    }

    if (isset($known[$answers]) && isset($known[$attempts])) {
        $orphans = $db->all(
            'SELECT a.id FROM `' . $answers . '` a
             LEFT JOIN `' . $attempts . '` p ON p.id = a.item_attempt_id
             WHERE p.id IS NULL'
        );

        if ($orphans !== []) {
            $total += count($orphans);
            $say('Плагин «' . $id . '»: ' . count($orphans) . ' ответов без прохождения');

            if ($fix) {
                foreach ($orphans as $row) {
                    $db->run('DELETE FROM `' . $answers . '` WHERE id = :id', ['id' => (int) $row['id']]);
                }
            }
        }
    }
}

if ($total === 0) {
    $say('Сирот не найдено.');

    exit(0);
}

$say($fix ? 'Удалено записей: ' . $total : 'Всего записей: ' . $total);
$say($fix ? 'Готово.' : 'Повторите с --fix, чтобы убрать их.');

exit($fix ? 0 : 1);