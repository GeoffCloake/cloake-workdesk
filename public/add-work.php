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
if (!is_array($cfg)||empty($cfg['db_password'])){http_response_code(503);exit('Configuration incomplete');}
function h($v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function lookup(PDO $pdo,string $sql,array $args=[]):array{$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
try{
 $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'cloakecrea_manager_devt').';charset=utf8mb4',$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
}catch(Throwable $e){error_log('M5 connection error: '.$e->getMessage());http_response_code(503);exit('Database unavailable');}
date_default_timezone_set('Pacific/Auckland');
if (empty($_SESSION['m5_csrf'])) $_SESSION['m5_csrf']=bin2hex(random_bytes(32));
$errors=[];$saved=false;$search=trim(substr((string)($_GET['find']??''),0,80));
$initialTask=filter_var($_GET['task_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$values=['details'=>'','date'=>date('Y-m-d'),'mode'=>'duration','hours'=>'0','minutes'=>'15','seconds'=>'0','start'=>'09:00','end'=>'09:15','task_id'=>(string)$initialTask,'billability'=>'review','bill_hours'=>'0','bill_minutes'=>'0','bill_seconds'=>'0','staff'=>''];
if($_SERVER['REQUEST_METHOD']==='POST'){
 foreach($values as $key=>$default)$values[$key]=trim((string)($_POST[$key]??$default));
 if(!hash_equals($_SESSION['m5_csrf'],(string)($_POST['csrf']??'')))$errors[]='Session token expired. Reload and try again.';
 $description=$values['details'];if(mb_strlen($description)<3 || mb_strlen($description)>5000)$errors[]='Describe the work (3–5000 characters).';
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$values['date']);if(!$d || $d->format('Y-m-d')!==$values['date'])$errors[]='Select a valid work date.';
 $mode=$values['mode'];if(!in_array($mode,['duration','times'],true))$errors[]='Invalid duration method.';
 $seconds=0;$startAt=null;$endAt=null;
 if($mode==='duration'){
  foreach(['hours','minutes','seconds'] as $key){if(!ctype_digit($values[$key])||strlen($values[$key])>5)$errors[]='Enter non-negative whole numbers for duration.';}
  if(!$errors){$hrs=(int)$values['hours'];$mins=(int)$values['minutes'];$secs=(int)$values['seconds'];if($mins>59||$secs>59||$hrs>23)$errors[]='Duration may be up to 23h 59m 59s; minutes and seconds must be 0–59.';else $seconds=$hrs*3600+$mins*60+$secs;}
 }elseif($mode==='times'){
  foreach(['start','end'] as $key)if(!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$values[$key]))$errors[]='Enter valid HH:MM clock times.';
  if(!$errors){$startAt=$values['date'].' '.$values['start'].':00';$endAt=$values['date'].' '.$values['end'].':00';$seconds=strtotime($endAt)-strtotime($startAt);if($seconds<=0)$errors[]='Finish must follow start on the same day. For overnight work, use Duration only.';}
 }
 if($seconds<1 || $seconds>86399)$errors[]='Enter a duration greater than zero and less than 24 hours.';
 $billability=$values['billability'];if(!in_array($billability,['review','non_billable','included_in_fee','billable'],true))$errors[]='Invalid billing classification.';
 $billSeconds=0;
 foreach(['bill_hours','bill_minutes','bill_seconds'] as $key)if(!ctype_digit($values[$key])||strlen($values[$key])>5)$errors[]='Invalid billable duration.';
 if(!$errors && $billability==='billable'){
  $bh=(int)$values['bill_hours'];$bm=(int)$values['bill_minutes'];$bs=(int)$values['bill_seconds'];
  if($bm>59||$bs>59||$bh>23)$errors[]='Billable minutes and seconds must be 0–59.';
  else $billSeconds=$bh*3600+$bm*60+$bs;
  if($billSeconds>$seconds)$errors[]='Billable time cannot exceed time worked.';
 }
 $taskId=null;$clientId=null;
 if($values['task_id']!==''){
  if(!ctype_digit($values['task_id']))$errors[]='Invalid task ID.';
  else{$s=$pdo->prepare('SELECT id,client_id FROM tasks WHERE id=?');$s->execute([(int)$values['task_id']]);$task=$s->fetch();if(!$task)$errors[]='Task not found.';else{$taskId=(int)$task['id'];$clientId=$task['client_id']===null?null:(int)$task['client_id'];}}
 }
 if(mb_strlen($values['staff'])>100)$errors[]='Staff name is too long.';
 if(!$errors){
  try{
   $pdo->beginTransaction();
   $s=$pdo->prepare('INSERT INTO work_log (client_id,task_id,source_system,source_id,staff_key,details,started_at,ended_at,work_date,duration_seconds,billable_seconds,billability,rate_ex_gst,billed_amount_ex_gst,billable_approved_at,legacy_record) VALUES (?,?,\'m5\',?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)');
   $s->execute([$clientId,$taskId,bin2hex(random_bytes(16)),$values['staff']?:null,$description,$startAt,$endAt,$values['date'],$seconds,$billSeconds,$billability]);
   $newId=(int)$pdo->lastInsertId();
   $e=$pdo->prepare('INSERT INTO activity_events(client_id,task_id,work_log_id,event_kind,event_at,summary,actor,payload) VALUES (?,?,?,\'work_log_created\',NOW(),?,?,NULL)');
   $e->execute([$clientId,$taskId,$newId,'New Work Log entry (staging)', $values['staff']?:null]);
   $pdo->commit();$_SESSION['m5_csrf']=bin2hex(random_bytes(32));
   header('Location: add-work.php?saved=1');exit;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('M5 save error: '.$e->getMessage());$errors[]='Could not save entry. No changes committed.';}
 }
}
$tasks=[];
try{
 if($search!=='')$tasks=lookup($pdo,'SELECT t.id,t.source_id,t.title,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.title LIKE ? OR c.name LIKE ? OR t.source_id LIKE ? ORDER BY t.id DESC LIMIT 35',['%'.$search.'%','%'.$search.'%','%'.$search.'%']);
 else $tasks=lookup($pdo,'SELECT t.id,t.source_id,t.title,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id ORDER BY t.id DESC LIMIT 35');
 if($values['task_id']!=='' && !in_array((int)$values['task_id'],array_column($tasks,'id'),false)){
  $s=$pdo->prepare('SELECT t.id,t.source_id,t.title,c.name client FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?');$s->execute([(int)$values['task_id']]);$r=$s->fetch();if($r)array_unshift($tasks,$r);
 }
}catch(Throwable $e){error_log('M5 lookup error: '.$e->getMessage());$errors[]='Task list unavailable';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f6f6f3"><title>Log work · Cloake staging</title><link rel="stylesheet" href="assets/app.css"><style>
.m5-shell{max-width:700px;margin:auto;padding:22px 16px 90px}.m5-shell h1{margin:6px 0}.m5-shell label{display:block;font-weight:650;font-size:14px;margin-bottom:7px}.m5-shell textarea,.m5-shell select{font:inherit;width:100%;border:1px solid #cad3cd;border-radius:11px;background:white;padding:12px;min-height:46px}.m5-shell textarea{min-height:115px}.m5-shell .field{margin:17px 0}.m5-shell .triple{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.m5-shell .double{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.m5-shell fieldset{border:0;padding:0;margin:0}.m5-shell legend{font-weight:650;margin-bottom:10px}.m5-shell .switches{display:flex;gap:8px;flex-wrap:wrap}.m5-shell .switches label{border:1px solid #cad3cd;border-radius:12px;padding:12px 14px;background:white;margin:0;cursor:pointer}.m5-shell .switches input{width:auto}.m5-shell details{padding:0 15px}.m5-shell .helper{font-size:12px;color:#718078}.m5-shell .alert{background:#fff1ec;border:1px solid #edb09c;padding:14px;border-radius:12px}.m5-shell .success{background:#edf5ef;padding:16px;border-radius:12px}.m5-shell .save{width:100%;padding:16px;margin-top:14px}.m5-shell .back{display:inline-block;margin:7px 0 14px}
</style></head><body><div class="m5-shell"><a class="back" href="index.php?page=work">← Work Log</a><div class="eyebrow">PRIVATE STAGING · CLIENT & SERVICES MANAGER</div><h1>Log work</h1><p class="muted">Record the work first. Billing is reviewed separately.</p>
<?php if(isset($_GET['saved'])):?><div class="success" role="status">Entry saved to staging. <a href="index.php?page=work">View Work Log</a></div><?php endif;?>
<?php if($errors):?><div class="alert" role="alert"><strong>Check this entry:</strong><ul><?php foreach(array_unique($errors) as $msg):?><li><?=h($msg)?></li><?php endforeach;?></ul></div><?php endif;?>
<form method="post" action="add-work.php" autocomplete="off"><input type="hidden" name="csrf" value="<?=h($_SESSION['m5_csrf'])?>">
<div class="field"><label for="details">What did you do?</label><textarea id="details" name="details" required maxlength="5000" placeholder="Brief work description"><?=h($values['details'])?></textarea></div>
<div class="field"><label for="date">Date</label><input type="date" id="date" name="date" required value="<?=h($values['date'])?>"></div>
<div class="field"><fieldset><legend>Duration method</legend><div class="switches"><label><input type="radio" name="mode" value="duration" <?=($values['mode']==='duration'?'checked':'')?>> Duration only</label><label><input type="radio" name="mode" value="times" <?=($values['mode']==='times'?'checked':'')?>> Start / finish</label></div></fieldset></div>
<div id="m5-duration" class="field"><div class="triple"><div><label for="hours">Hours</label><input id="hours" name="hours" type="number" min="0" max="23" step="1" value="<?=h($values['hours'])?>"></div><div><label for="minutes">Minutes</label><input id="minutes" name="minutes" type="number" min="0" max="59" step="1" value="<?=h($values['minutes'])?>"></div><div><label for="seconds">Seconds</label><input id="seconds" name="seconds" type="number" min="0" max="59" step="1" value="<?=h($values['seconds'])?>"></div></div><p class="helper">Exact duration, without inventing clock times.</p></div>
<div id="m5-times" class="field" hidden><div class="double"><div><label for="start">Start</label><input id="start" name="start" type="time" value="<?=h($values['start'])?>"></div><div><label for="end">Finish</label><input id="end" name="end" type="time" value="<?=h($values['end'])?>"></div></div><p class="helper">Same-day sessions. Use Duration only for overnight work.</p></div>
<div class="field"><label for="task_id">Task (optional)</label><select id="task_id" name="task_id"><option value="">Unallocated — assign later</option><?php foreach($tasks as $t):?><option value="<?=h($t['id'])?>" <?=((string)$t['id']===$values['task_id']?'selected':'')?>><?=h('#'.$t['source_id'].' · '.$t['title'].' · '.($t['client']??'Unassigned'))?></option><?php endforeach;?></select><p class="helper">Recent 35 tasks, or use the search below. Task IDs in the menu are original IDs; saved links use internal IDs.</p></div>
<div class="field"><details><summary>Find another task</summary><p class="helper">Search for a task on the main Tasks screen, open it, then select “Log work”. This preserves its assignment without losing your draft.</p></details></div>
<details><summary>Billing & staff (optional)</summary><div class="field"><label for="billability">Treatment</label><select name="billability" id="billability"><?php foreach(['review'=>'Review later','non_billable'=>'Non-billable','included_in_fee'=>'Included in set fee','billable'=>'Potentially billable (not approved)'] as $key=>$label):?><option value="<?=h($key)?>" <?=($key===$values['billability']?'selected':'')?>><?=h($label)?></option><?php endforeach;?></select></div><div class="field"><div class="triple"><div><label for="bill_hours">Billable hours</label><input id="bill_hours" type="number" min="0" max="23" name="bill_hours" value="<?=h($values['bill_hours'])?>"></div><div><label for="bill_minutes">Minutes</label><input id="bill_minutes" type="number" min="0" max="59" name="bill_minutes" value="<?=h($values['bill_minutes'])?>"></div><div><label for="bill_seconds">Seconds</label><input id="bill_seconds" type="number" min="0" max="59" name="bill_seconds" value="<?=h($values['bill_seconds'])?>"></div></div><p class="helper">Used only if treatment is “Potentially billable”; no rate, charge or approval is assigned.</p></div><div class="field"><label for="staff">Staff</label><input id="staff" name="staff" maxlength="100" value="<?=h($values['staff'])?>" placeholder="Staff name"></div></details>
<button class="save" type="submit">Save Work Log entry</button><p class="helper">Staging only. This adds a new record; imported historical entries stay unchanged. No Xero actions.</p></form></div><script src="assets/m5-work.js" defer></script><div class="release-indicator" aria-label="Application version">v<?=htmlspecialchars(CLOAKE_VERSION, ENT_QUOTES, 'UTF-8')?> <span>· build <?=htmlspecialchars(CLOAKE_BUILD, ENT_QUOTES, 'UTF-8')?></span></div></body></html>
