<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 verify_csrf(); $loginInput=trim((string)($_POST['login']??'')); $email=strtolower($loginInput); $mobile=preg_replace('/[\\s().-]/','',$loginInput); if (preg_match('/^09[0-9]{9}$/',$mobile)) $mobile='+63'.substr($mobile,1); elseif (preg_match('/^9[0-9]{9}$/',$mobile)) $mobile='+63'.$mobile; $password=(string)($_POST['password']??'');
 $stmt=$pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE email=? OR mobile_no=? LIMIT 1");$stmt->execute([$email,$mobile]);$user=$stmt->fetch();
 if (!$user || !password_verify($password,$user['password_hash'])) $error='Invalid email/mobile number or password.';
 elseif ($user['account_status']!=='approved') $error='Your account is awaiting administrator approval or is not currently active.';
 else { session_regenerate_id(true); unset($user['password_hash']); $_SESSION['user']=$user; header('Location: '.($user['role']==='system_admin'?'admin.php':'workspace.php')); exit; }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title><link rel="stylesheet" href="assets/css/site.css"></head><body><header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a></div></header><main class="wrap" style="max-width:560px;padding:48px 0"><div class="panel"><div class="eyebrow">Welcome back</div><h1 style="font:40px Georgia,serif">Sign in</h1><?php if($error):?><p style="color:#a33"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><p><label for="login">Email or Contact/Mobile No.</label><input class="field" id="login" type="text" name="login" required autocomplete="username" inputmode="text" placeholder="Enter email or tap to enter +63 mobile number"></p><p><label>Password</label><input class="field" type="password" name="password" required></p><button class="btn">Sign in</button></form></div></main><script>
(() => {
  const field = document.getElementById('login');
  const prefix = '+63';
  field.addEventListener('focus', () => {
    if (field.value.trim() === '') {
      field.value = prefix;
      field.setSelectionRange(field.value.length, field.value.length);
    }
  });
  field.addEventListener('input', () => {
    const value = field.value;
    // If the user starts entering an email address, remove the mobile prefix.
    if (/[A-Za-z@_]/.test(value) && value.startsWith(prefix)) {
      field.value = value.slice(prefix.length).trimStart();
    }
  });
})();
</script></body></html>
