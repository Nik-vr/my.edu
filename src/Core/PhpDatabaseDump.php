<?php

declare(strict_types=1);

namespace MyEdu\Core;

use RuntimeException;
use Throwable;

/**
 * Снятие копии базы средствами PHP — когда утилиты `mariadb-dump` на сервере нет.
 *
 * Нужно не «на всякий случай», а по факту: на шаред-хостинге утилиты часто нет
 * вовсе, а вариант «миграции применяются, а точки отката нет» — это не
 * страховка, а её отсутствие. Если сайт работает, значит есть PHP и есть
 * доступ к базе, а этого достаточно для копии.
 *
 * Формат файла тот же, что у утилиты: обычный SQL-скрипт, который загружается
 * обратно без ничего постороннего — хоть phpMyAdmin, хоть командой клиента.
 * Поэтому копию, снятую здесь, можно восстановить на сервере, где утилиты как
 * таковой нет.
 *
 * Что делается и почему именно так:
 *
 * - `SHOW CREATE TABLE` на каждую таблицу — описание берётся у сервера, а не
 *   пишется руками: собственного описания схемы у проекта нет, и любая
 *   расхождённая копия описания восстанавливала бы не ту базу;
 * - строки читаются порциями по первичному ключу, а не целиком: работа с
 *   оценками и ответами учеников — это десятки тысяч строк, и держать их в
 *   памяти незачем;
 * - двоичные значения пишутся как `0x…`: текст с любой кодировкой проходит
 *   через экранирование целиком, а двоичный — нет, и одна испорченная картинка
 *   или вложение тихо портила бы копию;
 * - `FOREIGN_KEY_CHECKS` выключается на время файла: строки идут в порядке имён
 *   таблиц, а не порядка ссылок, и с включёнными проверками загрузка падала бы
 *   на первом же несовпадении порядка;
 * - всё чтение идёт в одной транзакции: InnoDB повторяет снимок по всем
 *   обычным чтениям внутри, и таблицы видны на одном моменте времени. Без
 *   этого копию нельзя было бы назвать копией: между таблицами успевала бы
 *   измениться сама работа, и в копии оказалась бы попытка без работы или
 *   работа без попытки.
 *
 * Чего здесь нет: представлений, хранимых процедур, функций, триггеров и
 * событий. В проекте их нет вовсе (проверено по всем `*.sql`), и поддерживать
 * их выгрузку без проверки на живой базе — значит писать код, который выглядит
 * рабочим и молча теряет часть схемы.
 */
final class PhpDatabaseDump
{
    /** Порция строк за один запрос: ограничение памяти, а не смысла. */
    private const ROWS_PER_QUERY = 500;

    /**
     * Строк в одном `INSERT`.
     *
     * Много мелких вставок замедляют загрузку куда сильнее, чем один оператор
     * с сотней строк, а слишком длинный оператор упирается в `max_allowed_packet`.
     */
    private const ROWS_PER_INSERT = 100;

