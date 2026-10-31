<?php
declare(strict_types=1);
/*
 * CSV export of the Clients, Tasks or Work Log list, with the same filters as the screen
 * (shared queries in inc/queries.php) but without the 100-row screen limit.
 * Read-only database session; signed-in users only. UTF-8 with BOM so Excel shows macrons
 * correctly; cells that could be read as spreadsheet formulas are prefixed with an apostrophe.
 *   export.php?type=clients|tasks|work&<list filters>
 */
require __DIR__ . '/inc/bootstrap.php';
if (!cm_is_authenticated()) { header('Location: ./', true, 302); exit; }
session_write_close();
$pdo = cm_pdo(true);
$type = (string)($_GET['type'] ?? '');
const CM_EXPORT_MAX = 50000;
$hm = fn($s) => sprintf('%d:%02d', intdiv((int)$s, 3600), intdiv((int)$s % 3600, 60));
$hours = fn($s) => number_format((int)$s / 3600, 2, '.', '');

try {
    if ($type === 'clients') {
        [$sql, $args] = cm_clients_query($_GET);
        $head = ['ID', 'Original ID', 'Client name', 'Trading name', 'Status', 'Business email', 'Business phone', 'Town', 'Xero reference', 'Open tasks', 'Active contacts'];
        $contacts = [];
        foreach ($pdo->query("SELECT client_id, display_name, contact_type, mobile, phone, email FROM contacts WHERE client_id IS NOT NULL AND COALESCE(status,'')<>'archive' ORDER BY display_name") as $k) {
            $contacts[(int)$k['client_id']][] = trim($k['display_name'] . ($k['contact_type'] ? ' (' . cm_list_text($k['contact_type']) . ')' : '') . ' ' . implode(' ', array_filter([(string)($k['mobile'] ?: $k['phone']), (string)$k['email']])));
        }
        $map = fn($r) => [$r['id'], $r['source_id'], $r['name'], $r['business_name'], $r['status'], $r['email'], $r['phone'], $r['town'], $r['xero_contact_id'], $r['open_tasks'], implode('; ', $contacts[(int)$r['id']] ?? [])];
    } elseif ($type === 'tasks') {
        [$sql, $args] = cm_tasks_query($_GET);
        $head = ['ID', 'Original ID', 'Task title', 'Client', 'Contact', 'Status', 'Priority', 'Action for', 'Category', 'Type', 'Queue', 'Received', 'Required', 'Completed', 'Pricing', 'Set/estimated value (ex GST)', 'Invoice no'];
        $d = fn($v) => $v ? substr((string)$v, 0, 10) : '';
        $map = fn($r) => [$r['id'], $r['source_system'] === 'fabrik' ? $r['source_id'] : '', $r['title'], $r['client_name'], $r['contact_name'], cm_status_label($r['status']), cm_status_label($r['priority']), $r['assigned_to'], $r['task_category'], $r['task_type'], $r['queue'], $d($r['received_at']), $d($r['required_by']), $d($r['completed_at']), $r['pricing'], $r['agreed_fee_ex_gst'], $r['invoice_number']];
    } elseif ($type === 'work') {
        [$sql, $args] = cm_work_query($_GET);
        $head = ['ID', 'Original ID', 'Date', 'Staff', 'Client', 'Task', 'Details', 'Start', 'Finish', 'Worked (h:mm)', 'Worked (hours)', 'Billable (h:mm)', 'Billable (hours)', 'Billing decision', 'Charge rate (as imported)', 'Legacy amount ex GST (as imported)'];
        $t = fn($v) => $v ? substr((string)$v, 11, 5) : '';
        $map = fn($r) => [$r['id'], $r['source_system'] === 'fabrik' ? $r['source_id'] : '', $r['day'], $r['staff_key'], $r['client_name'], $r['task_title'], $r['details'], $t($r['started_at']), $t($r['ended_at']),
            $hm($r['duration_seconds']), $hours($r['duration_seconds']), $hm($r['billable_seconds']), $hours($r['billable_seconds']), $r['source_system'] === 'fabrik' ? 'imported, not reviewed' : cm_status_label($r['billability']), $r['rate_ex_gst'], $r['billed_amount_ex_gst']];
    } else { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); exit('Unknown export type.'); }

    $s = $pdo->prepare($sql . ' LIMIT ' . CM_EXPORT_MAX); $s->execute($args);
    $name = 'cloake-' . $type . '-' . date('Y-m-d-Hi') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = function ($v): string {
        $v = (string)($v ?? '');
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) ? "'" . $v : $v;
    };
    fputcsv($out, $head, ',', '"', '');
    while ($r = $s->fetch()) fputcsv($out, array_map($safe, $map($r)), ',', '"', '');
    fclose($out);
} catch (Throwable $e) {
    error_log('Cloake export ' . $type . ' failed: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    echo 'Export failed. The error has been logged.';
}
