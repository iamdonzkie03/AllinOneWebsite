<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user=require_login();
if ($user['role']==='system_admin') { header('Location: admin.php'); exit; }
if ($user['account_status']!=='approved') { session_destroy(); header('Location: login.php'); exit; }
$profileStmt=$pdo->prepare('SELECT full_name,nickname,birthdate,birthplace,email,mobile_no,credential_notes,email_verified_at,phone_verified_at,account_status,created_at,updated_at,avatar_path,banner_path,short_bio,facebook_url,x_url,youtube_url,other_social_links,affiliate_links,direct_support_url,ecommerce_url FROM users WHERE id=? LIMIT 1');
$profileStmt->execute([(int)$user['id']]);
$profile=$profileStmt->fetch() ?: $user;
$formatProfileDate=static function(?string $value, string $format='F j, Y'): string {
    if (!$value) return '—';
    $timestamp=strtotime($value);
    return $timestamp ? date($format,$timestamp) : '—';
};
$postsStmt=$pdo->prepare("SELECT c.title,c.slug,c.published_at,cat.name AS category_name FROM content c JOIN categories cat ON cat.id=c.category_id WHERE c.owner_id=? AND c.status='published' ORDER BY c.published_at DESC,c.updated_at DESC");
$postsStmt->execute([(int)$user['id']]);
$publishedPosts=$postsStmt->fetchAll();
$activePage=(string)($_GET['page']??'dashboard');
if (!in_array($activePage,['dashboard','my-profile','page-management'],true)) { $activePage='dashboard'; }
$search=trim((string)($_GET['q']??''));
$sql="SELECT c.id,c.title,c.status,c.updated_at,cat.name AS category_name,sub.name AS subcategory_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id WHERE c.owner_id=?";
$params=[$user['id']];
if($search!==''){$sql.=" AND (c.title LIKE ? OR c.excerpt LIKE ?)";$like="%$search%";$params[]=$like;$params[]=$like;}
$sql.=" ORDER BY c.updated_at DESC";$stmt=$pdo->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Creator workspace</title><link rel="stylesheet" href="assets/css/site.css"></head><body>
<header class="creator-site-header"><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php">View public site</a><a class="btn light" href="logout.php?scope=creator">Sign out</a></nav></div></header>
<nav class="creator-main-menu" aria-label="Creator navigation"><div class="wrap creator-main-menu-inner">
<a class="creator-main-menu-link<?= $activePage==="dashboard" ? " active" : "" ?>" href="workspace.php?page=dashboard"<?= $activePage==="dashboard" ? " aria-current=\"page\"" : "" ?>><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/></svg>Dashboard</a>
<a class="creator-main-menu-link<?= $activePage==="my-profile" ? " active" : "" ?>" href="workspace.php?page=my-profile"<?= $activePage==="my-profile" ? " aria-current=\"page\"" : "" ?>><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M4.8 20c.7-3.4 3.2-5.2 7.2-5.2s6.5 1.8 7.2 5.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>My Profile</a>
<a class="creator-main-menu-link<?= $activePage==="page-management" ? " active" : "" ?>" href="workspace.php?page=page-management"<?= $activePage==="page-management" ? " aria-current=\"page\"" : "" ?>><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21V5.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4 6h16M8 10h8M8 13h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Page Management</a>
</div></nav>
<main class="wrap dashboard creator-dashboard-layout"><section>
<?php if($activePage==="dashboard"): ?>
<div class="section-head"><div><div class="eyebrow">Creator dashboard</div><h2>Your creative space</h2><p class="muted">Create and manage your work. Submissions stay private until approved for publication.</p></div><a class="btn" href="content-edit.php">+ Create new</a></div>
<div class="stats"><div class="stat"><span class="muted">Your items</span><b><?=count($items)?></b></div><div class="stat"><span class="muted">Pending review</span><b><?=count(array_filter($items,fn($x)=>$x['status']==='pending_review'))?></b></div><div class="stat"><span class="muted">Published</span><b><?=count(array_filter($items,fn($x)=>$x['status']==='published'))?></b></div></div>
<?php endif; ?>
<?php if($activePage==="my-profile"): ?>
<section class="panel creator-profile-panel" id="my-profile">
<div class="creator-profile-heading"><div><div class="eyebrow">My Profile</div><h2>Creator Profile</h2><p class="muted">Your public identity, content presence, and business offerings.</p></div><span class="creator-profile-status"><?=e(ucfirst((string)($profile['account_status']??'approved')))?></span></div>

