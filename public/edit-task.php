<?php
require_once __DIR__ . '/version.php';
require __DIR__.'/m7-bootstrap.php';
$id=m7id();if (!$id) {http_response_code(400);exit('Invalid task.');}
$error='';
try {
 if ($_SERVER['REQUEST_METHOD']==='POST') {
  m7verify();
  $title=trim((string)($_POST['title']??''));$instructions=trim((string)($_POST['instructions']??''));
  $notes=trim((string)($_POST['notes']??''));$priority=(string)($_POST['priority']??'normal');
  $status=(string)($_POST['status']??'open');$required=m7date($_POST['required_by']??'');
  if ($title==='' || mb_strlen($title)>255) throw new InvalidArgumentException('Title required (maximum 255 characters).');
  if (!in_array($priority,['low','normal','high','urgent'],true)) throw new InvalidArgumentException('Invalid priority.');
  if (!in_array($status,['open','in_progress','waiting','completed'],true)) throw new InvalidArgumentException('Invalid status.');
  $pdo->beginTransaction();
  $s=$pdo->prepare('SELECT * FROM tasks WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();
  if (!$old) throw new InvalidArgumentException('Task not found.');
  if ($old['source_system']!=='enquiry' || $old['enquiry_id']===null || $old['legacy_record']!==null) throw new InvalidArgumentException('Historical tasks are read-only in M7A.');
  $dateTime=$required ? $required.' 00:00:00' : null;
  $completed=$status==='completed' ? ($old['completed_at'] ?: date('Y-m-d H:i:s')) : null;
  $s=$pdo->prepare('UPDATE tasks SET title=?, instructions=?, notes=?, priority=?, status=?, required_by=?, completed_at=? WHERE id=?');
  $s->execute([$title,$instructions?:null,$notes?:null,$priority,$status,$dateTime,$completed,$id]);
  $changes=[];
  foreach (['title'=>$title,'instructions'=>$instructions?:null,'notes'=>$notes?:null,'priority'=>$priority,'status'=>$status,'required_by'=>$dateTime,'completed_at'=>$completed] as $field=>$new) {
    if ((string)($old[$field]??'')!==(string)($new??'')) $changes[$field]=['previous'=>$old[$field], 'current'=>$new];
  }
  if($changes) {
    $s=$pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,task_id,event_kind,event_at,summary,payload) VALUES (?,?,?,'task_updated',NOW(),?,?)");
    $s->execute([$old['client_id'],$old['enquiry_id'],$id,'Updated task fields: '.implode(', ',array_keys($changes)),json_encode($changes,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)]);
  }
  $pdo->commit();header('Location: edit-task.php?id='.$id.'&saved=1',true,303);exit;
 }
} catch(Throwable $e) {
 if($pdo->inTransaction())$pdo->rollBack();
 $error=$e instanceof InvalidArgumentException?$e->getMessage():'Unable to save task.';
 if(!($e instanceof InvalidArgumentException))error_log('M7 task update #'.$id.': '.$e->getMessage());
}
$s=$pdo->prepare('SELECT t.*,c.name AS client_name FROM tasks t LEFT JOIN clients c ON c.id=t.client_id WHERE t.id=?');$s->execute([$id]);$t=$s->fetch();
if(!$t){http_response_code(404);exit('Task not found.');}
$editable=$t['source_system']==='enquiry' && $t['enquiry_id']!==null && $t['legacy_record']===null;
$required=$t['required_by'] ? substr($t['required_by'],0,10) : '';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Task #<?=m7h($id)?> · Cloake</title><link rel="stylesheet" href="assets/app.css"><style>main.m7{max-width:740px;margin:2rem auto;padding:0 1rem 5rem}.m7 label{display:block;margin:1rem 0 .3rem;font-weight:600}.m7 input,.m7 textarea,.m7 select{display:block;width:100%;max-width:100%;padding:.7rem;border:1px solid #b6bcb9;border-radius:7px;background:white;color:#172720}.m7 textarea{min-height:100px}.m7 button{margin-top:1.5rem;padding:.85rem 1.5rem;border-radius:8px;background:#263e34;color:white;border:0}.m7 .error{color:#a32}.m7 .ok{color:#176338}</style></head><body><main class="m7"><p><a href="index.php?page=tasks">← Tasks</a> · <a href="index.php?page=task&amp;id=<?=m7h($id)?>">Task details</a><?php if($t['enquiry_id']):?> · <a href="convert-enquiry.php?id=<?=m7h($t['enquiry_id'])?>">Original enquiry</a><?php endif;?></p><p class="eyebrow">PRIVATE STAGING</p><h1>Task #<?=m7h($id)?></h1><p><strong>Client:</strong> <?=m7h($t['client_name'])?></p><?php if($error):?><p class="error" role="alert"><?=m7h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p class="ok">Saved successfully.</p><?php endif;?><?php if(!$editable):?><p>This imported or historical task remains read-only. No fields have been changed.</p><?php else:?><form method="post"><input type="hidden" name="id" value="<?=m7h($id)?>"><input type="hidden" name="csrf" value="<?=m7h(m7csrf())?>"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=m7h($t['title'])?>"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=m7h($t['instructions'])?></textarea><label for="status">Status</label><select id="status" name="status"><?php foreach(['open'=>'Open','in_progress'=>'In progress','waiting'=>'Waiting','completed'=>'Completed'] as $v=>$label):?><option value="<?=m7h($v)?>" <?=$t['status']===$v?'selected':''?>><?=m7h($label)?></option><?php endforeach;?></select><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach(['low','normal','high','urgent'] as $v):?><option value="<?=m7h($v)?>" <?=$t['priority']===$v?'selected':''?>><?=ucfirst($v)?></option><?php endforeach;?></select><label for="required">Required by</label><input id="required" name="required_by" type="date" value="<?=m7h($required)?>"><details><summary>Additional notes</summary><label for="notes">Notes</label><textarea id="notes" name="notes"><?=m7h($t['notes'])?></textarea></details><button type="submit">Save task</button></form><?php endif;?></main><div class="release-indicator" aria-label="Application version">v<?=htmlspecialchars(CLOAKE_VERSION, ENT_QUOTES, 'UTF-8')?> <span>· build <?=htmlspecialchars(CLOAKE_BUILD, ENT_QUOTES, 'UTF-8')?></span></div></body></html>
