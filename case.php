<?php
require __DIR__.'/src/bootstrap.php';

function case_date(?string $value, string $format='M j, Y g:i a'): string {
    if (!$value) return '—';
    $time=strtotime($value);
    return $time?date($format,$time):$value;
}
function search_status(array $case,int $number): string {
    $status=(string)($case['search_'.$number.'_status']??'');
    if ($status!=='') return $status;
    if (!empty($case['search_'.$number.'_at'])) return 'not_found';
    return '';
}
function search_label(string $status): string {
    return $status==='complete'?'Complete':($status==='not_found'?'Not found':'Pending');
}
function search_final_number(array $case): int {
    for($n=1;$n<=4;$n++) {
        if (search_status($case,$n)==='complete') return $n;
    }
    if ($case['stage']==='closed'&&$case['resolution']==='not_found') for($n=4;$n>=1;$n--) if (!empty($case['search_'.$n.'_at'])) return $n;
    if ($case['stage']==='closed'&&$case['resolution']==='complete') for($n=4;$n>=1;$n--) if (!empty($case['search_'.$n.'_at'])) return $n;
    return 4;
}

$partnerToken=trim((string)($_GET['partner']??''));
if($partnerToken!==''){
    $q=db()->prepare("SELECT c.*,i.name institution_name,i.slug institution_slug FROM claims_cases c JOIN institutions i ON i.id=c.institution_id WHERE c.partner_token_hash=? AND c.stage<>'closed' AND (c.partner_token_expires_at IS NULL OR c.partner_token_expires_at>UTC_TIMESTAMP()) LIMIT 1");
    $q->execute([hash('sha256',$partnerToken)]);$c=$q->fetch();
    if(!$c){http_response_code(404);exit('Partner link is invalid or expired.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrf_check();$status=(string)($_POST['partner_status']??'');
        if(!in_array($status,['in_progress','complete','not_found'],true)){flash('Choose a valid partner search status.','error');redirect('case.php?partner='.rawurlencode($partnerToken));}
        $done=in_array($status,['complete','not_found'],true);$u=db()->prepare('UPDATE claims_cases SET partner_status=?,partner_completed_at=? WHERE id=?');$u->execute([$status,$done?gmdate('Y-m-d H:i:s'):null,$c['id']]);
        event((int)$c['id'],'partner_status','Partner search status changed to '.str_replace('_',' ',$status));$state=$status==='in_progress'?'The lending library has started its parallel search.':($status==='complete'?'The lending library completed its parallel search.':'The lending library completed its search and did not locate the item.');notice_message($c,$state);flash('Partner search status updated.');redirect('case.php?partner='.rawurlencode($partnerToken));
    }
    render_start('Partner search');echo '<section class="card"><p class="eyebrow">Partner search · '.h($c['institution_name']).'</p><h1>'.h($c['item_title']).'</h1><p>'.h($c['item_author']??'').' · Barcode '.h($c['item_barcode']??'').'</p><p>Current status: <span class="badge">'.h(str_replace('_',' ',$c['partner_status'])).'</span></p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Update search status<select name="partner_status"><option value="in_progress">Search in progress</option><option value="complete">Search completed</option><option value="not_found">Not found</option></select></label><button>Save status</button></form></section>';render_end();exit;
}

require_login();
$id=(int)($_GET['id']??0);$case=$id?case_by_id($id):null;
if($id&&!$case){http_response_code(404);exit('Case not found.');}
if($case&&!can_edit()){http_response_code(403);exit('Access denied.');}

$message='';$patronFound=false;$patronNotes=[];$loans=[];$openCaseByBarcode=[];$selectedItem=false;$editSearch=(int)($_GET['edit_search']??0);$selectedInstitutionId=can_manage_all_submissions()?(int)($_POST['institution_id']??tenant_id()):tenant_id();

function populate_patron(string $patronId): array {
    $r=alma_lookup_user(user()['institution_slug'],$patronId);
    if(!$r['ok']) return [null,$r['error']];
    $p=$r['data'];$fullName=$p['full_name']??trim((string)($p['first_name']??'').' '.(string)($p['last_name']??''));$_POST['patron_name']=trim((string)$fullName);$_POST['patron_email']='';
    $emails=$p['contact_info']['email']??[];if(isset($emails['email_address']))$emails=[$emails];
    foreach((array)$emails as $email)if(!empty($email['email_address'])){$_POST['patron_email']=$email['email_address'];if(filter_var($email['preferred']??false,FILTER_VALIDATE_BOOLEAN))break;}
    $_POST['patron_primary_id']=$p['primary_id']??$patronId;return [$p,''];
}
function alma_user_notes(array $patron): array {
    $notes=$patron['user_notes']['user_note']??[];
    if(!is_array($notes))return [];
    if(isset($notes['note_text']))$notes=[$notes];
    $out=[];
    foreach($notes as $note){
        if(!is_array($note))continue;
        $text=trim((string)($note['note_text']??''));if($text==='')continue;
        $type=$note['note_type']??'';if(is_array($type))$type=$type['desc']??$type['value']??'';
        $out[]=['text'=>$text,'type'=>trim((string)$type),'created_date'=>trim((string)($note['created_date']??''))];
    }
    return $out;
}
function populate_item(string $barcode,string $patronMember=''): array {
    $r=alma_lookup_item(user()['institution_slug'],$barcode);if(!$r['ok'])return [null,$r['error']];
    $i=$r['data']['item']??$r['data'];$d=$i['item_data']??[];$b=$i['bib_data']??[];
    $_POST['item_title']=$b['title']??$d['title']??'';$_POST['item_author']=$b['author']??'';$_POST['item_mms_id']=$b['mms_id']??'';$_POST['item_barcode']=$d['barcode']??$barcode;
    $loan=alma_lookup_loan((string)($_POST['patron_primary_id']??''),$_POST['item_barcode'],$patronMember);
    if($loan){$_POST['item_loan_status']=$loan['loan_status']??'ACTIVE';$_POST['item_loan_due_date']=$loan['due_date']??'';$_POST['item_loan_date']=$loan['loan_date']??'';$_POST['item_process_status']=$loan['process_status']??'';$_POST['item_loan_library']=is_array($loan['library']??null)?($loan['library']['desc']??$loan['library']['value']??''):(string)($loan['library']??'');$_POST['item_loan_location']=is_array($loan['location_code']??null)?($loan['location_code']['name']??$loan['location_code']['value']??''):(string)($loan['location_code']??'');$_POST['item_call_number']=$loan['call_number']??'';}
    return [$i,''];
}
function case_institution_field(int $selectedId): string {
    if(!can_manage_all_submissions())return '';
    $institutions=db()->query('SELECT id,name FROM institutions WHERE active=1 ORDER BY name')->fetchAll();$html='<label>Institution<select name="institution_id" required>';
    foreach($institutions as $institution)$html.='<option value="'.(int)$institution['id'].'" '.($selectedId===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';
    return $html.'</select></label>';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=(string)($_POST['action']??'');
    if($action==='lookup'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        [$p,$message]=populate_patron(trim((string)($_POST['patron_primary_id']??'')));
        if($p){$patronFound=true;$patronNotes=alma_user_notes($p);$loans=alma_lookup_loans((string)$_POST['patron_primary_id'],(string)($p['_alma_member']??''));if($loans){$barcodes=array_values(array_filter(array_column($loans,'item_barcode')));$marks=implode(',',array_fill(0,count($barcodes),'?'));$targetInstitution=can_manage_all_submissions()?(int)($_POST['institution_id']??tenant_id()):tenant_id();$q=db()->prepare("SELECT id,public_id,item_barcode FROM claims_cases WHERE institution_id=? AND stage<>'closed' AND item_barcode IN ($marks)");$q->execute(array_merge([$targetInstitution],$barcodes));foreach($q->fetchAll() as $open)$openCaseByBarcode[(string)$open['item_barcode']]=$open;}}
    }elseif($action==='select_item'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        [$p,$message]=populate_patron(trim((string)($_POST['patron_primary_id']??'')));
        if($p){$patronNotes=alma_user_notes($p);[$item,$message]=populate_item(trim((string)($_POST['item_barcode']??'')),(string)($p['_alma_member']??''));$selectedItem=(bool)$item;}
    }elseif($action==='create'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        $institutionId=can_manage_all_submissions()?(int)($_POST['institution_id']??0):tenant_id();$validInstitution=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$validInstitution->execute([$institutionId]);if(!$validInstitution->fetchColumn()){flash('Choose an active institution for this submission.','error');redirect('case.php');}
        $patronId=trim((string)($_POST['patron_primary_id']??''));$patronName=trim((string)($_POST['patron_name']??''));$title=trim((string)($_POST['item_title']??''));$loan=(string)($_POST['loan_type']??'local');$channel=(string)($_POST['patron_notice_channel']??'email');
        if(!$patronId||!$patronName||!$title||!in_array($loan,['local','CLS','P2P','ILL'],true)||!in_array($channel,['email','sms','both','none'],true)){flash('Enter the patron ID, name, item title, and valid workflow options.','error');redirect('case.php');}
        $public=strtoupper(bin2hex(random_bytes(6)));$partnerEmail=trim((string)($_POST['partner_email']??''));$partnerPhone=trim((string)($_POST['partner_phone']??''));$ptoken=$loan==='local'?null:bin2hex(random_bytes(32));$pstatus=$loan==='local'?'not_required':'requested';$interval=max(1,min(30,(int)(user()['search_interval_days']??7)));$next=trim((string)($_POST['next_action_at']??''));if(!$next)$next=gmdate('Y-m-d',strtotime('+'.$interval.' days'));
        $q=db()->prepare("INSERT INTO claims_cases(public_id,institution_id,created_by,patron_primary_id,patron_name,patron_email,patron_phone,item_barcode,item_mms_id,item_title,item_author,loan_type,partner_name,partner_email,partner_phone,partner_status,partner_requested_at,partner_token_hash,partner_token_expires_at,stage,next_action_at,opened_at,patron_notice_channel) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)");
        $q->execute([$public,$institutionId,user()['id'],$patronId,$patronName,trim((string)($_POST['patron_email']??'')),trim((string)($_POST['patron_phone']??'')),trim((string)($_POST['item_barcode']??'')),trim((string)($_POST['item_mms_id']??'')),$title,trim((string)($_POST['item_author']??'')),$loan,trim((string)($_POST['partner_name']??'')),$partnerEmail,$partnerPhone,$pstatus,$loan==='local'?null:gmdate('Y-m-d H:i:s'),$ptoken?hash('sha256',$ptoken):null,$ptoken?gmdate('Y-m-d H:i:s',strtotime('+90 days')):null,'search_1',$next,$channel]);$newId=(int)db()->lastInsertId();event($newId,'created','Claims Returned case opened',['public_id'=>$public,'loan_type'=>$loan]);$case=case_by_id($newId);
        $note='Claims Returned claim opened '.$case['opened_at'].'; case '.$public.'; item: '.$title.'.';$nr=alma_append_claim_note($case,$note);event($newId,'alma_note',$nr['ok']?'Added initial Alma user note':'Initial Alma note not updated',['ok'=>$nr['ok'],'skipped'=>$nr['skipped']??false,'error'=>$nr['error']??'']);notice_message($case,'We have opened a Claims Returned search for this item. Staff will complete four searches over time and contact you with the final outcome.');
        if($loan!=='local'&&$partnerEmail){$link=envv('APP_URL').'/case.php?partner='.rawurlencode($ptoken);$body="A Claims Returned search is requested for {$title}.
Barcode: ".($case['item_barcode']??'')."
Please update progress using this secure link: {$link}

Case: {$public}";notify_case($case,'partner_request','Partner library search requested: '.$title,$body,$partnerEmail,$partnerPhone);}
        flash('Case '.$public.' created.');redirect('case.php?id='.$newId);
    }elseif($action==='save_search'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $n=(int)($_POST['search_number']??0);$status=(string)($_POST['search_status']??'');$note=trim((string)($_POST['search_note']??''));
        if($n===0&&$case['stage']==='search_1')$n=1;
        if($n<1||$n>4||!in_array($status,['not_found','complete'],true)){flash('Choose a valid search status.','error');redirect('case.php?id='.$id.'&edit_search='.$n);}
        $oldStatus=search_status($case,$n);$isFinal=$status==='complete'||($n===4&&$status==='not_found');$nextStage=$isFinal?'closed':($n<4?'search_'.($n+1):'closed');$resolution=$isFinal?($status==='complete'?'complete':'not_found'):'';$closed=$isFinal?gmdate('Y-m-d H:i:s'):null;$next=$isFinal?null:gmdate('Y-m-d',strtotime('+'.max(1,min(30,(int)(user()['search_interval_days']??7))).' days'));
        $q=db()->prepare("UPDATE claims_cases SET search_{$n}_at=COALESCE(search_{$n}_at,UTC_TIMESTAMP()),search_{$n}_by=?,search_{$n}_result=?,search_{$n}_status=?,stage=?,resolution=?,resolution_note=?,closed_at=?,next_action_at=? WHERE id=? AND institution_id=?");$q->execute([user()['id'],$note,$status,$nextStage,$resolution,$isFinal?$note:null,$closed,$next,$id,(int)$case['institution_id']]);
        $case=case_by_id($id);event($id,'search_'.$n,($status==='complete'?'Search '.$n.' completed: item found':'Search '.$n.' completed: item not found'),['note'=>$note,'previous_status'=>$oldStatus]);
        $nr=alma_append_claim_note($case,'Claims Returned Search '.$n.' '.$status.' '.gmdate('Y-m-d').($note?'; '.$note:'').'; case '.$case['public_id'].'.');event($id,'alma_note',$nr['ok']?'Updated Alma user note':'Alma note update not completed',['ok'=>$nr['ok'],'skipped'=>$nr['skipped']??false,'error'=>$nr['error']??'']);
        if($status==='complete')$notificationResults=notice_message($case,'The item was found during Search '.$n.'. Your account has been updated.');elseif($isFinal)$notificationResults=notice_message($case,'The item was not found after four searches. Any applicable Lost Item Replacement charges remain in effect.');else $notificationResults=notice_message($case,'Search '.$n.' did not locate the item. The Claims Returned search remains active.');
        $smsFailures=array_values(array_filter($notificationResults,static fn(array $result): bool=>($result['channel']??'')==='sms'&&!($result['ok']??false)));
        if($smsFailures){$smsError=(string)($smsFailures[0]['error']??'Delivery failed.');flash(($isFinal?'Case completed.':'Search '.$n.' saved.').' Text message failed: '.$smsError,'error');}else flash($isFinal?'Case completed.':'Search '.$n.' saved.');
        redirect('case.php?id='.$id);
    }elseif($action==='reopen'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $nextStage='resolution';for($n=1;$n<=4;$n++)if(empty($case['search_'.$n.'_at'])){$nextStage='search_'.$n;break;}$next=$nextStage==='resolution'?null:gmdate('Y-m-d',strtotime('+'.max(1,min(30,(int)(user()['search_interval_days']??7))).' days'));$q=db()->prepare("UPDATE claims_cases SET stage=?,resolution='',resolution_note=NULL,closed_at=NULL,next_action_at=?,partner_status=IF(partner_status='complete','in_progress',partner_status) WHERE id=? AND institution_id=?");$q->execute([$nextStage,$next,$id,(int)$case['institution_id']]);$case=case_by_id($id);event($id,'reopened','Case reopened',['stage'=>$nextStage]);notice_message($case,'The Claims Returned case has been reopened and the search will continue.');flash('Case reopened.');redirect('case.php?id='.$id);
    }elseif($action==='update'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $assign=(int)($_POST['assigned_to']??0);$loanType=(string)($_POST['loan_type']??$case['loan_type']);if(!in_array($loanType,['local','CLS','P2P','ILL'],true)){flash('Choose a valid case type.','error');redirect('case.php?id='.$id);}if($assign){$check=db()->prepare('SELECT id FROM users WHERE id=? AND institution_id=? AND active=1');$check->execute([$assign,(int)$case['institution_id']]);if(!$check->fetchColumn()){flash('Choose an active staff account from this institution.','error');redirect('case.php?id='.$id);}}$q=db()->prepare('UPDATE claims_cases SET assigned_to=?,next_action_at=?,partner_status=?,loan_type=? WHERE id=? AND institution_id=?');$q->execute([$assign?:null,trim((string)($_POST['next_action_at']??''))?:null,(string)($_POST['partner_status']??$case['partner_status']),$loanType,$id,(int)$case['institution_id']]);event($id,'case_updated','Case assignment, case type, or next action updated',['loan_type'=>$loanType]);flash('Case updated.');redirect('case.php?id='.$id);
    }elseif($action==='update_contact'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $email=trim((string)($_POST['patron_email']??''));$phone=trim((string)($_POST['patron_phone']??''));$channel=(string)($_POST['patron_notice_channel']??'email');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('Enter a valid patron email address or leave it blank.','error');redirect('case.php?id='.$id);}
        if(!in_array($channel,['email','sms','both','none'],true)){flash('Choose a valid patron contact preference.','error');redirect('case.php?id='.$id);}
        $q=db()->prepare('UPDATE claims_cases SET patron_email=?,patron_phone=?,patron_notice_channel=? WHERE id=? AND institution_id=?');$q->execute([$email?:null,$phone?:null,$channel,$id,(int)$case['institution_id']]);event($id,'contact_updated','Patron contact information or notification preference updated');flash('Patron contact information updated.');redirect('case.php?id='.$id);
    }
}

if(!$case&&$id){http_response_code(404);exit('Case not found.');}
$caseTypeOptions='';if($case){foreach(['local'=>'Local','CLS'=>'CLS','P2P'=>'P2P','ILL'=>'ILL'] as $value=>$label)$caseTypeOptions.='<option value="'.h($value).'"'.($case['loan_type']===$value?' selected':'').'>'.h($label).'</option>';}
ob_start(static function(string $output) use ($case,$caseTypeOptions): string {
    $output=str_replace('Save Search 0','Save Search 1',$output);
    if($case){$output=str_replace('</select></label><label>Next search date','</select></label><label>Case type<select name="loan_type">'.$caseTypeOptions.'</select></label><label>Next search date',$output);$output=str_replace('<section class="card history"><h2>Activity history</h2><ul>','<details class="card history"><summary>Activity history</summary><ul>',$output);$output=str_replace('</ul></section>','</ul></details>',$output);}
    return $output;
});
render_start($case?'Case '.$case['public_id']:'New case');
if(!$case){
    if(!$selectedItem){
        echo '<section class="card"><h2>Find patron</h2><p class="muted">Enter the patron identifier first, then choose the checked-out item to report missing.</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="lookup">'.case_institution_field($selectedInstitutionId).'<label>Patron primary ID<input name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'" required autofocus></label><button>Look up patron</button></form></section>';
        if($patronFound){if($patronNotes){echo '<section class="card"><h2>Patron notes</h2><ul>';foreach($patronNotes as $note){echo '<li>';if($note['type']!=='')echo '<strong>'.h($note['type']).'</strong><br>';echo '<p style="white-space:pre-wrap">'.h($note['text']).'</p>';if($note['created_date']!=='')echo '<small>Added '.h($note['created_date']).'</small>';echo '</li>';}echo '</ul></section>'; }echo '<section class="card"><h2>Checked-out items for '.h($_POST['patron_name']??$_POST['patron_primary_id']??'').'</h2>';if($loans){echo '<div class="table-wrap"><table><thead><tr><th>Open case</th><th>Title</th><th>Barcode</th><th>Call number</th><th>Due date</th><th>Library</th></tr></thead><tbody>';foreach($loans as $loan){$open=$openCaseByBarcode[(string)($loan['item_barcode']??'')]??null;$due=$loan['due_date']??'';$dueTs=$due?strtotime($due):false;$library=is_array($loan['library']??null)?($loan['library']['desc']??$loan['library']['value']??''):(string)($loan['library']??'');echo '<tr><td>'.($open?'<a href="case.php?id='.(int)$open['id'].'">View '.$open['public_id'].'</a>':'—').'</td><td><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="select_item"><input type="hidden" name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'"><input type="hidden" name="institution_id" value="'.$selectedInstitutionId.'"><button class="secondary" name="item_barcode" value="'.h($loan['item_barcode']??'').'">'.h($loan['title']??'Untitled item').'</button></form></td><td>'.h($loan['item_barcode']??'').'</td><td>'.h($loan['call_number']??'').'</td><td>'.h($dueTs?date('M j, Y g:i a',$dueTs):$due).'</td><td>'.h($library).'</td></tr>';}echo '</tbody></table></div>';}else echo '<p class="empty">No active checked-out items were found for this patron.</p>';echo '</section>';}
    }else{
        echo '<div class="page-title"><div><p class="eyebrow">'.h(user()['institution_name']).'</p><h1>Open a Claims Returned case</h1></div><a href="index.php">← Queue</a></div><form method="post" class="card form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create">'.case_institution_field($selectedInstitutionId).'<div class="form-section"><h2>Patron and item</h2><div class="two"><label>Patron ID<input name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'" readonly></label><label>Item barcode<input name="item_barcode" value="'.h($_POST['item_barcode']??'').'" readonly></label></div><label>Patron name<input name="patron_name" value="'.h($_POST['patron_name']??'').'" required></label><div class="two"><label>Patron email<input type="email" name="patron_email" value="'.h($_POST['patron_email']??'').'"></label><label>Patron mobile (E.164 for SMS)<input type="tel" name="patron_phone" placeholder="+12025550123"></label></div><label>Item title<input name="item_title" value="'.h($_POST['item_title']??'').'" required></label><div class="two"><label>Author<input name="item_author" value="'.h($_POST['item_author']??'').'"></label><label>Alma MMS ID<input name="item_mms_id" value="'.h($_POST['item_mms_id']??'').'"></label></div></div><div class="form-section"><h2>Workflow and notifications</h2><div class="two"><label>Loan channel<select name="loan_type"><option value="local">Local</option><option value="CLS">CLS</option><option value="P2P">P2P</option><option value="ILL">ILL</option></select></label><label>Patron notices<select name="patron_notice_channel"><option value="email">Email</option><option value="sms">Text message</option><option value="both">Email and text</option><option value="none">Manual / none</option></select></label></div><label>First search due date<input type="date" name="next_action_at" value="'.h(gmdate('Y-m-d',strtotime('+7 days'))).'" required></label><div class="two"><label>Partner library<input name="partner_name"></label><label>Partner email<input type="email" name="partner_email"></label></div><label>Partner phone<input type="tel" name="partner_phone"></label></div><button>Create case and start search</button></form>';if($message)echo '<p class="flash error">'.h($message).'</p>';
    }
}else{
    $events=db()->prepare('SELECT e.*,u.display_name FROM case_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.case_id=? ORDER BY e.created_at DESC');$events->execute([$id]);$history=$events->fetchAll();$staffq=db()->prepare('SELECT id,display_name FROM users WHERE institution_id=? AND active=1 ORDER BY display_name');$staffq->execute([(int)$case['institution_id']]);$staff=$staffq->fetchAll();$finalSearch=search_final_number($case);$visibleSteps=$case['stage']==='closed'?$finalSearch:4;
    echo '<div class="page-title"><div><p class="eyebrow">'.h($case['institution_name']).' · '.h($case['public_id']).'</p><h1>'.h($case['item_title']).'</h1><p class="muted">'.h($case['patron_name']).' · '.h($case['patron_primary_id']).'</p></div><a href="index.php">← Queue</a></div><div class="detail-grid"><section class="card"><h2>Case progress</h2><ol class="steps">';
    for($n=1;$n<=$visibleSteps;$n++){$status=search_status($case,$n);$done=!empty($case['search_'.$n.'_at']);echo '<li class="'.($done?'done':'').'"><span class="step-edit">';if(can_edit())echo '<a class="button secondary edit-search" href="case.php?id='.$id.'&edit_search='.$n.'">Edit</a>';echo '</span><strong>Search '.$n.'</strong><span class="step-status">'.h(search_label($status)).'</span>';if($done)echo '<small>'.h($case['search_'.$n.'_result']??'').'</small>';echo '</li>';}
    echo '<li class="'.($case['stage']==='closed'?'done':'').'"><span class="step-edit"></span><strong>Final resolution</strong><span class="step-status">'.h($case['resolution']?search_label($case['resolution']):'Pending').'</span>';if($case['resolution_note'])echo '<small>'.h($case['resolution_note']).'</small>';echo '</li></ol><p><strong>Partner:</strong> '.h($case['loan_type']).' · '.h(str_replace('_',' ',$case['partner_status'])).'</p><p><strong>Next action:</strong> '.h($case['next_action_at']?:'—').'</p></section><section class="card"><h2>'.($editSearch?'Edit Search '.$editSearch:'Staff action').'</h2>';
    if(can_edit()&&$editSearch>=1&&$editSearch<=4){$status=search_status($case,$editSearch)?:'not_found';echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_search"><input type="hidden" name="search_number" value="'.$editSearch.'"><label>Status<select name="search_status"><option value="not_found" '.($status==='not_found'?'selected':'').'>Not found</option><option value="complete" '.($status==='complete'?'selected':'').'>Complete — item found</option></select></label><label>Search notes<textarea name="search_note" rows="5">'.h($case['search_'.$editSearch.'_result']??'').'</textarea></label><button>Save search</button></form>';}elseif(can_edit()&&$case['stage']!=='closed'){$n=(int)substr((string)$case['stage'],6);echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_search"><input type="hidden" name="search_number" value="'.$n.'"><label>Status<select name="search_status"><option value="not_found">Not found — continue searching</option><option value="complete">Complete — item found</option></select></label><label>Search notes<textarea name="search_note" rows="5"></textarea></label><button>Save Search '.$n.'</button></form><hr><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Assigned staff<select name="assigned_to"><option value="">Unassigned</option>';foreach($staff as $s)echo '<option value="'.(int)$s['id'].'" '.((int)$case['assigned_to']===(int)$s['id']?'selected':'').'>'.h($s['display_name']).'</option>';echo '</select></label><input type="hidden" name="action" value="update"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Next search date<input type="date" name="next_action_at" value="'.h($case['next_action_at']?substr($case['next_action_at'],0,10):'').'"></label><label>Partner status<select name="partner_status">';foreach(['not_required','pending','requested','in_progress','complete','not_found'] as $s)echo '<option value="'.h($s).'" '.($case['partner_status']===$s?'selected':'').'>'.h(str_replace('_',' ',$s)).'</option>';echo '</select></label><button class="secondary">Save assignment</button></form>';}else{echo '<p>This case is closed.</p>';if(can_edit())echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="reopen"><button>Reopen case</button></form>';}
    echo '</section></div><section class="card history"><h2>Activity history</h2><ul>';foreach($history as $e)echo '<li><time>'.h(date('M j, Y g:i a',strtotime($e['created_at']))).'</time><strong>'.h($e['summary']).'</strong><span>'.h($e['display_name']??'System').'</span></li>';echo '</ul></section>';
    echo '<div class="detail-grid persistent-sections"><section class="card"><h2>Assigned staff</h2><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update"><label>Assigned staff<select name="assigned_to" '.(can_edit()?'':'disabled').'><option value="">Unassigned</option>';foreach($staff as $s)echo '<option value="'.(int)$s['id'].'" '.((int)$case['assigned_to']===(int)$s['id']?'selected':'').'>'.h($s['display_name']).'</option>';echo '</select></label><label>Next search date<input type="date" name="next_action_at" value="'.h($case['next_action_at']?substr($case['next_action_at'],0,10):'').'" '.(can_edit()?'':'disabled').'></label><label>Partner status<select name="partner_status" '.(can_edit()?'':'disabled').'>';foreach(['not_required','pending','requested','in_progress','complete','not_found'] as $s)echo '<option value="'.h($s).'" '.($case['partner_status']===$s?'selected':'').'>'.h(str_replace('_',' ',$s)).'</option>';echo '</select></label>';if(can_edit())echo '<button class="secondary">Save assignment</button>';echo '</form></section><section class="card"><h2>Patron contact</h2><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update_contact"><label>Email<input type="email" name="patron_email" value="'.h($case['patron_email']??'').'" '.(can_edit()?'':'disabled').'></label><label>Mobile phone<input type="tel" name="patron_phone" value="'.h($case['patron_phone']??'').'" placeholder="+12025550123" '.(can_edit()?'':'disabled').'></label><label>Notification preference<select name="patron_notice_channel" '.(can_edit()?'':'disabled').'><option value="email" '.($case['patron_notice_channel']==='email'?'selected':'').'>Email</option><option value="sms" '.($case['patron_notice_channel']==='sms'?'selected':'').'>Text message</option><option value="both" '.($case['patron_notice_channel']==='both'?'selected':'').'>Email and text</option><option value="none" '.($case['patron_notice_channel']==='none'?'selected':'').'>Manual / none</option></select></label>';if(can_edit())echo '<button class="secondary">Save patron contact</button>';echo '</form></section></div>';
}
render_end();
