<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Ученики: их собственная таблица `students`, а не `users` с ролью student.
 *
 * Учеников заводит завуч или администратор: у каждого есть логин и пароль,
 * поиск по имени только находит существующего и никогда не создаёт записей.
 * Класс у ученика один (`class_id`), NULL означает «вне класса»: ученика
 * убрали из класса, а учётную запись сохранили.
 */
final class StudentRepository
{
    /** Алфавит логинов: русские буквы, как того требует формат «буква + цифры». */
    private const LOGIN_LETTERS = 'АБВГДЕЖЗИКЛМНОПРСТУФХЦЧШЩЭЮЯ';

    public function __construct(private readonly Database $db)
    {
    }

    /** Строка ученика, кроме заблокированных. */
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM students WHERE id = :id AND status <> :blocked', [
            'id'      => $id,
            'blocked' => 'blocked',
        ]);
    }

    /**
     * Поиск для входа: по логину, кроме заблокированных.
     *
     * @return array<string,mixed>|null
     */
    public function findByLogin(string $login): ?array
    {
        return $this->db->one(
            'SELECT * FROM students WHERE login = :login AND status <> :blocked LIMIT 1',
            ['login' => $login, 'blocked' => 'blocked']
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function hydrate(array $row): Student
    {
        return Student::fromRow($row);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $fullName, ?int $classId = null): ?array
    {
        $normalized = $this->normalizeName($fullName);

        if ($normalized === '') {
            return null;
        }

        return $this->db->one(
            "SELECT s.* FROM students s
              WHERE LOWER(TRIM(REPLACE(REPLACE(s.full_name, '  ', ' '), 'ё', 'е'))) = :normalized"
            . ($classId === null ? '' : ' AND s.class_id = :class') . '
           ORDER BY s.id
              LIMIT 1',
            $classId === null
                ? ['normalized' => $this->lower($normalized)]
                : ['normalized' => $this->lower($normalized), 'class' => $classId]
        );
    }

    /**
     * Однофамилец, которого ещё можно склеить с новой записью.
     *
     * Склеивать можно того, у кого ещё нет ни одной попытки: смешивать нечего, и
     * почти наверняка это тот же человек — перешёл в другой класс или был заведён
     * повторно из списка. А вот у ученика с работами имя совпадает уже с
     * кем-то другим: два «Иванов Иван Иванович» в разных классах — обычное дело,
     * и склейка отдала бы одному из них чужие оценки и логин.
     *
     * Поэтому наличие попыток — граница: после неё совпадение имени перестаёт
     * быть основанием считать, что это один и тот же человек.
     */
    public function findMergeableByName(string $fullName): ?array
    {
        $existing = $this->findByName($fullName);

        if ($existing === null) {
            return null;
        }

        $hasAttempts = $this->db->value(
            'SELECT 1 FROM assignment_attempts WHERE student_id = :id LIMIT 1',
            ['id' => (int) $existing['id']]
        );

        return $hasAttempts === null ? $existing : null;
    }

    /**
     * В каком классе числится ученик.
     *
     * @return list<string>
     */
    public function classNames(int $studentId): array
    {
        $row = $this->db->one(
            'SELECT c.name FROM students s
               JOIN classes c ON c.id = s.class_id
              WHERE s.id = :id',
            ['id' => $studentId]
        );

        return $row === null ? [] : [(string) $row['name']];
    }

    /**
     * Ученики класса вместе с их логинами.
     *
     * @return list<array<string,mixed>>
     */
    public function listByClass(int $classId): array
    {
        return $this->db->all(
            'SELECT id, full_name, login, status, created_at
               FROM students
              WHERE class_id = :class
           ORDER BY full_name',
            ['class' => $classId]
        );
    }

    public function isInClass(int $classId, int $studentId): bool
    {
        return $this->db->value(
            'SELECT 1 FROM students WHERE id = :user AND class_id = :class LIMIT 1',
            ['class' => $classId, 'user' => $studentId]
        ) !== null;
    }

    /**
     * Перевод в класс.
     *
     * Ученик в одном классе, поэтому «добавить в другой» — это перенос, а не
     * второе членство: прежнее классное списание снимается тем же запросом.
     */
    public function addToClass(int $classId, int $studentId): void
    {
        $this->db->run(
            'UPDATE students SET class_id = :class, updated_at = NOW() WHERE id = :user',
            ['class' => $classId, 'user' => $studentId]
        );
    }

    /**
     * Убрать из класса, сохранив учётную запись.
     *
     * Запрос скован и классом, и учеником: ученика чужого класса убрать из
     * этого нельзя, и проверка isInClass() перед вызовом не единственный
     * барьер.
     */
    public function removeFromClass(int $classId, int $studentId): void
    {
        $this->db->run(
            'UPDATE students SET class_id = NULL, updated_at = NOW()
              WHERE id = :user AND class_id = :class',
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
        $id = $this->db->insert('students', [
            'login'         => $login,
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'full_name'     => $fullName,
            // Ученику пароль выдаёт завуч на бумаге, почты у него нет,
            // поэтому подтверждение адреса не требуется.
            'status'        => 'active',
            'class_id'      => $classId,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        // Части имени заполняются здесь же, а не в UserRepository: заведение
        // ученика идёт через Database напрямую, и без этого у него были бы
        // пустые фамилия и имя при заполненном ФИО.
        ] + UserRepository::splitName($fullName));

        return ['id' => $id, 'login' => $login, 'password' => $plainPassword];
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

    /**
     * Занят ли логин.
     *
     * Проверяются обе таблицы, а не только ученики: логины сотрудников — это
     * их адреса почты, и совпадение с ними запутало бы людей на страницах
     * входа, где одна форма обслуживает учителей, а другая — учеников.
     */
    public function loginTaken(string $login): bool
    {
        $inStudents = $this->db->value(
            'SELECT 1 FROM students WHERE login = :login LIMIT 1',
            ['login' => $login]
        );

        if ($inStudents !== null) {
            return true;
        }

        return $this->db->value(
            'SELECT 1 FROM users WHERE login = :login OR email = :email LIMIT 1',
            ['login' => $login, 'email' => $login]
        ) !== null;
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
        $changed = $this->db->update('students', [
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

        $this->db->update('students', UserRepository::splitName($name) + [
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
        $this->db->update('students', [
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
