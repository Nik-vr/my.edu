<?php

declare(strict_types=1);

namespace MyEdu\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Тонкая обёртка над PDO.
 *
 * Требования к хостингу: расширение pdo_mysql — практически всегда есть.
 * Эмуляция prepares отключена: защита от инъекций не должна зависеть от настроек сервера.
 */
final class Database
{
    private PDO $pdo;

    /** @var array<string,PDOStatement> готовится по одному на текст запроса */
    private array $cache = [];

    private int $savepointCounter = 0;

    /** Пояс приложения из app.timezone: пустая строка — не задан, базу не трогаем. */
    private readonly string $timezone;

    public function __construct(array $config, string $timezone = '')
    {
        $this->timezone = trim($timezone);
        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $name = (string) ($config['name'] ?? '');
        $user = (string) ($config['user'] ?? 'root');
        $password = (string) ($config['password'] ?? '');
        $socket = trim((string) ($config['socket'] ?? ''));

        // Сокет задан явно — это ответ на «база есть, но по TCP не отвечает».
        if ($socket !== '') {
            $this->connect(
                sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $name, $charset),
                $user,
                $password,
                sprintf('сокет %s', $socket)
            );

            return;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);

        /*
         * Пробуем адрес из конфигурации, а если не вышло и адрес указывает на
         * эту же машину — ещё и противоположную запись.
         *
         * Причина не в аккуратности конфигурации: на шаред-хостинге MySQL
         * обычно слушает только unix-сокет, и обращение к нему по имени
         * «localhost» идёт через сокет, а по адресу 127.0.0.1 — по TCP, где
         * его может просто не быть. Ответ «Connection refused» означает не то,
         * что базы нет, а что не по тому адресу.
         */
        $targets = self::connectionTargets($host, $port);
        $errors = [];

