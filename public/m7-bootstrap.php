<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('cloake_m4');
session_start();
if (empty($_SESSION['m4_authenticated'])) { header('Location: index.php', true, 302); exit; }
function m7h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function m7date($value): ?string {
    if (!is_string($value) || trim($value)==='') return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d')!==$value) throw new InvalidArgumentException('Enter a valid date.');
    return $value;
}
function m7id(): int { return filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0; }
function m7csrf(): string { if (empty($_SESSION['m7_csrf'])) $_SESSION['m7_csrf']=bin2hex(random_bytes(32)); return $_SESSION['m7_csrf']; }
function m7verify(): void { if (!is_string($_POST['csrf']??null) || !hash_equals(m7csrf(),$_POST['csrf'])) {http_response_code(403);exit('Invalid session token. Reload the page.');} }
$configFile='/home/cloakecrea/cloake-manager/private/config.php';
if (!is_file($configFile)) { http_response_code(503);exit('Private configuration unavailable.'); }
$cfg=require $configFile;
if (!is_array($cfg) || empty($cfg['db_user']) || empty($cfg['db_password'])) {http_response_code(503);exit('Configuration incomplete.');}
try {
    $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'cloakecrea_manager_devt').';charset=utf8mb4',$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
} catch(Throwable $e) {error_log('M7 database connection failed: '.$e->getMessage());http_response_code(503);exit('Database temporarily unavailable.');}
