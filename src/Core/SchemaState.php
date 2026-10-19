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
     * @param array<string,list<string>> $tables       ожидаемые таблицы и их колонки
     * @param array<string,list<string>> $indexes      ожидаемые индексы: `имя:колонки`
     * @param array<string,list<string>> $foreignKeys  ожидаемые внешние ключи:
     *                                                    `имя:колонки>таблица:колонки`
     * @param bool                        $parsed      разобран ли schema.sql
     */
    private function __construct(
        private readonly array $tables,
        private readonly array $indexes,
        private readonly array $foreignKeys,
        private readonly bool $parsed
    ) {
    }

    /**
     * Разбор `database/schema.sql`.
     *
     * Берутся таблицы, колонки, индексы и внешние ключи.
     *
     * Про индексы и ключи. Раньше они не разбирались, и сверка оставалась формой
     * таблиц. Этого хватало, чтобы отличить новую базу от старой, но не хватало,
     * чтобы сказать «база в целевом состоянии»: база без `FOREIGN KEY` на
     * удалении ученика выглядит по именам таблиц и колонок ровно так же, как
     * база с ним. Журнал при этом чинится, миграция считается применённой, и
     * ограничение, ради которого её и писали, не появляется никогда.
     *
     * Разбор поэтому узкий и по форме самого файла, а не общий: в `schema.sql`
     * первичный ключ пишется как `PRIMARY KEY (...)`, индексы — как
     * `KEY имя (...)` или `UNIQUE KEY имя (...)`, внешние ключи — как
     * `CONSTRAINT имя FOREIGN KEY (...) REFERENCES ...`. Всё остальное не
     * распознаётся и просто не попадает в сверку.
     *
     * Ложное срабатывание здесь не опасно, и это тоже проверено: расхождение
     * означает лишь «не новую базу», то есть миграции будут применены — а это
     * и без сверки ключей делается при любой неприменённой миграции. Опасна
     * противоположная ошибка — признать базу новой по неполным признакам.
     */
    public static function fromSchemaFile(string $path): self
    {
        $sql = @file_get_contents($path);

        if ($sql === false) {
            return new self([], [], [], false);
        }

        $tables = [];
        $indexes = [];
        $foreignKeys = [];

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

            $indexes[$table] = self::parseIndexes($match[2]);
            $foreignKeys[$table] = self::parseForeignKeys($match[2]);
        }

        /*
         * Схема без таблиц — это не пустая схема, а непрочитанный файл. Различать
         * обязательно: иначе сверка решила бы, что база новая, и починила журнал
         * на основании ничего.
         */
        return new self($tables, $indexes, $foreignKeys, $tables !== []);
    }

    /**
     * Индексы таблицы в виде `имя:колонка,колонка`.
     *
     * Порядок колонок входит в отпечаток: составной ключ `(a, b)` и `(b, a)` —
     * разные индексы, и для составного первичного ключа разница меняет смысл.
     * У составного индекса в MySQL есть ещё и префикс, но он не влияет на то,
     * найдёт ли поиск строку, и в сверку не входит.
     *
     * @return list<string>
     */
    private static function parseIndexes(string $body): array
    {
        $found = [];

        if (preg_match_all('/^\s*PRIMARY\s+KEY\s*\(([^)]+)\)/im', $body, $matches) > 0) {
            $found[] = 'primary:' . self::columnList($matches[1][0]);
        }

        if (preg_match_all(
            '/^\s*(?:UNIQUE\s+)?KEY\s+`([a-z0-9_]+)`\s*\(([^)]+)\)/im',
            $body,
            $matches,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($matches as $match) {
                $found[] = strtolower($match[1]) . ':' . self::columnList($match[2]);
            }
        }

        return $found;
    }

    /**
     * Внешние ключи в виде `имя:колонки>таблица:колонки`.
     *
     * Имя сравнивается само по себе: одноимённый ключ, указывающий не туда,
     * ограничением не является. Правила удаления не сверяются — на поддержку
     * `ON DELETE` у MySQL свои строки в `information_schema`, а решение о них
     * принимает миграция, а не сверка.
     *
     * @return list<string>
     */
    private static function parseForeignKeys(string $body): array
    {
        if (preg_match_all(
            '/^\s*CONSTRAINT\s+`([a-z0-9_]+)`\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+'
                . 'REFERENCES\s+`([a-z0-9_]+)`\s*\(([^)]+)\)/im',
            $body,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $found[] = strtolower($match[1]) . ':' . self::columnList($match[2])
                . '>' . strtolower($match[3]) . ':' . self::columnList($match[4]);
        }

        return $found;
    }

    /** Колонки из списка в скобках: `` `a`, `b` `` → `a,b`. */
    private static function columnList(string $inside): string
    {
        $names = [];

        foreach (explode(',', $inside) as $name) {
            $clean = strtolower(trim(str_replace('`', '', $name)));

            if ($clean !== '') {
                $names[] = $clean;
            }
        }

        return implode(',', $names);
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
            $columnsComplete = true;

            foreach ($columns as $column) {
                $checked++;

                if (!in_array($column, $present, true)) {
                    $missing[] = 'колонка ' . $table . '.' . $column;
                    $columnsComplete = false;
                }
            }

            /*
             * Индексы и внешние ключи сверяются только когда все колонки таблицы
             * на месте. Ключ по отсутствующей колонке — следствие её отсутствия,
             * и отдельной строкой он лишь удваивает список одной и той же причины.
             */
            if (!$columnsComplete) {
                continue;
            }

            $presentIndexes = $this->indexesOf($db, $table);

            foreach ($this->indexes[$table] ?? [] as $index) {
                $checked++;

                if (!in_array($index, $presentIndexes, true)) {
                    $missing[] = 'индекс ' . $table . '.' . $index;
                }
            }

            $presentKeys = $this->foreignKeysOf($db, $table);

            foreach ($this->foreignKeys[$table] ?? [] as $key) {
                $checked++;

                if (!in_array($key, $presentKeys, true)) {
                    $missing[] = 'внешний ключ ' . $table . '.' . $key;
                }
            }
        }

        return ['checked' => $checked, 'missing' => $missing];
    }

    /**
     * Индексы таблицы в том же виде, что и разбор `schema.sql`.
     *
     * @return list<string>
     */
    private function indexesOf(Database $db, string $table): array
    {
        $rows = $db->all(
            'SELECT INDEX_NAME AS n, COLUMN_NAME AS c'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['t' => $table]
        );

        $grouped = [];

        foreach ($rows as $row) {
            // Значения берутся по порядку: имя колонки сервер отдаёт в своём
            // регистре, и на части хостингов оно приходит заглавными буквами.
            $values = array_values($row);
            $name = strtolower(trim((string) ($values[0] ?? '')));
            $grouped[$name][] = strtolower(trim((string) ($values[1] ?? '')));
        }

        $result = [];

        foreach ($grouped as $name => $columns) {
            $result[] = $name . ':' . implode(',', $columns);
        }

        return $result;
    }

    /**
     * Внешние ключи таблицы в том же виде, что и разбор `schema.sql`.
     *
     * @return list<string>
     */
    private function foreignKeysOf(Database $db, string $table): array
    {
        $rows = $db->all(
            'SELECT CONSTRAINT_NAME AS n, COLUMN_NAME AS c,'
            . ' REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc'
            . ' FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL'
            . ' ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            ['t' => $table]
        );

        $grouped = [];

        foreach ($rows as $row) {
            // Значения берутся по порядку: имя колонки сервер отдаёт в своём
            // регистре, и на части хостингов оно приходит заглавными буквами.
            $values = array_values($row);
            $name = strtolower(trim((string) ($values[0] ?? '')));
            $grouped[$name]['own'][] = strtolower(trim((string) ($values[1] ?? '')));
            $grouped[$name]['ref_table'] = strtolower(trim((string) ($values[2] ?? '')));
            $grouped[$name]['ref'][] = strtolower(trim((string) ($values[3] ?? '')));
        }

        $result = [];

        foreach ($grouped as $name => $key) {
            $result[] = $name . ':' . implode(',', $key['own'])
                . '>' . $key['ref_table'] . ':' . implode(',', $key['ref']);
        }

        return $result;
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

        /*
         * Имя берётся по порядку, а не по имени колонки: имя колонки в
         * `information_schema` сервер отдаёт в своём регистре, и на части
         * шаред-хостингов оно приходит заглавными буквами. Обращение по ключу
         * тогда даёт пустую строку, и сверка видит в базе таблицу с пустым
         * именем — то есть считает, что в базе нет ничего, и требует создать
         * всё с нуля.
         */
        $names = [];

        foreach ($rows as $row) {
            $name = strtolower(trim((string) Database::firstValue($row)));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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
            static fn (array $row): string => strtolower(trim((string) Database::firstValue($row))),
            $rows
        );
    }
}