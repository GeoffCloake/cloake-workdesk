<?php
declare(strict_types=1);
/*
 * Add or edit a client (v0.10.0). Archive instead of delete. Every change is audited.
 * Imported clients keep their original legacy_record untouched as provenance.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/edit.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$FIELDS = ['name', 'business_name', 'status', 'email', 'phone', 'contact_method', 'physical_address_1', 'physical_address_2', 'physical_city_town', 'physical_country',
    'postal_address_1', 'postal_address_2', 'postal_address_3', 'postal_city_town', 'postal_code', 'postal_country', 'xero_contact_id', 'notes'];
$LIMITS = ['name' => 255, 'business_name' => 255, 'status' => 20, 'email' => 255, 'phone' => 64, 'contact_method' => 40, 'physical_city_town' => 120, 'physical_country' => 80,
    'postal_city_town' => 120, 'postal_code' => 20, 'postal_country' => 80, 'xero_contact_id' => 64, 'notes' => 20000];
$error = ''; $dupes = [];
$c = $id ? oneRow($pdo, 'SELECT * FROM clients WHERE id=?', [$id]) : array_merge(array_fill_keys($FIELDS, null), ['status' => 'active', 'physical_country' => 'New Zealand', 'postal_country' => 'New Zealand']);
if ($id && !$c) { http_response_code(404); exit('Client not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        m7verify();
        $v = [];
        foreach ($FIELDS as $f) $v[$f] = cm_post($f, $LIMITS[$f] ?? 255);
        if (mb_strlen($v['name']) < 2) throw new InvalidArgumentException('Client name is required.');
        if (!in_array($v['status'], ['active', 'archive'], true)) throw new InvalidArgumentException('Invalid status.');
        if ($v['email'] !== '' && !validEmail($v['email'])) throw new InvalidArgumentException('Email address does not look valid.');
        if (!array_key_exists($v['contact_method'], CM_CONTACT_METHODS)) throw new InvalidArgumentException('Invalid preferred contact method.');
        $new = array_map('cm_nullable', $v); $new['name'] = $v['name']; $new['status'] = $v['status'];
        $pdo->beginTransaction();
        if ($id) {
            $s = $pdo->prepare('SELECT * FROM clients WHERE id=? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch();
            cm_check_ver($old, $FIELDS);
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE clients SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                $what = isset($changes['status']) ? ($v['status'] === 'archive' ? 'Client archived' : 'Client restored') : 'Client details updated';
                cm_audit($pdo, 'client_updated', ['client_id' => $id], $what . ': ' . implode(', ', array_keys($changes)), $changes);
            }
            $pdo->commit();
            header('Location: ' . route('client', ['id' => $id, 'saved' => $changes ? 1 : 0]), true, 303); exit;
        }
        // New client: warn about likely duplicates once (WHMCS reconciliation depends on one record per client).
        if (empty($_POST['confirm_duplicate'])) {
            $s = $pdo->prepare("SELECT id,name,status FROM clients WHERE LOWER(name)=LOWER(?) OR (?<>'' AND LOWER(email)=LOWER(?)) LIMIT 5");
            $s->execute([$v['name'], $v['email'], $v['email']]); $dupes = $s->fetchAll();
            if ($dupes) { $pdo->rollBack(); throw new InvalidArgumentException('A client with this name or email already exists. Open the existing client, or tick “Create anyway”.'); }
        }
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO clients (source_system,source_id,' . implode(',', array_map(fn($f) => "`$f`", $cols)) . ',updated_at) VALUES (\'app\',?,' . implode(',', array_fill(0, count($cols), '?')) . ',NOW())')
            ->execute([cm_new_source_id(), ...array_values($new)]);
        $newId = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'client_created', ['client_id' => $newId], 'Client created', array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        header('Location: ' . route('client', ['id' => $newId, 'saved' => 1]), true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'client save #' . $id);
        foreach ($FIELDS as $f) $c[$f] = is_string($_POST[$f] ?? null) ? trim($_POST[$f]) : ($c[$f] ?? null);
    }
}
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM clients WHERE id=?', [$id]), $FIELDS) : '';
$input = function (string $f, string $label, array $o = []) use ($c, $LIMITS) {
    $type = $o['type'] ?? 'text';
    echo '<div class="field"><label for="' . h($f) . '">' . h($label) . '</label><input id="' . h($f) . '" name="' . h($f) . '" type="' . h($type) . '" maxlength="' . (int)($LIMITS[$f] ?? 255) . '" value="' . h($c[$f] ?? '') . '"'
        . (!empty($o['required']) ? ' required' : '') . (!empty($o['auto']) ? ' autocomplete="' . h($o['auto']) . '"' : '') . (!empty($o['im']) ? ' inputmode="' . h($o['im']) . '"' : '') . '></div>';
};
cm_page_start($id ? 'Edit client' : 'New client', 'clients', ['back' => $id ? [route('client', ['id' => $id]), $c['name']] : [route('clients'), 'Clients'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?><?php if ($dupes): ?><ul><?php foreach ($dupes as $d): ?><li><a href="<?=h(route('client', ['id' => $d['id']]))?>"><?=h($d['name'])?></a><?=$d['status'] === 'archive' ? ' (archived)' : ''?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if ($id && !empty($c['legacy_record'])): ?><p class="small muted">Imported from the task manager (original ID <?=h($c['source_id'])?>). The original imported values are kept unchanged; edits here update the current record.</p><?php endif; ?>
<form method="post" class="form" autocomplete="off"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>">
<?php $input('name', 'Client name', ['required' => true]); $input('business_name', 'Business / legal name (if different)'); ?>
<div class="pair"><?php $input('email', 'Email', ['type' => 'email', 'auto' => 'off']); $input('phone', 'Phone', ['type' => 'tel', 'im' => 'tel']); ?></div>
<div class="pair"><div class="field"><label for="contact_method">Preferred contact</label><select id="contact_method" name="contact_method"><?php foreach (CM_CONTACT_METHODS as $k => $l): ?><option value="<?=h($k)?>"<?=(string)($c['contact_method'] ?? '') === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?><?php if (!array_key_exists((string)($c['contact_method'] ?? ''), CM_CONTACT_METHODS)): ?><option value="<?=h($c['contact_method'])?>" selected><?=h($c['contact_method'])?></option><?php endif; ?></select></div>
<div class="field"><label for="status">Status</label><select id="status" name="status"><option value="active"<?=($c['status'] ?? 'active') !== 'archive' ? ' selected' : ''?>>Active</option><option value="archive"<?=($c['status'] ?? '') === 'archive' ? ' selected' : ''?>>Archived</option></select></div></div>
<details<?=($c['physical_address_1'] ?? '') . ($c['physical_city_town'] ?? '') !== '' ? ' open' : ''?>><summary>Physical address</summary><?php $input('physical_address_1', 'Address line 1'); $input('physical_address_2', 'Address line 2'); ?><div class="pair"><?php $input('physical_city_town', 'Town / city'); $input('physical_country', 'Country'); ?></div></details>
<details<?=($c['postal_address_1'] ?? '') . ($c['postal_city_town'] ?? '') !== '' ? ' open' : ''?>><summary>Postal address</summary><?php $input('postal_address_1', 'Address line 1'); $input('postal_address_2', 'Address line 2'); $input('postal_address_3', 'Address line 3'); ?><div class="pair"><?php $input('postal_city_town', 'Town / city'); $input('postal_code', 'Postcode', ['im' => 'numeric']); ?></div><?php $input('postal_country', 'Country'); ?></details>
<details<?=($c['notes'] ?? '') . ($c['xero_contact_id'] ?? '') !== '' ? ' open' : ''?>><summary>Notes &amp; Xero reference</summary><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="20000"><?=h($c['notes'] ?? '')?></textarea></div><?php $input('xero_contact_id', 'Xero contact reference'); ?><p class="helper">Reference only. Nothing is sent to Xero.</p></details>
<?php if (!$id && $dupes): ?><div class="field"><label class="check"><input type="checkbox" name="confirm_duplicate" value="1"> Create anyway — this is a different client</label></div><?php endif; ?>
<button type="submit" class="save"><?=$id ? 'Save client' : 'Create client'?></button>
<?php if ($id): ?><p class="helper">To stop using a client, set Status to Archived. Clients are never deleted, so their history stays intact.</p><?php endif; ?>
</form>
<?php cm_page_end('clients');
