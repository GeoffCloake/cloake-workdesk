<?php
declare(strict_types=1);
/*
 * Enquiry form fields, validation and the optional new contact (v0.11).
 * Used by add-enquiry.php (new) and edit-enquiry.php (edit). Needs edit.php + forms.php + queries.php.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_ENQUIRY_FIELDS = ['client_id', 'contact_id', 'received_on', 'task_category', 'task_type', 'title', 'details', 'priority', 'required_by', 'next_action', 'next_action_due', 'waiting_for', 'status'];

/** Form values from a stored enquiry row (or defaults for a new one). */
function cm_enquiry_form_values(?array $e): array
{
    $v = array_fill_keys(CM_ENQUIRY_FIELDS, '');
    $v['priority'] = 'normal'; $v['status'] = 'enquiry'; $v['received_on'] = date('Y-m-d');
    $v['new_contact_name'] = $v['new_contact_email'] = $v['new_contact_phone'] = '';
    if ($e) {
        foreach (CM_ENQUIRY_FIELDS as $f) if (array_key_exists($f, $e)) $v[$f] = (string)($e[$f] ?? '');
        $v['received_on'] = substr((string)($e['received_at'] ?? $e['created_at'] ?? ''), 0, 10);
    }
    return $v;
}

/** Posted values (trimmed strings). */
function cm_enquiry_posted(): array
{
    $v = [];
    foreach (array_merge(CM_ENQUIRY_FIELDS, ['new_contact_name', 'new_contact_email', 'new_contact_phone']) as $f) $v[$f] = is_string($_POST[$f] ?? null) ? trim($_POST[$f]) : '';
    return $v;
}

/**
 * Validate posted values. Returns the column values to store (without contact if a new contact is to be created).
 * $old = stored row when editing. Throws InvalidArgumentException with a user-facing message.
 */
