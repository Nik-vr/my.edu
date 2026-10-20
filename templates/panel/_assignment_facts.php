<?php

declare(strict_types=1);

/**
 * Сведения о работе: блок «Как собрана работа».
 *
 * Отдельным файлом, потому что показывается в двух местах: последней вкладкой
 * редактора и на странице самой работы для тех, кому она доступна на чтение.
 * Списком свойств он один и тот же, а копия разошлась бы при первой правке.
 *
 * Ожидает:
 * $view, $assignment, $subjectName, $parallel, $issues
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var string|null $subjectName
 * @var int|null $parallel
 * @var list<array<string,mixed>> $issues
 */

use MyEdu\Repository\WorkTree;

$parallel = $parallel ?? WorkTree::parallelOf($assignment);
?>

<div class="card">
 <h2 class="card-title">Как собрана работа</h2>

 <ul class="facts">
 <li class="facts__row">
 <span class="facts__label">Описание</span>
 <span class="facts__value">
 <?= trim((string) ($assignment['description'] ?? '')) === ''
 ? '<span class="muted">не задано</span>'
 : $view->e($assignment['description']) ?>
 </span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Предмет</span>
 <span class="facts__value"><?= $view->e($subjectName ?? '—') ?></span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Параллель</span>
 <span class="facts__value">
 <?php if ($parallel !== null): ?>
 <?= $view->e($view->count((int) ($parallel),  'класс', 'класса', 'классы')) ?>
 <br><span class="muted">Для порядка в списке. Кому выдана работа, решает мастер выдачи.</span>
 <?php else: ?>
 <span class="muted">не указана</span>
 <?php endif; ?>
 </span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Время на попытку</span>
 <span class="facts__value">
 <?= $assignment['time_limit'] === null ? 'без ограничения' : $view->e((int) $assignment['time_limit']) . ' мин.' ?>
 </span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Попыток</span>
 <span class="facts__value">
 <?php
 /*
  * Лимита у работы нет: он принадлежит выдаче. Одна работа может быть выдана
  * двум классам по-разному, поэтому единственное верное число здесь — «задаётся
  * при выдаче», а конкретные значения видны в списке выдач.
  */
 $issueLimits = [];

 foreach ($issues as $issue) {
     $issueLimits[] = (int) ($issue['max_attempts'] ?? 0) === 0
         ? 'без ограничения'
         : $view->e((int) $issue['max_attempts']);
 }

 echo $issueLimits === []
     ? 'задаётся при выдаче'
     : implode('; ', array_unique($issueLimits));
 ?>
 </span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Итог</span>
 <span class="facts__value">
 <?= ($assignment['criteria_mode'] ?? 'percent') === 'score' ? 'по баллам' : 'по проценту выполнения' ?>
 </span>
 </li>
 <li class="facts__row">
 <span class="facts__label">Результат ученику</span>
 <span class="facts__value">
 <?= (int) ($assignment['show_results'] ?? 0) === 1 ? 'показывается' : 'скрыт' ?>
 </span>
 </li>
 </ul>
</div>
