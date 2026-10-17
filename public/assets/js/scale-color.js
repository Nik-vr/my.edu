/* ==========================================================================
   Цвет оценки на шаге «Результат».

   Квадрат у списка и крупная цифра справа показывают сохранённый цвет.
   Скрипт подтягивает их на глаз при выборе нового: без него человек увидел бы
   старый цвет рядом с только что выбранным и решил бы, что выбор не сработал.

   Значение приходит списком, поэтому скрипту нечего проверять: цвет приводит
   к виду `--grade-*` только то, что сам сервер уже принял, — а всё, чего в
   палитре нет, приходит из базы уже приведённым к одному из четырёх.

   Без скриптов форма работает и показывает верный цвет: сохраняется он
   одинаково при любом режиме.
   ========================================================================== */

(function () {
    'use strict';

    var PALETTE = ['success', 'primary', 'warning', 'danger'];

    function apply(row, color) {
        var chip = row.querySelector('.scale-color__chip');
        var grade = row.querySelector('.scale-row__grade');

        PALETTE.forEach(function (name) {
            var suffix = '--' + name;

            if (chip) {
                chip.classList.toggle('scale-color__chip' + suffix, name === color);
            }

            if (grade) {
                grade.classList.toggle('scale-row__grade' + suffix, name === color);
            }
        });
    }

    document.querySelectorAll('.scale-row').forEach(function (row) {
        var select = row.querySelector('[data-color-select]');

        if (!select) {
            return;
        }

        select.addEventListener('change', function () {
            apply(row, select.value);
        });
    });

    /* ------------------------------------------------------------------ баллы */

    /*
     * Пересчёт процентов в баллы прямо при наборе порога.
     *
     * Автор задаёт границы в процентах, а ученик работает с баллами: без
     * пересчёта «75 процентов» выглядит оторванным от работы, и человек
     * выбирал бы пороги вслепую. Значение сразу, без кнопки: иначе подсказка
     * показывала бы устаревшее число и вводила бы в заблуждение сильнее, чем
     * её отсутствие.
     *
     * Формат строки приходит из шаблона, но разделитель дробной части —
     * нет: шаблон его не содержит, и раньше подсказка печаталась на сервере как
     * «25,5», а сразу после загрузки переписывалась скриптом в «25.5». Число
     * меняло вид на глазах у человека, который уже начал его читать. Правило
     * ниже — то же, что `GradeScaleValidator::formatNumber()`: запятая, без
     * хвостовых нулей.
     *
     * Половина балла, а не сотые, — по той же причине, что и на сервере:
     * порог задан процентами, а человек вкладывает в него целое число
     * баллов. «85% — 11,05 из 13 баллов» выглядит как расчёт машины и
     * обесценивает подсказку, ради которой она напечатана.
     */
    function number(value) {
        var rounded = Math.round(Number(value) * 100) / 100;

        return rounded.toFixed(2)
            .replace('.', ',')
            .replace(/,?0+$/, '')
            .replace(/,$/, '');
    }

    document.querySelectorAll('[data-hint-template]').forEach(function (input) {
        var hint = input.closest('.scale-field') && input.closest('.scale-field').querySelector('[data-scale-points]');

        if (!hint) {
            return;
        }

        var template = input.getAttribute('data-hint-template') || '';
        var total = parseFloat(input.getAttribute('data-total-value') || '0');
        var totalText = input.getAttribute('data-total') || '';

        function refresh() {
            var percent = parseFloat(input.value);

            // Пересчитывать нечего: сумма цен ещё не задана, и подсказка
            // обещала бы «25,5 из 0 баллов» — то есть названное число и
            // нулевой знаменатель.
            if (isNaN(percent) || !(total > 0) || totalText === '') {
                hint.textContent = '';

                return;
            }

            var points = Math.round(total * percent / 100 * 2) / 2;

            hint.textContent = template
                .replace('__PERCENT__', number(percent))
                .replace('__POINTS__', number(points))
                .replace('__TOTAL__', totalText);
        }

        input.addEventListener('input', refresh);
        refresh();
    });
}());