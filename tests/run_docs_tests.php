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
 * Пять ролей — константы в Role.php, а владелец — не роль, а отметка
 * `users.is_owner` у того же первого зарегистрировавшегося. Проверяются оба
 * вида, потому что README перечисляет и то и другое, а забыть можно любое.
 */
$roles = (string) file_get_contents($root . '/src/Auth/Role.php');
$schema = (string) file_get_contents($root . '/database/schema.sql');

$staffRoles = [
    'администратор' => 'admin',
    'заведующ' => 'head_teacher',
    'учител' => 'teacher',
    'наблюдател' => 'observer',
    'ученик' => 'student',
];

$roleProblems = [];

foreach ($staffRoles as $russian => $code) {
    if (!str_contains($readme, $russian)) {
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

$runner->equals([], $roleProblems, 'роли названы в README и есть в коде: ' . implode('; ', $roleProblems));

exit($runner->report());