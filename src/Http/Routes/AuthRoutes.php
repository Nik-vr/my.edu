<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Core\Router;

/**
 * Вход, регистрация, письма и профиль.
 *
 * Страницы входа и регистрации помечены `GUEST_ONLY`: вошедшему вместо них
 * показывается его кабинет. Профиль, наоборот, требует входа — и он же
 * обслуживает смену пароля и аватара.
 *
 * Ограничение частоты на эти адреса живёт не здесь, а в `Kernel::limitRate()`:
 * список адресов с пределом должен быть виден целиком, а не размазанным по
 * файлам, иначе его легко дополнить не тем адресом.
 */
final class AuthRoutes implements RouteFile
{
    public static function register(Router $router, Controllers $c): void
    {
        $router->get('/register', [$c->auth, 'showRegister'], Router::GUEST_ONLY);
        $router->post('/register', [$c->auth, 'register'], Router::GUEST_ONLY);
        $router->get('/login', [$c->auth, 'showLogin'], Router::GUEST_ONLY);
        $router->post('/login', [$c->auth, 'login'], Router::GUEST_ONLY);

        // Вход ученика — отдельной формой: логин и пароль с листа, без e-mail
        // и без регистрации. Объявлено рядом с общим входом, чтобы не потерялось
        // при переносе блока маршрутов.
        $router->get('/student/login', [$c->auth, 'showStudentLogin'], Router::GUEST_ONLY);
        $router->post('/student/login', [$c->auth, 'studentLogin'], Router::GUEST_ONLY);

        /*
         * Выход только POST-формой.
         *
         * GET-маршрут был нарисован по привычке «выход по ссылке», но выход
         * требует CSRF-токен, а в GET-запросе его нет: такой адрес всегда
         * отвечал 419 и был мёртвым. Ссылкой выход не сделать — это действие
         * с побочным эффектом, его нельзя выполнить по клику из чужой страницы.
         */
        $router->post('/logout', [$c->auth, 'logout']);

        $router->get('/confirm/resend', [$c->auth, 'showResend']);
        $router->post('/confirm/resend', [$c->auth, 'resend']);
        $router->get('/auth/confirm', [$c->auth, 'showConfirm']);
        $router->post('/auth/confirm', [$c->auth, 'confirm']);
        $router->get('/auth/forgot', [$c->auth, 'showForgot']);
        $router->post('/auth/forgot', [$c->auth, 'sendResetLink']);
        $router->get('/auth/reset', [$c->auth, 'showReset']);
        $router->post('/auth/reset', [$c->auth, 'reset']);

        // Профиль и его действия.
        $router->get('/profile', [$c->auth, 'profile'], Router::AUTH_REQUIRED);
        $router->post('/profile', [$c->auth, 'saveProfile'], Router::AUTH_REQUIRED);
        $router->post('/profile/avatar', [$c->auth, 'saveAvatar'], Router::AUTH_REQUIRED);
$router->post('/profile/font-size', [$c->auth, 'saveFontSize'], Router::AUTH_REQUIRED);
        // Тема оформления — в профиле, а не в браузере (волна E, 24.10.2026).
        // Один адрес на двоих: форма профиля и кнопка в шапке шлют сюда один
        // и тот же POST, а вид ответа выбирает сервер по признаку запроса.
        $router->post('/profile/theme', [$c->auth, 'saveTheme'], Router::AUTH_REQUIRED);
        $router->post('/profile/password', [$c->auth, 'changePassword'], Router::AUTH_REQUIRED);
    }
}