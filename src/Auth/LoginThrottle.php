<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Ограничение перебора пароля на входе.
 *
 * Единый текст ошибки и проверка фиктивного хэша прячут, существует ли учётная
 * запись, но не мешают подбирать пароль к найденному адресу: миллион неудачных
 * попыток для сервера выглядит как миллион обычных входов. Здесь считаются
 * неудачи и закрываются два входа: по паре «логин + адрес источника» и по
 * одному логину.
 *
 * Второй счётчик — от чаяний, что первый достаточно. Не достаточно: адрес
 * источника меняется на каждом запросе из чужой сети, и перебор на логине разлетался
 * по тысяче адресов, обходя любой счёт. Счёт по логину не меняется от смены
 * адреса, поэтому окно держится на счётчике, который атакующий не может
 * размножить. Плата за это — легитимный человек с забытым паролем блокируется
 * после стольких же попыток со всех адресов; это заметно безопаснее, чем
 * бесконечный перебор одной чужой учётной записи.
 *
 * Ключ — sha256 от логина и адреса. Сам логин вводит посетитель, и держать
 * чужие адреса в таблице ограничений незачем: для подбора пароля достаточно
 * самого ключа, а читать его нечем.
 *
 * Окно и порог задаются конфигом: у разных установок требования разные, и
 * молчаливые числа в коде потом не найти.
 */
final class LoginThrottle
{
    private Database $db;

    private int $limit;

    private int $windowSeconds;

    public function __construct(Database $db, int $limit, int $windowSeconds)
    {
        $this->db = $db;
        $this->limit = max(1, $limit);
        $this->windowSeconds = max(60, $windowSeconds);
    }

    /**
     * Сколько секунд ещё нельзя пробовать, 0 — можно.
     *
     * Окно считается от первой неудачи, а не от последней: иначе перебор
     * можно было бы продлить, делая по одной попытке в окно, и ограничение
     * перестало бы ограничивать.
     */
    public function waitSeconds(string $identifier, string $ip): int
    {
        $wait = 0;

        foreach ($this->keys($identifier, $ip) as $key) {
            $wait = max($wait, $this->waitForKey($key));
        }

        return $wait;
    }

    private function waitForKey(string $key): int
    {
        $row = $this->db->one(
            'SELECT failures, first_failed_at FROM login_throttle WHERE throttle_key = :k',
            ['k' => $key]
        );

        if ($row === null || (int) $row['failures'] < $this->limit) {
            return 0;
        }

        $first = strtotime((string) $row['first_failed_at']) ?: time();
        $elapsed = time() - $first;
        $left = $this->windowSeconds - $elapsed;

        // Окно истекло: считать неудачи заново.
        if ($left <= 0) {
            $this->db->run('DELETE FROM login_throttle WHERE throttle_key = :k', ['k' => $key]);

            return 0;
        }

        return $left;
    }

/**
     * Отметить неудачную попытку.
     *
     * Счётчик наращивает один атомарный UPDATE, и это главное: раньше неудачи
     * считали чтением, а запись делали следом. Две одновременные попытки по
     * несуществующему логину обе видели пустую строку и обе вставляли её —
     * вторая падала с «Duplicate entry», и вместо отказа на входе человек
     * получал 500.
     *
     * Отдельные операции, а не один INSERT ... ON DUPLICATE KEY UPDATE: на этой
     * СУБД подзапрос в INSERT ... SELECT роняет подготовленный запрос, а в ветке
     * ON DUPLICATE KEY UPDATE именованные параметры работают не всегда. Поэтому
     * строка создаётся вставкой с игнорированием повторов, а счётчик
     * увеличивается UPDATE'ом — он и сам по себе атомарен.
     *
     * Параметры здесь позиционные, и это не вкус: именованные внутри IF() в
     * многострочном UPDATE на этой связке PHP и MariaDB дают «Invalid parameter
     * number» — запрос падает на ровном месте, а выглядит как ошибка в данных.
     *
     * Сброс окна выражен через дату отсечки: условие «прошло ли окно» передаётся
     * датой, а не вычисляется на стороне базы, где INTERVAL с подставленным
     * параметром понимают по-разному.
     */
    public function registerFailure(string $identifier, string $ip): void
    {
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);

        foreach ($this->keys($identifier, $ip) as $key) {
            // Ноль, а не единица: счётчик ниже увеличивается отдельным
            // UPDATE'ом, и строка, созданная единицей, считалась бы дважды —
            // уже на первой попытке.
            $this->db->run(
                'INSERT IGNORE INTO login_throttle (throttle_key, failures, first_failed_at, last_failed_at)
                      VALUES (?, 0, ?, ?)',
                [$key, $now, $now]
            );

            // Окно прошло — начинаем счёт заново.
            $this->db->run(
                'UPDATE login_throttle
                    SET failures = IF(last_failed_at <= ?, 1, failures),
                        first_failed_at = IF(last_failed_at <= ?, ?, first_failed_at),
                        last_failed_at = ?
                  WHERE throttle_key = ?
                    AND last_failed_at <= ?',
                [$cutoff, $cutoff, $now, $now, $key, $cutoff]
            );

            // Окно не прошло: счётчик растёт на единицу.
            $this->db->run(
                'UPDATE login_throttle
                    SET failures = failures + 1, last_failed_at = ?
                  WHERE throttle_key = ?
                    AND last_failed_at > ?',
                [$now, $key, $cutoff]
            );
        }
    }

    /** Успешный вход снимает счётчик: человек вошёл, перебора больше нет. */
    public function registerSuccess(string $identifier, string $ip): void
    {
        foreach ($this->keys($identifier, $ip) as $key) {
            $this->db->run('DELETE FROM login_throttle WHERE throttle_key = :k', ['k' => $key]);
        }
    }

    /**
     * Ключи, по которым считается одна и та же неудачная попытка.
     *
     * @return list<string>
     */
    private function keys(string $identifier, string $ip): array
    {
        return [$this->key($identifier, $ip), $this->key($identifier, '')];
    }

    private function key(string $identifier, string $ip): string
    {
        return hash('sha256', mb_strtolower($identifier) . '|' . $ip);
    }
}