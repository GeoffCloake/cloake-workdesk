<?php
declare(strict_types=1);
/*
 * Materials (v0.14): items bought for a job — on the task page, edited through materials.php.
 * ordered → received (goes to Pending Invoicing as a "Material" line) or cancelled (with a reason).
 * Sell price defaults to cost + the markup in Billing settings (blank = at cost); override per item.
 * Once invoiced (a non-void charge with source_kind 'material'), an item's amounts are locked.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_ITEM_STATUSES = ['ordered' => 'Ordered', 'received' => 'Received', 'cancelled' => 'Cancelled'];

function cm_has_materials(PDO $pdo): bool
{
    static $has = null;
    return $has ??= (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='job_items'");
}
function cm_item_charge(PDO $pdo, int $id): ?array { return oneRow($pdo, "SELECT * FROM charges WHERE source_kind='material' AND source_id=? AND status<>'void' ORDER BY id DESC LIMIT 1", [$id]); }
function cm_item_total(array $i, string $which = 'sell'): float { return round((float)$i['quantity'] * (float)$i[$which === 'cost' ? 'unit_cost_ex_gst' : 'unit_sell_ex_gst'], 2); }
function cm_default_sell(PDO $pdo, float $cost): float { $p = cm_setting($pdo, 'material_markup_pct'); return round($cost * (1 + (is_numeric($p) ? (float)$p / 100 : 0)), 2); }

/** Posted item fields → values (throws on bad input). */
function cm_item_posted(PDO $pdo): array
{
    $v = [];
    foreach (['description' => 500, 'supplier' => 150, 'supplier_ref' => 100, 'quantity' => 14, 'unit_cost' => 14, 'unit_sell' => 14, 'ordered_on' => 10] as $f => $max) $v[$f] = cm_post($f, $max);
    if (mb_strlen($v['description']) < 2) throw new InvalidArgumentException('Describe the item.');
    if ($v['quantity'] === '') $v['quantity'] = '1';
    if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $v['quantity']) || (float)$v['quantity'] <= 0) throw new InvalidArgumentException('Quantity must be a positive number.');
    foreach (['unit_cost', 'unit_sell'] as $f) $v[$f] = str_replace([',', '$'], '', $v[$f]);
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v['unit_cost'])) throw new InvalidArgumentException('Cost must be dollars ex GST (each).');
    if ($v['unit_sell'] === '') $v['unit_sell'] = number_format(cm_default_sell($pdo, (float)$v['unit_cost']), 2, '.', '');
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v['unit_sell'])) throw new InvalidArgumentException('Sell price must be dollars ex GST (each), or blank for cost + markup.');
    $v['ordered_on'] = m7date($v['ordered_on']);
    return $v;
}

