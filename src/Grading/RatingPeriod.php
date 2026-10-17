<?php

declare(strict_types=1);

namespace MyEdu\Grading;

/**
 * Период, за который считается средний балл.
 *
 * Правило владельца: балл считается за тот период, на который была выдана
 * работа. Не за тот, когда ученик её сдал: сдать можно и через месяц после
 * срока, и работа, выданная на неделю, не должна попадать в годовые числа
 * только потому, что её выполнили в марте.
 *
 * Отсюда и всё остальное:
 *
 * - **Повторная выдача — отдельный вклад.** Одна и та же работа, выданная
 *   дважды с разными сроками, попадает в два периода. Иначе правило «за период
 *   выдачи» перестаёт работать: пришлось бы выбирать, какую из двух выдач
 *   считать, и любой выбор был бы произвольным.
 * - **Выдача «навсегда» относится к учебному году.** Срока у неё нет, а период
 *   нужен: без этого часть работ выпадала бы из показателей молча. Учебный год
 *   считается от 1 сентября — так его понимают в школе, и год, в котором мы
 *   находимся, известен без всякой настройки.
 *
 * Границы периода — включительно: выдача «с 1 по 30» целиком попадает и в
 * сентябрь, и в сентябрь-октябрь, и в год. Выдача попадает в период, если её
 * срок с ним пересекается: работа на неделю, выданная 29 сентября, законная
 * и в сентябре, и в учебном году.
 */
final class RatingPeriod
{
    /** За всё время: ни одна выдача не отбрасывается. */
    public const ALL = 'all';

    /** Учебный год: с 1 сентября по 31 августа. */
    public const ACADEMIC_YEAR = 'year';

    /** Календарный месяц: с первого по последнее число. */
    public const MONTH = 'month';

    /** Свои даты. */
    public const DATES = 'dates';

    private function __construct(
        public readonly string $mode,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $label,
        public readonly string $academicYear,
        public readonly string $value
    ) {
    }

    /**
     * Значение, которым этот период выбирается и передаётся обратно в форме.
     *
     * Отдельное поле, а не сборка из границ: границы у месяца и у своих дат
     * выглядят по-разному, а список периодов на странице отдаёт готовые значения.
     * Собирать значение в шаблоне значило бы держать правило отображения сразу
     * в двух местах.
     */
    public function isAll(): bool
    {
        return $this->from === null || $this->to === null;
    }

    /**
     * Период из запроса.
     *
     * Значение приходит одним полем — `month:2026-11`, `year`, `all` — чтобы
     * список периодов и форма отправляли ровно то, что в списке, и не расходились
     * на два поля, которые ещё надо согласовать между собой.
     *
     * Мусор в датах не приводит к отказу, а к выбору безопасного периода:
     * показать пустую таблицу из-за опечатки в одной границе хуже, чем показать
     * данные за год.
     */
    public static function fromRequest(?string $value): self
    {
        $academicYear = self::academicYear();
        [$yearFrom, $yearTo] = self::academicYearRange($academicYear);

        $mode = $value === null ? '' : trim($value);
        $argument = null;

        $colon = strpos($mode, ':');

        if ($colon !== false) {
            $argument = substr($mode, $colon + 1);
            $mode = substr($mode, 0, $colon);
        }

        if ($mode === self::ACADEMIC_YEAR) {
            return new self(self::ACADEMIC_YEAR, $yearFrom, $yearTo, 'Учебный год ' . $academicYear, $academicYear, self::ACADEMIC_YEAR);
        }

        if ($mode === self::MONTH) {
            $timestamp = is_string($argument) && $argument !== ''
                ? strtotime($argument . '-01')
                : false;

            if ($timestamp !== false) {
                $from = date('Y-m-01', $timestamp);
                $to = date('Y-m-t', $timestamp);

                return new self(self::MONTH, $from, $to, self::monthName($timestamp) . ' ' . date('Y', $timestamp), $academicYear, self::MONTH . ':' . date('Y-m', $timestamp));
            }

            return new self(self::ACADEMIC_YEAR, $yearFrom, $yearTo, 'Учебный год ' . $academicYear, $academicYear, self::ACADEMIC_YEAR);
        }

        if ($mode === self::DATES) {
            /*
             * Две даты приходят одним значением через запятую: отдельные поля
             * формы разъезжались бы с одним списком периодов, а ради двух дат
             * отдельная форма — лишний экран.
             */
            $parts = is_string($argument) ? explode(',', $argument) : [];
            $from = self::parseDate($parts[0] ?? null);
            $to = self::parseDate($parts[1] ?? null);

            if ($from !== null && $to !== null && $from <= $to) {
                return new self(self::DATES, $from, $to, 'С ' . $from . ' по ' . $to, $academicYear, self::DATES . ':' . $from . ',' . $to);
            }

            return new self(self::ACADEMIC_YEAR, $yearFrom, $yearTo, 'Учебный год ' . $academicYear, $academicYear, self::ACADEMIC_YEAR);
        }

        return new self(self::ALL, null, null, 'За всё время', $academicYear, self::ALL);
    }

