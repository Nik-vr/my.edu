<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Ученик: запись из таблицы `students`.
 *
 * Ученик — не пользователь системы. У него нет почты, подтверждения адреса и
 * ролей, поэтому у него собственный объект, а не User с ролью student. В
 * шаблонах он подставляется вместо сотрудника (`$currentUser`), и чтобы шаблоны
 * не различали двух людей, у него есть всё, что они спрашивают: `fullName`,
 * `shortName()`, `hasCapability()` и `rolesLabel()`. Без последних двух главная
 * страница падала 500 — она зовёт их, не зная, кто перед ней стоит.
 *
 * Как и у сотрудника, сессия держит только идентификатор и отпечаток пароля:
 * роли у ученика нет, но смена пароля обязана обрывать все его входы.
 */
final class Student
{
    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $fullName,
        public readonly string $status,
        public readonly ?int $classId = null,
        /**
         * Личный размер шрифта: small, normal, large.
         *
         * Приводится к допустимым значениям тем же правилом, что и у
         * сотрудника: всё, что не из списка, — нормальный размер.
         */
        public readonly string $fontSize = User::FONT_NORMAL,
        /**
         * Личная тема: dark, light — приводится тем же правилом, что и у
         * сотрудника: всё, что не из списка, — тёмная тема.
         *
         * Хранится на сервере (решение владельца от 24.10.2026, волна E):
         * выбор живёт в профиле ученика и не зависит от браузера.
         */
        public readonly string $theme = User::THEME_DARK,
        public readonly ?string $passwordStamp = null
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            login: (string) $row['login'],
            fullName: (string) $row['full_name'],
            status: (string) $row['status'],
            classId: isset($row['class_id']) && $row['class_id'] !== null ? (int) $row['class_id'] : null,
            fontSize: User::fontSizeOf($row['font_size'] ?? null),
            theme: User::themeOf($row['theme'] ?? null),
            passwordStamp: substr(hash('sha256', (string) ($row['password_hash'] ?? '')), 0, 32)
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Единственное право ученика — сдавать работы.
     *
     * Держится здесь же, что и в `Auth::can()`: шаблоны спрашивают
     * `$currentUser->hasCapability(...)`, не зная, сотрудник перед ними или
     * ученик, и без этого метода главная страница падала бы 500 на первом же
     * обращении. Право сдачи — то, ради чего ученика вообще завели, поэтому оно
     * следует из самой личности, а не из записи в `user_roles`.
     *
     * Всё остальное ученику закрыто тем же порядком, что и гостю: другие права
     * у него нет, и подделать их нечем — ролей не существует вовсе.
     */
    public function hasCapability(string $capability): bool
    {
        return $capability === Role::TESTS_TAKE;
    }

    /**
     * Подпись под именем: «Ученик».
     *
     * У сотрудника здесь список ролей, у ученика — постоянное слово: ему нечего
     * перечислять, и прочерк на месте имени читался бы как поломка. Значение
     * то же, что раньше отдавала `Role::title('student')`, — только теперь оно
     * живёт здесь, где и живёт ученик.
     */
    public function rolesLabel(): string
    {
        return 'Ученик';
    }

    /**
     * Обращение по имени: «Мария».
     *
     * Порядок частей тот же, что у сотрудников: фамилия, имя, отчество. У
     * ученика без отчества и без имени остаётся фамилия — выдумывать её
     * нельзя, поэтому при одной части берётся полное имя целиком.
     */
    public function shortName(): string
    {
        $parts = $this->nameParts();

        return count($parts) >= 2 ? $parts[1] : $this->fullName;
    }

    /**
     * Части имени в порядке: фамилия, имя, отчество.
     *
     * @return list<string>
     */
    public function nameParts(): array
    {
        $parts = preg_split('/\s+/u', trim($this->fullName), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
