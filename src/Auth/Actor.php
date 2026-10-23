<?php

declare(strict_types=1);

namespace MyEdu\Auth;

/**
 * Кто выполняет действие: ученик или сотрудник.
 *
 * Ученик и сотрудник — записи разных таблиц с независимой нумерацией, поэтому
 * одного числа для них не существует: «номер 7» означает разное в зависимости
 * от того, о ком речь. Везде, где нужен человек — начало работы, проверка,
 * «своя ли это работа», — вместо голого int передаётся этот объект, и колонка,
 * в которую он попадает, выбирается сама.
 *
 * Устроен как запертая пара: сконструировать можно только через ofStudent()
 * или ofStaff(), и ровно одна из двух идентичностей всегда заполнена. Смешать
 * их нельзя даже по невнимательности — вторая колонка останется null.
 */
final class Actor
{
    private function __construct(
        public readonly ?int $studentId,
        public readonly ?int $staffId
    ) {
    }

    public static function ofStudent(int $studentId): self
    {
        return new self($studentId, null);
    }

    public static function ofStaff(int $staffId): self
    {
        return new self(null, $staffId);
    }

    public function isStudent(): bool
    {
        return $this->studentId !== null;
    }

    /**
     * Отпечаток для подстановки в запрос: `student:7` или `staff:7`.
     *
     * Нужен журналу и плагинам, где человека держат одной строкой, а не двумя
     * колонками.
     */
    public function label(): string
    {
        return $this->studentId !== null
            ? 'student:' . $this->studentId
            : 'staff:' . $this->staffId;
    }
}
