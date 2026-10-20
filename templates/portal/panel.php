<?php

declare(strict_types=1);

/**
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $classes
 * @var array<string,mixed>|null $users
 * @var string $schoolName
 */
?>
<div class="page-header">
    <h1 class="page-title">Панель управления</h1>
    <p class="muted">
        <?= $view->e($currentUser->rolesLabel()) ?>
    </p>
</div>

<?php if ($schoolName !== ''): ?>
    <p class="muted">Название школы: <strong><?= $view->e($schoolName) ?></strong></p>
<?php endif; ?>

<?php /*
 * Кнопок-ссылок на разделы здесь больше нет.
 *
 * Все разделы — «Настройки сайта», «Плагины», «Работы», «Результаты» —
 * стоят в боковом меню и подписаны там же. На главной странице панели они
 * оказывались вторым местом, где надо искать один и тот же раздел, и со
 * временем разъезжались с меню: добавили пункт туда, а про кнопку забыли.
 *
 * Панель теперь отвечает на вопрос «что сейчас происходит», а не «куда
 * можно пойти»: остаются цифры и классы. Сами разделы остались на месте.
 */ ?>

<?php if ($users !== null): ?>
    <div class="metric-row">
        <a class="metric" href="<?= $view->e($view->url('panel/users')) ?>">
            <span class="metric-value"><?= $view->e((int) $users['total']) ?></span>
            <span class="metric-label">пользователей</span>
        </a>
        <div class="metric">
            <span class="metric-value"><?= $view->e((int) $users['students']) ?></span>
            <span class="metric-label">учеников</span>
        </div>
        <div class="metric">
            <span class="metric-value"><?= $view->e((int) $users['classes']) ?></span>
            <span class="metric-label">классов</span>
        </div>
        <?php if ((int) $users['pending'] > 0): ?>
            <a class="metric metric--warn" href="<?= $view->e($view->url('panel/users')) ?>">
                <span class="metric-value"><?= $view->e((int) $users['pending']) ?></span>
                <span class="metric-label">ждут подтверждения</span>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($classes !== [] || $currentUser->hasCapability('classes.manage')): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Классы</h2>
            <?php if ($currentUser->hasCapability('classes.manage')): ?>
                <a class="btn btn-sm" href="<?= $view->e($view->url('classes')) ?>">Все классы</a>
            <?php endif; ?>
        </div>

        <?php if ($classes === []): ?>
            <p class="muted">Классов пока нет.</p>
        <?php else: ?>
            <ul class="chip-list">
                <?php foreach ($classes as $class): ?>
                    <li>
                        <a class="chip chip--link" href="<?= $view->e($view->url('classes/' . (int) $class['id'])) ?>">
                            <?= $view->e($class['name']) ?>
                            <?php if (!empty($class['title'])): ?>
                                <span class="chip__note"><?= $view->e($class['title']) ?></span>
                            <?php endif; ?>
                            <span class="chip__count"><?= $view->e((int) $class['student_count']) ?> уч.</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>