<?php
declare(strict_types=1);
/*
 * Quick Enquiry. v0.11: received date, contact (or a new contact), category › type with the
 * title filled from the type. ?client_id= preselects the client. One optional price line; more lines,
 * planned tasks and milestones are added on the enquiry (v0.12). v0.12: optional "Start from a template"
 * adds the template as a planned task with its price lines and milestones. One transaction.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
require_once __DIR__ . '/inc/edit.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/enquiry.php';
cm_require_login();
$pdo = cm_pdo();

if (empty($_SESSION['m6_csrf'])) $_SESSION['m6_csrf'] = bin2hex(random_bytes(32));
$values = cm_enquiry_form_values(null) + ['line_description' => '', 'line_quantity' => '1', 'line_unit_price' => '', 'line_pricing' => 'set', 'template_id' => ''];
$templates = cm_template_options($pdo);
$pre = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($pre) $values['client_id'] = (string)$pre;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = array_merge($values, cm_enquiry_posted());
    foreach (['line_description', 'line_quantity', 'line_unit_price', 'line_pricing', 'template_id'] as $f) $values[$f] = is_string($_POST[$f] ?? null) ? trim($_POST[$f]) : $values[$f];
    try {
        $tpl = null;
        if ($values['template_id'] !== '') {
            $tpl = ctype_digit($values['template_id']) ? oneRow($pdo, 'SELECT * FROM task_templates WHERE id=? AND active=1', [(int)$values['template_id']]) : null;
            if (!$tpl) throw new InvalidArgumentException('That template is no longer available.');
            // An empty title, category or type is taken from the template.
            if ($values['title'] === '') $values['title'] = $tpl['title'];
            if ($values['task_category'] === '' && $tpl['task_category']) { $values['task_category'] = (string)$tpl['task_category']; if ($values['task_type'] === '') $values['task_type'] = (string)$tpl['task_type']; }
        }
        if (!hash_equals($_SESSION['m6_csrf'], (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Session expired; reload the form.');
        $new = cm_enquiry_validate($pdo, $values, cm_task_classes($pdo), null);
        $new['status'] = 'enquiry';
        $hasLine = $values['line_description'] !== '' || $values['line_unit_price'] !== '';
        if ($hasLine) {
            if (mb_strlen($values['line_description']) < 3 || mb_strlen($values['line_description']) > 5000) throw new InvalidArgumentException('Price line needs a description (3–5000 characters).');
            if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $values['line_quantity']) || (float)$values['line_quantity'] <= 0) throw new InvalidArgumentException('Price line quantity must be positive (up to 3 decimals).');
            if ($values['line_unit_price'] === '' || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $values['line_unit_price'])) throw new InvalidArgumentException('Price line needs a non-negative unit price (ex GST).');
            if (!in_array($values['line_pricing'], ['hourly', 'set', 'web_service'], true)) throw new InvalidArgumentException('Invalid pricing type.');
        }
        $pdo->beginTransaction();
        if ($values['new_contact_name'] !== '') $new['contact_id'] = cm_enquiry_new_contact($pdo, $new['client_id'], $values);
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO enquiries (' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($new));
        $newId = (int)$pdo->lastInsertId();
        if ($hasLine) {
            $pdo->prepare("INSERT INTO breakdown_items (enquiry_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at) VALUES (?,?,?,?,?,'proposed',1,NOW())")
                ->execute([$newId, $values['line_description'], $values['line_quantity'], $values['line_unit_price'], $values['line_pricing']]);
        }
        if ($tpl) cm_plan_from_template($pdo, $newId, (int)$tpl['id']);
        cm_audit($pdo, 'enquiry_created', ['client_id' => $new['client_id'], 'enquiry_id' => $newId], 'Enquiry created: ' . $new['title'], array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        $_SESSION['m6_csrf'] = bin2hex(random_bytes(32));
        header('Location: add-enquiry.php?saved=' . $newId, true, 303); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) $errors[] = $e->getMessage();
        else { error_log('Cloake enquiry save: ' . $e->getMessage()); $errors[] = 'Unable to save the enquiry. No changes were made.'; }
    }
}
$savedId = filter_var($_GET['saved'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$cancel = $pre ? route('client', ['id' => $pre]) : route('enquiries');

cm_page_start('Quick Enquiry', 'enquiries', ['back' => [route('enquiries'), 'Enquiries'], 'bodyClass' => 'form-page']);
?>
<p class="muted">Capture the request first. Quoting and task creation come next.</p>
<?php if ($savedId): ?><div class="success" role="status"><strong>Enquiry #<?=h($savedId)?> saved.</strong> <a href="convert-enquiry.php?id=<?=(int)$savedId?>">Open enquiry</a> · <a href="<?=h(route('enquiries'))?>">All enquiries</a></div><?php endif; ?>
<?php if ($errors): ?><div class="alert" role="alert"><strong>Please check:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=h($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form action="add-enquiry.php<?=$pre ? '?client_id=' . (int)$pre : ''?>" method="post" autocomplete="off" class="form" data-task-form><input type="hidden" name="csrf" value="<?=h($_SESSION['m6_csrf'])?>">
<?php cm_enquiry_fields($pdo, $values, true); ?>
<?php if ($templates): ?><div class="field"><label for="template_id">Start from a template <span class="opt">(optional)</span></label><select id="template_id" name="template_id" data-template-select><option value="">—</option><?php foreach ($templates as $tt): ?><option value="<?=(int)$tt['id']?>" data-title="<?=h($tt['title'])?>"<?=$values['template_id'] === (string)$tt['id'] ? ' selected' : ''?>><?=h($tt['title'])?><?=$tt['total'] !== null ? ' — ' . h(cm_money($tt['total'])) . ' ex GST' : ''?></option><?php endforeach; ?></select><p class="helper">Adds it as a planned task with its price lines and milestones. More can be added on the enquiry.</p></div><?php endif; ?>
<details<?=($values['line_description'] . $values['line_unit_price']) !== '' ? ' open' : ''?>><summary>Optional price estimate</summary><p class="helper">One proposed line, excluding GST. Add more lines, planned tasks and milestones on the enquiry. This does not create a quote or invoice.</p><div class="field"><label for="line_description">Line description</label><textarea id="line_description" name="line_description"><?=h($values['line_description'])?></textarea></div><div class="pair"><div class="field"><label for="line_quantity">Quantity</label><input id="line_quantity" name="line_quantity" inputmode="decimal" value="<?=h($values['line_quantity'])?>"></div><div class="field"><label for="line_unit_price">Unit price (ex GST)</label><input id="line_unit_price" name="line_unit_price" inputmode="decimal" placeholder="0.00" value="<?=h($values['line_unit_price'])?>"></div></div><div class="field"><label for="line_pricing">Pricing type</label><select id="line_pricing" name="line_pricing"><?php foreach (['set' => 'Set fee', 'hourly' => 'Hourly', 'web_service' => 'Web service'] as $k => $v): ?><option value="<?=h($k)?>"<?=$values['line_pricing'] === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div></details>
<div class="form-actions"><button class="save" type="submit">Save enquiry</button><a class="cancel" href="<?=h($cancel)?>">Cancel</a></div>
<p class="helper">No task, quote or Xero document is created.</p></form>
<?php cm_page_end('enquiries');
