<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Config;
use MyEdu\Core\Database;
use MyEdu\Ui\Messages;

/**
 * Регистрация по e-mail с подтверждением, восстановление пароля и вход.
 *
 * Правило владельца: первый зарегистрировавшийся пользователь становится
 * владельцем сайта и администратором. Право закрепляется сразу при регистрации,
 * чтобы его не мог перехватить тот, кто зарегистрируется позже; войти без
 * подтверждения адреса он всё равно не сможет. Все последующие по умолчанию —
 * роль «Наблюдатель», остальные назначает администратор.
 */
final class AuthService
{
    /*
     * Хеш для проверки несуществующего логина.
     *
     * Задача одна: потратить на ответ столько же, сколько тратит настоящая
     * проверка. Иначе время ответа выдаёт, какие логины существуют, — достаточно
     * просто мерить.
     *
     * Раньше здесь стояла строка
     * `$2y$10$usesomesillystringforsalt000000000000000000000000000000` — это
     * 62 символа вместо шестидесяти, и MySQL такой хеш отвергает, а
     * password_verify на негодном хеше возвращает false сразу, не считая
     * ничего. То есть на несуществующий логин система отвечала за единицы
     * миллисекунд, на существующий — за сотни с лишним, и разница была видна
     * невооружённым глазом. Защита работала ровно наоборот: отличала
     * существующие учётные записи от несуществующих.
     *
     * Здесь настоящий bcrypt-хеш со стоимостью, какой создаёт сама система.
     * Пароль под ним неизвестен и не подбирается: он создан один раз из
     * случайных 32 байт и нигде не сохранялся. Если PHP сменит стоимость по
     * умолчанию, заглушку надо пересоздать — это проверяет набор тестов.
     */
    public const TIMING_HASH = '$2y$12$HrY/GdzFvvjVqlIlnoxbgecaEUlncwALyAVST0ZGIIwGIP9sKOfJ6';

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly Auth $auth,
        private readonly AuthMailer $mailer,
        private readonly Config $config,
        private readonly ?LoginThrottle $throttle = null
    ) {
    }

    /**
     * Регистрация сотрудника (не ученика).
     *
     * @param array{email:string,full_name:string,password:string} $data
     *
     * @return array{ok:bool,error?:string,user_id?:int,email_sent?:bool}
     */
    public function register(array $data, string $baseUrl): array
    {
        $email = mb_strtolower(trim($data['email']), 'UTF-8');
        $fullName = $this->normalizeName($data['full_name']);
        $password = $data['password'];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => Messages::EMAIL];
        }

        if (mb_strlen($fullName, 'UTF-8') < 3) {
            return ['ok' => false, 'error' => Messages::minLength(3) . ': укажите фамилию и имя'];
        }

        $minLength = $this->auth->minPasswordLength();

        if (mb_strlen($password, 'UTF-8') < $minLength) {
            return [
                'ok'    => false,
                'error' => "Пароль должен быть не короче {$minLength} символов",
            ];
        }

        if ($this->users->emailExists($email)) {
            return [
                'ok'    => false,
                'error' => 'Пользователь с таким адресом уже зарегистрирован. Попробуйте войти.',
            ];
        }

        $passwordHash = $this->auth->hashPassword($password);

        /** @var array{user_id:int,token:string}|array{error:string} $registration */
        $registration = $this->db->transaction(function (Database $db) use (
            $email,
            $fullName,
            $passwordHash
        ): array {
            // Все регистрации блокируют одну стабильную строку до проверки владельца
            // и вставки пользователя, поэтому параллельные запросы видят результат
            // предыдущей регистрации после снятия блокировки.
            $db->one("SELECT `key` FROM `settings` WHERE `key` = 'school_name' FOR UPDATE");

            if ($this->users->emailExists($email)) {
                return ['error' => 'Пользователь с таким адресом уже зарегистрирован. Попробуйте войти.'];
            }

            // Владение закрепляется при регистрации, не при подтверждении адреса.
            $becomesOwner = !$this->users->ownerExists();

            $userId = $this->users->create([
                'login'         => $email,
                'email'         => $email,
                'password_hash' => $passwordHash,
                'full_name'     => $fullName,
                'is_owner'      => $becomesOwner ? 1 : 0,
                'status'        => 'pending',
            ]);

            $this->users->setRole($userId, $becomesOwner ? Role::ADMIN : Role::OBSERVER, $userId);

            $token = $this->tokens->issue(
                $userId,
                TokenRepository::PURPOSE_CONFIRM,
                $this->config->int('auth.confirm_token_hours', 48),
                $email
            );

            return ['user_id' => $userId, 'token' => $token];
        });

        if (isset($registration['error'])) {
            return ['ok' => false, 'error' => $registration['error']];
        }

        $sent = $this->mailer->sendConfirmation(
            $email,
            $fullName,
            $this->confirmUrl($baseUrl, $registration['token'])
        );

        return ['ok' => true, 'user_id' => $registration['user_id'], 'email_sent' => $sent];
    }

