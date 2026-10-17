<?php

declare(strict_types=1);

namespace MyEduPlugin\Variable\Http;

use MyEdu\Auth\Auth;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\TextFragment;
use MyEdu\Core\View;
use MyEdu\Repository\AssignmentRepository;
use MyEduPlugin\Variable\InvalidProblemException;
use MyEduPlugin\Variable\ProblemConfig;
use MyEduPlugin\Variable\Repository\TaskRepository;
use MyEduPlugin\Variable\VariableGenerator;

/**
 * Редактор вариативной задания.
 *
 * Экран целиком принадлежит плагину: поля переменных, формулы и правила
 * целостности — его данные, и ядро о них ничего не знает. Поэтому ядро и не
 * рисует форму, а отправляет учителя на адрес, объявленный в маршрутах.
 *
 * Два входа на один и тот же экран: отдельная страница и фрагмент для
 * встраивания в редактор работы. Правила проверки настройки общие — иначе
 * пришлось бы держать две версии формы, которые разойдутся при первой правке.
 */
final class TaskEditorController
{
 /** Начало имени полей переменных: var_0[name], var_1[name] и так далее. */
 public const FIELD_PREFIX = 'var_';

 public function __construct(
 private readonly View $view,
 private readonly Session $session,
 private readonly Csrf $csrf,
 private readonly Auth $auth,
 private readonly Config $config,
 private readonly TaskRepository $tasks,
 private readonly AssignmentRepository $assignments,
 private readonly string $urlPrefix
 ) {
 }

 /**
 * Форма без обёртки страницы — карточкой в редакторе работы.
 *
 * Отдельной страницы редактора у задания нет: она убрана вместе с адресом
 * GET /task/{id}. Задание правится только в списке позиций работы, и
 * после сохранения автор возвращается туда же с закрытым редактором.
 */
 public function taskFragment(
 int $taskId,
 string $backUrl,
 bool $assignmentPublished = false,
 string $closeUrl = '',
 ?int $itemId = null,
 ?float $maxScore = null
 ): string {
 $task = $this->loadTask($taskId);

 return $this->view->render('variable/_task_form', [
 'task' => $task,
 'prefix' => $this->urlPrefix,
 // Возврат ведёт в редактор работы: форма отправляется на свой адрес
 // плагина, и без этого адреса автор после сохранения оказался бы на
 // странице, которой он не открывал.
 'backUrl' => $backUrl,

 /*
 * Опубликована ли работа, а не задание. У задания свой статус —
 * «черновик» или «опубликовано», — и по нему о публикации работы
 * сказать нельзя: работа с вариативным заданием внутри может быть
 * ещё черновиком, а задание — давно опубликовано.
 */
 'assignmentPublished' => $assignmentPublished,
 /*
 * Цена задания и адрес закрытия редактора. Цена раньше стояла в
 * списке позиций работы одной строкой на все плагины, и у
 * вариативной задачи своего места для неё не было вовсе: поле
 * убрали как дубль, а поставить цену было нечем.
 */
 'closeUrl' => $closeUrl !== '' ? $closeUrl : $backUrl,
 'itemId' => $itemId,
 'maxScore' => $maxScore,
 ], '');
 }

 /**
 * Сохранение задания.
 *
 * Проверка целостности обязательна здесь, а не при проверке работы: автор
 * видит «деление на ноль» в момент сохранения, а не ученик посреди своей
 * работы.
 */
 public function taskSave(Request $request, array $params): Response
 {
 $this->csrf->validate($request->postParam('_token'));

 $taskId = (int) $params['id'];
 $task = $this->loadTask($taskId);
 $backUrl = (string) $request->postParam('back', '');

$prompt = trim((string) $request->postParam('prompt', ''));

  if ($prompt === '') {
      return $this->respond($request, $backUrl, false, 'Введите условие задачи');
  }

  $config = $this->readConfig($request);

  try {
      ProblemConfig::validate($config, $prompt);
  } catch (InvalidProblemException $e) {
      return $this->respond($request, $backUrl, false, $e->getMessage());
  }

  $this->tasks->update($taskId, [
      'prompt' => $prompt,
      'config' => $config,
  ]);

  $priceError = $this->saveItemPrice($request, $taskId);

  /*
   * Назад уходит начало условия: в списке заданий карточка подписана именно
   * им, и без ответа на неё правка текста выглядела бы несохранившейся до
   * ручного обновления страницы.
   */
  return $this->respond(
      $request,
      $backUrl,
      $priceError === null,
      $priceError ?? 'Задание сохранено',
      TextFragment::from($prompt)
  );
}

