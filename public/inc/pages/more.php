<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
?>
<div class="more-list">
  <a href="add-enquiry.php"><span><strong>＋ Quick Enquiry</strong><small>Capture a request in seconds</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('enquiries'))?>"><span><strong>Enquiries</strong><small>Follow-ups and conversion to tasks</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('invoicing'))?>"><span><strong>Pending Invoicing</strong><small>Billing evidence review — no Xero actions</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('items'))?>"><span><strong>Account Items</strong><small>Rates and catalogue</small></span><span aria-hidden="true">›</span></a>
  <a href="<?=h(route('check'))?>"><span><strong>Release check</strong><small>Version, database and schema checks</small></span><span aria-hidden="true">›</span></a>
</div>
<p class="small muted more-version">Cloake Client &amp; Services Manager <?=h(cm_version_label())?></p>
