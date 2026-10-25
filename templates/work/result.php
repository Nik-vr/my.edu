<?php

declare(strict_types=1);

/**
 * Результат работы и разбор по позициям.
 *
 * Баллы и разбор показываются, только если автор разрешил это в настройках
 * работы. Причина простая: некоторые задания показываются ученику с первого
 * раза, и разбор с правильными ответами сразу после сдачи делает работу
 * бессмысленной.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var array<string,mixed> $attempt
 * @var \MyEdu\Grading\AssignmentTotal $total
 * @var list<array<string,mixed>> $items
 * @var bool $showResults
 */
$mode = (string) $assignment['grade_mode'];
?>
<div class="page-header">
    <h1 class="page-title"><?= $view->e($assignment['title']) ?></h1>
    <p class="muted">
        Попытка №<?= $view->e((int) $attempt['attempt_no']) ?>
        <?php if (!empty($attempt['finished_at'])): ?>
            · закончена <?= $view->e($attempt['finished_at']) ?>
        <?php endif; ?>
    </p>
</div>

<?php if ($total->isPending()): ?>
    <?= $view->raw($view->bannerWarning('Часть заданий ждёт проверки учителем, итог пока не окончательный.')) ?>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Итог</h2>

    <?php if (!$showResults): ?>
        <p class="muted">
            Для этой работы результат не показывает. Оценка появится в журнале после проверки учителем.
        </p>
    <?php else: ?>
        <?php
        /*
         * Вид оценки — дуга-шкала, как показано владельцу (решение от
         * 24.10.2026, волна D): дуга с процентом, кружок оценки цветом
         * авторской шкалы и таблица «баллы / максимум / процент».
         *
         * Геометрия дуги считается здесь и попадает в атрибуты SVG
         * (stroke-dasharray): инлайновые style= в шаблонах запрещены
         * проверкой ui, а атрибуты — обычная разметка. Окружность r=34,
         * её длина 2·π·34 ≈ 213.63; засчитывается доля процента.
         *
         * Процент может отсутствовать (у работы без максимума) — тогда дуга
         * пустая, а в подписи стоит прочерк: выдумывать число нельзя.
         */
        $gaugePercent = $total->percent === null
            ? null
            : max(0.0, min(100.0, (float) $total->percent));
        $gaugeRadius = 34.0;
        $gaugeLength = round(2 * M_PI * $gaugeRadius, 2);
        $gaugeFilled = $gaugePercent === null
            ? 0.0
            : round($gaugeLength * $gaugePercent / 100.0, 2);
        $gaugeText = $gaugePercent === null ? '—' : ($total->percent . '%');
        ?>
        <div class="result-gauge-row">
            <?php /*
             * Дуга: дорожка нейтральная, значение — акцентным цветом.
             * rotate(-90) смещает начало дуги на верхнюю точку: без него
             * шкала начиналась бы справа и читалась бы как перевёрнутая.
             */ ?>
            <svg class="result-gauge" width="96" height="96" viewBox="0 0 96 96"
                 role="img" aria-label="Выполнено: <?= $view->e($gaugeText) ?>">
                <circle class="result-gauge__track"
                        cx="48" cy="48" r="<?= $view->e((string) $gaugeRadius) ?>"
                        fill="none" stroke-width="9"
                        transform="rotate(-90 48 48)"
                        stroke-dasharray="<?= $view->e((string) $gaugeLength) ?> <?= $view->e((string) $gaugeLength) ?>" />
                <circle class="result-gauge__value"
                        cx="48" cy="48" r="<?= $view->e((string) $gaugeRadius) ?>"
                        fill="none" stroke-width="9" stroke-linecap="round"
                        transform="rotate(-90 48 48)"
                        stroke-dasharray="<?= $view->e((string) $gaugeFilled) ?> <?= $view->e((string) $gaugeLength) ?>" />
                <text class="result-gauge__percent" x="48" y="48"
                      text-anchor="middle" dy=".34em"><?= $view->e($gaugeText) ?></text>
                <text class="result-gauge__caption" x="48" y="66"
                      text-anchor="middle">выполнено</text>
            </svg>

            <div class="result-gauge-data">
                <?php
                /*
                 * Кружок оценки: цвет берётся из шкалы автора.
                 *
                 * Четыре палитры дают готовый класс grade--* (приведение к ним
                 * делает сведение), а собственный цвет автора класса не имеет и
                 * передаётся атрибутом data-grade-color — его применяет скрипт
                 * grade-color.js. Произвольная строка в разметку не попадает:
                 * цвет приведён к `#rrggbb` при сохранении.
                 */
                $gradeColor = (string) ($total->color ?? 'primary');
                $paletteGrade = \MyEdu\Grading\GradeScaleValidator::isPaletteColor($gradeColor);
                ?>
                <span class="grade-badge grade<?= $paletteGrade ? ' grade--' . $view->e($gradeColor) : '' ?>"
                      <?= $paletteGrade ? '' : 'data-grade-color="' . $view->e($gradeColor) . '"' ?>
                      title="Итоговая оценка"><?= $view->e($total->grade ?? '—') ?></span>

                <table class="result-figures">
                    <thead>
                        <tr>
                            <th scope="col">Баллы</th>
                            <th scope="col">Максимум</th>
                            <th scope="col">Процент</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= $view->e($total->score) ?></td>
                            <td><?= $view->e($total->maxScore) ?></td>
                            <td><?= $view->e($gaugeText) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total->penalty > 0.0): ?>
            <?php /*
             * Штраф показан отдельной строкой, а не вычтен из набранного молча:
             * ученик должен видеть, из чего сложился его результат — сколько
             * заработано, сколько снято и сколько осталось.
             */ ?>
            <p class="muted">
                Штраф −<?= $view->e($total->penalty) ?> · итого <?= $view->e($total->net()) ?>
            </p>
        <?php endif; ?>

        <p class="muted">
            Итог. За разные задания может быть начислено разное количество баллов.
            <?= $mode === 'per_item'
                ? 'Режим: отдельная оценка за задание, затем сведение по весам.'
                : 'Режим: единая оценка за всю работу.' ?>
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">По заданиям</h2>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">№</th>
                    <th scope="col">Задание</th>
                    <th scope="col">Вес</th>
                    <?php if ($showResults): ?>
                        <th scope="col">Балл</th>
                        <th scope="col">Оценка</th>
                    <?php endif; ?>
                    <th scope="col">Состояние</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= $view->e((int) $item['position']) ?></td>
                        <td><strong><?= $view->e($item['title']) ?></strong></td>
                        <td><?= $view->e($item['weight']) ?></td>

                        <?php if ($showResults): ?>
                            <td>
                                <?php if ($item['score'] === null): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    <?= $view->e($item['score']) ?>
                                    <?php if ($item['max_score'] !== null): ?>
                                        из <?= $view->e($item['max_score']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $view->e($item['grade'] ?? '—') ?></td>
                        <?php endif; ?>

                        <td>
                            <?php if ($item['needs_review']): ?>
                                <span class="status status-yellow">ждёт проверки</span>
                            <?php elseif ($item['status'] === 'correct'): ?>
                                <span class="status status-green">верно</span>
                            <?php elseif ($item['status'] === 'partial'): ?>
                                <span class="chip">частично</span>
                            <?php elseif ($item['status'] === 'wrong'): ?>
                                <span class="status status-red">неверно</span>
                            <?php elseif ($item['status'] === 'skipped'): ?>
                                <span class="muted">пропущено</span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($showResults && $item['review'] !== null): ?>
                        <tr>
                            <td colspan="<?= $mode === 'per_item' ? 6 : 5 ?>">
                                <div class="answer-box">
                                    <?= $view->raw($item['review']->body()) ?>
                                </div>
                            </td>
                        </tr>
                    <?php elseif ($showResults && $item['error'] !== null): ?>
                        <tr>
                            <td colspan="<?= $mode === 'per_item' ? 6 : 5 ?>">
                                <?= $view->raw($view->bannerError('Не удалось показать разбор: ' . $item['error'])) ?>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if ($showResults && $item['comment'] !== ''): ?>
                        <tr>
                            <td colspan="<?= $mode === 'per_item' ? 6 : 5 ?>">
                                <p class="muted">
                                    Комментарий учителя: <?= $view->e($item['comment']) ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p>
    <a class="btn" href="<?= $view->e($view->url('works')) ?>">К списку работ</a>
</p>