/**
     * Подтверждение адреса по ссылке из письма.
     *
     * Метод не бросает исключений и не сообщает о причине отказа — по ссылке
     * из письма это уместнее: человеку показывают «ссылка больше не действует»
     * независимо от того, истек ли срок, использована ли она уже или адрес успели
     * поменять. Для вызывающего кода различие важно, поэтому возвращается
     * результат с причиной — им пользуются проверки, а не форма.
     *
     * @param array<string,mixed> $tokenRow
     * @return array{ok:bool,reason?:string}
     */
    public function confirm(array $tokenRow): array
    {
        $userId = (int) $tokenRow['id'];
        $email = (string) ($tokenRow['email'] ?? '');

    // Ссылка привязана к адресу, на который её выпустили: подтвердить ею
    // можно только тот адрес, который сейчас указан у пользователя.
    if ($email === '' || !hash_equals($email, (string) ($tokenRow['target_email'] ?? ''))) {
        return ['ok' => false, 'reason' => 'address_mismatch'];
    }

    if ((string) ($tokenRow['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'reason' => 'not_pending'];
    }

    $activated = $this->db->transaction(function (Database $db) use ($userId, $email): bool {
        $result = $db->run(
            "UPDATE users
                SET status = 'active', email_verified_at = NOW(), updated_at = NOW()
              WHERE id = :id AND email = :email AND status = 'pending'",
            ['id' => $userId, 'email' => $email]
        );

        return $result->rowCount() === 1;
    });

    if (!$activated) {
        // Строку уже подтвердили или заблокировали между проверкой и записью.
        return ['ok' => false, 'reason' => 'race'];
    }

    /*
     * Роль выдана при регистрации, и повторно она здесь не выдаётся: тихая
     * выдача роли означала бы, что подтверждение адреса повышает права. Если
     * у записи всё же не оказалось ни одной роли — это следствие ручной
     * правки базы, и лечится администратором через страницу пользователя.
     * Здесь только отмечается, чтобы это не выглядело как обычный исход.
     */
    if (!$this->db->value('SELECT 1 FROM user_roles WHERE user_id = :id LIMIT 1', ['id' => $userId])) {
        return ['ok' => true, 'reason' => 'no_role'];
    }

    return ['ok' => true];
}

    /**
     * Вход по логину или e-mail.
     *
     * @return array{ok:bool,error?:string,user?:User,needs_confirmation?:bool}
     */
    public function login(string $identifier, string $password, string $ip = ''): array
    {
        $identifier = trim($identifier);
        $ip = $ip === '' ? 'local' : $ip;

        /*
         * Перебор пароля ограничивается до проверки учётной записи: иначе
         * тысячи попыток к найденному адресу проходили бы так же незаметно,
         * как и до появления этого ограничения.
         *
         * Сообщение при отказе намеренно другое, а не «неверный логин или
         * пароль»: рассказывать, что человек exists, не нужно, но и сказать
         * «неверный пароль» при блокировке значило бы сбить с толку верного
         * человека, который просто не уложился.
         */
        if ($this->throttle !== null) {
            $wait = $this->throttle->waitSeconds($identifier, $ip);

            if ($wait > 0) {
                return [
                    'ok'    => false,
                    'error' => 'Слишком много попыток входа. Повторите через '
                        . $this->formatWait($wait),
                ];
            }
        }

        $row = $this->users->findRowByIdentifier($identifier);

        /*
         * Ошибка на неверный логин и на неверный пароль одинаковая: иначе
         * по сообщению можно перебрать существующие учётные записи.
         */
        $genericError = 'Неверный логин или пароль';

        if ($row === null) {
            /*
             * Считаем настоящий хеш, которого в базе нет: время ответа на
             * несуществующий логин должно совпадать с временем ответа на
             * существующий, иначе перебор логинов идёт по часам.
             */
            password_verify($password, self::TIMING_HASH);

            $this->throttle?->registerFailure($identifier, $ip);

            return ['ok' => false, 'error' => $genericError];
        }

        if (!$this->auth->checkPassword($password, (string) $row['password_hash'])) {
            $this->throttle?->registerFailure($identifier, $ip);

            return ['ok' => false, 'error' => $genericError];
        }

        /*
         * Дальше пароль верный. Неверный адрес и заблокированная учётная
         * запись перебором не являются: человек вошёл ключом, и считать это
         * неудачей значило бы заблокировать себя за правильный вход.
         */
if ((string) $row['status'] === 'pending') {
                /*
                 * Ссылка «Отправить письмо снова» лежит на этой же странице внизу
                 * формы, но в сообщении её не упомянуть — нельзя: человек,
                 * зарегистрировавшийся и ни разу не получивший письма, читает
                 * «ссылка отправлена» как «надо подождать» и уходит. А ждать
                 * нечего: письмо либо ушло в спам, либо не ушло вовсе, и выхода
                 * из этого состояния, кроме как отправить письмо заново, нет —
                 * вход требует активного адреса, и смена пароля отправляется
                 * только активным.
                 */
                return [
                    'ok' => false,
                    'error' => 'Сначала подтвердите адрес электронной почты. '
                        . 'Письмо отправлено при регистрации, а если оно не дошло — '
                        . 'отправьте его снова ссылкой «Отправить письмо снова» под формой.',
                    'needs_confirmation' => true,
                ];
            }

        if ((string) $row['status'] !== 'active') {
            return ['ok' => false, 'error' => $genericError];
        }

        $user = $this->users->hydrate($row);
        $this->auth->login($user);
        $this->throttle?->registerSuccess($identifier, $ip);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Остаток ожидания для сообщения.
     *
     * Пишется сокращением «мин.»: полная форма потребовала бы правил
     * множественного числа, а они уже есть у вида, и дублировать их здесь —
     * значит со временем развестись.
     */
    private function formatWait(int $seconds): string
    {
        return max(1, (int) ceil($seconds / 60)) . ' мин.';
    }

/**
 * Смена адреса пользователя с обязательным подтверждением нового адреса.
 *
 * Возвращает различающий результат, а не bool. Раньше здесь стоял bool, и
 * `false` означал сразу три разных исхода: адрес не изменился, письмо не
 * отправилось и — после появления проверки занятости — адрес уже занят.
 * Вызывающая сторона печатала бы для них один текст, и человек узнавал бы
 * «письмо не отправлено» там, где на самом деле занят адрес.
 *
 * Что происходит с доступом до подтверждения: новый адрес записывается сразу,
 * и запись переходит в pending, поэтому войти нельзя ни новым, ни старым
 * адресом, а активная сессия обрывается на следующем же запросе
 * (Auth::user() отказывает неактивным). Выйти из этого состояния можно
 * только письмом на новый адрес — намеренно: старый адрес к этому моменту
 * уже не доказан как свой. Восстановление пароля для pending не работает,
 * поэтому льготного периода здесь нет. Это решение владельца, а не упущение:
 * вернуть вход по старому адресу — значит оставить возможность увести
 * учётную запись, захватив чужой адрес.
 *
 * @return array{ok:bool,reason?:string,error?:string,changed?:bool,sent?:bool}
     */
    public function changeEmail(int $userId, string $email, string $baseUrl): array
    {
    $row = $this->users->findRow($userId);

    if ($row === null) {
        return ['ok' => false, 'reason' => 'not_found', 'error' => 'Пользователь не найден'];
    }

    if ((string) ($row['email'] ?? '') === $email) {
        return ['ok' => true, 'changed' => false, 'sent' => false];
    }

    /*
     * Занятость адреса проверяется здесь, а не только в контроллере.
     *
     * Пользователь уникален по адресу, и без проверки changeEmail() падала бы
     * на users.email ошибкой СУБД: человек увидел бы 500 вместо причины.
     * Проверка в одном месте нужна и потому, что сервис вызывается из
     * разных мест — защита, живущая в форме, обходится любым другим
     * вызовом.
     */
    if ($this->users->emailOwnerId($email) !== null) {
        return [
            'ok'     => false,
            'reason' => 'taken',
            'error'  => 'Этот адрес почты уже занят другим пользователем',
        ];
    }

    $token = $this->db->transaction(function (Database $db) use ($userId, $email): ?string {
        $db->one('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);
        $current = $this->users->findRow($userId);

        if ($current === null || (string) ($current['email'] ?? '') === $email) {
            return null;
        }

        /*
         * Адрес мог занять другой человек, пока ждали блокировку строки.
         * Проверка повторяется внутри транзакции, иначе гонка проскочила бы
         * между двумя обращениями.
         */
        $owner = $this->users->emailOwnerId($email);

        if ($owner !== null && $owner !== $userId) {
            return null;
        }

        $this->users->update($userId, [
            'login'             => $email,
            'email'             => $email,
            'email_verified_at' => null,
            'status'            => (string) $current['status'] === 'active' ? 'pending' : (string) $current['status'],
        ]);

        $this->tokens->invalidate($userId, TokenRepository::PURPOSE_RESET);

        return $this->tokens->issue(
            $userId,
            TokenRepository::PURPOSE_CONFIRM,
            $this->config->int('auth.confirm_token_hours', 48),
            $email
        );
    });

    if ($token === null) {
        // Транзакция вернула null и по трём причинам; адрес отдельно уже
        // проверен, и повторная проверка нужна, чтобы не отдать человеку
        // общий отказ вместо настоящей причины.
        $current = $this->users->findRow($userId);

        if ($current === null) {
            return ['ok' => false, 'reason' => 'not_found', 'error' => 'Пользователь не найден'];
        }

        if ((string) ($current['email'] ?? '') === $email) {
            return ['ok' => true, 'changed' => false, 'sent' => false];
        }

        if ($this->users->emailOwnerId($email) !== null) {
            return [
                'ok'     => false,
                'reason' => 'taken',
                'error'  => 'Этот адрес почты уже занят другим пользователем',
            ];
        }

        return [
            'ok'     => false,
            'reason' => 'internal',
            'error'  => 'Не удалось подготовить смену адреса. Попробуйте позже.',
        ];
    }

    $sent = $this->mailer->sendConfirmation(
        $email,
        (string) $row['full_name'],
        $this->confirmUrl($baseUrl, $token)
    );

    return ['ok' => true, 'changed' => true, 'sent' => $sent];
}

    /**
     * Запрос письма для смены пароля.
     *
     * Результат всегда один и тот же, есть учётная запись или нет:
     * иначе форму можно использовать для выяснения, зарегистрирован ли адрес.
     *
     * @return array{ok:bool,sent:bool}
     */
    public function requestPasswordReset(string $email, string $baseUrl): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $row = $this->users->findRowByEmail($email);

        if ($row === null || (string) $row['status'] !== 'active') {
            return ['ok' => true, 'sent' => false];
        }

        $token = $this->tokens->issue(
            (int) $row['id'],
            TokenRepository::PURPOSE_RESET,
            $this->config->int('auth.reset_token_hours', 2)
        );

        $sent = $this->mailer->sendPasswordReset(
            (string) $row['email'],
            (string) $row['full_name'],
            $this->resetUrl($baseUrl, $token)
        );

        return ['ok' => true, 'sent' => $sent];
    }

    /**
     * Смена пароля по одноразовому токену.
     *
     * Валидация выполняется до потребления токена, а потребление и запись
     * пароля — в одной транзакции: короткий пароль или ошибка БД не должны
     * лишать человека единственной действующей ссылки.
     *
     * @return array{ok:bool,error?:string,retryable?:bool,blocked?:bool}
     */
    public function resetPasswordWithToken(string $token, string $password): array
    {
        $minLength = $this->auth->minPasswordLength();

        if (mb_strlen($password, 'UTF-8') < $minLength) {
            return [
                'ok'        => false,
                'retryable' => true,
                'error'     => "Пароль должен быть не короче {$minLength} символов",
            ];
        }

        $passwordHash = $this->auth->hashPassword($password);

        return $this->db->transaction(function (Database $db) use ($token, $passwordHash): array {
            $user = $this->tokens->consume($token, TokenRepository::PURPOSE_RESET);

            if ($user === null) {
                return [
                    'ok'        => false,
                    'retryable' => false,
                    'error'     => 'Ссылка для смены пароля недействительна или истекла',
                ];
            }

            $this->users->update((int) $user['id'], ['password_hash' => $passwordHash]);

            return [
                'ok'      => true,
                // Сброс пароля не является разблокировкой: статус меняет только
                // администратор, даже если токен выдали до блокировки.
                'blocked' => (string) ($user['status'] ?? '') === 'blocked',
            ];
        });
    }

    /**
     * Смена пароля самим пользователем (нужен текущий пароль).
     *
     * Текущий пароль — это пароль, а не второе имя: его тоже можно перебирать.
     * Сессия у человека уже есть, поэтому ограничение на вход её не спасает —
     * украденная сессия даёт возможность проверять пароль сколько угодно раз,
     * пока не угадают. Поэтому здесь тот же счётчик, что и на входе: ключом
     * служит логин, а не адрес, и смена адреса перебор не возобновляет.
     *
     * Счётчик общий со входом намеренно: перебирающий текущий пароль и
     * перебирающий пароль на входе — один и тот же противник, и блокировка
     * должна накрывать оба пути сразу.
     */
public function changeOwnPassword(int $userId, string $current, string $new, string $ip = ''): array
    {
        $row = $this->users->findRow($userId);

        if ($row === null) {
            return ['ok' => false, 'error' => 'Пользователь не найден'];
        }

        $identifier = (string) ($row['login'] ?? $row['email'] ?? (string) $userId);
        $ip = $ip === '' ? 'local' : $ip;

        if ($this->throttle !== null && $this->throttle->waitSeconds($identifier, $ip) > 0) {
            $wait = $this->throttle->waitSeconds($identifier, $ip);

            return [
                'ok'    => false,
                'error' => 'Слишком много попыток. Повторите через ' . $this->formatWait($wait),
            ];
        }

        if (!$this->auth->checkPassword($current, (string) $row['password_hash'])) {
            $this->throttle?->registerFailure($identifier, $ip);

            return ['ok' => false, 'error' => 'Текущий пароль указан неверно'];
        }

        $minLength = $this->auth->minPasswordLength();

        if (mb_strlen($new, 'UTF-8') < $minLength) {
            return ['ok' => false, 'error' => "Новый пароль должен быть не короче {$minLength} символов"];
        }

        $this->users->update($userId, ['password_hash' => $this->auth->hashPassword($new)]);

        // Успешная смена обнуляет счётчик: человек вспомнил пароль, и считать
        // дальше нечего. Иначе после пяти неудачных попыток смена была бы
        // закрыта даже с верным паролем.
        $this->throttle?->registerSuccess($identifier, $ip);

        return ['ok' => true];
    }

    /**
     * Повторная отправка письма подтверждения.
     */
    public function resendConfirmation(int $userId, string $baseUrl): bool
    {
        $row = $this->users->findRow($userId);

        if ($row === null || $row['email'] === null || (string) $row['status'] !== 'pending') {
            return false;
        }

        $token = $this->tokens->issue(
            $userId,
            TokenRepository::PURPOSE_CONFIRM,
            $this->config->int('auth.confirm_token_hours', 48),
            (string) $row['email']
        );

        return $this->mailer->sendConfirmation(
            (string) $row['email'],
            (string) $row['full_name'],
            $this->confirmUrl($baseUrl, $token)
        );
    }

    public function confirmUrl(string $baseUrl, string $token): string
    {
        return rtrim($baseUrl, '/') . '/auth/confirm?token=' . $token;
    }

    /**
     * Повторная отправка письма подтверждения по адресу.
     *
     * Отдельный метод, а не requestPasswordReset(): тот отправляет письмо только
     * активным ученикам, а повторное подтверждение нужно как раз тем, кто ещё
     * ожидает письма. Адрес не подтверждён — отвечаем одинаково, чтобы по
     * странице нельзя было выяснить, зарегистрирован ли он.
     */
    public function resendConfirmationByEmail(string $email, string $baseUrl): array
    {
        $row = $this->users->findRowByEmail(mb_strtolower(trim($email), 'UTF-8'));

        if ($row === null) {
            return ['ok' => true, 'sent' => false];
        }

        return ['ok' => true, 'sent' => $this->resendConfirmation((int) $row['id'], $baseUrl)];
    }

    public function resetUrl(string $baseUrl, string $token): string
    {
        return rtrim($baseUrl, '/') . '/auth/reset?token=' . $token;
    }

    private function normalizeName(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        return function_exists('mb_substr')
            ? mb_substr($name, 0, 191, 'UTF-8')
            : substr($name, 0, 191);
    }
}
