<?php

declare(strict_types=1);

/**
 * Модульные проверки обновления системы.
 *
 * Обновление меняет файлы, из которых работает сайт, и сравнивает версии по
 * номерам, а не по строкам. Обе вещи проверяются без базы и без HTTP:
 * сравнение версий — потому что «0.10 beta» по алфавиту меньше «0.9 beta», а
 * распаковка — потому что архив из интернета раскладывается поверх живого
 * проекта, и ошибка в пути стоила бы дороже всего остального вместе
 * взятого.
 *
 * Запуск: php tests/run_update_tests.php
 */

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', $rootDir . '/src');
$autoloader->register();

use MyEdu\Core\Version;
use MyEdu\Transfer\Zip;

final class Runner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public function is(bool $ok, string $description): void
    {
        if ($ok) {
            $this->passed++;
            echo "  \033[32m+\033[0m {$description}\n";
            return;
        }

        $this->failures[] = $this->group . ' → ' . $description;
        echo "  \033[31m-\033[0m {$description}\033[0m\n";
    }

    public function equals(mixed $expected, mixed $actual, string $description): void
    {
        $this->is(
            $expected === $actual,
            $description . ($expected === $actual ? '' : ' (ожидалось ' . var_export($expected, true)
                . ', получено ' . var_export($actual, true) . ')')
        );
    }

    public function contains(string $haystack, string $needle, string $description): void
    {
        $this->is(
            str_contains($haystack, $needle),
            $description . (str_contains($haystack, $needle) ? '' : ' (в тексте нет «' . $needle . '»)')
        );
    }

    public function report(): int
    {
        $total = $this->passed + count($this->failures);

        echo "\n" . str_repeat('-', 62) . "\n";

        if ($this->failures === []) {
            echo "\033[32mВсе проверки пройдены: {$this->passed}/{$total}\033[0m\n";
            return 0;
        }

        echo "\033[31mПровалено: " . count($this->failures) . "/{$total}\033[0m\n";
        foreach ($this->failures as $failure) {
            echo "  - {$failure}\n";
        }

        return 1;
    }
}

$t = new Runner();

/**
 * Разбор номера версии.
 */
$t->group('Номер версии');

$t->equals([0, 3], Version::parse('0.3')['numbers'] ?? null, 'номер разбирается по цифрам');
$t->equals('beta', Version::parse('0.3 beta')['stage'] ?? null, 'слово о готовности читается');
$t->equals('beta', Version::parse('0.3-beta')['stage'] ?? null, 'дефис вместо пробела не мешает');
$t->equals('beta', Version::parse('v0.3beta')['stage'] ?? null, 'слитная запись тоже читается');
$t->equals(null, Version::parse('не версия'), 'не-версия не разбирается');

/**
 * Сравнение номеров.
 *
 * Здесь важнее всего одно: «0.10» новее «0.9». По строкам было бы наоборот, и
 * обновление никогда бы не предложилось.
 */
$t->group('Сравнение версий');

$t->is(Version::compare('0.3 beta', '0.3 beta') === 0, 'одинаковые версии равны');
$t->is(Version::compare('0.4 beta', '0.3 beta') > 0, 'большая вторая цифра новее');
$t->is(Version::compare('0.3 beta', '0.4 beta') < 0, 'меньшая вторая цифра старее');
$t->is(Version::compare('0.10 beta', '0.9 beta') > 0, '0.10 новее 0.9 — сравниваются числа, а не строки');
$t->is(Version::compare('0.3.1', '0.3 beta') > 0, 'третья цифра новее той же второй без третьей');
$t->is(Version::compare('0.3 beta', '0.3 alfa') > 0, 'beta новее alfa');
$t->is(Version::compare('0.3 rc', '0.3 beta') > 0, 'rc новее beta');
$t->is(Version::compare('0.3', '0.3 beta') > 0, 'версия без слова о готовности считается выпущенной');
$t->is(Version::isNewer('0.4 beta', '0.3 beta'), 'более свежая версия определяется');
$t->is(!Version::isNewer('0.3 beta', '0.3 beta'), 'та же версия не считается более свежей');
$t->is(!Version::isNewer('0.3 beta', '0.4 beta'), 'старая версия не считается более свежей');

