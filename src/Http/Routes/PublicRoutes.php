<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Core\Router;

/**
 * Открытые страницы: главная, состояние сайта и вход в личный кабинет.
 *
 * Здесь нет ни одного права, кроме «вошедший»: адрес `/` открыт всем, а `/portal`
 * требует входа. Вход и регистрация живут отдельно, в `AuthRoutes`, — там
 * обратное условие, «гость», и смешивать их в одном файле значило бы держать
 * в голове два противоположных правила.
 */
final class PublicRoutes implements RouteFile
{
    public static function register(Router $router, Controllers $c): void
    {
        $router->get('/', [$c->portal, 'index']);
        $router->get('/health', [$c->health, 'show']);
        $router->get('/portal', [$c->portal, 'index'], Router::AUTH_REQUIRED);
    }
}