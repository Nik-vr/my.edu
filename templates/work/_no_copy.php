<?php

declare(strict_types=1);

/**
 * Подключение скрипта запрета копирования.
 *
 * Скрипт нужен только там, где учитель его включил, и подключается один раз
 * на страницу прохождения. Ожидает:
 *
 * $view, $noCopy
 *
 * @var \MyEdu\Core\View $view
 * @var bool $noCopy
 */

if (empty($noCopy)) {
    return;
}
?>

<script src="<?= $view->e($view->asset('assets/js/no-copy.js')) ?>" defer></script>
