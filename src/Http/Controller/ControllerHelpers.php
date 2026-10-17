<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller;

use MyEdu\Core\Csrf;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\View;

/**
 * Общие мелочи контроллеров: редиректы, рендер и разбор идентификатора в URL.
 *
 * Вынесено, чтобы в каждом контроллере не повторялось одно и то же и чтобы
 * все страницы выглядели одинаково.
 */
trait ControllerHelpers
{
    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect($this->view->url($path), $status);
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    /**
     * Просит ли клиент JSON вместо страницы.
     *
     * Правило живёт в самом запросе, в `Request::wantsJson()`. Здесь оно было
     * продублировано и уже разошлось с ядром: проверка заголовка X-Requested-With
     * осталась только в ядре, и один и тот же запрос в одном контроллере
     * считался JSON, а в другом — нет.
     *
     * Проверяется заголовок, а не отдельный скрытый параметр: иначе проверку
     * можно было бы обойти обычной отправкой формы и получить JSON вместо
     * страницы.
     *
     * Правило одно на всех, кто отвечает и форме, и скрипту: форма ждёт
     * перенаправления, скрипт просит JSON и не перезагружает страницу.
     */
    protected function wantsJson(Request $request): bool
    {
        return $request->wantsJson();
    }

    /**
     * Ответ скрипту.
     *
     * @param array<string,mixed> $payload
     */
    protected function json(array $payload): Response
    {
        $response = Response::json($payload);
        $response->withHeader('Cache-Control', 'no-store');

        return $response;
    }

/**
 * Запомнить введённое, чтобы вернуть его на страницу формы после отказа.
 *
 * Правило формы общее для всех: отказ сервера не должен стоить человеку
 * напечатанного. Поэтому значения кладутся в сессию под именем формы, а шаблон
 * при следующем открытии берёт их оттуда вместо данных из базы — иначе после
 * ошибки форма выглядела бы нетронутой, и всё введённое пропадало бы.
 *
 * Ключ задаётся вызывающим: он же читается в GET-обработчике через
 * `session->pull()`. Читать его надо именно так — с извлечением: иначе значения
 * переживут исправление и всплывут на следующей странице формы.
 *
 * @param array<string,true> $skip имена полей, которые запоминать не нужно
 */
protected function keepInput(string $key, Request $request, array $skip = ['_token']): void
{
    $values = $request->post();
    $kept = [];

    foreach ($values as $name => $value) {
        $name = (string) $name;

        if (in_array($name, $skip, true)) {
            continue;
        }

        /*
         * Пароли не запоминаются никогда — даже если поле называется не так,
         * как ожидают.
         *
         * Сохранённое значение живёт в файле сессии на диске, и предыдущие
         * версии клали туда всё подряд. Учитель набрал пароль в форме смены,
         * отправил, получил отказ по другой причине — и его пароль остался в
         * сессии лежать, пока открыт сеанс, а форма при следующем открытии
         * показала его снова целиком: человек, подошедший за компьютером, видел
         * чужой пароль в поле.
         *
         * Проверка по названию поля, а не по списку: поле с паролем может
         * называться как угодно, а вот «внутри похоже на пароль» ловит и
         * `password2`, и `new_password`, и `student_password`.
         */
        if (self::looksLikePassword($name)) {
            continue;
        }

        if (is_array($value)) {
            // Список отметок сохраняем целиком: иначе после отказа пришлось бы
            // отмечать заново двадцать пять учеников.
            $kept[$name] = array_values(array_filter(
                array_map(static fn ($item): string => (string) $item, $value),
                static fn (string $item): bool => $item !== ''
            ));

            continue;
        }

        if (is_scalar($value)) {
            $kept[$name] = (string) $value;
        }
    }

    $this->session->set($key, $kept);
}

/**
 * Похоже ли поле на поле с паролем.
 *
 * Имя сравнивается в нижнем регистре и без разделителей: `password`,
 * `new_password`, `pass`, `pin` и `student_password` должны отсеиваться
 * одинаково. Слово ищется целиком, а не как часть: `passwd_mode` — это не
 * пароль, и терять его не надо.
 */
private static function looksLikePassword(string $name): bool
{
    $flat = (string) preg_replace('/[^a-z0-9а-яё]+/i', '', $name);

    foreach (['password', 'passwd', 'pass', 'pin', 'secret'] as $word) {
        if (str_contains(mb_strtolower($flat, 'UTF-8'), $word)) {
            return true;
        }
    }

    return false;
}

/**
 * Значение поля или запасное, если поле не присылали.
 *
 * @param array<string,mixed> $old
 */
protected function old(array $old, string $name, mixed $fallback): string
{
    if (!array_key_exists($name, $old)) {
        return (string) $fallback;
    }

    return (string) $old[$name];
}

/**
 * Приводит параметр маршрута к положительному числу.
 */
    protected function intParam(array $params, string $key): int
    {
        return max(0, (int) ($params[$key] ?? 0));
    }

/**
     * Список положительных чисел из значений поля формы.
     *
     * Принимает как результат postList() (несколько флажков roles[]=a&roles[]=b),
     * так и строку «1,2,3» — в списках вопросов это зачастую удобнее.
     *
     * @param array<mixed>|string $raw
     * @return list<int>
     */
    protected function idList(array|string $raw): array
    {
        // Вложенный тернарный оператор без скобок в PHP 8 недопустим,
        // поэтому разбираем источник отдельным шагом.
        $parts = is_array($raw)
            ? $raw
            : (preg_split('/[\s,]+/', $raw) ?: []);

        $items = [];

        foreach ($parts as $part) {
            if (is_scalar($part) && preg_match('/^\d+$/', trim((string) $part)) === 1) {
                $items[] = (int) trim((string) $part);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Список строковых кодов из значений поля формы: observer, teacher, admin.
     *
     * Отдельный метод, потому что idList() пропускает только числа, а коды
     * ролей состоят из букв и получились бы «пустым списком».
     *
     * @param array<mixed>|string $raw
     * @return list<string>
     */
    protected function codeList(array|string $raw): array
    {
        $parts = is_array($raw)
            ? $raw
            : (preg_split('/[\s,]+/', $raw) ?: []);

        $items = [];

        foreach ($parts as $part) {
            $value = is_scalar($part) ? trim((string) $part) : '';

            if ($value !== '' && preg_match('/^[a-z][a-z0-9_]*$/i', $value) === 1) {
                $items[] = strtolower($value);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Проверка CSRF-токена из POST.
     */
    protected function requireToken(Request $request, Csrf $csrf): void
    {
        $csrf->validate($request->postParam('_token'));
    }
}