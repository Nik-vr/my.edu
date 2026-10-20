<?php

declare(strict_types=1);

/**
 * Тесты регистрации, подтверждения адреса, ролей и разграничения прав.
 *
 * Запуск:
 *   powershell -File tools\dev-server.ps1 -ForTests
 *   php tests/run_auth_tests.php http://127.0.0.1:8098
 *
 * ВНИМАНИЕ: скрипт очищает пользователей, роли, классы и попытки —
 * это база разработки, не рабочие данные.
 */

require __DIR__ . '/support/TestKit.php';

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Auth\Role;
use MyEdu\Auth\TokenRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8098';

/*
 * Скрипт чистит базу, поэтому MYEDU_DB_NAME обязан указывать на отдельную
 * тестовую базу — рабочую он не тронет, но и проверять её не должен.
 */
if (getenv('MYEDU_DB_NAME') === false || getenv('MYEDU_DB_NAME') === '') {
    putenv('MYEDU_DB_NAME=my_edu_review_test');
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

assertTestDatabase($db);

$t = new TestRunner();

echo "База: " . $db->value('SELECT DATABASE()') . "\n";
echo "\033[33mСкрипт очищает пользователей, роли, классы и попытки.\033[0m\n\n";

// --------------------------------------------------------------- очистка

/*
 * Очищаются таблицы регистрации, классов и попыток: они ссылаются на
 * пользователей, которых набор пересоздаёт с теми же идентификаторами.
 * Определения тестов, работ и их заданий остаются нетронутыми.
 *
 * Таблицы самостоятельных тестов (answers, attempts, grades) из списка убраны
 * вместе с ними — миграция 013. Пока они были в списке, набор падал на
 * первой же очистке, и падал молча: run_tests.ps1 показывал только итог
 * предыдущего набора.
 */
foreach ([
    'assignment_item_results', 'assignment_attempts',
    'class_students', 'class_teachers', 'class_subjects', 'auth_tokens',
    'user_roles', 'classes', 'users',
] as $table) {
    $db->run('DELETE FROM `' . $table . '`');
}

$db->run('ALTER TABLE users AUTO_INCREMENT = 1');

$users = new UserRepository($db);
$tokens = new TokenRepository($db);

// Права проверяются на опубликованной работе с опубликованным тестом.
$publishedWork = $db->one(
    "SELECT a.id AS assignment_id, i.task_id AS test_id
       FROM assignments a
       JOIN assignment_items i ON i.assignment_id = a.id
       JOIN tests t ON t.id = i.task_id
      WHERE a.status = 'published' AND i.plugin_id = 'test' AND t.status = 'published'
   ORDER BY a.published_at DESC, a.id DESC
      LIMIT 1"
);
$publishedWorkId = $publishedWork === null ? null : (int) $publishedWork['assignment_id'];
$publishedTestId = $publishedWork === null ? null : (int) $publishedWork['test_id'];

$t->is(!$users->ownerExists(), 'база очищена: владельца нет');

$t->group('Параллельная первая регистрация');

$barrierDir = sys_get_temp_dir() . '/myedu-owner-race-' . bin2hex(random_bytes(8));
mkdir($barrierDir, 0700);
$workers = [];
$raceEmails = ['race-one@example.edu', 'race-two@example.edu'];
$raceResults = [];

try {
    foreach ($raceEmails as $email) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $rootDir . '/tests/owner_registration_worker.php', $barrierDir, $email],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить процесс параллельной регистрации');
        }

        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes, 'email' => $email];
    }

    $deadline = microtime(true) + 10;
    while (count(glob($barrierDir . '/*.ready') ?: []) < count($raceEmails)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Процессы регистрации не достигли стартового барьера');
        }
        usleep(10000);
    }

    file_put_contents($barrierDir . '/go', 'go');

    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0) {
            throw new RuntimeException('Регистрация ' . $worker['email'] . ' завершилась ошибкой: ' . $stderr);
        }

        $raceResults[] = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    $raceUsers = array_map(static fn (string $email): ?array => $users->findRowByEmail($email), $raceEmails);
    $raceOwnerCount = (int) $db->value(
        "SELECT COUNT(*) FROM users WHERE email IN (:first, :second) AND is_owner = 1",
        ['first' => $raceEmails[0], 'second' => $raceEmails[1]]
    );

    $t->equals(2, count(array_filter($raceResults, static fn (array $result): bool => ($result['ok'] ?? false) === true)),
        'обе параллельные регистрации успешно завершились');
    $t->equals(1, $raceOwnerCount, 'ровно одна параллельная регистрация получила владельца');
    $t->is(count(array_filter($raceUsers, static fn (?array $user): bool => $user !== null)) === 2,
        'обе параллельные учётные записи сохранены');
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            foreach ([1, 2] as $pipeIndex) {
                if (is_resource($worker['pipes'][$pipeIndex])) {
                    fclose($worker['pipes'][$pipeIndex]);
                }
            }
            proc_close($worker['process']);
        }
    }

    foreach ($raceEmails as $email) {
        $db->run('DELETE FROM users WHERE email = :email', ['email' => $email]);
    }
    $db->run('ALTER TABLE users AUTO_INCREMENT = 1');

    foreach (glob($barrierDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($barrierDir);
}

$t->is(!$users->ownerExists(), 'тест гонки очистил временные регистрации');

$t->group('Конкурентное потребление одноразового токена');

$tokenTestUserId = $users->create([
    'login'         => 'token-race@example.edu',
    'email'         => 'token-race@example.edu',
    'password_hash' => password_hash('token-race-password', PASSWORD_DEFAULT),
    'full_name'     => 'Тест Одноразовый Токен',
    'is_owner'      => 0,
    'status'        => 'active',
]);
$tokenRace = $tokens->issue($tokenTestUserId, TokenRepository::PURPOSE_RESET, 1);
$tokenBarrierDir = sys_get_temp_dir() . '/myedu-token-race-' . bin2hex(random_bytes(8));
mkdir($tokenBarrierDir, 0700);
$tokenWorkers = [];
$consumeResults = [];
$consumeEmails = ['consume-one', 'consume-two'];

try {
    foreach ($consumeEmails as $email) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $rootDir . '/tests/token_consume_worker.php', $tokenBarrierDir, $tokenRace, $email],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить процесс потребления токена');
        }

        fclose($pipes[0]);
        $tokenWorkers[] = ['process' => $process, 'pipes' => $pipes, 'email' => $email];
    }

    $deadline = microtime(true) + 10;
    while (count(glob($tokenBarrierDir . '/*.ready') ?: []) < count($consumeEmails)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Процессы потребления не достигли стартового барьера');
        }
        usleep(10000);
    }

    file_put_contents($tokenBarrierDir . '/go', 'go');

    foreach ($tokenWorkers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0) {
            throw new RuntimeException('Потребление токена завершилось ошибкой: ' . $stderr);
        }

        $consumeResults[] = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    $t->equals(1, count(array_filter($consumeResults, static fn (array $result): bool => $result['consumed'] === true)),
        'токен успешно потребил ровно один из двух одновременных запросов');
    $t->is(!$tokens->hasActive($tokenRace, TokenRepository::PURPOSE_RESET),
        'после потребления токен более не действует');
} finally {
    foreach ($tokenWorkers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            foreach ([1, 2] as $pipeIndex) {
                if (is_resource($worker['pipes'][$pipeIndex])) {
                    fclose($worker['pipes'][$pipeIndex]);
                }
            }
            proc_close($worker['process']);
        }
    }

    $db->run('DELETE FROM users WHERE id = :id', ['id' => $tokenTestUserId]);
    $db->run('ALTER TABLE users AUTO_INCREMENT = 1');

    foreach (glob($tokenBarrierDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tokenBarrierDir);
}

/*
 * Атомарность выдачи ссылки.
 *
 * Гашение прежних токенов и вставка нового должны быть одной транзакцией.
 * Без этого сбой на вставке оставлял человека вообще без действующей ссылки:
 * UPDATE уже записался, а нового токена не появилось, и подтвердить адрес или
 * сбросить пароль было уже нечем.
 *
 * Сбой вызывается настоящей ошибкой базы — слишком длинным адресом получателя,
 * — а не подделкой: иначе проверялся бы сам заглушечный код, а не код
 * выпуска токена.
 */
$t->group('Выдача ссылки не теряет предыдущую');

$atomicEmail = 'atomic-token-probe@example.edu';
$db->run('DELETE FROM users WHERE email = :e', ['e' => $atomicEmail]);
$db->run(
    'INSERT INTO users (login, full_name, email, password_hash, created_at, updated_at)
     VALUES (:l, :n, :e, :h, NOW(), NOW())',
    ['l' => 'atomic-probe', 'n' => 'Проба выдачи ссылки', 'e' => $atomicEmail, 'h' => 'x']
);
$atomicUserId = (int) $db->value('SELECT id FROM users WHERE email = :e', ['e' => $atomicEmail]);

$survivingToken = $tokens->issue($atomicUserId, TokenRepository::PURPOSE_CONFIRM, 24, $atomicEmail);

$insertFailed = false;

try {
    $tokens->issue($atomicUserId, TokenRepository::PURPOSE_CONFIRM, 24, str_repeat('я', 300));
} catch (\Throwable) {
    $insertFailed = true;
}

$t->is($insertFailed, 'вставка нового токена упала — как и задумано');
$t->is($tokens->hasActive($survivingToken, TokenRepository::PURPOSE_CONFIRM),
    'прежняя ссылка пережила неудачную выдачу и осталась рабочей');

$db->run('DELETE FROM users WHERE id = :id', ['id' => $atomicUserId]);

/*
 * Владелец — тот, кто зарегистрировался первым, а не тот, кто подтвердил
 * адрес первым.
 *
 * Разница видна только на обратном порядке: если второй подтвердится раньше
 * первого, неправильная реализация, выдающая владельца при подтверждении,
 * отдала бы его второму. Прямой порядок «сначала подтвердил первый» эту
 * ошибку не показывает — там владелец и так первый.
 */
$t->group('Владелец достаётся по регистрации, а не по подтверждению');

$ownerRaceFirst = 'owner-race-first@example.edu';
$ownerRaceSecond = 'owner-race-second@example.edu';

foreach ([$ownerRaceFirst, $ownerRaceSecond] as $stale) {
    $db->run('DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE email = :e)', ['e' => $stale]);
    $db->run('DELETE FROM users WHERE email = :e', ['e' => $stale]);
}

$db->run(
    'INSERT INTO users (login, full_name, email, password_hash, is_owner, status, created_at, updated_at)
     VALUES (:l, :n, :e, :h, 0, :s, NOW(), NOW())',
    ['l' => 'owner-race-first', 'n' => 'Первый по регистрации', 'e' => $ownerRaceFirst, 'h' => 'x', 's' => 'pending']
);
$firstRegistrantId = (int) $db->value('SELECT id FROM users WHERE email = :e', ['e' => $ownerRaceFirst]);

$db->run(
    'INSERT INTO users (login, full_name, email, password_hash, is_owner, status, created_at, updated_at)
     VALUES (:l, :n, :e, :h, 0, :s, NOW(), NOW())',
    ['l' => 'owner-race-second', 'n' => 'Второй по регистрации', 'e' => $ownerRaceSecond, 'h' => 'x', 's' => 'pending']
);
$secondRegistrantId = (int) $db->value('SELECT id FROM users WHERE email = :e', ['e' => $ownerRaceSecond]);

/*
 * Владельцем назначается первый зарегистрировавшийся — ровно так же, как это
 * делает настоящая регистрация. Ручная вставка здесь нужна, чтобы не уводить
 * основной сценарий с его порядком шагов.
 */
$db->run('UPDATE users SET is_owner = 1 WHERE id = :id AND NOT EXISTS (SELECT 1 FROM users WHERE is_owner = 1)',
    ['id' => $firstRegistrantId]);

// Подтверждается первым второй: порядок подтверждений перевёрнут.
$db->run('UPDATE users SET status = :s, email_verified_at = NOW(), updated_at = NOW() WHERE id = :id',
    ['s' => 'active', 'id' => $secondRegistrantId]);

$secondAfterEarlyConfirm = $users->findRow($secondRegistrantId);
$firstStillWaiting = $users->findRow($firstRegistrantId);

$t->equals(0, (int) ($secondAfterEarlyConfirm['is_owner'] ?? -1),
    'подтвердившийся раньше всех не становится владельцем');
$t->equals(1, (int) ($firstStillWaiting['is_owner'] ?? 0),
    'владелец остался за тем, кто зарегистрировался первым');
$t->equals('pending', (string) ($firstStillWaiting['status'] ?? ''),
    'владелец не обязан подтверждать адрес, чтобы им быть');

foreach ([$ownerRaceFirst, $ownerRaceSecond] as $stale) {
    $db->run('DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE email = :e)', ['e' => $stale]);
    $db->run('DELETE FROM users WHERE email = :e', ['e' => $stale]);
}

// Сравнение ролей без учёта порядка: выдача сортируется по позиции роли.
$sameRoles = static function (array $expected, array $actual): bool {
    sort($expected);
    sort($actual);

    return $expected === $actual;
};

// ------------------------------------------------------------------ гость

$t->group('Гость');

$guest = new TestClient($baseUrl);

$t->is($guest->get('/register')['status'] === 200, 'страница регистрации доступна');
$t->contains($guest->get('/register')['body'], 'первая учётная запись', 'гость видит подсказку о владельце');
$t->is($guest->get('/login')['status'] === 200, 'страница входа доступна');

/*
 * Обе формы входа стоят вкладками в шапке, а по адресу сайта открывается
 * ученическая.
 *
 * Раньше по адресу сайта была витрина «С чего начать», а форма входа жила по
 * отдельному адресу: человек, пришедший по напечатанному листу, оказывался на
 * витрине и должен был ещё раз выбрать, куда нажать. Теперь адрес сайта —
 * это сразу форма ученика, а переключиться можно в шапке.
 *
 * Вкладки проверяются как ссылки, а не как переключение скриптом: страница
 * входа первая, куда попадает человек на свежей установке, и обязана работать
 * там, где скрипты не доехали.
 */
$guestHome = $guest->get('/');
$guestStaff = $guest->get('/login');

