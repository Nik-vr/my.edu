<?php

declare(strict_types=1);

/**
 * Проверка того, что документация говорит о проекте правду.
 *
 * Запуск: php tests/run_docs_tests.php
 *
 * Документация в этом проекте проверяется тем же способом, что и код, и по
 * той же причине: расхождение между текстом и поведением не ломает сайт, но
 * ломает того, кто поверил тексту. Ревью 2026-10-18 нашло одиннадцать
 * фактических ошибок в публичных файлах — среди них «перенос класса на другой
 * сервер», которого в коде нет вовсе, и описание плагина «Варианты», который на
 * самом деле проверяет числа самостоятельно.
 *
 * Что проверяется:
 *
 *   * пути вида `src/...`, упомянутые в документации, существуют: имя файла в
 *     тексте проверяется так же, как имя функции, — иначе инструкция ведёт в
 *     никуда;
 *   * удалённые файлы миграций нигде не упоминаются как существующие;
 *   * в документации репозитория нет личных путей разработчика;
 *   * версия, названная в README и в файле для разработчика, совпадает с
 *     настройкой `app.version` — единственным местом, где она написана;
 *   * минимальная версия PHP, названная в README, совпадает с той, которую
 *     проверяет `index.php` при старте;
 *   * каждый набор из `tools/run_tests.ps1` назван в README, а число названо то
 *     же, что их в прогоне;
 *   * утверждения, которые однажды оказались неправдой, не вернулись.
 *
 * Проверка знает про известные недочёты: у неё есть список известных мест, и
 * новое расхождение валит набор, а старое исчезает из списка.
 */

require __DIR__ . '/support/TestKit.php';
require __DIR__ . '/../src/Core/Config.php';
require __DIR__ . '/../src/Core/Request.php';

/*
 * Загрузчик нужен ради одного класса: список зарезервированных префиксов
 * объявлен константой в `PluginManager`, и сверять с ним надо само значение,
 * а не список, продублированный здесь.
 */
require dirname(__DIR__) . '/src/Core/Autoloader.php';
MyEdu\Core\Autoloader::create(dirname(__DIR__));

$root = str_replace('\\', '/', dirname(__DIR__));
$runner = new TestRunner();

$docs = [
    'README.md',
    'changelog.md',
    'ПРОДОЛЖИТЬ_РАБОТУ.md',
    'plugins/README.md',
    'docs/журнал-разработки-до-первого-релиза.md',
    'docs/ревью-2026-10-18.md',
    'docs/план-устранения-по-ревью-2026-10-18.md',
];

/*
 * Журнал работы до релиза — документ исторический.
 *
 * Он описывает проект таким, каким он был в процессе: там есть файлы, которых
 * уже нет (`src/Http/Presenter/GradeScale.php`, `public/public/index.php`), и
 * номера миграций, снятые ещё до снимка схемы. Это не ошибки, а запись о том,
 * что было.
 *
 * Поэтому сверка путей и миграций его не касается, а проверка личных данных —
 * касается: путь с именем пользователя Windows в истории недопустим нигде, даже
 * в прошедшем времени, и именно его в журнале и нашли.
 */
$historicalDocs = ['docs/журнал-разработки-до-первого-релиза.md'];

$currentDocs = static function (array $texts) use ($historicalDocs): array {
    return array_diff_key($texts, array_flip($historicalDocs));
};

/**
 * @return array<string, string> «путь к документу» → содержимое
 */
function readDocs(string $root, array $docs): array
{
    $out = [];

    foreach ($docs as $doc) {
        $path = $root . '/' . $doc;

        if (!is_file($path)) {
            continue;
        }

        $out[$doc] = (string) file_get_contents($path);
    }

    return $out;
}

$texts = readDocs($root, $docs);

// ---------------------------------------------------------------------------
// 1. Пути, названные в документации, должны существовать.
//
// Проверяются только пути с каталогом: имя файла без каталога в тексте — это
// обычно простыня («в Ui.php»), и требовать от неё полного пути бессмысленно.
$runner->group('Документация: пути');

$missingPaths = [];

foreach ($currentDocs($texts) as $doc => $text) {
    // `src/Core/App.php`, `tools\run_tests.ps1`, `database/schema.sql:164`
    preg_match_all(
        '/`((?:src|public|templates|database|tools|tests|config|plugins|docs|deploy)\/[\\\\\/]?[\w.\-\/\\\\]*?\.(?:php|ps1|sql|md|css|js|json|bat|vbs|example|conf|zip))(?:[:][\d,\- ]*)?`/u',
        $text,
        $found
    );

    foreach (array_unique($found[1]) as $path) {
        if (str_contains($path, '*') || str_contains($path, '…')) {
            continue;
        }

        $normalized = str_replace('\\', '/', $path);

        if (!is_file($root . '/' . $normalized) && !is_dir($root . '/' . $normalized)) {
            $missingPaths[] = $doc . ' → ' . $normalized;
        }
    }

    /*
     * Адреса markdown-ссылок — отдельно от путей в обратных кавычках.
     *
     * Ссылка `[текст](файл)` читается человеком как адрес: он нажимает и
     * получает 404. Проверка путей в кавычках такие ссылки не видит вовсе — она
     * ищет только то, что обёрнуто в кавычки. Так вышло, что ссылка на удалённый
     * документ осталась в README и ничем не помешала: набор был зелёный, а
     * ссылка вела в никуда.
     */
    preg_match_all('/\]\(([^)#\s]+)\)/u', $text, $links);

    foreach (array_unique($links[1]) as $link) {
        if ($link === '' || preg_match('#^(https?:|mailto:|//)#i', $link) === 1) {
            continue;
        }

        $target = explode('#', $link)[0];

        if ($target === '') {
            continue;
        }

        $full = $root . '/' . ltrim(str_replace('\\', '/', $target), '/');

        if (!is_file($full) && !is_dir($full)) {
            $missingPaths[] = $doc . ' → ' . $target . ' (ссылка)';
        }
    }
}

