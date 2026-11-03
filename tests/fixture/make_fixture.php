<?php
declare(strict_types=1);
/*
 * Synthetic fixture generator — FICTIONAL data only, same shapes as the
 * migrated staging data (source_system='fabrik' imports with legacy_record
 * JSON, plus app-created rows). Dates are relative to "today" (Pacific/Auckland)
 * so the Today screen always has overdue / due / stale / follow-up cases.
 *
 *   php make_fixture.php > fixture_data.sql
 */
date_default_timezone_set('Pacific/Auckland');
mt_srand(20261030);
$today = new DateTimeImmutable('today');
$d = fn(int $days) => $today->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$q = fn($v) => $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
$j = fn(array $a) => $q(json_encode($a, JSON_UNESCAPED_UNICODE));
$out = ["SET NAMES utf8mb4;", "START TRANSACTION;"];

$first = ['Aroha', 'Ben', 'Chloe', 'Daniel', 'Emma', 'Finn', 'Grace', 'Hemi', 'Isla', 'Jack', 'Kiri', 'Liam', 'Mere', 'Noah', 'Olivia', 'Paul', 'Quinn', 'Ruby', 'Sam', 'Tama'];
$last = ['Anderson', 'Brown', 'Clarke', 'Dixon', 'Evans', 'Fraser', 'Gibson', 'Harris', 'Irwin', 'Jones', 'King', 'Lee', 'Morgan', 'Ngata', 'Owens', 'Parata', 'Reid', 'Smith', 'Turner', 'Walker'];
$biz = ['Alpine Plumbing', 'Harbourview Motel', 'Kowhai Physio', 'Tekapo Kayak Tours', 'Rangitata Rural Supplies', 'Mackenzie Honey Co', 'Seaview Dental', 'Southern Cross Builders', 'Totara Law', 'Waimate Vets', 'Fernleaf Florist', 'Pleasant Point Garage', 'Clearwater Pools', 'Greystone Accounting', 'Highfield Bakery', 'Opihi Orchards', 'Ruapuna Racing Club', 'Saltwater Surf School', 'Te Ana Gallery', 'Upland Engineering',
    'Village Pharmacy', 'Westcoast Wines', 'Yellowtail Fishing', 'Zealandia Signs', 'Bayside Café', 'Coastal Carpets', 'Dunes Golf Club', 'Eastside Electrical', 'Foxglove Nursery', 'Glenmark Joinery', 'Hilltop Hire', 'Inland Bike Hire', 'Jetty Street Books', 'Kaka Point Lodge', 'Lakeside Camping', 'Matai Medical Centre', 'Northbank Trust', 'Oamaru Stone Works', 'Pukaki Photography', 'Quarry Road Concrete',
    'Riverstone Kindergarten', 'Stonebridge Croquet', 'Twizel Outdoor Gear', 'Unity Church Hall', 'Victoria Park Bowls', 'Waitaki Wool', 'Ōtipua Creek Farm', 'Arowhenua Arts', 'Bluegum Cottages', 'Bayfront Kiosk', 'Domain Tearooms', 'Elm Street Motors', 'Fairlie Pies', 'Geraldine Cheese', 'Hakataramea Station', 'Isthmus Design', 'Jubilee Hall', 'Kurow Museum', 'Lime Kiln Lodge', 'Mount Peel Station'];
$towns = ['Timaru', 'Twizel', 'Geraldine', 'Fairlie', 'Waimate', 'Oamaru', 'Temuka', 'Ashburton'];
$staff = ['geoff', 'roselyn', 'lydia', null];

