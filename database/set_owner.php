<?php

declare(strict_types=1);

/**
 * Назначение владельца сайта.
 *
 * Нужен, когда владельца нужно передать другому человеку: при разработке
 * базу засеивают тестовые записи, и первый зарегистрировавшийся тестовый
 * пользователь занимает место владельца.
 *
 * Запуск:
 *   php database/set_owner.php <адрес владельца>
 *
 * В примере адрес вымышленный: реальные учётные данные в коммитируемом файле
 * уезжают вместе с репозиторием.
 *
 * Что делает:
 *   1. снимает флаг владельца со всех остальных записей;
 *   2. выдаёт указанному пользователю флаг владельца и роль администратора;
 *   3. сохраняет прежние роли, добавляя администратора, а не заменяя список.
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\Role;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;

$email = trim((string) ($argv[1] ?? ''));

if ($email === '') {
    fwrite(STDERR, "Укажите адрес владельца: php database/set_owner.php <адрес>\n");
    exit(1);
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);
$users = new UserRepository($db);

$row = $users->findRowByEmail(mb_strtolower($email, 'UTF-8'));

if ($row === null) {
    fwrite(STDERR, "Пользователь с адресом {$email} не найден.\n");
    exit(1);
}

$userId = (int) $row['id'];

// Прежние роли сохраняем: смена владельца не должна отбирать
// у человека право вести уроки, если оно у него было.
$roles = $users->rolesOf($userId);

if (!in_array(Role::ADMIN, $roles, true)) {
    $roles[] = Role::ADMIN;
}

// Наблюдатель означает «доступа нет», поэтому вместе с правами администратора
// он бессмысленен: в списке ролей такая связка сбивает с толку.
$roles = array_values(array_filter(
    $roles,
    static fn (string $role): bool => $role !== Role::OBSERVER
));

$db->transaction(static function (Database $db) use ($users, $userId, $roles): void {
    $db->run('UPDATE users SET is_owner = 0 WHERE is_owner = 1');
    $db->run('UPDATE users SET is_owner = 1 WHERE id = :id', ['id' => $userId]);
    $users->setRoles($userId, $roles, $userId);
});

echo "Владелец: {$row['full_name']} <{$row['email']}>\n";
echo 'Роли: ' . implode(', ', $users->rolesOf($userId)) . "\n";

echo "Прежние владельцы теперь администраторы:\n";

foreach ($db->all('SELECT id, email, full_name FROM users WHERE is_owner = 0 AND id <> :id', ['id' => $userId]) as $other) {
    $otherRoles = $users->rolesOf((int) $other['id']);

    if (in_array(Role::ADMIN, $otherRoles, true)) {
        echo "  - {$other['full_name']} <{$other['email']}> — администратор\n";
    }
}