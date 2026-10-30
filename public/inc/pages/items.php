<?php
declare(strict_types=1);
defined('CLOAKE_CONFIG_FILE') || exit;
/* Account item catalogue (read-only). */
$items = allRows($pdo, 'SELECT id,code,description,pricing_mode,unit_price_ex_gst,xero_item_code,active FROM account_items ORDER BY active DESC,code LIMIT 200');
?>
<p class="lead">Your account item catalogue, directly from the staging database.</p>
<div class="notice"><strong>Service renewals are not yet mapped.</strong> Account items are price/catalogue definitions; they do not establish which client has a renewal, or when it is due. WHMCS services and domains have not been imported. No renewal notices or invoices are generated here.</div>
<?php if (!$items): ?><div class="panel"><h2 class="panel-title">No account items imported yet</h2><p class="muted">This is an empty catalogue, not a missing screen. Legacy account codes remain separately archived until a controlled mapping is approved.</p></div>
<?php else: ?>
<form class="search" method="get" action="index.php" role="search"><input type="hidden" name="page" value="items"><label for="iq" class="visually-hidden">Filter items</label><input id="iq" type="search" name="q" value="<?=h($q)?>" placeholder="Filter displayed items"><button type="submit">Filter</button></form>
<div class="stack compact-list"><?php foreach ($items as $it): if ($q !== '' && stripos($it['code'] . ' ' . ($it['description'] ?? ''), $q) === false) continue; ?>
  <div class="record"><span><strong><?=h($it['code'])?></strong><small><?=h($it['description'] ?? '')?> · <?=h($it['pricing_mode'])?> · <?=!empty($it['active']) ? 'Active' : 'Inactive'?></small></span><span class="end"><?=$it['unit_price_ex_gst'] !== null ? '$' . h(number_format((float)$it['unit_price_ex_gst'], 2)) . ' ex GST' : 'Price not set'?><?php if ($it['xero_item_code']): ?><small>Xero <?=h($it['xero_item_code'])?></small><?php endif; ?></span></div>
<?php endforeach; ?></div>
<?php endif; ?>
