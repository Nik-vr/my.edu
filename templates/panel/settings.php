<?php

declare(strict_types=1);

/**
 * Настройки сайта: название школы и прочее, что меняет администратор.
 *
 * @var \MyEdu\Core\View $view
 * @var string $schoolName
 * @var ?string $notice
 * @var ?string $error
 */



$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Настройки сайта', 'url' => ''],
    ]);
?>

<h1 class="page-title">Настройки сайта

</h1>

<div class="card">
 <form method="post" action="<?= $view->e($view->url('panel/settings')) ?>

">
 <?= $view->raw($view->csrfField()) ?>

 <label class="form-group">
 <span class="form-label">Название школы</span>
 <input type="text" name="school_name" value="<?= $view->e($schoolName) ?>"
 maxlength="191" autofocus
 placeholder="Муниципальное бюджетное общеобразовательное учреждение «Средняя школа № 1»">
 <span class="form-help">
 Показывается в панели управления, на листах печати и в письмах.
 Оставьте пустым, если школа ещё не выбрана.
 </span>
 </label>

 <button type="submit" class="btn btn-primary btn-lg">Сохранить</button>
 </form>
</div>
