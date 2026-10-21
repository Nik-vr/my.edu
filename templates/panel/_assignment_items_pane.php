<?php

declare(strict_types=1);

/**
 * Вкладка «Задания» редактора работы.
 *
 * Лежит на одной странице с остальными вкладками, поэтому шапку страницы и
 * нижнюю навигацию по этапам не содержит: у страницы одна шапка, а переходы
 * между вкладками — это сами вкладки над содержимым.
 *
 * Слева — строки работы в том порядке, в каком их увидит ученик. В строке
 * основное задание и, по желанию, две альтернативы: при выдаче ученику
 * достаётся одна из них, случайно, но одна и та же на всю попытку.
 *
 * Справа — палитра видов заданий. Она всегда на месте и не уезжает при
 * прокрутке: доска может быть длинной, а добавить задание нужно в любой её
 * части. Карточку перетаскивают из палитры в ячейку — на её месте в палитре
 * появляется новая пустая того же вида, поэтому вид всегда под рукой.
 *
 * Форм рядом с каждой позицией несколько, и они не вложены: браузер при
 * вложенности закрывает внешнюю форму и перестаёт отправлять её вообще —
 * порядок строк перестал бы сохраняться. Поэтому форма порядка стоит
 * отдельно и пустая, а кнопки строк ссылаются на неё через =.
 *
 * Форма вкладки — общая с «Начальной страницей» и «Результатом», и доска в
 * неё не входит: у каждой позиции своя кнопка и свой адрес. Общая форма
 * закрывается на вкладке заданий, поэтому вложенных форм тут не возникает.
 *
 * Ожидает:
 * $view, $assignment, $rows, $catalogue, $inactive, $editor
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<list<array<string,mixed>>> $rows строки работы, в каждой — варианты слева направо
 * @var list<array{id:string,name:string,kinds:list<array<string,mixed>>}> $catalogue
 * @var list<string> $inactive выключенные плагины с заданиями — их виды не показаны
 * @var array<string,mixed>|null $editor
 */

$assignmentId = (int) $assignment['id'];
$itemsUrl = 'panel/assignments/' . $assignmentId . '/edit/items';
$isPublished = in_array($assignment['status'] ?? 'draft', ['published', 'archived'], true);
$variantCount = count($rows);
?>

<!-- Форма порядка: детей нет, порядок проставляет скрипт по факту drag. -->
<form method="post" id="task-order-form"
 action="<?= $view->e($view->url($itemsUrl . '/reorder')) ?>">
 <?= $view->raw($view->csrfField()) ?>
</form>

<?php /*
 Стили редактора текста подключаются безусловно, а не «когда окно открыто
 сервером». Окно открывают двумя способами: переходом по адресу с `?edit=`
 и щелчком по карандашу, и второй открывает его скрипт уже после загрузки
 страницы. Безусловное подключение — единственный способ, при котором
 оформленный редактор есть в обоих случаях; `Ui::editorStyles()` отдаёт эти
 же строки, что и раньше, но не по условию. Сами поля формы рисует плагин,
 и ядро ничего о них не знает.
 */ ?>
<?= $view->raw($ui->editorStyles()) ?>

<div class="work-board">
 <section class="work-board__main">
  <h2 class="card-title">Задания работы</h2>

