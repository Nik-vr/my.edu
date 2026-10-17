<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Применение миграций к существующей базе.
 *
 * Логика одна и для командной строки (`database/migrate.php`), и для
 * автоприменения при выкатке новой версии на хостинг: копирование файлов
 * недостаточно, потому что меняется структура базы, а консоли на шаред-хостинге
 * у владельца нет. Две реализации разошлись бы при первой же правке — и
 * разошлись бы молча, потому что отличаются они как раз на том, что никто не
 * проверяет.
 *
 * Как это работает при выкатке:
 *
 *   1. Проверяем, что схема вообще есть: иначе это не обновление, а первая
 *      установка, и её делает `public/install.php` импортом `schema.sql`.
 *   2. Сверяем содержимое `database/migrations/` с таблицей `schema_migrations`.
 *   3. Чего не хватает — применяем по одному файлу и отмечаем в таблице.
 *   4. Если неприменённых файлов нет, а в журнале остались записи о файлах,
 *      которых на диске уже нет, — записи удаляются. См. `pruneStale()`.
 *
 * Про снимок схемы и миграции. Схема впервые опубликована снимком:
 * `database/schema.sql` описывает ровно то, что стоит на работающих базах, а
 * история её сборки удалена — на новой инсталляции она не нужна, на двух
 * работающих она уже применена и повторный запуск только навредил бы.
 * Нумерация миграций начинается заново, с 001, и каждая новая миграция — это
 * шаг ПОСЛЕ снимка: она применяется к базам, на которых уже стоит состояние из
 * `schema.sql`, и её изменение обязано появиться в том же файле, иначе свежая
 * установка разойдётся с работающими.
 *
 * Чего этот класс сознательно не делает:
 *
 * - Не откатывает. DDL в MySQL неявно коммитит, поэтому «откат миграции» —
 *   это новая миграция, написанная в обратную сторону.
 * - Не чинит частично применённую миграцию. Если файл упал на третьем
 *   операторе из десяти, база осталась в промежуточном состоянии, а повторный
 *   запуск упадёт на первом уже выполненном. Поэтому в журнал пишется всё
 *   до последнего оператора: чинить руками, зная, где остановились.
 *
 * Гонки не бывает: применение под блокировкой базы (`GET_LOCK`), потому что
 * на боевом сервере один закат означает несколько параллельных запросов, и все
 * они одновременно решат, что база устарела.
 */
final class SchemaMigrator
{
    public const DEFAULT_LEDGER = 'schema_migrations';

    /** Имя блокировки: одна на базу, независимо от файлов на диске. */
    private const LOCK_NAME = 'myedu_schema_migrate';

    /** Таблица, по которой судим, что схема вообще развёрнута. */
    private const CORE_TABLE = 'users';

