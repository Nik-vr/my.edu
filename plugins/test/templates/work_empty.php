<?php

declare(strict_types=1);

/**
 * Задание без вопросов.
 *
 * Обычно это ошибка автора: он создал тест, но не наполнил его. Ученику
 * говорим прямо и возвращаем к списку, чтобы он не сидел на пустой странице.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var int $position
 * @var int $total
 * @var string $attemptUrl
 */
?>
<div class="card ">
 <h1 class="card-title"><?= $view->e($title) ?></h1>

 <p class="card-subtitle">
 Задание <?= $view->e($position) ?> из <?= $view->e($total) ?> пока пусто:
 в нём нет ни одного вопроса.
 </p>

 <p class="muted">Сообщите учителю: скорее всего, он ещё не наполнил это задание.</p>

 <p>
 <a class="btn btn-primary" href="<?= $view->e($attemptUrl) ?>">
 К списку заданий работы
 </a>
 </p>
</div>
