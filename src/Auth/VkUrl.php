<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Ссылка на профиль ВКонтакте.
 *
 * Отдельный класс, а не метод контроллера, потому что правило одно и то же
 * для двух страниц: человек правит свою ссылку сам, администратор — чужую.
 * Расхождение в проверке было бы незаметно: одна страница приняла бы ссылку
 * на чужой сайт, а другая нет, и человек не понял бы почему.
 *
 * Почему хранится ссылка на профиль, а не ник: почта и телефон у школы
 * закрыты, а страница «О себе» видна тем, кому и положено.
 */
final class VkUrl
{
    private const HOSTS = ['vk.com', 'vk.ru'];

    /**
     * Приводит ссылку к виду https://vk.com/<профиль>.
     *
     * Короткие адреса вида vk.ru/durov приводятся к vk.com/durov: иначе
     * получилось бы две ссылки на один профиль, и при последующей правке
     * одна из них не находилась бы.
     *
     * null означает «это не профиль ВКонтакте». Проверяется и хост, и путь:
     * ссылка на vk.com/id1/photo в профиль не годится, и принимать её молча
     * значило бы показать человеку картинку вместо своей страницы.
     */
    public static function normalize(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);

        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');

        // Профиль — это «<ник>» или «<ник>_<id>» без других сегментов.
        if ($path === '' || str_contains($path, '/')) {
            return null;
        }

        return 'https://vk.com/' . $path;
    }
}
