<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Настройки сайта, которые меняет администратор из интерфейса.
 *
 * Отдельное хранилище «ключ → значение», а не правка config.php: настройку
 * должен уметь менять владелец сайта без доступа к файлам на сервере.
 *
 * Значения читаются из базы, а не кешируются: страницу правят редко, а
 * рассинхронизация кеша с настройкой выглядела бы как «я сохранил, а ничего
 * не изменилось».
 */
final class Settings
{
    public const SCHOOL_NAME = 'school_name';

    public function __construct(
        private readonly Database $db
    ) {
    }

    /** Название школы. Пустая строка, пока его не задали. */
    public function schoolName(): string
    {
        return trim($this->get(self::SCHOOL_NAME, ''));
    }

    public function setSchoolName(string $value): void
    {
        $this->set(self::SCHOOL_NAME, trim($value));
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->db->value(
            'SELECT `value` FROM `settings` WHERE `key` = :key LIMIT 1',
            ['key' => $key]
        );

        return $value === null ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES (:key, :value, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()',
            ['key' => $key, 'value' => $value]
        );
    }
}