$t->is(Version::isOlder('0.3.6-beta', '0.3.7 beta'), 'старая версия определяется как старая');
$t->is(!Version::isOlder('0.3.7-beta', '0.3.7 beta'), 'та же версия не считается старой');
$t->is(!Version::isOlder('0.3.8-beta', '0.3.7 beta'), 'новая версия не считается старой');
$t->is(!Version::isOlder('не версия', '0.3.7 beta'), 'неразобранный номер не считается старым');
$t->is(!Version::isOlder('0.3.6-beta', ''), 'без номера в настройках старой не считается ничего');

$t->equals('0.3 beta', Version::display('0.3-beta'), 'версия показывается так же, как записана в настройках');
$t->equals('0.3', Version::display('0.3'), 'версия без слова о готовности остаётся как есть');

/**
 * Выбор релиза из списка GitHub.
 *
 * Список, а не «последний релиз»: адрес .../releases/latest отдаёт релиз без
 * отметки «предрелиз», а беты проекта — они и есть предрелизы. С ними сайт
 * показывал «установлена последняя версия», хотя на GitHub лежала версия
 * новее, и обновление не предлагалось вовсе.
 *
 * Из списка берётся наибольший номер версии, а не первый: вышедший позже
 * старый номер не должен вытеснять новый.
 */
$t->group('Выбор релиза из списка');

$releases = [
    ['tag_name' => '0.3.4-beta', 'prerelease' => false, 'draft' => false, 'published_at' => '2026-10-21T13:18:43Z'],
    ['tag_name' => '0.3.6-beta', 'prerelease' => false, 'draft' => false, 'published_at' => '2026-10-22T10:40:00Z'],
    ['tag_name' => '0.3.5-beta', 'prerelease' => false, 'draft' => false, 'published_at' => '2026-10-22T08:00:00Z'],
];

$picked = MyEdu\Core\SystemUpdate::pickLatest($releases);

$t->equals('0.3.6-beta', (string) ($picked['tag_name'] ?? ''), 'берётся релиз с наибольшим номером');

$withPrerelease = $releases;
$withPrerelease[] = ['tag_name' => '0.4-beta', 'prerelease' => true, 'draft' => false, 'published_at' => '2026-10-23T10:00:00Z'];

$t->equals(
    '0.3.6-beta',
    (string) (MyEdu\Core\SystemUpdate::pickLatest($withPrerelease)['tag_name'] ?? ''),
    'предрелиз не выдаётся за обновление'
);

$t->equals(
    '0.4-beta',
    (string) (MyEdu\Core\SystemUpdate::newestPrerelease($withPrerelease) ?? ''),
    'предрелиз виден отдельно'
);

$withDraft = $releases;
$withDraft[] = ['tag_name' => '0.9-beta', 'prerelease' => false, 'draft' => true, 'published_at' => '2026-10-24T10:00:00Z'];

$t->equals(
    '0.3.6-beta',
    (string) (MyEdu\Core\SystemUpdate::pickLatest($withDraft)['tag_name'] ?? ''),
    'черновик не выдаётся за обновление'
);

$sameNumber = $releases;
$sameNumber[] = ['tag_name' => '0.3.6-beta', 'prerelease' => false, 'draft' => false, 'published_at' => '2026-10-25T10:00:00Z'];

$t->equals(
    '2026-10-25T10:00:00Z',
    (string) (MyEdu\Core\SystemUpdate::pickLatest($sameNumber)['published_at'] ?? ''),
    'из двух релизов одного номера берётся вышедший позже'
);

