<?php

declare(strict_types=1);

namespace MyEdu\Import;

/**
 * Файл не удалось разобрать как выгрузку извне.
 *
 * Отдельный класс, а не TransferException из переноса работ: сообщение здесь
 * адресовано учителю, который приложил не тот файл, и пересказывать ему
 * устройство пакета переноса незачем.
 */
final class ImportException extends \RuntimeException
{
}