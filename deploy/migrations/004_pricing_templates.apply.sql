-- Cloake Manager migration 004_pricing_templates: Pricing module (price lines for enquiries/tasks/templates), task templates, planned tasks, stages, billing milestones
-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.
-- Run ONCE on a database that does not yet have these columns (check: More > Release check).

CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB;
ALTER TABLE `breakdown_items` ADD COLUMN `template_id` BIGINT UNSIGNED NULL, ADD COLUMN `plan_id` BIGINT UNSIGNED NULL, ADD COLUMN `line_order` SMALLINT UNSIGNED NULL, ADD COLUMN `account_name` VARCHAR(120) NULL, ADD COLUMN `created_at` DATETIME NULL, ADD COLUMN `updated_at` DATETIME NULL;
ALTER TABLE `enquiries` ADD COLUMN `billing_mode` VARCHAR(20) NULL;
ALTER TABLE `tasks` ADD COLUMN `billing_mode` VARCHAR(20) NULL, ADD COLUMN `parent_task_id` BIGINT UNSIGNED NULL, ADD COLUMN `stage_no` SMALLINT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS task_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, instructions TEXT NULL,
 task_category VARCHAR(80) NULL, task_type VARCHAR(120) NULL, assigned_to VARCHAR(255) NULL,
 pricing VARCHAR(20) NULL, chargeable VARCHAR(10) NULL, estimate_minutes INT UNSIGNED NULL, billing_mode VARCHAR(20) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, from_task_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_template_from_task (from_task_id), FOREIGN KEY (from_task_id) REFERENCES tasks(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS enquiry_task_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enquiry_id BIGINT UNSIGNED NOT NULL, plan_order SMALLINT UNSIGNED NULL,
 title VARCHAR(255) NOT NULL, instructions TEXT NULL, task_category VARCHAR(80) NULL, task_type VARCHAR(120) NULL,
 assigned_to VARCHAR(255) NULL, pricing VARCHAR(20) NULL, chargeable VARCHAR(10) NULL, estimate_minutes INT UNSIGNED NULL,
 billing_mode VARCHAR(20) NULL, template_id BIGINT UNSIGNED NULL, task_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_plan_task (task_id), KEY ix_plan_enquiry (enquiry_id),
 FOREIGN KEY (enquiry_id) REFERENCES enquiries(id), FOREIGN KEY (task_id) REFERENCES tasks(id), FOREIGN KEY (template_id) REFERENCES task_templates(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS billing_milestones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enquiry_id BIGINT UNSIGNED NULL, task_id BIGINT UNSIGNED NULL,
 template_id BIGINT UNSIGNED NULL, plan_id BIGINT UNSIGNED NULL, milestone_order SMALLINT UNSIGNED NULL,
 label VARCHAR(255) NOT NULL, amount_ex_gst DECIMAL(12,2) NULL, percent DECIMAL(5,2) NULL,
 trigger_kind VARCHAR(20) NOT NULL, due_date DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'planned',
 marked_ready_at DATETIME NULL, marked_ready_by VARCHAR(100) NULL, void_reason VARCHAR(255) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 KEY ix_ms_enquiry (enquiry_id), KEY ix_ms_task (task_id), KEY ix_ms_template (template_id),
 FOREIGN KEY (enquiry_id) REFERENCES enquiries(id), FOREIGN KEY (task_id) REFERENCES tasks(id),
 FOREIGN KEY (template_id) REFERENCES task_templates(id), FOREIGN KEY (plan_id) REFERENCES enquiry_task_plans(id)
) ENGINE=InnoDB;
INSERT INTO task_templates (title,instructions,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode,active,from_task_id,created_at)
SELECT tk.title, CASE WHEN BINARY RIGHT(tk.instructions, 2) = BINARY 'rn' THEN LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tk.instructions, '\\r\\n', '\n'), 'rnA', '\nA'), 'rnB', '\nB'), 'rnC', '\nC'), 'rnD', '\nD'), 'rnE', '\nE'), 'rnF', '\nF'), 'rnG', '\nG'), 'rnH', '\nH'), 'rnI', '\nI'), 'rnJ', '\nJ'), 'rnK', '\nK'), 'rnL', '\nL'), 'rnM', '\nM'), 'rnN', '\nN'), 'rnO', '\nO'), 'rnP', '\nP'), 'rnQ', '\nQ'), 'rnR', '\nR'), 'rnS', '\nS'), 'rnT', '\nT'), 'rnU', '\nU'), 'rnV', '\nV'), 'rnW', '\nW'), 'rnX', '\nX'), 'rnY', '\nY'), 'rnZ', '\nZ'), CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tk.instructions, '\\r\\n', '\n'), 'rnA', '\nA'), 'rnB', '\nB'), 'rnC', '\nC'), 'rnD', '\nD'), 'rnE', '\nE'), 'rnF', '\nF'), 'rnG', '\nG'), 'rnH', '\nH'), 'rnI', '\nI'), 'rnJ', '\nJ'), 'rnK', '\nK'), 'rnL', '\nL'), 'rnM', '\nM'), 'rnN', '\nN'), 'rnO', '\nO'), 'rnP', '\nP'), 'rnQ', '\nQ'), 'rnR', '\nR'), 'rnS', '\nS'), 'rnT', '\nT'), 'rnU', '\nU'), 'rnV', '\nV'), 'rnW', '\nW'), 'rnX', '\nX'), 'rnY', '\nY'), 'rnZ', '\nZ')) - 2) ELSE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tk.instructions, '\\r\\n', '\n'), 'rnA', '\nA'), 'rnB', '\nB'), 'rnC', '\nC'), 'rnD', '\nD'), 'rnE', '\nE'), 'rnF', '\nF'), 'rnG', '\nG'), 'rnH', '\nH'), 'rnI', '\nI'), 'rnJ', '\nJ'), 'rnK', '\nK'), 'rnL', '\nL'), 'rnM', '\nM'), 'rnN', '\nN'), 'rnO', '\nO'), 'rnP', '\nP'), 'rnQ', '\nQ'), 'rnR', '\nR'), 'rnS', '\nS'), 'rnT', '\nT'), 'rnU', '\nU'), 'rnV', '\nV'), 'rnW', '\nW'), 'rnX', '\nX'), 'rnY', '\nY'), 'rnZ', '\nZ') END, tk.task_category, tk.task_type, tk.assigned_to, tk.pricing,
  CASE WHEN tk.chargeable LIKE '%yes%' THEN 'yes' WHEN tk.chargeable LIKE '%no%' THEN 'no' ELSE NULL END,
  NULLIF(tk.estimate_minutes, 0), NULL, 1, tk.id, NOW()
FROM tasks tk WHERE tk.id IN (SELECT tk.id FROM tasks tk JOIN clients c ON c.id = tk.client_id
  WHERE LOWER(TRIM(c.name)) IN ('pro forma','pro-forma','proforma')
    AND NOT (LOWER(TRIM(COALESCE(tk.status,''))) IN ('completed','complete','closed','cancelled','canceled','done')) AND tk.completed_at IS NULL) AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id = tk.id);
INSERT INTO breakdown_items (template_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at)
SELECT tt.id, tt.title, 1, tk.agreed_fee_ex_gst, 'set', 'proposed', 1, NOW()
FROM task_templates tt JOIN tasks tk ON tk.id = tt.from_task_id
WHERE tk.pricing = 'set' AND tk.agreed_fee_ex_gst > 0 AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.template_id = tt.id);
UPDATE breakdown_items SET line_order = 1 WHERE line_order IS NULL AND enquiry_id IS NOT NULL;
INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('004_pricing_templates','Pricing module (price lines for enquiries/tasks/templates), task templates, planned tasks, stages, billing milestones',NOW(),'phpMyAdmin');
