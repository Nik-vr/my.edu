<?php

declare(strict_types=1);

/**
 * Наполнение базы демонстрационной работой.
 *
 * Запуск:
 *   php database/seed_demo_test.php            — добавить, если ещё нет
 *   php database/seed_demo_test.php --force    — пересоздать демо-работу заново
 *
 * База выбирается так же, как в install_test_db.php: ключом --name= или
 * переменной MYEDU_DB_NAME, а по умолчанию — тестовая.
 *
 * По умолчанию именно тестовая, потому что засев стоит в середине документированной
 * последовательности прогона интеграционных наборов и человек запускает его,
 * не думая о базе. Скрипт при этом подключался к рабочей и переписывал в ней
 * демо-тест --force, то есть молча трогал данные, которых он не видел.
 *
 * Заданий шесть, по одному каждого типа, и лежат они в опубликованной работе.
 *
 * Раньше скрипт заводил один тест с шестью вопросами и оставлял его
 * опубликованным. Такая конструкция больше не показывает систему: ученик
 * получает работы, и отдельного теста он не видит вовсе. Работа из шести
 * заданий — это ровно то, что учитель собирает теперь, и демонстрация стала
 * правдивой.
 */

use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Database;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Скрипт запускается только из командной строки.\n");
}

$rootDir = dirname(__DIR__);

require $rootDir . '/src/Core/Autoloader.php';

MyEdu\Core\Autoloader::create($rootDir);

/*
 * База выбирается до загрузки приложения: перекрытие читает Config, а он
 * создаётся при загрузке. Знак равно обязателен, как и в install_test_db.php:
 * пробел молча проигнорировался бы, и засев ушёл бы в тестовую базу вместо
 * указанной.
 */
// Имя по умолчанию — та же тестовая база, что и у install_test_db.php.
// Прежнее имя my_edu_test в этом проекте уже не используется: скрипт
// подключался к базе, которой нет, и падал, либо — что хуже — писал
// демо-работу туда, куда никто не смотрит.
$dbName = (string) (getenv('MYEDU_DB_NAME') ?: 'my_edu_review_test');

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--name=')) {
        $dbName = substr($arg, 7);
    }
}

$dbName = trim($dbName);

if ($dbName === '') {
    fwrite(STDERR, "ОТКАЗ: не указано имя базы.\n");
    exit(2);
}

putenv('MYEDU_DB_NAME=' . $dbName);

$app = App::boot($rootDir);

// Пространства имён плагинов: демо-тест принадлежит плагину «Тест», и без
// его каталога в загрузчике скрипт не найдёт ни типы вопросов, ни проверку.
foreach (glob($rootDir . '/plugins/*/plugin.json') ?: [] as $manifest) {
    $data = json_decode((string) file_get_contents($manifest), true);

    if (!is_array($data) || ($data['namespace'] ?? '') === '') {
        continue;
    }

    $app->autoloader()->addNamespace(
        rtrim((string) $data['namespace'], '\\') . '\\',
        dirname($manifest) . '/src'
    );
}

/** @var Database $db */
$db = $app->get(Database::class);

/** @var UserRepository $users */
$users = $app->get(UserRepository::class);

$force = in_array('--force', $argv, true);

$currentDb = (string) $db->value('SELECT DATABASE()');

/*
 * Отказ для рабочей базы. --force переписывает демо-тест, а рабочая база
 * может содержать реальные данные; молчаливый --force здесь опаснее, чем
 * явный запуск с ключом, поэтому имя базы обязано содержать «test» либо
 * отключение приходится подтвердить переменной окружения.
 */
if (!str_contains(strtolower($currentDb), 'test') && getenv('MYEDU_ALLOW_DESTRUCTIVE') !== '1') {
    fwrite(
        STDERR,
        "ОТКАЗ: засев подключился к базе «{$currentDb}», а это не тестовая.\n"
        . "Укажите тестовую базу:  php database/seed_demo_test.php --name=my_edu_test\n"
        . "или рабочую осознанно:  MYEDU_ALLOW_DESTRUCTIVE=1 php database/seed_demo_test.php --name={$currentDb}\n"
    );
    exit(2);
}

