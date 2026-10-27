<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 verify_csrf(); $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
 $stmt=$pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE email=? LIMIT 1");$stmt->execute([$email]);$user=$stmt->fetch();
 if (!$user || !password_verify($password,$user['password_hash'])) $error='Invalid email or password.';
 elseif ($user['account_status']!=='approved') $error='Your account is awaiting administrator approval or is not currently active.';
 else { session_regenerate_id(true); unset($user['password_hash']); $_SESSION['user']=$user; header('Location: '.($user['role']==='system_admin'?'admin.php':'workspace.php')); exit; }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title><link rel="stylesheet" href="assets/css/site.css"></head><body><header><div class="wrap nav"><a class="brand" href="index.php">commonplace<span>.</span></a></div></header><main class="wrap" style="max-width:560px;padding:48px 0"><div class="panel"><div class="eyebrow">Welcome back</div><h1 style="font:40px Georgia,serif">Sign in</h1><?php if($error):?><p style="color:#a33"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><p><label>Email</label><input class="field" type="email" name="email" required></p><p><label>Password</label><input class="field" type="password" name="password" required></p><button class="btn">Sign in</button></form></div></main></body></html>