function cm_materials_panel(PDO $pdo, array $task): void
{
    if (!cm_has_materials($pdo)) return;
    $items = allRows($pdo, 'SELECT * FROM job_items WHERE task_id=? ORDER BY (status=\'cancelled\'), id', [(int)$task['id']]);
    $live = array_filter($items, fn($i) => $i['status'] !== 'cancelled');
    $cost = array_sum(array_map(fn($i) => cm_item_total($i, 'cost'), $live)); $sell = array_sum(array_map('cm_item_total', $live));
    $markup = cm_setting($pdo, 'material_markup_pct');
    $hid = fn(string $a, int $iid = 0) => '<input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($a) . '"><input type="hidden" name="task_id" value="' . (int)$task['id'] . '">' . ($iid ? '<input type="hidden" name="id" value="' . $iid . '">' : '');
    $err = ($_SESSION['cm_items_error']['task'] ?? 0) === (int)$task['id'] ? (string)$_SESSION['cm_items_error']['msg'] : ''; if ($err) unset($_SESSION['cm_items_error']);
    ?>
<section class="panel materials" id="materials" data-testid="materials"><h2 class="panel-title">Materials <?php if ($live): ?><small class="muted">cost <?=h(cm_money($cost))?> · sell <?=h(cm_money($sell))?> · margin <?=h(cm_money($sell - $cost))?></small><?php endif; ?></h2>
<?php if ($err): ?><div class="alert" role="alert"><?=h($err)?></div><?php endif; ?>
<?php if (!$items): ?><p class="muted small">Nothing bought for this job yet.</p><?php endif; ?>
<ul class="price-lines"><?php foreach ($items as $i): $ch = cm_item_charge($pdo, (int)$i['id']); ?>
<li class="price-line item-<?=h($i['status'])?>" data-testid="material"><span class="pl-main"><strong><?=h($i['description'])?></strong><small><?=h(implode(' · ', array_filter([cm_qty($i['quantity']) . ' × ' . cm_money($i['unit_cost_ex_gst']) . ' cost', $i['supplier'] ? $i['supplier'] . ($i['supplier_ref'] ? ' (' . $i['supplier_ref'] . ')' : '') : '', $i['status'] === 'received' ? 'received ' . niceDate($i['received_on']) : ($i['status'] === 'ordered' ? 'ordered' . ($i['ordered_on'] ? ' ' . niceDate($i['ordered_on']) : '') : 'cancelled' . ($i['cancel_reason'] ? ': ' . $i['cancel_reason'] : '')), $ch ? 'invoiced ' . ($ch['xero_invoice_number'] ?: '') : ''])))?></small></span>
<span class="pl-end"><strong><?=h(cm_money(cm_item_total($i)))?></strong><em class="ms-state"><?=h(CM_ITEM_STATUSES[$i['status']] ?? $i['status'])?></em><span class="pl-acts">
<?php if ($i['status'] === 'ordered'): ?><form method="post" action="materials.php"><?=$hid('receive', (int)$i['id'])?><button type="submit" class="mini">Received</button></form><?php endif; ?>
<?php if (!$ch && $i['status'] !== 'cancelled'): ?><a class="mini" href="materials.php?id=<?=(int)$i['id']?>">Edit</a><?php endif; ?></span></span></li>
<?php endforeach; ?></ul>
<details class="inline-add"><summary>＋ Add an item bought for this job</summary>
<form method="post" action="materials.php" class="form"><?=$hid('add')?>
<?php cm_item_fields(['description' => '', 'supplier' => '', 'supplier_ref' => '', 'quantity' => '1', 'unit_cost_ex_gst' => '', 'unit_sell_ex_gst' => '', 'ordered_on' => date('Y-m-d')], 'ni', $markup); ?>
<div class="form-actions"><button type="submit" class="secondary">Add item</button></div></form></details>
<p class="helper">Received items appear on Pending Invoicing as their own lines. Amounts are ex GST.</p>
</section>
<?php
}

function cm_item_fields(array $i, string $p, string $markup): void
{
    $money = fn($v) => $v === '' || $v === null ? '' : number_format((float)$v, 2, '.', '');
    ?>
<div class="field"><label for="<?=$p?>-desc">Item</label><input id="<?=$p?>-desc" name="description" maxlength="500" required value="<?=h($i['description'])?>" placeholder="e.g. Stock photo licence, SSL certificate"></div>
<div class="pair"><div class="field"><label for="<?=$p?>-sup">Supplier <span class="opt">(optional)</span></label><input id="<?=$p?>-sup" name="supplier" maxlength="150" value="<?=h((string)$i['supplier'])?>"></div><div class="field"><label for="<?=$p?>-ref">Supplier ref / order no</label><input id="<?=$p?>-ref" name="supplier_ref" maxlength="100" value="<?=h((string)$i['supplier_ref'])?>"></div></div>
<div class="triple"><div><label for="<?=$p?>-qty">Qty</label><input id="<?=$p?>-qty" name="quantity" inputmode="decimal" maxlength="14" value="<?=h($i['quantity'] === '' ? '' : cm_qty($i['quantity']))?>"></div><div><label for="<?=$p?>-cost">Cost each</label><input id="<?=$p?>-cost" name="unit_cost" inputmode="decimal" maxlength="14" required placeholder="0.00" value="<?=h($money($i['unit_cost_ex_gst']))?>"></div><div><label for="<?=$p?>-sell">Sell each</label><input id="<?=$p?>-sell" name="unit_sell" inputmode="decimal" maxlength="14" placeholder="<?=$markup !== '' ? 'cost +' . h($markup) . '%' : 'at cost'?>" value="<?=h($money($i['unit_sell_ex_gst']))?>"></div></div>
<div class="field narrow"><label for="<?=$p?>-ord">Ordered on</label><input type="date" id="<?=$p?>-ord" name="ordered_on" value="<?=h((string)$i['ordered_on'])?>"></div>
<?php
}
