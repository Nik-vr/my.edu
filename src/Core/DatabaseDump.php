<?php

declare(strict_types=1);

namespace MyEdu\Core;

use RuntimeException;
use Throwable;

/**
 * Резервная копия базы перед применением миграций.
 *
 * Миграция меняет схему, а DDL в MySQL не откатывается: `DROP COLUMN` или
 * `DROP TABLE`, применённые по ошибке, возвращаются только из копии. Поэтому
 * копия снимается не по расписанию, а в последний момент перед изменением
 * схемы — там, где её и ждали.
 *
 * Копия считается снятой не по факту запуска утилиты, а после проверки файла:
 * пустой или оборванный дамп — это не точка отката, а её отсутствие, и
 * молчаливый «снимок» хуже явного отказа. Проверяются три вещи: утилита
 * закончилась с нулём, файл не пуст, и в нём есть `CREATE TABLE` для каждой
 * таблицы базы. Обрезанный посередине дамп этому не удовлетворяет.
 *
 * **Два способа снять и почему второй не замалчивается.** Сначала пробуется
 * утилита `mariadb-dump`/`mysqldump`. Если её на сервере нет — а на шаред-хостинге
 * её обычно нет — копия снимается средствами PHP (`PhpDatabaseDump`): сайт же
 * работает, значит есть PHP и есть доступ к базе. Такой способ всегда пишется в
 * журнал отдельной строкой, потому что он медленнее, и об этом стоит знать заранее.
 *
 * Обратное — не так: утилита есть, но упала, — это отказ, а не повод молча
 * перейти на другой способ. Иначе настоящая поломка выглядела бы как «снято
 * другим путём», и разбираться пришлось бы уже после того, как миграция
 * применена.
 *
 * **Что в копию не входит и почему это осознанно.** Файлы проекта — картинки
 * заданий в `public/uploads/`, конфигурация — копируются на сервере средствами
 * сервера. Здесь речь только о базе: она лежит на той же машине, что и код,
 * и её потеря означает потерю результатов учеников.
 *
 * **Утилита.** Её путь ищется рядом с работающим сервером базы, в `PATH` и в
 * стандартных каталогах `/usr/bin` и подобных — под обоими именами
 * (`mariadb-dump`, `mysqldump`) и без расширения, как на Linux. Если утилиты нет,
 * обойти это можно и молча — см. выше про `PhpDatabaseDump`; осознанно отключить
 * копию — см. `disable()`.
 *
 * Реквизиты доступа передаются не в командной строке, а временным файлом
 * `--defaults-extra-file`: строка запуска видна в списке процессов, и пароль
 * базы там оседать не должен.
 */
final class DatabaseDump
{
    /** Куда кладутся копии. Внутри проекта, рядом с журналами. */
    public const DIRECTORY = '/var/backup';

    /**
     * Сколько последних копий хранить.
     *
     * Копии снимаются только перед миграциями, а их на единицу меньше, чем
     * релизов, поэтому десяти хватает за глаза. Удаление старых — здесь же:
     * папка, которую никто не чистит, через год занимает место и никому не
     * нужна.
     */
    private const KEEP_DEFAULT = 10;

    /** Минимальный размер осмысленного дампа: пустой файл — не копия. */
    private const MIN_BYTES = 1024;

    /**
     * Имена утилиты снятия копии, в порядке предпочтения.
     *
     * Оба, потому что это разные базы, а не разные версии одной: у MariaDB
     * утилита называется `mariadb-dump`, у MySQL и у старых сборок MariaDB —
     * `mysqldump`. Хостинг с MySQL здесь так же вероятен, как с MariaDB.
     */
    private const DUMP_NAMES = ['mariadb-dump', 'mysqldump'];

    /** Имена клиента, которым копию заливают обратно. */
    private const CLIENT_NAMES = ['mariadb', 'mysql'];

