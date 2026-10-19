<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable;

use MyEdu\Auth\Role;
use MyEdu\Plugin\PluginServices;
use MyEdu\Core\Config;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\TextFragment;
use MyEdu\Core\View;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\EditorContext;
use MyEdu\Plugin\ItemGrade;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\PluginMigrationRunner;
use MyEdu\Plugin\PortablePlugin;
use MyEdu\Plugin\TaskKind;
use MyEdu\Plugin\TaskRef;
use MyEduPlugin\Variable\Http\TaskEditorController;
use MyEduPlugin\Variable\Repository\AttemptRepository;
use MyEduPlugin\Variable\ProblemConfig;
use MyEduPlugin\Variable\Repository\TaskRepository;
use RuntimeException;

/**
 * Плагин «Вариативная задача».
 *
 * Отдельный способ проверки знаний, а не тип вопроса внутри «Теста»: у него
 * своя форма ответа (число по каждой переменной), своя схема хранения и своя
 * проверка. Тест про несколько вопросов про другое, и смешивать их значило бы
 * тащить в чужой плагин то, что ему не принадлежит.
 *
 * Ядро о вариативной задаче не знает: оно видит объявленный вид задания и
 * результат проверки.
 */
final class VariablePlugin implements AssessmentPlugin, PortablePlugin
{
    /** Вид задания, который видит учитель в списке типов. */
    public const KIND = 'generated';


    /** Адрес раздела плагина. */
    private const PREFIX = 'variables';

    /**
     * Данные заданий, уже прочитанные в этом запросе.
     *
     * Ядро спрашивает данные по каждой позиции работы, и один и тот же вопрос
     * задаётся несколько раз за страницу. Кэш живёт ровно один запрос: плагин
     * создаётся на запрос и умирает вместе с ним, поэтому «протухнуть» здесь
     * нечему. Общая копия в памяти процесса была бы уже другой задачей.
     *
     * @var array<int,array<string,mixed>|null>
     */
    private array $taskDataCache = [];

    /** @var array<int,string> */
    private array $taskPreviewCache = [];

    private ?TaskEditorController $editor = null;

    private ?TaskRepository $tasks = null;

    private ?AttemptRepository $attempts = null;

    private ?WorkItemHandler $work = null;

/**
 * Идентификатор, известный классу.
 *
 * Одно значение, ради которого плагин узнаёт себя в манифесте: название и
 * версию он берёт оттуда и не повторяет здесь. Повторение расходилось с
 * `plugin.json` при первом же переименовании, и расхождение было тихим:
 * обновление приходило по одной версии, а плагин называл себя по другой.
 */
private const PLUGIN_ID = 'variable';

public function __construct(private readonly PluginServices $services)
{
}

public function id(): string
{
    return self::PLUGIN_ID;
}

public function name(): string
{
    return $this->services->manifest()?->name ?? self::PLUGIN_ID;
}

public function version(): string
{
    return $this->services->manifest()?->version ?? '0.0.0';
}

    /**
     * @return list<TaskKind>
     */
    public function taskKinds(): array
    {
        return [
            new TaskKind(
                self::KIND,
                'Вариативная задача',
                'Каждому ученику выпадают свои числа, ответ — число по каждой переменной',
                TaskKind::SCORING_POINTS
            ),
        ];
    }

    public function knowsKind(string $kind): bool
    {
        return $kind === self::KIND;
    }

    /**
     * Свои адреса.
     *
     * Остался один: он принимает сохранение формы редактора. Страницы задания
     * и публикации задания больше нет — задание живёт в списке позиций
     * работы, а публикуется работа целиком последним шагом мастера.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function routes(): array
    {
        return [
            ['POST', '/task/{id}', 'taskSaveAction', Role::TESTS_MANAGE],
        ];
    }

    /**
     * Свои миграции, один раз при первом включении.
     */
public function install(): void
    {
        $dir = $this->services->ownPlugin(self::id())->migrationsDir();

        if ($dir === null) {
            throw new RuntimeException('Каталог плагина не найден на диске — миграции применить некуда');
        }

        (new PluginMigrationRunner(
            $this->database(),
            self::id(),
            $dir,
            $this->services->dump()
        ))->apply();
    }

