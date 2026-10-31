-- UNDO migration 002_task_workload_fields. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

ALTER TABLE `tasks` DROP COLUMN `contact_id`, DROP COLUMN `assigned_to`, DROP COLUMN `queue`, DROP COLUMN `task_category`, DROP COLUMN `task_type`, DROP COLUMN `domains`;
ALTER TABLE `clients` DROP COLUMN `postal_region`, DROP COLUMN `credit_approved`, DROP COLUMN `comments`;
ALTER TABLE `contacts` DROP COLUMN `title`;
DELETE FROM schema_migrations WHERE id='002_task_workload_fields';
