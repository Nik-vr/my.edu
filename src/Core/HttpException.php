<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * HTTP-ошибка, которую роутер/обработчик превращают в страницу с кодом статуса.
 */
class HttpException extends \RuntimeException
{
    private int $status;

    public function __construct(int $status, string $message = '')
    {
        $this->status = $status;

        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'Некорректный запрос',
            403     => 'Доступ запрещён',
            404     => 'Страница не найдена',
            405     => 'Метод не поддерживается',
            419     => 'Сессия истекла, обновите страницу',
            default => 'Внутренняя ошибка сервера',
        };
    }
}