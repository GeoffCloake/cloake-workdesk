<?php
declare(strict_types=1);
/*
 * Log work (M5 write path, kept). New in v0.9.0:
 *  - log directly against a client (client_id) with or without a task
 *  - task list follows the chosen client; task search
 *  - timer mode (browser-side; submits as an exact duration)
 * Validation, limits, columns, transaction and audit event are unchanged.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
function lookup(PDO $pdo, string $sql, array $args = []): array { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }

if (empty($_SESSION['m5_csrf'])) $_SESSION['m5_csrf'] = bin2hex(random_bytes(32));
$errors = [];
$initialTask = filter_var($_GET['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$initialClient = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$values = ['details' => '', 'date' => date('Y-m-d'), 'mode' => 'duration', 'hours' => '0', 'minutes' => '15', 'seconds' => '0', 'start' => '09:00', 'end' => '09:15',
    'task_id' => $initialTask ? (string)$initialTask : '', 'client_id' => $initialClient ? (string)$initialClient : '', 'billability' => 'review', 'bill_hours' => '0', 'bill_minutes' => '0', 'bill_seconds' => '0', 'staff' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) $values[$key] = trim(is_string($_POST[$key] ?? null) ? $_POST[$key] : $default);
    if (!hash_equals($_SESSION['m5_csrf'], (string)($_POST['csrf'] ?? ''))) $errors[] = 'Session token expired. Reload and try again.';
    $description = $values['details']; if (mb_strlen($description) < 3 || mb_strlen($description) > 5000) $errors[] = 'Describe the work (3–5000 characters).';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date']); if (!$d || $d->format('Y-m-d') !== $values['date']) $errors[] = 'Select a valid work date.';
    $mode = $values['mode']; if (!in_array($mode, ['duration', 'times'], true)) $errors[] = 'Invalid duration method.';
    $seconds = 0; $startAt = null; $endAt = null;
    if ($mode === 'duration') {
        foreach (['hours', 'minutes', 'seconds'] as $key) { if (!ctype_digit($values[$key]) || strlen($values[$key]) > 5) $errors[] = 'Enter non-negative whole numbers for duration.'; }
        if (!$errors) { $hrs = (int)$values['hours']; $mins = (int)$values['minutes']; $secs = (int)$values['seconds']; if ($mins > 59 || $secs > 59 || $hrs > 23) $errors[] = 'Duration may be up to 23h 59m 59s; minutes and seconds must be 0–59.'; else $seconds = $hrs * 3600 + $mins * 60 + $secs; }
    } elseif ($mode === 'times') {
        foreach (['start', 'end'] as $key) if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $values[$key])) $errors[] = 'Enter valid HH:MM clock times.';
        if (!$errors) { $startAt = $values['date'] . ' ' . $values['start'] . ':00'; $endAt = $values['date'] . ' ' . $values['end'] . ':00'; $seconds = strtotime($endAt) - strtotime($startAt); if ($seconds <= 0) $errors[] = 'Finish must follow start on the same day. For overnight work, use Duration only.'; }
    }
    if ($seconds < 1 || $seconds > 86399) $errors[] = 'Enter a duration greater than zero and less than 24 hours.';
    $billability = $values['billability']; if (!in_array($billability, ['review', 'non_billable', 'included_in_fee', 'billable'], true)) $errors[] = 'Invalid billing classification.';
    $billSeconds = 0;
    foreach (['bill_hours', 'bill_minutes', 'bill_seconds'] as $key) if (!ctype_digit($values[$key]) || strlen($values[$key]) > 5) $errors[] = 'Invalid billable duration.';
    if (!$errors && $billability === 'billable') {
        $bh = (int)$values['bill_hours']; $bm = (int)$values['bill_minutes']; $bs = (int)$values['bill_seconds'];
        if ($bm > 59 || $bs > 59 || $bh > 23) $errors[] = 'Billable minutes and seconds must be 0–59.';
        else $billSeconds = $bh * 3600 + $bm * 60 + $bs;
        if ($billSeconds > $seconds) $errors[] = 'Billable time cannot exceed time worked.';
    }
    $taskId = null; $clientId = null;
    if ($values['task_id'] !== '') {
        if (!ctype_digit($values['task_id'])) $errors[] = 'Invalid task ID.';
        else { $s = $pdo->prepare('SELECT id,client_id FROM tasks WHERE id=?'); $s->execute([(int)$values['task_id']]); $task = $s->fetch(); if (!$task) $errors[] = 'Task not found.'; else { $taskId = (int)$task['id']; $clientId = $task['client_id'] === null ? null : (int)$task['client_id']; } }
    }
    if ($values['client_id'] !== '') {
        if (!ctype_digit($values['client_id'])) $errors[] = 'Invalid client.';
        else {
            $s = $pdo->prepare('SELECT id FROM clients WHERE id=?'); $s->execute([(int)$values['client_id']]);
            if (!$s->fetchColumn()) $errors[] = 'Client not found.';
            elseif ($taskId !== null && $clientId !== null && $clientId !== (int)$values['client_id']) $errors[] = 'That task belongs to a different client. Choose a task for this client, or leave the task unallocated.';
            elseif ($clientId === null) $clientId = (int)$values['client_id']; // no task, or task without a client
        }
    }
    if (mb_strlen($values['staff']) > 100) $errors[] = 'Staff name is too long.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare('INSERT INTO work_log (client_id,task_id,source_system,source_id,staff_key,details,started_at,ended_at,work_date,duration_seconds,billable_seconds,billability,rate_ex_gst,billed_amount_ex_gst,billable_approved_at,legacy_record) VALUES (?,?,\'m5\',?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)');
            $s->execute([$clientId, $taskId, bin2hex(random_bytes(16)), $values['staff'] ?: null, $description, $startAt, $endAt, $values['date'], $seconds, $billSeconds, $billability]);
            $newId = (int)$pdo->lastInsertId();
            $e = $pdo->prepare('INSERT INTO activity_events(client_id,task_id,work_log_id,event_kind,event_at,summary,actor,payload) VALUES (?,?,?,\'work_log_created\',NOW(),?,?,NULL)');
            $e->execute([$clientId, $taskId, $newId, 'New Work Log entry (staging)', $values['staff'] ?: null]);
            $pdo->commit(); $_SESSION['m5_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['cm_last_staff'] = $values['staff'];
            header('Location: add-work.php?' . http_build_query(array_filter(['saved' => $newId, 'task_id' => $taskId, 'client_id' => $taskId ? null : $clientId])), true, 303); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('M5 save error: ' . $e->getMessage()); $errors[] = 'Could not save entry. No changes committed.'; }
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['cm_last_staff'])) $values['staff'] = (string)$_SESSION['cm_last_staff'];

// Context for the task list: the chosen task's client, else the chosen client.
$ctxClient = $values['client_id'] !== '' && ctype_digit($values['client_id']) ? (int)$values['client_id'] : 0;
$ctxTask = null;
if ($values['task_id'] !== '' && ctype_digit($values['task_id'])) {
    $ctxTask = lookup($pdo, 'SELECT t.id,t.source_id,t.source_system,t.title,t.client_id,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [(int)$values['task_id']])[0] ?? null;
    if ($ctxTask && $ctxTask['client_id'] && !$ctxClient) { $ctxClient = (int)$ctxTask['client_id']; $values['client_id'] = (string)$ctxClient; }
}
$closed = cm_closed_status_sql('t.status');
$tasks = [];
try {
    if ($ctxClient) $tasks = lookup($pdo, "SELECT t.id,t.source_id,t.source_system,t.title,t.status,($closed) is_closed,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.client_id=? ORDER BY ($closed) ASC, t.id DESC LIMIT 40", [$ctxClient]);
    else $tasks = lookup($pdo, "SELECT t.id,t.source_id,t.source_system,t.title,t.status,($closed) is_closed,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id ORDER BY ($closed) ASC, t.id DESC LIMIT 35");
    if ($ctxTask && !in_array((int)$ctxTask['id'], array_map('intval', array_column($tasks, 'id')), true)) array_unshift($tasks, $ctxTask + ['is_closed' => 0, 'status' => '']);
    $clients = cm_client_options($pdo);
    $staffKeys = $pdo->query("SELECT staff_key FROM work_log WHERE staff_key IS NOT NULL AND staff_key<>'' GROUP BY staff_key ORDER BY COUNT(*) DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { error_log('M5 lookup error: ' . $e->getMessage()); $errors[] = 'Task or client list unavailable'; $clients = $clients ?? []; $staffKeys = $staffKeys ?? []; }
$savedId = filter_var($_GET['saved'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$taskLabel = fn(array $t) => ($t['source_system'] === 'fabrik' ? '#' . $t['source_id'] : 'new #' . $t['id']) . ' · ' . $t['title'] . ($ctxClient ? '' : ' · ' . ($t['client'] ?? 'Unassigned'));

cm_page_start('Log work', 'work', ['back' => [route('work'), 'Work Log'], 'bodyClass' => 'form-page']);
?>
<p class="muted">Record the work first. Billing is reviewed separately.</p>
<?php if ($savedId): ?><div class="success" role="status"><strong>Entry #<?=$savedId?> saved.</strong> <?php if ($ctxTask): ?><a href="<?=h(route('task', ['id' => $ctxTask['id']]))?>">View task</a> · <?php elseif ($ctxClient): ?><a href="<?=h(route('client', ['id' => $ctxClient]))?>">View client</a> · <?php endif; ?><a href="<?=h(route('work'))?>">Work Log</a></div><?php endif; ?>
<?php if ($errors): ?><div class="alert" role="alert"><strong>Check this entry:</strong><ul><?php foreach (array_unique($errors) as $msg): ?><li><?=h($msg)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="add-work.php" autocomplete="off" class="form" data-work-form><input type="hidden" name="csrf" value="<?=h($_SESSION['m5_csrf'])?>">
<div class="field"><label for="details">What did you do?</label><textarea id="details" name="details" required maxlength="5000" placeholder="Brief work description"><?=h($values['details'])?></textarea></div>
<div class="field"><label for="client">Client <span class="opt">(optional)</span></label><?php cm_client_picker($clients, $values['client_id'], false, 'No client — assign later'); ?></div>
<div class="field"><label for="task_id">Task <span class="opt">(optional)</span></label><select id="task_id" name="task_id" data-task-select><option value="">Unallocated — assign later</option><?php foreach ($tasks as $t): ?><option value="<?=(int)$t['id']?>"<?=(string)$t['id'] === $values['task_id'] ? ' selected' : ''?>><?=h(($t['is_closed'] ? '[closed] ' : '') . $taskLabel($t))?></option><?php endforeach; ?></select>
  <p class="helper" data-task-help><?=$ctxClient ? 'This client’s tasks, open first.' : 'Recent tasks, open first. Choose a client to see only their tasks.'?></p>
  <div class="task-search" data-task-search hidden><label for="task-find" class="visually-hidden">Search all tasks</label><input id="task-find" type="search" placeholder="Search all tasks…" autocomplete="off" enterkeyhint="search"><div class="finder-results" data-task-results hidden></div></div></div>
<div class="field"><label for="date">Date</label><input type="date" id="date" name="date" required value="<?=h($values['date'])?>"></div>
<fieldset class="field"><legend>How long?</legend><div class="switches"><label><input type="radio" name="mode" value="duration"<?=$values['mode'] === 'duration' ? ' checked' : ''?>> Duration</label><label><input type="radio" name="mode" value="times"<?=$values['mode'] === 'times' ? ' checked' : ''?>> Start / finish</label><label data-js-only hidden><input type="radio" name="mode" value="timer" data-timer-mode> Timer</label></div></fieldset>
<div id="m5-duration" class="field"><div class="presets" data-presets hidden><button type="button" data-min="15">15m</button><button type="button" data-min="30">30m</button><button type="button" data-min="45">45m</button><button type="button" data-min="60">1h</button><button type="button" data-min="90">1h 30m</button></div><div class="triple"><div><label for="hours">Hours</label><input id="hours" name="hours" type="number" inputmode="numeric" min="0" max="23" step="1" value="<?=h($values['hours'])?>"></div><div><label for="minutes">Minutes</label><input id="minutes" name="minutes" type="number" inputmode="numeric" min="0" max="59" step="1" value="<?=h($values['minutes'])?>"></div><div><label for="seconds">Seconds</label><input id="seconds" name="seconds" type="number" inputmode="numeric" min="0" max="59" step="1" value="<?=h($values['seconds'])?>"></div></div><p class="helper">Exact duration, without inventing clock times.</p></div>
<div id="m5-times" class="field" hidden><div class="double"><div><label for="start">Start</label><input id="start" name="start" type="time" value="<?=h($values['start'])?>"></div><div><label for="end">Finish</label><input id="end" name="end" type="time" value="<?=h($values['end'])?>"></div></div><p class="helper">Same-day sessions. Use Duration for overnight work.</p></div>
<div id="m5-timer" class="field timer" hidden><output class="timer-clock" data-timer-clock aria-live="off">0:00:00</output><div class="timer-buttons"><button type="button" data-timer-start>Start</button><button type="button" class="secondary" data-timer-stop disabled>Stop &amp; use time</button><button type="button" class="quiet" data-timer-reset>Reset</button></div><p class="helper">The timer keeps running if you leave this page or lock your phone (it is stored on this device only). Stopping fills in the duration.</p></div>
<details><summary>Billing &amp; staff</summary><div class="field"><label for="billability">Treatment</label><select name="billability" id="billability"><?php foreach (['review' => 'Review later', 'non_billable' => 'Non-billable', 'included_in_fee' => 'Included in set fee', 'billable' => 'Potentially billable (not approved)'] as $key => $label): ?><option value="<?=h($key)?>"<?=$key === $values['billability'] ? ' selected' : ''?>><?=h($label)?></option><?php endforeach; ?></select></div><div class="field"><div class="triple"><div><label for="bill_hours">Billable hours</label><input id="bill_hours" type="number" inputmode="numeric" min="0" max="23" name="bill_hours" value="<?=h($values['bill_hours'])?>"></div><div><label for="bill_minutes">Minutes</label><input id="bill_minutes" type="number" inputmode="numeric" min="0" max="59" name="bill_minutes" value="<?=h($values['bill_minutes'])?>"></div><div><label for="bill_seconds">Seconds</label><input id="bill_seconds" type="number" inputmode="numeric" min="0" max="59" name="bill_seconds" value="<?=h($values['bill_seconds'])?>"></div></div><p class="helper">Used only if treatment is “Potentially billable”; no rate, charge or approval is assigned.</p></div><div class="field"><label for="staff">Staff</label><input id="staff" name="staff" maxlength="100" value="<?=h($values['staff'])?>" placeholder="Staff name" list="staff-list"><datalist id="staff-list"><?php foreach ($staffKeys as $sk): ?><option value="<?=h($sk)?>"><?php endforeach; ?></datalist></div></details>
<button class="save" type="submit">Save Work Log entry</button><p class="helper">Staging only. This adds a new record; imported historical entries stay unchanged. No Xero actions.</p></form>
<?php cm_page_end('work');
