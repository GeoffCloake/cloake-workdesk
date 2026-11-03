<?php
declare(strict_types=1);
/*
 * Xero sign-in returns here (v0.17). The session cookie is SameSite=Strict, so it is NOT sent on this cross-site
 * return; the one-time 'state' saved in private/xero.json by xero.php (10 minutes) proves the request is ours.
 * Shows a short page with a link back (a same-site click, so the session works again).
 */
require __DIR__ . '/inc/bootstrap.php';
$x = cm_xero_load();
$ok = false; $msg = '';
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
if (empty($x['pending_state']) || $state === '' || !hash_equals((string)$x['pending_state'], $state) || time() - (int)($x['pending_at'] ?? 0) > 600) {
    http_response_code(400); $msg = 'This Xero sign-in link has expired or was not started here. Go back and choose Connect to Xero again.';
} else {
    unset($x['pending_state'], $x['pending_at']);
    try {
        if (!empty($_GET['error'])) throw new RuntimeException('Xero sign-in was cancelled (' . substr((string)$_GET['error'], 0, 60) . ').');
        if ($code === '') throw new RuntimeException('Xero did not send a code.');
        $x = cm_xero_exchange_code($x, $code);
        $ok = true; $msg = !empty($x['tenant_id']) ? 'Connected to ' . $x['tenant_name'] . '.' : 'Signed in to Xero — choose the organisation on the next page.';
        try { cm_audit(cm_pdo(), 'xero_connected', [], 'Xero connected' . (!empty($x['tenant_name']) ? ': ' . $x['tenant_name'] : ''), ['tenant' => $x['tenant_name'] ?? null]); } catch (Throwable $e) { error_log('Cloake xero audit: ' . $e->getMessage()); }
    } catch (Throwable $e) { $msg = $e->getMessage(); error_log('Cloake xero callback: ' . $msg); }
    cm_xero_save($x);
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Xero · Cloake Workdesk</title><link rel="stylesheet" href="<?=h(asset('app.css'))?>"></head>
<body class="login-page"><main class="login"><div class="eyebrow">CLOAKE WORKDESK</div><h1><?=$ok ? 'Xero connected' : 'Xero not connected'?></h1>
<p class="<?=$ok ? '' : 'form-error'?>" role="status" data-testid="xero-callback"><?=h($msg)?></p><p><a class="button-link" href="xero.php">Continue</a></p></main></body></html>
