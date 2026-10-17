/* ==========================================================================
   Скрипт страницы прохождения теста.

   Основная логика страницы работает без JavaScript: и «Далее», и «Назад»,
   и переход по номеру задания — обычные кнопки отправки формы. Здесь только
   удобства: перетаскивание мышью, таймер с автоотправкой и предупреждение
   при завершении работы с незаполненными заданиями.

   Важно: ничего здесь не должно мешать отправке формы. Блокировка сделана
   через флаг и класс, а не через disabled, — отключённая кнопка отправки
   теряет своё name/value, и сервер не понял бы, что ученик нажал
   «Завершить тест», а не «Далее».
   ========================================================================== */

(function () {
    'use strict';

    var form = document.getElementById('attempt-form');

    if (!form) {
        return;
    }

    /* Автоотправка по истечении времени: обработчик submit здесь не срабатывает. */
    var autoSubmitting = false;

    /* Повторный клик по кнопке после первого — игнорируем. */
    var submitting = false;

    /* ---------------------------------------------------------- таймер */

    function initTimer() {
        var node = document.getElementById('timer-value');
        var box = document.getElementById('timer');

        if (!node) {
            return;
        }

        var remaining = parseInt(form.getAttribute('data-remaining'), 10) || 0;
        var stopped = false;

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function submitOnTimeout() {
            // form.submit() не передаёт name/value нажатой кнопки, поэтому
            // намерение завершить работу передаём отдельным скрытым полем.
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'action';
            hidden.value = 'finish';
            form.appendChild(hidden);

            autoSubmitting = true;
            form.submit();
        }

        function render() {
            if (stopped) {
                return;
            }

            if (remaining <= 0) {
                stopped = true;
                node.textContent = '00:00';

                if (box) {
                    box.classList.remove('timer--warn');
                    box.classList.add('timer--danger');
                }

                var hint = document.getElementById('submit-hint');

                if (hint) {
                    hint.textContent = 'Время вышло — работа отправляется на проверку.';
                }

                submitOnTimeout();
                return;
            }

            node.textContent = pad(Math.floor(remaining / 60)) + ':' + pad(remaining % 60);

            if (box) {
                box.classList.remove('timer--danger');

                if (remaining <= 60) {
                    box.classList.add('timer--danger');
                } else if (remaining <= 300) {
                    box.classList.add('timer--warn');
                }
            }

            remaining -= 1;
            window.setTimeout(render, 1000);
        }

        render();
    }

    /* ---------------------------------------------------------- порядок */

    /**
     * Перетаскивание элементов задания на установление последовательности.
     *
     * Списки выбора позиции при этом скрываются стилями: ответом считается
     * именно визуальный порядок. Скрытые стилями списки браузер всё равно
     * отправляет, поэтому ответ не теряется. Без библиотеки списки остаются
     * на месте, и задание выполняется обычным выбором позиции.
     */
    function initOrdering() {
        /*
         * Списков может быть несколько: в работе задание-тест не ограничено
         * одним вопросом, и два задания с последовательностью приходят на одну
         * страницу. querySelector брал только первый, и второе задание
         * оставалось без перетаскивания — притом страница выглядела исправной
         * и работала: списки позиций на месте, просто мышью не двигалось.
         */
        var lists = document.querySelectorAll('[data-ordering-list]');

        Array.prototype.forEach.call(lists, function (list) {
            setupOrderingList(list);
        });
    }

    function setupOrderingList(list) {
        syncPositions(list);

        if (typeof window.Sortable !== 'function') {
            /*
             * Без библиотеки остаётся запасной путь — выбор позиции вручную.
             * Молча уходить здесь нельзя только потому, что страница при этом
             * выглядит целой; списки остаются видимыми, и задание решается.
             */
            return;
        }

        list.classList.add('ordering--sortable');

        window.Sortable.create(list, {
            animation: 150,
            handle: '.ordering__handle',
            draggable: '[data-ordering-item]',
            ghostClass: 'ordering__item--dragging',
            onEnd: function () {
                syncPositions(list);
            }
        });
    }

    /**
     * Проставляет каждому элементу позицию по его месту в списке.
     *
     * Значения select'ов — единственный источник правды для сервера, поэтому
     * после перетаскивания списки обязаны быть пересобраны.
     */
    function syncPositions(list) {
        var items = list.querySelectorAll('[data-ordering-item]');

        Array.prototype.forEach.call(items, function (item, index) {
            var position = String(index + 1);
            var select = item.querySelector('[data-ordering-select]');
            var label = item.querySelector('.ordering__position');

            if (select) {
                select.value = position;
            }

            if (label) {
                label.textContent = position;
            }
        });
    }

    /* ---------------------------------------------------------- завершение работы */

    /**
     * Сколько заданий осталось без ответа.
     *
     * Текущее задание не считается: его ответ сохраняется только при отправке,
     * поэтому в списке слева оно ещё не отмечено. Без этой поправки ученик
     * получал бы вопрос «задание осталось без ответа» ровно в тот момент, когда
     * он всё уже заполнил, и нажимал бы «Отмена».
     */
    function countUnanswered() {
        var currentField = form.querySelector('input[name="current"]');
        var current = currentField ? parseInt(currentField.value, 10) : 0;

        var items = document.querySelectorAll('.qnav__item');

        var left = 0;

        Array.prototype.forEach.call(items, function (item) {
            if (item.classList.contains('is-answered')) {
                return;
            }

            if (parseInt(item.value, 10) === current) {
                return;
            }

            left++;
        });

        return left;
    }

    /** Текущее задание оставлено пустым — об этом стоит сказать. */
    function currentIsEmpty() {
        var section = form.querySelector('.question');

        if (!section) {
            return false;
        }

        var type = section.getAttribute('data-type');

        // Последовательность всегда «заполнена»: порядок задан по умолчанию.
        if (type === 'ordering') {
            return false;
        }

        var controls = section.querySelectorAll('input, select, textarea');

        if (!controls.length) {
            return false;
        }

        return Array.prototype.every.call(controls, function (control) {
            if (control.type === 'radio' || control.type === 'checkbox') {
                return !control.checked;
            }

            if (control.tagName === 'SELECT') {
                return control.value === '';
            }

            return String(control.value || '').trim() === '';
        });
    }

    function initSubmitGuard() {
        form.addEventListener('submit', function (event) {
            if (autoSubmitting) {
                return;
            }

            // Второй клик по «Далее» не должен перескочить через задание.
            if (submitting) {
                event.preventDefault();
                return;
            }

            var submitter = event.submitter;
            var isFinish = submitter && submitter.value === 'finish';

            if (isFinish) {
                var left = countUnanswered();
                var here = currentIsEmpty();

                if (left > 0 || here) {
                    var question = left === 1 ? 'одно задание без ответа' : 'заданий без ответа: ' + left;

                    var message = (here ? 'Текущее задание не заполнено. ' : '')
                        + (left > 0 ? question + '. ' : '')
                        + 'Завершить работу? Отменить будет нельзя.';

                    if (!window.confirm(message)) {
                        event.preventDefault();
                        return;
                    }
                }
            }

            lockForm();
        });
    }

    /**
     * Закрывает форму от повторных отправок.
     *
     * Кнопки намеренно не выключаются через disabled: у отключённой кнопки
     * отправки браузер не передаёт пару name/value, и сервер получил бы
     * запрос без намерения «завершить». Визуально форма блокируется через
     * pointer-events, а по логике — флагом submitting.
     */
    function lockForm() {
        submitting = true;
        form.classList.add('attempt--sending');
    }

    /* ---------------------------------------------------------- запуск */

    initTimer();
    initOrdering();
    initSubmitGuard();
}());