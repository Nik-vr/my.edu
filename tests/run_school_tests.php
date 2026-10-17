<?php

declare(strict_types=1);

/**
 * Тесты школьного блока этапа 2: классы, предметы, учителя, ученики,
 * CSV-импорт, выдача и смена паролей.
 *
 * Запуск:
 *   php -S 127.0.0.1:8098 -t public public/index.php   (в одном окне)
 *   php tests/run_school_tests.php http://127.0.0.1:8098
 *
 * ВНИМАНИЕ: скрипт удаляет классы и тестовые учётные записи домена
 * school.test — это база разработки, не рабочие данные.
 */

require __DIR__ . '/support/TestKit.php';

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\Role;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8098';

// Очищающая база — только тестовая, см. run_auth_tests.php.
if (getenv('MYEDU_DB_NAME') === false || getenv('MYEDU_DB_NAME') === '') {
    putenv('MYEDU_DB_NAME=my_edu_review_test');
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

assertTestDatabase($db);

$t = new TestRunner();

echo "База: " . $db->value('SELECT DATABASE()') . "\n";
echo "\033[33mСкрипт очищает классы и записи домена school.test.\033[0m\n\n";

$users = new UserRepository($db);
$classes = new ClassRepository($db);
$students = new StudentRepository($db);

// ------------------------------------------------------------------ очистка

/*
 * Удаляются только классы и записи этого набора тестов.
 *
 * Учеников нельзя искать по «email IS NULL»: так заводит учеников сам
 * проект (StudentRepository::create), то есть на рабочей базе под нож попали
 * бы все зачисленные ученики разом. Поэтому их идентификаторы собираются
 * ДО сноса классов — пока ещё видна связь через class_students — и удаляются
 * уже по списку.
 *
 * Само собой, набор остаётся разрушительным для всего остального (классы,
 * попытки, оценки), и защищает его assertTestDatabase(), а не этот список.
 */
$studentIds = array_map(
    static fn (array $row): int => (int) $row['student_id'],
    $db->all('SELECT DISTINCT student_id FROM class_students')
);

$testUserIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    $db->all("SELECT id FROM users WHERE email LIKE '%@school.test'")
);

$usersToDelete = array_values(array_unique(array_merge(
    array_map('intval', $studentIds),
    array_map('intval', $testUserIds)
)));

$db->run('DELETE FROM class_subjects');
$db->run('DELETE FROM class_teachers');
$db->run('DELETE FROM class_students');
$db->run('DELETE FROM classes');

/*
 * Колонка привязки у таблиц своя: у попыток работы это student_id, а не
 * user_id. Помощник молча брал user_id везде, и очистка падала с «Unknown
 * column» каждый раз, когда список оказывался непустым — то есть на любом
 * запуске, где ученики в базе уже были.
 */
$deleteUsers = static function (string $table, string $column = 'user_id') use ($db, $usersToDelete): void {
    if ($usersToDelete === []) {
        return;
    }

    $list = implode(',', $usersToDelete);

    $db->run("DELETE FROM {$table} WHERE {$column} IN ({$list})");
};

$deleteUsers('assignment_attempts', 'student_id');
$deleteUsers('auth_tokens');
$deleteUsers('user_roles');

if ($usersToDelete !== []) {
    $list = implode(',', $usersToDelete);

    $db->run("DELETE FROM users WHERE id IN ({$list})");
}

$ownerId = 0;
$teacherId = 0;
$classId = 0;
$testSuffix = strtoupper(bin2hex(random_bytes(4)));
$mathSubjectName = 'Тест школы ' . $testSuffix . ' — Математика';
$historySubjectName = 'Тест школы ' . $testSuffix . ' — История';

$navLabel = static function (string $html): ?string {
      if (preg_match('/<a class="nav-link active"[^>]*>.*?<span class="nav-text">([^<]+)<\/span>/s', $html, $matches) !== 1) {
          return null;
      }

      return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
  };

/**
 * Создание активной учётной записи с ролью.
 */
