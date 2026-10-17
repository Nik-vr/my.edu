<?php

declare(strict_types=1);

/**
 * Редактор работы: четыре вкладки на одной странице.
 *
 * Раньше это был мастер из четырёх страниц, и правка одного названия стоила
 * пяти переходов: сохранить первый этап, дойти до третьего, вернуться назад
 * нижней кнопкой. Полоса этапов при этом не отвечала на вопрос «где я» — она
 * показывала порядок, а не содержимое.
 *
 * Теперь все четыре набора полей лежат рядом и переключаются вкладками, как на
 * странице переноса работы, а сохраняет их одна кнопка справа сверху: назвать
 * работу и поправить шкалу — одна отправка, а не две.
 *
 * Проверка при переключении вкладок не выполняется: вкладка ничего не
 * сохраняет, и требовать заполнить скрытые поля перед тем, как посмотреть
 * соседнюю вкладку, значит запрещать смотреть. Проверяется форма целиком —
 * при нажатии «Сохранить», и тогда человек узнаёт обо всех ошибках сразу.
 *
 * Форма общая, поэтому поля начальной страницы и шкалы лежат внутри неё, а
 * доска заданий и публикация — снаружи. У доски своя форма на каждую позицию,
 * у публикации своя: вложить их в общую форму нельзя, браузер закрыл бы
 * внешнюю и перестал бы отправлять её вовсе. Панели при этом остаются
 * соседями: `display: contents` у формы убирает её коробку, и наборы полей
 * встают в ряд друг с другом, а не один внутри другого.
 *
 * Ожидает:
 * $view, $assignment, $subjects, $parallel, $hasPlugins, $old, $workTab,
 * $rows, $catalogue, $inactive, $editor, $max_score_total, $scale, $percent,
 * $score, $badFrom, $badTo, $messages, $blockers, $items, $status
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $subjects
 * @var int|null $parallel
 * @var bool $hasPlugins
 * @var array<string,string> $old
 * @var string $workTab
 * @var list<list<array<string,mixed>>> $rows
 * @var list<array{id:string,name:string,kinds:list<array<string,mixed>>}> $catalogue
 * @var list<string> $inactive
 * @var array<string,mixed>|null $editor
 * @var float $max_score_total
 * @var list<array<string,mixed>> $scale
 * @var array<int,array{from:?float,to:?float,label:string}> $percent
 * @var array<int,array{from:?float,to:?float,label:string}> $score
 * @var list<int> $badFrom
 * @var list<int> $badTo
 * @var list<string> $messages
 * @var list<string> $blockers
 * @var list<array<string,mixed>> $items
 * @var string $status
 */

$assignmentId = (int) $assignment['id'];
$isPublished = in_array((string) $status, ['published', 'archived'], true);
$saveUrl = 'panel/assignments/' . $assignmentId . '/edit/save';

/*
 * Панели идут в порядке вкладок, а форма очерчивает две из них — начальную
 * страницу и результат. Порядок в разметке поэтому не совпадает с порядком
 * вкладок: панель «Результат» стоит раньше «Заданий». На экран это не влияет:
 * видна одна панель, а порядок задаётся вкладками. Читается он правильно:
 * вкладка «Результат» объявляет aria-controls на свою панель по идентификатору,
 * а не по соседству.
 */
$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Редактор', 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e($assignment['title']) ?>

</h1>

<?php if ($isPublished): ?>
<?= $view->raw($view->bannerInfo(
    'Работа опубликована' . (($assignment['published_at'] ?? '') !== ''
        ? ' ' . (string) $assignment['published_at']
        : '') . ' и доступна ученикам. Изменение состава заданий и шкалы оценок '
    . 'не попадёт к тем, кто уже начал её проходить, — у них итог посчитается '
    . 'по тому, что было на момент старта.'
)) ?>
<?php endif; ?>

<?php require __DIR__ . '/_work_editor_tabs.php'; ?>

<?php /*
 * Сохранить — одна кнопка на всю страницу, справа сверху от вкладок.
 *
 * Стоит над вкладками, а не внизу каждой из них: под вкладкой её нет, и
 * кнопка на месте независимо от того, какая вкладка открыта. Заданий это не
 * касается — у каждой позиции своя кнопка, и они остаются на доске.
 */
 ?>
<div class="work-tabs__actions">
 <span class="form-help">
 Правки на вкладках «Начальная страница» и «Результат» сохраняются этой кнопкой.
 Задания сохраняются на доске — у каждого своя кнопка.
 </span>
 <button type="submit" class="btn btn-primary" form="work-editor-form">Сохранить</button>
</div>

<?php /*
 * Общая форма на две вкладки с полями.
 *
 * `display: contents` — обязательное условие, а не украшение: без него форма
 * стала бы блоком, и панель «Заданий» оказалась бы после панели «Результат»
 * и внутри формы. Браузер закрыл бы вложенные формы доски, и порядок заданий
 * перестал бы сохраняться.
 */ ?>
<form method="post" id="work-editor-form" class="work-editor-form" data-scale-switch
 action="<?= $view->e($view->url($saveUrl)) ?>">
<?= $view->raw($view->csrfField()) ?>

<section class="work-tabs__pane<?= $workTab === 'start' ? ' active' : '' ?>"
 id="work-pane-start" role="tabpanel" aria-labelledby="work-tab-start">
<?php require __DIR__ . '/_assignment_start_pane.php'; ?>
</section>

<section class="work-tabs__pane<?= $workTab === 'result' ? ' active' : '' ?>"
 id="work-pane-result" role="tabpanel" aria-labelledby="work-tab-result">
<?php require __DIR__ . '/_assignment_result_pane.php'; ?>
</section>
</form>

<section class="work-tabs__pane<?= $workTab === 'items' ? ' active' : '' ?>"
 id="work-pane-items" role="tabpanel" aria-labelledby="work-tab-items">
<?php require __DIR__ . '/_assignment_items_pane.php'; ?>
</section>

<section class="work-tabs__pane<?= $workTab === 'publish' ? ' active' : '' ?>"
 id="work-pane-publish" role="tabpanel" aria-labelledby="work-tab-publish">
<?php require __DIR__ . '/_assignment_publish_pane.php'; ?>
</section>

<p class="wizard-page__nav">
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/assignments')) ?>">← К списку работ</a>
 <a class="btn btn-primary" href="<?= $view->e($view->url('panel/assignments/' . $assignmentId)) ?>">
 К работе целиком
 </a>
</p>

<script src="<?= $view->e($view->asset('assets/js/work-tabs.js')) ?>" defer></script>