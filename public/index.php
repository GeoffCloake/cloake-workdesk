<?php
declare(strict_types=1);
/*
 * Cloake Workdesk (formerly Cloake Client & Services Manager) — main router.
 * Sign-in/out, then dispatches to one screen file in inc/pages/.
 * Browsing screens use a READ ONLY database session; all writes happen in the
 * dedicated form pages (add-work, add-enquiry, convert-enquiry, edit-*).
 */
require __DIR__ . '/inc/bootstrap.php';
$cfg = cm_config();

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
    if (($_POST['action'] ?? '') === 'login') {
        if (password_verify((string)($_POST['password'] ?? ''), $cfg['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['m4_authenticated'] = true;
            $next = (string)($_POST['next'] ?? '');
            header('Location: ' . (preg_match('/^index\.php\?page=[a-z]+(&[a-z_]+=[A-Za-z0-9_%+.-]*)*$/', $next) ? $next : './'));
            exit;
        }
        $loginError = 'Incorrect password.';
        sleep(1);
    }
}

if (!cm_is_authenticated()) {
    http_response_code(401);
    $next = isset($_GET['page']) ? 'index.php?' . http_build_query(array_intersect_key($_GET, array_flip(['page', 'id', 'q']))) : '';
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Cloake Workdesk</title><link rel="stylesheet" href="<?=h(asset('app.css'))?>"></head>
<body class="login-page"><main class="login"><div class="eyebrow">CLOAKE · PRIVATE STAGING</div><h1>Sign in</h1><p class="muted">Cloake Workdesk</p>
<form method="post" action="index.php"><input type="hidden" name="action" value="login"><input type="hidden" name="next" value="<?=h($next)?>"><label for="pw">Access password</label><input id="pw" name="password" type="password" required autocomplete="current-password"><button type="submit">Sign in</button></form>
<?php if ($loginError): ?><p class="form-error" role="alert"><?=h($loginError)?></p><?php endif; ?><p class="build-label login-version" data-testid="version"><?=h(cm_version_label())?></p></main></body></html>
<?php exit;
}

$pdo = cm_pdo(true);
$pages = [
    'today' => ['Today', 'today'], 'clients' => ['Clients', 'clients'], 'client' => ['Client', 'clients'],
    'tasks' => ['Tasks', 'tasks'], 'task' => ['Task', 'tasks'], 'work' => ['Work Log', 'work'],
    'enquiries' => ['Enquiries', 'enquiries'], 'invoicing' => ['Pending Invoicing', 'invoicing'],
    'items' => ['Account Items', 'items'], 'more' => ['More', 'more'], 'check' => ['Release check', 'check'],
    'templates' => ['Task templates', 'templates'], 'workload' => ['Workload', 'workload'], 'services' => ['Services', 'services'], 'domains' => ['Domains', 'services'], 'service' => ['Service', 'services'],
];
$page = (string)($_GET['page'] ?? 'today');
if (!isset($pages[$page])) $page = 'today';
[$title, $section] = $pages[$page];
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$today = date('Y-m-d'); // Pacific/Auckland, set in bootstrap
$scripts = [];

// Screens may set $title/$pageOpt before output via a "prepare" step; keep it simple:
// each screen file prints its own body and may append to $scripts.
ob_start();
try {
    require __DIR__ . '/inc/pages/' . $page . '.php';
} catch (Throwable $e) {
    error_log('Cloake page ' . $page . ' failed: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo '<div class="notice" role="alert"><strong>This screen could not be loaded.</strong> No data was changed. The error has been logged on the server.</div>';
}
$body = ob_get_clean();

cm_page_start($pageTitle ?? $title, $section, $pageOpt ?? []);
echo $body;
cm_page_end($section, $scripts);
