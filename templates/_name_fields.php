<?php

declare(strict_types=1);

use MyEdu\Ui\Ui;

/**
 * Поля имени по частям: фамилия, имя, отчество.
 *
 * Общий фрагмент для двух страниц — своей (/profile) и чужой
 * (/panel/users/{id}). Правила одни и те же: без фамилии и имени нельзя,
 * отчество необязательно. Расходиться тут нельзя — иначе один и тот же
 * человек окажется заполнен по-разному в зависимости от того, кто его правил.
 *
 * Рисуется хуками ядра (`$ui`) — теми же, что отданы плагинам. Форма одна на
 * всё приложение: иначе страница сотрудника и редактор задания в плагине
 * показывали бы одинаковое поле по-разному, и человек сравнивал бы их между
 * собой.
 *
 * Ошибка сервера приходит подписями и попадает в поле: `.is-invalid`,
 * `.form-error` и `aria-invalid`. Пока ошибки нет, и сам класс не ставится:
 * пустое поле с красной рамкой до нажатия выглядело бы поломкой.
 *
 * Ожидает в области видимости:
 * @var \MyEdu\Core\View $view
 * @var Ui $ui
 * $nameLast, $nameFirst, $nameMiddle — значения полей (строки, '' если пусто)
 * $nameErrorLast, $nameErrorFirst, $nameErrorMiddle — подписи ошибок,
 *   если сервер их прислал (строки, '' если ошибки нет)
 */

/** @var array<string,string> $nameErrors */
$nameErrors = $nameErrors ?? [];

?>
<?= $view->raw($ui->field('last_name', [
    'label'       => 'Фамилия',
    'value'       => $nameLast,
    'required'    => true,
    'maxlength'   => 191,
    'autocomplete' => 'family-name',
    'placeholder' => 'Иванов',
    'error'       => $nameErrors['last_name'] ?? '',
])) ?>

<?= $view->raw($ui->field('first_name', [
    'label'       => 'Имя',
    'value'       => $nameFirst,
    'required'    => true,
    'maxlength'   => 191,
    'autocomplete' => 'given-name',
    'placeholder' => 'Иван',
    'error'       => $nameErrors['first_name'] ?? '',
])) ?>

<?= $view->raw($ui->field('middle_name', [
    'label'       => 'Отчество',
    'value'       => $nameMiddle,
    'maxlength'   => 191,
    'placeholder' => 'Иванович',
    'error'       => $nameErrors['middle_name'] ?? '',
    'help'        => 'Необязательно.',
])) ?>