echo "Подключение: " . $currentDb . "\n";

// --------------------------------------------------------------- предмет

// Кода у предмета нет (миграция 017): повторного запуска скрипта защищает
// выборка с лимитом — иначе ON DUPLICATE KEY UPDATE не сработал бы, потому что
// уникального индекса по названию тоже нет, и второй запуск создал бы дубль.
$existingSubjectId = (int) $db->value('SELECT id FROM subjects WHERE name = :name LIMIT 1', ['name' => 'Биология']);

if ($existingSubjectId > 0) {
    $subjectId = $existingSubjectId;
} else {
    $subjectId = $db->insert('subjects', [
        'name'       => 'Биология',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

echo "Предмет: Биология (id={$subjectId})\n";

// --------------------------------------------------------------- учитель

/*
 * Демонстрационный учитель. Владельцем сайта он не становится: владелец
 * появляется при первом подтверждении адреса. Учитель нужен только чтобы
 * у теста был автор, а у класса — преподаватель.
 *
 * Идёт через UserRepository, а не прямым INSERT: только репозиторий
 * держит составное ФИО и его части в согласованном состоянии. Сырой
 * INSERT заполнил бы full_name, а last_name/first_name/middle_name оставил
 * пустыми — и сортировка по фамилии поехала бы.
 *
 * Раньше здесь стоял INSERT ... ON DUPLICATE KEY UPDATE, и он молчал ровно
 * тогда, когда строка уже существовала с другим логином: подставлялся
 * пароль, а адрес оставался прежним, и поиск учителя по адресу не находил
 * ничего.
 */
$teacherEmail = 'teacher@example.edu';
$teacherHash = password_hash('12345678', PASSWORD_DEFAULT);
$teacherName = ['last_name' => 'Иванова', 'first_name' => 'Мария', 'middle_name' => 'Петровна'];

$existingTeacher = $db->one(
    'SELECT id FROM users WHERE email = :email OR login = :login LIMIT 1',
    ['email' => $teacherEmail, 'login' => 'teacher']
);

if ($existingTeacher === null) {
    $authorId = $users->create([
        'login'            => 'teacher',
        'email'            => $teacherEmail,
        'password_hash'    => $teacherHash,
        'full_name'        => 'Иванова Мария Петровна',
        'is_owner'         => 0,
        'status'           => 'active',
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);
} else {
    $authorId = (int) $existingTeacher['id'];

    $users->update($authorId, [
        'login'         => 'teacher',
        'email'         => $teacherEmail,
        'password_hash' => $teacherHash,
        'status'        => 'active',
        'full_name'     => 'Иванова Мария Петровна',
    ] + $teacherName);
}

if ($authorId === 0) {
    exit("Не удалось создать демонстрационного учителя.\n");
}

echo "Автор: id={$authorId}\n";

$db->run(
    "INSERT IGNORE INTO user_roles (user_id, role_code, granted_at)
     VALUES (:user, 'teacher', NOW())",
    ['user' => $authorId]
);

// --------------------------------------------------------------- работа

$demoTitle = 'Демонстрационная работа: наука о природе';

/*
 * Демо-рабора ищется по названию, а не по флагу в базе: иначе скрипт не смог
 * бы сказать «уже есть» и молча создал бы вторую такую же.
 */
if ($force) {
    /*
     * Ответы ученика в таблицах плагинов.
     *
     * У `test_item_attempts` и `variable_item_attempts` нет внешнего ключа на
     * попытку работы: плагин держит свои данные сам и об их удалении ядро не
     * знает. Каскад по удалению работы до них не доходит, и прошлые ответы
     * остаются лежать с номерами попыток, которых больше нет. При повторном
     * засеве новая попытка получает тот же номер, и запись об ответе падает по
     * уникальному ключу — скрипт валится на середине, оставив базу надвоеленной.
     *
     * Таблицы названы прямо: это данные двух штатных плагинов, и чистить их
     * должен тот, кто пересоздаёт демо-работу.
     */
    foreach (['test_item_attempts', 'variable_item_attempts'] as $pluginTable) {
        $known = (int) $db->value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t",
            ['t' => $pluginTable]
        );

        if ($known === 0) {
            continue;
        }

        $db->run(
            'DELETE t FROM ' . $pluginTable . ' t
               JOIN assignment_attempts a ON a.id = t.assignment_attempt_id
              WHERE a.assignment_id IN (SELECT id FROM assignments WHERE title = :t)',
            ['t' => $demoTitle]
        );
    }

    $db->run(
        /*
         * Выдачи демо-работы.
         *
         * Сначала строки выдачи, у которых нет работы: у
         * `assignment_issue_students` нет внешнего ключа ни на выдачу, ни на
         * работу, поэтому её удаление до них не доходит, а номер выдачи
         * освобождается и занимается следующей. Следующий засев вставляет
         * выдачу с тем же номером — и падает по первичному ключу, оставив базу
         * в худшем состоянии, чем до него. Проверяется именно наличие работы:
         * выдача, чья работа уже удалена, — тоже сирота.
         */
        'DELETE FROM assignment_issue_students
          WHERE issue_id NOT IN (
                SELECT i.id FROM assignment_issues i
                  JOIN assignments a ON a.id = i.assignment_id
          )',
        []
    );
    $db->run(
        'DELETE FROM assignment_issue_students
          WHERE issue_id IN (SELECT id FROM assignment_issues WHERE assignment_id IN
                (SELECT id FROM assignments WHERE title = :t))',
        ['t' => $demoTitle]
    );
    $db->run('DELETE FROM assignment_issues WHERE assignment_id IN (SELECT id FROM assignments WHERE title = :t)', ['t' => $demoTitle]);
    $db->run('DELETE FROM assignment_items WHERE assignment_id IN (SELECT id FROM assignments WHERE title = :t)', ['t' => $demoTitle]);
    $db->run('DELETE FROM assignments WHERE title = :t', ['t' => $demoTitle]);
    $db->run('DELETE FROM tests WHERE title = :t', ['t' => $demoTitle]);
}

$existing = $db->value('SELECT id FROM assignments WHERE title = :t', ['t' => $demoTitle]);

if ($existing !== null) {
    echo "Демо-работа уже существует (id={$existing}). Для пересоздания укажите --force.\n";
    exit(0);
}

$assignmentId = $db->insert('assignments', [
    'title'         => $demoTitle,
    'description'   => 'Проверьте, как работает система: здесь по одному заданию каждого типа.',
    'subject_id'    => $subjectId,
    // Параллель — только подпись для папки в дереве работ. Никому ничего не
    // адресует: ученик увидит работу, только если её ему выдали.
    'parallel'      => 8,
    'author_id'     => $authorId,
    'status'        => 'published',
    'grade_mode'    => 'weighted',
    'criteria_mode' => 'percent',
    'show_results'  => 1,
    'time_limit'    => 20,
    // Числа попыток у работы нет: ограничение принадлежит выдаче, и у демо она
    // не задана — попыток сколько угодно.
    'criteria'      => json_encode([
        ['grade' => 5, 'min_percent' => 85.0, 'label' => 'отлично'],
        ['grade' => 4, 'min_percent' => 70.0, 'label' => 'хорошо'],
        ['grade' => 3, 'min_percent' => 50.0, 'label' => 'удовлетворительно'],
        ['grade' => 2, 'min_percent' => 0.0,  'label' => 'неудовлетворительно'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at'  => date('Y-m-d H:i:s'),
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

echo "Работа создана: id={$assignmentId}\n";

// --------------------------------------------------------------- вопросы

/**
 * Структура описания вопроса повторяет ожидаемый формат редактора этапа 2:
 * config — настройки типа, options — элементы с kind и value_key.
 *
 * @var list<array{type:string,prompt:string,weight:float,required:bool,explanation:?string,config:array<string,mixed>,options:list<array<string,mixed>>}>
 */
$questions = [
    [
        'type'        => 'single_choice',
        'prompt'      => 'Какая планета Солнечной системы самая большая по размеру?',
        'weight'      => 1.0,
        'required'    => true,
        'explanation' => 'Юпитер — газовый гигант, его масса в 318 раз больше земной.',
        'config'      => ['correct' => 'b'],
        'options'     => [
            ['kind' => 'option', 'value_key' => 'a', 'text' => 'Земля', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'b', 'text' => 'Юпитер', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'c', 'text' => 'Сатурн', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'd', 'text' => 'Марс', 'pair_key' => null],
        ],
    ],
    [
        'type'        => 'multiple_choice',
        'prompt'      => 'Какие из перечисленных явлений относятся к погоде? Выберите все подходящие.',
        'weight'      => 1.5,
        'required'    => true,
        'explanation' => 'Погода — это состояние атмосферы. Вулканизм и землетрясения к погоде не относятся.',
        'config'      => ['correct' => ['a', 'c', 'e'], 'partial' => true, 'miss_penalty' => 0.5],
        'options'     => [
            ['kind' => 'option', 'value_key' => 'a', 'text' => 'Дождь', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'b', 'text' => 'Извержение вулкана', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'c', 'text' => 'Туман', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'd', 'text' => 'Землетрясение', 'pair_key' => null],
            ['kind' => 'option', 'value_key' => 'e', 'text' => 'Град', 'pair_key' => null],
        ],
    ],
    [
        'type'        => 'ordering',
        'prompt'      => 'Расположите стадии развития растения в правильном порядке: от посева плода до нового плода.',
        'weight'      => 1.5,
        'required'    => true,
        'explanation' => 'Порядок: семя → всходы → побег с листьями → цветение → плод с семенами.',
        // Ключи b, c, d, e, a — это «семя, всходы, побег, цветение, плод».
        // Показываемые ученику элементы всё равно перемешиваются сервером.
        'config'      => ['correct_order' => ['b', 'c', 'd', 'e', 'a'], 'partial' => 'positional'],
        'options'     => [
            ['kind' => 'item', 'value_key' => 'a', 'text' => 'Плод с семенами', 'pair_key' => null],
            ['kind' => 'item', 'value_key' => 'b', 'text' => 'Семя в почве', 'pair_key' => null],
            ['kind' => 'item', 'value_key' => 'c', 'text' => 'Всходы', 'pair_key' => null],
            ['kind' => 'item', 'value_key' => 'd', 'text' => 'Побег с листьями', 'pair_key' => null],
            ['kind' => 'item', 'value_key' => 'e', 'text' => 'Цветение', 'pair_key' => null],
        ],
    ],
    [
        'type'        => 'text',
        'prompt'      => 'Какие два вещества растение использует при фотосинтезе? Назовите их.',
        'weight'      => 2.0,
        'required'    => true,
        'explanation' => 'Для фотосинтеза нужны углекислый газ (из воздуха) и вода (из почвы), '
            . 'а свет — это не вещество, а источник энергии.',
        'config'      => [
            'strategy'           => 'keywords',
            'expected'           => 'Углекислый газ и вода',
            'required_keywords'  => ['углекислый газ', 'вода'],
            'forbidden_keywords' => ['кислород'],
            'ignore_case'        => true,
            'partial'            => true,
            'rows'               => 3,
        ],
        'options'     => [],
    ],
    [
        /*
         * Числовая задача с диапазоном: ответ округляется по ходу решения, и
         * ученик, принёсший 4,8 вместо 5, должен считаться правым.
         */
        'type'        => 'numeric',
        'prompt'      => 'В стакане 200 мл воды. Ученик вылил половину и долил 40 мл. '
            . 'Сколько миллилитров воды стало в стакане?',
        'weight'      => 1.5,
        'required'    => true,
        'explanation' => '200 − 100 + 40 = 140 мл. Диапазон задан с запасом на округление '
            . 'на промежуточных шагах.',
        'config'      => [
            'mode'      => 'range',
            'min'       => 139,
            'max'       => 141,
            'tolerance' => 0.5,
            'unit'      => 'мл',
        ],
        'options'     => [],
    ],
    [
        'type'        => 'matching',
        'prompt'      => 'Установите соответствие между процессом и его описанием.',
        'weight'      => 2.0,
        'required'    => true,
        'explanation' => 'Фотосинтез — образование органических веществ; дыхание — распад с выделением '
            . 'энергии; транспирация — испарение воды растением.',
        'config'      => ['pairs' => ['l1' => 'r2', 'l2' => 'r3', 'l3' => 'r1'], 'partial' => true],
        'options'     => [
            ['kind' => 'left',  'value_key' => 'l1', 'text' => 'Фотосинтез', 'pair_key' => null],
            ['kind' => 'left',  'value_key' => 'l2', 'text' => 'Дыхание', 'pair_key' => null],
            ['kind' => 'left',  'value_key' => 'l3', 'text' => 'Транспирация', 'pair_key' => null],
            ['kind' => 'right', 'value_key' => 'r1', 'text' => 'Испарение воды растением', 'pair_key' => 'l3'],
            ['kind' => 'right', 'value_key' => 'r2', 'text' => 'Образование органических веществ из неорганических с использованием света', 'pair_key' => 'l1'],
            ['kind' => 'right', 'value_key' => 'r3', 'text' => 'Распад веществ с выделением энергии', 'pair_key' => 'l2'],
        ],
    ],
];

$registry = QuestionTypeRegistry::instance();
$plugins = $app->get(PluginManager::class);
$test = $plugins->get('test');

if ($test === null) {
    fwrite(STDERR, "Плагин test выключен: демо-задания создать нечем.\n");
    exit(1);
}

$assignments = new AssignmentRepository($db);
$position = 0;

foreach ($questions as $spec) {
    $position++;

    /*
     * Задание создаёт плагин — тем же путём, каким его создаёт редактор
     * работы. Сид не вставляет строки в таблицы плагина: тот же способ, что и
     * у живого редактора, заодно служит проверкой того, что путь работает.
     */
    $taskTestId = $test->createTask($spec['type'], [
        'author_id'  => $authorId,
        'title'      => $demoTitle,
        'subject_id' => $subjectId,
    ]);

    $questionId = (int) $db->value(
        'SELECT id FROM questions WHERE test_id = :t ORDER BY position LIMIT 1',
        ['t' => $taskTestId]
    );

    if ($questionId === 0) {
        fwrite(STDERR, "Плагин не создал заготовку вопроса для {$spec['type']}.\n");
        exit(1);
    }

    $options = $spec['options'];

    // pair_key денормализован из config.pairs — редактору этапа 2 он нужен,
    // чтобы не собирать соответствие из JSON при каждом изменении строки.
    if ($spec['type'] === 'matching') {
        $options = syncPairKeys($options, $spec['config']['pairs'] ?? []);
    }

    // Проверяем целостность вопроса тем же валидатором, что использует
    // редактор: опечатка в данных должна ронять сид сразу, а не всплывать
    // позже, когда демо-работу уже показывали ученику.
    $registry->get($spec['type'])->validateConfig($spec['config'], $options);

    $db->update('questions', [
        'prompt'      => $spec['prompt'],
        'type'        => $spec['type'],
        'weight'      => $spec['weight'],
        'is_required' => $spec['required'] ? 1 : 0,
        'explanation' => $spec['explanation'],
        'config'      => json_encode($spec['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['id' => $questionId]);

    // Заготовка плагина заменяется целиком: её пустые варианты иначе остались
    // бы в вопросе рядом с настоящими и показывались ученику пустышками.
    $db->run('DELETE FROM question_options WHERE question_id = :q', ['q' => $questionId]);

    $optionPosition = 0;

    foreach ($options as $option) {
        $db->insert('question_options', [
            'question_id' => $questionId,
            'kind'        => $option['kind'],
            'value_key'   => $option['value_key'],
            'text'        => $option['text'],
            'position'    => $optionPosition++,
            'pair_key'    => $option['pair_key'] ?? null,
        ]);
    }

    // Задание публикуется: черновик ученику не показывается, а работа с
    // черновиком внутри выглядит сломанной — половина заданий открывается,
    // половина отвечает «тест не опубликован».
    $db->update('tests', [
        'status'           => 'published',
        'shuffle_options'  => 1,
        'title'            => mb_substr($spec['prompt'], 0, 120, 'UTF-8'),
    ], ['id' => $taskTestId]);

    $check = new Question(
        id: $questionId,
        type: $spec['type'],
        prompt: $spec['prompt'],
        weight: $spec['weight'],
        isRequired: $spec['required'],
        explanation: $spec['explanation'],
        config: $spec['config'],
        options: $options
    );

    $assignments->addItem(
        $assignmentId,
        new TaskRef('test', $spec['type'], $taskTestId),
        1.0,
        $check->maxScore()
    );

    printf(
        "  Задание %d: %-15s %d элем., %.1f б. — %s\n",
        $position,
        $spec['type'],
        count($options),
        $check->maxScore(),
        mb_substr($spec['prompt'], 0, 48, 'UTF-8') . '…'
    );
}

$maxScore = (float) $db->value(
    'SELECT COALESCE(SUM(max_score), 0) FROM assignment_items WHERE assignment_id = :id',
    ['id' => $assignmentId]
);

// --------------------------------------------------------------- выдача

/*
 * Демо-работа выдаётся всем активным ученикам с их классом.
 *
 * Опубликованной работы недостаточно: ученик видит только то, что ему лично
 * выдали, и пока выдачи нет, демо выглядит сломанной — на /works пусто, а
 * автор на /panel/assignments видит работу с нулём выданных.
 */
$demoIssueId = $db->insert('assignment_issues', [
    'assignment_id'   => $assignmentId,
    'author_id'       => $authorId,
    // 0 — попыток сколько угодно: демо показывают, а не ограничивают.
    'max_attempts'    => 0,
    // NULL вместо границ — «всегда»: демо не должна молча исчезнуть к пятнице.
    'available_from'  => null,
    'available_until' => null,
    'created_at'      => date('Y-m-d H:i:s'),
]);

/*
 * Кому выдаём.
 *
 * Берётся по парам «ученик — класс», а первичный ключ у строки выдачи — пара
 * «выдача — ученик». Ученик, состоящий в двух классах, в списке пар встречается
 * дважды, и вторая вставка падает по ключу: засев обрывался на середине, и
 * повторить его было нельзя, пока строка-дубль не убрана руками.
 *
 * Поэтому ученик выдаётся один раз — в том классе, который попался первым.
 * Класс здесь — подпись, а не адресат: работа выдаётся человеку, а не классу.
 */
$demoStudents = [];
$seenStudents = [];

foreach ($db->all(
    'SELECT cs.student_id, cs.class_id
       FROM class_students cs
       JOIN users u ON u.id = cs.student_id
      WHERE u.status = \'active\'
      ORDER BY cs.student_id, cs.class_id'
) as $pair) {
    $studentId = (int) $pair['student_id'];

    if (isset($seenStudents[$studentId])) {
        continue;
    }

    $seenStudents[$studentId] = true;
    $demoStudents[] = $pair;
}

$now = date('Y-m-d H:i:s');

foreach ($demoStudents as $demoStudent) {
    $db->run(
        'INSERT INTO assignment_issue_students (issue_id, student_id, class_id, assigned_at)'
        . ' VALUES (:i, :s, :c, :t)',
        [
            'i' => $demoIssueId,
            's' => (int) $demoStudent['student_id'],
            'c' => (int) $demoStudent['class_id'],
            't' => $now,
        ]
    );
}

echo "\nГотово. Работа id={$assignmentId}, заданий: {$position}, сумма баллов: {$maxScore}\n";
echo "Выдана " . count($demoStudents) . " ученикам\n";
echo "Откройте в браузере: /works\n";

/**
 * Проставляет pair_key правой колонке по config.pairs.
 *
 * @param list<array<string,mixed>> $options
 * @param array<string,string>      $pairs
 * @return list<array<string,mixed>>
 */
function syncPairKeys(array $options, array $pairs): array
{
    $byRight = [];

    foreach ($pairs as $left => $right) {
        $byRight[(string) $right] = (string) $left;
    }

    foreach ($options as $i => $option) {
        if (($option['kind'] ?? '') === 'right') {
            $options[$i]['pair_key'] = $byRight[(string) $option['value_key']] ?? null;
        }
    }

    return $options;
}