    /**
     * Расширения, которые проверяются у каждого имени.
     *
     * Пустое расширение — не опечатка: на Unix его нет, и проверка только `.exe`
     * означала бы, что на любом Linux-хостинге утилита «не найдена» при полном
     * её наличии. Порядок неважен: каталог просматривается целиком.
     */
    private const SUFFIXES = ['', '.exe', '.bat', '.cmd'];

/**
     * Каталоги, где MariaDB стоит на машине разработчика.
     *
     * Windows: `modules/database/MariaDB-<версия>/bin`. Номер версии меняется
     * при обновлении, поэтому каталоги перебираются, а не зашиваются.
     *
     * У OSPanel два вида сборки: с промежуточным каталогом `database` и без него.
     * Пропуск второго означал бы, что на такой машине утилита не находится вовсе,
     * а с обязательной копией перед миграцией это останавливает сайт — и виноват
     * оказывается список каталогов, а не человек.
     */
    private const NEAR_SERVER_DIRS = [
        'c:/OSPanel/modules/database',
        'c:/OSPanel/modules',
        'D:/OpenServer/modules/database',
        'C:/xampp/mysql/bin',
    ];

    /**
     * Стандартные каталоги Linux и macOS.
     *
     * На дешёвом хостинге `mariadb-dump` обычно лежит именно в `/usr/bin`, и
     * без этого списка копия перед миграцией была бы невозможна при полном
     * наличии утилиты. Каталоги, которых нет, просто пропускаются.
     */
    private const UNIX_DIRS = [
        '/usr/bin',
        '/usr/local/bin',
        '/bin',
        '/usr/sbin',
        '/opt/mysql/bin',
        '/opt/mariadb/bin',
        '/usr/local/mysql/bin',
    ];

    private bool $disabled = false;

    private ?string $lastError = null;

    public function __construct(
        private readonly App $app,
        private readonly Config $config,
        /**
         * Соединение нужно только для сверки: сколько таблиц в базе, столько
         * обязано быть в копии. Скрипту, которому утилиты нужны, а копия не
         * снимается (копирование рабочей базы в тестовую), соединение не
         * требуется, и создавать его ради этого незачем.
         */
        private readonly ?Database $db = null,
        /**
         * Блокировать ли миграцию, если копия не снялась.
         *
         * Разница между командой и сайтом. В команде миграцию запускает человек,
         * который смотрит на экран: сказать «копия не снята, миграция не
         * применена» — правильный ответ. На сайте миграции применяет первый
         * же заход на страницу, и запрет на этом превращался бы в
         * «до устранения неисправности сайт не открывается» — ценой работоспособности
         * ради страховки, которая может и не понадобиться. Поэтому по умолчанию
         * сайт предупреждает и применяет, а команда — отказывает.
         */
        private readonly ?bool $blocking = null,
        /**
         * Каталог для копий; по умолчанию — `var/backup` внутри проекта.
         *
         * Переопределение нужно не «на всякий случай», а проверкам: набор
         * тестов снимает настоящую копию настоящей базы, и класть её рядом с
         * боевыми копиями нельзя — проверка удаляла бы их как устаревшие.
         */
        private readonly ?string $directory = null
    ) {
    }

    /**
     * Снять копию или узнать, почему не снялась.
     *
     * @param string $reason зачем понадобилась: попадёт в журнал и в текст отказа
     * @return string|null путь к файлу; null — копия не снималась по решению или
     *                     снятие не удалось и запрет не задан
     * @throws RuntimeException копия не снялась, а запрет задан
     */
    public function take(string $reason): ?string
    {
        $this->lastError = null;

        if ($this->disabled) {
            return null;
        }

        try {
            $path = $this->write($reason);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->log('ОТКАЗ (' . $reason . '): ' . $e->getMessage());

            if ($this->isBlocking()) {
                throw new RuntimeException($this->refusal($reason, $e->getMessage()), 0, $e);
            }

            /*
             * Сайт продолжает работать без копии — но молчать об этом нельзя:
             * отказ виден только в этом журнале, а миграция при этом применится.
             */
            $this->log('Продолжаю без копии: запрет не задан (security.backup_before_migrations_blocking).');

            return null;
        }

        $this->prune($path);

        return $path;
    }

