<?php

declare(strict_types=1);

/**
 * Разбор задания-теста на странице результата работы.
 *
 * Показывается то, что было проверено: разбор собран из снимка при проверке,
 * а не пересчитан при показе. Иначе правка вопроса автором изменила бы то,
 * что ученик видел в момент сдачи.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var int $position
 * @var int $total
 * @var ?\MyEduPlugin\Test\Grading\GradingResult $result
 * @var float $score
 * @var float $maxScore
 * @var bool $needsReview
 * @var ?string $error
 * @var bool $showExplanation комментарий автора: только после последней попытки
 */
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
<article class="work-review">
 <header class="work-review__head">
 <span class="work-review__position"><?= $view->e($position) ?></span>
 <div class="work-review__heading">
 <h3 class="work-review__title"><?= $view->e($title) ?></h3>
 <p class="work-review__meta">
 <span class="chip">
 <?= $view->e($formatScore($score)) ?> из <?= $view->e($formatScore($maxScore)) ?> б.
 </span>
 <?php if ($needsReview): ?>
 <span class="status status-yellow">на проверке учителя</span>
 <?php endif; ?>
 </p>
 </div>
 </header>

  <?php if ($error !== null): ?>
  <?= $view->raw($view->bannerError($error)) ?>
 <?php elseif ($result === null): ?>
 <p class="muted">Разбор по этому заданию недоступен.</p>
 <?php else: ?>
 <ul class="work-review__list">
 <?php foreach ($result->items as $index => $item): ?>
 <?php
 $isCorrect = ($item['is_correct'] ?? false) === true;
 $itemScore = (float) ($item['score'] ?? 0);
 $itemMax = (float) ($item['max_score'] ?? 0);
 $tone = $isCorrect ? 'good' : ($itemScore > 0.0 ? 'mid' : 'bad');
 ?>
 <li class="work-review__item work-review__item--<?= $view->e($tone) ?>">
 <p class="work-review__prompt">
 <span class="work-review__number"><?= $view->e($index + 1) ?></span>
 <?= $view->raw($view->rich($item['prompt'] ?? '')) ?>
 <span class="badge"><?= $view->e($item['type_title'] ?? '') ?></span>
 </p>

 <dl class="work-review__body">
 <div class="work-review__row">
 <dt>Ваш ответ</dt>
 <dd><?= $view->e($item['answer_text'] ?? '—') ?></dd>
 </div>
 <div class="work-review__row">
 <dt>Правильный ответ</dt>
 <dd><?= $view->e($item['expected_text'] ?? '—') ?></dd>
 </div>

 <?php /*
  * Комментарий автора к правильному ответу — только после последней попытки.
  *
  * До неё комментарий превращает разбор в подсказку: ученик прочитал, почему
  * ответ верный, и берёт следующую попытку уже с готовым ключом. При
  * неограниченном числе попыток комментарий не показывается вовсе — иначе
  * первой же попыткой ограничение перестаёт быть ограничением. Решение
  * приходит из ядра (`AttemptContext::showExplanations`), плагин читает.
  */ ?>
 <?php if (!empty($showExplanation) && !empty($item['explanation'])): ?>
 <div class="work-review__row">
 <dt>Комментарий</dt>
 <dd><?= $view->e($item['explanation']) ?></dd>
 </div>
 <?php endif; ?>

 <?php $note = \MyEdu\Http\Presenter\ReviewNote::make(
 is_array($item['detail'] ?? null) ? $item['detail'] : []
 ); ?>
 <?php if ($note !== ''): ?>
 <div class="work-review__row">
 <dt>Пояснение</dt>
 <dd><?= $view->e($note) ?></dd>
 </div>
 <?php endif; ?>

 <div class="work-review__row">
 <dt>Балл</dt>
 <dd>
 <?= $view->e($formatScore($itemScore)) ?>
 из <?= $view->e($formatScore($itemMax)) ?>
 </dd>
 </div>
 </dl>
 </li>
 <?php endforeach; ?>
 </ul>
 <?php endif; ?>
</article>
