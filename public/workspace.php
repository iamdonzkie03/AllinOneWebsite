<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user=require_login();
if ($user['role']==='system_admin') { header('Location: admin.php'); exit; }
if ($user['account_status']!=='approved') { session_destroy(); header('Location: login.php'); exit; }
// Ensure Creator profile fields exist before selecting them. This is deliberately
// idempotent so existing Laragon databases are upgraded on their next visit.
$creatorProfileColumns = [
    'last_name' => 'VARCHAR(100) NULL',
    'first_name' => 'VARCHAR(100) NULL',
    'middle_name' => 'VARCHAR(100) NULL',
    'name_suffix' => 'VARCHAR(30) NULL',
    'avatar_path' => 'VARCHAR(500) NULL',
    'banner_path' => 'VARCHAR(500) NULL',
    'short_bio' => 'VARCHAR(500) NULL',
    'facebook_url' => 'VARCHAR(500) NULL',
    'x_url' => 'VARCHAR(500) NULL',
    'youtube_url' => 'VARCHAR(500) NULL',
    'other_social_links' => 'LONGTEXT NULL',
    'affiliate_links' => 'LONGTEXT NULL',
    'direct_support_url' => 'VARCHAR(500) NULL',
    'ecommerce_url' => 'VARCHAR(500) NULL',
];
$profileColumnCheck=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'users\' AND COLUMN_NAME=?');
foreach ($creatorProfileColumns as $column=>$definition) {
    $profileColumnCheck->execute([$column]);
    if ((int)$profileColumnCheck->fetchColumn()===0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
    }
}
// Save an individual Creator profile section.
$profileSaveMessage='';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_creator_profile_section') {
    verify_csrf();
    $section=(string)($_POST['profile_section']??'');
    $creatorId=(int)$user['id'];
    if ($section==='identity') {
        $lastName=trim((string)($_POST['last_name']??''));
        $firstName=trim((string)($_POST['first_name']??''));
        $middleName=trim((string)($_POST['middle_name']??''));
        $suffix=trim((string)($_POST['name_suffix']??''));
        $nickname=trim((string)($_POST['nickname']??''));
        $email=strtolower(trim((string)($_POST['email']??'')));
        $mobile=preg_replace('/[\s()-]/','',trim((string)($_POST['mobile_no']??'')))??'';
        $birthdate=trim((string)($_POST['birthdate']??''));
        $birthplace=trim((string)($_POST['birthplace']??''));
        $shortBio=trim((string)($_POST['credential_notes']??''));
        $newPassword=(string)($_POST['new_password']??'');
        $confirmPassword=(string)($_POST['confirm_password']??'');
        if ($lastName==='' || $firstName==='' || mb_strlen($lastName)>100 || mb_strlen($firstName)>100 || mb_strlen($middleName)>100 || mb_strlen($suffix)>30 || mb_strlen($nickname)>80 || mb_strlen($shortBio)>500) { http_response_code(422); exit('Please provide a valid Lastname and Firstname and check the allowed field lengths.'); }
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>190) { http_response_code(422); exit('Please enter a valid email address.'); }
        if ($mobile!=='' && !preg_match('/^\+?[0-9]{7,16}$/',$mobile)) { http_response_code(422); exit('Please enter a valid mobile number.'); }
        $birthdateValue=null;
        if ($birthdate!=='') { $dateCheck=DateTime::createFromFormat('Y-m-d',$birthdate); if (!$dateCheck || $dateCheck->format('Y-m-d')!==$birthdate || $birthdate>date('Y-m-d')) { http_response_code(422); exit('Please enter a valid birthdate.'); } $birthdateValue=$birthdate; }
        $emailCheck=$pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1'); $emailCheck->execute([$email,$creatorId]);
        if ($emailCheck->fetchColumn()) { http_response_code(422); exit('That email address is already in use.'); }
        if ($newPassword!=='' || $confirmPassword!=='') {
            if ($newPassword!==$confirmPassword) { http_response_code(422); exit('The new password and retyped password do not match.'); }
            if (strlen($newPassword)<10 || !preg_match('/[A-Z]/',$newPassword) || !preg_match('/[a-z]/',$newPassword) || !preg_match('/[0-9]/',$newPassword) || !preg_match('/[^A-Za-z0-9]/',$newPassword)) { http_response_code(422); exit('Password must be at least 10 characters and include uppercase, lowercase, number, and symbol characters.'); }
        }
        $avatarPath=null; $bannerPath=null; $uploadDir=__DIR__.'/uploads/creator_profiles';
        if (!is_dir($uploadDir) && !mkdir($uploadDir,0755,true) && !is_dir($uploadDir)) { http_response_code(500); exit('Unable to create the profile image folder.'); }
        foreach (['avatar'=>'avatar_path','banner'=>'banner_path'] as $input=>$column) {
            if (!isset($_FILES[$input]) || (int)$_FILES[$input]['error']===UPLOAD_ERR_NO_FILE) continue;
            if ((int)$_FILES[$input]['error']!==UPLOAD_ERR_OK || (int)$_FILES[$input]['size']>5*1024*1024) { http_response_code(422); exit('Each profile image must be 5 MB or smaller.'); }
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$input]['tmp_name']); $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($extensions[$mime])) { http_response_code(422); exit('Profile images must be JPG, PNG, or WebP files.'); }
            $filename=$input.'-'.$creatorId.'-'.bin2hex(random_bytes(12)).'.'.$extensions[$mime];
            if (!move_uploaded_file($_FILES[$input]['tmp_name'],$uploadDir.'/'.$filename)) { http_response_code(500); exit('Unable to save the uploaded profile image.'); }
            if ($input==='avatar') $avatarPath='uploads/creator_profiles/'.$filename; else $bannerPath='uploads/creator_profiles/'.$filename;
        }
        $fullName=$lastName.', '.$firstName.($middleName!==''?' '.$middleName:'').($suffix!==''?' '.$suffix:'');
        $sql='UPDATE users SET last_name=?,first_name=?,middle_name=?,name_suffix=?,full_name=?,nickname=?,email=?,mobile_no=?,birthdate=?,birthplace=?,credential_notes=?,short_bio=?';
        $values=[$lastName,$firstName,$middleName!==''?$middleName:null,$suffix!==''?$suffix:null,$fullName,$nickname!==''?$nickname:null,$email,$mobile!==''?$mobile:null,$birthdateValue,$birthplace!==''?$birthplace:null,$shortBio,$shortBio];
        if ($avatarPath!==null) { $sql.=',avatar_path=?'; $values[]=$avatarPath; }
        if ($bannerPath!==null) { $sql.=',banner_path=?'; $values[]=$bannerPath; }
        if ($newPassword!=='') { $sql.=',password_hash=?'; $values[]=password_hash($newPassword,PASSWORD_DEFAULT); }
        $sql.=' WHERE id=?'; $values[]=$creatorId; $pdo->prepare($sql)->execute($values);
        $user['full_name']=$fullName; $user['nickname']=$nickname; $_SESSION['user']=$user;
    } elseif ($section==='social') {
        $fields=['facebook_url','x_url','youtube_url','other_social_links'];
        $values=[];
        foreach ($fields as $field) $values[]=trim((string)($_POST[$field]??''));
        foreach (array_slice($values,0,3) as $url) {
            if ($url!=='' && !filter_var($url,FILTER_VALIDATE_URL)) {
                http_response_code(422); exit('Please enter valid social media URLs, including https://.');
            }
        }
        $pdo->prepare('UPDATE users SET facebook_url=?,x_url=?,youtube_url=?,other_social_links=? WHERE id=?')->execute([...$values,$creatorId]);
    } elseif ($section==='business') {
        $affiliate=trim((string)($_POST['affiliate_links']??''));
        $support=trim((string)($_POST['direct_support_url']??''));
        $commerce=trim((string)($_POST['ecommerce_url']??''));
        foreach ([$support,$commerce] as $url) {
            if ($url!=='' && !filter_var($url,FILTER_VALIDATE_URL)) {
                http_response_code(422); exit('Please enter valid URLs, including https://.');
            }
        }
        $pdo->prepare('UPDATE users SET affiliate_links=?,direct_support_url=?,ecommerce_url=? WHERE id=?')->execute([$affiliate,$support,$commerce,$creatorId]);
    } else {
        http_response_code(400); exit('Invalid profile section.');
    }
    header('Location: workspace.php?page=my-profile&profile_saved='.rawurlencode($section)); exit;
}
$profileStmt=$pdo->prepare('SELECT full_name,last_name,first_name,middle_name,name_suffix,nickname,birthdate,birthplace,email,mobile_no,credential_notes,email_verified_at,phone_verified_at,account_status,created_at,updated_at,avatar_path,banner_path,short_bio,facebook_url,x_url,youtube_url,other_social_links,affiliate_links,direct_support_url,ecommerce_url FROM users WHERE id=? LIMIT 1');
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
<header class="creator-site-header"><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php?session_context=creator" target="_blank" rel="noopener noreferrer">View public site</a><a class="btn light" href="logout.php?scope=creator">Sign out</a></nav></div></header>
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

