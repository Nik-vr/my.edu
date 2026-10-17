<?php

declare(strict_types=1);

/**
* Шаг 4 мастера выдачи: на какой срок и сколько попыток.
 *
 * Срок по умолчанию — неделя. Работа, о которой забыли, закрывается сама, а
 * открытая навсегда работа засоряет список ученика следующими годами.
 * «Всегда» остаётся, но его надо выбрать специально.
 *
 * Число попыток живёт здесь, а не на странице работы. Одна работа может быть
 * выдана двум классам по-разному: на контрольную — одна попытка, на
 * тренировочную — пять. Число у работы описывало бы только половину случаев и
 * правкой работы менялось бы у всех выдач сразу, включая те, где учитель
 * ограничение ставил намеренно иначе.
 *
 * Поля своих дат всегда на странице: прятать их до выбора «свои даты» —
 * значит показать форму, которой не было на экране, и потерять то, что человек
 * уже набрал.
 *
 * Ожидает:
 * $view, $assignment, $classes, $classIds, $picked, $students
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $classes
 * @var list<int> $classIds
 * @var list<int> $picked
 * @var list<array{student_id:int,full_name:string,class_id:int,class_name:string}> $students
 * @var array<string,string> $old что вводилось на этом шаге до отказа сервера
 */

$assignmentId = (int) $assignment['id'];
$classNames = [];
foreach ($classes as $class) {
$classNames[(int) $class['id']] = (string) $class['name'];
}
$names = [];
foreach ($students as $student) {
if (in_array((int) $student['student_id'], $picked, true)) {
$names[] = (string) $student['full_name'];
}
}

/*
 * Срок и даты приходят адресом с предыдущего шага, а после отказа сервера
 * форма показывается снова. Без этого человек, который менял срок и получил
 * отказ, увидел бы неделя — то есть то, что он сам и не выбирал.
 */
$old = is_array($old ?? null) ? $old : [];
$oldPeriod = (string) ($old['period'] ?? 'week');


$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Выдать работу', 'url' => ''],
        ['label' => $view->e($assignment['title']), 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e($assignment['title']) ?>

</h1>

<div class="card">
 <ol class="steps-inline">
 <li>1. Работа</li>
 <li>2. Классы</li>
 <li>3. Ученики</li>
 <li class="is-current">4. Срок и попытки</li>
 </ol>
</div>

<div class="card">
 <h2 class="card-title">Кому выдаётся</h2>

 <p class="form-help">
 <?= $view->e(count($names)) ?>
 <?= $view->e($view->plural(count($names), 'ученик', 'ученика', 'учеников')) ?>:
 <?= $view->e(implode(', ', $names)) ?>
 </p>

 <form method="post" action="<?= $view->e($view->url('panel/issues/' . $assignmentId . '/period')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <?php foreach ($classIds as $classId): ?>
 <input type="hidden" name="classes" value="<?= $view->e($classId) ?>">
 <?php endforeach; ?>
 <?php foreach ($picked as $studentId): ?>
 <input type="hidden" name="students[]" value="<?= $view->e($studentId) ?>">
 <?php endforeach; ?>

 <fieldset>
 <legend>Срок доступности</legend>

<div class="check-list" data-required-group
 data-hint="Отметьте срок">
<label class="form-check">
<input type="radio" name="period" value="today"
 <?= $oldPeriod === 'today' ? 'checked' : '' ?>>
 Сегодня — до конца дня
</label>
<label class="form-check">
<input type="radio" name="period" value="week" <?= $oldPeriod === 'week' ? 'checked' : '' ?>>
 Неделя — до конца седьмого дня
</label>
<label class="form-check">
<input type="radio" name="period" value="always"
 <?= $oldPeriod === 'always' ? 'checked' : '' ?>>
 Всегда — не закрывается
</label>
<label class="form-check">
<input type="radio" name="period" value="dates"
 <?= $oldPeriod === 'dates' ? 'checked' : '' ?>>
 Свои даты
</label>
</div>

<label class="form-group">
<span class="form-label">Открыть</span>
<input type="date" id="date_from" name="date_from" value="<?= $view->e($old['date_from'] ?? date('Y-m-d')) ?>"
 data-required-when="period:dates"
 data-hint="Укажите, с какого числа доступна работа">
</label>

<label class="form-group">
<span class="form-label">Закрыть</span>
<input type="date" id="date_until" name="date_until" value="<?= $view->e($old['date_until'] ?? '') ?>"
 data-required-when="period:dates"
 data-hint="Укажите, до какого числа доступна работа">
</label>

<p class="form-help">
 Эти даты относятся только к режиму «Свои даты»: там обе обязательны.
 В остальных режимах срок считается сам — на неделю, на сегодня или
 бессрочно, и пустые даты ничего не значат.
</p>
</fieldset>

 <label class="form-group">
 <span class="form-label">Сколько попыток разрешить</span>
 <input type="number" name="max_attempts" min="0" max="20"
 value="<?= $view->e($old['max_attempts'] ?? '0') ?>">
 <span class="form-help">
 0 — сколько угодно. Это ограничение живёт здесь, а не в работе: та же работа,
 выданная другому классу, может иметь другой лимит.
 </span>
 </label>

 <p class="page-actions">
 <button type="submit" class="btn btn-primary btn-lg">Выдать работу</button>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/issues/' . $assignmentId
 . '/students?classes=' . implode(',', $classIds))) ?>">
 ← К выбору учеников
 </a>
 </p>
 </form>
</div>
