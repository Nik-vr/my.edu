-- Ученики переезжают из users в отдельную таблицу students.
--
-- Зачем: ученик не сотрудник. У него нет почты, подтверждения адреса и ролей,
-- а записей его в разы больше, чем сотрудников, — и всё это жило в одной
-- таблице со списком сотрудников, мешая ему и его подсчётам.
--
-- id сохраняются: на них ссылаются выдачи работ, попытки и данные плагинов.
-- Переписывать их — значит ломать чужие ссылки ради которых миграция и делается.
--
-- Повторяемость: копия идёт с явным id и через INSERT IGNORE, поэтому
-- повторный запуск ничего не дублирует и ничего не переписывает.

CREATE TABLE IF NOT EXISTS `students` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`         VARCHAR(64)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(191) NOT NULL,
  `last_name`     VARCHAR(191) NULL,
  `first_name`    VARCHAR(191) NULL,
  `middle_name`   VARCHAR(191) NULL,
  `status`        ENUM('active','blocked') NOT NULL DEFAULT 'active',
  `class_id`      INT UNSIGNED NULL COMMENT 'один класс на ученика; NULL — вне класса',
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_login` (`login`),
  KEY `idx_students_full_name` (`full_name`),
  KEY `idx_students_class` (`class_id`),
  KEY `idx_students_status` (`status`),
  CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'ученики';

-- Кто считается учеником: строка в user_roles с ролью student.
--
-- Статус: blocked остаётся blocked, всё остальное становится active. Значения
-- pending у ученика не бывает: для подтверждения адреса нужна почта, а её у
-- ученика нет.
--
-- class_id: у кого классов оказалось несколько (импорт при переводе добавлял
-- в новый класс, не убирая из старого) берётся меньший id — правило
-- детерминированное. Прошлые работы при этом не теряются: класс выдачи
-- запомнен в самой выдаче, а не в текущем классе ученика.
--
-- login: у живых учеников он всегда заполнен: StudentRepository генерирует его
-- при создании. COALESCE — защита от записи без логина в старых базах:
-- колонка NOT NULL, и без запасного значения миграция упала бы.

INSERT IGNORE INTO `students`
    (`id`, `login`, `password_hash`, `full_name`, `last_name`, `first_name`,
     `middle_name`, `status`, `class_id`, `created_at`, `updated_at`)
SELECT u.`id`,
       COALESCE(u.`login`, CONCAT('student', u.`id`)),
       u.`password_hash`,
       u.`full_name`,
       u.`last_name`,
       u.`first_name`,
       u.`middle_name`,
       CASE WHEN u.`status` = 'blocked' THEN 'blocked' ELSE 'active' END,
       (SELECT MIN(cs.`class_id`)
          FROM `class_students` cs
         WHERE cs.`student_id` = u.`id`),
       u.`created_at`,
       u.`updated_at`
  FROM `users` u
 WHERE EXISTS (
       SELECT 1 FROM `user_roles` r
        WHERE r.`user_id` = u.`id` AND r.`role_code` = 'student'
 );
