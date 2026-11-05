-- UNDO migration 010_billing_status. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

ALTER TABLE `services` DROP COLUMN `keep_billing`;
DELETE FROM schema_migrations WHERE id='010_billing_status';
