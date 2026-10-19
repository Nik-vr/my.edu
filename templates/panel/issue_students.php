<?php

declare(strict_types=1);

/**
 * Шаг 3 мастера выдачи: кому именно.
 *
 * Ученики идут по порядку классов и фамилий — список читают сверху вниз, и
 * случайный порядок заставлял бы искать глазами.
 *
 * Отметка по умолчанию снята. Отмеченные сразу все опаснее пустого списка:
 * выдача полутора десяткам там, где учитель хотел спросить троих, остаётся
 * незаметной, пока кто-то не начнёт выполнять чужую работу.
 *
 * Кто уже получал эту работу, помечен. Не чтобы запретить — повторная выдача
 * иногда нужна, — а чтобы её не выбрали случайно, не заметив.
 *
 * Ожидает:
 * $view, $assignment, $classes, $classIds, $students, $issued
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $classes
 * @var list<int> $classIds
 * @var list<array{student_id:int,full_name:string,class_id:int,class_name:string}> $students
 * @var list<int> $issued
 */

$assignmentId = (int) $assignment['id'];
$classNames = [];
foreach ($classes as $class) {
 $classNames[(int) $class['id']] = (string) $class['name'];
}


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
 <li class="is-current">3. Ученики</li>
 <li>4. Срок</li>
 </ol>
</div>

<div class="card">
 <h2 class="card-title">Кому выдать</h2>

 <p class="form-help">
 Классы:
 <?php
 $labels = [];

 foreach ($classIds as $classId) {
 $labels[] = $classNames[$classId] ?? ('#' . $classId);
 }

 echo $view->e(implode(', ', $labels));
 ?>
 · всего <?= $view->e($view->count(count($students), 'ученик', 'ученика', 'учеников')) ?>
 </p>

 <?php
/*
 * Шаг срока открывается адресом, а не формой: GET и POST на /period разные
 * вещи — первый показывает шаг, второй сохраняет выдачу. Отправлять шаг
 * учеников POST'ом значило бы сохранять выдачу до того, как человек увидел
 * выбор срока.
 */
?>
<form method="get"
 action="<?= $view->e($view->url('panel/issues/' . $assignmentId . '/period')) ?>"
 data-select-all-form
 data-required-group
 data-hint="Отметьте хотя бы одного ученика">

 <?php foreach ($classIds as $classId): ?>
 <input type="hidden" name="classes" value="<?= $view->e($classId) ?>">
 <?php endforeach; ?>

 <?php
 $groups = [];

 foreach ($students as $student) {
 $groups[(int) $student['class_id']][] = $student;
 }
 ?>

 <label class="form-check">
 <input type="checkbox" data-select-all>
 Выбрать всех
 </label>

 <?php foreach ($groups as $groupClassId => $groupStudents): ?>
 <?php
 $heading = $groupClassId === 0 ? 'Без класса' : (string) $groupStudents[0]['class_name'];
 ?>
 <h3 class="wizard__group-title"><?= $view->e($heading) ?></h3>

 <div class="check-list">
 <?php foreach ($groupStudents as $student): ?>
 <?php $studentId = (int) $student['student_id']; ?>
 <label class="form-check">
 <input type="checkbox" name="students[]" value="<?= $view->e($studentId) ?>"
 data-select-item>
 <?= $view->e($student['full_name']) ?>
 <?php if (in_array($studentId, $issued, true)): ?>
 <span class="chip"
 title="Эту работу ему уже выдавали раньше">уже выдавалось</span>
 <?php endif; ?>
 </label>
 <?php endforeach; ?>
 </div>
 <?php endforeach; ?>

 <p class="page-actions">
 <button type="submit" class="btn btn-primary">Дальше: срок</button>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/issues/' . $assignmentId . '/classes')) ?>">
 ← К выбору классов
 </a>
 </p>
 </form>
</div>

<!-- Скрипт общий со списком класса: галочка «Выбрать всех» одна и та же
 на всех страницах с большим списком, и разошлись бы они при правке. -->
<script src="<?= $view->e($view->asset('assets/js/select-all.js')) ?>" defer></script>
