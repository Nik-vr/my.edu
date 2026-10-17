<?php

declare(strict_types=1);

/**
 * Вкладка «Начальная страница» редактора работы.
 *
 * Всё, что относится к работе целиком, до заданий: о чём она, для какой
 * параллели, сколько времени. Критерии итоговой оценки живут на вкладке
 * «Результат», потому что они зависят от того, сколько баллов в сумме
 * получилось.
 *
 * Число попыток здесь нет и не было смысла: попытки ограничивают при выдаче,
 * где известно, кому работа достанется. Одна работа может быть выдана двум
 * классам по-разному, и одна цифра на странице работы описывала бы только
 * половину случаев.
 *
 * Классы здесь не выбираются, и это не потеря: работа ни к чему не привязана,
 * а кому её выдать и в какой срок решает мастер выдачи. Параллель — только
 * подпись для папки в списке работ.
 *
 * Своей формы у вкладки нет: поля лежат в общей форме редактора, и
 * сохраняются той же кнопкой, что и остальное. Такой же набор полей
 * используется и при создании работы, поэтому он вынесен отдельным файлом и
 * подключается из обоих мест.
 *
 * Ожидает:
 * $view, $assignment, $subjects, $parallel, $hasPlugins, $old
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $assignment
 * @var list<array<string,mixed>> $subjects
 * @var int|null $parallel
 * @var bool $hasPlugins есть ли чем наполнять работу
 * @var array<string,string> $old введённое, если сервер отказал
 */

$assignmentId = (int) $assignment['id'];
$isNew = $assignmentId === 0;

/*
 * Что показать в поле: то, что человек ввёл только что, иначе то, что лежит
 * в базе. Без этого отказ по одному полю стирал бы остальные.
 */
$old = is_array($old ?? null) ? $old : [];

$field = static fn (string $name, mixed $fallback): string => array_key_exists($name, $old)
    ? (string) $old[$name]
    : (string) $fallback;

?>
<?php if (!$hasPlugins): ?>
<?= $view->raw($view->bannerWarning(
    'Подключённых систем проверки нет, поэтому задания добавить будет нечем. '
    . 'Включите хотя бы одну систему проверки в панели плагинов.'
)) ?>
<p><a class="btn btn-sm" href="<?= $view->e($view->url('panel/plugins')) ?>">Открыть панель плагинов</a></p>
<?php endif; ?>

<div class="card">
 <h2 class="card-title">О работе</h2>

<label class="form-group">
<span class="form-label">Название</span>
<input type="text" name="title" required maxlength="191" autofocus
 value="<?= $view->e($field('title', $assignment['title'])) ?>">
<?php if ($isNew): ?>
<span class="form-help">Без названия работа не сохранится: имя ей даёт этот шаг.</span>
<?php endif; ?>
</label>

 <label class="form-group">
 <span class="form-label">Описание</span>
 <textarea name="description" rows="4"><?= $view->e($field('description', $assignment['description'] ?? '')) ?></textarea>
 <span class="form-help">Видно ученику перед началом работы.</span>
 </label>

 <label class="form-group">
 <span class="form-label">Предмет</span>
 <select name="subject_id">
 <option value="">Без предмета</option>
 <?php foreach ($subjects as $subject): ?>
 <option value="<?= $view->e((int) $subject['id']) ?>"
 <?= (int) $field('subject_id', $assignment['subject_id'] ?? 0) === (int) $subject['id'] ? 'selected' : '' ?>>
 <?= $view->e($subject['name']) ?>
 </option>
 <?php endforeach; ?>
 </select>
 </label>

 <label class="form-group">
 <span class="form-label">Время на попытку, минут</span>
 <input type="number" name="time_limit" min="0" max="600"
 value="<?= $view->e($field('time_limit', $assignment['time_limit'] ?? '')) ?>">
 <span class="form-help">Пусто — без ограничения.</span>
 </label>

 <label class="form-group">
 <span class="form-label">Параллель</span>
 <select name="parallel">
 <option value="">Не указана</option>
 <?php for ($n = 1; $n <= 11; $n++): ?>
 <option value="<?= $view->e($n) ?>" <?= (int) $field('parallel', $parallel ?? 0) === $n ? 'selected' : '' ?>>
 <?= $view->e($n . ' ' . $view->plural($n, 'класс', 'класса', 'класса')) ?>
 </option>
 <?php endfor; ?>
 </select>
 <span class="form-help">
 Только для порядка в списке: работа попадёт в папку
 «<?= $view->e($parallel === null ? 'Без параллели' : $parallel . ' ' . $view->plural($parallel, 'класс', 'класса', 'класса')) ?>».
 Ни на что больше не влияет: кому работа будет выдана, сколько попыток и в какой
 срок — решается в мастере выдачи.
 </span>
 </label>
</div>