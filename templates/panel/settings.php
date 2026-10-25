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
 * @var bool $releaseOlder найденный на GitHub релиз старее установленной версии
 * @var array{label:string,from:string,email:string,canSend:bool,warnings:list<string>} $mail
 */

$releaseOk = (bool) ($release['ok'] ?? false);
$releaseVersion = (string) ($release['version'] ?? '');
$releaseDate = (string) ($release['published'] ?? '');
$releasePrerelease = (string) ($release['prerelease'] ?? '');

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
 Отправка писем.

 Появился из-за жалобы владельца: письма с подтверждением адреса не доходят,
 а на странице регистрации видно только «письмо отправлено». Здесь видно, чем
 вообще отправляются письма, и проверить доставку можно прямо на странице —
 не разбирая журнал хостинга.

 Блок открыт только администратору вместе со всей страницей.
*/ ?>
<div class="card">
 <h2 class="card-title">Отправка писем</h2>

 <p class="muted">
 Драйвер: <strong><?= $view->e($mail['label']) ?></strong>
 <?php if ($mail['from'] !== ''): ?>
 <br>Адрес отправителя: <strong><?= $view->e($mail['from']) ?></strong>
 <?php endif; ?>
 </p>

 <?php foreach ($mail['warnings'] as $warning): ?>
 <?= $view->raw($view->bannerWarning($warning)) ?>
 <?php endforeach; ?>

 <?php if ($mail['warnings'] === []): ?>
 <p class="muted">
 По настройкам и окружению предупреждений нет: драйвер и адрес отправителя
 в порядке, утилита отправки на месте, у домена есть почтовые серверы и
 разрешение на отправку.
 Если письма всё равно не приходят — нажмите «Отправить тестовое письмо»
 и посмотрите на результат: он показывает, приняла ли почта письмо.
 </p>
 <?php endif; ?>

 <?php if ($mail['canSend']): ?>
 <form method="post" action="<?= $view->e($view->url('panel/settings/mail-test')) ?>">
 <?= $view->raw($view->csrfField()) ?>

  <button type="submit" class="btn btn-primary">Отправить тестовое письмо</button>

 <span class="form-help">
 Письмо уходит тем же путём, что и письма с подтверждением адреса новому
 пользователю: если тестовое дошло, доходят и они. Адрес получателя:
 <?= $view->e($mail['email']) ?>. После отправки проверьте и папку «Спам» —
 попадают туда и нормальные письма.
 </span>
 </form>
 <?php else: ?>
 <p class="muted">
 Тестовое письмо отправить не удастся: у вашей учётной записи не задан адрес почты.
 </p>
 <?php endif; ?>
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
  data-confirm="Файлы системы будут заменены на версию <?= $view->e($releaseVersion) ?>. Перед этим будет снята резервная копия базы. Продолжить?"
  data-confirm-yes="Обновить систему">
  Обновить систему до <?= $view->e($releaseVersion) ?>
  </button>

 <span class="form-help">
 Перед обновлением снимается резервная копия базы, а новые файлы кладутся
 поверх прежних. Настройки сайта, картинки и журналы при этом не трогаются.
 </span>
 </form>
  <?php else: ?>
  <p class="muted">
  <?php if ($releaseOlder): ?>
  <?php /*
   Найденный на GitHub релиз старее установленной версии.

   Обновлений действительно нет, но называть версию с GitHub последней нельзя:
   на сервере стоит другая, и она новее. Раньше страница говорила «Установлена
   последняя версия» и называла версию, которой на сервере нет, — особенно
   заметно сразу после выпуска свежего релиза, когда проверка ещё держит
   старый ответ (четверть часа).
   */ ?>
  Установленная версия <strong><?= $view->e($version) ?></strong> новее найденной на GitHub
  (<?= $view->e($releaseVersion) ?>). Обновлений нет.
  <?php else: ?>
  Установлена последняя версия: <?= $view->e($releaseVersion) ?>. Обновлений нет.
  <?php endif; ?>
  </p>
  <?php endif; ?>

  <?php if ($releasePrerelease !== ''): ?>
  <?php /*
   Предрелиз на GitHub виден отдельной строкой.

   Он не предлагается для установки — отметка «предрелиз» значит «не
   предлагать». Но молчать о нём нельзя: иначе страница говорит «обновлений
   нет», а на GitHub лежит версия новее, и выглядит это как поломка проверки.
   Раньше выходило ровно так: беты проекта помечены как предрелизы, и сайт
   с бетой на сервере не видел их вовсе.
   */ ?>
  <p class="muted">
  На GitHub есть предрелиз <strong><?= $view->e($releasePrerelease) ?></strong>.
  Он не предлагается для установки: снимите с него отметку «предрелиз» в GitHub,
  если он предназначен для вашего сервера.
  </p>
  <?php endif; ?>

  <?php if (!empty($tlsCheckDisabled)): ?>
  <?php /*
   Выключенная проверка сертификата GitHub.

   Обновление кладёт на сервер файлы, из которых работает весь сайт, и при
   выключенной проверке подменить их может тот, кто встал посередине
   соединения. Напоминание стоит в блоке обновления, пока проверка выключена:
   настройка в config.local.php, который страница не читает, — и без этой
   строки владелец мог бы не вспомнить о ней годами.
   */ ?>
  <p class="muted">
  <strong>Проверка сертификата GitHub выключена</strong> (allow_insecure_tls в
  config.local.php): файлы обновления могут быть подменены. Контрольная сумма
  релиза это компенсирует лишь отчасти. Включите проверку обратно, как только
  сервер сможет проверять сертификаты.
  </p>
  <?php endif; ?>
</div>
