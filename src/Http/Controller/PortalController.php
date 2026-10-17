<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Database;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\Settings;
use MyEdu\Core\View;
use MyEdu\Plugin\PluginManager;
use MyEdu\Repository\AssignmentRepository;

/**
 * Главная и личный кабинет.
 *
 * На главной видно, что пользователю доступно прямо сейчас. Сверху — работы:
 * по решению владельца проекта все выдачи идут через работу, и именно её
 * ученик должен видеть первой. Ниже — задания от плагинов: ядро не знает ни
 * про тесты, ни про игры, ни про тренажёры, и список собирают плагины.
 *
 * Панель управления доступна только тем, у кого есть право panel.access.
 */
final class PortalController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly View $view,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly Session $session,
        private readonly PluginManager $plugins,
        private readonly AssignmentRepository $assignments
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        /*
         * Гость попадает на адрес сайта и сразу должен увидеть форму, которой
         * воспользуется.
         *
         * Раньше здесь была страница «С чего начать» с текстом и тремя
         * ссылками, а форма входа жила по отдельному адресу. Человек, пришедший
         * по напечатанному листу, оказывался в витрине и должен был ещё раз
         * выбрать, куда нажать. Теперь по адресу сайта открывается вход
         * ученика: именно его логин и пароль напечатаны на листе, и именно он
         * приходит по этому адресу. Сотрудник переключается на свою вкладку в
         * шапке.
         */
        if ($user === null) {
            return $this->render('auth/login_student', [
                'title'  => 'Вход',
                'error'  => $this->session->pull('flash_error'),
                'notice' => $this->session->pull('flash_notice'),
                'old'    => (string) $this->session->pull('last_identifier', ''),
            ]);
        }

        return $this->render('portal/index', [
            /*
             * Работы идут первыми: по решению владельца проекта все выдачи
             * проходят через работу, и именно её ученик должен видеть на
             * главной.
             */
            'works' => $this->publishedWorks($user->id),
        ]);
    }

    /**
     * Опубликованные работы с прогрессом ученика.
     *
* Учитываются выдачи: на главной ученик видит только то, что ему лично
 * выдали и что ещё не закрыто по сроку.
     *
     * @return list<array<string,mixed>>
     */
    private function publishedWorks(int $userId): array
    {
        $rows = $this->assignments->allPublished(
            $userId,
            $this->auth->can(Role::TESTS_MANAGE)
        );

        $attempts = $this->db->all(
            'SELECT assignment_id, status, percent, grade
               FROM assignment_attempts
              WHERE student_id = :user
           ORDER BY id',
            ['user' => $userId]
        );

        $latest = [];

        foreach ($attempts as $attempt) {
            $latest[(int) $attempt['assignment_id']] = $attempt;
        }

        $works = [];

        foreach ($rows as $row) {
            $mine = $latest[(int) $row['id']] ?? null;

            $works[] = $row + [
                'my_status'  => $mine['status'] ?? null,
                'my_percent' => $mine['percent'] ?? null,
                'my_grade'   => $mine['grade'] ?? null,
            ];
        }

        return $works;
    }

    public function panel(Request $request): Response
    {
        return $this->render('portal/panel', [
            'classes'    => $this->can(Role::CLASSES_MANAGE) ? $this->classSummary() : [],
            'users'      => $this->can(Role::USERS_MANAGE) ? $this->userSummary() : null,
            'schoolName' => $this->settings->schoolName(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function classSummary(): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.title,
                    (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) AS student_count
               FROM classes c
           ORDER BY c.name
           LIMIT 20'
        );
    }

    /** @return array<string,mixed> */
    private function userSummary(): array
    {
        /*
         * Ученики в счёт пользователей не входят.
         *
         * Ученик — отдельная сущность, заводится завучем в списке класса и
         * живёт своей жизнью: у него нет ни почты, ни ролей сотрудника. Список
         * пользователей его тоже не показывает (allWithRoles() отсекает по
         * роли), так что «27 пользователей» рядом с «25 учеников» считало
         * одних и тех же людей дважды.
         */
        return [
            'total'    => (int) $this->db->value(
                "SELECT COUNT(*) FROM users u
                  WHERE NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role_code = 'student')"
            ),
            'pending'  => (int) $this->db->value(
                "SELECT COUNT(*) FROM users u
                  WHERE u.status = 'pending'
                    AND NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role_code = 'student')"
            ),
            'students' => (int) $this->db->value(
                "SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE role_code = 'student'"
            ),
            'classes'  => (int) $this->db->value('SELECT COUNT(*) FROM classes'),
        ];
    }

    private function can(string $capability): bool
    {
        return $this->auth->can($capability);
    }
}