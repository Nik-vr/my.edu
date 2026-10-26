<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Grading\GradeAggregator;
use MyEdu\Grading\GradeScaleValidator;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\EditorContext;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\AssignmentDraftRepository;
use MyEdu\Repository\WorkTree;

/**
 * Мастер работы: создание, правка по вкладкам, публикация.
 *
 * Один поток от адреса «Составить работу» до кнопки «Опубликовать». Первый
 * шаг задаёт название и предмет, вкладка «Задания» наполняет работу канбаном,
 * вкладка «Результат» — шкалой оценок, публикация выпускает работу ученикам.
 * Полную запись шага «Начало» делят мастер создания и карточка работы через
 * applyStartFields() — правило одно, формы две.
 *
 * Список, карточка и отзыв выдачи живут в AssignmentController; общие
 * помощники двух контроллеров — в трейте AssignmentSupport. Как и весь слой
 * составления, класс не знает ни одного конкретного плагина: виды заданий
 * приходят через PluginManager, а задание создаёт и рисует сам плагин.
 */
final class WorkEditorController
{
    use ControllerHelpers;
    use AssignmentSupport;

    /**
     * Сколько альтернатив может быть у одной строки работы.
     *
     * Число не случайное: три — это сколько колонок на доске. Четвёртая
     * альтернатива некуда положиться, и молча поставить её на место третьей
     * автор не поймёт.
     */
    private const MAX_VARIANTS = 3;

    /**
     * Легаси-вид задания: раньше тесты жили под собственным идентификатором,
     * и старые работы до сих пор ссылаются на вид `test`.
     *
     * Константа обязана быть в каждом из двух контроллеров — трейт
     * AssignmentSupport обращается к ней через self::, а констант внутри
     * трейта PHP не держит (см. шапку трейта).
     */
    private const LEGACY_TEST_KIND = 'test';

