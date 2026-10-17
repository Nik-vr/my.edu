<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

/**
 * Ошибка, из-за которой задание нельзя сохранить или проверить.
 *
 * Формулу и настройки задаёт учитель, а считается это на сервере, поэтому
 * каждая такая ошибка — понятный текст автору, а не исключение в лог.
 */
final class InvalidProblemException extends \RuntimeException
{
}