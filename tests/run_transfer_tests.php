<?php

declare(strict_types=1);

/**
 * Проверки чтения ZIP-пакета переноса без БД и внешних расширений.
 *
 * Запуск: php tests/run_transfer_tests.php
 */

require dirname(__DIR__) . '/src/Transfer/TransferException.php';
require dirname(__DIR__) . '/src/Transfer/Zip.php';
require dirname(__DIR__) . '/src/Auth/StudentListCsv.php';
require dirname(__DIR__) . '/src/Transfer/WorkImporter.php';

use MyEdu\Auth\StudentListCsv;
use MyEdu\Transfer\TransferException;
use MyEdu\Transfer\WorkImporter;
use MyEdu\Transfer\Zip;

require __DIR__ . '/support/TestKit.php';

/** ZIP с одной записью: метод 0 (stored) или 8 (deflate). */
$singleEntryZip = static function (string $name, string $content, int $method = 8): string {
    $compressed = $method === 8 ? gzdeflate($content) : $content;
    $crc = crc32($content);
    $size = strlen($content);
    $compressedSize = strlen($compressed);
    $nameLength = strlen($name);

    $local = "\x50\x4b\x03\x04"
        . pack('v', 20) . pack('v', 0) . pack('v', $method)
        . pack('v', 0) . pack('v', 0x21)
        . pack('V', $crc) . pack('V', $compressedSize) . pack('V', $size)
        . pack('v', $nameLength) . pack('v', 0) . $name . $compressed;

    $central = "\x50\x4b\x01\x02"
        . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $method)
        . pack('v', 0) . pack('v', 0x21)
        . pack('V', $crc) . pack('V', $compressedSize) . pack('V', $size)
        . pack('v', $nameLength) . pack('v', 0) . pack('v', 0)
        . pack('v', 0) . pack('v', 0) . pack('V', 32) . pack('V', 0)
        . $name;

    $end = "\x50\x4b\x05\x06"
        . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1)
        . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);

    return $local . $central . $end;
};

$runner = new TestRunner();
$reflection = new ReflectionMethod(Zip::class, 'readByHand');
$reflection->setAccessible(true);

$runner->group('ZIP-перенос: чтение и лимиты');

$valid = $singleEntryZip('work.json', '{"title":"Работа"}');
$path = tempnam(sys_get_temp_dir(), 'myedu-zip-test-');

try {
    file_put_contents($path, $valid);
    $read = Zip::read($path);
    $runner->equals('{"title":"Работа"}', $read['work.json'] ?? null,
        'архив читается через установленный путь');
    $runner->equals('{"title":"Работа"}', $reflection->invoke(null, $valid)['work.json'] ?? null,
        'fallback-разбор понимает ZIP с deflate');

    $badPath = $singleEntryZip('../config.php', 'not a valid package');
    $pathError = false;

    try {
        $reflection->invoke(null, $badPath);
    } catch (TransferException) {
        $pathError = true;
    }

    $runner->is($pathError, 'fallback отклоняет имена файлов с выходом из каталога');

    $bomb = $singleEntryZip('media/large.txt', str_repeat('A', 16 * 1024 * 1024 + 1));
    file_put_contents($path, $bomb);
    $bombRejected = false;

    try {
        Zip::read($path);
    } catch (TransferException) {
        $bombRejected = true;
    }

$runner->is($bombRejected, 'архив отклоняется по заявленному размеру до распаковки содержимого');

$fallbackBombRejected = false;

try {
    $reflection->invoke(null, $bomb);
} catch (TransferException) {
    $fallbackBombRejected = true;
}

$runner->is($fallbackBombRejected, 'fallback ограничивает распакованный размер до inflate');

/*
 * Архив, который врёт в заголовках.
 *
 * Классическая бомба: центральный каталог обещает килобайт, а локальный
 * заголовок — терабайты. Распаковщик верит локальному заголовку, поэтому
 * сверка `strlen($content) !== $size` срабатывает уже после того, как память
 * занята. Единственное место, где проверка что-то значит, — до распаковки.
 */
$runner->group('ZIP-перенос: расхождение в заголовках');

/*
 * Тот же архив, но размер в центральном каталоге уменьшен до килобайта.
 * Локальный заголовок не трогается: именно на этом и строится бомба.
 */
$liar = $singleEntryZip('media/large.txt', str_repeat('B', 64 * 1024));

$liarPos = strpos($liar, "\x50\x4b\x01\x02");

if ($liarPos === false) {
    $runner->is(false, 'в собранном архиве есть центральный каталог');
} else {
    // 24 знака центрального заголовка: 8 — время и дата, затем размеры.
    $centralHead = substr($liar, $liarPos + 20, 24);
    $centralFixed = $centralHead;
    $realSize = unpack('V', substr($centralHead, 8, 4))[1];
    $liar = substr($liar, 0, $liarPos + 20)
        . $centralFixed
        . pack('V', 1024) . pack('V', 1024)
        . substr($liar, $liarPos + 20 + 24);

    file_put_contents($path, $liar);

    $liarRejected = false;
    $liarMessage = '';

    try {
        Zip::read($path);
    } catch (TransferException $e) {
        $liarRejected = true;
        $liarMessage = $e->getMessage();
    }

    $runner->is($liarRejected, 'архив с расхождением в заголовках отклонён до распаковки');

    /*
     * Слова в отказе зависят от того, какой разбор доступен: с расширением zip
     * отказ приходит от сверки заголовков, без него — от ограничения inflate.
     * Проверяется поэтому сам отказ, а не его формулировка: обе ветки означают
     * одно — содержимое не распаковано.
     */
}
} finally {
    @unlink($path);
}

