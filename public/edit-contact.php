<?php
declare(strict_types=1);
/*
 * Add or edit a contact (v0.10.0). ?id= edits; ?client_id= adds to a client.
 * Contacts can be moved to another client (this also links the imported contacts
 * that had no client). Archive instead of delete. Every change is audited.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/edit.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$FIELDS = ['client_id', 'title', 'first_name', 'middle_name', 'last_name', 'position', 'contact_type', 'email', 'phone', 'mobile', 'contact_method', 'status', 'notes'];
$LIMITS = ['title' => 30, 'first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'position' => 150, 'contact_type' => 255, 'email' => 255, 'phone' => 64, 'mobile' => 64, 'contact_method' => 40, 'status' => 30, 'notes' => 20000, 'client_id' => 20];
$error = '';
$preClient = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$has002 = (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contacts' AND COLUMN_NAME='title'");
if (!$has002) $FIELDS = array_values(array_diff($FIELDS, ['title']));
$k = $id ? oneRow($pdo, 'SELECT * FROM contacts WHERE id=?', [$id]) : array_merge(array_fill_keys($FIELDS, null), ['client_id' => $preClient, 'status' => 'active']);
if ($id && !$k) { http_response_code(404); exit('Contact not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        m7verify();
        $v = [];
        foreach ($FIELDS as $f) if ($f !== 'contact_type') $v[$f] = cm_post($f, $LIMITS[$f] ?? 255);
        $types = array_values(array_filter(array_map('trim', is_array($_POST['contact_type'] ?? null) ? $_POST['contact_type'] : []), 'strlen'));
        $extra = cm_post('contact_type_other', 200);
        if ($extra !== '') $types = array_merge($types, array_map('trim', explode(',', $extra)));
        $v['contact_type'] = implode(', ', array_unique(array_filter($types, 'strlen')));
        if (mb_strlen($v['contact_type']) > 255) throw new InvalidArgumentException('Contact type is too long.');
        $clientId = cm_valid_client($pdo, $v['client_id'], true);
        if ($v['first_name'] === '' && $v['last_name'] === '') throw new InvalidArgumentException('Enter a first or last name.');
        if ($v['email'] !== '' && !validEmail($v['email'])) throw new InvalidArgumentException('Email address does not look valid.');
        if (!in_array($v['status'], ['active', 'archive'], true)) throw new InvalidArgumentException('Invalid status.');
        if (!array_key_exists($v['contact_method'], CM_CONTACT_METHODS) && $v['contact_method'] !== ($k['contact_method'] ?? null)) throw new InvalidArgumentException('Invalid preferred contact method.');
        $new = array_map('cm_nullable', $v); $new['client_id'] = $clientId; $new['status'] = $v['status'];
        $new['display_name'] = trim(implode(' ', array_filter([$v['first_name'], $v['middle_name'], $v['last_name']], 'strlen')));
        $pdo->beginTransaction();
        if ($id) {
            $s = $pdo->prepare('SELECT * FROM contacts WHERE id=? FOR UPDATE'); $s->execute([$id]); $old = $s->fetch();
            cm_check_ver($old, $FIELDS);
            // Imported names may contain parts we don't split out; keep the stored display name unless a name field changed.
            if (!cm_changes($old, array_intersect_key($new, array_flip(['first_name', 'middle_name', 'last_name'])))) unset($new['display_name']);
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE contacts SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                $label = $new['display_name'] ?? $old['display_name'];
                cm_audit($pdo, 'contact_updated', ['client_id' => $clientId], 'Contact ' . $label . ' updated: ' . implode(', ', array_keys($changes)), ['contact_id' => $id] + $changes);
                if (isset($changes['client_id']) && $old['client_id']) cm_audit($pdo, 'contact_moved', ['client_id' => (int)$old['client_id']], 'Contact ' . $label . ' moved to another client', ['contact_id' => $id, 'to_client_id' => $clientId]);
            }
            $pdo->commit();
            header('Location: ' . route('client', ['id' => $clientId, 'saved' => $changes ? 1 : 0]) . '#contacts', true, 303); exit;
        }
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO contacts (source_system,source_id,' . implode(',', array_map(fn($f) => "`$f`", $cols)) . ',updated_at) VALUES (\'app\',?,' . implode(',', array_fill(0, count($cols), '?')) . ',NOW())')
            ->execute([cm_new_source_id(), ...array_values($new)]);
        $newId = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'contact_created', ['client_id' => $clientId], 'Contact ' . $new['display_name'] . ' added', ['contact_id' => $newId] + array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        header('Location: ' . route('client', ['id' => $clientId, 'saved' => 1]) . '#contacts', true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'contact save #' . $id);
        foreach ($FIELDS as $f) if ($f !== 'contact_type') $k[$f] = is_string($_POST[$f] ?? null) ? trim($_POST[$f]) : ($k[$f] ?? null);
        if (isset($v['contact_type'])) $k['contact_type'] = $v['contact_type'];
    }
}
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM contacts WHERE id=?', [$id]), $FIELDS) : '';
$curTypes = array_filter(array_map('trim', explode(',', cm_list_text($k['contact_type'] ?? ''))), 'strlen');
$otherTypes = array_diff($curTypes, array_keys(CM_CONTACT_TYPES));
$clients = cm_client_options($pdo);
$clientName = $k['client_id'] ? (string)(oneRow($pdo, 'SELECT name FROM clients WHERE id=?', [(int)$k['client_id']])['name'] ?? '') : '';
$input = function (string $f, string $label, array $o = []) use ($k, $LIMITS) {
    echo '<div class="field"><label for="' . h($f) . '">' . h($label) . '</label><input id="' . h($f) . '" name="' . h($f) . '" type="' . h($o['type'] ?? 'text') . '" maxlength="' . (int)($LIMITS[$f] ?? 255) . '" value="' . h($k[$f] ?? '') . '"' . (!empty($o['im']) ? ' inputmode="' . h($o['im']) . '"' : '') . ' autocomplete="off"></div>';
};
$title = $id ? 'Edit contact' : 'New contact';
cm_page_start($title, 'clients', ['back' => $k['client_id'] ? [route('client', ['id' => $k['client_id']]), $clientName ?: 'Client'] : [route('clients'), 'Clients'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($id && !empty($k['legacy_record'])): ?><p class="small muted">Imported contact (original ID <?=h($k['source_id'])?>). Original imported values are kept unchanged.<?php if (!$k['client_id']): ?> <strong>This contact had no client in the old system — choose one below.</strong><?php endif; ?></p><?php endif; ?>
<form method="post" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>">
<div class="field"><label for="client">Client</label><?php cm_client_picker($clients, (string)($k['client_id'] ?? ''), true); ?></div>
<div class="pair"><?php if ($has002) $input('title', 'Title (Mr, Ms, Dr…)'); $input('first_name', 'First name'); ?></div>
<div class="pair"><?php $input('last_name', 'Last name'); ?></div>
<div class="pair"><?php $input('middle_name', 'Middle name'); $input('position', 'Position / role'); ?></div>
<div class="pair"><?php $input('mobile', 'Mobile', ['type' => 'tel', 'im' => 'tel']); $input('phone', 'Phone', ['type' => 'tel', 'im' => 'tel']); ?></div>
<?php $input('email', 'Email', ['type' => 'email']); ?>
<fieldset class="field"><legend>Contact for</legend><div class="checks"><?php foreach (CM_CONTACT_TYPES as $t => $l): ?><label class="check"><input type="checkbox" name="contact_type[]" value="<?=h($t)?>"<?=in_array($t, $curTypes, true) ? ' checked' : ''?>> <?=h($l)?></label><?php endforeach; ?></div>
<label for="contact_type_other" class="small">Other (comma-separated)</label><input id="contact_type_other" name="contact_type_other" maxlength="200" value="<?=h(implode(', ', $otherTypes))?>"></fieldset>
<div class="pair"><div class="field"><label for="contact_method">Preferred contact</label><select id="contact_method" name="contact_method"><?php foreach (CM_CONTACT_METHODS as $m => $l): ?><option value="<?=h($m)?>"<?=(string)($k['contact_method'] ?? '') === $m ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?><?php if (!array_key_exists((string)($k['contact_method'] ?? ''), CM_CONTACT_METHODS)): ?><option value="<?=h($k['contact_method'])?>" selected><?=h($k['contact_method'])?></option><?php endif; ?></select></div>
<div class="field"><label for="status">Status</label><select id="status" name="status"><option value="active"<?=($k['status'] ?? 'active') !== 'archive' ? ' selected' : ''?>>Active</option><option value="archive"<?=($k['status'] ?? '') === 'archive' ? ' selected' : ''?>>Archived</option></select></div></div>
<details<?=($k['notes'] ?? '') !== '' ? ' open' : ''?>><summary>Notes</summary><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="20000"><?=h($k['notes'] ?? '')?></textarea></div></details>
<div class="form-actions"><button type="submit" class="save"><?=$id ? 'Save contact' : 'Add contact'?></button><a class="cancel" href="<?=h($k['client_id'] ? route('client', ['id' => $k['client_id']]) : route('clients'))?>">Cancel</a></div>
</form>
<?php cm_page_end('clients');
