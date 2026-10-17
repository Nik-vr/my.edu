<?php

declare(strict_types=1);

/**
 * Создание учётной записи сотрудника из командной строки.
 *
 * Нужна, когда надо завести пользователя сразу с готовым паролем и ролями:
 * через форму регистрации он получит роль «Наблюдатель» и не сможет войти
 * без письма, а роль всё равно пришлось бы назначать вручную.
 *
 * Запуск:
 *   php database/create_user.php <адрес> "Фамилия Имя Отчество" <роли>
 *   php database/create_user.php uchet@example.edu "Иванов Иван Иванович" teacher
 *   php database/create_user.php admin@example.edu "Петрова Мария Сергеевна" head_teacher,teacher
 *
 * Пароль не принимается аргументом командной строки: аргументы процесса
 * видны в списке процессов и попадают в историю оболочки. Он читается со
 * стандартного ввода либо берётся из MYEDU_CREATE_PASSWORD — для
 * неинтерактивного запуска, где переменная окружения в отличие от аргумента
 * в список процессов не попадает.
 *
 * В примерах — вымышленные адреса. Реальные учётные данные сюда попадать не
 * должны: файл коммитится, и почтовый ящик с паролем в примере уехал бы
 * вместе с репозиторием.
 *
 * Роли перечисляются через запятую. Учётная запись сразу активна и с
 * подтверждённым адресом — иначе вход невозможен до получения письма.
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;

/*
 * Пароль не принимается аргументом командной строки.
 *
 * Аргументы процесса видны в списке процессов любому пользователю машины и
 * попадают в историю оболочки, поэтому пароль в argv — это пароль во всех
 * этих местах сразу. Здесь он читается со стандартного ввода и не сохраняется
 * нигде: ни в файле, ни в истории команд.
 *
 * Для неинтерактивного запуза (сценарий, CI) оставлена возможность передать
 * пароль через MYEDU_CREATE_PASSWORD: переменная окружения не попадает в
 * список процессов, в отличие от аргумента. Явно она сделана для автоматизации
 * и не должна использоваться руками.
 */
$args = array_slice($argv, 1);

if (count($args) < 3) {
    fwrite(
        STDERR,
        "Укажите адрес, ФИО и роли:\n"
        . "  php database/create_user.php uchet@example.edu \"Фамилия Имя Отчество\" admin\n"
        . "Пароль будет запрошен со стандартного ввода.\n"
        . "  роли: " . implode(', ', Role::assignable()) . "\n"
    );
    exit(1);
}

[$email, $fullName, $rolesArg] = $args;

/*
 * Четвёртый аргумент — это пароль из прежней сигнатуры. Молча пропустить его
 * нельзя: иначе «Фамилия Имя Отчество» уехало бы в пароль, роль — в ФИО, и
 * человек получал бы непонятную ошибку вдали от настоящей причины.
 */
if (count($args) > 3) {
    fwrite(
        STDERR,
        "Пароль больше не принимается аргументом командной строки.\n"
        . "Прежний вызов был: <адрес> <пароль> \"Фамилия Имя Отчество\" <роли>\n"
        . "Теперь: <адрес> \"Фамилия Имя Отчество\" <роли>, а пароль спросят со стандартного ввода.\n"
    );
    exit(1);
}

$password = (string) getenv('MYEDU_CREATE_PASSWORD');

if ($password === '') {
    /*
     * На Windows PHP не умеет отключать эхо ввода без внешней программы, а
     * надёжного способа сделать это из самого скрипта нет. Поэтому ввод
     * предупреждает заранее: пароль будет виден на экране.
     *
     * Для автоматизации (сценарий, CI) используйте MYEDU_CREATE_PASSWORD:
     * переменная окружения не попадает в список процессов, в отличие от
     * аргумента, и не сохраняется в истории команд.
     */
    fwrite(
        STDOUT,
        DIRECTORY_SEPARATOR === '\\'
            ? "Пароль (ввод будет виден на экране): "
            : "Пароль: "
    );

    $read = @fopen('php://stdin', 'rb');

    if ($read === false) {
        fwrite(STDERR, "\nНе удалось прочитать пароль со стандартного ввода.\n");
        exit(1);
    }

    $password = (string) stream_get_contents($read);
    fclose($read);

    fwrite(STDOUT, "\n");

    /*
     * BOM снимается явно. PowerShell при перенаправлении в поток пишет
     * UTF-8 с меткой порядка байтов, trim() её не убирает, и в базу уезжал
     * пароль с невидимым символом в начале — тот, что человек набрал, и тот,
     * что сохранён, переставали совпадать, и вход «работал не у всех».
     */
    $password = trim(preg_replace('/^\xEF\xBB\xBF/', '', $password) ?? $password);

    if ($password === '') {
        fwrite(STDERR, "Пароль не указан.\n");
        exit(1);
    }
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);
$auth = $app->get(Auth::class);
$users = new UserRepository($db);

$email = mb_strtolower(trim($email), 'UTF-8');
$fullName = (string) preg_replace('/\s+/u', ' ', trim($fullName));
$minLength = $auth->minPasswordLength();

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $fail("Некорректный адрес: {$email}");
}

if (mb_strlen($password, 'UTF-8') < $minLength) {
    $fail("Пароль короче {$minLength} символов.");
}

/*
 * Порядок частей имени — тот же, что и в форме регистрации:
 * фамилия, имя, отчество. Отсюда и обращение по имени в кабинете.
 */
if (count(preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2) {
    $fail("Укажите фамилию и имя, например: Иванов Иван Иванович");
}

$roles = array_values(array_filter(array_map('trim', explode(',', $rolesArg))));

foreach ($roles as $role) {
    if (!in_array($role, Role::assignable(), true)) {
        $fail("Неизвестная роль «{$role}». Доступны: " . implode(', ', Role::assignable()));
    }
}

if ($roles === []) {
    $fail('Нужна хотя бы одна роль.');
}

$existing = $users->findRowByIdentifier($email);

if ($existing !== null) {
    /*
     * Существующую запись не трогаем молча: иначе опечатка в адресе тихо
     * сбросила бы пароль живому человеку.
     */
    $fail(
        "Пользователь с адресом {$email} уже есть (id={$existing['id']}, "
        . 'роли: ' . implode(', ', $users->rolesOf((int) $existing['id'])) . '). '
        . 'Сменить пароль можно в панели управления.'
    );
}

$userId = $db->transaction(static function (Database $db) use ($users, $auth, $email, $password, $fullName, $roles): int {
    $userId = $users->create([
        'login'            => $email,
        'email'            => $email,
        'password_hash'    => $auth->hashPassword($password),
        'full_name'        => $fullName,
        'is_owner'         => 0,
        'status'           => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);

    $users->setRoles($userId, $roles, $userId);

    return $userId;
});

echo "Создан: {$fullName} <{$email}>\n";
echo 'Роли: ' . implode(', ', $users->rolesOf($userId)) . "\n";
echo "Пароль: {$password}\n";