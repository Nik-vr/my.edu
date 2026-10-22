<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Http;

use MyEduPlugin\Test\Question\AnswerText;
use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\Html;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\TextFragment;
use MyEdu\Core\View;
use MyEduPlugin\Test\Question\InvalidAnswerException;
use MyEduPlugin\Test\Question\Question;
use MyEduPlugin\Test\Question\QuestionTypeRegistry;
use MyEduPlugin\Test\Question\Types\NumericType;
use MyEduPlugin\Test\Question\Types\OrderingType;
use MyEduPlugin\Test\Question\Types\TextAnswerType;
use MyEduPlugin\Test\Repository\TestEditorRepository;
use MyEduPlugin\Test\Repository\TestRepository;
use MyEduPlugin\Test\Repository\WorkItemRepository;

/**
 * Редактор задания: обычные формы, без JSON и без кода в руках автора.
 *
 * Решение владельца проекта: учитель не должен ни редактировать JSON, ни
 * вставлять что-либо в код. Поэтому форма здесь описана руками для каждого из
 * шести типов вопросов, а разбор формы в данные задания вынесен в методы,
 * названные по типам.
 *
 * Экран открыт по адресу плагина, а не ядра: /tests/task/{id}. Так адрес
 * строится из url_prefix манифеста и не разойдётся с ядром после смены
 * префикса.
 *
 * Редактора теста целиком здесь нет и не планируется. Задание в работе — это
 * один вопрос одного типа, а список вопросов, свойства теста и шкала оценок
 * показывать было негде: тип выбирается при добавлении задания, остальное
 * настраивается в мастере работы.
 */
final class TestEditorController
{
    /**
     * Виды вопросов, у которых есть варианты ответа.
     *
     * Перемешивать нечего там, где вариантов нет: у текстового ответа и у
     * числа. Переставлять элементы упорядочивания — другое дело, это не
     * варианты, и галочка про них была бы обещанием не про то.
     */
    private const TYPES_WITH_OPTIONS = ['single_choice', 'multiple_choice', 'matching'];

 /** Сколько пустых строк показывать в формах элементов. */


private TestRepository $tests;

 private TestEditorRepository $editor;

 private WorkItemRepository $workItems;

public function __construct(
    /*
     * База приходит сразу, а не контейнером.
     *
     * Внутри от контейнера нужна ровно одна вещь, и она извлекалась тут же. Оставлять
     * ради неё контейнер в руках у контроллера плагина — значило держать в нём всё
     * остальное заодно, а список сервисов плагина для этого уже не годится: он
     * перечисляет, что плагину положено, и контейнер там быть не должно.
     */
    private readonly Database $db,
    private readonly View $view,
    private readonly Session $session,
    private readonly Csrf $csrf,
    private readonly Auth $auth,
    private readonly Config $config,
    private readonly QuestionTypeRegistry $registry,
    private readonly string $urlPrefix
    ) {
    $this->tests = new TestRepository($this->db);
    $this->editor = new TestEditorRepository($this->db, $registry);
    $this->workItems = new WorkItemRepository($this->db);
    }

