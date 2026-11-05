-- UNDO migration 009_service_plans. Drops the added columns and tables AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

DROP TABLE IF EXISTS `plans`;
ALTER TABLE `services` DROP COLUMN `plan_id`, DROP COLUMN `mailboxes`;
DELETE FROM schema_migrations WHERE id='009_service_plans';
