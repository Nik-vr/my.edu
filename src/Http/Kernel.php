<?php

declare(strict_types=1);

namespace MyEdu\Http;

use MyEdu\Auth\Auth;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\Student;
use MyEdu\Auth\User;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\RateLimiter;
use MyEdu\Core\RedirectSignal;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Router;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Routes\AuthRoutes;
use MyEdu\Http\Routes\Controllers;
use MyEdu\Http\Routes\PanelRoutes;
use MyEdu\Http\Routes\PublicRoutes;
use MyEdu\Http\Routes\SchoolRoutes;
use MyEdu\Http\Routes\WorkRoutes;
use MyEdu\Http\Presenter\PanelNav;
use MyEdu\Plugin\PluginManager;
use Throwable;

/**
 * Ядро HTTP: маршруты, проверка прав и единая обработка ошибок.
 *
 * Право доступа задаётся прямо при описании маршрута. Проверка живёт здесь,
 * а не в контроллерах: забытый контроллер защищает страницу, забытый
 * контроллер в контроллере — нет.
 */
final class Kernel
{
    /**
     * Имя cookie со состоянием свёрнутого бокового меню.
     *
     * Решение владельца от 24.10.2026 (волна E): состояние сворачивания
     * живёт в cookie, а не в localStorage. Cookie читает сервер и сразу
     * отрисовывает меню в нужном виде — страница не мигает развёрнутым
     * меню, а состояние видно в отладчике браузера. Имя дублируется в
     * `public/assets/js/panel-nav.js` (то же место, где разделяется и
     * CSRF-токен в meta), и менять его нужно в обоих.
     */
    private const SIDEBAR_RAIL_COOKIE = 'myedu-sidebar-rail';

    /**
     * Префикс cookie со состоянием разделов меню: `myedu-nav-works` и так далее.
     *
     * Имя состоит из префикса и ключа группы (`PanelNav`: `works`, `school`,
     * `system`), и так же оно собирается в `public/assets/js/panel-nav.js` —
     * менять префикс нужно в обоих местах, как и у cookie свёрнутого меню.
     */
    private const NAV_COOKIE_PREFIX = 'myedu-nav-';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Запрос, который сейчас обрабатывается.
     *
     * Нужен ответам: ошибка рисуется вне dispatch, и без него страница ошибки не
     * знала бы, чего ждёт человек — страницу или данные.
     */
    private ?Request $currentRequest = null;

    public function handle(Request $request): Response
    {
        $this->currentRequest = $request;

        try {
            return $this->answer($request);
        } finally {
            /*
             * Блокировка сессии отпускается здесь, а не в конце отрисовки
             * шаблона: к этой строке тело ответа уже собрано, а отправлять
             * байты ещё предстоит. Пока сессия активна, файл на диске закрыт на
             * этого человека, и любой другой его запрос — иконка, картинка,
             * вторая вкладка — ждёт. Под HTTP/2 ожидание обрывало поток, и
             * браузер показывал ERR_HTTP2_PROTOCOL_ERROR, хотя страница была
             * в порядке.
             *
             * finally, а не обычным return: на всех путях, включая отказы и
             * ошибки, блокировка должна сниматься одинаково. Забытый на одном
             * пути возврат означал бы, что при первом же отказе страница
             * повисла бы на блокировке до конца времени ответа.
             */
            $this->session()->close();
        }
    }

