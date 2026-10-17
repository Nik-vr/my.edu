<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\AuthService;
use MyEdu\Core\App;
use MyEdu\Core\Database;

[$script, $barrierDir, $email] = $argv;
$app = App::boot($rootDir);
$app->get(Database::class);
file_put_contents($barrierDir . '/' . $email . '.ready', 'ready');

$deadline = microtime(true) + 10;
while (!is_file($barrierDir . '/go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Тайм-аут стартового барьера');
    }
    usleep(10000);
}

$result = $app->get(AuthService::class)->register([
    'email'     => $email,
    'full_name' => 'Тест Гонки Владелец',
    'password'  => 'owner-race-password',
], 'http://127.0.0.1');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
