<?php
declare(strict_types=1);
/*
 * Enquiry view + progress to task (M6B write path, kept).
 * Conversion logic is unchanged: row lock on the enquiry, re-check for an
 * existing linked task inside the transaction, unique (source_system,source_id)
 * on tasks as a database backstop. Re-submitting never creates a second task.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!$id) { http_response_code(400); exit('Missing enquiry ID.'); }
if (empty($_SESSION['m6b_csrf'])) $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['m6b_csrf'], $_POST['csrf'])) { http_response_code(403); exit('Session expired. Reload the enquiry.'); }
        $pdo->beginTransaction();
        // The parent-row lock serialises conversions of the same enquiry.
        $q = $pdo->prepare('SELECT * FROM enquiries WHERE id=? FOR UPDATE');
        $q->execute([$id]); $enquiry = $q->fetch();
        if (!$enquiry) { throw new RuntimeException('Enquiry not found.'); }
        $q = $pdo->prepare('SELECT id FROM tasks WHERE enquiry_id=? LIMIT 1');
        $q->execute([$id]); $taskId = $q->fetchColumn();
        if (!$taskId) {
            if (empty($enquiry['client_id'])) throw new RuntimeException('Assign a client to this enquiry before conversion.');
            $q = $pdo->prepare('SELECT id FROM clients WHERE id=?');
            $q->execute([$enquiry['client_id']]);
            if (!$q->fetchColumn()) throw new RuntimeException('The enquiry client no longer exists.');
            $allowed = ['enquiry', 'task_created'];
            if (!in_array((string)$enquiry['status'], $allowed, true)) throw new RuntimeException('This enquiry is not eligible for conversion.');
            $q = $pdo->prepare('INSERT INTO tasks (client_id,enquiry_id,source_system,source_id,title,instructions,received_at,required_by,priority,status,notes) VALUES (?,?,?,?,?,?,NOW(),?,?,?,?)');
            $q->execute([(int)$enquiry['client_id'], $id, 'enquiry', (string)$id, $enquiry['title'], $enquiry['details'], $enquiry['required_by'] ? $enquiry['required_by'] . ' 00:00:00' : null, $enquiry['priority'], 'open', $enquiry['next_action'] ?: null]);
            $taskId = (int)$pdo->lastInsertId();
            $q = $pdo->prepare("UPDATE enquiries SET status='task_created' WHERE id=?");
            $q->execute([$id]);
            $q = $pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,task_id,event_kind,event_at,summary) VALUES (?,?,?,'enquiry_converted',NOW(),?)");
            $q->execute([(int)$enquiry['client_id'], $id, $taskId, 'Enquiry progressed to task']);
        }
        $pdo->commit();
        $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
        header('Location: index.php?page=task&id=' . (int)$taskId, true, 303); exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('M6B conversion error for enquiry ' . $id . ': ' . $e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to convert enquiry. No changes committed.';
}
try {
    $q = $pdo->prepare('SELECT e.*,c.name client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.id=?');
    $q->execute([$id]); $enquiry = $q->fetch();
    if (!$enquiry) { http_response_code(404); exit('Enquiry not found.'); }
    $q = $pdo->prepare('SELECT id,title FROM tasks WHERE enquiry_id=? ORDER BY id LIMIT 1');
    $q->execute([$id]); $linkedTask = $q->fetch();
    $q = $pdo->prepare('SELECT description,quantity,unit_price_ex_gst,pricing_mode,status FROM breakdown_items WHERE enquiry_id=? ORDER BY id');
    $q->execute([$id]); $lines = $q->fetchAll();
} catch (Throwable $e) { error_log('M6B load enquiry ' . $id . ': ' . $e->getMessage()); http_response_code(500); exit('Enquiry temporarily unavailable.'); }

cm_page_start('Enquiry #' . $id, 'enquiries', ['back' => [route('enquiries'), 'Enquiries'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<section class="panel"><h2 class="panel-title"><?=h($enquiry['title'])?></h2>
<dl class="facts"><dt>Client</dt><dd><?php if ($enquiry['client_id']): ?><a href="<?=h(route('client', ['id' => $enquiry['client_id']]))?>"><?=h($enquiry['client_name'])?></a><?php else: ?>Unassigned<?php endif; ?></dd>
<dt>Status</dt><dd><?=h(cm_status_label($enquiry['status']))?></dd><dt>Priority</dt><dd><?=h(cm_status_label($enquiry['priority']))?></dd><dt>Required by</dt><dd><?=h($enquiry['required_by'] ? niceDate($enquiry['required_by']) : 'Not set')?></dd>
<?php if ($enquiry['next_action'] || $enquiry['next_action_due']): ?><dt>Next action</dt><dd><?=h($enquiry['next_action'] ?: '—')?><?=$enquiry['next_action_due'] ? ' (by ' . h(niceDate($enquiry['next_action_due'])) . ')' : ''?></dd><?php endif; ?>
<?php if ($enquiry['waiting_for']): ?><dt>Waiting for</dt><dd><?=h($enquiry['waiting_for'])?></dd><?php endif; ?></dl>
<div class="prewrap"><?=h($enquiry['details'] ?: 'No description')?></div>
<?php if ($lines): ?><h3>Proposed breakdown (ex GST)</h3><ul class="plain"><?php foreach ($lines as $l): ?><li><?=h($l['description'])?> — <?=h((float)$l['quantity'])?> × $<?=h(number_format((float)$l['unit_price_ex_gst'], 2))?> · <?=h(cm_status_label($l['pricing_mode']))?> · <?=h($l['status'])?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php if (!empty($linkedTask)): ?>
  <div class="success"><strong>Already progressed.</strong> This enquiry has task <a href="<?=h(route('task', ['id' => $linkedTask['id']]))?>">#<?=h($linkedTask['id'])?> — <?=h($linkedTask['title'])?></a>. No new task will be created.</div>
  <nav class="client-actions three"><a class="ca primary" href="add-work.php?task_id=<?=(int)$linkedTask['id']?>">＋ Log work</a><a class="ca" href="<?=h(route('task', ['id' => $linkedTask['id']]))?>">Open task</a><?php if ($enquiry['client_id']): ?><a class="ca" href="<?=h(route('client', ['id' => $enquiry['client_id']]))?>">Client</a><?php endif; ?></nav>
<?php elseif (!in_array((string)$enquiry['status'], ['enquiry', 'task_created'], true)): ?>
  <p class="notice">This enquiry's status is <?=h($enquiry['status'])?>, so conversion is disabled.</p>
<?php elseif (empty($enquiry['client_id'])): ?>
  <p class="notice">Assign a client before converting.</p>
<?php else: ?>
  <nav class="client-actions two"><?php if (($enquiry['status'] ?? '') === 'enquiry' && $enquiry['legacy_source_id'] === null): ?><a class="ca" href="edit-enquiry.php?id=<?=(int)$id?>">Edit enquiry</a><?php endif; ?></nav>
  <div class="panel convert-box"><p>Creating a task makes one new <strong>open</strong> task linked to this enquiry. It copies the client, title, instructions, priority and required-by date. The enquiry stays in the database. Pricing and breakdown lines stay on the enquiry for later review; nothing is invoiced.</p>
  <form method="post" action="convert-enquiry.php" data-once><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6b_csrf'])?>"><button type="submit" class="save">Create linked task</button></form></div>
<?php endif; ?>
<?php cm_page_end('enquiries');