$runner->group('CSV учеников: ошибочные строки не импортируются');

$parser = new StudentListCsv();
$parsed = $parser->parse(
    "Фамилия;Имя;Отчество\r\n\r\n"
    . str_repeat('Ф', 65) . ";Иван;Ильич\r\n"
    . "Петров;Анна;Игоревна;лишняя колонка\r\n"
    . "Сидоров;Илья;Ильич\r\n"
);

$runner->equals(1, count($parsed['rows']), 'в список создания попадает только валидная строка');
$runner->equals('Сидоров', $parsed['rows'][0]['family'], 'чрезмерно длинное ФИО не становится записью ученика');
$runner->equals(3, $parsed['errors'][0]['line'], 'номер ошибки учитывает пропущенную пустую строку');
$runner->equals(4, $parsed['errors'][1]['line'], 'лишние колонки отмечены по физическому номеру строки');

$runner->group('Перенос работы: порядок карточек не зависит от порядка в файле');

/*
 * Порядок обхода решает, какая карточка выживет, если в файле совпали место и
 * вариант: остальные уходят в отчёт как дубли. Значит, при перестановке строк
 * во входе внос обязан дать тот же самый набор — иначе один и тот же пакет
 * переносится по-разному в зависимости от того, как его прочитали.
 */
$importer = (new ReflectionClass(WorkImporter::class))->newInstanceWithoutConstructor();

$sortReflection = new ReflectionMethod(WorkImporter::class, 'sortedItems');
$sortReflection->setAccessible(true);

// Сортировке ни база, ни плагины не нужны, а конструктор требует и то и другое.
$sort = static fn (array $work): array => $sortReflection->invoke($importer, $work);

/*
 * Чужой файл проверяется до записи.
 *
 * Значения из пакета шли в базу как есть, и недопустимые обрывали импорт ошибкой
 * СУБД посреди работы — без указания, что именно не так. Проверяется то, что
 * видно до базы: перечисления, вес, цену и время.
 */
$validateReflection = new ReflectionMethod(WorkImporter::class, 'validate');
$validateReflection->setAccessible(true);

