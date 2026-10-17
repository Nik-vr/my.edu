<?php

declare(strict_types=1);

/**
 * Вкладка «Результат» редактора работы.
 *
 * Итог сводит ядро, но числа приходят от плагинов — по каждому заданию это
 * баллы или процент выполнения. Что делать с этими числами дальше, решает
 * учитель здесь: выбирает, считать по баллам или по процентам, и задаёт
 * диапазоны.
 *
 * Один режим за раз: «от 7 баллов» и «от 25 процентов» на одной шкале
 * ученику не объяснить, а спросить дважды форма не может — молча берётся
 * первое, и человек получает шкалу, которую не выбирал. Поэтому у режимов
 * разные наборы полей: в процентном одна граница, в балловом — две.
 *
 * Оценок ровно четыре, и они не меняются. Диапазоны примыкают друг к другу и
 * начинаются с нуля: без этого на шкале остаётся промежуток, куда не попадает
 * ни одна оценка, и ученик с таким результатом получил бы двойку молча.
 *
 * Своей формы у вкладки нет: поля шкалы лежат в общей форме редактора, и
 * сохраняются той же кнопкой, что и всё остальное на странице.
 *
 * Ожидает:
 * $view, $assignment, $scale, $percent, $score, $badFrom, $badTo, $messages
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment несёт и max_score_total
 * @var list<array<string,mixed>> $scale
 * @var array<int,array{from:?float,to:?float,label:string}> $percent границы процентного режима
 * @var array<int,array{from:?float,to:?float,label:string}> $score границы баллового режима
 * @var list<int> $badFrom оценки с неверной нижней границей
 * @var list<int> $badTo оценки с неверной верхней границей
 * @var list<string> $messages
 */

use MyEdu\Grading\GradeScaleValidator;
$assignmentId = (int) $assignment['id'];
$byScore = ($assignment['criteria_mode'] ?? 'percent') === 'score';
$total = (float) ($assignment['max_score_total'] ?? 0);

