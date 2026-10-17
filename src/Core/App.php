<?php

declare(strict_types=1);

namespace MyEdu\Core;

use MyEdu\Auth\Auth;
use MyEdu\Auth\AuthMailer;
use MyEdu\Auth\AuthService;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\LoginThrottle;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\TokenRepository;
use MyEdu\Auth\UserRepository;
use RuntimeException;

/**
 * Небольшой контейнер сервисов и точка входа приложения.
 *
 * Никаких внешних зависимостей: сервисы создаются лениво и хранятся в памяти
 * на время запроса.
 */
final class App
{
    /** Версия ядра, с которой сверяется requires.core в манифестах плагинов. */
    public const VERSION = '1.0.0';

    private static ?App $instance = null;

    private Config $config;

    /** @var array<string,object> */
    private array $services = [];

    private string $basePath = '';

    /** Адрес, по которому сервер отдаёт каталог public/. */
    private string $publicDirUrl = '';

    private ?Autoloader $autoloader = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

public static function boot(string $rootDir): self
    {
        $config = Config::load($rootDir . '/config');

        $app = new self($config);
        $app->publicDirUrl = $app->resolvePublicDirUrl();

        /*
         * Явно заданный адрес публичной части меняет и навигацию: человек
         * сознательно сказал, что все адреса должны идти через public/.
         * Это выход для хостингов без mod_rewrite — там по коротким адресам
         * (/login) сервер всё равно не доходит до приложения, и лучше честно
         * оставить в адресах /public/, чем отдавать ссылки в никуда.
         */
        $app->basePath = $app->publicDirUrl !== '' && $app->publicDirUrlIsForced()
            ? $app->publicDirUrl
            : self::detectBasePath();

        $app->autoloader();

        // Конфигурация — тоже сервис: плагины достают её из контейнера,
        // как и всё остальное, и не знают, где ядро её читает.
        $app->set(Config::class, $config);

        self::$instance = $app;

        return $app;
    }

    /**
     * Автозагрузчик приложения.
     *
     * Отдаётся тот же экземпляр, что создала точка входа: плагины дописывают
     * в него свои пространства имён, и ядро должно видеть тот же загрузчик,
     * а не второй, вслепую.
     */
    public function autoloader(): Autoloader
    {
        return Autoloader::create($this->rootDir());
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Приложение не инициализировано');
        }

        return self::$instance;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
* Публичный маршрут приложения с учётом размещения не в корне домена.
     *
     * Вычисляется по трём признакам, по очереди:
     *
     * 1. Явное значение `app.base_path` в конфигурации. Нужно хостингам,
     *    где ничего не угадывается: PHP-FPM отдаёт DOCUMENT_ROOT, указывающий
     *    выше публичной части, и каталог сайта не совпадает с ней.
     * 2. Разница между DOCUMENT_ROOT и каталогом public/.
     * 3. Сам адрес запроса: если вычисленный префикс в нём не встречается,
     *    значит сервер уже переписал запрос внутрь public/ (Apache через
     *    .htaccess, nginx через try_files) и приложение живёт в корне.
     *
     * Третий пункт — то, что позволяет сайту работать и по адресу `/public/`,
     * и по корневому адресу одними и теми же файлами: адрес, по которому
     * пришёл первый запрос, решает, какой это из двух случаев.
     */
    private static function detectBasePath(): string
    {
        $configured = Config::env('MYEDU_BASE_PATH');

        if ($configured !== '') {
            return self::normalizeBasePath($configured);
        }

        $detected = self::detectBasePathFromServer();

        if ($detected === '' || !self::requestStartsWith($detected)) {
            return '';
        }

        return $detected;
    }

