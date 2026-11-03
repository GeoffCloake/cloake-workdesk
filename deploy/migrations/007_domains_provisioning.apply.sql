-- Cloake Manager migration 007_domains_provisioning: Domain register fields on services (name servers, DNS host, auto-renew, included in, provider ref, provisioning task) and a set-up template
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `services` ADD COLUMN `nameservers` VARCHAR(255) NULL, ADD COLUMN `dns_host` VARCHAR(60) NULL, ADD COLUMN `auto_renew` TINYINT(1) NULL, ADD COLUMN `included_in_service_id` BIGINT UNSIGNED NULL, ADD COLUMN `provider_ref` VARCHAR(100) NULL, ADD COLUMN `provision_task_id` BIGINT UNSIGNED NULL;
INSERT INTO task_templates (title,instructions,task_category,task_type,pricing,chargeable,active,created_at)
SELECT 'Set up hosting + domain', 'Checklist:
1. Register or transfer the domain (registrar / Vetta portal) — record expiry, registrar and provider reference on the service
2. DNS: set name servers / records — record DNS host and name servers on the service
3. Create the cPanel account — record the cPanel username (never the password)
4. Email accounts
5. SSL certificate issued
6. Site live and checked
7. Billing: set price, cycle and first period on the service', 'Website design and management', NULL, 'web_service', 'yes', 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM task_templates WHERE title='Set up hosting + domain');
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('007_domains_provisioning','Domain register fields on services (name servers, DNS host, auto-renew, included in, provider ref, provisioning task) and a set-up template',NOW(),'phpMyAdmin');
