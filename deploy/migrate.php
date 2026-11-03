<?php
declare(strict_types=1);
/*
 * Cloake Manager — database migration runner (CLI only).
 *
 *   php deploy/migrate.php status
 *   php deploy/migrate.php apply
 *   php deploy/migrate.php rollback --confirm      (undoes the LATEST migration only; --all for every one)
 *   php deploy/migrate.php sql                     (writes phpMyAdmin SQL files for each migration)
 *
 * Uses the private config (CM_CONFIG env overrides the default path).
 * Apply is safe to re-run: missing columns are added, backfill only fills empty
 * new columns, and every backfilled value is verified against its source.
 *
 * Rollback drops the columns a migration added, INCLUDING anything typed into them
 * since. It is only for a failed migration. Rolling back the app CODE to v0.9.0 does
 * NOT need a database rollback: the added columns are ignored by older versions.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80000) { fwrite(STDERR, "PHP 8.0+ required (this is " . PHP_VERSION . ").\n"); exit(2); }

$cmd = $argv[1] ?? 'status';
$confirm = in_array('--confirm', $argv, true);
$dir = __DIR__ . '/migrations';
$migrations = [];
foreach (glob($dir . '/[0-9][0-9][0-9]_*.php') as $f) { $m = require $f; $migrations[$m['id']] = $m; }
ksort($migrations);

function jexpr(string $field): string
{
    return "NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.\"$field\"'))),'null'),'')";
}
function listexpr(string $e): string
{
    // ["a","b"] -> a, b   (plain strings unchanged)
    return "CASE WHEN $e LIKE '[%' THEN NULLIF(TRIM(REPLACE(REPLACE(REPLACE(REPLACE($e,'[',''),']',''),'\"',''),',',', ')),'') ELSE $e END";
}
function backfillSql(array $m): array
{
    $out = [];
    foreach ($m['backfill'] ?? [] as $table => $map) {
        $sets = []; $wheres = [];
        foreach ($map as $col => $field) {
            $e = jexpr($field);
            if (in_array("$table.$col", $m['list_fields'] ?? [], true)) $e = listexpr($e);
            $sets[] = "`$col` = COALESCE(`$col`, $e)";
        }
        $out[] = "UPDATE `$table` SET " . implode(",\n  ", $sets) . "\nWHERE legacy_record IS NOT NULL";
    }
    // Backfills that need a lookup or a type conversion. {J:field} expands to the JSON value expression.
    foreach ($m['backfill_sql'] ?? [] as $sql) $out[] = preg_replace_callback('/\{J(?::([a-z]+))?:([A-Za-z0-9_]+)\}/', fn($x) => $x[1] !== '' ? str_replace('legacy_record', $x[1] . '.legacy_record', jexpr($x[2])) : jexpr($x[2]), $sql);
    return $out;
}
function addColumnsSql(string $table, array $cols): string
{
    return "ALTER TABLE `$table` " . implode(', ', array_map(fn($c, $d) => "ADD COLUMN `$c` $d", array_keys($cols), $cols));
}
function dropColumnsSql(string $table, array $cols): string
{
    return "ALTER TABLE `$table` " . implode(', ', array_map(fn($c) => "DROP COLUMN `$c`", $cols));
}
const LEDGER = "CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(80) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NULL) ENGINE=InnoDB";

if ($cmd === 'sql') {
    foreach ($migrations as $id => $m) {
        $apply = ["-- Cloake Manager migration $id: {$m['description']}", "-- phpMyAdmin fallback for deploy/migrate.php apply. Back up the database first.", "-- Run ONCE on a database that does not yet have these columns (check: More > Release check).", "", LEDGER . ";"];
        foreach ($m['tables'] ?? [] as $t => $ddl) $apply[] = $ddl . ";";
        foreach ($m['columns'] as $t => $cols) if ($cols) $apply[] = addColumnsSql($t, $cols) . ";";
        foreach (backfillSql($m) as $s) $apply[] = $s . ";";
        $apply[] = "INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES ('$id','" . addslashes($m['description']) . "',NOW(),'phpMyAdmin');";
        file_put_contents("$dir/$id.apply.sql", implode("\n", $apply) . "\n");
        $rb = ["-- UNDO migration $id. Drops the added columns" . (!empty($m['tables']) ? ' and tables' : '') . " AND anything entered into them since.", "-- Only for a failed migration. Rolling back the app code does not require this.", ""];
        foreach ($m['rollback_sql'] ?? [] as $sql) $rb[] = $sql . ";";
        foreach (array_reverse(array_keys($m['tables'] ?? [])) as $t) $rb[] = "DROP TABLE IF EXISTS `$t`;";
        foreach ($m['columns'] as $t => $cols) if ($cols) $rb[] = dropColumnsSql($t, array_keys($cols)) . ";";
        $rb[] = "DELETE FROM schema_migrations WHERE id='$id';";
        file_put_contents("$dir/$id.rollback.sql", implode("\n", $rb) . "\n");
        echo "wrote $dir/$id.apply.sql and .rollback.sql\n";
    }
    exit(0);
}

$cfgFile = getenv('CM_CONFIG') ?: '/home/cloakecrea/cloake-manager/private/config.php';
if (!is_file($cfgFile)) { fwrite(STDERR, "Private config not found: $cfgFile\n"); exit(2); }
$cfg = require $cfgFile;
try {
    $pdo = new PDO('mysql:host=' . ($cfg['db_host'] ?? 'localhost') . ';dbname=' . ($cfg['db_name'] ?? 'cloakecrea_manager_devt') . ';charset=utf8mb4',
        $cfg['db_user'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n"); exit(2); }
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

function existingColumns(PDO $pdo, string $table): array
{
    $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return array_column($s->fetchAll(), 'COLUMN_NAME');
}
function tableExists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]); return (bool)$s->fetchColumn();
}
/** True for "command denied" (no CREATE/ALTER privilege): the installer then offers the phpMyAdmin route. */
function isPrivilegeError(Throwable $e): bool { return $e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1142, 1044, 1227], true); }
function ledger(PDO $pdo): array
{
    $t = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
    return $t ? array_column($pdo->query('SELECT id FROM schema_migrations')->fetchAll(), 'id') : [];
}
/** Every non-empty source value must now be present in its column (counts must match). */
function verify(PDO $pdo, array $m): array
{
    $problems = [];
    foreach ($m['verify_sql'] ?? [] as $label => $sql) {
        $sql = preg_replace_callback('/\{J(?::([a-z]+))?:([A-Za-z0-9_]+)\}/', fn($x) => $x[1] !== '' ? str_replace('legacy_record', $x[1] . '.legacy_record', jexpr($x[2])) : jexpr($x[2]), $sql);
        $bad = (int)$pdo->query($sql)->fetchColumn();
        if ($bad) $problems[] = "$label: $bad rows";
    }
    foreach ($m['backfill'] ?? [] as $table => $map) {
        foreach ($map as $col => $field) {
            $isList = in_array("$table.$col", $m['list_fields'] ?? [], true);
            $srcExpr = $isList ? listexpr(jexpr($field)) : jexpr($field);   // [""] counts as empty, as in the backfill
            $src = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE legacy_record IS NOT NULL AND ($srcExpr) IS NOT NULL")->fetchColumn();
            $dst = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE legacy_record IS NOT NULL AND `$col` IS NOT NULL")->fetchColumn();
            if ($dst < $src) $problems[] = "$table.$col: $dst filled, $src expected";
            if (!in_array("$table.$col", $m['list_fields'] ?? [], true)) {
                $unedited = in_array('updated_at', existingColumns($pdo, $table), true) ? 'updated_at IS NULL AND ' : '';
                $bad = (int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE legacy_record IS NOT NULL AND $unedited`$col` IS NOT NULL AND BINARY `$col` <> BINARY " . jexpr($field))->fetchColumn();
                if ($bad) $problems[] = "$table.$col: $bad values differ from the imported record";
            }
        }
    }
    return $problems;
}

$applied = ledger($pdo);
echo "Database: $dbName\n";
if ($cmd === 'status') {
    $pending = 0;
    foreach ($migrations as $id => $m) {
        $missing = [];
        foreach ($m['columns'] as $t => $cols) foreach (array_diff(array_keys($cols), existingColumns($pdo, $t)) as $c) $missing[] = "$t.$c";
        foreach (array_keys($m['tables'] ?? []) as $t) if (!tableExists($pdo, $t)) $missing[] = "table $t";
        $state = in_array($id, $applied, true) ? 'applied' : ($missing ? 'PENDING' : 'columns present, not recorded');
        if ($state !== 'applied') $pending++;
        echo "  $id — $state" . ($missing && $state !== 'applied' ? ' (' . count($missing) . ' columns/tables to add)' : '') . "\n";
    }
    exit($pending ? 10 : 0);
}

if ($cmd === 'apply') {
    try { $pdo->exec(LEDGER); } catch (Throwable $e) { fwrite(STDERR, "Cannot create schema_migrations (needs CREATE/ALTER privilege): " . $e->getMessage() . "\nUse the phpMyAdmin SQL in deploy/migrations/ instead.\n"); exit(3); }
    foreach ($migrations as $id => $m) {
        if (in_array($id, $applied, true)) { echo "  $id — already applied\n"; continue; }
        echo "  $id — {$m['description']}\n";
        try {
            // New tables first (CREATE TABLE IF NOT EXISTS): if the user may not create tables, nothing has changed yet.
            foreach ($m['tables'] ?? [] as $t => $ddl) {
                if (tableExists($pdo, $t)) continue;
                $pdo->exec($ddl); echo "    created table $t\n";
            }
            foreach ($m['columns'] as $t => $cols) {
                $missing = array_diff_key($cols, array_flip(existingColumns($pdo, $t)));
                if ($missing) { $pdo->exec(addColumnsSql($t, $missing)); echo "    added " . count($missing) . " columns to $t\n"; }
            }
            $pdo->beginTransaction();
            foreach (backfillSql($m) as $s) { $n = $pdo->exec($s); echo "    filled from imported records: " . (int)$n . " rows changed\n"; }
            $problems = verify($pdo, $m);
            if ($problems) { $pdo->rollBack(); fwrite(STDERR, "    VERIFY FAILED (backfill rolled back; added columns left empty):\n      " . implode("\n      ", $problems) . "\n"); exit(4); }
            $s = $pdo->prepare('INSERT INTO schema_migrations (id,description,applied_at,applied_by) VALUES (?,?,NOW(),?)');
            $s->execute([$id, $m['description'], get_current_user() ?: 'cli']);
            $pdo->commit();
            echo "    verified: " . (empty($m["tables"]) ? "every non-empty imported value is in its new column" : "tables created and every check passed") . "\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (isPrivilegeError($e)) { fwrite(STDERR, "    The database user may not change table structure: " . $e->getMessage() . "\nUse the phpMyAdmin SQL in deploy/migrations/ instead.\n"); exit(3); }
            fwrite(STDERR, "    FAILED: " . $e->getMessage() . "\n"); exit(5);
        }
    }
    echo "Migrations up to date.\n"; exit(0);
}

if ($cmd === 'rollback') {
    // Undoes only the LATEST migration that is present (v0.11+). --all undoes every migration (newest first).
    if (!$confirm) { fwrite(STDERR, "Rollback drops the latest migration's added columns and anything entered into them. Re-run with --confirm (add --all to undo every migration).\n"); exit(2); }
    $all = in_array('--all', $argv, true); $done = 0;
    foreach (array_reverse($migrations, true) as $id => $m) {
        $present = [];
        foreach ($m['columns'] as $t => $cols) $present[$t] = array_values(array_intersect(array_keys($cols), existingColumns($pdo, $t)));
        $tables = array_values(array_filter(array_reverse(array_keys($m['tables'] ?? [])), fn($t) => tableExists($pdo, $t)));
        $recorded = in_array($id, ledger($pdo), true);
        if (!$recorded && !array_filter($present) && !$tables) continue;   // never applied: nothing to undo
        foreach ($m['rollback_sql'] ?? [] as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1054) throw $e; } }   // 1054: column already gone
        foreach ($tables as $t) { $pdo->exec("DROP TABLE `$t`"); echo "  $id — dropped table $t\n"; }
        foreach ($present as $t => $cols) if ($cols) { $pdo->exec(dropColumnsSql($t, $cols)); echo "  $id — dropped " . count($cols) . " columns from $t\n"; }
        if ($recorded) $pdo->prepare('DELETE FROM schema_migrations WHERE id=?')->execute([$id]);
        $done++;
        if (!$all) break;
    }
    echo $done ? "Rolled back.\n" : "Nothing to roll back.\n"; exit(0);
}
fwrite(STDERR, "Usage: php deploy/migrate.php status|apply|rollback --confirm|sql\n"); exit(2);
