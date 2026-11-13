<?php
require __DIR__.'/src/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$caseId=(int)($_GET['id']??0);
$case=$caseId?case_by_id($caseId):null;
if(!$case){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Case not found.']);exit;}
if(!can_edit()||!can_view_patron_account($case)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Access denied.']);exit;}

$lookup=alma_lookup_user((string)$case['institution_slug'],(string)$case['patron_primary_id']);
if(!$lookup['ok']||!is_array($lookup['data'])){
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'Could not load the patron account needed to verify the item status.'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$patron=$lookup['data'];
$member=(string)($patron['_alma_member']??$case['institution_slug']);
echo json_encode(['ok'=>true,'data'=>alma_case_patron_item_status($case,$patron,$member)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
