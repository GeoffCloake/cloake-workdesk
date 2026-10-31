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
$add('Migration 001 — editable client & contact details', !$missing001 && in_array('001_editable_details', $ledger, true), $missing001 ? 'missing: ' . implode(', ', $missing001) : (in_array('001_editable_details', $ledger, true) ? 'applied' : 'columns present but not recorded'));
$uq = oneRow($pdo, "SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND INDEX_NAME='uq_task_source'");
$add('Duplicate-conversion guard (tasks unique source key)', (int)$uq['n'] > 0);
$counts = oneRow($pdo, "SELECT (SELECT COUNT(*) FROM clients) clients, (SELECT COUNT(*) FROM contacts) contacts,
  (SELECT COUNT(*) FROM tasks WHERE source_system='fabrik') tasks_imported, (SELECT COUNT(*) FROM tasks WHERE source_system IS NULL OR source_system<>'fabrik') tasks_new,
  (SELECT COUNT(*) FROM work_log WHERE source_system='fabrik') work_imported, (SELECT COUNT(*) FROM work_log WHERE source_system IS NULL OR source_system<>'fabrik') work_new,
  (SELECT COUNT(*) FROM enquiries) enquiries, (SELECT COUNT(*) FROM activity_events) events");
$allOk = !in_array(false, array_column($checks, 1), true);
?>
<div class="panel check-head <?=$allOk ? 'ok' : 'bad'?>"><p><strong><?=$allOk ? 'All checks passed' : 'Some checks failed'?></strong></p>
<dl><dt>Application</dt><dd data-testid="check-version"><?=h(cm_version_label())?></dd><dt>PHP</dt><dd><?=h(PHP_VERSION)?></dd><dt>App time (Pacific/Auckland)</dt><dd><?=h(date('Y-m-d H:i'))?></dd><dt>Database time</dt><dd><?=h($db['now_db'])?></dd></dl></div>
<ul class="checklist"><?php foreach ($checks as [$label, $ok, $detail]): ?><li class="<?=$ok ? 'ok' : 'bad'?>"><span aria-hidden="true"><?=$ok ? '✓' : '✗'?></span> <?=h($label)?><?=$detail !== '' ? ' <small>' . h($detail) . '</small>' : ''?><?=$ok ? '' : ' <strong>(failed)</strong>'?></li><?php endforeach; ?></ul>
<details><summary>Record counts (diagnostic)</summary><dl><?php foreach (['clients' => 'Clients', 'contacts' => 'Contacts', 'tasks_imported' => 'Tasks — imported', 'tasks_new' => 'Tasks — created here', 'work_imported' => 'Work Log — imported', 'work_new' => 'Work Log — created here', 'enquiries' => 'Enquiries', 'events' => 'Activity events'] as $k => $label): ?><dt><?=h($label)?></dt><dd><?=number_format((int)$counts[$k])?></dd><?php endforeach; ?></dl></details>
