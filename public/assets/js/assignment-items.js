/* ==========================================================================
   Доска заданий работы: перетаскивание строк и карточек, добавление из
   палитры, отмена правки и сохранение без перезагрузки.

   Живёт в файле, а не в разметке: так страницу можно отдавать под
   Content-Security-Policy без unsafe-inline и nonce.

   Порядок и перестановка отправляются сразу после того, как элемент отпущен:
   отдельная кнопка «сохранить» заставила бы человека подтверждать очевидное
   действие, а без неё доска не двигалась бы вовсе там, где скрипты запрещены,
   — поэтому перемещение дублируется кнопками со стрелками.
   ========================================================================== */

(function () {
    'use strict';

    /*
     * Отмена правки задания спрашивает подтверждение и уходит по адресу без
     * отправки формы.
     *
     * Это обычная ссылка, а не кнопка отправки: без JavaScript она всё равно
     * работает и просто закрывает редактор. Подтверждение — надстройка, а не
     * условие сохранности: форма на «Отмена» не уходит никогда, поэтому
     * потерять правку скриптом невозможно даже при отказе подтверждения.
     */
    function bindCancel(root) {
        (root || document).querySelectorAll('[data-editor-cancel]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var message = link.getAttribute('data-editor-cancel-message');

                if (!message) {
                    /*
                     * Сценарий без подтверждения: ссылка ведёт на адрес редактора
                     * без открытого задания, и страница переходит туда. Подменять
                     * такой переход закрытием окна нельзя: без скриптов это
                     * единственный способ закрыть редактор, и без перезагрузки
                     * форма с введённым ушла бы вместе с половиной работы.
                     */

                    return;
                }

                event.preventDefault();

                askCancel(message, link);
            });
        });
    }

    /*
     * Спросить окном сайта и закрыть редактор, только если ответ «да».
     *
     * Раньше здесь стоял window.confirm: вопрос выскакивал поверх сайта в виде
     * окна браузера, а из-за него нельзя было ни отменить правку, ни уйти со
     * страницы, не нажав «Ок».
     */
    function askCancel(message, link) {
        if (!window.MyEduConfirm) {
            closeWithDraft(link);

            return;
        }

        window.MyEduConfirm.open({
            title: 'Отменить правку?',
            body: message,
            confirm: 'Отменить правку',
            danger: false
        }).then(function (yes) {
            if (yes) {
                closeWithDraft(link);
            }
        });
    }

    function closeWithDraft(link) {
        /*
         * Отмена удаляет черновик: человек сказал, что эта правка ему не нужна,
         * и вернуть её при следующем открытии значило бы спорить с ним дважды.
         * Закрытие крестиком черновик, наоборот, оставляет — там человек просто
         * отвлёкся, а не отказался.
         */
        if (window.MyEduTaskDraft) {
            window.MyEduTaskDraft.drop(link.closest('form'));
        }

        closeEditor(link.closest('.task-editor') || link);
    }

    /* ---------------------------------------------------------------- сохранение */

    /**
     * Показать, в каком поле автор ошибся.
     *
     * Сервер возвращает имя поля вместе с сообщением, и без отметки человек
     * читает «отметьте правильный вариант» и обходит форму заново: где именно
     * отметка, по тексту не понять — вариантов может быть несколько, а
     * правильный у каждого свой. Отмечаются все поля с таким именем: у
     * вариантов ответа их несколько, и ошибка в их числе — ошибка всех.
     *
     * Прежняя отметка снимается всегда, иначе исправленное поле осталось бы
     * красным до следующей неудачной попытки.
     */
    function markField(editor, name) {
        editor.querySelectorAll('.is-invalid').forEach(function (node) {
            node.classList.remove('is-invalid');
            node.removeAttribute('aria-invalid');
        });

        if (!name) {
            return;
        }

        var fields = editor.querySelectorAll('[name="' + name + '"]');

        /*
         * Имя поля приходит от сервера по образцу формы, а в форме у полей
         * массивов оно с хвостовыми скобками: `option_text[]`. Проверяются оба
         * написания — иначе опечатка в одном имени означала бы молчаливую
         * отмену всей отметки, и пропала бы именно там, где нужнее всего.
         */
        if (!fields.length && name.slice(-2) !== '[]') {
            fields = editor.querySelectorAll('[name="' + name + '[]"]');
        }

        if (!fields.length) {
            return;
        }

        fields.forEach(function (field) {
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
        });

        fields[0].focus({ preventScroll: false });
    }

    /**
     * Сказать автору, чем закончилось сохранение.
     *
     * Успех — всплывающим сообщением: редактор после него закрывается, и строка
     * уехала бы вместе с ним — человек остался бы с молчанием и решил бы, что
     * кнопка не нажалась.
     *
     * Отказ — один раз, и в редакторе. Раньше он печатался и тостом, и строкой:
     * тост гаснет сам, строка остаётся, и сообщение об отказе попадалось дважды
     * — второй раз, пока человек ещё не понял, что сохранить не вышло.
     */
    function notify(editor, message, bad, field) {
        if (!bad) {
            if (window.myEduToast) {
                window.myEduToast(message, 'success');
            }

            return;
        }

        var box = editor.querySelector('[data-ajax-status]');

        if (!box) {
            box = document.createElement('p');
            box.setAttribute('data-ajax-status', '');
            box.setAttribute('role', 'alert');
            editor.insertBefore(box, editor.firstChild);
        }

        box.className = 'banner banner-danger';
        box.textContent = message;

        markField(editor, field);
    }

    function renameTask(editor, preview) {
        if (preview === null || preview === undefined) {
            return;
        }

        var id = editor.getAttribute('data-editor-for');
        var card = document.querySelector('.work-card[data-item-id="' + id + '"]');

        if (!card) {
            return;
        }

        var link = card.querySelector('[data-task-title]');

        if (link) {
            link.textContent = preview;
        }
    }

    /**
     * Сохранение редактора без перезагрузки страницы.
     *
     * Ответ читается как данные, а не как страница: сервер присылает сообщение
     * и заголовок. Заголовок подставляется в карточку доски сам — иначе
     * переименованное задание осталось бы со старым названием до ручного F5,
     * и правка выглядела бы несохранившейся.
     *
     * Если сервер ответил не данными (сеть отвалилась, упала сессия),
     * форма отправляется обычным способом: страница перезагрузится, но правка
     * не потеряется — это надёжнее, чем молча показать «не сохранено».
     */
    /**
     * Адрес, куда уходит форма.
     *
     * Через `getAttribute`, а не через свойство `form.action`: в форме задания
     * есть скрытое поле с именем `action`, а именованные элементы формы
     * перекрывают её свойства. `form.action` отдаёт тот самый input — то есть
     * строку `[object HTMLInputElement]`, — и запрос уходил на 404 страницу
     * вместо сохранения. Ответ приходил HTML, разобрать его как данные было
     * нельзя, и скрипт откатывался к обычной отправке: страница
     * перезагружалась, введённое терялось, а ни подсветки полей, ни сообщения
     * об ошибке не появлялось — всё выглядело как «кнопка не работает».
     */
    function formAction(form) {
        return form.getAttribute('action') || '';
    }

    function bindAjaxSave(root) {
        (root || document).querySelectorAll('form[data-ajax-save]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var editor = form.closest('.task-editor');

                if (!editor || !window.fetch) {
                    return;
                }

                event.preventDefault();

                var submitter = event.submitter;
                var button = submitter && submitter.matches('button[type="submit"]') ? submitter : null;

                if (button) {
                    button.disabled = true;
                }

                fetch(formAction(form), {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                }).then(function (response) {
                    /*
                     * Код ответа разбирается раньше разбора тела.
                     *
                     * Истёкшая сессия отвечает не данными, а страницей входа или
                     * страницей ошибки: разобрать её как данные нельзя, и старая
                     * ветка отдавала форму обычным способом. То есть при отказе по
                     * сессии правка уходила повторно на адрес, где её уже не ждали,
                     * и человек видел страницу ошибки вместо своего текста —
                     * молча потеряв всё введённое. Здесь такой ответ называют
                     * словами и оставляют форму на месте.
                     */
                    var denied = response.status === 401 || response.status === 403 || response.status === 419;

                    if (denied || response.redirected) {
                        return { denied: true, data: { ok: false, message: denied
                            ? 'Сессия закончилась. Войдите заново — введённое лежит в черновике и вернётся.'
                            : 'Сервер перенаправил запрос. Сессия закончилась — войдите заново.' } };
                    }

                    return response.json().then(function (data) {
                        return { denied: false, data: data };
                    }).catch(function () {
                        // Ответ не JSON и не отказ: отдаём форму обычным способом.
                        form.submit();

                        return { denied: false, data: null };
                    });
                }).then(function (result) {
                    var data = result.data;

                    if (data === null) {
                        return;
                    }

                    if (result.denied) {
                        if (button) {
                            button.disabled = false;
                        }

                        notify(editor, data.message, true);

                        return;
                    }

                    notify(editor, data.message || (data.ok ? 'Сохранено' : 'Не сохранено'), !data.ok, data.field);

                    if (!data.ok) {
                        if (button) {
                            button.disabled = false;
                        }

                        return;
                    }

                    renameTask(editor, data.preview);

                    /*
                     * Сервер принял правку — черновик ей больше не нужен. Пока он
                     * лежит, следующее открытие задания вернуло бы только что
                     * сохранённое и сказало бы, что это черновик.
                     */
                    if (window.MyEduTaskDraft) {
                        window.MyEduTaskDraft.drop(form);
                    }

                    if (data.close) {
                        closeEditor(editor);

                        // Редактор закрыт, но его кнопка «Редактировать» в
                        // карточке уже не знает, что задание открыто.
                        // Адрес без edit= обновляется на месте: без него
                        // F5 вернул бы уже закрытый редактор.
                        if (data.url) {
                            window.history.replaceState(null, '', data.url);
                        }
                    } else if (button) {
                        button.disabled = false;
                    }
                }).catch(function () {
                    // Сеть не ответила: обычная отправка сохранит правку.
                    form.submit();
                });
            });
        });
    }

