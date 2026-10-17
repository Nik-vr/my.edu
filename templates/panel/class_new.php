<?php

declare(strict_types=1);

/**
 * Создание класса.
 *
 * Ожидает: $view, $old, $error
 *
 * @var \MyEdu\Core\View $view
 * @var array{name:string,title:string,year:?int} $old
 * @var ?string $error
 */



$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Классы', 'url' => 'classes'],
        ['label' => 'Новый класс', 'url' => ''],
    ]);
?>

<div class="card">
 <h1 class="card-title">Новый класс</h1>

 <p class="card-subtitle">
 Создайте класс — потом добавьте в него учеников и укажете предметы с
 учителями.
 </p>

 <form method="post" action="<?= $view->e($view->url('classes')) ?>

">
 <?= $view->raw($view->csrfField()) ?>

 <?php /*
 Поля рисуются хуками ядра — теми же, что отданы плагинам. Форма создания
 класса эталонная: по ней видно, как должно выглядеть поле, и разойтись с
 плагином после этого почти невозможно.
 */ ?>
 <?= $view->raw($ui->field('name', [
     'label'       => 'Номер класса',
     'value'       => $old['name'],
     'required'    => true,
     'maxlength'   => 32,
     'placeholder' => '7-Б',
     'hint'        => 'Укажите номер класса, например 7-Б',
     'help'        => 'Обязательно и уникально, например «7-Б».',
     'attrs'       => ['autofocus' => true],
 ])) ?>

 <?= $view->raw($ui->field('title', [
     'label'       => 'Название',
     'value'       => $old['title'],
     'maxlength'   => 40,
     'placeholder' => 'Например: параллель 2025/2026',
     'help'        => 'Необязательно, до 40 символов.'
 ])) ?>

 <?= $view->raw($ui->field('year', [
     'label'       => 'Учебный год',
     'value'       => (string) ($old['year'] ?? \MyEdu\Auth\ClassRepository::academicYear(date('Y'))),
     'maxlength'   => 9,
     'inputmode'   => 'numeric',
     'pattern'     => '[0-9]{4}(-[0-9]{4})?',
     'placeholder' => '2026-2027',
     'hint'        => 'Год одним числом или парой: 2026 или 2026-2027',
     'help'        => 'С 1 сентября по 31 мая следующего года. Можно написать «2026» или «2026-2027». Необязательно.'
 ])) ?>

 <button type="submit" class="btn btn-primary btn-lg">Создать класс</button>
 </form>

 <p class="page-actions">
 <a class="btn btn-ghost" href="<?= $view->e($view->url('classes')) ?>">К списку классов</a>
 </p>
</div>
