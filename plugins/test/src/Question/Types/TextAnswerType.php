<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question\Types;

use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionGrade;
use MyEduPlugin\Test\Question\QuestionTypeInterface;
use MyEduPlugin\Test\Question\TextNormalizer;

/**
 * Задание с текстовым ответом.
 *
 * Проверка свободного текста — самая спорная часть системы, поэтому стратегию
 * выбирает автор, а не алгоритм:
 *
 *   exact    — нормализованное совпадение с одним из вариантов ответа.
 *              Устойчиво к регистру, «ё/е», пунктуации и лишним пробелам.
 *              ignore_order разрешает перестановку слов.
 *   contains — ответ содержит одну из заданных фраз. Для коротких
 *              ответов вида «что?», где ученик пишет предложение.
 *   keywords — все обязательные ключевые слова есть и нет запрещённых.
 *              Самая устойчивая к переформулировкам стратегия.
 *   manual   — автопроверки нет, вопрос уходит учителю на ручную оценку.
 *
 * Числовой ответ вынесен в отдельный тип NumericType: смешивать число и текст
 * в одном типе неудобно — для числа нужны свои правила разбора и допуск, а
 * здесь они были бы лишней возможностью выбрать не то.
 *
 * config:
 *   {
 *     "strategy": "keywords",
 *     "expected": "Солнечный свет, углекислый газ, хлорофилл",
 *     "variants": ["..."], "required_keywords": ["..."], "forbidden_keywords": ["..."],
 *     "ignore_case": true, "ignore_order": false,
 *     "partial": true, "min_length": 0, "max_length": 0, "rows": 3
 *   }
 */
final class TextAnswerType implements QuestionTypeInterface
{
    public const STRATEGY_EXACT    = 'exact';
    public const STRATEGY_CONTAINS = 'contains';
    public const STRATEGY_KEYWORDS = 'keywords';
    public const STRATEGY_MANUAL   = 'manual';

    public function key(): string
    {
        return 'text';
    }

    public function title(): string
    {
        return 'Задание с текстовым ответом';
    }

    public function defaultConfig(): array
    {
        return [
            'strategy'          => self::STRATEGY_KEYWORDS,
            'expected'          => '',
            'variants'          => [],
            'required_keywords' => [],
            'forbidden_keywords'=> [],
            'ignore_case'       => true,
            'ignore_order'      => false,
            'partial'           => true,
            'min_length'        => 0,
            'max_length'        => 0,
            'rows'              => 3,
        ];
    }

