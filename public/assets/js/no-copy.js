/*
 * Запрет копирования текста задания.
 *
 * Подключается на странице прохождения, только если учитель включил запрет в
 * настройках работы.
 *
 * Что именно запрещается: выделять и копировать текст вопроса. Вопрос — это
 * то, что автор не хочет отдавать; ответ ученика — его собственный текст, и
 * записывать его он должен свободно.
 *
 * Почему не только запрет копирования. Событие `copy` срабатывает уже после
 * того, как текст выделен, и в буфер обмена он к тому моменту может успеть
 * попасть. Поэтому выделение блокируется первым: нельзя выделить — нечего
 * копировать. Событие `copy` запрещено тоже, но как запасной круг: скопировать
 * можно и не выделяя — например, перетаскиванием выделения или через
 * контекстное меню.
 *
 * Поля ответа остаются нетронутыми: в поле нельзя запрещать выделение, иначе
 * ученик не смог бы ни поправить свой ответ, ни скопировать его обратно в
 * другое поле.
 */
(function () {
    'use strict';

    /**
     * Начало выделения лежит в поле ввода?
     *
     * @param {Node|null} node
     * @returns {boolean}
     */
    function insideField(node) {
        while (node) {
            if (node.nodeType === 1) {
                var tag = node.tagName;

                if (tag === 'INPUT' || tag === 'TEXTAREA' || node.isContentEditable) {
                    return true;
                }
            }

            node = node.parentNode;
        }

        return false;
    }

    document.addEventListener('selectstart', function (event) {
        if (insideField(event.target)) {
            return;
        }

        event.preventDefault();
    });

    document.addEventListener('copy', function (event) {
        var selection = window.getSelection();

        if (selection && selection.anchorNode && insideField(selection.anchorNode)) {
            return;
        }

        event.preventDefault();
    });

    document.addEventListener('cut', function (event) {
        if (insideField(event.target)) {
            return;
        }

        event.preventDefault();
    });
}());
