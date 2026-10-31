<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$nicknameStmt = $pdo->prepare('SELECT nickname FROM users WHERE id = ? LIMIT 1');
$nicknameStmt->execute([(int)$admin['id']]);
$adminNickname = trim((string)($nicknameStmt->fetchColumn() ?: 'Administrator'));
$message = '';
$section = (string)($_GET['section'] ?? 'dashboard');
$allowedSections = ['dashboard', 'page-management', 'media-management', 'plugins', 'user-accounts'];
if (!in_array($section, $allowedSections, true)) $section = 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'approve_user' || $action === 'reject_user') {
        $status = $action === 'approve_user' ? 'approved' : 'rejected';
        $s = $pdo->prepare("UPDATE users SET account_status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND role<>'system_admin'");
        $s->execute([$status, $admin['id'], $id]);
        $message = 'Account review updated.';
        $section = 'user-accounts';
    }
    if ($action === 'publish' || $action === 'reject_content') {
        $status = $action === 'publish' ? 'published' : 'rejected';
        $s = $pdo->prepare("UPDATE content SET status=?,published_at=IF(?='published',NOW(),published_at) WHERE id=?");
        $s->execute([$status, $status, $id]);
        $message = 'Content moderation updated.';
        $section = 'dashboard';
    }
}
$pendingUsers = $pdo->query("SELECT id,full_name,email,credential_notes,created_at FROM users WHERE account_status='pending' AND role<>'system_admin' ORDER BY created_at")->fetchAll();
$pendingContent = $pdo->query("SELECT c.id,c.title,c.content_type,c.created_at,u.full_name,cat.name category_name FROM content c JOIN users u ON u.id=c.owner_id JOIN categories cat ON cat.id=c.category_id WHERE c.status='pending_review' ORDER BY c.created_at")->fetchAll();
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role<>'system_admin'")->fetchColumn();
$publishedContent = (int)$pdo->query("SELECT COUNT(*) FROM content WHERE status='published'")->fetchColumn();
function navActive(string $current, string $target): string { return $current === $target ? ' active' : ''; }
function adminUrl(string $section): string { return 'admin.php?section=' . rawurlencode($section); }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Administrator | Z.C. Commonplace</title>
<link rel="stylesheet" href="assets/css/site.css">
<style>
.admin-shell{min-height:calc(100vh - 82px);display:grid;grid-template-columns:250px minmax(0,1fr)}
.admin-sidebar{background:rgba(9,9,11,.92);border-right:1px solid var(--line);padding:28px 16px;position:sticky;top:82px;height:calc(100vh - 82px);overflow:auto}
.admin-label{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);padding:0 13px;margin:8px 0 12px}
.admin-nav{display:grid;gap:6px}
.admin-nav a{display:flex;align-items:center;gap:12px;padding:12px 13px;border:1px solid transparent;border-radius:12px;color:#c8bec1;font-size:13px}
.admin-nav a:hover{background:rgba(255,255,255,.035);color:#fff}
.admin-nav a.active{background:linear-gradient(100deg,rgba(128,20,39,.42),rgba(128,20,39,.08));border-color:rgba(184,50,63,.35);color:#fff}
.admin-nav .nav-icon{width:22px;text-align:center;color:var(--gold);font-size:16px}
.admin-subnav{margin:0 0 10px 22px;padding-left:13px;border-left:1px solid var(--line);display:grid;gap:3px}
.admin-subnav a{font-size:12px;padding:9px 10px}
.admin-main{padding:36px clamp(20px,4vw,52px) 60px;min-width:0}
.admin-topline{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:26px}
.admin-heading{font:500 clamp(32px,4vw,48px)/1.1 Georgia,serif;letter-spacing:-1.6px;margin:8px 0 12px}
.admin-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin:26px 0}
.admin-stat{padding:22px;border:1px solid var(--line);border-radius:17px;background:linear-gradient(145deg,rgba(255,255,255,.045),rgba(255,255,255,.012))}
.admin-stat span{display:block;color:var(--muted);font-size:12px}
.admin-stat strong{display:block;font:36px Georgia,serif;margin-top:9px;color:#f7eef0}
.admin-panel{margin:20px 0;padding:22px;min-width:0}
.admin-panel h2{font:24px Georgia,serif;margin:0 0 16px}
.admin-panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.admin-panel .table-wrap{overflow:auto}
.admin-panel .table{min-width:680px;width:100%}
.admin-empty{padding:28px;border:1px dashed var(--line);border-radius:14px;color:var(--muted);text-align:center}
.admin-feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:24px}
.admin-feature{padding:23px;border:1px solid var(--line);border-radius:17px;background:linear-gradient(145deg,rgba(255,255,255,.035),rgba(255,255,255,.01))}
.admin-feature .symbol{font-size:24px;color:var(--gold)}
.admin-feature h3{font:22px Georgia,serif;margin:12px 0 8px}
.admin-feature p{font-size:13px;color:var(--muted);margin:0 0 17px}
.admin-note{padding:14px 16px;border:1px solid rgba(184,50,63,.25);border-radius:12px;color:#d7c9cc;background:rgba(105,8,24,.12);font-size:13px}
@media(max-width:850px){.admin-shell{grid-template-columns:1fr}.admin-sidebar{position:relative;top:auto;height:auto;border-right:0;border-bottom:1px solid var(--line);padding:16px}.admin-nav{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-subnav{grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));margin:0 0 8px 0;padding:8px 0 0;border-left:0;border-top:1px solid var(--line)}.admin-main{padding-top:25px}}
@media(max-width:560px){.admin-stats,.admin-feature-grid{grid-template-columns:1fr}.admin-nav{grid-template-columns:1fr}.admin-subnav{grid-template-columns:1fr}.admin-topline .btn{width:100%}}
</style></head><body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php">Public website</a><a class="btn light" href="logout.php?scope=admin">Sign out</a></nav></div></header>
<div class="admin-shell">
<aside class="admin-sidebar">
  <div class="admin-label">Administration</div>
  <nav class="admin-nav" aria-label="Administrator menu">
    <a class="<?=navActive($section,'dashboard')?>" href="<?=e(adminUrl('dashboard'))?>"><span class="nav-icon">⌂</span>Dashboard</a>
    <a class="<?=in_array($section,['page-management','media-management','plugins'],true)?' active':''?>" href="<?=e(adminUrl('page-management'))?>"><span class="nav-icon">⚙</span>Settings</a>
    <div class="admin-subnav">
      <a class="<?=navActive($section,'page-management')?>" href="<?=e(adminUrl('page-management'))?>">Page Management</a>
      <a class="<?=navActive($section,'media-management')?>" href="<?=e(adminUrl('media-management'))?>">Media Management</a>
      <a class="<?=navActive($section,'plugins')?>" href="<?=e(adminUrl('plugins'))?>">Plugins</a>
    </div>
    <a class="<?=navActive($section,'user-accounts')?>" href="<?=e(adminUrl('user-accounts'))?>"><span class="nav-icon">♙</span>User Accounts <span class="status"><?=count($pendingUsers)?></span></a>
  </nav>
  <div class="admin-note" style="margin:26px 5px 0">Signed in as<br><strong><?=e($adminNickname)?></strong><br><span class="muted">System Administrator</span></div>
</aside>
<main class="admin-main">
<?php if($message):?><div class="panel" style="margin-bottom:20px"><?=e($message)?></div><?php endif;?>
<?php if($section==='dashboard'):?>
  <div class="admin-topline"><div><div class="eyebrow">System control</div><h1 class="admin-heading">Dashboard</h1><p class="muted">A clear overview of your website, accounts, and content activity.</p></div><a class="btn" href="<?=e(adminUrl('user-accounts'))?>">Review accounts <span aria-hidden="true">→</span></a></div>
  <div class="admin-stats"><div class="admin-stat"><span>Contributor accounts</span><strong><?=$totalUsers?></strong></div><div class="admin-stat"><span>Pending approvals</span><strong><?=count($pendingUsers)?></strong></div><div class="admin-stat"><span>Published content</span><strong><?=$publishedContent?></strong></div></div>
  <section class="panel admin-panel"><div class="admin-panel-head"><h2>Content moderation</h2><span class="status"><?=count($pendingContent)?> awaiting review</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Content</th><th>Creator</th><th>Category</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>
  <?php foreach($pendingContent as $c):?><tr><td><?=e($c['title'])?><br><small><?=e($c['content_type'])?></small></td><td><?=e($c['full_name'])?></td><td><?=e($c['category_name'])?></td><td><?=e($c['created_at'])?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn" name="action" value="publish">Publish</button><button class="btn light" name="action" value="reject_content">Reject</button></form></td></tr><?php endforeach;?>
  <?php if(!$pendingContent):?><tr><td colspan="5">No content awaiting review.</td></tr><?php endif;?></tbody></table></div></section>
  <div class="admin-feature-grid"><div class="admin-feature"><div class="symbol">▤</div><h3>Page Management</h3><p>Manage the pages and sections displayed on the public website.</p><a class="btn light" href="<?=e(adminUrl('page-management'))?>">Open settings</a></div><div class="admin-feature"><div class="symbol">▧</div><h3>Media Management</h3><p>Organize the images and media used throughout the website.</p><a class="btn light" href="<?=e(adminUrl('media-management'))?>">Open settings</a></div></div>
<?php elseif($section==='user-accounts'):?>
  <div class="admin-topline"><div><div class="eyebrow">Access control</div><h1 class="admin-heading">User Accounts</h1><p class="muted">Review contributor registrations and manage access approvals.</p></div><span class="status"><?=count($pendingUsers)?> pending</span></div>
  <section class="panel admin-panel"><h2>Account approval queue</h2><div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Contributor details</th><th>Registered</th><th>Decision</th></tr></thead><tbody>
  <?php foreach($pendingUsers as $u):?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['email'])?></td><td class="credential-cell"><?=nl2br(e($u['credential_notes']??'No details provided'))?></td><td><?=e($u['created_at'])?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="btn" name="action" value="approve_user">Approve</button><button class="btn light" name="action" value="reject_user">Reject</button></form></td></tr><?php endforeach;?>
  <?php if(!$pendingUsers):?><tr><td colspan="5">No accounts awaiting review.</td></tr><?php endif;?></tbody></table></div></section>
<?php else: $settings = ['page-management'=>['Page Management','Manage the website pages and their publication status.','▤'],'media-management'=>['Media Management','Manage image and media assets used by public pages and contributor content.','▧'],'plugins'=>['Plugins','Review and manage optional website extensions.','⌘']]; $info=$settings[$section];?>
  <div class="admin-topline"><div><div class="eyebrow">Settings</div><h1 class="admin-heading"><?=e($info[0])?></h1><p class="muted"><?=e($info[1])?></p></div><a class="btn light" href="<?=e(adminUrl('dashboard'))?>">← Dashboard</a></div>
  <section class="panel admin-panel"><div class="symbol" style="font-size:30px;color:var(--gold)"><?=e($info[2])?></div><h2 style="margin-top:12px"><?=e($info[0])?></h2><p class="muted">This section is now included in the System Administrator menu. Its management tools can be connected here as the corresponding storage and database structure is confirmed.</p><div class="admin-note">Navigation is ready. No page, media, or plugin records have been changed by this update.</div></section>
<?php endif;?>
</main></div></body></html>
