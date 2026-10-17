<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Auth\Role;
use MyEdu\Core\Router;

/**
 * Классы и ученики.
 *
 * Адреса живут не под `/panel`, а своими: `/classes` и `/students` — то, чем
 * пользуются и учитель, и завуч, и это их общий вход, а не раздел панели.
 *
 * Файл вынесен отдельно от панели ещё и потому, что права здесь другие: список
 * класса ведёт тот, кому положено `classes.manage`, а состав учеников и выдача
 * паролей — тому, кому `class.students`. Завуч не ведёт работы, но классы его.
 */
final class SchoolRoutes implements RouteFile
{
    public static function register(Router $router, Controllers $c): void
    {
        $router->get('/classes', [$c->classes, 'index'], Role::CLASSES_MANAGE);
        $router->get('/classes/new', [$c->classes, 'create'], Role::CLASSES_MANAGE);
        $router->post('/classes', [$c->classes, 'store'], Role::CLASSES_MANAGE);
        $router->get('/classes/{id}', [$c->classes, 'show'], Role::CLASSES_MANAGE);
        $router->post('/classes/{id}', [$c->classes, 'update'], Role::CLASSES_MANAGE);
        $router->get('/classes/{id}/delete', [$c->classes, 'confirmDestroy'], Role::CLASSES_MANAGE);
        $router->post('/classes/{id}/delete', [$c->classes, 'destroy'], Role::CLASSES_MANAGE);
        $router->get('/classes/{id}/roster', [$c->classes, 'roster'], Role::CLASSES_MANAGE);
        $router->post('/classes/{id}/subjects', [$c->classes, 'saveSubjects'], Role::CLASSES_MANAGE);
        $router->post('/classes/{id}/subjects/create', [$c->classes, 'createSubject'], Role::CLASSES_MANAGE);
        $router->post('/classes/{id}/teachers', [$c->classes, 'saveTeachers'], Role::CLASSES_MANAGE);

        $router->get('/classes/{id}/students', [$c->students, 'index'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/students', [$c->students, 'store'], Role::CLASS_STUDENTS);
        $router->get('/classes/{id}/students/template', [$c->students, 'template'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/students/import', [$c->students, 'import'], Role::CLASS_STUDENTS);
        $router->get('/classes/{id}/credentials', [$c->students, 'credentials'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/credentials/reveal', [$c->students, 'revealCredentials'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/students/remove', [$c->students, 'remove'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/students/rename', [$c->students, 'rename'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/students/status', [$c->students, 'setStatus'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/credentials/print', [$c->students, 'printCredentials'], Role::CLASS_STUDENTS);
        $router->post('/classes/{id}/credentials/issue', [$c->students, 'issuePassword'], Role::CLASS_STUDENTS);
    }
}