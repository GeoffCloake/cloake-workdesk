<?php
declare(strict_types=1);
/*
 * Edit a Work Log entry (v0.10.0).
 *  - Entries created in this app: description, date, duration, task, client, billing decision,
 *    billable time and staff.
 *  - Imported entries: link to a task / client and set the billing decision only. Original
 *    duration, billable time, rate and amount stay as imported.
 * Same task/client consistency rules as Log work. Every change is audited (work_log_updated).
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/edit.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id(); if (!$id) { http_response_code(400); exit('Invalid entry.'); }
$w = oneRow($pdo, 'SELECT * FROM work_log WHERE id=?', [$id]);
if (!$w) { http_response_code(404); exit('Work Log entry not found.'); }
$imported = ($w['source_system'] ?? '') === 'fabrik';
$editable = $imported ? ['task_id', 'client_id', 'billability'] : ['details', 'work_date', 'duration_seconds', 'started_at', 'ended_at', 'task_id', 'client_id', 'billability', 'billable_seconds', 'staff_key'];
$BILL = ['review' => 'Review later', 'non_billable' => 'Non-billable', 'included_in_fee' => 'Included in set fee', 'billable' => 'Potentially billable (not approved)'];
$error = '';
$hms = fn(int $s) => [intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        m7verify();
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT * FROM work_log WHERE id=? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch();
        cm_check_ver($old, $editable);
        $new = [];
        $bill = cm_post('billability', 20);
        if (!array_key_exists($bill, $BILL)) throw new InvalidArgumentException('Invalid billing decision.');
        $new['billability'] = $bill;
        // Task / client, with the same consistency rule as Log work.
        $taskRaw = cm_post('task_id', 20); $clientRaw = cm_post('client_id', 20);
        $taskId = null; $taskClient = null;
        if ($taskRaw !== '') {
            if (!ctype_digit($taskRaw)) throw new InvalidArgumentException('Invalid task.');
            $task = oneRow($pdo, 'SELECT id,client_id FROM tasks WHERE id=?', [(int)$taskRaw]);
            if (!$task) throw new InvalidArgumentException('Task not found.');
            $taskId = (int)$task['id']; $taskClient = $task['client_id'] === null ? null : (int)$task['client_id'];
        }
        $clientId = cm_valid_client($pdo, $clientRaw, false);
        if ($taskId && $taskClient && $clientId && $clientId !== $taskClient) throw new InvalidArgumentException('That task belongs to a different client. Choose a task for this client, or clear the task.');
        $new['task_id'] = $taskId;
        $new['client_id'] = $clientId ?? $taskClient;
        if (!$imported) {
            $details = cm_post('details', 5000); if (mb_strlen($details) < 3) throw new InvalidArgumentException('Describe the work (3–5000 characters).');
            $new['details'] = $details;
            $new['work_date'] = m7date(cm_post('work_date', 10)) ?? throw new InvalidArgumentException('Select a valid work date.');
            $parts = [];
            foreach (['hours', 'minutes', 'seconds', 'bill_hours', 'bill_minutes', 'bill_seconds'] as $k) { $v = cm_post($k, 5); if ($v === '') $v = '0'; if (!ctype_digit($v)) throw new InvalidArgumentException('Durations must be whole numbers.'); $parts[$k] = (int)$v; }
            if ($parts['minutes'] > 59 || $parts['seconds'] > 59 || $parts['hours'] > 23 || $parts['bill_minutes'] > 59 || $parts['bill_seconds'] > 59 || $parts['bill_hours'] > 23) throw new InvalidArgumentException('Minutes and seconds must be 0–59 and hours 0–23.');
            $secs = $parts['hours'] * 3600 + $parts['minutes'] * 60 + $parts['seconds'];
            if ($secs < 1) throw new InvalidArgumentException('Enter a duration greater than zero.');
            $billSecs = $bill === 'billable' ? $parts['bill_hours'] * 3600 + $parts['bill_minutes'] * 60 + $parts['bill_seconds'] : 0;
            if ($billSecs > $secs) throw new InvalidArgumentException('Billable time cannot exceed time worked.');
            $new['duration_seconds'] = $secs; $new['billable_seconds'] = $billSecs;
            // Clock times no longer describe the entry if date or duration changed.
            if ((int)$old['duration_seconds'] !== $secs || (string)$old['work_date'] !== $new['work_date']) { $new['started_at'] = null; $new['ended_at'] = null; }
            $staff = cm_post('staff', 100); $new['staff_key'] = cm_nullable($staff);
        }
        $new = array_intersect_key($new, array_flip($editable));
        $changes = cm_changes($old, $new);
        if ($changes) {
            $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
            $pdo->prepare("UPDATE work_log SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
            cm_audit($pdo, 'work_log_updated', ['client_id' => $new['client_id'] ?? $old['client_id'], 'task_id' => $new['task_id'] ?? $old['task_id'], 'work_log_id' => $id], 'Work Log entry updated: ' . implode(', ', array_keys($changes)), $changes);
        }
        $pdo->commit();
        $back = (string)($_POST['back'] ?? '');
        header('Location: ' . (preg_match('/^index\.php\?page=(work|task|client)[A-Za-z0-9_=&%+.-]*$/', $back) ? $back . '&saved=' . ($changes ? 1 : 0) : route('work', ['saved' => $changes ? 1 : 0])), true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'work_log save #' . $id);
        foreach (['details', 'billability', 'task_id', 'client_id'] as $f) if (is_string($_POST[$f] ?? null)) $w[$f] = trim($_POST[$f]);
    }
}
$cur = oneRow($pdo, 'SELECT * FROM work_log WHERE id=?', [$id]);
$ver = cm_ver($cur, $editable);
$ctxClient = $w['client_id'] ? (int)$w['client_id'] : 0;
if (!$ctxClient && $w['task_id']) $ctxClient = (int)(oneRow($pdo, 'SELECT client_id FROM tasks WHERE id=?', [(int)$w['task_id']])['client_id'] ?? 0);
$closed = cm_closed_status_sql('t.status');
$tasks = $ctxClient
    ? allRows($pdo, "SELECT t.id,t.source_id,t.source_system,t.title,($closed) is_closed FROM tasks t WHERE t.client_id=? ORDER BY ($closed) ASC, t.id DESC LIMIT 60", [$ctxClient])
    : allRows($pdo, "SELECT t.id,t.source_id,t.source_system,t.title,($closed) is_closed FROM tasks t ORDER BY ($closed) ASC, t.id DESC LIMIT 35");
if ($w['task_id'] && !in_array((int)$w['task_id'], array_map('intval', array_column($tasks, 'id')), true)) {
    $cur_t = oneRow($pdo, "SELECT t.id,t.source_id,t.source_system,t.title,($closed) is_closed FROM tasks t WHERE t.id=?", [(int)$w['task_id']]); if ($cur_t) array_unshift($tasks, $cur_t);
}
$clients = cm_client_options($pdo);
[$H, $Mi, $S] = $hms((int)$w['duration_seconds']); [$BH, $BM, $BS] = $hms((int)$w['billable_seconds']);
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');
cm_page_start($imported ? 'Update imported work entry' : 'Edit work entry', 'work', ['back' => [preg_match('/^index\.php\?page=/', $back) ? $back : route('work'), 'Back'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($imported): ?>
<div class="panel locked"><h2 class="panel-title"><?=h($w['details'] ?: 'Work entry')?></h2>
<dl class="facts"><dt>Date</dt><dd><?=h(niceDate($w['work_date'] ?? $w['started_at']))?></dd><dt>Worked</dt><dd><?=h(dur($w['duration_seconds']))?></dd><dt>Billable (as imported)</dt><dd><?=h(dur($w['billable_seconds']))?></dd><dt>Staff</dt><dd><?=h($w['staff_key'] ?: '—')?></dd><dt>Legacy amount (ex GST)</dt><dd><?=h($w['billed_amount_ex_gst'] ?? '—')?></dd></dl>
<p class="small muted">Imported entry (original ID <?=h($w['source_id'])?>). Its description, time and amounts stay as imported. You can link it to a task or client and record a billing decision.</p></div>
<?php endif; ?>
<form method="post" class="form" data-work-form><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>"><input type="hidden" name="back" value="<?=h($back)?>">
<?php if (!$imported): ?>
<div class="field"><label for="details">What was done</label><textarea id="details" name="details" required maxlength="5000"><?=h($w['details'])?></textarea></div>
<div class="field"><label for="work_date">Date</label><input type="date" id="work_date" name="work_date" required value="<?=h($w['work_date'] ?? substr((string)$w['started_at'], 0, 10))?>"></div>
<fieldset class="field"><legend>Time worked</legend><div class="triple"><div><label for="hours">Hours</label><input id="hours" name="hours" type="number" inputmode="numeric" min="0" max="23" value="<?=$H?>"></div><div><label for="minutes">Minutes</label><input id="minutes" name="minutes" type="number" inputmode="numeric" min="0" max="59" value="<?=$Mi?>"></div><div><label for="seconds">Seconds</label><input id="seconds" name="seconds" type="number" inputmode="numeric" min="0" max="59" value="<?=$S?>"></div></div>
<?php if ($w['started_at']): ?><p class="helper">Recorded as <?=h(substr((string)$w['started_at'], 11, 5))?>–<?=h(substr((string)$w['ended_at'], 11, 5))?>. Changing the date or duration clears these clock times.</p><?php endif; ?></fieldset>
<?php endif; ?>
<div class="field"><label for="client">Client <span class="opt">(optional)</span></label><?php cm_client_picker($clients, (string)($w['client_id'] ?? ''), false, 'No client'); ?></div>
<div class="field"><label for="task_id">Task <span class="opt">(optional)</span></label><select id="task_id" name="task_id" data-task-select><option value="">No task</option><?php foreach ($tasks as $t): ?><option value="<?=(int)$t['id']?>"<?=(string)$t['id'] === (string)$w['task_id'] ? ' selected' : ''?>><?=h(($t['is_closed'] ? '[closed] ' : '') . ($t['source_system'] === 'fabrik' ? '#' . $t['source_id'] : 'new #' . $t['id']) . ' · ' . $t['title'])?></option><?php endforeach; ?></select>
<p class="helper" data-task-help><?=$ctxClient ? 'This client’s tasks, open first.' : 'Recent tasks. Choose a client to see only their tasks.'?></p>
<div class="task-search" data-task-search hidden><label for="task-find" class="visually-hidden">Search all tasks</label><input id="task-find" type="search" placeholder="Search all tasks…" autocomplete="off"><div class="finder-results" data-task-results hidden></div></div></div>
<div class="field"><label for="billability">Billing decision</label><select name="billability" id="billability"><?php foreach ($BILL as $k => $l): ?><option value="<?=h($k)?>"<?=$k === $w['billability'] ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
<?php if (!$imported): ?>
<fieldset class="field"><legend>Billable time</legend><div class="triple"><div><label for="bill_hours">Hours</label><input id="bill_hours" name="bill_hours" type="number" inputmode="numeric" min="0" max="23" value="<?=$BH?>"></div><div><label for="bill_minutes">Minutes</label><input id="bill_minutes" name="bill_minutes" type="number" inputmode="numeric" min="0" max="59" value="<?=$BM?>"></div><div><label for="bill_seconds">Seconds</label><input id="bill_seconds" name="bill_seconds" type="number" inputmode="numeric" min="0" max="59" value="<?=$BS?>"></div></div><p class="helper">Used only when the decision is “Potentially billable”; otherwise saved as zero. No rate or charge is assigned.</p></fieldset>
<div class="field"><label for="staff">Staff</label><input id="staff" name="staff" maxlength="100" value="<?=h($w['staff_key'] ?? '')?>"></div>
<?php endif; ?>
<button type="submit" class="save">Save entry</button></form>
<?php cm_page_end('work');
