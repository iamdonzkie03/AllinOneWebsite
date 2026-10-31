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
.signin-dialog .btn{margin-top:8px}
@media(max-width:560px){.signin-dialog{padding:27px 22px}.signin-dialog h2{font-size:32px}}
</style>
</head>
<body>
<div class="topbar"><div class="wrap"><span>ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY</span><span>Local perspectives. Shared with care.</span></div></div>
<header><div class="wrap nav">
<a class="brand" href="index.php">Z.C. commonplace<span>.</span></a>
<nav class="navlinks">
<a href="#modules">Explore</a><a href="#zamboanga">History & Culture</a><a href="#latest">Latest stories</a>
<a href="#sign-in-modal" id="open-sign-in">Sign in</a><a class="btn" href="#create-account-modal" id="open-create-account">Join the community</a>
</nav></div></header>
<main>
<section class="hero home-hero"><div class="wrap hero-grid"><div>
<div class="eyebrow">One city. Many stories.</div>
<h1>Zamboanga,<br><em>through our eyes.</em></h1>
<p>A digital home for the city's history, culture, creative talent, community news, marketplace, entertainment and public conversation.</p>
<div class="hero-actions"><a class="btn" href="#zamboanga">Explore our heritage</a><a class="btn light" href="#create-account-modal" data-open-create-account>Become a contributor ↗</a></div>
<div class="hero-note"><span class="hero-note-dot"></span>Independent community contributions · Administrator-reviewed publishing</div>
</div>
<div class="hero-art home-art home-art-photo" role="img" aria-label="Zamboanga heritage collage featuring Fort Pilar, the Philippine flag, a traditional vinta, and Yakan-inspired textile patterns"><img class="hero-photo" src="public/assets/images/Sunset%20Vinta%20by%20the%20Historic%20Fort.png" alt="Sunset vinta sailing by the historic fort in Zamboanga" fetchpriority="high"><div class="art-stamp">ZAMBOANGA<br><span>PHILIPPINES</span></div><div class="hero-photo-caption">ART / IDEAS / EVERYDAY LIFE</div></div>
</div></section>