$t->equals(null, MyEdu\Core\SystemUpdate::pickLatest([]), 'пустой список не даёт релиза');
$t->equals(null, MyEdu\Core\SystemUpdate::newestPrerelease([]), 'в пустом списке нет предрелиза');

/**
 * Куда нельзя класть файлы при обновлении.
 *
 * Здесь лежат данные школы: копия настроек с доступами к базе и загруженные
 * картинки. Заменить их файлами из архива — значит отдать чужой сайт чужим
 * данным, и никакая копия базы это не вернёт.
 */
$t->group('Безопасность распаковки');

$t->equals('src/Core/View.php', MyEdu\Core\SystemUpdate::safePath('src/Core/View.php'), 'обычный файл проекта распаковывается');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('../evil.php'), 'путь наружу не распаковывается');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('/etc/passwd'), 'абсолютный путь не распаковывается');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('var/log/error.log'), 'журналы не трогаются');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('var/backup/dump.sql'), 'копии базы не трогаются');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('config/config.local.php'), 'настройки с доступами к базе не трогаются');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('public/uploads/picture.png'), 'загруженные картинки не трогаются');
$t->equals(null, MyEdu\Core\SystemUpdate::safePath('.git/config'), 'каталог git не трогается');

/**
 * Распаковка архива в отдельный каталог.
 *
 * Настоящий проект при этом не страдает: файлы кладутся в отдельный каталог,
 * и проверяется, что архив разложился целиком и что запрещённые пути в нём
 * остановлены.
 */
$t->group('Распаковка архива');

$target = sys_get_temp_dir() . '/myedu-update-test-' . substr(md5((string) getmypid()), 0, 8);

if (!is_dir($target)) {
    @mkdir($target, 0775, true);
}

$archive = Zip::write([
    'src/Core/Version.php' => '<?php // версия',
    'templates/panel/settings.php' => '<?php // настройки',
    'changelog.md' => '# 0.3 beta',
]);

$installer = null;
$result = MyEdu\Core\SystemUpdate::install($archive, $target);

$t->is(($result['ok'] ?? false) === true, 'архив распакован');
$t->equals(3, (int) ($result['files'] ?? 0), 'распаковано столько файлов, сколько было в архиве');
$t->is(is_file($target . '/src/Core/Version.php'), 'файл разложен по своей дороге');
$t->is(is_file($target . '/templates/panel/settings.php'), 'вложенный каталог создан');
$t->equals('<?php // версия', (string) @file_get_contents($target . '/src/Core/Version.php'), 'содержимое файла на месте');

// Запрещённые пути в архиве пропускаются, а не останавливают обновление:
// прежние файлы остаются на месте, а остальные раскладываются.
$badArchive = Zip::write([
    'src/Core/Version.php' => '<?php // версия',
    'config/config.local.php' => '<?php // чужие доступы',
    'var/log/error.log' => 'журнал',
]);

$badResult = MyEdu\Core\SystemUpdate::install($badArchive, $target . '-bad');

$t->is(($badResult['ok'] ?? false) === true, 'архив с запрещёнными путями разложен частично');
$t->equals(1, (int) ($badResult['files'] ?? 0), 'разложены только те файлы, которые можно');
$t->equals(2, count($badResult['skipped'] ?? []), 'запрещённые пути перечислены как пропущенные');
$t->is(!is_file($target . '-bad/config/config.local.php'), 'настройки с доступами к базе не записаны');
$t->is(!is_file($target . '-bad/var/log/error.log'), 'журналы не записаны');
$t->is(is_file($target . '-bad/src/Core/Version.php'), 'остальные файлы разложены');

/**
 * Исходный архив тега лежит в каталоге «имя-репозитория-тег».
 *
 * Без снятия этого каталога файлы разложились бы в подкаталог, и обновление
 * ничего бы не заменило. Это проверяется на настоящем таком архиве: собирается
 * он сам, структура — как у исходников GitHub.
 */
$t->group('Исходный архив тега');

