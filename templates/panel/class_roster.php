<?php

declare(strict_types=1);

/**
 * Редактор класса: вкладка «Предметы и учителя».
 *
 * Содержимое перенесено сюда без изменений — предметы и учителя класса это
 * отдельный сюжет, и смешивать его с учениками незачем. Изменилось только
 * то, что страница стала вкладкой редактора класса.
 *
 * Ожидает:
 * $view, $class, $tab, $subjects, $teachers, $allStaff, $mine
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var string $tab
 * @var list<array<string,mixed>> $subjects
 * @var array<int,array<string,mixed>> $teachers
 * @var list<array<string,mixed>> $allStaff
 * @var list<int> $mine
 */
$classId = (int) $class['id'];


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Классы', 'url' => 'classes'],
        ['label' => $view->e($class['name']), 'url' => 'classes/' . $classId],
        ['label' => 'Предметы и учителя', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Предметы и учителя — <?= $view->e($class['name']) ?></h1>
</div>

<?php require __DIR__ . '/_class_tabs.php'; ?>

<div class="split">
 <div class="card">
 <h2 class="card-title">Предметы класса</h2>

 <form method="post" action="<?= $view->e($view->url('classes/' . $classId . '/subjects')) ?>" class="stack">
 <?= $view->raw($view->csrfField()) ?>

 <?php if ($subjects === []): ?>
 <p class="muted">В системе пока нет предметов. Добавьте первый в форме ниже.</p>
 <?php else: ?>
 <?php foreach ($subjects as $subject): ?>
 <label class="form-check">
 <input type="checkbox" name="subjects[]" value="<?= $view->e((int) $subject['id']) ?>"
 <?= (int) $subject['attached'] === 1 ? 'checked' : '' ?>>
 <span><?= $view->e($subject['name']) ?></span>
 </label>
 <?php endforeach; ?>

 <button type="submit" class="btn btn-primary">Сохранить предметы</button>
 <?php endif; ?>
 </form>

 <hr class="sep">

 <h3 class="card-title">Добавить предмет в систему</h3>

 <form method="post"
 action="<?= $view->e($view->url('classes/' . $classId . '/subjects/create')) ?>">
 <?= $view->raw($view->csrfField()) ?>

<div class="form-group">
    <label class="form-label" for="roster-subject-name">Название предмета</label>
    <input class="form-control" type="text" id="roster-subject-name" name="name"
           required maxlength="191" placeholder="Биология">
</div>

<button type="submit" class="btn">Добавить и привязать к классу</button>
 </form>
 </div>

 <div class="card">
 <h2 class="card-title">Учителя</h2>
 <p class="muted">
 Выбираются из зарегистрированных сотрудников с ролью «Учитель»,
 «Завуч» или «Администратор».
 </p>

 <form method="post"
 action="<?= $view->e($view->url('classes/' . $classId . '/teachers')) ?>"
class="stack">
 <?= $view->raw($view->csrfField()) ?>

 <?php if ($allStaff === []): ?>
 <p class="muted">
 Ни одного учителя в системе нет. Сначала зарегистрируйте
 сотрудников и назначьте им роль «Учитель».
 </p>
 <?php else: ?>
 <?php foreach ($allStaff as $staff): ?>
 <label class="form-check">
 <input type="checkbox" name="teachers[]" value="<?= $view->e((int) $staff['id']) ?>"
 <?= in_array((int) $staff['id'], $mine, true) ? 'checked' : '' ?>>
 <span><?= $view->e($staff['full_name']) ?></span>
 </label>
 <?php endforeach; ?>

 <button type="submit" class="btn btn-primary">Сохранить учителей</button>
 <?php endif; ?>
 </form>

 <?php if ($teachers !== []): ?>
 <hr class="sep">
 <h3 class="card-title">Сейчас в классе</h3>
 <ul class="chip-list">
 <?php foreach ($teachers as $teacher): ?>
 <li class="chip"><?= $view->e($teacher['full_name']) ?></li>
 <?php endforeach; ?>
 </ul>
 <?php endif; ?>
 </div>
</div>
