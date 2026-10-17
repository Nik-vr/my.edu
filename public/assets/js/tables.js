/* ==========================================================================
   Таблицы: поиск, сортировка, постраничная выдача, выделение строк.

   Обвязка та же, что в шаблоне gentelella (v4/tables.js), но со списками на
   русском: в шаблоне строки интерфейса английские, а таблицей пользуется
   учитель.

   Библиотека подключается модулем и только ради этого файла. Модуль браузер
   не выполняет на старых версиях, и тогда остаётся серверная таблица целиком
   — то есть без поиска и сортировки, но вполне читаемая. Это осознанно:
   данные приходят с сервера уже готовыми, и без скриптов они никуда не
   деваются.

   Своей постраничности на сервере у списков нет, и это позволяет отдать её
   библиотеке: она режет уже пришедшие строки, а не требует нового адреса
   для каждой таблицы.

   Живёт в файле, а не в разметке: Content-Security-Policy отдаётся с
   script-src 'self', и инлайн-код заблокирован.
   ========================================================================== */

import DataTable from '../vendor/datatables/dataTables.min.mjs';

/*
 * Русские подписи. Списком, а не по одному ключу: DataTables требует весь
 * набор целиком, иначе строки поиска и пагинации остаются английскими.
 */
const LANGUAGE = {
    search: 'Поиск:',
    searchPlaceholder: 'Найти в таблице…',
    lengthMenu: 'Показать _MENU_ записей',
    info: 'Записи _START_–_END_ из _TOTAL_',
    infoEmpty: 'Ничего не найдено',
    infoFiltered: '(отфильтровано из _MAX_)',
    zeroRecords: 'Ничего не найдено',
    paginate: { previous: '← Назад', next: 'Вперёд →' },
    aria: { orderBy: ':sort' }
};

/**
 * Порядок сортировки по умолчанию берётся у сервера и не меняется.
 *
 * Порядок в списках не случаен: у дерева работ он отражает структуру, у
 * журнала — ход времени. Библиотека по умолчанию сортирует по первому
 * столбцу, и такие списки молча переставились бы.
 */
function optionsFor(table) {
    return {
        pageLength: parseInt(table.dataset.pageLength || '10', 10),
        lengthChange: false,
        order: [],
        language: LANGUAGE,
        columnDefs: Array.from(table.querySelectorAll('thead th')).reduce((defs, th, i) => {
            if (th.dataset.orderable === 'false') {
                defs.push({ targets: i, orderable: false });
            }

            return defs;
        }, [])
    };
}

/**
 * Выделение строк по флажкам.
 *
 * Флажок в шапке отмечает все видимые строки и только их: отмечать скрытое
 * поиском было бы ловушкой — человек отметил бы то, чего не видит.
 */
function wireSelection(table, api) {
    const boxes = Array.from(table.querySelectorAll('input[type="checkbox"][data-select-item]'));
    const master = table.querySelector('input[type="checkbox"][data-select-all]');

    if (!boxes.length) {
        return;
    }

    function visible() {
        return boxes.filter((box) => box.closest('tr').offsetParent !== null);
    }

    function refresh() {
        const shown = visible();
        const checked = shown.filter((box) => box.checked).length;

        if (master) {
            master.checked = checked > 0 && checked === shown.length;
            master.indeterminate = checked > 0 && checked < shown.length;
        }

        // Подсветка строки — по правилам шаблона: строка с отмеченной
        // отметкой подсвечивается, чтобы взгляд не искал галочку по таблице.
        shown.forEach((box) => {
            const row = box.closest('tr');

            if (row) {
                row.classList.toggle('has-selection', box.checked);
            }
        });
    }

    boxes.forEach((box) => {
        box.addEventListener('change', refresh);
    });

    if (master) {
        master.addEventListener('change', function () {
            visible().forEach((box) => { box.checked = master.checked; });
            refresh();
        });
    }

    // Поиск и постраничная выдача меняют набор видимых строк.
    if (api && typeof api.on === 'function') {
        api.on('draw', refresh);
    }

    refresh();
}

function init() {
    document.querySelectorAll('table[data-datatable]').forEach(function (table) {
        if (table.dataset.dtReady === '1') {
            return;
        }

        table.dataset.dtReady = '1';

        const api = new DataTable(table, optionsFor(table));

        wireSelection(table, api);

        // Поиск DataTables выходит без доступного имени: читающий с экрана
        // услышал бы поле без подписи.
        const input = table.closest('.dt-container')?.querySelector('.dt-search input');

        if (input && !input.getAttribute('aria-label')) {
            input.setAttribute('aria-label', 'Поиск по таблице');
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}