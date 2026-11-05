<?php
declare(strict_types=1);
/*
 * Plans (v0.19): the hosting / email / domain plan list with today's prices for new clients, how many services are
 * on each (and how many keep an older "legacy" price), matching existing services to plans, and the Xero items.
 *   GET  plans.php                 the list (+ Xero items when connected)
 *   GET  plans.php?edit=ID|new     plan form
 *   GET  plans.php?match=1         proposed plans for services that have none (nothing changes until Apply)
 *   POST action = save             add / edit a plan (audited; edits undoable — service_plan_updated, row_id)
 *        action = match            link the ticked services to their plans (one undoable change — services_plans_matched)
 *        action = xero_create      create the ticked plans as Xero items (Code = plan code)
 *        push = plan id            update that plan's Xero item from Workdesk
 * Workdesk never deletes or archives anything in Xero, and never changes any service's price.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
if (!cm_has_plans($pdo)) { cm_page_start('Plans', 'billing', ['back' => [route('more'), 'More']]); echo '<div class="notice">Plans need database migration 009 — see INSTALL.md.</div>'; cm_page_end('billing'); exit; }
$x = cm_xero_load();
$error = '';
$FIELDS = ['code', 'name', 'kind', 'price_ex_gst', 'price_months', 'xero_account', 'active', 'sort_order', 'notes'];
$editId = isset($_GET['edit']) && ctype_digit((string)$_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew = ($_GET['edit'] ?? '') === 'new';
$back = fn(string $q = '') => header('Location: plans.php' . $q, true, 303);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    if (isset($_POST['push']) && ctype_digit((string)$_POST['push'])) $action = 'xero_push';
    try {
        $pdo->beginTransaction();
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $old = $id ? oneRow($pdo, 'SELECT * FROM plans WHERE id=? FOR UPDATE', [$id]) : null;
            if ($id && !$old) throw new InvalidArgumentException('Plan not found.');
            if ($id) cm_check_ver($old, $FIELDS);
            $new = ['code' => strtoupper(cm_post('code', 30)), 'name' => cm_post('name', 50), 'kind' => cm_post('kind', 20), 'price_ex_gst' => cm_nullable(str_replace([',', '$'], '', cm_post('price_ex_gst', 14))),
                'price_months' => (int)cm_post('price_months', 3), 'xero_account' => cm_nullable(cm_post('xero_account', 20)), 'active' => !empty($_POST['active']) ? 1 : 0,
                'sort_order' => ctype_digit(cm_post('sort_order', 5)) ? (int)cm_post('sort_order', 5) : null, 'notes' => cm_nullable(cm_post('notes', 500))];
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $new['code'])) throw new InvalidArgumentException('Code: letters, numbers and dashes only (it becomes the Xero item code), up to 30.');
            if ($new['name'] === '') throw new InvalidArgumentException('Enter the plan name.');
            if (!isset(CM_PLAN_KINDS[$new['kind']])) throw new InvalidArgumentException('Choose a kind.');
            if (!isset(CM_PLAN_PERIODS[(string)$new['price_months']])) throw new InvalidArgumentException('Choose what the price is for.');
            if ($new['price_ex_gst'] !== null && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $new['price_ex_gst'])) throw new InvalidArgumentException('Price must be dollars ex GST (up to 2 decimals), or blank for a price per client.');
            if (oneRow($pdo, 'SELECT id FROM plans WHERE code=? AND id<>?', [$new['code'], $id])) throw new InvalidArgumentException('Another plan already has the code ' . $new['code'] . '.');
            if ($id) {
                $changes = cm_changes($old, $new);
                if (isset($changes['price_ex_gst']) && $changes['price_ex_gst']['previous'] !== null && $changes['price_ex_gst']['current'] !== null && (float)$changes['price_ex_gst']['previous'] === (float)$changes['price_ex_gst']['current']) unset($changes['price_ex_gst']);
                if (isset($changes['code']) && $old['xero_item_id']) throw new InvalidArgumentException('This plan is already a Xero item, so its code stays ' . $old['code'] . ' (change it in Xero and here together, or make a new plan).');
                if ($changes) {
                    $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                    $pdo->prepare("UPDATE plans SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                    cm_audit($pdo, 'service_plan_updated', [], 'Plan ' . $new['code'] . ' updated: ' . implode(', ', array_keys($changes)), ['row_id' => $id] + $changes);
                }
            } else {
                $cols = array_keys($new);
                $pdo->prepare('INSERT INTO plans (' . implode(',', $cols) . ',created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',NOW())')->execute(array_values($new));
                cm_audit($pdo, 'service_plan_created', [], 'Plan added: ' . $new['code'] . ' ' . $new['name'], ['row_id' => (int)$pdo->lastInsertId()] + array_filter($new, fn($v) => $v !== null));
            }
            $pdo->commit(); $back(); exit;
        }
        if ($action === 'match') {
            $plans = cm_plans($pdo, true);
            $rows = []; $n = 0;
            foreach (array_map('intval', is_array($_POST['sids'] ?? null) ? $_POST['sids'] : []) as $sid) {
                $s = oneRow($pdo, 'SELECT * FROM services WHERE id=? FOR UPDATE', [$sid]);
                if (!$s) throw new InvalidArgumentException('A ticked service no longer exists. Reload the page.');
                if ($s['plan_id'] !== null) continue;   // already linked meanwhile — leave it
                $pid = (int)($_POST['plan'][$sid] ?? 0); $mbRaw = trim((string)($_POST['mb'][$sid] ?? ''));
                if (!isset($plans[$pid])) throw new InvalidArgumentException(($s['domain'] ?: $s['label']) . ': choose a plan (or untick it).');
                if ($mbRaw !== '' && (!ctype_digit($mbRaw) || (int)$mbRaw > 500)) throw new InvalidArgumentException(($s['domain'] ?: $s['label']) . ': mailboxes must be a whole number.');
                $ch = cm_changes($s, ['plan_id' => $pid, 'mailboxes' => $mbRaw === '' ? null : (int)$mbRaw]);
                if (!$ch) continue;
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($ch)));
                $pdo->prepare("UPDATE services SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($ch, 'current'), $sid]);
                $rows[] = ['table' => 'services', 'id' => $sid, 'changes' => $ch]; $n++;
            }
            if (!$rows) throw new InvalidArgumentException('Tick the services to link to their plans.');
            cm_audit($pdo, 'services_plans_matched', [], $n . ' service' . ($n === 1 ? '' : 's') . ' linked to plans', ['_rows' => $rows]);
            $pdo->commit(); $back('?match=1'); exit;
        }
        if ($action === 'xero_create' || $action === 'xero_push') {
            if (cm_xero_state($x) !== 'connected') throw new InvalidArgumentException('Xero is not connected.');
            $items = cm_xero_items_all($x);
            $ids = $action === 'xero_push' ? [(int)$_POST['push']] : array_map('intval', is_array($_POST['plan_ids'] ?? null) ? $_POST['plan_ids'] : []);
            if (!array_filter($ids)) throw new InvalidArgumentException('Tick the plans to create in Xero.');
            $done = 0;
            foreach ($ids as $pid) {
                $p = oneRow($pdo, 'SELECT * FROM plans WHERE id=? FOR UPDATE', [$pid]);
                if (!$p) throw new InvalidArgumentException('Plan not found.');
                if (!$p['active']) throw new InvalidArgumentException('Plan ' . $p['code'] . ' is not active, so it is not sent to Xero.');
                $have = $items[strtoupper((string)$p['code'])] ?? null;
                if ($action === 'xero_create' && $have) { $iid = $have['id']; }   // already in Xero with this code: just link it
                else $iid = cm_xero_save_item($x, $pdo, $p, $have['id'] ?? '');
                if ($iid !== (string)$p['xero_item_id']) $pdo->prepare('UPDATE plans SET xero_item_id=?, updated_at=NOW() WHERE id=?')->execute([$iid, $pid]);
                cm_audit($pdo, 'plan_xero_' . ($have ? 'updated' : 'created'), [], 'Xero item ' . ($have ? ($action === 'xero_create' ? 'linked' : 'updated') : 'created') . ': ' . $p['code'], ['plan_id' => $pid, 'item_id' => $iid]);
                $done++;
            }
            $pdo->commit();
            $_SESSION['cm_plans_flash'] = $done . ' plan' . ($done === 1 ? '' : 's') . ($action === 'xero_push' ? ' updated' : ' created') . ' in Xero.';
            $back(); exit;
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : cm_edit_error($e, $pdo, 'plans');
        if (!($e instanceof InvalidArgumentException)) error_log('Cloake plans: ' . $e->getMessage());
        if ($action === 'save') { $editId = (int)($_POST['id'] ?? 0); $isNew = !$editId; }
        if ($action === 'match') $_GET['match'] = '1';
    }
}
$plans = cm_plans($pdo);
$flash = (string)($_SESSION['cm_plans_flash'] ?? ''); unset($_SESSION['cm_plans_flash']);

/* ---------- plan form ---------- */
if ($editId || $isNew) {
    $p = $editId ? ($plans[$editId] ?? null) : null;
    if ($editId && !$p) { http_response_code(404); exit('Plan not found.'); }
    $p ??= ['id' => 0, 'code' => '', 'name' => '', 'kind' => 'hosting', 'price_ex_gst' => null, 'price_months' => 6, 'xero_account' => null, 'active' => 1, 'sort_order' => null, 'notes' => null, 'xero_item_id' => null];
    if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') foreach ($FIELDS as $f) if (is_string($_POST[$f] ?? null)) $p[$f] = trim($_POST[$f]);
    $ver = $editId ? cm_ver($plans[$editId], $FIELDS) : '';
    cm_page_start($editId ? 'Edit plan' : 'New plan', 'billing', ['back' => ['plans.php', 'Plans'], 'bodyClass' => 'form-page']);
    $sel = function (string $name, array $choices, $cur) { echo '<select id="' . h($name) . '" name="' . h($name) . '">'; foreach ($choices as $k => $l) echo '<option value="' . h((string)$k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>'; echo '</select>'; };
    ?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" action="plans.php" class="form" data-testid="plan-form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><input type="hidden" name="ver" value="<?=h($ver)?>">
<div class="pair"><div class="field"><label for="code">Code <span class="opt">(the Xero item code)</span></label><input id="code" name="code" maxlength="30" value="<?=h((string)$p['code'])?>" placeholder="HOST-STD"<?=$p['xero_item_id'] ? ' readonly' : ''?>></div>
<div class="field"><label for="kind">Kind</label><?php $sel('kind', CM_PLAN_KINDS, $p['kind']); ?></div></div>
<div class="field"><label for="name">Name <span class="opt">(up to 50 characters)</span></label><input id="name" name="name" maxlength="50" value="<?=h((string)$p['name'])?>"></div>
<div class="triple"><div><label for="price_ex_gst">Price ex GST <span class="opt">(new clients)</span></label><input id="price_ex_gst" name="price_ex_gst" inputmode="decimal" maxlength="14" value="<?=h((string)($p['price_ex_gst'] ?? ''))?>" placeholder="per client"></div>
<div><label for="price_months">For</label><?php $sel('price_months', CM_PLAN_PERIODS, $p['price_months']); ?></div>
<div><label for="xero_account">Xero account</label><input id="xero_account" name="xero_account" maxlength="20" value="<?=h((string)($p['xero_account'] ?? ''))?>" placeholder="<?=h(cm_account_for($pdo, $p['kind'] === 'domain' ? 'domain' : 'hosting'))?>"></div></div>
<div class="pair"><div class="field"><label class="check"><input type="checkbox" name="active" value="1"<?=!empty($p['active']) ? ' checked' : ''?>> Active (offered for new services)</label></div><div class="field"><label for="sort_order">Order</label><input id="sort_order" name="sort_order" inputmode="numeric" maxlength="5" value="<?=h((string)($p['sort_order'] ?? ''))?>"></div></div>
<div class="field"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="500" value="<?=h((string)($p['notes'] ?? ''))?>"></div>
<p class="helper">Changing a price here changes it for new services only. Existing services keep the price they're on (shown as “legacy price” when lower). After saving, Plans shows whether the Xero item needs updating.</p>
<div class="form-actions"><button type="submit" class="save"><?=$editId ? 'Save plan' : 'Add plan'?></button><a class="cancel" href="plans.php">Cancel</a></div></form>
<?php cm_page_end('billing'); exit;
}

/* ---------- match services ---------- */
if (!empty($_GET['match'])) {
    $active = array_filter($plans, fn($p) => (int)$p['active'] === 1);
    $svc = allRows($pdo, "SELECT s.*, c.name client_name FROM services s LEFT JOIN clients c ON c.id=s.client_id
        WHERE s.plan_id IS NULL AND s.status IN ('active','pending','suspended') AND s.is_internal=0 ORDER BY s.kind, c.name, s.domain");
    $props = []; foreach ($svc as $s) $props[(int)$s['id']] = cm_plan_proposal($s, $plans);
    $sure = count(array_filter($props, fn($p) => $p['sure']));
    cm_page_start('Match services to plans', 'billing', ['back' => ['plans.php', 'Plans']]);
    ?>
<p class="lead">Live services without a plan, each with the plan its price points to. Nothing changes until you press <strong>Link ticked services</strong>, and prices are never changed — only the plan (and mailbox count) is recorded.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (!$svc): ?><div class="success" role="status" data-testid="match-none">Every live service has a plan.</div><?php cm_page_end('billing'); exit; endif; ?>
<p class="small" data-testid="match-summary"><?=count($svc)?> without a plan · <strong><?=$sure?></strong> clear matches (ticked) · <?=count($svc) - $sure?> to choose</p>
<form method="post" action="plans.php?match=1" class="form" data-testid="match-list"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="match">
<ul class="plan-list"><?php foreach ($svc as $s): $sid = (int)$s['id']; $pr = $props[$sid]; ?>
<li data-service="<?=$sid?>" data-sure="<?=$pr['sure'] ? '1' : '0'?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=$sid?>"<?=$pr['sure'] ? ' checked' : ''?>> <strong><?=h($s['domain'] ?: $s['label'] ?: '—')?></strong></label>
<small><?=h(implode(' · ', array_filter([$s['client_name'], CM_SERVICE_KINDS[$s['kind']] ?? $s['kind'], $s['price_ex_gst'] !== null ? cm_money($s['price_ex_gst']) . ' ' . strtolower(CM_CYCLES[(string)$s['cycle_months']] ?? '') : 'no price', $s['plan_name'] ? 'was “' . $s['plan_name'] . '”' : ''])))?></small>
<small class="<?=$pr['sure'] ? '' : 'late'?>"><?=h($pr['sure'] ? 'Proposed: ' . $pr['why'] : $pr['why'])?></small></span>
<span class="pl-end row-inline"><label class="visually-hidden" for="pl<?=$sid?>">Plan</label><select id="pl<?=$sid?>" name="plan[<?=$sid?>]"><option value="">— plan —</option><?php foreach ($active as $p): ?><option value="<?=(int)$p['id']?>"<?=(int)$pr['plan_id'] === (int)$p['id'] ? ' selected' : ''?>><?=h($p['code'])?></option><?php endforeach; ?></select>
<label class="visually-hidden" for="mb<?=$sid?>">Mailboxes</label><input id="mb<?=$sid?>" name="mb[<?=$sid?>]" class="mini-num" inputmode="numeric" maxlength="3" size="3" placeholder="mbx" value="<?=$pr['mailboxes'] !== null ? (int)$pr['mailboxes'] : ''?>"></span></li>
<?php endforeach; ?></ul>
<div class="form-actions"><button type="submit" class="save">Link ticked services</button><a class="cancel" href="plans.php">Cancel</a></div>
<p class="helper">“mbx” is the number of mailboxes on the service (used for the Xero description and the list price). Undo is available straight after.</p></form>
<?php cm_page_end('billing'); exit;
}

/* ---------- the list ---------- */
$use = []; foreach (allRows($pdo, "SELECT * FROM services WHERE plan_id IS NOT NULL AND status IN ('active','pending','suspended')") as $s) { $k = (int)$s['plan_id']; $use[$k]['n'] = ($use[$k]['n'] ?? 0) + 1; if (cm_price_status($s, $plans) === 'legacy') $use[$k]['legacy'] = ($use[$k]['legacy'] ?? 0) + 1; }
$unplanned = (int)oneRow($pdo, "SELECT COUNT(*) n FROM services WHERE plan_id IS NULL AND status IN ('active','pending','suspended') AND is_internal=0")['n'];
$items = null; $xerr = '';
if (cm_xero_state($x) === 'connected') { try { $items = cm_xero_items_all($x); } catch (Throwable $e) { $xerr = $e->getMessage(); } }
cm_page_start('Plans', 'billing', ['back' => [route('more'), 'More']]);
?>
<p class="lead">Your plans and today's prices for new clients (ex GST). Existing clients keep the price they're on — Workdesk only marks it <em>legacy price</em> when it is lower.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status"><?=h($flash)?></div><?php endif; ?>
<p class="row-inline"><a class="button-link" href="plans.php?edit=new"><span aria-hidden="true">＋</span> New plan</a><?php if ($unplanned): ?><a href="plans.php?match=1" data-testid="match-link">Match <?=$unplanned?> service<?=$unplanned === 1 ? '' : 's'?> without a plan ›</a><?php endif; ?></p>
<form method="post" action="plans.php" class="form" data-testid="plans-list"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<ul class="plan-list"><?php foreach ($plans as $p): $pid = (int)$p['id']; $it = $items[strtoupper((string)$p['code'])] ?? null;
    $xs = $items === null ? '' : (!$p['active'] ? 'inactive' : (!$it ? 'missing' : (cm_plan_item_diff($pdo, $p, $it) ? 'diff' : 'ok'))); ?>
<li data-plan="<?=h((string)$p['code'])?>" data-xero="<?=h($xs)?>"><span class="pl-main"><strong><a href="plans.php?edit=<?=$pid?>"><?=h($p['code'])?></a> <?=h($p['name'])?></strong><?=!$p['active'] ? ' <em class="tag">not active</em>' : ''?>
<small><?=h(implode(' · ', array_filter([$p['price_ex_gst'] !== null ? cm_money($p['price_ex_gst']) . ' ' . (CM_PLAN_PERIODS[(string)$p['price_months']] ?? '') : 'price per client', 'account ' . ($p['xero_account'] ?: cm_account_for($pdo, $p['kind'] === 'domain' ? 'domain' : 'hosting')), ($use[$pid]['n'] ?? 0) . ' service' . (($use[$pid]['n'] ?? 0) === 1 ? '' : 's'), !empty($use[$pid]['legacy']) ? $use[$pid]['legacy'] . ' on a legacy price' : '', $p['notes']])))?></small>
<?php if ($xs === 'ok'): ?><small>✓ In Xero</small><?php elseif ($xs === 'diff'): ?><small class="late">≠ Xero item differs (<?=h(implode(', ', cm_plan_item_diff($pdo, $p, $it)))?>) — Update Xero</small><?php elseif ($xs === 'missing'): ?><small class="late">✗ Not in Xero</small> <label class="check small"><input type="checkbox" name="plan_ids[]" value="<?=$pid?>" checked> create in Xero</label><?php endif; ?></span>
<span class="pl-end"><?php if ($xs === 'diff'): ?><button type="submit" name="push" value="<?=$pid?>" class="mini">Update Xero</button><?php endif; ?></span></li>
<?php endforeach; ?></ul>
<?php if ($items !== null && array_filter($plans, fn($p) => $p['active'] && !isset($items[strtoupper((string)$p['code'])]))): ?><div class="form-actions"><button type="submit" name="action" value="xero_create" class="secondary">Create ticked in Xero</button></div><?php endif; ?>
</form>
<?php if (cm_xero_state($x) !== 'connected'): ?><p class="helper">Connect Xero (More › Xero connection) to keep these as items in Xero's Products and services.</p>
<?php elseif ($xerr): ?><div class="alert" role="alert"><?=h($xerr)?></div>
<?php else: $codes = array_flip(array_map(fn($p) => strtoupper((string)$p['code']), $plans)); $other = array_filter($items, fn($i, $k) => !isset($codes[$k]), ARRAY_FILTER_USE_BOTH); ?>
<p class="small muted">Xero: <strong><?=h((string)$x['tenant_name'])?></strong><?=cm_xero_is_demo($x) ? ' <em class="tag">Demo Company — test data</em>' : ''?>. Items are created and updated only when you click; nothing is deleted in Xero.</p>
<?php if ($other): ?><details class="panel" data-testid="xero-other-items"><summary>Other Xero items (<?=count($other)?>) <small class="muted">not plans — no action needed</small></summary><ul class="plan-list"><?php foreach ($other as $i): ?><li><span class="pl-main"><strong><?=h($i['code'])?></strong> <?=h($i['name'])?><small><?=$i['price'] !== null ? h(cm_money($i['price'])) : 'no price'?><?=$i['account'] !== '' ? ' · account ' . h($i['account']) : ''?></small></span></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php endif; ?>
<?php cm_page_end('billing');
