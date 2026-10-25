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
 * Кнопки навигации, таймер и подпись «кто проходит» рисует оболочка в шапке
 * страницы: решение владельца от 24.10.2026 — во время задания главное —
 * текущий вопрос и оставшееся время, а список заданий и панель внизу
 * отвлекают. Ссылка на список заданий убрана совсем: список на время
 * прохождения скрыт.
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
 * @var float $weight
 * @var string $title название работы — уходит в <title> вкладки браузера
 * @var string $heading заголовок задания в теле страницы: «Задание №N»
 */
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
 <div class="page-header">
 <h1 class="page-title"><?= $view->e($heading) ?></h1>
 <p class="muted">
 Задание <?= $view->e($position) ?> из <?= $view->e($positions) ?> ·
 максимум <?= $view->e($formatScore($maxScore)) ?> б. ·
 вес в итоге работы <?= $view->e($formatScore($weight)) ?>
 </p>
 </div>

<?php /*
 Таймер теперь живёт в шапке страницы (его рисует оболочка по данным
 шапки прохождения) — тем же частичным шаблоном и с теми же
 идентификаторами, поэтому отсчёт ведёт attempt.js как прежде.
*/ ?>

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

 <p class="muted">
 Ответы сохраняются при каждом переходе, поэтому страницу можно
 обновить и вернуться позже. Ответы на <?= $view->e($answeredCount) ?>
 из <?= $view->e(count($questions)) ?> вопросов уже сохранены.
 </p>
</form>

<script src="<?= $view->e($view->asset('assets/js/vendor/sortable.min.js')) ?>" defer></script>
<script src="<?= $view->e($view->asset('assets/js/attempt.js')) ?>" defer></script>
