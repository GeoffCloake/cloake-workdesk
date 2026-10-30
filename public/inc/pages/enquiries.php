<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Enquiries list (new enquiries only — legacy enquiry archive is not mapped yet). */
$enq = allRows($pdo, 'SELECT e.id,e.title,e.status,e.required_by,e.priority,e.next_action_due,c.name client_name, (SELECT MIN(t.id) FROM tasks t WHERE t.enquiry_id=e.id) linked_task_id FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id ORDER BY e.id DESC LIMIT 50');
?>
<p><a class="button-link" href="add-enquiry.php"><span aria-hidden="true">＋</span> Quick Enquiry</a></p>
<p class="muted small">New enquiries only. The legacy enquiry archive has not been mapped into this list.</p>
<?php if (!$enq): ?><p class="emptyline">No enquiries yet.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($enq as $e): ?>
  <a class="record" href="convert-enquiry.php?id=<?=(int)$e['id']?>" aria-label="Open enquiry #<?=(int)$e['id']?>: <?=h($e['title'])?>"><span><strong><?=h($e['title'])?></strong><small><?=h($e['client_name'] ?? 'Unassigned')?> · <?=h(cm_status_label($e['priority']))?> · <?=$e['linked_task_id'] ? 'Task #' . (int)$e['linked_task_id'] : h(cm_status_label($e['status']))?><?=$e['required_by'] ? ' · required ' . h(niceDate($e['required_by'])) : ''?><?=$e['next_action_due'] ? ' · follow up ' . h(niceDate($e['next_action_due'])) : ''?></small></span><span class="end link-like">#<?=(int)$e['id']?> ›</span></a>
<?php endforeach; ?></div>
