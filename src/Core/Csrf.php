<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Защита POST-запросов от подделки (CSRF).
 *
 * Используется синхронный токен в форме: совместимо с отключённым JavaScript.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($token, $candidate);
    }

    /** Проверяет и требует валидный токен, иначе бросает 419. */
    public function validate(?string $candidate): void
    {
        if (!$this->isValid($candidate)) {
            throw new HttpException(419);
        }
    }

    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}