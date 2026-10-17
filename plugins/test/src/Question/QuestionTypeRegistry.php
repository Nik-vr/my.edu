<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

use MyEduPlugin\Test\Question\Types\MatchingType;
use MyEduPlugin\Test\Question\Types\MultipleChoiceType;
use MyEduPlugin\Test\Question\Types\NumericType;
use MyEduPlugin\Test\Question\Types\OrderingType;
use MyEduPlugin\Test\Question\Types\SingleChoiceType;
use MyEduPlugin\Test\Question\Types\TextAnswerType;
use RuntimeException;

/**
 * Реестр типов вопросов.
 *
 * Единственное место, которое нужно править при добавлении нового типа.
 */
final class QuestionTypeRegistry
{
    private static ?self $instance = null;

    /** @var array<string,QuestionTypeInterface> */
    private array $types = [];

    public function __construct()
    {
        foreach ([
            new SingleChoiceType(),
            new MultipleChoiceType(),
            new OrderingType(),
            new NumericType(),
            new TextAnswerType(),
            new MatchingType(),
        ] as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get(string $key): QuestionTypeInterface
    {
        if (!isset($this->types[$key])) {
            throw new RuntimeException('Неизвестный тип вопроса: ' . $key);
        }

        return $this->types[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return array<string,QuestionTypeInterface> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}