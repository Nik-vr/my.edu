<?php

declare(strict_types=1);

namespace MyEdu;

use MyEdu\Auth\Auth;
use MyEdu\Auth\AuthMailer;
use MyEdu\Auth\AuthService;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\LoginThrottle;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\SubjectRepository;
use MyEdu\Auth\TokenRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\SystemUpdate;
use MyEdu\Core\View;
use MyEdu\Http\Presenter\PanelNav;
use MyEdu\Plugin\PluginManager;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\AssignmentDraftRepository;
use MyEdu\Repository\RatingRepository;

/**
 * Сборка приложения: кто из кого создаётся.
 *
 * Зачем это отдельный файл. `Core\App` — контейнер, и контейнеру не полагается
 * знать про всё, что в контейнер кладут. Он знал: двадцать одна запись
 * `if ($id === ...)` с классами `Auth`, `PluginManager`, `PanelNav` и
 * репозиториев. Получалось, что ядро — самый нижний слой — depends on самый
 * верхний, и ядро нельзя было ни прочитать отдельно, ни проверить отдельно:
 * стоило убрать плагин, и контейнер перестал собираться.
 *
 * Теперь ядро знает только свои сервисы — подключение к базе, сессию, защитное
 * поле, вид, отправку писем, — а всё остальное приходит отсюда. Зависимость
 * направлена правильно: корень сборки знает о слоях, слои о корне не знают.
 *
 * Файл лежит в корневом пространстве имён рядом с `public/index.php` и
 * `database/migrate.php`, а не в каталоге слоя, потому что он принадлежит не
 * слою, а приложению целиком.
 */
final class Wiring
{
    /**
     * Зарегистрировать сервисы верхних слоёв.
     *
     * Вызывается один раз при подъёме контейнера. Регистрация ленивая: фабрика
     * получает контейнер и создаёт сервис по первому обращению, поэтому
     * неиспользуемый плагин или письмо ничего не стоят.
     */
    public static function into(App $app): void
    {
        $app->define(UserRepository::class, static fn (App $a): UserRepository
            => new UserRepository($a->get(Database::class)));

        $app->define(TokenRepository::class, static fn (App $a): TokenRepository
            => new TokenRepository($a->get(Database::class)));

        $app->define(ClassRepository::class, static fn (App $a): ClassRepository
            => new ClassRepository($a->get(Database::class)));

        $app->define(StudentRepository::class, static fn (App $a): StudentRepository
            => new StudentRepository($a->get(Database::class)));

        $app->define(SubjectRepository::class, static fn (App $a): SubjectRepository
            => new SubjectRepository($a->get(Database::class)));

        $app->define(AssignmentRepository::class, static fn (App $a): AssignmentRepository
            => new AssignmentRepository($a->get(Database::class)));

        $app->define(AssignmentDraftRepository::class, static fn (App $a): AssignmentDraftRepository
            => new AssignmentDraftRepository($a->get(Database::class)));

        $app->define(RatingRepository::class, static fn (App $a): RatingRepository
            => new RatingRepository($a->get(Database::class)));

        /*
         * Сессия проверяет пароль по хешу, и хеш ей передаётся из того же
         * контейнера, что и сама проверка. Раньше это было завязано в ядре
         * двумя строками — здесь, рядом с остальным.
         */
        $app->define(Auth::class, static fn (App $a): Auth => new Auth(
            $a->get(\MyEdu\Core\Session::class),
            $a->get(UserRepository::class),
            $a->config()
        ));

        $app->define(LoginThrottle::class, static fn (App $a): LoginThrottle => new LoginThrottle(
            $a->get(Database::class),
            $a->config()->int('security.login_failures_limit'),
            $a->config()->int('security.login_lockout_seconds')
        ));

        $app->define(AuthMailer::class, static fn (App $a): AuthMailer => new AuthMailer(
            $a->get(\MyEdu\Core\MailerInterface::class),
            $a->config(),
            $a->get(\MyEdu\Core\MailerFactory::class)
        ));

        $app->define(AuthService::class, static fn (App $a): AuthService => new AuthService(
            $a->get(Database::class),
            $a->get(UserRepository::class),
            $a->get(TokenRepository::class),
            $a->get(Auth::class),
            $a->get(AuthMailer::class),
            $a->config(),
            $a->get(LoginThrottle::class)
        ));

        $app->define(PluginManager::class, static fn (App $a): PluginManager => new PluginManager(
            $a,
            $a->get(Database::class),
            $a->rootDir() . '/plugins',
            $a->get(View::class),
            // Набор плагинов личный, поэтому менеджеру нужен тот, кто смотрит
            // на страницу: без него список был бы общим, как и раньше.
            $a->get(Auth::class)
        ));

        // Меню панели: считается один раз на запрос и кладётся в общий
        // контекст, иначе шаблон считал бы счётчики сам и они разъезжались бы
        // между страницами.
        $app->define(PanelNav::class, static fn (App $a): PanelNav => new PanelNav(
            $a->get(Auth::class),
            $a->get(AssignmentRepository::class),
            $a->get(Database::class)
        ));

        /*
         * Обновление системы из релизов GitHub.
         *
         * Копия базы снимается тем же кодом, что и перед миграциями: правило
         * одно, и две разные копии расходились бы при первой же правке.
         */
        $app->define(SystemUpdate::class, static fn (App $a): SystemUpdate => new SystemUpdate(
            $a,
            $a->config(),
            new DatabaseDump(
                $a,
                $a->config(),
                $a->get(Database::class),
                // Обновление без копии базы не продолжается: файлы уже заменены
                // и откатывать их нечем.
                true
            )
        ));
    }
}