<div class="creator-profile-group"><h3>Identity and Visual Branding</h3>
<div class="creator-branding-preview">
<div class="creator-profile-banner"><?php if(!empty($profile['banner_path'])): ?><img src="<?=e((string)$profile['banner_path'])?>" alt="Creator banner"><?php else: ?><span>Banner not uploaded</span><?php endif; ?></div>
<div class="creator-profile-avatar"><?php if(!empty($profile['avatar_path'])): ?><img src="<?=e((string)$profile['avatar_path'])?>" alt="Creator avatar"><?php else: ?><span><?=e(strtoupper(substr((string)($profile['nickname']??$profile['full_name']??'C'),0,1)))?></span><?php endif; ?></div>
</div>
<div class="creator-profile-grid">
<div><span class="muted">Name</span><strong><?=e((string)($profile['full_name']??'—'))?></strong></div>
<div><span class="muted">Short Bio</span><strong class="creator-profile-notes"><?=e((string)($profile['short_bio']??'Not added yet'))?></strong></div>
</div></div>

<div class="creator-profile-group"><h3>Content &amp; Social Hub</h3>
<div class="creator-profile-subheading">Social Hub</div>
<div class="creator-social-grid">
<?php foreach(['Facebook'=>'facebook_url','X'=>'x_url','YouTube'=>'youtube_url'] as $network=>$field): ?>
<div class="creator-profile-tile"><span><?=e($network)?></span><?php if(!empty($profile[$field]) && preg_match('~^https?://~i',(string)$profile[$field])): ?><a href="<?=e((string)$profile[$field])?>" target="_blank" rel="noopener noreferrer"><?=e((string)$profile[$field])?></a><?php else: ?><small>Not connected</small><?php endif; ?></div>
<?php endforeach; ?>
<div class="creator-profile-tile"><span>Other Social Media</span><strong><?=!empty($profile['other_social_links'])?nl2br(e((string)$profile['other_social_links'])):'Not added yet'?></strong></div>
</div>
<div class="creator-profile-subheading creator-profile-subheading-spaced">Posts &amp; Articles</div>
<div class="panel creator-posts-panel"><table class="table"><thead><tr><th>Post / Article</th><th>Category</th><th>Published</th><th>Status</th></tr></thead><tbody>
<?php foreach($publishedPosts as $post): ?><tr><td><?=e((string)$post['title'])?></td><td><?=e((string)$post['category_name'])?></td><td><?=e($formatProfileDate(isset($post['published_at'])?(string)$post['published_at']:null))?></td><td><span class="status">Published</span></td></tr><?php endforeach; ?>
<?php if(!$publishedPosts): ?><tr><td colspan="4">No published posts or articles yet.</td></tr><?php endif; ?>
</tbody></table></div>
</div>

<div class="creator-profile-group"><h3>Monetization &amp; Business Offerings</h3>
<div class="creator-social-grid creator-business-grid">
<div class="creator-profile-tile"><span>Affiliate Links</span><strong><?=!empty($profile['affiliate_links'])?nl2br(e((string)$profile['affiliate_links'])):'Not added yet'?></strong></div>
<div class="creator-profile-tile"><span>Direct Support</span><?php if(!empty($profile['direct_support_url']) && preg_match('~^https?://~i',(string)$profile['direct_support_url'])): ?><a href="<?=e((string)$profile['direct_support_url'])?>" target="_blank" rel="noopener noreferrer"><?=e((string)$profile['direct_support_url'])?></a><?php else: ?><small>Not configured</small><?php endif; ?></div>
<div class="creator-profile-tile"><span>E-Commerce</span><?php if(!empty($profile['ecommerce_url']) && preg_match('~^https?://~i',(string)$profile['ecommerce_url'])): ?><a href="<?=e((string)$profile['ecommerce_url'])?>" target="_blank" rel="noopener noreferrer"><?=e((string)$profile['ecommerce_url'])?></a><?php else: ?><small>Not configured</small><?php endif; ?></div>
</div>
</div>

