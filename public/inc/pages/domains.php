<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Domain register (v0.16): every domain service — expiry, registrar, DNS, auto-renew, and how it is billed
   (on its own, included in a hosting service, no charge, internal). Read-only list; edit on the service. */
if (!cm_has_domains($pdo)) { echo '<p class="notice">The domain register needs database migration 007. Run the installer for v0.16.</p>'; return; }
$f = ['q' => cm_filter_str($_GET, 'q'), 'show' => cm_filter_str($_GET, 'show', 12), 'reg' => cm_filter_str($_GET, 'reg', 60), 'status' => cm_filter_str($_GET, 'status', 10) ?: 'live'];
$soon = (new DateTimeImmutable($today))->modify('+' . CM_EXPIRY_WARN_DAYS . ' days')->format('Y-m-d');
$where = ["s.kind='domain'"]; $args = [];
if ($f['q'] !== '') { $like = '%' . mb_strtolower($f['q']) . '%'; $where[] = "(LOWER(COALESCE(s.domain,'')) LIKE ? OR LOWER(COALESCE(c.name,'')) LIKE ?)"; array_push($args, $like, $like); }
if ($f['status'] === 'live') $where[] = "s.status IN ('active','pending','suspended')"; elseif ($f['status'] === 'ended') $where[] = "s.status NOT IN ('active','pending','suspended')";
if ($f['reg'] !== '') { $where[] = 's.registrar=?'; $args[] = $f['reg']; }
$unlinked = "s.billable=0 AND s.is_internal=0 AND s.included_in_service_id IS NULL";
// Billed on its own but never invoiced (no charge recorded, from Xero history or the manager) — e.g. extra domains that may belong in a hosting plan.
$never = "s.billable=1 AND s.is_internal=0 AND s.included_in_service_id IS NULL AND NOT EXISTS (SELECT 1 FROM charges g WHERE g.source_kind='service' AND g.source_id=s.id AND g.status<>'void')";
switch ($f['show']) {
    case 'soon': $where[] = 's.expiry_date>=? AND s.expiry_date<=?'; array_push($args, $today, $soon); break;
    case 'expired': $where[] = 's.expiry_date<?'; $args[] = $today; break;
    case 'unlinked': $where[] = $unlinked; break;
    case 'never': $where[] = $never; break;
    case 'included': $where[] = 's.included_in_service_id IS NOT NULL'; break;
    case 'billed': $where[] = 's.billable=1 AND s.is_internal=0'; break;
}
$rows = allRows($pdo, 'SELECT s.*, c.name client_name, h.id host_id, COALESCE(h.domain,h.label) host_name FROM services s LEFT JOIN clients c ON c.id=s.client_id LEFT JOIN services h ON h.id=s.included_in_service_id WHERE '
    . implode(' AND ', $where) . ' ORDER BY (s.expiry_date IS NULL), s.expiry_date, s.domain LIMIT 500', $args);
