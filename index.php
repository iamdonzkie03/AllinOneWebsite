<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
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
<style>.home-art-photo{background:#16070b!important;padding:0!important}.home-art-photo:before{display:none!important}.home-art-photo:after{display:none!important}.home-art-photo .hero-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;border-radius:inherit}.home-art-photo:before{content:""}.hero-photo-caption{position:absolute;left:28px;bottom:25px;color:#f8e9eb;font-size:10px;font-weight:800;letter-spacing:2.5px;text-shadow:0 2px 8px #000;z-index:1}.home-art-photo .art-stamp{z-index:2;background:rgba(10,8,10,.48)}@media(max-width:560px){.hero-photo-caption{left:18px;bottom:17px;font-size:8px;letter-spacing:1.5px}}</style>\n</head>
<body>
<div class="topbar"><div class="wrap"><span>ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY</span><span>Local perspectives. Shared with care.</span></div></div>
<header><div class="wrap nav">
<a class="brand" href="index.php">Z.C. commonplace<span>.</span></a>
<nav class="navlinks">
<a href="#modules">Explore</a><a href="#zamboanga">History & Culture</a><a href="#latest">Latest stories</a>
<a href="public/login.php">Sign in</a><a class="btn" href="public/register.php">Join the community</a>
</nav></div></header>
<main>
<section class="hero home-hero"><div class="wrap hero-grid"><div>
<div class="eyebrow">One city. Many stories.</div>
<h1>Zamboanga,<br><em>through our eyes.</em></h1>
<p>A digital home for the city's history, culture, creative talent, community news, marketplace, entertainment and public conversation.</p>
<div class="hero-actions"><a class="btn" href="#zamboanga">Explore our heritage</a><a class="btn light" href="public/register.php">Become a contributor ↗</a></div>
<div class="hero-note"><span class="hero-note-dot"></span>Independent community contributions · Administrator-reviewed publishing</div>
</div>
<div class="hero-art home-art home-art-photo" role="img" aria-label="Zamboanga heritage collage featuring Fort Pilar, the Philippine flag, a traditional vinta, and Yakan-inspired textile patterns"><img class="hero-photo" src="https://commons.wikimedia.org/wiki/Special:FilePath/Vinta_at_Zamboanga_City_Tourism_Office,_Mar_2026.jpg?width=1600" alt="Traditional colorful vinta boat at the Zamboanga City Tourism Office, representing Zamboanga City maritime heritage" fetchpriority="high"><div class="art-stamp">ZAMBOANGA<br><span>PHILIPPINES</span></div><div class="hero-photo-caption">ART / IDEAS / EVERYDAY LIFE</div></div>
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
<div class="cards"><?php if (!$items): ?><div class="panel empty-state" style="grid-column:1/-1"><span class="empty-mark">✳</span><h3>Good stories start with a community.</h3><p class="muted">There are no published posts matching this view yet. Approved creators can prepare drafts in their personal space and submit them for review.</p><a class="btn" href="public/register.php">Join as a contributor</a></div><?php endif; ?>
<?php foreach($items as $i=>$item): ?><article class="story"><div class="story-img <?= $i%3===0?'art':($i%3===1?'home':'news') ?>"><?=e(strtoupper($item['category_name']))?></div><div class="story-body"><div class="tag"><?=e($item['category_name'])?></div><h3><?=e($item['title'])?></h3><p><?=e($item['excerpt'] ?? '')?></p><div class="story-foot"><span>By <?=e($item['full_name'])?></span><span><?= $item['price']!==null ? '₱'.number_format((float)$item['price'],2) : 'Read more ↗' ?></span></div><?php if(!empty($item['body'])): ?><details class="story-details"><summary>Read story</summary><p><?=nl2br(e($item['body']))?></p></details><?php endif; ?></div></article><?php endforeach; ?></div></div></section>

<section class="section contributor-section"><div class="wrap contributor-panel"><div><div class="eyebrow">Your voice belongs here</div><h2>Create your own space.</h2><p class="muted">Register, provide your contributor credentials for administrator review, then manage drafts, edit posts, search your work and submit content for publication once approved.</p></div><a class="btn" href="public/register.php">Apply for a creator account ↗</a></div></section>
</main>
<footer><div class="wrap footer-grid"><div><a class="brand" href="index.php">Z.C. commonplace<span>.</span></a><p>Zamboanga stories, culture, creativity and community — shared with care.</p><p>© <?=date('Y')?> Z.C. commonplace. Community publishing with administrator review.</p></div><div><b>Explore</b><a href="?category=creative-arts-photography#latest">Creative Arts and Photography</a><a href="?category=news-connect#latest">News Connect</a><a href="?category=marketplace#latest">Marketplace</a></div><div><b>Discover</b><a href="?category=entertainment-politics#latest">Entertainment & Politics</a><a href="?category=history-culture#latest">History & Culture</a><a href="#zamboanga">Zamboanga heritage slideshow</a></div></div></footer>
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
