<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Task detail (Fabrik task form groups: client details, instructions, administration, time sheets).
   Imported tasks: workload fields editable; title, instructions and billing locked. */
$pageOpt = ['back' => [route('tasks'), 'Tasks'], 'h1' => false];
$t = oneRow($pdo, 'SELECT t.*,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [$id]);
if (!$t) { http_response_code(404); echo '<p class="emptyline">Task not found. <a href="' . h(route('tasks')) . '">Back to tasks</a></p>'; return; }
$pageTitle = $t['title'];
$contact = !empty($t['contact_id']) ? oneRow($pdo, 'SELECT id,display_name,email,phone,mobile,client_id FROM contacts WHERE id=?', [(int)$t['contact_id']]) : null;
$tot = oneRow($pdo, "SELECT COUNT(*) n, COALESCE(SUM(duration_seconds),0) worked, COALESCE(SUM(billable_seconds),0) billable, SUM(source_system='fabrik' AND duration_seconds=0) zero_imported FROM work_log WHERE task_id=?", [$id]);
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
  <div><small>Required</small><strong><?=h(niceDate($t['required_by']))?></strong></div>
  <div><small>Priority</small><strong><?=h(cm_status_label($t['priority']))?></strong></div>
</div>
<dl class="facts task-facts">
  <?php if (!empty($t['assigned_to'])): ?><dt>Action for</dt><dd><?=h(ucwords((string)$t['assigned_to']))?></dd><?php endif; ?>
  <?php if ($contact): $cp = trim((string)($contact['mobile'] ?: $contact['phone'])); ?><dt>Contact</dt><dd><?=h($contact['display_name'])?><?php if (telHref($cp)): ?> · <a href="<?=h(telHref($cp))?>"><?=h($cp)?></a><?php endif; ?><?php if (validEmail((string)$contact['email'])): ?> · <a href="mailto:<?=h($contact['email'])?>"><?=h($contact['email'])?></a><?php endif; ?></dd><?php endif; ?>
  <?php if (!empty($t['task_category']) || !empty($t['task_type'])): ?><dt>Category</dt><dd><?=h(implode(' › ', array_filter([(string)$t['task_category'], (string)$t['task_type']])))?></dd><?php endif; ?>
  <?php if (!empty($t['domains'])): ?><dt>Domain(s)</dt><dd><?=h($t['domains'])?></dd><?php endif; ?>
  <?php if (isset($t['queue']) && $t['queue'] !== null && (int)$t['queue'] !== 0): ?><dt>Queue</dt><dd><?=(int)$t['queue']?></dd><?php endif; ?>
</dl>
<div class="panel"><h2 class="panel-title">Instructions</h2><div class="prewrap"><?=h($t['instructions'] ?: 'No instructions recorded')?></div></div>
<?php if (!empty($t['notes'])): ?><div class="panel"><h2 class="panel-title">Notes</h2><div class="prewrap"><?=h($t['notes'])?></div></div><?php endif; ?>
<details><summary>Pricing &amp; billing</summary><dl><?php foreach (['pricing' => 'Pricing', 'chargeable' => 'Chargeable', 'agreed_fee_ex_gst' => 'Original set/estimated value', 'legacy_billing_amount_ex_gst' => 'Legacy billing amount', 'billing_advised' => 'Billing advised', 'invoice_number' => 'Invoice number', 'invoice_reference' => 'Invoice reference', 'purchase_order_no' => 'Purchase order', 'job_number' => 'Job number'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h($t[$key] ?? '—')?></dd><?php endforeach; ?></dl><p class="muted small">Historical values only. No totals are recalculated here.</p></details>
<details><summary>Scheduling &amp; administration</summary><dl><?php foreach (['received_at' => 'Received', 'completed_at' => 'Completed'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h(niceDate($t[$key] ?? null))?></dd><?php endforeach; ?>
<dt>Time estimate</dt><dd><?=$t['estimate_minutes'] ? h(dur((int)$t['estimate_minutes'] * 60)) : '—'?></dd>
<?php if ($t['legacy_record']): foreach (['vetta_ticket_no' => 'Vetta ticket', 'costs' => 'Additional costs', 'invoice_details' => 'Invoice details', 'time_taken' => 'Time taken (as imported)', 'time_chargeable' => 'Time chargeable (as imported)'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h(legacyValue($t, $key) ?: '—')?></dd><?php endforeach; endif; ?></dl></details>
<div class="section-heading"><h2>Time sheets <small><?=(int)$tot['n']?> <?=(int)$tot['n'] === 1 ? 'entry' : 'entries'?></small></h2><a href="add-work.php?task_id=<?=(int)$t['id']?>">＋ Log work</a></div>
<?php if ((int)$tot['n']): ?><div class="totals" data-testid="task-totals"><span><strong><?=h(dur($tot['worked']))?></strong> worked</span><span><strong><?=h(dur($tot['billable']))?></strong> billable</span><?php if ((int)$tot['zero_imported']): ?><small><?=(int)$tot['zero_imported']?> imported <?=(int)$tot['zero_imported'] === 1 ? 'entry has' : 'entries have'?> no recorded time, so totals may be low.</small><?php endif; ?></div><?php endif; ?>
<?php if (!$entries): ?><p class="emptyline">No work logged against this task yet.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($entries as $r): ?><div class="record"><span><strong><?=h($r['details'] ?: 'Work entry')?></strong><small><?=h(implode(' · ', array_filter([$r['staff_key'], niceDate($r['work_date'] ?? $r['started_at']), dur($r['duration_seconds']) . ' worked', dur($r['billable_seconds']) . ' billable', $r['source_system'] === 'fabrik' ? '' : cm_status_label($r['billability'])])))?></small></span><a class="inline-action" href="edit-work.php?id=<?=(int)$r['id']?>&amp;back=<?=h(rawurlencode(route('task', ['id' => $t['id']])))?>">Edit</a></div><?php endforeach; ?></div>
