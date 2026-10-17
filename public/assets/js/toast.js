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

   Сообщение об ошибке не исчезает само: нажатый и не сработавший ключ —
   это то, о человеке надо сказать, а не мимо него проскочить.
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
    var host = null;

    function getHost() {
        if (host && host.isConnected) {
            return host;
        }

        host = document.createElement('div');
        host.className = 'toast-host';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.appendChild(host);

        return host;
    }

    /**
     * Показать сообщение.
     *
     * @param {string} message Текст
     * @param {string} variant 'success' | 'error' | 'warning' | 'info'
     * @param {number} duration Сколько живёт, миллисекунды. Отказ держится
     *                          вдвое дольше обычного: его успевают прочесть.
     */
    function showToast(message, variant, duration) {
        if (!message) {
            return null;
        }

        var kind = VARIANTS.indexOf(variant) === -1 ? 'default' : variant;
        var node = document.createElement('div');

        node.className = kind === 'default' ? 'toast' : 'toast toast-' + kind;
        node.textContent = message;
        getHost().appendChild(node);

        // Отрисовка заранее, иначе переход появления не сработает: браузер
        // не анимирует элемент, который только что появился.
        node.getBoundingClientRect();
        node.classList.add('show');

        function dismiss() {
            node.classList.remove('show');
            node.addEventListener('transitionend', function () { node.remove(); }, { once: true });
        }

        if (kind === 'error') {
            node.style.cursor = 'pointer';
            node.addEventListener('click', dismiss);
        } else {
            setTimeout(dismiss, duration || 2600);
            node.addEventListener('click', dismiss);
        }

        return node;
    }

    window.myEduToast = showToast;
}());