    // ---------------------------------------------------------------- маршруты

    public function taskSaveAction(Request $request, array $params): Response
    {
        return $this->editorController()->taskSave($request, $params);
    }

    // ---------------------------------------------------------------- контракт

    /**
     * Создание нового задания по запросу редактора работы.
     *
     * Задание сразу остаётся черновиком: пустое условие ученику показывать
     * нельзя, а заполняет его автор на следующем шаге.
     *
     * @param array<string,mixed> $context
     */
    public function createTask(string $kind, array $context = []): int
    {
        if ($kind !== self::KIND) {
            throw new RuntimeException('Плагин не умеет создавать вид задания: ' . $kind);
        }

        return $this->taskRepository()->create(
            (int) ($context['author_id'] ?? 0),
            trim((string) ($context['title'] ?? '')),
            isset($context['subject_id']) && is_numeric($context['subject_id'])
                ? (int) $context['subject_id']
                : null
        );
    }

    /**
     * Адрес редактора задания.
     *
     * Строится через префикс из манифеста: так адрес не разойдётся с ядром, если
     * автор переименует раздел.
     */
public function taskEditorUrl(TaskRef $task): ?string
      {
          /*
           * Отдельной страницы редактора у задания нет, поэтому и адреса у него
           * нет. null здесь — не «задание не правится вручную»: правится, и ядро
           * открывает форму по адресу ?edit= внутри списка позиций работы.
           * Здесь нужен адрес, куда можно уйти со страницы плагина, а уходить
           * некуда — поэтому null, а не ссылка на несуществующий адрес.
           */
          return null;
      }

    /**
     * Сырые данные задания из своей базы.
     *
     * Ядру они нужны, чтобы показать название и вернуть их плагину обратно при
     * проверке. Больше ядро с ними ничего не делает.
     *
     * @return array<string,mixed>
     */
    public function taskData(TaskRef $task): array
    {
        $id = (int) $task->taskId;

        if (!array_key_exists($id, $this->taskDataCache)) {
            $this->taskDataCache[$id] = $this->taskRepository()->find($id);
        }

        return $this->taskDataCache[$id] ?? [];
    }

    /**
     * Данные сразу нескольких заданий — одним обращением к базе.
     *
     * @param list<TaskRef> $tasks
     *
     * @return list<array<string,mixed>>
     */
    public function taskDataBatch(array $tasks): array
    {
        $missing = [];

        foreach ($tasks as $task) {
            $id = (int) $task->taskId;

            if (!array_key_exists($id, $this->taskDataCache)) {
                $missing[$id] = $id;
            }
        }

        if ($missing !== []) {
            foreach ($this->taskRepository()->findMany(array_values($missing)) as $id => $row) {
                $this->taskDataCache[(int) $id] = $row;
            }
        }

        $out = [];

        foreach ($tasks as $task) {
            /*
             * Отсутствующее задание — это [], а не исключение: страница должна
             * показать остальные позиции работы. Исключение здесь уронило бы всю
             * работу из-за одного удалённого задания.
             */
            $out[] = $this->taskDataCache[(int) $task->taskId] ?? [];
        }

        return $out;
    }

    /**
     * Форма редактора задания без оболочки страницы.
     *
     * Для встраивания в редактор работы: там своя шапка и своя навигация.
     */
    public function renderEditorFragment(EditorContext $context): string
    {
        if ($context->task === null || $context->task->taskId <= 0) {
            throw new RuntimeException('Форма редактора строится для созданного задания.');
        }

        return $this->editorController()->taskFragment(
            $context->task->taskId,
            $context->backUrl,
            $context->assignmentPublished,
            $context->closeUrl,
            $context->itemId,
            $this->itemMaxScore($context->itemId)
        );
    }

