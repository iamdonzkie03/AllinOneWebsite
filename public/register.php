<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
if (!isset($_SESSION['register_captcha']) || !is_array($_SESSION['register_captcha'])) $_SESSION['register_captcha'] = ['a' => random_int(2, 9), 'b' => random_int(1, 9)];
$captchaQuestion = (int)$_SESSION['register_captcha']['a'] . ' + ' . (int)$_SESSION['register_captcha']['b'];
$old = ['lastname'=>'','firstname'=>'','middlename'=>'','suffix'=>'','mobile_no'=>'','nickname'=>'','birthdate'=>'','birthplace'=>'','email'=>'','credential_notes'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { verify_csrf(); } catch (Throwable $e) { $error = 'Your session expired. Refresh the page and try again.'; }
    $captchaExpected = (int)($_SESSION['register_captcha']['a'] ?? 0) + (int)($_SESSION['register_captcha']['b'] ?? 0);
    $captchaProvided = trim((string)($_POST['captcha_answer'] ?? ''));
    unset($_SESSION['register_captcha']);
    if ($error === '' && ($captchaProvided === '' || !ctype_digit($captchaProvided) || (int)$captchaProvided !== $captchaExpected)) $error = 'Captcha verification failed. Please solve the new challenge and try again.';
    foreach ($old as $key => $_) $old[$key] = trim((string)($_POST[$key] ?? ''));
    $last = $old['lastname']; $first = $old['firstname']; $middle = $old['middlename']; $suffix = $old['suffix'];
    $mobile = preg_replace('/[\s().-]/', '', $old['mobile_no']) ?? '';
    if (preg_match('/^09[0-9]{9}$/', $mobile)) $mobile = '+63' . substr($mobile, 1);
    elseif (preg_match('/^9[0-9]{9}$/', $mobile)) $mobile = '+63' . $mobile;
    $email = strtolower($old['email']);
    $birthdate = $old['birthdate']; $birthplace = $old['birthplace']; $credentials = $old['credential_notes'];
    $date = DateTime::createFromFormat('!Y-m-d', $birthdate);
    $validDate = $date instanceof DateTime && $date->format('Y-m-d') === $birthdate && $birthdate <= date('Y-m-d');
    if ($error === '' && ($last === '' || strlen($last)>80 || $first === '' || strlen($first)>80 || strlen($middle)>80 || strlen($suffix)>20 ||
        !preg_match('/^\+639[0-9]{9}$/', $mobile) || $old['nickname']==='' || strlen($old['nickname'])>80 || !$validDate ||
        $birthplace==='' || strlen($birthplace)>180 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 ||
        strlen($credentials)<20 || strlen($credentials)>3000)) {
        $error = 'Please complete all required fields, enter a valid Philippine mobile number and email address, and provide at least 20 characters for contributor credentials/purpose.';
    }
    if ($error === '') {
        $fullName = implode(', ', array_values(array_filter([$last,$first,$middle,$suffix], static fn(string $v): bool => $v !== '')));
        $mail = $config['mail'] ?? [];
        $smtpReady = !empty($mail['host']) && !empty($mail['username']) && !empty($mail['password']) && !empty($mail['from']);
        $userId = 0;
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) throw new RuntimeException('That email address is already registered. Please sign in or use another email.');
            $stmt = $pdo->prepare('SELECT id FROM users WHERE mobile_no = ? LIMIT 1');
            $stmt->execute([$mobile]);
            if ($stmt->fetch()) throw new RuntimeException('That mobile number is already registered.');
            $pdo->beginTransaction();
            $temporaryHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,account_status,credential_notes,mobile_no,nickname,birthdate,birthplace,email_verified_at,lastname,firstname,middlename,suffix) VALUES(?,?,?,'creator','pending',?,?,?,?,?,NULL,?,?,?,?)");
            $stmt->execute([$fullName,$email,$temporaryHash,$credentials,$mobile,$old['nickname'],$birthdate,$birthplace,$last,$first,$middle,$suffix]);
            $userId = (int)$pdo->lastInsertId();
            $code = (string)random_int(100000,999999);
            $stmt = $pdo->prepare("INSERT INTO email_verification_codes(user_id,code_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
            $stmt->execute([$userId,password_hash($code,PASSWORD_DEFAULT)]);
            $pdo->commit();

            $subject = 'Your Z.C. commonplace verification code';
            $body = "Hello {$first},\n\nYour six-digit Z.C. commonplace email verification code is: {$code}\n\nThis code expires in 10 minutes. If you did not request this, you can ignore this email.";
            $sent = false;
            if ($smtpReady && is_file(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mailer->isSMTP();
                    $mailer->Host = (string)$mail['host'];
                    $mailer->SMTPAuth = true;
                    $mailer->Username = (string)$mail['username'];
                    $mailer->Password = (string)$mail['password'];
                    $mailer->Port = (int)($mail['port'] ?? 587);
                    $encryption = strtolower((string)($mail['encryption'] ?? 'tls'));
                    if ($encryption === 'ssl' || $encryption === 'smtps') $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    elseif ($encryption === 'tls' || $encryption === 'starttls') $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mailer->CharSet = 'UTF-8';
                    $mailer->setFrom((string)$mail['from'], (string)($mail['from_name'] ?? 'Z.C. commonplace'));
                    $mailer->addAddress($email, $fullName);
                    $mailer->Subject = $subject;
                    $mailer->Body = $body;
                    $sent = $mailer->send();
                }
            } elseif (!$smtpReady) {
                $from = (string)($mail['from'] ?? '');
                if ($from !== '') {
                    $headers = "From: " . $from . "\r\nContent-Type: text/plain; charset=UTF-8";
                    $sent = @mail($email, $subject, $body, $headers);
                }
            }
            if (!$sent) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                $error = 'We could not send the verification email. Configure SMTP in config/config.php (mail host, username, password, sender, and port), then try again.';
            } else {
                header('Location: verify-email.php?email=' . rawurlencode($email));
                exit;
            }
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($userId > 0) { try { $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]); } catch (Throwable $ignored) {} }
            error_log('Registration email verification error: ' . $e->getMessage());
            $error = 'Registration could not be completed. Check the email/SMTP configuration and database migration, then try again.';
        }
    }
}
if (!isset($_SESSION['register_captcha']) || !is_array($_SESSION['register_captcha'])) $_SESSION['register_captcha'] = ['a' => random_int(2, 9), 'b' => random_int(1, 9)];
$captchaQuestion = (int)$_SESSION['register_captcha']['a'] . ' + ' . (int)$_SESSION['register_captcha']['b'];
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create an account</title><link rel="stylesheet" href="assets/css/site.css">
<style>
html,body{width:100%;height:100%;margin:0;overflow:hidden}
body{background:#100b0e}
body>header{display:none!important}
main.wrap{box-sizing:border-box;width:100%;max-width:none;height:100%;overflow:hidden;padding:22px 26px!important}
main.wrap>.panel{box-sizing:border-box;height:100%;padding:20px 24px;overflow:hidden}
main.wrap h1{font-size:34px!important;margin:4px 0 8px!important}
main.wrap .muted{font-size:12px;line-height:1.45;margin:0 0 12px}
.form-grid{gap:10px 16px}
.form-grid label{display:block;font-size:12px;margin-bottom:4px}
.form-grid .field{box-sizing:border-box;min-height:36px;padding:8px 10px;font-size:13px}
.form-grid textarea.field{height:66px;min-height:66px;resize:none}
main.wrap form>p{margin:10px 0 0}
main.wrap form .btn{padding:10px 18px}
@media(max-width:560px){main.wrap{padding:12px!important}main.wrap>.panel{padding:14px 16px}main.wrap h1{font-size:27px!important}.form-grid{gap:7px 10px}.form-grid .field{min-height:32px;padding:6px 8px;font-size:12px}.form-grid label{font-size:11px}.form-grid textarea.field{height:56px;min-height:56px}}

.captcha-backdrop,.register-loading{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;background:rgba(5,3,5,.82);backdrop-filter:blur(5px)}
.captcha-backdrop.is-open,.register-loading.is-open{display:flex}
.captcha-card{width:min(390px,calc(100% - 36px));box-sizing:border-box;padding:28px;background:#1c1117;border:1px solid rgba(255,255,255,.16);border-radius:16px;text-align:center;box-shadow:0 22px 70px #000b;color:#fff}
.captcha-card h2{font:26px Georgia,serif;margin:0 0 10px}.captcha-card p{color:#cbbfc4;font-size:13px}
.captcha-equation{font-size:25px;font-weight:700;letter-spacing:2px;margin:18px 0;color:#f0c6d2}
.captcha-answer{width:120px;text-align:center;font-size:20px;padding:10px;border-radius:8px;border:1px solid #76515f;background:#100b0e;color:#fff}
.captcha-actions{display:flex;justify-content:center;gap:10px;margin-top:20px}.captcha-actions button{cursor:pointer;border:0;border-radius:7px;padding:10px 18px;font-weight:700}
.captcha-cancel{background:#3a2930;color:#fff}.captcha-continue{background:#9d263f;color:#fff}
.register-loading{z-index:10001;flex-direction:column;gap:17px}.loading-orbit{width:68px;height:68px;border:5px solid rgba(255,255,255,.18);border-top-color:#e74b69;border-right-color:#a52b48;border-radius:50%;animation:registerSpin .8s linear infinite;box-shadow:0 0 28px #d32e5555}
.loading-dots{display:flex;gap:10px}.loading-dots i{width:9px;height:9px;border-radius:50%;background:#f1b8c5;animation:registerFloat .65s ease-in-out infinite alternate}.loading-dots i:nth-child(2){animation-delay:.18s}.loading-dots i:nth-child(3){animation-delay:.36s}
.register-loading strong{color:#fff;font-size:14px;letter-spacing:.4px}@keyframes registerSpin{to{transform:rotate(360deg)}}@keyframes registerFloat{to{transform:translateY(-9px);opacity:.45}}
</style></head>
<body>
<main class="wrap"><div class="panel">
<div class="eyebrow">Join the community</div><h1 style="font:42px Georgia,serif;margin:8px 0">Create your account.</h1>
<p class="muted">Verify your email address with a six-digit code, then create your password to activate your account. No administrator approval is required. Do not include passwords or sensitive identity numbers in contributor credentials.</p>
<?php if ($error): ?><p role="alert" style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post" id="registerForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="captcha_answer" id="captchaAnswer">
<div class="form-grid">
<div><label for="lastname">Lastname</label><input class="field" id="lastname" name="lastname" required maxlength="80" autocomplete="family-name" value="<?=e($old['lastname'])?>"></div>
<div><label for="firstname">Firstname</label><input class="field" id="firstname" name="firstname" required maxlength="80" autocomplete="given-name" value="<?=e($old['firstname'])?>"></div>
<div><label for="middlename">Middlename</label><input class="field" id="middlename" name="middlename" maxlength="80" autocomplete="additional-name" value="<?=e($old['middlename'])?>" placeholder="Optional"></div>
<div><label for="suffix">Suffix</label><input class="field" id="suffix" name="suffix" maxlength="20" value="<?=e($old['suffix'])?>" placeholder="Jr., Sr., III (optional)"></div>
<div><label for="mobile_no">Contact/Mobile No. (+63)</label><input class="field" id="mobile_no" name="mobile_no" type="tel" required maxlength="20" autocomplete="tel" inputmode="tel" placeholder="+63 9XX XXXX XXX" value="<?=e($old['mobile_no'])?>"></div>
<div><label for="nickname">Nickname</label><input class="field" id="nickname" name="nickname" required maxlength="80" value="<?=e($old['nickname'])?>"></div>
<div><label for="birthdate">Birthdate</label><input class="field" id="birthdate" type="date" name="birthdate" required max="<?=e(date('Y-m-d'))?>" value="<?=e($old['birthdate'])?>"></div>
<div><label for="birthplace">Birthplace</label><input class="field" id="birthplace" name="birthplace" required maxlength="180" value="<?=e($old['birthplace'])?>"></div>
<div class="full"><label for="email">Email Address</label><input class="field" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($old['email'])?>"></div>
<div class="full"><label for="credential_notes">Contributor credentials / purpose</label><textarea class="field" id="credential_notes" name="credential_notes" rows="4" minlength="20" maxlength="3000" required placeholder="Describe what you plan to contribute, your interests, experience, portfolio or public creator links."><?=e($old['credential_notes'])?></textarea></div>
</div>
<p><button class="btn" id="sendVerification" type="button">Send Email Verification Code</button></p>
</form></div></main>
<div class="captcha-backdrop" id="captchaModal" role="dialog" aria-modal="true" aria-labelledby="captchaTitle"><div class="captcha-card"><h2 id="captchaTitle">Security check</h2><p>Please solve this quick captcha before we send your email verification code.</p><div class="captcha-equation"><?=e($captchaQuestion)?> = ?</div><input class="captcha-answer" id="captchaInput" type="text" inputmode="numeric" maxlength="2" autocomplete="off" aria-label="Captcha answer"><div class="captcha-actions"><button type="button" class="captcha-cancel" id="captchaCancel">Cancel</button><button type="button" class="captcha-continue" id="captchaContinue">Verify</button></div></div></div>
<div class="register-loading" id="registerLoading" aria-live="polite"><div class="loading-orbit"></div><div class="loading-dots"><i></i><i></i><i></i></div><strong>Sending verification email…</strong></div>
<script>
(() => {
 const form = document.getElementById('registerForm');
 const captchaModal = document.getElementById('captchaModal');
 const captchaInput = document.getElementById('captchaInput');
 const captchaAnswer = document.getElementById('captchaAnswer');
 const loading = document.getElementById('registerLoading');
 document.getElementById('sendVerification').addEventListener('click', () => { captchaInput.value=''; captchaModal.classList.add('is-open'); setTimeout(() => captchaInput.focus(), 50); });
 document.getElementById('captchaCancel').addEventListener('click', () => captchaModal.classList.remove('is-open'));
 document.getElementById('captchaContinue').addEventListener('click', () => {
   if (!/^\\d{1,2}$/.test(captchaInput.value.trim())) { captchaInput.focus(); return; }
   captchaAnswer.value = captchaInput.value.trim();
   captchaModal.classList.remove('is-open'); loading.classList.add('is-open');
   document.getElementById('sendVerification').disabled = true; form.submit();
 });
 captchaInput.addEventListener('keydown', event => { if(event.key==='Enter'){event.preventDefault();document.getElementById('captchaContinue').click();} });
 const input = document.getElementById('mobile_no');
 input.addEventListener('input', () => {
   let digits = input.value.replace(/\D/g,'');
   if (digits.startsWith('0063')) digits = digits.slice(4);
   else if (digits.startsWith('63')) digits = digits.slice(2);
   if (digits.startsWith('0')) digits = digits.slice(1);
   digits = digits.slice(0,10);
   const parts=[]; if(digits.length) parts.push(digits.slice(0,3)); if(digits.length>3) parts.push(digits.slice(3,7)); if(digits.length>7) parts.push(digits.slice(7,10));
   input.value = '+63' + (parts.length ? ' ' + parts.join(' ') : '');
 });
})();
</script>
</body></html>
