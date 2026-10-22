/* ==========================================================================
   Черновик формы редактора задания.

   Что делает: пока человек правит позицию работы, введённое уходит на сервер
   отдельной строкой и возвращается при следующем открытии. На задание это не
   влияет: «Сохранить» по-прежнему остаётся единственным способом внести правку
   в работу.

   Почему черновик на сервере, а не в браузере: sessionStorage умирал вместе со
   вкладкой. Работа на пять минут могла уйти из-за того, что окно закрылось,
   завис браузер или машина перезагрузилась, — и человек узнавал об этом
   только по исчезнувшему тексту. Теперь черновик переживает и это: он лежит в
   таблице assignment_drafts и приходит обратно при открытии задания.

   Почему это не публикация: строка лежит отдельно от позиции, ученик её не
   видит, а автосохранение не меняет статус работы. Публиковать по-прежнему
   можно только кнопкой — иначе учитель правил бы задание, уже видимое новым
   ученикам, и обещание «правка дойдёт до тех, кто начал» стало бы неверным
   тихо, без спроса.

   Кому принадлежит черновик: тому, кто набирал. У одного задания может быть
   несколько редакторов, и у каждого своя недописанная правка — поэтому сервер
   отдаёт черновик по паре «автор + позиция», и чужий не отдаётся никогда.

   Что не хранится: файлы. Загруженный файл не переживает перезагрузку
   страницы, восстанавливать его неоткуда, и молча оставить поле пустым хуже,
   чем не обещать восстановления.

   Чего модуль не делает: не решает за человека. Черновик возвращается сам и
   говорит об этом сообщением, но кнопка «Отмена» его удаляет: отмена значит
   отмену, иначе правка, от которой человек отказался, вернулась бы при
   следующем открытии.
   ========================================================================== */

