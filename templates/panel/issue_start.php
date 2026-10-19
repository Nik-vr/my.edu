<?php

declare(strict_types=1);

/**
 * Шаг 1 мастера выдачи: какую работу выдаём.
 *
 * Работы лежат тем же деревом по папкам параллелей, что и на странице
 * «Работы»: учитель ищет нужную там же, где и всегда искал. Построитель один
 * (WorkTree), иначе две страницы разошлись бы за первую же правку.
 *
 * Черновиков здесь нет: выдать неопубликованную работу нельзя, ученик её не
 * увидит, и выдача пропала бы молча.
 *
 * Ожидает:
 * $view, $tree, $total, $seesAll, $currentUserId, $chosen
 *
 * @var \MyEdu\Core\View $view
 * @var list<array{parallel:int|null,label:string,items:list<array<string,mixed>>}> $tree
 * @var int $total
 * @var bool $seesAll
 * @var int $currentUserId
 * @var int $chosen работа, выбранная адресом
 */



$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Выдать работу', 'url' => ''],
    ]);
?>

<h1 class="page-title">Выдать работу</h1>

<div class="card">
 <ol class="steps-inline">
 <li class="is-current">1. Работа</li>
 <li>2. Классы</li>
 <li>3. Ученики</li>
 <li>4. Срок</li>
 </ol>
</div>

<?php if ($total === 0):
?>

<div class="card">
 <p class="muted">Опубликованных работ нет. Выдавать имеет смысл опубликованную:
 ученик увидит её только тогда.</p>
 <a class="btn btn-primary" href="<?= $view->e($view->url('panel/assignments')) ?>">
 К списку работ
 </a>
 </div>
<?php endif; ?>

<form method="post" action="<?= $view->e($view->url('panel/issues/start')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <?php foreach ($tree as $folder): ?>
 <section class="work-folder">
 <details class="work-folder__box" open>
 <summary class="work-folder__title">
 <span class="work-folder__name"><?= $view->e($folder['label']) ?></span>
 <span class="work-folder__count">
<?= $view->e($view->count((int) (count($folder['items'])),  'работа', 'работы', 'работ')) ?>
 </span>
 </summary>

 <div class="work-list__head" aria-hidden="true">
 <span>Работа</span>
 <span>Состояние</span>
 <span>Заданий</span>
 <span>Выдано</span>
 <span>Выполнили</span>
 <span>Ждут проверки</span>
 <span></span>
 </div>

 <ul class="work-list">
 <?php foreach ($folder['items'] as $row): ?>
 <?php
 $selectable = true;
 $actionUrl = '';
 $pickName = 'assignment';
 // Порядковый номер строки: обязательным помечается первый круглый
 // переключатель группы, а требование «выберите работу» одно на все строки.
 $rowIndex = isset($rowIndex) ? $rowIndex + 1 : 0;
 require __DIR__ . '/_work_row.php';
 ?>
 <?php endforeach; ?>
 </ul>
 </details>
 </section>
 <?php endforeach; ?>

 <?php if ($total > 0): ?>
 <div class="card">
 <p class="form-help">
 Отметьте работу и нажмите «Дальше». Следующим шагом выберутся
 классы, затем ученики и срок, в течение которого работа доступна.
 </p>
 <button type="submit" class="btn btn-primary btn-lg">Дальше: классы</button>
 </div>
 <?php endif; ?>
</form>
