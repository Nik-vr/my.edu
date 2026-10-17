/* ==========================================================================
   Открытие всплывающих окон по кнопке.

   Окно лежит в разметке и без скриптов просто показано: без него форма
   добавления была бы недостижима, а добавление предмета — обычное дело, ради
   которого не стоит требовать JavaScript.

   Скрипт прячет окно и открывает его по кнопке. Прячет при первом же касании
   разметки, а не по кнопке: иначе между загрузкой страницы и нажатием окно
   мелькнуло бы на весь экран.

   Закрывается крестиком, щелчком по фону и Escape — тем же, чем и окно
   подтверждения, но своим кодом: то правило закрывает окно, которого на
   странице ещё нет, и ждать его появления здесь незачем.

   Живёт в файле, а не в разметке: политика безопасности отдаётся с
   script-src 'self', и код прямо в странице был бы заблокирован.
   ========================================================================== */

(function () {
    'use strict';

    var OPENED = 'modal--open';

    /*
     * Где окно лежало до открытия.
     *
     * Открытие переносит окно под подложку, а закрытие убирает подложку — и
     * вместе с ней окно. Оно исчезает из страницы навсегда: кнопка открытия
     * ищет окно по идентификатору и не находит его, поэтому второе открытие
     * не работает. Здесь запоминается место, куда окно надо вернуть.
     */
    var origin = new WeakMap();

    /*
     * Передать фокус, не двигая страницу.
     *
     * Окно открывается как оверлей, и страница под ним никуда не девается:
     * автор правит одно задание и после закрытия должен оказаться ровно там,
     * где оставил доску. Обычный `focus()` прокручивает страницу к тому, на
     * чём фокус оказался: при открытии — к кнопке закрытия, при закрытии — к
     * кнопке, которая окно открыла, а она нередко стоит внизу доски. Обе
     * страницы выглядели бы как «окно утащило список вниз» и «окно вернуло
     * список наверх».
     *
     * Отдельная страховка на старые браузеры не нужна: `preventScroll` они
     * просто проигнорируют, и страница поведёт себя как раньше, — хуже, чем
     * было до появления `preventScroll`, но не хуже, чем без этой строки.
     */
    function focusQuietly(element) {
        element.focus({ preventScroll: true });
    }

    function rememberPlace(modal) {
        if (!origin.has(modal) && modal.parentNode) {
            origin.set(modal, { parent: modal.parentNode, before: modal.nextSibling });
        }
    }

    function returnPlace(modal) {
        var place = origin.get(modal);

        if (!place || !place.parent) {
            return;
        }

        var before = place.before && place.before.parentNode === place.parent ? place.before : null;

        place.parent.insertBefore(modal, before);
    }

    function backdropFor(modal) {
        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.hidden = false;
        backdrop.setAttribute('data-modal-backdrop', '');

        /*
         * Шаблон уже приносит свой .modal-dialog: он держит рамку, скругление
         * и ограничение по высоте. Раньше скрипт создавал ещё один и клал его
         * сверху, из-за чего окно оказывалось вложенным в себя — рамка внутри
         * рамки, два ограничения высоты вместо одного. Поэтому новый диалог
         * заводится только когда в разметке его нет: старый шаблон без
         * .modal-dialog всё ещё должен получить оформление.
         *
         * Вкладывать сам modal в его же собственного потомка нельзя — браузер
         * откажется строить такую иерархию и окно просто не откроется.
         */
        var holder = modal;

        if (!modal.querySelector('.modal-dialog')) {
            holder = document.createElement('div');
            holder.className = 'modal-dialog';
            holder.setAttribute('role', 'dialog');
            holder.setAttribute('aria-modal', 'true');
            holder.appendChild(modal);
        }

        backdrop.appendChild(holder);

        return backdrop;
    }

    function close(modal) {
        var backdrop = document.querySelector('[data-modal-backdrop]');

        /*
         * Окно возвращается на своё место сразу, а не после исчезновения
         * подложки: подложка снимается через 200 мин ради анимации, и всё это
         * время кнопка открытия не должна находить страницу без окна.
         */
        returnPlace(modal);

        if (backdrop) {
            // Окно уходит с экрана раньше, чем исчезает из разметки: у шаблона
            // есть своя анимация появления, и без .show окно просто исчезает.
            backdrop.classList.remove('show');
        }

        if (backdrop) {
            setTimeout(function () {
                if (backdrop.parentNode) {
                    backdrop.remove();
                }
            }, 200);
        }

        document.body.classList.remove('modal-open');
        modal.hidden = true;

        var opener = modal.getAttribute('data-modal-opener');

        if (opener) {
            var button = document.getElementById(opener);

            if (button) {
                focusQuietly(button);
            }
        }
    }

    function open(modal) {
        if (modal.hidden === false) {
            return;
        }

        rememberPlace(modal);

        var backdrop = backdropFor(modal);

        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');
        modal.hidden = false;

        requestAnimationFrame(function () {
            backdrop.classList.add('show');
        });

        var closeButton = modal.querySelector('[data-modal-close]');
        var field = modal.querySelector('input, select, textarea');

        if (closeButton) {
            focusQuietly(closeButton);
        } else if (field) {
            focusQuietly(field);
        }
    }

    function init() {
        var modals = Array.prototype.slice.call(document.querySelectorAll('[data-modal]'));

        if (!modals.length) {
            return;
        }

        // Атрибут ставится до правок разметки: он означает «скрипт дошёл».
        document.documentElement.setAttribute('data-js', '');

        /*
         * Отдельная отметка — «окна обслуживает скрипт».
         *
         * `data-js` ставится уже в шапке, и этого мало: правило показа окна в
         * theme.css смотрит именно на эту отметку и на отсутствие `hidden`.
         * Отметка ставится здесь, то есть уже после того, как окна спрятаны
         * строкой ниже, — и с этого момента показом окна управляет только
         * атрибут `hidden`. До неё окно невидимо и не занимает место, иначе
         * страница с формой задания раздувалась бы до запуска скрипта и
         * браузер уводил бы прокрутку вниз.
         */
        modals.forEach(function (modal) {
            modal.hidden = true;
        });

        document.documentElement.setAttribute('data-modal-ready', '');

        /*
         * Окно, помеченное data-open-on-load, открывается сразу.
         *
         * Так возвращается форма, от которой сервер отказался: человек приходит
         * на страницу, видит открытое окно с тем, что он уже набрал, и правит
         * одну ошибку вместо того, чтобы начинать заново.
         */
        var onLoad = document.querySelector('[data-modal][data-open-on-load]');

        if (onLoad) {
            open(onLoad);
        }

        document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-modal-open]');

            if (opener) {
                var target = document.getElementById(opener.getAttribute('data-modal-open'));

                if (target) {
                    event.preventDefault();
                    target.setAttribute('data-modal-opener', opener.id || '');
                    open(target);
                }

                return;
            }

            var closer = event.target.closest('[data-modal-close]');

            if (closer) {
                var current = closer.closest('[data-modal]');

                if (current) {
                    event.preventDefault();
                    close(current);
                }

                return;
            }

            if (event.target.hasAttribute('data-modal-backdrop')) {
                var openModal = document.querySelector('[data-modal]:not([hidden])');

                if (openModal) {
                    close(openModal);
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            var openModal = document.querySelector('[data-modal]:not([hidden])');

            if (openModal) {
                close(openModal);
            }
        });
    }

    /*
     * Открыть и закрыть окно из другого скрипта.
     *
     * Окно редактора задания наполняется по данным и открывается без перезагрузки
     * страницы, а сам модуль про содержимое ничего не знает. Копия `open()` ради
     * этого была бы второй правкой одного и того же окна: подложка, анимация и
     * возврат фокуса живут здесь, и копия рано или поздно разошлась бы с ними.
     */
    window.MyEduModal = { open: open, close: close };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());