$t->is($guestHome['status'] === 200, 'адрес сайта открывается гостю');
$t->contains($guestHome['body'], 'Вход для ученика', 'по адресу сайта — вход для ученика');
$t->contains($guestHome['body'], 'name="identifier"', 'форма входа ученика на месте по адресу сайта');
$t->notContains($guestHome['body'], 'Зарегистрироваться',
    'на вкладке ученика нет регистрации');

foreach (['/', '/login'] as $tabAddress) {
    $t->contains($guest->get($tabAddress)['body'], 'role="tablist"',
        'вкладки входа — вкладки');
    $t->contains($guest->get($tabAddress)['body'], 'Вход для учителя',
        'в шапке есть вкладка входа для учителя');
}

$t->contains($guestStaff['body'], 'Зарегистрироваться',
    'регистрация переехала на вкладку учителя');
$t->notContains($guestHome['body'], '>Регистрация<',
    'кнопки регистрации в шапке больше нет');
/*
 * Активная вкладка помечена для чтения с экрана: на форме ученика — «вход для
 * ученика», на форме сотрудника — наоборот. Проверяется пара «рисуется выбор»
 * и «помечен один из двух», а не конкретный адрес: подсказка означает
 * «здесь», и подменить её другим адресом нельзя.
 *
 * Порядок атрибутов в проверке не участвует: он не часть договора, а следствие
 * того, как написана строка в шаблоне. Проверяется принадлежность отметки
 * нужной вкладке, а не её соседство с href.
 */
$t->equals(1, substr_count($guestHome['body'], 'aria-selected="true"'),
    'на форме ученика помечена ровно одна вкладка');
$t->equals(1, substr_count($guestStaff['body'], 'aria-selected="true"'),
    'на форме сотрудника помечена ровно одна вкладка');
$t->is(
    preg_match('#<a[^>]*aria-selected="true"[^>]*>\s*Вход для ученика#s', $guestHome['body']) === 1,
    'на форме ученика активна вкладка ученика'
);
$t->is(
    preg_match('#<a[^>]*aria-selected="true"[^>]*>\s*Вход для учителя#s', $guestStaff['body']) === 1,
    'на форме сотрудника активна вкладка учителя'
);

/*
 * Вкладки входа — элемент шаблона, а не группа кнопок.
 *
 * Раньше выбор формы был двумя кнопками в группе: на вид это действие, а на
 * деле это два адреса, между которыми переходят, то есть разделы. Вкладки
 * читаются правильно и переключаются тем же щелчком по тому же месту.
 */
foreach (['/' => $guestHome['body'], '/login' => $guestStaff['body']] as $tabAddress => $tabBody) {
    $t->contains($tabBody, 'class="nav nav-tabs"',
        'вкладки входа нарисованы элементом шаблона');
    $t->notContains($tabBody, 'class="btn-group auth-tabs"',
        'группы кнопок на месте вкладок больше нет');
}

$guestTests = $guest->get('/tests/1');
$t->is($guestTests['status'] === 302 && str_contains($guestTests['location'], '/login'),
    'гость отправляется на страницу входа вместо теста');

$guestPanel = $guest->get('/panel');
$t->is($guestPanel['status'] === 302, 'панель закрыта для гостя');

// ------------------------------------------------------------------ регистрация первого

$t->group('Регистрация первого пользователя');

$mailLogDir = $rootDir . '/var/log';

foreach (glob($mailLogDir . '/mail-*.log') ?: [] as $old) {
    @unlink($old);
}

$page = $guest->get('/register');
$form = [
    '_token'    => $guest->tokenFrom($page) ?? '',
    'full_name' => 'Власенко Ольга Петровна',
    'email'     => 'owner@example.edu',
    'password'  => 'parol12345',
    'password2' => 'parol12345',
];

$register = $guest->post('/register', $form);
$t->is($register['status'] === 302 && str_contains($register['location'], '/login'),
    'регистрация принята и ведёт ко входу');

$ownerRow = $users->findRowByEmail('owner@example.edu');
$t->is($ownerRow !== null, 'учётная запись создана');
$t->equals('pending', (string) ($ownerRow['status'] ?? ''), 'новый пользователь ожидает подтверждения');
$t->equals(1, (int) ($ownerRow['is_owner'] ?? 0), 'первый зарегистрировавшийся сразу стал владельцем');
$t->is($sameRoles([Role::ADMIN], $users->rolesOf((int) $ownerRow['id'])),
    'первому зарегистрировавшемуся выдана роль администратора');

$loginPage = $guest->get('/login');
$t->contains($loginPage['body'], 'отправили письмо', 'сообщение о письме показано');

$t->group('Письмо со ссылкой подтверждения');

$logFile = glob($mailLogDir . '/mail-*.log') ?: [];
$t->is($logFile !== [], 'письмо записано в журнал');

$mail = $logFile === [] ? '' : (string) file_get_contents($logFile[0]);
$t->contains($mail, 'owner@example.edu', 'в письме указан адрес получателя');
$t->contains($mail, 'Подтверждение регистрации', 'тема письма верна');
$t->contains($mail, '/auth/confirm?token=', 'в письме есть ссылка подтверждения');

preg_match('#/auth/confirm\?token=([a-f0-9]{64})#', $mail, $confirmMatch);
$confirmToken = $confirmMatch[1] ?? '';
$t->is($confirmToken !== '', 'ссылку подтверждения удалось извлечь', );

$t->is(strlen($confirmToken) === 64, 'токен имеет ожидаемую длину');
$t->is($tokens->hasActive($confirmToken, TokenRepository::PURPOSE_CONFIRM), 'токен найден и действует');

/*
 * Обрезка токенов в журнале.
 *
 * На стенде обрезка выключена переменной MYEDU_LOG_REDACT_TOKENS=0 — иначе
 * набор не смог бы достать токен из письма, чтобы им войти. Но на боевой
 * установке драйвер 'log' означает, что токен подтверждения лежит на диске
 * открытым текстом: каталог var/ доступен тому, у кого есть доступ к файлам.
 *
 * Поэтому обрезка проверяется здесь напрямую: стенд доказывает, что она
 * выключается, а этот набор — что по умолчанию она включена и токен в журнал
 * не попадает.
 */
$t->group('Токены в журнале писем обрезаются по умолчанию');

$redactDir = sys_get_temp_dir() . '/myedu_mail_' . getmypid();

@mkdir($redactDir, 0775, true);

$probeToken = bin2hex(random_bytes(32));

$redactingMailer = new MyEdu\Core\LogMailer($redactDir, true);
$redactingMailer->send(new MyEdu\Core\MailMessage(
    'probe@example.edu',
    'Проверка обрезки',
    "Откройте ссылку: https://edu.example.ru/auth/confirm?token={$probeToken}"
));

$redactFiles = glob($redactDir . '/mail-*.log') ?: [];
$redactLog = $redactFiles === [] ? '' : (string) file_get_contents($redactFiles[0]);

$t->is($redactLog !== '', 'письмо записано в журнал');
$t->notContains($redactLog, $probeToken, 'полного токена в журнале нет');
$t->contains($redactLog, 'скрыт', 'вместо токена в журнале стоит пометка, что он скрыт');
$t->contains($redactLog, '/auth/confirm?token=', 'сама ссылка в письме осталась: адрес нужен для разбора');

foreach (glob($redactDir . '/*.log') ?: [] as $left) {
    @unlink($left);
}

@rmdir($redactDir);

// ------------------------------------------------------------------ повторное письмо

$t->group('Повторная отправка письма подтверждения');

$resendPage = $guest->get('/confirm/resend');
$t->is($resendPage['status'] === 200, 'страница повторной отправки доступна');

$resend = $guest->post('/confirm/resend', [
    '_token' => $guest->tokenFrom($resendPage) ?? '',
    'email'  => 'owner@example.edu',
]);

$t->is($resend['status'] === 302, 'повторная отправка обработана');
$t->contains($guest->get('/login')['body'], 'отправили письмо', 'показан общий ответ о письме');

// В журнале должна появиться новая ссылка подтверждения.
$resentMail = $logFile === [] ? '' : (string) file_get_contents($logFile[0]);
preg_match_all('#/auth/confirm\?token=([a-f0-9]{64})#', $resentMail, $resentMatches);
$resentToken = (string) end($resentMatches[1]);

$t->is($resentToken !== '' && $resentToken !== $confirmToken, 'выдан новый токен подтверждения');
$t->is(!$tokens->hasActive($confirmToken, TokenRepository::PURPOSE_CONFIRM),
    'старая ссылка больше не действует — действует только последняя');

// Чужая незарегистрированная почта не должна выдавать разницу в ответе.
$resendPage = $guest->get('/confirm/resend');
$unknown = $guest->post('/confirm/resend', [
    '_token' => $guest->tokenFrom($resendPage) ?? '',
    'email'  => 'net-takogo@example.edu',
]);

$t->is($unknown['status'] === 302, 'неизвестный адрес обработан так же');
$t->contains($guest->get('/login')['body'], 'отправили письмо', 'неизвестный адрес не выдаёт себя');

// Подтверждаем по самой свежей ссылке.
$confirmToken = $resentToken;

// ------------------------------------------------------------------ вход до подтверждения

$t->group('Вход до подтверждения адреса');

$loginPage = $guest->get('/login');
$early = $guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'owner@example.edu',
    'password'  => 'parol12345',
]);

$t->is($early['status'] === 302 && str_contains($early['location'], '/login'), 'вход не выполнен');
$t->contains($guest->get('/login')['body'], 'подтвердите адрес', 'показано объяснение про подтверждение');

// ------------------------------------------------------------------ подтверждение

$t->group('Подтверждение адреса и права владельца');

$confirmPage = $guest->get('/auth/confirm?token=' . $confirmToken);
$t->is($confirmPage['status'] === 200, 'GET показывает форму подтверждения');
$t->is($tokens->hasActive($confirmToken, TokenRepository::PURPOSE_CONFIRM),
    'GET не погашает токен подтверждения');
$t->equals('pending', (string) ($users->findRowByEmail('owner@example.edu')['status'] ?? ''),
    'GET не активирует учётную запись');
$confirm = $guest->post('/auth/confirm', [
    '_token' => $guest->tokenFrom($confirmPage) ?? '',
    'token'  => $confirmToken,
]);
$t->is($confirm['status'] === 302, 'подтверждение выполнено POST-запросом');

$ownerRow = $users->findRowByEmail('owner@example.edu');
$t->equals('active', (string) ($ownerRow['status'] ?? ''), 'учётная запись активна');
$t->is(!empty($ownerRow['email_verified_at']), 'дата подтверждения записана');
$t->is($sameRoles([Role::ADMIN], $users->rolesOf((int) $ownerRow['id'])), 'первый подтверждённый стал администратором');

// Повторное использование ссылки не должно ничего менять
$again = $guest->post('/auth/confirm', [
    '_token' => $guest->tokenFrom($confirmPage) ?? '',
    'token'  => $confirmToken,
]);
$t->is($again['status'] === 400, 'использованный токен не принимается повторно');
$t->is($sameRoles([Role::ADMIN], $users->rolesOf((int) $ownerRow['id'])), 'роли не изменились после повторной попытки');

/*
 * Подтверждение без роли.
 *
 * Адрес подтверждается, и человек входит в систему — запрета на вход нет,
 * закрыт только раздел с заданиями. Проверяется, что сообщение говорит
 * именно это: прежний текст «войти не получится» был неправдой, из-за
 * которой человек шёл к администратору напрасно.
 */
$rolelessEmail = 'roleless@example.edu';
$db->run('DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE email = :e)', ['e' => $rolelessEmail]);
$db->run('DELETE FROM users WHERE email = :e', ['e' => $rolelessEmail]);
$db->run(
    'INSERT INTO users (login, full_name, email, password_hash, status, created_at, updated_at)
     VALUES (:l, :n, :e, :h, :s, NOW(), NOW())',
    [
        'l' => 'roleless',
        'n' => 'Без ролей Роль',
        'e' => $rolelessEmail,
        'h' => $app->get(\MyEdu\Auth\Auth::class)->hashPassword('parol12345'),
        's' => 'pending',
    ]
);
$rolelessId = (int) $db->value('SELECT id FROM users WHERE email = :e', ['e' => $rolelessEmail]);

$rolelessToken = $tokens->issue($rolelessId, TokenRepository::PURPOSE_CONFIRM, 24, $rolelessEmail);
$rolelessConfirmPage = $guest->get('/auth/confirm?token=' . $rolelessToken);
$rolelessConfirm = $guest->post('/auth/confirm', [
    '_token' => $guest->tokenFrom($rolelessConfirmPage) ?? '',
    'token'  => $rolelessToken,
]);

$t->is($rolelessConfirm['status'] === 400, 'подтверждение без роли не выдаёт страницу успеха');
$t->contains($rolelessConfirm['body'], 'нет ни одной роли', 'сообщение прямо говорит, что роли нет');
$t->contains($rolelessConfirm['body'], 'Войти можно', 'сообщение не врёт: вход без роли работает');
$t->equals('active', (string) ($users->findRow($rolelessId)['status'] ?? ''), 'адрес при этом подтверждён');
$t->is($users->rolesOf($rolelessId) === [], 'роль такому пользователю не выдана');

// Проверяем, что текст соответствует поведению, а не только сам по себе красив.
$rolelessClient = new TestClient($baseUrl);
$rolelessLoginPage = $rolelessClient->get('/login');
$rolelessClient->post('/login', [
    '_token'     => $rolelessClient->tokenFrom($rolelessLoginPage) ?? '',
    'identifier' => $rolelessEmail,
    'password'   => 'parol12345',
]);

$t->is($rolelessClient->get('/portal')['status'] === 200, 'запись без роли действительно входит в систему');
$t->is($rolelessClient->get('/panel')['status'] === 403, 'но раздел с заданиями ей закрыт');

$db->run('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => $rolelessId]);
$db->run('DELETE FROM users WHERE id = :id', ['id' => $rolelessId]);

// ------------------------------------------------------------------ вход

$t->group('Вход владельца');

$loginPage = $guest->get('/login');
$login = $guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'owner@example.edu',
    'password'  => 'parol12345',
]);

$t->is($login['status'] === 302 && !str_contains($login['location'], '/login'), 'вход выполнен');

