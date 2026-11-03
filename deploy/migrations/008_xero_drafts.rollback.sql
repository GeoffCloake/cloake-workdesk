-- UNDO migration 008_xero_drafts. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

ALTER TABLE `charges` DROP COLUMN `xero_invoice_id`;
DELETE FROM schema_migrations WHERE id='008_xero_drafts';
