<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Clients: instant finder (JS) with a full server-side search fallback (name, business name,
 * original ID, contact name, email, phone digits), status chips, and a contact-role filter
 * like the Fabrik client list. CSV export uses the same query.
 */
[$sql, $args, $f] = cm_clients_query($_GET);
$q = $f['q']; $show = $f['show']; $role = $f['role'];
$sql .= ' LIMIT 400';
$rows = allRows($pdo, $sql, $args);
$clean = fn($v) => ($v === null || $v === 'null') ? '' : trim((string)$v);
?>
<?php if (($_GET['saved'] ?? null) === '1'): ?><div class="success" role="status">Saved.</div><?php endif; ?>
<p><a class="button-link" href="edit-client.php"><span aria-hidden="true">＋</span> New client</a></p>
<section class="finder-panel">
  <form class="finder" method="get" action="index.php" role="search" data-finder>
    <input type="hidden" name="page" value="clients"><input type="hidden" name="show" value="<?=h($show)?>"><?php if ($role !== ''): ?><input type="hidden" name="role" value="<?=h($role)?>"><?php endif; ?>
    <label for="finder-q" class="visually-hidden">Find a client</label>
    <div class="finder-row"><input id="finder-q" name="q" type="search" value="<?=h($q)?>" placeholder="Name, contact, email or phone" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" data-finder-input data-finder-autofocus aria-controls="finder-results" aria-autocomplete="list"><button type="submit" class="finder-go">Search</button></div>
    <div id="finder-results" class="finder-results" role="listbox" aria-label="Matching clients" data-finder-results hidden></div>
    <div class="recent-clients" data-recent-clients hidden><span class="small muted">Recent</span></div>
  </form>
</section>
<nav class="chips" aria-label="Client status">
<?php foreach (['active' => 'Active', 'archive' => 'Archived', 'all' => 'All'] as $k => $v): ?>
  <a class="chip<?=$show === $k ? ' on' : ''?>" href="<?=h(route('clients', array_filter(['show' => $k, 'q' => $q, 'role' => $role])))?>"<?=$show === $k ? ' aria-current="true"' : ''?>><?=h($v)?></a>
<?php endforeach; ?>
</nav>
<form class="filters" method="get" action="index.php"><input type="hidden" name="page" value="clients"><input type="hidden" name="show" value="<?=h($show)?>"><input type="hidden" name="q" value="<?=h($q)?>">
  <div class="filter-row"><label>Contact role<select name="role" data-autosubmit><option value="">Any</option><?php foreach (CM_CONTACT_TYPES as $k => $l): ?><option value="<?=h($k)?>"<?=$role === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label></div>
  <noscript><button type="submit">Apply</button></noscript></form>
<p class="small muted list-meta"><span data-testid="client-count"><?=count($rows)?> <?=count($rows) === 1 ? 'client' : 'clients'?><?=$q !== '' ? ' matching “' . h($q) . '”' : ''?><?=$role !== '' ? ' with a ' . h(CM_CONTACT_TYPES[$role] ?? $role) . ' contact' : ''?>.</span><a href="export.php?<?=h(http_build_query(array_filter(['type' => 'clients', 'q' => $q, 'show' => $show, 'role' => $role], 'strlen')))?>">Export CSV</a></p>
<?php if (!$rows): ?><p class="emptyline">No clients match. Try part of a name, a contact's name, an email address or the last digits of a phone number.</p><?php endif; ?>
<div class="stack client-list">
<?php foreach ($rows as $r): $sub = array_filter([$clean($r['business_name']) !== $r['name'] ? $clean($r['business_name']) : '', $clean($r['town']), $role !== '' && $r['role_contacts'] ? CM_CONTACT_TYPES[$role] . ': ' . $r['role_contacts'] : '']); ?>
  <a class="record" href="<?=h(route('client', ['id' => $r['id']]))?>"><span><strong><?=h($r['name'])?><?php if (strtolower((string)$r['status']) === 'archive'): ?> <em class="tag">Archived</em><?php endif; ?></strong><small><?=h($sub ? implode(' · ', $sub) : 'Legacy ID ' . $r['source_id'])?></small></span><span class="end"><?=(int)$r['open_tasks'] ? (int)$r['open_tasks'] . ' open' : ''?> ›</span></a>
<?php endforeach; ?>
</div>
<?php $unlinked = allRows($pdo, 'SELECT id,display_name,email,phone FROM contacts WHERE client_id IS NULL ORDER BY display_name LIMIT 100');
if ($unlinked): ?>
<details><summary>Contacts without a client (<?=count($unlinked)?>)</summary><p class="small muted">These contacts had no matching client in the old system. Open one and choose its client.</p><div class="stack compact-list">
<?php foreach ($unlinked as $u): ?><div class="record"><span><strong><?=h($u['display_name'] ?: 'Unnamed contact')?></strong><small><?=h(implode(' · ', array_filter([(string)$u['email'], (string)$u['phone']])))?></small></span><a class="inline-action" href="edit-contact.php?id=<?=(int)$u['id']?>">Choose client</a></div><?php endforeach; ?>
</div></details>
<?php endif;
