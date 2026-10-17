<?php

declare(strict_types=1);

/**
 * @var \MyEdu\Core\View $view
 * @var int $status
 * @var string $message
 * @var string $appName
 */
?>
<div class="card">
 <p class="error__code"><?= $view->e($status) ?></p>
 <h1 class="error__title"><?= $view->e($message) ?></h1>

 <p class="muted">
 Проверьте адрес страницы или вернитесь туда, откуда пришли.
 </p>

 <p class="actions">
 <?php if (!empty($currentUser)): ?>
 <a class="btn btn-primary" href="<?= $view->e($view->url('portal')) ?>">В кабинет</a>
 <a class="btn" href="<?= $view->e($view->url('')) ?>">На главную</a>
 <?php else: ?>
 <a class="btn btn-primary" href="<?= $view->e($view->url('login')) ?>">Войти</a>
 <?php endif; ?>
 </p>
</div>