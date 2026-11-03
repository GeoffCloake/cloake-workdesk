<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
?>
<div class="more-list">
  <a href="add-enquiry.php"><span><strong>＋ Quick Enquiry</strong><small>Capture a request in seconds</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('enquiries'))?>"><span><strong>Enquiries</strong><small>Follow-ups and conversion to tasks</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('services'))?>"><span><strong>Services</strong><small>Hosting, domains and email — what each client has and when it renews</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('templates'))?>"><span><strong>Task templates</strong><small>Reusable tasks with their pricing and milestones</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('invoicing'))?>"><span><strong>Pending Invoicing</strong><small>Renewals, milestones, claims and tasks to invoice in Xero</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('workload'))?>"><span><strong>Workload</strong><small>Who is doing what, and when</small></span><span aria-hidden="true">›</span></a>
  <a href="staff.php"><span><strong>Staff &amp; hours</strong><small>People on the schedule and their hours</small></span><span aria-hidden="true">›</span></a>
  <a href="claims.php"><span><strong>Month-end claims</strong><small>Hours and progress claims for monthly-billed tasks</small></span><span aria-hidden="true">›</span></a>
  <a href="billing-settings.php"><span><strong>Billing settings</strong><small>Lead time, Xero accounts, hourly rate</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('items'))?>"><span><strong>Account Items</strong><small>Rates and catalogue</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('check'))?>"><span><strong>Release check</strong><small>Version, database and schema checks</small></span><span aria-hidden="true">›</span></a>
</div>
<p class="small muted more-version">Cloake Client &amp; Services Manager <?=h(cm_version_label())?></p>
