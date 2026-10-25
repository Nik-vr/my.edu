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
 * $view, $content, $currentUser, $fontSize, $appName, $panelNav,
 * $studentClassName (имя класса вошедшего ученика, пусто если нет),
 * $theme (личная тема: dark/light, из профиля),
 * $sidebarRail (true — меню свёрнуто по cookie),
 * $navClosed (состояния разделов меню: ключ группы → свёрнута ли она)
 *
 * @var \MyEdu\Core\View $view
 * @var string $content
 * @var \MyEdu\Auth\User|\MyEdu\Auth\Student $currentUser
 * @var string $fontSize
 * @var string $theme
 * @var bool $sidebarRail
 * @var array<string,bool> $navClosed
 * @var string|null $appName
 * @var string $studentClassName
 * @var list<array{title:string, items:list<array<string,mixed>>}> $panelNav
 */

use MyEdu\Auth\Role;

// Счётчик ждут проверки дублируется в шапке и в меню. Считается один раз
// здесь: две цифры, разошедшиеся из-за двух запросов, выглядели бы как ошибка.
$pendingReview = 0;

foreach ($panelNav as $group) {
 foreach ($group['items'] as $item) {
    if (($item['url'] ?? '') === 'panel/journal') {
 $pendingReview = (int) ($item['badge'] ?? 0);
 }
 }
}

$hasMenu = $panelNav !== [];

?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= $view->e($theme) ?>" data-font-size="<?= $view->e($fontSize) ?>">
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
    <?php /*
     * Метка уборки тестовой попытки: её ставит оболочка только на страницах
     * тестового прохождения (метка есть в сессии), и по ней скрипт шлёт
     * beacon при закрытии вкладки — решение владельца от 24.10.2026,
     * задача 6: результаты пробы живут ровно до закрытия вкладки.
     */ ?>
    <?php if ((int) ($trialAttempt ?? 0) > 0): ?>
    <meta name="trial-cleanup" content="<?= $view->e($view->url('work/trial/cleanup')) ?>"
          data-attempt="<?= $view->e((int) $trialAttempt) ?>">
    <?php endif; ?>
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
<?php /*
 * Свёрнутое меню ставится классом на самом сервере — по cookie, которую
 * писал скрипт при прежнем нажатии (решение владельца от 24.10.2026, волна
 * E). Раньше класс вешал скрипт после загрузки, и страница успевала
 * отрисоваться развёрнутым меню и «схлопнуться» — на каждом переходе.
 *
 * Класс безвреден на узком экране: основные правила sidebar-rail живут в
 * media-запросе для широких экранов, а там он лишь разрешает ширину панели.
 */ ?>
<body<?= $sidebarRail ? ' class="sidebar-rail"' : '' ?>>

<?php if ($hasMenu): ?>
<?php /*
 * tabindex="-1" — не для перехода с клавиатуры по Tab (в меню он не нужен),
 * а чтобы скрипт мог отдать фокус меню при открытии: пункт меню достижим
 * сразу, а Escape возвращает фокус на кнопку.
 */ ?>
