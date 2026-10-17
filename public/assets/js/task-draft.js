/* ==========================================================================
   Черновик формы редактора задания.

   Что делает: пока человек правит задание, введённое лежит в браузере, а не
   на сервере. На сервер оно уходит только по кнопке «Сохранить» — ни раньше,
   ни чаще.

   Почему черновик в браузере, а не автосохранение на сервере: редактор пишет
   прямо в позицию работы, а для опубликованной работы правка заданий не доходит
   до тех, кто уже начал её проходить. Автосохранение каждые полминуты сделало бы
   это обещание неверным тихо, без спроса, и человек не узнал бы, что работу уже
   видят новые ученики. Черновик ничего не публикует: это подстраховка от
   закрытой вкладки, а не способ сохранить.

   Где хранится: sessionStorage, а не localStorage. Черновик держится в пределах
   вкладки — на общем компьютере после закрытия не остаётся ничего, что мог бы
   увидеть следующий человек, — и переживает перезагрузку страницы и аварийное
   закрытие вкладки, ради чего он и нужен.

   Что не хранится: файлы. Загруженный файл не переживает перезагрузку страницы,
   восстанавливать его неоткуда, и молча оставить поле пустым хуже, чем не
   обещать восстановления.

   Чего модуль не делает: не решает за человека. Черновик возвращается сам и
   говорит об этом сообщением, но кнопка «Отмена» его удаляет: отмена значит
   отмену, иначе правка, от которой человек отказался, вернулась бы при
   следующем открытии.
   ========================================================================== */

