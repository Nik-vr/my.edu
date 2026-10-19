<?php

declare(strict_types=1);

namespace MyEdu\Ui;

/**
 * Словарь сообщений о неверном поле.
 *
 * Одно и то же условие проверяется дважды: браузером, пока человек ещё на
 * странице, и сервером, когда форма ушла. Формулировки расходились, и человек
 * получал два разных объяснения одного и того же: браузер писал «Заполните
 * «Фамилия»», сервер на то же пустое поле отвечал «Укажите фамилию». Хуже
 * всего выходило с адресом почты — «Похоже, это не адрес почты» против
 * «Укажите корректный адрес электронной почты»: человек правил адрес по первому
 * совету и получал тот же отказ по второму.
 *
 * Здесь лежат только те формулировки, которые не зависят от поля. Слова,
 * которые знает только сервер, — «Класс «7-Б» уже существует», «Дата рождения
 * не может быть в будущем», — остаются в коде: это не то же самое условие, а
 * другое, и подменять его общей фразой было бы потерей смысла. Для них есть
 * другой путь: поле несёт свою подсказку в `data-hint`, и браузер говорит
 * ровно то же, что скажет сервер.
 *
 * Вторая половина словаря — в `public/assets/js/form-validate.js`, откуда
 * браузер берёт свои фразы. Читать PHP-файл из браузера нельзя, поэтому
 * список живёт в двух местах, а `tests/run_ui_rules_tests.php` требует, чтобы
 * они совпадали слово в слово: разъехались — набор красный.
 *
 * `%s` и `%d` — подставляемые значения, и браузер подставляет их так же, как
 * сервер. Проверка сравнивает сами шаблоны, а не готовые фразы, иначе она
 * ловила бы разъезд на подстановке, а не на формулировке.
 */
final class Messages
{
    /** Обязательное поле с текстом. */
    public const REQUIRED_TEXT = 'Заполните «%s»';

    /** Обязательный флажок или выбор из нескольких. */
    public const REQUIRED_CHECK = 'Отметьте «%s»';

    /** Адрес не похож на почтовый. */
    public const EMAIL = 'Похоже, это не адрес почты';

    /** Адрес не похож на ссылку. */
    public const URL = 'Нужен полный адрес, например https://example.ru';

    /** Дата в чужом формате. */
    public const DATE = 'Введите дату в формате ГГГГ-ММ-ДД';

    /** В поле ожидалось число. */
    public const NUMBER = 'Нужно число';

    /** Слишком коротко. */
    public const MIN_LENGTH = 'Минимум %d символов';

    /** Слишком длинно. */
    public const MAX_LENGTH = 'Не больше %d символов';

    /** Число меньше нижней границы. */
    public const MIN = 'Не меньше %s';

    /** Число больше верхней границы. */
    public const MAX = 'Не больше %s';

    /** Дробное значение шагу не соответствует. */
    public const STEP = 'Дробное значение не подходит: шаг %s';

    /** Формат не распознан: телефон, идентификатор, произвольный код. */
    public const FORMAT = 'Значение не подходит по формату';

    /** Ни один вариант не отмечен. */
    public const GROUP = 'Отметьте хотя бы один вариант';

    public static function requiredText(string $field): string
    {
        return sprintf(self::REQUIRED_TEXT, $field);
    }

    public static function requiredCheck(string $field): string
    {
        return sprintf(self::REQUIRED_CHECK, $field);
    }

    public static function minLength(int $length): string
    {
        return sprintf(self::MIN_LENGTH, $length);
    }

    public static function maxLength(int $length): string
    {
        return sprintf(self::MAX_LENGTH, $length);
    }

    /**
     * Пары «условие — формулировка» в том же порядке, что в браузере.
     *
     * Нужен проверке: сравнивать списки по именам констант нельзя, у браузера
     * имён классов нет, а по алфавиту список разъедется от первого же
     * переименования.
     *
     * @return array<string, string>
     */
    public static function dictionary(): array
    {
        return [
            'REQUIRED_TEXT' => self::REQUIRED_TEXT,
            'REQUIRED_CHECK' => self::REQUIRED_CHECK,
            'EMAIL' => self::EMAIL,
            'URL' => self::URL,
            'DATE' => self::DATE,
            'NUMBER' => self::NUMBER,
            'MIN_LENGTH' => self::MIN_LENGTH,
            'MAX_LENGTH' => self::MAX_LENGTH,
            'MIN' => self::MIN,
            'MAX' => self::MAX,
            'STEP' => self::STEP,
            'FORMAT' => self::FORMAT,
            'GROUP' => self::GROUP,
        ];
    }
}