    /**
     * Вкладки редактора работы.
     *
     * Список один и на разметку, и на проверку `?tab=`, и на возврат после
     * сохранения. Держать его в трёх местах — значило бы получить страницу, где
     * вкладка есть в меню, но не открывается по своему адресу.
     */
    private const EDITOR_TABS = ['start', 'items', 'result', 'about'];

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
 * Кнопка «Составить работу» открывает мастер сразу.
 *
 * Отдельной страницы с одной кнопкой больше нет. Она стоила лишнего щелчка
 * и, главное, оставляла в списке пустые черновики: человек открывал мастер,
 * передумывал и закрывал — а работа без названия оставалась висеть в списке
 * навсегда. Теперь работы в базе нет, пока в мастере не нажали «Сохранить».
 *
 * Адрес остаётся: на него могли остаться ссылки из закладок и писем.
 */
public function create(Request $request): Response
    {
        /*
         * «Составить работу» открывает тот же редактор, что и «Редактировать».
         *
         * Раньше ссылка вела на отдельный первый шаг мастера, и у проекта были
         * два редактора работы с разными интерфейсами: один начинал новую,
         * другой правил существующую. Человек приходил по ссылке в один, а
         * возвращался из другого — и интерфейсы не совпадали.
         *
         * Работа появляется сразу, а название задаётся на первой вкладке
         * редактора: пустая строка в списке видна как «Без названия» и не
         * выглядит забытой работой.
         */
        $id = (int) $this->assignments->create(['title' => 'Новая работа', 'author_id' => $this->authorId()]);

        /*
         * Поля пустой работы ставятся те же, что ставил первый шаг мастера.
         *
         * Иначе новая работа отличалась бы от созданной прежним путём: у неё
         * были бы другие значения по умолчанию, и учитель увидел бы на
         * вкладке «Начало» то, чего не видел никогда.
         *
         * Показ результата здесь не перечислен намеренно: у нового черновика
         * он берётся из настройки таблицы (1 — показывать). Раньше здесь стоял
         * явный 0, и работы, созданные через «Составить работу», скрывали
         * результат ученику, пока автор не заметил и не включил галочку на
         * вкладке «Результат» — симптом владелец 24.10.2026 (задача 2).
         */
        $this->assignments->update([
            'title'        => 'Новая работа',
            'description'  => '',
            'subject_id'   => null,
            'parallel'     => null,
            'time_limit'   => null,
        ], ['id' => $id]);

        $this->session->set('flash_notice', 'Работа создана. Измените название и добавьте задания.');

        return $this->redirect('panel/assignments/' . $id . '/edit/start');
    }
/**
 * Первый шаг мастера для работы, которой ещё нет.
 *
 * Форма та же, что и у готовой работы, но отправляется на адрес создания: при
 * первом сохранении появится и сама работа, и её номер. Дальше мастер пойдёт
 * по обычным адресам с номером.
 */
public function editNewStart(Request $request): Response
    {
        /*
         * Название пустое, а не «Новая работа».
         *
         * Заглушка была значением поля, и сохранение без правки создавало работу,
         * которая так и называлась. Человек нажал «Сохранить» на пустом шаге — и
         * получил черновик с чужим именем вместо напоминания ввести своё.
         *
         * Этот шаг остался отдельным от ссылки «Составить работу»: он создаёт
         * работу по названию, и создавать её заранее незачем. Ссылка же ведёт
         * сразу в редактор — `create()`.
         */
        $empty = [
            'id'           => 0,
            'title'        => '',
            'description'  => '',
            'subject_id'   => null,
            'parallel'     => null,
            'time_limit'   => null,
            'show_results' => 1,
        ];

        $view = $this->wizardView($empty, 'start', [
            'subjects'   => $this->subjects(),
            'parallel'   => null,
            'hasPlugins' => $this->plugins->all() !== [],
            /*
             * Введённое на этом же шаге, если сервер отказал: без него человек
             * потерял бы остальные поля из-за пустого названия.
             */
            'old' => $this->session->pull('assignment_start_old', []),
        ]);

        /*
         * Шаги и адрес формы у несохранённой работы свои: пока номера нет, вести
         * её дальше некуда, и ссылки на «Задания» должны вести обратно сюда же,
         * а не в пустоту.
         */
        $view['isNew'] = true;

        return $this->render('panel/assignment_start', $view);
    }
/**
 * Первое сохранение: здесь и появляется работа.
 *
 * Отказ остаётся на том же шаге и ничего не записывает — пустой черновик в
 * списке работ хуже, чем его отсутствие: он выглядит как забытая работа, и
 * человек потом ищет, что с ней случилось.
 */
public function saveNewStart(Request $request): Response
{
    $this->requireToken($request, $this->csrf);

    $title = trim((string) $request->postParam('title', ''));

    if ($title === '') {
        $this->keepInput('assignment_start_old', $request);

        $this->session->set('flash_error', 'Название работы не может быть пустым');

        return $this->redirect('panel/assignments/new/start');
    }

    $id = (int) $this->assignments->create(['title' => $this->limit($title, 191), 'author_id' => $this->authorId()]);

    $this->applyStartFields($request, $id);

    $this->session->set('flash_notice', 'Работа создана. Добавьте задания.');

    return $this->redirect('panel/assignments/' . $id . '/edit/items');
}
    /**
     * Редактор работы: одна страница с четырьмя вкладками.
     *
     * Был мастер из четырёх страниц, и вход в него вёл на первый этап. Теперь
     * вкладок четыре, а вход открывает ту, на которой человек обычно и
     * остаётся, — доску заданий.
     *
     * @param array<string,mixed> $params
     */
    public function edit(Request $request, array $params): Response
    {
        return $this->renderEdit($request, $this->intParam($params, 'id'), $this->queryTab($request));
    }
    /**
     * Прежний первый этап: та же страница, вкладка «Начальная страница».
     *
     * Отдельный адрес остаётся рабочим: на `/edit/start` приходят из закладок
     * и из писем. Открывается он не прежней страницей, а этой же — с нужной
     * вкладкой.
     *
     * @param array<string,mixed> $params
     */
    public function editStart(Request $request, array $params): Response
    {
        return $this->renderEdit($request, $this->intParam($params, 'id'), 'start');
    }
    /**
     * Прежний этап с заданиями: та же страница, вкладка «Задания».
     *
     * @param array<string,mixed> $params
     */
    public function editItems(Request $request, array $params): Response
    {
        return $this->renderEdit($request, $this->intParam($params, 'id'), 'items');
    }
    /**
     * Прежний этап с результатом: та же страница, вкладка «Результат».
     *
     * @param array<string,mixed> $params
     */
    public function editResult(Request $request, array $params): Response
    {
        return $this->renderEdit($request, $this->intParam($params, 'id'), 'result');
    }
    /**
     * Сохранение редактора целиком.
     *
     * Одна форма на четыре вкладки, поэтому проверяется и записывается всё
     * сразу, а не по одному полю за этап. Порядок проверок прежний: без
     * названия работа не сохраняется вовсе, иначе сначала пишется всё про
     * работу, а затем шкала.
     *
     * @param array<string,mixed> $params
     */
    public function editSave(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        /*
         * Название приходит не всегда: в редакторе его правят в шапке, отдельной
         * формой, а общая форма за вкладками названия не содержит.
         *
         * Отдельная правка названия (форма в шапке) и общее сохранение вкладок —
         * разные дела, и отказ в одном не должен отменять другое: иначе пустое
         * название в общей форме сбрасывало бы всю работу автора.
         */
        if ($request->postParam('title_only') === '1') {
            $title = trim((string) $request->postParam('title', ''));

            if ($title === '') {
                $this->session->set('flash_error', 'Название работы не может быть пустым');

                return $this->redirect('panel/assignments/' . $id . '/edit');
            }

            $this->assignments->update(
                ['title' => $this->limit($title, 191)],
                ['id' => $id]
            );

            /*
             * Успех — всплывающим сообщением, а не баннером.
             *
             * Баннер остаётся для сообщений после перехода, но здесь переход
             * ничего не меняет: страница та же, вкладка та же, и человек видит
             * на ней то же самое название, что и секунду назад. Баннер об этом
             * только напоминал: «Название сохранено» — и название на глазах
             * меняется на другое.
             */
            $this->session->set('toast_notice', 'Название сохранено');

            return $this->redirect('panel/assignments/' . $id . '/edit');
        }

        $this->applyStartFields($request, $id);

        $submission = $this->criteriaSubmission($request, $this->itemsMaxScore($id));

        if (!$submission['check']['ok']) {
            /*
             * Страница возвращается сразу, а не адресом ошибки: адрес один,
             * и перебрасывать с него на него же значило бы заставлять
             * перезагружать страницу дважды, чтобы увидеть то же самое.
             *
             * Отмеченные поля и тексты идут вместе с введёнными границами:
             * вернуть числа и потерять пометки об ошибке значило бы оставить
             * человека перед полем, которое он уже правил.
             */
            return $this->renderEdit($request, $id, 'result', [
                'mode'         => $submission['mode'],
                'values'       => $submission['values'],
                'show_results' => $request->postParam('show_results') === '1',
                'bad_from'     => $submission['check']['badFrom'],
                'bad_to'       => $submission['check']['badTo'],
                'messages'     => $submission['check']['messages'],
            ]);
        }

        $this->saveCriteria($request, $submission, $id);

        $this->session->set('flash_notice', 'Работа сохранена');

        return $this->redirect('panel/assignments/' . $id . '/edit?tab=' . $this->postTab($request));
    }
    public function saveStart(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        /*
         * Название приходит не всегда.
         *
         * В редакторе его правят в шапке, отдельной формой, а общая форма — за
         * вкладками — названия не содержит. Поэтому пустое название отказом
         * считается только тогда, когда его действительно прислали: иначе
         * обычное сохранение вкладок стирало бы имя работы.
         */
        $title = $request->postParam('title', null);

        if ($title !== null && trim((string) $title) === '') {
            /*
             * Отказ не должен стоить остального: человек назвал работу, описал
             * её, выбрал предмет и срок, и ошибка была в одном поле. Всё
             * введённое возвращается на страницу — иначе шесть полей пришлось
             * бы заполнять заново из-за пустого названия.
             */
            $this->keepInput('assignment_start_old', $request);

            $this->session->set('flash_error', 'Название работы не может быть пустым');

            return $this->redirect('panel/assignments/' . $id . '/edit/start');
        }

        $this->applyStartFields($request, $id);

        // После первого этапа человек идёт дальше, а не возвращается в список:
        // список он и так видел, откуда пришёл.
        return $this->redirect('panel/assignments/' . $id . '/edit/items');
    }
    /**
     * Вкладка из адреса.
     *
     * Значение приходит из строки запроса и подставляется в разметку, поэтому
     * сверяется со списком вкладок, а не используется как есть. Неизвестное
     * значение открывает первую вкладку, а не пустую страницу: такой адрес
     * мог остаться от прежнего мастера.
     */
    private function queryTab(Request $request): string
    {
        $tab = $request->queryParam('tab');

        return is_string($tab) && in_array($tab, self::EDITOR_TABS, true) ? $tab : 'start';
    }
    /**
     * Какая вкладка открыта после сохранения.
     *
     * Человек правил поля той вкладки, на которой стоял, и после сохранения
     * должен вернуться на неё же: возврат на первую вкладку выкидывал бы его
     * с заполненной шкалы на пустое «Название».
     */
    private function postTab(Request $request): string
    {
        $tab = $request->postParam('work_tab');

        return is_string($tab) && in_array($tab, self::EDITOR_TABS, true) ? $tab : 'start';
    }
    /**
     * Страница редактора со всеми четырьмя вкладками сразу.
     *
     * Данные для всех вкладок собираются здесь, а не там, где вкладка
     * открывается: переключение вкладок не перезагружает страницу, и наборы
     * полей уже лежат в разметке. Собирать их по одной значило бы скрытую
     * загрузку, и каждая вкладка отставала бы на одно нажатие.
     *
     * @param array{mode:string,values:array<int,array<string,mixed>>,show_results?:bool,
     *        bad_from?:list<int>,bad_to?:list<int>,messages?:list<string>}|null $criteriaOld
     *        введённые границы и отметки неверных полей, если сервер отказал
     */
    private function renderEdit(
        Request $request,
        int $id,
        string $tab,
        ?array $criteriaOld = null
    ): Response {
        $assignment = $this->requireOwned($id);

        $total = $this->itemsMaxScore($id);
        $rows = $this->scaleRows($assignment, $total);
        $blockers = $this->publishBlockers($id, $assignment);

        $badFrom = [];
        $badTo = [];
        $messages = [];

        /*
         * Отказ возвращает введённое, а не то, что лежит в базе: иначе
         * человек правил бы границы заново после того, как сервер сказал, что
         * они не сходятся.
         */
        $kept = $criteriaOld ?? $this->session->pull('assignment_result_old', null);

        if (is_array($kept) && is_array($kept['values'] ?? null)) {
            $mode = ($kept['mode'] ?? 'percent') === 'score' ? 'score' : 'percent';

            $rows[$mode] = $kept['values'];
            $assignment['criteria_mode'] = $mode;
            $assignment['show_results'] = !empty($kept['show_results']) ? 1 : 0;

            $badFrom = $kept['bad_from'] ?? [];
            $badTo = $kept['bad_to'] ?? [];
            $messages = $kept['messages'] ?? [];
        }

        return $this->render('panel/assignment_edit', [
            'title'       => 'Редактор работы',
            'notice'      => $this->session->pull('flash_notice'),
            'error'       => $this->session->pull('flash_error'),
            /*
             * Всплывающее сообщение: страница не уходила, и человек видит
             * результат на ней же — баннер был бы лишним.
             */
            'toast'       => $this->session->pull('toast_notice'),
            'assignment'  => $assignment + ['max_score_total' => $total],
            'subjects'    => $this->subjects(),
            'parallel'    => WorkTree::parallelOf($assignment),
            'hasPlugins'  => $this->plugins->all() !== [],
            'old'         => $this->session->pull('assignment_start_old', []),
            'workTab'     => in_array($tab, self::EDITOR_TABS, true) ? $tab : 'start',
            'rows'        => $this->taskRows($id),
            'catalogue'   => $this->catalogueGroups(),
            'inactive'    => $this->inactiveTaskPlugins(),
            'editor'      => $this->taskEditor($request, $id),
            'itemsUrl'    => 'panel/assignments/' . $id . '/edit/items',
            'scale'       => GradeAggregator::create($this->config)->scale(),
            'percent'     => $rows['percent'],
            'score'       => $rows['score'],
              'badFrom'     => $badFrom,
              'badTo'       => $badTo,
              'messages'    => $messages,
              /*
               * Последняя вкладка «О работе» показывает сведения о собранной
               * работе: те же, что раньше были на отдельной странице работы.
               * Страница осталась для тех, кому работа доступна на чтение.
               */
              'subjectName' => $this->subjectName($assignment),
              'issues'      => $this->assignments->issuesForAssignment($id),
              'attempts'    => $this->assignments->attemptsForAssignment($id),
              'isAuthor'    => true,
            'blockers'    => $blockers,
            'items'       => $this->itemRows($id),
            'status'      => (string) $assignment['status'],
            /*
             * Выдавалась ли работа хоть раз.
             *
             * От этого зависит предупреждение: пока работу никто не получал,
             * правки в её составе и шкале никого не задевают, и предупреждение
             * было бы шумом.
             */
            'issued'      => $this->assignments->issuesForAssignment($id) !== [],
            /*
             * Открыто ли поле правки названия.
             *
             * Так же, как имя ученика в списке класса: адрес решает, что
             * открыто, и скрипт только убирает перезагрузку. Ссылка ведёт на
             * `?edit=title`, и без скриптов поле всё равно откроется.
             */
            'editTitle'   => (string) $request->queryParam('edit', '') === 'title',
            /*
             * Отказ публикации.
             *
             * Переключатель «Опубликовать» стоит в шапке, и при отказе человек
             * должен увидеть, чего не хватает, а не уйти на другую страницу.
             * Поэтому сервер оставляет препятствия в сессии, а редактор открывает
             * окно с ними.
             */
            'publishBlocked' => $this->session->pull('publish_blocked', []),
        ]);
    }
    /**
     * Форма редактора задания для встройки в эту страницу.
     *
     * Отдельный адрес редактора уводил автора из списка позиций работы, где
     * видно остальные задания и их веса: правишь одно — и надо помнить,
     * где остановился.
     *
     * Номер задания приходит из адреса и проверяется на принадлежность этой
     * работе: подставив чужой номер, автор открыл бы форму чужого задания
     * внутри своей работы.
     *
* @return array{itemId:int,taskId:int,order:int,variant:int,html:string}|null
     */
    private function taskEditor(Request $request, int $assignmentId): ?array
    {
        return $this->taskEditorFor((int) $request->queryParam('edit', '0'), $assignmentId);
    }
    /**
     * Форма редактора задания по его номеру.
     *
     * Номер передаётся, а не берётся из адреса: тем же методом собирается
     * ответ для скрипта, который открывает окно без перезагрузки страницы, и
     * там номер задания приходит параметром запроса, а не строкой `edit=` в
     * адресе.
     */
    private function taskEditorFor(int $taskId, int $assignmentId): ?array
    {
        if ($taskId <= 0) {
            return null;
        }

        /*
         * Порядковые номера считаются один раз на всю страницу: окно
         * редактора подписано номером задания, а нумерация живёт в строках
         * доски, и считать её отдельно здесь значило бы получить другой ответ.
         *
         * Номер присваивается каждой карточке строки, а не только основной:
         * редактор открывается и у варианта, и он принадлежит той же строке.
         */
        $order = [];

        foreach ($this->taskRows($assignmentId) as $rowIndex => $variants) {
            foreach ($variants as $variant) {
                $order[(int) $variant['id']] = $rowIndex + 1;
            }
        }

        foreach ($this->assignments->items($assignmentId) as $item) {
            if ((int) $item['task_id'] !== $taskId) {
                continue;
            }

            $ref = TaskRef::fromItemRow($item);
            $plugin = $this->plugins->get($ref->pluginId);

            if (!$plugin instanceof AssessmentPlugin) {
                return null;
            }

            try {
                $editorHtml = $plugin->renderEditorFragment(
                    $this->editorContext($plugin, $ref, $assignmentId, (int) $item['id'])
                );
            } catch (\Throwable $e) {
                $this->plugins->recordRuntimeFailure($ref->pluginId, $e);
                $editorHtml = $this->view->bannerError('Не удалось открыть редактор задания. Повторите попытку позже.');
            }

return [
                'itemId'  => (int) $item['id'],
                'taskId'  => $taskId,
                'order'   => $order[(int) $item['id']] ?? 0,
                'variant' => (int) ($item['variant'] ?? 1),
                'html'    => $editorHtml,
            ];
        }

        return null;
    }
    /**
     * Тот же редактор задания, но данными — без перезагрузки страницы.
     *
     * Раньше окно открывалось переходом по адресу с `?edit=`. Страница
     * перезагружалась целиком, и место, где автор остановился на доске,
     * терялось: после закрытия он оказывался в начале и снова прокручивал
     * список. Здесь приходит только разметка формы, страница остаётся на месте
     * и вместе с прокруткой.
     *
     * Проверка владельца та же, что и у страницы доски, иначе по номеру задания
     * можно было бы открыть чужую форму. Без скриптов адрес по-прежнему
     * работает: скрипт только перехватывает щелчок и заменяет переход.
     */
    public function taskEditorFragment(Request $request, array $params): Response
    {
        $id = $this->intParam($params, 'id');
        $this->requireOwned($id);

        $taskId = (int) $request->queryParam('task', '0');
        $editor = $this->taskEditorFor($taskId, $id);

        if ($editor === null) {
            return Response::json([
                'ok' => false,
                'message' => 'Задание не найдено в этой работе',
            ], 404);
        }

        return Response::json([
            'ok' => true,
            'html' => $editor['html'],
            'itemId' => $editor['itemId'],
            'taskId' => $editor['taskId'],
            'order' => $editor['order'],
            'variant' => $editor['variant'],
            'url' => $this->view->url('panel/assignments/' . $id . '/edit/items?edit=' . $taskId),
            /*
             * Адрес черновика — здесь, а не в разметке окна.
             *
             * Окно на странице одно, а заданий в работе много, и номер позиции
             * у каждого свой. В разметке окна он был нулевым, пока задание не
             * открыто, и оставался нулевым и после открытия: скрипт подставляет
             * в содержимое окна только форму, а адрес черновика лежит на самом
             * окне. Черновик уходил в позицию «0», сервер отвечал «позиция не
             * найдена», и правка автора не сохранялась никуда.
             */
            'draftUrl' => $this->view->url(
                'panel/assignments/' . $id . '/edit/items/' . (int) $editor['itemId'] . '/draft'
            ),
        ]);
    }
    /**
     * Контекст редактора для плагина.
     *
     * Собирается здесь, а не вытаскивается из сервис-контейнера: плагин
     * получает ровно то, что ядре известно о позиции, и ничего сверх того.
     */
    private function editorContext(
        AssessmentPlugin $plugin,
        TaskRef $ref,
        int $assignmentId,
        ?int $itemId = null
    ): EditorContext {
        $taskData = [];

        $taskData = $this->plugins->taskData($ref);

        return new EditorContext(
            $ref,
            $ref->kind,
            $taskData,
            $this->plugins->taskKinds($ref->pluginId),
            $this->auth->user(),
            $this->view,
            $this->csrf,
            $this->auth,
            // Возврат после сохранения — обратно в редактор работы, с тем же
            // открытым заданием.
            'panel/assignments/' . $assignmentId . '/edit/items?edit=' . $ref->taskId,
            /*
             * Опубликована ли работа. Плагин о статусе работы не знает: у
             * задания свой статус — «черновик» или «идёт проверка», — и по нему
             * публикацию работы определить нельзя. Раньше плагин смотрел на
             * статус задания и показывал «работу опубликована» при правке
             * черновика.
             */
            in_array(
                (string) $this->assignments->find($assignmentId)['status'],
                ['published', 'archived'],
                true
            ),
            /*
             * Номер позиции работы. Цена задания живёт в позиции, а не в
             * таблице плагина, и без номера плагин с собственной ценой не
             * смог бы её сохранить: форма редактора знает только задание.
             */
            $itemId,
            // Закрыть редактор — вернуться к списку заданий работы без
            // открытого задания. Настоящий backUrl для этого не годится:
            // он ведёт обратно с заданием, и «Отмена» выглядела бы как
            // ничего не сделавшая кнопка.
            'panel/assignments/' . $assignmentId . '/edit/items'
        );
    }
    /**
     * Плагины с видами заданий, которых нет в каталоге именно у этого
     * человека.
     *
     * Список берётся из того, что доступно ему сейчас, а не из общего флага:
     * набор плагинов личный, и плагин, отключённый учителем у себя, должен
     * быть назван здесь — иначе автор ищет «Вариативную задачу» в редакторе,
     * не находит и решает, что вида больше нет. На деле вид есть, и сказать
     * об этом должен редактор.
     *
     * @return list<string>
     */
    private function inactiveTaskPlugins(): array
    {
        $available = $this->plugins->availableIds();
        $out = [];

        foreach ($this->plugins->discover() as $id => $descriptor) {
            if ($descriptor->isBroken() || !$descriptor->providesTasks || in_array((string) $id, $available, true)) {
                continue;
            }

            $out[] = $descriptor->name !== '' ? $descriptor->name : (string) $id;
        }

        return $out;
    }
    /**
     * Доступные виды заданий, сгруппированные по плагинам.
     *
     * Группировка не украшение: список видов берётся у всех включённых
     * плагинов сразу, и без заголовка человек не понял бы, что «Загрузка
     * файла» и «Голосовой ответ» — из разных систем. Плагин без видов
     * пропускается целиком: пустая группа в каталоге выглядит поломкой.
     *
     * @return list<array{id:string,name:string,kinds:list<array<string,mixed>>}>
     */
    private function catalogueGroups(): array
    {
        $groups = [];

        foreach ($this->plugins->taskKindsByPlugin() as $pluginId => $kinds) {
            if ($kinds === []) {
                continue;
            }

            $plugin = $this->plugins->get((string) $pluginId);
            $rows = [];

            foreach ($kinds as $kind) {
                $rows[] = [
                    'slug'        => $kind->slug,
                    'title'       => $kind->title,
                    'description' => $kind->description,
                ];
            }

            $groups[] = [
                'id'    => (string) $pluginId,
                'name'  => $plugin?->name() ?? (string) $pluginId,
                'kinds' => $rows,
            ];
        }

        return $groups;
    }
    /**
     * Добавление задания из каталога видов.
     *
     * Цена в баллах берётся из той же формы, что и добавление: учитель
     * выбирает вид и сразу видит, сколько это стоит. Спрашивать цену вторым
     * шагом значило бы вернуться к тому же списку ещё раз ради одного числа.
     */
    public function addItemToWork(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $assignment = $this->requireOwned($assignmentId);

        $pluginId = (string) $request->postParam('plugin_id', '');
        $kind = (string) $request->postParam('task_kind', '');
        $plugin = $this->plugins->get($pluginId);

        if (!$plugin instanceof AssessmentPlugin || !$this->kindExists($pluginId, $kind)) {
            $this->session->set('flash_error', 'Этот вид задания сейчас недоступен');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

/*
         * Куда именно положить карточку.
         *
         * Форма палитры присылает ячейку, в которую её бросили: строка задаёт
         * место в работе, столбец — номер варианта. Без этих полей карточка
         * встаёт в конец новой строкой, и простое нажатие в палитре работает
         * так же, как раньше.
         *
         * Третий столбец — последний: четвёртой альтернативы в работе нет.
         */
        $row = (int) $request->postParam('row', '0');
        $variant = (int) $request->postParam('variant', '0');

        if ($row > 0 && $variant > self::MAX_VARIANTS) {
            /*
             * Четвёртой альтернативы у строки нет, и молча поставить её на
             * место третьей было бы враньём: автор увидел бы задание там, куда
             * не клал. Отказ прямо говорит, что место закончилось.
             */
            $this->session->set('flash_error', 'В строке не может быть больше трёх вариантов');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

        /*
         * Создание задания и вставка карточки — одна операция.
         *
         * Плагин пишет своё задание в свою таблицу, ядро кладёт на него
         * карточку. Пока это были две операции, вторая могла отказаться — строка
         * уже занята — и всё, что записал плагин, оставалось сиротой: задание
         * есть в его списке, в работе его нет, и почему так вышло человек
         * узнаёт через месяц. Раньше это лечилось вручную вызовом deleteTask,
         * а тот мог упасть — и тогда оставалось оба.
         *
         * Транзакция убирает саму возможность: отказ откатывает обе записи.
         * Плагин пишет через то же соединение, поэтому его вставка откатывается
         * вместе с ядровой.
         */
        try {
            $this->db->transaction(function () use ($plugin, $pluginId, $kind, $request, $assignment, $assignmentId, $row, $variant): void {
                $taskId = $plugin->createTask($kind, [
                    'author_id' => (int) $assignment['author_id'],
                    'title'     => trim((string) $request->postParam('title', '')),
                ]);

                $this->assignments->addItem(
                    $assignmentId,
                    new TaskRef($pluginId, $kind, $taskId),
                    $this->weight($request->postParam('weight')),
                    $this->postFloatParam($request->postParam('max_score')),
                    $row,
                    $variant
                );
            });
        } catch (\DomainException $e) {
            $this->session->set('flash_error', $e->getMessage() . ' — добавьте новую строку');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        } catch (\Throwable $e) {
            $pluginName = $this->plugins->descriptor($pluginId)?->name ?? $pluginId;
            $this->session->set(
                'flash_error',
                'Плагин «' . $pluginName . '» не смог добавить задание: ' . $e->getMessage()
            );

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

        $this->session->set('flash_notice', 'Задание добавлено. Наполните его и вернитесь сюда.');

        return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
    }
    /**
 * * Отдать черновик позиции.
 *
 * Отдаётся только автору работы: чужой черновик — это чужое несохранённое, и
 * отдавать его вместе с открытой формой значило бы показать человеку то, чего
 * он не писал.
 */
public function itemDraft(Request $request, array $params): Response
    {
        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');
        $this->requireOwned($assignmentId);
        $this->requireItemOf($assignmentId, $itemId);

        $draft = $this->drafts->find($this->authorId(), $itemId);

        if ($draft === null) {
            return Response::json(['ok' => true, 'draft' => null]);
        }

        return Response::json([
            'ok'     => true,
            'fields' => $draft['fields'],
            'at'     => $draft['savedAt'],
        ]);
    }
    /**
 * Записать или убрать черновик позиции.
 *
 * Черновик не публикует ничего: он лежит отдельно от задания, и ученик его не
 * видит. Успешное сохранение черновик убирает — иначе он пережил бы правку и
 * вернулся при следующем открытии уже сохранённого задания, то есть выглядел бы
 * как откат только что сделанного.
 */
public function saveItemDraft(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');
        $this->requireOwned($assignmentId);
        $this->requireItemOf($assignmentId, $itemId);
        $authorId = $this->authorId();

        if ($request->postParam('action') === 'drop') {
            $this->drafts->drop($authorId, $itemId);

            return Response::json(['ok' => true, 'dropped' => true]);
        }

        $fields = $request->postParam('fields', '');

        /*
         * Значения приходят строкой JSON, а не поимённым полем.
         *
         * У позиции десятки полей, имена которых знает только плагин: ядро их
         * не перечисляет и не может проверить. Поэтому разбор один на всех, а
         * отказ называет причину — иначе человек увидел бы пустую форму и решил
         * бы, что правка потерялась.
         */
        $decoded = is_string($fields) && $fields !== '' ? json_decode($fields, true) : null;

        if (!is_array($decoded)) {
            return Response::json(
                ['ok' => false, 'error' => 'Черновик не разобран: пришлите его заново'],
                422
            );
        }

        $this->drafts->save($authorId, $assignmentId, $itemId, $decoded);

        return Response::json(['ok' => true, 'savedAt' => date('Y-m-d H:i:s')]);
    }
    /**
     * Позиция принадлежит этой работе.
     *
     * Отдельный метод, потому что адрес `/assignments/{id}/items/{itemId}` сам по
     * себе ничего не проверяет: номер позиции можно назвать от другой работы, и
     * без такой сверки черновик уехал бы не туда.
     */
    private function requireItemOf(int $assignmentId, int $itemId): void
    {
        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
            throw new HttpException(404, 'Позиция не найдена');
        }
    }
    public function updateItemInWork(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');
        $this->requireOwned($assignmentId);

        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
            $this->session->set('flash_error', 'Позиция не найдена');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

        $position = $request->postParam('position');

        if ($position !== null && trim($position) !== '') {
            $this->assignments->moveItem($assignmentId, $itemId, (int) $position);
        }

        /*
         * Карточка переехала в другую ячейку доски.
         *
         * Это не перестановка позиции: строка (место в работе) и столбец
         * (номер варианта) приходят отдельными полями, и карточка встаёт в
         * нужное место вместе с альтернативами этой строки.
         */
        $row = $request->postParam('row');
        $variant = $request->postParam('variant');

        if ($row !== null && trim($row) !== '') {
            $this->assignments->moveCard(
                $assignmentId,
                $itemId,
                max(1, (int) $row),
                max(1, (int) ($variant ?? '1'))
            );
        }

        /*
         * Цена и вес обновляются только если их действительно прислали.
         *
         * Форм у позиции несколько: перемещение, цена, вес. Перемещение
         * отправляется кнопкой без остальных полей, и без этой проверки
         * перестановка задания молча обнуляла бы его стоимость и вес —
         * учитель ничего не трогал, а числа исчезли.
         */
        $data = [];

        if ($this->hasPostParam($request, 'weight')) {
            $data['weight'] = $this->weight($request->postParam('weight'));
        }

        if ($this->hasPostParam($request, 'max_score')) {
            $data['max_score'] = $this->postFloatParam($request->postParam('max_score'));
        }

        if ($data !== []) {
            /*
             * Цена и вес принадлежат строке, а не карточке: варианты делят
             * одно и то же значение. Иначе сумма цен перестала бы совпадать
             * с тем, что ученик может набрать, — а по ней считается вся
             * шкала оценок.
             */
            $row = (int) ($item['position'] ?? 1);
            $first = $this->assignments->variantsAt($assignmentId, $row)[0] ?? null;

            if ($first !== null) {
                $this->assignments->updateItem($data, (int) $first['id']);
            }
        }

        return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
    }
    /** Прислали ли поле формы — отличать «пусто» от «не прислано». */
    private function hasPostParam(Request $request, string $name): bool
    {
        return array_key_exists($name, $request->post());
    }
    public function destroyItemInWork(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $itemId = $this->intParam($params, 'itemId');

        $item = $this->assignments->findItem($itemId);

        if ($item === null || (int) $item['assignment_id'] !== $assignmentId) {
            $this->session->set('flash_error', 'Позиция не найдена');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

        // Владелец проверяется после чтения позиции: иначе по номеру чужой
        // работы можно было бы понять, что там есть.
        $this->requireOwned($assignmentId);

            try {
                $removed = $this->deleteItemWithPluginData($assignmentId, $item);
            } catch (TaskRemovalBlocked $e) {
                $this->session->set('flash_error', $e->getMessage());

                return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
            }

            $this->session->set(
                'flash_notice',
                $removed
                    ? 'Задание убрано из работы и удалено вместе с вопросами'
                    : 'Задание убрано из работы'
            );

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }
/**
 * Перестановка строки кнопками со стрелками.
 *
 * Кнопки нужны там, где перетаскивание не работает: без скриптов доска не
 * переставится, а порядок строк — это и есть порядок работы.
 */
public function moveRowInWork(Request $request, array $params): Response
{
    $this->requireToken($request, $this->csrf);

    $assignmentId = $this->intParam($params, 'id');
    $this->requireOwned($assignmentId);

    $from = $this->intParam($params, 'position');

    if ($from > 0) {
        $this->assignments->moveRow($assignmentId, $from, (int) $request->postParam('position', '0'));
    }

    return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
}
/**
 * Новый порядок строк целиком.
     *
     * Приходит после перетаскивания строк доски. Двигается строка вместе со
     * своими альтернативами: место в работе задаёт строка, а не карточка.
     *
     * Форма присылает номера строк в новом порядке, и применяются они в
     * обратном: moveRow двигает одну строку, и при проходе «вперёд» первая же
     * перетащенная строка сдвинула бы под собой все следующие.
     */
    public function reorderItems(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $assignmentId = $this->intParam($params, 'id');
        $this->requireOwned($assignmentId);

        $order = array_values(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            (array) $request->postArray('order')
        ), static fn (int $value): bool => $value > 0));

        $applied = $this->assignments->reorderRows($assignmentId, $order);

        /*
         * Скрипту отдаётся порядок, каким он стал на сервере, а не присланный.
         *
         * Перестановка считается на сервере: веса и соседние строки могли
         * разъехаться, и присланный список после этого уже не описывает доску.
         * Возвращать надо то, что верно, — иначе экран показал бы одно, а
         * ученик получил бы другое.
         *
         * Пустой ответ означает, что порядок не применён: набор строк на доске
         * разошёлся с тем, что прислал браузер. Молча показывать сохранённое
         * было бы враньём, поэтому это отказ с объяснением.
         *
         * Обычный POST без скриптов уходит адресом: перезагрузка страницы здесь
         * не нужна, но и не мешает — порядок и так применён.
         */
        if ($request->wantsJson()) {
            if ($applied === []) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Порядок не применён: строки на доске изменились, обновите страницу.',
                ], 409);
            }