/**
     * Закрыть редактор задания вместе с окном, в котором он открыт.
     *
     * Закрытие идёт через ту же кнопку, которой окно закрывает человек: снимает
     * и подложку, и «открытость» окна, и возвращает фокус. Свою копию этого
     * правила здесь заводить нельзя — подложка тогда осталась бы висеть поверх
     * доски после сохранения.
     *
     * Адрес очищается здесь же. Окно открывается сервером по `?edit=`, и если
     * этот параметр остаётся в адресной строке, то любая перезагрузка — F5,
     * Ctrl+F5, возврат по истории — снова отрисует редактор, хотя человек его
     * закрыл. Выглядит как «окно открылось само», и повторяется не всегда: пока
     * в адресе параметр был, страница вела себя ожидаемо.
     */
    function closeEditor(editor) {
        var modal = editor.closest('[data-modal]');

        forgetOpenTask();

        if (!modal) {
            editor.remove();

            return;
        }

        var closer = modal.querySelector('[data-modal-close]');

        if (closer) {
            closer.click();

            return;
        }

        modal.hidden = true;
    }

    /**
     * Убрать `?edit=` из адресной строки, ничего не перезагружая.
     *
     * Подменой истории, а не переходом: перезагрузка снесла бы введённое, а
     * человеку пришлось бы снова открывать то, что он уже закрыл.
     */
    function forgetOpenTask() {
        if (!window.history || !window.history.replaceState) {
            return;
        }

        var url = new URL(window.location.href);

        if (!url.searchParams.has('edit')) {
            return;
        }

        url.searchParams.delete('edit');
        window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    }

    /**
     * Следить за закрытием окна редактора, каким бы способом оно ни шло.
     *
     * Крестик, Escape и щелчок по подложке обрабатывает modal-open.js, и он про
     * `?edit=` ничего не знает: окно он закрывает, а параметр в адресе остаётся.
     * Дальше любая перезагрузка — F5, Ctrl+F5, возврат по истории — снова
     * отрисовывает редактор, который человек только что закрыл.
     *
     * Наблюдение за атрибутом `hidden`, а не свои обработчики на каждый способ
     * закрытия: путей больше, и новый появится вместе с правкой того окна.
     */
    function watchEditorClosing() {
        /*
         * Именно окно редактора, а не первое окно на странице.
         *
         * На странице редактора работы окон два: окно препятствий публикации
         * стоит выше по разметке, и окно редактора задания — ниже. Поиск по
         * общему признаку брал первое, то есть чужое: закрытие редактора не
         * замечалось, `?edit=` оставался в адресе, и любая перезагрузка снова
         * открывала редактор, который человек только что закрыл.
         */
        var modal = document.querySelector('[data-editor-shell]');

        if (!modal) {
            modal = document.querySelector('[data-modal]');
        }

        if (!modal || !window.MutationObserver) {
            return;
        }

        new MutationObserver(function () {
            if (modal.hidden) {
                forgetOpenTask();
            }
        }).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
    }

    /* ------------------------------------------------- открытие без перезагрузки */

    /*
     * Настроить форму редактора, где бы она ни появилась.
     *
     * Форма приходит двумя путями: с сервера вместе со страницей (без скриптов
     * и при переходе по `?edit=`) и по данным, когда окно открывает скрипт.
     * Привязки навешиваются здесь для обоих случаев, иначе подставленная форма
     * осталась бы без сохранения без перезагрузки и без проверки полей — то
     * есть выглядела бы исправной и молча не работала.
     */
    function initEditorForms(root) {
        bindCancel(root);
        bindAjaxSave(root);

        /*
         * Черновик подключается после привязок, а не раньше: он снимает форму
         * такой, какой её сделали эти привязки, иначе первое же сохранение
         * очистило бы черновик, которого человек не создавал.
         */
        if (window.MyEduTaskDraft) {
            window.MyEduTaskDraft.init(root);
        }

        // Чужие модули: визуальный редактор текста и проверка полей.
        if (window.MyEduEditor) {
            window.MyEduEditor.init(root);
        }

        if (window.MyEduValidate) {
            window.MyEduValidate.init();
        }
    }

    /**
     * Открыть редактор задания, не перезагружая страницу.
     *
     * Раньше карандаш был обычной ссылкой, и окно открывалось переходом по
     * адресу: страница перезагружалась целиком и место, где автор остановился на
     * доске, терялось. Он возвращался к началу списка и прокручивал его заново,
     * а после закрытия окна так и оставался в начале — выглядело так, будто
     * закрытие уводило страницу наверх.
     *
     * Здесь приходит только разметка формы, страница остаётся на месте вместе с
     * прокруткой. Ссылка при этом остаётся ссылкой: без скриптов переход по
     * адресу по-прежнему открывает то же окно, поэтому обработчик лишь
     * перехватывает щелчок.
     */
    function bindEditorOpen() {
        var modal = document.querySelector('[data-editor-shell]');

        if (!modal || !window.fetch) {
            return;
        }

        var body = modal.querySelector('[data-editor-body]');
        var title = modal.querySelector('[data-editor-title]');
        var endpoint = modal.getAttribute('data-editor-url') || '';

        if (!body || !title || endpoint === '') {
            return;
        }

        document.querySelectorAll('[data-editor-open]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                // Несколько слов, один знак вопроса, ctrl и средняя кнопка:
                // открыть в новой вкладке должен адрес из ссылки, а не окно.
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                    return;
                }

                event.preventDefault();

                var taskId = link.getAttribute('data-task-id') || '';

                if (taskId === '') {
                    return;
                }

                link.setAttribute('aria-busy', 'true');

                fetch(endpoint + '?task=' + encodeURIComponent(taskId), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    }).catch(function () {
                        return { ok: false, data: { message: 'Сервер ответил не данными.' } };
                    });
                }).then(function (result) {
                    link.removeAttribute('aria-busy');

                    if (!result.ok || !result.data || !result.data.html) {
                        /*
                         * Не получилось — переходим по ссылке: страница откроется
                         * обычным порядком, и человек получит либо окно, либо
                         * внятную ошибку. Молча ничего не показать хуже.
                         */
                        window.location.href = link.href;

                        return;
                    }

                    body.setAttribute('data-editor-for', String(result.data.itemId || ''));
                    body.innerHTML = result.data.html;
                    title.textContent = 'Задание № ' + result.data.order;

                    markOpenCard(result.data.taskId);
                    initEditorForms(modal);

                    /*
                     * Черновик возвращается после того, как форма настроена:
                     * визуальный редактор текста и пряталка полей по типу
                     * задания отрабатывают при настройке, и вернуть вопрос
                     * раньше — значило бы положить его в ещё не готовую форму.
                     */
                    if (window.MyEduTaskDraft) {
                        window.MyEduTaskDraft.reopen(modal);
                    }

                    if (window.MyEduModal) {
                        window.MyEduModal.open(modal);
                    }

                    // Адрес с `?edit=` нужен для F5 и для ссылки «открыть в
                    // новой вкладке»: с перезагрузкой окно вернётся открытым.
                    if (result.data.url && window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', result.data.url);
                    }
                }).catch(function () {
                    link.removeAttribute('aria-busy');
                    window.location.href = link.href;
                });
            });
        });
    }

    /** Пометить карточку открытой — так же, как это делает сервер. */
    function markOpenCard(taskId) {
        document.querySelectorAll('.work-card--open').forEach(function (card) {
            card.classList.remove('work-card--open');
        });

        document.querySelectorAll('.work-card[data-item-id]').forEach(function (card) {
            var link = card.querySelector('[data-editor-open]');

            if (link && link.getAttribute('data-task-id') === String(taskId)) {
                card.classList.add('work-card--open');
            }
        });
    }

    initEditorForms(document);
    bindEditorOpen();
    watchEditorClosing();

    /*
     * Черновик возвращается и при загрузке страницы, а не только при открытии
     * окна скриптом.
     *
     * Форма приходит с сервера в двух случаях: когда окно открыто по адресу
     * `?edit=` (перезагрузка, отключённые скрипты) и когда его подставил скрипт.
     * В первом случае страница приходит уже с открытым редактором, и без
     * этого возврата человек после F5 увидел бы пустую форму вместо своего
     * несохранённого текста — то есть ровно то, ради чего черновик и нужен.
     *
     * Порядок тот же, что и при открытии: сначала настройка, потом возврат.
     */
    if (window.MyEduTaskDraft) {
        window.MyEduTaskDraft.reopen(document);
    }

    /*
     * Точка входа для других скриптов: подставленной форме нужны те же
     * привязки, что и пришедшей с сервера.
     */
    window.MyEduItems = { initEditorForms: initEditorForms };

    /* ------------------------------------------------------------------- доска */

    var list = document.getElementById('task-list');
    var form = document.getElementById('task-order-form');

    if (!list || !form) {
        return;
    }