// Известные места: документы-архивы цитируют имена файлов, которых в проекте
// уже нет или ещё нет. Это не опечатка, а цитата.
$knownPaths = [
    'docs/ревью-2026-10-18.md → docs/ревью-проекта-до-релиза.md'
        => 'цитата: старый отчёт удалён, снимается с учёта в волне 0',
    'docs/ревью-2026-10-18.md → tools/check_ui_rules.php'
        => 'цитата: проверка сделана набором tests/run_ui_rules_tests.php',
    'docs/ревью-2026-10-18.md → tools/check_docs.php'
        => 'цитата: проверка сделана набором tests/run_docs_tests.php',
    'docs/план-устранения-по-ревью-2026-10-18.md → docs/ревью-проекта-до-релиза.md'
        => 'цитата из отчёта, снимается с учёта в волне 0',
    'docs/план-устранения-по-ревью-2026-10-18.md → tools/check_ui_rules.php'
        => 'переименовано в tests/run_ui_rules_tests.php',
    'docs/план-устранения-по-ревью-2026-10-18.md → tools/check_docs.php'
        => 'переименовано в tests/run_docs_tests.php',
    'ПРОДОЛЖИТЬ_РАБОТУ.md → public/_shot_tmp.php'
        => 'цитата: временный вход без пароля; файл удалён 22.10.2026 и в сборку не попадает',
    'ПРОДОЛЖИТЬ_РАБОТУ.md → docs/review-report-2026-10-21.md'
        => 'цитата: отчёт по ревью убран из проекта 22.10.2026; такие отчёты в коммиты '
            . 'не отправляются никогда — в них сведения об уязвимостях',
    'ПРОДОЛЖИТЬ_РАБОТУ.md → src/Repository/StudentRepository.php'
        => 'цитата: мёртвый репозиторий удалён задачей 2 (23.10.2026); '
            . 'в документе речь о сделанном — файл называется как удалённый',
];

$newMissing = array_values(array_diff($missingPaths, array_keys($knownPaths)));

$runner->equals(
    [],
    $newMissing,
    'пути в документации существуют: ' . implode('; ', array_slice($newMissing, 0, 8))
);

// ---------------------------------------------------------------------------
// 2. Удалённые миграции не упоминаются как существующие.
$runner->group('Документация: миграции');

$migrationMentions = [];

foreach ($currentDocs($texts) as $doc => $text) {
    if (preg_match_all('/`(\d{3}_[a-z0-9_]+\.sql)`/i', $text, $found) > 0) {
        foreach (array_unique($found[1]) as $name) {
            $migrationMentions[] = $doc . ' → ' . $name;
        }
    }
}

$knownMigrations = [
    'ПРОДОЛЖИТЬ_РАБОТУ.md → 002_users_and_roles.sql' => 'историческая справка о снятом номере, правка в волне 7',
    'ПРОДОЛЖИТЬ_РАБОТУ.md → 001_что_сделали.sql' => 'образец имени файла из database/migrations/README.md',
    'docs/ревью-2026-10-18.md → 002_users_and_roles.sql' => 'цитата из отчёта',
    'docs/план-устранения-по-ревью-2026-10-18.md → 002_users_and_roles.sql' => 'цитата из отчёта',
    'docs/план-устранения-по-ревью-2026-10-18.md → 015_issues_and_parallel.sql' => 'цитата из отчёта',
];

/*
 * Упоминание миграции в документации — это ссылка на файл, а не на слово.
 *
 * Список известных дополняется файлами, которые действительно лежат в
 * `database/migrations`: настоящая миграция, названная в документе, — это
 * нормально, и запрещать такие упоминания нельзя. Несуществующие имена
 * по-прежнему считаются ошибкой.
 */
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $path) {
    $name = basename((string) $path);

    foreach (array_keys($currentDocs($texts)) as $doc) {
        $knownMigrations[$doc . ' → ' . $name] = 'миграция, лежащая в database/migrations';
    }
}

$newMigrations = array_values(array_diff($migrationMentions, array_keys($knownMigrations)));

$runner->equals(
    [],
    $newMigrations,
    'упоминаний несуществующих миграций не добавилось: ' . implode('; ', $newMigrations)
);

// ---------------------------------------------------------------------------
// 3. Личных путей в документации нет.
//
// В журнале разработки до релиза нашёлся путь с именем пользователя Windows.
// Для публичного репозитория это имя разработчика на его машине, и оно не
// имеет никакого отношения к проекту.
$runner->group('Документация: личные данные');

$personal = [];

