<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Database;

/**
 * Загрузка пользователей и их ролей.
 *
 * Роли подгружаются вместе с пользователем одним запросом, чтобы на странице
 * со списком из 50 человек не было 50 дополнительных запросов.
 */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findRow(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function findUser(int $id): ?User
    {
        $row = $this->findRow($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Поиск сотрудника по логину или e-mail: сотрудник входит тем же адресом,
     * который указал при регистрации.
     *
     * Ученик сюда не ищется: его логин живёт в таблице `students`, и вход
     * у него отдельный. Общий поиск в обе таблицы делает невозможным
     * различение того, чей id найден, — поэтому таблицы не смешиваются.
     *
     * Именованные параметры здесь разные, хотя значения одинаковые:
     * при ATTR_EMULATE_PREPARES = false один и тот же плейсхолдер
     * нельзя использовать дважды — драйвер отвечает «Invalid parameter number».
     *
     * @return array<string,mixed>|null
     */
    public function findRowByIdentifier(string $identifier): ?array
    {
        return $this->db->one(
            'SELECT * FROM users
              WHERE (login = :by_login OR email = :by_email)
                AND status <> :blocked
              LIMIT 1',
            [
                'by_login' => $identifier,
                'by_email' => $identifier,
                'blocked'  => 'blocked',
            ]
        );
    }

    /** @return array<string,mixed>|null */
    public function findRowByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email), 'UTF-8')]
        );
    }

    public function emailExists(string $email): bool
    {
        $found = $this->db->value(
            'SELECT 1 FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email), 'UTF-8')]
        );

        return $found !== null;
    }

    /**
     * Кому принадлежит адрес почты.
     *
     * Нужна при смене адреса: одного «занят или нет» мало — человек вправе
     * переписать свой же адрес, а проверка занятости сказала бы «занят».
     * null означает, что адрес свободен.
     */
    public function emailOwnerId(string $email): ?int
    {
        $found = $this->db->value(
            'SELECT id FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email), 'UTF-8')]
        );

        return $found === null ? null : (int) $found;
    }

    /**
     * Есть ли в системе владелец.
     *
     * Проверяется при регистрации, а не при подтверждении адреса: владение
     * закрепляется сразу за первым зарегистрировавшимся. Поэтому «нет
     * владельца» означает, что в системе пока никто не регистрировался, а не
     * «есть записи без подтверждения».
     */
    public function ownerExists(): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE is_owner = 1 LIMIT 1') !== null;
    }

    /**
     * @return list<string>
     */
    public function rolesOf(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT r.code
               FROM user_roles ur
               JOIN roles r ON r.code = ur.role_code
              WHERE ur.user_id = :id
           ORDER BY r.position',
            ['id' => $userId]
        );

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /**
     * @param array<string,mixed> $row
     */
    public function hydrate(array $row): User
    {
        return User::fromRow($row, $this->rolesOf((int) $row['id']));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('users', $this->withNameParts($data) + [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('users', $this->withNameParts($data, $id), ['id' => $id]);
    }

    /**
     * Держит составное ФИО и его части в согласованном состоянии.
     *
     * Правило одно, на все записи, поэтому обойти его нельзя:
     *
     *   * переданы части имени — full_name собирается из них;
     *   * передан только full_name — части разбираются из него;
     *   * передано и то и то — собирается из частей, они главнее.
     *
     * Иначе получилось бы, что «Иванов Иван» в одном экране и «Петрова Мария»
     * в другом ссылаются на одного человека, а сортировка по фамилии
     * работала бы через раз.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function withNameParts(array $data, ?int $userId = null): array
    {
        $hasParts = array_key_exists('last_name', $data)
            || array_key_exists('first_name', $data)
            || array_key_exists('middle_name', $data);

        if (!$hasParts) {
            if (array_key_exists('full_name', $data) && is_string($data['full_name'])) {
                return $data + self::splitName($data['full_name']);
            }

            return $data;
        }

        $current = $userId === null ? [] : ($this->findRow($userId) ?? []);

        $last = $this->part($data, 'last_name', $current);
        $first = $this->part($data, 'first_name', $current);
        $middle = $this->part($data, 'middle_name', $current);

        /*
         * Присваивание, а не $data + [...]: оператор оставил бы в $data исходное
         * значение с необрезанными пробелами, и нормализация из $last попала бы
         * только в full_name, а части остались бы грязными.
         */
        $data['last_name'] = $last;
        $data['first_name'] = $first;
        $data['middle_name'] = $middle;
        $data['full_name'] = self::composeName($last, $first, $middle);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $current
     */
    private function part(array $data, string $key, array $current): ?string
    {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
        } else {
            $value = $current[$key] ?? null;
        }

        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * ФИО одной строкой из частей.
     *
     * Пустые части пропускаются: без отчества обходятся, а «Петрова Мария  »
     * с двумя пробелами на конце превратилось бы в ФИО с хвостом, который
     * потом путается при сортировке и при поиске по имени.
     */
    public static function composeName(?string $last, ?string $first, ?string $middle): string
    {
        return trim(preg_replace(
            '/\s+/u',
            ' ',
            implode(' ', array_filter([$last, $first, $middle], static fn (?string $p): bool => $p !== null && trim($p) !== ''))
        ) ?? '');
    }

    /**
     * Разбор составного ФИО на части.
     *
     * Порядок прежний: фамилия, имя, отчество. Отчество необязательно,
     * имя из одной части оставляет его пустым, а не выдумывает.
     *
     * @return array{last_name:?string,first_name:?string,middle_name:?string}
     */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'last_name'   => $parts[0] ?? null,
            'first_name'  => $parts[1] ?? null,
            'middle_name' => count($parts) > 2 ? $parts[count($parts) - 1] : null,
        ];
    }

