<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$userId = (int)($_SESSION['password_setup_user_id'] ?? 0);
if ($userId < 1) {
    header('Location: register.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, email, email_verified_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || empty($user['email_verified_at'])) {
    unset($_SESSION['password_setup_user_id']);
    header('Location: register.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($password) < 12) {
        $error = 'Your password must be at least 12 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND email_verified_at IS NOT NULL');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        unset($_SESSION['password_setup_user_id']);
        session_regenerate_id(true);
        header('Location: login.php?password_created=1');
        exit;
    }
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create account password</title><link rel="stylesheet" href="assets/css/site.css"></head>
<body><header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><a href="../index.php">← Back to home</a></div></header>
<main class="wrap" style="max-width:560px;padding:48px 0"><div class="panel"><div class="eyebrow">Email verified</div><h1 style="font:40px Georgia,serif">Create your password.</h1><p class="muted">Your email address has been verified. Create a password of at least 12 characters to finish setting up your account.</p><p class="muted">Verified email: <?=e((string)$user['email'])?></p>
<?php if ($error): ?><p style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><p><label for="password">Password (minimum 12 characters)</label><input class="field" id="password" type="password" name="password" minlength="12" required autocomplete="new-password"></p><p><label for="confirm_password">Confirm password</label><input class="field" id="confirm_password" type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></p><button class="btn" type="submit">Save Password</button></form></div></main></body></html>