(function () {
    'use strict';

    var DEBOUNCE_MS = 700;

    /*
     * Поля, которые в черновик не кладутся: одноразовые и служебные. Токен и
     * номер вопроса относятся к сохранению, а не к правке, и вернуть их к
     * введённому раньше — значит подсунуть форме чужой сохранённый текст.
     */
    var SKIP = ['_token', 'action', 'question_id', '_method'];

    /* Страница уже слушает уход со страницы: второй раз подписываться незачем. */
    var watching = false;

    /*
     * Идёт ли отправка формы.
     *
     * Пока идёт, вопрос об уходе не задаётся: отправка — это и есть
     * сохранение, и спрашивать после нажатия «Сохранить», не потеряются ли
     * данные, нелепо: данные только что сохранены.
     */
    var submitting = false;

    /* Формы, для которых черновик уже взят при открытии. */
    var loaded = [];

    /*
     * Сказано ли уже про истёкшую сессию.
     *
     * Один раз на страницу, а не на каждый запрос: автосохранение идёт раз в
     * 700 миллисекунд, и при истёкшей сессии каждый такой запрос отвечает
     * отказом. Без этого ограничения сообщение об ошибке сыпалось бы поверх
     * правки — и то, что должно было сказать одну причину, превратилось бы в
     * шум.
     */
    var sessionWarned = false;

    /**
     * Сообщить, что сессия закончилась.
     *
     * @param {string} message Что сказать
     */
    function warnSession(message) {
        if (sessionWarned) {
            return;
        }

        sessionWarned = true;

        if (window.myEduToast) {
            window.myEduToast(message, 'error');
        }
    }

    function editorOf(node) {
        return node && node.closest ? node.closest('[data-editor-for]') : null;
    }

    /** Адрес черновика приходит вместе с формой — скрипт его не собирает. */
    function endpoint(form) {
        var editor = editorOf(form);

        return editor ? editor.getAttribute('data-draft-url') || '' : '';
    }

    /** Номер позиции работы: по нему сервер и находит черновик. */
    function taskIdOf(form) {
        var editor = editorOf(form);

        return editor ? editor.getAttribute('data-editor-for') || '' : '';
    }

    function csrfToken(form) {
        var field = form.querySelector('input[name="_token"]');

        return field ? field.value : '';
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

    /** Готова ли пара «визуальный редактор + скрытое поле» к приёму текста. */
    function promptReady(form) {
        return promptValue(form) !== null;
    }

    /**
     * Положить значения обратно в форму.
     *
     * Текст вопроса возвращается отдельно и позже остальных полей: пряталка
     * полей по типу задания отрабатывает по событию change, и вернуть текст
     * раньше значило бы положить его в блок, который вот-вот спрячут.
     *
     * @param {boolean} withPrompt доносить ли текст из визуального редактора
     */
    function restore(form, fields, withPrompt) {
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

        if (!withPrompt) {
            return;
        }

        var prompt = promptValue(form);

        if (prompt !== null && window.MyEduEditor && typeof window.MyEduEditor.setPromptHtml === 'function') {
            window.MyEduEditor.setPromptHtml(form, fields[prompt.name] === undefined ? '' : String(fields[prompt.name]));
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
        var stamp = Date.parse(String(at || '').replace(' ', 'T'));

        if (isNaN(stamp)) {
            return 'недавно';
        }

        var minutes = Math.max(0, Math.round((Date.now() - stamp) / 60000));

        if (minutes < 1) {
            return 'только что';
        }

        if (minutes < 60) {
            return minutes + ' ' + plural(minutes, 'минуту', 'минуты', 'минут') + ' назад';
        }

        var hours = Math.round(minutes / 60);

        return hours + ' ' + plural(hours, 'час', 'часа', 'часов') + ' назад';
    }

    /** Отправить поля на сервер: обычный ответ проверяется, отказ не роняет форму. */
    function post(form, body, done) {
        var url = endpoint(form);

        if (!url || !window.fetch) {
            return false;
        }

        body.append('_token', csrfToken(form));

        fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        }).then(function (response) {
            /*
             * Код ответа разбирается раньше разбора тела.
             *
             * Истёкшая сессия отвечает не данными, а страницей входа: её нельзя
             * разобрать как JSON, и прежняя ветка считала такой ответ успешным —
             * правка помечалась сохранённой, хотя её в базе не было.
             */
            var denied = response.status === 401 || response.status === 403 || response.status === 419;

            if (denied || response.redirected) {
                return { denied: true };
            }

            return response.json().then(function () {
                return { denied: false };
            }).catch(function () {
                return { denied: false };
            });
        }).then(function (result) {
            if (done) {
                done(result.denied !== true);
            }

            if (result.denied) {
                /*
                 * Текст зависит от действия: при сохранении правка не дошла,
                 * а при удалении черновик остался на сервере — и вернётся при
                 * следующем открытии. Одно сообщение на оба случая сказало бы
                 * половину неправды.
                 */
                warnSession(body.get('action') === 'drop'
                    ? 'Сессия закончилась — черновик не удалён. Войдите заново, и он вернётся.'
                    : 'Сессия закончилась — черновик не сохранён. Войдите заново, и он вернётся.');
            }
        }).catch(function () {
            /*
             * Отказ по сети здесь не показывается: страница уже может закрываться.
             * Форма работает и без черновика — это подстраховка, а не условие
             * работы, — и крик об ошибке поверх уходящей вкладки только пугает.
             */
            if (done) {
                done(false);
            }
        });

        return true;
    }

    function save(form) {
        var editor = editorOf(form);

        if (!editor || !endpoint(form)) {
            return;
        }

        var body = new FormData();

        body.append('action', 'save');
        body.append('fields', JSON.stringify(capture(form)));

        post(form, body, function (ok) {
            if (ok) {
                editor.setAttribute('data-draft-state', 'stored');
            }
        });
    }

    /**
     * Убрать черновик.
     *
     * Зовётся по кнопке «Отмена» и после успешного сохранения. Черновик, который
     * пережил сохранение, вернулся бы при следующем открытии задания и выглядел
     * бы откатом уже сделанной правки.
     */
    function drop(form) {
        /*
         * Отложенная запись отменяется первой: правка ставит таймер
         * автосохранения, и сброс, сделанный раньше, чем таймер сработал,
         * без этого черновик удалял, а через долю секунды записывал заново.
         */
        window.clearTimeout(form && form.myEduDraftTimer);

        var editor = editorOf(form);

        if (editor) {
            editor.removeAttribute('data-draft-state');
        }

        if (!form || !endpoint(form)) {
            return;
        }

        var body = new FormData();

        body.append('action', 'drop');

        post(form, body);
    }

    /**
     * Начать запоминать правку.
     *
     * Пишет не на каждое нажатие клавиши, а через паузу после последнего
     * изменения: запись на каждое движение упирается в запрос к серверу, а на
     * длинном вопросе нажатий тысячи. Пауза чуть длиннее, чем была в браузере:
     * запрос идёт по сети, и слишком частые записи ничего не дают, кроме
     * лишней работы обоих.
     */
    function init(root) {
        formsIn(root).forEach(function (form) {
            if (form.getAttribute('data-draft-ready') === '') {
                return;
            }

            form.setAttribute('data-draft-ready', '');

            /*
             * Таймер живёт на форме, а не в замыкании: сбросу черновика
             * нужно уметь отменить его, а сброс зовётся из сохранения и
             * до этого замыкания не дотягивается. Без отмены выходило так:
             * правка ставила таймер, человек нажимал «Сохранить», черновик
             * удалялся — а через долю секунды таймер записывал его заново,
             * и reopen задания возвращал уже сохранённое как черновик.
             */
            function schedule() {
                window.clearTimeout(form.myEduDraftTimer);
                form.myEduDraftTimer = window.setTimeout(function () {
                    save(form);
                }, DEBOUNCE_MS);
            }

            form.addEventListener('input', schedule);
            form.addEventListener('change', schedule);
        });

        /*
         * Уход со страницы при несохранённой правке.
         *
         * Отложенная запись к этому моменту не сработала: закрытие вкладки
         * обрывает таймер, а запрос к серверу при уходе просто не успевает.
         * Поэтому последняя правка уходит отдельным сообщением, которое браузер
         * отправляет сам и на ответ не смотрит.
         */
        if (watching) {
            return;
        }

        watching = true;

        window.addEventListener('pagehide', function () {
            formsIn(document).forEach(function (form) {
                var editor = editorOf(form);

                if (!editor || draftState(editor) !== 'stored') {
                    return;
                }

                var url = endpoint(form);
                var body = new FormData();

                body.append('_token', csrfToken(form));
                body.append('action', 'save');
                body.append('fields', JSON.stringify(capture(form)));

                if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
                    window.navigator.sendBeacon(url, body);
                } else {
                    post(form, body);
                }
            });
        });

        /*
         * Предупреждение об уходе.
         *
         * Только пока окно редактора ОТКРЫТО и в нём есть несохранённая
         * правка. Закрытый редактор — это решение человека, и после него уход
         * со страницы не должен его спрашивать: правка всё равно лежит в
         * черновике на сервере.
         *
         * И никогда — при отправке формы. Отправка и есть сохранение: человек
         * нажал «Сохранить», и спрашивать его, не потеряются ли данные, —
         * глупо, ведь он только что их сохранил. Окно с вопросом появлялось
         * при нажатии «Сохранить» в редакторе работы: уходила со страницы вся
         * форма, а вопрос задавал черновик задания, который к ней отношения не
         * имел.
         */
        window.addEventListener('beforeunload', function (event) {
            if (submitting) {
                return;
            }

            var open = document.querySelectorAll(
                '[data-modal]:not([hidden]) [data-editor-for][data-draft-state]'
            );

            if (open.length === 0) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });

        /*
         * Отправка формы — намерение уйти со страницы.
         *
         * Слушатель на перехвате: он должен увидеть отправку любой формы на
         * странице, даже если та остановит распространение события. Формы
         * редактора и доски заданий отправляют по-разному, и спрашивать надо
         * про любую из них.
         */
        document.addEventListener('submit', function () {
            submitting = true;
        }, true);
    }

    /**
     * Вернуть черновик в открытый редактор.
     *
     * Возвращается сам, а не по вопросу: черновик появляется только когда правка
     * не была сохранена, и вопрос «вернуть ли то, что вы не сохранили» был бы
     * лишним шагом перед работой, которую человек и так собирался продолжить.
     *
     * Теперь это запрос, а не чтение из хранилища, поэтому функция возвращает
     * обещание, а не признак. Пришедшие значения кладутся в форму, как
     * только она готова: визуальный редактор поднимается плагином позже формы,
     * и текст вопроса, положенный раньше, просто потерялся бы.
     *
     * @return {Promise<boolean>} было ли что восстанавливать
     */
    function reopen(root) {
        var forms = formsIn(root);

        if (forms.length === 0) {
            return Promise.resolve(false);
        }

        var form = forms[0];
        var url = endpoint(form);

        loaded.push(form);

        if (!url || !window.fetch) {
            return Promise.resolve(false);
        }

        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        }).then(function (response) {
            /*
             * Код ответа разбирается раньше разбора тела.
             *
             * Истёкшая сессия отвечает не данными, а страницей входа: её нельзя
             * разобрать как JSON, и прежняя ветка считала это «черновика нет».
             * Теперь у отказа есть причина, и человек знает, что делать.
             */
            var denied = response.status === 401 || response.status === 403 || response.status === 419;

            if (denied || response.redirected) {
                return { denied: true, data: null };
            }

            return response.json().then(function (data) {
                return { denied: false, data: data };
            }).catch(function () {
                return { denied: false, data: null };
            });
        }).then(function (result) {
            if (result.denied) {
                warnSession('Сессия закончилась — черновик не восстановлен. Войдите заново, и он вернётся.');

                return false;
            }

            var data = result.data;

            if (!data || data.ok !== true || !data.fields || !Object.keys(data.fields).length) {
                return false;
            }

            return applyWhenReady(form, data, 0);
        }).catch(function () {
            /* Черновик не пришёл — это не поломка: форма открыта и работает. */

            return false;
        });
    }

    /**
     * Дождаться готовности формы и положить значения.
     *
     * Ожидание конечное: если плагин так и не поднял редактор, возвращаться
     * бессмысленно — обычные поля к этому времени уже на месте, теряется только
     * текст вопроса, и его человек напишет заново.
     */
    function applyWhenReady(form, data, attempt) {
        var ready = promptReady(form);
        var promptName = null;
        var fields = data.fields;

        Object.keys(fields).forEach(function (name) {
            if (fields[name] !== null && typeof fields[name] === 'string' && fields[name].indexOf('<') !== -1) {
                promptName = promptName || name;
            }
        });

        if (attempt < 20 && promptName !== null && !ready) {
            return new Promise(function (resolve) {
                window.setTimeout(function () {
                    resolve(applyWhenReady(form, data, attempt + 1));
                }, 100);
            });
        }

        restore(form, fields, ready);

        var editor = editorOf(form);

        if (editor) {
            editor.setAttribute('data-draft-state', 'restored');
        }

        if (window.myEduToast) {
            window.myEduToast(
                'Восстановлен черновик, сохранённый ' + savedAgo(data.at) + '.'
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

        /**
         * Есть ли несохранённая правка в открытом сейчас редакторе.
         *
         * Раньше это был вопрос «есть ли черновик вообще», и его можно было
         * ответить мгновенно, по хранилищу браузера. Теперь черновик лежит на
         * сервере, и мгновенного ответа нет: признак берётся из открытого
         * редактора, а хранилище браузера больше не участвует.
         */
        exists: function () {
            return document.querySelectorAll('[data-editor-for][data-draft-state]').length > 0;
        },

        /** Формы, для которых черновик уже взят: используется при отладке. */
        loadedForms: function () {
            return loaded.slice();
        },
    };
})();