foreach ($texts as $doc => $text) {
    if (preg_match_all('/[A-Z]:\\\\?Users\\\\?[^`\s"\'\\\\]*/i', $text, $found) > 0) {
        foreach (array_unique($found[0]) as $hit) {
            $personal[] = $doc . ' → ' . $hit;
        }
    }
}

// Совпадение с известным местом ищется по началу строки: путь в документе
// может быть обрезан (осталось «C:\Users\»), и точное равенство перестаёт
// работать именно там, где список нужен.
//
// Список пуст не «потому что чисто», а потому что волна 0 вычистила путь из
// журнала работ и из этого же плана: цитата найденного — тоже находка, и
// публиковать её нечего. Вернётся путь — упадёт набор.
$knownPersonal = [];

$isKnown = static function (string $line) use ($knownPersonal): bool {
    foreach (array_keys($knownPersonal) as $known) {
        if (str_starts_with($line, $known)) {
            return true;
        }
    }

    return false;
};

$newPersonal = array_values(array_filter($personal, static fn (string $line): bool => !$isKnown($line)));

$runner->equals(
    [],
    $newPersonal,
    'личных путей в документации нет: ' . implode('; ', $newPersonal)
);

// ---------------------------------------------------------------------------
// 4. Версия в документах совпадает с настройкой.
$runner->group('Документация: версия');

$config = (string) file_get_contents($root . '/config/config.php');
preg_match("/'version'\s*=>\s*'([^']+)'/", $config, $versionMatch);

if (!isset($versionMatch[1])) {
    Fail:
    $runner->is(false, 'в config/config.php есть app.version');

exit($runner->report());
}

$version = trim($versionMatch[1]);

$runner->contains(
    (string) ($texts['README.md'] ?? ''),
    'Текущая версия: **' . $version . '**',
    'README.md называет ту же версию, что и настройка'
);

$developer = (string) ($texts['ПРОДОЛЖИТЬ_РАБОТУ.md'] ?? '');
preg_match_all('/\b\d+\.\d+(?:\.\d+)?\s*(?:alfa|beta|rc)\b/', $developer, $found);
$wrongVersions = array_values(array_unique(array_filter(
    $found[0],
    static fn (string $v): bool => trim($v) !== $version
)));

$runner->equals([], $wrongVersions, 'файл для разработчика называет только текущую версию: ' . implode(', ', $wrongVersions));

// ---------------------------------------------------------------------------
// 5. Минимальная версия PHP совпадает с тем, что проверяет index.php.
$runner->group('Документация: требования');

$index = (string) file_get_contents($root . '/public/index.php');
preg_match('/PHP_VERSION_ID\s*<\s*(\d+)/', $index, $phpMatch);

$requiredPhp = isset($phpMatch[1]) ? (int) $phpMatch[1] : 0;
$phpVersion = sprintf('%d.%d', intdiv($requiredPhp, 10000), intdiv($requiredPhp, 100) % 100);

$runner->is(
    $phpVersion !== '0.00' && str_contains((string) ($texts['README.md'] ?? ''), 'PHP ' . $phpVersion),
    'README.md называет минимальную версию PHP той же, что проверяет index.php (' . $phpVersion . ')'
);

// ---------------------------------------------------------------------------
// 6. Каждый набор из run_tests.ps1 назван в README.
//
// Считать строки бесполезно: непонятно, какой именно набор забыт. Поэтому
// сверяются имена, и отсутствующее называется поимённо.
$runner->group('Документация: тесты');

$runnerScript = (string) file_get_contents($root . '/tools/run_tests.ps1');

/*
 * Наборы — это записи вида `@{ Name = '…'; Path = '…' }`.
 *
 * Раньше искалось просто `Path = '…'`, и в список попадал ещё и путь к PHP,
 * задаваемый ключом `-PhpPath`: его не существует, набором он не является, а
 * README про него не пишет. Число наборов от этого расходилось на единицу, и
 * сверка «столько ли наборов названо в README» ругалась сама на себя.
 */
preg_match_all(
    "/@\{\s*Name\s*=\s*'[^']+'\s*;\s*Path\s*=\s*'([^']+)'/",
    $runnerScript,
    $suitePaths
);

$readme = (string) ($texts['README.md'] ?? '');

$undocumented = [];

foreach ($suitePaths[1] ?? [] as $path) {
    $file = basename((string) $path);

    if (str_contains($readme, $file) !== true) {
        $undocumented[] = $file;
    }
}

$runner->equals(
    [],
    $undocumented,
    'README.md называет каждый набор из run_tests.ps1: не названы — ' . implode(', ', $undocumented)
);

$suiteCount = count($suitePaths[1] ?? []);

$runner->is(
    $suiteCount >= 5,
    'наборы в run_tests.ps1 перечислены (иначе сверка выше ничего не проверяет): '
    . $suiteCount . ' шт.'
);

/*
 * Названное число должно совпадать с настоящим.
 *
 * «Наборов шесть», а их десять: человек планировал работу по этому числу, а
 * тесты, добавленные позже, в счёт не попадали.
 */