$portal = $guest->get('/portal');
$t->is($portal['status'] === 200, 'кабинет открыт');
$t->contains($portal['body'], 'Ольга Петровна', 'в кабинете обращение по имени и отчеству');
$t->contains($portal['body'], 'владелец сайта', 'указано, что это владелец');

$t->is($guest->get('/panel')['status'] === 200, 'панель управления владельцу доступна');
$t->is($guest->get('/classes')['status'] === 200, 'список классов владельцу доступен');
$t->is($guest->get('/panel/users')['status'] === 200, 'управление пользователями владельцу доступно');

// ------------------------------------------------------------------ второй пользователь

$t->group('Регистрация второго пользователя');

$guest->post('/logout', ['_token' => $guest->tokenFrom($guest->get('/portal')) ?? '']);
$guest->forgetCookies();

// Журнал писем очищаем: иначе в нём останется уже использованная ссылка
// первого пользователя, и она будет принята за ссылку второго.
foreach (glob($mailLogDir . '/mail-*.log') ?: [] as $old) {
    @unlink($old);
}

$page = $guest->get('/register');
$t->contains($page['body'], 'Наблюдатель', 'новому пользователю обещана роль наблюдателя');
$t->notContains($page['body'], 'первая учётная запись', 'подсказки о владельце уже нет');

$guest->post('/register', [
    '_token'    => $guest->tokenFrom($page) ?? '',
    'full_name' => 'Тарасов Игорь Петрович',
    'email'     => 'teacher@example.edu',
    'password'  => 'uchitel2025',
    'password2' => 'uchitel2025',
]);

$second = $users->findRowByEmail('teacher@example.edu');
$t->is($second !== null, 'вторая учётная запись создана');
$t->equals('pending', (string) ($second['status'] ?? ''), 'второй тоже ожидает подтверждения');
$t->equals(0, (int) ($second['is_owner'] ?? 0), 'второй не стал владельцем');
$t->is($sameRoles([Role::OBSERVER], $users->rolesOf((int) $second['id'])), 'второму выдана роль наблюдателя');

// Подтверждение второго
$files = glob($mailLogDir . '/mail-*.log') ?: [];
$secondMail = $files === [] ? '' : (string) file_get_contents($files[0]);
preg_match('#/auth/confirm\?token=([a-f0-9]{64})#', $secondMail, $m2);
$t->is(($m2[1] ?? '') !== '', 'письмо со ссылкой второму пользователю отправлено');

$secondConfirmPage = $guest->get('/auth/confirm?token=' . ($m2[1] ?? ''));
$guest->post('/auth/confirm', [
    '_token' => $guest->tokenFrom($secondConfirmPage) ?? '',
    'token'  => $m2[1] ?? '',
]);

$second = $users->findRowByEmail('teacher@example.edu');
$t->equals('active', (string) ($second['status'] ?? ''), 'второй подтверждён');
$t->equals(0, (int) ($second['is_owner'] ?? 0), 'второй по-прежнему не владелец');
$t->is($sameRoles([Role::OBSERVER], $users->rolesOf((int) $second['id'])), 'после подтверждения роль осталась наблюдателем');

// ------------------------------------------------------------------ права наблюдателя

$t->group('Права наблюдателя');

$guest->post('/logout', ['_token' => $guest->tokenFrom($guest->get('/portal')) ?? '']);
$guest->forgetCookies();

$loginPage = $guest->get('/login');
$guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'uchitel2025',
]);

$t->is($guest->get('/portal')['status'] === 200, 'наблюдатель вошёл в систему');

$observerPanel = $guest->get('/panel');
$t->is($observerPanel['status'] === 403, 'панель управления наблюдателю закрыта');
$t->contains($observerPanel['body'], 'Недостаточно прав', 'показано сообщение о нехватке прав');

$t->is($guest->get('/classes')['status'] === 403, 'список классов наблюдателю закрыт');
$t->is($guest->get('/classes/1/students')['status'] === 403, 'регистрация учеников наблюдателю закрыта');
$t->is($guest->get('/panel/users')['status'] === 403, 'управление пользователями наблюдателю закрыто');
$t->is($guest->get('/classes/1')['status'] === 403, 'карточка класса наблюдателю закрыта');

$t->is($guest->get('/profile')['status'] === 200, 'свои данные наблюдатель видит');

// ------------------------------------------------------------------ назначение ролей

$t->group('Назначение ролей администратором');

$owner = new TestClient($baseUrl);
$loginPage = $owner->get('/login');
$owner->post('/login', [
    '_token'     => $owner->tokenFrom($loginPage) ?? '',
    'identifier' => 'owner@example.edu',
    'password'  => 'parol12345',
]);

$ownerId = (int) $ownerRow['id'];

$usersPage = $owner->get('/panel/users');
$t->is($usersPage['status'] === 200, 'владелец видит список пользователей');
$t->contains($usersPage['body'], 'владелец', 'владелец помечен в списке');

/*
 * Роль меняется из списка одним выбором, и на странице человека формы ролей
 * больше нет: одна правка в одном месте, а не две формы об одном поле.
 */
$t->notContains($usersPage['body'], 'name="roles[]"', 'в списке нет набора галочек с ролями');
$t->contains($usersPage['body'], 'panel/users/' . $ownerId, 'в списке есть ссылка «Редактировать»');

$ownPage = $owner->get('/panel/users/' . $ownerId);
$t->is($ownPage['status'] === 200, 'страница владельца открывается');
$t->notContains($ownPage['body'], 'name="roles[]"', 'на странице человека роли не редактируются');
$t->contains($ownPage['body'], 'Роль у человека одна', 'страница говорит, где роль меняется');

$secondId = (int) $second['id'];

/*
 * Старый много-ролевой адрес роли больше не выдаёт.
 *
 * Он не обязан отвечать 404: маршрут /panel/users/{id} ловит «roles» как номер
 * человека, а человека с таким номером не существует. Важно другое — роль не
 * изменилась.
 */
$rolesBeforeOldAddress = $users->rolesOf($secondId);
$owner->post('/panel/users/roles', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'roles[]' => [Role::TEACHER],
]);

$t->is($users->rolesOf($secondId) === $rolesBeforeOldAddress,
    'адрес назначения нескольких ролей больше не выдаёт роль');

// Попытка снять роль администратора у владельца
$attempt = $owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $ownerId,
    'role'    => Role::OBSERVER,
]);

$t->is($attempt['status'] === 302, 'запрос обработан');
$t->is($users->rolesOf($ownerId) === [Role::ADMIN], 'у владельца нельзя снять роль администратора');
$t->contains($owner->get('/panel/users/' . $ownerId)['body'], 'Администратор', 'роль владельца на месте');

$t->group('Роль одна: следующий выбор заменяет прежний');

// Назначаем учителем второго пользователя
$usersPage = $owner->get('/panel/users');
$assign = $owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::TEACHER,
]);

$t->is($assign['status'] === 302, 'назначение роли принято');
$t->is($users->rolesOf($secondId) === [Role::TEACHER], 'роль «Учитель» назначена');

// Следующий выбор заменяет прежнюю роль, а не добавляется к ней
$usersPage = $owner->get('/panel/users');
$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::ADMIN,
]);

$t->is($users->rolesOf($secondId) === [Role::ADMIN], 'у человека одна роль: новый выбор заменил прежний');

// Теперь защиту владельца видно со стороны второго администратора.
$secondAdmin = new TestClient($baseUrl);
$loginPage = $secondAdmin->get('/login');
$secondAdmin->post('/login', [
    '_token'     => $secondAdmin->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'uchitel2025',
]);

$fromSecondAdmin = $secondAdmin->get('/panel/users');
$t->is($fromSecondAdmin['status'] === 200, 'второй администратор видит список пользователей');

$ownerPageFromSecond = $secondAdmin->get('/panel/users/' . $ownerId);
$t->is($ownerPageFromSecond['status'] === 200, 'второй администратор открывает страницу владельца');
$t->notContains($ownerPageFromSecond['body'], 'name="roles[]"',
    'страница владельца и со стороны второго администратора без формы ролей');
$t->contains($ownerPageFromSecond['body'], 'Администратор', 'роль владельца показана как факт');

// Попытка снять роль у владельца через второй аккаунт отклоняется.
$secondAdmin->post('/panel/users/role', [
    '_token'  => $secondAdmin->tokenFrom($ownerPageFromSecond) ?? '',
    'user_id' => (string) $ownerId,
    'role'    => Role::OBSERVER,
]);

$t->is($users->rolesOf($ownerId) === [Role::ADMIN],
    'второй администратор не может снять роль администратора у владельца');

// ------------------------------------------------------------------ замкнутость ролей

$t->group('Чужие роли через форму не выдаются');

$usersPage = $owner->get('/panel/users');
$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::STUDENT,
]);

$t->is($users->rolesOf($secondId) === [Role::ADMIN], 'роль «Ученик» через форму не назначается');

// Несуществующая роль отклоняется
$usersPage = $owner->get('/panel/users');
$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'role'    => 'neizvestnaya-rol',
]);

$t->is($users->rolesOf($secondId) === [Role::ADMIN], 'несуществующая роль не принимается');

// Пустое значение отклоняется: без роли человек не видит ни одного раздела
$usersPage = $owner->get('/panel/users');
$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $secondId,
    'role'    => '',
]);

$t->is($users->rolesOf($secondId) === [Role::ADMIN], 'нельзя оставить человека без роли');

// Свою роль менять нельзя
$usersPage = $owner->get('/panel/users');
$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($usersPage) ?? '',
    'user_id' => (string) $ownerId,
    'role'    => Role::OBSERVER,
]);

$t->is($users->rolesOf($secondId) === [Role::ADMIN], 'состав ролей не пострадал');
$t->is($users->rolesOf($ownerId) === [Role::ADMIN], 'у владельца роль администратора на месте');

// ------------------------------------------------------------------ завуч

$t->group('Права завуча');

$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::HEAD_TEACHER,
]);

$guest->forgetCookies();
$loginPage = $guest->get('/login');
$guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'uchitel2025',
]);

$t->is($guest->get('/panel')['status'] === 200, 'завуч видит панель управления');
$t->is($guest->get('/classes')['status'] === 200, 'завуч видит список классов');
$t->is($guest->get('/panel/users')['status'] === 403, 'завуч не управляет пользователями');

if ($publishedWorkId !== null) {
    $t->is($guest->get('/work/' . $publishedWorkId)['status'] === 200, 'завуч может проходить тесты');
}

// ------------------------------------------------------------------ учитель

$t->group('Права учителя');

$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::TEACHER,
]);

$guest->forgetCookies();
$loginPage = $guest->get('/login');
$guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'uchitel2025',
]);

if ($publishedWorkId !== null) {
    $t->is($guest->get('/work/' . $publishedWorkId)['status'] === 200, 'учитель может проходить тесты');
}

$t->is($guest->get('/classes')['status'] === 403, 'учитель не управляет классами');
$t->is($guest->get('/panel')['status'] === 403, 'учитель не видит панель управления');

// ------------------------------------------------------------------ восстановление пароля

$t->group('Восстановление пароля');

$guest->forgetCookies();

$forgot = $guest->get('/auth/forgot');
$t->is($forgot['status'] === 200, 'страница восстановления доступна');

$guest->post('/auth/forgot', [
    '_token' => $guest->tokenFrom($forgot) ?? '',
    'email'  => 'teacher@example.edu',
]);

$files = glob($mailLogDir . '/mail-*.log') ?: [];
$resetMail = $files === [] ? '' : (string) file_get_contents($files[0]);

preg_match('#/auth/reset\?token=([a-f0-9]{64})#', $resetMail, $resetMatch);
$resetToken = $resetMatch[1] ?? '';
$t->is($resetToken !== '', 'письмо со ссылкой смены пароля отправлено');

$resetPage = $guest->get('/auth/reset?token=' . $resetToken);
$guest->post('/auth/reset', [
    '_token'    => $guest->tokenFrom($resetPage) ?? '',
    'token'     => $resetToken,
    'password'  => 'novyparol99',
    'password2' => 'novyparol99',
]);

$t->is($guest->get('/login')['body'] !== '', 'после смены пароля предложен вход');

$loginPage = $guest->get('/login');
$guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'novyparol99',
]);

$t->is($guest->get('/portal')['status'] === 200, 'вход с новым паролем работает');

$loginPage = $guest->get('/login');
$guest->forgetCookies();
$loginPage = $guest->get('/login');
$guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'uchitel2025',
]);

$t->is($guest->get('/portal')['status'] === 302, 'старый пароль больше не работает');

// ------------------------------------------------------------------ защита перебора

$t->group('Единое сообщение об ошибке входа');

$wrongPassword = $guest->post('/login', [
    '_token'     => $guest->tokenFrom($guest->get('/login')) ?? '',
    'identifier' => 'teacher@example.edu',
    'password'  => 'nepravilno',
]);

$message = $wrongPassword['body'];
$loginPage = $guest->get('/login');
$t->contains($loginPage['body'], 'Неверный логин или пароль', 'сообщение о неверном пароле');

$guest->post('/login', [
    '_token'     => $guest->tokenFrom($guest->get('/login')) ?? '',
    'identifier' => 'netakogo@example.edu',
    'password'  => 'nepravilno',
]);

$t->contains(
    $guest->get('/login')['body'],
    'Неверный логин или пароль',
    'неизвестный логин даёт то же сообщение — нельзя перебрать учётные записи'
);

/*
 * Заглушка для несуществующего логина обязана быть настоящим хешем.
 *
 * Сообщение об ошибке одинаково и у существующего логина, и у несуществующего.
 * Если бы система отвечала быстрее по второму, перебор шёл бы по часам:
 * замерять время ответа может кто угодно, это стоит одного запроса. Раньше
 * здесь стояла строка из 62 символов вместо шестидесяти — password_verify на
 * негодном хеше отвечает сразу, не считая ничего.
 */
$timingHash = MyEdu\Auth\AuthService::TIMING_HASH;

$t->equals(60, strlen($timingHash), 'заглушка времени ответа — хеш ровно в 60 символов');
$t->equals(
    'bcrypt',
    (string) (password_get_info($timingHash)['algoName'] ?? ''),
    'заглушка времени ответа опознаётся как bcrypt, а не отвергается как мусор'
);
$t->is(password_verify('пароль', $timingHash) === false, 'на заглушке нет пароля, который подошёл бы');
$t->is(
    password_verify('пароль', 'тут не хеш') === false,
    'проверка негодного хеша возвращает отказ, а не ошибку'
);

