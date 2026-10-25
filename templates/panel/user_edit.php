<?php

declare(strict_types=1);

/**
 * Страница одного пользователя.
 *
 * Здесь всё, что можно поменять: данные, роли, статус, письмо со ссылкой
 * для смены пароля. В списке пользователей остались только имена и кнопка
 * «Редактировать» — править человека из общей таблицы неудобно, рядом
 * чужие строки.
 *
 * Учеников здесь нет: они заводятся и правятся в своём классе.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $target
 * @var list<string> $roles
 * @var array<string,string> $roleTitles
 * @var bool $isSelf
 * @var bool $isOwnerRow
 * @var array<string,string> $old
 */

$status = (string) $target['status'];
$email = (string) ($target['email'] ?? '');
$fullName = (string) $target['full_name'];
$nameLast = $old['last_name'] ?? (string) ($target['last_name'] ?? '');
$nameFirst = $old['first_name'] ?? (string) ($target['first_name'] ?? '');
$nameMiddle = $old['middle_name'] ?? (string) ($target['middle_name'] ?? '');
$birthDate = $old['birth_date'] ?? (string) ($target['birth_date'] ?? '');
$emailValue = $old['email'] ?? $email;
$vkValue = $old['vk_url'] ?? (string) ($target['vk_url'] ?? '');

/*
 * Роль одна, поэтому и подпись одна. Пустой набор — не «нулевая роль», а
 * «не назначена»: без неё человек не видит ни одной строки, а на странице
 * списка он числится без должности.
 */
$roleTitle = $roles === []
    ? 'не назначена'
    : ($roleTitles[$roles[0]] ?? $roles[0]);

$avatarUrl = (string) ($target['avatar_url'] ?? '');

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Пользователи', 'url' => 'panel/users'],
        ['label' => $view->e($fullName)],
    ]);
?>

<?php
/*
 * Сообщения выводит оболочка: она получает те же данные и печатает их сама,
 * над содержимым страницы. Здесь они печатались вторым баннером — учитель
 * видел одну и ту же фразу дважды и на разных высотах, а это читается как
 * два разных сообщения.
 */
?>
<?php
/*
 * Заголовок страницы — имя сотрудника и отметки при нём.
 *
 * Раньше заголовок был первым уровнем внутри карточки, и имя уезжало внутрь
 * формы вместе с аватаром и полями: страница начиналась с того, что
 * редактируется, а не с того, что это. Заголовок стоит над содержимым и на
 * всех остальных страницах панели.
 */
ob_start();
?>
 <?= $view->e($fullName) ?>
 <?php if ((int) $target['is_owner'] === 1): ?>
 <span class="status status-yellow">владелец</span>
 <?php endif; ?>
 <?php if ($isSelf): ?>
 <span class="chip">это вы</span>
 <?php endif; ?>
<?php
$titleRaw = (string) ob_get_clean();

require __DIR__ . '/_page_header.php';
?>

<div class="card">

<?php /*
 * Аватар: кружок и форма. Форма одна на загрузку и удаление — по нажатой
 * кнопке, ровно как на странице своих данных.
 *
 * Загрузить может администратор: сотрудник не всегда сам заходит на сайт, а
 * фотографию сотрудника знает именно тот, кто его завёл.
 */
?>
 <div class="card-body profile-head">
  <div class="profile-avatar<?= $avatarUrl === '' ? ' profile-avatar--initials' : '' ?>">
  <?php if ($avatarUrl !== ''): ?>
   <img src="<?= $view->e($view->asset($avatarUrl)) ?>"
        alt="Аватар: <?= $view->e($fullName) ?>" width="120" height="120">
  <?php else: ?>
   <span class="profile-avatar__initials"><?= $view->e($view->initials($fullName)) ?></span>
  <?php endif; ?>
  </div>

  <div class="profile-head__text">
   <form method="post"
         action="<?= $view->e($view->url('panel/users/' . (int) $target['id'] . '/avatar')) ?>"
         enctype="multipart/form-data"
         class="profile-avatar-form">
    <?= $view->raw($view->csrfField()) ?>

    <div class="form-group">
     <label class="form-label" for="user-avatar">Аватар сотрудника</label>
     <input class="form-control"
            type="file"
            id="user-avatar"
            name="avatar"
            accept="image/jpeg,image/png,image/gif,image/webp">
     <div class="form-help">
      JPG, PNG, GIF или WEBP, не больше 3 МБ. Аватар виден в списке
      сотрудников.
     </div>
    </div>

    <div class="profile-avatar-form__actions">
     <button type="submit" class="btn btn-primary">Загрузить аватар</button>

     <?php if ($avatarUrl !== ''): ?>
      <button type="submit" class="btn btn-danger"
              name="remove" value="1"
              data-confirm="Убрать аватар у <?= $view->e($fullName) ?>?"
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
<dl class="facts">
<div class="settings-row">
<dt>Логин</dt>
 <dd><?= $view->e($target['login'] ?? '—') ?></dd>
 </div>
