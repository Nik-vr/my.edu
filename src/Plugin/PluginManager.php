<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

use MyEdu\Auth\Auth;
use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\View;
use RuntimeException;
use Throwable;

/**
 * Реестр плагинов: находит папки, подключает включённые, раздаёт остальному
 * коду готовые плагины.
 *
 * Ключевое требование — сайт не должен падать из-за одного плохого плагина.
 * Ошибка загрузки ловится и показывается в панели как «не загрузился», а сам
 * плагин исключается из списков заданий: учитель не должен выбрать то, что
 * не работает.
 */
final class PluginManager
    {
    /**
     * Префиксы, которые плагину нельзя занимать.
     *
     * Список вынесен наружу, потому что на него ссылается `plugins/README.md`:
     * пока он жил внутри метода, документация описывала префиксы по памяти и
     * разошлась с кодом — в README не было `uploads`, а объяснение про `tests`
     * ссылалось на правила `.htaccess`, которых после волны 4 уже нет.
     *
     * Открыт наружу, а не просто аккуратно отформатирован, ещё и потому, что
     * набор проверяет совпадение списков: код и документация обязаны называть
     * один и тот же набор, иначе плагин занимает адрес, который README
     * объявил свободным.
     *
     * @var list<string>
     */
    public const RESERVED_PREFIXES = [
        'src', 'config', 'database', 'plugins', 'tools', 'vendor', 'var', 'resources', 'public',
        'assets', 'uploads', 'auth', 'classes', 'confirm', 'health', 'login', 'logout',
        'panel', 'portal', 'profile', 'register', 'student', 'work', 'works',
    ];
    /**
     * Основной плагин: без него работа не собирается.
     *
     * Его переключатель общий и двигает только администратор; остальные
     * учителя видят тот же переключатель, но неактивный. Остальные плагины
     * настраивает каждый сам, иначе отключить лишнее у себя не мог бы никто.
     */
    public const BASE_PLUGIN = 'test';

    private const MANIFEST = 'plugin.json';

    /** @var array<string,PluginDescriptor> найденные на диске */
    private array $discovered = [];

    /** @var array<string,AssessmentPlugin> загруженные и доступные */
    private array $loaded = [];

    /** @var array<string,string> plugin_id => причина, по которой не загрузился */
    private array $failures = [];

    private bool $scanned = false;

    private bool $booted = false;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly string $pluginsDir,
        private readonly View $view,
        private readonly Auth $auth
    ) {
    }

    /** Каталог с плагинами; создаётся при первом обращении. */
    public function directory(): string
    {
        if (!is_dir($this->pluginsDir)) {
            @mkdir($this->pluginsDir, 0o775, true);
        }

        return $this->pluginsDir;
    }

    /**
     * Каталог для показа в интерфейсе.
     *
     * Абсолютный путь бесполезен учителю: на сервере он ещё и врёт, потому
     * что зависит от того, где лежит проект. Поэтому показываем plugins/.
     */
    public function directoryLabel(): string
    {
        return 'plugins/';
    }

    /**
     * Найти все папки с манифестом.
     *
     * @return array<string,PluginDescriptor>
     */
    public function discover(): array
    {
        if ($this->scanned) {
            return $this->discovered;
        }

        $this->scanned = true;
        $dir = $this->directory();

        $entries = is_dir($dir) ? scandir($dir) : false;

        if ($entries === false) {
            return $this->discovered;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '_')) {
                continue;
            }

            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($path)) {
                continue;
            }

            $manifestFile = $path . DIRECTORY_SEPARATOR . self::MANIFEST;

            if (!is_file($manifestFile)) {
                continue;
            }

            $descriptor = $this->readManifest($manifestFile, $path);

            if ($descriptor->id === '') {
                // Без id плагин не опознать в работах: молча пропускаем,
                // а не падаем на чужой папке без манифеста.
                continue;
            }

            /*
             * Два плагина с одним `id` — это не «один победил», а поломанная
             * установка: ключ в списке один, и вторая папка затирает первую
             * молча. Рабочие позиции указывают на `id`, а не на папку, поэтому
             * половина работ перестала бы открываться — и не сказала бы почему.
             *
             * Оба объявляются сломанными: выбрать, кому из них верить, по
             * порядку обхода каталога нельзя, порядок тут произволен.
             */
            if (isset($this->discovered[$descriptor->id])) {
                $existing = $this->discovered[$descriptor->id];
                $clash = 'id «' . $descriptor->id . '» занят папкой «'
                    . basename($existing->dir) . '»; переименуйте одну из папок или смените id';

                $this->discovered[$descriptor->id] = $existing->withError($clash);
                $this->failures[$descriptor->id] = $clash;

                continue;
            }

            $this->discovered[$descriptor->id] = $descriptor;
        }

        return $this->discovered;
    }

    /**
     * Плагины, доступные текущему пользователю: главный — по общему
     * переключателю, остальные — по его личному набору.
     *
     * @return array<string,AssessmentPlugin>
     */
    public function all(): array
    {
        if ($this->booted) {
            return array_diff_key($this->loaded, $this->failures);
        }

        $this->booted = true;

        /*
         * Подключаются все плагины, включённые общим переключателем, — а не
         * только те, что оставлены в личном наборе учителя.
         *
         * Личный переключатель убирает вид задания из палитры, и это всё, что
         * он должен делать. Раньше он же и подключение запрещал: работа, в
         * которой это задание уже лежит, переставала публиковаться с объяснением
         * «плагин выключен или не загрузился» — то есть виноватым выглядел
         * плагин, а человек как раз этим переключателем и воспользовался.
         * Выключенный учителем вид пропадал из палитры, а из готовой работы —
         * вместе с возможностью её отдать ученикам.
         *
         * Личный набор остаётся там, где он и нужен, — в `availableIds()`, из
         * которого берётся палитра и подсказка о недоступных видах.
         */
        $enabled = $this->enabledIds();

        foreach ($this->discover() as $id => $descriptor) {
            if (!in_array($id, $enabled, true)) {
                continue;
            }

            $plugin = $this->boot($descriptor);

            if ($plugin !== null) {
                try {
                    $this->installIfRequired($descriptor, $plugin);
                } catch (Throwable $e) {
                    $this->recordRuntimeFailure((string) $id, $e);
                    continue;
                }

                $this->loaded[$id] = $plugin;
            }
        }

        return array_diff_key($this->loaded, $this->failures);
    }

    public function get(string $id): ?AssessmentPlugin
    {
        if (isset($this->failures[$id])) {
            return null;
        }

        return $this->all()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * Манифест плагина.
     *
     * Нужен самому плагину, чтобы строить свои адреса по тому же префиксу,
     * что и ядро: иначе плагин разошёлся бы с ядром после смены url_prefix.
     */
    public function descriptor(string $id): ?PluginDescriptor
    {
        return $this->discover()[$id] ?? null;
    }

    /** Публичный префикс адресов плагина; без манифеста — его id. */
    public function urlPrefix(string $id): string
    {
        return $this->descriptor($id)?->urlPrefix() ?? $id;
    }

    /**
     * Причины, по которым плагин не загрузился.
     *
     * @return array<string,string>
     */
    public function failures(): array
    {
        $this->all();

        try {
            foreach ($this->db->all('SELECT id, error FROM plugins WHERE error IS NOT NULL') as $row) {
                $id = (string) $row['id'];

                if (!isset($this->failures[$id]) && isset($this->discover()[$id])) {
                    $this->failures[$id] = (string) $row['error'];
                }
            }
        } catch (Throwable) {
            // Panel diagnostics should remain available even before the plugin table exists.
        }

        return $this->failures;
    }

    /**
     * Все виды заданий от включённых плагинов — то, что видит учитель.
     *
     * Именно палитра, поэтому здесь личный набор и применяется: выключенный
     * учителем вид из неё пропадает. Подключение плагина при этом осталось
     * прежним — см. `all()`: работа, где такое задание уже лежит, должна
     * публиковаться.
     *
     * @return array<string,array<string,TaskKind>> plugin_id => список видов
     */
    public function taskKindsByPlugin(): array
    {
        $result = [];
        $available = $this->availableIds();

        foreach (array_keys($this->all()) as $pluginId) {
            if (!in_array((string) $pluginId, $available, true)) {
                continue;
            }

            $kinds = [];

            foreach ($this->taskKinds((string) $pluginId) as $kind) {
                $kinds[$kind->slug] = $kind;
            }

            if ($kinds !== []) {
                $result[$pluginId] = $kinds;
            }
        }

        return $result;
    }

    /**
     * Виды заданий одного плагина с изоляцией его runtime-ошибок.
     *
     * @return list<TaskKind>
     */
    public function taskKinds(string $id): array
    {
        $plugin = $this->get($id);

        if ($plugin === null) {
            return [];
        }

        try {
            $kinds = $plugin->taskKinds();

            if (!is_array($kinds)) {
                throw new \UnexpectedValueException('taskKinds() должен вернуть список TaskKind');
            }

            $result = [];
            $seen = [];

            foreach ($kinds as $kind) {
                if (!$kind instanceof TaskKind
                    || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $kind->slug) !== 1
                    || trim($kind->title) === ''
                    || !in_array($kind->scoring, [TaskKind::SCORING_POINTS, TaskKind::SCORING_PERCENT], true)
                    || isset($seen[$kind->slug])) {
                    throw new \UnexpectedValueException('taskKinds() вернул некорректный или повторный вид задания');
                }

                $seen[$kind->slug] = true;
                $result[] = $kind;
            }

            return $result;
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($id, $e);

            return [];
        }
    }

    /**
     * Маршруты одного плагина: данные контракта проверяются до передачи роутеру.
     *
     * @return list<array{0:string,1:string,2:string,3?:string}>
     */
    public function routesFor(string $id): array
    {
        $plugin = $this->get($id);

        if ($plugin === null) {
            return [];
        }

        try {
            $routes = $plugin->routes();

            if (!is_array($routes)) {
                throw new \UnexpectedValueException('routes() должен вернуть список маршрутов');
            }

            $validated = [];

            foreach ($routes as $route) {
                if (!is_array($route) || count($route) < 3
                    || !is_string($route[0] ?? null)
                    || !is_string($route[1] ?? null)
                    || !is_string($route[2] ?? null)) {
                    throw new \UnexpectedValueException('Маршрут плагина должен содержать method, path и action');
                }

                $method = strtoupper(trim($route[0]));
                $path = '/' . ltrim(trim($route[1]), '/');
                $action = $route[2];
                $capability = $route[3] ?? null;

                if (preg_match('/^[A-Z]{3,10}$/', $method) !== 1
                    || $path === '/'
                    || str_contains($path, '?')
                    || str_contains($path, '#')
                    || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
                    || !is_callable([$plugin, $action])
                    || ($capability !== null && !is_string($capability))) {
                    throw new \UnexpectedValueException('Маршрут плагина содержит недопустимые значения');
                }

                $validated[] = $capability === null
                    ? [$method, $path, $action]
                    : [$method, $path, $action, $capability];
            }

            return $validated;
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($id, $e);

            return [];
        }
    }

    public function knowsKind(string $id, string $kind): bool
    {
        $plugin = $this->get($id);

        if ($plugin === null) {
            return false;
        }

        try {
            return $plugin->knowsKind($kind);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($id, $e);

            return false;
        }
    }

    public function canManageTask(TaskRef $task, int $userId): bool
    {
        $plugin = $this->get($task->pluginId);

        if ($plugin === null) {
            return false;
        }

        try {
            return $plugin->canManageTask($task, $userId);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($task->pluginId, $e);

            return false;
        }
    }

    /** @return array<string,mixed> */
    public function taskData(TaskRef $task): array
    {
        $plugin = $this->get($task->pluginId);

        if ($plugin === null) {
            return [];
        }

        try {
            return $plugin->taskData($task);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($task->pluginId, $e);

            return [];
        }
    }

    /**
     * @param list<TaskRef> $tasks
     * @return list<array<string,mixed>>
     */
    public function taskDataBatch(string $id, array $tasks): array
    {
        $plugin = $this->get($id);

        if ($plugin === null) {
            return array_fill(0, count($tasks), []);
        }

        try {
            $data = $plugin->taskDataBatch($tasks);

            if (!is_array($data) || !array_is_list($data) || count($data) !== count($tasks)) {
                throw new \UnexpectedValueException('taskDataBatch() должен вернуть данные в исходном порядке для всех заданий');
            }

            foreach ($data as $row) {
                if (!is_array($row)) {
                    throw new \UnexpectedValueException('taskDataBatch() вернул не массив данных задания');
                }
            }

            return $data;
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($id, $e);

            return array_fill(0, count($tasks), []);
        }
    }

    public function taskPreview(TaskRef $task): ?string
    {
        $plugin = $this->get($task->pluginId);

        if ($plugin === null) {
            return null;
        }

        try {
            return $plugin->taskPreview($task);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($task->pluginId, $e);

            return null;
        }
    }

    public function taskEditorUrl(TaskRef $task): ?string
    {
        $plugin = $this->get($task->pluginId);

        if ($plugin === null) {
            return null;
        }

        try {
            return $plugin->taskEditorUrl($task);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($task->pluginId, $e);

            return null;
        }
    }

    public function publicationBlocker(TaskRef $task): ?string
    {
        $plugin = $this->get($task->pluginId);

        if ($plugin === null) {
            return 'Плагин выключен или не загрузился';
        }

        try {
            return $plugin->publicationBlocker($task);
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($task->pluginId, $e);

            return 'Плагин не смог проверить задание';
        }
    }

    public function recordRuntimeFailure(string $id, Throwable $e): void
    {
        $message = trim($e->getMessage());
        $this->failures[$id] = $message !== '' ? $message : 'Плагин завершился с неизвестной ошибкой';

        try {
            $this->db->run(
                'UPDATE plugins SET error = :error, updated_at = NOW() WHERE id = :id',
                ['error' => $this->failures[$id], 'id' => $id]
            );
        } catch (Throwable) {
            // Ошибка журнала плагина не должна превращать отказ плагина в 500.
        }
    }

    /**
     * Сбой, из-за которого пострадал один ответ, а не плагин.
     *
     * Отличается от `recordRuntimeFailure()` тем, что плагин остаётся включённым.
     * Это разделение нужно потому, что исключение при обработке ответа ученика
     * чаще всего означает не поломку плагина, а данные: человек вставил в поле
     * то, чего плагин не ждёт. Раньше такой ответ помечал плагин «не загрузился»
     * и записывал это в таблицу, то есть один неудачный ответ выключал вид
     * задания у всего учителя — до похода в панель плагинов, где это надо ещё
     * заметить и снять.
     *
     * Ничего не помечает и ничего не пишет: причина с работающим журналом
     * плагинов уже записана вызывающей стороной, у которой есть работа,
     * задание и попытка. Здесь важно только не выключать плагин, поэтому метод
     * и оставлен: вызвать его трудно перепутать с другим.
     */
    public function recordItemFailure(string $id, Throwable $e): void
    {
        unset($id, $e);
    }

    /** @return list<string> */
    public function enabledIds(): array
    {
        try {
            $rows = $this->db->all('SELECT id FROM plugins WHERE enabled = 1 ORDER BY id');
        } catch (Throwable) {
            // Таблицы ещё не накатаны: считаем, что плагинов нет, и не мешаем
            // работать остальному сайту.
            return [];
        }

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * Плагины, доступные пользователю.
     *
     * Без пользователя (разовые скрипты, миграции, проверки) отвечаем так же,
     * как отвечали до появления личных настроек: главный — по общему флагу,
     * остальные — по общему флагу установки. Иначе проверки и сидеры теряли бы
     * половину плагинов просто потому, что под ними нет сессии.
     *
     * @return list<string>
     */
    public function availableIds(): array
    {
        $userId = $this->auth->id();
        $global = $this->enabledIds();
        $ids = [];

        foreach (array_keys($this->discover()) as $found) {
            $id = (string) $found;

            if (!in_array($id, $global, true)) {
                continue;
            }

            if ($id !== self::BASE_PLUGIN && $userId !== null && !$this->isEnabledForUser($id, $userId)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Включён ли плагин у пользователя.
     *
     * У главного спрашивается общий переключатель, у остальных — личная
     * строка. Отсутствие строки означает «включён»: новый учитель начинает
     * со всеми доступными типами заданий, а не с пустого каталога, и ему не
     * приходится включать то, что ему и так подходит.
     */
    public function isEnabledForUser(string $id, int $userId): bool
    {
        if ($id === self::BASE_PLUGIN) {
            return in_array($id, $this->enabledIds(), true);
        }

        try {
            $value = $this->db->value(
                'SELECT enabled FROM user_plugins WHERE user_id = :u AND plugin_id = :p LIMIT 1',
                ['u' => $userId, 'p' => $id]
            );
        } catch (Throwable) {
            // Таблицы ещё не накатаны: показываем всё, чтобы сайт жил.
            return true;
        }

        return $value === null || (int) $value === 1;
    }

    /**
     * Переключатель плагина у пользователя.
     *
     * @return array{enabled:bool,locked:bool} locked — переключатель общий,
     *         и его двигает только администратор
     */
    public function switchForUser(string $id, ?int $userId): array
    {
        if ($id === self::BASE_PLUGIN) {
            return [
                'enabled' => in_array($id, $this->enabledIds(), true),
                'locked'  => true,
            ];
        }

        if ($userId === null) {
            return ['enabled' => true, 'locked' => false];
        }

        return ['enabled' => $this->isEnabledForUser($id, $userId), 'locked' => false];
    }

    /**
     * Переключить плагин у пользователя.
     *
     * Главного плагина это не касается: его переключатель общий, и право им
     * двигать есть только у администратора. Здесь такой вызов отвергается.
     *
     * @return array{ok:bool,message:string}
     */
    public function setEnabledForUser(string $id, int $userId, bool $enabled): array
    {
        if ($id === self::BASE_PLUGIN) {
            return [
                'ok'      => false,
                'message' => 'Плагин «Тест» основной: его включает администратор',
            ];
        }

        if ($enabled) {
            // Включение убирает строку выключения: хранить «включено» записью
            // незачем, а отсутствие строки и есть включённое состояние.
            $this->db->run(
                'DELETE FROM user_plugins WHERE user_id = :u AND plugin_id = :p',
                ['u' => $userId, 'p' => $id]
            );

            $this->forget();

            return ['ok' => true, 'message' => 'Плагин включён'];
        }

        $this->db->run(
            'INSERT INTO user_plugins (user_id, plugin_id, enabled, updated_at)
             VALUES (:u, :p, 0, NOW())
             ON DUPLICATE KEY UPDATE enabled = 0, updated_at = NOW()',
            ['u' => $userId, 'p' => $id]
        );

        $this->forget();

        return ['ok' => true, 'message' => 'Плагин выключен. Его данные сохранены.'];
    }

    /**
     * Каталог миграций плагина.
     *
     * Собирается из каталога плагина, а не из его `id`.
     *
     * Разница видна на любой переименованной папке: `plugins/olivki/` с
     * `id: test` — совершенно обычное дело при копировании заготовки. Путь,
     * собранный как `plugins/{id}/migrations`, указывал бы в
     * `plugins/test/migrations`, которого нет, — и плагин молча отметился бы
     * установленным, оставив базу без своих таблиц. Плагин включается, в
     * палитре появляется, а первая попытка ученика падает на
     * `Table ... doesn't exist`, и чинить это приходится вручную.
     *
     * `null` означает, что плагина нет на диске: вызывающий обязан на это
     * отреагировать, а не идти дальше.
     */
    public function migrationsDir(string $id): ?string
    {
        $descriptor = $this->discover()[$id] ?? null;

        if ($descriptor === null || $descriptor->dir === '') {
            return null;
        }

        return rtrim($descriptor->dir, '/\\') . '/migrations';
    }

    /**
     * Забыть загруженные плагины: список зависит от прав человека, а право
     * могло смениться в том же запросе.
     */
    private function forget(): void
    {
        $this->loaded = [];
        $this->failures = [];
        $this->booted = false;
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds(), true);
    }

    /**
 * Включение плагина.
 *
 * При первой установке и при обновлении версии накатываются недостающие
 * миграции. Ошибка установки оставляет плагин выключенным: включённый, но
 * не установленный плагин упал бы на первой же попытке ученика.
 *
 * `$globally` различает два разных действия, которые выглядят одинаково.
 * Общее включение — решение администратора: плагин становится доступен всем.
 * Личное — решение учителя: включается только ему. Но и то и другое требует,
 * чтобы плагин был установлен, — миграции общие для всех, и без них личная
 * галочка дала бы учителю вид задания, падающий на первой попытке ученика.
 * Поэтому личное включение тоже ставит плагин на место, но не двигает общий
 * флаг: иначе переключатель одного учителя включал бы вид задания у всех.
 *
 * @return array{ok:bool,message:string}
 */
    public function enable(string $id, bool $globally = true): array
    {
        $descriptor = $this->discover()[$id] ?? null;

        if ($descriptor === null) {
            return ['ok' => false, 'message' => 'Плагин не найден в каталоге plugins/'];
        }

        if ($descriptor->isBroken()) {
            return ['ok' => false, 'message' => $descriptor->errorMessage()];
        }

        $wasEnabled = $this->isEnabled($id);
        $this->remember($descriptor);

        $plugin = $this->boot($descriptor);

        if ($plugin === null) {
            $reason = $this->failures[$id] ?? 'не удалось загрузить код плагина';

            // Возвращаем флаг: включённый, но не загрузившийся плагин упал бы
            // на первой же попытке ученика.
            $this->db->run(
                'UPDATE plugins SET enabled = 0, error = :error, updated_at = NOW() WHERE id = :id',
                ['error' => $reason, 'id' => $id]
            );

            return ['ok' => false, 'message' => $reason];
        }

        try {
            $this->installIfRequired($descriptor, $plugin);

            /*
             * Флаг ставится здесь, а не в remember(): запись о плагине
             * появляется уже при первом просмотре панели, и включение не
             * должно зависеть от того, существовала ли она раньше.
             *
             * При личном включении общий флаг не трогается — см. описание
             * метода. Отдельно снимается и отметка об ошибке: плагин только что
             * загрузился, и старая ошибка от прежней версии к нему отношения
             * не имеет.
             */
            $this->db->run(
                'UPDATE plugins SET enabled = :enabled, error = NULL, updated_at = NOW() WHERE id = :id',
                ['enabled' => $globally ? 1 : (int) $wasEnabled, 'id' => $id]
            );
        } catch (Throwable $e) {
            $this->recordRuntimeFailure($id, $e);

            if ($globally) {
                $this->db->run('UPDATE plugins SET enabled = 0, updated_at = NOW() WHERE id = :id', ['id' => $id]);
            }

            return ['ok' => false, 'message' => 'Ошибка установки: ' . $e->getMessage()];
        }

        $this->forget();

        return [
            'ok' => true,
            'message' => !$globally
                ? 'Плагин установлен'
                : ($wasEnabled
                    ? 'Плагин уже включён; проверка обновлений завершена'
                    : 'Плагин «' . $descriptor->name . '» включён'),
        ];
    }

    /** Выключение. Данные плагина остаются: вернуть его можно одним галочкой. */
    public function disable(string $id): array
    {
        $this->db->run('UPDATE plugins SET enabled = 0, updated_at = NOW() WHERE id = :id', ['id' => $id]);

        $this->forget();


        return ['ok' => true, 'message' => 'Плагин выключен. Его данные сохранены.'];
    }

    public function isInstalled(string $id): bool
    {
        return $this->db->value('SELECT installed_at FROM plugins WHERE id = :id', ['id' => $id]) !== null;
    }

    /** Применить недостающие миграции при установке или смене версии. */
    private function installIfRequired(PluginDescriptor $descriptor, AssessmentPlugin $plugin): void
    {
        $row = $this->db->one(
            'SELECT installed_at, version FROM plugins WHERE id = :id',
            ['id' => $descriptor->id]
        );

    if ($row === null) {
            throw new RuntimeException('Нет записи плагина «' . $descriptor->id . '» в таблице plugins');
        }

        $migrations = rtrim($descriptor->dir, '/\\') . '/migrations';
        $runner = new PluginMigrationRunner($this->db, $descriptor->id, $migrations);

        /*
         * «Установлен и версия та же» — это только разрешение доверять журналу
         * миграций, а не разрешение ничего не делать.
         *
         * Раньше проверка состояния таблиц в этом месте не было, и база без
         * таблиц плагина оставалась такой навсегда: отметка «установлен» была,
         * журнал пуст, миграции не выполнялись, а первое же обращение к заданию
         * падало с «таблица не существует». Условие пропуска — ещё и то, что
         * таблицы, которые объявляют миграции, действительно есть.
         */
        if ($row['installed_at'] !== null
            && (string) $row['version'] === $descriptor->version
            && $runner->declaredTablesExist()) {
            $runner->baselineExisting();

            return;
        }

        $plugin->install();

        $this->db->run(
            'UPDATE plugins
                SET installed_at = COALESCE(installed_at, NOW()), version = :version,
                    error = NULL, updated_at = NOW()
              WHERE id = :id',
            ['version' => $descriptor->version, 'id' => $descriptor->id]
        );
    }

    /**
     * Запомнить найденный плагин, чтобы он появился в панели.
     *
     * Флаг включения здесь не трогается: он принадлежит enable() и
     * disable(), иначе просмотр панели мог бы включать и выключать плагины
    * сами по себе.
     */
    public function remember(PluginDescriptor $descriptor): void
    {
        $exists = $this->db->value('SELECT id FROM plugins WHERE id = :id', ['id' => $descriptor->id]);

        if ($exists === null) {
            $this->db->insert('plugins', [
                'id'          => $descriptor->id,
                'name'        => $descriptor->name,
                'version'     => $descriptor->version,
                'description' => $descriptor->description,
                'author'      => $descriptor->author,
                'enabled'     => 0,
                'error'       => $descriptor->error,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            return;
        }

        $this->db->run(
            'UPDATE plugins
                SET name = :name,
                    version = IF(installed_at IS NULL, :version, version),
                    description = :description,
                    author = :author, error = :error, updated_at = NOW()
              WHERE id = :id',
            [
                'name'        => $descriptor->name,
                'version'     => $descriptor->version,
                'description' => $descriptor->description,
                'author'      => $descriptor->author,
                'error'       => $descriptor->error,
                'id'          => $descriptor->id,
            ]
        );
    }

    /**
     * Подключение кода плагина.
     *
     * Пространство имён добавляется в автозагрузчик, точка входа подключается
     * один раз, а любая ошибка превращается в запись в журнал, а не в
     * белый экран.
     */
    private function boot(PluginDescriptor $descriptor): ?AssessmentPlugin
    {
        $autoloader = $this->app->autoloader();
        $autoloader->addNamespace($descriptor->namespace, rtrim($descriptor->dir, '/\\') . '/src');

        /*
         * Шаблоны плагина подключаются сразу, до проверки кода: даже битый
         * плагин может понадобиться для показа собственных экранов ошибки, а
         * проверка его интерфейса идёт следующим шагом.
         */
        $templatesDir = rtrim($descriptor->dir, '/\\') . '/templates';

        if (is_dir($templatesDir)) {
            $this->view->addTemplateRoot($descriptor->id, $templatesDir);
        }

        $entry = $descriptor->entryPath();
        $pluginRoot = realpath($descriptor->dir);
        $entryReal = realpath($entry);

        if (!is_file($entry) || $pluginRoot === false || $entryReal === false
            || !str_starts_with($entryReal, rtrim($pluginRoot, '/\\') . DIRECTORY_SEPARATOR)) {
            $this->failures[$descriptor->id] = 'Файл точки входа не найден: ' . $descriptor->entry;

            return null;
        }

        try {
            /*
             * Синтаксис проверяется до подключения.
             *
             * `try/catch` ловит исключения, но не ловит ошибку разбора: если в
             * точке входа опечатка, `require_once` обрывает весь запрос, и
             * «сайт не должен падать из-за одного плохого плагина» оказывается
             * неправдой именно там, где нужнее всего. Разбор с `TOKEN_PARSE`
             * бросает обычное `ParseError` — то же самое, что ловится.
             *
             * Файл читается целиком: это мелочь по размеру и единственный способ
             * узнать о синтаксисе, не выполняя код.
             */
            $source = @file_get_contents($entry);

            if (!is_string($source)) {
                $this->failures[$descriptor->id] = 'Файл точки входа не читается: ' . $descriptor->entry;

                return null;
            }

            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $this->failures[$descriptor->id] = 'Синтаксическая ошибка в ' . $descriptor->entry . ': ' . $e->getMessage();

                return null;
            }

            require_once $entry;

            /*
             * Класс берётся из имени файла, а не из пути к нему: пространство
             * имён уже указывает на каталог src/, и повторять его в пути
             * не нужно. При соглашении «namespace → src/, класс → одноимённый
             * файл» это работает и для plugins/test/src/TestPlugin.php,
             * и для вложенных каталогов.
             */
            $class = rtrim($descriptor->namespace, '\\') . '\\' . basename($entry, '.php');

            if (!class_exists($class)) {
                $this->failures[$descriptor->id] = 'Класс ' . $class . ' не найден после подключения файла';

                return null;
            }

            // Плагин получает не контейнер, а перечень того, что ему положено:
            // см. PluginServices. Отдавать ему App целиком означало бы отдать
            // и настройки, и соседние плагины — не по нужде, а по недосмотру.
            $plugin = new $class(new PluginServices($this->app, $descriptor->id));

            if (!$plugin instanceof AssessmentPlugin) {
                $this->failures[$descriptor->id] = 'Класс не реализует AssessmentPlugin';

                return null;
            }

            if ($plugin->id() !== $descriptor->id) {
                $this->failures[$descriptor->id] = 'Точка входа возвращает id «' . $plugin->id()
                    . '», а в манифесте «' . $descriptor->id . '»';

                return null;
            }

            return $plugin;
        } catch (Throwable $e) {
            $this->failures[$descriptor->id] = $e->getMessage();

            return null;
        }
    }

    /** Прочитать и проверить манифест. Ошибка не бросается, а попадает в описание. */
    private function readManifest(string $file, string $dir): PluginDescriptor
    {
        $raw = (string) @file_get_contents($file);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return new PluginDescriptor('', basename($dir), '0.0.0', $dir, '', '',
                error: 'Манифест ' . self::MANIFEST . ' не читается или содержит не объект JSON');
        }

        $id = (string) ($data['id'] ?? '');
        $name = (string) ($data['name'] ?? '');
        $version = (string) ($data['version'] ?? '');
        $entry = (string) ($data['entry'] ?? '');
        $namespace = rtrim((string) ($data['namespace'] ?? ''), '\\');
        $urlPrefix = (string) ($data['url_prefix'] ?? '');
        $requires = $data['requires'] ?? '';

        if (is_array($requires)) {
            $requires = $requires['core'] ?? '';
        }

        $requiresCore = is_scalar($requires) ? trim((string) $requires) : '';

        $make = function (?string $error) use (
            $dir, $id, $name, $version, $namespace, $entry, $urlPrefix, $requiresCore, $data
        ): PluginDescriptor {
            return new PluginDescriptor(
                $id,
                $name !== '' ? $name : $id,
                $version !== '' ? $version : '0.0.0',
                $dir,
                $namespace,
                $entry,
                (string) ($data['description'] ?? ''),
                (string) ($data['author'] ?? ''),
                $requiresCore,
                $urlPrefix,
                $error,
                ($data['provides_tasks'] ?? false) === true
            );
        };

        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id) !== 1) {
            return $make('id должен состоять из строчной латиницы, цифр и дефиса');
        }

        if ($name === '') {
            return $make('В манифесте нет name');
        }

        if ($namespace === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*(\\\\[A-Za-z][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
            return $make('В манифесте нет корректного namespace');
        }

        if ($entry === '') {
            return $make('В манифесте не указан entry — файл точки входа');
        }

        $entryPath = str_replace('\\', '/', $entry);

        if (str_starts_with($entryPath, '/')
            || preg_match('#(^|/)\.\.?(/|$)#', $entryPath) === 1
            || !str_ends_with(strtolower($entryPath), '.php')) {
            return $make('entry должен указывать на PHP-файл внутри каталога плагина');
        }

        $routePrefix = $urlPrefix !== '' ? $urlPrefix : $id;

        if ($urlPrefix !== '' && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $urlPrefix) !== 1) {
            return $make('url_prefix должен быть одним сегментом из строчной латиницы, цифр и дефиса');
        }

        if ($routePrefix !== '') {
            if (in_array($routePrefix, self::RESERVED_PREFIXES, true)) {
                return $make('url_prefix совпадает со служебным или системным адресом');
            }
        }

        if ($requiresCore !== '') {
            if (preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/', $requiresCore) !== 1) {
                return $make('requires.core должен содержать версию вида 1.0 или 1.0.0');
            }

            if (version_compare(App::VERSION, $requiresCore, '<')) {
                return $make('Плагину требуется ядро ' . $requiresCore . ' или новее; установлено ' . App::VERSION);
            }
        }

        // null, а не пустая строка: пустое сообщение об ошибке сделало бы
        // исправный плагин «битым» без всякой причины.
        return $make(null);
    }
}
