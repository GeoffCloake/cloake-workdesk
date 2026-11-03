<?php
declare(strict_types=1);
/*
 * Pricing module (v0.12): price lines + billing arrangement + milestones, shared by
 * enquiries (the quote), tasks and task templates. One panel, rendered by cm_pricing_panel(),
 * edited through pricing.php. Needs bootstrap.php (edit.php is loaded there).
 *
 * Owner = ['type' => 'enquiry'|'task'|'template', 'id' => int]. A price line or milestone has ONE home:
 * enquiry_id, else task_id, else template_id. Lines and milestones on an enquiry may also be FOR one of
 * its planned tasks (plan_id) and, once that task exists, for the task (task_id) — the same row, so the
 * enquiry and the task always show the same figures.
 *
 * Milestone readiness is worked out from its trigger, never stored, so undoing a sign-off or reopening
 * a task puts the milestone back to planned by itself:
 *   accepted  enquiry status accepted or task created
 *   signoff   its task is signed off / completed (for the whole job: every task of the enquiry)
 *   date      due date reached
 *   manual    someone marked it ready (marked_ready_at)
 * Stored status is only planned | void (invoiced arrives with v0.13).
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_GST_RATE = 0.15;
const CM_BILLING_MODES = ['' => 'On completion', 'monthly' => 'Monthly (each month end)', 'milestones' => 'Milestones'];
const CM_PRICING_MODES = ['set' => 'Set fee', 'hourly' => 'Hourly', 'web_service' => 'Web service'];
const CM_MS_TRIGGERS = ['accepted' => 'When the enquiry is accepted', 'signoff' => 'When its task is signed off', 'date' => 'On a date', 'manual' => 'When marked ready'];

function cm_money($v): string { $n = (float)$v; return ($n < 0 ? '−$' : '$') . number_format(abs($n), 2); }
function cm_qty($v): string { $s = rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
function cm_owner_col(string $type): string { return ['enquiry' => 'enquiry_id', 'task' => 'task_id', 'template' => 'template_id'][$type] ?? throw new InvalidArgumentException('Invalid owner.'); }

/** Load an owner. Returns ['type','id','row','editable','url','label','client_id','enquiry_id','task_id'] or null. */
function cm_owner(PDO $pdo, string $type, int $id): ?array
{
    if ($id < 1) return null;
    if ($type === 'enquiry') {
        $r = oneRow($pdo, 'SELECT * FROM enquiries WHERE id=?', [$id]); if (!$r) return null;
        return ['type' => $type, 'id' => $id, 'row' => $r, 'editable' => $r['legacy_source_id'] === null, 'url' => 'convert-enquiry.php?id=' . $id, 'label' => 'Enquiry #' . $id, 'client_id' => $r['client_id'], 'enquiry_id' => $id, 'task_id' => null];
    }
    if ($type === 'task') {
        $r = oneRow($pdo, 'SELECT * FROM tasks WHERE id=?', [$id]); if (!$r) return null;
        return ['type' => $type, 'id' => $id, 'row' => $r, 'editable' => true, 'url' => route('task', ['id' => $id]), 'label' => 'Task #' . $id, 'client_id' => $r['client_id'], 'enquiry_id' => $r['enquiry_id'], 'task_id' => $id];
    }
    if ($type === 'template') {
        $r = oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$id]); if (!$r) return null;
        return ['type' => $type, 'id' => $id, 'row' => $r, 'editable' => true, 'url' => 'edit-template.php?id=' . $id, 'label' => 'Template', 'client_id' => null, 'enquiry_id' => null, 'task_id' => null];
    }
    return null;
}

/** Audit ids for an owner (templates have none). */
function cm_owner_ids(array $o): array { return array_filter(['client_id' => $o['client_id'], 'enquiry_id' => $o['type'] === 'enquiry' ? $o['id'] : null, 'task_id' => $o['task_id']]); }

/** Price lines that belong to (or are for) an owner, in order. */
function cm_price_lines(PDO $pdo, array $o): array
{
    return allRows($pdo, 'SELECT * FROM breakdown_items WHERE ' . cm_owner_col($o['type']) . '=? ORDER BY COALESCE(line_order,65535), id', [$o['id']]);
}
function cm_milestones(PDO $pdo, array $o): array
{
    return allRows($pdo, 'SELECT * FROM billing_milestones WHERE ' . cm_owner_col($o['type']) . '=? ORDER BY COALESCE(milestone_order,65535), id', [$o['id']]);
}
function cm_line_total(array $l): float { return round((float)$l['quantity'] * (float)$l['unit_price_ex_gst'], 2); }
function cm_lines_total(array $lines): float { return round(array_sum(array_map('cm_line_total', $lines)), 2); }

