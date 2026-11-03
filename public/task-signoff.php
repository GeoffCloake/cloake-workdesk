<?php
declare(strict_types=1);
/*
 * Task sign-off and reopen (v0.11). Works for app-made and imported tasks (status and completed
 * date are workload fields). Both are ordinary audited task updates, so Undo applies.
 *   signoff — status completed, completed date (kept if already set), who, when, note
 *   reopen  — reason required; status back to "in progress", completed date and sign-off cleared
 * v0.12: a task with stages can only be signed off when every stage is completed or cancelled.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/edit.php';
cm_require_login();
$pdo = cm_pdo();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . route('tasks'), true, 303); exit; }
m7verify();
$id = m7id(); if (!$id) { http_response_code(400); exit('Invalid task.'); }
$action = (string)($_POST['action'] ?? '');
try {
    $pdo->beginTransaction();
    $old = oneRow($pdo, 'SELECT * FROM tasks WHERE id=? FOR UPDATE', [$id]);
    if (!$old) throw new InvalidArgumentException('Task not found.');
    $closed = in_array(strtolower(trim((string)$old['status'])), CM_CLOSED_TASK_STATUSES, true);
    if ($action === 'signoff') {
        if ($closed) throw new InvalidArgumentException('This task is already closed.');
        $open = cm_open_stages($pdo, $id);
        if ($open) throw new InvalidArgumentException('Sign off its stages first: ' . implode(', ', array_map(fn($x) => 'stage ' . $x['stage_no'] . ' (#' . $x['id'] . ')', $open)) . ' still open. A stage can also be cancelled.');
        $by = cm_post('signed_off_by', 100);
        if ($by === '') throw new InvalidArgumentException('Enter who is signing the task off.');
        $note = cm_post('sign_off_note', 500);
        $now = date('Y-m-d H:i:s');
        $new = ['status' => 'completed', 'completed_at' => $old['completed_at'] ?: $now, 'signed_off_at' => $now, 'signed_off_by' => $by, 'sign_off_note' => cm_nullable($note)];
        $summary = 'Task signed off by ' . $by . ($note !== '' ? ': ' . $note : '');
        $_SESSION['cm_last_staff'] = $by;
    } elseif ($action === 'reopen') {
        if (!$closed) throw new InvalidArgumentException('This task is not closed.');
        $reason = cm_post('reopen_reason', 500);
        if ($reason === '') throw new InvalidArgumentException('Give a reason for reopening.');
        $new = ['status' => 'in progress', 'completed_at' => null, 'signed_off_at' => null, 'signed_off_by' => null, 'sign_off_note' => null];
        $summary = 'Task reopened: ' . $reason;
    } else throw new InvalidArgumentException('Unknown action.');
    $changes = cm_changes($old, $new);
    if ($changes) {
        $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
        $pdo->prepare("UPDATE tasks SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
        cm_audit($pdo, 'task_updated', ['client_id' => $old['client_id'], 'enquiry_id' => $old['enquiry_id'], 'task_id' => $id], $summary, $changes);
    }
    $pdo->commit();
    header('Location: ' . route('task', ['id' => $id, 'saved' => 1]), true, 303); exit;
} catch (Throwable $e) {
    $msg = cm_edit_error($e, $pdo, 'task sign-off #' . $id);
    $_SESSION['cm_signoff_error'] = ['task' => $id, 'msg' => $msg];   // shown once on the task page (not passed in the URL)
    header('Location: ' . route('task', ['id' => $id]), true, 303); exit;
}
