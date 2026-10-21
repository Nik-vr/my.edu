<?php

declare(strict_types=1);

/**
 * Редактор класса: вкладка «Класс и ученики».
 *
 * Ученики и логины в одной таблице. Раньше это были две страницы — список
 * учеников и отдельный лист с паролями, и один и тот же человек показывался
 * дважды: в списке без пароля и в листе с логином.
 *
 * Пароля в таблице нет: он хранится хешем, а выданный показывается один раз —
 * на листе, который открывается сразу тем ответом, которым пароль выдан.
 *
 * Ожидает:
 * $view, $class, $tab, $students, $notice, $error, $importErrors
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $class
 * @var string $tab
 * @var list<array<string,mixed>> $students
 * @var list<array{line:int,message:string}> $importErrors
 * @var int $editStudentId номер ученика, открытого для правки имени; 0 — никого
 */

$classId = (int) $class['id'];


$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Классы', 'url' => 'classes'],
        ['label' => $view->e($class['name']), 'url' => ''],
    ]);
?>

<div class="page-header">
 <h1 class="page-title">Класс <?= $view->e($class['name']) ?>

</h1>
 <?php if (!empty($class['title'])): ?>
 <p class="muted"><?= $view->e($class['title']) ?></p>
 <?php endif; ?>
</div>

<?php require __DIR__ . '/_class_tabs.php'; ?>

<?php if ($importErrors !== []): ?>
 <div class="card">
 <h2 class="card-title">Замечания по импорту</h2>
 <ul class="issue-list">
 <?php foreach ($importErrors as $issue): ?>
 <li>строка <?= $view->e((int) $issue['line']) ?> — <?= $view->e($issue['message']) ?></li>
 <?php endforeach; ?>
 </ul>
 </div>
<?php endif; ?>

<div class="card">
 <h2 class="card-title">Класс</h2>

 <form method="post" action="<?= $view->e($view->url('classes/' . $classId)) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <label class="form-group">
 <span class="form-label">Номер класса</span>
  <input type="text" name="name" value="<?= $view->e($old['name'] ?? $class['name']) ?>"
 required maxlength="32" data-hint="Укажите номер класса, например 7-Б">
 </label>

 <label class="form-group">
 <span class="form-label">Название</span>
  <input type="text" name="title" value="<?= $view->e($old['title'] ?? ($class['title'] ?? '')) ?>"
 maxlength="40">
 </label>

 <label class="form-group">
 <span class="form-label">Учебный год</span>
 <input type="text" name="year" inputmode="numeric" pattern="[0-9]{4}(-[0-9]{4})?"
  value="<?= $view->e($old['year'] ?? \MyEdu\Auth\ClassRepository::academicYear($class['year'] ?? null)) ?>"
 maxlength="9" placeholder="2026-2027"
 data-hint="Год одним числом или парой: 2026 или 2026-2027">
 <span class="form-help">
 С 1 сентября по 31 мая. Можно написать «2026» или «2026-2027» —
 в обоих случаях получится 2026-2027.
 </span>
 </label>

 <button type="submit" class="btn btn-primary">Сохранить</button>
 </form>
</div>

<div class="split">
<div class="card">
  <h2 class="card-title">Добавить ученика</h2>
  <p class="muted">
  Система выдаст логин (русская буква и цифры) и пароль из восьми цифр и
  сразу покажет лист на печать. Лист сохраните: пароль хранится хешем, и
  повторно увидеть его можно только сменой — «Выдать пароль» в меню ученика.
  </p>

 <form method="post"
 action="<?= $view->e($view->url('classes/' . $classId . '/students')) ?>"
class="btn-group">
 <?= $view->raw($view->csrfField()) ?>

<label class="form-group">
 <span class="form-label">Фамилия, имя и отчество</span>
 <input type="text" name="full_name" required maxlength="191" autocomplete="name"
  value="<?= $view->e($studentAddOld) ?>" placeholder="Петров Иван Ильич">
 </label>

 <button type="submit" class="btn btn-primary">Зарегистрировать</button>
 </form>
 </div>

 <div class="card">
 <h2 class="card-title">Загрузить список класса</h2>
 <p class="muted">
 Формат фиксированный: три колонки — Фамилия; Имя; Отчество.
 Файл сохраняйте в кодировке UTF-8.
 </p>

 <p>
 <a class="btn btn-sm"
 href="<?= $view->e($view->url('classes/' . $classId . '/students/template')) ?>">
 Скачать форму
 </a>
 </p>

 <form method="post" enctype="multipart/form-data"
 action="<?= $view->e($view->url('classes/' . $classId . '/students/import')) ?>"
