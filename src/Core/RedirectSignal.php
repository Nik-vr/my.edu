<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Перенаправление, заданное не контроллером, а проверкой доступа.
 *
 * Нужен для двух случаев: гость попал на закрытую страницу (нужно на вход),
 * и вошедший открыл страницу для гостя (нужно в его кабинет).
 * Исключение позволяет проверке прав в роутере вернуть редирект, а не
 * подделывать ответ внутри себя.
 */
final class RedirectSignal extends \RuntimeException
{
    public function __construct(private readonly string $url, private readonly int $status = 302)
    {
        parent::__construct('Redirect to ' . $url);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function toResponse(): Response
    {
        return Response::redirect($this->url, $this->status);
    }
}