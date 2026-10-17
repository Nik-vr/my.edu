<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;

/**
 * Загрузка картинок в текст вопросов и заданий.
 *
 * Адрес один для всех плагинов. Загрузка — не свойство плагина: каталог
 * загрузок, проверка содержимого и ограничения живут в ядре, и у каждого
 * плагина своя копия этого адреса расходилась бы с ядром при первой же правке.
 *
 * Так было и случилось: у плагина «Вариативная задача» своего адреса не было,
 * и кнопка картинки в его редакторе молча ничего не делала — скрипт видел
 * пустой адрес и уходил, ничего не сказав. Человек выбирал файл и не понимал,
 * что его отбросили.
 */
final class EditorAssetController
{
    use ControllerHelpers;

    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly View $view,
        private readonly ImageUpload $uploads
    ) {
    }

    /** @param array<string,mixed> $params */
    public function upload(Request $request, array $params): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401, 'Нужно войти в систему');
        }

        if (!$user->hasCapability(\MyEdu\Auth\Role::TESTS_MANAGE)) {
            throw new HttpException(403, 'Нет права загружать файлы');
        }

        // Токен проверяется здесь же, а не только в форме задания: адрес
        // доступен любому, кто загрузил страницу с редактором, и без проверки
        // он был бы отдельной дорогой к загрузке файлов мимо формы.
        $this->csrf->validate($request->postParam('_token'));

        $result = $this->uploads->store($request->file('image'));

        if (($result['ok'] ?? false) !== true) {
            return Response::json(
                ['ok' => false, 'error' => (string) ($result['error'] ?? 'Загрузка не удалась.')],
                422
            );
        }

        return Response::json(['ok' => true, 'url' => (string) ($result['url'] ?? '')]);
    }
}