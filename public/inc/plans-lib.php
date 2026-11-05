<?php
declare(strict_types=1);
/*
 * Service plans (v0.19): the plan list, list prices, legacy-price flags, proposed matches for existing services,
 * and the Xero items that mirror the plans.
 *
 *  - A plan's price is TODAY's price for a new client (ex GST, per price_months). A service keeps its own price
 *    (what that client pays); a service below the list price is flagged "legacy price". Nothing changes prices.
 *  - Hosting list price = plan price for the service's cycle + mailboxes × the MAILBOX plan price (per month).
 *  - Each plan can be created in Xero as an item (Code = plan code). Workdesk → Xero only on a click; never deletes.
 *    Items use the accounting.invoices permission Workdesk already has.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_PLAN_KINDS = ['hosting' => 'Hosting', 'mailbox' => 'Mailbox (add-on)', 'email' => 'Email', 'domain' => 'Domain', 'dns' => 'DNS & forwarding', 'maintenance' => 'Maintenance'];
const CM_PLAN_PERIODS = ['1' => 'per month', '3' => 'per quarter', '6' => 'per 6 months', '12' => 'per year', '24' => 'per 2 years'];
/** Older prices still charged to existing clients, used only to recognise a service's plan when matching (ex GST, per 6 months). */
const CM_PLAN_LEGACY_BASES = ['HOST-STD' => [201.00]];

