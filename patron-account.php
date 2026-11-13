<?php
require __DIR__.'/src/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$caseId=(int)($_GET['id']??0);
$case=$caseId?case_by_id($caseId):null;
if(!$case){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Case not found.']);exit;}
if(!can_edit()){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Access denied.']);exit;}
if(!can_view_patron_account($case)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Access denied.']);exit;}

$lookup=alma_lookup_user((string)$case['institution_slug'],(string)$case['patron_primary_id']);
if(!$lookup['ok']||!is_array($lookup['data'])){
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>$lookup['error']?:'Alma could not return this patron account.'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$patron=$lookup['data'];
$emails=$patron['contact_info']['emails']['email']??$patron['contact_info']['email']??[];
if(isset($emails['email_address']))$emails=[$emails];
$email='';
foreach((array)$emails as $candidate){
    if(!is_array($candidate)||empty($candidate['email_address']))continue;
    if($email==='')$email=(string)$candidate['email_address'];
    if(filter_var($candidate['preferred']??false,FILTER_VALIDATE_BOOLEAN)){$email=(string)$candidate['email_address'];break;}
}
$fullName=trim((string)($patron['full_name']??trim((string)($patron['first_name']??'').' '.(string)($patron['middle_name']??'').' '.(string)($patron['last_name']??''))));
$member=(string)($patron['_alma_member']??$case['institution_slug']);
$institutionQuery=db()->prepare('SELECT name FROM institutions WHERE slug=? LIMIT 1');
$institutionQuery->execute([$member]);
$institutionName=(string)($institutionQuery->fetchColumn()?:$case['institution_name']);

$notes=$patron['user_note']??$patron['user_notes']['user_note']??[];
if(isset($notes['note_text']))$notes=[$notes];
$patronNotes=[];
foreach((array)$notes as $note){
    if(!is_array($note)||trim((string)($note['note_text']??''))==='')continue;
    $noteType=$note['note_type']??'';
    if(is_array($noteType))$noteType=$noteType['desc']??$noteType['value']??'';
    $patronNotes[]=['type'=>(string)$noteType,'text'=>(string)$note['note_text'],'created_date'=>(string)($note['created_date']??''),'delete_key'=>hash('sha256',json_encode($note,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))];
}
$emailStatuses=alma_note_email_statuses($patronNotes,(string)($patron['primary_id']??$case['patron_primary_id']));
foreach($patronNotes as $index=>&$note)$note['email_status']=$emailStatuses[$index]??['Internal update — no emails sent'];
unset($note);

$caseItemStatus=['state'=>'unknown','checkin_date'=>'','due_date'=>'','message'=>'Alma could not confirm the item status.'];
$liveItemStatus=alma_item_checkin_status($case);
if(($liveItemStatus['state']??'')==='checked_in'){
    $caseItemStatus=['state'=>'checked_in','checkin_date'=>(string)($liveItemStatus['checkin_date']??''),'due_date'=>'','message'=>'Alma reports that the item is checked in.'];
}elseif(($liveItemStatus['state']??'')==='checked_out'){
    $barcode=strtolower(trim((string)$case['item_barcode']));
    foreach(alma_lookup_patron_network_loans($patron,$member) as $activeLoan){
        $loanBarcode=strtolower(trim((string)($activeLoan['item_barcode']??$activeLoan['barcode']??'')));
        if($loanBarcode===''||$loanBarcode!==$barcode)continue;
        $caseItemStatus=['state'=>'checked_out','checkin_date'=>'','due_date'=>(string)($activeLoan['due_date']??''),'message'=>'Alma shows this item on the patron’s active account.'];
        break;
    }
    if($caseItemStatus['state']!=='checked_out')$caseItemStatus=['state'=>'unknown','checkin_date'=>'','due_date'=>'','message'=>'Alma reports that the item is on loan but does not show it on this patron’s active account.'];
}

echo json_encode(['ok'=>true,'data'=>[
    'full_name'=>$fullName,
    'primary_id'=>(string)($patron['primary_id']??$case['patron_primary_id']),
    'email'=>$email,
    'institution'=>institution_label($institutionName),
    'notes'=>$patronNotes,
    'case_item_status'=>$caseItemStatus,
    'can_delete_patron_notes'=>can_delete_patron_notes(),
    'csrf_token'=>csrf_token(),
]],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
