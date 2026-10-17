<?php

declare(strict_types=1);

/**
 * Журнал работ: по одному списку на класс.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $rows
 */



$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Журнал работ', 'url' => ''],
    ]);
?>

<h1 class="page-title">Журнал работ

</h1>

<div class="card">
    <p class="card-subtitle">
        Что сдали ученики и с каким результатом. Здесь учитель видит работу
        глазами ученика: сколько заданий, сколько баллов и что осталось
        на проверку.
    </p>
</div>

<div class="card">
    <h2 class="card-title">Классы</h2>

    <?php if ($rows === []):
?>

<p class="muted">
            Классов пока нет. Создайте класс и заведите учеников — журнал
            появится здесь сам.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" data-datatable data-page-length="25">
                <thead>
                    <tr>
                        <th scope="col">Класс</th>
                        <th scope="col">Учеников</th>
                        <th scope="col">Попыток</th>
                        <th scope="col">Сдано</th>
                        <th scope="col">Ждут проверки</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <strong><?= $view->e($row['name']) ?></strong>
                                <?php if (!empty($row['title'])): ?>
                                    <br><span class="muted"><?= $view->e($row['title']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $view->e((int) $row['student_count']) ?></td>
                            <td><?= $view->e((int) $row['attempt_count']) ?></td>
                            <td><?= $view->e((int) $row['finished_count']) ?></td>
                            <td>
                                <?php if ((int) $row['review_count'] > 0): ?>
                                    <span class="status status-yellow">
                                        <?= $view->e((int) $row['review_count']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-sm"
                                   href="<?= $view->e($view->url('panel/journal/' . (int) $row['id'])) ?>">
                                    Журнал
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="muted">
        Работы пока не закреплены за классами, поэтому в журнал попадают все
        опубликованные работы, которые ученики этого класса выполняли.
    </p>
</div>
