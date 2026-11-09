<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc<5){fwrite(STDERR,"Usage: php bin/create-user.php <institution-slug> <email> <role:admin|staff|viewer> <display name>\n");exit(2);}
[$script,$slug,$email,$role,$name]=$argv;if(!in_array($role,['admin','staff','viewer'],true)){fwrite(STDERR,"Role must be admin, staff, or viewer.\n");exit(2);}
$q=db()->prepare('SELECT id FROM institutions WHERE slug=? AND active=1');$q->execute([$slug]);$iid=$q->fetchColumn();if(!$iid){fwrite(STDERR,"Institution not found; add it first.\n");exit(1);}
fwrite(STDOUT,"Password: ");$stty=trim((string)shell_exec('stty -g 2>/dev/null'));if($stty)shell_exec('stty -echo');$password=trim((string)fgets(STDIN));if($stty)shell_exec('stty '.escapeshellarg($stty));fwrite(STDOUT,"\n");
$q=db()->prepare('INSERT INTO users(institution_id,email,display_name,password_hash,role) VALUES(?,?,?,?,?)');$q->execute([$iid,strtolower($email),$name,password_hash($password,PASSWORD_DEFAULT),$role]);echo "User created.\n";
