<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* One service (v0.13): details, next period, invoiced periods (manager + Xero history), the original WHMCS row (read-only). */
$pageOpt = ['back' => [route('services'), 'Services'], 'h1' => false];
$s = oneRow($pdo, 'SELECT s.*, c.name client_name FROM services s LEFT JOIN clients c ON c.id=s.client_id WHERE s.id=?', [$id]);
if (!$s) { http_response_code(404); echo '<p class="emptyline">Service not found. <a href="' . h(route('services')) . '">Back to services</a></p>'; return; }
$pageTitle = $s['domain'] ?: $s['label'] ?: 'Service';
$charges = cm_charges_for($pdo, 'service', $id);
$arch = $s['source_system'] === 'whmcs' && $s['source_id'] ? oneRow($pdo, 'SELECT * FROM archive_whmcs WHERE source_table=? AND source_id=?', [str_starts_with((string)$s['source_id'], 'D') ? 'domains' : 'services', substr((string)$s['source_id'], 1)]) : null;
$billed = $s['billable'] && !$s['is_internal'];
$late = $billed && $s['next_period_start'] && $s['next_period_start'] < $today;
$horizon = (new DateTimeImmutable($today))->modify('+' . max(0, (int)cm_setting($pdo, 'lead_weeks')) . ' weeks')->format('Y-m-d');
$err = ($_SESSION['cm_service_error']['id'] ?? 0) === $id ? (string)$_SESSION['cm_service_error']['msg'] : ''; unset($_SESSION['cm_service_error']);
?>
<header class="client-head"><h1><?=h($pageTitle)?></h1>
<p class="client-sub"><?php if ($s['client_id']): ?><a href="<?=h(route('client', ['id' => $s['client_id']]))?>"><?=h($s['client_name'])?></a><?php else: ?>No client<?php endif; ?> · <?=h(CM_SERVICE_KINDS[$s['kind']] ?? $s['kind'])?><?=$s['source_id'] ? ' · WHMCS ' . h($s['source_id']) : ''?></p></header>
<?php if (($_GET['saved'] ?? null) === '1' && !cm_undo_pending()): ?><div class="success" role="status">Saved.</div><?php endif; ?>
<?php if ($err): ?><div class="alert" role="alert"><?=h($err)?></div><?php endif; ?>
<nav class="client-actions two"><a class="ca" href="service-edit.php?id=<?=(int)$id?>">Edit service</a><a class="ca<?=$billed && $s['next_period_start'] && $s['next_period_start'] <= $horizon ? ' primary' : ''?>" href="<?=h(route('invoicing'))?>#client-<?=(int)$s['client_id']?>">Pending Invoicing</a></nav>
<div class="client-summary">
  <div><small>Status</small><strong><?=h(CM_SERVICE_STATUSES[$s['status']] ?? $s['status'])?></strong></div>
  <div><small>Price ex GST</small><strong><?=$s['is_internal'] ? 'Internal' : (!$s['billable'] ? 'No charge' : ($s['price_ex_gst'] !== null ? h(cm_money($s['price_ex_gst'])) : '—'))?></strong></div>
  <div><small>Next period</small><strong class="<?=$late ? 'late' : ''?>" data-testid="next-period"><?=$billed && $s['next_period_start'] ? h(niceDate($s['next_period_start'])) : '—'?></strong></div>
