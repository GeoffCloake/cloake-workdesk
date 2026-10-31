-- Cloake Manager migration 002_task_workload_fields: Task contact, assigned staff, queue, category, type, domains; client region/credit/comments; contact title
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `tasks` ADD COLUMN `contact_id` BIGINT UNSIGNED NULL, ADD COLUMN `assigned_to` VARCHAR(255) NULL, ADD COLUMN `queue` INT NULL, ADD COLUMN `task_category` VARCHAR(80) NULL, ADD COLUMN `task_type` VARCHAR(120) NULL, ADD COLUMN `domains` VARCHAR(255) NULL;
ALTER TABLE `clients` ADD COLUMN `postal_region` VARCHAR(80) NULL, ADD COLUMN `credit_approved` VARCHAR(10) NULL, ADD COLUMN `comments` TEXT NULL;
ALTER TABLE `contacts` ADD COLUMN `title` VARCHAR(30) NULL;
UPDATE `tasks` SET `assigned_to` = COALESCE(`assigned_to`, CASE WHEN NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."action_for"'))),'null'),'') LIKE '[%' THEN NULLIF(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."action_for"'))),'null'),''),'[',''),']',''),'"',''),',',', ')),'') ELSE NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."action_for"'))),'null'),'') END),
  `task_category` = COALESCE(`task_category`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."task_category"'))),'null'),'')),
  `task_type` = COALESCE(`task_type`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."task_type"'))),'null'),'')),
  `domains` = COALESCE(`domains`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."domains"'))),'null'),''))
WHERE legacy_record IS NOT NULL;
UPDATE `clients` SET `postal_region` = COALESCE(`postal_region`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_region"'))),'null'),'')),
  `credit_approved` = COALESCE(`credit_approved`, CASE WHEN NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."credit_approved"'))),'null'),'') LIKE '[%' THEN NULLIF(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."credit_approved"'))),'null'),''),'[',''),']',''),'"',''),',',', ')),'') ELSE NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."credit_approved"'))),'null'),'') END),
  `comments` = COALESCE(`comments`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."comments"'))),'null'),''))
WHERE legacy_record IS NOT NULL;
UPDATE `contacts` SET `title` = COALESCE(`title`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."title"'))),'null'),''))
WHERE legacy_record IS NOT NULL;
UPDATE tasks t JOIN contacts k ON k.source_system='fabrik' AND CAST(k.source_id AS BINARY) = CAST(NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(t.legacy_record,'$."contact_id"'))),'null'),'') AS BINARY)
         SET t.contact_id = k.id WHERE t.contact_id IS NULL AND t.legacy_record IS NOT NULL;
UPDATE tasks SET queue = CAST(NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."queue"'))),'null'),'') AS SIGNED) WHERE queue IS NULL AND legacy_record IS NOT NULL AND NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."queue"'))),'null'),'') REGEXP '^-?[0-9]{1,9}$';
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('002_task_workload_fields','Task contact, assigned staff, queue, category, type, domains; client region/credit/comments; contact title',NOW(),'phpMyAdmin');
