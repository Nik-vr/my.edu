/*
 * Редактор вариативной задачи.
 *
 * Две вещи, которые делает скрипт:
 *
 *   1. Следит за условием и строит таблицу переменных. Автор пишет «{a}» в
 *      тексте, и строка появляется сама. Раньше имена приходилось придумывать
 *      отдельно и вписывать вторым списком: любая опечатка в условии тихо
 *      оставляла пример с местом вместо числа, и это выяснялось уже на работе
 *      у ученика.
 *
 *   2. Переключает столбец значений по выбранному способу: перечисление с
 *      запятой или границы и шаг. Оба набора полей в разметке, виден нужный —
 *      без скриптов форма остаётся рабочей, просто показывает оба.
 *
 * Условие читается из скрытого поля, а не из содержимого редактора: именно в
 * поле уедет текст при отправке, и смотреть надо на него.
 *
 * Окно редактора открывается двумя путями. При перезагрузке страницы с `?edit=`
 * форма лежит в разметке и скрипт запускается сам, когда документ готов. При
 * щелчке по карандашу форму приносит fetch и вставляет через innerHTML — такие
 * скрипты браузер не выполняет, и настройку формы берёт на себя ядро,
 * вызывая `MyEduVariableTask.init()` там же, где настраивает остальные формы
 * (`initEditorForms` в assignment-items.js). Отсюда функция, а не сразу
 * привязки: одна и та же форма могла бы подписаться дважды.
 */
