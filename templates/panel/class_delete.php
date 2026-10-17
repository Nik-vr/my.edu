<?php

declare(strict_types=1);

/**
 * Подтверждение удаления класса.
 *
 * Отдельная страница, а не вопрос в браузере: удаление уносит учеников
 * вместе с их логинами и попытками, а `confirm()` закрывается нажатием
 * Enter — и слепой Enter стоил бы целого класса.
 *
 * Ожидает: $view, $class, $summary
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var array{students:int,subjects:int,teachers:int,attempts:int} $summary
 */

$classId = (int) $class['id'];
$name = trim((string) ($class['name'] ?? ''));
$deletionWarning = 'Восстановить это нельзя. ';

if ((int) $summary['students'] > 0) {
    $deletionWarning .= 'Вместе с классом будут удалены все его ученики '
        . (int) $summary['students'] . ' '
        . $view->plural((int) $summary['students'], 'человек', 'человека', 'человек')
        . ' вместе с логинами, паролями и всеми сданными работами. ';
} else {
    $deletionWarning .= 'Учеников в классе нет, но сданые ими работы и выдача работ этому классу тоже перестанут существовать. ';
}

$deletionWarning .= 'Ученик, состоящий в других классах, останется в системе.';


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Классы', 'url' => 'classes'],
        ['label' => 'Удалить класс', 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Удалить класс</h1>
</div>

<div class="card">
 <?php if ($name === ''):
?>

<?= $view->raw($view->bannerWarning('У этого класса нет номера. Если вы смотрите на эту страницу по ошибке, вернитесь к списку.')) ?>
 <?php else: ?>
 <p>Вы собираетесь удалить класс <strong><?= $view->e($name) ?></strong>.</p>
 <?php endif; ?>

 <div class="table-responsive">
 <table class="table">
 <tbody>
 <tr>
 <th scope="row">Учеников</th>
 <td><?= $view->e($summary['students']) ?></td>
 </tr>
 <tr>
 <th scope="row">Их попыток, вместе с ответами</th>
 <td><?= $view->e($summary['attempts']) ?></td>
 </tr>
 <tr>
 <th scope="row">Привязанных предметов</th>
 <td><?= $view->e($summary['subjects']) ?></td>
 </tr>
 <tr>
 <th scope="row">Учителей класса</th>
 <td><?= $view->e($summary['teachers']) ?></td>
 </tr>
 </tbody>
 </table>
 </div>

 <?php /*
 Ученики удаляются вместе с классом, и это сказано прямо: оставить их
 было бы хуже, чем удалить. В списке пользователей их нет, в списке
 классов — тоже, а в базе они висели бы без единого адреса, куда их
 можно посмотреть.

 Ученик, который состоит ещё и в другом классе, удалён не будет — его
 просто открепят от этого класса. Модель такое членство допускает, и
 сносить его вместе с классом было бы потерей чужого класса.
 */ ?>
  <?= $view->raw($view->bannerError($deletionWarning)) ?>

 <div class="page-actions">
 <a class="btn"
 href="<?= $view->e($view->url('classes/' . $classId)) ?>">
 Отмена, вернуться в класс
 </a>

 <form method="post"
 action="<?= $view->e($view->url('classes/' . $classId . '/delete')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-danger btn-lg">Удалить безвозвратно</button>
 </form>
 </div>
</div>
