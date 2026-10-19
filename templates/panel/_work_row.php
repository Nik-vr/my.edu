<?php

declare(strict_types=1);

/**
 * Строка дерева работ.
 *
 * Отдельным файлом, потому что показывается в двух местах: в списке работ и на
 * первом шаге мастера выдачи. Скопированная в два места таблица разошлась бы
 * при первой правке — и тихо: на одной странице работа выглядела бы
 * редактируемой, а на другой нет.
 *
 * Отличается одна вещь: в мастере строки выбираются, поэтому $selectable
 * добавляет кружок выбора слева вместо ссылки. Остальное — то же самое,
 * иначе учитель на двух страницах увидит разные работы.
 *
 * Ожидает:
 *   $view, $row, $seesAll, $currentUserId, $selectable, $pickName
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $row
 * @var bool $seesAll
 * @var int $currentUserId
 * @var bool $selectable строка выбирается для выдачи, а не открывается
 * @var string $pickName имя поля выбора
 */

$assignmentId = (int) $row['id'];
$name = trim((string) $row['title']);
$isMine = (int) $row['author_id'] === (int) $currentUserId;
$isPublished = (string) $row['status'] === 'published';
$isArchived = (string) $row['status'] === 'archived';
?>

<li class="work-row<?= $selectable ? ' work-row--selectable' : '' ?>">
    <?php if ($selectable): ?>
        <?php /*
            Кружок вместо ссылки: на первом шаге мастера работа выбирается, а не
            открывается. Подпись рядом обязательна — без неё строка читалась бы
            как ссылка, и нажатие увело бы учителя с мастера в редактор работы.

            Отметка приходит адресом (?assignment=12 с обзора работы), а не
            формой: человек приходит сюда уже зная, какую работу выдаёт, и
            заново искать её в дереве незачем.

            Обязательным помечен первый круглый переключатель группы, а не все:
            требование «выберите работу» относится ко всей группе, и HTML
            проверяет именно так — по первому полю с таким именем.
        */ ?>
        <?php $isChosen = isset($chosen) && (int) $chosen === $assignmentId; ?>
        <input type="radio" name="<?= $view->e($pickName) ?>" value="<?= $view->e($assignmentId) ?>"
               class="work-row__pick" aria-label="Выдать работу «<?= $view->e($name) ?>»"
               <?= $isChosen ? 'checked' : '' ?>
               <?= (int) ($rowIndex ?? 0) === 0 ? 'required data-hint="Отметьте работу, которую выдаёте"' : '' ?>>
    <?php endif; ?>

    <div class="work-row__body">
        <?php if ($name === ''): ?>
            <?php /*
                Пустое название — признак работы, которую начали и не довели.
                Писать «Без названия» нельзя: в списке это выглядело бы как
                настоящее название, и человек его уже не нашёл бы.
            */ ?>
            <em class="muted">Без названия</em>
        <?php else: ?>
            <?php if ($selectable): ?>
                <strong><?= $view->e($name) ?></strong>
            <?php else: ?>
                <strong>
                    <a href="<?= $view->e($view->url('panel/assignments/' . $assignmentId)) ?>">
                        <?= $view->e($name) ?>
                    </a>
                </strong>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($row['subject_name'])): ?>
            <span class="muted"> · <?= $view->e($row['subject_name']) ?></span>
        <?php endif; ?>

        <?php if ($seesAll): ?>
            <span class="muted"> · <?= $view->e((string) ($row['author_name'] ?? '—')) ?></span>
        <?php endif; ?>
    </div>

    <div class="work-row__state">
        <?php if ($isPublished): ?>
            <span class="status status-green">опубликована</span>
        <?php elseif ($isArchived): ?>
            <span class="chip">закрыта</span>
        <?php else: ?>
            <span class="chip">черновик</span>
        <?php endif; ?>
    </div>

    <div class="work-row__stat">
        <span class="muted"><?= $view->e((int) ($row['item_count'] ?? 0)) ?></span>
    </div>

    <div class="work-row__stat">
        <?php $issued = (int) ($row['issued_count'] ?? 0); ?>
        <?php if ($issued > 0): ?>
            <span class="badge"><?= $view->e($issued) ?> выдано</span>
        <?php else: ?>
            <span class="muted">не выдана</span>
        <?php endif; ?>
    </div>

    <div class="work-row__stat">
        <?php $finished = (int) ($row['finished_count'] ?? 0); ?>
        <?php if ($finished > 0): ?>
            <?= $view->e($finished) ?>
        <?php else: ?>
            <span class="muted">—</span>
        <?php endif; ?>
    </div>

    <div class="work-row__stat">
        <?php $review = (int) ($row['review_count'] ?? 0); ?>
        <?php if ($review > 0): ?>
            <span class="status status-yellow"><?= $view->e($review) ?></span>
        <?php else: ?>
            <span class="muted">—</span>
        <?php endif; ?>
    </div>

    <?php if (!$selectable): ?>
        <div class="work-row__actions">
            <?php if ($isMine): ?>
                <a class="btn btn-sm btn-primary"
                   href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/edit/start')) ?>">
                    Редактировать
                </a>
                <a class="btn btn-sm btn-danger"
                   href="<?= $view->e($view->url('panel/assignments/' . $assignmentId . '/delete')) ?>"
                   data-confirm="Удалить работу «<?= $view->e($name) ?>»?"
                   data-confirm-body="Будут удалены все задания работы и результаты всех, кто её выполнял. Восстановить это нельзя."
                   data-confirm-yes="Удалить работу">
                    Удалить
                </a>
            <?php else: ?>
                <?php /*
                    Кнопки править и удалить у чужой работы нет: они всё равно
                    упёрлись бы в проверку авторства, но человек увидел бы
                    возможность и удивлялся бы отказу.
                */ ?>
                <?php /*
 Переход к записи работы — одна и та же кнопка во всех списках.

 Раньше на странице ученика она была синей, а в панели — серой: одна и та же
 надпись «Открыть» выглядела двумя разными действиями, и человек, только что
 пользовавшийся ученической страницей, на панели искал, где такая же. Синяя
 — основное действие в обоих списках: работа одна, и открыть её и есть смысл.
 */ ?>
 <a class="btn btn-sm btn-primary" href="<?= $view->e($view->url('panel/assignments/' . $assignmentId)) ?>">
 Открыть
 </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>