<section class="section culture-section" id="zamboanga"><div class="wrap">
<div class="section-head"><div><div class="eyebrow">A living heritage</div><h2>Discover the story of Zamboanga City</h2><p class="muted">From historic landmarks to maritime traditions and a vibrant, multilingual culture.</p></div><div class="slide-counter" aria-live="polite"><span id="slide-current">01</span> / 04</div></div>
<div class="culture-slideshow" id="culture-slideshow" aria-label="Zamboanga City history and culture slideshow">
<article class="culture-slide is-active" data-slide="0">
<img src="https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_(NS_Valderoza,_Zamboanga_City;_10-07-2023).jpg?width=1800" alt="Historic stone walls of Fort Pilar in Zamboanga City" fetchpriority="high">
<div class="slide-shade"></div><div class="slide-content"><span class="slide-kicker">HERITAGE LANDMARK · 17TH CENTURY</span><h3>Fort Pilar</h3><p>A landmark shaped by centuries of history, faith and city life. Today, Fort Pilar remains one of Zamboanga's best-known cultural landmarks.</p><a href="https://commons.wikimedia.org/wiki/File:Fort_Pilar_(NS_Valderoza,_Zamboanga_City;_10-07-2023).jpg" target="_blank" rel="noopener">Photo credit: Patrickroque01 / Wikimedia Commons ↗</a></div>
</article>
<article class="culture-slide" data-slide="1">
<img src="https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_Paseo_Del_Mar_bay_view_sunrise_(NS_Valderosa,_Zamboanga_City;_10-11-2023).jpg?width=1800" alt="Zamboanga waterfront near Paseo del Mar at sunrise" loading="lazy">
<div class="slide-shade"></div><div class="slide-content"><span class="slide-kicker">THE CITY BY THE SEA</span><h3>Life along the waterfront</h3><p>The sea has long connected Zamboanga's communities, livelihoods and neighboring islands. Paseo del Mar is part of the city's contemporary public life.</p><a href="https://commons.wikimedia.org/wiki/File:Fort_Pilar_Paseo_Del_Mar_bay_view_sunrise_(NS_Valderosa,_Zamboanga_City;_10-11-2023).jpg" target="_blank" rel="noopener">Photo credit: Patrickroque01 / Wikimedia Commons ↗</a></div>
</article>
<article class="culture-slide" data-slide="2">
<img src="https://commons.wikimedia.org/wiki/Special:FilePath/Vinta_at_Zamboanga_City_Tourism_Office,_Mar_2026.jpg?width=1600" alt="Traditional vinta boat representing the maritime heritage of Zamboanga" loading="lazy">
<div class="slide-shade"></div><div class="slide-content"><span class="slide-kicker">MARITIME TRADITION</span><h3>The colors of the vinta</h3><p>The vinta's colorful sail is an enduring symbol of the region's relationship with the sea and its rich maritime traditions.</p><a href="https://commons.wikimedia.org/wiki/File:Vinta_at_Zamboanga_City_Tourism_Office,_Mar_2026.jpg" target="_blank" rel="noopener">Photo credit: Ralff Nestor Nacor / Wikimedia Commons ↗</a></div>
</article>
<article class="culture-slide" data-slide="3">
<img src="https://commons.wikimedia.org/wiki/Special:FilePath/Yakan_Weavers.jpg?width=1800" alt="Yakan weavers preserving intricate traditional textile patterns" loading="lazy">
<div class="slide-shade"></div><div class="slide-content"><span class="slide-kicker">ARTISANSHIP · LIVING CULTURE</span><h3>Patterns that carry stories</h3><p>Traditional weaving and intricate geometric designs reflect generations of skill and cultural knowledge across the Zamboanga Peninsula.</p><a href="https://commons.wikimedia.org/wiki/File:Yakan_Weavers.jpg" target="_blank" rel="noopener">Photo credit: Rauxilian / Wikimedia Commons ↗</a></div>
</article>
<button class="slide-arrow slide-prev" type="button" aria-label="Previous slide">‹</button><button class="slide-arrow slide-next" type="button" aria-label="Next slide">›</button>
<div class="slide-dots" role="group" aria-label="Choose a slide"><button class="slide-dot is-active" type="button" aria-label="Slide 1" aria-pressed="true"></button><button class="slide-dot" type="button" aria-label="Slide 2" aria-pressed="false"></button><button class="slide-dot" type="button" aria-label="Slide 3" aria-pressed="false"></button><button class="slide-dot" type="button" aria-label="Slide 4" aria-pressed="false"></button></div>
</div>
<p class="slide-disclaimer">Historical and cultural notes are introductory highlights. Image links lead to Wikimedia Commons pages with source and license information.</p>
</div></section>

<section class="section" id="modules"><div class="wrap"><div class="section-head"><div><div class="eyebrow">Explore the platform</div><h2>Five ways to connect</h2><p class="muted">A single community space for creativity, information, commerce and cultural memory.</p></div></div>
<div class="category-row module-grid">
<?php foreach($modules as $module): ?><a class="category module-card" href="?category=<?=e($module['slug'])?>#latest"><span class="symbol"><?=e($module['symbol'])?></span><strong><?=e($module['name'])?></strong><span class="module-description"><?=e($module['description'])?></span><span class="module-link">Explore module ↗</span></a><?php endforeach; ?>
</div></div></section>

