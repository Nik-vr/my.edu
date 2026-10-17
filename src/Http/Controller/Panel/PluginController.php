<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Plugin\PluginManager;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;

/**
 * Управление плагинами: список найденных и включение.
 *
 * Страница одна и та же для всех, кто ведёт тесты, но состояние у каждого
 * своё: учитель отключает лишние типы заданий у себя и не трогает настройки
 * коллег. Исключение — «Тест»: он основной, его переключатель общий, и
 * двигать его может только администратор.
 */
final class PluginController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly PluginManager $plugins,
        private readonly Auth $auth,
        private readonly UserRepository $users
    ) {
    }

    /**
     * Администратор — единственный, кто двигает общий переключатель «Теста».
     */
    private function isAdmin(): bool
    {
        $id = $this->auth->id();

        return $id !== null && in_array(Role::ADMIN, $this->users->rolesOf($id), true);
    }

    public function index(Request $request): Response
    {
        return $this->render('panel/plugins', [
            'title'    => 'Плагины',
            'rows'     => $this->rows(),
            'pluginsDir' => $this->plugins->directoryLabel(),
            'isAdmin'  => $this->isAdmin(),
            'notice'   => $this->session->pull('flash_notice'),
            'error'    => $this->session->pull('flash_error'),
        ]);
    }

    public function enable(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = (string) $request->postParam('id', '');
        $result = $this->plugins->enable($id);

        $this->session->set($result['ok'] ? 'flash_notice' : 'flash_error', (string) $result['message']);

        return $this->redirect('panel/plugins');
    }

    public function disable(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = (string) $request->postParam('id', '');
        $result = $this->plugins->disable($id);

        $this->session->set($result['ok'] ? 'flash_notice' : 'flash_error', (string) $result['message']);

        return $this->redirect('panel/plugins');
    }

    /**
     * Переключатель включения плагина.
     *
     * Один адрес вместо двух: переключатель говорит «включён», а не «какое из
     * двух действий выбрать». Иначе в форме пришлось бы прятать настоящее
     * состояние плагина и отправлять противоположное ему — а состояние живёт в
     * базе, и страница с задержкой в один запрос обнуляла бы его молча.
     *
     * Значение приходит двумя полями: скрытое «выключено» стоит перед
     * переключателем, поэтому при снятом переключателе сервер получает «0», а
     * при включённом — «1». Полагаться на отсутствие поля нельзя: тогда
     * запрос без него и запрос с пустым значением выглядели бы одинаково.
     *
     * Главного плагина это не касается: его переключатель недоступен учителю,
     * и подделка запроса отвергается тем же правилом.
     */
    public function toggle(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = (string) $request->postParam('id', '');
        $wanted = (string) $request->postParam('enabled', '0') === '1';

        $result = $id === PluginManager::BASE_PLUGIN
            ? $this->basePlugin($id, $wanted)
            : $this->ownPlugin($id, $wanted);

        $this->session->set($result['ok'] ? 'flash_notice' : 'flash_error', (string) $result['message']);

        return $this->redirect('panel/plugins');
    }

    /**
     * Главный плагин: общий переключатель, и только у администратора.
     *
     * @return array{ok:bool,message:string}
     */
    private function basePlugin(string $id, bool $wanted): array
    {
        if (!$this->isAdmin()) {
            return ['ok' => false, 'message' => 'Плагин «Тест» основной: его включает администратор'];
        }

        return $wanted ? $this->plugins->enable($id) : $this->plugins->disable($id);
    }

    /**
     * Остальные плагины: личная настройка того, кто смотрит страницу.
     *
     * Первое включение плагина кем бы то ни было накатывает его таблицы:
     * плагин должен быть живым в системе, иначе он попал бы в каталог и упал
     * бы на первой попытке ученика. Дальше каждый решает у себя.
     *
     * @return array{ok:bool,message:string}
     */
    private function ownPlugin(string $id, bool $wanted): array
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return ['ok' => false, 'message' => 'Нужно войти в систему'];
        }

        $descriptor = $this->plugins->descriptor($id);

        if ($descriptor === null) {
            return ['ok' => false, 'message' => 'Плагин не найден в каталоге plugins/'];
        }

        if ($wanted) {
            // Даже глобально установленный плагин мог получить новую версию
            // и миграции, пока был выключен у этого пользователя.
            $result = $this->plugins->enable($id);

            if (!$result['ok']) {
                return $result;
            }
        }

        return $this->plugins->setEnabledForUser($id, $userId, $wanted);
    }

    /**
     * Таблица для шаблона.
     *
     * Собирается здесь, а не в шаблоне, чтобы правки панели не превращали
     * страницу в место с запросами к базе прямо в разметке.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $descriptors = $this->plugins->discover();
        $this->plugins->all();
        $userId = $this->auth->id();
        $alive = $this->plugins->enabledIds();

        $rows = [];

        foreach ($descriptors as $id => $descriptor) {
            $kinds = array_map(
                static fn (\MyEdu\Plugin\TaskKind $kind): string => $kind->title,
                $this->plugins->taskKinds((string) $id)
            );

            $switch = $this->plugins->switchForUser((string) $id, $userId);
            $installed = $this->plugins->isInstalled((string) $id);

            $rows[] = [
                'id'          => $id,
                'name'        => $descriptor->name,
                'version'     => $descriptor->version,
                'description' => $descriptor->description,
                'author'      => $descriptor->author,
                // Личная настройка: её показывает и меняет сам человек.
                'enabled'     => $switch['enabled'],
                /*
                 * А плагин при этом ещё и жив в системе. Личная настройка по
                 * умолчанию «включено», поэтому у только что положенной папки
                 * обе они говорили «включён у вас» — при плагине, у которого нет
                 * ни таблиц, ни видов заданий. Одно поле «доступен» отвечает на
                 * вопрос, который на самом деле задаёт человек: работает ли это
                 * у меня сейчас, — и рисует переключатель в его положении.
                 *
                 * Живость берётся тем же, чем пользуется каталог видов
                 * заданий, а не отметкой об установке: установленная однажды
                 * папка остаётся установленной и после выключения, и по ней
                 * одной судить нельзя.
                 */
                'available'   => $switch['enabled'] && in_array((string) $id, $alive, true),
                // Заблокированный переключатель показывается, но не двигается:
                // учитель должен видеть, что «Тест» есть и что решение не его.
                'locked'      => $switch['locked'],
                'base'        => (string) $id === PluginManager::BASE_PLUGIN,
                'installed'   => $installed,
                'error'       => $descriptor->isBroken() ? $descriptor->errorMessage() : '',
                'kinds'       => $kinds,
            ];
        }

        $failures = $this->plugins->failures();

        foreach ($rows as &$row) {
            if (($failures[$row['id']] ?? '') !== '') {
                $row['error'] = $failures[$row['id']];
            }
        }
        unset($row);

        return $rows;
    }
}