    private function answer(Request $request): Response
    {
        try {
            $this->session()->start();

            date_default_timezone_set($this->config()->string('app.timezone', 'UTC'));

            if ($this->config()->bool('app.debug')) {
                error_reporting(E_ALL);
                ini_set('display_errors', '1');
            } else {
                error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
                ini_set('display_errors', '0');
            }

            /*
             * Общий контекст кладётся здесь, а не в dispatch: страница ошибки
             * рисуется мимо dispatch, и макет там не нашёл бы адреса. Из-за
             * этого страница 404 падала в 500 — строка в шаблоне получала
             * null вместо строки.
             */
            /*
             * В шаблонах `$currentUser` — вошедший человек, сотрудник или
             * ученик: оба объекта дают `fullName` и `shortName()`, поэтому
             * шаблоны обходятся без различий. Гостю кладётся null — шапка
             * рисует для него вход вместо имени.
             *
             * Разделять переменные на две нельзя было бы без нужды: почти
             * каждый шаблон обращается к ней, и придётся проверять обе.
             */
            $currentPerson = $this->auth()->user() ?? $this->auth()->student();

            $this->view()->share('currentUser', $currentPerson);
            $this->view()->share('appName', $this->config()->string('app.title'));

            /*
             * Личный размер шрифта — для атрибута `data-font-size` на <html>.
             *
             * Значение кладётся отдельно от `$currentUser`, а не берётся из
             * него в шаблоне: оболочка страницы должна читать одну переменную,
             * иначе гость (у него объекта нет) заставит шаблон проверять тип
             * на каждой странице. Гостю кладётся нормальный размер: выбирать
             * человеку не из чего, и он видит систему как задумано.
             *
             * Приведение идёт здесь, а не в базе или шаблоне: мусорное значение
             * становится нормальным раньше, чем доедет до CSS, и одна проверка
             * обслуживает и оболочку панели, и публичную.
             */
            $this->view()->share('fontSize', $currentPerson?->fontSize ?? User::FONT_NORMAL);

            /*
             * Имя класса ученика — для шапки прохождения работы.
             *
             * Решение владельца от 24.10.2026: на странице задания вместо
             * крошек показываются «имя+класс | таймер | кнопки». У самого
             * ученика в объекте лежит только номер класса, а имя класса
             * оболочке нужно текстом: шаблон не ходит в базу. Поэтому
             * запрос делается здесь, один раз на запрос страницы и только
             * для вошедшего ученика с классом — сотруднику и гостю он не
             * нужен и не выполняется.
             *
             * Пустая строка вместо «Без класса»: подпись «, » с пустым
             * именем выглядела бы поломкой, а отсутствие класса — норма
             * для ученика, которого ещё не распределили.
             */
            $studentClassName = '';

            if ($currentPerson instanceof Student && $currentPerson->classId !== null) {
                $class = $this->app->get(ClassRepository::class)->find($currentPerson->classId);
                $studentClassName = $class === null ? '' : (string) ($class['name'] ?? '');
            }

            $this->view()->share('studentClassName', $studentClassName);

            /*
             * Тема оформления — из профиля человека (решение владельца от
             * 24.10.2026, волна E). Сервер ставит её в разметку до первой
             * отрисовки, поэтому страница рисуется сразу в нужном виде и не
             * мигает; прежний выбор в localStorage браузера больше не читается
             * — тема перестала зависеть от состояния конкретного браузера.
             *
             * Гостю (вход, регистрация) кладётся тёмная: свой выбор у него
             * нет, а в разметке оболочки стоит именно она.
             */
            $this->view()->share('theme', $currentPerson?->theme ?? User::THEME_DARK);

            /*
             * Свёрнутое меню — тоже сервером: cookie пишет скрипт при
             * нажатии, а читает здесь, при отрисовке. Без этого cookie
             * хранила бы состояние, которым никто не пользуется, а меню на
             * каждом переходе открывалось бы заново.
             *
             * Значение проверяется на точное «1»: подделать cookie может
             * кто угодно, но вреда от ложного «свёрнуто» нет — это состояние
             * вида, а не право.
             */
            $this->view()->share('sidebarRail', $request->cookie(self::SIDEBAR_RAIL_COOKIE) === '1');

            /*
             * Состояние свёрнутости разделов меню (решение владельца от
             * 24.10.2026, задача 1).
             *
             * Дефолт — состояние при ПЕРВОМ входе: пока человек ничего не
             * нажимал, cookie нет, и разделы показываются так, как задумано —
             * «Работы» открыты, «Школа» и «Система» свёрнуты. Как только
             * разделом нажали, его cookie записывает скрипт, и дальше читается
             * уже личный выбор. У каждой группы своя cookie: свёрнутая
             * «Школа» не должна разворачивать «Систему».
             *
             * Значение «1» — свёрнута, «0» — открыта; всё прочее, включая
             * подделку, считается дефолтом.
             */
            $navDefaults = ['works' => false, 'school' => true, 'system' => true];
            $navClosed = [];

            foreach ($navDefaults as $navKey => $navDefault) {
                $navCookie = $request->cookie(self::NAV_COOKIE_PREFIX . $navKey);

                $navClosed[$navKey] = $navCookie === null
                    ? $navDefault
                    : ($navCookie === '1' ? true : ($navCookie === '0' ? false : $navDefault));
            }

            $this->view()->share('navClosed', $navClosed);

            /*
             * Версия в подвале берётся из настроек, а не из константы в коде:
             * единственное место, где версия написана, — иначе подвал и
             * документация разошлись бы, а заметить это можно было бы только
             * при разборе. Правила её изменения описаны рядом с настройкой.
             */
            $this->view()->share('appVersion', $this->config()->string('app.version'));
            $this->view()->share('currentPath', $this->currentPath());

            /*
             * Тестовое прохождение в интерфейсе ученика — решение владельца
             * от 24.10.2026 (задача 6).
             *
             * Меню не отдаётся на путях /work, когда идёт проба: либо уже
             * начата (метка trial_attempt в сессии), либо это сам показ по
             * ссылке «Пройти работу» (?trial=1) — на нём метки ещё нет,
             * но сотрудник должен увидеть ту же картинку, что увидит дальше.
             *
             * На обычных страницах панели меню остаётся: флаг действует
             * только на пути прохождения и снимается уборкой при закрытии
             * вкладки. Ученика это не задевает — у него прав нет, и групп
             * в меню и так нет.
             *
             * trialAttempt отдаётся оболочке для beacon-скрипта уборки: скрипт
             * должен знать, какую попытку предложить серверу удалить.
             */
            $trialAttempt = (int) ($this->session()->get('trial_attempt') ?? 0);
            $trialOpening = $request->queryParam('trial') !== null;
            $trialRunning = ($trialAttempt > 0 || $trialOpening)
                && str_starts_with($request->path(), '/work');

            $this->view()->share('trialAttempt', $trialRunning ? $trialAttempt : 0);
            $this->view()->share(
                'panelNav',
                $trialRunning ? [] : $this->panelNav()->items($this->currentPath())
            );

            return $this->dispatch($request);
        } catch (RedirectSignal $redirect) {
            // Редирект, заданный проверкой доступа, а не контроллером.
            return $redirect->toResponse();
        } catch (HttpException $e) {
            return $this->errorPage($e->status(), $e->getMessage());
        } catch (Throwable $e) {
            $this->reportError($e);

            return $this->errorPage(
                500,
                $this->config()->bool('app.debug')
                    ? $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine()
                    : 'Внутренняя ошибка сервера. Попробуйте повторить действие.'
            );
        }
    }

