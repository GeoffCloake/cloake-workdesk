<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Tasks ("current tasks"): filters like the Fabrik list — status, Action for, priority — plus search and sort. */
[$sql, $args, $f] = cm_tasks_query($_GET);
$rows = allRows($pdo, $sql . ' LIMIT 100', $args);
$has002 = (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='assigned_to'");
$staff = $has002 ? cm_staff_names($pdo) : [];
$keep = fn(array $over) => route('tasks', array_filter(array_merge(['q' => $f['q'], 'status' => $f['status'], 'assigned' => $f['assigned'], 'priority' => $f['priority'], 'sort' => $f['sort']], $over), fn($v) => $v !== '' && $v !== null));
$exportQ = http_build_query(array_filter(['type' => 'tasks', 'q' => $f['q'], 'status' => $f['status'], 'assigned' => $f['assigned'], 'priority' => $f['priority'], 'sort' => $f['sort']], 'strlen'));
$statusChoices = ['open' => 'Open (all not completed)', 'closed' => 'Completed / cancelled', 'all' => 'All'] + array_combine(array_map(fn($k) => (string)$k, array_keys(CM_TASK_STATUSES)), array_values(CM_TASK_STATUSES));
?>
<p><a class="button-link" href="edit-task.php"><span aria-hidden="true">＋</span> New task</a></p>
<form class="filters" method="get" action="index.php" role="search">
  <input type="hidden" name="page" value="tasks">
  <div class="search"><label for="tq" class="visually-hidden">Search tasks</label><input id="tq" type="search" name="q" value="<?=h($f['q'])?>" placeholder="Task, client or original ID" enterkeyhint="search"><button type="submit">Search</button></div>
  <div class="filter-row">
    <label>Status<select name="status" data-autosubmit><?php foreach ($statusChoices as $k => $l): ?><option value="<?=h($k)?>"<?=$f['status'] === (string)$k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <?php if ($staff): ?><label>Action for<select name="assigned" data-autosubmit><option value="">Anyone</option><?php foreach ($staff as $sn): ?><option value="<?=h($sn)?>"<?=$f['assigned'] === $sn ? ' selected' : ''?>><?=h(ucfirst($sn))?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Priority<select name="priority" data-autosubmit><option value="">Any</option><?php foreach (CM_PRIORITIES as $k => $l): ?><option value="<?=h($k)?>"<?=$f['priority'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <label>Sort<select name="sort" data-autosubmit><?php foreach (['' => 'Newest', 'due' => 'Required date', 'priority' => 'Priority', 'queue' => 'Queue'] as $k => $l): if ($k === 'queue' && !$has002) continue; ?><option value="<?=h($k)?>"<?=$f['sort'] === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
  </div>
  <noscript><button type="submit">Apply filters</button></noscript>
</form>
<p class="small muted list-meta"><span><?=count($rows)?><?=count($rows) === 100 ? '+' : ''?> tasks<?=count($rows) === 100 ? ' (first 100 — narrow the filters, or export)' : ''?></span><a href="export.php?<?=h($exportQ)?>">Export CSV</a><?php if ($f['assigned'] . $f['priority'] . $f['q'] !== '' || $f['status'] !== 'open' || $f['sort'] !== ''): ?><a href="<?=h(route('tasks'))?>">Clear filters</a><?php endif; ?></p>
<?php if (!$rows): ?><p class="emptyline">No tasks match.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($rows as $r): $late = $r['required_by'] && substr((string)$r['required_by'], 0, 10) < $today && !in_array(strtolower((string)$r['status']), CM_CLOSED_TASK_STATUSES, true) && !$r['completed_at']; ?>
  <a class="record" href="<?=h(route('task', ['id' => $r['id']]))?>"><span><strong><?php if (in_array(strtolower((string)$r['priority']), ['immediate', 'urgent'], true)): ?><em class="tag tag-hot"><?=h(cm_status_label($r['priority']))?></em> <?php endif; ?><?=h($r['title'])?></strong><small><?=h(implode(' · ', array_filter([$r['client_name'] ?? 'Unassigned', cm_status_label($r['status']), $r['assigned_to'] ? ucwords((string)$r['assigned_to']) : '', $r['source_system'] === 'fabrik' ? 'orig #' . $r['source_id'] : 'new #' . $r['id']])))?></small></span><span class="end<?=$late ? ' late' : ''?>"><?=h(niceDate($r['required_by']))?> ›</span></a>
<?php endforeach; ?></div>
