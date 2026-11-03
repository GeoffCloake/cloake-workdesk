<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Workload (v0.14): who is doing what, and when. One section per active person (Staff & hours):
 * days ahead with the tasks scheduled for them, booked time against their hours that day; overdue (scheduled
 * on a past day, still open) and not-yet-scheduled tasks alongside. On a computer, drag a card to another day or
 * person (or to "Not scheduled"); on a phone, "Move" opens schedule.php. Every move is an audited task update (Undo).
 */
if (!cm_has_schedule($pdo)) { echo '<div class="notice">Scheduling is not installed yet (migration 006).</div>'; return; }
$from = cm_filter_date($_GET, 'from') ?: cm_week_start($today);
$days = max(7, min(28, (int)($_GET['days'] ?? 14)));
$to = (new DateTimeImmutable($from))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
$who = cm_filter_str($_GET, 'who', 40);
$staffAll = cm_staff($pdo, true);
$staff = $who !== '' ? array_values(array_filter($staffAll, fn($s) => $s['name'] === mb_strtolower($who))) : $staffAll;
$closed = cm_closed_status_sql('t.status');
$old = (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d');
$tasks = allRows($pdo, "SELECT t.id,t.title,t.assigned_to,t.scheduled_on,t.scheduled_minutes,t.estimate_minutes,t.required_by,t.priority,t.stage_no,t.client_id,c.name client_name
    FROM tasks t LEFT JOIN clients c ON c.id=t.client_id
    WHERE t.assigned_to IS NOT NULL AND t.assigned_to<>'' AND NOT ($closed) AND t.completed_at IS NULL
      AND (t.scheduled_on IS NOT NULL OR t.required_by IS NULL OR DATE(t.required_by) >= ? OR (t.source_system IS NULL OR t.source_system<>'fabrik'))
    ORDER BY (t.required_by IS NULL), t.required_by, t.id", [$old]);
$olderCount = (int)oneRow($pdo, "SELECT COUNT(*) n FROM tasks t WHERE t.assigned_to IS NOT NULL AND t.assigned_to<>'' AND NOT ($closed) AND t.completed_at IS NULL AND t.scheduled_on IS NULL AND t.source_system='fabrik' AND t.required_by IS NOT NULL AND DATE(t.required_by) < ?", [$old])['n'];
$dates = []; for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) $dates[] = $d->format('Y-m-d');
$keep = fn(array $o) => route('workload', array_filter(array_merge(['from' => $from, 'days' => $days !== 14 ? $days : null, 'who' => $who], $o), fn($v) => $v !== null && $v !== ''));
$card = function (array $t, string $person, bool $late = false) use ($today) { $min = cm_booked_minutes($t); ?>
<li class="wl-card<?=$late ? ' is-late' : ''?>" draggable="true" data-task="<?=(int)$t['id']?>" data-who="<?=h($person)?>" data-testid="wl-card"><a class="wl-main" href="<?=h(route('task', ['id' => $t['id']]))?>"><strong><?php if (!empty($t['stage_no'])): ?><em class="tag">Stage <?=(int)$t['stage_no']?></em> <?php endif; ?><?=h($t['title'])?></strong><small><?=h(implode(' · ', array_filter([$t['client_name'] ?? '', $min ? cm_hm($min) : 'no estimate', $t['required_by'] ? 'required ' . niceDate($t['required_by']) : '', $late && $t['scheduled_on'] ? 'was ' . niceDate($t['scheduled_on']) : ''])))?></small></a><a class="mini" href="schedule.php?id=<?=(int)$t['id']?>&amp;who=<?=h(rawurlencode($person))?>">Move</a></li>
<?php };
?>
<p class="lead">Who is doing what, and when. Drag a card to another day or person on a computer, or use <strong>Move</strong>. Every move can be undone.</p>
<form class="filters" method="get" action="index.php"><input type="hidden" name="page" value="workload">
<div class="filter-row"><label>From<input type="date" name="from" value="<?=h($from)?>" data-autosubmit></label>
<label>Show<select name="days" data-autosubmit><?php foreach ([7 => '1 week', 14 => '2 weeks', 28 => '4 weeks'] as $k => $l): ?><option value="<?=$k?>"<?=$days === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></label>
<label>Person<select name="who" data-autosubmit><option value="">Everyone</option><?php foreach ($staffAll as $s): ?><option value="<?=h($s['name'])?>"<?=$who === $s['name'] ? ' selected' : ''?>><?=h(cm_staff_label($s))?></option><?php endforeach; ?></select></label></div>
<noscript><button type="submit">Show</button></noscript></form>
<p class="small muted list-meta"><a href="<?=h($keep(['from' => (new DateTimeImmutable($from))->modify('-7 days')->format('Y-m-d')]))?>">‹ Earlier</a><a href="<?=h($keep(['from' => cm_week_start($today)]))?>">This week</a><a href="<?=h($keep(['from' => (new DateTimeImmutable($from))->modify('+7 days')->format('Y-m-d')]))?>">Later ›</a><a href="staff.php">Staff &amp; hours</a></p>
<?php if (!$staff): ?><p class="emptyline">No one is active on the schedule. Add people in <a href="staff.php">Staff &amp; hours</a>.</p><?php endif; ?>
<div class="workload" data-workload data-csrf="<?=h(m7csrf())?>">
<?php foreach ($staff as $s):
    $mine = array_filter($tasks, fn($t) => in_array($s['name'], cm_assignees($t['assigned_to']), true));
    $late = array_filter($mine, fn($t) => $t['scheduled_on'] && $t['scheduled_on'] < $today);
    $unsched = array_filter($mine, fn($t) => !$t['scheduled_on']);
    $booked = 0; $cap = 0;
    foreach ($dates as $d) { $cap += cm_capacity($s, $d); foreach ($mine as $t) if ($t['scheduled_on'] === $d) $booked += cm_booked_minutes($t); }
?>
<section class="panel wl-person" data-testid="wl-person" data-person="<?=h($s['name'])?>"><h2 class="panel-title"><?=h(cm_staff_label($s))?> <small class="muted"><?=h(cm_hm($booked))?> booked of <?=h(cm_hm($cap))?></small></h2>
<?php if ($late): ?><h3 class="wl-sub late">Overdue on the schedule (<?=count($late)?>)</h3><ul class="wl-cards"><?php foreach ($late as $t) $card($t, $s['name'], true); ?></ul><?php endif; ?>
<div class="wl-days">
<?php foreach ($dates as $d): $cap1 = cm_capacity($s, $d); $dayTasks = array_filter($mine, fn($t) => $t['scheduled_on'] === $d); if (!$cap1 && !$dayTasks) continue; $b = array_sum(array_map('cm_booked_minutes', $dayTasks)); ?>
<div class="wl-day<?=$d === $today ? ' is-today' : ''?><?=$b > $cap1 ? ' is-over' : ''?>" data-drop data-date="<?=h($d)?>" data-who="<?=h($s['name'])?>" data-testid="wl-day"><div class="wl-date"><strong><?=h((new DateTimeImmutable($d))->format('D j M'))?></strong><small><?=h(cm_hm($b))?> / <?=h(cm_hm($cap1))?></small></div>
<ul class="wl-cards"><?php foreach ($dayTasks as $t) $card($t, $s['name']); ?></ul></div>
<?php endforeach; ?>
</div>
<details class="wl-unsched" data-drop data-date="" data-who="<?=h($s['name'])?>"<?=$unsched && count($unsched) <= 6 ? ' open' : ''?>><summary>Not scheduled (<?=count($unsched)?>)</summary><?php if (!$unsched): ?><p class="muted small">Everything assigned to <?=h(cm_staff_label($s))?> is scheduled.</p><?php else: ?><ul class="wl-cards"><?php foreach ($unsched as $t) $card($t, $s['name']); ?></ul><?php endif; ?></details>
</section>
<?php endforeach; ?>
</div>
<?php if ($olderCount): ?><p class="small muted"><?=$olderCount?> older imported open task<?=$olderCount === 1 ? '' : 's'?> (required more than 60 days ago) are not shown. They are on <a href="<?=h(route('tasks'))?>">Tasks</a>.</p><?php endif; ?>
