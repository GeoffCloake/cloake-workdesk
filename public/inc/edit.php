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

/** Write one audit event. $ids: client_id, enquiry_id, task_id, work_log_id (any subset). */
function cm_audit(PDO $pdo, string $kind, array $ids, string $summary, ?array $payload = null): void
{
    $s = $pdo->prepare('INSERT INTO activity_events (client_id,enquiry_id,task_id,work_log_id,event_kind,event_at,summary,payload) VALUES (?,?,?,?,?,NOW(),?,?)');
    $s->execute([$ids['client_id'] ?? null, $ids['enquiry_id'] ?? null, $ids['task_id'] ?? null, $ids['work_log_id'] ?? null, $kind, mb_substr($summary, 0, 1000),
        $payload === null ? null : json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
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
