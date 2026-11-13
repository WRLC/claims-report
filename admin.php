<?php
require __DIR__.'/src/bootstrap.php';
require_login();
$adminMode=can_manage_accounts();
$localManager=is_super_user()&&!$adminMode;
$localCreator=$localManager;
if(!$adminMode&&!$localManager){http_response_code(403);exit('Access denied.');}

function admin_role_label(string $role): string {
    return match($role){'super_user'=>'Super user','admin'=>'Admin','staff','viewer'=>'User',default=>'User'};
}
function admin_institution_options(array $institutions,int $selected): string {
    $html='';foreach($institutions as $institution)$html.='<option value="'.(int)$institution['id'].'" '.($selected===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';
    return $html;
}
function admin_account_scope_fields(bool $adminMode,array $institutions,int $institutionId,array $roles,string $selectedRole): string {
    if(!$adminMode)return '<div class="two"><label>Institution<input value="'.h(institution_label((string)user()['institution_name'])).'" readonly></label><input type="hidden" name="institution_id" value="'.tenant_id().'"><label>Access level<input value="User" readonly></label><input type="hidden" name="role" value="user"></div>';
    $html='<div class="two"><label>Institution<select name="institution_id" required>'.admin_institution_options($institutions,$institutionId).'</select></label><label>Access level<select name="role">';
    foreach($roles as $value=>$label)$html.='<option value="'.h($value).'"'.($selectedRole===$value?' selected':'').'>'.h($label).'</option>';
    return $html.'</select></label></div>';
}
function admin_password_is_valid(string $password,string $confirmation): bool {
    return strlen($password)>=12&&hash_equals($password,$confirmation);
}
function admin_contact_university(string $value,array $institutions): ?string {
    $value=trim($value);$aliases=['AU'=>'au','AU LAW'=>'au','CU'=>'cua','CU LAW'=>'cua','GA'=>'gallaudet','GM'=>'gmu','GT'=>'georgetown','GT LAW'=>'georgetown','GW'=>'gw','GW HIM'=>'gw','GW LAW'=>'gw','HU'=>'howard','HU LOUIS'=>'howard','HU LAW'=>'howard','MU'=>'marymount','TU'=>'trinitydc','UDC'=>'udc','UDC LAW'=>'udc','SCF'=>'scf','WRLC'=>'scf'];
    $slug=$aliases[strtoupper($value)]??'';
    foreach($institutions as $institution){if($slug!==''&&strcasecmp((string)$institution['slug'],$slug)===0)return (string)$institution['name'];if(strcasecmp(trim((string)$institution['name']),$value)===0||strcasecmp(trim((string)$institution['slug']),$value)===0||strcasecmp(trim((string)($institution['alma_code']??'')),$value)===0)return (string)$institution['name'];}
    return null;
}

$institutionNames=[
    'gw'=>'George Washington University','au'=>'American University','cua'=>'Catholic University of America',
    'udc'=>'University of the District of Columbia','gallaudet'=>'Gallaudet University','gmu'=>'George Mason University',
    'georgetown'=>'Georgetown University','howard'=>'Howard University','marymount'=>'Marymount University','scf'=>'WRLC','scf'=>'WRLC',
];
$configuredMembers=array_values(array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))),static fn(string $slug): bool=>$slug!==''&&alma_key($slug)!==''));
if($adminMode){
    $institutionLookup=db()->prepare('SELECT id,active FROM institutions WHERE slug=? LIMIT 1');
    $activateInstitution=db()->prepare('UPDATE institutions SET active=1 WHERE id=?');
    $addInstitution=db()->prepare('INSERT INTO institutions(slug,name,alma_code,active) VALUES(?,?,?,1)');
    foreach($configuredMembers as $member){
        $institutionLookup->execute([$member]);$existingInstitution=$institutionLookup->fetch();
        if(!$existingInstitution){$name=$institutionNames[$member]??ucwords(str_replace(['-','_'],' ',$member));$addInstitution->execute([$member,$name,$member]);}
        elseif(!(int)$existingInstitution['active'])$activateInstitution->execute([(int)$existingInstitution['id']]);
    }
    $institutions=db()->query('SELECT id,name,slug,alma_code FROM institutions WHERE active=1 ORDER BY name')->fetchAll();
    $roles=['user'=>'User','super_user'=>'Super user','admin'=>'Admin'];
}else{
    $q=db()->prepare('SELECT id,name,slug,alma_code FROM institutions WHERE id=? AND active=1');
    $q->execute([tenant_id()]);$institutions=$q->fetchAll();$roles=['user'=>'User','super_user'=>'Super user'];
}
$create=['institution_id'=>tenant_id(),'identifier'=>'','display_name'=>'','email'=>'','role'=>'user'];
$edit=null;$error='';$message='';$screen=(string)($_GET['add']??'');$lookupLoaded=false;
$section=(string)($_GET['section']??'accounts');if(!in_array($section,['accounts','resource-sharing'],true))$section='accounts';
if($section==='resource-sharing'&&!$adminMode){http_response_code(403);exit('Access denied.');}
$contactFields=['university'=>'','cls_contact'=>'','cls_email'=>'','cls_phone'=>'','p2p_email'=>'','p2p_phone'=>'','ill_email'=>'','ill_phone'=>''];
$contactEditId=(int)($_GET['contact_edit']??0);$contactEdit=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=(string)($_POST['action']??'');
    if(in_array($action,['save_resource_contact','delete_resource_contact','import_resource_contacts'],true)&&!$adminMode){http_response_code(403);exit('Access denied.');}
    if($action==='import_resource_contacts'){
        $section='resource-sharing';$upload=$_FILES['contacts_csv']??null;
        if(!$upload||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$error='Choose a CSV file exported from the Resource Sharing Contact List spreadsheet.';
        elseif(($upload['size']??0)>2*1024*1024)$error='The CSV file must be 2 MB or smaller.';
        elseif(!is_uploaded_file((string)($upload['tmp_name']??'')))$error='The CSV upload could not be read.';
        else{
            $handle=fopen($upload['tmp_name'],'rb');$header=$handle?fgetcsv($handle,null,',','"','\\'):false;
            if(!$handle||!is_array($header))$error='The CSV file is empty or could not be parsed.';
            else{
                $header[0]=preg_replace('/^\\xEF\\xBB\\xBF/','',(string)$header[0]);$header=array_map(static fn($cell)=>strtolower(trim((string)$cell)),$header);$columns=array_flip($header);
                $required=['university','cls_contact','cls_email','cls_phone','p2p_email','p2p_phone','ill_email','ill_phone'];$missing=array_values(array_diff($required,array_keys($columns)));
                if($missing)$error='CSV is missing required columns: '.implode(', ',$missing).'.';
                else{
                    $rows=[];$rowNumber=1;$allowedEmails=['cls_email','p2p_email','ill_email'];
                    while(($cells=fgetcsv($handle,null,',','"','\\'))!==false){$rowNumber++;if(!array_filter($cells,static fn($cell)=>trim((string)$cell)!==''))continue;$raw=[];foreach($required as $field)$raw[$field]=trim((string)($cells[$columns[$field]]??''));$university=admin_contact_university($raw['university'],$institutions);if(!$university){$error='CSV row '.$rowNumber.' has an unknown university “'.mb_substr($raw['university'],0,80).'”.';break;}$raw['university']=$university;if($raw['cls_contact']===''&&$raw['cls_email']===''&&$raw['p2p_email']===''&&$raw['ill_email']===''){$error='CSV row '.$rowNumber.' has no contact email or CLS contact.';break;}foreach($allowedEmails as $field)if($raw[$field]!==''&&!filter_var($raw[$field],FILTER_VALIDATE_EMAIL)){$error='CSV row '.$rowNumber.' has an invalid '.$field.'.';break;}if($error!=='')break;if(strlen($raw['cls_email'])>254||strlen($raw['p2p_email'])>254||strlen($raw['ill_email'])>254||mb_strlen($raw['cls_contact'])>160||mb_strlen($raw['cls_phone'])>80||mb_strlen($raw['p2p_phone'])>80||mb_strlen($raw['ill_phone'])>80){$error='CSV row '.$rowNumber.' contains a field that exceeds its maximum length.';break;}$rows[]=$raw;}
                    if($error===''&&!$rows)$error='The CSV contains no contact rows.';
                    if($error===''&&$rows){
                        $columnsToSave=['university','cls_contact','cls_email','cls_phone','p2p_email','p2p_phone','ill_email','ill_phone'];$existing=db()->query('SELECT '.implode(',',$columnsToSave).' FROM resource_sharing_contacts')->fetchAll();$signatures=[];$signature=static fn(array $row): string=>json_encode(array_map(static fn($field)=>trim((string)($row[$field]??'')),$columnsToSave),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);foreach($existing as $record)$signatures[$signature($record)]=true;
                        $insert=db()->prepare('INSERT INTO resource_sharing_contacts(university,cls_contact,cls_email,cls_phone,p2p_email,p2p_phone,ill_email,ill_phone) VALUES(?,?,?,?,?,?,?,?)');$added=0;$skipped=0;db()->beginTransaction();try{foreach($rows as $row){$key=$signature($row);if(isset($signatures[$key])){$skipped++;continue;}$values=[];foreach($columnsToSave as $field)$values[]=$row[$field]===''?null:$row[$field];$insert->execute($values);$signatures[$key]=true;$added++;}db()->commit();flash('Imported '.$added.' contact row'.($added===1?'':'s').'; skipped '.$skipped.' exact duplicate'.($skipped===1?'':'s').'.');redirect('admin.php?section=resource-sharing');}catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();$error='The CSV contacts could not be imported. Check the file and try again.';}
                    }
                }
            }
            if(is_resource($handle))fclose($handle);
        }
    }elseif($action==='save_resource_contact'){
        $section='resource-sharing';$contactEditId=(int)($_POST['contact_id']??0);
        foreach($contactFields as $field=>$unused)$contactFields[$field]=trim((string)($_POST[$field]??''));
        $emails=['cls_email','p2p_email','ill_email'];
        $university=admin_contact_university($contactFields['university'],$institutions);if(!$university)$error='Choose a university from the controlled list.';else $contactFields['university']=$university;
        if($error===''&&(mb_strlen($contactFields['cls_contact'])>160||strlen($contactFields['cls_email'])>254||strlen($contactFields['p2p_email'])>254||strlen($contactFields['ill_email'])>254||mb_strlen($contactFields['cls_phone'])>80||mb_strlen($contactFields['p2p_phone'])>80||mb_strlen($contactFields['ill_phone'])>80))$error='One or more contact fields exceed the maximum length.';
        if($error==='')foreach($emails as $field)if($contactFields[$field]!==''&&!filter_var($contactFields[$field],FILTER_VALIDATE_EMAIL)){$error='Enter a valid email address or leave the email field blank.';break;}
        if($error===''){
            $values=array_values($contactFields);
            if($contactEditId){$values[]=$contactEditId;$q=db()->prepare('UPDATE resource_sharing_contacts SET university=?,cls_contact=?,cls_email=?,cls_phone=?,p2p_email=?,p2p_phone=?,ill_email=?,ill_phone=? WHERE id=?');$q->execute($values);}
            else{$q=db()->prepare('INSERT INTO resource_sharing_contacts(university,cls_contact,cls_email,cls_phone,p2p_email,p2p_phone,ill_email,ill_phone) VALUES(?,?,?,?,?,?,?,?)');$q->execute($values);}
            flash($contactEditId?'Resource Sharing contact updated.':'Resource Sharing contact added.');redirect('admin.php?section=resource-sharing');
        }
    }elseif($action==='delete_resource_contact'){
        $q=db()->prepare('DELETE FROM resource_sharing_contacts WHERE id=?');$q->execute([(int)($_POST['contact_id']??0)]);flash('Resource Sharing contact deleted.');redirect('admin.php?section=resource-sharing');
    }elseif($action==='lookup'){
        $screen='alma';$create['identifier']=trim((string)($_POST['identifier']??''));
        if($create['identifier']==='')$error='Enter an Alma user ID.';
        else{
            if($localCreator){
                $member=(string)user()['institution_slug'];
                $result=alma_request($member,'/users/'.rawurlencode($create['identifier']).'?user_id_type=all_unique&view=full&expand=none');
                if($result['ok']&&is_array($result['data']))$result['data']['_alma_member']=$member;
            }else $result=alma_lookup_user('', $create['identifier']);
            if(!$result['ok'])$error=$result['error'];
            else{
                $person=$result['data'];$create['display_name']=trim((string)($person['full_name']??trim((string)($person['first_name']??'').' '.(string)($person['last_name']??''))));
                $emails=$person['contact_info']['email']??[];if(isset($emails['email_address']))$emails=[$emails];$email='';
                foreach((array)$emails as $record)if(!empty($record['email_address'])){$email=(string)$record['email_address'];if(filter_var($record['preferred']??false,FILTER_VALIDATE_BOOLEAN))break;}
                $create['email']=strtolower(trim($email));$member=(string)($person['_alma_member']??'');$matched=array_values(array_filter($institutions,static fn(array $i): bool=>$i['slug']===$member));
                if($localCreator)$create['institution_id']=tenant_id();elseif($matched)$create['institution_id']=(int)$matched[0]['id'];
                $lookupLoaded=true;
                $message='Alma details loaded. Review and complete the account fields below.';
            }
        }
    }elseif($action==='create_account'){
        $screen=(string)($_POST['origin']??'manual');
        $create['institution_id']=$localCreator?tenant_id():(int)($_POST['institution_id']??0);$create['identifier']=trim((string)($_POST['identifier']??''));$create['display_name']=trim((string)($_POST['display_name']??''));$create['email']=strtolower(trim((string)($_POST['email']??'')));$create['role']=$localCreator?'user':(string)($_POST['role']??'user');
        $lookupLoaded=$screen==='alma';
        $password=(string)($_POST['new_password']??'');$confirmation=(string)($_POST['confirm_password']??'');
        $institutionQuery=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$institutionQuery->execute([$create['institution_id']]);
        if(!$institutionQuery->fetchColumn())$error='Choose an active institution.';
        elseif($create['display_name']===''||!filter_var($create['email'],FILTER_VALIDATE_EMAIL))$error='Enter a name and valid email address.';
        elseif(mb_strlen($create['display_name'])>160||strlen($create['email'])>254)$error='The name must be 160 characters or fewer and the email address 254 characters or fewer.';
        elseif(!isset($roles[$create['role']]))$error='Choose a valid access level.';
        elseif($password===''||!hash_equals($password,$confirmation))$error='Enter matching passwords.';
        else{
            $duplicateQuery=db()->prepare('SELECT id FROM users WHERE LOWER(TRIM(email))=? LIMIT 1');$duplicateQuery->execute([$create['email']]);
            if($duplicateQuery->fetchColumn())$error='An account with this email address already exists, including disabled accounts.';
            else{
                try{$q=db()->prepare('INSERT INTO users(institution_id,email,display_name,password_hash,role) VALUES(?,?,?,?,?)');$q->execute([$create['institution_id'],$create['email'],$create['display_name'],password_hash($password,PASSWORD_DEFAULT),$create['role']]);flash('Account created for '.$create['display_name'].'.');redirect($localCreator?'admin.php?add=choose':'admin.php');}
                catch(PDOException $e){
                    $driverCode=(int)($e->errorInfo[1]??0);$sqlState=(string)$e->getCode();
                    $safeDetail=str_replace([$create['email'],$create['display_name']],['[email]','[name]'],$e->getMessage());
                    error_log('Claims Returned account creation failed: SQLSTATE='.$sqlState.' driver_code='.$driverCode.' detail='.$safeDetail);
                    $error=$driverCode===1062?'An account with this email address already exists, including disabled accounts.':'The account could not be saved (database error '.$driverCode.'). Check the application server log for details.';
                }
            }
        }
    }elseif($action==='update_account'){
        if(!$adminMode&&!$localManager){http_response_code(403);exit('Access denied.');}
        $id=(int)($_POST['user_id']??0);$name=trim((string)($_POST['display_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$institutionId=$localManager?tenant_id():(int)($_POST['institution_id']??0);$role=(string)($_POST['role']??'');$active=isset($_POST['active'])?1:0;$password=(string)($_POST['new_password']??'');$confirmation=(string)($_POST['confirm_password']??'');
        $institutionQuery=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$institutionQuery->execute([$institutionId]);
        $targetSql='SELECT id,institution_id,role,active FROM users WHERE id=?';if($localManager)$targetSql.=' AND institution_id=? AND role<>\'admin\'';$targetQuery=db()->prepare($targetSql);$targetQuery->execute($localManager?[$id,tenant_id()]:[$id]);$target=$targetQuery->fetch();
        if(!$target)$error='Account not found.';
        elseif(!$institutionQuery->fetchColumn())$error='Choose an active institution.';
        elseif($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))$error='Enter a name and valid email address.';
        elseif(!isset($roles[$role])||($localManager&&$role==='admin'))$error='Choose a valid access level.';
        elseif($id===(int)user()['id']&&(!$active||($adminMode&&$role!=='admin')||($localManager&&$role!=='super_user')))$error='You cannot remove your own elevated access or deactivate your account.';
        elseif($password!==''&&!admin_password_is_valid($password,$confirmation))$error='Set matching passwords with at least 12 characters.';
        else{
            $activeAdminCount=(int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            if($target['role']==='admin'&&$target['active']&&($role!=='admin'||!$active)&&$activeAdminCount<=1)$error='Keep at least one active admin account.';
            else{
                try{
                    if($password!==''){$q=db()->prepare('UPDATE users SET institution_id=?,email=?,display_name=?,role=?,active=?,password_hash=?,failed_login_count=0,locked_until=NULL WHERE id=?');$q->execute([$institutionId,$email,$name,$role,$active,password_hash($password,PASSWORD_DEFAULT),$id]);}
                    else{$q=db()->prepare('UPDATE users SET institution_id=?,email=?,display_name=?,role=?,active=? WHERE id=?');$q->execute([$institutionId,$email,$name,$role,$active,$id]);}
                    flash('Account updated.');redirect('admin.php?edit='.$id);
                }catch(PDOException){$error='That email address is already assigned to another account, or the account could not be saved.';}
            }
        }
        $_GET['edit']=(string)$id;
    }elseif($action==='delete_account'){
        if(!$adminMode&&!$localManager){http_response_code(403);exit('Access denied.');}
        $id=(int)($_POST['user_id']??0);$deleteSql='SELECT id,institution_id,role FROM users WHERE id=?';if($localManager)$deleteSql.=' AND institution_id=? AND role<>\'admin\'';$q=db()->prepare($deleteSql);$q->execute($localManager?[$id,tenant_id()]:[$id]);$target=$q->fetch();
        if(!$target)$error='Account not found or not available for deletion.';
        elseif($id===(int)user()['id'])$error='You cannot delete your own account.';
        elseif((string)($target['role']??'')==='admin'&&$adminMode&&(int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()<=1)$error='Keep at least one active admin account.';
        else{try{db()->beginTransaction();foreach([1,2,3,4] as $searchNumber){$q=db()->prepare("UPDATE claims_cases SET search_{$searchNumber}_by=NULL WHERE search_{$searchNumber}_by=?");$q->execute([$id]);}$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$id]);db()->commit();flash('Account deleted. Existing case history has been retained.');redirect('admin.php');}catch(PDOException){if(db()->inTransaction())db()->rollBack();$error='Account deletion requires the user deletion database migration. Apply migrations/2026-11-12-user-deletion.sql and try again.';}}
    }
}

$editId=(int)($_GET['edit']??0);
if(($adminMode||$localManager)&&$editId){$editSql='SELECT u.id,u.institution_id,u.email,u.display_name,u.role,u.active,u.last_login_at,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.id=?';if($localManager)$editSql.=' AND u.institution_id=? AND u.role<>\'admin\'';$q=db()->prepare($editSql);$q->execute($localManager?[$editId,tenant_id()]:[$editId]);$edit=$q->fetch()?:null;if(!$edit){http_response_code(404);exit('Account not found.');}}
$contacts=[];$contactEdit=null;$contactUniversityOptions=[];
if($adminMode&&$section==='resource-sharing'){
    if($contactEditId){$q=db()->prepare('SELECT * FROM resource_sharing_contacts WHERE id=?');$q->execute([$contactEditId]);$contactEdit=$q->fetch()?:null;if(!$contactEdit&&$_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(404);exit('Contact not found.');}if($contactEdit&&$_SERVER['REQUEST_METHOD']!=='POST')$contactFields=array_merge($contactFields,$contactEdit);}
    $contacts=db()->query('SELECT * FROM resource_sharing_contacts ORDER BY university,id')->fetchAll();
    $contactUniversityOptions=db()->query('SELECT name FROM institutions WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}
$accounts=[];$search='';$filterInstitution=0;$filterRole='';
if($adminMode||$localManager){
    $search=trim((string)($_GET['q']??''));$filterInstitution=(int)($_GET['institution_id']??0);$filterRole=(string)($_GET['role']??'');
    $where=[];$params=[];if($localManager){$where[]='u.institution_id=? AND u.role<>\'admin\'';$params[]=tenant_id();}
    if($search!==''){$where[]='(u.email LIKE ? OR u.display_name LIKE ? OR i.name LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
    if($filterInstitution){$where[]='u.institution_id=?';$params[]=$filterInstitution;}
    if(isset($roles[$filterRole])){$where[]='u.role=?';$params[]=$filterRole;}
    $sql='SELECT u.id,u.institution_id,u.email,u.display_name,u.role,u.active,u.last_login_at,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id';
    if($where)$sql.=' WHERE '.implode(' AND ',$where);
    $sql.=' ORDER BY i.name,u.display_name LIMIT 200';$q=db()->prepare($sql);$q->execute($params);$accounts=$q->fetchAll();
}

render_start($adminMode&&$section==='resource-sharing'?'Admin · Resource Sharing Contact List':($adminMode?'Admin · User accounts':'Institution accounts'));
echo '<div class="page-title"><div><p class="eyebrow">Administration</p><h1>'.($section==='resource-sharing'?'Resource Sharing Contact List':($adminMode?'User accounts':'Institution accounts')).'</h1><p class="muted">'.($section==='resource-sharing'?'Manage CLS, P2P, and ILL partner contacts.':' '.($adminMode?'Create accounts, manage access, and reset passwords.':'Manage accounts for '.h(institution_label((string)user()['institution_name'])).'.')).'</p></div>';
if(!$edit&&$screen===''&&$section==='accounts')echo '<a class="button" href="admin.php?add=choose" style="color:#fff !important">Add account</a>';
echo '</div>';
if($adminMode)echo '<nav class="admin-sections"><a href="admin.php"'.($section==='accounts'?' aria-current="page"':'').'>User accounts</a><a href="admin.php?section=resource-sharing"'.($section==='resource-sharing'?' aria-current="page"':'').'>Resource Sharing Contact List</a></nav>';
if($error)echo '<div class="flash error">'.h($error).'</div>';if($message)echo '<div class="flash">'.h($message).'</div>';
if($section==='accounts'&&$screen==='choose'&&!$edit){
    echo '<section class="card"><h2>Add an account</h2><p>Choose how to enter the account details.</p><div class="actions"><a class="button" href="admin.php?add=manual">Create manually</a><a class="button secondary" href="admin.php?add=alma">Look up in Alma</a></div><p><a href="'.($localCreator?'index.php':'admin.php').'">'.($localCreator?'Back to queue':'Back to accounts').'</a></p></section>';
}
if($section==='accounts'&&$screen==='alma'&&!$edit&&!$lookupLoaded){
    echo '<section class="card"><h2>Look up in Alma</h2><p class="muted">'.($localCreator?'Search your institution’s Alma by user ID.':'Search across the configured Alma member institutions by user ID.').'</p><form method="post" class="lookup-row"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="lookup"><label>Alma user ID<input name="identifier" value="'.h($create['identifier']).'" required autofocus></label><button>Search Alma</button></form><p><a href="admin.php?add=choose">Back to account options</a></p></section>';
}
if($section==='accounts'&&$screen==='manual'&&!$edit){
    echo '<section class="card"><h2>Create account manually</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create_account"><input type="hidden" name="origin" value="manual"><div class="two"><label>Full name<input name="display_name" value="'.h($create['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($create['email']).'" required></label></div>'.admin_account_scope_fields($adminMode,$institutions,(int)$create['institution_id'],$roles,$create['role']).'<div class="two"><label>Initial password<input type="password" name="new_password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" required></label></div><button>Create account</button></form><small>Passwords are stored as one-way hashes. Existing passwords are never shown; an admin can set a new one.</small><p><a href="admin.php?add=choose">Back to account options</a></p></section>';
}
if($section==='accounts'&&$screen==='alma'&&$lookupLoaded&&!$edit){
    echo '<section class="card"><h2>Finish account details</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create_account"><input type="hidden" name="origin" value="alma"><input type="hidden" name="identifier" value="'.h($create['identifier']).'"><div class="two"><label>Full name<input name="display_name" value="'.h($create['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($create['email']).'" required></label></div>'.admin_account_scope_fields($adminMode,$institutions,(int)$create['institution_id'],$roles,$create['role']).'<div class="two"><label>Initial password<input type="password" name="new_password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" required></label></div><button>Create account</button></form><small>Passwords are stored as one-way hashes. Alma does not provide an existing password.</small></section>';
}
if($section==='accounts'&&$edit){$editInstitutionField=$localManager?'<label>Institution<input value="'.h(institution_label((string)$edit['institution_name'])).'" readonly></label><input type="hidden" name="institution_id" value="'.tenant_id().'">':'<label>Institution<select name="institution_id" required>'.admin_institution_options($institutions,(int)($_POST['institution_id']??$edit['institution_id'])).'</select></label>';$editRoleField='<label>Access level<select name="role">';foreach($roles as $value=>$label)$editRoleField.='<option value="'.h($value).'" '.((string)($_POST['role']??$edit['role'])===$value?'selected':'').'>'.h($label).'</option>';$editRoleField.='</select></label>';$editActiveField='<label><input type="checkbox" name="active" value="1" style="display:inline;width:auto" '.((int)($_POST['active']??$edit['active'])===1?'checked':'').'> Active account</label>';echo '<section class="card"><h2>Edit account</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update_account"><input type="hidden" name="user_id" value="'.(int)$edit['id'].'"><div class="two"><label>Full name<input name="display_name" value="'.h($_POST['display_name']??$edit['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($_POST['email']??$edit['email']).'" required></label></div><div class="two">'.$editInstitutionField.$editRoleField.'</div>'.$editActiveField.'<p class="muted">Existing passwords cannot be viewed or recovered. Leave these fields blank to keep the current password.</p><div class="two"><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12"></label><label>Confirm new password<input type="password" name="confirm_password" autocomplete="new-password" minlength="12"></label></div><button>Save account</button></form></section>';}

if($section==='accounts'&&($adminMode||$localManager)&&($screen===''||$edit)){
echo '<section class="card"><h2>Find accounts</h2><form method="get" class="lookup-row"><label>Name, email, or institution<input name="q" value="'.h($search).'"></label><label>Institution<select name="institution_id"><option value="0">All institutions</option>'.admin_institution_options($institutions,$filterInstitution).'</select></label><label>Access level<select name="role"><option value="">All levels</option>';foreach($roles as $value=>$label)echo '<option value="'.h($value).'" '.($filterRole===$value?'selected':'').'>'.h($label).'</option>';echo '</select></label><button>Search</button></form><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Institution</th><th>Access</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>';
foreach($accounts as $account)echo '<tr><td>'.h($account['display_name']).'</td><td>'.h($account['email']).'</td><td>'.h(institution_label((string)$account['institution_name'])).'</td><td>'.h(admin_role_label($account['role'])).'</td><td>'.((int)$account['active']?'Active':'Disabled').'</td><td>'.h($account['last_login_at']?:'Never').'</td><td><div class="account-actions"><a class="button account-edit-button" href="admin.php?edit='.(int)$account['id'].'">Edit</a><form method="post" onsubmit="return confirm(\'Delete this account? Existing case history will be kept.\')"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="user_id" value="'.(int)$account['id'].'"><button class="danger secondary" type="submit">Delete</button></form></div></td></tr>';
if(!$accounts)echo '<tr><td colspan="7" class="empty">No accounts found.</td></tr>';
echo '</tbody></table></div><small>Showing up to 200 accounts.</small></section>';
}
if($adminMode&&$section==='resource-sharing'){
    $contactFormId=(int)($contactEdit['id']??$contactEditId);$contactHeading=$contactFormId?'Edit contact':'Add contact';
    if(!$contactFormId||$contactEdit||$error!==''){
        echo '<section class="card"><h2>'.$contactHeading.'</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="save_resource_contact"><input type="hidden" name="contact_id" value="'.$contactFormId.'"><label>University<select name="university" required><option value="">Select a university</option>';foreach($institutions as $institution)echo '<option value="'.h($institution['name']).'"'.(strcasecmp((string)$contactFields['university'],(string)$institution['name'])===0?' selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';echo '</select></label><div class="two"><label>CLS contact<input name="cls_contact" value="'.h($contactFields['cls_contact']).'" maxlength="160"></label><label>CLS email<input type="email" name="cls_email" value="'.h($contactFields['cls_email']).'" maxlength="254"></label><label>CLS phone<input name="cls_phone" value="'.h($contactFields['cls_phone']).'" maxlength="80"></label></div><div class="two"><label>P2P email<input type="email" name="p2p_email" value="'.h($contactFields['p2p_email']).'" maxlength="254"></label><label>P2P phone<input name="p2p_phone" value="'.h($contactFields['p2p_phone']).'" maxlength="80"></label><label>ILL email<input type="email" name="ill_email" value="'.h($contactFields['ill_email']).'" maxlength="254"></label><label>ILL phone<input name="ill_phone" value="'.h($contactFields['ill_phone']).'" maxlength="80"></label></div><button>Save contact</button>'.($contactFormId?'<a href="admin.php?section=resource-sharing">Cancel</a>':'').'</form></section>';
    }
    echo '<section class="card"><div class="page-title"><div><h2>Contacts by university</h2><p class="muted">University choices use the active institution list. Each email is used for the matching case type; staff can change the case email.</p></div><a class="button" href="admin.php?section=resource-sharing">Add contact</a></div><form method="post" enctype="multipart/form-data" class="csv-import-form"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="import_resource_contacts"><label>Import Google Sheets CSV<input type="file" name="contacts_csv" accept=".csv,text/csv" required></label><button class="secondary">Import CSV</button><small>Export the contact sheet as CSV. Required headers: university, cls_contact, cls_email, cls_phone, p2p_email, p2p_phone, ill_email, ill_phone.</small></form><div class="table-wrap"><table class="resource-sharing-table"><thead><tr><th>University</th><th>CLS contact / email / phone</th><th>P2P email / phone</th><th>ILL email / phone</th><th>Actions</th></tr></thead><tbody>';
    foreach($contacts as $contact){$cls=trim((string)($contact['cls_contact']??''));if($contact['cls_email'])$cls.=($cls?' · ':'').$contact['cls_email'];if($contact['cls_phone'])$cls.=($cls?' · ':'').$contact['cls_phone'];$p2p=trim((string)($contact['p2p_email']??''));if($contact['p2p_phone'])$p2p.=($p2p?' · ':'').$contact['p2p_phone'];$ill=trim((string)($contact['ill_email']??''));if($contact['ill_phone'])$ill.=($ill?' · ':'').$contact['ill_phone'];echo '<tr><td>'.h($contact['university']).'</td><td>'.h($cls?:'—').'</td><td>'.h($p2p?:'—').'</td><td>'.h($ill?:'—').'</td><td><div class="account-actions"><a class="button account-edit-button" href="admin.php?section=resource-sharing&amp;contact_edit='.(int)$contact['id'].'">Edit</a><form method="post" onsubmit="return confirm(\'Delete this Resource Sharing contact?\')"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="delete_resource_contact"><input type="hidden" name="contact_id" value="'.(int)$contact['id'].'"><button class="danger secondary" type="submit">Delete</button></form></div></td></tr>';}
    if(!$contacts)echo '<tr><td colspan="5" class="empty">No contacts have been added.</td></tr>';
    echo '</tbody></table></div></section>';
}
render_end();