    private function dispatch(Request $request): Response
    {
        $router = $this->routes();

        $route = $router->match($request->method(), $request->path());

        if ($route === null) {
            throw new HttpException(404);
        }

        $this->guard($request, $route['capability']);

        /** @var callable $handler */
        $handler = $route['handler'];

        return $handler($request, $route['params']);
    }

/**
     * Карта маршрутов целиком.
     *
     * Сами адреса разнесены по файлам в `src/Http/Routes/` — по частям сайта,
     * а не по контроллерам: в файле видно, что адрес открывает и кому он
     * доступен. Здесь они собираются в один роутер, и добавляется последний
     * кусок, которого в файлах быть не может, — адреса подключённых плагинов:
     * их набор известен только во время работы.
     *
     * Порядок подключения файлов в списке ни на что не влияет: у каждого своя
     * начальная часть адреса, и совпадений между файлами нет. Значим порядок
     * *внутри* файла, и он задан там же.
     */
    private function routes(): Router
    {
        $router = new Router();

        $controllers = new Controllers($this->app);

        PublicRoutes::register($router, $controllers);
        AuthRoutes::register($router, $controllers);
        WorkRoutes::register($router, $controllers);
        PanelRoutes::register($router, $controllers);
        SchoolRoutes::register($router, $controllers);

        $this->registerPluginRoutes($router);

        return $router;
    }

/**
     * Маршруты подключённых плагинов.
     *
     * Плагин объявляет пути относительно своего префикса и ничего не знает
     * о том, где установлен: /tests/{id} получается из префикса tests и
     * маршрута /{id}. Поэтому добавление плагина не требует правок здесь.
     *
     * Два правила добавляет ядро, а не плагин.
     *
     * Первое: маршрут без объявленного права требует входа. Раньше отсутствие
     * права означало «открыто всем», и адрес плагина, о котором автор забыл
     * дописать право, доставался гостю — то есть опечатка в манифесте или
     * недосмотр тихо открывали наружу то, что плагин явно не собирался отдавать.
     * Открытый адрес теперь надо назвать: `Router::PUBLIC_ACCESS`.
     *
     * Второе: изменяющий запрос проверяется на защитном поле здесь, до вызова
     * плагина. Ядро проверяет его само в каждом своём контроллере, но полагаться
     * на то, что каждый написанный кем-то плагин не забудет это сделать, —
     * значит оставлять защиту по договорённости: один забытый вызов `validate()`
     * превращался в изменение данных с чужого сайта.
     */
    private function registerPluginRoutes(Router $router): void
    {
        foreach ($this->pluginManager()->all() as $id => $plugin) {
            $descriptor = $this->pluginManager()->discover()[$id] ?? null;

            if ($descriptor === null) {
                continue;
            }

            $prefix = $descriptor->urlPrefix();

            foreach ($this->pluginManager()->routesFor((string) $id) as $route) {
                [$method, $path, $action] = $route;
                $capability = $route[3] ?? null;

                $full = '/' . trim($prefix, '/') . '/' . ltrim($path, '/');

                /*
                 * Адрес, занятый ядром или другим плагином, плагину не
                 * отдаётся молча: иначе отвечал бы тот, кто попал в разбор
                 * первым, а проигравший об этом не узнал бы.
                 */
                if ($router->isTaken(strtoupper($method), $full)) {
                    $this->pluginManager()->recordRuntimeFailure(
                        (string) $id,
                        new \RuntimeException('Адрес занят: ' . strtoupper($method) . ' ' . $full)
                    );

                    continue;
                }

                $router->addRoute(
                    strtoupper($method),
                    $full,
                    $this->pluginRouteHandler($plugin, $action, strtoupper($method)),
                    $capability === null ? Router::AUTH_REQUIRED : (string) $capability
                );
            }
        }
    }

