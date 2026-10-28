<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$error = '';
$firebase = $config['firebase'] ?? [];
$firebaseReady = !empty($firebase['api_key']) && !empty($firebase['auth_domain']) && !empty($firebase['project_id']) && !empty($firebase['app_id']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create an account</title><link rel="stylesheet" href="assets/css/site.css">
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-auth-compat.js"></script>
</head>
<body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><a href="../index.php">← Back to home</a></div></header>
<main class="wrap" style="max-width:760px;padding:48px 0"><div class="panel">
<div class="eyebrow">Join the community</div><h1 style="font:42px Georgia,serif;margin:8px 0">Create your account.</h1>
<p class="muted">Verify your Philippine mobile number with a six-digit SMS code. After phone verification, you will create your password. Creator access remains subject to System Administrator review. Do not include passwords or sensitive identity numbers in contributor credentials.</p>
<?php if (!$firebaseReady): ?><p style="color:#a33">Phone verification is not configured yet. Add your Firebase Web app settings to config/config.php using config/config.example.php as a guide.</p><?php endif; ?>
<p id="message" role="status" aria-live="polite"></p>
<form id="registration-form">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<div class="form-grid">
<div class="full"><label for="full_name">Full Name (Lastname, Firstname, Middle Name)</label><input class="field" id="full_name" name="full_name" required maxlength="200" autocomplete="name" placeholder="Dela Cruz, Juan, Santos"></div>
<div><label for="mobile_no">Contact/Mobile No. (+63)</label><input class="field" id="mobile_no" name="mobile_no" type="tel" required maxlength="16" autocomplete="tel" inputmode="tel" placeholder="+63 9XX XXXX XXX"></div>
<div><label for="nickname">Nickname</label><input class="field" id="nickname" name="nickname" required maxlength="80"></div>
<div><label for="birthdate">Birthdate</label><input class="field" id="birthdate" type="date" name="birthdate" required max="<?=e(date('Y-m-d'))?>"></div>
<div><label for="birthplace">Birthplace</label><input class="field" id="birthplace" name="birthplace" required maxlength="180"></div>
<div class="full"><label for="credential_notes">Contributor credentials / purpose</label><textarea class="field" id="credential_notes" name="credential_notes" rows="4" minlength="20" maxlength="3000" required placeholder="Describe what you plan to contribute, your interests, experience, portfolio or public creator links."></textarea></div>
</div>
<div id="recaptcha-container" style="margin:16px 0"></div>
<p><button class="btn" id="send-code" type="button" <?=!$firebaseReady?'disabled':''?>>Send SMS Verification Code</button></p>
<div id="code-section" hidden>
<p><label for="code">Six-digit SMS verification code</label><input class="field" id="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"></p>
<button class="btn" id="verify-code" type="button">Verify Phone and Continue</button>
</div>
</form>
</div></main>
<script>
(() => {
  const ready = <?=json_encode($firebaseReady)?>;
  if (!ready) return;
  const firebaseConfig = <?=json_encode([
      'apiKey' => (string)($firebase['api_key'] ?? ''),
      'authDomain' => (string)($firebase['auth_domain'] ?? ''),
      'projectId' => (string)($firebase['project_id'] ?? ''),
      'appId' => (string)($firebase['app_id'] ?? ''),
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
  firebase.initializeApp(firebaseConfig);
  const auth = firebase.auth();
  auth.useDeviceLanguage();
  const form = document.getElementById('registration-form');
  const phoneInput = form.elements.mobile_no;
  const formatPhone = (value) => {
    let digits = value.replace(/\D/g, '');
    if (digits.startsWith('0063')) digits = digits.slice(4);
    else if (digits.startsWith('63')) digits = digits.slice(2);
    if (digits.startsWith('0')) digits = digits.slice(1);
    digits = digits.slice(0, 10);
    const parts = [];
    if (digits.length) parts.push(digits.slice(0, 3));
    if (digits.length > 3) parts.push(digits.slice(3, 7));
    if (digits.length > 7) parts.push(digits.slice(7, 10));
    return '+63' + (parts.length ? ' ' + parts.join(' ') : '');
  };
  phoneInput.addEventListener('input', () => {
    phoneInput.value = formatPhone(phoneInput.value);
    try { phoneInput.setSelectionRange(phoneInput.value.length, phoneInput.value.length); } catch (_) {}
  });
  phoneInput.addEventListener('focus', () => {
    if (!phoneInput.value.trim()) phoneInput.value = '+63 ';
  });
  const message = document.getElementById('message');
  const sendButton = document.getElementById('send-code');
  const verifyButton = document.getElementById('verify-code');
  let confirmation = null;
  let verifier = null;
  const say = (text, bad = false) => { message.textContent = text; message.style.color = bad ? '#a33' : 'inherit'; };
  const normalizePhone = (value) => {
    let v = value.trim().replace(/[\s().-]/g, '');
    if (/^09\d{9}$/.test(v)) v = '+63' + v.slice(1);
    else if (/^9\d{9}$/.test(v)) v = '+63' + v;
    return /^\+639\d{9}$/.test(v) ? v : '';
  };
  sendButton.addEventListener('click', async () => {
    // Validate only the phone number at this stage. Other required registration fields
    // are validated when the user submits the completed registration after SMS verification.
    const phone = normalizePhone(form.elements.mobile_no.value);
    if (!phone) { say('Enter a valid Philippine mobile number, such as +639171234567.', true); return; }
    form.elements.mobile_no.value = phone;
    sendButton.disabled = true;
    try {
      if (!verifier) verifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', {size: 'normal'});
      await verifier.render();
      confirmation = await auth.signInWithPhoneNumber(phone, verifier);
      document.getElementById('code-section').hidden = false;
      document.getElementById('code').focus();
      say('SMS sent to ' + phone + '. Enter the six-digit code. The SMS may incur carrier charges.');
    } catch (err) {
      say(err.message || 'Could not send the SMS. Check Firebase phone authentication settings and try again.', true);
      if (verifier) { try { verifier.clear(); } catch (_) {} verifier = null; }
    } finally { sendButton.disabled = false; }
  });
  verifyButton.addEventListener('click', async () => {
    const code = document.getElementById('code').value.trim();
    if (!confirmation) { say('Send the SMS code first.', true); return; }
    if (!/^\d{6}$/.test(code)) { say('Enter the six-digit SMS code.', true); return; }
    verifyButton.disabled = true;
    try {
      const result = await confirmation.confirm(code);
      const token = await result.user.getIdToken(true);
      const data = new FormData(form);
      data.set('firebase_id_token', token);
      data.set('mobile_no', result.user.phoneNumber || form.elements.mobile_no.value);
      const response = await fetch('verify-phone.php', {method:'POST', body:data, credentials:'same-origin', headers:{'Accept':'application/json'}});
      const body = await response.json();
      if (!response.ok || !body.ok) throw new Error(body.error || 'Could not complete registration.');
      window.location.href = body.redirect;
    } catch (err) {
      say(err.message || 'The code is invalid or expired. Please try again.', true);
      verifyButton.disabled = false;
    }
  });
})();
</script>
</body></html>
