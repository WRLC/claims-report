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
function app_url(): string { return 'https://wrshields.wrlc2k.wrlc.org/api/claims-report'; }
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function institution_label(string $name): string { return strcasecmp(trim($name),'scf')===0?'WRLC':$name; }
function case_type_key(string $type): string { return match(strtolower(trim($type))){'local'=>'local','cls'=>'cls','p2p'=>'p2p','ill'=>'ill',default=>'unconfirmed'}; }
function case_type_label(string $type): string { return match(case_type_key($type)){'local'=>'Local','cls'=>'CLS','p2p'=>'P2P','ill'=>'ILL',default=>'Unconfirmed'}; }
function case_type_badge(string $type): string { $key=case_type_key($type);return '<span class="case-type-pill case-type-'.$key.'">'.h(case_type_label($type)).'</span>'; }
function email_addresses(string $value): array { $emails=array_map('trim',explode(',',$value));return array_values(array_unique(array_filter($emails,static fn(string $email): bool=>$email!==''))); }
function valid_email_list(string $value,bool $allowEmpty=true): bool {
 $value=trim($value);if($value==='')return $allowEmpty;
 $parts=array_map('trim',explode(',',$value));if(in_array('',$parts,true))return false;
 foreach($parts as $email)if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
 return true;
}
function resource_sharing_contact_email(string $partnerLibrary,string $caseType): string {
 $column=match(strtoupper(trim($caseType))){'CLS'=>'cls_email','P2P'=>'p2p_email','ILL'=>'ill_email',default=>''};
 if($column===''||trim($partnerLibrary)==='')return '';
 $q=db()->prepare('SELECT '.$column.' FROM resource_sharing_contacts WHERE LOWER(TRIM(university))=LOWER(TRIM(?)) AND '.$column.' IS NOT NULL AND TRIM('.$column.')<>\'\' ORDER BY id');$q->execute([$partnerLibrary]);
 $emails=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $value)foreach(email_addresses((string)$value) as $email)if(filter_var($email,FILTER_VALIDATE_EMAIL))$emails[strtolower($email)]=$email;
 return implode(', ',array_values($emails));
}
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
function can_delete_patron_notes(): bool { return is_admin()||is_super_user(); }
function is_case_partner_institution(array $case): bool {
 if(can_manage_all_submissions()||(int)($case['institution_id']??0)===tenant_id()||($case['loan_type']??'')!=='CLS')return false;
 $partner=strtolower(trim((string)($case['partner_name']??'')));$institution=strtolower(trim((string)(user()['institution_name']??'')));$label=strtolower(trim(institution_label((string)(user()['institution_name']??''))));
 return $partner!==''&&($partner===$institution||$partner===$label);
}
function can_view_patron_account(array $case): bool { return !is_case_partner_institution($case); }
function flash(string $m,string $k='success'): void { $_SESSION['flash']=[$m,$k]; }
function take_flash(): ?array { $f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f; }
function redirect(string $u='index.php'): never { header('Location: '.$u);exit; }
function event(int $cid,string $type,string $summary,array $detail=[]): void { $q=db()->prepare('INSERT INTO case_events(case_id,actor_user_id,event_type,summary,detail_json) VALUES(?,?,?,?,?)');$q->execute([$cid,user()['id']??null,$type,mb_substr($summary,0,500),$detail?json_encode($detail,JSON_UNESCAPED_UNICODE):null]); }
function case_by_id(int $id): ?array { $sql='SELECT c.*,i.name institution_name,i.slug institution_slug,u.display_name assignee_name FROM claims_cases c JOIN institutions i ON i.id=c.institution_id LEFT JOIN users u ON u.id=c.assigned_to WHERE c.id=?';$params=[$id];if(!can_manage_all_submissions()){$sql.=' AND (c.institution_id=? OR (c.loan_type=\'CLS\' AND LOWER(TRIM(c.partner_name)) IN (LOWER(TRIM(?)),LOWER(TRIM(?)))))';array_push($params,tenant_id(),(string)user()['institution_name'],institution_label((string)user()['institution_name']));}$q=db()->prepare($sql);$q->execute($params);return $q->fetch()?:null; }
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
 $input=trim($barcode);$variants=[$input];if(strtoupper(substr($input,-1))!=='X')$variants[]=$input.'X';$withoutX=preg_replace('/x$/i','',$input);if($withoutX!==''&&$withoutX!==$input)$variants[]=$withoutX;$variants=array_values(array_unique($variants));$members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));$ordered=[];if($slug!==''&&in_array($slug,$members,true))$ordered[]=$slug;foreach($members as $member)if(!in_array($member,$ordered,true))$ordered[]=$member;$last=['ok'=>false,'status'=>404,'data'=>null,'error'=>'No items found for barcode '.$input.'.'];
 foreach($ordered as $member)foreach($variants as $candidate){$r=alma_request($member,'/items?item_barcode='.rawurlencode($candidate));if($r['ok']){if(is_array($r['data']))$r['data']['_alma_member']=$member;return $r;}$last=$r;}
 return ['ok'=>false,'status'=>$last['status']?:404,'data'=>null,'error'=>'No items found for barcode '.$input.'.'];
}
function alma_item_checkin_status(array $case): array {
 $barcode=trim((string)($case['item_barcode']??''));if($barcode==='')return ['ok'=>false,'state'=>'unknown','label'=>'Unable to verify','message'=>'This case has no item barcode.'];
 $lookup=alma_lookup_item((string)$case['institution_slug'],$barcode);if(!$lookup['ok'])return ['ok'=>false,'state'=>'unknown','label'=>'Unable to verify','message'=>'Alma item lookup failed: '.($lookup['error']??'unknown API error')];
 $item=$lookup['data']['item']??$lookup['data'];if(is_array($item)&&array_is_list($item))$item=$item[0]??[];$data=is_array($item)?($item['item_data']??$item):[];
 $value=static function(mixed $field): string { if(is_array($field))return trim((string)($field['desc']??$field['value']??''));return trim((string)$field); };
 $base=$value($data['base_status']??'');$process=$value($data['process_type']??'');$normalized=strtolower($base);$processNormalized=strtolower($process);
 if(str_contains($processNormalized,'loan'))return ['ok'=>true,'state'=>'checked_out','label'=>'Still checked out','message'=>'Alma reports the item is still on loan.','base_status'=>$base,'process_type'=>$process,'member'=>$lookup['data']['_alma_member']??''];
 if(in_array(strtolower($base),['1','in place','item in place'],true))return ['ok'=>true,'state'=>'checked_in','label'=>'Checked in','message'=>'Alma reports the item is in place.','base_status'=>$base,'process_type'=>$process,'member'=>$lookup['data']['_alma_member']??''];
 if($base!==''||$process!=='')return ['ok'=>true,'state'=>'not_confirmed','label'=>'Not confirmed checked in','message'=>'Alma reports a status other than “Item in place”.','base_status'=>$base,'process_type'=>$process,'member'=>$lookup['data']['_alma_member']??''];
 return ['ok'=>false,'state'=>'unknown','label'=>'Unable to verify','message'=>'Alma returned the item record without a check-in status.'];
}
function alma_lookup_loans(string $patronId,string $preferredMember=''): array {
 $members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));$ordered=[];if($preferredMember&&in_array($preferredMember,$members,true))$ordered[]=$preferredMember;foreach($members as $member)if(!in_array($member,$ordered,true))$ordered[]=$member;
 foreach($ordered as $member){$out=[];$total=null;for($offset=0;$offset<1000;$offset+=100){$r=alma_request($member,'/users/'.rawurlencode($patronId).'/loans?user_id_type=all_unique&limit=100&offset='.$offset.'&order_by=id&direction=ASC&loan_status=Active');if(!$r['ok']||!is_array($r['data']))break;$loans=$r['data']['item_loan']??[];if(isset($loans['loan_id']))$loans=[$loans];$page=(array)$loans;foreach($page as $loan)if(is_array($loan)){$loan['_alma_member']=$member;$out[]=$loan;}if($total===null&&isset($r['data']['total_record_count']))$total=(int)$r['data']['total_record_count'];if(count($page)<100||($total!==null&&$offset+count($page)>=$total))break;}if($out)return $out;}
 return [];
}
function alma_lookup_linked_accounts(array $patron,string $homeMember): array {
 $homeId=trim((string)($patron['primary_id']??''));if($homeId==='')return [];
 $accounts=[['institution'=>$homeMember,'primary_id'=>$homeId,'is_home'=>true]];
 $sourceLinkId=alma_value($patron['source_link_id']??$patron['linking_id']??'');
 if($sourceLinkId===''||$homeMember==='')return $accounts;
 $sourceCode=alma_value($patron['source_institution_code']??'');
 if($sourceCode===''){
  $q=db()->prepare('SELECT alma_code,name FROM institutions WHERE slug=? LIMIT 1');$q->execute([$homeMember]);$row=$q->fetch()?:[];
  $sourceCode=trim((string)($row['alma_code']??''));
  if($sourceCode===''||strcasecmp($sourceCode,$homeMember)===0||!str_contains(strtoupper($sourceCode),'WRLC')){
   $sourceCode=['au'=>'01WRLC_AMU','cua'=>'01WRLC_CAA','udc'=>'01WRLC_DOC','gallaudet'=>'01WRLC_GAL','gmu'=>'01WRLC_GML','georgetown'=>'01WRLC_GUNIV','gw'=>'01WRLC_GWA','howard'=>'01WRLC_HOW','marymount'=>'01WRLC_MAR','scf'=>'01WRLC_SCF'][$homeMember]??$sourceCode;
  }
 }
 if($sourceCode==='')return $accounts;
 $members=array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))));
 foreach($members as $member){
  if($member===$homeMember)continue;
  $path='/users/'.rawurlencode($sourceLinkId).'?source_institution_code='.rawurlencode($sourceCode);
  $result=alma_request($member,$path);
  if(!$result['ok']||!is_array($result['data']))continue;
  $linked=$result['data'];$linkedId=trim((string)($linked['primary_id']??''));
  if($linkedId==='')continue;
  $linked['_alma_member']=$member;$linked['_alma_linked_from']=$homeMember;
  $accounts[]=['institution'=>$member,'primary_id'=>$linkedId,'is_home'=>false,'user'=>$linked];
 }
 return $accounts;
}
function alma_lookup_account_loans(string $patronId,string $member): array {
 if($patronId===''||$member==='')return [];
 $out=[];$total=null;
 for($offset=0;$offset<1000;$offset+=100){
  $r=alma_request($member,'/users/'.rawurlencode($patronId).'/loans?user_id_type=all_unique&limit=100&offset='.$offset.'&order_by=id&direction=ASC&loan_status=Active');
  if(!$r['ok']||!is_array($r['data']))break;
  $loans=$r['data']['item_loan']??[];if(isset($loans['loan_id']))$loans=[$loans];$page=(array)$loans;
  foreach($page as $loan)if(is_array($loan)){$loan['_alma_member']=$member;$loan['_alma_primary_id']=$patronId;$out[]=$loan;}
  if($total===null&&isset($r['data']['total_record_count']))$total=(int)$r['data']['total_record_count'];
  if(count($page)<100||($total!==null&&$offset+count($page)>=$total))break;
 }
 return $out;
}
function alma_lookup_patron_network_loans(array $patron,string $homeMember): array {
 $out=[];
 foreach(alma_lookup_linked_accounts($patron,$homeMember) as $account)$out=array_merge($out,alma_lookup_account_loans((string)$account['primary_id'],(string)$account['institution']));
 return $out;
}
function alma_value(mixed $value): string { if(is_array($value))return trim((string)($value['value']??$value['code']??$value['desc']??''));return trim((string)$value); }
function alma_case_item_field(array $record,array $names): mixed { foreach($record as $key=>$value)if(is_string($key)&&in_array(strtolower($key),$names,true))return $value;return null; }
function alma_case_field_text(mixed $value): string { if(is_array($value)){ $parts=[];foreach($value as $entry)$parts[]=alma_case_field_text($entry);return implode(' ',$parts); }return trim((string)$value); }
function alma_item_policy_location_text(array $item,array $loan=[]): string {
 $itemData=$item['item_data']??$item;$loanData=$loan['item_data']??$loan;
 $policy=alma_case_item_field(is_array($itemData)?$itemData:[],['policy','item_policy','policy_code']);if($policy===null)$policy=alma_case_item_field(is_array($loanData)?$loanData:[],['policy','item_policy','policy_code']);
 $location=alma_case_item_field(is_array($itemData)?$itemData:[],['location','location_code','item_location']);if($location===null)$location=alma_case_item_field(is_array($loanData)?$loanData:[],['location','location_code','item_location']);
 return strtolower(alma_case_field_text($policy).' '.alma_case_field_text($location));
}
function alma_item_case_type(array $item,array $loan=[]): ?string {
 $text=alma_item_policy_location_text($item,$loan);
 if(preg_match('/illiad|(?:^|[^a-z0-9])ill(?:$|[^a-z0-9])/i',$text))return 'ILL';
 if(preg_match('/suny|(?:^|[^a-z0-9])p2p(?:$|[^a-z0-9])/i',$text))return 'P2P';
 return null;
}
function alma_find_item_institution(mixed $record): string {
 if(!is_array($record))return '';
 foreach($record as $key=>$value)if(is_string($key)&&in_array(strtolower($key),['institution','institution_name','institution_code'],true)){
  if(is_array($value)){foreach(['name','desc','description','value','code'] as $part)if(isset($value[$part])&&!is_array($value[$part])&&trim((string)$value[$part])!=='')return trim((string)$value[$part]);}
  elseif(trim((string)$value)!=='')return trim((string)$value);
 }
 foreach($record as $value)if(is_array($value)){$found=alma_find_item_institution($value);if($found!=='')return $found;}
 return '';
}
function alma_item_partner_university(array $item,array $loan=[]): string {
 $records=[$item['item_data']??null,$item,$loan['item_data']??null,$loan];
 $value='';foreach($records as $record){$candidate=alma_find_item_institution($record);if($candidate!==''){$value=$candidate;break;}}
 if($value==='')return '';
 $q=db()->prepare('SELECT name FROM institutions WHERE active=1 AND (LOWER(TRIM(name))=LOWER(TRIM(?)) OR LOWER(TRIM(slug))=LOWER(TRIM(?)) OR LOWER(TRIM(alma_code))=LOWER(TRIM(?))) LIMIT 1');$q->execute([$value,$value,$value]);$name=$q->fetchColumn();if($name)return (string)$name;
 $labels=['au'=>'American University','cua'=>'Catholic University of America','gallaudet'=>'Gallaudet University','gmu'=>'George Mason University','georgetown'=>'Georgetown University','gw'=>'George Washington University','howard'=>'Howard University','marymount'=>'Marymount University','trinitydc'=>'Trinity Washington University','udc'=>'University of the District of Columbia'];$aliases=['AU'=>'au','AU LAW'=>'au','CU'=>'cua','CU LAW'=>'cua','GA'=>'gallaudet','GM'=>'gmu','GT'=>'georgetown','GT LAW'=>'georgetown','GW'=>'gw','GW HIM'=>'gw','GW LAW'=>'gw','HU'=>'howard','HU LOUIS'=>'howard','HU LAW'=>'howard','MU'=>'marymount','TU'=>'trinitydc','UDC'=>'udc','UDC LAW'=>'udc'];$slug=$aliases[strtoupper($value)]??'';
 if($slug!==''){$q=db()->prepare('SELECT name FROM institutions WHERE active=1 AND LOWER(TRIM(slug))=LOWER(TRIM(?)) LIMIT 1');$q->execute([$slug]);$name=$q->fetchColumn();if($name)return (string)$name;}
 $q=db()->prepare('SELECT university FROM resource_sharing_contacts WHERE LOWER(TRIM(university))=LOWER(TRIM(?)) ORDER BY id LIMIT 1');$q->execute([$value]);$name=$q->fetchColumn();if($name)return (string)$name;
 if($slug!==''&&isset($labels[$slug])){$q->execute([$labels[$slug]]);$name=$q->fetchColumn();if($name)return (string)$name;return $labels[$slug];}
 return $value;
}
function alma_classify_loan_evidence(string $homeMember,string $itemMember,bool $requestIdPresent,bool $requestLookupOk,bool $hasResourceSharing,string $profileType): array {
 $profile=strtoupper(trim($profileType));$category=null;$reason='Alma did not provide enough information to confirm the loan category.';
 if($requestIdPresent&&!$requestLookupOk){if($homeMember!==''&&$itemMember!==''&&strcasecmp($homeMember,$itemMember)!==0)return ['category'=>'CLS','status'=>'confirmed','reason'=>'The loan is attached to a patron account at another configured WRLC institution.','is_resource_sharing'=>null];return ['category'=>null,'status'=>'unresolved','reason'=>'The related Alma request could not be verified.','is_resource_sharing'=>null];}
 if($hasResourceSharing){
  if($profile==='NCIP_P2P'){$category='P2P';$reason='The linked Alma Resource Sharing partner uses the NCIP P2P profile.';}
  elseif($profile==='FULFILLMENT_NETWORK'&&$homeMember!==''&&$itemMember!==''&&strcasecmp($homeMember,$itemMember)!==0){$category='CLS';$reason='The linked Alma request uses the Fulfillment Network profile and the item record belongs to another configured Alma institution.';}
  elseif(in_array($profile,['EMAIL','ART_EMAIL','BLDSS','RAPID','INNREACH'],true)){$category='ILL';$reason='The linked Alma Resource Sharing request uses a traditional external lending profile.';}
  else $reason='The linked Alma Resource Sharing workflow does not identify P2P, ILL, or CLS unambiguously.';
 }elseif($homeMember!==''&&$itemMember!==''){
  if(strcasecmp($homeMember,$itemMember)===0){$category='local';$reason='The loaned item is owned by the patron’s home Alma institution and no Resource Sharing request is linked.';}
  else{$category='CLS';$reason='The loan is attached to a patron account at another configured WRLC institution.';}
 }
 return ['category'=>$category,'status'=>$category===null?'unresolved':'confirmed','reason'=>$reason,'is_resource_sharing'=>$hasResourceSharing];
}
function alma_match_partner_institution(array $institutions,string $member='',array $partnerDetails=[]): ?array {
 $needles=[];foreach([$member,$partnerDetails['name']??''] as $candidate){$value=mb_strtolower(trim(alma_value($candidate)));if($value!=='')$needles[$value]=true;}
 foreach($institutions as $institution){$aliases=[(string)($institution['slug']??''),(string)($institution['alma_code']??''),(string)($institution['name']??'')];foreach($aliases as $alias){$normalized=mb_strtolower(trim($alias));if($normalized!==''&&isset($needles[$normalized]))return $institution;}}
 return null;
}
function alma_mapped_partner_institution(array $institutions,string $partnerCode): ?array {
 $mapping=json_decode(envv('ALMA_PARTNER_INSTITUTION_MAP','{}'),true);if(!is_array($mapping)||$partnerCode===''||!isset($mapping[$partnerCode]))return null;
 $slug=mb_strtolower(trim((string)$mapping[$partnerCode]));foreach($institutions as $institution)if(mb_strtolower(trim((string)($institution['slug']??'')))===$slug)return $institution;
 return null;
}
function alma_partner_contact_email(array $partner): string {
 $contacts=$partner['contact_info']['emails']['email']??[];if(isset($contacts['email_address'])||isset($contacts['email']))$contacts=[$contacts];$choices=[];
 foreach((array)$contacts as $contact){if(!is_array($contact))continue;$email=trim((string)($contact['email_address']??$contact['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))continue;$type=strtolower(alma_value($contact['email_types']['email_type']??$contact['email_type']??''));$description=strtolower(trim((string)($contact['description']??'')));$score=(str_contains($type,'claim')||str_contains($description,'claim'))?0:((str_contains($type,'general')||str_contains($description,'resource sharing')||str_contains($description,'ill'))?1:2);$choices[]=[$score,$email];}
 if(!$choices)return '';
 usort($choices,static fn(array $a,array $b): int=>$a[0]<=>$b[0]);return $choices[0][1];
}
function alma_classify_selected_loan(string $patronId,string $homeMember,array $loan,array $item,string $loanMember='',string $loanPatronId=''): array {
 $requestId=alma_value($loan['request_id']??'');$requestIdPresent=$requestId!=='';$requestOk=false;$hasResourceSharing=false;$request=[];$partner=[];$profile='';
 $requestMember=$loanMember?:$homeMember;$requestPatronId=$loanPatronId?:$patronId;
 if($requestIdPresent){$requestResult=alma_request($requestMember,'/users/'.rawurlencode($requestPatronId).'/requests/'.rawurlencode($requestId));$requestOk=$requestResult['ok']&&is_array($requestResult['data']);if($requestOk){$request=$requestResult['data'];$resourceSharing=$request['resource_sharing']??[];$hasResourceSharing=is_array($resourceSharing)?!empty($resourceSharing):trim((string)$resourceSharing)!=='';}}
 $resourceSharing=$request['resource_sharing']??[];$partnerCode=alma_value(is_array($resourceSharing)?($resourceSharing['partner']??''):'');
 if($hasResourceSharing&&$partnerCode!==''){$partnerResult=alma_request($requestMember,'/partners/'.rawurlencode($partnerCode));if($partnerResult['ok']&&is_array($partnerResult['data'])){$partner=$partnerResult['data']['partner']??$partnerResult['data'];$details=$partner['partner_details']??[];$profile=alma_value($details['profile_details']['profile_type']??'');}}
 $itemMember=(string)($item['_alma_member']??$item['item_data']['_alma_member']??'');
 $classification=alma_classify_loan_evidence($homeMember,$itemMember,$requestIdPresent,$requestOk,$hasResourceSharing,$profile);
 $configured=json_decode(envv('ALMA_PARTNER_CASE_TYPES','{}'),true);if(is_array($configured)&&$partnerCode!==''&&isset($configured[$partnerCode])){$mapped=strtoupper(trim((string)$configured[$partnerCode]));if(in_array($mapped,['LOCAL','CLS','P2P','ILL'],true)){$classification['category']=$mapped==='LOCAL'?'local':$mapped;$classification['status']='confirmed';$classification['reason']='The Alma partner has an explicit ALMA_PARTNER_CASE_TYPES configuration.';}}
 $itemType=alma_item_case_type($item,$loan);if($itemType!==null){$classification['category']=$itemType;$classification['status']='confirmed';$classification['reason']='The Alma item Policy or Location identifies this as '.$itemType.'.';}
 $itemInstitution=alma_item_partner_university($item,$loan);
 $institutions=db()->query('SELECT id,slug,name,alma_code,partner_email FROM institutions WHERE active=1')->fetchAll();$details=$partner['partner_details']??[];$partnerMatchDetails=$details;
 $matched=null;
 if($classification['category']!=='local'){
  if($classification['category']==='CLS'){$matched=alma_match_partner_institution($institutions,$itemMember,[]);if(!$matched)$matched=alma_mapped_partner_institution($institutions,$partnerCode);if(!$matched)$matched=alma_match_partner_institution($institutions,'',$partnerMatchDetails);}
  else $matched=alma_match_partner_institution($institutions,'',$partnerMatchDetails);
  if(!$matched)$matched=alma_mapped_partner_institution($institutions,$partnerCode);
  if(!$matched&&$itemMember!==''&&strcasecmp($itemMember,$homeMember)!==0)$matched=alma_match_partner_institution($institutions,$itemMember,[]);
 }
 if($matched&&strcasecmp((string)$matched['slug'],$homeMember)===0)$matched=null;
 $partnerEmail=$matched?trim((string)($matched['partner_email']??'')):'';if(!filter_var($partnerEmail,FILTER_VALIDATE_EMAIL))$partnerEmail=$matched?alma_partner_contact_email($partner):'';
 $partnerName=$matched?(string)$matched['name']:'';
 if($classification['category']==='ILL')$partnerName=$itemInstitution!==''?$itemInstitution:'ILL';
 elseif($classification['category']==='P2P')$partnerName=$itemInstitution!==''?$itemInstitution:(str_contains(alma_item_policy_location_text($item,$loan),'suny')?'SUNY':'P2P');
 $resourceSharingEmail=resource_sharing_contact_email($partnerName,(string)($classification['category']??''));if($resourceSharingEmail!=='')$partnerEmail=$resourceSharingEmail;
 $itemInstitutionMatched=false;if($itemInstitution!==''){$q=db()->prepare('SELECT 1 FROM institutions WHERE active=1 AND LOWER(TRIM(name))=LOWER(TRIM(?)) UNION ALL SELECT 1 FROM resource_sharing_contacts WHERE LOWER(TRIM(university))=LOWER(TRIM(?)) LIMIT 1');$q->execute([$itemInstitution,$itemInstitution]);$itemInstitutionMatched=(bool)$q->fetchColumn();}
 $lendingIdentifier=$itemMember!==''?$itemMember:$partnerCode;
 if($classification['category']!==null&&$classification['category']!=='local'&&!$matched&&!$itemInstitutionMatched)$classification['reason'].=' The lending institution could not be matched to an active Partner Library.';
 return ['category'=>$classification['category'],'status'=>$classification['status'],'reason'=>$classification['reason'],'is_resource_sharing'=>$classification['is_resource_sharing'],'lending_identifier'=>$lendingIdentifier,'partner_code'=>$partnerCode,'partner_name'=>$partnerName,'partner_email'=>$partnerEmail,'partner_matched'=>(bool)$matched||$itemInstitutionMatched];
}
function alma_classify_patron_loans(array $patron,string $homeMember,array $loans): array {
 $homeId=trim((string)($patron['primary_id']??''));
 foreach($loans as $index=>$loan){
  if(!is_array($loan))continue;
  $loanMember=(string)($loan['_alma_member']??$homeMember);$loanId=(string)($loan['_alma_primary_id']??$homeId);
  $item=['_alma_member'=>$loanMember];
  // Always inspect the full item record: Policy/Location may already identify
  // P2P or ILL on the loan, but institution is needed to select the right
  // university contact instead of the generic SUNY/ILL fallback.
  if(trim((string)($loan['item_barcode']??''))!==''){$lookup=alma_lookup_item($loanMember?:$homeMember,(string)$loan['item_barcode']);if($lookup['ok']&&is_array($lookup['data'])){$item=$lookup['data']['item']??$lookup['data'];if(is_array($item)&&array_is_list($item))$item=$item[0]??[];if(!is_array($item))$item=[];$item['_alma_member']=$lookup['data']['_alma_member']??$loanMember;}}
  $classification=alma_classify_selected_loan($homeId,$homeMember,$loan,$item,$loanMember,$loanId);
  $category=$classification['category'];
  if($loanMember!==''&&$homeMember!==''&&strcasecmp($loanMember,$homeMember)!==0&&!in_array($category,['P2P','ILL'],true))$category='CLS';
  $classification['category']=$category;
  $partnerName=(string)($classification['partner_name']??'');$contactEmail=resource_sharing_contact_email($partnerName,(string)($category??''));
  $loans[$index]['_claims_case_type']=$category??'unconfirmed';
  $loans[$index]['_claims_classification_status']=$category!==null?'confirmed':($classification['status']??'unresolved');
  $loans[$index]['_claims_partner_name']=$partnerName;
  $loans[$index]['_claims_partner_email']=$contactEmail!==''?$contactEmail:(string)($classification['partner_email']??'');
 }
 return $loans;
}
function alma_lookup_loan(string $patronId,string $barcode,string $preferredMember='',string $loanMember='',string $loanPrimaryId=''): ?array {
 $loans=$loanMember!==''&&$loanPrimaryId!==''?alma_lookup_account_loans($loanPrimaryId,$loanMember):alma_lookup_loans($patronId,$preferredMember);
 foreach($loans as $loan)if(strcasecmp((string)($loan['item_barcode']??''),trim($barcode))===0)return $loan;
 return null;
}
function alma_append_claim_note(array $case,string $note): array {
 if(!filter_var(envv('ALMA_ENABLE_NOTE_UPDATES','false'),FILTER_VALIDATE_BOOLEAN))return ['ok'=>false,'skipped'=>true,'error'=>'Alma note updates are disabled by configuration.'];
 $note=mb_substr($note,0,2000);$path='/users/'.rawurlencode((string)$case['patron_primary_id']).'?user_id_type=all_unique&view=full&expand=none';$slug=(string)$case['institution_slug'];
 $got=alma_request($slug,$path);if(!$got['ok']||!is_array($got['data']))return ['ok'=>false,'error'=>'Could not read the full Alma user record before updating the note: '.($got['error']??'unknown API error')];
 $record=$got['data'];$notes=$record['user_note']??$record['user_notes']['user_note']??[];if(isset($notes['note_text']))$notes=[$notes];if(!is_array($notes))$notes=[];
 foreach($notes as $existing)if(is_array($existing)&&($existing['note_text']??null)===$note)return ['ok'=>true,'already_present'=>true];
 $notes[]=['note_type'=>['value'=>envv('ALMA_CLAIMS_NOTE_TYPE','OTHER')],'note_text'=>$note,'user_viewable'=>false,'popup_note'=>false,'segment_type'=>'Internal'];$record['user_note']=$notes;unset($record['user_notes']);
 $saved=alma_request($slug,$path,'PUT',$record);if(!$saved['ok'])return ['ok'=>false,'error'=>'Alma rejected the user note update: '.($saved['error']??'unknown API error')];
 $verify=alma_request($slug,$path);if(!$verify['ok']||!is_array($verify['data']))return ['ok'=>false,'error'=>'Alma accepted the update but the note could not be verified: '.($verify['error']??'unknown API error')];
 $savedNotes=$verify['data']['user_note']??$verify['data']['user_notes']['user_note']??[];if(isset($savedNotes['note_text']))$savedNotes=[$savedNotes];foreach((array)$savedNotes as $savedNote)if(is_array($savedNote)&&($savedNote['note_text']??null)===$note)return ['ok'=>true];
 return ['ok'=>false,'error'=>'Alma accepted the update, but the requested note was not present when the patron record was read back.'];
}
function alma_delete_user_note(string $slug,string $patronId,array $record,string $noteKey): array {
 if(!filter_var(envv('ALMA_ENABLE_NOTE_UPDATES','false'),FILTER_VALIDATE_BOOLEAN))return ['ok'=>false,'error'=>'Alma note updates are disabled by configuration.'];
 $notes=$record['user_note']??$record['user_notes']['user_note']??[];if(isset($notes['note_text']))$notes=[$notes];if(!is_array($notes))$notes=[];
 $target=null;$matchingCount=0;foreach($notes as $index=>$note)if(is_array($note)&&hash('sha256',json_encode($note,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))===$noteKey){$matchingCount++;if($target===null)$target=$index;}
 if($target===null)return ['ok'=>false,'error'=>'That patron note has changed or was already deleted. Reload the patron record and try again.'];
 unset($notes[$target]);$notes=array_values($notes);$path='/users/'.rawurlencode($patronId).'?user_id_type=all_unique&view=full&expand=none';unset($record['_alma_member']);$record['user_note']=$notes;unset($record['user_notes']);
 $saved=alma_request($slug,$path,'PUT',$record);if(!$saved['ok'])return ['ok'=>false,'error'=>'Alma rejected the note deletion: '.($saved['error']??'unknown API error')];
 $verify=alma_request($slug,$path);if(!$verify['ok']||!is_array($verify['data']))return ['ok'=>false,'error'=>'Alma accepted the update, but the patron record could not be verified: '.($verify['error']??'unknown API error')];
 $remaining=$verify['data']['user_note']??$verify['data']['user_notes']['user_note']??[];if(isset($remaining['note_text']))$remaining=[$remaining];$remainingCount=0;foreach((array)$remaining as $note)if(is_array($note)&&hash('sha256',json_encode($note,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE))===$noteKey)$remainingCount++;if($remainingCount!==$matchingCount-1)return ['ok'=>false,'error'=>'Alma accepted the update, but the requested note deletion could not be verified.'];
 return ['ok'=>true];
}
function save_notification(int $cid,string $channel,string $recipient,string $template,bool $ok,string $error=''): array { $q=db()->prepare('INSERT INTO notification_log(case_id,channel,recipient,template_key,status,error_text) VALUES(?,?,?,?,?,?)');$q->execute([$cid,$channel,$recipient,$template,$ok?'sent':'failed',$error?:null]);event($cid,'notification_'.$channel,($ok?'Sent ':'Failed ').$channel.' notification',['template'=>$template,'recipient'=>$recipient,'error'=>$error]);return ['channel'=>$channel,'ok'=>$ok,'error'=>$error]; }
function email_only_preference(array $case): string {
 $preference=(string)($case['patron_notice_channel']??'email');
 if(in_array($preference,['email','none'],true))return $preference;
 return trim((string)($case['patron_email']??''))!==''?'email':'none';
}
function notify_case(array $c,string $template,string $subject,string $body,?string $email=null): array {
 $channel=str_starts_with($template,'partner_')?'partner':email_only_preference($c);if($channel==='none')return [];
 $to=$channel==='partner'?$c['partner_email']:($email?:$c['patron_email']);if(!$to)return [];
 $headers=['From: '.envv('MAIL_FROM_NAME','WRLC Claims Returned').' <'.envv('MAIL_FROM','claims@localhost').'>','Content-Type: text/plain; charset=UTF-8'];
 $recipients=$channel==='partner'?email_addresses((string)$to):[(string)$to];$results=[];
 foreach($recipients as $recipient){$ok=filter_var($recipient,FILTER_VALIDATE_EMAIL)&&@mail($recipient,$subject,$body,implode("\r\n",$headers));$results[]=save_notification((int)$c['id'],'email',$recipient,$template,$ok,$ok?'':'mail() rejected the message or the address is invalid.');}
 return $results;
}
function notify_case_assignee(array $case,array $assignee): array {
 $to=trim((string)($assignee['email']??''));
 if(!filter_var($to,FILTER_VALIDATE_EMAIL))return save_notification((int)$case['id'],'email',$to?:'(missing email)','staff_assignment',false,'Assigned staff account has no valid email address.');
 $url=app_url().'/case.php?id='.(int)$case['id'];
 $body="Hello {$assignee['display_name']},\n\nA Claims Returned case has been assigned to you.\n\nCase: {$case['public_id']}\nPatron: {$case['patron_name']} ({$case['patron_primary_id']})\nItem: {$case['item_title']}\nBarcode: ".($case['item_barcode']?:'Not available')."\nNext search date: ".($case['next_action_at']?:'Not set')."\n\nView the case: {$url}";
 $headers=['From: '.envv('MAIL_FROM_NAME','WRLC Claims Returned').' <'.envv('MAIL_FROM','claims@localhost').'>','Content-Type: text/plain; charset=UTF-8'];
 $ok=@mail($to,'Claims Returned case assigned: '.$case['item_title'],$body,implode("\r\n",$headers));
 return save_notification((int)$case['id'],'email',$to,'staff_assignment',$ok,$ok?'':'mail() rejected the message.');
}
function alma_note_email_statuses(array $notes,string $patronId): array {
 $references=[];$noteKinds=[];
 foreach($notes as $index=>$note){
  $text=(string)($note['text']??$note['note_text']??'');
  if(str_starts_with(strtolower($text),'we have opened a claims returned search'))$noteKinds[$index]='opening';
  elseif(str_starts_with(strtolower($text),'the item was found during search')||str_starts_with(strtolower($text),'the item was not found after four searches'))$noteKinds[$index]='closure';
  else continue;
  if(preg_match('/Case:\s*([A-Z0-9]{12})/i',$text,$match))$references[$index]=strtoupper($match[1]);
 }
 $emailsByCase=[];
 if($references){
  $publicIds=array_values(array_unique(array_values($references)));$marks=implode(',',array_fill(0,count($publicIds),'?'));
  $q=db()->prepare("SELECT c.public_id,n.template_key,n.status,n.recipient FROM claims_cases c JOIN notification_log n ON n.case_id=c.id WHERE c.patron_primary_id=? AND c.public_id IN ($marks) AND n.template_key IN ('milestone','partner_request') ORDER BY n.created_at DESC");
  $q->execute(array_merge([$patronId],$publicIds));
  foreach($q->fetchAll() as $email)$emailsByCase[(string)$email['public_id']][]=$email;
 }
 $statuses=[];
 foreach($notes as $index=>$note){
  $reference=$references[$index]??'';$sent=[];$failed=[];
  foreach($emailsByCase[$reference]??[] as $email){$recipient=trim((string)$email['recipient']);$kind=$email['template_key']==='partner_request'?'partner':'patron';if($kind==='partner'&&($noteKinds[$index]??'')!=='opening')continue;$label=$kind==='partner'?'Emailed to partner email':'Emailed to patron';$entry=$label.($recipient!==''?': '.$recipient:'');if($email['status']==='sent')$sent[$kind][$recipient]=$entry;else$failed[$kind][$recipient]=($kind==='partner'?'Partner email failed':'Patron email failed').($recipient!==''?': '.$recipient:'');}
  $lines=[];foreach(['patron','partner'] as $kind)foreach($sent[$kind]??[] as $entry)$lines[]=$entry;
  if(!$lines)$lines[]='Internal update — no emails sent';
  foreach(['patron','partner'] as $kind)foreach($failed[$kind]??[] as $entry)$lines[]=$entry;
  $statuses[$index]=$lines;
 }
 return $statuses;
}
function patron_notice_text(array $c,string $text): string { $details="Item details:\nTitle: {$c['item_title']}\nBarcode: ".($c['item_barcode']?:'Not available')."\nAuthor: ".($c['item_author']?:'Not available')."\nMMS ID: ".($c['item_mms_id']?:'Not available');return $text."\n\n{$details}\nCase: {$c['public_id']}"; }
function notice_message(array $c,string $text): array { $body="Hello {$c['patron_name']},\n\n".patron_notice_text($c,$text)."\n\nPlease contact your library if you have questions.";return notify_case($c,'milestone','Claims Returned update: '.$c['item_title'],$body); }
function render_start(string $title): void { $f=take_flash();$u=user();echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · Claims Returned</title><link rel="stylesheet" href="assets/app.css?v=resource-sharing-accordion-20261113"><script src="assets/patron-notes.js" defer></script></head><body><header><a class="brand" href="index.php">WRLC <span>Claims Returned</span></a><nav><a href="about.php">About</a>';if(logged_in()){echo '<a href="admin.php?section=resource-sharing">Resource Sharing Contact List</a>';if(is_super_user())echo '<a href="admin.php">Institution accounts</a>';echo '<span>'.h($u['display_name']).' · '.h(institution_label((string)$u['institution_name'])).'</span>';if(can_manage_accounts())echo '<a href="admin.php">Admin</a>';echo '<a href="logout.php">Sign out</a>';}echo '</nav></header><main>';if($f)echo '<div class="flash '.h($f[1]).'">'.h($f[0]).'</div>'; }
function render_end(): void { echo '</main><footer>Claims Returned · Consortium case tracking</footer></body></html>'; }
