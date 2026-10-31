<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Today: find a client instantly, act, and see only what needs attention.
 * No database counts. Legacy open tasks whose dates are long past are folded
 * into one "older backlog" link instead of dominating the list.
 */
const CM_STALE_DAYS = 60;
$pageOpt = ['h1' => false];
$closed = cm_closed_status_sql('t.status');
$staleFrom = (new DateTimeImmutable($today))->modify('-' . CM_STALE_DAYS . ' days')->format('Y-m-d');
$soon = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');

// 1. Open tasks due within 7 days or overdue within the last 60 days.
$due = allRows($pdo, "SELECT t.*,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id
    WHERE t.required_by IS NOT NULL AND DATE(t.required_by) BETWEEN ? AND ? AND NOT ($closed) AND t.completed_at IS NULL
    ORDER BY t.required_by ASC, t.id DESC LIMIT 12", [$staleFrom, $soon]);
// 2. Newly created (non-imported) open tasks with no due date — otherwise they'd be invisible.
$openNew = allRows($pdo, "SELECT t.id,t.title,t.status,t.client_id,t.received_at,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id
    WHERE (t.source_system IS NULL OR t.source_system<>'fabrik') AND t.required_by IS NULL AND NOT ($closed) AND t.completed_at IS NULL
    ORDER BY t.id DESC LIMIT 8");
// 3. Enquiry follow-ups due within 7 days, and enquiries not yet progressed.
$follow = allRows($pdo, "SELECT e.id,e.title,e.next_action,e.next_action_due,e.waiting_for,e.client_id,c.name client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id
    WHERE e.next_action_due IS NOT NULL AND e.next_action_due<=? AND e.status NOT IN ('task_created','closed','cancelled')
    ORDER BY e.next_action_due ASC, e.id DESC LIMIT 6", [$soon]);
$followIds = array_map(fn($r) => (int)$r['id'], $follow);
$pendingEnq = allRows($pdo, "SELECT e.id,e.title,e.created_at,e.required_by,e.client_id,c.name client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id
    WHERE e.status='enquiry' AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.enquiry_id=e.id) ORDER BY e.id DESC LIMIT 6");
// 4. Older open backlog (count only).
$stale = oneRow($pdo, "SELECT COUNT(*) n FROM tasks t WHERE t.required_by IS NOT NULL AND DATE(t.required_by)<? AND NOT ($closed) AND t.completed_at IS NULL", [$staleFrom]);
// 5. New Work Log entries that still need a decision (imported history is excluded: it was all imported as 'review').
$review = oneRow($pdo, "SELECT SUM(billability='review') review, SUM(task_id IS NULL) unallocated FROM work_log WHERE source_system IS NULL OR source_system<>'fabrik'");
$recent = allRows($pdo, "SELECT w.id,w.details,w.work_date,w.started_at,w.duration_seconds,w.task_id,w.client_id,t.title task_title,c.name client_name
    FROM work_log w LEFT JOIN tasks t ON t.id=w.task_id LEFT JOIN clients c ON c.id=w.client_id
    WHERE w.source_system IS NULL OR w.source_system<>'fabrik' ORDER BY w.id DESC LIMIT 5");

// Build one attention queue, earliest first.
$queue = [];
foreach ($due as $t) {
    $dd = substr((string)$t['required_by'], 0, 10); $late = $dd < $today;
    $queue[] = ['sort' => $dd, 'kind' => $late ? 'Overdue' : ($dd === $today ? 'Due today' : 'Due'), 'tone' => $late ? 'late' : 'due',
        'title' => $t['title'], 'meta' => ($t['client_name'] ?? 'Unassigned') . ' · ' . cm_status_label($t['status']) . (!empty($t['assigned_to']) ? ' · ' . ucwords((string)$t['assigned_to']) : ''),
        'when' => niceDate($t['required_by']), 'href' => route('task', ['id' => $t['id']]),
        'act' => ['add-work.php?task_id=' . (int)$t['id'], 'Log work']];
}
foreach ($follow as $f) {
    $queue[] = ['sort' => (string)$f['next_action_due'], 'kind' => 'Follow up', 'tone' => $f['next_action_due'] < $today ? 'late' : 'due',
        'title' => $f['title'], 'meta' => ($f['client_name'] ?? 'Unassigned') . ($f['next_action'] ? ' · ' . mb_strimwidth((string)$f['next_action'], 0, 70, '…') : '') . ($f['waiting_for'] ? ' · waiting for ' . $f['waiting_for'] : ''),
        'when' => niceDate($f['next_action_due']), 'href' => 'convert-enquiry.php?id=' . (int)$f['id'], 'act' => null];
}
foreach ($pendingEnq as $e) {
    if (in_array((int)$e['id'], $followIds, true)) continue;
    $queue[] = ['sort' => $e['required_by'] ?: '9999-' . substr((string)$e['created_at'], 0, 10), 'kind' => 'Enquiry', 'tone' => 'info',
        'title' => $e['title'], 'meta' => ($e['client_name'] ?? 'Unassigned') . ' · not yet a task',
        'when' => $e['required_by'] ? niceDate($e['required_by']) : 'No date', 'href' => 'convert-enquiry.php?id=' . (int)$e['id'], 'act' => ['convert-enquiry.php?id=' . (int)$e['id'], 'Review']];
}
$dueIds = array_map(fn($r) => (int)$r['id'], $due);
foreach ($openNew as $t) {
    if (in_array((int)$t['id'], $dueIds, true)) continue;
    $queue[] = ['sort' => '9999-z' . str_pad((string)$t['id'], 10, '0', STR_PAD_LEFT), 'kind' => 'Open task', 'tone' => 'info',
        'title' => $t['title'], 'meta' => ($t['client_name'] ?? 'Unassigned') . ' · ' . cm_status_label($t['status']),
        'when' => 'No due date', 'href' => route('task', ['id' => $t['id']]), 'act' => ['add-work.php?task_id=' . (int)$t['id'], 'Log work']];
}
usort($queue, fn($a, $b) => strcmp($a['sort'], $b['sort']));
$queueShown = array_slice($queue, 0, 10);
?>
<section class="finder-panel" aria-labelledby="finder-label">
  <form class="finder" method="get" action="index.php" role="search" data-finder>
    <input type="hidden" name="page" value="clients">
    <label id="finder-label" for="finder-q">Find a client</label>
    <div class="finder-row"><input id="finder-q" name="q" type="search" placeholder="Name, contact, email or phone" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" data-finder-input aria-controls="finder-results" aria-autocomplete="list"><button type="submit" class="finder-go">Search</button></div>
    <div id="finder-results" class="finder-results" role="listbox" aria-label="Matching clients" data-finder-results hidden></div>
    <div class="recent-clients" data-recent-clients hidden><span class="small muted">Recent</span></div>
  </form>
</section>

<nav class="quick-actions" aria-label="Quick actions">
  <a class="qa primary" href="add-work.php"><span aria-hidden="true">＋</span> Log work</a>
  <a class="qa" href="add-enquiry.php"><span aria-hidden="true">＋</span> Enquiry</a>
  <a class="qa" href="<?=h(route('tasks'))?>">Open tasks</a>
</nav>

<section class="today-section" aria-labelledby="attn">
  <div class="section-heading"><h2 id="attn">Needs attention</h2><span class="small muted"><?=h((new DateTimeImmutable($today))->format('D j M'))?></span></div>
  <?php if (!$queueShown): ?>
    <p class="emptyline">Nothing due, overdue or awaiting follow-up in the next seven days.</p>
  <?php else: ?>
  <ul class="queue" data-testid="attention-queue">
    <?php foreach ($queueShown as $it): ?>
    <li class="queue-item tone-<?=h($it['tone'])?>">
      <a class="queue-main" href="<?=h($it['href'])?>"><span class="queue-kind"><?=h($it['kind'])?></span><strong><?=h($it['title'])?></strong><small><?=h($it['meta'])?></small></a>
      <span class="queue-side"><span class="queue-when"><?=h($it['when'])?></span><?php if ($it['act']): ?><a class="queue-act" href="<?=h($it['act'][0])?>"><?=h($it['act'][1])?></a><?php endif; ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($queue) > count($queueShown)): ?><p class="small muted"><?=count($queue) - count($queueShown)?> more in <a href="<?=h(route('tasks'))?>">Tasks</a> and <a href="<?=h(route('enquiries'))?>">Enquiries</a>.</p><?php endif; ?>
  <?php endif; ?>
  <?php if ((int)($stale['n'] ?? 0) > 0): ?>
  <a class="fold-link" href="<?=h(route('tasks', ['status' => 'open', 'sort' => 'due']))?>"><span><strong>Older open backlog</strong><small><?=(int)$stale['n']?> imported tasks still marked open with due dates more than <?=CM_STALE_DAYS?> days ago</small></span><span aria-hidden="true">›</span></a>
  <?php endif; ?>
</section>

<section class="today-section" aria-labelledby="rev">
  <div class="section-heading"><h2 id="rev">Work to review</h2><a href="<?=h(route('work'))?>">Work Log ›</a></div>
  <?php $nRev = (int)($review['review'] ?? 0); $nUn = (int)($review['unallocated'] ?? 0); ?>
  <?php if (!$nRev && !$nUn): ?><p class="emptyline">All new Work Log entries have a billing decision and a task.</p><?php else: ?>
  <div class="review-links">
    <?php if ($nRev): ?><a href="<?=h(route('work', ['filter' => 'review']))?>"><strong><?=$nRev?></strong> new <?=$nRev === 1 ? 'entry needs' : 'entries need'?> a billing decision ›</a><?php endif; ?>
    <?php if ($nUn): ?><a href="<?=h(route('work', ['filter' => 'unallocated']))?>"><strong><?=$nUn?></strong> new <?=$nUn === 1 ? 'entry has' : 'entries have'?> no task ›</a><?php endif; ?>
  </div><?php endif; ?>
</section>

<section class="today-section" aria-labelledby="rw">
  <div class="section-heading"><h2 id="rw">Recent work</h2><a href="add-work.php">＋ Log work</a></div>
  <?php if (!$recent): ?><p class="emptyline">No work logged in this app yet.</p><?php else: ?>
  <div class="stack compact-list"><?php foreach ($recent as $w): ?>
    <div class="record"><span><strong><?=h($w['details'])?></strong><small><?=h($w['client_name'] ?? 'No client')?><?=$w['task_title'] ? ' · ' . h($w['task_title']) : ''?> · <?=h(dur($w['duration_seconds']))?> · <?=h(niceDate($w['work_date'] ?? $w['started_at']))?></small></span>
    <?php if ($w['task_id']): ?><a class="inline-action" href="add-work.php?task_id=<?=(int)$w['task_id']?>">Log again</a><?php elseif ($w['client_id']): ?><a class="inline-action" href="add-work.php?client_id=<?=(int)$w['client_id']?>">Log again</a><?php endif; ?></div>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<a class="fold-link" href="<?=h(route('invoicing'))?>"><span><strong>Billing review</strong><small>Check work and invoice evidence before preparing anything in Xero</small></span><span aria-hidden="true">›</span></a>