    /**
     * Периоды для выбора на странице.
     *
     * Месяцы берутся не от сегодняшнего, а от начала учебного года: иначе в
     * сентябре в списке не оказалось бы месяцев будущего, а в июне — месяцев
     * учебного года позади. Порядок — от свежего к старому.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function presets(): array
    {
        $academicYear = self::academicYear();
        [$yearFrom] = self::academicYearRange($academicYear);

        $presets = [
            ['value' => self::ACADEMIC_YEAR, 'label' => 'Учебный год ' . $academicYear],
            ['value' => self::ALL, 'label' => 'За всё время'],
        ];

        $start = strtotime($yearFrom) ?: time();
        $month = strtotime('first day of this month', strtotime('-1 month', $start));

        for ($i = 0; $i < 12; $i++) {
            $presets[] = [
                'value' => self::MONTH . ':' . date('Y-m', $month),
                'label' => self::monthName($month) . ' ' . date('Y', $month),
            ];

            $month = strtotime('-1 month', $month);
        }

        return $presets;
    }

    /**
     * Учебный год в виде «2026-2027».
     *
     * Считается, а не хранится: год начинается 1 сентября, и настройки для
     * него в системе нет. Если у школы год начинается не 1 сентября, счёт
     * периодов сместится на месяц — это заметно и правится, но отдельная
     * настройка здесь стоила бы дороже, чем несоответствие в нестандартном
     * календаре.
     */
    public static function academicYear(?string $today = null): string
    {
        $date = $today !== null ? strtotime($today) : time();
        $date = $date === false ? time() : $date;
        $year = (int) date('Y', $date);

        if ((int) date('n', $date) < 9) {
            $year--;
        }

        return $year . '-' . ($year + 1);
    }

    /**
     * @return array{0:string,1:string} с 1 сентября по 31 августа
     */
    public static function academicYearRange(string $academicYear): array
    {
        $start = (int) explode('-', $academicYear)[0];

        return [$start . '-09-01', ($start + 1) . '-08-31'];
    }

    /**
     * Условие SQL: выдача попадает в этот период.
     *
     * Две части — обычные выдачи и «навсегда». Вторая сравнивается с границами
     * учебного года, а не с границами периода: если период — сентябрь, работа
     * «навсегда» в него попадёт, потому что учебный год её покрывает.
     *
     * Границы периода — включительно, поэтому срок внутри периода попадает в
     * него целиком: работа «с 29 сентября по 5 октября» законна и в сентябре, и
     * в октябре, и в учебном году.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public function issueSql(string $alias = 'i'): array
    {
        if ($this->isAll()) {
            return ['1 = 1', []];
        }

        $from = (string) $this->from;
        $to = (string) $this->to;
        [$yearFrom, $yearTo] = self::academicYearRange($this->academicYear);

        /*
         * Каждый параметр встречается в условии ровно один раз, и имена у них
         * разные: при отключённой эмуляции prepares повтор одного и того же
         * именованного параметра MySQL не принимает, и запрос падает с
         * «Invalid parameter number» — в самом начале отчёта и по причине,
         * которая в тексте запроса не видна.
         */
        $sql = '(('
            // Обычная выдача: срок есть и пересекается с периодом.
            . $alias . '.`available_from` IS NOT NULL AND ' . $alias . '.`available_until` IS NOT NULL'
            . ' AND ' . $alias . '.`available_until` >= :rating_issue_from'
            . ' AND ' . $alias . '.`available_from`   <= :rating_issue_to'
            // Выдача «навсегда»: срока нет, период определяется учебным годом.
            . ') OR ((' . $alias . '.`available_from` IS NULL OR ' . $alias . '.`available_until` IS NULL)'
            . ' AND :rating_year_to >= :rating_period_from'
            . ' AND :rating_year_from <= :rating_period_to'
            . '))';

        return [
            $sql,
            [
                'rating_period_from' => $from,
                'rating_period_to' => $to,
                'rating_issue_from' => $from,
                'rating_issue_to' => $to,
                'rating_year_from' => $yearFrom,
                'rating_year_to' => $yearTo,
            ],
        ];
    }

    private static function parseDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private static function monthName(int $timestamp): string
    {
        $months = [
            1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
        ];

        return $months[(int) date('n', $timestamp)] ?? '';
    }
}