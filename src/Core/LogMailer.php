<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Драйвер 'log': письма не отправляются, а дописываются в var/log/mail.log.
 *
 * Используется при разработке и в тестах: ссылки подтверждения можно открыть
 * прямо из журнала, не поднимая почтовый сервер. На боевом сервере драйвер по
 * умолчанию другой, а если его всё-таки включили, токены в журнале
 * обрезаются — об этом ниже.
 *
 * ПОЧЕМУ ТОКЕНЫ ОБРЕЗАЮТСЯ ПО УМОЛЧАНИЮ
 *
 * Журнал лежит в `var/`, то есть в каталоге, доступном тому, у кого есть
 * доступ к файлам сайта. При драйвере 'log' туда попадает всё, что система
 * отправила, а в письмах подтверждения и сброса пароля лежит токен, которым
 * входят. Письмо, напечатанное в журнал, перестаёт быть «почтой, которую
 * никто не видит» и становится секретом на диске.
 *
 * Обрезка оставляет первые символы — их достаточно, чтобы узнать в журнале,
 * какое письмо и когда ушло, но недостаточно, чтобы войти.
 *
 * Для разработки и тестов обрезка выключается явно: драйвер 'log' там
 * единственный способ посмотреть ссылку подтверждения. За это отвечает
 * переменная окружения MYEDU_LOG_REDACT_TOKENS=0, которую выставляет
 * `tools\dev-server.ps1 -ForTests`.
 *
 * Разделители в журнале — строки вида «Новое письмо», поэтому тесты и человек
 * могут читать файл одинаково.
 */
final class LogMailer implements MailerInterface
{
    private const SEPARATOR = '=== ПИСЬМО %s ===';

    public function __construct(
        private readonly string $logDir,
        private readonly bool $redactTokens = true
    ) {
    }

    public function name(): string
    {
        return 'журнал (отправка не выполняется)';
    }

    /**
 * Обрезать токены в письме.
 *
 * Правило состоит из двух частей, и обе нужны, потому что токен попадает в
 * письмо по-разному:
 *
 *   1. Значение параметра в ссылке (`?token=…`) — так строятся все ссылки
 *      подтверждения и сброса пароля.
 *   2. Отдельная длинная шестнадцатеричная строка (32 и более знаков) — так
 *      выглядит токен сам по себе, если письмо его повторяет в тексте.
 *
 * Второе правило не задевает обычный текст: тридцать два подряд идущих
 * шестнадцатеричных знака в письме не встречаются, а настоящий токен —
 * `random_bytes(32)` в шестнадцатеричной записи — встречается всегда.
 *
 * Остальные длинные строки не трогаются: иначе в разработке из журнала нельзя
 * было бы прочитать ни один адрес.
 */
private function redact(string $text): string
{
    if (!$this->redactTokens) {
        return $text;
    }

    $masked = (string) preg_replace_callback(
        '/\b(token|code)=([A-Za-z0-9._-]{12,})/i',
        static function (array $m): string {
            return $m[1] . '=' . substr($m[2], 0, 6) . '…(скрыт, ' . strlen($m[2]) . ' символов)';
        },
        $text
    );

    return (string) preg_replace_callback(
        '/(?<![A-Za-z0-9_-])[0-9a-f]{32,}(?![A-Za-z0-9_-])/i',
        static fn (array $m): string => substr($m[0], 0, 6) . '…(скрыт, ' . strlen($m[0]) . ' символов)',
        $masked
    );
}

    public function send(MailMessage $message): bool
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        if (!is_dir($this->logDir) || !is_writable($this->logDir)) {
            error_log('[mail/log] нет доступа к каталогу ' . $this->logDir);

            return false;
        }

        $text = sprintf(self::SEPARATOR, date('Y-m-d H:i:s'))
            . "\nКому: " . $message->to
            . "\nТема: " . $message->subject
            . "\nДата: " . date('r')
            . "\n"
            . str_repeat('-', 60)
            . "\n"
            . $this->redact($message->text);

        if ($message->html !== null && trim($message->html) !== '') {
            $text .= "\n\n--- HTML ---\n" . $this->redact($message->html);
        }

        $file = $this->logDir . '/mail-' . date('Y-m-d') . '.log';

        return @file_put_contents($file, $text . "\n\n", FILE_APPEND) !== false;
    }
}