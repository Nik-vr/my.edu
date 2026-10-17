<?php

declare(strict_types=1);

/**
 * Этапы редактора работы — `.stepper` шаблона gentelella.
 *
 * Живут только внутри мастера: три этапа, между которыми учитель ходит при
 * сборке работы. Отдельным блоком, а не общим меню панели, потому что здесь
 * другой вопрос — «на каком я шаге», а не «в какой раздел панели я попал».
 *
 * В шаблоне это горизонтальная полоса с кружками, а не боковая колонка. Так и
 * сделано: мастер идёт слева направо, и вертикальный список шагов читался бы
 * как ещё одна колонка рядом с меню панели — два списка рядом, и человек
 * перестаёт понимать, куда нажимать.
 *
 * Пройденный этап отмечен галочкой, текущий обведён. И то и другое — на самом
 * деле, а не картинкой: номер этапа виден и на пройденном, так что пропущенный
 * шаг не выглядит неразличимым с текущим.
 *
 * Ожидает:
 *   $view, $wizard
 *
 * @var \MyEdu\Core\View $view
 * @var array{title:string, url:string, current:bool, done:bool} $wizard
 */

?>
<div class="card wizard-card">
    <div class="card-body wizard-card__body">
        <ol class="stepper">
            <?php $steps = $wizard['steps']; ?>
            <?php foreach ($steps as $i => $step): ?>
                <?php $state = $step['current'] ? ' active' : ($step['done'] ? ' done' : ''); ?>
                <li class="step<?= $state ?>">
                    <a class="step__link"
                       href="<?= $view->e($view->url($step['url'])) ?>"
                       <?= $step['current'] ? 'aria-current="step"' : '' ?>>
                        <span class="num"><span><?= $view->e($i + 1) ?></span></span>
                        <span class="label"><?= $view->e($step['label']) ?></span>
                    </a>
                </li>
                <?php if ($i < count($steps) - 1): ?>
                    <li class="line<?= $step['done'] ? ' done' : '' ?>" aria-hidden="true"></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </div>
</div>