 public function submitted(Request $request, array $params): Response
 {
 $itemAttempt = $this->workItems->find(
 (int) ($params['assignmentAttemptId'] ?? 0),
 (int) ($params['itemId'] ?? 0)
 );

 if ($itemAttempt === null) {
 throw new HttpException(404, 'Прохождение не найдено');
 }

 /*
 * Проверяется авторство теста, а не прохождения: ответы показываются
 * автору материала. Без этой проверки страница отдавала чужие ответы
 * любому, кто угадал номера попытки и задания, — номера идут подряд и
 * угадываются перебором, а ответы ученика там видны целиком.
 */
 $test = $this->tests->find((int) $itemAttempt['test_id']);

 if ($test === null) {
 throw new HttpException(404, 'Тест не найден');
 }

 $this->requireAuthor($test);

 $answers = $this->workItems->submittedAnswers((int) $itemAttempt['id']);
 $questions = $this->questionsById((int) $itemAttempt['test_id']);

 $rows = [];

 foreach ($answers as $answer) {
 $questionId = (int) $answer['question_id'];
 $question = $questions[$questionId] ?? null;
 $type = $question === null ? null : $this->registry->get($question->type);

 $rows[] = [
 'prompt' => $question?->prompt ?? ('Вопрос #' . $questionId),
 'type' => $type?->title() ?? '',
 'answer' => AnswerText::format($type, $question, $answer),
 'checked' => $answer['is_correct'] !== null,
 'correct' => $answer['is_correct'] !== null && (int) $answer['is_correct'] === 1,
 ];
 }

 // Страница целиком: тот же фрагмент оборачивается оболочкой здесь, потому
// что плагин отдаёт разбор без неё — он же встраивается в страницу проверки,
// где оболочка уже есть.
$fragment = $this->view->render('test/work_submitted', [
 'title' => 'Ответ ученика',
 'rows' => $rows,
 ], '');

 return Response::html(
 $this->view->wrap($fragment, ['title' => 'Ответ ученика']),
 $rows === [] ? 404 : 200
 );
 }

/**
 * Редактор задания: один вопрос одного типа.
 *
 * Отдельной страницы у задания нет. Метод убран вместе с адресом: форма
 * рисуется только внутри списка позиций работы, и второй точки входа,
 * от которой автор после сохранения уходил на страницу задания, больше
 * нет. Сохранение принимает POST, а возвращает на адрес, который пришёл в
 * форме полем back.
 */
 public function taskFragment(
 int $testId,
 string $backUrl,
 bool $assignmentPublished = false,
 string $closeUrl = ''
 ): string {
 $test = $this->tests->find($testId);

 if ($test === null) {
 throw new HttpException(404, 'Задание не найдено');
 }

 $this->requireAuthor($test);

 $questions = $this->tests->questions($testId);
 $first = $questions[0] ?? null;
 $form = $first === null
                ? $this->formFor(null, $test)
                : $this->byId($questions, (int) $first->id, $test);

 return $this->view->render('test/_task_form', [
 'task' => $test,
 'form' => $form,
 'prefix' => $this->urlPrefix,
 // Возврат ведёт в редактор работы: форма отправляется на свой адрес
 // плагина, и без этого адреса автор после сохранения оказался бы на
 // странице, которой он не открывал.
 'backUrl' => $backUrl,
 /*
 * Закрыть редактор — вернуться к списку заданий без открытого
 * задания. Раньше «Отмена» вела на backUrl, где задание открыто
 * снова, и выглядела кнопкой, которая ничего не делает.
 */
 'closeUrl' => $closeUrl !== '' ? $closeUrl : $backUrl,

 /*
 * Опубликована ли работа, а не задание. Плагин видит свой статус
 * (`in_progress`/`graded` у вопросов, `draft`/`published` у
 * вариативной задачи) и по нему о публикации работы сказать не может.
 */
 'assignmentPublished' => $assignmentPublished,
 ],
 ''
 );
 }

 /** Сохранение задания: правится его единственный вопрос. */
 public function taskSave(Request $request, array $params): Response
 {
 $this->csrf->validate($request->postParam('_token'));

 $testId = (int) $params['id'];
 $test = $this->tests->find($testId);

 if ($test === null) {
 throw new HttpException(404, 'Задание не найдено');
 }

 $this->requireAuthor($test);

 $questionId = (int) $request->postParam('question_id');

 /*
 * Тип берём из сохранённого вопроса, а не из формы. У задания он один, и
 * подставить чужой тип через POST значило бы сохранить варианты не того
 * вида, который показывала форма правки.
 */
 $existing = null;

 foreach ($this->tests->questions($testId) as $candidate) {
 if ($candidate->id === $questionId) {
 $existing = $candidate;
 break;
 }
 }

 if ($existing === null) {
 throw new HttpException(404, 'Вопрос задания не найден');
 }

 $type = $existing->type;

$prompt = Html::clean((string) $request->postParam('prompt', ''));
 $weight = $this->number($request->postParam('weight')) ?? $existing->weight;
 /*
 * Галочка «Ответ обязателен» отключена — значит это.
 *
 * Снятая галочка браузер не присылает вовсе, и `postParam()` отдаёт `null` —
* ровно как если бы поля не было. Раньше отсутствие поля читалось как
 * «включено», и любой сохранённый вопрос без отметки получал её обратно: автор
 * снимал галочку, сохранял, а задание снова становилось обязательным. Проверка
 * идёт по самому наличию ключа, а не по его значению.
 */
$required = $this->checkedFlag($request, 'is_required');
 $explanation = trim((string) $request->postParam('explanation', ''));

 /*
 * Ошибка разбора вопроса — это ошибка формы, а не сбой сервера. Автор
 * забыл отметить правильный вариант или не заполнил ключевые слова: без
 * перехвата он увидел бы 500 и потерял правку. Сообщение уходит во
 * flash, как и в прежнем редакторе, и форма открывается заново.
 */
try {
  [$config, $options] = $this->readQuestion($type, $request);
  } catch (InvalidAnswerException $e) {
  return $this->respond($request, false, $e->getMessage(), false, '', $e->questionKey);
  }

 /*
 * Сохранение тоже может не пройти: вопрос успели удалить, база отвечает
 * отказом. Это тоже ошибка формы, а не падение — сообщение возвращает
 * автора к правке вместо белой страницы.
 */
try {
            $this->editor->updateQuestion(
 $testId,
 $questionId,
 $type,
 $prompt,
 $weight,
 $required,
 $explanation === '' ? null : $explanation,
 $config,
 $options
 );
  } catch (\Throwable $e) {
  return $this->respond($request, false, 'Не удалось сохранить: ' . $e->getMessage(), false);
 }

  /*
  * Перемешивание вариантов — настройка всего задания, а не вопроса.
  *
  * Сохраняется вместе с вопросом, потому что форма одна: отдельная кнопка
  * «сохранить настройки» заставила бы человека нажимать её после каждой
  * правки вопроса, и она бы забывалась.
  *
  * Галочка снята — значит выключено: снятая галочка браузером не присылается
  * вовсе, и отсутствие поля читалось бы как «включено».
  */
  if (in_array($type, self::TYPES_WITH_OPTIONS, true)) {
  try {
  $this->editor->updateTest($testId, [
  'shuffle_options' => $this->checkedFlag($request, 'shuffle_options') ? 1 : 0,
  ]);
  } catch (\Throwable $e) {
  return $this->respond($request, false, 'Не удалось сохранить настройки: ' . $e->getMessage(), false);
  }
 }

 /*
 * Заголовок возвращается вместе с ответом: строка в списке заданий показывает
 * старое название, пока страница не перезагрузится, и без этого правка
 * выглядела бы несохранившейся.
 */
 return $this->respond($request, true, 'Задание сохранено', true, TextFragment::from($prompt));
 }

