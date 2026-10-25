/* ==========================================================================
   Боковое меню: свёрнутая колонка и сворачиваемые разделы.

   Живёт в файле, а не прямо в разметке: так страницу можно отдавать под
   Content-Security-Policy без unsafe-inline и nonce. Иначе политику пришлось
   бы ослаблять целиком — то есть не ставить вовсе.

   Два состояния, обе — в cookie:

   1. Свёрнутая колонка (`myedu-sidebar-rail`) — узкая полоска значков.
      Cookie пишет скрипт при нажатии, а читает сервер при отрисовке и
      сразу рисует меню в нужном виде — страница не мигает развёрнутым
      меню. Имя cookie то же, что константой в `MyEdu\Http\Kernel`.

   2. Сворачиваемые разделы (`myedu-nav-works`, `-school`, `-system`) —
      решение владельца от 24.10.2026 (задача 1). Состояние каждого раздела
      своё: свёрнутая «Школа» не разворачивает «Систему». Пока человек
      раздел не нажимал, cookie нет и сервер применяет дефолт: «Работы»
      открыты, «Школа» и «Система» свёрнуты. Ключи групп и префикс имён —
      те же, что `PanelNav` и `Kernel`, — менять их нужно в трёх местах.

   Тема в этот файл больше не входит: выбор темы живёт в профиле человека
   (решение владельца от 24.10.2026), сервер ставит его в разметку, а
   сохраняется оно формой на странице профиля.

   Механизм сворачивания колонки прежний: на body вешается класс
   sidebar-rail, а подписи пунктов кладутся в data-rail-label, из которых
   CSS рисует подсказку на свёрнутом меню.
   ========================================================================== */

(function () {
    'use strict';

    /* ------------------------------------------------- служебные cookie */

    /*
     * Cookie одним чтением на загрузку: регулярка ищет имя в начале строки
     * или после «; » — иначе «myedu-sidebar-rail» совпало бы с чужим именем,
     * которым оно начинается.
     */
    function readCookie(name) {
        var found = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));

        return found ? decodeURIComponent(found[1]) : null;
    }

    function writeCookie(name, value) {
        /*
         * Год и путь=/ — состояние переживает перезагрузку и действует на
         * всех страницах. SameSite=Lax: cookie своя, чужой сайтовой запрос
         * её не шлёт. Secure не ставится — локальный сервер и разработка
         * ходят по http, а при https браузер добавляет его сам.
         */
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; path=/; max-age=31536000; SameSite=Lax';
    }

    function isDesktop() {
        return window.matchMedia('(min-width: 769px)').matches;
    }

    /* ------------------------------------------------- свёрнутая колонка */

    function bindSidebar() {
        var toggle = document.querySelector('.sidebar-toggle');
        var sidebar = document.getElementById('sidebar');
        var backdrop = document.querySelector('.sidebar-backdrop');
        var narrow = window.matchMedia('(max-width: 768px)');

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
            writeCookie('myedu-sidebar-rail', on ? '1' : '0');
        }

        /*
         * Выдвижная панель на узком экране. Открытие по CSS делает класс
         * `open` на самом меню, и без него панель остаётся за экраном.
         * Класс `sidebar-open` на body нужен отдельно: он блокирует прокрутку
         * страницы под открытой панелью.
         *
         * Раньше скрипт ставил только `sidebar-open`: меню не открывалось
         * вовсе, а прокрутка исчезала — страница на кнопку не реагировала, и
         * после нажатия её нельзя было прокрутить.
         */
        function setDrawer(open) {
            sidebar.classList.toggle('open', open);
            document.body.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (backdrop) {
                backdrop.hidden = !open;
            }
        }

        toggle.addEventListener('click', function () {
            /*
             * На узком экране меню — выдвижная панель, на широком — узкая
             * колонка со значками. Одна кнопка делает и то и другое, потому что
             * человек жмёт её в одном и том же месте.
             */
            if (isDesktop()) {
                setRail(!document.body.classList.contains('sidebar-rail'));

                return;
            }

            var open = !document.body.classList.contains('sidebar-open');

            setDrawer(open);

            // Фокус уходит в меню: с клавиатуры пункты достижимы сразу, и
            // Escape возвращает его на кнопку, которая меню открыла.
            if (open) {
                sidebar.focus();
            }
        });

        /*
         * Поворот телефона: экран стал шире, панель перестала быть выдвижной,
         * а состояние «открыто» осталось бы — прокрутка страницы была бы
         * заблокирована уже там, где меню видно и так.
         */
        if (typeof narrow.addEventListener === 'function') {
            narrow.addEventListener('change', function () {
                if (!narrow.matches) {
                    setDrawer(false);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                setDrawer(false);
                toggle.focus();
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

        /*
         * Состояние при загрузке ставит сервер по той же cookie, поэтому
         * скрипт его не переставляет — он лишь приводит aria-метку в
         * соответствие с уже нарисованной страницей: «развёрнуто» ровно там,
         * где колонка стоит на всю ширину.
         */
        var railNow = document.body.classList.contains('sidebar-rail');

        toggle.setAttribute('aria-expanded', isDesktop() && !railNow ? 'true' : 'false');
    }

    /* -------------------------------------------------- сворачиваемые разделы */

    function bindNavGroups() {
        document.querySelectorAll('[data-nav-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var group = button.closest('.nav-group');

                if (!group) {
                    return;
                }

                /*
                 * Состояние — класс на самой группе: CSS прячет пункты по
                 * нему же, поэтому сервер и скрипт говорят одним языком и
                 * после нажатия страница выглядит так же, как отрисованная
                 * сервером с этой cookie.
                 */
                var closed = group.classList.toggle('nav-group--closed');

                button.setAttribute('aria-expanded', closed ? 'false' : 'true');

                var key = button.getAttribute('data-nav-toggle');

                if (key) {
                    writeCookie('myedu-nav-' + key, closed ? '1' : '0');
                }
            });
        });
    }

    bindSidebar();
    bindNavGroups();
}());
