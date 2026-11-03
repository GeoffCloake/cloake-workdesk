<?php
declare(strict_types=1);
/*
 * Migration 004 — pricing module, task templates, planned tasks, stages, progressive billing (v0.12.0).
 * ADD-ONLY: new nullable columns and three new tables; no existing value is changed.
 *
 *  breakdown_items   price lines, now shared by enquiries, tasks and templates (+ order, account name, planned task)
 *  enquiries/tasks   billing_mode (NULL = on completion | monthly | milestones)
 *  tasks             parent_task_id + stage_no (stages, one level deep)
 *  task_templates    reusable tasks (replace the "Pro forma" client's tasks)
 *  enquiry_task_plans  tasks planned on an enquiry, converted to real tasks when ticked
 *  billing_milestones  progressive-billing milestones for an enquiry, task or template
 *
 * Seed: every open task of the imported "Pro forma" client is COPIED into task_templates
 * (from_task_id = the task). A set fee becomes the template's price line. The tasks themselves
 * are untouched; retiring them is a separate, undoable step in the app.
 */

// Fabrik lost the backslashes of \r\n in some instructions ("Stage1rnInstall", "terminaternSet").
// Turn "rn" before a capital letter, and at the very end, back into a line break; literal "\r\n" too.
$clean = 'tk.instructions';
$clean = "REPLACE($clean, '\\\\r\\\\n', '\\n')";
foreach (range('A', 'Z') as $L) $clean = "REPLACE($clean, 'rn$L', '\\n$L')";
$clean = "CASE WHEN BINARY RIGHT(tk.instructions, 2) = BINARY 'rn' THEN LEFT($clean, CHAR_LENGTH($clean) - 2) ELSE $clean END";

$proForma = "SELECT tk.id FROM tasks tk JOIN clients c ON c.id = tk.client_id
  WHERE LOWER(TRIM(c.name)) IN ('pro forma','pro-forma','proforma')
    AND NOT (LOWER(TRIM(COALESCE(tk.status,''))) IN ('completed','complete','closed','cancelled','canceled','done')) AND tk.completed_at IS NULL";

