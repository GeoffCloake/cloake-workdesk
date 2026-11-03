<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Review-only. Do not infer that historical task billing is still outstanding. No Xero calls. */
$billCounts = oneRow($pdo, "SELECT (SELECT COUNT(*) FROM tasks WHERE COALESCE(billing_advised,0)=1) flagged, (SELECT COUNT(*) FROM financial_documents) documents,
  (SELECT COUNT(*) FROM work_log WHERE billability='review' AND (source_system IS NULL OR source_system<>'fabrik')) review_new,
  (SELECT COUNT(*) FROM work_log WHERE billability='billable' AND (source_system IS NULL OR source_system<>'fabrik')) billable_new");
$billingRows = allRows($pdo, "SELECT t.id,t.title,t.status,t.pricing,t.agreed_fee_ex_gst,t.legacy_billing_amount_ex_gst,t.billing_advised,t.invoice_number,t.invoice_reference,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.billing_advised=1 OR (t.invoice_number IS NOT NULL AND t.invoice_number<>'') ORDER BY t.id DESC LIMIT 100");
$drafts = allRows($pdo, 'SELECT f.id,f.kind,f.local_status,f.xero_number,f.xero_status,f.created_at,c.name client_name FROM financial_documents f LEFT JOIN clients c ON c.id=f.client_id ORDER BY f.id DESC LIMIT 60');
// v0.12: progressive billing — milestones whose trigger has happened (read-only; invoicing arrives in v0.13).
$ready = [];
foreach (allRows($pdo, "SELECT m.*, COALESCE(e.client_id,t.client_id) client_id, c.name client_name, e.title enquiry_title, t.title task_title FROM billing_milestones m
    LEFT JOIN enquiries e ON e.id=m.enquiry_id LEFT JOIN tasks t ON t.id=m.task_id LEFT JOIN clients c ON c.id=COALESCE(e.client_id,t.client_id)
    WHERE m.status<>'void' AND (m.enquiry_id IS NOT NULL OR m.task_id IS NOT NULL) ORDER BY m.id") as $m) {
    $st = cm_ms_state($pdo, $m);
    if ($st['state'] !== 'ready') continue;
    $o = cm_owner($pdo, $m['enquiry_id'] ? 'enquiry' : 'task', (int)($m['enquiry_id'] ?: $m['task_id']));
    $m['amount'] = cm_ms_amount($m, cm_price_lines($pdo, $o)); $m['since'] = $st['since']; $m['url'] = $o['url'];
    $ready[] = $m;
}
?>
<p class="lead">Review billing evidence before preparing any invoice. No Xero actions are enabled.</p>
<section class="today-section" data-testid="ready-milestones"><div class="section-heading"><h2>Milestones ready to invoice <small><?=count($ready)?><?=$ready ? ' · ' . h(cm_money(array_sum(array_column($ready, 'amount')))) . ' ex GST' : ''?></small></h2></div>
<?php if (!$ready): ?><p class="emptyline">No milestones are ready. A milestone becomes ready when its enquiry is accepted, its task is signed off, its date arrives, or it is marked ready.</p><?php else: ?>
<div class="stack compact-list"><?php foreach ($ready as $m): ?><a class="record" href="<?=h($m['url'])?>#pricing"><span><strong><?=h($m['label'])?></strong><small><?=h(implode(' · ', array_filter([$m['client_name'] ?? 'Unassigned', $m['enquiry_title'] ? 'Enquiry #' . $m['enquiry_id'] . ': ' . $m['enquiry_title'] : ($m['task_title'] ? 'Task #' . $m['task_id'] . ': ' . $m['task_title'] : ''), $m['since'] ? 'ready ' . niceDate($m['since']) : ''])))?></small></span><span class="end"><?=h(cm_money($m['amount']))?><small>ex GST</small></span></a><?php endforeach; ?></div>
<p class="helper">Invoicing these (with the Xero invoice number recorded) arrives with the Pending Invoicing list in v0.13.</p><?php endif; ?></section>
<div class="client-summary">
  <div><small>New entries needing a billing decision</small><strong><a href="<?=h(route('work', ['filter' => 'review']))?>"><?=number_format((int)$billCounts['review_new'])?></a></strong></div>
  <div><small>New entries marked potentially billable</small><strong><?=number_format((int)$billCounts['billable_new'])?></strong></div>
  <div><small>Legacy billing-advised flags</small><strong><?=number_format((int)$billCounts['flagged'])?></strong></div>
</div>
<div class="notice"><strong>Not a confirmed outstanding-invoice list.</strong> Historical billing-advised flags and invoice references do not prove that an invoice is unpaid or still needs creating. Reconcile against Xero before acting. Imported Work Log history (all marked “review” at import) is excluded from the counts above.</div>
<h2>Billing history and flags <small>up to 100 tasks</small></h2>
<?php if (!$billingRows): ?><p class="emptyline">No flagged task or invoice-reference records found.</p><?php else: ?>
<div class="stack compact-list"><?php foreach ($billingRows as $r): ?><a class="record" href="<?=h(route('task', ['id' => $r['id']]))?>"><span><strong><?=h($r['title'])?></strong><small><?=h($r['client_name'] ?? 'Unassigned')?> · <?=h(cm_status_label($r['status']))?> · <?=h($r['pricing'] ?? '—')?> · Billing advised: <?=!empty($r['billing_advised']) ? 'Yes' : 'No'?></small></span><span class="end">Invoice <?=h($r['invoice_number'] ?: 'not recorded')?> ›</span></a><?php endforeach; ?></div>
<?php endif; ?>
<details><summary>Local quotes and invoices (<?=count($drafts)?>)</summary><?php if (!$drafts): ?><p class="muted">No local financial documents have been recorded (<?=number_format((int)$billCounts['documents'])?>). Xero is not queried.</p><?php else: ?><div class="stack"><?php foreach ($drafts as $d): ?><div class="record"><span><strong><?=h(ucfirst((string)$d['kind']))?> #<?=(int)$d['id']?></strong><small><?=h($d['client_name'] ?? 'Unknown client')?> · <?=h($d['local_status'])?> · Xero <?=h($d['xero_status'] ?? 'not linked')?></small></span><span class="end"><?=h($d['xero_number'] ?? '—')?></span></div><?php endforeach; ?></div><?php endif; ?></details>