    /**
     * Обработчик маршрута плагина с проверкой защитного поля.
     *
     * `$action` — имя метода плагина, а не сам метод: массив «объект, имя»
     * собирается здесь, где уже известен объект.
     */
    private function pluginRouteHandler(object $plugin, string $action, string $method): callable
    {
        $callable = [$plugin, $action];

        /*
         * Только методы, которые меняют данные. Проверка поля на чтении не
         * имеет смысла: токен всё равно берётся из формы, а вот его отсутствие
         * при чтении — обычное дело для ссылки.
         */
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return static fn (Request $request, array $params): Response => $callable($request, $params);
        }

        return function (Request $request, array $params) use ($callable): Response {
            $this->app->get(Csrf::class)->validate($request->postParam('_token'));

            return $callable($request, $params);
        };
    }

    /**
     * Проверка доступа к маршруту.
     */
    private function guard(Request $request, ?string $capability): void
    {
        /*
         * Ограничение частоты проверяется до всего остального, в том числе до
         * проверки прав.
         *
         * Иначе ограничение молча не работало бы ровно там, где оно нужнее
         * всего: у адресов без права (`/auth/forgot`, `/confirm/resend`) проверка
         * прав не выполняется вовсе, и выход по её отсутствию пропускал бы мимо
         * счётчика самые привлекательные для спама адреса.
         */
        $this->limitRate($request);

        if ($capability === null || $capability === Router::PUBLIC_ACCESS) {
            return;
        }

        $auth = $this->auth();

        // Гостевая страница: вошедшему сразу показываем его профиль.
        if ($capability === Router::GUEST_ONLY) {
            if ($auth->check()) {
                throw new RedirectSignal($this->view()->url('portal'));
            }

            return;
        }

        if (!$auth->check()) {
            /*
             * Запрос, ждущий данных, получает отказ, а не страницу входа.
             *
             * Иначе скрипт получает HTML там, где ждал JSON, и не может сказать
             * ничего внятного: правка выглядит несохранившейся без причины, а
             * повторная отправка уходит туда же и снова не проходит. Код 401
             * называет причину прямо, и человек знает, что надо войти заново.
             */
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Нужно войти в систему');
            }

            // Сохраняем, куда человек шёл: после входа вернём его обратно.
            $this->session()->set('_intended', $this->currentPath());

            throw new RedirectSignal($this->view()->url('login'));
        }

        if ($capability === Router::AUTH_REQUIRED) {
            return;
        }

        if (!$auth->can($capability)) {
            throw new HttpException(403, 'Недостаточно прав для просмотра этой страницы');
        }
    }

    /**
     * Адреса, у которых ограничена частота, и группа каждого.
     *
     * Список короткий и только там, где повтор одного и того же запроса ничего
     * не даёт: письма, регистрация, загрузка файла. Остальные адреса сюда не
     * попадают намеренно — иначе предел нашёлся бы на обычной работе учителя.
     *
     * @var array<string,string> адрес внутри приложения → группа
     */
    private const RATE_LIMITED = [
        '/register'        => 'auth',
        '/confirm/resend'  => 'auth',
        '/auth/forgot'     => 'password_reset',
        '/panel/editor/image' => 'image_upload',
    ];

    /**
     * Ограничить частоту, если адрес в списке.
     *
     * Кто считается «тем же самым»: вошедший человек — по номеру, гость — по
     * адресу источника. Считать гостя по сессии бессмысленно: сессию можно
     * завести новую и обойти предел, а адрес у злоумышленника один.
     */
    private function limitRate(Request $request): void
    {
        if ($request->method() !== 'POST') {
            return;
        }

        $bucket = self::RATE_LIMITED[$request->path()] ?? null;

        if ($bucket === null) {
            return;
        }

        $limits = $this->config()->array('security.rate_limits');
        $limit = (int) ($limits[$bucket] ?? 0);

        if ($limit <= 0) {
            return;
        }

        $user = $this->auth()->user();
        $who = $user === null ? 'ip:' . $request->ip() : 'user:' . $user->id;

        $wait = $this->app->get(RateLimiter::class)->hit($bucket, $limit, $who);

        if ($wait <= 0) {
            return;
        }

        /*
         * Отказ объясняет и сколько ждать: иначе человек решил бы, что его
         * отключили, и начал бы перезагружать страницу — то есть только
         * добился бы новых отказов.
         */
        throw new HttpException(429, 'Слишком много запросов. Подождите ' . $wait . ' с и попробуйте снова.');
    }

    /**
     * Путь внутри приложения, без базового.
     *
     * При размещении не в корне домена (http://host/my-edu/panel/users)
     * в REQUEST_URI лежит именно такой путь, и сравнивать его с адресами
     * вида '/panel' бесполезно: ни одна проверка не совпадёт. Приложение
     * добавляет базу само, через View::url(), — поэтому и отдавать дальше
     * нужно путь без неё.
     *
     * На это опираются три места: подсветка текущего пункта меню, само меню
     * и возврат по адресу, откуда человек пришёл на страницу входа.
     */
    private function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        $base = $this->app->basePath();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

