/* ==========================================================================
   Боковое меню и переключатель темы.

   Живут в файле, а не прямо в разметке: так страницу можно отдавать под
   Content-Security-Policy без unsafe-inline и nonce. Иначе политику пришлось
   бы ослаблять целиком — то есть не ставить вовсе.

   Оба состояния помнятся в localStorage: человек возвращается на сайт и
   получает тот вид, который оставил. При запрете хранилища скрипт просто не
   ничего не сохраняет — страница при этом остаётся в состоянии по умолчанию,
   то есть рабочей.

   Механизм тот же, что в шаблоне: на body вешается класс sidebar-rail, а
   подписи пунктов кладутся в data-rail-label, из которых CSS рисует
   подсказку на свёрнутом меню.
   ========================================================================== */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ меню */

    var RAIL_KEY = 'myedu:sidebar-rail';
    var THEME_KEY = 'myedu-theme';

    function readStore(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function writeStore(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Состояние не сохранилось — на текущей странице это не мешает.
        }
    }

    function isDesktop() {
        return window.matchMedia('(min-width: 769px)').matches;
    }

    function bindSidebar() {
        var toggle = document.querySelector('.sidebar-toggle');
        var sidebar = document.getElementById('sidebar');

        if (!toggle || !sidebar) {
            return;
        }

        /*
         * Подписи кладутся в data-rail-label всегда, а не только в свёрнутом
         * виде: иначе при первом сворачивании подсказки появились бы с задержкой
         * в один клик, и наведение на пункт показывало бы пустоту.
         */
        sidebar.querySelectorAll('.nav-link').forEach(function (link) {
            var text = link.querySelector('.nav-text');

            if (text) {
                link.setAttribute('data-rail-label', text.textContent.trim());
            }
        });

        function setRail(on) {
            document.body.classList.toggle('sidebar-rail', on);
            toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
            writeStore(RAIL_KEY, on ? '1' : '0');
        }

        function setDrawer(open) {
            document.body.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            /*
             * На узком экране меню — выдвижная панель, на широком — узкая
             * колонка со значками. Одна кнопка делает и то и другое, потому что
             * человек жмёт её в одном и том же месте.
             */
            if (isDesktop()) {
                setRail(!document.body.classList.contains('sidebar-rail'));
            } else {
                setDrawer(!document.body.classList.contains('sidebar-open'));
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                setDrawer(false);
                sidebar.focus();
            }
        });

        document.addEventListener('click', function (event) {
            if (!document.body.classList.contains('sidebar-open')) {
                return;
            }

            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                setDrawer(false);
            }
        });

        // Состояние применяется сразу, до первого клика: иначе меню было бы
        // развёрнуто при загрузке и сворачивалось уже после нажатия.
        if (readStore(RAIL_KEY) === '1' && isDesktop()) {
            document.body.classList.add('sidebar-rail');
        }

        toggle.setAttribute('aria-expanded', isDesktop() ? 'true' : 'false');
    }

    /* ------------------------------------------------------------------- тема */

    function bindTheme() {
        var button = document.querySelector('[data-theme-toggle]');

        if (!button) {
            return;
        }

        function apply(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        }

        // Приводим кнопку в соответствие с темой, которую поставил скрипт в шапке.
        apply(document.documentElement.getAttribute('data-theme') || 'dark');

        button.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';

            apply(next);
            writeStore(THEME_KEY, next);
        });
    }

    bindSidebar();
    bindTheme();
}());