<aside class="sidebar" id="sidebar" tabindex="-1" aria-label="Разделы панели">
 <div class="sidebar-brand">
 <a class="brand-icon" href="<?= $view->e($view->url('portal')) ?>" title="На главную">
 <?= $view->e(mb_substr((string) $appName, 0, 1)) ?>
 </a>
 <div class="brand-name"><?= $view->e($appName) ?></div>
 </div>

 <nav class="sidebar-nav">
 <?php foreach ($panelNav as $group): ?>
 <?php
 /*
  * Заголовок раздела — кнопка, если раздел сворачивается (решение
  * владельца от 24.10.2026, задача 1): «Работы», «Школа» и «Система»
  * сворачиваются по нажатию и помнят состояние каждая в своей cookie,
  * «Главная» — обычный заголовок, сворачивать нечего.
  *
  * Активный пункт разворачивает свою группу всегда — и при дефолтной
  * свёрнутости, и при личной: человек попал в раздел, и пункты раздела
  * должны быть видны. Cookie при этом не переписывается — раскрытие не
  * отменяет его выбора для следующего входа.
  */
 $groupKey = (string) ($group['key'] ?? '');
 $groupCollapsible = !empty($group['collapsible']);
 $groupClosed = $groupCollapsible
     && (($navClosed[$groupKey] ?? false) && empty(array_filter(
         $group['items'],
         static fn (array $item): bool => !empty($item['active'])
     )));
 $groupId = 'nav-group-' . $groupKey;
 ?>
 <div class="nav-group<?= $groupClosed ? ' nav-group--closed' : '' ?>">
 <?php if ($groupCollapsible): ?>
 <button type="button" class="nav-label nav-label--toggle"
 data-nav-toggle="<?= $view->e($groupKey) ?>"
 aria-controls="<?= $view->e($groupId) ?>"
 aria-expanded="<?= $groupClosed ? 'false' : 'true' ?>">
 <?= $view->e($group['title']) ?>
 </button>
 <?php else: ?>
 <div class="nav-label"><?= $view->e($group['title']) ?></div>
 <?php endif; ?>

 <div class="nav-sublist" id="<?= $view->e($groupId) ?>">
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
<?php /*
 * Подложка выдвижного меню: затемняет страницу и закрывает панель по нажатию.
 * Стиль для неё описан в сборке, элемента не было — и меню открывалось без
 * подложки. Стоит сразу после меню, пока видна: на широком экране сборка её
 * прячет (`display: none`), так что на десктопе её место не заметно.
 */ ?>
<div class="sidebar-backdrop" hidden></div>
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
$attemptBar = $view->attemptBarData();