$tagArchive = Zip::write([
    'my.edu-0.3-beta/src/Core/Version.php' => '<?php // версия',
    'my.edu-0.3-beta/templates/panel/settings.php' => '<?php // настройки',
    'my.edu-0.3-beta/config/config.local.php' => '<?php // чужие доступы',
]);

$tagResult = MyEdu\Core\SystemUpdate::install($tagArchive, $target . '-tag');

$t->is(($tagResult['ok'] ?? false) === true, 'архив тега разложен');
$t->equals(2, (int) ($tagResult['files'] ?? 0), 'каталог тега не попал в разложенные файлы');
$t->is(is_file($target . '-tag/src/Core/Version.php'), 'файл лёг по своей дороге, без каталога тега');
$t->is(is_file($target . '-tag/templates/panel/settings.php'), 'вложенный каталог тоже без общей приставки');
$t->is(!is_file($target . '-tag/config/config.local.php'), 'настройки с доступами к базе не записаны');

/*
 * Кнопка обновления и её подтверждение.
 *
 * Окно подтверждения без своей подписи показывает в кнопке слово «Удалить» —
 * так оно и было: человек, собирающийся обновить систему, видел «Удалить» и
 * вправе был не нажимать.
 */
$t->group('Кнопка обновления');

$settingsTemplate = (string) @file_get_contents($rootDir . '/templates/panel/settings.php');

$t->is(
    str_contains($settingsTemplate, 'data-confirm-yes="Обновить систему"'),
    'у кнопки обновления своя подпись в окне подтверждения'
);

$t->is(
    str_contains($settingsTemplate, 'panel/settings/update'),
    'форма обновления отправляется на свой адрес'
);

/*
 * Страница обновления не называет последней версию, которой на сервере нет.
 *
 * Найденный на GitHub релиз бывает старше установленного: проверка
 * кэшируется на четверть часа, и сразу после выпуска свежей версии в кэше
 * ещё лежит предыдущая. Раньше страница в этом случае говорила «Установлена
 * последняя версия» и называла версию с GitHub — то есть утверждала, что на
 * сервере стоит то, чего там нет.
 */
$t->group('Страница обновления');

$t->is(
    str_contains($settingsTemplate, 'новее найденной на GitHub'),
    'для случая, когда найденный релиз старее установленного, своя формулировка'
);

$t->is(
    str_contains($settingsTemplate, '$releaseOlder'),
    'шаблон спрашивает у контроллера, старее ли найденный релиз'
);

$t->is(
    str_contains($settingsTemplate, 'Установлена последняя версия'),
    'обычная формулировка про последнюю версию осталась на месте'
);

/*
 * Ни одно подтверждение в шаблонах не остаётся без своей подписи.
 *
 * Подпись по умолчанию — «Удалить», и она годится только для удаления. Для
 * остальных действий она врёт: «Убрать из работы» с кнопкой «Удалить» и
 * «Обновить систему» с той же кнопкой — одно и то же слово на разных
 * действиях.
 */
$withoutLabel = [];

