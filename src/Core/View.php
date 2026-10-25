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
 * а также переменные, передаваемые в render().
 *
 * Экранирование — ручное и обязательное: сам вид ничего не экранирует, он
 * только даёт для этого $this->e(). Данные выводятся через него; $this->raw()
 * — сознательная пометка «разметка уже безопасна» для собранных компонентов
 * (Ui, баннеры, санитайзер Html::clean). Правило проверяется набором ui-rules:
 * каждый вывод в шаблоне обязан назвать свой выход, и echo с сырой переменной
 * падает проверкой, а не ждёт находки.
 *
 * Вид ничего не достаёт сам. Раньше он брал `App::instance()` в шести местах —
 * адреса публичной части, базовый путь, каталог проекта и защитное поле, — и
 * был поэтому непригоден ни вне запроса, ни в проверке: вид, который должен был
 * быть расставляющим ссылки, знал про контейнер целиком. Теперь всё, что нужно,
 * передаётся при создании, и вид можно собрать с подменёнными адресами — что
 * и делает проверка ссылок.
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

    /**
     * Шапка прохождения работы — решение владельца от 24.10.2026.
     *
     * На странице задания крошки заменяются на «имя+класс | таймер | кнопки»:
     * ученику важны он сам и оставшееся время, а навигация по разделам ему
     * ни к чему — отвлекает от задания и ведёт к списку, который во время
     * прохождения скрыт. Страница задания объявляет шапку тем же способом,
     * что и крошки: она рендерится раньше оболочки, и переменная не долетит.
     *
     * Оболочка проверяет шапку прохождения раньше крошек: если страница
     * заявила первую, крошки не рисуются вовсе — иначе в шапке оказались бы
     * и имя с таймером, и следы прежней навигации.
     *
     * @var array{remaining:int|null,formId:string,buttons:list<array{action:string,label:string}>}|null
     */
    private ?array $attemptBar = null;

/**
 * Где лежит корень сайта и откуда вид адреса.
 *
 * Передаётся вместо контейнера: вид должен уметь работать в проверке, где
 * ни сессии, ни базы нет, а адреса — есть. Всё, что нужно от приложения, —
 * это префикс публичной части, базовый путь, каталог проекта и токен.
 */
