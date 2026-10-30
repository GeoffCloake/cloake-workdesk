<?php
declare(strict_types=1);
require_once __DIR__ . '/version.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure','1');
ini_set('session.cookie_samesite','Strict');
session_name('cloake_m4');
session_start();
if (empty($_SESSION['m4_authenticated'])) {header('Location: ./');exit;}
$configFile='/home/cloakecrea/cloake-manager/private/config.php';
if (!is_file($configFile)) {http_response_code(503);exit('Configuration unavailable');}
$cfg=require $configFile;
if (!is_array($cfg) || empty($cfg['db_password'])) {http_response_code(503);exit('Configuration incomplete');}
function h($value):string {return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function validDate(string $value):bool {if ($value==='')return true;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $date!==false && $date->format('Y-m-d')===$value;}
try {
 $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'cloakecrea_manager_devt').';charset=utf8mb4',$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
} catch(Throwable $e) {error_log('M6 DB connect: '.$e->getMessage());http_response_code(503);exit('Database temporarily unavailable');}
if (empty($_SESSION['m6_csrf']))$_SESSION['m6_csrf']=bin2hex(random_bytes(32));
$values=['client_id'=>'','title'=>'','details'=>'','required_by'=>'','priority'=>'normal','next_action'=>'','next_action_due'=>'','waiting_for'=>'','line_description'=>'','line_quantity'=>'1','line_unit_price'=>'','line_pricing'=>'set'];
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
 foreach($values as $field=>$default) {
  $raw=$_POST[$field]??$default;
  $values[$field]=is_string($raw)?trim($raw):'';
 }
 if (!hash_equals($_SESSION['m6_csrf'],(string)($_POST['csrf']??'')))$errors[]='Session expired; reload the form.';
 if (!ctype_digit($values['client_id']) || (int)$values['client_id']<1)$errors[]='Choose an existing client.';
 if (mb_strlen($values['title'])<3 || mb_strlen($values['title'])>255)$errors[]='Title must be between 3 and 255 characters.';
 if (mb_strlen($values['details'])>30000)$errors[]='Description is too long.';
 if (!validDate($values['required_by']) || !validDate($values['next_action_due']))$errors[]='Enter valid dates.';
 if (!in_array($values['priority'],['low','normal','high','urgent'],true))$errors[]='Invalid priority.';
 if (mb_strlen($values['next_action'])>10000 || mb_strlen($values['waiting_for'])>80)$errors[]='Follow-up details are too long.';
 $hasLine=$values['line_description']!=='' || $values['line_unit_price']!=='';
 if ($hasLine) {
  if (mb_strlen($values['line_description'])<3 || mb_strlen($values['line_description'])>5000)$errors[]='Breakdown needs a description (3–5000 characters).';
  if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$values['line_quantity']) || (float)$values['line_quantity']<=0)$errors[]='Breakdown quantity must be positive (up to 3 decimals).';
  if ($values['line_unit_price']==='' || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/',$values['line_unit_price']))$errors[]='Breakdown needs a non-negative unit price (ex GST).';
  if (!in_array($values['line_pricing'],['hourly','set','web_service'],true))$errors[]='Invalid pricing mode.';
 }
 $clientId=(int)$values['client_id'];
 if (!$errors) {
  $stmt=$pdo->prepare('SELECT id FROM clients WHERE id=?');$stmt->execute([$clientId]);
  if (!$stmt->fetchColumn())$errors[]='Client not found.';
 }
 if (!$errors) {
  try {
   $pdo->beginTransaction();
   $stmt=$pdo->prepare('INSERT INTO enquiries (client_id,title,details,required_by,priority,next_action,next_action_due,waiting_for,status) VALUES (?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$clientId,$values['title'],$values['details']?:null,$values['required_by']?:null,$values['priority'],$values['next_action']?:null,$values['next_action_due']?:null,$values['waiting_for']?:null,'enquiry']);
   $newId=(int)$pdo->lastInsertId();
   if ($hasLine) {
    $stmt=$pdo->prepare("INSERT INTO breakdown_items (enquiry_id,description,quantity,unit_price_ex_gst,pricing_mode,status) VALUES (?,?,?,?,?,'proposed')");
    $stmt->execute([$newId,$values['line_description'],$values['line_quantity'],$values['line_unit_price'],$values['line_pricing']]);
   }
   $stmt=$pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,event_kind,event_at,summary) VALUES (?,?,'enquiry_created',NOW(),?)");
   $stmt->execute([$clientId,$newId,'Quick Enquiry created in staging']);
   $pdo->commit();
   $_SESSION['m6_csrf']=bin2hex(random_bytes(32));
   header('Location: add-enquiry.php?saved='.$newId);exit;
  } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();error_log('M6 enquiry save: '.$e->getMessage());$errors[]='Unable to save enquiry. No changes committed.';}
 }
}
try {$clients=$pdo->query('SELECT id,name FROM clients ORDER BY name LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);} catch(Throwable $e){error_log('M6 clients: '.$e->getMessage());$clients=[];$errors[]='Client list unavailable.';}
$savedId=filter_var($_GET['saved']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f6f6f3"><title>Quick Enquiry · Cloake staging</title><link rel="stylesheet" href="assets/app.css"><style>
.m6-shell{max-width:710px;margin:0 auto;padding:20px 16px 85px}.m6-shell h1{margin:6px 0}.m6-shell label{display:block;font-size:14px;font-weight:650;margin:0 0 6px}.m6-shell input:not([type=hidden]),.m6-shell select,.m6-shell textarea{font:inherit;box-sizing:border-box;width:100%;min-height:44px;padding:11px;border:1px solid #c8d1cc;border-radius:10px;background:#fff;color:#24322c}.m6-shell textarea{min-height:95px}.m6-shell .field{margin:16px 0}.m6-shell .pair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.m6-shell details{padding:12px;border:1px solid #dce2db;border-radius:12px;margin:17px 0}.m6-shell summary{cursor:pointer;font-weight:650}.m6-shell .save{width:100%;min-height:50px;margin:15px 0}.m6-shell .alert{background:#fff0e9;padding:12px;border-radius:10px}.m6-shell .success{background:#eaf5ee;padding:12px;border-radius:10px}.m6-shell .hint{font-size:13px;color:#657268}@media(max-width:470px){.m6-shell .pair{grid-template-columns:1fr}}
</style></head><body><main class="m6-shell"><a href="index.php?page=enquiries">← Enquiries</a><div class="eyebrow" style="margin-top:18px">PRIVATE STAGING · CLIENT & SERVICES MANAGER</div><h1>Quick Enquiry</h1><p class="muted">Capture the request first. Quoting and task conversion come later.</p>
<?php if($savedId):?><div class="success" role="status">Enquiry #<?=h($savedId)?> saved. <a href="index.php?page=enquiries">View enquiries</a></div><?php endif;?>
<?php if($errors):?><div class="alert" role="alert"><strong>Please check:</strong><ul><?php foreach(array_unique($errors) as $error):?><li><?=h($error)?></li><?php endforeach;?></ul></div><?php endif;?>
<form action="add-enquiry.php" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h($_SESSION['m6_csrf'])?>">
<div class="field"><label for="client">Client</label><select id="client" name="client_id" required><option value="">Choose a client</option><?php foreach($clients as $c):?><option value="<?=h($c['id'])?>" <?=$values['client_id']===(string)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></div>
<div class="field"><label for="title">Enquiry / job title</label><input id="title" name="title" required maxlength="255" value="<?=h($values['title'])?>" placeholder="What is needed?"></div>
<div class="field"><label for="details">Description</label><textarea id="details" name="details" placeholder="Brief instructions or notes"><?=h($values['details'])?></textarea></div>
<div class="pair"><div class="field"><label for="required_by">Required by</label><input type="date" id="required_by" name="required_by" value="<?=h($values['required_by'])?>"></div><div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v):?><option value="<?=h($k)?>" <?=$values['priority']===$k?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div></div>
<details><summary>Next action & follow-up</summary><div class="field"><label for="next_action">Next action</label><textarea name="next_action" id="next_action"><?=h($values['next_action'])?></textarea></div><div class="pair"><div class="field"><label for="next_action_due">Follow up on</label><input type="date" name="next_action_due" id="next_action_due" value="<?=h($values['next_action_due'])?>"></div><div class="field"><label for="waiting_for">Waiting for</label><input name="waiting_for" id="waiting_for" maxlength="80" value="<?=h($values['waiting_for'])?>"></div></div></details>
<details><summary>Optional breakdown / price estimate</summary><p class="hint">One proposed line item, excluding GST. This does not generate a quote or invoice.</p><div class="field"><label for="line_description">Line description</label><textarea id="line_description" name="line_description"><?=h($values['line_description'])?></textarea></div><div class="pair"><div class="field"><label for="line_quantity">Quantity</label><input id="line_quantity" name="line_quantity" inputmode="decimal" value="<?=h($values['line_quantity'])?>"></div><div class="field"><label for="line_unit_price">Unit price (ex GST)</label><input id="line_unit_price" name="line_unit_price" inputmode="decimal" placeholder="0.00" value="<?=h($values['line_unit_price'])?>"></div></div><div class="field"><label for="line_pricing">Pricing type</label><select id="line_pricing" name="line_pricing"><?php foreach(['set'=>'Set fee','hourly'=>'Hourly','web_service'=>'Web service'] as $k=>$v):?><option value="<?=h($k)?>" <?=$values['line_pricing']===$k?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div></details>
<button class="save" type="submit">Save enquiry</button><p class="hint">Staging only. No task, quote or Xero document will be created.</p></form></main><div class="release-indicator" aria-label="Application version">v<?=htmlspecialchars(CLOAKE_VERSION, ENT_QUOTES, 'UTF-8')?> <span>· build <?=htmlspecialchars(CLOAKE_BUILD, ENT_QUOTES, 'UTF-8')?></span></div></body></html>