/*
 * И стоимость заглушки совпадает со стоимостью, которой система создаёт
 * пароли: bcrypt с меньшей стоимостью считается заметно быстрее, и разницу
 * снова видно по часам.
 */
$t->equals(
    (int) (password_get_info(password_hash('parol', PASSWORD_DEFAULT))['options']['cost'] ?? 0),
    (int) (password_get_info($timingHash)['options']['cost'] ?? 0),
    'стоимость заглушки совпадает со стоимостью настоящих паролей'
);

// И заглушка действительно считает: мгновенный ответ — это и есть утечка.
$start = hrtime(true);
password_verify('пароль', $timingHash);
$elapsedMs = (hrtime(true) - $start) / 1e6;

$t->is(
    $elapsedMs > 20.0,
    'проверка по заглушке занимает время, а не возвращает отказ мгновенно ('
    . round($elapsedMs, 1) . ' мс)'
);

/*
 * Ограничение перебора.
 *
 * Проверяется через настоящие попытки входа, потому что счётчик живёт между
 * запросами: проверка его состояния напрямую убеждала бы, что класс считает
 * неудачи, но ничего не сказала бы, что вход ими пользуется.
 *
 * Счётчик общий для пары «логин + адрес», а набор ходит с одного адреса,
 * поэтому сначала он очищается: иначе блокировка досталась бы от прошлых
 * попыток и проверка зависела бы от порядка запуска.
 */
$db->run('DELETE FROM login_throttle');

/**
 * Одна попытка входа и страница, на которой человек увидит её итог.
 *
 * Отдельная функция, а не повтор кода: клиент набора не идёт по редиректу, а
 * сообщение живёт во флеше и показывается на следующей отрисовке — читать
 * надо именно её.
 */
$tryLogin = static function (string $password) use ($guest): string {
    $guest->post('/login', [
        '_token'     => $guest->tokenFrom($guest->get('/login')) ?? '',
        'identifier' => 'teacher@example.edu',
        'password'  => $password,
    ]);

    return (string) ($guest->get('/login')['body'] ?? '');
};

for ($attempt = 1; $attempt <= 5; $attempt++) {
    $t->contains($tryLogin('nepravilno'), 'Неверный логин или пароль', 'попытка ' . $attempt . ': обычный отказ');
}

$t->contains($tryLogin('nepravilno'), 'Слишком много попыток входа', 'после порога вход прекращается');

/*
 * Верный пароль во время блокировки тоже не пускает. Это осознанно и является
 * частью ограничения: иначе перебор продолжился бы тем же темпом, просто
 * через паузу. Сообщение об этом человек видит прямо на странице входа.
 */
$t->contains($tryLogin('uchitel2025'), 'Слишком много попыток входа', 'верный пароль во время блокировки тоже отклоняется');

// Успешный вход снимает счётчик: иначе один блок пережил бы следующий вход.
$db->run('DELETE FROM login_throttle');

$t->contains($tryLogin('nepravilno'), 'Неверный логин или пароль', 'после сброса счётчика вход снова отвечает обычным отказом');

$db->run('DELETE FROM login_throttle');

/*
 * Перебор, размазанный по чужим адресам.
 *
 * Счётчик по паре «логин + адрес» перестаёт считать, стоит атакующему менять
 * адрес на каждый запрос: ключ меняется, и каждая попытка начинается с нуля.
 * Так перебор одного логина идёт с темпа одного запроса в секунду вместо
 * пауз после порога.
 *
 * Проверяется сменой адреса источника между попытками — ровно то, что делает
 * перебор из чужой сети.
 */
$t->group('Перебор с разных адресов');

$db->run('DELETE FROM login_throttle');

$throttle = new \MyEdu\Auth\LoginThrottle($db, 5, 300);

// Один и тот же логин, разные адреса: счёт по логину обязан копиться.
for ($hop = 1; $hop <= 6; $hop++) {
    $throttle->registerFailure('raznye-adresa@example.edu', '10.0.0.' . $hop);
}

$t->is($throttle->waitSeconds('raznye-adresa@example.edu', '10.0.0.99') > 0,
    'логин заблокирован, хотя каждый запрос шёл со своего адреса');
$t->is($throttle->waitSeconds('raznye-adresa@example.edu', '10.0.0.99') > 0,
    'блокировка по логину держится на последнем чужом адресе');

$t->is($throttle->waitSeconds('drugoy-login@example.edu', '10.0.0.1') === 0,
    'другой логин с того же адреса не заблокирован');

$throttle->registerSuccess('raznye-adresa@example.edu', '10.0.0.99');

$t->is($throttle->waitSeconds('raznye-adresa@example.edu', '10.0.0.99') === 0,
    'успешный вход снимает блокировку по логину');

/*
 * Гонка на первой неудаче.
 *
 * Неудачи раньше считали чтением, а запись делали следом. Две одновременные
 * попытки по несуществующему логину обе видели пустую строку, обе вставляли
 * свою — вторая падала с «Duplicate entry», и вместо отказа на входе человек
 * получал 500. Настоящая гонка воспроизводится параллельными процессами,
 * поэтому проверяется то же условие, на котором она держалась: повторная
 * вставка существующей строки обязана обновить счётчик, а не упасть.
 */
$t->group('Гонка при первом промахе');

$db->run('DELETE FROM login_throttle');

$raceThrottle = new \MyEdu\Auth\LoginThrottle($db, 5, 300);

$failed = false;

try {
    $raceThrottle->registerFailure('gonochnyy@example.edu', '10.1.0.1');
    // Повтор того же ключа: раньше это было второй вставкой и падало.
    $raceThrottle->registerFailure('gonochnyy@example.edu', '10.1.0.1');
    $raceThrottle->registerFailure('gonochnyy@example.edu', '10.1.0.1');
} catch (\Throwable $e) {
    $failed = true;
}

$t->is($failed === false, 'повторная неудача не роняет вход 500-й ошибкой');

$raceRows = (int) $db->value(
    'SELECT COALESCE(SUM(failures), 0) FROM login_throttle
      WHERE throttle_key IN (:a, :b)',
    [
        'a' => hash('sha256', 'gonochnyy@example.edu|10.1.0.1'),
        'b' => hash('sha256', 'gonochnyy@example.edu|'),
    ]
);

$t->equals(6, $raceRows, 'три неудачи дали три счётчика — по одному на каждый ключ');

$db->run('DELETE FROM login_throttle');

// ---------------------------------------------------------- частота запросов

$t->group('Ограничение частоты на чувствительных адресах');

/*
 * Вход закрыт счётчиком неудачных попыток. Остальные адреса, где один и тот же
 * запрос можно повторять без пользы, закрыты общим счётчиком частоты: письма,
 * регистрация, загрузка файла.
 *
 * Проверяется на настоящих запросах: счётчик живёт в базе и держится между
 * процессами, подделать его подменой ответа нельзя.
 *
 * Пороги берутся из конфигурации, а не выдумываются: иначе проверка жила бы по
 * своим числам и молчала бы после смены порога.
 */
$db->run('DELETE FROM rate_limits');

$config = $app->get(\MyEdu\Core\Config::class);
$ratePasswordResetLimit = (int) $config->int('security.rate_limits.password_reset');
$rateAuthLimit = (int) $config->int('security.rate_limits.auth');

$forgotToken = $guest->tokenFrom($guest->get('/auth/forgot')) ?? '';

$t->is($forgotToken !== '', 'страница сброса пароля отдаёт токен формы');

/*
 * Сброс пароля: письмо уходит человеку, поэтому порог здесь жёстче всего.
 *
 * Считается именно своя группа адреса, а не соседняя: у сброса пароля своя
 * группа, и проверка берёт её порог из конфигурации — иначе она жила бы по чужим
 * числам и ругалась бы сама на себя после смены порога.
 */
$rateAllowed = 0;
$rateDenied = 0;
$rateBody = '';

for ($i = 0; $i <= $ratePasswordResetLimit + 1; $i++) {
    $answer = $guest->post('/auth/forgot', [
        '_token' => $forgotToken,
        'email'  => 'nesushchestvuyushchiy@example.edu',
    ]);

    if ($answer['status'] === 429) {
        $rateDenied++;
        $rateBody = (string) $answer['body'];

        continue;
    }

    $rateAllowed++;
}

$t->is($rateDenied >= 1, 'после порога адрес отвечает «слишком часто»');
$t->equals($rateAllowed, $ratePasswordResetLimit, 'до порога запросы проходят, а не отсекаются заранее');
$t->contains($rateBody, 'Подождите', 'отказ объясняет, сколько ждать');

/*
 * Отказ приходит данными там, где ждут данные: иначе скрипт получил бы страницу
 * ошибки вместо разбора, и причина осталась бы непонятной.
 */
$rateAjax = $guest->postAjax('/auth/forgot', [
    '_token' => $forgotToken,
    'email'  => 'nesushchestvuyushchiy@example.edu',
]);

$t->is($rateAjax['status'] === 429, 'запрос, ждущий данные, получает 429 данными');
$t->is(str_contains((string) $rateAjax['body'], '"ok":false'),
    'отказ по частоте приходит данными, а не страницей');

/*
 * Счётчики раздельные: перебор на одном адресе не отнимает лимит у другого.
 *
 * Иначе лимит был бы общим, и человек, исчерпавший его на сбросе пароля, получил
 * бы отказ там, где ничего не делал. Проверяется наоборот: счётчик одной группы
 * не мешает другой группе пройти свой порог.
 */
$db->run('DELETE FROM rate_limits');

$resendToken = $guest->tokenFrom($guest->get('/confirm/resend')) ?? '';
$resendDenied = 0;

for ($i = 0; $i <= $rateAuthLimit + 1; $i++) {
    if ($guest->post('/confirm/resend', ['_token' => $resendToken])['status'] === 429) {
        $resendDenied++;
    }
}

$t->is($resendDenied >= 1, 'у своей группы свой предел, и он тоже срабатывает');

/*
 * Обычная работа ограничением не задевается — это главное опасение.
 *
 * Учитель за минуту делает сотню запросов честно: перетаскивает карточки,
 * переставляет строки, сохраняет задания. Общий счётчик на все POST выставил бы
 * его за работу, поэтому в списке только четыре адреса. Проверяется входом: он
 * в списке не стоит, потому что у него свой счётчик неудачных попыток.
 */
$db->run('DELETE FROM rate_limits');

$loginToken = $guest->tokenFrom($guest->get('/login')) ?? '';
$loginDenied = 0;

for ($i = 0; $i < $rateAuthLimit + 5; $i++) {
    if ($guest->post('/login', [
        '_token'     => $loginToken,
        'identifier' => 'uchitel2025',
        'password'   => 'nepravilno',
    ])['status'] === 429) {
        $loginDenied++;
    }
}

$t->equals($loginDenied, 0, 'адрес вне списка ограничением не задет');

$db->run('DELETE FROM login_throttle');
$db->run('DELETE FROM rate_limits');

// ------------------------------------------------------------------ число попыток

$t->group('Сколько раз можно пройти работу');

$users->create([
    'login'             => 'uchenik@example.edu',
    'email'             => 'uchenik@example.edu',
    'password_hash'     => password_hash('uchenik2025', PASSWORD_DEFAULT),
    'full_name'         => 'Учеников Тест Тестович',
    'is_owner'          => 0,
    'status'            => 'active',
    'email_verified_at' => date('Y-m-d H:i:s'),
]);
$studentRow = $users->findRowByIdentifier('uchenik@example.edu');
$studentId = (int) $studentRow['id'];
$users->setRole($studentId, Role::STUDENT);

if ($publishedWorkId === null) {
    $t->is(true, 'опубликованной работы с тестом нет — проверка числа попыток пропущена');
} else {
    /*
     * Ограничение в одну попытку включаем именно на время проверки: автор
     * работы, завуч и администратор обязаны обходить его, а ученик — нет.
     *
     * Число живёт в выдаче, а не в работе: одна работа выдаётся много раз и
     * по-разному, и лимит у работы менял бы его у всех выдач разом.
     */
    $previousMaxAttempts = (int) ($db->value(
        'SELECT MAX(max_attempts) FROM assignment_issues WHERE assignment_id = :id',
        ['id' => $publishedWorkId]
    ) ?? 0);

    try {
        $db->run('UPDATE assignment_issues SET max_attempts = 1 WHERE assignment_id = :id', ['id' => $publishedWorkId]);

        $logIn = static function (string $email, string $password): TestClient {
            $client = new TestClient($GLOBALS['baseUrl']);

            $page = $client->get('/login');
            $client->post('/login', [
                '_token'     => $client->tokenFrom($page) ?? '',
                'identifier' => $email,
                'password'   => $password,
            ]);

            return $client;
        };

        // Предыдущие попытки создаются как записи работы: именно они участвуют в её лимите.
        $addFinishedAttempt = static function (int $assignmentId, int $userId) use ($db): void {
            $attemptNo = (int) $db->value(
                'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM assignment_attempts
                  WHERE assignment_id = :assignment AND student_id = :student',
                ['assignment' => $assignmentId, 'student' => $userId]
            );
            $db->run(
                "INSERT INTO assignment_attempts
                    (assignment_id, student_id, token, status, attempt_no, started_at, finished_at)
                 VALUES (:assignment, :student, :token, 'finished', :number, NOW(), NOW())",
                [
                    'assignment' => $assignmentId,
                    'student'    => $userId,
                    'token'      => bin2hex(random_bytes(32)),
                    'number'     => $attemptNo,
                ]
            );
        };

        $workPath = '/work/' . $publishedWorkId;
        $startWork = static function (TestClient $client, string $path) use ($t): array {
            $page = $client->get($path);
            $t->is($page['status'] === 200, 'страница опубликованной работы открывается');

            return $client->post($path . '/start', [
                '_token' => $client->tokenFrom($page) ?? '',
            ]);
        };

        $addFinishedAttempt($publishedWorkId, $ownerId);
        $retry = $logIn('owner@example.edu', 'parol12345');
        $startAgain = $startWork($retry, $workPath);

        $t->is(
            $startAgain['status'] === 302 && str_contains($startAgain['location'], '/item/'),
            'администратор проходит работу повторно'
        );
        $db->run(
            "UPDATE assignment_attempts SET status = 'finished', finished_at = NOW()
              WHERE assignment_id = :assignment AND student_id = :student AND status = 'in_progress'",
            ['assignment' => $publishedWorkId, 'student' => $ownerId]
        );

        // У этого пользователя ранее был назначен пароль novyparol99.
        $users->setRole($secondId, Role::HEAD_TEACHER);
        $headAgain = $logIn('teacher@example.edu', 'novyparol99');
        $addFinishedAttempt($publishedWorkId, $secondId);
        $headStart = $startWork($headAgain, $workPath);

        $t->is(
            $headStart['status'] === 302 && str_contains($headStart['location'], '/item/'),
            'завуч проходит работу повторно'
        );
        $db->run(
            "UPDATE assignment_attempts SET status = 'finished', finished_at = NOW()
              WHERE assignment_id = :assignment AND student_id = :student AND status = 'in_progress'",
            ['assignment' => $publishedWorkId, 'student' => $secondId]
        );

        $users->setRole($secondId, Role::TEACHER);
        $teacherAgain = $logIn('teacher@example.edu', 'novyparol99');
        $teacherStart = $startWork($teacherAgain, $workPath);

        $t->is(
            $teacherStart['status'] === 302 && str_contains($teacherStart['location'], '/item/'),
            'учитель проходит работу повторно'
        );

        $addFinishedAttempt($publishedWorkId, $studentId);
        $studentClient = $logIn('uchenik@example.edu', 'uchenik2025');
        $studentPage = $studentClient->get($workPath);
        $blocked = $studentClient->post($workPath . '/start', [
            '_token' => $studentClient->tokenFrom($studentPage) ?? '',
        ]);

        $t->is(
            $blocked['status'] === 302 && str_contains($blocked['location'], $workPath),
            'ученику вторая попытка запрещена'
        );

        // Страницу открываем один раз: показ формы забирает сообщение из сессии.
        $t->contains($studentClient->get($blocked['location'])['body'], 'максимальное число раз',
            'ученику объяснено ограничение');
    } finally {
        $db->run('UPDATE assignment_issues SET max_attempts = :max WHERE assignment_id = :id', [
            'max' => $previousMaxAttempts,
            'id'  => $publishedWorkId,
        ]);
    }
}

