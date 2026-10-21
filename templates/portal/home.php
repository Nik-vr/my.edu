<?php

declare(strict_types=1);

/**
 * Главная страница: всё, что доступно человеку прямо сейчас.
 *
 * Раньше такая страница была одна, а войти можно было в две: `/portal` для
 * ученика (его работы) и `/panel` для сотрудника (цифры и классы). Человек,
 * у которого были и работы, и классы, должен был помнить, по какому адресу
 * что лежит, и половина сведений оказывалась в стороне.
 *
 * Теперь страница одна, а сведения на ней все: и работы, которые ждут
 * человека, и — если права позволяют — цифры и классы школы.
 *
 * Порядок: работы идут первыми. По решению владельца все выдачи проходят
 * через работу, и именно её человек должен видеть на главной. Сведения о
 * школе идут следом: у ученика их нет вовсе, а у сотрудника работы обычно
 * короткие, и до сводок он дойдёт одним экраном.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $works
 * @var list<array<string,mixed>> $classes
 * @var array<string,mixed>|null $users
 * @var string $schoolName
 * @var \MyEdu\Auth\User $currentUser
 */
?>

<div class="page-header">
    <h1 class="page-title">Здравствуйте, <?= $view->e($currentUser->shortName()) ?></h1>
    <p class="muted">
        <?= $view->e($currentUser->rolesLabel()) ?>
        <?php if ($schoolName !== ''): ?>
        · Школа «<?= $view->e($schoolName) ?>»
        <?php endif; ?>
    </p>
    <p>
        <a class="btn btn-ghost" href="<?= $view->e($view->url('profile')) ?>">Мои данные</a>
    </p>
</div>

<h2 class="card-title">Работы</h2>

<?php if ($works === []): ?>
    <p class="muted">Работ пока нет. Как только учитель что-то выдаст, оно появится здесь.</p>
<?php else: ?>
    <ul class="test-list">
        <?php foreach ($works as $work): ?>
            <li class="test-card">
                <div class="test-card__body">
                    <h3 class="test-card__title">
                        <a href="<?= $view->e($view->url('work/' . (int) $work['id'])) ?>">
                            <?= $view->e($work['title']) ?>
                        </a>
                    </h3>

                    <?php if (!empty($work['description'])): ?>
                        <p class="test-card__desc"><?= $view->e($work['description']) ?></p>
                    <?php endif; ?>

                    <ul class="test-card__meta">
                        <?php if (!empty($work['subject_name'])): ?>
                            <li><?= $view->e($work['subject_name']) ?></li>
                        <?php endif; ?>
                        <li>
<?= $view->e($view->count((int) ($work['item_count']),  'задание', 'задания', 'заданий')) ?>
                        </li>
                        <?php if (!empty($work['time_limit'])): ?>
                            <li><?= $view->e((int) $work['time_limit']) ?> мин</li>
                        <?php endif; ?>
                        <?php if (($work['my_status'] ?? null) === 'finished'): ?>
                            <li class="status status-green">
                                сдано, <?= $view->e($work['my_percent'] === null ? '—' : $work['my_percent'] . '%') ?>
                            </li>
                        <?php elseif (($work['my_status'] ?? null) === 'in_progress'): ?>
                            <li class="status status-yellow">в процессе</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <a class="btn btn-primary" href="<?= $view->e($view->url('work/' . (int) $work['id'])) ?>">
                    <?= ($work['my_status'] ?? null) === 'in_progress' ? 'Продолжить' : 'Открыть' ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="muted">
        <a href="<?= $view->e($view->url('works')) ?>">Все работы</a>
    </p>
<?php endif; ?>

<?php /*
 Сведения о школе — только тем, кому положено их видеть.

 Ученику счётчики пользователей и список классов не нужны и не должны быть видны:
 классов он не ведёт, а «пользователей» посчитать не из чего. Права проверяет
 ядро: сводка просто не приходит тому, у кого их нет.
 */ ?>
<?php if ($users !== null || $classes !== [] || $currentUser->hasCapability('classes.manage')): ?>

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
<?php endif; ?>
