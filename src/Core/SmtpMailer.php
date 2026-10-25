<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Драйвер 'smtp': минимальный SMTP-клиент на fsockopen.
 *
 * Свой, а не библиотека, ради переносимости: проект не должен требовать
 * Composer на шаред-хостинге. Поддерживается ровно то, что нужно для писем
 * с подтверждением: EHLO, STARTTLS, аутентификация и отправка текста.
 */
final class SmtpMailer implements MailerInterface
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    private int $step = 0;

    /** @var string|null причина последнего отказа, null — отправка прошла */
    private ?string $lastError = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly bool $encryption = true,
        private readonly int $timeout = 20
    ) {
    }

    public function name(): string
    {
        return 'SMTP ' . $this->host . ':' . $this->port;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Предупреждений при успешной отправке нет: свойства драйвера —
     * коды и тексты ответа SMTP, и все они уходят в lastError() при отказе.
     * Метод нужен интерфейсу — страница настроек спрашивает у любого драйвера.
     */
    public function lastWarning(): ?string
    {
        return null;
    }

    public function send(MailMessage $message): bool
    {
        $this->lastError = null;

        if (filter_var($message->to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail('адрес получателя не похож на почтовый: ' . mb_substr($message->to, 0, 120));
        }

        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!is_resource($this->socket)) {
            return $this->fail("Не удалось подключиться к {$this->host}:{$this->port}: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->timeout);

        try {
            return $this->deliver($message);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        } finally {
            $this->close();
        }
    }

    /**
     * Отказ: причина в журнал PHP и в lastError() для страницы настроек.
     */
    private function fail(string $reason): bool
    {
        $this->lastError = $reason;

        $this->log($reason);

        return false;
    }

    private function deliver(MailMessage $message): bool
    {
        $this->expect([220]);

        $this->command('EHLO ' . $this->heloName(), [250]);

        if ($this->encryption) {
            $this->command('STARTTLS', [220]);

            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Не удалось включить TLS');
            }

            $this->command('EHLO ' . $this->heloName(), [250]);
        }

        $this->authenticate();
        $this->command('MAIL FROM:<' . $this->fromEmail . '>', [250]);
        $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
        $this->command('DATA', [354]);

        $this->writeData($this->buildMessage($message));
        $this->expect([250]);

        $this->command('QUIT', [221, 250]);

        return true;
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235]);
    }

    private function buildMessage(MailMessage $message): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $hasHtml = $message->html !== null && trim($message->html) !== '';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($this->fromEmail, $this->fromName),
            'To: ' . $message->to,
            'Subject: ' . $this->encodeHeader($message->subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->heloName() . '>',
            'MIME-Version: 1.0',
        ];

        if ($hasHtml) {
            // Для составного письма кодируем части по отдельности: так
            // кириллица не ломается в разных почтовых клиентах.
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            $body = '--' . $boundary . self::CRLF
                . 'Content-Type: text/plain; charset=UTF-8' . self::CRLF
                . 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF
                . chunk_split(base64_encode($message->text), 76, self::CRLF)
                . '--' . $boundary . self::CRLF
                . 'Content-Type: text/html; charset=UTF-8' . self::CRLF
                . 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF
                . chunk_split(base64_encode((string) $message->html), 76, self::CRLF)
                . '--' . $boundary . '--' . self::CRLF;
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            $body = chunk_split(base64_encode($message->text), 76, self::CRLF);
        }

        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $body;
    }

    private function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
            return $text;
        }

        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private function formatAddress(string $email, string $name): string
    {
        if (trim($name) === '') {
            return '<' . $email . '>';
        }

        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function heloName(): string
    {
        $host = parse_url($this->host, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * Отправляет команду и ждёт ответ с одним из указанных кодов.
     *
     * @param list<int> $codes
     */
    private function command(string $command, array $codes): string
    {
        $this->write($command);
        $this->step++;

        return $this->expect($codes);
    }

    /**
     * @param list<int> $codes
     */
    private function expect(array $codes): string
    {
        $response = '';

        while (true) {
            $line = $this->readLine();

            if ($line === null) {
                throw new \RuntimeException(
                    sprintf('SMTP: нет ответа на шаге %d (%s)', $this->step, implode('|', $codes))
                );
            }

            $response .= $line . "\n";

            // Многострочный ответ: последняя строка не содержит дефиса
            // за третьим символом.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(
                sprintf('SMTP: неожиданный ответ на шаге %d: %s', $this->step, trim($response))
            );
        }

        return $response;
    }

    /**
     * Отправляет команду серверу.
     *
     * Точка в конце строки — только маркер конца письма (DATA), поэтому
     * обычные команды завершаются пустой строкой без неё.
     */
    private function write(string $command): void
    {
        $this->writeAll($command . self::CRLF);
    }

    /**
     * Отправляет тело письма и завершающий маркер «.».
     *
     * Строки, начинающиеся с точки, удваивают её — этого требует протокол,
     * иначе письмо, содержащее точку в начале строки, оборвётся.
     */
    private function writeData(string $body): void
    {
        $escaped = preg_replace('/^\./m', '..', $body) ?? $body;

        $this->writeAll($escaped . self::CRLF . '.' . self::CRLF);
    }

    private function writeAll(string $payload): void
    {
        $total = strlen($payload);
        $written = 0;

        while ($written < $total) {
            $count = @fwrite($this->socket, substr($payload, $written));

            if ($count === false || $count === 0) {
                throw new \RuntimeException('SMTP: соединение прервано при отправке');
            }

            $written += $count;
        }
    }

    private function readLine(): ?string
    {
        $line = @fgets($this->socket, 1024);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    private function log(string $message): void
    {
        error_log('[mail/smtp] ' . $message);
    }
}