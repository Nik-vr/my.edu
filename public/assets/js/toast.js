/* ==========================================================================
   Всплывающие сообщения об ответе сервера на запрос скрипта.

   Разметка шаблона gentelella: .toast-host и .toast с полосами по левому краю
   (--toast-success, --toast-error, --toast-warning). То же, что у них в
   v4/toast.js.

   Отличается от баннера тем, что живёт поверх страницы и исчезает сам. Баннер
   остаётся для сообщений после перехода — там скрипта может не быть вовсе, и
   человек обязан увидеть результат на странице. Здесь же страница никуда не
   уходила: человек и так видит, что нажал, — и ему нужно знать только, чем
   это кончилось.

   Сообщение об ошибке держится вдвое дольше обычного: его успевают прочесть и
   понять, что нажатый ключ не сработал. Написать его на миг — значит сообщить
   о поломке тому, кто мимо смотрел в этот момент, и промолчать о том, кто
   ждал ответа. И то, и другое гаснет само; щёлкнуть по нему тоже можно — но
   по ошибке человек щёлкает нечасто, а тишина на экране остаётся.
   ========================================================================== */

(function () {
    'use strict';

    /*
     * В сборке шаблона есть только три окрашенных варианта: success, error и
     * warning. Обычного тоста (без полосы слева) там нет, но он и не нужен —
     * поэтому четвёртый вариант в списке означает «просто тост», а не
     * .toast-info, которого в стилях не существует и который выглядел бы как
     * неоформленный серый прямоугольник.
     */
    var VARIANTS = ['success', 'error', 'warning', 'default'];

    /*
     * Время жизни сообщения.
     *
     * Отказ держится вдвое дольше обычного — так обещает подсказка к
     * `showToast`, и раньше обещание было неправдой: сообщение об ошибке не
     * гасло никогда. Оно и правда живёт до щелчка, но человек уходит за
     * клавиатуру, вспоминает про ошибку через полминуты и находит пустое
     * место — сообщение уже исчезло, а что именно не сработало, он не понял.
     */
    var DURATION = 2600;
    var ERROR_DURATION = 5200;

    var host = null;

    function getHost() {
        if (host && host.isConnected) {
            return host;
        }

        /*
         * У самого контейнера роли нет намеренно: он собирает и подтверждения,
         * и ошибки, а вслух они должны произноситься по-разному. Роль на
         * контейнере означала бы одно и то же отношение ко всем сообщениям
         * разом — ошибка произносилась бы так же вежливо, как «сохранено», и
         * при чтении с экрана не была бы даже замечена. Роль задаётся каждому
         * сообщению отдельно, в `showToast`.
         */
        host = document.createElement('div');
        host.className = 'toast-host';
        document.body.appendChild(host);

        return host;
    }

    /**
     * Показать сообщение.
     *
     * @param {string} message Текст
     * @param {string} variant 'success' | 'error' | 'warning' | 'info'
     * @param {number} duration Сколько живёт, миллисекунды
     */
    function showToast(message, variant, duration) {
        if (!message) {
            return null;
        }

        var kind = VARIANTS.indexOf(variant) === -1 ? 'default' : variant;
        var node = document.createElement('div');

        node.className = kind === 'default' ? 'toast' : 'toast toast-' + kind;
        node.textContent = message;

        /*
         * Ошибка объявляется тревогой: `role="alert"` прерывает то, что
         * вспомогательная технология читает сейчас, иначе она произносит её
         * после следующего сообщения — а следующим может оказаться уже другое
         * дело, и человек услышит не то, что случилось.
         */
        node.setAttribute('role', kind === 'error' ? 'alert' : 'status');

        if (kind !== 'error') {
            node.setAttribute('aria-live', 'polite');
        }

        getHost().appendChild(node);

        // Отрисовка заранее, иначе переход появления не сработает: браузер
        // не анимирует элемент, который только что появился.
        node.getBoundingClientRect();
        node.classList.add('show');

        function dismiss() {
            node.classList.remove('show');
            node.addEventListener('transitionend', function () { node.remove(); }, { once: true });
        }

        node.style.cursor = 'pointer';
        node.addEventListener('click', dismiss);

        setTimeout(dismiss, duration || (kind === 'error' ? ERROR_DURATION : DURATION));

        return node;
    }

    window.myEduToast = showToast;

    /*
     * Сообщение, оставленное сервером на странице.
     *
     * Сервер кладёт текст в метку `data-toast`, и скрипт показывает его как
     * обычное всплывающее сообщение. Так сообщают о сохранении там, где
     * страница не уходила: человек видит на ней же то, что изменилось, и баннер
     * был бы второй вещью об одном.
     *
     * Метка снимается сразу: сообщение показывается один раз, и перезагрузка
     * страницы не должна приносить его снова.
     */
    function showServerToast() {
        var nodes = document.querySelectorAll('[data-toast]');

        Array.prototype.forEach.call(nodes, function (node) {
            var text = (node.getAttribute('data-toast') || '').trim();
            var variant = node.getAttribute('data-toast-variant') || 'success';

            node.remove();

            if (text) {
                showToast(text, variant);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showServerToast);
    } else {
        showServerToast();
    }
}());