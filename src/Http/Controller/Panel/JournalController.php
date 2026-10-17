<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\ClassRepository;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Repository\AssignmentRepository;

/**
 * Журнал работ: что сдали ученики и с каким результатом.
 *
 * Журнал отвечает на вопрос «что сдали ученики этого класса», а не «что задано
 * этому классу»: работа выдаётся поимённо ученикам, и класса как адресата у
 * неё больше нет.
 *
 * Здесь же учитель смотрит глазами ученика, что было выдано: оценка за
 * чужую работу без этого экрана была бы догадкой.
 */
final class JournalController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Auth $auth,
        private readonly ClassRepository $classes,
        private readonly AssignmentRepository $assignments
    ) {
    }

    /**
     * Список журналов: по одному на класс.
     */
    public function index(Request $request): Response
    {
        return $this->render('panel/journal_index', [
            'title'  => 'Журнал работ',
            'rows'   => $this->assignments->journalSummary(),
            'notice' => $this->session->pull('flash_notice'),
            'error'  => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Журнал одного класса.
     *
     * Класс проверяется на существование, а не на пустоту: пустой класс —
     * тоже результат, и молчаливая пустая страница сбила бы с толку.
     */
    public function show(Request $request, array $params): Response
    {
        $classId = $this->intParam($params, 'id');
        $class = $this->classes->find($classId);

        if ($class === null) {
            throw new HttpException(404, 'Класс не найден');
        }

        $rows = $this->assignments->journalByClass($classId);

        $students = [];

        foreach ($rows as $row) {
            $studentId = (int) $row['id'];

            $students[] = $row + [
                'attempts' => $this->assignments->journalForStudent($studentId, $classId),
            ];
        }

        return $this->render('panel/journal_class', [
            'title'      => 'Журнал: ' . (string) $class['name'],
            'class'      => $class,
            'students'   => $students,
            'total'      => $this->totals($students),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Итоги по классу.
     *
     * Средний процент и средняя оценка считаются по ученикам, а не по
     * попыткам: иначе класс из трёх человек, один из которых пересдавал пять
     * раз, выглядел бы активнее остальных.
     *
     * @param list<array<string,mixed>> $students
     * @return array<string,mixed>
     */
    private function totals(array $students): array
    {
        $percents = [];
        $grades = [];
        $finished = 0;
        $review = 0;
        $attempts = 0;

        foreach ($students as $student) {
            $attempts += (int) $student['attempt_count'];
            $finished += (int) $student['finished_count'];
            $review += (int) $student['review_count'];

            if ($student['avg_percent'] !== null) {
                $percents[] = (float) $student['avg_percent'];
            }

            if ($student['avg_grade'] !== null) {
                $grades[] = (float) $student['avg_grade'];
            }
        }

        return [
            'students'     => count($students),
            'attempts'     => $attempts,
            'finished'     => $finished,
            'review'       => $review,
            'avg_percent'  => $percents === [] ? null : round(array_sum($percents) / count($percents), 2),
            'avg_grade'    => $grades === [] ? null : round(array_sum($grades) / count($grades), 2),
            'without_work' => count(array_filter(
                $students,
                static fn (array $s): bool => (int) $s['attempt_count'] === 0
            )),
        ];
    }
}
