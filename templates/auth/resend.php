<?php

declare(strict_types=1);

/**
 * Повторная отправка письма о подтверждении.
 *
 * Карточка .auth-card из шаблона gentelella.
 *
 * @var \MyEdu\Core\View $view
 * @var \MyEdu\Core\MailSetup $mail
 */
?>
<div class="auth-card">
    <h1 class="auth-title">Отправить письмо повторно</h1>
    <p class="auth-subtitle">
        Укажите адрес, который вводили при регистрации. Если учётная запись
        зарегистрирована и ещё не подтверждена, мы отправим письмо со ссылкой.
    </p>

    <?php if ($mail->isLog()): ?>
        <?= $view->raw($view->bannerWarning(
            'Сейчас письма записываются в журнал, а не отправляются. '
            . 'Ссылка будет в файле var/log/mail-*.log.'
        )) ?>
    <?php endif; ?>

    <form method="post" action="<?= $view->e($view->url('confirm/resend')) ?>">
        <?= $view->raw($view->csrfField()) ?>

        <div class="form-group">
            <label class="form-label" for="resend-email">Электронная почта</label>
            <input class="form-control" type="email" id="resend-email" name="email"
                   required maxlength="191" autofocus autocomplete="email">
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Отправить письмо</button>
    </form>

    <div class="auth-footer">
        <a href="<?= $view->e($view->url('login')) ?>">Вернуться ко входу</a>
    </div>
</div>
