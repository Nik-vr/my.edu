<?php

declare(strict_types=1);

/**
 * Проверка работы учителем.
 *
 * Балл и комментарий ставятся только по позициям, ждущим проверки: всё, что
 * плагин проверил сам, здесь не редактируется, иначе учитель мог бы случайно
 * сбить автопроверку.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var array<string,mixed> $attempt
 * @var list<array<string,mixed>> $items
 * @var \MyEdu\Grading\AssignmentTotal $total
 */
$pending = array_values(array_filter(
 $items,
 static fn (array $item): bool => $item['needs_review']
));


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => $view->e($assignment['title']), 'url' => 'panel/assignments/' . (int) $assignment['id']],
        ['label' => 'Проверка', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Проверка работы</h1>
 <p class="muted">
 <?= $view->e($assignment['title']) ?> ·
 <?= $view->e($attempt['full_name'] ?? 'ученик') ?> ·
 попытка <?= $view->e((int) $attempt['attempt_no']) ?>
 </p>
</div>

<?php if ($pending === []): ?>
 <div class="card">
 <p>Все задания этой работы проверены автоматически.</p>

 <p class="muted">
 Текущий итог:
 <?= $view->e($total->percent === null ? '—' : $total->percent . '%') ?>,
 оценка <?= $view->e($total->grade ?? '—') ?>.
 </p>

 <p>
 <a class="btn"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'])) ?>">
 К работе
 </a>
 </p>
 </div>
<?php else: ?>
 <form method="post"
 action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/attempts/' . (int) $attempt['id'])) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <div class="card">
 <h2 class="card-title">Задания, ждущие проверки</h2>

 <?php foreach ($pending as $item): ?>
 <div class="card card--review">
 <h3 class="card-title">
 <?= $view->e((int) $item['position']) ?>.
 <?= $view->e($item['title']) ?>
 <span class="muted">вес <?= $view->e($item['weight']) ?></span>
 </h3>

  <?php if ($item['error'] !== null): ?>
  <?= $view->raw($view->bannerError('Не удалось показать ответ: ' . $item['error'])) ?>
 <?php endif; ?>

 <div class="answer-box">
 <?php if ($item['answer'] !== null): ?>
 <?= $view->raw($item['answer']->body()) ?>
 <?php else: ?>
 <p class="muted">Плагин не показал ответ: возможно, он не сохраняется.</p>
 <?php endif; ?>
 </div>

 <div class="page-actions">
 <label class="form-group">
 <span class="form-label">Балл</span>
 <?php /*
  * Максимум подставляется в поле балла: сервер молча срезает балл до максимума,
  * и без подсказки учитель узнавал бы об этом только по итогу в журнале.
  */ ?>
 <input type="number" name="score[<?= $view->e((int) $item['id']) ?>]"
 min="0" step="0.01" size="6"
 <?php if ((float) ($item['max_score'] ?? 0) > 0): ?>
 max="<?= $view->e((string) (float) $item['max_score']) ?>"
 data-hint="Балл не может быть больше <?= $view->e((string) (float) $item['max_score']) ?>"
 <?php endif; ?>
 value="<?= $view->e($item['score'] ?? '') ?>">
 </label>

 <label class="form-group">
 <span class="form-label">Максимум</span>
 <input type="number" name="max_score[<?= $view->e((int) $item['id']) ?>]"
 min="0" step="0.01" size="6"
 value="<?= $view->e($item['max_score'] ?? '') ?>">
 </label>

 <label class="form-group">
 <span class="form-label">Отметка</span>
 <select name="mark[<?= $view->e((int) $item['id']) ?>]">
 <option value="correct">верно</option>
 <option value="partial">частично</option>
 <option value="wrong">неверно</option>
 <option value="skipped">не выполнялось</option>
 </select>
 </label>
 </div>

 <label class="form-group">
 <span class="form-label">Комментарий ученику</span>
 <textarea name="comment[<?= $view->e((int) $item['id']) ?>]" rows="2"><?= $view->e($item['comment']) ?></textarea>
 </label>
 </div>
 <?php endforeach; ?>

 <button type="submit" class="btn btn-primary btn-lg">
 Сохранить проверку и пересчитать итог
 </button>
 </div>
 </form>

 <div class="card">
 <h2 class="card-title">Уже проверено автоматически</h2>

 <ul class="issue-list">
 <?php foreach ($items as $item): ?>
 <?php if ($item['needs_review']) { continue; } ?>
 <li>
 <?= $view->e((int) $item['position']) ?>. <?= $view->e($item['title']) ?> —
 <?= $view->e($item['score'] ?? '—') ?> из
 <?= $view->e($item['max_score'] ?? '—') ?>
 </li>
 <?php endforeach; ?>
 </ul>
 </div>
<?php endif; ?>
