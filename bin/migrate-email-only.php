<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$pdo=db();
$pdo->exec("UPDATE claims_cases SET patron_notice_channel=CASE WHEN patron_email IS NOT NULL AND TRIM(patron_email)<>'' THEN 'email' ELSE 'none' END WHERE patron_notice_channel IN ('sms','both')");
$pdo->exec("ALTER TABLE claims_cases MODIFY patron_notice_channel ENUM('email','none') NOT NULL DEFAULT 'email'");
echo "Notification preferences migrated to email-only. SMS and combined preferences use email when available, otherwise manual / none.\n";
