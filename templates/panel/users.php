<?php

declare(strict_types=1);

/**
 * Пользователи сайта.
 *
 * Всё, что можно поменять, меняется прямо в строке: роль — выбором из списка,
 * блокировка — пунктом меню. Отдельная страница пользователя осталась для
 * данных (фамилия, имя, дата рождения, аватар) — это длинная форма, и в строке
 * таблицы ей не место.
 *
 * Роль у человека одна. Права от роли не отделились: у завуч уже есть все права
 * учителя, поэтому отдельная должность «Учитель» ему не нужна.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $users
 * @var array<string,string> $roleTitles
 * @var array{total:int,total_active:int,teachers:int,teachers_active:int,
 *           head_teachers:int,head_teachers_active:int,without_role:int} $stats
 */

use MyEdu\Auth\Role;

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Пользователи'],
    ]);

/*
 * Статус в таблице — состояние записи: активна, ждёт подтверждения или
 * заблокирована. Раньше здесь была «заходил за последний год», и ручная
 * правка `status = active` в базе на странице не была видна: правка проходила,
 * а колонка показывала другое измерение — давность захода.
 *
 * Давность никуда не делась: её печатает соседняя колонка «Последняя
 * активность», и это её настоящее место — дата, а не состояние.
 *
 * Блоки сверху считают другое и так и должны: «заходили за последний год».
 * Раньше там стояло «активны сейчас», и слово «активен» на одной странице
 * означало два разных состояния — статус записи и недавний заход.
 */
?>

<?php
/*
 * Сообщения выводит оболочка: она получает те же данные и печатает их сама,
 * над содержимым страницы. Здесь они печатались вторым баннером — учитель
 * видел одну и ту же фразу дважды и на разных высотах, а это читается как
 * два разных сообщения.
 */
?>
<div class="row col-4 stats-row">
    <div class="card">
        <div class="stat">
            <div class="stat-icon teal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M5 20c0-3.9 3.1-7 7-7s7 3.1 7 7"/>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-label">Всего пользователей</div>
                <div class="stat-value-row"><span class="stat-value"><?= (int) $stats['total'] ?></span></div>
                <div class="stat-subtext">
                    <?= $view->e($view->plural((int) $stats['total_active'], 'заходил за последний год', 'заходили за последний год', 'заходили за последний год')) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="stat">
            <div class="stat-icon blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M12 1l3 6 6 .9-4.5 4.4 1 6.7L12 16l-5.5 3 1-6.7L3 7.9 9 7z"/>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-label">Учителей</div>
                <div class="stat-value-row"><span class="stat-value"><?= (int) $stats['teachers'] ?></span></div>
                <div class="stat-subtext">
                    <?= $view->e($view->plural((int) $stats['teachers_active'], 'заходил за последний год', 'заходили за последний год', 'заходили за последний год')) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="stat">
            <div class="stat-icon purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-label">Завучей</div>
                <div class="stat-value-row"><span class="stat-value"><?= (int) $stats['head_teachers'] ?></span></div>
                <div class="stat-subtext">
                    <?= $view->e($view->plural((int) $stats['head_teachers_active'], 'заходил за последний год', 'заходили за последний год', 'заходили за последний год')) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="stat">
            <div class="stat-icon yellow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-label">Без должности</div>
                <div class="stat-value-row"><span class="stat-value"><?= (int) $stats['without_role'] ?></span></div>
                <div class="stat-subtext">
                    <?= $view->e($view->plural((int) $stats['without_role'], 'требует внимания', 'требуют внимания', 'требуют внимания')) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$title = 'Сотрудники';
require __DIR__ . '/_page_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table" data-datatable data-page-length="25">
            <thead>
                <tr>
                    <th scope="col">Пользователь</th>
                    <th scope="col">Роль</th>
                    <th scope="col">Статус</th>
                    <th scope="col">Последняя активность</th>
                    <th scope="col">Присоединился</th>
                    <th scope="col" data-orderable="false"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                    $userId = (int) $row['id'];
                    $roles = $row['role_list'] === null || $row['role_list'] === ''
                        ? []
                        : explode(',', (string) $row['role_list']);
                    $role = $roles[0] ?? '';
                    $isOwner = (int) $row['is_owner'] === 1;
                    $isSelf = $userId === (int) $currentUser->id;
                    $status = (string) $row['status'];
                    ?>
                    <tr<?= $isOwner ? ' class="is-owner"' : '' ?>>
                        <td>
                            <div class="cell-customer">
                                <?php if (($row['avatar_url'] ?? null) !== null && $row['avatar_url'] !== ''): ?>
                                    <img class="cell-avatar" src="<?= $view->e($view->asset((string) $row['avatar_url'])) ?>"
                                         alt="" width="24" height="24">
                                <?php else: ?>