    /** @return list<string> */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_KEYWORDS,
            self::STRATEGY_EXACT,
            self::STRATEGY_CONTAINS,
            self::STRATEGY_MANUAL,
        ];
    }

    public static function strategyTitle(string $strategy): string
    {
        return match ($strategy) {
            self::STRATEGY_EXACT    => 'точное совпадение (с вариантами)',
            self::STRATEGY_CONTAINS => 'ответ содержит фразу',
            self::STRATEGY_KEYWORDS => 'по ключевым словам',
            self::STRATEGY_MANUAL   => 'ручная проверка учителем',
            default                 => $strategy,
        };
    }

    public function validateConfig(array $config, array $options): void
    {
        $strategy = (string) ($config['strategy'] ?? '');

        if (!in_array($strategy, self::strategies(), true)) {
            throw new InvalidAnswerException('Неизвестная стратегия проверки: ' . $strategy);
        }

        if ($strategy === self::STRATEGY_MANUAL) {
            return;
        }

        if ($strategy === self::STRATEGY_KEYWORDS) {
            $required = $this->stringList($config['required_keywords'] ?? []);
            if ($required === []) {
                throw new InvalidAnswerException('Нужно указать хотя бы одно ключевое слово');
            }
            return;
        }

        $variants = $this->stringList($config['variants'] ?? []);
        if ($variants === [] && trim((string) ($config['expected'] ?? '')) === '') {
            throw new InvalidAnswerException('Нужно указать эталонный ответ');
        }
    }

    public function payload(Question $question): array
    {
        return [
            'strategy'   => (string) $question->configValue('strategy', self::STRATEGY_KEYWORDS),
            'rows'       => max(1, (int) $question->configFloat('rows', 3)),
            'max_length' => (int) $question->configFloat('max_length', 0),
            'min_length' => (int) $question->configFloat('min_length', 0),
            'placeholder'=> $this->placeholder($question),
        ];
    }

    public function normalizeAnswer(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }

        if (is_array($raw)) {
            $raw = implode("\n", array_filter(array_map(
                static fn ($v): string => is_scalar($v) ? (string) $v : '',
                $raw
            ), static fn (string $v): bool => $v !== ''));
        }

        if (!is_string($raw)) {
            throw new InvalidAnswerException('Ожидалась строка');
        }

        // CRLF из textarea приходит как \r\n — приводим к \n,
        // иначе разница между Windows и Unix не будет видна в разборе.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Убираем управляющие символы, кроме переводов строк.
        $raw = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);

        return trim($raw);
    }

    public function grade(Question $question, mixed $answer): QuestionGrade
    {
        $max = $question->maxScore();
        $strategy = (string) $question->configValue('strategy', self::STRATEGY_KEYWORDS);
        $text = is_string($answer) ? trim($answer) : '';

        if ($strategy === self::STRATEGY_MANUAL) {
            return QuestionGrade::forManualReview(
                $max,
                $text === ''
                    ? 'Вопрос без автоматической проверки. Ответ не задан.'
                    : 'Вопрос без автоматической проверки. Ответ ученика ждёт оценки учителя.'
            );
        }

        $expected = $this->expectedText($question);

        if ($text === '') {
            return QuestionGrade::wrong($max, $expected, ['strategy' => $strategy]);
        }

        return match ($strategy) {
            self::STRATEGY_KEYWORDS => $this->gradeKeywords($question, $text, $max, $expected),
            self::STRATEGY_CONTAINS => $this->gradeContains($question, $text, $max, $expected),
            default                => $this->gradeExact($question, $text, $max, $expected),
        };
    }

    public function expectedText(Question $question): string
    {
        $strategy = (string) $question->configValue('strategy', self::STRATEGY_KEYWORDS);
        $expected = trim((string) $question->configValue('expected', ''));

        if ($strategy === self::STRATEGY_KEYWORDS) {
            $required = $this->stringList($question->configValue('required_keywords', []));
            $forbidden = $this->stringList($question->configValue('forbidden_keywords', []));

            $parts = [];
            if ($expected !== '') {
                $parts[] = $expected;
            }
            if ($required !== []) {
                $parts[] = 'обязательные слова: ' . implode(', ', $required);
            }
            if ($forbidden !== []) {
                $parts[] = 'не должно быть: ' . implode(', ', $forbidden);
            }

            return $parts === [] ? '—' : implode('. ', $parts);
        }

        if ($expected !== '') {
            return $expected;
        }

        $variants = $this->stringList($question->configValue('variants', []));

        return $variants === [] ? '—' : implode(' / ', $variants);
    }

    public function answerText(Question $question, mixed $answer): string
    {
        $text = is_string($answer) ? trim($answer) : '';

        return $text === '' ? '— ответ не введён —' : $text;
    }

    // ---------------------------------------------------------------- стратегии

    private function gradeExact(Question $question, string $text, float $max, string $expected): QuestionGrade
    {
        $ignoreCase = $question->configBool('ignore_case', true);
        $ignoreOrder = $question->configBool('ignore_order', false);

        $normalize = $ignoreOrder
            ? static fn (string $s): string => TextNormalizer::normalizeUnordered($s, $ignoreCase)
            : static fn (string $s): string => TextNormalizer::normalize($s, $ignoreCase, true);

        $given = $normalize($text);

        foreach ($this->acceptedVariants($question) as $variant) {
            if ($normalize($variant) === $given) {
                return QuestionGrade::correct($max, $max, $expected);
            }
        }

        return QuestionGrade::wrong($max, $expected, ['strategy' => self::STRATEGY_EXACT]);
    }

    private function gradeContains(Question $question, string $text, float $max, string $expected): QuestionGrade
    {
        $ignoreCase = $question->configBool('ignore_case', true);
        $haystack = TextNormalizer::normalize($text, $ignoreCase, true);

        if ($haystack === '') {
            return QuestionGrade::wrong($max, $expected, ['strategy' => self::STRATEGY_CONTAINS]);
        }

        foreach ($this->acceptedVariants($question) as $variant) {
            $needle = TextNormalizer::normalize($variant, $ignoreCase, true);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return QuestionGrade::correct($max, $max, $expected);
            }
        }

        return QuestionGrade::wrong($max, $expected, ['strategy' => self::STRATEGY_CONTAINS]);
    }

    private function gradeKeywords(Question $question, string $text, float $max, string $expected): QuestionGrade
    {
        $ignoreCase = $question->configBool('ignore_case', true);
        $required = $this->stringList($question->configValue('required_keywords', []));
        $forbidden = $this->stringList($question->configValue('forbidden_keywords', []));

        $words = TextNormalizer::words($text, $ignoreCase);
        $haystack = ' ' . TextNormalizer::normalize($text, $ignoreCase, true) . ' ';

        $found = [];
        $missing = [];

        foreach ($required as $keyword) {
            $needle = TextNormalizer::normalize($keyword, $ignoreCase, true);

            if ($needle === '') {
                continue;
            }

            // Ключевое слово ищем как отдельное слово, чтобы «свет» не
            // срабатывало внутри слова «светофор». Пробелы по краям нужны,
            // чтобы фраза в начале или в конце ответа тоже находилась.
            if (in_array($needle, $words, true) || str_contains($haystack, ' ' . $needle . ' ')) {
                $found[] = $keyword;
            } else {
                $missing[] = $keyword;
            }
        }

        $forbiddenHit = null;
        foreach ($forbidden as $keyword) {
            $needle = TextNormalizer::normalize($keyword, $ignoreCase, true);

            if ($needle !== '' && str_contains($haystack, ' ' . $needle . ' ')) {
                $forbiddenHit = $keyword;
                break;
            }
        }

        $detail = [
            'strategy'        => self::STRATEGY_KEYWORDS,
            'found'           => $found,
            'missing'         => $missing,
            'forbidden_found' => $forbiddenHit,
        ];

        if ($forbiddenHit !== null) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        if ($missing === []) {
            return QuestionGrade::correct($max, $max, $expected, $detail);
        }

        if (!$question->configBool('partial', true) || $required === []) {
            return QuestionGrade::wrong($max, $expected, $detail);
        }

        $ratio = count($found) / count($required);

        return QuestionGrade::partial(round($ratio * $max, 2), $max, $expected, $detail);
    }

    // ---------------------------------------------------------------- помощники

    /** @return list<string> */
    private function acceptedVariants(Question $question): array
    {
        $variants = $this->stringList($question->configValue('variants', []));
        $expected = trim((string) $question->configValue('expected', ''));

        if ($expected !== '') {
            array_unshift($variants, $expected);
        }

        return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== '')));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function placeholder(Question $question): string
    {
        $strategy = (string) $question->configValue('strategy', self::STRATEGY_KEYWORDS);

        return match ($strategy) {
            self::STRATEGY_EXACT    => 'Краткий ответ',
            self::STRATEGY_CONTAINS => 'Ответ своими словами',
            default                 => 'Развёрнутый ответ',
        };
    }
}