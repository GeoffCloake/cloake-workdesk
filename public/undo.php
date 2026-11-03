<?php
declare(strict_types=1);
/*
 * Undo the last save made in this session (v0.11), within CM_UNDO_WINDOW.
 * Puts back each field's previous value from the audit entry, but only if every field still
 * holds the value that save wrote (otherwise someone changed it since, and nothing is touched).
 * The undo is itself an audited update, so undoing it again = Redo.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php', true, 303); exit; }
m7verify();
$eventId = filter_var($_POST['event'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$u = $_SESSION['cm_undo'] ?? null;
$back = 'index.php';
try {
    if (!$u || (int)$u['id'] !== $eventId || time() - (int)$u['at'] > CM_UNDO_WINDOW) throw new InvalidArgumentException('That change can no longer be undone here. Open the record and edit it instead.');
    $pdo->beginTransaction();
    $ev = oneRow($pdo, 'SELECT * FROM activity_events WHERE id=?', [$eventId]);
    $table = $ev ? (CM_UNDOABLE[$ev['event_kind']] ?? null) : null;
    if (!$table) throw new InvalidArgumentException('That change cannot be undone.');
    $payload = json_decode((string)$ev['payload'], true) ?: [];
    $isChange = fn($v) => is_array($v) && array_key_exists('previous', $v) && array_key_exists('current', $v);
    // Several rows at once (v0.12, e.g. retiring the pro-forma tasks): all or nothing.
    if ($table === '*') {
        $rows = is_array($payload['_rows'] ?? null) ? $payload['_rows'] : [];
        if (!$rows) throw new InvalidArgumentException('That change cannot be undone.');
        $reverseRows = [];
        foreach ($rows as $r) {
            $t = (string)($r['table'] ?? ''); $rid = (int)($r['id'] ?? 0); $ch = array_filter((array)($r['changes'] ?? []), $isChange);
            if (!in_array($t, CM_UNDO_MULTI_TABLES, true) || !$rid || !$ch) throw new InvalidArgumentException('That change cannot be undone.');
            $row = oneRow($pdo, "SELECT * FROM `$t` WHERE id=? FOR UPDATE", [$rid]);
            if (!$row) throw new InvalidArgumentException('A record in that change no longer exists, so nothing was undone.');
            foreach ($ch as $f => $c) {
                if (!preg_match('/^[a-z_0-9]+$/', (string)$f) || !array_key_exists($f, $row)) throw new InvalidArgumentException('That change cannot be undone.');
                if (!cm_same($row[$f], $c['current'])) throw new InvalidArgumentException('One of those records was changed again after that save, so nothing was undone.');
            }
            $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($ch)));
            $extra = array_key_exists('updated_at', $row) ? ', updated_at=NOW()' : '';
            $pdo->prepare("UPDATE `$t` SET $sets$extra WHERE id=?")->execute([...array_map(fn($c) => $c['previous'], array_values($ch)), $rid]);
            $reverseRows[] = ['table' => $t, 'id' => $rid, 'changes' => array_map(fn($c) => ['previous' => $c['current'], 'current' => $c['previous']], $ch)];
        }
        $summary = str_starts_with((string)$ev['summary'], 'Undo: ') ? substr((string)$ev['summary'], 6) : 'Undo: ' . $ev['summary'];
        cm_audit($pdo, $ev['event_kind'], ['client_id' => $ev['client_id']], $summary, ['_rows' => $reverseRows]);
        $pdo->commit();
        header('Location: ' . route('templates'), true, 303); exit;
    }
    $byRowId = in_array($table, ['breakdown_items', 'billing_milestones', 'task_templates', 'enquiry_task_plans'], true);
    $rowId = (int)($byRowId ? ($payload['row_id'] ?? 0) : match ($table) {
        'clients' => $ev['client_id'], 'tasks' => $ev['task_id'], 'work_log' => $ev['work_log_id'],
        'enquiries' => $ev['enquiry_id'], 'contacts' => $payload['contact_id'] ?? 0,
    });
    $changes = array_filter($payload, $isChange);
    if (!$rowId || !$changes) throw new InvalidArgumentException('That change cannot be undone.');
    $row = oneRow($pdo, "SELECT * FROM `$table` WHERE id=? FOR UPDATE", [$rowId]);
    if (!$row) throw new InvalidArgumentException('The record no longer exists.');
    foreach ($changes as $f => $c) {
        if (!preg_match('/^[a-z_0-9]+$/', (string)$f) || !array_key_exists($f, $row)) throw new InvalidArgumentException('That change cannot be undone.');
        if (!cm_same($row[$f], $c['current'])) throw new InvalidArgumentException('It was changed again after that save, so it was not undone. Open the record and edit it instead.');
    }
    $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
    $extra = array_key_exists('updated_at', $row) ? ', updated_at=NOW()' : '';
    $pdo->prepare("UPDATE `$table` SET $sets$extra WHERE id=?")->execute([...array_map(fn($c) => $c['previous'], array_values($changes)), $rowId]);
    $reverse = array_map(fn($c) => ['previous' => $c['current'], 'current' => $c['previous']], $changes);
    $summary = str_starts_with((string)$ev['summary'], 'Undo: ') ? substr((string)$ev['summary'], 6) : 'Undo: ' . $ev['summary'];
    $ids = ['client_id' => $ev['client_id'], 'enquiry_id' => $ev['enquiry_id'], 'task_id' => $ev['task_id'], 'work_log_id' => $ev['work_log_id']];
    cm_audit($pdo, $ev['event_kind'], $ids, $summary, ($table === 'contacts' ? ['contact_id' => $rowId] : []) + ($byRowId ? ['row_id' => $rowId] : []) + $reverse);
    $pdo->commit();
    // Price lines and milestones go back to whichever record they belong to.
    $ownerUrl = function (array $r): string {
        if (!empty($r['enquiry_id'])) return 'convert-enquiry.php?id=' . (int)$r['enquiry_id'] . '#pricing';
        if (!empty($r['task_id'])) return route('task', ['id' => (int)$r['task_id']]) . '#pricing';
        if (!empty($r['template_id'])) return 'edit-template.php?id=' . (int)$r['template_id'] . '#pricing';
        return 'index.php';
    };
    $back = match ($table) {
        'clients' => route('client', ['id' => $rowId]),
        'contacts' => ($cid = (int)(isset($changes['client_id']) ? $changes['client_id']['previous'] : $row['client_id'])) ? route('client', ['id' => $cid]) . '#contacts' : route('clients'),
        'tasks' => route('task', ['id' => $rowId]),
        'work_log' => route('work'),
        'enquiries' => 'convert-enquiry.php?id=' . $rowId,
        'breakdown_items', 'billing_milestones' => $ownerUrl($row),
        'task_templates' => 'edit-template.php?id=' . $rowId,
        'enquiry_task_plans' => 'convert-enquiry.php?id=' . (int)$row['enquiry_id'] . '#plans',
    };
} catch (Throwable $e) {
    $msg = cm_edit_error($e, $pdo, 'undo event #' . $eventId);
    unset($_SESSION['cm_undo']);
    $_SESSION['cm_undo_error'] = $msg;
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $back = preg_match('#/(index\.php\?page=[a-z]+[A-Za-z0-9_=&%+.\-]*|convert-enquiry\.php\?id=\d+|edit-template\.php\?id=\d+)$#', parse_url($back, PHP_URL_PATH) . (parse_url($back, PHP_URL_QUERY) ? '?' . parse_url($back, PHP_URL_QUERY) : ''), $m) ? $m[1] : 'index.php';
}
header('Location: ' . $back, true, 303); exit;
