<?php
require __DIR__.'/src/bootstrap.php';
if(logged_in())redirect();

$name=trim((string)($_POST['name']??''));
$email=strtolower(trim((string)($_POST['email']??'')));
$institutionId=(int)($_POST['institution_id']??0);
$error='';
$success=false;
$institutions=db()->query('SELECT id,name FROM institutions WHERE active=1 ORDER BY name')->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 if(trim((string)($_POST['website']??''))!==''){
  $success=true;
 }elseif((int)($_SESSION['account_request_last_sent']??0)>time()-60){
  $error='Please wait a minute before submitting another request.';
 }elseif($name===''||mb_strlen($name)>160){
  $error='Enter your name using 160 characters or fewer.';
 }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254){
  $error='Enter a valid email address.';
 }else{
  $institutionQuery=db()->prepare('SELECT id,name FROM institutions WHERE id=? AND active=1 LIMIT 1');
  $institutionQuery->execute([$institutionId]);$institution=$institutionQuery->fetch();
  if(!$institution){
   $error='Choose an active institution from the list.';
  }else{
   $recipientQuery=db()->prepare("SELECT email FROM users WHERE institution_id=? AND role='super_user' AND active=1 ORDER BY id");
   $recipientQuery->execute([$institutionId]);$recipients=array_values(array_unique(array_filter(array_map('trim',$recipientQuery->fetchAll(PDO::FETCH_COLUMN)),static fn(string $address): bool=>(bool)filter_var($address,FILTER_VALIDATE_EMAIL))));
   if(!$recipients){
    $recipients=array_values(array_unique(array_filter(array_map('trim',db()->query("SELECT email FROM users WHERE role='admin' AND active=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)),static fn(string $address): bool=>(bool)filter_var($address,FILTER_VALIDATE_EMAIL))));
   }
   if(!$recipients){
    $error='We could not route your request right now. Please contact your institution administrator.';
   }else{
    $from=envv('MAIL_FROM','claims@localhost');
    $fromName=str_replace(["\\r","\\n"],' ',envv('MAIL_FROM_NAME','WRLC Claims Returned'));
    $subject='Claims Returned account request: '.str_replace(["\\r","\\n"],' ',(string)$institution['name']);
    $body="A user has requested access to Claims Returned.\n\nName: {$name}\nEmail: {$email}\nInstitution: {$institution['name']}\n\nPlease contact the requester to follow up and create an account if appropriate.";
    $headers=['From: '.$fromName.' <'.$from.'>','Reply-To: '.$email,'Content-Type: text/plain; charset=UTF-8'];
    $sentCount=0;
    foreach($recipients as $recipient)if(@mail($recipient,$subject,$body,implode("\r\n",$headers)))$sentCount++;
    if($sentCount>0){
     $_SESSION['account_request_last_sent']=time();
     $success=true;
    }else{
     $error='We could not send your request right now. Please try again later or contact your institution administrator.';
    }
   }
  }
 }
}

render_start('Request User Account');
echo '<section class="login card"><h1>Request User Account</h1><p>Submit your details and the appropriate account administrator will follow up with you.</p>';
if($success)echo '<div class="flash">Your account request has been sent to the appropriate administrator.</div>';
if($error!=='')echo '<div class="flash error">'.h($error).'</div>';
if(!$success){
 echo '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><label>Name<input type="text" name="name" value="'.h($name).'" maxlength="160" autocomplete="name" required></label><label>Email address<input type="email" name="email" value="'.h($email).'" maxlength="254" autocomplete="email" required></label><label>Institution<select name="institution_id" required><option value="">Select your institution</option>';
 foreach($institutions as $institution)echo '<option value="'.(int)$institution['id'].'" '.($institutionId===(int)$institution['id']?'selected':'').'>'.h(institution_label((string)$institution['name'])).'</option>';
 echo '</select></label><div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div><button>Send account request</button></form>';
}
echo '<p class="login-about"><a href="login.php">Return to sign in</a></p></section>';
render_end();
