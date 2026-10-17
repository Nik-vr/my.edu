<?php

declare(strict_types=1);

/**
 * Ответ ученика для ручной проверки учителем.
 *
 * Показывается всё, что ученик ввёл, независимо от того, проверено оно или
 * нет: проверять не видя ответа бессмысленно.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var string $answerText
 * @var string $prompt
 */
?>
<div class="card">
    <h3 class="card-title"><?= $view->e($title) ?></h3>

    <p class="question__prompt"><?= $view->raw($view->rich($prompt)) ?></p>

    <p>Ответ ученика: <strong><?= $view->e($answerText) ?></strong></p>
</div>