<?php
declare(strict_types=1);
/*
 * Cloake Client & Services Manager — shared bootstrap.
 *
 * Every page includes this file first. It is the ONLY place that defines the
 * private configuration path, session settings, security headers and the
 * database connection, so individual pages cannot drift apart again.
 *
 * Settings preserved exactly from the v0.8.x pages:
 *   - config: /home/cloakecrea/cloake-manager/private/config.php (outside public_html)
 *   - session name cloake_m4, flag $_SESSION['m4_authenticated']
 *   - cookie Secure, HttpOnly, SameSite=Strict, strict mode
 *   - CSP default-src/style-src/script-src 'self' (no inline CSS or JS anywhere)
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80000) {
    http_response_code(503); header('Content-Type: text/plain; charset=utf-8');
    exit("Cloake Manager needs PHP 8.0 or newer; this site is running PHP " . PHP_VERSION . ".\nIn cPanel > MultiPHP Manager, set this domain to ea-php83.");
}

require_once __DIR__ . '/../version.php';

const CLOAKE_CONFIG_FILE = '/home/cloakecrea/cloake-manager/private/config.php';

function cm_send_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
}

function cm_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_name('cloake_m4');
    session_start();
}

/** Load the private config. Exits 503 (never fatals) when it is missing or incomplete. */
function cm_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    if (!is_file(CLOAKE_CONFIG_FILE)) { http_response_code(503); exit('Staging setup incomplete. Install private/config.php outside the public folder.'); }
    $loaded = require CLOAKE_CONFIG_FILE;
    if (!is_array($loaded) || empty($loaded['password_hash']) || empty($loaded['db_user']) || empty($loaded['db_password'])) { http_response_code(503); exit('Configuration incomplete.'); }
    return $cfg = $loaded;
}

function cm_is_authenticated(): bool { return !empty($_SESSION['m4_authenticated']); }

/** For pages: unauthenticated visitors go to the sign-in page. */
function cm_require_login(): void
{
    if (!cm_is_authenticated()) { header('Location: ./', true, 302); exit; }
}

/**
 * Database connection. $readOnly=true puts the session into READ ONLY
 * transaction mode, as index.php has always done for browsing pages.
 */
function cm_pdo(bool $readOnly = false): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = cm_config();
    try {
        $pdo = new PDO(
            'mysql:host=' . ($cfg['db_host'] ?? 'localhost') . ';dbname=' . ($cfg['db_name'] ?? 'cloakecrea_manager_devt') . ';charset=utf8mb4',
            $cfg['db_user'], $cfg['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]
        );
        if ($readOnly) $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    } catch (Throwable $e) {
        error_log('Cloake DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Database temporarily unavailable. Check private configuration.');
    }
    return $pdo;
}

/* ---------- small shared helpers ---------- */

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function route(string $page, array $params = []): string { return 'index.php?' . http_build_query(array_merge(['page' => $page], $params)); }
function allRows(PDO $pdo, string $sql, array $params = []): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function oneRow(PDO $pdo, string $sql, array $params = []): ?array { $s = $pdo->prepare($sql); $s->execute($params); $r = $s->fetch(); return $r ?: null; }
function niceDate($s): string { if (!$s || str_starts_with((string)$s, '0000-')) return '—'; try { return (new DateTimeImmutable((string)$s))->format('j M Y'); } catch (Throwable $e) { return '—'; } }
function dur($s): string { if (!is_numeric($s)) return '—'; $x = (int)$s; return floor($x / 3600) . 'h ' . str_pad((string)floor(($x % 3600) / 60), 2, '0', STR_PAD_LEFT) . 'm'; }
function legacy(?string $json): array { if (!$json) return []; return json_decode($json, true) ?: []; }
/** Read one field from a row's legacy_record JSON; arrays (and JSON-encoded array strings) become "a, b". */
function legacyValue(array $row, string $field): string
{
    $v = legacy($row['legacy_record'] ?? null)[$field] ?? null;
    if (is_string($v) && str_starts_with(ltrim($v), '[')) { $d = json_decode($v, true); if (is_array($d)) $v = $d; }
    if (is_array($v)) return implode(', ', array_filter(array_map('strval', $v), 'strlen'));
    return trim((string)($v ?? ''));
}
/** Digits-and-plus only, for tel: links. Empty string when nothing dialable remains. */
function telHref(string $phone): string { $d = preg_replace('/[^0-9+]/', '', $phone) ?? ''; return strlen($d) >= 5 ? 'tel:' . $d : ''; }
function validEmail(string $e): bool { return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
/** Task statuses that mean "no longer open" across imported (Fabrik) and new records. */
const CM_CLOSED_TASK_STATUSES = ['completed', 'complete', 'closed', 'cancelled', 'canceled', 'done'];
function cm_closed_status_sql(string $col): string
{
    return "LOWER(TRIM(COALESCE($col,''))) IN ('" . implode("','", CM_CLOSED_TASK_STATUSES) . "')";
}
function cm_status_label(?string $s): string { $s = trim((string)$s); if ($s === 'enquiry') return 'New'; return $s === '' ? '—' : ucfirst(str_replace('_', ' ', $s)); }

/** Enquiry statuses (v0.11). 'enquiry' is the stored value for New. Open = still being worked on. */
const CM_ENQUIRY_STATUSES = ['enquiry' => 'New', 'quoted' => 'Quoted', 'waiting' => 'Waiting', 'accepted' => 'Accepted', 'task_created' => 'Task created', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn', 'duplicate' => 'Duplicate'];
const CM_ENQUIRY_OPEN = ['enquiry', 'quoted', 'waiting', 'accepted'];

/* ---------- edit-page helpers (unchanged behaviour from m7-bootstrap.php) ---------- */

function m7h($v): string { return h($v); }
function m7date($value): ?string
{
    if (!is_string($value) || trim($value) === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date.');
    return $value;
}
function m7id(): int { return filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0; }
function m7csrf(): string { if (empty($_SESSION['m7_csrf'])) $_SESSION['m7_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['m7_csrf']; }
function m7verify(): void { if (!is_string($_POST['csrf'] ?? null) || !hash_equals(m7csrf(), $_POST['csrf'])) { http_response_code(403); exit('Invalid session token. Reload the page.'); } }

/* ---------- standard page start for authenticated pages ---------- */

date_default_timezone_set('Pacific/Auckland');
cm_send_headers();
cm_start_session();
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/edit.php';
require_once __DIR__ . '/queries.php';
