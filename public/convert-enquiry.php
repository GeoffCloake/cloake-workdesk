<?php
declare(strict_types=1);
/*
 * Enquiry view + progress to task (M6B write path, kept).
 * Conversion logic is unchanged: row lock on the enquiry, re-check for an
 * existing linked task inside the transaction, unique (source_system,source_id)
 * on tasks as a database backstop. Re-submitting never creates a second task.
 * v0.12: planned tasks (enquiry_task_plans) — each ticked plan becomes one task
 * (source_id "<enquiry>-p<plan>", plan row locked, plan.task_id set once), and the
 * plan's price lines and milestones are assigned to it. Without plans, the enquiry
 * becomes one task as before and takes the whole-job price lines and milestones.
 * The page also shows the planned tasks and the pricing panel (the quote).
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!$id) { http_response_code(400); exit('Missing enquiry ID.'); }
if (empty($_SESSION['m6b_csrf'])) $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
$error = '';
$plansOf = fn(int $eid) => allRows($pdo, 'SELECT p.*, (SELECT SUM(b.quantity*b.unit_price_ex_gst) FROM breakdown_items b WHERE b.plan_id=p.id) total FROM enquiry_task_plans p WHERE p.enquiry_id=? ORDER BY COALESCE(p.plan_order,65535), p.id', [$eid]);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['m6b_csrf'], $_POST['csrf'])) { http_response_code(403); exit('Session expired. Reload the enquiry.'); }
        $pdo->beginTransaction();
        // The parent-row lock serialises conversions of the same enquiry.
        $q = $pdo->prepare('SELECT * FROM enquiries WHERE id=? FOR UPDATE');
        $q->execute([$id]); $enquiry = $q->fetch();
        if (!$enquiry) { throw new RuntimeException('Enquiry not found.'); }
        $ticked = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['plans'] ?? null) ? $_POST['plans'] : []))));
        $openPlans = array_filter($plansOf($id), fn($p) => !$p['task_id']);
        $created = [];
        $newTask = function (array $f) use ($pdo, $enquiry, $id): int {
            // v0.11: copies contact, category and type; received = when the enquiry was received. v0.12: + plan fields, billing.
            $q = $pdo->prepare('INSERT INTO tasks (client_id,enquiry_id,source_system,source_id,title,instructions,received_at,required_by,priority,status,notes,contact_id,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([(int)$enquiry['client_id'], $id, 'enquiry', $f['source_id'], $f['title'], $f['instructions'], $enquiry['received_at'] ?? date('Y-m-d H:i:s'), $enquiry['required_by'] ? $enquiry['required_by'] . ' 00:00:00' : null, $enquiry['priority'], 'open', $f['notes'], $enquiry['contact_id'] ?? null, $f['task_category'], $f['task_type'], $f['assigned_to'] ?? null, $f['pricing'] ?? null, $f['chargeable'] ?? null, $f['estimate_minutes'] ?? null, $f['billing_mode'] ?? null]);
            $taskId = (int)$pdo->lastInsertId();
            $q = $pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,task_id,event_kind,event_at,summary) VALUES (?,?,?,'enquiry_converted',NOW(),?)");
            $q->execute([(int)$enquiry['client_id'], $id, $taskId, 'Enquiry progressed to task' . ($f['plan'] ? ' (planned task: ' . mb_substr($f['title'], 0, 120) . ')' : '')]);
            return $taskId;
        };
        $check = function () use ($pdo, $enquiry) {
            if (empty($enquiry['client_id'])) throw new RuntimeException('Assign a client to this enquiry before conversion.');
            $q = $pdo->prepare('SELECT id FROM clients WHERE id=?');
            $q->execute([$enquiry['client_id']]);
            if (!$q->fetchColumn()) throw new RuntimeException('The enquiry client no longer exists.');
            $allowed = array_merge(CM_ENQUIRY_OPEN, ['task_created']);
            if (!in_array((string)$enquiry['status'], $allowed, true)) throw new RuntimeException('This enquiry is not eligible for conversion.');
        };
        if ($openPlans || $ticked) {
            // v0.12: one task per ticked planned task.
            if (!$ticked) throw new RuntimeException('Tick at least one planned task to create.');
            $check();
            foreach ($ticked as $planId) {
                $p = oneRow($pdo, 'SELECT * FROM enquiry_task_plans WHERE id=? AND enquiry_id=? FOR UPDATE', [$planId, $id]);
                if (!$p) throw new RuntimeException('A ticked planned task is no longer on this enquiry. Reload the page.');
                if ($p['task_id']) continue;   // already created (e.g. a second click): never twice
                $taskId = $newTask(['source_id' => $id . '-p' . $planId, 'title' => $p['title'], 'instructions' => $p['instructions'], 'notes' => null, 'task_category' => $p['task_category'] ?? $enquiry['task_category'], 'task_type' => $p['task_type'] ?? $enquiry['task_type'],
                    'assigned_to' => $p['assigned_to'], 'pricing' => $p['pricing'], 'chargeable' => $p['chargeable'], 'estimate_minutes' => $p['estimate_minutes'], 'billing_mode' => $p['billing_mode'] ?? $enquiry['billing_mode'] ?? null, 'plan' => true]);
                $pdo->prepare('UPDATE enquiry_task_plans SET task_id=?, updated_at=NOW() WHERE id=?')->execute([$taskId, $planId]);
                $pdo->prepare('UPDATE breakdown_items SET task_id=? WHERE plan_id=? AND task_id IS NULL')->execute([$taskId, $planId]);
                $pdo->prepare('UPDATE billing_milestones SET task_id=? WHERE plan_id=? AND task_id IS NULL')->execute([$taskId, $planId]);
                $created[] = $taskId;
            }
        } else {
            $q = $pdo->prepare('SELECT id FROM tasks WHERE enquiry_id=? LIMIT 1');
            $q->execute([$id]); $taskId = $q->fetchColumn();
            if (!$taskId) {
                $check();
                $taskId = $newTask(['source_id' => (string)$id, 'title' => $enquiry['title'], 'instructions' => $enquiry['details'], 'notes' => $enquiry['next_action'] ?: null, 'task_category' => $enquiry['task_category'] ?? null, 'task_type' => $enquiry['task_type'] ?? null, 'billing_mode' => $enquiry['billing_mode'] ?? null, 'plan' => false]);
                // The whole-job price lines and milestones are this task's.
                $pdo->prepare('UPDATE breakdown_items SET task_id=? WHERE enquiry_id=? AND task_id IS NULL AND plan_id IS NULL')->execute([$taskId, $id]);
                $pdo->prepare('UPDATE billing_milestones SET task_id=? WHERE enquiry_id=? AND task_id IS NULL AND plan_id IS NULL')->execute([$taskId, $id]);
                $created[] = (int)$taskId;
            }
        }
        if ($created && $enquiry['status'] !== 'task_created') {
            $q = $pdo->prepare("UPDATE enquiries SET status='task_created' WHERE id=?");
            $q->execute([$id]);
        }
        $pdo->commit();
        $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
        if (count($created) > 1) { header('Location: convert-enquiry.php?id=' . $id . '&created=' . count($created), true, 303); exit; }
        if (!$created && isset($taskId)) $created = [(int)$taskId];
        header('Location: ' . ($created ? 'index.php?page=task&id=' . (int)$created[0] : 'convert-enquiry.php?id=' . $id), true, 303); exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('M6B conversion error for enquiry ' . $id . ': ' . $e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to convert enquiry. No changes committed.';
}
try {
    $q = $pdo->prepare('SELECT e.*,c.name client_name,k.display_name contact_name,k.email contact_email,k.phone contact_phone,k.mobile contact_mobile FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id LEFT JOIN contacts k ON k.id=e.contact_id WHERE e.id=?');
    $q->execute([$id]); $enquiry = $q->fetch();
    if (!$enquiry) { http_response_code(404); exit('Enquiry not found.'); }
    $q = $pdo->prepare('SELECT id,title,status,parent_task_id FROM tasks WHERE enquiry_id=? ORDER BY id');
    $q->execute([$id]); $linkedTasks = $q->fetchAll();
    $linkedTask = $linkedTasks[0] ?? null;
    $plans = $plansOf($id);
} catch (Throwable $e) { error_log('M6B load enquiry ' . $id . ': ' . $e->getMessage()); http_response_code(500); exit('Enquiry temporarily unavailable.'); }
$owner = cm_owner($pdo, 'enquiry', $id);
$openPlans = array_values(array_filter($plans, fn($p) => !$p['task_id']));
$eligible = in_array((string)$enquiry['status'], array_merge(CM_ENQUIRY_OPEN, ['task_created']), true);
$planErr = ($_SESSION['cm_plan_error']['enquiry'] ?? 0) === $id ? (string)$_SESSION['cm_plan_error']['msg'] : ''; unset($_SESSION['cm_plan_error']);
$templates = $owner['editable'] ? cm_template_options($pdo) : [];

cm_page_start('Enquiry #' . $id, 'enquiries', ['back' => [route('enquiries'), 'Enquiries'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && !cm_undo_pending()): ?><div class="success" role="status"><strong><?=$_GET['saved'] === '1' ? 'Saved.' : 'No changes.'?></strong></div><?php endif; ?>
<?php if ($enquiry['legacy_source_id'] === null): ?><nav class="client-actions two"><a class="ca" href="edit-enquiry.php?id=<?=(int)$id?>">Edit enquiry</a><a class="ca" href="edit-enquiry.php?id=<?=(int)$id?>#withdraw">Withdraw / duplicate</a></nav><?php endif; ?>
<section class="panel"><h2 class="panel-title"><?=h($enquiry['title'])?></h2>
<dl class="facts"><dt>Client</dt><dd><?php if ($enquiry['client_id']): ?><a href="<?=h(route('client', ['id' => $enquiry['client_id']]))?>"><?=h($enquiry['client_name'])?></a><?php else: ?>Unassigned<?php endif; ?></dd>
<?php if (!empty($enquiry['contact_name'])): ?><dt>Contact</dt><dd><?=h($enquiry['contact_name'])?><?php foreach (array_filter([$enquiry['contact_mobile'] ?? null, $enquiry['contact_phone'] ?? null]) as $ph): ?> · <a href="tel:<?=h(preg_replace('/[^0-9+]/', '', $ph))?>"><?=h($ph)?></a><?php endforeach; ?><?php if (!empty($enquiry['contact_email'])): ?> · <a href="mailto:<?=h($enquiry['contact_email'])?>"><?=h($enquiry['contact_email'])?></a><?php endif; ?></dd><?php endif; ?>
<dt>Received</dt><dd><?=h(niceDate($enquiry['received_at'] ?? $enquiry['created_at']))?></dd>
<?php if (!empty($enquiry['task_category'])): ?><dt>Type</dt><dd><?=h($enquiry['task_category'] . ($enquiry['task_type'] ? ' › ' . $enquiry['task_type'] : ''))?></dd><?php endif; ?>
<dt>Status</dt><dd><?=h(CM_ENQUIRY_STATUSES[$enquiry['status']] ?? cm_status_label($enquiry['status']))?><?php if (($enquiry['status'] ?? '') === 'duplicate' && !empty($enquiry['duplicate_of'])): ?> of <a href="convert-enquiry.php?id=<?=(int)$enquiry['duplicate_of']?>">#<?=(int)$enquiry['duplicate_of']?></a><?php endif; ?><?=!empty($enquiry['closed_reason']) ? ' — ' . h($enquiry['closed_reason']) : ''?></dd><dt>Priority</dt><dd><?=h(cm_status_label($enquiry['priority']))?></dd><dt>Required by</dt><dd><?=h($enquiry['required_by'] ? niceDate($enquiry['required_by']) : 'Not set')?></dd>
<?php if ($enquiry['next_action'] || $enquiry['next_action_due']): ?><dt>Next action</dt><dd><?=h($enquiry['next_action'] ?: '—')?><?=$enquiry['next_action_due'] ? ' (by ' . h(niceDate($enquiry['next_action_due'])) . ')' : ''?></dd><?php endif; ?>
<?php if ($enquiry['waiting_for']): ?><dt>Waiting for</dt><dd><?=h($enquiry['waiting_for'])?></dd><?php endif; ?></dl>
<div class="prewrap"><?=h($enquiry['details'] ?: 'No description')?></div>
</section>
<?php if (isset($_GET['created'])): ?><div class="success" role="status"><strong><?=(int)$_GET['created']?> tasks created.</strong></div><?php endif; ?>

<?php if ($owner['editable'] || $plans): ?>
<section class="panel plans" id="plans" data-testid="plans"><h2 class="panel-title">Planned tasks <small class="muted"><?=$plans ? count($plans) : ''?></small></h2>
<?php if ($planErr): ?><div class="alert" role="alert"><?=h($planErr)?></div><?php endif; ?>
<?php if (!$plans): ?><p class="muted small">None yet. Add tasks from a template (with their price lines and milestones) to create several tasks from this enquiry — or create a single task from the enquiry itself below.</p><?php endif; ?>
<ul class="plan-list"><?php foreach ($plans as $p): ?>
<li><span class="pl-main"><strong><?=h($p['title'])?></strong><small><?=h(implode(' · ', array_filter([$p['pricing'] ? (CM_PRICING_MODES[$p['pricing']] ?? $p['pricing']) : '', $p['estimate_minutes'] ? dur((int)$p['estimate_minutes'] * 60) . ' estimate' : '', $p['total'] !== null ? cm_money($p['total']) . ' ex GST' : ''])))?></small></span>
<span class="pl-end"><?php if ($p['task_id']): ?><a href="<?=h(route('task', ['id' => $p['task_id']]))?>">Task #<?=(int)$p['task_id']?> ›</a><?php else: ?><em class="ms-state">Not created yet</em><?php if ($owner['editable']): ?><a class="mini" href="plan.php?id=<?=(int)$p['id']?>">Edit</a><?php endif; ?><?php endif; ?></span></li>
<?php endforeach; ?></ul>
<?php if ($owner['editable']): ?>
<?php if ($templates): ?><form method="post" action="plan.php" class="row-inline add-plan"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="add_template"><input type="hidden" name="enquiry_id" value="<?=(int)$id?>">
<label for="plan-template" class="visually-hidden">Template</label><select id="plan-template" name="template_id" required><option value="">Add from a template…</option><?php foreach ($templates as $tt): ?><option value="<?=(int)$tt['id']?>"><?=h($tt['title'])?><?=$tt['total'] !== null ? ' — ' . h(cm_money($tt['total'])) : ''?></option><?php endforeach; ?></select><button type="submit" class="secondary">Add</button></form><?php endif; ?>
<details class="inline-add"><summary>＋ Add a blank planned task</summary><form method="post" action="plan.php" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="add_blank"><input type="hidden" name="enquiry_id" value="<?=(int)$id?>">
<div class="field"><label for="plan-title">Task title</label><input id="plan-title" name="title" maxlength="255" required></div><div class="form-actions"><button type="submit" class="secondary">Add planned task</button></div></form></details>
<?php endif; ?>
</section>
<?php endif; ?>

<?php cm_pricing_panel($pdo, $owner); ?>

<?php if ($linkedTasks): ?>
  <div class="success"><strong>Already progressed.</strong> This enquiry has <?=count($linkedTasks) === 1 ? 'task' : count($linkedTasks) . ' tasks:'?> <?php foreach ($linkedTasks as $i => $lt): ?><?=$i ? ', ' : ''?><a href="<?=h(route('task', ['id' => $lt['id']]))?>">#<?=h($lt['id'])?> — <?=h($lt['title'])?></a><?php endforeach; ?>.<?=$openPlans ? '' : ' No new task will be created.'?></div>
  <nav class="client-actions three"><a class="ca primary" href="add-work.php?task_id=<?=(int)$linkedTask['id']?>">＋ Log work</a><a class="ca" href="<?=h(route('task', ['id' => $linkedTask['id']]))?>">Open task</a><?php if ($enquiry['client_id']): ?><a class="ca" href="<?=h(route('client', ['id' => $enquiry['client_id']]))?>">Client</a><?php endif; ?></nav>
<?php endif; ?>
<?php if (!$eligible && (!$linkedTasks || $openPlans)): ?>
  <p class="notice">This enquiry is <?=h(strtolower(CM_ENQUIRY_STATUSES[$enquiry['status']] ?? cm_status_label($enquiry['status'])))?>, so no task can be created. To reopen it, edit the enquiry and set an open status.</p>
<?php elseif (empty($enquiry['client_id']) && (!$linkedTasks || $openPlans)): ?>
  <p class="notice">Assign a client before converting.</p>
<?php elseif ($openPlans): ?>
  <div class="panel convert-box" data-testid="convert-plans"><h2 class="panel-title">Create tasks</h2><p>Each ticked planned task becomes one new <strong>open</strong> task linked to this enquiry, with the client, contact, priority and dates, and its own price lines and milestones. Nothing is invoiced.</p>
  <form method="post" action="convert-enquiry.php" data-once><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6b_csrf'])?>">
  <div class="checks stacked"><?php foreach ($openPlans as $p): ?><label class="check"><input type="checkbox" name="plans[]" value="<?=(int)$p['id']?>" checked> <?=h($p['title'])?></label><?php endforeach; ?></div>
  <button type="submit" class="save">Create ticked tasks</button></form></div>
<?php elseif (!$linkedTasks): ?>
  <div class="panel convert-box"><p>Creating a task makes one new <strong>open</strong> task linked to this enquiry. It copies the client, contact, category and type, title, instructions, priority, received and required-by dates. The enquiry's price lines and milestones go with it. The enquiry stays in the database; nothing is invoiced.</p>
  <form method="post" action="convert-enquiry.php" data-once><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6b_csrf'])?>"><button type="submit" class="save">Create linked task</button></form></div>
<?php endif; ?>
<?php cm_page_end('enquiries');
