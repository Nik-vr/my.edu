<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\TokenRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;

[$script, $barrierDir, $token, $email] = $argv;
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

$user = $app->get(TokenRepository::class)->consume($token, TokenRepository::PURPOSE_RESET);
echo json_encode(['consumed' => $user !== null], JSON_THROW_ON_ERROR);