// ---- clients (fabrik imports) ----
$clientIds = [];
foreach ($biz as $i => $name) {
    $id = $i + 1; $clientIds[] = $id;
    $status = ($id % 9 === 0) ? 'archive' : 'active';
    $town = $towns[$id % count($towns)];
    $rec = ['id' => (string)(100 + $id), 'client_name' => $name, 'business_name' => $id % 5 === 0 ? $name . ' Ltd' : '', 'status' => $status,
        'email' => $id % 4 === 0 ? '' : 'office@' . preg_replace('/[^a-z]/', '', strtolower($name)) . '.example.nz', 'phone' => $id % 3 === 0 ? '' : '03 6' . str_pad((string)(10000 + $id * 37), 6, '0', STR_PAD_LEFT),
        'physical_address_1' => $id . ' Example Street', 'physical_city_town' => $town, 'postal_city_town' => $town, 'postal_code' => '79' . str_pad((string)$id, 2, '0', STR_PAD_LEFT),
        'xero_contact' => $id % 2 ? 'XERO-FAKE-' . $id : '', 'domains' => $id % 3 === 0 ? null : preg_replace('/[^a-z]/', '', strtolower($name)) . '.example.nz', 'notes' => $id === 7 ? 'Prefers email; fictional test note.' : '', 'contact_method' => 'email'];
    $out[] = "INSERT INTO clients (id,source_system,source_id,name,status,xero_contact_id,legacy_record) VALUES ($id,'fabrik'," . $q(100 + $id) . "," . $q($name) . "," . $q($status) . "," . $q($rec['xero_contact'] ?: null) . "," . $j($rec) . ");";
}
// ---- contacts ----
$cid = 0; $firstContact = [];
foreach ($clientIds as $c) {
    $n = 1 + ($c % 3); $firstContact[$c] = $cid + 1;
    for ($k = 0; $k < $n; $k++) {
        $cid++;
        $fn = $first[$cid % 20]; $ln = $last[($cid * 7 + intdiv($cid, 20)) % 20];
        $mobile = ($cid % 2) ? '021 ' . (100 + $cid) . ' ' . (1000 + $cid * 3) : '';
        $phone = ($cid % 3) ? '' : '03 688 ' . (1000 + $cid);
        $type = $k === 0 ? 'project liaison' : json_encode(['accounts', 'project liaison']);
        $st = ($cid % 11 === 0) ? 'archive' : 'active';
        $email = ($cid % 5 === 0) ? '' : strtolower($fn) . '.' . strtolower($ln) . '@example.nz';
        $rec = ['id' => (string)(500 + $cid), 'client_id' => (string)(100 + $c), 'first_name' => $fn, 'last_name' => $ln, 'email' => $email, 'phone' => $phone, 'mobile' => $mobile, 'position' => $k === 0 ? 'Manager' : '', 'contact_type' => $type, 'status' => $st];
        $out[] = "INSERT INTO contacts (client_id,source_system,source_id,display_name,email,phone,legacy_record) VALUES ($c,'fabrik'," . $q(500 + $cid) . "," . $q("$fn $ln") . "," . $q($email ?: null) . "," . $q(($phone ?: $mobile) ?: null) . "," . $j($rec) . ");";
    }
}
// unlinked contact (as in real data)
$out[] = "INSERT INTO contacts (client_id,source_system,source_id,display_name,email,phone,legacy_record) VALUES (NULL,'fabrik','999','Orphan Contact','orphan@example.nz',NULL," . $j(['id' => '999', 'client_id' => '9999', 'first_name' => 'Orphan', 'last_name' => 'Contact']) . ");";