class="btn-group">
 <?= $view->raw($view->csrfField()) ?>

 <label class="form-group">
 <span class="form-label">Файл со списком</span>
 <input type="file" name="file" accept=".csv,text/csv" required>
 <span class="form-help">Максимум 1 МБ.</span>
 </label>

 <button type="submit" class="btn btn-primary">Загрузить и зарегистрировать</button>
 </form>
 </div>
</div>

<div class="card">
 <h2 class="card-title">
 Ученики класса
 <?php if ($students !== []): ?>
 <span class="muted">(<?= $view->e(count($students)) ?>)</span>
 <?php endif; ?>
 </h2>

<?php if ($students === []): ?>
 <p class="muted">В классе пока нет учеников.</p>
 <?php else: ?>
 <?php $rowNumber = 1; ?>
 <?php /*
 * Одна форма на отметки и на обе кнопки под таблицей. Отметки живут в
 * таблице, и две отдельные формы означали бы, что работает только
 * одна из них.
 *
 * Форма стоит ВНЕ таблицы, а отметки привязаны к ней атрибутом .
 * Пока она охватывала таблицу, все формы внутри строк оказывались
 * вложенными, а вложенных форм в HTML нет: браузер выбрасывал тег и
 * отправлял поля «Сохранить имя», «Заблокировать» и «Убрать» во внешнюю
 * форму, то есть никуда. Признак был такой: проверки проходили, потому
 * что отправляли форму напрямую мимо страницы, а человек нажимал
 * кнопку и не видел ничего.
 */
 ?>
 <form method="post" id="roster-pick" data-select-all-form>
 <?= $view->raw($view->csrfField()) ?>
 </form>

 <div class="table-responsive">
 <table class="table credential-table">
 <thead>
 <tr>
<th scope="col" class="credential-table__num">№</th>
<th scope="col" class="credential-table__pick">
<?php /*
* «Выбрать всех» ставит отметки всем, но вручную
* снятые сохраняются: снимать людей по одному
* приходится чаще, чем отмечать всех заново.
*/ ?>
<input type="checkbox" data-select-all form="roster-pick"
aria-label="Выбрать всех учеников">
</th>
<th scope="col" class="credential-table__name">Ученик</th>
  <th scope="col">Логин</th>
  <th scope="col">Состояние</th>
 <th scope="col"></th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($students as $student): ?>
 <?php $studentId = (int) $student['id']; ?>
 <?php
 /*
 * Обычный вид ячейки с именем собирается один раз на
 * строку: подставляется и как есть, когда правка не
 * открыта, и внутрь <template>, когда открыта.
 */
 ob_start();
 ?>
 <div class="inline-value">
 <span class="inline-value__text"><?= $view->e($student['full_name']) ?></span>
  <a class="icon-btn"
  href="<?= $view->e($view->url('classes/' . $classId . '?edit=' . $studentId)) ?>"
  title="Изменить имя"
  aria-label="Изменить имя: <?= $view->e($student['full_name']) ?>">
  <?php $icon = 'edit'; ?>
  <?php require __DIR__ . '/../_action_icon.php'; ?>
  </a>
 </div>
 <?php $nameBlock = (string) ob_get_clean(); ?>
 <tr>
 <?php /*
 * Порядковый номер строки — просто счётчик по списку.
 *
 * Свои номера у ученика нет, а разговаривать приходится
 * именно по номеру строки: «у третьего в таблице
 * опечатка». Нумерация привязана к позиции, а не к
 * учётной записи, поэтому сортировка её не сдвинет, и
 * сослаться можно на то, что человек видит перед собой.
 */ ?>
 <td class="credential-table__num"><?= $rowNumber++ ?></td>
 <td class="credential-table__pick">
<input type="checkbox" name="students[]" value="<?= $view->e($studentId) ?>"
  form="roster-pick"
  data-select-item
  aria-label="Выбрать <?= $view->e($student['full_name']) ?>">
 </td>
 <td class="inline-cell credential-table__name">
 <?php /*
 * Открытая строка — это адрес: /classes/5?edit=17.
 * Карандаш ведёт на него, «Отмена» уводит без него,
 * сохранение возвращает на страницу без него же.
 *
 * Открытие поля осталось серверным намеренно: страница
 * тогда зависела сразу от двух вещей — от пришедшего
 * стиля (форма прячется правилом [hidden]) и от
 * пришедшего скрипта (он её открывает). Стоит одной
 * из двух не дойти — и у первого ученика появляется
 * готовое поле правки, которое никто не открывал.
 *
 * Сохранение, наоборот, ушло скрипту: перезагрузка
 * возвращала человека к началу таблицы, и правка
 * одного имени стоила двадцати пяти прокруток. Если
 * скрипт не доехал или сеть отвалилась, форма
 * уходит обычным POST — правка сохранится и там.
 */
  $isEditing = $editStudentId === $studentId;
  $renameValue = is_array($studentRenameOld ?? null)
      && (int) ($studentRenameOld['id'] ?? 0) === $studentId
      ? (string) ($studentRenameOld['full_name'] ?? $student['full_name'])
      : (string) $student['full_name'];
 ?>
 <?php if ($isEditing): ?>
 <form method="post"
 action="<?= $view->e($view->url('classes/' . $classId . '/students/rename')) ?>"
