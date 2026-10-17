<?php

declare(strict_types=1);

/**
 * Вкладки редактора работы.
 *
 * Раньше редактор был мастером из четырёх страниц: чтобы поменять название
 * работы, надо было сохранить первый этап, потом пройти до третьего и только
 * там вернуться назад через нижнюю кнопку. Пять переходов на одну правку.
 *
 * Теперь это одна страница с вкладками, как на странице переноса работы:
 * четыре набора полей рядом, видно все сразу, переключение мгновенное.
 *
 * Ссылки ведут на якоря, а не на адреса страниц, поэтому вкладка остаётся
 * ссылкой: без скриптов браузер всё равно перейдёт по ней. Скрипт перехватывает
 * щелчок и переключает панели на месте — без перезагрузки и без проверки формы,
 * потому что переключение вкладки ничего не сохраняет.
 *
 * Ожидает:
 * $view, $workTab
 *
 * @var \MyEdu\Core\View $view
 * @var string $workTab активная вкладка: 'start', 'items', 'result' или 'publish'
 */

$workTabs = [
    'start'   => 'Начальная страница',
    'items'   => 'Задания',
    'result'  => 'Результат',
    'publish' => 'Опубликовать',
];
$workTab = array_key_exists($workTab ?? '', $workTabs) ? (string) $workTab : 'start';
?>
<nav class="work-tabs" aria-label="Что редактируем">
 <ul class="nav nav-tabs" role="tablist">
 <?php foreach ($workTabs as $key => $label): ?>
 <?php $active = $workTab === $key; ?>
 <li class="nav-item" role="presentation">
 <a class="nav-link<?= $active ? ' active' : '' ?>"
  id="work-tab-<?= $view->e($key) ?>"
  href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit?tab=' . $key)) ?>"
  data-work-tab="<?= $view->e($key) ?>"
  role="tab"
  aria-controls="work-pane-<?= $view->e($key) ?>"
  aria-selected="<?= $active ? 'true' : 'false' ?>"
  <?= $active ? 'aria-current="page"' : '' ?>>
 <?= $view->e($label) ?>
 </a>
 </li>
 <?php endforeach; ?>
 </ul>
</nav>