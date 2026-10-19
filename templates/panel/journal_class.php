<?php

declare(strict_types=1);

/**
 * Журнал одного класса: каждый ученик и его последние попытки по работам.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var list<array<string,mixed>> $students
 * @var array<string,mixed> $total
 */

$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
$statusBadge = static function (array $attempt): string {
 if ((int) $attempt['manual'] === 1) {
 return '<span class="status status-yellow">ждёт проверки</span>';
 }
 if ($attempt['status'] === 'finished') {
 return '<span class="status status-green">сдано</span>';
 }
 return '<span class="chip">в процессе</span>';
};


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Журнал работ', 'url' => 'panel/journal'],
        ['label' => $view->e($class['name']), 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Журнал: <?= $view->e($class['name']) ?>

</h1>
 <p class="muted">
<?= $view->e($view->count((int) ($total['students']),  'ученик', 'ученика', 'учеников')) ?>
 <?php if (!empty($class['title'])): ?>
 · <?= $view->e($class['title']) ?>
 <?php endif; ?>
 </p>
</div>

<div class="metric-row">
 <div class="metric">
 <span class="metric-value"><?= $view->e($total['finished']) ?></span>
 <span class="metric-label">работ сдано</span>
 </div>

 <div class="metric">
 <span class="metric-value">
 <?= $view->e($total['avg_percent'] === null ? '—' : $total['avg_percent'] . '%') ?>
 </span>
 <span class="metric-label">средний процент</span>
 </div>

 <div class="metric">
 <span class="metric-value"><?= $view->e($total['avg_grade'] === null ? '—' : $total['avg_grade']) ?></span>
 <span class="metric-label">средняя оценка</span>
 </div>

 <?php if ($total['review'] > 0): ?>
 <a class="metric metric--warn" href="<?= $view->e($view->url('panel/results')) ?>">
 <span class="metric-value"><?= $view->e($total['review']) ?></span>
 <span class="metric-label">ждут проверки</span>
 </a>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">Ученики</h2>

 <?php if ($students === []): ?>
 <p class="muted">
 В классе нет учеников.
 <a href="<?= $view->e($view->url('classes/' . (int) $class['id'] . '/students')) ?>">Завести</a>.
 </p>
 <?php else: ?>
 <?php foreach ($students as $student): ?>
 <section class="card card--review">
 <header class="card-header">
 <h3 class="card-title"><?= $view->e($student['full_name']) ?></h3>

 <?php if ((int) $student['attempt_count'] === 0): ?>
 <span class="chip">работ не выполнял</span>
 <?php else: ?>
 <span class="muted">
 <?= $view->e((int) $student['finished_count']) ?>
 из <?= $view->e((int) $student['attempt_count']) ?>
 сдано
 </span>
 <?php endif; ?>
 </header>

 <?php if ($student['attempts'] !== []): ?>
 <div class="table-responsive">
 <table class="table" data-datatable data-page-length="25">
 <thead>
 <tr>
 <th scope="col">Работа</th>
 <th scope="col">Попытка</th>
 <th scope="col">Состояние</th>
 <th scope="col">Баллы</th>
 <th scope="col">Процент</th>
 <th scope="col">Оценка</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($student['attempts'] as $attempt): ?>
 <tr>
 <td><?= $view->e($attempt['assignment_title']) ?></td>
 <td><?= $view->e((int) $attempt['attempt_no']) ?></td>
 <td><?= $view->raw($statusBadge($attempt)) ?></td>
 <td>
 <?php if ($attempt['score'] === null): ?>
 <span class="muted">—</span>
 <?php else: ?>
 <?= $view->e($formatScore((float) $attempt['score'])) ?>
 из <?= $view->e($formatScore((float) $attempt['max_score'])) ?>
 <?php endif; ?>
 </td>
 <td>
 <?= $view->e(
 $attempt['percent'] === null
 ? '—'
 : $attempt['percent'] . '%'
 ) ?>
 </td>
 <td><?= $view->e($attempt['grade'] ?? '—') ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php else: ?>
 <p class="muted">Ни одной работы.</p>
 <?php endif; ?>
 </section>
 <?php endforeach; ?>
 <?php endif; ?>

  <?php if ($total['without_work'] > 0): ?>
  <?= $view->raw($view->bannerWarning(
      $total['without_work'] . ' '
      . $view->plural($total['without_work'], 'ученик не', 'ученика не', 'учеников не')
      . ' выполнил ни одной работы.'
  )) ?>
 <?php endif; ?>
</div>

<p class="muted">
 Средние считаются по ученикам, а не по попыткам: один ученик с несколькими
 пересдачами не должен весить в среднем больше остальных.
</p>
