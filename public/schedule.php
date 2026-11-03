<?php
declare(strict_types=1);
/*
 * Schedule a task (v0.14): the day, the time booked, and (optionally) move it to another person.
 *   GET  ?id=&who=        the Move form (phones; also the fallback without JavaScript)
 *   POST id, scheduled_on (blank = take off the schedule), hours/minutes (blank = keep / use the estimate),
 *        from (the person it was under), to (the person to move it to); ajax=1 answers JSON (drag and drop).
 * An ordinary audited task update (task_updated) — Undo puts it back. Works for imported tasks too (these are
 * workload fields; title, instructions and billing stay locked). Closed tasks cannot be scheduled.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id(); if (!$id) { http_response_code(400); exit('Invalid task.'); }
$ajax = ($_POST['ajax'] ?? '') === '1';
$who = mb_strtolower(trim((string)($_POST['from'] ?? $_GET['who'] ?? '')));
$error = '';
$back = route('workload', array_filter(['who' => (string)($_GET['back_who'] ?? '')]));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    try {
        $pdo->beginTransaction();
        $old = oneRow($pdo, 'SELECT * FROM tasks WHERE id=? FOR UPDATE', [$id]);
        if (!$old) throw new InvalidArgumentException('Task not found.');
        if (in_array(strtolower(trim((string)$old['status'])), CM_CLOSED_TASK_STATUSES, true) || $old['completed_at']) throw new InvalidArgumentException('This task is closed, so it is not scheduled.');
        $date = m7date(cm_post('scheduled_on', 10));
        $new = ['scheduled_on' => $date];
        $h = cm_post('hours', 4); $m = cm_post('minutes', 2);
        if ($h !== '' || $m !== '') {
            if (($h !== '' && !ctype_digit($h)) || ($m !== '' && !ctype_digit($m)) || (int)$m > 59) throw new InvalidArgumentException('Time booked is whole hours and minutes (0–59).');
            $new['scheduled_minutes'] = ((int)$h * 60 + (int)$m) ?: null;
        }
        $to = mb_strtolower(trim(cm_post('to', 40)));
        if ($to !== '' && $to !== $who) {
            if (!preg_match('/^[\p{L}0-9 ._\'-]{1,40}$/u', $to)) throw new InvalidArgumentException('Invalid person.');
            if (!oneRow($pdo, 'SELECT id FROM staff WHERE name=?', [$to])) throw new InvalidArgumentException('That person is not on Staff & hours.');
            $new['assigned_to'] = cm_reassign($old['assigned_to'], $who, $to);
        }
        $changes = cm_changes($old, $new);
        if (isset($changes['scheduled_minutes']) && (string)$changes['scheduled_minutes']['previous'] === (string)$changes['scheduled_minutes']['current']) unset($changes['scheduled_minutes']);
        if ($changes) {
            $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
            $pdo->prepare("UPDATE tasks SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
            $what = $date ? 'scheduled for ' . (new DateTimeImmutable($date))->format('D j M') : 'taken off the schedule';
            if (isset($changes['assigned_to'])) $what .= ', moved to ' . ucfirst($to);
            cm_audit($pdo, 'task_updated', ['client_id' => $old['client_id'], 'enquiry_id' => $old['enquiry_id'], 'task_id' => $id], 'Task #' . $id . ' ' . $what, $changes);
        }
        $pdo->commit();
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'changed' => (bool)$changes]); exit; }
        header('Location: ' . route('workload', array_filter(['from' => $date ? cm_week_start($date) : null, 'who' => (string)($_POST['back_who'] ?? '')])), true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'schedule task #' . $id);
        if ($ajax) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $error]); exit; }
    }
}
$t = oneRow($pdo, 'SELECT t.*,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [$id]);
if (!$t) { http_response_code(404); exit('Task not found.'); }
$staff = cm_staff($pdo, true);
$names = array_column($staff, 'name');
foreach (cm_assignees($t['assigned_to']) as $n) if (!in_array($n, $names, true)) $staff[] = ['name' => $n, 'display_name' => ucfirst($n) . ' (not on Staff)', 'active' => 0];
if ($who === '') $who = cm_assignees($t['assigned_to'])[0] ?? '';
$min = (int)($t['scheduled_minutes'] ?: 0); $est = (int)($t['estimate_minutes'] ?: 0);
cm_page_start('Schedule task', 'workload', ['back' => [route('workload'), 'Workload'], 'bodyClass' => 'form-page']);
?>
<p class="muted small"><a href="<?=h(route('task', ['id' => $t['id']]))?>">#<?=(int)$t['id']?> <?=h($t['title'])?></a><?=$t['client_name'] ? ' · ' . h($t['client_name']) : ''?><?=$est ? ' · estimate ' . h(cm_hm($est)) : ''?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" action="schedule.php" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="from" value="<?=h($who)?>">
<div class="field"><label for="scheduled_on">Day</label><input type="date" id="scheduled_on" name="scheduled_on" value="<?=h((string)($t['scheduled_on'] ?? ''))?>"><p class="helper">Leave empty to take it off the schedule.</p></div>
<fieldset class="field"><legend class="sub">Time booked <span class="opt">(blank = the estimate)</span></legend><div class="double"><div><label for="hours">Hours</label><input id="hours" name="hours" inputmode="numeric" maxlength="4" value="<?=$min ? intdiv($min, 60) : ''?>"></div><div><label for="minutes">Minutes</label><input id="minutes" name="minutes" inputmode="numeric" maxlength="2" value="<?=$min ? $min % 60 : ''?>"></div></div></fieldset>
<div class="field"><label for="to">Person</label><select id="to" name="to"><?php foreach ($staff as $s): ?><option value="<?=h($s['name'])?>"<?=$s['name'] === $who ? ' selected' : ''?>><?=h(cm_staff_label($s))?></option><?php endforeach; ?></select><p class="helper">Choosing someone else replaces <?=h(ucfirst($who ?: 'nobody'))?> in Action for (others stay).</p></div>
<div class="form-actions"><button type="submit" class="save">Save</button><a class="cancel" href="<?=h(route('workload'))?>">Cancel</a></div></form>
<?php cm_page_end('workload');
