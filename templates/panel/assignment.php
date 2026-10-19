<?php

declare(strict_types=1);

/**
 * Обзор собранной работы.
 *
 * Страница показывает, что получилось, и ведёт в мастер. Настроек на ней
 * нет: всё задаётся по шагам, и второй способ поменять то же самое означал бы
 * два разных ответа на один вопрос.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $items
 * @var string|null $subjectName
 * @var list<array<string,mixed>> $issues
 * @var list<array<string,mixed>> $attempts
 */

use MyEdu\Repository\WorkTree;
$status = (string) $assignment['status'];
$isPublished = $status === 'published';
$parallel = WorkTree::parallelOf($assignment);


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => $view->e($assignment['title']), 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title"><?= $view->e($assignment['title']) ?>

</h1>
 <p class="muted">
 <?php if ($isPublished): ?>
 <span class="status status-green">опубликована</span>
 <?php elseif ($status === 'archived'): ?>
 <span class="chip">закрыта</span>
 <?php else: ?>
 <span class="chip">черновик</span>
 <?php endif; ?>
<?= $view->e($view->count((int) (count($items)),  'задание', 'задания', 'заданий')) ?>
 </p>

 <?php if (! $isAuthor): ?>
 <?php /*
 Пояснение о чужой работе стоит в шапке, а не в ряду кнопок.

 Раньше оно стояло рядом с «Пройти работу», и ряд кнопок превращался в два
 разных предмета: кнопку и абзац. Взгляд цеплялся за текст вместо
 действия, а на узкой странице кнопка уезжала на вторую строку сама по себе.
 Пояснение — о том, что страница для чтения, и читать его нужно там, где
 стоит заголовок работы.
 */ ?>
 <p class="muted">Это работа другого автора. Посмотреть её можно, изменить — нет.</p>
 <?php endif; ?>
</div>

<div class="page-actions">
 <?php if ($canTakeWork): ?>
 <?php /*
 * «Пройти работу» стоит первым и у автора, и у чужой работы.
 *
 * Без неё сотрудник, открывший чужую работу, упирался бы в стену: в
 * его списке работ её нет (он не автор), а искать её среди чужих в
 * списке опубликованных — путь, который заканчивается ничем. Именно
 * поэтому перетаскивание в задании и не на чем было проверить.
 */ ?>
 <a class="btn btn-primary"
 href="<?= $view->e($view->url('work/' . (int) $assignment['id'])) ?>">
 Пройти работу
 </a>
 <?php endif; ?>

 <?php if ($isAuthor): ?>
 <?php /*
 * Мастер редактора — основной способ собирать работу.
 *
 * Эта страница остаётся обзором: всё сразу, одним взглядом. Собирать же
 * работу по этапам удобнее, и кнопка стоит первой — иначе мастер был бы
 * доступен только по вбитому адресу, а человек его не найдёт.
 */ ?>
 <a class="btn btn-primary" href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit')) ?>">
 Собрать по шагам
 </a>

 <?php if ($status === 'draft'): ?>
 <form method="post" action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/status')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="action" value="publish">
 <button type="submit" class="btn btn-primary">Опубликовать</button>
 </form>
 <?php elseif ($isPublished): ?>
 <form method="post" action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/status')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="action" value="archive">
 <button type="submit" class="btn">Закрыть работу</button>
 </form>
 <?php else: ?>
 <form method="post" action="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/status')) ?>">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="action" value="draft">
 <button type="submit" class="btn">Вернуть в черновик</button>
 </form>
 <?php endif; ?>

<?php /*
 * Удаление спрашивает подтверждения — окном, если скрипты доехали, и
 * страницей, если нет.
 *
 * Прямая форма здесь обходила бы подтверждение, ради которого оно и
 * затевалось: снос работы необратим, и одним нажатием Enter в браузере
 * пропали бы чужие результаты. Окно форму не обходит — отправка идёт на тот
 * же адрес и обрабатывается тем же сервером.
 */ ?>
