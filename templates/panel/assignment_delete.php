<?php

declare(strict_types=1);

/**
 * Подтверждение удаления работы.
 *
 * Отдельная страница, а не вопрос в браузере, потому что снос необратим:
 * вместе с работой уходят попытки учеников и их ответы. `confirm()` в
 * браузере закрывается нажатием Enter, и слепой Enter стоил бы чужих
 * результатов. Здесь нужно нажать кнопку, и до неё показано, что пропадёт.
 *
 * Ожидает: $view, $assignment, $itemCount, $totalAttempts
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var int $itemCount
 * @var int $totalAttempts
 */

$name = trim((string) ($assignment['title'] ?? ''));


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Удалить работу', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Удалить работу</h1>
</div>

<div class="card">
 <?php if ($name === ''):
?>

<?= $view->raw($view->bannerWarning('У этой работы нет названия. Если вы смотрите на этот список по ошибке, вернитесь назад.')) ?>
 <?php else: ?>
 <p>Вы собираетесь удалить работу <strong><?= $view->e($name) ?></strong>.</p>
 <?php endif; ?>

 <div class="table-responsive">
 <table class="table">
 <tbody>
 <tr>
 <th scope="row">Заданий</th>
 <td><?= $view->e($itemCount) ?></td>
 </tr>
 <tr>
 <th scope="row">Попыток, вместе с ответами</th>
 <td><?= $view->e($totalAttempts) ?></td>
 </tr>
 </tbody>
 </table>
 </div>

 <?php /*
 * Про само задание сказано прямо и без уменьшительных: если его пользует
 * ещё кто-то, оно останется, а если нет — исчезнет вместе с вопросами.
 * Человек должен понимать, что именно он сейчас отдаёт без возможности
 * вернуть.
 */ ?>
 <?= $view->raw($view->bannerError(
     'Восстановить это нельзя. Вместе с работой удалятся её задания — вместе с вопросами '
     . 'и вариантами ответа, — а также все попытки учеников и сданные ими ответы.'
 )) ?>

 <div class="page-actions">
 <a class="btn"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit/start')) ?>">
 Отмена, вернуться к работе
 </a>

 <form method="post"
 action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/delete')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-danger btn-lg">Удалить безвозвратно</button>
 </form>
 </div>
</div>
