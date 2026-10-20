-- Штрафы: отдельно от баллов.
--
-- Что это: штрафы перестают быть частью балла задания и становятся отдельной
-- величиной. Их три, и все они попадают в одну сводку — «набрано, штраф,
-- итого»:
--
--   * штраф за лишний вариант ответа (у задания) — теперь не срезает балл
--     задания, а лежит рядом с ним отдельным числом;
--   * штраф за сдачу после срока (у работы) — задаётся автором работы и
--     фиксируется в момент сдачи;
--   * ручной штраф учителя (у попытки) — учитель ставит его при проверке и
--     может оставить к нему комментарий.
--
-- Почему отдельная миграция, а не правка снимка схемы: снимок — это состояние
-- новой установки, а у уже стоящих баз колонок нет. Миграция повторяема и
-- ничего не переписывает: у работы и попытки без штрафов новые поля равны
-- нулю, то есть поведение остаётся прежним.
--
-- Значение по умолчанию — ноль: штрафы не включаются сами, их задаёт автор.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'assignments'
      AND COLUMN_NAME = 'late_penalty'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `assignments` ADD COLUMN `late_penalty` DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''штраф в баллах за сдачу после срока'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'assignment_attempts'
      AND COLUMN_NAME = 'penalty'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `assignment_attempts`
        ADD COLUMN `penalty` DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''штраф попытки: за лишние варианты, за опоздание и ручной'',
        ADD COLUMN `late_penalty` DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''штраф за сдачу после срока, зафиксирован при сдаче'',
        ADD COLUMN `manual_penalty` DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''штраф, поставленный учителем вручную'',
        ADD COLUMN `penalty_comment` TEXT NULL COMMENT ''комментарий учителя к ручному штрафу'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'assignment_item_results'
      AND COLUMN_NAME = 'penalty'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `assignment_item_results`
        ADD COLUMN `penalty` DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''штраф за лишние варианты в этом задании, отдельно от балла'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