    /**
     * Сознательно не снимать копию.
     *
     * Отдельный вызов вместо молчаливого поведения по умолчанию: пропуск
     * копии перед миграцией — решение человека, и в журнале должно быть видно,
     * кто и по какой причине его принял.
     */
    public function disable(string $reason): void
    {
        $this->disabled = true;

        $this->log('Копия не снималась по решению: ' . $reason);
    }

    /** Причина последнего отказа; null — отказа не было. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Запрещено ли останавливать миграцию при отказе. */
    public function isBlocking(): bool
    {
        return $this->blocking ?? $this->config->bool('security.backup_before_migrations_blocking', false);
    }

    /**
     * Утилита снятия копии.
     *
     * null — утилиты нет. Вызывающий решает, что это значит: снятие копии
     * обязано падать, а разовая выгрузка для переноса на тестовую базу —
     * просто объяснять, что делать.
     */
    public function dumpBinary(): ?string
    {
        return $this->binary(self::DUMP_NAMES, 'MYSQLDUMP');
    }

    /** Утилита выполнения SQL: нужна там, где копию заливают, а не снимают. */
    public function clientBinary(): ?string
    {
        return $this->binary(self::CLIENT_NAMES, 'MYSQL');
    }

    /**
     * Где искалась утилита и какими именами — для страницы диагностики.
     *
     * Ответ «не найдено» бесполезен сам по себе: человек не знает, где смотреть.
     * Поэтому возвращается и перечень мест, которые уже проверены.
     *
     * @return array<string,list<string>>
     */
    public function searchedIn(): array
    {
        return [
            'каталоги PATH' => $this->pathDirectories(),
            'рядом с сервером базы' => $this->nearServerDirectories(),
            'стандартные для Linux и macOS' => self::UNIX_DIRS,
            'точный путь можно задать переменной' => ['MYSQLDUMP (снятие копии)', 'MYSQL (загрузка копии)'],
        ];
    }

    /**
     * Найти утилиту MariaDB или MySQL.
     *
     * Порядок такой, какой и должен быть на живой машине, где ничего не
     * настроено специально под этот проект:
     *
     * 1. переменная окружения — на сервере утилита может стоять где угодно, и
     *    перебор каталогов её не найдёт вовсе;
     * 2. `PATH` — на Linux-хостинге `mariadb-dump` обычно именно там, но с
     *    другим именем, чем на Windows;
     * 3. каталоги рядом с работающим сервером базы — на этой машине MariaDB стоит
     *    из OpenServer, и `PATH` про неё ничего не знает;
     * 4. стандартные `/usr/bin` и подобные — последний круг, но на дешёвом
     *    хостинге это чаще всего и есть ответ.
     *
     * Имена проверяются оба: `mariadb-dump` у MariaDB и `mysqldump` у MySQL и
     * старых сборок MariaDB. Отсутствие `.exe` на Unix — не опечатка, там
     * расширения нет.
     *
     * @param list<string> $names  искомые имена, без пути
     * @param string       $envVariable имя переменной с путём к утилите
     */
    private function binary(array $names, string $envVariable): ?string
    {
        $fromEnv = (string) (getenv($envVariable) ?: '');

        if ($fromEnv !== '') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $fromEnv);

