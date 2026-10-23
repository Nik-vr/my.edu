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
         *
         * Проверяется «вошёл ли кто-нибудь», а не «вошёл ли сотрудник»:
         * `Auth::user()` у ученика — null, и прежняя проверка возвращала ему
         * форму входа, которую он уже прошёл. Вошедший человек должен попадать
         * на главную со своими работами, независимо от того, из какой таблицы
         * он пришёл.
         */
        if (!$this->auth->check()) {
            return $this->render('auth/login_student', [
                'title'  => 'Вход',
                'error'  => $this->session->pull('flash_error'),
                'notice' => $this->session->pull('flash_notice'),
                'old'    => (string) $this->session->pull('last_identifier', ''),
            ]);
        }

        return $this->render('portal/home', [
            /*
             * Работы идут первыми: по решению владельца проекта все выдачи
             * проходят через работу, и именно её человек должен видеть на
             * главной.
             */
            'works' => $this->publishedWorks(),
            /*
             * Сведения о школе — тем, кому положено их видеть. Ученику они
             * не достаются: сводка просто не считается, и на странице её нет.
             */
            'classes'    => $this->can(Role::CLASSES_MANAGE) ? $this->classSummary() : [],
            'users'      => $this->can(Role::USERS_MANAGE) ? $this->userSummary() : null,
            'schoolName' => $this->settings->schoolName(),
        ]);
    }

    /**
     * Опубликованные работы с прогрессом вошедшего.
     *
     * Учитываются выдачи: на главной человек видит только то, что ему
     * лично выдали и что ещё не закрыто по сроку.
     *
     * @return list<array<string,mixed>>
     */
    private function publishedWorks(): array
    {
        $actor = $this->auth->actor();

        if ($actor === null) {
            return [];
        }

        /*
         * Ученику достаётся выданное ему, сотруднику с правом ведения работ —
         * всё опубликованное: он и выдаёт работу, и проверяет её, и не должен
         * терять из виду выданное только потому, что класс ещё не заведён.
         *
         * Сотруднику без этого права (наблюдатель) — ничего. Выдача привязана
         * к ученику, и собственных выдач у сотрудника не бывает, поэтому
         * список его работ здесь пуст, а не «все работы школы»: последнее
         * показало бы ему чужие классы. Раньше это скрывалось за тем, что
         * номер сотрудника подставлялся в ученическую колонку и просто не
         * находил выдач, — но совпадение номеров из разных таблиц гарантией
         * не является.
         */
        if ($actor->isStudent()) {
            $rows = $this->assignments->allPublished($actor->studentId, false);
        } elseif ($this->can(Role::TESTS_MANAGE)) {
            $rows = $this->assignments->allPublished(null, true);
        } else {
            $rows = [];
        }

        /*
         * Прогресс берётся по той же колонке, в которую попытка записывается:
         * ученик — student_id, сотрудник, прогоняющий работу сам, — staff_id.
         * Общей колонки для них нет, и подставлять чужой номер значило бы
         * показать человеку чужой прогресс.
         */
        $column = $actor->isStudent() ? 'student_id' : 'staff_id';

        $attempts = $this->db->all(
            "SELECT assignment_id, status, percent, grade
               FROM assignment_attempts
              WHERE {$column} = :actor
           ORDER BY id",
            ['actor' => $actor->studentId ?? $actor->staffId]
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

    /**
     * Старый адрес панели управления.
     *
     * Панель и главная ученика стали одной страницей: `/portal` открывает всё
     * сразу — и работы, и сводки школы, если права позволяют. Старый адрес
     * не исчезает: на него стоят закладки и ссылки в письмах, поэтому он
     * переадресует на новую страницу, а не отвечает отказом.
     */
    public function panel(Request $request): Response
    {
        return $this->redirect('portal');
    }

    /** @return list<array<string,mixed>> */
    private function classSummary(): array
    {
        /*
         * Ученики считаются по их собственной таблице: класс принадлежит
         * ученику (students.class_id), а не наоборот, поэтому счётчик — это
         * просто число строк с этим классом. Таблица class_students упразднена:
         * в ней хранилось членство, а теперь у ученика один класс.
         */
        return $this->db->all(
            'SELECT c.id, c.name, c.title,
                    (SELECT COUNT(*) FROM students cs WHERE cs.class_id = c.id) AS student_count
               FROM classes c
           ORDER BY c.name
           LIMIT 20'
        );
    }

    /** @return array<string,mixed> */
    private function userSummary(): array
    {
        /*
         * Ученики считаются по своей таблице, а не по ролям.
         *
         * Ученик — отдельная сущность, заводится завучем в списке класса и
         * живёт своей жизнью: у него нет ни почты, ни ролей сотрудника. Роли
         * «student» в базе больше не существует, и подсчёт по ней дал бы ноль
         * при живых классах. Список пользователей учеников не показывает,
         * поэтому «27 пользователей» рядом с «25 учеников» считает разных
         * людей и не удваивает никого.
         */
        return [
            'total'    => (int) $this->db->value('SELECT COUNT(*) FROM users'),
            'pending'  => (int) $this->db->value("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
            'students' => (int) $this->db->value('SELECT COUNT(*) FROM students'),
            'classes'  => (int) $this->db->value('SELECT COUNT(*) FROM classes'),
        ];
    }

    private function can(string $capability): bool
    {
        return $this->auth->can($capability);
    }
}