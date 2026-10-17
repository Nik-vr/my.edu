<?php

declare(strict_types=1);

/**
 * Вкладка «Опубликовать» редактора работы.
 *
 * Публикация — решение об одном действии целиком, а не кнопка на странице
 * задания. Поэтому здесь, а не рядом с формой вопроса: автор работы решает,
 * готова ли она, и видит, что именно мешает.
 *
 * Препятствия перечисляются все сразу, а не по одному: иначе публикация
 * отклонялась бы по очереди на каждой недоделанной строке, и каждый отказ
 * стоил бы ещё одного нажатия. Список заполняет ядро, но про готовность
 * каждого задания отвечает плагин: только он знает, что значит «заполнено»
 * для его вида задания.
 *
 * Своя форма у вкладки одна — сама публикация. Это решение об отдельном
 * действии, а не ещё одно поле работы, и общая кнопка «Сохранить» её не
 * заменяет: сохранить можно и не публикуя.
 *
 * Ожидает:
 * $view, $assignment, $blockers, $items, $status
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<string> $blockers
 * @var list<array<string,mixed>> $items
 * @var string $status
 */

$assignmentId = (int) $assignment['id'];
$isPublished = $status === 'published';
$isArchived = $status === 'archived';

/*
 * Переходы к соседним вкладкам — вкладки же, а не адреса прежних этапов.
 *
 * Без этого «К заданиям» перезагружал бы страницу целиком там, где человек
 * просто переключает вкладку, и терял бы несохранённое в общей форме.
 * Ссылка остаётся ссылкой и без скриптов: адрес тот же, что и у вкладки.
 */
$itemsUrl = 'panel/assignments/' . $assignmentId . '/edit?tab=items';
$resultUrl = 'panel/assignments/' . $assignmentId . '/edit?tab=result';
?>

<div class="wizard-page">
 <section class="wizard-page__main">
 <h2 class="card-title">Что увидят ученики</h2>

 <div class="card">
 <div class="page-actions">
 <div>
 <strong>
 <?= $view->e($view->plural(count($items), 'задание', 'задания', 'заданий')) ?>
 </strong>
 <br>
 <span class="muted">Порядок заданий тот же, в каком они идут в списке.</span>
 </div>

 <span class="actions-row__spacer"></span>

 <a class="btn btn-sm" href="<?= $view->e($view->url($itemsUrl)) ?>">
 Изменить состав
 </a>
 </div>
 </div>

 <?php if ($blockers !== []): ?>
 <div class="card">
 <h3 class="card-title">Опубликовать пока нельзя</h3>

 <p class="card-subtitle">
 Перечислено всё, чего не хватает. Пока это не сделано, работа
 остаётся черновиком и ученикам не видна.
 </p>

 <ul>
 <?php foreach ($blockers as $blocker): ?>
 <li><?= $view->e($blocker) ?></li>
 <?php endforeach; ?>
 </ul>

 <p class="page-actions">
 <a class="btn" href="<?= $view->e($view->url($itemsUrl)) ?>">
 К заданиям
 </a>
 <a class="btn" href="<?= $view->e($view->url($resultUrl)) ?>">
 К результату
 </a>
 </p>
 </div>
 <?php else: ?>
 <div class="card">
 <?php if ($isPublished || $isArchived): ?>
 <p class="card-subtitle">
 Работа уже опубликована. Повторная публикация ничего не
 меняет — чтобы вернуть её к правке, снимите с публикации
 на странице работы.
 </p>

 <?php if ($isPublished): ?>
 <?php /*
 Выдать прямо отсюда: работа только что опубликована и
 ещё никому не выдана, а искать «Выдать работу» в меню
 пришлось бы каждый раз — и почти всегда сразу после
 публикации.
 */ ?>
 <a class="btn btn-primary"
 href="<?= $view->e($view->url('panel/issues/new?assignment=' . $assignmentId)) ?>">
 Выдать работу
 </a>
 <?php endif; ?>
 <?php else: ?>
 <p class="card-subtitle">
 После публикации работу нужно выдать: сама публикация её
 никому не открывает. Состав заданий и шкалу оценок можно
 будет править и дальше, но изменения не попадут к тем, кто
 уже начал её проходить.
 </p>

 <form method="post"
 action="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit/publish')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <button type="submit" class="btn btn-primary btn-lg">Опубликовать работу</button>
 </form>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 </section>

 <aside class="wizard-page__side">
 <h2 class="card-title">Что проверить</h2>

 <ul>
 <li>В работе есть задания, и на каждое ученик сможет ответить.</li>
 <li>Задана шкала оценок — без неё ученик получит процент без отметки.</li>
 <li>После публикации работу нужно выдать: сама публикация её никому
 не открывает.</li>
 </ul>

 <p class="form-help">
 Публикуется работа целиком. Задания отдельно не публикуются, и в
 редакторе задания есть только кнопка «Сохранить».
</p>
  </aside>
</div>
