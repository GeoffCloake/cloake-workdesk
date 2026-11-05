<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Services register (v0.13): hosting, email, domains, DNS, maintenance. Filters: search, kind, status, charging, check, due. */
$f = ['q' => cm_filter_str($_GET, 'q'), 'kind' => cm_filter_str($_GET, 'kind', 20), 'show' => cm_filter_str($_GET, 'show', 10) ?: 'live', 'charge' => cm_filter_str($_GET, 'charge', 10), 'check' => cm_filter_str($_GET, 'check', 20), 'due' => cm_filter_str($_GET, 'due', 10)];
$where = []; $args = [];
if ($f['q'] !== '') { $like = '%' . mb_strtolower($f['q']) . '%'; $where[] = '(LOWER(COALESCE(s.domain,\'\')) LIKE ? OR LOWER(COALESCE(s.label,\'\')) LIKE ? OR LOWER(c.name) LIKE ? OR s.source_id = ?)'; array_push($args, $like, $like, $like, $f['q']); }
if (isset(CM_SERVICE_KINDS[$f['kind']])) { $where[] = 's.kind=?'; $args[] = $f['kind']; }
if ($f['show'] === 'live') $where[] = "s.status IN ('active','pending','suspended')"; elseif ($f['show'] === 'ended') $where[] = "s.status NOT IN ('active','pending','suspended')";
if ($f['charge'] === 'billed') $where[] = 's.billable=1 AND s.is_internal=0'; elseif ($f['charge'] === 'free') $where[] = 's.billable=0 AND s.is_internal=0'; elseif ($f['charge'] === 'internal') $where[] = 's.is_internal=1';
if ($f['check'] === 'look') $where[] = "s.check_status IS NOT NULL AND s.check_status NOT IN ('','agrees')"; elseif (isset(CM_CHECK_STATUSES[$f['check']]) && $f['check'] !== '') { $where[] = 's.check_status=?'; $args[] = $f['check']; }
$horizon = (new DateTimeImmutable($today))->modify('+' . max(0, (int)cm_setting($pdo, 'lead_weeks')) . ' weeks')->format('Y-m-d');
if ($f['due'] === 'overdue') { $where[] = 's.billable=1 AND s.is_internal=0 AND ' . cm_billing_status_sql($pdo) . ' AND s.next_period_start<?'; $args[] = $today; }
elseif ($f['due'] === 'soon') { $where[] = 's.billable=1 AND s.is_internal=0 AND ' . cm_billing_status_sql($pdo) . ' AND s.next_period_start<=?'; $args[] = $horizon; }
$rows = allRows($pdo, 'SELECT s.*, c.name client_name FROM services s LEFT JOIN clients c ON c.id=s.client_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (s.next_period_start IS NULL), s.next_period_start, s.domain LIMIT 400', $args);
$tot = oneRow($pdo, "SELECT COUNT(*) n, SUM(status IN ('active','pending','suspended')) live, SUM(status IN ('active','pending','suspended') AND billable=1 AND is_internal=0) billed, SUM(check_status IS NOT NULL AND check_status NOT IN ('','agrees') AND status IN ('active','pending','suspended')) look FROM services");
$keep = fn(array $o) => route('services', array_filter(array_merge($f, $o), fn($v) => $v !== '' && $v !== null));
?>
<p class="lead">Every hosting service, domain and email account: who it belongs to, what it costs, and when it is next invoiced.</p>
<p class="row-inline"><a class="button-link" href="service-edit.php"><span aria-hidden="true">＋</span> New service</a><a href="<?=h(route('invoicing'))?>">Pending Invoicing ›</a></p>
<?php if (!(int)$tot['n']): ?><div class="notice">No services yet. They arrive with the WHMCS import (see INSTALL.md), or add one with ＋ New service.</div><?php endif; ?>
<div class="chips"><a class="chip<?=$f['check'] === 'look' ? ' on' : ''?>" href="<?=h($keep(['check' => $f['check'] === 'look' ? '' : 'look']))?>">Needs a look (<?=(int)$tot['look']?>)</a><a class="chip<?=$f['due'] === 'overdue' ? ' on' : ''?>" href="<?=h($keep(['due' => $f['due'] === 'overdue' ? '' : 'overdue']))?>">Overdue</a><a class="chip<?=$f['due'] === 'soon' ? ' on' : ''?>" href="<?=h($keep(['due' => $f['due'] === 'soon' ? '' : 'soon']))?>">Due within <?=(int)cm_setting($pdo, 'lead_weeks')?> weeks</a></div>
<form class="filters" method="get" action="index.php" role="search"><input type="hidden" name="page" value="services">
  <div class="search"><label for="sq" class="visually-hidden">Search services</label><input id="sq" type="search" name="q" value="<?=h($f['q'])?>" placeholder="Domain, client or WHMCS ID (S123 / D45)"><button type="submit">Search</button></div>
  <div class="filter-row">
    <label>Kind<select name="kind" data-autosubmit><option value="">All</option><?php foreach (CM_SERVICE_KINDS as $k => $l): ?><option value="<?=h($k)?>"<?=$f['kind'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <label>Status<select name="show" data-autosubmit><?php foreach (['live' => 'Live', 'ended' => 'Ended', 'all' => 'All'] as $k => $l): ?><option value="<?=h($k)?>"<?=$f['show'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <label>Charging<select name="charge" data-autosubmit><?php foreach (['' => 'All', 'billed' => 'Billed', 'free' => 'No charge', 'internal' => 'Internal'] as $k => $l): ?><option value="<?=h($k)?>"<?=$f['charge'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <label>Check<select name="check" data-autosubmit><option value="">Any</option><option value="look"<?=$f['check'] === 'look' ? ' selected' : ''?>>Needs a look</option><?php foreach (CM_CHECK_STATUSES as $k => $l): if ($k === '') continue; ?><option value="<?=h($k)?>"<?=$f['check'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
  </div><noscript><button type="submit">Apply filters</button></noscript></form>
<p class="small muted list-meta"><span><?=count($rows)?><?=count($rows) === 400 ? '+' : ''?> shown · <?=(int)$tot['live']?> live, <?=(int)$tot['billed']?> billed</span><?php if (array_filter($f, fn($v, $k) => $v !== '' && !($k === 'show' && $v === 'live'), ARRAY_FILTER_USE_BOTH)): ?><a href="<?=h(route('services'))?>">Clear filters</a><?php endif; ?></p>
<?php if (!$rows && (int)$tot['n']): ?><p class="emptyline">No services match.</p><?php endif; ?>
<div class="stack compact-list" data-testid="services-list"><?php foreach ($rows as $s): $billed = $s['billable'] && !$s['is_internal']; $late = $billed && $s['next_period_start'] && $s['next_period_start'] < $today; ?>
  <a class="record<?=in_array($s['status'], CM_SERVICE_LIVE, true) ? '' : ' is-closed'?>" href="<?=h(route('service', ['id' => $s['id']]))?>"><span><strong><?=h($s['domain'] ?: $s['label'] ?: '—')?><?php if ($s['check_status'] && $s['check_status'] !== 'agrees'): ?> <em class="tag"><?=h(CM_CHECK_STATUSES[$s['check_status']] ?? $s['check_status'])?></em><?php endif; ?></strong><small><?=h(implode(' · ', array_filter([$s['client_name'] ?? 'No client', CM_SERVICE_KINDS[$s['kind']] ?? $s['kind'], $s['status'] !== 'active' ? (CM_SERVICE_STATUSES[$s['status']] ?? $s['status']) : '', $s['is_internal'] ? 'internal' : (!$s['billable'] ? 'no charge' : ($s['price_ex_gst'] !== null ? cm_money($s['price_ex_gst']) . ' ' . strtolower(CM_CYCLES[(string)$s['cycle_months']] ?? '') : ''))])))?></small></span><span class="end<?=$late ? ' late' : ''?>"><?=$billed && $s['next_period_start'] ? h(niceDate($s['next_period_start'])) : ''?> ›</span></a>
<?php endforeach; ?></div>
