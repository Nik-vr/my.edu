<?php

declare(strict_types=1);

/**
 * Выдачи работы: кому и когда отдана.
 *
 * Отдельным файлом: таблица показывается в двух местах — последней вкладкой
 * редактора и на странице работы для тех, кому она доступна на чтение.
 *
 * Ожидает:
 * $view, $assignment, $issues
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $issues
 */

$assignmentId = (int) $assignment['id'];
?>

<div class="card">
 <div class="card-header">
 <h2 class="card-title">Выдачи</h2>
 </div>

 <?php if ($issues === []): ?>
 <p class="muted">
 Работа опубликована, но ещё никому не выдана. Сейчас её не видит ни один ученик.
 </p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Выдана</th>
 <th scope="col">Доступна</th>
 <th scope="col">Учеников</th>
 <th scope="col">Выполнили</th>
 <th scope="col">Ждут проверки</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($issues as $issue): ?>
 <tr>
 <td><?= $view->e($issue['created_at']) ?></td>
 <td>
 <?php if ($issue['available_from'] === null && $issue['available_until'] === null): ?>
 всегда
 <?php else: ?>
 <?= $view->e((string) ($issue['available_from'] ?? 'сейчас')) ?>
 —
 <?= $view->e((string) ($issue['available_until'] ?? 'бессрочно')) ?>
 <?php endif; ?>
 </td>
 <td><?= $view->e((int) $issue['issued_count']) ?></td>
 <td><?= $view->e((int) $issue['finished_count']) ?></td>
 <td class="nowrap">
 <?php if ((int) $issue['pending_review_count'] > 0): ?>
 <?php require __DIR__ . '/_needs_review_badge.php'; ?>
 <?php endif; ?>
 </td>
 <td class="row-actions">
 <a class="btn btn-sm" href="<?= $view->e($view->url('panel/issues/' . (int) $issue['id'])) ?>">
 Открыть
 </a>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>