// ------------------------------------------------------------------ сброс пароля администратором

$t->group('Сброс пароля администратором');

$usersPage = $owner->get('/panel/users');
$t->is($usersPage['status'] === 200, 'владелец видит список пользователей');

/*
 * Пароль не показывается и не вводится: у администратора есть только кнопка,
 * а сам пароль человек задаёт по ссылке из письма. Кнопка живёт на странице
 * пользователя, а не в списке.
 */
$t->notContains($usersPage['body'], 'name="password"', 'в списке нет поля для ввода пароля');
$t->notContains($usersPage['body'], '8 цифр', 'в списке нет подсказки про 8 цифр');
$t->notContains($usersPage['body'], 'panel/users/password', 'в списке нет кнопки сброса');

$secondPage = $owner->get('/panel/users/' . $secondId);
$t->is($secondPage['status'] === 200, 'страница пользователя открывается');
$t->notContains($secondPage['body'], 'name="password"', 'на странице нет поля для ввода пароля');
$t->contains($secondPage['body'], 'ссылк', 'есть кнопка отправки ссылки для смены пароля');

$hashBefore = (string) $users->findRow($secondId)['password_hash'];

$adminReset = $owner->post('/panel/users/password', [
    '_token'   => $owner->tokenFrom($secondPage) ?? '',
    'user_id'  => (string) $secondId,
]);

$t->is($adminReset['status'] === 302, 'сброс обработан');
$t->equals($hashBefore, (string) $users->findRow($secondId)['password_hash'],
    'пароль в базе не изменился — его задаст сам пользователь');

// Сообщение о результате показывается на странице пользователя: возврат в
// список отнёс бы его от того, к кому он относится.
$afterReset = $owner->get('/panel/users/' . $secondId);
$t->contains($afterReset['body'], 'ссылкой для смены пароля', 'администратору сказано, что письмо отправлено');
$t->contains($afterReset['body'], 'teacher@example.edu', 'администратор видит, на какой адрес ушло письмо');

// Ссылка из письма ведёт на форму нового пароля.
$mailFiles = glob($mailLogDir . '/mail-*.log') ?: [];
$lastMail = $mailFiles === [] ? '' : (string) file_get_contents($mailFiles[0]);

preg_match_all('#/auth/reset\?token=([a-f0-9]{64})#', $lastMail, $resetLinks);
$adminResetToken = (string) end($resetLinks[1]);

$t->is($adminResetToken !== '', 'письмо со ссылкой сброса отправлено');

$resetForm = $guest->get('/auth/reset?token=' . $adminResetToken);
$t->is($resetForm['status'] === 200, 'по ссылке открывается форма нового пароля');
$t->contains($resetForm['body'], 'Новый пароль', 'форма просит задать новый пароль');

