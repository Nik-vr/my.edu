<?php

declare(strict_types=1);

/**
 * Подтверждение адреса электронной почты по токену из письма.
 *
 * Карточка .auth-card из шаблона gentelella.
 *
 * @var \MyEdu\Core\View $view
 * @var string $token
 */
?>
<div class="auth-card">
    <h1 class="auth-title">Подтвердите адрес электронной почты</h1>
    <p class="auth-subtitle">
        Нажмите кнопку ниже, чтобы подтвердить адрес и завершить регистрацию.
    </p>

    <form method="post" action="<?= $view->e($view->url('auth/confirm')) ?>">
        <?= $view->raw($view->csrfField()) ?>
        <input type="hidden" name="token" value="<?= $view->e($token) ?>">
        <button type="submit" class="btn btn-primary btn-lg btn-block">Подтвердить адрес</button>
    </form>
</div>