    /*
     * Ошибки, означающие «в базе уже то, что эта миграция собиралась сделать».
     *
     * Именно на них миграция и должна уметь падать. DDL в MySQL и MariaDB не
     * транзакционный: падение на третьем операторе из пяти оставляет первые два
     * сделанными, а повторный запуск упал бы на первом уже выполненном — и
     * миграция становилась неприменимой навсегда.
     *
     * Раньше это лечилось синтаксисом «если нет» прямо в SQL, но `ADD COLUMN
     * IF NOT EXISTS` и `DROP INDEX IF EXISTS` понимает только MariaDB: MySQL
     * отвергал такую миграцию на первом операторе, и она не применялась ни
     * разу. Теперь файлы пишутся обычным DDL, одинаково понимаемым обеими
     * базами, а повторяемость обеспечивает этот список.
     *
     * Коды — нативные номера MySQL и MariaDB, они совпадают:
     *
     *   1060 ER_DUP_FIELDNAME     колонка уже есть
     *   1061 ER_DUP_KEYNAME       индекс уже есть
     *   1091 ER_CANT_DROP_FIELD_OR_KEY  убираемого нет
     *   1826 ER_FK_DUP_NAME       внешний ключ с таким именем уже есть
     *
     * Чего этот список не делает намеренно: он не сверяет, что уже стоящая
     * колонка объявлена так же, как просит миграция. «Колонка `variant` уже
     * есть» и «колонка `variant` уже есть, но другого типа» для сервера
     * неразличимы. Расхождение ловится проверкой схемы на свежей установке,
     * а не догадкой мигратора.
     */
    private const ALREADY_DONE_ERRORS = [
        1060 => 'колонка уже есть',
        1061 => 'индекс уже есть',
        1091 => 'убираемого объекта нет',
        1826 => 'внешний ключ с таким именем уже есть',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
        private readonly string $ledger = self::DEFAULT_LEDGER,
        private readonly string $lockName = self::LOCK_NAME,
        private readonly ?SchemaState $target = null,
        /**
         * Резервная копия базы перед изменением схемы.
         *
         * Необязательна не по недосмотру, а потому что у мигратора есть
         * честные применения без боевой базы: установочная копия для проверок,
         * временные базы в тестах. Там копия не нужна и была бы помехой.
         *
         * В бою сервис передаётся всегда — из `database/migrate.php`, из
         * `SchemaGate` и `check_login.php`, — и снимается копия уже под
         * блокировкой миграций, чтобы параллельные запросы не сняли её
         * одновременно.
         */
        private readonly ?DatabaseDump $backup = null
    ) {
    }

    /**
     * Приводит журнал в соответствие с тем, что реально есть в базе.
     *
     * Ситуация, ради которой это нужно: база новая, а журнал пустой или
     * обрезанный. Обычное применение в этом случае бесполезно и вредно — первая
     * же старая миграция рассчитана на базу первого этапа, правит объекты, которых
     * давно нет, и падает. Сайт не поднимается, включая `/login`.
     *
     * Раз база совпадает с `database/schema.sql`, то есть с целевым состоянием
     * текущей версии, — миграции на диске уже применены, и починка состоит в
     * том, чтобы записать это в журнал. DDL не выполняется.
     *
     * Одно исключение, о котором стоит сказать прямо. Миграция, которая что-то
     * убирает, может оказаться отмечена применённой, хотя предмет удаления в
     * базе остался: сверка спрашивает «есть ли всё нужное», а лишние объекты не
     * трогает. Само по себе это безобидно — лишняя колонка, которой никто не
     * читает, работе не мешает, — но молчать об этом нельзя, поэтому такие
     * миграции попадают в `leftOver`, и журнал сообщает о них словами.
     *
     * Возвращается то, что реально удалось, и вердикт:
     *
     * @return array{
     *     repaired: list<string>,
     *     alreadyCurrent: bool,
     *     missing: list<string>,
     *     checked: int,
     *     leftOver: list<string>
     * }
     */
    public function reconcile(): array
    {
        $result = [
            'repaired' => [],
            'alreadyCurrent' => false,
            'missing' => [],
            'checked' => 0,
            'leftOver' => [],
        ];

        if (!$this->schemaExists()) {
            return $result;
        }

        $target = $this->target ?? SchemaState::fromSchemaFile(
            dirname($this->migrationsDir) . '/schema.sql'
        );

        // Файл схемы не прочитан: вердикта нет, и починка не делается.
        if (!$target->isParsed()) {
            return $result;
        }

        $comparison = $target->missingIn($this->db);
        $result['checked'] = $comparison['checked'];
        $result['missing'] = $comparison['missing'];

        if ($comparison['missing'] !== []) {
            return $result;
        }

        $this->ensureLedger();

        $pending = array_values(array_diff($this->available(), $this->applied()));

        if ($pending === []) {
            $result['alreadyCurrent'] = true;

            return $result;
        }

        foreach ($pending as $name) {
            $result['leftOver'] = array_merge($result['leftOver'], $this->undropped($name));
        }

        /*
         * Что-то из записанного на диске в базе осталось — это попадает в
         * отчёт, но починку не отменяет.
         *
         * Раньше здесь стоял запрет: пока предмет удаления есть, миграции не
         * отмечаются. Он оказался негодным. Таблицы `attempts`, `answers` и
         * `grades` убирала миграция 013 из ядра, а плагин теста заводит свои
         * таблицы с теми же именами — и проверка видела «не убрано» там, где
         * всё давно убрано. Один такой ложный отказ останавливал починку целиком.
         *
         * Поэтому проверка остаётся только отчётом для журнала: она честно
         * показывает расхождение, но не решает за починку, можно ли доверять
         * базе. Признать базу новой можно и по совпадению с целевой схемой:
         * лишние объекты базу старой не делают.
         */

        /*
         * Записи идут под той же блокировкой, что и применение: два запроса,
         * увидевшие пустой журнал, должны либо оба увидеть готовую починку,
         * либо оба ждать. Без этого один мог бы вклиниться между чтением
         * журнала и записью и попытаться применить миграцию повторно.
         */
        $lock = substr($this->lockName, 0, 64);

        if (!$this->acquireLock($lock)) {
            return $result;
        }

        try {
            // Под блокировкой список неприменённых перечитывается: он мог
            // измениться, пока ждали.
            foreach (array_diff($this->available(), $this->applied()) as $name) {
                try {
                    $this->db->run(
                        'INSERT INTO `' . $this->ledger . '` (filename, applied_at) VALUES (:name, NOW())',
                        ['name' => $name]
                    );
                } catch (\Throwable $e) {
                    /*
                     * Одна не записалась — починка неполная. Молча продолжить
                     * нельзя: в журнале останется дыра, и следующий запрос снова
                     * решит, что база старая. Прерванная починка не опаснее
                     * отсутствующей — обе чинятся повтором, — но в списке
                     * починенного окажется меньше, чем на диске, и это видно.
                     */
                    break;
                }

                $result['repaired'][] = $name;
            }

            $result['alreadyCurrent'] = $result['missing'] === []
                && array_diff($this->available(), $this->applied()) === [];
        } finally {
            $this->releaseLock($lock);
        }

        return $result;
    }

