<?php

declare(strict_types=1);

/**
 * Ответ ученика для ручной проверки учителем.
 *
 * Показывается всё, что ученик написал или отметил, и ничего лишнего:
 * правильный ответ учителю не нужен, ему нужно видеть, что именно сделал
 * ученик.
 *
 * @var \MyEdu\Core\View $view
 * @var string $title
 * @var list<array<string,mixed>> $rows
 */
?>
<div class="submitted">
    <?php foreach ($rows as $index => $row): ?>
        <div class="submitted__item">
            <p class="submitted__prompt">
                <span class="submitted__number"><?= $view->e($index + 1) ?></span>
                <?= $view->raw($view->rich($row['prompt'])) ?>
                <?php if ($row['type'] !== ''): ?>
                    <span class="badge"><?= $view->e($row['type']) ?></span>
                <?php endif; ?>
            </p>

            <p class="submitted__answer"><?= $view->e($row['answer']) ?></p>

            <?php if ($row['checked']): ?>
                <p class="muted">
                    <?= $row['correct']
                        ? 'Плагин уже проверил: верно.'
                        : 'Плагин уже проверил: неверно.' ?>
                </p>
            <?php else: ?>
                <p class="muted">Автоматически не проверяется.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
