<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$old = ['lastname'=>'','firstname'=>'','middlename'=>'','suffix'=>'','mobile_no'=>'','nickname'=>'','birthdate'=>'','birthplace'=>'','email'=>'','credential_notes'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { verify_csrf(); } catch (Throwable $e) { $error = 'Your session expired. Refresh the page and try again.'; }
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
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create an account</title><link rel="stylesheet" href="assets/css/site.css"></head>
<body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><a href="../index.php">← Back to home</a></div></header>
<main class="wrap" style="max-width:760px;padding:48px 0"><div class="panel">
<div class="eyebrow">Join the community</div><h1 style="font:42px Georgia,serif;margin:8px 0">Create your account.</h1>
<p class="muted">Verify your email address with a six-digit code, then create your password to activate your account. No administrator approval is required. Do not include passwords or sensitive identity numbers in contributor credentials.</p>
<?php if ($error): ?><p role="alert" style="color:#a33"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
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
<p><button class="btn" type="submit">Send Email Verification Code</button></p>
</form></div></main>
<script>
(() => {
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
