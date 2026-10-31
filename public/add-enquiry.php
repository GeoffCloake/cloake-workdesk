<?php
declare(strict_types=1);
/*
 * Quick Enquiry (M6 write path, kept). New in v0.9.0: ?client_id= preselects
 * the client (from the client page), client type-ahead, and a direct link to
 * review/convert after saving. Validation, columns and transaction unchanged.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
cm_require_login();
$pdo = cm_pdo();
function validDate(string $value): bool { if ($value === '') return true; $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value; }

if (empty($_SESSION['m6_csrf'])) $_SESSION['m6_csrf'] = bin2hex(random_bytes(32));
$values = ['client_id' => '', 'title' => '', 'details' => '', 'required_by' => '', 'priority' => 'normal', 'next_action' => '', 'next_action_due' => '', 'waiting_for' => '', 'line_description' => '', 'line_quantity' => '1', 'line_unit_price' => '', 'line_pricing' => 'set'];
$pre = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($pre) $values['client_id'] = (string)$pre;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) { $raw = $_POST[$field] ?? $default; $values[$field] = is_string($raw) ? trim($raw) : ''; }
    if (!hash_equals($_SESSION['m6_csrf'], (string)($_POST['csrf'] ?? ''))) $errors[] = 'Session expired; reload the form.';
    if (!ctype_digit($values['client_id']) || (int)$values['client_id'] < 1) $errors[] = 'Choose an existing client.';
    if (mb_strlen($values['title']) < 3 || mb_strlen($values['title']) > 255) $errors[] = 'Title must be between 3 and 255 characters.';
    if (mb_strlen($values['details']) > 30000) $errors[] = 'Description is too long.';
    if (!validDate($values['required_by']) || !validDate($values['next_action_due'])) $errors[] = 'Enter valid dates.';
    if (!in_array($values['priority'], ['low', 'normal', 'high', 'urgent'], true)) $errors[] = 'Invalid priority.';
    if (mb_strlen($values['next_action']) > 10000 || mb_strlen($values['waiting_for']) > 80) $errors[] = 'Follow-up details are too long.';
    $hasLine = $values['line_description'] !== '' || $values['line_unit_price'] !== '';
    if ($hasLine) {
        if (mb_strlen($values['line_description']) < 3 || mb_strlen($values['line_description']) > 5000) $errors[] = 'Breakdown needs a description (3–5000 characters).';
        if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $values['line_quantity']) || (float)$values['line_quantity'] <= 0) $errors[] = 'Breakdown quantity must be positive (up to 3 decimals).';
        if ($values['line_unit_price'] === '' || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $values['line_unit_price'])) $errors[] = 'Breakdown needs a non-negative unit price (ex GST).';
        if (!in_array($values['line_pricing'], ['hourly', 'set', 'web_service'], true)) $errors[] = 'Invalid pricing mode.';
    }
    $clientId = (int)$values['client_id'];
    if (!$errors) { $stmt = $pdo->prepare('SELECT id FROM clients WHERE id=?'); $stmt->execute([$clientId]); if (!$stmt->fetchColumn()) $errors[] = 'Client not found.'; }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO enquiries (client_id,title,details,required_by,priority,next_action,next_action_due,waiting_for,status) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$clientId, $values['title'], $values['details'] ?: null, $values['required_by'] ?: null, $values['priority'], $values['next_action'] ?: null, $values['next_action_due'] ?: null, $values['waiting_for'] ?: null, 'enquiry']);
            $newId = (int)$pdo->lastInsertId();
            if ($hasLine) {
                $stmt = $pdo->prepare("INSERT INTO breakdown_items (enquiry_id,description,quantity,unit_price_ex_gst,pricing_mode,status) VALUES (?,?,?,?,?,'proposed')");
                $stmt->execute([$newId, $values['line_description'], $values['line_quantity'], $values['line_unit_price'], $values['line_pricing']]);
            }
            $stmt = $pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,event_kind,event_at,summary) VALUES (?,?,'enquiry_created',NOW(),?)");
            $stmt->execute([$clientId, $newId, 'Quick Enquiry created in staging']);
            $pdo->commit();
            $_SESSION['m6_csrf'] = bin2hex(random_bytes(32));
            header('Location: add-enquiry.php?saved=' . $newId, true, 303); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('M6 enquiry save: ' . $e->getMessage()); $errors[] = 'Unable to save enquiry. No changes committed.'; }
    }
}
try { $clients = cm_client_options($pdo); } catch (Throwable $e) { error_log('M6 clients: ' . $e->getMessage()); $clients = []; $errors[] = 'Client list unavailable.'; }
$savedId = filter_var($_GET['saved'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

cm_page_start('Quick Enquiry', 'enquiries', ['back' => [route('enquiries'), 'Enquiries'], 'bodyClass' => 'form-page']);
?>
<p class="muted">Capture the request first. Quoting and task conversion come later.</p>
<?php if ($savedId): ?><div class="success" role="status"><strong>Enquiry #<?=h($savedId)?> saved.</strong> <a href="convert-enquiry.php?id=<?=(int)$savedId?>">Review / create task</a> · <a href="<?=h(route('enquiries'))?>">All enquiries</a></div><?php endif; ?>
<?php if ($errors): ?><div class="alert" role="alert"><strong>Please check:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=h($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form action="add-enquiry.php" method="post" autocomplete="off" class="form"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6_csrf'])?>">
<div class="field"><label for="client">Client</label><?php cm_client_picker($clients, $values['client_id'], true); ?></div>
<div class="field"><label for="title">Enquiry / job title</label><input id="title" name="title" required maxlength="255" value="<?=h($values['title'])?>" placeholder="What is needed?"></div>
<div class="field"><label for="details">Description</label><textarea id="details" name="details" placeholder="Brief instructions or notes"><?=h($values['details'])?></textarea></div>
<div class="pair"><div class="field"><label for="required_by">Required by</label><input type="date" id="required_by" name="required_by" value="<?=h($values['required_by'])?>"></div><div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $k => $v): ?><option value="<?=h($k)?>"<?=$values['priority'] === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div></div>
<details<?=($values['next_action'] . $values['next_action_due'] . $values['waiting_for']) !== '' ? ' open' : ''?>><summary>Next action &amp; follow-up</summary><div class="field"><label for="next_action">Next action</label><textarea name="next_action" id="next_action"><?=h($values['next_action'])?></textarea></div><div class="pair"><div class="field"><label for="next_action_due">Follow up on</label><input type="date" name="next_action_due" id="next_action_due" value="<?=h($values['next_action_due'])?>"></div><div class="field"><label for="waiting_for">Waiting for</label><input name="waiting_for" id="waiting_for" maxlength="80" value="<?=h($values['waiting_for'])?>"></div></div></details>
<details<?=($values['line_description'] . $values['line_unit_price']) !== '' ? ' open' : ''?>><summary>Optional breakdown / price estimate</summary><p class="helper">One proposed line item, excluding GST. This does not generate a quote or invoice.</p><div class="field"><label for="line_description">Line description</label><textarea id="line_description" name="line_description"><?=h($values['line_description'])?></textarea></div><div class="pair"><div class="field"><label for="line_quantity">Quantity</label><input id="line_quantity" name="line_quantity" inputmode="decimal" value="<?=h($values['line_quantity'])?>"></div><div class="field"><label for="line_unit_price">Unit price (ex GST)</label><input id="line_unit_price" name="line_unit_price" inputmode="decimal" placeholder="0.00" value="<?=h($values['line_unit_price'])?>"></div></div><div class="field"><label for="line_pricing">Pricing type</label><select id="line_pricing" name="line_pricing"><?php foreach (['set' => 'Set fee', 'hourly' => 'Hourly', 'web_service' => 'Web service'] as $k => $v): ?><option value="<?=h($k)?>"<?=$values['line_pricing'] === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div></details>
<button class="save" type="submit">Save enquiry</button><p class="helper">Staging only. No task, quote or Xero document will be created.</p></form>
<?php cm_page_end('enquiries');
