<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\SchemaMigrator;
use RuntimeException;
use Throwable;

/**
 * Применяет SQL-миграции одного плагина с отметкой каждого оператора.
 *
 * DDL в MySQL не откатывается общей транзакцией. Поэтому после каждого
 * успешно выполненного оператора сохраняется checkpoint: повторный запуск
 * продолжит с первого незавершённого оператора, а не повторит уже созданные
 * таблицы. Уже применённые операторы неизменяемы; упавший оператор можно
 * исправить, не переписывая предыдущие.
 */
final class PluginMigrationRunner
{
    private const LEDGER = 'plugin_migrations';

    private const BASELINE_MARKER = '__baseline__';

    public function __construct(
        private readonly Database $db,
        private readonly string $pluginId,
        private readonly string $directory,
        /**
         * Резервная копия базы перед изменением схемы.
         *
         * Миграции плагина применяются не только из консоли: `install()`
         * вызывается при первом же обращении к панели, когда версия плагина
         * разошлась с записанной в базе. То есть `CREATE TABLE` может
         * выполниться в обычном запросе, где никто не ждёт ни отката, ни копии.
         * Поэтому сервис передаётся и здесь — копия снимается только тогда,
         * когда действительно есть что применять.
         */
        private readonly ?DatabaseDump $backup = null
    ) {
    }

    /**
     * Есть ли неприменённые операторы.
     *
     * Отдельный вопрос нужен для копии: снимать её «на каждый вызов» нельзя,
     * а вызов происходит при каждом обращении к панели плагинов. Проверяется
     * журнал операторов, а не файлы целиком: файлы плагина меняются редко,
     * журнал — на каждый вызов.
     */
    public function hasPending(): bool
    {
        foreach ($this->files() as $path) {
            $filename = basename($path);
            $sql = @file_get_contents($path);

            if ($sql === false) {
                return true;
            }

            $statements = SchemaMigrator::splitStatements($sql);
            $expected = $statements === [] ? 1 : count($statements);

            $applied = (int) $this->db->value(
                'SELECT COUNT(*) FROM `' . self::LEDGER . '` WHERE plugin_id = :plugin AND filename = :file',
                ['plugin' => $this->pluginId, 'file' => $filename]
            );

            if ($applied < $expected) {
                return true;
            }
        }

        return false;
    }

