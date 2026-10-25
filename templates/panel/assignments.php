<?php

declare(strict_types=1);

/**
 * Список работ — деревом по папкам параллелей.
 *
 * Папка «8 классы» собирает работы, у которых параллель 8. Это единственный
 * порядок в списке: состояние у каждой работы видно в строке значком, а вот
 * параллели у работы нет — и учитель, у которого два десятка работ по шести
 * параллелям, искал бы нужную среди плоского списка.
 *
 * Дерево собирает WorkTree — тем же построителем, что и первый шаг мастера
 * выдачи: два построителя разошлись бы при первой правке, и на двух
 * страницах папки назывались бы по-разному.
 *
 * Ожидает:
 * $view, $tree, $state, $drafts, $published, $issued, $total, $seesAll, $currentUserId,
 * $pendingReview, $hasPlugins
 *
 * @var \MyEdu\Core\View $view
 * @var list<array{parallel:int|null,label:string,items:list<array<string,mixed>>}> $tree
 * @var string $state drafts, published или issued
 * @var int $drafts
 * @var int $published
 * @var int $issued
 * @var int $total
 * @var bool $seesAll
 * @var int $currentUserId
 * @var int $pendingReview
 * @var bool $hasPlugins
 */



$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title"><?= $view->e($seesAll ? 'Все работы' : 'Мои работы') ?>

</h1>
 <p class="muted">
 <?php if ($seesAll): ?>
 Здесь работы всех учителей, по папкам параллелей. Править и удалять
 можно только свои — чужая работа открывается на чтение.
 <?php else: ?>
 Работы собраны по папкам параллелей. Параллель — только подпись для
 порядка: кому работа выдана, решает мастер выдачи.
 <?php endif; ?>
 </p>
</div>

<?php if ($pendingReview > 0): ?>
 <?= $view->raw($view->bannerWarning(
     $pendingReview . ' '
     . $view->plural($pendingReview, 'работа ждёт', 'работы ждут', 'работ ждут')
     . ' проверки.'
 )) ?>
 <p><a class="btn btn-sm" href="<?= $view->e($view->url('panel/journal')) ?>">Открыть результаты</a></p>
<?php endif; ?>

<div class="card">
 <div class="page-actions">
 <a class="btn btn-primary btn-lg" href="<?= $view->e($view->url('panel/assignments/new')) ?>">
 Составить работу
 </a>
 <a class="btn btn-lg" href="<?= $view->e($view->url('panel/issues/new')) ?>">
 Выдать работу
 </a>

 <?php if (!$hasPlugins): ?>
 <p class="form-help">
 Нет доступных типов заданий. Подключите хотя бы один плагин на
 <a href="<?= $view->e($view->url('panel/plugins')) ?>">странице настройки</a>.
 </p>
 <?php endif; ?>
 </div>
</div>

<?php if ($total === 0): ?>
 <div class="card">
 <p class="muted">Работ пока нет.</p>
 </div>
<?php elseif ($tree === []): ?>
 <div class="card">
 <p class="muted">
 <?php if ($state === 'drafts'): ?>
 Черновиков нет. Работу начинают кнопкой «Составить работу».
 <?php elseif ($state === 'issued'): ?>
 Выданных работ нет. Работу можно выдать через мастер выдачи.
 <?php else: ?>
 Опубликованных работ нет. Опубликуйте существующий черновик, или создайте новую работу.
 <?php endif; ?>
 </p>
 </div>
<?php endif; ?>

<?php /*
 Разделение по состоянию — над деревом, а не внутри папок.

 Внутри папки смешанные черновики и готовые работы читались бы как список
 готовых: человек открывает «8 классы» на публикацию и видит там работу,
 которую ещё нельзя ни выдать, ни открыть ученикам, а отличить её можно
 только по значку в строке.
*/ ?>
<nav aria-label="Состояние работ">
 <ul class="nav nav-tabs">
  <li class="nav-item">
   <a class="nav-link<?= $state === 'published' ? ' active' : '' ?>"
   <?= $state === 'published' ? 'aria-current="page"' : '' ?>
   href="<?= $view->e($view->url('panel/assignments?state=published')) ?>">
   Опубликованные <span class="chip"><?= $view->e($published) ?></span>
   </a>
  </li>
  <li class="nav-item">
   <a class="nav-link<?= $state === 'issued' ? ' active' : '' ?>"
   <?= $state === 'issued' ? 'aria-current="page"' : '' ?>
   href="<?= $view->e($view->url('panel/assignments?state=issued')) ?>">
   Выданные <span class="chip"><?= $view->e($issued) ?></span>
   </a>
  </li>
  <li class="nav-item">
   <a class="nav-link<?= $state === 'drafts' ? ' active' : '' ?>"
   <?= $state === 'drafts' ? 'aria-current="page"' : '' ?>
   href="<?= $view->e($view->url('panel/assignments?state=drafts')) ?>">
   Черновики <span class="chip"><?= $view->e($drafts) ?></span>
   </a>
  </li>
 </ul>
</nav>

<?php foreach ($tree as $folder): ?>
 <?php /*
 Папка раскрыта по умолчанию: работы ищут именно по параллели, и
 свёрнутая папка прятала бы то, ради чего дерево и построено. Сворачивание
 оставлено тем же флажком — на длинном списке папок это удобно.
 */ ?>
 <section class="work-folder">
 <details class="work-folder__box" open>
 <summary class="work-folder__title">
 <span class="work-folder__name"><?= $view->e($folder['label']) ?></span>
 <span class="work-folder__count">
<?= $view->e($view->count((int) (count($folder['items'])),  'работа', 'работы', 'работ')) ?>
 </span>
 </summary>

 <?php /*
 Строки рисует общий фрагмент: он же используется на первом шаге
 мастера выдачи, и копия таблицы разошлась бы при первой правке.
 Значения для него задаются до require — файл выполняется сразу
 и читает именно их.
 */ ?>
 <?php
 $selectable = false;
 $actionUrl = '';
 $pickName = '';
 $workState = $state; // передаем состояние для кнопки отзыва
 ?>

 <div class="work-list__head" aria-hidden="true">
 <span>Работа</span>
 <span>Состояние</span>
 <span>Заданий</span>
 <span>Выдано</span>
 <span>Выполнили</span>
 <span>Ждут проверки</span>
 <span></span>
 </div>

 <ul class="work-list" data-work-list>
 <?php foreach ($folder['items'] as $row): ?>
 <?php require __DIR__ . '/_work_row.php'; ?>
 <?php endforeach; ?>
 </ul>
 </details>
 </section>
<?php endforeach; ?>

<?php /*
 Удаление работы — без перезагрузки страницы.

 Скрипт нужен рядом со списком, а не в общем файле: он касается только
 удаления работы, и подключать его везде незачем.
 */ ?>
<script src="<?= $view->e($view->asset('assets/js/work-list.js')) ?>" defer></script>
