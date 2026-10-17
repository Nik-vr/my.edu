<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Письмо, готовое к отправке.
 *
 * Тема и текст хранятся как UTF-8. Кодировку в заголовки добавляет драйвер:
 * у mail() и SMTP это делается по-разному, а в лог пишется в исходном виде.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null
    ) {
    }
}