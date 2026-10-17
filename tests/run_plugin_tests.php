<?php

declare(strict_types=1);

/**
 * Тесты реестра плагинов.
 *
 * Проверяются на живой файловой системе и тестовой базе: настоящая папка
 * плагина, настоящее включение, настоящая битая папка.
 *
 * Работает с тестовой базой и меняет только таблицу plugins.
 *
 * Запуск: php tests/run_plugin_tests.php
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

require $rootDir . '/tests/support/TestKit.php';

use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\Html;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\MailMessage;
use MyEdu\Core\PhpMailer;
use MyEdu\Core\SchemaMigrator;
use MyEdu\Core\SchemaState;
use MyEdu\Core\View;
use MyEdu\Auth\Auth;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\PluginMigrationRunner;

if (getenv('MYEDU_DB_NAME') === false || getenv('MYEDU_DB_NAME') === '') {
    putenv('MYEDU_DB_NAME=my_edu_review_test');
}

$app = App::boot($rootDir);
/** @var Database $db */
$db = $app->get(Database::class);

/*
 * Набор выключает и включает плагин «test», а это запись в таблицу плагинов.
 * Раньше страховки здесь не было, и ручной запуск файла без MYEDU_DB_NAME
 * менял бы состояние рабочей базы — то самое, от которого потом не
 * восстановиться без вмешательства руками.
 */
assertTestDatabase($db);

/** @var PluginManager $plugins */
$plugins = $app->get(PluginManager::class);

$t = new TestRunner();

echo 'База: ' . $db->value('SELECT DATABASE()') . "\n\n";

/*
 * Плагин «Вариативная задача» включается интеграционным набором работ: там он
 * нужен включённым, иначе нечего проходить. Здесь он выключается — иначе
 * проверки каталога ниже видели бы в списке видов чужое задание и падали не по
 * своей причине.
 */
if ($plugins->isEnabled('variable')) {
    $plugins->disable('variable');
}

// ------------------------------------------------------------------ каталог

$t->group('Поиск плагинов на диске');

$discovered = $plugins->discover();

$t->is(isset($discovered['test']), 'плагин из plugins/ найден');
$t->equals('Тест с вопросами', $discovered['test']->name ?? '', 'название взято из манифеста');
$t->equals('1.0.0', $discovered['test']->version ?? '', 'версия взята из манифеста');
$t->is(!$discovered['test']->isBroken(), 'манифест прошёл проверки');
$t->is(is_file($discovered['test']->entryPath()), 'файл точки входа на месте');

/*
 * Чужая папка в каталоге не должна ломать панель: без манифеста она молча
 * пропускается, с неверным id — помечается битой, но не падает.
 */
$probeDir = $rootDir . '/plugins/probe-broken';
@mkdir($probeDir, 0o775, true);
file_put_contents($probeDir . '/plugin.json', '{"id": "Плохой ID", "name": "битый"}');

$afterProbe = $plugins->discover();

$t->is(!isset($afterProbe['probe-broken']), 'плагин с неверным id не принят');
$t->is(count($afterProbe) === count($discovered), 'битая папка не изменила список');

@mkdir($rootDir . '/plugins/probe-nomanifest', 0o775, true);
$withoutManifest = $plugins->discover();
$t->is(count($withoutManifest) === count($discovered), 'папка без манифеста пропущена');

@unlink($probeDir . '/plugin.json');
@rmdir($probeDir);
@rmdir($rootDir . '/plugins/probe-nomanifest');

