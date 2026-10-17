<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller;

use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Database;
use MyEdu\Core\MailerFactory;
use MyEdu\Core\Request;
use MyEdu\Core\Response;

/**
 * Проверка готовности окружения.
 *
 * Нужна при развёртывании на новом хостинге: показывает версии и то, какие
 * расширения PHP доступны, не раскрывая ничего секретного.
 *
 * Кому видны подробности, решает не только адрес запроса. Если сайт стоит за
 * nginx или apache на той же машине, тот обращается к PHP-FPM от 127.0.0.1, и
 * любой внешний запрос выглядит локальным: подробности — версия PHP, имя базы,
 * число пользователей, текст ошибки подключения — ушли бы наружу. Поэтому
 * решение принимает конфигурация:
 *
 *   app.health_public_details — "local" (по умолчанию) или "never".
 *
 * Значение "never" отдаёт только status: проверка доступности продолжает
 * работать, но перестаёт быть диагностикой для постороннего. Ставить его надо
 * при любом прокси перед приложением, иначе умолчание окажется неверным.
 */
final class HealthController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly Config $config,
        private readonly MailerFactory $mailers
    ) {
    }

    public function show(Request $request): Response
    {
        $detailsAllowed = $this->config->string('app.health_public_details', 'local') !== 'never'
            && $request->isLoopback();

        if (!$detailsAllowed) {
            try {
                $this->db->one('SELECT 1');

                return Response::json(['status' => 'ok']);
            } catch (\Throwable) {
                return Response::json(['status' => 'unavailable'], 503);
            }
        }

        $checks = [
            'php_version'  => PHP_VERSION,
            'php_ok'       => PHP_VERSION_ID >= 80100 ? 'да' : 'ТРЕБУЕТСЯ 8.1+',
            'pdo_mysql'    => extension_loaded('pdo_mysql') ? 'подключено' : 'НЕТ РАСШИРЕНИЯ',
            'mbstring'     => extension_loaded('mbstring') ? 'есть' : 'нет',
            'json'         => function_exists('json_encode') ? 'есть' : 'нет',
            'session'      => function_exists('session_start') ? 'есть' : 'нет',
            'mail_driver'  => $this->mailers->effectiveDriver(),
            'server_time'  => date('Y-m-d H:i:s'),
        ];

        try {
            $this->db->one('SELECT 1');
            $checks['database'] = 'подключено';
            $checks['database_name'] = (string) $this->db->value('SELECT DATABASE()');
            $checks['users'] = (int) $this->db->value('SELECT COUNT(*) FROM users');
            $checks['owner_exists'] = $this->db->value('SELECT 1 FROM users WHERE is_owner = 1') !== null
                ? 'да'
                : 'нет — ещё не подтверждён ни один адрес';
        } catch (\Throwable $e) {
            $checks['database'] = 'ОШИБКА: ' . $e->getMessage();
        }

        return Response::json($checks);
    }
}