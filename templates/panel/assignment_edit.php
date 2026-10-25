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

<?php
/*
 * Шапка редактора: название слева, действия справа.
 *
 * Так выглядит форма в шаблоне, на котором взят вид: заголовок и кнопки в одной
 * строке, кнопки прижаты вправо. Раньше кнопка «Сохранить» стояла под вкладками,
 * а внизу страницы была ещё и кнопка выдачи — человек искал их глазами по
 * всей странице.
 *
 * Название правится прямо в заголовке, как имя ученика в списке класса: карандаш
 * рядом с названием, поле и «Сохранить» вместо них. Отдельное поле «Название» в
 * основном блоке убрано: одно и то же название в двух местах расходилось, и
 * непонятно, какое из них главное.
 */
$workTitle = (string) ($assignment['title'] ?? '');
$isEditingTitle = !empty($editTitle);
$issued = !empty($issued);
$blockers = is_array($blockers ?? null) ? $blockers : [];
$isPublished = in_array((string) $status, ['published', 'archived'], true);
$publishUrl = 'panel/assignments/' . $assignmentId . '/status';
$editTitleUrl = 'panel/assignments/' . $assignmentId . '/edit?edit=title';
?>

<div class="work-editor-head">
  <div class="work-editor-head__title">
  <?php if ($isEditingTitle): ?>
  <form method="post" class="inline-form" data-inline-form
    action="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit/save')) ?>">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="title_only" value="1">
  <input type="text" name="title" required maxlength="191" autocomplete="off"
    value="<?= $view->e($workTitle) ?>"
    aria-label="Название работы" autofocus>
  <button type="submit" class="icon-btn icon-btn--save" title="Сохранить название">
  <?php $icon = 'copy'; ?>
  <?php require __DIR__ . '/../_action_icon.php'; ?>
  <span class="visually-hidden">Сохранить название</span>
  </button>
  <a class="icon-btn" title="Отменить"
  href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit')) ?>">
  <?php $icon = 'cancel'; ?>
  <?php require __DIR__ . '/../_action_icon.php'; ?>
  <span class="visually-hidden">Отменить</span>
  </a>
  </form>
  <?php else: ?>
  <h1 class="page-title work-editor-head__name">
  <?php if ($issued): ?>
  <?php /*
   * Значок вместо баннера.
   *
   * Баннер занимал всю ширину и повторялся на каждой вкладке, хотя значил
   * одно: работа уже у учеников, и правки состава и шкалы до них не дойдут.
   * Значок рядом с названием говорит то же, но не мешает работать, а текст
   * виден по наведению.
   *
   * Показывается только выданной хоть раз работе: невыданную работу правка
   * не задевает никого, и предупреждение было бы шумом.
   */ ?>
  <span class="work-editor-warn" tabindex="0"
  title="Работа опубликована и доступна ученикам. Изменение состава заданий и шкалы оценок не попадёт к тем, кто уже начал её проходить, — у них итог посчитается по тому, что было на момент старта.">
  <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
  <path d="M10 2 19 18H1zM9 7h2v6H9zm0 8h2v2H9z"/>
  </svg>
  <span class="visually-hidden">Работа уже выдана ученикам: изменения не дойдут до тех, кто её проходит</span>
  </span>
  <?php endif; ?>
  <?= $view->e($workTitle) ?>
  <a class="icon-btn" href="<?= $view->e($view->url($editTitleUrl)) ?>"
  title="Изменить название" aria-label="Изменить название работы">
  <?php $icon = 'edit'; ?>
  <?php require __DIR__ . '/../_action_icon.php'; ?>
  </a>
  </h1>
  <?php endif; ?>
  </div>

    <div class="work-editor-head__actions">
    <?php /*
     * «Пройти работу» — в шапке редактора.
     *
     * Раньше кнопка жила на отдельной странице работы, а та страница уехала
     * последней вкладкой сюда. Кнопку убрать было нельзя: автор после правки
     * задания прогоняет работу на себе, и делает это именно из редактора, где
     * он правит. Черновик проходить незачем — его не видно ученикам.
     */
    ?>
    <?php if ($isPublished): ?>
    <a class="btn"
      href="<?= $view->e($view->url('work/' . $assignmentId . '?trial=1')) ?>"
      target="_blank" rel="noopener">
    Пройти работу
    </a>
    <?php endif; ?>
    <?php /*
   * Подсказка про кнопку — на самой кнопке.
   *
   * Отдельной строкой рядом с кнопкой она занимала место в шапке и читалась
   * как ещё один текст страницы. На кнопке она там, где её ищут: человек
   * наводит на «Сохранить», когда не понимает, что кнопка сохраняет.
   */ ?>
  <form method="post" class="work-editor-head__publish"
  action="<?= $view->e($view->url($publishUrl)) ?>">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="action" value="publish" data-publish-action>
  <input type="hidden" name="return_to" value="panel/assignments/<?= $assignmentId ?>/edit">
  <label class="switch" title="Опубликована ли работа">
  <input type="checkbox" data-publish-toggle
  <?= $isPublished ? 'checked' : '' ?>
  <?= $blockers !== [] ? 'data-publish-blocked="1"' : '' ?>
  aria-label="Опубликовать работу">
  <span class="track"></span>
  </label>
  <span class="work-editor-head__publish-label">Опубликовать</span>
  </form>

  <noscript>
  <form method="post" action="<?= $view->e($view->url($publishUrl)) ?>">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="action" value="<?= $isPublished ? 'draft' : 'publish' ?>">
  <input type="hidden" name="return_to" value="panel/assignments/<?= $assignmentId ?>/edit">
  <button type="submit" class="btn btn-sm"><?= $isPublished ? 'Снять с публикации' : 'Опубликовать' ?></button>
  </form>
  </noscript>

  <button type="submit" class="btn btn-primary" form="work-editor-form"
  title="Правки на вкладках «Настройки работы» и «Результат» сохраняются этой кнопкой. Задания сохраняются на доске — у каждого своя кнопка.">
  Сохранить
  </button>
  </div>