$manifestProbeDir = $rootDir . '/plugins/probe-requires';
@mkdir($manifestProbeDir, 0o775, true);
file_put_contents($manifestProbeDir . '/plugin.json', json_encode([
    'id' => 'probe-requires',
    'name' => 'Проверка версии ядра',
    'version' => '1.0.0',
    'namespace' => 'MyEduPlugin\\ProbeRequires',
    'entry' => 'src/ProbeRequiresPlugin.php',
    'requires' => ['core' => '99.0'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$manifestManager = new PluginManager(
    $app,
    $db,
    $rootDir . '/plugins',
    $app->get(View::class),
    $app->get(Auth::class)
);
$incompatible = $manifestManager->descriptor('probe-requires');

$t->is($incompatible?->isBroken() === true, 'манифест с завышенным requires.core помечен как несовместимый');
$t->contains($incompatible?->errorMessage() ?? '', 'требуется ядро', 'причина несовместимости понятна');

file_put_contents($manifestProbeDir . '/plugin.json', json_encode([
    'id' => 'probe-requires',
    'name' => 'Проверка системного префикса',
    'version' => '1.0.0',
    'namespace' => 'MyEduPlugin\\ProbeRequires',
    'entry' => 'src/ProbeRequiresPlugin.php',
    'url_prefix' => 'panel',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$prefixManager = new PluginManager(
    $app,
    $db,
    $rootDir . '/plugins',
    $app->get(View::class),
    $app->get(Auth::class)
);
$reservedPrefix = $prefixManager->descriptor('probe-requires');
$t->is($reservedPrefix?->isBroken() === true, 'манифест не занимает системный префикс маршрутов');

@unlink($manifestProbeDir . '/plugin.json');
@rmdir($manifestProbeDir);

// ------------------------------------------------------------------ включение

$t->group('Включение и выключение');

$plugins->disable('test');
$t->is(!$plugins->isEnabled('test'), 'после выключения плагин выключен');
$t->is($plugins->get('test') === null, 'выключенный плагин не загружается');
$t->equals([], $plugins->taskKindsByPlugin(), 'выключенный плагин не даёт видов заданий');

$enable = $plugins->enable('test');
$t->is($enable['ok'], 'плагин включился');
$t->is($plugins->isEnabled('test'), 'состояние включения записано');
$t->is($plugins->isInstalled('test'), 'отметка об установке есть');

$t->group('Отображение статусов панели');
$pluginsView = new View($rootDir . '/templates');
$pluginsView->addTemplateRoot('test', $rootDir . '/plugins/test/templates');
/*
 * Строки панели собираются с полем `available`: личная настройка плюс факт,
 * что плагин стоит в системе. У только что положенной папки личная настройка
 * «включено» по умолчанию, и без этого поля страница говорила «включён у вас»
 * о плагине, у которого нет ни таблиц, ни видов заданий.
 *
 * `probe-new` поэтому включён лично, но не установлен — ровно то состояние,
 * в котором оказывается папка, положенная в plugins/.
 */
$statusRows = [
    ['id' => 'probe-enabled', 'name' => 'Активный', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => '', 'enabled' => true, 'available' => true, 'installed' => true, 'locked' => false, 'base' => false],
    ['id' => 'probe-disabled', 'name' => 'Выключенный', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => '', 'enabled' => false, 'available' => false, 'installed' => true, 'locked' => false, 'base' => false],
    ['id' => 'probe-new', 'name' => 'Новый', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => '', 'enabled' => true, 'available' => false, 'installed' => false, 'locked' => false, 'base' => false],
    ['id' => 'probe-dead', 'name' => 'Погашенный', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => '', 'enabled' => true, 'available' => false, 'installed' => true, 'locked' => false, 'base' => false],
    ['id' => 'probe-error', 'name' => 'С ошибкой', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => 'Ошибка теста', 'enabled' => false, 'available' => false, 'installed' => false, 'locked' => false, 'base' => false],
    ['id' => 'test', 'name' => 'Тест', 'version' => '1.0', 'author' => '', 'description' => '', 'kinds' => [], 'error' => '', 'enabled' => true, 'available' => true, 'installed' => true, 'locked' => true, 'base' => true],
];
$pluginsMarkup = $pluginsView->render('panel/plugins', [
    'rows' => $statusRows,
    'pluginsDir' => 'plugins/',
    'isAdmin' => false,
    'notice' => null,
    'error' => null,
], '');
/*
 * Статусы — разметка шаблона gentelella: точка состояния для «включён» и
 * «не загрузился», обычная метка для нейтральных состояний. Различие здесь не
 * украшение: человек обязан отличить «плагин есть, но выключен у меня» от
 * «плагина нет вовсе» — и цветом точки это читается, а без точки два серых
 * слова рядом выглядели бы одинаково.
 */
$t->contains($pluginsMarkup, 'status status-green">включён у вас', 'панель показывает включённый статус');
$t->contains($pluginsMarkup, 'chip">выключен у вас', 'панель различает плагин, выключенный у человека');
$t->contains($pluginsMarkup, 'chip">не установлен', 'панель помечает новый плагин как неустановленный');
$t->contains($pluginsMarkup, 'status status-red">не загрузился', 'панель показывает ошибку загрузки отдельно');

/*
 * «Тест» — основной плагин: переключатель общий и учителю не принадлежит.
 *
 * Учитель должен видеть, что плагин есть и что решение не его, поэтому строка
 * на месте и подпись про администратора тоже. Двигать переключатель нельзя:
 * подделанный запрос иначе убрал бы «Тест» у всего сайта.
 */
$baseRow = '';
foreach (preg_split('#<tr>#u', $pluginsMarkup) ?: [] as $part) {
    if (str_contains($part, '>Тест<')) {
        $baseRow = $part;
    }
}

$t->contains($pluginsMarkup, 'включается администратором', 'учителю сказано, что «Тест» включает администратор');
$t->is(
    preg_match('~<input type="checkbox" checked disabled~', $baseRow) === 1,
    'переключатель «Теста» у учителя неактивен'
);
$t->notContains($baseRow, 'value="test"', 'у неактивного переключателя нет формы — отправить его нечем');
$t->contains($baseRow, 'plugin-switch--locked', 'неактивный переключатель приглушён, чтобы было видно, что он не нажимается');

/*
 * Включение и выключение — переключатель, а не две кнопки подряд.
 *
 * Кнопки отвечали на вопрос «что нажимать», а не «включён ли плагин»: у
 * выключенного плагина на странице стояло «Включить», у включённого —
 * «Выключить», и состояние приходилось читать по подписи. Переключатель
 * показывает состояние сам, а кнопка сохранения осталась в разметке для
 * случая без скриптов — иначе управление плагинами зависело бы от JavaScript.
 */
$pluginRow = static function (string $id) use ($pluginsMarkup): string {
    foreach (preg_split('#<tr>#u', $pluginsMarkup) ?: [] as $part) {
        if (str_contains($part, 'value="' . $id . '"')) {
            return $part;
        }
    }

    return '';
};

$switchTag = static function (string $row): string {
    return preg_match('#<input[^>]*type="checkbox"[^>]*name="enabled"[^>]*>#u', $row, $tagMatch) ? $tagMatch[0] : '';
};

$enabledRow = $pluginRow('probe-enabled');
$t->contains($enabledRow, 'panel/plugins/toggle', 'переключатель отправляет на адрес переключения');
$t->contains($enabledRow, '<input type="hidden" name="enabled" value="0">',
    'снятый переключатель тоже отправляется — как «выключено»');
$t->is(str_contains($switchTag($enabledRow), 'checked'), 'у включённого плагина переключатель включён');

$disabledRow = $pluginRow('probe-disabled');
$t->is($switchTag($disabledRow) !== '', 'у выключенного установленного плагина переключатель есть');
$t->is(!str_contains($switchTag($disabledRow), 'checked'), 'у выключенного плагина переключатель выключен');

$t->equals('', $switchTag($pluginRow('probe-new')), 'не установленному плагину переключатель не нужен');
$t->equals('', $switchTag($pluginRow('probe-error')), 'не загрузившемуся плагину переключатель не нужен');

$t->contains($enabledRow, 'plugin-switch-save', 'кнопка сохранения осталась в разметке для случая без скриптов');
$t->notContains($pluginsMarkup, 'panel/plugins/enable', 'отдельной кнопки включения в разметке нет');
$t->notContains($pluginsMarkup, 'panel/plugins/disable', 'отдельной кнопки выключения в разметке нет');

/*
 * У неустановленного плагина есть кнопка установки.
 *
 * Раньше её не было вовсе: рядом стоял текст «положите папку с плагином в
 * каталог plugins/ и включите его здесь», а «включить здесь» было только
 * словами — адрес `panel/plugins/enable` на странице не встречался ни разу.
 * Учитель, положивший папку, получал строку с пометкой «не установлен» и без
 * единого способа это исправить, а виды заданий так и не появлялись.
 *
 * Отправляется тот же `toggle` с `enabled=1`: адрес один, и установку делает
 * он же — при первом включении плагину ставятся его таблицы.
 */
$newRow = $pluginRow('probe-new');

$t->contains($newRow, 'panel/plugins/toggle', 'установка идёт тем же адресом переключения');
$t->contains($newRow, '<input type="hidden" name="enabled" value="1">',
    'установка отправляет «включено» — иначе плагин остался бы неустановленным');
$t->contains($newRow, '>Установить<', 'у неустановленного плагина есть кнопка установки');
$t->contains($newRow, 'name="id" value="probe-new"', 'кнопка установки знает, какого плагина касается');

/*
 * Две колонки одной строки не противоречат друг другу.
 *
 * Личная настройка по умолчанию «включено»: строки в user_plugins у нового
 * плагина нет, и она читается как включённая. Проверка «не установлен» шла
 * после неё, и строка говорила «включён у вас» — при том, что плагин не стоит
 * в системе, а колонка действий в этой же строке отвечала «не установлен».
 */
$t->contains($newRow, 'chip">не установлен', 'состояние неустановленного плагина — «не установлен»');
$t->notContains($newRow, 'status status-green">включён у вас',
    'неустановленный плагин не выдаёт себя за включённый только потому, что настройки по умолчанию «включено»');
$t->notContains($pluginRow('probe-error'), 'status status-green">включён у вас',
    'незагрузившийся плагин не выдаёт себя за включённый');

/*
 * Установленная, но погашенная папка — тоже не «включён у вас».
 *
 * Живость берётся тем же, чем пользуется каталог видов заданий, а не отметкой
 * об установке: `installed_at` остаётся навсегда, и по нему одному плагин,
 * выключенный глобально, выглядел включённым — при пустом каталоге заданий.
 * Личная настройка здесь «включено», то есть по умолчанию; судить надо по тому,
 * работает ли плагин у человека сейчас.
 */
$deadRow = $pluginRow('probe-dead');

$t->notContains($deadRow, 'status status-green">включён у вас',
    'погашенный плагин не выдаёт себя за включённый — колонка состояния');
$t->contains($deadRow, 'chip">выключен у вас', 'у погашенного плагина честное состояние');
$t->is($switchTag($deadRow) !== '', 'у погашенного установленного плагина переключатель есть — вернуть можно');
$t->is(!str_contains($switchTag($deadRow), 'checked'), 'переключатель погашенного плагина выключен');
$t->contains($deadRow, 'panel/plugins/toggle', 'возврат погашенного плагина идёт тем же адресом');

$plugin = $plugins->get('test');
$t->is($plugin instanceof AssessmentPlugin, 'класс плагина загружен');

if ($plugin instanceof AssessmentPlugin) {
    $t->equals('test', $plugin->id(), 'id совпадает с манифестом');
    /*
     * Проверяется не количество, а наличие нужных маршрутов: добавление
     * редактора или экрана ответов добавляет маршруты, и падение проверки на
     * «стало 9 вместо 6» ни о чём не говорит.
     *
     * Отдельно проверяется, что адресов самостоятельных тестов не осталось:
     * задание выдаётся только внутри работы, и возврат адреса прохождения
     * означал бы, что мимо работы снова можно ходить по тесту.
     */
$routePaths = array_map(
    static fn (array $route): string => $route[1],
    $plugin->routes()
);
$t->equals($plugin->routes(), $plugins->routesFor('test'),
    'реестр валидирует и возвращает объявленные маршруты плагина');

/*
 * Отдельной страницы редактора и публикации задания у плагина больше нет:
 * задание правится в списке позиций работы, а публикуется работа целиком.
 * Адреса проверяются на отсутствие — вернуться они могут незаметно, вместе
 * со ссылкой из мастера.
 */
$t->is(in_array('/{id}', $routePaths, true), 'плагин объявляет адрес показа задания');
$t->is(in_array('/task/{id}', $routePaths, true), 'плагин принимает сохранение задания по своему адресу');
$t->is(!in_array('/task/{id}/status', $routePaths, true), 'адреса публикации задания у плагина нет');

$methodAndPath = array_map(
    static fn (array $r): string => $r[0] . ' ' . $r[1],
    $plugin->routes()
);

$t->is(
    !in_array('GET /task/{id}', $methodAndPath, true),
    'у адреса редактора задания нет варианта GET: страницы не существует'
);

/*
 * Своего адреса загрузки картинки у плагина нет: он не свойство плагина.
 * Проверяется именно отсутствие — у вариативной задачи такой адрес то ли
 * остался невыдуманным, то ли появился позже, и кнопка картинки молчала.
 */
$t->is(!in_array('/editor/upload', $routePaths, true),
    'плагин не объявляет свою загрузку картинок: адрес один, в ядре');

/*
 * Редактор теста целиком удалён вместе с экраном. Проверяется явно: пока он
 * был жив, автор мог уйти по старой ссылке и увидеть список вопросов вместо
 * своего единственного вопроса задания.
 */
$t->is(!in_array('/editor/{id}', $routePaths, true), 'прежний адрес редактора теста больше не объявляется');

    $standalone = ['/', '/{id}/start', '/{id}/attempt/{attemptId}', '/attempts/{attemptId}/result'];
    $leftOver = array_values(array_intersect($routePaths, $standalone));

    $t->equals([], $leftOver, 'адресов самостоятельных тестов не осталось');

    $kinds = $plugin->taskKinds();

/*
 * Один плагин вправе отдавать несколько видов заданий: «Тест» добавляет по
 * виду на каждый тип вопроса. Проверяется не количество, а то, что виды
 * совпадают с типами вопросов — иначе новый тип вопроса можно будет забыть
 * выставить в работах, и он просто не появится у учителя.
 */
$typeKeys = MyEduPlugin\Test\Question\QuestionTypeRegistry::instance()->keys();
$kindKeys = array_map(static fn (object $kind): string => $kind->slug, $kinds);
$t->equals($kindKeys, array_map(
    static fn (object $kind): string => $kind->slug,
    $plugins->taskKinds('test')
), 'безопасный API реестра возвращает виды заданий плагина');

$t->is(count($kinds) === count($typeKeys), 'плагин объявляет вид задания на каждый тип вопроса');
$t->equals($typeKeys, $kindKeys, 'виды задания совпадают с типами вопросов, по порядку');
$t->is(($kinds[0]->title ?? '') === 'Один вариант ответа', 'у вида задания есть название');
$t->is($kinds[0]->usesPoints(), 'вид задания объявляет, что считается в баллах');

$byPlugin = $plugins->taskKindsByPlugin();

foreach ($typeKeys as $key) {
    $t->is(isset($byPlugin['test'][$key]), 'вид «' . $key . '» попал в общий список для учителя');
}

$t->is(count($byPlugin['test'] ?? []) === count($typeKeys), 'в общем списке нет посторонних видов');
}

$t->group('Повторное включение');

$plugins->disable('test');
$db->run('UPDATE plugins SET installed_at = NULL WHERE id = :id', ['id' => 'test']);
$t->is(!$plugins->isInstalled('test'), 'пустая отметка времени не означает, что миграции применены');

$again = $plugins->enable('test');
$t->is($again['ok'], 'плагин с устаревшей отметкой повторно устанавливается');
$t->is($again['message'] !== '', 'на повторное включение есть ответ');
$t->is($plugins->isInstalled('test'), 'после install отметка установки восстановлена');

// ------------------------------------------------------------------ битый код

$t->group('Битый плагин не роняет сайт');

$brokenDir = $rootDir . '/plugins/probe-code';
@mkdir($brokenDir . '/src', 0o775, true);
file_put_contents(
    $brokenDir . '/plugin.json',
    '{"id":"probe-code","name":"Сломанный","version":"1.0","namespace":"MyEduPlugin\\\\ProbeCode","entry":"src/ProbeCodePlugin.php"}'
);
file_put_contents(
    $brokenDir . '/src/ProbeCodePlugin.php',
    "<?php\n\nnamespace MyEduPlugin\\ProbeCode;\n\nfinal class ProbeCodePlugin\n{\n}\n"
);

$fresh = App::boot($rootDir)->get(PluginManager::class);
$broken = $fresh->enable('probe-code');

$t->is(!$broken['ok'], 'плагин без интерфейса не включается');
$t->contains((string) $broken['message'], 'AssessmentPlugin', 'сказано, что не так с плагином');
$t->is(!$fresh->isEnabled('probe-code'), 'битый плагин остаётся выключен');
$t->is($fresh->get('test') instanceof AssessmentPlugin, 'работающий плагин при этом жив');

$failures = $fresh->failures();
$t->is(isset($failures['probe-code']), 'причина записана для показа в панели');

@unlink($brokenDir . '/src/ProbeCodePlugin.php');
@unlink($brokenDir . '/plugin.json');
@rmdir($brokenDir . '/src');
@rmdir($brokenDir);
$db->run("DELETE FROM plugins WHERE id = 'probe-code'");

$t->group('Каталог в порядке');

$left = array_values(array_diff(scandir($plugins->directory()) ?: [], ['.', '..']));
$t->equals(['README.md', 'test', 'variable'], $left, 'после проверок в каталоге ничего не осталось');

// ------------------------------------------------------------ очистка разметки

$t->group('Очистка разметки по белому списку');

/*
 * Текст вопроса пишет учитель в визуальном редакторе, а читают ученики. Это
 * единственный путь, где в базу попадает HTML, поэтому очистка проверяется не
 * «похоже на разметку», а конкретными способами её пронести.
 */
$attacks = [
    'код в script' => ['<p>Вопрос</p><script>alert(1)</script>', ['script', 'alert']],
    'обработчик onerror' => ['<img src="/uploads/a.png" onerror="alert(1)">', ['onerror']],
    'картинка с чужого адреса' => ['<img src="https://tracker.example/p.gif">', ['tracker.example']],
    'картинка с javascript' => ['<img src="javascript:alert(1)">', ['javascript']],
    'svg со скриптом' => ['<svg onload="alert(1)"></svg>', ['onload']],
    'iframe' => ['<iframe src="https://example.com"></iframe>', ['iframe']],
    'style' => ['<style>body{display:none}</style><p>Текст</p>', ['display:none']],
    'форма' => ['<form action="/steal"><input name="p"></form>', ['<form', '<input']],
    'выход за каталог загрузок' => ['<img src="/uploads/../../config/config.local.php">', ['config.local']],
    'ссылка с javascript' => ['<a href="java&#09;script:alert(1)">клик</a>', ['javascript:']],
];

foreach ($attacks as $name => [$input, $forbidden]) {
    $clean = Html::clean($input);
    $leaked = '';

    foreach ($forbidden as $needle) {
        if (stripos($clean, $needle) !== false) {
            $leaked = $needle;
            break;
        }
    }

    $t->is($leaked === '', 'не пропущено: ' . $name);
}

/*
 * Очистка, которая съедает всё, не отличается от очистки, которая ничего не
 * делает: такую полезно отловить сразу, а не по жалобам учителей.
 */
$keeps = [
    '<p>Жирный <strong>текст</strong> и <em>курсив</em></p>' => '<strong>',
    '<p>H<sub>2</sub>O и x<sup>2</sup></p>' => '<sub>',
    '<ul><li>раз</li><li>два</li></ul>' => '<li>',
    '<p><a href="https://example.com">ссылка</a></p>' => 'href=',
    '<img src="/uploads/abc.png" alt="схема" width="200">' => '/uploads/abc.png',
];

foreach ($keeps as $input => $needle) {
    $t->is(str_contains(Html::clean($input), $needle), 'сохранено форматирование: ' . $needle);
}

/*
 * Пустой абзац приезжает из чужих редакторов сплошным потоком — человек
 * нажал Enter в конце текста и дальше пошло пустое. Разметка безопасная, поэтому
 * очистка пропускала обломок, и он уезжал в базу, а потом в форму редактора
 * уже как часть условия.
 */
$emptyCases = [
    '<p>Первый</p><p></p><p>Второй</p>' => '<p>Первый</p><p>Второй</p>',
    '<p>Текст</p><p></p>' => '<p>Текст</p>',
    '<p>Текст</p><p><br></p>' => '<p>Текст</p>',
    '<p>Текст</p><p>&nbsp;</p>' => '<p>Текст</p>',
    '<p>Текст</p><p><strong></strong></p>' => '<p>Текст</p>',
    '<p></p><p><br></p>' => '',
];

foreach ($emptyCases as $input => $expected) {
    $t->equals($expected, Html::clean($input), 'пустой абзац убран: ' . str_replace(['<p>', '</p>'], '', $input));
}

/*
 * Абзац с картинкой пустым не считается: иначе картинка исчезла бы вместе с
 * ним. Путь берётся из uploads — политика атрибутов отвергает остальные.
 */
$t->is(
    str_contains(Html::clean('<p><img src="/uploads/abc.png"></p>'), '/uploads/abc.png'),
    'абзац с картинкой остался'
);

/*
 * Поиск идёт по чистому тексту. Иначе «хлорофилл» внутри <strong> перестал бы
 * находиться, и верный ответ перестал бы засчитываться.
 */
$t->is(
    str_contains(mb_strtolower(Html::toText('<p>Для растений важен <strong>хлорофилл</strong></p>')), 'хлорофилл'),
    'разметка не мешает найти слово в тексте'
);
$t->is(
    !str_contains(mb_strtolower(Html::toText('<p><strong>хлорофилл</strong></p>')), 'хлорофилл улавливает'),
    'слова из соседних тегов не склеиваются'
);

// ------------------------------------------------------------ приём картинок

$t->group('Приём картинок');

$uploads = new ImageUpload($rootDir);

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$goodPath = tempnam(sys_get_temp_dir(), 'up') . '.png';
file_put_contents($goodPath, $png);

$stored = $uploads->store([
    'name' => 'моя картинка.png',
    'type' => 'image/png',
    'tmp_name' => $goodPath,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($png),
]);

$t->is($stored['ok'] === true, 'настоящая картинка принята');
$t->is(str_starts_with((string) ($stored['url'] ?? ''), '/uploads/'), 'адрес ведёт в каталог загрузок');

/*
 * Имя файла на диске не должно совпадать с присланным: имя приходит от
 * клиента, а с ним в проект попадает и «../../../.htaccess».
 */
$t->is(basename((string) ($stored['url'] ?? '')) !== 'моя картинка.png', 'имя файла выдумано сервером');

$onDisk = $rootDir . '/public' . (string) ($stored['url'] ?? '');
$t->is(is_file($onDisk), 'файл лежит на диске');
@unlink($onDisk);

foreach ([
    'текст с именем .png' => "<?php echo 'x'; ?>",
    'чистый текст' => 'просто текст',
] as $name => $content) {
    $fakePath = tempnam(sys_get_temp_dir(), 'fake') . '.png';
    file_put_contents($fakePath, $content);

    $result = $uploads->store([
        'name' => 'картинка.png',
        'type' => 'image/png',
        'tmp_name' => $fakePath,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($content),
    ]);

    $t->is($result['ok'] === false, 'отклонено: ' . $name);
}

// ------------------------------------------------------------ отправка писем

/*
 * Драйвер 'mail' разбираем без отправки: письмо уходит через sendmail
 * самого хостинга, проверить его можно только на сервере. Здесь важно то,
 * что можно сломать по-настоящему, — заголовки. Тема и имя отправителя
 * кодируются по RFC 2047 и переносятся, иначе длинная русская тема
 * превращается у получателя в мусор.
 *
 * Отдельно проверяется, что сложенная тема собирается обратно в исходную:
 * разрыв внутри четвёрки base64 ломает данные, и письмо уходит с
 * нечитаемой темой, хотя отправка «прошла».
 */
$t->group('Отправка писем');

$mailer = new PhpMailer('no-reply@edu.example', 'Система контроля знаний');

$header = Closure::bind(
    static fn (PhpMailer $m, string $text): string => $m->encodeHeader($text),
    null,
    PhpMailer::class
);

$address = Closure::bind(
    static fn (PhpMailer $m, string $to, ?string $name): ?string => $m->encodeAddress($to, $name),
    null,
    PhpMailer::class
);

$t->equals('mail()', $mailer->name(), 'драйвер называется mail()');

$t->equals(
    'Confirm your account',
    $header($mailer, 'Confirm your account'),
    'ASCII-тема остаётся как есть'
);

$longSubject = 'Подтверждение регистрации в системе контроля знаний — длинная русская тема письма';
$encoded = $header($mailer, $longSubject);

$t->is(str_starts_with($encoded, '=?UTF-8?B?'), 'русская тема закодирована по RFC 2047');

/*
 * Строка заголовка не должна превышать 78 знаков: превышение приводит к
 * тому, что часть MTA обрезает тему или отвергает письмо целиком.
 */
$lines = explode("\r\n", $encoded);
$longest = max(array_map('strlen', $lines));

$t->is($longest <= 78, 'ни одна строка темы не длиннее 78 знаков (максимум ' . $longest . ')');
$t->is(count($lines) > 1, 'длинная тема перенесена');
$t->is(
    $lines[1] === '' || $lines[1][0] === ' ',
    'перенос continuation — с отступом, иначе это новый заголовок'
);

preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/]+=*)\?=/', str_replace("\r\n ", '', $encoded), $chunks);
$decoded = base64_decode(implode('', $chunks[1]), true);

$t->equals($longSubject, $decoded, 'перенесённая тема собирается обратно в исходную');

$t->equals('no-reply@edu.example', $address($mailer, 'no-reply@edu.example', ''), 'адрес без имени остаётся как есть');
$t->equals('no-reply@edu.example', $address($mailer, 'no-reply@edu.example', null), 'пустое имя не добавляется в заголовок');

$from = (string) $address($mailer, 'no-reply@edu.example', 'Система контроля знаний');

$t->is(str_ends_with($from, '<no-reply@edu.example>'), 'имя отправителя добавлено к адресу');
$t->is(str_starts_with($from, '=?UTF-8?B?'), 'имя отправителя закодировано по RFC 2047');

$t->equals(null, $address($mailer, 'не адрес', 'Имя'), 'заведомый мусор в адресе отклонён');

/*
 * Плохие адреса должны отсекаться до обращения к mail(): иначе хостинг
 * получает письмо с мусорным адресатом, а письмо в журнал не попадает.
 */
$badRecipient = new PhpMailer('no-reply@edu.example', 'Проверка');
$t->is(
    $badRecipient->send(new MailMessage('не адрес', 'Тема', 'Текст')) === false,
    'письмо с неверным получателем не отправляется'
);

$badSender = new PhpMailer('не адрес', 'Проверка');
$t->is(
    $badSender->send(new MailMessage('user@example.com', 'Тема', 'Текст')) === false,
    'письмо без корректного отправителя не отправляется'
);

// ------------------------------------------------------- миграции при выкатке

/*
 * Миграции применяются сами при первом запросе после заливки файлов: консоли на
 * шаред-хостинге у владельца нет, и забытый `php database/migrate.php` означал бы
 * падение каждой страницы с «Table ... doesn't exist».
 *
 * Проверяется на настоящей базе тестового набора, но на отдельной таблице
 * отметок и в отдельном каталоге миграций: применять настоящие миграции к
 * тестовой базе нельзя, а проверять тут нужно именно применение.
 */
$t->group('Миграции при выкатке');

$ledger = 'tmp_schema_ledger';
$migrationsDir = sys_get_temp_dir() . '/myedu_migrations_' . getmypid();

$db->run('DROP TABLE IF EXISTS `' . $ledger . '`');
$db->run('DROP TABLE IF EXISTS `tmp_migrated`');

@mkdir($migrationsDir, 0775, true);

$migrator = new SchemaMigrator($db, $migrationsDir, $ledger, 'myedu_test_migrate');

/*
 * Разбор SQL проверяется отдельно от применения: это место, где молча уезжает
 * всё подряд — точка с запятой в строке, `--` в данных, а также метка порядка
 * байтов, которую добавляют редакторы при сохранении файла.
 */
$t->equals(
    ["CREATE TABLE a (id INT)", "INSERT INTO t VALUES ('точка; и тире -- тут')"],
    SchemaMigrator::splitStatements(
        "CREATE TABLE a (id INT);\n"
        . "-- комментарий с ; точкой\n"
        . "INSERT INTO t VALUES ('точка; и тире -- тут');\n"
        . "# ещё комментарий;\n"
    ),
    'комментарии и точка с запятой внутри строки не рвут оператор'
);

$t->equals(
    ['CREATE TABLE b (id INT)'],
    SchemaMigrator::splitStatements("\xEF\xBB\xBFCREATE TABLE b (id INT);"),
    'метка порядка байтов в начале файла не попадает в оператор'
);

file_put_contents($migrationsDir . '/002_ok.sql', "CREATE TABLE tmp_migrated (id INT PRIMARY KEY) ENGINE=InnoDB;\n");

$first = $migrator->apply();

$t->equals('applied', $first['status'], 'первая миграция применена');
$t->equals(
    true,
    $db->value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :n',
        ['n' => 'tmp_migrated']
    ) === 1,
    'изменения из миграции дошли до базы'
);

$second = $migrator->apply();

$t->equals('ok', $second['status'], 'повторный запрос ничего не применяет');
$t->equals([], $second['applied'], 'список применённых при повторе пуст');

/*
 * Упавшая миграция не отмечается: иначе при следующем запросе она считалась бы
 * выполненной, а база осталась бы в промежуточном состоянии навсегда.
 */
file_put_contents($migrationsDir . '/003_broken.sql', "ALTER TABLE `нет_такой` ADD COLUMN `x` INT;\n");

$broken = $migrator->apply();

$t->equals('failed', $broken['status'], 'упавшая миграция помечена как сбой');
$t->equals('003_broken.sql', $broken['file'], 'в ответе виден файл, на котором остановились');
$t->equals(1, $broken['statementIndex'], 'указан номер оператора');
$t->equals(
    true,
    str_contains($broken['message'], 'нет_такой'),
    'в сообщении есть текст самого оператора, а не только код ошибки'
);
$t->equals(
    ['003_broken.sql'],
    $migrator->status()['pending'],
    'упавшая миграция осталась неприменённой и попадёт в следующий запрос'
);

/*
 * Первый каталог убирается здесь, пока переменная ещё указывает на него.
 *
 * Ниже `$migrationsDir` переиспользуется под второй набор файлов, и уборка в
 * конце набора достаётся уже ему: первый каталог оставался в TEMP навсегда,
 * по два файла на каждый прогон. Мелочь, но мусор растёт незаметно.
 */
@unlink($migrationsDir . '/002_ok.sql');
@unlink($migrationsDir . '/003_broken.sql');
@rmdir($migrationsDir);

/*
 * Миграция обязана выкарабкиваться из базы, которая уже наполовину изменена.
 *
 * DDL в MySQL и MariaDB не транзакционный: падение на третьем операторе из пяти
 * оставляет первые два сделанными. На хостинге так и вышло — миграция 020
 * добавила колонку, упала на удалении индекса, и с тех пор каждый повтор падал
 * на первой же операции, потому что колонка уже была.
 *
 * Раньше повторяемость держалась на синтаксисе «если нет», но он есть только
 * у MariaDB: MySQL отвергал такую миграцию целиком. Теперь файлы пишутся
 * обычным DDL, а повторяемость обеспечивает мигратор.
 */
$t->group('Миграции не падают на уже сделанном');

$ledger = 'tmp_schema_ledger2';
$migrationsDir = sys_get_temp_dir() . '/myedu_migrations2_' . getmypid();

$db->run('DROP TABLE IF EXISTS `' . $ledger . '`');
$db->run('DROP TABLE IF EXISTS `tmp_child`');
$db->run('DROP TABLE IF EXISTS `tmp_parent`');

@mkdir($migrationsDir, 0775, true);

$migrator = new SchemaMigrator($db, $migrationsDir, $ledger, 'myedu_test_migrate2');

file_put_contents(
    $migrationsDir . '/001_tables.sql',
    "CREATE TABLE `tmp_parent` (`id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
    . "CREATE TABLE `tmp_child` (`id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
);

// Первая попытка: половина работы уже сделана, а объект, которого нет, убирается.
file_put_contents(
    $migrationsDir . '/002_half.sql',
    "ALTER TABLE `tmp_parent` ADD COLUMN `note` VARCHAR(191) NULL;\n"
    . "ALTER TABLE `tmp_parent` DROP INDEX `такого_индекса_нет`;\n"
);

$half = $migrator->apply();

$t->equals('applied', $half['status'], 'миграция, попавшая на уже сделанное, считается применённой');
$t->equals(
    true,
    (int) $db->value(
        'SELECT COUNT(*) FROM information_schema.columns'
        . ' WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => 'tmp_parent', 'c' => 'note']
    ) === 1,
    'новая колонка из той же миграции всё же добавлена'
);

// Повторный запуск не должен ничего ломать и не должен ничего применять.
$repeat = $migrator->apply();

$t->equals('ok', $repeat['status'], 'повторный запуск после частичной миграции — ничего не делает');

// Внешний ключ с уже занятым именем: MySQL отвечает 1826, MariaDB — 1005/121.
file_put_contents(
    $migrationsDir . '/003_fk.sql',
    "ALTER TABLE `tmp_child` ADD CONSTRAINT `fk_tmp_child`"
    . " FOREIGN KEY (`id`) REFERENCES `tmp_parent` (`id`) ON DELETE CASCADE;\n"
);

$fkFirst = $migrator->apply();
$t->equals('applied', $fkFirst['status'], 'внешний ключ добавлен');

// Та же миграция, но отметка потеряна: база уже с ключом, а журнал пуст.
$db->run('DELETE FROM `' . $ledger . '` WHERE filename = :n', ['n' => '003_fk.sql']);

$fkAgain = $migrator->apply();

$t->equals(
    'applied',
    $fkAgain['status'],
    'повтор внешнего ключа с занятым именем не считается сбоем ни на MySQL, ни на MariaDB'
);
$t->equals(
    1,
    (int) $db->value(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS'
        . ' WHERE constraint_schema = DATABASE() AND table_name = :t AND delete_rule = :d',
        ['t' => 'tmp_child', 'd' => 'CASCADE']
    ),
    'внешний ключ на месте после повторной попытки'
);

/*
 * Настоящий сбой проходить не должен. «Убираемого объекта нет» — это про
 * отсутствие, а «нет такой таблицы» — про поломку: первое миграция переживает,
 * второе обязано упасть, иначе миграция отметилась бы, сделав ровно ничего.
 */
$db->run('DELETE FROM `' . $ledger . '` WHERE filename = :n', ['n' => '002_half.sql']);

file_put_contents(
    $migrationsDir . '/002_half.sql',
    "ALTER TABLE `нет_такой_таблицы` ADD COLUMN `x` INT;\n"
);

$genuine = $migrator->apply();

$t->equals('failed', $genuine['status'], 'настоящая ошибка миграции по-прежнему падает');
$t->equals('002_half.sql', $genuine['file'], 'падение показывает файл с настоящей ошибкой');
$t->equals(
    true,
    $genuine['notes'] === [],
    'настоящая ошибка не попадает в заметки о «уже сделанном»'
);

$db->run('DROP TABLE IF EXISTS `tmp_child`');
$db->run('DROP TABLE IF EXISTS `tmp_parent`');
@unlink($migrationsDir . '/001_tables.sql');
@unlink($migrationsDir . '/002_half.sql');
@unlink($migrationsDir . '/003_fk.sql');
$db->run('DROP TABLE IF EXISTS `' . $ledger . '`');
@rmdir($migrationsDir);

/*
 * Потерянный журнал при новой базе.
 *
 * Ситуация остаётся возможной и после того, как история сборки схемы удалена:
 * базу восстановили из дампа без отметок, журнал обрезали при переносе. Раньше
 * мигратор в такой ситуации начинал с `002_users_and_roles.sql` — той самой
 * миграции первого этапа, — и падал на первом операторе, а сайт не поднимался
 * вместе с `/login`.
 *
 * Проверяется, что база, совпадающая со снимком `database/schema.sql`, приводит
 * журнал в порядок сама, без единого DDL. Своя миграция для этого нужна: на
 * диске их сейчас нет, а проверять по ним было бы нечего.
 */
$t->group('Потерянный журнал при новой базе');

$repairLedger = 'tmp_repair_ledger';
$repairDir = sys_get_temp_dir() . '/myedu_repair_' . getmypid();

$db->run('DROP TABLE IF EXISTS `' . $repairLedger . '`');
$db->run('DROP TABLE IF EXISTS `tmp_repair_table`');
@mkdir($repairDir, 0775, true);

file_put_contents(
    $repairDir . '/001_probe.sql',
    "CREATE TABLE tmp_repair_table (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
);

/*
 * Снимок передаётся явно, а не ищется рядом с каталогом миграций.
 *
 * Мигратор берёт `schema.sql` из родительского каталога того, что ему дали, —
 * приём удобен для настоящих каталогов и невозможен для временного: рядом с
 * ним в `%TEMP%` никакого `schema.sql` нет, и сверка молча решила бы, что
 * проверять нечего.
 */
$snapshot = SchemaState::fromSchemaFile(dirname(__DIR__) . '/database/schema.sql');

$repaired = new SchemaMigrator($db, $repairDir, $repairLedger, 'myedu_test_repair', $snapshot);

$db->run('DROP TABLE IF EXISTS `' . $repairLedger . '`');

$repair = $repaired->reconcile();

$t->equals([], $repair['missing'], 'база набора соответствует database/schema.sql: ни одного недостающего объекта');
$t->equals(true, $repair['alreadyCurrent'] || $repair['repaired'] !== [], 'журнал признан отстающим и починен');
$t->equals(
    count(glob($repairDir . '/*.sql') ?: []),
    count($repair['repaired']),
    'отмечены все миграции с диска, а не часть'
);
$t->equals(
    count($repair['repaired']),
    (int) $db->value('SELECT COUNT(*) FROM `' . $repairLedger . '`'),
    'журнал теперь содержит столько же записей, сколько отмечено'
);

/*
 * Починка не должна выглядеть как применение: если бы миграция выполнилась,
 * база получила бы объект первой ступени поверх снимка схемы. Число таблиц до
 * и после — прямое доказательство, что DDL не выполнялся.
 */
$tablesBefore = (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');

$db->run('DELETE FROM `' . $repairLedger . '`');

$throughGate = $repaired->apply();

$t->equals('repaired', $throughGate['status'], 'обычный путь тоже чинит журнал, а не применяет миграцию');
$t->equals([], $throughGate['applied'], 'ни одна миграция не выполнялась');
$t->equals(
    $tablesBefore,
    (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'),
    'число таблиц не изменилось: ни одного DDL не выполнено'
);
$t->equals('ok', $repaired->apply()['status'], 'после починки повторный запрос ничего не делает');

/*
 * Отсталость базы — не потерянный журнал, и починка обязана её пропустить.
 * Иначе сайт поднялся бы с дырой в схеме, и дыру заметил бы тот, кто напорется
 * позже всех.
 */
$db->run('DELETE FROM `' . $repairLedger . '`');
$db->run('ALTER TABLE `user_plugins` DROP COLUMN `updated_at`');

$behind = $repaired->reconcile();

$t->equals(['колонка user_plugins.updated_at'], $behind['missing'], 'недостающая колонка названа точно');
$t->equals([], $behind['repaired'], 'при отсталой базе журнал не чинится');
$t->equals(false, $behind['alreadyCurrent'], 'отсталая база не объявляется новой');

$db->run('ALTER TABLE `user_plugins` ADD COLUMN `updated_at` DATETIME NULL AFTER `enabled`');
$t->equals(
    count(glob($repairDir . '/*.sql') ?: []),
    count($repaired->reconcile()['repaired']),
    'после возврата объекта починка снова проходит'
);

/*
 * Непрочитанный schema.sql — это «не знаю», а не «новая база».
 * Сверка, не сумевшая ничего проверить, не имеет права записывать в журнал.
 */
/*
 * Непрочитанный schema.sql — это «не знаю», а не «новая база».
 * Сверка, не сумевшая ничего проверить, не имеет права записывать в журнал.
 *
 * Каталог намеренно не создаётся: снимок мигратор ищет рядом с каталогом
 * миграций, и отсутствие каталога даёт нужное «схему прочитать не удалось»
 * без доступа к внутренностям класса, которые снаружи закрыты.
 */
@unlink($repairDir . '/001_probe.sql');

$noSchemaDir = sys_get_temp_dir() . '/myedu_no_schema_' . getmypid();
@rmdir($noSchemaDir);

$noSchema = new SchemaMigrator(
    $db,
    $noSchemaDir,
    $repairLedger,
    'myedu_test_repair_nofile'
);

$blind = $noSchema->reconcile();

$t->equals([], $blind['repaired'], 'без файла схемы журнал не трогается');
$t->equals(false, $blind['alreadyCurrent'], 'без файла схемы вердикта нет');
$t->equals(0, $blind['checked'], 'без файла схемы ничего не проверялось');

$db->run('DROP TABLE IF EXISTS `' . $repairLedger . '`');
$db->run('DROP TABLE IF EXISTS `tmp_repair_table`');
@rmdir($repairDir);
@rmdir($noSchemaDir);

$db->run('DROP TABLE IF EXISTS `tmp_migrated`');

$db->run('DROP TABLE IF EXISTS `' . $ledger . '`');
@rmdir($migrationsDir);

/*
 * Снимок схемы — единственный источник правды о структуре базы.
 *
 * Имя файла миграции и есть отметка о применении: пересоздать файл с прежним
 * именем — значит вернуть старую миграцию, а не добавить новую, и такой файл
 * считается применённым повторно не будет. Отсюда правило, которое проверяется
 * здесь: история сборки схемы удалена, нумерация начинается заново с 001, и
 * номера не должны иметь пропусков.
 *
 * Пропуск означал бы, что в базе на одной инсталляции выполнено то, чего не
 * выполнила другая, — а выяснилось бы это на живых данных.
 */
$t->group('Снимок схемы и нумерация миграций');

$projectMigrations = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($projectMigrations);

$wrongNames = array_values(array_filter(
    $projectMigrations,
    static fn (string $path): bool => preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', basename($path)) !== 1
));

$t->equals([], $wrongNames, 'миграции называются по образцу 001_что_сделали.sql');

$numbers = [];

foreach ($projectMigrations as $path) {
    preg_match('/^(\d+)_/', basename($path), $match);
    $numbers[] = (int) $match[1];
}

sort($numbers);

/*
 * Пустой список сравнивается отдельно. `range(1, 0)` в PHP возвращает `[1, 0]`,
 * то есть убывающий список из двух чисел, и сравнение «нет ни одной миграции»
 * с «есть миграция с номером ноль» молча разошлось бы.
 */
$expectedNumbers = $numbers === [] ? [] : range(1, count($numbers));

$t->equals(
    $expectedNumbers,
    $numbers,
    'нумерация миграций начинается с 001 и идёт без пропусков'
);

/*
 * Каталог миграций должен пережить клонирование репозитория: пустые каталоги
 * git не хранит, и без файла в нём каталога на сервере просто не будет.
 *
 * А это ломает не только красоту: уборка записей журнала специально не
 * трогает базу, если каталога миграций нет, — иначе на половине выкладываемых
 * файлов запись считалась бы потерянной. Тихой поломки тут быть не должно,
 * поэтому наличие README в каталоге проверяется.
 */
$t->is(
    is_file(dirname(__DIR__) . '/database/migrations/README.md'),
    'в каталоге миграций лежит README: каталог переживёт клонирование'
);

/*
 * Устаревшие записи журнала.
 *
 * На инсталляциях, переживших удаление файлов миграций, в `schema_migrations`
 * осталось двадцать четыре записи. Они не влияют на работу — неприменённые
 * файлы считаются по диску, — но выглядят как двадцать четыре применённые
 * миграции, и это вводит в заблуждение при разборе. Убираются сами, и только
 * когда база совпадает со снимком.
 */
$pruneLedger = 'tmp_prune_ledger';
$realMigrationsDir = dirname(__DIR__) . '/database/migrations';

$db->run('DROP TABLE IF EXISTS `' . $pruneLedger . '`');

$pruner = new SchemaMigrator($db, $realMigrationsDir, $pruneLedger, 'myedu_test_prune');

// Журнал создаёт сам мигратор — ровно так же, как это делает боевая база при
// первом обращении после выкатки.
$pruner->status();

$db->run(
    'INSERT INTO `' . $pruneLedger . '` (`filename`, `applied_at`)
     VALUES (:name, NOW()), (:name2, NOW())',
    ['name' => '002_users_and_roles.sql', 'name2' => '026_rate_limits.sql']
);

$pruned = $pruner->apply();

$t->equals('ok', $pruned['status'], 'при отсутствии миграций на диске ничего не применяется');
$t->equals(2, count($pruned['pruned']), 'записи об удалённых миграциях убраны');
$t->equals(
    0,
    (int) $db->value('SELECT COUNT(*) FROM `' . $pruneLedger . '`'),
    'журнал пуст: применённых миграций на диске нет, значит и записей быть не должно'
);
$t->equals('ok', $pruner->apply()['status'], 'повторный запуск ничего не делает');
$t->equals([], $pruner->apply()['pruned'], 'и не вычищает повторно то, чего уже нет');

/*
 * Отсталую базу журнал не трогает. Это не «лишняя запись», а способ узнать,
 * что именно к такой базе применялось, и он ещё понадобится.
 */
$db->run(
    'INSERT INTO `' . $pruneLedger . '` (`filename`, `applied_at`) VALUES (:name, NOW())',
    ['name' => '002_users_and_roles.sql']
);

$db->run('ALTER TABLE `user_plugins` DROP COLUMN `updated_at`');

$behindPrune = $pruner->apply();

$t->equals([], $behindPrune['pruned'], 'при отсталой базе журнал не чистится');
$t->equals(
    1,
    (int) $db->value('SELECT COUNT(*) FROM `' . $pruneLedger . '`'),
    'запись осталась на месте: база отстаёт, журнал ещё нужен'
);

$db->run('ALTER TABLE `user_plugins` ADD COLUMN `updated_at` DATETIME NULL AFTER `enabled`');
$db->run('DELETE FROM `' . $pruneLedger . '`');

/*
 * Нет каталога — это не «миграций нет». На шаред-хостинге выкладывают файлы по
 * одному, и каталог может отсутствовать в середине выкатки. Считать в такой
 * момент записи потерянными нельзя.
 */
$absentDir = sys_get_temp_dir() . '/myedu_absent_migrations_' . getmypid();

@rmdir($absentDir);

$db->run(
    'INSERT INTO `' . $pruneLedger . '` (`filename`, `applied_at`) VALUES (:name, NOW())',
    ['name' => '002_users_and_roles.sql']
);

$absentMigrator = new SchemaMigrator($db, $absentDir, $pruneLedger, 'myedu_test_prune_absent');
$absentResult = $absentMigrator->apply();

$t->equals([], $absentResult['pruned'], 'без каталога миграций журнал не чистится');
$t->equals(
    1,
    (int) $db->value('SELECT COUNT(*) FROM `' . $pruneLedger . '`'),
    'запись сохранена: отсутствие каталога — это незавершённая выкатка'
);

$db->run('DROP TABLE IF EXISTS `' . $pruneLedger . '`');

/*
 * Снимок против живой базы.
 *
 * `missingIn()` спрашивает «есть ли всё нужное» и решает, отстала ли база.
 * `extraIn()` спрашивает обратное: что в базе есть, а в снимке не описано. Для
 * решения «база ли старая» лишнее не мешает, а вот для снимка лишнее — это
 * опечатка, из-за которой свежая установка собирается иначе, чем рабочие базы.
 *
 * Именно так нашлась `assignment_items.title_snapshot`: на инсталляциях она
 * осталась после удаления из кода, а в снимке её не было, и любая миграция,
 * написанная по такой базе, просила бы несуществующий объект.
 *
 * Таблицы плагинов из сверки исключены: по устройству проекта они создаются
 * плагинами и в снимке ядра не описаны. Здесь они уже есть — плагины включены
 * набором раньше, — так что список исключений проверяется по делу.
 */
$state = SchemaState::fromSchemaFile(dirname(__DIR__) . '/database/schema.sql');

$pluginTables = [
    'plugin_migrations',
    'test_item_attempts',
    'test_item_answers',
    'variable_tasks',
    'variable_item_attempts',
    'variable_item_answers',
];

$t->is($state->isParsed(), 'снимок схемы разобран');
$t->equals([], $state->missingIn($db)['missing'], 'снимок описывает базу набора целиком');

/*
 * Что уже есть в базе сверх снимка, фиксируется ДО проверки, а не после.
 *
 * База набора — копия рабочей, и в рабочей осталась `title_snapshot`: колонка,
 * удалённая из кода, но не убранная из базы. Это нормально и по схеме не
 * мешает — лишнее не делает базу старой. Сравнивать надо «было — стало»,
 * иначе проверка самого приёма требовала бы от базы полной чистоты, которой у
 * рабочих баз нет и не будет.
 */
$extraBefore = $state->extraIn($db, $pluginTables);

$t->equals(
    false,
    str_contains(implode(' ', $extraBefore), 'variable_tasks'),
    'таблицы плагинов не считаются лишними: они создаются плагинами'
);

$db->run('ALTER TABLE `settings` ADD COLUMN `zz_left_over` VARCHAR(10) NULL');

$withProbe = $state->extraIn($db, $pluginTables);

/*
 * Списки сравниваются как множества: `extraIn()` возвращает их в порядке
 * information_schema, то есть по именам таблиц, а добавленная колонка в
 * конец списка не попадает. Порядок здесь ничего не значит, и требовать его —
 * значит проверять не то.
 */
$sortedBefore = $extraBefore;
sort($sortedBefore);

$expectedExtra = [...$sortedBefore, 'колонка settings.zz_left_over'];
sort($expectedExtra);

sort($withProbe);

$t->equals(
    $expectedExtra,
    $withProbe,
    'оставшаяся от былой миграции колонка видна и названа точно'
);

$db->run('ALTER TABLE `settings` DROP COLUMN `zz_left_over`');

$t->equals($extraBefore, $state->extraIn($db, $pluginTables), 'после возврата база в прежнем состоянии');

$t->group('Checkpoint миграций плагина');

$migrationProbe = bin2hex(random_bytes(5));
$migrationPluginId = 'probe-' . $migrationProbe;
$migrationTable = 'plugin_migration_' . $migrationProbe;
$migrationDir = sys_get_temp_dir() . '/myedu_plugin_migrations_' . $migrationProbe;
$migrationFile = $migrationDir . '/001_probe.sql';
@mkdir($migrationDir, 0700, true);
$db->run('DROP TABLE IF EXISTS `' . $migrationTable . '`');

$firstStatements = 'CREATE TABLE `' . $migrationTable . '` (value VARCHAR(100) NOT NULL);' . "\n"
    . 'INSERT INTO `' . $migrationTable . '` (value) VALUES (\'before;after\');' . "\n";
file_put_contents(
    $migrationFile,
    $firstStatements . 'INSERT INTO plugin_migration_missing (value) VALUES (\'fail\');'
);

$pluginMigrator = new PluginMigrationRunner($db, $migrationPluginId, $migrationDir);
$stoppedAtStatement = false;

try {
    $pluginMigrator->apply();
} catch (RuntimeException $e) {
    $stoppedAtStatement = str_contains($e->getMessage(), 'оператор 3 из 3');
}

$t->is($stoppedAtStatement, 'ошибка показывает оператор, на котором остановилась установка плагина');
$t->equals(1, (int) $db->value('SELECT COUNT(*) FROM `' . $migrationTable . '`'),
    'checkpoint сохраняет завершённые операторы и не делит точку с запятой в строке');

file_put_contents(
    $migrationFile,
    $firstStatements . 'INSERT INTO `' . $migrationTable . '` (value) VALUES (\'recovered\');'
);
$pluginMigrator->apply();
$pluginMigrator->apply();

$t->equals(2, (int) $db->value('SELECT COUNT(*) FROM `' . $migrationTable . '`'),
    'повтор продолжает с исправленного оператора и не повторяет завершённые');

$db->run('DELETE FROM plugin_migrations WHERE plugin_id = :id', ['id' => $migrationPluginId]);
$db->run('DROP TABLE `' . $migrationTable . '`');
@unlink($migrationFile);
@rmdir($migrationDir);

$savepointTable = 'tmp_savepoint_' . $migrationProbe;
$db->run('CREATE TABLE `' . $savepointTable . '` (value VARCHAR(32) NOT NULL) ENGINE=InnoDB');
$db->transaction(function (Database $db) use ($savepointTable): void {
    $db->run('INSERT INTO `' . $savepointTable . '` (value) VALUES (\'outer-before\')');

    try {
        $db->savepoint(function (Database $db) use ($savepointTable): void {
            $db->run('INSERT INTO `' . $savepointTable . '` (value) VALUES (\'inner-rollback\')');
            throw new RuntimeException('rollback savepoint');
        });
    } catch (RuntimeException) {
        // Имитация отказа создания одной записи при импорте частичного пакета.
    }

    $db->run('INSERT INTO `' . $savepointTable . '` (value) VALUES (\'outer-after\')');
});

$t->equals(2, (int) $db->value('SELECT COUNT(*) FROM `' . $savepointTable . '`'),
    'savepoint откатывает только неудачную операцию и сохраняет остальную транзакцию');
$db->run('DROP TABLE `' . $savepointTable . '`');

/*
 * API типовых элементов интерфейса.
 *
 * Плагин рисует форму через `$ui`, и рисует её ядро. Проверяется, что хуки
 * вообще есть и что элемент на выходе тот же самый, каким его рисует ядро: иначе
 * расхождение вернётся, но заметят его по скриншотам.
 *
 * Здесь же — равенство разметки поля у ядра и у плагина. Это и есть требование
 * «абсолютно идентичны»: сравнивается не класс, а сама строка.
 */
$t->group('API типовых элементов интерфейса');

$ui = (new View($rootDir . '/templates'))->ui();

$t->is($ui === (new View($rootDir . '/templates'))->ui() ? false : true,
    'у вида есть собственный объект элементов');

$hooks = [
    'field', 'textarea', 'select', 'checkbox', 'radio', 'toggle', 'group', 'editor',
    'file', 'hidden', 'message', 'fieldError', 'button', 'optionRow', 'optionRows',
    'editorScripts',
];

foreach ($hooks as $hook) {
    $t->is(method_exists($ui, $hook), 'есть хук ' . $hook);
}

$expectedField = '<div class="form-group"><label class="form-label" for="title">Название</label>'
    . '<input class="form-control" type="text" id="title" name="title" value="Тест" required maxlength="191">'
    . '<div class="form-help">Подсказка</div></div>';

$t->equals($expectedField, $ui->field('title', [
    'label' => 'Название',
    'value' => 'Тест',
    'required' => true,
    'maxlength' => 191,
    'help' => 'Подсказка',
]), 'поле текста рисуется по образцу ядра');

$t->equals(
    '<div class="form-group"><label class="form-label" for="email">Почта</label>'
    . '<input class="form-control is-invalid" type="email" id="email" name="email" value="" aria-invalid="true">'
    . '<div class="form-error">Адрес указан неверно</div></div>',
    $ui->field('email', [
        'label' => 'Почта',
        'type' => 'email',
        'error' => 'Адрес указан неверно',
    ]),
    'поле с ошибкой получает отметку, объявление для экрана и подпись ошибки'
);

$t->equals(
    '<div class="form-group"><label class="form-label" for="mode">Как проверять</label>'
    . '<select class="form-control" id="mode" name="mode">'
    . '<option value="exact">Точно</option><option value="range" selected>Диапазон</option>'
    . '</select></div>',
    $ui->select('mode', ['exact' => 'Точно', 'range' => 'Диапазон'], [
        'label' => 'Как проверять',
        'value' => 'range',
    ]),
    'список отмечает выбранный пункт по значению'
);

/*
 * Кнопка без размера — обычное дело: `size` необязателен. Раньше обращение к
 * нему шло напрямую, и любая кнопка без размера роняла страницу на
 * «Undefined array key «size»». Плагин об этом не знает: он передал кнопку
 * как есть.
 */
$t->equals(
    '<button type="submit" class="btn btn-primary">Сохранить</button>',
    $ui->button('Сохранить', ['type' => 'submit']),
    'кнопка без размера рисуется, а не роняет страницу на «Undefined array key»'
);

$t->contains(
    $ui->button('Сохранить', ['type' => 'submit']),
    'class="btn btn-primary"',
    'у кнопки без размера нет лишнего пробела в классе'
);

$t->contains(
    $ui->button('Сохранить', ['type' => 'submit', 'size' => 'btn-sm']),
    'btn btn-primary btn-sm',
    'размер кнопки по-прежнему попадает в класс'
);

/*
 * Подпись поля экранируется всегда.
 *
 * Раньше подпись, начинающаяся с «<», вставлялась в страницу как есть: на
 * случай, если кто-то подпишет поле готовой разметкой. Такого поля в проекте
 * нет, а вот имя из базы, начинающееся с «<», уехало бы в страницу как
 * команда. Подпись с разметкой, если она понадобится, пойдёт отдельным ключом.
 */
$t->notContains(
    $ui->field('title', ['label' => '<script>alert(1)</script>']),
    '<script>',
    'подпись поля, начинающаяся с «<», экранируется'
);

$t->contains(
    $ui->field('title', ['label' => '<b>жирно</b>']),
    '&lt;b&gt;жирно&lt;/b&gt;',
    'разметка в подписи остаётся текстом'
);

$t->equals(
    '<label class="form-check" for="partial"><input type="checkbox" class="form-check-input" '
    . 'id="partial" name="partial" value="1" checked><span>Частичный</span></label>',
    $ui->checkbox('partial', ['label' => 'Частичный', 'checked' => true]),
    'флажок рисуется по образцу ядра'
);

$t->equals(
    '<label class="check" for="period-percent"><input type="radio" class="form-check-input" '
    . 'id="period-percent" name="period" value="percent" checked><span>По процентам</span></label>',
    $ui->radio('period', ['label' => 'По процентам', 'value' => 'percent', 'checked' => true]),
    'переключатель рисуется по образцу ядра'
);

$t->contains($ui->toggle('enabled', ['label' => 'Включено']), 'class="switch"',
    'переключатель вкл/выкл рисуется по образцу ядра');

$t->contains($ui->editor('prompt', ['label' => 'Условие']), 'data-upload-url="/panel/editor/image"',
    'визуальный редактор берёт адрес загрузки из ядра');

$t->contains($ui->editorScripts(), 'assets/vendor/quill/quill.js',
    'скрипты редактора подключает ядро');

$t->contains($ui->message('Сохранено', 'ok'), 'class="banner ', 'сообщение об успехе');
$t->contains($ui->message('Отказ', 'error'), 'banner-danger', 'сообщение об отказе');
$t->contains($ui->message('Осторожно', 'warning'), 'banner-warning', 'предупреждение');

/*
 * Пустое поле в несколько строк закрывается.
 *
 * Тег с содержимым закрывается всегда, даже когда содержимого нет: незакрытый
 * `<textarea>` браузер считает текстом всё, что дальше, и следующее поле
 * получает вместо значения кусок разметки страницы. Именно так из редактора
 * задания пропал блок вариантов ответа — он оказался внутри «Пояснения после
 * проверки».
 */
$t->equals(
    '<div class="form-group"><label class="form-label" for="explanation">Пояснение</label>'
    . '<textarea class="form-control" id="explanation" name="explanation" rows="2"></textarea></div>',
    $ui->textarea('explanation', ['label' => 'Пояснение', 'value' => '', 'rows' => 2]),
    'пустое поле в несколько строк закрывается'
);

$t->equals(
    '<div class="form-group"><input class="form-control" type="text" id="title" name="title" value="Вопрос"></div>',
    $ui->field('title', ['label' => '', 'value' => 'Вопрос']),
    'поле без подписи рисуется без самой подписи, но в своей группе'
);

$t->contains($ui->file('package', ['label' => 'Файл', 'accept' => 'image/*']),
    'accept="image/*"', 'выбор файла принимает фильтр типов');

/*
 * Разметка каждого элемента должна быть закрытой.
 *
 * Незакрытый `<textarea>` браузер читает как открытый до конца страницы: всё,
 * что дальше, становится его содержимым. В редакторе задания из-за этого блок
 * вариантов ответа оказался внутри поля «Пояснение после проверки» — форма
 * выглядела исправной, а нужного поля на ней не было. Проверяется на всех
 * хуках сразу, потому что ошибка не в одном элементе, а в сборке тега.
 */
$voidElements = ['input', 'br', 'img', 'hr'];

$samples = [
    'field'        => $ui->field('a', ['label' => 'A', 'value' => '']),
    'field без значения' => $ui->field('b', ['label' => 'B']),
    'field с ошибкой' => $ui->field('c', ['label' => 'C', 'error' => 'Ошибка']),
    'textarea'     => $ui->textarea('d', ['label' => 'D', 'value' => '']),
    'textarea со значением' => $ui->textarea('e', ['label' => 'E', 'value' => 'текст']),
    'select'       => $ui->select('f', ['1' => 'один'], ['label' => 'F']),
    'checkbox'     => $ui->checkbox('g', ['label' => 'G']),
    'radio'        => $ui->radio('h', ['label' => 'H', 'value' => 'v']),
    'toggle'       => $ui->toggle('i', ['label' => 'I']),
    'file'         => $ui->file('j', ['label' => 'J']),
    'hidden'       => $ui->hidden('k', 'x'),
    'optionRow'    => $ui->optionRow(),
    'group'        => $ui->group(['label' => 'K'], 'внутри'),
    'optionRows'   => $ui->optionRows(['раз'], ['name' => 'option_text[]']),
    'editor'       => $ui->editor('prompt', ['label' => 'Условие', 'value' => '<p>текст</p>']),
];

foreach ($samples as $name => $html) {
    preg_match_all('#<([a-z]+)[^>]*>#i', $html, $opened);
    preg_match_all('#</([a-z]+)>#i', $html, $closed);

    $opened = array_map('strtolower', $opened[1]);
    $closed = array_map('strtolower', $closed[1]);
    $problems = [];

    foreach ($opened as $tag) {
        if (!in_array($tag, $voidElements, true) && !in_array($tag, $closed, true)) {
            $problems[] = 'не закрыт <' . $tag . '>';
        }
    }

    foreach ($closed as $tag) {
        if (in_array($tag, $voidElements, true)) {
            $problems[] = 'у тега без содержимого закрывающий: </' . $tag . '>';
        }
    }

    $t->equals([], $problems, 'разметка элемента «' . $name . '» собрана верно');
}
$t->equals(
    '<div class="form-error">Неверно</div>',
    $ui->fieldError('Неверно'),
    'подпись ошибки под полем'
);

/*
 * Экранирование — часть контракта, а не деталь реализации.
 *
 * Значение поля приходит из базы и из ответа ученика: без экранирования
 * подпись или содержимое ответа стало бы разметкой страницы.
 */
$t->notContains($ui->field('title', ['value' => '"><script>']), '<script>',
    'значение поля экранируется');
$t->notContains($ui->optionRow(['name' => '"><b>', 'value' => '"><b>']), '<b>',
    'подпись строки варианта экранируется');

$t->group('Свои наборы плагинов у пользователя');

/*
 * Набор плагинов личный.
 *
 * Раньше включение было одно на всех, и учитель не мог убрать из каталога
 * лишние типы заданий: переключатель принадлежал администратору, а он один
 * на весь сайт. Теперь состояние хранится у пользователя, и проверяется оно
 * здесь напрямую — через то же место, где живут настоящие настройки.
 *
 * Пользователей два намеренно: проверка «у одного выключено, у другого
 * включено» и есть смысл новой модели. На одном её не различить.
 */
$ownerId = (int) $db->insert('users', [
    'login'         => 'plugins-a@example.test',
    'email'         => 'plugins-a@example.test',
    'password_hash' => password_hash('probe-pass-1', PASSWORD_DEFAULT),
    'full_name'     => 'Первый набор',
    'status'        => 'active',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);
$otherId = (int) $db->insert('users', [
    'login'         => 'plugins-b@example.test',
    'email'         => 'plugins-b@example.test',
    'password_hash' => password_hash('probe-pass-1', PASSWORD_DEFAULT),
    'full_name'     => 'Второй набор',
    'status'        => 'active',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

$db->run('DELETE FROM user_plugins WHERE plugin_id = :p', ['p' => 'variable']);

// У нового пользователя нет ни одной строки — и плагин у него включён.
$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM user_plugins WHERE user_id = :u',
    ['u' => $ownerId]
), 'у нового пользователя настроек нет вовсе');
$t->is($plugins->isEnabledForUser('variable', $ownerId), 'без настроек плагин считается включённым');

// Первый выключает у себя.
$off = $plugins->setEnabledForUser('variable', $ownerId, false);

$t->is($off['ok'], 'выключение у себя удалось');
$t->is(!$plugins->isEnabledForUser('variable', $ownerId), 'у первого плагин выключен');
$t->is($plugins->isEnabledForUser('variable', $otherId), 'у второго он остался включён');

// Включение убирает строку: «включено» — это отсутствие записи.
$on = $plugins->setEnabledForUser('variable', $ownerId, true);

$t->is($on['ok'], 'повторное включение удалось');
$t->equals(0, (int) $db->value(
    'SELECT COUNT(*) FROM user_plugins WHERE user_id = :u AND plugin_id = :p',
    ['u' => $ownerId, 'p' => 'variable']
), 'включённый плагин не хранится записью — отсутствие строки и есть включение');

// Главный плагин личную настройку не принимает.
$baseOff = $plugins->setEnabledForUser(PluginManager::BASE_PLUGIN, $ownerId, false);

$t->is(!$baseOff['ok'], 'личной настройкой «Тест» не выключается');
$t->contains((string) $baseOff['message'], 'администратор', 'отказ объясняет, кто решает');
$t->is($plugins->isEnabled(PluginManager::BASE_PLUGIN), 'общий переключатель «Теста» не тронут');

// Переключатель «Теста» заблокирован, остальных — нет.
$t->is($plugins->switchForUser(PluginManager::BASE_PLUGIN, $ownerId)['locked'], 'переключатель «Теста» заблокирован');
$t->is(!$plugins->switchForUser('variable', $ownerId)['locked'], 'переключатель остальных плагинов доступен');

// Общий флаг «Теста» по-прежнему решает за всех.
$plugins->disable(PluginManager::BASE_PLUGIN);

$t->is(!$plugins->isEnabled(PluginManager::BASE_PLUGIN), '«Тест» выключен общим переключателем');
$t->is(!$plugins->isEnabledForUser(PluginManager::BASE_PLUGIN, $otherId), 'выключение «Теста» действует на всех');

$plugins->enable(PluginManager::BASE_PLUGIN);

$t->is($plugins->isEnabledForUser(PluginManager::BASE_PLUGIN, $otherId), 'после включения «Тест» вернулся всем');

$db->run('DELETE FROM user_plugins WHERE user_id IN (:a, :b)', ['a' => $ownerId, 'b' => $otherId]);
$db->run('DELETE FROM users WHERE id IN (:a, :b)', ['a' => $ownerId, 'b' => $otherId]);

/*
 * Резервная копия базы перед миграцией.
 *
 * Проверяется на настоящей копии настоящей базы, потому что смысл сервиса
 * именно в том, что файл получается пригодным: «утилита запустилась» —
 * не доказательство. Копия снимается во временный каталог, а не в `var/backup`:
 * иначе проверка удаляла бы боевые копии как устаревшие.
 */
$t->group('Резервная копия базы перед миграцией');

$backupDir = sys_get_temp_dir() . '/myedu_backup_' . bin2hex(random_bytes(4));
$config = $app->config();

$dump = new DatabaseDump($app, $config, $db, blocking: true, directory: $backupDir);
$backupPath = $dump->take('проверка набора');

if ($backupPath === null || !is_file($backupPath)) {
    $t->is(false, 'копия снята и лежит по указанному пути');
} else {
    $t->is(true, 'копия снята и лежит по указанному пути');

    $dumpText = (string) file_get_contents($backupPath);
    $tablesInDb = (int) $db->value(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = :type',
        ['type' => 'BASE TABLE']
    );

    $t->is(
        str_contains($dumpText, 'CREATE TABLE `users`'),
        'в копии есть таблица, без которой проект не работает'
    );

    $t->is(
        substr_count($dumpText, 'CREATE TABLE') >= $tablesInDb,
        sprintf('в копии все таблицы базы: %d из %d', substr_count($dumpText, 'CREATE TABLE'), $tablesInDb)
    );

    $t->is(
        str_contains($dumpText, 'Dump completed'),
        'файл дописан утилитой до конца, а не оборван'
    );

    /*
     * Имя копии разбирается на части, а не сравнивается целиком: там база,
     * там дата и время. Время — в имени файла, потому что по нему же
     * отбираются устаревшие копии.
     */
    $t->is(
        (bool) preg_match('/^' . preg_quote((string) $db->value('SELECT DATABASE()'), '/') . '_\d{8}_\d{6}(_\d+)?\.sql$/', basename($backupPath)),
        'имя копии разбирается без труда: база, дата, время'
    );
}

/*
 * Отказ — это отказ, а не «снимок, который наверное подойдёт».
 *
 * Отказ теперь возможен не «нет утилиты» (тогда есть запасной путь), а когда не
 * срабатывает ни один из двух: сервис создан без соединения с базой, то есть
 * снимать нечем в принципе. Молчаливая копия без проверки — худший исход: она
 * выглядит выполненной задачей, пока откатываться не придётся.
 */
/*
 * Отказ — это отказ, а не «снимок, который наверное подойдёт».
 *
 * Сценарий настоящий: утилита на месте и пишет файл, а сверка его отвергает —
 * сервис создан без соединения с базой, сверять нечем. Именно он опасен: файл
 * уже лежит на диске и называется копией, поэтому проверяется, что он исчезает.
 */
$filesBeforeFailure = count((array) glob($backupDir . '/*.sql'));

$brokenDump = new DatabaseDump($app, $config, null, blocking: true, directory: $backupDir);

$brokenRefused = false;
$brokenMessage = '';

try {
    $brokenDump->take('проверка отказа');
} catch (RuntimeException $e) {
    $brokenRefused = true;
    $brokenMessage = $e->getMessage();
}

$t->is($brokenRefused, 'миграция останавливается, если копия не снята и запрет задан');
$t->is(str_contains($brokenMessage, 'mariadb-dump'), 'отказ называет оба способа снятия');

/*
 * Число файлов сравнивается с тем, что было до попытки, а не «не больше
 * одного»: копий в каталоге может быть несколько, и важно, что неудачная
 * попытка не добавила ещё одну.
 */
$t->equals(
    $filesBeforeFailure,
    count((array) glob($backupDir . '/*.sql')),
    'неудачное снятие не оставляет файла, который выглядел бы копией'
);

/*
 * Нет утилиты — не значит «нет копии».
 *
 * Именно это и было главным риском у хозяина сервера: без `mariadb-dump`
 * копия перед миграцией была бы невозможна, и откат остался бы ручным.
 */
$noUtilityDump = new DatabaseDump($app, $config, $db, blocking: true, directory: $backupDir);

putenv('MYSQLDUMP=' . $backupDir . '/mariadb-dump.exe');

$withoutUtility = $noUtilityDump->take('проверка без утилиты');

putenv('MYSQLDUMP');

$t->is(
    $withoutUtility !== null && is_file($withoutUtility),
    'без mariadb-dump копия всё равно снимается средствами PHP'
);

/*
 * Без запрета сайт продолжает работать: копии нет, но и падать не от чего.
 * Так ведёт себя `SchemaGate` на первом заходе, и это осознанное решение —
 * см. `DatabaseDump`.
 */
$lenientDump = new DatabaseDump($app, $config, null, blocking: false, directory: $backupDir);
$lenientResult = $lenientDump->take('проверка без запрета');

$t->is($lenientResult === null, 'без запрета снятие возвращает «копии нет», а не падает');
$t->is($lenientDump->lastError() !== null, 'причина отказа остаётся доступной для журнала');

/*
 * Пропуск по решению человека — тоже без копии, но без отказа: сознательный
 * пропуск не должен выглядеть поломкой.
 */
$disabledDump = new DatabaseDump($app, $config, $db, blocking: true, directory: $backupDir);
$disabledDump->disable('проверка набора');

$t->is($disabledDump->take('проверка пропуска') === null, 'осознанный пропуск не считается отказом');
$t->is($disabledDump->lastError() === null, 'у пропуска нет причины отказа');

/*
 * Поиск утилиты должен работать не только на этой машине.
 *
 * Здесь проверяются три случая, каждый из которых ломал бы копирование на
 * чужом сервере молча: утилита лежит в каталоге из `PATH`, называется
 * `mysqldump` вместо `mariadb-dump` и лежит без расширения — как на Linux.
 */
$probeDir = sys_get_temp_dir() . '/myedu_path_probe_' . bin2hex(random_bytes(4));

@mkdir($probeDir, 0700, true);

file_put_contents($probeDir . '/mysqldump', '');
file_put_contents($probeDir . '/mariadb', '');

$pathBefore = (string) getenv('PATH');

putenv('PATH=' . $probeDir);

$pathDump = new DatabaseDump($app, $config, null, blocking: false, directory: $backupDir);
$onPath = $pathDump->dumpBinary();

$t->is(
    $onPath !== null && realpath((string) $onPath) === realpath($probeDir . '/mysqldump'),
    'утилита без расширения в каталоге PATH находится — так выглядит Linux-сервер'
);

$t->is(
    $pathDump->clientBinary() !== null,
    'клиент из того же каталога тоже находится'
);

putenv('MYSQLDUMP=' . $probeDir . '/mariadb-dump');

$t->is(
    (new DatabaseDump($app, $config, null, blocking: false, directory: $backupDir))->dumpBinary() === null,
    'заданный вручную несуществующий путь — это отсутствие утилиты, а не тихий поиск где-то ещё'
);

putenv('MYSQLDUMP');
putenv('PATH=' . $pathBefore);

@unlink($probeDir . '/mysqldump');
@unlink($probeDir . '/mariadb');
@rmdir($probeDir);

/*
 * Хранится только последнее нужное число копий: папка, которую никто не чистит,
 * через год занимает место и никому не нужна.
 */
$keepDir = sys_get_temp_dir() . '/myedu_backup_keep_' . bin2hex(random_bytes(4));
@mkdir($keepDir, 0775, true);

for ($i = 1; $i <= 12; $i++) {
    file_put_contents(sprintf('%s/probe_%02d.sql', $keepDir, $i), str_repeat('x', 2048));
    touch(sprintf('%s/probe_%02d.sql', $keepDir, $i), time() - (13 - $i) * 60);
}

/*
 * Каталог для проверки указывается на существующий каталог с файлами, а снятие
 * копии не запускается: достаточно `prune()`, а он не должен трогать свежую
 * копию, даже если она попала в тот же каталог.
 */
$keepDump = new DatabaseDump($app, $config, $db, blocking: true, directory: $keepDir);
$freshPath = sprintf('%s/probe_12.sql', $keepDir);
$keepDump->prune($freshPath);

$t->equals(10, count((array) glob($keepDir . '/*.sql')), 'остаются последние десять копий');
$t->is(is_file($freshPath), 'только что снятая копия не удаляется вместе с устаревшими');

foreach ((array) glob($keepDir . '/*.sql') as $file) {
    @unlink((string) $file);
}

@rmdir($keepDir);

/*
 * Копия берётся до изменения схемы, а не после.
 *
 * Проверяется настоящим применением миграции, создающей таблицу: если копия
 * снималась бы после выполнения операторов, отказ не спас бы уже изменённую
 * схему, и вся затея была бы в том, что файл появится рядом с поломкой.
 */
$hookProbe = bin2hex(random_bytes(4));
$hookDir = sys_get_temp_dir() . '/myedu_backup_hook_' . $hookProbe;
$hookTable = 'backup_hook_' . $hookProbe;
$hookLedger = 'backup_hook_ledger_' . $hookProbe;
$hookLock = 'myedu_backup_hook_lock_' . $hookProbe;

@mkdir($hookDir, 0700, true);

file_put_contents(
    $hookDir . '/001_probe.sql',
    'CREATE TABLE `' . $hookTable . '` (value VARCHAR(16) NOT NULL) ENGINE=InnoDB;'
);

$tableExists = static function (string $table) use ($db): bool {
    return (int) $db->value(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = :name',
        ['name' => $table]
    ) === 1;
};

/*
 * Отказ здесь даётся не отсутствием утилиты (тогда есть запасной путь), а
 * сервисом без соединения: снимать копию нечем вовсе. Иначе проверка на
 * «DDL не выполнился» проверяла бы уже не отказ, а работу запасного пути.
 */
$hookRefused = false;

try {
    (new SchemaMigrator(
        $db,
        $hookDir,
        $hookLedger,
        $hookLock,
        backup: new DatabaseDump($app, $config, null, blocking: true, directory: $backupDir)
    ))->apply();
} catch (RuntimeException $e) {
    $hookRefused = true;
}

$t->is($hookRefused, 'ядро не применяет миграцию, если копия не снята');
$t->is(!$tableExists($hookTable), 'DDL не выполнился: отказ случился до изменения схемы');

$hookApplied = (new SchemaMigrator(
    $db,
    $hookDir,
    $hookLedger,
    $hookLock,
    backup: new DatabaseDump($app, $config, $db, blocking: true, directory: $backupDir)
))->apply();

$t->equals('applied', $hookApplied['status'], 'с копией ядро применяет ту же миграцию');
$t->is($tableExists($hookTable), 'изменение дошло до базы');

/*
 * У плагина та же гарантия и ещё одно условие: копия снимается только когда
 * есть что применять. Миграции плагина проверяются при каждом обращении к
 * панели, и копия на каждый такой заход означала бы их тысячи за день.
 */
$pluginHookTable = 'plugin_backup_hook_' . $hookProbe;
$pluginHookId = 'probe-' . $hookProbe;
$pluginHookDir = sys_get_temp_dir() . '/myedu_plugin_hook_' . $hookProbe;

@mkdir($pluginHookDir, 0700, true);

file_put_contents(
    $pluginHookDir . '/001_probe.sql',
    'CREATE TABLE `' . $pluginHookTable . '` (value VARCHAR(16) NOT NULL) ENGINE=InnoDB;'
);

$pluginRunner = new PluginMigrationRunner($db, $pluginHookId, $pluginHookDir);

$t->is($pluginRunner->hasPending(), 'до применения миграция плагина считается неприменённой');

$pluginHookRefused = false;

try {
    (new PluginMigrationRunner(
        $db,
        $pluginHookId,
        $pluginHookDir,
        new DatabaseDump($app, $config, null, blocking: true, directory: $backupDir)
    ))->apply();
} catch (RuntimeException $e) {
    $pluginHookRefused = true;
}

$t->is($pluginHookRefused, 'плагин не применяет миграцию, если копия не снята');
$t->is(!$tableExists($pluginHookTable), 'DDL плагина не выполнился');

/*
 * И обратная сторона: с живой базой и без утилиты миграция применяется — потому
 * что копия снята запасным путём. Проверка «без копии ничего не применяется» не
 * должна была превратиться в «без mariadb-dump ничего не применяется».
 *
 * Тот же каталог миграций годится: предыдущая попытка ничего не выполнила, и
 * таблица, которую создаёт эта миграция, ещё не существует.
 */
putenv('MYSQLDUMP=' . $backupDir . '/mariadb-dump.exe');

(new PluginMigrationRunner(
    $db,
    $pluginHookId,
    $pluginHookDir,
    new DatabaseDump($app, $config, $db, blocking: true, directory: $backupDir)
))->apply();

putenv('MYSQLDUMP');

$t->is($tableExists($pluginHookTable), 'миграция плагина применяется и без утилиты: копия снята средствами PHP');
$t->is(
    !(new PluginMigrationRunner($db, $pluginHookId, $pluginHookDir))->hasPending(),
    'после применения снимать копию незачем'
);

$db->run('DELETE FROM plugin_migrations WHERE plugin_id = :id', ['id' => $pluginHookId]);
$db->run('DROP TABLE IF EXISTS `' . $pluginHookTable . '`');
$db->run('DROP TABLE IF EXISTS `' . $hookTable . '`');
$db->run('DROP TABLE IF EXISTS `' . $hookLedger . '`');

@unlink($hookDir . '/001_probe.sql');
@unlink($pluginHookDir . '/001_probe.sql');
@rmdir($hookDir);
@rmdir($pluginHookDir);

/*
 * Резервная копия средствами PHP — для сервера без mariadb-dump.
 *
 * На шаред-хостинге утилиты нет почти всегда, и «нет утилиты, значит нет копии»
 * означало бы, что точка отката появляется только на машине разработки. Поэтому
 * проверяется не только файл, но и главное свойство копии: она загружается обратно
 * и даёт ту же базу. Число строк в файле этому не доказывает.
 */
$t->group('Копия базы средствами PHP восстанавливается');

$phpDumpDir = sys_get_temp_dir() . '/myedu_phpdump_' . bin2hex(random_bytes(4));

@mkdir($phpDumpDir, 0700, true);

$phpDump = new DatabaseDump($app, $config, $db, blocking: true, directory: $phpDumpDir);

putenv('MYSQLDUMP=' . $phpDumpDir . '/mariadb-dump.exe');

$phpDumpPath = $phpDump->take('проверка средствами PHP');

putenv('MYSQLDUMP');

$t->is($phpDumpPath !== null && is_file((string) $phpDumpPath), 'копия снята без утилиты — средствами PHP');

/*
 * Значения, которые портят наивную выгрузку: кавычка, обратный слэш, перевод
 * строки и двоичные байты. Текст и двоичное идут разными путями, и если один из
 * них испорчен, это видно только на восстановленной копии.
 */
$valueTable = 'php_dump_values_' . bin2hex(random_bytes(3));

$db->run('DROP TABLE IF EXISTS `' . $valueTable . '`');
$db->run('CREATE TABLE `' . $valueTable . '` (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    plain VARCHAR(255) NOT NULL,
    note TEXT NOT NULL,
    payload VARBINARY(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$awkward = [
    'id' => 1,
    'plain' => "кавычка ' и \" вместе",
    'note' => "строка\nвторая\tс табом\\ и обратным слэшем",
    'payload' => "\x00\x01\xFF\xFEбинарные\x00байты",
];

$db->run(
    'INSERT INTO `' . $valueTable . '` (id, plain, note, payload) VALUES (:id, :plain, :note, :payload)',
    $awkward
);

$awkwardDump = new DatabaseDump($app, $config, $db, blocking: true, directory: $phpDumpDir);

putenv('MYSQLDUMP=' . $phpDumpDir . '/mariadb-dump.exe');
$awkwardPath = $awkwardDump->take('проверка значений');
putenv('MYSQLDUMP');

$restoreName = 'my_edu_restore_' . bin2hex(random_bytes(3));
$pdo = $db->pdo();
$sourceDatabase = (string) $db->value('SELECT DATABASE()');

/*
 * Счётчики исходной базы читаются ДО переключения соединения на базу-черновик.
 * После `USE` то же соединение читает уже восстановленную базу, и сравнение
 * выходило бы само с собой: расхождений не нашлось бы, даже если бы все данные
 * потерялись.
 */
$sourceCounts = [];

foreach ($db->all(
    'SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = :type ORDER BY table_name',
    ['type' => 'BASE TABLE']
) as $row) {
    $table = (string) $row['table_name'];
    $sourceCounts[$table] = (int) $db->value('SELECT COUNT(*) FROM `' . $table . '`');
}

$restoreResult = 'не выполнялась';
$diff = [];

try {
    $pdo->exec('CREATE DATABASE `' . $restoreName . '` DEFAULT CHARACTER SET utf8mb4');
    $pdo->exec('USE `' . $restoreName . '`');

    $statements = SchemaMigrator::splitStatements((string) file_get_contents((string) $awkwardPath));

    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            $restoreResult = 'оборвалась на операторе ' . $index . ': '
                . substr(preg_replace('/\s+/', ' ', $statement), 0, 60) . ' — ' . $e->getMessage();
            throw $e;
        }
    }

    $restoreResult = 'загружена целиком, операторов: ' . count($statements);

    foreach ($sourceCounts as $table => $inSource) {
        try {
            $inCopy = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (Throwable $e) {
            $diff[] = $table . ': таблицы нет в копии';
            continue;
        }

        if ($inSource !== $inCopy) {
            $diff[] = $table . ': строк ' . $inSource . ' против ' . $inCopy;
        }
    }

    $restored = $pdo->query('SELECT plain, note, payload FROM `' . $valueTable . '` WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    if ($restored === false) {
        $diff[] = 'строка с особыми значениями не восстановилась';
    } else {
        foreach (['plain', 'note', 'payload'] as $column) {
            if ((string) $restored[$column] !== (string) $awkward[$column]) {
                $diff[] = 'колонка ' . $column . ' восстановилась иначе';
            }
        }
    }
} catch (Throwable $e) {
    if (str_starts_with($restoreResult, 'загружена целиком')) {
        $restoreResult = 'оборвалась при проверке: ' . $e->getMessage();
    }
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $restoreName . '`');
    $pdo->exec('USE `' . $sourceDatabase . '`');
}

$t->is(str_starts_with($restoreResult, 'загружена целиком'), 'копия загружается обратно без ошибок — ' . $restoreResult);
$t->equals([], $diff, 'восстановленная база совпадает с исходной по всем таблицам и по особым значениям');

/*
 * Копия снимается на одном моменте времени.
 *
 * Читать таблицы по очереди — значит видеть базу в разные моменты: между
 * чтением одной таблицы и следующей ученик может сдать работу, и в копии
 * окажется половина его попытки. Точка отката, из которой нельзя
 * восстановиться, хуже, чем её отсутствие: человек узнаёт о проблеме, когда
 * данные уже потеряны.
 *
 * Проверяется само свойство, а не строчка в коде: внутри одной транзакции
 * первое чтение открывает снимок, изменения извне в него не попадают, и все
 * последующие чтения видят то же состояние. Именно на этом держится копия,
 * снятая PhpDatabaseDump.
 *
 * Изменение вносится отдельным подключением: по тому же самому его бы просто
 * не увидели, и проверка прошла бы на любом коде.
 */
$witness = 'snimok_' . bin2hex(random_bytes(6));

$db->run('INSERT INTO users (login, email, password_hash, full_name, status, created_at, updated_at)
    VALUES (:l, NULL, :h, :n, :s, NOW(), NOW())',
    [
        'l' => $witness,
        'h' => password_hash('parol12345', PASSWORD_DEFAULT),
        'n' => 'Свидетель снимка',
        's' => 'active',
    ]
);

$witnessId = (int) $db->value('SELECT id FROM users WHERE login = :l', ['l' => $witness]);

$connection = $config->array('database');
$outsider = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        (string) ($connection['host'] ?? '127.0.0.1'),
        $sourceDatabase
    ),
    (string) ($connection['user'] ?? 'root'),
    (string) ($connection['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$snapshot = $db->transaction(function (Database $inner) use ($witnessId, $outsider): array {
    $first = (int) $inner->value('SELECT COUNT(*) FROM users');

    $outsider->prepare(
    'UPDATE users SET full_name = :n WHERE id = :id'
)->execute([':n' => 'Свидетель изменён', ':id' => $witnessId]);

    return [
        $first,
        (int) $inner->value('SELECT COUNT(*) FROM users'),
        (string) $inner->value('SELECT full_name FROM users WHERE id = :id', ['id' => $witnessId]),
    ];
});

$t->equals($snapshot[0], $snapshot[1], 'внутри одной транзакции база видна на одном моменте');
$t->is(
    $snapshot[2] === 'Свидетель снимка',
    'изменение извне не попало в снимок, взятый при первом чтении'
);

// После выхода из транзакции изменение видно — значит, проверялось свойство
// снимка, а не просто то, что база не сломалась.
$t->is(
    (string) $db->value('SELECT full_name FROM users WHERE id = :id', ['id' => $witnessId])
        === 'Свидетель изменён',
    'после транзакции изменение извне видно'
);

$db->run('DELETE FROM user_roles WHERE user_id = :id', ['id' => $witnessId]);
$db->run('DELETE FROM users WHERE id = :id', ['id' => $witnessId]);

$db->run('DROP TABLE IF EXISTS `' . $valueTable . '`');

foreach ((array) glob($phpDumpDir . '/*.sql') as $file) {
    @unlink((string) $file);
}

@rmdir($phpDumpDir);

foreach ((array) glob($backupDir . '/*.sql') as $file) {
    @unlink((string) $file);
}

@rmdir($backupDir);

exit($t->report());
