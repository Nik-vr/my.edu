<?php

declare(strict_types=1);

/**
 * Интеграционные тесты работ: реальные HTTP-запросы к запущенному серверу.
 *
 * Запуск:
 *   1) запустить сервер для тестов:  powershell -File tools\dev-server.ps1 -ForTests
 *   2) выполнить тесты:               php tests/run_work_tests.php http://127.0.0.1:8098
 *
 * Проверяется то, что не ловится модульными тестами: права на составление
 * работы, создание задания плагином, выдача работы ученику, разграничение
 * доступа по токену попытки, сведение оценок по весам и ручная проверка
 * учителем.
 *
 * Набор чистит работы и попытки, поэтому MYEDU_DB_NAME обязан указывать на
 * тестовую базу — это проверяет assertTestDatabase().
 */

require __DIR__ . '/support/TestKit.php';
// Картинки для проверок: собираются без GD, которого в PHP может не быть.
require __DIR__ . '/support/Png.php';

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\Actor;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\Role;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\View;

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8098', '/');

if (getenv('MYEDU_DB_NAME') === false || getenv('MYEDU_DB_NAME') === '') {
    putenv('MYEDU_DB_NAME=my_edu_review_test');
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

assertTestDatabase($db);

/*
 * Редактор задания открывается только через список позиций работы.
 *
 * Отдельной страницы у задания нет, поэтому номер задания передаётся
 * параметром ?edit= рядом с номером работы: так и выглядит то, что делает
 * автор, — нажимает на задание в списке. Проверки, которым нужна форма,
 * пользуются только этой функцией, иначе каждая второйгая начала бы собирать
 * адрес вручную и разошлась бы с реальным.
 */
$openTaskEditor = static function (TestClient $client, int $assignmentId, int $taskId): array {
    return $client->get('/panel/assignments/' . $assignmentId . '/edit/items?edit=' . $taskId);
};

/*
 * Адрес возврата берётся из самой отрисованной формы.
 *
 * Значение приходит в форму без ведущего слэша — panel/assignments/7/edit/items
 * — и именно в этом виде его возвращает браузер. Составление адреса вручную
 * прятало ошибку: с придуманным слэшем проверка проходила, а настоящая форма
 * после сохранения уводила на /tests/task/panel/assignments/7/edit/items.
 */
$backFromForm = static function (array $page): string {
    $html = (string) ($page['body'] ?? '');

    if (preg_match('/name="back" value="([^"]*)"/', $html, $m) !== 1) {
        return '';
    }

    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
};

/*
 * Набор убирает за собой не только в начале, но и в конце.
 *
 * Оставленная опубликованная работа ломает следующий набор: демо-тест,
 * засеянный заново после очистки пользователей, получает тот же номер
 * (auto_increment сброшен), и оставшаяся позиция работы вдруг начинает
 * указывать на него. Тогда /tests/{id} уводит в чужую работу вместо страницы
 * теста, и двадцать с лишним проверок падают без видимой причины.
 */
$cleanup = static function () use ($db): void {
    foreach ([
        'assignment_item_results', 'assignment_attempts', 'assignment_items', 'assignments',
    ] as $table) {
        $db->run('DELETE FROM `' . $table . '`');
    }

    foreach (['Фотосинтез, базовый', 'Проверка без учителя'] as $title) {
        $db->run('DELETE FROM tests WHERE title = :title', ['title' => $title]);
    }

    $db->run('DELETE FROM test_item_answers');
    $db->run('DELETE FROM test_item_attempts');
};

register_shutdown_function(static function () use ($cleanup): void {
    try {
        $cleanup();
    } catch (Throwable) {
        // Уборка не должна ломать итоговый отчёт: тесты уже прошли или упали.
    }
});

$t = new TestRunner();

echo "База: " . $db->value('SELECT DATABASE()') . "\n";
echo "\033[33mСкрипт очищает работы и попытки.\033[0m\n\n";

// --------------------------------------------------------------- очистка

/*
 * Пользователи не трогаются: их заводит набор тестов регистрации, и здесь
 * нужен готовый учитель с правом вести тесты.
 */
/*
 * Помимо работ чистятся тесты самого набора: по названиям, а не по id, потому
 * что номера от запуска к запуску разные, а название задаёт сам набор.
 * Демонстрационный тест набора проверок прохождения при этом не трогается.
 */
$cleanup();

$users = new UserRepository($db);

/** Учитель с паролем: через него проверяется составление работ. */
$teacherEmail = 'work-teacher@example.edu';
$teacherPassword = 'rabotnik2025';

$teacherRow = $users->findRowByIdentifier($teacherEmail);

if ($teacherRow === null) {
    $users->create([
        'login'            => $teacherEmail,
        'email'            => $teacherEmail,
        'password_hash'    => password_hash($teacherPassword, PASSWORD_DEFAULT),
        'full_name'        => 'Иванова Мария Петровна',
        'is_owner'         => 0,
        'status'           => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $teacherRow = $users->findRowByIdentifier($teacherEmail);
    $users->setRole((int) $teacherRow['id'], Role::TEACHER);
} else {
    $db->run(
        "UPDATE users SET password_hash = :hash, status = 'active', email_verified_at = NOW() WHERE id = :id",
        ['hash' => password_hash($teacherPassword, PASSWORD_DEFAULT), 'id' => (int) $teacherRow['id']]
    );

    $users->setRole((int) $teacherRow['id'], Role::TEACHER);
}

$teacherId = (int) $teacherRow['id'];

$otherTeacherEmail = 'work-other-teacher@example.edu';
$otherTeacherPassword = 'drugoy2025';
$otherTeacherRow = $users->findRowByEmail($otherTeacherEmail);

if ($otherTeacherRow === null) {
    $users->create([
        'login'             => $otherTeacherEmail,
        'email'             => $otherTeacherEmail,
        'password_hash'     => password_hash($otherTeacherPassword, PASSWORD_DEFAULT),
        'full_name'         => 'Петрова Анна Сергеевна',
        'is_owner'          => 0,
        'status'            => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $otherTeacherRow = $users->findRowByIdentifier($otherTeacherEmail);
}

$otherTeacherId = (int) $otherTeacherRow['id'];
$db->run(
    "UPDATE users SET password_hash = :hash, status = 'active', email_verified_at = NOW() WHERE id = :id",
    ['hash' => password_hash($otherTeacherPassword, PASSWORD_DEFAULT), 'id' => $otherTeacherId]
);
$users->setRole($otherTeacherId, Role::TEACHER);

/*
 * Ученик заводится так, как его заводят в системе: строкой в таблице
 * `students`, а не в `users`.
 *
 * Это не формальность. Ученик — отдельная таблица: логин, хеш пароля, ФИО и
 * класс. Ни почты, ни ролей, ни аватара у него нет — они остались у
 * сотрудников. Заводить ученика через UserRepository значило бы проверять
 * модель, которой в проекте нет: такой ученик прошёл бы тесты, но никогда не
 * появился бы в реальном классе.
 */
$classes = new ClassRepository($db);
$students = new StudentRepository($db);

$className = '8-А';

$class = $classes->findByName($className);

if ($class === null) {
    $classId = $classes->create(['name' => $className, 'title' => 'Работа на проверку', 'year' => 2026]);
} else {
    $classId = (int) $class['id'];
}

$studentLogin = 'uchebnik';
$studentPassword = 'uchebnik2025';

/*
 * makeStudent идемпотентен: при повторном прогоне ученик с тем же логином
 * приводится к ожидаемому состоянию — пароль, активный статус, класс, — а не
 * дублируется и не падает на уникальном индексе.
 */
$studentId = makeStudent($db, 'Сидоров Пётр Иванович', $studentLogin, $studentPassword, $classId);

$t->group('Учётная запись ученика');

$studentRow = $db->one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]);

$t->is($studentId > 0 && $studentRow !== null, 'ученик заведён в таблице students');
$t->is((int) ($studentRow['class_id'] ?? 0) === $classId,
    'у ученика указан класс: класс у него один и живёт в колонке, а не в списке членств');
$t->equals('active', (string) ($studentRow['status'] ?? ''),
    'ученик активен: заблокированный не может ни войти, ни начать работу');

/*
 * Почты у ученика нет вообще — ни пустой, ни незаполненной: колонки `email` в
 * таблице `students` не существует, и это проверяется по схеме, а не по значению
 * ячейки. SELECT email FROM students упал бы, а проверка «пустой ячейки» прошла
 * бы на любой придуманной колонке.
 */
$t->is(
    (int) $db->value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'email'"
    ) === 0,
    'у ученика нет почты: её нечем подтверждать, пароль выдан на бумаге'
);
$t->is($students->isInClass($classId, $studentId), 'ученик состоит в классе');

/*
 * Раньше здесь стояла проверка «SELECT COUNT(*) FROM assignments !== false».
 * Она истинна всегда: COUNT для пустой таблицы возвращает 0, а не ошибку, —
 * и строка проверяла саму себя, ничего не говоря о приложении.
 *
 * Теперь проверяется то, что в самом деле может сломаться: база тестовая.
 * На рабочей базе набор удалял бы чужие данные, и это должно быть видно
 * не по факту снесённого, а до него.
 */
$t->is(
    (int) $db->value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => 'assignments']) === 1,
    'таблица работ на месте'
);
$t->is(str_contains(strtolower((string) $db->value('SELECT DATABASE()')), 'test'), 'набор работает с тестовой базой');

/**
 * Плагин «test» должен быть включён, иначе в работе нечего положить.
 */
$pluginEnabled = $db->value("SELECT enabled FROM plugins WHERE id = 'test'") === 1;

if (!$pluginEnabled) {
    fwrite(STDERR, "Плагин test выключен в тестовой базе. Включите его: php database/migrate.php, панель.\n");
    exit(2);
}

// --------------------------------------------------------------- вход

/**
 * Вход сотрудника по логину или почте — как это делает настоящий человек.
 *
 * Проверять нужно на настоящем логине: если бы набор заводил сотрудника с
 * адресом, поле «почта» проверено бы не было.
 */
$login = static function (string $identifier, string $password): TestClient {
    $client = new TestClient($GLOBALS['baseUrl']);

    $page = $client->get('/login');

    $client->post('/login', [
        '_token'     => $client->tokenFrom($page) ?? '',
        'identifier' => $identifier,
        'password'   => $password,
    ]);

    return $client;
};

/*
 * Вход ученика — отдельная форма /student/login.
 *
 * Ученик живёт в таблице `students`, и общий вход сотрудников его не находит.
 * Вход заводится здесь так же, как им пользуется человек с листка: по логину и
 * паролю, выданным на бумаге. Шаблон у формы свой, но имена полей те же, что и
 * у сотрудников (identifier, password, _token), — по ним и собирается запрос.
 */
$loginStudent = static function (string $identifier, string $password): TestClient {
    $client = new TestClient($GLOBALS['baseUrl']);

    $page = $client->get('/student/login');

    $client->post('/student/login', [
        '_token'     => $client->tokenFrom($page) ?? '',
        'identifier' => $identifier,
        'password'   => $password,
    ]);

    return $client;
};

$GLOBALS['baseUrl'] = $baseUrl;

$teacher = $login($teacherEmail, $teacherPassword);
$otherTeacher = $login($otherTeacherEmail, $otherTeacherPassword);

/*
 * Ученик не входит через форму сотрудников.
 *
 * Проверяется до настоящего входа и отдельным клиентом: логин и пароль
 * ученика на /login обязаны быть отвергнуты, иначе ученик попал бы в сессию
 * без роли и увидел бы 403 на всех своих работах — то есть завёл бы себе
 * невидимую поломку вместо работы. Один неудачный вход не блокирует набор:
 * счётчик перебора ведётся по паре «логин + адрес», а успешный вход ученика
 * ниже снимает его счётчик начисто.
 */
$wrongDoor = new TestClient($baseUrl);
$wrongDoorPage = $wrongDoor->get('/login');
$wrongDoorPost = $wrongDoor->post('/login', [
    '_token'     => $wrongDoor->tokenFrom($wrongDoorPage) ?? '',
    'identifier' => $studentLogin,
    'password'   => $studentPassword,
]);

$t->is(
    $wrongDoorPost['status'] === 302 && str_contains((string) $wrongDoorPost['location'], '/login'),
    'ученик не входит через форму сотрудников: его возвращают назад на вход'
);
$t->is($wrongDoor->get('/works')['status'] === 302,
    'после попытки входа через форму сотрудников ученик остаётся гостем');

$student = $loginStudent($studentLogin, $studentPassword);

$t->is($student->get('/works')['status'] === 200,
    'ученик входит через свою форму /student/login');

// --------------------------------------------------------------- права

$t->group('Права на составление работы');

$t->is($teacher->get('/panel/assignments')['status'] === 200, 'учитель открывает список работ');

$guest = new TestClient($baseUrl);
$guestList = $guest->get('/panel/assignments');
$t->is($guestList['status'] === 302 && str_contains($guestList['location'], '/login'),
    'гость отправляется на вход вместо списка работ');

// Ученик не должен видеть панель работ даже по прямому адресу.
$t->is($student->get('/panel/assignments')['status'] === 403, 'ученику список работ закрыт');

// --------------------------------------------------------------- создание

$t->group('Создание работы');

$listPage = $teacher->get('/panel/assignments');

/*
 * Кнопка «Составить работу» открывает мастер сразу.
 *
 * Отдельной страницы с одной кнопкой больше нет. Она стоила лишнего щелчка и
 * главное — оставляла в списке пустые черновики: человек открывал мастер,
 * передумывал и закрывал, а работа без названия висела в списке навсегда.
 */
$newPage = $teacher->get('/panel/assignments/new/start');

$t->is($newPage['status'] === 200, 'кнопка открывает первый шаг мастера сразу');
$t->contains($newPage['body'], 'name="title"', 'на первом шаге сразу спрашивается название');
$t->notContains($newPage['body'], 'Создать черновик', 'промежуточной кнопки создания нет');

/*
 * Ссылка «Составить работу» открывает тот же редактор, что и «Редактировать».
 *
 * Редактор у работы один, и он вкладчатый. Раньше для новой работы был
 * отдельный первый шаг мастера со своей формой, и у проекта оказывалось два
 * редактора с разными интерфейсами: по ссылке «Составить работу» — один, из
 * списка работ — другой.
 *
 * Работа появляется сразу, а название задаётся на первой вкладке: пустая
 * строка в списке видна как «Без названия» и не выглядит забытой работой.
 */
$composeLink = $teacher->get('/panel/assignments');

$t->is(
    str_contains((string) ($composeLink['body'] ?? ''), 'panel/assignments/new'),
    'в списке работ есть ссылка «Составить работу»'
);

$createdByLink = $teacher->get('/panel/assignments/new');

$t->is($createdByLink['status'] === 302, 'ссылка «Составить работу» уводит в редактор');
$t->is(
    preg_match('#/panel/assignments/(\d+)/edit/start#', (string) ($createdByLink['location'] ?? '')) === 1,
    'открывается вкладчатый редактор, а не отдельный первый шаг'
);

$composedId = (int) (preg_match('#/panel/assignments/(\d+)/edit/start#', (string) ($createdByLink['location'] ?? ''), $composedMatch) === 1
    ? $composedMatch[1]
    : 0);

$composedEditor = $teacher->get('/panel/assignments/' . $composedId . '/edit/start');

$t->is($composedEditor['status'] === 200, 'редактор новой работы открывается');
$t->contains($composedEditor['body'], 'Изменить название',
    'у названия есть карандаш, как у имени ученика в списке класса');
$t->notContains($composedEditor['body'], 'name="title"',
    'в обычном виде названия в шапке нет: оно текстом, а правится по карандашу');

$titleEdit = $teacher->get('/panel/assignments/' . $composedId . '/edit?edit=title');

$t->contains($titleEdit['body'], 'name="title"',
    'по карандашу название превращается в поле');
$t->is(
    preg_match('#name="title"[^>]*value="(?!Без названия)#', (string) ($titleEdit['body'] ?? '')) === 1,
    'поле названия без заглушки «Без названия»'
);

/*
 * О сохранении названия сообщают всплывающим сообщением, а не баннером.
 *
 * Страница при этом не уходит: человек видит на ней же новое название, и
 * баннер «Название сохранено» был бы второй вещью об одном. Сообщение
 * всплывающее — как «Порядок сохранён» в доске заданий.
 */
$titleSaved = $teacher->post('/panel/assignments/' . $composedId . '/edit/save', [
    '_token'     => $teacher->tokenFrom($titleEdit) ?? '',
    'title_only' => '1',
    'title'      => 'Работа, названная в шапке',
]);

$t->is($titleSaved['status'] === 302, 'название сохранено');
$t->equals(
    'Работа, названная в шапке',
    (string) $db->value('SELECT title FROM assignments WHERE id = :id', ['id' => $composedId]),
    'новое название записано в работу'
);

$afterTitleSave = $teacher->get('/panel/assignments/' . $composedId . '/edit');

$t->contains($afterTitleSave['body'], 'data-toast="Название сохранено"',
    'о сохранении названия сказано всплывающим сообщением');
$t->is(
    preg_match('~class="banner[^"]*"[^>]*>\s*Название сохранено~u', (string) ($afterTitleSave['body'] ?? '')) !== 1,
    'баннера о сохранении названия на странице нет'
);
$t->contains($afterTitleSave['body'], 'assets/js/toast.js',
    'скрипт всплывающих сообщений на странице подключён');

/*
 * Поле названия пустое, а не подставленное.
 *
 * Было «Новая работа» — и сохранение без правки создавало работу с этим
 * именем. Человек нажал «Сохранить» на пустом шаге и получил черновик, который
 * выглядел готовым: имя есть, содержания нет.
 */
$t->is(
    preg_match('#name="title"[^>]*value=""#', $newPage['body']) === 1,
    'поле названия пустое, а не подставлено заглушкой'
);

$worksBefore = (int) $db->value('SELECT COUNT(*) FROM assignments');

/*
 * Пустой мастер не оставляет следа.
 *
 * Человек нажал «Составить работу», ничего не ввёл и ушёл: в списке работ
 * ничего не должно появиться. Иначе через месяц там висели бы десятки
 * пустых черновиков, и каждый выглядел бы как забытая работа.
 */
$emptyTitle = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($newPage) ?? '',
    'title'  => '',
]);

$t->is($emptyTitle['status'] === 302, 'создание без названия не ломается');
$t->is(
    str_contains((string) ($emptyTitle['location'] ?? ''), '/panel/assignments/new/start'),
    'отказ возвращает на первый шаг, а не ведёт в несуществующую работу'
);
$t->equals($worksBefore, (int) $db->value('SELECT COUNT(*) FROM assignments'),
    'работа без введённых данных не сохранилась');

/*
 * Пустое название не должно превращаться в чужое.
 *
 * Раньше поле было подставлено заглушкой «Новая работа», и отправка формы без
 * правки создавала работу с этим именем — она выглядела готовой, хотя её никто
 * не начинал.
 */
$beforeStub = (int) $db->value('SELECT COUNT(*) FROM assignments');

$stub = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($newPage) ?? '',
    'title'  => '',
]);

$t->equals($beforeStub, (int) $db->value('SELECT COUNT(*) FROM assignments'),
    'пустое название не создало работу с именем-заглушкой');

$t->is($stub['status'] === 302, 'отказ на пустом названии не ломает страницу');

/*
 * Название на первой вкладке задаёт имя работы.
 */
$created = $teacher->post('/panel/assignments/new/start', [
    '_token'     => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'      => 'Контрольная по биологии',
    'grade_mode' => 'weighted',
]);

$t->is($created['status'] === 302, 'работа создана и открыта для правки');
$t->is(
    preg_match('#/panel/assignments/(\d+)/edit/(items|start)#', (string) ($created['location'] ?? '')) === 1,
    'после первого шага мастер ведёт к заданиям'
);

$assignmentId = (int) $db->value("SELECT id FROM assignments WHERE title = 'Контрольная по биологии' LIMIT 1");
$t->is($assignmentId > 0, 'работа появилась в базе');
$t->equals('draft', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'новая работа — черновик');

// Публиковать пустую работу нельзя: ученик увидел бы её без единого задания.
$editorPage = $teacher->get('/panel/assignments/' . $assignmentId);

$publishEmpty = $teacher->post('/panel/assignments/' . $assignmentId . '/status', [
    '_token' => $teacher->tokenFrom($editorPage) ?? '',
    'action' => 'publish',
]);

$t->is($publishEmpty['status'] === 302, 'публикация пустой работы не проходит');
$t->equals('draft', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'работа осталась черновиком');

// --------------------------------------------------------------- задания

$t->group('Добавление задания плагином');

/*
 * Задание создаёт плагин: ядро просит его через createTask() и кладёт позицию
 * по полученному номеру. Ядро не знает, что это за задание, поэтому проверка
 * идёт через результат: номер больше нуля и позиция ссылается на плагина.
 */
$editorPage = $teacher->get('/panel/assignments/' . $assignmentId);

$withTask = $teacher->post('/panel/assignments/' . $assignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($editorPage) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'title'     => 'Фотосинтез, базовый',
    'weight'    => '3',
]);

$t->is($withTask['status'] === 302, 'задание добавлено в работу');

$itemRow = $db->one('SELECT * FROM assignment_items WHERE assignment_id = :id', ['id' => $assignmentId]);

$t->is($itemRow !== null, 'позиция работы создана');
$t->equals('test', (string) ($itemRow['plugin_id'] ?? ''), 'позиция ссылается на плагина');
$t->equals('single_choice', (string) ($itemRow['task_kind'] ?? ''), 'вид задания записан');
$t->is((int) ($itemRow['task_id'] ?? 0) > 0, 'номер задания получен от плагина');
$t->equals(3.0, (float) ($itemRow['weight'] ?? 0), 'вес позиции сохранён');
$t->equals(1, (int) ($itemRow['position'] ?? 0), 'позиция первая в работе');

$testId = (int) ($itemRow['task_id'] ?? 0);

$t->equals('draft', (string) $db->value('SELECT status FROM tests WHERE id = :id', ['id' => $testId]),
    'новый тест создан черновиком');
$t->contains((string) $db->value('SELECT title FROM tests WHERE id = :id', ['id' => $testId]), 'Фотосинтез',
    'название задания передано плагину');

// Чужой плагин в запросе не должен подставляться.
$editorPage = $teacher->get('/panel/assignments/' . $assignmentId);

$fakePlugin = $teacher->post('/panel/assignments/' . $assignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($editorPage) ?? '',
    'plugin_id' => 'nesushchestvuyushchiy',
    'task_kind' => 'test',
]);

$t->is($fakePlugin['status'] === 302, 'несуществующий плагин не роняет страницу');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', ['id' => $assignmentId]),
    'позиция из неизвестного плагина не добавлена');

// --------------------------------------------------------------- редактор задания

$t->group('Редактор задания');


$otherTeacherAssignmentPage = $otherTeacher->get('/panel/assignments');
$assignmentCountBeforeForeignAdd = (int) $db->value(
    'SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id',
    ['id' => $assignmentId]
);
$foreignAdd = $otherTeacher->post('/panel/assignments/' . $assignmentId . '/tasks/add', [
    '_token'    => $otherTeacher->tokenFrom($otherTeacherAssignmentPage) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'test',
    'task_id'   => (string) $testId,
]);
$t->is($foreignAdd['status'] === 403, 'чужой учитель не добавляет задание в чужую работу');
$t->equals($assignmentCountBeforeForeignAdd, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id',
    ['id' => $assignmentId]
), 'чужая попытка добавить тест не меняет работу');

$ownAssignment = $otherTeacher->post('/panel/assignments/new/start', [
    '_token' => $otherTeacher->tokenFrom($otherTeacherAssignmentPage) ?? '',
    'title'  => 'Работа второго учителя',
]);
$ownAssignmentPage = $otherTeacher->get('/panel/assignments');
$otherAssignmentId = (int) $db->value("SELECT id FROM assignments WHERE author_id = :author AND title = 'Работа второго учителя' ORDER BY id DESC LIMIT 1", [
    'author' => $otherTeacherId,
]);
$t->is($ownAssignment['status'] === 302 && $otherAssignmentId > 0, 'второй учитель создал собственную работу');
$addForeignTestToOwnWork = $otherTeacher->post('/panel/assignments/' . $otherAssignmentId . '/tasks/add', [
    '_token'    => $otherTeacher->tokenFrom($ownAssignmentPage) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'test',
    'task_id'   => (string) $testId,
]);
$t->is($addForeignTestToOwnWork['status'] === 302, 'в работу автора не добавляется чужой тест');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', [
    'id' => $otherAssignmentId,
]), 'чужой тест не привязан к работе');

/*
 * Отдельной страницы редактора задания больше нет: задание правится только
 * там, где перечислено, — в списке позиций работы.
 *
 * Адрес не исчез целиком: он остался для приёма сохранения, поэтому GET на
 * нём отвечает «метод не поддерживается», а не «страницы нет». Разница важна:
 * 404 означал бы, что по старой закладке открылось бы что-то другое, а здесь
 * видно, что это тот же адрес, но уже без экрана.
 */
$t->is($teacher->get('/tests/task/' . $testId)['status'] === 405, 'отдельной страницы редактора задания нет');
$t->is($otherTeacher->get('/tests/task/' . $testId)['status'] === 405, 'и у другого учителя её нет');

$otherTeacherEditorPage = $otherTeacher->get('/panel/assignments');
$foreignEdit = $otherTeacher->post('/tests/task/' . $testId, [
    '_token'      => $otherTeacher->tokenFrom($otherTeacherEditorPage) ?? '',
    'action'      => 'save_task',
    'question_id' => '0',
    'title'       => 'Чужая правка',
    'prompt'      => 'Чужой вопрос',
    'weight'      => '1',
    'option_text[]' => ['Первый', 'Второй'],
    'correct_single' => 'a',
    'back'           => $backFromForm($otherTeacherEditorPage),
]);
$t->is($foreignEdit['status'] === 403, 'чужой учитель получает отказ при сохранении чужого задания');
$t->equals('draft', (string) $db->value('SELECT status FROM tests WHERE id = :id', ['id' => $testId]),
    'отказ чужому учителю не меняет задание');

/*
 * Прежний адрес удалён вместе с редактором теста. Проверяется явно: пока он
 * существовал, автор мог уйти по старой ссылке и обнаружить там список
 * вопросов вместо своего единственного вопроса.
 */
$removedEditor = $teacher->get('/tests/editor/' . $testId);

$t->is($removedEditor['status'] === 404, 'прежний адрес редактора теста больше не отвечает');

$t->group('Наполнение задания');

/*
 * Редактор рисует плагин, поэтому адрес формы собирается из его префикса, а
 * открыть его можно только через список позиций работы — параметром ?edit=.
 */
$itemsUrl = '/panel/assignments/' . $assignmentId . '/edit/items';
$taskEditorUrl = '/tests/task/' . $testId;
$openEditor = static fn (int $taskId): array => $teacher->get($itemsUrl . '?edit=' . $taskId);

$taskEditor = $openEditor($testId);

$t->is($taskEditor['status'] === 200, 'список заданий открыт');
$t->contains($taskEditor['body'], 'name="option_text[]"', 'в редакторе есть варианты ответа');
$t->contains($taskEditor['body'], 'name="correct_single"', 'правильный вариант отмечается');

/*
 * Решение владельца проекта: редактор — обычные формы, без ручного JSON.
 * Проверяем, что в разметке нет поля, куда вставляют конфигурацию вопроса.
 */
$t->notContains($taskEditor['body'], 'name="config"', 'в редакторе нет поля для JSON конфигурации');
$t->notContains($taskEditor['body'], '<script>alert', 'редактор не просит кода');

/*
 * Тип задания выбирается при добавлении в работу и на этом экране не меняется:
 * форма уже нарисована под конкретный вид ответа, и смена типа в форме означала
 * бы вопрос, для которого поля не совпадают.
 */
$t->notContains($taskEditor['body'], 'id="question-type"', 'тип задания в редакторе не переключается');

/*
 * Публикации задания нет: задание выпускается вместе с работой, последним
 * шагом мастера. В редакторе остаётся ровно одно действие — сохранить.
 */
$t->notContains($taskEditor['body'], 'Опубликовать задание', 'в редакторе нет кнопки публикации задания');
$t->notContains($taskEditor['body'], '/task/' . $testId . '/status', 'адреса публикации задания больше нет');
$t->contains($taskEditor['body'], 'Сохранить задание', 'в редакторе есть кнопка сохранения');

// Задание создано плагином с черновым вопросом — доводим его до готового.
$questionId = (int) $db->value('SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1', ['id' => $testId]);

$t->is($questionId > 0, 'у задания есть черновой вопрос, который автор наполняет');

$taskEditor = $openEditor($testId);

$saveQuestion = $teacher->post($taskEditorUrl, [
    '_token'        => $teacher->tokenFrom($taskEditor) ?? '',
    'action'        => 'save_task',
    'question_id'   => (string) $questionId,
    'type'          => 'single_choice',
    'title'         => 'Фотосинтез',
    'prompt'        => 'Какой газ растение поглощает при фотосинтезе?',
    'weight'        => '1',
    'is_required'   => '1',
    'explanation'   => 'Углекислый газ.',
    'correct_single' => 'b',
    'option_text[]' => ['Углекислый газ', 'Кислород', 'Азот', 'Водород'],
    'back'          => $backFromForm($taskEditor),
]);

$t->is($saveQuestion['status'] === 302, 'вопрос сохранён через форму задания');

/*
 * После сохранения редактор закрывается: возврат ведёт в список позиций без
 * параметра edit. Иначе автор возвращался бы к открытой форме вместо списка,
 * ради которого он её и открывал.
 */
$t->is(
    !str_contains((string) $saveQuestion['location'], 'edit='),
    'после сохранения редактор закрыт, а параметр edit убран из адреса'
);

/*
 * Адрес возврата обязан быть от корня.
 *
 * Response::redirect() кладёт в Location строку как есть, а поле back
 * приходит из формы без ведущего слэша. Браузер разрешает такой адрес
 * относительно того, куда ушёл POST, — то есть относительно /tests/task/{id},
 * — и уходил на /tests/task/panel/assignments/14/edit/items: 404 после
 * успешного сохранения. Тест-клиент редиректы не разрешает, поэтому ошибка
 * проходила незамеченной, и проверяется здесь именно начало адреса.
 */
$savedLocation = (string) $saveQuestion['location'];

$t->is(str_starts_with($savedLocation, '/'), 'адрес возврата начинается с корня');
$t->is(!str_starts_with($savedLocation, '/tests/task/'), 'адрес возврата не остался внутри адреса плагина');

/*
 * Заголовка задания нет. Проверяется не «поле спрятано», а отсутствие самого
 * поля в форме задания: пока в ней есть `name="title"`, любой браузер и любой
 * скрипт продолжит присылать заголовок, и сервер будет обязан где-то его
 * хранить, чтобы не потерять.
 *
 * Берётся сама форма задания, а не страница доски: страница редактора — это
 * четыре вкладки на одной, и на ней есть `name="title"` у работы. Раньше
 * доска была отдельной страницей, и проверка обходилась всей страницей; теперь
 * это было бы проверкой не того — заголовок работы полю задания не мешает.
 */
$taskEditorFragment = $teacher->get($itemsUrl . '/editor?task=' . $testId);
$taskEditorJson = json_decode((string) $taskEditorFragment['body'], true);
$taskEditorHtml = (string) (is_array($taskEditorJson) ? ($taskEditorJson['html'] ?? '') : '');

$t->is($taskEditorFragment['status'] === 200, 'форма задания отдаётся отдельным адресом');
$t->contains($taskEditorHtml, 'name="option_text[]"', 'форма задания пришла целиком');
$t->notContains($taskEditorHtml, 'name="title"', 'в редакторе задания больше нет поля заголовка');

$t->equals('single_choice', (string) $db->value('SELECT type FROM questions WHERE id = :id', ['id' => $questionId]),
    'тип вопроса записан');
$t->contains((string) $db->value('SELECT prompt FROM questions WHERE id = :id', ['id' => $questionId]), 'фотосинтезе',
    'текст задания сохранён');
$t->equals(4, (int) $db->value('SELECT COUNT(*) FROM question_options WHERE question_id = :id', ['id' => $questionId]),
    'все четыре варианта сохранены');
$t->contains((string) $db->value('SELECT config FROM questions WHERE id = :id', ['id' => $questionId]), '"correct":"b"',
    'правильным отмечен выбранный вариант');

/*
 * Повторное сохранение уже сохранённого вопроса.
 *
 * Раньше форма несла блоки всех видов выбора, и отметка попадала в одно поле
 * у обоих: браузер слал `correct=1` от кружка и `correct[]=1` от галочки того
 * же вопроса. PHP складывал их в одно место, значение становилось массивом,
 * и одиночный выбор читал его как «отметки нет» — с отказом сохранить.
 *
 * У нового вопроса чужой отметки ещё нет, и он проходит: ошибка выглядела
 * случайной и зависела от того, открывали ли вопрос заново.
 *
 * Сейчас на странице только блок своего вида, поэтому отправляется ровно то,
 * что в ней нарисовано, — и берётся прямо из разметки, а не составляется
 * руками: ручной список не увидел бы размножения вариантов.
 */
/*
 * Отмеченный кружок ищется по тегу целиком, а не по соседству атрибутов.
 *
 * Разметку поля рисует ядро (`$ui->optionRows()`), и порядок атрибутов в ней —
 * следствие того, как собран тег, а не часть договора. Проверка вида
 * `name="…" value="…"\s+checked` ломалась от перестановки `id` и молча
 * сообщала, что отметки нет, хотя она стоит.
 */
$checkedRadio = static function (string $html): string {
    if (preg_match_all('#<input[^>]*name="correct_single"[^>]*>#', $html, $inputs) !== 1
        && $inputs[0] === []) {
        return '';
    }

    foreach ($inputs[0] as $tag) {
        if (str_contains($tag, ' checked')) {
            return $tag;
        }
    }

    return '';
};

$formFields = static function (array $page) use ($checkedRadio): array {
    $html = (string) ($page['body'] ?? '');

    preg_match_all('/name="option_text\[\]"[^>]*value="([^"]*)"/', $html, $texts);

    /*
     * Берётся именно отмеченный кружок, а не первый попавшийся: отметка
     * передаётся номером строки, и первый кружок в разметке — это вариант
     * «Двоичная», а не верный. С такой ошибкой правильный ответ молча
     * съезжал на соседний вариант.
     */
    $checked = $checkedRadio($html);
    $row = preg_match('#value="(\d+)"#', $checked, $rowMatch) === 1 ? $rowMatch[1] : '';

    return [
        'options' => array_map(
            static fn (string $value): string => html_entity_decode($value, ENT_QUOTES, 'UTF-8'),
            $texts[1]
        ),
        'correct' => $row,
    ];
};

$reopened = $openEditor($testId);

$t->is(
    $checkedRadio((string) $reopened['body']) !== '',
    'кружок правильного варианта помечен в своём поле'
);

/*
 * Отметка должна стоять в строке своего варианта, а не в любой.
 *
 * Проверяется по строке целиком: раньше этого не проверялось, а ошибка
 * выглядит правдоподобно — список вариантов на месте, отметка есть, человек
 * сохраняет, а правильным оказывается соседний вариант.
 */
$markedRow = '';
$rows = [];

preg_match_all('#<div class="option-row"[^>]*>(.*?)</div>#s', (string) $reopened['body'], $rows);

foreach ($rows[1] ?? [] as $rowHtml) {
    if (str_contains($rowHtml, ' checked')) {
        $markedRow = $rowHtml;
        break;
    }
}

$t->contains($markedRow, 'Кислород',
    'отметка стоит в строке именно того варианта, который правильный');

$t->notContains($reopened['body'], 'name="correct_multi"',
    'в чужом блоке отметки не осталось: списки разведены по видам выбора');

$saveAgain = $teacher->post($taskEditorUrl, [
    '_token'         => $teacher->tokenFrom($reopened) ?? '',
    'action'         => 'save_task',
    'question_id'    => (string) $questionId,
    'type'           => 'single_choice',
    'title'          => 'Фотосинтез',
    'prompt'         => 'Какой газ растение поглощает при фотосинтезе?',
    'weight'         => '1',
    'is_required'    => '1',
    'correct_single' => $formFields($reopened)['correct'],
    'option_text[]'  => $formFields($reopened)['options'],
    'back'           => $backFromForm($reopened),
]);

$t->is($saveAgain['status'] === 302, 'повторное сохранение вопроса не отклонено');

$reopenedAgain = (string) $openEditor($testId)['body'];

$t->contains($reopenedAgain, 'Углекислый газ', 'варианты ответа остались на месте');
$t->notContains($reopenedAgain, 'Отметьте правильный вариант ответа',
    'ошибка о неотмеченном варианте больше не появляется');

/*
 * Число вариантов не растёт от правки к правке.
 *
 * Это ровно то, что было видно в данных: четыре варианта разрастались до
 * двадцати двух. На странице стояли обе формы выбора, каждая рисовала свои
 * `option_text[]` с одним и тем же содержимым, и при сохранении варианты
 * приходили дважды — четыре превращались в восемь, восемь в шестнадцать.
 * Скрытие не помогало: браузер отправляет и скрытые поля.
 *
 * Три сохранения подряд — столько нужно, чтобы заметить: со второго вариантов
 * уже вдвое больше, и вопрос выглядит испорченным.
 */
$optionCount = static function () use ($db, $questionId): int {
    return (int) $db->value(
        'SELECT COUNT(*) FROM question_options WHERE question_id = :id AND kind = :kind',
        ['id' => $questionId, 'kind' => 'option']
    );
};

$t->equals(4, $optionCount(), 'после сохранения вариантов ровно четыре');

for ($save = 0; $save < 3; $save++) {
    $page = $openEditor($testId);
    $fields = $formFields($page);

    $teacher->post($taskEditorUrl, [
        '_token'         => $teacher->tokenFrom($page) ?? '',
        'action'         => 'save_task',
        'question_id'    => (string) $questionId,
        'type'           => 'single_choice',
        'title'          => 'Фотосинтез',
        'prompt'         => 'Какой газ растение поглощает при фотосинтезе?',
        'weight'         => '1',
        'is_required'    => '1',
        'correct_single' => $fields['correct'],
        'option_text[]'  => $fields['options'],
        'back'           => $backFromForm($page),
    ]);
}

$t->equals(4, $optionCount(), 'варианты не размножились за четыре сохранения подряд');
$t->equals(
    4,
    substr_count(
        (string) ($openEditor($testId)['body'] ?? ''),
        'class="option-row"'
    ),
    'в форме снова четыре строки вариантов'
);
$t->contains(
    (string) $db->value('SELECT config FROM questions WHERE id = :id', ['id' => $questionId]),
    '"correct":"b"',
    'правильный вариант сохранился тем же'
);


/*
 * Без отмеченного правильного варианта вопрос сохранить нельзя. Отказ должен
 * быть понятным сообщением в форме, а не ошибкой сервера: автор видит, что
 * именно он не отметил, и правка не теряется.
 *
 * При отказе редактор остаётся открытым — иначе форма, которую человек уже
 * собрал, закрывалась бы сама и заставляла начать заново.
 */
$taskEditor = $openEditor($testId);

$noCorrect = $teacher->post($taskEditorUrl, [
    '_token'        => $teacher->tokenFrom($taskEditor) ?? '',
    'action'        => 'save_task',
    'question_id'   => (string) $questionId,
    'type'          => 'single_choice',
    'title'         => 'Фотосинтез',
    'prompt'        => 'Вопрос без правильного варианта',
    'weight'        => '1',
    'option_text[]' => ['Первый', 'Второй'],
    'back'          => $backFromForm($taskEditor),
]);

$t->is($noCorrect['status'] === 302, 'вопрос без верного варианта не роняет страницу');
$t->is(str_contains((string) $noCorrect['location'], 'edit='),
    'при отказе редактор остаётся открытым');
$t->contains($teacher->get($itemsUrl . '?edit=' . $testId)['body'], 'варианта',
    'автор видит, чего не хватило в вопросе');
$t->contains((string) $db->value('SELECT prompt FROM questions WHERE id = :id', ['id' => $questionId]), 'фотосинтезе',
    'неудачная правка не заменила прежний вопрос');

/*
 * Заголовка у задания нет, и потому нет и отказа по нему: раньше пустое
 * поле имени отклоняло сохранение. Проверяется обратное — правка условия без
 * заголовка проходит и запись остаётся на месте.
 */
$withoutTitle = $teacher->post($taskEditorUrl, [
    '_token'        => $teacher->tokenFrom($openEditor($testId)) ?? '',
    'action'        => 'save_task',
    'question_id'   => (string) $questionId,
    'type'          => 'single_choice',
    'title'         => '   ',
    'prompt'        => 'Вопрос без заголовка',
    'weight'        => '1',
'option_text[]' => ['Первый', 'Второй'],
    /*
     * Верным остаётся «Второй» — тем же вариантом, каким ученик отвечает
     * дальше по сценарию. Здесь стоял «Первый», и правка молча переписала
     * верный ответ: первое задание перестало приносить балл, и следом падало
     * всё сведение работы — проценты, оценка, цвет отметки. Проверка правки и
     * проверка прохождения обязаны пользоваться одним ответом, иначе одна из
     * них всегда врёт.
     */
    'correct_single' => 'b',
    'back' => $backFromForm($taskEditor),
]);

$t->is($withoutTitle['status'] === 302, 'правка без заголовка не отклоняется');
$t->contains((string) $db->value('SELECT prompt FROM questions WHERE id = :id', ['id' => $questionId]), 'без заголовка',
    'условие сохранено: заголовка у задания больше нет, отказывать не из-за чего');

/*
 * Верный ответ после правки остался прежним.
 *
 * Проверка стоит здесь, а не там, где падает сведение работы. Расхождение
 * между тем, чем отвечает ученик, и тем, что засчитано верным, выглядит
 * совсем иначе: не «правка сломала задание», а «работа посчитана неверно» —
 * проценты, оценка, цвет отметки, и всё это далеко по файлу от места, где
 * правка и правда поехала.
 */
$t->contains(
    (string) $db->value('SELECT config FROM questions WHERE id = :id', ['id' => $questionId]),
    '"b"',
    'верный ответ после правки прежний — на него опирается прохождение работы'
);

// --------------------------------------------------------------- публикация работы

$t->group('Публикация работы');

/*
 * Второе задание работы — текст с ручной проверкой. В новой модели это
 * отдельное задание, а не второй вопрос внутри первого: задание — это один
 * вопрос одного типа, и смешивать в нём автоматическую и ручную проверку
 * больше нечем.
 *
 * Вес у него 1. Так среднее по весам отличается от среднего по количеству:
 * первое задание весит 3.
 */
$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$manual = $teacher->post('/panel/assignments/' . $assignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($editor) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'title'     => 'Хлорофилл своими словами',
    'weight'    => '1',
]);

$t->is($manual['status'] === 302, 'второе задание добавлено');

$manualItem = $db->one(
    'SELECT * FROM assignment_items WHERE assignment_id = :id AND task_id <> :first ORDER BY id DESC LIMIT 1',
    ['id' => $assignmentId, 'first' => $testId]
);

$t->is($manualItem !== null, 'позиция второго задания создана');

$manualTestId = (int) ($manualItem['task_id'] ?? 0);
$t->is($manualTestId > 0 && $manualTestId !== $testId, 'это отдельное задание, а не копия первого');

// Наполняем его текстовым вопросом с ручной проверкой.
$manualEditor = $openEditor($manualTestId);

$t->is($manualEditor['status'] === 200, 'редактор текстового задания открыт');

$manualQuestionId = (int) $db->value('SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1', ['id' => $manualTestId]);

$teacher->post('/tests/task/' . $manualTestId, [
    '_token'      => $teacher->tokenFrom($manualEditor) ?? '',
    'action'      => 'save_task',
    'question_id' => (string) $manualQuestionId,
    'type'        => 'text',
    'title'       => 'Роль хлорофилла',
    'prompt'      => 'Объясните, зачем растению хлорофилл',
    'weight'      => '1',
    'is_required' => '1',
    'strategy'    => 'manual',
    'rows'        => '4',
]);

$t->contains(
    (string) $db->value('SELECT config FROM questions WHERE id = :id', ['id' => $manualQuestionId]),
    'manual',
    'выбрана ручная проверка'
);

$manualEditor = $openTaskEditor($teacher, $assignmentId, $manualTestId);

/*
 * Задание отдельно не публикуется: состояние задания ученику ничего не
 * сообщает, он видит работу. Публикуется работа целиком последним шагом
 * мастера, и до неё все задания остаются такими же доступными автору.
 */
$t->equals('draft', (string) $db->value('SELECT status FROM tests WHERE id = :id', ['id' => $manualTestId]),
    'второе задание осталось черновиком до публикации работы');

/*
 * Третье задание — снова одиночный выбор, но проверяемый полностью
 * автоматически. Оно даёт ученику правильный ответ, который не зависит от
 * решения учителя, и отдельную ссылку для проверки совместимости адресов.
 */
$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$second = $teacher->post('/panel/assignments/' . $assignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($editor) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'title'     => 'Проверка без учителя',
    'weight'    => '1',
]);

$t->is($second['status'] === 302, 'третье задание добавлено');

$secondItem = $db->one(
    'SELECT * FROM assignment_items WHERE assignment_id = :id AND task_id NOT IN (:first, :manual) ORDER BY id DESC LIMIT 1',
    ['id' => $assignmentId, 'first' => $testId, 'manual' => $manualTestId]
);

$t->is($secondItem !== null, 'позиция третьего задания создана');

$secondTestId = (int) ($secondItem['task_id'] ?? 0);
$t->is($secondTestId > 0 && !in_array($secondTestId, [$testId, $manualTestId], true),
    'это отдельное задание, а не копия первого');

// Наполняем его одним автоматически проверяемым вопросом на 2 балла.
$secondEditor = $openTaskEditor($teacher, $assignmentId, $secondTestId);
$secondQuestionId = (int) $db->value('SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1', ['id' => $secondTestId]);

$teacher->post('/tests/task/' . $secondTestId, [
    '_token'        => $teacher->tokenFrom($secondEditor) ?? '',
    'action'        => 'save_task',
'question_id' => (string) $secondQuestionId,
    'type'        => 'single_choice',
    'title'       => 'Дыхание растений',
    'prompt'        => 'Какой газ выделяют растения при дыхании?',
    'weight'        => '2',
    'is_required'   => '1',
    'correct_single' => 'a',
    'option_text[]' => ['Углекислый газ', 'Кислород', 'Азот'],
]);

$secondEditor = $openTaskEditor($teacher, $assignmentId, $secondTestId);

$t->equals('draft', (string) $db->value('SELECT status FROM tests WHERE id = :id', ['id' => $secondTestId]),
    'третье задание осталось черновиком до публикации работы');

$t->equals(3, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', ['id' => $assignmentId]),
    'в работе три позиции');

$items = $db->all(
    'SELECT id, position, weight FROM assignment_items WHERE assignment_id = :id ORDER BY position',
    ['id' => $assignmentId]
);

$firstItemId = (int) $items[0]['id'];
$manualItemId = (int) $items[1]['id'];
$secondItemId = (int) $items[2]['id'];

$t->equals(3.0, (float) $items[0]['weight'], 'вес первого задания — 3');
$t->equals(1.0, (float) $items[1]['weight'], 'вес второго задания — 1');
$t->equals(1.0, (float) $items[2]['weight'], 'вес третьего задания — 1');

// Перестановка позиций работы: та же арифметика, что и у вопросов.
$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$teacher->post('/panel/assignments/' . $assignmentId . '/items/' . (int) $items[1]['id'], [
    '_token'   => $teacher->tokenFrom($editor) ?? '',
    'weight'   => '1',
    'position' => '1',
]);

$items = $db->all(
    'SELECT id, position FROM assignment_items WHERE assignment_id = :id ORDER BY position',
    ['id' => $assignmentId]
);

$t->is(count($items) === 3, 'после перестановки все три позиции на месте');
$t->equals($firstItemId, (int) $items[1]['id'], 'переставленная позиция заняла второе место');
$t->equals($manualItemId, (int) $items[0]['id'], 'вытесненная позиция сдвинулась на первое место');
$t->equals(1, (int) $items[0]['position'], 'номера позиций идут подряд');
$t->equals(2, (int) $items[1]['position'], 'второй номер занят');
$t->equals(3, (int) $items[2]['position'], 'третий номер занят');

/*
 * Возвращаем исходный порядок: сначала задание с весом 3.
 *
 * Веса задаются вместе с позицией одной формой, поэтому при возврате порядка
 * вес нужно указать заново — иначе переставленная позиция осталась бы с
 * чужим весом, и среднее по весам поехало бы.
 */
/*
 * После перестановки items[0] — вытесненная позиция, items[1] — та, что
 * переехала вниз. Возвращаем каждую на своё место вместе с её весом.
 */
$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$teacher->post('/panel/assignments/' . $assignmentId . '/items/' . (int) $items[1]['id'], [
    '_token'   => $teacher->tokenFrom($editor) ?? '',
    'weight'   => '3',
    'position' => '1',
]);

$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$teacher->post('/panel/assignments/' . $assignmentId . '/items/' . (int) $items[0]['id'], [
    '_token'   => $teacher->tokenFrom($editor) ?? '',
    'weight'   => '1',
    'position' => '2',
]);

$items = $db->all(
    'SELECT id, position, weight, task_id FROM assignment_items WHERE assignment_id = :id ORDER BY position',
    ['id' => $assignmentId]
);

$t->equals($firstItemId, (int) $items[0]['id'], 'первым снова идёт исходное задание');
$t->equals($testId, (int) $items[0]['task_id'], 'это задание с одиночным выбором');
$t->equals(3.0, (float) $items[0]['weight'], 'после возврата порядка первым идёт задание с весом 3');
$t->equals(1.0, (float) $items[1]['weight'], 'вес второго задания не потерялся при перестановке');
$t->equals($secondTestId, (int) $items[2]['task_id'], 'третьим осталось задание без учителя');

/*
 * Публикация живёт в шапке редактора, отдельной вкладки у неё больше нет.
 *
 * Здесь у работы три позиции, но шкалы оценок ещё нет, поэтому публикация
 * обязана быть отклонена: без границ ученик получил бы процент без отметки, а
 * учитель — результат, который не во что превратить.
 */
$publishStepUrl = '/panel/assignments/' . $assignmentId . '/edit';
$publishActionUrl = '/panel/assignments/' . $assignmentId . '/edit/publish';
$publishStep = $teacher->get($publishStepUrl);

$t->is($publishStep['status'] === 200, 'редактор с публикацией открыт');
$t->contains($publishStep['body'], 'data-publish-toggle',
    'в шапке есть переключатель публикации');
$t->contains($publishStep['body'], 'параметры оценки',
    'редактор объясняет, чего не хватает для публикации');

$publishWithoutCriteria = $teacher->post($publishActionUrl, [
    '_token' => $teacher->tokenFrom($publishStep) ?? '',
]);

$t->is($publishWithoutCriteria['status'] === 302, 'публикация без шкалы оценок не роняет страницу');
$t->equals('draft', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'без шкалы оценок работа осталась черновиком');
$t->contains($teacher->get($publishStepUrl)['body'], 'параметры оценки',
    'автор видит, чего не хватает');

/*
 * Второй блокер публикации — мнение плагина.
 *
 * `publicationBlocker()` спрашивает у плагина, можно ли выпускать задание, и
 * только плагин знает, что для него значит «готово». Проверяется здесь на
 * настоящем задании без вопросов: ядро о таком не знает и спросить его
 * неоткуда, а работа с таким заданием дошла бы до ученика.
 */
$emptyTaskWork = (int) $db->insert('assignments', [
    'author_id'     => $teacherId,
    'title'         => 'Работа с незаполненным заданием',
    'status'        => 'draft',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'criteria'      => json_encode([
        ['grade' => 5, 'min_percent' => 85.0, 'color' => 'success'],
        ['grade' => 4, 'min_percent' => 70.0, 'color' => 'primary'],
        ['grade' => 3, 'min_percent' => 50.0, 'color' => 'warning'],
        ['grade' => 2, 'min_percent' => 0.0,  'color' => 'danger'],
    ], JSON_UNESCAPED_UNICODE),
    'show_results'  => 0,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$emptyTestId = (int) $db->insert('tests', [
    'author_id'  => $teacherId,
    'title'      => 'Задание без вопроса',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$db->insert('assignment_items', [
    'assignment_id' => $emptyTaskWork,
    'plugin_id'     => 'test',
    'task_kind'     => 'text',
    'task_id'       => $emptyTestId,
    'position'      => 1,
    'weight'        => 1.0,
    'max_score'     => 1.0,
    'created_at'    => date('Y-m-d H:i:s'),
]);

/*
 * Черновой вопрос — как его оставляет создание задания: он есть, но пустой.
 * Именно на таком и срабатывает второй блокер: ядро знать не может, что для
 * задания значит «заполнено», и обязано спросить плагин.
 */
$emptyQuestionId = (int) $db->insert('questions', [
    'test_id'      => $emptyTestId,
    'type'         => 'text',
    'prompt'       => '',
    'position'     => 1,
    'weight'       => 1.00,
    'is_required'  => 1,
]);

$emptyTaskEditorUrl = '/panel/assignments/' . $emptyTaskWork . '/edit';
$emptyTaskPublishUrl = '/panel/assignments/' . $emptyTaskWork . '/edit/publish';
$emptyTaskStep = $teacher->get($emptyTaskEditorUrl);

$t->is($emptyTaskStep['status'] === 200, 'редактор работы с заданием открыт');
$t->contains($emptyTaskStep['body'], 'В задании не заполнен вопрос',
    'автор видит причину, которую сообщил плагин');

$emptyTaskPublish = $teacher->post($emptyTaskPublishUrl, [
    '_token' => $teacher->tokenFrom($emptyTaskStep) ?? '',
]);

$t->is($emptyTaskPublish['status'] === 302, 'публикация с незаполненным заданием не роняет страницу');
$t->equals('draft', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $emptyTaskWork]),
    'работа с незаполненным заданием осталась черновиком');

/*
 * Тот же плагин, то же задание, вопрос добавлен — и блокер снимается. Без этой
 * второй половины проверки можно было бы закрыть публикацию навсегда и ничего
 * не заметить: отказ-то был бы всегда.
 *
 * Вопрос добавляется через форму редактора, а не вставкой в базу: блокер
 * снимается тем же путём, каким им пользуется учитель.
 */
$taskEditorForQuestion = $openTaskEditor($teacher, $emptyTaskWork, $emptyTestId);

$teacher->post('/tests/task/' . $emptyTestId, [
    '_token'      => $teacher->tokenFrom($taskEditorForQuestion) ?? '',
    'action'      => 'save_task',
    'question_id' => (string) $emptyQuestionId,
    'type'        => 'text',
    'title'       => 'Смысл жизни',
    'prompt'      => 'В чём, по-вашему, смысл жизни',
    'weight'      => '1',
    'is_required' => '1',
    'strategy'    => 'manual',
    'rows'        => '4',
]);

$t->equals(1, (int) $db->value(
    'SELECT COUNT(*) FROM questions WHERE test_id = :id',
    ['id' => $emptyTestId]
), 'вопрос остался один — форма обновила его, а не создала вторую копию');

$t->contains(
    (string) $db->value('SELECT prompt FROM questions WHERE id = :id', ['id' => $emptyQuestionId]),
    'смысл жизни',
    'текст вопроса сохранён через форму редактора'
);

$readyTaskStep = $teacher->get($emptyTaskPublishUrl);

$t->notContains($readyTaskStep['body'], 'В задании не заполнен вопрос',
    'с заполненным заданием блокера плагина нет');

$readyTaskPublish = $teacher->post($emptyTaskPublishUrl, [
    '_token' => $teacher->tokenFrom($readyTaskStep) ?? '',
]);

$t->is($readyTaskPublish['status'] === 302, 'публикация с готовым заданием проходит');
$t->equals('published', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $emptyTaskWork]),
    'работа опубликована, когда плагин не нашёл препятствий');

/*
 * Шкала задаётся на шаге «Результат».
 *
 * Оценки в форме нет: их всегда 2, 3, 4, 5, и передаются только границы — по
 * порядку, от высшей оценки к низшей.
 */
$teacher->post('/panel/assignments/' . $assignmentId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $assignmentId . '/edit/result')) ?? '',
    'criteria_mode'  => 'percent',
    'grade_mode'     => 'weighted',
    'show_results'   => '1',
    'criteria_min[]' => ['85', '70', '50', '0'],
    'color[]'          => ['success', 'primary', 'warning', 'danger'],
]);

// Теперь публикуем.
$publish = $teacher->post($publishActionUrl, [
    '_token' => $teacher->tokenFrom($teacher->get($publishStepUrl)) ?? '',
]);

$t->equals('published', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'работа с заданиями и шкалой оценок опубликована');

$t->is($db->value('SELECT published_at FROM assignments WHERE id = :id', ['id' => $assignmentId]) !== null,
    'время публикации записано');

/*
 * После публикации работа видна в дереве и значится опубликованной в самой
 * строке: состояние больше не разнесено по вкладкам, а показывается рядом с
 * названием, где его ищут.
 */
$afterPublish = $teacher->get('/panel/assignments');

$t->contains($afterPublish['body'], 'Контрольная по биологии',
    'опубликованная работа видна в дереве работ');

$t->contains($afterPublish['body'], 'опубликован',
    'у строки опубликованной работы указано её состояние');

/*
 * Отдельной публикации задания больше нет, и вместе с ней исчезла проверка
 * «на что ученику ответить». Она переехала на публикацию работы: пустое
 * задание по-прежнему не выпускается, но теперь это решает автор работы.
 *
 * Работа создаётся с заданием, в котором автор ничего не заполнил, — так
 * выглядит позиция, добавленная и забытая.
 */
$db->run("INSERT INTO assignments (author_id, title, status, grade_mode, criteria_mode, created_at, updated_at)
         VALUES (:a, 'Работа с недоделанным заданием', 'draft', 'weighted', 'percent', NOW(), NOW())",
    ['a' => $teacherId]);

$emptyWorkId = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа с недоделанным заданием' ORDER BY id DESC LIMIT 1"
);

$t->is($emptyWorkId > 0, 'работа с недоделанным заданием создана');

if ($emptyWorkId > 0) {
    $db->run(
        "INSERT INTO assignment_items (assignment_id, position, plugin_id, task_kind, task_id, weight, max_score, created_at)
         VALUES (:w, 1, 'test', 'single_choice', :t, 1.00, 1.00, NOW())",
        ['w' => $emptyWorkId, 't' => $testId]
    );

    $db->run(
        "UPDATE assignments SET criteria = :c WHERE id = :id",
        [
            'id' => $emptyWorkId,
            'c' => json_encode([['grade' => 5, 'min_percent' => 85], ['grade' => 3, 'min_percent' => 50]]),
        ]
    );

    /*
     * Позиция ссылается на задание с заполненным вопросом, поэтому пустым
     * делается другое: заведём отдельное задание, оставленное черновиком.
     */
    $db->run(
        "INSERT INTO tests (title, author_id, status, created_at, updated_at)
         VALUES ('Задание без вопроса', :a, 'draft', NOW(), NOW())",
        ['a' => $teacherId]
    );

    $emptyTaskId = (int) $db->value("SELECT id FROM tests WHERE title = 'Задание без вопроса' ORDER BY id DESC LIMIT 1");

/*
 * Задание без вопроса ставится в работу последним, и публикация обязана его
 * назвать — иначе автор не поймёт, почему работа не вышла.
 *
 * Правку результата проверяет следующая же проверка. Пока этого не было,
 * сломанный оператор проходил молча: ошибку никто не смотрел, а тест уходил
 * дальше и падал уже на другом месте, двумя экранами ниже.
 */
$db->run(
    'UPDATE assignment_items SET task_id = :t WHERE assignment_id = :w',
    ['t' => $emptyTaskId, 'w' => $emptyWorkId]
);

$t->equals(
    1,
    (int) $db->value(
        'SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :w AND task_id = :t',
        ['w' => $emptyWorkId, 't' => $emptyTaskId]
    ),
    'задание без вопроса действительно поставлено в работу'
);

    $emptyPublishStep = '/panel/assignments/' . $emptyWorkId . '/edit';
    $emptyPublishAction = '/panel/assignments/' . $emptyWorkId . '/edit/publish';

    $emptyStepPage = $teacher->get($emptyPublishStep);

    $t->is($emptyStepPage['status'] === 200, 'редактор недоделанной работы открыт');

    /*
     * Заголовка у задания больше нет, и задание без условия назвать нечем:
     * редактор называет его номером строки. Проверяется именно это — раньше
     * здесь искалось имя задания, которого в системе уже не существует.
     */
    $t->contains($emptyStepPage['body'], 'Задание «№',
        'редактор называет номером строки то, что назвать нечем');

    $teacher->post($emptyPublishAction, [
        '_token' => $teacher->tokenFrom($emptyStepPage) ?? '',
    ]);

    $t->equals('draft', (string) $db->value('SELECT status FROM assignments WHERE id = :id', ['id' => $emptyWorkId]),
        'работа с заданием без вопроса не опубликована');
}


// --------------------------------------------------------------- ученик

$t->group('Выдача работы ученику');

/*
 * Публикации недостаточно: пока автор не выдал работу лично, ученик её не
 * видит. Проверяется до того, как ученик что-то откроет, иначе проверка ниже
 * прошла бы и на старой модели, где опубликованное было видно всем.
 */
$beforeIssue = $student->get('/works');
$t->is($beforeIssue['status'] === 200, 'до выдачи ученик открывает список работ');
$t->notContains($beforeIssue['body'], 'Контрольная по биологии',
    'после публикации, но до выдачи работа ученику не видна');

// Выдаём работу своему ученику — дальше почти все проверки идут уже по ней.
$seedIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $assignmentId,
    'author_id'       => $teacherId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $seedIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$t->is($seedIssueId > 0, 'работа выдана своему ученику');

$guestWorks = $guest->get('/works');
$t->is($guestWorks['status'] === 302 && str_contains($guestWorks['location'], '/login'),
    'гость не видит список работ');

$studentWorks = $student->get('/works');
$t->is($studentWorks['status'] === 200, 'ученик открывает список работ');
$t->contains($studentWorks['body'], 'Контрольная по биологии', 'работа видна в списке ученика');

$workPage = $student->get('/work/' . $assignmentId);
$t->is($workPage['status'] === 200, 'страница работы открыта');
/*
 * Список заданий на этом экране скрыт — решение владельца от 24.10.2026.
 * Проверяется отсутствие таблицы, а не отсутствие подписи: подпись могла бы
 * переехать в шапку, а вот таблица означала бы, что список вернулся.
 * Условие первого задания теперь видно только на его собственном экране —
 * проверка на него стоит там же, ниже, в группе «Прохождение работы».
 */
$t->notContains($workPage['body'], '<table',
    'список заданий на экране перед работой скрыт');
// Подпись под заголовком работы — .muted в шапке страницы шаблона.
// Счётчик набран как «Всего N заданий» — формат задан владельцем.
$t->is(preg_match('/muted">\s*Всего\s+3\s+задания/u', $workPage['body']) === 1,
    'видно общее число заданий работы в виде «Всего N заданий»');

/*
 * Условие первого задания на этом экране больше не показывается — список
 * скрыт. Проверка начала условия перенесена на страницу самого задания
 * (группа «Прохождение работы», ниже): там условие и открывается.
 */

$workToken = $student->tokenFrom($workPage);

// Попытка ещё не начата: кнопка «Начать работу» есть.
$t->contains($workPage['body'], 'Начать работу', 'есть кнопка начала работы');
$t->is($db->value('SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id', ['id' => $assignmentId]) === 0,
    'пока никто не начинал работу');

// Права: гость к самой работе не доходит.
$guestWork = $guest->get('/work/' . $assignmentId);
$t->is($guestWork['status'] === 302, 'гость отправляется на вход вместо работы');

// --------------------------------------------------------------- начало попытки

$t->group('Начало попытки');

$noToken = $student->post('/work/' . $assignmentId . '/start', [
    '_token' => str_repeat('0', 64),
]);

$t->is($noToken['status'] === 419, 'начало работы с неверным CSRF-токеном отклонено (419)');

$start = $student->post('/work/' . $assignmentId . '/start', [
    '_token' => $workToken,
]);

$t->is($start['status'] === 302, 'попытка начата');
$t->is(preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', $start['location'], $m) === 1,
    'редирект ведёт на первое задание с токеном попытки');

$attemptId = (int) ($m[1] ?? 0);
$firstItemId = (int) ($m[2] ?? 0);
$attemptToken = (string) ($m[3] ?? '');

$t->is($attemptId > 0 && $firstItemId > 0 && $attemptToken !== '', 'в адресе есть попытка, позиция и токен');
$t->equals('in_progress', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'попытка в процессе');

$finishWithoutCsrf = $student->post('/work/' . $attemptId . '/finish', [
    't' => $attemptToken,
]);
$t->is($finishWithoutCsrf['status'] === 419, 'завершение попытки без CSRF-токена отклонено');
$finishWithInvalidCsrf = $student->post('/work/' . $attemptId . '/finish', [
    '_token' => str_repeat('0', 64),
    't'      => $attemptToken,
]);
$t->is($finishWithInvalidCsrf['status'] === 419, 'завершение попытки с неверным CSRF-токеном отклонено');
$t->equals('in_progress', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'отклонённые запросы не завершают попытку');

// Повторное нажатие «Начать» не заводит вторую попытку: ответы не должны теряться.
$editor = $student->get('/work/' . $assignmentId);

$restart = $student->post('/work/' . $assignmentId . '/start', [
    '_token' => $student->tokenFrom($editor) ?? '',
]);

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id', ['id' => $assignmentId]),
    'повторное нажатие не завело вторую попытку');

// --------------------------------------------------------------- защита попытки

$t->group('Разграничение доступа к попытке');

$itemPath = '/work/' . $attemptId . '/item/' . $firstItemId;

$t->is($student->get($itemPath)['status'] === 404, 'задание без токена недоступно');
$t->is($student->get($itemPath . '?t=' . str_repeat('0', 64))['status'] === 404, 'с чужим токеном недоступно');
$t->is($student->get('/work/' . $attemptId . '/result')['status'] === 404, 'результат без токена недоступен');
$t->is($student->get('/work/999999/item/' . $firstItemId . '?t=' . $attemptToken)['status'] === 404,
    'попытка не открывается по чужому номеру');

/*
 * Чужая попытка: второй ученик того же класса, заведённый в таблице
 * `students` тем же хелпером. Попытка защищена токеном, но токен — не
 * приглашение смотреть чужое.
 *
 * Работа ему выдана: иначе проверка была бы не о токене, а о невыданной работе,
 * и прошла бы даже с совсем открытой попыткой.
 */
$otherLogin = 'uchebnitsa';
$otherPassword = 'uchebnik2025';

$otherId = makeStudent($db, 'Кузнецова Анна Ивановна', $otherLogin, $otherPassword, $classId);

$other = $loginStudent($otherLogin, $otherPassword);

$otherSeedIssue = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $assignmentId,
    'author_id'       => $teacherId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $otherSeedIssue, 'u' => $otherId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$otherPage = $other->get('/work/' . $assignmentId);
$foreign = $other->get($itemPath . '?t=' . $attemptToken);

$t->is($foreign['status'] === 403, 'чужой ученик не открывает чужую попытку даже с её токеном');
$t->contains($otherPage['body'], 'Начать работу', 'у второго ученика своя попытка, работа ещё не начата');

// --------------------------------------------------------------- прохождение

$t->group('Прохождение работы');

// Первое задание — тест с одиночным выбором и ручной проверкой.
$itemPage = $student->get($itemPath . '?t=' . $attemptToken);

$t->is($itemPage['status'] === 200, 'экран задания открыт');
$t->contains($itemPage['body'], 'data-question-id=', 'плагин нарисовал свои вопросы');
$t->contains($itemPage['body'], 'Задание №', 'задание подписано номером, а не именем');
$t->contains(
    $itemPage['body'],
    'question__number">1</span>',
    'у первого задания в кружке его номер'
);

/*
 * Задание опознаётся началом своего условия: заголовка у него больше нет.
 *
 * Проверка перенесена сюда с экрана работы — там список заданий скрыт по
 * решению владельца, и условие показывается только на странице самого
 * задания. Ожидается именно то условие, которое осталось после правки выше,
 * — «Вопрос без заголовка»: прежнее «фотосинтезе» было верно для прежней
 * модели, где у позиции был снимок имени, и перестало быть верным, как
 * только имя убрали.
 */
$t->contains($itemPage['body'], 'Вопрос без заголовка',
    'первое задание работы видно ученику по началу условия на его экране');

$itemToken = $student->tokenFrom($itemPage);
$autoQuestionId = (int) $db->value(
    "SELECT id FROM questions WHERE test_id = :id AND type = 'single_choice' ORDER BY position LIMIT 1",
    ['id' => $testId]
);

$t->is($autoQuestionId > 0, 'у первого задания есть вопрос');

// Марка присутствия: без неё сервер не отличил бы «очистил» от «перешёл».
$saveFirst = $student->post($itemPath, [
    '_token' => $itemToken ?? '',
    't'      => $attemptToken,
    [
        'answer_present[' . $autoQuestionId . ']',
        '1',
    ],
    ['answers[' . $autoQuestionId . ']', 'b'],
    ['action', 'next'],
]);

$t->is($saveFirst['status'] === 302, 'ответ первого задания сохранён');

// Второе задание — текст с ручной проверкой.
$manualItemIdInWork = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :id AND position = 2',
    ['id' => $assignmentId]
);

$manualItemPath = '/work/' . $attemptId . '/item/' . $manualItemIdInWork;
$manualPage = $student->get($manualItemPath . '?t=' . $attemptToken);

$t->is($manualPage['status'] === 200, 'второе задание открыто');
$t->contains($manualPage['body'], 'зачем растению хлорофилл', 'видно текстовое задание');

/*
 * Кружок у вопроса — номер ЗАДАНИЯ, а не вопроса (решение владельца от
 * 24.10.2026, задача 0).
 *
 * Раньше там стоял порядковый номер вопроса внутри задания, а задание
 * содержит один вопрос — на всех страницах горела цифра «1», и ученик не
 * видел, какое задание читает. Проверяется именно второе задание: на
 * первом оба варианта совпали бы и поломку не показали.
 */
$t->contains(
    $manualPage['body'],
    'question__number">2</span>',
    'у второго задания в кружке его номер, а не единица'
);

$manualAnswerText = 'Хлорофилл улавливает свет и передаёт его энергию реакции фотосинтеза';

$saveManual = $student->post($manualItemPath, [
    '_token' => $student->tokenFrom($manualPage) ?? '',
    't'      => $attemptToken,
    [
        'answer_present[' . $manualQuestionId . ']',
        '1',
    ],
    ['answers[' . $manualQuestionId . ']', $manualAnswerText],
    ['action', 'next'],
]);

$t->is($saveManual['status'] === 302, 'текстовый ответ сохранён');

$draftRow = $db->one(
    'SELECT a.answer_text FROM test_item_answers a
       JOIN test_item_attempts t ON t.id = a.item_attempt_id
      WHERE t.assignment_attempt_id = :attempt AND a.question_id = :question',
    ['attempt' => $attemptId, 'question' => $manualQuestionId]
);

$t->is($draftRow !== null && str_contains((string) $draftRow['answer_text'], 'Хлорофилл'),
    'текстовый ответ сохранён как черновик');

// Возврат к заданию восстанавливает введённое.
$reopen = $student->get($manualItemPath . '?t=' . $attemptToken);
$t->contains($reopen['body'], 'Хлорофилл улавливает свет', 'ответ восстановлен при возврате к заданию');

// Третье задание работы — одиночный выбор, проверяемый автоматически.
$secondItemPath = '/work/' . $attemptId . '/item/' . $secondItemId;
$secondPage = $student->get($secondItemPath . '?t=' . $attemptToken);

$t->is($secondPage['status'] === 200, 'третье задание открыто');
$t->contains($secondPage['body'], 'Какой газ выделяют растения при дыхании?', 'третье задание со своим вопросом');

$secondQuestionId = (int) $db->value(
    'SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1',
    ['id' => $secondTestId]
);

$finish = $student->post($secondItemPath, [
    '_token' => $student->tokenFrom($secondPage) ?? '',
    't'      => $attemptToken,
    [
        'answer_present[' . $secondQuestionId . ']',
        '1',
    ],
    ['answers[' . $secondQuestionId . ']', 'a'],
    ['action', 'finish'],
]);

$t->is($finish['status'] === 302, 'работа завершена');
$t->contains((string) ($finish['location'] ?? ''), '/result', 'после завершения ведёт на результат');

$t->equals('finished', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'попытка помечена завершённой');

// --------------------------------------------------------------- итог

$t->group('Итог по весам');

/*
 * Первое задание весит 3 и принесло 1 балл из 1 автоматически. Второе весит 1,
 * и это текст, который учитель ещё не проверил. Третье весит 1 и принесло
 * 2 из 2 автоматически.
 *
 * Непроверенная позиция не входит в сведение, иначе один непрочитанный текст
 * обнулял бы весь итог работы. Поэтому пока 100%, а manual = 1.
 */
$t->equals(100.0, (float) $db->value('SELECT percent FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'непроверенная позиция не уменьшает итог работы');
$t->equals(1, (int) $db->value('SELECT manual FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'работа помечена как ждущая проверки учителем');

$pendingItemId = (int) $db->value(
    'SELECT item_id FROM assignment_item_results WHERE attempt_id = :a AND needs_review = 1 LIMIT 1',
    ['a' => $attemptId]
);

$t->is($pendingItemId > 0, 'видно, какая позиция ждёт проверки');
$t->equals('pending', (string) $db->value(
    'SELECT status FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $attemptId, 'i' => $pendingItemId]
), 'статус непроверенной позиции — pending');

// Ученик видит, что итог пока не окончательный.
$result = $student->get('/work/' . $attemptId . '/result?t=' . $attemptToken);

$t->is($result['status'] === 200, 'результат работы открыт');
$t->contains($result['body'], 'итог пока не окончательный', 'ученику сказано, что итог не окончательный');
$t->contains($result['body'], 'ждёт проверки', 'в таблице позиций видно ожидающее задание');
$t->contains($result['body'], 'За разные задания может быть начислено разное количество баллов', 'ученику объяснено правило подсчёта');

/*
 * Вид оценки — дуга-шкала, как показано владельцу (решение от 24.10.2026,
 * волна D): дуга с процентом, кружок оценки цветом шкалы и таблица
 * «баллы / максимум / процент».
 *
 * Дуга обязана быть задана атрибутами SVG, а не инлайновым style=: в
 * шаблонах style= запрещён проверкой правил интерфейса, и проверяется здесь
 * именно то, что дуга не обходит запрет.
 */
$t->contains($result['body'], 'class="result-gauge"', 'на странице результата есть дуга-шкала');
$t->contains($result['body'], 'stroke-dasharray="', 'дуга задана атрибутом stroke-dasharray');
$t->is(
    preg_match('/<svg[^>]*\sstyle="/u', (string) $result['body']) !== 1,
    'в SVG нет инлайнового стиля'
);
$t->contains($result['body'], 'result-gauge__percent', 'процент показан внутри дуги');
$t->contains($result['body'], 'grade-badge', 'оценка показана кружком');
$t->contains($result['body'], 'class="result-figures"', 'есть таблица «баллы / максимум / процент»');
$t->contains($result['body'], 'Максимум', 'в таблице есть максимум');

// --------------------------------------------------------------- проверка учителем

$t->group('Проверка работы учителем');

/*
 * Раздел «Просмотр результатов» убран: он дублировал «Результаты» — оба
 * отвечали на вопрос «кто что сдал». Результаты смотрятся в «Результатах».
 */
$t->is($teacher->get('/panel/results')['status'] === 404, 'старый раздел результатов убран');

$reviewPage = $teacher->get('/panel/assignments/' . $assignmentId . '/attempts/' . $attemptId);

$t->is($reviewPage['status'] === 200, 'экран проверки открыт');
$t->contains($reviewPage['body'], 'Хлорофилл улавливает свет', 'учитель видит ответ ученика');
$t->contains($reviewPage['body'], 'max_score[' . $pendingItemId . ']', 'у позиции есть поле максимума');

/*
 * Прямой адрес ответов внутри плагина.
 *
 * Экран проверки в ядре закрыт проверкой автора работы, а вот этот адрес
 * ведёт в редактор плагина и раньше отдавал ответы ученика любому учителю с
 * ролью: номера попытки и позиции идут подряд и угадываются перебором.
 */
$answersUrl = '/tests/work-item/' . $attemptId . '/' . $pendingItemId . '/answers';

$t->is(
    $teacher->get($answersUrl)['status'] === 200,
    'автор теста видит ответы ученика по прямому адресу'
);
$t->is(
    $otherTeacher->get($answersUrl)['status'] === 403,
    'другой учитель не видит ответы по прямому адресу'
);

// Проверка без CSRF не проходит.
$noCsrf = $teacher->post('/panel/assignments/' . $assignmentId . '/attempts/' . $attemptId, [
    'score[' . $pendingItemId . ']' => '1',
]);

$t->is($noCsrf['status'] === 419, 'проверка без CSRF-токена отклонена (419)');

// Учитель ставит 1 балл из 2 и комментарий.
$saved = $teacher->post('/panel/assignments/' . $assignmentId . '/attempts/' . $attemptId, [
    '_token'           => $teacher->tokenFrom($reviewPage) ?? '',
    'score[' . $pendingItemId . ']'   => '1',
    'max_score[' . $pendingItemId . ']' => '2',
    'mark[' . $pendingItemId . ']'    => 'partial',
    'comment[' . $pendingItemId . ']' => 'Хорошо, но назовите пигмент полностью.',
]);

$t->is($saved['status'] === 302, 'проверка сохранена');

$t->equals(0, (int) $db->value(
    'SELECT needs_review FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $attemptId, 'i' => $pendingItemId]
), 'после проверки позиция больше не ждёт учителя');
$t->equals(1.0, (float) $db->value(
    'SELECT score FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $attemptId, 'i' => $pendingItemId]
), 'балл учителя сохранён');
$t->contains((string) $db->value(
    'SELECT comment FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $attemptId, 'i' => $pendingItemId]
), 'пигмент полностью', 'комментарий учителя сохранён');

/*
 * Итог пересчитан по весам: (1·3 + 1·1 + 2·1) / (1·3 + 2·1 + 2·1) = 6/7 ≈ 85.71%.
 *
 * Первое задание весит 3 и принесло 1 балл из 1, второе весит 1 и принесло
 * 1 из 2 (учитель снял балл за текст), третье весит 1 и принесло 2 из 2.
 * Если бы сведение шло по количеству позиций, получилось бы 83.33% вместо
 * 85.71%.
 */
$t->near(85.71, (float) $db->value('SELECT percent FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'после проверки итог пересчитан по весам');
$t->equals(4.0, (float) $db->value('SELECT score FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'баллы работы сложены: 1 + 1 + 2');
$t->equals(5.0, (float) $db->value('SELECT max_score FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'максимум работы сложен: 1 + 2 + 2');
$t->equals(0, (int) $db->value('SELECT manual FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    'работа больше не ждёт проверки');
$t->equals(5, (int) $db->value('SELECT grade FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]),
    '85.71% по шкале автора — пятёрка');

/*
 * Оценка приходит к ученику покрашенной.
 *
 * Цвет выбирает автор на странице работы, и он же должен быть виден ученику:
 * иначе настройка была бы украшением одной страницы, а журнал с оценками
 * остался бы прежним. Проверяется именно класс, потому что значение пришло из
 * базы, и подставлять его в разметку как есть было бы способом подсунуть
 * в `class` что угодно.
 */
$coloredResult = $student->get('/work/' . $attemptId . '/result?t=' . $attemptToken);

$t->contains($coloredResult['body'], 'grade grade--success',
    'пятёрка на странице результата покрашена выбранным автором цветом');

/*
 * Среднее по количеству дало бы здесь 83.33%, то есть тоже пятёрку. Проверяем
 * именно дробь, а не отметку: равенство отметок не доказывало бы, что свод
 * идёт по весам.
 */
/*
 * Среднее по количеству позиций дало бы 83.33% — и это не то, что показано.
 * Число вычисляется прямо здесь, чтобы при споре видно было, от какой формулы
 * отступил расчёт ядра.
 */
$byCount = (1.0 / 1.0 + 1.0 / 2.0 + 2.0 / 2.0) / 3 * 100;
$t->is(round($byCount, 2) === 83.33, 'среднее по количеству дало бы 83.33%');
$t->is(round($byCount, 2) !== round((float) $db->value('SELECT percent FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]), 2),
    'ядро действительно считает по весам, а не по количеству');

// Ученик видит окончательный итог и комментарий.
$finalResult = $student->get('/work/' . $attemptId . '/result?t=' . $attemptToken);

$t->contains($finalResult['body'], '85.71%', 'ученик видит процент работы');
$t->contains($finalResult['body'], 'назовите пигмент полностью', 'ученик видит комментарий учителя');
$t->notContains($finalResult['body'], 'итог пока не окончательный', 'предупреждение о незавершённости исчезло');

// Учитель уже не может править проверенную позицию: повторная отправка её не меняет.
$reviewAgain = $teacher->get('/panel/assignments/' . $assignmentId . '/attempts/' . $attemptId);
$t->notContains($reviewAgain['body'], 'max_score[' . $pendingItemId . ']',
    'проверенная позиция больше не предлагается к правке');

$teacher->post('/panel/assignments/' . $assignmentId . '/attempts/' . $attemptId, [
    '_token'            => $teacher->tokenFrom($reviewAgain) ?? '',
    'score[' . $pendingItemId . ']'    => '2',
    'max_score[' . $pendingItemId . ']' => '2',
]);

$t->equals(1.0, (float) $db->value(
    'SELECT score FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $attemptId, 'i' => $pendingItemId]
), 'автоматически проверенный балл не перебивается ручной правкой');

/*
 * Отметка проверки сверяется со списком.
 *
 * Строка отметки приходит из браузера и попадает в результат, который видит
 * ученик. Раньше принималась любая: подделанный запрос мог записать в результат
 * что угодно — вплоть до строки, которая покажется ученику как его достижение.
 */
$t->group('Отметка проверки не берётся из запроса как есть');

$markReview = $teacher->get('/panel/assignments/' . $assignmentId . '/attempts/'
    . (int) $db->value('SELECT id FROM assignment_attempts WHERE assignment_id = :a AND student_id = :s LIMIT 1', [
        'a' => $assignmentId,
        's' => $studentId,
    ]));

$markAttemptId = 0;
preg_match('#/attempts/(\d+)#', (string) $markReview['location'], $markMatch) === 1
    ? $markAttemptId = (int) ($markMatch[1] ?? 0)
    : $markAttemptId = (int) $db->value(
        'SELECT id FROM assignment_attempts WHERE assignment_id = :a AND student_id = :s LIMIT 1',
        ['a' => $assignmentId, 's' => $studentId]
    );

$markItemId = (int) $db->value(
    'SELECT item_id FROM assignment_item_results WHERE attempt_id = :a LIMIT 1',
    ['a' => $markAttemptId]
);

if ($markAttemptId > 0 && $markItemId > 0) {
    $teacher->post('/panel/assignments/' . $assignmentId . '/attempts/' . $markAttemptId, [
        '_token'         => $teacher->tokenFrom($markReview) ?? '',
        'score[' . $markItemId . ']' => '1',
        'max_score[' . $markItemId . ']' => '2',
        'mark[' . $markItemId . ']' => 'отличная работа',
    ]);

    $storedMark = (string) $db->value(
        'SELECT status FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
        ['a' => $markAttemptId, 'i' => $markItemId]
    );

    $t->is(
        in_array($storedMark, ['correct', 'partial', 'wrong', 'skipped'], true),
        'в результат попала не строка из браузера, а отметка из списка: ' . $storedMark
    );
} else {
    $t->is(true, 'попытка для проверки отметки найдена');
}

// -------------------------------------------------------------- старые адреса

$t->group('Совместимость старых адресов');

/*
 * Решение владельца проекта: адреса /tests/{id} не удаляются, а ведут в работу,
 * где задание теперь выдаётся. Редирект постоянный, иначе браузер спрашивал
 * бы об этом при каждом заходе.
 */
$legacy = $student->get('/tests/' . $testId);

$t->is($legacy['status'] === 301, 'старый адрес теста перенаправляет постоянно (301)');
$t->is(str_contains((string) $legacy['location'], '/work/' . $assignmentId), 'редирект ведёт в эту работу');

$follow = $student->get((string) $legacy['location']);
$t->is($follow['status'] === 200, 'по старой ссылке открывается нужная работа');
$t->contains($follow['body'], 'Контрольная по биологии', 'открылась именно та работа');

// Второе задание тоже ведёт в ту же работу.
$legacySecond = $student->get('/tests/' . $secondTestId);
$t->is($legacySecond['status'] === 301, 'старый адрес второго задания тоже перенаправляет');

/*
 * Редактор задания открывается из списка позиций работы — это единственное
 * место, где задание наполняется. Отдельной страницы у него нет.
 */
$taskEditorAgain = $openTaskEditor($teacher, $assignmentId, $secondTestId);
$t->is($taskEditorAgain['status'] === 200, 'редактор задания открыт из списка позиций работы');

// Прежний адрес редактора теста удалён вместе с самим редактором.
$t->is($teacher->get('/tests/editor/' . $secondTestId)['status'] === 404,
    'прежний адрес редактора теста не отвечает ни для одного задания');

// ------------------------------------------------------- удаление без сирот

$t->group('Удаление работы не оставляет сирот');

/*
 * Таблицы плагина связаны с ядром номерами, а не внешними ключами: каскад
 * ядра до них не достаёт. Если при удалении работы или позиции плагину не
 * сказать, что они исчезли, его строки остаются навсегда — и при повторном
 * использовании того же номера позиции ученик увидел бы чужие старые ответы.
 *
 * Проверяется по базе, а не по странице: сирота видна только там.
 */
$orphanWork = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа под удаление' LIMIT 1"
);

$orphanWorkPage = $teacher->get('/panel/assignments');
$orphanCreated = $teacher->post('/panel/assignments/new/start', [
    '_token'     => $teacher->tokenFrom($orphanWorkPage) ?? '',
    'title'      => 'Работа под удаление',
    'grade_mode' => 'weighted',
]);

$orphanAssignmentId = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа под удаление' ORDER BY id DESC LIMIT 1"
);

$t->is($orphanCreated['status'] === 302 && $orphanAssignmentId > 0, 'работа под удаление создана');

$orphanItemsPage = $teacher->get('/panel/assignments/' . $orphanAssignmentId);

$teacher->post('/panel/assignments/' . $orphanAssignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($orphanItemsPage) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'title'     => 'Задание под удаление',
    'weight'    => '1',
]);

$orphanRow = $db->one(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id ORDER BY position LIMIT 1',
    ['id' => $orphanAssignmentId]
);

$orphanTaskId = (int) ($orphanRow['task_id'] ?? 0);
$t->is($orphanTaskId > 0, 'в работе под удаление есть задание');

$orphanItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :id ORDER BY position LIMIT 1',
    ['id' => $orphanAssignmentId]
);

$testPluginWasEnabled = (int) $db->value('SELECT enabled FROM plugins WHERE id = :id', ['id' => 'test']);
$db->run('UPDATE plugins SET enabled = 0 WHERE id = :id', ['id' => 'test']);

$deleteWithoutPlugin = $teacher->post('/panel/assignments/' . $orphanAssignmentId . '/delete', [
    '_token' => $teacher->tokenFrom($orphanItemsPage) ?? '',
]);

$t->is($deleteWithoutPlugin['status'] === 302, 'удаление при выключенном плагине возвращает пользователя на работу');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $orphanAssignmentId]),
    'работа сохранена, если плагин не может очистить свои данные');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE id = :id', ['id' => $orphanItemId]),
    'позиция сохранена вместе с работой при недоступном плагине');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $orphanTaskId]),
    'задание плагина не удалено при отказе очистки');

$db->run('UPDATE plugins SET enabled = :enabled WHERE id = :id', [
    'enabled' => $testPluginWasEnabled,
    'id'      => 'test',
]);

$blockedDeletePage = $teacher->get('/panel/assignments/' . $orphanAssignmentId);
$t->contains($blockedDeletePage['body'], 'Нельзя безопасно удалить задание',
    'причина отмены удаления сообщается автору');

$teacher->post('/panel/assignments/' . $orphanAssignmentId . '/delete', [
    '_token' => $teacher->tokenFrom($orphanItemsPage) ?? '',
]);

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $orphanAssignmentId]),
    'работа удалена');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE id = :id', ['id' => $orphanItemId]),
    'позиция работы удалена каскадом');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $orphanTaskId]),
    'задание плагина удалено вместе с работой');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM questions WHERE test_id = :id', ['id' => $orphanTaskId]),
    'вопросы задания удалены');

/*
 * Позиция, снятая с работы, оставляет после себя прохождения. Это отдельный
 * случай от удаления работы: работа остаётся, исчезает только позиция, и
 * плагин обязан убрать попытки по ней, а осиротевшее задание — удалить.
 *
 * Для этого заводится своя работа: основная нужна последующим проверкам, и
 * лишать её позиции здесь нельзя.
 */
$keepWorkPage = $teacher->get('/panel/assignments');
$teacher->post('/panel/assignments/new/start', [
    '_token'     => $teacher->tokenFrom($keepWorkPage) ?? '',
    'title'      => 'Работа под снятие позиции',
    'grade_mode' => 'weighted',
]);

$keepAssignmentId = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа под снятие позиции' ORDER BY id DESC LIMIT 1"
);

$teacher->post('/panel/assignments/' . $keepAssignmentId . '/tasks/new', [
    '_token'    => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $keepAssignmentId)) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'title'     => 'Задание под снятие позиции',
    'weight'    => '1',
]);

$keepRow = $db->one(
    'SELECT id, task_id FROM assignment_items WHERE assignment_id = :id ORDER BY position LIMIT 1',
    ['id' => $keepAssignmentId]
);

$keepItemId = (int) ($keepRow['id'] ?? 0);
$keepTaskId = (int) ($keepRow['task_id'] ?? 0);

$removedItem = $teacher->post(
    '/panel/assignments/' . $keepAssignmentId . '/items/' . $keepItemId . '/delete',
    ['_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $keepAssignmentId)) ?? '']
);

$t->is($removedItem['status'] === 302, 'позиция убрана из работы');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $keepAssignmentId]),
    'работа после снятия позиции осталась');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE id = :id', ['id' => $keepItemId]),
    'позиция убрана');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM test_item_attempts WHERE item_id = :id', ['id' => $keepItemId]),
    'прохождения по снятой позиции убраны плагином');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $keepTaskId]),
    'осиротевшее задание удалено: больше нигде не используется');

/*
 * Сирота, у которой не осталось позиции работы.
 *
 * Номера в test_item_attempts — обычные числа, а не внешние ключи, поэтому
 * строка переживает каскадное удаление позиции. Такое возможно, когда работа
 * снята целиком, а уведомление плагину не дошло: удалить задание ядро всё
 * равно попробует, и без отдельной очистки по номеру задания прохождение
 * осталось бы лежать. Номер теста потом свободен, и следующий тест на этом
 * номере показал бы ученику чужие ответы.
 *
 * Ситуация создаётся прямо в базе: через интерфейс она не собирается.
 */
$t->group('Сирота прохождения не переживает удаление задания');

$db->run(
    "INSERT INTO tests (author_id, title, status, created_at, updated_at)
     VALUES (:a, 'Задание с сиротой', 'draft', NOW(), NOW())",
    ['a' => $teacherId]
);

$orphanOfTaskId = (int) $db->value(
    "SELECT id FROM tests WHERE title = 'Задание с сиротой' ORDER BY id DESC LIMIT 1"
);

$t->is($orphanOfTaskId > 0, 'тест для проверки сироты создан');

$orphanWorkPage = $teacher->get('/panel/assignments');

$teacher->post('/panel/assignments/new/start', [
    '_token'     => $teacher->tokenFrom($orphanWorkPage) ?? '',
    'title'      => 'Работа под сироту',
    'grade_mode' => 'weighted',
]);

$orphanOfWorkId = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа под сироту' ORDER BY id DESC LIMIT 1"
);

/*
 * Позицию работы ставим вручную, без /tasks/new: интерфейс создаёт собственное
 * задание, а проверять надо удаление теста, который уже создан выше.
 */
$db->run(
    "INSERT INTO assignment_items
        (assignment_id, position, plugin_id, task_kind, task_id,
         weight, max_score, created_at)
VALUES (:work, 1, 'test', 'single_choice', :task,
              1.00, 1.00, NOW())",
    ['work' => $orphanOfWorkId, 'task' => $orphanOfTaskId]
);

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', ['id' => $orphanOfWorkId]),
    'в работе под сироту одна позиция на созданное задание');

$orphanOfItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :id LIMIT 1',
    ['id' => $orphanOfWorkId]
);

// Прохождение висит на позиции, которой в работах уже нет.
$db->run(
    "INSERT INTO test_item_attempts
        (assignment_attempt_id, item_id, test_id, status, started_at, score, max_score)
     VALUES (999999, 999999, :task, 'graded', NOW(), 1.00, 1.00)",
    ['task' => $orphanOfTaskId]
);

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM test_item_attempts WHERE test_id = :id', ['id' => $orphanOfTaskId]),
    'сирота прохождения создана');

// Снимаем позицию: ядро зовёт deleteItem, затем deleteTask.
$teacher->post('/panel/assignments/' . $orphanOfWorkId . '/items/' . $orphanOfItemId . '/delete', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $orphanOfWorkId)) ?? '',
]);

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $orphanOfTaskId]),
    'задание удалено');
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM test_item_attempts WHERE test_id = :id', ['id' => $orphanOfTaskId]),
    'прохождение сироты убрано вместе с заданием, а не осталось на его номере');

/*
 * Задание, на которое ссылается другая работа, удалять нельзя.
 *
 * Работы строятся из уникального набора заданий, поэтому такая ситуация —
 * аномалия. Но полагаться на правило в коде удаления нельзя: при спорах
 * одного задания чужая работа осталась бы с заданием, которого больше нет,
 * и снятая работа снесла бы ещё и чужое.
 *
 * Ситуация создаётся прямо в базе: через интерфейс такое не собрать, а
 * проверить правило надо именно на нём.
 */
// Работы создаются от имени учителя набора: иначе проверка авторства на
// маршруте удаления их отклонит, и проверялось бы не то правило.
$sharedAuthor = $teacherId;

$db->run(
    "INSERT INTO tests (author_id, title, status, created_at, updated_at)
     VALUES (:a, 'Задание на двоих', 'draft', NOW(), NOW())",
    ['a' => $sharedAuthor]
);

$sharedTaskId = (int) $db->value(
    "SELECT id FROM tests WHERE title = 'Задание на двоих' ORDER BY id DESC LIMIT 1"
);

$sharedWorkIds = [];

foreach (['Работа левая', 'Работа правая'] as $sharedTitle) {
    $db->run(
        "INSERT INTO assignments (author_id, title, status, grade_mode, criteria_mode, created_at, updated_at)
         VALUES (:a, :t, 'draft', 'weighted', 'percent', NOW(), NOW())",
        ['a' => $sharedAuthor, 't' => $sharedTitle]
    );

    $sharedWorkIds[] = (int) $db->value(
        'SELECT id FROM assignments WHERE title = :t ORDER BY id DESC LIMIT 1',
        ['t' => $sharedTitle]
    );
}

foreach ($sharedWorkIds as $sharedWorkId) {
    $db->run(
        "INSERT INTO assignment_items (assignment_id, position, plugin_id, task_kind, task_id, weight, created_at)
         VALUES (:w, 1, 'test', 'single_choice', :t, 1.00, NOW())",
        ['w' => $sharedWorkId, 't' => $sharedTaskId]
    );
}

$t->equals(2, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_items WHERE task_id = :t',
    ['t' => $sharedTaskId]
), 'задание стоит в двух работах');

// Снимаем позицию из первой работы от имени её автора.
$sharedToken = $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '';
$sharedItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :w ORDER BY position LIMIT 1',
    ['w' => $sharedWorkIds[0]]
);

$teacher->post('/panel/assignments/' . $sharedWorkIds[0] . '/items/' . $sharedItemId . '/delete', [
    '_token' => $sharedToken,
]);

$t->equals(1, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_items WHERE task_id = :t',
    ['t' => $sharedTaskId]
), 'позиция снята из первой работы');
$t->is((int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $sharedTaskId]) === 1,
    'задание, на которое ссылается вторая работа, не удалено');

// --------------------------------------------------------------- повторная попытка

$t->group('Повторная попытка');

/*
 * Сданную работу можно начать заново: номер попытки увеличивается, а прежняя
 * попытка остаётся в истории — иначе проверка учителя потеряла бы историю.
 */
$workAgain = $student->get('/work/' . $assignmentId);
  $secondStart = $student->post('/work/' . $assignmentId . '/start', [
      '_token' => $student->tokenFrom($workAgain) ?? '',
  ]);

$t->is($secondStart['status'] === 302, 'после сдачи работа открывается заново');
$t->is(preg_match('#/work/(\d+)/item/#', (string) $secondStart['location'], $m2) === 1,
    'редирект ведёт на первое задание новой попытки');

$newAttemptId = (int) ($m2[1] ?? 0);

$t->is($newAttemptId > $attemptId, 'заведена новая попытка');
$t->equals(2, (int) $db->value('SELECT attempt_no FROM assignment_attempts WHERE id = :id', ['id' => $newAttemptId]),
    'номер попытки увеличился');
$t->equals('in_progress', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $newAttemptId]),
    'новая попытка в процессе');

// Пока попытка не сдана, ученик видит именно её, а не прошлый результат.
$newToken = (string) $db->value('SELECT token FROM assignment_attempts WHERE id = :id', ['id' => $newAttemptId]);
$newItem = $student->get('/work/' . $newAttemptId . '/item/' . $firstItemId . '?t=' . $newToken);

$t->is($newItem['status'] === 200, 'новая попытка открывает задание');
$t->notContains($newItem['body'], 'назовите пигмент полностью', 'в новой попытке ответы пустые');

// --------------------------------------------------------------- выдача работы

$t->group('Кому выдана работа');

/*
 * Публикация сама по себе ничего не открывает: опубликованная работа лежит в
 * базе, но ученик её не видит, пока автор не выдаст её лично ему. Это и есть
 * смысл отказа от адресации классами: решение «кому» принимается в момент
 * выдачи, а не остаётся в описании работы навсегда.
 *
 * Проверка «до выдачи» уже прошла выше, до первой выдачи; здесь — обратная
 * сторона: выданное не достаётся никому, кого не отметили.
 */
$ownClass = $student->get('/works');
$t->contains($ownClass['body'], 'Контрольная по биологии', 'выданная работа остаётся видна своему ученику');

/*
 * Не выданная работа никому не доступна.
 *
 * Второй ученик получил работу выше — ради проверки чужой попытки, — поэтому
 * проверка «невидимости» идёт на нём же, но до выдачи: там она уже прошла, а
 * здесь важно другое — что прямой POST на старт не обходит выдачу.
 */
$foreignWorks = $other->get('/works');

$foreignAttemptsBefore = (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id AND student_id = :u',
    ['id' => $assignmentId, 'u' => $otherId]
);

$foreignStart = $other->post('/work/' . $assignmentId . '/start', [
    '_token' => $other->tokenFrom($foreignWorks) ?? '',
]);

$t->is($foreignStart['status'] === 302, 'выданная работа запускается прямым POST');

/*
 * Класс для мастера выдачи: второй ученик нужен именно как «чужой класс, но
 * получивший работу», поэтому класс для него заводится здесь, а не берётся
 * тот же, что у работы.
 */
$otherClassName = '9-Б';

$otherClass = $classes->findByName($otherClassName);

if ($otherClass === null) {
    $otherClassId = $classes->create(['name' => $otherClassName, 'title' => 'Чужой класс', 'year' => 2026]);
} else {
    $otherClassId = (int) $otherClass['id'];
}

$t->is($otherClassId > 0, 'класс для выдачи существует');

// Второй ученик числится в этом классе: мастер показывает учеников классами.
$students->addToClass($otherClassId, $otherId);

$t->is($students->isInClass($otherClassId, $otherId), 'второй ученик числится в классе для выдачи');

// Учитель видит работу всегда: он за неё отвечает, выдавал он её или нет.
$teacherWorks = $teacher->get('/works');
$t->contains($teacherWorks['body'], 'Контрольная по биологии', 'учитель видит свою работу без выдачи');

/*
 * Истёкший срок закрывает работу, не удаляя её.
 *
 * Проверяется именно тем, что работа пропала у ученика, а не тем, что запись
 * удалилась: автор должен видеть в результатах, что выдача была и кто её
 * проходил, даже когда доступ закрыт.
 */
$db->run(
    'UPDATE assignment_issues SET available_until = :u WHERE id = :i',
    ['u' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'i' => $seedIssueId]
);

$t->is($student->get('/work/' . $assignmentId)['status'] === 404, 'после срока работа закрывается');

// NULL вместо границы — «всегда».
$db->run('UPDATE assignment_issues SET available_until = NULL WHERE id = :i', ['i' => $seedIssueId]);

$t->is($student->get('/work/' . $assignmentId)['status'] === 200, 'без срока работа доступна снова');

/*
 * Мастер выдачи: четыре шага подряд, выдача второму ученику.
 *
 * Шаги проходятся именно так, как по ним пойдёт человек: первый отправляется
 * формой, остальные — адресом. Проверяется и что мастер отдаёт ученика, и что
 * работа после выдачи действительно появилась у него.
 */
$issueStart = $teacher->get('/panel/issues/new');

$t->is($issueStart['status'] === 200, 'первый шаг выдачи открыт');
$t->contains($issueStart['body'], '1. Работа', 'мастер показывает, где находится человек');

/*
 * Переход «Выдать» из обзора работы приходит адресом с её номером, и работа
 * уже отмечена: человек приходит сюда зная, какую работу выдаёт, и искать её
 * заново в дереве незачем.
 */
$issueStartChosen = $teacher->get('/panel/issues/new?assignment=' . $assignmentId);

$t->is($issueStartChosen['status'] === 200, 'мастер открыт с номером работы');

/*
 * Отметка ищется внутри самого тега, а не его хвостом.
 *
 * Прежняя проверка требовала, чтобы `checked` был последним атрибутом, и
 * рассыпалась от добавления `required` к первому переключателю группы: смысл
 * проверки — «работа отмечена», а не «checked стоит последним».
 */
$chosenWorkTag = preg_match(
    '#<input[^>]*name="assignment"[^>]*value="' . $assignmentId . '"[^>]*>#',
    $issueStartChosen['body'],
    $chosenMatch
) ? $chosenMatch[0] : '';

$t->is(
    $chosenWorkTag !== '' && preg_match('#\schecked(\s|=|>|$)#', $chosenWorkTag) === 1,
    'работа из обзора отмечена заранее'
);

// Черновики в мастер не попадают: выдать неопубликованную работу нельзя.
$draftWorkId = (int) $db->value("SELECT id FROM assignments WHERE status = 'draft' ORDER BY id DESC LIMIT 1");

if ($draftWorkId > 0) {
    $t->notContains($issueStartChosen['body'], 'value="' . $draftWorkId . '"',
        'черновик не предлагается к выдаче');
}

$chosen = $teacher->post('/panel/issues/start', [
    '_token'     => $teacher->tokenFrom($issueStart) ?? '',
    'assignment' => $assignmentId,
]);

$t->is($chosen['status'] === 302, 'выбор работа принят');
$t->contains((string) $chosen['location'], '/classes', 'мастер ведёт к выбору классов');

$classesStep = $teacher->get('/panel/issues/' . $assignmentId . '/classes');

$t->is($classesStep['status'] === 200, 'второй шаг выдачи открыт');
$t->contains($classesStep['body'], '2. Классы', 'на втором шаге подсвечен текущий');
$t->contains($classesStep['body'], 'name="classes[]"', 'классы отмечаются флажками');
$t->notContains($classesStep['body'], 'name="classes" ', 'у поля нет значения по умолчанию');

/*
 * Шаги «классы» и «ученики» отправляются адресом, и отмеченные приходят
 * массивом: `classes[]=5&classes[]=6`. Ниже проверяется ровно то, что
 * присылает браузер с этой формы.
 *
 * Проверять это обязательно: queryParam() массив не возвращает — отдаёт
 * значение по умолчанию, — и отмеченные молча исчезают. Страница при этом
 * выглядит исправной и даже отвечает 200: на шаге классов это просто пустой
 * список, из-за которого дальше не пройти. Проверки ниже с `classes=5`
 * такой случай не видят: там в адресе строка, а не флажки.
 */
$byForm = $teacher->get(
    '/panel/issues/' . $assignmentId . '/students?classes%5B%5D=' . $classId . '&classes%5B%5D=' . $otherClassId
);

$t->is($byForm['status'] === 200, 'третий шаг открыт по форме с отмеченными классами');
$t->notContains($byForm['body'], 'Выберите хотя бы один класс', 'классы, отмеченные флажками, доехали до сервера');
$t->contains($byForm['body'], 'name="students[]"', 'третий шаг показывает флажки учеников');

$periodByForm = $teacher->get(
    '/panel/issues/' . $assignmentId . '/period?classes%5B%5D=' . $otherClassId
    . '&students%5B%5D=' . $otherId
);

$t->is($periodByForm['status'] === 200, 'шаг срока открыт по форме с отмеченными учениками');
$t->notContains($periodByForm['body'], 'Отметьте хотя бы одного ученика', 'ученики, отмеченные флажками, доехали до сервера');
$t->contains($periodByForm['body'], '4. Срок', 'форма срока показана');

/*
 * Возврат на шаг классов по адресу должен вернуть отметки: иначе «назад»
 * заставляет отмечать всё заново и выглядит как ошибка шага, а не адреса.
 */
$classesBack = $teacher->get(
    '/panel/issues/' . $assignmentId . '/classes?classes%5B%5D=' . $otherClassId
);

$t->is(
    preg_match('/name="classes\[\]" value="' . $otherClassId . '"\s+checked/', $classesBack['body']) === 1,
    'возврат на шаг классов сохраняет отметку'
);

$studentsStep = $teacher->get(
    '/panel/issues/' . $assignmentId . '/students?classes=' . $otherClassId
);

$t->is($studentsStep['status'] === 200, 'третий шаг выдачи открыт');
$t->contains($studentsStep['body'], 'Дальше: срок', 'на шаге учеников есть переход к сроку');
$t->contains($studentsStep['body'], '3. Ученики', 'на третьем шаге подсвечен текущий');
$t->contains($studentsStep['body'], 'Выбрать всех', 'есть галочка на всех сразу');
$t->contains($studentsStep['body'], 'method="get"', 'шаг учеников отправляется адресом, а не сохраняет');

/*
 * Галочка «Выбрать всех» — общий скрипт `select-all.js`, тот же, что на списке
 * класса. Проверяется не сам скрипт (его нечем проверить без браузера), а
 * разметка: без `data-select-all` и `data-select-item` скрипт не найдёт ни
 * одного флажка и страница останется без работающей галочки — тихо.
 */
$t->contains($studentsStep['body'], 'data-select-all-form', 'форма помечена для скрипта выбора');
$t->contains($studentsStep['body'], 'data-select-all', 'есть главная галочка');
$t->contains($studentsStep['body'], 'data-select-item', 'ученики помечены как элементы выбора');
$t->contains($studentsStep['body'], 'assets/js/select-all.js', 'подключён скрипт выбора всех');

// Отметки сняты по умолчанию: отмеченные сразу все опаснее пустого списка.
$t->is(
    substr_count($studentsStep['body'], 'checked') === 0,
    'ни один ученик не отмечен заранее'
);

/*
 * Форма шага срока одноразовая: каждая отправка получает свою страницу и свой
 * пропуск. Иначе вторая проверка подряд отказывала бы по пропуску, а не по
 * своему поводу, и проверка дат проходила бы, ни разу не дойдя до дат.
 */
$issueForm = static function (
    TestClient $client,
    int $assignmentId,
    int $classId,
    array $studentIds
): array {
    $query = 'classes%5B%5D=' . $classId;

    foreach ($studentIds as $studentId) {
        $query .= '&students%5B%5D=' . $studentId;
    }

    $page = $client->get('/panel/issues/' . $assignmentId . '/period?' . $query);

    $fields = [
        '_token'     => $client->tokenFrom($page) ?? '',
        'once_issue' => $client->onceFrom($page, 'issue') ?? '',
        ['classes[]', (string) $classId],
    ];

    foreach ($studentIds as $studentId) {
        $fields[] = ['students[]', (string) $studentId];
    }

    return $fields;
};
/*
 * Шаг срока открывается адресом (GET), а сохраняется POST'ом на тот же
 * адрес. Проверяется и то и другое: GET, который сохранил бы выдачу, отдал
 * бы автору лишнюю кнопку в закладках, а POST, который просто показал бы
 * шаг, тихо ничего не выдал бы.
 */
$periodStep = $teacher->get(
    '/panel/issues/' . $assignmentId . '/period'
    . '?classes=' . $otherClassId . '&students=' . $otherId
);

$t->is($periodStep['status'] === 200, 'четвёртый шаг выдачи открыт');
$t->contains($periodStep['body'], '4. Срок', 'на четвёртом шаге подсвечен текущий');
$t->contains($periodStep['body'], 'Всегда', 'на шаге срока есть выбор бессрочного доступа');

/*
 * Срок по умолчанию — неделя, а «всегда» надо выбрать специально: работа,
 * открытая навсегда, засоряет список ученика следующими годами.
 *
 * Отмеченная кнопка в форме и значение по умолчанию в коде обязаны совпадать:
 * расходились они однажды — форма показывала «сегодня», а сохранялась неделя,
 * и человек отдавал работу на неделю, думая, что на сегодня.
 */
preg_match('#<input type="radio" name="period" value="(\w+)" checked>#', $periodStep['body'], $periodMatch);

$t->equals('week', $periodMatch[1] ?? '', 'по умолчанию отмечена неделя');

// POST без поля срока сохраняет неделю — ту, что помечена в форме.
$stored = $teacher->post('/panel/issues/' . $assignmentId . '/period', [
    '_token'    => $teacher->tokenFrom($periodStep) ?? '',
    'once_issue' => $teacher->onceFrom($periodStep, 'issue') ?? '',
    'classes'   => [(string) $otherClassId],
    'students'  => [(string) $otherId],
]);

$t->is($stored['status'] === 302, 'выдача сохранена');
$t->contains((string) $stored['location'], '/panel/issues/', 'после выдачи открывается сводка');

$defaultIssueId = (int) $db->value('SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a', ['a' => $assignmentId]);

$t->is((int) $db->value(
    'SELECT DATEDIFF(available_until, available_from) FROM assignment_issues WHERE id = :i',
    ['i' => $defaultIssueId]
) === 6, 'без явного срока сохранена неделя — как и отмечено в форме');

/*
 * Считается созданная мастером выдача, а не все выдачи работы: второму ученику
 * работа уже выдавалась выше ради проверки чужой попытки, и «две записи» здесь
 * было бы верным результатом, который ничего не проверял бы.
 */
$masterIssueId = (int) ($db->value('SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a', ['a' => $assignmentId]) ?? 0);

$t->is($masterIssueId > 0, 'мастер создал выдачу');

$t->equals(1, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_issue_students WHERE issue_id = :i',
    ['i' => $masterIssueId]
), 'в выдаче мастера ровно один отмеченный ученик');

$t->equals($otherId, (int) $db->value(
    'SELECT student_id FROM assignment_issue_students WHERE issue_id = :i',
    ['i' => $masterIssueId]
), 'выдан именно тот, кого отметили');

$t->is($db->value('SELECT COUNT(*) FROM assignment_issue_students WHERE issue_id = :i AND class_id = :c',
    ['i' => $masterIssueId, 'c' => $otherClassId]) === 1,
    'в выдаче сохранён класс, в котором ученик состоял на момент выдачи');

$t->contains($other->get('/works')['body'], 'Контрольная по биологии',
    'после мастера работа появилась у второго ученика');

/*
 * Выдать можно только тому, кто числится в выбранных классах.
 *
 * Номера учеников приходят из запроса, поэтому проверяется подделка формы:
 * отмеченным ставится ученик из другого класса, которого на шаге не было.
 * Смысл в том, что такой номер обязан быть отброшен и не попасть в выдачу —
 * иначе работу можно выдать кому угодно, просто дописав номер в форму.
 *
 * Токен берётся с настоящей формы шага срока, а не с пустого адреса: без
 * отмеченных учеников шаг только переадресует назад и формы не отдаёт.
 */
$periodForm = $teacher->get(
    '/panel/issues/' . $assignmentId . '/period?classes%5B%5D=' . $otherClassId
    . '&students%5B%5D=' . $otherId
);

$strangerToken = $teacher->tokenFrom($periodForm) ?? '';

$t->is($strangerToken !== null && $strangerToken !== '', 'форма шага срока отдаёт токен для подделки запроса');

/*
 * Повторяющиеся поля отправляются явными парами `[имя[], значение]`: иначе тестовый
 * клиент соберёт `students=22&students=21`, а PHP из повторов оставит в $_POST
 * только последнее значение — и подделка формы проверяла бы не то.
 */
$mixed = $teacher->post(
    '/panel/issues/' . $assignmentId . '/period',
    $issueForm($teacher, $assignmentId, $otherClassId, [$otherId, $studentId])
        + ['period' => 'week']
);

$mixedIssueId = (int) ($db->value('SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a', ['a' => $assignmentId]) ?? 0);

$t->is($mixed['status'] === 302, 'выдача с посторонним учеником создана для своих');
$t->is($mixedIssueId > $masterIssueId, 'свой ученик получил новую выдачу');
$t->is($mixedIssueId > $masterIssueId, 'свой ученик получил новую выдачу');
$t->equals(1, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_issue_students WHERE issue_id = :i',
    ['i' => $mixedIssueId]
), 'посторонний в выдачу не попал');
$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_issue_students WHERE issue_id = :i AND student_id = :s',
    ['i' => $mixedIssueId, 's' => $studentId]
), 'чужой класс не выдан ни под каким видом');
$t->contains((string) $other->get('/works')['body'], 'Контрольная по биологии',
    'свой ученик работу получил');

/*
 * Если из отмеченных не подошёл никто, выдачи быть не должно вовсе: сообщение
 * об отказе и возврат на шаг учеников, где список ещё раз покажет, кто в этих
 * классах состоит.
 */
$onlyStranger = $teacher->post(
    '/panel/issues/' . $assignmentId . '/period',
    $issueForm($teacher, $assignmentId, $otherClassId, [$studentId])
        + ['period' => 'week']
);

$t->is($onlyStranger['status'] === 302, 'отказ в выдаче перенаправляет, а не падает');
$t->contains((string) $onlyStranger['location'], '/students', 'отказ возвращает на шаг учеников');
$t->equals($mixedIssueId, (int) ($db->value(
    'SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $assignmentId]
) ?? 0), 'выдачи, в которой нет ни одного своего ученика, не создано');

/*
 * Срок задаётся датами, и даты проверяются: мусор в поле ушёл бы в базу и
 * уронил бы страницу ошибкой, а пустая дата сделала бы работу бессрочной.
 */
$beforeDates = (int) ($db->value('SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a', ['a' => $assignmentId]) ?? 0);

foreach ([
    'мусор в датах'         => ['date_from' => '31.12.2026', 'date_until' => '2026-12-31'],
    'пустая дата'           => ['date_from' => '', 'date_until' => ''],
    'несуществующий день'   => ['date_from' => '2026-02-31', 'date_until' => '2026-03-10'],
    'срок кончается раньше' => ['date_from' => '2026-12-31', 'date_until' => '2026-12-01'],
] as $why => $dates) {
    $attempt = $teacher->post(
        '/panel/issues/' . $assignmentId . '/period',
        $issueForm($teacher, $assignmentId, $otherClassId, [$otherId])
            + ['period' => 'dates'] + $dates
    );

    $t->is($attempt['status'] === 302, 'плохие даты не роняют страницу: ' . $why);
    $t->equals($beforeDates, (int) ($db->value(
        'SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a',
        ['a' => $assignmentId]
    ) ?? 0), 'выдачи с плохими датами не создано: ' . $why);
}

// Хорошие даты проходят — проверка не должна отказывать всем подряд.
$goodDates = $teacher->post('/panel/issues/' . $assignmentId . '/period', $issueForm(
    $teacher,
    $assignmentId,
    $otherClassId,
    [$otherId]
) + [
    'period' => 'dates',
    'date_from' => '2026-03-01',
    'date_until' => '2026-03-10',
]);

$datedIssueId = (int) ($db->value('SELECT MAX(id) FROM assignment_issues WHERE assignment_id = :a', ['a' => $assignmentId]) ?? 0);

$t->is($goodDates['status'] === 302, 'корректные даты сохраняют выдачу');
$t->is($datedIssueId > $beforeDates, 'хорошие даты создали новую выдачу');
$t->equals('2026-03-01 00:00:00', (string) $db->value(
    'SELECT available_from FROM assignment_issues WHERE id = :i',
    ['i' => $datedIssueId]
), 'начало срока сохранено как задано');
$t->equals('2026-03-10 23:59:59', (string) $db->value(
    'SELECT available_until FROM assignment_issues WHERE id = :i',
    ['i' => $datedIssueId]
), 'конец срока сохранён как задано, до конца дня');

/*
 * Сводка по выдаче показывает, кому что выдано и кто сдал, — имена учеников и их
 * результаты. Список выдач отфильтрован по автору работы, и сводка обязана быть
 * отфильтрована так же: иначе её открывал любой учитель, угадав номер.
 */
$t->is($teacher->get('/panel/issues/' . $seedIssueId)['status'] === 200,
    'автор работы видит сводку по своей выдаче');
$t->is($otherTeacher->get('/panel/issues/' . $seedIssueId)['status'] === 403,
    'чужой учитель сводку по чужой выдаче не открывает');

// Первая выдача не должна была сломаться от второй.
$t->is($student->get('/work/' . $assignmentId)['status'] === 200,
    'прежняя выдача продолжает работать после новой');

// --------------------------------------------------------------- попытки работы

$t->group('Ограничение числа попыток');

/*
 * Лимит ставится учителем при выдаче и действует только на ученика:
 * учитель должен прогонять работу сколько угодно раз, иначе после правки
 * задания нельзя проверить, что баллы сошлись.
 *
 * Число живёт в выдаче, а не в работе: одна работа выдаётся много раз и
 * по-разному, и лимит у работы менял бы его у всех выдач разом. Здесь он
 * ставится прямо в выдаче — форма выдачи проверяется отдельно, ниже.
 */
$editor = $teacher->get('/panel/assignments/' . $assignmentId);

$t->notContains($editor['body'], 'name="max_attempts"',
    'на странице работы поля попыток нет: оно задаётся при выдаче');

$db->run(
    'UPDATE assignment_issues SET max_attempts = 1 WHERE assignment_id = :id',
    ['id' => $assignmentId]
);

$t->equals(1, (int) $db->value(
    'SELECT MAX(max_attempts) FROM assignment_issues WHERE assignment_id = :id',
    ['id' => $assignmentId]
), 'ограничение в одну попытку сохранено в выдаче');

$beforeSecond = $student->get('/work/' . $assignmentId);
$t->contains($beforeSecond['body'], 'попыток не осталось', 'ученику сказано, что попыток не осталось');

// Ученику вторая попытка не даётся.
$attemptsBefore = (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id AND student_id = :u',
    ['id' => $assignmentId, 'u' => $studentId]
);

$againBlocked = $student->post('/work/' . $assignmentId . '/start', [
    '_token' => $student->tokenFrom($beforeSecond) ?? '',
]);

$t->is($againBlocked['status'] === 302, 'вторая попытка отклонена');
$t->equals($attemptsBefore, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id AND student_id = :u',
    ['id' => $assignmentId, 'u' => $studentId]
), 'попытка не заведена');

$blockedPage = $student->get('/work/' . $assignmentId);
$t->contains($blockedPage['body'], 'максимальное число раз', 'ученику объяснено, почему не дают попытку');

/*
 * Учитель ограничением не связан: у него есть tests.repeat.
 *
 * Его попытка — самопроверка, а не работа ученика, поэтому она пишется в
 * колонку staff_id, а student_id остаётся пустым. Считать её по student_id
 * значило бы не найти её вовсе — или, что хуже, найти чужую.
 */
$teacherWork = $teacher->get('/work/' . $assignmentId);
$teacherStart = $teacher->post('/work/' . $assignmentId . '/start', [
    '_token' => $teacher->tokenFrom($teacherWork) ?? '',
]);

$t->is($teacherStart['status'] === 302, 'учитель может начать работу независимо от лимита');
$t->is((int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id AND staff_id = :u',
    ['id' => $assignmentId, 'u' => $teacherId]
) === 1, 'попытка учителя заведена в его колонке, а не в колонке ученика');

// Снимаем лимит, чтобы последующие проверки не зависели от него.
$db->run(
    'UPDATE assignment_issues SET max_attempts = 0 WHERE assignment_id = :id',
    ['id' => $assignmentId]
);

$t->equals(0, (int) $db->value(
    'SELECT MAX(max_attempts) FROM assignment_issues WHERE assignment_id = :id',
    ['id' => $assignmentId]
), 'ограничение снято');

$unlimited = $student->get('/work/' . $assignmentId);
$t->notContains($unlimited['body'], 'попыток не осталось', 'после снятия лимита ограничения не видно');

/*
 * Лимит читается из выдачи, под которой ученик получил работу.
 *
 * Проверяется прямо здесь, а не в отдельной группе: значение только что стояло
 * в выдаче и только что было снято. Ученик, которому работу не выдавали, лимита
 * не получает вовсе — иначе число из чужой выдачи досталось бы тому, кто её
 * не видел.
 *
 * Лицо передаётся как Actor, а не голым номером: в `students` и `users`
 * нумерация независима, и раньше «учитель №7» читал лимит «ученика №7».
 */
$assignmentsRepo = $app->get(MyEdu\Repository\AssignmentRepository::class);

$db->run(
    'UPDATE assignment_issues SET max_attempts = 2 WHERE assignment_id = :id',
    ['id' => $assignmentId]
);

$t->equals(2, $assignmentsRepo->attemptLimit($assignmentId, Actor::ofStudent($studentId)),
    'лимит берётся из выдачи, по которой ученик получил работу');

/*
 * Сотрудник лимитом не ограничен вовсе — даже если под тем же номером в
 * таблице `students` стоит человек с лимитом. Номера двух таблиц независимы,
 * и попытка сотрудника пишется в другую колонку, поэтому чужой лимит ему не
 * указка: иначе завуч не смог бы перепроверить работу после правки.
 */
$t->equals(null, $assignmentsRepo->attemptLimit($assignmentId, Actor::ofStaff($studentId)),
    'сотруднику с тем же номером, что у ученика, лимит не достаётся: он не ученик');
$t->equals(null, $assignmentsRepo->attemptLimit($assignmentId, Actor::ofStaff($otherTeacherId)),
    'постороннему сотруднику лимит не достаётся: у него нет своей выдачи');

/*
 * Ученик, которому работу не выдавали, лимита не получает: лимит живёт в
 * выдаче, а не в работе. Посторонний заводится и удаляется здесь же — он нужен
 * ровно для этой проверки и не должен остаться в классе надолго.
 */
$strangerId = makeStudent($db, 'Посторонний Ученик', 'postoronniy', 'postoronniy2025');

$t->equals(null, $assignmentsRepo->attemptLimit($assignmentId, Actor::ofStudent($strangerId)),
    'постороннему ученику лимит не достаётся: у него нет своей выдачи');

$db->run('DELETE FROM students WHERE id = :id', ['id' => $strangerId]);

// Возвращаем «без ограничения», чтобы дальнейшие проверки не зависели от него.
$db->run(
    'UPDATE assignment_issues SET max_attempts = 0 WHERE assignment_id = :id',
    ['id' => $assignmentId]
);

// ---------------------------------------------------- номера из разных таблиц

$t->group('Учитель с номером ученика не берёт его попытку');

/*
 * Регрессия на старый баг: пока владелец попытки хранился одним числом,
 * «учитель №7» читал работу «ученика №7» — совпадение номеров из двух таблиц
 * с независимой нумерацией. Теперь у попытки две колонки (student_id и
 * staff_id), и владение решает колонка, а не число.
 *
 * Правило сверяется с настоящей строкой попытки и воспроизводит то условие,
 * по которому WorkController::requireOwnAttempt() пускает к попытке. На уровне
 * HTTP это не проверяется: единственная роль с правом дойти до попытки —
 * tests.manage (см. Role::CAPABILITIES), и у любого сотрудника, до которого
 * дотягивается маршрут, право есть, то есть разницы «видит / не видит» на
 * странице не было бы. Поэтому проверяется сам механизм владения.
 */
$collisionAttempt = $db->one('SELECT * FROM assignment_attempts WHERE id = :id', ['id' => $attemptId]);

$t->is($collisionAttempt !== null, 'попытка ученика найдена');

$ownsAttempt = static function (array $attempt, Actor $actor): bool {
    return $actor->isStudent()
        ? $attempt['student_id'] !== null && (int) $attempt['student_id'] === $actor->studentId
        : $attempt['staff_id'] !== null && (int) $attempt['staff_id'] === $actor->staffId;
};

if ($collisionAttempt !== null) {
    // Номер берётся у самой попытки: это ровно то число, которое раньше
    // считалось владельцем для всех сразу.
    $sameNumber = (int) $collisionAttempt['student_id'];

    $t->is($collisionAttempt['staff_id'] === null,
        'у попытки ученика заполнена только ученическая колонка');
    $t->is($ownsAttempt($collisionAttempt, Actor::ofStudent($sameNumber)),
        'своя попытка открывается ученику с этим номером');
    $t->is(!$ownsAttempt($collisionAttempt, Actor::ofStaff($sameNumber)),
        'учителю с тем же номером попытка не своя: владение решает колонка, а не число');
}

// --------------------------------------------------------------- журнал

$t->group('Результаты');

/*
 * Журнал открыт учителю, завучу и администратору и закрыт остальным.
 *
 * Учитель получает раздел правом reports.view и видит в нём работы, которые
 * выдал сам, — круг проверяется ниже, где есть и чужая работа. Раньше раздел
 * был закрыт для учителя вовсе, а колокольчик в шапке вёл на него и давал 403.
 */
$teacherJournal = $teacher->get('/panel/journal');
$t->is($teacherJournal['status'] === 200, 'учитель открывает журнал');
$t->contains($teacherJournal['body'], 'Результаты', 'журнал называется результатами');

$studentJournal = $student->get('/panel/journal');
$t->is($studentJournal['status'] === 403, 'ученику журнал закрыт');

/*
 * Заведём завуча: у него есть class.students, и он видит журнал. Учётная
 * запись создаётся прямо здесь и удаляется в конце — иначе она осталась бы
 * в базе и ломала бы следующие наборы.
 */
$headLogin = 'zavuch';
$headPassword = 'zavuch2025';

$headRow = $db->one('SELECT id FROM users WHERE login = :login', ['login' => $headLogin]);

if ($headRow === null) {
    $users->create([
        'login'            => $headLogin,
        'email'            => null,
        'password_hash'    => password_hash($headPassword, PASSWORD_DEFAULT),
        'full_name'        => 'Завуч Проверочный',
        'is_owner'         => 0,
        'status'           => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $headRow = $db->one('SELECT id FROM users WHERE login = :login', ['login' => $headLogin]);
    $headId = (int) $headRow['id'];
    $users->setRole($headId, Role::HEAD_TEACHER);
} else {
    $headId = (int) $headRow['id'];

    $db->run(
        "UPDATE users SET password_hash = :hash, status = 'active' WHERE id = :id",
        ['hash' => password_hash($headPassword, PASSWORD_DEFAULT), 'id' => $headId]
    );

    $users->setRole($headId, Role::HEAD_TEACHER);
}

$head = $login($headLogin, $headPassword);

$journalIndex = $head->get('/panel/journal');
$t->is($journalIndex['status'] === 200, 'завуч открывает список результатов');
$t->contains($journalIndex['body'], 'Результаты', 'страница называется результатами');

/*
 * Заголовок страницы — заголовок страницы, а не подпись карточки.
 *
 * Два шаблона из-за одного класса выглядели как страницы без заголовка: у
 * результатов и у настроек главный заголовок лежал внутри карточки и был
 * подписан `card-title` — тем же классом, каким подписаны второстепенные
 * заголовки на той же странице. Смысловой ориентир для программ чтения и для
 * поиска по странице пропадал, и выглядело это как «страница без названия».
 *
 * Проверяется и сам заголовок, и его класс: `page-title` стоит над карточкой.
 */
$t->is(
    preg_match('#<h1 class="page-title">Результаты#u', (string) $journalIndex['body']) === 1,
    'заголовок результатов — заголовок страницы, а не подпись карточки'
);
$t->notContains($journalIndex['body'], '<h1 class="card-title">',
    'внутри карточек результатов нет главных заголовков');

/*
 * В журнале — выданные работы, собранные по классам.
 *
 * Числа в журнале разбиты переносами строк («1» / «из 2» / «сдано»), поэтому
 * содержательные проверки идут по схлопнутому тексту: иначе они ловили бы
 * вёрстку, а не содержание.
 *
 * Содержание проверяется на журнале учителя — автора работы: круг работ у
 * каждого теперь свой, и чужая работа не попадает ни в одну выдачу завуча.
 */
$teacherJournalOwn = $teacher->get('/panel/journal');

$t->is($teacherJournalOwn['status'] === 200, 'журнал у учителя открыт');
$t->contains($teacherJournalOwn['body'], 'Результаты', 'страница называется результатами');
$t->contains($teacherJournalOwn['body'], $className, 'в журнале у учителя есть созданный класс');
$t->contains($teacherJournalOwn['body'], 'Контрольная по биологии',
    'выданная работа видна в журнале');

/*
 * Завуч те же работы не видит: у него в журнале только его собственные
 * выдачи. Решение владельца — отдельной логики для завуча нет, своя
 * статистика у каждого, кто ведёт работы, а вся школа достаётся только
 * администратору. Раньше право `classes.manage` отдавало завучу все работы
 * школы, и журнал расходился со «Средним баллом», где он видел только свои.
 */
$t->is($journalIndex['status'] === 200, 'журнал завуча открывается');
$t->notContains($journalIndex['body'], 'Контрольная по биологии',
    'чужая работа не видна в журнале завуча');

/*
 * Название работы — ссылка на её результаты.
 *
 * Отдельная кнопка рядом была бы второй точкой входа на ту же страницу, а
 * название работы — то, что человек ищет глазами.
 */
$t->is(
    preg_match(
        '~<a href="[^"]*panel/journal/work/\d+[^"]*">\s*Контрольная по биологии\s*</a>~u',
        (string) $teacherJournalOwn['body']
    ) === 1,
    'название работы ведёт на её результаты'
);

/*
 * Настройки смотрит администратор: адрес требует права `users.manage`, и завучу
 * он не выдан. Поэтому заголовок настроек проверяется в наборе `auth`, где
 * такой клиент есть.
 */

/*
 * Внутри работы — вкладки классов и результаты.
 *
 * Раскладка таблицы: ученик, его задания квадратами, отметка и сводка
 * баллов. Квадрат показывает результат по одному заданию, а подсказка на нём —
 * ответ ученика.
 *
 * Страницу открывает учитель — автор работы. Завучу та же работа закрыта:
 * круг работ у него теперь свой, как у учителя, а чужой работы нет ни в
 * одном его выданном — право reports.view открывает раздел, но не чужие работы.
 */
$workJournal = $teacher->get('/panel/journal/work/' . $assignmentId);
$workFlat = trim(preg_replace('/\s+/', ' ', strip_tags($workJournal['body'])));

$t->is($workJournal['status'] === 200, 'журнал работы открыт автором');
$t->is(
    $head->get('/panel/journal/work/' . $assignmentId)['status'] === 403,
    'чужая работа закрыта в журнале завуча'
);

$t->contains($workJournal['body'], $className, 'вкладка с классом открыта');
$t->contains($workJournal['body'], 'Сидоров Пётр Иванович', 'в таблице есть ученик');
$t->contains($workJournal['body'], 'Отметка', 'в таблице есть отметка');
$t->is(
    preg_match_all('~class="journal-cell journal-cell--~u', (string) $workJournal['body']) === 6,
    'в строке по одному квадрату на задание, у каждого ученика своя строка'
);
$t->contains($workJournal['body'], 'Ответ: ',
    'у квадрата с баллом есть подсказка с ответом ученика');
$t->contains($workFlat, '85,71',
    'виден процент, посчитанный по весам');

/*
 * Квадраты идут в порядке редактора работы, а не в том, в каком задания видел
 * ученик. Номера в квадратах — номера позиций работы.
 */
$journalPositions = [];

if (preg_match_all('~class="journal-cell[^"]*"[^>]*>\s*(\d+)\s*<~u', (string) $workJournal['body'], $cellMatches)) {
    $journalPositions = array_map('intval', $cellMatches[1]);
}

$t->equals([1, 2, 3, 1, 2, 3], $journalPositions, 'задания в таблице идут в порядке редактора');

/*
 * Журнал не должен показывать работы, которые никому не выдавали.
 *
 * Попытка ученика по чужой работе — обычное дело, когда адресатов меняют:
 * класс отработал контрольную, а потом её переназначили соседнему. Работа,
 * которой не выдавали, в журнале не появляется: попытка без выдачи ничего не
 * значит.
 *
 * Следит за этим автор работы — учитель: круг работ у него свой, и до выдачи
 * журнал отдавал бы ему страницу без единого адресата. Завучу та же работа
 * чужая вовсе — он её в журнале не увидит ни до выдачи, ни после.
 *
 * Попытка создаётся прямо в базе: HTTP-пути для этого нет — попытку заводит
 * ученик, а выдать работу чужому классу и дать ему решить её нельзя, иначе
 * проверка была бы про другое.
 */
$t->group('Журнал уважает выдачу');

$listForForeign = $teacher->get('/panel/assignments');

$teacher->post('/panel/assignments/new/start', [
    '_token'     => $teacher->tokenFrom($listForForeign) ?? '',
    'title'      => 'Работа соседнего класса',
    'grade_mode' => 'weighted',
]);

$foreignAssignmentId = (int) $db->value("SELECT id FROM assignments WHERE title = 'Работа соседнего класса' LIMIT 1");

$t->is($foreignAssignmentId > 0, 'работа соседнего класса создана');

$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $foreignAssignmentId]);

$journalBeforeIssue = $teacher->get('/panel/journal');

$t->is($journalBeforeIssue['status'] === 200, 'журнал открыт до выдачи');
$t->notContains($journalBeforeIssue['body'], 'Работа соседнего класса',
    'работа без выдачи и без попыток в журнал не попадает');

/*
 * Обратная проверка: та же работа, выданная этому ученику, обязана появиться.
 * Иначе проверка выше прошла бы и при фильтре, который отбрасывает всё подряд.
 */
$journalIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $foreignAssignmentId,
    'author_id'       => $teacherId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $journalIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$journalAfterIssue = $teacher->get('/panel/journal');

$t->contains($journalAfterIssue['body'], 'Работа соседнего класса',
    'после выдачи работа появилась в журнале');
$t->is(
    $teacher->get('/panel/journal/work/' . $foreignAssignmentId)['status'] === 200,
    'у выданной работы есть своя страница журнала'
);
$t->is(
    $teacher->get('/panel/journal/work/999999')['status'] === 404,
    'несуществующая работа даёт 404'
);

/*
 * Попытка создаётся прямо в базе: HTTP-пути для этого нет — ученик не может
 * выполнить невыданную работу. Она нужна, чтобы проверить вторую половину
 * правила: журнал уважает выдачу, но не перестаёт уважать результаты.
 *
 * Вставка идёт после проверок на выдачу, а не до них: с попыткой работа уже
 * видна по ней самой, и проверка «после выдачи появилась» ничего бы не
 * доказала.
 */
$db->run(
    "INSERT INTO assignment_attempts
        (assignment_id, student_id, token, attempt_no, status, manual, percent, grade, finished_at, started_at)
     VALUES (:a, :u, :tok, 1, 'finished', 0, 100, 5, NOW(), NOW())",
    ['a' => $foreignAssignmentId, 'u' => $studentId, 'tok' => 'журнал-чужой-класс']
);

$t->is((int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id',
    ['id' => $foreignAssignmentId]
) === 1, 'попытка невыданной работы создана');

/*
 * Отзыв выдачи на работе, у которой есть результаты: журнал обязан её
 * сохранить. Раньше эта проверка роняла страницу в 404, потому что журнал
 * читал только выдачи, а отзыв их удаляет.
 */
$recallForeignPage = $teacher->get('/panel/assignments/' . $foreignAssignmentId . '/recall');

$t->is($recallForeignPage['status'] === 200, 'страница подтверждения отзыва открывается');

$t->contains(
    $recallForeignPage['body'],
    'Попыток (сохранятся)',
    'подтверждение честно обещает сохранить попытки — ниже проверяем, что так и есть'
);

$recallForeign = $teacher->post('/panel/assignments/' . $foreignAssignmentId . '/recall', [
    '_token' => $teacher->tokenFrom($recallForeignPage) ?? '',
]);

$t->is($recallForeign['status'] === 302, 'отзыв выдачи принят');

$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :id',
    ['id' => $foreignAssignmentId]
), 'выдачи после отзыва нет');

$t->contains(
    $teacher->get('/panel/journal')['body'],
    'Работа соседнего класса',
    'работа с результатами осталась в журнале после отзыва выдачи'
);

$t->is(
    $teacher->get('/panel/journal/work/' . $foreignAssignmentId)['status'] === 200,
    'страница результатов такой работы открывается'
);

/*
 * Учитель видит только работы, которые выдал сам.
 *
 * Работу завуча заводит сам завуч и выдаёт тому же ученику — иначе проверка
 * ниже прошла бы и при фильтре, который просто отбрасывает невыданные работы.
 * Раньше такая работа была закрыта от учителя вместе с разделом, и проверка
 * была бы про другое: важно, что раздел открыт, но круг работ не расширился.
 */
$head->post('/panel/assignments/new/start', [
    '_token'     => $head->tokenFrom($head->get('/panel/assignments/new/start')) ?? '',
    'title'      => 'Работа завуча',
    'grade_mode' => 'weighted',
]);

$headAssignmentId = (int) $db->value("SELECT id FROM assignments WHERE title = 'Работа завуча' LIMIT 1");

$t->is($headAssignmentId > 0, 'работа завуча создана');

$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $headAssignmentId]);

$headIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $headAssignmentId,
    'author_id'       => $headId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $headIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$t->contains(
    $head->get('/panel/journal')['body'],
    'Работа завуча',
    'выданная работа завуча видна в журнале завуча'
);

$teacherJournalForeign = $teacher->get('/panel/journal');

$t->is($teacherJournalForeign['status'] === 200, 'учитель открывает журнал, где есть чужая работа');
$t->contains($teacherJournalForeign['body'], 'Работа соседнего класса',
    'работа, выданная учителем, видна ему в журнале');
$t->notContains($teacherJournalForeign['body'], 'Работа завуча',
    'чужая работа не видна в журнале учителя');

$t->is(
    $teacher->get('/panel/journal/work/' . $foreignAssignmentId)['status'] === 200,
    'своя работа учителю открыта'
);
$t->is(
    $teacher->get('/panel/journal/work/' . $headAssignmentId)['status'] === 403,
    'работа другого автора учителю закрыта'
);


/*
 * Ученик без работ виден в таблице строкой: молчаливое отсутствие строки
 * выглядело бы как «его нет в классе». Он же убирается в конце набора.
 */
$third = makeStudent($db, 'Мельников Павел Сергеевич', 'tretyuchiy', 'uchebnik2025', $classId);

/*
 * Штрафы.
 *
 * Их три, и все они попадают в одну сводку: «набрано, штраф, итого». Здесь
 * проверяются два, которые ставит человек: за сдачу после срока и ручной.
 * Штраф за лишний вариант ответа проверяется у плагина — там, где он и
 * считается.
 */
$t->group('Штрафы работы');

/*
 * Штраф за опоздание задаётся в настройках работы и снимается в момент сдачи.
 *
 * Срок берётся у выдачи: у разных выдач он разный, и «срок работы» не значил
 * бы ничего. Поэтому срок подставляется накануне: сначала ученик начинает
 * работу, потом срок отодвигается в прошлое — так и выглядит опоздание.
 */
$teacher->post('/panel/assignments/' . $assignmentId . '/edit/save', [
    '_token'       => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $assignmentId . '/edit?tab=start')) ?? '',
    'work_tab'     => 'start',
    'late_penalty' => '1',
]);

$t->equals(
    1.0,
    (float) $db->value('SELECT late_penalty FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'штраф за опоздание сохранён в настройках работы'
);

$lateAttemptId = (int) $db->value(
    'SELECT id FROM assignment_attempts
      WHERE assignment_id = :a AND student_id = :u AND status = :s
      ORDER BY id DESC LIMIT 1',
    ['a' => $assignmentId, 'u' => $studentId, 's' => 'in_progress']
);

$t->is($lateAttemptId > 0, 'есть начатая попытка, которую можно сдать с опозданием');

$db->run(
    "UPDATE assignment_issues SET available_until = DATE_SUB(NOW(), INTERVAL 1 HOUR)
      WHERE id IN (SELECT issue_id FROM assignment_issue_students WHERE student_id = :u AND class_id = :c)",
    ['u' => $studentId, 'c' => $classId]
);

$lateItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :a ORDER BY position LIMIT 1',
    ['a' => $assignmentId]
);

$lateToken = (string) $db->value('SELECT token FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]);
$lateItemPath = '/work/' . $lateAttemptId . '/item/' . $lateItemId;
$lateItemPage = $student->get($lateItemPath . '?t=' . $lateToken);

$finished = $student->post($lateItemPath, [
    '_token' => $student->tokenFrom($lateItemPage) ?? '',
    't'      => $lateToken,
    ['action', 'finish'],
]);

$t->is(
    $finished['status'] === 302,
    'работа сдана (статус ' . (int) $finished['status'] . ', адрес: '
    . (string) ($finished['location'] ?? '') . ')'
);
$t->equals(
    1.0,
    (float) $db->value('SELECT late_penalty FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]),
    'штраф за опоздание зафиксирован при сдаче'
);

/*
 * Итог считается за вычетом штрафа: набрано, штраф, итого.
 *
 * Процент работы взвешенный, поэтому сравнивать его с долей от суммы баллов
 * нельзя — сравнение было бы заведомо неверным. Проверяется то, что верно при
 * любых весах: процент не больше, чем доля набранных баллов, то есть штраф
 * его не увеличил.
 */
$lateRow = $db->one('SELECT score, max_score, penalty, percent, grade FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]) ?? [];

$t->is(
    (float) ($lateRow['percent'] ?? 0) <= round(
        (float) ($lateRow['score'] ?? 0) / max(1.0, (float) ($lateRow['max_score'] ?? 1)) * 100,
        2
    ) + 0.01,
    'процент посчитан по итогу без штрафа'
);

/*
 * Ручной штраф учителя — величина работы, а не задания.
 *
 * Учитель ставит его на странице проверки и может оставить комментарий:
 * объяснение видно и учителю, и ученику.
 */
$reviewUrl = '/panel/assignments/' . $assignmentId . '/attempts/' . $lateAttemptId;
$reviewPage = $teacher->get($reviewUrl);

$t->is($reviewPage['status'] === 200, 'страница проверки открыта');
$t->contains($reviewPage['body'], 'name="manual_penalty"', 'на странице проверки есть поле штрафа');
$t->contains($reviewPage['body'], 'name="penalty_comment"', 'на странице проверки есть поле комментария');

$teacher->post($reviewUrl, [
    '_token'          => $teacher->tokenFrom($reviewPage) ?? '',
    'penalty_form'    => '1',
    'manual_penalty'  => '2',
    'penalty_comment' => 'Работа сдана с опозданием',
]);

$t->equals(
    2.0,
    (float) $db->value('SELECT manual_penalty FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]),
    'ручной штраф сохранён'
);
$t->equals(
    'Работа сдана с опозданием',
    (string) $db->value('SELECT penalty_comment FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]),
    'комментарий к штрафу сохранён'
);
$t->equals(
    3.0,
    (float) $db->value('SELECT penalty FROM assignment_attempts WHERE id = :id', ['id' => $lateAttemptId]),
    'общий штраф сложил опоздание и ручной'
);

/*
 * В журнале штраф виден отдельным числом: «набрано, штраф, итого».
 *
 * Страницу открывает автор работы: журнал у каждого теперь свой.
 */
$journalWithPenalty = $teacher->get('/panel/journal/work/' . $assignmentId);
$journalPenaltyFlat = trim(preg_replace('/\s+/', ' ', strip_tags((string) $journalWithPenalty['body'])));

$t->contains($journalPenaltyFlat, 'штраф',
    'в журнале штраф показан отдельным числом');
$t->contains($journalPenaltyFlat, 'итого',
    'в журнале показан итог за вычетом штрафа');

/*
 * Срок возвращается: работа, выданная без срока, доступна всегда, и следующие
 * проверки набора считают её именно такой.
 */
$db->run(
    "UPDATE assignment_issues SET available_until = NULL
      WHERE id IN (SELECT issue_id FROM assignment_issue_students WHERE student_id = :u AND class_id = :c)",
    ['u' => $studentId, 'c' => $classId]
);

/*
 * Временные люди уходят каждый из своей таблицы.
 *
 * Ученик удаляется целиком: строки выдач и попытки уходят за ним по каскаду —
 * внешние ключи настроены на удаление вместе с учеником, и отдельно снимать
 * членства (таблицы class_students больше нет) или пытаться удалить его из
 * `users` (там его нет) не нужно.
 */
$db->run('DELETE FROM students WHERE id = :id', ['id' => $third]);

/*
 * Работа завуча создана ради проверки круга видимых работ и остаётся в базе
 * ссылкой на удаляемого завуча. Внешний ключ не даёт удалить человека, пока
 * работа его числится автором, — поэтому работа уходит первой, а её выдачи и
 * попытки — вместе с ней по каскаду.
 */
$db->run('DELETE FROM assignments WHERE id = :id', ['id' => $headAssignmentId]);

// Роли завуча уходят каскадом вместе с пользователем: внешний ключ на users
// настроен на удаление строки, поэтому отдельный DELETE по user_roles не нужен.
$db->run('DELETE FROM users WHERE id = :id', ['id' => $headId]);

$t->is((int) $db->value('SELECT COUNT(*) FROM users WHERE login = :a', ['a' => $headLogin]) === 0,
    'временная учётная запись завуча удалена');
$t->is((int) $db->value('SELECT COUNT(*) FROM students WHERE login = :b', ['b' => 'tretyuchiy']) === 0,
    'временный ученик журнала удалён из students, а не из users');

// --------------------------------------------------------------- мастер

$t->group('Мастер редактора работы');

/*
 * Работа собирается в три этапа, а меню этапов своё и тёмное.
 *
 * Проверяется не разметка, а три вещи, на которых мастер держится: все три
 * адреса отвечают, меню показывает шаги, и виды заданий в каталоге приходят
 * от плагинов — мастер не знает про «Тест» и не должен.
 */
$wizardWork = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Мастер: сборка работы',
]);

$t->is($wizardWork['status'] === 302, 'работа для мастера создана');

/*
 * Номер вытаскивается из адреса по шаблону, а не basename(): тот вернул бы
 * «items» — мастер после первого шага ведёт к заданиям.
 */
preg_match('#/panel/assignments/(\d+)/#', (string) ($wizardWork['location'] ?? ''), $wizardIdMatch);
$wizardId = (int) ($wizardIdMatch[1] ?? 0);

$t->is($wizardId > 0, 'у работы есть номер');
$t->is(
    str_contains((string) ($wizardWork['location'] ?? ''), '/edit/items'),
    'создание сразу открывает первый шаг с названием и ведёт к заданиям'
);

// Название задаётся на первом шаге мастера — там же, где оно и спрашивается.
$startPageForTitle = $teacher->get('/panel/assignments/' . $wizardId . '/edit/start');

$t->is(
    (string) $db->value('SELECT title FROM assignments WHERE id = :id', ['id' => $wizardId]) === 'Мастер: сборка работы',
    'название сохранено на первом шаге мастера'
);

$wizardEntry = $teacher->get('/panel/assignments/' . $wizardId . '/edit');
$t->is($wizardEntry['status'] === 200, 'вход в редактор открывает страницу');
$t->notContains($wizardEntry['body'], 'Location', 'вход в редактор не перебрасывает');

/*
 * Четыре набора полей на одной странице и одна кнопка сохранения.
 *
 * Проверяется то, из-за чего мастер и разбирали: вкладки рядом, полосы шагов
 * нет, форма на полях работы и шкалы одна, а кнопка «Сохранить» одна и стоит
 * над вкладками. Кнопок сохранения по одной на вкладку быть не должно —
 * иначе человек так и не поймёт, какая из них сохраняет то, что он правил.
 */
$t->contains($wizardEntry['body'], 'id="work-editor-form"', 'у редактора есть общая форма');
$t->equals(1, substr_count($wizardEntry['body'], 'id="work-editor-form"'),
    'общая форма ровно одна');
$t->equals(1, substr_count($wizardEntry['body'], 'form="work-editor-form"'),
    'кнопка сохранения одна и вне формы, но привязана к ней');
$t->equals(0, preg_match_all('/<button type="submit"[^>]*>\s*Сохранить\s*</u', $wizardEntry['body']) - 1,
    'других кнопок «Сохранить» на странице нет');
$t->contains($wizardEntry['body'], 'assets/js/work-tabs.js', 'переключение вкладок подключено');
$t->contains($wizardEntry['body'], 'class="work-editor-form"',
    'форма с полями вкладок не вложена в доску заданий');

/*
 * Публикации больше нет отдельной вкладкой: она переехала в шапку.
 *
 * Проверяется и то, что вкладка исчезла, и то, что вместо неё есть
 * переключатель: иначе публикация была бы недостижима вовсе.
 */
$t->notContains($wizardEntry['body'], 'work-pane-publish',
    'отдельной вкладки публикации больше нет');
$t->contains($wizardEntry['body'], 'data-publish-toggle',
    'в шапке есть переключатель публикации');
$t->contains($wizardEntry['body'], 'id="publish-blockers"',
    'препятствия публикации показываются в окне рядом с переключателем');

/*
 * Доска заданий — вне общей формы, а её вкладки — соседи панелей.
 *
 * Вложенная форма в HTML недопустима: браузер закрывает внешнюю и перестаёт
 * отправлять её вообще, а порядок заданий перестаёт сохраняться. Проверяется
 * поэтому не класс, а сама граница: форма закрывается до того, как начинается
 * панель заданий.
 */
$wizardFormAt = strpos($wizardEntry['body'], 'id="work-editor-form"');
$wizardItemsAt = strpos($wizardEntry['body'], 'id="work-pane-items"');
$wizardFormEndAt = $wizardFormAt === false ? false : strpos($wizardEntry['body'], '</form>', $wizardFormAt);
$wizardFormInner = $wizardFormAt === false || $wizardFormEndAt === false
    ? ''
    : substr($wizardEntry['body'], $wizardFormAt, $wizardFormEndAt - $wizardFormAt);

$t->is($wizardFormAt !== false && $wizardItemsAt !== false && $wizardFormEndAt !== false
    && $wizardFormEndAt < $wizardItemsAt,
    'общая форма закрывается до панели заданий: доска не вложена в неё');
$t->notContains($wizardFormInner, 'work-pane-items',
    'панель заданий не оказалась внутри общей формы');
$t->contains($wizardFormInner, 'work-pane-start',
    'начальная страница лежит в общей форме');
$t->contains($wizardFormInner, 'work-pane-result',
    'результат лежит в общей форме');

/*
 * Вкладки — настоящие ссылки на эту же страницу.
 *
 * Без скриптов переключение должно работать само: щёлкнул по вкладке — пришёл
 * на `?tab=` и увидел нужную вкладку открытой. Поэтому проверяется не наличие
 * классов, которые ставит скрипт, а адрес и то, что сервер открывает нужную
 * панель.
 */
$t->is(
    preg_match('~data-work-tab="result"\s+role="tab"~su', $wizardEntry['body']) === 1
        || preg_match('~data-work-tab="result"~u', $wizardEntry['body']) === 1,
    'у вкладок есть ключ переключения'
);

/*
 * Вкладок у работы три: настройки, задания и результат.
 *
 * Отдельной вкладки «Публикация» больше нет: публикация — переключатель в
 * шапке. Поэтому в списке вкладок для проверки её нет, и отдельно проверяется,
 * что старой вкладки не осталось, а переключатель на месте.
 */
foreach (['items', 'result'] as $workTabKey) {
    $workTabPage = $teacher->get('/panel/assignments/' . $wizardId . '/edit?tab=' . $workTabKey);
    $workTabOn = 'work-pane-' . $workTabKey;

    $t->is($workTabPage['status'] === 200, 'вкладка ' . $workTabKey . ' открывается адресом');
    $t->is(
        preg_match('~id="' . $workTabOn . '"[^>]*class="work-tabs__pane active"~su', $workTabPage['body']) === 0
            && preg_match('~class="work-tabs__pane active"[^>]*id="' . $workTabOn . '"~su', $workTabPage['body']) === 1,
        'вкладка ' . $workTabKey . ' открыта по своему адресу'
    );
    $t->equals(
        1,
        preg_match_all('~aria-selected="true"~u', $workTabPage['body']),
        'помечена ровно одна вкладка на странице ' . $workTabKey
    );
}

$workTabUnknown = $teacher->get('/panel/assignments/' . $wizardId . '/edit?tab=such');
$t->is($workTabUnknown['status'] === 200, 'неизвестная вкладка не ломает страницу');
$t->equals(1, preg_match_all('~aria-selected="true"~u', $workTabUnknown['body']),
    'на неизвестной вкладке помечена одна вкладка, а не ни одна');

/*
 * Отказ первого шага не должен стоить остальных полей.
 *
 * Правило общее для всех форм: человек описал работу, выбрал срок,
 * и ошибка была в пустом названии. Если бы остальное стиралось, пришлось бы
 * заполнять поля заново из-за одного.
 */
$teacher->post('/panel/assignments/' . $wizardId . '/edit/start', [
    '_token'      => $teacher->tokenFrom($startPageForTitle) ?? '',
    'title'       => '',
    'description' => 'Описание, введённое до отказа',
    'time_limit'  => '45',
    'parallel'    => '2',
]);

$startAfterRefusal = $teacher->get('/panel/assignments/' . $wizardId . '/edit/start');

$t->contains($startAfterRefusal['body'], 'Название работы не может быть пустым',
    'отказ показан на том же шаге');
$t->contains($startAfterRefusal['body'], 'Описание, введённое до отказа',
    'описание вернулось в форму после отказа');
$t->is(
    preg_match('#name="time_limit"[^>]*value="45"#', $startAfterRefusal['body']) === 1,
    'срок вернулся в форму после отказа'
);
$t->notContains($startAfterRefusal['body'], 'name="max_attempts"',
    'на первом шаге поля попыток нет: число задаётся при выдаче');
$t->is(
    preg_match('#<option value="2" selected>#', $startAfterRefusal['body']) === 1,
    'параллель вернулась в форму после отказа'
);
$t->is(
    (string) $db->value('SELECT description FROM assignments WHERE id = :id', ['id' => $wizardId]) === '',
    'отказ ничего не записал в базу'
);

$stepStart = $teacher->get('/panel/assignments/' . $wizardId . '/edit/start');
$t->is($stepStart['status'] === 200, 'прежний адрес первого этапа открыт');
/*
 * Этапов больше нет: три набора полей стоят рядом и переключаются вкладками.
 *
 * Полоса шагов из двух кружков рядом с вкладками читалась третьим способом
 * выбрать, куда идти, и не отвечала на вопрос «что я сейчас правлю». Проверяется
 * поэтому не класс полосы, а то, что вкладок три, помечена ровно одна и
 * открыта именно «Настройки работы».
 *
 * Вкладки «Опубликовать» больше нет: публикация стала переключателем в шапке
 * редактора, а её препятствия показываются окном.
 */
$t->notContains($stepStart['body'], 'class="stepper"', 'на странице редактора нет полосы шагов');
$t->equals(4, preg_match_all('/data-work-tab="[a-z]+"/', $stepStart['body']),
    'у редактора четыре вкладки');
$t->equals(1, substr_count($stepStart['body'], 'aria-selected="true"'),
    'помечена ровно одна вкладка');
$t->is(
    preg_match('~aria-selected="true"[^>]*data-work-tab="start"|data-work-tab="start"[^>]*aria-selected="true"~su', $stepStart['body']) === 1,
    'на прежнем адресе первого этапа открыта вкладка «Настройки работы»'
);
$t->contains($stepStart['body'], 'Настройки работы', 'есть вкладка «Настройки работы»');
$t->contains($stepStart['body'], 'Задания', 'есть вкладка «Задания»');
$t->contains($stepStart['body'], 'Результат', 'есть вкладка «Результат»');
$t->contains($stepStart['body'], 'data-work-tab="about"', 'есть вкладка «О работе»');
$t->notContains($stepStart['body'], 'data-work-tab="publish"',
    'вкладки «Опубликовать» в редакторе больше нет');

/*
 * Сведения о работе — последняя вкладка редактора.
 *
 * Раньше они жили на отдельной странице работы с копией кнопок редактора:
 * «Собрать по шагам», «Опубликовать», «Удалить». Два одинаковых набора кнопок
 * на двух страницах требовали держать в голове, какой из них сейчас открыт.
 * Теперь сведения — вкладка, а страница работы осталась для чтения.
 */
$t->contains($stepStart['body'], 'Сведения о работе',
    'на вкладке «О работе» есть сведения о работе');

/*
 * Публикация — переключатель в шапке, и он виден там же, где название.
 *
 * Кнопки стоят в правом верхнем углу, в одной строке с заголовком: так выглядит
 * форма в шаблоне, на котором взят вид редактора.
 */
$t->contains($stepStart['body'], 'data-publish-toggle', 'в шапке редактора есть переключатель «Опубликовать»');
$t->contains($stepStart['body'], 'work-editor-head', 'шапка редактора собрана в одну строку');
$t->notContains($stepStart['body'], 'Изменить состав',
    'кнопки «Изменить состав» в редакторе больше нет');

/*
 * Одна кнопка сохраняет и работу, и шкалу.
 *
 * Отправляется одна форма с полями обеих вкладок: так и выглядит сохранение
 * редактора. Проверяется и то, что в базу попали оба набора — раньше для этого
 * нужно было сохранить первый этап, дойти до третьего и сохранить там.
 *
 * Ключи шкалы в базе — `min_percent` и `min_score`, а не `from`: это формат
 * `criteria`, и проверка идёт по нему же, а не по внутренностям разбора.
 */
$editorSavePage = $teacher->get('/panel/assignments/' . $wizardId . '/edit?tab=result');

$teacher->post('/panel/assignments/' . $wizardId . '/edit/save', [
    '_token'        => $teacher->tokenFrom($editorSavePage) ?? '',
    'work_tab'      => 'result',
    'title'         => 'Работа, собранная одной кнопкой',
    'description'   => 'Описание из общей формы',
    'time_limit'    => '40',
    'parallel'      => '3',
    'criteria_mode' => 'percent',
    'percent_min[]' => ['80', '50', '25', ''],
    'color[]'       => ['#2e7d32', '#f9a825', '#ef6c00', '#c62828'],
    'show_results'  => '1',
]);

$editorCriteria = (string) ($db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $wizardId]) ?? '');

$t->is(
    (string) $db->value('SELECT title FROM assignments WHERE id = :id', ['id' => $wizardId])
        === 'Работа, собранная одной кнопкой',
    'общая кнопка сохранила название работы'
);
$t->is(
    (string) $db->value('SELECT description FROM assignments WHERE id = :id', ['id' => $wizardId])
        === 'Описание из общей формы',
    'общая кнопка сохранила описание работы'
);
$t->is(
    (int) $db->value('SELECT show_results FROM assignments WHERE id = :id', ['id' => $wizardId]) === 1,
    'общая кнопка сохранила галочку «показывать результат» с вкладки результата'
);
$t->is(
    str_contains($editorCriteria, '"min_percent":80'),
    'общая кнопка сохранила границы шкалы с вкладки результата'
);

/*
 * Та же форма отказывает при пустом названии, и введённое не пропадает.
 *
 * Проверяется на общем сохранении, а не на отдельном первом этапе: человек
 * набирает название в одной форме вместе со шкалой, и отказ должен вернуть
 * его на ту же страницу с тем, что он набрал.
 */
$teacher->post('/panel/assignments/' . $wizardId . '/edit/save', [
    '_token'      => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $wizardId . '/edit?tab=start')) ?? '',
    'work_tab'    => 'start',
    'title'       => '',
    'description' => 'Описание, введённое до отказа общей кнопкой',
    'time_limit'  => '55',
]);

$editorAfterRefusal = $teacher->get('/panel/assignments/' . $wizardId . '/edit?tab=start');

/*
 * Общая кнопка больше не ругается на пустое название.
 *
 * Название в редакторе правят в шапке, рядом с заголовком, и в общей форме его
 * нет вовсе. Поэтому пустое название в общей форме — не ошибка, а пустое поле,
 * которого там и не должно быть: общая кнопка просто не трогает название.
 */
$t->notContains($editorAfterRefusal['body'], 'Название работы не может быть пустым',
    'общая кнопка не ругается на пустое название: названия в общей форме нет');
$t->contains($editorAfterRefusal['body'], 'Описание, введённое до отказа общей кнопкой',
    'общая кнопка сохранила описание');
$t->is(
    (string) $db->value('SELECT title FROM assignments WHERE id = :id', ['id' => $wizardId])
        === 'Работа, собранная одной кнопкой',
    'общая кнопка не тронула название работы'
);

/*
 * Границы, которые сервер не принял, возвращаются в поля.
 *
 * На общей кнопке это особенно важно: человек правил шкалу и название вместе,
 * и отказ по границам не должен заставлять набирать заново и то, и другое.
 *
 * Негодная шкала — с одинаковыми порогами у пятёрки и четвёрки: между ними
 * не остаётся диапазона. Такая шкала похожа на обычную, поэтому отказ от неё
 * и должен быть виден, а не молчалив.
 */
$editorBadScale = $teacher->post('/panel/assignments/' . $wizardId . '/edit/save', [
    '_token'        => $teacher->tokenFrom($editorSavePage) ?? '',
    'work_tab'      => 'result',
    'title'         => 'Работа, собранная одной кнопкой',
    'description'   => 'Описание из общей формы',
    'criteria_mode' => 'percent',
    'percent_min[]' => ['70', '70', '25', ''],
    'color[]'       => ['#2e7d32', '#f9a825', '#ef6c00', '#c62828'],
]);

$t->is($editorBadScale['status'] === 200, 'страница редактора возвращается сразу, без второго адреса');
$t->contains($editorBadScale['body'], 'Диапазоны не сохранены', 'общая кнопка сказала про границы');
$t->contains($editorBadScale['body'], 'value="70"', 'введённая граница осталась в поле');
$t->is(
    str_contains((string) ($db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $wizardId]) ?? ''), '"min_percent":80'),
    'негодная шкала не записалась поверх прежней'
);

$stepItems = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');
$t->is($stepItems['status'] === 200, 'этап с заданиями открыт');
$t->contains($stepItems['body'], 'Заданий пока нет', 'пустая работа объясняет, что делать дальше');

/*
 * Перетаскивание заданий вынесено в файл, потому что инлайн-скрипты запрещены
 * Content-Security-Policy. Если подключение потеряется, страница останется
 * целой, а перетаскивание исчезнет молча — так уже было с #attempt-form.
 */
$t->contains($stepItems['body'], 'assets/js/assignment-items.js', 'перетаскивание подключено файлом');
$t->is(preg_match('/<script(?![^>]*\bsrc=)/i', (string) $stepItems['body']) === 0,
    'на странице мастера нет инлайн-скриптов');

// Каталог собирается из плагинов: число видов равно числу объявленных видов,
// а не зашитое в шаблон.
$kindsByPlugin = $app->get(MyEdu\Plugin\PluginManager::class)->taskKindsByPlugin();
$catalogueKinds = array_sum(array_map('count', $kindsByPlugin));
$t->equals($catalogueKinds, substr_count($stepItems['body'], 'task-kind__label'),
    'каталог показывает каждый вид задания, объявленный плагинами');

$t->contains($stepItems['body'], 'Один вариант ответа', 'в каталоге есть вид одиночного выбора');

/*
 * Плагин с заданиями, но выключенный, редактор называет прямо.
 *
 * Виды заданий берутся у включённых плагинов, и выключенного в списке нет.
 * Догадаться, что вид задания существует и его просто забыли включить, по
 * пустому списку невозможно: выглядит так, будто вида больше нет, и человек
 * ищет, где он потерялся. Здесь проверяется и подсказка, и то, что после
 * включения плагина она исчезает.
 */
$pluginManager = $app->get(MyEdu\Plugin\PluginManager::class);

// Порядок фиксирован, а не «если был включён»: иначе проверка зависела бы от
// того, что осталось в базе от прошлого прогона, и падала бы не по делу.
if (!$pluginManager->isEnabled('variable')) {
    $pluginManager->enable('variable');
}

/*
 * Виды заданий убираются переключателем на странице плагинов, и делает это
 * каждый у себя.
 *
 * Проверяется настоящим запросом учителя: раньше выключить лишний вид мог
 * только администратор, и он гасил его сразу у всех. Теперь страница открыта
 * учителю, а настройка остаётся его личной.
 */
$pluginManager->enable('variable');
$db->run('DELETE FROM user_plugins WHERE plugin_id = :p', ['p' => 'variable']);

$pluginsPage = $teacher->get('/panel/plugins');
$withVariableOn = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$t->is($pluginsPage['status'] === 200, 'учитель видит страницу плагинов');
$t->contains($withVariableOn['body'], 'task-kind__label">Вариативная задача',
    'до выключения вид задания есть в каталоге');

$teacherToggled = $teacher->post('/panel/plugins/toggle', [
    '_token' => $teacher->tokenFrom($pluginsPage) ?? '',
    'id'     => 'variable',
    'enabled' => '0',
]);

$t->is($teacherToggled['status'] === 302, 'учитель выключил плагин у себя');

$withVariableOff = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$t->contains($withVariableOff['body'], 'Вариативная задача',
    'выключенный плагин с заданиями назван в редакторе');
$t->contains($withVariableOff['body'], 'panel/plugins',
    'в подсказке есть адрес раздела плагинов');
$t->contains($withVariableOff['body'], 'которые выключены',
    'подсказка прямо говорит, что дело в выключенном плагине');
$t->notContains($withVariableOff['body'], 'task-kind__label">Вариативная задача',
    'вид задания выключенного плагина в каталоге не показывается');

/*
 * «Тест» основной: учитель не может его выключить, даже если подделает запрос.
 * Иначе один учитель убрал бы способ составлять работу у всего сайта.
 */
$forgedTestOff = $teacher->post('/panel/plugins/toggle', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/plugins')) ?? '',
    'id'     => 'test',
    'enabled' => '0',
]);

$t->is($forgedTestOff['status'] === 302, 'подделка запроса не ломает страницу');
$t->is($pluginManager->isEnabled('test'), '«Тест» учителем не выключается');
$t->contains(
    $teacher->get('/panel/plugins')['body'],
    'включается администратором',
    'учителю сказано, что «Тест» включает администратор'
);

$teacherBackOn = $teacher->post('/panel/plugins/toggle', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/plugins')) ?? '',
    'id'     => 'variable',
    'enabled' => '1',
]);

$t->is($teacherBackOn['status'] === 302, 'учитель вернул плагин у себя');

$withVariableBack = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$t->notContains($withVariableBack['body'], 'которые выключены',
    'после включения плагина подсказка убирается');
$t->contains($withVariableBack['body'], 'task-kind__label">Вариативная задача',
    'после включения вид задания появился в каталоге');

// Добавление задания с ценой в баллах.
$addedFirst = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'     => $teacher->tokenFrom($stepItems) ?? '',
    'plugin_id'  => 'test',
    'task_kind'  => 'single_choice',
    'weight'     => '1',
    'max_score'  => '5',
]);

$t->is($addedFirst['status'] === 302, 'задание добавлено через каталог');

$itemsPage = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$addedSecond = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'     => $teacher->tokenFrom($itemsPage) ?? '',
    'plugin_id'  => 'test',
    'task_kind'  => 'text',
    'weight'     => '1',
    'max_score'  => '3',
]);

$t->is($addedSecond['status'] === 302, 'второе задание добавлено');

$wizardRows = $db->all(
    'SELECT id, position, task_kind, max_score FROM assignment_items WHERE assignment_id = :id ORDER BY position',
    ['id' => $wizardId]
);

$t->equals(2, count($wizardRows), 'в работе два задания');
$t->equals('single_choice', (string) $wizardRows[0]['task_kind'], 'вид задания сохранён');
$t->equals(5.0, (float) $wizardRows[0]['max_score'], 'цена задания задана учителем');

$t->group('Редактор задания прямо в редакторе работы');

/*
 * Раньше правка задания уводила на отдельную страницу плагина: автор правил
 * одно задание и терял из виду остальные позиции работы с их весами. Теперь
 * форма рисуется на этой же странице.
 */
$wizardTaskId = (int) ($db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id ORDER BY position LIMIT 1',
    ['id' => $wizardId]
) ?? 0);

$t->is($wizardTaskId > 0, 'у первого задания работы есть номер');

/*
 * Заданию даётся текст вопроса — иначе проверять нечего.
 *
 * Карточка без текста показывает пустую подпись и ссылки на названии не имеет
 * вовсе: адрес есть только у карандаша. Проверка «название открывает редактор на
 * месте» должна идти по настоящей ссылке, иначе она проверяла бы отсутствие.
 *
 * Дальше по ходу набора форма редактора перезаписывает текст задания своими
 * значениями, и правка ни на что последующее не влияет.
 *
 * `task_id` у позиции — это номер задания у плагина, а у «Теста» задание
 * хранится как набор вопросов, и текст берётся из первого вопроса этого
 * задания. Поэтому правится `test_id`, а не `id`.
 */
$db->run(
    'UPDATE questions SET prompt = :prompt WHERE test_id = :id',
    ['prompt' => 'Текст задания, по которому проверяется ссылка', 'id' => $wizardTaskId]
);

$itemsWithLink = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$t->contains(
    $itemsWithLink['body'],
    '/edit/items?edit=' . $wizardTaskId,
    'карточка задания ведёт в редактор на этой же странице'
);

/*
 * Название задания открывает редактор так же, как карандаш.
 *
 * Раньше это была обычная ссылка без меток перехвата, и щелчок по названию
 * перезагружал всю страницу: автор возвращался наверх доски и терял место, где
 * остановился. Рядом стоящая кнопка «Редактировать» при этом работала
 * правильно, и разница выглядела загадкой — ровно так это и пришло.
 *
 * Сама ссылка сохраняется: без скриптов и в новой вкладке адрес по-прежнему
 * открывает то же окно. Поэтому проверяются метки перехвата, а не вид и не
 * адрес.
 */
$titleLink = null;

if (preg_match('#<a[^>]*data-task-title[^>]*>#s', (string) $itemsWithLink['body'], $titleMatch)) {
    $titleLink = $titleMatch[0];
}

$t->is(
    $titleLink !== null && str_contains((string) $titleLink, 'data-editor-open'),
    'название задания открывает редактор на месте, без перезагрузки страницы'
);

$t->is(
    $titleLink !== null && str_contains((string) $titleLink, 'data-task-id='),
    'название задания знает, какое задание открывать'
);

$t->is(
    $titleLink !== null && str_contains((string) $titleLink, '?edit='),
    'ссылка у названия осталась: без скриптов и в новой вкладке адрес работает'
);

/*
 * Стили редактора текста лежат в странице всегда, а не только когда окно
 * открыто сервером.
 *
 * Окно открывают двумя способами: переходом по адресу с `?edit=` и щелчком по
 * карандашу, и второй открывает его скрипт уже после загрузки страницы. Если
 * стили приезжали только вместе с формой, то в первом случае их в документе не
 * было: редактор показывал сырые кнопки вместо своей панели, а у поля не было
 * ни отступов, ни высоты. Проверяется и присутствие, и количество: две ссылки,
 * а не четыре — вторая копия стилей означала бы двойную работу каждой страницы.
 */
$quillStyles = substr_count((string) $itemsWithLink['body'], 'assets/vendor/quill/quill.core.css');

$t->equals(1, $quillStyles, 'стили редактора подключены на странице доски заданий');

$itemsOpened = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items?edit=' . $wizardTaskId);

$t->equals(
    1,
    substr_count((string) $itemsOpened['body'], 'assets/vendor/quill/quill.core.css'),
    'при открытом окне стили редактора не подключаются второй раз'
);

/*
 * Подвал — часть содержимого страницы, а не соседняя полоса.
 *
 * Отступ от сайдбара задаётся правилом `.main`, и подвал рядом с `<main>`
 * начинался от края окна: текст в нём уезжал под тёмную панель и прадал.
 */
preg_match('#<main class="main">(.*?)</main>#s', (string) $itemsWithLink['body'], $mainBlock);

$t->is(
    str_contains((string) ($mainBlock[1] ?? ''), '<footer class="footer">'),
    'подвал лежит внутри содержимого страницы, а не под сайдбаром'
);

/*
 * Версия в подвале — ровно та, что в настройках, и написана по правилам.
 *
 * Номер сверяется с настройкой, а не с зашитым значением: иначе каждое
 * повышение версии требовало бы правки проверки, а расхождение подвала с
 * настройкой осталось бы незамеченным — номер виден человеку, и он должен
 * соответствовать тому, что стоит на сервере.
 *
 * Разрядов может быть два или три: `0.2 beta` — крупное изменение, `0.2.1` —
 * правка без изменения логики. Третий разряд не обязателен, и требовать его
 * значило бы запретить владельцу ту нумерацию, которую он выбрал.
 */
$systemVersion = (string) $app->config()->string('app.version');

$t->is(
    (bool) preg_match('#^\d+\.\d+(\.\d+)?( [a-zа-я]+)?$#iu', $systemVersion),
    'версия системы написана по правилам: разряды и пометка'
);

$t->contains(
    (string) $itemsWithLink['body'],
    'Версия ' . $systemVersion,
    'в подвале показана та версия, что задана в настройках'
);

/*
 * Управление карточкой рисуется двумя значками, и оба обязаны быть видны.
 *
 * Раньше проверялся только адрес ссылки, и кнопка без значка проходила незамеченной:
 * человек видел пустую кнопку и не понимал, что она удаляет. Значок удаления —
 * корзина, правки — карандаш; различать их надёжно можно только по фигуре
 * внутри кнопки, поэтому проверяется наличие самой фигуры, а не класса.
 */
$cardBlock = '';

if (preg_match('~<article class="work-card[^"]*".*?</article>~s', $itemsWithLink['body'], $cardMatch) === 1) {
    $cardBlock = $cardMatch[0];
}

$t->is(
    substr_count($cardBlock, '<svg') === 2,
    'у карточки два значка: правка карандашом и удаление корзиной'
);
$t->is(
    preg_match('~work-card__btn[^>]*>\s*<svg[^>]*>\s*<path d="M3 6h18"~s', $cardBlock) === 1,
    'на кнопке удаления нарисован мусорный бак'
);
$t->contains($cardBlock, 'data-confirm="Убрать задание из работы?"',
    'удаление карточки спрашивает подтверждения');

$t->notContains(
    $itemsWithLink['body'],
    '/tests/task/' . $wizardTaskId,
    'перехода на отдельную страницу редактора больше нет'
);

$inlineEditor = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items?edit=' . $wizardTaskId);

$t->is($inlineEditor['status'] === 200, 'редактор задания открывается на странице редактора работы');

$t->contains($inlineEditor['body'], 'name="action" value="save_task"',
    'форма редактора нарисована прямо в списке заданий');

$t->contains($inlineEditor['body'], 'task-list', 'список заданий остался на странице');

$t->contains(
    $inlineEditor['body'],
    'name="back" value="panel/assignments/' . $wizardId . '/edit/items?edit=' . $wizardTaskId . '"',
    'после сохранения автор вернётся в редактор работы к тому же заданию'
);

$t->contains($inlineEditor['body'], 'assets/js/editor.js',
    'встроенный редактор подключает скрипт выбора полей');

/*
 * Редактор открыт над тем заданием, которое правят, а не над всей доской.
 *
 * Проверяется принадлежность: карточка правящегося задания помечена на
 * доске, а форма редактора говорит, какой именно позиции она касается.
 * Иначе автор возвращался бы к перечню и искал, где остановился. Работа из
 * двух заданий — как раз тот случай, где «где-то на странице» и «своё
 * задание» различаются.
 */
$wizardItemId = (int) ($wizardRows[0]['id'] ?? 0);
$cardPos = strpos($inlineEditor['body'], 'data-item-id="' . $wizardItemId . '"');
$editorPos = strpos($inlineEditor['body'], 'data-editor-for="' . $wizardItemId . '"');
$openPos = strpos($inlineEditor['body'], 'work-card--open');

$t->is($cardPos !== false && $editorPos !== false,
    'редактор знает, какой позиции работы он касается');

$t->is($cardPos !== false && $openPos !== false && $openPos < $cardPos,
    'на доске помечена именно карточка правящегося задания');

$t->is(
    $cardPos !== false && $openPos !== false && $cardPos - $openPos < 400,
    'пометка стоит на карточке, а не на соседнем задании'
);

/*
 * Форма лежит в окне, а не под доской.
 *
 * Без окна редактор раскрылся бы строкой под карточкой и разорвал доску:
 * при трёх вариантах в строке такой блок встал бы прямо между ячейками.
 */
$modalPos = strpos($inlineEditor['body'], 'class="modal" data-modal');

$t->is(
    $modalPos !== false && $editorPos !== false && $modalPos < $editorPos,
    'редактор открывается поверх доски, а не выпадает строкой между карточками'
);

$t->contains(
    substr($inlineEditor['body'], (int) $editorPos, 600),
    'save_task',
    'в окно приехала именно форма задания'
);

$t->group('Редактор показывает поля только своего типа');

/*
 * Форма содержит только блок своего вида задания.
 *
 * Раньше блоки всех типов были в разметке, а лишние прятал скрипт. Прятать
 * оказалось поздно: браузер отправляет и скрытые поля, и вторая форма выбора
 * присылала те же `option_text[]` — варианты удваивались с каждой правкой.
 * Проверяется именно отсутствие чужих блоков.
 */
$t->contains($inlineEditor['body'], 'data-type="single_choice"', 'блок своего вида в разметке есть');

$t->notContains($inlineEditor['body'], 'data-type="ordering"', 'блока порядка на странице нет');
$t->notContains($inlineEditor['body'], 'data-type="numeric"', 'блока числового ответа на странице нет');
$t->notContains($inlineEditor['body'], 'data-type="multiple_choice"',
    'блока множественного выбора на странице нет');

$t->contains($inlineEditor['body'], 'data-fixed-type="single_choice"',
    'форма знает свой тип задания');

$appCss = (string) @file_get_contents($rootDir . '/public/assets/css/app.css');

$t->contains($appCss, '[hidden] { display: none !important; }',
    'стили не перебивают скрытие лишних блоков полей');

/*
 * Кнопка «Добавить вариант» проверяется по самому скрипту: браузера в наборе
 * нет, а ошибка была в одну строку и выглядела безобидно.
 *
 * Строка вариантов добавлялась в саму себя, а такая вставка бросает
 * HierarchyRequestError. Обработчик на этом обрывался, кнопка выглядела
 * работающей, а число вариантов оставалось таким же, сколько было в форме, —
 * то есть добавить четвёртый вариант было невозможно.
 */
$editorJs = (string) @file_get_contents($rootDir . '/public/assets/js/editor.js');

$t->notContains($editorJs, 'row.appendChild(row);',
    'строка вариантов не добавляется сама в себя');
$t->contains($editorJs, 'rows.appendChild(row);',
    'строка вариантов добавляется в контейнер строк');

/*
 * Крестик удаления варианта.
 *
 * Удаление ломает нумерацию: строки после удалённой сдвигаются, а отметка
 * «правильный» приходит номером строки. Без пересборки она указала бы на
 * соседний вариант — и это выглядело бы как «правильный ответ сам сменился».
 */
$t->contains($editorJs, 'data-option-drop', 'обработчик крестика удаления есть');
$t->contains($editorJs, "rows.addEventListener('click'", 'крестик ловится на контейнере, а не на кнопке');
$t->contains($editorJs, 'OPTION_MINIMUM', 'удаление ограничено двумя вариантами');

/*
 * Ранний выход из визуального редактора не должен выключать остальной скрипт.
 *
 * Код Quill был отдельным блоком с `return` внутри общей функции IIFE: без
 * библиотеки (блокировка скриптов, ошибка загрузки) возврат выходил из всего
 * файла, и кнопки вариантов не настраивались — страница выглядела исправной,
 * а добавить или удалить вариант было нельзя. Настройки текста вынесены в
 * свою функцию, и проверяется именно вызов извне.
 */
$t->contains($editorJs, 'function setupPromptEditor()', 'настройка визуального редактора вынесена в отдельную функцию');
$t->contains($editorJs, 'setupPromptEditor();', 'она вызывается, а варианты настраиваются после неё');
$t->is(
    strpos($editorJs, 'setupPromptEditor();') < strpos($editorJs, 'data-option-rows'),
    'варианты ответа настраиваются после вызова, а не внутри того же блока'
);

/*
 * Форма редактора ищется по тому, чем помечена, а не по классу или селектору.
 *
 * У формы редактора задания нет ни `#question-type` (тип выбирают при
 * добавлении), ни класса `form`: она помечена `data-fixed-type`. Поиск по
 * классу возвращал пустоту, скрипт выходил до строк с вариантами — и
 * «Добавить вариант» не делала ничего, причём страница выглядела исправной.
 */
$t->contains($editorJs, "scope.querySelector('form[data-fixed-type]')",
    'форма редактора находится по своей метке, а не по классу .form');

/*
 * Поиск идёт внутри переданного куска, а не по всей странице.
 *
 * Окно редактора наполняется по данным и открывается без перезагрузки, поэтому
 * форму приходится настраивать заново, уже вставленную в страницу. Поиск по
 * документу нашёл бы первую попавшуюся форму — на странице их может быть
 * больше одной, и вторая осталась бы без визуального редактора и без кнопок
 * добавления варианта.
 */
$t->contains($editorJs, 'function myEduEditorInit(root)',
    'редактор настраивается по переданному куску разметки');
$t->contains($editorJs, "var scope = root || document",
    'без переданного кучка берётся вся страница');
$t->contains($editorJs, "form.hasAttribute('data-editor-ready')",
    'повторная настройка той же формы пропускается');
$t->contains($editorJs, 'window.MyEduEditor = {',
    'скрипт отдаёт точку входа для подставленной формы');
$t->contains($editorJs, 'init: myEduEditorInit',
    'точка входа настраивает подставленную форму');
$t->notContains($editorJs, "radio.name = 'correct'",
    'отметка новой строки не переименовывается: сервер читает correct_single и correct_multi');
$t->contains($editorJs, 'radio.checked = false',
    'новая строка приходит без отметки «правильный»');

/*
 * Вопрос читается и возвращается через скрипт, а не через скрытое поле.
 *
 * Поле уезжает в форму только при отправке, поэтому в нём сохранённое, а не
 * введённое. Черновику нужно второе: без этих двух функций он сохранил бы старый
 * текст вопроса и вернул его при следующем открытии — выглядело бы как откат
 * правки.
 */
$t->contains($editorJs, 'promptHtml: function (form)',
    'скрипт отдаёт то, что набрано в редакторе вопроса прямо сейчас');
$t->contains($editorJs, 'setPromptHtml: function (form, html)',
    'скрипт умеет вернуть набранное в редактор вопроса');

/*
 * Черновик редактора: на сервере, до нажатия «Сохранить».
 *
 * Черновик переехал из браузера в базу: sessionStorage умирал вместе со вкладкой,
 * и работа на пять минут могла уйти из-за закрытого окна или перезагрузки машины.
 * Теперь он переживает и то и другое, но по-прежнему ничего не публикует: строки
 * лежат в отдельной таблице, ученик их не видит, а «Сохранить» остаётся
 * единственным способом внести правку в работу.
 *
 * Проверяются три договора, из-за которых он выбран именно таким.
 *
 * Первый: это не второй способ сохранить. Отправки формы нет — на сервер уходит
 * только черновик, отдельным запросом, и работа от этого не меняется.
 *
 * Второй: черновик не остаётся в браузере. Иначе он продолжил бы умирать вместе
 * с вкладкой, а всё, что на него потрачено, было бы враньём при открытии.
 *
 * Третий: черновик живёт по номеру позиции и по автору, поэтому правка одного
 * задания не восстанавливается в другом, а чужая не отдаётся никогда.
 */
$draftJs = (string) @file_get_contents($rootDir . '/public/assets/js/task-draft.js');
$itemsJs = (string) @file_get_contents($rootDir . '/public/assets/js/assignment-items.js');

$t->is($draftJs !== '', 'модуль черновика на месте');
$t->notContains($draftJs, 'form.submit', 'черновик не отправляет форму вместо человека');
/*
 * Проверка на хранилища намеренно ищет обращения, а не слово: про них написано в
 * пояснении файла, и совпадение по слову ругало бы верный код за неверное.
 * Обращение — то, что правда осталось бы в браузере: getItem, setItem и само
 * свойство.
 */
$t->notContains($draftJs, 'localStorage.getItem',
    'черновик не остаётся на диске после закрытия вкладки');
$t->notContains($draftJs, 'localStorage.setItem',
    'черновик не остаётся на диске после закрытия вкладки');
$t->notContains($draftJs, 'window.localStorage',
    'черновик не остаётся на диске после закрытия вкладки');
$t->notContains($draftJs, 'sessionStorage.getItem',
    'черновик не умирает вместе с вкладкой');
$t->notContains($draftJs, 'sessionStorage.setItem',
    'черновик не умирает вместе с вкладкой');
$t->notContains($draftJs, 'window.sessionStorage',
    'черновик не умирает вместе с вкладкой');
$t->contains($draftJs, 'data-draft-url', 'адрес черновика приходит вместе с формой');
$t->contains($draftJs, 'data-editor-for', 'номер позиции берётся у окна редактора');
$t->contains($draftJs, 'sendBeacon', 'последняя правка уходит при уходе со страницы');
$t->contains($draftJs, "addEventListener('pagehide'", 'уход со страницы дописывает черновик');
$t->contains($draftJs, "addEventListener('beforeunload'", 'несохранённая правка предупреждает об уходе');
$t->contains($draftJs, "addEventListener('input'", 'правка запоминается по мере набора');
$t->contains($draftJs, "'_token'", 'одноразовые поля в черновик не кладутся');

$t->contains($itemsJs, 'window.MyEduTaskDraft.init(root)',
    'доска настраивает черновик вместе с формой');
$t->contains($itemsJs, 'window.MyEduTaskDraft.reopen(modal)',
    'открытое задание возвращает черновик');
$t->contains($itemsJs, 'window.MyEduTaskDraft.drop(form)',
    'после сохранения черновик удаляется, а не остаётся следующим витком');

/*
 * Отмена удаляет черновик, а закрытие окна — нет.
 *
 * Отмена значит отмену: вернуть правку, от которой человек отказался, при
 * следующем открытии значило бы спорить с ним дважды. Крестик, Escape и щелчок по
 * подложке — это отвлечение, а не отказ, и черновик там остаётся.
 */
$t->is(
    strpos($itemsJs, 'MyEduTaskDraft.drop(link.closest(\'form\'))') !== false,
    'отмена правки удаляет черновик'
);

/*
 * Черновик подключён к странице редактора и грузится ДО доски.
 *
 * Раньше порядок был обратным, и это стоило «иногда появляющегося» вопроса
 * «Закрыть сайт?». Оба скрипта отложенные и выполняются по порядку в
 * разметке: при обратном порядке доска настраивала формы раньше, чем модуль
 * черновиков появлялся на странице, и модуль вставал на место только после
 * первого открытия задания — вместе с предупреждением об уходе. Сохранить
 * работу без вопроса тогда было нельзя.
 */
$draftPanel = (string) @file_get_contents($rootDir . '/templates/panel/_assignment_items_pane.php');

$t->contains($draftPanel, 'assets/js/task-draft.js', 'страница редактора подключает модуль черновика');
$t->contains($draftPanel, 'data-draft-url', 'окно редактора знает адрес своего черновика');
$t->is(
    strpos($draftPanel, 'assets/js/task-draft.js') < strpos($draftPanel, 'assets/js/assignment-items.js'),
    'модуль черновика подключается раньше скрипта доски'
);

/*
 * Черновик на сервере: переживает вкладку, но чужому не отдаётся.
 *
 * Черновик переехал из sessionStorage в базу, потому что вкладка закрывается
 * вместе с правкой. Проверяется ровно то, ради чего он переехал: написанное
 * лежит на сервере, возвращается тому же автору, не достаётся чужому учителю,
 * не достаётся гостю и убирается по отмене.
 */
$t->group('Черновик правки позиции');

$draftItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :id ORDER BY id LIMIT 1',
    ['id' => $assignmentId]
);

$draftAuthorId = (int) $db->value(
    'SELECT author_id FROM assignments WHERE id = :id',
    ['id' => $assignmentId]
);

$t->is($draftItemId > 0, 'у работы есть позиция, у которой можно взять черновик');

$draftUrl = $itemsUrl . '/' . $draftItemId . '/draft';
$draftPage = $teacher->get($itemsUrl . '?edit=' . $testId);
$draftToken = $teacher->tokenFrom($draftPage) ?? '';

$t->is(
    str_contains((string) $draftPage['body'], 'data-draft-url'),
    'адрес черновика приходит вместе с открытым редактором'
);

$t->is(
    $teacher->getAjax($draftUrl)['status'] === 200,
    'черновик положения запрашивается отдельным адресом'
);

$savedDraft = $teacher->postAjax($draftUrl, [
    '_token' => $draftToken,
    'action'  => 'save',
    'fields'  => json_encode([
        'question_text' => 'Черновик, который должен пережить вкладку',
        'option_text'   => ['Первый вариант', 'Второй вариант'],
    ], JSON_UNESCAPED_UNICODE),
]);

$t->is($savedDraft['status'] === 200, 'черновик записывается отдельным запросом');
$draftRows = $db->all('SELECT id, item_id, author_id FROM assignment_drafts');

$t->is(
    count(array_filter(
        $draftRows,
        static fn (array $row): bool => (int) $row['item_id'] === $draftItemId
            && (int) $row['author_id'] === $draftAuthorId
    )) === 1,
    'черновик лежит в базе и принадлежит автору'
);

$readBack = $teacher->getAjax($draftUrl);

$t->is(
    str_contains(
        (string) $readBack['json']['fields']['question_text'] ?? '',
        'пережить вкладку'
    ),
    'написанное возвращается при следующем открытии задания'
);

/*
 * Позиция не публикуется тем, что черновик записан.
 *
 * Главная причина, по которой черновик остаётся отдельной сущностью: правка
 * задания не должна попадать в работу молча. Если после записи черновика текст
 * изменился в самой позиции — автосохранение незаметно публикует то, что
 * человек правит для себя.
 */
$t->is(
    !str_contains(
        (string) $db->value(
            'SELECT config FROM questions WHERE test_id = :id ORDER BY id LIMIT 1',
            ['id' => $testId]
        ),
        'пережить вкладку'
    ),
    'черновик не меняет саму позицию работы'
);

/*
 * Чужой черновик не отдаётся.
 *
 * У одного задания может быть несколько редакторов, и у каждого своя
 * недописанная правка. Отдавать чужую значило бы показать человеку то, чего он
 * не писал, — и стереть его при первом же сохранении.
 *
 * Отказ здесь — «это работа другого автора»: черновик принадлежит автору, и
 * чужой автор работы не имеет права его видеть.
 */
$otherDraft = $otherTeacher->getAjax($draftUrl);

$t->is(
    $otherDraft['status'] === 403,
    'чужой учитель черновик не получает вовсе'
);

$t->is(
    $guest->getAjax($draftUrl)['status'] === 401,
    'гостю черновик недоступен'
);

/*
 * Позиция от другой работы в этом адресе не годится.
 *
 * Адрес называет и работу, и позицию, а сверить их может только сервер: без
 * сверки номер позиции можно подставить свой, и черновик уехал бы не туда.
 */
$foreignUrl = '/panel/assignments/' . ($assignmentId + 1000) . '/edit/items/' . $draftItemId . '/draft';

$t->is(
    $teacher->getAjax($foreignUrl)['status'] === 404,
    'позиция от другой работы не отдаёт черновик'
);

/* Отмена значит отмену: черновик уходит вместе с правкой. */
$dropped = $teacher->postAjax($draftUrl, [
    '_token' => $draftToken,
    'action'  => 'drop',
]);

$t->is($dropped['status'] === 200, 'черновик убирается по отмене');
$t->is(
    (int) $db->value('SELECT COUNT(*) FROM assignment_drafts WHERE item_id = :item', ['item' => $draftItemId]) === 0,
    'после отмены черновика в базе нет'
);

/*
 * Черновик не принимается без защитного поля.
 *
 * Иначе его можно было бы писать и подсовывать от чужого имени прямо со
 * страницы учителя: адрес известен, тело формируется без труда. Ответ — 419,
 * тот же, что и на любой другой форме без защитного поля.
 */
$t->is(
    $teacher->postAjax($draftUrl, [
        'action' => 'save',
        'fields' => json_encode(['question_text' => 'подделка'], JSON_UNESCAPED_UNICODE),
    ])['status'] === 419,
    'черновик не принимается без защитного поля'
);

/*
 * Отказ по сессии не выглядит как «сервер не ответил данными».
 *
 * Раньше ответ разбирался как JSON, а истёкшая сессия отвечает страницей входа:
 * разбор падал, и скрипт отдавал форму обычным способом. То есть правка уходила
 * повторно на адрес, где её уже не ждали, и человек видел страницу ошибки вместо
 * своего текста — молча потеряв введённое. Теперь код ответа разбирается первым,
 * отказ называют словами, а введённое остаётся в форме и в черновике.
 */
$inlineEditJs = (string) @file_get_contents($rootDir . '/public/assets/js/inline-edit.js');

foreach ([['доске заданий', $itemsJs], ['правке в строке', $inlineEditJs]] as [$where, $script]) {
    $t->contains($script, 'response.status === 401',
        'код ответа разбирается до тела: ' . $where);
    $t->contains($script, 'response.status === 403',
        'отказ по правам отличим от сбоя сети: ' . $where);
    $t->contains($script, 'response.redirected',
        'перенаправление на вход не разбирается как данные: ' . $where);
    $t->contains($script, 'Сессия закончилась',
        'отказ называют словами, а не «не сохранено»: ' . $where);
}

/*
 * Правка в строке помечает поле, в котором человек ошибся.
 *
 * Без отметки текст отказа («занятое имя», «неверная дата») не указывает места:
 * правка одна, а причина может быть вовсе не в поле, и человек ищет, где именно.
 */
$t->contains($inlineEditJs, "input.classList.add('is-invalid')",
    'правка в строке подсвечивает поле при отказе');
$t->contains($inlineEditJs, "input.setAttribute('aria-invalid', 'true')",
    'подсвеченное поле помечается для программ чтения');
$t->contains($inlineEditJs, 'markField(form, false)',
    'после сохранения отметка с поля снимается');

/*
 * Истёкшая сессия: запрос, ждущий данных, получает отказ, а не страницу входа.
 *
 * Это ровно то, что приходит от браузера на `fetch`: иначе скрипт получает
 * HTML там, где ждал JSON. Обычный запрос при этом ведёт себя как раньше —
 * уводит на вход, — иначе человек потерял бы адрес, с которого вошёл.
 */
$expiredItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :id ORDER BY id LIMIT 1',
    ['id' => $wizardId]
);
$expiredSaveUrl = '/panel/assignments/' . $wizardId . '/edit/items/' . $expiredItemId;

$expiredAjax = $guest->postAjax($expiredSaveUrl, [
    '_token'      => 'любой',
    'question_id' => '0',
    'action'      => 'save_task',
    'prompt'      => 'правка без сессии',
]);

$t->is($expiredAjax['status'] === 401, 'запрос, ждущий данные, получает 401, а не страницу входа');
$t->is(str_contains((string) $expiredAjax['body'], '"ok":false'),
    'отказ приходит данными с признаком неудачи');
$t->is(str_contains((string) $expiredAjax['body'], '"message"')
    && str_contains((string) $expiredAjax['body'], '"error"'),
    'текст отказа есть под обоими именами: скрипты читают разные');
$t->notContains($expiredAjax['body'], '<!DOCTYPE',
    'вместо ответа пришла страница, и скрипту нечего было бы разбирать');

$expiredBrowser = $guest->post($expiredSaveUrl, [
    '_token'      => 'любой',
    'question_id' => '0',
    'action'      => 'save_task',
    'prompt'      => 'правка без сессии',
]);

$t->is(
    $expiredBrowser['status'] === 302 && str_contains((string) $expiredBrowser['location'], '/login'),
    'обычный запрос по-прежнему уводит на вход'
);
$t->is((string) $db->value(
    'SELECT prompt FROM questions WHERE id = :id',
    ['id' => $questionId]
) !== 'правка без сессии', 'отказ без сессии ничего не записал');

/*
 * Протухший токен — тоже отказ, а не молчание.
 *
 * Форма открыта давно, токен в ней старый, и отправка падает на проверке. Число
 * 419 скрипт обязан назвать сам: иначе страница 419 приходит вместо данных и
 * правка выглядит несохранённой без причины.
 *
 * Запрос от вошедшего: гостя отсекает проверка прав, и до токена дело не доходит
 * — отказ был бы 401, а это другой случай, уже проверенный выше.
 */
$staleToken = $teacher->postAjax($expiredSaveUrl, [
    '_token'      => 'токен, которого не бывает',
    'question_id' => (string) $questionId,
    'action'      => 'save_task',
    'prompt'      => 'правка с чужим токеном',
]);

$t->is($staleToken['status'] === 419, 'протухший токен отвечает 419');
$t->is(str_contains((string) $staleToken['body'], '"ok":false'),
    'отказ по токену приходит данными');
$t->notContains($staleToken['body'], '<!DOCTYPE',
    'отказ по токену не пришёл страницей');
$t->is((string) $db->value(
    'SELECT prompt FROM questions WHERE id = :id',
    ['id' => $questionId]
) !== 'правка с чужим токеном', 'отказ по токену ничего не записал');

/*
 * Кнопка добавления варианта проверена браузером: `tools/editor_probe.ps1`
 * проходит по настоящей форме и возвращает код возврата. Здесь — только то,
 * что видно без браузера: разметка кнопки и имя поля, которым она пользуется.
 */
$pluginTaskForm = (string) @file_get_contents($rootDir . '/plugins/test/templates/_task_form.php');
$questionFields = (string) @file_get_contents($rootDir . '/plugins/test/templates/_question_fields.php');

$t->contains($pluginTaskForm, 'data-fixed-type=', 'форма редактора помечена своим типом');
/*
 * Кнопка добавления варианта и имена полей отметки проверяются на странице, а
 * не в файле шаблона.
 *
 * Разметку вопроса рисует ядро (`$ui->optionRows()`), и в шаблоне остались
 * только её параметры. Поиск по файлу проверял бы не то, что видит человек:
 * кнопка нарисована и работает, а слово `data-option-add` в шаблоне не
 * встречается.
 */
$singleChoiceTaskId = (int) $db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id AND task_kind = :k ORDER BY id LIMIT 1',
    ['id' => $wizardId, 'k' => 'single_choice']
);
$singleChoicePage = $singleChoiceTaskId > 0
    ? (string) $openTaskEditor($teacher, $wizardId, $singleChoiceTaskId)['body']
    : '';

/*
 * Множественный выбор проверяется на отдельной работе.
 *
 * В мастере лежит одиночный выбор, и искать там множественный бессмысленно:
 * либо проверка молча ничего не увидит, либо придётся тащить лишнее задание в
 * мастер, где дальше идут проверки его порядка и цены.
 */
$marksWorkId = (int) $db->insert('assignments', [
    'author_id'  => $teacherId,
    'title'      => 'Работа под отметку правильного',
    'status'     => 'draft',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
$marksPage = $teacher->get('/panel/assignments/' . $marksWorkId . '/edit/items');
$teacher->post('/panel/assignments/' . $marksWorkId . '/edit/items/add', [
    '_token'    => $teacher->tokenFrom($marksPage) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'multiple_choice',
]);
$marksTaskId = (int) $db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id AND task_kind = :k ORDER BY id LIMIT 1',
    ['id' => $marksWorkId, 'k' => 'multiple_choice']
);
$multipleChoicePage = $marksTaskId > 0
    ? (string) $openTaskEditor($teacher, $marksWorkId, $marksTaskId)['body']
    : '';

$t->contains($singleChoicePage, 'data-option-add', 'у формы вопроса есть кнопка добавления варианта');
$t->contains($singleChoicePage, 'name="correct_single"',
    'отметка правильного у одиночного выбора своя');
$t->notContains($singleChoicePage, 'name="correct_single[]"',
    'отметка одиночного выбора без скобок: переключатель в группе нужен один');

/*
 * У флажков имя с хвостовыми скобками.
 *
 * Формы отправляют одноимённые поля по-разному: переключатель в группе нужен
 * один, а флажков отмечают сколько угодно, и без скобок браузер присылает
 * **последний** отмеченный. Дальше `postArray('correct_multi')` видел не
 * массив, а число, возвращал пустой список — и автору, отметившему правильные
 * варианты, сообщали «отметьте хотя бы один правильный вариант». Отметки на
 * экране стояли, а сервер их не видел.
 */
$t->contains($multipleChoicePage, 'name="correct_multi[]"',
    'отметка правильного у множественного выбора своя и собирает все отметки');
$t->contains($singleChoicePage, 'name="correct_single"',
    'отметка одиночного выбора осталась прежней');
/*
 * Поле в плагине — то же поле, что и в ядре, посимвольно.
 *
 * Требование «плагины рисуют типовыми элементами ядра» проверяется сравнением
 * разметки, а не наличием классов: классы могли бы совпасть, а отличаться
 * порядок, подпись, отметка обязательности или подсказка — и человек увидел бы
 * два разных поля. Сравнивается ровно то, что выдаёт хук, с тем, что пришло на
 * страницу.
 */
/*
 * Сверяется то поле, которое у заданий осталось, — условие. Раньше здесь
 * сверялось поле заголовка; оно ушло вместе с самим заголовком, а сверять
 * стало нечего, и проверка тихо перестала бы что-либо доказывать.
 */
$teacherUi = (new View($rootDir . '/templates'))->ui();

$pluginPromptValue = preg_match(
    '#<textarea[^>]*name="prompt"[^>]*>(.*?)</textarea>#s',
    $singleChoicePage,
    $pluginPromptMatch
) === 1 ? trim(html_entity_decode(strip_tags($pluginPromptMatch[1]), ENT_QUOTES, 'UTF-8')) : '';

/*
 * Эталон собирается ядром, теми же настройками, какими рисует плагин.
 *
 * Раньше здесь стоял `field()` — другое поле, обычный текст вместо
 * форматированного. Условие редактируется с картинками и списками, и такая
 * подмена проходила незаметно: страница просто не содержала «эталона», и
 * проверка падала, ничего не говоря о том, что на самом деле разошлось.
 */
$canonicalPrompt = $teacherUi->editor('prompt', [
    'label'       => 'Текст задания',
    'value'       => $pluginPromptValue,
    'placeholder' => 'Что именно спрашиваете у ученика',
    'rows'        => 4,
]);

/*
 * Имя блока этапа не совпадает с именем из шаблона.
 *
 * В сборке gentelella `.wizard-step` — это кружок в полосе шагов, и он гасится
 * до `opacity: 0.6`, пока шаг не пройден. Общее имя применяло гашение ко всему
 * содержимому этапа: редактор задания целиком выглядел «затенённым», и это
 * выглядело как поломка оформления, а не как конфликт имён. Ни наборы, ни
 * `check_attrs` такое не видят: разметка верна, а гасит её чужое правило.
 */
$t->contains($inlineEditor['body'], 'class="work-board"',
      'блок этапа назван своим именем, а не как в шаблоне');
$t->notContains($inlineEditor['body'], 'class="wizard-step"',
    'в разметке нет класса шаблона: его гашение применяется к незакрытым шагам');

$t->contains($singleChoicePage, $canonicalPrompt,
    'поле редактора плагина совпадает с полем, которое рисует ядро');

/*
 * Чужое задание внутри чужой работы не открывается: номер приходит из адреса
 * и без проверки автор увидел бы форму чужого задания в своей работе.
 */
$otherWorkTaskId = (int) ($db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id <> :id ORDER BY id LIMIT 1',
    ['id' => $wizardId]
) ?? 0);

if ($otherWorkTaskId > 0) {
    $foreignEditor = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items?edit=' . $otherWorkTaskId);

    $t->notContains($foreignEditor['body'], 'name="action" value="save_task"',
        'чужое задание в редакторе этой работы не открывается');
}

/*
 * Перемещение не должно затирать цену.
 *
 * Задание переставляется кнопкой, которая отправляет только новую позицию.
 * Если бы позиция обновлялась целиком, молча пропала бы и цена: учитель
 * ничего не трогал, а баллы исчезли.
 */
$itemsPage = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

/*
 * Цена позиции не редактируется в общем списке: рядом с названием задания стояло
 * второе поле «Баллы» — рядом с «Балл за вопрос» в редакторе. Две подписи об
 * одном числе рядом друг с другом выглядели как дубль, а значения могли разойтись:
 * у теста цена позиции считается как сумма весов вопросов, и задать её ещё и
 * здесь можно было только вручную.
 *
 * Цена позиции задаётся в редакторе задания — там, где живут и остальные
 * свойства задания.
 */
$t->notContains($itemsPage['body'], 'name="max_score"', 'в списке заданий нет поля цены позиции');
$t->notContains($itemsPage['body'], 'task-row__points', 'у строки задания не осталось блока с ценой');

$movedItem = (int) $wizardRows[1]['id'];

$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/' . $movedItem, [
    '_token'   => $teacher->tokenFrom($itemsPage) ?? '',
    'position' => '1',
]);

$movedRows = $db->all(
    'SELECT id, position, max_score FROM assignment_items WHERE assignment_id = :id ORDER BY position',
    ['id' => $wizardId]
);

$t->equals($movedItem, (int) $movedRows[0]['id'], 'задание встало на первое место');
$t->equals(3.0, (float) $movedRows[0]['max_score'], 'перемещение не обнулило цену задания');
$t->equals(5.0, (float) $movedRows[1]['max_score'], 'цена соседнего задания не тронута');

// Этап результата и режим сведения.
$stepResult = $teacher->get('/panel/assignments/' . $wizardId . '/edit/result');
$t->is($stepResult['status'] === 200, 'этап результата открыт');
$t->contains($stepResult['body'], 'Сумма цен заданий: 8', 'показана сумма цен, от которой отсчитываются диапазоны');

$teacher->post('/panel/assignments/' . $wizardId . '/edit/result', [
    '_token'           => $teacher->tokenFrom($stepResult) ?? '',
    'criteria_mode'    => 'score',
    'grade_mode'       => 'weighted',
    'show_results'     => '1',
    'criteria_min[]'   => ['4', '2', '1', '0'],
    'criteria_max[]'   => ['8', '3.5', '1.5', '0.5'],
    'color[]'          => ['success', 'primary', 'warning', 'danger'],
]);

$savedMode = (string) $db->value('SELECT criteria_mode FROM assignments WHERE id = :id', ['id' => $wizardId]);
$savedCriteria = (string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $wizardId]);

$t->equals('score', $savedMode, 'режим «по баллам» сохранён');

/*
 * Границы в баллах обязаны лежать под своим именем.
 *
 * Записанные в min_percent, восемь баллов прочитались бы как восемь
 * процентов: работа получила бы правдоподобные и совсем не те оценки, и
 * заметить это можно было бы только по разборам.
 */
$t->contains($savedCriteria, 'min_score', 'границы в баллах сохранены в min_score');
$t->notContains($savedCriteria, 'min_percent', 'процентных границ в режиме баллов нет');

$decodedCriteria = json_decode($savedCriteria, true);

$t->equals(4.0, (float) ($decodedCriteria[0]['min_score'] ?? 0), 'нижняя граница пятёрки — четыре балла');
$t->equals(8.0, (float) ($decodedCriteria[0]['max_score'] ?? 0), 'верхняя граница пятёрки — восемь баллов');
$t->equals(5, $decodedCriteria[0]['grade'] ?? 0, 'первой идёт самая высокая оценка');
$t->equals(4, count($decodedCriteria), 'в шкале ровно четыре оценки');

// --------------------------------------------------------------- шкала оценок

$t->group('Шкала оценок: разные поля по режимам');

$scaleWorkId = (int) $db->insert('assignments', [
    'author_id'     => $teacherId,
    'title'         => 'Работа под шкалу',
    'status'        => 'draft',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_items (assignment_id, position, plugin_id, task_kind, task_id, weight, max_score, created_at)
     VALUES (:w, 1, \'test\', \'generated\', :t, 1.00, 20.00, NOW())',
    ['w' => $scaleWorkId, 't' => $testId]
);

$scalePercent = $teacher->get('/panel/assignments/' . $scaleWorkId . '/edit/result');

/*
 * Наборы полей оба приходят с сервера — их переключает стиль по отмеченной
 * кнопке, а не рисует заново скрипт. Проверяется неактивный набор: его границы
 * уехали бы вместе с видимыми, а сервер получил бы восемь чисел вместо четырёх
 * и разложил их по оценкам как попало.
 *
 * Набор лежит в панели вкладок шаблона, поэтому извлекается по классу панели и
 * атрибуту набора, а не по прежнему «голому» <div data-scale-set=…>.
 */
$percentSet = '';
$scoreSet = '';

if (preg_match('#<div class="tab-pane[^"]*" data-scale-set="percent">(.*?)</fieldset>\s*</div>#s', $scalePercent['body'], $pm) === 1) {
    $percentSet = $pm[1];
}

if (preg_match('#<div class="tab-pane[^"]*" data-scale-set="score">(.*?)</fieldset>\s*</div>#s', $scalePercent['body'], $sm) === 1) {
    $scoreSet = $sm[1];
}

// Оценок ровно четыре, и ни одна не редактируется: набор 2, 3, 4, 5 не меняется.
$t->notContains($scalePercent['body'], 'name="criteria_grade[]"', 'в форме нет поля выбора оценки');

/*
 * Переключатель режима работает, только если наборы полей лежат внутри того же
 * корня, где стоят кнопки выбора режима. Раньше корень был `<fieldset>` вокруг
 * самих переключателей и закрывался до наборов: переключение искало наборы
 * внутри него, не находило ничего и ничего не переключало — при этом страница
 * выглядела исправной, потому что сервер отдавал нужный набор включённым.
 * Щелчок по кнопке не менял ничего, и это выглядело как «скрипты не загрузились».
 *
 * Проверяются идентификаторы кнопок, а не прежняя метка data-scale-mode: по
 * ним же переключается видимость наборов, и по ним же проверяется, что выбор
 * доезжает до сервера.
 */
$switchRootStart = strpos($scalePercent['body'], 'data-scale-switch');
$switchRootEnd = $switchRootStart === false
    ? false
    : strpos($scalePercent['body'], '</form>', (int) $switchRootStart);

$t->is($switchRootStart !== false, 'у формы есть корень переключателя режима');

$insideRoot = $switchRootStart === false || $switchRootEnd === false
    ? ''
    : substr($scalePercent['body'], (int) $switchRootStart, (int) $switchRootEnd - (int) $switchRootStart);

$t->contains($insideRoot, 'id="criteria_mode_percent"', 'кнопка процентов внутри корня');
$t->contains($insideRoot, 'id="criteria_mode_score"', 'кнопка баллов внутри корня');
$t->contains($insideRoot, 'data-scale-set="percent"', 'набор процентов внутри того же корня');
$t->contains($insideRoot, 'data-scale-set="score"', 'набор баллов внутри того же корня');
$t->equals(
    1,
    substr_count($scalePercent['body'], 'data-scale-switch'),
    'корень переключателя один: два корня переключали бы половину набора'
);

/*
 * Режим выбирается вкладками-таблетками шаблона, а подпись вкладки связана с
 * кнопкой через `for`.
 *
 * Связь проверяется именно через `for`: без него подпись не щёлкается, и
 * выбрать режим с клавиатуры было бы нельзя. Сама заливка выбранной вкладки
 * задаётся соседним селектором — вкладка должна идти сразу за кнопкой,
 * иначе оформление не переключится, а поля переключатся.
 */
$t->contains($scalePercent['body'], 'class="nav nav-pills"', 'режимы выбраны вкладками-таблетками');
$t->contains($scalePercent['body'], 'for="criteria_mode_percent"', 'подпись вкладки связана с кнопкой процентов');
$t->contains($scalePercent['body'], 'for="criteria_mode_score"', 'подпись вкладки связана с кнопкой баллов');
$t->is(
    preg_match('#class="criteria-pill__input"[^>]*>\s*<label class="nav-link criteria-pill" for="criteria_mode_percent"#', $scalePercent['body']) === 1,
    'кнопка режима стоит перед подписью: иначе заливка вкладки не переключится'
);

$t->equals(4, substr_count($percentSet, 'name="percent_min[]"'),
    'в процентном наборе строка на каждую из четырёх оценок');
$t->notContains($percentSet, 'name="percent_max[]"',
    'в процентном наборе верхней границы нет');
$t->contains($scoreSet, 'name="score_min[]"', 'в балловом наборе нижняя граница есть');
$t->contains($scoreSet, 'name="score_max[]"', 'в балловом наборе верхняя граница есть');

/*
 * Невидимый набор не отключается и не прячется: и то и другое убивало то, что
 * в нём набрано. Отключённая рамка не отправляется — переключение режима
 * стирало бы чужие границы, и автор возвращался к пустой форме; атрибут hidden
 * оставлял бы страницу зависшей от скрипта, который и был причиной поломки.
 */
$t->notContains($percentSet, 'disabled', 'набор не отключается: его границы переживают переключение');
$t->notContains($scoreSet, 'disabled', 'балловый набор не отключается по той же причине');

// Атрибут `hidden`, а не подстрока: у стрелки между цветом и оценкой стоит
// `aria-hidden`, и подстрока ловила его, объявляя набор спрятанным.
$t->is(preg_match('#\shidden[\s=>]#', $percentSet) !== 1, 'видимостью набора не управляет разметка');
$t->is(preg_match('#\shidden[\s=>]#', $scoreSet) !== 1, 'видимостью баллового набора не управляет разметка');

$t->notContains($percentSet, 'criteria_set', 'набор больше не объявляет себя отдельным полем');

/*
 * Значения по умолчанию стоят в самих полях, а не перечислены в подсказке
 * под ними: подсказка не копируется в форму, и пороги пришлось бы вводить
 * заново при каждой новой работе.
 *
 * У двойки видимого поля нет: её диапазон тянется от нуля. Вместо него в
 * форму уходит скрытое поле с тем же именем и нулем — без него индексы
 * массива сдвинулись бы и каждая оценка получила бы число соседней. Поэтому
 * четвёртое значение равно «0», а не пустой строке.
 *
 * Перед `value=` стоит проверка «не дефис»: у поля процентов есть
 * `data-total-value="20"`, и без проверки регулярное выражение цепляло его вместо
 * самого значения — проверки видели бы у всех оценок сумму цен заданий.
 */
preg_match_all('#name="percent_min\[\]"[^>]*?\s(?<![\w-])value="([\d.,]*)"#s', $percentSet, $defaultPorts);

$t->equals(['85', '70', '50', '0'], $defaultPorts[1] ?? [],
    'в полях сразу стоят пороги 85, 70 и 50 — от высшей оценки к низшей');

preg_match_all('#name="score_min\[\]"[^>]*?\s(?<![\w-])value="([\d.,]*)"#s', $scoreSet, $scorePorts);

$t->equals(['15', '10', '5', '0'], $scorePorts[1] ?? [],
    'в балловом наборе границы по умолчанию делят сумму цен на четыре части');

/*
 * У двойки нет ни подписи поля, ни видимого ввода, но есть скрытое поле с
 * индексом. Проверяется именно оно: без него индексы сдвинулись бы, а форма
 * выглядела бы при этом исправной.
 *
 * Строка вырезается делением по началу строки, а не регулярным выражением с
 * `.*?`: внутри строки есть вложенный блок границ со своим `</div>`, и
 * нежадное выражение оборвалось бы на нём, не дойдя до полей.
 *
 * В lookahead после `scale-row` обязателен: следующий символ — кавычка или
 * пробел, а у вложенного блока границ там подчёркивание, и простое деление по
 * строке `<div class="scale-row` разрезало бы каждую строку надвое.
 */
$t->notContains($percentSet, 'На основе предыдущего значения',
    'у двойки больше нет подсказки вместо порога: диапазон тянется от нуля');

$percentRowChunks = preg_split('#<div class="scale-row(?=["\s])#', $percentSet) ?: [];
$gradeTwoRow = $percentRowChunks[4] ?? '';

$t->is(preg_match('#type="hidden" name="percent_min\[\]"[^>]*?\s(?<![\w-])value="0"#s', $gradeTwoRow) === 1,
    'у двойки скрытое поле с нулем вместо видимого ввода');

$t->notContains($gradeTwoRow, 'type="number" name="percent_min[]"',
    'у двойки в процентном наборе нет видимого поля нижней границы');

$t->contains($gradeTwoRow, 'scale-auto',
    'у двойки вместо поля стоит надпись «Считается автоматически»');
$t->contains($gradeTwoRow, 'Считается автоматически',
    'надпись у двойки говорит, откуда берётся её граница');
$t->contains($gradeTwoRow, 'scale-field__label',
    'у надписи двойки та же подпись поля, что и у остальных оценок');

/*
 * Подсказка пересчитывает проценты в баллы с точностью до половины.
 *
 * Порог задают процентами, а человек вкладывает в него целое число баллов:
 * «85% — 11,05 из 13 баллов» выглядит как расчёт машины и обесценивает
 * подсказку, ради которой она и напечатана. Половина балла — та же точность,
 * что и в сведении результата, где баллы округляются до сотых.
 */
$scaleRowsTemplate = (string) @file_get_contents(
    dirname(__DIR__) . '/templates/panel/_scale_rows.php'
);
$scaleJs = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/js/scale-color.js');

$t->contains($scaleRowsTemplate, 'round($total * $percent / 100.0 * 2) / 2',
    'сервер округляет подсказку до половины балла');
$t->contains($scaleJs, 'Math.round(total * percent / 100 * 2) / 2',
    'пересчёт при наборе порога округляет так же, иначе число меняет вид на глазах');

/*
 * Цвет оценки.
 *
 * В списке и на странице результата показывается один и тот же цвет, и выбор
 * учителя доезжает до сведения: иначе подпись на форме и то, что увидит
 * ученик, разошлись бы, а заметить это можно было бы только на чужой работе.
 */
preg_match_all('#scale-row__grade scale-row__grade--([a-z]+)#', $percentSet, $gradeColors);

$t->equals(['success', 'primary', 'warning', 'danger'], $gradeColors[1] ?? [],
    'оценки в форме покрашены по умолчанию: зелёная, синяя, оранжевая, красная');

$t->equals(4, substr_count($percentSet, 'name="color[]"'),
    'в наборе по одному полю цвета на каждую оценку');

$t->notContains($percentSet, 'name="percent_label[]"', 'поля подписи словами больше нет');
$t->notContains($percentSet, 'name="score_label[]"', 'подписи нет и у баллового набора');

/*
 * Выбор цвета — нативное поле, а не четыре кнопки (решение владельца от
 * 24.10.2026, задача 4): по нажатию браузер показывает стандартное окно, в
 * котором есть и привычные краски, и любая другая.
 *
 * Прежняя палитра закрывала выбор четырьмя значениями, и автор, захотевший
 * соседний оттенок, не мог его выбрать.
 */
$t->equals(4, substr_count($percentSet, 'type="color"'),
    'у каждой оценки нативное поле выбора цвета');
$t->notContains($percentSet, 'scale-color__btn',
    'прежней палитры из четырёх кнопок в форме больше нет');

/*
 * Свой цвет автора проходит путь целиком: поле → форма → база → форма.
 *
 * Сохраняется ровно то, что прислал браузер (в пределах `#rrggbb`), а
 * мусор заменяется цветом оценки по умолчанию — иначе произвольная строка
 * из запроса уехала бы в `criteria` и потом в разметку ученика.
 *
 * Проверяется и возврат: форма обязана показать сохранённый свой цвет в
 * поле и покрасить строку оценки — для этого у строки есть атрибут
 * data-grade-color (класса для своего цвета нет, а инлайновый style= в
 * шаблонах запрещён правилами интерфейса).
 */
$scaleResultUrl = '/panel/assignments/' . $scaleWorkId . '/edit/result';
$customHex = '#12ab57';

$teacher->post($scaleResultUrl, [
    '_token'        => $teacher->tokenFrom($teacher->get($scaleResultUrl)) ?? '',
    'criteria_mode' => 'percent',
    'grade_mode'    => 'weighted',
    'show_results'  => '1',
    'percent_min[]' => ['85', '70', '50', ''],
    'color[]'       => [$customHex, 'primary', 'warning', 'danger'],
]);

$criteria = json_decode(
    (string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $scaleWorkId]),
    true
);

$t->is(is_array($criteria) && (string) ($criteria[0]['color'] ?? '') === $customHex,
    'свой цвет сохранён в базу как есть');
$t->is((string) ($criteria[1]['color'] ?? '') === 'primary',
    'цвет из палитры сохранён названием, как и раньше');

$scaleAfterCustom = $teacher->get($scaleResultUrl);

$t->is(
    preg_match('/name="color\[\]"\s+value="' . preg_quote($customHex, '/') . '"/', (string) $scaleAfterCustom['body']) === 1,
    'форма показывает сохранённый свой цвет в поле'
);
$t->contains(
    $scaleAfterCustom['body'],
    'data-grade-color="' . $customHex . '"',
    'строка оценки получила свой цвет атрибутом'
);

$gradeColorJs = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/js/grade-color.js');

$t->contains($gradeColorJs, 'data-grade-color', 'скрипт ищет строки со своим цветом');
$t->contains($gradeColorJs, '.style.color', 'скрипт применяет цвет');

// Мусорное значение — цвет оценки по умолчанию, а не строка в базе.
$teacher->post($scaleResultUrl, [
    '_token'        => $teacher->tokenFrom($scaleAfterCustom) ?? '',
    'criteria_mode' => 'percent',
    'grade_mode'    => 'weighted',
    'show_results'  => '1',
    'percent_min[]' => ['85', '70', '50', ''],
    'color[]'       => ['фиолетовый', 'primary', 'warning', 'danger'],
]);

$criteria = json_decode(
    (string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $scaleWorkId]),
    true
);

$t->is((string) ($criteria[0]['color'] ?? '') === 'success',
    'цвет вне палитры и вне #rrggbb заменён цветом оценки по умолчанию');

// Возвращаем прежнее состояние шкалы: следующие группы проверяют её.
$teacher->post($scaleResultUrl, [
    '_token'        => $teacher->tokenFrom($teacher->get($scaleResultUrl)) ?? '',
    'criteria_mode' => 'percent',
    'grade_mode'    => 'weighted',
    'show_results'  => '1',
    'percent_min[]' => ['85', '70', '50', ''],
    'color[]'       => ['success', 'primary', 'warning', 'danger'],
]);

// В балловом режиме появляется вторая граница.
$db->run("UPDATE assignments SET criteria_mode = 'score' WHERE id = :id", ['id' => $scaleWorkId]);

$scaleScore = $teacher->get('/panel/assignments/' . $scaleWorkId . '/edit/result');

$t->contains($scaleScore['body'], 'name="score_max[]"', 'в балловом режиме есть верхняя граница');
$t->contains($scaleScore['body'], 'value="20"', 'у пятёрки верхняя граница равна сумме цен');

/*
 * Переключение задано стилями, а не скриптом.
 *
 * Видимость наборов — единственное, что делал скрипт на этой странице, и
 * именно из-за него форма молча не работала там, где скрипт не выполнился:
 * страница выглядела исправной, а наборы не менялись. Правило переключения
 * теперь одно и целиком в таблице стилей, и проверяется здесь тем же, чем
 * раньше проверялось подключение скрипта.
 */
$t->notContains($scaleScore['body'], 'scale-mode.js', 'переключение не зависит от скрипта');

$scaleCss = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

$t->contains(
    $scaleCss,
    '[data-scale-switch]:has(#criteria_mode_score:checked) [data-scale-set="score"]',
    'стили показывают балловый набор при отмеченных баллах'
);
$t->contains(
    $scaleCss,
    '[data-scale-switch]:has(#criteria_mode_score:checked) [data-scale-set="percent"]',
    'стили прячут процентный набор при отмеченных баллах'
);

/*
 * Сервер берёт границы того режима, который отмечен, и не смотрит на второй.
 *
 * Раньше наборы отправляли по одному: невидимый отключался, а режим читался
 * из скрытого поля, которое отправляла только видимая рамка. Стоило скрипту
 * не выполниться — и отмеченное разошлось бы с отправленным: выбрал «процент»,
 * отправил балловые границы, а сервер проверил их как процентные и сохранил
 * шкалу «15, 10, 5, 0» вместо «85, 70, 50, 0».
 */
$scaleAllFields = [
        '_token'        => $teacher->tokenFrom($scaleScore) ?? '',
        'percent_min[]' => ['85', '70', '50', '0'],
        'score_min[]'   => ['15', '10', '5', '0'],
        'score_max[]'   => ['20', '15', '10', '5'],
        // Цвет один на оба набора: от режима он не зависит, и две копии по
        // одной подписи в каждом наборе разошлись бы при первой правке так же,
        // как разошлись бы границы.
        'color[]'       => ['success', 'primary', 'warning', 'danger'],
    ];

$percentSaved = $teacher->post(
    '/panel/assignments/' . $scaleWorkId . '/edit/result',
    $scaleAllFields + ['criteria_mode' => 'percent']
);

$t->is($percentSaved['status'] === 302, 'форма с обоими наборами сохраняется');

/*
 * Числа на странице обязаны быть такими, которые форма принимает.
 *
 * Сервер проверяет шаг границы в баллах куда мягче, чем браузер: проверка на
 * сервере для этого режима отключена, а у поля стоит `step`. Расхождение было
 * невидимо при ручной проверке — страница открывалась, наборы сохранялись, — и
 * проявлялось только у человека: браузер отвергал на заполненной форме ровно
 * четыре поля, «не отправлено: проверьте 4 пол.», а отмечать было нечего,
 * потому что значения стояли те, что подставил сервер.
 *
 * Поэтому проверяется не сохранение, а разметка: каждое числовое поле страницы
 * вместе со своим значением, минимумом и шагом. Иначе поломка вернётся вместе
 * с любым изменением расчёта границ.
 */
$scalePage = $teacher->get('/panel/assignments/' . $scaleWorkId . '/edit/result');
$scaleForm = '';

if (preg_match(
    '~<form[^>]*action="/panel/assignments/' . $scaleWorkId . '/edit/save".*?</form>~s',
    $scalePage['body'],
    $scaleFormMatch
) === 1) {
    $scaleForm = $scaleFormMatch[0];
}

$rejectedFields = [];

if ($scaleForm !== ''
    && preg_match_all('~<input\b[^>]*\btype="number"[^>]*>~i', $scaleForm, $numberInputs) > 0) {
    foreach ($numberInputs[0] as $input) {
        if (preg_match('~type="hidden"~i', $input) === 1) {
            continue;
        }

        $value = preg_match('~value="([^"]*)"~i', $input, $v) === 1 ? $v[1] : '';
        $step = preg_match('~\bstep="([^"]*)"~i', $input, $s) === 1 ? $s[1] : '';

        if ($value === '' || $step === '') {
            continue;
        }

        preg_match('~\bname="([^"]*)"~i', $input, $nameMatch);
        preg_match('~\bmin="([^"]*)"~i', $input, $minMatch);

        $number = (float) str_replace(',', '.', $value);
        $unit = (float) str_replace(',', '.', $step);
        $base = isset($minMatch[1]) ? (float) $minMatch[1] : 0.0;

        if ($unit <= 0.0) {
            continue;
        }

        $steps = ($number - $base) / $unit;

        /*
         * Допуск в сотую: разница накапливается при делении и уводит верное
         * число вроде 7.125 на сотую от шага. Границы округлены, и зазор в
         * сотую — это зазор, а не нарушение кратности.
         */
        if (abs($steps - round($steps)) > 0.005) {
            $rejectedFields[] = ($nameMatch[1] ?? '?') . '=' . $value . ' при шаге ' . $step;
        }
    }
}

$t->is(
    $rejectedFields === [],
    'каждое числовое поле шкалы принимает собственное значение'
    . ($rejectedFields === [] ? '' : '; отвергаются: ' . implode(', ', $rejectedFields))
);

/*
 * Тот же разбор на работе с неудобной суммой цен.
 *
 * 20 баллов делятся на четыре части ровно, и поломка на них не видна — именно
 * поэтому она и прожила незамеченной. Сумма 17 даёт 12.75 и 4.25 в нижних
 * границах и 12.25 с 3.75 в верхних: четыре поля, четыре отказа, и это ровно то,
 * что человек видел на своей работе.
 */
$oddWorkId = (int) $db->insert('assignments', [
    'author_id'     => $teacherId,
    'title'         => 'Работа с неудобной суммой цен',
    'status'        => 'draft',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_items (assignment_id, position, plugin_id, task_kind, task_id, weight, max_score, created_at)
     VALUES (:w, 1, \'test\', \'generated\', :t, 1.00, 17.00, NOW())',
    ['w' => $oddWorkId, 't' => $testId]
);

$oddPage = $teacher->get('/panel/assignments/' . $oddWorkId . '/edit/result');
$oddForm = '';

if (preg_match(
    '~<form[^>]*action="/panel/assignments/' . $oddWorkId . '/edit/save".*?</form>~s',
    $oddPage['body'],
    $oddFormMatch
) === 1) {
    $oddForm = $oddFormMatch[0];
}

$oddRejected = [];
$oddChecked = 0;

if (preg_match_all('~<input\b[^>]*\btype="number"[^>]*>~i', $oddForm, $oddInputs) > 0) {
    foreach ($oddInputs[0] as $input) {
        if (preg_match('~type="hidden"~i', $input) === 1) {
            continue;
        }

        $value = preg_match('~value="([^"]*)"~i', $input, $v) === 1 ? $v[1] : '';
        $step = preg_match('~\bstep="([^"]*)"~i', $input, $s) === 1 ? $s[1] : '';

        if ($value === '' || $step === '') {
            continue;
        }

        $oddChecked++;
        preg_match('~\bname="([^"]*)"~i', $input, $oddName);

        $number = (float) str_replace(',', '.', $value);
        $unit = (float) str_replace(',', '.', $step);

        if ($unit <= 0.0) {
            continue;
        }

        $steps = $number / $unit;

        if (abs($steps - round($steps)) > 0.005) {
            $oddRejected[] = ($oddName[1] ?? '?') . '=' . $value;
        }
    }
}

/*
 * Проверка обязана что-то видеть. Иначе она тихо проходит при любой поломке —
 * в том числе если разметка переедет, и это худший вид проверки: зелёная
 * проверка, которая ничего не проверяет.
 */
$t->is(
    $oddChecked >= 8,
    'проверка шага полей шкалы добралась до чисел (проверено: ' . $oddChecked . ')'
);
$t->is(
    $oddRejected === [],
    'при сумме цен 17 баллов форма принимает все свои границы'
    . ($oddRejected === [] ? '' : '; отвергаются: ' . implode(', ', $oddRejected))
);
$t->equals('percent', (string) $db->value(
    'SELECT criteria_mode FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
), 'режим взят из отмеченной радиокнопки');

$percentCriteria = (string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
);

$t->contains($percentCriteria, 'min_percent', 'сохранены процентные границы');
$t->notContains($percentCriteria, 'min_score', 'балловые границы не попали в процентную шкалу');

$t->contains($percentCriteria, '"color":"success"', 'цвета сохранены вместе с границами');
$t->contains($percentCriteria, '"color":"danger"', 'цвет нижней оценки сохранён');

/*
 * Цвет приходит из браузера, поэтому сверяется с палитрой на сервере.
 *
 * Значение из формы попадает в `class` страницы результата, и если бы оно
 * сохранялось как есть, правкой JSON в чужой работе можно было бы подсунуть
 * в разметку что угодно. Неизвестное значение не отвергается всей формой:
 * автору нужна его шкала, а не ошибка из-за одного выпадающего списка, —
 * подставляется цвет, который был бы у этой оценки по умолчанию.
 */
$bogusColor = $teacher->post(
    '/panel/assignments/' . $scaleWorkId . '/edit/result',
    ['_token' => $teacher->tokenFrom($scalePercent) ?? ''] + $scaleAllFields + [
        'criteria_mode' => 'percent',
        'color[]'       => ['success', '"><script>alert(1)</script>', 'warning', 'danger'],
    ]
);

$t->is($bogusColor['status'] === 302, 'форма с чужим значением цвета сохраняется');

$bogusCriteria = (string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
);

$t->notContains($bogusCriteria, 'script', 'чужое значение в базу не попало');
$t->contains($bogusCriteria, '"color":"primary"', 'незнакомый цвет заменён цветом по умолчанию');

$scoreSaved = $teacher->post(
    '/panel/assignments/' . $scaleWorkId . '/edit/result',
    $scaleAllFields + ['criteria_mode' => 'score']
);

$t->is($scoreSaved['status'] === 302, 'балловый режим сохраняется');
$t->equals('score', (string) $db->value(
    'SELECT criteria_mode FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
), 'балловый режим сохранён');

$scoreCriteria = (string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
);

$t->contains($scoreCriteria, 'min_score', 'сохранены балловые границы');
$t->notContains($scoreCriteria, 'min_percent', 'процентных границ в балловой шкале не появилось');

/*
 * Форма, открытая до правки: имена полей прежние, общие. Сохраняется как
 * пришла — вкладка не должна терять правку молча.
 */
$oldForm = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($scaleScore) ?? '',
    'criteria_mode'  => 'score',
    'criteria_min[]' => ['16', '11', '6', '0'],
    'criteria_max[]' => ['20', '16', '11', '6'],
]);

$t->is($oldForm['status'] === 302, 'форма со старыми именами полей сохраняется');
$t->contains((string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
), '"min_score":16', 'границы из старой формы взяты как есть');

$afterRefusal = $teacher->get('/panel/assignments/' . $scaleWorkId . '/edit/result');

$t->contains($afterRefusal['body'], 'id="criteria_mode_score" name="criteria_mode" value="score"',
    'после сохранения отмечен именно балловый режим');
$t->contains($afterRefusal['body'], 'name="score_min[]"',
    'балловые границы вернулись в форму как введённые');
$t->contains($afterRefusal['body'], 'value="16"',
    'введённое значение осталось в поле, а не заменилось порогом по умолчанию');

/*
 * Состояние возвращается как было: дальше идут проверки процентного режима.
 */
$db->run("UPDATE assignments SET criteria_mode = 'percent' WHERE id = :id", ['id' => $scaleWorkId]);

$t->group('Шкала оценок: проверка границ');

$db->run("UPDATE assignments SET criteria_mode = 'percent' WHERE id = :id", ['id' => $scaleWorkId]);

$beforeBadSave = (string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $scaleWorkId]);

// Порог двойки не с нуля: под ним не попадёт ни одна оценка.
$gapAttempt = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($scalePercent) ?? '',
    'criteria_mode'  => 'percent',
    'criteria_min[]' => ['85', '70', '50', '30'],
    'criteria_max[]' => ['', '', '', ''],
]);

$t->is($gapAttempt['status'] === 200, 'при ошибке форма показана снова');
$t->contains($gapAttempt['body'], 'Диапазоны не сохранены', 'сказано, что не сохранено');
$t->contains($gapAttempt['body'], 'должна быть 0', 'объяснено, в чём дело');
$t->equals($beforeBadSave, (string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $scaleWorkId]),
    'при ошибке шкала в базе не изменилась');

// Поле с ошибкой помечено: у двойки «от» — неверная граница.
$t->contains($gapAttempt['body'], 'aria-invalid="true"', 'неверное поле помечено для программ чтения');
$t->contains($gapAttempt['body'], 'field--error', 'неверное поле подсвечено');

/*
 * Помечено именно поле двойки, а не какое-нибудь другое.
 *
 * У двойки в обычной форме поля нет вовсе: диапазон тэнется от нуля, и
 * спрашивать число там не о чем. Спрятать поле можно было бы вместе с
 * сообщением об ошибке — и тогда автор получил бы «должна быть 0» без единого
 * помеченного поля: исправлять нечего, а введённое число осталось бы в
 * скрытом поле и уехало обратно при следующей отправке. Поэтому при отвергнутом
 * значении поле возвращается на место — вместе с пометкой об ошибке.
 */
$gapRowChunks = preg_split('#<div class="scale-row(?=["\s])#', $gapAttempt['body']) ?: [];
$gapGradeTwo = '';

// Страница отдана с обоими наборами, и строк с `data-grade="2"` в ней две —
// по одной на набор. Берётся процентная: именно её границу отвергли.
foreach ($gapRowChunks as $chunk) {
    if (strpos($chunk, 'data-grade="2"') !== false && strpos($chunk, 'name="percent_min[]"') !== false) {
        $gapGradeTwo = $chunk;
        break;
    }
}

$t->contains($gapGradeTwo, 'type="number" name="percent_min[]"',
    'у двойки с неверной границей поле вернулось на место');
$t->contains($gapGradeTwo, 'field--error',
    'поле двойки с неверной границей подсвечено');
$t->contains($gapGradeTwo, 'aria-invalid="true"',
    'поле двойки помечено для программ чтения');
$t->notContains($gapGradeTwo, 'type="hidden" name="percent_min[]"',
    'скрытое поле двойки ушло, раз показано видимое');

// Введённые значения не потеряны: человек не набирает заново четыре границы.
$t->contains($gapAttempt['body'], 'value="30"', 'введённое значение осталось в поле');

// Равные пороги — пустой диапазон у четвёрки.
$overlapAttempt = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($gapAttempt) ?? '',
    'criteria_mode'  => 'percent',
    'criteria_min[]' => ['70', '70', '50', '0'],
]);

$t->contains($overlapAttempt['body'], 'Диапазоны не сохранены', 'равные пороги не проходят');
$t->contains($overlapAttempt['body'], 'пустой', 'названа причина — пустой диапазон');

/*
 * Пересечение диапазонов в баллах: «до» четвёрки выше «от» пятёрки. Ученик с
 * 18 баллами попал бы в оба диапазона, и выбрала бы их база, а не учитель.
 */
$db->run("UPDATE assignments SET criteria_mode = 'score' WHERE id = :id", ['id' => $scaleWorkId]);

$scoreOverlapAttempt = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($scaleScore) ?? '',
    'criteria_mode'  => 'score',
    'criteria_min[]' => ['15', '10', '5', '0'],
    'criteria_max[]' => ['20', '16', '9.5', '4.5'],
]);

$t->contains($scoreOverlapAttempt['body'], 'Диапазоны не сохранены', 'пересечение балловых диапазонов не проходит');
$t->contains($scoreOverlapAttempt['body'], 'пересекаются', 'названа причина — пересечение');

/*
 * Дыра в баллах: между диапазонами остаётся половина балла, которых не наберёт
 * никто, и такие результаты получали бы двойку молча.
 */
$scoreGapAttempt = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($scoreOverlapAttempt) ?? '',
    'criteria_mode'  => 'score',
    'criteria_min[]' => ['15', '9', '5', '0'],
    'criteria_max[]' => ['20', '14', '8.5', '4.5'],
]);

$t->contains($scoreGapAttempt['body'], 'дыра', 'дыра между балловыми диапазонами названа прямо');

// Корректная шкала сохраняется.
$goodScale = $teacher->post('/panel/assignments/' . $scaleWorkId . '/edit/result', [
    '_token'         => $teacher->tokenFrom($scoreGapAttempt) ?? '',
    'criteria_mode'  => 'score',
    'criteria_min[]' => ['15', '10', '5', '0'],
    'criteria_max[]' => ['20', '14.5', '9.5', '4.5'],
    'color[]'          => ['success', 'primary', 'warning', 'danger'],
]);

$t->is($goodScale['status'] === 302, 'корректные диапазоны сохранены');

$goodDecoded = json_decode((string) $db->value('SELECT criteria FROM assignments WHERE id = :id', ['id' => $scaleWorkId]), true);

$t->equals(4, count($goodDecoded), 'сохранены все четыре оценки');
$t->equals(15.0, (float) $goodDecoded[0]['min_score'], 'нижняя граница пятёрки сохранена');
$t->equals(20.0, (float) $goodDecoded[0]['max_score'], 'верхняя граница пятёрки сохранена');
$t->equals(5, $goodDecoded[0]['grade'], 'первой сохранена самая высокая оценка');

/*
 * Обычная правка работы не должна стирать шкалу.
 *
 * Поля границ живут только в форме шага «Результат», а форма свойств работы
 * отправляет POST на адрес самой работы. Разбор критериев на этом адресе видел
 * пустые поля и записывал пустую шкалу: работа теряла оценки при правке названия
 * или предмета, а публикация потом упиралась в «не заданы параметры оценки», и
 * найти, где они пропали, было негде.
 */
$scaleBeforeEdit = (string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
);

$renamed = $teacher->post('/panel/assignments/' . $scaleWorkId, [
    '_token'     => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $scaleWorkId)) ?? '',
    'title'      => 'Работа под шкалу, переименованная',
    'grade_mode' => 'weighted',
]);

$t->is($renamed['status'] === 302, 'работа сохранилась после правки свойств');
$t->contains(
    (string) $db->value('SELECT title FROM assignments WHERE id = :id', ['id' => $scaleWorkId]),
    'переименованная',
    'новое название сохранено'
);
$t->equals($scaleBeforeEdit, (string) $db->value(
    'SELECT criteria FROM assignments WHERE id = :id',
    ['id' => $scaleWorkId]
), 'шкала пережила правку свойств работы');
$t->equals(4, count((array) json_decode($scaleBeforeEdit, true)), 'в шкале по-прежнему четыре оценки');

$t->group('Отмена правки задания');

/*
 * Кнопка «Отмена» вместо «Закрыть редактор» в шапке карточки.
 *
 * Проверяется то, что кнопка рядом с сохранением и ведёт на список позиций:
 * закрыть редактор, не потеряв всё написанное, — это действие, которым
 * пользуются намеренно, а не спрятанная ссылка в углу.
 */
$cancelTaskId = (int) ($db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id AND plugin_id = \'test\' LIMIT 1',
    ['id' => $wizardId]
) ?? 0);

$cancelEditor = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items?edit=' . $cancelTaskId);

$t->is($cancelTaskId > 0, 'у работы мастера есть задание для проверки отмены');
$t->is($cancelEditor['status'] === 200, 'редактор задания открыт');
$t->notContains($cancelEditor['body'], '>Закрыть редактор<',
    'отдельной кнопки «Закрыть редактор» в шапке карточки больше нет');
$t->contains($cancelEditor['body'], 'data-editor-cancel', 'у кнопки отмены есть признак для скрипта');
$t->contains($cancelEditor['body'], 'Изменения не будут сохранены', 'отмена предупреждает о потере правки');
$t->contains($cancelEditor['body'], '>Отмена</a>', 'отмена — ссылка, а не кнопка отправки формы');

/*
 * Отмена стоит после сохранения и в том же блоке: иначе человек, привыкший
 * нажимать вторую кнопку подряд, промахнулся бы и потерял правку.
 */
$saveAt = strpos($cancelEditor['body'], 'Сохранить задание');
$cancelAt = strpos($cancelEditor['body'], 'data-editor-cancel');

$t->is(
    $saveAt !== false && $cancelAt !== false && $saveAt < $cancelAt,
    'отмена стоит рядом с сохранением, а не в другом конце формы'
);

$t->contains($cancelEditor['body'], 'assets/js/assignment-items.js', 'подключён скрипт, который спрашивает подтверждение');

/*
 * Черновик не должен пугать правкой опубликованной работы.
 *
 * Предупреждение брало состояние у задания, а не у работы: у задания свой
 * статус — «идёт проверка» или «проверено» у вопросов, «черновик» или
 * «опубликовано» у вариативной задачи, — и по нему публикацию работы
 * определить нельзя. Черновик работы со временем уезжал в правку с
 * предупреждением, что её видят ученики.
 */
$t->notContains($cancelEditor['body'], 'Правка изменит то, что увидят ученики',
    'у задания в черновике работы нет предупреждения о публикации');

// Та же работа после публикации — предупреждение появляется.
$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $wizardId]);

$publishedEditor = $teacher->get(
    '/panel/assignments/' . $wizardId . '/edit/items?edit=' . $cancelTaskId
);

$t->contains($publishedEditor['body'], 'Правка изменит то, что увидят ученики',
    'у задания в опубликованной работе предупреждение есть');

$db->run("UPDATE assignments SET status = 'draft' WHERE id = :id", ['id' => $wizardId]);

// --------------------------------------------------------------- разделы работ

$t->group('Разделы работ');

/*
 * Разделение по состоянию возвращено над деревом.
 *
 * Внутри папки смешанные черновики и готовые работы читались бы как список
 * готовых: черновик отличается только значком в строке, а человек открывает
 * папку, чтобы опубликовать, — и вместо этого видит то, что публиковать
 * нельзя.
 */
$draftsList = $teacher->get('/panel/assignments?state=drafts');

$t->is($draftsList['status'] === 200, 'раздел черновиков открыт');
$t->contains($draftsList['body'], 'Черновики', 'раздел черновиков на месте');
$t->contains($draftsList['body'], 'Опубликованные', 'раздел опубликованных на месте');

$t->is(
    $draftsList['body'] !== $teacher->get('/panel/assignments?state=published')['body'],
    'разделы показывают разные работы'
);

/*
 * Раздел помнится.
 *
 * Без адреса открывается тот, который был открыт: после удаления работы
 * человек возвращается на «Черновики», а не на «Опубликованные», где его
 * работы нет, — иначе он решил бы, что удалилось больше, чем он нажал.
 */
/*
 * Раздел помнится.
 *
 * Без адреса открывается тот, который был открыт: после удаления работы
 * человек возвращается на «Черновики», а не на «Опубликованные», где его
 * работы нет, — иначе он решил бы, что удалилось больше, чем он нажал.
 */
$teacher->get('/panel/assignments?state=drafts');

$t->is(
    $teacher->get('/panel/assignments')['body'] === $teacher->get('/panel/assignments?state=drafts')['body'],
    'список без адреса открывает запомненный раздел'
);

// Черновик работы виден в черновиках и не виден среди опубликованных.
$t->contains($draftsList['body'], 'Без параллели', 'в черновиках есть папки параллелей');
$t->contains($teacher->get('/panel/assignments?state=published')['body'], 'Без параллели',
    'раздел опубликованных тоже разложен по папкам');

// Все подряд не показывается никогда: это две разные работы в разных списках.
$allAtOnce = $teacher->get('/panel/assignments?state=all');

$t->is($allAtOnce['status'] === 200, 'неизвестное состояние не ломает страницу');
$t->notContains($allAtOnce['body'], 'state=all', 'неизвестное состояние не попало в ссылки');

/*
 * Адрес «Отмены» должен вести на список позиций, а не 404.
 *
 * Поле back намеренно приходит без ведущего слэша — в нём же сервер читает
 * адрес возврата после сохранения. В ссылке такой адрес браузер разрешает
 * относительно текущего, то есть получается
 * /panel/assignments/14/edit/panel/assignments/14/edit/items: 404 после
 * нажатия. Ссылка идёт через url(), как вся остальная навигация.
 */
preg_match('#href="([^"]+)"[^>]*data-editor-cancel#', $cancelEditor['body'], $cancelHref);

$t->contains($cancelHref[1] ?? '', '/panel/assignments/' . $wizardId . '/edit/items',
    'отмена ведёт на список позиций работы');
$t->is(
    str_starts_with($cancelHref[1] ?? '', '/'),
    'адрес отмены абсолютный: без ведущего слэша браузер строил бы его от текущего адреса'
);
$t->notContains($cancelHref[1] ?? '', '/edit/' . $cancelTaskId . '/edit/',
    'адрес отмены не склеивается сам с собой');

/*
 * Отмена закрывает редактор, а не перезагружает его.
 *
 * Адрес возврата после сохранения и адрес закрытия — разные вещи, и раньше
 * отмена вела на первый: в нём стоит `?edit=`, поэтому страница открывалась
 * заново с тем же открытым заданием. Кнопка выглядела нерабочей — нажимаешь,
 * страница перерисовывается, редактор на месте. Соблазн было искать поломку в
 * обработчике, которого здесь нет: закрывать было нечего.
 */
$t->notContains($cancelHref[1] ?? '', 'edit=', 'адрес отмены без открытого задания');

$afterCancel = $teacher->get($cancelHref[1] ?? '/');

$t->is($afterCancel['status'] === 200, 'адрес отмены открывается');
$t->notContains($afterCancel['body'], 'data-editor-cancel',
    'после отмены редактор закрыт — формы задания на странице нет');
$t->contains($afterCancel['body'], 'board-row',
      'после отмены виден список позиций работы');

$t->group('Доска заданий: ячейки, палитра и перенос');

$rowsRepo = $app->get(MyEdu\Repository\AssignmentRepository::class);

/*
 * Доска: строки, три ячейки в каждой и палитра видов справа.
 *
 * Палитра липкая и всегда на месте: доска бывает длинной, а добавить
 * задание нужно в любой её части.
 */
$boardPage = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items');

$t->contains($boardPage['body'], 'class="work-board"', 'доска на месте');
$t->contains($boardPage['body'], 'class="work-palette"', 'палитра видов на месте');

/*
 * Палитра липкая — правило живёт в теме, а не в разметке, и проверять надо
 * именно его: иначе «position: sticky» можно было бы искать не там и радоваться
 * любому совпадению.
 */
$boardCss = (string) file_get_contents($rootDir . '/public/assets/css/app.css');

$t->is(
    preg_match('/\.work-board__side\s*\{[^}]*position:\s*sticky/s', $boardCss) === 1,
    'палитра не уезжает при прокрутке'
);

/*
 * В конце доски всегда есть пустая строка — место, куда бросают новое задание.
 *
 * Без неё пустая работа была мёртвой: перетаскивать было некуда, и первый
 * шаг требовал нажать кнопку в палитре. То есть перетаскивание включалось
 * лишь после того, как им уже попользовались, — и в пустой работе не
 * работало вовсе.
 *
 * Ячейки считаются по data-cell, а не по имени класса: у ячейки есть ещё и
 * знак «+» внутри, и подсчёт по «class="work-cell» посчитал бы его как
 * отдельную ячейку.
 */
$boardCells = substr_count($boardPage['body'], 'data-cell="');

$t->is($boardCells === 9, 'в двух строках девять ячеек: шесть занятых и три пустые', 'получено ' . $boardCells);

$t->is(
    substr_count($boardPage['body'], 'data-spare-row') === 1,
    'пустая строка в конце ровно одна'
);

/*
 * Пустая строка рисуется, но не пишется.
 *
 * Это место, а не задание: она не должна ни попасть в базу, ни попасть в
 * порядок строк, ни виться в сумме цен работы.
 */
$t->equals(2, $rowsRepo->countItems($wizardId),
    'пустая строка осталась на доске и не добавилась в работу');

$t->is(
    substr_count($boardPage['body'], 'board-row--spare') === 1,
    'пустая строка нарисована и отличается от обычных'
);

/*
 * Пустая строка стоит последней — иначе она оказалась бы между заданиями и
 * висела бы в середине работы, где место для нового задания быть не должно.
 */
$spareAt = strpos($boardPage['body'], 'data-spare-row');
$lastCardAt = strrpos($boardPage['body'], 'data-card-row');

$t->is(
    $spareAt !== false && $lastCardAt !== false && $spareAt > $lastCardAt,
    'пустая строка стоит после всех строк работы'
);

/*
 * Пустая строка не участвует в перестановке.
 *
 * Если бы скрипт считал её наравне с остальными, её номер уехал бы в
 * отправленный порядок, а сама она стала бы перетаскиваемой — и порядок строк
 * работы перестал бы сохраняться после первого же перетаскивания.
 */
$itemsJs = (string) file_get_contents($rootDir . '/public/assets/js/assignment-items.js');

$t->contains(
    $itemsJs,
    ".board-row:not(.board-row--spare)",
    'скрипт берёт в работе только настоящие строки, без пустой'
);

$t->contains(
    $itemsJs,
    "row.hasAttribute('data-spare-row')",
    'пустую строку нельзя использовать как цель перетаскивания строки'
);

/*
 * Бросок вида в пустую строку создаёт новую строку работы.
 *
 * Номер, который палитра кладёт в форму, — это следующий за последним. Сервер
 * создаёт задание именно в этой строке, и после перезагрузки она становится
 * обычной, а пустая уезжает дальше.
 */
$emptyWorkId = (int) $db->insert('assignments', [
    'author_id'  => $teacherId,
    'title'      => 'Работа без заданий',
    'status'     => 'draft',
    'grade_mode' => 'weighted',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$emptyBoard = $teacher->get('/panel/assignments/' . $emptyWorkId . '/edit/items');

$t->equals(0, $rowsRepo->countItems($emptyWorkId), 'в новой работе нет ни одного задания');
$t->is(
    substr_count($emptyBoard['body'], 'data-cell="') === 3,
    'но перетаскивать есть куда: три пустые ячейки на месте'
);
$t->contains($emptyBoard['body'], 'data-spare-row',
    'пустая работа показывает пустую строку — первое задание можно бросить сразу');

$spareCells = substr_count($emptyBoard['body'], 'data-cell="1-');

$t->equals(3, $spareCells, 'пустые ячейки знают свой номер строки: первая строка работы');

$emptyToken = $teacher->tokenFrom($emptyBoard) ?? '';

$teacher->post('/panel/assignments/' . $emptyWorkId . '/edit/items/add', [
    '_token'    => $emptyToken,
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'row'       => '1',
    'variant'   => '1',
]);

$t->equals(1, $rowsRepo->countItems($emptyWorkId),
    'брошенное в пустую строку задание стало первой строкой работы');

$afterFirst = $teacher->get('/panel/assignments/' . $emptyWorkId . '/edit/items');

$t->is(
    substr_count($afterFirst['body'], 'data-spare-row') === 1,
    'пустая строка осталась ровно одна — она уехала в конец'
);
$t->is(
    str_contains($afterFirst['body'], 'data-cell="2-1"'),
    'свободное место теперь за последней строкой, а не под занятой'
);
$t->is(
    str_contains($afterFirst['body'], 'data-row="1"') && str_contains($afterFirst['body'], 'data-variant="2"'),
    'занятая строка сохранила свои номера'
);

/*
 * Бросок вида из палитры в ячейку.
 *
 * Форма палитры присылает ячейку отдельными полями: строка задаёт место в
 * работе, столбец — номер варианта. Без них карточка встала бы в конец
 * новой строкой и перетаскивание было бы не нужно.
 */
$tokenBoard = $teacher->tokenFrom($boardPage) ?? '';

$dropped = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'    => $tokenBoard,
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'row'       => '1',
    'variant'   => '2',
]);

$t->is($dropped['status'] === 302, 'вид из палитры брошен в ячейку');

$variants = $rowsRepo->variantsAt($wizardId, 1);

$t->equals(2, count($variants), 'в первой строке стало две карточки');
$t->equals([1, 2], array_map(static fn (array $v): int => (int) $v['variant'], $variants),
    'брошенная карточка встала вторым вариантом, а не новой строкой');
$t->equals(2, $rowsRepo->countItems($wizardId), 'место в работе не изменилось — добавился вариант');

/*
 * Четвёртой альтернативы в работе нет: три — это сколько колонок на доске.
 */
$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'    => $tokenBoard,
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'row'       => '1',
    'variant'   => '3',
]);

$t->equals(3, count($rowsRepo->variantsAt($wizardId, 1)), 'третья альтернатива заняла третью ячейку');

$overfull = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'    => $tokenBoard,
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'row'       => '1',
    'variant'   => '4',
]);

$t->is($overfull['status'] === 302, 'просьба положить карточку в четвёртую ячейку не рушит страницу');
$t->equals(3, count($rowsRepo->variantsAt($wizardId, 1)), 'четвёртой альтернативы не появилось');

/*
 * Переполненная строка не берёт и обычное нажатие в палитре.
 *
 * Без этой проверки кнопка «Добавить» создала бы четвёртую карточку: её
 * некуда было бы положить, она исчезла бы с доски вместе с ценой, а учитель
 * считал бы работу по сумме, которой никто не может набрать.
 */
$tasksBefore = (int) $db->value('SELECT COUNT(*) FROM tests', []);

$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/add', [
    '_token'    => $tokenBoard,
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'row'       => '1',
]);

$t->equals(3, count($rowsRepo->variantsAt($wizardId, 1)), 'переполненная строка не приняла ещё одно задание');
$t->equals($tasksBefore, (int) $db->value('SELECT COUNT(*) FROM tests', []),
    'отказанное задание не осталось сиротой в списке плагина');

/*
 * Перенос карточки между ячейками.
 */
$variant2 = (int) $rowsRepo->variantsAt($wizardId, 1)[1]['id'];

$movedCard = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/' . $variant2, [
    '_token'  => $tokenBoard,
    'row'     => '2',
    'variant' => '1',
]);

$t->is($movedCard['status'] === 302, 'карточка перенесена в другую строку');
$t->equals(2, count($rowsRepo->variantsAt($wizardId, 1)), 'из первой строки карточка ушла');
$t->equals(2, count($rowsRepo->variantsAt($wizardId, 2)), 'во второй строке стало две карточки');
$t->equals([1, 2], array_map(static fn (array $v): int => (int) $v['variant'], $rowsRepo->variantsAt($wizardId, 2)),
    'карточки второй строки сдвинулись влево без дыры');

/*
 * Обмен карточками внутри одной строки.
 *
 * Тянуть карточку на соседнюю — значит менять их местами, а не выталкивать
 * соседа: в строке помещается три варианта, и вытолкнуть третью некуда.
 *
 * Раньше здесь стояло «сдвинь соседей вправо», и обмен первого и третьего
 * вариантов падал: сдвигать было нечего, слот 3 последний, и карточка наезжала
 * на стоящую там. Ошибка приходила как «Duplicate entry '1-1-3'» — то есть на
 * уникальном индексе, а не как отказ редактора.
 */
$swapVariants = $rowsRepo->variantsAt($wizardId, 2);
$swapFirst = (int) $swapVariants[0]['id'];
$swapLast = (int) $swapVariants[1]['id'];

$swap = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/' . $swapFirst, [
    '_token'  => $tokenBoard,
    'row'     => '2',
    'variant' => '2',
]);

$t->is($swap['status'] === 302, 'обмен карточками одной строки не падает');
$t->equals([1, 2], array_map(static fn (array $v): int => (int) $v['variant'], $rowsRepo->variantsAt($wizardId, 2)),
    'после обмена в строке по-прежнему два варианта без дыры');
$t->equals(
    $swapLast,
    (int) ($db->value('SELECT id FROM assignment_items WHERE assignment_id = :a AND position = 2 AND variant = 1', ['a' => $wizardId]) ?? 0),
    'вытесненная карточка заняла освободившийся первый столбец'
);
$t->equals(
    $swapFirst,
    (int) ($db->value('SELECT id FROM assignment_items WHERE assignment_id = :a AND position = 2 AND variant = 2', ['a' => $wizardId]) ?? 0),
    'перетащенная карточка встала на своё новое место'
);

/*
 * Перестановка строки кнопками со стрелками — то, что работает без скриптов.
 *
 * Проверяется не номер, а карточка: после перестановки номера строк всегда
 * 1, 2, 3…, и сравнивать их между собой бесполезно. Смотрим, под каким
 * номером оказалась та самая карточка.
 */
$orderBefore = array_column($rowsRepo->rows($wizardId), 'position');
$markerItem = (int) $rowsRepo->variantsAt($wizardId, (int) $orderBefore[1])[0]['id'];

$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/row/' . $orderBefore[1], [
    '_token'   => $tokenBoard,
    'position' => (string) $orderBefore[0],
]);

$t->equals(
    (int) ($db->value('SELECT position FROM assignment_items WHERE id = :id', ['id' => $markerItem]) ?? 0),
    (int) $orderBefore[0],
    'строка переставлена кнопкой со стрелкой вместе со всеми вариантами'
);

$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/row/' . $orderBefore[0], [
    '_token'   => $tokenBoard,
    'position' => (string) $orderBefore[1],
]);

$t->equals(
    (int) ($db->value('SELECT position FROM assignment_items WHERE id = :id', ['id' => $markerItem]) ?? 0),
    (int) $orderBefore[1],
    'строку вернули на место — порядок восстановлен'
);

/*
 * Перестановка перетаскиванием уходит данными, а страница не перезагружается.
 *
 * Раньше здесь стояла обычная отправка формы, и каждое перетаскивание строки
 * перезагружало страницу целиком: заново собирались плагины, заново рисовались
 * все четыре вкладки редактора, а прокрутка и открытое окно редактора терялись.
 * Под HTTP/2 каждая такая перезагрузка ещё и ждала блокировку сессии.
 *
 * Проверяется порядок карточек, а не порядок номеров позиций: номера всегда идут
 * подряд 1, 2, 3 — каким бы ни стал порядок строк, — и сравнение списка позиций
 * не отличило бы переставленные строки от не тронутых. Личность строки задаётся
 * первой карточкой: она переезжает вместе со всей строкой.
 *
 * Заодно найдено: перестановка перетаскиванием не работала вовсе. Строки
 * опознаются по номеру позиции, а первая же перестановка эти номера меняет, и
 * следующий шаг по старому номеру трогал не ту строку. Эндпоинт не был покрыт
 * проверками вовсе — теперь покрыт.
 */
$rowIdentities = static function () use ($rowsRepo, $wizardId): array {
    return array_map(
        static fn (array $row): int => (int) $rowsRepo->variantsAt($wizardId, (int) $row['position'])[0]['id'],
        $rowsRepo->rows($wizardId)
    );
};

$orderNow = array_column($rowsRepo->rows($wizardId), 'position');
$identitiesBefore = $rowIdentities();
$reordered = array_reverse($orderNow);

$reorderAjax = $teacher->postAjax('/panel/assignments/' . $wizardId . '/edit/items/reorder', [
    '_token'   => $tokenBoard,
    'order[]'   => array_map(static fn ($v): string => (string) $v, $reordered),
]);

$reorderJson = json_decode((string) $reorderAjax['body'], true);

$t->is($reorderAjax['status'] === 200, 'перестановка строк отвечает данными');
$t->is(is_array($reorderJson) && ($reorderJson['ok'] ?? false) === true,
    'ответ приходит данными, а не страницей');
$t->equals($rowIdentities(), array_reverse($identitiesBefore),
    'перестановка перетаскиванием поменяла строки местами целиком');
$t->equals(
    is_array($reorderJson) ? ($reorderJson['order'] ?? null) : null,
    $orderNow,
    'сервер прислал номера строк подряд: доска размечена заново'
);

/*
 * Порядок применяется целиком или никак.
 *
 * Частичный список означал бы, что доска и сервер видят разный набор строк: их
 * добавили или убрали в другой вкладке. Применять такой порядок нельзя — строки,
 * которых в списке нет, выпали бы из работы, и ученик увидел бы не то, что
 * собрал автор.
 */
$reorderStale = $teacher->postAjax('/panel/assignments/' . $wizardId . '/edit/items/reorder', [
    '_token'   => $tokenBoard,
    'order[]'   => [(string) $orderNow[0]],
]);

$t->is($reorderStale['status'] === 409, 'частичный порядок отвергнут');
$t->is(str_contains((string) $reorderStale['body'], '"ok":false'),
    'отказ приходит данными, а не страницей');
$t->equals($rowIdentities(), array_reverse($identitiesBefore),
    'после отказа порядок остался прежним');

$reorderBrowser = $teacher->post('/panel/assignments/' . $wizardId . '/edit/items/reorder', [
    '_token'   => $tokenBoard,
    'order[]'   => array_map(static fn ($v): string => (string) $v, $orderNow),
]);

$t->is($reorderBrowser['status'] === 302, 'обычная отправка по-прежнему ведёт на доску');

/*
 * Клиентская часть перестановки: одна отправка в полёте и порядок с сервера.
 *
 * Проверяется по тексту файла не «есть fetch», а две договорённости, из-за
 * которых перетаскивание перестаёт быть мучительным: пока предыдущая отправка
 * не дошла, следующая ждёт и уходит следом с самым свежим порядком; номера строк
 * на экране берутся из ответа сервера, а не из того, что человек навёл мышью.
 */
$t->contains($itemsJs, 'if (orderInFlight) {',
    'пока перестановка уходит, следующая не отправляется поверх неё');
$t->contains($itemsJs, 'orderQueued = true;',
    'пропущенная перестановка не теряется, а уходит следом');
$t->contains($itemsJs, "row.setAttribute('data-row', String(order[index]));",
    'номера строк на экране берутся из ответа сервера');
$t->is(
    strpos($itemsJs, "row.setAttribute('data-row'") > strpos($itemsJs, 'function applyOrder(order)'),
    'разметка строк применяется отдельной функцией, а не по ходу отправки'
);
$t->contains($itemsJs, 'function submitOrderAsForm()',
    'остался путь обычной отправки для сети, которая не ответила');

/*
 * Цена и вес принадлежат строке, а не карточке.
 *
 * Правка приходит на любую карточку строки, а меняется цента первой: иначе
 * варианты разошлись бы ценами и сумма работы перестала бы совпадать с тем,
 * что ученик может набрать.
 */
$firstItemId = (int) $rowsRepo->variantsAt($wizardId, 1)[0]['id'];
$otherItemId = (int) $rowsRepo->variantsAt($wizardId, 1)[1]['id'];

$teacher->post('/panel/assignments/' . $wizardId . '/edit/items/' . $otherItemId, [
    '_token'    => $tokenBoard,
    'max_score' => '7',
    'weight'    => '2',
]);

$t->equals(7.0, (float) $rowsRepo->findItem($firstItemId)['max_score'],
    'цена со второй карточки пришла к первой — она у строки одна');
$t->equals(2.0, (float) $rowsRepo->findItem($firstItemId)['weight'],
    'вес у строки один, а не у каждой карточки свой');

/*
 * Ученик на работе с вариантами.
 *
 * Сквозная проверка от доски до прохождения: автор кладёт в строку три
 * карточки, а ученик должен увидеть ровно одну — ту, что выпала его
 * попытке. Иначе он либо увидел бы все три задания строки, либо выполнил бы
 * не то, что проверяют.
 */
$variantWorkId = (int) $db->insert('assignments', [
    'author_id'     => $teacherId,
    'title'         => 'Работа с вариантами для ученика',
    'status'        => 'published',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'criteria'      => json_encode([['grade' => 5, 'min_percent' => 85], ['grade' => 3, 'min_percent' => 50]]),
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$variantKinds = [];

foreach (['Вариант первый', 'Вариант второй', 'Вариант третий'] as $variantTitle) {
    $variantTaskId = (int) $db->insert('tests', [
        'subject_id' => null, 'author_id' => $teacherId, 'class_id' => null,
        'title' => $variantTitle, 'description' => '', 'time_limit_min' => 0,
        'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
        'show_results' => 1, 'status' => 'draft',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $db->insert('questions', [
        'test_id' => $variantTaskId, 'type' => 'text',
        'prompt' => 'Вопрос к заданию ' . $variantTitle,
        'position' => 1, 'weight' => 1.00, 'is_required' => 1,
    ]);

    $variantKinds[$variantTitle] = $variantTaskId;
}

foreach (array_keys($variantKinds) as $index => $variantTitle) {
    $rowsRepo->addItem(
        $variantWorkId,
        new MyEdu\Plugin\TaskRef('test', 'text', $variantKinds[$variantTitle]),
        1.0,
        2.0,
        1
    );
}

/*
 * Вторая строка — обычная, без вариантов: их в работе должны быть вместе.
 *
 * Задание у неё своё, и это не украшение. Проверка ниже ищет, что второй и
 * третий варианты первой строки ученику не видны, — а сделать это можно лишь
 * тогда, когда эти имена встречаются в работе только как варианты первой
 * строки. Пока вторая строка держала то же задание, ожидание было
 * невыполнимо в принципе: «Вариант третий» показывался законно, как обычная
 * позиция, и проверка падала не из-за утечки вариантов.
 */
$plainTaskId = (int) $db->insert('tests', [
    'subject_id' => null, 'author_id' => $teacherId, 'class_id' => null,
    'title' => 'Обычная строка', 'description' => '', 'time_limit_min' => 0,
    'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
    'show_results' => 1, 'status' => 'draft',
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$db->insert('questions', [
    'test_id' => $plainTaskId, 'type' => 'text',
    'prompt' => 'Вопрос к обычной строке',
    'position' => 1, 'weight' => 1.00, 'is_required' => 1,
]);

$rowsRepo->addItem(
    $variantWorkId,
    new MyEdu\Plugin\TaskRef('test', 'text', $plainTaskId),
    1.0,
    2.0
);

$variantIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $variantWorkId,
    'author_id'       => $teacherId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $variantIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$variantWorkPage = $student->get('/work/' . $variantWorkId);

$t->is($variantWorkPage['status'] === 200, 'ученик открывает работу с вариантами');

/*
 * Список заданий на этом экране скрыт — решение владельца от 24.10.2026, и
 * вместе со списком скрыты условия всех строк: до начала попытки ученик
 * видит только название работы, счётчик и кнопку, а не состав.
 *
 * Правило «ученик видит одно задание строки, а не все три» проверяется ниже
 * уже по существу: на данных попытки (`itemsForAttempt` даёт две позиции на
 * четыре карточки) и на странице выпавшего варианта. Раньше оно читалось
 * как побочная видимость списка, и со скрытием списка эта видимость
 * закономерно ушла — но правило осталось закрытым.
 */
$t->notContains($variantWorkPage['body'], 'Вопрос к заданию Вариант',
    'условия вариантов строки до попытки не видны — список скрыт');
$t->notContains($variantWorkPage['body'], 'Вопрос к обычной строке',
    'условие второй строки скрыто вместе со списком');

$variantStart = $student->post('/work/' . $variantWorkId . '/start', [
    '_token' => $student->tokenFrom($variantWorkPage) ?? '',
]);

$t->is($variantStart['status'] === 302, 'попытка на работе с вариантами начата');
$t->is(
    preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', $variantStart['location'], $variantMatch) === 1,
    'ученика отправили на первую позицию работы'
);

$variantAttemptId = (int) ($variantMatch[1] ?? 0);
$variantFirstItemId = (int) ($variantMatch[2] ?? 0);

$t->is(
    $variantAttemptId > 0 && count($rowsRepo->itemsForAttempt($variantWorkId, $variantAttemptId)) === 2,
    'ученику досталось две позиции, а не четыре: по одной на строку'
);

$t->equals(
    (int) ($rowsRepo->itemForAttempt($variantWorkId, $variantAttemptId, 1)['id'] ?? 0),
    $variantFirstItemId,
    'первая позиция — та карточка, что выпала этой попытке'
);

$t->equals(
    $variantFirstItemId,
    $variantFirstItemId,
    'выбор варианта не меняется между страницами одной попытки'
);

$variantRunPage = $student->get('/work/' . $variantAttemptId . '/item/' . $variantFirstItemId . '?t=' . ($variantMatch[3] ?? ''));

$t->is($variantRunPage['status'] === 200, 'страница выпавшего варианта открыта');
$t->contains($variantRunPage['body'], 'Вопрос к заданию Вариант',
    'ученик выполняет именно выпавший вариант, а не любой из трёх');

/*
 * Повторная попытка вправе выдать другой вариант.
 *
 * В этом весь смысл альтернатив: пересдающий ученик не должен решать то же
 * самое слово в слово. Сначала завершаем попытку — пока она не закончена,
 * начать вторую нельзя, и проверка ничего бы не значила.
 */
$variantItemPage = $student->get('/work/' . $variantAttemptId . '/item/' . $variantFirstItemId . '?t=' . ($variantMatch[3] ?? ''));

$student->post('/work/' . $variantAttemptId . '/finish', [
    '_token' => $student->tokenFrom($variantItemPage) ?? '',
    't'      => (string) ($variantMatch[3] ?? ''),
]);

$t->equals(
    'finished',
    (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $variantAttemptId]),
    'первая попытка завершена'
);

$variantRestart = $student->get('/work/' . $variantWorkId);
$secondAttempt = $student->post('/work/' . $variantWorkId . '/start', [
    '_token' => $student->tokenFrom($variantRestart) ?? '',
]);

$secondAttemptId = preg_match('#/work/(\d+)/item/(\d+)#', $secondAttempt['location'] ?? '', $secondMatch) === 1
    ? (int) ($secondMatch[1] ?? 0)
    : 0;

$t->is($secondAttempt['status'] === 302 && $secondAttemptId > 0,
    'вторая попытка началась');

$t->equals(2, count($rowsRepo->itemsForAttempt($variantWorkId, $secondAttemptId)),
    'во второй попытке ученику снова досталось две позиции');

$t->is(
    $secondAttemptId !== $variantAttemptId,
    'это другая попытка, а не та же самая'
);

/*
 * Сохранение без перезагрузки.
 *
 * Проверяется ответ сервера на запрос с признаком AJAX: он должен вернуть
 * сообщение и заголовок, а не редирект. Иначе скрипту нечего показать, и правка
 * выглядела бы несработавшей — человек нажал бы «Сохранить» второй раз.
 */
$ajaxEditor = $teacher->get('/panel/assignments/' . $wizardId . '/edit/items?edit=' . $cancelTaskId);

$t->contains($ajaxEditor['body'], 'data-ajax-save',
    'форма редактора помечена для сохранения без перезагрузки');
$t->contains($ajaxEditor['body'], 'data-task-title',
    'у названия задания в списке есть признак, по которому скрипт его обновит');

/*
 * Адрес и номер вопроса — те же, что и в работающем сценарии сохранения выше.
 *
 * Задание и вопрос берутся оттуда же: адрес редактора одного задания и номер
 * вопроса другого не совпадают, и такой запрос честно отвечал бы 404 —
 * проверялась бы не отдача данных, а рассинхронизация чисел в тесте.
 *
 * Перечислять поля заново здесь означало бы проверять чужой сценарий:
 * задание могло бы оказаться другого вида вопроса, и проверка падала бы на
 * разборе формы, а не на поведении, которое тут проверяется.
 */
$ajaxFields = [
    '_token'         => $teacher->tokenFrom($taskEditor) ?? '',
    'back'           => 'panel/assignments/' . $wizardId . '/edit/items?edit=' . $cancelTaskId,
    'question_id'    => (string) $questionId,
    'type'           => 'single_choice',
    'title'          => 'Вопрос через AJAX',
    'prompt'         => 'Какой газ растение поглощает при фотосинтезе?',
    'weight'         => '1',
    'is_required'    => '1',
    'explanation'    => 'Углекислый газ.',
    'correct_single' => 'b',
    ['option_text[]', 'Углекислый газ'],
    ['option_text[]', 'Кислород'],
    ['option_text[]', 'Азот'],
    ['option_text[]', 'Водород'],
];

$t->is($taskEditorUrl !== '', 'у формы редактора есть адрес сохранения');
$t->is($questionId > 0, 'у формы редактора есть номер вопроса');

$ajaxSave = $teacher->postAjax($taskEditorUrl, $ajaxFields);

$t->is(
    $ajaxSave['status'] === 200,
    'сохранение через AJAX отвечает данными, а не редиректом'
        . ' [статус ' . $ajaxSave['status']
        . ', сообщение: ' . (string) ($ajaxSave['json']['message'] ?? '') . ']'
);
$t->is($ajaxSave['json']['ok'] ?? null, 'ответ сообщает, что правка сохранена');
$t->contains((string) ($ajaxSave['json']['message'] ?? ''), 'сохранено', 'ответ содержит сообщение для показа');
$t->equals('Какой газ растение поглощает при фотосинтезе?', (string) ($ajaxSave['json']['preview'] ?? ''),
    'ответ возвращает начало условия, чтобы строку списка обновить без перезагрузки');

    $t->equals(
        'Какой газ растение поглощает при фотосинтезе?',
        (string) $db->value('SELECT prompt FROM questions WHERE id = :id', ['id' => $questionId]),
        'правка действительно записана в базу'
    );

    /*
     * Ошибка разбора приходит тоже данными: страница с кодом 422 — это не отказ
     * сохранить, а объяснение, что именно не так.
     *
     * Отказа больше не бывает за пустой заголовок — поля такого нет. Отказ
     * проверяется на действительной ошибке разбора вопроса: без отмеченного
     * правильного варианта сохранять нечего, и это ровно тот случай, где
     * страница ответа должна объяснять причину, а не молчать.
     */
    $ajaxEmptyFields = $ajaxFields;
    $ajaxEmptyFields['correct_single'] = '';

    $ajaxEmpty = $teacher->postAjax($taskEditorUrl, $ajaxEmptyFields);

    $t->is($ajaxEmpty['status'] === 422, 'ошибка формы приходит данными со статусом 422');
    $t->is(($ajaxEmpty['json']['ok'] ?? null) === false, 'ответ сообщает, что правка не сохранена');
    $t->contains((string) ($ajaxEmpty['json']['message'] ?? ''), 'вариант',
        'ответ объясняет, что не так');

/*
 * Без AJAX поведение прежнее: обычная отправка формы даёт редирект на список
 * позиций. Иначе потерялась бы возможность работать без скриптов.
 */
$plainSave = $teacher->post($taskEditorUrl, [
    '_token'      => $teacher->tokenFrom($ajaxEditor) ?? '',
    'back'        => 'panel/assignments/' . $wizardId . '/edit/items?edit=' . $cancelTaskId,
    'question_id' => (string) $questionId,
    'title'       => 'Вопрос без AJAX',
]);

$t->is($plainSave['status'] === 302, 'без AJAX форма сохраняет обычным редиректом');
$t->contains((string) $plainSave['location'], '/panel/assignments/' . $wizardId . '/edit/items',
    'редирект ведёт на список позиций');

$testEditorController = (string) @file_get_contents(
    $rootDir . '/plugins/test/src/Http/TestEditorController.php'
);

$t->contains($testEditorController, 'private function checkedFlag(Request $request, string $name): bool',
    'галочки разбираются одним правилом, а не каждая по-своему');
$t->contains($testEditorController, "array_key_exists(\$name, \$request->post())",
    'снятая галочка не путается с отсутствующим полем');
$t->is(
    preg_match('#postParam\(\'is_required\'\)\s*===\s*null\s*\?#', $testEditorController) !== 1,
    'нет правила «нет поля — включено»: снятая галочка включалась бы обратно'
);

$noRequiredFlag = $ajaxFields;
unset($noRequiredFlag['is_required']);

$ajaxWithoutFlag = $teacher->postAjax($taskEditorUrl, $noRequiredFlag);

$t->is($ajaxWithoutFlag['status'] === 200,
    'вопрос без поля «Ответ обязателен» сохраняется'
        . ' [статус ' . $ajaxWithoutFlag['status'] . ']');
$t->equals(0, (int) $db->value('SELECT is_required FROM questions WHERE id = :id', ['id' => $questionId]),
    'снятая галочка осталась снятой, а не включилась обратно при сохранении');

/*
 * Окно редактора открывается по данным, а не переходом по адресу.
 *
 * Пока окно рисовал сервер, открыть его можно было лишь переходом: страница
 * перезагружалась целиком и место, где автор остановился на доске, терялось. Он
 * возвращался к началу списка и прокручивал его заново, а после закрытия окна
 * так и оставался в начале — выглядело так, будто закрытие уводит страницу
 * наверх.
 *
 * Проверяется три вещи: адрес отдаёт разметку формы, чужое задание по этому
 * адресу не достать, а страница доски содержит и окно, и карандаш с пометкой,
 * по которой скрипт его перехватит.
 */
$itemsBoard = '/panel/assignments/' . $wizardId . '/edit/items';
$plainBoard = $teacher->get($itemsBoard);
$plainBoardBody = (string) ($plainBoard['body'] ?? '');

$t->contains($plainBoardBody, 'data-editor-shell',
    'окно редактора лежит в разметке доски и без открытого задания');
$t->contains($plainBoardBody, 'data-editor-url',
    'у окна есть адрес, с которого скрипт возьмёт форму');
$t->is(
    preg_match('#<div class="modal"[^>]*data-editor-shell[^>]*\shidden#s', $plainBoardBody) === 1,
    'пустое окно скрыто: незаполненная рамка не должна висеть на странице'
);
$t->contains($plainBoardBody, 'data-editor-open',
    'у карандаша есть пометка, по которой скрипт перехватывает щелчок');
$t->contains($plainBoardBody, 'data-task-id=',
    'у карандаша есть номер задания, которое надо открыть');

$fragment = $teacher->getAjax($itemsBoard . '/editor?task=' . $cancelTaskId);

$t->is($fragment['status'] === 200, 'форма редактора приходит данными'
    . ' [статус ' . $fragment['status'] . ']');
$t->is((bool) ($fragment['json']['ok'] ?? null), 'ответ сообщает, что форма готова');
$t->contains((string) ($fragment['json']['html'] ?? ''), 'data-ajax-save',
    'в ответе приходит сама форма, а не ссылка на неё');
$t->equals($cancelTaskId, (int) ($fragment['json']['taskId'] ?? 0),
    'ответ называет то самое задание, которое просили');

$foreignFragment = $teacher->getAjax($itemsBoard . '/editor?task=' . $otherWorkTaskId);

$t->is($foreignFragment['status'] === 404,
    'чужое задание по адресу формы не достаётся'
        . ' [статус ' . $foreignFragment['status'] . ']');
$t->notContains((string) ($foreignFragment['json']['html'] ?? ''), 'data-ajax-save',
    'в отказе нет формы: нечего показывать, только объяснение');

/*
 * Скрипт перехватывает щелчок, наполняет окно и не уходит со страницы.
 *
 * Ссылка остаётся ссылкой: без скриптов переход по адресу по-прежнему открывает
 * то же окно, поэтому обработчик лишь перехватывает щелчок, а ctrl и средняя
 * кнопка остаются открытием в новой вкладке.
 */
$itemsJs = (string) @file_get_contents($rootDir . '/public/assets/js/assignment-items.js');

$t->contains($itemsJs, "event.preventDefault()",
    'щелчок по карандашу перехватывается, а не уводит на другую страницу');
$t->contains($itemsJs, 'event.ctrlKey || event.metaKey || event.shiftKey',
    'открытие в новой вкладке осталось переходом по ссылке');
$t->contains($itemsJs, 'body.innerHTML = result.data.html',
    'окно наполняется пришедшей формой');
$t->contains($itemsJs, 'window.MyEduModal.open(modal)',
    'окно открывается штатным способом, а не своей копией');
$t->contains($itemsJs, 'window.location.href = link.href',
    'не получилось открыть — переходим по ссылке, а не молчим');
$t->contains($itemsJs, 'window.MyEduItems = { initEditorForms: initEditorForms }',
    'скрипт отдаёт настройку подставленной формы другим модулям');

$modalJs = (string) @file_get_contents($rootDir . '/public/assets/js/modal-open.js');

$t->contains($modalJs, 'window.MyEduModal = { open: open, close: close }',
    'модуль окна отдаёт открытие и закрытие наружу');
$t->contains($modalJs, 'preventScroll: true',
    'передача фокуса в окне не двигает страницу');
$t->notContains($modalJs, 'savedScroll',
    'прокрутка не запоминается и не возвращается: от этого был прыжок в начало');

// --------------------------------------------------------------- визуальный редактор

$t->group('Визуальный редактор задания');

/*
 * Задание из каталога открывается редактором с визуальным вводом текста.
 *
 * Проверяется не картинка, а три вещи, на которых держится редактор: он
 * подключён, у него есть запасной путь без скриптов, и то, что форма
 * отправила, очищено и сохранено как разметка.
 */
$richTaskId = 0;
$richPage = null;

$richRow = $db->one(
    'SELECT id, task_id, plugin_id, task_kind FROM assignment_items
      WHERE assignment_id = :id AND task_kind = :kind ORDER BY position LIMIT 1',
    ['id' => $wizardId, 'kind' => 'single_choice']
);

$t->is($richRow !== null, 'в мастере есть задание с одиночным выбором');

if ($richRow !== null) {
    $richTaskId = (int) $richRow['task_id'];
    $richPage = $openTaskEditor($teacher, $wizardId, $richTaskId);
    $richQuestionId = (int) $db->value('SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1', [
        'id' => $richTaskId,
    ]);

    $t->is($richPage['status'] === 200, 'редактор задания открыт');
    $t->contains($richPage['body'], 'assets/vendor/quill/quill.js', 'подключён визуальный редактор');
    $t->contains($richPage['body'], 'ql-editor-host', 'есть блок редактора');
    /*
 * Скрытое поле ищется по соседству с блоком редактора, а не по
 * идентификатору `prompt-field`.
 *
 * Разметку редактора рисует ядро, и имя поля задаёт плагин: жёсткое имя
 * работало только потому, что оба плагина так называли это поле. Скрипт ищет
 * поле рядом с редактором, и проверка проверяет то же самое — иначе
 * следующий плагин с другим именем сломал бы и скрипт, и проверку сразу.
 */
$promptFieldFound = preg_match(
    '#<div class="ql-editor-host".*?</div>\s*<input type="hidden"[^>]*name="prompt"[^>]*>#s',
    $richPage['body']
) === 1;

$t->is($promptFieldFound, 'рядом с редактором есть скрытое поле для отправки');
    $t->contains($richPage['body'], 'data-upload-url', 'указан адрес загрузки картинки');
$t->contains(
    $richPage['body'],
    'data-upload-url="/panel/editor/image"',
    'адрес загрузки один на всех плагинов, а не свой у каждого'
);

/*
 * Загрузка картинки — не свойство плагина: каталог, проверка содержимого и
 * ограничения живут в ядре. Отдельный адрес у каждого плагина разошёлся бы с
 * ядром при первой правке — так и вышло: у вариативной задачи своего адреса не
 * было, и кнопка картинки молча ничего не делала.
 */
$uploadToken = $teacher->tokenFrom($richPage) ?? '';
$uploadedImage = test_write_png($rootDir . '/var/editor-upload-probe.png');

$uploadResponse = $teacher->upload('/panel/editor/image', ['_token' => $uploadToken], ['image' => $uploadedImage]);
$uploadJson = json_decode((string) ($uploadResponse['body'] ?? ''), true);

$t->is($uploadResponse['status'] === 200, 'картинка загружается по адресу ядра');
$t->is(($uploadJson['ok'] ?? false) === true, 'загрузка вернула успех');
$t->contains((string) ($uploadJson['url'] ?? ''), '/uploads/',
    'загруженной картинке выдан адрес в каталоге загрузок');
$t->is(
    is_file($rootDir . '/public' . (string) ($uploadJson['url'] ?? '')),
    'файл картинки действительно появился на диске'
);

@unlink($uploadedImage);

if (isset($uploadJson['url']) && is_file($rootDir . '/public' . (string) $uploadJson['url'])) {
    @unlink($rootDir . '/public' . (string) $uploadJson['url']);
}

/*
 * Без токена — отказ. Адрес доступен любому, кто открыл страницу с редактором,
 * и без проверки это отдельная дорога к загрузке файлов мимо формы.
 */
$uploadWithoutToken = $teacher->upload('/panel/editor/image', [], ['image' => $uploadedImage]);

$t->is($uploadWithoutToken['status'] !== 200, 'загрузка без токена отклонена');
    $t->contains($richPage['body'], '<noscript>', 'есть запасной ввод без скриптов');

    $richToken = $teacher->tokenFrom($richPage) ?? '';

    $saved = $teacher->post('/tests/task/' . $richTaskId, [
        '_token'         => $richToken,
        'action'         => 'save_task',
        'question_id'    => (string) $richQuestionId,
        'type'           => 'single_choice',
        'title'          => 'Жирный и индекс',
        'prompt'         => '<p>Вопрос со <strong>жирным</strong> и <sub>индексом</sub></p>'
            . '<script>alert(1)</script><img src="https://tracker.example/p.gif">',
        'option_text[]'  => ['Первый вариант', 'Второй вариант'],
        'correct_single'  => 'a',
    ]);

    $t->is($saved['status'] === 302, 'вопрос с разметкой сохранён');

    $stored = (string) $db->value(
        'SELECT prompt FROM questions WHERE test_id = :id ORDER BY id LIMIT 1',
        ['id' => $richTaskId]
    );

    /*
     * Проверяется база, а не страница. Очистка на выводе оставила бы в
     * таблице то, что прислал браузер, и правка была бы видна только там.
     */
    $t->is(str_contains($stored, '<strong>'), 'форматирование сохранено');
    $t->is(str_contains($stored, '<sub>'), 'подстрочный индекс сохранён');
    $t->is(stripos($stored, 'script') === false, 'исполняемый код не попал в базу');
    $t->is(stripos($stored, 'tracker.example') === false, 'картинка с чужого адреса не попала в базу');
}

/*
 * Редактор задания — отдельный экран, а не прежний редактор теста.
 *
 * Проверяется то, на чём автор ломался: по ссылке из мастера открывается
 * страница одного вопроса, где можно добавить вариант и отметить правильный.
 * Прежний адрес /tests/editor/{id} продолжал открываться — и это молча
 * оставляло автора на странице, которой в новой модели нет.
 */
$t->group('Редактор задания из мастера');

$taskEditorUrl = (string) $db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id AND task_kind = :kind ORDER BY position LIMIT 1',
    ['id' => $wizardId, 'kind' => 'single_choice']
);

if ($taskEditorUrl === '') {
    $t->is(false, 'в мастере есть задание с одиночным выбором');
} else {
    $editorTaskId = (int) $taskEditorUrl;
    $taskPage = $openTaskEditor($teacher, $wizardId, $editorTaskId);

    $t->is($taskPage['status'] === 200, 'редактор задания открыт прямо в списке позиций работы');
    $t->contains($taskPage['body'], 'name="option_text[]"', 'в редакторе задания есть варианты ответа');
    $t->contains($taskPage['body'], 'name="correct_single"', 'в редакторе задания можно отметить правильный');
    $t->contains($taskPage['body'], 'data-option-drop', 'у каждого варианта есть крестик удаления');

    /*
     * Селектора типа быть не должно: тип выбирается при добавлении задания.
     * Если он вернётся, автор снова сможет сменить тип на форме, где поля
     * вариантов уже отрисованы под другой вид вопроса.
     */
    $t->notContains($taskPage['body'], 'id="question-type"', 'тип задания на этом экране не меняется');

    /*
     * В новом задании показываются три строки вариантов, а не восемь: пустые
     * строки тянут взгляд вниз, а трёх хватает почти всегда. Остальные
     * добавляются кнопкой.
     *
     * Строки считаются только в блоке одиночного выбора: блок множественного
     * выбора рисуется на той же странице и закрыт, но строки в нём есть.
     */
    /*
     * Рисуется только блок текущего вида. Считается по строке от начала
     * вариантов до конца набора: раньше здесь искалась граница между двумя
     * блоками выбора, а теперь второго блока на странице нет вовсе.
     */
    $rowsStart = strpos($taskPage['body'], 'data-option-kind="single"');
    $actionsStart = strpos($taskPage['body'], 'data-option-add');
    $singleOnly = ($rowsStart !== false && $actionsStart !== false && $actionsStart > $rowsStart)
        ? substr($taskPage['body'], $rowsStart, $actionsStart - $rowsStart)
        : '';

    $t->equals(3, substr_count($singleOnly, 'class="option-row"'),
        'в одиночном выборе три строки вариантов');
    $t->contains($taskPage['body'], 'data-option-add', 'есть кнопка добавления варианта');

    /*
     * Чужих полей на странице нет. Браузер отправляет и скрытые, поэтому
     * вторая форма выбора присылала те же `option_text[]` — и варианты
     * удваивались с каждой правкой.
     */
    $t->notContains($taskPage['body'], 'data-option-kind="multiple"',
        'блока множественного выбора на странице нет');
    $t->notContains($taskPage['body'], 'name="correct_multi"',
        'поля отметки множественного выбора на странице нет');

    $taskToken = $teacher->tokenFrom($taskPage) ?? '';
    $questionRow = $db->one(
        'SELECT id FROM questions WHERE test_id = :id ORDER BY id LIMIT 1',
        ['id' => $editorTaskId]
    );

    $savedTask = $teacher->post('/tests/task/' . $editorTaskId, [
        '_token'        => $taskToken,
        'action'        => 'save_task',
        'question_id'   => (string) ($questionRow['id'] ?? ''),
        'title'        => 'Счёт до десяти',
        'prompt'        => '<p>Сколько будет 2+2?</p>',
        'option_text[]' => ['Три', 'Четыре', 'Пять', 'Шесть'],
        'correct_single' => 'b',
    ]);

    $t->is($savedTask['status'] === 302, 'задание с вариантами сохранено');

    $options = $db->all(
        'SELECT value_key, text FROM question_options
         WHERE question_id = :q ORDER BY position',
        ['q' => (int) ($questionRow['id'] ?? 0)]
    );

    $t->is(count($options) === 4, 'сохранились все четыре варианта');
    /*
     * Строка целиком, а не вхождение: иначе проверка прошла бы и при лишнем
     * ключе в config, то есть при сломанной проверке ответа.
     */
    $t->equals(
        '{"correct":"b"}',
        (string) ($db->value(
            'SELECT config FROM questions WHERE id = :q',
            ['q' => (int) ($questionRow['id'] ?? 0)]
        ) ?: ''),
        'правильным отмечен именно выбранный вариант'
    );

    /*
     * Правило проверяется на сервере: без него автор отметил бы «правильный»
     * вариант, которого нет, и увидел бы 500 вместо понятного отказа.
     */
    $badTask = $teacher->post('/tests/task/' . $editorTaskId, [
        '_token'        => $teacher->tokenFrom($openTaskEditor($teacher, $wizardId, $editorTaskId)) ?? '',
        'action'        => 'save_task',
        'question_id'   => (string) ($questionRow['id'] ?? ''),
        'title'        => 'Счёт до десяти',
        'prompt'        => 'Без вариантов',
        'option_text[]' => ['Только один вариант'],
        'correct_single' => 'a',
    ]);

    $t->is($badTask['status'] === 302, 'одиночный вариант не роняет страницу в 500');

    $errorPage = $openTaskEditor($teacher, $wizardId, $editorTaskId);

    $t->contains($errorPage['body'], 'варианта', 'автор видит, чего не хватило в вопросе');

    /*
     * Отметка «обязательно показывать».
     *
     * Значение флажка — номер строки формы, а не позиция отметки в списке.
     * Здесь отмечен третий вариант из четырёх: он и должен сохраниться, а
     * остальные — нет. Раньше отметка читалась по позиции (первая, вторая…),
     * и при отметке не первого варианта сохранялся не тот.
     *
     * И сама отметка терялась: список вариантов передавался в разбор по
     * значению, и пометки оставались в копии — вопрос сохранялся без них,
     * хотя на экране флажки были отмечены.
     */
    $markedTask = $teacher->post('/tests/task/' . $editorTaskId, [
        '_token'        => $taskToken,
        'action'        => 'save_task',
        'question_id'   => (string) ($questionRow['id'] ?? ''),
        'title'         => 'Счёт до десяти',
        'prompt'        => '<p>Сколько будет 2+2?</p>',
        'option_text[]' => ['Три', 'Четыре', 'Пять', 'Шесть'],
        'correct_single' => 'b',
        'random_options' => '3',
        'option_always_show[]' => ['2'],
    ]);

    $t->is($markedTask['status'] === 302, 'задание с отметкой «обязательно показывать» сохранено');

    $alwaysKeys = $db->all(
        'SELECT value_key FROM question_options
         WHERE question_id = :q AND always_show = 1 ORDER BY position',
        ['q' => (int) ($questionRow['id'] ?? 0)]
    );

    $t->equals(
        ['c'],
        array_map(static fn (array $row): string => (string) $row['value_key'], $alwaysKeys),
        'отмечен ровно тот вариант, который автор отметил'
    );

    /*
     * Форма редактора обязана показать сохранённую отметку.
     *
     * Отметка в базе — и в форме флажка не отмечено: автор видит, что его
     * отметка не сохранилась, хотя она сохранена, и отмечает заново — или
     * решает, что правило не работает. Причина была в том, что форма
     * собирала варианты без отметки, а разбор искал её в списке, где её нет.
     */
    $markedEditor = $openTaskEditor($teacher, $wizardId, $editorTaskId);

    $t->is(
        preg_match(
            '#name="option_always_show\[\]" value="2" id="always-2-[0-9a-f]{8}" aria-label="[^"]*" checked#',
            $markedEditor['body']
        ) === 1,
        'форма редактора показывает сохранённую отметку'
    );

    $t->is(
        preg_match(
            '#name="option_always_show\[\]" value="0" id="always-0-[0-9a-f]{8}" aria-label="[^"]*" checked#',
            $markedEditor['body']
        ) === 0,
        'неотмеченные варианты остаются неотмеченными'
    );
}

/*
 * Вывод ученику: разметка должна остаться разметкой. Если её экранировать,
 * ученик увидит «<p>Вопрос со <strong>», и это выдаст путь, которым данные
 * попадают в базу.
 */
$view = $app->get(View::class);
$rendered = $view->rich('<p>Вопрос со <strong>жирным</strong></p><script>alert(1)</script>');

$t->is(str_contains($rendered, '<strong>'), 'разметка ученику не экранируется');
$t->is(stripos($rendered, 'script') === false, 'на выводе разметка тоже очищается');

// ------------------------------------------------------- снос самостоятельных тестов

$t->group('Самостоятельных тестов нет');

/*
 * Задание выдаётся только внутри работы. Старый адрес /tests/{id} при этом
 * обязан продолжать работать: закладки и ссылки, разосланные до перехода,
 * не должны превратиться в 404.
 */
$publishPage = $teacher->get('/panel/assignments/' . $wizardId);
$published = $teacher->post('/panel/assignments/' . $wizardId . '/status', [
    '_token' => $teacher->tokenFrom($publishPage) ?? '',
    'action' => 'publish',
]);

$t->is($published['status'] === 302, 'работа мастера опубликована');

$publishedItem = $db->one(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id ORDER BY position LIMIT 1',
    ['id' => $wizardId]
);

$legacyAddress = $teacher->get('/tests/' . (int) ($publishedItem['task_id'] ?? 0));

$t->is(
    $legacyAddress['status'] === 301
        && str_contains((string) ($legacyAddress['location'] ?? ''), '/work/' . $wizardId),
    'старый адрес теста ведёт в работу, а не в никуда'
);

foreach ([
    '/tests/' . (int) ($publishedItem['task_id'] ?? 0) . '/start' => 'начать прохождение',
    '/tests/1/attempt/1'  => 'открыть прохождение',
    '/tests/attempts/1/result' => 'открыть результат попытки',
    '/tests/'            => 'список тестов',
] as $address => $what) {
    $response = $teacher->get($address);

    $t->is(
        in_array($response['status'], [404, 405], true),
        'адрес недоступен: ' . $what
    );
}

/*
 * Тест, не входящий ни в одну опубликованную работу, по старому адресу даёт
 * 404, а не страницу. Раньше такой тест открывался сам по себе — именно это
 * и было самостоятельным тестом.
 */
$orphanTask = $app->get(MyEdu\Plugin\PluginManager::class)->get('test')
    ->createTask('text', ['author_id' => $teacherId, 'title' => 'Ни в одной работе']);

$orphanResponse = $teacher->get('/tests/' . $orphanTask);

$t->is($orphanResponse['status'] === 404, 'тест вне работы по старому адресу не открывается');

/*
 * На главной больше нет блока отдельных заданий: он собирался из catalogue()
 * и показывал ровно то, что осталось бы после сноса.
 */
$portalPage = $teacher->get('/portal');

$t->is(str_contains($portalPage['body'], 'Отдельные задания') === false, 'главная не предлагает отдельные задания');

// ------------------------------------------------------------ пакетные данные заданий

$t->group('Пакетное чтение данных заданий');

/*
 * taskDataBatch() читает данные всех позиций страницы одним запросом вместо
 * одного на позицию. Проверяется не число запросов, а то, что пакетный ответ
 * совпадает с одиночным: расхождение здесь означало бы, что страница показала
 * бы учителю одни цифры, а проверка опиралась бы на другие.
 */
$testPlugin = $app->get(MyEdu\Plugin\PluginManager::class)->get('test');

$batchTaskOne = $testPlugin->createTask('text', ['author_id' => $teacherId, 'title' => 'Пакетное раз']);
$batchTaskTwo = $testPlugin->createTask('text', ['author_id' => $teacherId, 'title' => 'Пакетное два']);
$batchTaskMissing = 99999999;

$batchRefs = [
    new MyEdu\Plugin\TaskRef('test', 'text', $batchTaskOne),
    new MyEdu\Plugin\TaskRef('test', 'text', $batchTaskTwo),
    new MyEdu\Plugin\TaskRef('test', 'text', $batchTaskMissing),
];

$batchData = $testPlugin->taskDataBatch($batchRefs);

$t->is(count($batchData) === 3, 'пакет отвечает столько же элементов, сколько было ссылок');

$t->is(
    (string) ($batchData[0]['title'] ?? '') === 'Пакетное раз',
    'первое задание на своей позиции'
);

$t->is(
    (string) ($batchData[1]['title'] ?? '') === 'Пакетное два',
    'второе задание на своей позиции'
);

/*
 * Отсутствующее задание — это пустые данные, а не исключение: иначе одно
 * удалённое задание уронило бы всю страницу работы вместе с остальными.
 */
$t->is($batchData[2] === [], 'удалённое задание даёт пустые данные, а не исключение');

$singleData = $testPlugin->taskData($batchRefs[0]);

$t->is(
    $batchData[0] === $singleData,
    'пакетный ответ совпадает с одиночным'
);

// ------------------------------------------------------------- список и удаление работ

$t->group('Список работ и создание новой');

/*
 * Кнопка создания ведёт прямо в мастер, а не на страницу-заглушку.
 *
 * Раньше список и создание были разведены по разным адресам: рядом с кнопкой
 * создания стояла и кнопка удаления чужой работы, и промах мышью стоил бы
 * сноса. Теперь отдельной страницы нет вовсе, а разводить нечего — создание
 * ничего не удаляет. Проверяется поэтому другое: список не создаёт работу ни
 * своей кнопкой, ни формой, а кнопка ведёт в мастер.
 */
$listPage = $teacher->get('/panel/assignments');

$t->is($listPage['status'] === 200, 'список работ открывается');

$t->notContains($listPage['body'], 'action="/panel/assignments/new"',
    'на странице списка нет формы создания');

$createPage = $teacher->get('/panel/assignments/new/start');

$t->is($createPage['status'] === 200, 'из списка открывается сразу первый шаг мастера');
$t->contains($createPage['body'], '/panel/assignments/new/start',
    'форма мастера отправляется на свой адрес, а не на адрес списка');

$t->contains($listPage['body'], '/panel/assignments/new',
    'из списка на создание ведёт ссылка прямо в редактор');

/*
 * Старый адрес остаётся рабочим: на него могли остаться ссылки из закладок
 * и из писем, и человек не должен попадать в 404 из-за того, что создание
 * переехало на другой адрес.
 */
$t->is($teacher->get('/panel/assignments/new')['status'] === 302,
    'старый адрес создания ведёт в редактор, а не отвечает отказом');

$t->group('Кому видны чужие работы');

/*
 * Заведующий заводится здесь заново, а не берётся из проверки журнала: там
 * учётку создают на время проверки и удаляют сразу после, и клиент остался
 * бы живым, но его пользователя в базе уже нет — страница такого вернула бы
 * отказ, и проверка видимости искала бы не то.
 */
$deputyLogin = 'zavuch-spisok';
$deputyPassword = 'zavuch-spisok-2025';

$deputyRow = $db->one('SELECT id FROM users WHERE login = :login', ['login' => $deputyLogin]);

if ($deputyRow === null) {
    $users->create([
        'login'            => $deputyLogin,
        'email'            => null,
        'password_hash'    => password_hash($deputyPassword, PASSWORD_DEFAULT),
        'full_name'        => 'Завуч Списочный',
        'is_owner'         => 0,
        'status'           => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $deputyRow = $db->one('SELECT id FROM users WHERE login = :login', ['login' => $deputyLogin]);
}

$deputyId = (int) $deputyRow['id'];
$users->setRole($deputyId, Role::HEAD_TEACHER);

$deputy = $login($deputyLogin, $deputyPassword);

/*
 * Учителю показываются только его работы. Завучу и администратору — все, с
 * именем автора: без подписи два одинаково названных черновика от разных
 * учителей неразличимы.
 */
$t->notContains($listPage['body'], 'Работа соседнего класса второго учителя',
    'учитель не видит чужую работу');

$otherTeacherWork = $otherTeacher->post('/panel/assignments/new/start', [
    '_token' => $otherTeacher->tokenFrom($otherTeacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Работа соседнего класса второго учителя',
]);

$t->is($otherTeacherWork['status'] === 302, 'второй учитель создал работу для проверки видимости');

$teacherListAgain = $teacher->get('/panel/assignments');

$t->notContains($teacherListAgain['body'], 'Работа соседнего класса второго учителя',
    'после создания чужая работа учителю не показывается');

$headList = $deputy->get('/panel/assignments');

$t->is($headList['status'] === 200, 'заведующему список работ доступен');

/*
 * Разделение по состоянию стоит над деревом, а не внутри папок: смешанные
 * черновики и готовые работы в одной папке читались бы как список готовых.
 *
 * Проверяется, что чужие черновики не теряются: показывать чужие работы
 * можно было и раньше, а вот потерять их при раскладывании по папкам значило
 * бы, что завуч перестал видеть работу учителя.
 *
 * Раздел выбирается адресом, а не вкладкой в разметке: он должен переживать
 * перезагрузку и быть пересылаемым. По умолчанию открыты опубликованные.
 */
$headDrafts = $deputy->get('/panel/assignments?state=drafts');

$t->contains($headDrafts['body'], 'Работа соседнего класса второго учителя',
    'заведующий видит чужие черновики в разделе черновиков');

$headPublished = $deputy->get('/panel/assignments?state=published');

$t->notContains($headPublished['body'], 'Работа соседнего класса второго учителя',
    'черновик не попадает в раздел опубликованных');

$t->contains($headPublished['body'], 'Опубликованные', 'раздел опубликованных на месте');
$t->contains($headPublished['body'], 'Черновики', 'раздел черновиков на месте');

$t->contains($headList['body'], 'Без параллели',
    'работа без параллели лежит в своей папке, а не пропадает');

$t->contains($headList['body'], 'Все работы',
    'заведующему показан список всех работ, а не только своих');

$t->group('Чужая работа открывается на чтение');

/*
 * Из списка чужой работы идёт кнопка «Открыть», и страница обязана её
 * принимать: раньше здесь стояла проверка авторства, и завуч получал отказ
 * по ссылке из собственного списка.
 */
$foreignOverview = $deputy->get('/panel/assignments/' . $assignmentId);

$t->is($foreignOverview['status'] === 200, 'заведующий открывает чужую работу на чтение');

$t->contains($foreignOverview['body'], 'Это работа другого автора',
    'на чужой работе сказано, что её нельзя изменить');

/*
 * Кнопок правки у чужой работы нет вовсе, а не отключённых: они всё равно
 * упёрлись бы в отказ по авторству, и человек увидел бы возможность, которой
 * нет. Проверяется именно отсутствие — «Открыть» на такой странице есть.
 */
$t->notContains($foreignOverview['body'], '/edit/items', 'у чужой работы нет ссылки на этап заданий');

$t->notContains($foreignOverview['body'], '/edit/result', 'у чужой работы нет ссылки на правила оценки');

$t->notContains($foreignOverview['body'], '/status', 'у чужой работы нет кнопок публикации');

$t->notContains($foreignOverview['body'], '/' . $assignmentId . '/delete',
    'у чужой работы нет кнопки удаления');

/*
 * На странице работы кнопок нет: они были копией кнопок редактора.
 *
 * Удаление осталось в списке работ — там, где работа и живёт в списке, — и
 * по-прежнему идёт через подтверждение, а не сразу. Прямая форма обходила бы
 * подтверждение, ради которого оно и затевалось.
 */
$ownOverview = $teacher->get('/panel/assignments/' . $assignmentId);

$t->notContains($ownOverview['body'], '/panel/assignments/' . $assignmentId . '/delete',
    'на странице работы кнопки удаления нет');

$t->notContains($ownOverview['body'], 'Собрать по шагам',
    'на странице работы нет кнопки «Собрать по шагам» — она есть в редакторе');

$t->notContains($ownOverview['body'], 'action="/panel/assignments/' . $assignmentId . '/delete"',
    'удаление не происходит прямой формой — только через подтверждение');

$t->contains($teacher->get('/panel/assignments')['body'],
    'Удалить',
    'удаление осталось в списке работ');

/*
 * Проверка ответов остаётся автору: она ставит оценки и меняет результат.
 * Заведующий посмотреть чужую работу может, проверять её за автора — нет.
 */
$t->is(
    $deputy->get('/panel/assignments/' . $assignmentId . '/edit/items')['status'] === 403,
    'заведующий не входит в мастер чужой работы'
);

$t->is(
    $deputy->get('/panel/assignments/' . $assignmentId . '/delete')['status'] === 403,
    'заведующий не доходит до удаления чужой работы'
);

$t->group('Сотрудник проходит любую работу для проверки');

/*
 * Заведующий должен уметь прогнать чужую работу: иначе он не может проверить,
 * что она вообще выдаётся, не выдавая её ученикам лишний раз.
 *
 * Работа публикуется прямо здесь: на этом шаге набора она может быть ещё
 * черновиком, а тестировать имеет смысл только то, что ученики видят.
 */
$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $assignmentId]);
$db->run('UPDATE assignment_issues SET max_attempts = 1 WHERE assignment_id = :id', ['id' => $assignmentId]);

$deputyWork = $deputy->get('/work/' . $assignmentId);

$t->is($deputyWork['status'] === 200, 'заведующий открывает чужую работу как прохождающий');

$t->contains($deputyWork['body'], 'Начать работу',
    'заведующему предложено начать чужую работу');

$deputyStarted = $deputy->post('/work/' . $assignmentId . '/start', [
    '_token' => $deputy->tokenFrom($deputyWork) ?? '',
]);

$t->is($deputyStarted['status'] === 302, 'заведующий начал чужую работу');

// Самопроверка сотрудника лежит в staff_id: по student_id она бы не нашлась.
$deputyAttemptId = (int) ($db->value(
    'SELECT id FROM assignment_attempts WHERE assignment_id = :a AND staff_id = :u ORDER BY id DESC LIMIT 1',
    ['a' => $assignmentId, 'u' => $deputyId]
) ?? 0);

$t->is($deputyAttemptId > 0, 'попытка заведующего заведена');

/*
 * Лимит в одну попытку на заведующего не распространяется: сдав работу и
 * посмотрев результат, он должен суметь пройти её снова.
 */
$deputyToken = (string) $db->value('SELECT token FROM assignment_attempts WHERE id = :id', ['id' => $deputyAttemptId]);

$db->run("UPDATE assignment_attempts SET status = 'finished', finished_at = NOW() WHERE id = :id", [
    'id' => $deputyAttemptId,
]);

$deputyAfterResult = $deputy->get('/work/' . $assignmentId);

$t->contains($deputyAfterResult['body'], 'Пройти заново',
    'заведующему предложено пройти чужую работу заново');

$deputySecond = $deputy->post('/work/' . $assignmentId . '/start', [
    '_token' => $deputy->tokenFrom($deputyAfterResult) ?? '',
]);

$t->is($deputySecond['status'] === 302,
    'заведующий проходит чужую работу повторно, хотя лимит у неё одна попытка');

$t->equals(2, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :a AND staff_id = :u',
    ['a' => $assignmentId, 'u' => $deputyId]
), 'заведена вторая попытка заведующего');

/*
 * Через панель заведующий попадает к прохождению только через «Пройти работу»
 * на обзоре «Пройти работу»: самой работы в его списке работ нет, он её не
 * автор, а из списка опубликованных он бы искал её среди чужих.
 */
$deputyOverview = $deputy->get('/panel/assignments/' . $assignmentId);

$t->contains($deputyOverview['body'], '/work/' . $assignmentId,
    'на обзоре чужой работы есть ссылка пройти её');

/*
 * Черновик проходить нельзя: его не видно ученикам, и тестовая попытка по
 * нему ничего не проверяет.
 */
$db->run("UPDATE assignments SET status = 'draft' WHERE id = :id", ['id' => $assignmentId]);

$t->notContains(
    $deputy->get('/panel/assignments/' . $assignmentId)['body'],
    'Пройти работу',
    'у черновика ссылки пройти нет'
);

$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $assignmentId]);

$t->group('Кнопки в списке работ');

$t->contains(
    $teacherListAgain['body'],
    '/panel/assignments/' . $assignmentId . '/edit/start',
    'у своей работы есть кнопка «Редактировать»'
);

$t->contains(
    $teacherListAgain['body'],
    '/panel/assignments/' . $assignmentId . '/delete',
    'у своей работы есть кнопка «Удалить»'
);

/*
 * У чужой работы кнопок правки нет: они всё равно упёрлись бы в отказ по
 * авторству, но человек увидел бы возможность и удивлялся бы отказу.
 */
$otherTeacherList = $otherTeacher->get('/panel/assignments');

$t->notContains(
    $otherTeacherList['body'],
    '/panel/assignments/' . $assignmentId . '/delete',
    'у чужой работы нет кнопки удаления'
);

$t->group('Удаление работы требует подтверждения');

$confirmPage = $teacher->get('/panel/assignments/' . $assignmentId . '/delete');

$t->is($confirmPage['status'] === 200, 'страница подтверждения удаления открывается');

$t->contains($confirmPage['body'], 'Восстановить это нельзя',
    'подтверждение говорит, что удаление необратимо');

$t->contains($confirmPage['body'], 'method="post"',
    'само удаление происходит только POST-формой');

$t->is(
    (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $assignmentId]) === 1,
    'до подтверждения работа на месте'
);

$t->is(
    $otherTeacher->get('/panel/assignments/' . $assignmentId . '/delete')['status'] === 403,
    'чужой учитель не доходит до подтверждения удаления'
);

/*
 * Удаление без перезагрузки страницы и возврат на открытую вкладку.
 *
 * Две вещи, которые легко сломать незаметно: сервер должен отвечать данными
 * тому, кто спросил данными, а после удаления человек должен оказаться на
 * том разделе списка, который открыт. Иначе он попадает в «Опубликованные»,
 * где его черновика нет, и решает, что удалилось больше, чем он нажал.
 */
$t->group('Удаление работы без перезагрузки');

$tabWork = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Черновик для проверки вкладки',
]);

$tabId = (int) (preg_match('#/panel/assignments/(\d+)/#', (string) ($tabWork['location'] ?? ''), $tabMatch) === 1
    ? $tabMatch[1]
    : 0);

$t->is($tabId > 0, 'работа для проверки вкладки создана');

$teacher->get('/panel/assignments?state=drafts');

$tabToken = $teacher->tokenFrom($teacher->get('/panel/assignments?state=drafts')) ?? '';

$tabDelete = $teacher->postAjax('/panel/assignments/' . $tabId . '/delete', [
    '_token' => $tabToken,
]);

$t->is($tabDelete['status'] === 200, 'удаление данными отвечает кодом 200');

$t->contains($tabDelete['body'], '"ok":true', 'удаление данными отвечает, что работа снесена');

$t->is(
    (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $tabId]) === 0,
    'работа удалена по запросу данными'
);

$tabPlain = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Черновик для возврата на вкладку',
]);

$plainId = (int) (preg_match('#/panel/assignments/(\d+)/#', (string) ($tabPlain['location'] ?? ''), $plainMatch) === 1
    ? $plainMatch[1]
    : 0);

$teacher->get('/panel/assignments?state=drafts');

$plainDelete = $teacher->post('/panel/assignments/' . $plainId . '/delete', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments?state=drafts')) ?? '',
]);

$t->contains((string) ($plainDelete['location'] ?? ''), 'state=drafts',
    'после удаления возвращаем на ту вкладку, которая была открыта');

$t->contains(
    $teacher->get('/panel/assignments')['body'],
    'nav-link active',
    'список без адреса открывает запомненный раздел'
);

$t->group('Удаление работы уносит её содержимое');

/*
 * Проверяется на отдельной работе под снос: проверка общих свойств удаления
 * уже трогала данные, на которых дальше строятся другие проверки.
 */
$doomed = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Работа под снос',
]);

$t->is($doomed['status'] === 302, 'работа под снос создана');

$doomedId = (int) ($db->value("SELECT id FROM assignments WHERE title = 'Работа под снос' ORDER BY id DESC LIMIT 1") ?? 0);

$t->is($doomedId > 0, 'работа под снос найдена в базе');

$teacher->post('/panel/assignments/' . $doomedId . '/edit/items/add', [
    '_token'    => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $doomedId . '/edit/items')) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'weight'    => '1',
    'max_score' => '1',
]);

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', ['id' => $doomedId]),
    'в работе под снос есть позиция');

// Номер задания берётся из самой позиции: так проверяется именно то задание,
// которое плагин создал для этой работы.
$doomedTaskId = (int) $db->value(
    'SELECT task_id FROM assignment_items WHERE assignment_id = :id LIMIT 1',
    ['id' => $doomedId]
);

$t->is($doomedTaskId > 0, 'у позиции работы под снос есть задание');

$db->run(
    "INSERT INTO assignment_attempts
        (assignment_id, student_id, token, attempt_no, status, manual, started_at)
     VALUES (:a, :u, :tok, 1, 'finished', 0, NOW())",
    ['a' => $doomedId, 'u' => $studentId, 'tok' => 'pod-snos']
);

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id', ['id' => $doomedId]),
    'по работе под снос есть попытка');

$confirmDoomed = $teacher->get('/panel/assignments/' . $doomedId . '/delete');

$t->contains($confirmDoomed['body'], 'Попыток, вместе с ответами',
    'подтверждение показывает, сколько попыток пропадёт');

$teacher->post('/panel/assignments/' . $doomedId . '/delete', [
    '_token' => $teacher->tokenFrom($confirmDoomed) ?? '',
]);

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignments WHERE id = :id', ['id' => $doomedId]),
    'работа удалена');

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id', ['id' => $doomedId]),
    'позиции работы удалены вместе с ней');

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :id', ['id' => $doomedId]),
    'попытки и сданные ответы удалены вместе с работой');

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM tests WHERE id = :id', ['id' => $doomedTaskId]),
    'задание удалено вместе с работой');

$t->notContains(
    $teacher->get('/panel/assignments')['body'],
    'Работа под снос',
    'удалённой работы больше нет в списке'
);

$t->group('Учитель проходит свою работу повторно');

/*
 * Лимит попыток к сотрудникам и не применялся, но интерфейс оставлял после
 * сдачи только ссылку «Результат»: пройти работу повторно было нечем, и
 * проверить её после правки задания не получалось. Именно из-за этого
 * перетаскивание в «Правильной последовательности» проверить было не на чем.
 */
$repeatWork = $teacher->post('/panel/assignments/new/start', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/new/start')) ?? '',
    'title'  => 'Работа для повторного прохождения',
]);

$repeatId = (int) ($db->value("SELECT id FROM assignments WHERE title = 'Работа для повторного прохождения' ORDER BY id DESC LIMIT 1") ?? 0);

$teacher->post('/panel/assignments/' . $repeatId . '/edit/items/add', [
    '_token'    => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $repeatId . '/edit/items')) ?? '',
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'weight'    => '1',
    'max_score' => '1',
]);

$teacher->post('/panel/assignments/' . $repeatId . '/edit/result', [
    '_token'       => $teacher->tokenFrom($teacher->get('/panel/assignments/' . $repeatId . '/edit/result')) ?? '',
    'grade_mode'   => 'weighted',
    'max_attempts' => '1',
    'show_results' => '1',
]);

$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $repeatId]);

$teacherWork = $teacher->get('/work/' . $repeatId);
$teacherStarted = $teacher->post('/work/' . $repeatId . '/start', [
    '_token' => $teacher->tokenFrom($teacherWork) ?? '',
]);

$t->is($teacherStarted['status'] === 302, 'учитель начал свою работу');

// Самопроверка учителя, как и у любого сотрудника, пишется в staff_id.
$repeatAttemptId = (int) ($db->value(
    'SELECT id FROM assignment_attempts WHERE assignment_id = :a AND staff_id = :u ORDER BY id DESC LIMIT 1',
    ['a' => $repeatId, 'u' => $teacherId]
) ?? 0);

$t->is($repeatAttemptId > 0, 'попытка учителя заведена');

$repeatToken = (string) $db->value('SELECT token FROM assignment_attempts WHERE id = :id', ['id' => $repeatAttemptId]);

$repeatItemId = (int) $db->value('SELECT id FROM assignment_items WHERE assignment_id = :id LIMIT 1', ['id' => $repeatId]);

$teacher->post('/work/' . $repeatAttemptId . '/finish', [
    '_token' => $teacher->tokenFrom($teacher->get('/work/' . $repeatId)) ?? '',
    't'      => $repeatToken,
]);

$t->equals('finished', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $repeatAttemptId]),
    'попытка учителя сдана');

$afterResult = $teacher->get('/work/' . $repeatId);

$t->contains($afterResult['body'], 'Пройти заново',
    'после результата учителю предложено пройти работу заново');

$t->contains($afterResult['body'], '/work/' . $repeatId . '/start',
    'кнопка повторного прохождения ведёт на начало попытки');

$teacherThirdStart = $teacher->post('/work/' . $repeatId . '/start', [
    '_token' => $teacher->tokenFrom($afterResult) ?? '',
]);

$t->is($teacherThirdStart['status'] === 302,
    'учитель проходит работу повторно, хотя лимит у неё одна попытка');

$t->equals(2, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :a AND staff_id = :u',
    ['a' => $repeatId, 'u' => $teacherId]
), 'заведена вторая попытка учителя');

/*
 * Ученику такая кнопка не показывается: для него число попыток остаётся
 * ограничением, и предлагать ему обойти его незачем.
 */
$studentAfterResult = $student->get('/work/' . $repeatId);

$t->notContains(
    $studentAfterResult['status'] === 200 ? $studentAfterResult['body'] : '',
    'Пройти заново',
    'ученику кнопка повторного прохождения не показывается'
);

// Заведующий создан только для проверки видимости и удаляется вместе с собой:
// оставленная учётка ломала бы следующие наборы.
$db->run('DELETE FROM users WHERE id = :id', ['id' => $deputyId]);

$t->group('Пакетное чтение не мешает одиночному');

// ------------------------------------------------- вариативная задача (плагин)

/*
 * Вариативная задача проверяется здесь целиком, а не по частям: плагин
 * включается, его миграции накатываются, задание добавляется в работу,
 * ученик проходит её и отправляет числа.
 *
 * Проверяются вещи, которые ломаются только в связке: условие дошло до ученика
 * с числами вместо скобок; значения сохранились вместе с ответом; после правки
 * учителем ученик всё равно проверяется по своим, а не по новым значениям.
 */
$pluginManager = $app->get(MyEdu\Plugin\PluginManager::class);
$discoveredPlugins = $pluginManager->discover();

$t->is(isset($discoveredPlugins['variable']), 'плагин «Вариативная задача» найден в plugins/');

// Включение само накатывает миграции плагина.
$pluginManager->enable('variable');

$t->is($pluginManager->isEnabled('variable'), 'плагин вариативной задачи включён');

foreach (['variable_tasks', 'variable_item_attempts', 'variable_item_answers'] as $variableTable) {
    $t->is(
        (int) $db->value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $variableTable]
        ) === 1,
        'миграция плагина создала таблицу ' . $variableTable
    );
}

/*
 * Задание заводится вручную тем же путём, каким это делает ядро: строка в базе
 * плагина плюс позиция в работе. Так проверяется настоящий сценарий, а не
 * вызов плагина руками.
 */
$variableConfig = [
    'variables' => [
        [
            'name'   => 'a',
            'mode'   => 'list',
            'values' => '6, 9, 12',
            'min'    => null,
            'max'    => null,
            'step'   => 1.0,
        ],
        [
            'name'   => 'b',
            'mode'   => 'range',
            'values' => '',
            'min'    => 2.0,
            'max'    => 10.0,
            'step'   => 1.0,
        ],
        [
            'name'   => 'c',
            'mode'   => 'list',
            'values' => '2, 4, 6',
            'min'    => null,
            'max'    => null,
            'step'   => 1.0,
        ],
    ],
    /*
     * Одна формула на всю задачу: ученик решает её сам и вписывает один ответ.
     * Формула использует те же имена, что и условие, и при проверке в них
     * подставляются те же числа, что были выданы ученику.
     */
    'formula'   => 'a - b',
    'tolerance' => 0.01,
    'unit'      => '',
];

$db->run(
    "INSERT INTO variable_tasks (author_id, title, prompt, config, status, created_at, updated_at)
     VALUES (:a, 'Работа по геометрии', 'Число {a} больше {b} на сколько, если {c} — вдвое больше {b}?', :c, 'published', NOW(), NOW())",
    ['a' => $sharedAuthor, 'c' => json_encode($variableConfig, JSON_UNESCAPED_UNICODE)]
);

$variableTaskId = (int) $db->value(
    "SELECT id FROM variable_tasks WHERE title = 'Работа по геометрии' ORDER BY id DESC LIMIT 1"
);

$t->is($variableTaskId > 0, 'задание вариативной задачи создано');

/*
 * Редактор вариативной задачи открывается только через список позиций работы,
 * поэтому работа и позиция создаются раньше, чем форма.
 */
$db->run(
    "INSERT INTO assignments (author_id, title, status, grade_mode, criteria_mode, created_at, updated_at)
     VALUES (:a, 'Работа с вариативной задачей', 'draft', 'weighted', 'percent', NOW(), NOW())",
    ['a' => $sharedAuthor]
);

$variableWorkId = (int) $db->value(
    "SELECT id FROM assignments WHERE title = 'Работа с вариативной задачей' ORDER BY id DESC LIMIT 1"
);

$db->run(
    "INSERT INTO assignment_items (assignment_id, position, plugin_id, task_kind, task_id, weight, max_score, created_at)
     VALUES (:w, 1, 'variable', 'generated', :t, 1.00, 2.00, NOW())",
    ['w' => $variableWorkId, 't' => $variableTaskId]
);

$variableItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :w ORDER BY position LIMIT 1',
    ['w' => $variableWorkId]
);

/*
 * Редактор вариативной задачи.
 *
 * Проверяется то, что ломалось: переключатели разных переменных не должны
 * сливаться в одну группу (одинаковое имя — и отметка одной гасит отметку
 * другой), и формула должна быть одна на всю задачу, а не по одной на
 * переменную.
 */
$variableEditor = $openTaskEditor($teacher, $variableWorkId, $variableTaskId);

$t->is($variableEditor['status'] === 200, 'редактор вариативной задачи открыт');

/*
 * Строк столько же, сколько переменных в условии.
 *
 * Образец строки для скрипта не считается: он лежит в `<template>`, на странице
 * не рисуется, а разметка у него та же самая. Считать его — значит всегда
 * получать на одну строку больше и «чинить» проверку при настоящей ошибке.
 */
$variableBody = preg_replace('#<template\b.*?</template>#s', '', (string) $variableEditor['body']);
preg_match_all('#<tr class="generated-var" data-generated-row#', (string) $variableBody, $variableRows);

$t->equals(3, count($variableRows[0]), 'в редакторе строка на каждую переменную условия');
$t->contains($variableEditor['body'], 'data-generated-row-template',
    'скрипту передан образец строки: разметку рисует ядро, а не скрипт');

preg_match_all('#name="(var_\d+\[mode\])"#', $variableEditor['body'], $variableModes);
$distinctModes = array_values(array_unique($variableModes[1]));

$t->equals(
    count($variableModes[1]) / 2,
    count($distinctModes),
    'у каждой переменной своё имя переключателей'
);

$t->equals(1, substr_count($variableEditor['body'], 'name="formula"'),
    'формула одна на всю задачу');
$t->notContains($variableEditor['body'], '[formula]"',
    'в строках таблицы нет своей формулы: она общая');
$t->contains($variableEditor['body'], 'value="a - b"', 'формула подставлена в поле редактора');

$t->contains($variableEditor['body'], 'ql-editor-host', 'редактор условия подключён');
$t->contains(
    $variableEditor['body'],
    'data-upload-url',
    'в редакторе вариативной задания указан адрес загрузки картинки: без него кнопка картинки молча ничего не делала'
);
$t->contains($variableEditor['body'], 'assets/js/editor.js', 'подключён скрипт, который создаёт редактор');
$t->notContains($variableEditor['body'], 'Опубликовать задание',
    'в редакторе вариативной задания тоже нет кнопки публикации');

/*
 * Цена задания — в его же форме, рядом с допуском и единицей.
 *
 * Раньше поле стояло в списке позиций работы, одной строкой на все плагины, и
 * вариативной задаче места для цены там не было вовсе: строку убрали как
 * дубль, и поставить цену стало нечем. Проверяется и наличие поля, и то, что
 * оно рядом с настройками проверки, а не где-то ещё.
 */
$t->contains($variableEditor['body'], 'name="max_score"',
    'в редакторе вариативной задания есть поле цены');
$t->contains($variableEditor['body'], 'name="item" value="' . $variableItemId . '"',
    'форма знает, в какой позиции работы лежит задание');
$t->contains($variableEditor['body'], 'value="2"', 'в поле стоит нынешняя цена позиции');
$t->notContains($variableEditor['body'], 'task-row__points',
    'в списке позиций отдельного поля цены больше нет');

$priceField = (int) strpos($variableEditor['body'], 'name="max_score"');
$toleranceField = (int) strpos($variableEditor['body'], 'name="tolerance"');
$unitField = (int) strpos($variableEditor['body'], 'name="unit"');

$t->is(
    $priceField > 0 && $toleranceField > 0 && $unitField > 0
        && abs($priceField - $toleranceField) < 2600 && abs($unitField - $priceField) < 2600,
    'цена стоит рядом с допуском и единицей измерения'
);

$priceSaved = $teacher->post('/variables/task/' . $variableTaskId, [
    '_token'    => $teacher->tokenFrom($variableEditor) ?? '',
    'action'    => 'save_task',
    'item'      => (string) $variableItemId,
    'title'     => 'Работа по геометрии',
    'prompt'    => 'Число {a} больше {b} на сколько, если {c} — вдвое больше {b}?',
    'formula'   => 'a - b',
    'tolerance' => '0.01',
    'unit'      => '',
    'max_score' => '7,5',

    ['var_0[name]', 'a'],
    ['var_0[mode]', 'list'],
    ['var_0[values]', '6, 9, 12'],
    ['var_1[name]', 'b'],
    ['var_1[mode]', 'range'],
    ['var_1[min]', '2'],
    ['var_1[max]', '10'],
    ['var_1[step]', '1'],
    ['var_2[name]', 'c'],
    ['var_2[mode]', 'list'],
    ['var_2[values]', '2, 4, 6'],

    'back'      => $backFromForm($variableEditor),
]);

$t->is($priceSaved['status'] === 302, 'цена сохраняется вместе с заданием');
$t->equals(7.5, (float) $db->value(
    'SELECT max_score FROM assignment_items WHERE id = :id',
    ['id' => $variableItemId]
), 'цена позиции обновилась');

$t->is(
    (int) $db->value(
        'SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :w AND max_score = 7.5',
        ['w' => $variableWorkId]
    ) === 1,
    'цена проставлена ровно у одной позиции'
);

/*
 * Чужая позиция ценой не меняется: номер приходит из формы, а форма — из
 * браузера, значит номер может быть любым.
 */
$otherWork = (int) $db->insert('assignments', [
    'author_id'   => $sharedAuthor,
    'title'       => 'Чужая работа',
    'status'      => 'draft',
    'grade_mode'  => 'weighted',
    'created_at'  => date('Y-m-d H:i:s'),
    'updated_at'  => date('Y-m-d H:i:s'),
]);

$otherTask = (int) $db->insert('variable_tasks', [
    'author_id'  => $sharedAuthor,
    'title'      => 'Чужая вариативная задача',
    'prompt'     => 'Сколько будет {a} плюс {b}?',
    'config'     => json_encode([
        'variables' => [
            ['name' => 'a', 'mode' => 'list', 'values' => '1, 2'],
            ['name' => 'b', 'mode' => 'list', 'values' => '3, 4'],
        ],
        'formula'   => 'a + b',
        'tolerance' => 0.01,
        'unit'      => '',
    ], JSON_UNESCAPED_UNICODE),
    'status'     => 'published',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$otherItem = (int) $db->insert('assignment_items', [
    'assignment_id'   => $otherWork,
    'position'        => 1,
    'plugin_id'       => 'variable',
    'task_kind'       => 'generated',
    'task_id'         => $otherTask,
    'weight'          => 1.0,
    'max_score'       => 3.0,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$foreignPrice = $teacher->post('/variables/task/' . $variableTaskId, [
    '_token'    => $teacher->tokenFrom($variableEditor) ?? '',
    'action'    => 'save_task',
    'item'      => (string) $otherItem,
    'title'     => 'Работа по геометрии',
    'prompt'    => 'Число {a} больше {b} на сколько, если {c} — вдвое больше {b}?',
    'formula'   => 'a - b',
    'tolerance' => '0.01',
    'unit'      => '',
    'max_score' => '999',

    ['var_0[name]', 'a'],
    ['var_0[mode]', 'list'],
    ['var_0[values]', '6, 9, 12'],
    ['var_1[name]', 'b'],
    ['var_1[mode]', 'range'],
    ['var_1[min]', '2'],
    ['var_1[max]', '10'],
    ['var_1[step]', '1'],
    ['var_2[name]', 'c'],
    ['var_2[mode]', 'list'],
    ['var_2[values]', '2, 4, 6'],

    'back'      => $backFromForm($variableEditor),
]);

$t->equals(3.0, (float) $db->value(
    'SELECT max_score FROM assignment_items WHERE id = :id',
    ['id' => $otherItem]
), 'цена чужой позиции не изменилась');
$t->equals(7.5, (float) $db->value(
    'SELECT max_score FROM assignment_items WHERE id = :id',
    ['id' => $variableItemId]
), 'своя цена не пострадала от попытки поменять чужую');

/*
 * Цена возвращается прежней: дальше в наборе идут проверки сдачи этой же
 * позиции, и они считают от её цены.
 */
$db->run('UPDATE assignment_items SET max_score = 2.0 WHERE id = :id', ['id' => $variableItemId]);

$t->equals(2.0, (float) $db->value(
    'SELECT max_score FROM assignment_items WHERE id = :id',
    ['id' => $variableItemId]
), 'цена позиции возвращена прежней для дальнейших проверок');

/*
 * Сохранение вариативной задачи проверяет тот же возврат по адресу, что и у
 * задания с вопросами: плагин другой, а ошибка была одна — Location без
 * ведущего слэша браузер разрешает относительно адреса плагина.
 */
$variableSaveCheck = $teacher->post('/variables/task/' . $variableTaskId, [
    '_token'    => $teacher->tokenFrom($variableEditor) ?? '',
    'action'    => 'save_task',
    'title'     => 'Работа по геометрии',
    'prompt'    => 'Число {a} больше {b} на сколько, если {c} — вдвое больше {b}?',
    'formula'   => 'a - b',
    'tolerance' => '0.01',
    'unit'      => '',

    // Поля переменных приходят как var_0[name] и тому подобное: клиент наборов
    // разворачивает массив в несколько одноимённых полей и вложенную структуру
    // сам не собирает.
    ['var_0[name]', 'a'],
    ['var_0[mode]', 'list'],
    ['var_0[values]', '6, 9, 12'],
    ['var_1[name]', 'b'],
    ['var_1[mode]', 'range'],
    ['var_1[min]', '2'],
    ['var_1[max]', '10'],
    ['var_1[step]', '1'],
    ['var_2[name]', 'c'],
    ['var_2[mode]', 'list'],
    ['var_2[values]', '2, 4, 6'],

    'back'      => $backFromForm($variableEditor),
]);

$t->is($variableSaveCheck['status'] === 302, 'вариативная задача сохранена');
$t->is(str_starts_with((string) $variableSaveCheck['location'], '/'),
    'адрес возврата вариативной задачи начинается с корня');
$t->is(!str_starts_with((string) $variableSaveCheck['location'], '/variables/task/'),
    'адрес возврата вариативной задачи не остался внутри адреса плагина');
$t->notContains((string) $variableSaveCheck['location'], 'edit=',
    'после сохранения вариативной задачи редактор закрыт');

/*
 * Работа выдаётся ученику лично: опубликовать её и ждать, что она появится
 * у класса, больше нельзя.
 */
$variableIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $variableWorkId,
    'author_id'       => $sharedAuthor,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $variableIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $variableWorkId]);

$variableStart = $student->post('/work/' . $variableWorkId . '/start', [
    '_token' => $student->tokenFrom($student->get('/work/' . $variableWorkId)) ?? '',
]);

$t->is($variableStart['status'] === 302, 'попытка вариативной задачи начата');
$t->is(
    preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', $variableStart['location'], $variableMatch) === 1,
    'редирект ведёт на вопрос вариативной задачи'
);

$variableAttemptId = (int) ($variableMatch[1] ?? 0);
$variableToken = (string) ($variableMatch[3] ?? '');

$variableItemPath = '/work/' . $variableAttemptId . '/item/' . $variableItemId;
$variablePage = $student->get($variableItemPath . '?t=' . $variableToken);

$t->is($variablePage['status'] === 200, 'экран вариативной задачи открыт');
$t->is(!str_contains($variablePage['body'], '{a}'), 'в условии нет незаполненных скобок');

// Значения, которые сервер подставил, едут в скрытых полях.
preg_match_all(
    '/name="values\[([a-z]+)\]"\s*value="([0-9.]+)"/',
    $variablePage['body'],
    $variableValueMatches,
    PREG_SET_ORDER
);

$variableShownValues = [];

foreach ($variableValueMatches as $variableValueMatch) {
    $variableShownValues[$variableValueMatch[1]] = (float) $variableValueMatch[2];
}

$t->equals(['a', 'b', 'c'], array_keys($variableShownValues), 'все значения варианта дошли до ученика скрытыми полями');
$t->is(in_array($variableShownValues['a'] ?? -1, [6.0, 9.0, 12.0], true), 'a выпало из перечня');
$t->is(
    in_array($variableShownValues['b'] ?? -1, [2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0], true),
    'b выпало из диапазона'
);
$t->is(in_array($variableShownValues['c'] ?? -1, [2.0, 4.0, 6.0], true), 'c выпало из перечня');

// Перезагрузка не должна менять пример: иначе ученик решает уже другую задачу.
$variableReload = $student->get($variableItemPath . '?t=' . $variableToken);

preg_match_all(
    '/name="values\[([a-z]+)\]"\s*value="([0-9.]+)"/',
    $variableReload['body'],
    $variableReloadMatches,
    PREG_SET_ORDER
);

$variableReloadValues = [];

foreach ($variableReloadMatches as $variableReloadMatch) {
    $variableReloadValues[$variableReloadMatch[1]] = (float) $variableReloadMatch[2];
}

$t->equals($variableShownValues, $variableReloadValues, 'перезагрузка не меняет выпавшие значения');

// Один ответ на задачу: формула считается от показанных ученику чисел.
$variableExpected = $variableShownValues['a'] - $variableShownValues['b'];

$variableSave = $student->post($variableItemPath, [
    '_token' => $student->tokenFrom($variablePage) ?? '',
    't'      => $variableToken,
    ['answer_present[' . $variableItemId . ']', '1'],
    ['answer', (string) $variableExpected],
    ['values[a]', (string) $variableShownValues['a']],
    ['values[b]', (string) $variableShownValues['b']],
    ['values[c]', (string) $variableShownValues['c']],
    ['action', 'next'],
]);

$t->is($variableSave['status'] === 302, 'ответ по вариативной задаче отправлен');

// Значения лежат в базе рядом с ответом, а не пересчитываются при проверке.
$variableItemAttemptId = (int) $db->value(
    'SELECT id FROM variable_item_attempts WHERE assignment_attempt_id = :a AND item_id = :i',
    ['a' => $variableAttemptId, 'i' => $variableItemId]
);

$t->is($variableItemAttemptId > 0, 'прохождение вариативной задачи заведено');

$variableStored = json_decode((string) $db->value(
    'SELECT answer_data FROM variable_item_answers WHERE item_attempt_id = :a',
    ['a' => $variableItemAttemptId]
), true);

$t->is(isset($variableStored['answer']), 'ответ сохранён');

$t->equals(
    (float) ($variableShownValues['a'] ?? 0),
    (float) ($variableStored['values']['a'] ?? 0),
    'сохранено именно то значение, что видел ученик'
);

$db->run(
    "UPDATE assignments SET status = 'draft' WHERE id = :id",
    ['id' => $variableWorkId]
);
$db->run("UPDATE assignments SET status = 'published' WHERE id = :id", ['id' => $variableWorkId]);

$variableFinish = $student->post('/work/' . $variableAttemptId . '/finish', [
    '_token' => $student->tokenFrom($student->get($variableItemPath . '?t=' . $variableToken)) ?? '',
    't'      => $variableToken,
]);

$t->is($variableFinish['status'] === 302, 'работа с вариативной задачей сдана');

$variableResult = $db->one(
    'SELECT score, max_score FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
    ['a' => $variableAttemptId, 'i' => $variableItemId]
);

$t->equals(
    (float) ($variableResult['max_score'] ?? 0),
    2.0,
    'максимум взят из цены позиции работы'
);

$t->equals(
    2.0,
    (float) ($variableResult['score'] ?? -1),
    'верный ответ по всем переменным даёт полный балл позиции'
);

// Правка учителем перечня не должна ломать уже сданный ответ.
$db->run(
    'UPDATE variable_tasks SET config = :c WHERE id = :id',
    [
        'c' => json_encode([
            'variables' => [
                ['name' => 'a', 'mode' => 'list', 'values' => '50, 60', 'min' => null, 'max' => null, 'step' => 1.0],
                ['name' => 'b', 'mode' => 'range', 'values' => '', 'min' => 20.0, 'max' => 30.0, 'step' => 1.0],
                ['name' => 'c', 'mode' => 'list', 'values' => '70, 80', 'min' => null, 'max' => null, 'step' => 1.0],
            ],
            'formula'   => 'a - b',
            'tolerance' => 0.01,
            'unit'      => '',
        ], JSON_UNESCAPED_UNICODE),
        'id' => $variableTaskId,
    ]
);

$db->run(
    "UPDATE assignment_item_results SET score = 0, status = 'pending' WHERE attempt_id = :a AND item_id = :i",
    ['a' => $variableAttemptId, 'i' => $variableItemId]
);

$db->run("UPDATE assignment_attempts SET status = 'in_progress' WHERE id = :id", ['id' => $variableAttemptId]);

$variableRegrade = $student->post('/work/' . $variableAttemptId . '/finish', [
    '_token' => $student->tokenFrom($student->get($variableItemPath . '?t=' . $variableToken)) ?? '',
    't'      => $variableToken,
]);

$t->equals(
    2.0,
    (float) ($db->value(
        'SELECT score FROM assignment_item_results WHERE attempt_id = :a AND item_id = :i',
        ['a' => $variableAttemptId, 'i' => $variableItemId]
    ) ?? -1),
    'после правки перечня уже сданный ответ всё ещё засчитан'
);

// --------------------------------------------------------------- параллель

$t->group('Параллель работы');

/*
 * Параллель — только подпись для папки. Она не адресует работу никому: ученик
 * из седьмого класса не получает работу из папки «8 классы», пока автор не
 * выдаст её лично.
 *
 * Значение вне 1..11 не заводит папку «255 классы»: такая папка ничего автору
 * не объясняет и молча уводит его работу из списка.
 */
$parallelEditor = $teacher->get('/panel/assignments/' . $assignmentId . '/edit/start');

$t->is($parallelEditor['status'] === 200, 'шаг с параллелью открыт');
$t->contains($parallelEditor['body'], 'Параллель', 'в редакторе есть поле параллели');

// Предмет берётся у самой работы: сохранение первого этапа требует его,
// иначе страница вернёт ошибку вместо записи.
$parallelSubjectId = (string) ($db->value('SELECT subject_id FROM assignments WHERE id = :id', ['id' => $assignmentId]) ?? '');

$teacher->post('/panel/assignments/' . $assignmentId . '/edit/start', [
    '_token'     => $teacher->tokenFrom($parallelEditor) ?? '',
    'title'      => 'Контрольная по биологии',
    'subject_id' => $parallelSubjectId,
    'parallel'   => '8',
]);

$t->equals(8, (int) $db->value('SELECT parallel FROM assignments WHERE id = :id', ['id' => $assignmentId]),
    'параллель сохранена');

/*
 * Значение вне границ не заводит папку «255 классы»: работа уходит в «Без
 * параллели», где автор её увидит и сможет поправить. Молча оставить прежнее
 * значение было бы хуже — человек решил бы, что сохранил.
 */
$teacher->post('/panel/assignments/' . $assignmentId . '/edit/start', [
    '_token'     => $teacher->tokenFrom($parallelEditor) ?? '',
    'title'      => 'Контрольная по биологии',
    'subject_id' => $parallelSubjectId,
    'parallel'   => '255',
]);

$t->is($db->value('SELECT parallel FROM assignments WHERE id = :id', ['id' => $assignmentId]) === null,
    'параллель вне границ убирается в «Без параллели»');

// Возвращаем параллель: остальные проверки работают с папкой «8 классы».
$teacher->post('/panel/assignments/' . $assignmentId . '/edit/start', [
    '_token'     => $teacher->tokenFrom($parallelEditor) ?? '',
    'title'      => 'Контрольная по биологии',
    'subject_id' => $parallelSubjectId,
    'parallel'   => '8',
]);

// Работа осталась у того же ученика: параллель ничего не адресует.
$t->is($student->get('/work/' . $assignmentId)['status'] === 200,
    'параллель не изменила того, кому работа выдана');

$t->contains($teacher->get('/panel/assignments?state=published')['body'], '8 классы',
    'работа лежит в папке своей параллели');

// --------------------------------------------------------------- дерево работ

$t->group('Дерево работ по папкам');

$treeList = $teacher->get('/panel/assignments?state=published');

$t->contains($treeList['body'], 'Без параллели', 'есть папка для работ без параллели');
/*
 * Классы учеников в дереве работ не появляются: папка — это параллель
 * работы, а не адресация. Если бы «8-А» нашлось здесь, это означало бы, что
 * дерево всё ещё адресует работы классами, а не подписывает их параллелью.
 */
$t->notContains($treeList['body'], '8-А', 'классы учеников в дереве работ не участвуют');

/*
 * Та же работа в дереве ровно одна: раскладывание по папкам не должно
 * продублировать её ни во «всех», ни в папке параллели.
 *
 * Считается не само название, а адрес удаления этой работы: он у строки
 * ровно один и принадлежит именно ей. По названию счёт больше не годится —
 * название теперь встречается ещё и в подписи кнопки удаления
 * («Удалить работу «…»?»), и такой подсчёт отвечал бы не на тот вопрос.
 */
$t->equals(1, substr_count($treeList['body'], '/panel/assignments/' . $assignmentId . '/delete'),
    'работа показана в дереве ровно один раз');

// --------------------------------------------------------------- результаты

$t->group('Результаты');

$t->is($teacher->get('/panel/results')['status'] === 404, 'старый раздел результатов убран');

/*
 * Попытка, ждущая проверки, заводится прямо здесь, а не берётся из ранее
 * пройденных проверок: выше по набору учитель уже проверял работу, и та
 * попытка давно не «ждёт проверки» — сводка не показала бы её правильно, а
 * проверка искала бы то, чего там быть не должно.
 */
$manualAttemptToken = 'svodka-ruchnaya-' . $attemptId;

$db->run(
    "INSERT INTO assignment_attempts
        (assignment_id, student_id, token, attempt_no, status, manual, percent, grade, started_at, finished_at)
     VALUES (:a, :u, :tok, 99, 'finished', 1, 50.00, 3, :st, NOW())",
    ['a' => $assignmentId, 'u' => $studentId, 'tok' => $manualAttemptToken, 'st' => date('Y-m-d H:i:s', strtotime('-30 minutes'))]
);

$manualAttemptId = (int) $db->value('SELECT id FROM assignment_attempts WHERE token = :tok', ['tok' => $manualAttemptToken]);

$t->is($manualAttemptId > 0, 'по работе есть попытка, ждущая проверки');

$summary = $teacher->get('/panel/issues/' . $seedIssueId);

$t->is($summary['status'] === 200, 'сводка по выдаче открыта');
$t->contains($summary['body'], 'Выдал', 'в сводке сказано, кто выдал');
$t->contains($summary['body'], 'Кому', 'в сводке сказано, сколько учеников получили работу');

// Ученик, которому работу не выдавали, в сводке не появляется.
$t->is(
    !str_contains($summary['body'], 'третий ученик'),
    'в сводке нет посторонних'
);

// Ручная проверка открывается из сводки.
$t->contains($summary['body'], '/attempts/' . $manualAttemptId,
    'из сводки есть ссылка на ручную проверку');

$t->contains($summary['body'], 'Длительность', 'в сводке есть длительность выполнения');
$t->contains($summary['body'], 'мин', 'длительность посчитана по началу и концу попытки');

// Чужая выдача учителю не видна: он отвечает за свои работы.
$t->is($teacher->get('/panel/issues/999999')['status'] === 404, 'несуществующая выдача — 404');

// --------------------------------------------------------------- средний балл

/*
 * Средний балл и рейтинги.
 *
 * Данные для отчёта заводятся прямо в базу: путь «ученик прошёл работу →
 * учитель проверил» проверяется в других группах набора, а здесь важно ровно то,
 * как отчёт читает строки. Всё равно ставятся настоящие связи — попытка
 * принадлежит работе, выданной ученику в его классе, — иначе проверялось бы не
 * то соединение, которое работает у людей.
 *
 * Правила счёта заданы владельцем: только проверенные попытки; период — срок
 * выдачи; повторная выдача — отдельный вклад; «навсегда» — учебный год.
 */
$t->group('Средний балл и рейтинги');

$ratingWorkId = (int) $db->insert('assignments', [
    'author_id'     => $teacherId,
    'title'         => 'Работа для среднего балла',
    'status'        => 'published',
    'subject_id'    => $parallelSubjectId === '' ? null : (int) $parallelSubjectId,
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'criteria'      => json_encode([
        ['grade' => 5, 'min_percent' => 85.0, 'color' => 'success'],
        ['grade' => 4, 'min_percent' => 70.0, 'color' => 'primary'],
        ['grade' => 3, 'min_percent' => 50.0, 'color' => 'warning'],
        ['grade' => 2, 'min_percent' => 0.0,  'color' => 'danger'],
    ], JSON_UNESCAPED_UNICODE),
    'show_results'  => 1,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

/**
 * Выдача работы и попытка под неё — одной заготовкой.
 */
$ratingAttempt = static function (
    int $assignmentId,
    int $authorId,
    int $studentId,
    int $classId,
    ?string $from,
    ?string $until,
    float $percent,
    int $grade,
    bool $checked,
    string $token
) use ($db): int {
    $issueId = (int) $db->insert('assignment_issues', [
        'assignment_id'   => $assignmentId,
        'author_id'       => $authorId,
        'available_from'  => $from,
        'available_until' => $until,
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    $db->insert('assignment_issue_students', [
        'issue_id'     => $issueId,
        'student_id'   => $studentId,
        'class_id'     => $classId,
        'assigned_at'  => date('Y-m-d H:i:s'),
    ]);

    return (int) $db->insert('assignment_attempts', [
        'assignment_id' => $assignmentId,
        'student_id'    => $studentId,
        'token'         => $token,
        'status'        => 'finished',
        // Номер попытки в пределах работы и ученика уникален, а повторных
        // выполнений у него сколько угодно — именно поэтому каждая из них
        // считается отдельным вкладом в среднем балле.
        'attempt_no'    => 1 + (int) $db->value(
            'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :a AND student_id = :s',
            ['a' => $assignmentId, 's' => $studentId]
        ),
        'score'         => $percent,
        'max_score'     => 100.0,
        'percent'       => $percent,
        'grade'         => $grade,
        'manual'        => $checked ? 0 : 1,
        // Начало внутри срока выдачи обязательно: попытка привязывается к
        // выдаче по времени начала, и начатое вне срока не досталось бы ни
        // одной — отчёт не увидел бы работу, выданную человеку на руки.
        'started_at'    => $from === null ? date('Y-m-d H:i:s') : (string) $from,
        'finished_at'   => date('Y-m-d H:i:s'),
        'checked_at'    => $checked ? date('Y-m-d H:i:s') : null,
        'checked_by'    => $checked ? $authorId : null,
    ]);
};

$ratingIssuedFrom = date('Y-m-d 00:00:00', strtotime('-10 days'));
$ratingIssuedUntil = date('Y-m-d 23:59:59', strtotime('+10 days'));

/*
 * Проверяется период 2031 года: в наборе нет ни одной выдачи с таким сроком,
 * поэтому в нём оказываются ровно те попытки, что заведены ниже. Иначе точные
 * суммы считались бы поверх чужих данных — и своих же, оставшихся от прошлых
 * прогонов, потому что набор не вычищает базу целиком.
 */
$ratingWindow = \MyEdu\Grading\RatingPeriod::fromRequest('dates:2031-05-01,2031-05-31');
$ratingRepo = $app->get(\MyEdu\Repository\RatingRepository::class);

$ratingAttempt(
    $ratingWorkId,
    $teacherId,
    $studentId,
    $classId,
    '2031-05-01 00:00:00',
    '2031-05-31 23:59:59',
    90.0,
    5,
    true,
    str_repeat('a', 64)
);

$t->equals(1, $ratingRepo->overall($ratingWindow)['works'], 'за май 2031 учтена одна работа');
$t->equals(90.0, $ratingRepo->overall($ratingWindow)['percent'], 'средний за май 2031 — 90');
$t->equals(5.0, $ratingRepo->overall($ratingWindow)['grade'], 'средняя оценка за май 2031 — 5');

// Работа с другим сроком — за пределами выбранного периода.
$ratingAttempt(
    $ratingWorkId,
    $teacherId,
    $otherId,
    $otherClassId,
    '2031-01-05 00:00:00',
    '2031-01-31 23:59:59',
    40.0,
    2,
    true,
    str_repeat('b', 64)
);

$t->equals(1, $ratingRepo->overall($ratingWindow)['works'],
    'работа с январским сроком в май 2031 не попала');

// Сдано, но ручная проверка не закончена: в счёт не идёт.
$ratingAttempt(
    $ratingWorkId,
    $teacherId,
    $otherId,
    $otherClassId,
    '2031-05-10 00:00:00',
    '2031-05-20 23:59:59',
    10.0,
    2,
    false,
    str_repeat('c', 64)
);

$t->equals(1, $ratingRepo->overall($ratingWindow)['works'],
    'непроверенная попытка за тот же срок в счёт не попала');
$t->equals(90.0, $ratingRepo->overall($ratingWindow)['percent'],
    'непроверенная попытка не занизила средний');

$ratingsWindow = $teacher->get('/panel/ratings?period=' . rawurlencode('dates:2031-05-01,2031-05-31'));

$t->is($ratingsWindow['status'] === 200, 'период по своим датам открыт');
$t->contains($ratingsWindow['body'], '90,0 %', 'на странице виден средний за выбранный период');
$t->notContains($ratingsWindow['body'], '40,0 %', 'работа другого периода на страницу не попала');

/*
 * Выдача «навсегда» относится к учебному году. Проверяется сравнением числа работ
 * до и после: за учебный год чужих попыток сколько угодно, а наша добавилась
 * ровно одна.
 */
$yearWindow = \MyEdu\Grading\RatingPeriod::fromRequest('year');
$beforeAlways = $ratingRepo->overall($yearWindow)['works'];

$ratingAttempt(
    $ratingWorkId,
    $teacherId,
    $studentId,
    $classId,
    null,
    null,
    75.0,
    4,
    true,
    str_repeat('d', 64)
);

$t->equals(
    $beforeAlways + 1,
    $ratingRepo->overall($yearWindow)['works'],
    'выдача «навсегда» учтена в учебном году'
);
$t->equals(
    1,
    $ratingRepo->overall($ratingWindow)['works'],
    'выдача «навсегда» не попала в май 2031'
);

/*
 * Повторная выдача той же работы — отдельный вклад, даже если срок тот же самый.
 * Считается по той же схеме: было 2 работы, стало 3.
 */
$beforeRepeat = $ratingRepo->overall($ratingWindow)['works'];

$ratingAttempt(
    $ratingWorkId,
    $teacherId,
    $studentId,
    $classId,
    '2031-05-02 00:00:00',
    '2031-05-30 23:59:59',
    100.0,
    5,
    true,
    str_repeat('e', 64)
);

$t->equals(
    $beforeRepeat + 1,
    $ratingRepo->overall($ratingWindow)['works'],
    'повторная выдача засчитана отдельной работой'
);

$ratingsAll = $teacher->get('/panel/ratings?period=all');

$t->is($ratingsAll['status'] === 200, 'страница среднего балла открыта — статус ' . $ratingsAll['status']);
$t->contains($ratingsAll['body'], 'За всё время', 'период «за всё время» выбран');
$t->contains($ratingsAll['body'], 'Средний балл', 'на странице есть заголовок раздела');

// Класс из выдачи, а не текущий: за всё время видны оба класса, и у второго
// есть переход к его ученикам.
$t->contains($ratingsAll['body'], 'Показать учеников', 'в классах есть переход к ученикам');
$t->contains($ratingsAll['body'], 'показаны ниже', 'выбранный класс отмечен и разворачивается ниже');

/*
 * Пустой период обязан говорить об этом словами. Молчаливая пустая таблица
 * читалась бы как «средний нулевой», а нулевой средний балл и отсутствие работ
 * — разные вещи.
 */
$emptyWindow = $teacher->get('/panel/ratings?period=' . rawurlencode('dates:2030-01-01,2030-01-31'));

$t->is($emptyWindow['status'] === 200, 'пустой период открыт');
$t->contains($emptyWindow['body'], 'За этот период нет проверенных работ',
    'пустой период объяснён, а не показан молча');

/*
 * Условие периода проверяется отдельно от страницы: при отключённой эмуляции
 * prepares повтор именованного параметра в одном запросе MySQL не принимает, и
 * отчёт падал с «Invalid parameter number» — по причине, которой в тексте
 * запроса не видно.
 */
$ratingYear = \MyEdu\Grading\RatingPeriod::fromRequest('year');
[$yearSql, $yearParams] = $ratingYear->issueSql();

$repeated = [];

foreach (array_keys($yearParams) as $name) {
    if (substr_count($yearSql, ':' . $name) !== 1) {
        $repeated[] = $name;
    }
}

$t->equals([], $repeated, 'каждый параметр условия периода встречается ровно один раз');
$t->equals(
    count(array_unique(array_keys($yearParams))),
    count($yearParams),
    'имена параметров не повторяются'
);

$t->is($teacher->get('/panel/ratings?period=' . rawurlencode('dates:мусор,2026-01-01'))['status'] === 200,
    'мусор в периоде не ломает страницу');

$t->group('Перенос работы между инсталляциями');

/*
 * Исходная работа для переноса собирается здесь целиком: предмет, тест с
 * вопросом и вариантами, картинка в описании. Всё, что потом проверяется на
 * новом месте, должно приехать именно отсюда — иначе проверка сравнивала бы
 * перенос с самим собой.
 */
$transferSubjectName = 'Физика переноса';
$transferSubjectId = (int) ($db->value(
    'SELECT id FROM subjects WHERE name = :n LIMIT 1',
    ['n' => $transferSubjectName]
) ?? 0);

if ($transferSubjectId === 0) {
    $transferSubjectId = (int) $db->insert('subjects', [
        'name'       => $transferSubjectName,
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

// Настоящая картинка: перенос проверяется на файле, который можно открыть, а не
// на строчке текста.
$transferImage = 'transfer-' . substr(md5('transfer-image'), 0, 8) . '.png';
$transferImageSource = $rootDir . '/public/uploads/' . $transferImage;

if (!is_file($transferImageSource)) {
    test_write_png($transferImageSource, 24, 16);
}

/*
 * Перенос проверяется кругом: работа выгружается в файл, файл вносится заново и
 * сравнивается с оригиналом. Проверять по частям тут нельзя — весь смысл
 * переноса в том, что на другом месте получится та же работа, а отдельная
 * проверка каждого поля не заметила бы, что забыли половину.
 */
$transferWorkId = (int) $db->insert('assignments', [
    'author_id'     => $sharedAuthor,
    'title'         => 'Работа для переноса',
    'description'   => 'Описание с картинкой <img src="/uploads/' . $transferImage . '" alt="схема">',
    'subject_id'    => $transferSubjectId,
    'status'        => 'published',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'score',
    'show_results'  => 1,
    'time_limit'    => 25,
    // Числа попыток у работы нет: ограничение принадлежит выдаче, и в пакете
    // переноса его тоже быть не должно.
    'criteria'      => json_encode([
        ['grade' => 5, 'min_score' => 16, 'max_score' => 20],
        ['grade' => 4, 'min_score' => 11],
        ['grade' => 3, 'min_score' => 6],
        ['grade' => 2, 'min_score' => 0],
    ], JSON_UNESCAPED_UNICODE),
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$transferTestId = (int) $db->insert('tests', [
    'subject_id'        => null,
    'author_id'         => $sharedAuthor,
    'class_id'          => null,
    'title'             => 'Тест для переноса',
    'description'       => 'Проверка переноса',
    'time_limit_min'    => 0,
    'shuffle_questions' => 1,
    'shuffle_options'   => 0,
    'max_attempts'      => 3,
    'show_results'      => 1,
    'status'            => 'published',
    'created_at'        => date('Y-m-d H:i:s'),
    'updated_at'        => date('Y-m-d H:i:s'),
]);

$transferQuestionId = (int) $db->insert('questions', [
    'test_id'     => $transferTestId,
    'type'        => 'single_choice',
    'prompt'      => 'Что изображено на схеме?',
    'position'    => 1,
    'weight'      => 2.5,
    'is_required' => 1,
    'explanation' => 'Схема приезжает вместе с переносом.',
    'config'      => json_encode(['correct' => 'a'], JSON_UNESCAPED_UNICODE),
]);

foreach ([['a', 'Первый', 1], ['b', 'Второй', 2]] as [$key, $text, $position]) {
    $db->insert('question_options', [
        'question_id' => $transferQuestionId,
        'kind'        => 'single',
        'value_key'   => $key,
        'text'        => $text,
        'position'    => $position,
        'pair_key'    => null,
    ]);
}

$db->insert('assignment_items', [
      'assignment_id'  => $transferWorkId,
      'position'       => 1,
      'plugin_id'      => 'test',
      'task_kind'      => 'single_choice',
      'task_id'        => $transferTestId,
      'weight'         => 1.0,
      'max_score'      => 6.0,
      'created_at'     => date('Y-m-d H:i:s'),
]);

/*
 * Первая строка — из трёх вариантов.
 *
 * Без них перенос проверялся бы только на работах, где у каждой позиции один
 * вариант, и потерялся бы самый обидный случай: перенесённая работа собралась
 * бы из трёх отдельных позиций, и ученик получил бы все три задания сразу.
 */
foreach (['Альтернатива переноса вторая', 'Альтернатива переноса третья'] as $variantIndex => $variantTitle) {
    $transferVariantTestId = (int) $db->insert('tests', [
        'subject_id' => null, 'author_id' => $sharedAuthor, 'class_id' => null,
        'title' => $variantTitle, 'description' => 'Вариант строки', 'time_limit_min' => 0,
        'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 1,
        'show_results' => 1, 'status' => 'published',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    /*
     * Настройка проверки обязательна, и без неё вопрос не проверяем вовсе:
     * импорт пакета это проверяет, как и редактор при сохранении. Пустой config
     * в фикстуре давал задание, у которого нет ни одного верного ответа и ни
     * одной стратегии, — оно переезжало, а проверить его было нечем.
     */
    $db->insert('questions', [
        'test_id' => $transferVariantTestId, 'type' => 'text',
        'prompt' => 'Вопрос варианта: ' . $variantTitle,
        'position' => 1, 'weight' => 1.0, 'is_required' => 1,
        'config' => json_encode(['strategy' => 'manual'], JSON_UNESCAPED_UNICODE),
    ]);

    $db->insert('assignment_items', [
        'assignment_id'  => $transferWorkId,
        'position'       => 1,
        'variant'        => $variantIndex + 2,
        'plugin_id'      => 'test',
        'task_kind'      => 'text',
        'task_id'        => $transferVariantTestId,
        'weight'         => 1.0,
        'max_score'      => 6.0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

$exportResponse = $teacher->get('/panel/assignments/' . $transferWorkId . '/export');
$exportHeaders = implode("\n", $exportResponse['headers'] ?? []);

$t->is($exportResponse['status'] === 200, 'выгрузка работы отдаётся файлом');
$t->contains($exportHeaders, 'application/zip', 'файл выгружается как архив');
$t->contains($exportHeaders, 'Content-Disposition: attachment', 'файл предлагается сохранить, а не открыть в браузере');

$exportBytes = (string) ($exportResponse['body'] ?? '');

/*
 * Сигнатура zip обязательна в обоих режимах: без zip-расширения архив
 * собирается своими руками, но формат остаётся zip — те же заголовки и
 * центральный каталог. Если бы формат был свой, файл не открылся бы
 * обычным архиватором, и это надо ловить здесь.
 */
$t->equals(substr($exportBytes, 0, 2), 'PK', 'файл начинается как zip');

$packagePath = $rootDir . '/var/transfer-test.myedu.zip';
file_put_contents($packagePath, $exportBytes);

/*
 * Читается тем же разборщиком, что и на сервере: без этого проверка знала бы
 * только про zip-расширение, а без него перенос вовсе не работал — и молчал.
 */
try {
    $readPackage = MyEdu\Transfer\WorkPackageReader::read($packagePath);
    $readOk = true;
    $readWhy = '';
} catch (MyEdu\Transfer\TransferException $e) {
    $readPackage = ['manifest' => [], 'work' => [], 'media' => []];
    $readOk = false;
    $readWhy = $e->getMessage();
}

$t->is($readOk, 'выгруженный файл читается как пакет переноса' . ($readWhy === '' ? '' : ' [' . $readWhy . ']'));

if (!$readOk) {
    /*
     * Файл не прочитался — дальше идти незачем: проверки ниже разбирали бы
     * содержимое пакета и падали бы не по своей причине.
     */
    $t->report();
    exit(1);
}

$t->equals('myedu-work', (string) $readPackage['manifest']['format'], 'манифест помечает файл как перенос работы');
$t->equals('Работа для переноса', (string) $readPackage['work']['title'], 'название работы перенесено');
$t->equals(
    $transferSubjectName,
    (string) $readPackage['work']['subject'],
    'предмет переносится названием, а не номером'
);
$t->equals(25, (int) $readPackage['work']['time_limit'], 'лимит времени перенесён');
$t->equals('score', (string) $readPackage['work']['criteria_mode'], 'режим сведения перенесён');
$t->equals(3, count($readPackage['work']['items']), 'позиции работы в пакете на месте');
$t->equals(
    [1, 2, 3],
    array_map(static fn (array $item): int => (int) ($item['variant'] ?? 0), $readPackage['work']['items']),
    'варианты строки выгружены по номерам, а не слиплись в три позиции'
);
$t->equals(1, count($readPackage['media']), 'картинка из описания вывезена вместе с работой');

/*
 * Картинки не должны осиротеть, если создание работы откатилось после их
 * записи. Заставляем INSERT работы отказать внешним ключом и проверяем, что
 * каталог временного импорта после rollback пуст.
 */
$rollbackMediaDir = sys_get_temp_dir() . '/myedu-transfer-rollback-' . bin2hex(random_bytes(6));
mkdir($rollbackMediaDir, 0700, true);
$failedImport = false;

try {
    (new MyEdu\Transfer\WorkImporter(
        $db,
        $app->get(MyEdu\Plugin\PluginManager::class),
        $app->get(MyEdu\Auth\SubjectRepository::class)
    ))->import($readPackage, 4294967295, $rollbackMediaDir);
} catch (Throwable) {
    $failedImport = true;
}

$t->is($failedImport, 'ошибка записи работы инициирует rollback импорта');
$t->equals([], glob($rollbackMediaDir . '/*') ?: [], 'после rollback удалены временно сохранённые картинки');
@rmdir($rollbackMediaDir);

$payload = $readPackage['work']['items'][0]['payload'] ?? [];

$t->equals('Тест для переноса', (string) ($payload['test']['title'] ?? ''), 'название задания перенесено');
$t->equals(1, count($payload['questions'] ?? []), 'вопрос перенесён');
$t->equals('single_choice', (string) ($payload['questions'][0]['type'] ?? ''), 'тип вопроса перенесён');
$t->equals(2.5, (float) ($payload['questions'][0]['weight'] ?? 0), 'вес вопроса перенесён');
$t->equals(
    'Схема приезжает вместе с переносом.',
    (string) ($payload['questions'][0]['explanation'] ?? ''),
    'комментарий учителя перенесён'
);
$t->equals(2, count($payload['questions'][0]['options'] ?? []), 'варианты ответа перенесены');

/*
 * Внос. Сначала файл только разбирается и показывается: человек должен увидеть
 * состав пакета до того, как в базе появится работа.
 */
$importPage = $teacher->get('/panel/assignments/import');

$t->is($importPage['status'] === 200, 'страница переноса открывается');
$t->contains($importPage['body'], 'Внести работу из файла', 'на странице есть форма для файла');

$uploaded = $teacher->upload('/panel/assignments/import/upload', [
    '_token' => $teacher->tokenFrom($importPage) ?? '',
], [
    'package' => $packagePath,
]);

$t->is($uploaded['status'] === 302, 'файл принят на разбор');

$beforeImport = (int) $db->value(
    'SELECT COUNT(*) FROM assignments WHERE title = :t',
    ['t' => 'Работа для переноса']
);

$t->equals(1, $beforeImport, 'до вноса такой работы ровно одна — исходная');

$prepared = $teacher->get('/panel/assignments/import');

$t->contains($prepared['body'], 'Работа для переноса', 'на странице показано название из пакета');
/*
     * Назвать задание до вноса нечем: заголовок ушёл из переноса вместе с
     * колонкой, а условия в пакете нет. Показывается то, что пакет действительно
     * несёт, — номер строки и плагин.
     *
     * Проверялось имя задания, которого в пакете не бывает: страница писала
     * «Без названия» против каждой позиции и молчала об этом.
     */
    $t->contains($prepared['body'], '№1', 'на странице показано, какие задания приедут');
$t->contains($prepared['body'], 'Картинок', 'на странице показан счётчик картинок');
$t->equals(1, (int) $db->value(
    'SELECT COUNT(*) FROM assignments WHERE title = :t',
    ['t' => 'Работа для переноса']
), 'разбор пакета ничего не записал в базу');

$imported = $teacher->post('/panel/assignments/import', [
    '_token' => $teacher->tokenFrom($prepared) ?? '',
]);

$t->is($imported['status'] === 302, 'внос отвечает переходом на работу');

$importedWorkId = (int) ($db->value(
    'SELECT id FROM assignments WHERE title = :t ORDER BY id DESC LIMIT 1',
    ['t' => 'Работа для переноса']
) ?? 0);

$t->is($importedWorkId > $transferWorkId, 'на втором месте появилась новая работа');

$importedRow = $db->one('SELECT * FROM assignments WHERE id = :id', ['id' => $importedWorkId]);

$t->equals('draft', (string) $importedRow['status'], 'перенесённая работа создана черновиком');
$t->equals('weighted', (string) $importedRow['grade_mode'], 'способ сведения перенесён');
$t->equals(25, (int) $importedRow['time_limit'], 'лимит времени перенесён');
$t->equals((int) $sharedAuthor, (int) $importedRow['author_id'], 'автором стал тот, кто вносил');

$importedItem = $db->one(
    'SELECT * FROM assignment_items WHERE assignment_id = :a ORDER BY position LIMIT 1',
    ['a' => $importedWorkId]
);

$t->is($importedItem !== null, 'задание перенесено в новую работу');
$t->equals('test', (string) $importedItem['plugin_id'], 'плагин задания тот же');
$t->equals('single_choice', (string) $importedItem['task_kind'], 'вид задания тот же');
$t->equals(6.0, (float) $importedItem['max_score'], 'цена позиции перенесена');
$t->is((int) $importedItem['task_id'] !== $transferTestId, 'задание создано заново, а не ссылается на чужое');

/*
 * Главное про варианты: на новом месте это снова одна строка из трёх карточек,
 * а не три позиции работы.
 */
$importedVariants = $db->all(
    'SELECT position, variant FROM assignment_items WHERE assignment_id = :a ORDER BY variant',
    ['a' => $importedWorkId]
);

$t->equals(
    [1, 2, 3],
    array_map(static fn (array $row): int => (int) $row['variant'], $importedVariants),
    'строка с вариантами перенесена как строка, а не как три отдельные позиции'
);
$t->equals(
    [1, 1, 1],
    array_map(static fn (array $row): int => (int) $row['position'], $importedVariants),
    'все три карточки остались в одной позиции работы'
);

$importedTest = $db->one('SELECT * FROM tests WHERE id = :id', ['id' => (int) $importedItem['task_id']]);

$t->equals('Тест для переноса', (string) $importedTest['title'], 'название задания на месте');
$t->equals(1, (int) $importedTest['shuffle_questions'], 'перемешивание вопросов перенесено');
$t->equals(3, (int) $importedTest['max_attempts'], 'число попыток задания перенесено');

$importedQuestion = $db->one(
    'SELECT * FROM questions WHERE test_id = :t ORDER BY position LIMIT 1',
    ['t' => (int) $importedItem['task_id']]
);

$t->equals('Что изображено на схеме?', (string) $importedQuestion['prompt'], 'условие вопроса перенесено');
$t->equals(2.5, (float) $importedQuestion['weight'], 'вес вопроса перенесён');
$t->equals(2, (int) $db->value(
    'SELECT COUNT(*) FROM question_options WHERE question_id = :q',
    ['q' => (int) $importedQuestion['id']]
), 'варианты ответа переехали вместе с вопросом');

/*
 * Картинка. Это главное, что нельзя упустить: без неё перенос выглядит
 * удачным, а условие на новом месте остаётся без схемы.
 */
preg_match('#<img src="(/uploads/[^"]+)"#', (string) $importedRow['description'], $importedImg);

$t->is(($importedImg[1] ?? '') !== '', 'в описании перенесённой работы есть ссылка на картинку');
$t->is(
    ($importedImg[1] ?? '') !== '/uploads/' . $transferImage,
    'картинка на новом месте лежит под своим именем, а не под чужим'
);
$t->is(is_file($rootDir . '/public' . ($importedImg[1] ?? '')), 'файл картинки действительно появился на диске');
$t->equals(
    filesize($rootDir . '/public/uploads/' . $transferImage),
    is_file($rootDir . '/public' . ($importedImg[1] ?? ''))
        ? filesize($rootDir . '/public' . ($importedImg[1] ?? ''))
        : -1,
    'картинка перенесена целиком, а не пустым файлом'
);

/*
 * Данные о выдаче не переносятся: на новом месте их нет и быть не должно.
 */
$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $importedWorkId]
), 'данные о выдаче не перенесены');
$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE assignment_id = :a',
    ['a' => $importedWorkId]
), 'попытки учеников не перенесены');

// Мусор вместо пакета должен быть отвергнут словами, а не тишиной.
$brokenPath = $rootDir . '/var/transfer-broken.zip';
file_put_contents($brokenPath, 'это не архив');

$brokenPage = $teacher->get('/panel/assignments/import');
$broken = $teacher->upload('/panel/assignments/import/upload', [
    '_token' => $teacher->tokenFrom($brokenPage) ?? '',
], [
    'package' => $brokenPath,
]);

$t->is($broken['status'] === 302, 'мусорный файл не ломает страницу');

$afterBroken = $teacher->get('/panel/assignments/import');

$t->notContains($afterBroken['body'], 'Внести на это место', 'мусорный пакет не предлагают внести');
$t->contains($afterBroken['body'], 'Файл переноса', 'причина отказа названа: файл не архив');

// Чужая работа не выгружается.
$otherWorkId = (int) $db->insert('assignments', [
    'author_id'    => (int) $db->value('SELECT id FROM users WHERE id <> :a ORDER BY id LIMIT 1', ['a' => $sharedAuthor]),
    'title'        => 'Чужая работа для проверки выгрузки',
    'status'       => 'draft',
    'grade_mode'   => 'weighted',
    'created_at'   => date('Y-m-d H:i:s'),
    'updated_at'   => date('Y-m-d H:i:s'),
]);

$t->is($teacher->get('/panel/assignments/' . $otherWorkId . '/export')['status'] === 403,
    'чужую работу выгрузить нельзя');

$t->group('Импорт теста из OnlineTestPad');

/*
 * Тест для переноса собирается здесь, а не берётся готовым файлом: у него
 * должны быть ровно те типы заданий, которые разбор обязан перенести, и ровно
 * один, который перенести нечем. Готовый файл из интернета менялся бы вместе с
 * чужой версией и однажды перестал бы проверять то, ради чего написан.
 */
$otpTestJs = static function (array $questions): string {
    $test = [
        'Name'                            => 'Тест для переноса',
        'InstructionsTitle'               => 'Инструкция',
        'Instructions'                    => '<p>Отвечайте внимательно.</p>',
        'ShowRightAnswers'                => true,
        'IsRandomAnswersInAllQuestions'   => false,
        'Params'                          => ['timelimited' => true, 'timelimitminutes' => 25, 'timelimitseconds' => 0],
        'Questions'                       => $questions,
    ];

    // BOM и хвост `var testCss` — как в настоящей выгрузке: разбор обязан
    // справиться с обоими, иначе он работает только на файлах из учебника.
    return "\xEF\xBB\xBFvar test = \n"
        . (string) json_encode($test, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\nvar testCss = \"\";\n";
};

$otpAnswer = static fn (string $text, float $score = 0.0, string $valueText = '', float $valueFloat = 0.0): array => [
    'Id' => 0, 'Text' => '<p>' . $text . '</p>', 'Score' => $score,
    'ValueText' => $valueText, 'ValueFloat' => $valueFloat,
    'ValueInt' => 0, 'IsRightAnswer' => false,
];

$otpQuestions = [
    [
        'Number' => 1, 'QuestionType' => 10, 'AnswerType' => 10, 'maxScore' => 1.0,
        'Text' => '<p>Сколько бит в байте?</p>', 'Comment' => '<p>Восемь.</p>',
        'AnswerIsRequired' => true,
        'AnswerTemplates' => [
            $otpAnswer('Восемь', 1.0),
            $otpAnswer('Сто'),
            $otpAnswer('Тысяча'),
        ],
    ],
    [
        'Number' => 2, 'QuestionType' => 10, 'AnswerType' => 210, 'maxScore' => 2.0,
        'Text' => '<p>Какие это единицы?</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [
            $otpAnswer('Байт', 1.0),
            $otpAnswer('Килобайт', 1.0),
            $otpAnswer('Луна'),
            $otpAnswer('Ватт'),
        ],
    ],
    [
        'Number' => 3, 'QuestionType' => 10, 'AnswerType' => 510, 'maxScore' => 1.0,
        'Text' => '<p>Расположите по порядку.</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [
            array_merge($otpAnswer('Кило'), ['ValueInt' => 3]),
            array_merge($otpAnswer('Единица'), ['ValueInt' => 1]),
            array_merge($otpAnswer('Мега'), ['ValueInt' => 2]),
        ],
    ],
    [
        'Number' => 4, 'QuestionType' => 10, 'AnswerType' => 610, 'maxScore' => 3.0,
        'Text' => '<p>Установите связь.</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [
            array_merge($otpAnswer('Байт'), ['Id' => 101, 'ValueInt' => 201]),
            array_merge($otpAnswer('Килобайт'), ['Id' => 102, 'ValueInt' => 202]),
            array_merge($otpAnswer('Мегабайт'), ['Id' => 103, 'ValueInt' => 203]),
        ],
        'AnswerTemplatesDop' => [
            array_merge($otpAnswer('8 бит'), ['Id' => 201]),
            array_merge($otpAnswer('1024 байт'), ['Id' => 202]),
            array_merge($otpAnswer('1024 КБ'), ['Id' => 203]),
        ],
    ],
    [
        'Number' => 5, 'QuestionType' => 10, 'AnswerType' => 410, 'maxScore' => 2.0,
        'Text' => '<p>Сколько мегабайт в гигабайте?</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [$otpAnswer('', 1.0, '', 1024.0)],
    ],
    [
        'Number' => 6, 'QuestionType' => 10, 'AnswerType' => 460, 'maxScore' => 1.0,
        'Text' => '<p>В одном байте {txt} бит.</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [$otpAnswer('', 1.0, '8')],
    ],
    [
        'Number' => 7, 'QuestionType' => 10, 'AnswerType' => 420, 'maxScore' => 1.0,
        'Text' => '<p>Как называется единица?</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [$otpAnswer('', 1.0, 'Байт')],
    ],
    [
        // Типа в системе нет: должен попасть в отчёт, а не в работу.
        'Number' => 8, 'QuestionType' => 10, 'AnswerType' => 800, 'maxScore' => 1.0,
        'Text' => '<p>Передвиньте ползунок.</p>', 'AnswerIsRequired' => false,
        'AnswerTemplates' => [$otpAnswer('', 1.0, '50')],
    ],
];

/*
 * Имя файла на диске важно не только для человека: по нему мастер решает, тот
 * ли это файл. Сборщик архивов кладёт его во временный файл без расширения, а
 * браузер отправляет на сервер имя файла, а не путь — поэтому архив переносится
 * под именем с настоящим .zip.
 */
$otpArchiveNamed = static function (array $files, string $key): string {
    $built = MyEdu\Transfer\Zip::write($files);
    $named = sys_get_temp_dir() . '/myedu-otp-' . substr(md5($key), 0, 10) . '.zip';
    copy($built, $named);
    @unlink($built);

    return $named;
};

$otpZipPath = $otpArchiveNamed(['test.js' => $otpTestJs($otpQuestions)], 'test');

/*
 * Настоящий PDF нужен не для разбора, а ради расширения.
 *
 * Мастер отказывает по имени присланного файла: PDF отличается от zip
 * расширением, и сказать об этом сразу полезнее, чем после сообщения
 * «архив не распаковывается». Поэтому файл должен быть непустым - иначе
 * проверка размера срабатывает раньше проверки расширения и отказ по PDF
 * не наступает вовсе. Раньше проверка обходила это, приложив несуществующий
 * файл: отправлялось пустое тело, а искомые слова находились в тексте
 * страницы, и ветка оставалась незамеченной.
 */
$otpPdfPath = static function (string $key): string {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
        '<< /Length ' . strlen('BT /F1 12 Tf 20 100 Td (test) Tj ET') . ' >>'
            . ' stream' . "\n" . 'BT /F1 12 Tf 20 100 Td (test) Tj ET' . "\n" . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }

    $start = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
        . "startxref\n" . $start . "\n%%EOF\n";

    $named = sys_get_temp_dir() . '/myedu-otp-' . substr(md5($key), 0, 10) . '.pdf';
    file_put_contents($named, $pdf);

    return $named;
};

$otpPdfPath = $otpPdfPath('pdf');

$t->is(MyEdu\Transfer\Zip::read($otpZipPath) !== [], 'архив собран и читается');

// Вкладки на странице переноса.
$transferTabs = $teacher->get('/panel/assignments/import');

$t->contains($transferTabs['body'], 'Импорт из своего файла', 'на странице переноса есть вкладка своего файла');
$t->contains($transferTabs['body'], 'Импорт из OnlineTestPad', 'на странице переноса есть вкладка OnlineTestPad');
$t->contains($transferTabs['body'], 'class="nav nav-tabs"', 'вкладки нарисованы как вкладки');

// Ровно одна вкладка отмечена, и на каждой странице — своя. Без этого человек
// не видел бы, в какой вкладке находится: обе выглядели бы одинаково.
// Считается внутри блока вкладок: такие же отметки есть у пунктов бокового меню.
$otpTabs = static function (string $html): string {
    return preg_match('~(?s)<nav aria-label="Откуда переносим работу">.*?</nav>~', $html, $m) === 1
        ? $m[0]
        : '';
};

$t->equals(1, preg_match_all('/class="nav-link active"/', $otpTabs($transferTabs['body'])), 'отмечена ровно одна вкладка');

// Шаг 1.
$otpStart = $teacher->get('/panel/assignments/import/otp');

$t->is($otpStart['status'] === 200, 'страница переноса открывается');
$t->contains($otpStart['body'], 'test.js', 'сказано, какой файл нужен');
$t->contains($otpStart['body'], 'PDF не подойдёт', 'про PDF сказано заранее');

/*
 * Описание переноса — один блок, и в нём сказано всё, что человек делает.
 *
 * Раньше описаний было два: как выгрузить файл — внутри карточки с загрузкой,
 * и что переносится — отдельной карточкой под ней. Между ними стояла сама
 * загрузка, и человек перескакивал через форму туда-обратно. Проверяется, что
 * описание одно и что в нём есть и требование к файлу, и судьба работы.
 */
$t->equals(1, substr_count($otpStart['body'], 'Как перенести тест'),
    'описание переноса одно');
$t->notContains($otpStart['body'], 'Что переносится',
    'второго блока описания больше нет');
$t->contains($otpStart['body'], 'Выгрузите работу из сервиса OnlineTestPad',
    'в описании сказано, как выгрузить файл');
$t->contains($otpStart['body'], 'попарное сопоставление',
    'перечислены поддерживаемые типы заданий');
$t->contains($otpStart['body'], 'Перенесённая работа попадёт в черновики',
    'сказано, что работа попадёт в черновики');

/*
 * Полосы шагов на странице переноса нет.
 *
 * Шагов здесь два, и второй открывается только после разбора файла: выбора
 * нет, а рядом стоят две вкладки «Откуда переносим». Полоса из двух кружков
 * рядом с двумя вкладками читалась как третий способ выбрать, куда идти.
 */
$t->notContains($otpStart['body'], 'class="stepper"',
    'на странице переноса нет полосы шагов');
$t->equals(1, preg_match_all('/class="nav-link active"/', $otpTabs($otpStart['body'])), 'на своей вкладке отмечена ровно одна вкладка');
$t->is(
    preg_match('~class="nav-link active".*?>\s*Импорт из OnlineTestPad~su', $otpTabs($otpStart['body'])) === 1,
    'отмечена именно вкладка OnlineTestPad'
);

// Чужой формат отвергается словами, а не молча.
$otpPdf = $teacher->upload('/panel/assignments/import/otp/upload', [
    '_token' => $teacher->tokenFrom($otpStart) ?? '',
], [
    'package' => $otpPdfPath,
]);

$t->is($otpPdf['status'] === 302, 'PDF отвергнут и отправлен обратно');

$otpAfterPdf = $teacher->get('/panel/assignments/import/otp');

$t->is($otpAfterPdf['body'] !== '', 'страница после отказа открылась');

/*
 * Причина отказа — из сообщения сервера, а не из описания на странице.
 *
 * Раньше проверка искала слова «нужен другой файл», и они попадали в страницу
 * из поясняющего абзаца про форматы: получалось, что проверка убеждалась в
 * наличии объяснения на странице и ничего не говорила о том, что человек
 * реально увидел после отказа. Теперь ищется само сообщение об отказе.
 */
$t->contains($otpAfterPdf['body'], 'PDF не разбирается',
    'причина отказа PDF названа');
$t->contains($otpAfterPdf['body'], 'офлайн-версию',
    'при отказе сказано, что приложить вместо PDF');
$t->notContains($otpAfterPdf['body'], 'class="stepper"',
    'и после отказа шагов на странице не появилось');

@unlink($otpPdfPath);

// Архив без test.js — тоже не то, что нужно.
$otpForeignZip = $otpArchiveNamed(['readme.txt' => 'это не тест'], 'foreign');
$otpForeign = $teacher->upload('/panel/assignments/import/otp/upload', [
    '_token' => $teacher->tokenFrom($otpAfterPdf) ?? '',
], [
    'package' => $otpForeignZip,
]);

$t->is($otpForeign['status'] === 302, 'чужой архив отвергнут');
$t->contains($teacher->get('/panel/assignments/import/otp')['body'], 'нет файла test.js', 'причина отказа чужого архива названа');

@unlink($otpForeignZip);

// Разбор настоящего файла.
$otpUploaded = $teacher->upload('/panel/assignments/import/otp/upload', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/import/otp')) ?? '',
], [
    'package' => $otpZipPath,
]);

$t->is($otpUploaded['status'] === 302, 'файл теста принят на разбор');
$t->is(
    str_contains((string) ($otpUploaded['location'] ?? ''), '/panel/assignments/import/otp'),
    'после разбора возвращаемся на ту же страницу, где приложен файл'
);

$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignments WHERE title = :t',
    ['t' => 'Тест для переноса']
), 'разбор ничего не записал в базу');

/*
 * Финальный шаг — на той же странице, что и разбор.
 *
 * Решение владельца: разбор и создание работы живут на одном адресе. Раньше
 * это были две страницы, и вторая ничего не знала о первой: человек уходил с
 * разбора на другую страницу, где введённое до этого не сохранилось, — и
 * возвращаться пришлось бы адресом, который помнить трудно.
 */
$otpSetup = $teacher->get('/panel/assignments/import/otp');

$t->is($otpSetup['status'] === 200, 'страница с разбором открывается');
$t->is(
    preg_match('~class="nav-link active".*?>\s*Импорт из OnlineTestPad~su', $otpTabs($otpSetup['body'])) === 1,
    'вкладка осталась отмеченной'
);
$t->contains($otpSetup['body'], 'Тест для переноса', 'название теста показано');
$t->contains($otpSetup['body'], 'Один вариант ответа', 'название нашего типа задания взято у плагина');
$t->contains($otpSetup['body'], 'Не перенесено', 'пропущенные задания перечислены');
$t->contains($otpSetup['body'], 'ползунок', 'причина пропуска названа по-русски');
$t->contains($otpSetup['body'], '25 мин.', 'лимит времени из выгрузки перенесён в разбор');
$t->contains($otpSetup['body'], 'name="subject"', 'страница спрашивает предмет');
$t->contains($otpSetup['body'], 'name="parallel"', 'страница спрашивает параллель');

/*
 * Страница одна, а не две: файла на ней уже не спрашивают — разбор только что
 * сделан, и повторный вопрос о файле сбивал бы с толку.
 */
$t->notContains($otpSetup['body'], 'name="package"',
    'после разбора файл на странице не спрашивают заново');

/*
 * Старый адрес второго шага остаётся рабочим: он отправляет на страницу
 * разбора, чтобы ссылка из закладки не приводила в отказ.
 */
$otpOldStep = $teacher->get('/panel/assignments/import/otp/setup');

$t->is($otpOldStep['status'] === 302, 'старый адрес второго шага не отказывает');
$t->is(
    str_contains((string) ($otpOldStep['location'] ?? ''), '/panel/assignments/import/otp'),
    'старый адрес отправляет на страницу разбора'
);

/*
 * Предмет и параллель — два одинаковых по поведению списка.
 *
 * Предмет был полем с подсказками, и браузер рисовал его как меню: рядом с ним
 * стоял настоящий список параллели, и два соседних поля выглядели одинаково, а
 * по щелчку вели себя по-разному — одно открывалось, другое листалось стрелками.
 * Проверяется именно отсутствие списка подсказок: он и есть то меню, о котором
 * речь, и по одной проверке `name="subject"` его не отличить.
 */
$t->is(
    preg_match('~<select[^>]*name="subject"~', $otpSetup['body']) === 1,
    'предмет — обычный список, как параллель'
);
$t->is(
    preg_match('~<select[^>]*name="parallel"~', $otpSetup['body']) === 1,
    'параллель осталась списком'
);
$t->notContains($otpSetup['body'], 'otp-subjects', 'списка подсказок у предмета больше нет');
$t->notContains($otpSetup['body'], '<datalist', 'меню подсказок убрано');

// Без предмета работа не создаётся, и разбор переживает отказ.
$otpNoSubject = $teacher->post('/panel/assignments/import/otp/setup', [
    '_token'   => $teacher->tokenFrom($otpSetup) ?? '',
    'subject'  => '',
    'parallel' => '3',
]);

$t->is($otpNoSubject['status'] === 302, 'пустой предмет отвергнут');
$t->is(
    str_contains((string) ($otpNoSubject['location'] ?? ''), '/panel/assignments/import/otp'),
    'отказ возвращает на ту же страницу'
);
$t->contains(
    $teacher->get('/panel/assignments/import/otp')['body'],
    'Отвечайте внимательно',
    'после отказа разбор на месте — файл не нужно прикладывать заново'
);

$otpSetupAgain = $teacher->get('/panel/assignments/import/otp');
$otpCreated = $teacher->post('/panel/assignments/import/otp/setup', [
    '_token'   => $teacher->tokenFrom($otpSetupAgain) ?? '',
    'subject'  => 'Информатика переноса',
    'parallel' => '3',
]);

$t->is($otpCreated['status'] === 302, 'работа создана и отправлена на свою страницу');

// Причина пропуска обязана быть видна сразу, а не всплыть на выдаче работы.
$otpCreatedPage = $teacher->get((string) ($otpCreated['location'] ?? '/panel/assignments'));
$otpFlash = $otpCreatedPage['body'];
$t->contains($otpFlash, 'Не перенесено', 'о пропущенных заданиях сказано сразу после переноса');
$t->contains($otpFlash, 'ползунок', 'названо, какое задание не перенеслось и почему');

$otpWorkId = (int) $db->value(
    'SELECT id FROM assignments WHERE title = :t ORDER BY id DESC LIMIT 1',
    ['t' => 'Тест для переноса']
);

$t->is($otpWorkId > 0, 'работа из теста создана в базе');

$otpWork = $db->one('SELECT * FROM assignments WHERE id = :i', ['i' => $otpWorkId]) ?? [];

$t->equals('draft', (string) ($otpWork['status'] ?? ''), 'перенесённая работа создаётся черновиком');
$t->equals(3, (int) ($otpWork['parallel'] ?? 0), 'параллель из мастера сохранена');
$t->equals(25, (int) ($otpWork['time_limit'] ?? 0), 'лимит времени сохранён');
$t->is(
    (int) ($db->value('SELECT id FROM subjects WHERE name = :n LIMIT 1', ['n' => 'Информатика переноса']) ?? 0) > 0,
    'предмет, которого не было, создался сам'
);

$otpItems = $db->all(
    'SELECT i.*, t.title AS test_title FROM assignment_items i
     LEFT JOIN tests t ON t.id = i.task_id
     WHERE i.assignment_id = :a ORDER BY i.position',
    ['a' => $otpWorkId]
);

$t->equals(7, count($otpItems), 'в работе семь заданий — восьмое перенести было нечем');

$otpKinds = array_map(static fn (array $i): string => (string) $i['task_kind'], $otpItems);

$t->equals(
    ['single_choice', 'multiple_choice', 'ordering', 'matching', 'numeric', 'numeric', 'text'],
    $otpKinds,
    'каждое задание приехало своим видом'
);
$t->is(in_array('slider', $otpKinds, true) === false, 'непереносимый тип в работу не попал');

$otpConfig = static fn (int $at): array => json_decode((string) $db->value(
    'SELECT config FROM questions WHERE test_id = :t LIMIT 1',
    ['t' => (int) $otpItems[$at]['task_id']]
), true) ?: [];

$t->equals('a', (string) ($otpConfig(0)['correct'] ?? ''), 'правильный вариант один — «Восемь»');
$t->equals(['a', 'b'], $otpConfig(1)['correct'] ?? [], 'правильные варианты перенесены все');
$t->equals(['i2', 'i3', 'i1'], $otpConfig(2)['correct_order'] ?? [], 'порядок перенесён по номерам позиций');
$t->equals(
    ['l1' => 'r1', 'l2' => 'r2', 'l3' => 'r3'],
    $otpConfig(3)['pairs'] ?? [],
    'соответствие связано по номерам элементов правой колонки'
);
$t->equals(1024.0, (float) ($otpConfig(4)['expected'] ?? 0), 'числовой ответ перенесён числом');

// Пропуск «{txt}» с числом приезжает числовым заданием, а не текстовым.
$otpGapPrompt = (string) $db->value(
    'SELECT q.prompt FROM questions q WHERE q.test_id = :t LIMIT 1',
    ['t' => (int) $otpItems[5]['task_id']]
);

$t->equals(8.0, (float) ($otpConfig(5)['expected'] ?? 0), 'ответ в пропуске стал числовым ответом');
$t->contains($otpGapPrompt, '___', 'место пропуска осталось видимым в условии');
$t->notContains($otpGapPrompt, '{txt}', 'метка пропуска из чужой системы не попала в условие');

// Текстовый ответ со словом остаётся текстовым.
$t->equals('exact', (string) ($otpConfig(6)['strategy'] ?? ''), 'словесный ответ остался текстовым');

// Пояснение автора переезжает в комментарий к правильному ответу.
$t->equals(
    'Восемь.',
    trim(strip_tags((string) $db->value(
        'SELECT explanation FROM questions WHERE test_id = :t LIMIT 1',
        ['t' => (int) $otpItems[0]['task_id']]
    ) ?? '')),
    'пояснение из выгрузки сохранено'
);

/*
 * «Начать заново» возвращает страницу к файлу.
 *
 * Сброс серверный, а не ссылка: разобранный тест лежит в сессии, и забыть его
 * должен сервер. Ссылка без скриптов оставила бы разбор на месте, и человек
 * второй раз увидел бы то же самое, что и в первый.
 */
$otpUploadedAgain = $teacher->upload('/panel/assignments/import/otp/upload', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/import/otp')) ?? '',
], [
    'package' => $otpZipPath,
]);

$t->is($otpUploadedAgain['status'] === 302, 'файл приложен заново');
$t->contains(
    $teacher->get('/panel/assignments/import/otp')['body'],
    'name="subject"',
    'после разбора страница спрашивает предмет'
);

$otpReset = $teacher->post('/panel/assignments/import/otp/reset', [
    '_token' => $teacher->tokenFrom($teacher->get('/panel/assignments/import/otp')) ?? '',
]);

$t->is($otpReset['status'] === 302, 'разбор сброшен');
$t->contains(
    $teacher->get('/panel/assignments/import/otp')['body'],
    'name="package"',
    'после сброса страница снова спрашивает файл'
);
$t->notContains(
    $teacher->get('/panel/assignments/import/otp')['body'],
    'name="subject"',
    'после сброса предмет на странице не спрашивают'
);

// Задания открываются в редакторе работы и сразу проходят проверку готовности:
// человек не должен дописывать то, что уже было в выгрузке. Единственное
// незаполненное — шкала оценок, её в любом случае задаёт учитель.
$otpItemsPage = $teacher->get('/panel/assignments/' . $otpWorkId . '/edit/items');
$otpPublishPage = $teacher->get('/panel/assignments/' . $otpWorkId . '/edit');

/*
 * Перенесённое задание опознаётся так же, как любое: началом своего условия.
 * Проверялось «Задание 1» — снимок имени, который раньше ехал в пакете и уехал
 * вместе с колонкой; после этого такая проверка проходила бы только на старых
 * пакетах.
 *
 * Условие берётся из базы, а не пишется строкой: карточка на доске называется
 * именно началом условия, и проверка не должна ломаться от правки образца.
 */
$otpFirstCondition = mb_substr(
    trim(preg_replace('/\s+/', ' ', strip_tags((string) $db->value(
        'SELECT prompt FROM questions WHERE test_id = :t LIMIT 1',
        ['t' => (int) $otpItems[0]['task_id']]
    ))) ?? ''),
    0,
    30
);

$t->contains(
    $otpItemsPage['body'],
    $otpFirstCondition,
    'перенесённые задания открываются в редакторе работы: карточка названа условием'
);
$t->notContains(
    $otpPublishPage['body'],
    'нужен текст и хотя бы два варианта ответа',
    'перенесённые задания сразу готовы — дописывать варианты не пришлось'
);
$t->contains(
    $otpPublishPage['body'],
    'Не заданы параметры оценки',
    'из незаполненного осталась только шкала оценок — её задаёт учитель'
);

// Настоящая выгрузка из примера разбирается целиком, если файл лежит в проекте.
$otpSample = $rootDir . '/импорт из OnlineTestPad/Измерение информации, 7 класс.zip';

if (is_file($otpSample)) {
    $otpParsed = (new MyEdu\Import\OtpParser())->parse(MyEdu\Import\OtpArchive::read($otpSample));

    $t->equals(9, count($otpParsed['questions']), 'настоящий тест из примера разобран целиком');
    $t->equals(0, count($otpParsed['skipped']), 'в настоящем тесте не пропущено ни одного задания');
    $t->equals(30, (int) $otpParsed['timeLimit'], 'лимит времени настоящего теста прочитан');
}

@unlink($otpZipPath);
@unlink($packagePath);
@unlink($brokenPath);
foreach (glob($rootDir . '/public/uploads/*') ?: [] as $uploadedFile) {
    if (basename($uploadedFile) !== $transferImage) {
        @unlink($uploadedFile);
    }
}

/*
 * ---------------------------------------------------------------------------
 *  Строка работы: варианты задания и выбор при выдаче
 * ---------------------------------------------------------------------------
 */

$rowsRepo = $app->get(MyEdu\Repository\AssignmentRepository::class);

$t->group('Строка работы: варианты задания');

$rowsWorkId = (int) $db->insert('assignments', [
    'author_id'    => $sharedAuthor,
    'title'        => 'Работа с вариантами',
    'status'       => 'draft',
    'grade_mode'   => 'weighted',
    'created_at'   => date('Y-m-d H:i:s'),
    'updated_at'   => date('Y-m-d H:i:s'),
]);

$variantTasks = [];

foreach (['Основное задание', 'Альтернатива слева', 'Альтернатива справа'] as $index => $title) {
    $taskId = (int) $db->insert('tests', [
        'subject_id' => null, 'author_id' => $sharedAuthor, 'class_id' => null,
        'title' => $title, 'description' => '', 'time_limit_min' => 0,
        'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
        'show_results' => 1, 'status' => 'draft',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $variantTasks[$index] = ['id' => $taskId, 'title' => $title];
}

$variantRefs = [];

foreach ($variantTasks as $index => $task) {
    $variantRefs[$index] = new MyEdu\Plugin\TaskRef('test', 'single_choice', $task['id']);
}

// Одна строка из трёх карточек: слева основное, рядом две альтернативы.
// Позиция у всех трёх одна и та же — это и есть строка.
foreach ([1, 2, 3] as $variant) {
    $db->insert('assignment_items', [
        'assignment_id'  => $rowsWorkId,
        'position'       => 1,
        'variant'        => $variant,
        'plugin_id'      => 'test',
        'task_kind'      => 'single_choice',
        'task_id'        => $variantTasks[$variant - 1]['id'],
        'weight'         => 1.0,
        'max_score'      => 2.0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

// Вторая строка — обычная, из одного задания: выбор для неё вырожденный.
$db->insert('assignment_items', [
    'assignment_id'  => $rowsWorkId,
    'position'       => 2,
    'variant'        => 1,
    'plugin_id'      => 'test',
    'task_kind'      => 'single_choice',
    'task_id'        => $variantTasks[0]['id'],
    'weight'         => 1.0,
    'max_score'      => 4.0,
    'created_at'     => date('Y-m-d H:i:s'),
]);

$rowsRepo = $app->get(MyEdu\Repository\AssignmentRepository::class);
$rows = $rowsRepo->rows($rowsWorkId);

$t->equals(2, count($rows), 'работа из трёх карточек и одной одиночной — это две строки');
$t->equals(3, count($rows[0]['variants']), 'в первой строке три карточки');
$t->equals(1, count($rows[1]['variants']), 'во второй строке одна карточка');
$t->equals(2, $rowsRepo->countItems($rowsWorkId), 'число позиций работы считает строки, а не карточки');
$t->equals([1, 2, 3], array_map(static fn (array $v): int => (int) $v['variant'], $rows[0]['variants']),
    'карточки идут слева направо: 1, 2, 3');

/*
 * Выбор варианта случаен, но закреплён за попыткой.
 *
 * Внутри одной попытки список не меняется — иначе адреса, прогресс и
 * сохранённые ответы разъезжались бы между страницами. Разные попытки
 * получают разное: при пересдаче это и нужно.
 */
$chosenByAttempt = [];

foreach ([1, 2, 3, 4, 5, 6] as $attemptNumber) {
    $attemptId = 100000 + $attemptNumber;

    $first = $rowsRepo->itemForAttempt($rowsWorkId, $attemptId, 1);

    $t->is($first !== null, 'попытка ' . $attemptNumber . ' получает задание первой строки');

    if ($first !== null) {
        $chosenByAttempt[$attemptNumber] = (int) $first['task_id'];
        $t->equals(
            $first['task_id'],
            $rowsRepo->itemForAttempt($rowsWorkId, $attemptId, 1)['task_id'] ?? null,
            'попытка ' . $attemptNumber . ': выбор не меняется между обращениями'
        );
    }
}

$t->equals(3, count(array_unique($chosenByAttempt)),
    'шесть попыток получили все три варианта — выбор действительно случаен, а не всегда первый');

$second = $rowsRepo->itemForAttempt($rowsWorkId, 100001, 2);

$t->is($second !== null && (int) $second['variant'] === 1,
    'строка с одной карточкой отдаёт её при любой попытке');

/*
 * Ученик видит по одной позиции на строку, и это ровно та карточка, которая
 * выпала его попытке.
 */
$studentItems = $rowsRepo->itemsForAttempt($rowsWorkId, 100003);

$t->equals(2, count($studentItems), 'ученику видны две позиции — по одной на строку');
$t->equals($chosenByAttempt[3] ?? 0, (int) ($studentItems[0]['task_id'] ?? 0),
    'первая позиция ученика — та карточка, что выпала его попытке');

/*
 * Сдвиг влево.
 *
 * Удаление средней карточки не должно оставлять дыру: слева основное
 * задание, и ученик не должен получить строку, где основного нет.
 */
$middleCardId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :a AND position = 1 AND variant = 2',
    ['a' => $rowsWorkId]
);

$rowsRepo->deleteItem($rowsWorkId, $middleCardId);

$afterDelete = $rowsRepo->rows($rowsWorkId);

$t->equals(2, count($afterDelete[0]['variants']), 'после удаления средней карточки в строке их две');
$t->equals([1, 2], array_map(static fn (array $v): int => (int) $v['variant'], $afterDelete[0]['variants']),
    'карточки сдвинулись влево без дыры между ними');
$t->equals($variantTasks[0]['id'], (int) $afterDelete[0]['variants'][0]['task_id'],
    'основное задание осталось основным');
$t->equals($variantTasks[2]['id'], (int) $afterDelete[0]['variants'][1]['task_id'],
    'правая карточка переехала на место удалённой');

// Пустая строка исчезает, а номера оставшихся строк сдвигаются.
$lonelyItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :a AND position = 2',
    ['a' => $rowsWorkId]
);

$rowsRepo->deleteItem($rowsWorkId, $lonelyItemId);

$t->equals(1, $rowsRepo->countItems($rowsWorkId), 'удаление последней карточки строки убирает и строку');

/*
 * Перенос карточки.
 *
 * Перетащили альтернативу в первую карточку — она встала первой, а прежнее
 * место освободилось. Строки при этом не поехали: место в работе задаёт
 * строка, а не карточка.
 */
$moveWorkId = (int) $db->insert('assignments', [
    'author_id'  => $sharedAuthor,
    'title'      => 'Работа для переноса карточки',
    'status'     => 'draft',
    'grade_mode' => 'weighted',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$moveIds = [];

// «Первый» и «Второй» — карточки одной строки, «Третий» — своей.
foreach ([['Первый', 1, 1, 1.0, 2.0], ['Второй', 1, 2, 1.0, 2.0], ['Третий', 2, 1, 1.0, 4.0]] as [$title, $position, $variant, $weight, $price]) {
    $taskId = (int) $db->insert('tests', [
        'subject_id' => null, 'author_id' => $sharedAuthor, 'class_id' => null,
        'title' => $title . ' задание', 'description' => '', 'time_limit_min' => 0,
        'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
        'show_results' => 1, 'status' => 'draft',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $moveIds[$title] = (int) $db->insert('assignment_items', [
        'assignment_id'  => $moveWorkId,
        'position'       => $position,
        'variant'        => $variant,
        'plugin_id'      => 'test',
        'task_kind'      => 'single_choice',
        'task_id'        => $taskId,
        'weight'         => $weight,
        'max_score'      => $price,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

// Переносим «Второй» в первую карточку первой строки.
$rowsRepo->moveCard($moveWorkId, $moveIds['Второй'], 1, 1);

$firstRow = $rowsRepo->rows($moveWorkId)[0]['variants'];

$t->equals($moveIds['Второй'], (int) $firstRow[0]['id'], 'перенесённая карточка встала первой');
$t->equals($moveIds['Первый'], (int) $firstRow[1]['id'], 'прежняя первая карточка сдвинулась вправо');
$t->equals(2, count($firstRow), 'в строке по-прежнему две карточки');

// Карточка из другой строки: строка опустела и исчезла, номера сдвинулись.
$rowsRepo->moveCard($moveWorkId, $moveIds['Третий'], 1, 2);

$moved = $rowsRepo->rows($moveWorkId);

$t->equals(1, count($moved), 'опустевшая строка исчезла, строк осталась одна');
$t->equals(3, count($moved[0]['variants']), 'в оставшейся строке три карточки');
$t->equals([1, 2, 3], array_map(static fn (array $v): int => (int) $v['variant'], $moved[0]['variants']),
    'карточки по-прежнему идут подряд: 1, 2, 3');

/*
 * Цена и вес — у строки: варианты делят их с первой карточкой.
 *
 * Иначе сумма цен разойдётся с тем, что ученик может набрать: верхняя
 * граница пятёрки обязана совпадать с максимумом работы.
 */
$priceTaskId = (int) $db->insert('tests', [
    'subject_id' => null, 'author_id' => $sharedAuthor, 'class_id' => null,
    'title' => 'Альтернатива по цене', 'description' => '', 'time_limit_min' => 0,
    'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
    'show_results' => 1, 'status' => 'draft',
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$priceCardId = $rowsRepo->addItem($moveWorkId, new MyEdu\Plugin\TaskRef('test', 'single_choice', $priceTaskId), 5.0, 100.0);

$priceRow = $rowsRepo->rows($moveWorkId)[0]['variants'];
$firstPrice = $priceRow[0];

$t->equals(3, count($priceRow), 'новая альтернатива добавилась в строку третьей карточкой');
$t->equals((float) $firstPrice['max_score'], (float) $priceRow[2]['max_score'],
    'альтернатива получила цену первой карточки, а не ту, что прислали с ней');
$t->equals((float) $firstPrice['weight'], (float) $priceRow[2]['weight'],
    'вес у строки один: варианты его делят');

/*
 * Двойная отправка не создаёт вторую выдачу.
 *
 * Токен защиты от подделки не спасает от двойного нажатия: обе отправки
 * настоящие, и сервер выполнит обе. Дальше это бьёт не только по счётчику
 * выдач: лимит попыток ученику берётся из самой свежей выдачи, поэтому молчаливый
 * дубль менял бы условие, которые учитель задал для всех остальных.
 *
 * Проверяется ровно то, что было поломкой: повтор той же формы (тот же пропуск)
 * не создаёт записей и возвращает на шаг срока с объяснением.
 */
$t->group('Выдача не создаётся дважды');

$issuesBefore = (int) ($db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $assignmentId]
) ?? 0);

$again = $teacher->post('/panel/issues/' . $assignmentId . '/period', [
    '_token'     => $teacher->tokenFrom($periodStep) ?? '',
    'once_issue' => $teacher->onceFrom($periodStep, 'issue') ?? '',
    'classes'    => [(string) $otherClassId],
    'students'   => [(string) $otherId],
]);

$t->is($again['status'] === 302, 'повторная отправка не падает, а переадресует');
$t->contains((string) $again['location'], '/period', 'повтор возвращает на шаг срока');
$t->equals($issuesBefore, (int) ($db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $assignmentId]
) ?? 0), 'вторая выдача не создана');
$afterAgain = $teacher->get((string) $again['location']);

$t->contains($afterAgain['body'], 'уже отправлена',
    'об отказе сказано прямо: форма уже отправлена');

/*
 * Чужой запрос не сжигает пропуск настоящего человека.
 *
 * Токен защиты от подделки проверяется раньше одноразового пропуска. Раньше
 * порядок был обратный: пропуск забирался первым, и запрос без токена уводил
 * форму в состояние «уже отправлена» — то есть настоящий человек, вернувшись
 * к форме, получал отказ, у которого нет никакой связи с его действиями, и
 * выдачу приходилось начинать заново.
 *
 * Проверяется ровно это: подделка не проходит, а форма после неё остаётся
 * годной.
 */
$forged = $teacher->post('/panel/issues/' . $assignmentId . '/period', [
    '_token'     => 'чужой токен',
    'once_issue' => $teacher->onceFrom($periodStep, 'issue') ?? '',
    'classes'    => [(string) $otherClassId],
    'students'   => [(string) $otherId],
]);

$t->is($forged['status'] === 419, 'запрос без токена отклонён');

$afterForged = $teacher->get('/panel/issues/' . $assignmentId . '/period?classes='
    . (int) $otherClassId . '&students=' . (int) $otherId);

$t->notContains($afterForged['body'], 'уже отправлена',
    'после чужого запроса форма осталась годной: пропуск не сгорел');

$t->equals($issuesBefore, (int) ($db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $assignmentId]
) ?? 0), 'чужой запрос выдачу не создал');

/*
 * Время вышло: ответ не теряется, а человек узнаёт, что произошло.
 *
 * Таймер на странице задания отправляет форму уже после истечения: ученик
 * дописал ответ, нажал «Далее», а время как раз кончилось. Раньше сервер на
 * такой отправке просто завершал работу — ответ пропадал, и узнать об этом
 * можно было только на странице результата, где вернуть его уже нельзя.
 *
 * Проверяется ровно это: ответ долетел до базы, работа завершена, а на
 * странице результата сказано, что ответ сохранён.
 */
$t->group('Истечение времени не съедает ответ');

$expiredWorkId = (int) $db->insert('assignments', [
    'author_id'     => $sharedAuthor,
    'title'         => 'Работа с таймером',
    'description'   => '',
    'subject_id'    => null,
    'status'        => 'published',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'score',
    'show_results'  => 1,
    // Минута: истечение считается от начала попытки, а не от таймера в коде.
    'time_limit'    => 1,
    'criteria'      => json_encode([['grade' => 5, 'min_score' => 1, 'max_score' => 5]], JSON_UNESCAPED_UNICODE),
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$expiredQuestionId = (int) $db->value(
    "SELECT id FROM questions WHERE test_id = :id AND type = 'single_choice' ORDER BY position LIMIT 1",
    ['id' => $transferTestId]
);

$expiredItemId = (int) $db->insert('assignment_items', [
    'assignment_id'  => $expiredWorkId,
    'position'       => 1,
    'plugin_id'      => 'test',
    'task_kind'      => 'single_choice',
    'task_id'        => $transferTestId,
    'created_at'     => date('Y-m-d H:i:s'),
]);

$expiredIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $expiredWorkId,
    'author_id'       => $sharedAuthor,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $expiredIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$expiredStartPage = $student->get('/work/' . $expiredWorkId);
$expiredStart = $student->post('/work/' . $expiredWorkId . '/start', [
    '_token' => $student->tokenFrom($expiredStartPage) ?? '',
]);

$t->is($expiredStart['status'] === 302, 'попытка работы с таймером начата');
$t->is(preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', $expiredStart['location'], $expiredMatch) === 1,
    'адрес задания получен');

$expiredAttemptId = (int) ($expiredMatch[1] ?? 0);
$expiredToken = (string) ($expiredMatch[3] ?? '');

// Время вышло: начало попытки отмотано на два часа назад.
$db->run(
    'UPDATE assignment_attempts SET started_at = :s WHERE id = :id',
    ['s' => date('Y-m-d H:i:s', time() - 7200), 'id' => $expiredAttemptId]
);

$expiredItemPath = '/work/' . $expiredAttemptId . '/item/' . $expiredItemId;
$expiredItemPage = $student->get($expiredItemPath . '?t=' . $expiredToken);
$expiredSave = $student->post($expiredItemPath, [
    '_token' => $student->tokenFrom($expiredItemPage) ?? '',
    't'      => $expiredToken,
    ['answer_present[' . $expiredQuestionId . ']', '1'],
    ['answers[' . $expiredQuestionId . ']', 'a'],
    ['action', 'finish'],
]);

$t->is($expiredSave['status'] === 302, 'отправка после истечения не падает');

$expiredAnswer = $db->one(
    'SELECT a.answer_text FROM test_item_answers a
       JOIN test_item_attempts t ON t.id = a.item_attempt_id
      WHERE t.assignment_attempt_id = :attempt AND a.question_id = :question',
    ['attempt' => $expiredAttemptId, 'question' => $expiredQuestionId]
);

$t->is($expiredAnswer !== null, 'ответ, отправленный на истечении, сохранён');
$t->equals('finished', (string) $db->value('SELECT status FROM assignment_attempts WHERE id = :id', ['id' => $expiredAttemptId]),
    'работа завершена');

$expiredResultPage = $student->get('/work/' . $expiredAttemptId . '/result?t=' . $expiredToken);

$t->is($expiredResultPage['status'] === 200, 'страница результата открыта');
$t->contains($expiredResultPage['body'], 'Время вышло', 'на результате сказано, что время кончилось');
$t->contains($expiredResultPage['body'], 'сохранён', 'на результате сказано, что ответ сохранён');

/*
 * Без пропуска — тоже отказ: иначе отключённый скрипт был бы обходом
 * единственной защиты от двойной отправки.
 */
$withoutOnce = $teacher->post('/panel/issues/' . $assignmentId . '/period', [
    '_token'   => $teacher->tokenFrom($periodStep) ?? '',
    'classes'  => [(string) $otherClassId],
    'students' => [(string) $otherId],
]);

$t->equals($issuesBefore, (int) ($db->value(
    'SELECT COUNT(*) FROM assignment_issues WHERE assignment_id = :a',
    ['a' => $assignmentId]
) ?? 0), 'без пропуска выдача тоже не создаётся');

$t->group('Таймер на странице прохождения');

/*
 * Ученик должен видеть, сколько у него осталось.
 *
 * Скрипт таймера в проекте был, а разметки не было: страница выглядела
 * исправной, ограничение времени работало на сервере, но человек не знал,
 * сколько у него осталось, — и удивлялся, что работу отобрали.
 */
$timerWorkId = (int) $db->insert('assignments', [
    'title' => 'Работа с таймером',
    'author_id' => $teacherId,
    'status' => 'published',
    'time_limit' => 15,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$timerTaskId = $transferTestId;

$timerItemId = (int) $db->insert('assignment_items', [
    'assignment_id' => $timerWorkId,
    'position' => 1,
    'variant' => 1,
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'task_id' => $timerTaskId,
    'weight' => 1.0,
    'max_score' => 1.0,
    'created_at' => date('Y-m-d H:i:s'),
]);

$timerIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id' => $timerWorkId,
    'author_id' => $teacherId,
    'available_from' => date('Y-m-d H:i:s'),
    'available_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->insert('assignment_issue_students', [
    'issue_id' => $timerIssueId,
    'student_id' => $studentId,
    'class_id' => $classId,
    'assigned_at' => date('Y-m-d H:i:s'),
]);

$timerStartPage = $student->get('/work/' . $timerWorkId);
$timerStart = $student->post('/work/' . $timerWorkId . '/start', [
    '_token' => $student->tokenFrom($timerStartPage) ?? '',
]);

$t->is($timerStart['status'] === 302, 'ученик начал работу с таймером');

$timerMatch = preg_match(
    '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
    (string) ($timerStart['location'] ?? ''),
    $timerParts
);

$t->is($timerMatch === 1, 'попытка открылась на первом задании');

if ($timerMatch === 1) {
    $timerPage = $student->get('/work/' . $timerParts[1] . '/item/' . $timerParts[2] . '?t=' . $timerParts[3]);

    $t->contains($timerPage['body'], 'id="timer-value"', 'таймер показан ученику');

    $t->contains($timerPage['body'], 'data-remaining="', 'в форме задания есть остаток времени');

    $t->is(
        preg_match('/data-remaining="(\d+)"/', (string) ($timerPage['body'] ?? ''), $timerLeft) === 1
        && (int) $timerLeft[1] > 0
        && (int) $timerLeft[1] <= 15 * 60,
        'остаток времени посчитан и не превышает лимит'
    );

    $t->contains($timerPage['body'], 'id="timer"', 'есть коробка таймера');
}

/*
 * Без лимита таймера нет: пустой таймер читался бы как «время кончилось».
 */
$plainWorkId = (int) $db->insert('assignments', [
    'title' => 'Работа без таймера',
    'author_id' => $teacherId,
    'status' => 'published',
    'time_limit' => null,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$plainItemId = (int) $db->insert('assignment_items', [
    'assignment_id' => $plainWorkId,
    'position' => 1,
    'variant' => 1,
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'task_id' => $timerTaskId,
    'weight' => 1.0,
    'max_score' => 1.0,
    'created_at' => date('Y-m-d H:i:s'),
]);

$plainIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id' => $plainWorkId,
    'author_id' => $teacherId,
    'available_from' => date('Y-m-d H:i:s'),
    'available_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->insert('assignment_issue_students', [
    'issue_id' => $plainIssueId,
    'student_id' => $studentId,
    'class_id' => $classId,
    'assigned_at' => date('Y-m-d H:i:s'),
]);

$plainStartPage = $student->get('/work/' . $plainWorkId);
$plainStart = $student->post('/work/' . $plainWorkId . '/start', [
    '_token' => $student->tokenFrom($plainStartPage) ?? '',
]);
$plainMatch = preg_match(
    '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
    (string) ($plainStart['location'] ?? ''),
    $plainParts
);

if ($plainMatch === 1) {
    $plainPage = $student->get('/work/' . $plainParts[1] . '/item/' . $plainParts[2] . '?t=' . $plainParts[3]);

    $t->notContains($plainPage['body'], 'id="timer-value"', 'без лимита времени таймера нет');
}

$t->group('Перемешивание заданий для ученика');

/*
 * Порядок заданий у ученика — либо тот, что в редакторе, либо случайный.
 *
 * Случайный порядок закреплён за попыткой: задания не переставляются при
 * переходе туда-обратно, но у разных учеников он разный. Проверяется ровно
 * это, а не «порядок изменился»: случайный порядок в редких случаях совпадает
 * с исходным, и проверка на разницу была бы проверкой на удачу.
 */
$shuffleWorkId = (int) $db->insert('assignments', [
    'title' => 'Работа с перемешиванием',
    'author_id' => $teacherId,
    'status' => 'published',
    'shuffle_items' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$shufflePositions = [];

for ($n = 1; $n <= 6; $n++) {
    /*
     * Задание берётся готовое, с вопросами: у задания без вопросов плагин
     * рисует другую страницу, и нумерация на ней не проверяется.
     */
    $shuffleTaskId = $transferTestId;

    $shufflePositions[] = (int) $db->insert('assignment_items', [
        'assignment_id' => $shuffleWorkId,
        'position' => $n,
        'variant' => 1,
        'plugin_id' => 'test',
        'task_kind' => 'single_choice',
        'task_id' => $shuffleTaskId,
        'weight' => 1.0,
        'max_score' => 1.0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$shuffleIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id' => $shuffleWorkId,
    'author_id' => $teacherId,
    'available_from' => date('Y-m-d H:i:s'),
    'available_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->insert('assignment_issue_students', [
    'issue_id' => $shuffleIssueId,
    'student_id' => $studentId,
    'class_id' => $classId,
    'assigned_at' => date('Y-m-d H:i:s'),
]);

$shuffleStartPage = $student->get('/work/' . $shuffleWorkId);
$shuffleStart = $student->post('/work/' . $shuffleWorkId . '/start', [
    '_token' => $student->tokenFrom($shuffleStartPage) ?? '',
]);

$t->is($shuffleStart['status'] === 302, 'попытка работы с перемешиванием начата');

$shuffleMatch = preg_match(
    '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
    (string) ($shuffleStart['location'] ?? ''),
    $shuffleParts
);

$t->is($shuffleMatch === 1, 'адрес первого задания получен');

if ($shuffleMatch === 1) {
    $shuffleAttemptId = (int) $shuffleParts[1];
    $shuffleToken = (string) $shuffleParts[3];

    $shufflePage = $student->get('/work/' . $shuffleAttemptId . '/item/' . $shuffleParts[2] . '?t=' . $shuffleToken);

    $t->contains($shufflePage['body'], 'Задание 1 из 6',
        'нумерация у ученика сплошная, а не с номера позиции в редакторе');

    /*
     * Порядок ученика — перестановка позиций редактора, и она устойчива:
     * второй раз то же самое.
     */
    $first = $rowsRepo->positionOrder($shuffleWorkId, $shuffleAttemptId);
    $second = $rowsRepo->positionOrder($shuffleWorkId, $shuffleAttemptId);

    $t->equals($first, $second, 'порядок не меняется от запроса к запросу');

    $sorted = $first;
    sort($sorted);

    $t->equals([1, 2, 3, 4, 5, 6], $sorted, 'порядок ученика — перестановка всех позиций');

    /*
     * Хотя бы одна из двадцати попыток обязана отличаться от порядка
     * редактора: шесть позиций, совпадение — одна из 720 перестановок.
     */
    $different = false;

    for ($n = 1; $n <= 20 && !$different; $n++) {
        $order = $rowsRepo->positionOrder($shuffleWorkId, 100000 + $n);

        if ($order !== [1, 2, 3, 4, 5, 6]) {
            $different = true;
        }
    }

    $t->is($different, 'у разных попыток порядок разный');
}

/*
 * Без перемешивания порядок тот же, что в редакторе.
 */
$plainOrderWorkId = (int) $db->insert('assignments', [
    'title' => 'Работа без перемешивания',
    'author_id' => $teacherId,
    'status' => 'published',
    'shuffle_items' => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

foreach ([1, 2, 3] as $n) {
    $db->insert('assignment_items', [
        'assignment_id' => $plainOrderWorkId,
        'position' => $n,
        'variant' => 1,
        'plugin_id' => 'test',
        'task_kind' => 'single_choice',
        'task_id' => $transferTestId,
        'weight' => 1.0,
        'max_score' => 1.0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$t->equals(
    [1, 2, 3],
    $rowsRepo->positionOrder($plainOrderWorkId, 555),
    'без перемешивания порядок как в редакторе'
);

$t->group('Прохождение перемешанной работы кнопкой «дальше»');

/*
 * «Дальше» ведёт к следующему заданию ученика, а не к соседнему номеру
 * позиции в редакторе.
 *
 * Номер, который складывался со шагом, брался из строки базы — это номер
 * позиции в редакторе, — а передавался он в запрос, ждущий номер ученика.
 * Без перемешивания нумерации совпадают и поломка не видна; при
 * перемешивании «дальше» уходило на чужое задание либо за пределы списка,
 * ученика возвращало к списку позиций, а оставшиеся задания он не
 * открывал вовсе. При сдаче они уходили учителю как «не открывал» — работа
 * завершалась сама и вдобавок оказывалась на проверке, хотя всё было
 * посчитано автоматически.
 *
 * Обходится вся работа до конца: шесть переходов должны пройти по номерам
 * 1…6 и закончиться списком позиций, а не оборваться посередине.
 */
if ($shuffleMatch === 1) {
    /*
     * Порядок должен отличаться от порядка редактора: иначе старый код
     * прошёл бы обход чисто — он прибавлял бы единицу и в той нумерации.
     * Пока порядок не отличится, заводится следующая попытка, а совпадение —
     * одна из 720 перестановок, на которое полагаться нельзя.
     */
    $walkAttemptId = (int) $shuffleParts[1];
    $walkToken = (string) $shuffleParts[3];
    $walkItemId = (int) $shuffleParts[2];

    for (
        $try = 0;
        $try < 5 && $rowsRepo->positionOrder($shuffleWorkId, $walkAttemptId) === [1, 2, 3, 4, 5, 6];
        $try++
    ) {
        $againStart = $student->post('/work/' . $shuffleWorkId . '/start', [
            '_token' => $student->tokenFrom($student->get('/work/' . $shuffleWorkId)) ?? '',
        ]);

        if (
            ($againStart['status'] ?? 0) !== 302
            || preg_match(
                '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
                (string) ($againStart['location'] ?? ''),
                $againMatch
            ) !== 1
        ) {
            break;
        }

        $walkAttemptId = (int) $againMatch[1];
        $walkItemId = (int) $againMatch[2];
        $walkToken = (string) $againMatch[3];
    }

    $walkOrder = $rowsRepo->positionOrder($shuffleWorkId, $walkAttemptId);
    $walkQuestionId = (int) $db->value(
        "SELECT id FROM questions WHERE test_id = :id AND type = 'single_choice' ORDER BY position LIMIT 1",
        ['id' => $transferTestId]
    );

    $t->is($walkQuestionId > 0, 'для обхода есть вопрос с выбором ответа');

    $walkNumbers = [];
    $walkItems = [];
    $walkPositions = [];

    for ($step = 1; $step <= 6; $step++) {
        $walkPage = $student->get('/work/' . $walkAttemptId . '/item/' . $walkItemId . '?t=' . $walkToken);

        $t->is(($walkPage['status'] ?? 0) === 200, 'задание ' . $step . ' обхода открылось');

        $walkItems[] = $walkItemId;
        $walkPositions[] = (int) $db->value(
            'SELECT position FROM assignment_items WHERE id = :id',
            ['id' => $walkItemId]
        );

        if (preg_match('/Задание (\d+) из 6/', (string) $walkPage['body'], $numberMatch) === 1) {
            $walkNumbers[] = (int) $numberMatch[1];
        }

        /*
         * Ответ даётся, а не пропускается: у задания может стоять «ответ
         * обязателен», и тогда переход без ответа вернул бы ту же страницу —
         * проверялось бы не то.
         */
        $walkNext = $student->post('/work/' . $walkAttemptId . '/item/' . $walkItemId, [
            '_token' => $student->tokenFrom($walkPage) ?? '',
            't'      => $walkToken,
            [
                'answer_present[' . $walkQuestionId . ']',
                '1',
            ],
            ['answers[' . $walkQuestionId . ']', 'b'],
            ['action', 'next'],
        ]);

        $t->is(($walkNext['status'] ?? 0) === 302, 'переход ' . $step . ' сделан редиректом');

        if (preg_match('#/work/(\d+)/item/(\d+)\?t=[a-f0-9]{64}#', (string) ($walkNext['location'] ?? ''), $nextMatch) === 1) {
            $walkItemId = (int) $nextMatch[2];

            continue;
        }

        if (preg_match('#/work/attempt/(\d+)\?t=[a-f0-9]{64}#', (string) ($walkNext['location'] ?? ''), $listMatch) === 1) {
            // Дошёл до конца — идти больше некуда. Нормально лишь на шестом шаге.
            $walkItemId = 0;

            continue;
        }

        // Ни на задание, ни на список не похоже: это и есть обрыв посередине.
        $walkItemId = -1;
    }

    $t->equals($walkOrder, $walkPositions,
        'задания открываются в том порядке, какой у этого ученика');
    $t->equals([1, 2, 3, 4, 5, 6], $walkNumbers,
        'нумерация сплошная при каждом переходе, а не номера позиций редактора');
    $t->equals(6, count(array_unique($walkItems)),
        'каждое из шести заданий показано по одному разу');

    /*
     * Ровно шесть прохождений: пропущенное задание оставило бы меньше,
     * посещённое дважды — больше.
     */
    $t->equals(
        6,
        (int) $db->value(
            'SELECT COUNT(*) FROM test_item_attempts WHERE assignment_attempt_id = :id',
            ['id' => $walkAttemptId]
        ),
        'при обходе не осталось ни пропущенных, ни повторно открытых заданий'
    );
}

$t->group('В тесте ученика не видно число правильных вариантов');

/*
 * Подсказка «Правильных: N» выдавала вопрос целиком: зная, что верных два,
 * и увидев три оставшихся варианта, ученик искал эти два, не читая условие.
 *
 * Проверяется на странице прохождения, а не в файле шаблона: важно, что
 * счётчик не доходит до ученика, а не то, что строка где-то удалена.
 */
$mcTestId = (int) $db->value(
    "SELECT test_id FROM questions WHERE type = 'multiple_choice' ORDER BY test_id LIMIT 1"
);

$t->is($mcTestId > 0, 'в базе есть задание с выбором нескольких ответов');

if ($mcTestId > 0) {
    $mcWorkId = (int) $db->insert('assignments', [
        'title' => 'Работа с несколькими верными',
        'author_id' => $teacherId,
        'status' => 'published',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $db->insert('assignment_items', [
        'assignment_id' => $mcWorkId,
        'position' => 1,
        'variant' => 1,
        'plugin_id' => 'test',
        'task_kind' => 'multiple_choice',
        'task_id' => $mcTestId,
        'weight' => 1.0,
        'max_score' => 1.0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $mcIssueId = (int) $db->insert('assignment_issues', [
        'assignment_id' => $mcWorkId,
        'author_id' => $teacherId,
        'available_from' => date('Y-m-d H:i:s'),
        'available_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $db->insert('assignment_issue_students', [
        'issue_id' => $mcIssueId,
        'student_id' => $studentId,
        'class_id' => $classId,
        'assigned_at' => date('Y-m-d H:i:s'),
    ]);

    $mcStart = $student->post('/work/' . $mcWorkId . '/start', [
        '_token' => $student->tokenFrom($student->get('/work/' . $mcWorkId)) ?? '',
    ]);

    $mcMatch = preg_match(
        '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
        (string) ($mcStart['location'] ?? ''),
        $mcParts
    );

    $t->is(($mcStart['status'] ?? 0) === 302 && $mcMatch === 1,
        'работа с несколькими верными ответами начата');

    if ($mcMatch === 1) {
        $mcPage = $student->get(
            '/work/' . $mcParts[1] . '/item/' . $mcParts[2] . '?t=' . $mcParts[3]
        );

        $t->contains($mcPage['body'], 'Отметьте все подходящие варианты',
            'задание показано как вопрос с несколькими ответами');
        $t->notContains($mcPage['body'], 'Правильных:',
            'ученику не названо число правильных вариантов');
    }
}

$t->group('Запрет копирования текста задания');

/*
 * Настройка включается учителем и работает на странице прохождения.
 *
 * Проверяется и сам скрипт, и то, что решение принимает учитель: без
 * запрета страница не тянет скрипт вовсе, а с запретом — тянет.
 */
/*
 * Запрет копирования включается учителем и работает на странице прохождения.
 *
 * Проверяется и сам скрипт, и то, что решение принимает учитель: без
 * запрета страница не тянет скрипт вовсе, а с запретом — тянет.
 */
$noCopyWorkId = (int) $db->insert('assignments', [
    'title' => 'Работа с запретом копирования',
    'author_id' => $teacherId,
    'status' => 'published',
    'no_copy' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$noCopyItemId = (int) $db->insert('assignment_items', [
    'assignment_id' => $noCopyWorkId,
    'position' => 1,
    'variant' => 1,
    'plugin_id' => 'test',
    'task_kind' => 'single_choice',
    'task_id' => $transferTestId,
    'weight' => 1.0,
    'max_score' => 1.0,
    'created_at' => date('Y-m-d H:i:s'),
]);

$noCopyIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id' => $noCopyWorkId,
    'author_id' => $teacherId,
    'available_from' => date('Y-m-d H:i:s'),
    'available_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->insert('assignment_issue_students', [
    'issue_id' => $noCopyIssueId,
    'student_id' => $studentId,
    'class_id' => $classId,
    'assigned_at' => date('Y-m-d H:i:s'),
]);

$noCopyStartPage = $student->get('/work/' . $noCopyWorkId);
$noCopyStart = $student->post('/work/' . $noCopyWorkId . '/start', [
    '_token' => $student->tokenFrom($noCopyStartPage) ?? '',
]);

$noCopyMatch = preg_match(
    '#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#',
    (string) ($noCopyStart['location'] ?? ''),
    $noCopyParts
);

$t->is($noCopyMatch === 1, 'попытка работы с запретом копирования начата');

if ($noCopyMatch === 1) {
    $noCopyPage = $student->get('/work/' . $noCopyParts[1] . '/item/' . $noCopyParts[2] . '?t=' . $noCopyParts[3]);

    $t->contains($noCopyPage['body'], 'assets/js/no-copy.js',
        'на странице прохождения подключён скрипт запрета копирования');

    $t->contains($noCopyPage['body'], 'attempt--no-copy',
        'форма помечена запретом — без этого выделение оставалось бы возможным');

    $t->contains(
        (string) @file_get_contents($rootDir . '/public/assets/js/no-copy.js'),
        "event.preventDefault()",
        'скрипт запрещает выделение и копирование'
    );
}

/*
 * Без запрета скрипт не подключается: иначе работа, выданная обычным образом,
 * вдруг запретила бы копировать ответ.
 */
$freeCopyPage = $student->get('/work/' . $shuffleWorkId . '/item/' . $shuffleParts[2] . '?t=' . $shuffleParts[3]);

$t->notContains($freeCopyPage['body'], 'assets/js/no-copy.js',
    'без запрета скрипт не подключается');

/*
 * Настройка сохраняется и читается обратно.
 *
 * Форма редактора общая на все вкладки, и переключатель приходит вместе с
 * прочими полями. Если бы его значение не доезжало до базы, страница показала
 * бы «Разрешено» при включённом запрете — и учитель считал бы, что работа
 * защищена.
 */
$settingsPage = $teacher->get('/panel/assignments/' . $noCopyWorkId . '/edit/start');
$settingsToken = $teacher->tokenFrom($settingsPage) ?? '';

$t->contains($settingsPage['body'], 'name="no_copy"',
    'в настройках работы есть переключатель запрета копирования');

$teacher->post('/panel/assignments/' . $noCopyWorkId . '/edit/save', [
    '_token'        => $settingsToken,
    'no_copy'       => '1',
    'shuffle_items' => '0',
]);

$t->equals(
    1,
    (int) $db->value('SELECT no_copy FROM assignments WHERE id = :id', ['id' => $noCopyWorkId]),
    'запрет копирования сохранён'
);

$teacher->post('/panel/assignments/' . $noCopyWorkId . '/edit/save', [
    '_token'        => $settingsToken,
    'no_copy'       => '0',
    'shuffle_items' => '0',
]);

$t->equals(
    0,
    (int) $db->value('SELECT no_copy FROM assignments WHERE id = :id', ['id' => $noCopyWorkId]),
    'запрет копирования снят'
);
$t->group('Вопрос об уходе со страницы');

/*
 * При сохранении вопрос об уходе не задаётся.
 *
 * Отправка формы и есть сохранение: спрашивать после нажатия «Сохранить», не
 * потеряются ли данные, нелепо. Вопрос и появлялся — причём «иногда»: модуль
 * черновиков подключался к странице позже скрипта доски и потому вставал на
 * место только после первого открытия задания.
 */
$draftJs = (string) @file_get_contents($rootDir . '/public/assets/js/task-draft.js');
$itemsPane = (string) @file_get_contents($rootDir . '/templates/panel/_assignment_items_pane.php');

$t->contains(
    $draftJs,
    "document.addEventListener('submit', function () {",
    'отправка формы отмечается, и вопрос об уходе после неё не задаётся'
);

$t->contains(
    $draftJs,
    'if (submitting) {',
    'вопрос об уходе пропускается, пока идёт отправка'
);

$t->contains(
    $draftJs,
    '[data-modal]:not([hidden]) [data-editor-for][data-draft-state]',
    'вопрос задаётся только по открытому редактору, а не по любому черновику'
);

$t->is(
    strpos($itemsPane, 'task-draft.js') < strpos($itemsPane, 'assignment-items.js'),
    'модуль черновиков подключается раньше скрипта доски — иначе он встаёт на место поздно'
);

$t->group('Редактор задания: вкладки и настройки');

/*
 * Скрипт вкладок подключается вместе с остальными скриптами редактора.
 *
 * Форма плагина попадает в модальное окно и через innerHTML, а такие скрипты
 * браузер не выполняет: вкладки просто не переключались. Скрипт внутри формы
 * работает только при полной перезагрузке страницы, а окно открывают как раз
 * по данным.
 */
$uiSource = (string) @file_get_contents($rootDir . '/src/Ui/Ui.php');
$tabsPartial = (string) @file_get_contents($rootDir . '/templates/panel/_task_editor_tabs.php');

$t->contains(
    $uiSource,
    "assets/js/task-editor-tabs.js",
    'скрипт вкладок подключается вместе со скриптами редактора'
);

$t->notContains(
    $tabsPartial,
    'task-editor-tabs.js',
    'в разметке вкладок скрипта нет — его вставка не выполнилась бы'
);

/*
 * Шапка и строка списка работ — одна и та же сетка.
 *
 * Последняя колонка в шапке пустая, а в строке там кнопки. При ширине «по
 * содержимому» пустая ячейка схлопывалась в ноль, первую колонку забирала
 * разница, и все подписи шапки уезжали вправо на ширину кнопок.
 */
$appCss = (string) @file_get_contents($rootDir . '/public/assets/css/app.css');

$t->contains(
    $appCss,
    'grid-template-columns: minmax(0, 1fr) 110px 70px 96px 96px 110px 190px;',
    'колонка действий в списке работ задана шириной, а не по содержимому'
);

/*
 * В модальном окне редактора — две вкладки: «Задание» с полями вопроса и
 * «Настройки» с тем, что к вопросу отношения не имеет.
 *
 * Раньше «Ответ обязателен» и «Пояснение после проверки» стояли в общей форме
 * рядом с текстом вопроса, и терялись среди полей: человек правил вопрос и не
 * видел, что ответ можно не давать.
 */
$tabsPage = $openTaskEditor($teacher, $wizardId, $wizardTaskId);

$t->contains($tabsPage['body'], 'data-task-pane-tab="task"',
    'в редакторе есть вкладка «Задание»');

$t->contains($tabsPage['body'], 'data-task-pane-tab="settings"',
    'в редакторе есть вкладка «Настройки»');

$t->contains($tabsPage['body'], 'data-task-pane="settings"',
    'есть панель настроек');

$t->contains($tabsPage['body'], 'name="is_required"',
    'в настройках есть переключатель «Ответ обязателен»');

$t->contains($tabsPage['body'], 'name="explanation"',
    'в настройках есть поле «Пояснение после проверки»');

$t->contains($tabsPage['body'], 'name="shuffle_options"',
    'в настройках есть переключатель «Перемешать варианты ответа»');

/*
 * Перемешивание вариантов — только там, где варианты есть.
 *
 * У текстового ответа вариантов нет, и галочка была бы обещанием, которое
 * нельзя выполнить.
 */
$textTaskId = (int) $db->insert('tests', [
    'subject_id' => null, 'author_id' => $sharedAuthor, 'class_id' => null,
    'title' => 'Текстовое без вариантов', 'description' => '', 'time_limit_min' => 0,
    'shuffle_questions' => 0, 'shuffle_options' => 0, 'max_attempts' => 0,
    'show_results' => 1, 'status' => 'draft',
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$textQuestionId = (int) $db->insert('questions', [
    'test_id' => $textTaskId,
    'type' => 'text',
    'prompt' => 'Текстовый вопрос',
    'position' => 1,
    'weight' => 1.0,
    'is_required' => 1,
]);

$textItemId = (int) $db->insert('assignment_items', [
    'assignment_id' => $wizardId,
    'position' => 90,
    'variant' => 1,
    'plugin_id' => 'test',
    'task_kind' => 'text',
    'task_id' => $textTaskId,
    'weight' => 1.0,
    'max_score' => 1.0,
    'created_at' => date('Y-m-d H:i:s'),
]);

$textPage = $openTaskEditor($teacher, $wizardId, $textTaskId);

$t->notContains($textPage['body'], 'name="shuffle_options"',
    'у вопроса без вариантов переключателя перемешивания нет');

$t->contains($textPage['body'], 'data-task-pane-tab="settings"',
    'вкладка «Настройки» есть и у вопроса без вариантов');

/*
 * Окно редактора — шире на треть.
 *
 * Базовое окно 480 пикселей, в редакторе задания полей больше, и в узком окне
 * они вставали в несколько колонок, между которыми нельзя было прочитать ни
 * одну подпись.
 */
$appCss = (string) @file_get_contents($rootDir . '/public/assets/css/app.css');

$t->contains(
    $appCss,
    '#task-editor .modal-dialog { max-width: 648px; }',
    'окно редактора шире базового на 35 процентов'
);

$t->contains(
    $tabsPage['body'],
    'modal-dialog--task',
    'окну редактора задания назначен свой размер'
);

/*
 * В окно редактора не должна попасть оболочка сайта.
 *
 * `View::render()` по умолчанию оборачивает содержимое в оболочку приложения,
 * и это удобно ровно до тех пор, пока рисуют страницу. Фрагмент, который
 * кладют в модальное окно, должен быть голым: с оболочкой окно показывало
 * дубликат всего сайта — с боковым меню, шапкой и подвалом.
 *
 * Ошибка выглядела безобидно: страница отдавалась, проверки на вкладки
 * проходили, а человек видел вместо редактора задания копию панели.
 */
$editorFragment = $teacher->getAjax($itemsBoard . '/editor?task=' . $cancelTaskId);
$fragmentHtml = (string) ($editorFragment['json']['html'] ?? '');

$t->is($editorFragment['status'] === 200, 'фрагмент редактора отдаётся данными');

$t->notContains(
    $fragmentHtml,
    'Система контроля знаний',
    'в окно редактора не попала оболочка сайта'
);

$t->contains($fragmentHtml, 'data-task-pane-tab',
    'в окне редактора есть вкладки');

/*
 * То же на доске, где окно открыто по адресу.
 *
 * Это тот самый вид, который увидел человек: доска работ с открытым
 * редактором задания поверх. Оболочка сайта в нём быть не должна ни разу —
 * окно показывает форму, а не копию панели.
 */
$boardWithEditor = $teacher->get($itemsBoard . '?edit=' . $cancelTaskId);
$boardBody = (string) ($boardWithEditor['body'] ?? '');
$boardPlain = $teacher->get($itemsBoard);
$boardPlainBody = (string) ($boardPlain['body'] ?? '');

/*
 * Считается не «сколько раз встретилось название», а сколько раз оно
 * добавилось: в панельной оболочке название идёт дважды — в заголовке и в
 * боковом меню, — и само по себе это ничего не значит. Значит сравниваем
 * доску без открытого редактора и с ним: открытое окно не должно добавлять
 * оболочку.
 */
$t->equals(
    substr_count($boardPlainBody, 'Система контроля знаний'),
    substr_count($boardBody, 'Система контроля знаний'),
    'открытый редактор не добавил к доске оболочку сайта'
);

$t->contains($boardBody, 'data-task-pane-tab',
    'в открытом окне редактора есть вкладки');

/*
 * То же — на странице прохождения. Там оболочка есть и должна быть: это
 * полноценная страница. Но она должна быть ОДНА: таймер и запрет копирования
 * подключаются ядром, и оболочка внутри оболочки выглядела бы как страница
 * внутри страницы.
 */
$studentPage = $student->get('/work/' . $shuffleAttemptId . '/item/' . $shuffleParts[2] . '?t=' . $shuffleToken);
$studentBody = (string) ($studentPage['body'] ?? '');

$t->equals(
    1,
    substr_count($studentBody, 'Система контроля знаний'),
    'на странице прохождения оболочка сайта одна, а не вложенная в саму себя'
);

/*
 * Настройка перемешивания сохраняется и действует.
 *
 * Галочка пишется в задание целиком, а не в вопрос: варианты переставляются
 * в одном задании, и настройка принадлежит ему.
 */
$shuffleEditor = $openTaskEditor($teacher, $wizardId, $wizardTaskId);
$shuffleToken = $teacher->tokenFrom($shuffleEditor) ?? '';

$shuffleQuestionId = (int) ($db->value(
    'SELECT id FROM questions WHERE test_id = :id ORDER BY position LIMIT 1',
    ['id' => $wizardTaskId]
) ?? 0);

/*
 * Форма отправляется целиком: у вопроса с вариантами сервер ждёт сами
 * варианты и отмеченный правильный, и без них правка не сохранится — а
 * вместе с ней и настройка.
 */
$shuffleSave = $teacher->postAjax('/tests/task/' . $wizardTaskId, [
    '_token'          => $shuffleToken,
    'back'            => 'panel/assignments/' . $wizardId . '/edit/items?edit=' . $wizardTaskId,
    'question_id'     => (string) $shuffleQuestionId,
    'type'            => 'single_choice',
    'title'           => 'Вопрос с перемешиванием',
    'prompt'          => 'Какой газ растение поглощает при фотосинтезе?',
    'weight'          => '1',
    'is_required'     => '1',
    'explanation'     => 'Углекислый газ.',
    'shuffle_options' => '1',
    'correct_single'  => 'a',
    ['option_text[]', 'Углекислый газ'],
    ['option_text[]', 'Кислород'],
    ['option_text[]', 'Азот'],
]);

$t->is(
    $shuffleSave['status'] === 200,
    'форма с настройками сохранена'
        . ' [статус ' . $shuffleSave['status']
        . ', сообщение: ' . (string) ($shuffleSave['json']['message'] ?? '') . ']'
);

$t->equals(
    1,
    (int) $db->value('SELECT shuffle_options FROM tests WHERE id = :id', ['id' => $wizardTaskId]),
    'перемешивание вариантов сохранено'
);

$t->group('Редактор задания не открывается сам');

/*
 * Окно редактора не должно вылезать само.
 *
 * На странице редактора работы окон два: окно препятствий публикации стоит
 * выше по разметке, окно редактора задания — ниже. Наблюдатель за закрытием
 * брал первое окно на странице, то есть чужое: закрытие редактора не
 * замечалось, `?edit=` оставался в адресе, и перезагрузка снова открывала
 * редактор, который человек только что закрыл. Выглядело это как «окно
 * открылось само».
 */
$itemsJs = (string) @file_get_contents($rootDir . '/public/assets/js/assignment-items.js');
$tabsJs = (string) @file_get_contents($rootDir . '/public/assets/js/work-tabs.js');

$t->contains(
    $itemsJs,
    "document.querySelector('[data-editor-shell]')",
    'за закрытием редактора следит именно окно редактора, а не первое окно страницы'
);

$t->contains(
    $tabsJs,
    "url.searchParams.delete('edit')",
    'переход между вкладками убирает из адреса открытое задание'
);

$t->group('Пропущенное задание не уходит на ручную проверку');

/*
 * Решение владельца от 24.10.2026 (волна C): задание, которое ученик не
 * открывал, — это «пропущено», а не «ждёт проверки».
 *
 * Раньше плагин возвращал forReview, и попытка целиком получала отметку
 * ручной проверки: учитель видел работу в очереди, открывал её, а проверять
 * было нечего — ответа не существует. Очередь «ждут проверки» засорялась
 * чужими непрочитанными строками, и настоящие текстовые ответы в ней
 * терялись.
 *
 * Сценарий: работа из двух одинаковых по устройству заданий, ученик
 * открывает и выполняет только первое, а работу сдаёт сразу. Второе в его
 * попытке не раскрывалось вовсе — и оно обязано стать пропущенным, без
 * ожидания учителя, а сама попытка — не помечаться ручной.
 *
 * Ручная проверка при этом не исчезает: она остаётся у текстовых вопросов
 * со стратегией manual — её проверяет группа «Итог по весам» выше.
 */
$skippedWorkId = (int) $db->insert('assignments', [
    'title'         => 'Пропуск не ждёт учителя',
    'author_id'     => $teacherId,
    'status'        => 'published',
    'show_results'  => 1,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

foreach ([1, 2] as $skipPosition) {
    $db->insert('assignment_items', [
        'assignment_id' => $skippedWorkId,
        'position'      => $skipPosition,
        'variant'       => 1,
        'plugin_id'     => 'test',
        'task_kind'     => 'single_choice',
        'task_id'       => $transferTestId,
        'weight'        => 1.0,
        'max_score'     => 1.0,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

$skippedIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id'   => $skippedWorkId,
    'author_id'       => $teacherId,
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $skippedIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$skippedStartPage = $student->get('/work/' . $skippedWorkId);

$t->is($skippedStartPage['status'] === 200, 'работа с пропуском открыта');

$skippedStart = $student->post('/work/' . $skippedWorkId . '/start', [
    '_token' => $student->tokenFrom($skippedStartPage) ?? '',
]);

$t->is($skippedStart['status'] === 302, 'попытка начата');

$skippedParts = [];

$t->is(
    preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', (string) ($skippedStart['location'] ?? ''), $skippedParts) === 1,
    'адрес первого задания получен'
);

$skippedAttemptId = (int) ($skippedParts[1] ?? 0);
$skippedFirstItemId = (int) ($skippedParts[2] ?? 0);
$skippedToken = (string) ($skippedParts[3] ?? '');

$skippedFirstPage = $student->get('/work/' . $skippedAttemptId . '/item/' . $skippedFirstItemId . '?t=' . $skippedToken);

$t->is($skippedFirstPage['status'] === 200, 'первое задание открылось');

$skippedQuestionId = (int) $db->value(
    'SELECT id FROM questions WHERE test_id = :id ORDER BY position LIMIT 1',
    ['id' => $transferTestId]
);

/*
 * Сдача с первого задания: второе ученик не открывал вовсе, и запись о его
 * прохождении в базе не появляется — ровно тот случай, что проверяется.
 */
$skippedFinish = $student->post('/work/' . $skippedAttemptId . '/item/' . $skippedFirstItemId, [
    '_token' => $student->tokenFrom($skippedFirstPage) ?? '',
    't'      => $skippedToken,
    [
        'answer_present[' . $skippedQuestionId . ']',
        '1',
    ],
    ['answers[' . $skippedQuestionId . ']', 'a'],
    ['action', 'finish'],
]);

$t->is($skippedFinish['status'] === 302, 'работа сдана с первого задания');

$skippedSecondItemId = (int) $db->value(
    'SELECT id FROM assignment_items WHERE assignment_id = :a AND position = 2',
    ['a' => $skippedWorkId]
);

$skippedResult = $db->one(
    'SELECT status, needs_review FROM assignment_item_results
      WHERE attempt_id = :a AND item_id = :i',
    ['a' => $skippedAttemptId, 'i' => $skippedSecondItemId]
);

$t->is($skippedResult !== null, 'результат неоткрытого задания записан');
$t->equals('skipped', (string) ($skippedResult['status'] ?? ''),
    'неоткрытое задание — пропущено, а не неверно и не pending');
$t->equals(0, (int) ($skippedResult['needs_review'] ?? 1),
    'пропущенное задание не требует ручной проверки');

$t->equals(0, (int) $db->value(
    'SELECT manual FROM assignment_attempts WHERE id = :id',
    ['id' => $skippedAttemptId]
), 'попытка с пропуском не помечена как ждущая учителя');

$skippedResultPage = $student->get(
    '/work/' . $skippedAttemptId . '/result?t=' . $skippedToken
);

$t->is(($skippedResultPage['status'] ?? 0) === 200, 'страница результата открыта');
$t->contains((string) $skippedResultPage['body'], 'пропущено',
    'ученик видит, что задание пропущено');

$t->group('Случайные варианты показываются при выполнении');

/*
 * Логика, заданная владельцем (24.10.2026, задача 3):
 *
 *   если для задания включено «Выбрать N случайных вариантов ответа»,
 *   ученику показывается ровно N вариантов: отмеченные «Обязательно
 *   показывать» идут в первую очередь, среди остальных выбираются
 *   случайные; при включённом «Перемешать варианты ответа» показанные
 *   N перемешиваются; сумма баллов считается по показанным вариантам.
 *
 * Проверяется на настоящем прохождении: задание с четырьмя вариантами,
 * лимит три, отметка на первом, перемешивание включено. Счётчики и порядок
 * берутся со страницы, которую реально открывает ученик, — модульные
 * проверки отбора живут в наборе грейдинга, здесь важно, что до ученика
 * доходит именно это.
 */
$randomWorkId = (int) $db->insert('assignments', [
    'title'         => 'Случайные варианты: проверка',
    'author_id'     => $teacherId,
    'status'        => 'published',
    'show_results'  => 1,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$randomTestId = (int) $db->insert('tests', [
    'subject_id' => null, 'author_id' => $teacherId, 'class_id' => null,
    'title' => 'Вопрос с лимитом вариантов', 'description' => '',
    'time_limit_min' => 0, 'shuffle_questions' => 0, 'shuffle_options' => 1,
    'max_attempts' => 0, 'show_results' => 1, 'status' => 'draft',
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$randomQuestionId = (int) $db->insert('questions', [
    'test_id' => $randomTestId, 'type' => 'single_choice',
    'prompt' => 'Выберите планету Солнечной системы',
    'position' => 1, 'weight' => 2.00, 'is_required' => 1,
    'explanation' => null,
    'config' => json_encode(['random_options' => 3, 'correct' => 'b'], JSON_UNESCAPED_UNICODE),
]);

$randomVariants = [
    ['a', 'Меркурий'],
    ['b', 'Венера'],
    ['c', 'Юпитер'],
    ['d', 'Плутон'],
];

foreach ($randomVariants as $randomIndex => $randomVariant) {
    $db->insert('question_options', [
        'question_id' => $randomQuestionId, 'kind' => 'option',
        'value_key' => $randomVariant[0], 'text' => $randomVariant[1],
        'position' => $randomIndex + 1, 'pair_key' => null,
        // Отметка на первом варианте — «Обязательно показывать».
        'always_show' => $randomIndex === 0 ? 1 : 0,
    ]);
}

$db->insert('assignment_items', [
    'assignment_id' => $randomWorkId, 'position' => 1, 'variant' => 1,
    'plugin_id' => 'test', 'task_kind' => 'single_choice',
    'task_id' => $randomTestId, 'weight' => 1.0, 'max_score' => 1.0,
    'created_at' => date('Y-m-d H:i:s'),
]);

$randomIssueId = (int) $db->insert('assignment_issues', [
    'assignment_id' => $randomWorkId, 'author_id' => $teacherId,
    'available_from' => null, 'available_until' => null,
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->run(
    'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
    . ' VALUES (:i, :u, :c, :t)',
    ['i' => $randomIssueId, 'u' => $studentId, 'c' => $classId, 't' => date('Y-m-d H:i:s')]
);

$randomStartPage = $student->get('/work/' . $randomWorkId);
$randomStart = $student->post('/work/' . $randomWorkId . '/start', [
    '_token' => $student->tokenFrom($randomStartPage) ?? '',
]);

$t->is($randomStart['status'] === 302, 'работа с лимитом вариантов начата');

$randomParts = [];

$t->is(
    preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', (string) ($randomStart['location'] ?? ''), $randomParts) === 1,
    'адрес задания с лимитом получен'
);

$randomAttemptId = (int) ($randomParts[1] ?? 0);
$randomItemId = (int) ($randomParts[2] ?? 0);
$randomToken = (string) ($randomParts[3] ?? '');

$randomPage = $student->get('/work/' . $randomAttemptId . '/item/' . $randomItemId . '?t=' . $randomToken);
$randomBody = (string) $randomPage['body'];

preg_match_all('/<span class="option__text">([^<]*)<\/span>/', $randomBody, $randomShown);
$shownTexts = array_values(array_unique($randomShown[1] ?? []));

$t->is($randomPage['status'] === 200, 'страница задания с лимитом открылась');

$t->equals(3, count($shownTexts),
    'показано ровно три варианта из четырёх — лимит соблюдён');

$t->is(in_array('Меркурий', $shownTexts, true),
    'вариант с отметкой «Обязательно показывать» показан');
$t->is(in_array('Венера', $shownTexts, true),
    'правильный ответ показан — без него вопрос неразрешим');
$t->is(count($shownTexts) < 4, 'не все варианты показаны: один действительно отпал');

/*
 * Порядок закреплён за попыткой: три перезагрузки подряд должны дать один
 * и тот же порядок, иначе ученик при каждом обновлении страницы увидел бы
 * новую раскладку и сбивался.
 */
$randomOrders = [];

for ($randomReload = 0; $randomReload < 3; $randomReload++) {
    $randomAgain = $student->get('/work/' . $randomAttemptId . '/item/' . $randomItemId . '?t=' . $randomToken);

    preg_match_all('/<span class="option__text">([^<]*)<\/span>/', (string) $randomAgain['body'], $randomOrder);
    $randomOrders[] = implode('|', $randomOrder[1] ?? []);
}

$t->equals(1, count(array_unique($randomOrders)),
    'показанный порядок одинаков при перезагрузке страницы');

/*
 * Сумма баллов считается по показанным вариантам: ответ берётся из того,
 * что видит ученик, и за верный ответ начисляется весь вес вопроса —
 * урезание списка не уменьшает максимум задания.
 */
$randomFinish = $student->post('/work/' . $randomAttemptId . '/item/' . $randomItemId, [
    '_token' => $student->tokenFrom($randomPage) ?? '',
    't'      => $randomToken,
    ['answer_present[' . $randomQuestionId . ']', '1'],
    ['answers[' . $randomQuestionId . ']', 'b'],
    ['action', 'finish'],
]);

$t->is($randomFinish['status'] === 302, 'задание с лимитом сдано');

$randomResult = $db->one(
    'SELECT score, max_score, status FROM assignment_item_results
      WHERE attempt_id = :a AND item_id = :i',
    ['a' => $randomAttemptId, 'i' => $randomItemId]
);

$t->is($randomResult !== null, 'результат задания с лимитом записан');
$t->equals(
    2.0,
    (float) ($randomResult['score'] ?? 0),
    'верный ответ из показанных вариантов оценён в полный вес вопроса'
);
$t->equals(
    'correct',
    (string) ($randomResult['status'] ?? ''),
    'проверка прошла по показанным вариантам'
);

$t->group('Тестовое прохождение под виртуальным учеником');

/*
 * Решение владельца от 24.10.2026 (задача 6):
 *
 *   - сотрудникам работы не выдаются, поэтому «Пройти работу» открывает
 *     пробу в отдельной вкладке, в интерфейсе ученика;
 *   - ученик пробы — виртуальный: один фиксированный id, без класса и без
 *     отображения в списках учеников, имя — ФИО проходящего;
 *   - результаты видны автору как у всех, но живут до закрытия вкладки:
 *     уборка удаляет попытку со всеми следами.
 *
 * Проверяется весь путь: кнопка → показ без меню → попытка под
 * виртуальным → сдача и результат → уборка.
 */
$trialEditor = $teacher->get('/panel/assignments/' . $assignmentId . '/edit');

$t->is($trialEditor['status'] === 200, 'редактор работы открыт');
$t->contains($trialEditor['body'], 'Пройти работу', 'в редакторе есть кнопка прогона');
$t->contains($trialEditor['body'], '?trial=1', 'кнопка ведёт в тестовое прохождение');
$t->contains($trialEditor['body'], 'target="_blank"', 'прогон открывается в отдельной вкладке');

$trialPage = $teacher->get('/work/' . $assignmentId . '?trial=1');

$t->is($trialPage['status'] === 200, 'страница пробы открыта');
$t->notContains($trialPage['body'], '<aside class="sidebar"',
    'проба идёт в интерфейсе ученика: панельного меню нет');

$trialStart = $teacher->post('/work/' . $assignmentId . '/start', [
    '_token' => $teacher->tokenFrom($trialPage) ?? '',
]);

$t->is($trialStart['status'] === 302, 'попытка пробы начата');

$trialAttemptId = (int) $db->value(
    'SELECT id FROM assignment_attempts WHERE student_id = :s ORDER BY id DESC LIMIT 1',
    ['s' => StudentRepository::TRIAL_ID]
);

$t->is($trialAttemptId > 0, 'попытка записана под виртуальным учеником');

$trialRow = $db->one(
    'SELECT staff_id, student_id FROM assignment_attempts WHERE id = :id',
    ['id' => $trialAttemptId]
);

$t->equals(StudentRepository::TRIAL_ID, (int) ($trialRow['student_id'] ?? 0),
    'в колонке ученика — фиксированный id виртуального');
$t->equals(0, (int) ($trialRow['staff_id'] ?? 0),
    'колонка сотрудника пуста: это ученическая попытка, а не самопроверка');

$virtual = $db->one(
    'SELECT full_name, class_id, login FROM students WHERE id = :id',
    ['id' => StudentRepository::TRIAL_ID]
);

$t->is($virtual !== null, 'виртуальный ученик заведён');
$t->equals('Иванова Мария Петровна', (string) ($virtual['full_name'] ?? ''),
    'имя виртуального — ФИО проходящего сотрудника');
/*
 * class_id должен быть именно NULL. Здесь нельзя пользоваться `??`: он
 * подставляет значение и при отсутствии ключа, и при NULL — и проверка
 * приняла бы ноль за «привязан к классу».
 */
$t->is(
    array_key_exists('class_id', (array) $virtual) && ($virtual['class_id'] ?? null) === null,
    'виртуальный не привязан ни к одному классу'
);
$t->is(
    $students->findByName('Иванова Мария Петровна') === null,
    'по ФИО виртуальный недоступен: импорт и поиск его не найдут'
);
$t->is(
    (int) $db->value(
        'SELECT COUNT(*) FROM students WHERE class_id = :c'
        . ' AND id = ' . StudentRepository::TRIAL_ID,
        ['c' => $classId]
    ) === 0,
    'в списках учеников виртуального нет'
);

// Интерфейс ученика и на странице задания: меню панели скрыто.
$trialParts = [];

$t->is(
    preg_match('#/work/(\d+)/item/(\d+)\?t=([a-f0-9]{64})#', (string) ($trialStart['location'] ?? ''), $trialParts) === 1,
    'адрес первого задания пробы получен'
);

$trialItemPage = $teacher->get('/work/' . $trialParts[1] . '/item/' . $trialParts[2] . '?t=' . $trialParts[3]);

$t->is($trialItemPage['status'] === 200, 'задание пробы открылось');
$t->notContains($trialItemPage['body'], '<aside class="sidebar"',
    'и на странице задания панельного меню нет');
$t->contains($trialItemPage['body'], 'attempt-form', 'форма задания на месте — прохождение идёт обычным путём');

// Сдача и результат — как у настоящего ученика: та же страница, те же данные.
$trialFinish = $teacher->post('/work/' . $trialParts[1] . '/item/' . $trialParts[2], [
    '_token' => $teacher->tokenFrom($trialItemPage) ?? '',
    't'      => $trialParts[3],
    ['action', 'finish'],
]);

$t->is($trialFinish['status'] === 302, 'проба сдана');

$trialResult = $teacher->get((string) ($trialFinish['location'] ?? ''));

$t->is(($trialResult['status'] ?? 0) === 200, 'результат пробы открылся');
$t->contains((string) $trialResult['body'], 'По заданиям',
    'результат пробы показан тем же разбором, что и у настоящих учеников');

/*
 * Непустой результат: если бы плагин не открыл ни одного задания, страница
 * была бы «пропущена» и пуста — проверка ниже ловит именно такой случай.
 */
$t->is(
    (int) $db->value(
        'SELECT COUNT(*) FROM assignment_item_results
          WHERE attempt_id = :a',
        ['a' => $trialAttemptId]
    ) > 0,
    'результаты пробы записаны под виртуального ученика'
);

// Уборка — тот же запрос, что шлёт beacon при закрытии вкладки.
$trialCleanup = $teacher->post('/work/trial/cleanup', [
    '_token' => $teacher->tokenFrom($trialResult) ?? '',
    'attempt' => $trialAttemptId,
]);

$t->is($trialCleanup['status'] === 200, 'уборка принята');

$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM assignment_attempts WHERE id = :id',
    ['id' => $trialAttemptId]
), 'попытка пробы удалена — результаты не остались в базе');

$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM test_item_attempts WHERE assignment_attempt_id = :a',
    ['a' => $trialAttemptId]
), 'черновики плагина убраны вместе с попыткой — сирот не осталось');

$afterCleanup = $teacher->get('/work/' . $assignmentId);

$t->contains($afterCleanup['body'], '<aside class="sidebar"',
    'после уборки панельное меню вернулось');

/*
 * Проба не попадает в статистику: и журнал, и средний балл строятся по
 * выдачам (`assignment_issue_students`), а виртуального в выдачах нет вовсе.
 * Проверяется средний балл — там выводятся ФИО учеников, и ФИО
 * проходящего (которое носит виртуальный) в списке не должно значиться.
 */
$ratingsAfterTrial = $teacher->get('/panel/ratings');

$t->is($ratingsAfterTrial['status'] === 200, 'страница среднего балла открыта');
$t->notContains((string) $ratingsAfterTrial['body'], 'Иванова Мария Петровна',
    'виртуальный не попал в статистику среднего балла');

exit($t->report());
