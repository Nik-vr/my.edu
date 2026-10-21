<?php

declare(strict_types=1);

namespace MyEdu\Http\Routes;

use MyEdu\Auth\Auth;
use MyEdu\Auth\AuthMailer;
use MyEdu\Auth\AuthService;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\SubjectRepository;
use MyEdu\Auth\TokenRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\SystemUpdate;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\MailerFactory;
use MyEdu\Core\Session;
use MyEdu\Core\Settings;
use MyEdu\Core\SiteUrl;
use MyEdu\Core\View;
use MyEdu\Http\Controller\AuthController;
use MyEdu\Http\Controller\HealthController;
use MyEdu\Http\Controller\Panel\AssignmentController;
use MyEdu\Http\Controller\Panel\AttemptReviewController;
use MyEdu\Http\Controller\Panel\ClassController;
use MyEdu\Http\Controller\Panel\EditorAssetController;
use MyEdu\Http\Controller\Panel\IssueController;
use MyEdu\Http\Controller\Panel\JournalController;
use MyEdu\Http\Controller\Panel\OtpImportController;
use MyEdu\Http\Controller\Panel\PluginController;
use MyEdu\Http\Controller\Panel\RatingController;
use MyEdu\Http\Controller\Panel\StudentController;
use MyEdu\Http\Controller\Panel\SubjectController;
use MyEdu\Http\Controller\Panel\UpdateController;
use MyEdu\Http\Controller\Panel\UserController;
use MyEdu\Http\Controller\Panel\WorkTransferController;
use MyEdu\Http\Controller\PortalController;
use MyEdu\Http\Controller\WorkController;
use MyEdu\Plugin\PluginManager;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\AssignmentDraftRepository;
use MyEdu\Repository\RatingRepository;

/**
 * Контроллеры карты: строятся один раз на запрос и делятся между файлами.
 *
 * Раньше они создавались в `Kernel::routes()` вперемешку с адресами, и список
 * занимал больше половины ядра. Теперь создание отделено от объявления
 * адресов, а разделено оно не по принципу «удобно читать», а по требованиям
 * контроллеров: некоторые нужны нескольким файлам.
 *
 * - `works` отвечает и за `/work/…`, и входит в `attemptsReview`: разбор
 *   ответа открывает работу ученика, и это должен быть тот же контроллер.
 * - `portal` обслуживает и `/`, и `/portal`, и `/panel`.
 * - `auth` обслуживает и адреса гостя, и `/profile`.
 *
 * Контроллеры сами по себе состояния не держат: это набор ссылок на сервисы,
 * поэтому лишний экземпляр не испортил бы страницу. Но два разных `WorkController`
 * в одной карте — это уже две правки одного адреса в двух местах, а карта
 * должна читаться как одно место.
 */
final class Controllers
{
    public readonly AuthController $auth;
    public readonly PortalController $portal;
    public readonly UserController $users;
    public readonly PluginController $plugins;
    public readonly WorkController $works;
    public readonly AssignmentController $assignments;
    public readonly EditorAssetController $editorAssets;
    public readonly WorkTransferController $transfer;
    public readonly OtpImportController $otpImport;
    public readonly AttemptReviewController $attemptsReview;
    public readonly IssueController $issues;
    public readonly JournalController $journal;
    public readonly RatingController $ratings;
    public readonly ClassController $classes;
    public readonly SubjectController $subjects;
    public readonly StudentController $students;
    public readonly HealthController $health;
    public readonly UpdateController $update;