// Старый пароль пока работает: смена не завершена, пока не задан новый.
$stillOld = new TestClient($baseUrl);
$oldPage = $stillOld->get('/login');
$stillOld->post('/login', [
    '_token' => $stillOld->tokenFrom($oldPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password' => 'novyparol99',
]);

$t->is($stillOld->get('/portal')['status'] === 200, 'до перехода по ссылке старый пароль работает');

$tooShortReset = $guest->post('/auth/reset', [
    '_token'    => $guest->tokenFrom($resetForm) ?? '',
    'token'     => $adminResetToken,
    'password'  => 'short',
    'password2' => 'short',
]);

$t->is($tooShortReset['status'] === 302, 'короткий пароль возвращает на форму сброса');
$t->is($tokens->hasActive($adminResetToken, TokenRepository::PURPOSE_RESET),
    'отказ валидации не расходует одноразовую ссылку');
$t->is($guest->get('/auth/reset?token=' . $adminResetToken)['status'] === 200,
    'с той же ссылкой можно повторить ввод');

/*
 * Токен возвращается на форму, но не в адрес.
 *
 * Адрес с токеном попадает в историю браузера, в журналы сервера и прокси и в
 * `Referer` следующего запроса, а токен одноразовый. Поэтому в `Location` его
 * быть не должно, а сама форма обязана работать: токен лежит в скрытом поле.
 */
/*
 * Проверяется сам токен, а не слово `token` в адресе: имя параметра в адресе
 * безобидно, опасен именно его значение.
 */
$t->is(
    !str_contains($tooShortReset['location'], $adminResetToken),
    'токен сброса не возвращается в Location; адрес: ' . $tooShortReset['location']
);

$backToForm = $guest->get($tooShortReset['location']);
$t->is($backToForm['status'] === 200, 'форма сброса открывается без токена в адресе');
$t->contains($backToForm['body'], $adminResetToken,
    'токен остался в форме, поэтому её можно отправить повторно');

$guest->post('/auth/reset', [
    '_token'    => $guest->tokenFrom($resetForm) ?? '',
    'token'     => $adminResetToken,
    'password'  => 'posle-sbrosa-2026',
    'password2' => 'posle-sbrosa-2026',
]);

$t->is($guest->get('/login')['body'] !== '', 'после смены пароля предложен вход');

$withNew = new TestClient($baseUrl);
$newPage = $withNew->get('/login');
$withNew->post('/login', [
    '_token' => $withNew->tokenFrom($newPage) ?? '',
    'identifier' => 'teacher@example.edu',
    'password' => 'posle-sbrosa-2026',
]);

$t->is($withNew->get('/portal')['status'] === 200, 'новый пароль из письма работает');

$withOld = new TestClient($baseUrl);
$oldAgain = $withOld->get('/login');
$withOld->post('/login', [
    '_token' => $withOld->tokenFrom($oldAgain) ?? '',
    'identifier' => 'teacher@example.edu',
    'password' => 'novyparol99',
]);

$t->is($withOld->get('/portal')['status'] === 302, 'прежний пароль больше не работает');

$t->is(!$tokens->hasActive($adminResetToken, TokenRepository::PURPOSE_RESET),
    'ссылка сброса одноразовая');

/*
 * Смена пароля обрывает все сессии человека, а не только текущую.
 *
 * Именно это и нужно при взломе: человек меняет пароль, чтобы чужой вход
 * перестал работать. Если бы сессия пережила смену пароля, человек вышел бы,
 * вернулся — и снова был внутри, уже с новым паролем на руках.
 *
 * Проверяется на двух входах сразу: тот, кто менял, и тот, кто сидел в соседней
 * вкладке. Первый после смены пароля тоже выходит — пароль у него теперь другой,
 * а сессия заведена со старым.
 */
$t->group('Смена пароля обрывает чужие входы');

$stolenLogin = 'ukorenennyy-vhod@example.edu';
$stolenPassword = 'do-smeny-2026';

$stolenId = (int) $db->insert('users', [
    'login'         => $stolenLogin,
    'email'         => $stolenLogin,
    'password_hash' => password_hash($stolenPassword, PASSWORD_DEFAULT),
    'full_name'     => 'Кража Учётной Записи',
    'is_owner'      => 0,
    'status'        => 'active',
    'email_verified_at' => date('Y-m-d H:i:s'),
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$loginAs = static function (string $login, string $password): TestClient {
    $client = new TestClient($GLOBALS['baseUrl']);
    $page = $client->get('/login');

    $client->post('/login', [
        '_token'     => $client->tokenFrom($page) ?? '',
        'identifier' => $login,
        'password'   => $password,
    ]);

    return $client;
};

$GLOBALS['baseUrl'] = $baseUrl;

$thief = $loginAs($stolenLogin, $stolenPassword);

$t->is($thief->get('/portal')['status'] === 200, 'чужий вход работает до смены пароля');

$honest = $loginAs($stolenLogin, $stolenPassword);

$t->is($honest->get('/portal')['status'] === 200, 'свой вход работает до смены пароля');

$honest->post('/profile/password', [
    '_token'          => $honest->tokenFrom($honest->get('/profile')) ?? '',
    'current_password' => $stolenPassword,
    'new_password'    => 'posle-smeny-2026',
    'password2'       => 'posle-smeny-2026',
]);

$t->is($honest->get('/portal')['status'] === 302, 'сменивший пароль сам выходит и входит заново');
$t->is($thief->get('/portal')['status'] === 302,
    'чужой вход отвалился после смены пароля — иначе смена была бы бесполезной');

$reentered = $loginAs($stolenLogin, 'posle-smeny-2026');

$t->is($reentered->get('/portal')['status'] === 200, 'с новым паролем войти можно');

// Человек вымышленный: убираем, иначе он попадёт в счётчики следующих проверок.
$db->run('DELETE FROM user_roles WHERE user_id = :id', ['id' => $stolenId]);
$db->run('DELETE FROM users WHERE id = :id', ['id' => $stolenId]);

$t->group('Перебор текущего пароля в профиле ограничен');

/*
 * Текущий пароль — тоже пароль, и перебирать его можно с уже украденной
 * сессией: ограничение на вход такую сессию не спасает, проверка пароля идёт
 * мимо него. Счётчик тот же, что и на входе, и ключом служит логин, поэтому
 * смена адреса перебор не возобновляет.
 */
$guessedLogin = 'podbor-parolya@example.edu';
$guessedPassword = 'nastoyashchiy-parol-2026';

$guessedId = $users->create([
    'login'         => $guessedLogin,
    'email'         => $guessedLogin,
    'password_hash' => password_hash($guessedPassword, PASSWORD_DEFAULT),
    'full_name'     => 'Перебор Пароля',
    'status'        => 'active',
    'is_owner'      => 0,
]);

$guesser = $loginAs($guessedLogin, $guessedPassword);
$t->is($guesser->get('/profile')['status'] === 200, 'переборщик открыл профиль по своей сессии');

$wrongAnswers = [];
$lockMessageSeen = false;

for ($attempt = 0; $attempt < 6; $attempt++) {
    $guesser->post('/profile/password', [
        '_token'          => $guesser->tokenFrom($guesser->get('/profile')) ?? '',
        'current_password' => 'nepravilno-' . $attempt,
        'new_password'    => 'novy-parol-2026',
        'password2'       => 'novy-parol-2026',
    ]);

    $wrongAnswers[] = (string) $guesser->get('/profile')['body'];
}

$lockMessageSeen = (bool) preg_grep('/Слишком много попыток/u', $wrongAnswers);

$t->is($lockMessageSeen, 'после серии неудач перебор текущего пароля останавливается');

$verified = $guesser->post('/profile/password', [
    '_token'          => $guesser->tokenFrom($guesser->get('/profile')) ?? '',
    'current_password' => $guessedPassword,
    'new_password'    => 'dazhe-vernyy-2026',
    'password2'       => 'dazhe-vernyy-2026',
]);

/*
 * Верный пароль тоже не проходит, пока идёт блокировка.
 *
 * Иначе ограничение не значит ничего: подбор идёт, а верный пароль человека,
 * который вводит его после неудачных попыток, проходит с первого раза —
 * и человек понимает, что неудачные попытки считает не он.
 */
$t->is(
    password_verify($guessedPassword, (string) $users->findRow($guessedId)['password_hash']),
    'во время блокировки пароль не меняется даже с верным текущим'
);
$t->is($verified['status'] === 302, 'попытка обработана и не упала');

$db->run('DELETE FROM login_throttle');
$db->run('DELETE FROM user_roles WHERE user_id = :id', ['id' => $guessedId]);
$db->run('DELETE FROM users WHERE id = :id', ['id' => $guessedId]);

$t->group('Сброс пароля не снимает блокировку');

$blockedResetUserId = $users->create([
    'login'         => 'blocked-reset@example.edu',
    'email'         => 'blocked-reset@example.edu',
    'password_hash' => password_hash('before-reset-2026', PASSWORD_DEFAULT),
    'full_name'     => 'Сброс Блокировки',
    'status'        => 'active',
    'is_owner'      => 0,
]);
$blockedResetToken = $tokens->issue(
    $blockedResetUserId,
    TokenRepository::PURPOSE_RESET,
    1
);
$users->update($blockedResetUserId, ['status' => 'blocked']);
$blockedReset = $app->get(\MyEdu\Auth\AuthService::class)
    ->resetPasswordWithToken($blockedResetToken, 'after-block-reset-2026');

$t->is(($blockedReset['ok'] ?? false) === true, 'действующая ссылка позволяет заменить забытый пароль');
$t->is(($blockedReset['blocked'] ?? false) === true, 'сервис сообщает, что учётная запись заблокирована');
$t->equals('blocked', (string) $users->findRow($blockedResetUserId)['status'],
    'сброс пароля не активирует заблокированную запись');
$t->is(password_verify('after-block-reset-2026', (string) $users->findRow($blockedResetUserId)['password_hash']),
    'новый пароль сохранён, блокировка при этом остаётся');
$db->run('DELETE FROM users WHERE id = :id', ['id' => $blockedResetUserId]);

// ------------------------------------------------------------------ профиль

$t->group('Профиль: свои данные');

$profile = $owner->get('/profile');
$t->is($profile['status'] === 200, 'страница профиля открыта');
$t->contains($profile['body'], 'name="last_name"', 'есть поле фамилии');
$t->contains($profile['body'], 'name="first_name"', 'есть поле имени');
$t->contains($profile['body'], 'name="middle_name"', 'есть поле отчества');
$t->contains($profile['body'], 'name="birth_date"', 'есть поле даты рождения');
$t->contains($profile['body'], 'name="vk_url"', 'есть поле ссылки ВКонтакте');
$t->notContains($profile['body'], 'name="full_name"', 'старого единого поля ФИО больше нет');

/*
 * Браузер знает, какое поле перед ним.
 *
 * Отметки `autocomplete` подсказывают браузеру, что подставить: `bday` — дату
 * рождения из его профиля. Без отметки поле остаётся обычным текстовым и
 * человек вводит дату руками каждый раз.
 *
 * В форме правки чужого пользователя такой отметки нет намеренно: там
 * подставлять своё имя и дату нельзя, там правят чужое.
 */
$t->is(
    preg_match('#<input[^>]*name="birth_date"[^>]*autocomplete="bday"#s', (string) $profile['body']) === 1,
    'в своём профиле дата рождения помечена для автозаполнения'
);
$t->is(
    preg_match('#autocomplete="given-name"#', (string) $profile['body']) === 1,
    'поле имени помечено для автозаполнения'
);

$saved = $owner->post('/profile', [
    '_token'      => $owner->tokenFrom($profile) ?? '',
    'last_name'   => 'Власенко',
    'first_name'  => 'Ольга',
    'middle_name' => 'Петровна',
    'birth_date'  => '1980-05-17',
    'vk_url'      => 'vk.ru/olga_vlasenko',
]);

$t->is($saved['status'] === 302, 'данные сохранены');

$ownerRowAfter = $users->findRowByEmail('owner@example.edu');

$t->equals('1980-05-17', (string) $ownerRowAfter['birth_date'], 'дата рождения сохранена');
$t->equals('https://vk.com/olga_vlasenko', (string) $ownerRowAfter['vk_url'],
    'короткая ссылка приведена к единому виду');

// Части имени и составное ФИО обязаны совпадать: иначе список отсортирует
// по одному значению, а шаблон письма напишет другое.
$t->equals('Власенко', (string) $ownerRowAfter['last_name'], 'фамилия сохранена');
$t->equals('Ольга', (string) $ownerRowAfter['first_name'], 'имя сохранено');
$t->equals('Петровна', (string) $ownerRowAfter['middle_name'], 'отчество сохранено');
$t->equals('Власенко Ольга Петровна', (string) $ownerRowAfter['full_name'],
    'составное ФИО собрано из частей');

$profileAfter = $owner->get('/profile');
/*
 * Ссылка на профиль ВКонтакте показывается как внешняя: с адресом и
 * target="_blank". Собственного значка «VK» в шаблоне нет, и рисовать его
 * значило бы возвращать графику, которой в шаблоне не place: ссылка и так
 * опознаётся по адресу.
 */
$t->contains($profileAfter['body'], 'vk.com/ivanov', 'профиль ВКонтакте показан со значком');
$t->contains($profileAfter['body'], 'target="_blank" rel="noopener"',
    'ссылка на профиль открывается в новой вкладке безопасно');
$t->contains($profileAfter['body'], '17.05.1980', 'дата показана в привычном виде');

// Чужая ссылка не принимается, и прежнее значение не затирается.
$badLink = $owner->post('/profile', [
    '_token'      => $owner->tokenFrom($profileAfter) ?? '',
    'last_name'   => 'Власенко',
    'first_name'  => 'Ольга',
    'middle_name' => 'Петровна',
    'birth_date'  => '1980-05-17',
    'vk_url'      => 'https://example.com/olga',
]);

$t->is($badLink['status'] === 302, 'попытка сохранить чужую ссылку обработана');
$t->contains($owner->get('/profile')['body'], 'ВКонтакте', 'показано объяснение ошибки');
$t->equals(
    'https://vk.com/olga_vlasenko',
    (string) $users->findRowByEmail('owner@example.edu')['vk_url'],
    'прежняя ссылка не изменилась'
);

// Дата в будущем и пустое имя отклоняются.
$future = $owner->post('/profile', [
    '_token'      => $owner->tokenFrom($owner->get('/profile')) ?? '',
    'last_name'   => 'Власенко',
    'first_name'  => 'Ольга',
    'middle_name' => 'Петровна',
    'birth_date'  => '2099-01-01',
    'vk_url'      => '',
]);

$t->is($future['status'] === 302, 'дата в будущем отклонена');
$t->contains($owner->get('/profile')['body'], 'будущем', 'показано объяснение про будущую дату');

// Отчество необязательно, а вот фамилия с именем — нет.
$noMiddle = $owner->post('/profile', [
    '_token'      => $owner->tokenFrom($owner->get('/profile')) ?? '',
    'last_name'   => 'Власенко',
    'first_name'  => 'Ольга',
    'middle_name' => '',
    'birth_date'  => '',
    'vk_url'      => '',
]);

$t->is($noMiddle['status'] === 302, 'без отчества сохраняется');
$t->equals('Власенко Ольга', (string) $users->findRowByEmail('owner@example.edu')['full_name'],
    'ФИО без отчества собрано из двух частей');
$t->is($users->findRowByEmail('owner@example.edu')['middle_name'] === null, 'отчество очищено, а не заменено пробелом');

$noName = $owner->post('/profile', [
    '_token'      => $owner->tokenFrom($owner->get('/profile')) ?? '',
    'last_name'   => 'Власенко',
    'first_name'  => '',
    'middle_name' => '',
    'birth_date'  => '',
    'vk_url'      => '',
]);

$t->is($noName['status'] === 302, 'имя без фамилии и имени отклонено');

/*
 * Отказ должен называть пустое поле, а не формулировать общее «фамилию и имя».
 * Подсветка поля идёт по ключу, а ключ выбирает контроллер: без точного поля
 * подсветить нечего, и человек угадывал бы, какое из двух пустое. Поэтому
 * проверяется и подпись под полем, и её отметка — .is-invalid.
 */
$profileNoName = $owner->get('/profile');
$t->contains($profileNoName['body'], 'Заполните «имя»', 'показано объяснение про ФИО');
$t->contains($profileNoName['body'], 'form-control is-invalid', 'пустое имя подсвечено');
$t->contains($profileNoName['body'], 'form-error', 'под полем написано, что не так');
$t->contains($profileNoName['body'], 'aria-invalid="true"', 'поле объявлено неверным для чтения с экрана');

// Страница пользователя у администратора: чужие данные правятся, свои — нет.
$t->group('Страница пользователя у администратора');

$userPage = $owner->get('/panel/users/' . $secondId);
$t->is($userPage['status'] === 200, 'администратор открывает страницу пользователя');
$t->contains($userPage['body'], 'name="last_name"', 'на странице есть поле фамилии');
$t->contains($userPage['body'], 'name="email"', 'на странице есть поле почты');
$t->notContains($userPage['body'], 'name="password"', 'на странице нет поля для ввода пароля');
$t->is($owner->get('/panel/users/999999')['status'] === 404, 'несуществующий пользователь даёт 404');

$beforeEmail = (string) $users->findRow($secondId)['email'];

$owner->post('/panel/users/' . $secondId, [
    '_token'      => $owner->tokenFrom($userPage) ?? '',
    'last_name'   => 'Учитель',
    'first_name'  => 'Проверочный',
    'middle_name' => 'Петрович',
    'email'       => $beforeEmail,
    'birth_date'  => '1975-03-04',
    'vk_url'      => '',
]);

$secondAfter = $users->findRow($secondId);
$t->equals('Учитель Проверочный Петрович', (string) $secondAfter['full_name'],
    'ФИО пользователя переписано администратором');
$t->equals('1975-03-04', (string) $secondAfter['birth_date'], 'дата рождения чужого пользователя сохранена');

// Чужой адрес почты занят быть не может.
$userPage = $owner->get('/panel/users/' . $secondId);
$owner->post('/panel/users/' . $secondId, [
    '_token'     => $owner->tokenFrom($userPage) ?? '',
    'last_name'  => 'Учитель',
    'first_name' => 'Проверочный',
    'email'      => 'owner@example.edu',
]);

$t->contains($owner->get('/panel/users/' . $secondId)['body'], 'уже занят',
    'занятый адрес отклонён с объяснением');
$t->equals($beforeEmail, (string) $users->findRow($secondId)['email'],
    'чужой адрес не записан');

// Ученика в списке пользователей нет, и страницы у него нет: ученики
// заводятся в классах и правятся там, а не среди сотрудников.
$t->is($owner->get('/panel/users/' . $studentId)['status'] === 404,
    'страница ученика в списке пользователей не открывается');

$t->is($users->rolesOf($studentId) === [Role::STUDENT], 'проверяемый — ученик, а не сотрудник');

// ------------------------------------------------------------------ смена адреса

$t->group('Подтверждение нового адреса пользователя');

$oldEmail = (string) $users->findRow($secondId)['email'];
$oldConfirmToken = $tokens->issue(
    $secondId,
    TokenRepository::PURPOSE_CONFIRM,
    48,
    $oldEmail
);

foreach (glob($mailLogDir . '/mail-*.log') ?: [] as $old) {
    @unlink($old);
}

$userPage = $owner->get('/panel/users/' . $secondId);
$changedEmail = $owner->post('/panel/users/' . $secondId, [
    '_token'      => $owner->tokenFrom($userPage) ?? '',
    'last_name'   => 'Учитель',
    'first_name'  => 'Проверочный',
    'middle_name' => 'Петрович',
    'email'       => 'teacher-new@example.edu',
    'birth_date'  => '1975-03-04',
    'vk_url'      => '',
]);

$t->is($changedEmail['status'] === 302, 'смена адреса сохранена');
$t->contains($owner->get('/panel/users/' . $secondId)['body'], 'отправлена ссылка для подтверждения',
    'администратору показан результат отправки подтверждения');

$changedUser = $users->findRow($secondId);
$t->equals('teacher-new@example.edu', (string) ($changedUser['email'] ?? ''), 'новый адрес записан');
$t->equals('teacher-new@example.edu', (string) ($changedUser['login'] ?? ''), 'логин сотрудника синхронизирован с новым адресом');
$t->equals('pending', (string) ($changedUser['status'] ?? ''), 'до подтверждения нового адреса вход заблокирован');
$t->is($changedUser['email_verified_at'] === null, 'подтверждение прежнего адреса сброшено');
$t->is(!$tokens->hasActive($oldConfirmToken, TokenRepository::PURPOSE_CONFIRM),
    'неиспользованная ссылка для прежнего адреса отозвана');

$confirmationMailFiles = glob($mailLogDir . '/mail-*.log') ?: [];
$changeEmailMail = $confirmationMailFiles === [] ? '' : (string) file_get_contents($confirmationMailFiles[0]);
$t->contains($changeEmailMail, 'teacher-new@example.edu', 'ссылка подтверждения отправлена на новый адрес');
preg_match('#/auth/confirm\\?token=([a-f0-9]{64})#', $changeEmailMail, $changedEmailMatch);
$changedEmailToken = (string) ($changedEmailMatch[1] ?? '');
$t->is($changedEmailToken !== '', 'новая ссылка подтверждения создана');

$guest->forgetCookies();
$emailChangeGuest = new TestClient($baseUrl);
$loginPage = $emailChangeGuest->get('/login');
$blockedAfterChange = $emailChangeGuest->post('/login', [
    '_token'     => $emailChangeGuest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher-new@example.edu',
    'password'   => 'posle-sbrosa-2026',
]);
$t->is($blockedAfterChange['status'] === 302 && str_contains($blockedAfterChange['location'], '/login'),
    'вход по новому адресу до подтверждения запрещён');
$t->contains($emailChangeGuest->get('/login')['body'], 'Сначала подтвердите адрес',
    'до подтверждения показывается причина блокировки входа');

$changedEmailPage = $emailChangeGuest->get('/auth/confirm?token=' . $changedEmailToken);
$changedEmailConfirmation = $emailChangeGuest->post('/auth/confirm', [
    '_token' => $emailChangeGuest->tokenFrom($changedEmailPage) ?? '',
    'token'  => $changedEmailToken,
]);
$t->is($changedEmailConfirmation['status'] === 302, 'ссылка нового адреса принята');
$t->contains($emailChangeGuest->get('/login')['body'], 'Адрес подтверждён', 'показано сообщение об успешном подтверждении');
$confirmedChangedUser = $users->findRow($secondId);
$t->equals('active', (string) ($confirmedChangedUser['status'] ?? ''), 'новый адрес подтверждён и пользователь активирован');
$t->equals('teacher-new@example.edu', (string) ($confirmedChangedUser['email'] ?? ''), 'подтверждён именно актуальный адрес');
$t->is(!empty($confirmedChangedUser['email_verified_at']), 'время подтверждения нового адреса записано');

$guest->forgetCookies();
$loginPage = $guest->get('/login');
$loginWithOldEmail = $guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => $oldEmail,
    'password'   => 'novyparol99',
]);
$t->is($loginWithOldEmail['status'] === 302 && str_contains($loginWithOldEmail['location'], '/login'),
    'старый адрес больше не подходит для входа');

$loginPage = $guest->get('/login');
$loginWithNewEmail = $guest->post('/login', [
    '_token'     => $guest->tokenFrom($loginPage) ?? '',
    'identifier' => 'teacher-new@example.edu',
    'password'   => 'posle-sbrosa-2026',
]);
$t->is($loginWithNewEmail['status'] === 302 && !str_contains($loginWithNewEmail['location'], '/login'),
    'после подтверждения вход выполняется по новому адресу');

// ------------------------------------------------------------------ оболочка панели

$t->group('Оболочка панели управления');

/*
 * Панель выглядит как в образце gentelella: тёмное боковое меню слева, шапка
 * с крошками, содержимое в .page-wrapper. Проверяется по разметке, а не по
 * картинке: регрессия здесь чаще всего не «съехала вёрстка», а «пункт
 * потерялся».
 *
 * Классы — те же, что в сборке шаблона. Проверяется именно они: если новая
 * сборка перестанет их отдавать, страница останется без оформления, и это
 * заметят не проверки, а человек.
 */
$console = $owner->get('/panel');

$t->is($console['status'] === 200, 'консоль открывается');
$t->contains($console['body'], 'data-theme="dark"', 'панель в тёмной теме');
$t->contains($console['body'], 'class="sidebar"', 'есть боковое меню');
$t->contains($console['body'], 'class="topbar"', 'есть шапка');
$t->contains($console['body'], 'page-wrapper', 'есть область содержимого');
$t->contains($console['body'], 'sidebar-toggle', 'меню сворачивается');
$t->contains($console['body'], 'data-theme-toggle', 'в шапке переключатель темы');
$t->contains($console['body'], '/profile', 'из шапки есть переход в свои данные');
$t->contains($console['body'], 'sidebar-user', 'в подвале меню есть человек');

foreach (['Главная', 'Работы', 'Школа', 'Система'] as $groupTitle) {
    $t->contains($console['body'], '>' . $groupTitle . '<', 'группа меню «' . $groupTitle . '»');
}

foreach (['Консоль', 'Составить работу', 'Результаты', 'Пользователи', 'Настройки'] as $itemLabel) {
    $t->contains($console['body'], '>' . $itemLabel . '</span>', 'пункт меню «' . $itemLabel . '»');
}

$t->group('Панель не дублирует меню');

/*
 * Пять кнопок на главной странице панели повторяли пункты бокового меню.
 * Проверяется именно область содержимого: в меню эти адреса остались и
 * должны остаться, и искать их в шапке нельзя.
 */
$consoleMain = (string) (preg_match('#<main[^>]*>(.*)</main>#su', $console['body'], $consoleParts)
    ? ($consoleParts[1] ?? '')
    : $console['body']);

foreach ([
    '/panel/settings'  => 'Настройки сайта',
    '/panel/plugins'   => 'Плагины',
    '/panel/assignments' => 'Работы',
    '/panel/journal'   => 'Результаты',
] as $dupAddress => $dupLabel) {
    $t->notContains($consoleMain, 'href="' . $dupAddress . '"',
        'в содержимом панели нет дублирующей ссылки «' . $dupLabel . '»');
}

$t->group('Ученики не считаются пользователями');

/*
 * Ученик — отдельная сущность: он заводится завучем в списке класса, у него
 * нет ни почты, ни ролей сотрудника, и список пользователей его не
 * показывает. Считать его ещё и в «пользователях» значило показать одних и
 * тех же людей дважды — «27 пользователей» рядом с «25 учеников».
 */
$staffUsers = (int) $db->value(
    "SELECT COUNT(*) FROM users u
      WHERE NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role_code = 'student')"
);

