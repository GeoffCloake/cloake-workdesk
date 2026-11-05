<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* v0.15: everything beyond the everyday screens, grouped so it is easy to find and easy to ignore. */
$item = fn(string $href, string $title, string $hint) => '<a href="' . h($href) . '"><span><strong>' . h($title) . '</strong><small>' . h($hint) . '</small></span><span aria-hidden="true">›</span></a>';
$groups = [
    'Everyday' => [
        ['add-enquiry.php', '＋ Quick Enquiry', 'Capture a request in seconds'],
        [route('enquiries'), 'Enquiries', 'Follow-ups and conversion to tasks'],
    ],
    'Planning' => [
        [route('workload'), 'Workload', 'Who is doing what, and when'],
        [route('templates'), 'Task templates', 'Reusable tasks with their pricing and milestones'],
        ['staff.php', 'Staff & hours', 'People on the schedule and their hours'],
    ],
    'Billing' => [
        [route('invoicing'), 'Pending Invoicing', 'Renewals, milestones, claims and tasks to invoice in Xero'],
        [route('services'), 'Services', 'Hosting, domains and email — what each client has and when it renews'],
        ['plans.php', 'Plans', 'Hosting, email and domain plans: today\'s prices, legacy prices, Xero items'],
        ['cpanel-check.php', 'cPanel check', 'Mailboxes, forwards, package and disk from the server; counts to update'],
        [route('domains'), 'Domains', 'Every domain: expiry, registrar, DNS, auto-renew, and which hosting it is included in'],
        ['claims.php', 'Month-end claims', 'Hours and progress claims for monthly-billed tasks'],
        ['billing-settings.php', 'Billing settings', 'Lead time, Xero accounts, hourly rate, materials markup'],
        ['xero.php', 'Xero connection', 'Create draft invoices in Xero from Pending Invoicing'],
        ['xero-sync.php', 'Xero sync', 'Match clients with Xero contacts; check account codes and tax types'],
        [route('items'), 'Account Items', 'Rates and catalogue'],
    ],
    'System' => [
        [route('check'), 'Release check', 'Version, database and schema checks'],
    ],
];
foreach ($groups as $g => $rows): ?>
<h2 class="more-group"><?=h($g)?></h2>
<div class="more-list"><?php foreach ($rows as [$href, $title, $hint]) echo $item($href, $title, $hint); ?></div>
<?php endforeach; ?>
<p class="small muted more-version">Cloake Workdesk <?=h(cm_version_label())?></p>
