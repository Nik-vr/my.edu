<?php

declare(strict_types=1);

/**
 * Мои данные: собственная страница пользователя.
 *
 * Поля те же, что и на странице пользователя у администратора: фамилия, имя,
 * отчество, дата рождения, ссылка на ВКонтакте. Разница одна — здесь
 * заполняют только свои данные, там чужие.
 *
 * Разметка шаблона gentelella: .card / .card-header / .card-body, поля формы
 * с .is-invalid и подписью .form-error при отказе сервера.
 *
 * @var \MyEdu\Core\View $view
 * @var int $minLen
 * @var array<string,mixed>|null $row
 * @var array<string,string> $old
 * @var array<string,string> $errors
 */

$nameLast = $old['last_name'] ?? (string) ($row['last_name'] ?? '');
$nameFirst = $old['first_name'] ?? (string) ($row['first_name'] ?? '');
$nameMiddle = $old['middle_name'] ?? (string) ($row['middle_name'] ?? '');
$birthDate = $old['birth_date'] ?? (string) ($row['birth_date'] ?? '');
$vkValue = $old['vk_url'] ?? (string) ($row['vk_url'] ?? '');

$nameErrors = $errors;
$vkUrl = $row['vk_url'] ?? null;

$avatarUrl = (string) ($row['avatar_url'] ?? '');
$fullName = (string) ($currentUser->fullName ?? '');
?>
<?php /*
 * Сообщения сервера печатает оболочка.
 *
 * Раньше контроллер клал их в flash, а страница не выводила: «данные
 * сохранены», «пароль изменён» и «аватар сохранён» не появлялись нигде.
 * Пока страница печатала их сама, сообщение показывалось дважды — баннер
 * оболочки и баннер страницы. Теперь место одно.
 */ ?>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1 class="page-title">Мои данные</h1>
        </div>
    </div>
</div>

<?php /*
 * Аватар и загрузка — как в образце gentelella/profile.html: большой кружок
 * слева, справа имя с ролями и форма.
 *
 * Форма одна на оба действия: без файла и без кнопки «Убрать» отправляется
 * пустое намерение, и сервер просто ничего не делает. Две формы рядом путались
 * бы — выбрал файл, нажал «Убрать», а файл остался лежать на диске.
 */ ?>
<div class="card">
    <div class="card-body profile-head">
        <div class="profile-avatar<?= $avatarUrl === '' ? ' profile-avatar--initials' : '' ?>">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= $view->e($view->asset($avatarUrl)) ?>"
                     alt="Аватар: <?= $view->e($fullName) ?>" width="120" height="120">
            <?php else: ?>
                <?php /* Инициалы текстом, а не картинкой: файл не нужен, а
                 * инициалы остаются на месте даже там, где картинки нет. */ ?>
                <span class="profile-avatar__initials">
                    <?= $view->e($view->initials($fullName)) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="profile-head__text">
            <h2 class="card-title"><?= $view->e($fullName) ?></h2>
            <p class="muted"><?= $view->e($currentUser->rolesLabel()) ?></p>

            <form method="post"
                  action="<?= $view->e($view->url('profile/avatar')) ?>"
                  enctype="multipart/form-data"
                  class="profile-avatar-form">
                <?= $view->raw($view->csrfField()) ?>

                <div class="form-group">
                    <label class="form-label" for="profile-avatar">Аватар</label>
                    <input class="form-control"
                           type="file"
                           id="profile-avatar"
                           name="avatar"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                    <div class="form-help">
                        JPG, PNG, GIF или WEBP, не больше 3 МБ. Аватар виден тем,
                        у кого вы открываете список сотрудников.
                    </div>
                </div>

                <div class="profile-avatar-form__actions">
                    <button type="submit" class="btn btn-primary">Загрузить аватар</button>

                    <?php if ($avatarUrl !== ''): ?>
                        <?php /*
                         * Убрать — та же форма, другая кнопка. Отдельной формой
                         * было бы две почти одинаковые кнопки рядом, и одна из
                         * них отправляла бы форму с выбранным файлом.
                         */ ?>
                        <button type="submit" class="btn btn-danger"
                                name="remove" value="1"
                                data-confirm="Убрать аватар?"
                                data-confirm-body="Кружок с инициалами вернётся на место. Сам файл будет удалён с сервера."
                                data-confirm-yes="Убрать">
                            Убрать аватар
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <dl class="facts">
            <div class="settings-row">
                <dt class="form-label">Логин или e-mail</dt>
                <dd><?= $view->e($currentUser->login ?? '—') ?></dd>
            </div>
            <div class="settings-row">
                <dt class="form-label">Роли</dt>
                <dd><?= $view->e($currentUser->rolesLabel()) ?></dd>
            </div>
            <div class="settings-row">
                <dt class="form-label">Адрес подтверждён</dt>
                <dd><?= $currentUser->isVerified() ? 'да' : 'нет' ?></dd>
            </div>
            <?php if ($currentUser->birthDateRu() !== ''): ?>
                <div class="settings-row">
                    <dt class="form-label">Дата рождения</dt>
                    <dd><?= $view->e($currentUser->birthDateRu()) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">О себе</div>
    </div>
    <div class="card-body">
        <form method="post" action="<?= $view->e($view->url('profile')) ?>">
            <?= $view->raw($view->csrfField()) ?>

            <?php require __DIR__ . '/../_name_fields.php'; ?>

            <?= $view->raw($ui->field('birth_date', [
                'label' => 'Дата рождения',
                'type' => 'date',
                'value' => $birthDate,
                'max' => date('Y-m-d'),
                'autocomplete' => 'bday',
                'error' => $errors['birth_date'] ?? '',
                /*
                 * Возраст не хранится: он считается из даты рождения при
                 * чтении. Сохранённый возраст завтра стал бы неверным.
                 */
                'help' => 'Необязательно. Возраст считается сам и отдельно не хранится: сохранённый возраст завтра стал бы неверным.',
            ])) ?>

            <?= $view->raw($ui->field('vk_url', [
                'label' => 'ВКонтакте',
                'value' => $vkValue,
                'maxlength' => 191,
                'inputmode' => 'url',
                'placeholder' => 'vk.com/ivanov',
                'error' => $errors['vk_url'] ?? '',
                'help' => 'Ссылка на профиль: vk.com или vk.ru. Необязательно.',
            ])) ?>

            <button type="submit" class="btn btn-primary">Сохранить</button>
        </form>
    </div>
</div>

<?php if ($vkUrl !== null && $vkUrl !== ''): ?>
    <div class="card">
        <div class="card-header">
            <div class="card-title">Профиль ВКонтакте</div>
        </div>
        <div class="card-body">
            <a href="<?= $view->e($vkUrl) ?>" target="_blank" rel="noopener"><?= $view->e($vkUrl) ?></a>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">Смена пароля</div>
    </div>
    <div class="card-body">
        <form method="post" action="<?= $view->e($view->url('profile/password')) ?>">
            <?= $view->raw($view->csrfField()) ?>

            <div class="form-group">
                <label class="form-label" for="password-current">Текущий пароль</label>
                <input class="form-control" type="password" id="password-current"
                       name="current_password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label class="form-label" for="password-new">Новый пароль</label>
                <input class="form-control" type="password" id="password-new" name="new_password"
                       required minlength="<?= $view->e($minLen) ?>" autocomplete="new-password">
                <div class="form-help">Не менее <?= $view->e($minLen) ?> символов.</div>
            </div>

            <button type="submit" class="btn btn-primary">Сохранить пароль</button>
        </form>
    </div>
</div>