-- Cloake Manager migration 008_xero_drafts: charges.xero_invoice_id for invoices created as Xero drafts
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `charges` ADD COLUMN `xero_invoice_id` VARCHAR(40) NULL;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('008_xero_drafts','charges.xero_invoice_id for invoices created as Xero drafts',NOW(),'phpMyAdmin');
