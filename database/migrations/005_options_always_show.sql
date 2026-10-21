-- Обязательный показ варианта ответа.
--
-- Что это: у варианта ответа может стоять отметка «обязательно показывать».
-- Если у вопроса задано, сколько вариантов показывать ученику, то эти варианты
-- показываются всегда, а остальные набираются случайно.
--
-- Зачем: правильный ответ нельзя спрятать — иначе вопрос окажется без верного
-- варианта и станет неразрешимым. А лишние варианты ученику не нужны, и с
-- ними работа шла бы медленнее.
--
-- Файл повторяемый: у уже обновлённой базы столбец есть, и добавлять его
-- второй раз нельзя. Проверка идёт через information_schema, а не через
-- `ADD COLUMN IF NOT EXISTS` — такой синтаксис понимает только MariaDB.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'question_options'
      AND COLUMN_NAME = 'always_show'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `question_options` ADD COLUMN `always_show` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''вариант показывается всегда, даже при выборе случайных'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