// ---- tasks (fabrik imports) ----
$titles = ['Website update', 'Fix contact form', 'New page layout', 'Domain renewal check', 'Email setup', 'Joomla upgrade', 'Photo gallery', 'SEO review', 'Logo artwork', 'Hosting migration', 'Newsletter template', 'Booking form changes'];
$taskId = 0; $taskRows = [];
for ($i = 1; $i <= 240; $i++) {
    $taskId++;
    $c = ($i === 17 || $i === 33) ? null : $clientIds[($i * 7) % count($clientIds)];
    $status = $i % 11 === 0 ? 'cancelled' : ($i % 13 === 0 ? 'in progress' : 'completed');
    $req = $d(-(2000 - $i * 8));
    // deliberate Today cases among imports
    if ($i === 13) { $status = 'in progress'; $req = $d(-10); }   // overdue within 60 days
    if ($i === 26) { $status = 'in progress'; $req = $d(3); }     // due soon
    if ($i === 39) { $status = 'in progress'; $req = $d(-400); }  // stale backlog
    if ($i === 52) { $status = 'pending'; $req = $d(-200); }      // stale backlog
    $completed = $status === 'completed' ? $req . ' 16:00:00' : null;
    $title = $titles[$i % count($titles)] . ' ' . $i;
    $cats = [['Website design and management', 'Website edits'], ['Graphic design', 'Advert'], ['Graphic design', 'Flyer-poster-leaflet'], ['Photography & video', 'Commission photography']];
    $cat = $cats[$i % 4];
    $who = [['geoff'], ['roselyn'], ['roselyn', 'geoff'], ['lydia']][$i % 4];
    $rec = ['id' => (string)(3000 + $i), 'client_id' => $c ? (string)(100 + $c) : '9999', 'task_title' => $title, 'status' => $status, 'required_date' => $req . ' 00:00:00', 'pricing' => ['hourly', 'set', 'web_service'][$i % 3], 'action_for' => json_encode($who), 'notes' => '', 'billing_advised' => $i % 17 === 0 ? '1' : '0',
        'task_category' => $cat[0], 'task_type' => $cat[1], 'queue' => (string)($i % 5 === 0 ? 200 : 0), 'contact_id' => $c ? (string)(500 + $firstContact[$c]) : '', 'domains' => $i % 7 === 0 ? 'example-' . $i . '.co.nz' : ''];
    $out[] = "INSERT INTO tasks (id,client_id,source_system,source_id,title,instructions,required_by,received_at,completed_at,priority,status,pricing,billing_advised,invoice_number,legacy_record) VALUES ($taskId," . ($c ?? 'NULL') . ",'fabrik'," . $q(3000 + $i) . "," . $q($title) . "," . $q("Fictional instructions for task $i.\nSecond line.") . "," . $q($req . ' 00:00:00') . "," . $q($d(-(2010 - $i * 8)) . ' 09:00:00') . "," . $q($completed) . "," . $q(['immediate', 'normal', 'high'][$i % 3]) . "," . $q($status) . "," . $q($rec['pricing']) . "," . ($i % 17 === 0 ? 1 : 0) . "," . $q($i % 23 === 0 ? 'INV-FAKE-' . $i : null) . "," . $j($rec) . ");";
    $taskRows[$taskId] = $c;
}
// ---- the "Pro forma" client: its open tasks are used as templates (v0.12 copies them into task_templates) ----
$pf = count($clientIds) + 1;
$out[] = "INSERT INTO clients (id,source_system,source_id,name,status,legacy_record) VALUES ($pf,'fabrik','223','Pro forma','active'," . $j(['id' => '223', 'client_name' => 'Pro forma', 'status' => 'active']) . ");";
foreach ([
    ['Build new website - Stage 1', "Stage 1rnInstall, and configure website and template to reflect branding requirementsrnExport/import users", 'set', 'yes', 700.00, 'Website design and management', 'New website', 'in progress'],
    ['Develop content and layout', 'Develop supplied text, images and other content, and assist with loading into the website.', 'hourly', '["yes"]', 0.00, 'Website design and management', 'Website edits', 'in progress'],
    ['Setup mailbox', 'Setup for charging six monthly mailbox fee\\r\\n\\r\\n', 'web_service', 'no', 0.00, null, null, 'in progress'],
    ['Old pro-forma (completed)', 'Not a template any more', 'set', 'yes', 50.00, null, null, 'completed'],
] as $k => [$pt, $pi, $pp, $pc, $pfee, $pcat, $ptype, $pst]) {
    $taskId++;
    $rec = ['id' => (string)(4000 + $k), 'client_id' => '223', 'task_title' => $pt, 'task_instructions' => $pi, 'pricing' => $pp, 'chargeable' => $pc, 'value' => (string)(int)$pfee, 'status' => $pst, 'action_for' => '["roselyn","geoff"]', 'task_category' => $pcat, 'task_type' => $ptype];
    $out[] = "INSERT INTO tasks (id,client_id,source_system,source_id,title,instructions,status,pricing,chargeable,agreed_fee_ex_gst,completed_at,legacy_record) VALUES ($taskId,$pf,'fabrik'," . $q(4000 + $k) . "," . $q($pt) . "," . $q($pi) . "," . $q($pst) . "," . $q($pp) . "," . $q($pc) . "," . $q(number_format($pfee, 2, '.', '')) . "," . ($pst === 'completed' ? $q($d(-900) . ' 12:00:00') : 'NULL') . "," . $j($rec) . ");";
    // workload columns are filled by migration 002 from legacy_record
    $taskRows[$taskId] = $pf;
}
// ---- work_log (fabrik imports) ----
for ($i = 1; $i <= 480; $i++) {
    $t = 1 + ($i * 13) % 240; $c = $taskRows[$t];
    if ($i % 40 === 0) $t = null;           // work without task
    if ($i % 97 === 0) $c = null;           // work without client
    $secs = $i % 19 === 0 ? 0 : (900 * (1 + $i % 8)); // some zero (unparsed legacy) durations
    $date = $d(-(1900 - $i * 3));
    $rec = ['id' => (string)(8000 + $i), 'task_id' => $t ? (string)(3000 + $t) : '', 'client_id' => $c ? (string)(100 + $c) : '', 'details' => 'Fictional work item ' . $i, 'time_worked' => $secs ? gmdate('H:i:s', $secs) : '', 'action_by' => $staff[$i % 3]];
    $out[] = "INSERT INTO work_log (client_id,task_id,source_system,source_id,staff_key,details,started_at,ended_at,work_date,duration_seconds,billable_seconds,billability,legacy_record) VALUES (" . ($c ?? 'NULL') . "," . ($t ?? 'NULL') . ",'fabrik'," . $q(8000 + $i) . "," . $q($staff[$i % 3]) . "," . $q('Fictional work item ' . $i) . "," . $q($date . ' 09:00:00') . "," . $q($date . ' 10:00:00') . "," . $q($date) . ",$secs,$secs,'review'," . $j($rec) . ");";
}
// ---- category -> type table (as the archived Fabrik classification) ----
$cl = 0;
foreach (['Website design and management' => ['Website edits', 'New website', 'Upgrade website'], 'Graphic design' => ['Advert', 'Flyer-poster-leaflet', 'Logo'], 'Photography & video' => ['Commission photography', 'Video'], 'Social media' => ['']] as $cat => $types)
    foreach ($types as $ty) { $cl++; $out[] = "INSERT INTO legacy_classification (source_id,source_table,source_record,row_sha256) VALUES ($cl,'aa_cc_acc_codes'," . $j(['id' => (string)$cl, 'task_category' => $cat, 'task_type' => $ty, 'date_time' => '0000-00-00 00:00:00']) . ",'" . hash('sha256', $cat . $ty) . "');"; }
