<?php

declare(strict_types=1);

/**
 * Задание нового пароля по ссылке из письма.
 *
 * Карточка .auth-card из шаблона gentelella.
 *
 * @var \MyEdu\Core\View $view
 * @var string $token
 * @var int $minLen
 */
?>
<div class="auth-card">
    <h1 class="auth-title">Новый пароль</h1>
    <p class="auth-subtitle">
        Придумайте новый пароль. После смены он будет действовать сразу.
    </p>

    <form method="post" action="<?= $view->e($view->url('auth/reset')) ?>">
        <?= $view->raw($view->csrfField()) ?>
        <input type="hidden" name="token" value="<?= $view->e($token) ?>">

        <div class="form-group">
            <label class="form-label" for="reset-password">Новый пароль</label>
            <input class="form-control" type="password" id="reset-password" name="password"
                   required minlength="<?= $view->e($minLen) ?>" autofocus autocomplete="new-password">
            <div class="form-help">Не менее <?= $view->e($minLen) ?> символов.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="reset-password2">Новый пароль ещё раз</label>
            <input class="form-control" type="password" id="reset-password2" name="password2"
                   required minlength="<?= $view->e($minLen) ?>" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Сохранить пароль</button>
    </form>
</div>