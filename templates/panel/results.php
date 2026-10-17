<?php

declare(strict_types=1);

/**
 * Просмотр результатов: список выдач.
 *
 * Раздел заменил «Ждут проверки». Раньше там стоял список попыток, которые
 * ждут оценки: попадало ровно то, что уже сделано и не проверено, а выданное,
 * выполненное и проверенное было не видно вообще. Теперь список выдач, и по
 * каждой — сводка по всем, кому работа досталась.
 *
 * Сортировка по дате выдачи, а не по названию работы: сюда приходят с вопросом
 * «кто что уже сдал», и ответ — во времени, а не в алфавите.
 *
 * Ожидает:
 *   $view, $issues
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $issues
 */

use MyEdu\Repository\WorkTree;
$pendingTotal = 0;
foreach ($issues as $issue) {
    $pendingTotal += (int) $issue['pending_review_count'];
}


$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Просмотр результатов', 'url' => ''],
    ]);
?>

<h1 class="page-title">Просмотр результатов</h1>

<div class="card">
    <p class="page-actions">
        <a class="btn btn-primary" href="<?= $view->e($view->url('panel/issues/new')) ?>

">
            Выдать работу
        </a>
    </p>
    <?php if ($pendingTotal > 0): ?>
        <p class="muted">
            Требуют проверки: <?= $view->e($pendingTotal) ?>
            <?= $view->e($view->plural($pendingTotal, 'работа', 'работы', 'работ')) ?>.
            Значок стоит у выдачи — по нему видно, какую открыть.
        </p>
    <?php endif; ?>
</div>

<?php if ($issues === []): ?>
    <div class="card">
        <p class="muted">Выдач пока нет. Работа попадает сюда, когда её кому-то выдали.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table" data-datatable data-page-length="25">
            <thead>
                <tr>
                    <th scope="col">Работа</th>
                    <th scope="col">Выдал</th>
                    <th scope="col">Выдано</th>
                    <th scope="col">Выполнили</th>
                    <th scope="col">Ждут проверки</th>
                    <th scope="col">Когда выдана</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($issues as $issue): ?>
                <?php
                $issueId = (int) $issue['id'];
                $issued = (int) $issue['issued_count'];
                $finished = (int) $issue['finished_count'];
                $pending = (int) $issue['pending_review_count'];
                $parallel = $issue['parallel'] === null ? null : (int) $issue['parallel'];
                ?>
                <tr>
                    <td>
                        <a href="<?= $view->e($view->url('panel/issues/' . $issueId)) ?>">
                            <?= $view->e($issue['assignment_title']) ?>
                        </a>
                        <?php if ($parallel !== null): ?>
                            <span class="chip"><?= $view->e(WorkTree::label($parallel)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $view->e($issue['author_name']) ?></td>
                    <td><?= $view->e($issued) ?></td>
                    <td>
                        <?= $view->e($finished) ?>
                        <?php if ($issued > 0): ?>
                            <span class="muted">из <?= $view->e($issued) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pending > 0): ?>
                            <?php require __DIR__ . '/_needs_review_badge.php'; ?>
                            <span class="muted"><?= $view->e($pending) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $view->e(date('d.m.Y H:i', strtotime((string) $issue['created_at']))) ?></td>
                    <td class="nowrap">
                        <a class="btn btn-sm" href="<?= $view->e($view->url('panel/issues/' . $issueId)) ?>">
                            Сводка
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
