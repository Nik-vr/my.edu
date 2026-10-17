<?php

declare(strict_types=1);

/**
 * Регистрация сотрудника.
 *
 * Карточка .auth-card из шаблона gentelella — как и обе формы входа.
 * Регистрация доступна с вкладки сотрудника; в шапке кнопки нет.
 *
 * @var \MyEdu\Core\View $view
 * @var array{full_name:string,email:string} $old
 * @var \MyEdu\Core\MailSetup $mail
 * @var bool $isFirstRegistration признак, что владельца на сайте ещё нет
 */
?>
<div class="auth-card">
    <h1 class="auth-title">Регистрация сотрудника</h1>
    <p class="auth-subtitle">
        После регистрации на указанный адрес придёт письмо со ссылкой для
        подтверждения. Пока адрес не подтверждён, вход в систему невозможен.
    </p>

    <?php if ($mail->isLog()): ?>
        <?= $view->raw($view->bannerWarning(
            'На сервере настроена запись писем в журнал вместо отправки. '
            . 'Ссылка подтверждения находится в файле var/log/mail-*.log. '
            . 'Для реальной отправки укажите драйвер mail или smtp '
            . 'в config/config.local.php.'
        )) ?>
    <?php elseif ($mail->isLocal()): ?>
        <?php
        $localNotice = 'Сервер отправки — локальный, до почтового оператора письма не доходят.';

        if ($mail->webUi !== '') {
            $localNotice .= ' Откройте ' . $mail->webUi . ' — там лежит последнее письмо.';
        }

        $localNotice .= ' В журнал var/log/mail-*.log драйвер ' . $mail->label . ' ничего не пишет.';
        ?>
        <?= $view->raw($view->bannerWarning($localNotice)) ?>
    <?php else: ?>
        <p class="auth-subtitle">
            Письмо не пришло? Проверьте папку «Спам» или
            <a href="<?= $view->e($view->url('confirm/resend')) ?>">запросите письмо повторно</a>.
        </p>
    <?php endif; ?>

    <?php if ($isFirstRegistration): ?>
        <?php /*
         * Первая учётная запись — это уже не регистрация, а передача прав.
         * Человек обязан понимать, что после подтверждения станет владельцем
         * сайта: иначе первая же запись, созданная не тем, обрушила бы чужую
         * установку.
         */ ?>
        <?= $view->raw($view->bannerInfo(
            'Это будет первая учётная запись на сайте: после подтверждения адреса '
            . 'вы станете владельцем и администратором.'
        )) ?>
    <?php else: ?>
        <?= $view->raw($view->bannerInfo(
            'Регистрация создаёт учётную запись с ролью «Наблюдатель». '
            . 'Дополнительные права назначит администратор.'
        )) ?>
    <?php endif; ?>

    <form method="post" action="<?= $view->e($view->url('register')) ?>">
        <?= $view->raw($view->csrfField()) ?>

        <div class="form-group">
            <label class="form-label" for="register-name">Фамилия, имя и отчество</label>
            <input class="form-control" type="text" id="register-name" name="full_name"
                   value="<?= $view->e($old['full_name']) ?>"
                   required maxlength="191" autofocus autocomplete="name"
                   placeholder="Петроченко Николай Юрьевич">
            <div class="form-help">В этом порядке: фамилия, имя, отчество. Обязательны фамилия и имя.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="register-email">Электронная почта</label>
            <input class="form-control" type="email" id="register-email" name="email"
                   value="<?= $view->e($old['email']) ?>"
                   required maxlength="191" autocomplete="email">
        </div>

        <div class="form-group">
            <label class="form-label" for="register-password">Пароль</label>
            <input class="form-control" type="password" id="register-password" name="password"
                   required minlength="8" autocomplete="new-password">
            <div class="form-help">Не менее 8 символов.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="register-password2">Пароль ещё раз</label>
            <input class="form-control" type="password" id="register-password2" name="password2"
                   required minlength="8" autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">Зарегистрироваться</button>
    </form>

    <div class="auth-footer">
        Уже есть учётная запись? <a href="<?= $view->e($view->url('login')) ?>">Войти</a>
    </div>
</div>
