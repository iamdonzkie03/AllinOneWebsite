<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { verify_csrf(); } catch (Throwable $e) { $error = 'Your session expired. Refresh and try again.'; }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $code = trim((string)($_POST['code'] ?? ''));
    if ($error === '' && (!filter_var($email,FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/',$code))) {
        $error = 'Enter the email address used during registration and the six-digit code.';
    }
    if ($error === '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND email_verified_at IS NULL LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'No pending email verification was found for that address. You may already have verified it or need to register again.';
        } else {
            $stmt = $pdo->prepare('SELECT id, code_hash FROM email_verification_codes WHERE user_id = ? AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $record = $stmt->fetch();
            if (!$record || !password_verify($code,$record['code_hash'])) {
                $error = 'The code is invalid or expired. Please check your email and try again.';
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE email_verification_codes SET used_at = NOW() WHERE id = ?')->execute([(int)$record['id']]);
                    $pdo->prepare("UPDATE users SET email_verified_at = NOW(), account_status = 'approved' WHERE id = ?")->execute([(int)$user['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                session_regenerate_id(true);
                $_SESSION['password_setup_user_id'] = (int)$user['id'];
                header('Location: set-password.php');
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify email</title><link rel="stylesheet" href="assets/css/site.css"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#100b0e}body>header{display:none!important}main.wrap{box-sizing:border-box;width:100%;max-width:none;height:100%;padding:22px 26px!important;overflow:hidden}main.wrap>.panel{box-sizing:border-box;height:100%;padding:26px;overflow:auto}h1{font:36px Georgia,serif!important;margin:8px 0 12px}.muted{font-size:13px;line-height:1.5}form p{margin:14px 0}input.field{box-sizing:border-box;width:100%}@media(max-width:560px){main.wrap{padding:12px!important}main.wrap>.panel{padding:18px}}</style></head>
<body><main class="wrap"><div class="panel"><div class="eyebrow">Email verification</div><h1>Check your inbox.</h1><p class="muted">Enter the six-digit code sent to your email address. The code expires after 10 minutes.</p>
<?php if ($error): ?><p role="alert" style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<p><label for="email">Email Address</label><input class="field" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($email)?>"></p>
<p><label for="code">Six-digit verification code</label><input class="field" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code"></p>
<button class="btn" type="submit">Verify Email and Continue</button></form></div></main></body></html>
