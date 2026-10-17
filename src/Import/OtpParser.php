<?php

declare(strict_types=1);

namespace MyEdu\Import;

use MyEdu\Core\Html;

/**
 * Разбор теста OnlineTestPad в то, что понимает плагин «Тест».
 *
 * Каждое задание исходного теста становится отдельным заданием работы:
 * вид задания в системе и есть тип вопроса, поэтому «тест из девяти вопросов»
 * приезжает девятью позициями, а не одной.
 *
 * Разбор ничего не записывает: он только перечисляет, что перенесётся и что
 * нет. Человек смотрит на этот список и только потом решает — как и при переносе
 * работ между инсталляциями.
 */
final class OtpParser
{
    /** Длина ответа, при которой точное сравнение ещё разумно. */
    private const MAX_EXACT_LENGTH = 80;

    /** Вид задания плагина «Тест», который разбирает этот класс. */
    private const PLUGIN = 'test';

    /**
     * Разобрать тест.
     *
     * @param array<string,mixed> $test
     *
     * @return array{
     *     title:string,
     *     description:string,
     *     timeLimit:int|null,
     *     showResults:bool,
     *     shuffleOptions:bool,
     *     questions:list<array<string,mixed>>,
     *     skipped:list<array{number:int,preview:string,reason:string}>
     * }
     */
    public function parse(array $test): array
    {
        $questions = [];
        $skipped = [];

        foreach ($this->listOf($test['Questions'] ?? null) as $question) {
            $number = (int) ($question['Number'] ?? 0);
            $converted = $this->convert($question);

            if ($converted['question'] === null) {
                $skipped[] = [
                    'number'  => $number,
                    'preview' => $this->preview($question),
                    'reason'  => $converted['reason'],
                ];

                continue;
            }

            $questions[] = $converted['question'];
        }

        return [
            'title'          => $this->title($test),
            'description'    => $this->description($test),
            'timeLimit'      => $this->timeLimit($test),
            'showResults'    => (bool) ($test['ShowRightAnswers'] ?? false),
            'shuffleOptions' => (bool) ($test['IsRandomAnswersInAllQuestions'] ?? false),
            'questions'      => $questions,
            'skipped'        => $skipped,
        ];
    }

    /**
     * Одно задание исходного теста.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function convert(array $raw): array
    {
        $answerType = (int) ($raw['AnswerType'] ?? 0);
        $type = OtpQuestionTypes::type($answerType);

        if ($type === null) {
            return [
                'question' => null,
                'reason'   => 'в системе нет задания «' . OtpQuestionTypes::title($answerType) . '»',
            ];
        }

        $built = match ($type) {
            'single_choice'   => $this->singleChoice($raw),
            'multiple_choice' => $this->multipleChoice($raw),
            'ordering'        => $this->ordering($raw),
            'numeric'         => $this->numeric($raw),
            'text'            => $this->textAnswer($raw),
            'matching'        => $this->matching($raw),
            default           => ['question' => null, 'reason' => 'тип не поддерживается'],
        };

        if ($built['question'] === null) {
            return $built;
        }

$expected = trim((string) ($built['question']['config']['expected'] ?? ''));

        /*
         * Ответ-строка, который сам является числом, переносится числовым
         * заданием: иначе «8» проверялось бы как текст, и ученик, написавший
         * «8 бит», получил бы верный ответ, а написавший «восемь» — неверный.
         * Так приезжают и пропуск внутри текста, и короткий ответ «14».
         */
        if ($built['question']['type'] === 'text' && $expected !== '' && is_numeric($expected)) {
            $built['question']['type'] = 'numeric';
            $built['question']['config'] = [
                'mode'      => 'exact',
                'expected'  => (float) $expected,
                'tolerance' => 0.01,
            ];
        }

