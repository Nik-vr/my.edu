/* ==========================================================================
   Проверка форм перед отправкой.

   Задача модуля — не дать отправить форму с пустым или негодным значением и
   показать ошибку у того поля, где человек её допустил.

   Проверка своя, а не встроенная браузерная, по трём причинам. Первая: сообщение
   должно быть нашим, а не пересказом правил браузера на его языке. Вторая:
   внешний вид ошибки один на весь сайт — то же самое, что рисует сервер, класс
   .is-invalid на поле и подпись .form-error под ним. Третья: браузерная проверка
   молчит о поле, которое не в фокусе, и о поле внутри шага мастера, который ещё
   не открыт.

   Правила берутся из самой разметки — required, minlength, maxlength, min, max,
   step, type, pattern, — поэтому отдельного списка правил здесь нет и быть не
   должно: он разошёлся бы с разметкой при первом же изменении поля. Своё
   сообщение задаётся в поле через data-hint.

   Форма никогда не очищается. Модуль только мешает отправке и рисует ошибки:
   то, что человек ввёл, остаётся на месте и после неудачной попытки, и после
   исправления — иначе он был бы вынужден вводить всё заново.

   Сервер проверяет те же значения независимо от этого модуля: подделать запрос
   можно, а полагаться на браузер — нельзя.

   Живёт в файле, а не в разметке: политика безопасности отдаётся с
   script-src 'self', и код прямо в странице был бы заблокирован.
   ========================================================================== */

