<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$profileStmt = $pdo->prepare('SELECT full_name,nickname,email,mobile_no,lastname,firstname,middlename,suffix FROM users WHERE id = ? LIMIT 1');
$profileStmt->execute([(int)$admin['id']]);
$adminProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$adminNickname = trim((string)($adminProfile['nickname'] ?? '')) ?: 'Administrator';
$message = '';
$section = (string)($_GET['section'] ?? 'dashboard');
$allowedSections = ['dashboard', 'settings', 'page-management', 'media-management', 'plugins', 'user-accounts'];
if (!in_array($section, $allowedSections, true)) $section = 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'update_admin_profile') {
        $last = trim((string)($_POST['lastname'] ?? ''));
        $first = trim((string)($_POST['firstname'] ?? ''));
        $middleInitial = trim((string)($_POST['middle_initial'] ?? ''));
        $suffix = trim((string)($_POST['suffix'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $mobile = preg_replace('/[\\s().-]/', '', trim((string)($_POST['mobile_no'] ?? ''))) ?? '';
        $password = (string)($_POST['new_password'] ?? '');
        $fullName = implode(', ', array_values(array_filter([$last, $first, $middleInitial, $suffix], static fn(string $v): bool => $v !== '')));
        $passwordValid = strlen($password) >= 10 && preg_match('/[A-Z]/', $password) && preg_match('/[0-9]/', $password) && preg_match('/[^A-Za-z0-9]/', $password);
        if ($last === '' || $first === '' || $nickname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
            strlen($last)>80 || strlen($first)>80 || strlen($middleInitial)>10 || strlen($suffix)>20 || strlen($nickname)>80 ||
            ($mobile !== '' && !preg_match('/^\\+639[0-9]{9}$/', $mobile)) ||
            ($password !== '' && !$passwordValid)) {
            $message = 'Please check the required profile fields, Philippine mobile number, and password requirements.';
        } else {
            $duplicateEmail = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
            $duplicateEmail->execute([$email, (int)$admin['id']]);
            if ($duplicateEmail->fetchColumn()) {
                $message = 'That email address is already in use by another account.';
            } else {
                $sql = 'UPDATE users SET full_name=?,lastname=?,firstname=?,middlename=?,suffix=?,email=?,nickname=?,mobile_no=?';
                $values = [$fullName,$last,$first,$middleInitial,$suffix,$email,$nickname,$mobile !== '' ? $mobile : null];
                if ($password !== '') { $sql .= ',password_hash=?'; $values[] = password_hash($password, PASSWORD_DEFAULT); }
                $sql .= ' WHERE id=? AND role=\'system_admin\'';
                $values[] = (int)$admin['id'];
                $profileUpdate = $pdo->prepare($sql);
                $profileUpdate->execute($values);
                $passwordWasChanged = $password !== '';
                if ($passwordWasChanged) {
                    $_SESSION = [];
                    if (ini_get('session.use_cookies')) {
                        $cookie = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
                    }
                    session_destroy();
                    header('Location: ../index.php?profile_password_updated=1#sign-in-modal');
                    exit;
                }
                $adminProfile = array_merge($adminProfile, ['full_name'=>$fullName,'lastname'=>$last,'firstname'=>$first,'middlename'=>$middleInitial,'suffix'=>$suffix,'email'=>$email,'nickname'=>$nickname,'mobile_no'=>$mobile]);
                $adminNickname = $nickname;
                $message = 'Account successfully updated';
            }
        }
    }
    if ($action === 'delete_selected_users') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_users'] ?? [])), static fn(int $v): bool => $v > 0)));
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $delete = $pdo->prepare("DELETE FROM users WHERE id IN ($marks) AND role<>'system_admin'");
            $delete->execute($ids);
            $message = $delete->rowCount() . ' user account(s) deleted.';
        } else $message = 'Select at least one account to delete.';
        $section = 'user-accounts';
    }
    if ($action === 'toggle_user_status') {
        $next = (string)($_POST['next_status'] ?? '') === 'approved' ? 'approved' : 'suspended';
        // Account access can only be changed after the email address has been verified.
        $s = $pdo->prepare("UPDATE users SET account_status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND role<>'system_admin' AND email_verified_at IS NOT NULL");
        $s->execute([$next, $admin['id'], $id]);
        $message = $s->rowCount() ? 'Account status updated.' : 'This account must verify its email before its status can be changed.';
        $section = 'user-accounts';
    }
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
$activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role<>'system_admin' AND account_status='approved'")->fetchColumn();
$managedUsers = $pdo->query("SELECT id,full_name,email,credential_notes,created_at,updated_at,account_status,email_verified_at,nickname,birthdate,birthplace,mobile_no,lastname,firstname,middlename,suffix FROM users WHERE role<>'system_admin' ORDER BY created_at DESC")->fetchAll();
$pendingContent = $pdo->query("SELECT c.id,c.title,c.content_type,c.created_at,u.full_name,cat.name category_name FROM content c JOIN users u ON u.id=c.owner_id JOIN categories cat ON cat.id=c.category_id WHERE c.status='pending_review' ORDER BY c.created_at")->fetchAll();
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role<>'system_admin'")->fetchColumn();
$publishedContent = (int)$pdo->query("SELECT COUNT(*) FROM content WHERE status='published'")->fetchColumn();
function navActive(string $current, string $target): string { return $current === $target ? ' active' : ''; }
function adminUrl(string $section): string { return 'admin.php?section=' . rawurlencode($section); }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Administrator | Z.C. Commonplace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
<style>
/* Keep the entire System Administrator interface in Ubuntu. */
.admin-shell,.admin-shell *,.admin-shell *::before,.admin-shell *::after,
body:has(.admin-shell),body:has(.admin-shell) *{
  font-family:'Ubuntu',sans-serif !important;
}
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
.admin-panel .users-table{min-width:1040px}.user-full-name{font-weight:800;color:#fff}.user-subdetails{display:block;margin-top:7px;color:var(--muted);font-size:11px;line-height:1.7}.user-select-cell{width:42px;text-align:center}.user-toggle{position:relative;display:inline-flex;align-items:center;justify-content:flex-start;gap:9px;min-width:112px;height:36px;padding:4px 11px 4px 5px;border-radius:999px;border:1px solid rgba(70,180,125,.48);background:rgba(35,130,82,.18);color:#9de0b8;font-size:12px;font-weight:800;cursor:pointer;transition:background .2s,border-color .2s}.user-toggle .toggle-knob{width:26px;height:26px;flex:0 0 26px;border-radius:50%;background:#62c98d;box-shadow:0 1px 5px rgba(0,0,0,.35);transition:transform .2s,background .2s}.user-toggle.inactive{justify-content:flex-end;padding:4px 5px 4px 11px;border-color:rgba(220,85,100,.48);background:rgba(150,35,55,.2);color:#ff9eaa}.user-toggle.inactive .toggle-knob{order:2;background:#e66b7d}.user-toggle:focus-visible{outline:2px solid #e7b7c1;outline-offset:3px}.bulk-delete{background:#8f2034;border-color:#a62b42;color:#fff}.user-actions{display:flex;gap:7px;align-items:center}.pending-email-verification{display:inline-block;padding:7px 10px;border:1px solid rgba(220,165,70,.38);border-radius:999px;background:rgba(150,105,30,.13);color:#f0ca83;font-size:11px;font-weight:700;white-space:nowrap}.admin-note{padding:14px 16px;border:1px solid rgba(184,50,63,.25);border-radius:12px;color:#d7c9cc;background:rgba(105,8,24,.12);font-size:13px}
.admin-profile-card{margin:26px 5px 0;padding:18px 15px;border:1px solid rgba(184,50,63,.32);border-radius:15px;background:linear-gradient(145deg,rgba(128,20,39,.2),rgba(255,255,255,.025));box-shadow:0 12px 30px rgba(0,0,0,.18)}
.admin-profile-kicker{display:block;margin-bottom:10px;color:#bbaeb2;font-size:10px;letter-spacing:1.4px;text-transform:uppercase}
.admin-profile-name{display:block;margin:2px 0 5px;color:#fff;font-size:16px;font-weight:700;overflow-wrap:anywhere}
.admin-profile-role{display:block;color:#c8bec1;font-size:11px}
.admin-profile-edit{width:100%;margin-top:15px;justify-content:center;font-size:12px!important}
.profile-modal{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(0,0,0,.78);backdrop-filter:blur(7px);overflow:hidden}
.profile-modal[hidden]{display:none}
.profile-modal-card{box-sizing:border-box;width:min(680px,100%);max-height:calc(100dvh - 24px);overflow:hidden;padding:clamp(12px,2.2vh,20px) clamp(14px,2.5vw,22px);border:1px solid rgba(184,50,63,.45);border-radius:16px;background:linear-gradient(145deg,#211319,#100d10);box-shadow:0 25px 90px rgba(0,0,0,.65)}
.profile-modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:clamp(9px,1.5vh,14px)}
.profile-modal-head h2{margin:0 0 4px;font-size:18px;color:#fff}
.profile-modal-head p{margin:0;color:var(--muted);font-size:11px}
.profile-modal-close{flex:0 0 32px;width:32px;height:32px;display:grid;place-items:center;box-sizing:border-box;padding:0;border:1px solid var(--line);border-radius:9px;background:rgba(255,255,255,.04);color:#fff;font-size:20px;line-height:1;cursor:pointer}
.profile-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px 12px}
.profile-form-field{display:grid;gap:4px;min-width:0}
.profile-form-field.full{grid-column:1/-1}
.profile-form-field label{color:#d9cdd1;font-size:11px}
.profile-form-field input{width:100%;min-width:0;box-sizing:border-box;padding:8px 10px;border:1px solid rgba(255,255,255,.14);border-radius:8px;background:#171115;color:#fff;font:12px Ubuntu,sans-serif}
.profile-form-field input:focus{outline:2px solid rgba(184,50,63,.5);border-color:#a83248}
.profile-form-field input[readonly]{color:#a99da1;background:#120f11}
.profile-password-input-wrap{position:relative;display:flex;align-items:center}
.profile-password-input-wrap input{padding-right:42px}
.profile-password-toggle,.profile-password-toggle:hover,.profile-password-toggle:focus,.profile-password-toggle:focus-visible,.profile-password-toggle:active{position:absolute;right:7px;top:50%;transform:translateY(-50%) !important;translate:0 0 !important;scale:1 !important;display:grid;place-items:center;box-sizing:border-box;width:30px;height:30px;min-width:30px;min-height:30px;padding:5px;border:0;border-radius:6px;background:transparent;color:#bbaeb2;cursor:pointer;transition:none !important}
.profile-password-toggle:hover,.profile-password-toggle:focus-visible{transform:translateY(-50%) !important;translate:0 0 !important;scale:1 !important;color:#fff;background:rgba(255,255,255,.08);outline:1px solid rgba(184,50,63,.5)}
.profile-password-toggle svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.profile-modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:12px}
@media(max-height:720px){.profile-modal-card{padding:10px 16px}.profile-modal-head{margin-bottom:8px}.profile-form-grid{gap:6px 10px}.profile-form-field input{padding:7px 9px}.profile-password-meter{margin-top:4px}.profile-password-rules{margin-top:5px;gap:3px 8px}.profile-password-help{margin:4px 0;font-size:10px}.profile-modal-actions{margin-top:8px}}
@media(max-width:420px){.profile-modal{padding:7px}.profile-modal-card{max-height:calc(100dvh - 14px);padding:10px}.profile-form-grid{gap:7px}.profile-form-field input{padding:7px 6px;font-size:11px}.profile-form-field label{font-size:10px}.profile-password-rules{font-size:10px}}
.profile-password-meter{margin-top:9px}
.profile-password-track{height:7px;overflow:hidden;border-radius:99px;background:rgba(255,255,255,.12)}
.profile-password-fill{display:block;width:0;height:100%;border-radius:99px;background:#d94a62;transition:width .2s,background .2s}
.profile-password-status{display:flex;justify-content:space-between;gap:8px;margin-top:6px;color:#bbaeb2;font-size:11px}
.profile-password-rules{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;margin-top:10px;color:#a99da1;font-size:11px}
.profile-password-rules span::before{content:'○';margin-right:6px}
.profile-password-rules span.valid{color:#83dba6}
.profile-password-rules span.valid::before{content:'✓';color:#83dba6}
.profile-password-help{color:var(--muted);font-size:11px;line-height:1.5}

/* Keep the profile form in a compact two-column layout so the complete modal fits without scrolling. */
.account-status-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.76);backdrop-filter:blur(5px)}
.account-status-modal-card{width:min(420px,100%);padding:32px 26px;text-align:center;border:1px solid rgba(184,50,63,.42);border-radius:20px;background:linear-gradient(145deg,#211319,#100d10);box-shadow:0 24px 80px rgba(0,0,0,.6)}
.account-status-modal-icon{width:52px;height:52px;margin:0 auto 16px;display:grid;place-items:center;border-radius:50%;background:rgba(54,164,105,.16);border:1px solid rgba(91,205,145,.45);color:#83e0ac;font-size:27px;font-weight:800}
.account-status-modal-card h2{margin:0 0 22px;color:#fff;font:24px Georgia,serif}
.account-status-modal-card .btn{min-width:110px;justify-content:center}
/* Administrator typography scale */
.admin-main .admin-heading{font-family:'Ubuntu',sans-serif;font-size:18px;line-height:1.35;letter-spacing:0;margin:8px 0 8px;font-weight:700}
.admin-main .admin-topline p,.admin-main .admin-topline .muted{font-size:13px}
.admin-panel h2,.admin-feature h3,.account-status-modal-card h2{font-family:'Ubuntu',sans-serif;font-size:14px;line-height:1.4;font-weight:700}
.admin-panel-head .muted,.admin-panel-head .status,.admin-feature p,.admin-stat span{font-size:12px}
.admin-main .admin-label,.users-table-controls,.users-table-controls label,.users-pagination,.users-pagination #users-page-info,.users-pagination #users-page-number{font-size:12px}
.admin-panel .table thead th{font-size:12px}
.admin-panel .table tbody td{font-size:11px}
.admin-main button.btn,.admin-main a.btn,.navlinks a.btn{font-size:13px}
.admin-panel .table button,.admin-panel .table .btn,.admin-panel .table a.btn,.admin-panel .user-toggle,.admin-panel .user-actions button{font-size:12px}
.users-table-controls select,.users-table-controls input{font-size:12px}
.pending-email-verification{font-size:12px}
/* Collapsible Settings menu — match the Dashboard and User Accounts links */
.admin-nav button.settings-nav{
  display:flex;align-items:center;gap:12px;width:100%;
  padding:12px 13px;border:1px solid transparent;border-radius:12px;
  background:transparent;color:#c8bec1;font-family:inherit;font-size:13px;
  font-weight:400;line-height:normal;text-align:left;cursor:pointer;
  appearance:none;-webkit-appearance:none;
  transition:background .2s ease,border-color .2s ease,color .2s ease;
}
.admin-nav button.settings-nav:hover{background:rgba(255,255,255,.035);color:#fff}
.admin-nav button.settings-nav.active{
  background:linear-gradient(100deg,rgba(128,20,39,.42),rgba(128,20,39,.08));
  border-color:rgba(184,50,63,.35);color:#fff
}
.admin-nav .settings-menu-row{position:relative;display:flex;align-items:center;width:100%;min-height:46px}
.admin-nav .settings-menu-row>a.settings-nav{flex:1;min-width:0;padding-right:44px}
.admin-nav .settings-toggle{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:30px;height:30px;display:grid;place-items:center;padding:0;border:0;border-radius:8px;background:transparent;color:#c8bec1;cursor:pointer;z-index:2;transition:background .2s,color .2s}
.admin-nav .settings-toggle:hover{background:rgba(255,255,255,.08);color:#fff}
.admin-nav .settings-chevron{display:block;width:9px;height:9px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-2px) rotate(45deg);transition:transform .25s ease}
.admin-nav .settings-toggle[aria-expanded="true"] .settings-chevron{transform:translateY(2px) rotate(225deg)}
.admin-nav .settings-nav-label{flex:1}
.admin-subnav.settings-subnav{display:grid;overflow:hidden;max-height:0;opacity:0;transform:translateY(-5px);margin-top:0;margin-bottom:0;transition:max-height .28s ease,opacity .22s ease,transform .22s ease,margin .22s ease}
.admin-subnav.settings-subnav.is-open{max-height:180px;opacity:1;transform:translateY(0);margin-top:7px;margin-bottom:10px}
@media(prefers-reduced-motion:reduce){.admin-subnav.settings-subnav,.admin-nav .settings-chevron{transition:none}}
.admin-settings-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 24px;padding-bottom:12px;border-bottom:1px solid var(--line)}
.admin-settings-tabs a{padding:10px 15px;border:1px solid var(--line);border-radius:10px;color:#c8bec1;font-size:13px}
.admin-settings-tabs a:hover{background:rgba(255,255,255,.04);color:#fff}
.admin-settings-tabs a.active{color:#fff;background:linear-gradient(100deg,rgba(128,20,39,.42),rgba(128,20,39,.08));border-color:rgba(184,50,63,.4)}
@media(max-width:850px){.admin-shell{grid-template-columns:1fr}.admin-sidebar{position:relative;top:auto;height:auto;border-right:0;border-bottom:1px solid var(--line);padding:16px}.admin-nav{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-subnav{grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));margin:0 0 8px 0;padding:8px 0 0;border-left:0;border-top:1px solid var(--line)}.admin-main{padding-top:25px}}
@media(max-width:560px){.admin-stats,.admin-feature-grid{grid-template-columns:1fr}.admin-nav{grid-template-columns:1fr}.admin-subnav{grid-template-columns:1fr}.admin-topline .btn{width:100%}}
</style></head><body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php">Public website</a><a class="btn light" href="logout.php?scope=admin">Sign out</a></nav></div></header>
<div class="admin-shell">
<aside class="admin-sidebar">
  <div class="admin-label">Administration</div>
  <nav class="admin-nav" aria-label="Administrator menu">
    <a class="<?=navActive($section,'dashboard')?>" href="<?=e(adminUrl('dashboard'))?>"><span class="nav-icon">⌂</span>Dashboard</a>
    <div class="settings-menu-group">
      <div class="settings-menu-row">
        <a class="settings-nav<?=in_array($section,['settings','page-management','media-management','plugins'],true)?' active':''?>" href="<?=e(adminUrl('settings'))?>"><span class="nav-icon">⚙</span><span class="settings-nav-label">Settings</span></a>
        <button class="settings-toggle" id="settings-menu-toggle" type="button" aria-label="Toggle Settings submenu" aria-expanded="<?=in_array($section,['settings','page-management','media-management','plugins'],true)?'true':'false'?>" aria-controls="settings-submenu"><span class="settings-chevron" aria-hidden="true"></span></button>
      </div>
      <div class="admin-subnav settings-subnav<?=in_array($section,['settings','page-management','media-management','plugins'],true)?' is-open':''?>" id="settings-submenu">
        <a class="<?=navActive($section,'page-management')?>" href="<?=e(adminUrl('page-management'))?>">Page Management</a>
        <a class="<?=navActive($section,'media-management')?>" href="<?=e(adminUrl('media-management'))?>">Media Management</a>
        <a class="<?=navActive($section,'plugins')?>" href="<?=e(adminUrl('plugins'))?>">Plugins</a>
      </div>
    </div>
    <a class="<?=navActive($section,'user-accounts')?>" href="<?=e(adminUrl('user-accounts'))?>"><span class="nav-icon">♙</span>User Accounts </a>
  </nav>
  <section class="admin-profile-card" aria-label="Administrator profile">
    <span class="admin-profile-kicker">Signed in as</span>
    <strong class="admin-profile-name"><?=e($adminNickname)?></strong>
    <span class="admin-profile-role">System Administrator</span>
    <button type="button" class="btn light admin-profile-edit" id="open-profile-modal">Update my profile</button>
  </section>
</aside>
<div class="profile-modal" id="profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title" hidden>
  <div class="profile-modal-card">
    <div class="profile-modal-head"><div><h2 id="profile-modal-title">Update my profile</h2><p>Keep your administrator details up to date.</p></div><button type="button" class="profile-modal-close" id="close-profile-modal" aria-label="Close">×</button></div>
    <form method="post" id="profile-update-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="update_admin_profile">
      <div class="profile-form-grid">
        <div class="profile-form-field"><label for="profile-lastname">Lastname *</label><input id="profile-lastname" name="lastname" type="text" maxlength="80" required value="<?=e($adminProfile['lastname'] ?? '')?>"></div>
        <div class="profile-form-field"><label for="profile-firstname">Firstname *</label><input id="profile-firstname" name="firstname" type="text" maxlength="80" required value="<?=e($adminProfile['firstname'] ?? '')?>"></div>
        <div class="profile-form-field"><label for="profile-middle">Middle Initial</label><input id="profile-middle" name="middle_initial" type="text" maxlength="10" value="<?=e($adminProfile['middlename'] ?? '')?>"></div>
        <div class="profile-form-field"><label for="profile-suffix">Suffix</label><input id="profile-suffix" name="suffix" type="text" maxlength="20" value="<?=e($adminProfile['suffix'] ?? '')?>"></div>
        <div class="profile-form-field full"><label for="profile-email">Email Address *</label><input id="profile-email" name="email" type="email" maxlength="190" required value="<?=e($adminProfile['email'] ?? '')?>"></div>
        <div class="profile-form-field"><label for="profile-nickname">Nickname *</label><input id="profile-nickname" name="nickname" type="text" maxlength="80" required value="<?=e($adminProfile['nickname'] ?? '')?>"></div>
        <div class="profile-form-field"><label for="profile-mobile">Contact/Mobile No. (+63)</label><input id="profile-mobile" name="mobile_no" type="tel" maxlength="20" placeholder="+63 9XX XXXX XXX" value="<?=e($adminProfile['mobile_no'] ?? '')?>"></div>
        <div class="profile-form-field full"><label for="profile-password">New Password</label><div class="profile-password-input-wrap"><input id="profile-password" name="new_password" type="password" minlength="10" autocomplete="new-password" placeholder="Leave blank to keep current password"><button type="button" class="profile-password-toggle" id="profile-password-toggle" aria-label="Show password" aria-pressed="false" title="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><div class="profile-password-meter"><div class="profile-password-track"><span class="profile-password-fill" id="profile-password-fill"></span></div><div class="profile-password-status"><span id="profile-password-label">Password strength: Not entered</span><span id="profile-password-percent">0%</span></div></div><div class="profile-password-rules" id="profile-password-rules"><span data-rule="length">At least 10 characters</span><span data-rule="uppercase">At least 1 uppercase</span><span data-rule="number">At least 1 number</span><span data-rule="symbol">At least 1 symbol</span></div><p class="profile-password-help">Your password must meet all four requirements. Leave this field blank if you do not want to change it.</p></div>
      </div>
      <div class="profile-modal-actions"><button type="submit" class="btn">Save changes</button></div>
    </form>
  </div>
</div>
<main class="admin-main">
<?php if($message === 'Account status updated.'):?>
<div class="account-status-modal" id="account-status-modal" role="dialog" aria-modal="true" aria-labelledby="account-status-modal-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="account-status-modal-title">Account status updated.</h2>
    <button type="button" class="btn" id="account-status-modal-close">OK</button>
  </div>
</div>
<?php elseif($message):?><div class="panel" style="margin-bottom:20px"><?=e($message)?></div><?php endif;?>
<?php if($section==='dashboard'):?>
  <div class="admin-topline"><div><div class="eyebrow">System control</div><h1 class="admin-heading">Dashboard</h1><p class="muted">A clear overview of your website, accounts, and content activity.</p></div><a class="btn" href="<?=e(adminUrl('user-accounts'))?>">Manage accounts <span aria-hidden="true">→</span></a></div>
  <div class="admin-stats"><div class="admin-stat"><span>Contributor accounts</span><strong><?=$totalUsers?></strong></div><div class="admin-stat"><span>Active accounts</span><strong><?=$activeUsers?></strong></div><div class="admin-stat"><span>Published content</span><strong><?=$publishedContent?></strong></div></div>
  <section class="panel admin-panel"><div class="admin-panel-head"><h2>Content moderation</h2><span class="status"><?=count($pendingContent)?> awaiting review</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Content</th><th>Creator</th><th>Category</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>
  <?php foreach($pendingContent as $c):?><tr><td><?=e($c['title'])?><br><small><?=e($c['content_type'])?></small></td><td><?=e($c['full_name'])?></td><td><?=e($c['category_name'])?></td><td><?=e($c['created_at'])?></td><td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn" name="action" value="publish">Publish</button><button class="btn light" name="action" value="reject_content">Reject</button></form></td></tr><?php endforeach;?>
  <?php if(!$pendingContent):?><tr><td colspan="5">No content awaiting review.</td></tr><?php endif;?></tbody></table></div></section>
  <div class="admin-feature-grid"><div class="admin-feature"><div class="symbol">▤</div><h3>Page Management</h3><p>Manage the pages and sections displayed on the public website.</p><a class="btn light" href="<?=e(adminUrl('page-management'))?>">Open settings</a></div><div class="admin-feature"><div class="symbol">▧</div><h3>Media Management</h3><p>Organize the images and media used throughout the website.</p><a class="btn light" href="<?=e(adminUrl('media-management'))?>">Open settings</a></div></div>
<?php elseif($section==='user-accounts'):?>
  <div class="admin-topline"><div><div class="eyebrow">Access control</div><h1 class="admin-heading">User Accounts</h1><p class="muted">Manage registered accounts, contributor credentials and account access.</p></div></div>
  <section class="panel admin-panel"><div class="admin-panel-head"><h2>Registered User Accounts</h2><span class="muted" id="total-account-count"><?=count($managedUsers)?> Total <?=count($managedUsers)===1?'Account':'Accounts'?></span></div>
  <form id="bulk-users" method="post" hidden><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_selected_users"></form>
  <div class="admin-panel-head" style="margin:0 0 14px;justify-content:flex-end"><button class="btn bulk-delete" id="open-bulk-delete-confirm" type="button">Delete Selected</button></div>
  <div class="users-table-controls"><label>Show <select id="users-page-size" aria-label="Accounts per page"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label><label><input id="users-filter" type="search" placeholder="Search registered accounts..." aria-label="Search registered accounts"></label></div><div class="users-pagination"><span id="users-page-info" class="muted" aria-live="polite"></span><div class="users-page-buttons"><button type="button" class="btn light" id="users-prev">Previous</button><span id="users-page-number" class="muted"></span><button type="button" class="btn light" id="users-next">Next</button></div></div><div class="table-wrap"><table class="table users-table"><thead><tr><th class="user-select-cell"><input type="checkbox" id="select-all-users" aria-label="Select all user accounts"></th><th>Full Name</th><th>Email Address</th><th>Credential Notes</th><th>Date Created</th><th>Last Update</th><th>Action</th></tr></thead><tbody>
  <?php foreach($managedUsers as $u):
    $last = trim((string)($u['lastname'] ?? '')); $first = trim((string)($u['firstname'] ?? '')); $middle = trim((string)($u['middlename'] ?? '')); $suffix = trim((string)($u['suffix'] ?? ''));
    $middleInitial = $middle !== '' ? mb_strtoupper(mb_substr($middle,0,1,'UTF-8'),'UTF-8').'.' : '';
    $displayName = ($last !== '' || $first !== '') ? $last . ($first !== '' ? ', '.$first : '') . ($suffix !== '' ? ' '.$suffix : '') . ($middleInitial !== '' ? ', '.$middleInitial : '') : (string)$u['full_name'];
    $active = $u['account_status'] === 'approved';
  ?><tr>
  <td class="user-select-cell"><input type="checkbox" class="user-select" name="selected_users[]" value="<?=(int)$u['id']?>" form="bulk-users" aria-label="Select <?=e($displayName)?>"></td>
  <td><strong class="user-full-name"><?=e($displayName)?></strong><span class="user-subdetails">Nickname: <?=e($u['nickname'] ?? '—')?> · Birthdate: <?=e($u['birthdate'] ?? '—')?> · Birthplace: <?=e($u['birthplace'] ?? '—')?> · Contact: <?=e($u['mobile_no'] ?? '—')?></span></td>
  <td><?=e($u['email'])?></td><td class="credential-cell"><?=nl2br(e($u['credential_notes'] ?? '—'))?></td><td><?=e($u['created_at'])?></td><td><?=e($u['updated_at'])?></td>
  <td><?php if (empty($u['email_verified_at'])): ?><span class="pending-email-verification">Pending Email Verification</span><?php else: ?><form method="post" class="user-actions"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input type="hidden" name="next_status" value="<?=$active?'suspended':'approved'?>"><button class="user-toggle<?=$active?'':' inactive'?>" name="action" value="toggle_user_status" type="submit" role="switch" aria-checked="<?=$active?'true':'false'?>" aria-label="Account status: <?=$active?'Active':'Inactive'?>"><span class="toggle-knob" aria-hidden="true"></span><span><?=$active?'Active':'Inactive'?></span></button></form><?php endif; ?></td>
  </tr><?php endforeach;?>
  <?php if(!$managedUsers):?><tr><td colspan="7">No user accounts found.</td></tr><?php endif;?></tbody></table></div></section>
  <div class="bulk-delete-modal" id="bulk-delete-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-delete-title" hidden><div class="bulk-delete-dialog"><h2 id="bulk-delete-title">Delete selected accounts?</h2><p>This action cannot be undone. Do you want to permanently delete the selected user account records?</p><div class="bulk-delete-dialog-actions"><button type="button" class="btn light" id="cancel-bulk-delete">Cancel</button><button type="button" class="btn bulk-delete" id="confirm-bulk-delete">Delete</button></div></div></div>
  <div class="bulk-delete-loading" id="bulk-delete-loading" role="status" aria-live="assertive" hidden><div class="bulk-delete-loading-card"><span class="bulk-delete-spinner" aria-hidden="true"></span><strong>Deleting record(s)...</strong><span>Please wait while the selected accounts are deleted.</span></div></div>
  <style>
  .bulk-delete-modal,.bulk-delete-loading{position:fixed;inset:0;z-index:10020;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.78);backdrop-filter:blur(6px)}.bulk-delete-modal[hidden],.bulk-delete-loading[hidden]{display:none}.bulk-delete-dialog,.bulk-delete-loading-card{width:min(440px,100%);padding:28px 24px;text-align:center;border:1px solid rgba(184,50,63,.48);border-radius:18px;background:linear-gradient(145deg,#211319,#100d10);box-shadow:0 24px 80px rgba(0,0,0,.65)}.bulk-delete-dialog h2{margin:0 0 10px;color:#fff;font-size:19px}.bulk-delete-dialog p{margin:0;color:#c8bec1;font-size:13px;line-height:1.6}.bulk-delete-dialog-actions{display:flex;justify-content:center;gap:10px;margin-top:22px}.bulk-delete-dialog-actions .btn{min-width:105px;justify-content:center}.bulk-delete-loading-card{display:grid;justify-items:center;gap:12px;color:#fff}.bulk-delete-loading-card>span:last-child{color:#c8bec1;font-size:12px}.bulk-delete-spinner{width:38px;height:38px;border:4px solid rgba(255,255,255,.18);border-top-color:#e15b70;border-radius:50%;animation:bulk-delete-spin .75s linear infinite}@keyframes bulk-delete-spin{to{transform:rotate(360deg)}}
  .users-table-controls{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin:4px 0 14px;color:var(--muted);font-size:12px}.users-table-controls label{display:flex;align-items:center;gap:9px}.users-table-controls select,.users-table-controls input{background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit}.users-table-controls input{width:min(280px,65vw)}.users-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0 22px}.users-page-buttons{display:flex;align-items:center;gap:8px}.users-page-buttons .btn{padding:8px 12px;font-size:12px}.users-page-buttons .btn:disabled{opacity:.4;cursor:not-allowed}
  </style>
  <script>
  (()=>{const form=document.getElementById('bulk-users');const open=document.getElementById('open-bulk-delete-confirm');const modal=document.getElementById('bulk-delete-modal');const cancel=document.getElementById('cancel-bulk-delete');const confirm=document.getElementById('confirm-bulk-delete');const loading=document.getElementById('bulk-delete-loading');if(!form||!open||!modal||!confirm||!loading)return;const selected=()=>document.querySelectorAll('.users-table .user-select:checked').length;open.addEventListener('click',()=>{if(!selected()){alert('Select at least one account to delete.');return;}modal.hidden=false;cancel?.focus();});const close=()=>{modal.hidden=true;open.focus();};cancel?.addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close();});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});confirm.addEventListener('click',()=>{if(!selected()){close();return;}confirm.disabled=true;if(cancel)cancel.disabled=true;modal.hidden=true;loading.hidden=false;document.body.style.overflow='hidden';form.submit();});})();
  (()=>{const all=document.getElementById('select-all-users');const table=document.querySelector('.users-table');if(!table)return;const rows=[...table.tBodies[0].rows].filter(row=>row.querySelector('.user-select'));const filter=document.getElementById('users-filter');const size=document.getElementById('users-page-size');const info=document.getElementById('users-page-info');const number=document.getElementById('users-page-number');const prev=document.getElementById('users-prev');const next=document.getElementById('users-next');let page=1;function matches(){const q=filter.value.trim().toLocaleLowerCase();return rows.filter(row=>row.textContent.toLocaleLowerCase().includes(q));}function render(){const found=matches();const perPage=Number(size.value);const pages=Math.max(1,Math.ceil(found.length/perPage));page=Math.min(page,pages);const start=found.length?(page-1)*perPage:0;const end=Math.min(start+perPage,found.length);const visible=found.slice(start,end);const visibleSet=new Set(visible);rows.forEach(row=>row.hidden=!visibleSet.has(row));info.textContent=found.length?'Showing '+(start+1)+' to '+end+' of '+found.length+' '+(found.length===1?'account':'accounts'):'Showing 0 accounts';number.textContent='Page '+page+' of '+pages;prev.disabled=page<=1;next.disabled=page>=pages;if(all){const checks=visible.map(row=>row.querySelector('.user-select'));all.checked=checks.length>0&&checks.every(box=>box.checked);all.indeterminate=checks.some(box=>box.checked)&&!all.checked;}}filter.addEventListener('input',()=>{page=1;render();});size.addEventListener('change',()=>{page=1;render();});prev.addEventListener('click',()=>{if(page>1){page--;render();}});next.addEventListener('click',()=>{page++;render();});if(all)all.addEventListener('change',()=>{matches().slice((page-1)*Number(size.value),page*Number(size.value)).forEach(row=>row.querySelector('.user-select').checked=all.checked);render();});rows.forEach(row=>row.querySelector('.user-select').addEventListener('change',render));render();})();
  </script>
<?php else: $settings = ['page-management'=>['Page Management','Manage the website pages and their publication status.','▤'],'media-management'=>['Media Management','Manage image and media assets used by public pages and contributor content.','▧'],'plugins'=>['Plugins','Review and manage optional website extensions.','⌘']]; ?>
  <div class="admin-topline"><div><div class="eyebrow">System configuration</div><h1 class="admin-heading">Settings</h1><p class="muted">Manage website pages, media assets, and optional extensions.</p></div></div>
  <nav class="admin-settings-tabs" aria-label="Settings tabs">
    <a class="<?=navActive($section,'settings')?>" href="<?=e(adminUrl('settings'))?>">Overview</a>
    <a class="<?=navActive($section,'page-management')?>" href="<?=e(adminUrl('page-management'))?>">Page Management</a>
    <a class="<?=navActive($section,'media-management')?>" href="<?=e(adminUrl('media-management'))?>">Media Management</a>
    <a class="<?=navActive($section,'plugins')?>" href="<?=e(adminUrl('plugins'))?>">Plugins</a>
  </nav>
  <?php if($section==='settings'): ?>
    <div class="admin-feature-grid">
      <div class="admin-feature"><div class="symbol">▤</div><h3>Page Management</h3><p>Manage the pages and sections displayed on the public website.</p><a class="btn light" href="<?=e(adminUrl('page-management'))?>">Open tab</a></div>
      <div class="admin-feature"><div class="symbol">▧</div><h3>Media Management</h3><p>Organize images and media used throughout the website.</p><a class="btn light" href="<?=e(adminUrl('media-management'))?>">Open tab</a></div>
      <div class="admin-feature"><div class="symbol">⌘</div><h3>Plugins</h3><p>Review and manage optional website extensions.</p><a class="btn light" href="<?=e(adminUrl('plugins'))?>">Open tab</a></div>
    </div>
  <?php else: $info=$settings[$section]; ?>
    <section class="panel admin-panel"><div class="symbol" style="font-size:30px;color:var(--gold)"><?=e($info[2])?></div><h2 style="margin-top:12px"><?=e($info[0])?></h2><p class="muted"><?=e($info[1])?></p><div class="admin-note">This Settings tab is ready for its management controls. No existing records have been changed.</div></section>
  <?php endif; ?>
<?php endif;?>
<script>
(()=>{const password=document.getElementById('profile-password');if(!password)return;const fill=document.getElementById('profile-password-fill');const label=document.getElementById('profile-password-label');const percent=document.getElementById('profile-password-percent');const rules={length:v=>v.length>=10,uppercase:v=>/[A-Z]/.test(v),number:v=>/[0-9]/.test(v),symbol:v=>/[^A-Za-z0-9]/.test(v)};const update=()=>{const v=password.value;let score=0;Object.entries(rules).forEach(([key,test])=>{const ok=test(v);if(ok)score++;document.querySelector('[data-rule="'+key+'"]')?.classList.toggle('valid',ok);});const pct=Math.round(score/4*100);fill.style.width=pct+'%';fill.style.background=pct===100?'#55c987':pct>=50?'#d6a34b':'#d94a62';percent.textContent=pct+'%';label.textContent=!v?'Password strength: Not entered':pct===100?'Password strength: Strong':pct>=50?'Password strength: Moderate':'Password strength: Weak';};password.addEventListener('input',update);update();const passwordToggle=document.getElementById('profile-password-toggle');passwordToggle?.addEventListener('click',()=>{const reveal=password.type==='password';password.type=reveal?'text':'password';passwordToggle.setAttribute('aria-pressed',String(reveal));passwordToggle.setAttribute('aria-label',reveal?'Hide password':'Show password');passwordToggle.setAttribute('title',reveal?'Hide password':'Show password');passwordToggle.innerHTML=reveal?'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0112 5c6.4 0 10 7 10 7a15.7 15.7 0 01-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 004-.8"/></svg>':'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';});const mobile=document.getElementById('profile-mobile');mobile?.addEventListener('input',()=>{let digits=mobile.value.replace(/\\D/g,'');if(digits.startsWith('0063'))digits=digits.slice(4);else if(digits.startsWith('63'))digits=digits.slice(2);if(digits.startsWith('0'))digits=digits.slice(1);digits=digits.slice(0,10);const groups=[digits.slice(0,3),digits.slice(3,7),digits.slice(7,10)].filter(Boolean);mobile.value='+63'+(groups.length?' '+groups.join(' '):'');});const form=document.getElementById('profile-update-form');form?.addEventListener('submit',e=>{if(password.value&&!Object.values(rules).every(test=>test(password.value))){e.preventDefault();password.focus();update();}});})();
(()=>{const modal=document.getElementById('profile-modal');const open=document.getElementById('open-profile-modal');const close=document.getElementById('close-profile-modal');const cancel=document.getElementById('cancel-profile-modal');if(!modal||!open)return;const hide=()=>{modal.hidden=true;open.focus();};open.addEventListener('click',()=>{modal.hidden=false;document.getElementById('profile-full-name')?.focus();});close?.addEventListener('click',hide);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)hide();});})();
(()=>{const toggle=document.getElementById('settings-menu-toggle');const submenu=document.getElementById('settings-submenu');if(toggle&&submenu){toggle.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')==='true';toggle.setAttribute('aria-expanded',String(!open));submenu.classList.toggle('is-open',!open);});}})();
(()=>{const modal=document.getElementById('account-status-modal');if(!modal)return;const close=()=>modal.remove();document.getElementById('account-status-modal-close')?.addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close();});document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});document.getElementById('account-status-modal-close')?.focus();})();
</script>
</main></div></body></html>