$russianNumbers = [
    'один' => 1, 'два' => 2, 'три' => 3, 'четыре' => 4, 'пять' => 5, 'шесть' => 6,
    'семь' => 7, 'восемь' => 8, 'девять' => 9, 'десять' => 10, 'одиннадцать' => 11,
    'двенадцать' => 12, 'тринадцать' => 13, 'четырнадцать' => 14, 'пятнадцать' => 15,
];

if (preg_match('/Наборов ([а-яё]+),/u', $readme, $countMatch)) {
    $named = (int) ($russianNumbers[mb_strtolower($countMatch[1], 'UTF-8')] ?? 0);

    $runner->equals(
        $suiteCount,
        $named,
        'README.md называет столько наборов тестов, сколько их в прогоне'
    );
} else {
    $runner->is(
        false,
        'README.md называет число наборов тестов словами — иначе сверять нечего'
    );
}

// ---------------------------------------------------------------------------
// 7. Утверждения, которые однажды оказались неправдой.
//
// В отчёте 2026-10-18 перечислены одиннадцать мест, где публичные файлы говорили
// о проекте то, чего в нём нет: «перенос класса на другой сервер», плагин
// «Варианты» с проверкой учителем вместо автопроверки, журнал `app.log` вместо
// `app-ГГГГ-ММ-ДД.log`, расширение `intl`, которого в коде нет, инструкция
// включить `app.debug` на боевом сервере и другие.
//
// Проверяются сами утверждения, а не места: пока текст говорит одно и то же,
// правка формулировки не потребует правки проверки.
$runner->group('Документация: утверждения о проекте');

$changelog = (string) ($texts['changelog.md'] ?? '');

/*
 * Переноса класса нет: переносятся работы.
 */
$runner->notContains(
    $changelog,
    'перенос класса',
    'changelog не обещает перенос класса: такого в коде нет'
);

$runner->is(
    is_dir($root . '/src/Transfer'),
    'перенос работ действительно есть, и обещать можно только его'
);

/*
 * Названия плагинов — как в манифестах, а не как в старом тексте.
 *
 * «Варианты» — это не «задания с вариантами ответа, проверяет учитель»: плагин
 * называется «Вариативная задача» и проверяет числа сам.
 */
$variableManifest = (string) file_get_contents($root . '/plugins/variable/plugin.json');
preg_match('/"name"\s*:\s*"([^"]+)"/u', $variableManifest, $pluginName);

$runner->is(
    str_contains($readme, (string) ($pluginName[1] ?? 'Вариативная задача')),
    'README.md называет плагин так же, как его манифест: ' . (string) ($pluginName[1] ?? '?')
);

$runner->notContains(
    $readme,
    '«Варианты»',
    'старого названия плагина в README не осталось'
);

/*
 * Журнал событий называется так же, как его называет ядро.
 *
 * Ядро пишет файл по шаблону `/app-' . date('Y-m-d') . '.log'`. Проверяется
 * именно этот шаблон, а не слово «app-ГГГГ»: переименовать журнал в ядре и
 * забыть README должно быть так же трудно, как наоборот.
 */
$kernel = (string) file_get_contents($root . '/src/Http/Kernel.php');

$runner->is(
    str_contains($kernel, "'/app-' . date('Y-m-d') . '.log'"),
    'ядро пишет журнал событий по шаблону app-ГГГГ-ММ-ДД.log'
);

$runner->contains(
    $readme,
    'app-ГГГГ-ММ-ДД.log',
    'README.md называет журнал событий так же, как ядро'
);

/*
 * Расширений, которых нет в коде, README требовать не может.
 *
 * «Без intl не работает Unicode-нормализация имён» — правдоподобное и неверное
 * утверждение: ни Normalizer, ни intl в проекте не встречаются ни разу.
 */
$srcPhp = '';

foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
    ) as $file
) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $srcPhp .= (string) file_get_contents($file->getPathname());
    }
}

$runner->notContains(
    $readme,
    'Normalizer',
    'README.md не обещает Unicode-нормализацию имён'
);

$runner->notContains(
    $srcPhp,
    'Normalizer',
    'и в коде её нет — утверждение было неверным'
);

/*
 * Диагностическая страница требует ключа — README не должен предлагать её открыть
 * «просто так».
 */
$runner->notContains(
    $readme,
    'check_login.php?email=',
    'README.md не предлагает открыть диагностику без ключа'
);

$runner->contains(
    (string) file_get_contents($root . '/public/check_login.php'),
    'diagnostics.key',
    'а ключ диагностики требуется на самой странице'
);

/*
 * Включать отладку на боевом сервере README не советует: режим выводит в
 * страницу внутренние пути и тексты ошибок.
 */
$runner->notContains(
    $readme,
    'Включите `app.debug = true`',
    'README.md не советует включать отладку на сервере'
);

/*
 * Лицензии сторонних библиотек: README не может обещать файлы, которых нет.
 *
 * У DataTables и Quill текст лежит рядом с кодом, у SortableJS — в шапке
 * файла, а от Gentelella в проекте остался один CSS без шапки. Поэтому README
 * говорит «где именно написано в LICENSE», а не «рядом с файлами».
 *
 * Проверяется не наличие файла у каждой библиотеки — её и не должно быть
 * обязательно, — а то, что библиотека без файла названа поимённо: иначе
 * читатель будет искать `License.txt` там, где его нет.
 */
$withoutLicenseFile = [];

