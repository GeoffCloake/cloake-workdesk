<?php
declare(strict_types=1);
/*
 * Cloake Manager — WHMCS import (CLI only, v0.13). Needs migration 005.
 *
 *   php deploy/import-whmcs.php dry-run --dir <folder>             shows what would be created; changes nothing
 *   php deploy/import-whmcs.php apply   --dir <folder> --by "Name" imports in one transaction; prints the batch number
 *   php deploy/import-whmcs.php status                              lists import batches
 *   php deploy/import-whmcs.php undo    --batch <n> --confirm       removes everything that batch added
 *
 * <folder> (outside public_html) holds the files made by tools/prepare-whmcs-import.py:
 *   clients.csv services.csv domains.csv  — archived row for row (archive_whmcs)
 *   client-map.csv items.csv xero-history.csv — which live items become services, for which client, from which period
 * Any OTHER .csv in the folder (later: invoices, invoiceitems, transactions, products …) is archived as-is, one row
 * per line, keyed by its ID column — so the WHMCS history can be added later as a separate batch. (v0.22) Such a batch
 * needs no client-map.csv: rows are linked to the clients matched in the earlier batch (by WHMCS client ID).
 * Passwords: a Password/card/bank column is dropped on read even if present; note lines that look like logins are
 * removed. Rows already archived (same table + ID) and services already imported are skipped, never overwritten.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cmd = $argv[1] ?? 'status';
$opt = function (string $name) use ($argv): ?string { $i = array_search('--' . $name, $argv, true); return $i !== false ? ($argv[$i + 1] ?? '') : null; };
$cfgFile = getenv('CM_CONFIG') ?: '/home/cloakecrea/cloake-manager/private/config.php';
if (!is_file($cfgFile)) { fwrite(STDERR, "Private config not found: $cfgFile\n"); exit(2); }
$cfg = require $cfgFile;
$pdo = new PDO('mysql:host=' . ($cfg['db_host'] ?? 'localhost') . ';dbname=' . ($cfg['db_name'] ?? 'cloakecrea_manager_devt') . ';charset=utf8mb4', $cfg['db_user'], $cfg['db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$need = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('services','charges','archive_whmcs')")->fetchColumn();
if ((int)$need !== 3) { fwrite(STDERR, "Migration 005 is not applied (services/charges/archive_whmcs missing). Install v0.13 first.\n"); exit(2); }
const DROP_COLUMNS = ['password', 'password hash', 'card number', 'card type', 'card expiry', 'card last four', 'bank account', 'bank code', 'cvv', 'security answer'];
const CRED = '/pass|pwd|\bpw\b|log\s*in|user\s*(name)?\s*[:=]|ftp|cpanel|secret|\bpin\b/i';

function readCsv(string $file): array
{
    $fh = fopen($file, 'r'); $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = $r;
    fclose($fh);
    if ($rows && isset($rows[0][0])) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
    if ($rows && count(array_filter($rows[0], fn($c) => trim((string)$c) !== '')) === 1 && count($rows) > 1 && count($rows[1]) > 1) array_shift($rows);   // WHMCS title row
    $h = array_map('trim', array_shift($rows) ?? []);
    $out = [];
    foreach ($rows as $r) { if ($r === [null] || $r === ['']) continue; $out[] = array_combine($h, array_pad(array_slice($r, 0, count($h)), count($h), '')); }
    return [$h, $out];
}
function clean(array $row, array &$removed): array
{
    foreach (array_keys($row) as $k) if (in_array(strtolower(trim($k)), DROP_COLUMNS, true)) { unset($row[$k]); $removed['columns'][$k] = true; }
    foreach ($row as $k => $v) {
        if (!is_string($v) || !preg_match('/note|comment|description/i', $k)) continue;
        $lines = preg_split('/\r\n|\r|\n/', $v); $n = 0;
        foreach ($lines as &$l) if (!str_starts_with($l, '[line removed by import') && preg_match(CRED, $l)) { $l = '[line removed by import: may contain a login]'; $n++; }
        if ($n) { $row[$k] = implode("\n", $lines); $removed['lines'] = ($removed['lines'] ?? 0) + $n; }
    }
    return $row;
}
function d(?string $s): ?string
{
    $s = trim((string)$s); if ($s === '' || str_starts_with($s, '0000')) return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y'] as $f) { $x = DateTimeImmutable::createFromFormat('!' . $f, $s); if ($x) return $x->format('Y-m-d'); }
    return null;
}
function addMonths(string $date, int $n): string
{
    [$y, $m, $dd] = array_map('intval', explode('-', $date)); $m += $n; $y += intdiv($m - 1, 12); $m = ($m - 1) % 12 + 1;
    return sprintf('%04d-%02d-%02d', $y, $m, min($dd, (int)date('t', mktime(0, 0, 0, $m, 1, $y))));
}

if ($cmd === 'status') {
    foreach ($pdo->query("SELECT b.id,b.source_name,b.row_count,b.imported_at,(SELECT COUNT(*) FROM archive_whmcs a WHERE a.import_batch_id=b.id) arch,(SELECT COUNT(*) FROM services s WHERE s.import_batch_id=b.id) svc,(SELECT COUNT(*) FROM charges g WHERE g.import_batch_id=b.id) chg FROM import_batches b WHERE b.source_name LIKE 'whmcs:%' ORDER BY b.id") as $b)
        echo "  batch {$b['id']}  {$b['imported_at']}  {$b['source_name']} — archived {$b['arch']}, services {$b['svc']}, invoiced periods {$b['chg']}\n";
    exit(0);
}

if ($cmd === 'undo') {
    $batch = (int)($opt('batch') ?? 0);
    if (!$batch || !in_array('--confirm', $argv, true)) { fwrite(STDERR, "Usage: undo --batch <n> --confirm\n"); exit(2); }
    $b = $pdo->prepare("SELECT * FROM import_batches WHERE id=? AND source_name LIKE 'whmcs:%'"); $b->execute([$batch]); $b = $b->fetch();
    if (!$b) { fwrite(STDERR, "No WHMCS import batch $batch.\n"); exit(2); }
    $pdo->beginTransaction();
    try {
        $used = (int)$pdo->query("SELECT COUNT(*) FROM charges g JOIN services s ON s.id=g.source_id AND g.source_kind='service' WHERE s.import_batch_id=$batch AND (g.import_batch_id IS NULL OR g.import_batch_id<>$batch)")->fetchColumn();
        if ($used) throw new RuntimeException("$used invoices have been recorded in the manager against services from this batch. Undo those (or void them) first.");
        $ev = $pdo->prepare("SELECT payload FROM activity_events WHERE event_kind='whmcs_import' AND CAST(JSON_EXTRACT(payload,'$.batch') AS UNSIGNED)=? ORDER BY id DESC LIMIT 1"); $ev->execute([$batch]);
        $p = json_decode((string)$ev->fetchColumn(), true) ?: [];
        $n = [];
        $n['charges'] = $pdo->exec("DELETE FROM charges WHERE import_batch_id=$batch");
        $n['services'] = $pdo->exec("DELETE FROM services WHERE import_batch_id=$batch");
        $n['archive'] = $pdo->exec("DELETE FROM archive_whmcs WHERE import_batch_id=$batch");
        $n['clients'] = 0;
        foreach ($p['created_clients'] ?? [] as $cid) {
            $cid = (int)$cid; $refs = 0;
            foreach (['tasks', 'enquiries', 'contacts', 'work_log', 'services', 'charges'] as $t) $refs += (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE client_id=$cid")->fetchColumn();
            if ($refs) { echo "  kept new client #$cid (it now has records)\n"; continue; }
            $pdo->exec("UPDATE activity_events SET client_id=NULL WHERE client_id=$cid");
            $n['clients'] += $pdo->exec("DELETE FROM clients WHERE id=$cid AND source_system='whmcs'");
        }
        $pdo->prepare('INSERT INTO activity_events (event_kind,event_at,summary,payload) VALUES (\'whmcs_import_undone\',NOW(),?,?)')->execute(["WHMCS import batch $batch undone", json_encode(['batch' => $batch] + $n)]);
        $pdo->exec("DELETE FROM import_batches WHERE id=$batch");
        $pdo->commit();
        echo "Batch $batch undone: removed {$n['services']} services, {$n['charges']} invoiced periods, {$n['archive']} archived rows, {$n['clients']} new clients.\n"; exit(0);
    } catch (Throwable $e) { $pdo->rollBack(); fwrite(STDERR, "Undo refused: " . $e->getMessage() . "\nNothing was changed.\n"); exit(3); }
}

if ($cmd !== 'dry-run' && $cmd !== 'apply') { fwrite(STDERR, "Usage: php deploy/import-whmcs.php dry-run|apply --dir <folder> [--by Name] | status | undo --batch n --confirm\n"); exit(2); }
$dir = rtrim((string)$opt('dir'), '/');
if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "Give the import folder with --dir.\n"); exit(2); }
if (str_contains((string)realpath($dir), '/public_html')) { fwrite(STDERR, "The import folder is inside public_html. Move it outside the website first.\n"); exit(2); }
$by = trim((string)($opt('by') ?? ''));
if ($cmd === 'apply' && $by === '') { fwrite(STDERR, "Say who is importing: --by \"Your name\"\n"); exit(2); }
$files = glob($dir . '/*.csv'); sort($files);
$hash = hash('sha256', implode('|', array_map(fn($f) => basename($f) . ':' . hash_file('sha256', $f), $files)));
$prev = $pdo->prepare("SELECT id FROM import_batches WHERE source_name LIKE 'whmcs:%' AND sha256=?"); $prev->execute([$hash]);
if ($p = $prev->fetchColumn()) { echo "These exact files were already imported as batch $p. Nothing to do.\n"; exit(0); }
$special = ['client-map.csv', 'items.csv', 'xero-history.csv'];
$removed = ['columns' => [], 'lines' => 0];
$rep = []; $say = function (string $s) use (&$rep) { $rep[] = $s; echo $s, "\n"; };
echo ($cmd === 'dry-run' ? "DRY RUN — nothing will be changed.\n" : "IMPORT\n") . "Database: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\nFolder: $dir (" . count($files) . " files)\n";

// Client map
$map = [];
if (is_file("$dir/client-map.csv")) foreach (readCsv("$dir/client-map.csv")[1] as $r) $map[$r['whmcs_client_id']] = $r;
$clientExists = $pdo->prepare('SELECT id FROM clients WHERE id=?');
foreach ($map as $w => $r) if (in_array($r['action'], ['map', 'internal'], true)) { $clientExists->execute([(int)$r['manager_client_id']]); if (!$clientExists->fetchColumn()) { fwrite(STDERR, "client-map: WHMCS client $w → manager client #{$r['manager_client_id']} does not exist. Stopped; nothing changed.\n"); exit(4); } }

$pdo->beginTransaction();
try {
    $batch = null;
    if ($cmd === 'apply') {
        $pdo->prepare('INSERT INTO import_batches (source_name,sha256,row_count) VALUES (?,?,0)')->execute(['whmcs:' . basename($dir), $hash]);
        $batch = (int)$pdo->lastInsertId();
    }
    // 1. New clients
    $created = []; $clientOf = [];
    foreach ($map as $w => $r) {
        if ($r['action'] === 'new') {
            $ex = $pdo->prepare("SELECT id FROM clients WHERE source_system='whmcs' AND source_id=?"); $ex->execute([$w]);
            $id = $ex->fetchColumn();
            if (!$id) {
                if ($cmd === 'apply') { $pdo->prepare("INSERT INTO clients (source_system,source_id,name,status,updated_at) VALUES ('whmcs',?,?,'active',NOW())")->execute([$w, $r['new_client_name']]); $id = (int)$pdo->lastInsertId(); $created[] = $id; }
                else $id = -1;
                $say("  new client: " . $r['new_client_name'] . " (WHMCS $w)");
            } else $created[] = (int)$id;   // made by an earlier, undone batch: this batch now owns it (undo still keeps it if it has records)
            $clientOf[$w] = (int)$id;
        } elseif (in_array($r['action'], ['map', 'internal'], true)) $clientOf[$w] = (int)$r['manager_client_id'];
    }
    // v0.22: a later batch (e.g. invoices only) has no client-map.csv — use the clients matched in earlier batches
    foreach ($pdo->query("SELECT source_id, client_id FROM archive_whmcs WHERE source_table='clients' AND client_id IS NOT NULL") as $r) $clientOf[(string)$r['source_id']] ??= (int)$r['client_id'];
    // 2. Archive every row of every export
    $arch = ['new' => 0, 'skipped' => 0]; $byTable = [];
    $ins = $pdo->prepare('INSERT INTO archive_whmcs (source_table,source_id,whmcs_client_id,client_id,domain,status,source_record,removed,row_sha256,import_batch_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $exists = $pdo->prepare('SELECT 1 FROM archive_whmcs WHERE source_table=? AND source_id=?');
    $whmcs = [];
    foreach ($files as $f) {
        $base = basename($f); if (in_array($base, $special, true)) continue;
        $table = preg_replace('/[^a-z0-9_]/', '', strtolower(pathinfo($base, PATHINFO_FILENAME)));
        [$h, $rows] = readCsv($f);
        $idCol = in_array('ID', $h, true) ? 'ID' : (in_array('id', $h, true) ? 'id' : null);
        $byTable[$table] = 0;
        foreach ($rows as $i => $row) {
            $rem = ['columns' => [], 'lines' => 0];
            $row = clean($row, $rem);
            foreach (array_keys($rem['columns']) as $c) $removed['columns'][$c] = true;
            $removed['lines'] += $rem['lines'];
            $sid = $idCol ? (string)$row[$idCol] : (string)($i + 1);
            $whmcs[$table][$sid] = $row;
            $exists->execute([$table, $sid]);
            if ($exists->fetchColumn()) { $arch['skipped']++; continue; }
            $wc = $table === 'clients' ? $sid : (string)($row['User ID'] ?? $row['Client ID'] ?? $row['userid'] ?? '');
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $remTxt = trim(implode(', ', array_keys($rem['columns'])) . ($rem['lines'] ? ($rem['columns'] ? '; ' : '') . $rem['lines'] . ' note line(s)' : ''));
            if ($cmd === 'apply') $ins->execute([$table, $sid, $wc ?: null, ($clientOf[$wc] ?? 0) > 0 ? $clientOf[$wc] : null, $row['Domain Name'] ?? $row['Domain'] ?? null, $row['Status'] ?? null, $json, $remTxt ?: null, hash('sha256', $json), $batch]);
            $arch['new']++; $byTable[$table]++; if (($clientOf[$wc] ?? 0) > 0) $arch['linked'] = ($arch['linked'] ?? 0) + 1;
        }
    }
    $say('Archive: ' . $arch['new'] . ' rows (' . implode(', ', array_map(fn($t, $n) => "$t $n", array_keys($byTable), $byTable)) . ')' . ($arch['skipped'] ? '; ' . $arch['skipped'] . ' already archived, skipped' : '') . '; ' . (int)($arch['linked'] ?? 0) . ' linked to a Workdesk client');
    $say('Removed on read: ' . ($removed['columns'] ? 'columns ' . implode(', ', array_keys($removed['columns'])) : 'no columns') . '; ' . $removed['lines'] . ' note line(s) that looked like logins');
    // 3. Services from items.csv
    $svcNew = 0; $svcSkip = 0; $svcIds = []; $kinds = [];
    if (is_file("$dir/items.csv")) {
        $insS = $pdo->prepare('INSERT INTO services (client_id,kind,is_internal,billable,label,domain,plan_name,status,cycle_months,price_ex_gst,xero_account,next_period_start,expiry_date,registrar,cpanel_username,check_status,check_note,notes,source_system,source_id,import_batch_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $have = $pdo->prepare("SELECT id FROM services WHERE source_system='whmcs' AND source_id=?");
        $cyc = ['Monthly' => 1, 'Quarterly' => 3, 'Semi-Annually' => 6, 'Annually' => 12, 'Biennially' => 24];
        $st = ['active' => 'active', 'pending' => 'pending', 'suspended' => 'suspended', 'cancelled' => 'cancelled', 'terminated' => 'cancelled', 'transferred away' => 'transferred_out', 'expired' => 'expired'];
        foreach (readCsv("$dir/items.csv")[1] as $it) {
            $t = $it['whmcs_table']; $w = $whmcs[$t][$it['whmcs_id']] ?? null;
            if (!$w) throw new RuntimeException("items.csv: {$t} {$it['whmcs_id']} is not in {$t}.csv");
            $sourceId = ($t === 'domains' ? 'D' : 'S') . $it['whmcs_id'];
            $have->execute([$sourceId]); if ($have->fetchColumn()) { $svcSkip++; continue; }
            $owner = (string)($w['User ID'] ?? ''); $client = $clientOf[$owner] ?? null;
            if (!$client) throw new RuntimeException("items.csv: {$sourceId} belongs to WHMCS client $owner, which client-map.csv does not map");
            $months = $t === 'domains' ? 12 * max(1, (int)($w['Registration Period'] ?? 1)) : ($cyc[$w['Billing Cycle'] ?? ''] ?? null);
            $price = (float)($w['Recurring Amount'] ?? 0);
            $row = [$client > 0 ? $client : null, $it['kind'] ?: ($t === 'domains' ? 'domain' : 'hosting'), (int)$it['internal'], (int)$it['billable'],
                null, trim((string)($w['Domain Name'] ?? '')) ?: null, $it['plan_name'] ?: null, $st[strtolower(trim((string)($w['Status'] ?? '')))] ?? 'active',
                $months, $price, $it['xero_account'] ?: null, d($it['next_period_start']), $t === 'domains' ? d($w['Expiry Date'] ?? null) : null,
                $t === 'domains' ? (($w['Registrar'] ?? '') ?: null) : null, $t === 'services' ? (($w['Username'] ?? '') ?: null) : null,
                $it['check_status'] ?: null, $it['check_note'] ?: null, null, 'whmcs', $sourceId, $batch];
            if ($cmd === 'apply') { $insS->execute($row); $svcIds[$sourceId] = (int)$pdo->lastInsertId(); } else $svcIds[$sourceId] = -1;
            $svcNew++; $kinds[$row[1] . ($row[2] ? ' (internal)' : ($row[3] ? '' : ' (no charge)'))] = ($kinds[$row[1] . ($row[2] ? ' (internal)' : ($row[3] ? '' : ' (no charge)'))] ?? 0) + 1;
        }
    }
    ksort($kinds);
    $say("Services: $svcNew new (" . implode(', ', array_map(fn($k, $n) => "$k $n", array_keys($kinds), $kinds)) . ')' . ($svcSkip ? "; $svcSkip already imported, skipped" : ''));
    // 4. Xero history → invoiced charges
    $chg = 0; $chgSkip = 0;
    if (is_file("$dir/xero-history.csv")) {
        $insC = $pdo->prepare("INSERT INTO charges (client_id,source_kind,source_id,period_start,period_end,description,amount_ex_gst,xero_account,status,xero_invoice_number,recorded_by,recorded_at,origin,import_batch_id,created_at) VALUES (?, 'service', ?, ?, ?, ?, ?, ?, 'invoiced', ?, ?, ?, 'xero_import', ?, NOW())");
        $svc = $pdo->prepare('SELECT id,client_id,xero_account FROM services WHERE id=?');
        $dupC = $pdo->prepare("SELECT 1 FROM charges WHERE source_kind='service' AND source_id=? AND period_start=?");
        foreach (readCsv("$dir/xero-history.csv")[1] as $x) {
            $sourceId = ($x['whmcs_table'] === 'domains' ? 'D' : 'S') . $x['whmcs_id'];
            $sid = $svcIds[$sourceId] ?? null;
            if ($sid === null) { $have->execute([$sourceId]); $sid = $have->fetchColumn() ?: null; if ($sid) $sid = (int)$sid; }
            if (!$sid) { $chgSkip++; continue; }
            if ($cmd === 'apply') {
                $dupC->execute([$sid, $x['period_start']]); if ($dupC->fetchColumn()) { $chgSkip++; continue; }
                $svc->execute([$sid]); $s = $svc->fetch();
                $insC->execute([$s['client_id'], $sid, $x['period_start'], $x['period_end'] ?: null, $x['description'] ?: null, $x['amount_ex_gst'], $s['xero_account'], $x['invoice_number'] !== '' ? $x['invoice_number'] : '(no number)',
                    str_starts_with((string)$x['status'], 'Recorded by') ? 'Cathy (workbook)' : 'Xero history (import)', ($x['invoice_date'] ?: date('Y-m-d')) . ' 00:00:00', $batch]);
            }
            $chg++;
        }
    }
    $say("Invoiced periods from Xero: $chg" . ($chgSkip ? " ($chgSkip skipped: item not imported or period already recorded)" : ''));
    // Summary of what Pending Invoicing will show
    if (is_file("$dir/items.csv")) {
        $lead = (int)($pdo->query("SELECT v FROM settings WHERE k='lead_weeks'")->fetchColumn() ?: 8);
        $hor = (new DateTimeImmutable('today'))->modify("+$lead weeks")->format('Y-m-d'); $today = date('Y-m-d');
        $due = 0; $over = 0; $sum = 0.0;
        foreach (readCsv("$dir/items.csv")[1] as $it) {
            if (!(int)$it['billable'] || (int)$it['internal'] || !$it['next_period_start']) continue;
            $w = $whmcs[$it['whmcs_table']][$it['whmcs_id']] ?? []; $np = d($it['next_period_start']);
            if ($np && $np <= $hor) { $due++; $sum += (float)($w['Recurring Amount'] ?? 0); if ($np < $today) $over++; }
        }
        $say(sprintf('Pending Invoicing after import: %d due within %d weeks (%d overdue), $%s ex GST', $due, $lead, $over, number_format($sum, 2)));
    }
    if ($cmd === 'dry-run') { $pdo->rollBack(); echo "Dry run complete — nothing was changed. Run again with apply --by \"Your name\" to import.\n"; exit(0); }
    $pdo->prepare('UPDATE import_batches SET row_count=? WHERE id=?')->execute([$arch['new'] + $svcNew + $chg, $batch]);
    $pdo->prepare('INSERT INTO activity_events (event_kind,event_at,summary,actor,payload) VALUES (\'whmcs_import\',NOW(),?,?,?)')
        ->execute(["WHMCS import batch $batch: $svcNew services, $chg invoiced periods, {$arch['new']} archived rows", mb_substr($by, 0, 100), json_encode(['batch' => $batch, 'created_clients' => $created, 'report' => $rep], JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    echo "Imported as batch $batch. Undo with: php deploy/import-whmcs.php undo --batch $batch --confirm\n"; exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "STOPPED: " . $e->getMessage() . "\nNothing was changed.\n"); exit(5);
}
