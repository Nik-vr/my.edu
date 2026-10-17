<?php

declare(strict_types=1);

use MyEdu\Ui\Ui;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\Types\NumericType;
use MyEduPlugin\Test\Question\Types\OrderingType;
use MyEduPlugin\Test\Question\Types\TextAnswerType;

/**
 * Поля вопроса.
 *
 * Отдельный файл, потому что набор полей задаётся видом вопроса, а сам вид
 * выбирается один раз — при добавлении задания в работу. Дублировать эти
 * поля в редакторе задания было бы прямой дорогой к двум копиям правил,
 * которые разойдутся при первой правке.
 *
 * Разметка целиком рисуется хуками ядра (`$ui`): поля, редактор, группы,
 * список вариантов. Плагин не должен собирать форму сам — иначе одинаковое
 * поле в плагине и в ядре разойдётся при первом изменении оформления.
 * Одинаковость проверяется набором `run_work_tests`: разметка поля из
 * редактора плагина сверяется с такой же полем в форме ядра.
 *
 * Ожидает: $view, $form, $ui.
 *
 * @var \MyEdu\Core\View $view
 * @var array<string,mixed> $form
 * @var Ui $ui
 */

/** Тексты вариантов для правки: подставляются обратно в форму. */
$optionTexts = static function (?array $form): array {
    if ($form === null) {
        return [];
    }

    $kind = match ((string) $form['type']) {
        'ordering' => Question::KIND_ITEM,
        default => Question::KIND_OPTION,
    };

    $rows = [];

    foreach (($form['options'][$kind] ?? []) as $option) {
        $rows[] = $option['text'];
    }

    return $rows;
};

/** Значения элементов: по ним сопоставляются позиции в форме. */
$optionKeys = static function (?array $form): array {
    if ($form === null) {
        return [];
    }

    $kind = match ((string) $form['type']) {
        'ordering' => Question::KIND_ITEM,
        default => Question::KIND_OPTION,
    };

    return array_map(
        static fn (array $o): string => (string) $o['value'],
        $form['options'][$kind] ?? []
    );
};

$texts = $optionTexts($form);
$keys = $optionKeys($form);
$correct = array_map('strval', (array) $form['correct']);
$config = (array) $form['config'];
$formType = (string) $form['type'];

/*
 * Рисуется только блок текущего вида задания.
 *
 * Раньше в форме были все шесть блоков, а лишние прятал скрипт. Это не
 * работало: скрытие — это стили, а браузер отправляет и скрытые поля. Каждая
 * форма выбора рисовала одни и те же `option_text[]`, и при сохранении варианты
 * приходили дважды — по одному разу из одиночного и из множественного выбора.
 * Четыре варианта превращались в восемь, восемь — в шестнадцать, и вопрос
 * разрастался с каждой правкой.
 *
 * Вид задания выбирается один раз — при добавлении, — и на форме его нет, так
 * что рисовать остальные блоки незачем: скрывать нечего, а в запросе они были
 * лишними.
 *
 * Правильные варианты показываются только в том блоке, к которому относятся, и
 * имя поля у каждого блока своё: `correct_single` и `correct_multi`. Раньше
 * отметка подставлялась в оба блока, и у одиночного вопроса в скрытом блоке
 * множественного выбора оказывалась включённая галочка, которую браузер слал
 * вместе с кружком.
 */
$checkedIndexes = static function (array $keys, array $correct): array {
    $out = [];

    foreach ($keys as $index => $key) {
        if ($key !== null && in_array((string) $key, $correct, true)) {
            $out[] = (int) $index;
        }
    }

    return $out;
};

/*
 * Сколько строк вариантов показывать в новом вопросе.
 *
 * Три, а не восемь: пустые строки занимают место и тянут взгляд вниз по
 * странице, а большинству вопросов трёх хватает. Кому нужно больше —
 * добавляет кнопкой, и это видно по нажатой кнопке, а не по
 * предчувствию, что «где-то ещё осталось место».
 */
