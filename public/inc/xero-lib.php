<?php
declare(strict_types=1);
/*
 * Xero draft invoices (v0.17) — OAuth 2.0 connection and the one write Workdesk makes: a DRAFT sales invoice.
 *
 *  - The Xero app's client ID / secret and the tokens live in private/xero.json (next to config.php, outside the
 *    web root, mode 600) — never in the database, never shown back on screen.
 *  - Scopes: offline_access accounting.invoices accounting.contacts accounting.settings.read (granular scopes; apps made
 *    since March 2026 cannot use the old broad ones). Override with 'xero_scopes' in config.php if Xero changes them again.
 *    The scopes Xero actually granted are kept (x['scope']); Xero sync asks to reconnect when one is missing.
 *  - The only status ever sent is DRAFT (cm_xero_create_draft refuses anything else). Nothing is approved, emailed or paid.
 *  - Contact: the client's "Xero contact reference" if it is a Xero ContactID, else an exact name match in Xero.
 *    No match → refused (so no duplicate contacts are made) — except in Xero's Demo Company, where the name is sent
 *    and Xero creates the contact, so the test can run against made-up data.
 *  - Test endpoints can be set with 'xero_endpoints' in config.php (used only by the automated tests).
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

// v0.18: + accounting.contacts (create/update contacts from Xero sync) and accounting.settings.read (check account codes and tax rates)
const CM_XERO_SCOPES = 'offline_access accounting.invoices accounting.contacts accounting.settings.read';

function cm_xero_file(): string { return dirname(CLOAKE_CONFIG_FILE) . '/xero.json'; }

function cm_xero_endpoints(): array
{
    $e = cm_config()['xero_endpoints'] ?? [];
    return [
        'authorize' => $e['authorize'] ?? 'https://login.xero.com/identity/connect/authorize',
        'token' => $e['token'] ?? 'https://identity.xero.com/connect/token',
        'revoke' => $e['revoke'] ?? 'https://identity.xero.com/connect/revocation',
        'connections' => $e['connections'] ?? 'https://api.xero.com/connections',
        'api' => rtrim($e['api'] ?? 'https://api.xero.com/api.xro/2.0', '/'),
    ];
}

function cm_xero_load(): array
{
    $f = cm_xero_file();
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function cm_xero_save(array $d): void
{
    $f = cm_xero_file(); $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
    $old = umask(0077);
    try {
        if (file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Could not write the Xero settings file.');
        chmod($tmp, 0600);
        rename($tmp, $f);
    } finally { umask($old); if (is_file($tmp)) @unlink($tmp); }
}

function cm_xero_redirect_uri(): string
{
    $c = cm_config();
    if (!empty($c['xero_redirect_uri'])) return (string)$c['xero_redirect_uri'];
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'manager-dev.cloakecreative.co.nz');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return 'https://' . $host . $dir . '/xero-callback.php';
}

/** 'none' (no app saved) | 'app' (app saved, not connected) | 'connected' */
function cm_xero_state(?array $x = null): string
{
    $x ??= cm_xero_load();
    if (empty($x['client_id']) || empty($x['client_secret'])) return 'none';
    return !empty($x['refresh_token']) && !empty($x['tenant_id']) ? 'connected' : 'app';
}

/** True when the connection was granted this scope (connections made before v0.18 have no record → false for the new ones). */
function cm_xero_has_scope(array $x, string $scope): bool { return in_array($scope, preg_split('/\s+/', trim((string)($x['scope'] ?? ''))), true); }

function cm_xero_is_demo(array $x): bool { return (bool)preg_match('/demo company/i', (string)($x['tenant_name'] ?? '')); }

/** Minimal HTTPS call. Returns [status, decoded body|null, raw]. */
function cm_xero_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => empty(cm_config()['xero_endpoints']) ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Could not reach Xero (' . $e . '). Nothing was created.'); }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$status, json_decode((string)$raw, true), (string)$raw];
}

function cm_xero_basic(array $x): string { return 'Authorization: Basic ' . base64_encode($x['client_id'] . ':' . $x['client_secret']); }

