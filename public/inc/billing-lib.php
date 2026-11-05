<?php
declare(strict_types=1);
/*
 * Billing core (v0.13): settings, billing periods, Xero descriptions, and the Pending Invoicing list.
 *
 * Everything waiting to be invoiced, by kind (key format used by forms in brackets):
 *   service period  [svc:<service id>:<period start>]  active, billable service whose next period starts within the lead time
 *   milestone       [ms:<milestone id>]                 ready (pricing module) and not yet charged
 *   task fee        [fee:<task id>]                     task billed on completion, signed off/completed on or after charges_from,
 *                                                       with price lines (or billable hours × rate), not yet charged
 *   month-end claim [chg:<charge id>]                   a pending charge made by the month-end run
 * Recording an invoice (invoice-record.php) writes one charges row per item with the Xero invoice number;
 * a service then moves on to its next period. Nothing is sent anywhere.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_SERVICE_KINDS = ['hosting' => 'Hosting', 'email' => 'Email', 'domain' => 'Domain', 'dns' => 'DNS & forwarding', 'maintenance' => 'Maintenance'];
const CM_SERVICE_STATUSES = ['active' => 'Active', 'pending' => 'Pending — in development', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'terminated' => 'Terminated', 'transferred_out' => 'Transferred away', 'expired' => 'Expired'];
const CM_SERVICE_LIVE = ['active', 'pending', 'suspended'];
const CM_CYCLES = ['' => 'No cycle', '1' => 'Monthly', '3' => 'Quarterly', '6' => 'Six-monthly', '12' => 'Yearly', '24' => 'Two-yearly'];
const CM_CHECK_STATUSES = ['' => '—', 'agrees' => 'Agrees with Xero', 'xero_ahead' => 'Xero ahead of WHMCS', 'whmcs_ahead' => 'WHMCS ahead (no Xero invoice)', 'no_invoice' => 'No invoice found', 'price_differs' => 'Price differs', 'decide' => 'Needs a decision'];
const CM_CHARGE_KINDS = ['service' => 'Renewal', 'milestone' => 'Milestone', 'claim' => 'Month-end claim', 'progress' => 'Progress claim', 'task_fee' => 'Task', 'manual' => 'Manual'];
const CM_SETTINGS = [
    'lead_weeks' => ['Lead time (weeks before a period starts)', '8'],
    'gst_rate' => ['GST rate', '0.15'],
    'xero_account_hosting' => ['Xero account — hosting, email, DNS', '43504'],
    'xero_account_domain' => ['Xero account — domain renewals', '43601'],
    'xero_account_default' => ['Xero account — task work (optional)', ''],
    'hourly_rate_ex_gst' => ['Hourly rate ex GST (month-end claims, hourly tasks)', ''],
    'charges_from' => ['Task fees from (tasks completed on or after)', ''],
    'material_markup_pct' => ['Materials markup % on cost (blank = sell at cost)', ''],
    'xero_tax_type' => ['Xero tax type on draft invoice lines (OUTPUT2 = 15% GST on Income)', 'OUTPUT2'],
    'xero_tax_type_overseas' => ['Xero tax type for overseas clients (ZERORATEDOUTPUT = Zero Rated)', 'ZERORATEDOUTPUT'],
    // v0.20.1: your own sending mailboxes on client accounts are not client mailboxes (not counted, not billed)
    'management_mailboxes' => ['Management mailboxes, not billed — names before the @, comma-separated (* = any characters)', 'webmailer'],
    'dev_accounts' => ['Development cPanel accounts — usernames, comma-separated (their sites are listed on cPanel check to record as in development)', ''],
    'management_subdomains' => ['Mailboxes on a subdomain (e.g. x@mail.example.co.nz) are management, not billed (yes / no)', 'yes'],
];

/** v0.21: does services.keep_billing exist (migration 010)? */
function cm_has_keep_billing(PDO $pdo): bool
{
    static $has = null;
    return $has ??= (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='keep_billing'");
}
/** SQL: the service's status allows invoicing — Active, or Suspended / Pending with "Keep billing" ticked (v0.21 policy). */
function cm_billing_status_sql(PDO $pdo, string $a = 's'): string
{
    return cm_has_keep_billing($pdo) ? "($a.status='active' OR ($a.status IN ('pending','suspended') AND $a.keep_billing=1))" : "$a.status IN ('active','pending','suspended')";
}
/** The same test for one service row. */
function cm_billed_by_status(array $s): bool
{
    return ($s['status'] ?? '') === 'active' || (in_array($s['status'] ?? '', ['pending', 'suspended'], true) && (!array_key_exists('keep_billing', $s) || !empty($s['keep_billing'])));
}

function cm_setting(PDO $pdo, string $k): string
{
    static $cache = null;
    if ($cache === null) { $cache = []; try { foreach ($pdo->query('SELECT k,v FROM settings') as $r) $cache[$r['k']] = (string)$r['v']; } catch (Throwable $e) { } }
    return $cache[$k] ?? (CM_SETTINGS[$k][1] ?? '');
}
function cm_gst_rate(PDO $pdo): float { $g = cm_setting($pdo, 'gst_rate'); return is_numeric($g) ? (float)$g : CM_GST_RATE; }
function cm_incl(PDO $pdo, float $ex, bool $overseas = false): float { return $overseas ? round($ex, 2) : round($ex * (1 + cm_gst_rate($pdo)), 2); }

/** Add whole months, keeping the day where possible (31 Jan + 1 month = 28/29 Feb). */
function cm_add_months(string $date, int $n): string
{
    $d = new DateTimeImmutable($date);
    $y = (int)$d->format('Y'); $m = (int)$d->format('n') + $n; $day = (int)$d->format('j');
    $y += intdiv($m - 1, 12); $m = (($m - 1) % 12 + 12) % 12 + 1;
    if ($m < 1) { $m += 12; $y--; }
    $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    return sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
}
function cm_period_end(string $start, int $months): string { return (new DateTimeImmutable(cm_add_months($start, $months)))->modify('-1 day')->format('Y-m-d'); }
function cm_dmy(string $d): string { return (new DateTimeImmutable($d))->format('d/m/Y'); }
function cm_account_for(PDO $pdo, string $kind): string { return cm_setting($pdo, $kind === 'domain' ? 'xero_account_domain' : 'xero_account_hosting'); }

/** Suggested Xero line description (v0.18.2): one short line, no plan names or software versions, no email setup. */
function cm_service_description(array $s, string $start, string $end): string
{
    $dom = cm_desc_clean((string)($s['domain'] ?: $s['label'] ?: ''));
    $m = (int)($s['cycle_months'] ?: ($s['kind'] === 'domain' ? 12 : 0));
    $len = $m <= 0 ? '' : ($m % 12 === 0 ? ($m / 12) . ' year' . ($m === 12 ? '' : 's') : $m . ' month' . ($m === 1 ? '' : 's'));
    $per = cm_dmy($start) . ' to ' . cm_dmy($end) . ($len ? " ($len)" : '');
    $mb = (int)($s['mailboxes'] ?? 0);
    $mbTxt = $mb > 0 ? $mb . ' mailbox' . ($mb === 1 ? '' : 'es') : '';
    switch ($s['kind']) {
        case 'domain': $out = "Domain renewal – $dom – $per"; break;
        case 'email': $out = 'Email hosting – ' . $dom . ($mbTxt ? " – $mbTxt" : '') . " – $per"; break;
        case 'dns': $out = "DNS and forwarding – $dom – $per"; break;
        case 'maintenance': $out = "Website maintenance – $dom – $per"; break;
        default:
            $out = "Website hosting – $dom – $per";
            $inc = array_values(array_filter(array_map('cm_desc_clean', (array)($s['included_domains'] ?? []))));
            $extra = array_filter([$inc ? implode(', ', $inc) : '', $mbTxt]);
            if ($extra) $out .= '. Includes ' . implode(' and ', $extra);
    }
    return cm_desc_clean($out);
}

/** Never let an email address, web address or login-looking text into a Xero description. */
function cm_desc_clean(string $t): string
{
    $t = preg_replace('~\b[\w.+-]+@[\w-]+(\.[\w-]+)+\b~u', '', $t);
    $t = preg_replace('~\bhttps?://\S+~iu', '', $t);
    $t = preg_replace('~\b(user(name)?|login|pass(word)?|pwd|pw)\s*[:=]\s*\S+~iu', '', $t);
    return trim(preg_replace('~[ \t]{2,}~', ' ', $t));
}

/** The billable hours on a task not yet charged (optionally up to a date), and their value at the hourly rate. */
function cm_task_unbilled_hours(PDO $pdo, int $taskId, ?string $from = null, ?string $to = null): array
{
    $w = ["task_id=?", "charge_id IS NULL", "billability='billable'", "billable_seconds>0", "(source_system IS NULL OR source_system<>'fabrik')"]; $a = [$taskId];
    $date = 'COALESCE(work_date, DATE(started_at))';
    if ($from) { $w[] = "$date >= ?"; $a[] = $from; }
    if ($to) { $w[] = "$date <= ?"; $a[] = $to; }
    $r = oneRow($pdo, 'SELECT COUNT(*) n, COALESCE(SUM(billable_seconds),0) s FROM work_log WHERE ' . implode(' AND ', $w), $a);
    return ['entries' => (int)$r['n'], 'seconds' => (int)$r['s']];
}

/**
 * Everything due to invoice, grouped later by client. $horizon = include service periods starting on or before this date.
 * Each item: key, kind, client_id, client_name, label, description, period_start, period_end, amount (ex GST), account, overdue, url, (editable_amount)
 */
function cm_due_items(PDO $pdo, ?string $horizon = null): array
{
    $today = date('Y-m-d');
    $horizon ??= (new DateTimeImmutable($today))->modify('+' . max(0, (int)cm_setting($pdo, 'lead_weeks')) . ' weeks')->format('Y-m-d');
    $items = [];
    // 1. Service periods
    foreach (allRows($pdo, "SELECT s.*, c.name client_name FROM services s LEFT JOIN clients c ON c.id=s.client_id
        WHERE " . cm_billing_status_sql($pdo) . " AND s.billable=1 AND s.is_internal=0 AND s.price_ex_gst>0 AND s.cycle_months>0" . (cm_has_domains($pdo) ? ' AND s.included_in_service_id IS NULL' : '') . "
          AND s.next_period_start IS NOT NULL AND s.next_period_start<=? ORDER BY s.next_period_start, s.id", [$horizon]) as $s) {
        $start = (string)$s['next_period_start']; $end = cm_period_end($start, (int)$s['cycle_months']);
        if ($s['kind'] === 'hosting') $s['included_domains'] = cm_included_domains($pdo, (int)$s['id']);
        $items[] = ['key' => 'svc:' . $s['id'] . ':' . $start, 'kind' => 'service', 'client_id' => $s['client_id'], 'client_name' => $s['client_name'],
            'label' => $s['domain'] ?: $s['label'], 'description' => cm_service_description($s, $start, $end), 'period_start' => $start, 'period_end' => $end,
            'amount' => (float)$s['price_ex_gst'], 'account' => $s['xero_account'] ?: cm_account_for($pdo, $s['kind']), 'overdue' => $start < $today,
            'note' => $s['check_status'] && $s['check_status'] !== 'agrees' ? (CM_CHECK_STATUSES[$s['check_status']] ?? $s['check_status']) : '', 'url' => route('service', ['id' => $s['id']])];
    }
    // 2. Ready milestones without a charge
    foreach (allRows($pdo, "SELECT m.*, COALESCE(e.client_id,t.client_id) client_id, c.name client_name, COALESCE(t.title,e.title) home_title FROM billing_milestones m
        LEFT JOIN enquiries e ON e.id=m.enquiry_id LEFT JOIN tasks t ON t.id=m.task_id LEFT JOIN clients c ON c.id=COALESCE(e.client_id,t.client_id)
        WHERE m.status='planned' AND (m.enquiry_id IS NOT NULL OR m.task_id IS NOT NULL)
          AND NOT EXISTS (SELECT 1 FROM charges g WHERE g.source_kind='milestone' AND g.source_id=m.id AND g.status<>'void')") as $m) {
        $st = cm_ms_state($pdo, $m);
        if ($st['state'] !== 'ready') continue;
        $o = cm_owner($pdo, $m['enquiry_id'] ? 'enquiry' : 'task', (int)($m['enquiry_id'] ?: $m['task_id']));
        $items[] = ['key' => 'ms:' . $m['id'], 'kind' => 'milestone', 'client_id' => $m['client_id'], 'client_name' => $m['client_name'], 'label' => $m['label'],
            'description' => $m['label'] . ' - ' . $m['home_title'], 'period_start' => substr((string)($st['since'] ?: $m['created_at']), 0, 10), 'period_end' => null,
            'amount' => cm_ms_amount($m, cm_price_lines($pdo, $o)), 'account' => cm_setting($pdo, 'xero_account_default'), 'overdue' => false, 'note' => $st['why'], 'url' => $o['url'] . '#pricing'];
    }
    // 3. Task fees: billed on completion, done on/after charges_from, not yet charged, no milestone billing
    $from = cm_setting($pdo, 'charges_from');
    if ($from !== '') foreach (allRows($pdo, "SELECT t.*, c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id
        WHERE (t.billing_mode IS NULL OR t.billing_mode='') AND COALESCE(t.signed_off_at, t.completed_at) >= ? AND LOWER(COALESCE(t.status,''))='completed'
          AND LOWER(COALESCE(t.chargeable,'')) NOT LIKE '%no%'
          AND NOT EXISTS (SELECT 1 FROM charges g WHERE g.source_kind='task_fee' AND g.source_id=t.id AND g.status<>'void')
          AND NOT EXISTS (SELECT 1 FROM billing_milestones m WHERE m.task_id=t.id AND m.status<>'void')", [$from . ' 00:00:00']) as $t) {
        $lines = cm_price_lines($pdo, cm_owner($pdo, 'task', (int)$t['id']));
        $amount = cm_lines_total($lines); $how = $lines ? count($lines) . ' price line' . (count($lines) === 1 ? '' : 's') : '';
        if (!$lines) {
            $h = cm_task_unbilled_hours($pdo, (int)$t['id']); $rate = (float)cm_setting($pdo, 'hourly_rate_ex_gst');
            if (!$h['seconds']) continue;
            $amount = round($h['seconds'] / 3600 * $rate, 2); $how = dur($h['seconds']) . ' billable' . ($rate ? ' × ' . cm_money($rate) : ' (set the hourly rate in Billing settings)');
        }
        $items[] = ['key' => 'fee:' . $t['id'], 'kind' => 'task_fee', 'client_id' => $t['client_id'], 'client_name' => $t['client_name'], 'label' => $t['title'],
            'description' => $t['title'], 'period_start' => substr((string)($t['signed_off_at'] ?: $t['completed_at']), 0, 10), 'period_end' => null,
            'amount' => $amount, 'account' => cm_setting($pdo, 'xero_account_default'), 'overdue' => false, 'note' => $how, 'url' => route('task', ['id' => $t['id']]), 'editable_amount' => true];
    }
    // 4. Month-end claims waiting (pending charges)
    foreach (allRows($pdo, "SELECT g.*, c.name client_name, t.title task_title FROM charges g LEFT JOIN clients c ON c.id=g.client_id LEFT JOIN tasks t ON t.id=g.source_id AND g.source_kind IN ('claim','progress')
        WHERE g.status='pending' ORDER BY g.period_start, g.id") as $g) {
        $items[] = ['key' => 'chg:' . $g['id'], 'kind' => $g['source_kind'], 'client_id' => $g['client_id'], 'client_name' => $g['client_name'], 'label' => $g['task_title'] ?: $g['description'],
            'description' => (string)$g['description'], 'period_start' => $g['period_start'], 'period_end' => $g['period_end'], 'amount' => (float)$g['amount_ex_gst'],
            'account' => $g['xero_account'] ?: cm_setting($pdo, 'xero_account_default'), 'overdue' => false, 'note' => $g['source_kind'] === 'progress' ? 'Progress claim' : 'Month-end claim', 'url' => in_array($g['source_kind'], ['claim', 'progress'], true) ? route('task', ['id' => $g['source_id']]) : route('invoicing')];
    }
    // 5. Received materials not yet charged (v0.14)
    if (function_exists('cm_has_materials') && cm_has_materials($pdo)) foreach (allRows($pdo, "SELECT i.*, t.title task_title, COALESCE(i.client_id,t.client_id) cid, c.name client_name FROM job_items i JOIN tasks t ON t.id=i.task_id LEFT JOIN clients c ON c.id=COALESCE(i.client_id,t.client_id)
        WHERE i.status='received' AND NOT EXISTS (SELECT 1 FROM charges g WHERE g.source_kind='material' AND g.source_id=i.id AND g.status<>'void') ORDER BY i.received_on, i.id") as $m) {
        $items[] = ['key' => 'mat:' . $m['id'], 'kind' => 'material', 'client_id' => $m['cid'], 'client_name' => $m['client_name'], 'label' => $m['description'],
            'description' => $m['description'] . ((float)$m['quantity'] != 1 ? ' (' . cm_qty($m['quantity']) . ' × ' . cm_money($m['unit_sell_ex_gst']) . ')' : '') . ' - ' . $m['task_title'], 'period_start' => $m['received_on'], 'period_end' => null,
            'amount' => round((float)$m['quantity'] * (float)$m['unit_sell_ex_gst'], 2), 'account' => cm_setting($pdo, 'xero_account_default'), 'overdue' => false, 'note' => 'Received ' . niceDate($m['received_on']), 'url' => route('task', ['id' => $m['task_id']]) . '#materials'];
    }
    return $items;
}

/** Charges already recorded for one source. */
function cm_charges_for(PDO $pdo, string $kind, int $id): array
{
    return allRows($pdo, 'SELECT * FROM charges WHERE source_kind=? AND source_id=? ORDER BY period_start DESC, id DESC', [$kind, $id]);
}


/**
 * v0.18: the Xero invoice Reference for a set of charges — what the client will recognise:
 * a task's purchase order no (else job no, else title), a service's domain, a milestone's / material's task.
 * Distinct values joined with ", ", at most 255 characters.
 */
function cm_charges_reference(PDO $pdo, array $chargeIds): string
{
    if (!$chargeIds) return '';
    $refs = [];
    $taskRef = function (int $tid) use ($pdo): string { $t = oneRow($pdo, 'SELECT title, job_number, purchase_order_no FROM tasks WHERE id=?', [$tid]); return $t ? (trim((string)$t['purchase_order_no']) !== '' ? 'PO ' . trim((string)$t['purchase_order_no']) : (trim((string)$t['job_number']) !== '' ? 'Job ' . trim((string)$t['job_number']) : (string)$t['title'])) : ''; };
    foreach (allRows($pdo, 'SELECT source_kind, source_id FROM charges WHERE id IN (' . implode(',', array_map('intval', $chargeIds)) . ') ORDER BY id') as $g) {
        $id = (int)$g['source_id'];
        switch ($g['source_kind']) {
            case 'service': $r = (string)(oneRow($pdo, 'SELECT COALESCE(domain,label) n FROM services WHERE id=?', [$id])['n'] ?? ''); break;
            case 'material': $r = $taskRef((int)(oneRow($pdo, 'SELECT task_id FROM job_items WHERE id=?', [$id])['task_id'] ?? 0)); break;
            case 'milestone': $m = oneRow($pdo, 'SELECT task_id, enquiry_id FROM billing_milestones WHERE id=?', [$id]); $r = $m && $m['task_id'] ? $taskRef((int)$m['task_id']) : ($m ? (string)(oneRow($pdo, 'SELECT title FROM enquiries WHERE id=?', [(int)$m['enquiry_id']])['title'] ?? '') : ''); break;
            default: $r = $taskRef($id);   // task_fee, claim, progress: source_id is the task
        }
        if (($r = trim($r)) !== '') $refs[$r] = true;
    }
    return mb_substr(implode(', ', array_keys($refs)), 0, 255);
}
