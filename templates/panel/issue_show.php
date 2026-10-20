<?php

declare(strict_types=1);

/**
 * Сводка по выдаче: кто что получил, что сделал и что ждёт проверки.
 *
 * Одна строка на ученика, а не одна карточка на попытку: человек открывает
 * сводку, чтобы увидеть «кого я ещё не посмотрел», и ответ должен помещаться
 * в один взгляд по вертикали, без прокрутки между блоками.
 *
 * «Ручная проверка» — значок со знаком действия, а не просто слово: ручной
 * разбор идёт из сводки, и ссылка на него должна читаться как ссылка.
 *
 * Ожидает:
 * $view, $issue, $rows, $finished, $pending
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $issue
 * @var list<array<string,mixed>> $rows
 * @var int $finished
 * @var int $pending
 */

$issueId = (int) $issue['id'];
$period = 'срок не задан';
if ($issue['available_from'] !== null || $issue['available_until'] !== null) {
 $from = $issue['available_from'] === null
 ? 'всегда'
 : date('d.m.Y', strtotime((string) $issue['available_from']));
 $until = $issue['available_until'] === null
 ? 'бессрочно'
 : date('d.m.Y', strtotime((string) $issue['available_until']));
 $period = 'от ' . $from . ' до ' . $until;
}


$view->crumbs([
        ['label' => 'Результаты', 'url' => 'panel/journal'],
        ['label' => $view->e($issue['assignment_title']), 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e($issue['assignment_title']) ?>

</h1>

<div class="card">
<ul class="facts">
<li class="facts__row">
<span class="facts__label">Выдал</span>
<span class="facts__value"><?= $view->e($issue['author_name']) ?></span>
</li>
<li class="facts__row">
<span class="facts__label">Кому</span>
<span class="facts__value">
<?= $view->e($view->count((int) (count($rows)),  'ученик', 'ученика', 'учеников')) ?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Выполнили</span>
<span class="facts__value"><?= $view->e($finished) ?></span>
</li>
<li class="facts__row">
<span class="facts__label">Срок</span>
<span class="facts__value"><?= $view->e($period) ?></span>
</li>
</ul>
</div>

<?php if ($rows === []): ?>
 <div class="card">
 <p class="muted">Ученики выданы, но попыток нет: работу ещё не начали.</p>
 </div>
<?php else: ?>
 <div class="card">
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Ученик</th>
 <th scope="col">Класс</th>
 <th scope="col">Результат</th>
 <th scope="col">Начал</th>
 <th scope="col">Закончил</th>
 <th scope="col">Длительность</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($rows as $row): ?>
 <?php
 $attemptId = (int) ($row['attempt_id'] ?? 0);
 $status = (string) ($row['attempt_status'] ?? '');
 $manual = (int) ($row['manual'] ?? 0) === 1;
 $needsReview = $manual && $status === 'finished';

 $duration = '—';

 if (!empty($row['started_at']) && !empty($row['finished_at'])) {
 $seconds = strtotime((string) $row['finished_at'])
 - strtotime((string) $row['started_at']);
 $duration = $seconds >= 60
 ? intdiv($seconds, 60) . ' мин'
 : max(1, $seconds) . ' с';
 } elseif (!empty($row['started_at'])) {
 $duration = 'идёт';
 }

 $result = '—';

 if ($status === 'finished') {
 if ($row['percent'] !== null) {
 $result = number_format((float) $row['percent'], 2, ',', ' ') . '%';
 } elseif ($row['score'] !== null) {
 $result = number_format((float) $row['score'], 2, ',', ' ')
 . ' ' . $view->plural(
 (int) $row['score'],
 'балл',
 'балла',
 'баллов'
 );
 } elseif ($row['grade'] !== null) {
 $result = (string) $row['grade'];
 }
 } elseif ($status === 'started') {
 $result = 'в работе';
 } elseif ($row['attempt_id'] === null) {
 $result = 'не начинал';
 }
 ?>
 <tr>
 <td><?= $view->e($row['full_name']) ?></td>
 <td><?= $view->e($row['class_name'] ?? '—') ?></td>
 <td><?= $view->e($result) ?></td>
 <td>
 <?= $view->e(!empty($row['started_at']) ? date('d.m.Y H:i', strtotime((string) $row['started_at'])) : '—') ?>
 </td>
 <td>
 <?= $view->e(!empty($row['finished_at']) ? date('d.m.Y H:i', strtotime((string) $row['finished_at'])) : '—') ?>
 </td>
 <td><?= $view->e($duration) ?></td>
 <td class="nowrap">
 <?php if ($needsReview && $attemptId > 0): ?>
 <?php $item = ['icon' => 'needs_review']; ?>
 <a class="btn btn-sm"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $issue['assignment_id']
 . '/attempts/' . $attemptId)) ?>"
 title="Ручная проверка: <?= $view->e($row['full_name']) ?>">
 <?php require __DIR__ . '/../_panel_icon.php'; ?>
 <span class="sr-only">Ручная проверка: <?= $view->e($row['full_name']) ?></span>
 </a>
 <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 </div>
<?php endif; ?>

<p class="page-actions">
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/journal')) ?>">
 ← Ко всем результатам
 </a>
</p>