/** Exchange the authorisation code (callback). Stores tokens and the organisations this connection can reach. */
function cm_xero_exchange_code(array $x, string $code): array
{
    [$st, $j] = cm_xero_http('POST', cm_xero_endpoints()['token'], [cm_xero_basic($x), 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => cm_xero_redirect_uri()]));
    if ($st !== 200 || empty($j['refresh_token'])) throw new RuntimeException('Xero did not accept the connection (' . ($j['error'] ?? $st) . ').');
    $x = cm_xero_store_tokens($x, $j);
    [$st, $conns] = cm_xero_http('GET', cm_xero_endpoints()['connections'], ['Authorization: Bearer ' . $x['access_token'], 'Accept: application/json']);
    if ($st !== 200 || !is_array($conns)) throw new RuntimeException('Connected, but Xero did not list the organisations (' . $st . ').');
    $x['tenants'] = array_values(array_map(fn($c) => ['id' => (string)$c['tenantId'], 'name' => (string)($c['tenantName'] ?? ''), 'type' => (string)($c['tenantType'] ?? '')], array_filter($conns, fn($c) => ($c['tenantType'] ?? 'ORGANISATION') === 'ORGANISATION')));
    if (count($x['tenants']) === 1) { $x['tenant_id'] = $x['tenants'][0]['id']; $x['tenant_name'] = $x['tenants'][0]['name']; }
    else unset($x['tenant_id'], $x['tenant_name']);
    $x['connected_at'] = date('Y-m-d H:i:s');
    return $x;
}

function cm_xero_store_tokens(array $x, array $j): array
{
    $x['access_token'] = (string)$j['access_token'];
    if (!empty($j['scope'])) $x['scope'] = (string)$j['scope'];
    if (!empty($j['refresh_token'])) $x['refresh_token'] = (string)$j['refresh_token'];   // Xero rotates the refresh token on every use
    $x['expires_at'] = time() + max(60, (int)($j['expires_in'] ?? 1800)) - 60;
    return $x;
}

/** A valid access token (refreshing and saving the rotated refresh token when needed). */
function cm_xero_token(array &$x): string
{
    if (!empty($x['access_token']) && (int)($x['expires_at'] ?? 0) > time()) return (string)$x['access_token'];
    [$st, $j] = cm_xero_http('POST', cm_xero_endpoints()['token'], [cm_xero_basic($x), 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => (string)($x['refresh_token'] ?? '')]));
    if ($st !== 200 || empty($j['access_token'])) {
        if (($j['error'] ?? '') === 'invalid_grant') { unset($x['access_token'], $x['refresh_token']); cm_xero_save($x); throw new RuntimeException('The Xero connection has expired (not used for 60 days, or disconnected in Xero). Connect again on More › Xero connection.'); }
        throw new RuntimeException('Xero did not renew the connection (' . ($j['error'] ?? $st) . '). Nothing was created.');
    }
    $x = cm_xero_store_tokens($x, $j); cm_xero_save($x);
    return (string)$x['access_token'];
}

function cm_xero_api(array &$x, string $method, string $path, ?array $body = null): array
{
    $h = ['Authorization: Bearer ' . cm_xero_token($x), 'xero-tenant-id: ' . $x['tenant_id'], 'Accept: application/json'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    return cm_xero_http($method, cm_xero_endpoints()['api'] . $path, $h, $body !== null ? json_encode($body) : null);
}

/** The Xero contact to invoice: ['ContactID' => …] or (Demo Company only) ['Name' => …]. */
function cm_xero_contact(array &$x, array $client): array
{
    $ref = trim((string)($client['xero_contact_id'] ?? ''));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref)) return ['ContactID' => strtolower($ref)];
    $name = trim((string)$client['name']);
    [$st, $j] = cm_xero_api($x, 'GET', '/Contacts?where=' . rawurlencode('Name=="' . str_replace('"', '\"', $name) . '"') . '&summaryOnly=true');
    if ($st !== 200) throw new RuntimeException('Could not look up the contact in Xero (' . $st . '). Nothing was created.');
    $found = array_values(array_filter($j['Contacts'] ?? [], fn($c) => ($c['ContactStatus'] ?? 'ACTIVE') !== 'ARCHIVED'));
    if (count($found) === 1) return ['ContactID' => (string)$found[0]['ContactID']];
    if (count($found) > 1) throw new RuntimeException('More than one Xero contact is called “' . $name . '”. Put the right ContactID in the client’s Xero contact reference.');
    if (cm_xero_is_demo($x)) return ['Name' => $name];
    throw new RuntimeException('No Xero contact is called “' . $name . '”. Put its Xero ContactID in the client’s Xero contact reference (Edit client › Notes, credit & Xero reference), or rename to match. Nothing was created.');
}

