/*
 * Вкладки редактора задания.
 *
 * Переключение происходит на месте: форма одна, и перезагрузка при переходе
 * между вкладками означала бы, что человек либо потеряет несохранённое, либо
 * побоится открывать соседнюю вкладку вообще.
 *
 * Проверка формы при переключении не выполняется намеренно. Вкладка ничего не
 * сохраняет, и требовать заполнить скрытые поля перед тем, как посмотреть
 * соседние настройки, значит запрещать смотреть: браузер показал бы ошибку в
 * поле, которого на экране нет. Проверка случается один раз — при нажатии
 * «Сохранить».
 *
 * Слушатель на документе, а не на кнопках. Форма плагина попадает в окно
 * двумя путями: целиком при перезагрузке страницы и по данным, когда окно
 * открывает скрипт. Во втором случае кнопок вкладок в момент загрузки
 * страницы ещё нет, и слушатель, навешенный на них, не поймал бы щелчок.
 * Без скриптов вкладок нет: без них видна одна панель, и это работает.
 */
(function () {
    'use strict';

    function select(key) {
        document.querySelectorAll('[data-task-pane-tab]').forEach(function (tab) {
            var active = tab.getAttribute('data-task-pane-tab') === key;

            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        document.querySelectorAll('[data-task-pane]').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-task-pane') !== key;
        });
    }

    document.addEventListener('click', function (event) {
        var tab = event.target.closest ? event.target.closest('[data-task-pane-tab]') : null;

        if (tab === null) {
            return;
        }

        event.preventDefault();

        select(tab.getAttribute('data-task-pane-tab'));
    });
}());
