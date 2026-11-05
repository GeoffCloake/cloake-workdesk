<?php
declare(strict_types=1);
/*
 * Cloake Workdesk — nightly email inventory (v0.20). RUN AS ROOT, from a root-owned copy:
 *   /usr/local/sbin/cm-email-inventory          (installed by deploy/install-email-inventory.sh)
 *
 * For every cPanel account it lists, with cPanel's own `uapi` command (read-only):
 *   - the account's domains            DomainInfo::list_domains
 *   - mailboxes (addresses only)       Email::list_pops        — no passwords are returned or written
 *   - forwarders (address → where)     Email::list_forwarders
 * and writes ONE file for Workdesk: ~cloakecrea/cloake-manager/private/email-inventory.json
 * (owned by cloakecrea, mode 600, outside the website). Workdesk only reads it. Nothing in any account is changed.
 *
 * Also, from one WHM call (whmapi1 listaccts, read-only): each account's package, disk used / quota and whether it is suspended.
 *
 * Test hooks (not used on the server): CM_UAPI (uapi binary), CM_WHMAPI (whmapi1 binary), CM_USERS_DIR (folder with one file per cPanel user),
 * CM_EMAIL_OUT (output file), CM_OWNER (owner of the output file).
 */
if (PHP_VERSION_ID < 80000) { fwrite(STDERR, 'The email inventory needs PHP 8 (this is PHP ' . PHP_VERSION . ").\n"); exit(2); }
$uapi = getenv('CM_UAPI') ?: '/usr/local/cpanel/bin/uapi';
$whmapi = getenv('CM_WHMAPI') ?: '/usr/local/cpanel/bin/whmapi1';
$usersDir = getenv('CM_USERS_DIR') ?: '/var/cpanel/users';
$out = getenv('CM_EMAIL_OUT') ?: '/home/cloakecrea/cloake-manager/private/email-inventory.json';
$owner = getenv('CM_OWNER') ?: 'cloakecrea';
if (!is_executable($uapi)) { fwrite(STDERR, "uapi not found at $uapi\n"); exit(2); }
if (!is_dir(dirname($out))) { fwrite(STDERR, 'Workdesk private folder not found: ' . dirname($out) . "\n"); exit(2); }

$call = function (string $user, string $module, string $func) use ($uapi): ?array {
    $cmd = escapeshellarg($uapi) . ' --user=' . escapeshellarg($user) . ' --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($func) . ' 2>/dev/null';
    $raw = shell_exec($cmd);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    $r = $j['result'] ?? null;
    if (!is_array($r) || empty($r['status'])) return null;
    return is_array($r['data'] ?? null) ? $r['data'] : [];
};
// One WHM call for every account's package, disk and suspension (best effort).
$meta = [];
if (is_executable($whmapi)) {
    $j = json_decode((string)shell_exec(escapeshellarg($whmapi) . ' --output=json listaccts want=user,domain,plan,diskused,disklimit,suspended 2>/dev/null'), true);
    foreach ((array)($j['data']['acct'] ?? []) as $a) if (!empty($a['user'])) $meta[(string)$a['user']] = ['package' => (string)($a['plan'] ?? ''), 'disk_used' => (string)($a['diskused'] ?? ''), 'disk_limit' => (string)($a['disklimit'] ?? ''), 'suspended' => !empty($a['suspended'])];
}
$users = [];
foreach (scandir($usersDir) ?: [] as $f) if (preg_match('/^[a-z][a-z0-9]{0,31}$/', $f) && is_file("$usersDir/$f") && $f !== 'root' && $f !== 'system') $users[] = $f;
sort($users);
$acc = []; $errors = 0;
foreach ($users as $u) {
    $doms = $call($u, 'DomainInfo', 'list_domains');
    $pops = $call($u, 'Email', 'list_pops');
    $fwds = $call($u, 'Email', 'list_forwarders');
    if ($pops === null && $fwds === null && $doms === null) { $acc[$u] = ['error' => 'uapi failed']; $errors++; continue; }
    $domains = [];
    if ($doms) {
        if (!empty($doms['main_domain'])) $domains[] = (string)$doms['main_domain'];
        foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $k) foreach ((array)($doms[$k] ?? []) as $d) $domains[] = (string)$d;
    }
    $mb = [];
    foreach ((array)$pops as $p) { $e = strtolower(trim((string)($p['email'] ?? ''))); if (str_contains($e, '@')) $mb[] = $e; }   // skips the account's own system mailbox
    $fw = [];
    foreach ((array)$fwds as $x) { $from = strtolower(trim((string)($x['dest'] ?? ''))); $to = trim((string)($x['forward'] ?? '')); if ($from !== '') $fw[] = ['from' => $from, 'to' => $to]; }
    sort($mb);
    usort($fw, fn($a, $b) => strcmp($a['from'] . $a['to'], $b['from'] . $b['to']));
    $acc[$u] = ($meta[$u] ?? []) + ['domains' => array_values(array_unique(array_map('strtolower', $domains))), 'mailboxes' => array_values(array_unique($mb)), 'forwarders' => $fw];
}
$json = json_encode(['generated_at' => date('c'), 'accounts' => $acc], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$tmp = $out . '.tmp' . getmypid();
$old = umask(0077);
file_put_contents($tmp, $json);
umask($old);
chmod($tmp, 0600);
if (function_exists('posix_getuid') && posix_getuid() === 0) @chown($tmp, $owner);
rename($tmp, $out);
echo 'Email inventory: ' . count($acc) . ' accounts, ' . array_sum(array_map(fn($a) => count($a['mailboxes'] ?? []), $acc)) . ' mailboxes, ' . array_sum(array_map(fn($a) => count($a['forwarders'] ?? []), $acc)) . ' forwarders' . ($errors ? ", $errors accounts could not be read" : '') . " → $out\n";