<?php if ($rows === []): ?>
  <p class="card-subtitle">
  Заданий пока нет. Перетащите вид из палитры справа в любое из трёх мест
  или нажмите на него — задание встанет сюда, и его можно будет наполнить.
  </p>
  <?php else: ?>
  <p class="card-subtitle">
  Строки идут сверху вниз в том же порядке, в каком их увидит ученик.
  Порядок меняется перетаскиванием или кнопками со стрелками. Слева —
  основное задание, рядом варианты: при выдаче ученику достаётся один из них,
  а внутри попытки он не меняется.
  </p>
  <?php endif; ?>

  <?php /*
  Доска всегда кончается пустой строкой — местом, куда кладут новое задание.

  Без неё пустая работа была мёртвой: перетаскивать в неё было некуда, и
  первый шаг требовал нажать кнопку в палитре, то есть перетаскивание
  включалось лишь после того, как им уже попользовались. Строка рисуется
  всегда и в базу не пишется: это место, а не задание.
  */ ?>
  <div class="work-rows" id="task-list"
 data-move-url="<?= $view->e($view->url($itemsUrl . '/')) ?>">
  <?php foreach ($rows as $rowIndex => $variants): ?>
  <?php
  $position = (int) $variants[0]['position'];
  $first = $variants[0];
  ?>
  <section class="board-row" data-row="<?= $view->e($position) ?>">
   <header class="board-row__head">
    <span class="board-row__num"><?= $view->e($rowIndex + 1) ?></span>

    <div class="board-row__meta">
     <span class="board-row__price" title="Цена и вес принадлежат строке: варианты делят их">
      <?= $view->e($first['max_score'] === null ? '—' : rtrim(rtrim(number_format((float) $first['max_score'], 2, ',', ''), '0'), ',')) ?>
      б.
     </span>
     <?php if (count($variants) > 1): ?>
     <span class="board-row__variants">вариантов: <?= $view->e(count($variants)) ?></span>
     <?php endif; ?>
    </div>

    <div class="board-row__actions">
     <button type="submit" class="btn btn-ghost" form="task-order-form"
     formaction="<?= $view->e($view->url($itemsUrl . '/row/' . $position)) ?>"
     name="position" value="<?= $view->e($position - 1) ?>"
     title="Выше"
     <?= $rowIndex === 0 ? 'disabled' : '' ?>>↑</button>

     <button type="submit" class="btn btn-ghost" form="task-order-form"
     formaction="<?= $view->e($view->url($itemsUrl . '/row/' . $position)) ?>"
     name="position" value="<?= $view->e($position + 1) ?>"
     title="Ниже"
     <?= $rowIndex === $variantCount - 1 ? 'disabled' : '' ?>>↓</button>
    </div>
   </header>

   <div class="board-row__cells">
   <?php foreach ([1, 2, 3] as $variant): ?>
   <?php
   $card = null;

   foreach ($variants as $candidate) {
    if ((int) $candidate['variant'] === $variant) {
     $card = $candidate;
     break;
    }
   }

   $cellId = $position . '-' . $variant;
   ?>
   <div class="work-cell<?= $card === null ? ' work-cell--empty' : '' ?>"
    data-cell="<?= $view->e($cellId) ?>"
    data-row="<?= $view->e($position) ?>"
    data-variant="<?= $view->e($variant) ?>">
   <?php if ($card !== null): ?>
   <?php
   $itemId = (int) $card['id'];
   $taskId = (int) ($card['task_id'] ?? 0);
   $isOpen = $editor !== null && $editor['taskId'] === $taskId;
   // Форму рисует плагин, и ядро не знает, есть ли она у
   // этого вида задания. Встраивают все плагины контракта,
   // а вот выключенный или сломанный — нет: к нему идём на
   // его собственную страницу, если она вообще есть.
   $editHref = $taskId > 0 && empty($card['broken'])
   ? $itemsUrl . '?edit=' . $taskId
   : (string) ($card['editUrl'] ?? '');
   ?>
   <article class="work-card<?= $isOpen ? ' work-card--open' : '' ?><?= empty($card['broken']) ? '' : ' work-card--broken' ?>"
    data-item-id="<?= $view->e($itemId) ?>"
    data-card-row="<?= $view->e($position) ?>"
    data-card-variant="<?= $view->e((int) $card['variant']) ?>">
    <div class="work-card__title">
    <?php if ($card['preview'] !== '' && $editHref !== ''): ?>
    <?php /*
     Ссылка на название открывает редактор так же, как карандаш, — теми же
     двумя метками. Раньше она была обычной ссылкой без них, и щелчок по
     названию перезагружал всю страницу: автор возвращался наверх доски и терял
     место, где остановился, — рядом стоящая кнопка «Редактировать» при этом
     работала правильно, и разница выглядела загадкой.

     Сама ссылка при этом остаётся: без скриптов адрес по-прежнему открывает
     то же окно, и в новой вкладке открывается именно он.
     */ ?>
    <a href="<?= $view->e($view->url($editHref)) ?>" data-task-title
     data-editor-open
     data-task-id="<?= $view->e((int) ($taskId ?? 0)) ?>">
    <?= $view->e($card['preview']) ?>
    </a>
    <?php else: ?>
    <?php /*
     Текста у задания нет — показать нечего, и выдуманная подпись была бы
     враньём: человек решил бы, что задание названо, и искал бы это название.

     Ссылку на редактирование в этом случае даёт кнопка с карандашом: она есть
     всегда, и карточка без условия остаётся такой же управляемой, как
     остальные. Иначе задание без текста было бы единственным на доске, куда
     нельзя войти, — и осталось бы навсегда таким.
     */ ?>
    <span class="work-card__title--empty" data-task-title></span>
    <?php endif; ?>
    </div>
    <div class="work-card__kind"><?= $view->e($card['kind']) ?></div>

    <?php /*
     Управление карточкой стоит одной строкой внизу: убрать слева, открыть
     справа. Раньше карандаш висел в правом верхнем углу, а удаление было
     отдельной строкой, и две кнопки в разных местах читались как разные
     намерения, хотя относятся к одной карточке.

     Удаление спрашивает подтверждения: снос позиции работы необратим, а
     задевает и то, что автор собрал. Скрипт показывает окно подтверждения; без
     скриптов форма уйдёт сразу — это тот же порядок, что и у удаления самой
     работы.
     */ ?>
    <div class="work-card__tools">
     <form method="post" class="work-card__remove"
      action="<?= $view->e($view->url($itemsUrl . '/' . $itemId . '/delete')) ?>">
      <?= $view->raw($view->csrfField()) ?>
      <button type="submit" class="work-card__btn work-card__btn--danger"
      data-confirm="Убрать задание из работы?"
      data-confirm-body="Задание вернётся в список плагина. Оно останется там, но из этой работы его придётся класть заново."
      data-confirm-yes="Убрать из работы"
       title="Убрать из работы" aria-label="Убрать задание из работы">
       <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 6h18"/>
        <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
        <path d="M10 11v6"/>
        <path d="M14 11v6"/>
       </svg>
      </button>
     </form>

