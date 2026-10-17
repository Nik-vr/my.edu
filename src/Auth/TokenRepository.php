<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Одноразовые токены из писем: подтверждение адреса и сброс пароля.
 *
 * В базу кладётся только SHA-256 хеш токена. Даже при полном доступе к базе
 * восстановить действующую ссылку из писем нельзя.
 *
 * Токен считается использованным после первого предъявления; повторное
 * использование возвращает null, а не ошибку, чтобы ссылка из старого письма
 * не давала разных ответов при переходе.
 */
final class TokenRepository
{
    public const PURPOSE_CONFIRM = 'email_confirm';
    public const PURPOSE_RESET = 'password_reset';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Выпускает токен и возвращает его открытым текстом — только чтобы
     * подставить в ссылку в письме.
     *
     * Гашение старых токенов и выпуск нового идут одной транзакцией под
     * блокировкой строки пользователя. По отдельности это ломалось дважды:
     * при сбое вставки человек остался вообще без действующей ссылки, а при
     * двух одновременных запросах «пришлите ссылку» оба UPDATE'а успевали
     * пройти до вставок — и обе ссылки оказывались живыми, то есть
     * действовало старое письмо, хотя правило «работает только свежее» этого
     * не допускает.
     */
    public function issue(int $userId, string $purpose, int $validHours, ?string $targetEmail = null): string
    {
        if ($purpose === self::PURPOSE_CONFIRM && $targetEmail === null) {
            throw new \InvalidArgumentException('Для подтверждения адреса нужно указать адрес получателя');
        }

        return $this->db->transaction(function (Database $db) use ($userId, $purpose, $validHours, $targetEmail): string {
            // Блокировка строки пользователя: выдача ссылок выстраивается в
            // очередь по пользователю, а не по таблице токенов. Пустой
            // результат означает, что выдавать нечего: пользователя нет.
            $db->run('SELECT id FROM users WHERE id = :user FOR UPDATE', ['user' => $userId]);

            // Прежние неиспользованные токены гасим: по ссылке из старого
            // письма нельзя должно получиться войти в обход свежего.
            $db->run(
                'UPDATE auth_tokens SET used_at = NOW()
                  WHERE user_id = :user AND purpose = :purpose AND used_at IS NULL',
                ['user' => $userId, 'purpose' => $purpose]
            );

            $token = bin2hex(random_bytes(32));

            $db->insert('auth_tokens', [
                'user_id'      => $userId,
                'purpose'      => $purpose,
                'target_email' => $purpose === self::PURPOSE_CONFIRM ? $targetEmail : null,
                'token_hash'   => self::hash($token),
                'expires_at'   => date('Y-m-d H:i:s', time() + $validHours * 3600),
                'used_at'      => null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            return $token;
        });
    }

    /**
     * Находит пользователя по действующему токену и гасит его.
     *
     * @return array<string,mixed>|null
     */
    public function consume(string $token, string $purpose): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $hash = self::hash($token);

        return $this->db->transaction(function (Database $db) use ($hash, $purpose): ?array {
            $candidate = $db->one(
                'SELECT id, user_id FROM auth_tokens
                  WHERE token_hash = :hash AND purpose = :purpose AND used_at IS NULL
                  LIMIT 1',
                ['hash' => $hash, 'purpose' => $purpose]
            );

            if ($candidate === null) {
                return null;
            }

            // Токенопотребление и смена адреса блокируют сначала пользователя,
            // затем токен; единый порядок не допускает гонки со сменой адреса.
            $user = $db->one('SELECT * FROM users WHERE id = :id FOR UPDATE', ['id' => $candidate['user_id']]);

            if ($user === null) {
                return null;
            }

            $row = $db->one(
                'SELECT * FROM auth_tokens
                  WHERE id = :id AND token_hash = :hash AND purpose = :purpose AND used_at IS NULL
                  LIMIT 1 FOR UPDATE',
                ['id' => $candidate['id'], 'hash' => $hash, 'purpose' => $purpose]
            );

            if ($row === null) {
                return null;
            }

            if (strtotime((string) $row['expires_at']) < time()) {
                $db->run(
                    'UPDATE auth_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL',
                    ['id' => $row['id']]
                );

                return null;
            }

            if ($purpose === self::PURPOSE_CONFIRM
                && ((string) ($row['target_email'] ?? '') === ''
                    || !hash_equals((string) ($user['email'] ?? ''), (string) $row['target_email']))) {
                $db->run(
                    'UPDATE auth_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL',
                    ['id' => $row['id']]
                );

                return null;
            }

            $consumed = $db->run(
                'UPDATE auth_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL',
                ['id' => $row['id']]
            );

            return $consumed->rowCount() === 1
                ? $user + ['target_email' => $row['target_email']]
                : null;
        });
    }

    public function invalidate(int $userId, string $purpose): void
    {
        $this->db->run(
            'UPDATE auth_tokens SET used_at = NOW()
              WHERE user_id = :user AND purpose = :purpose AND used_at IS NULL',
            ['user' => $userId, 'purpose' => $purpose]
        );
    }

    /** Есть ли неиспользованный действующий токен у пользователя. */
    public function hasActive(string $token, string $purpose): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $row = $this->db->one(
            'SELECT expires_at FROM auth_tokens
              WHERE token_hash = :hash AND purpose = :purpose AND used_at IS NULL
              LIMIT 1',
            ['hash' => self::hash($token), 'purpose' => $purpose]
        );

        return $row !== null && strtotime((string) $row['expires_at']) >= time();
    }

    /**
     * Причину отказа: токен не найден или истёк.
     *
     * Нужна, чтобы показать человеку точную формулировку, а не «ссылка недействительна».
     */
    public function lookup(string $token, string $purpose): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->one(
            'SELECT id, user_id, expires_at, used_at FROM auth_tokens
              WHERE token_hash = :hash AND purpose = :purpose
              LIMIT 1',
            ['hash' => self::hash($token), 'purpose' => $purpose]
        );
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
