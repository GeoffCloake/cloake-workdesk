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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    if (!empty($acct[1]['suspended']) && $s['billable'] && !$s['is_internal']) $susp[] = [$s, $acct[0]];
}
$orphans = array_diff_key(array_filter($inv['accounts'], fn($a) => empty($a['error'])), $used);
$name = fn($s) => h($s['domain'] ?: $s['label'] ?: '—');
?>
<div class="client-summary" data-testid="cpanel-summary"><div><small>Mailbox counts to update</small><strong class="<?=$diff ? 'late' : ''?>"><?=count($diff)?></strong></div><div><small>No account found</small><strong><?=count($none)?></strong></div><div><small>Accounts with no service</small><strong><?=count($orphans)?></strong></div></div>

<?php if ($diff): ?><form method="post" action="cpanel-check.php" class="form panel" data-testid="cpanel-diff"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><h2 class="panel-title">Mailboxes differ</h2>
<ul class="plan-list"><?php foreach ($diff as [$s, $u, $n]): ?><li data-service="<?=(int)$s['id']?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=(int)$s['id']?>" checked> <strong><a href="<?=h(route('service', ['id' => $s['id']]))?>"><?=$name($s)?></a></strong></label><small><?=h((string)$s['client_name'])?> · cPanel <?=h($u)?> · here <?=(int)($s['mailboxes'] ?? 0)?> → cPanel <?=$n?></small></span></li><?php endforeach; ?></ul>
<div class="form-actions"><button type="submit" class="save">Update mailbox counts</button></div><p class="helper">Counts leave out management mailboxes (Billing settings: “<?=h(cm_setting($pdo, 'management_mailboxes'))?>”<?=cm_setting($pdo, 'management_subdomains') !== 'no' ? ', and any on a subdomain' : ''?>). Only the mailbox count is changed (it shows on the Xero line and in the list price). Prices are not changed. Undo is offered straight after.</p></form><?php endif; ?>

<?php if ($susp): ?><details class="panel" open data-testid="cpanel-suspended"><summary>Billed, but suspended on the server (<?=count($susp)?>)</summary><ul class="plan-list"><?php foreach ($susp as [$s, $u]): ?><li><span class="pl-main"><a href="<?=h(route('service', ['id' => $s['id']]))?>"><?=$name($s)?></a><small><?=h((string)$s['client_name'])?> · cPanel <?=h($u)?></small></span></li><?php endforeach; ?></ul></details><?php endif; ?>

<?php if ($none): ?><details class="panel" data-testid="cpanel-none-list"><summary>No cPanel account found (<?=count($none)?>)</summary><ul class="plan-list"><?php foreach ($none as $s): ?><li><span class="pl-main"><a href="service-edit.php?id=<?=(int)$s['id']?>"><?=$name($s)?></a><small><?=h((string)$s['client_name'])?><?=$s['cpanel_username'] ? ' · username ' . h($s['cpanel_username']) . ' not on this server' : ' · no cPanel username'?></small></span></li><?php endforeach; ?></ul><p class="helper">Hosted elsewhere, or the username/domain differs — put the cPanel username on the service.</p></details><?php endif; ?>

<?php if ($orphans): ?><details class="panel" data-testid="cpanel-orphans"><summary>cPanel accounts with no live service (<?=count($orphans)?>)</summary><ul class="plan-list"><?php foreach ($orphans as $u => $a): ?><li><span class="pl-main"><strong><?=h((string)$u)?></strong> <?=h((string)($a['domains'][0] ?? ''))?><small><?=h(implode(' · ', array_filter([!empty($a['package']) ? 'package ' . $a['package'] : '', count((array)($a['mailboxes'] ?? [])) . ' mailboxes (incl. management)', !empty($a['suspended']) ? 'suspended' : ''])))?></small></span></li><?php endforeach; ?></ul><p class="helper">Possibly not billed — or internal. Add a service for it, or ignore it if it's yours.</p></details><?php endif; ?>
<?php if (!$diff && !$susp && !$none && !$orphans): ?><div class="success" role="status">Everything matches.</div><?php endif; ?>
<?php cm_page_end('billing');
