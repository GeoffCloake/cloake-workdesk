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
    // v0.18: row buttons in the one big list — <button name="push|unlink" value="client id">
    foreach (['push', 'unlink'] as $rb) if (isset($_POST[$rb]) && ctype_digit((string)$_POST[$rb])) { $action = $rb; $_POST['client_id'] = $_POST[$rb]; }
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
        } elseif ($action === 'ignore' || $action === 'unignore') {
            // v0.18.1: a Xero contact that isn't a client (supplier, old one-off…) — remembered in private/xero.json, not in Xero
            $xid = strtolower((string)($_POST['contact_id'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/', $xid)) throw new InvalidArgumentException('Unknown Xero contact.');
            $ig = array_values(array_diff($x['ignored'] ?? [], [$xid])); if ($action === 'ignore') $ig[] = $xid;
            $x['ignored'] = $ig; cm_xero_save($x);
            cm_audit($pdo, 'xero_contact_' . $action . 'd', [], ($action === 'ignore' ? 'Xero contact marked “not a client”: ' : 'Xero contact shown again: ') . (string)($_POST['contact_name'] ?? ''), ['contact_id' => $xid]);
            $done[] = $action === 'ignore' ? 'marked not a client' : 'shown again';
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
        header('Location: xero-sync.php' . (isset($_GET['show']) && preg_match('/^[a-z]+$/', (string)$_GET['show']) ? '?show=' . $_GET['show'] : ''), true, 303); exit;
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
$ignored = array_flip($x['ignored'] ?? []);
$unmatched = array_values(array_filter($xc, fn($k) => !isset($linkedIds[$k['id']]) && !isset($suggested[$k['id']])));
usort($unmatched, fn($a, $b) => strcasecmp($a['name'], $b['name']));
// v0.18.1: only Xero *customers* can need action; suppliers and other contacts are listed for reference only
$xeroOnly = array_values(array_filter($unmatched, fn($k) => $k['customer'] && !isset($ignored[$k['id']])));
$xeroOther = array_values(array_filter($unmatched, fn($k) => !$k['customer'] && !isset($ignored[$k['id']])));
$xeroIgnored = array_values(array_filter($unmatched, fn($k) => isset($ignored[$k['id']])));
?>
<?php
// v0.18: one list — every active client with its Xero status and the action that fixes it
$rows = [];
foreach ($linked as [$c, $k]) { $d = cm_norm_name((string)$c['name']) !== cm_norm_name($k['name']) || (trim((string)$c['email']) !== '' && mb_strtolower(trim((string)$c['email'])) !== mb_strtolower($k['email'])); $rows[] = ['c' => $c, 'st' => $d ? 'diff' : 'ok', 'k' => $k]; }
foreach ($probable as [$c, $cands]) $rows[] = ['c' => $c, 'st' => 'match', 'cands' => $cands];
foreach ($missing as $c) $rows[] = ['c' => $c, 'st' => 'missing'];
foreach ($lost as $c) $rows[] = ['c' => $c, 'st' => 'lost'];
usort($rows, fn($a, $b) => strcasecmp((string)$a['c']['name'], (string)$b['c']['name']));
$ST = ['ok' => ['In sync', '✓', 'Nothing to do.'], 'diff' => ['Different', '≠', 'Name or email differs — update Xero from Workdesk (or correct the client here).'],
       'match' => ['Probable match', '?', 'Check the match, tick it and Link.'], 'missing' => ['Not in Xero', '✗', 'Tick and Create in Xero.'],
       'lost' => ['Contact gone from Xero', '⚠', 'Archived or deleted in Xero — Unlink, then match or create again.']];
$count = array_count_values(array_column($rows, 'st'));
$show = (string)($_GET['show'] ?? ''); if (!isset($ST[$show]) && $show !== 'todo') $show = '';
$shown = array_values(array_filter($rows, fn($r) => $show === '' || ($show === 'todo' ? $r['st'] !== 'ok' : $r['st'] === $show)));
$todo = count($rows) - ($count['ok'] ?? 0);
?>
<div class="client-summary" data-testid="sync-summary">
  <div><small>In sync</small><strong><?=(int)($count['ok'] ?? 0)?></strong></div>
  <div><small>Need action</small><strong class="<?=$todo ? 'late' : ''?>"><?=$todo?></strong></div>
  <div><small>Xero customers with no client</small><strong><?=count($xeroOnly)?></strong></div>
</div>
<div class="chips" data-testid="sync-chips"><a class="chip<?=$show === '' ? ' on' : ''?>" href="xero-sync.php">All (<?=count($rows)?>)</a><a class="chip<?=$show === 'todo' ? ' on' : ''?>" href="xero-sync.php?show=todo">Need action (<?=$todo?>)</a><?php foreach ($ST as $k => [$l, $ic]): if (empty($count[$k])) continue; ?><a class="chip<?=$show === $k ? ' on' : ''?>" href="xero-sync.php?show=<?=h($k)?>"><?=h($ic . ' ' . $l)?> (<?=(int)$count[$k]?>)</a><?php endforeach; ?></div>
<form method="post" action="xero-sync.php<?=$show !== '' ? '?show=' . h($show) : ''?>" class="form" data-testid="sync-list"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<ul class="plan-list sync-list"><?php foreach ($shown as $r): $c = $r['c']; [$lab, $ic, $act] = $ST[$r['st']]; $ov = cm_client_overseas($c); ?>
<li class="sync-<?=h($r['st'])?>" data-client="<?=(int)$c['id']?>" data-status="<?=h($r['st'])?>"><span class="pl-main"><strong><a href="<?=h(route('client', ['id' => $c['id']]))?>"><?=h($c['name'])?></a> <em class="tag sync-tag"><?=h($ic . ' ' . $lab)?></em></strong>
<small><?php if ($r['st'] === 'ok' || $r['st'] === 'diff'): ?>Xero: <?=h($r['k']['name'])?><?=$r['k']['email'] !== '' ? ' · ' . h($r['k']['email']) : ''?><?php if ($r['st'] === 'diff'): ?> — here: <?=h((string)$c['name'])?><?=trim((string)$c['email']) !== '' ? ' · ' . h((string)$c['email']) : ''?><?php endif; ?><?php else: ?><?=h($act)?><?php endif; ?><?=$ov !== '' ? ' · overseas (' . h($ov) . ') — zero rated' : ''?></small>
<?php if ($r['st'] === 'match'): foreach ($r['cands'] as [$k, $why]): ?><label class="check small"><input type="checkbox" name="pairs[]" value="<?=(int)$c['id'] . ':' . h($k['id'])?>"<?=count($r['cands']) === 1 ? ' checked' : ''?>> Xero “<?=h($k['name'])?>” <span class="muted">(<?=h($why)?><?=$k['email'] ? ' · ' . h($k['email']) : ''?>)</span></label><?php endforeach; endif; ?>
<?php if ($r['st'] === 'missing'): ?><label class="check small"><input type="checkbox" name="client_ids[]" value="<?=(int)$c['id']?>"> create in Xero</label><?php endif; ?></span>
<span class="pl-end"><?php if ($r['st'] === 'diff'): ?><button type="submit" name="push" value="<?=(int)$c['id']?>" class="mini"<?=$missingScope ? ' disabled' : ''?>>Update Xero</button><?php elseif ($r['st'] === 'lost'): ?><button type="submit" name="unlink" value="<?=(int)$c['id']?>" class="mini">Unlink</button><?php endif; ?></span></li>
<?php endforeach; ?></ul>
<?php if (!$shown): ?><p class="emptyline">Nothing here.</p><?php endif; ?>
<?php if (($count['match'] ?? 0) || ($count['missing'] ?? 0)): ?><div class="form-actions sync-actions"><?php if ($count['match'] ?? 0): ?><button type="submit" name="action" value="link" class="secondary">Link ticked matches</button><?php endif; ?><?php if ($count['missing'] ?? 0): ?><button type="submit" name="action" value="create" class="secondary"<?=$missingScope ? ' disabled' : ''?>>Create ticked in Xero</button><?php endif; ?></div>
<p class="helper">Create in Xero sends name, email, phone and postal address. Xero refuses a name it already has — then link instead.</p><?php endif; ?>
</form>

<?php $xrow = function (array $k, array $buttons) use ($hid) { ob_start(); ?><li data-contact="<?=h($k['id'])?>"><span class="pl-main"><strong><?=h($k['name'])?></strong><small><?=h(implode(' · ', array_filter([$k['email'], $k['customer'] ? 'customer in Xero' : 'not a customer in Xero (e.g. supplier)'])))?></small></span>
<span class="pl-end row-inline"><?php foreach ($buttons as $act => $label): ?><form method="post" action="xero-sync.php" class="row-inline"><?=$hid($act)?><input type="hidden" name="contact_id" value="<?=h($k['id'])?>"><input type="hidden" name="contact_name" value="<?=h($k['name'])?>"><button type="submit" class="mini" data-act="<?=h($act)?>"><?=h($label)?></button></form><?php endforeach; ?></span></li><?php return ob_get_clean(); }; ?>
<?php if ($xeroOnly): ?><details class="panel" data-testid="sync-xero-only" open><summary>Xero customers with no client (<?=count($xeroOnly)?>) <small class="muted">may need action</small></summary><ul class="plan-list"><?php foreach ($xeroOnly as $k) echo $xrow($k, ['add_client' => 'Add to Workdesk', 'ignore' => 'Not a client']); ?></ul>
<p class="helper">For each: <strong>Add to Workdesk</strong> if it's a client you're missing · <strong>Not a client</strong> to stop it showing here (Xero is not changed) · or give an existing client the same name or email so it becomes a probable match.</p></details><?php endif; ?>
<?php if ($xeroOther): ?><details class="panel" data-testid="sync-xero-other"><summary>Other Xero contacts (<?=count($xeroOther)?>) <small class="muted">suppliers etc. — no action needed</small></summary><ul class="plan-list"><?php foreach ($xeroOther as $k) echo $xrow($k, ['add_client' => 'Add to Workdesk']); ?></ul></details><?php endif; ?>
<?php if ($xeroIgnored): ?><details class="panel" data-testid="sync-xero-ignored"><summary>Marked “not a client” (<?=count($xeroIgnored)?>)</summary><ul class="plan-list"><?php foreach ($xeroIgnored as $k) echo $xrow($k, ['unignore' => 'Show again']); ?></ul></details><?php endif; ?>

<details class="panel" data-testid="sync-accounts"<?=$missingScope ? '' : ' open'?>><summary>Account codes and tax types</summary>
<?php if (in_array('accounting.settings.read', $missingScope, true)): ?><p class="muted small">Reconnect Xero to check these.</p>
<?php else: try { $acc = cm_xero_accounts($x); $tax = cm_xero_taxrates($x); } catch (Throwable $e) { $acc = $tax = null; echo '<p class="alert">' . h($e->getMessage()) . '</p>'; }
if ($acc !== null):
    $codes = [];
    foreach (['xero_account_hosting' => 'Billing settings: hosting, email, DNS', 'xero_account_domain' => 'Billing settings: domain renewals', 'xero_account_default' => 'Billing settings: task work'] as $k => $l) if (($v = cm_setting($pdo, $k)) !== '') $codes[$v][] = $l;
    foreach (allRows($pdo, "SELECT xero_account a, COUNT(*) n FROM services WHERE xero_account IS NOT NULL AND xero_account<>'' AND status IN ('active','pending','suspended') GROUP BY xero_account") as $r) $codes[$r['a']][] = $r['n'] . ' service' . ($r['n'] == 1 ? '' : 's');
    $types = [cm_setting($pdo, 'xero_tax_type') ?: 'OUTPUT2' => 'default (NZ clients)', cm_setting($pdo, 'xero_tax_type_overseas') ?: 'ZERORATEDOUTPUT' => 'overseas clients']; ?>
<ul class="plan-list"><?php foreach ($codes as $code => $uses): $a = $acc[$code] ?? null; $ok = $a && $a['status'] === 'ACTIVE'; ?><li class="<?=$ok ? '' : 'is-late'?>" data-code="<?=h((string)$code)?>" data-ok="<?=$ok ? '1' : '0'?>"><span class="pl-main"><strong>Account <?=h((string)$code)?></strong> <?=$ok ? '✓ ' . h($a['name']) : '<span class="late">✗ ' . ($a ? 'not active in Xero' : 'not in Xero') . '</span>'?><small><?=h(implode(' · ', $uses))?><?=$ok ? '' : ' — action: add or re-activate it in Xero (Accounting › Chart of accounts), or change the code in Billing settings / on the service'?></small></span></li><?php endforeach; ?>
<?php foreach ($types as $t => $use): $r = $tax[$t] ?? null; $ok = $r && $r['status'] === 'ACTIVE'; ?><li class="<?=$ok ? '' : 'is-late'?>" data-tax="<?=h($t)?>" data-ok="<?=$ok ? '1' : '0'?>"><span class="pl-main"><strong>Tax type <?=h($t)?></strong> <?=$ok ? '✓ ' . h($r['name']) . ' (' . h((string)$r['rate']) . '%)' : '<span class="late">✗ not in Xero</span>'?><small><?=h($use)?><?=$ok ? '' : ' — action: change it in Billing settings to one of Xero\'s tax types'?></small></span></li><?php endforeach; ?></ul>
<p class="helper">Change codes in <a href="billing-settings.php">Billing settings</a> or on the service.</p>
<?php endif; endif; ?></details>
<?php cm_page_end('billing');
