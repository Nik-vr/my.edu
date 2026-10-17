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
 * неудачи по паре «логин + адрес источника» и после порога вход временно
 * прекращается.
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
        $key = $this->key($identifier, $ip);
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
     * Решение о сбросе счётчика принимается здесь, а не в SQL: условие
     * «прошло ли окно» в ON DUPLICATE KEY UPDATE с подставленным параметром
     * внутри INTERVAL базы понимают по-разному, и запрос падал там, где
     * проверка должна была тихо обновить строку.
     */
    public function registerFailure(string $identifier, string $ip): void
    {
        $key = $this->key($identifier, $ip);
        $now = date('Y-m-d H:i:s');
        $row = $this->db->one(
            'SELECT failures, last_failed_at FROM login_throttle WHERE throttle_key = :k',
            ['k' => $key]
        );

        if ($row === null) {
            /*
             * Имена параметров разные, хотя значение одно: повтор одного
             * именованного параметра PDO принимает не всегда, и запрос падал
             * с «Invalid parameter number» там, где данные одинаковые.
             */
            $this->db->run(
                'INSERT INTO login_throttle (throttle_key, failures, first_failed_at, last_failed_at)
                      VALUES (:k, 1, :first, :last)',
                ['k' => $key, 'first' => $now, 'last' => $now]
            );

            return;
        }

        $last = strtotime((string) $row['last_failed_at']) ?: 0;
        $windowPassed = (time() - $last) >= $this->windowSeconds;

        // Окно прошло — начинаем счёт заново, иначе старая неудача вечно держала
        // бы блокировку и человек не смог бы войти никогда.
        $failures = $windowPassed ? 1 : (int) $row['failures'] + 1;
        $first = $windowPassed ? $now : (string) $row['last_failed_at'];

        $this->db->run(
            'UPDATE login_throttle
                SET failures = :count, first_failed_at = :first, last_failed_at = :last
              WHERE throttle_key = :k',
            ['count' => $failures, 'first' => $first, 'last' => $now, 'k' => $key]
        );
    }

    /** Успешный вход снимает счётчик: человек вошёл, перебора больше нет. */
    public function registerSuccess(string $identifier, string $ip): void
    {
        $this->db->run(
            'DELETE FROM login_throttle WHERE throttle_key = :k',
            ['k' => $this->key($identifier, $ip)]
        );
    }

    private function key(string $identifier, string $ip): string
    {
        return hash('sha256', mb_strtolower($identifier) . '|' . $ip);
    }
}