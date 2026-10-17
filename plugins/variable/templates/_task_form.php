<?php

declare(strict_types=1);

use MyEdu\Ui\Ui;
use MyEduPlugin\Variable\Http\TaskEditorController;
use MyEduPlugin\Variable\ProblemConfig;
use MyEduPlugin\Variable\VariableGenerator;

/**
 * Форма редактора вариативной задачи.
 *
 * Отдельный файл, потому что рисуется в двух местах: на своей странице и
 * карточкой в редакторе работы. Копия формы означала бы две версии правил, и
 * они разошлись бы при первой правке.
 *
 * Вся разметка — хуками ядра (`$ui`), как и у плагина с вопросами: поля,
 * редактор, сообщения, строка переменной. Разница в том, что здесь ещё есть
 * скрипт плагина: `variable-task.js` строит строки переменных по условию, и он
 * не подключается оболочкой.
 *
 * Строка переменной рисуется сервером и кладётся в `<template>`: скрипт её
 * копирует. Собственная разметка строки в скрипте выглядела иначе, чем
 * отрисованная сервером, — первая добавленная переменная отличалась от
 * остальных, а человек это видит сразу.
 *
 * Ожидает:
 * $view, $ui, $task, $backUrl, $prefix, $closeUrl, $itemId, $maxScore,
 * $assignmentPublished
 *
 * @var \MyEdu\Core\View $view
 * @var Ui $ui
 * @var array<string,mixed> $task
 * @var string $backUrl
 * @var string $prefix
 */
$taskId = (int) $task['id'];
$config = is_array($task['config'] ?? null) ? $task['config'] : [];
$prompt = (string) ($task['prompt'] ?? '');

/*
 * Строки таблицы берутся из условия, а не из прошлой настройки.
 *
 * Автор пишет условие и получает переменные оттуда: в старой форме приходилось
 * сначала придумать имена, потом отдельно объявлять их списком, и любая опечатка
 * в условии тихо оставляла пример с пустым местом вместо числа.
 *
 * Порядок строк сохраняется: иначе после сохранения условие и настройка
 * разъехались бы по разным спискам.
 */
$names = VariableGenerator::namesIn((string) $view->plain($prompt));
$declared = [];

foreach (ProblemConfig::variables($config) as $variable) {
    $declared[(string) $variable['name']] = $variable;
}

$rows = [];

foreach ($names as $name) {
    $rows[] = $declared[$name] ?? [
        'name' => $name,
        'mode' => VariableGenerator::MODE_LIST,
        'values' => '',
        'min' => '',
        'max' => '',
        'step' => '1',
        'formula' => '',
    ];
    unset($declared[$name]);
}

/*
 * Объявленные, но не упомянутые в условии переменные не выбрасываем: автор мог
 * удалить её из текста и ещё не сохранить, и молчаливая потеря настройки была
 * бы хуже лишней строки. Проверка на сохранении объяснит, что с ней не так.
 */
foreach ($declared as $orphan) {
    $rows[] = $orphan;
}

/**
 * Строка таблицы переменных. Один и тот же код — и для строк на странице, и
 * для образца в `<template>`, из которого скрипт берёт новые.
 */
