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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create account password</title><link rel="stylesheet" href="assets/css/site.css"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#100b0e}body>header{display:none!important}main.wrap{box-sizing:border-box;width:100%;max-width:none;height:100%;padding:22px 26px!important;overflow:hidden}main.wrap>.panel{box-sizing:border-box;height:100%;padding:26px;overflow:auto}h1{font:36px Georgia,serif!important;margin:8px 0 12px}.muted{font-size:13px;line-height:1.5}form p{margin:14px 0}input.field{box-sizing:border-box;width:100%}.password-wrap{position:relative}.password-wrap input.field{padding-right:50px}.password-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:0;background:transparent;color:#c9b8bf;cursor:pointer;display:grid;place-items:center}.password-toggle svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.strength{margin:10px 0 16px}.strength-track{height:7px;border-radius:8px;background:#392a31;overflow:hidden}.strength-fill{height:100%;width:0;background:#d34a62;transition:width .2s,background .2s}.strength-caption{font-size:12px;color:#c9b8bf;margin:6px 0 8px}.strength-rules{display:grid;grid-template-columns:1fr 1fr;gap:7px 14px;margin:0;padding:0;list-style:none}.strength-rules li{font-size:12px;color:#b9aab0;display:flex;align-items:center;gap:7px}.strength-rules li::before{content:"○";font-size:15px;color:#b9aab0}.strength-rules li.met{color:#79cf9a}.strength-rules li.met::before{content:"✓";color:#79cf9a;font-weight:700}.password-error{color:#ff4545!important;font-weight:700!important}@media(max-width:560px){main.wrap{padding:12px!important}main.wrap>.panel{padding:18px}.strength-rules{grid-template-columns:1fr}}</style></head>
<body><main class="wrap"><div class="panel"><div class="eyebrow">Email verified</div><h1>Create your password.</h1><p class="muted">Your email address has been verified. Create a password with at least 10 characters, including an uppercase letter, a number, and a symbol.</p><p class="muted">Verified email: <?=e((string)$user['email'])?></p>
<?php if ($error): ?><p class="password-error" role="alert"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<p><label for="password">Password</label><span class="password-wrap"><input class="field" id="password" type="password" name="password" minlength="10" required autocomplete="new-password"><button class="password-toggle" type="button" data-toggle="password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span></p>
<div class="strength" aria-live="polite"><div class="strength-track"><div class="strength-fill" id="strengthFill"></div></div><p class="strength-caption" id="strengthCaption">Password strength: 0 of 4 requirements met</p><ul class="strength-rules"><li id="ruleUpper">At least 1 capital letter</li><li id="ruleNumber">At least 1 number</li><li id="ruleSymbol">At least 1 symbol</li><li id="ruleLength">Minimum 10 characters</li></ul></div>
<p><label for="confirm_password">Confirm password</label><span class="password-wrap"><input class="field" id="confirm_password" type="password" name="confirm_password" minlength="10" required autocomplete="new-password"><button class="password-toggle" type="button" data-toggle="confirm_password" aria-label="Show confirm password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span></p>
<button class="btn" type="submit">Save Password</button></form>
<script>
(() => {
 const password = document.getElementById('password');
 const fill = document.getElementById('strengthFill');
 const caption = document.getElementById('strengthCaption');
 const rules = [
   [document.getElementById('ruleUpper'), value => /[A-Z]/.test(value)],
   [document.getElementById('ruleNumber'), value => /[0-9]/.test(value)],
   [document.getElementById('ruleSymbol'), value => /[^a-zA-Z0-9]/.test(value)],
   [document.getElementById('ruleLength'), value => value.length >= 10]
 ];
 function updateStrength() {
   const value = password.value;
   let met = 0;
   rules.forEach(([item, test]) => {
     const passed = test(value);
     item.classList.toggle('met', passed);
     if (passed) met++;
   });
   fill.style.width = (met * 25) + '%';
   fill.style.background = met === 4 ? '#36b978' : '#d34a62';
   caption.textContent = 'Password strength: ' + met + ' of 4 requirements met';
 }
 password.addEventListener('input', updateStrength);
 document.querySelectorAll('[data-toggle]').forEach(button => {
   button.addEventListener('click', () => {
     const input = document.getElementById(button.dataset.toggle);
     const reveal = input.type === 'password';
     input.type = reveal ? 'text' : 'password';
     button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
     button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
     button.innerHTML = reveal
       ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1 0 1.9-.2 2.8-.5"/></svg>'
       : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
   });
 });
 updateStrength();
})();
</script></div></main></body></html>