$optionRows = max(3, count($texts));
?>
<?= $view->raw($ui->editor('prompt', [
      'label'       => 'Текст задания',
      'value'       => $form['prompt'],
      'placeholder' => 'Что именно спрашиваете у ученика',
    'rows'        => 4,
])) ?>

<div class="split">
 <?= $view->raw($ui->field('weight', [
     'label' => 'Балл за вопрос',
     'type'  => 'number',
     'value' => $form['weight'],
     'min'   => '0.01',
     'max'   => '999.99',
     'step'  => '0.01',
     'size'  => 6,
 ])) ?>

 <?= $view->raw($ui->checkbox('is_required', [
     'label'   => 'Ответ обязателен',
     'checked' => (bool) $form['required'],
 ])) ?>
</div>

<?= $view->raw($ui->textarea('explanation', [
    'label'       => 'Пояснение после проверки',
    'value'       => $form['explanation'],
    'rows'        => 2,
    'placeholder' => 'Показывается в разборе: почему такой ответ верный',
])) ?>

<?php if ($formType === 'single_choice'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Варианты ответа и правильный', 'attrs' => ['data-type' => 'single_choice']],
    $ui->optionRows($texts, [
        'name'    => 'option_text[]',
        'markName' => 'correct_single',
        'kind'    => 'single',
        'minimum' => $optionRows,
        'checked' => $checkedIndexes($keys, $correct),
        'help'    => 'Отметьте переключателем правильный вариант справа. Нужно минимум два '
            . 'заполненных варианта — остальные добавьте кнопкой ниже.',
    ])
)) ?>
<?php endif; ?>

<?php if ($formType === 'multiple_choice'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Варианты ответа и правильные', 'attrs' => ['data-type' => 'multiple_choice']],
    $ui->optionRows($texts, [
        'name'    => 'option_text[]',
        'markName' => 'correct_multi',
        'kind'    => 'multiple',
        'minimum' => $optionRows,
        'checked' => $checkedIndexes($keys, $correct),
    ])
)) ?>

<?= $view->raw($ui->checkbox('partial', [
    'label'   => 'Ставить частичный балл за неполный ответ',
    'checked' => (bool) ($config['partial'] ?? true),
])) ?>

<?= $view->raw($ui->field('miss_penalty', [
    'label' => 'Штраф за лишний вариант',
    'type'  => 'number',
    'value' => $config['miss_penalty'] ?? 0.5,
    'min'   => '0',
    'max'   => '1',
    'step'  => '0.05',
    'size'  => 5,
    'help'  => 'Доля вопроса, снимаемая за каждый лишний отмеченный вариант. 0 — не снимать.',
])) ?>

<p class="form-help">
 Правильных вариантов должно быть минимум два, иначе это уже одиночный выбор.
</p>
<?php endif; ?>

<?php if ($formType === 'ordering'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Элементы и верный порядок', 'attrs' => ['data-type' => 'ordering']],
    (static function () use ($ui, $keys, $texts, $correct, $config): string {
        /*
         * У каждого элемента укаживается его место в верном порядке: первый
         * шаг — 1, второй — 2 и так далее.
         */
        $order = [];

        foreach ($correct as $index => $value) {
            $order[(string) $value] = $index + 1;
        }

        $rows = '';

        for ($i = 0; $i < 8; $i++) {
            $key = $keys[$i] ?? null;

            if ($key === null && ($texts[$i] ?? '') === '') {
                continue;
            }

            $rows .= '<div class="page-actions">'
                . $ui->field('option_text[]', [
                    'label'       => 'Элемент',
                    'value'       => $texts[$i] ?? '',
                    'placeholder' => 'Шаг ' . ($i + 1),
                    'id'          => 'order-item-' . $i,
                ])
                . $ui->field('order_pos[]', [
                    'label' => 'Место',
                    'type'  => 'number',
                    'value' => $order[(string) ($key ?? '')] ?? '',
                    'min'   => '1',
                    'max'   => '8',
                    'step'  => '1',
                    'size'  => 3,
                    'id'    => 'order-pos-' . $i,
                ])
                . '</div>';
        }

        return $rows . $ui->select('partial', array_map(
            static fn (string $mode): array => [
                'value' => $mode,
                'label' => OrderingType::partialModeTitle($mode),
            ],
            OrderingType::partialModes()
        ), [
            'label' => 'Частичный балл',
            'value' => $config['partial'] ?? 'positional',
            'help'  => 'Что считать частичным баллом, если порядок собран не полностью.',
        ]);
    })()
)) ?>
<?php endif; ?>

