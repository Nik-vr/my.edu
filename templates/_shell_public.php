<?php

declare(strict_types=1);

/**
 * Оболочка страниц вне панели: вход, регистрация, восстановление пароля.
 *
 * Разметка шаблона gentelella — карточка `.auth-card` по центру. Меню и шапки
 * здесь нет: человек ещё не вошёл, и пустая тёмная колонка слева выглядела бы
 * поломкой.
 *
 * Тёмная тема ставится так же, как в панели: атрибут на <html> и тот же
 * скрипт в шапке. Иначе гость видел бы светлую страницу входа рядом с тёмной
 * панелью — два разных вида одного сайта.
 *
 * Ожидает в области видимости: $view, $content, $currentUser, $appName,
 * $currentPath.
 *
 * @var \MyEdu\Core\View $view
 * @var string $content
 * @var \MyEdu\Auth\User|\MyEdu\Auth\Student|null $currentUser
 * @var string $fontSize
 * @var string|null $appName
 * @var string $currentPath
 */
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= $view->e($theme) ?>" data-font-size="<?= $view->e($fontSize) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->e($title ?? $appName) ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <?php /*
         * Порядок подключения — не вкусовое предпочтение, а условие того,
         * чьи стили победят.
         *
         * app.css идёт первым и несёт только блоки предметной области:
         * таблицы вариантов, дерево работ, лист печати, поля вопроса. Их в
         * шаблоне нет и быть не может — это конструкции этого приложения.
         *
         * Сборка шаблона идёт после и забирает всё общее: карточки, кнопки,
         * поля, таблицы, шапку, меню. Собственные стили проекта с теми же
         * именами в файле ещё остались, и подключённые последними они бы
         * перебили шаблон — то есть класс в разметке был бы шаблонный, а вид
         * прежний.
         *
         * theme.css идёт последним намеренно: это переопределение токенов
         * (синий основной цвет, тёмная тема), и оно обязано видеть токены
         * шаблона, а не наоборот.
         */ ?>
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/css/app.css')) ?>">
    <?php /*
         * Стили таблиц идут перед сборкой шаблона: шаблон переопределяет их
         * своими классами .dt-*, и порядок обратный вернул бы вид библиотеки
         * вместо вида шаблона.
         */ ?>
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/vendor/datatables/dataTables.min.css')) ?>">
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/vendor/gentelella/gentelella.css')) ?>">
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/css/theme.css')) ?>">
    <script src="<?= $view->e($view->asset('assets/js/theme-boot.js')) ?>"></script>
    <?php /*
         * Работа по ссылке и форма нового пароля по ссылке из письма приходят с
         * токеном в адресе — иначе их не открыть. Скрипт убирает токен из
         * адресной строки сразу после загрузки, чтобы он не остался в истории
         * и не попал в скопированную ссылку.
         */ ?>
    <script src="<?= $view->e($view->asset('assets/js/token-in-url.js')) ?>" defer></script>
</head>
<body>

<?php if (!empty($currentUser)): ?>
    <?php /*
     * Карточка на странице, которая уже не про вход, — это оболочка панели.
     * Здесь её быть не должно, но если страницу открыли мимо входа, то
     * перенаправление выше по цепочке ещё не сработало: пустая колонка меню
     * была бы хуже обычного содержимого.
     */ ?>
    <main class="auth-page">
        <div class="auth-card">
            <div class="auth-actions">
                <a href="<?= $view->e($view->url('portal')) ?>">Вернуться в систему</a>
            </div>
        </div>
    </main>
<?php else: ?>
    <?php
    /*
     * Две формы входа, и выбор между ними — вкладки.
     *
     * По адресу сайта открывается ученическая: именно её логин и пароль
     * напечатаны на листе, и именно по этому адресу ученик приходит. Раньше
     * здесь была витрина, и человек с листом сначала угадывал, какую из двух
     * ссылок нажать.
     *
     * Ссылки, а не переключение скриптом: страница входа — первая, куда
     * попадает человек на свежей установке, и обязана работать там, где
     * скрипты не доехали. Побочно адрес пересылается и работает «назад».
     *
     * Регистрация — на вкладке сотрудника: регистрация дело сотрудника, и
     * предлагать её тому, кто пришёл по напечатанному листу и учётную запись
     * уже имеет, незачем.
     */
    $isStudentLogin = in_array($currentPath, ['/', '/student/login'], true);
    ?>
    <main class="auth-page">
        <div class="auth-panel">

            <div class="auth-brand">
                <a class="brand-icon" href="<?= $view->e($view->url('')) ?>" aria-hidden="true">
                    <?= $view->e(mb_substr((string) $appName, 0, 1)) ?>
                </a>
                <div class="brand-name"><?= $view->e($appName) ?></div>
            </div>

            <?php /*
            Выбор формы входа — вкладки шаблона gentelella в стиле Underlined,
            а не группа кнопок: это два адреса, и они должны читаться как
            разделы, между которыми переходят, а не как действие. Разметка и
            правила вкладок — в theme.css.
            */ ?>
            <nav aria-label="Выбор формы входа">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link<?= $isStudentLogin ? ' active' : '' ?>"
                           role="tab"
                           aria-selected="<?= $isStudentLogin ? 'true' : 'false' ?>"
                           href="<?= $view->e($view->url('')) ?>">Вход для ученика</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= $isStudentLogin ? '' : ' active' ?>"
                           role="tab"
                           aria-selected="<?= $isStudentLogin ? 'false' : 'true' ?>"
                           href="<?= $view->e($view->url('login')) ?>">Вход для учителя</a>
                    </li>
                </ul>
            </nav>

            <?php /*
             Результат действия — всплывающим сообщением, а не баннером.

             Баннер на всю страницу занимал место и оставался там, пока её не
             закрыли, хотя сообщение относилось к одному нажатию: человек уже
             видит, что нажал, и ему нужно знать только, чем это кончилось.
             Сообщение показывает скрипт toast.js, который читает эту метку при
             загрузке.
             */ ?>
            <?php if (!empty($notice)): ?>
                <span data-toast="<?= $view->e($notice) ?>" data-toast-variant="success" hidden></span>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <span data-toast="<?= $view->e($error) ?>" data-toast-variant="error" hidden></span>
            <?php endif; ?>

            <?= $view->raw($content) ?>
        </div>
    </main>
<?php endif; ?>

<footer class="footer footer--center">
    <span><?= $view->e($appName) ?></span>
<?php /* Версия и здесь: подвал один и тот же, а страницы ученика — тоже страницы системы. */ ?>
<?php if ($appVersion !== ''): ?>
    <span>Версия <?= $view->e($appVersion) ?></span>
<?php endif; ?>
</footer>

<?php /*
 Скрипт всплывающих сообщений нужен и здесь: оболочка отдаёт результат действия
 меткой, и без скрипта метка просто молча пропадёт.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/toast.js')) ?>" defer></script>

</body>
</html>