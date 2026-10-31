<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Work Log browse. Filters: new | review | unallocated | all. Edit links: new entries fully, imported entries link/billing only. */
$filter = in_array($_GET['filter'] ?? '', ['all', 'new', 'review', 'unallocated'], true) ? $_GET['filter'] : 'all';
$where = []; $args = [];
if ($q !== '') { $where[] = '(w.details LIKE :q OR c.name LIKE :client OR t.title LIKE :task OR w.source_id LIKE :id)'; $args = ['q' => '%' . $q . '%', 'client' => '%' . $q . '%', 'task' => '%' . $q . '%', 'id' => '%' . $q . '%']; }
$newOnly = "(w.source_system IS NULL OR w.source_system<>'fabrik')";
if ($filter === 'new') $where[] = $newOnly;
if ($filter === 'review') $where[] = "$newOnly AND w.billability='review'";
if ($filter === 'unallocated') $where[] = "$newOnly AND w.task_id IS NULL";
$sql = 'SELECT w.id,w.source_id,w.source_system,w.details,w.staff_key,w.work_date,w.started_at,w.duration_seconds,w.billable_seconds,w.billability,w.billed_amount_ex_gst,w.client_id,w.task_id,c.name client_name,t.title task_title
  FROM work_log w LEFT JOIN clients c ON c.id=w.client_id LEFT JOIN tasks t ON t.id=w.task_id'
  . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY w.id DESC LIMIT 100';
$rows = allRows($pdo, $sql, $args);
?>
<?php if (($_GET['saved'] ?? null) === '1'): ?><div class="success" role="status">Work Log entry saved.</div><?php elseif (($_GET['saved'] ?? null) === '0'): ?><div class="success" role="status">No changes to save.</div><?php endif; ?>
<p><a class="button-link" href="add-work.php"><span aria-hidden="true">＋</span> Log work</a></p>
<form class="search" method="get" action="index.php" role="search"><input type="hidden" name="page" value="work"><input type="hidden" name="filter" value="<?=h($filter)?>"><label for="wq" class="visually-hidden">Search Work Log</label><input id="wq" type="search" name="q" value="<?=h($q)?>" placeholder="Work, client or task" enterkeyhint="search"><button type="submit">Search</button></form>
<nav class="chips" aria-label="Work Log filter">
<?php foreach (['all' => 'All', 'new' => 'New entries', 'review' => 'Needs billing decision', 'unallocated' => 'No task'] as $k => $v): ?>
  <a class="chip<?=$filter === $k ? ' on' : ''?>" href="<?=h(route('work', array_filter(['filter' => $k === 'all' ? '' : $k, 'q' => $q])))?>"<?=$filter === $k ? ' aria-current="true"' : ''?>><?=h($v)?></a>
<?php endforeach; ?>
</nav>
<p class="muted small">Showing up to 100 records, newest first. A zero duration on an imported entry may mean missing legacy data, not zero work.</p>
<?php if (!$rows): ?><p class="emptyline">No Work Log entries match.</p><?php endif; ?>
<div class="stack"><?php foreach ($rows as $r): $isNew = $r['source_system'] !== 'fabrik'; ?>
  <details class="workitem"><summary><strong><?=h($r['details'] ?: 'Work entry')?></strong><small><?=h($r['client_name'] ?? 'No client')?> · <?=h(niceDate($r['work_date'] ?? $r['started_at']))?> · <?=h(dur($r['duration_seconds']))?><?=$isNew ? ' · new' : ''?></small></summary>
  <dl><dt>Entry</dt><dd><?=$isNew ? 'New #' . (int)$r['id'] : 'Imported (original ID ' . h($r['source_id']) . ')'?></dd><dt>Staff</dt><dd><?=h($r['staff_key'] ?: '—')?></dd><dt>Task</dt><dd><?php if ($r['task_id']): ?><a href="<?=h(route('task', ['id' => $r['task_id']]))?>"><?=h($r['task_title'])?></a><?php else: ?>Not linked<?php endif; ?></dd><dt>Client</dt><dd><?php if ($r['client_id']): ?><a href="<?=h(route('client', ['id' => $r['client_id']]))?>"><?=h($r['client_name'])?></a><?php else: ?>Not linked<?php endif; ?></dd><dt>Worked</dt><dd><?=h(dur($r['duration_seconds']))?></dd><dt>Billable</dt><dd><?=h(dur($r['billable_seconds']))?></dd><dt>Billing treatment</dt><dd><?=$isNew ? h(cm_status_label($r['billability'])) : 'Imported — not yet reviewed'?></dd><dt>Legacy amount (ex GST)</dt><dd><?=h($r['billed_amount_ex_gst'] ?? '—')?></dd></dl>
  <p class="row-actions"><a class="inline-action" href="edit-work.php?id=<?=(int)$r['id']?>&amp;back=<?=h(rawurlencode(route('work', array_filter(['filter' => $filter === 'all' ? '' : $filter, 'q' => $q]))))?>"><?=$isNew ? 'Edit' : 'Link / billing decision'?></a><?php if ($r['task_id'] || $r['client_id']): ?> <a class="inline-action" href="add-work.php?<?=$r['task_id'] ? 'task_id=' . (int)$r['task_id'] : 'client_id=' . (int)$r['client_id']?>">Log more like this</a><?php endif; ?></p>
  </details>
<?php endforeach; ?></div>