$validate = static function (array $work) use ($importer, $validateReflection): string {
    try {
        $validateReflection->invoke($importer, $work);

        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

$goodWork = [
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'time_limit'    => 45,
    'items'         => [['position' => 1, 'variant' => 1, 'weight' => 1.5, 'max_score' => 2]],
];

$runner->equals('', $validate($goodWork), 'обычный пакет проходит проверку');

$cases = [
    'недопустимый grade_mode' => ['grade_mode' => 'как-угодно', 'want' => 'оценок'],
    'недопустимый criteria_mode' => ['criteria_mode' => 'магией', 'want' => 'баллов'],
    'время в миллиарде минут' => ['time_limit' => 999999999, 'want' => 'время'],
    'минус в весе' => ['items' => [['position' => 1, 'weight' => -3]], 'want' => 'вес'],
    'цена в миллиард баллов' => ['items' => [['position' => 1, 'max_score' => 1e12]], 'want' => 'недопустимую цену'],
];

foreach ($cases as $title => $case) {
    $want = $case['want'];
    unset($case['want']);
    // Значения проверки должны перекрывать исходный пакет, а не наоборот,
// поэтому объединение идёт от случая: в PHP ключ из левого операнда
// побеждает, и порядок здесь решает, что именно проверяется.
$message = $validate($case + $goodWork);

    $runner->is(str_contains($message, $want), $title . ' отклонён с объяснением: ' . $message);
}

$labels = static fn (array $items): array => array_map(
    static fn (array $i): string => (string) ($i['title'] ?? ''),
    $items
);

$card = static fn (int $position, int $variant, string $title): array => [
    'position' => $position,
    'variant'  => $variant,
    'title'    => $title,
];

// Одна строка в трёх вариантах плюс вторая строка: варианты перечислены
// вперемешку, как их может выдать разборщик чужого пакета.
$straight = $sort(['items' => [
    $card(1, 1, 'один-1'), $card(1, 2, 'один-2'), $card(1, 3, 'один-3'), $card(2, 1, 'два-1'),
]]);

$shuffled = $sort(['items' => [
    $card(1, 3, 'один-3'), $card(2, 1, 'два-1'), $card(1, 1, 'один-1'), $card(1, 2, 'один-2'),
]]);

$runner->equals(
    ['один-1', 'один-2', 'один-3', 'два-1'],
    $labels($straight),
    'карточки идут по месту, а внутри места — по варианту'
);

$runner->equals(
    $labels($straight),
    $labels($shuffled),
    'перестановка строк во входе не меняет порядок обхода'
);

// Место без варианта в файле — это первый вариант, и он обязан встать первым,
// иначе карточка получит чужое место в раздаче.
$withoutVariant = $sort(['items' => [
    ['position' => 1, 'title' => 'без варианта'],
    $card(1, 2, 'второй'),
]]);

$runner->equals(
    ['без варианта', 'второй'],
    $labels($withoutVariant),
    'отсутствующий вариант считается первым и не уходит в конец'
);

/*
 * Числовые типы OnlineTestPad, включая пропуск с описанием.
 *
 * Типы 470 и 475 — это тот же числовой ответ, только с необязательным
 * пояснением. Раньше они не были перенесены вовсе, и задание уходило в
 * пропущенные со словами «в системе нет задания "пропуск с описанием"» —
 * при том, что числовая часть переносится прекрасно.
 */
$runner->group('Импорт из OnlineTestPad: числовые типы');

/*
 * Набор модульный, базы нет, а автозагрузчик ядра к нему не подключён:
 * нужные файлы берутся напрямую, как это делает сам набор для Zip и CSV.
 * Парсер тянет за собой очистку разметки — она тоже нужна.
 */
$otpFiles = [
    'src/Core/Html.php',
    'src/Core/TextFragment.php',
    'src/Import/OtpQuestionTypes.php',
    'src/Import/OtpParser.php',
];

foreach ($otpFiles as $otpFile) {
    if (is_file($otpFilePath = dirname(__DIR__) . '/' . $otpFile)) {
        require_once $otpFilePath;
    }
}

$numericRaw = static fn (int $answerType): array => [
    'Questions' => [[
        'Number'           => 1,
        'QuestionType'     => 10,
        'AnswerType'       => $answerType,
        'maxScore'         => 1,
        'Text'             => 'Сколько бит в байте?',
        'AnswerIsRequired' => true,
        'AnswerTemplates'  => [['ValueFloat' => 8, 'ValueText' => '8']],
    ]],
];

foreach ([410, 450, 470, 475] as $answerType) {
    $converted = (new MyEdu\Import\OtpParser())->parse($numericRaw($answerType));
    $question = $converted['questions'][0] ?? null;

    $runner->equals(
        'numeric',
        $question['type'] ?? null,
        'числовой тип ' . $answerType . ' переносится числовым заданием'
    );
    $runner->equals(
        '8',
        (string) ($question['config']['expected'] ?? ''),
        'правильный числовой ответ типа ' . $answerType . ' переносится'
    );
    $runner->equals(
        [],
        $converted['skipped'],
        'тип ' . $answerType . ' не попадает в пропущенные'
    );
}

exit($runner->report());
