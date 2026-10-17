<?php

declare(strict_types=1);

/**
 * Оболочка панели управления — разметка шаблона gentelella.
 *
 * Устройство взято из шаблона целиком: тёмное боковое меню слева, шапка с
 * крошками сверху, содержимое в `.page-wrapper`. Классы — те же, что и в
 * шаблоне, потому что стили приходят из его сборки.
 *
 * Оболочка выводится на сервере, а не собирается скриптом. В шаблоне её
 * подставляет сборщик, но здесь страницы и так формируются на сервере, и
 * меню, которое появилось бы после загрузки, мигало бы и не существовало бы
 * вовсе без JavaScript. Скрипт нужен только для сворачивания и для темы.
 *
 * Ожидает в области видимости:
 * $view, $content, $currentUser, $appName, $panelNav
 *
 * @var \MyEdu\Core\View $view
 * @var string $content
 * @var \MyEdu\Auth\User $currentUser
 * @var string|null $appName
 * @var list<array{title:string, items:list<array<string,mixed>>}> $panelNav
 */

// Счётчик ждут проверки дублируется в шапке и в меню. Считается один раз
// здесь: две цифры, разошедшиеся из-за двух запросов, выглядели бы как ошибка.
$pendingReview = 0;

foreach ($panelNav as $group) {
 foreach ($group['items'] as $item) {
 if (($item['url'] ?? '') === 'panel/results') {
 $pendingReview = (int) ($item['badge'] ?? 0);
 }
 }
}

$hasMenu = $panelNav !== [];

?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
 <meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
 <title><?= $view->e($title ?? $appName) ?></title>
<?php /*
     * Иконка объявлена здесь, а не полагается на догадку браузера.
     *
     * Без этой строки адрес /favicon.ico всё равно запрашивается на каждом
     * просмотре — но уже через front controller, то есть вместе с сессией и
     * проверкой схемы базы. Файл лежит рядом, но без ссылки браузер про него
     * не знает.
     */
 ?>
 <link rel="icon" href="/favicon.ico" sizes="any">
<?php /*
     * Порядок подключения — не вкусовое предпочтение, а условие того, чьи
     * стили победят.
     *
     * app.css идёт первым и несёт только блоки предметной области: таблицы
     * вариантов, дерево работ, лист печати, поля вопроса. Их в шаблоне нет и
     * быть не может — это конструкции этого приложения.
     *
     * Сборка шаблона идёт после и забирает всё общее: карточки, кнопки,
     * поля, таблицы, шапку, меню. Собственные стили проекта с теми же
     * именами в файле ещё остались, и подключённые последними они бы перебили
     * шаблон — то есть класс в разметке был бы шаблонный, а вид прежний.
     *
     * theme.css идёт последним намеренно: это переопределение токенов
     * (синий основной цвет, тёмная тема), и оно обязано видеть токены
     * шаблона, а не наоборот.
     */ ?>
    <?php /* Токен защиты в разметке: его читают скрипты. */ ?>
    <meta name="csrf-token" content="<?= $view->e($view->csrfToken()) ?>">
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/css/app.css')) ?>">
    <?php /*
         * Стили таблиц идут перед сборкой шаблона: шаблон переопределяет их
         * своими классами .dt-*, и порядок обратный вернул бы вид библиотеки
         * вместо вида шаблона.
         */ ?>
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/vendor/datatables/dataTables.min.css')) ?>">
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/vendor/gentelella/gentelella.css')) ?>">
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/css/theme.css')) ?>">
 <?php /*
 * Тема ставится до первой отрисовки.
 *
 * Иначе страница мигнула бы светлой и осталась такой: тёмные значения живут
 * в правиле [data-theme="dark"], а пока атрибута нет, действуют светлые.
 * Человек увидел бы белый экран вместо панели и решил, что сайт сломан.
 *
 * Скрипт подключён без defer и в отдельном файле: без defer он выполнится
 * до отрисовки, а в файле — потому что политика безопасности отдаётся с
 * script-src 'self', и код прямо в разметке был бы заблокирован. Тема
 * молча перестала бы переключаться, а страница выглядела бы исправной.
 *
 * В самой разметке атрибут уже стоит, так что без скриптов страница всё
 * равно тёмная — см. theme.css.
 */ ?>
 <script src="<?= $view->e($view->asset('assets/js/theme-boot.js')) ?>"></script>
</head>
<body>

