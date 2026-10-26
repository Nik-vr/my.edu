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
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\AssignmentDraftRepository;
use MyEdu\Repository\WorkTree;

/**
 * Составление работ: список, карточка, удаление, отзыв, позиции.
 *
 * Мастер создания и правки работы живёт в WorkEditorController; здесь всё,
 * что вокруг него: список по папкам параллелей, карточка работы, статус,
 * удаление вместе с результатами учеников и отзыв выдачи с их сохранением.
 * Канбан тоже здесь: задания создаются и правятся силами плагинов, ядро
 * кладёт в работу только ссылку «плагин + вид + номер».
 *
 * Поэтому здесь нет ни одного упоминания конкретного плагина: добавление
 * нового способа проверки знаний не требует правок этого файла.
 */
final class AssignmentController
{
    use ControllerHelpers;
    use AssignmentSupport;

    /**
     * Устаревший вид задания «Тест с вопросами».
     *
     * Больше не предлагается учителю: плагин отдаёт по виду на каждый тип
     * вопроса. Но работы, созданные до перехода, всё ещё на него ссылаются,
     * и без подстановки названия они показывали бы машинное имя вместо
     * человеческого. Константа объявлена и в WorkEditorController: трейт
     * kindTitle() ею пользуется, а констант в трейте не бывает.
     */
    private const LEGACY_TEST_KIND = 'test';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Database $db,
        private readonly AssignmentRepository $assignments,
        private readonly AssignmentDraftRepository $drafts,
        private readonly PluginManager $plugins
    ) {
    }