            return Response::json([
                'ok' => true,
                'order' => $applied,
            ]);
        }

        if ($applied === []) {
            $this->session->set('flash_error', 'Порядок не применён: строки на доске изменились.');

            return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
        }

        return $this->redirect('panel/assignments/' . $assignmentId . '/edit/items');
    }
    /**
     * Строки шкалы для полей формы — для обоих режимов сразу.
     *
     * Форма содержит два набора полей и переключает их на месте. Значит, в ней
     * лежат границы обоих режимов: показывать дефолты вместо сохранённых
     * значило бы стереть у учителя то, что он набрал, стоило ему щёлкнуть
     * переключателем и вернуться.
     *
     * Берутся из сохранённых критериев, а при их отсутствии — из шкалы по
     * умолчанию для режима. Форма показывает значения, а не пустые поля:
     * пустая шкала выглядит как «ещё не настроено».
     *
     * @param array<string,mixed> $assignment
     *
     * @return array{percent:array<int,array{from:?float,to:?float,label:string}>,score:array<int,array{from:?float,to:?float,label:string}>}
     */
    private function scaleRows(array $assignment, float $total): array
    {
        $saved = json_decode((string) ($assignment['criteria'] ?? ''), true);
        $saved = is_array($saved) ? $saved : [];

        $stored = [];

        foreach ($saved as $row) {
            if (!is_array($row)) {
                continue;
            }

            $grade = (int) ($row['grade'] ?? 0);

            if (!in_array($grade, GradeScaleValidator::GRADES, true)) {
                continue;
            }

            $stored[$grade] = $row;
        }

        $rows = [];

        foreach (['percent' => false, 'score' => true] as $mode => $byScore) {
            $source = $byScore
                ? GradeScaleValidator::scoreDefaults($total)
                : GradeScaleValidator::percentDefaults();

            foreach ($stored as $grade => $row) {
                // Сохранённые границы относятся к тому режиму, в котором их
                // записывали: в баллах это min_score, а в процентах min_percent.
                if ($byScore !== array_key_exists('min_score', $row)) {
                    continue;
                }

                $source[] = [
                    'grade' => $grade,
                    'from'  => $byScore ? (float) ($row['min_score'] ?? 0) : (float) ($row['min_percent'] ?? 0),
                    'to'    => $byScore && array_key_exists('max_score', $row) ? (float) $row['max_score'] : null,
                    'color' => (string) ($row['color'] ?? ''),
                ];
            }

            foreach (GradeScaleValidator::GRADES as $grade) {
                $from = null;
                $to = null;
                $color = null;

                foreach ($source as $candidate) {
                    if ((int) $candidate['grade'] !== $grade) {
                        continue;
                    }

                    // Последняя подходящая строка побеждает: сохранённая идёт
                    // после значения по умолчанию и должна перекрыть его.
                    $from = (float) $candidate['from'];
                    $to = $candidate['to'] ?? null;
                    $color = (string) ($candidate['color'] ?? '');
                }

                /*
                 * Прежние шкалы цвета не содержали: подставляется цвет по
                 * умолчанию, и такая работа не выглядит бесцветной и не
                 * требует пересохранения.
                 */
                $rows[$mode][$grade] = [
                    'from'  => $from,
                    'to'    => $to,
                    'color' => GradeScaleValidator::normalizeColor($color, $grade),
                ];
            }
        }

        return ['percent' => $rows['percent'] ?? [], 'score' => $rows['score'] ?? []];
    }
    /**
     * Сумма цен заданий работы.
     *
     * Считается по строкам, а не по карточкам: ученик выполняет по одной
     * позиции в строке, и верхняя граница пятёрки обязана совпадать с тем, что
     * он может набрать. Считать каждую альтернативу значило бы показать сумму,
     * которой не бывает ни у одного ученика.
     *
     * Берётся цена первой карточки строки: варианты делят цену и вес, и
     * пока делят, брать первую — то же самое, что брать усреднённую.
     *
     * Позиции без цены не считаются нулевыми: у них цену назначит плагин,
     * и до первой сдачи работы её просто не из чего взять. Такие позиции в
     * сумму не входят, поэтому показанное число — это то, что действительно
     * задал учитель, а не догадка.
     */
    private function itemsMaxScore(int $assignmentId): float
    {
        $total = 0.0;

        foreach ($this->assignments->rows($assignmentId) as $row) {
            $first = $row['variants'][0] ?? null;

            if ($first !== null && $first['max_score'] !== null) {
                $total += (float) $first['max_score'];
            }
        }

        return round($total, 2);
    }
