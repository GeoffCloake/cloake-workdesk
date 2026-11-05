<?php
declare(strict_types=1);
/*
 * Archive quiet clients (v0.22.3): Active clients with no live service, no open task, no open enquiry and nothing
 * since the cut-off (work, tasks, enquiries, invoices recorded, WHMCS invoices), listed for ticking.
 *   GET  clients-tidy.php?years=3
 *   POST sids[] → those clients' status = archive, in ONE undoable change (clients_archived, multi-row undo).
 * Archiving changes only the status: nothing is deleted, history stays, Xero is not touched.
 */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$years = in_array((int)($_GET['years'] ?? 3), [1, 2, 3, 5, 10], true) ? (int)($_GET['years'] ?? 3) : 3;
$cut = (new DateTimeImmutable('today'))->modify("-$years years")->format('Y-m-d');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    m7verify();
    try {
        $pdo->beginTransaction();
        $rows = [];
        foreach (array_unique(array_map('intval', is_array($_POST['sids'] ?? null) ? $_POST['sids'] : [])) as $cid) {
            $c = oneRow($pdo, 'SELECT * FROM clients WHERE id=? FOR UPDATE', [$cid]);
            if (!$c) throw new InvalidArgumentException('A ticked client no longer exists. Reload the page.');
            if (strtolower((string)$c['status']) === 'archive') continue;
            $ch = cm_changes($c, ['status' => 'archive']);
            if (!$ch) continue;
            $pdo->prepare("UPDATE clients SET status='archive', updated_at=NOW() WHERE id=?")->execute([$cid]);
            $rows[] = ['table' => 'clients', 'id' => $cid, 'changes' => $ch];
        }
        if (!$rows) throw new InvalidArgumentException('Tick the clients to archive.');
        cm_audit($pdo, 'clients_archived', [], count($rows) . ' client' . (count($rows) === 1 ? '' : 's') . ' archived', ['_rows' => $rows]);
        $pdo->commit();
        header('Location: clients-tidy.php?years=' . $years, true, 303); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : cm_edit_error($e, $pdo, 'archive clients');
    }
}
$closed = cm_closed_status_sql('t.status');
$hasSvc = (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services'");
$last = "GREATEST(
  COALESCE((SELECT MAX(COALESCE(w.work_date, DATE(w.started_at))) FROM work_log w WHERE w.client_id=c.id), '1000-01-01'),
  COALESCE((SELECT MAX(GREATEST(COALESCE(DATE(t.received_at),'1000-01-01'), COALESCE(DATE(t.completed_at),'1000-01-01'))) FROM tasks t WHERE t.client_id=c.id), '1000-01-01'),
  COALESCE((SELECT MAX(COALESCE(DATE(e.received_at), DATE(e.created_at))) FROM enquiries e WHERE e.client_id=c.id), '1000-01-01'),
  COALESCE(DATE(c.created_at), '1000-01-01')"
  . ($hasSvc ? ",
  COALESCE((SELECT MAX(g.period_start) FROM charges g WHERE g.client_id=c.id AND g.status<>'void'), '1000-01-01'),
  COALESCE((SELECT MAX(LEFT(JSON_UNQUOTE(JSON_EXTRACT(a.source_record, '$.\"Creation Date\"')), 10)) FROM archive_whmcs a WHERE a.client_id=c.id AND a.source_table='invoices'), '1000-01-01')" : '') . ")";
$cands = allRows($pdo, "SELECT c.id, c.name, c.business_name, $last last_at,
    (SELECT COUNT(*) FROM tasks t WHERE t.client_id=c.id) tasks
  FROM clients c
  WHERE LOWER(COALESCE(c.status,'')) <> 'archive'
    AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.client_id=c.id AND NOT ($closed) AND t.completed_at IS NULL)
    AND NOT EXISTS (SELECT 1 FROM enquiries e WHERE e.client_id=c.id AND e.status IN ('enquiry','quoted','waiting','accepted'))"
  . ($hasSvc ? " AND NOT EXISTS (SELECT 1 FROM services s WHERE s.client_id=c.id AND s.status IN ('active','pending','suspended'))" : '') . "
  HAVING last_at < ?
  ORDER BY last_at, c.name", [$cut]);
cm_page_start('Archive quiet clients', 'clients', ['back' => [route('clients'), 'Clients']]);
?>
<p class="lead">Active clients with <strong>no live service, no open task and no open enquiry</strong>, and nothing at all (work, tasks, enquiries, invoices) since the date below. Archiving only hides them from everyday lists — their history stays and you can find or reactivate them any time. Xero is not touched.</p>
<?php if ($error): ?><div class="alert" role="alert"><?=h($error)?></div><?php endif; ?>
<nav class="chips" aria-label="Quiet for"><?php foreach ([1, 2, 3, 5, 10] as $y): ?><a class="chip<?=$y === $years ? ' on' : ''?>" href="clients-tidy.php?years=<?=$y?>">Nothing for <?=$y?> year<?=$y === 1 ? '' : 's'?></a><?php endforeach; ?></nav>
<p class="small muted" data-testid="tidy-count"><?=count($cands)?> client<?=count($cands) === 1 ? '' : 's'?> with nothing since <?=h(niceDate($cut))?></p>
<?php if (!$cands): ?><p class="emptyline">None — every active client has something recent or live.</p>
<?php else: ?>
<form method="post" action="clients-tidy.php?years=<?=$years?>" class="form" data-testid="tidy-list"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>">
<ul class="plan-list"><?php foreach ($cands as $c): ?><li data-client="<?=(int)$c['id']?>"><span class="pl-main"><label class="check"><input type="checkbox" name="sids[]" value="<?=(int)$c['id']?>" checked> <strong><a href="<?=h(route('client', ['id' => $c['id']]))?>"><?=h($c['name'])?></a></strong></label>
<small><?=h(implode(' · ', array_filter([$c['last_at'] > '1000-01-01' ? 'last activity ' . niceDate($c['last_at']) : 'no recorded activity', (int)$c['tasks'] ? $c['tasks'] . ' past task' . ((int)$c['tasks'] === 1 ? '' : 's') : ''])))?></small></span></li><?php endforeach; ?></ul>
<div class="form-actions"><button type="submit" class="save">Archive ticked clients</button><a class="cancel" href="<?=h(route('clients'))?>">Cancel</a></div>
<p class="helper">Untick any you want to keep active. One Undo puts them all back.</p></form>
<?php endif; ?>
<?php cm_page_end('clients');
