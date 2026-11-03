<?php
declare(strict_types=1);
/*
 * Pricing module write page (v0.12). One endpoint for the panel on enquiries, tasks and templates.
 *   GET  ?owner_type=&owner_id=&line=ID   edit one price line      (&ms=ID edits a milestone)
 *   POST action = line_add | line_save | line_delete | line_up | billing_save
 *               | ms_add | ms_save | ms_delete | ms_ready | ms_unready | ms_void | ms_restore
 * Every change is audited; edits (line_save, ms_save, billing_save) are undoable like other saves.
 * Imported enquiries are read-only. Nothing is invoiced or sent anywhere.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pricing-module.php';
cm_require_login();
$pdo = cm_pdo();
$type = (string)($_POST['owner_type'] ?? $_GET['owner_type'] ?? '');
$oid = filter_var($_POST['owner_id'] ?? $_GET['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!in_array($type, ['enquiry', 'task', 'template'], true) || !$oid) { http_response_code(400); exit('Invalid request.'); }
$o = cm_owner($pdo, $type, $oid);
if (!$o) { http_response_code(404); exit('Not found.'); }
$col = cm_owner_col($type);
$error = '';
$rowOf = function (string $table, string $key) use ($pdo, $col, $oid): array {
    $id = filter_var($_POST[$key] ?? $_GET[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $r = $id ? oneRow($pdo, "SELECT * FROM `$table` WHERE id=? AND `$col`=?" . ($_SERVER['REQUEST_METHOD'] === 'POST' ? ' FOR UPDATE' : ''), [$id, $oid]) : null;
    if (!$r) throw new InvalidArgumentException('That item is no longer here. Reload the page.');
    return $r;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    $ids = cm_owner_ids($o);
    try {
        if (!$o['editable']) throw new InvalidArgumentException('Imported enquiries are read-only.');
        $pdo->beginTransaction();
        $what = $o['type'] === 'template' ? 'template “' . $o['row']['title'] . '”' : $o['label'];
        switch ($action) {
            case 'line_add':
            case 'line_save':
                $v = cm_line_posted();
                [$planId, $taskId] = $o['type'] === 'enquiry' ? cm_for_resolve($pdo, $o, $v['for']) : [null, null];
                $new = ['description' => $v['description'], 'quantity' => $v['quantity'], 'unit_price_ex_gst' => $v['unit_price'], 'pricing_mode' => $v['pricing_mode'], 'account_name' => cm_nullable($v['account_name'])];
                if ($o['type'] === 'enquiry') { $new['plan_id'] = $planId; $new['task_id'] = $taskId; }
                if ($action === 'line_add') {
                    $order = (int)(oneRow($pdo, "SELECT COALESCE(MAX(line_order),0) m FROM breakdown_items WHERE `$col`=?", [$oid])['m'] ?? 0) + 1;
                    $cols = array_merge([$col], array_keys($new), ['status', 'line_order', 'created_at']);
                    $pdo->prepare('INSERT INTO breakdown_items (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols) - 1, '?')) . ',NOW())')
                        ->execute([$oid, ...array_values($new), 'proposed', $order]);
                    $lid = (int)$pdo->lastInsertId();
                    cm_audit($pdo, 'price_line_added', $ids, 'Price line added to ' . $what . ': ' . mb_substr($v['description'], 0, 80) . ' ' . cm_money((float)$v['quantity'] * (float)$v['unit_price']), ['row_id' => $lid] + $new);
                } else {
                    $old = $rowOf('breakdown_items', 'line_id');
                    $changes = cm_changes($old, $new);
                    // Numbers compare numerically (1 = 1.000).
                    foreach (['quantity', 'unit_price_ex_gst'] as $f) if (isset($changes[$f]) && (float)$changes[$f]['previous'] === (float)$changes[$f]['current']) unset($changes[$f]);
                    if ($changes) {
                        $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                        $pdo->prepare("UPDATE breakdown_items SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), (int)$old['id']]);
                        cm_audit($pdo, 'price_line_updated', $ids, 'Price line on ' . $what . ' updated: ' . implode(', ', array_keys($changes)), ['row_id' => (int)$old['id']] + $changes);
                    }
                }
                break;
            case 'line_delete':
                $old = $rowOf('breakdown_items', 'line_id');
                $pdo->prepare('DELETE FROM breakdown_items WHERE id=?')->execute([(int)$old['id']]);
                cm_audit($pdo, 'price_line_deleted', $ids, 'Price line removed from ' . $what . ': ' . mb_substr((string)$old['description'], 0, 80), ['line' => $old]);
                break;
            case 'line_up':
                $old = $rowOf('breakdown_items', 'line_id');
                $all = cm_price_lines($pdo, $o);
                $pos = array_search((int)$old['id'], array_map(fn($l) => (int)$l['id'], $all), true);
                if ($pos) {
                    [$all[$pos - 1], $all[$pos]] = [$all[$pos], $all[$pos - 1]];
                    foreach ($all as $i => $l) $pdo->prepare('UPDATE breakdown_items SET line_order=? WHERE id=?')->execute([$i + 1, (int)$l['id']]);
                }
                break;
            case 'billing_save':
                $mode = cm_post('billing_mode', 20);
                if (!isset(CM_BILLING_MODES[$mode]) && $mode !== (string)($o['row']['billing_mode'] ?? '')) throw new InvalidArgumentException('Invalid billing choice.');
                $table = ['enquiry' => 'enquiries', 'task' => 'tasks', 'template' => 'task_templates'][$type];
                $cur = oneRow($pdo, "SELECT id,billing_mode FROM `$table` WHERE id=? FOR UPDATE", [$oid]);
                $changes = cm_changes($cur, ['billing_mode' => cm_nullable($mode)]);
                if ($changes) {
                    $pdo->prepare("UPDATE `$table` SET billing_mode=? WHERE id=?")->execute([cm_nullable($mode), $oid]);
                    $kind = ['enquiry' => 'enquiry_updated', 'task' => 'task_updated', 'template' => 'template_updated'][$type];
                    cm_audit($pdo, $kind, $ids, ucfirst($what) . ': billing set to ' . strtolower(CM_BILLING_MODES[$mode] ?? $mode), ($type === 'template' ? ['row_id' => $oid] : []) + $changes);
                }
                break;
            case 'ms_add':
            case 'ms_save':
                $v = cm_ms_posted($o['type'] === 'enquiry' || !empty($o['enquiry_id']));
                [$planId, $taskId] = $o['type'] === 'enquiry' ? cm_for_resolve($pdo, $o, $v['for']) : [null, null];
                $new = ['label' => $v['label'], 'amount_ex_gst' => cm_nullable($v['amount']), 'percent' => cm_nullable($v['percent']), 'trigger_kind' => $v['trigger_kind'], 'due_date' => $v['due_date']];
                if ($o['type'] === 'enquiry') { $new['plan_id'] = $planId; $new['task_id'] = $taskId; }
                if ($action === 'ms_add') {
                    $order = (int)(oneRow($pdo, "SELECT COALESCE(MAX(milestone_order),0) m FROM billing_milestones WHERE `$col`=?", [$oid])['m'] ?? 0) + 1;
                    $cols = array_merge([$col], array_keys($new), ['milestone_order', 'status', 'created_at']);
                    $pdo->prepare('INSERT INTO billing_milestones (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols) - 1, '?')) . ',NOW())')
                        ->execute([$oid, ...array_values($new), $order, 'planned']);
                    $mid = (int)$pdo->lastInsertId();
                    cm_audit($pdo, 'milestone_added', $ids, 'Milestone added to ' . $what . ': ' . $v['label'], ['row_id' => $mid] + $new);
                } else {
                    $old = $rowOf('billing_milestones', 'ms_id');
                    $changes = cm_changes($old, $new);
                    foreach (['amount_ex_gst', 'percent'] as $f) if (isset($changes[$f]) && $changes[$f]['previous'] !== null && $changes[$f]['current'] !== null && (float)$changes[$f]['previous'] === (float)$changes[$f]['current']) unset($changes[$f]);
                    if ($changes) {
                        $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                        $pdo->prepare("UPDATE billing_milestones SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), (int)$old['id']]);
                        cm_audit($pdo, 'milestone_updated', $ids, 'Milestone “' . $old['label'] . '” on ' . $what . ' updated: ' . implode(', ', array_keys($changes)), ['row_id' => (int)$old['id']] + $changes);
                    }
                }
                break;
            case 'ms_delete':
                $old = $rowOf('billing_milestones', 'ms_id');
                if (cm_ms_state($pdo, $old)['state'] === 'ready') throw new InvalidArgumentException('This milestone is ready to invoice, so it can’t be removed. Void it instead (with a reason).');
                $pdo->prepare('DELETE FROM billing_milestones WHERE id=?')->execute([(int)$old['id']]);
                cm_audit($pdo, 'milestone_deleted', $ids, 'Milestone removed from ' . $what . ': ' . $old['label'], ['milestone' => $old]);
                break;
            case 'ms_ready':
            case 'ms_unready':
                $old = $rowOf('billing_milestones', 'ms_id');
                if ($old['trigger_kind'] !== 'manual') throw new InvalidArgumentException('Only a “when marked ready” milestone is marked by hand.');
                $by = cm_post('marked_ready_by', 100);
                if ($action === 'ms_ready' && $by === '') throw new InvalidArgumentException('Enter who is marking it ready.');
                $new = $action === 'ms_ready' ? ['marked_ready_at' => date('Y-m-d H:i:s'), 'marked_ready_by' => $by] : ['marked_ready_at' => null, 'marked_ready_by' => null];
                $changes = cm_changes($old, $new);
                if ($changes) {
                    $pdo->prepare('UPDATE billing_milestones SET marked_ready_at=?, marked_ready_by=?, updated_at=NOW() WHERE id=?')->execute([$new['marked_ready_at'], $new['marked_ready_by'], (int)$old['id']]);
                    cm_audit($pdo, 'milestone_updated', $ids, 'Milestone “' . $old['label'] . '” ' . ($action === 'ms_ready' ? 'marked ready by ' . $by : 'set back to planned'), ['row_id' => (int)$old['id']] + $changes);
                }
                if ($by !== '') $_SESSION['cm_last_staff'] = $by;
                break;
            case 'ms_void':
            case 'ms_restore':
                $old = $rowOf('billing_milestones', 'ms_id');
                $reason = cm_post('void_reason', 255);
                if ($action === 'ms_void' && $reason === '') throw new InvalidArgumentException('Give a reason for voiding the milestone.');
                $new = $action === 'ms_void' ? ['status' => 'void', 'void_reason' => $reason] : ['status' => 'planned', 'void_reason' => null];
                $changes = cm_changes($old, $new);
                if ($changes) {
                    $pdo->prepare('UPDATE billing_milestones SET status=?, void_reason=?, updated_at=NOW() WHERE id=?')->execute([$new['status'], $new['void_reason'], (int)$old['id']]);
                    cm_audit($pdo, 'milestone_updated', $ids, 'Milestone “' . $old['label'] . '” ' . ($action === 'ms_void' ? 'voided: ' . $reason : 'restored'), ['row_id' => (int)$old['id']] + $changes);
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        $pdo->commit();
        header('Location: ' . $o['url'] . (str_contains($o['url'], '?') ? '&' : '?') . 'priced=1#pricing', true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'pricing ' . $type . ' #' . $oid);
        // Panel actions return to the record with the message; edit-page saves stay on the edit form.
        if (!in_array($action, ['line_save', 'ms_save', 'line_delete', 'ms_delete', 'ms_void', 'ms_ready', 'ms_unready', 'ms_restore'], true) || (!isset($_POST['line_id']) && !isset($_POST['ms_id']))) {
            $_SESSION['cm_pricing_error'] = ['key' => $type . ':' . $oid, 'msg' => $error];
            header('Location: ' . $o['url'] . (str_contains($o['url'], '?') ? '&' : '?') . 'priced=0#pricing', true, 303); exit;
        }
    }
}

// ---- edit forms (GET, or a failed save) ----
$choices = cm_for_choices($pdo, $o);
$hasEnquiry = $o['type'] === 'enquiry' || !empty($o['enquiry_id']);
try {
    $line = isset($_GET['line']) || isset($_POST['line_id']) ? $rowOf('breakdown_items', isset($_GET['line']) ? 'line' : 'line_id') : null;
    $ms = !$line && (isset($_GET['ms']) || isset($_POST['ms_id'])) ? $rowOf('billing_milestones', isset($_GET['ms']) ? 'ms' : 'ms_id') : null;
} catch (InvalidArgumentException $e) { header('Location: ' . $o['url'], true, 303); exit; }
if (!$line && !$ms) { header('Location: ' . $o['url'], true, 303); exit; }
if (!$o['editable']) { header('Location: ' . $o['url'], true, 303); exit; }
if ($error && $line) foreach (['description' => 'description', 'quantity' => 'quantity', 'unit_price_ex_gst' => 'unit_price', 'pricing_mode' => 'pricing_mode', 'account_name' => 'account_name'] as $k => $p) if (is_string($_POST[$p] ?? null)) $line[$k] = trim($_POST[$p]);
if ($error && $ms) foreach (['label' => 'label', 'amount_ex_gst' => 'amount', 'percent' => 'percent', 'trigger_kind' => 'trigger_kind', 'due_date' => 'due_date'] as $k => $p) if (is_string($_POST[$p] ?? null)) $ms[$k] = trim($_POST[$p]);
$hid = fn(string $action) => '<input type="hidden" name="owner_type" value="' . h($type) . '"><input type="hidden" name="owner_id" value="' . (int)$oid . '"><input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">' . ($line ? '<input type="hidden" name="line_id" value="' . (int)$line['id'] . '">' : '<input type="hidden" name="ms_id" value="' . (int)$ms['id'] . '">');
$ownerName = $o['type'] === 'template' ? 'Template: ' . $o['row']['title'] : $o['label'] . ': ' . ($o['row']['title'] ?? '');
cm_page_start($line ? 'Edit price line' : 'Edit milestone', $type === 'task' ? 'tasks' : ($type === 'template' ? 'templates' : 'enquiries'), ['back' => [$o['url'] . '#pricing', $o['type'] === 'template' ? 'Template' : $o['label']], 'bodyClass' => 'form-page']);
?>
<p class="muted small"><?=h($ownerName)?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($line): ?>
<form method="post" action="pricing.php" class="form"><?=$hid('line_save')?>
<?php cm_line_fields($line, $choices, 'e'); ?>
<div class="form-actions"><button type="submit" class="save">Save line</button><a class="cancel" href="<?=h($o['url'])?>#pricing">Cancel</a></div></form>
<section class="panel danger-zone"><form method="post" action="pricing.php" class="form"><?=$hid('line_delete')?><label class="check"><input type="checkbox" name="confirm" value="1" required> Remove this price line</label><div class="form-actions"><button type="submit" class="danger">Remove line</button></div></form></section>
<?php else: $st = cm_ms_state($pdo, $ms); ?>
<p class="notice">Now: <strong><?=h(['planned' => 'Planned', 'ready' => 'Ready to invoice', 'void' => 'Void', 'template' => 'In template'][$st['state']])?></strong> — <?=h($st['why'])?></p>
<form method="post" action="pricing.php" class="form"><?=$hid('ms_save')?>
<?php cm_ms_fields($ms, $choices, $hasEnquiry, 'e'); ?>
<div class="form-actions"><button type="submit" class="save">Save milestone</button><a class="cancel" href="<?=h($o['url'])?>#pricing">Cancel</a></div></form>
<?php if ($ms['trigger_kind'] === 'manual' && $ms['status'] !== 'void' && $o['type'] !== 'template'): ?>
<section class="panel"><?php if (!$ms['marked_ready_at']): ?><form method="post" action="pricing.php" class="form"><?=$hid('ms_ready')?><div class="field"><label for="mrb">Marked ready by</label><input id="mrb" name="marked_ready_by" maxlength="100" required value="<?=h((string)($_SESSION['cm_last_staff'] ?? ''))?>"></div><div class="form-actions"><button type="submit" class="secondary">Mark ready to invoice</button></div></form>
<?php else: ?><form method="post" action="pricing.php" class="form"><?=$hid('ms_unready')?><p>Marked ready <?=h(niceDate($ms['marked_ready_at']))?><?=$ms['marked_ready_by'] ? ' by ' . h($ms['marked_ready_by']) : ''?>.</p><div class="form-actions"><button type="submit" class="secondary">Set back to planned</button></div></form><?php endif; ?></section>
<?php endif; ?>
<section class="panel danger-zone">
<?php if ($ms['status'] === 'void'): ?><form method="post" action="pricing.php" class="form"><?=$hid('ms_restore')?><p>Voided: <?=h($ms['void_reason'] ?? '')?></p><div class="form-actions"><button type="submit" class="secondary">Restore milestone</button></div></form>
<?php elseif ($st['state'] === 'ready'): ?><form method="post" action="pricing.php" class="form"><?=$hid('ms_void')?><div class="field"><label for="vr">Void — reason</label><input id="vr" name="void_reason" maxlength="255" required placeholder="Why it won't be invoiced"></div><div class="form-actions"><button type="submit" class="danger">Void milestone</button></div></form>
<?php else: ?><form method="post" action="pricing.php" class="form"><?=$hid('ms_delete')?><label class="check"><input type="checkbox" name="confirm" value="1" required> Remove this milestone</label><div class="form-actions"><button type="submit" class="danger">Remove milestone</button></div></form><?php endif; ?>
</section>
<?php endif; ?>
<?php cm_page_end($type === 'task' ? 'tasks' : ($type === 'template' ? 'templates' : 'enquiries'));