    public function __construct(App $app)
    {
        $view = $app->get(View::class);
        $session = $app->get(Session::class);
        $csrf = $app->get(Csrf::class);
        $config = $app->get(Config::class);
        $db = $app->get(Database::class);
        $auth = $app->get(Auth::class);
        $settings = $app->get(Settings::class);
        $plugins = $app->get(PluginManager::class);
        $assignments = $app->get(AssignmentRepository::class);
        $drafts = $app->get(AssignmentDraftRepository::class);
        $users = $app->get(UserRepository::class);
        $subjects = $app->get(SubjectRepository::class);
        $students = $app->get(StudentRepository::class);
        $uploads = $app->get(ImageUpload::class);
        $baseUrl = $app->get(SiteUrl::class)->base();

        $this->auth = new AuthController(
            $app, $view, $session, $csrf, $config,
            $app->get(AuthService::class), $app->get(AuthMailer::class),
            $app->get(TokenRepository::class), $auth, $users, $uploads, $baseUrl
        );

        $this->portal = new PortalController(
            $app, $view, $config, $auth, $db, $settings, $session, $plugins, $assignments
        );

        $this->users = new UserController(
            $app, $db, $view, $session, $csrf, $config, $auth, $users, $settings,
            $app->get(AuthService::class), $uploads, $app->get(SystemUpdate::class), $baseUrl
        );

        $this->plugins = new PluginController(
            $app, $view, $session, $csrf, $config, $plugins, $auth, $users
        );

        $this->works = new WorkController(
            $view, $session, $csrf, $config, $auth, $db, $assignments, $plugins
        );

        $this->assignments = new AssignmentController(
            $view, $session, $csrf, $config, $auth, $db, $assignments, $drafts, $plugins
        );

        $this->editorAssets = new EditorAssetController($auth, $csrf, $view, $uploads);

        // Перенос работ между инсталляциями: выгрузка файла и внос такого же файла.
        $this->transfer = new WorkTransferController(
            $auth, $csrf, $session, $view, $db, $assignments, $plugins, $subjects, $app
        );

        // Перенос теста из OnlineTestPad — вторая вкладка той же страницы.
        $this->otpImport = new OtpImportController(
            $auth, $csrf, $session, $view, $db, $plugins, $subjects, $app
        );

        $this->attemptsReview = new AttemptReviewController(
            $view, $session, $csrf, $config, $auth, $assignments, $plugins, $this->works
        );

        // Мастер выдачи работ: работа, классы, ученики, срок.
        $this->issues = new IssueController(
            $view, $session, $csrf, $config, $auth, $db, $assignments
        );

        $this->journal = new JournalController(
            $view, $session, $auth, $assignments, $app->get(PluginManager::class)
        );

        $this->ratings = new RatingController($view, $auth, $app->get(RatingRepository::class));

        $this->classes = new ClassController(
            $app, $db, $view, $session, $csrf, $config, $auth,
            $app->get(ClassRepository::class), $subjects, $users, $students, $settings,
            $app->get(PluginManager::class)
        );

        $this->subjects = new SubjectController($view, $session, $csrf, $config, $subjects);

        $this->students = new StudentController(
            $app, $db, $view, $session, $csrf, $config, $auth, $students,
            $app->get(ClassRepository::class)
        );

        /*
         * Фабрика писем берётся из контейнера, а не собирается здесь.
         *
         * Здесь она создавалась с каталогом `var/log`, а `MailerFactory::create()`
         * дописывает к нему `log` сам. Журнал писем уезжал в `var/log/log/`,
         * тогда как всё остальное писалось в `var/log/`: письма о регистрации и
         * сбросе пароля оказывались в непривычном месте, которое никто не
         * открывал, — и на боевом сервере это выглядело бы как «письма не
         * отправляются вовсе».
         */
        $this->health = new HealthController(
            $app, $db, $config, $app->get(MailerFactory::class)
        );

        // Обновление системы: отдельный контроллер, потому что дело это не о
        // настройках сайта, а о файлах, из которых сайт работает.
        //
        // Вид передаётся обязательно: помощники контроллера строят адрес
        // возврата через него, а без него страница после обновления падала бы
        // с «Call to a member function url() on null» — уже после того, как
        // файлы заменены. Человек видел бы ошибку и решал, что обновление не
        // прошло.
        $this->update = new UpdateController(
            $csrf,
            $session,
            $app->get(SystemUpdate::class),
            $app->get(View::class)
        );
    }
}