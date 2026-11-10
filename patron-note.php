<?php
require __DIR__.'/src/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function patron_note_json(bool $ok,string $message,int $status=200): never {
    http_response_code($status);
    echo json_encode(['ok'=>$ok,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST')patron_note_json(false,'Method not allowed.',405);
csrf_check();
if(!can_delete_patron_notes())patron_note_json(false,'Only admins and super users can delete patron notes.',403);

$patronId=trim((string)($_POST['patron_primary_id']??''));
$noteKey=trim((string)($_POST['note_key']??''));
$caseId=(int)($_POST['case_id']??0);
if($patronId===''||!preg_match('/^[a-f0-9]{64}$/',$noteKey))patron_note_json(false,'The patron note details are invalid.',400);

$preferredMember='';
if($caseId){
    $case=case_by_id($caseId);
    if(!$case)patron_note_json(false,'Case not found.',404);
    if(!hash_equals((string)$case['patron_primary_id'],$patronId))patron_note_json(false,'The patron does not match this case.',400);
    $preferredMember=(string)$case['institution_slug'];
}else{
    $preferredMember=trim((string)($_POST['alma_member']??''));
}
$members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));
if($preferredMember!==''&&in_array($preferredMember,$members,true)){
    $lookup=alma_request($preferredMember,'/users/'.rawurlencode($patronId).'?user_id_type=all_unique&view=full&expand=none');
    if($lookup['ok']&&is_array($lookup['data']))$lookup['data']['_alma_member']=$preferredMember;
}else{
    $lookup=alma_lookup_user('', $patronId);
}
if(!$lookup['ok']||!is_array($lookup['data']))patron_note_json(false,$lookup['error']?:'Alma could not return this patron account.',502);
$member=(string)($lookup['data']['_alma_member']??$preferredMember);
$result=alma_delete_user_note($member,$patronId,$lookup['data'],$noteKey);
if(!$result['ok'])patron_note_json(false,(string)($result['error']??'Alma could not delete this note.'),409);
patron_note_json(true,'Patron note deleted from Alma.');