<div class="creator-profile-group creator-profile-card creator-profile-identity"><div class="creator-profile-group-heading"><h3>Identity and Visual Branding</h3><button type="button" class="btn light creator-profile-edit" data-profile-modal="identity">Edit</button></div>
<div class="creator-branding-preview">
<div class="creator-profile-banner"><?php if(!empty($profile['banner_path'])): ?><img src="<?=e((string)$profile['banner_path'])?>" alt="Creator banner"><?php else: ?><span>Banner not uploaded</span><?php endif; ?></div>
<div class="creator-profile-avatar"><?php if(!empty($profile['avatar_path'])): ?><img src="<?=e((string)$profile['avatar_path'])?>" alt="Creator avatar"><?php else: ?><span><?=e(strtoupper(substr((string)($profile['nickname']??$profile['full_name']??'C'),0,1)))?></span><?php endif; ?></div>
</div>
<div class="creator-profile-grid">
<div><span class="muted">Name</span><strong><?=e((string)($profile['full_name']??'—'))?></strong></div>
<div><span class="muted">Short Bio</span><strong class="creator-profile-notes"><?=e((string)($profile['short_bio']??'Not added yet'))?></strong></div>
</div></div>

<div class="creator-profile-group creator-profile-card creator-profile-social"><div class="creator-profile-group-heading"><h3>Content &amp; Social Hub</h3><button type="button" class="btn light creator-profile-edit" data-profile-modal="social">Edit</button></div>
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

