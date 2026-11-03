<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Pending Invoicing (v0.13) — the list Cathy works from in Xero. Everything due, grouped by client:
 * service renewals (due within the lead time, or overdue), ready milestones, completed tasks to bill, month-end claims.
 * Per client: tick the lines on one Xero invoice, enter its number and your name → invoice-record.php.
 * Nothing is sent to Xero. Older billing evidence (imported task flags) stays below, unchanged.
 */
$hasBilling = (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charges'");
$lead = (int)cm_setting($pdo, 'lead_weeks');
$items = $hasBilling ? cm_due_items($pdo) : [];
$byClient = [];
foreach ($items as $it) $byClient[(int)$it['client_id']][] = $it;
uasort($byClient, function ($a, $b) {
    $oa = min(array_map(fn($x) => $x['overdue'] ? 0 : 1, $a)); $ob = min(array_map(fn($x) => $x['overdue'] ? 0 : 1, $b));
    return [$oa, min(array_column($a, 'period_start')), $a[0]['client_name']] <=> [$ob, min(array_column($b, 'period_start')), $b[0]['client_name']];
});
$sum = array_sum(array_column($items, 'amount')); $overdue = array_filter($items, fn($x) => $x['overdue']);
$err = $_SESSION['cm_invoice_error'] ?? null; unset($_SESSION['cm_invoice_error']);
$recent = $hasBilling ? allRows($pdo, "SELECT g.xero_invoice_number, g.recorded_by, MAX(g.recorded_at) at, COUNT(*) n, SUM(g.amount_ex_gst) total, c.name client_name FROM charges g LEFT JOIN clients c ON c.id=g.client_id
    WHERE g.origin='manager' AND g.status='invoiced' GROUP BY g.xero_invoice_number, g.recorded_by, c.name ORDER BY at DESC LIMIT 15") : [];
$kindTag = ['service' => 'Renewal', 'milestone' => 'Milestone', 'task_fee' => 'Task', 'claim' => 'Month-end claim', 'material' => 'Material', 'progress' => 'Progress claim', 'manual' => 'Charge'];
?>
<p class="lead">Invoice these in Xero, then record the Xero invoice number here. Renewals show <?=$lead?> weeks before their period starts. Amounts are ex GST.</p>
<?php $xeroOn = cm_xero_state() === 'connected'; $xeroName = $xeroOn ? (string)(cm_xero_load()['tenant_name'] ?? '') : ''; ?>
<?php if (isset($_GET['recorded']) && !empty($_GET['draft'])): ?><div class="success" role="status" data-testid="draft-made">Draft <?=h($_GET['recorded'])?> created in Xero (<?=h($xeroName)?>) and recorded here. Cathy: review and approve it in Xero › Invoices › Draft.</div>
<?php elseif (isset($_GET['recorded']) && !cm_undo_pending()): ?><div class="success" role="status">Invoice <?=h($_GET['recorded'])?> recorded.</div><?php endif; ?>
<?php if ($xeroOn): ?><p class="small muted" data-testid="xero-on">Xero: connected to <strong><?=h($xeroName)?></strong><?=cm_xero_is_demo(cm_xero_load()) ? ' (Demo Company — test)' : ''?>. <a href="xero.php">Xero connection</a></p><?php endif; ?>
<?php if ($err): ?><div class="alert" role="alert"><?=h($err['msg'])?></div><?php endif; ?>
<?php if (!$hasBilling): ?><div class="notice">Billing tables are not installed yet (migration 005).</div><?php endif; ?>
<div class="client-summary" data-testid="pending-summary">
  <div><small>To invoice</small><strong><?=count($items)?></strong></div>
  <div><small>Overdue</small><strong class="<?=$overdue ? 'late' : ''?>"><?=count($overdue)?></strong></div>
  <div><small>Total ex GST</small><strong><?=h(cm_money($sum))?></strong></div>
</div>
<?php if ($hasBilling && !$items): ?><p class="emptyline">Nothing to invoice right now.</p><?php endif; ?>
<?php foreach ($byClient as $cid => $list): $ctot = array_sum(array_column($list, 'amount')); $late = array_filter($list, fn($x) => $x['overdue']); ?>
<section class="panel invoice-client" id="client-<?=(int)$cid?>" data-testid="invoice-client">
<h2 class="panel-title"><?php if ($cid): ?><a href="<?=h(route('client', ['id' => $cid]))?>"><?=h($list[0]['client_name'])?></a><?php else: ?>No client<?php endif; ?> <small class="muted"><?=count($list)?> · <?=h(cm_money($ctot))?> ex GST · <?=h(cm_money(cm_incl($pdo, $ctot)))?> incl<?=$late ? ' · <span class="late">' . count($late) . ' overdue</span>' : ''?></small></h2>
<form method="post" action="invoice-record.php" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<ul class="invoice-lines"><?php foreach ($list as $i => $it): $kid = 'k' . (int)$cid . '-' . $i; $checked = !$err || in_array($it['key'], $err['keys'] ?? [], true); ?>
<li class="invoice-line<?=$it['overdue'] ? ' is-late' : ''?>"><label class="il-check" for="<?=h($kid)?>"><input type="checkbox" id="<?=h($kid)?>" name="keys[]" value="<?=h($it['key'])?>"<?=$checked ? ' checked' : ''?>></label>
<div class="il-main"><span class="queue-kind"><?=h($kindTag[$it['kind']] ?? $it['kind'])?><?=$it['overdue'] ? ' · overdue since ' . h(niceDate($it['period_start'])) : ($it['kind'] === 'service' ? ' · from ' . h(niceDate($it['period_start'])) : '')?></span>
<div class="prewrap il-desc" id="d-<?=h($kid)?>"><?=h($it['description'])?></div>
<small class="muted"><?=$it['account'] ? 'Account ' . h($it['account']) . ' · ' : ''?>15% GST on Income<?=$it['note'] ? ' · ' . h($it['note']) : ''?> · <a href="<?=h($it['url'])?>">open</a></small>
<button type="button" class="mini" data-copy="d-<?=h($kid)?>">Copy description</button></div>
<div class="il-amt"><?php if (!empty($it['editable_amount'])): ?><label class="visually-hidden" for="a-<?=h($kid)?>">Amount ex GST</label><input id="a-<?=h($kid)?>" name="amount[<?=h($it['key'])?>]" inputmode="decimal" maxlength="14" value="<?=h(number_format((float)$it['amount'], 2, '.', ''))?>"><?php else: ?><strong><?=h(cm_money($it['amount']))?></strong><?php endif; ?><small><?=h(cm_money(cm_incl($pdo, (float)$it['amount'])))?> incl</small></div></li>
<?php endforeach; ?></ul>
<div class="pair"><div class="field"><label for="inv-<?=(int)$cid?>">Xero invoice number</label><input id="inv-<?=(int)$cid?>" name="invoice_number" maxlength="40" required placeholder="INV-0000" value="<?=h($err && $err['keys'] && in_array($list[0]['key'], $err['keys'], true) ? $err['inv'] : '')?>"></div>
<div class="field"><label for="by-<?=(int)$cid?>">Recorded by</label><input id="by-<?=(int)$cid?>" name="recorded_by" maxlength="100" required value="<?=h((string)($_SESSION['cm_last_staff'] ?? ''))?>"></div></div>
<div class="form-actions"><button type="submit" class="secondary">Record invoice for ticked lines</button><?php if ($xeroOn): ?><button type="submit" name="xero_draft" value="1" formnovalidate class="secondary" data-testid="xero-draft">Create Xero draft instead</button><?php endif; ?></div>
<?php if ($xeroOn): ?><p class="helper">“Create Xero draft” sends the ticked lines to Xero as one <strong>draft</strong> (no number needed — Xero gives it one). Only “Recorded by” is needed.</p><?php endif; ?></form></section>
<?php endforeach; ?>
<?php if ($recent): ?><details><summary>Recently recorded (<?=count($recent)?>)</summary><div class="stack compact-list"><?php foreach ($recent as $r): ?><div class="record"><span><strong><?=h($r['xero_invoice_number'])?> · <?=h($r['client_name'] ?? '')?></strong><small><?=(int)$r['n']?> line<?=(int)$r['n'] === 1 ? '' : 's'?> · <?=h($r['recorded_by'])?> · <?=h(niceDate($r['at']))?></small></span><span class="end"><?=h(cm_money($r['total']))?></span></div><?php endforeach; ?></div></details><?php endif; ?>
<?php
// ---- older billing evidence (unchanged from earlier versions) ----
$billingRows = allRows($pdo, "SELECT t.id,t.title,t.status,t.pricing,t.billing_advised,t.invoice_number,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.billing_advised=1 OR (t.invoice_number IS NOT NULL AND t.invoice_number<>'') ORDER BY t.id DESC LIMIT 100");
?>
<details><summary>Imported billing history and flags (<?=count($billingRows)?>)</summary><p class="muted small">Historical billing-advised flags and invoice references from the old task manager. They don't prove an invoice is unpaid or still needed.</p>
<div class="stack compact-list"><?php foreach ($billingRows as $r): ?><a class="record" href="<?=h(route('task', ['id' => $r['id']]))?>"><span><strong><?=h($r['title'])?></strong><small><?=h($r['client_name'] ?? 'Unassigned')?> · <?=h(cm_status_label($r['status']))?> · Billing advised: <?=!empty($r['billing_advised']) ? 'Yes' : 'No'?></small></span><span class="end">Invoice <?=h($r['invoice_number'] ?: '—')?> ›</span></a><?php endforeach; ?></div></details>
