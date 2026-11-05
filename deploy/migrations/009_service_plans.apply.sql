-- Cloake Manager migration 009_service_plans: Service plans (plans table, services.plan_id, services.mailboxes)
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL, name VARCHAR(50) NOT NULL, kind VARCHAR(20) NOT NULL,
 price_ex_gst DECIMAL(12,2) NULL, price_months TINYINT UNSIGNED NOT NULL DEFAULT 6, xero_account VARCHAR(20) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT UNSIGNED NULL, notes VARCHAR(500) NULL, xero_item_id VARCHAR(40) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB;
ALTER TABLE `services` ADD COLUMN `plan_id` BIGINT UNSIGNED NULL, ADD COLUMN `mailboxes` SMALLINT UNSIGNED NULL;
INSERT INTO plans (code,name,kind,price_ex_gst,price_months,xero_account,active,sort_order,notes,created_at) VALUES
 ('HOST-ESS','Website hosting - Essential','hosting',135.00,6,NULL,1,10,'Simple and HTML sites',NOW()),
 ('HOST-STD','Website hosting - Standard','hosting',299.70,6,NULL,1,20,'CMS sites. Older clients may be on a legacy price (e.g. 201.00)',NOW()),
 ('HOST-ADV','Website hosting - Advanced','hosting',390.00,6,NULL,1,30,'E-commerce, booking, mapping and other special features',NOW()),
 ('MAILBOX','Email mailbox','mailbox',2.95,1,NULL,1,40,'Per provisioned mailbox, per month',NOW()),
 ('DNS-FWD','DNS and forwarding','dns',NULL,6,NULL,1,50,'Price per client (often no charge)',NOW()),
 ('DOMAIN','Domain renewal','domain',49.95,12,NULL,1,60,NULL,NOW())
 ON DUPLICATE KEY UPDATE code=code;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('009_service_plans','Service plans (plans table, services.plan_id, services.mailboxes)',NOW(),'phpMyAdmin');