private function errorPage(int $status, string $message): Response
    {
        /*
         * Запросу, ждущему данные, отдаются данные.
         *
         * Иначе отказ приходит страницей: разобрать её как JSON нельзя, скрипт
         * падает на разборе и показывает общую ошибку. Здесь у отказа есть код и
         * текст, а скрипт может отличить «вышел срок сессии» от «нет прав».
         *
         * Причины в ответе нет: в сообщении нет ни адресов, ни имён полей, и
         * при отладке она всё равно видна по телу страницы, которая приходит
         * вместо ответа.
         */
        if ($this->currentRequest !== null && $this->currentRequest->wantsJson()) {
            return Response::json([
                'ok' => false,
                /*
                 * Текст отдаётся под обоими именами: правка задания читает
                 * `message`, правка в строке — `error`. Одно имя означало бы, что
                 * в чужом сценарии сообщение пропало бы молча и человек увидел бы
                 * «не сохранено» вместо причины.
                 */
                'error' => $message,
                'message' => $message,
                'status' => $status,
            ], $status);
        }

        $body = $this->view()->render('error', [
            'status'   => $status,
            'message'  => $message,
            'appName'  => $this->config()->string('app.title', 'Панель управления'),
            'auth'     => $this->auth(),
        ]);

        return Response::html($body, $status);
    }

    /**
     * Журнал ошибок без аргументов вызовов.
     *
     * Throwable::getTraceAsString() печатает значения аргументов каждого
     * вызова, и в журнал попадали пароли: строка стектрейса вида
     * «AuthService->login('адрес', 'пароль')» целиком уезжала в файл журнала,
     * а журнал копируется вместе с проектом.
     *
     * Поэтому стек собирается вручную, без args. Потеря данных касается
     * только отладки значениями переменных; где, когда и в каком методе
     * ошибка возникло — остаётся целиком.
     *
     * Настройка zend.exception_ignore_args не используется сознательно: её
     * значение зависит от php.ini на конкретной машине, и при ON-параметрах
     * утечка вернулась бы вместе с другой настройкой.
     */
    private function safeTrace(Throwable $e): string
    {
        $frames = [];

        foreach (array_values($e->getTrace()) as $index => $frame) {
            $where = (string) ($frame['file'] ?? '[внутренний вызов]');

            if (isset($frame['line'])) {
                $where .= ':' . $frame['line'];
            }

            $call = '';

            if (isset($frame['class'])) {
                $call .= $frame['class'] . ($frame['type'] ?? '::');
            }

            $call .= ($frame['function'] ?? '?') . '()';

            // Нумерация с единицы, как в привычном стектрейсе: кадр #0 —
            // это сама точка исключения, и она печатается отдельно.
            $frames[] = '#' . ($index + 1) . ' ' . $where . '  ' . $call;
        }

        if ($frames === []) {
            return '';
        }

        return '#0 ' . $e->getFile() . ':' . $e->getLine() . "\n" . implode("\n", $frames);
    }

    private function reportError(Throwable $e): void
    {
        $line = sprintf(
            "[%s] %s: %s в %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $this->safeTrace($e)
        );

        $dir = $this->app->rootDir() . '/var/log';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND);
        }

        if (!error_log(trim($line))) {
            @file_put_contents('php://stderr', $line);
        }
    }

    // ---------------------------------------------------------------- сервисы

    /*
     * Геттеры ниже — только те, что нужны самому ядру HTTP: разбор запроса,
     * проверка прав, страница ошибки и меню панели. Сервисы для контроллеров
     * собирает `Routes\Controllers`: держать в ядре список того, что кому
     * нужно, значило бы держать в нём и сам список контроллеров.
     */
    private function config(): Config
    {
        return $this->app->config();
    }

    private function view(): View
    {
        return $this->app->get(View::class);
    }

    private function session(): Session
    {
        return $this->app->get(Session::class);
    }

    private function auth(): Auth
    {
        return $this->app->get(Auth::class);
    }

    private function pluginManager(): PluginManager
    {
        return $this->app->get(PluginManager::class);
    }

    private function panelNav(): PanelNav
    {
        return $this->app->get(PanelNav::class);
    }
}