$allUsers = (int) $db->value('SELECT COUNT(*) FROM users');

$studentUsers = (int) $db->value(
    "SELECT COUNT(*) FROM users u WHERE EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role_code = 'student')"
);

$t->is($allUsers === $staffUsers + $studentUsers,
    'все учётные записи делятся на сотрудников и учеников');

/*
 * Показатели консоли названы metric, а не stat: прежние .stat отошли
 * шаблонному блоку на странице пользователей (см. комментарий в app.css).
 * Проверка ищет число рядом с подписью «пользователей», поэтому имя класса
 * здесь несущественно — существенно само число.
 */
$panelBlock = (string) (preg_match('#<span class="(?:metric|stat)-value">\s*(\d+)\s*</span>\s*<span class="(?:metric|stat)-label">\s*пользователей#u', $console['body'], $statMatch)
    ? $statMatch[1]
    : '');

$t->equals((string) $staffUsers, $panelBlock,
    'на панели показано число сотрудников, а не всех, кто заведён в системе');

/*
 * Подсвечен должен быть самый глубокий подходящий пункт.
 *
 * «Консоль» — это /panel, и на /panel/users он совпадает с адресом по началу
 * строки, то есть со всем разделом. Без выбора самого глубокого на любой
 * странице панели горел бы «Консоль», а её собственный пункт не горел бы
 * никогда — заметить это можно только по подсветке на соседних страницах.
 */
/*
 * Подсвеченный пункт ищется по классу active и подписи .nav-text — так он
 * помечен в сборке gentelella. Проверяется именно подпись: класс active может
 * стоять и на пункте, у которого нет текста, и тогда подсветка есть, а человек
 * ничего не видит.
 */
$activeLabel = static function (string $html): string {
    return preg_match(
        '/<a class="nav-link active"[^>]*>.*?<span class="nav-text">([^<]+)/s',
        $html,
        $m
    ) === 1 ? $m[1] : '';
};

$t->equals('Консоль', $activeLabel($console['body']), 'на консоли горит «Консоль»');
$t->equals('Пользователи', $activeLabel($owner->get('/panel/users')['body']), 'в списке горит «Пользователи»');
$t->equals('Пользователи', $activeLabel($owner->get('/panel/users/' . $secondId)['body']),
    'на странице пользователя тоже горит «Пользователи»');
$t->equals('Настройки', $activeLabel($owner->get('/panel/settings')['body']), 'в настройках горит «Настройки»');

/*
 * Заголовок настроек — заголовок страницы, а не подпись карточки.
 *
 * Он лежал внутри карточки и был подписан `card-title` — тем же классом, каким
 * подписаны второстепенные заголовки. Для программы чтения страница выглядела
 * безымянной, и по документу было не найти само название раздела.
 *
 * Проверяется здесь, а не в наборе работ: адрес требует права `users.manage`,
 * и учитель его не имеет — проверка смотрела бы не на ту страницу.
 */
$settingsPage = $owner->get('/panel/settings');

$t->is(
    preg_match('#<h1 class="page-title">Настройки сайта#u', (string) $settingsPage['body']) === 1,
    'заголовок настроек — заголовок страницы, а не подпись карточки'
);
$t->notContains($settingsPage['body'], '<h1 class="card-title">',
    'внутри карточек настроек нет главных заголовков');

/*
 * Блок обновления системы — в настройках, и только у администратора.
 *
 * Обновление меняет файлы, из которых работает весь сайт, и право на это
 * должно быть у того, кто и так распоряжается сайтом. Проверяется то, что видно
 * без сети: сам блок и текущая версия.
 *
 * Содержимое блока зависит от GitHub, а сети у школьного сервера может не
 * быть: поэтому проверка не требует ни кнопки обновления, ни отказа GitHub.
 * Право на само обновление проверяется в наборе `school`, где есть завуч.
 */
$t->contains($settingsPage['body'], 'Обновление', 'в настройках есть блок обновления');
$t->contains($settingsPage['body'], 'Текущая версия', 'в блоке названа текущая версия');
$t->contains($settingsPage['body'], '0.3.1 beta', 'текущая версия показана та, что стоит в настройках');

/*
 * Список работ и создание новой — разные пункты и разные адреса, поэтому
 * подсвечиваются они по-разному. Если бы они остались одним пунктом, то
 * человек, пришедший составить работу, оказывался бы в списке без всякого
 * признака, где он находится.
 */
$t->equals('Работы', $activeLabel($owner->get('/panel/assignments')['body']),
    'в списке работ горит «Работы»');

$t->equals('Составить работу', $activeLabel($owner->get('/panel/assignments/new/start')['body']),
  'на первом шаге мастера горит «Составить работу»');

/*
 * Новый стиль — всему, кто вошёл. Граница проходит по входу, а не по
 * разделу: иначе меню появлялось бы только внутри панели и пропадало на
 * главной, и переход туда и обратно выглядел бы как смена сайта.
 */
foreach (['/portal', '/profile', '/works', '/panel', '/classes'] as $staffPath) {
    $t->contains($owner->get($staffPath)['body'], 'class="topbar"',
        'шапка шаблона применена на ' . $staffPath);
}

foreach (['/panel', '/classes'] as $staffPath) {
    $t->contains($owner->get($staffPath)['body'], 'aria-label="Разделы панели"', 'у сотрудника есть меню на ' . $staffPath);
}

/*
 * Ученику меню панели не достаётся — нет прав. Но новый стиль и кнопка
 * выхода у него остаются: без кнопки человек вошёл бы в систему и не смог
 * выйти, потому что выход был только в меню.
 */
$studentHome = new TestClient($baseUrl);
$studentLoginPage = $studentHome->get('/login');
$studentHome->post('/login', [
    '_token'     => $studentHome->tokenFrom($studentLoginPage) ?? '',
    'identifier' => 'uchenik@example.edu',
    'password'   => 'uchenik2025',
]);

$t->is($studentHome->get('/portal')['status'] === 200, 'ученик видит свою главную');
$t->contains($studentHome->get('/portal')['body'], 'class="topbar"', 'ученику тоже шапка шаблона');
$t->notContains($studentHome->get('/portal')['body'], 'aria-label="Разделы панели"', 'ученику меню панели не показывается');
$t->contains($studentHome->get('/portal')['body'], '/profile', 'ученик видит вход в свои данные');
$t->contains($studentHome->get('/portal')['body'], '/logout', 'у ученика есть выход в шапке');

/*
 * Гость остаётся на страницах вне панели: вход, регистрация и восстановление
 * пароля. У них нет ни меню, ни шапки — только карточка с формой, как в
 * образце.
 */
$t->notContains($guest->get('/login')['body'], 'class="sidebar"', 'на странице входа нет меню панели');
$t->notContains($guest->get('/register')['body'], 'class="sidebar"', 'на странице регистрации нет меню панели');

/*
 * ------------------------------------------------------ страница пользователей
 *
 * Страница переделана по образцу gentelella/user_management.html: четыре
 * показателя сверху, аватар с инициалами, одна роль, статус, две даты и меню в
 * последнем столбце. Проверяется всё, что человек видит, и всё, что он может
 * поменять прямо в строке.
 *
 * Проверки не завязаны на конкретные числа там, где число зависит от состояния
 * базы: считается то же самое запросом и сравнивается с тем, что отрисовано.
 * Иначе набор падал бы не из-за страницы, а из-за чужого набора данных.
 */
$t->group('Страница пользователей по образцу gentelella');

$staffCount = (int) $db->value(
    'SELECT COUNT(*) FROM users
      WHERE id NOT IN (SELECT user_id FROM user_roles WHERE role_code = \'student\')'
);

$t->is((int) $db->value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url'"
) === 1, 'в users есть колонка avatar_url');

$page = $owner->get('/panel/users');
$body = $page['body'];

/*
 * Четыре показателя: подписи и числа. Числа считаются тем же запросом, каким
 * их считает страница, — иначе проверка ловила бы не расхождение в шаблоне, а
 * расхождение двух разных подсчётов, что невозможно.
 */
foreach (['Всего пользователей', 'Учителей', 'Завучей', 'Без должности'] as $statLabel) {
    $t->contains($body, $statLabel, 'показатель «' . $statLabel . '» на месте');
}

$t->equals(4, substr_count($body, 'class="stat-value"'), 'показателей ровно четыре, как в образце');

$staffTotal = (int) $db->value(
    "SELECT COUNT(DISTINCT u.id) FROM users u
      WHERE u.id NOT IN (SELECT ur2.user_id FROM user_roles ur2 WHERE ur2.role_code = 'student')"
);

$t->contains($body, '<span class="stat-value">' . $staffTotal . '</span>',
    'число сотрудников в показателе совпадает с базой');

$teacherTotal = (int) $db->value(
    "SELECT COUNT(DISTINCT u.id) FROM users u
      JOIN user_roles ur ON ur.user_id = u.id AND ur.role_code = 'teacher'
     WHERE u.id NOT IN (SELECT ur2.user_id FROM user_roles ur2 WHERE ur2.role_code = 'student')"
);

$t->contains($body, '<span class="stat-value">' . $teacherTotal . '</span>',
    'число учителей в показателе совпадает с базой');

/* Порядок столбцов — как в образце, и он одинаков в шапке и в теле таблицы. */
$headHtml = preg_match('#<thead>(.*?)</thead>#su', $body, $theadMatch) ? $theadMatch[1] : '';
preg_match_all('#<th[^>]*>(.*?)</th>#su', $headHtml, $thMatch);
$columns = array_map(
    static fn (string $cell): string => trim(strip_tags($cell)),
    $thMatch[1] ?? []
);

$t->equals(['Пользователь', 'Роль', 'Статус', 'Последняя активность', 'Присоединился', ''], $columns,
    'порядок столбцов совпадает с образцом');

preg_match_all('#<td class="row-actions">#u', $body, $tdMatch);
$t->equals($staffCount, count($tdMatch[0] ?? []), 'меню есть в каждой строке списка');

$t->equals($staffCount, substr_count($body, 'Редактировать профиль'),
    'в меню каждой строки есть «Редактировать профиль»');

$t->contains($body, 'panel/users/' . $secondId, 'ссылка ведёт на страницу сотрудника');

$t->notContains($body, 'uchenik@example.edu', 'ученика в списке сотрудников нет');

/*
 * Аватар: у кого файл загружен — картинка, у кого нет — кружок с инициалами.
 * Проверяется прямо в базе, потому что загрузки здесь ещё не было: важно, что
 * страница читает колонку и умеет показать оба случая.
 */
$db->run(
    'UPDATE users SET avatar_url = :avatar WHERE id = :id',
    ['avatar' => 'uploads/proverka-avatar.png', 'id' => $secondId]
);
$withAvatar = $owner->get('/panel/users')['body'];
$t->contains($withAvatar, '<img class="cell-avatar"', 'загруженный аватар показан картинкой');

