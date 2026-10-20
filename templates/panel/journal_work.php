<?php

declare(strict_types=1);

/**
 * Результаты одной работы: вкладки классов и результаты учеников.
 *
 * Вкладка — класс, которому работу выдали. На вкладке — таблица: ученик, его
 * задания цветными квадратами, оценка и сводка баллов.
 *
 * Квадрат с номером задания показывает результат по этому заданию: зелёный —
 * полный балл, оранжевый — балл меньше максимального, красный — ноль или
 * минус, серый — задание не выполнялось. Наведение показывает ответ ученика.
 *
 * Задания идут в порядке редактора работы, даже если они выдавались в
 * перемешанном порядке: учителю нужен порядок самой работы.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $classes
 * @var array<string,mixed> $current
 * @var list<array<string,mixed>> $items
 * @var list<array<string,mixed>> $rows
 */

$format = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Результаты', 'url' => 'panel/journal'],
        ['label' => (string) $assignment['title'], 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e((string) $assignment['title']) ?></h1>

<p class="muted">
    Результаты по классам, которым работа выдавалась. Номер задания — его
    номер в редакторе работы.
</p>

<nav aria-label="Классы работы">
    <ul class="nav nav-tabs">
    <?php foreach ($classes as $class): ?>
        <li class="nav-item">
            <a class="nav-link<?= (int) $class['id'] === (int) $current['id'] ? ' active' : '' ?>"
            <?= (int) $class['id'] === (int) $current['id'] ? 'aria-current="page"' : '' ?>
            href="<?= $view->e($view->url(
                'panel/journal/work/' . (int) $assignment['id'] . '?class=' . (int) $class['id']
            )) ?>">
            <?= $view->e((string) $class['name']) ?>
            <span class="chip"><?= $view->e((int) $class['student_count']) ?></span>
            </a>
        </li>
    <?php endforeach; ?>
    </ul>
</nav>

<div class="card">
    <h2 class="card-title"><?= $view->e((string) $current['name']) ?></h2>

    <?php if ($rows === []): ?>
    <p class="muted">В этом классе работу никто не получал.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table journal-table">
        <thead>
        <tr>
            <th scope="col">Ученик</th>
            <th scope="col">Задания</th>
            <th scope="col">Отметка</th>
            <th scope="col">Баллы</th>
            <th scope="col">Процент</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <?php $student = $row['student']; ?>
        <tr>
            <td class="nowrap">
                <?= $view->e((string) $student['full_name']) ?>
                <?php if ($row['late']): ?>
                <?php /*
                 Значок опоздания: работа сдана после срока выдачи.

                 Секундомер читается мгновенно, в отличие от подписи: в таблице
                 с двадцатью учениками слова заняли бы больше места, чем сами
                 баллы. Подсказка под значком остаётся — наводится он мышью.
                */ ?>
                <span class="journal-late" title="Работа сдана после срока" aria-label="Работа сдана после срока">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="10" cy="11" r="6.2"></circle>
                        <path d="M10 8.2V11l2 1.4"></path>
                        <path d="M7.8 2.6h4.4"></path>
                        <path d="M8.6 1h2.8"></path>
                    </svg>
                </span>
                <?php endif; ?>
            </td>
            <td class="journal-cells">
            <?php foreach ($row['cells'] as $cell): ?>
                <?php
                $hint = [];

                if ($cell['answer'] !== null) {
                    $hint[] = 'Ответ: ' . $cell['answer'];
                }

                if ($cell['max'] !== null && $cell['max'] > 0.0) {
                    $hint[] = 'Балл: ' . $format((float) $cell['score']);
                }

                if (($cell['penalty'] ?? 0.0) > 0.0) {
                    $hint[] = 'Штраф: ' . $format((float) $cell['penalty']);
                }

                if ($cell['comment'] !== '') {
                    $hint[] = 'Комментарий: ' . $cell['comment'];
                }
                ?>
                <span class="journal-cell journal-cell--<?= $view->e($cell['tone']) ?>"
                title="<?= $view->e(implode(' · ', $hint)) ?>">
                <?= $view->e((int) $cell['position']) ?>
                </span>
            <?php endforeach; ?>
            </td>
            <td>
            <?php if ($row['grade'] === null): ?>
                <span class="muted">—</span>
            <?php else: ?>
                <?= $view->e((int) $row['grade']) ?>
            <?php endif; ?>
            </td>
            <td class="nowrap">
            <?php if ($row['score'] === null): ?>
                <span class="muted">—</span>
            <?php else: ?>
                <?= $view->e($format((float) $row['score'])) ?> из
                <?= $view->e($format((float) $row['max'])) ?>
                <?php if ($row['penalty'] > 0.0): ?>
                <span class="journal-penalty" title="Штраф: <?= $view->e($format((float) $row['penalty'])) ?>">
                    штраф <?= $view->e($format((float) $row['penalty'])) ?>
                </span>
                , итого <?= $view->e($format((float) $row['net'])) ?>
                <?php endif; ?>
            <?php endif; ?>
            </td>
            <td class="nowrap">
            <?php if ($row['percent'] === null): ?>
                <span class="muted">—</span>
            <?php else: ?>
                <?= $view->e($format((float) $row['percent'])) ?>%
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
