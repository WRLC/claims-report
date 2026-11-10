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

$result=alma_request((string)$case['institution_slug'],'/users/'.rawurlencode((string)$case['patron_primary_id']).'?user_id_type=all_unique&view=full&expand=none');
if(!$result['ok']||!is_array($result['data'])){
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>$result['error']?:'Alma could not return this patron account.'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$removeSensitiveFields=static function(array $value) use (&$removeSensitiveFields): array {
    foreach($value as $key=>$item){
        if(preg_match('/password|pin_number|api.?key|access.?token|secret/i',(string)$key)){unset($value[$key]);continue;}
        if(is_array($item))$value[$key]=$removeSensitiveFields($item);
    }
    return $value;
};

echo json_encode(['ok'=>true,'data'=>$removeSensitiveFields($result['data'])],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