<div class="creator-profile-group"><h3>Account Information</h3><div class="creator-profile-grid">
<div><span class="muted">Account Type</span><strong>Creator</strong></div>
<div><span class="muted">Account Status</span><strong><?=e(ucfirst(str_replace('_',' ',(string)($profile['account_status']??'approved'))))?></strong></div>
<div><span class="muted">Date Created</span><strong><?=e($formatProfileDate(isset($profile['created_at'])?(string)$profile['created_at']:null,'F j, Y · g:i A'))?></strong></div>
<div><span class="muted">Last Updated</span><strong><?=e($formatProfileDate(isset($profile['updated_at'])?(string)$profile['updated_at']:null,'F j, Y · g:i A'))?></strong></div>
</div></div>
</section>
<?php endif; ?>
<?php if($activePage==="page-management"): ?>
<section id="page-management" class="creator-page-management"><div class="section-head"><div><div class="eyebrow">Page Management</div><h2>Manage your content</h2><p class="muted">Create, update, search, and manage your drafts and submissions.</p></div></div>
<form class="searchbar" style="margin:22px 0" method="get"><input type="hidden" name="page" value="page-management"><input name="q" value="<?=e($search)?>" placeholder="Search your content…"><button class="btn">Search</button></form>
<div class="panel"><table class="table"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Updated</th><th>Manage</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><?=e($item['title'])?></td><td><?=e($item['category_name'])?><?php if(!empty($item['subcategory_name'])): ?><div class="muted" style="font-size:10px">↳ <?=e($item['subcategory_name'])?></div><?php endif; ?></td><td><span class="status"><?=e(str_replace('_',' ',$item['status']))?></span></td><td><?=e($item['updated_at'])?></td><td><a href="content-edit.php?id=<?=(int)$item['id']?>">Edit</a> · <a href="content-delete.php?id=<?=(int)$item['id']?>">Delete</a></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="5">No content yet. Create your first draft.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif; ?>
</section></main>
<style>
.creator-main-menu{position:relative;z-index:20;background:rgba(17,10,14,.97);border-top:1px solid rgba(255,255,255,.045);border-bottom:1px solid rgba(224,82,96,.24);box-shadow:0 8px 24px rgba(0,0,0,.2)}
.creator-main-menu-inner{display:flex;align-items:stretch;gap:8px;min-height:54px}
.creator-main-menu-link{position:relative;display:inline-flex;align-items:center;gap:9px;padding:0 19px;color:#cdbdc2;text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap;transition:color .18s,background .18s}
.creator-main-menu-link svg{width:17px;height:17px;color:#c98795;flex:0 0 17px}
.creator-main-menu-link:hover{color:#fff;background:rgba(174,48,71,.1)}
.creator-main-menu-link.active{color:#fff;background:rgba(174,48,71,.13)}
.creator-main-menu-link.active:after{content:"";position:absolute;left:17px;right:17px;bottom:0;height:2px;background:#c43b53;border-radius:2px 2px 0 0}
.creator-dashboard-layout{display:block;padding-top:32px}
.creator-profile-panel{margin:26px 0;padding:24px}
.creator-profile-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.08)}
.creator-profile-heading h2{margin:6px 0}
.creator-branding-preview{position:relative;margin:2px 0 48px}
.creator-profile-banner{height:145px;overflow:hidden;border-radius:12px;background:linear-gradient(120deg,#29121b,#5b1e32 55%,#1b1016);display:flex;align-items:center;justify-content:center;color:#c7aeb8;font-size:12px}
.creator-profile-banner img{width:100%;height:100%;object-fit:cover}
.creator-profile-avatar{position:absolute;left:22px;bottom:-34px;width:76px;height:76px;border-radius:50%;overflow:hidden;border:4px solid #21151b;background:#5c2638;display:grid;place-items:center;color:#fff;font-size:27px;font-weight:700}
.creator-profile-avatar img{width:100%;height:100%;object-fit:cover}
.creator-profile-subheading{font-size:12px;font-weight:700;color:#e7c4cf;margin:0 0 12px}
.creator-profile-subheading-spaced{margin-top:24px}
.creator-social-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.creator-profile-tile{display:flex;flex-direction:column;gap:7px;min-width:0;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.025)}
.creator-profile-tile>span{font-size:11px;color:#bba9b0;font-weight:600}
.creator-profile-tile strong,.creator-profile-tile a,.creator-profile-tile small{font-size:12px;overflow-wrap:anywhere;line-height:1.55}
.creator-profile-tile strong{color:#fff;font-weight:500}
.creator-profile-tile a{color:#ef9caf;text-decoration:none}
.creator-profile-tile a:hover{text-decoration:underline}
.creator-profile-tile small{color:#92828a}
.creator-posts-panel{padding:0;overflow-x:auto}
.creator-business-grid{grid-template-columns:repeat(3,minmax(0,1fr))}

.creator-profile-status{display:inline-flex;align-items:center;padding:6px 12px;border:1px solid rgba(91,190,133,.35);border-radius:999px;background:rgba(56,145,96,.13);color:#a7e4bd;font-size:11px;font-weight:700;white-space:nowrap}
.creator-profile-group{padding:20px 0;border-bottom:1px solid rgba(255,255,255,.07)}
.creator-profile-group:last-child{padding-bottom:0;border-bottom:0}
.creator-profile-group h3{margin:0 0 15px;color:#f1dce2;font-size:14px}
.creator-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 28px}
.creator-profile-grid>div{display:flex;flex-direction:column;gap:5px;min-width:0}
.creator-profile-grid strong{font-size:13px;font-weight:600;overflow-wrap:anywhere;color:#fff}
.creator-profile-grid small{font-size:11px;color:#a9cdb5}
.creator-profile-grid.single{grid-template-columns:1fr}
.creator-profile-notes{font-weight:400!important;line-height:1.65;white-space:normal}
.creator-page-management{margin-top:32px;scroll-margin-top:18px}
#my-profile{scroll-margin-top:18px}
@media(max-width:620px){.creator-social-grid,.creator-business-grid{grid-template-columns:1fr}.creator-profile-banner{height:112px}.creator-main-menu-inner{overflow-x:auto;gap:0}.creator-main-menu-link{padding:0 13px;font-size:12px}.creator-profile-grid{grid-template-columns:1fr;gap:13px}.creator-profile-panel{padding:18px}.creator-profile-heading{flex-direction:column}.creator-dashboard-layout{padding-top:22px}}
</style>
</body></html>
