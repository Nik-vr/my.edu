/**
 * Редактор теста: показ полей выбранного типа задания и визуальный редактор
 * текста вопроса.
 *
 * Форма работает и без этого скрипта: все поля есть в разметке, просто
 * показаны все сразу. Скрипт прячет неприменимые, чтобы длинная форма не
 * пугала автора лишними полями.
 *
 * Функция, а не разовая обработка: окно редактора наполняется по данным и
 * открывается без перезагрузки страницы, поэтому форму приходится настраивать
 * заново — на той же странице, где редактор открывают один за другим. Повторная
 * обработка той же формы пропускается: визуальный редактор и обработчики
 * вариантов навесились бы поверх прежних, и добавление варианта отвечало бы
 * дважды.
 */
function myEduEditorInit(root) {
    'use strict';

    var scope = root || document;

    /*
     * Тип задания у редактора задания фиксирован: его выбрали при добавлении,
     * и селектора на странице нет. Отсюда две разные ветки, а не одна с
     * пустым значением: раньше выбор типа был обязателен, и при его отсутствии
     * скрипт выходил целиком — вместе с визуальным редактором и синхронизацией
     * формы, то есть страница выглядела исправной и молча не работала.
     *
     * Искать форму надо по тому, чем она помечена сама, а не по классу `.form`
     * или по селектору типа: форма редактора помечена `data-fixed-type`, и при
     * его отсутствии скрипт выходил до строк с вариантами ответа. Кнопка
     * «Добавить вариант» нажималась и не делала ничего, а страница выглядела
     * исправной.
     */
    var typeSelect = scope.querySelector('#question-type');
    var form = typeSelect
        ? typeSelect.closest('form')
        : (scope.querySelector('form[data-fixed-type]') || scope.querySelector('form.form'));

    if (!form) {
        return;
    }

    if (form.hasAttribute('data-editor-ready')) {
        return;
    }

    form.setAttribute('data-editor-ready', '');

    /** Показать или спрятать блок по текущему типу задания и режиму внутри него. */
    function apply() {
        var type = typeSelect ? typeSelect.value : form.getAttribute('data-fixed-type');
        var show = typeSelect ? function (block) {
            return block.getAttribute('data-type') === type;
        } : function (block) {
            return block.getAttribute('data-type') === type;
        };

        form.querySelectorAll('[data-type]').forEach(function (block) {
            block.hidden = !show(block);
        });

        form.querySelectorAll('[data-when]').forEach(function (field) {
            var modes = field.getAttribute('data-when').split(/\s+/);

            field.hidden = modes.indexOf(currentMode(field)) === -1;
        });
    }

    /**
     * Режим, к которому относится поле: числовой ответ — это exact или range,
     * текстовый — это стратегия проверки.
     */
    function currentMode(field) {
        var container = field.closest('[data-type]');
        var block = container ? container.getAttribute('data-type') : '';

        if (block === 'numeric') {
            var select = form.querySelector('select[name="mode"]');
            return select ? select.value : 'exact';
        }

        if (block === 'text') {
            var strategy = form.querySelector('select[name="strategy"]');
            return strategy ? strategy.value : 'keywords';
        }

        return '';
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', apply);
    }

    form.querySelectorAll('select[name="mode"], select[name="strategy"]').forEach(function (select) {
        select.addEventListener('change', apply);
    });

    apply();

    /*
     * Визуальный редактор текста вопроса.
     *
     * Quill работает в отдельном блоке, а в форму уходит скрытое поле.
     * Синхронизация сделана перед отправкой, а не на каждое нажатие клавиши:
     * при отправке всё равно нужно, а на каждое движение — лишняя работа и
     * лишние перерисовки на длинном вопросе.
     *
     * Пустой Quill обнуляет поле целиком, в том числе <p><br></p>, который он
     * оставляет в пустом документе. Без этой проверки вопрос без текста
     * сохранился бы с невидимым содержимым и прошёл бы проверку «текст есть».
     */
    /*
     * Всё, что касается визуального редактора текста, живёт в этой функции.
     *
     * Раньше это был просто блок кода с `return` внутри IIFE. Возврат выходил
     * не из редактора текста, а из всего скрипта — и варианты ответа ниже не
     * настраивались вовсе: без Quill (отключённая внешняя библиотека,
     * блокировка скриптов, ошибка загрузки) кнопки «Добавить вариант» и
     * крестик удаления просто не появлялись, хотя страница выглядела
     * исправной. Ранний выход должен выходить из своей функции.
     */
    function setupPromptEditor() {
    var host = form.querySelector('.ql-editor-host');

    /*
     * Скрытое поле ищется рядом с редактором, а не по фиксированному
     * `prompt-field`. Разметку редактора рисует ядро (`$ui->editor()`), и имя
     * поля в ней задаёт плагин: жёсткий идентификатор работал только потому,
     * что оба плагина так называли одно и то же поле, а стоило переименовать —
     * и содержимое перестало уезжать в форму молча.
     */
    var field = host ? host.parentNode.querySelector('input[type="hidden"][name]') : null;

    if (!host || !field || typeof window.Quill === 'undefined') {
        // Quill не загрузился: остаётся поле из <noscript>, и это штатная
        // ситуация, а не поломка — текст вводится обычной строкой.
        return;
    }

    var quill = new window.Quill(host, {
        theme: 'snow',
        placeholder: host.getAttribute('data-placeholder') || '',
        modules: {
            toolbar: [
                [{ italic: true }, { bold: true }, { underline: true }, { strike: true }],
                [{ sub: true }, { sup: true }],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link', 'image'],
            ],
        },
    });

    // Quill 2.0 не ставит курсор в конец: без этого текст открылся бы
    // выделенным с начала и первое нажатие заменило бы его.
    quill.setSelection(quill.getLength(), 0);

    /*
     * Экземпляр — на самой форме, а не в замыкании: черновику нужно прочитать
     * то, что человек набрал прямо сейчас, а поле формы для этого не годится.
     * В форму уезжает текст только при отправке (ниже), поэтому введённое
     * лежит в редакторе, а в скрытом поле — сохранённое.
     */
    form.myEduPromptEditor = quill;

    form.addEventListener('submit', function () {
        window.MyEduEditor.syncPrompt(form);
    });

    /*
     * Загрузка картинки прямо в текст.
     *
     * Ответ приходит адресом уже переименованного файла в /uploads/, а
     * содержимое проверяет сервер. Вставляется относительный адрес: полный
     * здесь означал бы домен того места, где вопрос редактировали, и такой
     * вопрос сломался бы при переносе сайта.
     */
    var uploadUrl = host.getAttribute('data-upload-url');

    /*
     * Без адреса загрузки кнопка картинки не делает ничего — и это выглядит
     * как «файл не выбрался» или «браузер не поддерживает». Молчаливый выход
     * здесь стоил одного «ничего не происходит» у человека, который выбирал
     * файл по семидесяти правильным кнопкам.
     */
    function uploadProblem(text) {
        var problem = document.createElement('div');

        problem.className = 'ql-upload-note ql-upload-note--error';
        problem.textContent = text;

        host.parentNode.insertBefore(problem, host.nextSibling);
    }

    if (!uploadUrl) {
        uploadProblem('Загрузка картинок в этом редакторе недоступна.');

        return;
    }

    var picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/png,image/jpeg,image/gif,image/webp';
    picker.hidden = true;

    document.body.appendChild(picker);

    quill.getModule('toolbar').addHandler('image', function () {
        picker.value = '';
        picker.click();
    });

    picker.addEventListener('change', function () {
        var file = picker.files && picker.files[0];

        if (!file) {
            return;
        }

        // CSRF-токен берётся из формы: он там уже есть, и второй запрос за
        // ним не ходил бы зря.
        var tokenField = form.querySelector('input[name="_token"]');
        var data = new FormData();

        data.append('_token', tokenField ? tokenField.value : '');
        data.append('image', file);

        var note = document.createElement('div');
        note.className = 'ql-upload-note';
        note.textContent = 'Загрузка картинки…';

        host.parentNode.insertBefore(note, host.nextSibling);

        fetch(uploadUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(function (response) {
                /*
                 * Отказ разбирается раньше тела.
                 *
                 * Истёкшая сессия отвечает страницей входа, а её нельзя разобрать
                 * как данные: исключение уходило в общую ветку и человек читал
                 * «Картинку загрузить не удалось» — то есть виновником оказывался
                 * браузер, а не истёкшая сессия. Теперь у отказа есть причина.
                 */
                var denied = response.status === 401 || response.status === 403 || response.status === 419;

                if (denied || response.redirected) {
                    return {
                        ok: false,
                        error: denied
                            ? 'Сессия закончилась. Войдите заново — текст вопроса не потерян.'
                            : 'Сервер перенаправил запрос. Войдите заново — текст вопроса не потерян.',
                    };
                }

                return response.json().catch(function () {
                    return { ok: false, error: 'Сервер ответил не данными. Попробуйте ещё раз.' };
                });
            })
            .then(function (result) {
                note.remove();

                if (result.ok && result.url) {
                    var range = quill.getSelection(true);
                    var index = range ? range.index : quill.getLength() - 1;

                    quill.insertEmbed(index, 'image', result.url, 'user');
                    quill.setSelection(index + 1, 0);

                    return;
                }

                note.className = 'ql-upload-note ql-upload-note--error';
                note.textContent = result.error || 'Картинку загрузить не удалось.';
            })
            .catch(function () {
                note.className = 'ql-upload-note ql-upload-note--error';
                note.textContent = 'Картинку загрузить не удалось.';
            });
    });
    }

    setupPromptEditor();

    /*
     * Варианты ответа: строки добавляются и убираются по мере надобности.
     *
     * Раньше в форме было ровно восемь пустых строк, и правильный вариант
     * приходилось ставить, заполняя их подряд. С пропуском строки буквы
     * съезжали, и отметка указывала на чужой вариант — поэтому теперь в
     * отметке стоит номер строки, а сервер переводит его в ключ сам.
     *
     * Номера строк пересобираются после каждого добавления и удаления: иначе
     * строки с одинаковым value отмеченный вариант потерял бы при первом же
     * снятии галочки.
     */
    var OPTION_LIMIT = 16;

    /*
     * Ниже двух строк не удаляем: вариантов ответа должно быть хотя бы два, и
     * об этом говорит сервер при сохранении. Оставить одну строку — значит
     * упереться в отказ, и человек решит, что форма сломалась.
     */
    var OPTION_MINIMUM = 2;

    function renumberOptionRows(rows) {
        rows.forEach(function (row, position) {
            row.setAttribute('data-row-index', String(position));

            var text = row.querySelector('input[type="text"]');

            if (text) {
                text.setAttribute('placeholder', 'Вариант ' + (position + 1));
            }

            var marks = row.querySelectorAll('input[type="radio"], input[type="checkbox"]');

            Array.prototype.forEach.call(marks, function (mark) {
                mark.setAttribute('value', String(position));
            });

            var hidden = row.querySelector('input[type="hidden"]');

            if (hidden) {
                hidden.setAttribute('value', String(position));
            }
        });
    }

    form.querySelectorAll('[data-option-rows]').forEach(function (rows) {
        var block = rows.closest('[data-type]') || rows;

        renumberOptionRows(Array.prototype.slice.call(rows.querySelectorAll('.option-row')));

        block.querySelectorAll('[data-option-add]').forEach(function (button) {
            button.addEventListener('click', function () {
                var current = Array.prototype.slice.call(rows.querySelectorAll('.option-row'));

                if (current.length >= OPTION_LIMIT) {
                    return;
                }

                var last = current[current.length - 1];

                if (!last) {
                    return;
                }

                var row = last.cloneNode(true);

                var text = row.querySelector('input[type="text"]');

                if (text) {
                    text.value = '';
                }

                /*
                 * У отметки остаётся то же имя, что у остальных строк блока:
                 * одинаковое имя — это и есть группа переключателей, и снимать
                 * его нельзя. Раньше здесь имя убиралось и задавалось заново,
                 * причём не тем (`correct` вместо `correct_single`): новая
                 * строка выглядела обычной, но правильным её нельзя было
                 * пометить — сервер такого поля не читает.
                 */
                if (rows.getAttribute('data-option-kind') === 'single') {
                    row.querySelectorAll('input[type="radio"]').forEach(function (radio) {
                        radio.checked = false;
                    });
                } else {
                    row.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                        box.checked = false;
                    });
                }

                /*
                 * Строка добавляется в контейнер строк, а не сама в себя.
                 * Прежде здесь стояло row.appendChild(row): узел, вставляемый
                 * в самого себя, бросает HierarchyRequestError, обработчик
                 * на этом обрывался, и кнопка выглядела работающей, не
                 * добавляя ничего. Ровно тот симптом и видели: вариантов
                 * оставалось столько же, сколько было в форме изначально.
                 */
                rows.appendChild(row);
                renumberOptionRows(Array.prototype.slice.call(rows.querySelectorAll('.option-row')));

                var field = row.querySelector('input[type="text"]');

                if (field) {
                    field.focus();
                }
            });
        });

        /*
         * Удаление строки крестиком.
         *
         * Номера строк пересобираются после удаления, поэтому отметка
         * «правильный» остаётся на своём варианте, а не на соседнем: без
         * пересборки сдвинувшийся набор значений указал бы на другой вариант,
         * и это выглядело бы как «правильный ответ съехал».
         *
         * Слушатель ставится на контейнер, а не на каждую кнопку: строки
         * добавляются и удаляются, и обработчик на кнопке перестал бы
         * доставать до новых.
         */
        rows.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('[data-option-drop]') : null;

            if (!trigger || !rows.contains(trigger)) {
                return;
            }

            var current = Array.prototype.slice.call(rows.querySelectorAll('.option-row'));

            if (current.length <= OPTION_MINIMUM) {
                return;
            }

            trigger.closest('.option-row').parentNode.removeChild(trigger.closest('.option-row'));
            renumberOptionRows(Array.prototype.slice.call(rows.querySelectorAll('.option-row')));
        });
    });
}

