<?php

declare(strict_types=1);

/**
 * Экран работы: что это за работа, сколько в ней заданий и кнопки входа.
 *
 * Список позиций на этом экране скрыт по решению владельца от 24.10.2026:
 * ученик видит состав работы только в редакторе у автора, а здесь — счётчик,
 * описание, прогресс и кнопки. Изменения после выдачи на счётчик не влияют:
 * число заданий и название берутся из самой работы, а не из снимка на момент
 * выдачи.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $items позиции: нужны для кнопки «Продолжить»
 * @var array<string,mixed>|null $attempt
 * @var int $totalItems
 * @var int $doneCount
 * @var int|null $attemptsLeft
 * @var bool $mayRepeat сотруднику можно проходить работу повторно
 */
$started = $attempt !== null && $attempt['status'] === 'in_progress';
$token = $attempt === null ? '' : (string) $attempt['token'];

// Хлебные крошки для шапки панели
$view->crumbs([
    ['label' => 'Работы', 'url' => 'works'],
    ['label' => $assignment['title'], 'url' => ''],
]);

/*
 * Шапка страницы: заголовок и подпись под ним.
 *
 * Счётчик набран в виде «Всего N заданий» — так решено владельцем: слово
 * «Всего» спереди читается как обещание обзора всей работы, а не как
 * отметка прогресса текущей попытки (для неё ниже свой счётчик «выполнено»).
 *
 * Предмет, лимит времени и остаток попыток остаются в той же строке: все три
 * отвечают на вопросы, которые ученик задаёт перед нажатием «Начать»,
 * и убирать их в отдельные блоки незачем — строка и так короткая.
 */
$counter = 'Всего ' . $view->count((int) $totalItems, 'задание', 'задания', 'заданий');
?>
<div class="page-header">
 <h1 class="page-title"><?= $view->e($assignment['title']) ?></h1>
 <p class="muted">
 <?= $view->e($counter) ?>

 <?php /*
  Прогресс переехал сюда из таблицы заданий, которую на этом экране больше
  нет: подпись «выполнено» отвечала на вопрос «сколько я прошёл», не
  показывая состав работы.
  */ ?>
 <?php if ($attempt !== null): ?>
 · выполнено <?= $view->e($doneCount) ?> из <?= $view->e($totalItems) ?>
 <?php endif; ?>

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

<?php /*
 * Экран прохождения: список заданий скрыт — решение владельца от 24.10.2026.
 *
 * Раньше здесь была таблица позиций (номер, название, вес, состояние, балл).
 * Во время работы она отвлекала и позволяла заглянуть в состав, пока
 * работа не начата; прогресс отдельной подписью переехал в шапку страницы
 * («выполнено X из Y»), а кнопки остались. Сам список достижим только
 * там, где он и нужен, — в редакторе работы у автора.
 *
 * Данные позиций ($items) шаблону по-прежнему нужны: по первой позиции
 * строится кнопка «Продолжить» ниже.
 */ ?>

<div class="card">
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
