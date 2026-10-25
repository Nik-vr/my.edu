<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Auth\Role;
use MyEdu\Core\Router;

/**
 * Прохождение работы: список того, что выдано, сама работа и её попытка.
 *
 * Право одно на весь блок — `tests.take`: сотрудник проходит работу сам, и это
 * тот же адрес, что у ученика. Отдельного права «посмотреть чужую попытку» нет
 * намеренно: разбор ответа живёт в панели и требует `tests.manage`.
 */
final class WorkRoutes implements RouteFile
{
    public static function register(Router $router, Controllers $c): void
    {
        /*
         * Все выдачи идут через работу: ученик получает работу, внутри которой
         * задания разных видов. Старые адреса /tests/{id} не удаляются — плагин
         * сам перенаправляет их на /work/{id}, чтобы закладки продолжали
         * работать.
         */
        $router->get('/works', [$c->works, 'index'], Role::TESTS_TAKE);

        /*
         * Уборка тестовой попытки — литерал до шаблонов с {id}: иначе
         * «trial» читалось бы как номер работы. Право то же, что на всё
         * прохождение: уборка шлётся из самих страниц прохождения.
         */
        $router->post('/work/trial/cleanup', [$c->works, 'trialCleanup'], Role::TESTS_TAKE);

        $router->get('/work/{id}', [$c->works, 'show'], Role::TESTS_TAKE);
        $router->post('/work/{id}/start', [$c->works, 'start'], Role::TESTS_TAKE);
        $router->get('/work/attempt/{attemptId}', [$c->works, 'attempt'], Role::TESTS_TAKE);
        $router->get('/work/{attemptId}/item/{itemId}', [$c->works, 'item'], Role::TESTS_TAKE);
        $router->post('/work/{attemptId}/item/{itemId}', [$c->works, 'saveAnswer'], Role::TESTS_TAKE);
        $router->post('/work/{attemptId}/item/{itemId}/pause', [$c->works, 'pause'], Role::TESTS_TAKE);
        $router->post('/work/{attemptId}/finish', [$c->works, 'finish'], Role::TESTS_TAKE);
        $router->get('/work/{attemptId}/result', [$c->works, 'result'], Role::TESTS_TAKE);
    }
}