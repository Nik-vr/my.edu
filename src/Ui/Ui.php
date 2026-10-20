<?php

declare(strict_types=1);

namespace MyEdu\Ui;

use MyEdu\Core\View;

/**
 * Типовые элементы интерфейса: поля, редактор, загрузка файла, сообщения.
 *
 * Смысл класса — один. Плагин пишет формы сам, и раньше это означало, что
 * одинаковое поле в ядре и в плагине выглядело по-разному: подпись, подсказка,
 * отметка ошибки и обязательность собирались вручную в каждом шаблоне и
 * расходились при первой же правке. Здесь элемент один, и рисует его ядро.
 *
 * Плагин получает объект от ядра: в шаблоне — как `$ui`, в коде контроллера —
 * как `$context->view->ui()`. Ничего своего рисовать не нужно: неизвестный
 * класс оформления в плагине — это расхождение с ядром, которого здесь быть
 * не может.
 *
 * Разметка — из образца gentelella (`.form-group`, `.form-label`,
 * `.form-control`, `.form-help`, `.form-error`, `.form-check`) плюс правила
 * проверки полей из `form-validate.js` (`required`, `min`, `max`, `maxlength`,
 * `data-hint`). Элемент нарисован в одном месте, а не в двух.
 *
 * Проверка в браузере живёт в обоих сразу: `form-validate.js` подключён
 * оболочкой панели и не смотрит, чьё это поле, — ядро и плагин отвечают о
 * поле одинаково.
 */
final class Ui
{
    /**
     * Параметры, которые попадают в разметку как атрибуты с тем же именем.
     *
     * Список, а не «всё, что не забрали»: параметр `value` не должен вдруг
     * оказаться одноимённым атрибутом, а `attrs` для редких случаев есть.
     */
    private const ATTRIBUTES = [
        'required', 'placeholder', 'maxlength', 'min', 'max', 'step', 'size',
        'autocomplete', 'inputmode', 'accept', 'disabled', 'readonly', 'multiple',
        'rows', 'title', 'pattern',
    ];

    /** Параметры, которые не атрибуты, хотя похожи на них. */
    private const NOT_ATTRIBUTES = [
        'label', 'labelClass', 'type', 'value', 'help', 'hint', 'error', 'checked',
        'wrapperClass', 'wrapperAttrs', 'attrs', 'options', 'id', 'role', 'legend',
    ];

    public function __construct(private readonly View $view)
    {
    }

    /**
     * Поле формы.
     *
     * @param array<string,mixed> $o Параметры:
     *        label — подпись (без неё поле останется без подписи, как в строке
     *        фильтров); type — тип поля, по умолчанию text; value — значение;
     *        help — подсказка под полем; hint — своё сообщение проверки
     *        (попадает в data-hint); error — подпись ошибки: поле получит
     *        .is-invalid, под ним появится .form-error; required, placeholder,
     *        maxlength, min, max, step, size, autocomplete, inputmode, accept,
     *        disabled, readonly — обычные атрибуты поля; id — идентификатор
     *        (по умолчанию имя поля); attrs — прочие атрибуты; wrapperClass —
     *        класс обёртки вместо form-group (нужно блокам, которые задают
     *        раскладку); wrapperAttrs — атрибуты обёртки.
     */
    public function field(string $name, array $o = []): string
    {
        $id = (string) ($o['id'] ?? $name);
        $hint = trim((string) ($o['hint'] ?? ''));
        $error = trim((string) ($o['error'] ?? ''));

        $control = $this->tag('input', [
            'class'    => 'form-control' . ($error === '' ? '' : ' is-invalid'),
            'type'     => (string) ($o['type'] ?? 'text'),
            'id'       => $id,
            'name'     => $name,
            'value'    => (string) ($o['value'] ?? ''),
            'data-hint' => $hint === '' ? null : $hint,
            /*
             * Поле с ошибкой объявляется и для чтения с экрана: иначе человек,
             * который не видит страницу, узнает об ошибке только из подписи под
             * полем — а её он тоже не видит.
             */
            'aria-invalid' => $error === '' ? null : 'true',
        ] + $this->attributeOptions($o));

        return $this->wrap($control, $o, $id);
    }