(function () {
    'use strict';

    var ERROR_CLASS = 'is-invalid';
    var ERROR_NODE = 'form-error';
    var SUMMARY_CLASS = 'form-summary';
    var CHECKED_FLAG = 'data-validate-touched';
    var READY_FLAG = 'data-validate-ready';

    /*
     * Подпись поля: ближайшая метка рядом с ним.
     *
     * Берётся из разметки, а не из имени поля: «name=» в сообщении человек не
     * понимает, а «Номер класса» понимает.
     */
    function labelOf(field) {
        var holder = field.closest('label');

        if (holder) {
            var text = holder.querySelector('.form-label');

            if (text) {
                return text.textContent.trim();
            }
        }

        if (field.id) {
            var byFor = document.querySelector('label[for="' + field.id + '"]');

            if (byFor) {
                return byFor.textContent.trim();
            }
        }

        var group = field.closest('.form-group');

        if (group) {
            var label = group.querySelector('.form-label, .form-group__label');

            if (label) {
                return label.textContent.trim();
            }
        }

        return field.getAttribute('name') || 'поле';
    }

    function isBlank(field) {
        if (field.type === 'checkbox' || field.type === 'radio') {
            return !field.checked;
        }

        return field.value.trim() === '';
    }

    /*
 * Обязательное ли поле прямо сейчас.
 *
 * Кроме обычного required есть условная обязательность: поля «свои даты»
 * нужны только когда выбран режим со своими датами, а в остальных режимах они
 * не отправляются вовсе и требовать их было бы неправдой.
 *
 * Условие задаётся в разметке: data-required-when="period:dates" — «если поле
 * period выбрано со значением dates». Проверяется то, что человек видит и может
 * изменить, без отдельного списка правил в скрипте.
 */
function isRequired(field) {
    if (field.required) {
        return true;
    }

    var when = field.getAttribute('data-required-when');

    if (!when) {
        return false;
    }

    var parts = when.split(':');

    if (parts.length !== 2) {
        return false;
    }

    var controls = field.form ? field.form.querySelectorAll('[name="' + parts[0] + '"]') : [];

    return Array.prototype.some.call(controls, function (control) {
        return control.checked && control.value === parts[1];
    });
}

function dependentFields(form, name) {
    return Array.prototype.filter.call(
        form.querySelectorAll('[data-required-when^="' + name + ':"]'),
        function (field) {
            return field;
        }
    );
}

    /*
     * Текст ошибки поля или null, если поле в порядке.
     *
     * Порядок проверок — от самой очевидной к самой тонкой: пустое поле человек
     * исправляет первым делом, и рассказывать ему про формат даты, пока он
     * ничего не ввёл, незачем.
     */
    function problemWith(field) {
        var hint = field.getAttribute('data-hint') || '';
        var what = labelOf(field);

        if (field.disabled || field.type === 'hidden') {
            return null;
        }

        if (isRequired(field) && isBlank(field)) {
            if (field.type === 'checkbox') {
                return hint || 'Отметьте «' + what + '»';
            }

            return hint || 'Заполните «' + what + '»';
        }

        if (isBlank(field)) {
            return null;
        }

        var value = field.value.trim();
        var min = field.getAttribute('minlength');
        var max = field.getAttribute('maxlength');

        if (min && value.length < Number(min)) {
            return hint || 'Минимум ' + min + ' символов';
        }

        if (max && value.length > Number(max)) {
            return hint || 'Не больше ' + max + ' символов';
        }

        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
            return hint || 'Похоже, это не адрес почты';
        }

        if (field.type === 'url' && !/^https?:\/\/[^\s/]+/i.test(value)) {
            return hint || 'Нужен полный адрес, например https://example.ru';
        }

        if (field.type === 'number' || field.type === 'range') {
            var number = Number(value);

            if (isNaN(number)) {
                return hint || 'Нужно число';
            }

            var lowest = field.getAttribute('min');
            var highest = field.getAttribute('max');

            if (lowest !== null && number < Number(lowest)) {
                return hint || 'Не меньше ' + lowest;
            }

            if (highest !== null && number > Number(highest)) {
                return hint || 'Не больше ' + highest;
            }

            var step = field.getAttribute('step');

            if (step && Number(step) > 0) {
                var base = lowest !== null ? Number(lowest) : 0;
                var steps = (number - base) / Number(step);

                if (Math.abs(steps - Math.round(steps)) > 0.0001) {
                    return hint || 'Дробное значение не подходит: шаг ' + step;
                }
            }
        }

        if (field.type === 'date') {
            // Поле типа date отдаёт значение только когда дата настоящая, но
            // браузер с другим форматом может принять мусор: пустая строка и
            // неразбираемое значение считаем ошибкой ввода.
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                // Формат в подсказке — тот, в котором значение приходит в
                // серверную проверку: год, месяц, день.
                return hint || 'Введите дату в формате ГГГГ-ММ-ДД';
            }
        }

        var pattern = field.getAttribute('pattern');

        if (pattern && !new RegExp('^(?:' + pattern + ')$', 'u').test(value)) {
            return hint || 'Значение не подходит по формату';
        }

        return null;
    }

    function errorNodeFor(field) {
        var group = field.closest('.form-group');

        if (group) {
            return group.querySelector('.' + ERROR_NODE);
        }

        return field.parentNode ? field.parentNode.querySelector('.' + ERROR_NODE) : null;
    }

    function showProblem(field, message) {
        field.classList.add(ERROR_CLASS);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute(CHECKED_FLAG, '');

        var node = errorNodeFor(field);

        if (!node && field.parentNode) {
            node = document.createElement('p');
            node.className = 'banner ' + ERROR_NODE;
            node.setAttribute('role', 'alert');
            field.parentNode.appendChild(node);
        }

        if (node) {
            node.textContent = message;
            node.hidden = false;
        }
    }

    function clearProblem(field) {
        field.classList.remove(ERROR_CLASS);
        field.removeAttribute('aria-invalid');

        var node = errorNodeFor(field);

        if (node) {
            /*
             * Подпись ошибки не удаляется, а прячется: сервер рисует её в том же
             * самом месте, и пустой <p class="form-error"> оставлял бы пустое
             * место под полем. Скрытый узел при этом остаётся в разметке — и
             * сервер, и браузер пишут в одно и то же место.
             */
            node.hidden = true;
        }
    }

    /*
     * Пересчитать, что в форме ещё плохо.
     *
     * Считаются и поля, и группы-вопросы («отметьте работу»): иначе исправленный
     * текст снимал бы сводку, пока не отмеченная работа продолжала бы её
     * требовать. Без побочных эффектов — только список.
     */
    function collectProblems(form, fields) {
        var failed = [];
        var failedGroups = [];

        (fields || fieldsOf(form)).forEach(function (field) {
            if (problemWith(field) !== null) {
                failed.push(field);
            }
        });

        groupsOf(form).forEach(function (group) {
            if (problemWithGroup(group) !== null) {
                failedGroups.push(group);
            }
        });

        return { fields: failed, groups: failedGroups };
    }

    /*
     * Убрать сводку, когда исправлено последнее плохое поле.
     *
     * Иначе надпись «проверьте поле» остаётся висеть над формой, в которой всё
     * уже заполнено, и человек нажимает кнопку в третий раз, недоумевая, что
     * ещё не так.
     */
    function refreshSummary(form) {
        var summary = form.querySelector('.' + SUMMARY_CLASS);

        if (!summary) {
            return;
        }

        var problems = collectProblems(form);

        if (!problems.fields.length && !problems.groups.length) {
            summary.remove();
        }
    }

    /*
     * Поля, которые проверяем: не скрытые, не отключённые и не вне формы.
     */
    function fieldsOf(form) {
        return Array.prototype.filter.call(
            form.querySelectorAll('input, select, textarea'),
            function (field) {
                return field.type !== 'hidden' && !field.disabled && field.name !== '_token';
            }
        );
    }

    /*
     * Поля шага мастера.
     *
     * Проверяется только тот шаг, который человек видит: поля спрятанных шагов
     * пусты по определению, и требовать их заполнить до открытия шага значило бы
     * запретить выход на первый шаг вообще.
     */
    function scopeFor(form) {
        var scope = form.closest('[data-step]');

        return scope ? scope.querySelectorAll('input, select, textarea') : fieldsOf(form);
    }

    /*
     * Поле, где нужно отметить хоть что-то.
     *
     * Отдельного правила в разметке у флажков нет: HTML умеет требовать выбор
     * только у радиокнопок, а «отметьте хотя бы одного ученика» — это как раз
     * список флажков. Такие группы помечены data-required-group, и без единой
     * отмеченной галочки шаг мастера не отправляется.
     */
    function problemWithGroup(group) {
        /*
         * Считаются только поля, у которых есть имя: без имени флажок не
         * отправляется, и отметка «выбрать всех» не выбрала ни одного ученика.
         */
        var boxes = group.querySelectorAll('input[type="checkbox"][name], input[type="radio"][name]');

        if (!boxes.length) {
            return null;
        }

        var marked = Array.prototype.some.call(boxes, function (box) {
            return box.checked && !box.disabled;
        });

        if (marked) {
            return null;
        }

        return group.getAttribute('data-hint') || 'Отметьте хотя бы один вариант';
    }

    function groupErrorNode(group) {
        var node = group.querySelector('.' + ERROR_NODE);

        if (!node) {
            node = document.createElement('p');
            node.className = 'banner ' + ERROR_NODE;
            node.setAttribute('role', 'alert');
            group.appendChild(node);
        }

        return node;
    }

    function showGroupProblem(group, message) {
        groupErrorNode(group).textContent = message;
        groupErrorNode(group).hidden = false;
    }

    function clearGroupProblem(group) {
        var node = group.querySelector('.' + ERROR_NODE);

        if (node) {
            node.hidden = true;
        }
    }

    function groupsOf(form) {
        return Array.prototype.slice.call(form.querySelectorAll('[data-required-group]'));
    }

    /*
     * Сводка сверху формы нужна не для красоты: первая ошибка на экране — это то
     * поле, которое человек не видит, потому что форма длинная и он нажал кнопку
     * внизу.
     */
    function summaryFor(form) {
        var node = form.querySelector('.' + SUMMARY_CLASS);

        if (!node) {
            node = document.createElement('p');
            node.className = 'banner banner-danger ' + SUMMARY_CLASS;
            node.setAttribute('role', 'alert');
            form.insertBefore(node, form.firstChild);
        }

        return node;
    }

    function validate(form, fields) {
        var scope = fields || fieldsOf(form);
        var problems = collectProblems(form, scope);
        var failed = problems.fields;
        var failedGroups = problems.groups;

        failed.forEach(function (field) {
            showProblem(field, problemWith(field));
        });

        failedGroups.forEach(function (group) {
            showGroupProblem(group, problemWithGroup(group));
        });

        scope.forEach(function (field) {
            if (failed.indexOf(field) === -1) {
                clearProblem(field);
            }
        });

        groupsOf(form).forEach(function (group) {
            if (failedGroups.indexOf(group) === -1) {
                clearGroupProblem(group);
            }
        });

        var total = failed.length + failedGroups.length;

        if (!total) {
            var summary = form.querySelector('.' + SUMMARY_CLASS);

            if (summary) {
                summary.remove();
            }

            return true;
        }

        /*
         * Сводка сверху формы нужна не для красоты: первая ошибка на экране —
         * это то поле, которое человек не видит, потому что форма длинная и он
         * нажал кнопку внизу.
         */
        if (total === 1) {
            summaryFor(form).textContent = failed.length
                ? 'Не отправлено: проверьте поле «' + labelOf(failed[0]) + '»'
                : 'Не отправлено: ' + failedGroups[0].getAttribute('data-hint');
        } else {
            summaryFor(form).textContent = 'Не отправлено: проверьте ' + total + ' пол.';
        }

        if (failed.length) {
            failed[0].focus();
        } else {
            var first = failedGroups[0].querySelector('input, select, textarea');

            if (first) {
                first.focus();
            }
        }

        return false;
    }

    /*
     * Слушатели одной формы.
     *
     * Форма получает novalidate программно, а не в разметке: без скриптов
     * браузерная проверка остаётся в силе и работает как страховка, а с ним
     * сообщения становятся нашими.
     */
    function bind(form) {
        form.noValidate = true;

        form.addEventListener('submit', function (event) {
            if (!validate(form, scopeFor(form))) {
                event.preventDefault();
            }
        });

        /*
         * После первой неудачной попытки ошибка исчезает сама, как только
         * поле исправлено: иначе человек дописывал бы вторую фамилию к
         * первой и думал, что ошибка осталась.
         */
        var recheck = function (event) {
            var field = event.target;

            /*
             * Переключатель режима меняет обязательность зависимых полей:
             * выбрали «свои даты» — даты стали обязательными, выбрали
             * «неделя» — перестали. Ошибка на них появляется и исчезает
             * вместе с выбором, а не остаётся до следующей отправки.
             */
            if (field.name) {
                dependentFields(form, field.name).forEach(function (dependent) {
                    var dependentProblem = problemWith(dependent);

                    if (dependentProblem) {
                        showProblem(dependent, dependentProblem);
                    } else {
                            clearProblem(dependent);
                        }
                    });
                }

                if (!field.matches('input, select, textarea')
                    || !field.hasAttribute(CHECKED_FLAG)) {
                    /*
                     * Отметка в списке флажков относится к группе, а не к полю:
                     * ошибка «отметьте хотя бы одного» исчезает, как только
                     * человек отметил первого же.
                     */
                    var group = event.target.closest('[data-required-group]');

                    if (group && group.matches('[data-required-group]')) {
                        var groupProblem = problemWithGroup(group);

                        if (groupProblem) {
                            showGroupProblem(group, groupProblem);
                        } else {
                            clearGroupProblem(group);
                            refreshSummary(form);
                        }
                    }

                    return;
                }

                var problem = problemWith(field);

                if (problem) {
                    showProblem(field, problem);
                } else {
                    clearProblem(field);
                    refreshSummary(form);
                }
            };

            form.addEventListener('blur', recheck, true);
        form.addEventListener('change', recheck);

        form.setAttribute(READY_FLAG, '');
    }

    /*
     * Формула: одна форма — один набор слушателей.
     *
     * init() вызывается и на загрузке, и на появлении новых форм, поэтому
     * без метки страница получила бы по второму submit-слушателю на форму и
     * проверяла одно и то же поле дважды.
     */
    function bindOnce(forms) {
        Array.prototype.forEach.call(forms, function (form) {
            if (form.hasAttribute(READY_FLAG)) {
                return;
            }

            bind(form);
        });
    }

    function init() {
        bindOnce(document.querySelectorAll('form[data-validate], form:not([data-no-validate])'));

        /*
         * Форма, добавленная скриптом позже, тоже должна проверяться: иначе
         * окно, открытое кнопкой, отправило бы пустое значение мимо всей
         * проверки. Наблюдатель один на страницу и молчит, пока разметка не
         * меняется.
         */
        if (!window.MyEduValidate) {
            window.MyEduValidate = { init: init, validate: validate };

            if (typeof MutationObserver === 'function') {
                new MutationObserver(function (records) {
                    records.forEach(function (record) {
                        Array.prototype.forEach.call(record.addedNodes, function (node) {
                            if (node.nodeType !== 1) {
                                return;
                            }

                            if (node.matches && node.matches('form')) {
                                bindOnce([node]);
                            }

                            if (node.querySelectorAll) {
                                bindOnce(node.querySelectorAll('form[data-validate], form:not([data-no-validate])'));
                            }
                        });
                    });
                }).observe(document.documentElement, { childList: true, subtree: true });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());