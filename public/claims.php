<?php
declare(strict_types=1);
/*
 * Month-end claims (v0.13) for tasks billed monthly (billing_mode 'monthly').
 * For the chosen month each task shows its unbilled billable hours (× the hourly rate, editable) and, if it has
 * set-fee price lines, a progress claim (capped at what is left of the fee). "Create claims" makes PENDING charges
 * (claim = hours, progress = part of the set fee) that appear on Pending Invoicing; the hours are tied to the claim
 * so they are never billed twice. A pending claim can be removed (its hours are released). Audited.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$today = date('Y-m-d');
$defMonth = date('Y-m-t') === $today ? date('Y-m') : (new DateTimeImmutable('first day of last month'))->format('Y-m');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? $_POST['month'] ?? '')) ? (string)($_GET['month'] ?? $_POST['month']) : $defMonth;
$from = $month . '-01'; $to = (new DateTimeImmutable($from))->format('Y-m-t'); $label = (new DateTimeImmutable($from))->format('F Y');
$rate = (float)cm_setting($pdo, 'hourly_rate_ex_gst');
$error = ''; $made = 0;
$tasksSql = "SELECT t.*, c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.billing_mode='monthly' AND (t.completed_at IS NULL OR t.completed_at >= ?) ORDER BY c.name, t.title";
$setTotal = fn(int $tid) => (float)(oneRow($pdo, "SELECT COALESCE(SUM(quantity*unit_price_ex_gst),0) v FROM breakdown_items WHERE task_id=? AND pricing_mode<>'hourly'", [$tid])['v'] ?? 0);
$claimed = fn(int $tid) => (float)(oneRow($pdo, "SELECT COALESCE(SUM(amount_ex_gst),0) v FROM charges WHERE source_kind='progress' AND source_id=? AND status<>'void'", [$tid])['v'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? 'create');
    try {
        $pdo->beginTransaction();
        if ($action === 'remove') {
            $g = oneRow($pdo, "SELECT * FROM charges WHERE id=? AND status='pending' AND source_kind IN ('claim','progress') FOR UPDATE", [(int)($_POST['charge_id'] ?? 0)]);
            if (!$g) throw new InvalidArgumentException('That claim has already been invoiced or removed.');
            $pdo->prepare('UPDATE work_log SET charge_id=NULL WHERE charge_id=?')->execute([(int)$g['id']]);
            $pdo->prepare('DELETE FROM charges WHERE id=?')->execute([(int)$g['id']]);
            cm_audit($pdo, 'claim_removed', ['client_id' => $g['client_id'], 'task_id' => $g['source_id']], 'Month-end claim removed: ' . $g['description'], ['charge' => $g]);
        } else {
            $by = cm_post('created_by', 100); if ($by === '') throw new InvalidArgumentException('Enter your name.');
            foreach (allRows($pdo, $tasksSql, [$from . ' 00:00:00']) as $t) {
                $tid = (int)$t['id'];
                $hrs = trim((string)($_POST['hours'][$tid] ?? '')); $prog = trim((string)($_POST['progress'][$tid] ?? ''));
                foreach (['hours' => $hrs, 'progress' => $prog] as $k => $v) if ($v !== '' && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', str_replace([',', '$'], '', $v))) throw new InvalidArgumentException("“{$t['title']}”: the amount must be dollars ex GST.");
                $hrs = (float)str_replace([',', '$'], '', $hrs); $prog = (float)str_replace([',', '$'], '', $prog);
                if ($hrs > 0) {
                    if (oneRow($pdo, "SELECT id FROM charges WHERE source_kind='claim' AND source_id=? AND period_start=?", [$tid, $from])) throw new InvalidArgumentException("“{$t['title']}” already has an hours claim for $label.");
                    $h = cm_task_unbilled_hours($pdo, $tid, $from, $to);
                    $pdo->prepare("INSERT INTO charges (client_id,source_kind,source_id,period_start,period_end,description,amount_ex_gst,xero_account,status,recorded_by,origin,created_at) VALUES (?,'claim',?,?,?,?,?,?,'pending',?,'manager',NOW())")
                        ->execute([$t['client_id'], $tid, $from, $to, $t['title'] . " — $label: " . dur($h['seconds']) . ' billable', $hrs, cm_setting($pdo, 'xero_account_default') ?: null, $by]);
                    $cid = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE work_log SET charge_id=? WHERE task_id=? AND charge_id IS NULL AND billability='billable' AND billable_seconds>0 AND (source_system IS NULL OR source_system<>'fabrik') AND COALESCE(work_date, DATE(started_at)) BETWEEN ? AND ?")->execute([$cid, $tid, $from, $to]);
                    cm_audit($pdo, 'claim_created', ['client_id' => $t['client_id'], 'task_id' => $tid], "Month-end claim ($label) for {$t['title']}: " . cm_money($hrs), ['charge_id' => $cid]);
                    $made++;
                }
                if ($prog > 0) {
                    $left = round($setTotal($tid) - $claimed($tid), 2);
                    if ($prog > $left + 0.001) throw new InvalidArgumentException("“{$t['title']}”: the progress claim " . cm_money($prog) . ' is more than what is left of the fee (' . cm_money($left) . ').');
                    if (oneRow($pdo, "SELECT id FROM charges WHERE source_kind='progress' AND source_id=? AND period_start=?", [$tid, $from])) throw new InvalidArgumentException("“{$t['title']}” already has a progress claim for $label.");
                    $pdo->prepare("INSERT INTO charges (client_id,source_kind,source_id,period_start,period_end,description,amount_ex_gst,xero_account,status,recorded_by,origin,created_at) VALUES (?,'progress',?,?,?,?,?,?,'pending',?,'manager',NOW())")
                        ->execute([$t['client_id'], $tid, $from, $to, $t['title'] . " — progress claim $label (" . cm_money($claimed($tid) + $prog) . ' of ' . cm_money($setTotal($tid)) . ')', $prog, cm_setting($pdo, 'xero_account_default') ?: null, $by]);
                    cm_audit($pdo, 'claim_created', ['client_id' => $t['client_id'], 'task_id' => $tid], "Progress claim ($label) for {$t['title']}: " . cm_money($prog), ['charge_id' => (int)$pdo->lastInsertId()]);
                    $made++;
                }
            }
            if (!$made) throw new InvalidArgumentException('Enter at least one amount to claim.');
            $_SESSION['cm_last_staff'] = $by;
        }
        $pdo->commit();
        header('Location: claims.php?month=' . $month . '&done=' . ($action === 'remove' ? 'removed' : $made), true, 303); exit;
    } catch (Throwable $e) { $error = cm_edit_error($e, $pdo, 'claims ' . $month); }
}
$tasks = allRows($pdo, $tasksSql, [$from . ' 00:00:00']);
$pending = allRows($pdo, "SELECT g.*, t.title FROM charges g LEFT JOIN tasks t ON t.id=g.source_id WHERE g.source_kind IN ('claim','progress') AND g.period_start=? ORDER BY g.id", [$from]);
cm_page_start('Month-end claims', 'claims', ['back' => [route('invoicing'), 'Pending Invoicing'], 'bodyClass' => 'form-page']);
?>
<form method="get" class="row-inline"><label for="month">Month</label><input type="month" id="month" name="month" value="<?=h($month)?>"><button type="submit" class="secondary">Show</button></form>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (isset($_GET['done'])): ?><div class="success" role="status"><?=$_GET['done'] === 'removed' ? 'Claim removed; its hours are free to claim again.' : (int)$_GET['done'] . ' claim(s) created. They are on Pending Invoicing.'?></div><?php endif; ?>
<?php if (!$rate): ?><p class="notice">No hourly rate is set, so hours aren't priced. Set it in <a href="billing-settings.php">Billing settings</a>, or type the amounts.</p><?php endif; ?>
<?php if (!$tasks): ?><p class="emptyline">No tasks are billed monthly. Choose “Monthly” under Price &amp; billing on a task.</p><?php else: ?>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="month" value="<?=h($month)?>"><input type="hidden" name="action" value="create">
<div class="stack" data-testid="claim-tasks"><?php foreach ($tasks as $t): $tid = (int)$t['id']; $h = cm_task_unbilled_hours($pdo, $tid, $from, $to); $fee = $setTotal($tid); $left = round($fee - $claimed($tid), 2); ?>
<div class="panel"><h2 class="panel-title"><a href="<?=h(route('task', ['id' => $tid]))?>"><?=h($t['title'])?></a> <small class="muted"><?=h($t['client_name'] ?? '')?></small></h2>
<div class="pair"><div class="field"><label for="h<?=$tid?>">Hours in <?=h($label)?>: <?=h(dur($h['seconds']))?> (<?=$h['entries']?>)</label><input id="h<?=$tid?>" name="hours[<?=$tid?>]" inputmode="decimal" placeholder="0.00" value="<?=$h['seconds'] && $rate ? h(number_format($h['seconds'] / 3600 * $rate, 2, '.', '')) : ''?>"></div>
<div class="field"><label for="p<?=$tid?>">Progress claim on the fee<?=$fee ? ' (' . h(cm_money($left)) . ' left of ' . h(cm_money($fee)) . ')' : ''?></label><input id="p<?=$tid?>" name="progress[<?=$tid?>]" inputmode="decimal" placeholder="<?=$fee ? '0.00' : 'no set fee'?>"<?=$fee ? '' : ' disabled'?>></div></div></div>
<?php endforeach; ?></div>
<div class="field"><label for="created_by">Your name</label><input id="created_by" name="created_by" maxlength="100" required value="<?=h((string)($_SESSION['cm_last_staff'] ?? ''))?>"></div>
<div class="form-actions"><button type="submit" class="save">Create claims for <?=h($label)?></button><a class="cancel" href="<?=h(route('invoicing'))?>">Cancel</a></div></form>
<?php endif; ?>
<?php if ($pending): ?><h2>Claims for <?=h($label)?></h2><div class="stack compact-list"><?php foreach ($pending as $g): ?><div class="record"><span><strong><?=h($g['description'])?></strong><small><?=h(ucfirst($g['status']))?><?=$g['xero_invoice_number'] ? ' · ' . h($g['xero_invoice_number']) : ''?></small></span><span class="end"><?=h(cm_money($g['amount_ex_gst']))?><?php if ($g['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="month" value="<?=h($month)?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="charge_id" value="<?=(int)$g['id']?>"><button type="submit" class="mini">Remove</button></form><?php endif; ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php cm_page_end('claims');