class="inline-form"
 data-inline-form
 data-inline-message="Имя сохранено">
 <?= $view->raw($view->csrfField()) ?>
 <input type="hidden" name="student_id" value="<?= $view->e($studentId) ?>">
<input type="text" name="full_name" required maxlength="191" autocomplete="off"
  value="<?= $view->e($renameValue) ?>"
  aria-label="Фамилия, имя и отчество"
  autofocus>
 <button type="submit" class="icon-btn icon-btn--save" title="Сохранить имя">
 <?php $icon = 'copy'; ?>
 <?php require __DIR__ . '/../_action_icon.php'; ?>
 <span class="visually-hidden">Сохранить имя</span>
 </button>
 <a class="icon-btn" title="Отменить"
 href="<?= $view->e($view->url('classes/' . $classId)) ?>">
 <?php $icon = 'cancel'; ?>
 <?php require __DIR__ . '/../_action_icon.php'; ?>
 <span class="visually-hidden">Отменить</span>
 </a>
 </form>
 <?php /*
 * Разметка обычного состояния строки — имя и
 * карандаш — приходит вместе с формой в
 * <template>.
 *
 * Скрипту после сохранения нужно вернуть строку
 * к этому виду, и собирать её вручную означало бы
 * держать одну и ту же разметку в двух местах: здесь
 * и в файле скрипта. Второе место со временем
 * разошлось бы с первым, и правка выглядела бы
 * несохранившейся.
 *
 * Сам <template> браузер не рисует, поэтому без
 * скриптов он просто не занимает места и ничего не
 * ломает.
 */ ?>
 <template data-inline-template><?= $view->raw($nameBlock) ?></template>
 <?php else: ?>
 <?= $view->raw($nameBlock) ?>
 <?php endif; ?>
 </td>
<td><code><?= $view->e($student['login']) ?></code>
<?php
/*
 * Выданный пароль виден в строке.
 *
 * Лист с паролями уходит на печать, а учитель часто не печатает: он идёт по
 * классу и диктует пароль ученику. Таблица — место, где человек ищет логины и
 * пароли, и пустым паролем в ней быть не должно.
 *
 * Показывается только тот, что выдан недавно: в базе пароля нет, он лежит
 * хешем, и второй раз его не достать — только выдать заново.
 */
$issuedPassword = (string) (($issuedPasswords[(string) $studentId]['password'] ?? ''));
?>
<?php if ($issuedPassword !== ''): ?>
<br><code class="issued-password"><?= $view->e($issuedPassword) ?></code>
<?php endif; ?>
</td>
  <td>
 <?php if ($student['status'] === 'blocked'): ?>
 <span class="status status-red">заблокирован</span>
 <?php else: ?>
 <span class="chip">активен</span>
 <?php endif; ?>
 </td>
<td class="row-actions">
<?php /*
* Действия ученика — меню, а не ряд кнопок.
*
* Раньше здесь стояли два значка: «Заблокировать» и «Убрать из класса».
* Третье действие — выдать пароль — в ряд не поместилось бы, а именно оно
* нужно чаще всего: учитель идёт по классу и раздаёт пароли. Меню держит
* любое число пунктов в одной ячейке и не растягивает строку.
*
* Меню сделано на <details>, а не скриптом шаблона: <details> раскрывается
* сам, и все три пункта остаются рабочими без скриптов. Скрипт (row-menu.js)
* только приподнимает меню над обрезкой таблицы.
*/ ?>
<details class="row-menu">
<summary class="card-opt-btn"
aria-label="Действия: <?= $view->e($student['full_name']) ?>">
<?php $icon = 'more'; ?>
<?php require __DIR__ . '/../_action_icon.php'; ?>
</summary>
<div class="menu-popover" role="menu">