<div class="creator-profile-group creator-profile-card creator-profile-business"><div class="creator-profile-group-heading"><h3>Monetization &amp; Business Offerings</h3><button type="button" class="btn light creator-profile-edit" data-profile-modal="business">Edit</button></div>
<div class="creator-social-grid creator-business-grid">
<div class="creator-profile-tile"><span>Affiliate Links</span><strong><?=!empty($profile['affiliate_links'])?nl2br(e((string)$profile['affiliate_links'])):'Not added yet'?></strong></div>
<div class="creator-profile-tile"><span>Direct Support</span><?php if(!empty($profile['direct_support_url']) && preg_match('~^https?://~i',(string)$profile['direct_support_url'])): ?><a href="<?=e((string)$profile['direct_support_url'])?>" target="_blank" rel="noopener noreferrer"><?=e((string)$profile['direct_support_url'])?></a><?php else: ?><small>Not configured</small><?php endif; ?></div>
<div class="creator-profile-tile"><span>E-Commerce</span><?php if(!empty($profile['ecommerce_url']) && preg_match('~^https?://~i',(string)$profile['ecommerce_url'])): ?><a href="<?=e((string)$profile['ecommerce_url'])?>" target="_blank" rel="noopener noreferrer"><?=e((string)$profile['ecommerce_url'])?></a><?php else: ?><small>Not configured</small><?php endif; ?></div>
</div>
</div>