// ---- app-created rows, mirroring the user's staging tests ----
$out[] = "ALTER TABLE tasks AUTO_INCREMENT=2048;";
$out[] = "ALTER TABLE work_log AUTO_INCREMENT=4096;";
$out[] = "INSERT INTO enquiries (id,client_id,title,details,required_by,priority,status,created_at) VALUES (1,2,'Fixture enquiry converted','Converted in fixture',NULL,'normal','task_created'," . $q($d(-5) . ' 10:00:00') . ");";
$out[] = "INSERT INTO enquiries (id,client_id,title,details,required_by,priority,next_action,next_action_due,waiting_for,status,created_at) VALUES (2,3,'Quote for new booking page',NULL," . $q($d(14)) . ",'high','Phone to confirm scope'," . $q($d(1)) . ",'Client photos','enquiry'," . $q($d(-2) . ' 11:00:00') . ");";
$out[] = "INSERT INTO enquiries (id,client_id,title,details,priority,status,created_at) VALUES (3,4,'Unconverted enquiry for tests','Plain new enquiry','normal','enquiry'," . $q($d(-1) . ' 12:00:00') . ");";
$out[] = "INSERT INTO tasks (id,client_id,enquiry_id,source_system,source_id,title,instructions,received_at,priority,status) VALUES (2048,2,1,'enquiry','1','Fixture enquiry converted','Converted in fixture'," . $q($d(-5) . ' 10:05:00') . ",'normal','open');";
$out[] = "INSERT INTO activity_events (client_id,enquiry_id,event_kind,event_at,summary) VALUES (2,1,'enquiry_created'," . $q($d(-5) . ' 10:00:00') . ",'Quick Enquiry created in staging'),(3,2,'enquiry_created'," . $q($d(-2) . ' 11:00:00') . ",'Quick Enquiry created in staging'),(4,3,'enquiry_created'," . $q($d(-1) . ' 12:00:00') . ",'Quick Enquiry created in staging');";
$out[] = "INSERT INTO activity_events (client_id,enquiry_id,task_id,event_kind,event_at,summary) VALUES (2,1,2048,'enquiry_converted'," . $q($d(-5) . ' 10:05:00') . ",'Enquiry progressed to task');";
$out[] = "INSERT INTO work_log (id,client_id,task_id,source_system,source_id,staff_key,details,work_date,duration_seconds,billable_seconds,billability) VALUES (4096,NULL,NULL,'m5','fixture-4096',NULL,'Test 17 minute entry'," . $q($d(0)) . ",1020,0,'review');";
$out[] = "INSERT INTO activity_events (work_log_id,event_kind,event_at,summary) VALUES (4096,'work_log_created',NOW(),'New Work Log entry (staging)');";
$out[] = "COMMIT;";
echo implode("\n", $out), "\n";
