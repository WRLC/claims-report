<?php
set_time_limit(0);
require __DIR__.'/src/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
csrf_check();
if(!can_edit()){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Access denied.']);exit;}
session_write_close();
function lookup_institution_name(string $member): string {
 if($member==='')return '';
 $q=db()->prepare('SELECT name FROM institutions WHERE slug=? AND active=1 LIMIT 1');$q->execute([$member]);
 return (string)($q->fetchColumn()?:'');
}
function lookup_institution_id(string $member,int $fallback): int {
 if(!can_manage_all_submissions()||$member==='')return $fallback;
 $q=db()->prepare('SELECT id FROM institutions WHERE slug=? AND active=1 LIMIT 1');$q->execute([$member]);
 return (int)($q->fetchColumn()?:$fallback);
}
$id=trim((string)($_POST['patron_primary_id']??''));
if($id===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Enter a patron ID.']);exit;}
$mode=(string)($_POST['mode']??'accounts');
$profileFromPost=static function(): array {
 $home=trim((string)($_POST['home_member']??''));$primary=trim((string)($_POST['home_primary_id']??''));
 if($home===''||$primary==='')return [];
 return ['primary_id'=>$primary,'source_link_id'=>trim((string)($_POST['source_link_id']??'')),'source_institution_code'=>trim((string)($_POST['source_institution_code']??'')),'_alma_member'=>$home];
};
$findPatron=static function() use ($id): array {
 $lookup=alma_lookup_user((string)user()['institution_slug'],$id);
 return !empty($lookup['ok'])&&is_array($lookup['data'])?$lookup['data']:[];
};
$patron=$profileFromPost();
if(!$patron&&$mode!=='member')$patron=$findPatron();
if(!$patron){http_response_code(502);echo json_encode(['ok'=>false,'error'=>'Alma could not find this patron account.']);exit;}
$home=(string)$patron['_alma_member'];
if($mode==='home'){
 $loans=alma_lookup_account_loans((string)$patron['primary_id'],$home,'Active',100);
 $name=trim((string)($patron['full_name']??trim((string)($patron['first_name']??'').' '.(string)($patron['last_name']??''))));
 $rawNotes=$patron['user_note']??$patron['user_notes']['user_note']??[];$notes=[];
 if(is_array($rawNotes)){if(isset($rawNotes['note_text']))$rawNotes=[$rawNotes];foreach($rawNotes as $note){if(!is_array($note))continue;$text=trim((string)($note['note_text']??''));if($text==='')continue;$type=$note['note_type']??'';if(is_array($type))$type=$type['desc']??$type['value']??'';$notes[]=['text'=>$text,'type'=>trim((string)$type),'created_date'=>trim((string)($note['created_date']??'')),'delete_key'=>hash('sha256',json_encode($note,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))];}}
 $emailStatuses=alma_note_email_statuses($notes,(string)$patron['primary_id']);foreach($notes as $index=>&$note)$note['email_status']=$emailStatuses[$index]??['Internal update — no emails sent'];unset($note);
 foreach($loans as &$loan){$loan['_institution_name']=institution_label(lookup_institution_name($home));$loan['_case_type_label']='Checking…';$loan['_case_type_key']='unconfirmed';}
 unset($loan);
 echo json_encode(['ok'=>true,'name'=>$name,'home_member'=>$home,'home_primary_id'=>(string)$patron['primary_id'],'source_link_id'=>(string)($patron['source_link_id']??$patron['linking_id']??''),'source_institution_code'=>(string)($patron['source_institution_code']??''),'institution_id'=>lookup_institution_id($home,tenant_id()),'institution_name'=>institution_label(lookup_institution_name($home)),'notes'=>$notes,'can_delete_patron_notes'=>can_delete_patron_notes(),'loans'=>$loans],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);exit;
}
if($mode==='accounts'){
 $accounts=alma_lookup_linked_accounts($patron,$home);$out=[];
 foreach($accounts as $account)$out[]=['institution'=>(string)$account['institution'],'primary_id'=>(string)$account['primary_id']];
 echo json_encode(['ok'=>true,'home_member'=>$home,'home_primary_id'=>(string)$patron['primary_id'],'source_link_id'=>(string)($patron['source_link_id']??$patron['linking_id']??''),'source_institution_code'=>(string)($patron['source_institution_code']??''),'institution_id'=>lookup_institution_id($home,tenant_id()),'accounts'=>$out],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);exit;
}
if($mode!=='member'){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Unknown lookup mode.']);exit;}
$member=trim((string)($_POST['member']??''));$primary=trim((string)($_POST['primary_id']??''));
if($member===''||$primary===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Institution account details are missing.']);exit;}
$accountLoans=alma_lookup_account_loans($primary,$member,'Active',1000);
$loans=alma_classify_patron_loans($patron,$home,$accountLoans);
$barcodes=array_values(array_unique(array_filter(array_map(static fn($loan)=>trim((string)($loan['item_barcode']??'')),$loans))));$open=[];
if($barcodes){$marks=implode(',',array_fill(0,count($barcodes),'?'));$target=can_manage_all_submissions()?lookup_institution_id($home,tenant_id()):tenant_id();$q=db()->prepare("SELECT id,public_id,item_barcode FROM claims_cases WHERE institution_id=? AND stage<>'closed' AND item_barcode IN ($marks)");$q->execute(array_merge([$target],$barcodes));foreach($q->fetchAll() as $row)$open[(string)$row['item_barcode']]=['id'=>(int)$row['id'],'public_id'=>(string)$row['public_id']];}
foreach($loans as &$loan){$due=(string)($loan['due_date']??'');$dueTime=$due!==''?strtotime($due):false;$loan['_due_label']=$dueTime?date('M j, Y g:i a',$dueTime):$due;$loan['_institution_name']=institution_label(lookup_institution_name((string)($loan['_alma_member']??'')));$loan['_case_type_label']=case_type_label((string)($loan['_claims_case_type']??'unconfirmed'));$loan['_case_type_key']=case_type_key((string)($loan['_claims_case_type']??'unconfirmed'));$loan['_open_case']=$open[(string)($loan['item_barcode']??'')]??null;}
unset($loan);
echo json_encode(['ok'=>true,'loans'=>$loans],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
