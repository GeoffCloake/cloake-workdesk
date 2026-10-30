<?php
declare(strict_types=1);
/* Edit page (M7A write path, kept): only new, unconverted/enquiry-generated records are editable; changes are audited. */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
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
cm_page_start('Edit task #' . $id, 'tasks', ['back' => [route('task', ['id' => $id]), 'Task details'], 'bodyClass' => 'form-page']);
?>
<p><strong>Client:</strong> <?=m7h($t['client_name'] ?? 'Unassigned')?><?php if ($t['enquiry_id']): ?> · <a href="convert-enquiry.php?id=<?=m7h($t['enquiry_id'])?>">Original enquiry #<?=(int)$t['enquiry_id']?></a><?php endif; ?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=m7h($error)?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="success" role="status"><strong>Saved.</strong> <a href="<?=h(route('task', ['id' => $id]))?>">Back to task</a></div><?php endif; ?>
<?php if (!$editable): ?><div class="panel"><p>This imported or historical task remains read-only. No fields have been changed.</p></div>
<?php else: ?>
<form method="post" class="form"><input type="hidden" name="id" value="<?=m7h($id)?>"><input type="hidden" name="csrf" value="<?=m7h(m7csrf())?>">
<div class="field"><label for="title">Task title</label><input id="title" name="title" maxlength="255" required value="<?=m7h($t['title'])?>"></div>
<div class="field"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions"><?=m7h($t['instructions'])?></textarea></div>
<div class="pair"><div class="field"><label for="status">Status</label><select id="status" name="status"><?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting', 'completed' => 'Completed'] as $v => $label): ?><option value="<?=m7h($v)?>"<?=$t['status'] === $v ? ' selected' : ''?>><?=m7h($label)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $v): ?><option value="<?=m7h($v)?>"<?=$t['priority'] === $v ? ' selected' : ''?>><?=ucfirst($v)?></option><?php endforeach; ?></select></div></div>
<div class="field"><label for="required">Required by</label><input id="required" name="required_by" type="date" value="<?=m7h($required)?>"></div>
<details<?=$t['notes'] ? ' open' : ''?>><summary>Additional notes</summary><div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?=m7h($t['notes'])?></textarea></div></details>
<button type="submit" class="save">Save task</button></form>
<?php endif; ?>
<?php cm_page_end('tasks');