    /**
     * Поле в несколько строк.
     *
     * @param array<string,mixed> $o Те же параметры, что у field, плюс rows
     */
    public function textarea(string $name, array $o = []): string
    {
        $id = (string) ($o['id'] ?? $name);
        $error = trim((string) ($o['error'] ?? ''));

        $control = $this->tag('textarea', [
            'class'   => 'form-control' . ($error === '' ? '' : ' is-invalid'),
            'id'      => $id,
            'name'    => $name,
            'rows'    => (string) max(1, (int) ($o['rows'] ?? 3)),
            'aria-invalid' => $error === '' ? null : 'true',
        ] + $this->attributeOptions($o, ['rows']), (string) ($o['value'] ?? ''));

        return $this->wrap($control, $o, $id);
    }

    /**
     * Список с выбором.
     *
     * @param list<array<string,mixed>>|array<string,string> $items либо список
     *        ['value' => …, 'label' => …, 'selected' => …], либо пары
     *        значение => подпись
     * @param array<string,mixed> $o Параметры те же, что у field; value — чему
     *        соответствует отмеченный пункт, если у пункта нет своей отметки
     */
    public function select(string $name, array $items, array $o = []): string
    {
        $id = (string) ($o['id'] ?? $name);
        $error = trim((string) ($o['error'] ?? ''));
        $current = (string) ($o['value'] ?? '');
        $options = '';

        foreach ($this->items($items) as $item) {
            $selected = $item['selected'] || ($current !== '' && $item['value'] === $current);

            $options .= sprintf(
                '<option value="%s"%s%s>%s</option>',
                $this->e($item['value']),
                $selected ? ' selected' : '',
                $item['disabled'] ? ' disabled' : '',
                $this->e($item['label'])
            );
        }

        $control = $this->tag('select', [
            'class' => 'form-control' . ($error === '' ? '' : ' is-invalid'),
            'id'    => $id,
            'name'  => $name,
            'aria-invalid' => $error === '' ? null : 'true',
        ] + $this->attributeOptions($o), $options);

        return $this->wrap($control, $o, $id);
    }

    /**
     * Флажок с подписью.
     *
     * Обёртку не рисует: подпись у флажка своя, вторая сверху повторила бы её
     * дважды. Блок, в котором стоит флажок, задаёт вызывающий.
     *
     * @param array<string,mixed> $o Параметры: label (обязательно), name, id,
     *        value, checked, disabled, help, hint, attrs
     */
    public function checkbox(string $name, array $o = []): string
    {
        return $this->choice('checkbox', $name, $o);
    }

    /**
     * Переключатель.
     *
     * @param array<string,mixed> $o Параметры те же, что у checkbox, плюс role
     */
    public function radio(string $name, array $o = []): string
    {
        return $this->choice('radio', $name, $o);
    }

    /**
     * Переключатель «вкл/выкл» — как на странице плагинов.
     *
     * @param array<string,mixed> $o Параметры те же, что у checkbox
     */
    public function toggle(string $name, array $o = []): string
    {
        $id = (string) ($o['id'] ?? $name);
        $label = (string) ($o['label'] ?? '');

        $control = '<label class="switch" for="' . $this->e($id) . '">'
            . $this->tag('input', [
                'type'    => 'checkbox',
                'class'   => 'switch-input',
                'id'      => $id,
                'name'    => $name,
                'value'   => '1',
                'checked' => !empty($o['checked']) ? true : null,
            ] + $this->attributeOptions($o, ['checked', 'value']))
            . '<span class="slider round" aria-hidden="true"></span>'
            . '<span class="switch-label">' . $this->e($label) . '</span>'
            . '</label>';

        return $this->withNote($control, $o);
    }

