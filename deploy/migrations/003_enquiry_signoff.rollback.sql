-- UNDO migration 003_enquiry_signoff. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

ALTER TABLE `enquiries` DROP COLUMN `received_at`, DROP COLUMN `contact_id`, DROP COLUMN `task_category`, DROP COLUMN `task_type`, DROP COLUMN `duplicate_of`, DROP COLUMN `closed_reason`;
ALTER TABLE `tasks` DROP COLUMN `signed_off_at`, DROP COLUMN `signed_off_by`, DROP COLUMN `sign_off_note`;
DELETE FROM schema_migrations WHERE id='003_enquiry_signoff';
