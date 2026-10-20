<?php

declare(strict_types=1);

/**
 * Вкладки редактора задания.
 *
 * Ядро даёт вкладки, плагин — их содержимое: поля задания у каждой системы
 * проверки свои, и ядро о них ничего не знает. Поэтому вкладка «Настройки»
 * появляется только там, где плагину есть что на неё положить, а «Задание»
 * есть всегда.
 *
 * Ожидает:
 * $view, $settings
 *
 * @var \MyEdu\Core\View $view
 * @var bool $settings есть ли у плагина настройки задания
 */
?>

<div class="task-editor-tabs" role="tablist">
 <button type="button" class="task-editor-tabs__tab is-active"
  data-task-pane-tab="task" role="tab" aria-selected="true">
 Задание
 </button>
 <?php if (!empty($settings)): ?>
 <button type="button" class="task-editor-tabs__tab"
  data-task-pane-tab="settings" role="tab" aria-selected="false">
 Настройки
 </button>
 <?php endif; ?>
</div>

<?php /*
 Скрипта здесь нет: он подключается вместе с остальными скриптами редактора
 (`Ui::editorScripts()`). Форма плагина попадает в окно и через innerHTML, а
 такие скрипты браузер не выполняет — вкладки не переключались бы.
 */ ?>
