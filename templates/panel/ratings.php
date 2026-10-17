<?php

declare(strict_types=1);

/**
 * Средний балл и рейтинги.
 *
 * Ожидает:
 * $view, $period, $presets, $overall, $classes, $subjects, $classId, $students, $own
 *
 * @var \MyEdu\Core\View $view
 * @var \MyEdu\Grading\RatingPeriod $period
 * @var list<array{value:string,label:string}> $presets
 * @var array{works:int,percent:?float,grade:?float} $overall
 * @var list<array{class_id:int|null,class_name:string,class_title:string,works:int,percent:?float,grade:?float}> $classes
 * @var list<array{subject_id:int|null,name:string,works:int,percent:?float,grade:?float}> $subjects
 * @var int $classId
 * @var list<array{student_id:int,full_name:string,works:int,percent:?float,grade:?float}> $students
 * @var bool $own показываются ли только свои работы
 */

/*
 * Числа без знака «%» в данных: проценты приходят числами и округляются в
 * репозитории до одного знака, оценки — до двух. Средняя оценка дробная и сама
 * по себе ничего не значит: это среднее по средним, а не оценка ученику. Поэтому
 * рядом всегда стоит средний балл в процентах — он единственный, из оценок не
 * получившийся.
 */
$ratingNumber = static function (?float $value, int $decimals): string {
 if ($value === null) {
 return '—';
 }
 return number_format($value, $decimals, ',', ' ');
};


$view->crumbs([
        ['label' => 'Панель', 'url' => 'panel'],
        ['label' => 'Средний балл', 'url' => ''],
    ]);
?>

<div class="card">
 <h1 class="card-title">Средний балл</h1>

 <p class="card-subtitle">
 Считается по тем попыткам, которые уже проверены учителем, за тот период,
 на который была выдана работа<?= $own ? ', и только по вашим работам' : '' ?>.
 </p>

 <?php /* ?>
 Работа, сданная и ждущая проверки, здесь не видна: неотмеченные позиции
 дают ноль, и средний по классу был бы занижен, а потом поехал вверх сам
 собой после проверки. Число, меняющееся после того, как его посмотрели,
 читалось бы как «оценки испортились».
 <?php */ ?>

 <form method="get" action="<?= $view->e($view->url('panel/ratings')) ?>" class="field-row">
 <label class="form-group">
 <span class="form-label">Период</span>
 <select name="period">
 <?php foreach ($presets as $preset): ?>
 <option value="<?= $view->e($preset['value']) ?>"
 <?= $preset['value'] === $period->value ? ' selected' : '' ?>>
 <?= $view->e($preset['label']) ?>
 </option>
 <?php endforeach; ?>
 </select>
 </label>

 <button type="submit" class="btn">Показать</button>
 </form>

<dl class="facts">
<div class="facts__row">
<span class="facts__label">Период</span>
<span class="facts__value"><?= $view->e($period->label) ?></span>
</div>
<div class="facts__row">
<span class="facts__label">Работ учтено</span>
<span class="facts__value"><?= $view->e($overall['works']) ?></span>
</div>
<div class="facts__row">
<span class="facts__label">Средний балл</span>
<span class="facts__value">
<?= $view->e($ratingNumber($overall['percent'], 1)) ?> %
</span>
</div>
<div class="facts__row">
<span class="facts__label">Средняя оценка</span>
<span class="facts__value"><?= $view->e($ratingNumber($overall['grade'], 2)) ?></span>
</div>
</dl>
</div>

<div class="card">
 <h2 class="card-title">По классам</h2>

 <?php if ($classes === []): ?>
 <p class="muted">
 За этот период нет проверенных работ. Средний появляется, когда
 учитель проверит хотя бы одну работу, выданную сроком.
 </p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Класс</th>
 <th scope="col">Работ</th>
 <th scope="col">Средний балл</th>
 <th scope="col">Средняя оценка</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($classes as $row): ?>
 <?php $isCurrent = $row['class_id'] !== null && (int) $row['class_id'] === $classId; ?>
 <tr<?= $isCurrent ? 'class="is-current"' : '' ?>>
 <td>
 <strong><?= $view->e($row['class_name'] === '' ? 'Без класса' : $row['class_name']) ?></strong>
 <?php if ($row['class_title'] !== ''): ?>
 <br><span class="muted"><?= $view->e($row['class_title']) ?></span>
 <?php endif; ?>
 </td>
 <td><?= $view->e($row['works']) ?></td>
 <td><?= $view->e($ratingNumber($row['percent'], 1)) ?> %</td>
 <td><?= $view->e($ratingNumber($row['grade'], 2)) ?></td>
 <td>
 <?php if ($row['class_id'] === null): ?>
 <?php /* ?>
 Класс мог быть удалён после выдачи: класс хранится в
 выдаче копией и ссылку на себя не держит, иначе удаление
 класса стирало бы выдачу. Такую строку чинить нечем —
 только переоформить выдачу.
 <?php */ ?>
 <span class="muted">—</span>
 <?php elseif ($isCurrent): ?>
 <span class="muted">показаны ниже</span>
 <?php else: ?>
 <a class="link"
 href="<?= $view->e($view->url('panel/ratings?period=' . rawurlencode($period->value)
 . '&class=' . (int) $row['class_id'])) ?>">
 Показать учеников
 </a>
 <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">По предметам</h2>

 <?php if ($subjects === []): ?>
 <p class="muted">За этот период нет проверенных работ с предметом.</p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Предмет</th>
 <th scope="col">Работ</th>
 <th scope="col">Средний балл</th>
 <th scope="col">Средняя оценка</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($subjects as $row): ?>
 <tr>
 <td><strong><?= $view->e($row['name'] === '' ? 'Без предмета' : $row['name']) ?></strong></td>
 <td><?= $view->e($row['works']) ?></td>
 <td><?= $view->e($ratingNumber($row['percent'], 1)) ?> %</td>
 <td><?= $view->e($ratingNumber($row['grade'], 2)) ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>

<?php if ($students !== []): ?>
 <div class="card">
 <h2 class="card-title">
 Ученики класса
 <?php
 foreach ($classes as $row) {
 if ($row['class_id'] !== null && (int) $row['class_id'] === $classId) {
 echo $view->e(($row['class_name'] === '' ? '' : $row['class_name'] . ' ')
 . ($row['class_title'] === '' ? '' : $row['class_title']));
 break;
 }
 }
 ?>
 </h2>

 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Ученик</th>
 <th scope="col">Работ</th>
 <th scope="col">Средний балл</th>
 <th scope="col">Средняя оценка</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($students as $row): ?>
 <tr>
 <td><?= $view->e($row['full_name']) ?></td>
 <td><?= $view->e($row['works']) ?></td>
 <td><?= $view->e($ratingNumber($row['percent'], 1)) ?> %</td>
 <td><?= $view->e($ratingNumber($row['grade'], 2)) ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 </div>
<?php endif; ?>
