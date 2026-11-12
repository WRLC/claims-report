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
    render_start('Partner search');echo '<section class="card"><p class="eyebrow">Partner search · '.h($c['institution_name']).'</p><h1>'.h($c['item_title']).'</h1><p>'.h($c['item_author']??'').' · Barcode '.h($c['item_barcode']??'').'</p><p>Current status: <span class="badge">'.h(str_replace('_',' ',$c['partner_status'])).'</span></p></section>';render_end();exit;
}

require_login();
$id=(int)($_GET['id']??0);$case=$id?case_by_id($id):null;
if($id&&!$case){http_response_code(404);exit('Case not found.');}
if($case&&!can_edit()){http_response_code(403);exit('Access denied.');}

$message='';$patronFound=false;$patronNotes=[];$patronMember='';$patronInstitutionName='';$loans=[];$openCaseByBarcode=[];$selectedItem=false;$loanClassification=null;$editSearch=(int)($_GET['edit_search']??0);$selectedInstitutionId=can_manage_all_submissions()?(int)($_POST['institution_id']??tenant_id()):tenant_id();

function populate_patron(string $patronId): array {
    $r=alma_lookup_user(user()['institution_slug'],$patronId);
    if(!$r['ok']) return [null,$r['error']];
    $p=$r['data'];$fullName=$p['full_name']??trim((string)($p['first_name']??'').' '.(string)($p['last_name']??''));$_POST['patron_name']=trim((string)$fullName);$_POST['patron_email']='';
    $emails=$p['contact_info']['email']??[];if(isset($emails['email_address']))$emails=[$emails];
    foreach((array)$emails as $email)if(!empty($email['email_address'])){$_POST['patron_email']=$email['email_address'];if(filter_var($email['preferred']??false,FILTER_VALIDATE_BOOLEAN))break;}
    $_POST['patron_primary_id']=$p['primary_id']??$patronId;return [$p,''];
}
function alma_user_notes(array $patron,string $patronId): array {
    $notes=$patron['user_note']??$patron['user_notes']['user_note']??[];
    if(!is_array($notes))return [];
    if(isset($notes['note_text']))$notes=[$notes];
    $out=[];
    foreach($notes as $note){
        if(!is_array($note))continue;
        $text=trim((string)($note['note_text']??''));if($text==='')continue;
        $type=$note['note_type']??'';if(is_array($type))$type=$type['desc']??$type['value']??'';
        $out[]=['text'=>$text,'type'=>trim((string)$type),'created_date'=>trim((string)($note['created_date']??'')),'delete_key'=>hash('sha256',json_encode($note,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))];
    }
    $emailStatuses=alma_note_email_statuses($out,$patronId);
    foreach($out as $index=>&$note)$note['email_status']=$emailStatuses[$index]??['Internal update — no emails sent'];
    unset($note);
    return $out;
}
function populate_item(string $barcode,string $patronMember='',string $loanPrimaryId='',string $loanMember=''): array {
    $r=alma_lookup_item($patronMember?:user()['institution_slug'],$barcode);if(!$r['ok'])return [null,$r['error']];
    $i=$r['data']['item']??$r['data'];$i['_alma_member']=(string)($r['data']['_alma_member']??'');$d=$i['item_data']??[];$b=$i['bib_data']??[];
    $_POST['item_title']=$b['title']??$d['title']??'';$_POST['item_author']=$b['author']??'';$_POST['item_mms_id']=$b['mms_id']??'';$_POST['item_barcode']=$d['barcode']??$barcode;
    $loanMember=$loanMember?:$patronMember;$loanPrimaryId=$loanPrimaryId?:trim((string)($_POST['patron_primary_id']??''));
    $loan=alma_lookup_loan((string)($_POST['patron_primary_id']??''),$_POST['item_barcode'],$patronMember,$loanMember,$loanPrimaryId);
    $classification=null;
    if($loan){$_POST['item_loan_status']=$loan['loan_status']??'ACTIVE';$_POST['item_loan_due_date']=$loan['due_date']??'';$_POST['item_loan_date']=$loan['loan_date']??'';$_POST['item_process_status']=$loan['process_status']??'';$_POST['item_loan_library']=is_array($loan['library']??null)?($loan['library']['desc']??$loan['library']['value']??''):(string)($loan['library']??'');$_POST['item_loan_location']=is_array($loan['location_code']??null)?($loan['location_code']['name']??$loan['location_code']['value']??''):(string)($loan['location_code']??'');$_POST['item_call_number']=$loan['call_number']??'';$classification=alma_classify_selected_loan((string)($_POST['patron_primary_id']??''),$patronMember,$loan,$i,$loanMember,$loanPrimaryId);$_POST['loan_type']=$classification['category']??'';$_POST['partner_name']=$classification['partner_name'];$_POST['partner_email']=$classification['partner_email'];}
    else{$_POST['loan_type']='';$_POST['partner_name']='';$_POST['partner_email']='';}
    return [$i,'',$classification];
}
function case_institution_field(int $selectedId): string {
    if(!can_manage_all_submissions())return '';
    $institutions=db()->query('SELECT id,name FROM institutions WHERE active=1 ORDER BY name')->fetchAll();$html='<label>Institution<select name="institution_id" required>';
    foreach($institutions as $institution)$html.='<option value="'.(int)$institution['id'].'" '.($selectedId===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';
    return $html.'</select></label>';
}
function partner_institution_field(string $selected='',bool $disabled=false,bool $required=false): string {
    $institutions=db()->query('SELECT name,partner_email FROM institutions WHERE active=1 ORDER BY name')->fetchAll();
    $html='<label>Partner library<select name="partner_name"'.($disabled?' disabled':'').($required?' required':'').'><option value="">Select an institution or service</option>';$matched=false;
    foreach(['P2P','ILL'] as $service){$isSelected=$selected!==''&&strcasecmp(trim($selected),$service)===0;$matched=$matched||$isSelected;$html.='<option value="'.h($service).'"'.($isSelected?' selected':'').'>'.h($service).'</option>';}
    foreach($institutions as $institution){$name=(string)$institution['name'];$email=trim((string)($institution['partner_email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))$email='';$isSelected=$selected!==''&&strcasecmp(trim($selected),trim($name))===0;$matched=$matched||$isSelected;$html.='<option value="'.h($name).'" data-partner-email="'.h($email).'"'.($isSelected?' selected':'').'>'.h(institution_label($name)).'</option>';}
    if($selected!==''&&!$matched&&strcasecmp(trim($selected),'CLS')!==0)$html.='<option value="'.h($selected).'" selected>'.h($selected).' (existing value)</option>';
    return $html.'</select></label>';
}
function valid_cls_partner_library(string $name): bool {
    if(trim($name)==='')return false;
    $q=db()->prepare('SELECT 1 FROM institutions WHERE active=1 AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1');$q->execute([$name]);return (bool)$q->fetchColumn();
}
function partner_staff_contact(?array $staff): string {
    if(!$staff)return "Assigned staff contact: No staff member is currently assigned.";
    $lines=['Assigned staff contact:','Name: '.(string)$staff['display_name']];
    if(trim((string)($staff['email']??''))!=='')$lines[]='Email: '.trim((string)$staff['email']);
    if(trim((string)($staff['institution_name']??''))!=='')$lines[]='Institution: '.institution_label((string)$staff['institution_name']);
    return implode("\n",$lines);
}
function alma_institution_name(string $member): string {
    if($member==='')return '';
    $q=db()->prepare('SELECT name FROM institutions WHERE slug=? AND active=1 LIMIT 1');$q->execute([$member]);
    return (string)($q->fetchColumn()?:'');
}
function alma_institution_id(string $member,int $fallback): int {
    if(!can_manage_all_submissions()||$member==='')return $fallback;
    $q=db()->prepare('SELECT id FROM institutions WHERE slug=? AND active=1 LIMIT 1');$q->execute([$member]);
    return (int)($q->fetchColumn()?:$fallback);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=(string)($_POST['action']??'');
    if($action==='lookup'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        [$p,$message]=populate_patron(trim((string)($_POST['patron_primary_id']??'')));
        if($p){$patronFound=true;$patronMember=(string)($p['_alma_member']??'');$patronInstitutionName=alma_institution_name($patronMember);$patronNotes=alma_user_notes($p,(string)($_POST['patron_primary_id']??''));$selectedInstitutionId=alma_institution_id($patronMember,$selectedInstitutionId);$loans=alma_classify_patron_loans($p,$patronMember,alma_lookup_patron_network_loans($p,$patronMember));if($loans){$barcodes=array_values(array_filter(array_column($loans,'item_barcode')));$marks=implode(',',array_fill(0,count($barcodes),'?'));$targetInstitution=can_manage_all_submissions()?$selectedInstitutionId:tenant_id();$q=db()->prepare("SELECT id,public_id,item_barcode FROM claims_cases WHERE institution_id=? AND stage<>'closed' AND item_barcode IN ($marks)");$q->execute(array_merge([$targetInstitution],$barcodes));foreach($q->fetchAll() as $open)$openCaseByBarcode[(string)$open['item_barcode']]=$open;}}
    }elseif($action==='select_item'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        [$p,$message]=populate_patron(trim((string)($_POST['patron_primary_id']??'')));
        if($p){$patronMember=(string)($p['_alma_member']??'');$patronInstitutionName=alma_institution_name($patronMember);$patronNotes=alma_user_notes($p,(string)($_POST['patron_primary_id']??''));$selectedInstitutionId=alma_institution_id($patronMember,$selectedInstitutionId);[$item,$message,$loanClassification]=populate_item(trim((string)($_POST['item_barcode']??'')),$patronMember,trim((string)($_POST['loan_primary_id']??'')),trim((string)($_POST['loan_member']??'')));$selectedItem=(bool)$item;}
    }elseif($action==='create'){
        if(!can_edit()){http_response_code(403);exit('Access denied.');}
        $institutionId=can_manage_all_submissions()?(int)($_POST['institution_id']??0):tenant_id();$validInstitution=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$validInstitution->execute([$institutionId]);if(!$validInstitution->fetchColumn()){flash('Choose an active institution for this submission.','error');redirect('case.php');}
        $patronId=trim((string)($_POST['patron_primary_id']??''));$patronName=trim((string)($_POST['patron_name']??''));$title=trim((string)($_POST['item_title']??''));$loan=(string)($_POST['loan_type']??'local');$channel=isset($_POST['patron_notice_email'])?'email':'none';$partnerName=trim((string)($_POST['partner_name']??''));$partnerEmail=trim((string)($_POST['partner_email']??''));$partnerNotify=isset($_POST['partner_notify_email'])&&$loan!=='local';
        if(!$patronId||!$patronName||!$title||!in_array($loan,['local','CLS','P2P','ILL'],true)||($partnerEmail!==''&&!filter_var($partnerEmail,FILTER_VALIDATE_EMAIL))||($partnerNotify&&$loan!=='local'&&$partnerEmail==='')){flash('Enter the patron ID, name, item title, and valid workflow options. A partner email is required when partner notifications are enabled.','error');redirect('case.php');}
        if($loan==='CLS'&&!valid_cls_partner_library($partnerName)){flash('Choose a partner library institution for CLS cases.','error');redirect('case.php');}
        $assign=(int)($_POST['assigned_to']??0);$assignedStaff=null;
        if($assign){$assigneeQuery=db()->prepare('SELECT u.id,u.email,u.display_name,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.id=? AND u.active=1 AND i.active=1');$assigneeQuery->execute([$assign]);$assignedStaff=$assigneeQuery->fetch()?:null;if(!$assignedStaff){flash('Choose an active staff account.','error');redirect('case.php');}}
        $public=strtoupper(bin2hex(random_bytes(6)));$ptoken=$loan==='local'?null:bin2hex(random_bytes(32));$pstatus=$loan==='local'?'not_required':'requested';$search1Due=date('Y-m-d',strtotime('+1 week'));$search2Due=date('Y-m-d',strtotime('+2 weeks'));$search3Due=date('Y-m-d',strtotime('+3 weeks'));$search4Due=date('Y-m-d',strtotime('+4 weeks'));$next=$search1Due;
        $insertColumns=['public_id','institution_id','created_by','assigned_to','patron_primary_id','patron_name','patron_email','item_barcode','item_mms_id','item_title','item_author','loan_type','partner_name','partner_email','partner_notify_email','partner_status','partner_requested_at','partner_token_hash','partner_token_expires_at','stage','search_1_due_at','search_2_due_at','search_3_due_at','search_4_due_at','next_action_at','opened_at','patron_notice_channel'];
        $insertValues=[$public,$institutionId,user()['id'],$assign?:null,$patronId,$patronName,trim((string)($_POST['patron_email']??'')),trim((string)($_POST['item_barcode']??'')),trim((string)($_POST['item_mms_id']??'')),$title,trim((string)($_POST['item_author']??'')),$loan,$partnerName,$partnerEmail,$partnerNotify?1:0,$pstatus,$loan==='local'?null:gmdate('Y-m-d H:i:s'),$ptoken?hash('sha256',$ptoken):null,$ptoken?gmdate('Y-m-d H:i:s',strtotime('+90 days')):null,'search_1',$search1Due,$search2Due,$search3Due,$search4Due,$next,gmdate('Y-m-d H:i:s'),$channel];
        $q=db()->prepare('INSERT INTO claims_cases('.implode(',',$insertColumns).') VALUES('.implode(',',array_fill(0,count($insertValues),'?')).')');$q->execute($insertValues);$newId=(int)db()->lastInsertId();event($newId,'created','Claims Returned case opened',['public_id'=>$public,'loan_type'=>$loan,'auto_classification_category'=>trim((string)($_POST['loan_classification_category']??'')),'auto_classification_status'=>trim((string)($_POST['loan_classification_status']??'')),'auto_classification_reason'=>trim((string)($_POST['loan_classification_reason']??'')),'alma_lending_identifier'=>trim((string)($_POST['alma_lending_identifier']??''))]);$case=case_by_id($newId);
        if($assignedStaff){event($newId,'assigned','Case initially assigned to '.$assignedStaff['display_name']);notify_case_assignee($case,$assignedStaff);}
        $openingText='Thank you for letting us know you’re having trouble locating this item. Your library has opened a Claims Returned search, and staff will search for it over the coming weeks. While the search is underway, please continue checking places where the item may be, such as your home or office. We’ll contact you when the search reaches its final outcome. We appreciate your patience.';$nr=alma_append_claim_note($case,patron_notice_text($case,$openingText));event($newId,'alma_note',$nr['ok']?'Added initial patron notification to Alma user notes':'Initial Alma note not updated',['ok'=>$nr['ok'],'skipped'=>$nr['skipped']??false,'error'=>$nr['error']??'']);notice_message($case,$openingText);
        if($loan!=='local'&&$partnerNotify&&$partnerEmail){$link=app_url().'/case.php?partner='.rawurlencode($ptoken);$body="A Claims Returned search is requested for {$title}.
Barcode: ".($case['item_barcode']??'')."
Please update progress using this secure link: {$link}

Partner library: {$partnerName}
".partner_staff_contact($assignedStaff)."

Case: {$public}";notify_case($case,'partner_request','Partner library search requested: '.$title,$body,$partnerEmail);}
        flash('Case '.$public.' created.');redirect('case.php?id='.$newId.'&edit_search=1');
    }elseif($action==='save_search'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $n=(int)($_POST['search_number']??0);$status=(string)($_POST['search_status']??'');$note=trim((string)($_POST['search_note']??''));
        if($n===0&&$case['stage']==='search_1')$n=1;
        if($n<1||$n>4||!in_array($status,['not_found','complete'],true)){flash('Choose a valid search status.','error');redirect('case.php?id='.$id.'&edit_search='.$n);}
        $oldStatus=search_status($case,$n);$isFinal=$status==='complete'||($n===4&&$status==='not_found');$nextStage=$isFinal?'closed':($n<4?'search_'.($n+1):'closed');$resolution=$isFinal?($status==='complete'?'complete':'not_found'):'';$closed=$isFinal?gmdate('Y-m-d H:i:s'):null;$next=$isFinal?null:($case['search_'.($n+1).'_due_at']??gmdate('Y-m-d',strtotime('+'.($n+1).' weeks')));
        $q=db()->prepare("UPDATE claims_cases SET search_{$n}_at=COALESCE(search_{$n}_at,UTC_TIMESTAMP()),search_{$n}_by=?,search_{$n}_result=?,search_{$n}_status=?,stage=?,resolution=?,resolution_note=?,closed_at=?,next_action_at=? WHERE id=? AND institution_id=?");$q->execute([user()['id'],$note,$status,$nextStage,$resolution,$isFinal?$note:null,$closed,$next,$id,(int)$case['institution_id']]);$editedDue=trim((string)($_POST['search_due_date']??''));if($editedDue!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$editedDue)){$dueUpdate=db()->prepare("UPDATE claims_cases SET search_{$n}_due_at=? WHERE id=? AND institution_id=?");$dueUpdate->execute([$editedDue,$id,(int)$case['institution_id']]);}
        $case=case_by_id($id);event($id,'search_'.$n,($status==='complete'?'Search '.$n.' completed: item found':'Search '.$n.' completed: item not found'),['note'=>$note,'previous_status'=>$oldStatus]);
        $caseUrl=app_url().'/case.php?id='.(int)$case['id'];$almaNote='WRLC Claims Returned Case - '.$case['public_id']."\nSearch Notes: ".($note!==''?$note:'(No search notes entered)')."\nSearch: ".$n."\nCase URL: ".$caseUrl;$nr=alma_append_claim_note($case,$almaNote);event($id,'alma_note',$nr['ok']?'Updated Alma user note':'Alma note update not completed',['ok'=>$nr['ok'],'skipped'=>$nr['skipped']??false,'error'=>$nr['error']??'']);
        if($status==='complete'){$closureCheckin=alma_item_checkin_status($case);$closedText=match($closureCheckin['state']??'unknown'){'checked_in'=>'Good news—we found your item, and Alma now shows it as checked in. Your account has been updated. Thank you for your patience while we looked into this.','checked_out'=>'Good news—we found your item. Alma still shows it as checked out, so it may not yet be reflected on your account. Please contact your library so staff can confirm its return and help update your account.','not_confirmed'=>'Good news—we found your item. Alma does not currently confirm that it has been checked in. Please contact your library if your account still shows a charge or if you have questions.','unknown'=>'Good news—we found your item. We could not confirm its check-in status in Alma, so please contact your library if your account still shows a charge or if you have questions.',default=>'Good news—we found your item. Please contact your library if your account still shows a charge or if you have questions.'};$closedNote=alma_append_claim_note($case,patron_notice_text($case,$closedText));event($id,'alma_note',$closedNote['ok']?'Added case closure note to Alma':'Case closure note not added to Alma',['ok'=>$closedNote['ok'],'already_present'=>$closedNote['already_present']??false,'skipped'=>$closedNote['skipped']??false,'error'=>$closedNote['error']??'']);notice_message($case,$closedText);}elseif($isFinal){$closedText='Thank you for your patience while we searched for your item. Unfortunately, we were unable to locate it. Any applicable lost-item replacement charges remain in place. If you think this result is incorrect or have questions, please contact your library—we are happy to help.';$closedNote=alma_append_claim_note($case,patron_notice_text($case,$closedText));event($id,'alma_note',$closedNote['ok']?'Added case closure note to Alma':'Case closure note not added to Alma',['ok'=>$closedNote['ok'],'already_present'=>$closedNote['already_present']??false,'skipped'=>$closedNote['skipped']??false,'error'=>$closedNote['error']??'']);notice_message($case,$closedText);}
        flash($isFinal?'Case completed.':'Search '.$n.' saved.');
        redirect('case.php?id='.$id);
    }elseif($action==='reopen'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $nextStage='resolution';$nextSearch=0;for($n=1;$n<=4;$n++)if(empty($case['search_'.$n.'_at'])){$nextStage='search_'.$n;$nextSearch=$n;break;}$next=$nextStage==='resolution'?null:($case['search_'.$nextSearch.'_due_at']??gmdate('Y-m-d',strtotime('+'.$nextSearch.' weeks')));$q=db()->prepare("UPDATE claims_cases SET stage=?,resolution='',resolution_note=NULL,closed_at=NULL,next_action_at=?,partner_status=IF(partner_status='complete','in_progress',partner_status) WHERE id=? AND institution_id=?");$q->execute([$nextStage,$next,$id,(int)$case['institution_id']]);$case=case_by_id($id);event($id,'reopened','Case reopened',['stage'=>$nextStage]);notice_message($case,'The Claims Returned case has been reopened and the search will continue.');flash('Case reopened.');redirect('case.php?id='.$id);
    }elseif($action==='delete'){
        if(!$case||!can_manage_all_submissions()){http_response_code(403);exit('Access denied.');}
        if((string)($_POST['confirm_delete']??'')!=='DELETE'){flash('Type DELETE to confirm case removal.','error');redirect('case.php?id='.$id);}
        $q=db()->prepare('DELETE FROM claims_cases WHERE id=?');$q->execute([$id]);flash('Case '.$case['public_id'].' was deleted.');redirect('index.php');
    }elseif($action==='update'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $assign=(int)($_POST['assigned_to']??0);
        $loanType=(string)($_POST['loan_type']??$case['loan_type']);
        $partnerName=trim((string)($_POST['partner_name']??$case['partner_name']??''));
        $partnerEmail=trim((string)($_POST['partner_email']??$case['partner_email']??''));
        $partnerNotify=array_key_exists('partner_notify_email_present',$_POST)?isset($_POST['partner_notify_email']):(bool)($case['partner_notify_email']??false);
        if(!in_array($loanType,['local','CLS','P2P','ILL'],true)){flash('Choose a valid case type.','error');redirect('case.php?id='.$id);}
        if($loanType==='CLS'&&!valid_cls_partner_library($partnerName)){flash('Choose a partner library institution for CLS cases.','error');redirect('case.php?id='.$id);}
        if($partnerEmail!==''&&!filter_var($partnerEmail,FILTER_VALIDATE_EMAIL)){flash('Enter a valid partner email address or leave it blank.','error');redirect('case.php?id='.$id);}
        if($partnerNotify&&$loanType!=='local'&&$partnerEmail===''){flash('Enter a partner email address or turn off partner notifications.','error');redirect('case.php?id='.$id);}
        $assignedStaff=null;
        if($assign){$check=db()->prepare('SELECT u.id,u.email,u.display_name,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.id=? AND u.institution_id=? AND u.active=1 AND i.active=1');$check->execute([$assign,(int)$case['institution_id']]);$assignedStaff=$check->fetch()?:null;if(!$assignedStaff){flash('Choose an active staff account from this institution.','error');redirect('case.php?id='.$id);}}
        $assignmentChanged=(int)($case['assigned_to']??0)!==$assign;
        $hasDue=array_key_exists('search_due_date',$_POST);$dueNumber=(int)($_POST['search_due_number']??0);$dueDate=trim((string)($_POST['search_due_date']??''));
        if($dueNumber<1||$dueNumber>4)$dueNumber=(int)substr((string)$case['stage'],7);
        $nextAction=$dueDate?:$case['next_action_at'];
        if($hasDue&&$dueNumber>=1&&$dueNumber<=4){$q=db()->prepare('UPDATE claims_cases SET assigned_to=?,next_action_at=?,loan_type=?,partner_name=?,partner_email=?,partner_notify_email=?,search_'.$dueNumber.'_due_at=? WHERE id=? AND institution_id=?');$q->execute([$assign?:null,$nextAction,$loanType,$partnerName,$partnerEmail?:null,$partnerNotify?1:0,$dueDate?:null,$id,(int)$case['institution_id']]);}
        else{$q=db()->prepare('UPDATE claims_cases SET assigned_to=?,next_action_at=?,loan_type=?,partner_name=?,partner_email=?,partner_notify_email=? WHERE id=? AND institution_id=?');$q->execute([$assign?:null,$case['next_action_at'],$loanType,$partnerName,$partnerEmail?:null,$partnerNotify?1:0,$id,(int)$case['institution_id']]);}
        $updatedCase=case_by_id($id);
        event($id,'case_updated','Case assignment, case type, partner details, or search due date updated',['loan_type'=>$loanType,'partner_notify_email'=>$partnerNotify,'search_due_number'=>$dueNumber]);
        if($assignmentChanged&&$assignedStaff){event($id,'assigned','Case assigned to '.$assignedStaff['display_name']);notify_case_assignee($updatedCase,$assignedStaff);}
        $partnerDetailsChanged=$loanType!==$case['loan_type']||$partnerName!==($case['partner_name']??'')||$partnerEmail!==($case['partner_email']??'')||!(bool)($case['partner_notify_email']??false);
        if($partnerNotify&&$loanType!=='local'&&$partnerEmail&&($assignmentChanged||$partnerDetailsChanged)){
            $partnerToken=bin2hex(random_bytes(32));
            $tokenUpdate=db()->prepare('UPDATE claims_cases SET partner_token_hash=?,partner_token_expires_at=? WHERE id=?');
            $tokenUpdate->execute([hash('sha256',$partnerToken),gmdate('Y-m-d H:i:s',strtotime('+90 days')),$id]);
            $link=app_url().'/case.php?partner='.rawurlencode($partnerToken);
            $opening=$assignmentChanged?'The staff contact for this Claims Returned case has changed.':'A Claims Returned search is requested for '.$updatedCase['item_title'].'.';
            $body=$opening."\nPartner library: {$partnerName}\nBarcode: ".($updatedCase['item_barcode']??'')."\nPlease update progress using this secure link: {$link}\n\n".partner_staff_contact($assignedStaff)."\n\nCase: {$updatedCase['public_id']}";
            $subject=$assignmentChanged?'Claims Returned case contact updated: '.$updatedCase['item_title']:'Partner library search requested: '.$updatedCase['item_title'];
            notify_case($updatedCase,'partner_request',$subject,$body,$partnerEmail);
        }
        flash('Case updated.');redirect('case.php?id='.$id);
    }elseif($action==='update_contact'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $email=trim((string)($_POST['patron_email']??''));$channel=isset($_POST['patron_notice_email'])?'email':'none';
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('Enter a valid patron email address or leave it blank.','error');redirect('case.php?id='.$id);}
        if(!in_array($channel,['email','none'],true)){flash('Choose a valid patron contact preference.','error');redirect('case.php?id='.$id);}
        $q=db()->prepare('UPDATE claims_cases SET patron_email=?,patron_notice_channel=? WHERE id=? AND institution_id=?');$q->execute([$email?:null,$channel,$id,(int)$case['institution_id']]);event($id,'contact_updated','Patron contact information or notification preference updated');flash('Patron contact information updated.');redirect('case.php?id='.$id);
    }elseif($action==='add_patron_note'){
        if(!$case||!can_edit()){http_response_code(403);exit('Access denied.');}
        $customNote=trim((string)($_POST['custom_patron_note']??''));
        if($customNote===''){flash('Enter a note to add to Alma.','error');redirect('case.php?id='.$id);}
        $caseUrl=app_url().'/case.php?id='.(int)$case['id'];
        $almaNote='WRLC Claims Returned Case - '.$case['public_id']."\nCustom Patron Note: {$customNote}\n\nItem details:\nTitle: {$case['item_title']}\nBarcode: ".($case['item_barcode']?:'Not available')."\nAuthor: ".($case['item_author']?:'Not available')."\nMMS ID: ".($case['item_mms_id']?:'Not available')."\nCase: {$case['public_id']}\nCase URL: {$caseUrl}";
        if(mb_strlen($almaNote)>2000){flash('The note is too long once case and item details are included. Shorten the note and try again.','error');redirect('case.php?id='.$id);}
        $result=alma_append_claim_note($case,$almaNote);
        event($id,'alma_note',$result['ok']?'Added a custom patron note to Alma':'Custom patron note was not added to Alma',['ok'=>$result['ok'],'already_present'=>$result['already_present']??false,'error'=>$result['error']??'']);
        flash($result['ok']?'Custom patron note added to Alma.':'Could not add the patron note to Alma: '.($result['error']??'unknown API error'),$result['ok']?'success':'error');
        redirect('case.php?id='.$id);
    }
}

if(!$case&&$id){http_response_code(404);exit('Case not found.');}
$caseTypeOptions='';if($case){foreach(['local'=>'Local','CLS'=>'CLS','P2P'=>'P2P','ILL'=>'ILL'] as $value=>$label)$caseTypeOptions.='<option value="'.h($value).'"'.($case['loan_type']===$value?' selected':'').'>'.h($label).'</option>';}
$createLoanValue=(string)($_POST['loan_type']??'local');if($selectedItem&&(!$loanClassification||$loanClassification['status']!=='confirmed'))$createLoanValue='';
$createTypeField='<label>Case type<select name="loan_type" class="case-type-select case-type-'.case_type_key($createLoanValue).'" data-case-type-select>';if($createLoanValue==='')$createTypeField.='<option value="" selected>Select a case type</option>';foreach(['local'=>'Local','CLS'=>'CLS','P2P'=>'P2P','ILL'=>'ILL'] as $value=>$label)$createTypeField.='<option value="'.h($value).'"'.($createLoanValue===$value?' selected':'').'>'.h($label).'</option>';$createTypeField.='</select></label>';
$createPartnerEmail=trim((string)($_POST['partner_email']??''));$partnerNotifyDefault=$selectedItem?($createLoanValue!==''&&$createLoanValue!=='local'&&$createPartnerEmail!==''):true;
$createPartnerFields=partner_institution_field((string)($_POST['partner_name']??''),false,$createLoanValue==='CLS').'<label>Partner email<input id="partner-email-field" type="email" name="partner_email" value="'.h($createPartnerEmail).'"></label><label class="checkbox-label"><input type="checkbox" name="partner_notify_email" value="1"'.($partnerNotifyDefault?' checked':'').'> Email partner the search request</label>';
if($selectedItem&&$loanClassification){$hint='';if($loanClassification['status']==='confirmed')$hint='Alma classified this loan as '.strtoupper((string)$loanClassification['category']).'. '.$loanClassification['reason'];else $hint='Alma could not confirm the case type. Choose it manually. '.$loanClassification['reason'];if($loanClassification['category']!=='local'&&!empty($loanClassification['lending_identifier'])&&!$loanClassification['partner_matched'])$hint.=' Alma lending identifier: '.$loanClassification['lending_identifier'].'.';$createPartnerFields.='<p class="muted loan-classification-hint">'.h($hint).'</p><input type="hidden" name="loan_classification_category" value="'.h($loanClassification['category']??'').'"><input type="hidden" name="loan_classification_status" value="'.h($loanClassification['status']).'"><input type="hidden" name="loan_classification_reason" value="'.h($loanClassification['reason']).'"><input type="hidden" name="alma_lending_identifier" value="'.h($loanClassification['lending_identifier']).'">';}
$customPatronNoteForm='';
if($case&&can_edit())$customPatronNoteForm='<form method="post" class="custom-patron-note-form"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="add_patron_note"><label>Add a custom note to Alma<textarea name="custom_patron_note" rows="4" maxlength="1100" required></textarea></label><p class="muted">The note will include the case number and item details. It is saved as an internal update; no email is sent.</p><button>Add note to Alma</button></form>';
$partnerFields='';
if($case)$partnerFields=partner_institution_field((string)($case['partner_name']??''),!can_edit(),($case['loan_type']??'')==='CLS').'<label>Partner email<input type="email" name="partner_email" value="'.h($case['partner_email']??'').'"'.(can_edit()?'':' disabled').'></label><input type="hidden" name="partner_notify_email_present" value="1"><label class="checkbox-label"><input type="checkbox" name="partner_notify_email" value="1"'.(!empty($case['partner_notify_email'])?' checked':'').(can_edit()?'':' disabled').'> Send partner notifications by email</label>';
$createInstitutionField=$case? '':case_institution_field($selectedInstitutionId);
$createStaffField='';
if(!$case&&$selectedItem){
    $createStaffQuery=db()->prepare("SELECT u.id,u.display_name,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.active=1 AND i.active=1 ORDER BY CASE WHEN LOWER(i.name)='scf' THEN 'WRLC' ELSE i.name END,SUBSTRING_INDEX(TRIM(u.display_name),' ',-1),u.display_name");$createStaffQuery->execute();
    $createStaffField='<label>Assigned staff<select name="assigned_to"><option value="">Unassigned</option>';
    foreach($createStaffQuery->fetchAll() as $candidate)$createStaffField.='<option value="'.(int)$candidate['id'].'">'.h($candidate['display_name']).' — '.h(institution_label((string)$candidate['institution_name'])).'</option>';
    $createStaffField.='</select></label>';
}
ob_start(static function(string $output) use ($case,$caseTypeOptions,$createStaffField,$createInstitutionField,$customPatronNoteForm,$partnerFields,$createTypeField,$createPartnerFields): string {
    $output=str_replace('Save Search 0','Save Search 1',$output);
    if(!$case){
        $output=preg_replace('/<section class="card"><h2>Patron notes<\/h2>(.*?)<\/section>/s','<details class="card patron-notes-accordion"><summary>Patron notes</summary>$1</details>',$output,1);
        if($createInstitutionField!==''){
            $output=str_replace($createInstitutionField,'',$output);
            $output=str_replace('<div class="form-section"><h2>Patron Information</h2>','<div class="form-section"><h2>Patron Information</h2>'.$createInstitutionField,$output);
        }
        $output=str_replace('<form method="post" class="card form-grid">','<form id="new-case-form" method="post" class="card form-grid">',$output);
        $output=str_replace('<label>Loan channel<select name="loan_type"><option value="local">Local</option><option value="CLS">CLS</option><option value="P2P">P2P</option><option value="ILL">ILL</option></select></label>',$createTypeField,$output);
        $output=str_replace('<div class="form-section"><h2>Workflow and notifications</h2>','<div class="form-section workflow-section"><h2>Workflow and notifications</h2>'.$createStaffField,$output);
        $output=str_replace('<label>Patron notices<select name="patron_notice_channel"><option value="email">Email</option><option value="none">Manual / none</option></select></label>','',$output);
        $output=preg_replace_callback('/<div class="two">(<label>Patron email<input type="email" name="patron_email" value="[^"]*"><\/label>)<\/div>/',static fn($match)=>'<div class="two patron-email-row">'.$match[1].'<label class="checkbox-label"><input type="checkbox" name="patron_notice_email" value="1" checked> Email updates to patron</label></div>',$output,1);
        $output=str_replace('<div class="two"><label>Partner library<input name="partner_name"></label><label>Partner email<input type="email" name="partner_email"></label></div>',$createPartnerFields,$output);
    }
    if($case){$output=str_replace('</select></label><label>Next search date','</select></label><label>Case type<select name="loan_type" class="case-type-select case-type-'.case_type_key((string)$case['loan_type']).'" data-case-type-select>'.$caseTypeOptions.'</select></label><label>Next search date',$output);$output=str_replace('<section class="card history"><h2>Activity history</h2><ul>','<details class="card history"><summary>Activity history</summary><ul>',$output);$output=str_replace('</ul></section>','</ul></details>',$output);$active=(int)substr((string)$case['stage'],7);if($active>=1&&$active<=4){$due=(string)($case['search_'.$active.'_due_at']??'');$dueValue=$due?date('Y-m-d',strtotime($due)):'';$needle='<label>Search notes<textarea name="search_note" rows="5"></textarea></label><button>Save Search '.$active;$replacement='<label>Search notes<textarea name="search_note" rows="5"></textarea></label><input type="hidden" name="search_due_number" value="'.$active.'"><label>Search '.$active.' due date<input type="date" name="search_due_date" value="'.$dueValue.'"></label><button>Save Search '.$active;$output=str_replace($needle,$replacement,$output);}for($n=1;$n<=4;$n++){$status=h(search_label(search_status($case,$n)));$needle='<strong>Search '.$n.'</strong><span class="step-status">'.$status.'</span>';$due=(string)($case['search_'.$n.'_due_at']??'');$dueLabel=$due?date('M j, Y',strtotime($due)):'—';$output=str_replace($needle,'<strong>Search '.$n.'</strong><span class="step-status">'.$status.'</span><span class="step-due">Due '.h($dueLabel).'</span>',$output);}$output=preg_replace('/<label>Next search date<input type="date" name="next_action_at"[^>]*><\/label>/','',$output);}
    if($case){
        $output=str_replace('<section class="card"><h2>Patron contact</h2><form method="post">','<section class="card"><h2>Patron Contact/Patron Note</h2>'.$customPatronNoteForm.'<form method="post">',$output);
        $output=str_replace('<!--PARTNER_FIELDS-->',$partnerFields,$output);
        $checked=$case['patron_notice_channel']==='email'?' checked':'';$disabled=can_edit()?'':' disabled';
        $output=preg_replace('/<label>Notification preference<select name="patron_notice_channel"[^>]*>.*?<\/select><\/label>/','<label class="checkbox-label"><input type="checkbox" name="patron_notice_email" value="1"'.$checked.$disabled.'> Email updates to patron</label>',$output,1);
        $output=preg_replace('/(<label>Email<input type="email" name="patron_email" value="[^"]*"[^>]*><\/label>)(<label class="checkbox-label"><input type="checkbox" name="patron_notice_email" value="1"[^>]*> Email updates to patron<\/label>)/','<div class="two patron-email-row">$1$2</div>',$output,1);
    }
    return $output;
});
render_start($case?'Case '.$case['public_id']:'New case');
if(!$case){
    if(!$selectedItem){
        echo '<section class="card"><h2>Find patron</h2><p class="muted">Enter the patron identifier first, then choose the checked-out item to report missing.</p><form method="post" onsubmit="this.querySelector(\'button[type=submit]\').disabled=true;document.getElementById(\'patron-lookup-progress\').hidden=false"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="lookup"><label>Patron primary ID<input name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'" required autofocus></label><button type="submit">Look up patron</button><p id="patron-lookup-progress" class="lookup-progress" role="status" aria-live="polite" hidden><progress aria-label="Searching configured Alma accounts"></progress> Searching all configured Alma accounts…</p></form></section>';
        if($patronFound){
            echo '<section class="card"><h2>Checked-out items for '.h($_POST['patron_name']??$_POST['patron_primary_id']??'').($patronInstitutionName!==''?' · '.h(institution_label($patronInstitutionName)):'').'</h2>';
            if($loans){echo '<div class="table-wrap"><table><thead><tr><th>Open case</th><th>Title</th><th>Barcode</th><th>Institution</th><th>Case type</th><th>Call number</th><th>Due date</th><th>Library</th></tr></thead><tbody>';foreach($loans as $loan){$open=$openCaseByBarcode[(string)($loan['item_barcode']??'')]??null;$due=$loan['due_date']??'';$dueTs=$due?strtotime($due):false;$library=is_array($loan['library']??null)?($loan['library']['desc']??$loan['library']['value']??''):(string)($loan['library']??'');$caseType=(string)($loan['_claims_case_type']??'unconfirmed');echo '<tr><td>'.($open?'<a href="case.php?id='.(int)$open['id'].'">View '.$open['public_id'].'</a>':'—').'</td><td><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="select_item"><input type="hidden" name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'"><input type="hidden" name="institution_id" value="'.$selectedInstitutionId.'"><input type="hidden" name="loan_primary_id" value="'.h($loan['_alma_primary_id']??$_POST['patron_primary_id']??'').'"> <input type="hidden" name="loan_member" value="'.h($loan['_alma_member']??$patronMember).'"><button class="secondary" name="item_barcode" value="'.h($loan['item_barcode']??'').'">'.h($loan['title']??'Untitled item').'</button></form></td><td>'.h($loan['item_barcode']??'').'</td><td>'.h(institution_label(alma_institution_name((string)($loan['_alma_member']??'')))).'</td><td>'.case_type_badge($caseType).'</td><td>'.h($loan['call_number']??'').'</td><td>'.h($dueTs?date('M j, Y g:i a',$dueTs):$due).'</td><td>'.h($library).'</td></tr>';}echo '</tbody></table></div>';}else echo '<p class="empty">No active checked-out items were found for this patron.</p>';
            echo '</section>';
            if($patronNotes){
                echo '<section class="card"><h2>Patron notes</h2><ul class="patron-note-list">';
                foreach($patronNotes as $note){
                    echo '<li data-patron-note>';if($note['type']!=='')echo '<strong>'.h($note['type']).'</strong><br>';
                    echo '<p style="white-space:pre-wrap">'.h($note['text']).'</p>';
                    if($note['created_date']!=='')echo '<small>Added '.h($note['created_date']).'</small>';
                    foreach($note['email_status'] as $emailStatus)echo '<small class="note-delivery">'.h($emailStatus).'</small>';
                    if(can_delete_patron_notes())echo '<form class="patron-note-delete" method="post" action="patron-note.php"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'"><input type="hidden" name="alma_member" value="'.h($patronMember).'"><input type="hidden" name="note_key" value="'.h($note['delete_key']).'"><button type="submit" class="danger secondary">Delete note</button></form>';
                    echo '</li>';
                }
                echo '</ul></section>';
            }
        }
    }else{
        echo '<div class="page-title"><div><p class="eyebrow">'.h(user()['institution_name']).'</p><h1>Open a Claims Returned case</h1></div><a href="index.php">← Queue</a></div><form method="post" class="card form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create">'.case_institution_field($selectedInstitutionId).'<div class="form-section"><h2>Patron Information</h2><label>Patron ID<input name="patron_primary_id" value="'.h($_POST['patron_primary_id']??'').'" readonly></label><label>Patron name<input name="patron_name" value="'.h($_POST['patron_name']??'').'" required></label><div class="two"><label>Patron email<input type="email" name="patron_email" value="'.h($_POST['patron_email']??'').'"></label></div></div><div class="form-section"><h2>Item Information</h2><label>Item barcode<input name="item_barcode" value="'.h($_POST['item_barcode']??'').'" readonly></label><label>Item title<input name="item_title" value="'.h($_POST['item_title']??'').'" required></label><div class="two"><label>Author<input name="item_author" value="'.h($_POST['item_author']??'').'"></label><label>Alma MMS ID<input name="item_mms_id" value="'.h($_POST['item_mms_id']??'').'"></label></div></div><div class="form-section"><h2>Workflow and notifications</h2><div class="two"><label>Loan channel<select name="loan_type"><option value="local">Local</option><option value="CLS">CLS</option><option value="P2P">P2P</option><option value="ILL">ILL</option></select></label><label>Patron notices<select name="patron_notice_channel"><option value="email">Email</option><option value="none">Manual / none</option></select></label></div><label>First search due date<input type="date" name="next_action_at" value="'.h(gmdate('Y-m-d',strtotime('+7 days'))).'" required></label><div class="two"><label>Partner library<input name="partner_name"></label><label>Partner email<input type="email" name="partner_email"></label></div></div><button>Create case and start search</button></form>';if($message)echo '<p class="flash error">'.h($message).'</p>';
    }
    echo '<section class="card"><h2>Resources</h2><ul><li><a href="https://www.libraries.wrlc.org/resource-sharing-wrlc-systems-support/consortium-loan-service-cls-contact-list" target="_blank" rel="noopener noreferrer">CLS Contact List</a></li></ul></section>';
}else{
    $checkinStatus=($case['stage']==='closed'&&$case['resolution']==='complete')?alma_item_checkin_status($case):null;
    $events=db()->prepare('SELECT e.*,u.display_name FROM case_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.case_id=? ORDER BY e.created_at DESC');$events->execute([$id]);$history=$events->fetchAll();$staffq=db()->prepare('SELECT id,display_name FROM users WHERE institution_id=? AND active=1 ORDER BY display_name');$staffq->execute([(int)$case['institution_id']]);$staff=$staffq->fetchAll();$finalSearch=search_final_number($case);$visibleSteps=$case['stage']==='closed'?$finalSearch:4;
    echo '<div class="page-title"><div><p class="eyebrow">'.h($case['institution_name']).' · '.h($case['public_id']).'</p><h1>'.h($case['item_title']).'</h1><p class="muted">'.h($case['patron_name']).' · '.h($case['patron_primary_id']).'</p></div><div class="page-actions"><a href="index.php">← Queue</a>';if(can_manage_all_submissions())echo '<form method="post" class="delete-top" onsubmit="return confirm(\'Permanently delete case '.h($case['public_id']).'?\');"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm_delete" value="DELETE"><button class="danger">Delete case</button></form>';echo '</div></div>';
    if($checkinStatus){$updatedAt=date('M j, Y g:i:s a T');echo '<section class="card alma-checkin alma-checkin-'.h($checkinStatus['state']).'"><div><p class="eyebrow">Live Alma item check</p><h2>Item check-in status</h2><span class="badge">'.h($checkinStatus['label']).'</span><p>'.h($checkinStatus['message']).'</p>';if(!empty($checkinStatus['base_status']))echo '<p><strong>Alma item status:</strong> '.h($checkinStatus['base_status']).'</p>';if(!empty($checkinStatus['process_type']))echo '<p><strong>Alma process:</strong> '.h($checkinStatus['process_type']).'</p>';echo '<small>Checked at '.h($updatedAt).'</small></div></section>';}
    if(can_view_patron_account($case))echo '<section class="card patron-account"><h2>Patron account</h2><details id="patron-account-details" data-case-id="'.$id.'" data-url="patron-account.php?id='.$id.'"><summary>View patron account summary</summary><p class="muted account-info-status">Patron details, notes, and active checkouts will load from Alma when expanded.</p><div class="account-info-data"></div></details></section>';
    echo '<div class="detail-grid"><section class="card"><h2>Case progress</h2><ol class="steps">';
    for($n=1;$n<=$visibleSteps;$n++){$status=search_status($case,$n);$done=!empty($case['search_'.$n.'_at']);echo '<li class="'.($done?'done':'').'"><span class="step-edit">';if(can_edit())echo '<a class="button secondary edit-search" href="case.php?id='.$id.'&edit_search='.$n.'">Edit</a>';echo '</span><strong>Search '.$n.'</strong><span class="step-status">'.h(search_label($status)).'</span>';if($done)echo '<small>'.h($case['search_'.$n.'_result']??'').'</small>';echo '</li>';}
    echo '<li class="'.($case['stage']==='closed'?'done':'').'"><span class="step-edit"></span><strong>Final resolution</strong><span class="step-status">'.h($case['resolution']?search_label($case['resolution']):'Pending').'</span>';if($case['resolution_note'])echo '<small>'.h($case['resolution_note']).'</small>';echo '</li></ol><p><strong>Case type:</strong> '.case_type_badge((string)$case['loan_type']).'</p><p><strong>Partner:</strong> '.h($case['partner_name']?:'—').($case['partner_status']!=='not_required'?' · '.h(str_replace('_',' ',$case['partner_status'])):'').'</p><p><strong>Next action:</strong> '.h($case['next_action_at']?:'—').'</p></section><section class="card"><h2>'.($editSearch?'Edit Search '.$editSearch:'Staff action').'</h2>';
    if(can_edit()&&$editSearch>=1&&$editSearch<=4){$status=search_status($case,$editSearch)?:'not_found';$searchDue=$case['search_'.$editSearch.'_due_at']??'';echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_search"><input type="hidden" name="search_number" value="'.$editSearch.'"><label>Status<select name="search_status"><option value="not_found" '.($status==='not_found'?'selected':'').'>Not found</option><option value="complete" '.($status==='complete'?'selected':'').'>Complete — item found</option></select></label><label>Search due date<input type="date" name="search_due_date" value="'.h($searchDue?date('Y-m-d',strtotime($searchDue)):'').'" required></label><label>Search notes<textarea name="search_note" rows="5">'.h($case['search_'.$editSearch.'_result']??'').'</textarea></label><button>Save search</button></form>';}elseif(can_edit()&&$case['stage']!=='closed'){$n=(int)substr((string)$case['stage'],6);echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_search"><input type="hidden" name="search_number" value="'.$n.'"><label>Status<select name="search_status"><option value="not_found">Not found — continue searching</option><option value="complete">Complete — item found</option></select></label><label>Search notes<textarea name="search_note" rows="5"></textarea></label><button>Save Search '.$n.'</button></form><hr><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Assigned staff<select name="assigned_to"><option value="">Unassigned</option>';foreach($staff as $s)echo '<option value="'.(int)$s['id'].'" '.((int)$case['assigned_to']===(int)$s['id']?'selected':'').'>'.h($s['display_name']).'</option>';echo '</select></label><input type="hidden" name="action" value="update"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Next search date<input type="date" name="next_action_at" value="'.h($case['next_action_at']?substr($case['next_action_at'],0,10):'').'"></label><button class="secondary">Save assignment</button></form>';}else{echo '<p>This case is closed.</p>';if(can_edit())echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="reopen"><button>Reopen case</button></form>';}
    echo '</section></div><section class="card history"><h2>Activity history</h2><ul>';foreach($history as $e)echo '<li><time>'.h(date('M j, Y g:i a',strtotime($e['created_at']))).'</time><strong>'.h($e['summary']).'</strong><span>'.h($e['display_name']??'System').'</span></li>';echo '</ul></section>';
    echo '<div class="detail-grid persistent-sections"><section class="card"><h2>Assigned staff</h2><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update"><label>Assigned staff<select name="assigned_to" '.(can_edit()?'':'disabled').'><option value="">Unassigned</option>';foreach($staff as $s)echo '<option value="'.(int)$s['id'].'" '.((int)$case['assigned_to']===(int)$s['id']?'selected':'').'>'.h($s['display_name']).'</option>';echo '</select></label><label>Next search date<input type="date" name="next_action_at" value="'.h($case['next_action_at']?substr($case['next_action_at'],0,10):'').'" '.(can_edit()?'':'disabled').'></label><!--PARTNER_FIELDS-->';if(can_edit())echo '<button class="secondary">Save assignment</button>';echo '</form></section><section class="card"><h2>Patron contact</h2><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update_contact"><label>Email<input type="email" name="patron_email" value="'.h($case['patron_email']??'').'" '.(can_edit()?'':'disabled').'></label><label>Notification preference<select name="patron_notice_channel" '.(can_edit()?'':'disabled').'><option value="email" '.($case['patron_notice_channel']==='email'?'selected':'').'>Email</option><option value="none" '.($case['patron_notice_channel']==='none'?'selected':'').'>Manual / none</option></select></label>';if(can_edit())echo '<button class="secondary">Save patron contact</button>';echo '</form></section></div>';
}
if($case&&can_view_patron_account($case)){
    echo '<script>
(()=>{
 const panel=document.getElementById("patron-account-details");
 if(!panel)return;
 const status=panel.querySelector(".account-info-status");
 const output=panel.querySelector(".account-info-data");
 const add=(tag,cls,text,parent)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;parent.append(node);return node;};
 const displayDate=value=>{if(!value)return "—";const date=new Date(value);return Number.isNaN(date.getTime())?value:date.toLocaleDateString(undefined,{year:"numeric",month:"short",day:"numeric"});};
 const render=(data)=>{
  const summary=add("dl","patron-summary-grid",undefined,output);
  [["Full name",data.full_name],["Primary ID",data.primary_id],["Email address",data.email],["Institution",data.institution]].forEach(([label,value])=>{add("dt","",label,summary);add("dd","",value||"—",summary);});
  const notesSection=add("details","patron-account-section patron-notes-accordion",undefined,output);add("summary","","Patron notes",notesSection);
  if(!data.notes?.length)add("p","muted","No patron notes were returned.",notesSection);
  else{const notes=add("ul","patron-note-list",undefined,notesSection);for(const note of data.notes){const li=add("li","",undefined,notes);li.dataset.patronNote="";const meta=[note.type,displayDate(note.created_date)].filter(value=>value&&value!=="—").join(" · ");if(meta)add("small","",meta,li);add("p","",note.text,li);for(const delivery of note.email_status||[])add("small","note-delivery",delivery,li);if(data.can_delete_patron_notes){const form=add("form","patron-note-delete",undefined,li);form.method="post";form.action="patron-note.php";[["_csrf",data.csrf_token],["case_id",panel.dataset.caseId],["patron_primary_id",data.primary_id],["note_key",note.delete_key]].forEach(([name,value])=>{const input=add("input","",undefined,form);input.type="hidden";input.name=name;input.value=value;});const button=add("button","danger secondary","Delete note",form);button.type="submit";}}}
  const itemsSection=add("section","patron-account-section",undefined,output);add("h3","","Currently checked out",itemsSection);
  if(!data.checked_out_items?.length){add("p","muted","No currently checked out items were found.",itemsSection);return;}
  const wrap=add("div","table-wrap patron-loans-wrap",undefined,itemsSection),table=add("table","",undefined,wrap),head=add("thead","",undefined,table),headRow=add("tr","",undefined,head);
  ["Title","Barcode","Institution","Case type","Call number","Due date","Library","Open case"].forEach(label=>add("th","",label,headRow));
  const body=add("tbody","",undefined,table);
  for(const item of data.checked_out_items){const row=add("tr","",undefined,body);[item.title,item.barcode,item.institution].forEach(value=>add("td","",value||"—",row));const typeCell=add("td","",undefined,row);const typeKey=({local:"local",cls:"cls",p2p:"p2p",ill:"ill"})[String(item.case_type||"").toLowerCase()]||"unconfirmed";add("span",`case-type-pill case-type-${typeKey}`,item.case_type||"Unconfirmed",typeCell);[item.call_number,displayDate(item.due_date),item.library].forEach(value=>add("td","",value||"—",row));const casesCell=add("td","",undefined,row);if(item.open_cases?.length){for(const linkedCase of item.open_cases){const link=add("a","",`View ${linkedCase.public_id}`,casesCell);link.href=`case.php?id=${encodeURIComponent(linkedCase.id)}`;}}else add("span","muted","—",casesCell);}
 };
 panel.addEventListener("toggle",async()=>{
  if(!panel.open)return;
  status.hidden=false;status.textContent="Loading current account information from Alma…";output.replaceChildren();
  try{
   const response=await fetch(panel.dataset.url,{credentials:"same-origin",headers:{Accept:"application/json"}});
   const result=await response.json();
   if(!response.ok||!result.ok)throw new Error(result.error||"Could not load this account.");
   status.hidden=true;render(result.data);
  }catch(error){status.textContent=error.message||"Could not load this account.";}
 });
})();
</script>';
}
echo '<script>
(()=>{
 const categories=[...document.querySelectorAll("select[name=loan_type]")];
 const typeClass=value=>({local:"local",cls:"cls",p2p:"p2p",ill:"ill"})[String(value||"").toLowerCase()]||"unconfirmed";
 const colorCaseType=select=>{select.classList.remove("case-type-local","case-type-cls","case-type-p2p","case-type-ill","case-type-unconfirmed");select.classList.add("case-type-select",`case-type-${typeClass(select.value)}`);};
 categories.forEach(colorCaseType);
 const partners=[...document.querySelectorAll("select[name=partner_name]")];
 const setPartnerRequirement=()=>{const required=categories.some(category=>category.value==="CLS");partners.forEach(partner=>partner.required=required);};
 partners.forEach(partner=>{
  const form=partner.closest("form"),email=form?.querySelector("input[name=partner_email]");
  let automaticPartnerEmail=partner.selectedOptions[0]?.dataset.partnerEmail||"";
  partner.addEventListener("change",()=>{const next=partner.selectedOptions[0]?.dataset.partnerEmail||"";if(email&&(email.value.trim()===""||email.value===automaticPartnerEmail))email.value=next;automaticPartnerEmail=next;});
 });
 categories.forEach(category=>category.addEventListener("change",()=>{
  categories.forEach(other=>{if(other!==category)other.value=category.value;colorCaseType(other);});
  if(category.value==="local")partners.forEach(partner=>{partner.value="";const email=partner.closest("form")?.querySelector("input[name=partner_email]");if(email)email.value="";});
  setPartnerRequirement();
 }));
 setPartnerRequirement();
})();
</script>';
render_end();