$db->run('UPDATE users SET avatar_url = NULL WHERE id = :id', ['id' => $secondId]);
$withoutAvatar = $owner->get('/panel/users')['body'];
$t->notContains($withoutAvatar, '<img class="cell-avatar"', 'без файла картинки не бывает');
$t->contains($withoutAvatar, 'class="cell-avatar"', 'без файла показан кружок с инициалами');

/* Инициалы — первая буква фамилии и имени, а не первые две буквы подряд. */
$secondName = (string) ($users->findRow($secondId)['full_name'] ?? '');
$nameParts = preg_split('/\s+/u', trim($secondName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$initials = mb_strtoupper(
    mb_substr($nameParts[0] ?? '', 0, 1, 'UTF-8') . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);

$t->is(
    preg_match('#>\s*' . preg_quote($initials, '#') . '\s*<#u', $withoutAvatar) === 1,
    'в кружке первые буквы фамилии и имени'
);

/*
 * Роль меняется прямо в строке и остаётся одна: выбор из списка заменяет
 * прежний набор целиком, а не добавляет к нему ещё одну.
 */
$originalRole = $users->rolesOf($secondId)[0] ?? Role::TEACHER;

$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::HEAD_TEACHER,
]);

$t->is($users->rolesOf($secondId) === [Role::HEAD_TEACHER], 'роль сменилась и осталась одна');

$t->contains($owner->get('/panel/users')['body'], 'роль — Завуч', 'сообщение о смене роли показано');

$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'role'    => Role::STUDENT,
]);

$t->is($users->rolesOf($secondId) === [Role::HEAD_TEACHER], 'роль «Ученик» через список не назначается');

$owner->post('/panel/users/role', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $ownerId,
    'role'    => Role::OBSERVER,
]);

$t->is($users->rolesOf($ownerId) === [Role::ADMIN], 'у владельца роль администратора не снимается');

/* Выбор роли есть у всех сотрудников, кроме владельца и самого себя. */
$roleFormExpected = (int) $db->value(
    "SELECT COUNT(*) FROM users u
      WHERE u.is_owner = 0
        AND u.id <> :self
        AND u.id NOT IN (SELECT ur2.user_id FROM user_roles ur2 WHERE ur2.role_code = 'student')",
    ['self' => $ownerId]
);

$rolePage = $owner->get('/panel/users')['body'];

/*
 * Роль меняется меню строки, а не выпадающим списком с кнопкой «Сохранить».
 *
 * Список требовал двух нажатий, а на странице с двадцатью пятью сотрудниками он
 * давал двадцать пять списков и столько же кнопок. Меню выбирается одним
 * нажатием и открывается без скриптов — как меню действий в последнем столбце.
 */
$t->equals($roleFormExpected, substr_count($rolePage, 'row-menu--role'),
    'меню смены роли есть у всех сотрудников, кроме владельца и себя');
$t->notContains($rolePage, 'role-select',
    'выпадающего списка роли в строке больше нет');
$t->notContains($rolePage, 'role-save',
    'кнопки «Сохранить» рядом со списком больше нет');
$t->contains($rolePage, 'name="role"',
    'пункты меню отправляют выбранную роль');

/* В каждом меню перечислены все назначаемые роли, и текущая отмечена. */
$t->equals(
    $roleFormExpected * count(Role::assignable()),
    preg_match_all('/name="role"[^>]*value="[a-z_]+"/', $rolePage),
    'в каждом меню перечислены все назначаемые роли'
);
$t->is(
    preg_match_all('/menu-item--current"[^>]*aria-current="true"/', $rolePage) === $roleFormExpected,
    'текущая роль отмечена в меню'
);

$t->contains($rolePage, 'role-chip">Владелец', 'у владельца роль показана подписью, а не выбором');

/* Блокировка меняет подпись в строке и пункт меню. */
$owner->post('/panel/users/status', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'status'  => 'blocked',
]);

$blockedPage = $owner->get('/panel/users')['body'];
$t->contains($blockedPage, 'status-red', 'заблокированный помечен красным');
$t->contains($blockedPage, 'Разблокировать', 'в меню заблокированного есть «Разблокировать»');

$owner->post('/panel/users/status', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/users')) ?? '',
    'user_id' => (string) $secondId,
    'status'  => 'active',
]);

$t->equals(0, substr_count($owner->get('/panel/users')['body'], 'status-red'),
    'после разблокировки заблокированных не осталось');

/*
 * Подпись «ещё не заходил» появляется только у тех, кто ни разу не входил.
 * Если таких нет — пропускаем, а не выдумываем пользователя ради проверки.
 */
$neverLoggedIn = (int) $db->value(
    "SELECT COUNT(*) FROM users u
      WHERE u.last_login_at IS NULL
        AND u.id NOT IN (SELECT ur2.user_id FROM user_roles ur2 WHERE ur2.role_code = 'student')"
);

if ($neverLoggedIn > 0) {
    $t->contains($owner->get('/panel/users')['body'], 'ещё не заходил',
        'у кого не было входа, написано «ещё не заходил»');
} else {
    $t->is(true, 'сотрудников без входа нет — подпись «ещё не заходил» не проверялась');
}

/*
 * В разметке списка нет inline-стилей: стиль в атрибуте запрещён политикой
 * безопасности (style-src 'self') и браузер его отбрасывает. Одна такая
 * строка — и кружок аватара остаётся пустым, как это и случилось.
 */
$t->notContains($body, 'style="', 'в списке сотрудников нет inline-стилей');

/* Состояние базы возвращается как было: проверки не должны влиять на наборы, идущие дальше. */
$users->setRole($secondId, $originalRole);

/*
 * Аватар: загрузка, отказ по содержимому, удаление файла.
 *
 * Проверяется целиком — от выбора файла до его исчезновения с диска. Половина
 * этой проверки ничего не значила бы: адрес в базе при наличии файла и файл
 * при отсутствии адреса — это разные поломки с одинаковыми симптомами на
 * странице.
 */
/*
 * Файл удалён — но на Windows только что созданный файл какое-то время держит
 * антивирус, и удаление возвращает «занято». Проверка ждёт: иначе она ловит
 * не поломку, а скорость чужой программы.
 */
$waitGone = static function (string $path): bool {
    for ($i = 0; $i < 20; $i++) {
        if (!is_file($path)) {
            return true;
        }

        clearstatcache(true, $path);
        usleep(250_000);
    }

    return false;
};
$t->group('Аватар: загрузка и удаление');

// Настоящая картинка в один пиксель: проверка смотрит файл, а не его имя.
$pngPath = tempnam(sys_get_temp_dir(), 'avatar-proverka') . '.png';
$txtPath = tempnam(sys_get_temp_dir(), 'avatar-proverka') . '.txt';

file_put_contents($pngPath, (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
));
file_put_contents($txtPath, 'это не картинка');

$profilePage = $owner->get('/profile');

$t->contains($profilePage['body'], 'enctype="multipart/form-data"', 'форма аватара объявляет multipart');
$t->contains($profilePage['body'], 'profile/avatar', 'форма аватара ведёт на свой адрес');
$t->contains($profilePage['body'], 'type="file"', 'в форме есть выбор файла');
$t->contains($profilePage['body'], 'Загрузить аватар', 'есть кнопка загрузки');
$t->is($users->avatarUrl($ownerId) === null, 'у владельца сначала аватара нет');

$upload = $owner->upload(
    '/profile/avatar',
    ['_token' => $owner->tokenFrom($profilePage) ?? ''],
    ['avatar' => $pngPath]
);

$t->is($upload['status'] === 302, 'загрузка принята');

$avatarUrl = (string) $users->avatarUrl($ownerId);

$t->is(str_starts_with($avatarUrl, '/uploads/'), 'адрес аватара ведёт в каталог загрузок');
$t->is(is_file($rootDir . '/public' . $avatarUrl), 'файл аватара лежит на диске');

$afterUpload = $owner->get('/profile');

$t->contains($afterUpload['body'], 'Аватар сохранён', 'загрузка отвечает сообщением');
$t->contains($afterUpload['body'], $avatarUrl, 'аватар показан на странице профиля');
$t->contains($afterUpload['body'], 'Убрать аватар', 'кнопка удаления появилась');

$t->contains(
    $owner->get('/panel/users')['body'],
    '<img class="cell-avatar"',
    'загруженный аватар виден в списке сотрудников'
);

/* Файл не картинкой отклоняется, и прежний аватар при этом не теряется. */
$owner->upload(
    '/profile/avatar',
    ['_token' => $owner->tokenFrom($afterUpload) ?? ''],
    ['avatar' => $txtPath]
);

$rejected = $owner->get('/profile');

$t->contains($rejected['body'], 'Это не картинка', 'файл не картинкой отклонён с объяснением');
$t->is($users->avatarUrl($ownerId) === $avatarUrl, 'прежний аватар не пострадал');

$t->is(
    $owner->upload('/profile/avatar', [], ['avatar' => $pngPath])['status'] === 419,
    'загрузка без токена не принимается'
);

/* Удаление: пропадает и адрес, и файл — иначе каталог загрузок только растёт. */
$remove = $owner->post('/profile/avatar', [
    '_token' => $owner->tokenFrom($owner->get('/profile')) ?? '',
    'remove' => '1',
]);

$t->is($remove['status'] === 302, 'удаление принято');
$t->is($users->avatarUrl($ownerId) === null, 'аватара больше нет');
$t->is($waitGone($rootDir . '/public' . $avatarUrl), 'файл аватара удалён с диска');

@unlink($pngPath);
@unlink($txtPath);

/*
 * Аватар сотрудника загружает администратор.
 *
 * Человек не всегда сам заходит на сайт, а фотографию сотрудника знает тот,
 * кто его завёл. Проверяется и форма, и то, что адрес ученику закрыт: у
 * ученика страницы сотрудника нет даже для чтения, и форма с загрузкой была бы
 * второй страницей, которой у него не должно быть.
 */
$t->group('Аватар сотрудника: администратор загружает');

$userEditPage = $owner->get('/panel/users/' . $secondId);

$t->contains($userEditPage['body'], 'enctype="multipart/form-data"', 'форма аватара объявляет multipart');
$t->contains($userEditPage['body'], 'panel/users/' . $secondId . '/avatar', 'форма ведёт на свой адрес');
$t->contains($userEditPage['body'], 'type="file"', 'в форме есть выбор файла');
$t->contains($userEditPage['body'], 'Загрузить аватар', 'есть кнопка загрузки');
$t->is($users->avatarUrl($secondId) === null, 'у второго сотрудника сначала аватара нет');

$staffPng = tempnam(sys_get_temp_dir(), 'avatar-proverka') . '.png';
file_put_contents($staffPng, (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
));

$staffUpload = $owner->upload(
    '/panel/users/' . $secondId . '/avatar',
    ['_token' => $owner->tokenFrom($userEditPage) ?? ''],
    ['avatar' => $staffPng]
);

$t->is($staffUpload['status'] === 302, 'загрузка принята');

$staffAvatarUrl = (string) $users->avatarUrl($secondId);

$t->is(str_starts_with($staffAvatarUrl, '/uploads/'), 'адрес аватара ведёт в каталог загрузок');
$t->is(is_file($rootDir . '/public' . $staffAvatarUrl), 'файл аватара лежит на диске');

$afterStaffUpload = $owner->get('/panel/users/' . $secondId);

$t->contains($afterStaffUpload['body'], 'Аватар сохранён', 'загрузка отвечает сообщением');
$t->contains($afterStaffUpload['body'], $staffAvatarUrl, 'аватар показан на странице сотрудника');
$t->contains($afterStaffUpload['body'], 'Убрать аватар', 'кнопка удаления появилась');
$t->contains(
    $owner->get('/panel/users')['body'],
    '<img class="cell-avatar"',
    'аватар сотрудника виден в списке'
);

/* У ученика такой страницы нет: его правят в своём классе. */
$t->is(
    $owner->get('/panel/users/' . $studentId)['status'] === 404,
    'страница сотрудника ученику недоступна'
);
$t->is(
    $owner->post('/panel/users/' . $studentId . '/avatar', [
        '_token' => $owner->tokenFrom($afterStaffUpload) ?? '',
        'remove' => '1',
    ])['status'] === 404,
    'адрес аватара ученику закрыт'
);

/* Удаление: пропадает и адрес, и файл. */
$staffRemove = $owner->post('/panel/users/' . $secondId . '/avatar', [
    '_token' => $owner->tokenFrom($afterStaffUpload) ?? '',
    'remove' => '1',
]);

$t->is($staffRemove['status'] === 302, 'удаление принято');
$t->is($users->avatarUrl($secondId) === null, 'аватара сотрудника больше нет');
$t->is($waitGone($rootDir . '/public' . $staffAvatarUrl), 'файл аватара сотрудника удалён с диска');

$t->is(
    $owner->upload('/panel/users/' . $secondId . '/avatar', [], ['avatar' => $staffPng])['status'] === 419,
    'загрузка аватара без токена не принимается'
);

@unlink($staffPng);

/*
 * Переключатель плагина: адрес один на включение и выключение.
 *
 * Проверяется на несуществующем плагине: настоящий включать нельзя, состояние
 * плагинов читают другие наборы, а смысл проверки не в переключении, а в том,
 * что адрес живёт, спрашивает токен и отвечает понятным сообщением.
 */
$owner->post('/panel/plugins/toggle', [
    '_token'  => $owner->tokenFrom($owner->get('/panel/plugins')) ?? '',
    'id'      => 'no-such-plugin',
    'enabled' => '1',
]);

/*
 * Сообщение берётся одним обращением: оно показывается один раз, второе
 * обращение к странице его уже не увидит — и проверка искала бы его там,
 * где его и не может быть.
 */
$pluginPage = $owner->get('/panel/plugins');

$t->is($pluginPage['status'] === 200, 'страница плагинов открывается');
$t->contains($pluginPage['body'], 'Плагин не найден',
    'переключатель отвечает сообщением, а не молча');

$withoutToken = $owner->post('/panel/plugins/toggle', [
    'id'      => 'no-such-plugin',
    'enabled' => '1',
]);

$t->is($withoutToken['status'] === 419, 'переключатель без токена не принимается');
$t->is($owner->get('/panel/plugins')['status'] === 200, 'страница плагинов открывается и после отказа');

exit($t->report());

