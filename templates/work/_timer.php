<?php

declare(strict_types=1);

/**
 * Таймер оставшегося времени на попытку.
 *
 * Показывается ученику, когда у работы задан лимит времени. Без лимита таймера
 * нет: пустой таймер выглядел бы как «время кончилось», хотя ученик только
 * начал.
 *
 * Разметка под скрипт `attempt.js`: счётчик с идентификатором `timer-value`,
 * коробка `timer` (ей скрипт вешает классы предупреждения), и подсказка
 * `submit-hint`, которую скрипт переписывает, когда время вышло.
 *
 * Ожидает:
 * $view, $remaining
 *
 * @var \MyEdu\Core\View $view
 * @var int|null $remaining сколько секунд осталось; null — лимита нет
 */

$remaining = $remaining ?? null;

if ($remaining === null || $remaining < 0) {
    return;
}

$minutes = intdiv($remaining, 60);
$seconds = $remaining % 60;
?>

<div class="timer" id="timer">
 <span class="timer__label">Осталось</span>
 <strong class="timer__value" id="timer-value">
 <?= $view->e(str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $seconds, 2, '0', STR_PAD_LEFT)) ?>
 </strong>
</div>
