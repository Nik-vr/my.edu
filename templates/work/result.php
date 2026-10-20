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
            Автор работы не показывает результат. Оценка появится в журнале
            после проверки учителем.
        </p>
    <?php else: ?>
        <div class="metric-row">
            <div class="metric">
                <span class="metric-value">
                    <?= $view->e($total->percent === null ? '—' : $total->percent . '%') ?>
                </span>
                <span class="metric-label">выполнено</span>
            </div>

            <div class="metric">
                <?php /*
                Оценка красится тем цветом, который автор выбрал для неё в
                шкале. Раньше под подписью стояло слово из его шкалы («отлично»),
                и слово приходилось читать, а цвет читается сам.

                Класс берётся из данных сведения, а собирается там из палитры
                и уже приведён к одному из четырёх: подставить в разметку
                произвольную строку из базы нельзя.
                */
                ?>
                <span class="metric-value grade grade--<?= $view->e($total->color ?? 'primary') ?>">
                    <?= $view->e($total->grade ?? '—') ?>
                </span>
                <span class="metric-label">оценка</span>
            </div>

            <div class="metric">
                <span class="metric-value"><?= $view->e($total->score) ?></span>
                <span class="metric-label">из <?= $view->e($total->maxScore) ?></span>
            </div>

            <?php if ($total->penalty > 0.0): ?>
            <?php /*
             * Штраф показан отдельной строкой, а не вычтен из набранного молча.
             *
             * Ученик должен видеть, из чего сложился его результат: сколько
             * заработано, сколько снято и сколько осталось. Иначе «8 из 11»
             * читалось бы как «8 из 11» же, а на деле означало бы девять.
             */ ?>
            <div class="metric">
                <span class="metric-value">−<?= $view->e($total->penalty) ?></span>
                <span class="metric-label">штраф, итого <?= $view->e($total->net()) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <p class="muted">
            Итог — среднее по весам заданий, а не по их количеству.
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
