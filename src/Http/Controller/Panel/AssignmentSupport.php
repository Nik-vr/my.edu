<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Plugin\AssessmentPlugin;
use MyEdu\Plugin\TaskRef;
use MyEdu\Repository\AssignmentRepository;

/**
 * Общие помощники двух контроллеров работы.
 *
 * Разрезание AssignmentController оставило общий остаток: канбан
 * (AssignmentController) и мастер правки (WorkEditorController) зовут одни и
 * те же проверку авторства, запись полей «Начала», названия заданий и уборку
 * данных плагинов при удалении. Здесь это живёт в одном экземпляре, а не в
 * двух разъезжающихся копиях.
 *
 * Методы опираются на свойства использующего класса: $view, $session, $csrf,
 * $config, $auth, $db, $assignments, $drafts, $plugins — конструкторы обоих
 * контроллеров получают их одинаково.
 *
 * Константа LEGACY_TEST_KIND объявляется в каждом из двух контроллеров:
 * констант внутри трейта PHP не держит.
 */
trait AssignmentSupport
{
    /**
     * Удалить позицию и её данные плагина атомарно.
     *
     * Сначала плагин очищает ответы, затем ядро удаляет позицию, и после
     * освобождения ссылки плагин может удалить осиротевшее задание. Все SQL
     * callback-и используют соединение приложения и участвуют в этой
     * транзакции; исключение откатывает оба слоя.
     *
     * @param array<string,mixed> $item
     */
    private function deleteItemWithPluginData(int $assignmentId, array $item): bool
    {
        return $this->db->transaction(function () use ($assignmentId, $item): bool {
            $current = $this->db->one(
                'SELECT * FROM assignment_items WHERE id = :item FOR UPDATE',
                ['item' => (int) $item['id']]
            );

            if ($current === null || (int) $current['assignment_id'] !== $assignmentId) {
                throw new \RuntimeException('Позиция работы изменилась во время удаления');
            }

            $ref = TaskRef::fromItemRow($current);
            $plugin = $this->pluginForRemoval($ref);
            $plugin->deleteItem($ref, (int) $current['id']);

            if (!$this->assignments->deleteItem($assignmentId, (int) $current['id'])) {
                throw new \RuntimeException('Позиция работы изменилась во время удаления');
            }

            return $plugin->deleteTask($ref);
        });
    }
    /** Не удалять данные ядра, если плагин не может выполнить свою часть. */
    private function pluginForRemoval(TaskRef $ref): AssessmentPlugin
    {
        $plugin = $this->plugins->get($ref->pluginId);

        if (!$plugin instanceof AssessmentPlugin) {
            throw new TaskRemovalBlocked(
                'Нельзя безопасно удалить задание: плагин «' . $ref->pluginId
                . '» выключен или не загружается. Включите его и повторите удаление.'
            );
        }

        return $plugin;
    }
    /**
     * Позиции работы до её удаления — с тем, чем плагин будет уведомлён.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsBeforeRemoval(int $assignmentId): array
    {
        // Без try/catch намеренно: ошибка чтения должна быть видна, а не
        // превращаться в молчаливый пустой список — иначе плагин просто не
        // получит уведомления, а данные останутся лежать без виновника.
        return $this->db->all(
            'SELECT id, plugin_id, task_id, task_kind FROM assignment_items WHERE assignment_id = :id',
            ['id' => $assignmentId]
        );
    }

    // ================================================================ мастер
    //
    // Работа собирается в три этапа. Отдельные адреса, а не один длинный
    // экран: на втором этапе список заданий вырос бы, а на третьем критерии
    // занимают столько же места, сколько первый экран целиком, — и всё это
    // в одной форме означало бы прокрутку через половину работы, чтобы
    // добраться до кнопки внизу.
    //
    // Старый адрес /panel/assignments/{id} остаётся: он открывает все три
    // этапа сразу, и на него заходят из списка работ, где мастер был бы
    // лишним щелчком.
    /**
     * Поля первого шага мастера.
     *
     * Одна запись на оба пути — и для только что созданной работы, и для давно
     * готовой. Расходятся они были бы первым же новым полем: в одном месте оно
     * появилось бы, в другом — нет, и правка тихо перестала бы работать.
     *
     * Шкалы оценок здесь нет намеренно. Поля границ живут только в форме шага
     * «Результат» (/edit/result), и в эту форму они не входят: разбор видел их
     * пустыми и затирал шкалу целиком. Работа, у которой уже задана шкала,
     * теряла её при обычной правке названия или предмета — а публикация потом
     * упиралась в «не заданы параметры оценки», и автор не понимал, где их потерял.
     */
    private function applyStartFields(Request $request, int $assignmentId): void
    {
        $data = [
            'description'  => trim((string) $request->postParam('description', '')),
            'subject_id'   => $this->subjectId($request->postParam('subject_id')),
            'parallel'     => $this->parallel($request->postParam('parallel')),
            'time_limit'   => $this->timeLimit($request->postParam('time_limit')),
            /*
             * Порядок заданий для ученика.
             *
             * Значение приходит строкой «0» или «1», и никаким другим: переключатель
             * не может прийти пустым, иначе выключенное перемешивание молча
             * включалось бы при любом другом сохранении вкладки — форма общая, и
             * поля на ней не приходят все сразу.
             */
            'shuffle_items' => $request->postParam('shuffle_items') === '1' ? 1 : 0,
            /*
             * Запрет копирования — так же, как перемешивание: переключатель
             * приходит строкой «0» или «1», и никаким другим.
             */
            'no_copy' => $request->postParam('no_copy') === '1' ? 1 : 0,
            /*
             * Штраф за сдачу после срока — в баллах.
             *
             * Пустое поле означает «штрафа нет», а ноль — то же самое: снимать
             * нечего. Отрицательное значение отбрасывается, иначе «штраф» стал бы
             * надбавкой за опоздание.
             */
            'late_penalty' => $this->latePenalty($request->postParam('late_penalty')),
        ];

        /*
         * Название пишется только когда его прислали и оно не пустое.
         *
         * В редакторе название правят в шапке, отдельной формой, а общая форма
         * за вкладками названия не содержит. Без этой оговорки обычное
         * сохранение вкладок стирало бы имя работы, и человек увидел бы пустую
         * шапку рядом со своим текстом.
         */
        $title = $request->postParam('title', null);

        if ($title !== null && trim((string) $title) !== '') {
            $data['title'] = $this->limit(trim((string) $title), 191);
        }

        /*
         * Показ результата пишется только когда поле прислало само.
         *
         * У галочки на вкладке «Результат» есть скрытый спутник с нулём, и в
         * общей форме редактора поле приходит всегда — отмеченная галочка
         * перекрывает ноль, снятая остаётся нулём. А формы, где галочки вовсе
         * нет (первый шаг мастера), поле не присылают вовсе — и раньше они
         * молча писали сюда 0: работа, выставленная автором с показом
         * результата, скрывала его от ученика после любого сохранения шага
         * мастера. Такой случай поле не трогаем вовсе (владелец, 24.10.2026,
         * задача 2: ученик не видел результат работы с порогами оценок).
         */
        if ($request->postParam('show_results') !== null) {
            $data['show_results'] = $request->postParam('show_results') === '1' ? 1 : 0;
        }

        $this->assignments->update($data, ['id' => $assignmentId]);
    }
/**
 * Публикация работы: что автор увидит и что мешает выпустить.
     *
     * Проверки собраны здесь, а не в репозитории по одной: автор должен увидеть
     * все препятствия сразу, а не чинить их по одному, попутно перезагружая
     * страницу после каждой неудачной попытки.
     *
     * @return array<int,string> пустой список — публиковать можно
     */
    private function publishBlockers(int $assignmentId, array $assignment): array
    {
        $blockers = [];

        if ($this->assignments->countItems($assignmentId) === 0) {
            $blockers[] = 'В работе нет ни одного задания';
        }

        /*
         * Без шкалы оценок ученик получает процент без отметки, а учитель —
         * результат, который нельзя превратить в оценку: границы не заданы,
         * и превращать не во что. Проценты при этом показываются, и работа
         * выглядит готовой — поэтому это и запрещается.
         */
        if (trim((string) ($assignment['criteria'] ?? '')) === '') {
            $blockers[] = 'Не заданы параметры оценки: заполните шкалу на шаге «Результат»';
        }

foreach ($this->assignments->items($assignmentId) as $item) {
            $ref = TaskRef::fromItemRow($item);
            $plugin = $this->plugins->get($ref->pluginId);

            /*
             * Называем позицию началом условия, а без условия — её номером.
             * Пустые кавычки «Задание «»: плагин выключен» не называли ничего и
             * заставляли автора пересчитывать позиции, чтобы понять, о какой
             * речь: таких строк в работе может быть несколько.
             */
            $label = $this->safeTitle($plugin, $ref);
            $name = $label !== '' ? $label : '№' . (string) ($item['position'] ?? '?');

            if (!$plugin instanceof AssessmentPlugin) {
                $blockers[] = 'Задание «' . $name . '»: плагин выключен или не загрузился';

                continue;
            }

            try {
                $reason = $this->plugins->publicationBlocker($ref);
            } catch (\Throwable) {
                // Плагин не ответил. Молча пропустить здесь нельзя: это тот же
                // случай, что и выключенный плагин, — работа не готова.
                $reason = 'плагин не смог проверить задание';
            }

            if ($reason !== null) {
                $blockers[] = 'Задание «' . $name . '»: ' . $reason;
            }
        }

        return $blockers;
    }
    private function kindExists(string $pluginId, string $kind): bool
    {
        // knowsKind(), а не список видов: работа, созданная до смены правил,
        // ссылается на вид, которого в taskKinds() уже нет, но плагин его ещё
        // обслуживает. Отказ по такому виду сделал бы старую работу неоткрываемой.
        return $this->plugins->knowsKind($pluginId, $kind);
    }
    /**
     * Позиции работы вместе с началом условия задания.
     *
     * Начало условия берётся у плагина: автор правит текст — редактор должен
     * показать новое, без отдельного снимка, который расходился бы с заданием.
     *
     * @return list<array<string,mixed>>
     */
    private function itemRows(int $assignmentId): array
    {
        $rows = [];

        foreach ($this->assignments->items($assignmentId) as $item) {
            $ref = TaskRef::fromItemRow($item);
            $plugin = $this->plugins->get($ref->pluginId);

$rows[] = $item + [
                    'preview' => $this->safeTitle($plugin, $ref),
                    'kind'    => $this->kindTitle($plugin, $ref),
                    'plugin'  => $plugin?->name() ?? ('плагин ' . $ref->pluginId . ' выключен'),
                    'broken'  => !$plugin instanceof AssessmentPlugin,
                    'editUrl' => $this->editUrl($plugin, $ref, $assignmentId),
                ];
        }

        return $rows;
    }
    /**
     * Строки доски: позиции работы вместе с началом условия задания.
     *
     * То же, что itemRows(), но собранное по позициям: место в работе задаёт
     * строка, и альтернативы её лежат рядом, а не в общем списке. Живое
     * название берётся у плагина по той же причине — автор переименовал тест,
     * доска должна показать новое имя.
     *
     * @return list<list<array<string,mixed>>>
     */
    private function taskRows(int $assignmentId): array
    {
        $grouped = [];

        foreach ($this->itemRows($assignmentId) as $item) {
            $grouped[(int) $item['position']][] = $item;
        }

        ksort($grouped);

        return array_values($grouped);
    }
    private function kindTitle(?AssessmentPlugin $plugin, TaskRef $ref): string
    {
        if ($plugin === null) {
            return $ref->kind;
        }

        foreach ($this->plugins->taskKinds($ref->pluginId) as $kind) {
            if ($kind->slug === $ref->kind) {
                return $kind->title;
            }
        }

        // Вид, оставшийся от старых работ, в списке видов уже не значится.
        // Показывать ученику и учителю машинное имя «test» вместо названия
        // нельзя, поэтому у таких позиций оно подставляется отдельно.
        return $ref->kind === self::LEGACY_TEST_KIND ? 'Тест с вопросами' : $ref->kind;
    }
/**
     * Фрагмент текста задания для показа в списке.
     *
     * Битый плагин не должен ронять редактор работы: у такой позиции просто
     * нет подписи, и она помечена как проблемная отдельной отметкой.
     */
    private function safeTitle(?AssessmentPlugin $plugin, TaskRef $ref, string $fallback = ''): string
    {
        if ($plugin === null) {
            return $fallback;
        }

        $preview = $this->plugins->taskPreview($ref);

        if ($preview === null) {
            return $fallback;
        }

        return $preview !== '' ? $preview : $fallback;
    }
    /*
     * Ссылка ведёт в редактор задания. back нужен, чтобы после сохранения
     * автор вернулся к списку заданий работы, а не остался на самом задании:
     * чаще всего он правит несколько заданий подряд.
     */
    private function editUrl(?AssessmentPlugin $plugin, TaskRef $ref, int $assignmentId): ?string
    {
        if ($plugin === null) {
            return null;
        }

        $url = $this->plugins->taskEditorUrl($ref);

        if (!is_string($url) || $url === '') {
            return null;
        }

        $back = '/panel/assignments/' . $assignmentId . '/edit/items';

        return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($back);
    }
    /**
     * Активные предметы для выбора в работе.
     *
     * Выключенные не показываются: назначить работу выключенному предмету
     * нельзя, но в работах за прошлые годы он остаётся.
     *
     * @return list<array<string,mixed>>
     */
    private function subjects(): array
    {
        return $this->db->all('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name');
    }
    /**
     * Автор работы — текущий пользователь.
     *
     * Чужие работы не редактируются. Право tests.manage есть у завуча, но
     * чужие черновики ему не нужны, а общий список чужих работ сбил бы с толку.
     */
    private function authorId(): int
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401, 'Нужно войти в систему');
        }

        return $user->id;
    }
    /** @return array<string,mixed> */
    private function requireOwned(int $id): array
    {
        $assignment = $this->assignments->find($id);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        if ((int) $assignment['author_id'] !== $this->authorId()) {
            throw new HttpException(403, 'Это работа другого автора');
        }

        return $assignment;
    }
    private function gradeMode(?string $value): string
    {
        return $value === AssignmentRepository::MODE_PER_ITEM
            ? AssignmentRepository::MODE_PER_ITEM
            : AssignmentRepository::MODE_WEIGHTED;
    }
    /**
     * Параллель работы из поля формы.
     *
     * Только для порядка в списке: работа ни к чему не привязана, и ученик
     * получит её только через выдачу. Вне границ 1–11 значение отбрасывается
     * — иначе опечатка в поле завела бы папку «0 классы» и увела работу из
     * списка, и автор её не нашёл бы.
     */
    private function parallel(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parallel = (int) $value;

        return ($parallel < 1 || $parallel > 11) ? null : $parallel;
    }
    private function subjectId(?string $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
    private function timeLimit(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? min($minutes, 600) : null;
    }
    /**
     * Штраф за сдачу после срока, в баллах.
     *
     * Пустое поле и ноль значат одно и то же: штрафа нет. Отрицательное
     * значение отбрасывается — иначе «штраф» превратился бы в надбавку за
     * опоздание, а это противоположно смыслу.
     */
    private function latePenalty(?string $value): float
    {
        $penalty = $this->postFloatParam($value);

        if ($penalty === null || $penalty <= 0.0) {
            return 0.0;
        }

        return min(round($penalty, 2), 999.99);
    }
    /**
     * Вес позиции.
     *
     * Ноль и отрицательные значения недопустимы: при нулевом весе позиция
     * выпала бы из сведения, а отрицательный переворачивал бы смысл балла.
     * Такое значение заменяется на 1, а не отбрасывается молча.
     */
    private function weight(?string $value): float
    {
        $weight = $this->postFloatParam($value);

        return $weight !== null && $weight > 0.0 ? min(round($weight, 2), 999.99) : 1.0;
    }
    private function limit(string $text, int $length): string
    {
        return mb_substr($text, 0, $length, 'UTF-8');
    }

    /** Название предмета работы; предмета может не быть вовсе. */
    private function subjectName(array $assignment): ?string
    {
        $subjectId = (int) ($assignment['subject_id'] ?? 0);

        if ($subjectId === 0) {
            return null;
        }

        foreach ($this->subjects() as $subject) {
            if ((int) $subject['id'] === $subjectId) {
                return (string) $subject['name'];
            }
        }

        return null;
    }

    /**
     * Число из поля формы: запятая приводится к точке, мусор даёт null.
     *
     * Живёт здесь, а не в одном из контроллеров: метод нужен и шкале
     * оценок (вкладка «Результат»), и весу позиции при добавлении задания,
     * а оба вызова идут из методов трейта.
     */
    private function postFloatParam(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
