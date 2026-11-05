-- Cloake Manager migration 010_billing_status: services.keep_billing (bill while suspended / in development)
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `services` ADD COLUMN `keep_billing` TINYINT(1) NOT NULL DEFAULT 0;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('010_billing_status','services.keep_billing (bill while suspended / in development)',NOW(),'phpMyAdmin');
