<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/*
 * Client detail, phone-first: who, how to reach them, what's open, what happened.
 * Read-only. All values come from the clients/contacts/tasks/work_log/enquiries
 * tables and the preserved legacy_record JSON; nothing is inferred or totalled
 * into money.
 */
$pageOpt = ['back' => [route('clients'), 'Clients'], 'h1' => false];
$c = oneRow($pdo, 'SELECT * FROM clients WHERE id=?', [$id]);
if (!$c) { http_response_code(404); echo '<p class="emptyline">Client not found. <a href="' . h(route('clients')) . '">Back to clients</a></p>'; return; }
$pageTitle = $c['name'];
$raw = legacy($c['legacy_record']);
$rv = function (string $k) use ($raw): string { $v = $raw[$k] ?? ''; if (is_array($v)) $v = implode(', ', $v); $v = trim((string)$v); return ($v === 'null' || $v === '0000-00-00 00:00:00') ? '' : $v; };
$closed = cm_closed_status_sql('status');

$contacts = allRows($pdo, 'SELECT * FROM contacts WHERE client_id=? ORDER BY display_name LIMIT 200', [$id]);
$tasksOpen = allRows($pdo, "SELECT id,title,status,required_by,pricing,source_system FROM tasks WHERE client_id=? AND NOT ($closed) AND completed_at IS NULL ORDER BY (required_by IS NULL), required_by DESC, id DESC LIMIT 60", [$id]);
$tasksClosed = allRows($pdo, "SELECT id,title,status,required_by,completed_at,pricing FROM tasks WHERE client_id=? AND (($closed) OR completed_at IS NOT NULL) ORDER BY COALESCE(completed_at,required_by,received_at) DESC, id DESC LIMIT 60", [$id]);
$closedCount = oneRow($pdo, "SELECT COUNT(*) n FROM tasks WHERE client_id=? AND (($closed) OR completed_at IS NOT NULL)", [$id]);
$work = allRows($pdo, "SELECT w.id,w.details,w.staff_key,w.work_date,w.started_at,w.duration_seconds,w.billable_seconds,w.billability,w.source_system,w.task_id,t.title task_title
    FROM work_log w LEFT JOIN tasks t ON t.id=w.task_id WHERE w.client_id=? ORDER BY COALESCE(w.work_date,DATE(w.started_at)) DESC, w.id DESC LIMIT 40", [$id]);
$workStats = oneRow($pdo, "SELECT COUNT(*) n, MAX(COALESCE(work_date,DATE(started_at))) last_at FROM work_log WHERE client_id=?", [$id]);
$enquiries = allRows($pdo, "SELECT e.id,e.title,e.status,e.created_at,e.next_action_due,(SELECT MIN(t.id) FROM tasks t WHERE t.enquiry_id=e.id) task_id FROM enquiries e WHERE e.client_id=? ORDER BY e.id DESC LIMIT 20", [$id]);

// Best phone/email for the top quick actions: client record first, then first active contact.
$primaryPhone = $rv('phone'); $primaryEmail = $rv('email');
foreach ($contacts as $k) {
    if ($primaryPhone === '' && trim((string)$k['phone']) !== '' && legacyValue($k, 'status') !== 'archive') $primaryPhone = trim((string)$k['phone']);
    if ($primaryEmail === '' && validEmail(trim((string)$k['email'])) && legacyValue($k, 'status') !== 'archive') $primaryEmail = trim((string)$k['email']);
}
$tel = telHref($primaryPhone);
$isArchived = strtolower((string)$c['status']) === 'archive';

// Activity timeline (newest first): work, tasks received/completed, enquiries.
$timeline = [];
foreach ($work as $w) {
    $d = $w['work_date'] ?: substr((string)$w['started_at'], 0, 10);
    $timeline[] = ['d' => (string)$d, 'k' => 'work', 'icon' => '◷', 'title' => $w['details'] ?: 'Work entry',
        'meta' => trim(implode(' · ', array_filter([dur($w['duration_seconds']) . ' worked', $w['staff_key'], $w['task_title']]))) . ($w['source_system'] === 'fabrik' ? '' : ' · new entry'),
        'href' => $w['task_id'] ? route('task', ['id' => $w['task_id']]) : ''];
}
foreach (array_merge($tasksOpen, array_slice($tasksClosed, 0, 20)) as $t) {
    if (!empty($t['completed_at'])) $timeline[] = ['d' => substr((string)$t['completed_at'], 0, 10), 'k' => 'task', 'icon' => '✓', 'title' => $t['title'], 'meta' => 'Task completed', 'href' => route('task', ['id' => $t['id']])];
}
foreach ($enquiries as $e) {
    $timeline[] = ['d' => substr((string)$e['created_at'], 0, 10), 'k' => 'enquiry', 'icon' => '＋', 'title' => $e['title'],
        'meta' => 'Enquiry · ' . cm_status_label($e['status']) . ($e['task_id'] ? ' · task #' . (int)$e['task_id'] : ''), 'href' => 'convert-enquiry.php?id=' . (int)$e['id']];
}
usort($timeline, fn($a, $b) => strcmp($b['d'], $a['d']));
$timeline = array_slice($timeline, 0, 40);

$addr = fn(array $keys) => implode(', ', array_filter(array_map($rv, $keys), 'strlen'));
$physical = $addr(['physical_address_1', 'physical_address_2', 'physical_city_town', 'physical_country']);
$postal = $addr(['postal_address_1', 'postal_address_2', 'postal_address_3', 'postal_city_town', 'postal_code', 'postal_country']);
$domains = $rv('domains');
?>
<header class="client-head" data-recent-client="<?=(int)$id?>" data-name="<?=h($c['name'])?>">
  <h1><?=h($c['name'])?></h1>
  <p class="client-sub"><?php if ($isArchived): ?><em class="tag">Archived</em> <?php endif; ?><?=h(implode(' · ', array_filter([$rv('business_name') !== $c['name'] ? $rv('business_name') : '', $rv('physical_city_town') ?: $rv('postal_city_town'), 'Legacy ID ' . $c['source_id']])))?></p>
</header>

<nav class="client-actions" aria-label="Client actions">
  <?php if ($tel): ?><a class="ca" href="<?=h($tel)?>"><span aria-hidden="true">✆</span> Call</a><?php else: ?><span class="ca disabled" aria-disabled="true"><span aria-hidden="true">✆</span> No phone</span><?php endif; ?>
  <?php if (validEmail($primaryEmail)): ?><a class="ca" href="mailto:<?=h($primaryEmail)?>"><span aria-hidden="true">✉</span> Email</a><?php else: ?><span class="ca disabled" aria-disabled="true"><span aria-hidden="true">✉</span> No email</span><?php endif; ?>
  <a class="ca primary" href="add-work.php?client_id=<?=(int)$id?>"><span aria-hidden="true">＋</span> Log work</a>
  <a class="ca" href="add-enquiry.php?client_id=<?=(int)$id?>"><span aria-hidden="true">＋</span> Enquiry</a>
</nav>
<?php if ($primaryPhone !== '' || $primaryEmail !== ''): ?><p class="small muted reach"><?=h(implode(' · ', array_filter([$primaryPhone, $primaryEmail])))?></p><?php endif; ?>

<div class="client-summary">
  <div><small>Open tasks</small><strong><?=count($tasksOpen)?></strong></div>
  <div><small>Last work</small><strong><?=h($workStats['last_at'] ? niceDate($workStats['last_at']) : '—')?></strong></div>
  <div><small>Contacts</small><strong><?=count($contacts)?></strong></div>
</div>

<section aria-labelledby="ct">
  <h2 id="ct">Contacts</h2>
  <?php if (!$contacts): ?><p class="emptyline">No contacts linked to this client.</p><?php endif; ?>
  <?php $archivedContacts = []; ?>
  <div class="stack contact-list"><?php foreach ($contacts as $k):
      if (legacyValue($k, 'status') === 'archive') { $archivedContacts[] = $k; continue; }
      $mobile = legacyValue($k, 'mobile'); $phone = legacyValue($k, 'phone'); if ($phone === '' && $mobile === '') $phone = trim((string)$k['phone']);
      $role = implode(' · ', array_filter([legacyValue($k, 'position'), legacyValue($k, 'contact_type')])); $email = trim((string)$k['email']); ?>
    <div class="contact-card"><div class="contact-who"><strong><?=h($k['display_name'] ?: 'Unnamed contact')?></strong><?php if ($role): ?><small><?=h($role)?></small><?php endif; ?></div>
      <div class="contact-links">
        <?php if ($mobile !== '' && telHref($mobile)): ?><a href="<?=h(telHref($mobile))?>" aria-label="Call mobile <?=h($mobile)?>"><span class="lbl">Mobile</span> <?=h($mobile)?></a><?php endif; ?>
        <?php if ($phone !== '' && telHref($phone) && $phone !== $mobile): ?><a href="<?=h(telHref($phone))?>" aria-label="Call <?=h($phone)?>"><span class="lbl">Phone</span> <?=h($phone)?></a><?php endif; ?>
        <?php if (validEmail($email)): ?><a href="mailto:<?=h($email)?>"><span class="lbl">Email</span> <?=h($email)?></a><?php elseif ($email !== ''): ?><span><span class="lbl">Email</span> <?=h($email)?></span><?php endif; ?>
      </div></div>
  <?php endforeach; ?></div>
  <?php if ($archivedContacts): ?><details><summary>Archived contacts (<?=count($archivedContacts)?>)</summary><div class="stack">
    <?php foreach ($archivedContacts as $k): ?><div class="record"><span><strong><?=h($k['display_name'] ?: 'Unnamed contact')?></strong><small><?=h(implode(' · ', array_filter([(string)$k['email'], (string)$k['phone']])))?></small></span></div><?php endforeach; ?>
  </div></details><?php endif; ?>
</section>

<section aria-labelledby="ot">
  <div class="section-heading"><h2 id="ot">Open tasks</h2><a href="add-enquiry.php?client_id=<?=(int)$id?>">＋ Enquiry</a></div>
  <?php if (!$tasksOpen): ?><p class="emptyline">No open tasks for this client.</p><?php else: ?>
  <div class="stack compact-list"><?php foreach ($tasksOpen as $t): $late = $t['required_by'] && substr((string)$t['required_by'], 0, 10) < $today; ?>
    <div class="record"><a class="record-main" href="<?=h(route('task', ['id' => $t['id']]))?>"><strong><?=h($t['title'])?></strong><small><?=h(cm_status_label($t['status']))?><?=$t['pricing'] ? ' · ' . h(cm_status_label($t['pricing'])) : ''?><?=$t['required_by'] ? ' · due ' . h(niceDate($t['required_by'])) : ''?></small></a>
      <a class="inline-action<?=$late ? ' late' : ''?>" href="add-work.php?task_id=<?=(int)$t['id']?>">Log work</a></div>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ((int)$closedCount['n'] > 0): ?><details><summary>Completed &amp; cancelled tasks (<?=(int)$closedCount['n']?>)</summary><div class="stack compact-list">
    <?php foreach ($tasksClosed as $t): ?><a class="record" href="<?=h(route('task', ['id' => $t['id']]))?>"><span><strong><?=h($t['title'])?></strong><small><?=h(cm_status_label($t['status']))?></small></span><span class="end"><?=h(niceDate($t['completed_at'] ?: $t['required_by']))?></span></a><?php endforeach; ?>
    <?php if ((int)$closedCount['n'] > count($tasksClosed)): ?><a class="small" href="<?=h(route('tasks', ['q' => $c['name'], 'status' => 'closed']))?>">Show all <?=(int)$closedCount['n']?> in Tasks ›</a><?php endif; ?>
  </div></details><?php endif; ?>
</section>

<section aria-labelledby="act">
  <h2 id="act">Activity</h2>
  <div class="chips" role="group" aria-label="Filter activity" data-timeline-filter hidden>
    <button type="button" class="chip on" data-k="all" aria-pressed="true">All</button><button type="button" class="chip" data-k="work" aria-pressed="false">Work</button><button type="button" class="chip" data-k="task" aria-pressed="false">Tasks</button><button type="button" class="chip" data-k="enquiry" aria-pressed="false">Enquiries</button>
  </div>
  <?php if (!$timeline): ?><p class="emptyline">No recorded activity for this client.</p><?php else: ?>
  <ol class="timeline" data-timeline><?php foreach ($timeline as $ev): ?>
    <li class="tl-item" data-k="<?=h($ev['k'])?>"><span class="tl-icon" aria-hidden="true"><?=h($ev['icon'])?></span><div class="tl-body"><time datetime="<?=h($ev['d'])?>"><?=h(niceDate($ev['d']))?></time>
      <?php if ($ev['href']): ?><a href="<?=h($ev['href'])?>"><?=h(mb_strimwidth((string)$ev['title'], 0, 160, '…'))?></a><?php else: ?><span class="tl-title"><?=h(mb_strimwidth((string)$ev['title'], 0, 160, '…'))?></span><?php endif; ?>
      <small><?=h($ev['meta'])?></small></div></li>
  <?php endforeach; ?></ol>
  <?php if ((int)$workStats['n'] > count($work)): ?><p class="small"><a href="<?=h(route('work', ['q' => $c['name']]))?>">All <?=(int)$workStats['n']?> Work Log entries for this client ›</a></p><?php endif; ?>
  <p class="small muted">Imported durations of 0h 00m can mean the original time was missing, not that no work was done.</p>
  <?php endif; ?>
</section>

<details><summary>Client details</summary><dl>
  <?php foreach (['Email' => $rv('email'), 'Phone' => $rv('phone'), 'Physical address' => $physical, 'Postal address' => $postal, 'Preferred contact' => $rv('contact_method'), 'Xero contact reference' => $rv('xero_contact') ?: (string)$c['xero_contact_id'], 'Credit approved' => $rv('credit_approved'), 'Notes' => $rv('notes'), 'Comments' => $rv('comments')] as $label => $val): ?>
  <dt><?=h($label)?></dt><dd><?=h($val !== '' ? $val : '—')?></dd>
  <?php endforeach; ?>
</dl></details>
<details><summary>Services &amp; domains</summary>
  <?php if ($domains !== ''): ?><dl><dt>Domains (from task manager)</dt><dd><?=h($domains)?></dd></dl><?php endif; ?>
  <p class="small muted">WHMCS hosting services, domain renewals and Xero invoices have not been imported or reconciled yet, so none are shown here. Nothing on this page is a renewal or amount owing.</p>
</details>
