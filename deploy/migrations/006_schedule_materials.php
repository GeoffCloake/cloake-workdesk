<?php
declare(strict_types=1);
/*
 * Migration 006 — scheduling and materials (v0.14.0).
 * ADD-ONLY: two new columns on tasks, two new tables, two settings; no existing value is changed.
 *
 *  tasks.scheduled_on / scheduled_minutes   the day a task (or stage) is planned for, and the time booked
 *  staff        the people on the Workload screen: name (as used in "Action for"), active, minutes available per weekday
 *               — seeded from the names already in Action for, 6 h Mon–Fri; active only with an open or recent (12 months) task
 *  job_items    materials bought for a job: supplier, cost and sell price ex GST, ordered / received / cancelled;
 *               received items go to Pending Invoicing (charges.source_kind = 'material')
 *  settings     material_markup_pct (blank = sell at cost), work_day_minutes (360)
 */
return [
    'id' => '006_schedule_materials',
    'description' => 'Scheduling (tasks.scheduled_on/minutes, staff) and materials (job_items)',
    'columns' => [
        'tasks' => [
            'scheduled_on' => 'DATE NULL',
            'scheduled_minutes' => 'INT UNSIGNED NULL',
        ],
    ],
    'tables' => [
        'staff' => "CREATE TABLE IF NOT EXISTS staff (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL, display_name VARCHAR(100) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 min_mon SMALLINT UNSIGNED NOT NULL DEFAULT 360, min_tue SMALLINT UNSIGNED NOT NULL DEFAULT 360, min_wed SMALLINT UNSIGNED NOT NULL DEFAULT 360,
 min_thu SMALLINT UNSIGNED NOT NULL DEFAULT 360, min_fri SMALLINT UNSIGNED NOT NULL DEFAULT 360, min_sat SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 min_sun SMALLINT UNSIGNED NOT NULL DEFAULT 0, sort_order SMALLINT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_staff_name (name)
) ENGINE=InnoDB",
        'job_items' => "CREATE TABLE IF NOT EXISTS job_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NULL,
 description VARCHAR(500) NOT NULL, supplier VARCHAR(150) NULL, supplier_ref VARCHAR(100) NULL,
 quantity DECIMAL(12,3) NOT NULL DEFAULT 1, unit_cost_ex_gst DECIMAL(12,2) NOT NULL DEFAULT 0, unit_sell_ex_gst DECIMAL(12,2) NOT NULL DEFAULT 0,
 status VARCHAR(12) NOT NULL DEFAULT 'ordered', ordered_on DATE NULL, received_on DATE NULL, cancel_reason VARCHAR(255) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 KEY ix_items_task (task_id), KEY ix_items_status (status),
 FOREIGN KEY (task_id) REFERENCES tasks(id), FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB",
    ],
    'backfill_sql' => [
        "INSERT INTO settings (k,v,updated_at,updated_by) VALUES ('material_markup_pct','',NOW(),'migration'),('work_day_minutes','360',NOW(),'migration') ON DUPLICATE KEY UPDATE k=k",
        // One staff row per name already used in "Action for" (comma-separated, lower-case), 6 h Mon–Fri.
        "INSERT INTO staff (name,created_at)
SELECT DISTINCT n.name, NOW() FROM (
  SELECT LOWER(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(t.assigned_to, ',', k.i), ',', -1))) name
  FROM tasks t JOIN (SELECT 1 i UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) k
    ON k.i <= 1 + CHAR_LENGTH(t.assigned_to) - CHAR_LENGTH(REPLACE(t.assigned_to, ',', ''))
  WHERE t.assigned_to IS NOT NULL AND t.assigned_to <> ''
) n WHERE n.name <> '' AND CHAR_LENGTH(n.name) <= 40 AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.name = n.name)",
        // Only people with an open task, or a task received in the last 12 months, start as active (changeable on the Staff screen).
        "UPDATE staff s SET s.active = 0 WHERE s.created_at >= NOW() - INTERVAL 1 HOUR AND NOT EXISTS (SELECT 1 FROM tasks t
  WHERE CONCAT(',', REPLACE(LOWER(COALESCE(t.assigned_to,'')), ' ', ''), ',') LIKE CONCAT('%,', s.name, ',%')
    AND (t.received_at >= NOW() - INTERVAL 12 MONTH OR (t.completed_at IS NULL AND LOWER(TRIM(COALESCE(t.status,''))) NOT IN ('completed','complete','closed','cancelled','canceled','done'))))",
    ],
    'verify_sql' => [
        'settings missing' => "SELECT 2 - COUNT(*) FROM settings WHERE k IN ('material_markup_pct','work_day_minutes')",
    ],
];
