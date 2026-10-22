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
 *
 * Вместе с идентификатором кладётся отпечаток пароля — и это единственное, что
 * обрывает сессии после смены пароля. Идентификатора самого по себе мало: он
 * переживает смену пароля, и человек, у которого угнали пароль, после смены
 * оставался внутри под чужой сессией. То же касается всех входов сразу — на
 * телефоне, на школьном компьютере, на чужом устройстве, — и закрывать их по
 * одному человек не может.
 *
 * Отпечаток — кусок самого хеша, а не сравнение паролей: он ничего не говорит
 * о пароле, но меняется вместе с ним и стоит в сессии рядом с идентификатором.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    private const STAMP_KEY = '_auth_pwd_stamp';

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

        /*
         * Пароль сменили после того, как эта сессия была заведена: значит, её
         * оставлять нельзя. Именно это и бывает при взломе — человек меняет
         * пароль, чтобы чужой вход перестал работать, а чужой вход работает,
         * пока сессия жива.
         *
         * Сессии, заведённые без отпечатка (старые файлы сессий, до этой правки),
         * тоже отпускаются: доверять им нечем — пароль в них могли сменить
         * сколько угодно раз назад.
         */
        $stamp = $this->session->get(self::STAMP_KEY);

        if (!is_string($stamp) || !hash_equals($this->stampOf($user), $stamp)) {
            $this->session->remove(self::SESSION_KEY);
            $this->session->remove(self::STAMP_KEY);

            return $this->user = null;
        }

        return $this->user = $user;
    }

    /**
     * Отпечаток пароля для сессии.
     *
     * Считается на настоящем объекте пользователя, который уже несёт его с
     * собой из базы. Отдельного запроса за хешем нет намеренно: это был бы
     * лишний запрос на каждый заход в систему.
     */
    private function stampOf(User $user): string
    {
        return $user->passwordStamp ?? substr(hash('sha256', ''), 0, 32);
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
     * Чей круг работ видит человек в журнале и в счётчиках меню.
     *
     * Завуч и администратор ведут школу целиком и видят все работы (0 — без
     * ограничения по автору). Учитель видит только работы, которые выдал сам:
     * иначе раздел показал бы ему результаты чужих классов, к которым у него
     * нет доступа.
     *
     * @return int автор работ или 0, если видны все
     */
    public function journalAuthorId(): int
    {
        return $this->can(Role::CLASSES_MANAGE) ? 0 : (int) ($this->user()?->id ?? 0);
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
        $this->session->set(self::STAMP_KEY, $this->stampOf($user));

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