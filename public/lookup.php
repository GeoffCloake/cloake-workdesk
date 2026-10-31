<?php
declare(strict_types=1);
/*
 * Read-only JSON lookups for the phone UI (instant client finder, task pickers).
 * Requires the normal signed-in session; database session is READ ONLY.
 *
 *   lookup.php?clients=1          compact client + contact index for instant client-side filtering
 *   lookup.php?tasks_for=<id>     open tasks first, then recent closed tasks, for one client
 *   lookup.php?task_q=<text>      task search by title / client / original ID
 *   lookup.php?contacts_for=<id>  contacts of one client (task form)
 */
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (!cm_is_authenticated()) { http_response_code(401); echo json_encode(['error' => 'signed_out']); exit; }
session_write_close(); // never hold the session lock during lookups
$pdo = cm_pdo(true);

function out(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

try {
    if (isset($_GET['clients'])) {
        $clients = allRows($pdo, "SELECT id, name, status, source_id, business_name, email, phone, COALESCE(physical_city_town, postal_city_town) town FROM clients ORDER BY name");
        $contacts = allRows($pdo, "SELECT client_id, display_name, email, phone, mobile FROM contacts WHERE client_id IS NOT NULL AND COALESCE(status,'')<>'archive' ORDER BY display_name");
        $byClient = [];
        foreach ($contacts as $c) {
            $mobile = ($c['mobile'] ?? '') === 'null' ? '' : (string)($c['mobile'] ?? '');
            $byClient[(int)$c['client_id']][] = [
                'n' => (string)($c['display_name'] ?? ''), 'e' => (string)($c['email'] ?? ''),
                'p' => trim(implode(' · ', array_unique(array_filter([trim((string)($c['phone'] ?? '')), trim($mobile)], 'strlen')))),
            ];
        }
        $clean = fn($v) => ($v === null || $v === 'null') ? '' : trim((string)$v);
        $rows = [];
        foreach ($clients as $c) {
            $rows[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'st' => $clean($c['status']), 'sid' => (string)$c['source_id'],
                       'alt' => $clean($c['business_name']), 'town' => $clean($c['town']),
                       'e' => $clean($c['email']), 'p' => $clean($c['phone']), 'c' => $byClient[(int)$c['id']] ?? []];
        }
        out(['build' => CLOAKE_BUILD, 'clients' => $rows]);
    }

    if (isset($_GET['contacts_for'])) {
        $cid = filter_var($_GET['contacts_for'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!$cid) { http_response_code(400); out(['error' => 'bad_client']); }
        $rows = allRows($pdo, "SELECT id, display_name, status FROM contacts WHERE client_id=? ORDER BY (COALESCE(status,'')='archive'), display_name", [$cid]);
        out(['contacts' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['display_name'], 'archived' => ($r['status'] ?? '') === 'archive'], $rows)]);
    }

    $closed = cm_closed_status_sql('t.status');
    if (isset($_GET['tasks_for'])) {
        $cid = filter_var($_GET['tasks_for'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!$cid) { http_response_code(400); out(['error' => 'bad_client']); }
        $rows = allRows($pdo, "SELECT t.id, t.source_id, t.title, t.status, t.required_by, ($closed) is_closed
              FROM tasks t WHERE t.client_id=? ORDER BY ($closed) ASC, t.id DESC LIMIT 40", [$cid]);
        out(['tasks' => array_map(fn($r) => ['id' => (int)$r['id'], 'sid' => (string)$r['source_id'], 'title' => (string)$r['title'],
            'status' => cm_status_label($r['status']), 'closed' => (bool)$r['is_closed'], 'due' => $r['required_by'] ? niceDate($r['required_by']) : ''], $rows)]);
    }

    if (isset($_GET['task_q'])) {
        $q = trim(mb_substr((string)$_GET['task_q'], 0, 80));
        if (mb_strlen($q) < 2) out(['tasks' => []]);
        $like = '%' . $q . '%';
        $rows = allRows($pdo, "SELECT t.id, t.source_id, t.title, t.status, c.name client, ($closed) is_closed
              FROM tasks t LEFT JOIN clients c ON c.id=t.client_id
              WHERE t.title LIKE ? OR c.name LIKE ? OR t.source_id LIKE ?
              ORDER BY ($closed) ASC, t.id DESC LIMIT 25", [$like, $like, $like]);
        out(['tasks' => array_map(fn($r) => ['id' => (int)$r['id'], 'sid' => (string)$r['source_id'], 'title' => (string)$r['title'],
            'client' => (string)($r['client'] ?? 'Unassigned'), 'status' => cm_status_label($r['status']), 'closed' => (bool)$r['is_closed']], $rows)]);
    }

    http_response_code(400);
    out(['error' => 'unknown_lookup']);
} catch (Throwable $e) {
    error_log('Cloake lookup failed: ' . $e->getMessage());
    http_response_code(500);
    out(['error' => 'lookup_failed']);
}
