<?php

declare(strict_types=1);

/**
 * Восстановление пароля по почте.
 *
 * Карточка .auth-card из шаблона gentelella — как и обе формы входа.
 *
 * @var \MyEdu\Core\View $view
 * @var \MyEdu\Core\MailSetup $mail
 */
?>
<div class="auth-card">
    <h1 class="auth-title">Восстановление пароля</h1>
    <p class="auth-subtitle">
        Укажите адрес электронной почты — мы отправим письмо со ссылкой
        для задания нового пароля.
    </p>

    <?php if ($mail->isLog()): ?>
        <?php /*
         * Письма пишутся в журнал, а не отправляются. Человек уходит со
         * страницы и ждёт письма, которого не будет: подсказка нужна прямо
         * здесь, а не в журнале на сервере.
         */ ?>
        <?= $view->raw($view->bannerWarning(
            'Сейчас письма записываются в журнал, а не отправляются. '
            . 'Ссылка будет в файле var/log/mail-*.log.'
        )) ?>
    <?php endif; ?>

    <form method="post" action="<?= $view->e($view->url('auth/forgot')) ?>">
        <?= $view->raw($view->csrfField()) ?>

        <div class="form-group">
            <label class="form-label" for="forgot-email">Электронная почта</label>
            <input class="form-control" type="email" id="forgot-email" name="email"
                   required maxlength="191" autofocus autocomplete="email">
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Отправить письмо</button>
    </form>

    <div class="auth-footer">
        <a href="<?= $view->e($view->url('login')) ?>">Вернуться ко входу</a>
    </div>
</div>