/*
 * Точка входа для других скриптов.
 *
 * Имя функции и объект с ней — одно и то же намерение с двух сторон: скрипт сам
 * настраивает формы, пришедшие с сервера, а вызывающий код не знает, ни как
 * зовётся функция, ни что внутри неё происходит.
 *
 * `promptHtml` и `setPromptHtml` — чтение и запись того, что человек набрал
 * сейчас, для черновика. Через скрытое поле не годится: оно обновляется только
 * при отправке и хранит сохранённое, а не введённое.
 */
window.MyEduEditor = {
    init: myEduEditorInit,

    /**
     * Положить набранный текст в скрытое поле формы.
     *
     * В запрос уезжает скрытое поле, а не сам редактор, и до отправки в нём
     * лежит сохранённый текст, а не набранный. Тот, кто собирает запрос,
     * обязан позвать это ДО чтения формы: иначе текст уйдёт пустым, и сервер
     * скажет «введите текст задания», хотя он стоит на экране.
     *
     * @param {HTMLFormElement} form
     * @return {boolean} есть ли что класть
     */
    syncPrompt: function (form) {
        var quill = form && form.myEduPromptEditor;

        if (!quill) {
            return false;
        }

        var host = form.querySelector('.ql-editor-host');

        if (!host || !host.parentNode) {
            return false;
        }

        var field = host.parentNode.querySelector('input[type="hidden"][name]');

        if (!field) {
            return false;
        }

        var text = quill.getText().trim();

        field.value = text === '' ? '' : quill.root.innerHTML;

        return true;
    },

    /**
     * Текст вопроса как он есть в редакторе, без синхронизации с формой.
     *
     * @param {HTMLFormElement} form
     * @return {string|null} null — визуального редактора нет
     */
    promptHtml: function (form) {
        var quill = form && form.myEduPromptEditor;

        if (!quill) {
            return null;
        }

        // Пустой документ Quill оставляет <p><br></p>: он выглядит как
        // невидимый текст и в черновик попал бы вместе с мусором.
        return quill.getText().trim() === '' ? '' : quill.root.innerHTML;
    },

    /**
     * Положить текст вопроса в редактор.
     *
     * @param {HTMLFormElement} form
     * @param {string} html
     */
    setPromptHtml: function (form, html) {
        var quill = form && form.myEduPromptEditor;

        if (!quill || typeof html !== 'string') {
            return;
        }

        if (html === '') {
            quill.setText('');

            return;
        }

        quill.clipboard.dangerouslyPasteHTML(html);
    },
};

myEduEditorInit(document);