function cm_enquiry_validate(PDO $pdo, array $v, array $classes, ?array $old): array
{
    $clientId = cm_valid_client($pdo, $v['client_id'], true);
    if (mb_strlen($v['title']) < 3 || mb_strlen($v['title']) > 255) throw new InvalidArgumentException('Title must be between 3 and 255 characters.');
    if (mb_strlen($v['details']) > 30000) throw new InvalidArgumentException('Description is too long.');
    if (mb_strlen($v['next_action']) > 10000 || mb_strlen($v['waiting_for']) > 80) throw new InvalidArgumentException('Follow-up details are too long.');
    $priorities = array_merge(array_keys(CM_PRIORITIES), CM_LEGACY_PRIORITIES);
    if (!in_array($v['priority'], $priorities, true)) throw new InvalidArgumentException('Invalid priority.');
    $received = m7date($v['received_on']);
    if ($received === null) throw new InvalidArgumentException('Enter the date the enquiry was received.');
    if ($received > date('Y-m-d')) throw new InvalidArgumentException('The received date cannot be in the future.');
    // Keep the stored time when the date is unchanged; a new enquiry received today gets the current time.
    if ($old && substr((string)($old['received_at'] ?? ''), 0, 10) === $received) $receivedAt = $old['received_at'];
    elseif ($received === date('Y-m-d')) $receivedAt = date('Y-m-d H:i:s');
    else $receivedAt = $received . ' 00:00:00';
    $cat = $v['task_category']; $type = $v['task_type'];
    $keep = fn($f, $val) => $old && (string)($old[$f] ?? '') === $val;   // unusual stored values stay valid
    if ($cat !== '' && !isset($classes[$cat]) && !$keep('task_category', $cat)) throw new InvalidArgumentException('Choose a category from the list.');
    if ($type !== '' && !in_array($type, $classes[$cat] ?? [], true) && !$keep('task_type', $type)) throw new InvalidArgumentException('Choose a type that belongs to the category.');
    $contactId = null;
    if ($v['contact_id'] !== '') {
        if (!ctype_digit($v['contact_id'])) throw new InvalidArgumentException('Invalid contact.');
        $k = oneRow($pdo, 'SELECT id,client_id FROM contacts WHERE id=?', [(int)$v['contact_id']]);
        if (!$k) throw new InvalidArgumentException('That contact no longer exists.');
        if ((int)$k['client_id'] !== $clientId && !$keep('contact_id', $v['contact_id'])) throw new InvalidArgumentException('That contact belongs to a different client.');
        $contactId = (int)$k['id'];
    }
    if ($v['new_contact_name'] !== '') {
        if (mb_strlen($v['new_contact_name']) > 200) throw new InvalidArgumentException('New contact name is too long.');
        if ($v['new_contact_email'] !== '' && !filter_var($v['new_contact_email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('New contact email does not look valid.');
        if (mb_strlen($v['new_contact_phone']) > 64) throw new InvalidArgumentException('New contact phone is too long.');
    }
    $status = $v['status'] === '' ? ($old['status'] ?? 'enquiry') : $v['status'];
    $allowed = array_keys(CM_ENQUIRY_STATUSES);
    if (!in_array($status, $allowed, true) && !$keep('status', $status)) throw new InvalidArgumentException('Invalid status.');
    if ($status === 'duplicate' && !($old && $old['status'] === 'duplicate')) throw new InvalidArgumentException('Use "Withdraw or mark duplicate" to mark a duplicate.');
    return [
        'client_id' => $clientId, 'contact_id' => $contactId, 'received_at' => $receivedAt,
        'task_category' => cm_nullable($cat), 'task_type' => cm_nullable($type),
        'title' => $v['title'], 'details' => cm_nullable($v['details']), 'priority' => $v['priority'],
        'required_by' => m7date($v['required_by']), 'next_action' => cm_nullable($v['next_action']),
        'next_action_due' => m7date($v['next_action_due']), 'waiting_for' => cm_nullable($v['waiting_for']),
        'status' => $status,
    ];
}

/** Create the inline new contact (inside the caller's transaction). Returns its id. */
function cm_enquiry_new_contact(PDO $pdo, int $clientId, array $v): int
{
    $parts = preg_split('/\s+/', $v['new_contact_name'], 2);
    $first = $parts[0]; $last = $parts[1] ?? null;
    $pdo->prepare("INSERT INTO contacts (source_system,source_id,client_id,display_name,first_name,last_name,email,phone,status,updated_at) VALUES ('app',?,?,?,?,?,?,?,'active',NOW())")
        ->execute([cm_new_source_id(), $clientId, $v['new_contact_name'], $first, $last, cm_nullable($v['new_contact_email']), cm_nullable($v['new_contact_phone'])]);
    $id = (int)$pdo->lastInsertId();
    cm_audit($pdo, 'contact_created', ['client_id' => $clientId], 'Contact ' . $v['new_contact_name'] . ' added (from an enquiry)', ['contact_id' => $id, 'display_name' => $v['new_contact_name']]);
    return $id;
}

/** Shared fields for the new and edit forms. */
function cm_enquiry_fields(PDO $pdo, array $v, bool $isNew): void
{
    $classes = cm_task_classes($pdo);
    $contacts = $v['client_id'] !== '' && ctype_digit($v['client_id']) ? allRows($pdo, "SELECT id,display_name,status FROM contacts WHERE client_id=? ORDER BY (COALESCE(status,'')='archive'), display_name", [(int)$v['client_id']]) : [];
    if ($v['contact_id'] !== '' && !in_array($v['contact_id'], array_map('strval', array_column($contacts, 'id')), true)) {
        $ck = oneRow($pdo, 'SELECT id,display_name,status FROM contacts WHERE id=?', [(int)$v['contact_id']]);
        if ($ck) { $ck['display_name'] .= ' (another client)'; $contacts[] = $ck; }
    }
    $types = $classes[$v['task_category']] ?? [];
    if ($v['task_type'] !== '' && !in_array($v['task_type'], $types, true)) $types[] = $v['task_type'];
    $priorities = CM_PRIORITIES; if (!isset($priorities[$v['priority']]) && $v['priority'] !== '') $priorities[$v['priority']] = cm_status_label($v['priority']) . ' (current)';
    ?>
<div class="field"><label for="client">Client</label><?php cm_client_picker(cm_client_options($pdo), $v['client_id'], true); ?></div>
<div class="pair"><div class="field"><label for="contact_id">Contact</label><select id="contact_id" name="contact_id" data-contact-select><option value="">—</option><?php foreach ($contacts as $k): ?><option value="<?=(int)$k['id']?>"<?=(string)$k['id'] === $v['contact_id'] ? ' selected' : ''?>><?=h($k['display_name'] . (($k['status'] ?? '') === 'archive' ? ' (archived)' : ''))?></option><?php endforeach; ?></select><p class="helper" data-contact-help><?=$v['client_id'] === '' ? 'Choose a client to list its contacts.' : ($contacts ? '' : 'This client has no contacts yet.')?></p></div>
<div class="field"><label for="received_on">Received</label><input type="date" id="received_on" name="received_on" required max="<?=h(date('Y-m-d'))?>" value="<?=h($v['received_on'])?>"></div></div>
<details<?=$v['new_contact_name'] !== '' ? ' open' : ''?>><summary>＋ New contact</summary><p class="helper">Adds a contact to the chosen client and uses it for this enquiry.</p><div class="field"><label for="new_contact_name">Name</label><input id="new_contact_name" name="new_contact_name" maxlength="200" autocomplete="off" value="<?=h($v['new_contact_name'])?>"></div><div class="pair"><div class="field"><label for="new_contact_email">Email</label><input id="new_contact_email" name="new_contact_email" type="email" maxlength="255" value="<?=h($v['new_contact_email'])?>"></div><div class="field"><label for="new_contact_phone">Phone</label><input id="new_contact_phone" name="new_contact_phone" type="tel" maxlength="64" value="<?=h($v['new_contact_phone'])?>"></div></div></details>
<div class="pair"><div class="field"><label for="task_category">Category</label><select id="task_category" name="task_category" data-category-select><option value="">—</option><?php foreach (array_keys($classes) as $c): ?><option value="<?=h($c)?>"<?=$c === $v['task_category'] ? ' selected' : ''?>><?=h($c)?></option><?php endforeach; ?><?php if ($v['task_category'] !== '' && !isset($classes[$v['task_category']])): ?><option value="<?=h($v['task_category'])?>" selected><?=h($v['task_category'])?> (current)</option><?php endif; ?></select></div>
<div class="field"><label for="task_type">Type</label><select id="task_type" name="task_type" data-type-select data-types="<?=h(json_encode($classes, JSON_UNESCAPED_UNICODE))?>"><option value="">—</option><?php foreach ($types as $t): ?><option value="<?=h($t)?>"<?=$t === $v['task_type'] ? ' selected' : ''?>><?=h($t)?></option><?php endforeach; ?></select></div></div>
<div class="field"><label for="title">Enquiry / job title</label><input id="title" name="title" required maxlength="255" value="<?=h($v['title'])?>" placeholder="What is needed?" data-title-from-type<?=$isNew ? '' : ' data-title-manual'?>><p class="helper">Choosing a type fills this in; you can change it.</p></div>
<div class="field"><label for="details">Description</label><textarea id="details" name="details" placeholder="Brief instructions or notes"><?=h($v['details'])?></textarea></div>
<div class="pair"><div class="field"><label for="required_by">Required by</label><input type="date" id="required_by" name="required_by" value="<?=h($v['required_by'])?>"></div><div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach ($priorities as $k => $label): ?><option value="<?=h($k)?>"<?=$v['priority'] === $k ? ' selected' : ''?>><?=h($label)?></option><?php endforeach; ?></select></div></div>
<?php if (!$isNew): ?><div class="field"><label for="status">Status</label><select id="status" name="status"><?php foreach (CM_ENQUIRY_STATUSES as $k => $label): if ($k === 'duplicate' && $v['status'] !== 'duplicate') continue; ?><option value="<?=h($k)?>"<?=$v['status'] === $k ? ' selected' : ''?>><?=h($label)?></option><?php endforeach; ?><?php if (!isset(CM_ENQUIRY_STATUSES[$v['status']])): ?><option value="<?=h($v['status'])?>" selected><?=h(cm_status_label($v['status']))?> (current)</option><?php endif; ?></select><p class="helper">Setting an open status again clears a withdrawn or duplicate mark.</p></div><?php endif; ?>
<details<?=($v['next_action'] . $v['next_action_due'] . $v['waiting_for']) !== '' ? ' open' : ''?>><summary>Next action &amp; follow-up</summary><div class="field"><label for="next_action">Next action</label><textarea name="next_action" id="next_action"><?=h($v['next_action'])?></textarea></div><div class="pair"><div class="field"><label for="next_action_due">Follow up on</label><input type="date" name="next_action_due" id="next_action_due" value="<?=h($v['next_action_due'])?>"></div><div class="field"><label for="waiting_for">Waiting for</label><input name="waiting_for" id="waiting_for" maxlength="80" value="<?=h($v['waiting_for'])?>"></div></div></details>
<?php
}
