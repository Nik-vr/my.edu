<?php

declare(strict_types=1);

/**
 * Вход для ученика.
 *
 * Форма отдельная и намеренно короткая. Ученик приходит по листу, который
 * напечатал учитель: там логин и пароль, больше ничего. Общая форма предлагала
 * ему e-mail и кнопку регистрации — то есть два способа, которыми он не
 * пользуется, и ни одного, которым пользуется.
 *
 * Выбор между формами — вкладки в шапке, поэтому своей ссылки на форму
 * сотрудников здесь нет: она была вторым, ничем не лучшим способом сделать то
 * же, что делает вкладка рядом с заголовком.
 *
 * Сообщения об отказе и об успехе выводит оболочка: см. login.php.
 *
 * @var \MyEdu\Core\View $view
 * @var string $old
 */
?>
<div class="auth-card">
 <h1 class="auth-title">Вход для ученика</h1>
 <p class="auth-subtitle">
 Введите логин и пароль, выданные учителем. Они напечатаны на листе
 с вашим именем.
 </p>

 <form method="post" action="<?= $view->e($view->url('student/login')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <div class="form-group">
 <label class="form-label" for="student-identifier">Логин</label>
 <input
class="form-control"
 type="text"
 id="student-identifier"
 name="identifier"
 value="<?= $view->e($old ?? '') ?>"
 required
 autofocus
 autocomplete="username"
 autocapitalize="characters"
 spellcheck="false">
 </div>

 <div class="form-group">
 <label class="form-label" for="student-password">Пароль</label>
 <input class="form-control" type="password" id="student-password" name="password"
 required autocomplete="current-password">
 </div>

 <button type="submit" class="btn btn-primary btn-lg btn-block">Войти</button>
 </form>
</div>