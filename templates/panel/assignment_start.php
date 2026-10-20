<?php

declare(strict_types=1);

/**
 * Этап 1 мастера создания работы: начальная страница.
 *
 * У новой работы номера ещё нет, и вести её дальше некуда: вкладок с
 * заданиями, шкалой и публикацией ещё не существует. Поэтому здесь остаётся
 * мастер с полосой этапов, а редактор уже созданной работы — страница с
 * вкладками.
 *
 * Сами поля вынесены в общий файл: тот же набор полей человек правит и в
 * редакторе работы, и разошлись бы они при первом же новом поле.
 *
 * Ожидает:
 * $view, $assignment, $subjects, $parallel, $wizard, $hasPlugins
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $subjects
 * @var int|null $parallel
 * @var array<string,mixed> $wizard
 * @var bool $hasPlugins есть ли чем наполнять работу
 * @var array<string,string> $old введённое на этом шаге, если сервер отказал
 */

$assignmentId = (int) $assignment['id'];
$isNew = $assignmentId === 0;

$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Начальная страница', 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e($isNew ? 'Новая работа' : $assignment['title']) ?>

</h1>

<div class="card">
<?php /*
 * У работы, которой ещё нет, отправлять поля некуда: первый шаг создаёт её.
 * У работы существующей этот адрес больше не используется — редактор
 * существующей работы это страница с вкладками, и форма там общая на все
 * вкладки.
 */
$startUrl = $isNew
    ? 'panel/assignments/new/start'
    : 'panel/assignments/' . $assignmentId . '/edit/save';
?>
 <form method="post" action="<?= $view->e($view->url($startUrl)) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <?php /*
  * У этого шага своё поле названия, а не то, что в общей панели.
  *
  * Название в редакторе работы правят в шапке, рядом с заголовком, и в общей
  * панели его нет. Здесь же работа ещё не создана, и назвать её надо здесь:
  * пустая работа в списке выглядит забытой.
  */
  ?>
 <label class="form-group">
 <span class="form-label">Название</span>
 <input type="text" name="title" required maxlength="191" autofocus
 value="<?= $view->e((string) ($assignment['title'] ?? '')) ?>">
 </label>

 <?php require __DIR__ . '/_assignment_start_pane.php'; ?>

 <button type="submit" class="btn btn-primary">Сохранить и перейти к заданиям</button>
 </form>
</div>

<p class="wizard-page__nav">
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/assignments')) ?>">← К списку работ</a>
 <?php if (!$isNew): ?>
 <a class="btn btn-primary" href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit?tab=items')) ?>">
 Дальше: задания →
 </a>
 <?php endif; ?>
</p>
