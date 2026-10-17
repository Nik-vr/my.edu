<?php

declare(strict_types=1);

/**
 * Главная страница: что пользователю доступно прямо сейчас.
 *
 * Только работы: по решению владелька проекта все выдачи идут через них, а
 * самостоятельных заданий больше нет.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $works
 * @var \MyEdu\Auth\User $currentUser
 */
?>
<div class="page-header">
    <h1 class="page-title">Здравствуйте, <?= $view->e($currentUser->shortName()) ?></h1>
    <p class="muted">Выберите, что выполнять.</p>
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
                            <?= $view->e((int) $work['item_count']) ?>
                            <?= $view->e($view->plural((int) $work['item_count'], 'задание', 'задания', 'заданий')) ?>
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
<?php
/*
 * Блок «Отдельные задания» убран вместе с самостоятельными тестами.
 *
 * Он был единственным потребителем catalogue(): список собирался из всех
 * подключённых плагинов и показывал то, что не входит ни в одну работу.
 * Теперь выдача идёт только через работы, и список на главной состоял бы
 * из одной пустой секции с объяснением, зачем она.
 */
?>