-- ============================================================================
--  Миграция плагина «Вариативная задача»
--
--  Накатывается один раз при включении плагина: VariablePlugin::install().
--
--  Что появляется:
--    * variable_tasks        — сами задания: условие с переменными и настройки.
--      Ядро знает только номер задания в позиции работы, поэтому всё, из чего
--      задание собирается, лежит здесь.
--    * variable_item_attempts — прохождение одного задания внутри работы.
--      Ядро держит попытку по всей работе целиком, а плагину нужна своя на
--      каждое задание: у него свой пример, свой ответ и своя проверка.
--    * variable_item_answers  — ответ ученика вместе с теми значениями
--      переменных, которые он видел.
--
--  Ключ (assignment_attempt_id, item_id) связывает прохождение с позицией
--  работы ядра. Внешних ключей на assignment_attempts и assignment_items нет
--  намеренно: таблицы работы принадлежат ядру, и плагин не должен ни мешать их
--  удалению, ни ломаться от их перестройки.
--
--  Почему значения переменных лежат в ответе, а не пересчитываются при
--  проверке: учитель вправе изменить допустимые значения после того, как
--  ученик ответил, и повторный розыгрыш дал бы ему другой пример — верный
--  ответ стал бы неверным. Сохранённый рядом с ответом пример и есть
--  единственный источник правды.
--
--  Ответ у задачи один, а не по числу на переменную: формула одна на всю
--  задачу, ученик её решает и вписывает результат. Поэтому в config лежит
--  одна строка formula, а не формула у каждой переменной.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `variable_tasks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_id`   INT UNSIGNED NOT NULL COMMENT 'учитель-автор',
  `subject_id`  INT UNSIGNED NULL,
  `title`       VARCHAR(191) NOT NULL,
  `prompt`      TEXT         NOT NULL COMMENT 'условие с переменными в фигурных скобках',
  `config`      TEXT         NULL     COMMENT 'JSON: переменные, формулы, допуск, единица',
  `status`      ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `created_at`  DATETIME NOT NULL,
  `updated_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_variable_tasks_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `variable_item_attempts` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_attempt_id` INT UNSIGNED NOT NULL COMMENT 'попытка работы в ядре',
  `item_id`               INT UNSIGNED NOT NULL COMMENT 'позиция работы в ядре',
  `task_id`               INT UNSIGNED NOT NULL,
  `status`                ENUM('in_progress','graded') NOT NULL DEFAULT 'in_progress',
  `started_at`            DATETIME NOT NULL,
  `finished_at`           DATETIME NULL,
  `score`                 DECIMAL(8,2) NULL,
  `max_score`             DECIMAL(8,2) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variable_item_attempt` (`assignment_attempt_id`, `item_id`),
  KEY `idx_variable_item_attempts_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `variable_item_answers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_attempt_id` INT UNSIGNED NOT NULL,
  `answer_data`     TEXT NULL COMMENT 'ответы по переменным и показанные значения',
  `score_awarded`   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `is_correct`      TINYINT(1)   NULL COMMENT 'NULL — ответ ещё не проверялся',
  `detail`          TEXT         NULL COMMENT 'разбор проверки: по каждой переменной',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variable_item_answer` (`item_attempt_id`),
  CONSTRAINT `fk_variable_answers_attempt`
    FOREIGN KEY (`item_attempt_id`) REFERENCES `variable_item_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;