/** Planned tasks and real tasks of an enquiry, for the "For" choice: ['' => whole job, 'plan:N' => .., 'task:N' => ..]. */
function cm_for_choices(PDO $pdo, array $o): array
{
    if ($o['type'] !== 'enquiry') return [];
    $out = ['' => 'Whole job'];
    foreach (allRows($pdo, 'SELECT id,title,task_id FROM enquiry_task_plans WHERE enquiry_id=? ORDER BY COALESCE(plan_order,65535),id', [$o['id']]) as $p) if (!$p['task_id']) $out['plan:' . $p['id']] = 'Planned: ' . $p['title'];
    foreach (allRows($pdo, 'SELECT id,title FROM tasks WHERE enquiry_id=? ORDER BY id', [$o['id']]) as $t) $out['task:' . $t['id']] = 'Task #' . $t['id'] . ': ' . $t['title'];
    return $out;
}
function cm_for_value(array $row): string { return $row['task_id'] ? 'task:' . $row['task_id'] : ($row['plan_id'] ? 'plan:' . $row['plan_id'] : ''); }
function cm_for_label(PDO $pdo, array $row, array $choices): string { $k = cm_for_value($row); return $k === '' ? '' : ($choices[$k] ?? ''); }
/** Resolve a posted "For" value into [plan_id, task_id] for an enquiry owner. */
function cm_for_resolve(PDO $pdo, array $o, string $v): array
{
    if ($o['type'] !== 'enquiry' || $v === '') return [null, null];
    if (preg_match('/^plan:(\d+)$/', $v, $m)) {
        $p = oneRow($pdo, 'SELECT id,task_id FROM enquiry_task_plans WHERE id=? AND enquiry_id=?', [(int)$m[1], $o['id']]);
        if (!$p) throw new InvalidArgumentException('That planned task is not on this enquiry.');
        return [(int)$p['id'], $p['task_id'] ? (int)$p['task_id'] : null];
    }
    if (preg_match('/^task:(\d+)$/', $v, $m)) {
        $t = oneRow($pdo, 'SELECT id FROM tasks WHERE id=? AND enquiry_id=?', [(int)$m[1], $o['id']]);
        if (!$t) throw new InvalidArgumentException('That task is not linked to this enquiry.');
        $p = oneRow($pdo, 'SELECT id FROM enquiry_task_plans WHERE task_id=?', [(int)$t['id']]);
        return [$p ? (int)$p['id'] : null, (int)$t['id']];
    }
    throw new InvalidArgumentException('Invalid choice.');
}

/** The lines a milestone's percentage applies to: those for the same task/planned task, else all the owner's lines. */
function cm_ms_base(array $ms, array $lines): float
{
    $same = array_filter($lines, function ($l) use ($ms) {
        if ($ms['task_id'] && $ms['enquiry_id']) return (int)$l['task_id'] === (int)$ms['task_id'];
        if ($ms['plan_id'] && !$ms['task_id']) return (int)$l['plan_id'] === (int)$ms['plan_id'];
        return true;
    });
    return cm_lines_total($same);
}
function cm_ms_amount(array $ms, array $lines): float
{
    if ($ms['amount_ex_gst'] !== null && $ms['amount_ex_gst'] !== '') return (float)$ms['amount_ex_gst'];
    return round(cm_ms_base($ms, $lines) * (float)$ms['percent'] / 100, 2);
}