 /*
 * Возврат туда, откуда форма пришла.
 *
 * Адрес лежит в поле back: он приходит из редактора работы, потому что
 * форма отправляется на свой адрес плагина, и без этого адреса автор после
 * сохранения оказался бы на странице, которой он не открывал.
 *
 * Параметр closeEditor решает, что делать с открытым заданием:
 *
 * - после сохранения редактор закрывается. Возвращаться на открытое
 * задание незачем: правка сделана, а список позиций работы с её весами —
 * то, что автор видит дальше. Раньше здесь был редирект на отдельную
 * страницу задания, и после «Сохранить» автор оказывался в другом
 * месте, откуда ещё надо было уйти обратно.
 * - при отказе редактор остаётся открытым. Форма собрана человеком, и
 * закрывать её отказом — значит заставлять начинать заново.
 */
 private function redirectBack(Request $request, bool $closeEditor): Response
 {
 $back = trim((string) $request->postParam('back', ''));

 if ($back === '') {
 return Response::redirect($this->view->url('panel/assignments'));
 }

 /*
 * Адрес приводится к корню сайта обязательно.
 *
 * Response::redirect() кладёт в Location строку как есть, и поле back
 * приходит из формы без ведущего слэша: panel/assignments/14/edit/items.
 * Браузер разрешает такой адрес относительно того, куда ушёл POST, —
 * то есть относительно /tests/task/12, — и получал
 * /tests/task/panel/assignments/14/edit/items: 404 после успешного
 * сохранения. Правка сохранялась, но человек уходил на ошибку и
 * возвращался, уже не доверяя результату.
 *
 * Адрес может прийти и уже абсолютным — это не мешает: url() оставляет
 * такие строки как есть.
 */
 return Response::redirect(
 $this->view->url($closeEditor ? self::withoutEditParam($back) : $back)
 );
 }

 /**
 * Ответ на сохранение: данными или страницей.
 *
 * Скрипту, который отправляет форму без перезагрузки, нужен ответ с
 * сообщением и адресом — перезагружать страницу он не собирается, а
 * разбирать HTML-редирект ему незачем. Обычной отправке формы, наоборот,
 * нужна страница: без JavaScript это единственный путь.
 *
* @param string $message что сказать автору; показывается в обоих случаях
  * @param string $preview начало условия для подписи карточки на доске
  * @param string $field имя поля, в котором автор ошибся; пусто, если поле
  *                      указать нечем и отмечать нечего
  */
 private function respond(
        Request $request,
        bool $ok,
        string $message,
        bool $closeEditor,
        string $preview = '',
        string $field = ''
    ): Response {
        if ($request->wantsJson()) {
            $back = trim((string) $request->postParam('back', ''));

            /*
             * Назад уходит фрагмент условия, а не заголовок задания: заголовка
             * больше нет, а карточка в списке работы подписана началом текста.
             * Без этого после сохранения на доске оставался бы прежний обрывок
             * и правка выглядела бы несохранившейся до ручного обновления.
             */
            return Response::json([
                'ok' => $ok,
                'message' => $message,
                'close' => $closeEditor,
                'preview' => $preview,
                'field' => $field,
                'url' => $back === ''
                    ? ''
                    : $this->view->url($closeEditor ? self::withoutEditParam($back) : $back),
            ], $ok ? 200 : 422);
        }

 $this->session->set($ok ? 'flash_notice' : 'flash_error', $message);

 return $this->redirectBack($request, $closeEditor);
 }