/**
 * Create ONE draft sales invoice. $lines: [['description','amount' (ex GST),'account']].
 * Returns ['id' => InvoiceID, 'number' => InvoiceNumber, 'url' => link to open it in Xero].
 */
function cm_xero_create_draft(array &$x, array $client, array $lines, string $dueDate, string $reference = '', string $taxType = 'OUTPUT2'): array
{
    if (cm_xero_state($x) !== 'connected') throw new RuntimeException('Xero is not connected. Set it up on More › Xero connection.');
    $inv = ['Type' => 'ACCREC', 'Status' => 'DRAFT', 'LineAmountTypes' => 'Exclusive', 'Contact' => cm_xero_contact($x, $client),
        'Date' => date('Y-m-d'), 'DueDate' => $dueDate, 'Reference' => mb_substr($reference, 0, 255),
        // v0.17.1: the tax rate is always sent (a draft whose account Xero doesn't know otherwise gets no GST at all)
        'LineItems' => array_map(fn($l) => array_filter(['ItemCode' => (string)($l['item_code'] ?? ''), 'Description' => function_exists('cm_desc_clean') ? cm_desc_clean((string)$l['description']) : (string)$l['description'], 'Quantity' => 1, 'UnitAmount' => round((float)$l['amount'], 2), 'AccountCode' => (string)($l['account'] ?? ''), 'TaxType' => $taxType], fn($v) => $v !== ''), $lines)];
    if ($inv['Status'] !== 'DRAFT') throw new LogicException('Only drafts may be created.');
    [$st, $j, $raw] = cm_xero_api($x, 'POST', '/Invoices?summarizeErrors=true', ['Invoices' => [$inv]]);
    $out = $j['Invoices'][0] ?? null;
    if ($st !== 200 || !$out || empty($out['InvoiceID'])) {
        $msg = $j['Elements'][0]['ValidationErrors'][0]['Message'] ?? ($j['Message'] ?? ('HTTP ' . $st));
        throw new RuntimeException('Xero did not create the draft: ' . $msg . '. Nothing was recorded.');
    }
    if (strtoupper((string)($out['Status'] ?? 'DRAFT')) !== 'DRAFT') throw new RuntimeException('Xero returned the invoice with status ' . $out['Status'] . ' — check it in Xero.');
    // v0.17.1: Xero silently drops an account code it doesn't know on a draft — say so instead of leaving it unnoticed.
    $warn = [];
    foreach ($inv['LineItems'] as $i => $sent) {
        $got = $out['LineItems'][$i] ?? null;
        if (!is_array($got)) continue;
        if (!empty($sent['AccountCode']) && (string)($got['AccountCode'] ?? '') !== $sent['AccountCode']) $warn[] = 'Xero dropped account ' . $sent['AccountCode'] . ' on line ' . ($i + 1) . ' — set the account on the draft in Xero.';
        if (!empty($sent['TaxType']) && (string)($got['TaxType'] ?? '') !== $sent['TaxType']) $warn[] = 'Xero did not apply tax type ' . $sent['TaxType'] . ' on line ' . ($i + 1) . ' — check GST on the draft.';
    }
    return ['id' => (string)$out['InvoiceID'], 'number' => (string)($out['InvoiceNumber'] ?? ''), 'warnings' => array_values(array_unique($warn)), 'url' => 'https://go.xero.com/AccountsReceivable/Edit.aspx?InvoiceID=' . rawurlencode((string)$out['InvoiceID'])];
}


/* ---------------- v0.18: contacts sync, accounts and tax rates ---------------- */

/** The client's Xero ContactID if one is linked (a GUID in xero_contact_id), else ''. */
function cm_client_xero_id(array $c): string
{
    $r = strtolower(trim((string)($c['xero_contact_id'] ?? '')));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $r) ? $r : '';
}

/** Name for matching: lower case, no punctuation, "&" = "and", without Ltd/Limited/The. */
function cm_norm_name(string $n): string
{
    $n = mb_strtolower(trim($n));
    $n = str_replace('&', ' and ', $n);
    $n = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $n);
    $n = preg_replace('/\b(ltd|limited|the|nz|co)\b/u', ' ', $n);
    return trim(preg_replace('/\s+/', ' ', $n));
}

