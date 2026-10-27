<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $fullName=trim((string)($_POST['full_name']??'')); $mobile=trim((string)($_POST['mobile_no']??'')); $nickname=trim((string)($_POST['nickname']??''));
    $birthdate=trim((string)($_POST['birthdate']??'')); $birthplace=trim((string)($_POST['birthplace']??''));
    $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
    $credentials=trim((string)($_POST['credential_notes']??''));
    $validDate=$birthdate!=='' && DateTime::createFromFormat('Y-m-d',$birthdate) && DateTime::createFromFormat('Y-m-d',$birthdate)->format('Y-m-d')===$birthdate && $birthdate<=date('Y-m-d');
    if ($fullName==='' || strlen($fullName)>200 || $mobile==='' || !preg_match('/^[0-9+() .-]{7,25}$/',$mobile) || $nickname==='' || !$validDate || $birthplace==='' ||
        !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($credentials)<20) {
        $error='Please complete all fields. Use a valid birthdate, a password of at least 12 characters, and contributor credentials/purpose of at least 20 characters.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,account_status,credential_notes,mobile_no,nickname,birthdate,birthplace,email_verified_at) VALUES(?,?,?,'creator','pending',?,?,?,?,?,NULL)");
            $stmt->execute([$fullName,$email,password_hash($password,PASSWORD_DEFAULT),$credentials,$mobile,$nickname,$birthdate,$birthplace]);
            $userId=(int)$pdo->lastInsertId(); $code=(string)random_int(100000,999999);
            $pdo->prepare("INSERT INTO email_verification_codes(user_id,code_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))")->execute([$userId,password_hash($code,PASSWORD_DEFAULT)]);
            $pdo->commit();
            $subject='Your Z.C. commonplace verification code';
            $body="Your 6-digit email verification code is: {$code}\n\nThis code expires in 10 minutes. If you did not create this account, you can ignore this email.";
            $headers="From: ".($config['mail']['from']??'no-reply@localhost')."\r\nContent-Type: text/plain; charset=UTF-8";
            if (!mail($email,$subject,$body,$headers)) {
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
                $error='We could not send the verification email. Please try again later or contact the site administrator to configure outgoing email.';
            } else {
                header('Location: verify-email.php?email='.rawurlencode($email)); exit;
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error='That email may already be registered, or registration is temporarily unavailable.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create an account</title><link rel="stylesheet" href="assets/css/site.css"></head><body><header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><a href="../index.php">← Back to home</a></div></header><main class="wrap" style="max-width:760px;padding:48px 0"><div class="panel"><div class="eyebrow">Join the community</div><h1 style="font:42px Georgia,serif;margin:8px 0">Create your account.</h1><p class="muted">Verify your email address with a 6-digit code before signing in. Creator access remains subject to System Administrator review. Do not include passwords or sensitive identity numbers in contributor credentials.</p><?php if($error):?><p style="color:#a33"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="form-grid">
<div class="full"><label for="full_name">Full Name (Lastname, Firstname, Middle Name)</label><input class="field" id="full_name" name="full_name" required maxlength="200" autocomplete="name" placeholder="Dela Cruz, Juan, Santos"></div>
<div><label for="mobile_no">Contact/Mobile No.</label><input class="field" id="mobile_no" name="mobile_no" type="tel" required maxlength="25" autocomplete="tel" placeholder="+63 9XX XXX XXXX"></div>
<div><label for="nickname">Nickname</label><input class="field" id="nickname" name="nickname" required maxlength="80"></div>
<div><label for="birthdate">Birthdate</label><input class="field" id="birthdate" type="date" name="birthdate" required max="<?=e(date('Y-m-d'))?>"></div>
<div><label for="birthplace">Birthplace</label><input class="field" id="birthplace" name="birthplace" required maxlength="180"></div>
<div class="full"><label for="email">Email Address</label><input class="field" id="email" type="email" name="email" required maxlength="190" autocomplete="email"></div>
<div class="full"><label for="credential_notes">Contributor credentials / purpose</label><textarea class="field" id="credential_notes" name="credential_notes" rows="4" minlength="20" maxlength="3000" required placeholder="Describe what you plan to contribute, your interests, experience, portfolio or public creator links."></textarea></div>
<div class="full"><label for="password">Password (minimum 12 characters)</label><input class="field" id="password" type="password" name="password" minlength="12" required autocomplete="new-password"></div>
</div><p><button class="btn" type="submit">Create Account</button></p></form></div></main></body></html>