foreach (['gentelella', 'datatables', 'quill'] as $vendor) {
    $vendorDir = $root . '/public/assets/vendor/' . $vendor;

    if (!is_dir($vendorDir)) {
        continue;
    }

    $hasLicenseFile = false;

    foreach (glob($vendorDir . '/*') ?: [] as $entry) {
        if (stripos(basename((string) $entry), 'licen') !== false) {
            $hasLicenseFile = true;
        }
    }

    if (!$hasLicenseFile) {
        $withoutLicenseFile[] = $vendor;
    }
}

$unnamedVendors = array_values(array_filter(
    $withoutLicenseFile,
    static fn (string $vendor): bool => !str_contains($readme, $vendor)
));

$runner->equals(
    [],
    $unnamedVendors,
    'библиотеки без файла лицензии названы поимённо: ' . implode(', ', $unnamedVendors)
);

$runner->notContains(
    $readme,
    'Тексты лицензий — рядом с файлами',
    'README.md не обещает, что тексты лицензий лежат рядом с каждым файлом'
);

/*
 * Роли перечислены все: забытая роль — это человек, которому нельзя объяснить,
 * почему он ничего не видит.
 *
 * Четыре роли — константы в Role.php, а владелец — не роль, а отметка
 * `users.is_owner` у того же первого зарегистрировавшегося. Проверяются оба
 * вида, потому что README перечисляет и то и другое, а забыть можно любое.
 *
 * Роли «ученик» здесь намеренно нет: ученик живёт в отдельной таблице
 * `students` и ролей не имеет. Поэтому проверка идёт в обе стороны — не только
 * «названная роль есть в коде», но и «роль, которой в коде нет, не названа
 * как роль». Иначе README мог бы продолжать звать ученика ролью, а читатель
 * искал бы его в списке ролей и не находил.
 */
$roles = (string) file_get_contents($root . '/src/Auth/Role.php');
$schema = (string) file_get_contents($root . '/database/schema.sql');

$staffRoles = [
    'администратор' => 'admin',
    'заведующ' => 'head_teacher',
    'учител' => 'teacher',
    'наблюдател' => 'observer',
];

/*
 * Роль завучщего называется в README двумя словами: «заведующий» и «завуч».
 *
 * Оба слова правильные, и какое из них употребить — дело автора текста. Проверка
 * следит за смыслом (роль названа, роль есть в коде), а не за слово: требование
 * одного написания ломало бы правку формулировки, ничего не проверяя по делу.
 */
$roleSpellings = [
    'заведующ' => ['заведующ', 'завуч'],
];

$roleProblems = [];

foreach ($staffRoles as $russian => $code) {
    $spellings = $roleSpellings[$russian] ?? [$russian];
    $named = false;

    foreach ($spellings as $spelling) {
        if (str_contains($readme, $spelling)) {
            $named = true;
            break;
        }
    }

    if (!$named) {
        $roleProblems[] = 'в README нет «' . $russian . '»';
    }

    if (!str_contains($roles, "'" . $code . "'")) {
        $roleProblems[] = 'в коде нет роли ' . $code;
    }
}

if (!str_contains($readme, 'владелец')) {
    $roleProblems[] = 'в README нет владельца';
}

if (!str_contains($schema, 'is_owner')) {
    $roleProblems[] = 'в схеме нет отметки is_owner';
}

/*
 * Роль ученика не должна вернуться.
 *
 * Ученик — отдельная таблица со своими ключами сессии, и роль в нём была
 * ошибкой модели: она давала право сдавать работы через запись в user_roles,
 * а после разделения право это следует из самой личности. Если роль снова
 * появится, `/login` снова найдёт ученика среди сотрудников — и разделение
 * кончится.
 */
if (str_contains($roles, "'student'")) {
    $roleProblems[] = 'роль student снова есть в Role.php, хотя ученики живут в своей таблице';
}

if (!str_contains($schema, 'CREATE TABLE IF NOT EXISTS `students`')) {
    $roleProblems[] = 'в схеме нет таблицы students';
}

if (str_contains($schema, '`class_students`')) {
    $roleProblems[] = 'в схеме осталась таблица class_students: у ученика один класс';
}

$runner->equals([], $roleProblems, 'роли названы в README и есть в коде: ' . implode('; ', $roleProblems));

/*
 * Наружу отдаётся только public/.
 *
 * Правило доступа не проверяется запросом к серверу: тестовая машина отдаёт
 * каталог своим сервером без Apache, и проверка на такой выдаче означала бы
 * ничего. Проверяется само правило — и это ровно то место, где ошибка остаётся
 * незамеченной: белый список легко превращается обратно в чёрный, и служебные
 * файлы снова уходят наружу.
 *
 * Проверяются обе половины: в Apache нет перечисления закрытого, а адрес вне
 * public/ уводится внутрь; в nginx PHP исполняется только из public/.
 */
$runner->group('Правила: наружу отдаётся только public/');

$htaccess = (string) file_get_contents($root . '/.htaccess');

$runner->is(
    preg_match('#RewriteRule\s+\^\(\?!public/\)\(\.\*\)\$\s+public/\$1#', $htaccess) === 1,
    'Apache уводит любой адрес вне public/ внутрь, а не перечисляет закрытое'
);

