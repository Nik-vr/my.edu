<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Сверка живой базы с целевой схемой из `database/schema.sql`.
 *
 * Зачем это нужно. `schema_migrations` — не источник истины, а журнал: он
 * записывает, какие файлы мигратор уже прогнал. Журнал теряется и расходится
 * с реальностью на живых сайтах, и вот как это выглядит на шаред-хостинге:
 *
 *   * база была поставлена из `schema.sql`, журнал при этом не заполнился —
 *     отметки лежат в той же базе, а её восстанавливали из дампа без них;
 *   * журнал обрезали при переносе, а таблица `schema_migrations` осталась;
 *   * `install.php` отметил не всё, потому что его прервали на середине.
 *
 * Дальше мигратор видит пустой журнал на полностью новой базе и начинает
 * с `002_users_and_roles.sql`. Та миграция рассчитана на базу первого этапа:
 * она снимает внешние ключи с `answers`, `attempts` и `grades`, которых давно
 * нет, — и падает на первом же таком операторе. `SchemaGate` показывает
 * владельцу страницу с ошибкой, но починить это без консоли он не может: тот же
 * `002` не применится никогда, потому что объекты, которые он правит, удалены
 * более поздними миграциями и уже никогда не появятся. Сайт не поднимается,
 * и `/login` среди прочего — зайти некуда.
 *
 * Что здесь делается. Схема читается из файла, который по определению
 * describes текущую версию программы, и сравнивается с тем, что реально есть
 * в базе. Если база уже новая — расходится только журнал, и миграции на самом
 * деле уже применены. Тогда они отмечаются применёнными, без единого DDL.
 *
 * Чего этот класс не делает:
 *
 * - Не применяет миграции и не меняет схему. Только читает и сравнивает:
 *   решение «применить» принимает `SchemaMigrator`.
 * - Не считает базу новой по одному-двум признакам. Совпадение проверяется по
 *   всем объектам целевой схемы, и любое расхождение означает «не новая».
 * - Не объявляет базу новой по умолчанию при любой ошибке разбора. Если файл
 *   схемы не прочитан или в нём не найдено ни одной таблицы, сравнение
 *   возвращает «не знаю», и починка не производится: пропущенная миграция
 *   опаснее лишней записи в журнал.
 *
 * Про «любой лишний объект». Сверка спрашивает «есть ли всё, что нужно», а не
 * «нет ли лишнего». Поэтому таблица, оставшаяся от удалённого плагина, не мешает
 * признать базу новой: мешать не должен посторонний мусор, а проверка «лишнего»
 * была бы запретом на любые посторонние объекты — то есть на саму базу,
 * которой владелец распоряжается.
 */
final class SchemaState
{
    /**
     * @param array<string,list<string>> $tables ожидаемые таблицы и их колонки
     * @param bool                        $parsed разобран ли schema.sql
     */
    private function __construct(
        private readonly array $tables,
        private readonly bool $parsed
    ) {
    }

    /**
     * Разбор `database/schema.sql`.
     *
     * Берутся таблицы и колонки — этого достаточно, чтобы отличить новую базу
     * от старой. Индексы и внешние ключи намеренно не разбираются: для сверки
     * «база новая или нет» хватает формы таблиц, а разбор ключей в общем виде
     * (в том числе встроенных в `CREATE TABLE`) дорог и хрупок — ошибка в нём
     * выглядела бы как «схема не совпадает», то есть как просьба применить
     * миграции, которые применять нельзя.
     */
    public static function fromSchemaFile(string $path): self
    {
        $sql = @file_get_contents($path);

        if ($sql === false) {
            return new self([], false);
        }

        $tables = [];

        /*
         * Таблица — от `CREATE TABLE` до `) ENGINE=`, как в проверках схемы.
         * Многострочный разбор: тело таблицы содержит и запятые, и вложенные
         * скобки у DECIMAL(6,2), поэтому границу даёт только следующая
         * `) ENGINE=` — то, что MySQL пишет в конце объявления.
         */
        preg_match_all(
            '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([a-z0-9_]+)`\s*\((.*?)\)\s*ENGINE=/is',
            $sql,
            $found,
            PREG_SET_ORDER
        );

        foreach ($found as $match) {
            $table = $match[1];
            $columns = [];

            /*
             * Колонка начинается с имени в обратных кавычках. Строки вида
             * `PRIMARY KEY (...)`, `KEY ...`, `CONSTRAINT ...` тоже начинаются
             * с обратных кавычек, поэтому имя колонки отличается по следующему
             * за ним слову: у колонки это всегда тип (`INT`, `VARCHAR`,
             * `DECIMAL`, `ENUM`, `DATETIME`...), а у ключей — `KEY`,
             * `PRIMARY`, `UNIQUE`, `CONSTRAINT`, `FOREIGN`, `FULLTEXT`.
             */
            preg_match_all(
                '/^\s*`([a-z0-9_]+)`\s+((?:INT|BIGINT|SMALLINT|TINYINT|MEDIUMINT|VARCHAR|CHAR|TEXT|TINYTEXT|MEDIUMTEXT|LONGTEXT|DECIMAL|NUMERIC|FLOAT|DOUBLE|ENUM|SET|DATE|DATETIME|TIMESTAMP|TIME|YEAR|BLOB|VARBINARY|JSON|BOOLEAN|BIT)\b)/im',
                $match[2],
                $columnLines,
                PREG_SET_ORDER
            );

            foreach ($columnLines as $column) {
                $columns[] = strtolower($column[1]);
            }

            $columns = array_values(array_unique($columns));

            if ($columns !== []) {
                $tables[$table] = $columns;
            }
        }

        /*
         * Схема без таблиц — это не пустая схема, а непрочитанный файл. Различать
         * обязательно: иначе сверка решила бы, что база новая, и починила журнал
         * на основании ничего.
         */
        return new self($tables, $tables !== []);
    }

