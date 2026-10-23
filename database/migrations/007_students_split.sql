-- Ученики уходят из users окончательно: попытки получают две колонки, выдачи
-- перенацеливаются на students, строки роли student удаляются, таблица
-- class_students снимается.
--
-- Миграция повторяема: добавление колонок идёт через проверку
-- information_schema, повторный ALTER считается признаком «в базе уже так»,
-- удаление при повторном запуске просто ничего не находит. Копия сделана
-- в 006, здесь её не нужно повторять.

-- ------------------------------------------------- 1. staff_id у попыток

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'assignment_attempts'
      AND COLUMN_NAME = 'staff_id'
);

SET @sql := IF(
    @column_exists > 0,
    'SELECT 1',
    'ALTER TABLE `assignment_attempts` ADD COLUMN `staff_id` INT UNSIGNED NULL COMMENT ''сотрудник, начавший работу сам; NULL — ученик'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- student_id становится nullable: колонку заполняет только ученик.
ALTER TABLE `assignment_attempts`
  MODIFY COLUMN `student_id` INT UNSIGNED NULL COMMENT 'ученик; NULL — начал сотрудник';

-- ------------------------------------------------- 2. Самопроверки учителей
--
-- Сегодня попытки учителей лежат в student_id наравне с попытками учеников.
-- Ученики уже переехали в students с теми же id, а сотрудники остались в
-- users, — поэтому самопроверка распознаётся однозначно: id есть в users, но
-- его нет в students. Колонка staff_id к этому моменту уже добавлена.

UPDATE `assignment_attempts` a
   SET a.`staff_id` = a.`student_id`,
       a.`student_id` = NULL
 WHERE a.`student_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `students` s WHERE s.`id` = a.`student_id`)
   AND EXISTS (SELECT 1 FROM `users` u WHERE u.`id` = a.`student_id`);

-- Попытки-сироты: id не в students и не в users. Таких в здоровой базе быть не
-- может — единственный путь удалить ученика (удаление класса) снимает попытки
-- первым делом. Удаление — защита от отказа добавления внешнего ключа: строка
-- без владельца никому не показывается и ничьей работы не открывает.

DELETE a
  FROM `assignment_attempts` a
 WHERE a.`student_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `students` s WHERE s.`id` = a.`student_id`)
   AND NOT EXISTS (SELECT 1 FROM `users` u WHERE u.`id` = a.`student_id`);

-- ------------------------------------------------- 3. Индексы и ключи попыток
--
-- Каждый индекс — отдельным ALTER: если объявить их одним оператором, повтор
-- первого имени уронит весь оператор, и второй индекс не добавится.

ALTER TABLE `assignment_attempts`
  ADD UNIQUE KEY `uq_attempt_number_staff` (`assignment_id`, `staff_id`, `attempt_no`);

ALTER TABLE `assignment_attempts`
  ADD KEY `idx_attempts_staff` (`staff_id`);

ALTER TABLE `assignment_attempts`
  ADD CONSTRAINT `fk_attempts_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `assignment_attempts`
  ADD CONSTRAINT `fk_attempts_staff`
    FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------- 4. Выдачи работ на students

DELETE s
  FROM `assignment_issue_students` s
 WHERE NOT EXISTS (SELECT 1 FROM `students` st WHERE st.`id` = s.`student_id`);

ALTER TABLE `assignment_issue_students`
  DROP FOREIGN KEY `fk_issue_students_student`;

ALTER TABLE `assignment_issue_students`
  ADD CONSTRAINT `fk_issue_students_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------- 5. Ученики уходят из users
--
-- Строки users, скопированные в students, удаляются: иначе в списке
-- сотрудников останутся люди без должности — те самые ученики, ради которых
-- переделка и затевалась. Связи (роли, токены, личные настройки плагинов)
-- уходят каскадом.

DELETE FROM `user_roles` WHERE `role_code` = 'student';
DELETE FROM `roles` WHERE `code` = 'student';
DELETE u FROM `users` u INNER JOIN `students` s ON s.`id` = u.`id`;

-- class_students больше не нужна: класс у ученика теперь одна колонка.
DROP TABLE IF EXISTS `class_students`;
