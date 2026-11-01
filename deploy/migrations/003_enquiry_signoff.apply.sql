-- Cloake Manager migration 003_enquiry_signoff: Enquiry received date, contact, category/type, duplicate/withdrawn reason; task sign-off
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `enquiries` ADD COLUMN `received_at` DATETIME NULL, ADD COLUMN `contact_id` BIGINT UNSIGNED NULL, ADD COLUMN `task_category` VARCHAR(80) NULL, ADD COLUMN `task_type` VARCHAR(120) NULL, ADD COLUMN `duplicate_of` BIGINT UNSIGNED NULL, ADD COLUMN `closed_reason` VARCHAR(255) NULL;
ALTER TABLE `tasks` ADD COLUMN `signed_off_at` DATETIME NULL, ADD COLUMN `signed_off_by` VARCHAR(100) NULL, ADD COLUMN `sign_off_note` VARCHAR(500) NULL;
UPDATE enquiries SET received_at = created_at WHERE received_at IS NULL AND created_at IS NOT NULL;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('003_enquiry_signoff','Enquiry received date, contact, category/type, duplicate/withdrawn reason; task sign-off',NOW(),'phpMyAdmin');