/** ['state' => planned|ready|void|template, 'since' => ?string, 'why' => string] */
function cm_ms_state(PDO $pdo, array $ms): array
{
    if ($ms['status'] === 'void') return ['state' => 'void', 'since' => null, 'why' => $ms['void_reason'] ? 'Void: ' . $ms['void_reason'] : 'Void'];
    if ($ms['template_id'] && !$ms['enquiry_id'] && !$ms['task_id']) return ['state' => 'template', 'since' => null, 'why' => CM_MS_TRIGGERS[$ms['trigger_kind']] ?? ''];
    switch ($ms['trigger_kind']) {
        case 'accepted':
            $eid = $ms['enquiry_id'] ?: ($ms['task_id'] ? (oneRow($pdo, 'SELECT enquiry_id FROM tasks WHERE id=?', [(int)$ms['task_id']])['enquiry_id'] ?? null) : null);
            $e = $eid ? oneRow($pdo, 'SELECT status FROM enquiries WHERE id=?', [(int)$eid]) : null;
            return $e && in_array($e['status'], ['accepted', 'task_created'], true) ? ['state' => 'ready', 'since' => null, 'why' => 'Enquiry accepted'] : ['state' => 'planned', 'since' => null, 'why' => 'When the enquiry is accepted'];
        case 'signoff':
            if ($ms['task_id']) {
                $t = oneRow($pdo, 'SELECT id,status,completed_at,signed_off_at FROM tasks WHERE id=?', [(int)$ms['task_id']]);
                $done = $t && ($t['signed_off_at'] || ($t['completed_at'] && strtolower((string)$t['status']) === 'completed'));
                return $done ? ['state' => 'ready', 'since' => $t['signed_off_at'] ?: $t['completed_at'], 'why' => 'Task #' . $t['id'] . ' signed off'] : ['state' => 'planned', 'since' => null, 'why' => 'When task #' . (int)$ms['task_id'] . ' is signed off'];
            }
            if ($ms['plan_id']) return ['state' => 'planned', 'since' => null, 'why' => 'When its task is signed off (task not created yet)'];
            if ($ms['enquiry_id']) {
                $ts = allRows($pdo, 'SELECT status,completed_at,signed_off_at FROM tasks WHERE enquiry_id=?', [(int)$ms['enquiry_id']]);
                $open = array_filter($ts, fn($t) => !($t['signed_off_at'] || ($t['completed_at'] && strtolower((string)$t['status']) === 'completed')));
                return $ts && !$open ? ['state' => 'ready', 'since' => max(array_map(fn($t) => (string)($t['signed_off_at'] ?: $t['completed_at']), $ts)), 'why' => 'All tasks signed off'] : ['state' => 'planned', 'since' => null, 'why' => 'When all its tasks are signed off'];
            }
            return ['state' => 'planned', 'since' => null, 'why' => 'When its task is signed off'];
        case 'date':
            $due = (string)$ms['due_date'];
            return $due !== '' && $due <= date('Y-m-d') ? ['state' => 'ready', 'since' => $due, 'why' => 'Date reached'] : ['state' => 'planned', 'since' => null, 'why' => $due !== '' ? 'On ' . niceDate($due) : 'On a date (not set)'];
        case 'manual':
            return $ms['marked_ready_at'] ? ['state' => 'ready', 'since' => $ms['marked_ready_at'], 'why' => 'Marked ready' . ($ms['marked_ready_by'] ? ' by ' . $ms['marked_ready_by'] : '')] : ['state' => 'planned', 'since' => null, 'why' => 'When marked ready'];
    }
    return ['state' => 'planned', 'since' => null, 'why' => ''];
}

/* ---------- validation of posted values ---------- */

