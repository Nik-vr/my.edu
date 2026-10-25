<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller;

use MyEdu\Auth\Auth;
use MyEdu\Auth\AuthMailer;
use MyEdu\Auth\AuthService;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\TokenRepository;
use MyEdu\Auth\User;
use MyEdu\Auth\UserRepository;
use MyEdu\Auth\VkUrl;

use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\Panel\StudentController;
use MyEdu\Ui\Messages;

/**
 * Регистрация, подтверждение адреса, вход и профиль.
 *
 * Страницы регистрации и входа доступны только гостю (это задано в роутере),
 * а в шаблонах доступен $currentUser — его кладёт ядро перед отрисовкой.
 */
final class AuthController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly AuthService $service,
        private readonly AuthMailer $mailer,
        private readonly TokenRepository $tokens,
private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly StudentRepository $students,
        private readonly ImageUpload $uploads,
        private readonly string $baseUrl
    ) {
    }

    // ---------------------------------------------------------------- регистрация

    public function showRegister(Request $request): Response
    {
return $this->render('auth/register', [
            'title'  => 'Регистрация',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'old'    => ['full_name' => '', 'email' => ''],
            'mail' => $this->mailer->setup(),
            'isFirstRegistration' => !$this->users->ownerExists(),
        ]);
    }

    public function register(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $fullName = (string) $request->postParam('full_name', '');
        $email = (string) $request->postParam('email', '');
        $password = (string) $request->postParam('password', '');
        $password2 = (string) $request->postParam('password2', '');

        if ($password !== $password2) {
            return $this->registerFailed('Пароли не совпадают', $fullName, $email);
        }

        $result = $this->service->register([
            'full_name' => $fullName,
            'email'     => $email,
            'password'  => $password,
        ], $this->baseUrl);

        if (($result['ok'] ?? false) !== true) {
            return $this->registerFailed((string) $result['error'], $fullName, $email);
        }

        $this->session->set('flash_notice', ($result['email_sent'] ?? false) === true
            ? 'Регистрация завершена. Мы отправили письмо со ссылкой для подтверждения адреса.'
            : 'Регистрация завершена, но отправить письмо не удалось. Запросите письмо повторно со страницы входа.');

        return $this->redirect('login');
    }

    private function registerFailed(string $error, string $fullName, string $email): Response
    {
        $this->session->set('flash_error', $error);

return $this->render('auth/register', [
            'title'  => 'Регистрация',
            'error'  => $error,
            'notice' => null,
            'old'    => ['full_name' => $fullName, 'email' => $email],
            'mail' => $this->mailer->setup(),
            'isFirstRegistration' => !$this->users->ownerExists(),
        ], 422);
    }

    public function showResend(Request $request): Response
    {
        return $this->render('auth/resend', [
            'title'  => 'Отправить письмо повторно',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'mail' => $this->mailer->setup(),
            'isFirstRegistration' => !$this->users->ownerExists(),
        ]);
    }

    public function resend(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $email = (string) $request->postParam('email', '');

/*
         * Здесь нужна именно ссылка подтверждения, а не смена пароля:
         * requestPasswordReset() отправляет письмо только активным ученикам,
         * а повторное подтверждение требуют те, кто ещё ожидает первого письма.
         * Ответ всегда одинаковый: по нему нельзя выяснить, зарегистрирован
         * ли адрес.
         */
        $this->service->resendConfirmationByEmail($email, $this->baseUrl);

        $this->session->set(
            'flash_notice',
            'Письмо отправлено на указанный вами адрес.'
        );

        return $this->redirect('login');
    }

    // ---------------------------------------------------------------- подтверждение

    public function showConfirm(Request $request): Response
    {
        return $this->render('auth/confirm', [
            'title' => 'Подтверждение адреса',
            'token' => (string) $request->queryParam('token', ''),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $token = (string) $request->postParam('token', '');
        $row = $this->tokens->consume($token, TokenRepository::PURPOSE_CONFIRM);

        if ($row === null) {
            return $this->render('auth/message', [
                'title'  => 'Подтверждение адреса',
                'error'  => $this->confirmReason($token),
                'notice' => null,
                'action' => ['label' => 'Запросить письмо повторно', 'url' => $this->view->url('confirm/resend')],
            ], 400);
        }

        $result = $this->service->confirm($row);

        /*
         * Ссылка одноразовая, поэтому «почти успех» — это уже неуспех.
         * Показывать «адрес подтверждён» при reason 'no_role' значило бы
         * сообщить человеку, что он может войти, когда у записи нет ни одной
         * роли и вход её не даст.
         */
        if (($result['ok'] ?? false) !== true) {
            return $this->render('auth/message', [
                'title'  => 'Подтверждение адреса',
                'error'  => 'Не удалось подтвердить адрес: данные изменились, пока ссылка была открыта. Запросите письмо повторно.',
                'notice' => null,
                'action' => ['label' => 'Запросить письмо повторно', 'url' => $this->view->url('confirm/resend')],
            ], 400);
        }

        if (($result['reason'] ?? '') === 'no_role') {
            /*
             * Текст обязан соответствовать реальности: войти запись без роли
             * может — запрета на вход нет, закрыт только раздел с заданиями.
             * Прежняя формулировка «войти не получится» была неправдой: человек
             * уходил к администратору, хотя войти он мог, и позже обнаруживал
             * пустой кабинет без объяснения, почему он пустой.
             */
            return $this->render('auth/message', [
                'title'  => 'Адрес подтверждён',
                'error'  => 'Адрес подтверждён, но у учётной записи нет ни одной роли. Войти можно, '
                    . 'но задания и панель будут недоступны, пока роль не выдаст администратор.',
                'notice' => null,
                'action' => null,
            ], 400);
        }

        $this->session->set('flash_notice', 'Адрес подтверждён. Теперь можно войти в систему.');

        return $this->redirect('login');
    }

    private function confirmReason(string $token): string
    {
        $lookup = $this->tokens->lookup($token, TokenRepository::PURPOSE_CONFIRM);

        if ($lookup !== null && $lookup['used_at'] === null && strtotime((string) $lookup['expires_at']) < time()) {
            return 'Срок действия ссылки истёк. Запросите письмо повторно.';
        }

        return 'Ссылка подтверждения недействительна или уже использована.';
    }

    // ---------------------------------------------------------------- вход и выход

public function showLogin(Request $request): Response
    {
        return $this->render('auth/login', [
            'title'     => 'Вход',
            'error'     => $this->session->pull('flash_error'),
            'notice'    => $this->session->pull('flash_notice'),
            'old'       => (string) $this->session->pull('last_identifier', ''),
            'needEmail' => $this->config->bool('auth.allow_login_by_email', true),
        ]);
    }

    /**
     * Форма входа для ученика.
     *
     * Отдельная от общей не из эстетики. Ученик приходит по напечатанному
     * листу с логином и паролем и не знает ни про e-mail, ни про
     * регистрацию: общая форма предлагала ему оба и уводила в сторону,
     * которой он не пользуется. Сотрудники сюда не ходят — у них своя форма,
     * и наоборот.
     *
     * Поля те же, что и у сотрудников: адрес источника обязателен для
     * ограничения перебора, и он приходит с любой формы.
     */
    public function showStudentLogin(Request $request): Response
    {
        return $this->render('auth/login_student', [
            'title'  => 'Вход для ученика',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'old'    => (string) $this->session->pull('last_identifier', ''),
        ]);
    }

    /**
     * Вход ученика.
     *
     * Отдельный метод сервиса, а не общий login(): ученик живёт в своей
     * таблице `students`, у него нет ни почты, ни подтверждения адреса, ни
     * ролей. Общий вход искал бы его среди сотрудников и молча не находил,
     * и человек читал бы «неверный логин или пароль» при верном пароле.
     *
     * Имя поля осталось `identifier`, чтобы не менять шаблон: у формы одна
     * строка ввода, и называть её «логином» в разметке значило бы подсказывать
     * формат логина постороннему.
     */
    public function studentLogin(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $result = $this->service->loginStudent(
            (string) $request->postParam('identifier', ''),
            (string) $request->postParam('password', ''),
            $request->ip()
        );

        if (($result['ok'] ?? false) !== true) {
            $this->session->set('flash_error', 'Не удалось войти: ' . (string) $result['error']);
            $this->session->set('last_identifier', (string) $request->postParam('identifier', ''));

            return $this->redirect('student/login');
        }

        $this->session->remove('last_identifier');
        /*
         * Выданные пароли — собственность прежней личности: сессия при входе
         * не разрушается, а перерождается с тем же содержимым, и без этой
         * строки пароли, напечатанные сотрудником на общем компьютере, дожили
         * бы до ученической сессии того же браузера.
         */
        $this->session->remove(StudentController::ISSUED_PASSWORDS_KEY);
        $intended = $this->session->pull('_intended');

        return $this->redirect(is_string($intended) && $intended !== '' ? $intended : 'portal');
    }

    public function login(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $result = $this->service->login(
            (string) $request->postParam('identifier', ''),
            (string) $request->postParam('password', ''),
            // Адрес источника обязателен для ограничения перебора: без него
            // счётчик неудачных попыток вёлся бы на весь узел, и один
            // неудачный вход блокировал бы всех, кто за ним стоит.
            $request->ip()
        );

        if (($result['ok'] ?? false) !== true) {
            $this->session->set('flash_error', 'Не удалось войти: ' . (string) $result['error']);
            $this->session->set('last_identifier', (string) $request->postParam('identifier', ''));

            return $this->redirect('login');
        }

        // См. studentLogin(): смена личности уносит выданные пароли с собой.
        $this->session->remove(StudentController::ISSUED_PASSWORDS_KEY);
        $intended = $this->session->pull('_intended');

        return $this->redirect(is_string($intended) && $intended !== '' ? $intended : 'portal');
    }

    public function logout(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $this->auth->logout();

        return $this->redirect('login');
    }

    // ---------------------------------------------------------------- восстановление

    public function showForgot(Request $request): Response
    {
        return $this->render('auth/forgot', [
            'title'  => 'Восстановление пароля',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'mail' => $this->mailer->setup(),
            'isFirstRegistration' => !$this->users->ownerExists(),
        ]);
    }

    public function sendResetLink(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $this->service->requestPasswordReset(
            (string) $request->postParam('email', ''),
            $this->baseUrl
        );

        /*
         * Ответ одинаковый независимо от того, есть такой адрес или нет:
         * иначе форму можно использовать для выяснения, кто зарегистрирован.
         */
        $this->session->set(
            'flash_notice',
            'Письмо со ссылкой для смены пароля отправлено на указанный вами адрес.'
        );

        return $this->redirect('login');
    }

    // ---------------------------------------------------------------- смена пароля

    /*
 * Токен смены пароля не должен возвращаться в `Location`.
 *
 * Ссылка из письма приходит с токеном в адресе — иначе её не открыть, и первая
 * страница отдаёт её как было. Дальше токен живёт в сессии: при возврате на
 * форму после ошибки он больше не подставляется в адрес, а значит, не попадает
 * в историю браузера, в журналы сервера и прокси и в заголовок `Referer`.
 * Из адресной строки его убирает скрипт `token-in-url.js`.
 */
private const RESET_TOKEN = 'reset_token';

public function showReset(Request $request): Response
    {
        $token = (string) $request->queryParam('token', '');

        if ($token !== '') {
            $this->session->set(self::RESET_TOKEN, $token);
        }

        return $this->render('auth/reset', [
            'title'  => 'Новый пароль',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'token'  => $token !== ''
                ? $token
                : (string) $this->session->get(self::RESET_TOKEN, ''),
            'minLen' => $this->auth->minPasswordLength(),
        ]);
    }

    public function reset(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $token = (string) $request->postParam('token', '');
        $password = (string) $request->postParam('password', '');
        $password2 = (string) $request->postParam('password2', '');

        if ($password !== $password2) {
            $this->session->set('flash_error', 'Пароли не совпадают');

            return $this->rememberResetToken($token);
        }

        $result = $this->service->resetPasswordWithToken($token, $password);

        if (($result['ok'] ?? false) !== true) {
            // Обвязка по месту: речь о смене пароля, а не об отправке письма.
            $this->session->set('flash_error', 'Не удалось сменить пароль: ' . (string) $result['error']);

            if (!empty($result['retryable'])) {
                return $this->rememberResetToken($token);
            }

            $this->session->remove(self::RESET_TOKEN);

            return $this->redirect('login');
        }

        $this->session->remove(self::RESET_TOKEN);
        $this->session->set(
            'flash_notice',
            !empty($result['blocked'])
                ? 'Пароль изменён, но учётная запись заблокирована. Обратитесь к администратору.'
                : 'Пароль изменён. Войдите с новым паролем.'
        );

        return $this->redirect('login');
    }

    /**
     * Вернуть человека к форме, не называя токен в адресе.
     *
     * Токен кладётся в сессию, и следующая страница подставит его в скрытое
     * поле формы. В `Location` его нет — а значит, нет и в истории браузера,
     * и в журнале, и в `Referer` следующего запроса.
     */
    private function rememberResetToken(string $token): Response
    {
        if ($token !== '') {
            $this->session->set(self::RESET_TOKEN, $token);
        }

        return $this->redirect('auth/reset');
    }

    /**
     * Правка своих данных — только для сотрудника.
     *
     * Ученик живёт в своей таблице, и формы здесь писали бы в `users`, где его
     * строки больше нет. Без проверки молчаливый обновилось бы ноль строк, а
     * человек прочитал бы «Данные сохранённые» — сообщение о том, чего не
     * произошло. Отказ честнее: страница ученика это знает и форм не показывает.
     */
    private function requireStaffProfile(): void
    {
        if ($this->auth->student() !== null) {
            throw new HttpException(403, 'Эта страница для сотрудников');
        }
    }

    public function profile(Request $request): Response
    {
        /*
         * Ученик — не сотрудник, и сотрудническая карточка ему не годится.
         *
         * Раньше он был строкой `users`, и страница читала у него дату
         * рождения, подтверждение адреса и аватар. После разделения таблиц этих
         * сведений у него нет вовсе, и обращение к ним падало бы 500. Формы
         * здесь же писали бы в `users` — таблицу, где его строки больше нет.
         *
         * Поэтому ученику показывается его собственная карточка. Ссылка «Мои
         * данные» стоит на главной безусловно, и отвечать ей отказом значило
         * бы вести человека на сломанную страницу.
         */
        $student = $this->auth->student();

        if ($student !== null) {
            return $this->render('auth/profile_student', [
                'title'   => 'Мои данные',
                'error'   => $this->session->pull('flash_error'),
                'notice'  => $this->session->pull('flash_notice'),
                'student' => $student,
            ]);
        }

        $row = $this->users->findRow((int) $this->auth->id());

        return $this->render('auth/profile', [
            'title'  => 'Мои данные',
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
            'minLen' => $this->auth->minPasswordLength(),
            'row'    => $row,
            'old'    => $this->session->get('profile_old', []),
            // Ошибки забираются, а не читаются: подсветка должна погаснуть
            // после следующего открытия страницы, иначе человек увидит
            // красное поле, которое он давно исправил.
            'errors' => $this->session->pull('profile_errors', []),
        ]);
    }

    /**
     * Правка своих данных: фамилия, имя, отчество, дата рождения, ВКонтакте.
     *
     * Поля имени те же, что и на странице пользователя у администратора:
     * правило одно, чтобы человек не удивлялся, что его имя здесь вводится
     * одной строкой, а там — тремя.
     */
    public function saveProfile(Request $request): Response
    {
        $this->requireStaffProfile();
        $this->requireToken($request, $this->csrf);

        $last = trim((string) $request->postParam('last_name', ''));
        $first = trim((string) $request->postParam('first_name', ''));
        $middle = trim((string) $request->postParam('middle_name', ''));
        $birthDate = trim((string) $request->postParam('birth_date', ''));
        $vkUrl = trim((string) $request->postParam('vk_url', ''));

        /*
         * Отказ помнит и значения, и конкретное поле, из-за которого он был.
         *
         * Значения нужны, чтобы человек не набирал всё заново. Поле нужно,
         * чтобы подсветить именно его: сообщение «Укажите фамилию и имя» на
         * странице целиком оставляет вопрос — какое из двух полей пустое, и
         * приходится угадывать. Подсветка поля — приём шаблона: .is-invalid на
         * поле и подпись под ним.
         */
        $fail = function (string $message, string $field = '') use (
            $last,
            $first,
            $middle,
            $birthDate,
            $vkUrl
        ): Response {
            $this->session->set('profile_old', [
                'last_name'   => $last,
                'first_name'  => $first,
                'middle_name' => $middle,
                'birth_date'  => $birthDate,
                'vk_url'      => $vkUrl,
            ]);

            if ($field !== '') {
                $this->session->set('profile_errors', [$field => $message]);
            }

            $this->session->set('flash_error', $message);

            return $this->redirect('profile');
        };

        // Без отчества можно обойтись, без фамилии и имени — нет: на них
        // строится всё остальное, включая логины учеников.
        if ($last === '') {
            return $fail(Messages::requiredText('фамилию'), 'last_name');
        }

        if ($first === '') {
            return $fail(Messages::requiredText('имя'), 'first_name');
        }

        if (mb_strlen(UserRepository::composeName($last, $first, $middle), 'UTF-8') > 191) {
            return $fail(Messages::maxLength(191));
        }

        $cleanDate = null;

        if ($birthDate !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);

            if ($parsed === false || $parsed->format('Y-m-d') !== $birthDate) {
                return $fail(Messages::DATE, 'birth_date');
            }

            // Дата в будущем — опечатка, а не дата рождения.
            if ($parsed > new \DateTimeImmutable('today')) {
                return $fail('Дата рождения не может быть в будущем', 'birth_date');
            }

            $cleanDate = $birthDate;
        }

        $cleanVk = null;

        if ($vkUrl !== '') {
            $cleanVk = VkUrl::normalize($vkUrl);

            if ($cleanVk === null) {
                return $fail('Ссылка должна вести на профиль ВКонтакте: vk.com или vk.ru', 'vk_url');
            }
        }

        $this->users->update((int) $this->auth->id(), [
            'last_name'   => $last,
            'first_name'  => $first,
            'middle_name' => $middle,
            'birth_date'  => $cleanDate,
            'vk_url'      => $cleanVk,
        ]);

        $this->session->remove('profile_old');
        $this->session->remove('profile_errors');
        $this->session->set('flash_notice', 'Данные сохранены');

        return $this->redirect('profile');
    }

    /**
     * Личный размер шрифта: мелкий, нормальный, крупный.
     *
     * Отдельная форма и отдельный адрес, а не поле внутри «О себе»: у
     * сотрудника и у ученика разные страницы профиля и разные таблицы, а
     * свойство одно и чинится в одном месте. Собрав всё в одну форму
     * «О себе», пришлось бы для ученика держать вторую копию той же логики —
     * и проверку, и сохранение, и сообщение об ошибке.
     *
     * Обработчик работает и для сотрудника, и для ученика: разбирается он
     * сам, а не по требованию страницы. Это то отличие, которое отделяет его
     * от `saveProfile` — там сотрудничество проверяется в начале, потому что
     * форма правит сведения, существующие только у сотрудника. Размер шрифта
     * есть у обоих.
     *
     * Сохранение без JavaScript: значение уходит обычной формой, страница
     * перезагружается, и ядро при отрисовке читает его из базы заново —
     * объект пользователя живёт один запрос.
     */
    public function saveFontSize(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        // Значение из запроса приводится к списку, а не проверяется по месту:
        // три размера общаются и с CSS, и с формой, и одной проверкой на все
        // три места обойтись дешевле, чем тремя.
        $fontSize = User::fontSizeOf($request->postParam('font_size'));

        $student = $this->auth->student();

        if ($student !== null) {
            $this->students->setFontSize($student->id, $fontSize);
        } else {
            $this->users->setFontSize((int) $this->auth->id(), $fontSize);
        }

        /*
         * Сообщение называет именно то, что человек увидит после перезагрузки:
         * он вернётся на ту же страницу уже в новом размере, и если сказать
         * просто «Сохранено», останется вопрос — чего именно.
         */
        $this->session->set('flash_notice', 'Размер шрифта сохранён');

        return $this->redirect('profile');
    }

    /**
     * Личная тема оформления: тёмная или светлая.
     *
     * Решение владельца от 24.10.2026 (волна E): тема живёт в профиле, а не
     * в браузере. Обработчик один на двух потребителей — как и у размера
     * шрифта, у сотрудника и ученика разные страницы, а свойство одно:
     *
     *   - форма в профиле отправляется обычным POST: страница перезагружается
     *     с сообщением «Тема сохранена»;
     *   - кнопка в шапке шлёт данные без перезагрузки (X-Requested-With) и
     *     получает JSON: тема на странице применяется сразу, без ожидания.
     *
     * Правило приведения одно на всё — `User::themeOf`: в базу попадает
     * только dark или light, и оболочка при отрисовке ничего не проверяет.
     */
    public function saveTheme(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $theme = User::themeOf($request->postParam('theme'));

        $student = $this->auth->student();

        if ($student !== null) {
            $this->students->setTheme($student->id, $theme);
        } else {
            $this->users->setTheme((int) $this->auth->id(), $theme);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'theme' => $theme]);
        }

        $this->session->set('flash_notice', 'Тема сохранена');

        return $this->redirect('profile');
    }

    /**
     * Аватар: загрузка и удаление одной формой.
     *
     * Форма одна, а действий два — по нажатой кнопке. Две формы рядом путались
     * бы: человек выбрал файл, нажал «Убрать» и удивился, что файл остался
     * лежать на диске.
     *
     * Старый файл удаляется, а не остаётся навсегда: имена у файлов случайные,
     * и без уборки каждая замена аватара оставляла бы в каталоге загрузок
     * файлы, на которые никто больше не ссылается.
     */
    public function saveAvatar(Request $request): Response
    {
        $this->requireStaffProfile();
        $this->requireToken($request, $this->csrf);

        $userId = (int) $this->auth->id();
        $previous = $this->users->avatarUrl($userId);

        if ($request->postParam('remove') !== null) {
            if ($previous === null) {
                $this->session->set('flash_notice', 'Аватара и не было');

                return $this->redirect('profile');
            }

            $this->users->setAvatarUrl($userId, '');
            $this->uploads->delete($previous);
            $this->session->set('flash_notice', 'Аватар убран');

            return $this->redirect('profile');
        }

        $file = $request->file('avatar');

        /*
         * Пустое поле — не ошибка: человек нажал «Загрузить», ничего не выбрав.
         * Отвечать на это «Файл не выбран» значило бы ругаться на пустое
         * намерение.
         */
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $this->redirect('profile');
        }

        $result = $this->uploads->store($file);

        if (($result['ok'] ?? false) !== true) {
            $this->session->set('flash_error', (string) ($result['error'] ?? 'Не удалось сохранить аватар'));

            return $this->redirect('profile');
        }

        $this->users->setAvatarUrl($userId, (string) $result['url']);
        $this->uploads->delete($previous);
        $this->session->set('flash_notice', 'Аватар сохранён');

        return $this->redirect('profile');
    }

    public function changePassword(Request $request): Response
    {
        $this->requireStaffProfile();
        $this->requireToken($request, $this->csrf);

        $result = $this->service->changeOwnPassword(
            (int) $this->auth->id(),
            (string) $request->postParam('current_password', ''),
            (string) $request->postParam('new_password', ''),
            $request->ip()
        );

        $ok = ($result['ok'] ?? false) === true;

        $this->session->set(
            $ok ? 'flash_notice' : 'flash_error',
            $ok ? 'Пароль изменён' : (string) $result['error']
        );

        return $this->redirect('profile');
    }
}