<a class="btn btn-danger"
href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/delete')) ?>"
data-confirm="Удалить работу «<?= $view->e($assignment['title']) ?>»?"
data-confirm-body="Будут удалены все задания работы и результаты всех, кто её выполнял. Восстановить это нельзя."
data-confirm-yes="Удалить работу">
Удалить работу
</a>

 <?php /*
 Выгрузка переноса живёт рядом с удалением: оба действия относятся к
 работе целиком, а не к её части. Файл уносит настройки, задания и
 картинки на другое место — например, с локального сервера на хостинг.
 */ ?>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/export')) ?>">
 Выгрузить перенос
 </a>
 <?php else: ?>
<?php /*
 Чужая работа — только для чтения. Кнопки правки убираются целиком, а
 не делаются отключёнными: они всё равно упёрлись бы в отказ по
 авторству, и человек увидел бы возможность, которой нет. Пояснение
 переехало в шапку страницы — см. выше.
 */ ?>
 <?php endif; ?>
</div>


<div class="card">
 <h2 class="card-title">Как собрана работа</h2>

 <?php /*
 * Обзор без форм.
 *
 * Настройки работы задаются в мастере, и второй набор форм здесь был бы
 * не «запасным», а вторым местом, где то же самое можно поменять иначе.
 * Обзор показывает собранное и ведёт в мастер — всё, что для этого нужно.
 */
 ?>

<ul class="facts">
<li class="facts__row">
<span class="facts__label">Описание</span>
<span class="facts__value">
<?= trim((string) ($assignment['description'] ?? '')) === ''
? '<span class="muted">не задано</span>'
: $view->e($assignment['description']) ?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Предмет</span>
<span class="facts__value"><?= $view->e($subjectName ?? '—') ?></span>
</li>
<li class="facts__row">
<span class="facts__label">Параллель</span>
<span class="facts__value">
<?php if ($parallel !== null): ?>
<?= $view->e($view->count((int) ($parallel),  'класс', 'класса', 'классы')) ?>
<br><span class="muted">Для порядка в списке. Кому выдана работа, решает мастер выдачи.</span>
<?php else: ?>
<span class="muted">не указана</span>
<?php endif; ?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Время на попытку</span>
<span class="facts__value">
<?= $assignment['time_limit'] === null ? 'без ограничения' : $view->e((int) $assignment['time_limit']) . ' мин.' ?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Попыток</span>
<span class="facts__value">
<?php
/*
 * Лимита у работы нет: он принадлежит выдаче. Одна работа может быть выдана
 * двум классам по-разному, поэтому единственное верное число здесь — «задаётся
 * при выдаче», а конкретные значения видны в списке выдач.
 */
$issueLimits = [];

foreach ($issues as $issue) {
    $issueLimits[] = (int) ($issue['max_attempts'] ?? 0) === 0
        ? 'без ограничения'
        : $view->e((int) $issue['max_attempts']);
}

echo $issueLimits === []
    ? 'задаётся при выдаче'
    : implode('; ', array_unique($issueLimits));
?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Итог</span>
<span class="facts__value">
<?= ($assignment['criteria_mode'] ?? 'percent') === 'score' ? 'по баллам' : 'по проценту выполнения' ?>
</span>
</li>
<li class="facts__row">
<span class="facts__label">Результат ученику</span>
<span class="facts__value">
<?= (int) ($assignment['show_results'] ?? 0) === 1 ? 'показывается' : 'скрыт' ?>
</span>
</li>
</ul>

 <?php if ($isAuthor): ?>
 <p class="page-actions">
 <a class="btn btn-primary"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit')) ?>">
 Собрать по шагам
 </a>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit/items')) ?>">
 Задания
 </a>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/edit/result')) ?>">
 Правила оценки
 </a>
 </p>
 <?php endif; ?>
</div>

<div class="card">
 <div class="card-header">
 <h2 class="card-title">Выдачи</h2>
 <a class="btn btn-sm btn-primary"
 href="<?= $view->e($view->url('panel/issues/new?assignment=' . (int) $assignment['id'])) ?>">
 Выдать работу
 </a>
 </div>

 <?php if ($issues === []): ?>
 <p class="muted">
 Работа ещё никому не выдана. Пока не выдана, её не видит ни один
 ученик, даже опубликованная.
 </p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Выдана</th>
 <th scope="col">Доступна</th>
 <th scope="col">Учеников</th>