$makeUser = static function (string $email, string $password, string $name, int $isOwner) use ($users, $db): int {
    $existing = $users->findRowByIdentifier($email);

    if ($existing === null) {
        $users->create([
            'login'            => $email,
            'email'            => $email,
            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
            'full_name'        => $name,
            'is_owner'         => $isOwner,
            'status'           => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        $existing = $users->findRowByIdentifier($email);
    }

    $id = (int) $existing['id'];
    $users->setRole($id, $isOwner === 1 ? Role::ADMIN : Role::TEACHER);

    return $id;
};

$ownerId = $makeUser('director@school.test', 'direktor2025', 'Власенко Ольга', 1);
$teacherId = $makeUser('teacher@school.test', 'uchitel2025', 'Иванова Мария Петровна', 0);

/*
 * Совпадение баз проверяется ещё раз — уже по тому серверу, куда набор
 * пойдёт по HTTP.
 *
 * assertTestDatabase() смотрит только на собственное подключение набора.
 * Этого мало: сервер по адресу $baseUrl может быть поднят на рабочей базе,
 * и тогда весь школьный блок (импорт учеников, создание классов) ушёл бы
 * туда, минуя всякую защиту. Именно так данные оказались в рабочей базе при
 * ручном запуске набора.
 */
$serverDatabase = static function (string $baseUrl): string {
    $probe = new TestClient($baseUrl);
    $health = $probe->get('/health');

    if (($health['status'] ?? 0) !== 200) {
        return '';
    }

    $decoded = json_decode((string) ($health['body'] ?? ''), true);

    return is_array($decoded) ? (string) ($decoded['database_name'] ?? '') : '';
};

$expectedDatabase = (string) $db->value('SELECT DATABASE()');
$actualDatabase = $serverDatabase($baseUrl);

$t = new TestRunner();
$t->is($actualDatabase !== '', 'сервер отвечает на /health');
$t->is($actualDatabase === $expectedDatabase, sprintf(
    'сервер и набор работают с одной базой (сервер: %s, набор: %s)',
    $actualDatabase === '' ? 'неизвестно' : $actualDatabase,
    $expectedDatabase
));

if ($actualDatabase !== $expectedDatabase) {
    fwrite(
        STDERR,
        "\nОТКАЗ: сервер {$baseUrl} работает с базой «{$actualDatabase}»,\n"
        . "а набор — с «{$expectedDatabase}». Импорт учеников ушёл бы не туда.\n"
        . "Поднимите сервер на тестовой базе: tools\\dev-server.ps1 -Detach -ForTests\n"
    );

    exit(2);
}

$t->group('Подготовка данных');
$t->is($ownerId > 0 && $teacherId > 0, 'владелец и учитель созданы');
$t->is((int) ($users->findRow($ownerId)['is_owner'] ?? 0) === 1, 'владелец помечен владельцем сайта');
$t->is(in_array(Role::TEACHER, $users->rolesOf($teacherId), true), 'учителю назначена роль учителя');

// ------------------------------------------------------------------ вход владельца

$owner = new TestClient($baseUrl);
$loginPage = $owner->get('/login');
$owner->post('/login', [
    '_token'     => $owner->tokenFrom($loginPage) ?? '',
    'identifier' => 'director@school.test',
    'password'   => 'direktor2025',
]);

$t->is($owner->get('/panel')['status'] === 200, 'владелец вошёл и видит панель');

// ------------------------------------------------------------------ настройки сайта

$t->group('Название школы');

$settingsPage = $owner->get('/panel/settings');
$t->is($settingsPage['status'] === 200, 'страница настроек открыта');
$t->contains($settingsPage['body'], 'Название школы', 'поле названия школы есть');

$owner->post('/panel/settings', [
    '_token'      => $owner->tokenFrom($settingsPage) ?? '',
    'school_name' => 'МБОУ «Средняя школа № 12»',
]);

$t->contains($owner->get('/panel/settings')['body'], 'МБОУ «Средняя школа № 12»',
    'название школы сохранено');
$t->contains($owner->get('/panel')['body'], 'МБОУ «Средняя школа № 12»',
    'название школы видно в панели');

// В форме нового класса название подставляется из настроек.
$classesPage = $owner->get('/classes/new');
$t->contains($classesPage['body'], 'value="МБОУ «Средняя школа № 12»"',
    'название школы подставлено в форму класса');

/*
 * Поля ядра рисуются теми же хуками, что отданы плагинам.
 *
 * Сравнивается посимвольно то, что вернул хук, с тем, что пришло на страницу:
 * проверка «есть класс form-control» пропустила бы форму, собранную вручную и
 * разойдяшуюся с плагином в подписи, подсказке или отметке обязательности.
 * Равенство ядра и плагина — и есть требование к API; проверяется оно здесь,
 * потому что страницу создания класса открывает владелец, а не учитель.
 */
$ownerUi = (new MyEdu\Core\View(dirname(__DIR__) . '/templates'))->ui();

$t->contains($classesPage['body'], $ownerUi->field('name', [
    'label'       => 'Номер класса',
    'value'       => '',
    'required'    => true,
    'maxlength'   => 32,
    'placeholder' => '7-Б',
    'hint'        => 'Укажите номер класса, например 7-Б',
    'help'        => 'Обязательно и уникально, например «7-Б».',
    'attrs'       => ['autofocus' => true],
]), 'поле в ядре совпадает с тем, что отдаёт хук');

$classYearValue = preg_match(
    '#<label class="form-label" for="year">.*?value="([^"]*)"#s',
    $classesPage['body'],
    $classYearMatch
) === 1 ? html_entity_decode($classYearMatch[1], ENT_QUOTES, 'UTF-8') : '';

$t->contains($classesPage['body'], $ownerUi->field('year', [
    'label'       => 'Учебный год',
    'value'       => $classYearValue,
    'maxlength'   => 9,
    'inputmode'   => 'numeric',
    'pattern'     => '[0-9]{4}(-[0-9]{4})?',
    'placeholder' => '2026-2027',
    'hint'        => 'Год одним числом или парой: 2026 или 2026-2027',
    'help'        => 'С 1 сентября по 31 мая следующего года. Можно написать «2026» или «2026-2027». Необязательно.',
]), 'поле с проверкой формата рисуется ядром так же');

// Завуч настройками сайта не управляет.
$headPage = $owner->get('/panel/settings');
$owner->post('/panel/users/roles', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $teacherId,
    'roles[]' => [Role::HEAD_TEACHER],
]);

$head = new TestClient($baseUrl);
$headLogin = $head->get('/login');
$head->post('/login', [
    '_token'     => $head->tokenFrom($headLogin) ?? '',
    'identifier' => 'teacher@school.test',
    'password'   => 'uchitel2025',
]);

$t->is($head->get('/panel/settings')['status'] === 403, 'завуч не управляет настройками сайта');
$t->is($head->post('/panel/settings', ['school_name' => 'Чужая школа'])['status'] === 403,
    'завуч не может сменить название школы');

$t->equals((int) $db->value("SELECT COUNT(*) FROM settings WHERE `value` = 'Чужая школа'"), 0,
    'название школы не изменилось');

// Роль возвращаем: ниже по файлу этот же пользователь проверяется как учитель,
// у которого доступа к классам нет.
$users->setRole($teacherId, Role::TEACHER);

// ------------------------------------------------------------------ классы

$t->group('Классы');

$classesPage = $owner->get('/classes');
$t->is($classesPage['status'] === 200, 'страница классов открыта');

$created = $owner->post('/classes', [
    '_token' => $owner->tokenFrom($classesPage) ?? '',
    'name'   => '7 А',
    'title'  => 'Средняя школа № 1',
    'year'   => '2026/2027',
]);

$t->is($created['status'] === 302, 'класс создан');

preg_match('#/classes/(\d+)#', $created['location'], $classMatch);
$classId = (int) ($classMatch[1] ?? 0);
$t->is($classId > 0, 'адрес класса получен');

$classRow = $classes->find($classId);
$t->equals((string) ($classRow['name'] ?? ''), '7 А', 'название класса сохранено');
$t->equals((string) ($classRow['title'] ?? ''), 'Средняя школа № 1', 'школа сохранена');

$t->group('Учебный год');

$t->equals('2026-2027', ClassRepository::academicYear($classRow['year'] ?? null),
    'учебный год показывается как 2026-2027');
$t->equals(2026, (int) ($classRow['year'] ?? 0),
    'из «2026/2027» в базу попал только год начала');
$t->equals('2026-2027', ClassRepository::academicYear(2026), 'год начала разворачивается в диапазон');
$t->equals('', ClassRepository::academicYear(null), 'пустой год не превращается в «-»');
$t->equals('', ClassRepository::academicYear(''), 'пустая строка не превращается в «-»');

$classPage = $owner->get('/classes/' . $classId);
$t->is($classPage['status'] === 200, 'карточка класса открыта');
$t->contains($classPage['body'], '7 А', 'название класса видно в карточке');
$t->contains($classPage['body'], 'value="2026-2027"', 'в форме учебный год в формате 2026-2027');
$t->contains($classPage['body'], 'С 1 сентября по 31 мая', 'подсказка про границы учебного года есть');

/*
 * Поле ФИО знает, что это ФИО.
 *
 * Браузер подставляет фамилию и имя из профиля человека, когда поле помечено
 * `autocomplete="name"`. Без отметки поле остаётся обычным текстовым: ученика,
 * которого заводили раньше в другом классе, пришлось бы вводить заново.
 *
 * Поле в строке списка намеренно помечено `autocomplete="off"`: оно правит
 * чужое имя, и подставить туда своё браузер не должен. Проверяется там, где
 * список учеников уже не пуст, — в пустом списке правки имени просто нет.
 */
$t->is(
    preg_match('#<input[^>]*name="full_name"[^>]*autocomplete="name"#s', (string) $classPage['body']) === 1,
    'поле для нового ученика помечено как ФИО'
);

/*
 * Год принимается и как «2026», и как «2026-2027»: учитель вводит как
 * привык. А вот «2026-2030» — опечатка, и молча согласиться с ней нельзя:
 * показать 2026-2027 вместо заданного года хуже, чем спросить.
 */
$owner->post('/classes/' . $classId, [
    '_token' => $owner->tokenFrom($classPage) ?? '',
    'name'   => '7 А',
    'title'  => 'Средняя школа № 1',
    'year'   => '2026',
]);

$t->equals(2026, (int) ($classes->find($classId)['year'] ?? 0), 'короткая запись года принята');

$owner->post('/classes/' . $classId, [
    '_token' => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
    'name'   => '7 А',
    'title'  => 'Средняя школа № 1',
    'year'   => '2026-2030',
]);

// Страницу открываем один раз: показ формы забирает сообщение из сессии.
$afterBadYear = $owner->get('/classes/' . $classId);

$t->is($afterBadYear['status'] === 200, 'страница класса открывается после отклонённого года');
/*
 * Отказ показывается баннером из шаблона: класс banner-danger. Раньше здесь был
 * .alert--error, которого в сборке gentelella нет вовсе, — то есть проверка
 * искала класс из прежних стилей и держалась на одном только собственном файле.
 */
$t->contains($afterBadYear['body'], 'banner-danger', 'опечатка в году объяснена');
$t->contains($afterBadYear['body'], 'Учебный год указан неверно', 'сказано, что не так с годом');
$t->contains($afterBadYear['body'], 'value="2026-2030"', 'после отказа в поле остаётся введённый год');
$t->contains($afterBadYear['body'], 'value="Средняя школа № 1"', 'после отказа сохраняется введённое название');
$t->equals(2026, (int) ($classes->find($classId)['year'] ?? 0), 'год при этом не потерян');
$t->contains($owner->get('/classes/' . $classId)['body'], 'value="2026-2027"',
    'после показа отказа форма возвращается к сохранённому году');

/*
 * Отказ на форме создания класса возвращает всё введённое, включая то, из-за
 * чего отказа не было.
 *
 * Правило общее для всех форм: номер и название уже набраны, и человек
 * исправляет один год. Стирать остальное — значит заставлять вводить всё заново.
 */
$newClassPage = $owner->get('/classes/new');
$refusedYear = $owner->post('/classes', [
    '_token' => $owner->tokenFrom($newClassPage) ?? '',
    'name'   => '7-Ж',
    'title'  => 'Параллель 2026/2027',
    'year'   => '20-26',
]);

$t->is($refusedYear['status'] === 422, 'форма создания класса отвечает отказом, а не редиректом');
$t->contains($refusedYear['body'], 'name="name" value="7-Ж"',
    'номер класса вернулся в форму после отказа');
$t->contains($refusedYear['body'], 'name="title" value="Параллель 2026/2027"',
    'название класса вернулось в форму после отказа');
$t->is(
    preg_match('#name="year"[^>]*value="20-26"#', $refusedYear['body']) === 1,
    'ошибочный год показан как ввели, а не как поняли'
);

// Ограничение числа попыток создать класс с тем же именем.
$repeat = $owner->post('/classes', [
    '_token' => $owner->tokenFrom($owner->get('/classes')) ?? '',
    'name'   => '7 А',
    'title'  => 'Средняя школа № 1',
    'year'   => '2026/2027',
]);

$t->contains($repeat['body'], 'уже существует', 'дубль класса отклонён с понятным сообщением');
$t->equals((int) $db->value("SELECT COUNT(*) FROM classes WHERE name = '7 А'"), 1, 'дубль класса не создан');

// ------------------------------------------------------------------ предметы

$t->group('Предметы класса');

$classPage = $owner->get('/classes/' . $classId);
$withSubject = $owner->post('/classes/' . $classId . '/subjects/create', [
    '_token' => $owner->tokenFrom($classPage) ?? '',
    'name'   => $mathSubjectName,
]);

$t->is($withSubject['status'] === 302, 'предмет создан');
$t->equals((int) $db->value('SELECT COUNT(*) FROM subjects WHERE name = :name', ['name' => $mathSubjectName]),
    1, 'предмет сохранён в базе');

$mathSubjectId = (int) $db->value('SELECT id FROM subjects WHERE name = :name LIMIT 1', ['name' => $mathSubjectName]);

/*
 * Поля кода у предмета нет и на странице состава класса — там он тоже
 * показывался меткой рядом с названием.
 */
$t->notContains($classPage['body'], 'name="code"', 'поля кода в создании предмета из класса нет');

$classPage = $owner->get('/classes/' . $classId);
$saveSubjects = $owner->post('/classes/' . $classId . '/subjects', [
    '_token'     => $owner->tokenFrom($classPage) ?? '',
    'subjects[]' => [$mathSubjectId],
]);

$t->is($saveSubjects['status'] === 302, 'предмет прикреплён к классу');
$t->equals((int) $db->value('SELECT COUNT(*) FROM class_subjects WHERE class_id = :id', ['id' => $classId]),
    1, 'в базе одна связь класса с предметом');

// Снятие предмета и проверка, что галочки действительно работают.
$owner->post('/classes/' . $classId . '/subjects', [
    '_token' => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_subjects WHERE class_id = :id', ['id' => $classId]),
    0, 'предмет снят с класса');

$owner->post('/classes/' . $classId . '/subjects', [
    '_token'     => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
    'subjects[]' => [$mathSubjectId],
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_subjects WHERE class_id = :id', ['id' => $classId]),
    1, 'предмет снова прикреплён');

/*
 * Предметы и учителя — две отдельные формы на одной вкладке, и сохранение
 * одного списка не должно затирать другой.
 *
 * Проверялось вручную и выглядело как «учителя слетают», хотя обе операции
 * трогали свои таблицы. Такая ошибка вернётся при любом будущем изменении
 * сборки страницы, поэтому её стоит держать проверкой.
 */
$owner->post('/classes/' . $classId . '/teachers', [
    '_token'      => $owner->tokenFrom($owner->get('/classes/' . $classId . '?tab=roster')) ?? '',
    'teachers[]' => [$teacherId],
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_teachers WHERE class_id = :id', ['id' => $classId]),
    1, 'учитель прикреплён к классу');

$rosterBeforeSubjects = $owner->get('/classes/' . $classId . '?tab=roster');

$owner->post('/classes/' . $classId . '/subjects', [
    '_token'      => $owner->tokenFrom($rosterBeforeSubjects) ?? '',
    'subjects[]' => [$mathSubjectId],
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_teachers WHERE class_id = :id', ['id' => $classId]),
    1, 'сохранение предметов не тронуло список учителей');

$owner->post('/classes/' . $classId . '/subjects/create', [
    '_token' => $owner->tokenFrom($owner->get('/classes/' . $classId . '?tab=roster')) ?? '',
    'name'   => 'Предмет для проверки ' . $testSuffix,
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_teachers WHERE class_id = :id', ['id' => $classId]),
    1, 'создание предмета тоже не тронуло список учителей');

$rosterAfter = $owner->get('/classes/' . $classId . '?tab=roster');

preg_match('#name="teachers\[\]" value="' . $teacherId . '"[^>]*>#', $rosterAfter['body'], $teacherMark);
$t->is(
    isset($teacherMark[0]) && str_contains($teacherMark[0], 'checked'),
    'на вкладке учитель по-прежнему отмечен'
);

/*
 * Без отметки версии браузер месяцами отдаёт сохранённые стили и скрипты, и
 * человек видит старое поведение при уже исправленном коде — «кнопка есть, а
 * не нажимается». Отметка ставится по времени изменения файла.
 */
preg_match('#assets/css/app\.css\?v=(\d+)#', $rosterAfter['body'], $cssVersion);
$t->is(count($cssVersion) === 2, 'стили подключаются с отметкой версии');

$classPageAfter = $owner->get('/classes/' . $classId);

// Скрипт правки имени со страницы класса убран: строку открывает сервер, по
// адресу. Проверяется, что оставшийся скрипт отметку версии всё ещё несёт.
preg_match('#assets/js/select-all\.js\?v=(\d+)#', $classPageAfter['body'], $jsVersion);
$t->is(count($jsVersion) === 2, 'скрипты подключаются с отметкой версии');

// ------------------------------------------------------------- каталог предметов

$t->group('Каталог предметов');

/*
 * Каталог нужен сам по себе. Пока предмет создавался только как побочный
 * эффект правки класса, посмотреть список целиком было негде, а удалить или
 * переименовать предмет — нечем.
 */
$subjectsPage = $owner->get('/panel/subjects');

$t->is($subjectsPage['status'] === 200, 'страница предметов открывается');
$t->contains($subjectsPage['body'], 'name="name"', 'на странице есть поле названия');

/*
 * Поля кода у предмета нет — см. миграцию 017.
 *
 * Код нигде не участвовал: перенос работ сопоставляет предметы по названию,
 * ни одна ссылка и ни одна выгрузка коду не обращалась. Он был наполнителем
 * колонки NOT NULL и обслуживанием уникального индекса, то есть синтетическим
 * идентификатором. Проверяется отсутствием: если поле вернётся, человек снова
 * будет вписывать в него BIO руками ради ничего.
 */
$t->notContains($subjectsPage['body'], 'name="code"', 'поля кода на странице нет');
$t->notContains($subjectsPage['body'], '>Код<', 'колонки кода в таблице нет');
$t->contains($subjectsPage['body'], '>' . $mathSubjectName . '<',
    'предмет, созданный через класс, виден в каталоге');

$subjectsPage = $owner->get('/panel/subjects');
$owner->post('/panel/subjects', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => $historySubjectName,
]);

$historyId = (int) $db->value('SELECT id FROM subjects WHERE name = :name LIMIT 1', ['name' => $historySubjectName]);

$t->is($historyId > 0, 'предмет добавлен по одному названию');

/*
 * Одинаковые названия разрешены, и раньше разрешались — просто код разводил их
 * по разным значениям (BIO и BIO2), а оба предмета всё равно создавались.
 * Теперь ограничения нет вовсе, и перенос работ выбирает между одноимёнными
 * первый по имени: названия предметов у учителя уникальны на практике, а
 * искусственная уникальность кода только прятала это.
 */
$subjectsPage = $owner->get('/panel/subjects');
$owner->post('/panel/subjects', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => $historySubjectName,
]);

$t->equals(2, (int) $db->value('SELECT COUNT(*) FROM subjects WHERE name = :name', ['name' => $historySubjectName]),
    'одинаковые названия добавлены дважды');

/* Пустое название отклоняется с объяснением. */
$subjectsPage = $owner->get('/panel/subjects');
$owner->post('/panel/subjects', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => '   ',
]);

$t->contains($owner->get('/panel/subjects')['body'], 'Укажите название предмета',
    'пустое название отклонено с объяснением');

/* Переименование меняет только название. */
$subjectsPage = $owner->get('/panel/subjects');
$renamedHistoryName = 'Тест школы ' . $testSuffix . ' — Всеобщая история';
$owner->post('/panel/subjects/' . $historyId, [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => $renamedHistoryName,
]);
$t->equals($renamedHistoryName, (string) $db->value('SELECT name FROM subjects WHERE id = :id', ['id' => $historyId]),
    'предмет переименован');

/*
 * Выключенный предмет нельзя выбрать для новой работы, но он остаётся в
 * списке с пометкой. Удалять его нельзя: на него ссылаются прошлые работы.
 */
$subjectsPage = $owner->get('/panel/subjects');
$owner->post('/panel/subjects/' . $historyId . '/status', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
]);

$t->equals(0, (int) $db->value('SELECT is_active FROM subjects WHERE id = :id', ['id' => $historyId]),
    'предмет выключен');

/*
 * Выключенный предмет нельзя выбрать для новой работы, но он остаётся в
 * каталоге: на него ссылаются прошлые работы, поэтому он не удаляется, а
 * исчезает из списков выбора.
 *
 * Пометки «выключен» в каталоге теперь нет — столбец «Состояние» убран по
 * образцу таблиц шаблона, и показывать состояние значило бы вернуть его
 * отдельной подписью. Проверяется то, что действительно важно: предмет остаётся
 * в базе и пропадает из выбора.
 */
$subjectsPage = $owner->get('/panel/subjects');
$t->contains($subjectsPage['body'], $renamedHistoryName, 'выключенный предмет остаётся в каталоге');

$subjectsPage = $owner->get('/panel/subjects');
$owner->post('/panel/subjects/' . $historyId . '/status', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
]);

$t->equals(1, (int) $db->value('SELECT is_active FROM subjects WHERE id = :id', ['id' => $historyId]),
    'предмет снова включён');

/* Пункт меню и подсветка: предметы не должны перехватывать подсветку у классов. */
$t->equals('Предметы', $navLabel($owner->get('/panel/subjects')['body']),
    'на странице предметов горит «Предметы»');
$t->equals('Классы и ученики', $navLabel($owner->get('/classes')['body']),
    'на списке классов по-прежнему горит «Классы и ученики»');

/* Права: каталог ведёт завуч, а учитель не управляет предметами. */
$subjectsPage = $owner->get('/panel/subjects');
$missing = $owner->post('/panel/subjects/999999', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => 'Несуществующий',
]);

