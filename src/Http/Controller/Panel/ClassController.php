<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\SubjectRepository;
use MyEdu\Auth\StudentRepository;
use MyEdu\Auth\UserRepository;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\Settings;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Plugin\ClassAwarePlugin;
use MyEdu\Plugin\PluginManager;

/**
 * Классы: список, создание, предметы и учителя.
 *
 * Класс состоит из номера («7-Б») и необязательного названия. Предметы и
 * учителя задаются на отдельной странице — так список класса остаётся
 * коротким, а состав класса виден на своих экранах.
 */
final class ClassController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly ClassRepository $classes,
private readonly SubjectRepository $subjects,
        private readonly UserRepository $users,
        private readonly StudentRepository $students,
        private readonly Settings $settings,
        private readonly PluginManager $plugins
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('panel/classes', [
            'title'  => 'Классы',
            'classes' => $this->classes->allWithCounts(),
            'error'  => $this->session->pull('flash_error'),
            'notice' => $this->session->pull('flash_notice'),
        ]);
    }

    /**
     * Создание класса — отдельной страницей.
     *
     * Список остаётся списком: пока форма создания стояла над ним, страница
     * отвечала на два разных вопроса сразу, и удаление класса нажималось
     * рядом с полями для нового.
     */
    public function create(Request $request): Response
    {
        return $this->render('panel/class_new', [
            'title'  => 'Новый класс',
            'error'  => $this->session->pull('flash_error'),
            // Название класса по умолчанию берём из настроек сайта: школа одна,
            // и вводить её в каждом классе заново незачем.
            'old'    => ['name' => '', 'title' => $this->settings->schoolName(), 'year' => date('Y')],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $name = $this->cleanClassName((string) $request->postParam('name', ''));
        $title = $this->cleanTitle((string) $request->postParam('title', ''));
        // Год запоминаем как ввели: разобранное значение на странице ошибки
        // показало бы не то, что человек собирался исправлять.
        $rawYear = trim((string) $request->postParam('year', ''));
        $parsedYear = $this->parseYear($rawYear);
        $year = $parsedYear['year'];

        if ($name === '') {
            return $this->storeFailed('Укажите номер класса, например 7-Б', '', $title, $year);
        }

        if (!$parsedYear['ok']) {
            return $this->storeFailed('Учебный год указан неверно. ' . $this->yearHint(), $name, $title, null, $rawYear);
        }

        if ($this->classes->findByName($name) !== null) {
            return $this->storeFailed('Класс «' . $name . '» уже существует', $name, $title, $year);
        }

        $id = $this->classes->create(['name' => $name, 'title' => $title, 'year' => $year]);

        $this->session->set('flash_notice', 'Класс ' . $name . ' создан');

        return $this->redirect('classes/' . $id);
    }

/**
     * Редактор класса.
     *
     * Две вкладки: «Класс и ученики» и «Предметы и учителя». Вкладка с
     * предметами и учителями переносится сюда как была — её содержание не
     * менялось, меняется только то, где она живёт.
     */
    public function show(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        $tab = (string) $request->queryParam('tab', 'class');

        if (!in_array($tab, ['class', 'roster'], true)) {
            $tab = 'class';
        }

        if ($tab === 'roster') {
            return $this->renderRoster($class);
        }

        $classId = (int) $class['id'];

        return $this->render('panel/class', [
            'title'     => 'Класс ' . $class['name'],
            'class'     => $class,
            'tab'       => $tab,
            /*
             * Ученики и логины в одной таблице.
             *
             * Раньше это были две страницы: список учеников и отдельный лист
             * с паролями, и один и тот же человек показывался дважды. Здесь
             * таблица одна — с логином, паролем и отметкой для печати.
             */
            'students'  => $this->studentRows($classId),
            /*
             * Какая строка открыта для правки имени.
             *
             * Решает сервер, а не скрипт: пока это делал скрипт, страница
             * зависела и от таблицы стилей, и от скрипта, и стоило одной из них
             * не дойти — поле правки появлялось у первого ученика само. Адрес
             * ?edit=<id> теперь и есть состояние строки, а скриптов на странице
             * нет вовсе.
             */
            'editStudentId' => (int) $request->queryParam('edit', '0'),
            /*
             * Пароли, выданные недавно, — чтобы они были видны в таблице.
             *
             * Лист с паролями уходит на печать и закрывает вкладку, а учитель
             * часто не печатает: он идёт по классу и диктует пароль ученику.
             * Таблица — место, где человек ищет логины и пароли.
             *
             * В базе пароля нет, он лежит хешем, и второй раз его не достать —
             * только выдать заново. Поэтому здесь лежат только что выданные, и
             * только до следующей выдачи.
             */
            'issuedPasswords' => $this->session->get('issued_passwords', []),
            'old'       => $this->session->pull('class_edit_old', []),
            'studentAddOld' => $this->session->pull('student_add_old', ''),
            'studentRenameOld' => $this->session->pull('student_rename_old', []),
            'error'     => $this->session->pull('flash_error'),
            'notice'    => $this->session->pull('flash_notice'),
            'importErrors' => $this->session->pull('import_errors', []),
        ]);
    }

    /**
     * Ученики класса вместе с логинами.
     *
     * Пароля в строке нет и быть не может: он хранится хешем, а выданный
     * показывается один раз — на листе, который открывается сразу при выдаче.
     * Раньше колонка «Пароль» читала пароли из сессии и держала их на экране
     * до печати: это значило, что чужие пароли лежат в файле сессии на диске
     * весь сеанс учителя.
     *
     * @return list<array<string,mixed>>
     */
    private function studentRows(int $classId): array
    {
        $rows = [];

        foreach ($this->students->listByClass($classId) as $student) {
            $rows[] = [
                'id'        => (int) $student['id'],
                'full_name' => (string) $student['full_name'],
                'login'     => (string) $student['login'],
                'status'    => (string) $student['status'],
            ];
        }

        return $rows;
    }

    /**
     * Подтверждение удаления класса.
     *
     * Отдельная страница, а не вопрос в браузере: удаление уносит учеников
     * вместе с их логинами и попытками, а `confirm()` закрывается нажатием
     * Enter — и слепой Enter стоил бы целого класса.
     */
    public function confirmDestroy(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        return $this->render('panel/class_delete', [
            'title'   => 'Удалить класс',
            'class'   => $class,
            'summary' => $this->classes->removalSummary((int) $class['id']),
        ]);
    }

    /**
     * Выгрузка данных класса перед удалением.
     *
     * Отдельный адрес, а не кнопка на странице подтверждения, потому что файл
     * должен забрать ДО удаления, и сделать это можно независимо: учитель мог
     * уже стереть класс и только потом понять, что учеников в нём были
     * десять человек с логинами и паролями.
     *
     * Файл — обычный CSV в UTF-8 с BOM: русский Excel открывает его без плясок
     * с кодировкой, а такой файл учитель без всяких правок вносит обратно в
     * список класса.
     */
    public function exportForRemoval(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        $rows = $this->classes->exportForRemoval((int) $class['id']);

        $csv = "\xEF\xBB\xBF" . 'Фамилия;Логин;Попыток;Сумма баллов' . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(';', [
                (string) $row['full_name'],
                (string) $row['login'],
                (string) $row['attempts'],
                $row['score'] === null ? '' : rtrim(rtrim(number_format((float) $row['score'], 2, '.', ''), '0'), '.'),
            ]) . "\r\n";
        }

        $name = 'klassa-' . preg_replace('/[^0-9A-Za-zА-Яа-я_-]+/u', '-', (string) $class['name']) . '.csv';

        return Response::download($csv, $name, 'text/csv; charset=utf-8');
    }

    /**
     * Удаление класса вместе с его учениками.
     */
    public function destroy(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $name = (string) $class['name'];

        $result = $this->classes->deleteWithStudents((int) $class['id']);

        /*
         * Плагины узнают об удалении после того, как оно состоялось.
         *
         * Их данные живут отдельно: тест, заведённый под классом, при удалении
         * класса теряет привязку и остаётся жить уже ни в чьём классе. Ядро об
         * этом знает по своей схеме, плагин — нет: без уведомления он молча
         * оставляет данные сиротами, и понять, что класс больше не существует,
         * он сможет только по косвенным признакам.
         *
         * Заметки плагинов показываются в сообщении об удалении: хук, о котором
         * никто не сказал, хуже отсутствия хука — он выглядит как «плагин всё
         * обработал», даже если ничего не сделал.
         */
        $notes = [];

        foreach ($this->plugins->all() as $plugin) {
            if (!$plugin instanceof ClassAwarePlugin) {
                continue;
            }

            try {
                $notes = array_merge($notes, $plugin->onClassRemoved([
                    'classId'    => (int) $class['id'],
                    'className'  => $name,
                    'studentIds' => $result['studentIds'],
                ]));
            } catch (\Throwable $e) {
                // Упавший плагин не должен отменять уже состоявшееся удаление:
                // класс уже снесён, а возврат ошибки здесь означал бы «ничего не
                // удалено», что неправда. Сбой попадает в список сбоев плагинов.
                $this->plugins->recordRuntimeFailure($plugin->id(), $e);
                $notes[] = 'Плагин «' . $plugin->name() . '» не обработал удаление класса';
            }
        }

        $this->session->set(
            'flash_notice',
            'Класс ' . $name . ' удалён'
            . ($result['deletedStudents'] > 0
                ? ' вместе с ' . $result['deletedStudents'] . ' ученик'
                    . ($result['deletedStudents'] % 10 === 1 && $result['deletedStudents'] % 100 !== 11 ? 'ом' : 'ами')
                : '')
            . ($result['keptStudents'] > 0
                ? '. Учеников, состоящих в других классах, оставлено: ' . $result['keptStudents']
                : '')
            . ($notes === [] ? '' : '. ' . implode('. ', $notes))
        );

        return $this->redirect('classes');
    }

    /**
     * Вкладка «Предметы и учителя».
     *
     * Содержимое перенесено без изменений: предметы и учителя класса — это
     * отдельный сюжет, и смешивать его с учениками незачем.
     */
    private function renderRoster(array $class): Response
    {
        $classId = (int) $class['id'];
        $teacherIds = $this->classes->teacherIds($classId);

        return $this->render('panel/class_roster', [
            'title'    => 'Предметы и учителя — ' . $class['name'],
            'class'    => $class,
            'tab'      => 'roster',
            'subjects' => $this->subjects->subjectsForClass($classId),
            'teachers' => $this->users->findMany($teacherIds),
            'allStaff' => $this->users->staffForSelect(),
            'mine'     => $teacherIds,
            'error'    => $this->session->pull('flash_error'),
            'notice'   => $this->session->pull('flash_notice'),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));

        $rawName = (string) $request->postParam('name', '');
        $rawTitle = (string) $request->postParam('title', '');
        $rawYear = (string) $request->postParam('year', '');
        $name = $this->cleanClassName($rawName);
        $title = $this->cleanTitle($rawTitle);
        $parsedYear = $this->parseYear($rawYear);
        $year = $parsedYear['year'];

        if ($name === '') {
            return $this->updateFailed(
                (int) $class['id'], $rawName, $rawTitle, $rawYear,
                'Укажите номер класса, например 7-Б'
            );
        }

        /*
         * Неверный год не проходит молча: иначе опечатка обнулила бы год,
         * а учитель увидел бы пустое поле и решил, что год не сохранился.
         */
        if (!$parsedYear['ok']) {
            return $this->updateFailed(
                (int) $class['id'], $rawName, $rawTitle, $rawYear,
                'Учебный год указан неверно. ' . $this->yearHint()
            );
        }

        $existing = $this->classes->findByName($name);

        if ($existing !== null && (int) $existing['id'] !== (int) $class['id']) {
            return $this->updateFailed(
                (int) $class['id'], $rawName, $rawTitle, $rawYear,
                'Класс «' . $name . '» уже существует'
            );
        }

        $this->classes->update((int) $class['id'], [
            'name'  => $name,
            'title' => $title,
            'year'  => $year,
        ]);

        $this->session->remove('class_edit_old');
        $this->session->set('flash_notice', 'Класс обновлён');

        return $this->redirect('classes/' . (int) $class['id']);
    }

    /** Отказ без потери введённых полей редактора класса. */
    private function updateFailed(int $classId, string $name, string $title, string $year, string $error): Response
    {
        $this->session->set('class_edit_old', [
            'name'  => $name,
            'title' => $title,
            'year'  => $year,
        ]);
        $this->session->set('flash_error', $error);

        return $this->redirect('classes/' . $classId);
    }

/**
     * Состав класса: предметы и учителя.
     *
     * Отдельный адрес остаётся рабочим и уводит на вкладку редактора: закладки
     * и ссылки из писем не должны превращаться в 404 после переноса.
     */
    public function roster(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        /*
         * Адрес строится через redirect(), а не «как есть»: в Location нужен
         * абсолютный путь. Без ведущего слэша браузер разрешает адрес
         * относительно текущего — из /classes/5/roster строка без слэша
         * превращается в /classes/classes/5, и вкладка открывалась 404.
         */
        return $this->redirect('classes/' . (int) $class['id'] . '?tab=roster');
    }

    public function saveSubjects(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));

        $subjects = $this->idList($request->postList('subjects'));
        $available = array_map(
            static fn (array $s): int => (int) $s['id'],
            $this->subjects->subjectsForClass((int) $class['id'])
        );

        // Берём только существующие предметы: иначе можно было бы подсунуть
        // произвольные идентификаторы в форму.
        $subjects = array_values(array_filter($subjects, static fn (int $id): bool => in_array($id, $available, true)));

        $this->subjects->setSubjects((int) $class['id'], $subjects);

        $this->session->set('flash_notice', 'Список предметов сохранён');

        return $this->redirect('classes/' . (int) $class['id'] . '?tab=roster');
    }

    public function createSubject(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));

        $name = trim((string) $request->postParam('name', ''));

        if ($name === '') {
            $this->session->set('flash_error', 'Укажите название предмета');

            return $this->redirect('classes/' . (int) $class['id'] . '?tab=roster');
        }

        /*
         * Кода у предмета нет — см. миграцию 017.
         *
         * Раньше здесь выводился код из названия, сверялся с уже занятым и
         * предмет отказывали. Защищать было нечего: код нигде не читался, а
         * одинаковые названия и раньше создавались — двум таким давались разные
         * коды (BIO и BIO2). То есть отказ «занятый код» отсекал ровно то, что
         * система и так разрешала.
         */
        $subjectId = $this->subjects->create(['name' => $name]);

        $this->subjects->setSubjects(
            (int) $class['id'],
            array_merge($this->subjects->subjectIds((int) $class['id']), [$subjectId])
        );

        $this->session->set('flash_notice', 'Предмет «' . $name . '» добавлен в класс');

        return $this->redirect('classes/' . (int) $class['id'] . '?tab=roster');
    }

    public function saveTeachers(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));

        $teachers = $this->idList($request->postList('teachers'));

        $allowed = array_map(
            static fn (array $u): int => (int) $u['id'],
            $this->users->staffForSelect()
        );

        $teachers = array_values(array_filter(
            $teachers,
            static fn (int $id): bool => in_array($id, $allowed, true)
        ));

        $this->classes->setTeachers((int) $class['id'], $teachers);

        $this->session->set('flash_notice', 'Список учителей сохранён');

        return $this->redirect('classes/' . (int) $class['id'] . '?tab=roster');
    }

    // ---------------------------------------------------------------- помощники

    /** @return array<string,mixed> */
    private function loadClass(int $id): array
    {
        if ($id <= 0) {
            throw new HttpException(404, 'Класс не найден');
        }

        $class = $this->classes->find($id);

        if ($class === null) {
            throw new HttpException(404, 'Класс не найден');
        }

        return $class;
    }

    /**
 * Отказ от создания класса: страница остаётся открытой, введённое — на месте.
 *
 * Номер класса тоже возвращается, хотя он и мог быть причиной отказа: человек
 * набирал «7-Б», а сервер отказал из-за учебного года, и заставлять его
 * вводить номер заново незачем. Правило общее для всех форм: отказ не должен
 * стоить напечатанного.
 */
