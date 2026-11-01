<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Work Log ("time sheet"): filters like the Fabrik list — staff, client, dates — with totals and CSV export.
   Edit links: new entries fully, imported entries link/billing only. */
[$sql, $args, $f] = cm_work_query($_GET);
$rows = allRows($pdo, $sql . ' LIMIT 100', $args);
$tot = oneRow($pdo, 'SELECT COUNT(*) n, COALESCE(SUM(x.duration_seconds),0) worked, COALESCE(SUM(x.billable_seconds),0) billable, COALESCE(SUM(x.source_system=\'fabrik\' AND x.duration_seconds=0),0) zero_imported FROM (' . $sql . ') x', $args);
$staff = cm_staff_names($pdo);
$clientName = $f['client'] !== '' ? (string)(oneRow($pdo, 'SELECT name FROM clients WHERE id=?', [(int)$f['client']])['name'] ?? '') : '';
$filtersOn = $f['q'] . $f['staff'] . $f['from'] . $f['to'] . $f['client'] !== '' || $f['filter'] !== 'all';
$base = ['filter' => $f['filter'] === 'all' ? '' : $f['filter'], 'q' => $f['q'], 'staff' => $f['staff'], 'from' => $f['from'], 'to' => $f['to'], 'client' => $f['client']];
$keep = fn(array $over) => route('work', array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
$exportQ = http_build_query(array_filter(array_merge(['type' => 'work'], $base), 'strlen'));
$backHere = $keep([]);
?>
<?php if (($_GET['saved'] ?? null) === '1' && !cm_undo_pending()): ?><div class="success" role="status">Work Log entry saved.</div><?php elseif (($_GET['saved'] ?? null) === '0'): ?><div class="success" role="status">No changes to save.</div><?php endif; ?>
<p><a class="button-link" href="add-work.php"><span aria-hidden="true">＋</span> Log work</a></p>
<form class="filters" method="get" action="index.php" role="search">
  <input type="hidden" name="page" value="work"><input type="hidden" name="filter" value="<?=h($f['filter'] === 'all' ? '' : $f['filter'])?>"><?php if ($f['client'] !== ''): ?><input type="hidden" name="client" value="<?=h($f['client'])?>"><?php endif; ?>
  <div class="search"><label for="wq" class="visually-hidden">Search Work Log</label><input id="wq" type="search" name="q" value="<?=h($f['q'])?>" placeholder="Work, client or task" enterkeyhint="search"><button type="submit">Search</button></div>
  <div class="filter-row">
    <label>Staff<select name="staff" data-autosubmit><option value="">Everyone</option><?php foreach ($staff as $sn): ?><option value="<?=h($sn)?>"<?=$f['staff'] === $sn ? ' selected' : ''?>><?=h(ucfirst($sn))?></option><?php endforeach; ?></select></label>
    <label>From<input type="date" name="from" value="<?=h($f['from'])?>" data-autosubmit></label>
    <label>To<input type="date" name="to" value="<?=h($f['to'])?>" data-autosubmit></label>
  </div>
  <noscript><button type="submit">Apply filters</button></noscript>
</form>
<nav class="chips" aria-label="Work Log filter">
<?php foreach (['all' => 'All', 'new' => 'New entries', 'review' => 'Needs billing decision', 'unallocated' => 'No task'] as $k => $v): ?>
  <a class="chip<?=$f['filter'] === $k ? ' on' : ''?>" href="<?=h($keep(['filter' => $k === 'all' ? '' : $k]))?>"<?=$f['filter'] === $k ? ' aria-current="true"' : ''?>><?=h($v)?></a>
<?php endforeach; ?>
<?php if ($clientName !== ''): ?><a class="chip on" href="<?=h($keep(['client' => '']))?>" aria-label="Remove client filter"><?=h($clientName)?> ✕</a><?php endif; ?>
</nav>
<div class="totals" data-testid="work-totals"><span><strong><?=number_format((int)$tot['n'])?></strong> <?=(int)$tot['n'] === 1 ? 'entry' : 'entries'?></span><span><strong><?=h(dur($tot['worked']))?></strong> worked</span><span><strong><?=h(dur($tot['billable']))?></strong> billable</span>
<?php if ((int)$tot['zero_imported']): ?><small><?=(int)$tot['zero_imported']?> imported <?=(int)$tot['zero_imported'] === 1 ? 'entry has' : 'entries have'?> no recorded time, so totals may be low.</small><?php endif; ?></div>
<p class="small muted list-meta"><span>Newest first<?=(int)$tot['n'] > 100 ? ', first 100 shown' : ''?>.</span><a href="export.php?<?=h($exportQ)?>">Export CSV</a><?php if ($filtersOn): ?><a href="<?=h(route('work'))?>">Clear filters</a><?php endif; ?></p>
<?php if (!$rows): ?><p class="emptyline">No Work Log entries match.</p><?php endif; ?>
<div class="stack"><?php foreach ($rows as $r): $isNew = $r['source_system'] !== 'fabrik'; ?>
  <details class="workitem"><summary><strong><?=h($r['details'] ?: 'Work entry')?></strong><small><?=h(implode(' · ', array_filter([$r['client_name'] ?? 'No client', niceDate($r['day']), dur($r['duration_seconds']), $r['staff_key'], $isNew ? 'new' : ''])))?></small></summary>
  <dl><dt>Entry</dt><dd><?=$isNew ? 'New #' . (int)$r['id'] : 'Imported (original ID ' . h($r['source_id']) . ')'?></dd><dt>Staff</dt><dd><?=h($r['staff_key'] ?: '—')?></dd><dt>Task</dt><dd><?php if ($r['task_id']): ?><a href="<?=h(route('task', ['id' => $r['task_id']]))?>"><?=h($r['task_title'])?></a><?php else: ?>Not linked<?php endif; ?></dd><dt>Client</dt><dd><?php if ($r['client_id']): ?><a href="<?=h(route('client', ['id' => $r['client_id']]))?>"><?=h($r['client_name'])?></a> · <a href="<?=h($keep(['client' => (string)$r['client_id']]))?>">only this client</a><?php else: ?>Not linked<?php endif; ?></dd>
  <?php if ($r['started_at'] && $r['ended_at']): ?><dt>Start – finish</dt><dd><?=h(substr((string)$r['started_at'], 11, 5))?> – <?=h(substr((string)$r['ended_at'], 11, 5))?></dd><?php endif; ?>
  <dt>Worked</dt><dd><?=h(dur($r['duration_seconds']))?></dd><dt>Billable</dt><dd><?=h(dur($r['billable_seconds']))?></dd><dt>Billing treatment</dt><dd><?=$isNew ? h(cm_status_label($r['billability'])) : 'Imported — not yet reviewed'?></dd>
  <?php if ($r['rate_ex_gst'] !== null): ?><dt>Charge rate (as imported)</dt><dd>$<?=h($r['rate_ex_gst'])?></dd><?php endif; ?><dt>Legacy amount (ex GST)</dt><dd><?=h($r['billed_amount_ex_gst'] ?? '—')?></dd></dl>
  <p class="row-actions"><a class="inline-action" href="edit-work.php?id=<?=(int)$r['id']?>&amp;back=<?=h(rawurlencode($backHere))?>"><?=$isNew ? 'Edit' : 'Link / billing decision'?></a><?php if ($r['task_id'] || $r['client_id']): ?> <a class="inline-action" href="add-work.php?<?=$r['task_id'] ? 'task_id=' . (int)$r['task_id'] : 'client_id=' . (int)$r['client_id']?>">Log more like this</a><?php endif; ?></p>
  </details>
<?php endforeach; ?></div>