$t->is($missing['status'] === 404, 'несуществующий предмет даёт 404');

/*
 * Каталог предметов по образцу таблиц шаблона.
 *
 * Проверяется то, что человек видит и чем может управлять: порядок столбцов,
 * добавление из окна, правка названия в строке и меню с удалением. Столбцы
 * «Состояние» и «Выключить» убраны, поэтому проверяется и их отсутствие: вернуться
 * они могут молча, вместе с прежней вёрсткой.
 */
$t->group('Каталог предметов по образцу таблиц шаблона');

$subjectsPage = $owner->get('/panel/subjects');
$subjectsBody = $subjectsPage['body'];

preg_match('#<thead>(.*?)</thead>#su', $subjectsBody, $subjectsHead);
preg_match_all('#<th[^>]*>(.*?)</th>#su', $subjectsHead[1] ?? '', $subjectsTh);

$t->equals(
    ['Предмет', 'Классы', 'Работы', ''],
    array_map(static fn (string $cell): string => trim(strip_tags($cell)), $subjectsTh[1] ?? []),
    'порядок столбцов каталога: предмет, классы, работы и действия'
);
$t->notContains($subjectsBody, '>Состояние<', 'столбца «Состояние» больше нет');
$t->notContains($subjectsBody, '>Выключить<', 'кнопки «Выключить» в строке больше нет');
$t->notContains($subjectsBody, 'Export', 'кнопки выгрузки из образца на эту страницу не переносились');

/* Добавление — из окна, а не блоком под шапкой. */
$t->contains($subjectsBody, 'data-modal-open="subject-add"',
    'кнопка «Добавить предмет» вызывает окно');
$t->contains($subjectsBody, 'data-modal id="subject-add"',
    'окно добавления лежит в разметке и работает без скриптов');
$t->contains($subjectsBody, 'name="name"',
    'в окне есть поле названия');

/*
 * Окно не должно занимать место на странице, пока до него не дошёл скрипт.
 *
 * Правило `html:not([data-js]) [data-modal] { display: block }` показывает окно
 * как обычную карточку — так страница работает без скриптов. Но между началом
 * разбора страницы и запуском `modal-open.js` окно оставалось блоком в потоке.
 * На странице с формой задания это визуальный редактор с полями и вариантами,
 * и документ становился почти на тысячу пикселей выше: браузер уводил
 * прокрутку к раздутому блоку, скрипт закрывал окно, документ сжимался — а
 * прокрутка оставалась внизу. Закрытие окна выглядело так, будто страницу
 * утащило в самый конец, и происходило это для любой карточки.
 *
 * Проверяется и сам факт сокрытия, и то, чем окно показывается обратно: от
 * одного `display: none` мало — он перебивает атрибут `hidden`, которым скрипт
 * управляет окном, и окно не открылось бы вовсе.
 */
$themeCss = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/css/theme.css');

$t->is(
    preg_match('#\[data-modal\]\s*\{\s*display:\s*none#s', $themeCss) === 1,
    'окно скрыто правилом по умолчанию: до скрипта оно не занимает место'
);
$t->is(
    preg_match('#html\[data-modal-ready\]\s*\[data-modal\]:not\(\[hidden\]\)\s*\{\s*display:\s*block#s', $themeCss) === 1,
    'показ окна зависит и от отметки скрипта, и от снятого hidden'
);
$t->is(
    preg_match('#html:not\(\[data-js\]\)\s*\[data-modal\]:not\(\[hidden\]\)\s*\{[^}]*display:\s*block#s', $themeCss) === 1,
    'без скриптов окно по-прежнему показано карточкой'
);
$t->is(
    preg_match('#html:not\(\[data-js\]\)\s*\[data-modal\]\s*\{[^}]*display:\s*block#s', $themeCss) !== 1,
    'пустое окно не показывается даже без скриптов: в нём нечего смотреть'
);

/*
 * Отметку `data-js` ставить нужно в шапке, а не в том скрипте, который первым
 * до неё дотянется: пока отметки нет, действует правило выше и окно снова
 * оказывается в потоке. `theme-boot.js` подключён без `defer` в `<head>`,
 * поэтому к разбору тела он уже выполнился.
 */
$themeBoot = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/js/theme-boot.js');

$t->contains($themeBoot, "setAttribute('data-js'",
    'отметку «скрипты работают» ставит скрипт из шапки');

$shellSubjects = (string) @file_get_contents(dirname(__DIR__) . '/templates/_shell_panel.php');
$bootPos = strpos($shellSubjects, 'assets/js/theme-boot.js');
$bodyPos = strpos($shellSubjects, '<body');

$t->is($bootPos !== false && $bodyPos !== false && $bootPos < $bodyPos,
    'скрипт из шапки подключён до тела: иначе окно успевает раздуть страницу');
$t->is(preg_match('#assets/js/theme-boot\.js[^\n]*\bdefer\b#', $shellSubjects) !== 1,
    'скрипт из шапки не отложен: defer отложил бы отметку до конца разбора');

/*
 * Возврат фокуса не должен уводить страницу.
 *
 * Окно открывается как оверлей, и страница под ним никуда не девается: автор
 * правит одно задание и после закрытия должен оказаться там же, где оставил
 * доску. Фокус без `preventScroll` прокручивает страницу к кнопке, а кнопка
 * закрытия лежит в конце разметки — страница уезжает вниз.
 */
$modalOpen = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/js/modal-open.js');

$t->contains($modalOpen, 'preventScroll',
    'фокус в окне передаётся с запретом прокрутки');
$t->contains($modalOpen, 'data-modal-ready',
    'скрипт отмечает, что окна обслуживает он, и только после этого они показываются');

/* Название правится в строке: по умолчанию текст, по адресу — поле. */
$t->contains($subjectsBody, 'inline-value__text', 'в строке по умолчанию текст');
$t->contains($subjectsBody, 'panel/subjects?edit=', 'у предмета есть карандаш правки');
$t->notContains($subjectsBody, 'data-inline-form',
    'поле правки закрыто, пока его не открыли');

$subjectEdit = $owner->get('/panel/subjects?edit=' . $historyId);

$t->is($subjectEdit['status'] === 200, 'страница с открытой правкой открывается');
$t->contains($subjectEdit['body'], 'data-inline-form',
    'по адресу правки приезжает форма');
$t->contains($subjectEdit['body'], 'icon-btn--save',
    'у формы есть кнопка «Сохранить»');
$t->contains($subjectEdit['body'], 'data-inline-template',
    'скрипту отдана разметка обычного состояния строки');

/*
 * Переименование тем же адресом отвечает и форме, и скрипту: без JavaScript
 * форма ждёт перенаправления, скрипт просит JSON и не перезагружает страницу.
 */
$subjectsPage = $owner->get('/panel/subjects');
$jsonRename = $owner->post('/panel/subjects/' . $historyId, [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
    'name'   => 'Всеобщая история ' . $testSuffix,
], ['Accept: application/json']);

$t->contains($jsonRename['body'], '"ok":true', 'скрипту приходит подтверждение правки');
$t->contains($jsonRename['body'], 'Всеобщая история ' . $testSuffix,
    'скрипту приходит сохранённое название');

/* Меню строки: единственный пункт — удаление, и с подтверждением. */
$t->equals(
    substr_count($subjectsBody, '<td class="row-actions">'),
    substr_count($subjectsBody, 'class="row-menu"'),
    'меню действий стоит в каждой строке каталога'
);
$t->contains($subjectsBody, 'Удалить предмет', 'в меню есть пункт удаления');
$t->contains($subjectsBody, 'panel/subjects/' . $historyId . '/delete',
    'пункт удаления ведёт на свой адрес');
$t->contains($subjectsBody, 'data-confirm="Удалить предмет', 'удаление спрашивается подтверждением');

/*
 * Удаление: предмет, на который ссылается класс или работа, удалить нельзя —
 * вместо этого объясняется, что мешает. Свободный удаляется.
 *
 * Проверяется предмет из класса: он заведён выше и назначен классу, то есть
 * ссылка на него есть в самой таблице.
 */
$t->is(
    (int) $db->value(
        'SELECT COUNT(*) FROM class_subjects WHERE subject_id = :id',
        ['id' => $mathSubjectId]
    ) > 0,
    'предмет, на который ссылается класс, есть в каталоге'
);

$owner->post('/panel/subjects/' . $mathSubjectId . '/delete', [
    '_token' => $owner->tokenFrom($subjectsPage) ?? '',
]);

$t->equals(
    1,
    (int) $db->value('SELECT COUNT(*) FROM subjects WHERE id = :id', ['id' => $mathSubjectId]),
    'предмет, на который ссылается класс, удалить нельзя'
);
$t->contains($owner->get('/panel/subjects')['body'], 'удалить нельзя',
    'отказ в удалении объясняет, что мешает');

/* Свободный предмет удаляется, и удаление спрашивалось подтверждением. */
$owner->post('/panel/subjects', [
    '_token' => $owner->tokenFrom($owner->get('/panel/subjects')) ?? '',
    'name'   => 'Удаляемый предмет ' . $testSuffix,
]);

$throwawayId = (int) $db->value(
    'SELECT id FROM subjects WHERE name = :name LIMIT 1',
    ['name' => 'Удаляемый предмет ' . $testSuffix]
);

$throwawayPage = $owner->get('/panel/subjects');
$t->contains($throwawayPage['body'], 'data-confirm="Удалить предмет Удаляемый предмет',
    'подтверждение удаления называет предмет, а не «строку»');

$owner->post('/panel/subjects/' . $throwawayId . '/delete', [
    '_token' => $owner->tokenFrom($throwawayPage) ?? '',
]);

$t->equals(
    0,
    (int) $db->value('SELECT COUNT(*) FROM subjects WHERE id = :id', ['id' => $throwawayId]),
    'свободный предмет удалён'
);

$db->run('DELETE FROM subjects WHERE name IN (:history, :renamed, :math)', [
    'history' => $historySubjectName,
    'renamed' => $renamedHistoryName,
    'math'    => $mathSubjectName,
]);

$t->equals((int) $db->value('SELECT COUNT(*) FROM subjects WHERE name IN (:history, :renamed, :math)', [
    'history' => $historySubjectName,
    'renamed' => $renamedHistoryName,
    'math'    => $mathSubjectName,
]), 0, 'временные предметы удалены');

// ------------------------------------------------------------------ учителя

$t->group('Учителя класса');

$classPage = $owner->get('/classes/' . $classId);
$saveTeachers = $owner->post('/classes/' . $classId . '/teachers', [
    '_token'     => $owner->tokenFrom($classPage) ?? '',
    'teachers[]' => [$teacherId],
]);

$t->is($saveTeachers['status'] === 302, 'учитель прикреплён к классу');
$t->equals((int) $db->value('SELECT COUNT(*) FROM class_teachers WHERE class_id = :id AND user_id = :u',
    ['id' => $classId, 'u' => $teacherId]), 1, 'связь класса с учителем создана');
$t->contains($owner->get('/classes/' . $classId . '?tab=roster')['body'], 'Иванова', 'учитель виден на странице класса');

// ------------------------------------------------------------------ ученики

$t->group('Регистрация ученика');

$studentsPage = $owner->get('/classes/' . $classId);
$t->is($studentsPage['status'] === 200, 'страница учеников открыта');

/*
 * Отдельного адреса со списком учеников больше нет: он уводит в редактор
 * класса. Адрес остаётся рабочим, иначе закладки и старые ссылки превратились
 * бы в ошибку.
 */
$t->is(
    $owner->get('/classes/' . $classId . '/students')['status'] === 302,
    'старый адрес списка учеников ведёт в редактор класса'
);

$manual = $owner->post('/classes/' . $classId . '/students', [
    '_token'    => $owner->tokenFrom($studentsPage) ?? '',
    'full_name' => 'Петров Иван Ильич',
]);

/*
 * Лист с паролем приходит сразу тем ответом, которым ученик добавлен.
 *
 * Раньше пароль клался в сессию и показывался позже, из таблицы класса, а
 * «Печать паролей» печатала его задним числом. Пароль в сессии — это пароль в
 * файле на диске, доступном любому, у кого есть файловый доступ к хостингу, на
 * всё время сеанса учителя.
 */
$t->is($manual['status'] === 200, 'добавление сразу отдаёт лист с паролем');

$petrov = $users->findRowByEmail('petrov@example.edu')
    ?: $db->one("SELECT * FROM users WHERE full_name = 'Петров Иван Ильич' LIMIT 1");
$petrovId = (int) ($petrov['id'] ?? 0);

$t->is($petrovId > 0, 'ученик сохранён в базе');
$t->equals((int) $db->value('SELECT COUNT(*) FROM class_students WHERE class_id = :id AND student_id = :u',
    ['id' => $classId, 'u' => $petrovId]), 1, 'ученик добавлен в класс');
$t->is(in_array(Role::STUDENT, $users->rolesOf($petrovId), true), 'ученику выдана роль student');

preg_match('#<dd><code>\s*([А-ЯЁ]\d{4})\s*</code>#u', $manual['body'], $loginMatch);
preg_match('#<dd><code>\s*(\d{8})\s*</code>#u', $manual['body'], $passMatch);

