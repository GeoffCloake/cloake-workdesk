<?php
// Unit check for the Xero line descriptions (no database needed). Prints OK or the failures.
declare(strict_types=1);
function cm_dmy(string $d): string { return (new DateTimeImmutable($d))->format('d/m/Y'); }
$src = file_get_contents(__DIR__ . '/../public/inc/billing-lib.php');
preg_match('~/\*\* Suggested Xero line description.*?(?=/\*\* The billable hours)~s', $src, $m);
eval('?><?php ' . $m[0]);
$bad = [];
$chk = function (string $name, array $s, string $want) use (&$bad) {
    $got = cm_service_description($s + ['label' => '', 'domain' => '', 'cycle_months' => 6], '2026-11-20', '2027-05-19');
    if ($got !== $want) $bad[] = "$name: got «{$got}»";
};
$chk('hosting', ['kind' => 'hosting', 'domain' => 'example.nz', 'plan_name' => 'CMS with PHP 5.6.20'], 'Website hosting – example.nz – 20/11/2026 to 19/05/2027 (6 months)');
$chk('hosting+', ['kind' => 'hosting', 'domain' => 'example.nz', 'included_domains' => ['example.co.nz', 'ex.nz'], 'mailboxes' => 3], 'Website hosting – example.nz – 20/11/2026 to 19/05/2027 (6 months). Includes example.co.nz, ex.nz and 3 mailboxes');
$chk('no mailboxes', ['kind' => 'hosting', 'domain' => 'example.nz', 'mailboxes' => 0], 'Website hosting – example.nz – 20/11/2026 to 19/05/2027 (6 months)');
$chk('email', ['kind' => 'email', 'domain' => 'example.nz', 'cycle_months' => 12, 'mailboxes' => 1], 'Email hosting – example.nz – 1 mailbox – 20/11/2026 to 19/05/2027 (1 year)');
$chk('domain', ['kind' => 'domain', 'domain' => 'example.nz', 'cycle_months' => 12], 'Domain renewal – example.nz – 20/11/2026 to 19/05/2027 (1 year)');
$chk('dns', ['kind' => 'dns', 'domain' => 'example.nz'], 'DNS and forwarding – example.nz – 20/11/2026 to 19/05/2027 (6 months)');
$chk('monthly', ['kind' => 'hosting', 'domain' => 'example.nz', 'cycle_months' => 1], 'Website hosting – example.nz – 20/11/2026 to 19/05/2027 (1 month)');
$d = cm_service_description(['kind' => 'hosting', 'domain' => '', 'label' => 'shop https://shop.example.nz/wp-login user: bob pass: Secret22 bob@example.nz', 'cycle_months' => 6], '2026-11-20', '2027-05-19');
if (preg_match('~@|https?:|Secret22|\bbob\b|pass~i', $d)) $bad[] = "credentials kept: «{$d}»";
echo $bad ? implode("\n", $bad) . "\n" : "OK\n";
