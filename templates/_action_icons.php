<?php

declare(strict_types=1);

/**
 * Значки действий в строках и кнопках.
 *
 * Отдельная карта, а не дополнение к `templates/_icon.php`: там значки шаблона
 * gentelella — сетка 24×24, толщина линии 1.5, класс `icon`. Здесь свои
 * контуры: сетка 20×20 и 16×16, толщина 1.6. Смешаны в одной карте, они
 * разошлись бы по толщине линии, и панель выглядела бы собранной из двух
 * наборов — ровно то, чего карта значков меню и не допускает.
 *
 * Зачем карта: пять значков повторялись в шести шаблонах по два-три раза.
 * Копия значка — это копия его начертания: исправили в одном месте, в
 * остальных осталось старое, и кнопки в разных таблицах выглядели по-разному.
 * Здесь начертание одно, и правка вносится в него.
 *
 * Размер значка задаёт оформление кнопки, а не атрибуты: `.icon-btn svg` — 18
 * пикселей, `.card-opt-btn svg` — 14. Атрибуты `width`/`height` у значка
 * `close` заданы отдельно: кнопка закрытия окна своего правила размера не
 * имеет, и без них крестик был бы вдвое крупнее остальных.
 *
 * Ожидает: $icon — имя значка.
 *
 * @var string $icon
 */

return [
    'edit' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M13.5 3.5 16.5 6.5 7 16H4v-3z"/><path d="M12 5l3 3"/></svg>',
    'copy' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 4h9l3 3v9H4z"/><path d="M7 4v4h6V4"/><path d="M7 16v-4h6v4"/></svg>',
    'cancel' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 6l8 8M14 6l-8 8"/></svg>',
    'more' => '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="8" cy="3" r="1.2"/><circle cx="8" cy="8" r="1.2"/><circle cx="8" cy="13" r="1.2"/></svg>',
    'close' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
];
