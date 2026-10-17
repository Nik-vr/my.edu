<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Стратегия типа вопроса.
 *
 * Добавление нового типа (например, «установить соответствие по формулам»
 * или «вставить формулу») — это один новый класс в Types/ и одна строка
 * в QuestionTypeRegistry. Схема БД, контроллеры и шаблоны при этом не меняются.
 *
 * ВАЖНО: payload() не имеет права возвращать правильный ответ — он уходит
 * в браузер ученика. Правильные ответы живут только в config/options и
 * используются исключительно в grade().
 */
interface QuestionTypeInterface
{
    /** Машинное имя типа; хранится в questions.type. */
    public function key(): string;

    /** Название для интерфейса автора (этап 2) и подписей в разборе. */
    public function title(): string;

    /**
     * Значения config по умолчанию — основа формы редактора (этап 2).
     *
     * @return array<string,mixed>
     */
    public function defaultConfig(): array;

    /**
     * Проверка целостности вопроса: есть ли верный ответ, достаточно ли
     * элементов и т.п. Вызывается автором при сохранении и при импорте.
     *
     * @param array<string,mixed>       $config
     * @param list<array<string,mixed>> $options
     *
     * @throws InvalidAnswerException
     */
    public function validateConfig(array $config, array $options): void;

    /**
     * Данные для отрисовки вопроса в браузере. Без правильного ответа.
     *
     * @return array<string,mixed>
     */
    public function payload(Question $question): array;

    /**
     * Приводит присланный ответ к внутреннему канону; бросает исключение,
     * если формат не тот.
     */
    public function normalizeAnswer(mixed $raw): mixed;

    /** Проверка ответа и выдача баллов. */
    public function grade(Question $question, mixed $answer): QuestionGrade;

    /** Текст правильного ответа для показа в разборе работы. */
    public function expectedText(Question $question): string;

    /** Текст ответа ученика для показа в разборе работы. */
    public function answerText(Question $question, mixed $answer): string;
}