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
$parent = !empty($t['parent_task_id']) ? oneRow($pdo, 'SELECT id,title,status FROM tasks WHERE id=?', [(int)$t['parent_task_id']]) : null;
$stages = allRows($pdo, 'SELECT id,title,status,completed_at,signed_off_at,stage_no,required_by FROM tasks WHERE parent_task_id=? ORDER BY COALESCE(stage_no,65535), id', [$id]);
$openStages = array_filter($stages, fn($x) => !in_array(strtolower(trim((string)$x['status'])), CM_CLOSED_TASK_STATUSES, true) && !$x['completed_at']);
$madeTemplate = oneRow($pdo, 'SELECT id FROM task_templates WHERE from_task_id=?', [$id]);
$entries = allRows($pdo, 'SELECT id,details,staff_key,work_date,started_at,duration_seconds,billable_seconds,billability,source_system FROM work_log WHERE task_id=? ORDER BY COALESCE(work_date,DATE(started_at)) DESC, id DESC LIMIT 100', [$id]);
?>
<header class="client-head"><h1><?=h($t['title'])?></h1>
<?php if ($parent): ?><p class="stage-of" data-testid="stage-of">Stage <?=(int)$t['stage_no']?> of <a href="<?=h(route('task', ['id' => $parent['id']]))?>">#<?=(int)$parent['id']?> <?=h($parent['title'])?></a></p><?php endif; ?>
<p class="client-sub"><?php if ($t['client_id']): ?><a href="<?=h(route('client', ['id' => $t['client_id']]))?>"><?=h($t['client_name'])?></a><?php else: ?>Unassigned<?php endif; ?> · <?=($t['source_system'] ?? '') === 'fabrik' ? 'Original task #' . h($t['source_id']) : 'Task #' . (int)$t['id']?></p></header>
<?php if (($_GET['saved'] ?? null) === '1' && !cm_undo_pending()): ?><div class="success" role="status">Saved.</div><?php elseif (($_GET['saved'] ?? null) === '0'): ?><div class="success" role="status">No changes to save.</div><?php endif; ?>
<nav class="client-actions three" aria-label="Task actions">
  <a class="ca primary" href="add-work.php?task_id=<?=(int)$t['id']?>"><span aria-hidden="true">＋</span> Log work</a>
  <a class="ca" href="edit-task.php?id=<?=(int)$t['id']?>"><?=$t['legacy_record'] === null ? 'Edit task' : 'Update status'?></a>
  <?php if (!empty($t['enquiry_id'])): ?><a class="ca" href="convert-enquiry.php?id=<?=(int)$t['enquiry_id']?>">Enquiry #<?=(int)$t['enquiry_id']?></a><?php elseif ($t['client_id']): ?><a class="ca" href="<?=h(route('client', ['id' => $t['client_id']]))?>">Client</a><?php endif; ?>
