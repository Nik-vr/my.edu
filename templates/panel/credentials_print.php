<?php

declare(strict_types=1);

/**
 * Лист печати логинов и паролей.
 *
 * Отдельная страница без оболочки сайта: меню, шапка и подвал на бумаге
 * бесполезны и съедают половину листа. Стили — тоже свои, в
 * assets/css/print-credentials.css: общие правила печати прячут именно то,
 * что на листе нужно.
 *
 * Две колонки всегда, даже когда ученик один: карточка на всю ширину листа
 * выглядела бы иначе, чем та же карточка рядом со второй, а лист режут по
 * ученикам и хранят стопкой. Недостающая ячейка второй колонки остаётся
 * пустой — из-за неё лист всегда выглядит одинаково.
 *
 * Ожидает: $view, $class, $rows, $printedAt
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var list<array<string,mixed>> $rows
 * @var string $printedAt
 */

/*
 * Нечётное число карточек дополняется пустой ячейкой. Сетка из двух колонок
 * без неё положила бы последнюю карточку в левую половину и оставила правую
 * пустой сама по себе — но только при нечётном количестве, а при чётном
 * выглядело бы иначе. Лист должен выглядеть одинаково всегда.
 */
$oddCell = count($rows) % 2 === 1;
$manyCards = count($rows) > 1;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Логины и пароли — <?= $view->e($class['name']) ?></title>
    <link rel="stylesheet" href="<?= $view->e($view->asset('assets/css/print-credentials.css')) ?>">
</head>
<body>
<main class="sheet">
    <header class="sheet__head">
        <h1 class="sheet__title">Логины и пароли</h1>
        <p class="sheet__subtitle">
            <?= $view->e($class['name']) ?>
            <?php if (!empty($class['title'])): ?>
                · <?= $view->e($class['title']) ?>
            <?php endif; ?>
            · <?= $view->e($printedAt) ?>
        </p>
    </header>

    <p class="sheet__note">
        Передайте лист ученику. Вход — логин и пароль, оба указаны рядом с
        фамилией, там же адрес, по которому ученик входит. Если пароль утерян,
        его меняют: восстановить прежний нельзя.
    </p>

    <div class="sheet__grid<?= $manyCards ? ' sheet__grid--multi' : '' ?>">
        <?php foreach ($rows as $index => $row): ?>
            <div class="entry">
                <span class="entry__no"><?= $view->e($index + 1) ?></span>
                <div class="entry__body">
                    <p class="entry__name"><?= $view->e($row['full_name']) ?></p>
                    <dl class="entry__pair">
                        <dt>Логин</dt>
                        <dd><code><?= $view->e($row['login']) ?></code></dd>
                        <dt>Пароль</dt>
                        <dd><code><?= $view->e($row['password']) ?></code></dd>
                        <?php /*
                         * Адрес входа — под каждым учеником, а не один на весь
                         * лист: лист раздают по одному, и вырезанная часть
                         * остаётся рабочей. Ученик идёт по напечатанной бумаге,
                         * а не по адресной строке чужого браузера, и попадает в
                         * форму, где спрашивают только логин и пароль.
                         */ ?>
                        <dt>Адрес</dt>
                        <dd><code class="entry__url"><?= $view->e($loginUrl) ?></code></dd>
                    </dl>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($oddCell): ?>
            <?php /*
             * Пустая половина ряда. Разделители под ней не рисуются — ряд
             * последний, а линия под последним рядом на листе лишняя.
             */ ?>
            <div class="entry entry--blank" aria-hidden="true"></div>
        <?php endif; ?>
    </div>

    <footer class="sheet__foot">
        <?= $view->e($view->plural(count($rows), 'ученик', 'ученика', 'учеников')) ?>
        · этот лист содержит пароли для входа, передайте его лично
    </footer>
</main>
<?php /*
 * Лист сам уходит на печать: он открывается сразу тем ответом, которым пароль
 * выдан, и второе нажатие было бы лишним шагом, который забывают. Без
 * скриптов лист просто откроется — печать вызывается кнопкой и сочетанием
 * клавиш.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/print-sheet.js')) ?>" defer></script>
</body>
</html>