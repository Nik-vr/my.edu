<?php

declare(strict_types=1);

/**
 * Вход для сотрудника.
 *
 * Форма отдельная от ученической: сотрудник входит по адресу электронной почты,
 * а ученик — по логину с листа. Одной формой тот или другой обязательно
 * получает поле, которым не пользуется, и не получает то, которым пользуется.
 *
 * Регистрация живёт здесь, а не в шапке: регистрация дело сотрудника, и рядом
 * с формой, которой человек воспользуется, её найти естественно.
 *
 * Сообщения об отказе и об успехе не выводятся здесь: их показывает оболочка,
 * одна на все страницы вне панели. Иначе каждая форма выводила бы их сама, и
 * сообщение об ошибке входа появлялось бы на странице дважды.
 *
 * @var \MyEdu\Core\View $view
 * @var ?string $old
 * @var bool $needEmail
 */
?>
<div class="auth-card">
 <h1 class="auth-title">Вход для учителя</h1>
 <p class="auth-subtitle">
 Сотрудники входят по адресу электронной почты, указанному при регистрации.
 </p>

 <form method="post" action="<?= $view->e($view->url('login')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <div class="form-group">
 <label class="form-label" for="staff-identifier">Логин или e-mail</label>
 <input
class="form-control"
 type="text"
 id="staff-identifier"
 name="identifier"
 value="<?= $view->e($old ?? '') ?>"
 required
 autofocus
 autocomplete="username">
 </div>

 <div class="form-group">
 <label class="form-label" for="staff-password">Пароль</label>
 <input class="form-control" type="password" id="staff-password" name="password"
 required autocomplete="current-password">
 </div>

 <button type="submit" class="btn btn-primary btn-lg btn-block">Войти</button>
 </form>

 <div class="auth-actions">
 <a href="<?= $view->e($view->url('auth/forgot')) ?>">Забыли пароль?</a>
 <a href="<?= $view->e($view->url('register')) ?>">Зарегистрироваться</a>
 </div>

 <div class="auth-divider">Не пришло письмо о подтверждении</div>

 <div class="auth-footer">
 <a href="<?= $view->e($view->url('confirm/resend')) ?>">Отправить письмо снова</a>
 </div>
</div>