public function __construct(
        string $templateDir,
        private readonly string $basePath = '',
        private readonly string $rootDir = '',
        private readonly ?Csrf $csrf = null,
        private readonly ?string $publicDirUrl = null
    ) {
        $this->templateDir = rtrim($templateDir, '/\\');
        $this->ui = new Ui($this);
    }

    /**
     * Адрес публичной части от корня сайта: `/public/assets/app.css`.
     *
     * Задаётся снаружи, потому что каталог public/ лежит по разному адресу.
     * На шаред-хостинге корень сайта смотрит в каталог проекта, и публичная
     * часть лежит по адресу `/public/`. На локальном сервере корень сайта — это
     * сам каталог public/, и адрес пустой: публичная часть лежит прямо в корне.
     *
     * Раньше здесь стояло `basePath . '/public'` — и на локальном сервере стили
     * не находились: страница открывалась совсем без оформления, и снимок
     * экрана показывал голый HTML. Правило «публичная часть всегда по адресу
     * /public» верно только для шаред-хостинга, а адрес у сервера один и тот
     * же — его и надо спрашивать.
     */
    public function publicDirUrl(): string
    {
        if ($this->publicDirUrl !== null) {
            return $this->publicDirUrl;
        }

        return $this->basePath . '/public';
    }

    /** Адрес файла публичной части с путём внутри неё. */
    public function publicUrl(string $relative): string
    {
        return $this->publicDirUrl() . '/' . ltrim($relative, '/');
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
     * Заявить шапку прохождения работы вместо крошек.
     *
     * Кнопки навигации объявляются страницей, а не рисует их оболочка сама:
     * какие кнопки нужны — решает позиция (на первой нет «назад», на последней
     * «далее» сменяется «завершить»), а это знает только страница. Оболочка
     * рисует их кнопками `submit` с атрибутом `form`, указывающим на форму
     * задания: так навигация переезжает в шапку, но остаётся частью той же
     * формы и уходит на сервер обычным POST — без скриптов и без потери
     * токена защиты.
     *
     * @param array{remaining?:int|null,formId?:string,buttons?:list<array{action?:string,label?:string}>} $data
     */
    public function attemptBar(array $data): void
    {
        $buttons = [];

        foreach (is_array($data['buttons'] ?? null) ? $data['buttons'] : [] as $button) {
            $action = (string) ($button['action'] ?? '');
            $label = (string) ($button['label'] ?? '');

            if ($action !== '' && $label !== '') {
                $buttons[] = ['action' => $action, 'label' => $label];
            }
        }

        $remaining = $data['remaining'] ?? null;

        $this->attemptBar = [
            'remaining' => is_numeric($remaining) ? (int) $remaining : null,
            'formId'    => (string) ($data['formId'] ?? 'attempt-form'),
            'buttons'   => $buttons,
        ];
    }

    /**
     * Заявленная шапка прохождения или null, если страница крошки обычная.
     *
     * @return array{remaining:int|null,formId:string,buttons:list<array{action:string,label:string}>}|null
     */
    public function attemptBarData(): ?array
    {
        return $this->attemptBar;
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
        $prefix = $this->publicDirUrl();

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
        $base = $this->basePath;

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
        $url = $this->publicUrl($path);

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
        $file = $this->rootDir . '/public/' . $relative;

        if (!is_file($file)) {
            return $url;
        }

        return $url . '?v=' . (string) filemtime($file);
    }

    public function csrfField(): string
    {
        /*
         * Защитное поле приходит при создании вида.
         *
         * Без него вид отдаёт пустое поле, а не падает: вид используется и там,
         * где запроса нет (проверки, письма), и требовать от него сессии значит
         * сделать его бесполезным ровно там, где его собирают вручную.
         */
        return $this->csrf?->field() ?? '';
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
        return $this->csrf?->token() ?? '';
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

    /*
     * Число и слово вместе: «8 классы», «1 работа», «25 работ».
     *
     * Раньше число и слово печатались рядом двумя выражениями, и пробел между
     * ними появлялся только потому, что между тегами в шаблоне стоял перевод
     * строки, который браузер показывает пробелом. Работало, пока перенос не
     * убрали при правке вёрстки, и «8классы» слипалось: слово читается как
     * часть числа.
     *
     * Пробел теперь задаётся здесь, то есть вместе со склонением, и не
     * зависит от того, как расставлены строки в шаблоне.
     */
    public function count(int $number, string $one, string $few, string $many): string
    {
        return $number . ' ' . $this->plural($number, $one, $few, $many);
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

    /**
     * Что пропадёт вместе с классом.
     *
     * Текст один на два места: окно подтверждения в списке классов и страница
     * подтверждения. Раньше они описывали одно и то же удаление разными словами,
     * и ученик, удалявший класс прямо из списка, узнавал об учениках и работах
     * меньше, чем тот, кто сначала перешёл на страницу подтверждения: одно
     * окно обещало «его ученики, логины и сданные работы», другое — то же самое
     * плюс пароли и число учеников.
     *
     * Про пустой класс сказано отдельно, потому что там удаляется не то же
     * самое: попытки снимаются вместе с учениками, а учеников нет — значит,
     * нечего и снимать, и обещать пропажу сданных работ было бы враньём.
     */
    public function classRemovalWarning(int $students): string
    {
        if ($students < 1) {
            return 'В классе нет учеников: удалятся сам класс, его предметы и учителя, '
                . 'а выданные работы останутся без класса. Восстановить это нельзя.';
        }

        return 'Вместе с классом будут удалены все его ученики — '
            . $this->count($students, 'человек', 'человека', 'человек')
            . ' — вместе с логинами, паролями и всеми сданными работами. '
            . 'Восстановить это нельзя.';
    }
}