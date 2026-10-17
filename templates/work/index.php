<?php

declare(strict_types=1);

/**
 * Список опубликованных работ.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $rows
 */
?>
<div class="page-header">
 <h1 class="page-title">Работы</h1>
 <p class="muted">Здесь собраны работы, которые выдал учитель.</p>
</div>

<?php if ($rows === []): ?>
 <div class="card">
 <h2>Работ пока нет</h2>
 <p class="muted">
 Как только учитель опубликует работу, она появится здесь.
 </p>
 </div>
<?php else: ?>
 <ul class="test-list">
 <?php foreach ($rows as $row): ?>
 <li class="test-card">
 <div class="test-card__body">
 <h2 class="test-card__title">
 <a href="<?= $view->e($view->url('work/' . (int) $row['id'])) ?>">
 <?= $view->e($row['title']) ?>
 </a>
 </h2>

 <?php if (!empty($row['description'])): ?>
 <p class="test-card__desc"><?= $view->e($row['description']) ?></p>
 <?php endif; ?>

 <ul class="test-card__meta">
 <?php if (!empty($row['subject_name'])): ?>
 <li><?= $view->e($row['subject_name']) ?></li>
 <?php endif; ?>
 <li>
 <?= $view->e((int) $row['item_count']) ?>
 <?= $view->e($view->plural((int) $row['item_count'], 'задание', 'задания', 'заданий')) ?>
 </li>
 <?php if (!empty($row['time_limit'])): ?>
 <li><?= $view->e((int) $row['time_limit']) ?> мин</li>
 <?php endif; ?>
 <?php if (($row['my_status'] ?? null) === 'finished'): ?>
 <li class="chip">
 сдано, <?= $view->e($row['my_percent'] === null ? '—' : $row['my_percent'] . '%') ?>
 </li>
 <?php elseif (($row['my_status'] ?? null) === 'in_progress'): ?>
 <li class="status status-yellow">в процессе</li>
 <?php endif; ?>
 </ul>
 </div>

 <a class="btn btn-primary" href="<?= $view->e($view->url('work/' . (int) $row['id'])) ?>">
 <?= ($row['my_status'] ?? null) === 'in_progress' ? 'Продолжить' : 'Открыть' ?>
 </a>
 </li>
 <?php endforeach; ?>
 </ul>
<?php endif; ?>