<?php if ($formType === 'numeric'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Числовой ответ', 'attrs' => ['data-type' => 'numeric']],
    (static function () use ($ui, $config): string {
        return $ui->select('mode', array_map(
            static fn (string $mode): array => [
                'value' => $mode,
                'label' => NumericType::modeTitle($mode),
            ],
            NumericType::modes()
        ), ['label' => 'Как проверять', 'value' => $config['mode'] ?? NumericType::MODE_EXACT])
        . $ui->field('expected', [
            'label'     => 'Правильное число',
            'value'     => $config['expected'] ?? '',
            'inputmode' => 'decimal',
            'id'        => 'numeric-expected',
            'wrapperAttrs' => ['data-when' => 'exact'],
        ])
        . '<div class="split" data-when="range">'
        . $ui->field('min', ['label' => 'От', 'value' => $config['min'] ?? '', 'inputmode' => 'decimal', 'id' => 'numeric-min'])
        . $ui->field('max', ['label' => 'До', 'value' => $config['max'] ?? '', 'inputmode' => 'decimal', 'id' => 'numeric-max'])
        . '</div>'
        . '<div class="split">'
        . $ui->field('tolerance', [
            'label'     => 'Допуск',
            'value'     => $config['tolerance'] ?? 0.01,
            'inputmode' => 'decimal',
            'size'      => 6,
            'help'      => 'Например 0,01 для двух знаков после запятой.',
        ])
        . $ui->field('unit', ['label' => 'Единица измерения', 'value' => $config['unit'] ?? '', 'maxlength' => 16])
        . $ui->checkbox('integer', ['label' => 'Только целое число', 'checked' => (bool) ($config['integer'] ?? true)])
        . '</div>';
    })()
)) ?>
<?php endif; ?>