function cm_has_plans(PDO $pdo): bool
{
    static $has = null;
    return $has ??= (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='plan_id'");
}

/** All plans (or only active ones), keyed by id. */
function cm_plans(PDO $pdo, bool $activeOnly = false): array
{
    if (!cm_has_plans($pdo)) return [];
    $o = [];
    foreach (allRows($pdo, 'SELECT * FROM plans' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY (sort_order IS NULL), sort_order, code') as $p) $o[(int)$p['id']] = $p;
    return $o;
}

/** The mailbox add-on price per month ex GST (0 when there is no active MAILBOX plan). */
function cm_mailbox_month_price(array $plans): float
{
    foreach ($plans as $p) if ($p['kind'] === 'mailbox' && $p['active'] && $p['price_ex_gst'] !== null) return (float)$p['price_ex_gst'] / max(1, (int)$p['price_months']);
    return 0.0;
}

/** Plan price scaled to $months (null when the plan has no set price). */
function cm_plan_price_for(array $plan, int $months): ?float
{
    if ($plan['price_ex_gst'] === null || $months <= 0) return null;
    return round((float)$plan['price_ex_gst'] * $months / max(1, (int)$plan['price_months']), 2);
}

/** Today's list price for this service (its plan at its cycle, plus mailboxes on hosting/email), or null. */
function cm_service_list_price(array $s, array $plans): ?float
{
    $p = $plans[(int)($s['plan_id'] ?? 0)] ?? null;
    $m = (int)($s['cycle_months'] ?? 0);
    if (!$p || $m <= 0) return null;
    $mb = (int)($s['mailboxes'] ?? 0) * cm_mailbox_month_price($plans) * $m;
    if ($p['kind'] === 'mailbox') return round(max(1, (int)($s['mailboxes'] ?? 0)) * cm_mailbox_month_price($plans) * $m, 2);
    $base = cm_plan_price_for($p, $m);
    return $base === null ? null : round($base + ($s['kind'] === 'hosting' ? $mb : 0), 2);
}

/** 'list' (at list price) | 'legacy' (below — an older price) | 'above' | '' (no plan, no price, or not billed). */
function cm_price_status(array $s, array $plans): string
{
    if (empty($s['billable']) || !empty($s['is_internal']) || $s['price_ex_gst'] === null) return '';
    $lp = cm_service_list_price($s, $plans);
    if ($lp === null) return '';
    $d = (float)$s['price_ex_gst'] - $lp;
    return abs($d) < 0.01 ? 'list' : ($d < 0 ? 'legacy' : 'above');
}

/**
 * The proposed plan for a service that has none: ['plan_id' => ?int, 'mailboxes' => ?int, 'why' => text, 'sure' => bool].
 * Hosting: price per 6 months = a plan's price (or a known older price) + whole mailboxes; Advanced is never proposed.
 * Domain / DNS / email are matched by kind. Anything else is left for you to choose.
 */
function cm_plan_proposal(array $s, array $plans): array
{
    $byCode = []; foreach ($plans as $p) if ($p['active']) $byCode[$p['code']] = $p;
    $none = fn(string $why) => ['plan_id' => null, 'mailboxes' => null, 'why' => $why, 'sure' => false];
    $kindPlan = function (string $kind) use ($byCode) { foreach ($byCode as $p) if ($p['kind'] === $kind) return $p; return null; };
    $m = (int)($s['cycle_months'] ?? 0); $price = $s['price_ex_gst'] !== null ? (float)$s['price_ex_gst'] : null;
    $mbMonth = cm_mailbox_month_price($plans);
    switch ($s['kind']) {
        case 'domain': $p = $kindPlan('domain'); return $p ? ['plan_id' => (int)$p['id'], 'mailboxes' => null, 'why' => 'domain', 'sure' => true] : $none('no domain plan');
        case 'dns': $p = $kindPlan('dns'); return $p ? ['plan_id' => (int)$p['id'], 'mailboxes' => null, 'why' => 'DNS and forwarding', 'sure' => true] : $none('no DNS plan');
        case 'email':
            $p = $kindPlan('mailbox');
            if (!$p) return $none('no mailbox plan');
            if ($price !== null && $m > 0 && $mbMonth > 0) { $n = $price / ($mbMonth * $m); if ($n >= 0.99 && abs($n - round($n)) < 0.01) return ['plan_id' => (int)$p['id'], 'mailboxes' => (int)round($n), 'why' => 'email only: ' . (int)round($n) . ' × mailbox', 'sure' => true]; }
            return ['plan_id' => (int)$p['id'], 'mailboxes' => null, 'why' => 'email only — check the number of mailboxes', 'sure' => false];
        case 'hosting':
            if ($price === null || $m <= 0) return $none('no price or cycle');
            $p6 = $price * 6 / $m; $mb6 = $mbMonth * 6;
            $hits = [];
            foreach ($byCode as $code => $p) {
                if ($p['kind'] !== 'hosting' || $code === 'HOST-ADV' || $p['price_ex_gst'] === null) continue;
                $bases = array_merge([(float)cm_plan_price_for($p, 6)], CM_PLAN_LEGACY_BASES[$code] ?? []);
                foreach ($bases as $i => $b) {
                    $rest = $p6 - $b;
                    if ($rest < -0.01) continue;
                    $n = $mb6 > 0 ? $rest / $mb6 : ($rest < 0.01 ? 0 : -1);
                    if ($n >= -0.001 && abs($n - round($n)) < 0.005 && round($n) <= 20) $hits[] = ['plan' => $p, 'n' => (int)round($n), 'legacy' => $i > 0, 'b' => $b];
                }
            }
            usort($hits, fn($a, $b) => $a['n'] <=> $b['n']);
            if (!$hits) return $none(sprintf('$%.2f per 6 months matches no plan — choose one (or Advanced)', $p6));
            $h = $hits[0];
            $why = $h['plan']['name'] . ($h['legacy'] ? sprintf(' at the older price $%.2f', $h['b']) : '') . ($h['n'] ? ' + ' . $h['n'] . ' mailbox' . ($h['n'] === 1 ? '' : 'es') : '');
            $sure = count($hits) === 1 || $hits[1]['n'] !== $h['n'];
            return ['plan_id' => (int)$h['plan']['id'], 'mailboxes' => $h['n'], 'why' => $why . ($sure ? '' : ' (another plan also fits — check)'), 'sure' => $sure];
        default: return $none('choose a plan');
    }
}

/* ---------------- Xero items ---------------- */

/** The Xero item body for a plan (Workdesk → Xero). */
function cm_plan_item_body(PDO $pdo, array $p): array
{
    $acct = $p['xero_account'] ?: cm_account_for($pdo, $p['kind'] === 'domain' ? 'domain' : 'hosting');
    $sales = array_filter(['AccountCode' => (string)$acct, 'TaxType' => cm_setting($pdo, 'xero_tax_type') ?: 'OUTPUT2'], fn($v) => $v !== '');
    if ($p['price_ex_gst'] !== null) $sales = ['UnitPrice' => round((float)$p['price_ex_gst'], 2)] + $sales;
    return ['Code' => (string)$p['code'], 'Name' => mb_substr((string)$p['name'], 0, 50), 'Description' => (string)$p['name'] . ' (' . (CM_PLAN_PERIODS[(string)$p['price_months']] ?? '') . ')', 'IsSold' => true, 'IsPurchased' => false, 'SalesDetails' => $sales];
}

/** All Xero items, keyed by upper-case Code. */
function cm_xero_items_all(array &$x): array
{
    [$st, $j] = cm_xero_api($x, 'GET', '/Items');
    if ($st === 403) throw new RuntimeException('Xero did not allow reading items — reconnect Xero (More › Xero connection).');
    if ($st !== 200) throw new RuntimeException('Could not read items from Xero (' . $st . ').');
    $o = [];
    foreach ($j['Items'] ?? [] as $i) $o[strtoupper((string)($i['Code'] ?? ''))] = ['id' => strtolower((string)($i['ItemID'] ?? '')), 'code' => (string)($i['Code'] ?? ''), 'name' => (string)($i['Name'] ?? ''), 'price' => isset($i['SalesDetails']['UnitPrice']) ? (float)$i['SalesDetails']['UnitPrice'] : null, 'account' => (string)($i['SalesDetails']['AccountCode'] ?? ''), 'sold' => !empty($i['IsSold'])];
    return $o;
}

/** What differs between a plan and its Xero item ([] = in sync). */
function cm_plan_item_diff(PDO $pdo, array $p, array $item): array
{
    $b = cm_plan_item_body($pdo, $p); $d = [];
    if ($item['name'] !== $b['Name']) $d[] = 'name';
    $want = $b['SalesDetails']['UnitPrice'] ?? null;
    if (($want === null) !== ($item['price'] === null) || ($want !== null && abs($want - (float)$item['price']) >= 0.005)) $d[] = 'price';
    if (($b['SalesDetails']['AccountCode'] ?? '') !== $item['account']) $d[] = 'account';
    return $d;
}

/** Create or update the Xero item for a plan. Returns the ItemID. Never deletes. */
function cm_xero_save_item(array &$x, PDO $pdo, array $p, string $itemId = ''): string
{
    $body = cm_plan_item_body($pdo, $p);
    if ($itemId !== '') $body['ItemID'] = $itemId;
    [$st, $j] = cm_xero_api($x, 'POST', '/Items?summarizeErrors=true', ['Items' => [$body]]);
    $out = $j['Items'][0] ?? null;
    if ($st === 403) throw new RuntimeException('Xero did not allow changing items — reconnect Xero (More › Xero connection).');
    if ($st !== 200 || !$out || empty($out['ItemID'])) throw new RuntimeException('Xero did not save the item ' . $body['Code'] . ': ' . ($j['Elements'][0]['ValidationErrors'][0]['Message'] ?? ($j['Message'] ?? ('HTTP ' . $st))));
    return strtolower((string)$out['ItemID']);
}
