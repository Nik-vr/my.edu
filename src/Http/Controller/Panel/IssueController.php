<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\WorkTree;

/**
 * Мастер выдачи работ.
 *
 * Работа ни к чему не привязана: в самой работе ни класса, ни параллели как
 * адресата нет. Кому она достанется, решает этот мастер — работа, классы,
 * ученики, срок. Ученик видит ровно то, что ему выдали, и только пока срок не
 * истёк.
 *
 * Четыре шага, а не одна форма: список работ большой, учеников бывает много,
 * и решение «кому» принимается в последний момент — когда автор уже видит
 * фамилии. Один длинный экран заставлял бы прокручивать список работ, чтобы
 * добраться до галочек, и выбирать класс, не видя, кто в нём.
 *
 * Шаг выбирается адресом, а не хранится в сессии: мастер не должен ломаться от
 * того, что человек закрыл вкладку и вернулся завтра, и адрес шага можно
 * переслать другому.
 */
final class IssueController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Database $db,
        private readonly AssignmentRepository $assignments
    ) {
    }

/**
 * Просмотр результатов: список выдач.
     *
     * Раздел заменил «Ждут проверки». Раньше там стоял список попыток, которые
     * ждут оценки: туда попадало ровно то, что уже сделано и не проверено, а
     * всё остальное — выданное, выполненное и проверенное — было не видно
     * вообще. Теперь список выдач, и по каждой сводка по всем, кому работа
     * доставалась: видно не только «кого надо посмотреть», но и «кого уже
     * посмотрели» и «кому работа вообще не досталась».
     */
    public function index(Request $request): Response
    {
        // Завуч видит выдачи всех учителей, учитель — свои.
        $authorId = $this->auth->can(Role::USERS_MANAGE) ? 0 : $this->authorId();

        return $this->render('panel/results', [
            'title'   => 'Просмотр результатов',
            'issues'  => $this->assignments->issues($authorId),
            'notice'  => $this->session->pull('flash_notice'),
            'error'   => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Шаг 1: какую работу выдаём.
     *
     * Работы показываются тем же деревом по папкам параллелей, что и на
     * странице «Работы»: учитель ищет нужную там же, где и всегда искал, и
     * два разных списка означали бы, что он помнит, какой где.
     *
     * Черновики не предлагаются: выдать работу, которую автор ещё не
     * опубликовал, нельзя — ученик её не увидит, и выдача молча пропадёт.
     */
    public function start(Request $request): Response
    {
        $authorId = $this->authorId();
        $seesAll = $this->auth->can(Role::USERS_MANAGE);

        $rows = $seesAll
            ? $this->assignments->allWithAuthor()
            : $this->assignments->allByAuthor($authorId);

        $published = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) $row['status'] === 'published'
        ));

        return $this->render('panel/issue_start', [
            'title'         => 'Выдать работу',
            'tree'          => WorkTree::build($published),
            'total'         => count($published),
            'seesAll'       => $seesAll,
            'currentUserId' => $this->authorId(),
            // Работу можно начать выдавать прямо из списка работ: выбор
            // сохраняется адресом, и первый шаг открывается уже с отмеченной
            // строкой, а не с пустого.
            'chosen'        => max(0, (int) $request->queryParam('assignment', '0')),
            'notice'        => $this->session->pull('flash_notice'),
            'error'         => $this->session->pull('flash_error'),
        ]);
    }

    /**
 * Шаг 1 отправлен: выбранная работа уводит на выбор классов.
     *
     * Отдельное действие, а не переход по ссылке из строки: строка
     * выбирается кружком, и без этого серверного шага форма отправилась бы
     * обратно сама в себя, и мастер топтался бы на первом шаге.
     */
    public function choose(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = (int) $request->postParam('assignment', '0');

        if ($id <= 0) {
            $this->session->set('flash_error', 'Выберите работу');

            return $this->redirect('panel/issues/new');
        }

        // Проверка опубликованности и прав — та же, что на следующих шагах.
        $this->requirePublishedAssignment($id);

        return $this->redirect('panel/issues/' . $id . '/classes');
    }

    /**
     * Шаг 2: каким классам.
     *
     * Классы показываются все, а не только той параллели, что указана в
     * работе: параллель у работы — подпись для папки, а не адресация, и
     * ограничение классами той же параллели было бы адресацией, которой здесь
     * быть не должно.
     */
    public function classes(Request $request, array $params): Response
    {
        $assignment = $this->requirePublishedAssignment((int) ($params['id'] ?? 0));

        $selected = $this->listFromQuery($request, 'classes');

        return $this->render('panel/issue_classes', [
            'title'     => 'Выдать работу',
            'assignment' => $assignment,
            'classes'   => $this->allClasses(),
            'selected'  => $selected,
            'notice'    => $this->session->pull('flash_notice'),
            'error'     => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Шаг 3: кому именно.
     *
     * Ученики выводятся по порядку классов и фамилий, а не по дате вступления:
     * список читают сверху вниз и ставят галочки, и случайный порядок заставлял
     * бы искать глазами.
     *
     * Отметка по умолчанию снята. Отмеченные сразу все — опаснее пустого
     * списка: выдача полутора десяткам учеников там, где учитель хотел спросить
     * троих, остаётся незаметной, пока кто-то не начнёт выполнять чужую работу.
     */
    public function students(Request $request, array $params): Response
    {
        $assignment = $this->requirePublishedAssignment($this->intParam($params, 'id'));
        $classIds = $this->classIdsFrom($request);

        if ($classIds === []) {
            $this->session->set('flash_error', 'Выберите хотя бы один класс');

            return $this->redirect('panel/issues/' . (int) $assignment['id'] . '/classes');
        }

        $students = $this->studentsOfClasses($classIds);

        if ($students === []) {
            $this->session->set(
                'flash_error',
                'В выбранных классах нет учеников: заведите их в «Классах и учениках»'
            );

            return $this->redirect('panel/issues/' . (int) $assignment['id'] . '/classes');
        }

        return $this->render('panel/issue_students', [
            'title'      => 'Выдать работу',
            'assignment' => $assignment,
            'classes'    => $this->allClasses(),
            'classIds'   => $classIds,
            'students'   => $students,
            'issued'     => $this->alreadyIssued((int) $assignment['id']),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }

/**
     * Шаг 4: срок доступности.
     *
     * GET показывает шаг, POST сохраняет выдачу — метод различает их, а не
     * наличие поля срока. Различение по полю ломалось само собой: браузер
     * присылает отмеченную кнопку всегда, и пустой POST (тест, отключённый
     * JavaScript) молча показывал шаг заново вместо сохранения.
     */
    public function period(Request $request, array $params): Response
    {
        $assignment = $this->requirePublishedAssignment($this->intParam($params, 'id'));
        $classIds = $this->classIdsFrom($request);
        $picked = $this->idList(
            $request->postList('students') !== []
                ? $request->postList('students')
                : $this->listFromQuery($request, 'students')
        );

        if ($picked === []) {
            $this->session->set('flash_error', 'Отметьте хотя бы одного ученика');

            return $this->redirect('panel/issues/' . (int) $assignment['id']
                . '/students?classes=' . implode(',', $classIds));
        }

        if ($request->method() === 'POST') {
            /*
             * Срок проверяется здесь, до выдачи: пустые или перепутанные даты
             * означали бы либо бессрочную работу, либо выдачу, которой не видно
             * никому, — и обе молчали бы.
             */
            $period = $this->periodFromRequest($request);

            if ($period === null) {
                /*
                 * Отказ возвращает шаг с теми же датами: человек их только что
                 * ввёл и менял порядок, и заставлять набирать заново — значит
                 * заставить ошибиться второй раз.
                 */
                $this->keepInput('issue_period_old', $request, ['_token', 'period', 'date_from', 'date_until']);

                $this->session->set(
                    'flash_error',
                    'Проверьте даты: нужны обе в формате ГГГГ-ММ-ДД, и срок не может '
                    . 'заканчиваться раньше, чем начинается'
                );

                return $this->redirect('panel/issues/' . (int) $assignment['id'] . '/period');
            }

            return $this->store($request, $params, $period);
        }

        return $this->render('panel/issue_period', [
            'title'      => 'Выдать работу',
            'assignment' => $assignment,
            'classes'    => $this->allClasses(),
            'classIds'   => $classIds,
            'picked'     => $picked,
            'students'   => $this->studentsOfClasses($classIds),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
            // Что человек ввёл на этом шаге до отказа: срок и даты приходят
            // адресом с предыдущего шага, и без этого отказ стирал бы их.
            'old'        => $this->session->pull('issue_period_old', []),
        ]);
    }

    /** Выдача. */
    public function store(Request $request, array $params, array $period): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignment = $this->requirePublishedAssignment($this->intParam($params, 'id'));
        $assignmentId = (int) $assignment['id'];

        /*
         * Ученики приходят полем students[] — по одному флажку на человека. Раньше
         * здесь стоял postParam(), который брал первый элемент массива и молчал:
         * выдача «одному из отмеченных» прошла бы и выглядела бы как успешная, а
         * остальные просто исчезли бы.
         */
        $picked = $this->idList($request->postList('students'));

        if ($picked === []) {
            // Отмеченные ученики приходят с предыдущего шага и на этом шаге не
            // показываются: возвращать их некуда, но и терять нельзя — иначе
            // двадцать пять галочек пришлось бы ставить заново.
            $this->keepInput('issue_period_old', $request, ['_token']);

            $this->session->set('flash_error', 'Отметьте хотя бы одного ученика');

            return $this->redirect('panel/issues/' . $assignmentId . '/period');
        }

        /*
         * Класс каждого ученика берётся заново, а не из формы: список мог
         * устареть между шагами, и в форме его всё равно нет — он нужен
         * сводной таблице в «Просмотре результатов» как «кому выдавалось».
         */
        $map = [];

        foreach ($this->studentsOfClasses($this->classIdsFrom($request)) as $student) {
            $map[(int) $student['student_id']] = (int) $student['class_id'];
        }

        /*
         * Выдаётся ровно то, что отмечено среди учеников выбранных классов.
         * Номера приходят из запроса, а не из базы, поэтому сверяться надо
         * обязательно: без сверки достаточно подделать форму, чтобы выдать
         * работу любому существующему пользователю — учителю, завучу, кому угодно.
         * Такой строке ещё и не досталось бы класса, и выдача молча выпала бы из
         * журнала класса вместе со своим «кому выдавалось».
         *
         * Отмеченные, которых в классах нет, пропускаются с объяснением, а не
         * молча: иначе учитель увидел бы «выдано 2 ученикам», отметил троих и
         * не понял, куда делся третий.
         */
        $students = [];
        $strangers = [];

        foreach ($picked as $studentId) {
            if (isset($map[$studentId])) {
                $students[$studentId] = $map[$studentId];
                continue;
            }

            $strangers[] = $studentId;
        }

        if ($students === []) {
            $this->session->set(
                'flash_error',
                'Отмеченные ученики не числятся в выбранных классах: выдавать им работу нельзя'
            );

            return $this->redirect(
                'panel/issues/' . $assignmentId . '/students?classes='
                . implode(',', $this->classIdsFrom($request))
            );
        }

        [$from, $until] = $period;

        /*
         * Число попыток задаётся здесь, а не у работы: одна работа выдаётся
         * много раз и по-разному, и лимит у работы менял бы его у всех
         * выдач разом, включая те, где учитель поставил его намеренно иначе.
         */
        $issueId = $this->assignments->createIssue(
            $assignmentId,
            $this->authorId(),
            $students,
            $from,
            $until,
            $this->attemptLimit($request->postParam('max_attempts'))
        );

        if ($issueId === 0) {
            $this->session->set('flash_error', 'Выдача не создана');

            return $this->redirect('panel/issues/' . $assignmentId . '/students');
        }

        $notice = 'Работа выдана '
            . $this->view->plural(count($students), 'ученику', 'ученикам', 'ученикам')
            . ': ' . (string) $assignment['title'];

        if ($strangers !== []) {
            $notice .= '. Не выдано: '
                . $this->view->plural(count($strangers), 'ученик', 'ученика', 'учеников')
                . ', которых нет в выбранных классах';
        }

        $this->session->set('flash_notice', $notice);

        return $this->redirect('panel/issues/' . $issueId);
    }

    /**
     * Сводка по выдаче.
     *
     * Отдельная страница, а не список работ с раскрытием: сводка нужна сама по
     * себе, её открывают ссылкой из меню и из списка выдач, и она не должна
     * тянуть за собой весь список работ.
     */
    public function show(Request $request, array $params): Response
    {
        $issue = $this->assignments->findIssue($this->intParam($params, 'id'));

        if ($issue === null) {
            throw new HttpException(404, 'Выдача не найдена');
        }

        /*
         * Сводка показывает, кому что выдано и кто сдал, то есть имена учеников и
         * их результаты. Список выдач отфильтрован по автору работы, а сама
         * сводка открывалась по номеру любому учителю с правом выдавать работы:
         * достаточно было угадать или перебрать несколько номеров.
         *
         * Право здесь то же, что и в списке, — владелец работы или администратор.
         */
        if ((int) $issue['assignment_author'] !== $this->authorId()
            && !$this->auth->can(Role::USERS_MANAGE)) {
            throw new HttpException(403, 'Это не ваша выдача');
        }

        $rows = $this->assignments->issueStudents((int) $issue['id']);

        return $this->render('panel/issue_show', [
            'title'     => (string) $issue['assignment_title'],
            'issue'     => $issue,
            'rows'      => $rows,
            'finished'  => count(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['attempt_status'] === 'finished'
            )),
            'pending'   => count(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['manual'] === 1
                    && (string) $row['attempt_status'] === 'finished'
            )),
            'notice'    => $this->session->pull('flash_notice'),
            'error'     => $this->session->pull('flash_error'),
        ]);
    }

    // ------------------------------------------------------------------ помощь

    /**
     * Срок из формы.
     *
     * Четыре варианта как четыре разных значения, а не один срок с подписью:
     * «всегда» — это отсутствие границ, и подставлять вместо него дату
     * означало бы, что работа закрылась бы сама.
     *
     * Даты проверяются, а не подставляются как есть: мусор в поле ушёл бы в
     * DATETIME и при строгом режиме MySQL уронил бы страницу ошибкой базы,
     * а пустая дата сделала бы работу бессрочной — то есть выданной почти навсегда,
     * хотя автор думал, что ограничил её по сроку.
     *
     * @return array{0:?string,1:?string}|null null — даты негодные, выдача не создаётся
     */
    /**
     * Сколько попыток разрешить этой выдаче.
     *
     * Пустое поле и 0 — «без ограничения», как и везде, где считают попытки.
     * Верхняя граница осмысленна: двадцать попыток контрольной — это уже не
     * ограничение, а отсутствие проверки, и молча соглашаться с этим нельзя.
     */
    private function attemptLimit(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        $attempts = (int) $value;

        return $attempts > 0 ? min($attempts, 20) : 0;
    }
    private function periodFromRequest(Request $request): ?array
    {
        $mode = (string) $request->postParam('period', 'week');

        if ($mode === 'always') {
            return [null, null];
        }

        if ($mode === 'today') {
            $today = date('Y-m-d 23:59:59');

            return [date('Y-m-d 00:00:00'), $today];
        }

        if ($mode === 'dates') {
            $from = trim((string) $request->postParam('date_from', ''));
            $until = trim((string) $request->postParam('date_until', ''));

            if (!$this->isDate($from) || !$this->isDate($until)) {
                return null;
            }

            /*
             * Перепутанные границы дают выдачу, которой не видит никто: работа
             * должна быть доступна, пока `available_from <= сейчас <= until`, а
             * при from позже until такого момента не наступает. Выдача выглядела
             * бы сделанной и молчала бы — ученик ничего не получал бы, а автор
             * видел бы её в своих выдачах.
             */
            if ($from > $until) {
                return null;
            }

            return [$from . ' 00:00:00', $until . ' 23:59:59'];
        }

        return [date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+6 days'))];
    }

    /**
     * Дата в формате `ГГГГ-ММ-ДД` — именно та, которую ждёт поле и MySQL.
     *
     * Проверка строгая: `createFromFormat` молча соглашается на `2026-13-45`,
     * перенося её на следующий месяц, и выдача получила бы срок, которого
     * автор не задавал. Поэтому результат сравнивается с исходной строкой.
     */
    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @return list<array{student_id:int,full_name:string,class_id:int,class_name:string}> */
    private function studentsOfClasses(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $placeholders = [];

        foreach ($classIds as $index => $classId) {
            $placeholders['c' . $index] = $classId;
        }

        return $this->db->all(
            'SELECT cs.student_id, u.full_name, cs.class_id, c.name AS class_name
               FROM class_students cs
               JOIN users u   ON u.id = cs.student_id
               JOIN classes c ON c.id = cs.class_id
              WHERE cs.class_id IN (:' . implode(', :', array_keys($placeholders)) . ')
                AND u.status = \'active\'
           ORDER BY c.name, u.full_name',
            $placeholders
        );
    }

    /** Кому эта работа уже выдавалась — показывается в списке учеников. */
    private function alreadyIssued(int $assignmentId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['student_id'],
            $this->db->all(
                'SELECT s.student_id
                   FROM assignment_issue_students s
                   JOIN assignment_issues i ON i.id = s.issue_id
                  WHERE i.assignment_id = :a',
                ['a' => $assignmentId]
            )
        );
    }

    private function requirePublishedAssignment(int $id): array
    {
        $assignment = $this->db->one(
            'SELECT * FROM assignments WHERE id = :id',
            ['id' => $id]
        );

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        /*
         * Чужая опубликованная работа выдаётся: завуч проверяет, что всё
         * видно ученикам. Черновик — нет: выдача прошла бы незамеченной, и
         * автор обнаружил бы её, лишь увидев работу у себя в выдачах.
         */
        if ((int) $assignment['author_id'] !== $this->authorId()
            && !$this->auth->can(Role::USERS_MANAGE)) {
            throw new HttpException(403, 'Это не ваша работа');
        }

        if ((string) $assignment['status'] !== AssignmentRepository::STATUS_PUBLISHED) {
            $this->session->set(
                'flash_error',
                'Выдать можно только опубликованную работу: опубликуйте её последним шагом редактора'
            );

            throw new HttpException(404, 'Работа не опубликована');
        }

        return $assignment;
    }

    /**
     * Классы из запроса — и формой, и адресом.
     *
     * Форма присылает `classes[]`, а шаг между собой держит список в адресе,
     * чтобы шаг был пересылаемой ссылкой. Поддерживаются оба вида: иначе
     * переход «назад» по адресу терял бы выбранные классы.
     *
     * Разбор значений — общий idList() из ControllerHelpers: свой дублирующий
     * список в этом контроллере разошёлся бы с остальными страницами при
     * первом же различии в формате.
     *
     * @return list<int>
     */
    private function classIdsFrom(Request $request): array
    {
        /*
         * Значения приходят тремя способами, и все три надо понимать:
         * формой classes[] (GET и POST), скрытым полем classes (шаг срока) и
         * строкой classes=1,2,3 в адресе — её шаг передаёт следующему.
         *
         * Проверяется сначала POST, потом GET-массив, потом строка: строка в
         * адресе — это последний рубеж, и брать её первой значило бы забыть
         * про отмеченные в форме классы.
         */
        $posted = $request->postList('classes');

        if ($posted !== []) {
            return $this->idList($posted);
        }

        return $this->listFromQuery($request, 'classes');
    }

    /**
     * Список номеров из адреса: и флажки (`classes[]=5&classes[]=6`), и строку
     * `classes=5,6`, которой шаги передают друг другу выбор.
     *
     * Разница в том, как приходит значение: отмеченных классов может быть
     * сколько угодно и одним значением их не заменить, а строка — это всегда
     * одно значение, и его надо разделить. idList() делит строку и берёт
     * массив как есть, поэтому одно значение передаётся ему строкой.
     *
     * @return list<int>
     */
    private function listFromQuery(Request $request, string $key): array
    {
        $values = $request->queryList($key);

        if ($values === []) {
            return [];
        }

        return $this->idList(count($values) === 1 ? (string) $values[0] : $values);
    }

    private function authorId(): int
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401, 'Нужно войти в систему');
        }

        return (int) $user->id;
    }

    /** @return list<array<string,mixed>> */
    private function allClasses(): array
    {
        return $this->db->all('SELECT id, name, title FROM classes ORDER BY name');
    }
}
