<?php
declare(strict_types=1);
/*
 * List queries shared by the list screens and CSV export, so an export always
 * contains exactly what the filtered list shows (without the 100-row screen limit).
 * Each returns [sql-without-limit, args, normalised-filters].
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function cm_filter_str(array $src, string $k, int $max = 100): string { $v = $src[$k] ?? ''; return is_string($v) ? trim(mb_substr($v, 0, $max)) : ''; }
function cm_filter_date(array $src, string $k): string { $v = cm_filter_str($src, $k, 10); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }

/** Tasks. Filters: q, status (open|closed|all|<exact status>), assigned, priority, sort (new|due|priority|queue). */
function cm_tasks_query(array $src): array
{
    $f = ['q' => cm_filter_str($src, 'q'), 'status' => cm_filter_str($src, 'status', 40), 'assigned' => cm_filter_str($src, 'assigned', 60), 'priority' => cm_filter_str($src, 'priority', 25), 'sort' => cm_filter_str($src, 'sort', 10)];
    if ($f['status'] === '') $f['status'] = $f['q'] !== '' ? 'all' : 'open';
    $closed = cm_closed_status_sql('t.status');
    $where = []; $args = [];
    if ($f['q'] !== '') { $like = '%' . mb_strtolower($f['q']) . '%'; $where[] = '(LOWER(t.title) LIKE ? OR t.source_id LIKE ? OR LOWER(c.name) LIKE ?)'; array_push($args, $like, '%' . $f['q'] . '%', $like); }
    if ($f['status'] === 'open') $where[] = "NOT ($closed) AND t.completed_at IS NULL";
    elseif ($f['status'] === 'closed') $where[] = "(($closed) OR t.completed_at IS NOT NULL)";
    elseif ($f['status'] !== 'all') { $where[] = "LOWER(REPLACE(COALESCE(t.status,''),'_',' ')) = ?"; $args[] = str_replace('_', ' ', mb_strtolower($f['status'])); }
    if ($f['assigned'] !== '') { $where[] = "CONCAT(', ', LOWER(COALESCE(t.assigned_to,'')), ',') LIKE ?"; $args[] = '%, ' . mb_strtolower($f['assigned']) . ',%'; }
    if ($f['priority'] !== '') { $where[] = 'LOWER(COALESCE(t.priority,\'\')) = ?'; $args[] = mb_strtolower($f['priority']); }
    $order = [
        'due' => ' ORDER BY (t.required_by IS NULL), t.required_by ASC, t.id DESC',
        'priority' => " ORDER BY FIELD(LOWER(COALESCE(t.priority,'')),'immediate','urgent','high','normal','low','') , (t.required_by IS NULL), t.required_by ASC, t.id DESC",
        'queue' => ' ORDER BY (t.queue IS NULL), t.queue ASC, (t.required_by IS NULL), t.required_by ASC, t.id DESC',
    ][$f['sort']] ?? ' ORDER BY t.id DESC';
    $sql = 'SELECT t.id,t.title,t.source_id,t.source_system,t.status,t.priority,t.required_by,t.received_at,t.completed_at,t.client_id,t.assigned_to,t.queue,t.task_category,t.task_type,t.pricing,t.agreed_fee_ex_gst,t.invoice_number,c.name client_name,k.display_name contact_name
      FROM tasks t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN contacts k ON k.id=t.contact_id'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . $order;
    return [$sql, $args, $f];
}

/** Work Log. Filters: q, filter (all|new|review|unallocated), staff, from, to, client. */
function cm_work_query(array $src): array
{
    $f = ['q' => cm_filter_str($src, 'q'), 'filter' => in_array($src['filter'] ?? '', ['new', 'review', 'unallocated'], true) ? $src['filter'] : 'all',
          'staff' => cm_filter_str($src, 'staff', 100), 'from' => cm_filter_date($src, 'from'), 'to' => cm_filter_date($src, 'to'),
          'client' => ctype_digit((string)($src['client'] ?? '')) ? (string)$src['client'] : ''];
    $where = []; $args = [];
    $date = 'COALESCE(w.work_date, DATE(w.started_at))';
    if ($f['q'] !== '') { $like = '%' . mb_strtolower($f['q']) . '%'; $where[] = '(LOWER(w.details) LIKE ? OR LOWER(c.name) LIKE ? OR LOWER(t.title) LIKE ? OR w.source_id LIKE ?)'; array_push($args, $like, $like, $like, '%' . $f['q'] . '%'); }
    $newOnly = "(w.source_system IS NULL OR w.source_system<>'fabrik')";
    if ($f['filter'] === 'new') $where[] = $newOnly;
    if ($f['filter'] === 'review') $where[] = "$newOnly AND w.billability='review'";
    if ($f['filter'] === 'unallocated') $where[] = "$newOnly AND w.task_id IS NULL";
    if ($f['staff'] !== '') { $where[] = 'LOWER(COALESCE(w.staff_key,\'\')) = ?'; $args[] = mb_strtolower($f['staff']); }
    if ($f['from'] !== '') { $where[] = "$date >= ?"; $args[] = $f['from']; }
    if ($f['to'] !== '') { $where[] = "$date <= ?"; $args[] = $f['to']; }
    if ($f['client'] !== '') { $where[] = 'w.client_id = ?'; $args[] = (int)$f['client']; }
    $sql = "SELECT w.id,w.source_id,w.source_system,w.details,w.staff_key,w.work_date,w.started_at,w.ended_at,w.duration_seconds,w.billable_seconds,w.billability,w.rate_ex_gst,w.billed_amount_ex_gst,w.client_id,w.task_id,c.name client_name,t.title task_title, $date day
      FROM work_log w LEFT JOIN clients c ON c.id=w.client_id LEFT JOIN tasks t ON t.id=w.task_id"
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $date DESC, w.id DESC";
    return [$sql, $args, $f];
}