$live = "s.kind='domain' AND s.status IN ('active','pending','suspended')";
$tot = oneRow($pdo, "SELECT COUNT(*) n, SUM(s.expiry_date>=? AND s.expiry_date<=?) soon, SUM(s.expiry_date<?) expired, SUM($unlinked) unlinked, SUM($never) never, SUM(s.included_in_service_id IS NOT NULL) included FROM services s WHERE $live", [$today, $soon, $today]);
$regs = array_column(allRows($pdo, "SELECT DISTINCT registrar FROM services WHERE kind='domain' AND registrar IS NOT NULL AND registrar<>'' ORDER BY registrar"), 'registrar');
$keep = fn(array $o) => route('domains', array_filter(array_merge($f, $o), fn($v) => $v !== '' && $v !== null));
$chip = fn(string $k, string $label, $n) => '<a class="chip' . ($f['show'] === $k ? ' on' : '') . '" href="' . h($keep(['show' => $f['show'] === $k ? '' : $k])) . '">' . h($label) . ' (' . (int)$n . ')</a>';
?>
<p class="lead">Every domain: when it expires, where it's registered and hosted, and how it's billed.</p>
<p class="row-inline"><a class="button-link" href="service-edit.php"><span aria-hidden="true">＋</span> New domain or service</a><a href="<?=h(route('services'))?>">All services ›</a></p>
<div class="chips" data-testid="domain-chips"><?=$chip('soon', 'Expiring within ' . CM_EXPIRY_WARN_DAYS . ' days', $tot['soon'])?><?=$chip('expired', 'Expired', $tot['expired'])?><?=$chip('never', 'Billed, never invoiced', $tot['never'])?><?=$chip('unlinked', 'No charge, not linked', $tot['unlinked'])?><?=$chip('included', 'Included in hosting', $tot['included'])?></div>
<form class="filters" method="get" action="index.php" role="search"><input type="hidden" name="page" value="domains"><?php if ($f['show'] !== ''): ?><input type="hidden" name="show" value="<?=h($f['show'])?>"><?php endif; ?>
  <div class="search"><label for="dq" class="visually-hidden">Search domains</label><input id="dq" type="search" name="q" value="<?=h($f['q'])?>" placeholder="Domain or client"><button type="submit">Search</button></div>
  <div class="filter-row">
    <label>Registrar<select name="reg" data-autosubmit><option value="">All</option><?php foreach ($regs as $r): ?><option value="<?=h($r)?>"<?=$f['reg'] === $r ? ' selected' : ''?>><?=h($r)?></option><?php endforeach; ?></select></label>
    <label>Status<select name="status" data-autosubmit><?php foreach (['live' => 'Live', 'ended' => 'Ended', 'all' => 'All'] as $k => $l): ?><option value="<?=h($k)?>"<?=$f['status'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
  </div><noscript><button type="submit">Apply filters</button></noscript></form>
<p class="small muted list-meta"><span><?=count($rows)?><?=count($rows) === 500 ? '+' : ''?> shown · <?=(int)$tot['n']?> live domains</span><?php if ($f['q'] !== '' || $f['show'] !== '' || $f['reg'] !== '' || $f['status'] !== 'live'): ?><a href="<?=h(route('domains'))?>">Clear filters</a><?php endif; ?></p>
<?php if (!$rows): ?><p class="emptyline">No domains match.</p><?php endif; ?>
<div class="stack compact-list" data-testid="domains-list"><?php foreach ($rows as $s): $xs = cm_expiry_state($s['expiry_date'], $today);
  $bill = $s['is_internal'] ? 'internal' : ($s['included_in_service_id'] ? 'in ' . $s['host_name'] : ($s['billable'] ? ($s['price_ex_gst'] !== null ? cm_money($s['price_ex_gst']) . ' ' . strtolower(CM_CYCLES[(string)$s['cycle_months']] ?? '') : 'billed') : 'no charge'));
  $meta = array_filter([$s['client_name'] ?? 'No client', $s['registrar'], $s['dns_host'] ? 'DNS ' . $s['dns_host'] : '', $s['auto_renew'] === null ? '' : ($s['auto_renew'] ? 'auto-renew' : 'manual renew'), $bill, $s['status'] !== 'active' ? (CM_SERVICE_STATUSES[$s['status']] ?? $s['status']) : '']); ?>
  <a class="record<?=in_array($s['status'], CM_SERVICE_LIVE, true) ? '' : ' is-closed'?>" href="<?=h(route('service', ['id' => $s['id']]))?>" data-domain="<?=h((string)$s['domain'])?>"><span><strong><?=h($s['domain'] ?: $s['label'] ?: '—')?></strong><small><?=h(implode(' · ', $meta))?></small></span><span class="end<?=$xs === 'expired' ? ' late' : ($xs === 'soon' ? ' soon' : '')?>"><?=$s['expiry_date'] ? h(niceDate($s['expiry_date'])) : '—'?> ›</span></a>
<?php endforeach; ?></div>