    /**
     * Группа полей с подписью — набор, который человек заполняет целиком.
     *
     * Подпись ставится полем (`<legend>`), а не обёрткой: без рамки группы
     * браузерная подпись в тёмной теме выглядит поломкой вёрстки.
     *
     * @param array<string,mixed> $o Параметры: label (обязательно), legend —
     *        подпись полем (по умолчанию) или простой строкой (legend = false),
     *        help, error, attrs, wrapperClass
     */
    public function group(array $o, string $inner): string
    {
        $legend = array_key_exists('legend', $o) ? (bool) $o['legend'] : true;
        $label = (string) ($o['label'] ?? '');

        $html = $legend
            ? '<fieldset class="form-group"' . $this->extraAttributes($o['attrs'] ?? null) . '>'
                . '<legend class="form-label">' . $this->e($label) . '</legend>'
                . $inner
                . '</fieldset>'
            : '<div class="' . $this->e($this->wrapperClass($o)) . '"' . $this->extraAttributes($o['attrs'] ?? null) . '>'
                . '<div class="form-label">' . $this->e($label) . '</div>'
                . $inner
                . '</div>';

        $error = trim((string) ($o['error'] ?? ''));
        $help = trim((string) ($o['help'] ?? ''));

        if ($error !== '') {
            $html .= $this->fieldError($error);
        } elseif ($help !== '') {
            $html .= '<div class="form-help">' . $this->e($help) . '</div>';
        }

        return $html;
    }

    /**
     * Визуальный редактор текста.
     *
     * Рисует ровно то, что нужно плагину: сам редактор, скрытое поле, в которое
     * уходит содержимое, и запасной textarea для случая без скриптов. Загрузка
     * картинок идёт через адрес ядра, поэтому плагину не нужно знать маршрут.
     *
     * @param array<string,mixed> $o Параметры: label, name, value, placeholder,
     *        upload (адрес загрузки; по умолчанию адрес ядра), rows, help, error
     */
    public function editor(string $name, array $o = []): string
    {
        $id = (string) ($o['id'] ?? ($name . '-editor'));
        $fieldId = $id . '-field';
        $value = (string) ($o['value'] ?? '');
        $rows = max(2, (int) ($o['rows'] ?? 3));
        $upload = (string) ($o['upload'] ?? $this->view->url('panel/editor/image'));

        $control = sprintf(
            '<div class="ql-host">'
            . '<div class="ql-editor-host" data-upload-url="%s" data-placeholder="%s">%s</div>'
            . '<input type="hidden" name="%s" id="%s" value="%s">'
            . '</div>'
            . '<noscript><textarea name="%s" rows="%d">%s</textarea>'
            . '<p class="form-help">Без JavaScript доступен обычный текст без форматирования и без картинок.</p>'
            . '</noscript>',
            $this->e($upload),
            $this->e($o['placeholder'] ?? ''),
            $this->view->rich($value),
            $this->e($name),
            $this->e($fieldId),
            $this->e($value),
            $this->e($name),
            $rows,
            $this->e($value)
        );

        return $this->wrap($control, $o + ['id' => $id], $id);
    }

    /**
     * Выбор файла.
     *
     * @param array<string,mixed> $o Параметры те же, что у field
     */
    public function file(string $name, array $o = []): string
    {
        $id = (string) ($o['id'] ?? $name);
        $error = trim((string) ($o['error'] ?? ''));

        $control = $this->tag('input', [
            'class' => 'form-control' . ($error === '' ? '' : ' is-invalid'),
            'type'  => 'file',
            'id'    => $id,
            'name'  => $name,
            'aria-invalid' => $error === '' ? null : 'true',
        ] + $this->attributeOptions($o));

        return $this->wrap($control, $o, $id);
    }