        return $built;
    }

    // ------------------------------------------------------------- по типам

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function singleChoice(array $raw): array
    {
        $options = $this->choiceOptions($raw);
        $correct = $this->correctKeys($raw);

        if (count($options) < 2) {
            return ['question' => null, 'reason' => 'вариантов ответа меньше двух'];
        }

        if (count($correct) !== 1) {
            return [
                'question' => null,
                'reason'   => 'отмечено правильных вариантов: ' . count($correct)
                    . ' — нужно ровно одно',
            ];
        }

        return $this->done($raw, 'single_choice', ['correct' => $correct[0]], $options);
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function multipleChoice(array $raw): array
    {
        $options = $this->choiceOptions($raw);
        $correct = $this->correctKeys($raw);

        if ($options === []) {
            return ['question' => null, 'reason' => 'нет вариантов ответа'];
        }

        if ($correct === []) {
            return ['question' => null, 'reason' => 'не отмечено ни одного правильного варианта'];
        }

        /*
         * Отмечен один — это обычный «один вариант ответа», так и переносим:
         * правильный ответ сохраняется целиком, а не теряется из-за того, как
         * автор нажал кнопку в чужой системе.
         */
        if (count($correct) === 1) {
            return $this->done($raw, 'single_choice', ['correct' => $correct[0]], $options);
        }

        if (count($correct) === count($options)) {
            return [
                'question' => null,
                'reason'   => 'правильным отмечен каждый вариант — такой вопрос нельзя проверить',
            ];
        }

        return $this->done(
            $raw,
            'multiple_choice',
            ['correct' => $correct, 'partial' => true, 'miss_penalty' => 0.5],
            $options
        );
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function ordering(array $raw): array
    {
        $items = $this->listOf($raw['AnswerTemplates'] ?? null);
        $count = count($items);

        if ($count < 2) {
            return ['question' => null, 'reason' => 'элементов меньше двух'];
        }

        $positions = array_map(
            static fn (array $item): int => (int) ($item['ValueInt'] ?? 0),
            $items
        );

        $sorted = $positions;
        sort($sorted);

        if ($sorted !== range(1, $count)) {
            return ['question' => null, 'reason' => 'в исходном тесте не задан правильный порядок'];
        }

        /*
         * Порядок задаётся номерами позиций по ВСЕМ элементам исходного теста,
         * поэтому сортировка идёт до того, как пустые строки будут выброшены:
         * выбросить их раньше — значит получить не перестановку 1..N, и порядок
         * был бы признан незаданным вопреки исходному файлу.
         */
        $inOrder = array_keys($positions);
        usort($inOrder, static fn (int $a, int $b): int => $positions[$a] <=> $positions[$b]);

        $options = [];
        $keyOfIndex = [];

        foreach ($items as $at => $item) {
            if (trim($this->clean((string) ($item['Text'] ?? ''))) === '') {
                continue;
            }

            /*
             * Номер считается ДО добавления. Значение внутри создаваемого
             * массива вычисляется раньше, чем массив добавлен, и `count() - 1`
             * на первом элементе дало бы минус один — а номер строки у нас
             * беззнаковый, и внос молча падал бы на всём задании.
             */
            $position = count($options);

            $keyOfIndex[$at] = 'i' . ($position + 1);
            $options[] = [
                'kind'      => 'item',
                'value_key' => $keyOfIndex[$at],
                'text'      => $this->clean((string) ($item['Text'] ?? '')),
                'position'  => $position,
                'pair_key'  => null,
            ];
        }

        $order = [];

        foreach ($inOrder as $at) {
            if (isset($keyOfIndex[$at])) {
                $order[] = $keyOfIndex[$at];
            }
        }

        return $this->done(
            $raw,
            'ordering',
            ['correct_order' => $order, 'partial' => 'positional'],
            $options
        );
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function numeric(array $raw): array
    {
        $expected = $this->expectedNumber($raw);

        if ($expected === null) {
            return ['question' => null, 'reason' => 'не указан правильный числовой ответ'];
        }

        return $this->done($raw, 'numeric', [
            'mode'      => 'exact',
            'expected'  => $expected,
            'tolerance' => 0.01,
        ], []);
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function textAnswer(array $raw): array
    {
        $expected = $this->expectedText($raw);

        if ($expected === '') {
            // Ответ без эталона проверять нечем: точное сравнение с пустотой
            // зачтёт пустой ответ, а проверка по словам — тем более.
            return $this->done($raw, 'text', ['strategy' => 'manual'], []);
        }

        /*
         * Короткий эталон сравнивается сам, длинный — ждёт учителя: заставить
         * ученика дословно совпасть с эталоном в три строки значит заранее
         * выставить ему двойку за формулировку.
         */
        if (mb_strlen($expected) > self::MAX_EXACT_LENGTH) {
            return $this->done($raw, 'text', ['strategy' => 'manual'], []);
        }

        return $this->done($raw, 'text', [
            'strategy'           => 'exact',
            'expected'           => $expected,
            'variants'           => [$expected],
            'ignore_case'        => true,
            'ignore_order'       => false,
            'partial'            => true,
            'rows'               => 3,
        ], []);
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function matching(array $raw): array
    {
        $left = $this->filledColumn($raw, 'AnswerTemplates');
        $right = $this->filledColumn($raw, 'AnswerTemplatesDop');

        if (count($left) < 2) {
            return ['question' => null, 'reason' => 'в левой колонке меньше двух элементов'];
        }

        /*
         * У нас колонки должны быть равной длины: каждая строка — это одна
         * пара, и лишний элемент справа некуда девать. Ровно это присылает
         * OnlineTestPad, когда автор добавил справа запасной вариант.
         */
        if (count($left) !== count($right)) {
            return [
                'question' => null,
                'reason'   => 'колонки разной длины: слева ' . count($left) . ', справа ' . count($right),
            ];
        }

        // Ключ правого элемента по его Id: в ValueInt левой лежит именно он.
        $rightById = [];

        foreach ($right as $at => $item) {
            $rightById[(string) ($item['Id'] ?? '')] = 'r' . ($at + 1);
        }

        $pairs = [];
        $options = [];

        foreach ($left as $at => $item) {
            $leftKey = 'l' . ($at + 1);
            $rightKey = $rightById[(string) ($item['ValueInt'] ?? '')] ?? null;

            if ($rightKey === null) {
                return ['question' => null, 'reason' => 'не все элементы сопоставлены'];
            }

            $pairs[$leftKey] = $rightKey;
            $options[] = [
                'kind'      => 'left',
                'value_key' => $leftKey,
                'text'      => $this->clean((string) ($item['Text'] ?? '')),
                'position'  => $at,
                'pair_key'  => null,
            ];
        }

        foreach ($right as $at => $item) {
            $rightKey = 'r' . ($at + 1);
            $leftKey = array_search($rightKey, $pairs, true);

            $options[] = [
                'kind'      => 'right',
                'value_key' => $rightKey,
                'text'      => $this->clean((string) ($item['Text'] ?? '')),
                'position'  => $at,
                'pair_key'  => $leftKey === false ? null : (string) $leftKey,
            ];
        }

        return $this->done($raw, 'matching', ['pairs' => $pairs, 'partial' => true], $options);
    }

    /**
     * Колонка соответствия с непустым текстом.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<array<string,mixed>>
     */
    private function filledColumn(array $raw, string $key): array
    {
        $column = [];

        foreach ($this->listOf($raw[$key] ?? null) as $item) {
            if (trim($this->clean((string) ($item['Text'] ?? ''))) !== '') {
                $column[] = $item;
            }
        }

        return $column;
    }

    // ---------------------------------------------------------------- помощь

    /**
     * Собрать готовое задание.
     *
     * @param array<string,mixed>       $raw
     * @param list<array<string,mixed>> $options
     *
     * @return array{question:array<string,mixed>|null,reason:string}
     */
    private function done(array $raw, string $type, array $config, array $options): array
    {
        $prompt = $this->prompt($raw);

        if (trim($prompt) === '') {
            return ['question' => null, 'reason' => 'в задании нет текста'];
        }

        // Пустые строки уже выброшены при раздаче ключей, поэтому здесь ключи
        // сплошные и правильный ответ ссылается на свой вариант.
        if ($options !== [] && count($options) < 2) {
            return ['question' => null, 'reason' => 'осталось меньше двух заполненных строк'];
        }

        $weight = (float) ($raw['maxScore'] ?? 0);

        return ['question' => [
            'number'      => (int) ($raw['Number'] ?? 0),
            'type'        => $type,
            'plugin'      => self::PLUGIN,
            'prompt'      => $prompt,
            'plain'       => $this->plain($prompt),
            'weight'      => $weight > 0 ? $weight : 1.0,
            'required'    => (bool) ($raw['AnswerIsRequired'] ?? false),
            'explanation' => $this->clean((string) ($raw['Comment'] ?? '')),
            'config'      => $config,
            'options'     => $options,
        ], 'reason' => ''];
    }

    /**
     * Варианты ответа с ключами `a`, `b`, `c` — так их называет и наш редактор.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<array<string,mixed>>
     */
    private function choiceOptions(array $raw): array
    {
        $options = [];

        foreach ($this->filledAnswers($raw) as $at => $item) {
            $options[] = [
                'kind'      => 'option',
                'value_key' => chr(97 + $at),
                'text'      => $this->clean((string) ($item['Text'] ?? '')),
                'position'  => $at,
                'pair_key'  => null,
            ];
        }

        return $options;
    }

    /**
     * Отмеченные варианты.
     *
     * Отметка у OnlineTestPad живёт в `Score`, а не в `IsRightAnswer`: второе
     * поле в выгрузках остаётся выключенным даже там, где ответ отмечен. Оба
     * поля читаются, потому что встречается и то, и другое.
     *
     * Список берётся тем же, что и варианты: иначе после пропуска пустой строки
     * ключи разъедутся и ссылка на правильный ответ уедет на соседний вариант.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<string>
     */
    private function correctKeys(array $raw): array
    {
        $keys = [];

        foreach ($this->filledAnswers($raw) as $at => $item) {
            if ((float) ($item['Score'] ?? 0) > 0 || (bool) ($item['IsRightAnswer'] ?? false)) {
                $keys[] = chr(97 + $at);
            }
        }

        return $keys;
    }

    /**
     * Варианты с непустым текстом.
     *
     * Пустые строки выбрасываются сразу, до раздачи ключей. Если выбросить их
     * позже, в ключах останется дыра, а наш правильный ответ ссылается на ключ
     * по номеру — и молча уедет на соседний вариант.
     *
     * @param array<string,mixed> $raw
     *
     * @return list<array<string,mixed>>
     */
    private function filledAnswers(array $raw): array
    {
        $filled = [];

        foreach ($this->listOf($raw['AnswerTemplates'] ?? null) as $item) {
            if (trim($this->clean((string) ($item['Text'] ?? ''))) !== '') {
                $filled[] = $item;
            }
        }

        return $filled;
    }

    /**
     * Правильный ответ-число из поля ответа.
     *
     * @param array<string,mixed> $raw
     */
    private function expectedNumber(array $raw): ?float
    {
        $answers = $this->listOf($raw['AnswerTemplates'] ?? null);

        foreach ($answers as $item) {
            $value = (float) ($item['ValueFloat'] ?? 0);

            if ($value !== 0.0) {
                return $value;
            }

            $text = trim((string) ($item['ValueText'] ?? ''));

            if ($text !== '' && is_numeric($text)) {
                return (float) $text;
            }
        }

        return null;
    }

    /**
     * Правильный ответ-строка из поля ответа.
     *
     * @param array<string,mixed> $raw
     */
    private function expectedText(array $raw): string
    {
        $answers = $this->listOf($raw['AnswerTemplates'] ?? null);

        foreach ($answers as $item) {
            $text = trim((string) ($item['ValueText'] ?? ''));

            if ($text !== '') {
                return $this->clean($text);
            }
        }

        return '';
    }

    /**
     * Текст задания.
     *
     * Пропуск внутри текста (`{txt}`) — это место, куда ученик вписывает ответ.
     * У нас поле ответа стоит под текстом, поэтому вместо метки оставляем
     * прочерк: по нему видно, где был ответ, и текст читается как в оригинале.
     *
     * @param array<string,mixed> $raw
     */
    private function prompt(array $raw): string
    {
        $prompt = $this->clean((string) ($raw['Text'] ?? ''));

        return (string) preg_replace('/\{txt\d*\}/iu', '___', $prompt);
    }

    /** @param array<string,mixed> $raw */
    private function preview(array $raw): string
    {
        $plain = $this->plain($this->prompt($raw));

        return mb_strlen($plain) > 110 ? mb_substr($plain, 0, 110) . '…' : $plain;
    }

    /** @param array<string,mixed> $test */
    private function title(array $test): string
    {
        $name = trim((string) ($test['Name'] ?? ''));

        return $name === '' ? 'Тест из OnlineTestPad' : $name;
    }

    /**
     * Инструкция к тесту уходит в описание работы.
     *
     * @param array<string,mixed> $test
     */
    private function description(array $test): string
    {
        $heading = trim((string) ($test['InstructionsTitle'] ?? ''));
        $body = trim((string) ($test['Instructions'] ?? ''));

        if ($heading === '' || $heading === $body) {
            return $body;
        }

        return $heading === '' ? $body : '<p><strong>' . $this->clean($heading) . '</strong></p>' . $body;
    }

    /** @param array<string,mixed> $test */
    private function timeLimit(array $test): ?int
    {
        $params = is_array($test['Params'] ?? null) ? $test['Params'] : [];

        if (!($params['timelimited'] ?? false)) {
            return null;
        }

        $minutes = (int) ($params['timelimitminutes'] ?? 0)
            + (int) ($params['timelimitseconds'] ?? 0) / 60;

        return $minutes > 0 ? max(1, (int) round($minutes)) : null;
    }

    private function clean(string $html): string
    {
        return trim(Html::clean($html));
    }

    private function plain(string $html): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', Html::toText($html)) ?? '');

        return $plain === '' ? 'Без названия' : $plain;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}