/**
 * Назначает роль человеку. Роль одна.
 *
 * Метод принимает одну роль, а не список: пока он принимал список, роль у
 * человека могла оказаться любой, и всё, что считало «ровно одну роль» —
 * список сотрудников, его показатели, заголовок «Роль» на странице человека, —
 * считало это молча и врало. Права завучу и так включают права учителя, а
 * несколько ролей одному человеку нужно было только для того, чтобы обойти
 * это.
 *
 * Прежние строки всё равно удаляются: смена роли это замена, а не добавление.
 */
public function setRole(int $userId, string $role, ?int $grantedBy = null): void
{
$this->db->transaction(function (Database $db) use ($userId, $role, $grantedBy): void {
        $db->run('DELETE FROM user_roles WHERE user_id = :id', ['id' => $userId]);

        // Плейсхолдер :by дважды: при ATTR_EMULATE_PREPARES = false один и тот же
        // именованный параметр нельзя использовать в разных частях запроса.
        $db->run(
            'INSERT INTO user_roles (user_id, role_code, granted_at, granted_by)
             VALUES (:user, :role, NOW(), :by_insert)
             ON DUPLICATE KEY UPDATE granted_at = NOW(), granted_by = :by_update',
            [
                'user'      => $userId,
                'role'      => $role,
                'by_insert' => $grantedBy,
                'by_update' => $grantedBy,
            ]
        );
    });
}

    /**
     * Пользователи с их ролями — для страницы управления.
     *
     * Учеников здесь нет уже потому, что их в таблице `users` больше не
     * осталось: они живут в своей таблице `students`, их заводят в классе,
     * ими занимается завуч, у них своя страница и своя логика паролей.
     * Показывать их рядом с сотрудниками значило бы смешать два разных
     * списка в один, а администратору — решить, что он вправе выдать роль
     * «Учитель» человеку из класса.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithRoles(): array
    {
        $rows = $this->db->all(
            "SELECT u.*, GROUP_CONCAT(ur.role_code ORDER BY r.position SEPARATOR ',') AS role_list
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
          LEFT JOIN roles r ON r.code = ur.role_code
              GROUP BY u.id
           ORDER BY u.full_name"
        );

        return $rows;
    }

    /**
     * Адрес аватара пользователя.
     *
     * Пустая строка означает «аватара нет»: в списке и в шапке на месте
     * картинки показываются инициалы. Хранится адрес файла, а не сам файл.
     */
    public function setAvatarUrl(int $userId, string $url): void
    {
        $this->db->update('users', ['avatar_url' => $url === '' ? null : $url], ['id' => $userId]);
    }

    public function avatarUrl(int $userId): ?string
    {
        $url = $this->db->value('SELECT avatar_url FROM users WHERE id = :id', ['id' => $userId]);

        return $url === null || $url === '' ? null : (string) $url;
    }

    /**
     * Сводка по сотрудникам для верхних блоков страницы пользователей.
     *
     * «Активен» здесь и в колонке статуса значит одно и то же: человек
     * заходил за последний год. Считать это двумя разными способами нельзя —
     * блок и колонка тогда показывали бы разные числа об одном и том же.
     *
     * Ученики не считаются: они заводятся в классе и в управлении пользователями
     * не участвуют.
     *
     * @return array{total:int,total_active:int,teachers:int,teachers_active:int,
     *               head_teachers:int,head_teachers_active:int,without_role:int}
     */
    public function staffStats(): array
    {
        $recent = 'u.last_login_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';

        /*
         * COUNT(DISTINCT ...) защищает сводку от старых записей, где один
         * сотрудник был связан с несколькими ролями и иначе попал бы в счётчик
         * более одного раза.
         *
         * Учеников отсекать больше не нужно: они ушли в свою таблицу
         * `students`, и `users` целиком состоит из сотрудников.
         */
        $row = $this->db->one(
            "SELECT
                COUNT(DISTINCT u.id) AS total,
                COUNT(DISTINCT CASE WHEN {$recent} THEN u.id END) AS total_active,
                COUNT(DISTINCT CASE WHEN ur.role_code = 'teacher' THEN u.id END) AS teachers,
                COUNT(DISTINCT CASE WHEN ur.role_code = 'teacher' AND {$recent} THEN u.id END) AS teachers_active,
                COUNT(DISTINCT CASE WHEN ur.role_code = 'head_teacher' THEN u.id END) AS head_teachers,
                COUNT(DISTINCT CASE WHEN ur.role_code = 'head_teacher' AND {$recent} THEN u.id END) AS head_teachers_active,
                COUNT(DISTINCT CASE WHEN ur.role_code IS NULL THEN u.id END) AS without_role
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id"
        ) ?? [];

        $int = static fn (string $key): int => (int) ($row[$key] ?? 0);

        return [
            'total'                => $int('total'),
            'total_active'         => $int('total_active'),
            'teachers'             => $int('teachers'),
            'teachers_active'      => $int('teachers_active'),
            'head_teachers'        => $int('head_teachers'),
            'head_teachers_active' => $int('head_teachers_active'),
            'without_role'         => $int('without_role'),
        ];
    }

    /**
     * Сотрудники, которым можно назначить роль.
     *
     * @return list<array<string,mixed>>
     */
    public function staffForSelect(): array
    {
        return $this->db->all(
            "SELECT u.id, u.full_name, u.email,
                    GROUP_CONCAT(ur.role_code ORDER BY r.position SEPARATOR ',') AS role_list
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.code = ur.role_code
              WHERE u.status = 'active'
                AND ur.role_code IN ('teacher', 'head_teacher', 'admin')
           GROUP BY u.id
           ORDER BY u.full_name"
        );
    }

    /**
     * @param list<int> $userIds
     * @return array<int,array<string,mixed>>
     */
    public function findMany(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $rows = $this->db->all(
            "SELECT u.*, GROUP_CONCAT(ur.role_code ORDER BY r.position SEPARATOR ',') AS role_list
               FROM users u
          LEFT JOIN user_roles ur ON ur.user_id = u.id
          LEFT JOIN roles r ON r.code = ur.role_code
              WHERE u.id IN ($placeholders)
           GROUP BY u.id",
            $userIds
        );

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }
}
