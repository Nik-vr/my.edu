<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Текущий сотрудник: запись из users плюс его роли и права.
 *
 * Ученик здесь не бывает: у него свой объект `Student` и своя таблица
 * `students`. В шаблонах оба подставляются вместо переменной `$currentUser`,
 * поэтому у Student есть те же `fullName` и `shortName()`.
 *
 * Объект неизменяемый и загружается один раз на запрос.
 */
final class User
{
    /** Размеры шрифта, доступные человеку, и тот, который считается нормальным. */
    public const FONT_SMALL = 'small';
    public const FONT_NORMAL = 'normal';
    public const FONT_LARGE = 'large';

    /** Темы оформления: тёмная — умолчание, светлая — выбор человека. */
    public const THEME_DARK = 'dark';
    public const THEME_LIGHT = 'light';

    /** @param list<string> $roles */
    public function __construct(
        public readonly int $id,
        public readonly ?string $login,
        public readonly ?string $email,
        public readonly string $fullName,
        public readonly bool $isOwner,
        public readonly string $status,
        public readonly array $roles,
        public readonly ?string $emailVerifiedAt = null,
        public readonly ?string $birthDate = null,
        public readonly ?string $vkUrl = null,
        /*
         * Личный размер шрифта: small, normal, large.
         *
         * Защищён от мусора тем же приёмом, что и остальные значения из базы:
         * что не из списка — то нормальный размер. Иначе человек, однажды
         * написавший в поле что попало, навсегда остался бы со страницей без
         * шрифта, и никакого сообщения об этом он бы не увидел.
         */
        public readonly string $fontSize = self::FONT_NORMAL,
        /*
         * Личная тема: dark, light.
         *
         * Хранится на сервере (решение владельца от 24.10.2026, волна E):
         * раньше выбор жил в localStorage браузера и терялся на другом
         * устройстве. Приведение — тем же правилом, что и у размера шрифта:
         * мусор считается тёмной темой, то есть значением по умолчанию.
         */
        public readonly string $theme = self::THEME_DARK,
        /*
         * Отпечаток пароля — не сам хеш и не сам пароль.
         *
         * Нужен сессии: смена пароля должна обрывать все входы человека, а
         * сравнивать для этого нечего — хеш пароля в объект пользователя не
         * кладут специально, чтобы он не оказался в шаблоне. Отпечаток занимает
         * 32 знака, ничего не говорит о пароле и меняется вместе с ним.
         */
        public readonly ?string $passwordStamp = null
    ) {
    }

    /**
     * Размеры шрифта в порядке «от мелкого к крупному».
     *
     * Список нужен форме настроек: она рисует выбор в этом порядке, а не в
     * порядке, в каком значения записаны в коде. Это же — полный список
     * допустимых значений: всё, чего здесь нет, при сохранении отклоняется.
     *
     * @return list<string>
     */
    public static function fontSizes(): array
    {
        return [self::FONT_SMALL, self::FONT_NORMAL, self::FONT_LARGE];
    }

    /**
     * Привести значение из базы или из запроса к одному из допустимых размеров.
     *
     * Отдельная функция, а не проверка в месте чтения: значений три — в базе,
     * в форме и в оболочке страницы, — и если каждое будет приводить по-своему,
     * то расхождение обнаружится только на экране человека.
     */
    public static function fontSizeOf(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::fontSizes(), true) ? $value : self::FONT_NORMAL;
    }

    /**
     * @return list<string> темы в порядке: тёмная, светлая
     */
    public static function themes(): array
    {
        return [self::THEME_DARK, self::THEME_LIGHT];
    }

    /**
     * Привести значение из базы или из запроса к одной из допустимых тем.
     *
     * Та же причина, что и у размера шрифта: значение читается в трёх местах —
     * база, форма профиля, оболочка страницы, — и приведение должно быть
     * одним, иначе форма запомнит одно, а оболочка нарисует другое.
     */
    public static function themeOf(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::themes(), true) ? $value : self::THEME_DARK;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, array $roles): self
    {
        return new self(
            id: (int) $row['id'],
            login: $row['login'] !== null ? (string) $row['login'] : null,
            email: $row['email'] !== null ? (string) $row['email'] : null,
            fullName: (string) $row['full_name'],
            isOwner: (int) ($row['is_owner'] ?? 0) === 1,
            status: (string) $row['status'],
            roles: $roles,
            emailVerifiedAt: !empty($row['email_verified_at']) ? (string) $row['email_verified_at'] : null,
            birthDate: !empty($row['birth_date']) ? (string) $row['birth_date'] : null,
            vkUrl: !empty($row['vk_url']) ? (string) $row['vk_url'] : null,
            fontSize: self::fontSizeOf($row['font_size'] ?? null),
            theme: self::themeOf($row['theme'] ?? null),
            passwordStamp: substr(hash('sha256', (string) ($row['password_hash'] ?? '')), 0, 32)
        );
    }

    /**
     * Дата рождения в виде «12.03.2015» или пустая строка.
     *
     * Формат именно такой: в русской школе дату пишут по-русски, а ISO
     * вид выглядит как служебная отметка.
     */
    public function birthDateRu(): string
    {
        if ($this->birthDate === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->birthDate, $m) !== 1) {
            return '';
        }

        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Подтверждён ли адрес почты. */
    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, Role::capabilitiesFor($this->roles), true);
    }

    /**
     * Обращение по имени: «Николай Юрьевич».
     *
     * Так принято обращаться к учителям в российских школах, поэтому в личном
     * кабинете по умолчанию используется именно этот вид. Фамилия остаётся в
     * полном имени — она нужна в списках и на печатных листах.
     *
     * Части имени разбираются по порядку: фамилия, имя, отчество. Отчество
     * может отсутствовать — тогда обращение состоит только из имени.
     */
    public function shortName(): string
    {
        $parts = $this->nameParts();

        // Части нужны именно три: фамилия, имя, отчество. При двух частях
        // отчества нет, и выдумывать его нельзя — показываем имя. Раньше
        // здесь проверялось «меньше двух», а бралась третья часть, и человек
        // без отчества получал ошибку и обращение с пустым хвостом.
        return match (true) {
            count($parts) >= 3 => $parts[1] . ' ' . $parts[2],
            count($parts) === 2 => $parts[1],
            default             => $this->fullName,
        };
    }

    /**
     * Части имени в порядке: фамилия, имя, отчество.
     *
     * Порядок задаётся формой регистрации и формой регистрации ученика,
     * поэтому разбирать фамилию отдельно не требуется.
     *
     * @return list<string>
     */
    public function nameParts(): array
    {
        $parts = preg_split('/\s+/u', trim($this->fullName), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /**
     * Человекочитаемый список ролей: «Учитель, Администратор».
     */
    public function rolesLabel(): string
    {
        if ($this->roles === []) {
            return '—';
        }

        $titles = array_map(static fn (string $r): string => Role::title($r), $this->roles);

        if ($this->isOwner) {
            $titles[] = 'владелец сайта';
        }

        return implode(', ', $titles);
    }
}