</div>

<?php /*
 * Окно с препятствиями публикации.
 *
 * Переключатель «Опубликовать» при включении может упереться в неготовую работу:
 * без заданий или без шкалы оценок публиковать нельзя, и сервер откажет. Человек
 * должен увидеть, чего именно не хватает, а не пустой отказ.
 *
 * Окно открывает скрипт: он же не даёт переключателю включиться, пока
 * препятствия на месте, — иначе форма ушла бы и сервер ответил бы отказом
 * без объяснения.
 */
?>
<div class="modal" data-modal id="publish-blockers" role="dialog" aria-modal="true"
  aria-labelledby="publish-blockers-title"
  <?= !empty($publishBlocked) ? 'data-open-on-load' : '' ?>>
  <div class="modal-dialog">
  <div class="modal-header">
  <h3 class="modal-title" id="publish-blockers-title">Опубликовать пока нельзя</h3>
  <button type="button" class="modal-close" data-modal-close aria-label="Закрыть">
  <span aria-hidden="true">&times;</span>
  </button>
  </div>
  <div class="modal-body">
  <p>Перечислено всё, чего не хватает. Пока это не сделано, работа остаётся
  черновиком и ученикам не видна.</p>
  <ul>
  <?php foreach ($blockers as $blocker): ?>
  <li><?= $view->e($blocker) ?></li>
  <?php endforeach; ?>
  </ul>
  </div>
  <div class="modal-footer">
  <button type="button" class="btn btn-outline" data-modal-close>Закрыть</button>
  </div>
  </div>
</div>

<?php require __DIR__ . '/_work_editor_tabs.php'; ?>


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

 <?php /*
  Последняя вкладка: сведения о собранной работе.
  Раньше они жили на отдельной странице работы, у которой были свои кнопки —
  «Собрать по шагам», «Опубликовать», «Удалить». Всё это есть в редакторе, и
  две страницы с одинаковыми кнопками требовали держать в голове, какая из них
  сейчас открыта. Здесь кнопок нет: есть сведения.
 */ ?>
 <section class="work-tabs__pane<?= $workTab === 'about' ? ' active' : '' ?>"
  id="work-pane-about" role="tabpanel" aria-labelledby="work-tab-about">
 <?php require __DIR__ . '/_assignment_facts.php'; ?>

 <?php require __DIR__ . '/_assignment_issues_pane.php'; ?>
 <?php require __DIR__ . '/_assignment_attempts_pane.php'; ?>
 </section>

 <p class="wizard-page__nav">
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/assignments')) ?>">← К списку работ</a>
 </p>

<script src="<?= $view->e($view->asset('assets/js/work-tabs.js')) ?>" defer></script>