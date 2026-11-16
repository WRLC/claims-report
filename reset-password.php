<?php
require __DIR__.'/src/bootstrap.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$token=trim((string)($_POST['token']??$_GET['token']??''));
$tokenHash=preg_match('/^[a-f0-9]{64}$/i',$token)?hash('sha256',$token):'';
$password=(string)($_POST['password']??'');
$confirmation=(string)($_POST['password_confirmation']??'');
$error='';
$success=false;
$tokenValid=false;
if($tokenHash!==''){
 $lookup=db()->prepare('SELECT 1 FROM password_reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.active=1 LIMIT 1');
 $lookup->execute([$tokenHash]);$tokenValid=(bool)$lookup->fetchColumn();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 if(!$tokenValid){
  $error='This password reset link is invalid or has expired. Request a new link and try again.';
 }elseif(strlen($password)<8||strlen($password)>4096){
  $error='Choose a password between 8 and 4,096 characters.';
 }elseif(!hash_equals($password,$confirmation)){
  $error='The passwords do not match.';
 }else{
  $pdo=db();$pdo->beginTransaction();
  try{
   $q=$pdo->prepare('SELECT t.id,t.user_id FROM password_reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.active=1 FOR UPDATE');
   $q->execute([$tokenHash]);$reset=$q->fetch();
   if(!$reset){
    $pdo->rollBack();$tokenValid=false;$error='This password reset link is invalid or has expired. Request a new link and try again.';
   }else{
    $update=$pdo->prepare('UPDATE users SET password_hash=?,failed_login_count=0,locked_until=NULL WHERE id=? AND active=1');
    $update->execute([password_hash($password,PASSWORD_DEFAULT),(int)$reset['user_id']]);
    $consume=$pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL');$consume->execute([(int)$reset['id']]);
    $remove=$pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND id<>?');$remove->execute([(int)$reset['user_id'],(int)$reset['id']]);
    $pdo->commit();
    $_SESSION=[];session_regenerate_id(true);
    $_SESSION['flash']=['Your password has been updated. Sign in with your new password.','success'];
    redirect('login.php');
   }
  }catch(Throwable $exception){
   if($pdo->inTransaction())$pdo->rollBack();
   throw $exception;
  }
 }
}

render_start('Choose a New Password');
echo '<section class="login card"><h1>Choose a New Password</h1>';
if($tokenValid){
 echo '<p>Verify ownership of your account by choosing a new password. This link can only be used once.</p>';
 if($error!=='')echo '<div class="flash error">'.h($error).'</div>';
 echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="token" value="'.h($token).'"><label>New password<input type="password" name="password" minlength="8" maxlength="4096" autocomplete="new-password" required autofocus></label><label>Confirm new password<input type="password" name="password_confirmation" minlength="8" maxlength="4096" autocomplete="new-password" required></label><button>Update password</button></form>';
}else{
 echo '<div class="flash error">'.h($error!==''?$error:'This password reset link is invalid or has expired. Request a new link and try again.').'</div>';
 echo '<p class="login-about"><a href="forgot-password.php">Request another recovery link</a></p>';
}
echo '<p class="login-about"><a href="login.php">Return to sign in</a></p></section>';
render_end();