<?php if ($editHref !== ''): ?>
      <a class="work-card__edit work-card__btn" href="<?= $view->e($view->url($editHref)) ?>"
       data-editor-open
       data-task-id="<?= $view->e((int) ($taskId ?? 0)) ?>"
       title="Редактировать задание" aria-label="Редактировать задание">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
       <path d="M12 20h9"/>
       <path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4Z"/>
      </svg>
      </a>
      <?php endif; ?>
    </div>
   </article>
   <?php endif; ?>
   </div>
   <?php endforeach; ?>
   </div>
  </section>
  <?php endforeach; ?>

  <?php
  $spareRow = $variantCount + 1;
  ?>
  <section class="board-row board-row--spare" data-spare-row>
   <header class="board-row__head">
    <span class="board-row__num"><?= $view->e($spareRow) ?></span>

    <div class="board-row__meta">
     <span class="board-row__spare">Перетащите сюда вид из палитры справа</span>
    </div>
   </header>

   <div class="board-row__cells">
   <?php foreach ([1, 2, 3] as $variant): ?>
   <?php
   /*
    Номер строки у пустой строки — следующий за последним. Именно он и
    записывается в форму палитры при броске: сервер создаёт задание в этой
    строке, а после перезагрузки она становится обычной, а пустая уезжает
    на следующее место.
    */
   ?>
   <div class="work-cell work-cell--spare"
    data-cell="<?= $view->e($spareRow . '-' . $variant) ?>"
    data-row="<?= $view->e($spareRow) ?>"
    data-variant="<?= $view->e($variant) ?>">
    <span class="work-cell__plus" aria-hidden="true">+</span>
   </div>
   <?php endforeach; ?>
   </div>
  </section>
  </div>
 </section>

 <aside class="work-board__side">
  <div class="work-palette">
   <h2 class="card-title">Виды заданий</h2>

   <p class="card-subtitle">
   Перетащите вид в ячейку или нажмите на него — задание встанет в конец
   работы. Одна система может давать несколько видов.
   </p>

   <?php if ($catalogue === []): ?>
   <p class="muted">
   Подключённых систем проверки нет. Включите хотя бы одну в
   разделе «Плагины».
   </p>
   <?php endif; ?>

   <?php foreach ($catalogue as $group): ?>
   <div class="wizard__group">
    <p class="wizard__group-title"><?= $view->e($group['name']) ?></p>

    <?php foreach ($group['kinds'] as $kind): ?>
    <form method="post" class="work-palette__form"
     data-palette-form
     action="<?= $view->e($view->url($itemsUrl . '/add')) ?>">
    <?= $view->raw($view->csrfField()) ?>
    <input type="hidden" name="plugin_id" value="<?= $view->e($group['id']) ?>">
    <input type="hidden" name="task_kind" value="<?= $view->e($kind['slug']) ?>">
    <input type="hidden" name="row" value="" data-palette-row>
    <input type="hidden" name="variant" value="" data-palette-variant>

    <button type="submit" class="task-kind" draggable="true"
     data-palette-kind
     title="<?= $view->e($kind['description']) ?>">
    <span class="task-kind__label"><?= $view->e($kind['title']) ?></span>
    <span class="task-kind__add" aria-hidden="true">+</span>
    </button>
    </form>
    <?php endforeach; ?>
   </div>
   <?php endforeach; ?>

   <?php if ($inactive !== []): ?>
   <?php /*
   Плагин с заданиями, но выключенный. Тип задания при этом есть —
   он просто не показан выше, и искать его в редакторе бессмысленно:
   человек concludes, что вида больше нет. Поэтому редактор говорит
   прямо, кому не хватает кнопки.
   */ ?>
   <p class="muted">
   Не показаны задания плагинов, которые выключены:
   <?= $view->e(implode(', ', $inactive)) ?>.
   Включите их в разделе
   <a href="<?= $view->e($view->url('panel/plugins')) ?>">«Плагины»</a>.
   </p>
   <?php endif; ?>
  </div>
 </aside>
