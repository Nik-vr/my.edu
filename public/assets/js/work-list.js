/*
 * Удаление работы без перезагрузки страницы.
 *
 * Зачем: список работ бывает длинным, и после удаления работы человек
 * оказывался в начале страницы, потерял место и решил, что пропало больше,
 * чем он нажал.
 *
 * Почему ссылка, а не форма: без скриптов ссылка ведёт на страницу
 * подтверждения, и работа удаляется только после явного нажатия. Форма
 * отправляла бы сразу, и один Enter в браузере снёс бы работу с чужими
 * результатами.
 *
 * Подтверждение то же, что и везде: окно спрашивает, куда делись бы задания и
 * результаты. Здесь окно вызывается из скрипта, потому что отправлять надо
 * не формой, а данными.
 */
(function () {
    'use strict';

    var list = document.querySelector('[data-work-list]');

    if (list === null || !window.fetch || !window.MyEduConfirm) {
        return;
    }

    /*
     * Ошибка сервера — это ответ, а не сбой. Например, работа удаляется
     * вместе с попытками учеников, а плагин не смог убрать свои данные: сервер
     * отвечает «нельзя» и объясняет почему. Показать это надо рядом со
     * строкой, а не в консоли.
     */
    function report(row, message) {
        var note = row.querySelector('[data-work-delete-error]');

        if (note === null) {
            note = document.createElement('span');
            note.className = 'work-row__delete-error';
            note.setAttribute('data-work-delete-error', '');
            note.setAttribute('role', 'alert');
            row.appendChild(note);
        }

        note.textContent = message;
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? (meta.getAttribute('content') || '') : '';
    }

    list.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-work-delete]') : null;

        if (link === null) {
            return;
        }

        event.preventDefault();

        var row = link.closest('[data-work-row]');
        var url = link.getAttribute('href');

        if (row === null || !url) {
            return;
        }

        window.MyEduConfirm.open({
            title: link.getAttribute('data-confirm') || 'Подтвердите действие',
            body: link.getAttribute('data-confirm-body') || '',
            confirm: link.getAttribute('data-confirm-yes') || 'Удалить'
        }).then(function (yes) {
            if (!yes) {
                return;
            }

            link.classList.add('is-busy');

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: '_token=' + encodeURIComponent(token()),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok && data.ok !== false, data: data };
                }).catch(function () {
                    return { ok: false, data: { message: 'Сервер ответил не так, как ожидалось' } };
                });
            }).then(function (result) {
                link.classList.remove('is-busy');

                if (!result.ok) {
                    report(row, result.data.message || 'Удалить не удалось');

                    return;
                }

                /*
                 * Строка убрана, а не перерисована: перерисовка сортировала бы
                 * список заново, и человек терял бы место, ради которого
                 * удаление и делалось без перезагрузки.
                 */
                row.remove();

                /*
                 * Без сообщения человек не увидит результата: строка исчезла,
                 * и он не знает, удалилось ли то, что он нажал, или вместе с
                 * ней пропало что-то ещё. Тот же текст, что и при перезагрузке
                 * страницы, — но всплывающим сообщением.
                 */
                if (window.myEduToast) {
                    window.myEduToast('Работа удалена вместе с попытками', 'success');
                }
            }).catch(function () {
                link.classList.remove('is-busy');
                report(row, 'Связь с сервером прервалась');
            });
        });
    });
}());