    /**
     * Цена позиции работы, которой принадлежит задание.
     *
     * Цена живёт в позиции работы, а не в таблице плагина: одна и та же работа
     * складывается из заданий разных плагинов, и ядро считает сумму цен,
     * поэтому и хранит её у себя. Задание без позиции (ещё не добавленное в
     * работу) цены не имеет — там поле и не показывается.
     */
    private function itemMaxScore(?int $itemId): ?float
    {
        if ($itemId === null || $itemId <= 0) {
            return null;
        }

        $item = $this->services->assignments()->findItem($itemId);

        if ($item === null || $item['max_score'] === null) {
            return null;
        }

        return (float) $item['max_score'];
    }

    public function renderTask(AttemptContext $context): Response
    {
        return $this->workHandler()->render($context);
    }

    public function saveAnswer(AttemptContext $context, Request $request): void
    {
        $this->workHandler()->saveAnswer($context, $request);
    }

    public function grade(AttemptContext $context): ItemGrade
    {
        return $this->workHandler()->grade($context);
    }

    public function renderReview(AttemptContext $context, ItemGrade $grade): Response
    {
        return $this->workHandler()->renderReview($context, $grade);
    }

    public function renderSubmitted(AttemptContext $context): ?Response
    {
        return $this->workHandler()->renderSubmitted($context);
    }

public function canManageTask(TaskRef $task, int $userId): bool
    {
        $row = $this->taskRepository()->find($task->taskId);

        return $row !== null && (int) $row['author_id'] === $userId;
    }

    /**
     * Задание можно выпускать ученикам, если в нём есть условие и формула,
     * которая считается на краях диапазонов.
     *
     * Раньше ту же проверку выполняла публикация отдельного задания. Отдельной
     * публикации больше нет — задание выпускается вместе с работой, — и без
     * этого метода работа опубликовалась бы с заданием, в котором ученику не
     * на что ответить: без условия или без формулы правильный ответ не
     * считается вовсе.
     */
    public function publicationBlocker(TaskRef $task): ?string
    {
        $row = $this->taskRepository()->find($task->taskId);

        if ($row === null) {
            return 'Задание не найдено';
        }

        $prompt = trim((string) $row['prompt']);
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];

        if ($prompt === '') {
            return 'В задании не заполнено условие';
        }

        try {
            ProblemConfig::validate($config, $prompt);
        } catch (InvalidProblemException $e) {
            return 'Задание не готово: ' . $e->getMessage();
        }

        return null;
    }