</nav>
<?php
$closed = in_array(strtolower(trim((string)$t['status'])), CM_CLOSED_TASK_STATUSES, true);
$soErr = ($_SESSION['cm_signoff_error']['task'] ?? 0) === (int)$t['id'] ? (string)$_SESSION['cm_signoff_error']['msg'] : ''; unset($_SESSION['cm_signoff_error']);
$hasSignoff = array_key_exists('signed_off_at', $t);
?>
<?php if ($soErr): ?><div class="alert" role="alert"><?=h($soErr)?></div><?php endif; ?>
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
<?php if ($hasSignoff && $closed): ?>
<section class="panel signoff signoff-done" data-testid="signoff"><h2 class="panel-title"><?=$t['signed_off_at'] ? 'Signed off' : h(cm_status_label($t['status']))?></h2>
<?php if ($t['signed_off_at']): ?><p><?=h($t['signed_off_by'] ?: '—')?> · <?=h(niceDate($t['signed_off_at']))?><?=$t['sign_off_note'] ? ' — ' . h($t['sign_off_note']) : ''?></p><?php elseif ($t['completed_at']): ?><p class="muted small">Completed <?=h(niceDate($t['completed_at']))?>.</p><?php endif; ?>
<details><summary>Reopen task</summary><form method="post" action="task-signoff.php" class="form"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="reopen">
<div class="field"><label for="reopen_reason">Reason</label><input id="reopen_reason" name="reopen_reason" maxlength="500" required placeholder="Why is it being reopened?"></div>
<div class="form-actions"><button type="submit" class="secondary">Reopen</button></div></form></details></section>
<?php elseif ($hasSignoff): ?>
<details class="signoff" data-testid="signoff"><summary>Sign off &amp; close</summary><?php if ($openStages): ?><p class="notice small">Sign off its <?=count($openStages)?> open stage<?=count($openStages) === 1 ? '' : 's'?> first (or cancel <?=count($openStages) === 1 ? 'it' : 'them'?>).</p><?php endif; ?><form method="post" action="task-signoff.php" class="form"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="signoff">
<div class="pair"><div class="field"><label for="signed_off_by">Signed off by</label><input id="signed_off_by" name="signed_off_by" maxlength="100" required list="signoff-staff" value="<?=h((string)($_SESSION['cm_last_staff'] ?? ''))?>"><datalist id="signoff-staff"><?php foreach (cm_staff_names($pdo) as $sn): ?><option value="<?=h(ucfirst($sn))?>"><?php endforeach; ?></datalist></div>
<div class="field"><label for="sign_off_note">Note (optional)</label><input id="sign_off_note" name="sign_off_note" maxlength="500" placeholder="e.g. approved by client"></div></div>
<p class="helper">Marks the task completed<?=$t['completed_at'] ? '' : ' today'?>. It can be reopened later.</p>
<div class="form-actions"><button type="submit" class="save">Sign off &amp; close</button></div></form></details>
<?php endif; ?>
<div class="panel"><h2 class="panel-title">Instructions</h2><div class="prewrap"><?=h($t['instructions'] ?: 'No instructions recorded')?></div></div>
<?php if (!empty($t['notes'])): ?><div class="panel"><h2 class="panel-title">Notes</h2><div class="prewrap"><?=h($t['notes'])?></div></div><?php endif; ?>
<?php cm_pricing_panel($pdo, cm_owner($pdo, 'task', $id)); ?>
<?php cm_materials_panel($pdo, $t); ?>
<?php if (!$parent): ?>
<section class="panel stages" id="stages" data-testid="stages"><h2 class="panel-title">Stages <?php if ($stages): ?><small class="muted"><?=count($stages) - count($openStages)?> of <?=count($stages)?> done</small><?php endif; ?></h2>
<?php if ($stages): ?><ul class="plan-list"><?php foreach ($stages as $sg): $sgDone = !in_array($sg, $openStages, true); ?><li class="<?=$sgDone ? 'is-done' : ''?>"><span class="pl-main"><a href="<?=h(route('task', ['id' => $sg['id']]))?>"><strong>Stage <?=(int)$sg['stage_no']?>: <?=h($sg['title'])?></strong></a><small>#<?=(int)$sg['id']?> · <?=h(cm_status_label($sg['status']))?><?=$sg['required_by'] ? ' · required ' . h(niceDate($sg['required_by'])) : ''?></small></span><span class="pl-end"><?=$sg['signed_off_at'] ? '<em class="ms-state">Signed off</em>' : ''?></span></li><?php endforeach; ?></ul>
<?php else: ?><p class="muted small">Break this task out into stages: each stage is its own task (work log, sign-off, price lines and milestones), and this task can be signed off once they're all done.</p><?php endif; ?>
<details class="inline-add"><summary><?=$stages ? '＋ Extend — add a stage' : 'Break out into stages'?></summary>
<form method="post" action="task-stages.php" class="form"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<?php $next = $stages ? max(array_map(fn($x) => (int)$x['stage_no'], $stages)) + 1 : 1; for ($k = 0; $k < ($stages ? 1 : 3); $k++): ?><div class="field"><label for="stage<?=$k?>">Stage <?=$next + $k?></label><input id="stage<?=$k?>" name="stages[]" maxlength="255"<?=$k === 0 ? ' required' : ''?> placeholder="<?=$k === 0 ? 'e.g. Design' : 'optional'?>"></div><?php endfor; ?>
<p class="helper">Each stage copies the client, contact, enquiry, category, Action for and required date.</p>
<div class="form-actions"><button type="submit" class="secondary"><?=$stages ? 'Add stage' : 'Create stages'?></button></div></form></details>
</section>
<?php endif; ?>
<details><summary>Recorded pricing fields</summary><dl><?php foreach (['pricing' => 'Pricing', 'chargeable' => 'Chargeable', 'agreed_fee_ex_gst' => 'Original set/estimated value', 'legacy_billing_amount_ex_gst' => 'Legacy billing amount', 'billing_advised' => 'Billing advised', 'invoice_number' => 'Invoice number', 'invoice_reference' => 'Invoice reference', 'purchase_order_no' => 'Purchase order', 'job_number' => 'Job number'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h($t[$key] ?? '—')?></dd><?php endforeach; ?></dl><p class="muted small">The task's own pricing fields (as imported, or as set on Edit task). The price lines above are separate; no totals are recalculated here.</p></details>
<details><summary>Scheduling &amp; administration</summary><dl><?php foreach (['received_at' => 'Received', 'completed_at' => 'Completed'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h(niceDate($t[$key] ?? null))?></dd><?php endforeach; ?>
<dt>Time estimate</dt><dd><?=$t['estimate_minutes'] ? h(dur((int)$t['estimate_minutes'] * 60)) : '—'?></dd>
<?php if ($t['legacy_record']): foreach (['vetta_ticket_no' => 'Vetta ticket', 'costs' => 'Additional costs', 'invoice_details' => 'Invoice details', 'time_taken' => 'Time taken (as imported)', 'time_chargeable' => 'Time chargeable (as imported)'] as $key => $label): ?><dt><?=h($label)?></dt><dd><?=h(legacyValue($t, $key) ?: '—')?></dd><?php endforeach; endif; ?></dl></details>
<div class="section-heading"><h2>Time sheets <small><?=(int)$tot['n']?> <?=(int)$tot['n'] === 1 ? 'entry' : 'entries'?></small></h2><a href="add-work.php?task_id=<?=(int)$t['id']?>">＋ Log work</a></div>
<?php if ((int)$tot['n']): ?><div class="totals" data-testid="task-totals"><span><strong><?=h(dur($tot['worked']))?></strong> worked</span><span><strong><?=h(dur($tot['billable']))?></strong> billable</span><?php if ((int)$tot['zero_imported']): ?><small><?=(int)$tot['zero_imported']?> imported <?=(int)$tot['zero_imported'] === 1 ? 'entry has' : 'entries have'?> no recorded time, so totals may be low.</small><?php endif; ?></div><?php endif; ?>
<?php if (!$entries): ?><p class="emptyline">No work logged against this task yet.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($entries as $r): ?><div class="record"><span><strong><?=h($r['details'] ?: 'Work entry')?></strong><small><?=h(implode(' · ', array_filter([$r['staff_key'], niceDate($r['work_date'] ?? $r['started_at']), dur($r['duration_seconds']) . ' worked', dur($r['billable_seconds']) . ' billable', $r['source_system'] === 'fabrik' ? '' : cm_status_label($r['billability'])])))?></small></span><a class="inline-action" href="edit-work.php?id=<?=(int)$r['id']?>&amp;back=<?=h(rawurlencode(route('task', ['id' => $t['id']])))?>">Edit</a></div><?php endforeach; ?></div>
<section class="panel template-from" data-testid="make-template"><?php if ($madeTemplate): ?><p class="small">A <a href="edit-template.php?id=<?=(int)$madeTemplate['id']?>">task template</a> was made from this task.</p><?php else: ?><form method="post" action="edit-template.php" class="row-inline"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="from_task"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><span class="small muted">Use this task again for other jobs</span><button type="submit" class="secondary">Make a template from this task</button></form><?php endif; ?></section>
