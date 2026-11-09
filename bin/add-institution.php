<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc<4){fwrite(STDERR,"Usage: php bin/add-institution.php <slug> <institution name> <alma institution code> [partner email]\n");exit(2);}
[$script,$slug,$name,$code]=$argv;$email=$argv[4]??null;
$q=db()->prepare('INSERT INTO institutions(slug,name,alma_code,partner_email) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),alma_code=VALUES(alma_code),partner_email=VALUES(partner_email),active=1');$q->execute([$slug,$name,$code,$email?:null]);echo "Institution saved.\n";
