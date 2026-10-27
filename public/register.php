<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $name=trim((string)($_POST['full_name']??'')); $email=strtolower(trim((string)($_POST['email']??'')));
    $password=(string)($_POST['password']??'');
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) {
        $error='Enter your name, a valid email, and a password of at least 12 characters.';
    } else {
        try {
            $stmt=$pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,account_status) VALUES(?,?,?,'creator','pending')");
            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $success='Registration received. A System Administrator must review and approve your account before you can access your personal workspace.';
        } catch (PDOException $ex) { $error='That email may already be registered. Please try another email.'; }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Join Commonplace</title><link rel="stylesheet" href="assets/css/site.css"></head><body><header><div class="wrap nav"><a class="brand" href="index.php">commonplace<span>.</span></a><a href="index.php">← Back to home</a></div></header><main class="wrap" style="max-width:720px;padding:48px 0"><div class="panel"><div class="eyebrow">Join the community</div><h1 style="font:42px Georgia,serif;margin:8px 0">Create your space.</h1><p class="muted">Creator accounts are reviewed by the System Administrator before access is granted.</p><?php if($error):?><p style="color:#a33"><?=e($error)?></p><?php endif;?><?php if($success):?><p><?=e($success)?></p><a class="btn" href="index.php">Return to homepage</a><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="form-grid"><div class="full"><label for="full_name">Full name</label><input class="field" id="full_name" name="full_name" required maxlength="160"></div><div class="full"><label for="email">Email address</label><input class="field" id="email" type="email" name="email" required maxlength="190"></div><div class="full"><label for="password">Password (minimum 12 characters)</label><input class="field" id="password" type="password" name="password" minlength="12" required autocomplete="new-password"></div></div><p><button class="btn" type="submit">Submit for approval</button></p></form><?php endif;?></div></main></body></html>
