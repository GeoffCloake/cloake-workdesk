<?php
declare(strict_types=1);
/*
 * cPanel check (v0.20): compare live hosting/email services with what the server has, from the nightly file.
 *   - mailboxes recorded here vs mailboxes on the account → tick, "Update mailbox counts" (one undoable change)
 *   - services with no cPanel account found; billed services whose account is suspended
 *   - cPanel accounts that no live service points to (possibly not billed)
 * Read-only towards the server. Nothing is billed or priced from here.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$error = '';
$inv = cm_cpanel_inventory();
$svc = allRows($pdo, "SELECT s.*, c.name client_name FROM services s LEFT JOIN clients c ON c.id=s.client_id
    WHERE s.kind IN ('hosting','email') AND s.status IN ('active','pending','suspended') ORDER BY c.name, s.domain");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dev_add') {
    // v0.21: record a site on a development account as a service "Pending — in development" for its client (not billed)
    m7verify();
    try {
        $dom = strtolower(trim((string)($_POST['domain'] ?? ''))); $user = strtolower(trim((string)($_POST['account'] ?? '')));
        $devs = array_filter(array_map('trim', explode(',', cm_setting($pdo, 'dev_accounts'))));
        if (!$inv || !in_array($user, $devs, true) || !in_array($dom, (array)($inv['accounts'][$user]['domains'] ?? []), true)) throw new InvalidArgumentException('That site is not on a development account. Reload the page.');
        $cid = cm_valid_client($pdo, (string)($_POST['client_id'] ?? ''), false);
        if (!$cid) throw new InvalidArgumentException('Choose the client for ' . $dom . '.');
        if (oneRow($pdo, "SELECT id FROM services WHERE domain=? AND status IN ('active','pending','suspended')", [$dom])) throw new InvalidArgumentException($dom . ' is already recorded.');
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO services (client_id,kind,domain,status,billable,is_internal,cpanel_username,notes,source_system,created_at) VALUES (?,'hosting',?,'pending',0,0,?,'Development site (from cPanel check)','app',NOW())")->execute([$cid, $dom, $user]);
        $nid = (int)$pdo->lastInsertId();
        cm_audit($pdo, 'service_created', ['client_id' => $cid], 'Development site recorded: ' . $dom, ['row_id' => $nid, 'domain' => $dom, 'status' => 'pending', 'cpanel_username' => $user]);
        $pdo->commit();
        header('Location: cpanel-check.php#dev', true, 303); exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e instanceof InvalidArgumentException ? $e->getMessage() : cm_edit_error($e, $pdo, 'cpanel dev site'); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    try {
        if (!$inv) throw new InvalidArgumentException('There is no cPanel file yet.');
        if (!cm_has_plans($pdo)) throw new InvalidArgumentException('Mailbox counts need database migration 009.');
        $pdo->beginTransaction();
        $rows = [];
        foreach (array_map('intval', is_array($_POST['sids'] ?? null) ? $_POST['sids'] : []) as $sid) {
            $s = oneRow($pdo, 'SELECT * FROM services WHERE id=? FOR UPDATE', [$sid]);
            if (!$s) throw new InvalidArgumentException('A ticked service no longer exists. Reload the page.');
            $acct = cm_service_account($s, $inv); if (!$acct) continue;
            $n = count(cm_service_mailboxes($pdo, $s, $acct[1])['client']);
            $ch = cm_changes($s, ['mailboxes' => $n]);
            if (!$ch) continue;
            $pdo->prepare('UPDATE services SET mailboxes=?, updated_at=NOW() WHERE id=?')->execute([$n, $sid]);
            $rows[] = ['table' => 'services', 'id' => $sid, 'changes' => $ch];
        }
        if (!$rows) throw new InvalidArgumentException('Tick the services to update.');
        cm_audit($pdo, 'services_mailboxes_updated', [], 'Mailbox counts taken from cPanel for ' . count($rows) . ' service' . (count($rows) === 1 ? '' : 's'), ['_rows' => $rows]);
        $pdo->commit();
        header('Location: cpanel-check.php', true, 303); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : cm_edit_error($e, $pdo, 'cpanel check');
    }
}
cm_page_start('cPanel check', 'billing', ['back' => [route('more'), 'More']]);
?>
<p class="lead">What the server has for each hosting and email service — mailboxes, forwards, package, disk — from the nightly read-only check. Workdesk never changes anything on the server.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (!$inv): ?><div class="notice" data-testid="cpanel-missing"><strong>No cPanel file yet.</strong> Install the nightly check once as root (see INSTALL.md, "Email inventory"); it writes <code>private/email-inventory.json</code>, which this page reads.</div><?php cm_page_end('billing'); exit; endif; ?>
<p class="small muted" data-testid="cpanel-age">From the server at <strong><?=h($inv['at'])?></strong> · <?=count($inv['accounts'])?> cPanel accounts<?=$inv['age_hours'] > 36 ? ' · <span class="late">more than a day old — check the nightly job</span>' : ''?></p>
<?php
$diff = []; $none = []; $susp = []; $used = [];
foreach ($svc as $s) {
    $acct = cm_service_account($s, $inv);
    if (!$acct) { if (!$s['is_internal']) $none[] = $s; continue; }
    $used[$acct[0]] = true;
    $n = count(cm_service_mailboxes($pdo, $s, $acct[1])['client']);
    if (cm_has_plans($pdo) && $n !== (int)($s['mailboxes'] ?? 0)) $diff[] = [$s, $acct[0], $n];
    if (!empty($acct[1]['suspended']) && $s['status'] === 'active' && !$s['is_internal']) $susp[] = [$s, $acct[0]];
}
$orphans = array_diff_key(array_filter($inv['accounts'], fn($a) => empty($a['error'])), $used, array_flip(array_filter(array_map('trim', explode(',', cm_setting($pdo, 'dev_accounts'))))));
$name = fn($s) => h($s['domain'] ?: $s['label'] ?: '—');
?>
<div class="client-summary" data-testid="cpanel-summary"><div><small>Mailbox counts to update</small><strong class="<?=$diff ? 'late' : ''?>"><?=count($diff)?></strong></div><div><small>No account found</small><strong><?=count($none)?></strong></div><div><small>Accounts with no service</small><strong><?=count($orphans)?></strong></div></div>

<?php if ($diff): ?><form method="post" action="cpanel-check.php" class="form panel" data-testid="cpanel-diff"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><h2 class="panel-title">Mailboxes differ</h2>
<ul class="plan-list"><?php foreach ($diff as [$s, $u, $n]): ?><li data-service="<?=(int)$s['id']?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=(int)$s['id']?>" checked> <strong><a href="<?=h(route('service', ['id' => $s['id']]))?>"><?=$name($s)?></a></strong></label><small><?=h((string)$s['client_name'])?> · cPanel <?=h($u)?> · here <?=(int)($s['mailboxes'] ?? 0)?> → cPanel <?=$n?></small></span></li><?php endforeach; ?></ul>
<div class="form-actions"><button type="submit" class="save">Update mailbox counts</button></div><p class="helper">Counts leave out management mailboxes (Billing settings: “<?=h(cm_setting($pdo, 'management_mailboxes'))?>”<?=cm_setting($pdo, 'management_subdomains') !== 'no' ? ', and any on a subdomain' : ''?>). Only the mailbox count is changed (it shows on the Xero line and in the list price). Prices are not changed. Undo is offered straight after.</p></form><?php endif; ?>

<?php $st = fn(string $to, string $label, string $help) => '<input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="to" value="' . $to . '"><input type="hidden" name="back" value="cpanel-check.php"><div class="form-actions"><button type="submit" class="secondary">' . h($label) . '</button></div><p class="helper">' . h($help) . '</p>'; ?>
<?php if ($susp): ?><form method="post" action="services-status.php" class="panel" data-testid="cpanel-suspended"><h2 class="panel-title">Suspended on the server, Active here (<?=count($susp)?>)</h2><ul class="plan-list"><?php foreach ($susp as [$s, $u]): ?><li data-service="<?=(int)$s['id']?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=(int)$s['id']?>" checked> <a href="<?=h(route('service', ['id' => $s['id']]))?>"><?=$name($s)?></a></label><small><?=h((string)$s['client_name'])?> · cPanel <?=h($u)?></small></span></li><?php endforeach; ?></ul>
<?=$st('suspended', 'Set ticked to Suspended', 'Suspended services are not invoiced (unless “Keep billing” is ticked on the service). One Undo puts them back.')?></form><?php endif; ?>

<?php if ($none): ?><details class="panel" data-testid="cpanel-none-list"<?=count($none) <= 30 ? ' open' : ''?>><summary>No cPanel account found (<?=count($none)?>)</summary><form method="post" action="services-status.php"><ul class="plan-list"><?php foreach ($none as $s): ?><li data-service="<?=(int)$s['id']?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=(int)$s['id']?>"> <?=$name($s)?></label><small><?=h((string)$s['client_name'])?><?=$s['cpanel_username'] ? ' · username ' . h($s['cpanel_username']) . ' not on this server' : ' · no cPanel username'?> · <a href="service-edit.php?id=<?=(int)$s['id']?>">edit</a></small></span></li><?php endforeach; ?></ul>
<?=$st('terminated', 'Mark ticked as terminated', 'For sites that have gone. Hosted elsewhere or a different username? Leave it unticked and fix the username on the service instead. One Undo puts them back.')?></form></details><?php endif; ?>

<?php // v0.21: sites on development accounts — record each against its client as "Pending — in development" (not billed)
$devs = array_filter(array_map('trim', explode(',', cm_setting($pdo, 'dev_accounts'))));
$devRows = [];
foreach ($devs as $du) foreach (array_slice((array)($inv['accounts'][$du]['domains'] ?? []), 1) as $dd) $devRows[] = [$du, $dd, oneRow($pdo, "SELECT s.id, c.name FROM services s LEFT JOIN clients c ON c.id=s.client_id WHERE s.domain=? AND s.status IN ('active','pending','suspended')", [$dd])];
if ($devRows): $clients = allRows($pdo, "SELECT id, name FROM clients WHERE LOWER(COALESCE(status,''))<>'archive' ORDER BY name"); ?>
<details class="panel" id="dev" data-testid="cpanel-dev" open><summary>Sites on development accounts (<?=count($devRows)?>)</summary><ul class="plan-list"><?php foreach ($devRows as [$du, $dd, $rec]): ?>
<li data-site="<?=h($dd)?>"><span class="pl-main"><strong><?=h($dd)?></strong><small>cPanel <?=h($du)?><?=$rec ? ' · recorded for ' . h((string)$rec['name']) . ' — <a href="' . h(route('service', ['id' => $rec['id']])) . '">open</a>' : ''?></small></span>
<?php if (!$rec): ?><form method="post" action="cpanel-check.php" class="pl-end row-inline"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="dev_add"><input type="hidden" name="account" value="<?=h($du)?>"><input type="hidden" name="domain" value="<?=h($dd)?>"><label class="visually-hidden" for="dc-<?=h(md5($dd))?>">Client</label><select id="dc-<?=h(md5($dd))?>" name="client_id"><option value="">— client —</option><?php foreach ($clients as $c): ?><option value="<?=(int)$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select><button type="submit" class="mini">Record</button></form><?php endif; ?></li>
<?php endforeach; ?></ul><p class="helper">Recorded as “Pending — in development” with no charge, so it is never invoiced; when the site goes live, set the service to Active and give it a plan and price.</p></details>
<?php endif; ?>

<?php if ($orphans): ?><details class="panel" data-testid="cpanel-orphans"><summary>cPanel accounts with no live service (<?=count($orphans)?>)</summary><ul class="plan-list"><?php foreach ($orphans as $u => $a): ?><li><span class="pl-main"><strong><?=h((string)$u)?></strong> <?=h((string)($a['domains'][0] ?? ''))?><small><?=h(implode(' · ', array_filter([!empty($a['package']) ? 'package ' . $a['package'] : '', count((array)($a['mailboxes'] ?? [])) . ' mailboxes (incl. management)', !empty($a['suspended']) ? 'suspended' : ''])))?></small></span></li><?php endforeach; ?></ul><p class="helper">Possibly not billed — or internal. Add a service for it, or ignore it if it's yours.</p></details><?php endif; ?>
<?php if (!$diff && !$susp && !$none && !$orphans && empty($devRows)): ?><div class="success" role="status">Everything matches.</div><?php endif; ?>
<?php cm_page_end('billing');
