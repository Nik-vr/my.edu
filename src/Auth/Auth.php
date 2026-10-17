<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Config;
use MyEdu\Core\Session;

/**
 * Текущая сессия пользователя.
 *
 * Сессия хранит только идентификатор: все роли и права читаются из базы при
 * каждом запросе. Благодаря этому снятие роли действует немедленно, а не
 * после выхода из системы, и нельзя «подсунуть» себе права через данные
 * сессии, которые видел браузер.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    private ?User $user = null;

    private bool $loaded = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly Config $config
    ) {
    }

    /** Текущий пользователь или null для гостя. */
    public function user(): ?User
    {
        if ($this->loaded) {
            return $this->user;
        }

        $this->loaded = true;
        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return $this->user = null;
        }

        $user = $this->users->findUser((int) $id);

        // Заблокированного пользователя мгновенно выкидываем из сессии.
        if ($user === null || !$user->isActive()) {
            $this->session->remove(self::SESSION_KEY);

            return $this->user = null;
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    public function isOwner(): bool
    {
        return $this->user()?->isOwner ?? false;
    }

    public function can(string $capability): bool
    {
        return $this->user()?->hasCapability($capability) ?? false;
    }

    /**
     * Вход пользователя в систему.
     *
     * Идентификатор сессии обновляется, чтобы прежний идентификатор cookie
     * нельзя было использовать для доступа от имени вошедшего.
     */
    public function login(User $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $user->id);

        $this->users->update($user->id, ['last_login_at' => date('Y-m-d H:i:s')]);

        $this->user = $user;
        $this->loaded = true;
    }

    public function logout(): void
    {
        $this->session->destroy();

        $this->user = null;
        $this->loaded = true;
    }

    /**
     * Проверка пароля.
     *
     * Пустой хеш (ученик из этапа 1) паролем не является: такой пользователь
     * не сможет войти, пока пароль не зададут заново.
     */
    public function checkPassword(string $password, string $hash): bool
    {
        if (trim($hash) === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function minPasswordLength(): int
    {
        return max(6, $this->config->int('auth.min_password_length', 8));
    }
}