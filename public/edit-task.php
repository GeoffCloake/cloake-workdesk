<?php
declare(strict_types=1);
/*
 * Add or edit a task (v0.10.0).
 *  - New task:            ?client_id= optional. Created with source_system='app'.
 *  - App/enquiry tasks:   all working fields, pricing and client are editable.
 *  - Imported tasks:      status, completed date, due date, priority, notes and client only.
 *                         Title, instructions and every historical billing field stay locked.
 * Every change is audited (task_created / task_updated with {field:{previous,current}}).
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/edit.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$IMPORTED_EDITABLE = ['status', 'completed_at', 'required_by', 'priority', 'notes', 'client_id'];
$APP_EDITABLE = ['title', 'instructions', 'status', 'completed_at', 'required_by', 'priority', 'notes', 'client_id', 'pricing', 'agreed_fee_ex_gst', 'estimate_minutes'];
$error = '';
$preClient = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$t = $id ? oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$id]) : array_merge(array_fill_keys($APP_EDITABLE, null), ['client_id' => $preClient, 'status' => 'open', 'priority' => 'normal', 'legacy_record' => null, 'source_system' => 'app']);
if ($id && !$t) { http_response_code(404); exit('Task not found.'); }
$imported = $id && $t['legacy_record'] !== null;
$editable = $imported ? $IMPORTED_EDITABLE : $APP_EDITABLE;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        m7verify();
        $raw = [];
        foreach (['title' => 255, 'instructions' => 30000, 'notes' => 30000, 'status' => 40, 'priority' => 25, 'required_by' => 10, 'completed_on' => 10, 'client_id' => 20, 'pricing' => 20, 'agreed_fee_ex_gst' => 16, 'estimate_minutes' => 7] as $f => $max) $raw[$f] = cm_post($f, $max);
        $pdo->beginTransaction();
        $old = null;
        if ($id) { $s = $pdo->prepare('SELECT * FROM tasks WHERE id=? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch(); cm_check_ver($old, $editable); }
        $new = [];
        // Status: standard values, or the record's existing (imported) value left as it was.
        $statusOk = array_key_exists($raw['status'], CM_TASK_STATUSES) || ($old && $raw['status'] === (string)$old['status']);
        if (!$statusOk) throw new InvalidArgumentException('Invalid status.');
        $prioOk = array_key_exists($raw['priority'], CM_PRIORITIES) || ($old && $raw['priority'] === (string)$old['priority']) || $raw['priority'] === '';
        if (!$prioOk) throw new InvalidArgumentException('Invalid priority.');
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
        if (!$imported) {
            if (mb_strlen($raw['title']) < 3) throw new InvalidArgumentException('Task title is required (3–255 characters).');
            $new['title'] = $raw['title'];
            $new['instructions'] = cm_nullable($raw['instructions']);
            if ($raw['pricing'] !== '' && !in_array($raw['pricing'], ['hourly', 'set', 'web_service'], true)) throw new InvalidArgumentException('Invalid pricing type.');
            $new['pricing'] = cm_nullable($raw['pricing']);
            if ($raw['agreed_fee_ex_gst'] !== '' && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw['agreed_fee_ex_gst'])) throw new InvalidArgumentException('Agreed fee must be a number with up to 2 decimals.');
            $new['agreed_fee_ex_gst'] = cm_nullable($raw['agreed_fee_ex_gst']);
            if ($raw['estimate_minutes'] !== '' && (!ctype_digit($raw['estimate_minutes']) || (int)$raw['estimate_minutes'] > 999999)) throw new InvalidArgumentException('Estimate must be whole minutes.');
            $new['estimate_minutes'] = cm_nullable($raw['estimate_minutes']);
        }
        // Belt and braces: never write a field outside this record's editable set.
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
        foreach (['title', 'instructions', 'notes', 'status', 'priority', 'client_id', 'pricing', 'agreed_fee_ex_gst', 'estimate_minutes'] as $f) if (is_string($_POST[$f] ?? null)) $t[$f] = trim($_POST[$f]);
        if (is_string($_POST['required_by'] ?? null)) $t['required_by'] = $_POST['required_by'];
        if (is_string($_POST['completed_on'] ?? null)) $t['completed_at'] = $_POST['completed_on'];
    }
}
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$id]), $editable) : '';
$clients = cm_client_options($pdo);
$clientName = $t['client_id'] ? (string)(oneRow($pdo, 'SELECT name FROM clients WHERE id=?', [(int)$t['client_id']])['name'] ?? '') : '';
$opt = function (array $choices, ?string $cur) { foreach ($choices as $k => $l) echo '<option value="' . h($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>'; if ($cur !== null && $cur !== '' && !array_key_exists($cur, $choices)) echo '<option value="' . h($cur) . '" selected>' . h(cm_status_label($cur)) . ' (as imported)</option>'; };
$title = $id ? ($imported ? 'Update imported task' : 'Edit task') : 'New task';
cm_page_start($title, 'tasks', ['back' => $id ? [route('task', ['id' => $id]), 'Task details'] : ($preClient ? [route('client', ['id' => $preClient]), $clientName ?: 'Client'] : [route('tasks'), 'Tasks']), 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($imported): ?>
<div class="panel locked"><h2 class="panel-title"><?=h($t['title'])?></h2><p class="small muted">Imported task (original #<?=h($t['source_id'])?>). Title, instructions and historical billing (pricing, amounts, invoice references) are kept as imported and can't be changed here.</p></div>
<?php endif; ?>
<form method="post" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>">
<?php if (!$imported): ?>
<div class="field"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=h($t['title'] ?? '')?>"></div>
<div class="field"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=h($t['instructions'] ?? '')?></textarea></div>
<?php endif; ?>
<div class="field"><label for="client">Client <span class="opt">(optional)</span></label><?php cm_client_picker($clients, (string)($t['client_id'] ?? ''), false, 'No client'); ?></div>
<div class="pair"><div class="field"><label for="status">Status</label><select id="status" name="status"><?php $opt(CM_TASK_STATUSES, $t['status']); ?></select></div>
<div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php if (($t['priority'] ?? '') === '' || $t['priority'] === null): ?><option value="" selected>—</option><?php endif; $opt(CM_PRIORITIES, $t['priority']); ?></select></div></div>
<div class="pair"><div class="field"><label for="required">Required by</label><input id="required" name="required_by" type="date" value="<?=h($t['required_by'] ? substr((string)$t['required_by'], 0, 10) : '')?>"></div>
<div class="field"><label for="completed_on">Completed on</label><input id="completed_on" name="completed_on" type="date" value="<?=h($t['completed_at'] ? substr((string)$t['completed_at'], 0, 10) : '')?>"></div></div>
<p class="helper">Choosing “Completed” fills today’s date if none is set. Re-opening a task clears it.</p>
<?php if (!$imported): ?>
<details<?=($t['pricing'] ?? '') . ($t['agreed_fee_ex_gst'] ?? '') . ($t['estimate_minutes'] ?? '') !== '' ? ' open' : ''?>><summary>Pricing</summary>
<div class="pair"><div class="field"><label for="pricing">Pricing type</label><select id="pricing" name="pricing"><option value="">—</option><?php foreach (['hourly' => 'Hourly', 'set' => 'Set fee', 'web_service' => 'Web service'] as $k => $l): ?><option value="<?=h($k)?>"<?=($t['pricing'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="agreed_fee_ex_gst">Agreed / estimated fee (ex GST)</label><input id="agreed_fee_ex_gst" name="agreed_fee_ex_gst" inputmode="decimal" maxlength="16" placeholder="0.00" value="<?=h($t['agreed_fee_ex_gst'] ?? '')?>"></div></div>
<div class="field"><label for="estimate_minutes">Time estimate (minutes)</label><input id="estimate_minutes" name="estimate_minutes" inputmode="numeric" maxlength="7" value="<?=h($t['estimate_minutes'] ?? '')?>"></div>
<p class="helper">Recorded for billing review only. Nothing is invoiced or sent to Xero.</p></details>
<?php endif; ?>
<details<?=($t['notes'] ?? '') !== '' ? ' open' : ''?>><summary>Notes</summary><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?=h($t['notes'] ?? '')?></textarea></div></details>
<button type="submit" class="save"><?=$id ? 'Save task' : 'Create task'?></button></form>
<?php cm_page_end('tasks');
