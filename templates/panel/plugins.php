<?php

declare(strict_types=1);

/**
 * Плагины системы оценивания.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $rows
 * @var string $pluginsDir
 * @var bool $isAdmin
 * @var ?string $notice
 * @var ?string $error
 */

$ui = $view->ui();

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Плагины', 'url' => ''],
    ]);
?>

<?php /*
 * Ответ сервера показывается на странице.
 *
 * Раньше контроллер клал сообщение в flash, а шаблон его не выводил: «плагин
 * включён» и «плагин не найден» не появлялись нигде, и после нажатия ничего не
 * менялось на глазах. У переключателя это тем более заметно — щелчок без
 * ответа выглядит как поломка.
 */ ?>
<?php if ($notice !== null && $notice !== ''): ?>
<?= $view->raw($view->bannerOk($notice)) ?>
<?php endif; ?>

<?php if ($error !== null && $error !== ''): ?>
<?= $view->raw($view->bannerError($error)) ?>
<?php endif; ?>

<div class="card">
 <h1 class="card-title">Плагины</h1>

 <p class="card-subtitle">
 Плагин — это отдельный способ проверки знаний: тест, игра, тренажёр,
 квиз. Установленный плагин добавляет в систему свои виды заданий, и они
 появляются в списке типов при составлении работы.
 </p>

 <?php /*
 Набор свой у каждого: учитель отключает лишние типы заданий у себя и не
 трогает настройки коллег. Раньше переключатель был один на всех, и лишнее
 убрать не мог никто.

 «Тест» — исключение: без него работа не собирается, и его переключатель
 общий, доступный администратору. Учитель видит тот же переключатель, но
 неактивный: решение не его, и он должен видеть, что оно принято.
 */ ?>
 <?= $view->raw($ui->message(
     'Переключатель слева — ваш: он меняет только ваш набор типов заданий и ни на кого '
     . 'влияет. У плагина «Тест» переключатель общий — его включает администратор.',
     'info'
 )) ?>

  <?= $view->raw($view->bannerWarning(
      'Плагин — обычная программа на PHP: он видит базу и файлы сайта. '
      . 'Включайте только тот код, которому доверяете.'
  )) ?>

 <p>
 Установка: положить папку с плагином в каталог
 <code><?= $view->e($pluginsDir) ?></code> и включить его здесь.
 Ядро менять не нужно.
 </p>
</div>

<div class="card">
 <h2 class="card-title">Установленные и найденные</h2>

 <?php if ($rows === []): ?>
 <p class="muted">
 В каталоге нет ни одного плагина. Скопируйте туда папку с
 <code>plugin.json</code> — и он появится в этом списке.
 </p>
 <?php else: ?>
 <div class="table-responsive">
 <table class="table">
 <thead>
 <tr>
 <th scope="col">Плагин</th>
 <th scope="col">Виды заданий</th>
 <th scope="col">Состояние</th>
 <th scope="col" class="row-actions">Включён</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($rows as $row): ?>
 <tr>
 <td>
 <strong><?= $view->e($row['name']) ?></strong>
 <span class="chip">
 <?= $view->e($row['id']) ?> <?= $view->e($row['version']) ?>
 </span>
 <?php if ($row['author'] !== ''): ?>
 <br><span class="muted"><?= $view->e($row['author']) ?></span>
 <?php endif; ?>
 <?php if ($row['description'] !== ''): ?>
 <br><span class="muted"><?= $view->e($row['description']) ?></span>
 <?php endif; ?>
 </td>

 <td>
 <?php if ($row['kinds'] === []): ?>
 <span class="muted">—</span>
 <?php else: ?>
 <?= $view->e(implode(', ', $row['kinds'])) ?>
 <?php endif; ?>
 </td>