/**
  * Сохранение шкалы оценивания.
      *
      * При ошибках форма показывается заново вместе с тем, что человек ввёл:
      * отправлять его обратно на пустую страницу значило бы заставлять
      * набирать заново четыре границы из-за одной неверной цифры.
      */
    public function saveResult(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $assignment = $this->requireOwned($id);

        $submission = $this->criteriaSubmission($request, $this->itemsMaxScore($id));

        if (!$submission['check']['ok']) {
            $this->session->set(
                'flash_error',
                'Диапазоны не сохранены: ' . implode('; ', $submission['check']['messages'])
            );

            return $this->renderResultForm(
                $request,
                $assignment,
                $submission['mode'],
                $this->itemsMaxScore($id),
                $submission['values'],
                $submission['check'],
                // Отметка «показывать результат» переносится с формы: при
                // ошибке границ форма возвращается, и галочка иначе сбросилась
                // бы молча, будто автор её и не ставил. `=== '1'`, а не
                // `!== null`: у галочки есть скрытый спутник, и поле приходит
                // всегда — по одному наличию снятая галочка читалась бы
                // отмеченной.
                $request->postParam('show_results') === '1'
            );
        }

        $this->saveCriteria($request, $submission, $id);

        $this->session->set('flash_notice', 'Итоговая оценка сохранена');

        return $this->redirect('panel/assignments/' . $id);
    }
    /**
     * Разбор полей шкалы из запроса и проверка границ.
     *
     * Одна запись на оба сохранения — и отдельное сохранение шкалы, и общее
     * сохранение редактора. Расходились бы они при первом же новом поле: в
     * одном месте оно появилось бы, в другом нет, и одна из кнопок молча
     * сохраняла бы старую шкалу.
     *
     * Режим берётся из переключателя, границы — по именам полей того режима,
     * который отмечен. Наборов два, и уходят оба: переключение не стирает уже
     * набранное, а виден только отмеченный — это делает CSS.
     *
     * Если полей с именем режима нет, форма пришла старая — с общими именами:
     * берём их как есть, чтобы открытая до обновления вкладка не потеряла
     * правку молча.
     *
     * @return array{
     *     mode:string,
     *     values:array<int,array{from:?float,to:?float,color:string}>,
     *     check:array{ok:bool,messages:list<string>,badFrom:list<int>,badTo:list<int>}
     * }
     */
    private function criteriaSubmission(Request $request, float $total): array
    {
        $mode = $this->criteriaMode($request->postParam('criteria_mode'));

        $bounds = $request->postArray($mode . '_min');
        $maxes = $mode === 'score' ? $request->postArray('score_max') : [];

        /*
         * Цвет один на оба режима: он не зависит от того, чем считают, и две
         * копии по одной подписи в каждом наборе разошлись бы при первой
         * правке так же, как разошлись бы границы. Имя общее — `color[]`.
         */
        $colors = $request->postArray('color');

        if ($bounds === []) {
            $bounds = $request->postArray('criteria_min');
            $maxes = $mode === 'score' ? $request->postArray('criteria_max') : [];
        }

        $rows = [];
        $values = [];
        $lowestGrade = GradeScaleValidator::GRADES[count(GradeScaleValidator::GRADES) - 1];

        foreach (GradeScaleValidator::GRADES as $index => $grade) {
            $from = $this->postFloatParam(
                isset($bounds[$index]) && is_scalar($bounds[$index]) ? (string) $bounds[$index] : null
            );

            /*
             * У нижней оценки поле границы пустое — диапазон тянется от нуля,
             * и спрашивать число там не о чем. Пустое поле значит «от нуля», а не
             * «строки нет»: иначе отправка формы убрала бы из шкалы последнюю
             * оценку, и результат между нижней и следующей не попал бы ни в одну.
             */
            if ($from === null && $grade === $lowestGrade) {
                $from = 0.0;
            }

            $to = $mode === 'score'
                ? $this->postFloatParam(
                    isset($maxes[$index]) && is_scalar($maxes[$index]) ? (string) $maxes[$index] : null
                )
                : null;

            // Значение приходит из браузера, поэтому сверяется с палитрой.
            $color = GradeScaleValidator::normalizeColor(
                isset($colors[$index]) && is_scalar($colors[$index]) ? $colors[$index] : null,
                $grade
            );

            $values[$grade] = [
                'from'  => $from,
                'to'    => $to,
                'color' => $color,
            ];

            $rows[] = ['grade' => $grade, 'from' => $from, 'to' => $to];
        }

        return [
            'mode'   => $mode,
            'values' => $values,
            'check'  => GradeScaleValidator::check($rows, $mode === 'score', $mode === 'score' ? $total : 100.0),
        ];
    }
    /**
     * Запись разобранной шкалы в работу.
     *
     * @param array{mode:string,values:array<int,array<string,mixed>>,check:array<string,mixed>} $submission
     */
    private function saveCriteria(Request $request, array $submission, int $assignmentId): void
    {
        $data = [
            'criteria_mode' => $submission['mode'],
            'grade_mode'    => $this->gradeMode($request->postParam('grade_mode')),
            'criteria'      => $this->criteria($request, $submission['mode']),
        ];

        /*
         * Показ результата — та же правило, что в `applyStartFields`: поле
         * пишется только когда прислало само. Форма этой вкладки несёт
         * скрытого спутника галочки, поэтому в обычном сохранении значение
         * приходит всегда, а форма без галочки поле не трогает.
         */
        if ($request->postParam('show_results') !== null) {
            $data['show_results'] = $request->postParam('show_results') === '1' ? 1 : 0;
        }

        $this->assignments->update($data, ['id' => $assignmentId]);
    }
    /**
     * Редактор с несохранёнными границами.
     *
     * Введённые границы относятся к тому режиму, в котором учитель их
     * набирал. Набор другого режима остаётся дефолтным: подставить туда
     * проценты вместо баллов означало бы показать ему правдоподобные числа
     * не его шкалы, и он сохранил бы чужую.
     *
     * @param array<int,array{from:?float,to:?float,label:string}>       $values
     * @param array{ok:bool,messages:list<string>,badFrom:list<int>,badTo:list<int>} $check
     */
    private function renderResultForm(
        Request $request,
        array $assignment,
        string $mode,
        float $total,
        array $values,
        array $check,
        ?bool $showResults = null
    ): Response {
        return $this->renderEdit($request, (int) $assignment['id'], 'result', [
            'mode'         => $mode,
            'values'       => $values,
            'show_results' => $showResults === true,
            'bad_from'     => $check['badFrom'],
            'bad_to'       => $check['badTo'],
            'messages'     => $check['messages'],
        ]);
    }
    /**
     * Публикация работы.
     *
     * В редакторе публикация — переключатель в шапке, но адрес остаётся:
     * публиковать работу можно и со страницы работы, и по прямой ссылке.
     *
     * Отказ не теряет работу автора: он возвращает в редактор и открывает там
     * окно с препятствиями, — иначе человек, нажавший «Опубликовать» в
     * последний раз, ушёл бы со страницы без ответа.
     */
    public function publish(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $assignment = $this->requireOwned($id);

        /*
         * Проверки повторяются на самом POST, а не только при показе страницы:
         * между открытием редактора и нажатием кнопки автор мог убрать последнее
         * задание или стереть шкалу на соседней вкладке.
         */
        $blockers = $this->publishBlockers($id, $assignment);

        if ($blockers !== []) {
            $this->session->set('publish_blocked', $blockers);
            $this->session->set('flash_error', 'Опубликовать нельзя: ' . implode('; ', $blockers));

            return $this->redirect('panel/assignments/' . $id . '/edit');
        }

        $message = $this->assignments->publish($id);

        $this->session->set('flash_notice', $message);

        return $this->redirect('panel/assignments/' . $id);
    }
    /**
     * Общее для всех этапов: меню мастера, заголовок, сообщения.
     *
     * Отметка «этап пройден» не вычисляется из полноты данных: половина
     * работы не приходит к концу за один присест, и человек возвращается
     * к первому этапу без всякой вины. Пройденным считается то, до чего
     * человек сам дошёл, — так меню показывает дорогу, а не оценку.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
private function wizardView(array $assignment, string $current, array $extra = []): array
{
    $id = (int) $assignment['id'];

    /*
     * У ещё не созданной работы номера нет, и шаги ведут на себя.
     *
     * Полоса шагов при этом остаётся: человек видит, что дальше будет, и не
     * думает, что мастер обрывается. Переходы включатся сами после первого
     * сохранения — тогда у работы появится номер.
     */
    $base = $id > 0 ? 'panel/assignments/' . $id : 'panel/assignments/new';

    $steps = [
        'start'   => ['label' => 'Начальная страница', 'icon' => 'step_start', 'url' => $base . ($id > 0 ? '/edit/start' : '/start')],
        'items'   => ['label' => 'Задания',           'icon' => 'step_items', 'url' => $base . ($id > 0 ? '/edit/items' : '/start')],
        'result'  => ['label' => 'Результат',         'icon' => 'step_result', 'url' => $base . ($id > 0 ? '/edit/result' : '/start')],
        /*
         * Публикация — переключатель в шапке редактора, отдельного шага нет.
         *
         * Поэтому шага «Опубликовать» в полосе этапов больше нет: он вёл бы в
         * адрес, которого больше не существует.
         */
    ];
    $order = array_keys($steps);
    $reached = $id > 0
        ? $order[$this->reachedStep($id, (string) $assignment['status'])]
        : 0;
    $wizardSteps = [];

        foreach ($order as $index => $key) {
            $wizardSteps[] = [
                'label'   => $steps[$key]['label'],
                'icon'    => $steps[$key]['icon'],
                'url'     => $steps[$key]['url'],
                'current' => $key === $current,
                'done'    => $index < $reached,
            ];
        }

        return $extra + [
            'title'      => (string) $assignment['title'],
            'assignment' => $assignment,
            'wizard'     => ['title' => 'Редактор', 'steps' => $wizardSteps],
            'notice'     => $this->session->pull('flash_notice'),
            'error'      => $this->session->pull('flash_error'),
        ];
    }