    /** Скрытое поле. */
    public function hidden(string $name, mixed $value = ''): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', $this->e($name), $this->e($value));
    }

    /**
     * Скрипты визуального редактора.
     *
     * Подключает их ядро, а не плагин: библиотека и обработчики одни и те же
     * для всех систем проверки. Подключение в шаблоне плагина означало бы, что
     * забыть его можно где угодно — и страница при этом выглядит исправной,
     * а редактор не отвечает.
     */
    /**
     * Стили редактора текста.
     *
     * Отдельным методом от `editorScripts()` — и по той же причине, по которой
     * скрипты подключаются безусловно: форму задания в окно приносит скрипт
     * уже после загрузки страницы, и к этому моменту стили редактора должны быть
     * в документе. Подключать их «когда окно открыто сервером» значило бы
     * показать неоформленный редактор при первом же щелчке по карандашу: кнопки
     * панели съезжают в узкие кнопки интерфейса, а у поля нет высоты.
     */
    public function editorStyles(): string
    {
        return sprintf(
            '<link rel="stylesheet" href="%s">' . "\n"
            . '<link rel="stylesheet" href="%s">',
            $this->e($this->view->asset('assets/vendor/quill/quill.core.css')),
            $this->e($this->view->asset('assets/vendor/quill/quill.snow.css'))
        );
    }

    public function editorScripts(): string
    {
        /*
         * Скрипт вкладок редактора — здесь, а не в разметке формы.
         *
         * Форма плагина попадает в модальное окно двумя путями: целиком при
         * перезагрузке страницы и по данным, когда окно открывает скрипт. Во
         * втором случае разметка вставляется через innerHTML, а такие скрипты
         * браузер не выполняет: вкладки просто не переключались.
         *
         * Здесь скрипт подключается один раз на страницу, и потому работает
         * при любом способе открытия окна.
         */
        return sprintf(
            '<script src="%s"></script>' . "\n"
            . '<script src="%s" defer></script>' . "\n"
            . '<script src="%s" defer></script>',
            $this->e($this->view->asset('assets/vendor/quill/quill.js')),
            $this->e($this->view->asset('assets/js/editor.js')),
            $this->e($this->view->asset('assets/js/task-editor-tabs.js'))
        );
    }

    /**
     * Подпись ошибки под полем.
     *
     * Отдельный метод, а не только состояние поля: ошибку ставит сервер, а
     * рисовали её вручную в каждом шаблоне — в плагинах в том числе, где она
     * попросту не появлялась.
     */
    public function fieldError(string $message): string
    {
        return '<div class="form-error">' . $this->e($message) . '</div>';
    }

    /**
     * Сообщение на странице: ошибка, предупреждение, подсказка или отчёт.
     *
     * Плагину не нужно знать классы шаблона и роль для чтения с экрана: это
     * разные тонкости одного баннера.
     */
    public function message(string $text, string $kind = 'info'): string
    {
        return match ($kind) {
            'error' => $this->view->bannerError($text),
            'warning' => $this->view->bannerWarning($text),
            'ok' => $this->view->bannerOk($text),
            default => $this->view->bannerInfo($text),
        };
    }

    /**
     * Значок с восклицательным знаком и подсказкой при наведении.
     *
     * Предупреждение, которое повторяется на каждой странице редактора, не
     * должно занимать половину экрана: автор открывает редактор задания
     * десятки раз за день, и баннер отодвигал собственно форму всё ниже. Значок
     * занимает знак места, а текст остаётся доступен целиком — по наведению
     * мышью, по клавиатуре и для программ чтения с экрана.
     *
     * Подсказка сделана стилями, а не скриптом: страница обязана объяснять
     * предупреждение и там, где скрипты запрещены. На безымянном элементе без
     * роли и подписи подсказка была бы недоступна вслепую, поэтому роль и
     * aria-label обязательны и задаются здесь, а не шаблоном.
     *
     * @param string               $text полный текст предупреждения
     * @param array<string,mixed>  $o    Параметры: title (подпись у значка),
     *                                     class, attrs
     */
    public function warnHint(string $text, array $o = []): string
    {
        $label = (string) ($o['title'] ?? 'Предупреждение');

        $class = 'hint-warn' . (isset($o['class']) ? ' ' . (string) $o['class'] : '');
        $extra = $this->extraAttributes($o['attrs'] ?? null);

        return sprintf(
            '<span class="%s" data-hint="%s" tabindex="0" role="img" aria-label="%s: %s"%s>'
            . '<span aria-hidden="true">!</span>'
            . '</span>',
            $this->e($class),
            $this->e($text),
            $this->e($label),
            $this->e($text),
            $extra
        );
    }

    /**
     * Кнопка отправки или действия.
     *
     * @param array<string,mixed> $o Параметры: variant (btn-primary по
     *        умолчанию), size, type, name, value, disabled, attrs
     */
    public function button(string $label, array $o = []): string
    {
        $extra = $this->extraAttributes($o['attrs'] ?? null);

        if (isset($o['name'])) {
            $extra = ' name="' . $this->e($o['name']) . '"' . $extra;
        }

        if (isset($o['value']) && $o['value'] !== '') {
            $extra = ' value="' . $this->e($o['value']) . '"' . $extra;
        }

        if (!empty($o['disabled'])) {
            $extra .= ' disabled';
        }

        return sprintf(
            '<button type="%s" class="btn %s%s"%s>%s</button>',
            $this->e((string) ($o['type'] ?? 'submit')),
            $this->e((string) ($o['variant'] ?? 'btn-primary')),
            /*
             * `size` необязателен, и кнопка без него — обычное дело. Раньше
             * здесь стояло `$o['size'] === null`, и любая кнопка без размера
             * роняла страницу на «Undefined array key «size»»: автор плагина
             * передал кнопку как есть, без выдуманного требования.
             */
            ($o['size'] ?? null) === null || ($o['size'] ?? '') === ''
                ? ''
                : ' ' . $this->e((string) $o['size']),
            $extra,
            $this->e($label)
        );
    }

    /**
     * Строка варианта ответа: поле, отметка «правильный» и крестик удаления.
     *
     * Разметка строки — одна на оба плагина. Именно она копируется при
     * добавлении, и любое расхождение здесь означает, что новая строка уедет в
     * запрос не с тем именем поля: автор отметил правильный вариант, а
     * сохранение его отбросило.
     *
     * @param array<string,mixed> $o Параметры: name (имя поля текста),
     *        markName (имя поля отметки), kind ('single' или 'multiple'),
     *        index, value, placeholder, checked, drop (нужен ли крестик),
     *        markLabel
     */
    public function optionRow(array $o = []): string
    {
        $textName = (string) ($o['name'] ?? 'option_text[]');
        $markName = (string) ($o['markName'] ?? 'correct_single');
        $kind = (string) ($o['kind'] ?? 'single');
        $index = (int) ($o['index'] ?? 0);
        $markId = $this->uniqueId($markName . '-' . $index);

        /*
         * У флажков имя с хвостовыми скобками, у переключателей — без них.
         *
         * Формы отправляют одноимённые поля по-разному: переключатель в группе
         * нужен один, а флажков отмечают сколько угодно, и без скобок браузер
         * присылает **последний** отмеченный. Дальше `postArray('correct_multi')`
         * видел не массив, а число, возвращал пустой список — и автору, отметившему
         * правильные варианты, сообщали «отметьте хотя бы один правильный
         * вариант». Отметки на экране стояли, а сервер их не видел.
         */
        $markField = $kind === 'multiple' ? $markName . '[]' : $markName;

        $mark = sprintf(
            '<input type="%s" name="%s" value="%d" id="%s"%s>',
            $kind === 'multiple' ? 'checkbox' : 'radio',
            $this->e($markField),
            $index,
            $this->e($markId),
            !empty($o['checked']) ? ' checked' : ''
        );

        $drop = empty($o['drop']) ? '' : sprintf(
            '<button type="button" class="option-row__drop" data-option-drop title="%s" aria-label="%s">✕</button>',
            $this->e('Удалить вариант'),
            $this->e('Удалить вариант')
        );

        return sprintf(
            '<div class="option-row" data-row-index="%d">'
            . '<input type="text" name="%s" value="%s" placeholder="%s">'
            . '<label class="option-row__pick" for="%s">%s<span>%s</span></label>'
            . '%s'
            . '</div>',
            $index,
            $this->e($textName),
            $this->e($o['value'] ?? ''),
            $this->e($o['placeholder'] ?? ('Вариант ' . ($index + 1))),
            $this->e($markId),
            $mark,
            $this->e($o['markLabel'] ?? 'правильный'),
            $drop
        );
    }

    /**
     * Список строк вариантов с кнопкой добавления.
     *
     * @param list<string> $values значения уже сохранённых вариантов
     * @param array<string,mixed> $o Параметры: name, markName, kind, minimum
     *        (сколько строк рисовать, 3 по умолчанию), placeholder, markLabel,
     *        addLabel, help, drop, checked (номера отмеченных строк)
     */
    public function optionRows(array $values, array $o = []): string
    {
        $minimum = max(1, (int) ($o['minimum'] ?? 3));
        $count = max($minimum, count($values));
        $checked = array_map('intval', (array) ($o['checked'] ?? []));
        $rows = '';

        for ($i = 0; $i < $count; $i++) {
            $rows .= $this->optionRow([
                'name'      => $o['name'] ?? 'option_text[]',
                'markName'  => $o['markName'] ?? 'correct_single',
                'kind'      => $o['kind'] ?? 'single',
                'index'     => $i,
                'value'     => $values[$i] ?? '',
                'placeholder' => $o['placeholder'] ?? null,
                'markLabel' => $o['markLabel'] ?? 'правильный',
                'checked'   => in_array($i, $checked, true),
                'drop'      => $o['drop'] ?? true,
            ]);
        }

        $html = '<div class="option-rows" data-option-rows data-option-input="'
            . $this->e((string) ($o['name'] ?? 'option_text[]'))
            . '" data-option-kind="' . $this->e((string) ($o['kind'] ?? 'single')) . '">'
            . $rows
            . '</div>';

        $html .= '<p class="option-rows__actions">'
            . '<button type="button" class="btn btn-ghost" data-option-add>+ '
            . $this->e((string) ($o['addLabel'] ?? 'Добавить вариант'))
            . '</button>';

        $help = trim((string) ($o['help'] ?? ''));

        if ($help !== '') {
            $html .= '<span class="form-help">' . $this->e($help) . '</span>';
        }

        return $html . '</p>';
    }

    /**
     * Обёртка поля: подпись, сам элемент, подсказка или ошибка.
     *
     * @param array<string,mixed> $o параметры поля
     */
    private function wrap(string $control, array $o, string $id): string
    {
        $label = trim((string) ($o['label'] ?? ''));
        $help = trim((string) ($o['help'] ?? ''));
        $error = trim((string) ($o['error'] ?? ''));
        $labelClass = trim((string) ($o['labelClass'] ?? ''));

        /*
 * Подпись всегда экранируется.
 *
 * Раньше подпись, начинающаяся с «<», вставлялась как есть — на случай, если
 * кто-то захотел подписать поле готовой разметкой. Такого подписывающего поля
 * в проекте нет ни одного: все 179 вызовов передают обычный текст. А ветка
 * осталась и ждала того, кто передаст в подпись то, что покажет пользователю:
 * имя класса или фамилию из базы, начинающееся с «<», уехало бы в страницу
 * без экранирования.
 *
 * Подпись с разметкой, если она понадобится, — отдельный ключ labelRaw, и
 * звать его придётся нарочно.
 */
$labelHtml = $label === ''
            ? ''
            : '<label class="' . ($labelClass === '' ? 'form-label' : $labelClass) . '"'
                . ($id === '' ? '' : ' for="' . $this->e($id) . '"')
                . '>' . $this->e($label) . '</label>';

        $footer = $error !== '' ? $this->fieldError($error)
            : ($help === '' ? '' : '<div class="form-help">' . $this->e($help) . '</div>');

        return '<div class="' . $this->e($this->wrapperClass($o)) . '"'
            . $this->extraAttributes($o['wrapperAttrs'] ?? null)
            . '>'
            . $labelHtml . $control . $footer
            . '</div>';
    }

    private function wrapperClass(array $o): string
    {
        $class = trim((string) ($o['wrapperClass'] ?? ''));

        return $class === '' ? 'form-group' : $class;
    }

    /**
     * Теги без содержимого: закрывать их нельзя.
     *
     * Список в самом хуке, а не флагом у каждого вызова: однажды забытый флаг
     * даёт либо незакрытый `<textarea>` (браузер съедает следующее поле), либо
     * `</input>` в разметке. Обе ошибки не видны ни в PHP, ни в тестах без
     * браузера, и обе выглядят как «сломанная форма».
     */
    private const VOID_ELEMENTS = ['input', 'img', 'br', 'hr', 'meta', 'link'];

    /**
     * Собрать тег: атрибуты по порядку, значение — внутри.
     *
     * Значение true означает «само имя без значения» (checked, disabled),
     * null — «не выводить вовсе». Тег без содержимого закрывается сам, а у
     * остальных закрывающий тег ставится всегда: у пустого `<textarea>` без
     * `</textarea>` браузер считает текстом всё, что дальше.
     *
     * @param array<string,string|bool|null> $pairs
     */
    private function tag(string $name, array $pairs, string $text = ''): string
    {
        $out = '<' . $name;

        foreach ($pairs as $attribute => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $out .= ' ' . $attribute . ($value === true ? '' : '="' . $this->e($value) . '"');
        }

        return in_array($name, self::VOID_ELEMENTS, true)
            ? $out . '>'
            : $out . '>' . $text . '</' . $name . '>';
    }

    /**
     * Флажок или переключатель с подписью справа.
     *
     * @param array<string,mixed> $o
     */
    private function choice(string $type, string $name, array $o): string
    {
        $id = (string) ($o['id'] ?? ($type === 'radio' ? $name . '-' . ($o['value'] ?? '') : $name));
        $role = trim((string) ($o['role'] ?? ''));
        $hint = trim((string) ($o['hint'] ?? ''));

        $input = $this->tag('input', [
            'type'      => $type,
            'class'     => 'form-check-input',
            'id'        => $id,
            'name'      => $name,
            'value'     => (string) ($o['value'] ?? ($type === 'radio' ? '' : '1')),
            'checked'   => !empty($o['checked']) ? true : null,
            'data-hint' => $hint === '' ? null : $hint,
        ] + $this->attributeOptions($o, ['checked', 'value']));

        $label = $type === 'radio' ? 'check' : 'form-check';
        $roleAttr = $role === '' ? '' : ' role="' . $this->e($role) . '"';

        $control = '<label class="' . $label . '" for="' . $this->e($id) . '"' . $roleAttr . '>'
            . $input
            . '<span>' . $this->e($o['label'] ?? '') . '</span>'
            . '</label>';

        return $this->withNote($control, $o);
    }

    /**
     * Подсказка или ошибка под флажком и переключателем.
     *
     * @param array<string,mixed> $o
     */
    private function withNote(string $control, array $o): string
    {
        $error = trim((string) ($o['error'] ?? ''));
        $help = trim((string) ($o['help'] ?? ''));

        if ($error !== '') {
            return $control . $this->fieldError($error);
        }

        return $help === '' ? $control : $control . '<div class="form-help">' . $this->e($help) . '</div>';
    }

    /**
     * Атрибуты поля из одноимённых параметров плюс `attrs`.
     *
     * @param list<string> $skip параметры, уже выведенные или не атрибуты
     * @return array<string,string|bool|null>
     */
    private function attributeOptions(array $o, array $skip = []): array
    {
        $out = [];

        foreach (self::ATTRIBUTES as $attribute) {
            if (in_array($attribute, $skip, true) || !array_key_exists($attribute, $o)) {
                continue;
            }

            $value = $o[$attribute];

            if ($value === null || $value === false || $value === '') {
                continue;
            }

            $out[$attribute] = $value === true ? true : (string) $value;
        }

        $extra = $o['attrs'] ?? null;

        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                if ($value === null || $value === false) {
                    continue;
                }

                $out[(string) $name] = $value === true ? true : (string) $value;
            }
        }

        return $out;
    }

    /**
     * Прочие атрибуты: значение true — само имя, иначе «имя="значение"».
     *
     * @param array<string,mixed>|mixed $extra
     */
    private function extraAttributes(mixed $extra): string
    {
        if (!is_array($extra)) {
            return '';
        }

        $out = '';

        foreach ($extra as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $out .= $value === true
                ? ' ' . $this->e($name)
                : ' ' . $this->e($name) . '="' . $this->e($value) . '"';
        }

        return $out;
    }

    /**
     * Привести список вариантов к единому виду.
     *
     * @param list<array<string,mixed>>|array<string,string> $items
     * @return list<array{value:string,label:string,selected:bool,disabled:bool}>
     */
    private function items(array $items): array
    {
        $result = [];

        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $result[] = [
                    'value'    => (string) ($item['value'] ?? ''),
                    'label'    => (string) ($item['label'] ?? ''),
                    'selected' => (bool) ($item['selected'] ?? false),
                    'disabled' => (bool) ($item['disabled'] ?? false),
                ];
                continue;
            }

            $result[] = [
                'value'    => (string) $key,
                'label'    => (string) $item,
                'selected' => false,
                'disabled' => false,
            ];
        }

        return $result;
    }

    /**
     * Идентификатор для отметки в строке варианта.
     *
     * Случайная часть нужна, потому что строки копируются копированием узла, и
     * одинаковые id у метки и её поля сделали бы подпись привязанной к двум
     * элементам сразу — первый щелчок по «правильный» отмечал бы не ту строку.
     */
    private function uniqueId(string $base): string
    {
        return $base . '-' . substr(hash('crc32b', uniqid('', true)), 0, 8);
    }

    private function e(mixed $value): string
    {
        return $this->view->e($value);
    }
}
