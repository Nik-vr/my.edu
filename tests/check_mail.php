<?php

declare(strict_types=1);

/**
 * Проверка отправки письма текущим драйвером.
 *
 * Запуск: php tests/check_mail.php
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\AuthMailer;
use MyEdu\Core\App;
use MyEdu\Core\Config;

$app = App::boot($rootDir);
$mailer = $app->get(AuthMailer::class);

echo "драйвер: " . Config::load($rootDir . '/config')->get('mail.driver', 'log') . "\n";

$sent = $mailer->sendConfirmation(
    'proverka@school.test',
    'Проверка Отправки',
    'http://127.0.0.1:8088/auth/confirm?token=' . str_repeat('a', 64)
);

echo $sent ? "письмо отправлено\n" : "письмо НЕ отправлено\n";

exit($sent ? 0 : 1);