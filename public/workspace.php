<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user=require_login();
if ($user['role']==='system_admin') { header('Location: admin.php'); exit; }
if ($user['account_status']!=='approved') { session_destroy(); header('Location: login.php'); exit; }
$search=trim((string)($_GET['q']??''));
$sql="SELECT c.id,c.title,c.status,c.updated_at,cat.name AS category_name,sub.name AS subcategory_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id WHERE c.owner_id=?";
$params=[$user['id']];
if($search!==''){$sql.=" AND (c.title LIKE ? OR c.excerpt LIKE ?)";$like="%$search%";$params[]=$like;$params[]=$like;}
$sql.=" ORDER BY c.updated_at DESC";$stmt=$pdo->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Creator workspace</title><link rel="stylesheet" href="assets/css/site.css"></head><body>
<header class="creator-site-header"><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php">View public site</a><a class="btn light" href="logout.php?scope=creator">Sign out</a></nav></div></header>
<nav class="creator-main-menu" aria-label="Creator navigation"><div class="wrap creator-main-menu-inner">
<a class="creator-main-menu-link active" href="workspace.php#dashboard" aria-current="page"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.4" stroke="currentColor" stroke-width="1.7"/></svg>Dashboard</a>
<a class="creator-main-menu-link" href="#my-account"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M4.8 20c.7-3.4 3.2-5.2 7.2-5.2s6.5 1.8 7.2 5.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>My Account</a>
<a class="creator-main-menu-link" href="#page-management"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21V5.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M4 6h16M8 10h8M8 13h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>Page Management</a>
</div></nav>
<main class="wrap dashboard creator-dashboard-layout" id="dashboard"><section>
<div class="section-head"><div><div class="eyebrow">Creator dashboard</div><h2>Your creative space</h2><p class="muted">Create and manage your work. Submissions stay private until approved for publication.</p></div><a class="btn" href="content-edit.php">+ Create new</a></div>
<div class="stats"><div class="stat"><span class="muted">Your items</span><b><?=count($items)?></b></div><div class="stat"><span class="muted">Pending review</span><b><?=count(array_filter($items,fn($x)=>$x['status']==='pending_review'))?></b></div><div class="stat"><span class="muted">Published</span><b><?=count(array_filter($items,fn($x)=>$x['status']==='published'))?></b></div></div>
<section class="panel creator-account-summary" id="my-account"><div class="eyebrow">My Account</div><h3><?=e($user['full_name'])?></h3><div class="creator-account-details"><div><span class="muted">Nickname</span><strong><?=e((string)($user['nickname']??'—'))?></strong></div><div><span class="muted">Email</span><strong><?=e((string)($user['email']??'—'))?></strong></div><div><span class="muted">Mobile</span><strong><?=e((string)($user['mobile_no']??'—'))?></strong></div></div></section>
<section id="page-management" class="creator-page-management"><div class="section-head"><div><div class="eyebrow">Page Management</div><h2>Manage your content</h2><p class="muted">Create, update, search, and manage your drafts and submissions.</p></div></div>
<form class="searchbar" style="margin:22px 0" method="get"><input name="q" value="<?=e($search)?>" placeholder="Search your content…"><button class="btn">Search</button></form>
<div class="panel"><table class="table"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Updated</th><th>Manage</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><?=e($item['title'])?></td><td><?=e($item['category_name'])?><?php if(!empty($item['subcategory_name'])): ?><div class="muted" style="font-size:10px">↳ <?=e($item['subcategory_name'])?></div><?php endif; ?></td><td><span class="status"><?=e(str_replace('_',' ',$item['status']))?></span></td><td><?=e($item['updated_at'])?></td><td><a href="content-edit.php?id=<?=(int)$item['id']?>">Edit</a> · <a href="content-delete.php?id=<?=(int)$item['id']?>">Delete</a></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="5">No content yet. Create your first draft.</td></tr><?php endif;?></tbody></table></div></section>
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
.creator-account-summary{margin:26px 0;padding:22px}
.creator-account-summary h3{margin:7px 0 16px}
.creator-account-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.creator-account-details>div{display:flex;flex-direction:column;gap:5px;min-width:0}
.creator-account-details strong{font-size:13px;overflow-wrap:anywhere}
.creator-page-management{margin-top:32px;scroll-margin-top:18px}
#my-account{scroll-margin-top:18px}
@media(max-width:620px){.creator-main-menu-inner{overflow-x:auto;gap:0}.creator-main-menu-link{padding:0 13px;font-size:12px}.creator-account-details{grid-template-columns:1fr;gap:12px}.creator-dashboard-layout{padding-top:22px}}
</style>
</body></html>
