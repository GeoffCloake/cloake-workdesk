<?php
declare(strict_types=1);
/*
 * Edit enquiry (v0.11). Enquiries made in this app stay editable at any status, including after a
 * task was created (to fix mis-entries). Actions:
 *   save   — update fields (audited, stale-edit protected, undoable)
 *   close  — withdraw, or mark as a duplicate of another enquiry; optionally cancel its open tasks
 *   delete — only an app-made enquiry with no task; its price lines go with it; a snapshot is audited
 * Imported enquiries (legacy_source_id set) stay read-only.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
require_once __DIR__ . '/inc/edit.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/enquiry.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id(); if (!$id) { http_response_code(400); exit('Invalid enquiry.'); }
$VER = ['client_id', 'contact_id', 'received_at', 'task_category', 'task_type', 'title', 'details', 'priority', 'required_by', 'next_action', 'next_action_due', 'waiting_for', 'status', 'duplicate_of', 'closed_reason'];
$error = ''; $posted = null;
$linkedTasks = fn() => allRows($pdo, 'SELECT id,title,status FROM tasks WHERE enquiry_id=? ORDER BY id', [$id]);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        m7verify();
        $action = (string)($_POST['action'] ?? 'save');
        $pdo->beginTransaction();
        $old = oneRow($pdo, 'SELECT * FROM enquiries WHERE id=? FOR UPDATE', [$id]);
        if (!$old) throw new InvalidArgumentException('Enquiry not found.');
        if ($old['legacy_source_id'] !== null) throw new InvalidArgumentException('Imported enquiries are read-only.');
        cm_check_ver($old, $VER);
        $tasks = $linkedTasks();

        if ($action === 'save') {
            $posted = cm_enquiry_posted();
            $new = cm_enquiry_validate($pdo, $posted, cm_task_classes($pdo), $old);
            if ($new['status'] === 'task_created' && !$tasks) throw new InvalidArgumentException('"Task created" needs a linked task. Use Create task on the enquiry page.');
            if ($posted['new_contact_name'] !== '') $new['contact_id'] = cm_enquiry_new_contact($pdo, $new['client_id'], $posted);
            if (in_array($new['status'], CM_ENQUIRY_OPEN, true) || $new['status'] === 'task_created') { $new['duplicate_of'] = null; $new['closed_reason'] = null; }
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE enquiries SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                cm_audit($pdo, 'enquiry_updated', ['client_id' => $new['client_id'], 'enquiry_id' => $id], 'Enquiry #' . $id . ' updated: ' . implode(', ', array_keys($changes)), $changes);
            }
            $pdo->commit();
            header('Location: convert-enquiry.php?id=' . $id . '&saved=' . ($changes ? 1 : 0), true, 303); exit;
        }

        if ($action === 'close') {
            $kind = (string)($_POST['close_kind'] ?? '');
            $reason = cm_post('closed_reason', 255);
            $new = ['status' => $kind, 'closed_reason' => cm_nullable($reason), 'duplicate_of' => null];
            if ($kind === 'duplicate') {
                $dup = cm_post('duplicate_of', 12);
                if (!ctype_digit($dup) || (int)$dup === $id) throw new InvalidArgumentException('Enter the number of the enquiry this one duplicates.');
                if (!oneRow($pdo, 'SELECT id FROM enquiries WHERE id=?', [(int)$dup])) throw new InvalidArgumentException('Enquiry #' . $dup . ' does not exist.');
                $new['duplicate_of'] = (int)$dup;
            } elseif ($kind !== 'withdrawn') throw new InvalidArgumentException('Choose withdraw or duplicate.');
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE enquiries SET $sets WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
            }
            $cancelled = [];
            if (!empty($_POST['cancel_tasks'])) {
                foreach ($tasks as $t) {
                    if (in_array(strtolower((string)$t['status']), ['completed', 'cancelled'], true)) continue;
                    $pdo->prepare("UPDATE tasks SET status='cancelled' WHERE id=?")->execute([(int)$t['id']]);
                    cm_audit($pdo, 'task_updated', ['client_id' => $old['client_id'], 'enquiry_id' => $id, 'task_id' => (int)$t['id']], 'Task cancelled with enquiry #' . $id, ['status' => ['previous' => $t['status'], 'current' => 'cancelled']]);
                    $cancelled[] = (int)$t['id'];
                }
            }
            // Audited last so Undo restores the enquiry (each cancelled task has its own audit entry).
            if ($changes) cm_audit($pdo, 'enquiry_updated', ['client_id' => $old['client_id'], 'enquiry_id' => $id], 'Enquiry #' . $id . ($kind === 'duplicate' ? ' marked duplicate of #' . $new['duplicate_of'] : ' withdrawn') . ($cancelled ? '; cancelled task #' . implode(', #', $cancelled) : ''), $changes);
            $pdo->commit();
            header('Location: convert-enquiry.php?id=' . $id . '&saved=1', true, 303); exit;
        }

        if ($action === 'delete') {
            if ($tasks) throw new InvalidArgumentException('This enquiry has a task, so it cannot be deleted. Withdraw it or mark it as a duplicate instead.');
            if (cm_post('confirm_delete', 10) !== 'yes') throw new InvalidArgumentException('Tick the box to confirm deleting the enquiry.');
            $lines = allRows($pdo, 'SELECT * FROM breakdown_items WHERE enquiry_id=?', [$id]);
            $pdo->prepare('DELETE FROM breakdown_items WHERE enquiry_id=?')->execute([$id]);
            // Earlier audit entries keep their text; only their link to the deleted row is cleared.
            $pdo->prepare('UPDATE activity_events SET enquiry_id=NULL WHERE enquiry_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM enquiries WHERE id=?')->execute([$id]);
            cm_audit($pdo, 'enquiry_deleted', ['client_id' => $old['client_id']], 'Enquiry #' . $id . ' deleted: ' . $old['title'], ['enquiry' => $old, 'price_lines' => $lines]);
            $pdo->commit();
            header('Location: ' . route('enquiries', ['deleted' => $id]), true, 303); exit;
        }
        throw new InvalidArgumentException('Unknown action.');
    }
} catch (Throwable $e) {
    $error = cm_edit_error($e, $pdo, 'enquiry #' . $id);
}
$e = oneRow($pdo, 'SELECT e.*,c.name AS client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.id=?', [$id]);
if (!$e) { http_response_code(404); exit('Enquiry not found.'); }
$tasks = $linkedTasks();
$editable = $e['legacy_source_id'] === null;
$v = cm_enquiry_form_values($e);
if ($posted) $v = array_merge($v, $posted);
cm_page_start('Edit enquiry #' . $id, 'enquiries', ['back' => ['convert-enquiry.php?id=' . $id, 'Enquiry #' . $id], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (!$editable): ?><div class="panel"><p>This enquiry was imported, so it is read-only.</p></div>
<?php else: ?>
<?php if ($tasks): ?><p class="notice">Linked task<?=count($tasks) > 1 ? 's' : ''?>: <?php foreach ($tasks as $i => $t): ?><?=$i ? ', ' : ''?><a href="<?=h(route('task', ['id' => $t['id']]))?>">#<?=(int)$t['id']?> <?=h($t['title'])?></a> (<?=h(cm_status_label($t['status']))?>)<?php endforeach; ?>. Changes here do not change the task.</p><?php endif; ?>
<form method="post" class="form" data-task-form><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h(cm_ver($e, $VER))?>"><input type="hidden" name="action" value="save">
<?php cm_enquiry_fields($pdo, $v, false); ?>
<div class="form-actions"><button type="submit" class="save">Save enquiry</button><a class="cancel" href="convert-enquiry.php?id=<?=(int)$id?>">Cancel</a></div></form>

<section class="panel danger-zone" id="withdraw"><h2 class="panel-title">Withdraw or mark duplicate</h2>
<form method="post" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h(cm_ver($e, $VER))?>"><input type="hidden" name="action" value="close">
<fieldset class="field"><legend>This enquiry is</legend><label class="check"><input type="radio" name="close_kind" value="withdrawn" required<?=$e['status'] === 'withdrawn' ? ' checked' : ''?>> Withdrawn (no longer needed, or entered by mistake)</label><label class="check"><input type="radio" name="close_kind" value="duplicate"<?=$e['status'] === 'duplicate' ? ' checked' : ''?>> A duplicate of another enquiry</label></fieldset>
<div class="pair"><div class="field"><label for="duplicate_of">Duplicate of enquiry #</label><input id="duplicate_of" name="duplicate_of" inputmode="numeric" maxlength="12" value="<?=h($e['duplicate_of'] ?? '')?>"></div><div class="field"><label for="closed_reason">Reason (optional)</label><input id="closed_reason" name="closed_reason" maxlength="255" value="<?=h($e['closed_reason'] ?? '')?>"></div></div>
<?php $openTasks = array_filter($tasks, fn($t) => !in_array(strtolower((string)$t['status']), ['completed', 'cancelled'], true)); if ($openTasks): ?><label class="check"><input type="checkbox" name="cancel_tasks" value="1"> Also cancel its open task<?=count($openTasks) > 1 ? 's' : ''?> (#<?=implode(', #', array_map(fn($t) => (int)$t['id'], $openTasks))?>)</label><?php endif; ?>
<div class="form-actions"><button type="submit" class="secondary" data-close-save>Save</button></div></form>
<?php if (!$tasks): ?>
<form method="post" class="form delete-form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h(cm_ver($e, $VER))?>"><input type="hidden" name="action" value="delete">
<label class="check"><input type="checkbox" name="confirm_delete" value="yes" required> Delete this enquiry permanently (it has no task)</label>
<div class="form-actions"><button type="submit" class="danger">Delete enquiry</button></div></form>
<?php else: ?><p class="helper">Enquiries with a task cannot be deleted; withdraw or mark duplicate instead.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php cm_page_end('enquiries');