/**
 * Какой этап работы считается пройденным.
     *
     * Для черновика — первый: работа ещё пуста, и отмечать пройденным нечего.
     * Для опубликованной — все четыре: она уже ушла ученикам, и незакрытых
     * этапов у неё не осталось.
     */
    private function reachedStep(int $assignmentId, string $status): int
    {
        if ($status === 'published' || $status === 'archived') {
            return 3;
        }

        return (int) $this->db->value(
            'SELECT COUNT(*) FROM assignment_items WHERE assignment_id = :id',
            ['id' => $assignmentId]
        ) > 0 ? 1 : 0;
    }

    // ---------------------------------------------------------------- служебное
    /**
     * Критерии оценки работы из формы.
     *
     * Пустая строка означает «взять шкалу по умолчанию».
     *
     * Оценки из формы не берутся: их ровно четыре — 2, 3, 4, 5. Принимать их
     * от автора незачем, в школьной России других оценок нет, а произвольный
     * набор или пустой список дали бы шкалу, которую нельзя объяснить ни
     * ученику, ни родителю.
     *
     * Ключ границы зависит от режима, и это не украшение, а условие
     * правильности: в режиме «по баллам» граница 8 — это восемь баллов, а
     * записанная она под именем min_percent была бы прочитана как восемь
     * процентов. Работа получила бы правдоподобные и совсем не те оценки,
     * и заметить это можно было бы только по разбирам.
     */
    private function criteria(Request $request, string $mode = 'percent'): string
    {
        $rows = [];

        /*
         * Имена полей — по режиму, как и при проверке. Наборов два, и уходят
         * оба; брать чужие границы здесь нельзя, иначе переключение режима
         * сохраняло бы шкалу из полей, которых автор не касался.
         */
        $bounds = $request->postArray($mode . '_min');
        $maxes = $mode === 'score' ? $request->postArray('score_max') : [];
        $colors = $request->postArray('color');

        // Форма, открытая до правки: имена прежние, общие.
        if ($bounds === []) {
            $bounds = $request->postArray('criteria_min');
            $maxes = $mode === 'score' ? $request->postArray('criteria_max') : [];
        }

        // В режиме баллов граница — дробная: половина балла на границе
        // диапазона вполне обычна, а в процентах она бессмысленна.
        $byScore = $mode === 'score';

        foreach (GradeScaleValidator::GRADES as $index => $grade) {
            $min = $this->postFloatParam(
                isset($bounds[$index]) && is_scalar($bounds[$index]) ? (string) $bounds[$index] : null
            );

            if ($min === null) {
                // Нижняя оценка начинается от нуля, и пустое поле у неё — это
                // как раз ноль. Иначе строка выпала бы из шкалы целиком, и
                // результат не попал бы ни в одну оценку. Остальные пустые
                // пропускаются: пропуск границы у пятёрки — это недоказанная
                // шкала, а не заданная.
                if ($grade !== GradeScaleValidator::GRADES[count(GradeScaleValidator::GRADES) - 1]) {
                    continue;
                }

                $min = 0.0;
            }

            $row = [
                'grade' => $grade,
                ($byScore ? 'min_score' : 'min_percent') => $min,
                'color' => GradeScaleValidator::normalizeColor(
                    isset($colors[$index]) && is_scalar($colors[$index]) ? $colors[$index] : null,
                    $grade
                ),
            ];

            if ($byScore) {
                $to = $this->postFloatParam(
                    isset($maxes[$index]) && is_scalar($maxes[$index]) ? (string) $maxes[$index] : null
                );

                /*
                 * Без верхней границы строка не пишется вовсе. Подставить ноль
                 * нельзя: «до 0» сделало бы оценку недостижимой, и в базе такая
                 * граница выглядела бы заданной, а её нет.
                 */
                if ($to !== null) {
                    $row['max_score'] = $to;
                }
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return '';
        }

        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
    /**
     * По чему считается итоговая оценка.
     *
     * Всегда одно из двух. Смешанная шкала не объясняется: ученику сказали бы
     * «от 7 баллов», а посчитали бы от процентов, или наоборот, и расхождение
     * обнаружилось бы только в журнале. Спросить второй раз нельзя — форма
     * одна, и второе значение просто отбросилось бы, оставив человека с
     * шкалой, которую он не выбирал.
     */
    private function criteriaMode(?string $value): string
    {
        return $value === 'score' ? 'score' : 'percent';
    }
}
