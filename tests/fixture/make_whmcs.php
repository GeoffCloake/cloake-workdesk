<?php
declare(strict_types=1);
/*
 * Synthetic WHMCS import folder for the tests (FICTIONAL data, dates relative to today).
 *   php make_whmcs.php <out-dir>
 * Same files as tools/prepare-whmcs-import.py produces, but the WHMCS exports deliberately still carry a
 * Password column and a note line with a password, to prove deploy/import-whmcs.php drops them on read.
 * Fixture clients: 2 Harbourview Motel, 3 Kowhai Physio, 1 = Cloake's own (internal).
 */
date_default_timezone_set('Pacific/Auckland');
$out = $argv[1] ?? ''; if ($out === '') { fwrite(STDERR, "usage: php make_whmcs.php <out-dir>\n"); exit(2); }
@mkdir($out, 0700, true);
$d = fn(int $days) => (new DateTimeImmutable('today'))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$w = function (string $f, array $rows, bool $title = false) use ($out) { $fh = fopen("$out/$f", 'w'); if ($title) fputcsv($fh, [ucfirst(pathinfo($f, PATHINFO_FILENAME))], ',', '"', ''); foreach ($rows as $r) fputcsv($fh, $r, ',', '"', ''); fclose($fh); };
$w('clients.csv', [
    ['ID', 'First Name', 'Last Name', 'Company Name', 'Email', 'City', 'Status', 'Notes'],
    ['901', 'Hana', 'Fixture', 'Harbourview Motel', 'office@harbourviewmotel.example.nz', 'Twizel', 'Active', 'Fictional'],
    ['902', 'Kev', 'Fixture', 'Kowhai Physio', 'office@kowhaiphysio.example.nz', 'Geraldine', 'Active', ''],
    ['903', 'Nell', 'Newby', 'Fictional New Client Ltd', 'nell@newclient.example.nz', 'Timaru', 'Active', 'login: nell / Password: letmein'],
    ['904', 'Test', 'Account', 'Test company', 'test@example.nz', '', 'Inactive', ''],
    ['905', 'Cloake', 'Creative', 'Cloake Creative', 'info@cloake.example.nz', 'Timaru', 'Active', ''],
], true);
$w('services.csv', [
    ['ID', 'User ID', 'Client Name', 'Product ID', 'Registration Date', 'Domain Name', 'Recurring Amount', 'Billing Cycle', 'Next Due Date', 'Termination Date', 'Status', 'Username', 'Password', 'Notes'],
    ['1', '901', 'Harbourview Motel', '4', '2019-05-01', 'harbourviewmotel.example.nz', '201.00', 'Semi-Annually', $d(-200), '', 'Active', 'harbourv', 'hunter2-secret', "Moved to new server 2024\nFTP password: p4ss-w0rd\nRenewal by email"],
    ['2', '901', 'Harbourview Motel', '7', '2020-01-10', 'harbourviewmotel.example.nz', '60.00', 'Annually', $d(30), '', 'Active', '', 'x1', 'Email only mailbox'],
    ['3', '902', 'Kowhai Physio', '34', '2021-03-15', 'kowhaiphysio.example.nz', '299.70', 'Semi-Annually', $d(120), '', 'Active', 'kowhai', 'x2', ''],
    ['4', '902', 'Kowhai Physio', '4', '2015-03-15', 'oldkowhai.example.nz', '135.00', 'Semi-Annually', '2019-01-01', '2019-02-01', 'Terminated', 'oldk', 'x3', ''],
    ['5', '905', 'Cloake Creative', '1', '2012-01-01', 'cloake.example.nz', '0.00', 'Free Account', '', '', 'Active', 'cloake', 'x4', ''],
    ['6', '904', 'Test company', '1', '2022-01-01', 'test.example.nz', '0.00', 'Free Account', '', '', 'Active', 'test', 'x5', ''],
    ['7', '903', 'Fictional New Client Ltd', '4', $d(-10), 'newclient.example.nz', '201.00', 'Semi-Annually', $d(5), '', 'Pending', 'newcl', 'x6', ''],
], true);
$w('domains.csv', [
    ['ID', 'User ID', 'Client Name', 'Domain Name', 'Recurring Amount', 'Registration Period', 'Registration Date', 'Expiry Date', 'Next Due Date', 'Registrar', 'Status', 'Notes'],
    ['11', '901', 'Harbourview Motel', 'harbourviewmotel.example.nz', '49.95', '1', '2019-05-01', $d(40), $d(8), 'Domainreseller', 'Active', ''],
    ['12', '902', 'Kowhai Physio', 'oldkowhai.example.nz', '49.95', '1', '2015-03-15', '2020-03-15', '2020-02-12', 'Domainreseller', 'Expired', ''],
], true);
$w('client-map.csv', [['whmcs_client_id', 'action', 'manager_client_id', 'new_client_name', 'contact_id'],
    ['901', 'map', '2', '', ''], ['902', 'map', '3', '', ''], ['903', 'new', '', 'Fictional New Client Ltd', ''], ['904', 'skip', '', '', ''], ['905', 'internal', '1', '', '']]);
$p1 = $d(-20);   // S1: last Xero period ended yesterday-ish → next period started 20 days ago (overdue)
$w('items.csv', [['whmcs_table', 'whmcs_id', 'kind', 'billable', 'internal', 'next_period_start', 'check_status', 'check_note', 'plan_name', 'xero_account'],
    ['services', '1', 'hosting', '1', '0', $p1, 'xero_ahead', 'Xero ahead of WHMCS by 1 period(s)', 'Standard - CMS with PHP 7', '43504'],
    ['services', '2', 'email', '1', '0', $d(30), 'agrees', 'WHMCS and Xero agree', '', '43504'],
    ['services', '3', 'hosting', '1', '0', $d(120), 'no_invoice', 'No Xero invoice for this item', '', '43504'],
    ['services', '5', 'hosting', '0', '1', '', '', '', '', '43504'],
    ['services', '7', 'hosting', '1', '0', $d(5), '', '', '', '43504'],
    ['domains', '11', 'domain', '1', '0', $d(40), 'agrees', 'WHMCS and Xero agree', '', '43601']]);
$w('xero-history.csv', [['whmcs_table', 'whmcs_id', 'invoice_number', 'invoice_date', 'period_start', 'period_end', 'amount_ex_gst', 'status', 'description'],
    ['services', '1', 'CC-9001', $d(-230), (new DateTimeImmutable($p1))->modify('-6 months')->format('Y-m-d'), (new DateTimeImmutable($p1))->modify('-1 day')->format('Y-m-d'), '201.00', 'Paid', 'WEBSITE HOSTING - Standard - CMS with PHP 7 - harbourviewmotel.example.nz'],
    ['domains', '11', 'CC-9002', $d(-330), (new DateTimeImmutable($d(40)))->modify('-1 year')->format('Y-m-d'), $d(39), '49.95', 'Paid', 'Domain Renewal - harbourviewmotel.example.nz - 1 Year/s']]);
echo "$out\n";
