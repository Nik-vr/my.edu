<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Обёртка над сессией PHP с безопасными настройками cookie.
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly bool $secureCookies,
        private readonly string $savePath = '',
        private readonly string $name = ''
    ) {
        // Настройки применяются в start(), а не здесь: сервис создаётся
        // лениво, и к моменту конструктора заголовки могли уже уйти (например,
        // если до этого что-то вывели). session_name() после этого даёт
        // предупреждение и имя не применяется вовсе.
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if ($this->name !== '') {
            /*
             * Имя сессии задаётся конфигом, значит и применяться должно всегда:
             * иначе конфиг выглядит настроенным, а браузер получает PHPSESSID —
             * и по названию cookie нельзя понять, чья это сессия.
             */
            session_name($this->name);
        }

        /*
         * Параметры ставятся безусловно, а не «если secure или задан путь».
         * Прежнее условие означало, что на обычной локальной настройке
         * (secure_cookies = false, save_path = '') cookie уходила вовсе без
         * HttpOnly и SameSite — то есть по умолчанию, а не в исключительном
         * случае. Значения httponly и samesite полезны и без HTTPS.
         */
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if ($this->savePath !== '' && is_dir($this->savePath)) {
            session_save_path($this->savePath);
        }

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Значение, которое нужно показать один раз (после редиректа).
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->started = false;
    }

    /**
     * Отпустить сессию, не разрушая её.
     *
     * Сессия PHP держит файл-блокировку, пока она активна, и отпускает её при
     * закрытии. Пока приложение не закрывало сессию ни разу, блокировка жила до
     * конца отрисовки страницы, то есть десятки миллисекунд на каждой странице
     * панели. Под HTTP/2 запросы браузера идут параллельно по одному соединению:
     * иконка, картинка и основная страница вставали в очередь на одну блокировку,
     * и ожидание обрывало поток — браузер показывал ERR_HTTP2_PROTOCOL_ERROR.
     * Повтор через пару секунд проходил, потому что блокировка уже снималась.
     *
     * Закрывается после того, как тело ответа собрано: к этому моменту сессия
     * уже прочитана и дописана (сообщения и введённые значения тянутся прямо в
     * ходе отрисовки), а отправлять байты ещё предстоит. Раньше этого момента
     * закрывать нельзя: потерялось бы всё, что контроллер положил в сессию
     * после старта запроса.
     */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->started = false;
    }
}