<?php /* Цвет задан классом, а не стилем в разметке:
                                     * style-src 'self' такие стили отбрасывает, и
                                     * кружок оставался бы пустым. */ ?>
                                    <div class="cell-avatar<?= $isOwner ? ' cell-avatar--owner' : '' ?>">
                                        <?= $view->e($view->initials((string) $row['full_name'])) ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="cell-strong">
                                        <?= $view->e($row['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="chip">это вы</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cell-subtext">
                                        <?= $view->e($row['email'] ?? $row['login'] ?? '—') ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <?php if ($isOwner): ?>
                                <span class="role-chip">Владелец</span>
                            <?php elseif ($isSelf): ?>
                                <?php /* Свою роль не даём менять здесь: это тот же
                                * запрет, что и на странице пользователя, только
                                * без лишней страницы. */ ?>
                                <span class="role-chip"><?= $view->e(Role::title($role)) ?></span>
                            <?php else: ?>
                                <?php /*
                                 * Смена роли — меню строки, а не выпадающий
                                 * список с кнопкой «Сохранить».
                                 *
                                 * Список требовал двух нажатий и держал на
                                 * странице столько же форм, сколько
                                 * сотрудников: на двадцати пяти строках это
                                 * двадцать пять списков и двадцать пять
                                 * кнопок. В меню роль выбирается одним
                                 * нажатием, и открывается оно без скриптов —
                                 * как и меню действий в последнем столбце.
                                 *
                                 * Пункты — кнопки отправки одной формы: браузер
                                 * сам отправляет значение только нажатой
                                 * кнопки, отдельные формы не нужны.
                                 */ ?>
                                <details class="row-menu row-menu--role">
                                    <summary class="row-menu__current">
                                        <span class="role-chip"><?= $view->e(Role::title($role)) ?></span>
                                        <?php $icon = 'more'; ?>
                                        <?php require __DIR__ . '/../_action_icon.php'; ?>
                                        <span class="visually-hidden">
                                            Сменить роль: <?= $view->e($row['full_name']) ?>
                                        </span>
                                    </summary>
                                    <div class="menu-popover" role="menu">
                                        <form method="post"
                                              action="<?= $view->e($view->url('panel/users/role')) ?>">
                                            <?= $view->raw($view->csrfField()) ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $userId ?>">

                                            <?php foreach (Role::assignable() as $option): ?>
                                                <button type="submit"
                                                        class="menu-item<?= $option === $role ? ' menu-item--current' : '' ?>"
                                                        role="menuitem"
                                                        name="role"
                                                        value="<?= $view->e($option) ?>"
                                                        <?= $option === $role ? 'aria-current="true"' : '' ?>>
                                                    <?= $view->e(Role::title($option)) ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php /*
                             * Три состояния, и все из `users.status`. Неактивных
                             * в этом смысле не бывает: человек либо активен,
                             * либо ещё не подтвердил адрес, либо заблокирован.
                             */ ?>
                            <?php if ($status === 'blocked'): ?>
                                <span class="status status-red">Заблокирован</span>
                            <?php elseif ($status === 'pending'): ?>
                                <span class="status status-yellow">Ожидает подтверждения</span>
                            <?php else: ?>
                                <span class="status status-green">Активный</span>
                            <?php endif; ?>
                        </td>

                        <td class="nowrap cell-muted">
                            <?php if (($row['last_login_at'] ?? null) === null): ?>
                                ещё не заходил
                            <?php else: ?>
                                <?= $view->e(date('d.m.Y H:i', strtotime((string) $row['last_login_at']))) ?>
                            <?php endif; ?>
                        </td>

                        <td class="nowrap cell-muted">
                            <?= $view->e(date('d.m.Y', strtotime((string) $row['created_at']))) ?>
                        </td>

                        <td class="row-actions">
                            <details class="row-menu">
                                <summary class="card-opt-btn"
                                         aria-label="Действия: <?= $view->e($row['full_name']) ?>">
                                    <?php $icon = 'more'; ?>
                                    <?php require __DIR__ . '/../_action_icon.php'; ?>
                                </summary>
                                <div class="menu-popover" role="menu">
                                    <a class="menu-item" role="menuitem"
                                       href="<?= $view->e($view->url('panel/users/' . $userId)) ?>">
                                        Редактировать профиль
                                    </a>

                                    <?php if ($status === 'blocked'): ?>
                                        <form method="post"
                                              action="<?= $view->e($view->url('panel/users/status')) ?>">
                                            <?= $view->raw($view->csrfField()) ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="menu-item" role="menuitem">
                                                Разблокировать
                                            </button>
                                        </form>
                                    <?php elseif (!$isSelf): ?>
                                        <div class="menu-separator" role="separator"></div>
                                        <form method="post"
                                              action="<?= $view->e($view->url('panel/users/status')) ?>">
                                            <?= $view->raw($view->csrfField()) ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                                            <input type="hidden" name="status" value="blocked">
                                            <button type="submit" class="menu-item menu-item--danger"
                                                    role="menuitem"
                                                    data-confirm="Заблокировать <?= $view->e($row['full_name']) ?>?"
                                                    data-confirm-body="Человек не сможет войти на сайт, пока
блокировку не снимут. Логины, пароли и история попыток останутся на месте."
                                                    data-confirm-yes="Заблокировать">
                                                Заблокировать
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($users === []): ?>
    <p class="muted">Пользователей пока нет.</p>
<?php endif; ?>