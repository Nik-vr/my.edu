<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Core\Csrf;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\SystemUpdate;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;

/**
 * Обновление системы на сервере.
 *
 * Отдельный контроллер, а не действие в настройках сайта: обновление меняет
 * файлы, из которых работает сайт, и к нему не должно быть дороги «вписать
 * название школы».
 *
 * Право — `users.manage`, то есть администратор. Обновление меняет всю систему
 * на сервере, и право на это должно быть у того, кто и так распоряжается
 * сайтом.
 */
final class UpdateController
{
    use ControllerHelpers;

    public function __construct(
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly SystemUpdate $update,
        /*
         * Вид нужен помощникам: без него `redirect()` обходит `$this->view` и
         * падает с «Call to a member function url() on null» — уже после того,
         * как обновление выполнено. То есть файлы заменены, а человек видит
         * ошибку и решает, что ничего не произошло.
         */
        private readonly View $view
    ) {
    }

    /**
     * Обновить систему до последнего релиза.
     */
    public function apply(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $result = $this->update->apply();

        if (($result['ok'] ?? false) === true) {
            $this->session->set('flash_notice', (string) ($result['message'] ?? 'Система обновлена.'));
        } else {
            $this->session->set('flash_error', (string) ($result['message'] ?? 'Обновление не выполнено.'));
        }

        return $this->redirect('panel/settings');
    }
}