<td>
 <?php if ($row['error'] !== ''): ?>
  <span class="status status-red">не загрузился</span>
 <?php elseif ($row['locked']): ?>
 <?php /*
 Общий переключатель: состояние одно на всех, и решение принимает
 администратор. Учитель должен видеть, что «Тест» есть и что это не его
 решение, — поэтому строка есть, а двигать её нельзя.
 */ ?>
  <span class="status status-green">включён</span>
  <?php if (!$isAdmin): ?>
  <br><span class="muted">включается администратором</span>
  <?php endif; ?>
 <?php elseif (!$row['installed']): ?>
 <?php /*
 Не установлен — раньше, чем личная настройка, и это единственный порядок,
 при котором две колонки строки не противоречат друг другу.

 Личная настройка по умолчанию «включено»: у нового плагина нет строки в
 user_plugins, и она читается как включённый. Проверка шла личной настройкой
 первой, и строка говорила «включён у вас» — при том, что плагин не стоит в
 системе, видов заданий у него нет, а колонка действий в этой же строке
 отвечала «не установлен».
 */ ?>
  <span class="chip">не установлен</span>
 <?php elseif ($row['available']): ?>
  <span class="status status-green">включён у вас</span>
 <?php else: ?>
  <span class="chip">выключен у вас</span>
 <?php endif; ?>

 <?php if ($row['error'] !== ''): ?>
 <br><span class="muted"><?= $view->e($row['error']) ?></span>
 <?php endif; ?>
 </td>

 <td class="row-actions">
  <?php if ($row['error'] !== ''): ?>
  <?php /* Не загрузился — включать нечего: код плагина не читается, и
   * включение только спрятало бы это. */ ?>
  <span class="muted">—</span>
  <?php elseif ($row['locked'] && !$isAdmin): ?>
  <?php /*
  Показывается, но не двигается. Без disabled отправленная форма убрала бы
  «Тест» у всего сайта, и одного подделанного запроса хватило бы.
  */ ?>
  <label class="switch plugin-switch--locked">
  <input type="checkbox" checked disabled
  aria-label="Плагин <?= $view->e($row['name']) ?>: включён, переключается администратором">
  <span class="track"></span>
  </label>
  <?php elseif ($row['locked']): ?>
  <form method="post"
  action="<?= $view->e($view->url('panel/plugins/toggle')) ?>"
  class="plugin-switch">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="id" value="<?= $view->e($row['id']) ?>">
  <input type="hidden" name="enabled" value="0">
  <label class="switch">
  <input type="checkbox"
  name="enabled"
  value="1"
  <?= $row['enabled'] ? 'checked' : '' ?>
  aria-label="Плагин <?= $view->e($row['name']) ?>: включён на всём сайте">
  <span class="track"></span>
  </label>
  <button type="submit" class="btn btn-sm plugin-switch-save">Сохранить</button>
  </form>
  <?php elseif (!$row['installed']): ?>
  <?php /*
  Переключателя нет — переключать нечего, плагин ещё не стоит в системе.
  Но и кнопки установки раньше не было: адрес `panel/plugins/enable` на странице
  не встречался ни разу, а текст рядом звал «включите его здесь». Учитель,
  положивший папку в plugins/, получал строку с пометкой «не установлен» и
  без единого способа это исправить — виды заданий так и не появлялись.

  Кнопка отправляет тот же `toggle` с `enabled=1`: адрес один, и установку
  делает он же — при первом включении плагину ставятся его таблицы.
  */ ?>
  <form method="post"
        action="<?= $view->e($view->url('panel/plugins/toggle')) ?>"
        class="plugin-switch">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="id" value="<?= $view->e($row['id']) ?>">
  <input type="hidden" name="enabled" value="1">
  <button type="submit" class="btn btn-sm">Установить</button>
  </form>
  <?php else: ?>
  <?php /*
  Переключатель вместо двух кнопок: состояние видно на самом переключателе,
  а колонка «Состояние» рядом осталась не из-за этого — там написано то, чего
  переключатель не показывает: «не загрузился» с ошибкой, «не установлен» и
  «выключен у вас».

  Форма отправляется кнопкой, которая в разметке видна: без скриптов это
  единственный путь. Скрипт кнопку убирает и отправляет форму сам.
  */ ?>
  <form method="post"
  action="<?= $view->e($view->url('panel/plugins/toggle')) ?>"
  class="plugin-switch">
  <?= $view->raw($view->csrfField()) ?>
  <input type="hidden" name="id" value="<?= $view->e($row['id']) ?>">
  <input type="hidden" name="enabled" value="0">
  <label class="switch">
  <input type="checkbox"
  name="enabled"
  value="1"
<?= $row['available'] ? 'checked' : '' ?>
    aria-label="Плагин <?= $view->e($row['name']) ?>: включён у вас">
    <span class="track"></span>
    </label>
    <button type="submit" class="btn btn-sm plugin-switch-save">Сохранить</button>
  </form>
  <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>

 <p class="muted">
 Выключение не удаляет данные плагина: его работы, попытки и оценки
 остаются, и вернуть плагин можно одной галочкой.
 </p>
 <?php endif; ?>
</div>
