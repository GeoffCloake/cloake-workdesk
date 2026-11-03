-- UNDO migration 006_schedule_materials. Drops the added columns and tables AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

DROP TABLE IF EXISTS `job_items`;
DROP TABLE IF EXISTS `staff`;
ALTER TABLE `tasks` DROP COLUMN `scheduled_on`, DROP COLUMN `scheduled_minutes`;
DELETE FROM schema_migrations WHERE id='006_schedule_materials';
