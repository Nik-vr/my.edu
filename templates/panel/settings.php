<?php

declare(strict_types=1);

/**
 * Настройки сайта: название школы и прочее, что меняет администратор.
 *
 * @var \MyEdu\Core\View $view
 * @var string $schoolName
 * @var ?string $notice
 * @var ?string $error
 * @var array<string,mixed> $release сведения о последнем релизе на GitHub
 * @var string $version текущая версия системы
 * @var bool $hasUpdate есть ли более свежий релиз
 */

$releaseOk = (bool) ($release['ok'] ?? false);
$releaseVersion = (string) ($release['version'] ?? '');
$releaseDate = (string) ($release['published'] ?? '');

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Настройки сайта', 'url' => ''],
    ]);
?>

<h1 class="page-title">Настройки сайта

</h1>

<div class="card">
 <form method="post" action="<?= $view->e($view->url('panel/settings')) ?>

 ">
 <?= $view->raw($view->csrfField()) ?>

 <label class="form-group">
 <span class="form-label">Название школы</span>
 <input type="text" name="school_name" value="<?= $view->e($schoolName) ?>"
 maxlength="191" autofocus
 placeholder="Муниципальное бюджетное общеобразовательное учреждение «Средняя школа № 1»">
 <span class="form-help">
 Показывается в панели управления, на листах печати и в письмах.
 Оставьте пустым, если школа ещё не выбрана.
 </span>
 </label>

 <button type="submit" class="btn btn-primary btn-lg">Сохранить</button>
 </form>
</div>

<?php /*
 Обновление системы.

 Блок виден только администратору: страница настроек и так открыта только ему
 (право `users.manage`), а обновление меняет файлы, из которых работает весь
 сайт.

 Проверка новой версии идёт по GitHub, и сервер может быть без доступа в
 интернет — тогда блок говорит об этом прямо, а не молчит.
*/ ?>
<div class="card">
 <h2 class="card-title">Обновление</h2>

 <p class="muted">
 Текущая версия: <strong><?= $view->e($version) ?></strong>
 </p>

 <?php if (!$releaseOk): ?>
 <p class="muted">
 Последнюю версию проверить не удалось:
 <?= $view->e((string) ($release['error'] ?? 'сервер не ответил.')) ?>
 Проверка повторяется при следующем открытии страницы.
 </p>
 <?php elseif ($hasUpdate): ?>
 <p>
 На GitHub есть более свежая версия:
 <strong><?= $view->e($releaseVersion) ?></strong>
 <?php if ($releaseDate !== ''): ?>
 (от <?= $view->e(date('d.m.Y', strtotime($releaseDate))) ?>)
 <?php endif; ?>
 </p>

 <form method="post" action="<?= $view->e($view->url('panel/settings/update')) ?>">
 <?= $view->raw($view->csrfField()) ?>

 <button type="submit" class="btn btn-primary"
 data-confirm="Файлы системы будут заменены на версию <?= $view->e($releaseVersion) ?>. Перед этим будет снята резервная копия базы. Продолжить?">
 Обновить систему до <?= $view->e($releaseVersion) ?>
 </button>

 <span class="form-help">
 Перед обновлением снимается резервная копия базы, а новые файлы кладутся
 поверх прежних. Настройки сайта, картинки и журналы при этом не трогаются.
 </span>
 </form>
 <?php else: ?>
 <p class="muted">
 Установлена последняя версия: <?= $view->e($releaseVersion) ?>. Обновлений нет.
 </p>
 <?php endif; ?>
</div>