    /** Сколько байт текста набирать в один `INSERT`. */
    private const BYTES_PER_INSERT = 60000;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    /**
     * Снять копию в указанный файл.
     *
     * @throws RuntimeException таблица не читается или файл не пишется
     */
    public function write(string $path): void
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('не открылся файл копии на запись: ' . $path);
        }

        try {
            $this->writeHeader($handle);

            /*
             * Пустой список таблиц — это не «база пустая», а «список не прочитан».
             *
             * База без таблиц на живом сайте не бывает, а вот служебную таблицу
             * прочитать не удалось — бывает: имя колонки сервер отдаёт в своём
             * регистре, и чтение по имени даёт пустоту. Копия без таблиц
             * выглядит как снятая, а на деле в ней ничего нет, — и такую
             * копию нельзя назвать точкой отката.
             */
            $tables = $this->tables();

            if ($tables === []) {
                throw new RuntimeException(
                    'не удалось прочитать список таблиц базы: служебная таблица '
                    . 'information_schema отдала имена, которые не читаются'
                );
            }

            /*
             * Вся копия снимается в одной транзакции, и уровень изоляции
             * задаётся явно.
             *
             * Раньше здесь стоял `transaction()`, и это была неправда: повторяемое
             * чтение даёт уровень изоляции сервера, а не приложения. При
             * READ-COMMITTED — а он по умолчанию на части хостингов — каждое
             * чтение внутри транзакции видит свежие данные, и таблицы читались в
             * разные моменты. Без этого копия, из которой нельзя восстановиться,
             * хуже отсутствия копии: человек узнаёт о проблеме, когда уже потерял
             * данные.
             *
             * `consistentSnapshot()` задаёт уровень только на время копии, а
             * вложенным вызовом не ломает уже открытую транзакцию.
             */
            $this->db->consistentSnapshot(function () use ($handle, $tables): void {
                $this->writeTables($handle, $tables);
            });

            $this->writeFooter($handle);
        } catch (Throwable $e) {
            fclose($handle);

            throw $e;
        }

        fclose($handle);
    }

    /**
     * Имена таблиц базы — только базовые.
     *
     * Порядок по имени, а не по дате изменения: он одинаков при любом запуске,
     * и две копии, снятые подряд, отличаются данными, а не порядком таблиц.
     *
     * Имя берётся по порядку, а не по имени колонки: имя колонки в
     * `information_schema` сервер отдаёт в своём регистре, и на части
     * шаред-хостингов оно приходит заглавными буквами. Обращение по ключу тогда
     * даёт пустую строку, и копия обрывается ошибкой «Incorrect table name ''».
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->db->all(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = :type
              ORDER BY table_name',
            ['type' => 'BASE TABLE']
        );

        $names = [];

        foreach ($rows as $row) {
            $name = trim((string) Database::firstValue($row));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param resource $handle */
    private function writeHeader($handle): void
    {
        fwrite($handle, $this->comment(sprintf(
            'Копия базы %s, снята средствами PHP, потому что утилиты mariadb-dump на сервере нет',
            (string) $this->config->array('database')['name']
        )));

        // Порядок вставок идёт по именам таблиц, а не по ссылкам между ними.
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET NAMES utf8mb4;\n\n");
    }

    /** @param resource $handle */
    private function writeTables($handle, array $tables): void
    {
        foreach ($tables as $table) {
            $this->writeTable($handle, $table);
        }
    }

    /** @param resource $handle */
    private function writeTable($handle, string $table): void
    {
        $create = $this->db->one('SHOW CREATE TABLE `' . $table . '`');

        if ($create === null) {
            throw new RuntimeException('сервер не описал таблицу ' . $table);
        }

        $definition = (string) ($create['Create Table'] ?? '');

        if ($definition === '') {
            throw new RuntimeException('сервер вернул пустое описание таблицы ' . $table);
        }

        fwrite($handle, $this->comment('Таблица ' . $table));
        fwrite($handle, "DROP TABLE IF EXISTS `" . $table . "`;\n");
        fwrite($handle, $definition . ";\n\n");

        $primary = $this->numericPrimaryKey($table);
        $binary = $this->binaryColumns($table);
        $columns = $this->columns($table);

        if ($columns === []) {
            return;
        }

        $quoted = array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns
        );

        $last = '';
        $offset = 0;
        $pending = [];
        $pendingBytes = 0;

        while (true) {
            /*
             * Две схемы чтения: по первичному ключу, когда он числовой и один,
             * и по смещению иначе. Смещение медленнее — сервер пересчитывает
             * пропущенные строки, — но работает с любой таблицей, где ключа нет
             * или он составной.
             */
            $rows = $primary === null
                ? $this->db->all(
                    'SELECT * FROM `' . $table . '` LIMIT ' . self::ROWS_PER_QUERY . ' OFFSET ' . $offset
                )
                : $this->chunkByKey($table, $primary, $last);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($primary !== null) {
                    $last = $this->keyValue($row, $primary);
                }

                $values = $this->values($row, $binary);
                $pending[] = '(' . implode(',', $values) . ')';
                $pendingBytes += strlen(implode(',', $values)) + 1;

                if (count($pending) >= self::ROWS_PER_INSERT || $pendingBytes >= self::BYTES_PER_INSERT) {
                    $this->flush($handle, $table, $quoted, $pending);
                    $pending = [];
                    $pendingBytes = 0;
                }
            }

            $offset += count($rows);

            /*
             * Порция меньше запрошенной — таблица кончилась. Без этой остановки
             * последний кусок читался бы снова и снова, и в копии оказались бы
             * дубли последних строк.
             */
            if (count($rows) < self::ROWS_PER_QUERY) {
                break;
            }
        }

        if ($pending !== []) {
            $this->flush($handle, $table, $quoted, $pending);
        }

        fwrite($handle, "\n");
    }

    /**
     * Следующая порция строк по первичному ключу.
     *
     * Ключевая выборка вместо смещения: при больших таблицах смещение заставляет
     * сервер пересчитывать пропущенные строки, и копия второй таблицы на
     * двадцать тысяч строк шла бы заметно дольше первой.
     *
     * @return list<array<string,mixed>>
     */
    private function chunkByKey(string $table, string $key, string $after): array
    {
        $sql = $after === ''
            ? 'SELECT * FROM `' . $table . '` ORDER BY `' . $key . '` LIMIT ' . self::ROWS_PER_QUERY
            : 'SELECT * FROM `' . $table . '` WHERE `' . $key . '` > :after ORDER BY `' . $key . '` LIMIT ' . self::ROWS_PER_QUERY;

        return $this->db->all($sql, $after === '' ? [] : ['after' => $after]);
    }

    /** @param array<string,mixed> $row */
    private function keyValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return $value === null ? '' : (string) $value;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $values
     * @param resource     $handle
     */
    private function flush($handle, string $table, array $columns, array $values): void
    {
        fwrite(
            $handle,
            'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ") VALUES\n"
            . implode(",\n", $values) . ";\n"
        );
    }

    /**
     * Значения строки в виде SQL-литералов.
     *
     * @param array<string,mixed> $row
     * @param list<string>        $binary колонки, которые пишутся как `0x…`
     * @return list<string>
     */
    private function values(array $row, array $binary): array
    {
        $values = [];

        foreach ($row as $column => $value) {
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }

            if (in_array((string) $column, $binary, true)) {
                $values[] = '0x' . bin2hex((string) $value);
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $values[] = (string) $value;
                continue;
            }

            $quoted = $this->db->pdo()->quote((string) $value);

            if ($quoted === false) {
                throw new RuntimeException('не удалось подготовить значение колонки ' . $column);
            }

            $values[] = $quoted;
        }

        return $values;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $rows = $this->db->all(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :table
              ORDER BY ordinal_position',
            ['table' => $table]
        );

        return array_map(
            static fn (array $row): string => (string) Database::firstValue($row),
            $rows
        );
    }

    /**
     * Колонки, которые нельзя писать текстом.
     *
     * Список типов задан прямо в запросе, а не константой: он повторяет то, что
     * делает сервер при сравнении, и две копии этого списка разошлись бы при
     * первом же добавлении типа в MySQL.
     *
     * @return list<string>
     */
    private function binaryColumns(string $table): array
    {
        $rows = $this->db->all(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :table
                AND (data_type IN ('blob', 'binary', 'varbinary', 'bit', 'geometry')
                     OR character_set_name = :binary)
              ORDER BY ordinal_position",
            ['table' => $table, 'binary' => 'binary']
        );

        return array_map(
            static fn (array $row): string => (string) Database::firstValue($row),
            $rows
        );
    }

    /**
     * Единственная числовая колонка первичного ключа — по ней и читаем порциями.
     *
     * Тип берётся из `information_schema.columns`, а не из `statistics`: там его
     * нет, и запрос с `data_type` падал бы на каждой таблице. Соединение по трём
     * колонкам нужно только ради этого типа.
     */
    private function numericPrimaryKey(string $table): ?string
    {
        $rows = $this->db->all(
            'SELECT s.column_name, c.data_type
               FROM information_schema.statistics s
               JOIN information_schema.columns c
                 ON c.table_schema = s.table_schema
                AND c.table_name = s.table_name
                AND c.column_name = s.column_name
              WHERE s.table_schema = DATABASE()
                AND s.table_name = :table
                AND s.seq_in_index = 1',
            ['table' => $table]
        );

        if (count($rows) !== 1) {
            return null;
        }

        $types = ['int', 'integer', 'smallint', 'mediumint', 'bigint'];

        // Значения берутся по порядку: имя колонки сервер отдаёт в своём
        // регистре, и на части хостингов оно приходит заглавными буквами.
        $values = array_values($rows[0]);

        if (!in_array(strtolower((string) ($values[1] ?? '')), $types, true)) {
            return null;
        }

        return (string) ($values[0] ?? '');
    }

    /** @param resource $handle */
    private function writeFooter($handle): void
    {
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($handle, $this->comment('Конец копии'));
    }

    /** Строка-комментарий: файл остаётся читаемым, а значения не теряются. */
    private function comment(string $text): string
    {
        return '-- ' . str_replace(["\r\n", "\n", "\r"], ' ', $text) . "\n";
    }
}