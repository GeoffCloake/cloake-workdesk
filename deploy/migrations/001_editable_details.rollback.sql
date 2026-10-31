-- UNDO migration 001_editable_details. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

ALTER TABLE `clients` DROP COLUMN `business_name`, DROP COLUMN `email`, DROP COLUMN `phone`, DROP COLUMN `physical_address_1`, DROP COLUMN `physical_address_2`, DROP COLUMN `physical_city_town`, DROP COLUMN `physical_country`, DROP COLUMN `postal_address_1`, DROP COLUMN `postal_address_2`, DROP COLUMN `postal_address_3`, DROP COLUMN `postal_city_town`, DROP COLUMN `postal_code`, DROP COLUMN `postal_country`, DROP COLUMN `contact_method`, DROP COLUMN `notes`, DROP COLUMN `updated_at`;
ALTER TABLE `contacts` DROP COLUMN `first_name`, DROP COLUMN `middle_name`, DROP COLUMN `last_name`, DROP COLUMN `mobile`, DROP COLUMN `position`, DROP COLUMN `contact_type`, DROP COLUMN `contact_method`, DROP COLUMN `status`, DROP COLUMN `notes`, DROP COLUMN `updated_at`;
DELETE FROM schema_migrations WHERE id='001_editable_details';
