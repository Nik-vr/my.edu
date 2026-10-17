<?php

declare(strict_types=1);

namespace MyEdu\Core;

use MyEdu\Ui\Ui;
use RuntimeException;
use Throwable;

/**
 * Рендер шаблонов из каталога templates/ обычным PHP.
 *
 * В шаблонах доступны: $this->e(), $this->raw(), $this->url(), $this->csrf(),
 * а также переменные, переданные в render(). Данные всегда экранируются
 * через e() — это осознанное требование безопасности.
 */
final class View
{
    private string $templateDir;

    private string $layout = 'layout';

    /** @var array<string,mixed> */
    private array $shared = [];

    /** @var array<string,string> префикс шаблона => каталог */
    private array $roots = [];

    private Ui $ui;

    /**
     * Хлебные крошки, заявленные страницей.
     *
     * Крошки живут в шапке оболочки, а не в теле страницы: в шаблоне gentelella
     * они стоят рядом с кнопкой сворачивания меню. Но шаблон страницы рендерится
     * раньше оболочки и в отдельной области видимости, поэтому объявить их
     * обычной переменной `$breadcrumb` он не может — переменная не долетит.
     * Объявление уходит в объект вида, а разметку строит оболочка: одна копия
     * разметки на всё приложение вместо копии в каждом шаблоне.
     *
     * @var list<array{label:string,url?:string}>
     */
    private array $crumbs = [];

    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, '/\\');
        $this->ui = new Ui($this);
    }

    /**
     * Типовые элементы интерфейса: поля, редактор, сообщения.
     *
     * Отдаётся и шаблону (как `$ui`), и коду (плагину — из контекста). Форма
     * одна: иначе ядро и плагин нарисовали бы одинаковое поле по-разному, и
     * разошлись при первом изменении оформления.
     */
    public function ui(): Ui
    {
        return $this->ui;
    }

    /**
     * Добавить каталог шаблонов под префиксом.
     *
     * Нужно плагинам: их экраны лежат в их собственном каталоге, а общий
     * шаблон сайта остаётся в templates/. Имя шаблона при этом пишется как
     * test/attempt — так же, как маршрут плагина пишется как tests/attempt.
     */
    public function addTemplateRoot(string $prefix, string $dir): void
    {
        $this->roots[trim($prefix, '/')] = rtrim($dir, '/\\');
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Заявить хлебные крошки страницы.
     *
     * Последний элемент — текущая страница: она не ссылка. Элемент без адреса
     * тоже не ссылка, даже если стоит не последним (например, когда страница
     * недостижима по прямому адресу).
     *
     * @param list<array{label:string,url?:string}> $items
     */
    public function crumbs(array $items): void
    {
        $this->crumbs = array_values(array_filter(
            array_map(
                static fn (mixed $item): array => [
                    'label' => (string) ($item['label'] ?? ''),
                    'url'   => (string) ($item['url'] ?? ''),
                ],
                is_array($items) ? $items : []
            ),
            static fn (array $item): bool => $item['label'] !== ''
        ));
    }

    /**
     * Готовая разметка крошек для шапки.
     *
     * Разделитель ставится перед каждым элементом, кроме первого, и скрывается
     * от чтения с экрана: «/» там не значит ничего, и озвучивать его лишнее.
     */
    public function crumbsHtml(): string
    {
        if ($this->crumbs === []) {
            return '';
        }

        $last = count($this->crumbs) - 1;
        $html = '<nav class="breadcrumb" aria-label="Хлебные крошки">';

        foreach ($this->crumbs as $i => $crumb) {
            if ($i > 0) {
                $html .= '<span class="sep" aria-hidden="true">›</span>';
            }

            if ($i === $last || $crumb['url'] === '') {
                $html .= '<span class="current" aria-current="page">'
                    . $this->e($crumb['label']) . '</span>';
            } else {
                $html .= '<a href="' . $this->e($this->url($crumb['url'])) . '">'
                    . $this->e($crumb['label']) . '</a>';
            }
        }

        return $html . '</nav>';
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->capture($template, $data);

        $chosenLayout = $layout ?? $this->layout;

        if ($chosenLayout === '') {
            return $content;
        }

        return $this->capture($chosenLayout, array_merge($data, ['content' => $content]));
    }

    /**
     * Готовое содержимое в оболочку сайта.
     *
     * Нужно там, где фрагмент готовит плагин, а страницу собирает ядро: разбор
     * задания готовится один раз, а показывается то встраиванием в результат
     * работы, то отдельной страницей ответов. Без этого метода плагин вынужден
     * был бы угадывать, как его вызов используют, и оборачивать в оболочку всегда —
     * от чего внутри уже собранной страницы появлялась вторая копия шапки и меню.
     *
     * @param array<string,mixed> $data
     */
    public function wrap(string $content, array $data = []): string
    {
        if ($this->layout === '') {
            return $content;
        }

        return $this->capture($this->layout, $data + ['content' => $content]);
    }

    private function capture(string $template, array $data): string
    {
        $file = $this->resolve($template);

        if ($file === null) {
            throw new RuntimeException('Шаблон не найден: ' . $template);
        }

        $scope = array_merge($this->shared, $data);
        $scope['view'] = $this;
        /*
         * `$ui` кладётся в каждую отрисовку, а не в раз: шаблоны плагинов
         * приходят тем же render(), и без него плагин рисовал бы поля сам —
         * то есть снова по-своему.
         */
        $scope['ui'] = $this->ui;

        ob_start();

        try {
            (static function (string $__file, array $__scope): void {
                extract($__scope, EXTR_SKIP);
                require $__file;
            })($file, $scope);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Поиск файла шаблона.
     *
     * Сначала проверяются каталоги плагинов по префиксу имени, потом общий
     * каталог. Порядок важен: плагин может подложить свою версию шаблона с
     * тем же именем, если это осознанное решение автора плагина.
     */
    private function resolve(string $template): ?string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';
        $slash = strpos($template, '/');

        if ($slash !== false) {
            $prefix = substr($template, 0, $slash);

            if (isset($this->roots[$prefix])) {
                $candidate = $this->roots[$prefix] . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, substr($template, $slash + 1)) . '.php';

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $candidate = $this->templateDir . DIRECTORY_SEPARATOR . $relative;

        return is_file($candidate) ? $candidate : null;
    }

    public function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function raw(mixed $value): string
    {
        return (string) $value;
    }

    /**
     * Баннер с сообщением — разметка шаблона gentelella (`.banner`).
     *
     * Метод, а не отдельный файл-частица. Частицу пришлось бы подключать
     * через `__DIR__`, а каталоги шаблонов вложены по-разному: из auth/
     * до файла в корне шаблонов один путь, из panel/ — другой. Ошибка в пути
     * даёт не «страница без баннера», а PHP-предупреждение в журнал и тихую
     * пустоту на экране — то есть сообщение пропадало бы там, где оно важнее
     * всего.
     *
     * @param string $text    Текст сообщения
     * @param string $variant Вариант шаблона: '' — обычный, 'banner-info',
     *                        'banner-warning' или 'banner-danger'
     * @param string $role    Роль для чтения с экрана: 'status' для успеха,
     *                        'alert' для отказа
     */
    public function banner(string $text, string $variant = '', string $role = 'status'): string
    {
        $icons = [
            'banner-danger'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.2v.3"/>',
            'banner-warning' => '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4M12 17.3v.2"/>',
            'banner-info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.7v.2"/>',
        ];

        $known = ['', 'banner-danger', 'banner-warning', 'banner-info'];
        $variant = in_array($variant, $known, true) ? $variant : '';
        $path = $icons[$variant] ?? '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>';

        return sprintf(
            '<div class="banner %s" role="%s">'
            . '<span class="banner-icon" aria-hidden="true">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.6">%s</svg></span>'
            . '<div class="banner-body">%s</div></div>',
            $variant,
            $role === 'alert' ? 'alert' : 'status',
            $path,
            $this->e($text)
        );
    }

    /** Баннер об удавшейся операции. */
    public function bannerOk(string $text): string
    {
        return $this->banner($text);
    }

    /**
     * Подпись ошибки под полем.
     *
     * Метод остаётся на виде, потому что о нём уже написано в шаблонах полей
     * имени (`_name_fields.php`): там он был обещан и не существовал, и ошибки
     * приходилось рисовать вручную — в плагинах в том числе, где они просто
     * не появлялись. Смысл у обоих один: рисует `Ui::fieldError()`.
     */
    public function fieldError(string $message): string
    {
        return $this->ui->fieldError($message);
    }

    /** Баннер об отказе: красный, и это здесь не украшение. */
    public function bannerError(string $text): string
    {
        return $this->banner($text, 'banner-danger', 'alert');
    }

    /** Баннер-предупреждение: действие возможно, но человек должен знать о цене. */
    public function bannerWarning(string $text): string
    {
        return $this->banner($text, 'banner-warning', 'alert');
    }

    /** Баннер-подсказка: пояснение, а не отказ. */
    public function bannerInfo(string $text): string
    {
        return $this->banner($text, 'banner-info');
    }

    /**
     * Вывод размеченного текста: очистить и отдать как есть.
     *
     * Очистка повторяется на выводе, хотя при записи она уже была. Дело в
     * том, что база могла достаться из старой копии, из выгрузки или из
     * ручной правки — и там разметка может быть любой, включая принесённую
     * до того, как очистка появилась. На выходе это второй рубеж: чтобы его
     * пройти, разметку необязательно где-то вычищать, достаточно не быть
     * выведенной через raw() без очистки.
     *
     * Пользоваться raw() напрямую для данных из базы нельзя — это вывод без
     * экранирования, и одна забытая скобка превращается в выполнение кода в
     * браузере.
     */
    public function rich(mixed $value): string
    {
        $clean = Html::clean((string) $value);
        $prefix = App::instance()->publicDirUrl();

        // Картинки хранятся с адресом от корня сайта («/uploads/…»): такой
        // видят и санитайзер, и редактор. Если публичная часть лежит не в
        // корне, адрес надо дополнить префиксом — иначе на странице будут
        // битые картинки там, где корень сайта смотрит в каталог проекта.
        if ($prefix !== '') {
            $clean = str_replace(
                ['="/uploads/', '="/assets/'],
                ['="' . $prefix . '/uploads/', '="' . $prefix . '/assets/'],
                $clean
            );
        }

        return $clean;
    }

    /** Размеченный текст без разметки — для поиска, заголовков и сравнения. */
    public function plain(mixed $value): string
    {
        return Html::toText((string) $value);
    }

    public function url(string $path): string
    {
        $base = App::instance()->basePath();

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Адрес статического файла с отметкой версии.
     *
     * К файлам без отметки браузер относится по своему усмотрению и чаще
     * всего месяцами отдаёт сохранённую копию. После правки стилей или
     * скриптов человек видил старое поведение и удивлялся: кнопка «настроена»,
     * а не нажимается, поле «включено», а не принимает текст. И это выглядело
     * как ошибка в коде, хотя код был уже правильным.
     *
     * Отметка ставится по времени изменения файла: она меняется сама, ровно
     * тогда, когда меняется содержимое, и не требует ни ручной правки, ни
     * настройки сервера.
     */
    public function asset(string $path): string
    {
        $relative = ltrim($path, '/');

        // Стили и скрипты ищутся там, где они физически лежат, а не там,
        // каким адресом открыт сайт. На хостинге, где корень сайта смотрит в
        // каталог проекта, это единственная разница между оформленной
        // страницей и страницей без стилей.
        $url = App::instance()->publicUrl($path);

        if (!str_starts_with($relative, 'assets/')) {
            return $url;
        }

        /*
         * Публичные файлы лежат в public/, а не в корне проекта: корень сайта
         * смотрит именно туда, и всё из public/ отдаётся напрямую. Искать надо
         * по тому же месту, откуда файл реально отдаётся, иначе отметка версии
         * молча не проставится — а это именно тот случай, когда она нужнее
         * всего.
         */
        $file = App::instance()->rootDir() . '/public/' . $relative;

        if (!is_file($file)) {
            return $url;
        }

        return $url . '?v=' . (string) filemtime($file);
    }

    public function csrfField(): string
    {
        /** @var Csrf $csrf */
        $csrf = App::instance()->get(Csrf::class);

        return $csrf->field();
    }

    /**
     * Токен защиты отдельным значением — для скриптов.
     *
     * Форма отдаёт токен скрытым полем, и этого достаточно странице без
     * скриптов. Скрипту же поле недоступно: он строит отправку сам, поэтому
     * токен кладётся в разметку как meta — так же, как это сделано в самом
     * шаблоне для его таблиц.
     */
    public function csrfToken(): string
    {
        /** @var Csrf $csrf */
        $csrf = App::instance()->get(Csrf::class);

        return $csrf->token();
    }

    /**
     * Инициалы для кружка, который стоит вместо аватара.
     *
     * Первая буква фамилии и первая буква имени, а не первые две буквы подряд:
     * у «Тестов Тест Тестович» это «Те» от слова «Тестов», и такой кружок
     * читается как опечатка в имени, а не как инициалы.
     *
     * Правило живёт здесь, а не в шаблонах, потому что кружки рисуются в трёх
     * местах — в списке сотрудников, в профиле и в шапке, — и три копии
     * одного правила разъедутся при первом же исключении.
     */
    public function initials(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
        $parts = $parts === false ? [] : $parts;

        $surname = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
        // Без имени берётся вторая буква фамилии: одна буква в кружке не
        // читается, пустой кружок — тем более.
        $name = mb_substr($parts[1] ?? mb_substr($parts[0] ?? '', 1, 1, 'UTF-8'), 0, 1, 'UTF-8');

        return mb_strtoupper($surname . $name, 'UTF-8');
    }

    /** Человекочитаемое склонение: 1 вопрос, 2 вопроса, 5 вопросов. */
    public function plural(int $number, string $one, string $few, string $many): string
    {
        $mod100 = $number % 100;
        $mod10 = $number % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        if ($mod10 === 1) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }
}