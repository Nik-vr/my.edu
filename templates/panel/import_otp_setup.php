<?php

declare(strict_types=1);

/**
 * Мастер переноса из OnlineTestPad, шаг 2: что перенесётся, предмет, параллель.
 *
 * Сначала показывается разбор, потом кнопка создания. Человек видит, что
 приехало, ещё до того, как в базе появилась работа: узнать об этом потом
 можно только тем, что работа уже создана.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $parsed
 * @var list<array<string,mixed>> $subjects
 * @var array<string,mixed> $old
 * @var array<string,string> $kindTitles
 */

$questions = is_array($parsed['questions'] ?? null) ? $parsed['questions'] : [];
$skipped = is_array($parsed['skipped'] ?? null) ? $parsed['skipped'] : [];

$view->crumbs([
    ['label' => 'Работы', 'url' => 'panel/assignments'],
    ['label' => 'Перенос', 'url' => 'panel/assignments/import'],
    ['label' => 'OnlineTestPad', 'url' => ''],
]);

$ui = $view->ui();
$transferTab = 'otp';
$field = static fn (string $name, mixed $fallback): string => array_key_exists($name, $old)
    ? (string) $old[$name]
    : (string) $fallback;

$parallels = ['0' => 'Не указана'];
for ($n = 1; $n <= 11; $n++) {
    $parallels[(string) $n] = $n . ' ' . $view->plural($n, 'класс', 'класса', 'классы');
}

/*
 * Предметы как список, а не поле с подсказками.
 *
 * Раньше здесь было текстовое поле со списком подсказок, и браузер рисовал его
 * как меню: выглядело как выпадающий список, а по щелчку открывалось отдельное
 * окно с вариантами. Рядом стояла «Параллель» — настоящий список, и два соседних
 * поля выглядели одинаково, а вели себя по-разному: одно открывалось, другое
 * листалось стрелками. Теперь оба списка.
 *
 * Значение и подпись — одно и то же имя предмета: сервер принимает имя и
 * находит предмет по нему, так что идентификатор в списке здесь лишний.
 */
$subjectChoices = ['' => '— выберите предмет —'];

foreach ($subjects as $subject) {
    $name = trim((string) ($subject['name'] ?? ''));

    if ($name !== '') {
        $subjectChoices[$name] = $name;
    }
}

$subjectChosen = $field('subject', '');

// Прежний введённый предмет мог не быть в списке: показываем его отдельным
// пунктом, чтобы выбор не слетел молча.
if ($subjectChosen !== '' && !isset($subjectChoices[$subjectChosen])) {
    $subjectChoices[$subjectChosen] = $subjectChosen . ' (нет в списке)';
}
?>

<h1 class="page-title"><?= $view->e($title) ?></h1>

<?php require __DIR__ . '/_transfer_tabs.php'; ?>

<div class="card">
 <h2 class="card-title">В файле</h2>

 <table class="table">
 <tbody>
  <tr>
   <th scope="row">Тест</th>
   <td><?= $view->e((string) ($parsed['title'] ?? '')) ?></td>
  </tr>
  <tr>
   <th scope="row">Заданий</th>
   <td><?= count($questions) ?></td>
  </tr>
  <tr>
   <th scope="row">Пропущено</th>
   <td><?= count($skipped) ?></td>
  </tr>
  <tr>
   <th scope="row">Время на попытку</th>
   <td><?= $parsed['timeLimit'] === null
     ? 'без ограничения'
     : (int) $parsed['timeLimit'] . ' мин.' ?></td>
  </tr>
 </tbody>
 </table>

 <?php /*
 Инструкция к тесту едет вместе с ним и станет описанием работы. Показывается
 на разборе, чтобы учитель прочитал её до того, как нажмёт кнопку.
 Разметка уже очищена разбором — здесь она выводится как есть.
 */ ?>
 <?php if (($parsed['description'] ?? '') !== ''): ?>
 <div class="muted"><?= $view->raw((string) $parsed['description']) ?></div>
 <?php endif; ?>
</div>

<div class="card">
 <h2 class="card-title">Задания</h2>

 <ul>
 <?php foreach ($questions as $at => $question): ?>
 <li>
  <strong>№<?= (int) ($question['number'] ?? $at + 1) ?>.</strong>
  <?= $view->e($kindTitles[(string) ($question['type'] ?? '')] ?? (string) ($question['type'] ?? '')) ?>
  <span class="chip"><?= $view->e(rtrim(rtrim(number_format((float) ($question['weight'] ?? 1), 1, ',', ''), '0'), ',')) ?> б.</span>
  <?php /*
  Условие задания показывается рядом с названием: работа из девяти однотипных
  позиций иначе выглядит девятью одинаковыми строками.
  */ ?>
  <div class="muted"><?= $view->e(mb_substr((string) ($question['plain'] ?? ''), 0, 160, 'UTF-8')) ?></div>
 </li>
 <?php endforeach; ?>
 </ul>
</div>

<?php if ($skipped !== []): ?>
<div class="card">
 <h2 class="card-title">Не перенесено</h2>

 <?= $view->raw($ui->message(
     'Эти задания в работе не появятся: в системе нет таких типов заданий. '
     . 'Их придёся завести вручную или убрать из исходного теста.',
     'warning'
 )) ?>

 <ul>
 <?php foreach ($skipped as $item): ?>
 <li>
  <strong>№<?= (int) ($item['number'] ?? 0) ?>.</strong>
  <?= $view->e((string) ($item['reason'] ?? '')) ?>
  <?php if (($item['preview'] ?? '') !== ''): ?>
  <div class="muted"><?= $view->e((string) $item['preview']) ?></div>
  <?php endif; ?>
 </li>
 <?php endforeach; ?>
 </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= $view->e($view->url('panel/assignments/import/otp/setup')) ?>">
<?= $view->raw($view->csrfField()) ?>

<div class="card">
 <h2 class="card-title">Куда положить</h2>

<?php /*
  Список предметов, а не поле с подсказками. Новый предмет здесь не заводится:
  предметов этого места может быть много, и набор здесь давно не равен всему,
  что есть в системе, — молчаливый список сузил бы выбор. Завести предмет можно
  на странице «Предметы», и в подсказке сказано где.
  */ ?>
  <?= $view->raw($ui->select('subject', $subjectChoices, [
      'label' => 'Предмет',
      'value' => $subjectChosen,
      'hint'  => 'Нет нужного предмета? Заведите его на странице «Предметы» и вернитесь сюда.',
  ])) ?>

  <?= $view->raw($ui->select('parallel', $parallels, [
     'label' => 'Параллель',
     'value' => $field('parallel', '0'),
     'hint'  => 'В выгрузке её нет, а работа без неё не попадёт ни в одну папку списка. '
         . 'Позже сменить можно в редакторе работы.',
 ])) ?>
</div>

<div class="page-actions">
 <button type="submit" class="btn btn-primary">
 Создать работу из <?= count($questions) ?> заданий
 </button>
 <a class="btn btn-ghost"
 href="<?= $view->e($view->url('panel/assignments/import/otp')) ?>">
 Начать заново
 </a>
</div>
</form>