<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$loginError = '';
$showLoginProgress = !empty($_SESSION['login_progress']);
$loginRedirect = (string)($_SESSION['login_redirect'] ?? 'public/workspace.php');
unset($_SESSION['login_progress'], $_SESSION['login_redirect']);
$loginMethod = (string)($_POST['login_method'] ?? 'email');
$loginValue = trim((string)($_POST['login'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form_action'] ?? '') === 'sign_in') {
    verify_signin_csrf();
    $password = (string)($_POST['password'] ?? '');
    if ($loginMethod === 'mobile') {
        $digits = preg_replace('/\\D/', '', $loginValue) ?? '';
        if (str_starts_with($digits, '0063')) $digits = substr($digits, 4);
        elseif (str_starts_with($digits, '63')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
        $stmtLogin = $pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE mobile_no=? LIMIT 1");
        $stmtLogin->execute(['+63' . $digits]);
    } else {
        $stmtLogin = $pdo->prepare("SELECT id,full_name,email,password_hash,role,account_status FROM users WHERE email=? LIMIT 1");
        $stmtLogin->execute([strtolower($loginValue)]);
    }
    $loginUser = $stmtLogin->fetch();
    if (!$loginUser || !password_verify($password, $loginUser['password_hash'])) $loginError = 'Invalid email/mobile number or password.';
    elseif ($loginUser['account_status'] !== 'approved') $loginError = 'Your account is awaiting administrator approval or is not currently active.';
    else {
        $targetContext = ($loginUser['role'] === 'system_admin') ? 'admin' : 'creator';
        switch_app_session_context($targetContext);
        unset($loginUser['password_hash']);
        $_SESSION['user'] = $loginUser;
        $_SESSION['login_progress'] = true;
        $_SESSION['login_redirect'] = ($targetContext === 'admin' ? 'public/admin.php' : 'public/workspace.php');
        header('Location: index.php?session_context=' . $targetContext);
        exit;
    }
}
$search = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$sql = "SELECT c.*, cat.name AS category_name, cat.slug AS category_slug, u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id JOIN users u ON u.id=c.owner_id WHERE c.status='published' AND cat.is_active=1";
$params = [];
if ($search !== '') { $sql .= " AND (c.title LIKE ? OR c.excerpt LIKE ? OR c.body LIKE ?)"; $like = "%$search%"; array_push($params,$like,$like,$like); }
if ($category !== '') { $sql .= " AND cat.slug=?"; $params[] = $category; }
$sql .= " ORDER BY COALESCE(c.published_at,c.created_at) DESC LIMIT 18";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $items=$stmt->fetchAll();
$categories=$pdo->query("SELECT name,slug,description FROM categories WHERE is_active=1 ORDER BY id")->fetchAll();
// Ensure Page Management tables exist even before an administrator first opens the admin panel.
$pdo->exec("CREATE TABLE IF NOT EXISTS homepage_sections (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 page_name VARCHAR(140) NOT NULL,
 slug VARCHAR(160) NOT NULL UNIQUE,
 description TEXT NULL,
 position ENUM('left','right','top','bottom') NOT NULL DEFAULT 'top',
 width_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
 sort_order INT NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS homepage_section_items (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 section_id INT UNSIGNED NOT NULL,
 item_type VARCHAR(32) NOT NULL DEFAULT 'story',
 title VARCHAR(180) NOT NULL,
 body MEDIUMTEXT NULL,
 media_url VARCHAR(1000) NULL,
 link_label VARCHAR(100) NULL,
 link_page_name VARCHAR(140) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(section_id),
 CONSTRAINT fk_homepage_item_section FOREIGN KEY(section_id) REFERENCES homepage_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$publicHomepageSections = $pdo->query("SELECT * FROM homepage_sections WHERE is_active=1 ORDER BY sort_order,id")->fetchAll();
$publicHomepageItems = [];
if ($publicHomepageSections) {
    $publicItemsStmt=$pdo->query("SELECT i.*, s.slug AS destination_slug FROM homepage_section_items i LEFT JOIN homepage_sections s ON s.page_name=i.link_page_name AND s.is_active=1 ORDER BY i.sort_order,i.id");
    foreach ($publicItemsStmt->fetchAll() as $publicItem) $publicHomepageItems[(int)$publicItem['section_id']][]=$publicItem;
}
$modules = [
 ['name'=>'Creative Arts and Photography','slug'=>'creative-arts-photography','symbol'=>'✳','description'=>'Photography, visual art, design and local creative voices.'],
 ['name'=>'News Connect','slug'=>'news-connect','symbol'=>'◎','description'=>'Community updates, civic stories and current affairs.'],
 ['name'=>'Marketplace','slug'=>'marketplace','symbol'=>'◇','description'=>'Discover products, local makers, services and listings.'],
 ['name'=>'Entertainment & Politics','slug'=>'entertainment-politics','symbol'=>'♫','description'=>'Entertainment, public figures, political discussion and analysis.'],
 ['name'=>'History & Culture','slug'=>'history-culture','symbol'=>'⌂','description'=>'Zamboanga heritage, Chavacano identity and living traditions.'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Explore Zamboanga City's history and culture, photography, community news, marketplace, entertainment and public affairs.">
<title><?=e($config['app']['name'])?> — Zamboanga stories, culture and community</title>
<link rel="stylesheet" href="public/assets/css/site.css">
<style>.home-art-photo{background:#16070b!important;padding:0!important}.home-art-photo:before{display:none!important}.home-art-photo:after{display:none!important}.home-art-photo .hero-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;border-radius:inherit}.home-art-photo:before{content:""}.hero-photo-caption{position:absolute;left:28px;bottom:25px;color:#f8e9eb;font-size:10px;font-weight:800;letter-spacing:2.5px;text-shadow:0 2px 8px #000;z-index:1}.home-art-photo .art-stamp{z-index:2;background:rgba(10,8,10,.48)}@media(max-width:560px){.hero-photo-caption{left:18px;bottom:17px;font-size:8px;letter-spacing:1.5px}}
.register-modal{position:fixed;inset:0;z-index:1001;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(5,3,5,.82);backdrop-filter:blur(7px)}
.register-modal.is-open{display:flex}.register-dialog{position:relative;width:min(100%,960px);height:min(94vh,900px);overflow:hidden;background:#160d11;border:1px solid rgba(190,75,98,.4);border-radius:18px;box-shadow:0 24px 90px rgba(0,0,0,.6)}.register-dialog iframe{display:block;width:100%;height:100%;border:0;background:#100b0e;overflow:hidden}.register-close{position:absolute;right:12px;top:10px;z-index:2;width:38px;height:38px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1;text-align:center;border:1px solid rgba(255,255,255,.18);border-radius:50%;background:#241219;color:#fff;font-size:23px;font-family:Arial,sans-serif;cursor:pointer;box-shadow:0 2px 12px #0008;box-sizing:border-box}
.signin-modal{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(5,3,5,.78);backdrop-filter:blur(7px)}
.signin-modal.is-open{display:flex}
.signin-dialog{position:relative;width:min(100%,480px);max-height:calc(100vh - 44px);overflow:auto;background:#160d11;color:#f7edf0;border:1px solid rgba(190,75,98,.35);border-radius:18px;padding:34px;box-shadow:0 24px 90px rgba(0,0,0,.55)}
.signin-dialog h2{font:38px Georgia,serif;margin:7px 0 20px}
.signin-dialog label{display:block;margin:0 0 7px;font-size:13px}
.signin-dialog .field{width:100%;box-sizing:border-box}
.signin-dialog .signin-close{position:absolute;right:17px;top:13px;border:0;background:transparent;color:#f7edf0;font-size:28px;cursor:pointer}
.signin-dialog .signin-methods{display:flex;gap:22px;margin:8px 0 18px}
.signin-dialog .signin-methods label{display:flex;align-items:center;gap:8px;cursor:pointer}
.signin-dialog .signin-methods input{accent-color:#a52b45}
.signin-dialog .signin-error{color:#ff9eae;background:rgba(160,35,60,.14);border:1px solid rgba(220,80,105,.3);padding:10px 12px;border-radius:8px;font-size:13px}
.signin-dialog .signin-success{color:#b9e5c0;background:rgba(65,145,86,.13);border:1px solid rgba(119,190,135,.3);padding:10px 12px;border-radius:8px;font-size:13px}
.signin-dialog .btn{margin-top:8px}
@media(max-width:560px){.signin-dialog{padding:27px 22px}.signin-dialog h2{font-size:32px}}
</style>
</head>
<body>
<main>

<?php if ($publicHomepageSections): ?>
<section class="homepage-managed-sections"><div class="wrap homepage-managed-grid">
<?php foreach($publicHomepageSections as $managedSection): $managedPosition=$managedSection['position']; ?>
<section class="homepage-managed-section position-<?=e($managedPosition)?>" id="<?=e($managedSection['slug'])?>" style="--section-width:<?=max(10,min(100,(int)$managedSection['width_percent']))?>%">
  <div class="section-head"><div><div class="eyebrow"><?=e(strtoupper($managedPosition))?></div><h2><?=e($managedSection['page_name'])?></h2><?php if(!empty($managedSection['description'])): ?><p class="muted"><?=e($managedSection['description'])?></p><?php endif; ?></div></div>
  <div class="homepage-managed-items"><?php foreach(($publicHomepageItems[(int)$managedSection['id']] ?? []) as $managedItem): ?>
    <article class="homepage-managed-item">
      <?php if(!empty($managedItem['media_url'])): ?><img src="<?=e($managedItem['media_url'])?>" alt="<?=e($managedItem['title'])?>" loading="lazy"><?php endif; ?>
      <div><span class="tag"><?=e(str_replace('-',' ',strtoupper($managedItem['item_type'])))?></span><h3><?=e($managedItem['title'])?></h3><?php if(!empty($managedItem['body'])): ?><p><?=nl2br(e($managedItem['body']))?></p><?php endif; ?>
      <?php if(!empty($managedItem['link_label']) && !empty($managedItem['destination_slug'])): ?><a class="btn light" href="#<?=e($managedItem['destination_slug'])?>"><?=e($managedItem['link_label'])?> ↗</a><?php endif; ?></div>
    </article>
  <?php endforeach; ?></div>
</section>
<?php endforeach; ?>
</div></section>
<style>.homepage-managed-grid{display:flex;flex-wrap:wrap;gap:22px;align-items:stretch}.homepage-managed-section{box-sizing:border-box;flex:0 1 var(--section-width);min-width:min(100%,280px);padding:26px;border:1px solid rgba(190,75,98,.25);border-radius:18px;background:linear-gradient(145deg,rgba(35,16,23,.82),rgba(16,12,15,.92))}.homepage-managed-section.position-left{margin-right:auto}.homepage-managed-section.position-right{margin-left:auto}.homepage-managed-section.position-top,.homepage-managed-section.position-bottom{margin-inline:auto}.homepage-managed-items{display:grid;gap:14px}.homepage-managed-item{display:grid;grid-template-columns:minmax(100px,28%) 1fr;gap:17px;align-items:start;padding:13px;border:1px solid rgba(255,255,255,.08);border-radius:12px}.homepage-managed-item img{width:100%;max-height:190px;object-fit:cover;border-radius:9px}.homepage-managed-item h3{margin:6px 0}.homepage-managed-item p{color:var(--muted);font-size:13px;line-height:1.65;white-space:normal}@media(max-width:620px){.homepage-managed-section{flex-basis:100%!important}.homepage-managed-item{grid-template-columns:1fr}}</style>
<?php endif; ?>
</main>

<div class="register-modal" id="create-account-modal" aria-hidden="true"><div class="register-dialog" role="dialog" aria-modal="true" aria-label="Create an Account"><button type="button" class="register-close" id="close-create-account" aria-label="Close create account">×</button><iframe src="public/register.php" title="Create an Account" loading="lazy"></iframe></div></div>

<div class="signin-modal<?= ($loginError !== '' || (string)($_GET['profile_password_updated'] ?? '') === '1' || (string)($_GET['account_removed'] ?? '') === '1') ? ' is-open' : '' ?>" id="sign-in-modal" aria-hidden="<?= ($loginError !== '' || (string)($_GET['profile_password_updated'] ?? '') === '1' || (string)($_GET['account_removed'] ?? '') === '1') ? 'false' : 'true' ?>">
<div class="signin-dialog" role="dialog" aria-modal="true" aria-labelledby="signin-title">
<button type="button" class="signin-close" id="close-sign-in" aria-label="Close sign in">×</button>
<div class="eyebrow">Welcome back</div><h2 id="signin-title">Sign in</h2>
<?php if ((string)($_GET['registered'] ?? '') === '1'): ?><p class="signin-success" role="status">Your email is verified and your password is set. You can now sign in.</p><?php endif; ?>
<?php if ((string)($_GET['profile_password_updated'] ?? '') === '1'): ?><p class="signin-success" role="status">Account successfully updated. Your password has changed. Please sign in again using your new password.</p><?php endif; ?>
<?php if ((string)($_GET['account_removed'] ?? '') === '1'): ?><p class="signin-error" role="alert">Your account is no longer available or active. Please sign in with an active account.</p><?php endif; ?>
<?php if ($loginError !== ''): ?><p class="signin-error" role="alert"><?=e($loginError)?></p><?php endif; ?>
<form method="post" action="index.php"><input type="hidden" name="csrf" value="<?=e(signin_csrf_token())?>"><input type="hidden" name="form_action" value="sign_in">
<fieldset style="border:0;padding:0;margin:0"><legend style="font-size:13px;font-weight:600">Sign in using</legend><div class="signin-methods">
<label><input type="radio" name="login_method" value="email" <?= $loginMethod !== 'mobile' ? 'checked' : '' ?>> Email</label>
<label><input type="radio" name="login_method" value="mobile" <?= $loginMethod === 'mobile' ? 'checked' : '' ?>> Contact/Mobile No.</label>
</div></fieldset>
<p><label id="signin-login-label" for="signin-login"><?= $loginMethod === 'mobile' ? 'Contact/Mobile No. (+63)' : 'Email Address' ?></label>
<input class="field" id="signin-login" type="<?= $loginMethod === 'mobile' ? 'tel' : 'email' ?>" name="login" required maxlength="190" autocomplete="username" inputmode="<?= $loginMethod === 'mobile' ? 'tel' : 'email' ?>" placeholder="<?= $loginMethod === 'mobile' ? '+63 9XX XXXX XXX' : 'Enter your email address' ?>" value="<?=e($loginValue)?>"></p>
<p><label for="signin-password">Password</label><input class="field" id="signin-password" type="password" name="password" required autocomplete="current-password"></p>
<button class="btn" type="submit">Sign in</button></form></div></div>
<?php if ($showLoginProgress): ?>
<div class="login-progress-overlay" role="status" aria-live="polite" aria-label="Sign in successful. Redirecting to your page.">
  <div class="login-progress-card">
    <div class="login-progress-check" aria-hidden="true">✓</div>
    <div class="eyebrow">Authentication complete</div>
    <h2>Sign in successful</h2>
    <p>Taking you to your designated page…</p>
    <div class="login-progress-voyage" aria-label="Sign-in progress">
      <div class="login-progress-track"><span id="login-progress-bar"></span></div>
      <svg class="login-vinta" id="login-vinta" viewBox="0 0 64 48" role="img" aria-label="Colorful vinta sailing across the progress bar">
        <path d="M31 4 L31 33" stroke="#f4e9df" stroke-width="2.4" stroke-linecap="round"/>
        <path d="M29 7 L10 26 L29 26 Z" fill="#d84b62" stroke="#f7d9df" stroke-width="1"/>
        <path d="M33 7 L53 26 L33 26 Z" fill="#e6b94f" stroke="#f7e8bf" stroke-width="1"/>
        <path d="M30 11 L30 26 L17 26 Z" fill="#287c83" opacity=".95"/>
        <path d="M6 34 Q31 39 58 34 L51 43 Q31 47 13 42 Z" fill="#f3e6d7" stroke="#8d3045" stroke-width="1.5"/>
        <path d="M4 45 Q18 42 31 45 T60 45" fill="none" stroke="#6db4bd" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="login-progress-caption"><span>Preparing your workspace</span><span id="login-progress-percent">0%</span></div>
  </div>
</div>
<style>
.login-progress-overlay{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:22px;background:rgba(5,3,5,.94);backdrop-filter:blur(9px)}
.login-progress-card{width:min(100%,430px);padding:36px 32px;text-align:center;color:#f7edf0;background:#160d11;border:1px solid rgba(190,75,98,.4);border-radius:18px;box-shadow:0 24px 90px rgba(0,0,0,.55)}
.login-progress-check{width:54px;height:54px;margin:0 auto 18px;display:grid;place-items:center;border-radius:50%;background:rgba(119,171,126,.16);border:1px solid rgba(119,171,126,.55);color:#a9d8ae;font-size:28px}
.login-progress-card h2{font:34px Georgia,serif;margin:8px 0 10px}.login-progress-card p{color:#c9b9bf;margin:0 0 24px}
.login-progress-voyage{position:relative;height:52px;margin-top:24px;padding:0;overflow:visible}
.login-progress-track{position:absolute;left:0;right:0;bottom:5px;height:9px;overflow:hidden;border-radius:99px;background:#39232b;border:1px solid rgba(255,255,255,.08);z-index:1}
.login-progress-track span{display:block;width:0;height:100%;border-radius:99px;background:linear-gradient(90deg,#7f1830,#d44c68)}
.login-vinta{position:absolute;display:block;left:0;top:0;width:58px;height:44px;max-width:none;overflow:visible;transform:none;z-index:3;visibility:visible;opacity:1;filter:drop-shadow(0 3px 3px rgba(0,0,0,.55));will-change:left;pointer-events:none}
.login-progress-caption{display:flex;justify-content:space-between;gap:12px;margin-top:10px;color:#bdaab1;font-size:11px;letter-spacing:.4px}
@media(prefers-reduced-motion:reduce){.login-vinta{transition:none}}
</style>
<script>
(() => {
  const bar = document.getElementById('login-progress-bar');
  const boat = document.getElementById('login-vinta');
  const percent = document.getElementById('login-progress-percent');
  const track = document.querySelector('.login-progress-track');
  if (!bar || !boat || !percent || !track) return;
  const started = performance.now();
  const duration = 6500;
  function animate(now) {
    const value = Math.min(100, Math.round(((now - started) / duration) * 100));
    const travelDistance = Math.max(0, track.parentElement.clientWidth - boat.getBoundingClientRect().width);
    bar.style.width = value + '%';
    boat.style.left = (travelDistance * value / 100) + 'px';
    percent.textContent = value + '%';
    if (value < 100) requestAnimationFrame(animate);
  }
  requestAnimationFrame(animate);
  window.addEventListener('resize', () => {
    const value = Math.min(100, Math.round(((performance.now() - started) / duration) * 100));
    const travelDistance = Math.max(0, track.parentElement.clientWidth - boat.getBoundingClientRect().width);
    boat.style.left = (travelDistance * value / 100) + 'px';
  });
  window.setTimeout(() => { window.location.replace('<?=e($loginRedirect)?>'); }, 6650);
})();
</script>
<?php endif; ?>

<script>
(() => {
  const modal = document.getElementById('sign-in-modal');
  const openButton = document.getElementById('open-sign-in');
  const closeButton = document.getElementById('close-sign-in');
  const loginField = document.getElementById('signin-login');
  const loginLabel = document.getElementById('signin-login-label');
  const methodRadios = document.querySelectorAll('input[name="login_method"]');
  const registerModal = document.getElementById('create-account-modal');
  const openRegisterButtons = document.querySelectorAll('[data-open-create-account], #open-create-account');
  const closeRegister = document.getElementById('close-create-account');
  function showRegisterModal(){if(!registerModal)return;registerModal.classList.add('is-open');registerModal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';if(closeRegister)closeRegister.focus();}
  function clearRegisterForm(){
    const frame = registerModal ? registerModal.querySelector('iframe') : null;
    try {
      const doc = frame && frame.contentDocument;
      if (!doc) return;
      const form = doc.getElementById('registerForm');
      if (form) form.reset();
      const captchaInput = doc.getElementById('captchaInput');
      const captchaAnswer = doc.getElementById('captchaAnswer');
      const captchaModal = doc.getElementById('captchaModal');
      const loading = doc.getElementById('registerLoading');
      const sendButton = doc.getElementById('sendVerification');
      if (captchaInput) captchaInput.value = '';
      if (captchaAnswer) captchaAnswer.value = '';
      if (captchaModal) captchaModal.classList.remove('is-open');
      if (loading) loading.classList.remove('is-open');
      if (sendButton) sendButton.disabled = true;
      const message = doc.querySelector('main [role="alert"]');
      if (message) message.remove();
    } catch (error) { /* Same-origin frame is expected; ignore if it is unavailable. */ }
  }
  function hideRegisterModal(){if(!registerModal)return;clearRegisterForm();registerModal.classList.remove('is-open');registerModal.setAttribute('aria-hidden','true');document.body.style.overflow='';const trigger=document.querySelector('[data-open-create-account], #open-create-account');if(trigger)trigger.focus();}
  openRegisterButtons.forEach(button=>button.addEventListener('click',event=>{event.preventDefault();hideSignInModal();showRegisterModal();}));
  if(closeRegister)closeRegister.addEventListener('click',hideRegisterModal);
  if(registerModal) {
    const registerFrame = registerModal.querySelector('iframe');
    const bindRegisterEscape = () => {
      try {
        const frameDocument = registerFrame && registerFrame.contentDocument;
        if (!frameDocument || frameDocument.documentElement.dataset.escapeBound === '1') return;
        frameDocument.documentElement.dataset.escapeBound = '1';
        frameDocument.addEventListener('keydown', event => {
          if (event.key === 'Escape' && registerModal.classList.contains('is-open')) {
            event.preventDefault();
            event.stopPropagation();
            hideRegisterModal();
          }
        }, true);
      } catch (error) { /* Ignore an unavailable iframe document. */ }
    };
    if (registerFrame) {
      registerFrame.addEventListener('load', bindRegisterEscape);
      bindRegisterEscape();
    }
  }

  if (!modal || !openButton || !closeButton || !loginField || !loginLabel) return;

  function showSignInModal() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => loginField.focus(), 40);
  }

  function hideSignInModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    openButton.focus();
  }

  openButton.addEventListener('click', event => {
    event.preventDefault();
    hideRegisterModal();
    showSignInModal();
  });
  closeButton.addEventListener('click', hideSignInModal);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) hideSignInModal();
    if (event.key === 'Escape' && registerModal && registerModal.classList.contains('is-open')) hideRegisterModal();
  });

  function updateLoginMethod() {
    const isMobile = document.querySelector('input[name="login_method"]:checked').value === 'mobile';
    loginLabel.textContent = isMobile ? 'Contact/Mobile No. (+63)' : 'Email Address';
    loginField.type = isMobile ? 'tel' : 'email';
    loginField.inputMode = isMobile ? 'tel' : 'email';
    loginField.placeholder = isMobile ? '+63 9XX XXXX XXX' : 'Enter your email address';
    loginField.maxLength = isMobile ? 20 : 190;
    if (!isMobile && loginField.value.trim() === '+63') loginField.value = '';
  }
  methodRadios.forEach(radio => radio.addEventListener('change', updateLoginMethod));

  loginField.addEventListener('input', () => {
    if (document.querySelector('input[name="login_method"]:checked').value !== 'mobile') return;
    let digits = loginField.value.replace(/\D/g, '');
    if (digits.startsWith('0063')) digits = digits.slice(4);
    else if (digits.startsWith('63')) digits = digits.slice(2);
    if (digits.startsWith('0')) digits = digits.slice(1);
    digits = digits.slice(0, 10);
    const groups = [];
    if (digits.length) groups.push(digits.slice(0, 3));
    if (digits.length > 3) groups.push(digits.slice(3, 7));
    if (digits.length > 7) groups.push(digits.slice(7, 10));
    loginField.value = '+63' + (groups.length ? ' ' + groups.join(' ') : '');
  });

  const params = new URLSearchParams(window.location.search);
  if (modal.classList.contains('is-open') || params.get('signin') === '1') showSignInModal();
})();
</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function(){
 const $slides=$('.culture-slide'),$dots=$('.slide-dot'); let active=0,timer=null;
 function showSlide(next){active=(next+$slides.length)%$slides.length;$slides.removeClass('is-active').attr('aria-hidden','true').eq(active).addClass('is-active').attr('aria-hidden','false');$dots.removeClass('is-active').attr('aria-pressed','false').eq(active).addClass('is-active').attr('aria-pressed','true');$('#slide-current').text(String(active+1).padStart(2,'0'));}
 function restart(){if(timer)clearInterval(timer);if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches)timer=setInterval(()=>showSlide(active+1),6500);}
 $('.slide-next').on('click',()=>{showSlide(active+1);restart()});$('.slide-prev').on('click',()=>{showSlide(active-1);restart()});$dots.each(function(i){$(this).on('click',()=>{showSlide(i);restart()})});
 $('#culture-slideshow').on('mouseenter focusin',()=>{if(timer)clearInterval(timer)}).on('mouseleave focusout',restart);
 showSlide(0);restart();
});
</script>
</body></html>
