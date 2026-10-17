<?php

declare(strict_types=1);

/**
 * Одно задание теста.
 *
 * Значение $stored — ответ, сохранённый при предыдущем переходе. Оно лежит
 * в базе ровно в том виде, в каком пришло из формы, поэтому восстановление
 * сводится к отметке нужных полей и не требует обратных преобразований.
 *
 * Атрибут required намеренно НЕ ставится: браузер при незаполненном поле
 * блокирует отправку формы целиком — вместе с кнопкой «Далее» и переходом по
 * номеру в списке. Ученик оказывается заперт на задании, а объяснение
 * браузер рисует в виде маленького пузыря. Обязательность проверяет сервер
 * и сообщает об этом понятной фразой со списком номеров.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $q
 * @var mixed $stored
 */
$storedArray = is_array($stored) ? $stored : [];
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
 <section class="question" data-question-id="<?= $view->e((int) $q['id']) ?>" data-type="<?= $view->e($q['type']) ?>">
 <?php /* Маркер присутствия задания в форме.
 Без него сервер не отличил бы «сняли все флажки» от «перешли на другой
 вопрос»: браузер не отправляет неотмеченные флажки вообще, поэтому
 при очистке ответ вообще не приходит в запросе. */ ?>
 <?= $view->raw($ui->hidden('answer_present[' . (int) $q['id'] . ']', '1')) ?>
 <header class="question__head">
 <span class="question__number"><?= $view->e((int) $q['number']) ?></span>
 <div class="question__heading">
 <h2 class="question__prompt"><?= $view->raw($view->rich($q['prompt'])) ?></h2>
 <p class="question__meta">
 <span class="badge"><?= $view->e($q['typeTitle']) ?></span>
 <?php if ((float) $q['weight'] !== 1.0): ?>
 <span class="chip"><?= $view->e($formatScore((float) $q['weight'])) ?> б.</span>
 <?php endif; ?>
 <?php if ($q['required']): ?>
 <span class="chip">обязательное</span>
 <?php endif; ?>
 </p>
 </div>
 </header>

 <div class="question__body">
 <?php if ($q['type'] === 'single_choice'): ?>
 <div class="options" role="radiogroup" aria-label="<?= $view->e($q['typeTitle']) ?>">
 <?php foreach ($q['payload']['options'] as $option): ?>
 <label class="option">
 <input
 type="radio"
 name="answers[<?= $view->e((int) $q['id']) ?>]"
 value="<?= $view->e($option['value']) ?>"
 <?= (string) $stored === $option['value'] ? 'checked' : '' ?>>
 <span class="option__mark" aria-hidden="true"></span>
 <span class="option__text"><?= $view->e($option['text']) ?></span>
 </label>
 <?php endforeach; ?>
 </div>

 <?php elseif ($q['type'] === 'multiple_choice'): ?>
 <p class="question__hint">
 Отметьте все подходящие варианты. Правильных: <?= $view->e((int) $q['payload']['correct_count']) ?>.
 </p>
 <div class="options">
 <?php foreach ($q['payload']['options'] as $option): ?>
 <label class="option">
 <input
 type="checkbox"
 name="answers[<?= $view->e((int) $q['id']) ?>][]"
 value="<?= $view->e($option['value']) ?>"
 <?= in_array($option['value'], $storedArray, true) ? 'checked' : '' ?>>
 <span class="option__mark option__mark--box" aria-hidden="true"></span>
 <span class="option__text"><?= $view->e($option['text']) ?></span>
 </label>
 <?php endforeach; ?>
 </div>

 <?php elseif ($q['type'] === 'ordering'): ?>
 <p class="question__hint">
 Задайте порядок элементов. Можно перетаскивать мышью или выбрать позицию.
 </p>
 <ol class="ordering" data-ordering-list>
 <?php $itemCount = count($q['payload']['items']); ?>
 <?php foreach ($q['payload']['items'] as $index => $item): ?>
 <?php
 // Позиция хранится в черновике по ключу элемента, а не по
 // индексу: показанный порядок перемешан сервером и при
 // возврате к заданию может отличаться от исходного.
 $position = isset($storedArray[$item['value']])
 ? (int) $storedArray[$item['value']]
 : $index + 1;
 ?>
 <li class="ordering__item" data-ordering-item data-value="<?= $view->e($item['value']) ?>">
 <span class="ordering__handle" aria-hidden="true" title="Перетащите"></span>
 <span class="ordering__position" aria-hidden="true"><?= $view->e($position) ?></span>
 <span class="ordering__text"><?= $view->e($item['text']) ?></span>
 <label class="ordering__select">
 <span class="visually-hidden">Позиция элемента</span>
 <select name="answers[<?= $view->e((int) $q['id']) ?>][<?= $view->e($item['value']) ?>]" data-ordering-select>
 <?php for ($pos = 1; $pos <= $itemCount; $pos++): ?>
 <option value="<?= $view->e($pos) ?>" <?= $pos === $position ? 'selected' : '' ?>>
 место <?= $view->e($pos) ?>
 </option>
 <?php endfor; ?>
 </select>
 </label>
 </li>
 <?php endforeach; ?>
 </ol>

 <?php elseif ($q['type'] === 'numeric'): ?>
 <?php /*
 Число вводится текстом, а не number: ученик вправе написать
 «9,81» с запятой или «≈ 12», и разбор это понимает. С number
 браузер молча отклоняет такое значение и не даёт отправить форму.

 Поле рисует ядро: подпись, подсказка и отметка ошибки у ответа ученика
 должны выглядеть так же, как у любого поля ядра, иначе одна и та же форма
 выглядит по-разному в редакторе и при прохождении работы.
 */ ?>
 <?= $view->raw($ui->field('answers[' . (int) $q['id'] . ']', [
     'label'      => 'Ваш ответ',
     'labelClass' => 'visually-hidden',
     'value'      => is_string($stored) ? $stored : '',
     'inputmode'  => $q['payload']['integer'] ? 'numeric' : 'decimal',
     'maxlength'  => 32,
     'id'         => 'answer-' . (int) $q['id'],
     'placeholder' => $q['payload']['unit'] !== ''
         ? 'Например, 12 ' . $q['payload']['unit']
         : 'Введите число',
     'help'       => $q['payload']['unit'] !== ''
         ? 'Единица измерения: ' . $q['payload']['unit'] . '.'
         : '',
 ])) ?>

 <?php elseif ($q['type'] === 'text'): ?>
 <?= $view->raw($ui->textarea('answers[' . (int) $q['id'] . ']', [
     'label'      => 'Ваш ответ',
     'labelClass' => 'visually-hidden',
     'value'      => is_string($stored) ? $stored : '',
     'rows'       => (int) $q['payload']['rows'],
     'maxlength'  => (int) ($q['payload']['max_length'] ?: 4000),
     'id'         => 'answer-' . (int) $q['id'],
     'placeholder' => (string) $q['payload']['placeholder'],
 ])) ?>

 <?php elseif ($q['type'] === 'matching'): ?>
 <p class="question__hint">
 Для каждого понятия слева выберите подходящее определение справа.
 </p>
 <div class="matching">
 <?php foreach ($q['payload']['left'] as $left): ?>
 <?php $chosen = isset($storedArray[$left['value']]) ? (string) $storedArray[$left['value']] : ''; ?>
 <div class="matching__row">
 <span class="matching__left"><?= $view->e($left['text']) ?></span>
 <span class="matching__arrow" aria-hidden="true">&rarr;</span>
 <label class="matching__right">
 <span class="visually-hidden">Соответствие для «<?= $view->e($left['text']) ?>»</span>
 <select
 name="answers[<?= $view->e((int) $q['id']) ?>][<?= $view->e($left['value']) ?>]">
 <option value="">— выберите —</option>
 <?php foreach ($q['payload']['right'] as $right): ?>
 <option value="<?= $view->e($right['value']) ?>" <?= $chosen === $right['value'] ? 'selected' : '' ?>>
 <?= $view->e($right['text']) ?>
 </option>
 <?php endforeach; ?>
 </select>
 </label>
 </div>
 <?php endforeach; ?>
 </div>

 <?php endif; ?>
 </div>
</section>