<th scope="col">Выполнили</th>
<th scope="col">Ждут проверки</th>
<th scope="col"></th>
</tr>
</thead>
<tbody>
<?php foreach ($issues as $issue): ?>
<tr>
<td><?= $view->e($issue['created_at']) ?></td>
<td>
<?php if ($issue['available_from'] === null && $issue['available_until'] === null): ?>
всегда
<?php else: ?>
<?= $view->e((string) ($issue['available_from'] ?? 'сейчас')) ?>
—
<?= $view->e((string) ($issue['available_until'] ?? 'бессрочно')) ?>
<?php endif; ?>
</td>
<td><?= $view->e((int) $issue['issued_count']) ?></td>
<td><?= $view->e((int) $issue['finished_count']) ?></td>
<?php /*
 Значок «требует проверки» и кнопка «Открыть» стояли в одной ячейке, и
 кнопки разных строк не выравнивались: у первой строки перед кнопкой
 оказывался значок, у остальных — ничего, и глазам приходилось искать
 кнопку в каждой строке отдельно. Теперь это две колонки: состояние и
 действие. Пустая ячейка состояния оставлена всё равно — иначе колонка
 прыгала бы по ширине от строки к строке.
*/ ?>
<td class="nowrap">
<?php if ((int) $issue['pending_review_count'] > 0): ?>
<?php require __DIR__ . '/_needs_review_badge.php'; ?>
<?php endif; ?>
</td>
<td class="row-actions">
<a class="btn btn-sm" href="<?= $view->e($view->url('panel/issues/' . (int) $issue['id'])) ?>">
Открыть
</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
 </table>
 </div>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">Попытки учеников</h2>

 <?php if ($attempts === []): ?>
 <p class="muted">Попыток пока нет.</p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Ученик</th>
 <th scope="col">Попытка</th>
 <th scope="col">Состояние</th>
 <th scope="col">Балл</th>
 <th scope="col">Оценка</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($attempts as $attempt): ?>
 <tr>
 <td><?= $view->e($attempt['full_name']) ?></td>
 <td><?= $view->e((int) $attempt['attempt_no']) ?></td>
 <td>
 <?php if ((int) $attempt['manual'] === 1): ?>
 <span class="status status-yellow">ждёт проверки</span>
 <?php elseif ($attempt['status'] === 'finished'): ?>
 <span class="status status-green">завершена</span>
 <?php else: ?>
 <span class="chip">в процессе</span>
 <?php endif; ?>
 </td>
 <td>
 <?php if ($attempt['score'] === null): ?>
 <span class="muted">—</span>
 <?php else: ?>
 <?= $view->e(rtrim(rtrim(number_format((float) $attempt['score'], 2, ',', ''), '0'), ',')) ?>
 /
 <?= $view->e(rtrim(rtrim(number_format((float) $attempt['max_score'], 2, ',', ''), '0'), ',')) ?>
 <?php endif; ?>
 </td>
 <td><?= $view->e($attempt['grade'] ?? '—') ?></td>
 <td class="nowrap">
 <?php if ($attempt['status'] !== 'finished'): ?>
 <span class="muted">—</span>
 <?php elseif ($isAuthor): ?>
 <?php /*
 * Проверка остаётся автору работы: она ставит
 * оценки и пишет комментарии, то есть меняет
 * чужой результат. Заведующий чужую работу
 * посмотреть может, а проверять её за автора —
 * нет. Поэтому у чужой работы ссылка на
 * проверку не показывается вовсе.
 */ ?>
 <a class="btn btn-sm"
 href="<?= $view->e($view->url('panel/assignments/' . (int) $assignment['id'] . '/attempts/' . (int) $attempt['id'])) ?>">
 <?= (int) $attempt['manual'] === 1 ? 'Проверить' : 'Открыть' ?>
 </a>
 <?php else: ?>
 <span class="muted">—</span>
 <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>
