<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$userId = (int)($_SESSION['password_setup_user_id'] ?? 0);
if ($userId < 1) { header('Location: register.php'); exit; }
$stmt = $pdo->prepare('SELECT id, email, email_verified_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || empty($user['email_verified_at'])) {
    unset($_SESSION['password_setup_user_id']);
    header('Location: register.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { verify_csrf(); } catch (Throwable $e) { $error = 'Your session expired. Refresh and try again.'; }
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($error === '' && strlen($password) < 10) $error = 'Your password must be at least 10 characters long.';
    elseif ($error === '' && !preg_match('/[A-Z]/', $password)) $error = 'Your password must include at least one uppercase letter.';
    elseif ($error === '' && !preg_match('/[0-9]/', $password)) $error = 'Your password must include at least one number.';
    elseif ($error === '' && !preg_match('/[^a-zA-Z0-9]/', $password)) $error = 'Your password must include at least one symbol.';
    elseif ($error === '' && $password !== $confirm) $error = 'The passwords do not match.';
    if ($error === '') {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND email_verified_at IS NOT NULL');
        $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
        unset($_SESSION['password_setup_user_id']);
        session_regenerate_id(true);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Account verified</title></head><body><script>if(window.parent && window.parent !== window){window.parent.location.href="../index.php?signin=1&registered=1";}else{window.location.href="../index.php?signin=1&registered=1";}</script></body></html>';
        exit;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create account password</title><link rel="stylesheet" href="assets/css/site.css"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#100b0e}body>header{display:none!important}main.wrap{box-sizing:border-box;width:100%;max-width:none;height:100%;padding:22px 26px!important;overflow:hidden}main.wrap>.panel{box-sizing:border-box;height:100%;padding:26px;overflow:auto}h1{font:36px Georgia,serif!important;margin:8px 0 12px}.muted{font-size:13px;line-height:1.5}form p{margin:14px 0}input.field{box-sizing:border-box;width:100%}@media(max-width:560px){main.wrap{padding:12px!important}main.wrap>.panel{padding:18px}}</style></head>
<body><main class="wrap"><div class="panel"><div class="eyebrow">Email verified</div><h1>Create your password.</h1><p class="muted">Your email address has been verified. Create a password with at least 10 characters, including an uppercase letter, a number, and a symbol.</p><p class="muted">Verified email: <?=e((string)$user['email'])?></p>
<?php if ($error): ?><p role="alert" style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><p><label for="password">Password (minimum 10 characters; uppercase, number, and symbol required)</label><input class="field" id="password" type="password" name="password" minlength="10" required autocomplete="new-password"></p><p><label for="confirm_password">Confirm password</label><input class="field" id="confirm_password" type="password" name="confirm_password" minlength="10" required autocomplete="new-password"></p><button class="btn" type="submit">Save Password</button></form></div></main></body></html>