/*
 * Строки работы — без пустой строки в конце.
 *
 * Пустая строка рисуется на каждой доске и в базу не пишется: это место, куда
 * бросают новое задание, а не позиция работы. Если считать её наравне с
 * остальными, её номер уедет в отправленный порядок, а сама она станет
 * перетаскиваемой — и перестановка строк начала бы ломаться.
 */
function rowElements() {
    return Array.prototype.slice.call(list.querySelectorAll('.board-row:not(.board-row--spare)'));
}

    function renumber() {
        rowElements().forEach(function (row, index) {
            var num = row.querySelector('.board-row__num');

            if (num) {
                num.textContent = String(index + 1);
            }
        });
    }

    /*
 * Порядок строк уходит на сервер без перезагрузки страницы.
 *
 * Раньше здесь стоял form.submit(), и каждое перетаскивание строки перезагружало
 * всю страницу целиком: заново собирались плагины, заново рисовались все четыре
 * вкладки редактора, а прокрутка и открытое окно редактора терялись. Перетаскивание
 * выглядело при этом исправным — просто страница «моргала», и на длинной работе,
 * где строки переставляют по одной, это быстро надоедало. Под HTTP/2 каждая такая
 * перезагрузка ещё и ждала блокировку сессии.
 *
 * Отправка одна в полёте: пока предыдущая не дошла, следующая ждёт и уходит
 * следом с самым свежим порядком. Без этого быстрое перетаскивание слало бы
 * столько запросов, сколько движений, а сервер успевал бы применить не все.
 */
