<?php
declare(strict_types=1);
require_once __DIR__ . '/version.php';
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
if (empty($_SESSION['m4_authenticated'])) { header('Location: ./'); exit; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$configFile = '/home/cloakecrea/cloake-manager/private/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Private configuration unavailable.'); }
$cfg = require $configFile;
if (!is_array($cfg) || empty($cfg['db_user']) || empty($cfg['db_password'])) { http_response_code(503); exit('Configuration incomplete.'); }
try {
    $pdo = new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'cloakecrea_manager_devt').';charset=utf8mb4', $cfg['db_user'], $cfg['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
} catch (Throwable $e) { error_log('M6B DB connect: '.$e->getMessage()); http_response_code(503); exit('Database unavailable.'); }
$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if (!$id) { http_response_code(400); exit('Missing enquiry ID.'); }
if (empty($_SESSION['m6b_csrf'])) $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['m6b_csrf'], $_POST['csrf'])) {
            http_response_code(403); exit('Session expired. Reload the enquiry.');
        }
        $pdo->beginTransaction();
        // The parent-row lock serialises conversions of the same enquiry.
        $q = $pdo->prepare('SELECT * FROM enquiries WHERE id=? FOR UPDATE');
        $q->execute([$id]); $enquiry = $q->fetch();
        if (!$enquiry) { throw new RuntimeException('Enquiry not found.'); }
        $q = $pdo->prepare('SELECT id FROM tasks WHERE enquiry_id=? LIMIT 1');
        $q->execute([$id]); $taskId = $q->fetchColumn();
        if (!$taskId) {
            if (empty($enquiry['client_id'])) throw new RuntimeException('Assign a client to this enquiry before conversion.');
            $q = $pdo->prepare('SELECT id FROM clients WHERE id=?');
            $q->execute([$enquiry['client_id']]);
            if (!$q->fetchColumn()) throw new RuntimeException('The enquiry client no longer exists.');
            $allowed = ['enquiry', 'task_created'];
            if (!in_array((string)$enquiry['status'], $allowed, true)) throw new RuntimeException('This enquiry is not eligible for conversion.');
            $q = $pdo->prepare('INSERT INTO tasks (client_id,enquiry_id,source_system,source_id,title,instructions,received_at,required_by,priority,status,notes) VALUES (?,?,?,?,?,?,NOW(),?,?,?,?)');
            $q->execute([(int)$enquiry['client_id'],$id,'enquiry',(string)$id,$enquiry['title'],$enquiry['details'], $enquiry['required_by'] ? $enquiry['required_by'].' 00:00:00' : null,$enquiry['priority'],'open', $enquiry['next_action'] ?: null]);
            $taskId = (int)$pdo->lastInsertId();
            $q = $pdo->prepare("UPDATE enquiries SET status='task_created' WHERE id=?");
            $q->execute([$id]);
            $q = $pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,task_id,event_kind,event_at,summary) VALUES (?,?,?,'enquiry_converted',NOW(),?)");
            $q->execute([(int)$enquiry['client_id'],$id,$taskId,'Enquiry progressed to task']);
        }
        $pdo->commit();
        $_SESSION['m6b_csrf'] = bin2hex(random_bytes(32));
        header('Location: index.php?page=task&id='.(int)$taskId, true, 303); exit;
    }
    $q = $pdo->prepare('SELECT e.*,c.name client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.id=?');
    $q->execute([$id]); $enquiry = $q->fetch();
    if (!$enquiry) { http_response_code(404); exit('Enquiry not found.'); }
    $q = $pdo->prepare('SELECT id,title FROM tasks WHERE enquiry_id=? ORDER BY id LIMIT 1');
    $q->execute([$id]); $linkedTask = $q->fetch();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('M6B conversion error for enquiry '.$id.': '.$e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to convert enquiry. No changes committed.';
    if (!isset($enquiry)) { http_response_code(500); exit('Enquiry temporarily unavailable.'); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Progress enquiry · Cloake staging</title><link rel="stylesheet" href="assets/app.css"><style>.conversion{max-width:680px;margin:30px auto;padding:0 18px 70px}.conversion .panel{margin:18px 0;padding:18px}.conversion button{min-height:48px;padding:12px 20px}.conversion .error{color:#a22}.conversion .pre{white-space:pre-wrap}</style></head><body><main class="conversion"><a href="index.php?page=enquiries">← Enquiries</a><p class="eyebrow">PRIVATE STAGING</p><h1>Enquiry #<?=h($id)?></h1><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?><section class="panel"><h2><?=h($enquiry['title'])?></h2><p><strong>Client:</strong> <?=h($enquiry['client_name'] ?? 'Unassigned')?></p><p><strong>Priority:</strong> <?=h($enquiry['priority'])?> · <strong>Required by:</strong> <?=h($enquiry['required_by'] ?: 'Not set')?></p><p class="pre"><?=h($enquiry['details'] ?: 'No description')?></p></section><?php if (empty($linkedTask) && ($enquiry['status']??'')==='enquiry'):?><p><a class="action-button" href="edit-enquiry.php?id=<?=(int)$id?>">Edit enquiry</a></p><?php endif;?><?php if (!empty($linkedTask)): ?><p>This enquiry already has task <a href="index.php?page=task&amp;id=<?=h($linkedTask['id'])?>">#<?=h($linkedTask['id'])?> — <?=h($linkedTask['title'])?></a>. No new task will be created.</p><?php elseif (!in_array((string)$enquiry['status'], ['enquiry','task_created'],true)): ?><p>This enquiry's status is <?=h($enquiry['status'])?>, so conversion is disabled.</p><?php elseif (empty($enquiry['client_id'])): ?><p>Assign a client before converting.</p><?php else: ?><p>This creates one new <strong>open</strong> task linked to the original enquiry. It copies the client, title, instructions, priority and required-by date. The enquiry remains in the database. Pricing and proposed breakdown lines stay on the enquiry for later review; nothing is invoiced.</p><form method="post" action="convert-enquiry.php"><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6b_csrf'])?>"><button type="submit">Create linked task</button></form><?php endif; ?></main><div class="release-indicator" aria-label="Application version">v<?=htmlspecialchars(CLOAKE_VERSION, ENT_QUOTES, 'UTF-8')?> <span>· build <?=htmlspecialchars(CLOAKE_BUILD, ENT_QUOTES, 'UTF-8')?></span></div></body></html>
