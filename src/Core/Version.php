<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Номер версии и его сравнение.
 *
 * Версия в системе пишется словами («0.3 beta»), а в GitHub лежит тегом
 * («0.3-beta»). Сравнивать их как строки нельзя: «0.10 beta» по алфавиту
 * меньше «0.9 beta», а по смыслу — больше, и обновление никогда бы не
 * предложилось либо предложилось не то.
 *
 * Правило разбора версии задано владельцем (см. `config/config.php`):
 * первая цифра — сама система, вторая — серьёзные изменения, третья — правки
 * без изменения логики, а alfa/beta/rc — насколько сборка готова.
 */
final class Version
{
    /**
     * Насколько сборка готова, от худшей к лучшей.
     *
     * Без слова о готовности версия считается выпущенной: «0.3» — это релиз,
     * и он новее «0.3 beta».
     *
     * @var array<string,int>
     */
    private const STAGES = [
        'alfa'  => 0,
        'alpha' => 0,
        'beta'  => 1,
        'rc'    => 2,
        ''      => 3,
    ];

    /**
     * Разобрать номер версии.
     *
     * @return array{numbers:list<int>,stage:string}|null null — если это не версия
     */
    public static function parse(string $value): ?array
    {
        $normalized = strtolower(trim($value));
        $normalized = ltrim($normalized, 'v');

        if ($normalized === '') {
            return null;
        }

        /*
         * Номер и слово о готовности разбираются отдельно: в записи «0.3 beta»
         * они разделены пробелом или дефисом, а иногда слитны («0.3beta»).
         */
        $stage = '';
        $numbers = '';

        foreach (['alfa', 'alpha', 'beta', 'rc'] as $word) {
            if (str_contains($normalized, $word)) {
                $stage = $word;
                $normalized = str_replace($word, '', $normalized);

                break;
            }
        }

        foreach (str_split($normalized) as $char) {
            if ($char >= '0' && $char <= '9' || $char === '.') {
                $numbers .= $char;
            }
        }

        $numbers = trim($numbers, '.');

        if ($numbers === '') {
            return null;
        }

        $parts = [];

        foreach (explode('.', $numbers) as $part) {
            $parts[] = (int) $part;
        }

        return ['numbers' => $parts, 'stage' => $stage];
    }

    /**
     * Сравнить две версии.
     *
     * -1 — первая меньше, 0 — равны, 1 — первая больше.
     */
    public static function compare(string $left, string $right): int
    {
        $a = self::parse($left);
        $b = self::parse($right);

        if ($a === null || $b === null) {
            /*
             * Неразобранный номер сравнить не с чем: возвращаем «равны», чтобы
             * обновление не предлагалось на пустом месте и не ломалось о
             * версиях незнакомого вида.
             */
            return 0;
        }

        $length = max(count($a['numbers']), count($b['numbers']));

        for ($i = 0; $i < $length; $i++) {
            $x = $a['numbers'][$i] ?? 0;
            $y = $b['numbers'][$i] ?? 0;

            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        $stageA = self::STAGES[$a['stage']] ?? 3;
        $stageB = self::STAGES[$b['stage']] ?? 3;

        return $stageA <=> $stageB;
    }

    /**
     * Новее ли версия-кандидат текущей.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        return self::compare($candidate, $current) > 0;
    }

    /**
     * Старше ли версия-кандидат текущей.
     *
     * Отдельный метод, а не сравнение с нулём: неразобранный номер не равен
     * текущему, он с ним несравним. Сравнение через `compare` вернуло бы для
     * такой версии «равны», и «старее» означало бы враньё.
     */
    public static function isOlder(string $candidate, string $current): bool
    {
        if (self::parse($candidate) === null || self::parse($current) === null) {
            return false;
        }

        return self::compare($candidate, $current) < 0;
    }

    /**
     * Название версии в виде, в котором её показывают.
     *
     * «0.3-beta» → «0.3 beta»: то же самое, что пишется в настройках.
     */
    public static function display(string $value): string
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            return trim($value);
        }

        $numbers = implode('.', $parsed['numbers']);

        return $parsed['stage'] === '' ? $numbers : $numbers . ' ' . $parsed['stage'];
    }
}