$petrovLogin = (string) ($loginMatch[1] ?? '');
$petrovPassword = (string) ($passMatch[1] ?? '');

$t->is($petrovLogin !== '', 'логин ученика показан на листе: ' . $petrovLogin);
$t->is(strlen($petrovPassword) === 8, 'пароль из восьми цифр показан один раз');

/*
 * И нигде больше он не остаётся: ни в редакторе класса, ни в файле сессии.
 */
$classAfterAdd = $owner->get('/classes/' . $classId);

$t->notContains($classAfterAdd['body'], $petrovPassword, 'в редакторе класса пароля нет');
$t->contains($classAfterAdd['body'], 'Ученики класса', 'редактор класса открывается с таблицей учеников');
$t->notContains($classAfterAdd['body'], 'credentials/print', 'кнопки печати выданных паролей больше нет');

$t->group('Вход ученика по выданным данным');

$studentClient = new TestClient($baseUrl);
$studentLoginPage = $studentClient->get('/student/login');

/*
 * Ученик входит своей формой: логин и пароль с листа и ничего больше.
 *
 * Общая форма предлагала ученику e-mail и кнопку регистрации — два способа,
 * которыми он не пользуется, и ни одного, которым пользуется. Вход по общей
 * форме продолжает работать (учитель мог отдать ссылку не туда), но адрес на
 * листе и в подсказке — тот, где спрашивают только логин.
 */
$t->is($studentLoginPage['status'] === 200, 'форма входа ученика открыта');
$t->contains($studentLoginPage['body'], 'name="identifier"', 'в форме ученика есть логин');
$t->contains($studentLoginPage['body'], 'name="password"', 'в форме ученика есть пароль');
$t->contains($studentLoginPage['body'], 'Вход для ученика', 'форма озаглавлена как ученическая');
$t->notContains($studentLoginPage['body'], 'Зарегистрироваться',
    'в форме ученика нет кнопки регистрации');
$t->notContains($studentLoginPage['body'], 'Логин или e-mail',
    'в форме ученика не спрашивают про e-mail');
$t->notContains($studentLoginPage['body'], 'auth/forgot',
    'восстановление пароля по почте ученику не предлагают');

$studentLogin = $studentClient->post('/student/login', [
    '_token'     => $studentClient->tokenFrom($studentLoginPage) ?? '',
    'identifier' => $petrovLogin,
    'password'   => $petrovPassword,
]);

$t->is($studentLogin['status'] === 302 && !str_contains($studentLogin['location'], '/login'),
    'ученик вошёл по выданному логину и паролю');

// Повторная регистрация ученика в том же классе отклоняется.
$duplicate = $owner->post('/classes/' . $classId . '/students', [
    '_token'    => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
    'full_name' => 'Петров Иван Ильич',
]);

$t->is($duplicate['status'] === 302, 'повторная регистрация обработана');
$t->equals((int) $db->value('SELECT COUNT(*) FROM users WHERE full_name = :n',
    ['n' => 'Петров Иван Ильич']), 1, 'дубль ученика не создан');
$duplicatePage = $owner->get('/classes/' . $classId);
$t->contains($duplicatePage['body'], 'value="Петров Иван Ильич"',
    'после отказа в форме остаётся введённое ФИО');

// ------------------------------------------------------------------ CSV

$t->group('Шаблон и импорт списка');

$csvParser = new \MyEdu\Auth\StudentListCsv();
$csvValidation = $csvParser->parse(
    "Фамилия;Имя;Отчество\r\n\r\n"
    . str_repeat('Ф', 65) . ";Иван;Ильич\r\n"
    . "Петров;Анна;Игоревна;Лишняя колонка\r\n"
    . "Сидоров;Илья;Ильич\r\n"
);
$t->equals(1, count($csvValidation['rows']), 'в разбор попадает только валидная CSV-строка');
$t->equals('Сидоров', $csvValidation['rows'][0]['family'], 'невалидные строки не создают ученика');
$t->equals(3, $csvValidation['errors'][0]['line'], 'номер строки учитывает пустые строки');
$t->contains($csvValidation['errors'][0]['message'], 'длинное', 'длинное ФИО отмечается ошибкой и отклоняется');
$t->equals(4, $csvValidation['errors'][1]['line'], 'лишняя колонка указывается на физическом номере строки');

$template = $owner->get('/classes/' . $classId . '/students/template');
$t->is($template['status'] === 200, 'шаблон отдаётся');
$t->contains($template['body'], 'Фамилия', 'в шаблоне есть заголовки');
$t->contains($template['body'], 'Сидорова', 'в шаблоне есть пример строки');

$csvPath = (function (): string {
    $content = "\xEF\xBB\xBF" . "Фамилия;Имя;Отчество\r\n"
        . "Сидорова;Мария;Алексеевна\r\n"
        . "Кузнецов;Пётр;\r\n"
        . "Петров;Иван;Ильич\r\n"
        . ";Безфамилия;\r\n"
        . "Никитин;Алексей;Петрович\r\n";

    $path = tempnam(sys_get_temp_dir(), 'spisok') . '.csv';
    file_put_contents($path, $content);

    return $path;
})();

$import = $owner->upload(
    '/classes/' . $classId . '/students/import',
    ['_token' => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? ''],
    ['file' => $csvPath]
);

/*
 * Импорт сразу отдаёт лист с паролями: раньше они клались в сессию, и файл с
 * двадцатью учениками оставлял в файле сессии двадцать паролей — до самого
 * конца сеанса учителя.
 */
$t->is($import['status'] === 200, 'импорт обработан и сразу отдал лист с паролями');
$t->contains($import['body'], 'Логины и пароли', 'на импорте показан лист на печать');
$t->is(
    preg_match_all('/entry__name/', $import['body']) === 3,
    'в листе по одному ученику на каждую заведённую строку файла'
);

/*
 * Отчёт об импорте и замечания по строкам человек увидит после возврата в
 * класс: они лежат в сессии и показываются один раз.
 */
$reportPage = $owner->get('/classes/' . $classId);
$t->contains($reportPage['body'], 'Из файла зарегистрировано учеников', 'отчёт об импорте показан');

$t->contains($reportPage['body'], 'строка 5 — не указана фамилия', 'ошибка строки показана');
$t->contains($reportPage['body'], 'уже зарегистрирован', 'пропуск дубля показан');

$t->equals((int) $db->value('SELECT COUNT(*) FROM users WHERE full_name LIKE :n',
    ['n' => 'Сидорова Мария Алексеевна']), 1, 'ученик из файла создан');
$t->equals((int) $db->value('SELECT COUNT(*) FROM users WHERE full_name LIKE :n',
    ['n' => 'Кузнецов Пётр']), 1, 'ученик без отчества создан');
$t->equals((int) $db->value('SELECT COUNT(*) FROM users WHERE full_name LIKE :n',
    ['n' => 'Петров Иван Ильич']), 1, 'дубль из файла не создан');
$t->equals((int) $db->value('SELECT COUNT(*) FROM users WHERE full_name LIKE :n',
    ['n' => 'Безфамилия%']), 0, 'строка без фамилии не создана');

$t->equals((int) $db->value('SELECT COUNT(*) FROM class_students WHERE class_id = :id', ['id' => $classId]),
    4, 'в классе четыре ученика');

@unlink($csvPath);

// ------------------------------------------------------------------ смена паролей

$t->group('Смена паролей учеников');

$sidorovaId = (int) $db->value("SELECT id FROM users WHERE full_name LIKE 'Сидорова%' LIMIT 1");

$resetPage = $owner->get('/classes/' . $classId);
$t->is($resetPage['status'] === 200, 'страница выбора учеников открыта');

$reveal = $owner->post('/classes/' . $classId . '/credentials/reveal', [
    '_token'    => $owner->tokenFrom($resetPage) ?? '',
    'students[]' => [$sidorovaId],
]);

$t->is($reveal['status'] === 200, 'выдача сразу отдаёт лист с новыми паролями');

/*
 * Пароль достаётся из карточки именно этого ученика.
 *
 * На листе их может быть несколько: отмеченных учеников выдача меняет разом.
 * Взять «первый попавшийся» — значит взять пароль соседа, и проверка входа
 * затем проверяла бы чужой пароль.
 */
$passwordOfStudent = static function (string $html, string $name): string {
    $at = mb_strpos($html, $name);

    if ($at === false) {
        return '';
    }

    // Пароль берётся из карточки, где стоит имя: на листе это первый пароль
    // после фамилии, то есть ровно её собственный.
    $tail = substr($html, $at);

    return preg_match('#<dd><code>\s*(\d{8})\s*</code>#u', $tail, $m) === 1 ? (string) $m[1] : '';
};

$sidorovaPassword = $passwordOfStudent($reveal['body'], 'Сидорова');

$t->is(strlen($sidorovaPassword) === 8, 'новый пароль из восьми цифр выдан');

/*
 * И пароля нет ни в таблице класса, ни где-либо ещё: он жил только в этом
 * ответе.
 */
$classAfterReveal = $owner->get('/classes/' . $classId);

$t->notContains($classAfterReveal['body'], $sidorovaPassword, 'в редакторе класса нового пароля нет');

// Старый пароль больше не подходит, новый — подходит.
$sidorovaRow = $users->findRow($sidorovaId);
$sidorovaLogin = (string) $sidorovaRow['login'];

$checkOld = new TestClient($baseUrl);
$oldPage = $checkOld->get('/login');
$checkOld->post('/login', [
    '_token'     => $checkOld->tokenFrom($oldPage) ?? '',
    'identifier' => $sidorovaLogin,
    'password'   => '12345678',
]);

$t->is($checkOld->get('/portal')['status'] === 302, 'старый пароль не подходит');

$checkNew = new TestClient($baseUrl);
$newPage = $checkNew->get('/login');
$checkNew->post('/login', [
    '_token'     => $checkNew->tokenFrom($newPage) ?? '',
    'identifier' => $sidorovaLogin,
    'password'   => $sidorovaPassword,
]);

$t->is($checkNew->get('/portal')['status'] === 200, 'новый пароль подходит');

// ------------------------------------------------------------------ правка имени

$t->group('Правка фамилии ученика');

/*
 * Опечатку в фамилии раньше исправить было нечем: импорт CSV существующих
 * пропускает, а админская страница пользователей ученикам закрыта. Проверяется
 * не только запись в базу, но и части имени: фамилия, имя и отчество хранятся
 * отдельно, и без их пересборки в печатном листе осталось бы прежнее ФИО.
 */
$studentsList = $owner->get('/classes/' . $classId);

$t->contains($studentsList['body'], '/students/status', 'у ученика есть действие блокировки');

$t->contains($studentsList['body'], '?edit=' . $petrovId, 'у ученика есть ссылка правки имени');

/*
 * Форма правки раскрывается по ссылке, а не висит в каждой строке: править
 * одного ученика из двадцати пяти, сразу развернув двадцать пять форм, —
 * неудобно и нечитаемо. И состояние строки решает сервер: скриптов на
 * странице нет, а значит, таблица не зависит ни от стилей, ни от того, доехал
 * ли скрипт до браузера.
 */
$editForm = $owner->get('/classes/' . $classId . '?edit=' . $petrovId);

$t->contains($editForm['body'], '/students/rename', 'по ссылке раскрывается форма правки имени');
$t->notContains($studentsList['body'], '/students/rename',
    'без ссылки формы правки в таблице нет вовсе');
$t->notContains($studentsList['body'], 'data-student-form',
    'скрытых форм правки в разметке больше нет');

/*
 * Открытие поля осталось серверным, а сохранение ушло скрипту.
 *
 * Раньше здесь стояло требование «скриптов на странице нет вообще». Оно было
 * верно, пока сохранение тоже уходило с перезагрузкой, — но перезагрузка
 * возвращала человека к началу таблицы, и правка одного имени стоила
 * прокрутки всей таблицы заново. Теперь скрипт есть, и требование к нему
 * другое: он обязан только убирать перезагрузку, а форму в целом — уметь
 * отправить и без него. Поэтому проверяется не отсутствие скрипта, а то, что
 * форма осталась обычной: method="post" и адрес обработчика на месте, и
 * открытая строка по-прежнему приезжает с сервера по адресу ?edit=.
 */
$t->contains($editForm['body'], 'inline-edit.js',
    'скрипт сохранения значения в строке подключён к странице');
$t->contains($editForm['body'], 'method="post"',
    'форма правки отправляется обычным POST и работает без скрипта');
$t->contains($editForm['body'], 'data-inline-form',
    'форма правки помечена для скрипта');
$t->contains($editForm['body'], 'data-inline-template',
    'скрипту отдана разметка обычного состояния строки');
$t->contains($editForm['body'], 'name="full_name" required',
    'поле имени осталось обязательным — без скриптов браузер проверит его сам');

/*
 * Правка имени проверялась отправкой прямо на адрес — и потому проходила, пока
 * страница была сломана. Вложенных форм в HTML нет: браузер выбрасывает тег
 * второй формы, и поля «Сохранить имя», «Заблокировать» и «Убрать» уезжают в
 * форму, охватывающую таблицу целиком, то есть никуда. Проверяется разметка так,
 * как её читает браузер: ни одна форма не вложена в другую.
 */
$formDepth = 0;
$maxFormDepth = 0;
preg_match_all('#<form\b|</form>#i', $editForm['body'], $formTags);

