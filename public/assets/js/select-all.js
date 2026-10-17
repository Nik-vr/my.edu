/* ==========================================================================
   Галочка «Выбрать всех» в таблице.

   Отдельный файл, потому что инлайн-обработчики запрещены
   Content-Security-Policy, а ослаблять политику ради одной галочки
   означало бы не ставить её вовсе.

   Отметки снимаются не вручную, а по факту: иначе пришлось бы галочкой
   следить за каждым изменением. Неопределённое состояние сделано явно —
   иначе браузер показывал бы галочку «выбраны не все» как «выбраны все».

   Поля ищутся не внутри формы, а по признаку принадлежности к ней.
   В таблице учеников форма отметок стоит снаружи, а сами отметки внутри
   таблицы привязаны к ней атрибутом form: иначе они оказались бы вложенными
   формами — браузер такие выбрасывает, и кнопки в строках перестают работать.
   Искать по вложенности значило бы не найти ничего и молча отключить галочку.
   ========================================================================== */

(function () {
    'use strict';

    /*
     * Свойство .form у поля возвращает форму, к которой оно отнесено, — и
     * учитывает атрибут form. Оно и есть готовый ответ на вопрос «чьё это
     * поле», поэтому достаточно спросить у браузера, а не строить условия
     * самому. Старые браузеры без этого свойства просто не найдут полей и
     * оставят галочку неактивной.
     */
    function fieldsOf(form, selector) {
        var found = Array.prototype.slice.call(document.querySelectorAll(selector));

        if (!found.length) {
            return [];
        }

        return found.filter(function (field) {
            return field.form ? field.form === form : form.contains(field);
        });
    }

    function bind(form) {
        var all = fieldsOf(form, '[data-select-all]');

        if (!all.length) {
            return;
        }

        var master = all[0];
        var items = fieldsOf(form, '[data-select-item]');

        function refresh() {
            var checked = items.filter(function (item) { return item.checked; }).length;

            master.checked = checked > 0 && checked === items.length;
            master.indeterminate = checked > 0 && checked < items.length;
        }

        master.addEventListener('change', function () {
            items.forEach(function (item) {
                item.checked = master.checked;
            });

            refresh();
        });

        items.forEach(function (item) {
            item.addEventListener('change', refresh);
        });

        refresh();
    }

    document.querySelectorAll('[data-select-all-form]').forEach(bind);
}());