public function taskPreview(TaskRef $task): string
    {
        $id = (int) $task->taskId;

        if (isset($this->taskPreviewCache[$id])) {
            return $this->taskPreviewCache[$id];
        }

        $row = $this->taskRepository()->find($id);

        return $this->taskPreviewCache[$id] = $row === null
            ? ''
            : TextFragment::from((string) ($row['prompt'] ?? ''));
    }

    /**
     * Удаление задания.
     *
     * Сначала проверяется, не осталось ли задание в другой работе: работа
     * строится из уникального набора заданий, но полагаться на это в коде
     * удаления нельзя — при спорах одного задания чужая работа получила бы
     * задание, которого больше нет.
     */
    public function deleteTask(TaskRef $task): bool
    {
        $taskId = $task->taskId;

        if ($this->taskRepository()->references($taskId) > 0) {
            return false;
        }

        // Ответы хранят номерами, а не внешним ключом: снятое задание
        // освобождает номер, и без удаления следующая работа на нём показала бы
        // ученику чужие ответы.
        $this->attemptRepository()->deleteForTask($taskId);
        $this->taskRepository()->delete($taskId);

        return true;
    }

    /**
     * Позиция работы снята — прохождение по ней больше не нужно.
     */
    public function deleteItem(TaskRef $task, int $itemId): void
    {
        $this->attemptRepository()->deleteForItem($itemId);
    }

    // ---------------------------------------------------------------- помощники

    public function taskRepository(): TaskRepository
    {
        return $this->tasks ??= new TaskRepository($this->database());
    }

    public function attemptRepository(): AttemptRepository
    {
        return $this->attempts ??= new AttemptRepository($this->database());
    }

    public function grader(): ProblemGrader
    {
        return new ProblemGrader();
    }

    private function editorController(): TaskEditorController
    {
        return $this->editor ??= new TaskEditorController(
            $this->view(),
            $this->services->session(),
            $this->services->csrf(),
            $this->services->auth(),
            $this->config(),
            $this->taskRepository(),
            $this->services->assignments(),
            $this->urlPrefix()
        );
    }

    private function workHandler(): WorkItemHandler
    {
        return $this->work ??= new WorkItemHandler(
            $this->view(),
            $this->taskRepository(),
            $this->attemptRepository(),
            $this->grader()
        );
    }

    public function view(): View
    {
        return $this->services->view();
    }

    private function database(): Database
    {
        return $this->services->database();
    }

    private function config(): Config
    {
        return $this->services->config();
    }

    /**
     * Префикс адресов — из манифеста, чтобы ядро и плагин строили ссылки
     * одинаково, даже если автор переименует раздел.
     */
private function urlPrefix(): string
    {
        return $this->services->ownPlugin(self::id())->url();
    }

    /**
     * Перенос вариативной задачи.
     *
     * Настройка целиком лежит в одном поле config — переменные, формула, допуск и
     * единица, — поэтому переносится она тем же текстом, каким была записана.
     * Разбирать её и собирать заново незачем и опасно: формула пережила бы
     * перенос только потому, что её никто не трогал.
     *
     * Статус переносится как был: опубликованное задание остаётся опубликованным.
     * Публикуется работа целиком, и решать это должен человек на новом месте.
     */
    public function exportTask(TaskRef $task): ?array
    {
        $row = $this->taskRepository()->find($task->taskId);

        if ($row === null) {
            return null;
        }

        return [
            'title'   => (string) $row['title'],
            'prompt'  => (string) $row['prompt'],
            'config'  => (string) ($row['config'] ?? ''),
            'status'  => (string) $row['status'],
        ];
    }

public function importTask(array $data, string $kind, int $authorId, ?int $subjectId = null): int
    {
        /*
         * Настройки проверяются до записи, тем же правилом, что и в редакторе.
         *
         * Раньше `config` из чужого пакета ложился в базу как есть, и ошибка
         * всплывала на живой работе: формула без переменных, пример из
         * неизвестных имён — и ученик получает задание, которое не считается.
         * Импорт при этом отчитывался как успешный.
         *
         * Ядро ловит исключение плагина и откатывает вставку, а позиция попадает
         * в отчёт как «плагин не смог создать задание» с текстом причины.
         */
        $prompt = (string) ($data['prompt'] ?? '');
        $rawConfig = $data['config'] ?? [];

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            $rawConfig = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($rawConfig)) {
            throw new RuntimeException('настройки задачи не читаются — это должен быть JSON');
        }

        try {
            ProblemConfig::validate($rawConfig, $prompt);
        } catch (InvalidProblemException $e) {
            throw new RuntimeException('настройки задачи неверны: ' . $e->getMessage(), 0, $e);
        }

        $id = $this->taskRepository()->create(
            $authorId,
            mb_substr(trim((string) ($data['title'] ?? 'Задача')), 0, 191),
            $subjectId,
            $prompt
        );

        $status = (string) ($data['status'] ?? 'draft');

        $this->taskRepository()->update($id, [
            'config' => (string) json_encode($rawConfig, JSON_UNESCAPED_UNICODE),
            'status' => $status === 'published' ? 'published' : 'draft',
        ]);

        return $id;
    }
}
