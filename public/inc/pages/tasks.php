<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Tasks list. Default: open tasks, newest first. Searching covers all statuses unless a filter is chosen. */
$status = in_array($_GET['status'] ?? '', ['open', 'closed', 'all'], true) ? $_GET['status'] : ($q !== '' ? 'all' : 'open');
$sortDue = ($_GET['sort'] ?? '') === 'due';
$closed = cm_closed_status_sql('t.status');
$where = []; $args = [];
if ($q !== '') { $where[] = '(t.title LIKE :q OR t.source_id LIKE :id OR c.name LIKE :client)'; $args = ['q' => '%' . $q . '%', 'id' => '%' . $q . '%', 'client' => '%' . $q . '%']; }
if ($status === 'open') $where[] = "NOT ($closed) AND t.completed_at IS NULL";
if ($status === 'closed') $where[] = "(($closed) OR t.completed_at IS NOT NULL)";
$sql = 'SELECT t.id,t.title,t.source_id,t.source_system,t.status,t.required_by,t.client_id,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ($sortDue ? ' ORDER BY (t.required_by IS NULL), t.required_by ASC, t.id DESC' : ' ORDER BY t.id DESC') . ' LIMIT 100';
$rows = allRows($pdo, $sql, $args);
?>
<p><a class="button-link" href="edit-task.php"><span aria-hidden="true">＋</span> New task</a></p>
<form class="search" method="get" action="index.php" role="search"><input type="hidden" name="page" value="tasks"><input type="hidden" name="status" value="<?=h($status)?>"><label for="tq" class="visually-hidden">Search tasks</label><input id="tq" type="search" name="q" value="<?=h($q)?>" placeholder="Task, client or original ID" enterkeyhint="search"><button type="submit">Search</button></form>
<nav class="chips" aria-label="Task status">
<?php foreach (['open' => 'Open', 'closed' => 'Completed / cancelled', 'all' => 'All'] as $k => $v): ?>
  <a class="chip<?=$status === $k ? ' on' : ''?>" href="<?=h(route('tasks', array_filter(['status' => $k, 'q' => $q, 'sort' => $sortDue ? 'due' : ''])))?>"<?=$status === $k ? ' aria-current="true"' : ''?>><?=h($v)?></a>
<?php endforeach; ?>
  <a class="chip<?=$sortDue ? ' on' : ''?>" href="<?=h(route('tasks', array_filter(['status' => $status, 'q' => $q, 'sort' => $sortDue ? '' : 'due'])))?>">Sort by due date</a>
</nav>
<p class="small muted"><?=count($rows)?><?=count($rows) === 100 ? '+' : ''?> tasks shown<?=count($rows) === 100 ? ' (first 100 — search to narrow)' : ''?>.</p>
<?php if (!$rows): ?><p class="emptyline">No tasks match.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($rows as $r): $late = $r['required_by'] && substr((string)$r['required_by'], 0, 10) < $today && $status !== 'closed'; ?>
  <a class="record" href="<?=h(route('task', ['id' => $r['id']]))?>"><span><strong><?=h($r['title'])?></strong><small><?=h($r['client_name'] ?? 'Unassigned')?> · <?=h(cm_status_label($r['status']))?> · <?=$r['source_system'] === 'fabrik' ? 'orig #' . h($r['source_id']) : 'new #' . (int)$r['id']?></small></span><span class="end<?=$late ? ' late' : ''?>"><?=h(niceDate($r['required_by']))?> ›</span></a>
<?php endforeach; ?></div>