/**
 * Список работ.
     *
     * Учителю показываются только его работы: чужие он не может ни открыть,
     * ни поправить, а список чужого состоял бы из неактивных ссылок.
     * Завучу и администратору — все, с именем автора: у них это рабочий
     * инструмент, а не чужое личное дело.
     *
     * Список показывается целиком и разложен по папкам параллелей. Раньше он
     * делился на вкладки «Опубликованные» и «Черновики», и вкладка делила
     * работы пополам: дерево по параллелям отвечает на вопрос, который
     * учитель задаёт чаще, — «где моя работа для восьмых», — а состояние у
     * каждой работы видно и в строке.
     *
     * Дерево собирается тем же построителем, что и первый шаг мастера выдачи:
     * два построителя разошлись бы при первой правке, и на двух страницах
     * папки назывались бы по-разному.
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        $seesAll = $this->seesAllWorks();

        $rows = $seesAll
            ? $this->assignments->allWithAuthor()
            : $this->assignments->allByAuthor($this->authorId());

        /*
         * Раздел состояния выбирается адресом, а не вкладкой в разметке: он
         * должен переживать перезагрузку и быть пересылаемым. Всё подряд не
         * показывается никогда — смешанные черновики и готовые работы в одной
         * папке читаются как список готовых, а черновик среди них незаметен.
         *
         * И при этом раздел помнится: адрес без `state` открывает тот, который
         * открыт был. Иначе после удаления работы человек возвращался бы на
         * «Опубликованные» из вкладки «Черновики», а список показывал бы ему
         * пустоту — и он решил бы, что работа удалилась вся.
         */
        $requested = $request->queryParam('state');
        $allowedStates = ['published', 'drafts', 'issued'];
        $state = is_string($requested) && in_array($requested, $allowedStates, true) ? $requested : 'published';

        if ($requested === null || $requested === '') {
            $remembered = $this->session->get('assignments_state');

            if (is_string($remembered) && in_array($remembered, $allowedStates, true)) {
                $state = $remembered;
            }
        }

        $this->session->set('assignments_state', $state);

        $drafts = 0;
        $published = 0;
        $issued = 0;

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'draft');
            if ($status === 'draft') {
                $drafts++;
            } else {
                $published++;
            }
            if ((int) ($row['issued_count'] ?? 0) > 0) {
                $issued++;
            }
        }

        // Filter rows by state
        $filteredRows = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'draft');
            $issuedCount = (int) ($row['issued_count'] ?? 0);

            if ($state === 'drafts') {
                if ($status === 'draft') {
                    $filteredRows[] = $row;
                }
            } elseif ($state === 'issued') {
                if ($issuedCount > 0) {
                    $filteredRows[] = $row;
                }
            } else { // published
                if ($status !== 'draft') {
                    $filteredRows[] = $row;
                }
            }
        }

        return $this->render('panel/assignments', [
            'title'         => $seesAll ? 'Все работы' : 'Мои работы',
            'tree'          => WorkTree::build($filteredRows, $state === 'drafts' ? 'drafts' : 'published'),
            'state'         => $state,
            'drafts'        => $drafts,
            'published'     => $published,
            'issued'        => $issued,
            'total'         => count($rows),
            'seesAll'       => $seesAll,
            // Кто автор — решает шаблон: у завуча видны чужие работы, и без
            // этого он предложил бы кнопки, которые всё равно упрутся в отказ.
            'currentUserId' => $user?->id ?? 0,
            'pendingReview' => $this->assignments->countPendingReview($this->authorId()),
            'hasPlugins'    => $this->plugins->all() !== [],
            'notice'        => $this->session->pull('flash_notice'),
            'error'         => $this->session->pull('flash_error'),
        ]);
    }
    /**
     * Обзор собранной работы.
     *
     * Страница показывает, что получилось, и ведёт в мастер. Форм настроек на
     * ней больше нет: всё задаётся по шагам, а второй способ поменять то же
* самое означал бы два разных ответа на один вопрос.
     */
    public function show(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');

        /*
         * Обзор открывается и чужому автору — тому, кому список работ уже
         * показывает чужие работы. Раньше здесь стоял requireOwned(), и
         * получалось противоречие: список предлагал «Открыть», а страница
         * отвечала отказом.
         *
         * Только чтение. Всё, что меняет, по-прежнему requireOwned(): завуч
         * видит чужую работу, но не переписывает её за автора.
         */
        $assignment = $this->requireReadable($id);
        $isAuthor = (int) $assignment['author_id'] === $this->authorId();

        /*
         * Кнопок на странице больше нет: они были копией кнопок редактора
         * («Собрать по шагам», «Опубликовать», «Удалить»), и два одинаковых
         * набора на двух страницах требовали держать в голове, какой из них
         * сейчас открыт. Те же сведения лежат последней вкладкой редактора —
         * «О работе», и в списке работ название ведёт прямо туда.
         *
         * Страница остаётся отдельной для того, кому работа доступна на
         * чтение, и для старых ссылок: переадресация уводила бы и туда, и
         * обратно, а после правки задания возвращала бы человека не туда,
         * откуда он пришёл.
         */
        return $this->render('panel/assignment', [
            'title'      => (string) $assignment['title'],
            'assignment' => $assignment,
            'isAuthor'   => $isAuthor,
            'items'      => $this->itemRows($id),
            'subjectName' => $this->subjectName($assignment),
            'issues'      => $this->assignments->issuesForAssignment($id),
            'attempts'   => $this->assignments->attemptsForAssignment($id),
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ]);
    }
    /**
     * Сохранение свойств работы.
     *
     * Позиции здесь не трогаются: у них свои формы. Иначе случайно отправленная
     * форма сохранения названия переставила бы всю работу.
     */
    public function update(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        $title = trim((string) $request->postParam('title', ''));

        if ($title === '') {
            $this->session->set('flash_error', 'Название работы не может быть пустым');

            return $this->redirect('panel/assignments/' . $id);
        }

        $this->assignments->update([
            'title'        => $this->limit($title, 191),
            'description'  => trim((string) $request->postParam('description', '')),
            'subject_id'   => $this->subjectId($request->postParam('subject_id')),
            'parallel'     => $this->parallel($request->postParam('parallel')),
            'grade_mode'   => $this->gradeMode($request->postParam('grade_mode')),
            'time_limit'   => $this->timeLimit($request->postParam('time_limit')),
            /*
             * Шкалы оценок здесь нет намеренно. Поля границ живут только в
             * форме шага «Результат» (`/edit/result`), и в эту форму они не
             * входят: разбор видел их пустыми и затирал шкалу целиком. Работа,
             * у которой уже задана шкала, теряла её при обычной правке названия
             * или предмета — а публикация потом упиралась в «не заданы
             * параметры оценки», и автор не понимал, где он их потерял.
             */
        ], ['id' => $id]);

        $this->session->set('flash_notice', 'Работа сохранена');

        return $this->redirect('panel/assignments/' . $id);
    }
    /**
     * Смена состояния: публикация, возврат в черновик, закрытие.
     *
     * Одно действие вместо трёх маршрутов: правила перехода живут в репозитории,
     * контроллер только передаёт намерение и показывает ответ.
     */
    public function status(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $assignment = $this->requireOwned($id);

        $action = (string) $request->postParam('action', '');

        $message = match ($action) {
            'publish' => $this->assignments->publish($id),
            'archive' => $this->assignments->archive($id),
            'draft'   => $this->assignments->toDraft($id),
            default   => 'Неизвестное действие',
        };

        $ok = !in_array($message, ['Работа не найдена', 'Добавьте в работу хотя бы одно задание'], true);

        $this->session->set($ok ? 'flash_notice' : 'flash_error', $message);

        /*
         * Отказ публикации из редактора возвращает в редактор и открывает окно
         * с препятствиями.
         *
         * Переключатель «Опубликовать» стоит в шапке редактора, и уводить
         * человека из него на страницу работы — значит терять то, что он
         * правил. Поэтому адрес возврата приходит с формой, а препятствия
         * ожидают в редакторе.
         */
        $back = (string) $request->postParam('return_to', '');

        if ($back !== '' && str_starts_with($back, 'panel/assignments/' . $id)) {
            if (!$ok && $action === 'publish') {
                $this->session->set('publish_blocked', $this->publishBlockers($id, $assignment));
            }

            return $this->redirect($back);
        }

        return $this->redirect('panel/assignments/' . $id);
    }
    /**
     * Подтверждение удаления работы.
     *
     * Отдельная страница, а не вопрос в браузере: снос работы необратим —
     * вместе с ней уходят попытки учеников и их ответы, — а `confirm()`
     * закрывается одним нажатием Enter, и слепой Enter стоил бы чужих
     * результатов. Здесь удаление намеренно требует нажатия кнопки, а
     * рядом показано, что именно пропадёт.
     */
    public function confirmDestroy(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');

        $assignment = $this->requireOwned($id);

        return $this->render('panel/assignment_delete', [
            'title'         => 'Удалить работу',
            'assignment'    => $assignment,
            'itemCount'     => $this->assignments->countItems($id),
            'totalAttempts' => $this->assignments->countAllAttempts($id),
        ]);
    }
    public function destroy(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        /*
         * Удаление без перезагрузки страницы.
         *
         * Список работ — длинная страница, и после удаления работы человек
         * оказывался в начале списка, потеряв место. Теперь строка убирается на
         * месте, а сервер отвечает данными; без скриптов всё работает как
         * раньше — обычная отправка формы и переход на страницу списка.
         */
        if ($request->wantsJson()) {
            try {
                $this->deleteAssignmentWithPluginData($id);
            } catch (TaskRemovalBlocked $e) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 409);
            }

            return Response::json(['ok' => true]);
        }

        try {
            $this->deleteAssignmentWithPluginData($id);
        } catch (TaskRemovalBlocked $e) {
            $this->session->set('flash_error', $e->getMessage());

            return $this->redirect('panel/assignments/' . $id);
        }

        $this->session->set('flash_notice', 'Работа удалена вместе с попытками');

        /*
         * Возврат на тот раздел списка, который был открыт.
         *
         * Иначе после удаления черновика человек попадал в «Опубликованные»,
         * где его работы нет, и решал бы, что удалилось больше, чем он нажал.
         */
        $state = $this->session->get('assignments_state');

        return $this->redirect(
            'panel/assignments?state=' . (is_string($state) && $state === 'drafts' ? 'drafts' : 'published')
        );
    }
    /**
     * Страница подтверждения отзыва выдачи работы.
     */
    public function confirmRecall(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');

        $assignment = $this->requireOwned($id);

        $issuedCount = (int) $this->assignments->countIssued($id);
        $attemptCount = (int) $this->assignments->countAllAttempts($id);

        return $this->render('panel/assignment_recall', [
            'title'         => 'Отозвать выдачу',
            'assignment'    => $assignment,
            'issuedCount'   => $issuedCount,
            'attemptCount'  => $attemptCount,
        ]);
    }
    /**
     * Отзыв выдачи: удаляет все выдачи (issues и issue_students),
     * но сохраняет саму работу и результаты уже начавших учеников.
     */
    public function recall(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        /*
         * Отзыв без перезагрузки страницы.
         *
         * Список работ — длинная страница, и после отзыва человек
         * оказывался в начале списка, теряя место. Теперь строка обновляется
         * на месте, а сервер отвечает данными; без скриптов всё работает как
         * раньше — обычная отправка формы и переход на страницу списка.
         */
        if ($request->wantsJson()) {
            try {
                $this->recallAssignmentIssues($id);
            } catch (\Throwable $e) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 409);
            }

            return Response::json(['ok' => true]);
        }

        try {
            $this->recallAssignmentIssues($id);
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $e->getMessage());

            return $this->redirect('panel/assignments/' . $id);
        }

        $this->session->set('flash_notice', 'Выдача работы отозвана. Результаты учеников сохранены.');

        $state = $this->session->get('assignments_state');

        return $this->redirect(
            'panel/assignments?state=' . (is_string($state) && $state === 'drafts' ? 'drafts' : 'published')
        );
    }
    /**
     * Удаляет все выдачи работы (issues и issue_students).
     *
     * @throws \RuntimeException если удаление не удалось
     */
    private function recallAssignmentIssues(int $assignmentId): void
    {
        // Находим все issues для этой работы
        $issues = $this->db->all(
            'SELECT id FROM assignment_issues WHERE assignment_id = :id',
            ['id' => $assignmentId]
        );

        if ($issues === []) {
            return; // нет выдач — ничего не делаем
        }

        $issueIds = array_column($issues, 'id');

        // Удаляем студентов выдач
        $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
        $this->db->run(
            "DELETE FROM assignment_issue_students WHERE issue_id IN ($placeholders)",
            $issueIds
        );

        // Удаляем сами выдачи
        $this->db->run(
            "DELETE FROM assignment_issues WHERE assignment_id = :id",
            ['id' => $assignmentId]
        );
    }

    // ---------------------------------------------------------------- позиции
    /**
     * Создание нового задания и добавление его в работу.
     *
     * Сначала плагин создаёт задание у себя и возвращает номер — и только
     * потом ядро кладёт позицию в работу. Порядок обязателен: номер позиции
     * должен указывать на уже существующее задание, иначе работа будет
     * опубликована с битой позицией.
     */
    public function createTask(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $assignment = $this->requireOwned($assignmentId);

        $pluginId = (string) $request->postParam('plugin_id', '');
        $kind = (string) $request->postParam('task_kind', '');

        $plugin = $this->plugins->get($pluginId);

        if (!$plugin instanceof AssessmentPlugin || !$this->kindExists($pluginId, $kind)) {
            $this->session->set('flash_error', 'Этот вид задания сейчас недоступен');

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        try {
            $taskId = $plugin->createTask($kind, [
                'author_id' => (int) $assignment['author_id'],
                'title'     => trim((string) $request->postParam('title', '')),
            ]);
        } catch (\Throwable $e) {
            $pluginName = $this->plugins->descriptor($pluginId)?->name ?? $pluginId;
            $this->session->set('flash_error', 'Плагин «' . $pluginName . '» не смог создать задание: ' . $e->getMessage());

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        $ref = new TaskRef($pluginId, $kind, $taskId);

        $this->assignments->addItem(
            $assignmentId,
            $ref,
            $this->weight($request->postParam('weight'))
        );

        $this->session->set('flash_notice', 'Задание добавлено. Откройте его и наполните.');

        return $this->redirect('panel/assignments/' . $assignmentId);
    }
    /**
     * Добавление в работу задания, которое уже создано.
     *
     * Нужно, чтобы не заводить копию одного и того же теста для каждой работы.
     */
    public function addExistingTask(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $this->requireOwned($assignmentId);

        $pluginId = (string) $request->postParam('plugin_id', '');
        $kind = (string) $request->postParam('task_kind', '');
        $taskId = (int) $request->postParam('task_id');

        $plugin = $this->plugins->get($pluginId);

        if (!$plugin instanceof AssessmentPlugin || $taskId <= 0 || !$this->kindExists($pluginId, $kind)) {
            $this->session->set('flash_error', 'Задание не найдено или его плагин выключен');

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        $ref = new TaskRef($pluginId, $kind, $taskId);

        if (!$this->plugins->canManageTask($ref, $this->authorId())) {
            $this->session->set('flash_error', 'Задание не найдено или принадлежит другому автору');

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        $this->assignments->addItem(
            $assignmentId,
            $ref,
            $this->weight($request->postParam('weight'))
        );

        $this->session->set('flash_notice', 'Задание добавлено в работу');

        return $this->redirect('panel/assignments/' . $assignmentId);
    }
    public function updateItem(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');
        $this->requireOwned($assignmentId);

        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
            $this->session->set('flash_error', 'Позиция не найдена');

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        $position = $request->postParam('position');

        if ($position !== null && trim($position) !== '') {
            $this->assignments->moveItem($assignmentId, $itemId, (int) $position);
        }

        $this->assignments->updateItem(
            ['weight' => $this->weight($request->postParam('weight'))],
            $itemId
        );

        return $this->redirect('panel/assignments/' . $assignmentId);
    }
    public function destroyItem(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');

        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
            $this->session->set('flash_error', 'Позиция не найдена');

            return $this->redirect('panel/assignments/' . $assignmentId);
        }

        // Проверка авторства после чтения позиции: иначе номер чужой работы
        // можно было бы использовать, чтобы понять, что там есть.
        $this->requireOwned($assignmentId);

            try {
                $removed = $this->deleteItemWithPluginData($assignmentId, $item);
            } catch (TaskRemovalBlocked $e) {
                $this->session->set('flash_error', $e->getMessage());

                return $this->redirect('panel/assignments/' . $assignmentId);
            }

            $this->session->set(
                'flash_notice',
                $removed
                    ? 'Задание убрано из работы и удалено вместе с вопросами'
                    : 'Задание убрано из работы'
            );

            return $this->redirect('panel/assignments/' . $assignmentId);
        }
    /**
     * Удалить работу, затем освободившиеся задания плагинов, одной транзакцией.
     *
     */
    private function deleteAssignmentWithPluginData(int $assignmentId): void
    {
        $this->db->transaction(function () use ($assignmentId): void {
            $locked = $this->db->one(
                'SELECT id FROM assignments WHERE id = :id FOR UPDATE',
                ['id' => $assignmentId]
            );

            if ($locked === null) {
                throw new \RuntimeException('Работа изменилась во время удаления');
            }

            // Список читается под блокировкой: новая позиция не должна попасть
            // под каскад удаления, не получив callback очистки плагина.
            $rows = $this->itemsBeforeRemoval($assignmentId);
            $plugins = [];
            $tasks = [];

            foreach ($rows as $row) {
                $ref = TaskRef::fromItemRow($row);
                $plugin = $this->pluginForRemoval($ref);
                $plugin->deleteItem($ref, (int) $row['id']);

                $plugins[$ref->pluginId] = $plugin;
                $tasks[$ref->pluginId . ':' . $ref->taskId] = $ref;
            }

            if ($this->assignments->delete($assignmentId) !== 1) {
                throw new \RuntimeException('Работа изменилась во время удаления');
            }

            // После каскадного удаления позиции плагин может определить,
            // используется ли задание ещё другими работами.
            foreach ($tasks as $key => $ref) {
                $plugins[$ref->pluginId]->deleteTask($ref);
            }
        });
    }
    /**
     * Работа, доступная для чтения.
     *
     * Автор открывает любую свою работу. Завуч и администратор — любую вообще:
     * список работ у них один на всех, и ссылка «Открыть» из списка обязана
     * вести куда-то, а не в отказ.
     *
     * Отказ остаётся 404, а не 403: человеку, которому работу не показывают,
     * незачем знать, что она существует.
     *
     * @return array<string,mixed>
     */
    private function requireReadable(int $id): array
    {
        $assignment = $this->assignments->find($id);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        $isAuthor = (int) $assignment['author_id'] === $this->authorId();

        if (!$isAuthor && !$this->seesAllWorks()) {
            throw new HttpException(404, 'Работа не найдена');
        }

        return $assignment;
    }
    /**
     * Видит ли человек работы всех учителей.
     *
     * Один ответ на два вопроса: чей это список и чью чужую работу можно
     * открыть. Если бы правила расходились, список предлагал бы кнопку,
     * которая ведёт в отказ.
     */
    private function seesAllWorks(): bool
    {
        return $this->auth->can(Role::CLASS_STUDENTS);
    }
}
