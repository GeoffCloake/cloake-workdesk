<?php
declare(strict_types=1);
/*
 * Task stages (v0.12): "Break out" a task into stages, or "Extend" it with another stage.
 * A stage is an ordinary new task (source_system 'app') with parent_task_id + stage_no, one level deep:
 * a stage cannot have stages. It copies the parent's client, contact, enquiry, category, type,
 * Action for, priority, required date, pricing type, chargeable and billing choice.
 * The parent itself is not changed (so imported tasks can be broken out too).
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . route('tasks'), true, 303); exit; }
m7verify();
$id = m7id(); if (!$id) { http_response_code(400); exit('Invalid task.'); }
try {
    $titles = [];
    foreach (is_array($_POST['stages'] ?? null) ? array_slice($_POST['stages'], 0, 6) : [] as $s) {
        $s = is_string($s) ? trim($s) : '';
        if ($s === '') continue;
        if (mb_strlen($s) < 2 || mb_strlen($s) > 255) throw new InvalidArgumentException('Each stage needs a title (2–255 characters).');
        $titles[] = $s;
    }
    if (!$titles) throw new InvalidArgumentException('Give the stage a title.');
    $pdo->beginTransaction();
    $p = oneRow($pdo, 'SELECT * FROM tasks WHERE id=? FOR UPDATE', [$id]);
    if (!$p) throw new InvalidArgumentException('Task not found.');
    if ($p['parent_task_id']) throw new InvalidArgumentException('This task is itself a stage. Add stages to the main task instead.');
    $next = (int)(oneRow($pdo, 'SELECT COALESCE(MAX(stage_no),0) m FROM tasks WHERE parent_task_id=?', [$id])['m'] ?? 0);
    $pricing = in_array($p['pricing'], ['hourly', 'set', 'web_service'], true) ? $p['pricing'] : null;
    $ch = strtolower((string)$p['chargeable']); $chargeable = str_contains($ch, 'yes') ? 'yes' : (str_contains($ch, 'no') ? 'no' : null);
    $made = [];
    foreach ($titles as $title) {
        $next++;
        $pdo->prepare("INSERT INTO tasks (source_system,source_id,received_at,client_id,enquiry_id,contact_id,title,status,priority,required_by,task_category,task_type,assigned_to,domains,pricing,chargeable,billing_mode,parent_task_id,stage_no) VALUES ('app',?,NOW(),?,?,?,?,'open',?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([cm_new_source_id(), $p['client_id'], $p['enquiry_id'], $p['contact_id'], $title, $p['priority'], $p['required_by'], $p['task_category'], $p['task_type'], $p['assigned_to'], $p['domains'], $pricing, $chargeable, $p['billing_mode'], $id, $next]);
        $sid = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'task_created', ['client_id' => $p['client_id'], 'enquiry_id' => $p['enquiry_id'], 'task_id' => $sid], 'Stage ' . $next . ' of task #' . $id . ' created: ' . $title, ['parent_task_id' => $id, 'stage_no' => $next, 'title' => $title]);
        $made[] = $sid;
    }
    $pdo->commit();
    header('Location: ' . route('task', ['id' => $id]) . '#stages', true, 303); exit;
} catch (Throwable $e) {
    $_SESSION['cm_signoff_error'] = ['task' => $id, 'msg' => cm_edit_error($e, $pdo, 'task stages #' . $id)];   // shown once on the task page
    header('Location: ' . route('task', ['id' => $id]), true, 303); exit;
}
