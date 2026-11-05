<?php
declare(strict_types=1);
/*
 * Change the status of several services at once (v0.21) — from cPanel check and Domains.
 *   POST to = terminated | suspended, sids[] = service ids, back = page to return to (allow-listed)
 * One audited change for all of them (services_status_updated, multi-row undo). Only the status changes:
 * no price, no period, nothing on the server or in Xero.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
m7verify();
$to = (string)($_POST['to'] ?? '');
$back = (string)($_POST['back'] ?? '');
$back = in_array($back, ['cpanel-check.php', 'domains'], true) ? $back : 'cpanel-check.php';
$backUrl = $back === 'domains' ? route('domains', ['show' => 'expired']) : 'cpanel-check.php';
try {
    if (!in_array($to, ['terminated', 'suspended'], true)) throw new InvalidArgumentException('Unknown status.');
    $pdo->beginTransaction();
    $rows = [];
    foreach (array_unique(array_map('intval', is_array($_POST['sids'] ?? null) ? $_POST['sids'] : [])) as $sid) {
        $s = oneRow($pdo, 'SELECT * FROM services WHERE id=? FOR UPDATE', [$sid]);
        if (!$s) throw new InvalidArgumentException('A ticked service no longer exists. Reload the page.');
        if (!in_array($s['status'], CM_SERVICE_LIVE, true)) continue;   // already ended
        $ch = cm_changes($s, ['status' => $to]);
        if (!$ch) continue;
        $pdo->prepare('UPDATE services SET status=?, updated_at=NOW() WHERE id=?')->execute([$to, $sid]);
        $rows[] = ['table' => 'services', 'id' => $sid, 'changes' => $ch];
    }
    if (!$rows) throw new InvalidArgumentException('Tick the services to change.');
    cm_audit($pdo, 'services_status_updated', [], count($rows) . ' service' . (count($rows) === 1 ? '' : 's') . ' set to ' . strtolower(CM_SERVICE_STATUSES[$to]), ['_rows' => $rows]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['cm_undo_error'] = $e instanceof InvalidArgumentException ? $e->getMessage() : cm_edit_error($e, $pdo, 'services status');
}
header('Location: ' . $backUrl, true, 303);