<section class="section" id="latest"><div class="wrap"><div class="section-head"><div><div class="eyebrow">Community publishing</div><h2><?= $category || $search ? 'Search results' : 'Latest approved posts' ?></h2><p class="muted">Only content approved by the System Administrator appears on this public page.</p></div>
<form class="searchbar" method="get"><input name="q" value="<?=e($search)?>" placeholder="Search stories, creators and listings…" aria-label="Search public content"><?php if($category!==''): ?><input type="hidden" name="category" value="<?=e($category)?>"><?php endif; ?><button class="btn">Search</button></form></div>
<?php if($category || $search): ?><p><a class="btn light" href="index.php#latest">Clear filters</a></p><?php endif; ?>
<div class="cards"><?php if (!$items): ?><div class="panel empty-state" style="grid-column:1/-1"><span class="empty-mark">✳</span><h3>Good stories start with a community.</h3><p class="muted">There are no published posts matching this view yet. Approved creators can prepare drafts in their personal space and submit them for review.</p><a class="btn" href="#create-account-modal" data-open-create-account>Join as a contributor</a></div><?php endif; ?>
<?php foreach($items as $i=>$item): ?><article class="story"><div class="story-img <?= $i%3===0?'art':($i%3===1?'home':'news') ?>"><?=e(strtoupper($item['category_name']))?></div><div class="story-body"><div class="tag"><?=e($item['category_name'])?></div><h3><?=e($item['title'])?></h3><p><?=e($item['excerpt'] ?? '')?></p><div class="story-foot"><span>By <?=e($item['full_name'])?></span><span><?= $item['price']!==null ? '₱'.number_format((float)$item['price'],2) : 'Read more ↗' ?></span></div><?php if(!empty($item['body'])): ?><details class="story-details"><summary>Read story</summary><p><?=nl2br(e($item['body']))?></p></details><?php endif; ?></div></article><?php endforeach; ?></div></div></section>

<section class="section contributor-section"><div class="wrap contributor-panel"><div><div class="eyebrow">Your voice belongs here</div><h2>Create your own space.</h2><p class="muted">Register, provide your contributor credentials for administrator review, then manage drafts, edit posts, search your work and submit content for publication once approved.</p></div><a class="btn" href="#create-account-modal" data-open-create-account>Apply for a creator account ↗</a></div></section>
</main>

<div class="register-modal" id="create-account-modal" aria-hidden="true"><div class="register-dialog" role="dialog" aria-modal="true" aria-label="Create an Account"><button type="button" class="register-close" id="close-create-account" aria-label="Close create account">×</button><iframe src="public/register.php" title="Create an Account" loading="lazy"></iframe></div></div>

<div class="signin-modal<?= $loginError !== '' ? ' is-open' : '' ?>" id="sign-in-modal" aria-hidden="<?= $loginError !== '' ? 'false' : 'true' ?>">
<div class="signin-dialog" role="dialog" aria-modal="true" aria-labelledby="signin-title">
<button type="button" class="signin-close" id="close-sign-in" aria-label="Close sign in">×</button>
<div class="eyebrow">Welcome back</div><h2 id="signin-title">Sign in</h2>
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
<footer><div class="wrap footer-grid"><div><a class="brand" href="index.php">Z.C. commonplace<span>.</span></a><p>Zamboanga stories, culture, creativity and community — shared with care.</p><p>© <?=date('Y')?> Z.C. commonplace. Community publishing with administrator review.</p></div><div><b>Explore</b><a href="?category=creative-arts-photography#latest">Creative Arts and Photography</a><a href="?category=news-connect#latest">News Connect</a><a href="?category=marketplace#latest">Marketplace</a></div><div><b>Discover</b><a href="?category=entertainment-politics#latest">Entertainment & Politics</a><a href="?category=history-culture#latest">History & Culture</a><a href="#zamboanga">Zamboanga heritage slideshow</a></div></div></footer>
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
  function hideRegisterModal(){if(!registerModal)return;registerModal.classList.remove('is-open');registerModal.setAttribute('aria-hidden','true');document.body.style.overflow='';const trigger=document.querySelector('[data-open-create-account], #open-create-account');if(trigger)trigger.focus();}
  openRegisterButtons.forEach(button=>button.addEventListener('click',event=>{event.preventDefault();hideSignInModal();showRegisterModal();}));
  if(closeRegister)closeRegister.addEventListener('click',hideRegisterModal);
  if(registerModal)registerModal.addEventListener('click',event=>{if(event.target===registerModal)hideRegisterModal();});

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
  modal.addEventListener('click', event => {
    if (event.target === modal) hideSignInModal();
  });
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
