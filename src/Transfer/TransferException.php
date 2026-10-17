<?php

declare(strict_types=1);

namespace MyEdu\Transfer;

use RuntimeException;

/**
 * Ошибка переноса, о которой можно сказать учителю.
 *
 * Отдельный тип нужен, чтобы отличить «пакет не такой» от «сломалось внутри»:
 * первое — понятное сообщение на странице импорта, второе — ошибка сервера.
 */
final class TransferException extends RuntimeException
{
}