<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Отправка писем.
 *
 * Драйвер выбирается в config.php — в config.php driver'log'. Он пишет письма
 * в var/log/mail.log вместо отправки: это позволяет проверять ссылки
 * подтверждения в тестах и при разработке без почтового сервера.
 *
 * В production используется драйвер 'mail' (функция mail(), есть на любом
 * шаред-хостинге) либо 'smtp' (собственный клиент на fsockopen, если mail()
 * на сервере не работает или письма уходят в спам).
 */
interface MailerInterface
{
    public function send(MailMessage $message): bool;

    /** Человекочитаемое имя драйвера для интерфейса. */
    public function name(): string;
}