            return is_file($path) ? $path : null;
        }

        $directories = array_merge(
            $this->pathDirectories(),
            $this->nearServerDirectories(),
            self::UNIX_DIRS
        );

        foreach ($directories as $directory) {
            foreach ($names as $name) {
                foreach (self::SUFFIXES as $suffix) {
                    $candidate = $directory . DIRECTORY_SEPARATOR . $name . $suffix;

                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /** Каталоги из `PATH` — там утилита оказывается на любой unix-машине. */
    private function pathDirectories(): array
    {
        $path = (string) (getenv('PATH') ?: '');

        if ($path === '') {
            return [];
        }

        $directories = [];

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory);

            if ($directory !== '' && is_dir($directory)) {
                $directories[] = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
            }
        }

        return $directories;
    }

    /**
     * Временный файл с реквизитами доступа для утилиты.
     *
     * Обязателен `forgetCredentials()` — в `finally`, а не «когда вспомнят»:
     * файл с паролем не должен переживать команду.
     */
    public function credentialsFile(): string
    {
        $database = $this->config->array('database');

        $path = (string) tempnam(sys_get_temp_dir(), 'myedu-dump-');

        if ($path === '') {
            throw new RuntimeException('Не создался временный файл для реквизитов доступа');
        }

        $lines = [
            '[client]',
            'host=' . (string) ($database['host'] ?? '127.0.0.1'),
            'port=' . (string) ($database['port'] ?? 3306),
            'user=' . (string) ($database['user'] ?? 'root'),
        ];

        if ((string) ($database['password'] ?? '') !== '') {
            $lines[] = 'password=' . (string) $database['password'];
        }

        file_put_contents($path, implode("\n", $lines) . "\n");

        // На Windows chmod ничего не делает, на Unix без него файл читает любой,
        // а пароль базы читать не должен никто, кроме владельца.
        @chmod($path, 0600);

        return $path;
    }

    /** Удалить временный файл с реквизитами. */
    public function forgetCredentials(): void
    {
        foreach ((array) glob(sys_get_temp_dir() . '/myedu-dump-*') as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Запустить утилиту без оболочки.
     *
     * Файловые дескрипторы передаются процессу напрямую — без `>`, `<` и `2>`,
     * поэтому кодировка не зависит от кодовой страницы консоли и кириллица в
     * копии остаётся кириллицей. Через `cmd.exe` пришлось бы удваивать внешние
     * кавычки, а строка, начинающаяся с кавычки, разбиралась бы по своим
     * правилам и давала «не является внутренней или внешней командой».
     *
     * @param list<string> $command    утилита с аргументами, без оболочки
     * @param string|null  $inputFile  что подать на вход; null — пустой вход
     * @param string       $outputFile куда писать вывод
     * @param string       $errorFile  куда писать ошибки
     * @return int код завершения; именно его возвращает `proc_close()`
     */
    public function runTool(array $command, ?string $inputFile, string $outputFile, string $errorFile): int
    {
        $descriptors = [
            $inputFile === null ? ['pipe', 'r'] : ['file', $inputFile, 'r'],
            ['file', $outputFile, 'w'],
            ['file', $errorFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return 1;
        }

        // Неиспользуемый канал закрывается сразу: иначе процесс ждёт его вечно.
        if ($inputFile === null) {
            fclose($pipes[0]);
        }

        /*
         * Код завершения даёт именно proc_close(): значение, полученное сразу
         * после proc_open, — это ресурс процесса, а не число. Возвращённое
         * раньше всегда было «успех», и отказ выглядел так, будто копия снята.
         */
        return proc_close($process);
    }

    /**
     * Снять копию и убедиться, что она пригодна.
     *
     * @throws RuntimeException утилиты нет и снять нечем, она упала или файл
     *                          не прошёл проверку
     */
    private function write(string $reason): string
    {
        $directory = $this->directory();
        $path = $directory . DIRECTORY_SEPARATOR . $this->fileName();
        $binary = $this->dumpBinary();

        if ($binary === null) {
            if ($this->db === null) {
                throw new RuntimeException(
                    'на сервере нет mariadb-dump, а снять копию средствами PHP нечем: '
                    . 'сервис создан без соединения с базой'
                );
            }

            /*
             * Утилиты нет — это не поломка, а обычное дело шаред-хостинга. Раз
             * копия всё равно снимается, способ записывается в журнал отдельной
             * строкой: он медленнее, и это надо видеть, а не выводить из молчания
             * по факту медленной загрузки страницы.
             */
            $this->log('Утилита mariadb-dump не найдена, копия снимается средствами PHP — ' . $reason);

            (new PhpDatabaseDump($this->db, $this->config))->write($path);
            $this->verifyOrDrop($path);

            $this->log(sprintf(
                'Копия снята средствами PHP: %s (%s, таблиц: %d) — %s',
                $path,
                $this->humanSize((int) @filesize($path)),
                $this->tableCountInDump($path),
                $reason
            ));

            return $path;
        }

        $errorFile = (string) tempnam(sys_get_temp_dir(), 'myedu-dump-err-');

        $credentials = $this->credentialsFile();

        try {
            $code = $this->runTool(
                [
                    $binary,
                    '--defaults-extra-file=' . $credentials,
                    '--default-character-set=' . $this->charset(),
                    /*
                     * Единая транзакция: копия не должна собираться из разных
                     * моментов времени. Блокировки при этом не берутся, иначе
                     * дамп встал бы на минуту на каждую таблицу.
                     */
                    '--single-transaction',
                    '--skip-lock-tables',
                    '--add-drop-table',
                    /*
                     * --routines и --events намеренно не указаны: хранимых
                     * процедур, функций, триггеров и событий в проекте нет
                     * (проверено по всем *.sql), а права на них требуются
                     * отдельные и на дешёвом хостинге их может не быть. Копия
                     * из-за лишнего ключа снялась бы хуже, а не лучше.
                     */
                    (string) $this->config->array('database')['name'],
                ],
                null,
                $path,
                $errorFile
            );

            $stderr = $errorFile === '' ? '' : (string) @file_get_contents($errorFile);
        } finally {
            $this->forgetCredentials();

            if ($errorFile !== '') {
                @unlink($errorFile);
            }
        }

        if ($code !== 0) {
            @unlink($path);

            throw new RuntimeException(trim($stderr) === '' ? 'утилита снятия копии завершилась с кодом ' . $code : trim($stderr));
        }

        $this->verifyOrDrop($path);

        $this->log(sprintf(
            'Копия снята: %s (%s, таблиц: %d) — %s',
            $path,
            $this->humanSize((int) @filesize($path)),
            $this->tableCountInDump($path),
            $reason
        ));

        return $path;
    }

    /**
     * Проверить копию и убрать её, если она не годна.
     *
     * Негодная копия удаляется, а не остаётся «на всякий случай»: файл, который
     * называется копией, но не прошёл сверку, выглядит выполненной задачей и
     * обнаружится только в момент отката — когда проверять уже поздно.
     *
     * @throws RuntimeException
     */
    private function verifyOrDrop(string $path): void
    {
        try {
            $this->verify($path);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }
    }

    /**
     * Проверить, что в файле есть то, ради чего он снимался.
     *
     * Только размер было бы недостаточно: оборванный файл в несколько
     * килобайт размером настоящий. Поэтому сравнивается число таблиц в копии
     * с числом таблиц в базе — обрезанный посередине дам не дотягивает.
     *
     * @throws RuntimeException
     */
    private function verify(string $path): void
    {
        $size = (int) @filesize($path);

        if ($size < self::MIN_BYTES) {
            throw new RuntimeException('файл копии пуст или почти пуст (' . $size . ' байт)');
        }

        if ($this->db === null) {
            throw new RuntimeException(
                'сервис создан без соединения с базой, а копию снять без сверки нельзя'
            );
        }

        $expected = (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = :type',
            ['type' => 'BASE TABLE']
        );

        $inDump = $this->tableCountInDump($path);

        if ($expected > 0 && $inDump < $expected) {
            throw new RuntimeException(sprintf('в копии %d таблиц из %d: файл оборван', $inDump, $expected));
        }
    }

    /** Сколько `CREATE TABLE` в файле копии. */
    private function tableCountInDump(string $path): int
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            return 0;
        }

        return substr_count($content, 'CREATE TABLE');
    }

    /**
     * Удалить копии старше, чем нужно, кроме указанной.
     *
     * Открытый метод, а не только внутренний шаг снятия: подчистить папку
     * можно и отдельно от копии, не создавая её ради уборки.
     *
     * @param string $keepPath файл, который переживает уборку: та самая свежая
     *                         копия, ради которой всё и затевалось
     */
    public function prune(string $keepPath): void
    {
        $keep = max(1, $this->config->int('security.backup_keep', self::KEEP_DEFAULT));
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.sql');

        if ($files === false || count($files) <= $keep) {
            return;
        }

        $withTime = [];

        foreach ($files as $file) {
            $withTime[$file] = (int) @filemtime($file);
        }

        // Свежие сверху: равные метки (копии одного запуска) разводятся по имени,
        // иначе порядок между ними зависит от порядка чтения каталога.
        arsort($withTime, SORT_NUMERIC);

        $removed = 0;

        foreach (array_keys($withTime) as $position => $file) {
            /*
             * Первые $keep по времени остаются, иначе уборка съела бы самую
             * свежую копию — ту самую, ради которой всё затевалось.
             */
            if ($position < $keep) {
                continue;
            }

            if ($file === $keepPath) {
                continue;
            }

            if (@unlink($file)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->log('Удалено старых копий: ' . $removed . ', хранится последних ' . $keep);
        }
    }

    /** Текст отказа: что случилось и что делать дальше. */
    private function refusal(string $reason, string $detail): string
    {
        return 'Не снята резервная копия базы перед ' . $reason . ': ' . $detail
            . ' Не сработал ни один способ: утилита mariadb-dump и снятие средствами PHP.'
            . ' Если утилита на сервере есть, укажите её путь (MYSQLDUMP=/полный/путь/mariadb-dump),'
            . ' иначе снимите копию вручную; сознательно пропустить можно:'
            . ' --skip-backup или MYEDU_SKIP_BACKUP=1.';
    }

    /** Каталог копий; создаётся вместе с правами на запись. */
    private function directory(): string
    {
        $directory = $this->directory ?? $this->app->rootDir() . self::DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('не создался каталог копий: ' . $directory);
        }

        if (!is_writable($directory)) {
            throw new RuntimeException('каталог копий недоступен для записи: ' . $directory);
        }

        return $directory;
    }

    /**
     * Имя файла копии: база, дата и время.
     *
     * В минуту может быть снято несколько копий (два миграционных запуска подряд),
     * и молча перезаписать предыдущую нельзя: та самая точка отката может быть
     * единственной.
     */
    private function fileName(): string
    {
        $name = (string) $this->config->array('database')['name'];

        /*
         * Секунды, а не минуты: два миграционных запуска подряд в одну минуту
         * не должны превращаться в один файл. Замыкающая `M` в формате `Ymd_HiM`
         * дала бы название месяца («Oct») — `m` это месяц, а `M` тоже месяц,
         * только буквами.
         */
        $stamp = date('Ymd_His');
        $directory = $this->directory();

        $candidate = $name . '_' . $stamp . '.sql';
        $suffix = 2;

        while (file_exists($directory . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $name . '_' . $stamp . '_' . $suffix . '.sql';
            $suffix++;
        }

        return $candidate;
    }

    private function charset(): string
    {
        return (string) ($this->config->array('database')['charset'] ?? 'utf8mb4');
    }

    /**
     * Каталоги рядом с работающим сервером базы.
     *
     * Windows-машина разработки: MariaDB стоит из OpenServer или OSPanel, её
     * каталог — `modules/database/MariaDB-*`, и утилита лежит в его `bin`.
     * Номер версии в имени меняется при обновлении, поэтому каталоги
     * перебираются, а не зашиваются.
     *
     * @return list<string>
     */
    private function nearServerDirectories(): array
    {
        $found = [];

        foreach (self::NEAR_SERVER_DIRS as $base) {
            if (!is_dir($base)) {
                continue;
            }

            foreach (glob($base . '/MariaDB-*', GLOB_ONLYDIR) ?: [] as $dir) {
                $bin = str_replace('/', DIRECTORY_SEPARATOR, $dir . '/bin');

                if (is_dir($bin)) {
                    $found[] = $bin;
                }
            }
        }

        return $found;
    }

    /**
     * Запись в журнал копий.
     *
     * Отдельный файл, а не общий журнал ошибок: по нему видно, когда копия
     * снималась в последний раз, а не «что-то где-то писалось».
     */
    private function log(string $line): void
    {
        $directory = $this->app->rootDir() . '/var/log';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        if (!is_writable($directory)) {
            return;
        }

        @file_put_contents(
            $directory . '/backup-' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
            FILE_APPEND
        );
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' МБ'
            : max(1, (int) round($bytes / 1024)) . ' КБ';
    }
}