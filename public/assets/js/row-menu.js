// Приподнимает меню строки над обрезкой таблицы.
//
// Меню построено на <details>, поэтому работает без скриптов: при раскрытии
// браузер показывает содержимое сам. Скрипт нужен только для одной вещи —
// обвести обрезку. Таблица лежит в .table-responsive с overflow-x: auto, а
// прокручиваемый контейнер обрезает всё, что выходит за его края: меню у
// нижних строк просто не было бы видно.
//
// Скрипт переносит раскрытое меню в position: fixed и ставит координаты по
// кнопке — так его нечему обрезать. Без скрипта меню остаётся на своём месте и
// открывается внутри строки: чуть длиннее, зато наверняка.

const OPEN_MARGIN = 6;
const EDGE = 8;
const VIEWPORT_GAP = 8;

function closeAll(except) {
  document.querySelectorAll('details.row-menu[open]').forEach((menu) => {
    if (menu !== except) {
      menu.open = false;
    }
  });
}

/**
 * Поставить раскрытое меню рядом с его кнопкой, не давая ему уйти за край
 * окна. Меню у нижних строк открывается вверх, у верхних — вниз.
 */
function position(menu) {
  const summary = menu.querySelector(':scope > summary');
  const popover = menu.querySelector(':scope > .menu-popover');

  if (!summary || !popover) {
    return;
  }

  // Меню должно получить размеры до того, как считать координаты.
  popover.classList.add('is-positioned');
  popover.style.top = '0px';
  popover.style.left = '0px';
  popover.style.visibility = 'hidden';

  const rect = summary.getBoundingClientRect();
  const width = popover.offsetWidth;
  const height = popover.offsetHeight;

  let top = rect.bottom + OPEN_MARGIN;

  if (top + height > window.innerHeight - VIEWPORT_GAP) {
    const above = rect.top - height - OPEN_MARGIN;
    top = above > EDGE ? above : Math.max(EDGE, window.innerHeight - height - VIEWPORT_GAP);
  }

  let left = rect.right - width;
  left = Math.max(EDGE, Math.min(left, window.innerWidth - width - EDGE));

  popover.style.top = `${Math.round(top)}px`;
  popover.style.left = `${Math.round(left)}px`;
  popover.style.visibility = '';
}

function reset(popover) {
  if (!popover) {
    return;
  }

  // Снимается всё, что ставил скрипт: без этого закрытое меню осталось бы
  // плавающим и уехавшим из своего места на следующей странице.
  popover.classList.remove('is-positioned');
  popover.style.top = '';
  popover.style.left = '';
  popover.style.visibility = '';
}

function init() {
  document.querySelectorAll('details.row-menu').forEach((menu) => {
    const popover = menu.querySelector(':scope > .menu-popover');

    menu.addEventListener('toggle', () => {
      if (menu.open) {
        closeAll(menu);
        position(menu);
      } else {
        reset(popover);
      }
    });

    // Клик по пункту закрывает меню: пункт — это форма или ссылка, и после
    // отправки страница уедет сама, но при отмене подтверждения удаления
    // страница останется, а открытое меню рядом будет выглядеть забытым.
    menu.addEventListener('click', (event) => {
      if (event.target.closest('.menu-item')) {
        menu.open = false;
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    const open = document.querySelector('details.row-menu[open]');

    if (!open) {
      return;
    }

    open.open = false;
    open.querySelector(':scope > summary').focus();
  });

  document.addEventListener('click', (event) => {
    const open = document.querySelector('details.row-menu[open]');

    if (open && !open.contains(event.target)) {
      open.open = false;
    }
  });

  window.addEventListener('resize', () => {
    const open = document.querySelector('details.row-menu[open]');

    if (open) {
      position(open);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}