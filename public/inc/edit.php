<?php
declare(strict_types=1);
/*
 * Shared helpers for edit pages: change detection, audit events, stale-edit protection,
 * and small validators. Every create/update writes one activity_events row whose payload
 * records {field: {previous, current}}.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

/** Fields whose value differs between $old and $new (string comparison, null == ''). */
function cm_changes(array $old, array $new): array
{
    $c = [];
    foreach ($new as $k => $v) {
        $o = $old[$k] ?? null;
        // Browsers send textarea line breaks as CRLF and may add/strip trailing spaces: compare normalised.
        $n = fn($x) => trim(str_replace("\r\n", "\n", (string)($x ?? '')));
        if ($n($o) !== $n($v)) $c[$k] = ['previous' => $o, 'current' => $v];
    }
    return $c;
}

/** Updates that can be undone (v0.11): event kind => table. The payload holds {field:{previous,current}}. */
const CM_UNDOABLE = ['client_updated' => 'clients', 'contact_updated' => 'contacts', 'task_updated' => 'tasks', 'work_log_updated' => 'work_log', 'enquiry_updated' => 'enquiries',
    // v0.12: rows identified by payload row_id; '*' = several rows, payload {_rows:[{table,id,changes}]}
    'price_line_updated' => 'breakdown_items', 'milestone_updated' => 'billing_milestones', 'template_updated' => 'task_templates', 'plan_updated' => 'enquiry_task_plans', 'proforma_retired' => '*', 'item_updated' => 'job_items', 'staff_updated' => 'staff',
    // v0.13
    'service_updated' => 'services', 'invoice_recorded' => 'invoice',
    // v0.19: a plan's details (payload row_id); several services linked to plans at once ('*')
    'service_plan_updated' => 'plans', 'services_plans_matched' => '*'];
/** Tables a multi-row undo ('*') may touch. */
const CM_UNDO_MULTI_TABLES = ['tasks', 'clients', 'services'];
/** How long after saving the Undo link stays usable (seconds). */
const CM_UNDO_WINDOW = 1800;

/** Write one audit event. $ids: client_id, enquiry_id, task_id, work_log_id (any subset). */
function cm_audit(PDO $pdo, string $kind, array $ids, string $summary, ?array $payload = null): void
{
    $s = $pdo->prepare('INSERT INTO activity_events (client_id,enquiry_id,task_id,work_log_id,event_kind,event_at,summary,payload) VALUES (?,?,?,?,?,NOW(),?,?)');
    $s->execute([$ids['client_id'] ?? null, $ids['enquiry_id'] ?? null, $ids['task_id'] ?? null, $ids['work_log_id'] ?? null, $kind, mb_substr($summary, 0, 1000),
        $payload === null ? null : json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    // Offer Undo for the most recent update made in this session (shown once on the next page).
    if (isset(CM_UNDOABLE[$kind]) && $payload && session_status() === PHP_SESSION_ACTIVE && (CM_UNDOABLE[$kind] !== '*' || !empty($payload['_rows'])) && (CM_UNDOABLE[$kind] !== 'invoice' || !empty($payload['_invoice']))) {
        $_SESSION['cm_undo'] = ['id' => (int)$pdo->lastInsertId(), 'at' => time(), 'show' => true, 'summary' => mb_substr($summary, 0, 160)];
    }
}

/** Same value for undo purposes: text normalised like cm_changes; numbers numerically; a date matches its datetime. */
function cm_same($a, $b): bool
{
    $n = fn($x) => trim(str_replace("\r\n", "\n", (string)($x ?? '')));
    $x = $n($a); $y = $n($b);
    if ($x === $y) return true;
    if ($x !== '' && $y !== '' && is_numeric($x) && is_numeric($y)) return (float)$x === (float)$y;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $y) && str_starts_with($x, $y . ' 00:00:00')) return true;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) && str_starts_with($y, $x . ' 00:00:00')) return true;
    return false;
}

/**
 * Version token for stale-edit protection: a hash of the row's editable fields when the
 * form was opened. If someone else saved in between, the token no longer matches.
 */
function cm_ver(array $row, array $fields): string
{
    $parts = [];
    foreach ($fields as $f) $parts[] = $f . '=' . (string)($row[$f] ?? '');
    return hash('sha256', implode("\x1f", $parts));
}
function cm_check_ver(array $row, array $fields): void
{
    if (!hash_equals(cm_ver($row, $fields), (string)($_POST['ver'] ?? ''))) {
        throw new InvalidArgumentException('This record was changed by someone else after you opened it. Reload the page to see their changes, then make yours again.');
    }
}

function cm_post(string $k, int $max = 255): string
{
    $v = $_POST[$k] ?? '';
    $v = is_string($v) ? trim(str_replace("\r\n", "\n", $v)) : '';
    if (mb_strlen($v) > $max) throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . " is too long (maximum $max characters).");
    return $v;
}
function cm_nullable(string $v): ?string { return $v === '' ? null : $v; }
function cm_new_source_id(): string { return 'app-' . bin2hex(random_bytes(8)); }
function cm_valid_client(PDO $pdo, string $v, bool $required): ?int
{
    if ($v === '') { if ($required) throw new InvalidArgumentException('Choose a client.'); return null; }
    if (!ctype_digit($v)) throw new InvalidArgumentException('Invalid client.');
    $s = $pdo->prepare('SELECT id FROM clients WHERE id=?'); $s->execute([(int)$v]);
    if (!$s->fetchColumn()) throw new InvalidArgumentException('Client not found.');
    return (int)$v;
}
/** "a, b" from a list-ish stored value (handles legacy JSON arrays). */
function cm_list_text(?string $v): string
{
    $v = trim((string)$v);
    if ($v !== '' && $v[0] === '[') { $d = json_decode($v, true); if (is_array($d)) return implode(', ', array_filter(array_map('strval', $d), 'strlen')); }
    return $v;
}
const CM_CONTACT_TYPES = ['accounts' => 'Accounts', 'project liaison' => 'Project liaison', 'domain owner' => 'Domain owner', 'domain admin' => 'Domain admin', 'domain tech' => 'Domain tech'];
const CM_CONTACT_METHODS = ['' => '—', 'email' => 'Email', 'phone' => 'Phone', 'mobile' => 'Mobile', 'post' => 'Post'];
// Status and priority words follow the Fabrik task manager (stored lower-case, as imported).
const CM_TASK_STATUSES = ['open' => 'Open (new)', 'pending' => 'Pending', 'planned' => 'Planned', 'in progress' => 'In progress', 'ongoing' => 'Ongoing', 'waiting' => 'Waiting', 'on hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
const CM_PRIORITIES = ['immediate' => 'Immediate', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];
/** Older values still accepted when a record already has them (e.g. 'urgent' on early enquiries, 'in_progress'). */
const CM_LEGACY_PRIORITIES = ['urgent'];

/** Standard error handling for edit pages: returns the message to show. */
function cm_edit_error(Throwable $e, PDO $pdo, string $logLabel): string
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof InvalidArgumentException) return $e->getMessage();
    error_log('Cloake ' . $logLabel . ': ' . $e->getMessage());
    return 'Could not save. No changes were made. The error has been logged.';
}

/** v0.12: stages of a task that are still open (not completed/cancelled). */
function cm_open_stages(PDO $pdo, int $taskId): array
{
    return allRows($pdo, 'SELECT id,stage_no,title FROM tasks WHERE parent_task_id=? AND NOT (' . cm_closed_status_sql('status') . ') AND completed_at IS NULL ORDER BY stage_no,id', [$taskId]);
}
