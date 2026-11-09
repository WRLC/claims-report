<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$pdo=db();
$columns=$pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('failed_login_count',$columns,true))$pdo->exec('ALTER TABLE users ADD COLUMN failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0');
if(!in_array('locked_until',$columns,true))$pdo->exec('ALTER TABLE users ADD COLUMN locked_until DATETIME NULL');
$pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','staff','viewer','user','super_user') NOT NULL DEFAULT 'user'");
$pdo->exec("UPDATE users SET role='user' WHERE role IN ('staff','viewer')");
$pdo->exec("ALTER TABLE users MODIFY role ENUM('user','super_user','admin') NOT NULL DEFAULT 'user'");
echo "Access levels migrated. Existing staff/viewer accounts now have user access; admin accounts remain admins.\n";