    /**
     * Есть ли база и развёрнута ли в ней схема.
     *
     * Различать это важно: на пустой базе применять миграции бессмысленно —
     * первая из них рассчитана на уже созданные таблицы, и упадёт. Первая
     * установка идёт импортом `database/schema.sql` через `public/install.php`,
     * и тот сразу отмечает все миграции применёнными.
     *
     * Отдельный вопрос — доступна ли база вообще: подключение может не
     * открыться, и это тоже не «нужна установка». Различить два случая можно
     * только по тому, дошла ли ошибка до сервера базы.
     */
    public function schemaExists(): bool
    {
        return $this->tableExists(self::CORE_TABLE);
    }

    /**
     * Применяет неприменённые миграции.
     *
     * `notes` — операторы, пропущенные как «в базе уже так». Применением они не
     * являются, но в журнал попадают: по ним видно, что миграция нашла базу не
     * в том состоянии, на которое рассчитана.
     *
     * @return array{
     *     status: string,
     *     applied: list<string>,
     *     repaired: list<string>,
     *     leftOver: list<string>,
     *     pruned: list<string>,
     *     message: string,
     *     file: string,
     *     statementIndex: int,
     *     statementCount: int,
     *     notes: list<string>
     * }
     *
     * status: `ok` — нечего было применять, `applied` — применено,
     * `repaired` — журнал приведён в соответствие с базой, миграции уже были
     * применены раньше, `install` — базы нет, `locked` — миграцию уже применяет
     * другой запрос, `failed` — упало, подробности в `message`.
     */
    public function apply(): array
    {
        $result = [
            'status' => 'ok',
            'applied' => [],
            'repaired' => [],
            'leftOver' => [],
            'pruned' => [],
            'message' => '',
            'file' => '',
            'statementIndex' => 0,
            'statementCount' => 0,
            'notes' => [],
        ];

        if (!$this->schemaExists()) {
            $result['status'] = 'install';

            return $result;
        }

        $this->ensureLedger();

        $files = $this->available();
        $done = $this->applied();

        $pending = [];

        foreach ($files as $name) {
            if (!in_array($name, $done, true)) {
                $pending[] = $name;
            }
        }

        if ($pending === []) {
            $this->pruneStale($result);

            return $result;
        }

        /*
         * Журнал пустой или обрезанный, а база — нет. Прежде чем применять
         * старые миграции, проверяем, не применены ли они уже: по журналу судить
         * нельзя, он и есть проблема, а по базе — можно. Если база совпадает с
         * целевой схемой, миграции отмечаются применёнными, и сайт поднимается.
         *
         * Порядок именно такой, а не наоборот: обычное применение здесь упало бы
         * на первой же миграции первого этапа и оставило бы сайт лежать до
         * ручной починки, то есть до случая, которого на шаред-хостинге не
         * рассчитывают.
         */
        $reconciled = $this->reconcile();

        if ($reconciled['repaired'] !== []) {
            $result['status'] = 'repaired';
            $result['repaired'] = $reconciled['repaired'];
            $result['leftOver'] = $reconciled['leftOver'];

            return $result;
        }

        if ($reconciled['alreadyCurrent']) {
            return $result;
        }

        $notes = [];

        /*
         * Сверка сказала, что базе чего-то не хватает: неприменённые миграции
         * действительно нужны. Пояснение попадает в журнал рядом с применением —
         * иначе выглядело бы так, будто сайт начал подниматься с нуля.
         */
        if ($reconciled['checked'] > 0) {
            $notes[] = sprintf(
                'База не совпадает с database/schema.sql: не хватает %d объектов, применяются миграции',
                count($reconciled['missing'])
            );
        }

        /*
         * Блокировка обязательна: боевой сервер обслуживает запросы параллельно,
         * и все они одновременно увидят неприменённые миграции. Без блокировки
         * второй запрос выполнил бы те же ALTER TABLE и упал бы с «Duplicate
         * column», и владелец увидел бы ошибку вместо тихого обновления.
         *
         * Имя блокировки не длиннее 64 знаков — ограничение MySQL, за которым
         * обрезается слишком длинное имя и блокировка перестаёт работать.
         */
        $lock = substr($this->lockName, 0, 64);

        if (!$this->acquireLock($lock)) {
            $result['status'] = 'locked';
            $result['message'] = 'Миграции применяет другой запрос';

            return $result;
        }

        try {
            /*
             * Копия снимается здесь, а не до блокировки: копий на один
             * миграционный запуск должна быть одна, а запросов на боевом
             * сервере одновременно несколько, и каждая из них увидит одни и те же
             * неприменённые миграции.
             *
             * Порядок тоже здесь: сначала копия, потом DDL. Иначе миграция,
             * упавшая на середине, оставила бы схему без точки отката.
             *
             * Починка журнала (`reconcile()`) копии не требует: она только
             * дописывает отметки применённого и не трогает объекты базы.
             */
            $this->backup?->take('миграциями ядра: ' . implode(', ', $pending));

            foreach ($pending as $name) {
                $outcome = $this->applyFile($name, $notes);

                if ($outcome !== null) {
                    $result['status'] = 'failed';
                    $result['file'] = $name;
                    $result['message'] = $outcome['message'];
                    $result['statementIndex'] = $outcome['index'];
                    $result['statementCount'] = $outcome['count'];

                    return $result;
                }

                $result['applied'][] = $name;
            }

            $result['status'] = 'applied';
            $result['notes'] = $notes;

            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Убирает из журнала записи о файлах, которых на диске уже нет.
     *
     * Зачем это нужно. Схема впервые опубликована снимком, и история её сборки
     * удалена. На двух работающих инсталляциях в `schema_migrations` остались
     * двадцать четыре записи об этих файлах. Они больше ничего не говорят:
     * неприменённые файлы считаются по диску, а не по журналу, поэтому такие
     * записи не влияют на работу — но их видно в отчёте `migrate.php` и в
     * диагностике, и там они выглядят как «применено двадцать четыре
     * миграции», то есть правдоподобно и неверно.
     *
     * Условия, при которых запись удаляется. Все три обязательны, и каждое
     * закрывает свой случай аварии:
     *
     * - каталог `migrations/` существует. Если его нет, это не «миграций нет»,
     *   а незагруженная половина файлов: на шаред-хостинге выкладывают по
     *   одной копии, и стирание журнала в этот момент сделало бы базу
     *   отстающей по-настоящему;
     * - `schema.sql` прочитан. Непрочитанный файл — это «не знаю», а не
     *   «миграций нет»;
     * - база совпадает со снимком. Отсталая база ничего удалять не должна:
     *   журнал пригодится, чтобы понять, что именно к ней применялось.
     *
     * Само удаление — под той же блокировкой, что и применение: несколько
     * параллельных запросов не должны вычитывать одну и ту же запись.
     *
     * @return list<string> что удалено
     */
    private function pruneStale(array &$result): array
    {
        $result['pruned'] = [];

        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $available = $this->available();

        if ($available !== []) {
            return [];
        }

        $stale = array_values(array_diff($this->applied(), $available));

        if ($stale === []) {
            return [];
        }

        $target = $this->target ?? SchemaState::fromSchemaFile(
            dirname($this->migrationsDir) . '/schema.sql'
        );

        if (!$target->isParsed()) {
            return [];
        }

        if ($target->missingIn($this->db)['missing'] !== []) {
            return [];
        }

        $lock = substr($this->lockName, 0, 64);

        if (!$this->acquireLock($lock)) {
            return [];
        }

        try {
            foreach (array_diff($this->applied(), $available) as $name) {
                $this->db->run(
                    'DELETE FROM `' . $this->ledger . '` WHERE filename = :name',
                    ['name' => $name]
                );

                $result['pruned'][] = $name;
            }

            if ($result['pruned'] !== []) {
                $result['notes'][] = sprintf(
                    'Журнал приведён в соответствие: удалено записей об удалённых файлах миграций — %d (%s)',
                    count($result['pruned']),
                    implode(', ', array_slice($result['pruned'], 0, 5))
                        . (count($result['pruned']) > 5 ? ', …' : '')
                );
            }
        } finally {
            $this->releaseLock($lock);
        }

        return $result['pruned'];
    }

    /**
     * Чего миграция собиралась убрать, а что в базе осталось.
     *
     * Нужно, чтобы починка не выдавала себя за выполненную работу. Миграция
     * `024_drop_work_max_attempts.sql` убирает колонку; если база ей
     * соответствует целевой схеме, а колонка вдруг осталась, отметить файл
     * применённым можно — на работу это не влияет, — но сказать об этом надо.
     *
     * Разбираются только операторы удаления. Если в файле есть что-то ещё,
     * предметом здесь не он, и вердикт по нему не выносится.
     *
     * @return list<string>
     */
    private function undropped(string $name): array
    {
        $sql = @file_get_contents($this->migrationsDir . '/' . $name);

        if ($sql === false) {
            return [];
        }

        $statements = self::splitStatements($sql);
        $leftover = [];
        $removals = 0;

        foreach ($statements as $statement) {
            if (preg_match('/^\s*SET\s+/i', $statement) === 1) {
                continue;
            }

            if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`([a-z0-9_]+)`/i', $statement, $m) === 1) {
                $removals++;

                if ($this->tableExists($m[1])) {
                    $leftover[] = 'таблица ' . $m[1];
                }

                continue;
            }

            if (preg_match(
                '/^\s*ALTER\s+TABLE\s+`([a-z0-9_]+)`\s+DROP\s+(COLUMN\s+)?`([a-z0-9_]+)`/i',
                $statement,
                $m
            ) === 1) {
                $removals++;

                if ($this->columnExists($m[1], $m[3])) {
                    $leftover[] = 'колонка ' . $m[1] . '.' . $m[3];
                }

                continue;
            }

            // Не удаление: по этому файлу судить не о чем.
            return [];
        }

        // Файл без удалений судить не о чем, а файл с удалениями — есть.
        return $removals === 0 ? [] : $leftover;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => $table, 'c' => $column]
        ) > 0;
    }

    /**
     * Состояние для показа: применённые, доступные, ждущие и устаревшие.
     *
     * `missing` — чего в базе не хватает до целевой схемы. Показывается рядом с
     * `pending`, потому что эти два списка отвечают на разные вопросы и вместе
     * объясняют картину: «в журнале не применено, но в базе всё на месте» —
     * это расхождение журнала, а «в базе не хватает» — это настоящая отсталость.
     *
     * `stale` — записи журнала о файлах, которых на диске нет. На работу они не
     * влияют, но в отчёте их видно, и без отдельного списка «применённых
     * миграций» их пришлось бы считать применёнными.
     *
     * @return array{
     *     applied: list<string>,
     *     available: list<string>,
     *     pending: list<string>,
     *     stale: list<string>,
     *     schemaExists: bool,
     *     missing: list<string>,
     *     checked: int
     * }
     */
    public function status(): array
    {
        $exists = $this->schemaExists();

        if (!$exists) {
            return [
                'applied' => [],
                'available' => $this->available(),
                'pending' => [],
                'stale' => [],
                'schemaExists' => false,
                'missing' => [],
                'checked' => 0,
            ];
        }

        $this->ensureLedger();

        $applied = $this->applied();
        $available = $this->available();

        $target = $this->target ?? SchemaState::fromSchemaFile(
            dirname($this->migrationsDir) . '/schema.sql'
        );

        $comparison = $target->isParsed()
            ? $target->missingIn($this->db)
            : ['checked' => 0, 'missing' => []];

        return [
            'applied' => $applied,
            'available' => $available,
            'pending' => array_values(array_diff($available, $applied)),
            'stale' => is_dir($this->migrationsDir)
                ? array_values(array_diff($applied, $available))
                : [],
            'schemaExists' => true,
            'missing' => $comparison['missing'],
            'checked' => $comparison['checked'],
        ];
    }

    /**
 * Миграции на диске, по порядку.
 *
 * Порядок задаёт имя файла: `002_...` должен идти после `001_...`, и
 * разбирать имена в попытке вывести порядок изнутри номера не нужно. Сейчас
 * на диске обычно пусто: снимок схемы заменил историю её сборки, и файлы
 * появляются заново, с `001_`.
 *
 * @return list<string>
 */
    public function available(): array
    {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $rows = $this->db->all('SELECT filename FROM `' . $this->ledger . '` ORDER BY filename');
        $names = array_map(static fn (array $row): string => (string) $row['filename'], $rows);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Применяет один файл и отмечает его в таблице миграций.
     *
     * Пропущенные операторы собираются в `$notes`: они не мешают применению,
     * но попасть в журнал должны.
     *
     * @param list<string> $notes
     *
     * @return array{message: string, index: int, count: int}|null null — применён
     */
    private function applyFile(string $name, array &$notes): ?array
    {
        $path = $this->migrationsDir . '/' . $name;
        $sql = @file_get_contents($path);

        if ($sql === false) {
            return [
                'message' => 'Файл миграции не читается: ' . $name,
                'index' => 0,
                'count' => 0,
            ];
        }

        $statements = self::splitStatements($sql);
        $count = count($statements);

        foreach ($statements as $index => $statement) {
            try {
                $this->db->run($statement);
            } catch (\Throwable $e) {
                $already = $this->alreadyDone($statement, $e);

                if ($already !== null) {
                    $notes[] = self::alreadyDoneNote($name, $index + 1, $statement, $already);

                    continue;
                }

                /*
                 * Возвращаем не только текст ошибки, но и сам оператор: по
                 * сообщению MySQL «Duplicate column name» не понять, какой
                 * именно, а база осталась в промежуточном состоянии и повторный
                 * запуск упадёт уже на другом месте.
                 */
                return [
                    'message' => sprintf(
                        'оператор %d из %d: %s — %s',
                        $index + 1,
                        $count,
                        $e->getMessage(),
                        self::shorten($statement)
                    ),
                    'index' => $index + 1,
                    'count' => $count,
                ];
            }
        }

        try {
            $this->db->run(
                'INSERT INTO `' . $this->ledger . '` (filename, applied_at) VALUES (:name, NOW())',
                ['name' => $name]
            );
        } catch (\Throwable $e) {
            /*
             * Операторы выполнились, а отметка не легла — повторная попытка
             * упадёт на первом же ALTER. Это отдельный случай, и говорить о нём
             * нужно прямо: база уже изменена, а миграция не помечена.
             */
            return [
                'message' => 'миграция выполнена, но не отмечена в ' . $this->ledger . ': ' . $e->getMessage()
                    . '. Отметьте её вручную, иначе повторный запуск упадёт на уже выполненных операторах.',
                'index' => 0,
                'count' => $count,
            ];
        }

        return null;
    }

    /**
     * Ошибка, которую стоит считать успехом, — или null, если это настоящий сбой.
     *
     * Проверяются две вещи: что ошибка означает «в базе уже так» и что оператор
     * действительно меняет схему. Без второй проверки список был бы шире, чем
     * нужно: те же коды у INSERT означали бы совсем другое.
     */
    private function alreadyDone(string $statement, \Throwable $e): ?string
    {
        if (!preg_match('/^\s*(ALTER|CREATE|DROP|RENAME)\b/i', $statement)) {
            return null;
        }

        $code = self::nativeErrorCode($e);
        $reason = self::ALREADY_DONE_ERRORS[$code] ?? null;

        if ($reason !== null) {
            return $reason;
        }

        return $this->foreignKeyAlreadyExists($statement, $code);
    }

    /**
     * Повторное добавление внешнего ключа с уже занятым именем на MariaDB.
     *
     * MySQL отвечает на это кодом 1826, а MariaDB — 1005 с errno 121
     * «Duplicate key on write or update». Код 121 слишком общий, чтобы считать
     * успехом: тем же сервер отвечает и на настоящую неразрешимую базу, и
     * молча проглотить такое — значит оставить сайт без внешнего ключа,
     * решив, что всё в порядке.
     *
     * Поэтому проверяется не код, а состояние: если внешний ключ с таким именем
     * в базе действительно есть, повтор был безобидным. Нет — настоящий сбой,
     * и он должен упасть.
     */
    private function foreignKeyAlreadyExists(string $statement, int $code): ?string
    {
        if ($code !== 1005) {
            return null;
        }

        if (!preg_match(
            '/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY\b/i',
            $statement,
            $m
        )) {
            return null;
        }

        $exists = (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE constraint_schema = DATABASE() AND table_name = :t AND constraint_name = :n',
            ['t' => $m[1], 'n' => $m[2]]
        );

        return $exists > 0
            ? 'внешний ключ «' . $m[2] . '» уже есть (MariaDB отвечает на повтор 1005, а не 1826)'
            : null;
    }

    /**
     * Нативный номер ошибки MySQL, а не SQLSTATE.
     *
     * PDOException::getCode() отдаёт SQLSTATE — строку вроде «42S21», — и
     * приведение её к целому даёт 42, то есть номер, не встречающийся среди
     * ошибок сервера. Настоящий номер лежит в errorInfo[1], и без него ни один
     * случай из списка не распознался бы: миграция падала бы ровно так же, как
     * до появления списка. У прочих исключений остаётся getCode().
     */
    private static function nativeErrorCode(\Throwable $e): int
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        return (int) $e->getCode();
    }

    /**
     * Заметка для журнала: оператор пропущен, потому что база уже в нужном виде.
     *
     * Само по себе это не сбой, но молчать об этом нельзя: по журналу видно, что
     * миграция применялась к базе, стоявшей не в том состоянии, на которое она
     * рассчитана, — и человек узнаёт об этом до следующей выкатки.
     *
     * Пишет в файл не мигратор, а вызывающий: путь к журналу у него свой, и
     * второй такой же путь в миграторе рано или поздно разошёлся бы с первым.
     */
    private static function alreadyDoneNote(string $file, int $index, string $statement, string $reason): string
    {
        return sprintf(
            '%s: оператор %d пропущен — %s — %s',
            $file,
            $index,
            $reason,
            self::shorten($statement)
        );
    }

    private function ensureLedger(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS `' . $this->ledger . '` (
                `filename`   VARCHAR(191) NOT NULL,
                `applied_at` DATETIME     NOT NULL,
                PRIMARY KEY (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Есть ли таблица — через information_schema, а не «SELECT из неё».
     *
     * Проба SELECT'ом отличила бы «таблицы нет» от «нет прав» только по тексту
     * ошибки, а он зависит от версии и локали сервера.
     */
    private function tableExists(string $table): bool
    {
        return $this->db->value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        ) !== 0;
    }

    /**
     * Сколько ждать чужую миграцию, прежде чем сказать «обновляется».
     *
     * Ждать нужно: обычно блокировку держит параллельный запрос того же
     * человека, и через секунду всё уже применено. Но ждать долго тоже нельзя —
     * страница висела бы впустую, и человек решил бы, что сайт сломался.
     * Поэтому ждём недолго и отдаём внятный ответ с просьбой обновить страницу:
     * он сработает и на миграции, которая идёт дольше.
     */
    private const LOCK_WAIT_SECONDS = 10;

    private function acquireLock(string $lock): bool
    {
        return (int) $this->db->value(
            'SELECT GET_LOCK(:name, :wait)',
            ['name' => $lock, 'wait' => self::LOCK_WAIT_SECONDS]
        ) === 1;
    }

    private function releaseLock(string $lock): void
    {
        try {
            $this->db->run('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        } catch (\Throwable) {
            // Освободит и обрыв соединения: блокировка в MySQL держится до конца сессии.
        }
    }

    private static function shorten(string $statement, int $limit = 200): string
    {
        $oneLine = preg_replace('/\s+/', ' ', $statement) ?? '';

        return mb_strlen($oneLine) > $limit ? mb_substr($oneLine, 0, $limit) . '…' : $oneLine;
    }

    /**
     * Разбивает SQL-файл на отдельные операторы.
     *
     * PDO выполняет только один оператор за вызов, а миграция содержит десятки.
     * Разбор учитывает строковые литералы, экранирование и комментарии, иначе
     * точка с запятой внутри данных или комментария разорвала бы оператор.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        /*
         * Метка порядка байтов UTF-8 в начале файла — не часть текста, а
         * признак кодировки, и в SQL она недопустима. Панели хостинга и
         * редакторы на разных машинах добавляют её при сохранении, и без этой
         * строки первая же миграция падала бы с «You have an error in your SQL
         * syntax» прямо на невидимом символе — то есть совсем не в том месте,
         * где искали.
         */
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Комментарии до конца строки
            if ($quote === null && $char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($quote === null && $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            // Блочные комментарии
            if ($quote === null && $char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            // Строковые литералы
            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote === "'") {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    // Двойная кавычка внутри одинарной не закрывает строку
                    if ($quote === "'" && $next === "'") {
                        $current .= $next;
                        $i++;
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}