    public function apply(): void
    {
        $this->ensureLedger();
        $lock = 'myedu_plugin_' . substr(hash('sha256', $this->pluginId), 0, 40);

        if ((int) $this->db->value('SELECT GET_LOCK(:name, 10)', ['name' => $lock]) !== 1) {
            throw new RuntimeException('Не удалось получить блокировку миграций плагина «' . $this->pluginId . '»');
        }

        try {
            $files = $this->files();

            /*
             * Копия снимается под блокировкой и только если есть что применять:
             * миграции плагина применяются при первом обращении к панели, и
             * снимать копию на каждый такой заход — значит писать их тысячами.
             */
            if ($this->backup !== null && $this->hasPending()) {
                $this->backup->take('миграциями плагина «' . $this->pluginId . '»');
            }

            foreach ($files as $path) {
                $this->applyFile($path);
            }

            $this->markApplied(self::BASELINE_MARKER, 0, hash('sha256', 'baseline'));
        } finally {
            try {
                $this->db->run('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
            } catch (Throwable) {
                // MySQL освободит именованную блокировку при закрытии соединения.
            }
        }
    }

    /**
     * Файлы миграций плагина по порядку.
     *
     * Порядок по имени — тот же, по которому они применяются: отсортированный
     * список нужен и для применения, и для вопроса «есть ли что применять».
     *
     * @return list<string>
     */
    private function files(): array
    {
        $files = is_dir($this->directory) ? glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [] : [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Записать baseline старой установки, у которой ещё нет operator ledger.
     *
     * Это нужно только при переходе со старого установщика: установленная
     * версия уже завершила все файлы до появления checkpoint-таблицы. Новые
     * версии плагинов должны добавлять новые файлы и менять version в манифесте.
     */
    public function baselineExisting(): void
    {
        $this->ensureLedger();

        if ($this->hasLedgerRows()) {
            return;
        }

        $lock = 'myedu_plugin_' . substr(hash('sha256', $this->pluginId), 0, 40);

        if ((int) $this->db->value('SELECT GET_LOCK(:name, 10)', ['name' => $lock]) !== 1) {
            throw new RuntimeException('Не удалось получить блокировку миграций плагина «' . $this->pluginId . '»');
        }

        try {
            if ($this->hasLedgerRows()) {
                return;
            }

            $files = $this->files();

            /*
             * Отметка «миграции уже выполнены» проверяется по таблице, а не
             * верится на слово.
             *
             * Раньше здесь достаточно было отсутствия строк в журнале, и пустой
             * журнал означал «установить как было». На живой базе выходит и
             * другое: журнала могло не быть вовсе — базу ставили до появления
             * журнала миграций плагинов, — а таблиц плагина при этом никто не
             * создавал. Тогда миграции отмечались выполненными, ничего не
             * выполнялось, и плагин оставался сломанным навсегда: при выдаче
             * работы падало «таблица test_item_attempts не существует», а
             * починить это без консоли было нечем.
             *
             * Поэтому перед отметкой спрашивается база: раз таблицы, которые
             * объявляют миграции, на месте — верить журналу можно; нет — миграции
             * надо выполнить по-настоящему.
             */
            if ($files !== [] && !$this->declaredTablesExist($files)) {
                return;
            }

            foreach ($files as $path) {
                $sql = @file_get_contents($path);

                if ($sql === false) {
                    throw new RuntimeException('Не читается старая миграция плагина: ' . basename($path));
                }

                $statements = SchemaMigrator::splitStatements($sql);

                if ($statements === []) {
                    $this->markApplied(basename($path), 0, hash('sha256', ''));
                    continue;
                }

                foreach ($statements as $index => $statement) {
                    $this->markApplied(basename($path), $index + 1, hash('sha256', trim($statement)));
                }
            }

            $this->markApplied(self::BASELINE_MARKER, 0, hash('sha256', 'baseline'));
        } finally {
            try {
                $this->db->run('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
            } catch (Throwable) {
                // MySQL освободит именованную блокировку при закрытии соединения.
            }
        }
    }

    /**
     * На месте ли таблицы, которые объявляют миграции плагина.
     *
     * Ответ на «можно ли доверять журналу миграций». Проверяется только
     * `CREATE TABLE`: остальные операторы о состоянии установки не говорят —
     * добавление колонки может и состояться, и нет, — а таблица, которую создаёт
     * миграция, либо есть, либо нет.
     *
     * @param list<string>|null $files файлы миграций; null — все из каталога
     */
    public function declaredTablesExist(?array $files = null): bool
    {
        $files ??= is_dir($this->directory) ? glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [] : [];
        sort($files, SORT_STRING);

        foreach ($files as $path) {
            $sql = @file_get_contents($path);

            if ($sql === false) {
                continue;
            }

            preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
                $sql,
                $created
            );

            foreach (array_unique($created[1]) as $table) {
                $exists = (int) $this->db->value(
                    'SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = :name',
                    ['name' => $table]
                );

                if ($exists === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function applyFile(string $path): void
    {
        $filename = basename($path);
        $sql = @file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException('Не читается миграция плагина: ' . $filename);
        }

        $statements = SchemaMigrator::splitStatements($sql);
        $count = count($statements);

        $applied = $this->db->all(
            'SELECT statement_index, checksum FROM `' . self::LEDGER . '` WHERE plugin_id = :plugin AND filename = :file',
            ['plugin' => $this->pluginId, 'file' => $filename]
        );
        $done = [];

        foreach ($applied as $row) {
            $done[(int) $row['statement_index']] = (string) $row['checksum'];
        }

        // Пустой файл тоже отмечается, чтобы не проверять его на каждом включении.
        if ($count === 0) {
            $emptyChecksum = hash('sha256', '');

            foreach (array_keys($done) as $number) {
                if ($number !== 0) {
                    throw new RuntimeException(
                        'Из пустой миграции «' . $this->pluginId . '/' . $filename
                        . '» удалены уже применённые операторы'
                    );
                }
            }

            if (isset($done[0]) && !hash_equals($done[0], $emptyChecksum)) {
                throw new RuntimeException('Пустая миграция «' . $this->pluginId . '/' . $filename . '» изменилась');
            }

            if (!isset($done[0])) {
                $this->markApplied($filename, 0, $emptyChecksum);
            }

            return;
        }

        if (isset($done[0])) {
            throw new RuntimeException('Пустая миграция «' . $this->pluginId . '/' . $filename . '» изменилась');
        }

        foreach ($statements as $index => $statement) {
            $number = $index + 1;
            $checksum = hash('sha256', trim($statement));

            if (array_key_exists($number, $done)) {
                if (!hash_equals($done[$number], $checksum)) {
                    throw new RuntimeException(
                        'Оператор ' . $number . ' миграции «' . $this->pluginId . '/' . $filename
                        . '» изменён после применения'
                    );
                }

                continue;
            }

            try {
                $this->db->run($statement);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        'Миграция плагина «%s/%s», оператор %d из %d: %s',
                        $this->pluginId,
                        $filename,
                        $number,
                        $count,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }

            try {
                $this->markApplied($filename, $number, $checksum);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        'Оператор %d миграции «%s/%s» выполнен, но checkpoint не записан: %s',
                        $number,
                        $this->pluginId,
                        $filename,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        foreach (array_keys($done) as $number) {
            if ($number > $count) {
                throw new RuntimeException(
                    'Из миграции «' . $this->pluginId . '/' . $filename
                    . '» удалены уже применённые операторы'
                );
            }
        }
    }

    private function markApplied(string $filename, int $statement, string $checksum): void
    {
        $this->db->run(
            'INSERT INTO `' . self::LEDGER . '` (plugin_id, filename, statement_index, checksum, applied_at) '
            . 'VALUES (:plugin, :file, :statement, :checksum, NOW()) '
            . 'ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = VALUES(applied_at)',
            [
                'plugin' => $this->pluginId,
                'file' => $filename,
                'statement' => $statement,
                'checksum' => $checksum,
            ]
        );
    }

    private function hasLedgerRows(): bool
    {
        return $this->db->value(
            'SELECT 1 FROM `' . self::LEDGER . '` WHERE plugin_id = :plugin LIMIT 1',
            ['plugin' => $this->pluginId]
        ) !== null;
    }

    private function ensureLedger(): void
    {
        $exists = $this->db->value(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1',
            ['table' => self::LEDGER]
        );

        if ($exists !== null) {
            return;
        }

        $this->db->run(
            'CREATE TABLE IF NOT EXISTS `' . self::LEDGER . '` (
                `plugin_id` VARCHAR(64) NOT NULL,
                `filename` VARCHAR(191) NOT NULL,
                `statement_index` SMALLINT UNSIGNED NOT NULL,
                `checksum` CHAR(64) NOT NULL,
                `applied_at` DATETIME NOT NULL,
                PRIMARY KEY (`plugin_id`, `filename`, `statement_index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
