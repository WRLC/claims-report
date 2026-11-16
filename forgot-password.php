<?php
require __DIR__.'/src/bootstrap.php';
if(logged_in())redirect();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$email=strtolower(trim((string)($_POST['email']??'')));
$error='';
$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 if(trim((string)($_POST['website']??''))!==''){
  $success=true;
 }elseif((int)($_SESSION['password_reset_last_sent']??0)>time()-60){
  $error='Please wait a minute before requesting another link.';
 }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254){
  $error='Enter a valid email address.';
 }else{
  send_password_reset_link($email);
  $_SESSION['password_reset_last_sent']=time();
  $success=true;
 }
}

render_start('Forgot Password');
echo '<section class="login card"><h1>Forgot Password?</h1><p>Enter your account email address. If an active account uses it, we will email a link to verify ownership and choose a new password.</p>';
if($success)echo '<div class="flash">If an active account uses this email, password reset instructions have been sent.</div>';
if($error!=='')echo '<div class="flash error">'.h($error).'</div>';
if(!$success)echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Email address<input type="email" name="email" value="'.h($email).'" maxlength="254" autocomplete="email" required autofocus></label><div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div><button>Send recovery link</button></form>';
echo '<p class="login-about"><a href="login.php">Return to sign in</a></p></section>';
render_end();
