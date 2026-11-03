<?php
declare(strict_types=1);
/*
 * A stand-in for Xero's sign-in, token and Accounting API — for the automated tests only (never deployed).
 *   php -S localhost:8099 tests/xero-mock.php     (XERO_MOCK_LOG=<file> records every request as JSON lines)
 * Behaves like Xero for the calls Workdesk makes: authorize → redirect with code; token (authorization_code /
 * refresh_token, rotating refresh tokens, Basic auth checked); connections; Contacts lookup; Invoices POST
 * (refuses anything but DRAFT, returns InvoiceID + InvoiceNumber); revocation.
 * Special contact name "Test Xero Fail" makes the invoice POST fail with a validation error.
 */
const CLIENT_ID = 'MOCKCLIENTID0000000000000000ABCD';
const CLIENT_SECRET = 'mock-secret-0123456789abcdef';
$log = getenv('XERO_MOCK_LOG') ?: sys_get_temp_dir() . '/xero-mock.log';
$state = $log . '.state.json';
$S = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
$save = function () use (&$S, $state) { file_put_contents($state, json_encode($S)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$hdr = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($log, json_encode(['m' => $_SERVER['REQUEST_METHOD'], 'p' => $path, 'q' => $_GET, 'b' => $body, 'tenant' => $hdr['xero-tenant-id'] ?? ($hdr['Xero-Tenant-Id'] ?? null), 'auth' => $hdr['Authorization'] ?? null]) . "\n", FILE_APPEND);
$json = function ($code, $data) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
$basicOk = fn() => ($hdr['Authorization'] ?? '') === 'Basic ' . base64_encode(CLIENT_ID . ':' . CLIENT_SECRET);
$bearerOk = fn() => isset($S['access']) && ($hdr['Authorization'] ?? '') === 'Bearer ' . $S['access'];

switch ($path) {
    case '/authorize':
        if (($_GET['client_id'] ?? '') !== CLIENT_ID) { http_response_code(400); exit('unknown client'); }
        foreach (['offline_access', 'accounting.invoices'] as $sc) if (!str_contains((string)($_GET['scope'] ?? ''), $sc)) { http_response_code(400); exit('missing scope ' . $sc); }
        $S['code'] = 'code-' . bin2hex(random_bytes(6)); $S['redirect'] = $_GET['redirect_uri']; $save();
        header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => $S['code'], 'state' => $_GET['state'] ?? '', 'scope' => $_GET['scope'] ?? '']), true, 302); exit;
    case '/token':
        if (!$basicOk()) $json(401, ['error' => 'invalid_client']);
        parse_str($body, $f);
        if (($f['grant_type'] ?? '') === 'authorization_code') {
            if (($f['code'] ?? '') !== ($S['code'] ?? null) || ($f['redirect_uri'] ?? '') !== ($S['redirect'] ?? null)) $json(400, ['error' => 'invalid_grant']);
            unset($S['code']);
        } elseif (($f['grant_type'] ?? '') === 'refresh_token') {
            if (($f['refresh_token'] ?? '') !== ($S['refresh'] ?? null)) $json(400, ['error' => 'invalid_grant']);
        } else $json(400, ['error' => 'unsupported_grant_type']);
        $S['access'] = 'acc-' . bin2hex(random_bytes(8)); $S['refresh'] = 'ref-' . bin2hex(random_bytes(8)); $S['refreshes'] = ($S['refreshes'] ?? 0) + (($f['grant_type'] ?? '') === 'refresh_token' ? 1 : 0); $save();
        $json(200, ['access_token' => $S['access'], 'refresh_token' => $S['refresh'], 'expires_in' => (int)($S['expires_in'] ?? 1800), 'token_type' => 'Bearer']);
    case '/connections':
        if (!$bearerOk()) $json(401, ['Title' => 'Unauthorized']);
        $json(200, [['id' => 'c1', 'tenantId' => '11111111-2222-3333-4444-555555555555', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Demo Company (NZ)']]);
    case '/revoke':
        unset($S['access'], $S['refresh']); $save(); http_response_code(200); exit;
    case '/api/Contacts':
        if (!$bearerOk()) $json(401, ['Title' => 'Unauthorized']);
        $json(200, ['Contacts' => []]);
    case '/api/Invoices':
        if (!$bearerOk()) $json(401, ['Title' => 'Unauthorized']);
        $in = json_decode($body, true)['Invoices'][0] ?? [];
        if (($in['Status'] ?? '') !== 'DRAFT' || ($in['Type'] ?? '') !== 'ACCREC') $json(400, ['Message' => 'mock only accepts DRAFT ACCREC']);
        if (($in['Contact']['Name'] ?? '') === 'Test Xero Fail') $json(400, ['Elements' => [['ValidationErrors' => [['Message' => 'Account code \'99999\' is not a valid code']]]]]);
        $S['n'] = ($S['n'] ?? 9000) + 1; $save();
        $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        $json(200, ['Invoices' => [['InvoiceID' => $id, 'InvoiceNumber' => 'INV-' . $S['n'], 'Status' => 'DRAFT', 'Type' => 'ACCREC']]]);
}
http_response_code(404); echo 'mock: no route ' . $path;