</section>
<?php if(!empty($_GET['profile_saved'])): ?><div class="creator-profile-save-notice" role="status">Profile section successfully updated.</div><?php endif; ?>
<div class="creator-profile-modal" id="profile-modal-identity" aria-hidden="true"><div class="creator-profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-identity-title"><div class="creator-profile-modal-head"><h3 id="profile-identity-title">Edit Identity and Visual Branding</h3><button type="button" class="creator-profile-modal-close" aria-label="Close">&times;</button></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_creator_profile_section"><input type="hidden" name="profile_section" value="identity"><div class="creator-name-fields"><label>Lastname<input name="last_name" maxlength="100" required value="<?=e((string)($profile['last_name']??''))?>"></label><label>Firstname<input name="first_name" maxlength="100" required value="<?=e((string)($profile['first_name']??$profile['full_name']??''))?>"></label><label>Middle Name<input name="middle_name" maxlength="100" value="<?=e((string)($profile['middle_name']??''))?>"></label><label>Suffix<input name="name_suffix" maxlength="30" placeholder="Jr., Sr., III" value="<?=e((string)($profile['name_suffix']??''))?>"></label><label>Nickname<input name="nickname" maxlength="80" value="<?=e((string)($profile['nickname']??''))?>"></label></div><label>Short Bio / Credential Notes<textarea name="credential_notes" maxlength="500" rows="4"><?=e((string)($profile['credential_notes']??$profile['short_bio']??''))?></textarea><small>Maximum 500 characters.</small></label><label>Avatar Photo<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp"><small>JPG, PNG, or WebP; maximum 5 MB.</small></label><label>Banner Image<input type="file" name="banner" accept="image/png,image/jpeg,image/webp"><small>JPG, PNG, or WebP; maximum 5 MB.</small></label><div class="creator-name-fields creator-personal-fields"><label>Email Address<input type="email" name="email" maxlength="190" required value="<?=e((string)($profile['email']??''))?>"></label><label>Mobile Number<input type="tel" name="mobile_no" maxlength="20" value="<?=e((string)($profile['mobile_no']??''))?>" placeholder="+63 9XX XXX XXXX"></label><label>Birthdate<input type="date" name="birthdate" max="<?=e(date('Y-m-d'))?>" value="<?=e((string)($profile['birthdate']??''))?>"></label><label>Birthplace<input name="birthplace" maxlength="180" value="<?=e((string)($profile['birthplace']??''))?>"></label></div><div class="creator-password-section"><h4>Change Password</h4><p class="muted">Leave both password fields blank to keep your current password.</p><label>New Password<input type="password" name="new_password" id="creator-new-password" minlength="10" autocomplete="new-password"></label><label>Retype New Password<input type="password" name="confirm_password" id="creator-confirm-password" minlength="10" autocomplete="new-password"></label><div class="creator-password-strength"><div class="creator-password-strength-track"><span id="creator-password-strength-bar"></span></div><small id="creator-password-strength-label">Password strength: Not entered</small></div><ul class="creator-password-requirements"><li data-rule="length">At least 10 characters</li><li data-rule="upper">At least 1 uppercase letter</li><li data-rule="lower">At least 1 lowercase letter</li><li data-rule="number">At least 1 number</li><li data-rule="symbol">At least 1 symbol</li><li data-rule="match">Passwords match</li></ul></div><div class="creator-profile-modal-actions"><button type="button" class="btn light creator-profile-modal-close">Cancel</button><button class="btn" type="submit">Save Changes</button></div></form></div></div>
<div class="creator-profile-modal" id="profile-modal-social" aria-hidden="true"><div class="creator-profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-social-title"><div class="creator-profile-modal-head"><h3 id="profile-social-title">Edit Content &amp; Social Hub</h3><button type="button" class="creator-profile-modal-close" aria-label="Close">&times;</button></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_creator_profile_section"><input type="hidden" name="profile_section" value="social"><label>Facebook URL<input type="url" name="facebook_url" placeholder="https://facebook.com/..." value="<?=e((string)($profile['facebook_url']??''))?>"></label><label>X URL<input type="url" name="x_url" placeholder="https://x.com/..." value="<?=e((string)($profile['x_url']??''))?>"></label><label>YouTube URL<input type="url" name="youtube_url" placeholder="https://youtube.com/..." value="<?=e((string)($profile['youtube_url']??''))?>"></label><label>Other Social Media<textarea name="other_social_links" rows="4" placeholder="Add other social links, one per line"><?=e((string)($profile['other_social_links']??''))?></textarea></label><div class="creator-profile-modal-actions"><button type="button" class="btn light creator-profile-modal-close">Cancel</button><button class="btn" type="submit">Save Changes</button></div></form></div></div>
<div class="creator-profile-modal" id="profile-modal-business" aria-hidden="true"><div class="creator-profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-business-title"><div class="creator-profile-modal-head"><h3 id="profile-business-title">Edit Monetization &amp; Business Offerings</h3><button type="button" class="creator-profile-modal-close" aria-label="Close">&times;</button></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_creator_profile_section"><input type="hidden" name="profile_section" value="business"><label>Affiliate Links<textarea name="affiliate_links" rows="4" placeholder="Add affiliate links, one per line"><?=e((string)($profile['affiliate_links']??''))?></textarea></label><label>Direct Support URL<input type="url" name="direct_support_url" placeholder="https://..." value="<?=e((string)($profile['direct_support_url']??''))?>"></label><label>E-Commerce URL<input type="url" name="ecommerce_url" placeholder="https://..." value="<?=e((string)($profile['ecommerce_url']??''))?>"></label><div class="creator-profile-modal-actions"><button type="button" class="btn light creator-profile-modal-close">Cancel</button><button class="btn" type="submit">Save Changes</button></div></form></div></div>
<script>
const creatorNewPassword=document.getElementById('creator-new-password');
const creatorConfirmPassword=document.getElementById('creator-confirm-password');
function updateCreatorPasswordStrength(){
 if(!creatorNewPassword)return;
 const value=creatorNewPassword.value;
 const checks={length:value.length>=10,upper:/[A-Z]/.test(value),lower:/[a-z]/.test(value),number:/[0-9]/.test(value),symbol:/[^A-Za-z0-9]/.test(value),match:value.length>0&&value===creatorConfirmPassword.value};
 Object.entries(checks).forEach(([rule,valid])=>{const item=document.querySelector('.creator-password-requirements [data-rule="'+rule+'"]');if(item)item.classList.toggle('is-valid',valid);});
 const score=Object.values(checks).filter(Boolean).length,bar=document.getElementById('creator-password-strength-bar'),label=document.getElementById('creator-password-strength-label');
 if(bar){bar.style.width=(score/6*100)+'%';bar.style.background=score>=6?'#4caf78':score>=4?'#d2a34b':'#b63c55';}
 if(label)label.textContent='Password strength: '+(value===''?'Not entered':score<=2?'Weak':score<=4?'Fair':score===5?'Good':'Strong');
}
[creatorNewPassword,creatorConfirmPassword].forEach(input=>input?.addEventListener('input',updateCreatorPasswordStrength));
document.querySelectorAll('.creator-profile-modal form').forEach(form=>form.addEventListener('submit',event=>{
 if(form.querySelector('[name="profile_section"]')?.value!=='identity')return;
 const password=creatorNewPassword?.value||'',confirmation=creatorConfirmPassword?.value||'';
 if((password!==''||confirmation!=='')&&!(password.length>=10&&/[A-Z]/.test(password)&&/[a-z]/.test(password)&&/[0-9]/.test(password)&&/[^A-Za-z0-9]/.test(password)&&password===confirmation)){event.preventDefault();updateCreatorPasswordStrength();alert('Password must be at least 10 characters and include uppercase, lowercase, number, and symbol characters. Retype the same password to confirm.');}
}));
document.querySelectorAll('[data-profile-modal]').forEach(button=>button.addEventListener('click',()=>{const modal=document.getElementById('profile-modal-'+button.dataset.profileModal);if(modal){modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('profile-modal-open');const first=modal.querySelector('input:not([type=hidden]),textarea');if(first)first.focus();}}));
function closeCreatorProfileModal(modal){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');if(!document.querySelector('.creator-profile-modal.is-open'))document.body.classList.remove('profile-modal-open');}
document.querySelectorAll('.creator-profile-modal-close').forEach(button=>button.addEventListener('click',()=>closeCreatorProfileModal(button.closest('.creator-profile-modal'))));
document.addEventListener('keydown',event=>{if(event.key==='Escape'){document.querySelectorAll('.creator-profile-modal.is-open').forEach(closeCreatorProfileModal);}});
</script>
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

.creator-profile-group.creator-profile-card{margin:16px 0;padding:20px;border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 12px 28px rgba(0,0,0,.18)}
.creator-profile-group.creator-profile-identity{background:linear-gradient(135deg,rgba(106,35,55,.58),rgba(54,22,35,.72))}
.creator-profile-group.creator-profile-social{background:linear-gradient(135deg,rgba(35,68,91,.58),rgba(22,39,57,.76))}
.creator-profile-group.creator-profile-business{background:linear-gradient(135deg,rgba(75,75,39,.58),rgba(43,48,27,.76))}
.creator-profile-group-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}
.creator-profile-group-heading h3{margin:0}
.creator-profile-edit{padding:7px 15px;font-size:12px}
.creator-profile-modal{display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;padding:20px;background:rgba(5,3,5,.78);backdrop-filter:blur(7px)}
.creator-profile-modal.is-open{display:flex}
.creator-profile-modal-dialog{width:min(580px,100%);max-height:min(88vh,850px);overflow:auto;padding:24px;border:1px solid rgba(225,151,170,.25);border-radius:16px;background:#21151c;box-shadow:0 24px 80px rgba(0,0,0,.55)}
/* Give the detailed Identity and Visual Branding form a larger 75% viewport workspace. */
#profile-modal-identity .creator-profile-modal-dialog{width:75vw;max-width:1100px;height:75vh;max-height:75vh;box-sizing:border-box;overflow-y:auto;overscroll-behavior:contain}
#profile-modal-identity .creator-profile-modal-dialog form{gap:11px}
#profile-modal-identity .creator-profile-modal-head{margin-bottom:14px;padding-bottom:10px}
#profile-modal-identity .creator-password-section{padding:11px;gap:8px}
@media(max-width:700px){#profile-modal-identity .creator-profile-modal-dialog{width:75vw;padding:16px}#profile-modal-identity .creator-name-fields{grid-template-columns:1fr}}

.creator-profile-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.09)}
.creator-profile-modal-head h3{margin:0;color:#f6e8ed;font-size:16px}
.creator-profile-modal-close{border:0;background:transparent;color:#e8cbd4;font-size:26px;line-height:1;cursor:pointer}
.creator-profile-modal form{display:grid;gap:15px}
.creator-name-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.creator-password-section{padding:15px;border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(0,0,0,.16);display:grid;gap:10px}
.creator-password-section h4{margin:0;color:#f1dce2;font-size:13px}
.creator-password-section p{margin:0;font-size:11px}
.creator-password-strength-track{height:7px;border-radius:20px;background:rgba(255,255,255,.12);overflow:hidden}
.creator-password-strength-track span{display:block;height:100%;width:0;background:#b63c55;transition:width .2s,background .2s}
.creator-password-strength small{font-size:11px;color:#cbb7bf}
.creator-password-requirements{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 14px;margin:0;padding:0;list-style:none}
.creator-password-requirements li{font-size:11px;color:#bbaab1}
.creator-password-requirements li:before{content:"○";margin-right:7px;color:#d58b9b}
.creator-password-requirements li.is-valid{color:#a7e4bd}
.creator-password-requirements li.is-valid:before{content:"✓";color:#83d6a2}
@media(max-width:620px){.creator-name-fields,.creator-password-requirements{grid-template-columns:1fr}}
.creator-profile-modal label{display:grid;gap:7px;color:#e8d6dc;font-size:12px;font-weight:600}
.creator-profile-modal input:not([type=file]),.creator-profile-modal textarea,.creator-profile-modal input[type=file]{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid rgba(255,255,255,.13);border-radius:8px;background:#160f13;color:#fff;font:inherit;font-size:13px}
.creator-profile-modal input[type=file]{font-size:11px}
.creator-profile-modal textarea{resize:vertical;min-height:86px}
.creator-profile-modal label small{color:#a9949d;font-size:10px;font-weight:400}
.creator-profile-modal-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:8px}
.creator-profile-save-notice{margin:0 0 18px;padding:11px 14px;border:1px solid rgba(91,190,133,.3);border-radius:9px;background:rgba(56,145,96,.12);color:#b7ebc9;font-size:12px}
body.profile-modal-open{overflow:hidden}
@media(max-width:620px){.creator-profile-group.creator-profile-card{padding:16px}.creator-profile-modal-dialog{padding:18px}.creator-profile-modal-actions{flex-wrap:wrap}}
</style>
</body></html>
