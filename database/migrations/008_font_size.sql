-- Личный размер шрифта.
--
-- Что это: человек выбирает размер шрифта один раз в настройках — мелкий,
-- нормальный или крупный, — и весь интерфейс показывается ему в этом
-- размере. Выбор хранится отдельно у сотрудника и отдельно у ученика: у них
-- разные таблицы и разные страницы настроек, но свойства одно — «так мне
-- удобно читать», и оно не смешивается с чужим.
--
-- Зачем отдельная миграция, а не только снимок схемы: снимок описывает
-- новую установку, а на уже стоящих базах колонки нет, и у человека без неё
-- просто нечего читать. Миграция повторяема: проверка идёт через
-- information_schema, а не через `ADD COLUMN IF NOT EXISTS` — такой синтаксис
-- понимает только MariaDB.
--
-- Тип — VARCHAR со значениями small/normal/large, а не TINYINT с числом:
--   1. Значение читается как есть в шаблоне (`data-font-size="large"`), и
--      переводить число в слово пришлось бы в двух местах сразу — в оболочке
--      страницы и в форме настроек, где они легко разошлись бы.
--   2. В базе, которую человек смотрит руками, написано слово, а не «2»,
--      и гадать, какое это число, не нужно.
--   3. Пустые значения и слова не из списка — тоже обычное «нормальный»,
--      поэтому ограничениеENUM здесь не даёт ничего, а при расширении
--      списка (появился бы ещё один размер) пришлось бы менять ENUM.
--
-- Значение по умолчанию — normal: пока человек ничего не выбрал, он видит
-- систему как задумано, без мелкого и без крупного.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'font_size'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `font_size` VARCHAR(8) NOT NULL DEFAULT ''normal'' COMMENT ''личный размер шрифта: small, normal, large'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'font_size'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `students` ADD COLUMN `font_size` VARCHAR(8) NOT NULL DEFAULT ''normal'' COMMENT ''личный размер шрифта: small, normal, large'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
