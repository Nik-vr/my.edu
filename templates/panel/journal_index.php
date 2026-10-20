<?php

declare(strict_types=1);

/**
 * Результаты: выданные работы, собранные по классам.
 *
 * Сверху — по одному разделу на класс, внутри — работы, которые этому классу
 * выдавали. Класс взят из выдачи, то есть тот, в котором ученик состоял на
 * момент выдачи.
 *
 * Название работы — ссылка на её результаты: отдельная кнопка рядом была бы
 * второй точкой входа на ту же страницу.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $groups
 */

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Результаты', 'url' => ''],
    ]);
?>

<h1 class="page-title">Результаты

</h1>

<div class="card">
    <p class="card-subtitle">
        Какие работы выдавали и кто их сдавал. Откройте работу — внутри вкладки
        с классами, которым её выдавали, и результаты по каждому ученику.
    </p>
</div>

<?php if ($groups === []): ?>

<div class="card">
    <p class="muted">
        Выданных работ пока нет. Журнал наполняется сам: как только работу
        кому-то выдадут, она появится здесь.
    </p>
</div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<section class="work-folder">
    <details class="work-folder__box" open>
    <summary class="work-folder__title">
        <span class="work-folder__name"><?= $view->e($group['name']) ?></span>
        <span class="work-folder__count">
            <?= $view->e($view->count(count($group['works']), 'работа', 'работы', 'работ')) ?>
        </span>
    </summary>

    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Работа</th>
                <th scope="col">Выдано</th>
                <th scope="col">Сдали</th>
                <th scope="col">Ждут проверки</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($group['works'] as $work): ?>
                <tr>
                    <td>
                        <strong>
                            <a href="<?= $view->e($view->url('panel/journal/work/' . (int) $work['assignment_id'])) ?>">
                                <?= $view->e($work['assignment_title']) ?>
                            </a>
                        </strong>
                        <?php if (!empty($work['published_at'])): ?>
                            <br><span class="muted">
                                опубликована <?= $view->e(date('d.m.Y', strtotime((string) $work['published_at']))) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= $view->e((int) $work['issued_count']) ?></td>
                    <td><?= $view->e((int) $work['finished_count']) ?></td>
                    <td>
                        <?php if ((int) $work['review_count'] > 0): ?>
                            <span class="status status-yellow">
                                <?= $view->e((int) $work['review_count']) ?>
                            </span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </details>
</section>
<?php endforeach; ?>
