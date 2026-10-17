<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Разбор числового ответа.
 *
 * Вынесен отдельно, потому что ответ приходит свободным текстом: ученик
 * пишет «9,81», «9.81», «≈9,8 м/с» или «ответ: 20». Проверка должна понимать
 * это одинаково, независимо от типа задания.
 */
final class NumberParser
{
    /**
     * Первое число из текста, вместе со знаком.
     *
     * Поддерживает русскую десятичную запятую и английскую точку: в русской
     * школе «9,8» и «9.8» — одно и то же число, и требовать точку было бы
     * требованием ошибаться.
     *
     * @return float|null null, если чисел в тексте нет
     */
    public static function extract(string $text): ?float
    {
        $normalized = TextNormalizer::replaceYo($text);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        if (preg_match('/[-+]?\d+(?:[.,]\d+)?/u', $normalized, $matches) !== 1) {
            return null;
        }

        $value = str_replace(',', '.', $matches[0]);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Приводит текст поля к числу, если это возможно однозначно.
     *
     * Отличается от extract() тем, что не берёт первое число из чего угодно:
     * в ответе «площадь 9,81, периметр 12» чисел два, и такой ответ не
     *однозначен — засчитать первое значило бы поставить балл за ошибку.
     * Допускается только одно число и, возможно, единица измерения рядом.
     *
     * @return float|null null, если ответ не является одним числом
     */
    public static function strict(string $text): ?float
    {
        $normalized = TextNormalizer::replaceYo($text);
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        // Ведущий мусор («≈», «примерно») на число не влияет и отбрасывается.
        $normalized = (string) preg_replace('/^[^-+0-9]+/u', '', $normalized);

        // Всё, что осталось, должно быть числом и, возможно, единицей.
        if (preg_match('/^([-+]?\d+(?:[.,]\d+)?)\s*[^\d]{0,24}$/u', $normalized, $matches) !== 1) {
            return null;
        }

        $value = str_replace(',', '.', $matches[1]);

        return is_numeric($value) ? (float) $value : null;
    }
}