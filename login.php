<?php
require __DIR__.'/src/bootstrap.php';
if (logged_in()) redirect();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');
 $q=db()->prepare('SELECT u.*,i.name institution_name,i.slug institution_slug,i.active institution_active,i.search_interval_days FROM users u JOIN institutions i ON i.id=u.institution_id WHERE u.email=? AND u.active=1 LIMIT 1');$q->execute([$email]);$u=$q->fetch();
 if($u&&$u['institution_active']&&(!($u['locked_until']??null)||strtotime($u['locked_until'].' UTC')<=time())&&password_verify($pass,$u['password_hash'])){session_regenerate_id(true);unset($u['password_hash'],$u['institution_active'],$u['failed_login_count'],$u['locked_until']);$_SESSION['user']=$u;$_SESSION['last_activity']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));$q=db()->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP(),failed_login_count=0,locked_until=NULL WHERE id=?');$q->execute([$u['id']]);redirect();}
 if($u){$q=db()->prepare('UPDATE users SET failed_login_count=failed_login_count+1,locked_until=IF(failed_login_count+1>=8,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),locked_until) WHERE id=?');$q->execute([$u['id']]);}
 $error='Email or password was not recognized.';
}
render_start('Sign in');
echo '<section class="login card"><h1>Claims Returned</h1><p>Sign in with your consortium staff account.</p>';if($error)echo '<div class="flash error">'.h($error).'</div>';echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Email<input type="email" name="email" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Sign in</button></form><p><a href="about.php">About this site and how to use it</a></p></section>';
render_end();
