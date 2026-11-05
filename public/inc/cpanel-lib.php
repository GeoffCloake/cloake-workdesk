<?php
declare(strict_types=1);
/*
 * cPanel inventory (v0.20): read-only view of what is provisioned on the server, from the file the nightly root job
 * writes (deploy/email-inventory.php → private/email-inventory.json). Workdesk holds no server credential and never
 * changes anything on the server. The file holds addresses and settings only — never passwords.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function cm_cpanel_file(): string { return dirname(CLOAKE_CONFIG_FILE) . '/email-inventory.json'; }

/** ['at' => ISO time, 'age_hours' => float, 'accounts' => [user => {...}]] or null when there is no file yet. */
function cm_cpanel_inventory(): ?array
{
    static $inv = false;
    if ($inv !== false) return $inv;
    $f = cm_cpanel_file();
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($d) || !is_array($d['accounts'] ?? null)) return $inv = null;
    $t = strtotime((string)($d['generated_at'] ?? '')) ?: (int)filemtime($f);
    return $inv = ['at' => date('Y-m-d H:i', $t), 'age_hours' => (time() - $t) / 3600, 'accounts' => $d['accounts']];
}

/** The cPanel account for a service: by its cPanel username, else the account that has its domain. [user, account] or null. */
function cm_service_account(array $s, ?array $inv = null): ?array
{
    $inv ??= cm_cpanel_inventory();
    if (!$inv) return null;
    $u = strtolower(trim((string)($s['cpanel_username'] ?? '')));
    if ($u !== '' && isset($inv['accounts'][$u]) && empty($inv['accounts'][$u]['error'])) return [$u, $inv['accounts'][$u]];
    $dom = strtolower(trim((string)($s['domain'] ?? '')));
    if ($dom === '') return null;
    foreach ($inv['accounts'] as $user => $a) if (!empty($a['domains']) && in_array($dom, (array)$a['domains'], true)) return [(string)$user, $a];
    return null;
}

/** Mailboxes on the account that belong to this service's domain (or all of them when the service is the account's main site). */
function cm_account_mailboxes(array $s, array $a): array
{
    $dom = strtolower(trim((string)($s['domain'] ?? '')));
    $all = (array)($a['mailboxes'] ?? []);
    $own = array_values(array_filter($all, fn($m) => $dom !== '' && str_ends_with($m, '@' . $dom)));
    return ($a['domains'][0] ?? '') === $dom ? $all : $own;
}
function cm_account_forwarders(array $s, array $a): array
{
    $dom = strtolower(trim((string)($s['domain'] ?? '')));
    $all = (array)($a['forwarders'] ?? []);
    return ($a['domains'][0] ?? '') === $dom ? $all : array_values(array_filter($all, fn($f) => $dom !== '' && str_ends_with((string)$f['from'], '@' . $dom)));
}

/** Your own sending/management mailbox (Billing settings): by name before the @, or on a subdomain of one of the account's domains. */
function cm_is_management_mailbox(PDO $pdo, string $email, array $accountDomains): bool
{
    [$local, $dom] = array_pad(explode('@', strtolower($email), 2), 2, '');
    foreach (array_filter(array_map('trim', explode(',', cm_setting($pdo, 'management_mailboxes')))) as $pat)
        if (preg_match('/^' . str_replace('\\*', '.*', preg_quote($pat, '/')) . '$/', $local)) return true;
    if (cm_setting($pdo, 'management_subdomains') !== 'no') foreach ($accountDomains as $d) if ($dom !== $d && str_ends_with($dom, '.' . $d)) return true;
    return false;
}
/** Client mailboxes (counted and billed) and management ones, for this service. */
function cm_service_mailboxes(PDO $pdo, array $s, array $a): array
{
    $client = []; $mgmt = [];
    foreach (cm_account_mailboxes($s, $a) as $m) { if (cm_is_management_mailbox($pdo, $m, (array)($a['domains'] ?? []))) $mgmt[] = $m; else $client[] = $m; }
    return ['client' => $client, 'management' => $mgmt];
}

/** Disk in MB as "120 MB of 2,000 MB" (cPanel reports e.g. "120M" and "unlimited"). */
function cm_disk_text(array $a): string
{
    $u = (string)($a['disk_used'] ?? ''); $l = (string)($a['disk_limit'] ?? '');
    if ($u === '' && $l === '') return '';
    $n = fn($v) => is_numeric(rtrim($v, 'Mm')) ? number_format((float)rtrim($v, 'Mm')) . ' MB' : $v;
    return $n($u) . ($l !== '' ? ' of ' . $n($l) : '');
}
