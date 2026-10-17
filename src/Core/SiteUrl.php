<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Абсолютный адрес сайта — нужен в письмах.
 *
 * Ссылка подтверждения должна работать из почтового клиента, где относительный
 * адрес бессмысленен. Адрес берётся из конфигурации; если он не задан,
 * собирается из текущего запроса.
 */
final class SiteUrl
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Адрес с учётом размещения приложения не в корне домена.
     *
     * Префикс берётся у приложения тем же правилом, что и для ссылок в
     * интерфейсе. Раньше здесь префикс угадывался по SCRIPT_NAME, и письмо
     * получало адрес без каталога: сайт открывался по `/public/`, а в ссылке
     * его не было.
     */
    public function base(): string
    {
        $configured = trim($this->config->string('app.url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // За обратным прокси переменная HTTPS часто пуста, а схема приходит
        // отдельным заголовком: без этой поправки письма получали бы http://
        // и ссылка из почты уводила с защищённого адреса на незащищённый.
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        $scheme = $https ? 'https' : 'http';

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . App::instance()->basePath();
    }

    public function to(string $path): string
    {
        return $this->base() . '/' . ltrim($path, '/');
    }
}