<?php
declare(strict_types=1);
/*
 * Xero sync (v0.18): keep active clients and Xero contacts in step, and check the account codes and tax types in use.
 * Reads Xero live on every visit (nothing cached). Changes happen only on a click:
 *   POST action = link (pairs[] "client:contact")     store the Xero ContactID on the client (audited, undoable)
 *              | unlink (client_id)                     clear it (audited, undoable)
 *              | create (client_ids[])                  create the contact in Xero from the client, then link
 *              | push (client_id)                       update the Xero contact's name/email/phone/address from Workdesk
 *              | add_client (contact_id)                create a Workdesk client from a Xero contact
 * Workdesk never deletes or archives anything in Xero, and never overwrites Workdesk data from Xero.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$x = cm_xero_load();
$error = ''; $done = [];
$need = ['accounting.contacts', 'accounting.settings.read'];
$missingScope = array_values(array_filter($need, fn($s) => !cm_xero_has_scope($x, $s)));
$clientRow = fn(int $id) => oneRow($pdo, "SELECT * FROM clients WHERE id=?", [$id]);
$link = function (int $cid, string $xid, string $why) use ($pdo, $clientRow) {
    $c = $clientRow($cid); if (!$c) throw new InvalidArgumentException('Client not found.');
    $changes = cm_changes($c, ['xero_contact_id' => $xid === '' ? null : $xid]);
    if (!$changes) return false;
    $pdo->prepare('UPDATE clients SET xero_contact_id=?, updated_at=NOW() WHERE id=?')->execute([$xid === '' ? null : $xid, $cid]);
    cm_audit($pdo, 'client_updated', ['client_id' => $cid], $why . ': ' . $c['name'], $changes);
    return true;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (cm_xero_state($x) !== 'connected') throw new InvalidArgumentException('Xero is not connected.');
        $pdo->beginTransaction();
        if ($action === 'link') {
            $pairs = array_filter(array_map('strval', is_array($_POST['pairs'] ?? null) ? $_POST['pairs'] : []));
            if (!$pairs) throw new InvalidArgumentException('Tick the matches to link.');
            $known = array_column(cm_xero_contacts_all($x), null, 'id');
            foreach ($pairs as $p) {
                if (!preg_match('/^(\d+):([0-9a-f-]{36})$/', $p, $m) || !isset($known[$m[2]])) throw new InvalidArgumentException('A ticked Xero contact no longer exists. Reload the page.');
                if (oneRow($pdo, 'SELECT id FROM clients WHERE xero_contact_id=? AND id<>?', [$m[2], (int)$m[1]])) throw new InvalidArgumentException('“' . $known[$m[2]]['name'] . '” is already linked to another client.');
                if ($link((int)$m[1], $m[2], 'Linked to Xero contact “' . $known[$m[2]]['name'] . '”')) $done[] = 'linked';
            }
        } elseif ($action === 'unlink') {
            if ($link((int)($_POST['client_id'] ?? 0), '', 'Unlinked from Xero')) $done[] = 'unlinked';
        } elseif ($action === 'create' || $action === 'push') {
            if ($missingScope) throw new InvalidArgumentException('Reconnect Xero first (More › Xero connection) so Workdesk may create and update contacts.');
            $ids = $action === 'push' ? [(int)($_POST['client_id'] ?? 0)] : array_map('intval', is_array($_POST['client_ids'] ?? null) ? $_POST['client_ids'] : []);
            if (!array_filter($ids)) throw new InvalidArgumentException('Tick the clients to create in Xero.');
            foreach ($ids as $cid) {
                $c = $clientRow($cid); if (!$c) throw new InvalidArgumentException('Client not found.');
                if ($action === 'create') {
                    if (cm_client_xero_id($c) !== '') continue;
                    $xid = cm_xero_save_contact($x, $c);
                    $link($cid, $xid, 'Created in Xero and linked'); $done[] = 'created';
                } else {
                    $xid = cm_client_xero_id($c); if ($xid === '') throw new InvalidArgumentException('That client is not linked to a Xero contact.');
                    cm_xero_save_contact($x, $c, $xid);
                    cm_audit($pdo, 'xero_contact_pushed', ['client_id' => $cid], 'Xero contact updated from Workdesk: ' . $c['name'], ['contact_id' => $xid]);
                    $done[] = 'updated in Xero';
                }
            }
        } elseif ($action === 'add_client') {
            $xid = strtolower((string)($_POST['contact_id'] ?? ''));
            $xc = array_values(array_filter(cm_xero_contacts_all($x), fn($c) => $c['id'] === $xid))[0] ?? null;
            if (!$xc) throw new InvalidArgumentException('That Xero contact no longer exists.');
            if (oneRow($pdo, 'SELECT id FROM clients WHERE xero_contact_id=?', [$xid])) throw new InvalidArgumentException('That Xero contact is already linked to a client.');
            $email = filter_var($xc['email'], FILTER_VALIDATE_EMAIL) ? $xc['email'] : null;
            $pdo->prepare("INSERT INTO clients (source_system,source_id,name,status,email,xero_contact_id,created_at,updated_at) VALUES ('app',?,?,'active',?,?,NOW(),NOW())")->execute([cm_new_source_id(), $xc['name'], $email, $xid]);
            $nid = (int)$pdo->lastInsertId();
            cm_audit($pdo, 'client_created', ['client_id' => $nid], 'Client added from Xero contact “' . $xc['name'] . '”', ['name' => $xc['name'], 'email' => $email, 'xero_contact_id' => $xid]);
            $done[] = 'client added';
        } else throw new InvalidArgumentException('Unknown action.');
        $pdo->commit();
        $cnt = array_count_values($done);
        $_SESSION['cm_xero_flash'] = $cnt ? ucfirst(implode(', ', array_map(fn($k) => $cnt[$k] . ' ' . $k, array_keys($cnt)))) . '.' : 'Nothing to change.';
        header('Location: xero-sync.php', true, 303); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Could not save. Nothing changed.';
        if (!($e instanceof InvalidArgumentException)) error_log('Cloake xero sync: ' . $e->getMessage());
        if (!empty($done)) $error .= ' (Contacts already created in Xero before this stopped are linked only if listed below — reload to check.)';
    }
}
$flash = (string)($_SESSION['cm_xero_flash'] ?? ''); unset($_SESSION['cm_xero_flash']);
cm_page_start('Xero sync', 'billing', ['back' => [route('more'), 'More']]);
$hid = fn(string $a) => '<input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($a) . '">';
?>
<p class="lead">Keep your active clients and Xero contacts in step, and check that the account codes and tax types Workdesk uses exist in Xero. Nothing changes unless you click; nothing is ever deleted in Xero.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status"><?=h($flash)?></div><?php endif; ?>
<?php if (cm_xero_state($x) !== 'connected'): ?><div class="notice">Xero is not connected. <a href="xero.php">Set up the Xero connection</a> first.</div><?php cm_page_end('billing'); exit; endif; ?>
<p class="small muted">Organisation: <strong><?=h((string)$x['tenant_name'])?></strong><?=cm_xero_is_demo($x) ? ' <em class="tag">Demo Company — test data</em>' : ''?></p>
<?php if ($missingScope): ?><div class="notice" data-testid="need-reconnect"><strong>Reconnect Xero once</strong> to let Workdesk create and update contacts and read account codes and tax rates (new permissions: <?=h(implode(', ', $missingScope))?>). <a class="button-link" href="xero.php?connect=<?=h(rawurlencode(m7csrf()))?>">Reconnect</a></div><?php endif; ?>
<?php
try { $xc = cm_xero_contacts_all($x); } catch (Throwable $e) { echo '<div class="alert" role="alert">' . h($e->getMessage()) . '</div>'; cm_page_end('billing'); exit; }
$byId = array_column($xc, null, 'id');
$clients = allRows($pdo, "SELECT * FROM clients WHERE LOWER(COALESCE(status,''))<>'archive' ORDER BY name");
$linkedIds = [];
foreach (allRows($pdo, "SELECT id, xero_contact_id FROM clients WHERE xero_contact_id IS NOT NULL") as $r) { $g = cm_client_xero_id($r); if ($g !== '') $linkedIds[$g] = (int)$r['id']; }
$byNorm = []; $byEmail = []; $byNumber = [];
foreach ($xc as $c) { if (isset($linkedIds[$c['id']])) continue; $byNorm[cm_norm_name($c['name'])][] = $c; if ($c['email'] !== '') $byEmail[mb_strtolower($c['email'])][] = $c; if ($c['number'] !== '') $byNumber[$c['number']][] = $c; }
$linked = []; $lost = []; $probable = []; $missing = [];
$suggested = [];
foreach ($clients as $c) {
    $g = cm_client_xero_id($c);
    if ($g !== '') { if (isset($byId[$g])) $linked[] = [$c, $byId[$g]]; else $lost[] = $c; continue; }
    $cand = [];
    foreach ($byNorm[cm_norm_name((string)$c['name'])] ?? [] as $k) $cand[$k['id']] = [$k, 'same name'];
    if (($e = mb_strtolower(trim((string)$c['email']))) !== '') foreach ($byEmail[$e] ?? [] as $k) $cand[$k['id']] ??= [$k, 'same email'];
    $ref = trim((string)$c['xero_contact_id']); if ($ref !== '' && strtolower($ref) !== 'null') foreach ($byNumber[$ref] ?? [] as $k) $cand[$k['id']] ??= [$k, 'same contact number'];
    if ($cand) { $probable[] = [$c, array_values($cand)]; foreach ($cand as $id => $_) $suggested[$id] = true; } else $missing[] = $c;
}
$xeroOnly = array_values(array_filter($xc, fn($k) => !isset($linkedIds[$k['id']]) && !isset($suggested[$k['id']])));
usort($xeroOnly, fn($a, $b) => [$b['customer'], $a['name']] <=> [$a['customer'], $b['name']]);
$diff = array_values(array_filter($linked, fn($p) => cm_norm_name((string)$p[0]['name']) !== cm_norm_name($p[1]['name']) || (trim((string)$p[0]['email']) !== '' && mb_strtolower(trim((string)$p[0]['email'])) !== mb_strtolower($p[1]['email']))));
?>
<div class="client-summary" data-testid="sync-summary">
  <div><small>Linked</small><strong><?=count($linked)?></strong></div>
  <div><small>Probable matches</small><strong><?=count($probable)?></strong></div>
  <div><small>Not in Xero</small><strong><?=count($missing)?></strong></div>
</div>
<p class="small muted"><?=count($clients)?> active clients · <?=count($xc)?> Xero contacts · <?=count($xeroOnly)?> Xero contacts with no client<?=$lost ? ' · <span class="late">' . count($lost) . ' linked contact(s) not found in Xero</span>' : ''?></p>

<?php if ($probable): ?><section class="panel" data-testid="sync-probable"><h2 class="panel-title">Probable matches <small class="muted">tick and link</small></h2>
<form method="post" action="xero-sync.php" class="form"><?=$hid('link')?><ul class="plan-list"><?php foreach ($probable as [$c, $cands]): foreach ($cands as $i => [$k, $why]): ?>
<li><label class="check"><input type="checkbox" name="pairs[]" value="<?=(int)$c['id'] . ':' . h($k['id'])?>"<?=count($cands) === 1 ? ' checked' : ''?>> <span><strong><?=h($c['name'])?></strong> ↔ Xero “<?=h($k['name'])?>” <small class="muted">(<?=h($why)?><?=$k['email'] ? ' · ' . h($k['email']) : ''?>)</small></span></label></li>
<?php endforeach; endforeach; ?></ul><div class="form-actions"><button type="submit" class="secondary">Link ticked</button></div></form></section><?php endif; ?>

<?php if ($missing): ?><details class="panel" data-testid="sync-missing"<?=count($missing) <= 20 ? ' open' : ''?>><summary>Active clients not in Xero (<?=count($missing)?>)</summary>
<form method="post" action="xero-sync.php" class="form"><?=$hid('create')?><ul class="plan-list"><?php foreach ($missing as $c): ?><li><label class="check"><input type="checkbox" name="client_ids[]" value="<?=(int)$c['id']?>"> <span><a href="<?=h(route('client', ['id' => $c['id']]))?>"><?=h($c['name'])?></a> <small class="muted"><?=h(implode(' · ', array_filter([(string)$c['email'], cm_client_overseas($c) !== '' ? 'overseas: ' . cm_client_overseas($c) : ''])))?></small></span></label></li><?php endforeach; ?></ul>
<p class="helper">Creates each ticked client as a contact in Xero (name, email, phone, postal address) and links it. Check the name first — Xero refuses a name it already has.</p>
<div class="form-actions"><button type="submit" class="secondary"<?=$missingScope ? ' disabled' : ''?>>Create ticked in Xero</button></div></form></details><?php endif; ?>

<?php if ($diff): ?><section class="panel" data-testid="sync-diff"><h2 class="panel-title">Linked, but different <small class="muted">Workdesk ↔ Xero</small></h2><ul class="plan-list"><?php foreach ($diff as [$c, $k]): ?>
<li><span class="pl-main"><strong><?=h($c['name'])?></strong><small>Xero: <?=h($k['name'])?><?=$k['email'] !== '' || $c['email'] ? ' · email here ' . h((string)$c['email'] ?: '—') . ' / Xero ' . h($k['email'] ?: '—') : ''?></small></span>
<span class="pl-end"><form method="post" action="xero-sync.php" class="row-inline"><?=$hid('push')?><input type="hidden" name="client_id" value="<?=(int)$c['id']?>"><button type="submit" class="mini"<?=$missingScope ? ' disabled' : ''?>>Update Xero from Workdesk</button></form></span></li><?php endforeach; ?></ul></section><?php endif; ?>

<?php if ($lost): ?><section class="panel" data-testid="sync-lost"><h2 class="panel-title">Linked contact not found in Xero <small class="muted">archived or deleted there</small></h2><ul class="plan-list"><?php foreach ($lost as $c): ?>
<li><span class="pl-main"><strong><?=h($c['name'])?></strong></span><span class="pl-end"><form method="post" action="xero-sync.php" class="row-inline"><?=$hid('unlink')?><input type="hidden" name="client_id" value="<?=(int)$c['id']?>"><button type="submit" class="mini">Unlink</button></form></span></li><?php endforeach; ?></ul></section><?php endif; ?>

<?php if ($xeroOnly): ?><details class="panel" data-testid="sync-xero-only"><summary>Xero contacts with no client (<?=count($xeroOnly)?>)</summary><ul class="plan-list"><?php foreach ($xeroOnly as $k): ?>
<li><span class="pl-main"><strong><?=h($k['name'])?></strong><small><?=h(implode(' · ', array_filter([$k['email'], $k['customer'] ? 'customer' : 'not a customer'])))?></small></span>
<span class="pl-end"><form method="post" action="xero-sync.php" class="row-inline"><?=$hid('add_client')?><input type="hidden" name="contact_id" value="<?=h($k['id'])?>"><button type="submit" class="mini">Add to Workdesk</button></form></span></li><?php endforeach; ?></ul>
<p class="helper">To link one of these to an existing client instead, put its name on the client (or its email) so it shows under Probable matches.</p></details><?php endif; ?>

<details class="panel" data-testid="sync-accounts"<?=$missingScope ? '' : ' open'?>><summary>Account codes and tax types</summary>
<?php if (in_array('accounting.settings.read', $missingScope, true)): ?><p class="muted small">Reconnect Xero to check these.</p>
<?php else: try { $acc = cm_xero_accounts($x); $tax = cm_xero_taxrates($x); } catch (Throwable $e) { $acc = $tax = null; echo '<p class="alert">' . h($e->getMessage()) . '</p>'; }
if ($acc !== null):
    $codes = [];
    foreach (['xero_account_hosting' => 'Billing settings: hosting, email, DNS', 'xero_account_domain' => 'Billing settings: domain renewals', 'xero_account_default' => 'Billing settings: task work'] as $k => $l) if (($v = cm_setting($pdo, $k)) !== '') $codes[$v][] = $l;
    foreach (allRows($pdo, "SELECT xero_account a, COUNT(*) n FROM services WHERE xero_account IS NOT NULL AND xero_account<>'' AND status IN ('active','pending','suspended') GROUP BY xero_account") as $r) $codes[$r['a']][] = $r['n'] . ' service' . ($r['n'] == 1 ? '' : 's');
    $types = [cm_setting($pdo, 'xero_tax_type') ?: 'OUTPUT2' => 'default (NZ clients)', cm_setting($pdo, 'xero_tax_type_overseas') ?: 'ZERORATEDOUTPUT' => 'overseas clients']; ?>
<ul class="plan-list"><?php foreach ($codes as $code => $uses): $a = $acc[$code] ?? null; $ok = $a && $a['status'] === 'ACTIVE'; ?><li class="<?=$ok ? '' : 'is-late'?>" data-code="<?=h((string)$code)?>" data-ok="<?=$ok ? '1' : '0'?>"><span class="pl-main"><strong>Account <?=h((string)$code)?></strong> <?=$ok ? '✓ ' . h($a['name']) : '<span class="late">✗ ' . ($a ? 'not active in Xero' : 'not in Xero') . '</span>'?><small><?=h(implode(' · ', $uses))?></small></span></li><?php endforeach; ?>
<?php foreach ($types as $t => $use): $r = $tax[$t] ?? null; $ok = $r && $r['status'] === 'ACTIVE'; ?><li class="<?=$ok ? '' : 'is-late'?>" data-tax="<?=h($t)?>" data-ok="<?=$ok ? '1' : '0'?>"><span class="pl-main"><strong>Tax type <?=h($t)?></strong> <?=$ok ? '✓ ' . h($r['name']) . ' (' . h((string)$r['rate']) . '%)' : '<span class="late">✗ not in Xero</span>'?><small><?=h($use)?></small></span></li><?php endforeach; ?></ul>
<p class="helper">Change codes in <a href="billing-settings.php">Billing settings</a> or on the service.</p>
<?php endif; endif; ?></details>
<?php cm_page_end('billing');
