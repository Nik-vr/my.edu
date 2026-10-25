<?php

declare(strict_types=1);

namespace MyEduPlugin\Test;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Plugin\PluginServices;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\TextFragment;
use MyEdu\Core\View;
use MyEduPlugin\Test\Grading\AttemptGrader;
use MyEduPlugin\Test\Grading\GradeCalculator;
use MyEduPlugin\Test\Http\TestEditorController;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;
use MyEduPlugin\Test\Repository\TestEditorRepository;
use MyEduPlugin\Test\Repository\TestRepository;
use MyEduPlugin\Test\Repository\WorkItemRepository;
use MyEduPlugin\Test\Work\WorkItemHandler;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\AttemptAwarePlugin;
use MyEdu\Plugin\ClassAwarePlugin;
use MyEdu\Plugin\AttemptContext;
use MyEdu\Plugin\EditorContext;
use MyEdu\Plugin\ItemGrade;
use MyEdu\Plugin\PluginManager;
use MyEdu\Plugin\PluginMigrationRunner;
use MyEdu\Plugin\PortablePlugin;
use MyEdu\Plugin\TaskKind;
use MyEdu\Plugin\TaskRef;
use RuntimeException;
use Throwable;

/**
 * Плагин «Тест с вопросами».
 *
 * Первый плагин системы: он показывает, как обычный тест становится одним из
 * способов проверки знаний наравне с будущими играми и тренажёрами.
 *
 * Всё, что относится к тесту, лежит здесь: правила проверки ответов, хранение
 * тестов и попыток, экраны учителя и ученика. Ядро о тесте не знает — оно
 * видит только объявленные виды заданий и результат проверки.
 */
final class TestPlugin implements AssessmentPlugin, PortablePlugin, ClassAwarePlugin, AttemptAwarePlugin
{
    /** Вид задания, который видит учитель в списке типов. */
    public const KIND = 'test';

    /** Идентификатор по умолчанию: тест без настройки. */
    public const DEFAULT_PREFIX = 'tests';

    private ?TestEditorController $editorController = null;

    /**
     * Данные заданий, уже прочитанные в этом запросе.
     *
     * Ядро спрашивает название и данные задания по каждой позиции работы: на
     * списке заданий, на результате и в разборе. Один и тот же вопрос задаётся
     * по несколько раз за страницу, а ответ между этими обращениями не меняется
     * — работа и позиции не правятся посреди отрисовки.
     *
     * Кэш живёт ровно один запрос: плагин создаётся на запрос и умирает вместе
     * с ним, поэтому «протухнуть» здесь нечему. Общая копия задания в памяти
     * процесса была бы уже другой задачей — с обновлениями и синхронизацией.
     *
     * @var array<int,array<string,mixed>|null>
     */
    private array $taskDataCache = [];

    /** @var array<int,string> */
    private array $taskPreviewCache = [];

    private ?WorkItemHandler $workHandler = null;

    private ?TestEditorRepository $editorRepository = null;

    private ?WorkItemRepository $workItems = null;

/**
 * Идентификатор, известный классу.
 *
 * Одно значение, ради которого плагин узнаёт себя в манифесте: название и
 * версию он берёт оттуда и не повторяет здесь. Повторение расходилось с
 * `plugin.json` при первом же переименовании, и расхождение было тихим:
 * обновление приходило по одной версии, а плагин называл себя по другой.
 */
private const PLUGIN_ID = 'test';

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
        /*
         * Вид задания и тип вопроса — одно и то же.
         *
         * Раньше плагин отдавал единственный вид «Тест с вопросами», и учителю
         * приходилось заводить тест, а уже внутри выбирать тип каждого
         * вопроса. Теперь каждый тип вопроса — отдельное задание работы:
         * учитель выбирает «Один вариант ответа» и сразу получает подходящее
         * задание, без контейнера и без лишнего вопроса «а сколько их тут».
         *
         * Список берётся из реестра типов, а не пишется руками: добавили
         * класс типа — вид появился сам, и разойтись они не могут.
         */
        $kinds = [];

