<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Нормализация текста для сравнения свободных ответов.
 *
 * Задача — сделать сравнение устойчивым к типичным различиям в записи ученика:
 * регистр, «ё»/«е», лишние пробелы, двойные пробелы, пунктуация, неразрывные
 * пробелы и невидимые символы (например, copy-past из документа).
 */
final class TextNormalizer
{
    /** Невидимые и форматирующие символы, которые нужно вырезать целиком. */
    private const INVISIBLE = "\x{00a0}\x{200b}\x{200c}\x{200d}\x{200e}\x{200f}\x{feff}\x{2060}";

    /** Пунктуация: заменяется пробелом, а не удаляется, иначе слова склеиваются. */
    private const PUNCTUATION = <<<'REGEX'
    /[\p{P}\p{S}]+/u
    REGEX;

    /**
     * Приводит текст к сравнимому виду.
     *
     * @param bool $ignoreCase    игнорировать регистр
     * @param bool $ignoreSpacing схлопывать пробелы (влияет на exact/contains)
     */
    public static function normalize(string $text, bool $ignoreCase = true, bool $ignoreSpacing = true): string
    {
        // Нормализация Unicode (совместимые формы, напр. «№» или римские цифры).
        if (function_exists('normalizer_normalize')) {
            $normalized = @normalizer_normalize($text, \Normalizer::NFKC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = self::stripInvisible($text);
        $text = self::replaceYo($text);
        $text = (string) preg_replace(self::PUNCTUATION, ' ', $text);

        if ($ignoreSpacing) {
            $text = (string) preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);
        }

        if ($ignoreCase) {
            $text = self::lower($text);
        }

        return $text;
    }

    /**
     * Нормализация для сравнения без учёта порядка слов.
     *
     * Используется в стратегии exact с ignore_order: «кислород O2» и «O2 кислород»
     * считаются одним ответом.
     */
    public static function normalizeUnordered(string $text, bool $ignoreCase = true): string
    {
        $normalized = self::normalize($text, $ignoreCase, true);

        if ($normalized === '') {
            return '';
        }

        $words = explode(' ', $normalized);
        sort($words, SORT_STRING);

        return implode(' ', $words);
    }

    /**
     * Разбивает нормализованный текст на слова для проверки ключевых слов.
     *
     * @return list<string>
     */
    public static function words(string $text, bool $ignoreCase = true): array
    {
        $normalized = self::normalize($text, $ignoreCase, true);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    public static function stripInvisible(string $text): string
    {
        return (string) preg_replace('/[' . self::INVISIBLE . ']/u', ' ', $text);
    }

    /** Приводит «ё» к «е» — иначе половина учеников пишет по-другому. */
    public static function replaceYo(string $text): string
    {
        return str_replace(['ё', 'Ё', 'ё'], ['е', 'Е', 'е'], $text);
    }

    /**
     * Локализованный strtolower.
     *
     * mb_strtolower корректно работает с кириллицей, но требует mbstring,
     * который на некоторых хостингах отключён — предусмотрена заглушка.
     */
    private static function lower(string $text): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        // В CP1251 это единственный способ; в UTF-8 подойдёт для ASCII-случая.
        $map = [
            'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е',
            'Ё' => 'е', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к',
            'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р',
            'С' => 'с', 'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц',
            'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь',
            'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
        ];

        return strtr($text, $map);
    }
}