 /**
 * Убрать из адреса параметр edit — то самое открытое задание.
 *
 * Адрес вида panel/assignments/7/edit/items?edit=42 разбирается по частям,
 * а не регулярным выражением по строке: иначе значение параметра с
 * «редактировать» внутри текста вырезалось бы вместе с ним.
 */
 private static function withoutEditParam(string $url): string
 {
 $parts = explode('?', $url, 2);

 if (!isset($parts[1]) || $parts[1] === '') {
 return $parts[0];
 }

 parse_str($parts[1], $query);
 unset($query['edit']);

 return $query === [] ? $parts[0] : $parts[0] . '?' . http_build_query($query);
 }

// Загрузки картинки здесь больше нет: адрес один на всех, POST /panel/editor/image.
// Каталог загрузок, проверка содержимого и ограничения — дело ядра, и своя
// копия в каждом плагине разошлась бы с ним при первой правке. Именно так
// и вышло: у вариативной задачи своего адреса не было, и кнопка картинки в
// её редакторе молча ничего не делала.

 /**
 * Данные задания из формы по типу.
 *
 * Каждый тип разбирается своим методом: правильные ответы лежат в разных
 * полях (одиночный выбор — это значение, порядок — это список, соответствие
 * — это пары), и общий разбор всё равно выродился бы в проверку типа.
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readQuestion(string $type, Request $request): array
 {
 return match ($type) {
 'single_choice' => $this->readSingleChoice($request),
 'multiple_choice' => $this->readMultipleChoice($request),
 'ordering' => $this->readOrdering($request),
 'numeric' => $this->readNumeric($request),
 'text' => $this->readText($request),
 'matching' => $this->readMatching($request),
 default => throw new InvalidAnswerException('Неизвестный тип задания: ' . $type),
 };
 }

 /**
 * Одиночный выбор: правильный вариант отмечается из списка.
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
  private function readSingleChoice(Request $request): array
  {
    $keyMap = [];
    $options = $this->readOptions($request, $keyMap);

    /*
     * Отметка приходит номером строки формы и превращается в ключ варианта.
     *
     * Поле зовётся correct_single, а не correct: обе формы выбора стоят в
     * одной странице, и при общем имени PHP складывал их в одно место —
     * `correct=1` от кружка перекрывался `correct[]=1` от галочки того же
     * вопроса, значение становилось массивом, и одиночный выбор читал его
     * как «отметки нет». Проявлялось это только у сохранённого вопроса: у
     * нового в чужом блоке отметки ещё нет, и он проходил.
     */
    $row = (string) $request->postParam('correct_single', '');
    $correct = $this->resolveCorrect($row, $keyMap, count($options));

    // Пустой правильный ответ не считаем: тест без верного варианта
    // невозможно пройти правильно, и это должен заметить автор.
    if ($correct === '') {
        throw new InvalidAnswerException('Отметьте правильный вариант ответа', 'correct_single');
    }

    $config = ['correct' => $correct];
    $this->readRandomOptions($request, $config, $options, $keyMap);