        foreach ($this->registry()->all() as $type) {
            $kinds[] = new TaskKind(
                $type->key(),
                $type->title(),
                $this->kindDescription($type->key()),
                TaskKind::SCORING_POINTS
            );
        }

        return $kinds;
    }

    /** Вид задания по строке, для чтения чужих ссылок и проверки прав. */
    public function kindFor(string $slug): ?TaskKind
    {
        foreach ($this->taskKinds() as $kind) {
            if ($kind->slug === $slug) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Устаревший контейнер «Тест с вопросами» плагин ещё понимает.
     *
     * В taskKinds() его нет — создавать такие работы больше нельзя, — но
     * работы, созданные раньше, всё ещё ссылаются на него, и ядро должно
     * продолжать их открывать, а не отказывать по незнакомому виду.
     */
    public function knowsKind(string $kind): bool
    {
        return $kind === self::KIND || $this->kindFor($kind) !== null;
    }

    private function kindDescription(string $key): string
    {
        return match ($key) {
            'single_choice'  => 'Ученик выбирает один вариант из предложенных',
            'multiple_choice'=> 'Ученик отмечает несколько подходящих вариантов',
            'ordering'       => 'Ученик расставляет элементы по порядку',
            'numeric'        => 'Ученик вводит число: точное или из диапазона',
            'text'           => 'Ученик пишет ответ; можно передать учителю на проверку',
            'matching'       => 'Ученик соединяет элементы левой и правой части',
            default          => 'Задание с вопросом',
        };
    }

    /**
 * @return list<array{0:string,1:string,2:string,3?:string}>
     */
    public function routes(): array
    {
        /*
         * Самостоятельных тестов больше нет: задание выдаётся только внутри
         * работы, поэтому адресов вида /tests/{id}/start и /tests/{id}/attempt
         * здесь не осталось.
         *
         * Адрес /{id} сохранён и отдаёт постоянное перенаправление на работу,
         * в которой задание лежит. Старые закладки и ссылки продолжают
         * работать, а теста вне работы по этому адресу уже не откроется.
         */
/*
         * Загрузки картинки здесь нет: она не свойство плагина. Каталог
         * загрузок, проверка содержимого и ограничения живут в ядре, и адрес у
         * плагина был бы второй копией того же самого — у вариативной задачи
         * своей копии не оказалось вовсе, и кнопка картинки в её редакторе
         * молча ничего не делала. Адрес один: POST /panel/editor/image.
         */
        return [
            ['GET', '/{id}', 'showAction', Role::TESTS_TAKE],
                /*
           * Адреса редактора задания. Страницы отдельной у плагина больше нет:
           * задание правится только там же, где перечислено, — внутри списка
           * позиций работы. Остался один адрес, POST: он принимает сохранение
           * формы, а вернуть автора можно на любой его собственный адрес.
           *
           * Отдельный GET был нужен ровно для перехода со страницы задания: он
           * и уводил автора из списка после сохранения. Теперь возврата к
           * нему нет, и GET на этом адресе был бы второй точкой входа в
           * редактор, которая ничем не отличается от встроенной.
           */
                ['POST', '/task/{id}', 'taskSaveAction', Role::TESTS_MANAGE],
            ['GET', '/work-item/{assignmentAttemptId}/{itemId}/answers', 'submittedAction', Role::TESTS_MANAGE],
        ];
    }

    /**
     * Свои миграции, один раз при первом включении.
     *
     * Таблицы тестов и вопросов пока создаёт схема ядра, но данные прохождения
     * внутри работы принадлежат плагину, поэтому их таблицы живут здесь.
     */
public function install(): void
    {
        $dir = $this->services->ownPlugin(self::id())->migrationsDir();

        if ($dir === null) {
            throw new RuntimeException('Каталог плагина не найден на диске — миграции применить некуда');
        }

        /*
         * Сервис копии передаётся обязательно: install() вызывается при первом
         * обращении к панели, когда версия плагина разошлась с базой, то есть
         * CREATE TABLE может выполниться в обычном запросе. Без копии такой
         * запрос необратим.
         */
        (new PluginMigrationRunner(
            $this->database(),
            self::id(),
            $dir,
            $this->services->dump()
        ))->apply();
    }

    /**
     * Адрес опубликованной работы, в которой лежит задание.
     *
     * Берётся последняя из опубликованных: если задание добавили в новую
     * работу, вести надо туда — там, где его выдают сейчас.
     */
    private function workUrlFor(int $testId): ?string
    {
        // Таблиц работ может ещё не быть — это не повод ломать старые адреса.
        try {
            $assignmentId = $this->database()->value(
                "SELECT a.id
                   FROM assignments a
                   JOIN assignment_items i ON i.assignment_id = a.id
                  WHERE i.plugin_id = 'test' AND i.task_id = :task
                    AND a.status = 'published'
               ORDER BY a.published_at DESC, a.id DESC
                  LIMIT 1",
                ['task' => $testId]
            );
        } catch (\Throwable) {
            return null;
        }

        return $assignmentId === null ? null : $this->view()->url('work/' . (int) $assignmentId);
    }

    // ---------------------------------------------------------------- маршруты

    /**
     * Старый адрес теста: ведёт в работу, где задание лежит.
     *
     * Отдельной страницы теста больше нет, поэтому адрес либо уводит туда,
     * где задание выдаётся сейчас, либо честно отвечает 404. Раньше здесь был
     * второй случай — тест вне работы, — и он исчез вместе с самими такими
     * тестами.
     */
    public function showAction(Request $request, array $params): Response
    {
        $testId = (int) $params['id'];
        $workUrl = $this->workUrlFor($testId);

        if ($workUrl === null) {
            throw new HttpException(404, 'Тест не найден. Задания выдаются внутри работы.');
        }

        return Response::redirect($workUrl, 301);
    }

    /** Экран правки задания: его единственный вопрос. */
/** Сохранение задания. */
      public function taskSaveAction(Request $request, array $params): Response
      {
          return $this->editor()->taskSave($request, $params);
      }

    public function submittedAction(Request $request, array $params): Response
    {
        return $this->editor()->submitted($request, $params);
    }

    // ---------------------------------------------------------------- контракт

    /**
     * Создание нового задания по запросу редактора работы.
     *
     * Ядро просит создать задание вида и получает номер. Задание — это запись
     * теста с ровно одним вопросом выбранного типа: контейнера «тест» больше
     * нет, учитель добавляет в работу конкретные задания, а не наборы.
     *
     * Задание сразу остаётся черновиком: пустой вопрос ученику показывать
     * нельзя, а заполняет его автор на следующем шаге редактора.
     *
     * @param array<string,mixed> $context
     */
    public function createTask(string $kind, array $context = []): int
    {
        /*
         * Старый вид «Тест с вопросами» больше не предлагается учителю, но
         * работы, созданные до перехода, всё ещё указывают на него, и их нельзя
         * оставить нечитаемыми: такой позиции просто перестал бы находиться
         * плагин, и работа с заданием перестала бы открываться. Поэтому вид
         * принимается и создаётся по-старому — контейнером на несколько
         * вопросов, без автоматического вопроса внутри.
         */
        if ($kind === self::KIND) {
            return $this->editorRepository()->create(
                (int) ($context['author_id'] ?? 0),
                trim((string) ($context['title'] ?? '')),
                isset($context['subject_id']) && is_numeric($context['subject_id'])
                    ? (int) $context['subject_id']
                    : null
            );
        }

        $type = $this->registry()->get($kind);

        $testId = $this->editorRepository()->create(
            (int) ($context['author_id'] ?? 0),
            trim((string) ($context['title'] ?? '')) !== ''
                ? trim((string) $context['title'])
                : $type->title(),
            isset($context['subject_id']) && is_numeric($context['subject_id'])
                ? (int) $context['subject_id']
                : null
        );

        /*
         * Вопрос создаётся сразу, вместе с заданием, а не после первого
         * сохранения редактора. Иначе работа получила бы позицию, которая
         * ничего не спрашивает: проверять нечего, максимум считать не от чего,
         * и такой мусор жил бы в работе до тех пор, пока учитель не зайдёт
         * в каждое задание вручную.
         */
        $this->editorRepository()->addDraftQuestion(
            $testId,
            $kind,
            $type->defaultConfig(),
            $this->emptyOptions($kind)
        );

        return $testId;
    }

    /**
     * Заготовка вариантов под тип вопроса.
     *
     * Пустой список у части типов — не ошибка: их validateConfig потребует
     * варианты при заполнении, и редактор покажет, чего не хватает. Но там,
     * где варианты обязательны сразу, даём одну строку и второй номер: иначе
     * учитель увидит пустую форму и не поймёт, куда вписывать.
     *
     * @return list<array<string,mixed>>
     */
    private function emptyOptions(string $kind): array
    {
        return match ($kind) {
            'single_choice', 'multiple_choice' => [
                ['value_key' => 'a', 'text' => '', 'kind' => 'option'],
                ['value_key' => 'b', 'text' => '', 'kind' => 'option'],
            ],
            'ordering' => [
                ['value_key' => 'a', 'text' => '', 'kind' => 'item'],
                ['value_key' => 'b', 'text' => '', 'kind' => 'item'],
            ],
            'matching' => [
                ['value_key' => 'l1', 'text' => '', 'kind' => 'left'],
                ['value_key' => 'l2', 'text' => '', 'kind' => 'left'],
                ['value_key' => 'r1', 'text' => '', 'kind' => 'right'],
                ['value_key' => 'r2', 'text' => '', 'kind' => 'right'],
            ],
            default => [],
        };
    }

    /**
     * Адрес редактора задания.
     *
     * Это редактор одного вопроса, а не всего теста: задание в работе и есть
     * один вопрос, а список вопросов, свойства теста и шкала оценок автору
     * не нужны — тип выбирается при добавлении, остальное настраивается в
     * мастере.
     *
     * Строится через свой префикс из url_prefix манифеста: так адрес не
     * разойдётся с ядром, если автор переименует раздел.
     */
public function taskEditorUrl(TaskRef $task): ?string
      {
          /*
           * Отдельной страницы редактора у задания нет, поэтому и адреса у него
           * нет. null здесь — не «задание не правится вручную»: правится, и
           * ядро открывает форму по адресу ?edit= внутри списка позиций
           * работы. Здесь нужен адрес, куда можно уйти со страницы плагина, а
           * уйти некуда — поэтому null, а не ссылка на несуществующий адрес.
           */
          return null;
      }

    /**
     * Сырые данные теста из своей базы.
     *
     * Ядру они нужны, чтобы показать название и счётчик вопросов, и чтобы
     * вернуть их плагину обратно при проверке. Больше ядро с ними ничего
     * не делает.
     *
     * @return array<string,mixed>
     */
    public function taskData(TaskRef $task): array
    {
        $id = (int) $task->taskId;

        if (!array_key_exists($id, $this->taskDataCache)) {
            $this->taskDataCache[$id] = $this->testRepository()->findWithData($id);
        }

        $test = $this->taskDataCache[$id];

        if ($test === null) {
            return [];
        }

        return $test;
    }

    /**
     * Данные нескольких заданий одним запросом.
     *
     * Наполняет тот же кэш, что и taskData(), поэтому вызовы по одному после
     * пакетного не идут в базу: метод не заменяет одиночный, а готовит его.
     *
     * @param list<\MyEdu\Plugin\TaskRef> $tasks
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
            foreach ($this->testRepository()->findManyWithData(array_values($missing)) as $id => $row) {
                $this->taskDataCache[(int) $id] = $row;
            }
        }

        $out = [];

        foreach ($tasks as $task) {
            $id = (int) $task->taskId;

            /*
             * Отсутствующее задание — это [], а не исключение: страница должна
             * показать остальные позиции работы. Исключение здесь уронило бы
             * всю работу из-за одного удалённого задания.
             */
            $out[] = $this->taskDataCache[$id] ?? [];
        }

        return $out;
    }

    /**
     * Форма редактора задания для встраивания в редактор работы.
     *
     * Тот же самый контроллер и тот же шаблон формы, что и на отдельной
     * странице: правила типа вопроса должны быть одни и те же в обоих
     * местах, иначе откроется вторая, более старая версия формы.
     */
    public function renderEditorFragment(EditorContext $context): string
    {
        if ($context->task === null || $context->task->taskId <= 0) {
            throw new RuntimeException('Форма редактора строится для созданного задания.');
        }

        return $this->editor()->taskFragment(
            $context->task->taskId,
            $context->backUrl,
            $context->assignmentPublished,
            $context->closeUrl
        );
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

    /**
     * Короткий текст ответа — для подсказок в таблицах журнала.
     */
    public function workAnswerSummary(string $snapshot): ?string
    {
        return $this->workHandler()->workAnswerSummary($snapshot);
    }

public function canManageTask(TaskRef $task, int $userId): bool
      {
          $test = $this->testRepository()->find($task->taskId);

          return $test !== null && (int) $test['author_id'] === $userId;
      }

      /**
       * Задание можно выпускать ученикам, если на его вопрос есть что ответить.
       *
       * Раньше ту же проверку выполняла публикация отдельного задания. Отдельной
       * публикации больше нет — задание выпускается вместе с работой, — и без
       * этого метода работа опубликовалась бы с пустым вопросом, на который
       * нельзя ответить.
       */
      public function publicationBlocker(TaskRef $task): ?string
      {
          $testId = (int) $task->taskId;

          if ($testId <= 0 || $this->testRepository()->find($testId) === null) {
              return 'Задание не найдено';
          }

          return $this->editorRepository()->unfinishedTaskReason($testId);
      }

    public function taskPreview(TaskRef $task): string
    {
        $id = (int) $task->taskId;

        if (isset($this->taskPreviewCache[$id])) {
            return $this->taskPreviewCache[$id];
        }

        $questions = $this->testRepository()->questions($id);

        if ($questions === []) {
            return $this->taskPreviewCache[$id] = '';
        }

        /*
         * Вопрос приходит объектом, а не строкой базы: условие лежит в
         * `prompt`, и обращение как к массиву уронило бы весь плагин — вместе с
         * редактором задания, списком работы и выдачей.
         */
        return $this->taskPreviewCache[$id] = TextFragment::from($questions[0]->prompt);
    }


    /**
     * Класс удалён: тесты этого класса остались жить уже ни в чьём классе.
     *
     * Привязка обнуляется самим внешним ключом (`ON DELETE SET NULL`), и
     * поэтому ничего чинить не нужно — но плагин об этом узнаёт только здесь.
     * Без уведомления он молчал бы про данные, которые остались сиротами: тест
     * есть, ученик есть, класса нет, и почему так вышло — неизвестно.
     *
     * Удалять тесты здесь нельзя: они могут стоять в работах, выданных другим
     * классам, и класс — не единственный, кто на них ссылается.
     *
     * @param array{classId:int, className:string, studentIds:list<int>} $removal
     *
     * @return list<string>
     */
    public function onClassRemoved(array $removal): array
    {
        $orphaned = (int) $this->database()->value(
            'SELECT COUNT(*) FROM tests WHERE class_id IS NULL',
            []
        );

        if ($orphaned === 0) {
            return [];
        }

        return [
            'Тесты класса «' . $removal['className'] . '» остались в списке без класса ('
            . $orphaned . ' шт.) — привяжите их к новому классу или удалите',
        ];
    }

    /**
     * Удаление теста.
     *
     * Сначала проверяется, не осталось ли задание в другой работе: работы
     * строятся из уникального набора заданий, но полагаться на это в коде
     * удаления нельзя — при спорах одного задания чужая работа получила бы
     * задание, которого больше нет, вместе с учеником посреди работы.
     */
    public function deleteTask(TaskRef $task): bool
    {
        $testId = $task->taskId;

        $references = $this->database()->value(
            'SELECT COUNT(*) FROM assignment_items WHERE plugin_id = :plugin AND task_id = :task',
            ['plugin' => self::id(), 'task' => $testId]
        );

        if ((int) $references > 0) {
            return false;
        }

        // Всё удаление — одной транзакцией: если удаление теста не пройдёт
        // по внешнему ключу, прохождения тоже должны остаться.
        //
        // Прохождения хранят номер теста числом, а не внешним ключом: без их
        // удаления следующий тест, получивший тот же номер, показал бы ученику
        // чужие ответы. Ответы удаляются каскадом из test_item_attempts.
        return $this->database()->transaction(function () use ($testId): bool {
            $this->workItemsRepository()->deleteForTask($testId);
            $this->testRepository()->delete($testId);

            return true;
        });
    }

    /**
     * Позиция работы снята — убираем прохождение по ней.
     *
     * Таблицы плагина связаны с ядром не внешними ключами, а номерами: строки
     * попыток по удалённой позиции остались бы в ба навсегда, и при
     * повторном использовании того же номера позиции ученик увидел бы старые
     * ответы.
     */
    /**
     * Попытка работы удалена: убрать свои прохождения.
     *
     * Таблица прохождений не ссылается на попытку ядра внешним ключом, и без
     * этой уборки каждое тестовое прохождение оставляло бы здесь мёртвые
     * строки. Ответы уходят каскадом по внешнему ключу на прохождение.
     */
    public function deleteAttemptData(int $assignmentAttemptId): void
    {
        $this->workItemsRepository()->deleteForAttempt($assignmentAttemptId);
    }

    public function deleteItem(TaskRef $task, int $itemId): void
    {
        $this->workItemsRepository()->deleteForItem($itemId);
    }

    public function testRepository(): TestRepository
    {
        return new TestRepository($this->database());
    }

    public function editorRepository(): TestEditorRepository
    {
        return $this->editorRepository ??= new TestEditorRepository(
            $this->database(),
            $this->registry()
        );
    }

    public function workItemsRepository(): WorkItemRepository
    {
        return $this->workItems ??= new WorkItemRepository($this->database());
    }

    public function registry(): QuestionTypeRegistry
    {
        return QuestionTypeRegistry::instance();
    }

    public function grader(): AttemptGrader
    {
        return new AttemptGrader(
            $this->registry(),
            (int) $this->config()->int('attempt.max_text_length', 4000)
        );
    }

    public function calculator(array $criteria): GradeCalculator
    {
        return GradeCalculator::create($this->config(), $criteria);
    }

    public function view(): View
    {
        return $this->services->view();
    }

    /**
     * Префикс адресов — из манифеста, чтобы ядро и плагин строили ссылки
     * одинаково, даже если автор переименует раздел.
     */
    private function urlPrefix(): string
    {
        return $this->services->ownPlugin(self::id())->url();
    }

    private function editor(): TestEditorController
    {
        return $this->editorController ??= new TestEditorController(
            $this->services->database(),
            $this->view(),
            $this->services->session(),
            $this->services->csrf(),
            $this->services->auth(),
            $this->config(),
            $this->registry(),
            $this->urlPrefix()
        );
    }

    /**
     * Прохождение задания внутри работы.
     *
     * Отдельный объект, а не редактор и не проверка: прохождение принадлежит
     * позиции работы и её попытке, а не тесту. Общего у них с правилом
     * проверки только одно — как считается балл.
     */
    private function workHandler(): WorkItemHandler
    {
        return $this->workHandler ??= new WorkItemHandler(
            $this->view(),
            $this->config(),
            $this->registry(),
            $this->testRepository(),
            $this->workItemsRepository(),
            $this->grader()
        );
    }

    private function database(): Database
    {
        return $this->services->database();
    }

    /**
     * Перенос задания вместе с вопросами.
     *
     * Выгружаются строки таблиц как есть, а не через редактор: редактор
     * принимает форму вопроса и пересобирает её под тип, а перенос должен
     * принести то же самое, что было на старом месте, — включая веса, порядок и
     * разбор. Сборка через форму рано или поздно потеряла бы часть полей, и
     * потеря выглядела бы как «перенесли, но не совсем».
     *
     * Номера не выгружаются: на новом месте они другие, и связь вопроса с
     * вариантами ответа пересобирается при вносе по порядку.
     */
    public function exportTask(TaskRef $task): ?array
    {
        $db = $this->database();
        $test = $db->one('SELECT * FROM tests WHERE id = :id', ['id' => $task->taskId]);

        if ($test === null) {
            return null;
        }

        $questions = [];

        foreach ($db->all(
            'SELECT * FROM questions WHERE test_id = :t ORDER BY position, id',
            ['t' => $task->taskId]
        ) as $question) {
            $options = [];

            foreach ($db->all(
                'SELECT kind, value_key, text, position, pair_key, always_show FROM question_options WHERE question_id = :q ORDER BY position, id',
                ['q' => (int) $question['id']]
            ) as $option) {
                $options[] = [
                    'kind'      => (string) $option['kind'],
                    'value_key' => (string) $option['value_key'],
                    'text'      => (string) $option['text'],
                    'position'  => (int) $option['position'],
                    'pair_key'  => $option['pair_key'] === null ? null : (string) $option['pair_key'],
                ];
            }

            $questions[] = [
                'type'        => (string) $question['type'],
                'prompt'      => (string) $question['prompt'],
                'position'    => (int) $question['position'],
                'weight'      => (float) $question['weight'],
                'is_required' => (int) $question['is_required'],
                'explanation' => (string) ($question['explanation'] ?? ''),
                'config'      => (string) ($question['config'] ?? ''),
                'options'     => $options,
            ];
        }

        return [
            'test' => [
                'title'             => (string) $test['title'],
                'description'       => (string) ($test['description'] ?? ''),
                'time_limit_min'    => (int) $test['time_limit_min'],
                'shuffle_questions' => (int) $test['shuffle_questions'],
                'shuffle_options'   => (int) $test['shuffle_options'],
                'max_attempts'      => (int) $test['max_attempts'],
                'show_results'      => (int) $test['show_results'],
                'status'            => (string) $test['status'],
            ],
            'questions' => $questions,
        ];
    }

    public function importTask(array $data, string $kind, int $authorId, ?int $subjectId = null): int
    {
        $test = is_array($data['test'] ?? null) ? $data['test'] : [];
        $db = $this->database();

        /*
         * Пакет проверяется до первой записи.
         *
         * Раньше `type` и `config` брались из файла как есть. Чужой пакет мог
         * принести вопрос с типом, которого нет в реестре, — и такой тест
         * проходил импорт, а падал при первой же попытке ученика, то есть на
         * живой работе, уже после того, как учитель увидел «импорт завершён».
         * `config` приходил строкой: мусор в нём всплывал при разборе ответа,
         * тоже в момент попытки.
         *
         * Ошибка бросается с текстом: ядро ловит исключение плагина, откатывает
         * вставку и пишет позицию в отчёт как «плагин не смог создать задание» —
         * то есть импорт одного плохого задания не отменяет остальные.
         */
        $questions = [];

        foreach ($data['questions'] ?? [] as $index => $question) {
            if (!is_array($question)) {
                throw new RuntimeException('вопрос #' . ((int) $index + 1) . ' в пакете не является объектом');
            }

            $questions[] = $this->validateImportedQuestion($question, (int) $index + 1);
        }

        return $db->transaction(function (Database $db) use ($test, $questions, $authorId, $subjectId): int {
            $testId = (int) $db->insert('tests', [
                'subject_id'        => $subjectId,
                'author_id'         => $authorId,
                // Классы на новом месте свои: привязка задания к классу —
                // это его домашний адрес, и переносить её некуда.
                'class_id'          => null,
                'title'             => mb_substr((string) ($test['title'] ?? 'Задание'), 0, 255),
                'description'       => (string) ($test['description'] ?? ''),
                'time_limit_min'    => (int) ($test['time_limit_min'] ?? 0),
                'shuffle_questions' => (int) ($test['shuffle_questions'] ?? 0),
                'shuffle_options'   => (int) ($test['shuffle_options'] ?? 1),
                'max_attempts'      => (int) ($test['max_attempts'] ?? 0),
                'show_results'      => (int) ($test['show_results'] ?? 1),
                'status'            => (string) ($test['status'] ?? 'draft'),
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            foreach ($questions as $question) {
                $config = $question['config'];
                $options = $question['options'];

                $questionId = (int) $db->insert('questions', [
                    'test_id'     => $testId,
                    'type'        => $question['type'],
                    'prompt'      => $question['prompt'],
                    'position'    => $question['position'],
                    'weight'      => $question['weight'],
                    'is_required' => $question['is_required'],
                    'explanation' => $question['explanation'],
                    'config'      => $config,
                ]);

                foreach ($options as $option) {
                    $db->insert('question_options', [
                        'question_id' => $questionId,
                        'kind'        => $option['kind'],
                        'value_key'   => $option['value_key'],
                        'text'        => $option['text'],
                        'position'    => $option['position'],
                        'pair_key'    => $option['pair_key'],
                    ]);
                }
            }

            return $testId;
        });
    }

    /**
     * Вопрос из чужого пакета приводится к тому, чему готовы остальные части.
     *
     * Проверяется не только имя типа: правильный ответ лежит в `config`, и без
     * него вопрос не проверяем — ученик ответит верно, а балл не начислится.
     * Считать это рабочим заданием нельзя, и `validateConfig()` типа об этом
     * говорит прямо.
     *
     * @param  array<string,mixed> $question
     * @return array<string,mixed>
     */
    private function validateImportedQuestion(array $question, int $number): array
    {
        $type = mb_substr((string) ($question['type'] ?? ''), 0, 32);
        $registry = $this->registry();

        if ($type === '' || !$registry->has($type)) {
            throw new RuntimeException(sprintf(
                'вопрос #%d: типа «%s» нет, известны: %s',
                $number,
                $type === '' ? 'пусто' : $type,
                implode(', ', $registry->keys())
            ));
        }

        $rawConfig = $question['config'] ?? [];

        if (is_string($rawConfig)) {
            $decoded = json_decode($rawConfig, true);
            $rawConfig = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($rawConfig)) {
            throw new RuntimeException(sprintf(
                'вопрос #%d: настройки типа «%s» не читаются — это должен быть JSON',
                $number,
                $type
            ));
        }

        $options = [];

        foreach ($question['options'] ?? [] as $position => $option) {
            if (!is_array($option)) {
                throw new RuntimeException(sprintf('вопрос #%d: вариант #%d не является объектом', $number, (int) $position + 1));
            }

            $options[] = [
                'kind'      => mb_substr((string) ($option['kind'] ?? ''), 0, 32),
                'value_key' => mb_substr((string) ($option['value_key'] ?? ''), 0, 191),
                'text'      => (string) ($option['text'] ?? ''),
                'position'  => (int) ($option['position'] ?? $position),
                'pair_key'  => $option['pair_key'] ?? null,
            ];
        }

        try {
            $registry->get($type)->validateConfig($rawConfig, $options);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('вопрос #%d: %s', $number, $e->getMessage()), 0, $e);
        }

        return [
            'type'        => $type,
            'prompt'      => (string) ($question['prompt'] ?? ''),
            'position'    => (int) ($question['position'] ?? $number - 1),
            'weight'      => (float) ($question['weight'] ?? 1),
            'is_required' => (int) ($question['is_required'] ?? 1),
            'explanation' => (string) ($question['explanation'] ?? ''),
            'config'      => (string) json_encode($rawConfig, JSON_UNESCAPED_UNICODE),
            'options'     => $options,
        ];
    }

    private function config(): Config
    {
        return $this->services->config();
    }
}