<?php if ($formType === 'text'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Текстовый ответ', 'attrs' => ['data-type' => 'text']],
    (static function () use ($ui, $config): string {
        return $ui->select('strategy', array_map(
            static fn (string $strategy): array => [
                'value' => $strategy,
                'label' => TextAnswerType::strategyTitle($strategy),
            ],
            TextAnswerType::strategies()
        ), ['label' => 'Как проверять', 'value' => $config['strategy'] ?? TextAnswerType::STRATEGY_KEYWORDS])
        . $ui->field('required_keywords', [
            'label'        => 'Ключевые слова через запятую',
            'value'        => implode(', ', (array) ($config['required_keywords'] ?? [])),
            'id'           => 'text-required-keywords',
            'wrapperAttrs' => ['data-when' => 'keywords'],
        ])
        . $ui->field('forbidden_keywords', [
            'label'        => 'Запрещённые слова через запятую',
            'value'        => implode(', ', (array) ($config['forbidden_keywords'] ?? [])),
            'help'         => 'Если ученик напишет такое слово, балл снимается.',
            'id'           => 'text-forbidden-keywords',
            'wrapperAttrs' => ['data-when' => 'keywords'],
        ])
        . $ui->field('expected', [
            'label'        => 'Ожидаемый ответ',
            'value'        => $config['expected'] ?? '',
            'id'           => 'text-expected',
            'wrapperAttrs' => ['data-when' => 'exact contains'],
        ])
        . $ui->textarea('variants[]', [
            'label'        => 'Другие допустимые ответы, по одному в строке',
            'value'        => implode("\n", (array) ($config['variants'] ?? [])),
            'rows'         => 3,
            'id'           => 'text-variants',
            'wrapperAttrs' => ['data-when' => 'exact'],
        ])
        . '<div data-when="keywords exact contains">'
        . $ui->checkbox('partial', ['label' => 'Ставить частичный балл за неполный ответ', 'checked' => (bool) ($config['partial'] ?? true), 'id' => 'text-partial'])
        . $ui->checkbox('ignore_case', ['label' => 'Не учитывать регистр', 'checked' => (bool) ($config['ignore_case'] ?? true), 'id' => 'text-ignore-case'])
        . $ui->checkbox('ignore_order', ['label' => 'Не учитывать порядок ключевых слов', 'checked' => (bool) ($config['ignore_order'] ?? false), 'id' => 'text-ignore-order'])
        . '</div>'
        . '<div class="split">'
        . $ui->field('rows', ['label' => 'Строк в поле ответа', 'type' => 'number', 'value' => $config['rows'] ?? 3, 'min' => '1', 'max' => '20', 'size' => 3, 'id' => 'text-rows'])
        . $ui->field('min_length', ['label' => 'Минимум символов', 'type' => 'number', 'value' => $config['min_length'] ?? 0, 'min' => '0', 'size' => 5, 'id' => 'text-min-length'])
        . $ui->field('max_length', [
            'label' => 'Максимум символов',
            'type'  => 'number',
            'value' => $config['max_length'] ?? 0,
            'min'   => '0',
            'size'  => 5,
            'help'  => '0 — без ограничения.',
            'id'    => 'text-max-length',
        ])
        . '</div>';
    })()
)) ?>

<p class="form-help">
 При проверке учителем ключевые слова и эталонный ответ не нужны: оценивание
 целиком делает учитель.
</p>
<?php endif; ?>

<?php if ($formType === 'matching'): ?>
<?= $view->raw($ui->group(
    ['label' => 'Соответствие понятий и определений', 'attrs' => ['data-type' => 'matching']],
    (static function () use ($ui, $form, $config): string {
        $lefts = $form['options'][Question::KIND_LEFT] ?? [];
        $rights = $form['options'][Question::KIND_RIGHT] ?? [];
        $pairs = (array) ($config['pairs'] ?? []);
        $rows = '';

        for ($i = 0; $i < 6; $i++) {
            $leftKey = 'l' . ($i + 1);
            $rightText = '';

            foreach ($rights as $right) {
                if ((string) ($pairs[$leftKey] ?? '') === (string) $right['value']) {
                    $rightText = (string) $right['text'];
                    break;
                }
            }

            $rows .= '<div class="page-actions">'
                . $ui->field('left_text[]', [
                    'label' => 'Понятие',
                    'value' => $lefts[$i]['text'] ?? '',
                    'id'    => 'match-left-' . $i,
                ])
                . $ui->field('right_text[]', [
                    'label' => 'Определение',
                    'value' => $rightText,
                    'id'    => 'match-right-' . $i,
                ])
                . $ui->checkbox('correct_pair[]', [
                    'label'   => 'верная пара',
                    'value'   => $leftKey,
                    'checked' => array_key_exists($leftKey, $pairs),
                    'id'      => 'match-pair-' . $i,
                ])
                . '</div>';
        }

        return $rows . $ui->checkbox('partial', [
            'label'   => 'Ставить частичный балл за часть верных пар',
            'checked' => (bool) ($config['partial'] ?? true),
            'id'      => 'matching-partial',
        ]);
    })()
)) ?>

<p class="form-help">
 В каждой строке — понятие слева и его определение справа. Отметьте верные пары:
 обычно это все строки, если порядок не важен.
</p>
<?php endif; ?>