$forbiddenInHtaccess = ['templates', 'docs', 'src', 'config', 'tools', 'tests', 'database'];

$stillListed = [];

foreach ($forbiddenInHtaccess as $dir) {
    if (preg_match('#RewriteRule[^\\n]*' . preg_quote($dir, '#') . '[^\\n]*\[F,L\]#', $htaccess) === 1) {
        $stillListed[] = $dir;
    }
}

$runner->equals(
    [],
    $stillListed,
    'в Apache нет чёрного списка каталогов — новый каталог иначе пришлось бы вносить руками: '
    . implode(', ', $stillListed)
);

$runner->notContains(
    $htaccess,
    'RewriteCond %{REQUEST_FILENAME} !-f',
    'Apache не отдаёт существующий файл вне public/ как есть — это обходило бы правило целиком'
);

$nginx = (string) file_get_contents($root . '/deploy/nginx-shared-hosting.conf.example');

$runner->is(
    preg_match('#location\s+~\s+\^/public/\.\+\\\.php\$#', $nginx) === 1,
    'nginx исполняет PHP только из public/'
);

$runner->is(
    preg_match('#location\s+~\s+\\\\\.php\$\s*\{\s*return\s+404#', $nginx) === 1,
    'PHP вне public/ в nginx не исполняется'
);

/*
 * Прокси и адрес клиента.
 *
 * Обратный прокси подменяет `REMOTE_ADDR`, и без доверия к нему все, кто пришёл
 * через прокси, выглядят одним адресом: лимиты на неудачные входы делятся между
 * всеми сразу. Но заголовок `X-Forwarded-For` подделывает кто угодно, поэтому
 * читать его без явного списка нельзя — иначе человек подставит себе чужой адрес
 * и обойдёт лимит.
 *
 * Проверяется именно это: без доверия заголовок игнорируется, с доверием читается
 * первый адрес цепочки, а соседний адрес в той же сети доверия не получает.
 */
$runner->group('Адрес клиента за обратным прокси');

// Короткое имя класса: блок проверок должен читаться как чтение, а не как
// набор длинных ссылок на пространство имён.
class_alias(\MyEdu\Core\Request::class, 'Request');

$forwarded = static fn (string $remote, string $chain): Request => new Request(
    [],
    [],
    ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $chain]
);

Request::setTrustedProxies([]);

$runner->equals(
    '10.0.0.1',
    $forwarded('10.0.0.1', '203.0.113.7')->ip(),
    'без списка доверенных заголовок не читается'
);

Request::setTrustedProxies(['10.0.0.1']);

$runner->equals(
    '203.0.113.7',
    $forwarded('10.0.0.1', '203.0.113.7')->ip(),
    'доверенному прокси верится'
);

$runner->equals(
    '203.0.113.9',
    $forwarded('10.0.0.1', '198.51.100.4, 203.0.113.9')->ip(),
    'из цепочки берётся дописанный прокси адрес, а не подставленный клиентом'
);

$runner->equals(
    '198.51.100.4',
    $forwarded('10.0.0.1', '198.51.100.4')->ip(),
    'единственный адрес цепочки берётся как есть'
);

Request::setTrustedProxies(['10.0.0.1', '10.0.0.2']);

$runner->equals(
    '203.0.113.9',
    $forwarded('10.0.0.1', '203.0.113.9, 10.0.0.2')->ip(),
    'цепочка из двух доверенных прокси проходится до конца'
);

$runner->equals(
    '10.0.0.9',
    $forwarded('10.0.0.9', '203.0.113.7')->ip(),
    'соседний адрес в той же сети доверия не получает'
);

Request::setTrustedProxies(['10.0.0.0/24']);

$runner->equals(
    '203.0.113.7',
    $forwarded('10.0.0.5', '203.0.113.7')->ip(),
    'сеть в списке доверенных целиком'
);

Request::setTrustedProxies([]);

/*
 * Токен не должен возвращаться в адрес.
 *
 * Ссылка с токеном попадает в историю браузера, в журналы сервера и прокси и в
 * `Referer`, а токен одноразовый: им можно открыть чужую попытку или сменить
 * чужой пароль. Проверяется, что адрес чистится и что скрипт очистки подключён.
 */
$runner->group('Токены не остаются в адресе');

$authController = (string) file_get_contents($root . '/src/Http/Controller/AuthController.php');

$runner->is(
    preg_match('#[\'"]auth/reset\?token=#', $authController) !== 1,
    'токен сброса не подставляется в адрес возврата'
);

$shellPublic = (string) file_get_contents($root . '/templates/_shell_public.php');

$runner->contains(
    $shellPublic,
    'assets/js/token-in-url.js',
    'оболочка ученика подключает очистку адреса'
);

$tokenScript = (string) file_get_contents($root . '/public/assets/js/token-in-url.js');

$runner->contains(
    $tokenScript,
    "['t', 'token']",
    'из адреса убираются и токен попытки, и токен сброса пароля'
);

$runner->contains(
    $tokenScript,
    'params.delete(name)',
    'токен удаляется из строки запроса'
);

$runner->contains(
    $tokenScript,
    'history.replaceState',
    'адрес подменяется без новой записи истории'
);

