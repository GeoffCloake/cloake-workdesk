<?php
declare(strict_types=1);
/*
 * Xero connection (v0.17).
 *   GET              status: app saved? connected to which organisation?
 *   GET ?connect=<t> start Xero sign-in (a link, not a form: the page's form-action policy stays 'self')
 *   POST action = save_app (client_id, client_secret) | tenant (tenant_id) | disconnect
 * Secrets are written to private/xero.json (mode 600) and never shown back.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$x = cm_xero_load();
$error = ''; $saved = '';
if (isset($_GET['connect'])) {
    if (!is_string($_GET['connect']) || !hash_equals(m7csrf(), $_GET['connect'])) { http_response_code(403); exit('Invalid session token. Reload the page.'); }
    if (cm_xero_state($x) === 'none') { header('Location: xero.php', true, 303); exit; }
    $x['pending_state'] = bin2hex(random_bytes(24)); $x['pending_at'] = time();
    cm_xero_save($x);
    $q = ['response_type' => 'code', 'client_id' => $x['client_id'], 'redirect_uri' => cm_xero_redirect_uri(), 'scope' => (string)(cm_config()['xero_scopes'] ?? CM_XERO_SCOPES), 'state' => $x['pending_state']];
    header('Location: ' . cm_xero_endpoints()['authorize'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986), true, 302); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo = cm_pdo();
        if ($action === 'save_app') {
            $id = trim((string)($_POST['client_id'] ?? '')); $sec = trim((string)($_POST['client_secret'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $id)) throw new InvalidArgumentException('Paste the Client id from your Xero app (letters and numbers).');
            if ($sec === '' && empty($x['client_secret'])) throw new InvalidArgumentException('Paste the Client secret from your Xero app.');
            if ($sec !== '' && !preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $sec)) throw new InvalidArgumentException('That does not look like a Xero client secret.');
            $changed = ($x['client_id'] ?? '') !== $id || $sec !== '';
            if ($changed && ($x['client_id'] ?? '') !== $id) $x = [];          // a different app: forget the old connection
            $x['client_id'] = $id; if ($sec !== '') $x['client_secret'] = $sec;
            cm_xero_save($x);
            cm_audit($pdo, 'xero_app_saved', [], 'Xero app details saved (client id …' . substr($id, -4) . ')', ['client_id_end' => substr($id, -4)]);
            $saved = 'Xero app saved. Now connect.';
        } elseif ($action === 'tenant') {
            $tid = (string)($_POST['tenant_id'] ?? '');
            $t = array_values(array_filter($x['tenants'] ?? [], fn($t) => $t['id'] === $tid))[0] ?? null;
            if (!$t) throw new InvalidArgumentException('Choose one of the organisations listed.');
            $x['tenant_id'] = $t['id']; $x['tenant_name'] = $t['name']; cm_xero_save($x);
            cm_audit($pdo, 'xero_connected', [], 'Xero organisation chosen: ' . $t['name'], ['tenant' => $t['name']]);
            $saved = 'Using ' . $t['name'] . '.';
        } elseif ($action === 'disconnect') {
            if (!empty($x['refresh_token'])) { try { cm_xero_http('POST', cm_xero_endpoints()['revoke'], [cm_xero_basic($x), 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $x['refresh_token']])); } catch (Throwable $e) { /* removed locally anyway */ } }
            $name = (string)($x['tenant_name'] ?? '');
            $x = array_intersect_key($x, array_flip(['client_id', 'client_secret'])); cm_xero_save($x);
            cm_audit($pdo, 'xero_disconnected', [], 'Xero disconnected' . ($name ? ' (' . $name . ')' : ''), []);
            $saved = 'Disconnected. Workdesk can no longer reach Xero.';
        } else throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $e) { $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Could not save. Nothing changed.'; if (!($e instanceof InvalidArgumentException)) error_log('Cloake xero: ' . $e->getMessage()); }
}
$state = cm_xero_state($x);
$flash = (string)($_SESSION['cm_xero_flash'] ?? ''); unset($_SESSION['cm_xero_flash']);
cm_page_start('Xero connection', 'billing', ['back' => [route('more'), 'More'], 'bodyClass' => 'form-page']);
$hid = fn(string $a) => '<input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($a) . '">';
?>
<p class="lead">Lets Pending Invoicing create <strong>draft</strong> invoices in Xero. Workdesk never approves, sends or pays anything — Cathy reviews and approves each draft in Xero.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($saved || $flash): ?><div class="success" role="status"><?=h($saved ?: $flash)?></div><?php endif; ?>
<section class="panel" data-testid="xero-status"><h2 class="panel-title">Status</h2>
<?php if ($state === 'connected'): ?><p><strong>Connected to <?=h((string)$x['tenant_name'])?></strong><?=cm_xero_is_demo($x) ? ' <em class="tag">Demo Company — test data</em>' : ''?></p><p class="small muted">Since <?=h(niceDate($x['connected_at'] ?? null))?>. The connection renews itself each time it is used; if it isn't used for 60 days, connect again.</p>
<?php elseif ($state === 'app' && !empty($x['tenants'])): ?><p>Signed in to Xero — choose the organisation to use:</p>
<form method="post" action="xero.php" class="form row-inline"><?=$hid('tenant')?><label for="tenant" class="visually-hidden">Organisation</label><select id="tenant" name="tenant_id"><?php foreach ($x['tenants'] as $t): ?><option value="<?=h($t['id'])?>"><?=h($t['name'])?></option><?php endforeach; ?></select><button type="submit" class="secondary">Use this organisation</button></form>
<?php elseif ($state === 'app'): ?><p>App saved, not connected yet.</p>
<?php else: ?><p>Not set up.</p><?php endif; ?>
<?php if ($state !== 'none'): ?><p class="row-inline"><a class="button-link" href="xero.php?connect=<?=h(rawurlencode(m7csrf()))?>" data-testid="xero-connect"><?=$state === 'connected' ? 'Reconnect / switch organisation' : 'Connect to Xero'?></a></p><?php endif; ?>
</section>
<details<?=$state === 'none' ? ' open' : ''?>><summary>Xero app details</summary>
<ol class="small steps-list"><li>At <strong>developer.xero.com</strong> › My Apps › <strong>New app</strong>: Web app; Redirect URI exactly <code data-testid="redirect-uri"><?=h(cm_xero_redirect_uri())?></code></li><li>Open the app › <strong>Configuration</strong>: copy the <strong>Client id</strong>, and <strong>Generate a secret</strong> and copy it.</li><li>Paste both below and Save, then <strong>Connect to Xero</strong> and choose <strong>Demo Company</strong> for the test.</li></ol>
<form method="post" action="xero.php" class="form" autocomplete="off"><?=$hid('save_app')?>
<div class="field"><label for="client_id">Client id</label><input id="client_id" name="client_id" maxlength="64" required value="<?=h((string)($x['client_id'] ?? ''))?>" spellcheck="false"></div>
<div class="field"><label for="client_secret">Client secret <span class="opt"><?=!empty($x['client_secret']) ? '(saved — leave blank to keep)' : ''?></span></label><input id="client_secret" name="client_secret" type="password" maxlength="128" autocomplete="new-password"<?=empty($x['client_secret']) ? ' required' : ''?>></div>
<p class="helper">Kept in the private folder on the server (outside the website), never in the database, never shown again.</p>
<div class="form-actions"><button type="submit" class="save">Save app details</button></div></form></details>
<?php if ($state === 'connected'): ?><details class="danger-zone"><summary>Disconnect</summary><form method="post" action="xero.php" class="form"><?=$hid('disconnect')?><p class="small">Removes Workdesk's access to Xero (drafts already made stay in Xero).</p><div class="form-actions"><button type="submit" class="danger">Disconnect from Xero</button></div></form></details><?php endif; ?>
<?php cm_page_end('billing');
