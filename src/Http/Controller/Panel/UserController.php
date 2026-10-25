<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\AuthService;
use MyEdu\Auth\Role;
use MyEdu\Auth\UserRepository;
use MyEdu\Auth\VkUrl;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
    use MyEdu\Core\ImageUpload;
    use MyEdu\Core\MailerFactory;
    use MyEdu\Core\MailerInterface;
    use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\Settings;
use MyEdu\Core\SystemMailer;
use MyEdu\Core\SystemUpdate;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Ui\Messages;

/**
 * Управление пользователями, ролями и настройками сайта.
 *
 * Правило владельца: у владельца сайта роль «Администратор» снять нельзя.
 * Остальные администраторы равны между собой и полностью управляют сайтом —
 * защищён только владелец.
 */
final class UserController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly Settings $settings,
        private readonly AuthService $authService,
        private readonly ImageUpload $uploads,
        private readonly SystemUpdate $update,
        private readonly string $baseUrl
    ) {
    }

    // ------------------------------------------------------------------ настройки

    /**
     * Название школы и прочие настройки сайта.
     *
     * Отдельная страница, а не поле в профиле: настройку меняет администратор,
     * а видят её все.
     */
    public function settings(Request $request): Response
    {
        /*
         * Сведения об обновлении тянутся с GitHub и могут не прийти: сервер без
         * доступа в интернет — обычное дело для школьного хостинга. Тогда блок
         * обновления говорит, что проверить не удалось, и ничего не ломает.
         */
        $release = $this->update->latest();

        return $this->render('panel/settings', [
            'title'       => 'Настройки сайта',
            'schoolName'  => $this->settings->schoolName(),
            'release'     => $release,
            'version'     => $this->update->currentVersion(),
            'hasUpdate'   => $this->update->hasUpdate($release),
            'releaseOlder' => $this->update->isReleaseOlder($release),
            'mail'        => $this->mailSetupReport(),
            'notice'      => $this->session->pull('flash_notice'),
            'error'       => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Тестовое письмо на адрес администратора.
     *
     * Проверяет не настройку, а сам путь отправки: тот же драйвер и те же
     * заголовки, что у письма подтверждения, которым на шаред-хостинге не
     * доходят письма новым пользователям. Результат остаётся на странице —
     * в отличие от причины отказа, которая раньше лежала только в журнале PHP.
     */
    public function mailTest(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $user = $this->auth->user();
        $email = (string) ($user?->email ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->session->set(
                'flash_error',
                'У вашей учётной записи не задан адрес почты — тестовое письмо некуда отправить.'
            );

            return $this->redirect('panel/settings');
        }

        $mailer = $this->app->get(MailerInterface::class);
        $sent = (new SystemMailer($mailer))->sendTest($email, (string) ($user?->fullName ?? ''));

        if ($sent) {
            $notice = 'Тестовое письмо принято к отправке на ' . $email . '.';

            /*
             * Самое важное здесь — предупреждение при «успехе».
             *
             * Проблема владельца (24.10.2026, задача 7): письмо «принято»,
             * а на адрес не приходит. Если PHP сообщил о проблеме на пути
             * (sendmail не выполняется, нет прав, нет места) — это и есть,
             * скорее всего, причина, и показать её нужно прямо здесь, а не в
             * журнале хостинга.
             */
            $warning = $mailer->lastWarning();

            if ($warning !== null) {
                $notice .= ' Но отправка прошла не чисто: ' . $warning
                    . '. Скорее всего, из-за этого письмо и не дошло.';
            } else {
                $notice .= ' Если его нет и в папке «Спам», значит, письмо ушло, но почта его отклонила:'
                    . ' посмотрите раздел «Отправка писем» на этой же странице — там проверки домена'
                    . ' отправителя (MX и SPF) и путь отправки.';
            }

            $this->session->set('flash_notice', $notice);

            return $this->redirect('panel/settings');
        }

        $this->session->set(
            'flash_error',
            'Тестовое письмо отправить не удалось: '
            . (string) ($mailer->lastError() ?? 'причина неизвестна — подробности в журнале PHP, поиск по строке «[mail]».')
            . '. Та же причина мешает письмам с подтверждением адреса.'
        );

        return $this->redirect('panel/settings');
    }

    /**
     * Как настроена отправка писем — для блока на странице настроек.
     *
     * Предупреждения собраны по отказам, которые чаще всего случаются на
     * шаред-хостинге. Все они означают одно и то же — письма не уходят, — но
     * выглядят по-разному, и без этого владелец видит только «письма не
     * приходят» без единой подсказки, куда смотреть.
     *
     * @return array{label:string,from:string,email:string,canSend:bool,warnings:list<string>}
     */
    private function mailSetupReport(): array
    {
        $setup = $this->app->get(MailerFactory::class)->setup();

        $fromEmail = trim($this->config->string('mail.from_email', ''));
        $siteHost = (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?? '');
        $warnings = [];

        if ($setup->isLog()) {
            $warnings[] = 'Драйвер «журнал»: письма не отправляются, а пишутся в var/log/mail-*.log. '
                . 'Для реальной отправки укажите mail.driver = "mail" или "smtp".';
        }

        if ($setup->driver === 'mail' && !function_exists('mail')) {
            $warnings[] = 'Функция mail() отключена на хостинге, письма отправить нельзя. '
                . 'Переключите mail.driver на "smtp".';
        }

        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $warnings[] = 'Адрес отправителя mail.from_email не задан: mail() без него письма не отправляет.';
        } elseif ($siteHost !== '' && strcasecmp($this->domainOf($fromEmail), $siteHost) !== 0) {
            $warnings[] = 'Адрес отправителя ' . $fromEmail . ' не на домене вашего сайта (' . $siteHost . '). '
                . 'Почтовые серверы отклоняют такие письма, поэтому письмо уходит, но не доходит. '
                . 'Укажите адрес на своём домене.';
        }

        if ($setup->driver === 'mail' && $this->config->bool('mail.envelope_from', false)) {
            $warnings[] = 'Включён mail.envelope_from: mail() передаёт пятый параметр «-f», '
                . 'который на многих хостингах запрещён — из-за него отправка возвращает отказ. '
                . 'Выключите его, если адрес отправителя не нужно задавать явно.';
        }

        /*
         * Диагностика окружения отправки — решение владельца от 24.10.2026
         * (задача 7): письмо «принято к отправке», но не доходит, а прежних
         * проверок хватало только на настройки системы. Здесь проверяется то,
         * что прячется за «успехом» mail(): сама утилита отправки и DNS
         * домена отправителя — две причины, по которым письмо уходит из PHP,
         * но не доходит до адресата.
         *
         * Проверки только для драйвера mail(): у журнала отправки нет, а у
         * SMTP свой путь и своя диагностика в lastError(). DNS-запросы
         * оборачиваются в @: на сервере без доступа к DNS они отказывают сами
         * по себе, и отказ разбора не должен ломать страницу настроек.
         */
        if ($setup->driver === 'mail' && function_exists('mail')) {
            $warnings = array_merge($warnings, $this->sendmailWarnings());
            $warnings = array_merge($warnings, $this->dnsWarnings($fromEmail));
        }

        $email = (string) ($this->auth->user()?->email ?? '');

        return [
            'label'    => $setup->label,
            'from'     => $fromEmail,
            'email'    => $email,
            'canSend'  => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            'warnings' => $warnings,
        ];
    }

    /** Домен адреса почты, без «@» и без учёта регистра. */
    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /**
     * Проверка самой утилиты отправки: доходит ли письмо до неё.
     *
     * Диагностика проблемы владельца (24.10.2026, задача 7): mail()
     * отвечает успехом, а письма нет. Одна из причин — утилита отправки
     * недоступна (не задан путь, файла нет, прав нет), и часть хостингов
     * при этом честно отвечает, что письмо принято: отдавать его было некуда.
     *
     * @return list<string>
     */
    private function sendmailWarnings(): array
    {
        $path = trim((string) ini_get('sendmail_path'));

        if ($path === '') {
            return [
                'В php.ini не задан sendmail_path: mail() не знает, кому отдать письмо, '
                . 'и на части хостингов при этом считает отправку удачной. Попросите хостинг '
                . 'указать путь к утилите отправки (обычно /usr/sbin/sendmail) или переключите '
                . 'mail.driver на "smtp".',
            ];
        }

        /*
         * Первое слово пути — сама утилита: путь может содержать аргументы
         * (/usr/sbin/sendmail -t -i), и проверять нужно именно файл.
         * Относительное имя (sendmail без пути) ищется в PATH самим PHP —
         * такие случаи не проверяются: поле может быть и не абсолютным.
         */
        $binary = strtok($path, " \t");
        $binary = is_string($binary) ? $binary : '';

        if (str_starts_with($binary, '/') && !is_file($binary)) {
            return [
                'Утилита отправки не найдена по пути ' . $binary . ': письмо некуда отдать, '
                . 'и mail() при этом может отвечать успехом — письмо просто исчезает. '
                . 'Уточните у хостинга правильный sendmail_path или переключите mail.driver на "smtp".',
            ];
        }

        return [];
    }

    /**
     * Проверка домена отправителя в DNS: MX и SPF.
     *
     * Тоже диагностика «принято, но не дошло» (та же задача 7): домен
     * отправителя участвует в проверке у получателя — по обратному адресу
     * идут возвраты, а по SPF почтовые сервисы решают, подделка ли письмо.
     * Обе проверки читаются из DNS самого домена, и владелец может исправить
     * их у регистратора, не имея доступа ни к каким настройкам сервера.
     *
     * @return list<string>
     */
    private function dnsWarnings(string $fromEmail): array
    {
        $domain = $this->domainOf($fromEmail);

        // Пустое, IP-подобное или локальное имя — DNS спрашивать не о чем.
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return [];
        }

        $warnings = [];

        $mailServers = [];
        $hasMx = @getmxrr($domain, $mailServers);

        if (!$hasMx || $mailServers === []) {
            $warnings[] = 'У домена ' . $domain . ' нет почтовых серверов (запись MX не найдена): '
                . 'почтовые сервисы отклоняют письма от доменов, у которых их нет, — письмо уходит, '
                . 'но не доходит. Добавьте MX-запись у регистратора домена или у хостинга.';
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($domain, DNS_TXT) ?: [];
            $hasSpf = false;

            foreach ($records as $record) {
                $text = isset($record['txt']) ? (string) $record['txt'] : '';

                if (stripos($text, 'v=spf1') !== false) {
                    $hasSpf = true;
                    break;
                }
            }

            if (!$hasSpf) {
                $warnings[] = 'У домена ' . $domain . ' нет SPF-записи (txt-запись «v=spf1»): '
                    . 'mail.ru и другие почтовые сервисы чаще отклоняют письма без SPF как подделку. '
                    . 'Добавьте SPF-запись у регистратора домена — она разрешает вашему серверу '
                    . 'отправлять почту от имени этого домена.';
            }
        }

        return $warnings;
    }

    public function saveSettings(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $schoolName = trim((string) $request->postParam('school_name', ''));

        // 191 символ — предел колонки value в TEXT не нужен, но под печатный
        // лист и заголовки письма длинное название бессмысленно.
        if (mb_strlen($schoolName, 'UTF-8') > 191) {
            $this->session->set('flash_error', 'Название школы слишком длинное.');

            return $this->redirect('panel/settings');
        }

        $this->settings->setSchoolName($schoolName);

        $this->session->set(
            'flash_notice',
            $schoolName === '' ? 'Название школы очищено.' : 'Название школы сохранено: ' . $schoolName
        );

        return $this->redirect('panel/settings');
    }

    public function index(Request $request): Response
    {
        /*
         * Параметры role и q отсюда убраны: они читались и передавались в
         * шаблон, но ни форма их не содержала, ни выборка по ним фильтровала.
         * Страница выглядела готовой к фильтрации, а работала без неё.
         *
         * Если фильтр понадобится, это отдельная доработка: поле в форме плюс
         * условие в запросе. Возвращать одни только параметры бессмысленно —
         * они и создавали видимость функции, которой не было.
         */
        return $this->render('panel/users', [
            'title'      => 'Пользователи',
            'users'      => $this->users->allWithRoles(),
            'roleTitles' => $this->roleTitles(),
            'stats'      => $this->users->staffStats(),
            'isOwner'    => $this->auth->isOwner(),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Смена роли прямо из списка.
     *
     * Роль у человека одна, и меняется она одним выбором: отдельная форма с
     * кнопкой под каждым набором ролей требовала бы держать в таблице столько
     * форм, сколько сотрудников, а список ролей — перечислять в каждой.
     * Права не меняются: у завуч уже есть все права учителя, отдельная должность
     * «Учитель» ему не нужна.
     *
     * Возврат — на список: строка, у которой меняли роль, должна остаться на
     * своём месте, а не под сообщением на другой странице.
     */
    public function saveRole(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $userId = (int) $request->postParam('user_id', '0');
        $role = trim((string) $request->postParam('role', ''));

        if ($userId <= 0) {
            $this->session->set('flash_error', 'Не указан пользователь');

            return $this->redirect('panel/users');
        }

        $target = $this->users->findRow($userId);

        if ($target === null) {
            $this->session->set('flash_error', 'Пользователь не найден');

            return $this->redirect('panel/users');
        }

        if ($userId === $this->auth->id()) {
            $this->session->set('flash_error', 'Нельзя изменить собственную роль — попросите другого администратора');

            return $this->redirect('panel/users');
        }

        if (!in_array($role, Role::assignable(), true)) {
            $this->session->set('flash_error', 'Такой роли нет');

            return $this->redirect('panel/users');
        }

        // Владельца нельзя оставить без роли администратора: иначе у сайта
        // останется человек с правами, но без входа в панель управления.
        if ((int) $target['is_owner'] === 1 && $role !== Role::ADMIN) {
            $this->session->set('flash_error', 'У владельца сайта роль «Администратор» снять нельзя');

            return $this->redirect('panel/users');
        }

        $this->users->setRole($userId, $role, $this->auth->id());

        $name = (string) $target['full_name'];
        $this->session->set('flash_notice', $name . ': роль — ' . Role::title($role));

        return $this->redirect('panel/users');
    }

    /**
     * Страница одного пользователя: всё, что можно поменять.
     *
     * В списке пользователей остались только имена и кнопка «Редактировать»:
     * править роль, статус и данные в строке таблицы неудобно — рядом
     * находятся чужие строки, и одно нажатие «Сохранить» меняет не то, куда
     * целился взгляд. Здесь форма одна и относится ровно к одному человеку.
     *
     * Учеников здесь нет: они заводятся в классе и правятся там же.
     */
    public function edit(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');
        $target = $this->users->findRow($id);

        if ($target === null) {
            throw new HttpException(404, 'Пользователь не найден');
        }

        $roles = $this->users->rolesOf($id);

        return $this->render('panel/user_edit', [
            'title'      => 'Пользователь: ' . (string) $target['full_name'],
            'target'     => $target,
            'roles'      => $roles,
            'roleTitles' => $this->roleTitles(),
            'isSelf'     => $id === $this->auth->id(),
            'isOwnerRow' => (int) $target['is_owner'] === 1,
            'old'        => $this->session->get('user_edit_old', []),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

/**
 * Аватар сотрудника: загрузка и удаление одной формой.
 *
 * Тот же приём, что на странице своих данных: по нажатой кнопке — загрузка
 * или удаление, а не две формы рядом. Проверки те же: файл по содержимому, не
 * больше 3 МБ, старый файл удаляется с диска.
 *
 * Ученикам адрес недоступен: их правят в своём классе, и страницы сотрудника
 * у них нет — как нет её и для чтения.
 */
public function saveAvatar(Request $request, array $params): Response
{
    $this->requireToken($request, $this->csrf);

    $id = $this->intParam($params, 'id');
    $target = $this->users->findRow($id);

    if ($target === null) {
        throw new HttpException(404, 'Пользователь не найден');
    }

    $previous = $this->users->avatarUrl($id);
    $back = 'panel/users/' . $id;

    if ($request->postParam('remove') !== null) {
        if ($previous === null) {
            $this->session->set('flash_notice', 'Аватара и не было');

            return $this->redirect($back);
        }

        $this->users->setAvatarUrl($id, '');
        $this->uploads->delete($previous);
        $this->session->set('flash_notice', 'Аватар убран');

        return $this->redirect($back);
    }

    $file = $request->file('avatar');

    // Пустое поле — не ошибка: человек нажал «Загрузить», ничего не выбрав.
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $this->redirect($back);
    }

    $result = $this->uploads->store($file);

    if (($result['ok'] ?? false) !== true) {
        $this->session->set('flash_error', (string) ($result['error'] ?? 'Не удалось сохранить аватар'));

        return $this->redirect($back);
    }

    $this->users->setAvatarUrl($id, (string) $result['url']);
    $this->uploads->delete($previous);
    $this->session->set('flash_notice', 'Аватар сохранён');

    return $this->redirect($back);
}

/**
 * Данные пользователя: фамилия, имя, отчество, дата рождения, e-mail,
 * ссылка на профиль ВКонтакте.
     *
     * Пароля здесь нет намеренно. Администратор знает чужой пароль только
     * в одном случае — когда сам его выдал, — и оставлять его в чужом браузере
     * хуже, чем отправить ссылку: пароль оседает в переписке и в истории
     * браузера. Поэтому здесь кнопка отправки письма со ссылкой, а новый
     * пароль человек вводит сам.
     */
    public function saveProfile(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $target = $this->users->findRow($id);

        if ($target === null) {
            $this->session->set('flash_error', 'Пользователь не найден');

            return $this->redirect('panel/users');
        }

        $last = trim((string) $request->postParam('last_name', ''));
        $first = trim((string) $request->postParam('first_name', ''));
        $middle = trim((string) $request->postParam('middle_name', ''));
        $birthDate = trim((string) $request->postParam('birth_date', ''));
        $email = trim((string) $request->postParam('email', ''));
        $vkUrl = trim((string) $request->postParam('vk_url', ''));

        $fail = function (string $message) use ($id, $last, $first, $middle, $birthDate, $email, $vkUrl): Response {
            // Введённое возвращается в форму: иначе после ошибки пришлось бы
            // вбивать фамилию заново, а смена e-mail — самое длинное из полей.
            $this->session->set('user_edit_old', [
                'last_name'   => $last,
                'first_name'  => $first,
                'middle_name' => $middle,
                'birth_date'  => $birthDate,
                'email'       => $email,
                'vk_url'      => $vkUrl,
            ]);
            $this->session->set('flash_error', $message);

            return $this->redirect('panel/users/' . $id);
        };

        if ($last === '' || $first === '') {
            return $fail(Messages::requiredText('фамилию и имя'));
        }

        $fullName = UserRepository::composeName($last, $first, $middle);

        if (mb_strlen($fullName, 'UTF-8') > 191) {
            return $fail(Messages::maxLength(191));
        }

        $cleanDate = null;

        if ($birthDate !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);

            if ($parsed === false || $parsed->format('Y-m-d') !== $birthDate) {
                return $fail(Messages::DATE);
            }

            // Дата в будущем — опечатка, а не дата рождения.
            if ($parsed > new \DateTimeImmutable('today')) {
                return $fail('Дата рождения не может быть в будущем');
            }

            $cleanDate = $birthDate;
        }

/*
 * Пустой адрес — не «очистить поле», а «оставить как есть».
 *
 * Раньше пустое поле молча ставило NULL: адрес исчезал вместе со всеми
 * письмами, что на него приходили, а «восстановить пароль по почте» после
 * этого переставал работать навсегда. Вернуть адрес можно было только вручную в
 * базе, и человек узнавал об этом не сразу, а на первом «забыли пароль».
 *
 * Убирать адрес нельзя, а оставлять можно: человек, который его заводил,
 * вправе перестать им пользоваться. Поэтому пустое поле у человека без адреса
 * (ученик, сотрудник, которого завели без почты) — обычное дело, и оно
 * проходит молча.
 */
$currentEmail = trim((string) ($target['email'] ?? ''));

if ($email === '' && $currentEmail !== '') {
    return $fail(
        'Нельзя убрать адрес почты: по нему восстанавливается пароль. '
        . 'Если адрес сменился, напишите новый — письмо для подтверждения придёт на него'
    );
}

$cleanEmail = null;

if ($email !== '') {
            $cleanEmail = mb_strtolower($email);

            if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                return $fail(Messages::EMAIL);
            }

            // Адрес уникален: без проверки смена почты падала бы на ошибке
            // СУБД, и человек видел бы вместо причины пустую страницу.
            if ($this->users->emailExists($cleanEmail) && $this->users->emailOwnerId($cleanEmail) !== $id) {
                return $fail('Этот адрес почты уже занят другим пользователем');
            }
        }

        $cleanVk = null;

        if ($vkUrl !== '') {
            $cleanVk = VkUrl::normalize($vkUrl);

            if ($cleanVk === null) {
                return $fail('Ссылка должна вести на профиль ВКонтакте: vk.com или vk.ru');
            }
        }

        $emailChanged = $cleanEmail !== null && $cleanEmail !== (string) ($target['email'] ?? '');

        $this->users->update($id, [
            'last_name'   => $last,
            'first_name'  => $first,
            'middle_name' => $middle,
            'birth_date'  => $cleanDate,
            'vk_url'      => $cleanVk,
        ]);

        $confirmationSent = false;

        if ($emailChanged) {
            /*
             * Адрес проверяется здесь, чтобы форма не уводила администратора
             * на страницу, где он ничего не поймёт. Сервис проверяет его тоже
             * и сам: защита, живущая только в контроллере, обходится любым
             * другим вызовом, а логика смены адреса живёт в сервисе.
             */
            $takenBy = $this->users->emailOwnerId($cleanEmail ?? '');

            if ($takenBy !== null && $takenBy !== $id) {
                return $fail('Этот адрес почты уже занят другим пользователем');
            }

            $change = $this->authService->changeEmail($id, (string) $cleanEmail, $this->baseUrl);

            if (($change['ok'] ?? false) !== true) {
                return $fail((string) ($change['error'] ?? 'Не удалось сменить адрес почты'));
            }

            $confirmationSent = (bool) ($change['sent'] ?? false);
        } else {
            $this->users->update($id, ['email' => $cleanEmail]);
        }

        $this->session->remove('user_edit_old');
        $this->session->set('flash_notice', $emailChanged
            ? ($confirmationSent
                ? 'Данные сохранены. На новый адрес отправлена ссылка для подтверждения; до подтверждения вход по почте недоступен.'
                : 'Данные сохранены, но отправить письмо на новый адрес не удалось. Пользователь может запросить письмо повторно со страницы входа.')
            : 'Данные пользователя сохранены');

        return $this->redirect('panel/users/' . $id);
    }

    /**
     * Блокировка и разблокировка.
     *
     * Состояние живёт в колонке пользователя, а не в форме: форма отправляет
     * желаемое состояние и тем же адресом, каким снимается блокировка в меню
     * списка, — одна правка вместо двух.
     */
    public function saveStatus(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $userId = (int) $request->postParam('user_id', '0');
        $status = (string) $request->postParam('status', 'active');

        if (!in_array($status, ['active', 'blocked'], true)) {
            $status = 'active';
        }

        $target = $this->users->findRow($userId);

        if ($target === null || $userId === $this->auth->id()) {
            $this->session->set('flash_error', 'Нельзя изменить статус этой учётной записи');

            return $this->redirect('panel/users');
        }

        if ((int) $target['is_owner'] === 1 && $status === 'blocked') {
            $this->session->set('flash_error', 'Владельца сайта блокировать нельзя');

            return $this->redirect('panel/users/' . $userId);
        }

        $this->users->update($userId, ['status' => $status]);

        $this->session->set('flash_notice', $status === 'blocked'
            ? 'Доступ пользователю заблокирован'
            : 'Доступ пользователю восстановлен');

        return $this->redirect('panel/users/' . $userId);
    }

    /**
     * Сброс пароля: администратор отправляет письмо со ссылкой, а новый
     * пароль человек вводит сам.
     *
     * Пароль намеренно не показывается и не задаётся администратором: иначе
     * он оседает в чужом браузере и в переписке.
     */
    public function resetPassword(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $userId = (int) $request->postParam('user_id', '0');
        $target = $this->users->findRow($userId);

        if ($target === null) {
            $this->session->set('flash_error', 'Пользователь не найден');

            return $this->redirect('panel/users');
        }

        if ($target['email'] === null || trim((string) $target['email']) === '') {
            $this->session->set('flash_error', 'У пользователя нет адреса почты — письмо с ссылкой отправить некуда');

            return $this->redirect('panel/users');
        }

        $result = $this->authService->requestPasswordReset((string) $target['email'], $this->baseUrl);

        $this->session->set(
            $result['sent'] ? 'flash_notice' : 'flash_error',
            $result['sent']
                ? 'Письмо со ссылкой для смены пароля отправлено на ' . $target['email']
                : 'Не удалось отправить письмо. Проверьте настройку отправки писем.'
        );

        return $this->redirect('panel/users/' . $userId);
    }

    /** @return array<string,string> */
    private function roleTitles(): array
    {
        $rows = $this->db->all('SELECT code, title FROM roles');

        $titles = [];

        foreach ($rows as $row) {
            $titles[(string) $row['code']] = (string) $row['title'];
        }

        return $titles;
    }
}
