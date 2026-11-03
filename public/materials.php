<?php
declare(strict_types=1);
/*
 * Materials write page (v0.14).
 *   POST action = add (task_id) | save (id) | receive (id, received_on optional) | cancel (id, reason) | unreceive (id) | delete (id)
 *   GET  ?id=  edit form (with Received / Cancel / Delete)
 * Invoiced items (a non-void 'material' charge) are locked. Delete only for an item never received or invoiced.
 * Every change is audited; edits (item_updated) are undoable.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$error = '';
$item = $id ? oneRow($pdo, 'SELECT * FROM job_items WHERE id=?', [$id]) : null;
if ($id && !$item) { http_response_code(404); exit('Item not found.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    $taskId = $item ? (int)$item['task_id'] : (filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    try {
        $pdo->beginTransaction();
        $t = oneRow($pdo, 'SELECT id,title,client_id,enquiry_id FROM tasks WHERE id=?', [$taskId]);
        if (!$t) throw new InvalidArgumentException('Task not found.');
        $ids = ['client_id' => $t['client_id'], 'enquiry_id' => $t['enquiry_id'], 'task_id' => $taskId];
        $old = $item ? oneRow($pdo, 'SELECT * FROM job_items WHERE id=? FOR UPDATE', [$id]) : null;
        if ($old && in_array($action, ['save', 'cancel', 'unreceive', 'delete'], true) && cm_item_charge($pdo, $id)) throw new InvalidArgumentException('This item has been invoiced, so it can’t be changed. Undo that invoice on Pending Invoicing first.');
        switch ($action) {
            case 'add':
                $v = cm_item_posted($pdo);
                $pdo->prepare("INSERT INTO job_items (task_id,client_id,description,supplier,supplier_ref,quantity,unit_cost_ex_gst,unit_sell_ex_gst,status,ordered_on,created_at) VALUES (?,?,?,?,?,?,?,?,'ordered',?,NOW())")
                    ->execute([$taskId, $t['client_id'], $v['description'], cm_nullable($v['supplier']), cm_nullable($v['supplier_ref']), $v['quantity'], $v['unit_cost'], $v['unit_sell'], $v['ordered_on']]);
                cm_audit($pdo, 'item_added', $ids, 'Material added to task #' . $taskId . ': ' . $v['description'] . ' (' . cm_money((float)$v['quantity'] * (float)$v['unit_sell']) . ' ex GST)', ['row_id' => (int)$pdo->lastInsertId()] + $v);
                break;
            case 'save':
                $v = cm_item_posted($pdo);
                $new = ['description' => $v['description'], 'supplier' => cm_nullable($v['supplier']), 'supplier_ref' => cm_nullable($v['supplier_ref']), 'quantity' => $v['quantity'], 'unit_cost_ex_gst' => $v['unit_cost'], 'unit_sell_ex_gst' => $v['unit_sell'], 'ordered_on' => $v['ordered_on']];
                $changes = cm_changes($old, $new);
                foreach (['quantity', 'unit_cost_ex_gst', 'unit_sell_ex_gst'] as $f) if (isset($changes[$f]) && (float)$changes[$f]['previous'] === (float)$changes[$f]['current']) unset($changes[$f]);
                if ($changes) {
                    $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                    $pdo->prepare("UPDATE job_items SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                    cm_audit($pdo, 'item_updated', $ids, 'Material “' . $old['description'] . '” updated: ' . implode(', ', array_keys($changes)), ['row_id' => $id] + $changes);
                }
                break;
            case 'receive':
            case 'unreceive':
            case 'cancel':
                if ($action === 'receive') {
                    if ($old['status'] !== 'ordered') throw new InvalidArgumentException('Only an ordered item can be marked received.');
                    $on = m7date(cm_post('received_on', 10)) ?: date('Y-m-d');
                    if ($on > date('Y-m-d')) throw new InvalidArgumentException('The received date cannot be in the future.');
                    $new = ['status' => 'received', 'received_on' => $on];
                } elseif ($action === 'unreceive') {
                    if ($old['status'] !== 'received') throw new InvalidArgumentException('This item is not marked received.');
                    $new = ['status' => 'ordered', 'received_on' => null];
                } else {
                    $reason = cm_post('cancel_reason', 255);
                    if ($reason === '') throw new InvalidArgumentException('Give a reason for cancelling the item.');
                    $new = ['status' => 'cancelled', 'cancel_reason' => $reason];
                }
                $changes = cm_changes($old, $new);
                if ($changes) {
                    $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                    $pdo->prepare("UPDATE job_items SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                    cm_audit($pdo, 'item_updated', $ids, 'Material “' . $old['description'] . '” ' . ['receive' => 'received', 'unreceive' => 'set back to ordered', 'cancel' => 'cancelled'][$action], ['row_id' => $id] + $changes);
                }
                break;
            case 'delete':
                if ($old['status'] === 'received') throw new InvalidArgumentException('A received item can’t be deleted. Cancel it instead (with a reason).');
                if (cm_post('confirm', 2) !== '1') throw new InvalidArgumentException('Tick the box to confirm deleting the item.');
                $pdo->prepare('DELETE FROM job_items WHERE id=?')->execute([$id]);
                cm_audit($pdo, 'item_deleted', $ids, 'Material deleted from task #' . $taskId . ': ' . $old['description'], ['item' => $old]);
                break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
        $pdo->commit();
        header('Location: ' . route('task', ['id' => $taskId]) . '#materials', true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'material');
        if (!$item || in_array($action, ['add', 'receive'], true) && !isset($_POST['from_edit'])) { $_SESSION['cm_items_error'] = ['task' => $taskId, 'msg' => $error]; header('Location: ' . route('task', ['id' => $taskId]) . '#materials', true, 303); exit; }
        foreach (['description' => 'description', 'supplier' => 'supplier', 'supplier_ref' => 'supplier_ref', 'quantity' => 'quantity', 'unit_cost_ex_gst' => 'unit_cost', 'unit_sell_ex_gst' => 'unit_sell', 'ordered_on' => 'ordered_on'] as $k => $p) if (is_string($_POST[$p] ?? null)) $item[$k] = trim($_POST[$p]);
    }
}
if (!$item) { header('Location: ' . route('tasks'), true, 303); exit; }
$t = oneRow($pdo, 'SELECT id,title FROM tasks WHERE id=?', [(int)$item['task_id']]);
$ch = cm_item_charge($pdo, (int)$item['id']);
$hid = fn(string $a) => '<input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($a) . '"><input type="hidden" name="id" value="' . (int)$item['id'] . '"><input type="hidden" name="from_edit" value="1">';
$back = route('task', ['id' => $t['id']]) . '#materials';
cm_page_start('Material', 'tasks', ['back' => [$back, 'Task #' . (int)$t['id']], 'bodyClass' => 'form-page']);
?>
<p class="muted small">For <a href="<?=h($back)?>">#<?=(int)$t['id']?> <?=h($t['title'])?></a> · <?=h(CM_ITEM_STATUSES[$item['status']] ?? $item['status'])?><?=$item['received_on'] ? ' ' . h(niceDate($item['received_on'])) : ''?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($ch): ?><p class="notice">Invoiced on <?=h($ch['xero_invoice_number'] ?: '—')?> (<?=h(cm_money($ch['amount_ex_gst']))?> ex GST). It can’t be changed unless that invoice is undone.</p>
<?php elseif ($item['status'] === 'cancelled'): ?><p class="notice">Cancelled: <?=h((string)$item['cancel_reason'])?></p>
<?php else: ?>
<form method="post" action="materials.php" class="form"><?=$hid('save')?>
<?php cm_item_fields($item, 'e', cm_setting($pdo, 'material_markup_pct')); ?>
<div class="form-actions"><button type="submit" class="save">Save item</button><a class="cancel" href="<?=h($back)?>">Cancel</a></div></form>
<section class="panel"><?php if ($item['status'] === 'ordered'): ?><form method="post" action="materials.php" class="form row-inline"><?=$hid('receive')?><label for="ron" class="visually-hidden">Received on</label><input type="date" id="ron" name="received_on" value="<?=h(date('Y-m-d'))?>" max="<?=h(date('Y-m-d'))?>"><button type="submit" class="secondary">Mark received</button></form>
<?php else: ?><form method="post" action="materials.php" class="form"><?=$hid('unreceive')?><button type="submit" class="secondary">Set back to ordered</button></form><?php endif; ?></section>
<section class="panel danger-zone"><form method="post" action="materials.php" class="form"><?=$hid('cancel')?><div class="field"><label for="cr">Cancel — reason</label><input id="cr" name="cancel_reason" maxlength="255" required placeholder="e.g. supplier out of stock"></div><div class="form-actions"><button type="submit" class="danger">Cancel item</button></div></form>
<?php if ($item['status'] === 'ordered'): ?><form method="post" action="materials.php" class="form delete-form"><?=$hid('delete')?><label class="check"><input type="checkbox" name="confirm" value="1" required> Delete this item (entered by mistake)</label><div class="form-actions"><button type="submit" class="danger">Delete item</button></div></form><?php endif; ?></section>
<?php endif; ?>
<?php cm_page_end('tasks');
