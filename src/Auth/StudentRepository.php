<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Ученики класса.
 *
 * На этапе 1 ученик просто назывался при начале теста. Теперь учеников
 * заводит завуч или администратор, у каждого есть логин и пароль, поэтому
 * поиск по имени больше не создаёт записей: он только находит существующего.
 */
final class StudentRepository
{
    /** Алфавит логинов: русские буквы, как того требует формат «буква + цифры». */
    private const LOGIN_LETTERS = 'АБВГДЕЖЗИКЛМНОПРСТУФХЦЧШЩЭЮЯ';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = :id AND status <> :blocked', [
            'id'      => $id,
            'blocked' => 'blocked',
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $fullName): ?array
    {
        $normalized = $this->normalizeName($fullName);

        if ($normalized === '') {
            return null;
        }

        return $this->db->one(
            "SELECT u.* FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.role_code = 'student'
                AND LOWER(TRIM(REPLACE(REPLACE(u.full_name, '  ', ' '), 'ё', 'е'))) = :normalized
           ORDER BY u.id
              LIMIT 1",
            ['normalized' => $this->lower($normalized)]
        );
    }

    /**
     * Ученики класса вместе с их логинами.
     *
     * @return list<array<string,mixed>>
     */
    public function listByClass(int $classId): array
    {
        return $this->db->all(
            'SELECT u.id, u.full_name, u.login, u.status, cs.joined_at
               FROM class_students cs
               JOIN users u ON u.id = cs.student_id
              WHERE cs.class_id = :class
           ORDER BY u.full_name',
            ['class' => $classId]
        );
    }

    public function isInClass(int $classId, int $studentId): bool
    {
        return $this->db->value(
            'SELECT 1 FROM class_students WHERE class_id = :class AND student_id = :user LIMIT 1',
            ['class' => $classId, 'user' => $studentId]
        ) !== null;
    }

    public function addToClass(int $classId, int $studentId): void
    {
        $this->db->run(
            'INSERT IGNORE INTO class_students (class_id, student_id, joined_at) VALUES (:class, :user, NOW())',
            ['class' => $classId, 'user' => $studentId]
        );
    }

    public function removeFromClass(int $classId, int $studentId): void
    {
        $this->db->run(
            'DELETE FROM class_students WHERE class_id = :class AND student_id = :user',
            ['class' => $classId, 'user' => $studentId]
        );
    }

    /**
     * Создаёт ученика с выданным логином и паролем.
     *
     * @return array{id:int,login:string,password:string}
     */
    public function create(string $fullName, string $login, string $plainPassword, int $classId): array
    {
        $userId = $this->db->transaction(function (Database $db) use ($fullName, $login, $plainPassword, $classId): int {
            $id = $db->insert('users', [
                'login'         => $login,
                'email'         => null,
                'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                'full_name'     => $fullName,
                'is_owner'      => 0,
                // Ученику пароль выдаёт завуч на бумаге, почты у него нет,
                // поэтому подтверждение адреса не требуется.
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            // Части имени заполняются здесь же, а не в UserRepository:
            // заведение ученика идёт через Database напрямую, минуя
            // репозиторий, и без этого у ученика были бы пустые фамилия и
            // имя при заполненном ФИО.
            ] + UserRepository::splitName($fullName));

            $db->run(
                'INSERT INTO user_roles (user_id, role_code, granted_at) VALUES (:user, :role, NOW())',
                ['user' => $id, 'role' => Role::STUDENT]
            );

            $db->run(
                'INSERT INTO class_students (class_id, student_id, joined_at) VALUES (:class, :user, NOW())',
                ['class' => $classId, 'user' => $id]
            );

            return $id;
        });

        return ['id' => $userId, 'login' => $login, 'password' => $plainPassword];
    }

    /** Генерирует логин вида «И1234» и проверяет уникальность. */
    public function generateLogin(string $preferredLetter): string
    {
        $letter = $this->loginLetter($preferredLetter);
        $letters = [$letter];

        // Запасные буквы на случай, пока основная занята.
        $alphabet = str_split(self::LOGIN_LETTERS);
        $offset = array_search($letter, $alphabet, true);
        for ($i = 1; $i < count($alphabet); $i++) {
            $letters[] = $alphabet[(($offset === false ? 0 : $offset) + $i) % count($alphabet)];
        }

        foreach ($letters as $candidate) {
            for ($attempt = 0; $attempt < 40; $attempt++) {
                $login = $candidate . random_int(1000, 9999);

                if (!$this->loginTaken($login)) {
                    return $login;
                }
            }
        }

        throw new \RuntimeException('Не удалось подобрать свободный логин');
    }

    /** Восемь цифр — так пароль удобно продиктовать и записать. */
    public function generatePassword(): string
    {
        return (string) random_int(10000000, 99999999);
    }

    public function loginTaken(string $login): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE login = :login LIMIT 1', ['login' => $login]) !== null;
    }

    /**
     * Задать ученику новый пароль.
     *
     * Если запись не прошла, бросается исключение: иначе контроллер отдал бы на
     * лист пароль, который не подходит, и человек распечатал бы и раздал бы
     * бумагу, на которой ничего не работает.
     */
public function changePassword(int $studentId, string $plainPassword): void
    {
        $changed = $this->db->update('users', [
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['id' => $studentId]);

        if ($changed < 1) {
            throw new \RuntimeException('Не удалось сохранить новый пароль');
        }
    }

    /**
     * Переименование ученика.
     *
     * Части имени пересобираются здесь же, а не остаются от прежнего ФИО:
     * фамилия, имя и отчество хранятся отдельно, и без пересборки в журнале
     * и на печатном листе осталось бы «Петров Иван» после правки на «Петрова
     * Ивана Ильича».
     *
     * @return bool false — ученика нет или новое имя уже занято
     */
    public function rename(int $studentId, string $fullName): bool
    {
        $name = self::normalizeName($fullName);

        if ($name === '') {
            return false;
        }

        $existing = $this->findByName($name);

        if ($existing !== null && (int) $existing['id'] !== $studentId) {
            return false;
        }

        $this->db->update('users', UserRepository::splitName($name) + [
            'full_name'  => $name,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $studentId]);

        return true;
    }

    /**
     * Блокировка и разблокировка входа.
     *
     * Учётная запись остаётся, вместе с ней все попытки: заблокированный
     * ученик не может войти, но его история не пропадает и возвращается к
     * прежней при разблокировке.
     */
    public function setStatus(int $studentId, string $status): void
    {
        $this->db->update('users', [
            'status'     => $status === 'blocked' ? 'blocked' : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $studentId]);
    }

    public static function normalizeName(string $fullName): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($fullName));
        $name = str_replace(['ё', 'Ё'], ['е', 'Е'], $name);

        return function_exists('mb_substr')
            ? mb_substr($name, 0, 191, 'UTF-8')
            : substr($name, 0, 191);
    }

    /**
     * Первая буква фамилии в верхнем регистре — по ней ученик узнаёт свой логин.
     */
    private function loginLetter(string $fullName): string
    {
        $first = self::normalizeName($fullName);
        $letter = mb_strtoupper(mb_substr($first, 0, 1, 'UTF-8'), 'UTF-8');

        if (!in_array($letter, str_split(self::LOGIN_LETTERS), true)) {
            return 'У';
        }

        return $letter;
    }

    private function lower(string $text): string
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
}