/*
 * Локальное переопределение конфигурации.
 *
 * Проверяется настоящий путь: два настоящих файла во временном каталоге и
 * Config::load(). Через отражение проверялась бы приватная функция, а сломанная
 * именно тут — то, что владелец не может вычеркнуть адрес из списка и не может
 * очистить список вовсе, — осталась бы незамеченной: в файле конфигурации всё
 * выглядит правильно.
 */
/*
 * Плагинный README обязан совпадать с кодом.
 *
 * Расхождение тут не бумажное: README перечисляет, какие адреса плагин занимать
 * не может. Пока список жил внутри метода, документация описывала его по памяти
 * — в README не было `uploads`, а объяснение про `tests` ссылалось на правила
 * `.htaccess`, которых после волны 4 уже нет. Плагин, написанный по README,
 * занимал адрес, который README же и объявил свободным.
 */
$runner->group('README плагинов совпадает с кодом');

$pluginReadme = (string) file_get_contents(dirname(__DIR__) . '/plugins/README.md');

$runner->notContains(
    $pluginReadme,
    'taskTitle',
    'README не называет метода, которого в контракте нет'
);

$runner->contains(
    $pluginReadme,
    'taskPreview',
    'README называет метод, который есть в контракте'
);

/** @var list<string> $reservedPrefixes */
$reservedPrefixes = (new ReflectionClass(MyEdu\Plugin\PluginManager::class))
    ->getConstant('RESERVED_PREFIXES');

$missingPrefixes = [];

foreach ($reservedPrefixes as $prefix) {
    if (str_contains($pluginReadme, '`' . $prefix . '`') === false) {
        $missingPrefixes[] = $prefix;
    }
}

$runner->is(
    $missingPrefixes === [],
    'README перечисляет каждый зарезервированный префикс'
    . ($missingPrefixes === [] ? '' : '; не названы: ' . implode(', ', $missingPrefixes))
);

$runner->contains(
    $pluginReadme,
    'RESERVED_PREFIXES',
    'README ссылается на список в коде, а не перечисляет его повторно'
);

$runner->group('Конфигурация: локальное переопределение');

$configDir = sys_get_temp_dir() . '/myedu-config-' . bin2hex(random_bytes(4));

if (!is_dir($configDir)) {
    mkdir($configDir, 0777, true);
}

file_put_contents(
    $configDir . '/config.php',
    '<?php return ' . var_export([
        'security' => [
            'trusted_proxies' => ['10.0.0.1', '10.0.0.2', '10.0.0.3'],
            'login_attempts' => 5,
        ],
        'session' => ['secure_cookies' => true, 'name' => 'myedu'],
    ], true) . ';'
);

$writeLocal = static function (array $local) use ($configDir): array {
    file_put_contents($configDir . '/config.local.php', '<?php return ' . var_export($local, true) . ';');

    return MyEdu\Core\Config::load($configDir)->array('security.trusted_proxies');
};

$runner->equals(
    ['10.0.0.9'],
    $writeLocal(['security' => ['trusted_proxies' => ['10.0.0.9']]]),
    'список перекрывается целиком: вычеркнутые адреса не остаются доверенными'
);

$runner->equals(
    [],
    $writeLocal(['security' => ['trusted_proxies' => []]]),
    'список очищается пустым списком, а не остаётся основанием'
);

file_put_contents(
    $configDir . '/config.local.php',
    '<?php return ' . var_export([
        'security' => ['login_attempts' => 3],
        'session' => ['secure_cookies' => false],
    ], true) . ';'
);

$loaded = MyEdu\Core\Config::load($configDir);

$runner->is(
    $loaded->int('security.login_attempts') === 3,
    'словарь накладывается поимённо'
);

$runner->is(
    $loaded->bool('session.secure_cookies') === false,
    'соседние ключи словаря не теряются при наложении'
);

$runner->is(
    $loaded->string('session.name') === 'myedu',
    'не названный ключ сохраняет значение из общей конфигурации'
);

foreach (glob($configDir . '/*') ?: [] as $file) {
    unlink($file);
}

rmdir($configDir);

/*
 * Документация о плагинах.
 *
 * Здесь нет охоты за опечатками — есть проверка одного: пример в README должен
 * совпадать с настоящим плагином. Пока пример был выдуман, он молча разошёлся
 * с кодом сразу по трём пунктам: неймспейс, конструктор раннера и способ взять
 * каталог миграций. Человек, скопировавший такое, получил бы плагин, который не
 * грузится, и ошибку в файле, которого у него нет.
 */
$runner->group('Документация о плагинах говорит правду о коде');

$readme = (string) file_get_contents($root . '/README.md');
$pluginReadme = (string) file_get_contents($root . '/plugins/README.md');
$testPlugin = (string) file_get_contents($root . '/plugins/test/src/TestPlugin.php');

$runner->is(
    str_contains($pluginReadme, 'private readonly App $app') === false,
    'плагинам больше не обещают контейнер приложения'
);

$runner->is(
    str_contains($pluginReadme, 'private readonly PluginServices $services') === true,
    'конструктор в документации — тот, что в коде'
);

$runner->is(
    str_contains($testPlugin, 'private readonly PluginServices $services') === true,
    'плагин из поставки действительно получает список сервисов'
);

$runner->is(
    str_contains($testPlugin, 'private readonly App $app') === false,
    'плагин из поставки не держит контейнер'
);

