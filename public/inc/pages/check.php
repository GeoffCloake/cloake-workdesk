<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Release check: proves which build is running and that the schema it relies on
 * is present. Read-only (information_schema + COUNTs). Use after every deploy.
 */
$checks = [];
$add = function (string $label, bool $ok, string $detail = '') use (&$checks) { $checks[] = [$label, $ok, $detail]; };
$db = oneRow($pdo, 'SELECT DATABASE() db, VERSION() v, NOW() now_db');
$add('Database connection', true, (string)$db['v']);
$tables = array_column(allRows($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'), 'TABLE_NAME');
foreach (['clients', 'contacts', 'tasks', 'work_log', 'enquiries', 'activity_events', 'breakdown_items', 'account_items', 'financial_documents'] as $t) $add("Table $t", in_array($t, $tables, true));
$legacy = array_values(array_filter($tables, fn($t) => str_starts_with($t, 'legacy_')));
$add('Legacy archive tables (6 expected)', count($legacy) === 6, implode(', ', $legacy));
$cols = [];
foreach (allRows($pdo, 'SELECT TABLE_NAME t, COLUMN_NAME c, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $r) $cols[$r['t'] . '.' . $r['c']] = $r['n'];
foreach (['work_log.work_date', 'work_log.duration_seconds', 'work_log.billable_seconds', 'work_log.billability', 'tasks.enquiry_id', 'enquiries.next_action_due', 'enquiries.legacy_source_id', 'activity_events.payload'] as $c) $add("Column $c", isset($cols[$c]));
$add('tasks.client_id allows unassigned (M3D)', ($cols['tasks.client_id'] ?? '') === 'YES');
$mig001 = ['clients.email', 'clients.business_name', 'clients.physical_city_town', 'clients.notes', 'clients.updated_at', 'contacts.mobile', 'contacts.first_name', 'contacts.status', 'contacts.updated_at'];
$missing001 = array_values(array_filter($mig001, fn($c) => !isset($cols[$c])));
$ledger = in_array('schema_migrations', $tables, true) ? array_column(allRows($pdo, 'SELECT id FROM schema_migrations'), 'id') : [];
$mig002 = ['tasks.contact_id', 'tasks.assigned_to', 'tasks.queue', 'tasks.task_category', 'tasks.task_type', 'tasks.domains', 'clients.postal_region', 'clients.credit_approved', 'clients.comments', 'contacts.title'];
$missing002 = array_values(array_filter($mig002, fn($c) => !isset($cols[$c])));
$add('Migration 001 — editable client & contact details', !$missing001 && in_array('001_editable_details', $ledger, true), $missing001 ? 'missing: ' . implode(', ', $missing001) : (in_array('001_editable_details', $ledger, true) ? 'applied' : 'columns present but not recorded'));
$add('Migration 002 — task workload fields', !$missing002 && in_array('002_task_workload_fields', $ledger ?? [], true), $missing002 ? 'missing: ' . implode(', ', $missing002) : 'applied');
$mig003 = ['enquiries.received_at', 'enquiries.contact_id', 'enquiries.task_category', 'enquiries.task_type', 'enquiries.duplicate_of', 'enquiries.closed_reason', 'tasks.signed_off_at', 'tasks.signed_off_by', 'tasks.sign_off_note'];
$missing003 = array_values(array_filter($mig003, fn($c) => !isset($cols[$c])));
$add('Migration 003 — enquiry details & task sign-off', !$missing003 && in_array('003_enquiry_signoff', $ledger ?? [], true), $missing003 ? 'missing: ' . implode(', ', $missing003) : (in_array('003_enquiry_signoff', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
$mig004 = ['breakdown_items.template_id', 'breakdown_items.plan_id', 'breakdown_items.line_order', 'breakdown_items.account_name', 'enquiries.billing_mode', 'tasks.billing_mode', 'tasks.parent_task_id', 'tasks.stage_no', 'task_templates.id', 'enquiry_task_plans.id', 'billing_milestones.id'];
$missing004 = array_values(array_filter($mig004, fn($c) => !isset($cols[$c])));
$add('Migration 004 — pricing, templates, planned tasks, stages, milestones', !$missing004 && in_array('004_pricing_templates', $ledger ?? [], true), $missing004 ? 'missing: ' . implode(', ', $missing004) : (in_array('004_pricing_templates', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
$mig005 = ['services.id', 'charges.id', 'settings.k', 'archive_whmcs.id', 'work_log.charge_id'];
$missing005 = array_values(array_filter($mig005, fn($c) => !isset($cols[$c])));
$add('Migration 005 — services, charges, billing settings, WHMCS archive', !$missing005 && in_array('005_services_billing', $ledger ?? [], true), $missing005 ? 'missing: ' . implode(', ', $missing005) : (in_array('005_services_billing', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
$mig006 = ['tasks.scheduled_on', 'tasks.scheduled_minutes', 'staff.id', 'job_items.id'];
$missing006 = array_values(array_filter($mig006, fn($c) => !isset($cols[$c])));
$add('Migration 006 — scheduling and materials', !$missing006 && in_array('006_schedule_materials', $ledger ?? [], true), $missing006 ? 'missing: ' . implode(', ', $missing006) : (in_array('006_schedule_materials', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
$mig007 = ['services.nameservers', 'services.dns_host', 'services.auto_renew', 'services.included_in_service_id', 'services.provider_ref', 'services.provision_task_id'];
$missing007 = array_values(array_filter($mig007, fn($c) => !isset($cols[$c])));
$add('Migration 007 — domain register and provisioning', !$missing007 && in_array('007_domains_provisioning', $ledger ?? [], true), $missing007 ? 'missing: ' . implode(', ', $missing007) : (in_array('007_domains_provisioning', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
$missing008 = isset($cols['charges.xero_invoice_id']) ? [] : ['charges.xero_invoice_id'];
$add('Migration 008 — Xero draft invoices', !$missing008 && in_array('008_xero_drafts', $ledger ?? [], true), $missing008 ? 'missing: charges.xero_invoice_id' : (in_array('008_xero_drafts', $ledger ?? [], true) ? 'applied' : 'columns present but not recorded'));
if (!$missing005) { $w = oneRow($pdo, "SELECT (SELECT COUNT(*) FROM services) s, (SELECT COUNT(*) FROM archive_whmcs) a, (SELECT COUNT(*) FROM charges) c, (SELECT COUNT(*) FROM archive_whmcs WHERE JSON_SEARCH(source_record,'one','%password%') IS NOT NULL OR JSON_CONTAINS_PATH(source_record,'one','$.Password')) pw");
  $add('WHMCS import', true, (int)$w['s'] ? $w['s'] . ' services, ' . $w['a'] . ' archived WHMCS rows, ' . $w['c'] . ' invoiced periods' : 'not imported yet');
  $add('No passwords in the WHMCS archive', (int)$w['pw'] === 0, (int)$w['pw'] ? $w['pw'] . ' rows mention a password' : ''); }
$uq = oneRow($pdo, "SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND INDEX_NAME='uq_task_source'");
$add('Duplicate-conversion guard (tasks unique source key)', (int)$uq['n'] > 0);
$counts = oneRow($pdo, "SELECT (SELECT COUNT(*) FROM clients) clients, (SELECT COUNT(*) FROM contacts) contacts,
  (SELECT COUNT(*) FROM tasks WHERE source_system='fabrik') tasks_imported, (SELECT COUNT(*) FROM tasks WHERE source_system IS NULL OR source_system<>'fabrik') tasks_new,
  (SELECT COUNT(*) FROM work_log WHERE source_system='fabrik') work_imported, (SELECT COUNT(*) FROM work_log WHERE source_system IS NULL OR source_system<>'fabrik') work_new,
  (SELECT COUNT(*) FROM enquiries) enquiries, (SELECT COUNT(*) FROM activity_events) events" . (!$missing004 ? ", (SELECT COUNT(*) FROM task_templates) templates, (SELECT COUNT(*) FROM breakdown_items) price_lines, (SELECT COUNT(*) FROM billing_milestones) milestones" : ''));
$allOk = !in_array(false, array_column($checks, 1), true);
?>
<div class="panel check-head <?=$allOk ? 'ok' : 'bad'?>"><p><strong><?=$allOk ? 'All checks passed' : 'Some checks failed'?></strong></p>
<dl><dt>Application</dt><dd data-testid="check-version"><?=h(cm_version_label())?></dd><dt>PHP</dt><dd><?=h(PHP_VERSION)?></dd><dt>App time (Pacific/Auckland)</dt><dd><?=h(date('Y-m-d H:i'))?></dd><dt>Database time</dt><dd><?=h($db['now_db'])?></dd></dl></div>
<ul class="checklist"><?php foreach ($checks as [$label, $ok, $detail]): ?><li class="<?=$ok ? 'ok' : 'bad'?>"><span aria-hidden="true"><?=$ok ? '✓' : '✗'?></span> <?=h($label)?><?=$detail !== '' ? ' <small>' . h($detail) . '</small>' : ''?><?=$ok ? '' : ' <strong>(failed)</strong>'?></li><?php endforeach; ?></ul>
<details><summary>Record counts (diagnostic)</summary><dl><?php foreach (['clients' => 'Clients', 'contacts' => 'Contacts', 'tasks_imported' => 'Tasks — imported', 'tasks_new' => 'Tasks — created here', 'work_imported' => 'Work Log — imported', 'work_new' => 'Work Log — created here', 'enquiries' => 'Enquiries', 'events' => 'Activity events', 'templates' => 'Task templates', 'price_lines' => 'Price lines', 'milestones' => 'Milestones'] as $k => $label): if (!isset($counts[$k])) continue; ?><dt><?=h($label)?></dt><dd><?=number_format((int)$counts[$k])?></dd><?php endforeach; ?></dl></details>