    return [$config, $options];
    }

 /**
 * Ключ варианта по тому, что прислала форма.
 *
 * Форма шлёт номер строки, но принимается и сам ключ: так сохранённая
 * страница, открытая из истории браузера и отправленная после обновления
 * плагина, всё ещё работает. Неизвестное значение отбрасывается — иначе
 * отметка указывала бы на вариант, которого в вопросе нет.
 *
 * @param array<string,string> $keyMap номер строки → ключ варианта
 * @param int $optionCount сколько вариантов собрано
 */
 private function resolveCorrect(string $raw, array $keyMap, int $optionCount): string
 {
 if (isset($keyMap[$raw])) {
 return $keyMap[$raw];
 }

 if ($raw !== '' && preg_match('/^[a-z]$/', $raw) === 1) {
 $position = ord($raw) - ord('a');

 if ($position < $optionCount) {
 return $raw;
 }
 }

 return '';
 }

 /**
 * Множественный выбор: правильных вариантов может быть сколько угодно.
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readMultipleChoice(Request $request): array
 {
 $keyMap = [];
 $options = $this->readOptions($request, $keyMap);

 // Отметки приходят номерами строк формы и превращаются в ключи вариантов.
 //
 // Поле своё — correct_multi, а не общий correct: иначе отметка
 // одиночного выбора, отмеченная в скрытом блоке той же страницы,
 // перетирала бы значения этого списка.
 $correct = [];

 foreach ($this->codeList($request->postArray('correct_multi')) as $row) {
 $key = $this->resolveCorrect($row, $keyMap, count($options));

 if ($key !== '') {
 $correct[] = $key;
 }
 }

 if ($correct === []) {
 throw new InvalidAnswerException('Отметьте хотя бы один правильный вариант', 'correct_multi');
 }

  $partial = $this->checkedFlag($request, 'partial');
  $penalty = $this->number($request->postParam('miss_penalty'));

  $config = [
    'correct' => $correct,
    'partial' => $partial,
    'miss_penalty' => $penalty !== null && $penalty >= 0.0 ? min($penalty, 1.0) : 0.5,
  ];
  $this->readRandomOptions($request, $config, $options, $keyMap);

  return [$config, $options];
  }

    /**
     * Сколько вариантов показывать ученику и какие показывать всегда.
     *
     * Число приходит из настроек вопроса, а отметки «обязательно показывать» —
     * из строк вариантов. Если отмечено больше, чем показывать, автору
     * говорят: либо показывать больше, либо снять лишние отметки. Иначе часть
     * отметок молча пропала бы, и автор не узнал бы, что его правило не
     * сработало.
     *
     * Номер строки формы и номер варианта — не одно и то же: пустые строки
     * пропускаются, и варианты считаются заново. Поэтому отметка переводится
     * через ключ варианта, а не берётся по номеру строки.
     *
     * Список вариантов принимается по ссылке: отметки «обязательно показывать»
     * принадлежат варианту, и без ссылки они остались бы в копии — вопрос
     * сохранился бы без них, а человек видел бы отмеченные флажки, которые не
     * сохранились.
     *
     * @param array<string,mixed>       $config  настройки вопроса
     * @param list<array<string,mixed>> $options варианты вопроса
     * @param array<string,string>      $keyMap  номер строки формы → ключ варианта
     */
    private function readRandomOptions(Request $request, array &$config, array &$options, array $keyMap): void
    {
    $always = 0;

    /*
     * Отметка приходит значением флажка, а значением у него — номер строки
     * формы. Нужен именно он: позиция отметки в списке (первая, вторая) и
     * номер строки — разные вещи, и если отмечены, скажем, варианты 2 и 4,
     * то по позиции отметки читался бы вариант 1.
     */
    foreach ($request->postArray('option_always_show') as $value) {
        if (!is_scalar($value) || (string) $value === '') {
            continue;
        }

        $key = $keyMap[(string) $value] ?? null;

        if ($key === null) {
            continue;
        }

        foreach ($options as $position => $option) {
            if ((string) ($option['value_key'] ?? '') === $key) {
                $options[$position]['always_show'] = 1;
                $always++;
            }
        }
    }

    $limit = (int) ($request->postParam('random_options') ?? 0);

    if ($limit < 0) {
        $limit = 0;
    }

    if ($limit > 0 && $always > $limit) {
        throw new InvalidAnswerException(
            'Отмечено ' . $always . ' вариантов как обязательные, а показывать можно '
            . $limit . '. Увеличьте число или снимите лишние отметки «Обязательно показывать».',
            'random_options'
        );
    }

    /*
     * Ноль — это «показывать все», и в настройках его незачем хранить.
     *
     * Заодно снимается старое значение: автор поставил три, потом вернул
     * «показывать все», и без этого в вопросе осталось бы три.
     */
    if ($limit > 0) {
        $config['random_options'] = $limit;
    } else {
        unset($config['random_options']);
    }
    }

 /**
 * Порядок: автор задаёт верную последовательность.
 *
 * Форма присылает элементы и их места парами: «текст элемента» и «какое он
 * занимает место». Порядок в поле не должен зависеть от того, в каком
 * порядке браузер отправил массивы, поэтому места читаются по индексу строки,
 * а собираются в последовательность сортировкой по номеру места.
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readOrdering(Request $request): array
 {
 $texts = $request->postArray('option_text');
 $positions = $request->postArray('order_pos');

 $options = [];
 $placed = [];

 foreach ($texts as $index => $raw) {
 if (!is_scalar($raw)) {
 continue;
 }

 $text = trim((string) $raw);

 if ($text === '') {
 continue;
 }

 $key = 'i' . ($index + 1);

 $options[] = [
 'kind' => Question::KIND_ITEM,
 'value_key' => $key,
 'text' => $text,
 ];

 $place = $positions[$index] ?? null;
 $position = $place === null || !is_scalar($place) ? 0 : (int) $place;

 if ($position > 0) {
 $placed[] = ['position' => $position, 'key' => $key];
 }
 }

 if (count($options) < 2) {
 throw new InvalidAnswerException('Нужно хотя бы два элемента', 'option_text[]');
 }

 if (count($placed) !== count($texts)) {
throw new InvalidAnswerException(
  'Укажите место для каждого элемента: верный порядок должен включать все элементы',
  'order_pos[]'
  );
  }

  usort($placed, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

  $order = array_map(static fn (array $row): string => (string) $row['key'], $placed);

  if (count(array_unique($order)) !== count($order)) {
  throw new InvalidAnswerException('Места элементов повторяются: укажите их по порядку', 'order_pos[]');
  }

 $partial = (string) $request->postParam('partial', 'positional');

 return [[
 'correct_order' => $order,
 'partial' => in_array($partial, OrderingType::partialModes(), true) ? $partial : 'positional',
 ], $options];
 }

 /**
 * Число: точное значение либо диапазон.
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readNumeric(Request $request): array
 {
 $mode = (string) $request->postParam('mode', NumericType::MODE_EXACT);

 if (!in_array($mode, NumericType::modes(), true)) {
 $mode = NumericType::MODE_EXACT;
 }

 $unit = trim((string) $request->postParam('unit', ''));
 $integer = $this->checkedFlag($request, 'integer');
 $tolerance = $this->number($request->postParam('tolerance'));

 $config = [
 'mode' => $mode,
 'unit' => $unit,
 'integer' => $integer,
 'tolerance' => $tolerance !== null && $tolerance >= 0.0 ? $tolerance : 0.01,
 ];

 if ($mode === NumericType::MODE_RANGE) {
 $config['min'] = $this->number($request->postParam('min'));
 $config['max'] = $this->number($request->postParam('max'));
 } else {
 $config['expected'] = $this->number($request->postParam('expected'));
 }

 return [$config, []];
 }

 /**
 * Текстовый ответ.
 *
 * Стратегия «ручная проверка» специально вынесена в отдельную ветку: для
 * неё не нужны ни ключевые слова, ни эталонный ответ, и требовать их было
 * бы ошибкой — именно этот режим и означает «проверит учитель».
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readText(Request $request): array
 {
 $strategy = (string) $request->postParam('strategy', TextAnswerType::STRATEGY_KEYWORDS);

 if (!in_array($strategy, TextAnswerType::strategies(), true)) {
 $strategy = TextAnswerType::STRATEGY_KEYWORDS;
 }

 $partial = $this->checkedFlag($request, 'partial');
 $ignoreCase = $this->checkedFlag($request, 'ignore_case');
 $ignoreOrder = $this->checkedFlag($request, 'ignore_order');

 $rows = $this->number($request->postParam('rows')) ?? 3.0;
 $maxLength = $this->number($request->postParam('max_length')) ?? 0.0;
 $minLength = $this->number($request->postParam('min_length')) ?? 0.0;

 $config = [
 'strategy' => $strategy,
 'partial' => $partial,
 'ignore_case' => $ignoreCase,
 'ignore_order' => $ignoreOrder,
 'rows' => (int) max(1, min(20, $rows)),
 'max_length' => (int) max(0, $maxLength),
 'min_length' => (int) max(0, $minLength),
 'required_keywords' => $this->wordList($request->postArray('required_keywords')),
 'forbidden_keywords' => $this->wordList($request->postArray('forbidden_keywords')),
 'variants' => $this->lineList($request->postArray('variants')),
 ];

 if ($strategy === TextAnswerType::STRATEGY_MANUAL) {
 /*
 * Ручная проверка не имеет эталона: ключевые слова и варианты для
 * неё бессмысленны и только мешали бы учителю при проверке.
 */
 $config['expected'] = '';
 $config['required_keywords'] = [];
 $config['forbidden_keywords'] = [];
 $config['variants'] = [];
 $config['partial'] = true;

