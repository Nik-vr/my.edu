-- ============================================================================
--  Миграция плагина «Тест с вопросами» — хранение прохождения внутри работы
--
--  Накатывается один раз при включении плагина: TestPlugin::install().
--
--  Что появляется:
--    * test_item_attempts — прохождение одного задания-теста внутри работы.
--      Ядро держит попытку по всей работе целиком, а плагину нужна своя
--      на каждое задание: у него свои вопросы, свои ответы и своя проверка.
--    * test_item_answers  — ответы ученика в этом прохождении.
--
--  Ключ (assignment_attempt_id, item_id) связывает прохождение с позицией
--  работы ядра. Внешних ключей на assignment_attempts и assignment_items нет
--  намеренно: таблицы работы принадлежат ядру, и плагин не должен ни мешать
--  их удалению, ни ломаться от их перестройки.
--
--  Свои таблицы нужны ещё и потому, что answers общего назначения привязан
--  внешним ключом к attempts, а попытка работы — это не попытка теста.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `test_item_attempts` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_attempt_id` INT UNSIGNED NOT NULL COMMENT 'попытка работы в ядре',
  `item_id`               INT UNSIGNED NOT NULL COMMENT 'позиция работы в ядре',
  `test_id`               INT UNSIGNED NOT NULL,
  `status`                ENUM('in_progress','graded') NOT NULL DEFAULT 'in_progress',
  `started_at`            DATETIME NOT NULL,
  `finished_at`           DATETIME NULL,
  `score`                 DECIMAL(8,2) NULL,
  `max_score`             DECIMAL(8,2) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_attempt` (`assignment_attempt_id`, `item_id`),
  KEY `idx_item_attempts_test` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `test_item_answers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_attempt_id` INT UNSIGNED NOT NULL,
  `question_id`   INT UNSIGNED NOT NULL,
  `answer_text`   TEXT         NULL,
  `answer_data`   TEXT         NULL COMMENT 'ответ как его ждёт тип вопроса',
  `score_awarded` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `is_correct`    TINYINT(1)   NULL COMMENT 'NULL — ответ ещё не проверялся',
  `detail`        TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_answer` (`item_attempt_id`, `question_id`),
  CONSTRAINT `fk_item_answers_attempt`
    FOREIGN KEY (`item_attempt_id`) REFERENCES `test_item_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
