-- Черновики правки позиции работы.
--
-- Почему отдельная таблица, а не столбец в позиции: черновик принадлежит
-- человеку, а не позиции. У одного задания может быть несколько редакторов
-- (два учителя ведут одну работу), и у каждого своя недописанная правка.
--
-- Файл повторяемый (`IF NOT EXISTS`) и без удалений: он должен одинаково
-- применяться к базе без черновиков и к базе, где таблица уже есть.
CREATE TABLE IF NOT EXISTS `assignment_drafts` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED NOT NULL,
  `item_id`       INT UNSIGNED NOT NULL,
  `author_id`     INT UNSIGNED NOT NULL COMMENT 'кто набирал: черновик виден только ему',
  `fields`        TEXT         NOT NULL COMMENT 'значения полей позиции в виде JSON',
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_drafts_author_item` (`author_id`,`item_id`),
  KEY `idx_assignment_drafts_assignment` (`assignment_id`),
  CONSTRAINT `fk_assignment_drafts_assignment`
    FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_drafts_item`
    FOREIGN KEY (`item_id`) REFERENCES `assignment_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_drafts_author`
    FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;