function cm_line_posted(): array
{
    $v = [];
    foreach (['description' => 5000, 'quantity' => 14, 'unit_price' => 14, 'pricing_mode' => 20, 'account_name' => 120, 'for' => 30] as $f => $max) $v[$f] = cm_post($f, $max);
    if (mb_strlen($v['description']) < 3) throw new InvalidArgumentException('Price line needs a description (3–5000 characters).');
    if ($v['quantity'] === '') $v['quantity'] = '1';
    if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $v['quantity']) || (float)$v['quantity'] <= 0) throw new InvalidArgumentException('Quantity must be a positive number (up to 3 decimals).');
    $v['unit_price'] = str_replace([',', '$'], '', $v['unit_price']);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $v['unit_price'])) throw new InvalidArgumentException('Price must be a number of dollars ex GST (up to 2 decimals).');
    if (!isset(CM_PRICING_MODES[$v['pricing_mode']])) throw new InvalidArgumentException('Invalid pricing type.');
    return $v;
}
function cm_ms_posted(bool $hasEnquiry): array
{
    $v = [];
    foreach (['label' => 255, 'amount' => 14, 'percent' => 6, 'trigger_kind' => 20, 'due_date' => 10, 'for' => 30] as $f => $max) $v[$f] = cm_post($f, $max);
    if (mb_strlen($v['label']) < 2) throw new InvalidArgumentException('Give the milestone a name, e.g. "Deposit" or "Stage 1 complete".');
    $v['amount'] = str_replace([',', '$'], '', $v['amount']); $v['percent'] = rtrim($v['percent'], '% ');
    if (($v['amount'] === '') === ($v['percent'] === '')) throw new InvalidArgumentException('Enter either an amount (ex GST) or a percentage — one of the two.');
    if ($v['amount'] !== '' && !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $v['amount'])) throw new InvalidArgumentException('Amount must be dollars ex GST (up to 2 decimals).');
    if ($v['percent'] !== '' && (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $v['percent']) || (float)$v['percent'] <= 0 || (float)$v['percent'] > 100)) throw new InvalidArgumentException('Percentage must be between 0 and 100.');
    if (!isset(CM_MS_TRIGGERS[$v['trigger_kind']])) throw new InvalidArgumentException('Choose when the milestone becomes ready to invoice.');
    if ($v['trigger_kind'] === 'accepted' && !$hasEnquiry) throw new InvalidArgumentException('"When the enquiry is accepted" needs an enquiry; this task has none.');
    $v['due_date'] = m7date($v['due_date']);
    if ($v['trigger_kind'] === 'date' && !$v['due_date']) throw new InvalidArgumentException('Enter the date for a dated milestone.');
    return $v;
}

/* ---------- copying (templates ⇄ enquiries ⇄ tasks) ---------- */

/** Copy a template's price lines and milestones to a new home. $home: column => value (enquiry_id/plan_id/task_id/template_id). */
function cm_copy_pricing(PDO $pdo, string $fromCol, int $fromId, array $home): array
{
    $n = ['lines' => 0, 'milestones' => 0];
    $cols = array_keys($home);
    $base = (int)(oneRow($pdo, 'SELECT COALESCE(MAX(line_order),0) m FROM breakdown_items WHERE ' . $cols[0] . '=?', [reset($home)])['m'] ?? 0);
    foreach (allRows($pdo, "SELECT * FROM breakdown_items WHERE $fromCol=? ORDER BY COALESCE(line_order,65535),id", [$fromId]) as $i => $l) {
        $pdo->prepare('INSERT INTO breakdown_items (' . implode(',', $cols) . ',description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,account_name,created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ",?,?,?,?,'proposed',?,?,NOW())")
            ->execute([...array_values($home), $l['description'], $l['quantity'], $l['unit_price_ex_gst'], $l['pricing_mode'], $base + $i + 1, $l['account_name']]);
        $n['lines']++;
    }
    $mbase = (int)(oneRow($pdo, 'SELECT COALESCE(MAX(milestone_order),0) m FROM billing_milestones WHERE ' . $cols[0] . '=?', [reset($home)])['m'] ?? 0);
    foreach (allRows($pdo, "SELECT * FROM billing_milestones WHERE $fromCol=? AND status<>'void' ORDER BY COALESCE(milestone_order,65535),id", [$fromId]) as $i => $m) {
        $pdo->prepare('INSERT INTO billing_milestones (' . implode(',', $cols) . ",milestone_order,label,amount_ex_gst,percent,trigger_kind,due_date,status,created_at) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ",?,?,?,?,?,?,'planned',NOW())")
            ->execute([...array_values($home), $mbase + $i + 1, $m['label'], $m['amount_ex_gst'], $m['percent'], $m['trigger_kind'], $m['due_date']]);
        $n['milestones']++;
    }
    return $n;
}

/** Add a planned task to an enquiry from a template (fields + price lines + milestones). Returns the plan id. */
function cm_plan_from_template(PDO $pdo, int $enquiryId, int $templateId): int
{
    $tt = oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$templateId]);
    if (!$tt) throw new InvalidArgumentException('Template not found.');
    if (!(int)$tt['active']) throw new InvalidArgumentException('That template is archived. Restore it first to use it.');
    $order = (int)(oneRow($pdo, 'SELECT COALESCE(MAX(plan_order),0) m FROM enquiry_task_plans WHERE enquiry_id=?', [$enquiryId])['m'] ?? 0) + 1;
    $pdo->prepare('INSERT INTO enquiry_task_plans (enquiry_id,plan_order,title,instructions,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode,template_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$enquiryId, $order, $tt['title'], $tt['instructions'], $tt['task_category'], $tt['task_type'], $tt['assigned_to'], $tt['pricing'], $tt['chargeable'], $tt['estimate_minutes'], $tt['billing_mode'], $templateId]);
    $planId = (int)$pdo->lastInsertId();
    cm_copy_pricing($pdo, 'template_id', $templateId, ['enquiry_id' => $enquiryId, 'plan_id' => $planId]);
    return $planId;
}

