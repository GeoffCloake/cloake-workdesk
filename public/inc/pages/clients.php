<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Clients: instant finder (JS) with a full server-side search fallback that
 * matches client name, business name, original ID, contact name, email and phone.
 */
$show = in_array($_GET['show'] ?? '', ['active', 'archive', 'all'], true) ? $_GET['show'] : ($q !== '' ? 'all' : 'active');
$where = []; $args = [];
if ($q !== '') {
    // LOWER() on both sides: on MySQL 8, JSON_UNQUOTE() returns utf8mb4_bin (case-sensitive), unlike MariaDB.
    $like = '%' . mb_strtolower($q) . '%';
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $digitsSql = fn(string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),' ',''),'-',''),'+',''),'(',''),')','') LIKE ?";
    $where[] = "(LOWER(c.name) LIKE ? OR c.source_id LIKE ? OR LOWER(c.business_name) LIKE ? OR LOWER(c.email) LIKE ?"
        . (strlen($digits) >= 4 ? ' OR ' . $digitsSql('c.phone') : '')
        . " OR EXISTS (SELECT 1 FROM contacts k WHERE k.client_id=c.id AND (LOWER(k.display_name) LIKE ? OR LOWER(k.email) LIKE ?"
        . (strlen($digits) >= 4 ? ' OR ' . $digitsSql('k.phone') . ' OR ' . $digitsSql('k.mobile') : '') . ")))";
    array_push($args, $like, $like, $like, $like);
    if (strlen($digits) >= 4) $args[] = '%' . $digits . '%';
    array_push($args, $like, $like);
    if (strlen($digits) >= 4) array_push($args, '%' . $digits . '%', '%' . $digits . '%');
}
if ($show === 'active') $where[] = "LOWER(COALESCE(c.status,''))<>'archive'";
if ($show === 'archive') $where[] = "LOWER(COALESCE(c.status,''))='archive'";
$sql = "SELECT c.id,c.name,c.status,c.source_id,
    c.business_name, COALESCE(c.physical_city_town, c.postal_city_town) town,
    (SELECT COUNT(*) FROM tasks t WHERE t.client_id=c.id AND NOT (" . cm_closed_status_sql('t.status') . ") AND t.completed_at IS NULL) open_tasks
  FROM clients c" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.name LIMIT 400';
$rows = allRows($pdo, $sql, $args);
$clean = fn($v) => ($v === null || $v === 'null') ? '' : trim((string)$v);
?>
<?php if (($_GET['saved'] ?? null) === '1'): ?><div class="success" role="status">Saved.</div><?php endif; ?>
<p><a class="button-link" href="edit-client.php"><span aria-hidden="true">＋</span> New client</a></p>
<section class="finder-panel">
  <form class="finder" method="get" action="index.php" role="search" data-finder>
    <input type="hidden" name="page" value="clients"><input type="hidden" name="show" value="<?=h($show)?>">
    <label for="finder-q" class="visually-hidden">Find a client</label>
    <div class="finder-row"><input id="finder-q" name="q" type="search" value="<?=h($q)?>" placeholder="Name, contact, email or phone" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" data-finder-input data-finder-autofocus aria-controls="finder-results" aria-autocomplete="list"><button type="submit" class="finder-go">Search</button></div>
    <div id="finder-results" class="finder-results" role="listbox" aria-label="Matching clients" data-finder-results hidden></div>
    <div class="recent-clients" data-recent-clients hidden><span class="small muted">Recent</span></div>
  </form>
</section>
<nav class="chips" aria-label="Client status">
<?php foreach (['active' => 'Active', 'archive' => 'Archived', 'all' => 'All'] as $k => $v): ?>
  <a class="chip<?=$show === $k ? ' on' : ''?>" href="<?=h(route('clients', array_filter(['show' => $k, 'q' => $q])))?>"<?=$show === $k ? ' aria-current="true"' : ''?>><?=h($v)?></a>
<?php endforeach; ?>
</nav>
<p class="small muted" data-testid="client-count"><?=count($rows)?> <?=count($rows) === 1 ? 'client' : 'clients'?><?=$q !== '' ? ' matching “' . h($q) . '”' : ''?>.</p>
<?php if (!$rows): ?><p class="emptyline">No clients match. Try part of a name, a contact's name, an email address or the last digits of a phone number.</p><?php endif; ?>
<div class="stack client-list">
<?php foreach ($rows as $r): $sub = array_filter([$clean($r['business_name']) !== $r['name'] ? $clean($r['business_name']) : '', $clean($r['town'])]); ?>
  <a class="record" href="<?=h(route('client', ['id' => $r['id']]))?>"><span><strong><?=h($r['name'])?><?php if (strtolower((string)$r['status']) === 'archive'): ?> <em class="tag">Archived</em><?php endif; ?></strong><small><?=h($sub ? implode(' · ', $sub) : 'Legacy ID ' . $r['source_id'])?></small></span><span class="end"><?=(int)$r['open_tasks'] ? (int)$r['open_tasks'] . ' open' : ''?> ›</span></a>
<?php endforeach; ?>
</div>
<?php $unlinked = allRows($pdo, 'SELECT id,display_name,email,phone FROM contacts WHERE client_id IS NULL ORDER BY display_name LIMIT 100');
if ($unlinked): ?>
<details><summary>Contacts without a client (<?=count($unlinked)?>)</summary><p class="small muted">These contacts had no matching client in the old system. Open one and choose its client.</p><div class="stack compact-list">
<?php foreach ($unlinked as $u): ?><div class="record"><span><strong><?=h($u['display_name'] ?: 'Unnamed contact')?></strong><small><?=h(implode(' · ', array_filter([(string)$u['email'], (string)$u['phone']])))?></small></span><a class="inline-action" href="edit-contact.php?id=<?=(int)$u['id']?>">Choose client</a></div><?php endforeach; ?>
</div></details>
<?php endif;
