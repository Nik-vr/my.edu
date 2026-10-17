<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Драйвер 'mail': стандартная функция mail() PHP.
 *
 * Нужен там, где собственного SMTP нет, а почтовый сервер хостинга есть.
 * Именно так устроен обычный шаред-хостинг: письмо уходит через sendmail
 * самой машины, и настраивать ничего не нужно — только указать адрес
 * отправителя на своём домене.
 *
 * Отличия от простой отправки, без которых письмо доходит плохо или не
 * доходит вовсе:
 *
 * - Тема кодируется по RFC 2047 и переносится. Строка заголовка не должна
 *   превышать 78 знаков, поэтому base64 нарезается кусками по 56 знаков
 *   (14 полных групп по 4 знака: разрыв внутри группы портит данные) и
 *   склеивается переносом с отступом — это складывание одного заголовка,
 *   а не начало нового. Без этого длинная русская тема превращается у
 *   получателя в мусор или письмо отвергается вовсе.
 * - Пятый параметр `-f` по умолчанию не передаётся. На многих хостингах он
 *   запрещён, и `mail()` с ним возвращает false, хотя сама почта работает.
 *   Передавать его стоит только там, где адрес отправителя нужно задать
 *   явно (mail.sendmail_from в php.ini), — включается настройкой.
 * - Причину отказа пишем в журнал. `mail()` возвращает false одинаково и
 *   когда письмо отвергнуто, и когда функция запрещена настройками, и
 *   молчание здесь выглядит как «письма просто не приходят».
 */
final class PhpMailer implements MailerInterface
{
    /** @var array<string,string> */
    private array $options;

    private bool $useEnvelopeFrom;

    /**
     * @param array<string,string> $options  дополнительные заголовки письма
     * @param bool                 $useEnvelopeFrom передавать ли «-f» в mail()
     */
    public function __construct(
        private readonly string $fromEmail,
        private readonly string $fromName,
        array $options = [],
        bool $useEnvelopeFrom = false
    ) {
        $this->options = $options;
        $this->useEnvelopeFrom = $useEnvelopeFrom;
    }

    public function name(): string
    {
        return 'mail()';
    }

    public function send(MailMessage $message): bool
    {
        if (!function_exists('mail')) {
            error_log('[mail] функция mail() недоступна: она выключена настройками PHP');

            return false;
        }

        if (filter_var($message->to, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[mail] адрес получателя не похож на почтовый: ' . mb_substr($message->to, 0, 120));

            return false;
        }

        if (filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[mail] не задан адрес отправителя mail.from_email');

            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . (string) $this->encodeAddress($this->fromEmail, $this->fromName),
            'X-Mailer: my.edu',
        ];

        foreach ($this->options as $header => $value) {
            $headers[] = $header . ': ' . $value;
        }

        // Письмо целиком в base64: так его содержимое не зависит от того, как
        // MTA переносит строки, и не превращается в мусор на почте в Windows.
        $body = rtrim(chunk_split(base64_encode($message->text), 76, "\r\n"), "\r\n");

        // Завершающий CRLF обязателен по RFC 2046: часть MTA без него письмо
        // не принимает.
        $body .= "\r\n";

        $parameters = $this->useEnvelopeFrom ? ['-f' . $this->fromEmail] : [];

        $sent = @mail(
            $message->to,
            $this->encodeHeader($message->subject),
            $body,
            implode("\r\n", $headers),
            ...$parameters
        );

        if ($sent !== true) {
            error_log(
                '[mail] mail() вернула false для ' . $message->to
                . ' (отправитель ' . $this->fromEmail . ')'
                . ($this->useEnvelopeFrom ? ' с параметром -f' : '')
                . '. Частая причина на шаред-хостинге: запрещён пятый параметр '
                . 'либо не задан mail.sendmail_from в php.ini — попробуйте '
                . 'mail.envelope_from => false.'
            );
        }

        return $sent === true;
    }

    /**
     * Заголовок темы или имени отправителя по RFC 2047.
     *
     * Кодировка одна на весь заголовок: смешивать «=?UTF-8?B?» с обычным
     * текстом посреди заголовка нельзя, почтовые клиенты это понимают по-разному.
     */
    private function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
            return $text;
        }

        $words = [];

        // Нарезка потока base64 строго по границе четвёрки: 56 = 14 групп,
        // строка выходит 68 знаков, с отступом при переносе 69 — в пределах
        // 78 знаков на строку заголовка.
        foreach (str_split(base64_encode($text), 56) as $chunk) {
            $words[] = '=?UTF-8?B?' . $chunk . '?=';
        }

        return implode("\r\n ", $words);
    }

    /**
     * Адрес в заголовке, с именем отправителя, если оно задано.
     */
    private function encodeAddress(string $address, ?string $name = null): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if ($name === null || trim($name) === '') {
            return $address;
        }

        return $this->encodeHeader($name) . ' <' . $address . '>';
    }
}