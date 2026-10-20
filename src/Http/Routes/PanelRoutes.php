<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Auth\Role;
use MyEdu\Core\Router;

/**
 * Панель учителя: всё, что живёт под `/panel`.
 *
 * Файл самый большой в карте, и это по существу, а не по недосмотру: под
 * панелью собраны работы, их выдача, перенос, предметы, классы учеников,
 * журнал и оценки. Делить его дальше имеет смысл только вместе с делением
 * контроллеров — на список адресов это не влияет, а вот разъехавшиеся по двум
 * файлам правила порядка (см. RouteFile) вернулись бы ошибкой «new разобрался
 * как номер».
 *
 * Порядок внутри блоков задан буквально и менять его нельзя: литеральные
 * адреса идут раньше адресов с `{id}`.
 */
final class PanelRoutes implements RouteFile
{
    public static function register(Router $router, Controllers $c): void
    {
        $router->get('/panel', [$c->portal, 'panel'], Role::PANEL_ACCESS);

        // ---------------------------------------------------------- пользователи

        $router->get('/panel/users', [$c->users, 'index'], Role::USERS_MANAGE);
        $router->post('/panel/users/role', [$c->users, 'saveRole'], Role::ROLES_ASSIGN);
        $router->post('/panel/users/status', [$c->users, 'saveStatus'], Role::USERS_MANAGE);
        $router->post('/panel/users/password', [$c->users, 'resetPassword'], Role::USERS_MANAGE);

        // Маршрут с {id} идёт после литеральных: иначе «roles», «status»
        // и «password» попали бы под {id} и открывали бы чужую страницу.
        $router->get('/panel/users/{id}', [$c->users, 'edit'], Role::USERS_MANAGE);
        $router->post('/panel/users/{id}', [$c->users, 'saveProfile'], Role::USERS_MANAGE);
        $router->post('/panel/users/{id}/avatar', [$c->users, 'saveAvatar'], Role::USERS_MANAGE);
    $router->get('/panel/settings', [$c->users, 'settings'], Role::USERS_MANAGE);
    $router->post('/panel/settings', [$c->users, 'saveSettings'], Role::USERS_MANAGE);
    $router->post('/panel/settings/update', [$c->update, 'apply'], Role::USERS_MANAGE);

        // Каталог предметов. Порядок тот же, что у пользователей: литеральные
        // адреса раньше адресов с {id}, иначе «store» и «status» попали бы
        // под {id}.
        $router->get('/panel/subjects', [$c->subjects, 'index'], Role::CLASSES_MANAGE);
        $router->post('/panel/subjects', [$c->subjects, 'store'], Role::CLASSES_MANAGE);
        $router->post('/panel/subjects/{id}', [$c->subjects, 'rename'], Role::CLASSES_MANAGE);
        $router->post('/panel/subjects/{id}/status', [$c->subjects, 'toggle'], Role::CLASSES_MANAGE);
        $router->post('/panel/subjects/{id}/delete', [$c->subjects, 'destroy'], Role::CLASSES_MANAGE);

        /*
         * Плагины: страница одна для всех, кто ведёт тесты, но состояние у
         * каждого своё — учитель отключает лишние типы заданий у себя.
         * Поэтому право здесь то же, что и на составление работы, а не
         * управление пользователями: раньше страницу видел только администратор.
         */
        $router->get('/panel/plugins', [$c->plugins, 'index'], Role::TESTS_MANAGE);
        $router->post('/panel/plugins/enable', [$c->plugins, 'enable'], Role::TESTS_MANAGE);
        $router->post('/panel/plugins/disable', [$c->plugins, 'disable'], Role::TESTS_MANAGE);
        $router->post('/panel/plugins/toggle', [$c->plugins, 'toggle'], Role::TESTS_MANAGE);

        // ---------------------------------------------------------- работы

        /*
         * Перенос работ между инсталляциями.
         *
         * Адреса без номера работы объявлены раньше `/panel/assignments/{id}`:
         * тот поймал бы «import» как номер и открыл бы вместо формы переноса
         * чужую страницу работы или отказ.
         */
        $router->get('/panel/assignments/import', [$c->transfer, 'form'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/import/upload', [$c->transfer, 'upload'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/import', [$c->transfer, 'import'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}/export', [$c->transfer, 'export'], Role::TESTS_MANAGE);

        /*
         * Перенос теста из OnlineTestPad — вторая вкладка той же страницы.
         *
         * Тот же адрес, что у вноса своего файла: оба живут на «Переносе
         * работ», и разводить их на разные страницы незачем. Разбор файла и
         * создание работы — на одной странице: решение владельца, финальный
         * шаг должен быть там же, где и разбор.
         *
         * Адреса формы и самой страницы разные: отправка должна попадать на
         * адрес, который обрабатывает форму, а не на адрес, который её
         * открывает. Старый адрес второго шага остаётся: он отправляет на
         * страницу разбора, чтобы ссылка из закладки не приводила в отказ.
         */
    $router->get('/panel/assignments/import/otp', [$c->otpImport, 'start'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/import/otp/upload', [$c->otpImport, 'upload'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/import/otp/setup', [$c->otpImport, 'setup'], Role::TESTS_MANAGE);
    $router->get('/panel/assignments/import/otp/setup', [$c->otpImport, 'setup'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/import/otp/reset', [$c->otpImport, 'reset'], Role::TESTS_MANAGE);

        // Картинки в текст вопроса: адрес один для всех плагинов.
        $router->post('/panel/editor/image', [$c->editorAssets, 'upload'], Role::TESTS_MANAGE);

        $router->get('/panel/assignments', [$c->assignments, 'index'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/new', [$c->assignments, 'create'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/new/start', [$c->assignments, 'editNewStart'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/new/start', [$c->assignments, 'saveNewStart'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}', [$c->assignments, 'show'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}', [$c->assignments, 'update'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/status', [$c->assignments, 'status'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}/delete', [$c->assignments, 'confirmDestroy'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/delete', [$c->assignments, 'destroy'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/tasks/new', [$c->assignments, 'createTask'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/tasks/add', [$c->assignments, 'addExistingTask'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/items/{itemId}', [$c->assignments, 'updateItem'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/items/{itemId}/delete', [$c->assignments, 'destroyItem'], Role::TESTS_MANAGE);

        /*
         * Мастер редактора работы: три этапа и операции второго.
         *
         * Адреса длиннее обычного не от красоты: /panel/assignments/{id} уже
         * занят экраном со всем сразу, и короткий /edit/{step} перекрылся бы с
         * ним при разборе пути. Слово edit вместо числового шага выбрано потому,
         * что в адресе видно, куда ведёт ссылка, а не просто «куда-то вглубь».
         */
        $router->get('/panel/assignments/{id}/edit', [$c->assignments, 'edit'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/save', [$c->assignments, 'editSave'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}/edit/start', [$c->assignments, 'editStart'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/start', [$c->assignments, 'saveStart'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}/edit/items', [$c->assignments, 'editItems'], Role::TESTS_MANAGE);
        $router->get('/panel/assignments/{id}/edit/items/editor', [$c->assignments, 'taskEditorFragment'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/items/add', [$c->assignments, 'addItemToWork'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/items/reorder', [$c->assignments, 'reorderItems'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/items/row/{position}', [$c->assignments, 'moveRowInWork'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/items/{itemId}', [$c->assignments, 'updateItemInWork'], Role::TESTS_MANAGE);
        $router->post('/panel/assignments/{id}/edit/items/{itemId}/delete', [$c->assignments, 'destroyItemInWork'], Role::TESTS_MANAGE);
    $router->get('/panel/assignments/{id}/edit/items/{itemId}/draft', [$c->assignments, 'itemDraft'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/{id}/edit/items/{itemId}/draft', [$c->assignments, 'saveItemDraft'], Role::TESTS_MANAGE);
    $router->get('/panel/assignments/{id}/edit/result', [$c->assignments, 'editResult'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/{id}/edit/result', [$c->assignments, 'saveResult'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/{id}/edit/publish', [$c->assignments, 'publish'], Role::TESTS_MANAGE);
    $router->get('/panel/assignments/{id}/attempts/{attemptId}', [$c->attemptsReview, 'show'], Role::TESTS_MANAGE);
    $router->post('/panel/assignments/{id}/attempts/{attemptId}', [$c->attemptsReview, 'save'], Role::TESTS_MANAGE);

        // ---------------------------------------------------------- выдача и итоги

        /*
         * Мастер выдачи работ.
         *
         * Порядок адресов важен: /panel/issues/new объявлено раньше
         * /panel/issues/{issueId}, иначе «new» разбиралось бы как номер выдачи.
         */
        $router->get('/panel/issues/new', [$c->issues, 'start'], Role::TESTS_MANAGE);
        $router->post('/panel/issues/start', [$c->issues, 'choose'], Role::TESTS_MANAGE);
        $router->get('/panel/issues/{id}/classes', [$c->issues, 'classes'], Role::TESTS_MANAGE);
        $router->get('/panel/issues/{id}/students', [$c->issues, 'students'], Role::TESTS_MANAGE);
        $router->get('/panel/issues/{id}/period', [$c->issues, 'period'], Role::TESTS_MANAGE);

        // Отправка шага срока идёт на тот же адрес: period() сам различает
        // показ шага и сохранение по наличию поля срока.
        $router->post('/panel/issues/{id}/period', [$c->issues, 'period'], Role::TESTS_MANAGE);
        $router->get('/panel/issues/{id}', [$c->issues, 'show'], Role::TESTS_MANAGE);

        // Результаты: кто что сдал. Право class.students — то же, что и на
        // списки учеников: кто видит состав класса, видит и его результаты.
    $router->get('/panel/journal', [$c->journal, 'index'], Role::CLASS_STUDENTS);
    $router->get('/panel/journal/work/{id}', [$c->journal, 'work'], Role::CLASS_STUDENTS);
        $router->get('/panel/ratings', [$c->ratings, 'index'], Role::TESTS_MANAGE);
    }
}