 /**
 * Цена задания из формы.
 *
 * Цена живёт у позиции работы, и плагин пишет её через ядро: правило
 * «это моя позиция в моей работе» знает только ядро, а подделать номер
 * позиции в форме можно как угодно. Поэтому проверяются и номер позиции,
 * и её принадлежность этому заданию, и то, что работа принадлежит автору.
 *
 * Пустое поле — не ошибка, а «цену не меняли»: так форма ведёт себя и при
 * сохранении из старой вкладки, где поля ещё нет.
 *
 * @return string|null текст ошибки; null — цена принята или не присылалась
 */
 private function saveItemPrice(Request $request, int $taskId): ?string
 {
 $itemId = (int) $request->postParam('item', '0');

 if ($itemId <= 0) {
 return null;
 }

 $raw = trim((string) $request->postParam('max_score', ''));

 if ($raw === '') {
 return null;
 }

 if (!is_numeric(str_replace(',', '.', $raw))) {
 return 'Цена задания должна быть числом';
 }

 $price = (float) str_replace(',', '.', $raw);

 if ($price < 0) {
 return 'Цена задания не может быть отрицательной';
 }

 if ($price > 100000) {
 return 'Цена задания слишком велика';
 }

 $item = $this->assignments->findItem($itemId);

 if (
 $item === null
 || (int) $item['task_id'] !== $taskId
 || (string) $item['plugin_id'] !== 'variable'
 ) {
 return 'Не удалось сохранить цену: задание не найдено в этой работе';
 }

 $assignment = $this->assignments->find((int) $item['assignment_id']);

 if ($assignment === null || (int) $assignment['author_id'] !== (int) $this->auth->id()) {
 return 'Не удалось сохранить цену: работа принадлежит другому учителю';
 }

 $this->assignments->updateItem(['max_score' => $price], $itemId);

 return null;
 }

 /**
 * Ответ на сохранение: данными или страницей.
 *
 * Скрипту, отправляющему форму без перезагрузки, нужен ответ с сообщением
 * и заголовком — страницу он перезагружать не собирается. Обычной отправке
 * формы нужна страница: без JavaScript это единственный путь.
 */
private function respond(
        Request $request,
        string $backUrl,
        bool $ok,
        string $message,
        string $preview = ''
    ): Response {
 if ($request->wantsJson()) {
 return Response::json([
 'ok' => $ok,
 'message' => $message,
 'close' => $ok,
 'preview' => $preview,
 'url' => $backUrl === ''
 ? ''
 : $this->view->url($ok ? self::withoutEditParam($backUrl) : $backUrl),
 ], $ok ? 200 : 422);
 }

 $this->session->set($ok ? 'flash_notice' : 'flash_error', $message);

 return $this->back($backUrl, $ok);
 }

 // ---------------------------------------------------------------- разбор формы