(function () {
    'use strict';

    var VARIABLE_PATTERN = /\{([a-zA-Z][a-zA-Z0-9_]{0,15})\}/g;
    var MAX_VARIABLES = 6;

    /*
     * Плейсхолдер номера строки в образце.
     *
     * Сервер рисует строку-образец с именем `var_N[…]` и идентификаторами
     * `var_N-…`: номера строки на тот момент нет, и он подставляется уже здесь.
     */
    var TaskEditorPrefixPlaceholder = 'var_N';

    /** Форма уже настроена: повторный вызов ничего не подписывает заново. */
    var READY_FLAG = 'data-variable-ready';

    /*
     * К визуальному редактору привязка под отдельной пометкой, а не под той же,
     * что у формы: порядок скриптов в двух способах открытия разный, и форма
     * бывает настроена раньше, чем создан Quill.
     */
    var QUILL_FLAG = 'data-quill-ready';

    /*
     * Скрытое поле условия ищется рядом с редактором, а не по идентификатору:
     * разметку рисует ядро, и имя поля задаёт плагин. Привязка к `prompt-field`
     * работала только потому, что оба плагина так называли это поле, и молча
     * ломалась при переименовании — условие переставало уезжать в форму.
     *
     * Поиск идёт от формы, а не от документа: в окне редактора такая форма
     * одна, но на странице их может быть несколько, и брать чужую — значит
     * строить таблицу переменных по условию соседнего задания.
     */
    function promptField(form) {
        var host = form.querySelector('.ql-editor-host');

        return host && host.parentNode
            ? host.parentNode.querySelector('input[type="hidden"][name]')
            : null;
    }

    /*
     * Имена переменных в порядке появления в условии, без повторов.
     *
     * Порядок важен: он же задаёт порядок строк, поэтому после сохранения
     * таблица не переставляется сама и ученику не меняются номера ответов.
     */
    function namesIn(text) {
        var seen = {};
        var out = [];
        var match;

        VARIABLE_PATTERN.lastIndex = 0;

        while ((match = VARIABLE_PATTERN.exec(text)) !== null) {
            if (!seen[match[1]]) {
                seen[match[1]] = true;
                out.push(match[1]);
            }
        }

        return out;
    }

    function rowName(row) {
        var cell = row.querySelector('.generated-table__name code');
        var match = /\{([^}]+)\}/.exec(cell ? cell.textContent : '');

        return match ? match[1] : '';
    }

    function rowsOf(host) {
        return Array.prototype.slice.call(host.querySelectorAll('[data-generated-row]'));
    }

    /*
     * Свежая строка для новой переменной.
     *
     * Имя и все четыре группы полей создаются здесь целиком: строить строку
     * копированием соседней опасно, копия унаследовала бы его номер в name, и
     * две строки получили бы одинаковое имя — переключатели разных переменных
     * слиплись бы в одну группу, где отметка одной гасила отметку другой.
     */
    /*
     * Новая строка — копия серверной.
     *
     * Раньше разметка строки собиралась здесь строкой HTML, и она отличалась от
     * отрисованной сервером: другие классы, другие подписи. Первая добавленная
     * переменная выглядела иначе, чем остальные, и человек это видел сразу.
     * Теперь образец кладёт на страницу ядро, а скрипт его копирует — и обе
     * строки нарисованы одним кодом.
     */
    function blankRow(key, name) {
        var template = document.querySelector('[data-generated-row-template]');

        if (!template) {
            return '';
        }

        var row = template.content.firstElementChild.cloneNode(true);

        /*
         * В образце имена и идентификаторы собраны от `var_N` — номера строки
         * на момент отрисовки ещё нет. Здесь они заменяются на настоящие, и
         * разметка строки дальше ничем не отличается от серверной.
         */
        row.querySelectorAll('input').forEach(function (field) {
            var current = field.getAttribute('name') || '';

            if (current.indexOf(TaskEditorPrefixPlaceholder) === 0) {
                field.setAttribute('name', key + current.slice(TaskEditorPrefixPlaceholder.length));
            }
        });

        row.querySelectorAll('[id]').forEach(function (node) {
            node.setAttribute('id', node.id.replace(TaskEditorPrefixPlaceholder + '-', key + '-'));
        });

        row.querySelectorAll('label[for]').forEach(function (label) {
            label.setAttribute('for', label.getAttribute('for').replace(TaskEditorPrefixPlaceholder + '-', key + '-'));
        });

        row.querySelector('code').textContent = '{' + name + '}';
        row.querySelector('input[type="hidden"]').value = name;
        row.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            radio.checked = radio.value === 'list';
        });

        applyMode(row);

        return row.outerHTML;
    }

    function applyMode(row) {
        if (!row) {
            return;
        }

        var checked = row.querySelector('input[type="radio"]:checked');
        var mode = checked ? checked.value : 'list';

        row.setAttribute('data-mode', mode);

        row.querySelectorAll('[data-when]').forEach(function (part) {
            part.hidden = part.getAttribute('data-when') !== mode;
        });
    }

    /*
     * Проставляет полям уникальные имена по порядку строк.
     *
     * Имя обязано быть разным у каждой строки: одинаковое имя у переключателей
     * превращает их в одну группу, и отметка одной гасила отметку в другой.
     * Пересобирается всегда, а не только при добавлении.
     */
    function renumber(host) {
        var prefix = host.getAttribute('data-generated-prefix') || 'var_';

        rowsOf(host).forEach(function (row, position) {
            var key = prefix + position;

            row.querySelectorAll('input').forEach(function (field) {
                var name = field.getAttribute('name') || '';
                var cut = name.indexOf('][');

                if (cut !== -1) {
                    field.setAttribute('name', key + name.slice(cut));
                } else if (name.indexOf('[') !== -1) {
                    /*
                     * Имя вида `var_0[values]`. Префикс строки меняется при
                     * перестановке, а хвост — нет, поэтому заменяется только он.
                     */
                    field.setAttribute(
                        'name',
                        key + name.slice(name.indexOf('['))
                    );
                }
            });

            /*
             * Идентификаторы идут по тому же ключу, что и имена.
             *
             * Подпись поля привязана к полю через `for`, и без переноса id
             * после перестановки строк подпись указывала бы на чужое поле:
             * щёлкнув «Шаг» в первой строке, человек отмечал бы поле второй.
             */
            row.querySelectorAll('[id]').forEach(function (node) {
                node.setAttribute('id', node.id.replace(/^var_[^-\s]+-/, key + '-'));
            });

            row.querySelectorAll('label[for]').forEach(function (label) {
                label.setAttribute(
                    'for',
                    label.getAttribute('for').replace(/^var_[^-\s]+-/, key + '-')
                );
            });

            applyMode(row);
        });

        return rowsOf(host).length;
    }

    /** Таблица одной формы приводится в соответствие с её условием. */
    function syncTable(form) {
        var prompt = promptField(form);

        if (!prompt) {
            return;
        }

        var wanted = namesIn(prompt.value);

        form.querySelectorAll('[data-generated-vars]').forEach(function (host) {
            var body = host.querySelector('tbody');
            var prefix = host.getAttribute('data-generated-prefix') || 'var_';

            rowsOf(host).forEach(function (row) {
                if (wanted.indexOf(rowName(row)) === -1) {
                    row.parentNode.removeChild(row);
                }
            });

            var used = rowsOf(host).map(rowName);

            wanted.forEach(function (name) {
                if (used.indexOf(name) !== -1 || rowsOf(host).length >= MAX_VARIABLES || !body) {
                    return;
                }

                var fresh = blankRow(prefix + rowsOf(host).length, name);
                var current = rowsOf(host);
                var last = current[current.length - 1];

                if (last) {
                    last.insertAdjacentHTML('afterend', fresh);
                } else {
                    body.innerHTML = fresh;
                }

                used.push(name);
            });

            var count = renumber(host);
            var empty = host.querySelector('[data-vars-empty]');

            if (empty) {
                empty.hidden = count > 0;
            }

            var table = host.querySelector('table');

            if (table) {
                table.hidden = count === 0;
            }
        });
    }

    /**
     * Настроить форму вариативной задачи.
     *
     * @param {ParentNode} область, где лежит форма: документ либо открытая
     *        вставка формы в модальном окне
     */
    function init(scope) {
        var root = scope || document;

        Array.prototype.slice.call(root.querySelectorAll('[data-generated-vars]'))
            .forEach(function (host) {
                var form = host.closest('form');

                if (!form) {
                    return;
                }

                var prompt = promptField(form);

                if (!prompt) {
                    return;
                }

                /*
                 * Привязки к форме ставятся один раз. Редактор условия —
                 * отдельным шагом и со своей пометкой: скрипт мог запуститься
                 * раньше, чем ядро создало Quill, и тогда привязка остаётся
                 * на следующий вызов — а общий флаг вернул бы её в пустоту.
                 */
                if (!form.hasAttribute(READY_FLAG)) {
                    form.setAttribute(READY_FLAG, '');

                    rowsOf(host).forEach(applyMode);

                    /*
                     * Переключатель «перечислением / диапазоном» живёт внутри
                     * строки, и подпись события вешается на форму: строки
                     * добавляются и убираются сами, и слушатель на каждой из них
                     * протёк бы вместе с ней.
                     */
                    form.addEventListener('change', function (event) {
                        if (event.target.matches('input[type="radio"]')) {
                            applyMode(event.target.closest('[data-generated-row]'));
                        }
                    });

                    form.addEventListener('submit', function () {
                        syncTable(form);
                    });

                    prompt.addEventListener('input', function () {
                        syncTable(form);
                    });
                }

                bindPromptEditor(form, prompt);
                syncTable(form);
            });
    }

    /*
     * Привязка к визуальному редактору — отдельной пометкой, а не той же,
     * что у формы.
     *
     * Порядок скриптов не одинаков в двух способах открытия: при полной
     * загрузке редактор текста настраивается раньше, чем сюда доходит очередь,
     * а при открытии из доски скрипт плагина подключается вместе с формой и
     * может обогнать ядро. Форма при этом уже помечена настроенной, и общий
     * флаг оставил бы редактор без синхронизации: условие набиралось бы, а
     * таблица переменных осталась бы пустой до сохранения.
     */
    function bindPromptEditor(form, prompt) {
        if (form.hasAttribute(QUILL_FLAG)) {
            return;
        }

        var editorHost = form.querySelector('.ql-editor-host');
        var quill = editorHost && window.Quill ? window.Quill.find(editorHost) : null;

        if (!quill) {
            return;
        }

        form.setAttribute(QUILL_FLAG, '');

        /*
         * Quill синхронизирует скрытое поле только перед отправкой формы, а до
         * этого момента оно пустое — и таблица осталась бы пустой до самого
         * сохранения. Поэтому читаем редактор напрямую и держим поле в текущем
         * состоянии: тогда строки появляются сразу, ещё до сохранения.
         */
        quill.on('text-change', function () {
            var text = quill.getText();

            if (prompt.value !== text) {
                prompt.value = text;
                syncTable(form);
            }
        });
    }

    /*
     * Подпись для ядра и собственный запуск.
     *
     * Ядро зовёт `init()` там же, где настраивает остальные формы, — после
     * визуального редактора, то есть когда Quill уже создан. Свой запуск нужен
     * только когда форма лежит в разметке сразу (путь `?edit=`): окно вставляет
     * форму уже после загрузки страницы и зовёт хуки само, а здесь в этот
     * момент инициализация всё равно безвредна — привязки помечены.
     *
     * Готовность документа проверяется по `complete`, а не по `loading`:
     * отложенные скрипты выполняются в состоянии `interactive`, и проверка на
     * `loading` пропустила бы их — форма осталась бы без переключателя режима
     * и без синхронизации условия.
     */
    window.MyEduFormInits = window.MyEduFormInits || [];
    window.MyEduFormInits.push(init);

    if (document.readyState === 'complete') {
        init(document);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})();
