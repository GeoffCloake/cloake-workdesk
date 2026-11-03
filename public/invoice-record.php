<?php
declare(strict_types=1);
/*
 * Record a Xero invoice against ticked Pending Invoicing lines (v0.13). POST only.
 *   keys[]          svc:<service>:<period start> | ms:<milestone> | fee:<task> | mat:<material> | chg:<pending charge>  — all one client
 *   invoice_number  the Xero number (e.g. INV-1234), recorded_by (typed name), amount[<key>] for task fees (override)
 * One charges row per line, status invoiced, with the number, who and when. A service moves on to its next period;
 * a task fee ties the task's unbilled hours to the charge. One audit event (invoice_recorded) — undoable: it removes
 * those charges, puts claims back to pending, and puts the services' previous dates back (if unchanged since).
 * Nothing is sent to Xero.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . route('invoicing'), true, 303); exit; }
m7verify();
$keys = array_values(array_unique(array_filter(array_map('strval', is_array($_POST['keys'] ?? null) ? $_POST['keys'] : []))));
$amounts = is_array($_POST['amount'] ?? null) ? $_POST['amount'] : [];
$back = route('invoicing');
try {
    $inv = cm_post('invoice_number', 40); $by = cm_post('recorded_by', 100);
    if (!$keys) throw new InvalidArgumentException('Tick the lines that are on this Xero invoice.');
    if ($inv === '') throw new InvalidArgumentException('Enter the Xero invoice number.');
    if ($by === '') throw new InvalidArgumentException('Enter your name (who recorded it).');
    $pdo->beginTransaction();
    $created = []; $claims = []; $svcDates = []; $work = []; $client = null; $total = 0.0; $lines = 0;
    $now = date('Y-m-d H:i:s');
    $one = function ($cid) use (&$client) {
        if ($client === null) { $client = (int)$cid; return; }
        if ((int)$cid !== $client) throw new InvalidArgumentException('Those lines belong to different clients. One Xero invoice is for one client.');
    };
    $insert = function (array $c) use ($pdo, &$created, $inv, $by, $now) {
        $pdo->prepare("INSERT INTO charges (client_id,source_kind,source_id,period_start,period_end,description,amount_ex_gst,xero_account,status,xero_invoice_number,recorded_by,recorded_at,origin,created_at) VALUES (?,?,?,?,?,?,?,?,'invoiced',?,?,?,'manager',NOW())")
            ->execute([$c['client_id'], $c['kind'], $c['id'], $c['start'], $c['end'], $c['description'], $c['amount'], $c['account'] ?: null, $inv, $by, $now]);
        $created[] = (int)$pdo->lastInsertId();
    };
    foreach ($keys as $k) {
        if (preg_match('/^svc:(\d+):(\d{4}-\d{2}-\d{2})$/', $k, $m)) {
            $s = oneRow($pdo, 'SELECT * FROM services WHERE id=? FOR UPDATE', [(int)$m[1]]);
            if (!$s) throw new InvalidArgumentException('A ticked service no longer exists. Reload the page.');
            if ((string)$s['next_period_start'] !== $m[2]) throw new InvalidArgumentException(($s['domain'] ?: $s['label']) . ': that period has already been recorded or its date changed. Reload the page.');
            if (!$s['billable'] || $s['is_internal'] || !$s['cycle_months']) throw new InvalidArgumentException(($s['domain'] ?: $s['label']) . ' is not billed.');
            $one($s['client_id']);
            $end = cm_period_end($m[2], (int)$s['cycle_months']);
            $insert(['client_id' => $s['client_id'], 'kind' => 'service', 'id' => (int)$s['id'], 'start' => $m[2], 'end' => $end, 'description' => cm_service_description($s, $m[2], $end), 'amount' => $s['price_ex_gst'], 'account' => $s['xero_account'] ?: cm_account_for($pdo, $s['kind'])]);
            $next = cm_add_months($m[2], (int)$s['cycle_months']);
            $pdo->prepare('UPDATE services SET next_period_start=?, updated_at=NOW() WHERE id=?')->execute([$next, (int)$s['id']]);
            $svcDates[(int)$s['id']] = ['previous' => $m[2], 'current' => $next];
            $total += (float)$s['price_ex_gst'];
        } elseif (preg_match('/^ms:(\d+)$/', $k, $m)) {
            $ms = oneRow($pdo, 'SELECT * FROM billing_milestones WHERE id=? FOR UPDATE', [(int)$m[1]]);
            if (!$ms || $ms['status'] === 'void') throw new InvalidArgumentException('A ticked milestone is no longer available. Reload the page.');
            if (cm_ms_state($pdo, $ms)['state'] !== 'ready') throw new InvalidArgumentException('Milestone “' . $ms['label'] . '” is not ready to invoice.');
            if (oneRow($pdo, "SELECT id FROM charges WHERE source_kind='milestone' AND source_id=? AND status<>'void'", [(int)$ms['id']])) throw new InvalidArgumentException('Milestone “' . $ms['label'] . '” has already been invoiced.');
            $o = cm_owner($pdo, $ms['enquiry_id'] ? 'enquiry' : 'task', (int)($ms['enquiry_id'] ?: $ms['task_id']));
            $one($o['client_id']);
            $amt = cm_ms_amount($ms, cm_price_lines($pdo, $o));
            $insert(['client_id' => $o['client_id'], 'kind' => 'milestone', 'id' => (int)$ms['id'], 'start' => substr((string)$ms['created_at'], 0, 10), 'end' => null, 'description' => $ms['label'] . ' - ' . ($o['row']['title'] ?? ''), 'amount' => $amt, 'account' => cm_setting($pdo, 'xero_account_default')]);
            $total += $amt;
        } elseif (preg_match('/^fee:(\d+)$/', $k, $m)) {
            $t = oneRow($pdo, 'SELECT * FROM tasks WHERE id=? FOR UPDATE', [(int)$m[1]]);
            if (!$t) throw new InvalidArgumentException('A ticked task no longer exists.');
            if (oneRow($pdo, "SELECT id FROM charges WHERE source_kind='task_fee' AND source_id=? AND status<>'void'", [(int)$t['id']])) throw new InvalidArgumentException('Task “' . $t['title'] . '” has already been invoiced.');
            $one($t['client_id']);
            $a = str_replace([',', '$'], '', trim((string)($amounts[$k] ?? '')));
            if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $a)) throw new InvalidArgumentException('Enter the amount ex GST for “' . $t['title'] . '”.');
            $insert(['client_id' => $t['client_id'], 'kind' => 'task_fee', 'id' => (int)$t['id'], 'start' => substr((string)($t['signed_off_at'] ?: $t['completed_at'] ?: date('Y-m-d')), 0, 10), 'end' => null, 'description' => $t['title'], 'amount' => $a, 'account' => cm_setting($pdo, 'xero_account_default')]);
            $cid = end($created);
            $ids = array_column(allRows($pdo, "SELECT id FROM work_log WHERE task_id=? AND charge_id IS NULL AND billability='billable' AND billable_seconds>0 AND (source_system IS NULL OR source_system<>'fabrik') FOR UPDATE", [(int)$t['id']]), 'id');
            if ($ids) { $pdo->prepare('UPDATE work_log SET charge_id=? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute([$cid]); $work = array_merge($work, array_map('intval', $ids)); }
            $total += (float)$a;
        } elseif (preg_match('/^mat:(\d+)$/', $k, $m)) {
            $it = oneRow($pdo, 'SELECT i.*, t.title task_title, t.client_id task_client FROM job_items i JOIN tasks t ON t.id=i.task_id WHERE i.id=? FOR UPDATE', [(int)$m[1]]);
            if (!$it || $it['status'] !== 'received') throw new InvalidArgumentException('A ticked material is no longer received. Reload the page.');
            if (oneRow($pdo, "SELECT id FROM charges WHERE source_kind='material' AND source_id=? AND status<>'void'", [(int)$it['id']])) throw new InvalidArgumentException('Material “' . $it['description'] . '” has already been invoiced.');
            $cid = $it['client_id'] ?: $it['task_client']; $one($cid);
            $amt = round((float)$it['quantity'] * (float)$it['unit_sell_ex_gst'], 2);
            $insert(['client_id' => $cid, 'kind' => 'material', 'id' => (int)$it['id'], 'start' => (string)$it['received_on'], 'end' => null, 'description' => $it['description'] . ' - ' . $it['task_title'], 'amount' => $amt, 'account' => cm_setting($pdo, 'xero_account_default')]);
            $total += $amt;
        } elseif (preg_match('/^chg:(\d+)$/', $k, $m)) {
            $g = oneRow($pdo, 'SELECT * FROM charges WHERE id=? FOR UPDATE', [(int)$m[1]]);
            if (!$g || $g['status'] !== 'pending') throw new InvalidArgumentException('A ticked claim has already been recorded or removed. Reload the page.');
            $one($g['client_id']);
            $pdo->prepare("UPDATE charges SET status='invoiced', xero_invoice_number=?, recorded_by=?, recorded_at=?, updated_at=NOW() WHERE id=?")->execute([$inv, $by, $now, (int)$g['id']]);
            $claims[] = (int)$g['id']; $total += (float)$g['amount_ex_gst'];
        } else throw new InvalidArgumentException('Unknown line. Reload the page.');
        $lines++;
    }
    $cname = (string)(oneRow($pdo, 'SELECT name FROM clients WHERE id=?', [$client])['name'] ?? '');
    cm_audit($pdo, 'invoice_recorded', ['client_id' => $client], "Invoice $inv recorded for $cname by $by: $lines line" . ($lines === 1 ? '' : 's') . ', ' . cm_money($total) . ' ex GST',
        ['_invoice' => ['created' => $created, 'claims' => $claims, 'services' => $svcDates, 'work' => $work, 'invoice_number' => $inv]]);
    $_SESSION['cm_last_staff'] = $by;
    $pdo->commit();
    header('Location: ' . $back . '&recorded=' . rawurlencode($inv), true, 303); exit;
} catch (Throwable $e) {
    $_SESSION['cm_invoice_error'] = ['msg' => cm_edit_error($e, $pdo, 'invoice record'), 'keys' => $keys, 'inv' => (string)($_POST['invoice_number'] ?? '')];
    header('Location: ' . $back, true, 303); exit;
}
