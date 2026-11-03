-- UNDO migration 007_domains_provisioning. Drops the added columns AND anything entered into them since.
-- Only for a failed migration. Rolling back the app code does not require this.

DELETE FROM task_templates WHERE title='Set up hosting + domain' AND from_task_id IS NULL AND NOT EXISTS (SELECT 1 FROM enquiry_task_plans p WHERE p.template_id=task_templates.id) AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.template_id=task_templates.id) AND NOT EXISTS (SELECT 1 FROM billing_milestones m WHERE m.template_id=task_templates.id);
ALTER TABLE `services` DROP COLUMN `nameservers`, DROP COLUMN `dns_host`, DROP COLUMN `auto_renew`, DROP COLUMN `included_in_service_id`, DROP COLUMN `provider_ref`, DROP COLUMN `provision_task_id`;
DELETE FROM schema_migrations WHERE id='007_domains_provisioning';