</div>

<?php /*
Скрипты редактора текста подключаются здесь только когда окно пустое, то
есть когда форму придёт скрипт. Когда форму отдал сервер, они приезжают
вместе с ней — иначе на одной странице оказалось бы по две копии библиотеки и
по два обработчика на форму.
*/ ?>
<?php if ($editor === null): ?>
<?= $view->raw($ui->editorScripts()) ?>
<?php endif; ?>

<?php /*
Окно лежит в разметке всегда, а не только при `?edit=` в адресе.

Раньше его рисовал сервер, и открыть его можно было лишь переходом по
адресу: страница перезагружалась целиком, а вместе с ней терялось место на
доске, где автор остановился. Теперь окно на странице уже есть, и скрипт
наполняет его содержимым, не перезагружая страницу.

Пока данных нет, окно скрыто атрибутом — и для человека, и для программ
чтения. Без скриптов адрес по-прежнему работает: сервер отдаст страницу с
заполненным окном, и карандаш поведёт себя как обычная ссылка.
*/ ?>
<div class="modal" data-modal id="task-editor" role="dialog" aria-modal="true"
 data-editor-shell
 data-editor-url="<?= $view->e($view->url($itemsUrl . '/editor')) ?>"
 <?= $editor !== null ? ' data-open-on-load' : '' ?>
 <?= $editor === null ? 'hidden' : '' ?>>
 <div class="modal-dialog modal-dialog--task">
  <div class="modal-header">
   <h2 class="modal-title" id="task-editor-title" data-editor-title>
     <?php if ($isPublished): ?>
     <?= $view->raw($ui->warnHint(
         'Работа опубликована. Правка изменит то, что увидят ученики, которые ещё не начали '
         . 'её проходить; у начавших останется прежняя версия.',
         ['title' => 'Работа опубликована']
     )) ?>
     <?php endif; ?>
     Задание № <?= $view->e($editor['order'] ?? 0) ?>
</h2>
   <button type="button" class="modal-close" data-modal-close aria-label="Закрыть">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.5" aria-hidden="true">
     <path d="M18 6 6 18M6 6l12 12"/>
    </svg>
   </button>
  </div>

  <div class="modal-body">
<div class="task-editor" data-editor-for="<?= $view->e((int) ($editor['itemId'] ?? 0)) ?>"
     <?php /*
      * Адрес черновика — рядом с формой, а не собран скриптом.
      *
      * Скрипт знает только номер позиции (`data-editor-for`); собирать из него
      * путь значило бы держать в браузере копию правил маршрутизации, которая
      * рано или поздно разойдётся с серверной. Здесь адрес уже готов, и вместе
      * с ним рядом лежит номер работы.
      */ ?>
     data-draft-url="<?= $view->e($view->url($itemsUrl . '/' . (int) ($editor['itemId'] ?? 0) . '/draft')) ?>"
     data-editor-body>
     <?php if ($editor !== null): ?>
     <?= $view->raw($editor['html']) ?>
     <?php endif; ?>
   </div>
  </div>
 </div>
</div>

<?php /*
 * Черновик подключается раньше скрипта доски, а не позже.
 *
 * Отсрочка была сделана с добрыми намерениями: доска настраивает формы при
 * открытии окна, и модуль нужен к этому моменту. Но оба скрипта отложенные и
 * выполняются по порядку в разметке, и при обратном порядке доска
 * настраивала формы раньше, чем модуль черновиков появлялся на странице.
 *
 * Чем это оборачивалось: модуль не подключался вовсе, пока редактор задания
 * не открывали хотя бы раз. А он подключал предупреждение об уходе со
 * страницы. Значит, открыв задание один раз, человек получал вопрос «Закрыть
 * сайт?» при любом уходе — в том числе при нажатии «Сохранить» в редакторе
 * работы. Вопрос и появлялся «иногда»: только после первого открытия задания.
 */
?>
<script src="<?= $view->e($view->asset('assets/js/task-draft.js')) ?>" defer></script>

<script src="<?= $view->e($view->asset('assets/js/assignment-items.js')) ?>" defer></script>