return [$config, []];
 }

 $expected = trim((string) $request->postParam('expected', ''));
 $config['expected'] = $expected;

 if ($strategy === TextAnswerType::STRATEGY_KEYWORDS && $config['required_keywords'] === []) {
 throw new InvalidAnswerException('Укажите ключевые слова или выберите другой способ проверки', 'required_keywords');
 }

 if ($strategy !== TextAnswerType::STRATEGY_KEYWORDS && $expected === '' && $config['variants'] === []) {
 throw new InvalidAnswerException('Укажите ожидаемый ответ или его варианты', 'expected');
 }

 return [$config, []];
 }

 /**
 * Соответствие: автор расставляет пары «понятие — определение».
 *
 * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
 */
 private function readMatching(Request $request): array
 {
 $lefts = $this->readOptionRows($request, 'left_text');
 $rights = $this->readOptionRows($request, 'right_text');
 $selected = $this->codeList($request->postArray('correct_pair'));

 if (count($lefts) < 2) {
 throw new InvalidAnswerException('Нужно хотя бы две пары для соответствия', 'left_text[]');
 }

 $options = [];
 $pairs = [];

 foreach ($lefts as $index => $left) {
 $key = 'l' . ($index + 1);

 $options[] = [
 'kind' => Question::KIND_LEFT,
 'value_key' => $key,
 'text' => $left['text'],
 ];

 $right = $rights[$index] ?? null;

 if ($right !== null) {
 $options[] = [
 'kind' => Question::KIND_RIGHT,
 'value_key' => 'r' . ($index + 1),
 'text' => $right['text'],
 ];
 }

 if (in_array($key, $selected, true) && $right !== null) {
 $pairs[$key] = 'r' . ($index + 1);
 }
 }

 if ($pairs === []) {
 throw new InvalidAnswerException('Отметьте хотя бы одну верную пару', 'correct_pair[]');
 }

 $partial = $this->checkedFlag($request, 'partial');

 return [['pairs' => $pairs, 'partial' => $partial], $options];
 }

 /**
 * Обычные варианты ответа: тексты и отметка правильного.
 *
 * @return list<array<string,mixed>>
 */
 private function readOptions(Request $request, ?array &$keyMap = null): array
 {
 $options = [];
 $keyMap = [];
 $rows = $this->readOptionRows($request, 'option_text');

 if (count($rows) < 2) {
 throw new InvalidAnswerException('Нужно хотя бы два варианта ответа', 'option_text[]');
 }

 /*
 * Ключ варианта — по порядку среди непустых строк, а отметка «правильный»
 * приходит номером строки формы. Поэтому запоминаем, во что строка
 * превратилась: без этого пропущенная посередине строка сдвинула бы
 * буквы, и отметка указывала бы на другой вариант.
 */
 foreach ($rows as $position => $row) {
 $key = chr(97 + $position);
 $keyMap[(string) $row['index']] = $key;

 $options[] = [
 'kind' => Question::KIND_OPTION,
 'value_key' => $key,
 'text' => $row['text'],
 ];
 }

 return $options;
 }

 /**
 * Непустые строки поля формы — так пропущенные строки не мешают.
 *
 * Номер строки возвращается вместе с текстом: он нужен тем типам, где
 * правильный вариант отмечается прямо в строке.
 *
 * @return list<array{index:int,text:string}>
 */
 private function readOptionRows(Request $request, string $key): array
 {
 $rows = [];

 foreach ($request->postArray($key) as $index => $value) {
 if (!is_scalar($value)) {
 continue;
 }

 $text = trim((string) $value);

 if ($text !== '') {
 $rows[] = ['index' => (int) $index, 'text' => $text];
 }
 }

 return $rows;
 }

