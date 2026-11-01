<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Enquiries (v0.11): status, category, received-date filters and search. Default = open enquiries. */
$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => (string)($_GET['status'] ?? 'open'),
    'cat' => trim((string)($_GET['cat'] ?? '')),
    'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''),
];
$validDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$where = []; $args = [];
if ($f['status'] === 'open') { $where[] = 'e.status IN (' . implode(',', array_fill(0, count(CM_ENQUIRY_OPEN), '?')) . ')'; array_push($args, ...CM_ENQUIRY_OPEN); }
elseif ($f['status'] === 'closed') { $where[] = "e.status IN ('declined','withdrawn','duplicate')"; }
elseif (isset(CM_ENQUIRY_STATUSES[$f['status']])) { $where[] = 'e.status = ?'; $args[] = $f['status']; }
else $f['status'] = 'all';
if ($f['cat'] !== '') { $where[] = 'e.task_category = ?'; $args[] = $f['cat']; }
if ($validDate($f['from'])) { $where[] = 'COALESCE(e.received_at,e.created_at) >= ?'; $args[] = $f['from'] . ' 00:00:00'; } else $f['from'] = '';
if ($validDate($f['to'])) { $where[] = 'COALESCE(e.received_at,e.created_at) <= ?'; $args[] = $f['to'] . ' 23:59:59'; } else $f['to'] = '';
if ($f['q'] !== '') {
    $like = '%' . mb_strtolower($f['q']) . '%';
    $where[] = '(LOWER(e.title) LIKE ? OR LOWER(COALESCE(e.details,\'\')) LIKE ? OR LOWER(COALESCE(c.name,\'\')) LIKE ? OR LOWER(COALESCE(k.display_name,\'\')) LIKE ? OR CAST(e.id AS CHAR) = ?)';
    array_push($args, $like, $like, $like, $like, $f['q']);
}
$sql = 'SELECT e.id,e.title,e.status,e.required_by,e.priority,e.next_action_due,e.received_at,e.created_at,e.task_category,e.task_type,e.duplicate_of,c.name client_name,k.display_name contact_name,
        (SELECT MIN(t.id) FROM tasks t WHERE t.enquiry_id=e.id) linked_task_id
        FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id LEFT JOIN contacts k ON k.id=e.contact_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY COALESCE(e.received_at,e.created_at) DESC, e.id DESC LIMIT 200';
$enq = allRows($pdo, $sql, $args);
$cats = array_column(allRows($pdo, "SELECT DISTINCT task_category c FROM enquiries WHERE task_category IS NOT NULL AND task_category<>'' ORDER BY 1"), 'c');
$statusChoices = ['open' => 'Open (New, Quoted, Waiting, Accepted)', 'all' => 'All', 'closed' => 'Declined / withdrawn / duplicate'] + CM_ENQUIRY_STATUSES;
$filtered = $f['q'] . $f['cat'] . $f['from'] . $f['to'] !== '' || $f['status'] !== 'open';
$deleted = filter_var($_GET['deleted'] ?? null, FILTER_VALIDATE_INT) ?: 0;
?>
<p><a class="button-link" href="add-enquiry.php"><span aria-hidden="true">＋</span> Quick Enquiry</a></p>
<?php if ($deleted): ?><div class="success" role="status"><strong>Enquiry #<?=(int)$deleted?> deleted.</strong></div><?php endif; ?>
<form class="filters" method="get" action="index.php" role="search">
  <input type="hidden" name="page" value="enquiries">
  <div class="search"><label for="eq" class="visually-hidden">Search enquiries</label><input id="eq" type="search" name="q" value="<?=h($f['q'])?>" placeholder="Title, client, contact or #number" enterkeyhint="search"><button type="submit">Search</button></div>
  <div class="filter-row">
    <label>Status<select name="status" data-autosubmit><?php foreach ($statusChoices as $k => $l): ?><option value="<?=h((string)$k)?>"<?=$f['status'] === (string)$k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
    <?php if ($cats): ?><label>Category<select name="cat" data-autosubmit><option value="">Any</option><?php foreach ($cats as $c): ?><option value="<?=h($c)?>"<?=$f['cat'] === $c ? ' selected' : ''?>><?=h($c)?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Received from<input type="date" name="from" value="<?=h($f['from'])?>" data-autosubmit></label>
    <label>to<input type="date" name="to" value="<?=h($f['to'])?>" data-autosubmit></label>
  </div>
  <noscript><button type="submit">Apply filters</button></noscript>
</form>
<p class="small muted list-meta"><span><?=count($enq)?><?=count($enq) === 200 ? '+' : ''?> enquir<?=count($enq) === 1 ? 'y' : 'ies'?><?=count($enq) === 200 ? ' (first 200 — narrow the filters)' : ''?></span><?php if ($filtered): ?><a href="<?=h(route('enquiries'))?>">Clear filters</a><?php endif; ?></p>
<?php if (!$enq): ?><p class="emptyline"><?=$filtered ? 'No enquiries match.' : 'No open enquiries.'?></p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($enq as $e):
    $statusText = $e['status'] === 'duplicate' && $e['duplicate_of'] ? 'Duplicate of #' . (int)$e['duplicate_of'] : (CM_ENQUIRY_STATUSES[$e['status']] ?? cm_status_label($e['status']));
    if ($e['linked_task_id'] && $e['status'] === 'task_created') $statusText = 'Task #' . (int)$e['linked_task_id'];
    $bits = array_filter([niceDate($e['received_at'] ?? $e['created_at']), $e['client_name'] ?? 'Unassigned', $e['contact_name'], $e['task_type'] ?: $e['task_category'], $statusText,
        in_array($e['priority'], ['immediate', 'high', 'urgent'], true) ? cm_status_label($e['priority']) : '',
        $e['required_by'] ? 'required ' . niceDate($e['required_by']) : '', $e['next_action_due'] ? 'follow up ' . niceDate($e['next_action_due']) : '']); ?>
  <a class="record<?=in_array($e['status'], ['declined', 'withdrawn', 'duplicate'], true) ? ' is-closed' : ''?>" href="convert-enquiry.php?id=<?=(int)$e['id']?>" aria-label="Open enquiry #<?=(int)$e['id']?>: <?=h($e['title'])?>"><span><strong><?=h($e['title'])?></strong><small><?=h(implode(' · ', $bits))?></small></span><span class="end link-like">#<?=(int)$e['id']?> ›</span></a>
<?php endforeach; ?></div>
