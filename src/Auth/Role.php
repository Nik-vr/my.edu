<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Роли и права доступа.
 *
 * Права описаны кодом, а не записями в базе: их мало, они меняются только
 * вместе с кодом, и разбор прав должен быть виден без запросов в базу.
 * В базе лежит только название и описание роли — для интерфейса.
 *
 * Пользователю назначается одна роль. Старые записи с несколькими ролями
 * остаются совместимыми: capabilitiesFor() по-прежнему объединяет их права,
 * пока администратор не назначит новую роль через UserRepository::setRole().
 */
final class Role
{
    public const OBSERVER = 'observer';
    public const STUDENT = 'student';
    public const TEACHER = 'teacher';
    public const HEAD_TEACHER = 'head_teacher';
    public const ADMIN = 'admin';

    // Права. Имена соответствуют проверкам в роутере.
    public const TESTS_TAKE = 'tests.take';
    public const TESTS_REPEAT = 'tests.repeat';
    public const TESTS_MANAGE = 'tests.manage';
    public const CLASSES_MANAGE = 'classes.manage';
    public const CLASS_STUDENTS = 'class.students';
    public const REPORTS_VIEW = 'reports.view';
    public const USERS_MANAGE = 'users.manage';
    public const ROLES_ASSIGN = 'roles.assign';
    public const PANEL_ACCESS = 'panel.access';

    /** @var array<string,list<string>> */
    private const CAPABILITIES = [
        // Наблюдатель зарегистрировался и смотрит свои данные.
        self::OBSERVER => [],

        // Ученик проходит тесты.
        self::STUDENT => [self::TESTS_TAKE],

        // Учитель ведёт свои тесты и может проходить их сколько угодно раз:
        // ограничение по числу попыток для учеников, а не для проверяющего.
        self::TEACHER => [self::TESTS_TAKE, self::TESTS_REPEAT, self::TESTS_MANAGE],

        // Завуч ведёт классы, предметы, учителей и регистрирует учеников.
        self::HEAD_TEACHER => [
            self::TESTS_TAKE,
            self::TESTS_REPEAT,
            self::TESTS_MANAGE,
            self::CLASSES_MANAGE,
            self::CLASS_STUDENTS,
            self::REPORTS_VIEW,
            self::PANEL_ACCESS,
        ],

        // Администратор управляет пользователями и сайтом.
        self::ADMIN => [
            self::TESTS_TAKE,
            self::TESTS_REPEAT,
            self::TESTS_MANAGE,
            self::CLASSES_MANAGE,
            self::CLASS_STUDENTS,
            self::REPORTS_VIEW,
            self::PANEL_ACCESS,
            self::USERS_MANAGE,
            self::ROLES_ASSIGN,
        ],
    ];

    /** @return list<string> права роли */
    public static function capabilitiesOf(string $role): array
    {
        return self::CAPABILITIES[$role] ?? [];
    }

    /**
     * Права для сохранённого списка ролей.
     *
     * Новые назначения содержат одну роль; объединение сохраняет безопасную
     * совместимость со старыми записями БД, где могло быть несколько ролей.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public static function capabilitiesFor(array $roles): array
    {
        $capabilities = [];

        foreach ($roles as $role) {
            foreach (self::capabilitiesOf($role) as $capability) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    /**
     * Роли, доступные для назначения через интерфейс.
     *
     * Ученика не назначают через «управление пользователями»: его заводит
     * завуч или администратор при регистрации учеников класса.
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        return [self::OBSERVER, self::TEACHER, self::HEAD_TEACHER, self::ADMIN];
    }

    public static function title(string $role): string
    {
        return match ($role) {
            self::OBSERVER     => 'Наблюдатель',
            self::STUDENT      => 'Ученик',
            self::TEACHER      => 'Учитель',
            self::HEAD_TEACHER => 'Завуч',
            self::ADMIN        => 'Администратор',
            default            => $role,
        };
    }
}