/**
 * Правая часть формы подставляется из сохранённого вопроса.
 *
 * Уже сохранённые значения нужны, чтобы правка вопроса начиналась с того,
 * что автор видел, а не с пустой формы.
 *
 * @param array<string,mixed> $question
 * @return array<string,mixed>
 */
    private function formFor(?array $question, ?array $test = null): array
 {
 if ($question === null) {
 return [
 'id' => 0,
 'type' => 'single_choice',
 'prompt' => '',
 'weight' => 1.0,
 'required' => true,
 'explanation' => '',
 'config' => [],
 'options' => [],
 'correct' => [],
  'order' => [],
  'pairs' => [],
  'hasOptions' => true,
  'shuffleOptions' => (bool) ($test['shuffle_options'] ?? false),
  ];
  }

 $type = (string) $question['type'];
 $config = $question['config'];

 $options = [];

    foreach ($question['options'] as $option) {
    $options[(string) $option['kind']][] = [
    'value' => (string) $option['value_key'],
    'text' => (string) $option['text'],
    /*
     * Отметка «обязательно показывать» — часть варианта, а не отдельная
     * настройка. Без неё форма открывалась бы с незамеченными флажками, и
     * автор видел бы, что его отметка не сохранилась, хотя она сохранена.
     */
    'always' => (int) ($option['always_show'] ?? 0) === 1,
    ];
    }

 $correct = [];

 switch ($type) {
 case 'single_choice':
 $correct = [(string) ($config['correct'] ?? '')];
 break;
 case 'multiple_choice':
 $correct = is_array($config['correct'] ?? null) ? $config['correct'] : [];
 break;
 case 'ordering':
 $correct = is_array($config['correct_order'] ?? null) ? $config['correct_order'] : [];
 break;
 case 'matching':
 $lefts = $options[Question::KIND_LEFT] ?? [];
 $correct = [];

 foreach ($lefts as $index => $left) {
 $paired = $config['pairs'][$left['value']] ?? null;

 if ($paired !== null) {
 $correct[$index] = $paired;
 }
 }
 break;
 }

 return [
 'id' => (int) $question['id'],
 'type' => $type,
 'prompt' => (string) $question['prompt'],
 'weight' => (float) $question['weight'],
  'required' => (bool) $question['is_required'],
  'explanation' => (string) ($question['explanation'] ?? ''),
  'config' => $config,
  'options' => $options,
  'correct' => $correct,
  'order' => $options[Question::KIND_ITEM] ?? ($options[Question::KIND_OPTION] ?? []),
  'pairs' => $options,
  /*
   * Настройки задания целиком, а не вопроса: варианты перемешиваются в
   * одном задании, и галочка «перемешать варианты» относится ко всем его
   * вопросам сразу.
   */
  'hasOptions' => in_array((string) $question['type'], self::TYPES_WITH_OPTIONS, true),
  'shuffleOptions' => (bool) ($test['shuffle_options'] ?? false),
  ];
  }

 /**
 * @param list<Question> $questions
 * @return array<string,mixed>|null
 */
    private function byId(array $questions, int $id, ?array $test = null): ?array
 {
 foreach ($questions as $question) {
 if ($question->id === $id) {
 return $this->formFor([
 'id' => $question->id,
 'type' => $question->type,
 'prompt' => $question->prompt,
 'weight' => $question->weight,
 'is_required' => $question->isRequired,
 'explanation' => $question->explanation ?? '',
 'config' => $question->config,
  'options' => $question->options,
  ], $test);
  }
  }

 return null;
 }

 /**
 * @return array<int,Question>
 */
 private function questionsById(int $testId): array
 {
 $map = [];

 foreach ($this->tests->questions($testId) as $question) {
 $map[$question->id] = $question;
 }

 return $map;
 }

 // ---------------------------------------------------------------- мелочи

 /** @param array<string,mixed> $test */
 private function requireAuthor(array $test): void
 {
 $user = $this->auth->user();

 if ($user === null) {
 throw new HttpException(401, 'Нужно войти в систему');
 }

 if ((int) $test['author_id'] !== $user->id) {
 throw new HttpException(403, 'Это тест другого автора');
 }
 }

 /** Число из поля формы; дробное остаётся дробным. */
 private function number(?string $value): int|float|null
 {
 if ($value === null || trim($value) === '') {
 return null;
 }

 $normalized = str_replace(',', '.', trim($value));

 if (!is_numeric($normalized)) {
 return null;
 }

 return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
 }

 /**
 * @return list<string>
 */
