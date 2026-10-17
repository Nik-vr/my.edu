<?php

declare(strict_types=1);

/**
 * Шаг 2 мастера выдачи: каким классам.
 *
 * Показываются все классы, а не только те, что в параллели работы: параллель у
 * работы — подпись для папки, а не адресация, и ограничение классами той же
 * параллели было бы именно ей.
 *
 * Пустой класс тоже виден, но отмечен: работа выданная «всем, кто есть» —
 * нормальная мысль учителя, и молча выбрасывать из неё учеников нельзя.
 *
 * Ожидает:
 * $view, $assignment, $classes, $selected
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $classes
 * @var list<int> $selected
 */

$assignmentId = (int) $assignment['id'];


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
 <li class="is-current">2. Классы</li>
 <li>3. Ученики</li>
 <li>4. Срок</li>
 </ol>
</div>

<div class="card">
 <h2 class="card-title">Кому будет выдана</h2>

 <?php if ($classes === []): ?>
 <p class="muted">
 Классов в системе нет. Сначала заведите классы и учеников в разделе
 «Классы и ученики» — выдавать некому.
 </p>
 <?php else: ?>
 <form method="get"
 action="<?= $view->e($view->url('panel/issues/' . $assignmentId . '/students')) ?>">
 <div class="check-list" data-required-group
 data-hint="Отметьте хотя бы один класс">
 <?php foreach ($classes as $class): ?>
 <?php $classId = (int) $class['id']; ?>
 <label class="form-check">
 <input type="checkbox" name="classes[]" value="<?= $view->e($classId) ?>"
 <?= in_array($classId, $selected, true) ? 'checked' : '' ?>>
 <?= $view->e($class['name']) ?>
 <?php if (!empty($class['title'])): ?>
 <span class="muted"><?= $view->e($class['title']) ?></span>
 <?php endif; ?>
 </label>
 <?php endforeach; ?>
 </div>

 <p class="page-actions">
 <button type="submit" class="btn btn-primary">Дальше: ученики</button>
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/issues/new')) ?>">
 ← К выбору работы
 </a>
 </p>
 </form>
 <?php endif; ?>
</div>