</div>
<?php if ($s['check_status'] && $s['check_status'] !== 'agrees'): ?><p class="notice"><strong><?=h(CM_CHECK_STATUSES[$s['check_status']] ?? $s['check_status'])?>.</strong> <?=h((string)$s['check_note'])?></p><?php endif; ?>
<dl class="facts task-facts">
  <dt>Billing</dt><dd><?=h(CM_CYCLES[(string)$s['cycle_months']] ?? '—')?><?=$s['xero_account'] ? ' · Xero account ' . h($s['xero_account']) : ''?></dd>
  <?php if ($s['plan_name']): ?><dt>Plan</dt><dd><?=h($s['plan_name'])?></dd><?php endif; ?>
  <?php if ($s['expiry_date']): ?><dt>Domain expiry</dt><dd><?=h(niceDate($s['expiry_date']))?><?=$s['registrar'] ? ' · ' . h($s['registrar']) : ''?></dd><?php endif; ?>
  <?php if ($s['cpanel_username']): ?><dt>cPanel user</dt><dd><?=h($s['cpanel_username'])?></dd><?php endif; ?>
  <?php if ($s['check_status'] === 'agrees'): ?><dt>Check</dt><dd><?=h((string)$s['check_note'] ?: 'Agrees with Xero')?></dd><?php endif; ?>
  <?php if ($s['notes']): ?><dt>Notes</dt><dd><?=h($s['notes'])?></dd><?php endif; ?>
</dl>
<?php if ($billed && $s['next_period_start'] && $s['cycle_months']): $end = cm_period_end((string)$s['next_period_start'], (int)$s['cycle_months']); ?>
<div class="panel"><h2 class="panel-title">Next invoice</h2><p class="prewrap small"><?=h(cm_service_description($s, (string)$s['next_period_start'], $end))?></p>
<p class="small muted"><?=h(cm_money($s['price_ex_gst']))?> ex GST · <?=h(cm_money(cm_incl($pdo, (float)$s['price_ex_gst'])))?> incl GST</p>
<details><summary>Correct the next period date</summary><form method="post" action="service-edit.php" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="correct_date">
<div class="pair"><div class="field"><label for="np">Next period starts</label><input type="date" id="np" name="next_period_start" required value="<?=h((string)$s['next_period_start'])?>"></div><div class="field"><label for="why">Reason</label><input id="why" name="reason" maxlength="255" required placeholder="e.g. Xero invoice CC-5160 covers to 12/09"></div></div>
<div class="form-actions"><button type="submit" class="secondary">Save date</button></div></form></details></div>
<?php endif; ?>
<div class="section-heading"><h2>Invoiced periods <small><?=count($charges)?></small></h2></div>
<?php if (!$charges): ?><p class="emptyline">No invoices recorded for this service yet.</p><?php else: ?>
<div class="stack compact-list" data-testid="service-charges"><?php foreach ($charges as $g): ?><div class="record<?=$g['status'] === 'void' ? ' is-closed' : ''?>"><span><strong><?=h(niceDate($g['period_start']))?><?=$g['period_end'] ? ' – ' . h(niceDate($g['period_end'])) : ''?></strong><small><?=h(implode(' · ', array_filter([$g['xero_invoice_number'] ? 'Invoice ' . $g['xero_invoice_number'] : '', $g['status'] === 'void' ? 'Void: ' . $g['void_reason'] : '', $g['origin'] === 'xero_import' ? 'from Xero history' : ($g['recorded_by'] ? 'recorded by ' . $g['recorded_by'] : ''), $g['recorded_at'] ? niceDate($g['recorded_at']) : ''])))?></small></span><span class="end"><?=h(cm_money($g['amount_ex_gst']))?><small>ex GST</small></span></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($arch): $r = json_decode((string)$arch['source_record'], true) ?: []; ?>
<details data-testid="whmcs-record"><summary>Original WHMCS record (read-only)</summary><dl class="raw-record"><?php foreach ($r as $k => $v): if ($v === '' || $v === null) continue; ?><dt><?=h($k)?></dt><dd><?=h(is_scalar($v) ? (string)$v : json_encode($v))?></dd><?php endforeach; ?></dl>
<p class="muted small">Archived as imported<?=$arch['removed'] ? ' (removed for safety: ' . h($arch['removed']) . ')' : ''?>. Fingerprint <?=h(substr((string)$arch['row_sha256'], 0, 12))?>…</p></details>
<?php endif; ?>