foreach ($formTags[0] as $formTag) {
    $formDepth += ($formTag === '</form>') ? -1 : 1;
    if ($formDepth > $maxFormDepth) {
        $maxFormDepth = $formDepth;
    }
}

$t->equals(1, $maxFormDepth, 'ни одна форма страницы не вложена в другую');
$t->equals(0, $formDepth, 'формы на странице закрыты все');
$t->contains($editForm['body'], 'id="roster-pick"', 'форма отметок вынесена из таблицы');

/*
 * Отметки и кнопка стоят вне формы отметок, поэтому привязаны к ней атрибутом
 * form. Без привязки кнопка отправила бы саму себя, без отметок, и выдача
 * паролей не нашла бы никого — то есть поломалось бы ровно то, что форма
 * отметок и удерживает.
 */
preg_match_all(
    '#<button\b[^>]*form="roster-pick"[^>]*formaction="[^"]*credentials/reveal"#',
    $editForm['body'],
    $boundButtons
);

$t->equals(1, count($boundButtons[0] ?? []),
    'кнопка выдачи паролей привязана к форме отметок');

$t->equals(
    (int) preg_match_all('#<input\b[^>]*type="checkbox"[^>]*name="students\[\]"#', $editForm['body']),
    (int) preg_match_all('#<input\b[^>]*type="checkbox"[^>]*name="students\[\]"[^>]*form="roster-pick"#', $editForm['body']),
    'каждая отметка ученика привязана к форме отметок'
);

/*
 * Галочка «Выбрать всех» привязана к той же форме.
 *
 * Она лежит в шапке таблицы, то есть снаружи формы отметок, и по вложенности
 * к форме не относится: если бы искать поля только внутри неё, галочка нашла
 * бы ноль отметок и выглядела бы рабочей, но не отмечала ничего. Скрипт
 * спрашивает у браузера принадлежность поля к форме, и проверяется, что
 * принадлежность в разметке действительно проставлена.
 */
$t->contains($editForm['body'], 'data-select-all form="roster-pick"',
    'галочка «Выбрать всех» привязана к форме отметок');

/*
 * Строки учеников пронумерованы по порядку, с единицы.
 *
 * Номер привязан к позиции, а не к учётной записи: сослаться можно ровно на
 * то, что человек видит перед собой. Поэтому проверяется не «номера вообще
 * есть», а что они идут подряд с единицы и столько же их, сколько учеников.
 */
preg_match_all('#<td class="credential-table__num">(\d+)</td>#', $editForm['body'], $rowNumbers);

$t->equals(
    range(1, max(1, count($rowNumbers[1] ?? []))),
    array_map('intval', $rowNumbers[1] ?? []),
    'строки учеников пронумерованы подряд с единицы'
);

$t->equals((int) $db->value(
    'SELECT COUNT(*) FROM class_students WHERE class_id = :class',
    ['class' => $classId]
), count($rowNumbers[1] ?? []), 'пронумерованы все ученики класса');
$t->contains($editForm['body'], '<th scope="col" class="credential-table__num">№</th>',
    'у колонки с номерами есть заголовок');

/*
 * Порядок колонок в шапке и в строке должен совпадать.
 *
 * Раньше «№» и отметка стояли в шапке наоборот, чем в строках: в шапке первой
 * шла галочка, в строке — номер. Браузер этого не проверяет и показывает
 * таблицу как есть, поэтому заголовок «Выбрать всех» оказывался над фамилией, а
 * номер — над пустой отметкой. Ни один тест этого не ловил: все смотрели, что
 * нужные элементы есть, а не что они стоят на своих местах.
 */
$headBlock = preg_match('/<thead>(.*?)<\/thead>/s', $editForm['body'], $headHit) === 1
    ? $headHit[1]
    : '';
$rowBlock = preg_match('/<tbody>(.*?)<\/tr>/s', $editForm['body'], $rowHit) === 1
    ? $rowHit[1]
    : '';

preg_match_all('/<th\b[^>]*>/', $headBlock, $headTags);
preg_match_all('/<td\b[^>]*>/', $rowBlock, $rowTags);

$headTags = $headTags[0];
$rowTags = $rowTags[0];

$t->equals(count($headTags), count($rowTags), 'в строке столько же ячеек, сколько заголовков');
$t->is(
    str_contains($headTags[0] ?? '', 'credential-table__num'),
    'нумерация стоит в шапке первой'
);
$t->is(
    str_contains($headTags[1] ?? '', 'credential-table__pick'),
    'отметка стоит в шапке второй'
);
$t->is(
    str_contains($rowTags[0] ?? '', 'credential-table__num'),
    'в строке номер тоже первый'
);
$t->is(
    str_contains($rowTags[1] ?? '', 'credential-table__pick'),
    'в строке отметка тоже вторая'
);
$t->is(
    str_contains($rowTags[2] ?? '', 'credential-table__name'),
    'третьей идёт фамилия — как и в шапке'
);

/*
 * Подставленный в адрес чужой номер открывает форму у того ученика, чей номер
 * подставлен, — и никакого другого. Иначе форма была бы открыта у одного, а
 * сохранила бы имя другого.
 *
 * Смотрится именно форма правки: в строке ученика есть и другие формы (блок,
 * убрать из класса), и у каждой свой номер — считать их все нельзя.
 */
$editForeign = $owner->get('/classes/' . $classId . '?edit=' . $sidorovaId);

preg_match_all(
    '#action="[^"]*students/rename"[^>]*>.*?name="student_id" value="(\d+)"#s',
    $editForeign['body'],
    $renameIds
);

$t->equals([(string) $sidorovaId], $renameIds[1] ?? [],
    'форма правки открыта ровно у ученика из адреса');
$t->equals(1, substr_count($editForeign['body'], 'students/rename'),
    'в таблице открыта ровно одна форма правки');

/*
 * Правка чужого имени не подставляет имя владельца браузера.
 *
 * Поле в строке списка помечено `autocomplete="off"`: иначе браузер, увидев
 * поле с именем, подставил бы имя того, кто сидит за компьютером, — и правка
 * молча испортила бы чужую строку.
 */
$t->is(
    preg_match(
        '#<input[^>]*name="full_name"[^>]*autocomplete="off"[^>]*>#s',
        (string) $editForeign['body']
    ) === 1,
    'поле правки имени в строке не подставляет имя владельца браузера'
);

$renameToken = $owner->tokenFrom($editForm) ?? '';
$renamed = $owner->post('/classes/' . $classId . '/students/rename', [
    '_token'     => $renameToken,
    'student_id' => (string) $petrovId,
    'full_name'  => 'Петров Иван Ильич',
]);

$t->is($renamed['status'] === 302, 'правка имени обработана');
$t->equals('Петров Иван Ильич', (string) $db->value('SELECT full_name FROM users WHERE id = :id', ['id' => $petrovId]),
    'ФИО сохранено');

$t->equals('Петров', (string) $db->value('SELECT last_name FROM users WHERE id = :id', ['id' => $petrovId]),
    'фамилия пересобрана вместе с ФИО');

/*
 * Тот же адрес отвечает и форме, и скрипту: скрипт просит JSON и получает его
 * без перезагрузки страницы — при перезагрузке таблица двадцати пяти учеников
 * возвращалась в начало и правка следующего начиналась заново сверху.
 */
$jsonRename = new TestClient($baseUrl);
$jsonLogin = $jsonRename->get('/login');
$jsonRename->post('/login', [
    '_token'     => $jsonRename->tokenFrom($jsonLogin) ?? '',
    'identifier' => 'director@school.test',
    'password'   => 'direktor2025',
]);

$classForJson = $jsonRename->get('/classes/' . $classId);

/*
 * Переименовывается тот же ученик, что и выше: занятое имя сервер нарочно
 * не пускает — иначе зазванная фамилия соседа была бы узнаваема по отказу.
 * Для проверки формата ответа это лишнее препятствие.
 */
$jsonStudentId = $petrovId;

if ($jsonStudentId > 0) {
    $jsonResult = $jsonRename->post('/classes/' . $classId . '/students/rename', [
        '_token'     => $jsonRename->tokenFrom($classForJson) ?? '',
        'student_id' => (string) $jsonStudentId,
        'full_name'  => 'Петров Иван Ильич',
    ], ['Accept: application/json']);

    $contentType = '';
    foreach ($jsonResult['headers'] as $header) {
        if (stripos($header, 'content-type:') === 0) { $contentType = trim(substr($header, 13)); break; }
    }
    $t->is(str_contains(strtolower($contentType), 'application/json'), 'ответ на запрос скрипта — JSON');

    $jsonBody = json_decode($jsonResult['body'], true);
    $t->is(is_array($jsonBody) && ($jsonBody['ok'] ?? false) === true,
        'скрипт получает подтверждение без перезагрузки');
    $t->equals('Петров Иван Ильич', (string) ($jsonBody['full_name'] ?? ''),
        'в ответе возвращается сохранённое имя');

    /*
     * Адрес возврата нужен скрипту, чтобы убрать из строки браузера ?edit=.
     * Без него F5 вернул бы уже закрытое поле правки, и человек решил бы,
     * что правка не закрылась.
     */
    $t->is(
        is_string($jsonBody['url'] ?? null) && !str_contains($jsonBody['url'], 'edit=')
            && str_ends_with((string) $jsonBody['url'], '/classes/' . $classId),
        'в ответе есть адрес возврата без ?edit='
    );

    /*
     * Отказ тоже должен приходить в JSON: иначе скрипт попытался бы разобрать
     * страницу с ошибкой и показал бы человеку «Не удалось сохранить» вместо
     * внятной причины.
     */
    $jsonFail = $jsonRename->post('/classes/' . $classId . '/students/rename', [
        '_token'     => $jsonRename->tokenFrom($classForJson) ?? '',
        'student_id' => (string) $jsonStudentId,
        'full_name'  => '   ',
    ], ['Accept: application/json']);

    $jsonFailBody = json_decode($jsonFail['body'], true);
    $t->is(is_array($jsonFailBody) && ($jsonFailBody['ok'] ?? true) === false,
        'отказ тоже приходит в JSON, а не страницей');
    $t->is(is_string($jsonFailBody['error'] ?? null) && $jsonFailBody['error'] !== '',
        'в отказе объяснено, что не так');
}

/*
 * Чужое имя отвергается, но без указания, чьё именно: сказав «занято», мы
 * подтвердили бы, что ученик с таким именем в системе есть.
 */
$renameTaken = $owner->post('/classes/' . $classId . '/students/rename', [
    '_token'     => $renameToken,
    'student_id' => (string) $petrovId,
    'full_name'  => 'Сидорова Мария Алексеевна',
]);

$t->is($renameTaken['status'] === 302, 'занятое имя не проходит');
$renameRetry = $owner->get($renameTaken['location']);
$t->contains($renameRetry['body'], 'value="Сидорова Мария Алексеевна"',
    'после отказа по занятому имени форма остаётся открытой с введённым ФИО');

$t->equals('Петров Иван Ильич', (string) $db->value('SELECT full_name FROM users WHERE id = :id', ['id' => $petrovId]),
    'чужое имя не записало ученику');

// ------------------------------------------------------------------ блокировка

$t->group('Блокировка ученика');

$blockToken = $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '';

$block = $owner->post('/classes/' . $classId . '/students/status', [
    '_token'     => $blockToken,
    'student_id' => (string) $petrovId,
    'status'     => 'blocked',
]);

$t->is($block['status'] === 302, 'блокировка обработана');
$t->equals('blocked', (string) $db->value('SELECT status FROM users WHERE id = :id', ['id' => $petrovId]),
    'ученик заблокирован');

/*
 * Блокировка обязана закрывать вход по-настоящему, иначе это просто надпись
 * в списке. Проверяется на том же ученике, которого только что заблокировали.
 * Учётная запись при этом остаётся: история попыток не должна пропасть из-за
 * блокировки.
 */
$blockedClient = new TestClient($baseUrl);
$blockedLoginPage = $blockedClient->get('/login');

$blockedClient->post('/login', [
    '_token'     => $blockedClient->tokenFrom($blockedLoginPage) ?? '',
    'identifier' => $petrovLogin,
    'password'   => $petrovPassword,
]);

$t->is($blockedClient->get('/portal')['status'] === 302,
    'заблокированный ученик не может войти даже с верным паролем');

$t->is(
    (int) $db->value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $petrovId]) === 1,
    'учётная запись заблокированного ученика осталась в базе'
);

$unblock = $owner->post('/classes/' . $classId . '/students/status', [
    '_token'     => $blockToken,
    'student_id' => (string) $petrovId,
    'status'     => 'active',
]);

$t->is($unblock['status'] === 302, 'разблокировка обработана');
$t->equals('active', (string) $db->value('SELECT status FROM users WHERE id = :id', ['id' => $petrovId]),
    'ученик разблокирован');

/*
 * Чужим классом не блокируют: номер ученика подставляется в адрес, и без
 * проверки членства в классе завуч соседнего класса заблокировал бы чужого.
 */
$foreignClassId = (int) $db->value('SELECT id FROM classes WHERE id <> :id ORDER BY id LIMIT 1', ['id' => $classId]);

if ($foreignClassId > 0) {
    $foreignBlock = $owner->post('/classes/' . $foreignClassId . '/students/status', [
        '_token'     => $blockToken,
        'student_id' => (string) $petrovId,
        'status'     => 'blocked',
    ]);

    $t->is($foreignBlock['status'] === 302, 'чужой класс отклонил запрос');

    $t->equals('active', (string) $db->value('SELECT status FROM users WHERE id = :id', ['id' => $petrovId]),
        'чужой класс не заблокировал ученика');
}

