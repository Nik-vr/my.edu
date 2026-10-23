-- ============================================================================
--  my.edu — система контроля знаний
--  Снимок схемы БД (MySQL 5.7+ / MariaDB 10.2+ / MySQL 8.x)
--
--  Совместимость: движок InnoDB, кодировка utf8mb4_unicode_ci.
--  JSON-поля хранятся в TEXT (MariaDB не имеет настоящего типа JSON),
--  CHECK-констрейнты не используются — валидация на уровне приложения.
--
--  ВНИМАНИЕ: ФАЙЛ ДЛЯ ПУСТОЙ БАЗЫ.
--
--  Здесь объявлены таблицы, которых в базе ещё нет. Таблицы, которые уже
--  есть, не трогаются: объявление идёт как `CREATE TABLE IF NOT EXISTS`, и
--  удаления в файле нет вообще.
--
--  Импорт этого файла в базу с данными ничего в ней не изменит — и это
--  сделано намеренно. Раньше перед созданием таблиц стояло
--  `DROP TABLE IF EXISTS`, и ошибочный выбор базы в панели хостинга стирал её
--  целиком: учеников, классы, работы и результаты. Установщик `public/install.php`
--  такую базу и раньше отвергал, но файл импортировали и руками.

--  Импорт:
--    mysql -u USER -p DBNAME < schema.sql
--
--  ЭТО ФАЙЛ СОСТОЯНИЯ, А НЕ ЧЕРНОВИК.
--
--  Здесь описано ровно то, что стоит на работающих инсталляциях. Схема была
--  собрана из миграций, и история их применения до сих пор лежала рядом, в
--  database/migrations — двадцать четыре файла, каждый из которых рассчитан на
--  базу, какой она была в тот момент. Для новой инсталляции они не нужны: база
--  получается импортом этого файла. Для двух работающих сайтов они уже
--  применены, и повторно их запускать нельзя — половина файлов состоит из
--  DROP COLUMN и переименований, которых на этих базах давно нет.
--
--  Поэтому файлы удалены, а нумерация миграций начинается заново, с 001.
--  Что это меняет для работы:
--
--    * этот файл — единственный источник правды о схеме. Изменение схемы
--      начинается с него, а не с миграции;
--    * новая миграция в database/migrations/001_*.sql пишется на то, что
--      делает СЛЕДУЮЩИМ шагом после этого файла, и применяется к базам, на
--      которых уже стоит состояние из этого файла;
--    * после каждой применённой миграции её изменение обязано появиться и
--      здесь, иначе свежая установка разойдётся с работающими базами. Проверка
--      на это есть в tools/run_tests.ps1: наборы идут по базе, собранной
--      импортом этого файла, и расхождение всплывает сразу.
--
--  Лишние объекты в старых базах нормальны и удалять их не нужно: сверка
--  спрашивает «есть ли всё нужное», а не «нет ли лишнего». Например, колонка
--  assignment_items.title_snapshot осталась на инсталляциях после того, как
--  её убрали из кода, — она никем не читается и работе не мешает.
--
--  Таблицы плагинов здесь не описаны: у каждого плагина свои, и создаёт их
--  сам плагин. Здесь лежат только таблицы ядра. Исключение — tests, questions и
--  question_options: они достались ядру от первого этапа, когда заданий ещё не
--  было как понятия, и переезжать обратно они не будут.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------ Пользователи

