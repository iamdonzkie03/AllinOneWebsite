<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $method = (string)($_POST['login_method'] ?? 'email');
    $loginInput = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($method === 'mobile') {
        $mobileDigits = preg_replace('/\\D/', '', $loginInput) ?? '';
        // Normalize pasted or typed country codes to exactly one +63 prefix.
        while (str_starts_with($mobileDigits, '63')) {
            $mobileDigits = substr($mobileDigits, 2);
        }
        if (str_starts_with($mobileDigits, '0')) {
            $mobileDigits = substr($mobileDigits, 1);
        }
        $mobile = '+63' . $mobileDigits;
        $stmt = $pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE mobile_no=? LIMIT 1");
        $stmt->execute([$mobile]);
    } else {
        $email = strtolower($loginInput);
        $stmt = $pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
    }
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Invalid email/mobile number or password.';
    } elseif ($user['account_status'] !== 'approved') {
        $error = 'Your account is awaiting administrator approval or is not currently active.';
    } else {
        $targetContext = ($user['role'] === 'system_admin') ? 'admin' : 'creator';
        switch_app_session_context($targetContext);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        header('Location: ' . ($targetContext === 'admin' ? 'admin.php' : 'workspace.php'));
        exit;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in</title>
<link rel="stylesheet" href="assets/css/site.css">
<style>
.login-methods{display:flex;gap:22px;flex-wrap:wrap;margin:10px 0 14px}
.login-methods label{display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:14px}
.login-methods input{accent-color:#9b263b}
</style>
</head>
<body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a></div></header>
<main class="wrap" style="max-width:560px;padding:48px 0">
<div class="panel">
<div class="eyebrow">Welcome back</div>
<h1 style="font:40px Georgia,serif">Sign in</h1>
<?php if ($error): ?><p role="alert" style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<fieldset style="border:0;padding:0;margin:0 0 14px">
<legend style="font-size:14px;font-weight:600;margin-bottom:8px">Sign in using</legend>
<div class="login-methods">
<label><input type="radio" name="login_method" value="email" checked> Email</label>
<label><input type="radio" name="login_method" value="mobile"> Contact/Mobile No.</label>
</div>
</fieldset>
<p><label id="login-label" for="login">Email Address</label>
<input class="field" id="login" type="text" name="login" required maxlength="190" autocomplete="username" inputmode="email" placeholder="Enter your email address"></p>
<p><label for="password">Password</label><input class="field" id="password" type="password" name="password" required autocomplete="current-password"></p>
<button class="btn" type="submit">Sign in</button>
</form>
</div>
</main>
<script>
(() => {
 const input = document.getElementById('login');
 const label = document.getElementById('login-label');
 const choices = document.querySelectorAll('input[name="login_method"]');

 function updateMethod() {
   const selected = document.querySelector('input[name="login_method"]:checked').value;
   if (selected === 'mobile') {
     label.textContent = 'Contact/Mobile No. (+63)';
     input.type = 'tel';
     input.inputMode = 'tel';
     input.placeholder = '+63 9XX XXXX XXX';
     input.maxLength = 20;
     input.autocomplete = 'tel';
   } else {
     label.textContent = 'Email Address';
     input.type = 'email';
     input.inputMode = 'email';
     input.placeholder = 'Enter your email address';
     input.maxLength = 190;
     input.autocomplete = 'username';
     if (input.value.trim() === '+63') input.value = '';
   }
 }

 choices.forEach(choice => choice.addEventListener('change', updateMethod));

 // Match the Create Account contact-number textbox exactly:
 // accept local or pasted international digits, normalize to +63,
 // and display the number in +63 9XX XXXX XXX format.
 input.addEventListener('input', () => {
   const selected = document.querySelector('input[name="login_method"]:checked').value;
   if (selected !== 'mobile') return;

   let digits = input.value.replace(/\D/g, '');
   if (digits.startsWith('0063')) digits = digits.slice(4);
   else if (digits.startsWith('63')) digits = digits.slice(2);
   if (digits.startsWith('0')) digits = digits.slice(1);
   digits = digits.slice(0, 10);

   const parts = [];
   if (digits.length) parts.push(digits.slice(0, 3));
   if (digits.length > 3) parts.push(digits.slice(3, 7));
   if (digits.length > 7) parts.push(digits.slice(7, 10));
   input.value = '+63' + (parts.length ? ' ' + parts.join(' ') : '');
 });
})();
</script>
</body>
</html>
