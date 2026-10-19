<?php

declare(strict_types=1);

/**
 * Экран самой работы: список позиций с прогрессом.
 *
 * Название позиции показывается таким, каким оно было при добавлении: если
 * автор переименует задание после выдачи, список у ученика не должен
 * меняться задним числом.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $items
 * @var array<string,mixed>|null $attempt
 * @var int|null $attemptsLeft
 * @var bool $mayRepeat сотруднику можно проходить работу повторно
 */
$started = $attempt !== null && $attempt['status'] === 'in_progress';
$token = $attempt === null ? '' : (string) $attempt['token'];
?>
<div class="page-header">
 <h1 class="page-title"><?= $view->e($assignment['title']) ?></h1>
 <p class="muted">
<?= $view->e($view->count((int) ($totalItems),  'задание', 'задания', 'заданий')) ?>

 <?php if (!empty($assignment['subject_name'])): ?>
 · <?= $view->e($assignment['subject_name']) ?>
 <?php endif; ?>

 <?php if (!empty($assignment['time_limit'])): ?>
 · не более <?= $view->e((int) $assignment['time_limit']) ?> мин
 <?php endif; ?>

 <?php if ($attemptsLeft !== null): ?>
 <?php if ($attemptsLeft === 0): ?>
 · попыток не осталось
 <?php else: ?>
 · <?= $view->e($view->plural(
 $attemptsLeft,
 'осталась попытка',
 'осталось попытки',
 'осталось попыток'
 )) ?>
 <?php endif; ?>
 <?php endif; ?>
 </p>
</div>

<?php if (!empty($assignment['description'])): ?>
 <div class="card">
 <p><?= $view->e($assignment['description']) ?></p>
 </div>
<?php endif; ?>

<div class="card">
 <h2 class="card-title">
 Задания
 <?php if ($attempt !== null): ?>
 <span class="muted">выполнено <?= $view->e($doneCount) ?> из <?= $view->e($totalItems) ?></span>
 <?php endif; ?>
 </h2>

 <?php if ($items === []): ?>
 <p class="muted">В работе нет заданий. Обратитесь к учителю.</p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">№</th>
 <th scope="col">Задание</th>
 <th scope="col">Вес</th>
 <th scope="col">Состояние</th>
 <?php if ($started && (int) $assignment['show_results'] === 1): ?>
 <th scope="col">Балл</th>
 <?php endif; ?>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($items as $item): ?>
 <tr>
 <td><?= $view->e((int) $item['position']) ?></td>
 <td>
 <strong><?= $view->e($item['title']) ?></strong>
 <?php if (!$item['available']): ?>
 <br><span class="status status-red">сейчас недоступно</span>
 <?php endif; ?>
 </td>
 <td><?= $view->e($item['weight']) ?></td>
 <td>
 <?php if ($item['done']): ?>
 <span class="status status-green">выполнено</span>
 <?php else: ?>
 <span class="muted">не выполнено</span>
 <?php endif; ?>
 </td>
 <?php if ($started && (int) $assignment['show_results'] === 1): ?>
 <td>
 <?php if ($item['percent'] === null): ?>
 <span class="muted">—</span>
 <?php else: ?>
 <?= $view->e($item['score']) ?> из <?= $view->e($item['max_score']) ?>
 <?php endif; ?>
 </td>
 <?php endif; ?>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>

 <hr class="sep">

 <div class="page-actions">
 <?php if ($attempt === null): ?>
 <form method="post"
 action="<?= $view->e($view->url('work/' . (int) $assignment['id'] . '/start')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-primary btn-lg">Начать работу</button>
 </form>
 <?php elseif ($started): ?>
 <?php $first = $items[0] ?? null; ?>
 <?php if ($first !== null && !empty($first['available'])): ?>
 <a class="btn btn-primary btn-lg"
 href="<?= $view->e($view->url('work/' . (int) $attempt['id'] . '/item/' . (int) $first['id'] . '?t=' . $token)) ?>">
 Продолжить
 </a>
 <?php endif; ?>

 <form method="post"
 action="<?= $view->e($view->url('work/' . (int) $attempt['id'] . '/finish')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="t" value="<?= $view->e($token) ?>">
 <button type="submit" class="btn">Завершить и сдать</button>
 </form>
 <?php else: ?>
 <a class="btn btn-primary btn-lg"
 href="<?= $view->e($view->url('work/' . (int) $attempt['id'] . '/result?t=' . $token)) ?>">
 Результат
 </a>

 <?php /*
 * Кнопка «пройти заново» — не только сотрудникам.
 *
 * Сервер повтор разрешает всем, у кого попытки ещё остались:
 * ограничение числа попыток проверяется в startAttempt(), а не в
 * этой кнопке. Раньше кнопка была только у сотрудника, и ученику
 * после сдачи оставалась одна ссылка «Результат» — пройти работу
 * второй раз было нечем, хотя автор мог разрешить три попытки.
 * Человек ставил ограничение в два-три раза и получал ровно одну:
 * интерфейс молчал, а сервер повтор разрешал.
 *
 * Сотруднику кнопка доступна всегда: лимит попыток к нему не
 * применяется, и после правки задания он проверяет её на себя.
 */ ?>
 <?php $canRepeat = $mayRepeat || $attemptsLeft === null || $attemptsLeft > 0; ?>
 <?php if ($canRepeat): ?>
 <form method="post"
 action="<?= $view->e($view->url('work/' . (int) $assignment['id'] . '/start')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-lg">Пройти заново</button>
 </form>
 <?php endif; ?>
 <?php endif; ?>
 </div>

 <?php if ($attempt !== null && $started): ?>
 <p class="muted">
 Попытка №<?= $view->e((int) $attempt['attempt_no']) ?>.
 Ответы сохраняются при каждом переходе, поэтому страницу можно
 обновить и вернуться позже.
 </p>
 <?php endif; ?>
</div>