        foreach ($targets as $index => $target) {
            try {
                $this->connect(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $target[0], $target[1], $name, $charset),
                    $user,
                    $password,
                    $target[0] . ':' . $target[1]
                );

                if ($index > 0) {
                    $this->noteFallback($host, $target[0]);
                }

                return;
            } catch (PDOException $e) {
                $errors[] = $e;
            }
        }

        throw new \RuntimeException(
            self::connectionMessage($targets, $errors),
            (int) ($errors[0]->getCode() ?? 0),
            $errors[0]
        );
    }

    /**
     * Адреса, которые имеет смысл попробовать, по порядку.
     *
     * IPv6 намеренно не пробуется: лишняя попытка лишь заменяет внятную
     * причину отказа невнятной («Требуемый адрес для своего контекста
     * неверен»), а базы на IPv6-loopback в хостинге не бывает.
     *
     * @return list<array{0:string,1:int}>
     */
    private static function connectionTargets(string $host, int $port): array
    {
        $targets = [[$host, $port]];

        $loopback = ['127.0.0.1', 'localhost'];

        if (in_array(strtolower($host), $loopback, true)) {
            foreach ($loopback as $candidate) {
                if ($candidate !== $host && !in_array([$candidate, $port], $targets, true)) {
                    $targets[] = [$candidate, $port];
                }
            }
        }

        return $targets;
    }

    private function connect(string $dsn, string $user, string $password, string $target): void
    {
        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException(
                $target . ': ' . $e->getMessage(),
                (int) $e->getCode()
            );
        }

        // Единый формат дат и чисел во всём приложении.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        /*
         * Часы базы — в поясе приложения.
         *
         * Приложение пишет все даты функцией date() по app.timezone, а окна
         * доступности выдач и штампы базы считают свои NOW(). Пояс MySQL на
         * хостинге свой и чаще всего UTC — без этой строки окна «с 1 по 10
         * число» сдвигались на несколько часов, а сравнение со сроком сдачи
         * шло по двум разным часам.
         *
         * Смещением, а не именем пояса: именованные пояса требуют таблиц
         * часовых поясов в MySQL, которых на шаред-хостинге обычно нет, а
         * смещение '+03:00' понимает любой сервер. Смещение считается на
         * момент соединения, сессия живёт один запрос — смена сезона в течение
         * дня не успевает сделать его неверным.
         */
        $offset = self::timezoneOffsetSql($this->timezone);

        if ($offset !== null) {
            $this->pdo->exec("SET SESSION time_zone = '" . $offset . "'");
        }
    }

    /**
     * Сообщение об отказе подключения.
     *
     * Причина берётся от того адреса, который задан в конфигурации: запасные
     * адреса добавляются в список проверенных, но подменять ими причину
     * нельзя — иначе «доступ запрещён» превратится в «соединение отклонено»,
     * и человек будет искать не тот пароль.
     *
     * @param list<array{0:string,1:int}> $targets
     * @param list<PDOException>          $errors
     */
    private static function connectionMessage(array $targets, array $errors): string
    {
        $tried = [];

        foreach ($targets as $target) {
            $tried[] = $target[0] . ':' . $target[1];
        }

        return 'Не удалось подключиться к базе данных. '
            . 'Проверены адреса: ' . implode(', ', $tried) . '. '
            . 'Причина: ' . ($errors[0]->getMessage() ?? 'неизвестна') . '. '
            . 'Проверьте в config/config.local.php значения database.host и database.port: '
            . 'на шаед-хостинге адрес и порт базы выдаёт панель управления, '
            . 'а если база лежит на этой же машине и отвечает только через сокет — '
            . 'укажите database.socket путём к нему или host => localhost.';
    }

    /**
     * Отметка в журнале: подключение состоялось по запасному адресу.
     *
     * Само по себе это не поломка, но знать о нём полезно: значит адрес в
     * конфигурации можно уточнить и убрать лишнюю попытку на каждый запрос.
     */
    private function noteFallback(string $configured, string $used): void
    {
        error_log(sprintf(
            '[db] адрес из конфигурации «%s» не ответил, соединение установлено по «%s»',
            $configured,
            $used
        ));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        /*
         * Ключ — сам SQL, без параметров.
         *
         * С параметрами в ключе кэш работал только на повторе одного и того же
         * запроса с одним и тем же набором значений, то есть почти никогда: у
         * каждой строки в цикле параметры свои, и на каждую строку готовилась
         * своя копия prepare. Вставка двадцати позиций готовила двадцать
         * одинаковых запросов вместо одного — кэш, задуманный как ускорение,
         * сам себе мешал и рос без границ.
         *
         * Переиспользовать один и тот же PDOStatement с новыми параметрами
         * можно: execute() перепривязывает их перед отправкой. Состояние
         * прошлого execute при этом не влияет на следующий — PDOStatement
         * хранит только курсор уже выполненного запроса, а его следующий вызов
         * заменяет.
         */
        if (!isset($this->cache[$sql])) {
            $this->cache[$sql] = $this->pdo->prepare($sql);
        }

        $stmt = $this->cache[$sql];
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Первое значение строки — по порядку, а не по имени колонки.
     *
     * Имя колонки в `information_schema` приходит от сервера в том регистре, в
     * каком сервер его отдаёт: MySQL и MariaDB отдают по-разному, и на части
     * шаред-хостингов имя приходит заглавными буквами. Обращение по ключу тогда
     * молча даёт пустое значение — а пустое имя таблицы в `SHOW CREATE TABLE`
     * обрывается ошибкой «Incorrect table name ''», и человек видит про миграцию,
     * хотя дело в чтении служебной таблицы.
     *
     * Значение берётся по порядку, поэтому от регистра не зависит.
     *
     * @param array<string,mixed> $row
     */
    public static function firstValue(array $row): mixed
    {
        $values = array_values($row);

        return $values === [] ? null : $values[0];
    }

    /**
     * @param array<string|int,mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . $this->quoteIdent($table)
            . ' (' . implode(', ', array_map([$this, 'quoteIdent'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>         $updateColumns столбцы, которые обновляются при
     *                                          повторном ключе; по умолчанию — все, кроме ключа
     */
    public function upsert(string $table, array $data, array $keyColumns, array $updateColumns = []): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        if ($updateColumns === []) {
            $updateColumns = array_values(array_diff($columns, $keyColumns));
        }

        // Use prefixed parameter names for ON DUPLICATE KEY UPDATE to avoid
        // "Invalid parameter number" with emulated prepares disabled: the same
        // named parameter cannot appear twice in one query (once in VALUES,
        // once in UPDATE). Prefix 'u_' keeps update parameters separate.
        $updateParams = [];
        $assignments = array_map(
            function (string $c) use (&$updateParams, $data): string {
                $paramName = 'u_' . $c;
                $updateParams[$paramName] = $data[$c];
                return $this->quoteIdent($c) . ' = :' . $paramName;
            },
            $updateColumns
        );

        $sql = 'INSERT INTO ' . $this->quoteIdent($table)
            . ' (' . implode(', ', array_map([$this, 'quoteIdent'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        if ($assignments !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        }

        $this->run($sql, $data + $updateParams);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];

        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdent($column) . ' = :s_' . $column;
            $params['s_' . $column] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdent($column) . ' = :w_' . $column;
            $params['w_' . $column] = $value;
        }

        $sql = 'UPDATE ' . $this->quoteIdent($table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . implode(' AND ', $conditions);

        return $this->run($sql, $params)->rowCount();
    }

/**
     * Выполнить чтение на одном моменте времени — снимок базы.
     *
     * Отдельный метод, а не `transaction()`, потому что повторяемое чтение — это
     * не то же самое, что транзакция: оно зависит от уровня изоляции, который
     * задаёт сервер, а не приложение.
     *
     * При READ COMMITTED (а именно он стоит по умолчанию на части хостингов)
     * каждое чтение внутри транзакции видит свежие данные. Копия, снятая так,
     * читала бы таблицы в разные моменты: между `assignments` и `tests` успела
     * бы появиться попытка — и в копии оказалась бы попытка без работы. Точка
     * отката, из которой нельзя восстановиться, хуже, чем её отсутствие.
     *
     * Уровень задаётся явно и без `SESSION`, поэтому относится только к этой
     * транзакции: соединение после возврата ведёт себя как раньше, и обычная
     * работа приложения не меняется.
     *
     * Если транзакция уже открыта, уровень изменить нельзя, и работа идёт в
     * ней как есть: незаписанные изменения такого снимка не должны видеть
     * посторонние, а увидят — значит, это снимок внешний и он честнее ответа.
     */
    public function consistentSnapshot(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Выполнить запись данных в транзакции.
     *
     * Вложенные вызовы не открывают вторую транзакцию: внутренний просто
     * выполняется в рамках внешней. Иначе код вида «внешняя транзакция плюс
     * репозиторий, который открывает свою», падал бы с «already an active
     * transaction».
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Изолировать часть работы вложенной транзакцией (savepoint).
     *
     * Обычный transaction() намеренно не создаёт вложенную транзакцию: это
     * удобно для репозиториев. При частичном импорте плагина нужен savepoint,
     * чтобы откатить неудачное создание одного задания, сохранив остальные
     * валидные задания пакета.
     */
    public function savepoint(callable $callback): mixed
    {
        if (!$this->pdo->inTransaction()) {
            return $this->transaction(fn (): mixed => $this->savepoint($callback));
        }

        $name = 'myedu_sp_' . (++$this->savepointCounter);
        $this->pdo->exec('SAVEPOINT `' . $name . '`');

        try {
            $result = $callback($this);
            $this->pdo->exec('RELEASE SAVEPOINT `' . $name . '`');

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT `' . $name . '`');
                $this->pdo->exec('RELEASE SAVEPOINT `' . $name . '`');
            } catch (\Throwable) {
                // Сохраняем исходную ошибку; внешний transaction откатит всё.
            }

            throw $e;
        }
    }

    private function quoteIdent(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }

    /**
     * Смещение часового пояса в виде, который понимает MySQL: '+03:00'.
     *
     * null — пояс не задан или не существует. Несуществующий пояс не роняет
     * соединение: тем же значением приложение уже вызывало
     * date_default_timezone_set(), предупредило и осталось в UTC — база
     * подстраивается под то же решение, а не решает заново.
     *
     * Пояса без перехода на летнее время дают постоянное смещение; пояса с
     * переходом дают смещение текущего момента — см. комментарий в connect().
     */
    public static function timezoneOffsetSql(string $timezone): ?string
    {
        $timezone = trim($timezone);

        if ($timezone === '') {
            return null;
        }

        try {
            $zone = new \DateTimeZone($timezone);
            $seconds = $zone->getOffset(new \DateTimeImmutable('now', $zone));
        } catch (\Exception) {
            return null;
        }

        $sign = $seconds < 0 ? '-' : '+';
        $abs = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}