var orderInFlight = false;
var orderQueued = false;

function orderToken() {
    var field = form.querySelector('input[name="_token"]');

    return field ? field.value : '';
}

function orderParams() {
    return 'order%5B%5D=' + rowElements().map(function (row) {
        return encodeURIComponent(row.getAttribute('data-row'));
    }).join('&order%5B%5D=')
        + '&_token=' + encodeURIComponent(orderToken());
}

function notifyOrder(text, bad) {
    if (window.myEduToast) {
        window.myEduToast(text, bad ? 'error' : 'success');
    }
}

/**
 * Разметить строки номерами, какими они стали на сервере.
 *
 * Возвращается порядок с сервера, а не присланный: перестановка считается там
 * с учётом весов и соседей, и присланный список после этого уже может не
 * описывать доску. Экран обязан показывать то же, что увидит ученик.
 */
function applyOrder(order) {
    var rows = rowElements();

    if (!Array.isArray(order) || order.length !== rows.length) {
        renumber();

        return;
    }

    rows.forEach(function (row, index) {
        row.setAttribute('data-row', String(order[index]));
    });

    renumber();
}

function saveOrder() {
    if (orderInFlight) {
        orderQueued = true;

        return;
    }

    if (!window.fetch) {
        return submitOrderAsForm();
    }

    orderInFlight = true;

    fetch(form.getAttribute('action') || '', {
        method: 'POST',
        body: orderParams(),
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
    }).then(function (response) {
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
        orderInFlight = false;

        if (result.denied) {
            notifyOrder('Сессия закончилась — порядок строк не сохранён. Войдите заново.', true);

            /*
             * На экране порядок, которого на сервере нет, — значит он врёт. Единственная
             * честная картинка здесь: перерисовать доску заново. Черновик открытой
             * правки при этом не теряется, он лежит в браузере и вернётся.
             */
            window.location.href = window.location.pathname + window.location.search;

            return;
        }

        if (result.data === null) {
            submitOrderAsForm();

            return;
        }

        if (!result.data.ok) {
            /*
             * Порядок не применён, и это не поломка: строки на доске разошлись с
             * присланными, например их добавили в другой вкладке. Показывать
             * сохранённый порядок нельзя — экрана нет, есть только то, что человек
             * навёл мышью. Доску перерисовываем.
             */
            notifyOrder(result.data.error || 'Порядок не сохранён.', true);
            window.location.href = window.location.pathname + window.location.search;

            return;
        }

        applyOrder(result.data.order);
        notifyOrder('Порядок сохранён', false);

        if (orderQueued) {
            orderQueued = false;
            saveOrder();
        }
    }).catch(function () {
        orderInFlight = false;

        // Сеть не ответила: обычная отправка сохранит порядок и уйдёт с доски.
        submitOrderAsForm();
    });
}