foreach ([$rootDir . '/templates', $rootDir . '/plugins'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($files as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $source = (string) @file_get_contents($file->getPathname());

        if (!str_contains($source, 'data-confirm=')) {
            continue;
        }

        $positions = [];
        $offset = 0;

        while (($at = strpos($source, 'data-confirm=', $offset)) !== false) {
            $positions[] = $at;
            $offset = $at + 13;
        }

        foreach ($positions as $index => $at) {
            $end = $positions[$index + 1] ?? strlen($source);
            $window = substr($source, $at, min(600, $end - $at));

            if (!str_contains($window, 'data-confirm-yes')) {
                $withoutLabel[] = str_replace($rootDir . '/', '', $file->getPathname());
            }
        }
    }
}

$t->equals([], $withoutLabel, 'у каждого подтверждения своя подпись: ' . implode(', ', $withoutLabel));

/*
 * Контроллер обновления умеет вернуть человека на страницу настроек.
 *
 * Помощники строят адрес возврата через вид, и без него страница после
 * обновления падала с «Call to a member function url() on null» — уже после
 * того, как файлы были заменены. Человек видел ошибку и думал, что обновление
 * не прошло.
 */
$updateController = new ReflectionClass(MyEdu\Http\Controller\Panel\UpdateController::class);
$updateParams = array_map(
    static fn (ReflectionParameter $parameter): string => $parameter->getName(),
    $updateController->getConstructor()->getParameters()
);

$t->is(
    in_array('view', $updateParams, true),
    'контроллер обновления получает вид для адреса возврата'
);

/**
 * Очистка.
 */foreach ([$target, $target . '-bad', $target . '-tag'] as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($directory);
}

/*
 * Устаревших вызовов в коде нет.
 *
 * curl_close() с PHP 8.0 ничего не делает, а с 8.5 объявлен устаревшим.
 * На сервере с 8.5 это сообщение печаталось прямо в ответ на обновление, и
 * ответ переставал быть разбираемым: обновление падало не из-за сети, а из-за
 * строчки в ответе.
 */
$t->group('Код без устаревших вызовов');

$deprecatedCalls = [];

foreach ([$rootDir . '/src', $rootDir . '/plugins'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($files as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $source = (string) @file_get_contents($file->getPathname());

        /*
         * Комментарии не в счёт: там вызов может быть назван словами, и
         * проверка ругалась бы на объяснение, а не на код.
         */
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\r\n]*#'], ' ', $source);

        foreach (['curl_close(', 'utf8_encode(', 'utf8_decode(', 'strftime(', 'create_function('] as $call) {
            if (str_contains($code, $call)) {
                $deprecatedCalls[] = str_replace($rootDir . '/', '', $file->getPathname()) . ': ' . $call;
            }
        }
    }
}

$t->equals([], $deprecatedCalls, 'устаревших вызовов в коде нет: ' . implode('; ', $deprecatedCalls));

/*
 * Служебные сообщения PHP не попадают в ответ.
 *
 * На боевом сервере display_errors включён, и без обработчика любое
 * предупреждение печатается прямо в страницу — туда, где человек ждёт
 * результата. Теперь такие сообщения уходят в журнал, а страница остаётся
 * целой.
 */
$t->group('Служебные сообщения не попадают в ответ');

$logFile = sys_get_temp_dir() . '/myedu-error-handler-' . bin2hex(random_bytes(4)) . '.log';

$handler = static function () use ($logFile): void {
    MyEdu\Core\ErrorHandler::register($logFile);

    // Предупреждение без фатальных последствий: страница должна остаться целой.
    trigger_error('проверка предупреждения', E_USER_WARNING);

    echo 'СТРАНИЦА ЦЕЛА';
};

$snippet = <<<'PHP'
<?php
require 'ROOT/src/Core/Autoloader.php';
$autoloader = new MyEdu\Core\Autoloader();
$autoloader->addNamespace('MyEdu\\', 'ROOT/src');
$autoloader->register();
MyEdu\Core\ErrorHandler::register('LOGFILE');
trigger_error('проверка предупреждения', E_USER_WARNING);
echo 'СТРАНИЦА ЦЕЛА';
PHP;

$snippetFile = sys_get_temp_dir() . '/myedu-warning-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents(
    $snippetFile,
    str_replace(['ROOT', 'LOGFILE'], [addslashes($rootDir), addslashes($logFile)], $snippet)
);

$output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($snippetFile) . ' 2>&1');

@unlink($snippetFile);

$t->is(
    str_contains($output, 'СТРАНИЦА ЦЕЛА'),
    'страница осталась целой после предупреждения'
);

$t->is(
    !str_contains($output, 'проверка предупреждения'),
    'текст предупреждения не попал в ответ'
);

$t->is(
    is_file($logFile) && str_contains((string) @file_get_contents($logFile), 'проверка предупреждения'),
    'предупреждение записано в журнал'
);
exit($t->report());