/** Clients. Filters: q, show (active|archive|all), role (contact role of an active contact). */
function cm_clients_query(array $src): array
{
    $f = ['q' => cm_filter_str($src, 'q'), 'show' => cm_filter_str($src, 'show', 10), 'role' => cm_filter_str($src, 'role', 60)];
    if (!in_array($f['show'], ['active', 'archive', 'all'], true)) $f['show'] = $f['q'] !== '' ? 'all' : 'active';
    $where = []; $args = [];
    if ($f['q'] !== '') {
        // LOWER() on both sides: on MySQL 8 some string expressions are case-sensitive (utf8mb4_bin).
        $like = '%' . mb_strtolower($f['q']) . '%';
        $digits = preg_replace('/\D+/', '', $f['q']) ?? '';
        $dig = fn(string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),' ',''),'-',''),'+',''),'(',''),')','') LIKE ?";
        $where[] = "(LOWER(c.name) LIKE ? OR c.source_id LIKE ? OR LOWER(c.business_name) LIKE ? OR LOWER(c.email) LIKE ?"
            . (strlen($digits) >= 4 ? ' OR ' . $dig('c.phone') : '')
            . " OR EXISTS (SELECT 1 FROM contacts k WHERE k.client_id=c.id AND (LOWER(k.display_name) LIKE ? OR LOWER(k.email) LIKE ?"
            . (strlen($digits) >= 4 ? ' OR ' . $dig('k.phone') . ' OR ' . $dig('k.mobile') : '') . ')))';
        array_push($args, $like, $like, $like, $like);
        if (strlen($digits) >= 4) $args[] = '%' . $digits . '%';
        array_push($args, $like, $like);
        if (strlen($digits) >= 4) array_push($args, '%' . $digits . '%', '%' . $digits . '%');
    }
    if ($f['show'] === 'active') $where[] = "LOWER(COALESCE(c.status,''))<>'archive'";
    if ($f['show'] === 'archive') $where[] = "LOWER(COALESCE(c.status,''))='archive'";
    if ($f['role'] !== '') { $where[] = "EXISTS (SELECT 1 FROM contacts r WHERE r.client_id=c.id AND COALESCE(r.status,'')<>'archive' AND CONCAT(', ', LOWER(COALESCE(r.contact_type,'')), ',') LIKE ?)"; $args[] = '%, ' . mb_strtolower($f['role']) . ',%'; }
    $closed = cm_closed_status_sql('t.status');
    $roleSel = $f['role'] !== '' ? "(SELECT GROUP_CONCAT(r.display_name ORDER BY r.display_name SEPARATOR ', ') FROM contacts r WHERE r.client_id=c.id AND COALESCE(r.status,'')<>'archive' AND CONCAT(', ', LOWER(COALESCE(r.contact_type,'')), ',') LIKE ?)" : 'NULL';
    if ($f['role'] !== '') array_unshift($args, '%, ' . mb_strtolower($f['role']) . ',%');
    $sql = "SELECT c.id,c.name,c.status,c.source_id,c.business_name,c.email,c.phone,COALESCE(c.physical_city_town,c.postal_city_town) town,c.xero_contact_id,
        (SELECT COUNT(*) FROM tasks t WHERE t.client_id=c.id AND NOT ($closed) AND t.completed_at IS NULL) open_tasks, $roleSel role_contacts
      FROM clients c" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.name';
    return [$sql, $args, $f];
}

/** Staff names for pickers/filters: from Work Log and task assignments, most used first. */
function cm_staff_names(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query("SELECT LOWER(staff_key) s, COUNT(*) n FROM work_log WHERE staff_key IS NOT NULL AND staff_key<>'' GROUP BY LOWER(staff_key)") as $r) $names[$r['s']] = ($names[$r['s']] ?? 0) + (int)$r['n'];
    try {
        foreach ($pdo->query("SELECT assigned_to FROM tasks WHERE assigned_to IS NOT NULL AND assigned_to<>''") as $r)
            foreach (explode(',', $r['assigned_to']) as $s) { $s = mb_strtolower(trim($s)); if ($s !== '') $names[$s] = ($names[$s] ?? 0) + 1; }
    } catch (Throwable $e) { /* migration 002 not applied */ }
    arsort($names);
    return array_keys($names);
}

/** Category => [types] from the archived Fabrik classification table (read-only). */
function cm_task_classes(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT TRIM(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.task_category'))) c, TRIM(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.task_type'))) t FROM legacy_classification") as $r) {
            $c = (string)$r['c']; $t = (string)$r['t'];
            if ($c === '' || $c === 'null') continue;
            $out[$c] = $out[$c] ?? [];
            if ($t !== '' && $t !== 'null' && !in_array($t, $out[$c], true)) $out[$c][] = $t;
        }
    } catch (Throwable $e) { return []; }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($out as &$types) sort($types, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