?>
<div class="card">
 <h2 class="card-title">Итоговая оценка</h2>

 <?php if ($total > 0): ?>
 <?php /* ?>
 Сумма цен показывается в любом режиме, а не только в балльном:
 это масштаб работы, и учителю нужно видеть его до того, как он
 выберет режим. Иначе пришлось бы сначала выбрать, потом
 вернуться и посмотреть, и решение принималось бы вслепую.
 */ ?>
  <?= $view->raw($view->bannerInfo(
      'Сумма цен заданий: ' . GradeScaleValidator::formatNumber($total) . ' балл.'
      . ($byScore ? ' Диапазоны ниже отсчитываются от этой суммы.' : '')
  )) ?>
 <?php endif; ?>

 <?php if ($messages !== []): ?>
 <?php /*
 Ошибки перечисляются целиком, а не по одной: границы проверяются
 вместе, и чинить их по одной — значит отправлять форму заново
 после каждой найденной.
 */ ?>
  <?= $view->raw($view->bannerError(
      'Диапазоны не сохранены: ' . implode('; ', $messages)
      . ' Исправьте отмеченные поля и сохраните снова.'
  )) ?>
 <?php endif; ?>

 <fieldset class="form-group">
 <legend class="form-label">Считать итог</legend>

 <?php /*
 Режим выбирается вкладками-таблетками, а не переключателями: перед человеком
 ровно два способа посчитать итог, и это выбор между ними, а не ответ на
 вопрос. Вкладка подписана тем же, чем был подписан переключатель, и набор
 полей под ней тот же.

 Радиокнопки не убраны: вкладка — это их подпись, и режим едет в поле
 `criteria_mode` как раньше. Кнопка стоит ПЕРЕД подписью, и заливку выбранной
 вкладки задаёт соседний селектор `input:checked + .nav-link`.

 Почему соседний, а не :has(): проверка подписи через :has() не применялась —
 правило совпадало с элементом, а оформление оставалось прежним. Работающая
 комбинация здесь не эксперимент: набор полей переключается отдельным
 правилом по отмеченной кнопке, и форма остаётся рабочей без скриптов.

 Сами поля скрыты только на глаз: правило .criteria-pill__input прячет кнопку,
 оставляя её в клавиатурном обходе, иначе выбрать режим с клавиатуры было бы
 нельзя.
 */ ?>
 <ul class="nav nav-pills" role="tablist">
 <li class="nav-item" role="presentation">
 <input type="radio" id="criteria_mode_percent" name="criteria_mode" value="percent"
 class="criteria-pill__input" <?= !$byScore ? 'checked' : '' ?>>
 <label class="nav-link criteria-pill" for="criteria_mode_percent">
 <span>По проценту выполнения</span>
 </label>
 </li>
 <li class="nav-item" role="presentation">
 <input type="radio" id="criteria_mode_score" name="criteria_mode" value="score"
 class="criteria-pill__input" <?= $byScore ? 'checked' : '' ?>>
 <label class="nav-link criteria-pill" for="criteria_mode_score">
 <span>По набранным баллам</span>
 </label>
 </li>
 </ul>

 <span class="form-help">
 Режим выбирается один раз на всю работу. Задания присылают
 баллы, но «процент выполнения» считается из них же — поэтому
 два режима дают один и тот же порядок, а различаются тем, что
 показывается ученику.
 </span>
 </fieldset>

 <fieldset class="form-group">
 <legend class="form-label">Диапазоны</legend>

 <?php /*
 Два набора полей, а не один перерисовываемый: рисовать их скриптом
 значило бы держать разметку шкалы в JavaScript и в шаблоне
 сразу.

 Переключает их CSS, а не скрипт. Набор показывается по тому,
 какая радиокнопка отмечена, — правило одно и целиком в стилях.
 Скрипт здесь ничего не менял, кроме видимости, и ровно этим
 переключение и ломалось: страница выглядела исправной, а
 наборы не менялись. Видимость — не данные, и держать её в
 разметке или в скрипте одинаково плохо: правка там или там
 расходилась с правкой здесь, и человек видел форму, которая
 молча не работает.

 Ничего не отключается: оба набора уходят вместе с формой, и
 границы каждого сохраняются свои. Раньше невидимый набор
 отключался, чтобы сервер не получил восемь чисел вместо
 четырёх; теперь у наборов свои имена полей, и сервер берёт
 те, что соответствуют отмеченному режиму. Поэтому переключение
 и возврат не стирают то, что уже набрано, а смену режима
 переживает даже страница, где скрипты запрещены.
 */ ?>
<div class="tab-content">
 <div class="tab-pane<?= !$byScore ? ' active' : '' ?>" data-scale-set="percent">
 <fieldset>
 <p class="form-help">
 У каждой оценки одна граница — с какого процента она
 ставится. Верхняя граница берётся у следующей оценки, а
 диапазон двойки начинается с нуля.
 </p>

 <?php
 $scaleRows = $percent;
 $scaleMode = 'percent';
 require __DIR__ . '/_scale_rows.php';
 ?>
 </fieldset>
 </div>

 <div class="tab-pane<?= $byScore ? ' active' : '' ?>" data-scale-set="score">
 <fieldset>
<p class="form-help">
   У каждой оценки две границы в баллах: нижняя и верхняя. Они смыкаются
   друг с другом без зазора, и последняя верхняя граница равна
   сумме цен — <?= $view->e(GradeScaleValidator::formatNumber($total)) ?>.
   </p>

 <?php
 $scaleRows = $score;
 $scaleMode = 'score';
 require __DIR__ . '/_scale_rows.php';
 ?>
 </fieldset>
 </div>
</div>
</fieldset>

 <fieldset class="form-group">
 <legend class="form-label">Что видит ученик</legend>

 <label class="check">
 <input type="checkbox" name="show_results" <?= !empty($assignment['show_results']) ? 'checked' : '' ?>>
 Показывать разбор и оценку после сдачи
 </label>
 </fieldset>
</div>

<script src="<?= $view->e($view->asset('assets/js/scale-color.js')) ?>" defer></script>
