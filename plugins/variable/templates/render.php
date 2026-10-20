<?php

declare(strict_types=1);

use MyEduPlugin\Variable\Number;

/**
 * Задание внутри работы: пример с подставленными числами.
 *
 * Форма отправляется на маршрут ядра, поэтому переход «дальше» и сдача работы
 * остаются в ведении ядра: плагин не решает, что делать с попыткой.
 *
 * Значения переменных едут в скрытых полях вместе с ответами. Это не защита от
 * жулика: показывать их ученику нужно, чтобы после возврата к заданию он увидел
 * тот же пример, который решал, и чтобы проверка считала по нему, а не
 * разыгрывала новый.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var string $prompt
 * @var array<string,float> $values
 * @var float|null $answer
 * @var string $unit
 * @var int $maxLength
 * @var int $position
 * @var int $total
 * @var float $weight
 * @var int $itemId
 * @var int $attemptId
 * @var string $token
 * @var string $attemptUrl
 * @var string $nextUrl
 * @var bool $isLast
 * @var int|null $remaining сколько секунд осталось на попытку
 */
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
<div class="page-header">
 <h1 class="page-title"><?= $view->e($title) ?></h1>
 <p class="muted">
 Задание <?= $view->e($position) ?> из <?= $view->e($total) ?> ·
 вес в итоге работы <?= $view->e($formatScore($weight)) ?>
 </p>
</div>

<?php /*
 Таймер рисует ядро: остаток времени считает оно, а плагин его только
 показывает. Без лимита таймера нет вовсе.
 */ ?>
<?= $view->raw($view->render('work/_timer', ['remaining' => $remaining ?? null], '')) ?>

<?php /* Запрет копирования подключается ядром: решение принимает учитель. */ ?>
<?= $view->raw($view->render('work/_no_copy', ['noCopy' => !empty($noCopy)], '')) ?>

<form
 id="attempt-form"
 method="post"
 action="<?= $view->e($view->url(sprintf('work/%d/item/%d', $attemptId, $itemId))) ?>"
 class="attempt<?= !empty($noCopy) ? ' attempt--no-copy' : '' ?>"
 data-remaining="<?= $view->e((string) ($remaining ?? 0)) ?>"
 novalidate><?php /*
 id обязателен: attempt.js начинает работу с поиска #attempt-form и без
 него выходит сразу. Без него страница выглядела бы исправной, просто не
 работали бы защита от повторной отправки и сохранение при уходе.
 */ ?>

 <?= $view->raw($ui->hidden('t', $token)) ?>
 <?= $view->raw($view->csrfField()) ?>

 <?php /* Маркер присутствия: без него «очистил ответ» не отличить от перехода. */ ?>
 <?= $view->raw($ui->hidden('answer_present[' . (int) $itemId . ']', '1')) ?>

 <h2 class="question__prompt"><?= $view->raw($view->rich($prompt)) ?></h2>

 <p class="question__hint">
 Решите задачу и впишите ответ. Проверка выполняется по той же формуле,
 что и ваши вычисления, — с теми же числами, которые вы видите в условии.
 </p>

 <?php /*
 Поле ответа рисует ядро: подпись, подсказка и отметка ошибки у ответа
 ученика должны выглядеть так же, как у любого поля ядра, иначе одна и та же
 форма выглядит по-разному в редакторе и при прохождении работы.
 */ ?>
 <?= $view->raw($ui->field('answer', [
     'label'      => 'Ответ',
     'value'      => $answer === null ? '' : Number::format($answer),
     'inputmode'  => 'decimal',
     'maxlength'  => $maxLength,
     'placeholder' => 'Введите число',
     'help'       => $unit === '' ? '' : 'Единица измерения: ' . $unit . '.',
 ])) ?>

 <?php /*
 Значения ушедших в условие переменных кладутся в скрытые поля и
 отправляются вместе с ответом: иначе при возврате к заданию пример
 разыгрывался бы заново, а учитель мог бы изменить перечень уже после
 того, как ученик ответил, — и проверка пошла бы по другой задаче.
 */ ?>
 <?php foreach ($values as $name => $value): ?>
 <?= $view->raw($ui->hidden('values[' . $name . ']', Number::format((float) $value))) ?>
 <?php endforeach; ?>

 <div class="page-actions">
 <span class="actions-row__spacer"></span>

 <?php if ($isLast): ?>
 <?= $view->raw($ui->button('Завершить работу', [
     'size'  => 'btn-lg',
     'name'  => 'action',
     'value' => 'finish',
 ])) ?>
 <?php else: ?>
 <?= $view->raw($ui->button('Далее →', [
     'size'  => 'btn-lg',
     'name'  => 'action',
     'value' => 'next',
 ])) ?>
 <?php endif; ?>
 </div>

 <p class="muted">
 Ответ сохраняется при каждом переходе, поэтому страницу можно обновить и
 вернуться позже: числа останутся теми же.
 </p>
</form>

<p>
 <a class="btn btn-ghost" href="<?= $view->e($attemptUrl) ?>">К списку заданий работы</a>
</p>

<script src="<?= $view->e($view->asset('assets/js/attempt.js')) ?>" defer></script>