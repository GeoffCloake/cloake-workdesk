<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Task detail. Imported tasks: status/dates/priority/notes/client editable; title, instructions and billing locked. */
$pageOpt = ['back' => [route('tasks'), 'Tasks'], 'h1' => false];
$t = oneRow($pdo, 'SELECT t.*,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [$id]);
if (!$t) { http_response_code(404); echo '<p class="emptyline">Task not found. <a href="' . h(route('tasks')) . '">Back to tasks</a></p>'; return; }
$pageTitle = $t['title'];
$entries = allRows($pdo, 'SELECT id,details,staff_key,work_date,started_at,duration_seconds,billable_seconds,billability,source_system FROM work_log WHERE task_id=? ORDER BY COALESCE(work_date,DATE(started_at)) DESC, id DESC LIMIT 100', [$id]);
?>
<header class="client-head"><h1><?=h($t['title'])?></h1>
<p class="client-sub"><?php if ($t['client_id']): ?><a href="<?=h(route('client', ['id' => $t['client_id']]))?>"><?=h($t['client_name'])?></a><?php else: ?>Unassigned<?php endif; ?> · <?=($t['source_system'] ?? '') === 'fabrik' ? 'Original task #' . h($t['source_id']) : 'Task #' . (int)$t['id']?></p></header>
<?php if (($_GET['saved'] ?? null) === '1'): ?><div class="success" role="status">Saved.</div><?php elseif (($_GET['saved'] ?? null) === '0'): ?><div class="success" role="status">No changes to save.</div><?php endif; ?>
<nav class="client-actions three" aria-label="Task actions">
  <a class="ca primary" href="add-work.php?task_id=<?=(int)$t['id']?>"><span aria-hidden="true">＋</span> Log work</a>
  <a class="ca" href="edit-task.php?id=<?=(int)$t['id']?>"><?=$t['legacy_record'] === null ? 'Edit task' : 'Update status'?></a>
  <?php if (!empty($t['enquiry_id'])): ?><a class="ca" href="convert-enquiry.php?id=<?=(int)$t['enquiry_id']?>">Enquiry #<?=(int)$t['enquiry_id']?></a><?php elseif ($t['client_id']): ?><a class="ca" href="<?=h(route('client', ['id' => $t['client_id']]))?>">Client</a><?php endif; ?>
</nav>
<div class="client-summary">
  <div><small>Status</small><strong><?=h(cm_status_label($t['status']))?></strong></div>
  <div><small>Required by</small><strong><?=h(niceDate($t['required_by']))?></strong></div>
  <div><small>Priority</small><strong><?=h(cm_status_label($t['priority']))?></strong></div>
</div>
<div class="panel"><h2 class="panel-title">Instructions</h2><div class="prewrap"><?=h($t['instructions'] ?: 'No instructions recorded')?></div></div>
<?php if (!empty($t['notes'])): ?><div class="panel"><h2 class="panel-title">Notes</h2><div class="prewrap"><?=h($t['notes'])?></div></div><?php endif; ?>
<details><summary>Pricing &amp; billing</summary><dl><?php foreach (['pricing' => 'Pricing', 'chargeable' => 'Chargeable', 'agreed_fee_ex_gst' => 'Original set/estimated value', 'legacy_billing_amount_ex_gst' => 'Legacy billing amount', 'billing_advised' => 'Billing advised', 'invoice_number' => 'Invoice number', 'invoice_reference' => 'Invoice reference', 'purchase_order_no' => 'Purchase order', 'job_number' => 'Job number'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h($t[$key] ?? '—')?></dd><?php endforeach; ?></dl><p class="muted small">Historical values only. No totals are recalculated here.</p></details>
<details><summary>Scheduling &amp; administration</summary><dl><?php foreach (['received_at' => 'Received', 'completed_at' => 'Completed', 'priority' => 'Priority', 'estimate_minutes' => 'Estimate (minutes)'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h($t[$key] ?? '—')?></dd><?php endforeach; ?><?php foreach (['task_category' => 'Category', 'task_type' => 'Type', 'action_for' => 'Assigned staff', 'domains' => 'Domains', 'vetta_ticket_no' => 'Vetta ticket', 'costs' => 'Additional costs', 'invoice_details' => 'Invoice details', 'notes' => 'Original notes'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h(legacyValue($t, $key) ?: '—')?></dd><?php endforeach; ?></dl></details>
<div class="section-heading"><h2>Work Log <small><?=count($entries)?> <?=count($entries) === 1 ? 'entry' : 'entries'?></small></h2><a href="add-work.php?task_id=<?=(int)$t['id']?>">＋ Log work</a></div>
<?php if (!$entries): ?><p class="emptyline">No work logged against this task yet.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($entries as $r): ?><div class="record"><span><strong><?=h($r['details'] ?: 'Work entry')?></strong><small><?=h(implode(' · ', array_filter([$r['staff_key'], niceDate($r['work_date'] ?? $r['started_at']), dur($r['duration_seconds']) . ' worked', dur($r['billable_seconds']) . ' billable', $r['source_system'] === 'fabrik' ? '' : cm_status_label($r['billability'])])))?></small></span><a class="inline-action" href="edit-work.php?id=<?=(int)$r['id']?>&amp;back=<?=h(rawurlencode(route('task', ['id' => $t['id']])))?>">Edit</a></div><?php endforeach; ?></div>
