<?php

declare(strict_types=1);

namespace MyEdu\Core;

use RuntimeException;

/**
 * Создание драйвера отправки писем по конфигурации.
 *
 * Вынесено отдельно от App, чтобы драйвер можно было подменить в тестах
 * и не тянуть зависимости туда, где письма не отправляются.
 */
final class MailerFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly string $varDir
    ) {
    }

    /**
     * Драйвер, который будет реально использован.
     *
     * Переменная окружения MYEDU_MAIL_DRIVER важнее конфигурации: так тесты
     * запускают сервер с драйвером 'log' и проверяют письма по файлу, не
     * переписывая config.local.php, а обычный запуск работает с настройками
     * из конфигурации.
     */
    public function effectiveDriver(): string
    {
        $driver = strtolower($this->config->string('mail.driver', 'log'));
        $envDriver = Config::env('MYEDU_MAIL_DRIVER');

        if ($envDriver !== '') {
            $driver = strtolower($envDriver);
        }

        return $driver;
    }

    /**
     * Настройка отправки в понятных терминах — для подсказок на страницах.
     *
     * Режим определяется по тому, куда уходят письма на самом деле, а не по
     * тому, заполнена ли настройка локального почтовика. Настройка может
     * остаться в конфигурации с машины разработки, и на настоящей отправке
     * это не должно превращать страницу в инструкцию «откройте 127.0.0.1».
     */
    public function setup(): MailSetup
    {
        $driver = $this->effectiveDriver();
        $label = $driver === 'log'
            ? 'журнал (отправка не выполняется)'
            : ($driver === 'smtp'
                ? 'SMTP ' . $this->smtpTarget()
                : 'mail()');

        if ($driver === 'log') {
            return new MailSetup(MailSetup::MODE_LOG, $driver, $label);
        }

        if ($driver === 'smtp' && $this->isLoopbackHost($this->config->string('mail.smtp.host', 'localhost'))) {
            return new MailSetup(
                MailSetup::MODE_LOCAL,
                $driver,
                $label,
                trim($this->config->string('mail.dev_web_ui', ''))
            );
        }

        return new MailSetup(MailSetup::MODE_REAL, $driver, $label);
    }

    private function smtpTarget(): string
    {
        return $this->config->string('mail.smtp.host', 'localhost')
            . ':' . $this->config->int('mail.smtp.port', 25);
    }

    /**
     * Сервер на этой же машине?
     *
     * SMTP на 127.0.0.1 или localhost — это заведомо не почтовый оператор:
     * так поднимают локальный почтовик для разработки.
     */
    private function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, " \t\n\r\0\x0B[]")), ['127.0.0.1', 'localhost', '::1'], true);
    }

    public function create(): MailerInterface
    {
        $driver = $this->effectiveDriver();

        return match ($driver) {
            'mail' => new PhpMailer(
                $this->config->string('mail.from_email', 'noreply@localhost'),
                $this->config->string('mail.from_name', 'Система контроля знаний'),
                $this->config->array('mail.headers'),
                // Пятый параметр mail() по умолчанию не передаётся: на
                // шаред-хостинге он часто запрещён, и с ним отправка молча
                // возвращает false. Включается настройкой, когда адрес
                // отправителя действительно нужно задавать явно.
                $this->config->bool('mail.envelope_from', false)
            ),

            'smtp' => new SmtpMailer(
                $this->config->string('mail.smtp.host', 'localhost'),
                $this->config->int('mail.smtp.port', 25),
                $this->config->string('mail.smtp.username'),
                $this->config->string('mail.smtp.password'),
                $this->config->string('mail.from_email', 'noreply@localhost'),
                $this->config->string('mail.from_name', 'Система контроля знаний'),
                $this->config->bool('mail.smtp.tls', true),
                $this->config->int('mail.smtp.timeout', 20)
            ),

            // Обрезка токенов в журнале выключается переменной окружения: иначе
            // в разработке из журнала нельзя было бы открыть ссылку подтверждения.
            'log' => new LogMailer(
                $this->varDir . '/log',
                Config::env('MYEDU_LOG_REDACT_TOKENS') !== '0'
            ),

            default => throw new RuntimeException(
                'Неизвестный драйвер отправки писем: ' . $driver
                . '. Допустимы: log, mail, smtp'
            ),
        };
    }
}