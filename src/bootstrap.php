<?php
declare(strict_types=1);
$claimsEnvFile='/etc/claims-report/claims-report.env';
if(is_readable($claimsEnvFile)){
 foreach(file($claimsEnvFile,FILE_IGNORE_NEW_LINES)?:[] as $line){
  $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
  if(str_starts_with($line,'export '))$line=substr($line,7);
  $equals=strpos($line,'=');if($equals===false)continue;
  $key=trim(substr($line,0,$equals));if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$key)||getenv($key)!==false)continue;
  $value=trim(substr($line,$equals+1));
  if(strlen($value)>=2&&(($value[0]==='"'&&$value[strlen($value)-1]==='"')||($value[0]==="'"&&$value[strlen($value)-1]==="'")))$value=substr($value,1,-1);
  putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;
 }
}
session_name('claims_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>filter_var(getenv('SESSION_SECURE')?:'false',FILTER_VALIDATE_BOOLEAN),'httponly'=>true,'samesite'=>'Lax']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
date_default_timezone_set(envv('APP_TIMEZONE','America/New_York'));
function envv(string $key,string $default=''): string { return trim((string)(getenv($key)!==false?getenv($key):$default)); }
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function institution_label(string $name): string { return strcasecmp(trim($name),'scf')===0?'WRLC':$name; }
function db(): PDO { static $p; if(!$p){$dsn=envv('DB_DSN');if(!$dsn)throw new RuntimeException('DB_DSN is not configured.');$p=new PDO($dsn,envv('DB_USER'),envv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $p; }
function csrf_token(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
function csrf_check(): void { if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['_csrf']??''))){http_response_code(400);exit('Request expired. Reload and try again.');} }
function logged_in(): bool { return isset($_SESSION['user']['id']); }
function require_login(): void {
 if(!logged_in()){header('Location: login.php');exit;}
 $q=db()->prepare('SELECT u.id,u.institution_id,u.email,u.display_name,u.role,u.active,i.name institution_name,i.slug institution_slug,i.active institution_active,i.search_interval_days FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.id=? LIMIT 1');
 $q->execute([user()['id']]);$fresh=$q->fetch();
 if(!$fresh||!$fresh['active']||!$fresh['institution_active']){$_SESSION=[];session_destroy();header('Location: login.php');exit;}
 $_SESSION['user']=$fresh;
}
function require_admin(): void { require_login();if(!can_manage_accounts()){http_response_code(403);exit('Access denied.');} }
function user(): array { return $_SESSION['user']??[]; }
function tenant_id(): int { return (int)(user()['institution_id']??0); }
function is_admin(): bool { return (user()['role']??'')==='admin'; }
function is_super_user(): bool { return (user()['role']??'')==='super_user'; }
function can_manage_all_submissions(): bool { return is_admin()||is_super_user(); }
function can_manage_accounts(): bool { return is_admin(); }
function can_edit(): bool { return in_array(user()['role']??'', ['admin','super_user','user'],true); }
function flash(string $m,string $k='success'): void { $_SESSION['flash']=[$m,$k]; }
function take_flash(): ?array { $f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f; }
function redirect(string $u='index.php'): never { header('Location: '.$u);exit; }
function event(int $cid,string $type,string $summary,array $detail=[]): void { $q=db()->prepare('INSERT INTO case_events(case_id,actor_user_id,event_type,summary,detail_json) VALUES(?,?,?,?,?)');$q->execute([$cid,user()['id']??null,$type,mb_substr($summary,0,500),$detail?json_encode($detail,JSON_UNESCAPED_UNICODE):null]); }
function case_by_id(int $id): ?array { $sql='SELECT c.*,i.name institution_name,i.slug institution_slug,u.display_name assignee_name FROM claims_cases c JOIN institutions i ON i.id=c.institution_id LEFT JOIN users u ON u.id=c.assigned_to WHERE c.id=?';$params=[$id];if(!can_manage_all_submissions()){$sql.=' AND c.institution_id=?';$params[]=tenant_id();}$q=db()->prepare($sql);$q->execute($params);return $q->fetch()?:null; }
function alma_key(string $slug): string { $members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));if(!in_array($slug,$members,true))return '';return envv('ALMA_KEY_'.strtoupper(preg_replace('/[^a-zA-Z0-9]/','_',$slug))); }
function alma_request(string $slug,string $path,string $method='GET',?array $payload=null): array {
 $key=alma_key($slug);if(!$key)return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Alma API is not configured for this member.'];$url=rtrim(envv('ALMA_BASE_URL','https://api-na.hosted.exlibrisgroup.com/almaws/v1'),'/').$path.(str_contains($path,'?')?'&':'?').'apikey='.rawurlencode($key);$headers=['Accept: application/json'];if($payload!==null)$headers[]='Content-Type: application/json';$tries=0;
 do{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5]);if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$last=['ok'=>$err===''&&$status>=200&&$status<300,'status'=>$status,'data'=>json_decode($body?:'',true),'error'=>$err?:''];if($last['ok']||!in_array($status,[408,429,500,502,503,504],true)||$tries>=2)break;usleep(250000*(2**$tries));$tries++;}while(true);
 if(!$last['ok']&&$last['error']==='')$last['error']=(string)($last['data']['errorList']['error'][0]['errorMessage']??'Alma request failed (HTTP '.$last['status'].').');return $last;
}
function alma_lookup_user(string $slug,string $id): array {
 $members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));$last=['ok'=>false,'status'=>404,'data'=>null,'error'=>'User with identifier '.trim($id).' was not found.'];
 foreach($members as $member){$r=alma_request($member,'/users/'.rawurlencode($id).'?user_id_type=all_unique&view=full&expand=none');if($r['ok']){if(is_array($r['data']))$r['data']['_alma_member']=$member;return $r;}$last=$r;}
 return ['ok'=>false,'status'=>$last['status']?:404,'data'=>null,'error'=>'User with identifier '.trim($id).' was not found.'];
}
function alma_lookup_item(string $slug,string $barcode): array {
 $input=trim($barcode);$variants=[$input];if(strtoupper(substr($input,-1))!=='X')$variants[]=$input.'X';$withoutX=preg_replace('/x$/i','',$input);if($withoutX!==''&&$withoutX!==$input)$variants[]=$withoutX;$variants=array_values(array_unique($variants));$members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));$last=['ok'=>false,'status'=>404,'data'=>null,'error'=>'No items found for barcode '.$input.'.'];
 foreach($members as $member)foreach($variants as $candidate){$r=alma_request($member,'/items?item_barcode='.rawurlencode($candidate));if($r['ok']){if(is_array($r['data']))$r['data']['_alma_member']=$member;return $r;}$last=$r;}
 return ['ok'=>false,'status'=>$last['status']?:404,'data'=>null,'error'=>'No items found for barcode '.$input.'.'];
}
function alma_lookup_loans(string $patronId,string $preferredMember=''): array {
 $members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));$ordered=[];if($preferredMember&&in_array($preferredMember,$members,true))$ordered[]=$preferredMember;foreach($members as $member)if(!in_array($member,$ordered,true))$ordered[]=$member;
 foreach($ordered as $member){$r=alma_request($member,'/users/'.rawurlencode($patronId).'/loans?user_id_type=all_unique&limit=100&offset=0&order_by=id&direction=ASC&loan_status=Active');if(!$r['ok']||!is_array($r['data']))continue;$loans=$r['data']['item_loan']??[];if(isset($loans['loan_id']))$loans=[$loans];$out=[];foreach((array)$loans as $loan)if(is_array($loan)){$loan['_alma_member']=$member;$out[]=$loan;}if($out)return $out;}
 return [];
}
function alma_lookup_loan(string $patronId,string $barcode,string $preferredMember=''): ?array {
 foreach(alma_lookup_loans($patronId,$preferredMember) as $loan)if(strcasecmp((string)($loan['item_barcode']??''),trim($barcode))===0)return $loan;
 return null;
}
function alma_append_claim_note(array $case,string $note): array {
 if(!filter_var(envv('ALMA_ENABLE_NOTE_UPDATES','false'),FILTER_VALIDATE_BOOLEAN))return ['ok'=>false,'skipped'=>true,'error'=>'Alma note updates are disabled by configuration.'];$path='/users/'.rawurlencode((string)$case['patron_primary_id']).'?user_id_type=all_unique&view=full&expand=none';$got=alma_request((string)$case['institution_slug'],$path);if(!$got['ok']||!is_array($got['data']))return ['ok'=>false,'error'=>'Could not read the full Alma user record before updating the note.'];$r=$got['data'];$notes=$r['user_notes']['user_note']??[];if(isset($notes['note_text']))$notes=[$notes];if(!is_array($notes))$notes=[];$notes[]=['note_type'=>['value'=>envv('ALMA_CLAIMS_NOTE_TYPE','OTHER')],'note_text'=>$note,'user_viewable'=>false,'popup_note'=>false,'segment_type'=>'Internal'];$r['user_notes']=['user_note'=>$notes];$saved=alma_request((string)$case['institution_slug'],$path,'PUT',$r);return ['ok'=>$saved['ok'],'error'=>$saved['error']??''];
}
function save_notification(int $cid,string $channel,string $recipient,string $template,bool $ok,string $error=''): array { $q=db()->prepare('INSERT INTO notification_log(case_id,channel,recipient,template_key,status,error_text) VALUES(?,?,?,?,?,?)');$q->execute([$cid,$channel,$recipient,$template,$ok?'sent':'failed',$error?:null]);event($cid,'notification_'.$channel,($ok?'Sent ':'Failed ').$channel.' notification',['template'=>$template,'recipient'=>$recipient,'error'=>$error]);return ['channel'=>$channel,'ok'=>$ok,'error'=>$error]; }
function send_sms(int $cid,string $to,string $template,string $body): array {
 $sid=envv('TWILIO_ACCOUNT_SID');$token=envv('TWILIO_AUTH_TOKEN');$from=envv('TWILIO_FROM_NUMBER');
 if(!$sid||!$token||!$from)return save_notification($cid,'sms',$to,$template,false,'SMS is not configured. Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER in the server configuration.');
 if(!preg_match('/^\+[1-9]\d{7,14}$/',trim($to)))return save_notification($cid,'sms',$to,$template,false,'Recipient phone must use E.164 format, such as +12025550123.');
 if(!preg_match('/^\+[1-9]\d{7,14}$/',trim($from)))return save_notification($cid,'sms',$to,$template,false,'Twilio sender number must use E.164 format.');
 $ch=curl_init('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json');
 if($ch===false)return save_notification($cid,'sms',$to,$template,false,'Could not initialize the Twilio request.');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>$sid.':'.$token,CURLOPT_POSTFIELDS=>http_build_query(['To'=>trim($to),'From'=>trim($from),'Body'=>mb_substr($body,0,1500)]),CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5]);
 $resp=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$j=json_decode($resp?:'',true);
 $ok=$err===''&&$status>=200&&$status<300;$providerId=is_array($j)?($j['sid']??null):null;
 $error='';
 if(!$ok){
  if($err!=='')$error='Twilio connection failed: '.$err;
  else{$code=is_array($j)?($j['code']??null):null;$message=is_array($j)?($j['message']??null):null;$error='Twilio rejected the message (HTTP '.$status.($code?' / '.$code:'').')'.($message?': '.$message:'');}
 }
 $q=db()->prepare('INSERT INTO notification_log(case_id,channel,recipient,template_key,status,provider_id,error_text) VALUES(?,?,?,?,?,?,?)');$q->execute([$cid,'sms',$to,$template,$ok?'sent':'failed',$providerId,$ok?null:mb_substr($error,0,500)]);
 event($cid,'notification_sms',($ok?'Sent':'Failed').' SMS notification',['template'=>$template,'recipient'=>$to,'provider_id'=>$providerId,'error'=>$error]);
 return ['channel'=>'sms','ok'=>$ok,'error'=>$error];
}
function notify_case(array $c,string $template,string $subject,string $body,?string $email=null,?string $phone=null): array { $channel=str_starts_with($template,'partner_')?'partner':$c['patron_notice_channel'];if($channel==='none')return [];$to=$channel==='partner'?$c['partner_email']:($email?:$c['patron_email']);$mobile=$channel==='partner'?$c['partner_phone']:($phone?:$c['patron_phone']);$out=[];if(in_array($channel,['email','both','partner'],true)&&$to){$headers=['From: '.envv('MAIL_FROM_NAME','WRLC Claims Returned').' <'.envv('MAIL_FROM','claims@localhost').'>','Content-Type: text/plain; charset=UTF-8'];$ok=@mail($to,$subject,$body,implode("\r\n",$headers));$out[]=save_notification((int)$c['id'],'email',$to,$template,$ok,$ok?'':'mail() rejected the message.');}if(in_array($channel,['sms','both','partner'],true))$out[]=send_sms((int)$c['id'],(string)$mobile,$template,$body);return $out; }
function notice_message(array $c,string $text): array { $details="Item details:\nTitle: {$c['item_title']}\nBarcode: ".($c['item_barcode']?:'Not available')."\nAuthor: ".($c['item_author']?:'Not available')."\nMMS ID: ".($c['item_mms_id']?:'Not available');$body="Hello {$c['patron_name']},\n\n{$text}\n\n{$details}\nCase: {$c['public_id']}\n\nPlease contact your library if you have questions.";return notify_case($c,'milestone','Claims Returned update: '.$c['item_title'],$body); }
function render_start(string $title): void { $f=take_flash();$u=user();echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · Claims Returned</title><link rel="stylesheet" href="assets/app.css"></head><body><header><a class="brand" href="index.php">WRLC <span>Claims Returned</span></a><nav>';if(logged_in()){echo '<span>'.h($u['display_name']).' · '.h(institution_label((string)$u['institution_name'])).'</span>';if(can_manage_accounts())echo '<a href="admin.php">Admin</a>';echo '<a href="logout.php">Sign out</a>';}echo '</nav></header><main>';if($f)echo '<div class="flash '.h($f[1]).'">'.h($f[0]).'</div>'; }
function render_end(): void { echo '</main><footer>Claims Returned · Consortium case tracking</footer></body></html>'; }