$variableRow = static function (array $variable, bool $empty = false) use ($ui, $view): string {
    $variable = is_array($variable) ? $variable : [];
    $name = (string) ($variable['name'] ?? '');
    $key = TaskEditorController::FIELD_PREFIX . 'N'; // N — номер строки, его проставит скрипт
    $isList = ((string) ($variable['mode'] ?? VariableGenerator::MODE_LIST)) !== VariableGenerator::MODE_RANGE;
    $modes = ['list' => 'Перечислением', 'range' => 'Диапазон'];

    $mode = '';

    foreach ($modes as $value => $label) {
        $mode .= $ui->radio($key . '[mode]', [
            'label'   => $label,
            'value'   => $value,
            'checked' => $value === ($isList ? 'list' : 'range'),
            'id'      => $key . '-mode-' . $value,
        ]);
    }

    $values = $empty ? '' : (string) ($variable['values'] ?? '');
    $min = $empty ? '' : (string) ($variable['min'] ?? '');
    $max = $empty ? '' : (string) ($variable['max'] ?? '');
    $step = $empty ? '1' : (string) ($variable['step'] ?? '1');

    return '<tr class="generated-var" data-generated-row data-mode="' . ($isList ? 'list' : 'range') . '">'
        . '<td class="generated-table__name"><code>{' . $view->e($name) . '}</code>'
        . $ui->hidden($key . '[name]', $name)
        . '</td>'
        . '<td class="generated-table__mode">' . $mode . '</td>'
        . '<td class="generated-table__values">'
        /*
         * Оба набора полей в разметке, показывается нужный: без скриптов
         * форма остаётся рабочей — просто видны лишние поля.
         */
        . $ui->field($key . '[values]', [
            'value'        => $values,
            'maxlength'    => 200,
            'placeholder'  => '3, 5, 8',
            'id'           => $key . '-values',
            'wrapperAttrs' => ['data-when' => 'list'],
        ])
        . '<div class="split" data-when="range"' . ($isList ? ' hidden' : '') . '>'
        . $ui->field($key . '[min]', ['value' => $min, 'maxlength' => 16, 'size' => 4, 'placeholder' => 'от 1', 'id' => $key . '-min', 'label' => 'Нижняя граница'])
        . $ui->field($key . '[max]', ['value' => $max, 'maxlength' => 16, 'size' => 4, 'placeholder' => 'до 9', 'id' => $key . '-max', 'label' => 'Верхняя граница'])
        . $ui->field($key . '[step]', ['value' => $step, 'maxlength' => 16, 'size' => 4, 'placeholder' => 'шаг 1', 'id' => $key . '-step', 'label' => 'Шаг'])
        . '</div>'
        . '</td>'
        /*
         * Столбец-подсказка: формула одна на всю задачу, в строке ей нечего
         * показывать. Сам текст формулы — под таблицей, на всю ширину: там он
         * читается, а не теряется в ячейке.
         */
        . '<td class="generated-table__formula"><span class="muted">'
        . ($isList ? 'перечисление' : 'диапазон')
        . '</span></td>'
        . '</tr>';
};
?>
<div class="card">
  <?php /*
   Предупреждение об опубликованной работе показывает ядро — значком в шапке
   окна редактора. Здесь стоял бы второй копией того же текста: при открытии из
   доски значок уже на месте, а эта полоса отодвигала бы форму вниз ещё на
   пол-экрана.
   */ ?>

 <form method="post" action="<?= $view->e($view->url($prefix . '/task/' . $taskId)) ?>"
 data-ajax-save>
 <?= $view->raw($view->csrfField()) ?>
 <?= $view->raw($ui->hidden('action', 'save_task')) ?>
 <?= $view->raw($ui->hidden('back', $backUrl)) ?><?php /*
 back в форме, а не только в адресе: без него сохранение отправляло бы
 автора обратно на редактор задания вместо списка заданий работы.
*/ ?>

<?php /*
Тот же визуальный редактор, что и в остальных заданиях: рисует его ядро.
 Раньше здесь стоял обычный textarea, и условие выглядело беднее соседних
 заданий плюс не позволяло вставить картинку — а схема с рисунком и
 подписями для математики обычна.
 */ ?>
 <?= $view->raw($ui->editor('prompt', [
     'label'       => 'Условие',
     'value'       => $prompt,
     'placeholder' => 'Что именно спрашиваете у ученика',
     'rows'        => 4,
 ])) ?>

 <p class="form-help">
 В условии вместо чисел пишите переменные в фигурных скобках:
 например, «{a}» или «{speed}». Каждой переменной укажите допустимые
 значения и формулу, по которой считается её ответ.
 </p>

 <p class="form-help">
 В фигурных скобках пишется то место, где должно стоять число: на место
 «{a}» ученик увидит выпавшее значение. Поэтому вопрос «Найдите {a}»
 превратится в «Найдите 9» — и искать будет нечего. Вопрос задавайте
 словами: «Найдите значения a и b».
 </p>

<?php /*
 Таблица переменных.

 Строки строятся по именам, найденным в условии: автор пишет «{a}» в тексте, и
 строка появляется сама. В старой форме приходилось сначала придумать имена,
 потом отдельно объявить их списком — и опечатка в условии тихо оставляла
 пример с местом вместо числа.

 Способ задания выбирается переключателем, и третьим столбцом сразу видно, что
 именно требуется: перечисление с запятой или границы и шаг. Один столбец на
 оба способа означал бы, что половина полей всегда пустует зря.
*/ ?>
 <div class="generated-vars"
 data-generated-vars
 data-generated-prefix="<?= $view->e(TaskEditorController::FIELD_PREFIX) ?>">

 <?php if ($rows === []): ?>
 <p class="form-help" data-vars-empty>
 Переменных пока нет. Впишите их в условие в фигурных скобках —
 например, «{a}», — и строки появятся здесь сами.
 </p>
 <?php endif; ?>

 <table class="table generated-table">
 <thead>
 <tr>
 <th scope="col">Переменная</th>
 <th scope="col">Как задать значения</th>
 <th scope="col">Допустимые значения</th>
 <th scope="col">Как заданы значения</th>
 </tr>
 </thead>

 <tbody>
 <?php foreach ($rows as $index => $variable): ?>
 <?= $view->raw($variableRow(
     is_array($variable) ? $variable : [],
     false
 )) ?>
 <?php endforeach; ?>
 </tbody>
 </table>

 <p class="form-help">
 Переменных не больше <?= (int) ProblemConfig::MAX_VARIABLES ?>.
 </p>
 </div>

