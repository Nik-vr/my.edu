<?php

declare(strict_types=1);

/**
 * Попытки учеников по работе.
 *
 * Отдельным файлом: таблица показывается в двух местах — последней вкладкой
 * редактора и на странице работы для тех, кому она доступна на чтение.
 *
 * Ожидает:
 * $view, $assignment, $attempts, $isAuthor
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $attempts
 * @var bool $isAuthor
 */

$assignmentId = (int) $assignment['id'];
?>

<div class="card">
 <h2 class="card-title">Попытки учеников</h2>

 <?php if ($attempts === []): ?>
 <p class="muted">Попыток пока нет.</p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Ученик</th>
 <th scope="col">Попытка</th>
 <th scope="col">Состояние</th>
 <th scope="col">Балл</th>
 <th scope="col">Оценка</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($attempts as $attempt): ?>
 <tr>
 <td><?= $view->e($attempt['full_name']) ?></td>
 <td><?= $view->e((int) $attempt['attempt_no']) ?></td>
 <td>
 <?php if ((int) $attempt['manual'] === 1): ?>
 <span class="status status-yellow">ждёт проверки</span>
 <?php elseif ($attempt['status'] === 'finished'): ?>
 <span class="status status-green">завершена</span>
 <?php else: ?>
 <span class="chip">в процессе</span>
 <?php endif; ?>
 </td>
 <td>
 <?php if ($attempt['score'] === null): ?>
 <span class="muted">—</span>
 <?php else: ?>
 <?= $view->e(rtrim(rtrim(number_format((float) $attempt['score'], 2, ',', ''), '0'), ',')) ?>
 /
 <?= $view->e(rtrim(rtrim(number_format((float) $attempt['max_score'], 2, ',', ''), '0'), ',')) ?>
 <?php endif; ?>
 </td>
 <td><?= $view->e($attempt['grade'] ?? '—') ?></td>
 <td class="nowrap">
 <?php if ($attempt['status'] !== 'finished'): ?>
 <span class="muted">—</span>
 <?php elseif ($isAuthor): ?>
 <?php /*
 Проверка остаётся автору работы: она ставит
 оценки и пишет комментарии, то есть меняет
 чужой результат. Заведующий чужую работу
 посмотреть может, а проверять её за автора —
 нет. Поэтому у чужой работы ссылка на
 проверку не показывается вовсе.
 */ ?>
 <a class="btn btn-sm"
  href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/attempts/' . (int) $attempt['id'])) ?>">
 <?= (int) $attempt['manual'] === 1 ? 'Проверить' : 'Открыть' ?>
 </a>
 <?php else: ?>
 <span class="muted">—</span>
 <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>
