<?php
declare(strict_types=1);
/*
 * New / edit service (v0.13). Audited; edits are undoable (service_updated, payload row_id).
 *   action=save          new or edited service
 *   action=correct_date  change the next period start — a reason is required and recorded
 *   action=include       (v0.16) include a domain in one of the client's hosting services (or take it out) — not billed on its own
 *   action=provision     (v0.16) create the set-up task from a template and link it to this service
 * The next period otherwise only moves when an invoice is recorded (invoice-record.php).
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/forms.php';
require_once __DIR__ . '/inc/billing-lib.php';
cm_require_login();
$pdo = cm_pdo();
$id = m7id();
$FIELDS = ['client_id', 'kind', 'domain', 'label', 'plan_name', 'status', 'cycle_months', 'price_ex_gst', 'xero_account', 'billable', 'is_internal', 'expiry_date', 'registrar', 'cpanel_username', 'check_status', 'check_note', 'notes'];
$DOM = cm_has_domains($pdo) ? ['nameservers', 'dns_host', 'auto_renew', 'included_in_service_id', 'provider_ref'] : [];
$PLN = cm_has_plans($pdo) ? ['plan_id', 'mailboxes'] : [];   // v0.19
$KB = cm_has_keep_billing($pdo) ? ['keep_billing'] : [];      // v0.21
$FIELDS = array_merge($FIELDS, $DOM, $PLN, $KB);
$plans = cm_plans($pdo);
$pre = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$s = $id ? oneRow($pdo, 'SELECT * FROM services WHERE id=?', [$id]) : array_merge(array_fill_keys($FIELDS, null), ['client_id' => $pre, 'kind' => 'hosting', 'status' => 'active', 'billable' => 1, 'is_internal' => 0, 'cycle_months' => 6, 'next_period_start' => null]);
if ($id && !$s) { http_response_code(404); exit('Service not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        $pdo->beginTransaction();
        $old = $id ? oneRow($pdo, 'SELECT * FROM services WHERE id=? FOR UPDATE', [$id]) : null;
        if ($id && !$old) throw new InvalidArgumentException('Service not found.');
        if ($action === 'correct_date') {
            $np = m7date(cm_post('next_period_start', 10)); $why = cm_post('reason', 255);
            if (!$np) throw new InvalidArgumentException('Enter the date the next period starts.');
            if ($why === '') throw new InvalidArgumentException('Give a reason for changing the date.');
            $changes = cm_changes($old, ['next_period_start' => $np]);
            if ($changes) {
                $pdo->prepare('UPDATE services SET next_period_start=?, updated_at=NOW() WHERE id=?')->execute([$np, $id]);
                cm_audit($pdo, 'service_updated', ['client_id' => $old['client_id']], 'Next period of ' . ($old['domain'] ?: $old['label']) . ' changed to ' . cm_dmy($np) . ': ' . $why, ['row_id' => $id, 'reason' => $why] + $changes);
            }
            $pdo->commit();
            header('Location: ' . route('service', ['id' => $id, 'saved' => 1]), true, 303); exit;
        }
        if ($action === 'include' && $DOM) {
            $inc = cm_valid_included($pdo, cm_post('included_in_service_id', 20), $old['client_id'] !== null ? (int)$old['client_id'] : null, $id);
            if ($old['kind'] !== 'domain') throw new InvalidArgumentException('Only a domain can be included in a hosting service.');
            $changes = cm_changes($old, ['included_in_service_id' => $inc] + ($inc ? ['billable' => 0] : []));
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE services SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                $hn = $inc ? (string)(oneRow($pdo, 'SELECT COALESCE(domain,label) n FROM services WHERE id=?', [$inc])['n'] ?? '') : '';
                cm_audit($pdo, 'service_updated', ['client_id' => $old['client_id']], ($old['domain'] ?: $old['label']) . ($inc ? ' included in hosting ' . $hn : ' no longer included in a hosting service'), ['row_id' => $id] + $changes);
            }
            $pdo->commit();
            header('Location: ' . route('service', ['id' => $id, 'saved' => $changes ? 1 : 0]), true, 303); exit;
        }
        if ($action === 'provision' && $DOM) {
            if ($old['provision_task_id']) throw new InvalidArgumentException('This service already has a set-up task.');
            $tid = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            if (!$tid) throw new InvalidArgumentException('Choose a template for the set-up task.');
            $taskId = cm_task_from_template($pdo, $tid, $old['client_id'] !== null ? (int)$old['client_id'] : null, 'Set up: ' . ($old['domain'] ?: $old['label']));
            $pdo->prepare('UPDATE tasks SET domains=? WHERE id=?')->execute([$old['domain'], $taskId]);
            $pdo->prepare('UPDATE services SET provision_task_id=?, updated_at=NOW() WHERE id=?')->execute([$taskId, $id]);
            $pdo->commit();
            header('Location: ' . route('task', ['id' => $taskId, 'saved' => 1]), true, 303); exit;
        }
        $raw = [];
        foreach (['client_id' => 20, 'kind' => 20, 'domain' => 255, 'label' => 255, 'plan_name' => 120, 'status' => 20, 'cycle_months' => 3, 'price_ex_gst' => 14, 'xero_account' => 20, 'expiry_date' => 10, 'registrar' => 60, 'cpanel_username' => 60, 'check_status' => 20, 'check_note' => 500, 'notes' => 5000, 'next_period_start' => 10, 'nameservers' => 255, 'dns_host' => 60, 'auto_renew' => 2, 'included_in_service_id' => 20, 'provider_ref' => 100, 'plan_id' => 20, 'mailboxes' => 4] as $f => $max) $raw[$f] = cm_post($f, $max);
        if ($id) cm_check_ver($old, $FIELDS);
        $new = ['client_id' => cm_valid_client($pdo, $raw['client_id'], true), 'kind' => $raw['kind'], 'domain' => cm_nullable(strtolower($raw['domain'])), 'label' => cm_nullable($raw['label']), 'plan_name' => cm_nullable($raw['plan_name']),
            'status' => $raw['status'], 'cycle_months' => $raw['cycle_months'] === '' ? null : (int)$raw['cycle_months'], 'price_ex_gst' => cm_nullable(str_replace([',', '$'], '', $raw['price_ex_gst'])),
            'xero_account' => cm_nullable($raw['xero_account']), 'billable' => !empty($_POST['billable']) ? 1 : 0, 'is_internal' => !empty($_POST['is_internal']) ? 1 : 0, 'expiry_date' => m7date($raw['expiry_date']),
            'registrar' => cm_nullable($raw['registrar']), 'cpanel_username' => cm_nullable($raw['cpanel_username']), 'check_status' => cm_nullable($raw['check_status']), 'check_note' => cm_nullable($raw['check_note']), 'notes' => cm_nullable($raw['notes'])];
        if ($DOM) {
            if (!in_array($raw['auto_renew'], ['', '0', '1'], true)) throw new InvalidArgumentException('Auto-renew must be yes, no or not known.');
            $new += ['nameservers' => cm_nullable(strtolower($raw['nameservers'])), 'dns_host' => cm_nullable($raw['dns_host']), 'auto_renew' => $raw['auto_renew'] === '' ? null : (int)$raw['auto_renew'],
                'included_in_service_id' => $new['kind'] === 'domain' ? cm_valid_included($pdo, $raw['included_in_service_id'], $new['client_id'] !== null ? (int)$new['client_id'] : null, (int)$id) : null, 'provider_ref' => cm_nullable($raw['provider_ref'])];
            if ($new['included_in_service_id']) $new['billable'] = 0;   // included in hosting: never billed on its own
        }
        if ($PLN) {
            if ($raw['plan_id'] !== '' && (!ctype_digit($raw['plan_id']) || !isset($plans[(int)$raw['plan_id']]))) throw new InvalidArgumentException('Choose a plan from the list.');
            if ($raw['mailboxes'] !== '' && (!ctype_digit($raw['mailboxes']) || (int)$raw['mailboxes'] > 500)) throw new InvalidArgumentException('Mailboxes must be a whole number.');
            $new += ['plan_id' => $raw['plan_id'] === '' ? null : (int)$raw['plan_id'], 'mailboxes' => $raw['mailboxes'] === '' ? null : (int)$raw['mailboxes']];
            // a new billed service with a plan and no price starts at today's list price for its cycle
            if (!$id && $new['price_ex_gst'] === null && $new['plan_id'] && $new['cycle_months']) { $lp = cm_service_list_price($new, $plans); if ($lp !== null) $new['price_ex_gst'] = number_format($lp, 2, '.', ''); }
        }
        if ($KB) $new['keep_billing'] = !empty($_POST['keep_billing']) ? 1 : 0;
        if (!isset(CM_SERVICE_KINDS[$new['kind']])) throw new InvalidArgumentException('Choose a kind.');
        if (!isset(CM_SERVICE_STATUSES[$new['status']])) throw new InvalidArgumentException('Choose a status.');
        if (!array_key_exists((string)($new['cycle_months'] ?? ''), CM_CYCLES)) throw new InvalidArgumentException('Choose a billing cycle.');
        if ($new['price_ex_gst'] !== null && !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $new['price_ex_gst'])) throw new InvalidArgumentException('Price must be dollars ex GST (up to 2 decimals).');
        if (!isset(CM_CHECK_STATUSES[(string)$new['check_status']])) throw new InvalidArgumentException('Invalid check status.');
        if ($new['domain'] === null && $new['label'] === null) throw new InvalidArgumentException('Enter the domain (or a label if it has none).');
        if ($new['billable'] && !$new['is_internal'] && ($new['price_ex_gst'] === null || !$new['cycle_months'])) throw new InvalidArgumentException('A billed service needs a price and a billing cycle (or untick “Billed”).');
        if ($id) {
            $changes = cm_changes($old, $new);
            foreach (['price_ex_gst'] as $f) if (isset($changes[$f]) && $changes[$f]['previous'] !== null && $changes[$f]['current'] !== null && (float)$changes[$f]['previous'] === (float)$changes[$f]['current']) unset($changes[$f]);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE services SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $id]);
                cm_audit($pdo, 'service_updated', ['client_id' => $new['client_id']], 'Service ' . ($new['domain'] ?: $new['label']) . ' updated: ' . implode(', ', array_keys($changes)), ['row_id' => $id] + $changes);
            }
            $pdo->commit();
            header('Location: ' . route('service', ['id' => $id, 'saved' => $changes ? 1 : 0]), true, 303); exit;
        }
        $new['next_period_start'] = m7date($raw['next_period_start']);
        if ($new['billable'] && !$new['is_internal'] && !$new['next_period_start']) throw new InvalidArgumentException('Enter when the first period to invoice starts.');
        $cols = array_keys($new);
        $pdo->prepare('INSERT INTO services (' . implode(',', $cols) . ',source_system,created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ",'app',NOW())")->execute(array_values($new));
        $newId = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'service_created', ['client_id' => $new['client_id']], 'Service added: ' . ($new['domain'] ?: $new['label']), ['row_id' => $newId] + array_filter($new, fn($x) => $x !== null));
        $pdo->commit();
        header('Location: ' . route('service', ['id' => $newId, 'saved' => 1]), true, 303); exit;
    } catch (Throwable $e) {
        $error = cm_edit_error($e, $pdo, 'service #' . $id);
        if (in_array($action, ['correct_date', 'include', 'provision'], true)) { $_SESSION['cm_service_error'] = ['id' => $id, 'msg' => $error]; header('Location: ' . route('service', ['id' => $id]), true, 303); exit; }
        foreach (array_merge($FIELDS, ['next_period_start']) as $f) if (is_string($_POST[$f] ?? null)) $s[$f] = trim($_POST[$f]);
        $s['billable'] = !empty($_POST['billable']) ? 1 : 0; $s['is_internal'] = !empty($_POST['is_internal']) ? 1 : 0; if ($KB) $s['keep_billing'] = !empty($_POST['keep_billing']) ? 1 : 0;
    }
}
$ver = $id ? cm_ver(oneRow($pdo, 'SELECT * FROM services WHERE id=?', [$id]), $FIELDS) : '';
$sel = function (string $name, array $choices, $cur) { echo '<select id="' . h($name) . '" name="' . h($name) . '">'; foreach ($choices as $k => $l) echo '<option value="' . h($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>'; echo '</select>'; };
$back = $id ? route('service', ['id' => $id]) : ($pre ? route('client', ['id' => $pre]) : route('services'));
cm_page_start($id ? 'Edit service' : 'New service', 'services', ['back' => [$back, $id ? 'Service' : ($pre ? 'Client' : 'Services')], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" class="form"><input type="hidden" name="id" value="<?=(int)$id?>"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="ver" value="<?=h($ver)?>"><input type="hidden" name="action" value="save">
<div class="field"><label for="client">Client</label><?php cm_client_picker(cm_client_options($pdo), (string)($s['client_id'] ?? ''), true); ?></div>
<div class="pair"><div class="field"><label for="kind">Kind</label><?php $sel('kind', CM_SERVICE_KINDS, $s['kind']); ?></div><div class="field"><label for="status">Status</label><?php $sel('status', CM_SERVICE_STATUSES, $s['status']); ?></div></div>
<div class="pair"><div class="field"><label for="domain">Domain</label><input id="domain" name="domain" maxlength="255" value="<?=h($s['domain'] ?? '')?>" placeholder="example.co.nz"></div><div class="field"><label for="label">Label <span class="opt">(if no domain)</span></label><input id="label" name="label" maxlength="255" value="<?=h($s['label'] ?? '')?>"></div></div>
<?php if ($PLN): ?><div class="pair"><div class="field"><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><option value="">— no plan —</option><?php foreach ($plans as $p): if (!$p['active'] && (string)$p['id'] !== (string)($s['plan_id'] ?? '')) continue; ?><option value="<?=(int)$p['id']?>"<?=(string)($s['plan_id'] ?? '') === (string)$p['id'] ? ' selected' : ''?>><?=h($p['code'] . ' · ' . $p['name'] . ($p['price_ex_gst'] !== null ? ' · ' . cm_money($p['price_ex_gst']) . ' ' . (CM_PLAN_PERIODS[(string)$p['price_months']] ?? '') : ''))?></option><?php endforeach; ?></select></div>
<div class="field"><label for="mailboxes">Mailboxes <span class="opt">(provisioned)</span></label><input id="mailboxes" name="mailboxes" inputmode="numeric" maxlength="4" value="<?=h((string)($s['mailboxes'] ?? ''))?>"></div></div>
<?php if (!$id): ?><p class="helper">Leave the price blank to use the plan's list price for the cycle (plus mailboxes).</p><?php endif; ?><?php endif; ?>
<div class="field"><label for="plan_name"><?=$PLN ? 'Old plan name' : 'Plan'?> <span class="opt">(for your records; not on invoices)</span></label><input id="plan_name" name="plan_name" maxlength="120" value="<?=h($s['plan_name'] ?? '')?>" placeholder="e.g. Standard - CMS with PHP 7"></div>
<fieldset class="field group"><legend>Billing</legend>
<div class="checks"><label class="check"><input type="checkbox" name="billable" value="1"<?=!empty($s['billable']) ? ' checked' : ''?>> Billed</label><label class="check"><input type="checkbox" name="is_internal" value="1"<?=!empty($s['is_internal']) ? ' checked' : ''?>> Internal (Cloake's own)</label><?php if ($KB): ?><label class="check"><input type="checkbox" name="keep_billing" value="1"<?=!empty($s['keep_billing']) ? ' checked' : ''?>> Keep billing while suspended or in development</label><?php endif; ?></div>
<?php if ($KB): ?><p class="helper">Only Active services are invoiced; Suspended and Pending (in development) are not, unless “Keep billing” is ticked.</p><?php endif; ?>
<div class="triple"><div><label for="price_ex_gst">Price ex GST</label><input id="price_ex_gst" name="price_ex_gst" inputmode="decimal" maxlength="14" value="<?=h($s['price_ex_gst'] ?? '')?>"></div><div><label for="cycle_months">Cycle</label><?php $sel('cycle_months', CM_CYCLES, $s['cycle_months'] ?? ''); ?></div><div><label for="xero_account">Xero account</label><input id="xero_account" name="xero_account" maxlength="20" value="<?=h($s['xero_account'] ?? '')?>" placeholder="<?=h(cm_account_for($pdo, (string)$s['kind']))?>"></div></div>
<?php if (!$id): ?><div class="field"><label for="next_period_start">First period to invoice starts</label><input type="date" id="next_period_start" name="next_period_start" value="<?=h($s['next_period_start'] ?? '')?>"></div><?php else: ?><p class="helper">The next period (<?=h(niceDate($s['next_period_start'] ?? null))?>) moves on when an invoice is recorded. To correct it, use “Correct the next period date” on the service page.</p><?php endif; ?>
</fieldset>
<details<?=($s['expiry_date'] ?? '') . ($s['registrar'] ?? '') . ($s['cpanel_username'] ?? '') . ($s['dns_host'] ?? '') . ($s['nameservers'] ?? '') . ($s['provider_ref'] ?? '') . ($s['included_in_service_id'] ?? '') !== '' || ($s['kind'] ?? '') === 'domain' ? ' open' : ''?>><summary>Domain &amp; server</summary>
<div class="pair"><div class="field"><label for="expiry_date">Domain expiry</label><input type="date" id="expiry_date" name="expiry_date" value="<?=h($s['expiry_date'] ?? '')?>"></div><div class="field"><label for="registrar">Registrar</label><input id="registrar" name="registrar" maxlength="60" value="<?=h($s['registrar'] ?? '')?>"></div></div>
<div class="field"><label for="cpanel_username">cPanel username <span class="opt">(never a password)</span></label><input id="cpanel_username" name="cpanel_username" maxlength="60" autocomplete="off" value="<?=h($s['cpanel_username'] ?? '')?>"></div>
<?php if ($DOM): $hosts = cm_hosting_choices($pdo, $s['client_id'] ? (int)$s['client_id'] : null, (int)$id); ?>
<div class="pair"><div class="field"><label for="dns_host">DNS host</label><input id="dns_host" name="dns_host" maxlength="60" list="dns-hosts" value="<?=h($s['dns_host'] ?? '')?>" placeholder="e.g. Cloudflare"><datalist id="dns-hosts"><?php foreach (array_column(allRows($pdo, "SELECT DISTINCT dns_host FROM services WHERE dns_host IS NOT NULL ORDER BY dns_host"), 'dns_host') as $dh): ?><option value="<?=h($dh)?>"><?php endforeach; ?></datalist></div>
<div class="field"><label for="auto_renew">Auto-renew</label><?php $sel('auto_renew', ['' => 'Not known', '1' => 'Yes', '0' => 'No'], $s['auto_renew'] ?? ''); ?></div></div>
<div class="field"><label for="nameservers">Name servers</label><input id="nameservers" name="nameservers" maxlength="255" value="<?=h($s['nameservers'] ?? '')?>" placeholder="ns1.example.net, ns2.example.net"></div>
<div class="field"><label for="provider_ref">Provider reference <span class="opt">(registrar / Vetta portal ID — never a transfer code)</span></label><input id="provider_ref" name="provider_ref" maxlength="100" autocomplete="off" value="<?=h($s['provider_ref'] ?? '')?>"></div>
<div class="field"><label for="included_in_service_id">Included in hosting <span class="opt">(domains only — then not billed on its own)</span></label><select id="included_in_service_id" name="included_in_service_id"><option value="">— billed on its own / not included —</option><?php foreach ($hosts as $hs): ?><option value="<?=(int)$hs['id']?>"<?=(string)($s['included_in_service_id'] ?? '') === (string)$hs['id'] ? ' selected' : ''?>><?=h($hs['name'] . ($hs['status'] !== 'active' ? ' (' . (CM_SERVICE_STATUSES[$hs['status']] ?? $hs['status']) . ')' : ''))?></option><?php endforeach; ?></select><?php if (!$hosts): ?><p class="helper">This client has no hosting service to include it in.</p><?php endif; ?></div>
<?php endif; ?></details>
<details<?=($s['check_status'] ?? '') . ($s['check_note'] ?? '') . ($s['notes'] ?? '') !== '' ? ' open' : ''?>><summary>Check &amp; notes</summary>
<div class="field"><label for="check_status">Check</label><?php $sel('check_status', CM_CHECK_STATUSES, $s['check_status'] ?? ''); ?></div>
<div class="field"><label for="check_note">Check note</label><input id="check_note" name="check_note" maxlength="500" value="<?=h($s['check_note'] ?? '')?>"></div>
<div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" placeholder="No passwords here — they belong in your password manager."><?=h($s['notes'] ?? '')?></textarea></div></details>
<div class="form-actions"><button type="submit" class="save"><?=$id ? 'Save service' : 'Add service'?></button><a class="cancel" href="<?=h($back)?>">Cancel</a></div></form>
<?php cm_page_end('services');