(function () {
    'use strict';

    var PREFIX = 'myedu-task-draft:';
    var DEBOUNCE_MS = 500;

    /*
     * Поля, которые в черновик не кладутся: одноразовые и служебные. Токен и
     * номер вопроса относятся к сохранению, а не к правке, и вернуть их к
     * введённому раньше — значит подсунуть форме чужой сохранённый текст.
     */
    var SKIP = ['_token', 'action', 'question_id', '_method'];

    var store = null;

    /* Страница уже слушает уход со страницы: второй раз подписываться незачем. */
    var watching = false;

    /**
     * Хранилище или null, если им нельзя пользоваться.
     *
     * Проверяется не только наличие: в приватном режиме некоторых браузеров
     * sessionStorage есть, а писать в него нельзя — обращение бросает
     * исключение. Черновик удобство, а не условие работы формы, и его отсутствие
     * не должно мешать сохранять.
     */
    function storage() {
        if (store !== null) {
            return store;
        }

        try {
            var probe = PREFIX + 'probe';
            var candidate = window.sessionStorage;

            candidate.setItem(probe, '1');
            candidate.removeItem(probe);

            store = candidate;
        } catch (e) {
            store = false;
        }

        return store;
    }

    function draftKey(taskId) {
        return PREFIX + String(taskId || '');
    }

    /** Задание, которому принадлежит форма: окно хранит номер позиции работы. */
    function taskIdOf(form) {
        var host = form.closest ? form.closest('[data-editor-for]') : null;

        return host ? host.getAttribute('data-editor-for') || '' : '';
    }

    function draftState(editor) {
        return editor ? editor.getAttribute('data-draft-state') : null;
    }

    function formsIn(root) {
        var scope = root || document;

        return Array.prototype.slice.call(scope.querySelectorAll('form[data-ajax-save]'))
            .filter(function (form) {
                return form.closest('[data-editor-for]') !== null;
            });
    }

    function isMulti(form, name) {
        return form.querySelectorAll('[name="' + name + '"]').length > 1;
    }

    /** Снимок формы: значения по именам, без файлов и служебных полей. */
    function capture(form) {
        var fields = {};

        Array.prototype.slice.call(form.elements).forEach(function (node) {
            var name = node.name || '';

            if (!name || node.disabled || SKIP.indexOf(name) !== -1) {
                return;
            }

            if (node.type === 'file' || node.type === 'submit' || node.type === 'button') {
                return;
            }

            if (node.type === 'checkbox' || node.type === 'radio') {
                // Отметка хранится флагом, а не значением: у неотмеченной
                // галочки значения нет вовсе, и восстанавливать было бы нечего.
                // У одноимённых галочек позиция в списке значит всё, поэтому
                // неотмеченная кладётся на своё место значением null.
                if (isMulti(form, name)) {
                    fields[name] = fields[name] || [];
                    fields[name].push(node.checked ? node.value : null);
                } else {
                    fields[name] = node.checked;
                }

                return;
            }

            if (isMulti(form, name)) {
                fields[name] = fields[name] || [];
                fields[name].push(node.value);

                return;
            }

            fields[name] = node.value;
        });

        /*
         * Текст вопроса снимается отдельно: он лежит в визуальном редакторе, а в
         * скрытое поле уезжает только при отправке. Без этого в черновик попал бы
         * сохранённый текст, а не введённый, и восстановление выглядело бы как
         * откат правки.
         */
        var prompt = promptValue(form);

        if (prompt !== null) {
            fields[prompt.name] = prompt.html;
        }

        return fields;
    }

    /** Имя поля вопроса и то, что сейчас в редакторе. */
    function promptValue(form) {
        if (!window.MyEduEditor || typeof window.MyEduEditor.promptHtml !== 'function') {
            return null;
        }

        var host = form.querySelector('.ql-editor-host');

        if (!host || !host.parentNode) {
            return null;
        }

        var field = host.parentNode.querySelector('input[type="hidden"][name]');
        var html = window.MyEduEditor.promptHtml(form);

        if (!field || !field.name || html === null) {
            return null;
        }

        return { name: field.name, html: html };
    }

    /** Положить значения обратно в форму. */
    function restore(form, fields) {
        Object.keys(fields).forEach(function (name) {
            var value = fields[name];

            Array.prototype.slice.call(form.querySelectorAll('[name="' + name + '"]')).forEach(function (node, index) {
                if (node.type === 'checkbox' || node.type === 'radio') {
                    node.checked = Array.isArray(value)
                        ? value.indexOf(node.value) !== -1
                        : (value === true || node.value === String(value));

                    return;
                }

                // У одноимённых полей значение своё у каждого: варианты ответа
                // приходят списком и по индексу, а не по совпадению.
                if (Array.isArray(value)) {
                    if (typeof value[index] === 'string') {
                        node.value = value[index];
                    }

                    return;
                }

                if (typeof value === 'string') {
                    node.value = value;
                }
            });
        });

        /*
         * Вопрос возвращается после остальных полей. Пряталка полей по типу
         * задания отрабатывает по событию change, и вернуть текст раньше значило
         * бы положить его в блок, который вот-вот спрячут.
         */
        var prompt = promptValue(form);

        if (prompt !== null && window.MyEduEditor && typeof window.MyEduEditor.setPromptHtml === 'function') {
            window.MyEduEditor.setPromptHtml(form, fields[prompt.name] === undefined ? '' : String(fields[prompt.name]));
        }
    }

    function read(taskId) {
        var place = storage();

        if (!place || !taskId) {
            return null;
        }

        var raw = place.getItem(draftKey(taskId));

        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);

            if (!parsed || typeof parsed !== 'object' || !parsed.fields) {
                place.removeItem(draftKey(taskId));

                return null;
            }

            return parsed;
        } catch (e) {
            // Битый черновик не должен ломать открытие задания.
            place.removeItem(draftKey(taskId));

            return null;
        }
    }

    function drop(form) {
        var place = storage();
        var taskId = taskIdOf(form);

        if (place && taskId) {
            place.removeItem(draftKey(taskId));
        }

        var editor = form.closest('[data-editor-for]');

        if (editor) {
            editor.removeAttribute('data-draft-state');
        }
    }

    function save(form) {
        var place = storage();
        var taskId = taskIdOf(form);

        if (!place || !taskId) {
            return;
        }

        var editor = form.closest('[data-editor-for]');

        try {
            place.setItem(draftKey(taskId), JSON.stringify({
                at: Date.now(),
                fields: capture(form),
            }));

            if (editor) {
                editor.setAttribute('data-draft-state', 'stored');
            }
        } catch (e) {
            // Хранилище переполнено или запрещено: форма работает и без черновика.
        }
    }

    function plural(n, one, few, many) {
        var mod10 = n % 10;
        var mod100 = n % 100;

        if (mod10 === 1 && mod100 !== 11) {
            return one;
        }

        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return few;
        }

        return many;
    }

    /** «3 минуты назад», «час назад» — человек понимает, насколько черновик свеж. */
    function savedAgo(at) {
        var minutes = Math.max(0, Math.round((Date.now() - Number(at || Date.now())) / 60000));

        if (minutes < 1) {
            return 'только что';
        }

        if (minutes < 60) {
            return minutes + ' ' + plural(minutes, 'минуту', 'минуты', 'минут') + ' назад';
        }

        var hours = Math.round(minutes / 60);

        return hours + ' ' + plural(hours, 'час', 'часа', 'часов') + ' назад';
    }

    /**
     * Начать запоминать правку.
     *
     * Пишет не на каждое нажатие клавиши, а через полсекунды после последнего
     * изменения: запись на каждое движение упирается в запись хранилища, а на
     * длинном вопросе нажатий тысячи.
     */
    function init(root) {
        var place = storage();

        if (!place) {
            return;
        }

        formsIn(root).forEach(function (form) {
            if (form.getAttribute('data-draft-ready') === '') {
                return;
            }

            form.setAttribute('data-draft-ready', '');

            var timer = null;

            form.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    save(form);
                }, DEBOUNCE_MS);
            });

            form.addEventListener('change', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    save(form);
                }, DEBOUNCE_MS);
            });
        });

        /*
         * Уход со страницы при несохранённой правке.
         *
         * Отложенная запись к этому моменту не сработала: закрытие вкладки
         * обрывает таймер. Поэтому пишется прямо здесь — иначе теряются
         * последние полсекунды, то есть ровно то, что человек набирал в момент,
         * когда нажал закрыть.
         *
         * Один обработчик на страницу, а не по одному на форму: формы приходят и
         * уходят вместе с окном, и слушатель на каждой копии остался бы висеть
         * после закрытия.
         */
        if (watching) {
            return;
        }

        watching = true;

        window.addEventListener('pagehide', function () {
            formsIn(document).forEach(function (form) {
                if (draftState(form.closest('[data-editor-for]')) === 'stored') {
                    save(form);
                }
            });
        });

        /*
         * Предупреждение об уходе.
         *
         * Только пока окно редактора открыто и в нём есть несохранённая правка:
         * закрытый редактор — это решение человека, и после него уход со
         * страницы не должен его спрашивать. Черновик при этом остаётся и
         * вернётся при следующем открытии задания.
         */
        window.addEventListener('beforeunload', function (event) {
            var open = document.querySelectorAll('[data-editor-for][data-draft-state]');

            if (open.length === 0) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });
    }

    /**
     * Вернуть черновик в открытый редактор.
     *
     * Возвращается сам, а не по вопросу: черновик появляется только когда правка
     * не была сохранена, и вопрос «вернуть ли то, что вы не сохранили» был бы
     * лишним шагом перед работой, которую человек и так собирался продолжить.
     *
     * @return {boolean} было ли что восстанавливать
     */
    function reopen(root) {
        var forms = formsIn(root);

        if (forms.length === 0) {
            return false;
        }

        var form = forms[0];
        var draft = read(taskIdOf(form));

        if (!draft) {
            return false;
        }

        restore(form, draft.fields);

        var editor = form.closest('[data-editor-for]');

        if (editor) {
            editor.setAttribute('data-draft-state', 'restored');
        }

        if (window.myEduToast) {
            window.myEduToast(
                'Восстановлен черновик, сохранённый ' + savedAgo(draft.at) + '.'
                + ' Если он не нужен — нажмите «Отмена».',
                'success'
            );
        }

        return true;
    }

    window.MyEduTaskDraft = {
        init: init,
        reopen: reopen,
        drop: drop,

        /** Есть ли несохранённая правка — у этой задачи или у любой другой. */
        exists: function (form) {
            if (form) {
                return read(taskIdOf(form)) !== null;
            }

            var place = storage();

            if (!place) {
                return false;
            }

            for (var i = 0; i < place.length; i++) {
                var key = place.key(i);

                if (key && key.indexOf(PREFIX) === 0) {
                    return true;
                }
            }

            return false;
        },
    };
})();