/** Active templates for pickers. */
function cm_template_options(PDO $pdo): array
{
    return allRows($pdo, 'SELECT tt.id,tt.title,tt.instructions,tt.task_category,tt.task_type,(SELECT SUM(b.quantity*b.unit_price_ex_gst) FROM breakdown_items b WHERE b.template_id=tt.id) total FROM task_templates tt WHERE tt.active=1 ORDER BY tt.title');
}

/* ---------- the panel ---------- */

/**
 * Render the pricing panel for an owner. Read-only when the owner is not editable.
 * $opt['open'] => bool (start expanded). Forms post to pricing.php.
 */
function cm_pricing_panel(PDO $pdo, array $o, array $opt = []): void
{
    $lines = cm_price_lines($pdo, $o);
    $mss = cm_milestones($pdo, $o);
    $choices = cm_for_choices($pdo, $o);
    $mode = (string)($o['row']['billing_mode'] ?? '');
    $total = cm_lines_total($lines); $gst = round($total * CM_GST_RATE, 2);
    $ed = $o['editable'];
    $hid = fn(string $action) => '<input type="hidden" name="owner_type" value="' . h($o['type']) . '"><input type="hidden" name="owner_id" value="' . (int)$o['id'] . '"><input type="hidden" name="csrf" value="' . h(m7csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">';
    $err = ($_SESSION['cm_pricing_error']['key'] ?? '') === $o['type'] . ':' . $o['id'] ? (string)$_SESSION['cm_pricing_error']['msg'] : '';
    if ($err) unset($_SESSION['cm_pricing_error']);
    $hasEnquiry = $o['type'] === 'enquiry' || !empty($o['enquiry_id']);
    ?>
<section class="panel pricing" id="pricing" data-testid="pricing">
<h2 class="panel-title">Price &amp; billing <small class="muted"><?=$o['type'] === 'enquiry' ? 'the quote' : ($o['type'] === 'template' ? 'copied when the template is used' : '')?></small></h2>
<?php if ($err): ?><div class="alert" role="alert"><?=h($err)?></div><?php endif; ?>
<?php if (!$lines): ?><p class="muted small">No price lines yet.</p><?php else: ?>
<ul class="price-lines"><?php foreach ($lines as $i => $l): $for = cm_for_label($pdo, $l, $choices); ?>
<li class="price-line"><span class="pl-main"><strong><?=h($l['description'])?></strong><small><?=h(cm_qty($l['quantity']))?> × <?=h(cm_money($l['unit_price_ex_gst']))?> · <?=h(CM_PRICING_MODES[$l['pricing_mode']] ?? $l['pricing_mode'])?><?=$l['account_name'] ? ' · ' . h($l['account_name']) : ''?><?=$for !== '' ? ' · ' . h($for) : ''?><?php if ($o['type'] === 'task' && $l['enquiry_id']): ?> · from enquiry #<?=(int)$l['enquiry_id']?><?php endif; ?></small></span>
<span class="pl-end"><strong><?=h(cm_money(cm_line_total($l)))?></strong><?php if ($ed): ?><span class="pl-acts"><?php if ($i > 0): ?><form method="post" action="pricing.php"><?=$hid('line_up')?><input type="hidden" name="line_id" value="<?=(int)$l['id']?>"><button type="submit" class="mini" aria-label="Move up: <?=h($l['description'])?>">↑</button></form><?php endif; ?><a class="mini" href="pricing.php?owner_type=<?=h($o['type'])?>&amp;owner_id=<?=(int)$o['id']?>&amp;line=<?=(int)$l['id']?>">Edit</a></span><?php endif; ?></span></li>
<?php endforeach; ?></ul>
<?php endif; ?>
<dl class="price-totals" data-testid="price-totals"><dt>Total ex GST</dt><dd><strong><?=h(cm_money($total))?></strong></dd><dt>GST <?=(int)round(CM_GST_RATE * 100)?>%</dt><dd><?=h(cm_money($gst))?></dd><dt>Total incl GST</dt><dd><?=h(cm_money($total + $gst))?></dd></dl>
<?php if ($ed): ?>
<details class="inline-add"><summary>＋ Add price line</summary>
<form method="post" action="pricing.php" class="form"><?=$hid('line_add')?>
<?php cm_line_fields(['description' => '', 'quantity' => '1', 'unit_price_ex_gst' => '', 'pricing_mode' => 'set', 'account_name' => '', 'task_id' => null, 'plan_id' => null], $choices, 'n' . $o['type']); ?>
<div class="form-actions"><button type="submit" class="secondary">Add line</button></div></form></details>
<?php endif; ?>

<h3 class="pricing-sub">Billing</h3>
<?php if ($ed): ?>
<form method="post" action="pricing.php" class="billing-mode"><?=$hid('billing_save')?><label for="bm-<?=h($o['type'])?>">How it is billed</label><div class="row-inline"><select id="bm-<?=h($o['type'])?>" name="billing_mode"><?php foreach (CM_BILLING_MODES as $k => $l): ?><option value="<?=h($k)?>"<?=$mode === $k ? ' selected' : ''?>><?=h($l)?></option><?php endforeach; ?><?php if ($mode !== '' && !isset(CM_BILLING_MODES[$mode])): ?><option value="<?=h($mode)?>" selected><?=h($mode)?></option><?php endif; ?></select><button type="submit" class="secondary">Save</button></div></form>
<?php else: ?><p><?=h(CM_BILLING_MODES[$mode] ?? $mode)?></p><?php endif; ?>
<p class="helper"><?php if ($mode === 'monthly'): ?>A claim at each month end: billable hours logged that month, or a progress claim on a set fee. The month-end run and invoicing come with Pending Invoicing (v0.13).<?php elseif ($mode === 'milestones'): ?>Invoiced in parts as each milestone becomes ready. Invoicing comes with Pending Invoicing (v0.13).<?php else: ?>One invoice when the work is signed off.<?php endif; ?></p>
<?php if ($mss || $mode === 'milestones'): ?>
<h3 class="pricing-sub">Milestones <?php if ($mss): ?><small class="muted"><?=h(cm_money(array_sum(array_map(fn($m) => $m['status'] === 'void' ? 0 : cm_ms_amount($m, $lines), $mss))))?> of <?=h(cm_money($total))?> ex GST</small><?php endif; ?></h3>
<?php if (!$mss): ?><p class="muted small">No milestones yet.</p><?php endif; ?>
<ul class="milestones"><?php foreach ($mss as $m): $st = cm_ms_state($pdo, $m); $for = cm_for_label($pdo, $m, $choices); ?>
<li class="milestone ms-<?=h($st['state'])?>" data-testid="milestone"><span class="pl-main"><strong><?=h($m['label'])?></strong><small><?=$m['percent'] !== null && $m['amount_ex_gst'] === null ? h(rtrim(rtrim((string)$m['percent'], '0'), '.')) . '% · ' : ''?><?=h($st['why'])?><?=$for !== '' ? ' · ' . h($for) : ''?></small></span>
<span class="pl-end"><strong><?=h(cm_money(cm_ms_amount($m, $lines)))?></strong><em class="ms-state"><?=h(['planned' => 'Planned', 'ready' => 'Ready to invoice', 'void' => 'Void', 'template' => 'In template'][$st['state']])?><?=$st['since'] && $st['state'] === 'ready' ? ' · ' . h(niceDate($st['since'])) : ''?></em><?php if ($ed): ?><a class="mini" href="pricing.php?owner_type=<?=h($o['type'])?>&amp;owner_id=<?=(int)$o['id']?>&amp;ms=<?=(int)$m['id']?>">Edit</a><?php endif; ?></span></li>
<?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($ed): ?>
<details class="inline-add"><summary>＋ Add milestone</summary>
<form method="post" action="pricing.php" class="form"><?=$hid('ms_add')?>
<?php cm_ms_fields(['label' => '', 'amount_ex_gst' => '', 'percent' => '', 'trigger_kind' => $hasEnquiry ? 'accepted' : 'signoff', 'due_date' => '', 'task_id' => null, 'plan_id' => null], $choices, $hasEnquiry, 'nm' . $o['type']); ?>
<div class="form-actions"><button type="submit" class="secondary">Add milestone</button></div></form></details>
<?php endif; ?>
<p class="helper">Prices are ex GST. Nothing here is invoiced or sent to Xero.</p>
</section>
<?php
}

function cm_line_fields(array $l, array $choices, string $p = 'l'): void
{
    ?>
<div class="field"><label for="<?=$p?>-desc">Description</label><textarea id="<?=$p?>-desc" name="description" required minlength="3" maxlength="5000"><?=h($l['description'])?></textarea></div>
<div class="triple"><div><label for="<?=$p?>-qty">Qty</label><input id="<?=$p?>-qty" name="quantity" inputmode="decimal" maxlength="14" value="<?=h($l['quantity'] === '' ? '' : cm_qty($l['quantity']))?>"></div><div><label for="<?=$p?>-price">Price ex GST</label><input id="<?=$p?>-price" name="unit_price" inputmode="decimal" maxlength="14" placeholder="0.00" required value="<?=h($l['unit_price_ex_gst'] === '' || $l['unit_price_ex_gst'] === null ? '' : number_format((float)$l['unit_price_ex_gst'], 2, '.', ''))?>"></div><div><label for="<?=$p?>-mode">Pricing</label><select id="<?=$p?>-mode" name="pricing_mode"><?php foreach (CM_PRICING_MODES as $k => $v): ?><option value="<?=h($k)?>"<?=$l['pricing_mode'] === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div></div>
<div class="pair"><div class="field"><label for="<?=$p?>-acct">Account <span class="opt">(optional)</span></label><input id="<?=$p?>-acct" name="account_name" maxlength="120" value="<?=h($l['account_name'])?>" placeholder="e.g. Web design"></div>
<?php if ($choices): ?><div class="field"><label for="<?=$p?>-for">For</label><select id="<?=$p?>-for" name="for"><?php foreach ($choices as $k => $v): ?><option value="<?=h($k)?>"<?=cm_for_value($l) === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div><?php endif; ?></div>
<?php
}

function cm_ms_fields(array $m, array $choices, bool $hasEnquiry, string $p = 'm'): void
{
    $triggers = CM_MS_TRIGGERS; if (!$hasEnquiry) unset($triggers['accepted']);
    $pct = $m['percent'] !== null && $m['percent'] !== '' && ($m['amount_ex_gst'] === null || $m['amount_ex_gst'] === '') ? rtrim(rtrim((string)$m['percent'], '0'), '.') : '';
    ?>
<div class="field"><label for="<?=$p?>-label">Milestone</label><input id="<?=$p?>-label" name="label" required maxlength="255" value="<?=h($m['label'])?>" placeholder="e.g. Deposit, Stage 1 complete"></div>
<div class="pair"><div class="field"><label for="<?=$p?>-amt">Amount ex GST</label><input id="<?=$p?>-amt" name="amount" inputmode="decimal" maxlength="14" placeholder="0.00" value="<?=h($m['amount_ex_gst'] === null || $m['amount_ex_gst'] === '' ? '' : number_format((float)$m['amount_ex_gst'], 2, '.', ''))?>"></div><div class="field"><label for="<?=$p?>-pct">or % of the price</label><input id="<?=$p?>-pct" name="percent" inputmode="decimal" maxlength="6" placeholder="e.g. 30" value="<?=h($pct)?>"></div></div>
<div class="pair"><div class="field"><label for="<?=$p?>-trig">Ready to invoice</label><select id="<?=$p?>-trig" name="trigger_kind"><?php foreach ($triggers as $k => $v): ?><option value="<?=h($k)?>"<?=$m['trigger_kind'] === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div><div class="field"><label for="<?=$p?>-due">Date <span class="opt">(for “On a date”)</span></label><input type="date" id="<?=$p?>-due" name="due_date" value="<?=h((string)$m['due_date'])?>"></div></div>
<?php if ($choices): ?><div class="field"><label for="<?=$p?>-for">For</label><select id="<?=$p?>-for" name="for"><?php foreach ($choices as $k => $v): ?><option value="<?=h($k)?>"<?=cm_for_value($m) === $k ? ' selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select><p class="helper">A percentage is of the lines for the same task (or of the whole job).</p></div><?php endif; ?>
<?php
}
