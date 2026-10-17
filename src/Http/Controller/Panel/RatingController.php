<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\View;
use MyEdu\Grading\RatingPeriod;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Repository\RatingRepository;

/**
 * Средний балл и рейтинги.
 *
 * Считается за период, на который была выдана работа, — по тем же попыткам,
 * что и журнал, но сводкой. Правила периода живут в `RatingPeriod`, что считать
 * попыткой — в `RatingRepository`; здесь только форма и раскладка.
 *
 * Чужие работы видны тем же, кому и в «Просмотре результатов»: администратору
 * все, остальным свои. Иначе сводка по классу собирала бы половину работ школы
 * из одного учительского аккаунта и выглядела бы полной.
 */
final class RatingController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly RatingRepository $ratings
    ) {
    }

    public function index(Request $request): Response
    {
        $period = RatingPeriod::fromRequest($request->queryParam('period'));
        $authorId = $this->auth->can(Role::USERS_MANAGE) ? 0 : (int) ($this->auth->user()?->id ?? 0);

        $byClass = $this->ratings->byClass($period, $authorId);
        $classId = (int) $request->queryParam('class', '0');

        /*
         * Класс с учениками выбирается из того, что попал в отчёт: класс, у
         * которого за период нет ни одной проверенной работы, показать нечего,
         * и молчаливая пустая таблица выглядела бы ошибкой.
         */
        if ($classId <= 0 && $byClass !== []) {
            $classId = (int) ($byClass[0]['class_id'] ?? 0);
        }

        return $this->render('panel/ratings', [
            'title'    => 'Средний балл',
            'period'   => $period,
            'presets'  => RatingPeriod::presets(),
            'overall'  => $this->ratings->overall($period, $authorId),
            'classes'  => $byClass,
            'subjects' => $this->ratings->bySubject($period, $authorId),
            'classId'  => $classId,
            'students' => $classId > 0 ? $this->ratings->studentsOfClass($classId, $period, $authorId) : [],
            'own'      => $authorId > 0,
        ]);
    }
}