/** All Xero contacts (not archived), paged 100 at a time. */
function cm_xero_contacts_all(array &$x): array
{
    $all = [];
    for ($page = 1; $page <= 50; $page++) {
        [$st, $j] = cm_xero_api($x, 'GET', '/Contacts?page=' . $page . '&includeArchived=false');
        if ($st !== 200) throw new RuntimeException('Could not read contacts from Xero (' . $st . ').');
        $batch = $j['Contacts'] ?? [];
        foreach ($batch as $c) $all[] = ['id' => strtolower((string)$c['ContactID']), 'name' => (string)($c['Name'] ?? ''), 'email' => (string)($c['EmailAddress'] ?? ''), 'number' => (string)($c['ContactNumber'] ?? ''), 'status' => (string)($c['ContactStatus'] ?? 'ACTIVE'), 'customer' => !empty($c['IsCustomer'])];
        if (count($batch) < 100) break;
    }
    return $all;
}

/** Xero's contact fields from a Workdesk client (only what Workdesk has). */
function cm_xero_contact_body(array $c): array
{
    $b = ['Name' => trim((string)$c['name'])];
    if (filter_var(trim((string)($c['email'] ?? '')), FILTER_VALIDATE_EMAIL)) $b['EmailAddress'] = trim((string)$c['email']);
    if (trim((string)($c['phone'] ?? '')) !== '') $b['Phones'] = [['PhoneType' => 'DEFAULT', 'PhoneNumber' => trim((string)$c['phone'])]];
    $addr = array_filter(['AddressType' => 'POBOX', 'AddressLine1' => trim((string)($c['postal_address_1'] ?? '')), 'AddressLine2' => trim((string)($c['postal_address_2'] ?? '')), 'AddressLine3' => trim((string)($c['postal_address_3'] ?? '')), 'City' => trim((string)($c['postal_city_town'] ?? '')), 'Region' => trim((string)($c['postal_region'] ?? '')), 'PostalCode' => trim((string)($c['postal_code'] ?? '')), 'Country' => trim((string)($c['postal_country'] ?? ''))], fn($v) => $v !== '');
    if (count($addr) > 1) $b['Addresses'] = [$addr];
    return $b;
}

/** Create (or, with $contactId, update name/email/phone/address of) a Xero contact. Returns the ContactID. Never deletes or archives. */
function cm_xero_save_contact(array &$x, array $client, string $contactId = ''): string
{
    $body = cm_xero_contact_body($client);
    if ($body['Name'] === '') throw new RuntimeException('The client has no name.');
    if ($contactId !== '') $body['ContactID'] = $contactId;
    [$st, $j] = cm_xero_api($x, 'POST', '/Contacts?summarizeErrors=true', ['Contacts' => [$body]]);
    $out = $j['Contacts'][0] ?? null;
    if ($st === 403) throw new RuntimeException('Xero did not allow changing contacts — reconnect Xero (More › Xero connection) so Workdesk gets the contacts permission.');
    if ($st !== 200 || !$out || empty($out['ContactID'])) throw new RuntimeException('Xero did not save the contact “' . $body['Name'] . '”: ' . ($j['Elements'][0]['ValidationErrors'][0]['Message'] ?? ($j['Message'] ?? ('HTTP ' . $st))));
    return strtolower((string)$out['ContactID']);
}

/** Xero chart of accounts and tax rates (for the check on Xero sync). */
function cm_xero_accounts(array &$x): array
{
    [$st, $j] = cm_xero_api($x, 'GET', '/Accounts');
    if ($st !== 200) throw new RuntimeException('Could not read accounts from Xero (' . $st . ').');
    $o = []; foreach ($j['Accounts'] ?? [] as $a) if (($a['Code'] ?? '') !== '') $o[(string)$a['Code']] = ['name' => (string)($a['Name'] ?? ''), 'status' => (string)($a['Status'] ?? ''), 'tax' => (string)($a['TaxType'] ?? '')];
    return $o;
}
function cm_xero_taxrates(array &$x): array
{
    [$st, $j] = cm_xero_api($x, 'GET', '/TaxRates');
    if ($st !== 200) throw new RuntimeException('Could not read tax rates from Xero (' . $st . ').');
    $o = []; foreach ($j['TaxRates'] ?? [] as $t) $o[(string)$t['TaxType']] = ['name' => (string)($t['Name'] ?? ''), 'status' => (string)($t['Status'] ?? ''), 'rate' => (float)($t['EffectiveRate'] ?? 0)];
    return $o;
}

