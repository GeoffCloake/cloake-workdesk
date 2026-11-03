<?php
declare(strict_types=1);
/*
 * Task template: new / edit / archive / restore (v0.12), with the pricing panel.
 * Also: action=from_task (make a template from a task, copying its price lines and milestones)
 *       action=retire_proforma (cancel the open pro-forma tasks that were copied into templates and
 *       archive their client — one undoable change).
 * Templates are copied when used, so editing one never changes an enquiry or task made from it.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$FIELDS = ['title', 'instructions', 'task_category', 'task_type', 'assigned_to', 'pricing', 'chargeable', 'estimate_minutes'];
$error = '';
$t = $id ? oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$id]) : array_merge(array_fill_keys(array_merge($FIELDS, ['billing_mode']), null), ['active' => 1, 'from_task_id' => null, 'chargeable' => 'yes', 'pricing' => 'set']);
if ($id && !$t) { http_response_code(404); exit('Template not found.'); }
$classes = cm_task_classes($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        $pdo->beginTransaction();
        if ($action === 'retire_proforma') {
            if (cm_post('confirm', 2) !== '1') throw new InvalidArgumentException('Tick the box to confirm.');
            $closed = cm_closed_status_sql('tk.status');
            $tasks = allRows($pdo, "SELECT tk.id,tk.status,tk.client_id FROM tasks tk JOIN clients c ON c.id=tk.client_id WHERE LOWER(TRIM(c.name)) IN ('pro forma','pro-forma','proforma') AND NOT ($closed) AND tk.completed_at IS NULL AND EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id=tk.id) FOR UPDATE");
            if (!$tasks) throw new InvalidArgumentException('There are no open pro-forma tasks to retire.');
            $rows = [];
            foreach ($tasks as $tk) {
                $pdo->prepare("UPDATE tasks SET status='cancelled' WHERE id=?")->execute([(int)$tk['id']]);
                $rows[] = ['table' => 'tasks', 'id' => (int)$tk['id'], 'changes' => ['status' => ['previous' => $tk['status'], 'current' => 'cancelled']]];
            }
            $clientIds = array_values(array_unique(array_map(fn($x) => (int)$x['client_id'], $tasks)));
            foreach ($clientIds as $cid) {
                $c = oneRow($pdo, 'SELECT id,status FROM clients WHERE id=? FOR UPDATE', [$cid]);
                if ($c && $c['status'] !== 'archive') {
                    $pdo->prepare("UPDATE clients SET status='archive', updated_at=NOW() WHERE id=?")->execute([$cid]);
                    $rows[] = ['table' => 'clients', 'id' => $cid, 'changes' => ['status' => ['previous' => $c['status'], 'current' => 'archive']]];
                }
            }
            cm_audit($pdo, 'proforma_retired', ['client_id' => $clientIds[0] ?? null], 'Retired ' . count($tasks) . ' pro-forma tasks (now task templates) and archived the Pro forma client', ['_rows' => $rows]);
            $pdo->commit();
            header('Location: ' . route('templates', ['retired' => 1]), true, 303); exit;
        }
        if ($action === 'from_task') {
            $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $tk = $taskId ? oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$taskId]) : null;
            if (!$tk) throw new InvalidArgumentException('Task not found.');
            $exists = oneRow($pdo, 'SELECT id FROM task_templates WHERE from_task_id=?', [$taskId]);
            if ($exists) { $pdo->rollBack(); header('Location: edit-template.php?id=' . (int)$exists['id'] . '&made=0', true, 303); exit; }
            $ch = strtolower((string)$tk['chargeable']);
            $pdo->prepare('INSERT INTO task_templates (title,instructions,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode,active,from_task_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,NOW())')
                ->execute([$tk['title'], $tk['instructions'], $tk['task_category'], $tk['task_type'], $tk['assigned_to'], in_array($tk['pricing'], ['hourly', 'set', 'web_service'], true) ? $tk['pricing'] : null, str_contains($ch, 'yes') ? 'yes' : (str_contains($ch, 'no') ? 'no' : null), $tk['estimate_minutes'] ?: null, $tk['billing_mode'] ?? null, $taskId]);
            $newId = (int)$pdo->lastInsertId();
            $n = cm_copy_pricing($pdo, 'task_id', $taskId, ['template_id' => $newId]);
            // A set fee with no price line becomes the template's line (as the pro-forma copy does).
            if (!$n['lines'] && $tk['pricing'] === 'set' && (float)$tk['agreed_fee_ex_gst'] > 0)
                $pdo->prepare("INSERT INTO breakdown_items (template_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at) VALUES (?,?,1,?,'set','proposed',1,NOW())")->execute([$newId, $tk['title'], $tk['agreed_fee_ex_gst']]);
            cm_audit($pdo, 'template_created', ['task_id' => $taskId], 'Template made from task #' . $taskId . ': ' . $tk['title'], ['row_id' => $newId, 'from_task_id' => $taskId]);
            $pdo->commit();
            header('Location: edit-template.php?id=' . $newId . '&made=1', true, 303); exit;
        }
        if ($action === 'archive' || $action === 'restore') {
            if (!$id) throw new InvalidArgumentException('Template not found.');
            $old = oneRow($pdo, 'SELECT * FROM task_templates WHERE id=? FOR UPDATE', [$id]);
            $changes = cm_changes($old, ['active' => $action === 'archive' ? 0 : 1]);
            if ($changes) {
                $pdo->prepare('UPDATE task_templates SET active=?, updated_at=NOW() WHERE id=?')->execute([$action === 'archive' ? 0 : 1, $id]);
                cm_audit($pdo, 'template_updated', [], 'Template “' . $old['title'] . '” ' . ($action === 'archive' ? 'archived' : 'restored'), ['row_id' => $id] + $changes);
            }
            $pdo->commit();
            header('Location: edit-template.php?id=' . $id, true, 303); exit;
        }
        // save
        $raw = [];
        foreach (['title' => 255, 'instructions' => 30000, 'task_category' => 80, 'task_type' => 120, 'assigned_to' => 255, 'pricing' => 20, 'chargeable' => 10, 'est_hours' => 4, 'est_minutes' => 2] as $f => $max) $raw[$f] = cm_post($f, $max);
        $old = $id ? oneRow($pdo, 'SELECT * FROM task_templates WHERE id=? FOR UPDATE', [$id]) : null;
        if ($id) cm_check_ver($old, $FIELDS);
        if (mb_strlen($raw['title']) < 3) throw new InvalidArgumentException('Title is required (3–255 characters).');
        $keep = fn(string $f, string $v) => $old && $v === (string)$old[$f];
        if ($raw['task_category'] !== '' && !isset($classes[$raw['task_category']]) && !$keep('task_category', $raw['task_category'])) throw new InvalidArgumentException('Choose a category from the list.');
        if ($raw['pricing'] !== '' && !isset(CM_PRICING_MODES[$raw['pricing']])) throw new InvalidArgumentException('Invalid pricing type.');
        if (!in_array($raw['chargeable'], ['', 'yes', 'no'], true)) throw new InvalidArgumentException('Invalid chargeable value.');
        foreach (['est_hours', 'est_minutes'] as $k) if ($raw[$k] !== '' && !ctype_digit($raw[$k])) throw new InvalidArgumentException('Time estimate must be whole hours and minutes.');
        if ((int)$raw['est_minutes'] > 59) throw new InvalidArgumentException('Estimate minutes must be 0–59.');
        $est = (int)$raw['est_hours'] * 60 + (int)$raw['est_minutes'];
        $who = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $raw['assigned_to'])), fn($x) => $x !== '' && preg_match('/^[\p{L}0-9 ._\'-]{1,40}$/u', $x))));
        $new = ['title' => $raw['title'], 'instructions' => cm_nullable($raw['instructions']), 'task_category' => cm_nullable($raw['task_category']), 'task_type' => cm_nullable($raw['task_type']),
            'assigned_to' => $who ? implode(', ', $who) : null, 'pricing' => cm_nullable($raw['pricing']), 'chargeable' => cm_nullable($raw['chargeable']), 'estimate_minutes' => $est ?: null];
        if ($id) {
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE task_templates SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                cm_audit($pdo, 'template_updated', [], 'Template “' . $new['title'] . '” updated: ' . implode(', ', array_keys($changes)), ['row_id' => $id] + $changes);
            }
            $pdo->commit();
            header('Location: edit-template.php?id=' . $id . '&saved=' . ($changes ? 1 : 0), true, 303); exit;
        }
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO task_templates (' . implode(',', $cols) . ',active,created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',1,NOW())')->execute(array_values($new));
        $newId = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'template_created', [], 'Template created: ' . $new['title'], ['row_id' => $newId] + array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        header('Location: edit-template.php?id=' . $newId . '&saved=1#pricing', true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'template #' . $id);
        if ($action === 'retire_proforma') { $_SESSION['cm_undo_error'] = $error; header('Location: ' . route('templates'), true, 303); exit; }
        if ($action === 'from_task') { $_SESSION['cm_undo_error'] = $error; header('Location: ' . route('task', ['id' => (int)($_POST['task_id'] ?? 0)]), true, 303); exit; }
        if ($action === 'save') {
            foreach (['title', 'instructions', 'task_category', 'task_type', 'assigned_to', 'pricing', 'chargeable'] as $f) if (is_string($_POST[$f] ?? null)) $t[$f] = trim($_POST[$f]);
            $t['estimate_minutes'] = (int)($_POST['est_hours'] ?? 0) * 60 + (int)($_POST['est_minutes'] ?? 0);
        }
    }
}
if ($id) $t = array_merge(oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$id]), $error ? array_intersect_key($t, array_flip($FIELDS)) : []);
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$id]), $FIELDS) : '';
$est = (int)($t['estimate_minutes'] ?? 0);
$types = $classes[$t['task_category'] ?? ''] ?? []; if (($t['task_type'] ?? '') !== '' && !in_array($t['task_type'], $types, true)) $types[] = $t['task_type'];
$src = $id && $t['from_task_id'] ? oneRow($pdo, 'SELECT t.id,t.title,c.name client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?', [(int)$t['from_task_id']]) : null;
cm_page_start($id ? 'Template' : 'New template', 'templates', ['back' => [route('templates'), 'Task templates'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1' && !cm_undo_pending()): ?><div class="success" role="status">Saved.</div><?php elseif (($_GET['saved'] ?? '') === '0'): ?><div class="success" role="status">No changes.</div><?php endif; ?>
<?php if (($_GET['made'] ?? '') === '1'): ?><div class="success" role="status">Template made from the task, with its price lines and milestones. Change anything below.</div><?php elseif (($_GET['made'] ?? '') === '0'): ?><div class="notice">A template was already made from that task — here it is.</div><?php endif; ?>
<?php if ($id && !(int)$t['active']): ?><p class="notice">This template is archived: it can't be chosen for new enquiries. Enquiries and tasks made from it are unaffected.</p><?php endif; ?>
<?php if ($src): ?><p class="muted small">Copied from <a href="<?=h(route('task', ['id' => $src['id']]))?>">task #<?=(int)$src['id']?></a><?=$src['client_name'] ? ' (' . h($src['client_name']) . ')' : ''?>.</p><?php endif; ?>
<form method="post" class="form" data-task-form><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>"><input type="hidden" name="action" value="save">
<div class="field"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=h($t['title'] ?? '')?>"></div>
<div class="field"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=h($t['instructions'] ?? '')?></textarea></div>
<div class="pair"><div class="field"><label for="task_category">Category</label><select id="task_category" name="task_category" data-category-select><option value="">—</option><?php foreach (array_keys($classes) as $c): ?><option value="<?=h($c)?>"<?=$c === ($t['task_category'] ?? '') ? ' selected' : ''?>><?=h($c)?></option><?php endforeach; ?><?php if (($t['task_category'] ?? '') !== '' && !isset($classes[$t['task_category']])): ?><option value="<?=h($t['task_category'])?>" selected><?=h($t['task_category'])?> (current)</option><?php endif; ?></select></div>
<div class="field"><label for="task_type">Type</label><select id="task_type" name="task_type" data-type-select data-types="<?=h(json_encode($classes, JSON_UNESCAPED_UNICODE))?>"><option value="">—</option><?php foreach ($types as $ty): ?><option value="<?=h($ty)?>"<?=$ty === ($t['task_type'] ?? '') ? ' selected' : ''?>><?=h($ty)?></option><?php endforeach; ?></select></div></div>
<div class="field"><label for="assigned_to">Action for <span class="opt">(comma-separated)</span></label><input id="assigned_to" name="assigned_to" maxlength="255" value="<?=h($t['assigned_to'] ?? '')?>" placeholder="e.g. roselyn, geoff"></div>
<div class="pair"><div class="field"><label for="pricing">Pricing</label><select id="pricing" name="pricing"><option value="">—</option><?php foreach (CM_PRICING_MODES as $k => $l): ?><option value="<?=h($k)?>"<?=($t['pricing'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="chargeable">Chargeable</label><select id="chargeable" name="chargeable"><?php foreach (['' => '—', 'yes' => 'Yes', 'no' => 'No'] as $k => $l): ?><option value="<?=h($k)?>"<?=(string)($t['chargeable'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div></div>
<fieldset class="field"><legend class="sub">Time estimate</legend><div class="double"><div><label for="est_hours">Hours</label><input id="est_hours" name="est_hours" inputmode="numeric" maxlength="4" value="<?=$est ? intdiv($est, 60) : ''?>"></div><div><label for="est_minutes">Minutes</label><input id="est_minutes" name="est_minutes" inputmode="numeric" maxlength="2" value="<?=$est ? $est % 60 : ''?>"></div></div></fieldset>
<div class="form-actions"><button type="submit" class="save"><?=$id ? 'Save template' : 'Create template'?></button><a class="cancel" href="<?=h(route('templates'))?>">Cancel</a></div></form>
<?php if ($id): ?>
<?php cm_pricing_panel($pdo, cm_owner($pdo, 'template', $id)); ?>
<section class="panel"><form method="post" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="<?=(int)$t['active'] ? 'archive' : 'restore'?>">
<p class="helper"><?=(int)$t['active'] ? 'Archive it when it is no longer used. It stays visible where it was used.' : 'Restore it to choose it for new enquiries again.'?></p>
<button type="submit" class="secondary"><?=(int)$t['active'] ? 'Archive template' : 'Restore template'?></button></form></section>
<?php else: ?><p class="helper">Add price lines and milestones after creating the template.</p><?php endif; ?>
<?php cm_page_end('templates');