private function storeFailed(string $error, string $name, string $title, ?int $year, ?string $rawYear = null): Response
    {
        $this->session->set('flash_error', $error);

        return $this->render('panel/class_new', [
            'title'  => 'Новый класс',
            'error'  => $error,
            'old'    => [
                'name'  => $name,
                'title' => $title,
                /*
                 * Год возвращается ровно таким, каким его ввели. При ошибке в
                 * самом году нормализованное значение означало бы «вот какой
                 * год мы поняли вместо вашего» — и человек исправлял бы не то.
                 */
                'year'  => $rawYear ?? ($year !== null ? (string) $year : ClassRepository::academicYear(date('Y'))),
            ],
        ], 422);
    }

    private function cleanClassName(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        return mb_strtoupper(mb_substr($name, 0, ClassRepository::NAME_MAX, 'UTF-8'), 'UTF-8');
    }

    private function cleanTitle(string $title): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        if ($title === '') {
            return null;
        }

        return mb_substr($title, 0, ClassRepository::TITLE_MAX, 'UTF-8');
    }

    /**
 * Год начала учебного года из того, что ввёл учитель.
 *
 * Принимаются оба написания — «2026» и «2026-2027»: учитель вводит так, как
 * привык, а в базу попадает только начало. Год-за-годом брать первые четыре
 * цифры нельзя: из опечатки «22026» вышло бы 2202, и такой год молча уехал бы
 * в поле.
 *
 * @return array{ok:bool,year:?int} ok = false означает, что запись испорчена
     */
    private function parseYear(mixed $year): array
    {
        $text = trim((string) $year);

        if ($text === '') {
            return ['ok' => true, 'year' => null];
        }

        // Тире и слэши приводим к дефису, чтобы «2026/2027» и «2026 — 2027»
        // понимались одинаково.
        $text = (string) preg_replace('/[\s\/–—]+/u', '-', $text);

        if (preg_match('/^(\d{4})(?:-(\d{4}))?$/u', $text, $matches) !== 1) {
            return ['ok' => false, 'year' => null];
        }

        $start = (int) $matches[1];

        if ($start < 2000 || $start > 2100) {
            return ['ok' => false, 'year' => null];
        }

        /*
         * Если второе число указано и это не следующий год, в записи опечатка:
         * молча соглашаться с «2026-2030» значит показать учителю не тот год,
         * который он задал.
         */
        if (isset($matches[2]) && $matches[2] !== '' && (int) $matches[2] !== $start + 1) {
            return ['ok' => false, 'year' => null];
        }

        return ['ok' => true, 'year' => $start];
    }

    /** Подсказка об учебном годе — одна и та же во всех местах. */
    private function yearHint(): string
    {
        return 'С 1 сентября по 31 мая следующего года. Можно написать «2026» или «2026-2027».';
    }
}
