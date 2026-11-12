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
if(!can_view_patron_account($case)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Patron account details are available only to the owning institution.']);exit;}

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

$loans=alma_classify_patron_loans($patron,$member,alma_lookup_patron_network_loans($patron,$member));
$barcodes=array_values(array_unique(array_filter(array_map(static fn($loan)=>trim((string)($loan['item_barcode']??'')),$loans))));
$openCasesByBarcode=[];
if($barcodes){
    $marks=implode(',',array_fill(0,count($barcodes),'?'));
    $sql="SELECT id,public_id,item_barcode FROM claims_cases WHERE stage<>'closed' AND item_barcode IN ($marks)";
    $params=$barcodes;
    if(!can_manage_all_submissions()){$sql.=' AND institution_id=?';$params[]=tenant_id();}
    $q=db()->prepare($sql.' ORDER BY created_at DESC');
    $q->execute($params);
    foreach($q->fetchAll() as $open)$openCasesByBarcode[(string)$open['item_barcode']][]=['id'=>(int)$open['id'],'public_id'=>(string)$open['public_id']];
}

$itemLoans=[];
$institutionNames=[];
foreach(db()->query('SELECT slug,name FROM institutions')->fetchAll() as $institution)$institutionNames[(string)$institution['slug']]=(string)$institution['name'];
foreach($loans as $loan){
    $barcode=(string)($loan['item_barcode']??'');
    $library=$loan['library']??'';
    if(is_array($library))$library=$library['desc']??$library['value']??'';
    $loanMember=(string)($loan['_alma_member']??'');
    $itemLoans[]=[
        'title'=>(string)($loan['title']??'Untitled item'),
        'barcode'=>$barcode,
        'institution'=>institution_label($institutionNames[$loanMember]??$loanMember),
        'case_type'=>match((string)($loan['_claims_case_type']??'')){'CLS'=>'CLS','P2P'=>'P2P','ILL'=>'ILL','local'=>'Local',default=>'Unconfirmed'},
        'call_number'=>(string)($loan['call_number']??''),
        'due_date'=>(string)($loan['due_date']??''),
        'library'=>(string)$library,
        'open_cases'=>$openCasesByBarcode[$barcode]??[],
    ];
}

echo json_encode(['ok'=>true,'data'=>[
    'full_name'=>$fullName,
    'primary_id'=>(string)($patron['primary_id']??$case['patron_primary_id']),
    'email'=>$email,
    'institution'=>institution_label($institutionName),
    'notes'=>$patronNotes,
    'can_delete_patron_notes'=>can_delete_patron_notes(),
    'csrf_token'=>csrf_token(),
    'checked_out_items'=>$itemLoans,
]],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