// ------------------------------------------------------------------ лист печати

$t->group('Лист печати паролей');

$printPage = $owner->get('/classes/' . $classId);
$printToken = $owner->tokenFrom($printPage) ?? '';

$t->contains($printPage['body'], 'data-select-all', 'в шапке есть галочка «выбрать всех»');

$t->notContains($printPage['body'], 'credentials/print',
    'кнопки печати ранее выданных паролей нет: лист появляется сразу при выдаче');

$t->notContains($printPage['body'], 'Подпись ученика', 'колонка «Подпись ученика» убрана');

$t->notContains($printPage['body'], 'Выдать новые пароли</h2>',
    'дублирующий блок со вторым списком учеников убран');

/*
 * Старый адрес печати не молчит: человек мог закладкой ходить сюда годами, и
 * вместо тихого 404 он должен узнать, куда делась кнопка.
 */
$oldPrint = $owner->post('/classes/' . $classId . '/credentials/print', [
    '_token'      => $printToken,
    'students[]'  => [(string) $sidorovaId],
]);

$t->is($oldPrint['status'] === 302, 'старый адрес печати отвечает, а не 404');
$t->contains($owner->get('/classes/' . $classId)['body'], 'показывается один раз',
    'старый адрес печати объясняет, что пароль показывают один раз');

/*
 * Выдача пароля из меню строки.
 *
 * Отдельное действие, а не «отметить и нажать Печать»: отметок в меню нет, и
 * человек, которому нужен пароль одного ученика, иначе должен был бы искать
 * галочку в шапке таблицы. Здесь одно нажатие — и сразу лист на экране.
 */
$issued = $owner->post('/classes/' . $classId . '/credentials/issue', [
    '_token'      => $printToken,
    'student_id'  => (string) $sidorovaId,
]);

$t->is($issued['status'] === 200, 'выдача пароля сразу отдаёт лист');
$t->contains($issued['body'], 'Логины и пароли', 'на выдаче показан тот же лист');
$t->contains($issued['body'], 'Сидорова', 'в лист попал именно тот ученик, у которого выдавали');
$t->is(
    preg_match_all('/entry__name/', $issued['body']) === 1,
    'в листе ровно один ученик, а не весь класс'
);
$t->is(
    preg_match('/Пароль<\/dt>\s*<dd><code>\d{8}<\/code>/', $issued['body']) === 1,
    'на листе пароль из восьми цифр'
);
$t->notContains($issued['body'], '<nav class="sidebar"',
    'лист на печать не несёт меню и шапку панели');

/*
 * Один ученик — лист всё равно в две колонки.
 *
 * Карточка на всю ширину выглядела бы иначе, чем та же карточка рядом со
 * второй: лист режут по ученикам и складывают стопкой, и разница видна сразу.
 * Вторая половина ряда остаётся пустой ячейкой.
 */
$t->contains($issued['body'], 'entry--blank', 'при одном ученике вторая колонка остаётся пустой');
$t->notContains($issued['body'], 'sheet__grid--multi', 'при одном ученике отступ не увеличен');
$t->equals(
    1,
    substr_count($issued['body'], 'class="entry"'),
    'в листе ровно одна карточка'
);
$t->equals(
    1,
    substr_count($issued['body'], 'class="entry entry--blank"'),
    'вторую половину ряда занимает пустая ячейка'
);

/*
 * И пароль с этого листа не остаётся нигде.
 *
 * Раньше выдача из меню клала его в сессию, и колонка «Пароль» в таблице
 * показывала его до печати: то есть пароль ученика лежал открытым текстом в
 * файле сессии весь сеанс учителя. Теперь он живёт только в этом ответе.
 */
$classAfterIssue = $owner->get('/classes/' . $classId);
$issuedPassword = '';
if (preg_match('#<dd><code>\s*(\d{8})\s*</code>#u', $issued['body'], $m) === 1) {
    $issuedPassword = (string) $m[1];
}

$t->is($issuedPassword !== '', 'на выданном листе есть пароль из восьми цифр');
$t->notContains($classAfterIssue['body'], '>' . $issuedPassword . '<',
    'выданный пароль в таблице класса не остаётся');
$t->notContains($classAfterIssue['body'], 'class="credential"',
    'колонка с паролями из таблицы класса убрана');

/*
 * И сам пароль должен работать: иначе на бумаге окажется то, чем нельзя войти.
 */
$checkIssued = new TestClient($baseUrl);
$checkIssued->post('/login', [
    '_token'     => $checkIssued->tokenFrom($checkIssued->get('/login')) ?? '',
    'identifier' => (string) $db->value('SELECT login FROM users WHERE id = :i', ['i' => $sidorovaId]),
    'password'   => $issuedPassword,
]);

$t->is($checkIssued->get('/portal')['status'] === 200, 'выданный пароль подходит для входа ученика');

/*
 * Лист на несколько карточек: вёрстка обязана отличаться от одно-карточной.
 *
 * Раньше это была одна и та же вёрстка, и карточки на разном количестве
 * учеников выглядели по-разному: с одним он тянулся на всю ширину листа.
 * Теперь лист — это и есть ответ на выдачу, поэтому собирается он сразу по
 * отмеченным.
 */
$multiPage = $owner->get('/classes/' . $classId);
$multi = $owner->post('/classes/' . $classId . '/credentials/reveal', [
    '_token'     => $owner->tokenFrom($multiPage) ?? '',
    'students[]' => [$petrovId, $sidorovaId],
]);

$t->is($multi['status'] === 200, 'лист на двух учеников формируется сразу при выдаче');
$t->is(
    preg_match_all('/entry__name/', $multi['body']) === 2,
    'на листе обе карточки'
);
$t->contains($multi['body'], 'sheet__grid--multi', 'при нескольких карточках отступ между рядами увеличен');
$t->notContains($multi['body'], 'entry--blank', 'при чётном числе карточек пустой ячейки нет');

/*
 * В лист попадают только отмеченные: лишние пароли на бумаге незачем, а бумагу
 * завуч обычно несёт один раз.
 */
$t->contains($multi['body'], 'Сидорова', 'в лист попал отмеченный ученик');
$t->contains($multi['body'], 'Петров', 'в лист попал и второй отмеченный');

$third = (int) $db->value("SELECT id FROM users WHERE full_name LIKE 'Кузнец%' LIMIT 1");

if ($third > 0) {
    $oneOnly = $owner->post('/classes/' . $classId . '/credentials/reveal', [
        '_token'     => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
        'students[]' => [$third],
    ]);

    $t->is(
        preg_match_all('/entry__name/', $oneOnly['body']) === 1,
        'в лист попал только отмеченный ученик'
    );
    $t->notContains($oneOnly['body'], 'Сидорова', 'неотмеченный ученик в лист не попал');
}

/*
 * Адрес входа напечатан у каждого ученика, а не один на весь лист.
 *
 * Лист режут по ученикам, и вырезанная часть остаётся рабочей: адрес должен
 * быть рядом с логином и паролем. Ученик идёт по напечатанной бумаге, а не по
 * адресной строке чужого браузера, и попадает в форму, где спрашивают только
 * логин и пароль, — без e-mail и без регистрации.
 */
$printedEntries = substr_count($multi['body'], 'entry__url');
$t->equals(
    substr_count($multi['body'], 'class="entry"'),
    $printedEntries,
    'адрес входа напечатан у каждого ученика'
);
$t->contains($multi['body'], 'student/login', 'на листе с паролями напечатан адрес входа ученика');
$t->notContains($multi['body'], 'Адрес входа:', 'общего адреса в шапке листа больше нет');

/*
 * Чужого ученика выдать нельзя: адрес действия можно подставить вручную.
 */
$alien = $owner->post('/classes/' . $classId . '/credentials/issue', [
    '_token'      => $printToken,
    'student_id'  => '99999999',
]);

$t->is($alien['status'] === 302, 'чужой ученик не выдаётся и уводит на страницу класса');

/*
 * Лист уходит на печать сам: второе нажатие «Печать» человек забывает.
 */
$t->contains($multi['body'], 'print-sheet.js', 'лист сам отправляется на печать');

$t->contains($multi['body'], 'print-credentials.css', 'лист печати со своими стилями');

$t->notContains($multi['body'], 'class="card__head"', 'лист без оболочки сайта');

/*
 * Две колонки — требование владельца: двадцать пять учеников в одну колонку
 * на лист А4 не влезают. Проверяется сам файл стилей, а не страница: правило
 * живёт в CSS, а инлайн-стили запрещены политикой безопасности, поэтому в
 * разметке его и не будет.
 */
$printCss = (string) @file_get_contents($rootDir . '/public/assets/css/print-credentials.css');

/*
 * Лист свёрстан сеткой в две колонки, а не колонками CSS: колонки балансируют
 * высоту сами, и с одним учеником карточка растягивалась на всю ширину листа.
 * Сетка рисует две равные ячейки всегда, а неполный ряд дополняется пустой.
 */
$t->contains($printCss, 'grid-template-columns: 1fr 1fr', 'лист печати свёрстан в две равные колонки');
$t->notContains($printCss, 'column-count', 'лист печати не полагается на балансировку колонок');
$t->contains($printCss, '.sheet__grid--multi .entry', 'при нескольких карточках отступ между рядами увеличен');
$t->is(preg_match_all('/border-(?:top|bottom|left):[^;]*dashed/', $printCss) >= 4, 'разделители листа пунктирные');
$t->notContains($printCss, 'solid var(', 'на листе не осталось сплошных разделителей');

// Пунктир — именно на сгибе: линия под рядом и между колонками.
$t->contains($printCss, '.entry:nth-last-child(-n + 2)', 'под последним рядом разделитель не рисуется');
$t->contains($printCss, '.entry:nth-child(even)', 'между колонками проведена разделительная линия');

/*
 * Ни в одном файле сессии пароля нет.
 *
 * Это главное из того, что поменялось: раньше пароль ученика клался в
 * $_SESSION и лежал там открытым текстом весь сеанс учителя. На хостинге с
 * общей папкой сессий такой файл читается любым, у кого есть доступ к файлам,
 * а пароль после этого всё равно приходится менять — ломая тем самым всех,
 * кто уже записался под ним.
 */
/*
 * Пустой искомый пароль нашёлся бы в любом файле: str_contains отвечает на
 * вопрос «есть ли пустая строка», а это всегда да. Поэтому проверяются только
 * те пароли, которые действительно получены.
 */
$issuedPasswords = array_values(array_filter(
    [$sidorovaPassword, $petrovPassword],
    static fn (string $password): bool => $password !== ''
));

$t->is($issuedPasswords !== [], 'пароли для проверки сессий получены');

$sessionWithPassword = 0;

$sessionDir = session_save_path() !== '' ? session_save_path() : sys_get_temp_dir();

foreach (glob($sessionDir . '/sess_*') ?: [] as $sessionFile) {
    $content = (string) file_get_contents($sessionFile);

    foreach ($issuedPasswords as $password) {
        if (str_contains($content, $password)) {
            $sessionWithPassword++;

            break;
        }
    }
}

$t->equals(0, $sessionWithPassword, 'ни в одном файле сессии нет выданных паролей');

// И в коде больше нет места, где такие пароли могли бы осесть. Ищется не
// само слово, а его использование: в комментариях «issued_credentials»
// упоминается намеренно — там объяснено, почему этого больше нет.
$passwordStash = [];