-- users — ученики, учителя, завучи и администраторы.
--
-- Роли вынесены в отдельные таблицы: человек может одновременно быть
-- администратором и учителем, поэтому связь Many-to-many.
--
-- Поля login и email:
--   * у сотрудников login = e-mail, вход возможен по любому из двух;
--   * у учеников login генерируется: кириллическая буква + цифры.
--
-- is_owner — владелец сайта, назначается первому подтверждённому
-- пользователю. Роль администратора у владельца снять нельзя.
--
-- status: pending — зарегистрирован, но не подтвердил e-mail;
--         active  — может входить;
--         blocked — вход запрещён.
CREATE TABLE IF NOT EXISTS `users` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`             VARCHAR(64)  NULL,
  `email`             VARCHAR(191) NULL,
  `password_hash`     VARCHAR(255) NOT NULL,
  -- full_name — составное ФИО одной строкой. Остаётся, потому что по нему
  --   сортируются журнал и списки, и он печатается в письмах. Заполняется из
  --   трёх частей при каждой записи, сам по себе не редактируется.
  `full_name`         VARCHAR(191) NOT NULL,
  `last_name`         VARCHAR(191) NULL,
  `first_name`        VARCHAR(191) NULL,
  `middle_name`       VARCHAR(191) NULL,
  `birth_date`        DATE         NULL,
  `vk_url`            VARCHAR(191) NULL,
  -- avatar_url — адрес файла в public/uploads. Пусто у тех, кто аватар не
  --   загружал; тогда показываются инициалы. См. миграцию 018.
  `avatar_url`        VARCHAR(255) NULL,
  `is_owner`          TINYINT(1)   NOT NULL DEFAULT 0,
  `status`            ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending',
  `email_verified_at` DATETIME     NULL,
  `last_login_at`     DATETIME     NULL,
  `created_at`        DATETIME     NOT NULL,
  `updated_at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_login` (`login`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_full_name` (`full_name`),
  KEY `idx_users_last_name` (`last_name`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Роли

-- roles — справочник ролей. Справочник, а не ENUM: роли показываются
-- в интерфейсе с названиями и описаниями.
CREATE TABLE IF NOT EXISTS `roles` (
  `code`        VARCHAR(32)  NOT NULL,
  `title`       VARCHAR(64)  NOT NULL,
  `description` VARCHAR(191) NULL,
  `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Роли — только для сотрудников. Ученик в этот справочник не входит: у него
-- своя таблица `students`, и ролей у него нет.
INSERT IGNORE INTO `roles` (`code`, `title`, `description`, `position`) VALUES
  ('observer',     'Наблюдатель',   'Регистрация и просмотр своих данных',  10),
  ('teacher',      'Учитель',       'Создание и проведение своих тестов',   30),
  ('head_teacher', 'Завуч',         'Классы, предметы, учители, ученики',  40),
  ('admin',        'Администратор', 'Управление пользователями и сайтом',   50);

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id`    INT UNSIGNED NOT NULL,
  `role_code`  VARCHAR(32)  NOT NULL,
  `granted_at` DATETIME     NOT NULL,
  `granted_by` INT UNSIGNED NULL,
  PRIMARY KEY (`user_id`, `role_code`),
  KEY `idx_user_roles_role` (`role_code`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_code`) REFERENCES `roles` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Токены из писем

-- auth_tokens — одноразовые токены из писем.
-- Хранится только SHA-256 хеш: при утечке базы токены из писем не восстановить.
-- target_email привязывает ссылку подтверждения к адресу её получателя.
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `purpose`      ENUM('email_confirm','password_reset') NOT NULL,
  `target_email` VARCHAR(191) NULL,
  `token_hash`   CHAR(64)     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_tokens_hash` (`token_hash`),
  KEY `idx_auth_tokens_user` (`user_id`, `purpose`),
  CONSTRAINT `fk_auth_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Классы

-- name — номер класса, например «7-Б», уникален;
-- title — необязательное название, до 40 символов.
CREATE TABLE IF NOT EXISTS `classes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(32)  NOT NULL COMMENT 'номер класса, например 7-Б',
  `title`      VARCHAR(40)  NULL COMMENT 'название, до 40 символов',
  `year`       SMALLINT UNSIGNED NULL COMMENT 'учебный год начала, например 2025',
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_subjects` (
  `class_id`   INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`class_id`, `subject_id`),
  KEY `idx_class_subjects_subject` (`subject_id`),
  CONSTRAINT `fk_class_subjects_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_class_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_teachers` (
  `class_id`    INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `assigned_at` DATETIME     NOT NULL,
  PRIMARY KEY (`class_id`, `user_id`),
  KEY `idx_class_teachers_user` (`user_id`),
  CONSTRAINT `fk_class_teachers_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_teachers_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ученики. Отдельная таблица, а не строки `users` с ролью «ученик»: ученик
-- не сотрудник — у него нет почты, подтверждения адреса и ролей, а записей
-- его в разы больше, чем сотрудников. Вход у ученика свой (/student/login),
-- сессия своя (_auth_student_id).
-- class_id — один класс на ученика; NULL означает «вне класса»: ученика убрали
--   из класса, а учётную запись сохранили.
CREATE TABLE IF NOT EXISTS `students` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`         VARCHAR(64)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  -- full_name — составное ФИО одной строкой, как у сотрудников: по нему
  --   сортируются журнал и списки класса.
  `full_name`     VARCHAR(191) NOT NULL,
  `last_name`     VARCHAR(191) NULL,
  `first_name`    VARCHAR(191) NULL,
  `middle_name`   VARCHAR(191) NULL,
  -- status — только active/blocked: значения pending у ученика не бывает,
  --   почты для подтверждения адреса у него нет.
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

-- ------------------------------------------------------------------ Предметы

CREATE TABLE IF NOT EXISTS `subjects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(191) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`)
  -- Уникальности по названию намеренно нет: предметы заводятся свободным
  -- текстом, и одинаковые названия допускаются (например, «Биология» в
  -- разных параллелях). Код, который стоял здесь прежде, уникальность
  -- обеспечивал искусственно — двум одноимённым предметам он давал разные
  -- значения (BIO и BIO2), и оба создавались.
  --
  -- Индекса по названию нет: предметов на школу десятки, список показывается
  -- целиком, а искать их по имени не приходится. На инсталляциях его тоже нет.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Тесты

CREATE TABLE IF NOT EXISTS `tests` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `subject_id`        INT UNSIGNED    NULL,
  `author_id`         INT UNSIGNED    NULL,
  `class_id`          INT UNSIGNED    NULL COMMENT 'для каких классов предназначен тест',
  `title`             VARCHAR(255)    NOT NULL,
  `description`       TEXT            NULL,
  `time_limit_min`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = без ограничения',
  `shuffle_questions` TINYINT(1)      NOT NULL DEFAULT 0,
  `shuffle_options`   TINYINT(1)      NOT NULL DEFAULT 1,
  `max_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = без ограничения',
  `show_results`      TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'показывать разбор после проверки',
  `status`            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at`        DATETIME        NOT NULL,
  `updated_at`        DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tests_status` (`status`),
  KEY `idx_tests_subject` (`subject_id`),
  KEY `idx_tests_class` (`class_id`),
  CONSTRAINT `fk_tests_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tests_author`  FOREIGN KEY (`author_id`)  REFERENCES `users` (`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_tests_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Критерии оценивания отдельного задания больше не хранятся: шкала принадлежит
-- работе целиком, она лежит в assignments.criteria и задаётся на шаге «Результат».
-- Таблица test_criteria снята миграцией 016.

-- Вопросы.
--
-- type — ключ стратегии из QuestionTypeRegistry. Добавление типа = класс +
-- строка в реестре, схема не меняется.
-- config — JSON с настройками типа:
--            single_choice  : {"correct":"b"}
--            multiple_choice: {"correct":["a","c"],"partial":true,"miss_penalty":0.5}
--            ordering       : {"correct_order":["a","b","c"],"partial":"positional"}
--            numeric        : {"mode":"exact|range","expected":56,"min":4.7,"max":5.3,
--                              "tolerance":0.1,"unit":"см"}
--            text           : {"strategy":"keywords|exact|contains|manual", ...}
--            matching       : {"pairs":{"l1":"r3"},"partial":true}
CREATE TABLE IF NOT EXISTS `questions` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `test_id`     INT UNSIGNED  NOT NULL,
  `type`        VARCHAR(32)   NOT NULL,
  `prompt`      TEXT          NOT NULL,
  `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `weight`      DECIMAL(6,2)  NOT NULL DEFAULT 1.00,
  `is_required` TINYINT(1)    NOT NULL DEFAULT 1,
  `explanation` TEXT          NULL COMMENT 'комментарий учителя к правильному ответу',
  `config`      TEXT          NULL COMMENT 'JSON-настройки типа вопроса',
  PRIMARY KEY (`id`),
  KEY `idx_questions_test` (`test_id`, `position`),
  CONSTRAINT `fk_questions_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Элементы задания.
--
-- kind: 'option' — вариант ответа, 'item' — переставляемый элемент,
--       'left'/'right' — колонки задания на соответствие.
-- value_key — стабильный ключ; ответ ученика хранит ключи, а не текст,
--             чтобы переформулировка вопроса не ломала собранные попытки.
-- pair_key — для matching: элемент левой колонки, которому соответствует
--            эта строка правой колонки (дублирует config.pairs).
CREATE TABLE IF NOT EXISTS `question_options` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` INT UNSIGNED NOT NULL,
  `kind`        VARCHAR(16)  NOT NULL,
  `value_key`   VARCHAR(64)  NOT NULL,
  `text`        TEXT         NOT NULL,
  `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pair_key`    VARCHAR(64)  NULL,
  `always_show` TINYINT(1)    NOT NULL DEFAULT 0
  COMMENT 'вариант показывается всегда, даже при выборе случайных',
  PRIMARY KEY (`id`),
  KEY `idx_qopt_question` (`question_id`, `kind`, `position`),
  CONSTRAINT `fk_qopt_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Настройки сайта

-- Хранилище «ключ → значение». Первым ключом идёт school_name: название
-- школы, которое администратор задаёт в панели управления.
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(64) NOT NULL,
  `value`      TEXT        NULL,
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('school_name', '');

-- ------------------------------------------------------- Ограничение входа

-- Неудачные попытки входа по паре «логин + адрес источника». Ключ хранится
-- хэшем: логин вводит посетитель, и хранить его здесь — значило бы копить
-- чужие адреса. Подробности в migrations/014_login_throttle.sql.
CREATE TABLE IF NOT EXISTS `login_throttle` (
  `throttle_key`    CHAR(64) NOT NULL COMMENT 'sha256(логин + ip)',
  `failures`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `first_failed_at` DATETIME NOT NULL,
  `last_failed_at`  DATETIME NOT NULL,
  PRIMARY KEY (`throttle_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- Ограничение частоты

-- Счётчик запросов на чувствительные адреса: регистрация, сброс пароля,
-- повторное письмо, загрузка картинки. Окно скользящее — считается от первого
-- запроса, поэтому лавина не проходит на стыке двух минут. Запись удаляется
-- сама по истечении окна, таблица не растёт. Ключ — хэш, чтобы по таблице не
-- было видно, кто и как часто ходил. Подробности в
-- migrations/026_rate_limits.sql.
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `limit_key`         CHAR(64) NOT NULL COMMENT 'sha256(группа + кто)',
  `window_started_at` DATETIME NOT NULL,
  `hits`              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`limit_key`),
  KEY `rate_limits_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Плагины

-- Установленные плагины системы оценивания. Папки в plugins/ сканируются при
-- обращении к панели, здесь хранится только состояние.
--
-- Включение: у «Теста» колонка enabled — общий переключатель, его двигает
-- администратор; у остальных плагинов она лишь отметка того, что плагин
-- установлен и загружается, а личное состояние хранится в user_plugins.
CREATE TABLE IF NOT EXISTS `plugins` (
  `id`           VARCHAR(64)  NOT NULL COMMENT 'id из plugin.json',
  `name`         VARCHAR(191) NOT NULL,
  `version`      VARCHAR(32)  NOT NULL DEFAULT '0.0.0',
  `description`  VARCHAR(255) NULL,
  `author`       VARCHAR(191) NULL,
  `enabled`      TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'для «Теста» — общий выключатель; для остальных — отметка установки',
  `error`        TEXT         NULL COMMENT 'почему плагин не загрузился',
  `installed_at` DATETIME     NULL,
  `updated_at`   DATETIME     NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Свои наборы плагинов у каждого пользователя: у учителя свой каталог типов
-- заданий, и лишнее он отключает у себя, не трогая настройки коллег.
--
-- Отсутствие строки означает «включён»: новый учитель начинает со всеми
-- доступными плагинами, а не с пустого каталога. Исключение из этого правила
-- — «Тест», он общий и хранится в plugins.enabled.
CREATE TABLE IF NOT EXISTS `user_plugins` (
  `user_id`    INT UNSIGNED NOT NULL,
  `plugin_id`  VARCHAR(64)  NOT NULL COMMENT 'id из plugin.json',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT '0 — выключен у пользователя; отсутствие строки означает «включён»',
  `updated_at` DATETIME     NULL,
  PRIMARY KEY (`user_id`, `plugin_id`),
  KEY `idx_user_plugins_plugin` (`plugin_id`),
  CONSTRAINT `fk_user_plugins_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Работы

-- Работа: то, что учитель собирает из заданий разных плагинов и выдаёт
-- ученику целиком. Само содержимое принадлежит плагинам, ядро держит только
-- ссылку, поэтому новый способ проверки знаний не требует правок здесь.
--
-- grade_mode: 'weighted' — единая оценка за всю работу (среднее по весам),
--             'per_item' — отдельная оценка за каждое задание со сведением.
-- criteria_mode: 'percent' — итог по доле выполнения, 'score' — по баллам.
--             Смешивать нельзя: «от 7 баллов» и «от 25%» на одной шкале ученику
--             не объяснить, поэтому режим выбирается один раз на всю работу.
-- criteria   — критерии в JSON: [{"grade":5,"min_percent":85}, …].
--             При criteria_mode = 'score' вместо min_percent в критерии лежит
--             min_score, а граница верхней строки берётся из следующей.
CREATE TABLE IF NOT EXISTS `assignments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(191) NOT NULL,
  `description`  TEXT         NULL,
  `subject_id`   INT UNSIGNED NULL COMMENT 'предмет; NULL — работа без предмета',
  `parallel`     TINYINT UNSIGNED NULL
                 COMMENT 'параллель 1–11; только для порядка в списке, адресации не задаёт',
  `author_id`    INT UNSIGNED NOT NULL COMMENT 'кто собрал работу',
  `status`       ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `grade_mode`   ENUM('weighted','per_item') NOT NULL DEFAULT 'weighted',
  `criteria_mode` ENUM('percent','score') NOT NULL DEFAULT 'percent'
    COMMENT 'по чему считается итог: проценты или баллы',
  `show_results` TINYINT(1)   NOT NULL DEFAULT 1,
  `time_limit`   SMALLINT UNSIGNED NULL COMMENT 'лимит времени на попытку, минут',
  `shuffle_items` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'выдавать задания ученику в случайном порядке',
  `no_copy`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'запретить копирование текста задания при выполнении',
  `late_penalty`  DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'штраф в баллах за сдачу после срока',
  `criteria`     TEXT         NULL,
  `published_at` DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assignments_author` (`author_id`),
  KEY `idx_assignments_status` (`status`),
  -- Индекса по parallel нет: номер параллели задаёт только порядок в списке и
  -- ничего не адресует, а работ у учителя десятки. На инсталляциях его тоже нет.
  -- RESTRICT, а не CASCADE как у выдач: работа с её попытками и результатами —
  -- самая ценная вещь в системе, и удаление сотрудника не должно молча её
  -- уносить. Пусть удаление откажется, а разбираться будет человек.
  -- Удаляются только ученики класса (ClassRepository::deleteWithStudents),
  -- а они работ не составляют, поэтому ключ не мешает.
  CONSTRAINT `fk_assignments_author`
    FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Выдачи работы.
--
-- Работа ни к чему не привязана: параллель выше — только подпись для папки в
-- списке. Кто и в какой срок получит работу, решает выдача, и именно её видит
-- ученик. Поэтому её и храним отдельно от самой работы: одну и ту же работу
-- можно выдать разным классам в разные сроки.
--
-- NULL в available_from и available_until означает «всегда».
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assignment_issues` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id`   INT UNSIGNED NOT NULL,
  `author_id`       INT UNSIGNED NOT NULL
                    COMMENT 'кто выдал: чужую выдачу учитель не правит',
  `max_attempts`    INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'сколько попыток разрешено этой выдаче; 0 — сколько угодно',
  `available_from`  DATETIME     NULL
                    COMMENT 'NULL вместе с available_until — работа доступна всегда',
  `available_until` DATETIME     NULL
                    COMMENT 'после этого срока работа ученику не видна',
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_issues_assignment` (`assignment_id`),
  KEY `idx_issues_author` (`author_id`),
  KEY `idx_issues_assignment_student` (`assignment_id`,`id`),
  CONSTRAINT `fk_issues_assignment` FOREIGN KEY (`assignment_id`)
    REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issues_author` FOREIGN KEY (`author_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'выдачи работы: кому и в какой срок';

-- Кому выдана работа. Класс запоминается на момент выдачи — он нужен сводной
-- таблице в «Просмотре результатов», где видно, кому работа досталась, а не
-- где ученик учится сегодня. NULL, чтобы удаление класса не стирало выдачу.
-- Кому выдана работа. Класс запоминается на момент выдачи — он нужен сводной
-- таблице в «Просмотре результатов», где видно, кому работа досталась, а не
-- где ученик учится сегодня. NULL, чтобы удаление класса не стирало выдачу.
-- student_id ссылается на `students`, а не на `users`: ученик — отдельная
--   таблица, сотрудник учеником не бывает.
CREATE TABLE IF NOT EXISTS `assignment_issue_students` (
  `issue_id`    INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `class_id`    INT UNSIGNED NULL COMMENT 'класс на момент выдачи',
  `assigned_at` DATETIME    NOT NULL,
  PRIMARY KEY (`issue_id`, `student_id`),
  KEY `idx_issue_students_student` (`student_id`),
  KEY `idx_issue_students_class` (`class_id`),
  CONSTRAINT `fk_issue_students_issue` FOREIGN KEY (`issue_id`)
    REFERENCES `assignment_issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issue_students_student` FOREIGN KEY (`student_id`)
    REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'кому выдана работа';

-- Позиция работы. weight — вес в сведении оценок: по умолчанию все равны,
-- итог — среднее по весам, а не по количеству позиций.
-- Внешнего ключа на task_id нет намеренно: таблицы заданий у плагинов разные.
CREATE TABLE IF NOT EXISTS `assignment_items` (
  `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `assignment_id`  INT UNSIGNED      NOT NULL,
  `position`       SMALLINT UNSIGNED NOT NULL,
  `plugin_id`      VARCHAR(64)       NOT NULL,
  `task_kind`      VARCHAR(64)       NOT NULL,
  `task_id`        INT UNSIGNED      NOT NULL COMMENT 'id задания в базе плагина',
`variant`        TINYINT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'столбец строки: 1 — основное, 2 и 3 — альтернативы',
    `weight`         DECIMAL(6,2)      NOT NULL DEFAULT 1.00,
  `max_score`      DECIMAL(8,2)      NULL COMMENT 'цена задания в баллах; NULL — считает плагин',
  `created_at`     DATETIME          NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_position_variant` (`assignment_id`,`position`,`variant`),
  KEY `idx_assignment_items_task` (`plugin_id`, `task_id`),
  CONSTRAINT `fk_assignment_items_assignment`
    FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Черновик правки позиции работы.
--
-- Что это: значения полей, которые учитель набрал, но ещё не сохранил. Раньше
-- черновик жил в sessionStorage и умирал вместе со вкладкой — работа на пять
-- минут могла уйти из-за того, что окно закрылось или завис браузер.
--
-- Почему отдельная таблица, а не столбец в позиции: черновик принадлежит
-- человеку, а не позиции. У одного задания может быть несколько редакторов
-- (два учителя ведут одну работу), и у каждого своя недописанная правка.
-- Поэтому уникальность по (автор, позиция), а не по позиции.
--
-- Ничего не публикуется: строка живёт отдельно от задания, ученик её не видит,
-- и «сохранить» по-прежнему остаётся единственным способом опубликовать.
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

-- Попытка по работе: либо ученика, либо сотрудника, который начал работу сам,
-- чтобы проверить её (без ограничения числа попыток). token — одноразовый ключ
-- доступа, как у тестов: по голой ссылке чужую работу не открыть.
-- manual = 1 — ждёт проверки учителем.
CREATE TABLE IF NOT EXISTS `assignment_attempts` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED      NOT NULL,
  -- Ровно одна из двух колонок заполнена. Смешанной записи не бывает: это
  --   держит репозиторий и проверяет набор work. Две колонки вместо одной
  --   нужны затем, чтобы id ученика и id сотрудника из разных таблиц не могли
  --   совпасть и отдать чужую работу.
  `student_id`    INT UNSIGNED      NULL COMMENT 'ученик; NULL — начал сотрудник',
  `staff_id`      INT UNSIGNED      NULL COMMENT 'сотрудник, начавший работу сам; NULL — ученик',
  `token`         CHAR(64)          NOT NULL,
  `status`        ENUM('in_progress','finished','expired') NOT NULL DEFAULT 'in_progress',
  `attempt_no`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `score`         DECIMAL(8,2)      NULL,
  `max_score`     DECIMAL(8,2)      NULL,
  `percent`       DECIMAL(5,2)      NULL,
  `grade`         TINYINT UNSIGNED  NULL,
  `penalty`       DECIMAL(8,2)      NOT NULL DEFAULT 0.00
                    COMMENT 'штраф попытки: за лишние варианты, за опоздание и ручной',
  `late_penalty`  DECIMAL(8,2)      NOT NULL DEFAULT 0.00
                    COMMENT 'штраф за сдачу после срока, зафиксирован при сдаче',
  `manual_penalty` DECIMAL(8,2)     NOT NULL DEFAULT 0.00
                    COMMENT 'штраф, поставленный учителем вручную',
  `penalty_comment` TEXT            NULL
                    COMMENT 'комментарий учителя к ручному штрафу',
  `manual`        TINYINT(1)        NOT NULL DEFAULT 0,
  `started_at`    DATETIME          NOT NULL,
  `finished_at`   DATETIME          NULL,
  `checked_at`    DATETIME          NULL,
  `checked_by`    INT UNSIGNED      NULL,
  PRIMARY KEY (`id`),
  -- Номер попытки уникален по той колонке, которая заполнена: у учеников по
  --   student_id, у самопроверок учителя — по staff_id.
  UNIQUE KEY `uq_attempt_number` (`assignment_id`, `student_id`, `attempt_no`),
  UNIQUE KEY `uq_attempt_number_staff` (`assignment_id`, `staff_id`, `attempt_no`),
  UNIQUE KEY `uq_attempt_token` (`token`),
  KEY `idx_attempts_assignment` (`assignment_id`),
  KEY `idx_attempts_student` (`student_id`),
  KEY `idx_attempts_staff` (`staff_id`),
  KEY `idx_attempts_manual` (`manual`, `status`),
  CONSTRAINT `fk_attempts_assignment`
    FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempts_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempts_staff`
    FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Проверка одной позиции работы. snapshot — JSON из ItemGrade::snapshot:
-- разбор не пересчитывается при показе, иначе правка задания изменила бы то,
-- что ученик видел при проверке.
CREATE TABLE IF NOT EXISTS `assignment_item_results` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `attempt_id`    INT UNSIGNED     NOT NULL,
  `item_id`       INT UNSIGNED     NOT NULL,
  `score`         DECIMAL(8,2)     NULL,
  `max_score`     DECIMAL(8,2)     NULL,
  `percent`       DECIMAL(5,2)     NULL,
  `penalty`       DECIMAL(8,2)     NOT NULL DEFAULT 0.00
                  COMMENT 'штраф за лишние варианты в этом задании, отдельно от балла',
  `grade`         TINYINT UNSIGNED NULL,
  `status`        ENUM('pending','correct','partial','wrong','skipped') NOT NULL DEFAULT 'pending',
  `needs_review`  TINYINT(1)       NOT NULL DEFAULT 0,
  `comment`       TEXT             NULL,
  `checked_at`    DATETIME         NULL,
  `checked_by`    INT UNSIGNED     NULL,
  `snapshot`      MEDIUMTEXT       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_result` (`attempt_id`, `item_id`),
  KEY `idx_item_results_item` (`item_id`),
  CONSTRAINT `fk_item_results_attempt`
    FOREIGN KEY (`attempt_id`) REFERENCES `assignment_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_results_item`
    FOREIGN KEY (`item_id`) REFERENCES `assignment_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Служебное

-- Отметка применённых миграций для баз, созданных до этапа 2.
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `filename`   VARCHAR(191) NOT NULL,
  `applied_at` DATETIME     NOT NULL,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
