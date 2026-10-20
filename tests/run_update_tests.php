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

$t->equals('0.3 beta', Version::display('0.3-beta'), 'версия показывается так же, как записана в настройках');
$t->equals('0.3', Version::display('0.3'), 'версия без слова о готовности остаётся как есть');

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

// Запрещённые пути в архиве останавливают распаковку целиком.
$badArchive = Zip::write([
    'src/Core/Version.php' => '<?php // версия',
    'config/config.local.php' => '<?php // чужие доступы',
]);

$badResult = MyEdu\Core\SystemUpdate::install($badArchive, $target . '-bad');

$t->is(($badResult['ok'] ?? false) === false, 'архив с настройками базы не распакован');
$t->contains((string) ($badResult['message'] ?? ''), 'config.local.php', 'в отказе назван запрещённый файл');
$t->is(!is_file($target . '-bad/config/config.local.php'), 'настройки с доступами к базе не записаны');

/**
 * Очистка.
 */
foreach ([$target, $target . '-bad'] as $directory) {
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

exit($t->report());
