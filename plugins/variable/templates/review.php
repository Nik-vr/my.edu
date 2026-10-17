<?php

declare(strict_types=1);

use MyEduPlugin\Variable\Number;

/**
 * Разбор вариативной задачи.
 *
 * Фрагмент, а не страница: разбор встраивается в результат работы рядом с
 * другими заданиями, и оболочка приходит извне.
 *
 * Показываются числа, которые видел ученик, и один его ответ против одного
 * правильного: формула у задачи одна, поэтому и ответ один. Всё это приходит
 * из снимка проверки — правка задания автором не должна менять то, что стояло
 * в разборе.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var int $position
 * @var int $total
 * @var float $score
 * @var float $maxScore
 * @var bool $correct
 * @var string|null $error
 * @var string $prompt
 * @var array<string,float> $values
 * @var string $unit
 * @var string $answerText
 * @var string $expectedText
 */
$formatScore = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
?>
<div class="card">
 <h3 class="card-title">
 Задание <?= $view->e($position) ?> из <?= $view->e($total) ?>:
 <?= $view->e($title) ?>
 </h3>

 <?php if ($error !== null): ?>
 <p class="form-help">Задание ждёт проверки учителя: <?= $view->e($error) ?></p>
 <?php endif; ?>

 <p class="question__prompt"><?= $view->raw($view->rich($prompt)) ?></p>

 <?php if ($values !== []): ?>
 <p class="form-help">
 <?php foreach ($values as $name => $value): ?>
 <?php if ($name !== array_key_first($values)): ?>, <?php endif; ?>
 <?= $view->e($name) ?> = <?= $view->e(Number::format((float) $value)) ?><?= $view->e($unit === '' ? '' : ' ' . $unit) ?>
 <?php endforeach; ?>
 </p>
 <?php endif; ?>

 <table class="table">
 <thead>
 <tr>
 <th>Ответ ученика</th>
 <th>Правильный ответ</th>
 </tr>
 </thead>
 <tbody>
 <tr>
 <td><?= $view->e($answerText) ?></td>
 <td>
 <?= $view->e($expectedText) ?>
 <?php if (!$correct && $error === null): ?>
 <span class="chip">не совпало</span>
 <?php endif; ?>
 </td>
 </tr>
 </tbody>
 </table>

 <p>
 Балл за задание:
 <strong><?= $view->e($formatScore($score)) ?> из <?= $view->e($formatScore($maxScore)) ?></strong>
 </p>
</div>