 /**
 * Настройка из полей формы.
 *
 * Поля приходят как var_0[name], var_1[name] и так далее, поэтому PHP
 * разложит их в post() под ключами var_0, var_1… Обращение по
 * postArray('var_') искало бы ключ, которого не бывает, и задание сохранилось
 * бы вовсе без переменных — молча, без всякой ошибки. Поэтому ключи
 * перебираются и отбираются по префиксу, а порядок задаётся числом в конце.
 *
 * @return array<string,mixed>
 */
 public function readConfig(Request $request): array
 {
 $indexed = [];

 foreach ($request->post() as $key => $fields) {
 if (!is_string($key) || !str_starts_with($key, self::FIELD_PREFIX) || !is_array($fields)) {
 continue;
 }

 $index = substr($key, strlen(self::FIELD_PREFIX));

 // Ключ должен быть числом: иначе это чужое поле с похожим именем.
 if ($index === '' || !ctype_digit($index)) {
 continue;
 }

 $indexed[(int) $index] = $fields;
 }

 ksort($indexed, SORT_NUMERIC);

 $variables = [];

 foreach ($indexed as $fields) {
 $name = trim((string) ($fields['name'] ?? ''));

 if ($name === '') {
 continue;
 }

 $mode = (string) ($fields['mode'] ?? VariableGenerator::MODE_LIST);

 if (!in_array($mode, [VariableGenerator::MODE_LIST, VariableGenerator::MODE_RANGE], true)) {
 $mode = VariableGenerator::MODE_LIST;
 }

 $step = $this->number($fields['step'] ?? null);

 $variables[] = [
 'name' => $name,
 'mode' => $mode,
 'values' => trim((string) ($fields['values'] ?? '')),
 'min' => $this->number($fields['min'] ?? null),
 'max' => $this->number($fields['max'] ?? null),
 'step' => $step !== null && $step > 0 ? $step : 1.0,
 ];
 }

 // Порядок по имени: строки добавляются и удаляются, и без сортировки
 // форма переставлялась бы при каждом открытии.
 usort($variables, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

 $tolerance = $this->number($request->postParam('tolerance'));

 return [
 'variables' => $variables,
 /*
 * Формула одна на всю задачу: ученик решает задачу и вписывает один
 * ответ. Имена в формуле — те же, что в условии, и при проверке
 * подставляются те же числа, что были выданы ученику.
 */
 'formula' => trim((string) $request->postParam('formula', '')),
 'tolerance' => $tolerance !== null && $tolerance >= 0 ? $tolerance : ProblemConfig::DEFAULT_TOLERANCE,
 'unit' => trim((string) $request->postParam('unit', '')),
 ];
 }

 // ---------------------------------------------------------------- помощники

 /**
 * @return array<string,mixed>
 */
 private function loadTask(int $taskId): array
 {
 $task = $this->tasks->find($taskId);

 if ($task === null) {
 throw new HttpException(404, 'Задание не найдено');
 }

 $user = $this->auth->user();

 if ($user === null) {
 throw new HttpException(401, 'Нужно войти в систему');
 }

 if ((int) $task['author_id'] !== $user->id) {
 throw new HttpException(403, 'Это задание другого автора');
 }

 return $task;
 }

/**
 * Возврат в редактор работы.
 *
 * После сохранения открытое задание закрывается: правка сделана, дальше
 * автору нужен список позиций с их весами. При отказе редактор остаётся
 * открытым — форма собрана человеком, и закрывать её отказом значит
 * заставлять начинать заново.
 *
 * Адрес приводится к корню сайта обязательно: поле back приходит без
 * ведущего слэша, а Response::redirect() кладёт в Location строку как
 * есть. Без этого браузер разрешил бы адрес относительно
 * /variables/task/{id} и ушёл на 404 после успешного сохранения.
 */
 private function back(string $backUrl, bool $closeEditor): Response
 {
 if ($backUrl === '') {
 return Response::redirect($this->view->url('panel/assignments'));
 }

 return Response::redirect(
 $this->view->url($closeEditor ? self::withoutEditParam($backUrl) : $backUrl)
 );
 }

 /**
 * Убрать из адреса параметр edit — то самое открытое задание.
 *
 * Адрес разбирается по частям, а не регулярным выражением по строке: иначе
 * значение параметра с «редактировать» внутри текста вырезалось бы вместе
 * с ним.
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

 /** Число из поля формы; дробное остаётся дробным. */
 private function number(mixed $value): int|float|null
 {
 if (!is_scalar($value)) {
 return null;
 }

 $normalized = str_replace(',', '.', trim((string) $value));

 return $normalized === '' || !is_numeric($normalized) ? null : (float) $normalized;
 }
}