/*
 * Пример в README проверяется по существующим файлам, а не по написанному
 * тексту: каждый путь, упомянутый в примере, обязан быть на диске.
 */
foreach (['plugins/test/plugin.json', 'plugins/test/src/TestPlugin.php', 'plugins/test/migrations'] as $path) {
    $runner->is(
        file_exists($root . '/' . $path),
        'путь примера существует: ' . $path
    );
}

$runner->is(
    str_contains($readme, 'plugins/test/plugin.json') === true,
    'пример ведёт к плагину из поставки, а не к выдуманному'
);

$runner->is(
    str_contains($readme, 'plugins/example') === false,
    'выдуманного примера в README не осталось'
);

/*
 * Методы сервисов перечислены в документации и существуют в коде.
 *
 * Список API, написанный по памяти, выглядит убедительно и не проверяется
 * ничем, пока кто-нибудь не напишет по нему плагин. Таблица сверяется с
 * объявлениями: названного метода в сервисах нет — значит документация врёт.
 *
 * Сравнивается только таблица сервисов. Таблица контракта рядом перечисляет
 * методы `AssessmentPlugin`, их в `PluginServices` нет и быть не должно — иначе
 * проверка ругалась бы на верно написанное.
 */
$servicesSection = '';

if (preg_match('/Через `\$services` доступно:\s*\n(.*?)\n\s*\n/s', $pluginReadme, $section)) {
    $servicesSection = $section[1];
}

preg_match_all('/^\| `([a-z][A-Za-z]*)\(\)`?/m', $servicesSection, $found);
preg_match_all('/public function ([a-z][A-Za-z]*)\(/', (string) file_get_contents($root . '/src/Plugin/PluginServices.php'), $real);

$runner->is(
    $servicesSection !== '' && $found[1] !== [],
    'таблица сервисов в plugins/README найдена и разобрана'
);

foreach (['manifest', 'database', 'view', 'session', 'csrf', 'auth', 'config', 'dump', 'assignments', 'ratings', 'ownPlugin'] as $method) {
    $runner->is(
        in_array($method, $real[1], true),
        'метод, названный в документации, есть в сервисах: ' . $method
    );
}

foreach ($found[1] as $documented) {
    $runner->is(
        in_array($documented, $real[1], true),
        'всё, что перечислено таблицей сервисов, существует: ' . $documented
    );
}

/*
 * Резервная копия: документ обязан называть оба поведения.
 *
 * «Копия снимается всегда» — неправда, если копия не снялась: при
 * `backup_before_migrations_blocking => false` миграция всё равно применяется.
 * Хостер, который это не прочёл, будет считать, что точка отката есть всегда.
 */
$runner->is(
    str_contains($readme, 'security.backup_before_migrations_blocking') === true,
    'README называет ключ, которым выбирается поведение при сбое копии'
);

$runner->is(
    str_contains($readme, 'миграция не применяется') === true
    && str_contains($readme, 'применяется без точки отката') === true,
    'README описывает оба поведения, а не только удачное'
);

/*
 * Формат списка учеников зафиксирован в коде, и README обязан совпадать с ним.
 *
 * Раньше здесь была выдуманная таблица из шести колонок с логином и паролем.
 * Такого формата нет: файл принимает ровно Фамилия; Имя; Отчество, а логины и
 * пароли система придумывает сама. Учитель, поверивший заголовку, приготовил бы
 * файл, который импорт не примет.
 */
$csv = (string) file_get_contents($root . '/src/Auth/StudentListCsv.php');

$runner->is(
    str_contains($csv, "'Фамилия', 'Имя', 'Отчество'") === true,
    'формат файла учеников прежний: три колонки'
);

$runner->is(
    str_contains($readme, 'Три колонки') === false
    && str_contains($readme, 'три колонки — Фамилия; Имя; Отчество') === true,
    'README называет те же три колонки, что и код'
);

$runner->is(
    str_contains($readme, 'Логин;Пароль') === false && str_contains($readme, '| Пароль |') === false,
    'в README нет колонок логина и пароля: система генерирует их сама'
);

/*
 * Переменные окружения перечислены в README и читаются кодом.
 *
 * Список из памяти расходится с кодом на первой же незнакомой переменной:
 * человек ставит MYEDU_SKIP_BACKUP, а программа её не видит и снимает копию
 * мимо его воли — или наоборот.
 */
$source = '';

foreach (['src', 'database'] as $dir) {
    foreach (glob($root . '/' . $dir . '/**/*.php', GLOB_BRACE) ?: [] as $file) {
        $source .= (string) file_get_contents($file);
    }
}

foreach (glob($root . '/database/*.php') ?: [] as $file) {
    $source .= (string) file_get_contents($file);
}

foreach (['MYEDU_DB_NAME', 'MYEDU_ALLOW_DESTRUCTIVE', 'MYEDU_SKIP_BACKUP', 'MYSQLDUMP', 'MYEDU_CREATE_PASSWORD'] as $var) {
    $runner->is(
        str_contains($source, "'" . $var . "'") === true,
        'переменная из README читается кодом: ' . $var
    );

    $runner->is(
        str_contains($readme, '`' . $var . '`') === true,
        'переменная, читаемая кодом, названа в README: ' . $var
    );
}

exit($runner->report());
