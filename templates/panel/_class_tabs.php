<?php

declare(strict_types=1);

/**
 * Переключатель вкладок редактора класса.
 *
 * Отдельный файл, потому что рисуется в двух шаблонах — на вкладке класса и
 * на вкладке предметов с учителями. Две копии списка вкладок разошлись бы
 * при первой же правке: забыли бы поменять адрес у одной из них, и человек
 * оказался бы в другом классе.
 *
 * Ожидает: $view, $class, $tab
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var string $tab текущая вкладка
 */
$classId = (int) $class['id'];

$tabs = [
    'class'  => 'Класс и ученики',
    'roster' => 'Предметы и учителя',
];
/*
 * Разметка — вкладки шаблона gentelola в стиле Underlined (`nav nav-tabs`).
 *
 * Это ссылки между разделами одной страницы, а не кнопки: у вкладки есть
 * адрес, и без скриптов раздел открывается обычным переходом. Собственных
 * классов ради этого в проекте нет — правила вкладок описаны в theme.css
 * вместе с остальным, чего не хватает сборке шаблона.
 */
?>
<nav aria-label="Разделы класса">
    <ul class="nav nav-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <?php
            $isActive = $tab === $key;
            $href = $isActive
                ? $view->url('classes/' . $classId)
                : $view->url('classes/' . $classId . '?tab=' . $key);
            ?>
            <li class="nav-item">
                <a class="nav-link<?= $isActive ? ' active' : '' ?>"
                   href="<?= $view->e($href) ?>"
                   <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <?= $view->e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>