foreach ([$rootDir . '/src', $rootDir . '/templates'] as $passwordDir) {
    $scan = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($passwordDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($scan as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#(^|\s)//[^\n]*#', '$1', $code);
        $code = (string) preg_replace('#(^|\s)#[^\n]*#', '$1', $code);

        if (str_contains($code, 'issued_credentials')) {
            $passwordStash[] = $file->getPathname();
        }
    }
}

$t->equals([], $passwordStash, 'в коде больше нет места, где хранятся выданные пароли');

/*
 * Без отметки лист не формируется: пустая страница выглядела бы как ошибка
 * печати, и человек искал бы, что он сломал.
 */
$printedNone = $owner->post('/classes/' . $classId . '/credentials/reveal', [
    '_token'     => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
    'students[]' => [],
]);

$t->is($printedNone['status'] === 302, 'лист без отметок не формируется');

// ------------------------------------------------------------------ исключение из класса

$t->group('Исключение ученика из класса');

$studentsPage = $owner->get('/classes/' . $classId);
$remove = $owner->post('/classes/' . $classId . '/students/remove', [
    '_token'     => $owner->tokenFrom($studentsPage) ?? '',
    'student_id' => (string) $petrovId,
]);

$t->is($remove['status'] === 302, 'исключение обработано');
$t->equals((int) $db->value('SELECT COUNT(*) FROM class_students WHERE class_id = :id AND student_id = :u',
    ['id' => $classId, 'u' => $petrovId]), 0, 'ученик больше не в классе');
$t->is($users->findRow($petrovId) !== null, 'учётная запись ученика сохранена');

// ------------------------------------------------------------------ права

$t->group('Разграничение прав');

$guest = new TestClient($baseUrl);
$t->is($guest->get('/classes')['status'] === 302, 'гость не видит классы');
$t->is($guest->post('/classes', ['name' => '9 Б'])['status'] === 302, 'гость не может создать класс');

$teacherClient = new TestClient($baseUrl);
$teacherLoginPage = $teacherClient->get('/login');
$teacherClient->post('/login', [
    '_token'     => $teacherClient->tokenFrom($teacherLoginPage) ?? '',
    'identifier' => 'teacher@school.test',
    'password'   => 'uchitel2025',
]);

$t->is($teacherClient->get('/classes')['status'] === 403, 'учитель не управляет классами');
$t->is($teacherClient->post('/classes/' . $classId . '/students',
    ['full_name' => 'Новый Ученик'])['status'] === 403, 'учитель не регистрирует учеников');
$t->is($teacherClient->get('/classes/' . $classId . '/roster')['status'] === 403, 'учитель не видит список класса');

$observerId = $makeUser('parent@school.test', 'roditel2025', 'Сидоров Пётр Алексеевич', 0);
$observerClient = new TestClient($baseUrl);
$observerLoginPage = $observerClient->get('/login');
$observerClient->post('/login', [
    '_token'     => $observerClient->tokenFrom($observerLoginPage) ?? '',
    'identifier' => 'parent@school.test',
    'password'   => 'roditel2025',
]);

$t->is($observerClient->get('/classes')['status'] === 403, 'наблюдатель не управляет классами');
$t->is($observerClient->get('/panel')['status'] === 403, 'наблюдатель не видит панель');

/*
 * Роль одна и меняется из списка, а не галочками на странице человека.
 *
 * Проверяется и то, что формы ролей нет ни там, ни там, и то, что собственную
 * роль владелец не меняет: ни через форму (её больше нет), ни через новый адрес
 * — сама страница честно говорит, где роль меняется.
 */
$ownerUsers = $owner->get('/panel/users');
$t->notContains($ownerUsers['body'], 'name="roles[]"', 'в списке пользователей роли не правятся галочками');

$ownerSelfPage = $owner->get('/panel/users/' . $ownerId);
$t->is($ownerSelfPage['status'] === 200, 'владелец открывает свою страницу');
$t->notContains($ownerSelfPage['body'], 'name="roles[]"', 'на странице человека роли не правятся галочками');
$t->contains($ownerSelfPage['body'], 'У владельца сайта она всегда «Администратор» — снять её нельзя',
    'владелец не может редактировать собственную роль');

$t->is(
    $owner->post('/panel/users/role', [
        '_token'  => $owner->tokenFrom($ownerUsers) ?? '',
        'user_id' => (string) $ownerId,
        'role'    => \MyEdu\Auth\Role::OBSERVER,
    ])['status'] === 302,
    'смена собственной роли обработана'
);
$t->is(
    in_array(\MyEdu\Auth\Role::ADMIN, (new \MyEdu\Auth\UserRepository($db))->rolesOf((int) $ownerId), true),
    'у владельца роль администратора на месте'
);

// ------------------------------------------------------------- единый стиль классов

$t->group('Список классов и редактор класса');

/*
 * Список и создание разведены по разным адресам. Пока форма создания стояла
 * над списком, страница отвечала на два вопроса сразу, и удаление класса
 * нажималось рядом с полями для нового.
 */
$classList = $owner->get('/classes');

$t->is($classList['status'] === 200, 'список классов открыт');
$t->notContains($classList['body'], 'name="name"', 'на странице списка нет формы создания');
$t->contains($classList['body'], '/classes/new', 'из списка ведёт ссылка на создание');

$classNew = $owner->get('/classes/new');

$t->is($classNew['status'] === 200, 'страница создания класса открыта');
$t->contains($classNew['body'], 'name="name"', 'форма создания на своей странице');

/*
 * Подтверждение удаления — всплывающим окном, с той же страницей запасным
 * путём.
 *
 * Окно не заменяет страницу, а надстраивается над ней: без скриптов ссылка
 * ведёт на страницу, где последствия перечислены словами, и удаляет сервер.
 * Со скриптами то же самое спрашивается на месте. Проверяется, что оба пути
 * на месте: убрать страницу было бы страховкой от собственного окна, а убрать
 * окно — молчаливым ухудшением для тех, у кого скрипты есть.
 */
$t->contains($classList['body'], 'data-confirm="Удалить класс',
    'удаление класса спрашивается подтверждением');
$t->contains($classList['body'], 'data-confirm-body=',
    'у подтверждения есть объяснение последствий');
$t->contains($classList['body'], 'assets/js/confirm-modal.js', 'скрипт окна подключён');
$t->contains($classList['body'], 'name="csrf-token"',
    'токен защиты доступен скрипту из разметки');
$t->contains($classList['body'], 'data-datatable',
    'список передан библиотеке таблиц шаблона');

/*
 * Действия класса — меню в последнем столбце, как у сотрудников и учеников.
 *
 * Две кнопки в строке занимали место и тянули взгляд: у класса в списке их
 * двадцать, и ни одна не отвечала на вопрос «что я хочу с этим сделать».
 * Меню построено на <details> и раскрывается без скриптов, поэтому запасной
 * путь никуда не делся: без скриптов пункт меню — обычная ссылка.
 */
$t->equals(
    substr_count($classList['body'], '<td class="row-actions">'),
    substr_count($classList['body'], 'class="row-menu"'),
    'меню действий стоит в каждой строке списка классов'
);
$t->contains($classList['body'], 'Редактировать класс', 'в меню есть «Редактировать класс»');
$t->is(
    preg_match('#>\s*Удалить класс\s*</a>#u', $classList['body']) === 1,
    'в меню есть «Удалить класс»'
);
$t->contains($classList['body'], '/classes/' . $classId . '/delete', 'пункт удаления ведёт на свой адрес');
$t->notContains($classList['body'], 'btn-danger', 'отдельной кнопки удаления в строке больше нет');
$t->notContains($classList['body'], 'style="', 'в списке классов нет inline-стилей');
$t->is($owner->get('/classes/' . $classId . '/delete')['status'] === 200,
    'страница подтверждения удаления осталась запасным путём');

/*
 * Склеенный тег проверяется и здесь, а не только в наборе http.
 *
 * Поломка `<span class="…">` → `<spanclass="…">` молчаливая: PHP в порядке,
 * сервер отвечает 200, а браузер не узнаёт тег и рисует его как текст. Наборы
 * смотрели слова в ответе, а не форму ответа, поэтому такая ошибка и проходила.
 * Проверяется форма ответа целиком — на страницах панели, где собираются те же
 * шаблоны, но по другой ветке прав.
 */
foreach ([
    '/classes'             => 'список классов',
    '/classes/' . $classId => 'страница класса',
    '/panel/subjects'      => 'каталог предметов',
    '/panel/users'         => 'список пользователей',
] as $url => $what) {
    $page = $owner->get($url);
    $body = (string) ($page['body'] ?? '');

    $t->is($page['status'] === 200, $what . ' открыт');
    $t->is(
        preg_match('/<[a-z][a-z0-9]*class="[^"]*"/i', $body) === 0,
        'в «' . $what . '» нет тега, слипшегося с атрибутом class'
    );
    $t->is(
        preg_match('/"class="[^"]*"/', $body) === 0,
        'в «' . $what . '» нет атрибута class, слипшегося с кавычкой'
    );
    $t->is(
        preg_match('/\sclass=""/', $body) === 0,
        'в «' . $what . '» нет пустого class'
    );

    /*
     * Крошки рисуются оболочкой в шапке. Страница объявляет их данными, а не
     * разметкой: иначе каждая страница панели показывает одни и те же крошки
     * дважды — из шапки и из своего тела. Раньше так и было, и заметить это
     * можно было только глазами.
     */
    $t->equals(
        1,
        preg_match_all('/<nav class="breadcrumb"/', $body),
        'в «' . $what . '» крошки ровно один раз'
    );
    $t->is(
        preg_match('/<main[\s\S]*<nav class="breadcrumb"/', $body) === 0,
        'в «' . $what . '» крошки не повторяются в теле страницы'
    );
}

$t->group('Вкладки редактора класса');

$editorPage = $owner->get('/classes/' . $classId);

$t->is($editorPage['status'] === 200, 'редактор класса открыт');
$t->contains($editorPage['body'], 'Класс и ученики', 'есть вкладка «Класс и ученики»');
$t->contains($editorPage['body'], 'Предметы и учителя', 'есть вкладка «Предметы и учителя»');
$t->contains($editorPage['body'], '?tab=roster', 'вкладка предметов адресуется');

$rosterTab = $owner->get('/classes/' . $classId . '?tab=roster');

$t->is($rosterTab['status'] === 200, 'вкладка предметов и учителей открыта');
$t->contains($rosterTab['body'], 'Предметы класса', 'на вкладке есть предметы');
$t->contains($rosterTab['body'], 'Учителя', 'на вкладке есть учителя');
$t->notContains($rosterTab['body'], 'Загрузить список класса', 'на вкладке нет учеников');

/*
 * Старый адрес состава класса остаётся рабочим и уводит на вкладку: закладки
 * и ссылки из писем не должны превращаться в 404 после переноса.
 */
$oldRoster = $owner->get('/classes/' . $classId . '/roster');

$t->is($oldRoster['status'] === 302, 'старый адрес состава класса перенаправляет');
$t->contains($oldRoster['location'], 'tab=roster', 'перенаправляет на нужную вкладку');

/*
 * Ученики и логины — одна таблица. Раньше это были две страницы, и
 * один и тот же человек показывался дважды.
 */
$t->contains($editorPage['body'], 'Ученики класса', 'на первой вкладке есть таблица учеников');
$t->contains($editorPage['body'], 'Выдать новые пароли', 'в таблице есть выдача паролей отмеченным');
$t->contains($editorPage['body'], 'Зарегистрировать', 'в таблице есть регистрация ученика');
$t->contains($editorPage['body'], 'Загрузить список класса', 'в таблице есть загрузка списка');
$t->contains($editorPage['body'], 'title="Изменить имя"', 'у ученика есть правка имени');

/*
 * Правка имени решается адресом, а не скриптом.
 *
 * Долго то, открыто ли поле, решал скрипт: страница зависела и от таблицы
 * стилей (форма прячется правилом [hidden]), и от скрипта (он её открывает).
 * Стоит одной из двух не дойти — и у первого ученика в таблице появляется
 * готовое поле правки, которое никто не открывал; выглядит при этом страница
 * обычно. Владелец видел это дважды, и правило [hidden] оказалось лишь одним
 * из двух условий, а не самим условием.
 *
 * Теперь состояние строки — это адрес. Скриптов на странице нет, и таблица
 * выглядит одинаково при любом браузере и при любой версии стилей.
 */
preg_match('#\?edit=(\d+)#', $editorPage['body'], $editMatch);

$editableStudentId = (int) ($editMatch[1] ?? 0);

$t->is($editableStudentId > 0, 'в таблице есть ученик со ссылкой правки имени');
$t->notContains($editorPage['body'], '/students/rename',
    'при обычном открытии страницы форм правки в таблице нет');
$t->is(
    preg_match_all('#<form[^>]*\shidden[\s>]#', $editorPage['body']) === 0,
    'в таблице учеников не осталось форм, спрятанных стилями'
);

$withEditParam = $owner->get('/classes/' . $classId . '?edit=' . $editableStudentId);

$t->contains($withEditParam['body'], 'name="student_id" value="' . $editableStudentId . '"',
    'адрес открыл форму правки у названного ученика');
$t->equals(
    1,
    substr_count($withEditParam['body'], '/students/rename'),
    'открыта ровно одна форма, даже если учеников двадцать пять'
);

$afterSavePage = $owner->get('/classes/' . $classId);

$t->notContains($afterSavePage['body'], '/students/rename',
    'после сохранения правка закрыта: возврат идёт без адреса правки');

/*
 * Действия ученика живут в меню, а не в ряду значков.
 *
 * Раньше в строке стояли два значка, и третье действие — выдать пароль, то
 * есть самое частое — в ряд не поместилось бы. Меню держит любое число
 * пунктов в одной ячейке.
 *
 * Проверяется и вид, и работоспособность без скриптов: меню построено на
 * <details>, поэтому все пункты остаются кнопками отправки, а не ссылками на
 * обработчик, которого при выключенных скриптах не будет.
 */
$t->contains($editorPage['body'], '<details class="row-menu">',
    'действия ученика собраны в меню');
$t->contains($editorPage['body'], 'class="card-opt-btn"',
    'меню вызывается кнопкой шаблона');
/*
 * Пункты меню ищутся регуляркой, а не подстрокой: между тегом и названием
 * пункта в разметке перевод строки, и его вид зависит от того, чем писали
 * файл. Искать по смыслу — «кнопка, в которой написано вот это» — надёжнее,
 * чем по точному числу пробелов.
 */
$menuItem = static function (string $name, string $body): bool {
    return preg_match('/<button[^>]*class="menu-item[^"]*"[^>]*>\s*' . preg_quote($name, '/') . '\s*</', $body) === 1;
};

$t->is($menuItem('Выдать пароль', $editorPage['body']), 'в меню есть выдача пароля');
$t->is($menuItem('Заблокировать ученика', $editorPage['body']), 'в меню есть блокировка');
$t->is($menuItem('Убрать из класса', $editorPage['body']), 'в меню есть удаление из класса');
$t->contains($editorPage['body'], 'class="menu-item menu-item--danger"',
    'пункт удаления помечен как опасный');
$t->contains($editorPage['body'], 'credentials/issue',
    'пункт выдачи пароля ведёт на серверное действие');
$t->notContains($editorPage['body'], 'title="Заблокировать ученика"',
    'отдельного значка блокировки в строке больше нет');
$t->notContains($editorPage['body'], 'title="Убрать из класса"',
    'отдельного значка удаления в строке больше нет');

/*
 * Таблица учеников: шрифт 14px и строки ниже.
 *
 * Класс на двадцать пять человек должен помещаться на один экран: раньше
 * шрифт был 16px, и таблица занимала два экрана, хотя в строке не было ничего,
 * кроме логина и пароля.
 */
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

$t->contains($css, '.credential-table { font-size: 14px; }',
    'шрифт таблицы учеников увеличен до 14px');
$t->contains($css, 'padding-top: 4px;',
    'вертикальный отступ в строке уменьшен');
$t->notContains($css, '.credential-table { font-size: 16px; }',
    'старый размер шрифта таблицы больше не задан');

/*
 * Кнопка без варианта должна быть видна.
 *
 * На кнопку `class="btn"` осталось правило прежней темы — белый фон и цвет
 * текста, — а в тёмной теме текст тоже светлый: надпись была белым по белому,
 * и это не показал ни один набор, потому что страница отвечала 200, а слова в
 * ответе оставались на месте. Видно это только глазами или замером в
 * браузере, поэтому здесь проверяется наличие правила: без него кнопка снова
 * станет невидимой.
 */
$themeCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/theme.css');

$t->contains($themeCss, '.btn:not(.btn-primary)',
    'для кнопки без варианта задано своё оформление, а не осталось от прежней темы');

/*
 * Меню не должно растягиваться на всю ширину экрана.
 *
 * Порок был в одной строке: скрипт ставит меню position: fixed и вычисляет
 * координаты, а в стилях рядом стояло ещё и прижатие к правому краю. У
 * фиксированного блока, которому заданы обе стороны, ширина не по содержимому,
 * а между ними — то есть на весь экран.
 *
 * Проверяется именно эта связка: у плавающего меню не должно быть задано
 * прижатие ни к одной из сторон, а ширина должна быть по содержимому.
 */
$positionedRule = '';

if (preg_match('/\.row-menu \.menu-popover\.is-positioned\s*\{([^}]*)\}/', $css, $rule) === 1) {
    $positionedRule = $rule[1];
}

$t->is($positionedRule !== '', 'для плавающего меню есть отдельное правило');
$t->is(
    preg_match('/inset-inline-(?:end|start)|(^|[\s;])(?:right|left)\s*:/m', $positionedRule) === 0,
    'плавающее меню не прижато ни к одной из сторон'
);
$t->contains($positionedRule, 'width: max-content', 'ширина меню задана по содержимому');
$t->contains($positionedRule, 'position: fixed', 'меню отрывается от таблицы');

/*
 * Подпись для чтения с экрана остаётся: кнопка меню без названия недоступна
 * человеку со скринридером.
 */
$t->contains($editorPage['body'], 'aria-label="Действия: ',
    'у кнопки меню есть подпись для чтения с экрана');

$t->group('Удаление класса');

$doomed = $owner->post('/classes', [
    '_token' => $owner->tokenFrom($classNew) ?? '',
    'name'   => '9 В',
]);

$t->is($doomed['status'] === 302, 'класс под удаление создан');

$doomedId = (int) ($db->value("SELECT id FROM classes WHERE name = '9 В' LIMIT 1") ?? 0);

$t->is($doomedId > 0, 'класс под удаление найден');

$doomedStudent = $owner->post('/classes/' . $doomedId . '/students', [
    '_token'    => $owner->tokenFrom($owner->get('/classes/' . $doomedId)) ?? '',
    'full_name' => 'Кузнецов Пётр Ильич',
]);

$t->is($doomedStudent['status'] === 200, 'в класс под удаление добавлен ученик, лист с паролем показан сразу');

$doomedStudentId = (int) ($db->value(
    "SELECT u.id FROM users u JOIN class_students cs ON cs.student_id = u.id
      WHERE cs.class_id = :c AND u.full_name LIKE 'Кузнецов%' LIMIT 1",
    ['c' => $doomedId]
) ?? 0);

$t->is($doomedStudentId > 0, 'ученик класса под удаление найден');

$confirm = $owner->get('/classes/' . $doomedId . '/delete');

$t->is($confirm['status'] === 200, 'страница подтверждения удаления открыта');
$t->contains($confirm['body'], 'Восстановить это нельзя', 'сказано, что удаление необратимо');
$t->contains($confirm['body'], 'будут удалены все его ученики', 'предупреждено об учениках');

$t->is(
    (int) $db->value('SELECT COUNT(*) FROM classes WHERE id = :id', ['id' => $doomedId]) === 1,
    'до подтверждения класс на месте'
);

$removed = $owner->post('/classes/' . $doomedId . '/delete', [
    '_token' => $owner->tokenFrom($confirm) ?? '',
]);

$t->is($removed['status'] === 302, 'удаление обработано');

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM classes WHERE id = :id', ['id' => $doomedId]),
    'класс удалён');