    /**
     * Чего в базе не хватает до целевой схемы.
     *
     * @return array{checked:int,missing:list<string>}
     */
    public function missingIn(Database $db): array
    {
        if (!$this->parsed) {
            return ['checked' => 0, 'missing' => []];
        }

        $missing = [];
        $checked = 0;

        foreach ($this->tables as $table => $columns) {
            $checked++;

            if (!$this->tableExists($db, $table)) {
                $missing[] = 'таблица ' . $table;

                /*
                 * Колонки отсутствующей таблицы не перечисляются: их отсутствие
                 * уже объявлено отсутствием самой таблицы, и двадцать строк про
                 * одну и ту же причину только засоряют отчёт.
                 */
                continue;
            }

            $present = $this->columnsOf($db, $table);

            foreach ($columns as $column) {
                $checked++;

                if (!in_array($column, $present, true)) {
                    $missing[] = 'колонка ' . $table . '.' . $column;
                }
            }
        }

        return ['checked' => $checked, 'missing' => $missing];
    }

    /** Разобран ли schema.sql: без него сверка не даёт вердикта. */
    public function isParsed(): bool
    {
        return $this->parsed;
    }

    /**
     * Чего в базе есть, а в снимке схемы не объявлено.
     *
     * Обратная сторона `missingIn()`, и нужна она для другого вопроса. Там
     * спрашивается «база ли отстала» — для этого лишние объекты не мешают:
     * посторонняя таблица не делает базу старой. Здесь спрашивается другое:
     * «соответствует ли файл тому, что он сам создаёт», — а это уже случай, где
     * лишнее означает опечатку.
     *
     * Именно так нашлась лишняя колонка `assignment_items.title_snapshot` в
     * файле: в базу её никто не импортировал, она осталась от старой миграции,
     * и на работающих инсталляциях её нет. Свежая установка собиралась с ней,
     * и любая будущая миграция, написанная по такому файлу, запросила бы
     * изменение объекта, которого на живых базах нет.
     *
     * @param list<string> $skipTableStartsWith таблицы, начинающиеся с этих
     *                                            префиксов, из сверки убираются:
     *                                            таблицы плагинов не описаны
     *                                            в снимке ядра по устройству
     *                                            проекта
     */
    public function extraIn(Database $db, array $skipTableStartsWith = []): array
    {
        if (!$this->parsed) {
            return [];
        }

        $extra = [];

        foreach ($this->tablesIn($db) as $table) {
            if ($this->isSkipped($table, $skipTableStartsWith)) {
                continue;
            }

            $declared = $this->tables[$table] ?? null;

            if ($declared === null) {
                $extra[] = 'таблица ' . $table;

                continue;
            }

            foreach ($this->columnsOf($db, $table) as $column) {
                if (!in_array($column, $declared, true)) {
                    $extra[] = 'колонка ' . $table . '.' . $column;
                }
            }
        }

        return $extra;
    }

    /**
     * @param list<string> $prefixes
     */
    private function isSkipped(string $table, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tablesIn(Database $db): array
    {
        $rows = $db->all(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() ORDER BY table_name'
        );

        return array_map(
            static fn (array $row): string => strtolower((string) $row['table_name']),
            $rows
        );
    }

    /** @return array<string,list<string>> */
    public function tables(): array
    {
        return $this->tables;
    }

    private function tableExists(Database $db, string $table): bool
    {
        return (int) $db->value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        ) > 0;
    }

    /** @return list<string> */
    private function columnsOf(Database $db, string $table): array
    {
        $rows = $db->all(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        );

        return array_map(
            static fn (array $row): string => strtolower((string) $row['column_name']),
            $rows
        );
    }
}