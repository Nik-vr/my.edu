<?php

declare(strict_types=1);

namespace MyEdu\Repository;

use MyEdu\Core\Database;

/**
 * Ученики.
 *
 * На этапе 1 ученик не авторизуется, а называет себя при начале теста.
 * Репозиторий подбирает существующую запись по ФИО либо создаёт новую —
 * это даёт историю попыток без полноценной регистрации (этап 3).
 */
final class StudentRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = :id AND role = :role', [
            'id'   => $id,
            'role' => 'student',
        ]);
    }

    /**
     * Ищет ученика по ФИО без учёта регистра и лишних пробелов.
     *
     * Сравнение идёт по нормализованному значению, поэтому «Иванов  Иван»
     * и «иванов иван» найдут одну запись. Нормализация продублирована
     * в SQL, а не в PHP, чтобы не тянуть все записи в память.
     */
    public function findByName(string $fullName): ?array
    {
        $normalized = $this->normalize($fullName);

        if ($normalized === '') {
            return null;
        }

        return $this->db->one(
            "SELECT * FROM users
              WHERE role = 'student'
                AND LOWER(TRIM(REPLACE(REPLACE(full_name, '  ', ' '), 'ё', 'е'))) = :normalized
           ORDER BY id
              LIMIT 1",
            ['normalized' => self::lower($normalized)]
        );
    }

    /**
     * Приведение к нижнему регистру для поиска по ФИО.
     *
     * Нужен именно для кириллицы; при отсутствии mb_string регистр
     * не понижается, и поиск становится чувствительным к нему.
     */
    private static function lower(string $text): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtr($text, [
            'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е',
            'Ё' => 'е', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к',
            'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р',
            'С' => 'с', 'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц',
            'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь',
            'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
        ]);
    }

    /**
     * Возвращает id ученика: находит существующего или создаёт нового.
     */
    public function findOrCreateByName(string $fullName): int
    {
        $existing = $this->findByName($fullName);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->db->insert('users', [
            'role'       => 'student',
            'full_name'  => $fullName,
            'is_active'  => 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * Ограничение длины ФИО в users.full_name (VARCHAR(191)).
     */
    public static function normalize(string $fullName): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($fullName));
        $name = str_replace(['ё', 'Ё'], ['е', 'Е'], $name);

        // mbstring проверяется при старте приложения, но класс может быть
        // использован и из CLI-скриптов — подстрахуемся.
        return function_exists('mb_substr')
            ? mb_substr($name, 0, 191, 'UTF-8')
            : substr($name, 0, 191);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}