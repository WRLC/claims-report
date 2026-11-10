<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$pdo=db();
$column=$pdo->query("SHOW COLUMNS FROM claims_cases LIKE 'partner_notify_email'")->fetch();
if(!$column){
    $pdo->exec('ALTER TABLE claims_cases ADD COLUMN partner_notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER partner_email');
    $pdo->exec("UPDATE claims_cases SET partner_notify_email=IF(partner_email IS NOT NULL AND TRIM(partner_email)<>'',1,0)");
    echo "Partner email notification preferences added. Existing cases with partner email retain the previous automatic notification behavior.\n";
}else{
    echo "Partner email notification preferences already exist.\n";
}