/**
 * Отправить порядок обычной отправкой формы.
 *
 * Путь для браузера без скриптов и для сети, которая не ответила: страница
 * перезагрузится, но порядок применится. Раньше это был единственный путь,
 * и он же объяснял, почему перетаскивание стоило целой перезагрузки.
 */
function submitOrderAsForm() {
    form.querySelectorAll('input[name="order[]"]').forEach(function (node) {
        node.remove();
    });

    rowElements().forEach(function (row) {
        var input = document.createElement('input');

        input.type = 'hidden';
        input.name = 'order[]';
        input.value = row.getAttribute('data-row');
        form.appendChild(input);
    });

    form.submit();
}

    /* ---------------------------------------------------------- перенос строк */

    var draggedRow = null;

    rowElements().forEach(function (row) {
        row.setAttribute('draggable', 'true');
    });

    list.addEventListener('dragstart', function (e) {
        var row = e.target.closest ? e.target.closest('.board-row') : null;

        /*
         * За строку тянут за шапку, а не за карточку: иначе одно движение
         * означало бы сразу два разных переноса — строки и карточки внутри
         * неё, и человек не понял бы, что именно переехало.
         */
        if (!row || (e.target.closest && e.target.closest('.work-card'))) {
            return;
        }

        draggedRow = row;
        row.classList.add('is-dragging');

        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
    });

    list.addEventListener('dragover', function (e) {
        if (!draggedRow) {
            return;
        }

        var row = e.target.closest ? e.target.closest('.board-row') : null;

        /*
         * Пустая строка в конце не участвует в перестановке: она всегда
         * последняя и всегда одна. Бросок на неё ничего бы не переставил, а
         * строка под ней — пустое место, которое переставлять нельзя.
         */
        if (!row || row === draggedRow || row.hasAttribute('data-spare-row')) {
            return;
        }

        e.preventDefault();

        var all = rowElements();
        var from = all.indexOf(draggedRow);
        var to = all.indexOf(row);

        /*
         * Вставляем перед строкой-целью, а не рядом с ней через соседей:
         * пустая строка в конце списке стоять не должна, поэтому хвост
         * отрезается и вставляется заново — иначе перенесённая строка
         * оказалась бы после неё.
         */
        var spare = list.querySelector('[data-spare-row]');
        var after = from < to ? (all[to + 1] || spare) : row;

        list.insertBefore(draggedRow, after);

        if (spare) {
            list.appendChild(spare);
        }

        renumber();
    });

    list.addEventListener('dragend', function () {
        if (!draggedRow) {
            return;
        }

        draggedRow.classList.remove('is-dragging');
        draggedRow = null;
        saveOrder();
    });

    /* ------------------------------------------------------ перенос карточек */

    /*
     * Карточка ищет свою ячейку по DOM, а не по пересчитанным номерам строк:
     * при переносе строки её data-row остаётся прежним, и сервер разберётся
     * сам — он переставит строки и сдвинет номера сам.
     */
    var draggedCard = null;
    var cardHome = null;

    function cellOf(node) {
        return node && node.closest ? node.closest('.work-cell') : null;
    }

    list.querySelectorAll('.work-card').forEach(function (card) {
        card.setAttribute('draggable', 'true');
    });

    list.addEventListener('dragstart', function (e) {
        var card = e.target.closest ? e.target.closest('.work-card') : null;

        if (!card) {
            return;
        }

        e.stopPropagation();

        draggedCard = card;
        cardHome = cellOf(card);
        card.classList.add('is-dragging');

        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
    });

    list.addEventListener('dragover', function (e) {
        var cell = cellOf(e.target);

        if (!draggedCard || !cell) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        list.querySelectorAll('.work-cell.is-over').forEach(function (other) {
            if (other !== cell) {
                other.classList.remove('is-over');
            }
        });

        cell.classList.add('is-over');

        if (cell !== cellOf(draggedCard) && cell.firstElementChild !== draggedCard) {
            cell.appendChild(draggedCard);
        }
    });

    list.addEventListener('drop', function (e) {
        if (!draggedCard) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
    });

    list.addEventListener('dragend', function () {
        if (!draggedCard) {
            return;
        }

        var card = draggedCard;
        var target = cellOf(card);
        var row = target ? target.getAttribute('data-row') : null;
        var variant = target ? target.getAttribute('data-variant') : null;
        var moved = cardHome && target && cellOf(cardHome) !== target;

        card.classList.remove('is-dragging');
        draggedCard = null;
        cardHome = null;

        if (target) {
            target.classList.remove('is-over');
        }

        if (!moved || !row || !variant) {
            return;
        }

        /*
         * Отправляем на сервер и возвращаем доску с сервера: альтернативы
         * сдвигаются, а скрипт про сдвиг не знает — рисовать его здесь
         * означало бы держать вторую копию правил раскладки строки.
         */
        var request = document.createElement('form');
        var base = list.getAttribute('data-move-url') || '';

        request.method = 'POST';
        request.setAttribute('action', base + encodeURIComponent(card.getAttribute('data-item-id') || ''));

        var token = form.querySelector('input[name="_token"]');
        var fields = [
            ['_token', token ? token.value : ''],
            ['row', row],
            ['variant', variant],
        ];

        fields.forEach(function (pair) {
            var input = document.createElement('input');

            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            request.appendChild(input);
        });

        document.body.appendChild(request);
        request.submit();
    });

    /* ------------------------------------------------------ добавление из палитры */

    /*
     * Вид из палитры — это форма: без скриптов нажатие добавляет задание в
     * конец работы, и палитра остаётся рабочей. Перетаскивание лишь подставляет
     * в эту же форму ячейку, в которую бросили карточку.
     */
    var paletteDrag = null;

    document.querySelectorAll('[data-palette-kind]').forEach(function (button) {
        button.setAttribute('draggable', 'true');

        button.addEventListener('dragstart', function (e) {
            paletteDrag = button;

            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', '');
            }
        });

        button.addEventListener('dragend', function () {
            paletteDrag = null;
        });
    });

    list.addEventListener('dragover', function (e) {
        if (!paletteDrag) {
            return;
        }

        var cell = cellOf(e.target);

        if (!cell) {
            return;
        }

        e.preventDefault();

        if (e.dataTransfer) {
            e.dataTransfer.dropEffect = 'copy';
        }

        list.querySelectorAll('.work-cell.is-over').forEach(function (other) {
            if (other !== cell) {
                other.classList.remove('is-over');
            }
        });

        cell.classList.add('is-over');
    });

    list.addEventListener('drop', function (e) {
        if (!paletteDrag) {
            return;
        }

        var cell = cellOf(e.target);

        if (!cell) {
            return;
        }

        e.preventDefault();
        cell.classList.remove('is-over');

        var card = paletteDrag.closest('[data-palette-form]');

        if (!card) {
            return;
        }

        card.querySelector('[data-palette-row]').value = cell.getAttribute('data-row');
        card.querySelector('[data-palette-variant]').value = cell.getAttribute('data-variant');
        card.submit();
    });
}());