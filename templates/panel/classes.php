<?php

declare(strict_types=1);

/**
 * Список классов.
 *
 * Страница только показывает. Формы создания здесь больше нет: пока она
 * стояла над списком, страница отвечала на два разных вопроса сразу, и
 * удаление класса нажималось рядом с полями для нового класса.
 *
 * Ожидает: $view, $classes, $notice, $error
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $classes
 */



$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Классы', 'url' => ''],
    ]);
?>

<div class="page-header">
    <h1 class="page-title">Классы</h1>
    <p class="muted">
        Класс — это номер, название и состав учеников. Предметы и учителя
        задаются внутри класса отдельной вкладкой.
    </p>
</div>

<div class="card">
    <a class="btn btn-primary btn-lg" href="<?= $view->e($view->url('classes/new')) ?>">
        Создать класс
    </a>
</div>

<div class="card">
    <?php if ($classes === []): ?>
        <p class="muted">Классов пока нет.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" data-datatable data-page-length="25">
                <thead>
                    <tr>
                        <th scope="col">Класс</th>
                        <th scope="col">Название</th>
                        <th scope="col">Учеников</th>
                        <th scope="col">Предметов</th>
                        <th scope="col">Учителей</th>
                        <th scope="col" data-orderable="false"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                        <?php $classId = (int) $class['id']; ?>
                        <tr>
                            <td>
                                <a href="<?= $view->e($view->url('classes/' . $classId)) ?>">
                                    <strong><?= $view->e($class['name']) ?></strong>
                                </a>
                            </td>
                            <td><?= $view->e($class['title'] ?? '—') ?></td>
                            <td><?= $view->e((int) $class['student_count']) ?></td>
                            <td><?= $view->e((int) $class['subject_count']) ?></td>
                            <td><?= $view->e((int) $class['teacher_count']) ?></td>
                            <td class="row-actions">
<?php /*
 * Действия класса — меню, как у сотрудников и учеников.
 *
 * Ссылки внутри, а не формы: адрес подтверждения удаления и так остаётся
 * запасным путём, а форма здесь означала бы, что удаление классов идёт мимо
 * /classes/{id}/delete, который описан и проверен. Скрипт подтверждения
 * перехватывает клик по любому элементу с data-confirm, а меню построено на
 * <details> и раскрывается без скриптов.
 */ ?>
                                <details class="row-menu">
                                    <summary class="card-opt-btn"
                                             aria-label="Действия: <?= $view->e($class['name']) ?>">
                                        <?php $icon = 'more'; ?>
                                        <?php require __DIR__ . '/../_action_icon.php'; ?>
                                    </summary>
                                    <div class="menu-popover" role="menu">
                                        <a class="menu-item" role="menuitem"
                                           href="<?= $view->e($view->url('classes/' . $classId)) ?>">
                                            Редактировать класс
                                        </a>
                                        <div class="menu-separator" role="separator"></div>
                                        <a class="menu-item menu-item--danger" role="menuitem"
                                           href="<?= $view->e($view->url('classes/' . $classId . '/delete')) ?>"
                                           data-confirm="Удалить класс <?= $view->e($class['name']) ?>?"
                                           data-confirm-body="<?= $view->e($view->classRemovalWarning((int) $class['student_count'])) ?>"
                                           data-confirm-yes="Удалить класс">
                                            Удалить класс
                                        </a>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
