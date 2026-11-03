-- UNDO migration 004_pricing_templates. Drops the added columns and tables AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

DELETE FROM breakdown_items WHERE template_id IS NOT NULL AND enquiry_id IS NULL AND task_id IS NULL;
DROP TABLE IF EXISTS `billing_milestones`;
DROP TABLE IF EXISTS `enquiry_task_plans`;
DROP TABLE IF EXISTS `task_templates`;
ALTER TABLE `breakdown_items` DROP COLUMN `template_id`, DROP COLUMN `plan_id`, DROP COLUMN `line_order`, DROP COLUMN `account_name`, DROP COLUMN `created_at`, DROP COLUMN `updated_at`;
ALTER TABLE `enquiries` DROP COLUMN `billing_mode`;
ALTER TABLE `tasks` DROP COLUMN `billing_mode`, DROP COLUMN `parent_task_id`, DROP COLUMN `stage_no`;
DELETE FROM schema_migrations WHERE id='004_pricing_templates';
