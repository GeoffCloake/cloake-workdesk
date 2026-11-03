<?php
declare(strict_types=1);
/*
 * Staff & hours (v0.14): the people on the Workload screen — name (as used in "Action for"), shown name,
 * on the schedule or not, and hours available each weekday. POST action=save (id) | add (name). Audited.
 * Staff are never deleted (tasks keep their Action for names); switch someone off instead.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$error = ''; $savedName = '';
$hoursIn = function (string $k): int {
    $v = str_replace(',', '.', cm_post($k, 5));
    if ($v === '') return 0;
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v) || (float)$v > 24) throw new InvalidArgumentException('Hours per day are numbers from 0 to 24 (e.g. 6 or 7.5).');
    return (int)round((float)$v * 60);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();
        if ($action === 'add') {
            $name = mb_strtolower(cm_post('name', 40));
            if (!preg_match('/^[\p{L}0-9 ._\'-]{1,40}$/u', $name)) throw new InvalidArgumentException('Enter a short name (as used in Action for), e.g. "roselyn".');
            if (oneRow($pdo, 'SELECT id FROM staff WHERE name=?', [$name])) throw new InvalidArgumentException(ucfirst($name) . ' is already on the list.');
            $min = (int)cm_setting($pdo, 'work_day_minutes') ?: 360;
            $pdo->prepare('INSERT INTO staff (name,display_name,active,min_mon,min_tue,min_wed,min_thu,min_fri,min_sat,min_sun,created_at) VALUES (?,?,1,?,?,?,?,?,0,0,NOW())')->execute([$name, cm_nullable(cm_post('display_name', 100)), $min, $min, $min, $min, $min]);
            cm_audit($pdo, 'staff_added', [], 'Added ' . $name . ' to Staff & hours', ['name' => $name]);
            $savedName = $name;
        } elseif ($action === 'save') {
            $sid = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $old = oneRow($pdo, 'SELECT * FROM staff WHERE id=? FOR UPDATE', [$sid]);
            if (!$old) throw new InvalidArgumentException('Person not found.');
            $new = ['display_name' => cm_nullable(cm_post('display_name', 100)), 'active' => !empty($_POST['active']) ? 1 : 0];
            foreach (array_keys(CM_WEEKDAYS) as $d) $new['min_' . $d] = $hoursIn('h_' . $d);
            $changes = cm_changes($old, $new);
            if ($changes) {
                $sets = implode(', ', array_map(fn($f) => "`$f`=?", array_keys($changes)));
                $pdo->prepare("UPDATE staff SET $sets, updated_at=NOW() WHERE id=?")->execute([...array_column($changes, 'current'), $sid]);
                cm_audit($pdo, 'staff_updated', [], 'Staff & hours: ' . $old['name'] . ' updated (' . implode(', ', array_keys($changes)) . ')', ['row_id' => $sid] + $changes);
            }
            $savedName = $old['name'];
        } else throw new InvalidArgumentException('Unknown action.');
        $pdo->commit();
        header('Location: staff.php?saved=' . rawurlencode($savedName), true, 303); exit;
    } catch (Throwable $e) { $error = cm_edit_error($e, $pdo, 'staff'); }
}
$staff = cm_staff($pdo, false);
$fmt = fn(int $m) => $m ? rtrim(rtrim(number_format($m / 60, 2, '.', ''), '0'), '.') : '';
cm_page_start('Staff & hours', 'staff', ['back' => [route('workload'), 'Workload'], 'bodyClass' => 'form-page']);
?>
<p class="lead">The people on the Workload screen, and the hours each is available per day. The name is the one used in "Action for".</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="success" role="status">Saved <?=h(ucfirst((string)$_GET['saved']))?>.</div><?php endif; ?>
<?php foreach ($staff as $s): ?>
<details class="staff-row<?=$s['active'] ? '' : ' is-off'?>" data-testid="staff-row"<?=($_GET['saved'] ?? '') === $s['name'] ? ' open' : ''?>><summary><span><strong><?=h(cm_staff_label($s))?></strong> <small class="muted"><?=$s['active'] ? h(cm_hm((int)array_sum(array_map(fn($d) => (int)$s['min_' . $d], array_keys(CM_WEEKDAYS))))) . ' a week' : 'not on the schedule'?></small></span></summary>
<form method="post" action="staff.php" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)$s['id']?>">
<div class="pair"><div class="field"><label>Name in Action for</label><input value="<?=h($s['name'])?>" disabled></div><div class="field"><label for="dn<?=(int)$s['id']?>">Shown as</label><input id="dn<?=(int)$s['id']?>" name="display_name" maxlength="100" value="<?=h((string)$s['display_name'])?>" placeholder="<?=h(ucfirst((string)$s['name']))?>"></div></div>
<label class="check"><input type="checkbox" name="active" value="1"<?=$s['active'] ? ' checked' : ''?>> On the Workload schedule</label>
<fieldset class="field"><legend class="sub">Hours available</legend><div class="week-hours"><?php foreach (CM_WEEKDAYS as $d => $label): ?><div><label for="h<?=$d?><?=(int)$s['id']?>"><?=h($label)?></label><input id="h<?=$d?><?=(int)$s['id']?>" name="h_<?=$d?>" inputmode="decimal" maxlength="5" value="<?=h($fmt((int)$s['min_' . $d]))?>"></div><?php endforeach; ?></div></fieldset>
<div class="form-actions"><button type="submit" class="save">Save <?=h(cm_staff_label($s))?></button><a class="cancel" href="staff.php">Cancel</a></div></form></details>
<?php endforeach; ?>
<details class="inline-add"<?=$error && ($_POST['action'] ?? '') === 'add' ? ' open' : ''?>><summary>＋ Add a person</summary>
<form method="post" action="staff.php" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="add">
<div class="pair"><div class="field"><label for="new-name">Name in Action for</label><input id="new-name" name="name" maxlength="40" required placeholder="e.g. sam"></div><div class="field"><label for="new-dn">Shown as <span class="opt">(optional)</span></label><input id="new-dn" name="display_name" maxlength="100"></div></div>
<p class="helper">Starts with <?=h(cm_hm((int)cm_setting($pdo, 'work_day_minutes') ?: 360))?> Monday to Friday; change it after adding.</p>
<div class="form-actions"><button type="submit" class="secondary">Add person</button></div></form></details>
<?php cm_page_end('staff');
