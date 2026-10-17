<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Присланный учеником ответ не в том формате, который ожидает тип вопроса.
 * Ошибка 422: запрос разобран, но данные непригодны.
 */
final class InvalidAnswerException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $questionKey = '')
    {
        parent::__construct($message, 422);
    }
}