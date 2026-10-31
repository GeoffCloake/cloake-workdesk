<?php
declare(strict_types=1);
/*
 * Add or edit a task (v0.10.0, fields aligned with the Fabrik task form).
 *  - New task:          ?client_id= optional. Created with source_system='app'.
 *  - App/enquiry tasks: every field below is editable.
 *  - Imported tasks:    workload fields only (status, dates, priority, notes, client, contact,
 *                       Action for, queue, category, type, domains). Title, instructions and all
 *                       historical billing fields stay locked.
 * Every change is audited (task_created / task_updated with {field:{previous,current}}).
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$WORKLOAD = ['status', 'completed_at', 'required_by', 'priority', 'notes', 'client_id', 'contact_id', 'assigned_to', 'queue', 'task_category', 'task_type', 'domains'];
$IMPORTED_EDITABLE = $WORKLOAD;
$APP_EDITABLE = array_merge(['title', 'instructions'], $WORKLOAD, ['pricing', 'chargeable', 'agreed_fee_ex_gst', 'estimate_minutes', 'job_number', 'purchase_order_no', 'invoice_number', 'invoice_reference', 'billing_advised']);
$error = '';
$preClient = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$t = $id ? oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$id]) : array_merge(array_fill_keys($APP_EDITABLE, null), ['client_id' => $preClient, 'status' => 'open', 'priority' => 'normal', 'legacy_record' => null, 'source_system' => 'app', 'chargeable' => 'yes']);
if ($id && !$t) { http_response_code(404); exit('Task not found.'); }
$m002 = ['contact_id', 'assigned_to', 'queue', 'task_category', 'task_type', 'domains'];
$migrated002 = $id ? array_key_exists('assigned_to', $t) : (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='assigned_to'");
if (!$migrated002) { $IMPORTED_EDITABLE = array_diff($IMPORTED_EDITABLE, $m002); $APP_EDITABLE = array_diff($APP_EDITABLE, $m002); }
$imported = $id && $t['legacy_record'] !== null;
$editable = array_values($imported ? $IMPORTED_EDITABLE : $APP_EDITABLE);
$staff = cm_staff_names($pdo);
$classes = cm_task_classes($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        m7verify();
        $raw = [];
        foreach (['title' => 255, 'instructions' => 30000, 'notes' => 30000, 'status' => 40, 'priority' => 25, 'required_by' => 10, 'completed_on' => 10, 'client_id' => 20, 'contact_id' => 20,
                  'queue' => 9, 'task_category' => 80, 'task_type' => 120, 'domains' => 255, 'assigned_other' => 100,
                  'pricing' => 20, 'chargeable' => 10, 'agreed_fee_ex_gst' => 16, 'est_hours' => 4, 'est_minutes' => 2, 'job_number' => 100, 'purchase_order_no' => 80, 'invoice_number' => 64, 'invoice_reference' => 255] as $f => $max) $raw[$f] = cm_post($f, $max);
        $pdo->beginTransaction();
        $old = null;
        if ($id) { $s = $pdo->prepare('SELECT * FROM tasks WHERE id=? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch(); cm_check_ver($old, $editable); }
        $new = [];
        $keep = fn(string $f, string $v) => $old && $v === (string)$old[$f];   // an existing non-standard value left as it was
        if (!array_key_exists($raw['status'], CM_TASK_STATUSES) && !$keep('status', $raw['status'])) throw new InvalidArgumentException('Invalid status.');
        if ($raw['priority'] !== '' && !array_key_exists($raw['priority'], CM_PRIORITIES) && !in_array($raw['priority'], CM_LEGACY_PRIORITIES, true) && !$keep('priority', $raw['priority'])) throw new InvalidArgumentException('Invalid priority.');
        $new['status'] = $raw['status'];
        $new['priority'] = cm_nullable($raw['priority']);
        $req = m7date($raw['required_by']);
        $new['required_by'] = $req ? ($old && $old['required_by'] && substr((string)$old['required_by'], 0, 10) === $req ? $old['required_by'] : $req . ' 00:00:00') : null;
        $done = m7date($raw['completed_on']);
        $closed = in_array(strtolower(trim($raw['status'])), CM_CLOSED_TASK_STATUSES, true);
        if ($done) $new['completed_at'] = ($old && $old['completed_at'] && substr((string)$old['completed_at'], 0, 10) === $done) ? $old['completed_at'] : $done . ' 00:00:00';
        elseif ($raw['status'] === 'completed') $new['completed_at'] = $old['completed_at'] ?? date('Y-m-d H:i:s');
        else $new['completed_at'] = $closed ? ($old['completed_at'] ?? null) : null;
        $new['notes'] = cm_nullable($raw['notes']);
        $new['client_id'] = cm_valid_client($pdo, $raw['client_id'], false);
        if ($migrated002) {
            // Contact: must belong to the chosen client (an imported mismatch may be kept unchanged).
            $new['contact_id'] = null;
            if ($raw['contact_id'] !== '') {
                if (!ctype_digit($raw['contact_id'])) throw new InvalidArgumentException('Invalid contact.');
                $k = oneRow($pdo, 'SELECT id,client_id FROM contacts WHERE id=?', [(int)$raw['contact_id']]);
                if (!$k) throw new InvalidArgumentException('Contact not found.');
                if ((int)$k['client_id'] !== (int)$new['client_id'] && !$keep('contact_id', $raw['contact_id'])) throw new InvalidArgumentException('That contact belongs to a different client.');
                $new['contact_id'] = (int)$k['id'];
            }
            $who = array_map(fn($x) => mb_strtolower(trim((string)$x)), is_array($_POST['assigned_to'] ?? null) ? $_POST['assigned_to'] : []);
            if ($raw['assigned_other'] !== '') $who = array_merge($who, array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $raw['assigned_other'])));
            $who = array_values(array_unique(array_filter($who, fn($x) => $x !== '' && preg_match('/^[\p{L}0-9 ._\'-]{1,40}$/u', $x))));
            $new['assigned_to'] = $who ? implode(', ', $who) : null;
            if ($raw['queue'] !== '' && !preg_match('/^-?\d{1,6}$/', $raw['queue'])) throw new InvalidArgumentException('Queue must be a whole number.');
            $new['queue'] = $raw['queue'] === '' ? null : (int)$raw['queue'];
            if ($raw['task_category'] !== '' && !isset($classes[$raw['task_category']]) && !$keep('task_category', $raw['task_category'])) throw new InvalidArgumentException('Choose a category from the list.');
            $new['task_category'] = cm_nullable($raw['task_category']);
            $new['task_type'] = cm_nullable($raw['task_type']);
            $new['domains'] = cm_nullable($raw['domains']);
        }
        if (!$imported) {
            if (mb_strlen($raw['title']) < 3) throw new InvalidArgumentException('Task title is required (3–255 characters).');
            $new['title'] = $raw['title'];
            $new['instructions'] = cm_nullable($raw['instructions']);
            if ($raw['pricing'] !== '' && !in_array($raw['pricing'], ['hourly', 'set', 'web_service'], true)) throw new InvalidArgumentException('Invalid pricing type.');
            $new['pricing'] = cm_nullable($raw['pricing']);
            if (!in_array($raw['chargeable'], ['', 'yes', 'no'], true)) throw new InvalidArgumentException('Invalid chargeable value.');
            $new['chargeable'] = cm_nullable($raw['chargeable']);
            if ($raw['agreed_fee_ex_gst'] !== '' && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw['agreed_fee_ex_gst'])) throw new InvalidArgumentException('Set/estimated value must be a number with up to 2 decimals.');
            $new['agreed_fee_ex_gst'] = cm_nullable($raw['agreed_fee_ex_gst']);
            foreach (['est_hours', 'est_minutes'] as $k) if ($raw[$k] !== '' && !ctype_digit($raw[$k])) throw new InvalidArgumentException('Time estimate must be whole hours and minutes.');
            if ((int)$raw['est_minutes'] > 59) throw new InvalidArgumentException('Estimate minutes must be 0–59.');
            $est = (int)$raw['est_hours'] * 60 + (int)$raw['est_minutes'];
            $new['estimate_minutes'] = $est === 0 ? null : $est;
            foreach (['job_number', 'purchase_order_no', 'invoice_number', 'invoice_reference'] as $k) $new[$k] = cm_nullable($raw[$k]);
            $ba = !empty($_POST['billing_advised']) ? 1 : 0;
            $new['billing_advised'] = ($ba === 0 && $old && $old['billing_advised'] === null) ? null : $ba;   // unticked + never set = still unset
        }
        // Never write a field outside this record's editable set.
        $new = array_intersect_key($new, array_flip($editable));
        if ($id) {
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE tasks SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                cm_audit($pdo, 'task_updated', ['client_id' => $new['client_id'] ?? $old['client_id'], 'enquiry_id' => $old['enquiry_id'], 'task_id' => $id], 'Updated task fields: ' . implode(', ', array_keys($changes)), $changes);
            }
            $pdo->commit();
            header('Location: ' . route('task', ['id' => $id, 'saved' => $changes ? 1 : 0]), true, 303); exit;
        }
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO tasks (source_system,source_id,received_at,' . implode(',', array_map(fn($f) => "`$f`", $cols)) . ') VALUES (\'app\',?,NOW(),' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute([cm_new_source_id(), ...array_values($new)]);
        $newId = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'task_created', ['client_id' => $new['client_id'], 'task_id' => $newId], 'Task created: ' . $new['title'], array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        header('Location: ' . route('task', ['id' => $newId, 'saved' => 1]), true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'task save #' . $id);
        foreach (['title', 'instructions', 'notes', 'status', 'priority', 'client_id', 'contact_id', 'queue', 'task_category', 'task_type', 'domains', 'pricing', 'chargeable', 'agreed_fee_ex_gst', 'job_number', 'purchase_order_no', 'invoice_number', 'invoice_reference'] as $f) if (is_string($_POST[$f] ?? null)) $t[$f] = trim($_POST[$f]);
        if (is_string($_POST['required_by'] ?? null)) $t['required_by'] = $_POST['required_by'];
        if (is_string($_POST['completed_on'] ?? null)) $t['completed_at'] = $_POST['completed_on'];
        if (isset($_POST['assigned_to']) && is_array($_POST['assigned_to'])) $t['assigned_to'] = implode(', ', array_map('strval', $_POST['assigned_to']));
        if (!$imported) $t['billing_advised'] = !empty($_POST['billing_advised']) ? 1 : 0;
        if (isset($_POST['est_hours']) || isset($_POST['est_minutes'])) $t['estimate_minutes'] = (int)($_POST['est_hours'] ?? 0) * 60 + (int)($_POST['est_minutes'] ?? 0);
    }
}
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$id]), $editable) : '';
$clients = cm_client_options($pdo);
$clientName = $t['client_id'] ? (string)(oneRow($pdo, 'SELECT name FROM clients WHERE id=?', [(int)$t['client_id']])['name'] ?? '') : '';
$contacts = ($migrated002 && $t['client_id']) ? allRows($pdo, "SELECT id,display_name,status FROM contacts WHERE client_id=? ORDER BY (COALESCE(status,'')='archive'), display_name", [(int)$t['client_id']]) : [];
if (!empty($t['contact_id']) && !in_array((int)$t['contact_id'], array_map('intval', array_column($contacts, 'id')), true)) {
    $ck = oneRow($pdo, 'SELECT id,display_name,status FROM contacts WHERE id=?', [(int)$t['contact_id']]); if ($ck) { $ck['display_name'] .= ' (another client)'; $contacts[] = $ck; }
}
$assigned = array_values(array_filter(array_map('trim', explode(',', mb_strtolower((string)($t['assigned_to'] ?? '')))), 'strlen'));
$staffShown = array_values(array_unique(array_merge(array_slice($staff, 0, 8), $assigned)));
$opt = function (array $choices, ?string $cur) { foreach ($choices as $k => $l) echo '<option value="' . h($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>'; if ($cur !== null && $cur !== '' && !array_key_exists($cur, $choices)) echo '<option value="' . h($cur) . '" selected>' . h(cm_status_label($cur)) . ' (current)</option>'; };
$estSet = ($t['estimate_minutes'] ?? null) !== null && $t['estimate_minutes'] !== '' && (int)$t['estimate_minutes'] > 0;
$estH = $estSet ? intdiv((int)$t['estimate_minutes'], 60) : '';
$estM = $estSet ? (int)$t['estimate_minutes'] % 60 : '';
$field = fn(string $f, string $label, int $max, string $extra = '') => '<div class="field"><label for="' . h($f) . '">' . h($label) . '</label><input id="' . h($f) . '" name="' . h($f) . '" maxlength="' . $max . '" value="' . h($t[$f] ?? '') . '"' . $extra . '></div>';
$title = $id ? ($imported ? 'Update imported task' : 'Edit task') : 'New task';
cm_page_start($title, 'tasks', ['back' => $id ? [route('task', ['id' => $id]), 'Task details'] : ($preClient ? [route('client', ['id' => $preClient]), $clientName ?: 'Client'] : [route('tasks'), 'Tasks']), 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($imported): ?>
<div class="panel locked"><h2 class="panel-title"><?=h($t['title'])?></h2><p class="small muted">Imported task (original #<?=h($t['source_id'])?>). Title, instructions and historical billing (pricing, chargeable, estimate, value, amounts, invoice and order numbers) are kept as imported and can't be changed here.</p></div>
<?php endif; ?>
<form method="post" class="form" data-task-form><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>">
<?php if (!$imported): ?>
<div class="field"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=h($t['title'] ?? '')?>"></div>
<div class="field"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=h($t['instructions'] ?? '')?></textarea></div>
<?php endif; ?>
<fieldset class="field group"><legend>Client details</legend>
<div class="field"><label for="client">Client <span class="opt">(optional)</span></label><?php cm_client_picker($clients, (string)($t['client_id'] ?? ''), false, 'No client'); ?></div>
<?php if ($migrated002): ?>
<div class="field"><label for="contact_id">Contact</label><select id="contact_id" name="contact_id" data-contact-select><option value="">—</option><?php foreach ($contacts as $k): ?><option value="<?=(int)$k['id']?>"<?=(string)$k['id'] === (string)($t['contact_id'] ?? '') ? ' selected' : ''?>><?=h($k['display_name'] . (($k['status'] ?? '') === 'archive' ? ' (archived)' : ''))?></option><?php endforeach; ?></select><p class="helper" data-contact-help><?=$t['client_id'] ? '' : 'Choose a client to list its contacts.'?></p></div>
<div class="pair"><div class="field"><label for="task_category">Task category</label><select id="task_category" name="task_category" data-category-select><option value="">—</option><?php foreach (array_keys($classes) as $c): ?><option value="<?=h($c)?>"<?=$c === ($t['task_category'] ?? '') ? ' selected' : ''?>><?=h($c)?></option><?php endforeach; ?><?php if (($t['task_category'] ?? '') !== '' && !isset($classes[$t['task_category']])): ?><option value="<?=h($t['task_category'])?>" selected><?=h($t['task_category'])?> (current)</option><?php endif; ?></select></div>
<div class="field"><label for="task_type">Task type</label><select id="task_type" name="task_type" data-type-select data-types="<?=h(json_encode($classes, JSON_UNESCAPED_UNICODE))?>"><option value="">—</option><?php $types = $classes[$t['task_category'] ?? ''] ?? []; if (($t['task_type'] ?? '') !== '' && !in_array($t['task_type'], $types, true)) $types[] = $t['task_type']; foreach ($types as $ty): ?><option value="<?=h($ty)?>"<?=$ty === ($t['task_type'] ?? '') ? ' selected' : ''?>><?=h($ty)?></option><?php endforeach; ?></select></div></div>
<?=$field('domains', 'Domain(s)', 255, ' placeholder="example.co.nz"')?>
<?php endif; ?>
</fieldset>
<fieldset class="field group"><legend>Administration</legend>
<?php if ($migrated002): ?>
<fieldset class="field"><legend class="sub">Action for</legend><div class="checks"><?php foreach ($staffShown as $sname): ?><label class="check"><input type="checkbox" name="assigned_to[]" value="<?=h($sname)?>"<?=in_array($sname, $assigned, true) ? ' checked' : ''?>> <?=h(ucfirst($sname))?></label><?php endforeach; ?></div>
<label for="assigned_other" class="small">Someone else (comma-separated)</label><input id="assigned_other" name="assigned_other" maxlength="100"></fieldset>
<?php endif; ?>
<div class="pair"><div class="field"><label for="status">Status</label><select id="status" name="status"><?php $opt(CM_TASK_STATUSES, $t['status']); ?></select></div>
<div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php if (($t['priority'] ?? '') === '' || $t['priority'] === null): ?><option value="" selected>—</option><?php endif; $opt(CM_PRIORITIES, $t['priority']); ?></select></div></div>
<div class="pair"><div class="field"><label for="required">Required</label><input id="required" name="required_by" type="date" value="<?=h($t['required_by'] ? substr((string)$t['required_by'], 0, 10) : '')?>"></div>
<div class="field"><label for="completed_on">Completed</label><input id="completed_on" name="completed_on" type="date" value="<?=h($t['completed_at'] ? substr((string)$t['completed_at'], 0, 10) : '')?>"></div></div>
<p class="helper">Choosing “Completed” fills today’s date if none is set. Re-opening a task clears it.</p>
<?php if ($migrated002): ?><div class="field narrow"><label for="queue">Queue</label><input id="queue" name="queue" inputmode="numeric" maxlength="7" value="<?=h($t['queue'] ?? '')?>"><p class="helper">Lower numbers come first when Tasks are sorted by queue.</p></div><?php endif; ?>
<?php if (!$imported): ?>
<div class="pair"><?=$field('job_number', 'Job number', 100)?><?=$field('purchase_order_no', 'Order no', 80)?></div>
<?php endif; ?>
<details<?=($t['notes'] ?? '') !== '' ? ' open' : ''?>><summary>Notes</summary><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?=h($t['notes'] ?? '')?></textarea></div></details>
</fieldset>
<?php if (!$imported): ?>
<details<?=($t['pricing'] ?? '') . ($t['agreed_fee_ex_gst'] ?? '') . ($estSet ? '1' : '') . ($t['invoice_number'] ?? '') . ($t['invoice_reference'] ?? '') . (!empty($t['billing_advised']) ? '1' : '') !== '' ? ' open' : ''?>><summary>Pricing &amp; billing</summary>
<div class="pair"><div class="field"><label for="chargeable">Chargeable</label><select id="chargeable" name="chargeable"><?php foreach (['' => '—', 'yes' => 'Yes', 'no' => 'No'] as $k => $l): ?><option value="<?=h($k)?>"<?=(string)($t['chargeable'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="pricing">Pricing</label><select id="pricing" name="pricing"><option value="">—</option><?php foreach (['hourly' => 'Hourly', 'set' => 'Set fee', 'web_service' => 'Web service'] as $k => $l): ?><option value="<?=h($k)?>"<?=($t['pricing'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div></div>
<div class="field"><label for="agreed_fee_ex_gst">Set/estimated value ($ ex GST)</label><input id="agreed_fee_ex_gst" name="agreed_fee_ex_gst" inputmode="decimal" maxlength="16" placeholder="0.00" value="<?=h($t['agreed_fee_ex_gst'] ?? '')?>"></div>
<fieldset class="field"><legend class="sub">Time estimate</legend><div class="double"><div><label for="est_hours">Hours</label><input id="est_hours" name="est_hours" inputmode="numeric" maxlength="4" value="<?=h($estH)?>"></div><div><label for="est_minutes">Minutes</label><input id="est_minutes" name="est_minutes" inputmode="numeric" maxlength="2" value="<?=h($estM)?>"></div></div></fieldset>
<div class="pair"><?=$field('invoice_number', 'Invoice no', 64)?><?=$field('invoice_reference', 'Invoice reference', 255)?></div>
<div class="field"><label class="check"><input type="checkbox" name="billing_advised" value="1"<?=!empty($t['billing_advised']) ? ' checked' : ''?>> Billing advised</label></div>
<p class="helper">Recorded for billing review only. Nothing is invoiced or sent to Xero.</p></details>
<?php endif; ?>
<button type="submit" class="save"><?=$id ? 'Save task' : 'Create task'?></button></form>
<?php cm_page_end('tasks');
