<?php

declare(strict_types=1);

/**
 * Задание-тест внутри работы.
 *
 * Все вопросы теста показаны на одной странице: внутри работы переключение
 * вопроса было бы вторым уровнем навигации поверх навигации ядра, и ученик
 * терялся бы в двух списках подряд.
 *
 * Форма отправляется на маршрут ядра, поэтому переход «дальше» и сдача работы
 * остаются в ведении ядра: плагин не решает, что делать с попыткой.
 *
 * Разметка — хуки ядра (`$ui`): поля ответа, скрытые поля и кнопки. Виджеты
 * ответа (варианты, порядок, соответствие) остаются своими: это содержимое
 * вопроса, а не типовой элемент формы.
 *
 * @var \MyEdu\Core\View $view
 * @var \MyEdu\Ui\Ui $ui
 * @var array<string,mixed> $test
 * @var list<array<string,mixed>> $questions
 * @var array<int,mixed> $drafts
 * @var int $answeredCount
 * @var float $maxScore
 * @var int $position
 * @var int $positions
 * @var int $itemId
 * @var int $attemptId
 * @var string $token
 * @var string $attemptUrl
 * @var bool $isLast
 * @var float $weight
 */
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
 <div class="page-header">
 <h1 class="page-title"><?= $view->e($title) ?></h1>
 <p class="muted">
 Задание <?= $view->e($position) ?> из <?= $view->e($positions) ?> ·
 максимум <?= $view->e($formatScore($maxScore)) ?> б. ·
 вес в итоге работы <?= $view->e($formatScore($weight)) ?>
 </p>
 </div>

 <?php /*
 Таймер рисует ядро: остаток времени считает оно, а плагин его только
 показывает. Без лимита таймера нет вовсе — пустой таймер читался бы как
 «время кончилось».
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
 него выходит сразу. Идентификатор достался в наследство от снятого
 шаблона самостоятельного теста, и когда тот убрали, скрипт перестал
 видеть форму — вместе с перетаскиванием в «Правильной последовательности»,
 таймером и защитой от повторной отправки. Ни один из них не был виден
 глазом: страница выглядела исправной, просто не работала.
 */ ?>

 <?= $view->raw($ui->hidden('t', $token)) ?>
 <?= $view->raw($view->csrfField()) ?>

 <?php foreach ($questions as $question): ?>
 <?= $view->raw($view->render('test/render', [
 'q' => $question,
 'stored' => $drafts[(int) $question['id']] ?? null,
 ], '')) ?>
 <?php endforeach; ?>

 <div class="page-actions">
 <?= $view->raw($ui->button('← Назад', [
     'variant' => 'btn-ghost',
     'name'    => 'action',
     'value'   => 'prev',
 ])) ?>

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
 Ответы сохраняются при каждом переходе, поэтому страницу можно
 обновить и вернуться позже. Ответы на <?= $view->e($answeredCount) ?>
 из <?= $view->e(count($questions)) ?> вопросов уже сохранены.
 </p>
</form>

<p>
 <a class="btn btn-ghost" href="<?= $view->e($attemptUrl) ?>">К списку заданий работы</a>
</p>

<script src="<?= $view->e($view->asset('assets/js/vendor/sortable.min.js')) ?>" defer></script>
<script src="<?= $view->e($view->asset('assets/js/attempt.js')) ?>" defer></script>
