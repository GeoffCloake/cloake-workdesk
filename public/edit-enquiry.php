<?php
declare(strict_types=1);
/* Edit page (M7A write path, kept): only new, unconverted/enquiry-generated records are editable; changes are audited. */
require __DIR__ . '/inc/bootstrap.php';
cm_require_login();
$pdo = cm_pdo();
$id=m7id(); if (!$id) {http_response_code(400);exit('Invalid enquiry.');}
$error=''; $saved=false;
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        m7verify();
        $title=trim((string)($_POST['title']??''));
        $details=trim((string)($_POST['details']??''));
        $nextAction=trim((string)($_POST['next_action']??''));
        $waiting=trim((string)($_POST['waiting_for']??''));
        $priority=(string)($_POST['priority']??'normal');
        $required=m7date($_POST['required_by']??'');
        $followup=m7date($_POST['next_action_due']??'');
        if ($title==='' || mb_strlen($title)>255) throw new InvalidArgumentException('Title is required (maximum 255 characters).');
        if (!in_array($priority,['low','normal','high','urgent'],true)) throw new InvalidArgumentException('Invalid priority.');
        if (mb_strlen($waiting)>80) throw new InvalidArgumentException('Waiting for is too long.');
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT * FROM enquiries WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();
        if (!$old) throw new InvalidArgumentException('Enquiry not found.');
        $s=$pdo->prepare('SELECT COUNT(*) FROM tasks WHERE enquiry_id=?');$s->execute([$id]);
        if ($old['legacy_source_id']!==null || $old['status']!=='enquiry' || (int)$s->fetchColumn()>0) throw new InvalidArgumentException('This enquiry is historical or already progressed and cannot be edited here.');
        $s=$pdo->prepare('UPDATE enquiries SET title=?,details=?,priority=?,required_by=?,next_action=?,next_action_due=?,waiting_for=? WHERE id=?');
        $s->execute([$title,$details,$priority,$required,$nextAction?:null,$followup,$waiting?:null,$id]);
        $changes=[];
        foreach (['title'=>$title,'details'=>$details,'priority'=>$priority,'required_by'=>$required,'next_action'=>$nextAction?:null,'next_action_due'=>$followup,'waiting_for'=>$waiting?:null] as $field=>$new) {
            if ((string)($old[$field]??'')!==(string)($new??'')) $changes[$field]=['previous'=>$old[$field], 'current'=>$new];
        }
        if ($changes) {
            $s=$pdo->prepare("INSERT INTO activity_events (client_id,enquiry_id,event_kind,event_at,summary,payload) VALUES (?,?,'enquiry_updated',NOW(),?,?)");
            $s->execute([$old['client_id'],$id,'Updated enquiry fields: '.implode(', ',array_keys($changes)),json_encode($changes,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit(); header('Location: edit-enquiry.php?id='.$id.'&saved=1',true,303); exit;
    }
} catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error=$e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save the enquiry.';
    if (!($e instanceof InvalidArgumentException)) error_log('M7 enquiry update #'.$id.': '.$e->getMessage());
}
$s=$pdo->prepare('SELECT e.*,c.name AS client_name FROM enquiries e LEFT JOIN clients c ON c.id=e.client_id WHERE e.id=?');$s->execute([$id]);$e=$s->fetch();
if (!$e) {http_response_code(404);exit('Enquiry not found.');}
$s=$pdo->prepare('SELECT id FROM tasks WHERE enquiry_id=? LIMIT 1');$s->execute([$id]);$linked=$s->fetchColumn();
$editable=($e['legacy_source_id']===null && $e['status']==='enquiry' && !$linked);
cm_page_start('Edit enquiry #' . $id, 'enquiries', ['back' => ['convert-enquiry.php?id=' . $id, 'Enquiry #' . $id], 'bodyClass' => 'form-page']);
?>
<p><strong>Client:</strong> <?=m7h($e['client_name'] ?? 'Unassigned')?> · <strong>Status:</strong> <?=m7h(cm_status_label($e['status']))?></p>
<?php if ($error): ?><div class="alert" role="alert"><?=m7h($error)?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="success" role="status"><strong>Saved.</strong> <a href="convert-enquiry.php?id=<?=(int)$id?>">Back to enquiry</a></div><?php endif; ?>
<?php if (!$editable): ?><div class="panel"><p>This enquiry is historical or has already been converted, so editing is disabled here.</p><?php if ($linked): ?><p><a href="<?=h(route('task', ['id' => $linked]))?>">View linked task #<?=m7h($linked)?></a></p><?php endif; ?></div>
<?php else: ?>
<form method="post" class="form"><input type="hidden" name="id" value="<?=m7h($id)?>"><input type="hidden" name="csrf" value="<?=m7h(m7csrf())?>">
<div class="field"><label for="title">Title</label><input id="title" name="title" maxlength="255" required value="<?=m7h($e['title'])?>"></div>
<div class="field"><label for="details">Details</label><textarea id="details" name="details"><?=m7h($e['details'])?></textarea></div>
<div class="pair"><div class="field"><label for="priority">Priority</label><select name="priority" id="priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $v): ?><option value="<?=m7h($v)?>"<?=$e['priority'] === $v ? ' selected' : ''?>><?=ucfirst($v)?></option><?php endforeach; ?></select></div>
<div class="field"><label for="required">Required by</label><input id="required" type="date" name="required_by" value="<?=m7h($e['required_by'])?>"></div></div>
<details<?=($e['next_action'] || $e['next_action_due'] || $e['waiting_for']) ? ' open' : ''?>><summary>Next action and follow-up</summary><div class="field"><label for="next">Next action</label><textarea id="next" name="next_action"><?=m7h($e['next_action'])?></textarea></div><div class="pair"><div class="field"><label for="due">Follow-up date</label><input id="due" type="date" name="next_action_due" value="<?=m7h($e['next_action_due'])?>"></div><div class="field"><label for="waiting">Waiting for</label><input id="waiting" name="waiting_for" maxlength="80" value="<?=m7h($e['waiting_for'])?>"></div></div></details>
<button type="submit" class="save">Save enquiry</button></form>
<?php endif; ?>
<?php cm_page_end('enquiries');
