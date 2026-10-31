-- Cloake Manager migration 001_editable_details: Editable client and contact detail columns, filled from imported records
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `clients` ADD COLUMN `business_name` VARCHAR(255) NULL, ADD COLUMN `email` VARCHAR(255) NULL, ADD COLUMN `phone` VARCHAR(64) NULL, ADD COLUMN `physical_address_1` VARCHAR(255) NULL, ADD COLUMN `physical_address_2` VARCHAR(255) NULL, ADD COLUMN `physical_city_town` VARCHAR(120) NULL, ADD COLUMN `physical_country` VARCHAR(80) NULL, ADD COLUMN `postal_address_1` VARCHAR(255) NULL, ADD COLUMN `postal_address_2` VARCHAR(255) NULL, ADD COLUMN `postal_address_3` VARCHAR(255) NULL, ADD COLUMN `postal_city_town` VARCHAR(120) NULL, ADD COLUMN `postal_code` VARCHAR(20) NULL, ADD COLUMN `postal_country` VARCHAR(80) NULL, ADD COLUMN `contact_method` VARCHAR(40) NULL, ADD COLUMN `notes` TEXT NULL, ADD COLUMN `updated_at` DATETIME NULL;
ALTER TABLE `contacts` ADD COLUMN `first_name` VARCHAR(100) NULL, ADD COLUMN `middle_name` VARCHAR(100) NULL, ADD COLUMN `last_name` VARCHAR(100) NULL, ADD COLUMN `mobile` VARCHAR(64) NULL, ADD COLUMN `position` VARCHAR(150) NULL, ADD COLUMN `contact_type` VARCHAR(255) NULL, ADD COLUMN `contact_method` VARCHAR(40) NULL, ADD COLUMN `status` VARCHAR(30) NULL, ADD COLUMN `notes` TEXT NULL, ADD COLUMN `updated_at` DATETIME NULL;
UPDATE `clients` SET `business_name` = COALESCE(`business_name`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."business_name"'))),'null'),'')),
  `email` = COALESCE(`email`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."email"'))),'null'),'')),
  `phone` = COALESCE(`phone`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."phone"'))),'null'),'')),
  `physical_address_1` = COALESCE(`physical_address_1`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."physical_address_1"'))),'null'),'')),
  `physical_address_2` = COALESCE(`physical_address_2`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."physical_address_2"'))),'null'),'')),
  `physical_city_town` = COALESCE(`physical_city_town`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."physical_city_town"'))),'null'),'')),
  `physical_country` = COALESCE(`physical_country`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."physical_country"'))),'null'),'')),
  `postal_address_1` = COALESCE(`postal_address_1`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_address_1"'))),'null'),'')),
  `postal_address_2` = COALESCE(`postal_address_2`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_address_2"'))),'null'),'')),
  `postal_address_3` = COALESCE(`postal_address_3`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_address_3"'))),'null'),'')),
  `postal_city_town` = COALESCE(`postal_city_town`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_city_town"'))),'null'),'')),
  `postal_code` = COALESCE(`postal_code`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_code"'))),'null'),'')),
  `postal_country` = COALESCE(`postal_country`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."postal_country"'))),'null'),'')),
  `contact_method` = COALESCE(`contact_method`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."contact_method"'))),'null'),'')),
  `notes` = COALESCE(`notes`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."notes"'))),'null'),''))
WHERE legacy_record IS NOT NULL;
UPDATE `contacts` SET `first_name` = COALESCE(`first_name`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."first_name"'))),'null'),'')),
  `middle_name` = COALESCE(`middle_name`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."mid_name"'))),'null'),'')),
  `last_name` = COALESCE(`last_name`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."last_name"'))),'null'),'')),
  `mobile` = COALESCE(`mobile`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."mobile"'))),'null'),'')),
  `position` = COALESCE(`position`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."position"'))),'null'),'')),
  `contact_type` = COALESCE(`contact_type`, CASE WHEN NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."contact_type"'))),'null'),'') LIKE '[%' THEN NULLIF(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."contact_type"'))),'null'),''),'[',''),']',''),'"',''),',',', ')),'') ELSE NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."contact_type"'))),'null'),'') END),
  `contact_method` = COALESCE(`contact_method`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."contact_method"'))),'null'),'')),
  `status` = COALESCE(`status`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."status"'))),'null'),'')),
  `notes` = COALESCE(`notes`, NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$."notes"'))),'null'),''))
WHERE legacy_record IS NOT NULL;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('001_editable_details','Editable client and contact detail columns, filled from imported records',NOW(),'phpMyAdmin');