/**
 * Галочка прислана и включена.
 *
 * Снятую галочку браузер не присылает вообще — в запросе её просто нет. Значит
 * «поля нет» и «снято» это одно и то же, а различать их нечем и незачем: форма
 * всегда рисует галочку, и отсутствие ключа означает ровно «автор снял».
 *
 * Раньше здесь стояло «нет поля — включено», и снятая галочка включалась обратно
 * при каждом сохранении: автор снимал «Ответ обязателен», тот же вопрос
 * сохранялся обязательным. Побочно это правило объясняло и появление отметки там,
 * где её не ставили.
 *
 * Так же читается и ключ, когда его нет вовсе: подделанный запрос без галочки
 * означает «выключено», иначе подделкой можно было бы включать что угодно.
 */
private function checkedFlag(Request $request, string $name): bool
{
 return array_key_exists($name, $request->post()) && $request->postParam($name) === '1';
 }

private function codeList(array $values): array
{
 $items = [];

 foreach ($values as $value) {
 if (is_scalar($value)) {
 $text = trim((string) $value);

 if ($text !== '') {
 $items[] = $text;
 }
 }
 }

 return $items;
 }

 /**
 * @return list<string>
 */
 private function wordList(array $values): array
 {
 $items = [];

 foreach ($values as $value) {
 if (!is_scalar($value)) {
 continue;
 }

 $text = trim((string) $value);

 if ($text === '') {
 continue;
 }

 // Автор пишет ключевые слова через запятую или по одному в строке —
 // оба варианта одинаково привычны.
 foreach (preg_split('/[\s,;]+/u', $text) ?: [] as $word) {
 $word = trim($word);

 if ($word !== '') {
 $items[] = $word;
 }
 }
 }

 return array_values(array_unique($items));
 }

 /**
 * @return list<string>
 */
 private function lineList(array $values): array
 {
 $items = [];

 foreach ($values as $value) {
 if (!is_scalar($value)) {
 continue;
 }

 $text = trim((string) $value);

 if ($text !== '') {
 $items[] = $text;
 }
 }

 return $items;
 }
}
