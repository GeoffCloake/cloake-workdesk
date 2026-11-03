<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Task templates (v0.12): reusable tasks with their price lines and milestones. Replace the "Pro forma" client's tasks. */
$rows = allRows($pdo, 'SELECT tt.*, (SELECT SUM(b.quantity*b.unit_price_ex_gst) FROM breakdown_items b WHERE b.template_id=tt.id) total,
  (SELECT COUNT(*) FROM billing_milestones m WHERE m.template_id=tt.id) milestones,
  (SELECT COUNT(*) FROM enquiry_task_plans p WHERE p.template_id=tt.id) used
  FROM task_templates tt ORDER BY tt.active DESC, tt.title');
$active = array_filter($rows, fn($r) => (int)$r['active'] === 1);
$archived = array_filter($rows, fn($r) => (int)$r['active'] !== 1);
// Open pro-forma tasks that have been copied into templates (retire them once the templates are checked).
$closedSql = cm_closed_status_sql('tk.status');
$proForma = allRows($pdo, "SELECT tk.id,tk.title,c.id client_id,c.name client_name,c.status client_status FROM tasks tk JOIN clients c ON c.id=tk.client_id
  WHERE LOWER(TRIM(c.name)) IN ('pro forma','pro-forma','proforma') AND NOT ($closedSql) AND tk.completed_at IS NULL
    AND EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id=tk.id) ORDER BY tk.title");
$retired = ($_GET['retired'] ?? '') === '1';
$row = function (array $r): void { ?>
  <a class="record" href="edit-template.php?id=<?=(int)$r['id']?>"><span><strong><?=h($r['title'])?></strong><small><?=h(implode(' · ', array_filter([implode(' › ', array_filter([(string)$r['task_category'], (string)$r['task_type']])), $r['pricing'] ? (CM_PRICING_MODES[$r['pricing']] ?? $r['pricing']) : '', CM_BILLING_MODES[(string)$r['billing_mode']] ?? '', (int)$r['milestones'] ? $r['milestones'] . ' milestone' . ((int)$r['milestones'] === 1 ? '' : 's') : '', (int)$r['used'] ? 'used ' . $r['used'] . '×' : ''])))?></small></span><span class="end"><?=$r['total'] !== null ? h(cm_money($r['total'])) . '<small>ex GST</small>' : '<small>no price</small>'?> ›</span></a>
<?php };
?>
<p class="lead">Start an enquiry's planned tasks from a template: its instructions, price lines and milestones are copied in, and can then be changed for that job.</p>
<p><a class="button-link" href="edit-template.php"><span aria-hidden="true">＋</span> New template</a></p>
<?php if ($retired && !cm_undo_pending()): ?><div class="success" role="status">Pro-forma tasks retired.</div><?php endif; ?>
<?php if ($proForma): ?>
<section class="panel notice" data-testid="retire-proforma"><h2 class="panel-title">Pro-forma tasks now copied into templates</h2>
<p>These <?=count($proForma)?> open tasks on the “<?=h($proForma[0]['client_name'])?>” client were copied into templates. Once you've checked the templates, retire them: they're set to Cancelled (so they leave the open task lists) and the client is archived. You can undo this straight afterwards.</p>
<ul class="plain"><?php foreach ($proForma as $p): ?><li><a href="<?=h(route('task', ['id' => $p['id']]))?>">#<?=(int)$p['id']?> <?=h($p['title'])?></a></li><?php endforeach; ?></ul>
<form method="post" action="edit-template.php" class="form"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="action" value="retire_proforma">
<label class="check"><input type="checkbox" name="confirm" value="1" required> Retire these <?=count($proForma)?> pro-forma tasks and archive the client</label>
<div class="form-actions"><button type="submit" class="secondary">Retire pro-forma tasks</button></div></form></section>
<?php endif; ?>
<h2>Templates <small><?=count($active)?></small></h2>
<?php if (!$active): ?><p class="emptyline">No templates yet. Make one here, or from any task with “Make a template from this task”.</p><?php endif; ?>
<div class="stack compact-list"><?php foreach ($active as $r) $row($r); ?></div>
<?php if ($archived): ?><details><summary>Archived templates (<?=count($archived)?>)</summary><div class="stack compact-list"><?php foreach ($archived as $r) $row($r); ?></div></details><?php endif; ?>
