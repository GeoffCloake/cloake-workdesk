<?php
declare(strict_types=1);
/*
 * Invoices (v0.22) — read live from Xero, nothing stored, nothing changed in Xero.
 *   invoices.php              everything awaiting payment (overdue first), with the Workdesk client where linked
 *   invoices.php?client=ID    one client's Xero invoices (all statuses, newest first) + their WHMCS history link
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$x = cm_xero_load();
$cid = filter_var($_GET['client'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$client = $cid ? oneRow($pdo, 'SELECT * FROM clients WHERE id=?', [$cid]) : null;
if ($cid && !$client) { http_response_code(404); exit('Client not found.'); }
$today = date('Y-m-d');
cm_page_start($client ? 'Invoices — ' . $client['name'] : 'Invoices', 'billing', ['back' => $client ? [route('client', ['id' => $cid]), 'Client'] : [route('more'), 'More']]);
if (cm_xero_state($x) !== 'connected') { echo '<div class="notice">Xero is not connected. <a href="xero.php">Set up the Xero connection</a> to see invoices here.</div>'; cm_page_end('billing'); exit; }
?>
<p class="small muted">Live from Xero: <strong><?=h((string)$x['tenant_name'])?></strong><?=cm_xero_is_demo($x) ? ' <em class="tag">Demo Company — test data</em>' : ''?>. Read only — open an invoice in Xero to change it.</p>
<?php
try {
    if ($client) {
        $xid = cm_client_xero_id($client);
        if ($xid === '') { echo '<div class="notice">This client is not linked to a Xero contact yet. <a href="xero-sync.php">Link it on Xero sync</a>.</div>'; cm_page_end('billing'); exit; }
        $list = cm_xero_invoices($x, [], $xid, 3);
    } else $list = cm_xero_invoices($x, ['AUTHORISED']);
} catch (Throwable $e) { echo '<div class="alert" role="alert">' . h($e->getMessage()) . '</div>'; cm_page_end('billing'); exit; }
$byXero = []; foreach (allRows($pdo, "SELECT id, name, xero_contact_id FROM clients WHERE xero_contact_id IS NOT NULL") as $r) { $g = cm_client_xero_id($r); if ($g !== '') $byXero[$g] = $r; }
$open = array_filter($list, fn($i) => $i['status'] === 'AUTHORISED' && $i['due_amount'] > 0);
$over = array_filter($open, fn($i) => $i['due'] !== '' && $i['due'] < $today);
$key = fn($i) => [$i['due'] !== '' && $i['due'] < $today ? 0 : 1, $i['due']];
if (!$client) usort($list, fn($a, $b) => $key($a) <=> $key($b));   // overdue first, then by due date
$show = (string)($_GET['show'] ?? ''); $shown = $show === 'overdue' ? array_values($over) : $list;
$sum = fn(array $l) => array_sum(array_column($l, 'due_amount'));
?>
<div class="client-summary" data-testid="inv-summary">
  <div><small>Awaiting payment</small><strong><?=count($open)?> · <?=h(cm_money($sum($open)))?></strong></div>
  <div><small>Overdue</small><strong class="<?=$over ? 'late' : ''?>"><?=count($over)?> · <?=h(cm_money($sum($over)))?></strong></div>
</div>
<?php if (!$client): ?><div class="chips"><a class="chip<?=$show === '' ? ' on' : ''?>" href="invoices.php">All awaiting payment</a><a class="chip<?=$show === 'overdue' ? ' on' : ''?>" href="invoices.php?show=overdue">Overdue (<?=count($over)?>)</a></div><?php endif; ?>
<?php if (!$shown): ?><p class="emptyline">No invoices.</p><?php endif; ?>
<ul class="plan-list" data-testid="inv-list"><?php foreach ($shown as $i): $late = $i['status'] === 'AUTHORISED' && $i['due_amount'] > 0 && $i['due'] < $today; $wc = $byXero[$i['contact_id']] ?? null;
  $st = ['AUTHORISED' => $i['due_amount'] > 0 ? 'awaiting payment' : 'paid', 'PAID' => 'paid', 'DRAFT' => 'draft', 'SUBMITTED' => 'awaiting approval'][$i['status']] ?? strtolower($i['status']); ?>
<li data-invoice="<?=h($i['number'])?>" class="<?=$late ? 'is-late' : ''?>"><span class="pl-main"><strong><a href="<?=h($i['url'])?>" rel="noopener" target="_blank"><?=h($i['number'] ?: 'draft')?></a></strong> <?=!$client ? ($wc ? '<a href="' . h(route('client', ['id' => $wc['id']])) . '">' . h($wc['name']) . '</a>' : h($i['contact']) . ' <small class="muted">(not linked)</small>') : ''?>
<small><?=h(implode(' · ', array_filter([$i['date'] ? niceDate($i['date']) : '', $i['reference'], cm_money($i['total']) . ' incl GST', $st, $i['due_amount'] > 0 && $i['status'] === 'AUTHORISED' ? cm_money($i['due_amount']) . ' due ' . ($i['due'] ? niceDate($i['due']) : '') : ''])))?><?=$late ? ' · <span class="late">overdue</span>' : ''?></small></span></li>
<?php endforeach; ?></ul>
<?php if ($client): ?><p class="helper">Older WHMCS invoices are on the client page under Invoices › WHMCS invoices.</p><?php endif; ?>
<?php cm_page_end('billing');
