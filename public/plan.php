<?php
declare(strict_types=1);
/*
 * Planned tasks on an enquiry (v0.12). A plan becomes a real task when it is ticked on the enquiry's
 * "Create tasks". POST action:
 *   add_template  (enquiry_id, template_id)  copy a template: fields + price lines + milestones (for this plan)
 *   add_blank     (enquiry_id, title)
 *   save          (id)                       edit an unconverted plan (audited, undoable)
 *   remove        (id)                       only before its task exists; its own price lines and milestones go too
 * GET ?id= shows the edit form. Imported enquiries are read-only.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$FIELDS = ['title', 'instructions', 'task_category', 'task_type', 'assigned_to', 'pricing', 'chargeable', 'estimate_minutes'];
$classes = cm_task_classes($pdo);
$error = '';
$enquiryOf = function (int $eid) use ($pdo): array {
    $e = oneRow($pdo, 'SELECT * FROM enquiries WHERE id=? FOR UPDATE', [$eid]);
    if (!$e) throw new InvalidArgumentException('Enquiry not found.');
    if ($e['legacy_source_id'] !== null) throw new InvalidArgumentException('Imported enquiries are read-only.');
    return $e;
};
$p = $id ? oneRow($pdo, 'SELECT * FROM enquiry_task_plans WHERE id=?', [$id]) : null;
if ($id && !$p) { http_response_code(404); exit('Planned task not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    $eid = $p ? (int)$p['enquiry_id'] : (filter_var($_POST['enquiry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    try {
        $pdo->beginTransaction();
        $e = $enquiryOf($eid);
        $ids = ['client_id' => $e['client_id'], 'enquiry_id' => $eid];
        if ($action === 'add_template') {
            $tid = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if (!$tid) throw new InvalidArgumentException('Choose a template.');
            $planId = cm_plan_from_template($pdo, $eid, $tid);
            $tt = oneRow($pdo, 'SELECT title FROM task_templates WHERE id=?', [$tid]);
            cm_audit($pdo, 'plan_added', $ids, 'Planned task added from template: ' . $tt['title'], ['row_id' => $planId, 'template_id' => $tid]);
        } elseif ($action === 'add_blank') {
            $title = cm_post('title', 255);
            if (mb_strlen($title) < 3) throw new InvalidArgumentException('Give the planned task a title (3–255 characters).');
            $order = (int)(oneRow($pdo, 'SELECT COALESCE(MAX(plan_order),0) m FROM enquiry_task_plans WHERE enquiry_id=?', [$eid])['m'] ?? 0) + 1;
            $pdo->prepare('INSERT INTO enquiry_task_plans (enquiry_id,plan_order,title,task_category,task_type,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$eid, $order, $title, $e['task_category'], $e['task_type']]);
            cm_audit($pdo, 'plan_added', $ids, 'Planned task added: ' . $title, ['row_id' => (int)$pdo->lastInsertId()]);
        } elseif ($action === 'save' || $action === 'remove') {
            $old = oneRow($pdo, 'SELECT * FROM enquiry_task_plans WHERE id=? FOR UPDATE', [$id]);
            if (!$old) throw new InvalidArgumentException('Planned task not found.');
            if ($old['task_id']) throw new InvalidArgumentException('Task #' . $old['task_id'] . ' has been created from this plan; change the task instead.');
            if ($action === 'remove') {
                if (cm_post('confirm', 2) !== '1') throw new InvalidArgumentException('Tick the box to confirm removing the planned task.');
                $lines = allRows($pdo, 'SELECT * FROM breakdown_items WHERE plan_id=? AND task_id IS NULL', [$id]);
                $mss = allRows($pdo, 'SELECT * FROM billing_milestones WHERE plan_id=? AND task_id IS NULL', [$id]);
                $pdo->prepare('DELETE FROM breakdown_items WHERE plan_id=? AND task_id IS NULL')->execute([$id]);
                $pdo->prepare('DELETE FROM billing_milestones WHERE plan_id=? AND task_id IS NULL')->execute([$id]);
                $pdo->prepare('DELETE FROM enquiry_task_plans WHERE id=?')->execute([$id]);
                cm_audit($pdo, 'plan_removed', $ids, 'Planned task removed: ' . $old['title'] . ($lines ? ' (with ' . count($lines) . ' price line' . (count($lines) === 1 ? '' : 's') . ')' : ''), ['plan' => $old, 'price_lines' => $lines, 'milestones' => $mss]);
                $pdo->commit();
                header('Location: convert-enquiry.php?id=' . $eid . '#plans', true, 303); exit;
            }
            cm_check_ver($old, $FIELDS);
            $raw = [];
            foreach (['title' => 255, 'instructions' => 30000, 'task_category' => 80, 'task_type' => 120, 'assigned_to' => 255, 'pricing' => 20, 'chargeable' => 10, 'est_hours' => 4, 'est_minutes' => 2] as $f => $max) $raw[$f] = cm_post($f, $max);
            if (mb_strlen($raw['title']) < 3) throw new InvalidArgumentException('Title is required (3–255 characters).');
            if ($raw['task_category'] !== '' && !isset($classes[$raw['task_category']]) && $raw['task_category'] !== (string)$old['task_category']) throw new InvalidArgumentException('Choose a category from the list.');
            if ($raw['pricing'] !== '' && !isset(CM_PRICING_MODES[$raw['pricing']])) throw new InvalidArgumentException('Invalid pricing type.');
            if (!in_array($raw['chargeable'], ['', 'yes', 'no'], true)) throw new InvalidArgumentException('Invalid chargeable value.');
            foreach (['est_hours', 'est_minutes'] as $k) if ($raw[$k] !== '' && !ctype_digit($raw[$k])) throw new InvalidArgumentException('Time estimate must be whole hours and minutes.');
            if ((int)$raw['est_minutes'] > 59) throw new InvalidArgumentException('Estimate minutes must be 0–59.');
            $est = (int)$raw['est_hours'] * 60 + (int)$raw['est_minutes'];
            $who = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $raw['assigned_to'])), fn($x) => $x !== '' && preg_match('/^[\p{L}0-9 ._\'-]{1,40}$/u', $x))));
            $new = ['title' => $raw['title'], 'instructions' => cm_nullable($raw['instructions']), 'task_category' => cm_nullable($raw['task_category']), 'task_type' => cm_nullable($raw['task_type']),
                'assigned_to' => $who ? implode(', ', $who) : null, 'pricing' => cm_nullable($raw['pricing']), 'chargeable' => cm_nullable($raw['chargeable']), 'estimate_minutes' => $est ?: null];
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE enquiry_task_plans SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                cm_audit($pdo, 'plan_updated', $ids, 'Planned task “' . $new['title'] . '” updated: ' . implode(', ', array_keys($changes)), ['row_id' => $id] + $changes);
            }
        } else throw new InvalidArgumentException('Unknown action.');
        $pdo->commit();
        header('Location: convert-enquiry.php?id=' . $eid . '#plans', true, 303); exit;
    } catch (Throwable $ex) {
        $error = cm_edit_error($ex, $pdo, 'plan #' . $id);
        if (!$p) { $_SESSION['cm_plan_error'] = ['enquiry' => $eid, 'msg' => $error]; header('Location: convert-enquiry.php?id=' . $eid . '#plans', true, 303); exit; }
        foreach (['title', 'instructions', 'task_category', 'task_type', 'assigned_to', 'pricing', 'chargeable'] as $f) if (is_string($_POST[$f] ?? null)) $p[$f] = trim($_POST[$f]);
    }
}
if (!$p) { header('Location: ' . route('enquiries'), true, 303); exit; }
$e = oneRow($pdo, 'SELECT id,title,legacy_source_id FROM enquiries WHERE id=?', [(int)$p['enquiry_id']]);
$fresh = oneRow($pdo, 'SELECT * FROM enquiry_task_plans WHERE id=?', [$id]);
$est = (int)($p['estimate_minutes'] ?? 0);
$types = $classes[$p['task_category'] ?? ''] ?? []; if (($p['task_type'] ?? '') !== '' && !in_array($p['task_type'], $types, true)) $types[] = $p['task_type'];
$back = 'convert-enquiry.php?id=' . (int)$e['id'] . '#plans';
cm_page_start('Planned task', 'enquiries', ['back' => [$back, 'Enquiry #' . (int)$e['id']], 'bodyClass' => 'form-page']);
?>
<p class="muted small">On enquiry #<?=(int)$e['id']?>: <?=h($e['title'])?><?=$p['template_id'] ? ' · from a template' : ''?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($fresh['task_id']): ?><p class="notice">Task <a href="<?=h(route('task', ['id' => $fresh['task_id']]))?>">#<?=(int)$fresh['task_id']?></a> has been created from this plan. Change the task instead.</p>
<?php elseif ($e['legacy_source_id'] !== null): ?><p class="notice">This enquiry was imported, so it is read-only.</p>
<?php else: ?>
<form method="post" action="plan.php" class="form" data-task-form><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h(cm_ver($fresh, $FIELDS))?>"><input type="hidden" name="action" value="save">
<div class="field"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=h($p['title'])?>"></div>
<div class="field"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=h($p['instructions'] ?? '')?></textarea></div>
<div class="pair"><div class="field"><label for="task_category">Category</label><select id="task_category" name="task_category" data-category-select><option value="">—</option><?php foreach (array_keys($classes) as $c): ?><option value="<?=h($c)?>"<?=$c === ($p['task_category'] ?? '') ? ' selected' : ''?>><?=h($c)?></option><?php endforeach; ?><?php if (($p['task_category'] ?? '') !== '' && !isset($classes[$p['task_category']])): ?><option value="<?=h($p['task_category'])?>" selected><?=h($p['task_category'])?> (current)</option><?php endif; ?></select></div>
<div class="field"><label for="task_type">Type</label><select id="task_type" name="task_type" data-type-select data-types="<?=h(json_encode($classes, JSON_UNESCAPED_UNICODE))?>"><option value="">—</option><?php foreach ($types as $ty): ?><option value="<?=h($ty)?>"<?=$ty === ($p['task_type'] ?? '') ? ' selected' : ''?>><?=h($ty)?></option><?php endforeach; ?></select></div></div>
<div class="field"><label for="assigned_to">Action for <span class="opt">(comma-separated)</span></label><input id="assigned_to" name="assigned_to" maxlength="255" value="<?=h($p['assigned_to'] ?? '')?>"></div>
<div class="pair"><div class="field"><label for="pricing">Pricing</label><select id="pricing" name="pricing"><option value="">—</option><?php foreach (CM_PRICING_MODES as $k => $l): ?><option value="<?=h($k)?>"<?=($p['pricing'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="chargeable">Chargeable</label><select id="chargeable" name="chargeable"><?php foreach (['' => '—', 'yes' => 'Yes', 'no' => 'No'] as $k => $l): ?><option value="<?=h($k)?>"<?=(string)($p['chargeable'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div></div>
<fieldset class="field"><legend class="sub">Time estimate</legend><div class="double"><div><label for="est_hours">Hours</label><input id="est_hours" name="est_hours" inputmode="numeric" maxlength="4" value="<?=$est ? intdiv($est, 60) : ''?>"></div><div><label for="est_minutes">Minutes</label><input id="est_minutes" name="est_minutes" inputmode="numeric" maxlength="2" value="<?=$est ? $est % 60 : ''?>"></div></div></fieldset>
<p class="helper">Its price lines and milestones are on the enquiry, marked “Planned: <?=h($p['title'])?>”.</p>
<div class="form-actions"><button type="submit" class="save">Save planned task</button><a class="cancel" href="<?=h($back)?>">Cancel</a></div></form>
<section class="panel danger-zone"><form method="post" action="plan.php" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="remove">
<label class="check"><input type="checkbox" name="confirm" value="1" required> Remove this planned task and its own price lines and milestones</label>
<div class="form-actions"><button type="submit" class="danger">Remove planned task</button></div></form></section>
<?php endif; ?>
<?php cm_page_end('enquiries');
