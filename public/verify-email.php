<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$success = '';
$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $code = trim((string)($_POST['code'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
        $error = 'Enter the email address used during registration and the 6-digit code.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND email_verified_at IS NULL LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No pending email verification was found for that address. You may already have verified it or need to register again.';
        } else {
            $stmt = $pdo->prepare("SELECT id, code_hash FROM email_verification_codes WHERE user_id = ? AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int)$user['id']]);
            $record = $stmt->fetch();

            if (!$record || !password_verify($code, $record['code_hash'])) {
                $error = 'The code is invalid or expired. Please check your email and try again.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE email_verification_codes SET used_at = NOW() WHERE id = ?")->execute([(int)$record['id']]);
                $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
                $pdo->commit();
                header('Location: login.php?verified=1');
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify email</title><link rel="stylesheet" href="assets/css/site.css"></head>
<body><header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><a href="../index.php">← Back to home</a></div></header>
<main class="wrap" style="max-width:560px;padding:48px 0"><div class="panel"><div class="eyebrow">Email verification</div><h1 style="font:40px Georgia,serif">Check your inbox.</h1><p class="muted">Enter the six-digit code sent to your email address. The code expires after 10 minutes.</p>
<?php if ($error): ?><p style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<p><label for="email">Email Address</label><input class="field" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($email)?>"></p>
<p><label for="code">Six-digit verification code</label><input class="field" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code"></p>
<button class="btn" type="submit">Verify Email</button></form></div></main></body></html>
