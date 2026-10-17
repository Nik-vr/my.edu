/* ==========================================================================
   Правка значения прямо в строке таблицы.

   Так работает столбец «ФИО» на странице класса и столбец «Предмет» на
   странице предметов: по умолчанию в ячейке текст и карандаш, а по нажатию
   карандаша вместо текста появляется поле и кнопка «Сохранить».

   Открытие поля осталось серверным: карандаш ведёт на адрес с ?edit=<номер>, и
   приехавшая страница сама решает, у кого поле открыто. Здесь только последний
   шаг — отправка, потому что именно он уводил человека с таблицы двадцати
   пяти строк к началу страницы: ради правки одного значения приходилось
   прокручивать её заново.

   Если скрипт не доехал или сеть отвалилась, отправка уходит обычным POST —
   правка сохранится и там. Поэтому поле открывается и сохраняется без
   JavaScript, а скрипт только убирает перезагрузку.

   Имена здесь общие, а не «студенческие»: правило одно и для имени, и для
   названия предмета, а с двумя почти одинаковыми скриптами одно из них
   рано или поздно перестало бы догонять другое.

   Живёт в файле, а не в разметке: так страницу можно отдавать под
   Content-Security-Policy без unsafe-inline и nonce.
   ========================================================================== */

(function () {
    'use strict';

/*
 * Показать, в каком поле автор ошибся.
 *
 * Без отметки человек читает «имя не может быть пустым» и ищет, где именно: в
 * правке одного значения поле одно, но при отказе сервера по другой причине
 * (занятое имя, неверная дата) текст может относиться к самой правке и ни к
 * одному видимому полю. Красная рамка снимает вопрос, а не украшение: после
 * исправления снята, иначе исправленное поле осталось бы красным до следующей
 * попытки.
 */
function markField(form, bad) {
    if (!form) {
        return;
    }

    form.querySelectorAll('.is-invalid').forEach(function (node) {
        node.classList.remove('is-invalid');
        node.removeAttribute('aria-invalid');
    });

    if (!bad) {
        return;
    }

    var input = form.querySelector('input:not([type="hidden"]), textarea, select');

    if (!input) {
        return;
    }

    input.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
    input.focus({ preventScroll: true });
}

/*
 * Сказать автору, чем закончилось сохранение.
 *
 * Всплывающим сообщением: строка в ячейке после успеха исчезает вместе с
 * формой, и сообщение уехало бы вместе с ней — человек остался бы с
 * молчанием и нажал бы вторую кнопку, переписав то же самое.
 *
 * Отказ остаётся в ячейке строкой и никуда не исчезает: форма при отказе не
 * закрывается, и человек должен видеть, что не сохранено, пока не исправит.
 */
function notify(cell, form, message, bad) {
    if (window.myEduToast) {
        window.myEduToast(message, bad ? 'error' : 'success');
    }

    markField(form, bad);

    if (!bad) {
        return;
    }

    var box = cell.querySelector('[data-inline-status]');

    if (!box) {
        box = document.createElement('p');
        box.setAttribute('data-inline-status', '');
        box.setAttribute('role', 'alert');
        cell.appendChild(box);
    }

    box.className = 'banner banner-danger inline-status';
    box.textContent = message;
}

    /*
     * Вернуть строку к обычному виду: значение, карандаш и подпись для чтения с
     * экрана.
     *
     * Разметка берётся из <template>, который приехал вместе с формой, и
     * собирать её вручную здесь означало бы держать одну и ту же разметку в
     * двух местах — в шаблоне и в скрипте. Второе место рано или поздно
     * разошлось бы с первым, и правка выглядела бы несохранившейся.
     *
     * Значение меняется уже на готовой разметке: сервер прислал то, что человек
     * уже ввёл, но в ответе оно нормализовано — с лишними пробелами
     * схлопнутыми.
     */
    function showValue(cell, value, editUrl, label) {
        var template = cell.querySelector('[data-inline-template]');

        if (!template) {
            return;
        }

        var block = template.content.cloneNode(true);
        var text = block.querySelector('.inline-value__text');
        var edit = block.querySelector('a.icon-btn');

        if (text) {
            text.textContent = value;
        }

        if (edit && editUrl) {
            edit.setAttribute('href', editUrl);
            edit.setAttribute('aria-label', 'Изменить: ' + value);
            edit.setAttribute('title', 'Изменить: ' + value);
        }

        if (label) {
            var caption = block.querySelector('[data-inline-label]');

            if (caption) {
                caption.textContent = label;
            }
        }

        var form = cell.querySelector('form[data-inline-form]');

        if (form) {
            form.remove();
        }

        template.remove();
        cell.appendChild(block);
    }

    function bindAjaxSave() {
        document.querySelectorAll('form[data-inline-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.fetch) {
                    return;
                }

                var cell = form.closest('.inline-cell');
                var input = form.querySelector('input[type="text"]');

                if (!cell || !input) {
                    return;
                }

                event.preventDefault();

                var button = form.querySelector('button[type="submit"]');
                var status = cell.querySelector('[data-inline-status]');

                if (status) {
                    status.remove();
                }

                if (button) {
                    button.disabled = true;
                }

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                }).then(function (response) {
                    /*
                     * Код ответа разбирается раньше разбора тела.
                     *
                     * Истёкшая сессия отвечает не данными, а страницей входа: её
                     * нельзя разобрать как JSON, и прежняя ветка отдавала форму
                     * обычным способом — то есть повторно слала правку на адрес,
                     * где её уже не ждали, и человек видел страницу ошибки вместо
                     * своего значения. Здесь отказ называют словами и оставляют
                     * поле открытым: введённое не потеряно.
                     */
                    var denied = response.status === 401 || response.status === 403 || response.status === 419;

                    if (denied || response.redirected) {
                        return { denied: true, data: { ok: false, error: denied
                            ? 'Сессия закончилась. Войдите заново — правка не потеряна.'
                            : 'Сервер перенаправил запрос. Войдите заново — правка не потеряна.' } };
                    }

                    return response.json().then(function (data) {
                        return { denied: false, data: data };
                    }).catch(function () {
                        // Ответ не JSON и не отказ: отдаём форму обычным способом.
                        form.submit();

                        return { denied: false, data: null };
                    });
                }).then(function (result) {
                    var data = result.data;

                    if (data === null) {
                        return;
                    }

                    if (!data.ok) {
                        if (button) {
                            button.disabled = false;
                        }

                        notify(cell, form, data.error || 'Не сохранено', true);

                        return;
                    }

                    /*
                     * Адрес возврата убирает ?edit= из строки браузера: без
                     * этого F5 вернул бы уже закрытое поле правки, и человек
                     * решил бы, что правка не закрылась.
                     */
                    if (data.url) {
                        window.history.replaceState(null, '', data.url);
                    }

                    /*
                     * Сохранённое значение берётся из ответа сервера: у имени это
                     * full_name, у предмета — name, а правило одно. Введённое
                     * текстом используется только если сервер ничего не прислал.
                     */
                    showValue(
                        cell,
                        data.name || data.full_name || input.value,
                        data.url || '',
                        form.getAttribute('data-inline-label')
                    );

                    markField(form, false);
                    notify(cell, form, form.getAttribute('data-inline-message') || 'Сохранено', false);
                }).catch(function () {
                    // Сеть не ответила: обычная отправка сохранит правку.
                    form.submit();
                });
            });
        });
    }

    bindAjaxSave();
}());