<?php if ($hasMenu): ?>
<aside class="sidebar" id="sidebar" aria-label="Разделы панели">
 <div class="sidebar-brand">
 <a class="brand-icon" href="<?= $view->e($view->url('portal')) ?>" title="На главную">
 <?= $view->e(mb_substr((string) $appName, 0, 1)) ?>
 </a>
 <div class="brand-name"><?= $view->e($appName) ?></div>
 </div>

 <nav class="sidebar-nav">
 <?php foreach ($panelNav as $group): ?>
 <div class="nav-group">
 <div class="nav-label"><?= $view->e($group['title']) ?></div>

 <?php foreach ($group['items'] as $item): ?>
 <a class="nav-link<?= !empty($item['active']) ? ' active' : '' ?>"
 href="<?= $view->e($view->url((string) $item['url'])) ?>"
 <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>>
 <?php $itemIcon = (string) ($item['icon'] ?? 'dashboard'); ?>
 <?php require __DIR__ . '/_panel_icon.php'; ?>
 <span class="nav-text"><?= $view->e($item['label']) ?></span>
 <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
 <span class="badge badge-red"><?= $view->e((int) $item['badge']) ?></span>
 <?php endif; ?>
 </a>
 <?php endforeach; ?>
 </div>
 <?php endforeach; ?>
 </nav>

 <?php /*
 * Профиль — в подвале боковой колонки, как в шаблоне. Выхода здесь нет:
 * колонки нет вовсе у ученика и наблюдателя, и кнопка выхода была бы им
 * недоступна. Выход живёт в шапке, где он виден всем.
 */ ?>
 <div class="sidebar-footer">
 <div class="sidebar-user">
 <a class="avatar" href="<?= $view->e($view->url('profile')) ?>"
 title="<?= $view->e($currentUser->shortName()) ?>">
 <?= $view->e(mb_substr($currentUser->fullName, 0, 1)) ?>
 <span class="online" aria-hidden="true"></span>
 </a>
 <div class="sidebar-user-info">
 <a class="name" href="<?= $view->e($view->url('profile')) ?>">
 <?= $view->e($currentUser->shortName()) ?>
 </a>
 <div class="role"><?= $view->e($currentUser->rolesLabel()) ?></div>
 </div>
 </div>
 </div>
</aside>
<?php endif; ?>

<header class="topbar">
 <div class="topbar-left">
 <?php /*
 * Кнопка меню видна только когда меню есть: у ученика и наблюдателя
 * колонки нет, и кнопка открывала бы пустоту.
 */ ?>
 <?php if ($hasMenu): ?>
 <button class="sidebar-toggle" type="button"
 aria-label="Открыть меню" aria-controls="sidebar" aria-expanded="false">
 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
 stroke-width="1.5" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
 </button>
 <?php endif; ?>

<?php
/*
* Крошки объявляет страница через $view->crumbs(), а разметку строит здесь:
* в шаблоне gentelella они стоят в шапке, рядом с кнопкой сворачивания
* меню. В теле страницы их быть не должно — иначе на каждой странице панели
* одни и те же крошки показываются дважды.
*
* Если страница крошек не заявила, показывается её заголовок: пустое место в
* шапке выглядит как ошибка вёрстки, а не как отсутствие данных.
*/
$crumbsHtml = $view->crumbsHtml();

if ($crumbsHtml === '') {
    $crumbsHtml = '<nav class="breadcrumb" aria-label="Хлебные крошки">'
        . '<span class="current" aria-current="page">'
        . $view->e($title ?? $appName)
        . '</span></nav>';
}
?>
<?= $view->raw($crumbsHtml) ?>
</div>

 <div class="topbar-right">
 <?php /* Ждут проверки — тот же счётчик, что и в меню. */ ?>
 <a class="tb-btn<?= $pendingReview > 0 ? ' tb-notifications' : '' ?>"
 href="<?= $view->e($view->url('panel/results')) ?>"
 title="Выдачи, которые ждут проверки" aria-label="Работы, ждущие проверки">
 <?php $icon = 'needs_review'; ?>
 <?php require __DIR__ . '/_panel_icon.php'; ?>
 <?php if ($pendingReview > 0): ?>
 <span class="dot" aria-hidden="true"></span>
 <?php endif; ?>
 </a>

 <?php /*
 * Кнопка темы. Она переключает тёмную и светлую и помнит выбор: человек
 * возвращается на сайт и получает тот вид, который выбрал.
 */ ?>
 <button class="tb-btn theme-toggle" type="button" data-theme-toggle
 title="Сменить тему" aria-label="Сменить тему" aria-pressed="true">
 <svg class="theme-icon-light" width="18" height="18" viewBox="0 0 24 24" fill="none"
 stroke="currentColor" stroke-width="1.5" aria-hidden="true">
 <circle cx="12" cy="12" r="4"/>
 <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
 </svg>
 <svg class="theme-icon-dark" width="18" height="18" viewBox="0 0 24 24" fill="none"
 stroke="currentColor" stroke-width="1.5" aria-hidden="true">
 <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
 </svg>
 </button>

 <a class="tb-btn" href="<?= $view->e($view->url('profile')) ?>" title="Мои данные">
 <?php $icon = 'profile'; ?>
 <?php require __DIR__ . '/_panel_icon.php'; ?>
 </a>

 <?php /*
 * Выход — в шапке, и он единственный: колонки с меню у ученика и
 * наблюдателя нет вовсе, и выход в боковой колонке был бы им
 * недоступен. Заодно это единственная форма на странице, из которой
 * берётся токен защиты: у человека без меню на странице работы не
 * было ни одной формы, и отправка оттуда отбивалась как подделка.
 *
 * Кнопка в форме, а не ссылка на отдельный адрес: выход меняет
 * состояние на сервере, и по ссылке его не сделать.
 */ ?>
 <form method="post" action="<?= $view->e($view->url('logout')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button class="tb-btn" type="submit" title="Выйти из системы" aria-label="Выйти из системы">
 <?php $icon = 'logout'; ?>
 <?php require __DIR__ . '/_panel_icon.php'; ?>
 </button>
 </form>
 </div>
