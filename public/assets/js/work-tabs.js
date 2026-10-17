/*
 * Вкладки редактора работы.
 *
 * Переключение происходит на месте, без перезагрузки: у редактора одна форма
 * на четыре вкладки, и перезагрузка страницы при переходе между вкладками
 * означала бы, что человек либо потеряет несохранённое, либо будет бояться
 * щелкать по соседним вкладкам вообще.
 *
 * Проверка формы при переключении не выполняется намеренно. Вкладка ничего не
 * сохраняет, и требовать заполнить скрытые поля перед тем, как посмотреть
 * соседнюю вкладку, значит запрещать смотреть: браузер показал бы ошибку в
 * поле, которого на экране нет. Проверка случается один раз — при нажатии
 * «Сохранить», и тогда предъявляются все поля сразу.
 *
 * Ссылки у вкладок настоящие: адрес ведёт на ту же страницу с `?tab=`, и без
 * этого файла переключение работает через перезагрузку. Здесь щелчок
 * перехватывается, адрес заменяется на `replaceState` — иначе «Назад» после
 * просмотра трёх вкладок проходил бы по трём адресам, которых человек не
 * открывал.
 */
(function () {
  'use strict';

  var nav = document.querySelector('.work-tabs');
  var panes = Array.prototype.slice.call(document.querySelectorAll('.work-tabs__pane'));

  if (nav === null || panes.length === 0) {
    return;
  }

  var tabs = Array.prototype.slice.call(nav.querySelectorAll('[data-work-tab]'));

  function paneFor(key) {
    for (var i = 0; i < panes.length; i++) {
      if (panes[i].id === 'work-pane-' + key) {
        return panes[i];
      }
    }

    return null;
  }

  /*
   * Фокус на самой вкладке, а не на первом поле панели: человек щёлкнул по
   * вкладке, и курсор он ждёт на вкладке. Если бы фокус прыгал в поле,
   * переключение выглядело бы как самопроизвольный переход к правке.
   */
  function select(key, focus) {
    var pane = paneFor(key);

    if (pane === null) {
      return;
    }

    panes.forEach(function (item) {
      item.classList.remove('active');
    });

    tabs.forEach(function (item) {
      var active = item.getAttribute('data-work-tab') === key;

      item.classList.toggle('active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');

      if (active) {
        item.setAttribute('aria-current', 'page');
      } else {
        item.removeAttribute('aria-current');
      }
    });

    pane.classList.add('active');

    if (typeof window.history === 'object' && window.history !== null && window.history.replaceState) {
      var url = new URL(window.location.href);

      url.searchParams.set('tab', key);
      window.history.replaceState(window.history.state, '', url.toString());
    }

    if (focus === true) {
      var tab = nav.querySelector('[data-work-tab="' + key + '"]');

      if (tab !== null) {
        tab.focus();
      }
    }
  }

  /*
   * Ошибка проверки в скрытой панели открывает эту панель.
   *
   * Форма одна на четыре вкладки, и кнопка «Сохранить» стоит над вкладками.
   * Случай запрошенным полем на скрытой панели — обычное дело: человек поправил
   * название, ушёл на шкалу и нажал «Сохранить». Браузер остановит отправку и
   * напишет прямо на невидимом поле: подсказка либо не появится вовсе, либо
   * появится там, где её не видно, и человек будет гадать, что не так.
   *
   * Слушатель с перехватом: `invalid` не всплывает, а вешать его на саму панель
   * или на поле бессмысленно — событие приходит на каждый неверный элемент
   * отдельно.
   */
  var form = document.getElementById('work-editor-form');

  if (form !== null) {
    form.addEventListener('invalid', function (event) {
      var field = event.target;
      var pane = field !== null && typeof field.closest === 'function'
        ? field.closest('.work-tabs__pane')
        : null;

      if (pane !== null && !pane.classList.contains('active')) {
        select(pane.id.replace('work-pane-', ''), false);
      }
    }, true);
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (event) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return;
      }

      event.preventDefault();
      select(tab.getAttribute('data-work-tab'), true);
    });
  });

  /*
   * Стрелки переключают вкладки, как на вкладках вообще: иначе до каждой
   * вкладки нужно было бы дойти табом через четыре ссылки, и вкладки читались
   * бы четырьмя отдельными местами, а не одним.
   */
  nav.addEventListener('keydown', function (event) {
    var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);

    if (step === 0) {
      return;
    }

    var current = tabs.indexOf(document.activeElement);

    if (current < 0) {
      return;
    }

    var next = (current + step + tabs.length) % tabs.length;

    event.preventDefault();
    select(tabs[next].getAttribute('data-work-tab'), true);
  });
})();