if ($attemptBar !== null) {
    /*
     * Страница прохождения работы: крошки заменяются шапкой прохождения —
     * «имя+класс | таймер | кнопки». Решение владельца от 24.10.2026: во
     * время задания навигация по разделам отвлекает и ведёт к списку
     * заданий, который на время прохождения скрыт. Крошки не дополняются,
     * а заменяются — иначе в шапке оказались бы и имя с таймером, и следы
     * прежней навигации.
     *
     * Кто проходит: имя целиком, а не короткое — на странице работы важно
     * видеть, под кем именно она идёт: в одном классе возможны однофамильцы.
     * Класс дописывается только когда он есть: у ученика вне класса и у
     * сотрудника подписи не будет, и запятая с пустотой позади читалась бы
     * как поломка.
     */
    $whoLabel = (string) $currentUser->fullName;
    $studentClassName = $studentClassName ?? '';

    if ($studentClassName !== '') {
        $whoLabel .= ', ' . $studentClassName;
    }

    /*
     * Таймер — тот же частичный шаблон, что раньше стоял в теле задания, и
     * с теми же идентификаторами `timer`/`timer-value`: их ищет attempt.js,
     * и менять их при переезде в шапку нельзя — скрипт перестал бы вести
     * отсчёт и предупреждать о конце времени.
     *
     * Без лимита времени таймер не рисуется вовсе: пустая пилюля читалась бы
     * как «время кончилось» (решает сам частичный шаблон).
     */
    $timerHtml = $attemptBar['remaining'] === null
        ? ''
        : $view->raw($view->render('work/_timer', ['remaining' => $attemptBar['remaining']], ''));

    /*
     * Кнопки навигации — обычные submit-кнопки формы задания, но лежащие в
     * шапке: атрибут `form` связывает кнопку с формой в теле страницы, и
     * нажатие уходит на сервер тем же POST с тем же токеном защиты, что и
     * кнопка внутри формы. Так навигация физически переезжает в шапку, а
     * работоспособность без скриптов сохраняется: на этой странице это
     * обязательное условие — без скриптов ученик всё равно должен уметь
     * ответить и перейти дальше.
     *
     * Акцентная кнопка — та, что ведёт вперёд: «далее» и «завершить» должны
     * читаться как главное действие, «назад» — как вспомогательное.
     */
    $navHtml = '';

    foreach ($attemptBar['buttons'] as $button) {
        $primary = $button['action'] !== 'prev';

        $navHtml .= '<button type="submit"'
            . ' form="' . $view->e($attemptBar['formId']) . '"'
            . ' name="action" value="' . $view->e($button['action']) . '"'
            . ' class="btn btn-sm' . ($primary ? ' btn-primary' : '') . '">'
            . $view->e($button['label'])
            . '</button>';
    }

    $crumbsHtml = '<div class="attempt__who">' . $view->e($whoLabel) . '</div>'
        . $timerHtml
        . ($navHtml === '' ? '' : '<div class="attempt__nav">' . $navHtml . '</div>');
} else {
    $crumbsHtml = $view->crumbsHtml();

    if ($crumbsHtml === '') {
        $crumbsHtml = '<nav class="breadcrumb" aria-label="Хлебные крошки">'
            . '<span class="current" aria-current="page">'
            . $view->e($title ?? $appName)
            . '</span></nav>';
    }
}
?>
<?= $view->raw($crumbsHtml) ?>
</div>

 <div class="topbar-right">
 <?php /*
 * Ждут проверки — тот же счётчик, что и в меню.
 *
 * Кнопка стоит только там, где журнал открыт: учителю, завучу и администратору
 * он открыт правом reports.view, а у остальных кнопка вела бы на страницу,
 * которая им не открыта, — как вела учителя, пока раздел был закрыт.
 */ ?>
  <?php if ($currentUser->hasCapability(Role::REPORTS_VIEW)): ?>
  <a class="tb-btn<?= $pendingReview > 0 ? ' tb-notifications' : '' ?>"
  href="<?= $view->e($view->url('panel/journal')) ?>"
  title="Результаты, которые ждут проверки" aria-label="Результаты: работы, ждущие проверки">
 <?php $icon = 'needs_review'; ?>
 <?php require __DIR__ . '/_panel_icon.php'; ?>
 <?php if ($pendingReview > 0): ?>
 <span class="dot" aria-hidden="true"></span>
 <?php endif; ?>
 </a>
 <?php endif; ?>

 <?php /*
 * Кнопки смены темы в шапке больше нет — решение владельца от 24.10.2026
 * (задача 2): тема выбирается на странице профиля, в карточке «Вид
 * интерфейса», рядом с размером шрифта. Отдельная кнопка в шапке
 * дублировала ту же настройку в другом месте и путала: два входа к одному
 * свойству.
 */ ?>
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
  <?php /*
   Результат действия — всплывающим сообщением, а не баннером.

   Баннер на всю страницу занимал место и оставался там, пока её не закрыли,
   хотя сообщение относилось к одному нажатию: человек и так видит, что
   нажал, и ему нужно знать только, чем это кончилось. Сообщение показывает
   скрипт toast.js, который читает эту метку при загрузке.

   Баннеры остались в других местах — там, где они не результат действия, а
   часть страницы: «включите систему проверки», «работа без названия» и
   подобные предупреждения. Их читают, а не пропускают.
   */ ?>
  <?php if (!empty($notice)): ?>
  <span data-toast="<?= $view->e($notice) ?>" data-toast-variant="success" hidden></span>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
  <span data-toast="<?= $view->e($error) ?>" data-toast-variant="error" hidden></span>
  <?php endif; ?>

  <?php /*
   * Всплывающее сообщение, оставленное сервером на странице.
   *
   * Баннер для сообщений после перехода: там человек мог и не заметить, что
   * страница сменилась, и обязан увидеть результат. Здесь страница не
   * уходила — человек видит на ней же то, что изменилось, и баннер был бы
   * второй вещью об одном. Сообщение показывает скрипт toast.js, который
   * читает эту метку при загрузке.
   */
  ?>
  <?php if (!empty($toast ?? '')): ?>
  <span data-toast="<?= $view->e($toast) ?>" hidden></span>
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
<?php if ((int) ($trialAttempt ?? 0) > 0): ?>
<script src="<?= $view->e($view->asset('assets/js/trial-cleanup.js')) ?>" defer></script>
<?php endif; ?>
<?php /*
 * Собственный цвет оценки (выбор автора в палитре браузера) накладывается
 * скриптом: четыре привычных цвета живут в CSS-классах и не требуют
 * скриптов, а инлайновый style= в шаблонах запрещён правилами интерфейса.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/grade-color.js')) ?>" defer></script>
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