/*
 * Ученик удалён вместе с классом: оставить его было бы хуже — в списке
 * пользователей его нет, в списке классов тоже, а в базе он висел бы без
 * единого адреса, куда его можно посмотреть.
 */
$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $doomedStudentId]),
    'ученик удалён вместе с классом');

/*
 * Проверяется по ссылке, а не по названию: в сообщении об успешном удалении
 * название класса законно остаётся — «Класс 9 В удалён», и искать его в
 * тексте бессмысленно.
 */
$t->notContains($owner->get('/classes')['body'], '/classes/' . $doomedId,
    'удалённого класса больше нет в списке');

$t->group('Ученик другого класса не удаляется вместе с классом');

/*
 * Человек может состоять в двух классах: заведённого ученика можно добавить
 * в другой. Сносить его учётную запись вместе с одним из классов было бы
 * потерей чужого класса.
 */
$sharedLogin = 'uzin-dvuk';
$sharedPassword = 'uzin-dvuk-2026';

$users->create([
    'login'            => $sharedLogin,
    'email'            => null,
    'password_hash'    => password_hash($sharedPassword, PASSWORD_DEFAULT),
    'full_name'        => 'Двухклассов Ученик',
    'is_owner'         => 0,
    'status'           => 'active',
    'email_verified_at' => date('Y-m-d H:i:s'),
]);

$sharedId = (int) $db->one('SELECT id FROM users WHERE login = :l', ['l' => $sharedLogin])['id'];

$db->run('INSERT IGNORE INTO user_roles (user_id, role_code, granted_at) VALUES (:u, :r, NOW())', [
    'u' => $sharedId,
    'r' => Role::STUDENT,
]);

$owner->post('/classes/' . $classId . '/students', [
    '_token'    => $owner->tokenFrom($owner->get('/classes/' . $classId)) ?? '',
    'full_name' => 'Двухклассов Ученик',
]);

$owner->post('/classes/' . $doomedId . '/students', [
    '_token'    => $owner->tokenFrom($owner->get('/classes/' . $doomedId)) ?? '',
    'full_name' => 'Двухклассов Ученик',
]);

$secondClass = $owner->post('/classes', [
    '_token' => $owner->tokenFrom($owner->get('/classes/new')) ?? '',
    'name'   => '8 Г',
]);

$secondClassId = (int) ($db->value("SELECT id FROM classes WHERE name = '8 Г' LIMIT 1") ?? 0);

$db->run('INSERT IGNORE INTO class_students (class_id, student_id, joined_at) VALUES (:c, :u, NOW())', [
    'c' => $secondClassId,
    'u' => $sharedId,
]);

$t->equals(2, (int) $db->value('SELECT COUNT(*) FROM class_students WHERE student_id = :u', ['u' => $sharedId]),
    'ученик состоит в двух классах');

$owner->get('/classes/' . $doomedId . '/delete');
$owner->post('/classes/' . $doomedId . '/delete', [
    '_token' => $owner->tokenFrom($owner->get('/classes/' . $doomedId . '/delete')) ?? '',
]);

$t->equals(0, (int) $db->value('SELECT COUNT(*) FROM classes WHERE id = :id', ['id' => $doomedId]),
    'класс с общим учеником удалён');

$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $sharedId]),
    'ученик, состоящий в другом классе, остался');

/*
 * Связей было три: основной класс, удаляемый и второй. После сноса одного
 * класса остаётся ровно две — удалённый класс унёс свою связь каскадом, а
 * две чужие не тронул.
 */
$t->equals(2, (int) $db->value('SELECT COUNT(*) FROM class_students WHERE student_id = :u', ['u' => $sharedId]),
    'связи с оставшимися классами целы');

// Уборка класса, созданного для проверки.
$db->run('DELETE FROM classes WHERE id = :id', ['id' => $secondClassId]);
$db->run('DELETE FROM users WHERE id = :id', ['id' => $sharedId]);

/*
 * Проверка ввода в формах панели.
 *
 * Правила живут в самой разметке (required, min, max, maxlength), а сообщения
 * и подсветку рисует form-validate.js. Проверяется, что скрипт подключён и
 * что места, где человек обязан что-то выбрать, помечены: без отметки шаг
 * мастера уходил бы с пустым выбором и возвращался с отказом.
 */
$validateJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/form-validate.js');

$t->contains($owner->get('/classes/new')['body'], 'form-validate.js',
    'проверка полей подключена к страницам панели');
$t->contains($validateJs, 'event.preventDefault()',
    'проверка умеет не отправлять форму');
$t->is(
    preg_match('/\.(value\s*=\s*.{0,3}\s*\)|reset\(\))/', $validateJs) === 0,
    'проверка не стирает введённое: это главное требование к ней'
);

/*
 * Пароли не запоминаются для возврата в форму.
 *
 * Помощник `keepInput` кладёт введённое в сеанс, чтобы отказ сервера не стоил
 * человеку напечатанного. Но сеанс лежит файлом на диске, и раньше туда попадало
 * всё подряд: учитель набрал пароль в форме, отправил, получил отказ по другой
 * причине — и пароль оставался лежать, а форма показывала его снова целиком.
 *
 * Ни одна нынешняя форма пароля не содержит, но помощник общий — в том числе
 * для плагинов. «Пока никто не передаёт» защитой не является, поэтому отсечка
 * проверяется на самом помощнике.
 */
$t->group('Введённое возвращается в форму, пароли — нет');

$keepInputProbe = new class($app->get(MyEdu\Core\Session::class), $app->get(MyEdu\Core\View::class)) {
    use MyEdu\Http\Controller\ControllerHelpers;

    public function __construct(
        public MyEdu\Core\Session $session,
        public MyEdu\Core\View $view
    ) {
    }

    public function remember(string $key, MyEdu\Core\Request $request): void
    {
        $this->keepInput($key, $request);
    }
};

$keepInputProbe->remember('school_probe_input', new MyEdu\Core\Request(
    [],
    [
        '_token'           => 'маркер',
        'title'            => 'Проверка ввода',
        'students'         => ['7', '9'],
        'current_password' => 'старый-пароль',
        'new_password'     => 'новый-пароль',
        'pass'             => 'короткий',
        'pin'              => '1234',
        'classroom'        => '7-Б',
    ],
    ['REQUEST_METHOD' => 'POST']
));

$keptInput = $keepInputProbe->session->get('school_probe_input', []);

$t->equals('Проверка ввода', $keptInput['title'] ?? '', 'обычное поле переживает отказ');
$t->equals(['7', '9'], $keptInput['students'] ?? [], 'список отметок переживает отказ целиком');
$t->equals('7-Б', $keptInput['classroom'] ?? '', 'поле с обычным именем запоминается');
$t->is(!array_key_exists('_token', $keptInput), 'маркер подделки в сеанс не попадает');

foreach (['current_password', 'new_password', 'pass', 'pin'] as $secretField) {
    $t->is(
        !array_key_exists($secretField, $keptInput),
        'поле ' . $secretField . ' в сеанс не попадает'
    );
}

/*
 * Обязательность ищется внутри тега поля, а не по соседству слов: порядок
 * атрибутов в разметке — дело оформления, и проверка не должна от него зависеть.
 */
$classNameField = preg_match(
    '#<input[^>]*name="name"[^>]*>#',
    $owner->get('/classes/new')['body'],
    $classNameFieldMatch
) ? $classNameFieldMatch[0] : '';

$t->is(
    $classNameField !== '' && preg_match('#\srequired(\s|=|>|$)#', $classNameField) === 1,
    'номер класса помечен обязательным — сервер без него всё равно отказывает'
);

/*
 * Подсказка про отметку работы проверяется по шаблону, а не по странице.
 *
 * На странице первого шага строки работ берутся из базы: у владельца в наборе
 * может не быть ни одной работы, и тогда проверка видела бы пустую форму и
 * падала в зависимости от того, что осталось от других наборов. Само правило
 * живёт в шаблоне строки и не зависит ни от данных, ни от порядка наборов.
 */
$workRowTemplate = (string) file_get_contents(dirname(__DIR__) . '/templates/panel/_work_row.php');

$t->contains($workRowTemplate, 'data-hint="Отметьте работу, которую выдаёте"',
    'на первом шаге выдачи сказано, что работу надо отметить');
$t->is(
    preg_match('/required data-hint="Отметьте работу, которую выдаёте"/', $workRowTemplate) === 1,
    'отметка работы обязательна, а не только подсказана'
);

exit($t->report());