/* ---------------- v0.18: tax by country (kept simple — overseas clients are rare) ---------------- */

/** The client's country if it is clearly outside New Zealand (postal country first, else physical), else ''. Blank / NZ / a town → NZ. */
function cm_client_overseas(array $c): string
{
    foreach (['postal_country', 'physical_country'] as $f) {
        $v = trim((string)($c[$f] ?? ''));
        if ($v === '') continue;
        $n = mb_strtolower($v);
        if (preg_match('/^(new zealand|nz|aotearoa|aotearoa new zealand)$/u', $n)) return '';
        foreach (CM_OVERSEAS_COUNTRIES as $k) if ($n === $k) return $v;
        return '';   // not recognised as another country (e.g. a town or postcode typed in the country box) → NZ
    }
    return '';
}
const CM_OVERSEAS_COUNTRIES = ['united kingdom', 'uk', 'england', 'scotland', 'wales', 'northern ireland', 'great britain', 'gb', 'ireland', 'australia', 'united states', 'usa', 'us', 'canada', 'netherlands', 'nederland', 'nederlands', 'the netherlands', 'germany', 'france', 'italy', 'spain', 'switzerland', 'sweden', 'norway', 'denmark', 'singapore', 'hong kong', 'japan', 'china', 'fiji', 'cook islands', 'samoa', 'tonga'];

/** The Xero tax type for a client's invoice lines: overseas → Billing settings "overseas" type, else the default (15% GST on Income). */
function cm_client_tax_type(PDO $pdo, array $c): string
{
    return cm_client_overseas($c) !== '' ? (cm_setting($pdo, 'xero_tax_type_overseas') ?: 'ZERORATEDOUTPUT') : (cm_setting($pdo, 'xero_tax_type') ?: 'OUTPUT2');
}

/* ---------------- v0.22: read invoices from Xero (read-only) ---------------- */

/** Xero's date ("/Date(1762300800000+0000)/" or ISO) as Y-m-d. */
function cm_xero_date($v): string
{
    $v = (string)$v;
    if (preg_match('~/Date\((-?\d+)~', $v, $m)) return gmdate('Y-m-d', intdiv((int)$m[1], 1000));
    return substr($v, 0, 10);
}

/**
 * Sales invoices from Xero, newest first. $statuses e.g. ['AUTHORISED'] (awaiting payment), ['PAID'], [] = all except deleted/voided.
 * $contactId limits to one contact. At most $maxPages × 100 invoices. Each: id, number, contact_id, contact, date, due, total, paid, due_amount, status, reference, url.
 */
function cm_xero_invoices(array &$x, array $statuses = [], string $contactId = '', int $maxPages = 10): array
{
    $q = ['where' => 'Type=="ACCREC"', 'order' => 'Date DESC', 'summaryOnly' => 'true'];
    $q['Statuses'] = implode(',', $statuses ?: ['DRAFT', 'SUBMITTED', 'AUTHORISED', 'PAID']);
    if ($contactId !== '') $q['ContactIDs'] = $contactId;
    $out = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        [$st, $j] = cm_xero_api($x, 'GET', '/Invoices?' . http_build_query($q + ['page' => $page]));
        if ($st !== 200) throw new RuntimeException('Could not read invoices from Xero (' . $st . ').');
        $batch = $j['Invoices'] ?? [];
        foreach ($batch as $i) $out[] = ['id' => (string)($i['InvoiceID'] ?? ''), 'number' => (string)($i['InvoiceNumber'] ?? ''), 'contact_id' => strtolower((string)($i['Contact']['ContactID'] ?? '')), 'contact' => (string)($i['Contact']['Name'] ?? ''),
            'date' => cm_xero_date($i['DateString'] ?? $i['Date'] ?? ''), 'due' => cm_xero_date($i['DueDateString'] ?? $i['DueDate'] ?? ''), 'total' => (float)($i['Total'] ?? 0), 'paid' => (float)($i['AmountPaid'] ?? 0),
            'due_amount' => (float)($i['AmountDue'] ?? 0), 'status' => (string)($i['Status'] ?? ''), 'reference' => (string)($i['Reference'] ?? ''),
            'url' => 'https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=' . rawurlencode((string)($i['InvoiceID'] ?? ''))];
        if (count($batch) < 100) break;
    }
    return $out;
}
