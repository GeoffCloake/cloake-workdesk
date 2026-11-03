-- UNDO migration 005_services_billing. Drops the added columns and tables AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

DROP TABLE IF EXISTS `archive_whmcs`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `charges`;
DROP TABLE IF EXISTS `services`;
ALTER TABLE `work_log` DROP COLUMN `charge_id`;
DELETE FROM schema_migrations WHERE id='005_services_billing';
