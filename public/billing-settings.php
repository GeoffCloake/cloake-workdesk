<?php
declare(strict_types=1);
/* Billing settings (v0.13): lead time, GST, Xero accounts, hourly rate, task-fee start date. Each change is audited. */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    try {
        $by = cm_post('updated_by', 100); if ($by === '') throw new InvalidArgumentException('Enter your name.');
        $pdo->beginTransaction();
        $changed = [];
        foreach (CM_SETTINGS as $k => [$label]) {
            $v = cm_post($k, 255);
            if ($k === 'lead_weeks' && !preg_match('/^\d{1,2}$/', $v)) throw new InvalidArgumentException('Lead time must be whole weeks (0–99).');
            if ($k === 'gst_rate' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 1)) throw new InvalidArgumentException('GST rate is a fraction, e.g. 0.15.');
            if ($k === 'hourly_rate_ex_gst' && $v !== '' && !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) throw new InvalidArgumentException('Hourly rate must be dollars ex GST.');
            if ($k === 'charges_from' && $v !== '') m7date($v);
            if ($k === 'material_markup_pct' && $v !== '' && !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v)) throw new InvalidArgumentException('Materials markup is a percentage, e.g. 20.');
            $old = oneRow($pdo, 'SELECT v FROM settings WHERE k=? FOR UPDATE', [$k]);
            if ((string)($old['v'] ?? CM_SETTINGS[$k][1]) === $v && $old) continue;
            $pdo->prepare('INSERT INTO settings (k,v,updated_at,updated_by) VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW(), updated_by=VALUES(updated_by)')->execute([$k, $v, $by]);
            $changed[$k] = ['previous' => $old['v'] ?? null, 'current' => $v];
        }
        if ($changed) cm_audit($pdo, 'settings_updated', [], 'Billing settings changed by ' . $by . ': ' . implode(', ', array_keys($changed)), $changed);
        $pdo->commit();
        header('Location: billing-settings.php?saved=' . ($changed ? 1 : 0), true, 303); exit;
    } catch (Throwable $e) { $error = cm_edit_error($e, $pdo, 'billing settings'); }
}
cm_page_start('Billing settings', 'billing', ['back' => [route('invoicing'), 'Pending Invoicing'], 'bodyClass' => 'form-page']);
?>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="success" role="status"><?=$_GET['saved'] === '1' ? 'Saved.' : 'No changes.'?></div><?php endif; ?>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<?php foreach (CM_SETTINGS as $k => [$label]): $v = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : cm_setting($pdo, $k); ?>
<div class="field"><label for="<?=h($k)?>"><?=h($label)?></label><input id="<?=h($k)?>" name="<?=h($k)?>" maxlength="255" value="<?=h($v)?>"<?=$k === 'charges_from' ? ' type="date"' : ' inputmode="decimal"'?>></div>
<?php endforeach; ?>
<p class="helper">Task fees: completed tasks billed on completion appear on Pending Invoicing only if they were completed on or after this date (so old work doesn't flood the list). Leave it empty to show none.</p>
<div class="field"><label for="updated_by">Your name</label><input id="updated_by" name="updated_by" maxlength="100" required value="<?=h((string)($_SESSION['cm_last_staff'] ?? ''))?>"></div>
<div class="form-actions"><button type="submit" class="save">Save settings</button><a class="cancel" href="<?=h(route('invoicing'))?>">Cancel</a></div></form>
<?php cm_page_end('billing');