return [
    'id' => '004_pricing_templates',
    'description' => 'Pricing module (price lines for enquiries/tasks/templates), task templates, planned tasks, stages, billing milestones',
    'columns' => [
        'breakdown_items' => [
            'template_id' => 'BIGINT UNSIGNED NULL',
            'plan_id' => 'BIGINT UNSIGNED NULL',
            'line_order' => 'SMALLINT UNSIGNED NULL',
            'account_name' => 'VARCHAR(120) NULL',
            'created_at' => 'DATETIME NULL',
            'updated_at' => 'DATETIME NULL',
        ],
        'enquiries' => [
            'billing_mode' => 'VARCHAR(20) NULL',
        ],
        'tasks' => [
            'billing_mode' => 'VARCHAR(20) NULL',
            'parent_task_id' => 'BIGINT UNSIGNED NULL',
            'stage_no' => 'SMALLINT UNSIGNED NULL',
        ],
    ],
    'tables' => [
        'task_templates' => "CREATE TABLE IF NOT EXISTS task_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, instructions TEXT NULL,
 task_category VARCHAR(80) NULL, task_type VARCHAR(120) NULL, assigned_to VARCHAR(255) NULL,
 pricing VARCHAR(20) NULL, chargeable VARCHAR(10) NULL, estimate_minutes INT UNSIGNED NULL, billing_mode VARCHAR(20) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, from_task_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_template_from_task (from_task_id), FOREIGN KEY (from_task_id) REFERENCES tasks(id)
) ENGINE=InnoDB",
        'enquiry_task_plans' => "CREATE TABLE IF NOT EXISTS enquiry_task_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enquiry_id BIGINT UNSIGNED NOT NULL, plan_order SMALLINT UNSIGNED NULL,
 title VARCHAR(255) NOT NULL, instructions TEXT NULL, task_category VARCHAR(80) NULL, task_type VARCHAR(120) NULL,
 assigned_to VARCHAR(255) NULL, pricing VARCHAR(20) NULL, chargeable VARCHAR(10) NULL, estimate_minutes INT UNSIGNED NULL,
 billing_mode VARCHAR(20) NULL, template_id BIGINT UNSIGNED NULL, task_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_plan_task (task_id), KEY ix_plan_enquiry (enquiry_id),
 FOREIGN KEY (enquiry_id) REFERENCES enquiries(id), FOREIGN KEY (task_id) REFERENCES tasks(id), FOREIGN KEY (template_id) REFERENCES task_templates(id)
) ENGINE=InnoDB",
        'billing_milestones' => "CREATE TABLE IF NOT EXISTS billing_milestones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enquiry_id BIGINT UNSIGNED NULL, task_id BIGINT UNSIGNED NULL,
 template_id BIGINT UNSIGNED NULL, plan_id BIGINT UNSIGNED NULL, milestone_order SMALLINT UNSIGNED NULL,
 label VARCHAR(255) NOT NULL, amount_ex_gst DECIMAL(12,2) NULL, percent DECIMAL(5,2) NULL,
 trigger_kind VARCHAR(20) NOT NULL, due_date DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'planned',
 marked_ready_at DATETIME NULL, marked_ready_by VARCHAR(100) NULL, void_reason VARCHAR(255) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 KEY ix_ms_enquiry (enquiry_id), KEY ix_ms_task (task_id), KEY ix_ms_template (template_id),
 FOREIGN KEY (enquiry_id) REFERENCES enquiries(id), FOREIGN KEY (task_id) REFERENCES tasks(id),
 FOREIGN KEY (template_id) REFERENCES task_templates(id), FOREIGN KEY (plan_id) REFERENCES enquiry_task_plans(id)
) ENGINE=InnoDB",
    ],
    'backfill_sql' => [
        // Copy each open pro-forma task into a template (once: from_task_id is unique).
        "INSERT INTO task_templates (title,instructions,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode,active,from_task_id,created_at)
SELECT tk.title, $clean, tk.task_category, tk.task_type, tk.assigned_to, tk.pricing,
  CASE WHEN tk.chargeable LIKE '%yes%' THEN 'yes' WHEN tk.chargeable LIKE '%no%' THEN 'no' ELSE NULL END,
  NULLIF(tk.estimate_minutes, 0), NULL, 1, tk.id, NOW()
FROM tasks tk WHERE tk.id IN ($proForma) AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id = tk.id)",
        // Its set fee becomes the template's price line.
        "INSERT INTO breakdown_items (template_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at)
SELECT tt.id, tt.title, 1, tk.agreed_fee_ex_gst, 'set', 'proposed', 1, NOW()
FROM task_templates tt JOIN tasks tk ON tk.id = tt.from_task_id
WHERE tk.pricing = 'set' AND tk.agreed_fee_ex_gst > 0 AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.template_id = tt.id)",
        // Existing enquiry price lines keep their order.
        "UPDATE breakdown_items SET line_order = 1 WHERE line_order IS NULL AND enquiry_id IS NOT NULL",
    ],
    'verify_sql' => [
        'open pro-forma tasks without a template' => "SELECT COUNT(*) FROM tasks tk WHERE tk.id IN ($proForma) AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id = tk.id)",
        'template title differs from its task' => 'SELECT COUNT(*) FROM task_templates tt JOIN tasks tk ON tk.id = tt.from_task_id WHERE tt.updated_at IS NULL AND BINARY tt.title <> BINARY tk.title',
        'set fee not carried to the template' => "SELECT COUNT(*) FROM task_templates tt JOIN tasks tk ON tk.id = tt.from_task_id WHERE tk.pricing = 'set' AND tk.agreed_fee_ex_gst > 0 AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.template_id = tt.id AND b.unit_price_ex_gst = tk.agreed_fee_ex_gst)",
    ],
    // Run before the tables/columns are dropped: template price lines would otherwise be left without an owner.
    'rollback_sql' => [
        'DELETE FROM breakdown_items WHERE template_id IS NOT NULL AND enquiry_id IS NULL AND task_id IS NULL',
    ],
];
