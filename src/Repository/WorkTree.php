<?php

declare(strict_types=1);

namespace MyEdu\Repository;

/**
 * Дерево работ по папкам параллелей.
 *
 * Папка «8 классы» собирает работы, у которых параллель 8, и ничего больше:
 * параллель у работы — справочная подпись, а не адресация. Ученика из 7-го
 * класса работа из папки «8 классы» не касается — её выдают лично, отдельной
 * выдачей.
 *
 * Построитель один на обе страницы, где показывается дерево: список работ и
 * первый шаг мастера выдачи. Два построителя разошлись бы за правку — на
 * одной странице папка называлась бы иначе или иначе сортировалась, и учитель
 * выбрал бы не то, что только что видел.
 *
 * Работы без параллели собираются в отдельной папке: они есть почти всегда —
 * работа, начатая до того, как про параллели заговорили, — и прятать их среди
 * чужих нельзя, потерялось бы.
 */
final class WorkTree
{
    /** Границы параллели: ниже первого и выше последнего школа не бывает. */
    private const FIRST = 1;
    private const LAST = 11;

    /**
     * @param list<array<string,mixed>> $assignments
     * @param string|null                $state drafts, published или null — все вместе.
     *                                          Разделение по состоянию стоит
     *                                          выше папок, а не внутри их: черновик
     *                                          и готовая работа одной параллели —
     *                                          это разные списки, и смешанные в
     *                                          одной папке они читались бы как
     *                                          список готовых.
     *
     * @return list<array{parallel:int|null,label:string,items:list<array<string,mixed>>}>
     */
    public static function build(array $assignments, ?string $state = null): array
    {
        $folders = [];

        foreach ($assignments as $assignment) {
            if ($state !== null && !self::inState($assignment, $state)) {
                continue;
            }

            $parallel = self::parallelOf($assignment);

            $folders[$parallel === null ? 0 : $parallel][] = $assignment;
        }

        // Числовые папки идут по возрастанию, «Без параллели» — в конце: она
        // не про порядок обучения, а про то, что автор не указал параллель.
        ksort($folders, SORT_NUMERIC);

        $tree = [];

        foreach ($folders as $parallel => $items) {
            $parallel = $parallel === 0 ? null : (int) $parallel;

            $tree[] = [
                'parallel' => $parallel,
                'label'    => self::label($parallel),
                'items'    => array_values($items),
            ];
        }

        return $tree;
    }

    /**
     * Название папки параллели.
     *
     * Публичный метод, а не только внутренний: то же название нужно значку в
     * «Просмотре результатов». Склонение, посчитанное там своим скриптом,
     * рано или поздно разошлось бы с папкой — и на одной странице параллель
     * называлась бы «8 классы», а на другой «8 классов».
     */
    public static function label(?int $parallel): string
    {
        if ($parallel === null) {
            return 'Без параллели';
        }

        $word = match (true) {
            $parallel === 1 => 'класс',
            $parallel >= 2 && $parallel <= 4 => 'класса',
            default => 'классы',
        };

        return $parallel . ' ' . $word;
    }

    /**
     * Работа входит в раздел состояния.
     *
     * Закрытая работа попадает в «опубликованные», а не в третью вкладку:
     * закрытая работа опубликована была и к ученикам ушла, отличается лишь тем,
     * что новых попыток больше не принимает. Её в «черновиках» показывать
     * нельзя — она не черновик.
     *
     * @param array<string,mixed> $assignment
     */
    private static function inState(array $assignment, string $state): bool
    {
        $status = (string) ($assignment['status'] ?? 'draft');

        return $state === 'drafts'
            ? $status === 'draft'
            : $status !== 'draft';
    }

    /**
     * Параллель работы или null.
     *
     * Значение вне границ считается отсутствующим: мусор в поле не должен
     * заводить папку «0 классы» или «255 классы», которые ничего не объясняют
     * автору и тихо уводят его работу из списка.
     *
     * @param array<string,mixed> $assignment
     */
    public static function parallelOf(array $assignment): ?int
    {
        $raw = $assignment['parallel'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $parallel = (int) $raw;

        return ($parallel < self::FIRST || $parallel > self::LAST) ? null : $parallel;
    }
}