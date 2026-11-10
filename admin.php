<?php
require __DIR__.'/src/bootstrap.php';
require_admin();

function admin_role_label(string $role): string {
    return match($role){'super_user'=>'Super user','admin'=>'Admin','staff','viewer'=>'User',default=>'User'};
}
function admin_institution_options(array $institutions,int $selected): string {
    $html='';foreach($institutions as $institution)$html.='<option value="'.(int)$institution['id'].'" '.($selected===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';
    return $html;
}
function admin_password_is_valid(string $password,string $confirmation): bool {
    return strlen($password)>=12&&hash_equals($password,$confirmation);
}

$institutionNames=[
    'gw'=>'George Washington University','au'=>'American University','cua'=>'Catholic University of America',
    'udc'=>'University of the District of Columbia','gallaudet'=>'Gallaudet University','gmu'=>'George Mason University',
    'georgetown'=>'Georgetown University','howard'=>'Howard University','marymount'=>'Marymount University','scf'=>'WRLC','scf'=>'WRLC',
];
$configuredMembers=array_values(array_filter(array_map('trim',explode(',',envv('ALMA_MEMBERS'))),static fn(string $slug): bool=>$slug!==''&&alma_key($slug)!==''));
$institutionLookup=db()->prepare('SELECT id,active FROM institutions WHERE slug=? LIMIT 1');
$activateInstitution=db()->prepare('UPDATE institutions SET active=1 WHERE id=?');
$addInstitution=db()->prepare('INSERT INTO institutions(slug,name,alma_code,active) VALUES(?,?,?,1)');
foreach($configuredMembers as $member){
    $institutionLookup->execute([$member]);
    $existingInstitution=$institutionLookup->fetch();
    if(!$existingInstitution){
        $name=$institutionNames[$member]??ucwords(str_replace(['-','_'],' ',$member));
        $addInstitution->execute([$member,$name,$member]);
    }elseif(!(int)$existingInstitution['active'])$activateInstitution->execute([(int)$existingInstitution['id']]);
}
$institutions=db()->query('SELECT id,name,slug FROM institutions WHERE active=1 ORDER BY name')->fetchAll();
$roles=['user'=>'User','super_user'=>'Super user','admin'=>'Admin'];
$create=['institution_id'=>tenant_id(),'identifier'=>'','display_name'=>'','email'=>'','role'=>'user'];
$edit=null;$error='';$message='';$screen=(string)($_GET['add']??'');$lookupLoaded=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=(string)($_POST['action']??'');
    if($action==='lookup'){
        $screen='alma';$create['identifier']=trim((string)($_POST['identifier']??''));
        if($create['identifier']==='')$error='Enter an Alma user ID.';
        else{
            $result=alma_lookup_user('', $create['identifier']);
            if(!$result['ok'])$error=$result['error'];
            else{
                $person=$result['data'];$create['display_name']=trim((string)($person['full_name']??trim((string)($person['first_name']??'').' '.(string)($person['last_name']??''))));
                $emails=$person['contact_info']['email']??[];if(isset($emails['email_address']))$emails=[$emails];$email='';
                foreach((array)$emails as $record)if(!empty($record['email_address'])){$email=(string)$record['email_address'];if(filter_var($record['preferred']??false,FILTER_VALIDATE_BOOLEAN))break;}
                $create['email']=strtolower(trim($email));$member=(string)($person['_alma_member']??'');$matched=array_values(array_filter($institutions,static fn(array $i): bool=>$i['slug']===$member));
                if($matched)$create['institution_id']=(int)$matched[0]['id'];
                $lookupLoaded=true;
                $message='Alma details loaded. Review and complete the account fields below.';
            }
        }
    }elseif($action==='create_account'){
        $screen=(string)($_POST['origin']??'manual');
        $create['institution_id']=(int)($_POST['institution_id']??0);$create['identifier']=trim((string)($_POST['identifier']??''));$create['display_name']=trim((string)($_POST['display_name']??''));$create['email']=strtolower(trim((string)($_POST['email']??'')));$create['role']=(string)($_POST['role']??'user');
        $lookupLoaded=$screen==='alma';
        $password=(string)($_POST['new_password']??'');$confirmation=(string)($_POST['confirm_password']??'');
        $institutionQuery=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$institutionQuery->execute([$create['institution_id']]);
        if(!$institutionQuery->fetchColumn())$error='Choose an active institution.';
        elseif($create['display_name']===''||!filter_var($create['email'],FILTER_VALIDATE_EMAIL))$error='Enter a name and valid email address.';
        elseif(!isset($roles[$create['role']]))$error='Choose a valid access level.';
        elseif($password===''||!hash_equals($password,$confirmation))$error='Enter matching passwords.';
        else{
            try{$q=db()->prepare('INSERT INTO users(institution_id,email,display_name,password_hash,role) VALUES(?,?,?,?,?)');$q->execute([$create['institution_id'],$create['email'],$create['display_name'],password_hash($password,PASSWORD_DEFAULT),$create['role']]);flash('Account created for '.$create['display_name'].'.');redirect('admin.php');}
            catch(PDOException){$error='That email address is already assigned to an account, or the account could not be saved.';}
        }
    }elseif($action==='update_account'){
        $id=(int)($_POST['user_id']??0);$name=trim((string)($_POST['display_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$institutionId=(int)($_POST['institution_id']??0);$role=(string)($_POST['role']??'');$active=isset($_POST['active'])?1:0;$password=(string)($_POST['new_password']??'');$confirmation=(string)($_POST['confirm_password']??'');
        $institutionQuery=db()->prepare('SELECT id FROM institutions WHERE id=? AND active=1');$institutionQuery->execute([$institutionId]);
        $targetQuery=db()->prepare('SELECT id,role,active FROM users WHERE id=?');$targetQuery->execute([$id]);$target=$targetQuery->fetch();
        if(!$target)$error='Account not found.';
        elseif(!$institutionQuery->fetchColumn())$error='Choose an active institution.';
        elseif($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))$error='Enter a name and valid email address.';
        elseif(!isset($roles[$role]))$error='Choose a valid access level.';
        elseif($id===(int)user()['id']&&($role!=='admin'||!$active))$error='You cannot remove your own admin access or deactivate your account.';
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
    }
}

$editId=(int)($_GET['edit']??0);
if($editId){$q=db()->prepare('SELECT u.id,u.institution_id,u.email,u.display_name,u.role,u.active,u.last_login_at,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.id=?');$q->execute([$editId]);$edit=$q->fetch()?:null;}
$search=trim((string)($_GET['q']??''));$filterInstitution=(int)($_GET['institution_id']??0);$filterRole=(string)($_GET['role']??'');
$where=[];$params=[];
if($search!==''){$where[]='(u.email LIKE ? OR u.display_name LIKE ? OR i.name LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
if($filterInstitution){$where[]='u.institution_id=?';$params[]=$filterInstitution;}
if(isset($roles[$filterRole])){$where[]='u.role=?';$params[]=$filterRole;}
$sql='SELECT u.id,u.institution_id,u.email,u.display_name,u.role,u.active,u.last_login_at,i.name institution_name FROM users u JOIN institutions i ON i.id=u.institution_id';
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY i.name,u.display_name LIMIT 200';$q=db()->prepare($sql);$q->execute($params);$accounts=$q->fetchAll();

render_start('Admin · User accounts');
echo '<div class="page-title"><div><p class="eyebrow">Administration</p><h1>User accounts</h1><p class="muted">Create accounts, manage access, and reset passwords.</p></div>';
if(!$edit&&$screen==='')echo '<a class="button" href="admin.php?add=choose" style="color:#fff !important">Add account</a>';
echo '</div>';
if($error)echo '<div class="flash error">'.h($error).'</div>';if($message)echo '<div class="flash">'.h($message).'</div>';
if($screen==='choose'&&!$edit){
    echo '<section class="card"><h2>Add an account</h2><p>Choose how to enter the account details.</p><div class="actions"><a class="button" href="admin.php?add=manual">Create manually</a><a class="button secondary" href="admin.php?add=alma">Look up in Alma</a></div><p><a href="admin.php">Back to accounts</a></p></section>';
}
if($screen==='alma'&&!$edit&&!$lookupLoaded){
    echo '<section class="card"><h2>Look up in Alma</h2><p class="muted">Search across the configured Alma member institutions by user ID.</p><form method="post" class="lookup-row"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="lookup"><label>Alma user ID<input name="identifier" value="'.h($create['identifier']).'" required autofocus></label><button>Search Alma</button></form><p><a href="admin.php?add=choose">Back to account options</a></p></section>';
}
if($screen==='manual'&&!$edit){
    echo '<section class="card"><h2>Create account manually</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create_account"><input type="hidden" name="origin" value="manual"><div class="two"><label>Full name<input name="display_name" value="'.h($create['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($create['email']).'" required></label></div><div class="two"><label>Institution<select name="institution_id" required>'.admin_institution_options($institutions,(int)$create['institution_id']).'</select></label><label>Access level<select name="role">';
    foreach($roles as $value=>$label)echo '<option value="'.h($value).'" '.($create['role']===$value?'selected':'').'>'.h($label).'</option>';
    echo '</select></label></div><div class="two"><label>Initial password<input type="password" name="new_password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" required></label></div><button>Create account</button></form><small>Passwords are stored as one-way hashes. Existing passwords are never shown; an admin can set a new one.</small><p><a href="admin.php?add=choose">Back to account options</a></p></section>';
}
if($screen==='alma'&&$lookupLoaded&&!$edit){
    echo '<section class="card"><h2>Finish account details</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="create_account"><input type="hidden" name="origin" value="alma"><input type="hidden" name="identifier" value="'.h($create['identifier']).'"><div class="two"><label>Full name<input name="display_name" value="'.h($create['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($create['email']).'" required></label></div><div class="two"><label>Institution<select name="institution_id" required>'.admin_institution_options($institutions,(int)$create['institution_id']).'</select></label><label>Access level<select name="role">';
    foreach($roles as $value=>$label)echo '<option value="'.h($value).'" '.($create['role']===$value?'selected':'').'>'.h($label).'</option>';
    echo '</select></label></div><div class="two"><label>Initial password<input type="password" name="new_password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" required></label></div><button>Create account</button></form><small>Passwords are stored as one-way hashes. Alma does not provide an existing password.</small></section>';
}
if($edit){echo '<section class="card"><h2>Edit account</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="update_account"><input type="hidden" name="user_id" value="'.(int)$edit['id'].'"><div class="two"><label>Full name<input name="display_name" value="'.h($_POST['display_name']??$edit['display_name']).'" required></label><label>Email<input type="email" name="email" value="'.h($_POST['email']??$edit['email']).'" required></label></div><div class="two"><label>Institution<select name="institution_id" required>'.admin_institution_options($institutions,(int)($_POST['institution_id']??$edit['institution_id'])).'</select></label><label>Access level<select name="role">';foreach($roles as $value=>$label)echo '<option value="'.h($value).'" '.((string)($_POST['role']??$edit['role'])===$value?'selected':'').'>'.h($label).'</option>';echo '</select></label></div><label><input type="checkbox" name="active" value="1" style="display:inline;width:auto" '.((int)($_POST['active']??$edit['active'])===1?'checked':'').'> Active account</label><p class="muted">Existing passwords cannot be viewed or recovered. Leave these fields blank to keep the current password.</p><div class="two"><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12"></label><label>Confirm new password<input type="password" name="confirm_password" autocomplete="new-password" minlength="12"></label></div><button>Save account</button></form></section>';}

if($screen===''||$edit){
echo '<section class="card"><h2>Find accounts</h2><form method="get" class="lookup-row"><label>Name, email, or institution<input name="q" value="'.h($search).'"></label><label>Institution<select name="institution_id"><option value="0">All institutions</option>'.admin_institution_options($institutions,$filterInstitution).'</select></label><label>Access level<select name="role"><option value="">All levels</option>';foreach($roles as $value=>$label)echo '<option value="'.h($value).'" '.($filterRole===$value?'selected':'').'>'.h($label).'</option>';echo '</select></label><button>Search</button></form><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Institution</th><th>Access</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>';
foreach($accounts as $account)echo '<tr><td>'.h($account['display_name']).'</td><td>'.h($account['email']).'</td><td>'.h(institution_label((string)$account['institution_name'])).'</td><td>'.h(admin_role_label($account['role'])).'</td><td>'.((int)$account['active']?'Active':'Disabled').'</td><td>'.h($account['last_login_at']?:'Never').'</td><td><a href="admin.php?edit='.(int)$account['id'].'">Edit</a></td></tr>';
if(!$accounts)echo '<tr><td colspan="7" class="empty">No accounts found.</td></tr>';
echo '</tbody></table></div><small>Showing up to 200 accounts.</small></section>';
}
render_end();
