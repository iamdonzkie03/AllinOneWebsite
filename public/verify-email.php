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
                $error = 'The verification code is incorrect or expired. Please check your email and try again.';
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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify email</title><link rel="stylesheet" href="assets/css/site.css"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#100b0e}body>header{display:none!important}main.wrap{box-sizing:border-box;width:100%;max-width:none;height:100%;padding:22px 26px!important;overflow:hidden}main.wrap>.panel{box-sizing:border-box;height:100%;padding:26px;overflow:auto}h1{font:36px Georgia,serif!important;margin:8px 0 12px}.muted{font-size:13px;line-height:1.5}form p{margin:14px 0}input.field{box-sizing:border-box;width:100%}.verification-error{color:#ff4545!important;font-weight:700!important;text-shadow:0 0 8px rgba(255,45,45,.18);margin:12px 0}.code-inputs{display:flex;gap:10px;margin-top:8px;max-width:430px}.code-digit{box-sizing:border-box;width:calc((100% - 50px)/6);height:58px;text-align:center;font-size:25px;font-weight:700;color:#fff;background:#21151b;border:1px solid rgba(255,255,255,.25);border-radius:9px;outline:none}.code-digit:focus{border-color:#c64b65;box-shadow:0 0 0 2px rgba(198,75,101,.2)}@media(max-width:420px){.code-inputs{gap:6px}.code-digit{height:50px;font-size:21px;width:calc((100% - 30px)/6)}}@media(max-width:560px){main.wrap{padding:12px!important}main.wrap>.panel{padding:18px}}</style></head>
<body><main class="wrap"><div class="panel"><div class="eyebrow">Email verification</div><h1>Check your inbox.</h1><p class="muted">Enter the six-digit code sent to your email address. The code expires after 10 minutes.</p>
<?php if ($error): ?><p class="verification-error" role="alert"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<p><label for="email">Email Address</label><input class="field" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($email)?>"></p>
<p><label for="code-digit-1">Six-digit verification code</label>
<div class="code-inputs" role="group" aria-label="Six-digit verification code">
<input class="code-digit" id="code-digit-1" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 1" autocomplete="one-time-code" required>
<input class="code-digit" id="code-digit-2" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 2" autocomplete="off" required>
<input class="code-digit" id="code-digit-3" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 3" autocomplete="off" required>
<input class="code-digit" id="code-digit-4" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 4" autocomplete="off" required>
<input class="code-digit" id="code-digit-5" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 5" autocomplete="off" required>
<input class="code-digit" id="code-digit-6" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit 6" autocomplete="off" required>
</div>
<input type="hidden" id="code" name="code" value=""></p>
<button class="btn" type="submit">Verify Email and Continue</button></form></div></main><script>
(() => {
 const boxes = Array.from(document.querySelectorAll('.code-digit'));
 const hidden = document.getElementById('code');
 const form = hidden.form;
 const sync = () => { hidden.value = boxes.map(box => box.value).join(''); };
 boxes.forEach((box,index) => {
   box.addEventListener('input', () => {
     const digit = box.value.replace(/\D/g,'').slice(-1);
     box.value = digit;
     sync();
     if (digit && index < boxes.length-1) boxes[index+1].focus();
   });
   box.addEventListener('keydown', event => {
     if (event.key === 'Backspace' && !box.value && index > 0) boxes[index-1].focus();
     if (event.key === 'ArrowLeft' && index > 0) { event.preventDefault(); boxes[index-1].focus(); }
     if (event.key === 'ArrowRight' && index < boxes.length-1) { event.preventDefault(); boxes[index+1].focus(); }
   });
   box.addEventListener('paste', event => {
     const digits = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
     if (!digits) return;
     event.preventDefault();
     boxes.forEach((item,i) => item.value = digits[i] || '');
     sync();
     boxes[Math.min(digits.length,5)].focus();
   });
 });
 form.addEventListener('submit', event => {
   sync();
   if (!/^\d{6}$/.test(hidden.value)) {
     event.preventDefault();
     boxes.find(box => !box.value)?.focus();
   }
 });
 boxes[0].focus();
})();
</script></body></html>