</header>

<main class="main">
 <div class="page-wrapper">
 <?php if (!empty($notice)): ?>
 <?= $view->raw($view->bannerOk($notice)) ?>
 <?php endif; ?>

 <?php if (!empty($error)): ?>
 <?= $view->raw($view->bannerError($error)) ?>
 <?php endif; ?>

 <?php /* Этапы есть только у мастера редактора работы. */ ?>
 <?php if (!empty($wizard)): ?>
 <?php require __DIR__ . '/_wizard_steps.php'; ?>
 <?php endif; ?>

<?= $view->raw($content) ?>
  </div>

<?php /*
 * Подвал лежит внутри `.main`, а не рядом с ним — и это не вопрос вкуса.
 *
 * Отступ от сайдбара задаётся правилом `.main { margin-inline-start: … }`:
 * подвал рядом с `<main>` начинался от края окна и уезжал под тёмную панель,
 * а текст в нём пропадал. Иными словами, место под подвалом резервировала
 * сама обёртка содержимого, и подвал должен быть её частью.
 */ ?>
  <footer class="footer">
   <?php /* Название берётся из настроек и само всё говорит: приписывать к нему */
         /* «система контроля знаний» значило бы показать его дважды. */ ?>
   <span><?= $view->e($appName) ?></span>
   <?php if ($appVersion !== ''): ?>
   <span>Версия <?= $view->e($appVersion) ?></span>
   <?php endif; ?>
   <span>Оформление — <a href="https://github.com/ColorlibHQ/gentelella" target="_blank" rel="noopener">gentelella</a>, MIT</span>
  </footer>
 </main>

<script src="<?= $view->e($view->asset('assets/js/panel-nav.js')) ?>" defer></script>
<script src="<?= $view->e($view->asset('assets/js/confirm-modal.js')) ?>" defer></script>
<script src="<?= $view->e($view->asset('assets/js/toast.js')) ?>" defer></script>
<?php /*
 * Меню строк таблицы. Само меню построено на <details> и работает без скрипта;
 * скрипт только приподнимает раскрытое меню над обрезкой прокручиваемой
 * таблицы. На страницах без меню он ничего не делает.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/row-menu.js')) ?>" defer></script>
<?php /*
 * Переключатель плагина: убирает кнопку и отправляет форму по переключению.
 * Без скриптов форма отправляется кнопкой, которая в разметке остаётся.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/plugin-switch.js')) ?>" defer></script>
<?php /*
 * Правка значения прямо в строке таблицы: столбец «ФИО» на странице класса и
 * столбец «Предмет» на странице предметов устроены одинаково. На страницах без
 * таких форм скрипт не делает ничего.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/inline-edit.js')) ?>" defer></script>
<?php /*
 * Открытие всплывающих окон по кнопке. Без скриптов окно показано сразу —
 * см. правило в theme.css по отсутствию data-js.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/modal-open.js')) ?>" defer></script>
<?php /*
 * Проверка полей перед отправкой: обязательные и формат.
 *
 * Модуль ничего не стирает — он только не даёт отправить форму с пустым полем и
 * подсказывает, что исправить. Сервер проверяет те же значения сам: браузеру
 * доверять нельзя, а человеку — можно.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/form-validate.js')) ?>" defer></script>
<?php /*
 * Модуль: браузер грузит его и его зависимости отдельными запросами, и только
 * там, где такая таблица есть на странице. Без модулей (старый браузер,
 * запрет на них) страница остаётся целой — таблица пришла с сервера и просто
 * показывается целиком, без поиска и сортировки.
 */ ?>
<script type="module" src="<?= $view->e($view->asset('assets/js/tables.js')) ?>"></script>
</body>
</html>