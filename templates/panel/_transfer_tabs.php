<?php

declare(strict_types=1);

/**
 * Вкладки страницы переноса.
 *
 * Откуда взяты два разных пути: свой файл переносит работу целиком, из чужой
 * системы приезжает только содержимое теста и неизвестно, сколько его будет.
 * Смешивать их в одну форму нельзя — человек не поймёт, что именно приложит.
 *
 * @var \MyEdu\Core\View $view
 * @var string $transferTab активная вкладка: 'own' или 'otp'
 */

 /*
  * По умолчанию — первая вкладка. Без этого шаблон, который поставит пустое
  * значение, показал бы страницу без выделенной вкладки: человек не понял бы,
  * в какой из двух вкладок он находится.
  */
$transferTab = ($transferTab ?? '') === 'otp' ? 'otp' : 'own';

$transferTabs = [
    'own' => [
        'label' => 'Импорт из своего файла',
        'url'   => 'panel/assignments/import',
    ],
    'otp' => [
        'label' => 'Импорт из OnlineTestPad',
        'url'   => 'panel/assignments/import/otp',
    ],
];
?>
<nav aria-label="Откуда переносим работу">
 <ul class="nav nav-tabs">
  <?php foreach ($transferTabs as $key => $tab): ?>
  <?php $active = $transferTab === $key; ?>
  <li class="nav-item">
   <a class="nav-link<?= $active ? ' active' : '' ?>"
   href="<?= $view->e($view->url($tab['url'])) ?>"
   <?= $active ? 'aria-current="page"' : '' ?>>
   <?= $view->e($tab['label']) ?>
   </a>
  </li>
  <?php endforeach; ?>
 </ul>
</nav>