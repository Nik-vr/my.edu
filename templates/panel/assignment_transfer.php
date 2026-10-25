<?php

declare(strict_types=1);

/**
 * Перенос работ: приложить пакет и увидеть, что внутри.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed>|null $package разобранный пакет, если он приложен
 * @var int $maxUpload
 */

$work = is_array($package) ? $package['work'] : [];
$items = is_array($work) ? ($work['items'] ?? []) : [];
$media = is_array($package) ? ($package['media'] ?? []) : [];
$transferTab = 'own';



$view->crumbs([
        ['label' => 'Работы', 'url' => 'panel/assignments'],
        ['label' => 'Перенос', 'url' => ''],
    ]);
?>

<h1 class="page-title"><?= $view->e($title) ?></h1>

<?php require __DIR__ . '/_transfer_tabs.php'; ?>

<div class="card">
 <h2 class="card-title">Внести работу из файла</h2>

 <p class="muted">
 Файл взят в другом сервисе на базе my.edu при помощи функции
 «Выгрузить перенос» на странице работы. Переносится всё, что собрал
 мастер: настройки, шкала, задания с вопросами и формулами, а также
 картинки, загруженные при их создании.
 </p>

 <p class="muted">
 Данные о том, кому работа выдавалась и кто её выполнял, не переносятся.
 Перенесённая работа попадает в «Черновики», её нужно проверить
 и опубликовать.
 </p>

 <form method="post" enctype="multipart/form-data"
 action="<?= $view->e($view->url('panel/assignments/import/upload')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <label class="form-group">
 <span class="form-label">Файл переноса</span>
 <input type="file" name="package" accept=".zip,application/zip" required>
 <span class="form-help">
 Обычное расширение .zip. До <?= (int) ($maxUpload / 1024 / 1024) ?> МБ.
 </span>
 </label>

 <button type="submit" class="btn btn-primary">Посмотреть, что внутри</button>
 </form>
</div>

<?php if ($package !== null): ?>
 <?php /*
 Сначала показывается, потом вносится. Человек видит, что приехало,
 ещё до того, как в базе появилась работа: узнать об этом постфактум
 можно только тем, что работа уже создана.
 */ ?>
 <div class="card">
 <h2 class="card-title">В пакете</h2>

 <table class="table">
 <tbody>
 <tr>
 <th scope="row">Работа</th>
 <td><?= $view->e((string) ($work['title'] ?? '')) ?></td>
 </tr>
 <tr>
 <th scope="row">Предмет</th>
 <td><?= $view->e((string) ($work['subject'] ?? '') ?: 'без предмета') ?></td>
 </tr>
 <tr>
 <th scope="row">Заданий</th>
 <td><?= count($items) ?></td>
 </tr>
 <tr>
 <th scope="row">Картинок</th>
 <td><?= count($media) ?></td>
 </tr>
 </tbody>
 </table>

 <?php if ($items !== []): ?>
 <h3 class="card-subtitle">Задания</h3>

 <ul>
 <?php foreach ($items as $item): ?>
 <?php if (is_array($item)): ?>
 <li>
 <?php
 /*
  * Задание в пакете назвать нечем: заголовок ушёл из переноса вместе с
  * колонкой, а условия в пакете нет — плагин другой установки, и его сюда
  * не положить. Показывать «Без названия» значило написать одно и то же
  * столько раз, сколько заданий, и не сказать автору ничего.
  *
  * Пакет всё же несёт номер строки и плагина, и по ним автор свою работу
  * узнаёт: та же нумерация, что на её странице.
  */
 ?>
 №<?= $view->e((string) ($item['position'] ?? '?')) ?>
 <span class="muted">— <?= $view->e((string) ($item['plugin'] ?? 'плагин неизвестен')) ?></span>
 </li>
 <?php endif; ?>
 <?php endforeach; ?>
 </ul>
 <?php endif; ?>

 <form method="post" action="<?= $view->e($view->url('panel/assignments/import')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <div class="page-actions">
 <button type="submit" class="btn btn-primary">Внести на это место</button>
 <a class="btn btn-ghost" href="<?= $view->e($view->url('panel/assignments')) ?>">Отмена</a>
 </div>
 </form>
 </div>
<?php endif; ?>
