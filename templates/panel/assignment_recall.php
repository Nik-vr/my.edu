<?php

declare(strict_types=1);

/**
 * Подтверждение отзыва выдачи работы.
 *
 * Отдельная страница, а не вопрос в браузере, потому что отзыв необратим для
 * новых учеников: выдачи удаляются, и новые не смогут начать работу. Уже
 * начавшие ученики смогут доработать, но новые попытки создаваться не будут.
 *
 * Ожидает: $view, $assignment, $issuedCount, $attemptCount
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var int $issuedCount
 * @var int $attemptCount
 */

$name = trim((string) ($assignment['title'] ?? ''));


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Отозвать выдачу', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Отозвать выдачу работы</h1>
</div>

<div class="card">
 <?php if ($name === ''):
?>

<?= $view->raw($view->bannerWarning('У этой работы нет названия. Если вы смотрите на этот список по ошибке, вернитесь назад.')) ?>
 <?php else: ?>
 <p>Вы собираетесь отозвать выдачу работы <strong><?= $view->e($name) ?></strong>.</p>
 <?php endif; ?>

 <div class="table-responsive">
 <table class="table">
 <tbody>
 <tr>
 <th scope="row">Выдано ученикам</th>
 <td><?= $view->e($issuedCount) ?></td>
 </tr>
 <tr>
 <th scope="row">Попыток (сохранятся)</th>
 <td><?= $view->e($attemptCount) ?></td>
 </tr>
 </tbody>
 </table>
 </div>

 <?php /*
 * Про отзыв сказано прямо: работа остаётся, но новые ученики её не увидят.
 * Уже начавшие смогут доработать, но новые попытки не создадутся.
 */ ?>
 <?= $view->raw($view->bannerWarning(
     'Работа останется опубликованной, но новые ученики не смогут её начать. '
     . 'Ученики, которые уже начали прохождение, смогут закончить — их результаты сохранятся. '
     . 'Все выдачи работы будут отменены.'
 )) ?>

 <div class="page-actions">
 <a class="btn"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit/start')) ?>">
 Отмена, вернуться к работе
 </a>

 <form method="post"
 action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/recall')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-warning btn-lg">Отозвать выдачу</button>
 </form>
 </div>
</div>