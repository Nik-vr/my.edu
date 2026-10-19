/* ==========================================================================
   Подтверждение опасных действий всплывающим окном.

   Разметка окна — из шаблона gentelella: .modal-backdrop, .modal-dialog,
   .modal-header, .modal-body, .modal-footer и кнопки .btn. Своих классов
   здесь нет: окно выглядит как в шаблоне, потому что оно и есть его окно.

   Подтверждение в окне — надстройка, а не условие. Без скриптов ссылка ведёт
   на обычную страницу подтверждения, которая и сейчас на месте: удаление
   уносит учеников вместе с логинами, и «да» в браузерном вопросе было бы
   слишком дешёвым. Поэтому скрипт строит форму внутри окна и отправляет её
   обычным POST — тем же самым, что делала бы страница.

   Токен защиты берётся из <meta name="csrf-token">: так его читает и сам
   шаблон в tables.js, и подменять его тут незачем.

   Живёт в файле, а не в разметке: Content-Security-Policy отдаётся с
   script-src 'self', и инлайн-код был бы просто заблокирован.
   ========================================================================== */

(function () {
    'use strict';

    var FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    var openBackdrop = null;
    var previousFocus = null;
    var pendingResult = null;

    /*
     * Ответ окна тому, кто его открыл.
     *
     * Кнопка «да» и любой другой способ закрытия — это разные ответы, и
     * различать их приходится здесь. Раньше окно умело только отправить форму,
     * поэтому скрипты, которым нужно было спросить и продолжить по-разному
     * (завершить работу, закрыть редактор), писали window.confirm: чужое окно
     * браузера посреди сайта, и отменить его, не отменив отправку формы, нельзя.
     * Теперь у них одно окно на двоих.
     */
    function finish(value) {
        var done = pendingResult;

        pendingResult = null;

        if (typeof done === 'function') {
            done(value);
        }
    }

    function closeModal() {
        if (!openBackdrop) {
            return;
        }

        var el = openBackdrop;

        el.classList.remove('show');
        document.body.classList.remove('modal-open');

        var cleanup = function () {
            if (el.isConnected) {
                el.remove();
            }
        };

        el.addEventListener('transitionend', cleanup, { once: true });
        setTimeout(cleanup, 280);

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }

        openBackdrop = null;
        previousFocus = null;

        /*
         * Закрытие — это тоже ответ. Без этой строки скрипт, спросивший окно,
         * ждал бы обещание вечно: «Отмена», крестик, Escape и щелчок по фону
         * уводили бы окно, ничего не сообщив спросившему.
         */
        finish(false);
    }

    function icon(name) {
        var paths = {
            trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
            warn: '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4M12 17.3v.2"/>',
            close: '<path d="M18 6 6 18M6 6l12 12"/>'
        };

        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="1.5" aria-hidden="true">' + (paths[name] || paths.warn) + '</svg>';
    }

    function svgBtn(content, label) {
        return '<button type="button" class="modal-close" aria-label="' + label + '">' + content + '</button>';
    }

    /**
     * Окно подтверждения.
     *
     * В тело кладётся настоящая форма: она отправляется на адрес удаления
     * обычной отправкой, то есть через тот же серверный обработчик, что и
     * страница подтверждения. Отдельный адрес для скрипта означал бы второй
     * путь удаления — и рано или поздно они разошлись бы.
     */
    function showConfirm(opts) {
        closeModal();

        pendingResult = typeof opts.onResult === 'function' ? opts.onResult : null;

        previousFocus = document.activeElement;

        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';

        var dialog = document.createElement('div');
        dialog.className = 'modal-dialog modal-sm';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'confirm-title');

        var head = document.createElement('div');
        head.className = 'modal-header';

        var title = document.createElement('h2');
        title.className = 'modal-title';
        title.id = 'confirm-title';
        title.textContent = opts.title || 'Подтвердите действие';
        head.appendChild(title);
        head.appendChild(
            (function () {
                var holder = document.createElement('div');
                holder.innerHTML = svgBtn(icon('close'), 'Закрыть');

                return holder.firstChild;
            }())
        );

        var body = document.createElement('div');
        body.className = 'modal-body';

        var text = document.createElement('p');
        text.textContent = opts.body || '';

        /*
         * Форма нужна окну, которое отправляет действие серверу. Окно, которое
         * спрашивает скрипт (window.MyEduConfirm.open), отправлять нечего:
         * ему нужен только ответ, и пустая форма была бы лишней разметкой и
         * лишним обходом сервера при нажатии «да».
         */
        var form = opts.action ? document.createElement('form') : null;

        if (form) {
            form.method = 'post';
            form.action = opts.action;

            var token = document.querySelector('meta[name="csrf-token"]');

            if (token) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = '_token';
                hidden.value = token.getAttribute('content') || '';
                form.appendChild(hidden);
            }

            (opts.fields || []).forEach(function (pair) {
                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = pair[0];
                field.value = pair[1];
                form.appendChild(field);
            });

            form.appendChild(text);
            body.appendChild(form);
        } else {
            body.appendChild(text);
        }

        var foot = document.createElement('div');
        foot.className = 'modal-footer';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-outline';
        cancel.textContent = opts.cancel || 'Отмена';
        foot.appendChild(cancel);

        var confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
        confirm.textContent = opts.confirm || 'Удалить';
        confirm.addEventListener('click', function () {
            if (form) {
                form.submit();

                return;
            }

            /*
             * Спросил скрипт, а не сервер: сначала отвечаем ему и только потом
             * закрываем окно. Порядок важен — закрытие тоже сообщает ответ, и
             * без finish(true) первым ушло бы «нет».
             */
            finish(true);
            closeModal();
        });

        foot.appendChild(cancel);
        foot.appendChild(confirm);

        dialog.appendChild(head);
        dialog.appendChild(body);
        dialog.appendChild(foot);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');

        /*
         * Запоминаем открытое окно — по нему же оно потом и закрывается.
         *
         * Без этой строки closeModal() выходил сразу, и «Отмена», крестик, Escape
         * и щелчок по фону не делали ничего: окно оставалось на экране, и
         * закрыть его было нечем. Признак — жалоба «модальное окно зависает».
         */
        openBackdrop = backdrop;

        requestAnimationFrame(function () {
            backdrop.classList.add('show');
        });

        head.querySelector('.modal-close').addEventListener('click', closeModal);
        cancel.addEventListener('click', closeModal);
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                closeModal();
            }
        });

        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            var focusable = dialog.querySelectorAll(FOCUSABLE);

            if (!focusable.length) {
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        (opts.danger === false ? cancel : confirm).focus();
    }

    function bind() {
        document.querySelectorAll('[data-confirm]').forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
                // Без скриптов ссылка ведёт на страницу подтверждения, и
                // отменять её нельзя: иначе удаление стало бы недоступным там,
                // где скрипты не доехали.
                if (!document.body || !window.fetch) {
                    return;
                }

                event.preventDefault();

                /*
                 * Адрес и поля берутся у формы, в которой лежит кнопка, — а у
                 * ссылки приходится брать её саму.
                 *
                 * Раньше адрес читался как trigger.href, и у кнопки этого
                 * свойства просто нет: у `<button>` есть не href, а form.
                 * Получалось, что у четырёх окон подтверждения форма
                 * отправлялась на адрес «undefined» — то есть никуда: человек
                 * нажимал «Заблокировать» и наблюдал, как ничего не
                 * происходит.
                 */
                var form = trigger.form || trigger.closest('form');
                var action = trigger.getAttribute('data-confirm-action')
                    || (form ? form.getAttribute('action') : trigger.href);
                var pairs = [];

                if (form) {
                    Array.prototype.forEach.call(form.elements, function (field) {
                        if (!field.name || field.disabled || field.name === '_token') {
                            return;
                        }

                        var tag = field.tagName;

                        if (tag === 'BUTTON') {
                            return;
                        }

                        if (tag === 'INPUT' && (field.type === 'submit' || field.type === 'button'
                            || field.type === 'reset' || field.type === 'file')) {
                            return;
                        }

                        // Отмеченный флажок уходит, снятый — нет: иначе в
                        // запрос попадёт «remove=» от нажатой кнопки.
                        if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                            return;
                        }

                        pairs.push([field.name, field.value]);
                    });
                }

                try {
                    pairs = pairs.concat(JSON.parse(trigger.getAttribute('data-confirm-fields') || '[]'));
                } catch (e) {
                    // Поля не заданы: отправляем то, что собрали из формы.
                }

                showConfirm({
                    title: trigger.getAttribute('data-confirm') || 'Подтвердите действие',
                    body: trigger.getAttribute('data-confirm-body') || '',
                    confirm: trigger.getAttribute('data-confirm-yes') || 'Удалить',
                    cancel: trigger.getAttribute('data-confirm-no') || 'Отмена',
                    danger: trigger.getAttribute('data-confirm-danger') !== 'false',
                    action: action,
                    fields: pairs
                });
            });
        });
    }

    /*
     * Спросить из скрипта и получить ответ.
     *
     * Для случаев, где вопрос возникает не из разметки. Раньше такие места
     * пользовались window.confirm: вопрос появлялся посреди сайта чужим
     * окном браузера, и отменить его, не отменив отправку формы, было нельзя —
     * приходилось отменять отправку и начинать её заново.
     *
     * Возвращает обещание: «да» — иначе «нет». Закрытие любым способом
     * считается отказом, потому что отказом оно и ощущается.
     */
    window.MyEduConfirm = {
        open: function (opts) {
            return new Promise(function (resolve) {
                var options = opts || {};

                options.onResult = resolve;

                showConfirm(options);
            });
        }
    };

    bind();
}());