<div class="settings-row">
<dt>Роль</dt>
<dd><?= $view->e($roleTitle) ?></dd>
</div>
 <div class="settings-row">
 <dt>Статус</dt>
 <dd>
 <?php if ($status === 'active'): ?>
 <span class="chip">активен</span>
 <?php elseif ($status === 'pending'): ?>
 <span class="status status-yellow">не подтвердил e-mail</span>
 <?php else: ?>
 <span class="status status-red">заблокирован</span>
 <?php endif; ?>
 </dd>
 </div>
 <div class="settings-row">
 <dt>Адрес подтверждён</dt>
 <dd><?= $target['email_verified_at'] === null ? 'нет' : 'да' ?></dd>
 </div>
 </dl>
</div>

<div class="card">
 <h2 class="card-title">Данные</h2>

 <form method="post" action="<?= $view->e($view->url('panel/users/' . (int) $target['id'])) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <?php require __DIR__ . '/../_name_fields.php'; ?>

 <label class="form-group">
 <span class="form-label">Дата рождения</span>
 <input type="date" name="birth_date" value="<?= $view->e($birthDate) ?>"
 max="<?= $view->e(date('Y-m-d')) ?>">
 <span class="form-help">
 Необязательно.
 </span>
 </label>

 <label class="form-group">
 <span class="form-label">Адрес почты</span>
 <input type="email" name="email" maxlength="191" autocomplete="email"
 value="<?= $view->e($emailValue) ?>"
 placeholder="ivanov@example.ru">
 <span class="form-help">
 На него приходят письма для смены пароля. При смене адреса
 пользователь получит ссылку подтверждения; до подтверждения
 адреса вход в систему будет недоступен.
 </span>
 </label>

 <label class="form-group">
 <span class="form-label">ВКонтакте</span>
 <input type="text" name="vk_url" maxlength="191" inputmode="url"
 value="<?= $view->e($vkValue) ?>"
 placeholder="vk.com/ivanov"
 data-hint="Ссылка должна вести на профиль ВКонтакте: vk.com или vk.ru">
 <span class="form-help">
 Ссылка на профиль: vk.com или vk.ru. Необязательно.
 </span>
 </label>

 <button type="submit" class="btn btn-primary">Сохранить</button>
 </form>
</div>

<div class="card">
 <h2 class="card-title">Роль</h2>

 <p class="muted">
 <?php /* Роль меняется одним выбором в списке сотрудников, и здесь она
  * показывается фактом, а не формой.
  *
  * Две формы правки одного и того же в одном разделе расходятся при первом же
  * изменении: сегодня список даёт одну роль, страница сотрудника — набор, и
  * человек видит две разные правды об одном человеке.
  */ ?>
 <?= $view->e($roleTitle) ?>
 </p>

 <p class="form-help">
 Роль у человека одна и меняется в списке сотрудников.
 <?php if ($isOwnerRow): ?>
  У владельца сайта она всегда «Администратор» — снять её нельзя.
 <?php elseif ($isSelf): ?>
  Свою роль из этого списка не меняют: попросите другого администратора.
 <?php endif; ?>
 Первый подтвердивший адрес пользователь становится владельцем сайта;
 остальные администраторы равны между собой.
 </p>

 <?php if (!$isSelf): ?>
 <a class="btn" href="<?= $view->e($view->url('panel/users')) ?>">Сменить роль в списке</a>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">Пароль</h2>

 <p class="card-subtitle">
 Администратор пароль не видит и задать не может. Кнопка ниже отправляет
 пользователю письмо со ссылкой, а новый пароль он вводит сам.
 </p>

 <?php if ($isSelf): ?>
 <p class="muted">Свой пароль меняется в личном кабинете.</p>
  <?php elseif ($email === ''): ?>
  <?= $view->raw($view->bannerWarning(
      'У пользователя нет адреса почты — письмо со ссылкой отправить некуда. '
      . 'Пароль ему выдаёт завуч на бумаге.'
  )) ?>
 <?php else: ?>
 <form method="post"
 action="<?= $view->e($view->url('panel/users/password')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="user_id" value="<?= $view->e((int) $target['id']) ?>">
 <button type="submit" class="btn">Отправить ссылку для смены пароля</button>
 </form>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">Доступ</h2>

 <?php if ($isSelf): ?>
 <p class="muted">Себя заблокировать нельзя.</p>
 <?php elseif ($isOwnerRow): ?>
 <p class="muted">Владельца сайта блокировать нельзя.</p>
 <?php else: ?>
 <form method="post"
 action="<?= $view->e($view->url('panel/users/status')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="user_id" value="<?= $view->e((int) $target['id']) ?>">
 <input type="hidden" name="status" value="<?= $status === 'blocked' ? 'active' : 'blocked' ?>">
 <button type="submit" class="btn <?= $status === 'blocked' ? '' : 'btn-danger' ?>">
 <?= $status === 'blocked' ? 'Разблокировать' : 'Заблокировать' ?>
 </button>
 </form>
 <?php endif; ?>
</div>