    /** Приводит префикс к виду «/public» без хвостовых слешей. */
    private static function normalizeBasePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '' : '/' . $path;
    }

    /**
     * Где физически лежит каталог public/ относительно корня сайта.
     *
     * Это не то же самое, что basePath(). Адреса навигации отвечают на
     * вопрос «по какому адресу открыт сайт» и потому остаются чистыми. А
     * стили, скрипты и загруженные картинки лежат в каталоге public/, и их
     * надо искать там, где он реально находится.
     *
     * Расхождение видно на шаед-хостинге: сайт открыт по адресу корня, а
     * корень сайта смотрит в каталог проекта. Стили лежат в public/assets/,
     * и без перехода по адресу `/public/` браузер их не найдёт — страница
     * откроется без оформления.
     *
     * Значение можно задать явно: app.public_prefix в конфигурации.
     */
    private function resolvePublicDirUrl(): string
    {
        $configured = trim($this->config->string('app.public_prefix', ''));

        if ($configured !== '') {
            return self::normalizeBasePath($configured);
        }

        return self::detectBasePathFromServer();
    }

    /** Задан ли адрес публичной части человеком, а не угадан сервером. */
    public function publicDirUrlIsForced(): bool
    {
        return trim($this->config->string('app.public_prefix', '')) !== '';
    }

    /**
     * Адрес каталога public/ для файлов: стили, скрипты, загрузки.
     */
    public function publicDirUrl(): string
    {
        return $this->publicDirUrl;
    }

    /**
     * Адрес файла, лежащего в публичной части.
     */
    public function publicUrl(string $path): string
    {
        return $this->publicDirUrl . '/' . ltrim($path, '/');
    }

    /** Префикс по DOCUMENT_ROOT: работает, когда корень сайта — public/. */
    private static function detectBasePathFromServer(): string
    {
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

        if ($docRoot === false) {
            return '';
        }

        $publicDir = realpath(dirname(__DIR__, 2) . '/public');

        if ($publicDir === false || !str_starts_with($publicDir, $docRoot)) {
            return '';
        }

        return self::normalizeBasePath(trim(str_replace($docRoot, '', $publicDir), '/\\'));
    }

    /**
     * Начинается ли адрес запроса с указанного префикса.
     *
     * Сравнение идёт по целым сегментам, а не по подстроке: префикс `/public`
     * не должен считаться совпадением для адреса `/publications`.
     */
    private static function requestStartsWith(string $basePath): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim(is_string($path) ? $path : '/', '/');

        return $path === $basePath || str_starts_with($path, $basePath . '/');
    }

    public function get(string $id): object
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        $service = $this->make($id);
        $this->services[$id] = $service;

        return $service;
    }

    /**
     * Заранее положенный сервис.
     *
     * Нужен для вещей, которые нельзя собрать автоматически, — прежде всего
     * для конфигурации: она уже прочитана при старте.
     */
    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    private function make(string $id): object
    {
        if ($id === Csrf::class) {
            return new Csrf($this->get(Session::class));
        }

if ($id === MailerFactory::class) {
            return new MailerFactory($this->config, $this->rootDir() . '/var');
        }

        if ($id === MailerInterface::class) {
            return $this->get(MailerFactory::class)->create();
        }

        if ($id === ImageUpload::class) {
            return new ImageUpload($this->rootDir());
        }

        if ($id === UserRepository::class) {
            return new UserRepository($this->get(Database::class));
        }

        if ($id === TokenRepository::class) {
            return new TokenRepository($this->get(Database::class));
        }

        if ($id === Auth::class) {
            return new Auth($this->get(Session::class), $this->get(UserRepository::class), $this->config);
        }

        if ($id === AuthMailer::class) {
            return new AuthMailer($this->get(MailerInterface::class), $this->config, $this->get(MailerFactory::class));
        }

if ($id === LoginThrottle::class) {
            return new LoginThrottle(
                $this->get(Database::class),
                $this->config->int('security.login_failures_limit'),
                $this->config->int('security.login_lockout_seconds')
            );
        }

        if ($id === RateLimiter::class) {
            return new RateLimiter(
                $this->get(Database::class),
                max(10, $this->config->int('security.rate_window_seconds', 60))
            );
        }

        if ($id === AuthService::class) {
            return new AuthService(
                $this->get(Database::class),
                $this->get(UserRepository::class),
                $this->get(TokenRepository::class),
                $this->get(Auth::class),
                $this->get(AuthMailer::class),
                $this->config,
                $this->get(LoginThrottle::class)
            );
        }

if ($id === SiteUrl::class) {
            return new SiteUrl($this->config);
        }

        /*
         * Резервная копия базы перед миграциями.
         *
         * В контейнере она нужна миграторам и плагинам, а решить, блокировать ли
         * миграцию при отказе, должен вызывающий: команда и сайт ведут себя по
         * разному, и это различие живёт не в конфиге по умолчанию.
         */
        if ($id === DatabaseDump::class) {
            return new DatabaseDump($this, $this->config, $this->get(Database::class));
        }

        if ($id === ClassRepository::class) {
            return new ClassRepository($this->get(Database::class));
        }

        if ($id === StudentRepository::class) {
            return new StudentRepository($this->get(Database::class));
        }

        if ($id === \MyEdu\Auth\SubjectRepository::class) {
            return new \MyEdu\Auth\SubjectRepository($this->get(Database::class));
        }

        if ($id === Settings::class) {
            return new Settings($this->get(Database::class));
        }

if ($id === \MyEdu\Repository\AssignmentRepository::class) {
            return new \MyEdu\Repository\AssignmentRepository($this->get(Database::class));
        }

        if ($id === \MyEdu\Repository\RatingRepository::class) {
            return new \MyEdu\Repository\RatingRepository($this->get(Database::class));
        }

if ($id === \MyEdu\Plugin\PluginManager::class) {
            return new \MyEdu\Plugin\PluginManager(
                $this,
                $this->get(Database::class),
                $this->rootDir() . '/plugins',
                $this->get(View::class),
                // Набор плагинов личный, поэтому менеджеру нужен тот, кто смотрит
                // на страницу: без него список был бы общим, как и раньше.
                $this->get(Auth::class)
            );
        }

        // Меню панели: считается один раз на запрос и кладётся в общий
        // контекст, иначе шаблон считал бы счётчики сам и они разъезжались бы
        // между страницами.
        if ($id === \MyEdu\Http\Presenter\PanelNav::class) {
            return new \MyEdu\Http\Presenter\PanelNav(
                $this->get(Auth::class),
                $this->get(\MyEdu\Repository\AssignmentRepository::class),
                $this->get(Database::class)
            );
        }

        return match ($id) {
            Database::class => new Database($this->config->array('database')),
            Session::class  => new Session(
                $this->config->bool('session.secure_cookies'),
                $this->config->string('session.save_path'),
                $this->config->string('session.name')
            ),
            View::class     => new View($this->rootDir() . '/templates'),
            Router::class   => new Router(),
            default         => throw new RuntimeException('Сервис не зарегистрирован: ' . $id),
        };
    }
}