<?= $view->raw($ui->field('formula', [
    'label'       => 'Формула ответа',
    'value'       => ProblemConfig::formula($config),
    'maxlength'   => 200,
    'placeholder' => 'a * b - 4',
    'help'        => 'Одна формула на всю задачу. В ней используются те же имена, что и в условии, '
        . 'и при проверке на место каждого имени подставляется то же число, которое видел ученик. '
        . 'Действия: + − * / % и скобки. Ученику формула не показывается.',
])) ?>

 <div class="split">
 <?= $view->raw($ui->field('tolerance', [
     'label' => 'Допуск к ответу',
     'type'  => 'number',
     'value' => ProblemConfig::tolerance($config),
     'min'   => '0',
     'max'   => '1',
     'step'  => '0.01',
     'size'  => 6,
     'help'  => 'На столько ответ может отличаться и всё равно засчитаться. Полезно для дробных результатов: 0,01.',
 ])) ?>

 <?= $view->raw($ui->field('unit', [
     'label'     => 'Единица измерения',
     'value'     => ProblemConfig::unit($config),
     'maxlength' => 16,
 ])) ?>

 <?php if ($itemId !== null): ?>
 <?php /*
 Цена задания — рядом с допуском и единицей, а не в списке позиций работы.
 В списке стояла одна строка на все плагины сразу, и вариативной задаче места
 для цены там не было: поле убрали как дубль, а задать цену оказалось нечем.
 Рядом с настройками проверки её и место — тут автор правит ответ, а не список
 позиций.

 Показывается только у задания, уже лежащего в работе: у создаваемого позиции
 ещё нет, и цены — тоже.
 */ ?>
 <?= $view->raw($ui->field('max_score', [
     'label' => 'Цена задания, баллов',
     'type'  => 'number',
     'value' => $maxScore === null ? '' : $maxScore,
     'min'   => '0',
     'max'   => '100000',
     'step'  => '0.01',
     'size'  => 6,
     'help'  => 'Из цен всех заданий складывается сумма, от которой отсчитываются диапазоны '
         . 'оценок на шаге «Результат». Пустое поле — цена не задана.',
 ])) ?>

 <?= $view->raw($ui->hidden('item', (int) $itemId)) ?>
 <?php endif; ?>
 </div>

 <?php /*
 Одна кнопка сохранения, как и у задания с вопросами: в редакторе ровно одно
 действие, которое что-то меняет. Публикуется работа целиком последним шагом
 мастера, и отдельная кнопка публикации задания решала не то, зачем открыт
 редактор.

 «Отмена» рядом с ней — закрыть без сохранения. Ссылка, а не submit: форма на
 неё не уходит никогда, поэтому потерять правку нельзя даже там, где скрипты
 запрещены, — скрипт лишь спрашивает подтверждение.
 */ ?>
 <div class="page-actions">
 <?= $view->raw($ui->button('Сохранить задание', ['size' => 'btn-lg'])) ?>

 <a class="btn btn-ghost btn-lg"
 href="<?= $view->e($view->url($closeUrl !== '' ? $closeUrl : $backUrl)) ?>"
 data-editor-cancel
 data-editor-cancel-message="Изменения не будут сохранены. Закрыть редактор?">Отмена</a>
 </div>

<?php /*
 Образец строки для скрипта.

 Скрипт копирует её и подставляет имя переменной, поэтому разметка новой строки
 та же, что и нарисованной сервером. Своя разметка в скрипте выглядела иначе:
 добавленная переменная отличалась от остальных, и это видно сразу.
*/ ?>
 <template data-generated-row-template><?= $view->raw($variableRow([], true)) ?></template>
 </form>
</div>

<?php /*
 Здесь подключаются и Quill, и скрипты плагина.

 Форма рисуется карточкой в редакторе работы, и оболочки страницы у неё нет —
 вкладывать скрипты в обёртку было бы некуда.

 editor.js обязателен и не заменяется variable-task.js: именно он создаёт
 визуальный редактор условия и перед отправкой переносит текст в скрытое поле.
 Без него условие оставалось серым прямоугольником, в который нельзя было
 ввести ни одной буквы, — при том, что библиотека Quill была подключена.
 Порядок важен: editor.js сначала кладёт в поле разметку, variable-task.js затем
 строит по ней таблицу переменных.
*/ ?>
<?= $view->raw($ui->editorScripts()) ?>
<script src="<?= $view->e($view->asset('assets/js/variable-task.js')) ?>" defer></script>
