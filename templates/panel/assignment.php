<?php

declare(strict_types=1);

/**
 * Страница работы.
 *
 * Теперь это последняя вкладка редактора — «О работе». Отдельная страница
 * осталась только для того, кому работа доступна на чтение: автору редактор
 * открыт, а чужую работу не откроешь.
 *
 * Кнопок на странице нет: они были копией кнопок редактора («Собрать по
 * шагам», «Опубликовать», «Удалить»), и держать два одинаковых набора кнопок
 * на двух страницах значило забыть, где какой. Удалить работу по-прежнему можно
 * из списка работ, а пройти работу — из шапки редактора.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $items
 * @var string|null $subjectName
 * @var list<array<string,mixed>> $issues
 * @var list<array<string,mixed>> $attempts
 * @var bool $isAuthor
 */

use MyEdu\Repository\WorkTree;

$status = (string) $assignment['status'];
$isPublished = $status === 'published';
$parallel = WorkTree::parallelOf($assignment);

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => $view->e($assignment['title']), 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title"><?= $view->e($assignment['title']) ?>

</h1>
 <p class="muted">
 <?php if ($isPublished): ?>
 <span class="status status-green">опубликована</span>
 <?php elseif ($status === 'archived'): ?>
 <span class="chip">закрыта</span>
 <?php else: ?>
 <span class="chip">черновик</span>
 <?php endif; ?>
 <?= $view->e($view->count((int) (count($items)),  'задание', 'задания', 'заданий')) ?>
 </p>

 <?php if (! $isAuthor): ?>
 <?php /*
 Пояснение о чужой работе стоит в шапке, а не в ряду кнопок.

 Раньше оно стояло рядом с «Пройти работу», и ряд кнопок превращался в два
 разных предмета: кнопку и абзац. Взгляд цеплялся за текст вместо
 действия, а на узкой странице кнопка уезжала на вторую строку сама по себе.
 Пояснение — о том, что страница для чтения, и читать его нужно там, где
 стоит заголовок работы.
 */ ?>
 <p class="muted">Это работа другого автора. Посмотреть её можно, изменить — нет.</p>
 <?php endif; ?>
</div>

<?php /*
 Кнопка «Пройти работу» — только тому, кто не автор.

 У автора она в шапке редактора: он правит работу и прогоняет её на себе
 именно оттуда, где правит. Заведующему редактор недоступен, и убирать у
 него возможность проверить, что работа вообще выдаётся, было бы лишением:
 у него больше нигде такой кнопки нет.
 */
 ?>
 <?php if (! $isAuthor && $isPublished): ?>
 <p class="page-actions">
 <a class="btn btn-primary"
  href="<?= $view->e($view->url('work/' . (int) $assignment['id'])) ?>">
 Пройти работу
 </a>
 </p>
 <?php endif; ?>

<?php require __DIR__ . '/_assignment_facts.php'; ?>

<?php require __DIR__ . '/_assignment_issues_pane.php'; ?>

<?php require __DIR__ . '/_assignment_attempts_pane.php'; ?>