<form method="post"
action="<?= $view->e($view->url('classes/' . $classId . '/credentials/issue')) ?>">
<?= $view->raw($view->csrfField()) ?>
<input type="hidden" name="student_id" value="<?= $view->e($studentId) ?>">
<button type="submit" class="menu-item" role="menuitem">
Выдать пароль
</button>
</form>

<?php if ($student['status'] === 'blocked'): ?>
<form method="post"
action="<?= $view->e($view->url('classes/' . $classId . '/students/status')) ?>">
<?= $view->raw($view->csrfField()) ?>
<input type="hidden" name="student_id" value="<?= $view->e($studentId) ?>">
<input type="hidden" name="status" value="active">
<button type="submit" class="menu-item" role="menuitem">
Разблокировать ученика
</button>
</form>
<?php else: ?>
<form method="post"
action="<?= $view->e($view->url('classes/' . $classId . '/students/status')) ?>">
<?= $view->raw($view->csrfField()) ?>
<input type="hidden" name="student_id" value="<?= $view->e($studentId) ?>">
<input type="hidden" name="status" value="blocked">
<button type="submit" class="menu-item menu-item--danger" role="menuitem"
data-confirm="Заблокировать <?= $view->e($student['full_name']) ?>?"
data-confirm-body="Человек не сможет войти на сайт, пока
блокировку не снимут. Логины, пароли и история попыток останутся на месте."
data-confirm-yes="Заблокировать">
Заблокировать ученика
</button>
</form>
<?php endif; ?>

<div class="menu-separator" role="separator"></div>

<?php /* Кнопка «Правка имени» из этого списка убрана: карандаш стоит
* рядом с самим именем, где и находится то,
* что правят. В списке действий он был
* через три кнопки от своего предмета. */ ?>

<form method="post"
action="<?= $view->e($view->url('classes/' . $classId . '/students/remove')) ?>">
<?= $view->raw($view->csrfField()) ?>
<input type="hidden" name="student_id" value="<?= $view->e($studentId) ?>">
<button type="submit" class="menu-item menu-item--danger" role="menuitem"
data-confirm="Убрать <?= $view->e($student['full_name']) ?> из класса?"
data-confirm-body="Учётная запись и история попыток останутся, но в классе
ученик числиться не будет, и выдать ему работу станет нельзя."
data-confirm-yes="Убрать из класса">
Убрать из класса
</button>
</form>

</div>
</details>
</td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>

<div class="page-actions">
  <?php /*
  Кнопка привязана к форме отметок атрибутом form: стоящая вне неё кнопка
  отправляла бы сама себя, без отметок, и никто бы не получил лист.

  Раньше здесь стояли две кнопки — «Печать паролей» и «Выдать новые пароли»,
  и пароль можно было распечатать задним числом: он лежал в сессии с момента
  выдачи. Теперь лист появляется один раз, сразу при выдаче, и печатать
  задним числом нечего: кнопка «Печать паролей» была бы обещанием, которое
  система не выполняет.

  Подтверждение здесь обязательно: кнопка необратимо меняет пароли всех
  отмеченных учеников и сразу уходит на печать. Отметили случайно всех вместо
  одного — прежние пароли уже не работают, а новый лист на руках.

  Адрес указан явно через data-confirm-action: кнопка отправляет форму
  `roster-pick` на другой адрес, и окно подтверждения без этой подсказки
  отправило бы её туда, откуда форма и так уходит, — то есть просто перезаписало
  бы отметки.
  */ ?>
  <button type="submit" class="btn btn-primary" form="roster-pick"
  formaction="<?= $view->e($view->url('classes/' . $classId . '/credentials/reveal')) ?>"
  data-confirm="Выдать новые пароли отмеченным ученикам?"
  data-confirm-body="Прежние пароли перестанут работать, а новый лист
сразу уйдёт на печать. Ученикам, которых отметили случайно, придётся
получить пароль заново."
  data-confirm-action="<?= $view->e($view->url('classes/' . $classId . '/credentials/reveal')) ?>"
  data-confirm-yes="Выдать пароли">
  Выдать новые пароли
  </button>
  </div>

  <p class="muted">
  «Выдать новые пароли» сразу покажет лист на печать и уйдёт на печать сам.
  Прежние пароли перестанут работать. Отмечать можно всех сразу — галочка
  в шапке.
  </p>

 <p class="muted">
 «Убрать из класса» не удаляет учётную запись: она остаётся в системе
 вместе с историей попыток. Снос всего класса уносит учеников — об
 этом предупреждает страница удаления.
 </p>
 <?php endif; ?>
</div>

<script src="<?= $view->e($view->asset('assets/js/select-all.js')) ?>" defer></script>
