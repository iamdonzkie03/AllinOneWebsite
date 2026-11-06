<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$nicknameStmt = $pdo->prepare('SELECT nickname FROM users WHERE id = ? LIMIT 1');
$nicknameStmt->execute([(int)$admin['id']]);
$adminNickname = trim((string)($nicknameStmt->fetchColumn() ?: 'Administrator'));
$message = '';
// Persist public header configuration in a dedicated settings row.
$pdo->exec("CREATE TABLE IF NOT EXISTS site_header_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  header_title VARCHAR(160) NOT NULL DEFAULT 'Z.C. commonplace',
  logo_path VARCHAR(500) NULL,
  header_links LONGTEXT NULL,
  hidden_left VARCHAR(180) NULL,
  hidden_right VARCHAR(180) NULL,
  font_family VARCHAR(80) NOT NULL DEFAULT 'Ubuntu',
  font_style VARCHAR(20) NOT NULL DEFAULT 'normal',
  font_size TINYINT UNSIGNED NOT NULL DEFAULT 20,
  font_weight SMALLINT UNSIGNED NOT NULL DEFAULT 700,
  logo_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  logo_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
  title_html LONGTEXT NULL,
  hidden_left_html LONGTEXT NULL,
  hidden_right_html LONGTEXT NULL,
  typography_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Additive compatibility for databases created before independent header typography was introduced.
foreach (['title_html'=>'LONGTEXT NULL','hidden_left_html'=>'LONGTEXT NULL','hidden_right_html'=>'LONGTEXT NULL','typography_json'=>'LONGTEXT NULL'] as $column=>$definition) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='site_header_settings' AND COLUMN_NAME=?");
    $exists->execute([$column]);
    if (!(int)$exists->fetchColumn()) $pdo->exec("ALTER TABLE site_header_settings ADD COLUMN {$column} {$definition}");
}
function cleanHeaderRichHtml(string $html): string {
    $html = strip_tags($html, '<span><b><strong><i><em><u>');
    $html = preg_replace_callback('/<span\\b([^>]*)>/i', static function($m) {
        $style = '';
        if (preg_match('/style\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i', $m[1], $sm)) {
            $rules = [];
            foreach (explode(';', $sm[1]) as $rule) {
                if (!str_contains($rule, ':')) continue;
                [$key,$value] = array_map('trim', explode(':', $rule, 2));
                if ($key === 'color' && preg_match('/^#[0-9a-f]{3,8}$/i', $value)) $rules[] = 'color:'.$value;
                if ($key === 'font-size' && preg_match('/^(?:[8-9]|[1-3][0-9]|40)px$/', $value)) $rules[] = 'font-size:'.$value;
                if ($key === 'font-weight' && in_array($value, ['300','400','500','600','700','800','bold','normal'], true)) $rules[] = 'font-weight:'.$value;
                if ($key === 'font-style' && in_array($value, ['normal','italic'], true)) $rules[] = 'font-style:'.$value;
                if ($key === 'font-family' && in_array(trim($value), ['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'], true)) $rules[] = 'font-family:'.trim($value);
                if ($key === 'text-decoration' && in_array($value, ['none','underline'], true)) $rules[] = 'text-decoration:'.$value;
            }
            if ($rules) $style = ' style="'.implode(';',$rules).'"';
        }
        return '<span'.$style.'>';
    }, $html);
    $html = preg_replace('/<(b|strong|i|em|u)\\b[^>]*>/i', '<$1>', $html);
    return trim((string)$html);
}
function headerTypography(array $input, array $fallback=[]): array {
    $families=['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'];
    $family=(string)($input['family'] ?? $fallback['family'] ?? 'Ubuntu');
    $style=(string)($input['style'] ?? $fallback['style'] ?? 'normal');
    $size=max(8,min(40,(int)($input['size'] ?? $fallback['size'] ?? 16)));
    $weight=(int)($input['weight'] ?? $fallback['weight'] ?? 400);
    $color=(string)($input['color'] ?? $fallback['color'] ?? '#eadde1');
    $format=(string)($input['format'] ?? (($weight>=700)?'bold':(($style==='italic')?'italic':'normal')));
    $formatParts=array_values(array_intersect(['bold','italic','underline'],preg_split('/[ ,]+/',$format,-1,PREG_SPLIT_NO_EMPTY) ?: []));
    $format=implode(' ',$formatParts);
    return ['family'=>in_array($family,$families,true)?$family:'Ubuntu','style'=>in_array($style,['normal','italic'],true)?$style:'normal','size'=>$size,'weight'=>in_array($weight,[300,400,500,600,700,800],true)?$weight:400,'color'=>preg_match('/^#[0-9a-f]{6}$/i',$color)?$color:'#eadde1','format'=>$format];
}
$pdo->exec("INSERT IGNORE INTO site_header_settings (id,header_title,header_links,hidden_left,hidden_right) VALUES (1,'Z.C. commonplace','[]','ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY','Local perspectives. Shared with care.')");
$headerSettings = $pdo->query("SELECT * FROM site_header_settings WHERE id=1")->fetch() ?: [];
$headerLinks = json_decode((string)($headerSettings['header_links'] ?? '[]'), true);
if (!is_array($headerLinks)) $headerLinks = [];
$section = (string)($_GET['section'] ?? 'dashboard');
$allowedSections = ['dashboard', 'settings', 'page-management', 'media-management', 'plugins', 'user-accounts'];
if (!in_array($section, $allowedSections, true)) $section = 'dashboard';

/**
 * Publish the saved header row into the actual root index.php on GitHub main.
 * The token is read only from server-side environment/configuration and is never
 * sent to the browser or stored in the repository.
 */
function publishHeaderSettingsToGitHub(array $header): string {
    global $config;
    $token = trim((string)(getenv('ALLINONE_GITHUB_TOKEN') ?: ($config['github']['token'] ?? '')));
    if ($token === '') {
        throw new RuntimeException('GitHub publishing is not configured. Set ALLINONE_GITHUB_TOKEN on the Laragon/PHP server (or add github.token to the private config/config.php), then restart the web server.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for GitHub publishing. Enable extension=curl in Laragon PHP and restart Apache.');
    }

    $repository = trim((string)(getenv('ALLINONE_GITHUB_REPOSITORY') ?: 'iamdonzkie03/AllInOneWebsite'));
    $branch = trim((string)(getenv('ALLINONE_GITHUB_BRANCH') ?: 'main'));
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository) || $branch === '') {
        throw new RuntimeException('The GitHub repository or branch configuration is invalid.');
    }

    $apiUrl = 'https://api.github.com/repos/' . $repository . '/contents/index.php';
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: AllInOneWebsite-HeaderPublisher',
    ];
    $request = static function(string $url, string $method, array $requestHeaders, ?string $body = null): array {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response === false) throw new RuntimeException('GitHub connection failed: ' . ($curlError ?: 'unknown cURL error'));
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) throw new RuntimeException('GitHub returned an unreadable response (HTTP ' . $status . ').');
        return [$status, $decoded];
    };

    [$readStatus, $file] = $request($apiUrl . '?ref=' . rawurlencode($branch), 'GET', $headers);
    if ($readStatus !== 200 || empty($file['content']) || empty($file['sha'])) {
        $detail = (string)($file['message'] ?? ('HTTP ' . $readStatus));
        throw new RuntimeException('Unable to read index.php from GitHub: ' . $detail);
    }
    $source = base64_decode((string)$file['content'], true);
    if (!is_string($source)) throw new RuntimeException('GitHub index.php content could not be decoded.');

    $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) throw new RuntimeException('Header settings could not be encoded for GitHub.');
    $encodedSettings = base64_encode($json);
    $replacement = "// BEGIN GITHUB-PUBLISHED HEADER SETTINGS\n"
        . "\$githubPublishedHeaderSettings = json_decode(base64_decode('" . $encodedSettings . "'), true) ?: [];\n"
        . "// END GITHUB-PUBLISHED HEADER SETTINGS";
    $pattern = '~// BEGIN GITHUB-PUBLISHED HEADER SETTINGS.*?// END GITHUB-PUBLISHED HEADER SETTINGS~s';
    if (!preg_match($pattern, $source)) {
        throw new RuntimeException('The GitHub index.php does not contain the header-publishing marker block. Pull the latest project code first.');
    }
    $updatedSource = preg_replace($pattern, $replacement, $source, 1);
    if (!is_string($updatedSource)) throw new RuntimeException('Unable to prepare the updated GitHub index.php.');

    [$writeStatus, $result] = $request($apiUrl, 'PUT', array_merge($headers, ['Content-Type: application/json']), json_encode([
        'message' => 'Publish Page Management header settings',
        'content' => base64_encode($updatedSource),
        'sha' => (string)$file['sha'],
        'branch' => $branch,
    ], JSON_UNESCAPED_SLASHES));
    if (!in_array($writeStatus, [200, 201], true)) {
        $detail = (string)($result['message'] ?? ('HTTP ' . $writeStatus));
        if ($writeStatus === 401 || $writeStatus === 403) {
            $detail .= ' Check that the token is valid and has Contents: Read and write permission for this repository.';
        }
        throw new RuntimeException('GitHub did not commit index.php: ' . $detail);
    }
    return (string)($result['commit']['sha'] ?? '');
}

/**
 * Update the embedded header settings block in the local root index.php.
 * This makes the saved Header tab values part of the actual local homepage file.
 */
function updateLocalIndexHeaderSettings(array $header): void {
    $indexPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($indexPath) || !is_readable($indexPath) || !is_writable($indexPath)) {
        throw new RuntimeException('The local root index.php is not writable by Laragon/Apache. Grant write permission to C:\\laragon\\www\\allinoneweb\\index.php and try again.');
    }
    $source = file_get_contents($indexPath);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read the local root index.php file.');
    }
    $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Header settings could not be encoded for the local homepage.');
    }
    $replacement = "// BEGIN GITHUB-PUBLISHED HEADER SETTINGS\n"
        . "\$githubPublishedHeaderSettings = json_decode(base64_decode('" . base64_encode($json) . "', true) ?: '[]', true) ?: [];\n"
        . "// END GITHUB-PUBLISHED HEADER SETTINGS";
    $pattern = '~// BEGIN GITHUB-PUBLISHED HEADER SETTINGS.*?// END GITHUB-PUBLISHED HEADER SETTINGS~s';
    if (!preg_match($pattern, $source)) {
        throw new RuntimeException('The local index.php is missing the header settings marker block. Pull the latest project code first.');
    }
    $updated = preg_replace($pattern, $replacement, $source, 1);
    if (!is_string($updated)) {
        throw new RuntimeException('Unable to prepare the updated local index.php file.');
    }
    $written = file_put_contents($indexPath, $updated, LOCK_EX);
    if ($written === false || $written !== strlen($updated)) {
        throw new RuntimeException('The saved settings could not be written completely to the local index.php file. Check Laragon/Apache folder permissions.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_header_settings') {
        $title = trim((string)($_POST['header_title'] ?? ''));
        $left = trim((string)($_POST['hidden_left'] ?? ''));
        $right = trim((string)($_POST['hidden_right'] ?? ''));
        $family = (string)($_POST['font_family'] ?? 'Ubuntu');
        $style = (string)($_POST['font_style'] ?? 'normal');
        $size = max(12, min(32, (int)($_POST['font_size'] ?? 20)));
        $weight = (int)($_POST['font_weight'] ?? 700);
        $weight = in_array($weight, [300,400,500,600,700,800], true) ? $weight : 700;
        $positionX = max(0, min(100, (int)($_POST['logo_position_x'] ?? 50)));
        $positionY = max(0, min(100, (int)($_POST['logo_position_y'] ?? 50)));
        $allowedFamilies = ['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'];
        if (!in_array($family, $allowedFamilies, true)) $family = 'Ubuntu';
        if (!in_array($style, ['normal','italic'], true)) $style = 'normal';
        $titleHtml = cleanHeaderRichHtml((string)($_POST['header_title_html'] ?? ''));
        $leftHtml = cleanHeaderRichHtml((string)($_POST['hidden_left_html'] ?? ''));
        $rightHtml = cleanHeaderRichHtml((string)($_POST['hidden_right_html'] ?? ''));
        $typography = [
            'title'=>headerTypography((array)($_POST['typography']['title'] ?? []), ['family'=>$family,'style'=>$style,'size'=>$size,'weight'=>$weight,'color'=>'#f7eef0']),
            'hidden_left'=>headerTypography((array)($_POST['typography']['hidden_left'] ?? []), ['size'=>10,'weight'=>400,'color'=>'#cdbdc2']),
            'hidden_right'=>headerTypography((array)($_POST['typography']['hidden_right'] ?? []), ['size'=>10,'weight'=>400,'color'=>'#cdbdc2'])
        ];
        $links = [];
        foreach ((array)($_POST['header_link_label'] ?? []) as $index => $label) {
            $label = trim((string)$label);
            $url = trim((string)(($_POST['header_link_url'][$index] ?? '')));
            $labelHtml = cleanHeaderRichHtml((string)(($_POST['header_link_html'][$index] ?? '')));
            if ($label === '' && $url === '') continue;
            if ($label === '' || $url === '' || !preg_match('~^(https?://|/|#|\\.\\./|\\./)~i', $url)) {
                $message = 'Each header link needs a label and a valid URL (http://, https://, /, or #).';
                break;
            }
            $links[] = ['label'=>mb_substr($label,0,60),'label_html'=>$labelHtml,'url'=>mb_substr($url,0,500),'typography'=>headerTypography((array)($_POST['typography']['links'][$index] ?? []), ['size'=>13,'weight'=>500,'color'=>'#e8dce0'])];
        }
        $logoPath = (string)($headerSettings['logo_path'] ?? '');
        if ($message === '' && !empty($_FILES['header_logo']['name'])) {
            $file = $_FILES['header_logo'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 3 * 1024 * 1024) {
                $message = 'Logo upload failed. Choose a PNG, JPG or JPEG image up to 3 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
                $extensions = ['image/png'=>'png','image/jpeg'=>'jpg'];
                if (!isset($extensions[$mime]) || @getimagesize((string)$file['tmp_name']) === false) {
                    $message = 'Only valid PNG, JPG or JPEG logo images are allowed.';
                } else {
                    $uploadDir = __DIR__ . '/uploads/site-header';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $message = 'Unable to create the header logo upload folder.';
                    } else {
                        $filename = 'header-logo-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                        if (!move_uploaded_file((string)$file['tmp_name'], $uploadDir . '/' . $filename)) {
                            $message = 'Unable to save the uploaded header logo.';
                        } else {
                            $logoPath = 'uploads/site-header/' . $filename;
                        }
                    }
                }
            }
        }
        if ($message === '') {
            $save = $pdo->prepare("INSERT INTO site_header_settings
                (id,header_title,logo_path,header_links,hidden_left,hidden_right,font_family,font_style,font_size,font_weight,logo_position_x,logo_position_y,title_html,hidden_left_html,hidden_right_html,typography_json)
                VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                header_title=VALUES(header_title),logo_path=VALUES(logo_path),header_links=VALUES(header_links),
                hidden_left=VALUES(hidden_left),hidden_right=VALUES(hidden_right),font_family=VALUES(font_family),
                font_style=VALUES(font_style),font_size=VALUES(font_size),font_weight=VALUES(font_weight),
                logo_position_x=VALUES(logo_position_x),logo_position_y=VALUES(logo_position_y),
                title_html=VALUES(title_html),hidden_left_html=VALUES(hidden_left_html),
                hidden_right_html=VALUES(hidden_right_html),typography_json=VALUES(typography_json)");
            $save->execute([$title,$logoPath ?: null,json_encode($links,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$left,$right,$family,$style,$size,$weight,$positionX,$positionY,$titleHtml,$leftHtml,$rightHtml,json_encode($typography,JSON_UNESCAPED_SLASHES)]);
            // Read the row back from the same connection and verify every public-header
            // value. A successful SQL execute alone does not prove that the intended
            // settings were persisted.
            $verifyStmt = $pdo->prepare('SELECT * FROM site_header_settings WHERE id = 1 LIMIT 1');
            $verifyStmt->execute();
            $savedHeader = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            $expectedLinks = json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $expectedTypography = json_encode($typography, JSON_UNESCAPED_SLASHES);
            $checks = [
                'header_title' => $title,
                'logo_path' => $logoPath ?: null,
                'header_links' => $expectedLinks,
                'hidden_left' => $left,
                'hidden_right' => $right,
                'font_family' => $family,
                'font_style' => $style,
                'font_size' => (string)$size,
                'font_weight' => (string)$weight,
                'logo_position_x' => (string)$positionX,
                'logo_position_y' => (string)$positionY,
                'title_html' => $titleHtml,
                'hidden_left_html' => $leftHtml,
                'hidden_right_html' => $rightHtml,
                'typography_json' => $expectedTypography,
            ];
            $verificationErrors = [];
            if (!$savedHeader) {
                $verificationErrors[] = 'The settings row (id 1) was not found after saving.';
            } else {
                foreach ($checks as $column => $expectedValue) {
                    $actualValue = $savedHeader[$column] ?? null;
                    if ($expectedValue === null) {
                        if ($actualValue !== null && $actualValue !== '') $verificationErrors[] = $column;
                    } elseif ((string)$actualValue !== (string)$expectedValue) {
                        $verificationErrors[] = $column;
                    }
                }
            }
            if ($verificationErrors) {
                error_log('Header settings verification failed for columns: ' . implode(', ', $verificationErrors) . '; database=' . (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
                throw new RuntimeException('Header settings were submitted but could not be fully verified in the connected database. Please check the database selected in config/config.php and the PHP error log. Fields not verified: ' . implode(', ', $verificationErrors) . '.');
            }
            $headerSettings = $savedHeader;
            $headerLinks = $links;
            // Persist the verified settings into the actual local root index.php file.
            updateLocalIndexHeaderSettings($savedHeader);
            $message = 'Header settings successfully updated.';
        }
        $section = 'page-management';
    }
    $id = (int)($_POST['id'] ?? 0);
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
  transition:background .22s ease,border-color .22s ease,color .22s ease,box-shadow .22s ease;
}
.admin-nav button.settings-nav:hover{background:rgba(255,255,255,.045);color:#fff}
.admin-nav button.settings-nav.active{
  background:linear-gradient(110deg,rgba(128,20,39,.48),rgba(30,12,18,.62));
  border-color:rgba(184,50,63,.38);color:#fff;
  box-shadow:inset 1px 1px 3px rgba(255,255,255,.045),inset -2px -2px 5px rgba(0,0,0,.28),0 5px 16px rgba(0,0,0,.16)
}
.admin-nav .settings-nav-label{flex:1}
.admin-nav .settings-chevron{
  margin-left:auto;display:flex;align-items:center;justify-content:center;
  align-self:center;width:22px;height:22px;min-width:22px;flex:0 0 22px;
  padding:0;border:0;border-radius:0;background:transparent;
  color:#d9b5bd;font-size:0;line-height:1;box-shadow:none;
  transition:color .2s,transform .3s cubic-bezier(.2,.8,.2,1);
}
.admin-nav .settings-chevron::before{
  content:"";display:block;width:8px;height:8px;flex:0 0 8px;
  border-right:2px solid currentColor;border-bottom:2px solid currentColor;
  transform:translateY(-2px) rotate(45deg);transform-origin:center;
  transition:transform .3s cubic-bezier(.2,.8,.2,1);
}
.admin-nav .settings-nav[aria-expanded="true"] .settings-chevron{
  color:#fff;
}
.admin-nav .settings-nav[aria-expanded="true"] .settings-chevron::before{
  transform:translateY(2px) rotate(225deg);
}
.admin-subnav.settings-subnav{
  display:grid;gap:3px;margin:0 0 0 22px;padding:0 0 0 13px;
  border-left:1px solid rgba(184,50,63,.25);overflow:hidden;
  max-height:0;opacity:0;transform:translateY(-7px);
  pointer-events:none;visibility:hidden;
  transition:max-height .34s cubic-bezier(.2,.8,.2,1),opacity .22s ease,transform .3s ease,padding .3s ease,margin .3s ease,visibility .34s;
  background:linear-gradient(90deg,rgba(255,255,255,.018),rgba(255,255,255,0));
  border-radius:0 0 0 10px;
}
.admin-subnav.settings-subnav.is-open{
  max-height:180px;opacity:1;transform:translateY(0);
  pointer-events:auto;visibility:visible;padding:7px 0 7px 13px;margin:3px 0 10px 22px;
}
.admin-subnav.settings-subnav a{
  border-radius:9px;transition:background .2s ease,color .2s ease,transform .2s ease;
}
.admin-subnav.settings-subnav a:hover{background:rgba(255,255,255,.045);transform:translateX(3px)}
@media(prefers-reduced-motion:reduce){
 .admin-nav button.settings-nav,.admin-nav .settings-chevron,.admin-subnav.settings-subnav,.admin-subnav.settings-subnav a{transition:none}
}
.admin-settings-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 24px;padding-bottom:12px;border-bottom:1px solid var(--line)}
.admin-settings-tabs a{padding:10px 15px;border:1px solid var(--line);border-radius:10px;color:#c8bec1;font-size:13px}
.admin-settings-tabs a:hover{background:rgba(255,255,255,.04);color:#fff}
.admin-settings-tabs a.active{color:#fff;background:linear-gradient(100deg,rgba(128,20,39,.42),rgba(128,20,39,.08));border-color:rgba(184,50,63,.4)}
@media(max-width:850px){.admin-shell{grid-template-columns:1fr}.admin-sidebar{position:relative;top:auto;height:auto;border-right:0;border-bottom:1px solid var(--line);padding:16px}.admin-nav{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-subnav{grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));margin:0 0 8px 0;padding:8px 0 0;border-left:0;border-top:1px solid var(--line)}.admin-main{padding-top:25px}}
@media(max-width:560px){.admin-stats,.admin-feature-grid{grid-template-columns:1fr}.admin-nav{grid-template-columns:1fr}.admin-subnav{grid-template-columns:1fr}.admin-topline .btn{width:100%}}
</style></head><body>
<header><div class="wrap nav"><a class="brand" href="../index.php">Z.C. commonplace<span>.</span></a><nav class="navlinks"><a href="../index.php" target="_blank" rel="noopener noreferrer">Public website</a><a class="btn light" href="logout.php?scope=admin">Sign out</a></nav></div></header>
<div class="admin-shell">
<aside class="admin-sidebar">
  <div class="admin-label">Administration</div>
  <nav class="admin-nav" aria-label="Administrator menu">
    <a class="<?=navActive($section,'dashboard')?>" href="<?=e(adminUrl('dashboard'))?>"><span class="nav-icon">⌂</span>Dashboard</a>
    <button class="settings-nav<?=in_array($section,['settings','page-management','media-management','plugins'],true)?' active':''?>" type="button" id="settings-menu-toggle" aria-controls="settings-submenu" aria-expanded="<?=in_array($section,['settings','page-management','media-management','plugins'],true)?'true':'false'?>"><span class="nav-icon">⚙</span><span class="settings-nav-label">Settings</span><span class="settings-chevron" aria-hidden="true">⌄</span></button>
    <div class="admin-subnav settings-subnav<?=in_array($section,['settings','page-management','media-management','plugins'],true)?' is-open':''?>" id="settings-submenu">
      <a class="<?=navActive($section,'page-management')?>" href="<?=e(adminUrl('page-management'))?>">Page Management</a>
      <a class="<?=navActive($section,'media-management')?>" href="<?=e(adminUrl('media-management'))?>">Media Management</a>
      <a class="<?=navActive($section,'plugins')?>" href="<?=e(adminUrl('plugins'))?>">Plugins</a>
    </div>
    <a class="<?=navActive($section,'user-accounts')?>" href="<?=e(adminUrl('user-accounts'))?>"><span class="nav-icon">♙</span>User Accounts </a>
  </nav>
  <div class="admin-note" style="margin:26px 5px 0">Signed in as<br><strong><?=e($adminNickname)?></strong><br><span class="muted">System Administrator</span></div>
</aside>
<main class="admin-main">
<?php if($message === 'Header settings successfully updated.'):?>
<div class="account-status-modal" id="header-settings-success-modal" role="dialog" aria-modal="true" aria-labelledby="header-settings-success-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="header-settings-success-title">Header Settings Successfully Updated.</h2>
    <button type="button" class="btn" id="header-settings-success-close">OK</button>
  </div>
</div>
<?php elseif($message === 'Account status updated.'):?>
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
  <form id="bulk-users" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_selected_users">
  <div class="admin-panel-head" style="margin:0 0 14px;justify-content:flex-end"><button class="btn bulk-delete" type="submit" onclick="return confirm('Delete all selected user accounts? This action cannot be undone.');">Delete Selected</button></div>
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
  <?php if(!$managedUsers):?><tr><td colspan="7">No user accounts found.</td></tr><?php endif;?></tbody></table></div></form></section>
  <style>
  .users-table-controls{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin:4px 0 14px;color:var(--muted);font-size:12px}.users-table-controls label{display:flex;align-items:center;gap:9px}.users-table-controls select,.users-table-controls input{background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit}.users-table-controls input{width:min(280px,65vw)}.users-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0 22px}.users-page-buttons{display:flex;align-items:center;gap:8px}.users-page-buttons .btn{padding:8px 12px;font-size:12px}.users-page-buttons .btn:disabled{opacity:.4;cursor:not-allowed}
  </style>
  <script>
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
  <?php elseif($section==='page-management'): ?>
    <style>
      .page-management-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:20px 0 0;padding:0 0 12px;border-bottom:1px solid var(--line)}
      .page-management-tabs button{appearance:none;border:1px solid transparent;border-radius:10px;padding:10px 17px;background:transparent;color:#c8bec1;font:500 13px 'Ubuntu',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,color .2s,box-shadow .2s}
      .page-management-tabs button:hover{background:rgba(255,255,255,.045);color:#fff}
      .page-management-tabs button[aria-selected="true"]{color:#fff;background:linear-gradient(110deg,rgba(128,20,39,.48),rgba(30,12,18,.62));border-color:rgba(184,50,63,.4);box-shadow:inset 1px 1px 3px rgba(255,255,255,.04),0 4px 12px rgba(0,0,0,.14)}
      .page-management-tabpanel{margin-top:20px;min-height:190px}
      .page-management-tabpanel[hidden]{display:none}
      .header-settings-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.85fr);gap:24px;align-items:start}
      .header-settings-fields{display:grid;gap:20px;min-width:0}.header-field{display:grid;gap:8px;color:#f4e9ec;font-size:13px;font-weight:600}.header-field b{color:#ff8799}.header-field small,.header-preview-help{font-size:11px;color:var(--muted);font-weight:400;line-height:1.55}.header-field .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:9px;padding:10px 12px;font:13px 'Ubuntu',sans-serif}
      .header-link-row{display:grid;grid-template-columns:minmax(100px,.8fr) minmax(150px,1.3fr) auto;gap:8px;margin:8px 0}
      .rich-editor{display:block;width:100%;min-height:38px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:#120d10;color:#f4e9ec;font:13px Ubuntu,sans-serif;outline:none;box-sizing:border-box}.header-title-source{min-height:58px;resize:vertical;white-space:pre-wrap}.rich-editor:focus{border-color:#a83b52;box-shadow:0 0 0 2px rgba(168,59,82,.16)}.rich-tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.font-format-tools{display:flex;align-items:center;gap:5px}.font-format-tools .format-icon{width:32px;height:30px;border:1px solid var(--line);border-radius:6px;background:#171115;color:#f4e9ec;cursor:pointer;font:13px Ubuntu,sans-serif}.font-format-tools .format-icon[aria-pressed="true"]{background:#7f1d34;border-color:#bd536a;color:#fff;box-shadow:inset 0 0 0 1px #bd536a}.rich-style-controls{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;grid-column:1/-1}.rich-style-controls label{display:grid;gap:4px;font-size:10px;color:#cdbdc2}.rich-style-controls input,.rich-style-controls select{width:100%;min-width:0;padding:6px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px;font:11px Ubuntu,sans-serif}.rich-tools button{padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:#21151a;color:#f4e9ec;font-size:11px;cursor:pointer}.rich-tools input,.rich-tools select{max-width:110px;padding:4px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px}.typography-group{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;border:1px solid var(--line);border-radius:10px;padding:10px}.typography-group legend{font-size:12px;color:#f1c4ce;padding:0 5px}.typography-group label{display:grid;gap:5px;font-size:11px;color:#d9cbd0}.typography-group .field{min-width:0;padding:8px}.header-link-row .rich-editor,.header-link-row .rich-tools{grid-column:1/-1}.header-link-row{align-items:start}.header-hidden-fields,.header-typography-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.header-hidden-item{display:grid;gap:8px;min-width:0;color:#d9cbd0;font-size:12px}.header-hidden-fields label,.header-typography-fields label,.logo-crop-controls label{display:grid;gap:7px;font-size:12px;color:#d9cbd0}.logo-crop-controls{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}.logo-crop-controls input{width:100%;accent-color:#a52b45}
      .header-preview-column{position:sticky;top:20px;min-width:0}.header-preview-title{font-size:13px;font-weight:700;margin-bottom:10px}.header-preview-frame{overflow:hidden;border:1px solid rgba(184,50,63,.35);border-radius:13px;background:#100d10;box-shadow:0 12px 35px rgba(0,0,0,.22)}.header-preview-topbar{display:flex;justify-content:space-between;gap:10px;padding:8px 12px;background:#090809;color:#cdbdc2;font-size:9px;letter-spacing:.5px}.header-preview-main{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 12px;min-height:65px}.header-preview-brand{display:flex;align-items:center;gap:9px;color:#fff;text-decoration:none;min-width:0}.header-preview-brand img{width:70px;height:70px;max-width:70px;max-height:70px;object-fit:cover;object-position:50% 50%;border-radius:7px}.header-preview-brand span{overflow-wrap:anywhere}.header-preview-main nav{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}.header-preview-main nav a{font-size:9px;color:#e8dce0;text-decoration:none;white-space:nowrap}.header-preview-main nav .preview-signin{color:#fff}.header-preview-main nav .preview-join{padding:7px 9px;border-radius:7px;background:#92243a;color:#fff}.header-preview-help{margin:10px 0 0}.header-settings-actions{display:flex;justify-content:flex-end;margin-top:22px;padding-top:17px;border-top:1px solid var(--line)}
      @media(max-width:900px){.header-settings-grid{grid-template-columns:1fr}.header-preview-column{position:static}}
      @media(max-width:560px){.header-link-row{grid-template-columns:1fr}.header-hidden-fields,.header-typography-fields,.logo-crop-controls{grid-template-columns:1fr}.header-preview-main{align-items:flex-start;flex-direction:column}.header-preview-main nav{justify-content:flex-start}}
      @media(max-width:560px){.page-management-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.page-management-tabs button{width:100%}}
    </style>
    <section class="panel admin-panel">
      <div class="admin-panel-head"><div><h2>Page Management</h2><p class="muted">Manage the content and presentation of your public website.</p></div></div>
      <div class="page-management-tabs" role="tablist" aria-label="Page Management tabs">
        <button type="button" id="pm-tab-header" role="tab" aria-controls="pm-panel-header" aria-selected="true" tabindex="0" data-page-tab="header">Header</button>
        <button type="button" id="pm-tab-footer" role="tab" aria-controls="pm-panel-footer" aria-selected="false" tabindex="-1" data-page-tab="footer">Footer</button>
        <button type="button" id="pm-tab-sections" role="tab" aria-controls="pm-panel-sections" aria-selected="false" tabindex="-1" data-page-tab="sections">Sections</button>
        <button type="button" id="pm-tab-gallery" role="tab" aria-controls="pm-panel-gallery" aria-selected="false" tabindex="-1" data-page-tab="gallery">Gallery</button>
        <button type="button" id="pm-tab-video" role="tab" aria-controls="pm-panel-video" aria-selected="false" tabindex="-1" data-page-tab="video">Video</button>
      </div>
      <section class="page-management-tabpanel" id="pm-panel-header" role="tabpanel" aria-labelledby="pm-tab-header">
        <form method="post" enctype="multipart/form-data" id="header-settings-form" class="header-settings-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="save_header_settings">
          <div class="header-settings-grid">
            <div class="header-settings-fields">
              <div class="header-field"><span>Header Title</span><textarea class="rich-editor header-title-source" data-rich-editor="title" aria-label="Edit Header Title" placeholder="Enter website title" rows="2"><?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?></textarea><input type="hidden" name="header_title" maxlength="160" data-plain-value="title" value="<?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?>"><input type="hidden" name="header_title_html" data-rich-value="title"><?php $savedTypography=json_decode((string)($headerSettings['typography_json'] ?? '{}'),true) ?: []; $titleTypography=headerTypography((array)($savedTypography['title'] ?? []),['family'=>'Ubuntu','style'=>'normal','size'=>20,'weight'=>700,'color'=>'#f7eef0']); ?><fieldset class="typography-group"><legend>Independent Typography — Header Title</legend>
                  <label>Font family<select class="field" name="typography[title][family]"><?php foreach(['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'] as $f): ?><option value="<?=$f?>" <?=$titleTypography['family']===$f?'selected':''?>><?=$f?></option><?php endforeach; ?></select></label>
                  <label>Font size<select class="field" name="typography[title][size]"><?php foreach([8,9,10,11,12,13,14,16,18,20,24,28,32,36,40] as $s): ?><option value="<?=$s?>" <?=$titleTypography['size']===$s?'selected':''?>><?=$s?> px</option><?php endforeach; ?></select></label>
                  <div class="font-format-tools" role="group" aria-label="Font formatting"><input type="hidden" name="typography[title][format]" value="<?=e($titleTypography['format'])?>" data-format-value><button type="button" class="format-icon" data-format-toggle="bold" aria-label="Bold" aria-pressed="<?=str_contains($titleTypography['format'],'bold')?'true':'false'?>"><b>B</b></button><button type="button" class="format-icon" data-format-toggle="italic" aria-label="Italic" aria-pressed="<?=str_contains($titleTypography['format'],'italic')?'true':'false'?>"><i>I</i></button><button type="button" class="format-icon" data-format-toggle="underline" aria-label="Underline" aria-pressed="<?=str_contains($titleTypography['format'],'underline')?'true':'false'?>"><u>U</u></button></div>
                  <label>Font color<input class="field" type="color" name="typography[title][color]" value="<?=e($titleTypography['color'])?>"></label>
                </fieldset><small>Clicking the title or logo takes visitors to index.php.</small></div>
              <div class="header-field"><span>Header Logo</span><input class="field" type="file" name="header_logo" id="header-logo-input" accept="image/png,image/jpeg"><small>PNG, JPG or JPEG · Maximum display size 70 × 70 px · Maximum upload 3 MB. The preview uses a square crop.</small>
                <div class="logo-crop-controls"><label>Horizontal crop <input type="range" name="logo_position_x" id="logo-position-x" min="0" max="100" value="<?=(int)($headerSettings['logo_position_x'] ?? 50)?>"></label><label>Vertical crop <input type="range" name="logo_position_y" id="logo-position-y" min="0" max="100" value="<?=(int)($headerSettings['logo_position_y'] ?? 50)?>"></label></div>
              </div>
              <div class="header-field"><span>Header Links</span><small>Add as many navigation links as needed. Each URL can point directly to a website page.</small>
                <div id="header-links-list">
                <?php foreach($headerLinks as $linkIndex=>$headerLink): ?><div class="header-link-row"><input class="field" type="text" name="header_link_url[]" maxlength="500" placeholder="Page URL (e.g. /about.php)" value="<?=e((string)($headerLink['url'] ?? ''))?>"><button type="button" class="btn light remove-header-link" aria-label="Remove link">Remove</button><div class="rich-editor" data-rich-editor="link" contenteditable="true" role="textbox" aria-label="Edit header link label" data-placeholder="Link label"><?=cleanHeaderRichHtml((string)($headerLink['label_html'] ?? '')) ?: e((string)($headerLink['label'] ?? ''))?></div><input type="hidden" name="header_link_label[]" maxlength="60" data-plain-value="link" value="<?=e((string)($headerLink['label'] ?? ''))?>"><input type="hidden" name="header_link_html[]" data-rich-value="link"><div class="rich-style-controls" data-style-controls="link" data-index="<?=$linkIndex?>" data-typography="<?=e(json_encode((array)($headerLink['typography'] ?? ['family'=>'Ubuntu','style'=>'normal','size'=>13,'weight'=>500,'color'=>'#e8dce0'])))?>"></div></div><?php endforeach; ?>
                </div><button type="button" class="btn light" id="add-header-link">+ Add Header Link</button>
              </div>
              <div class="header-field"><span>Hidden Texts</span><small>Left and right text have separate typography settings.</small><div class="header-hidden-fields"><div class="header-hidden-item"><span>Left text</span><div class="rich-editor" data-rich-editor="hidden_left" contenteditable="true" role="textbox" aria-label="Edit left hidden text" data-placeholder="Text on the left"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_left_html'] ?? '')) ?: e((string)($headerSettings['hidden_left'] ?? ''))?></div><input type="hidden" name="hidden_left" maxlength="180" data-plain-value="hidden_left" value="<?=e((string)($headerSettings['hidden_left'] ?? ''))?>"><input type="hidden" name="hidden_left_html" data-rich-value="hidden_left"><?php $leftTypography=headerTypography((array)($savedTypography['hidden_left'] ?? []),['family'=>'Ubuntu','style'=>'normal','size'=>10,'weight'=>400,'color'=>'#cdbdc2']); ?><fieldset class="typography-group"><legend>Independent Typography — Left Text</legend>
                    <label>Font family<select class="field" name="typography[hidden_left][family]"><?php foreach(['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'] as $f): ?><option value="<?=$f?>" <?=$leftTypography['family']===$f?'selected':''?>><?=$f?></option><?php endforeach; ?></select></label>
                  <label>Font size<select class="field" name="typography[hidden_left][size]"><?php foreach([8,9,10,11,12,13,14,16,18,20,24,28,32,36,40] as $s): ?><option value="<?=$s?>" <?=$leftTypography['size']===$s?'selected':''?>><?=$s?> px</option><?php endforeach; ?></select></label>
                    <div class="font-format-tools" role="group" aria-label="Font formatting"><input type="hidden" name="typography[hidden_left][format]" value="<?=e($leftTypography['format'])?>" data-format-value><button type="button" class="format-icon" data-format-toggle="bold" aria-label="Bold" aria-pressed="<?=str_contains($leftTypography['format'],'bold')?'true':'false'?>"><b>B</b></button><button type="button" class="format-icon" data-format-toggle="italic" aria-label="Italic" aria-pressed="<?=str_contains($leftTypography['format'],'italic')?'true':'false'?>"><i>I</i></button><button type="button" class="format-icon" data-format-toggle="underline" aria-label="Underline" aria-pressed="<?=str_contains($leftTypography['format'],'underline')?'true':'false'?>"><u>U</u></button></div>
                    <label>Font color<input class="field" type="color" name="typography[hidden_left][color]" value="<?=e($leftTypography['color'])?>"></label>
                  </fieldset></div><div class="header-hidden-item"><span>Right text</span><div class="rich-editor" data-rich-editor="hidden_right" contenteditable="true" role="textbox" aria-label="Edit right hidden text" data-placeholder="Text on the right"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_right_html'] ?? '')) ?: e((string)($headerSettings['hidden_right'] ?? ''))?></div><input type="hidden" name="hidden_right" maxlength="180" data-plain-value="hidden_right" value="<?=e((string)($headerSettings['hidden_right'] ?? ''))?>"><input type="hidden" name="hidden_right_html" data-rich-value="hidden_right"><?php $rightTypography=headerTypography((array)($savedTypography['hidden_right'] ?? []),['family'=>'Ubuntu','style'=>'normal','size'=>10,'weight'=>400,'color'=>'#cdbdc2']); ?><fieldset class="typography-group"><legend>Independent Typography — Right Text</legend>
                    <label>Font family<select class="field" name="typography[hidden_right][family]"><?php foreach(['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'] as $f): ?><option value="<?=$f?>" <?=$rightTypography['family']===$f?'selected':''?>><?=$f?></option><?php endforeach; ?></select></label>
                  <label>Font size<select class="field" name="typography[hidden_right][size]"><?php foreach([8,9,10,11,12,13,14,16,18,20,24,28,32,36,40] as $s): ?><option value="<?=$s?>" <?=$rightTypography['size']===$s?'selected':''?>><?=$s?> px</option><?php endforeach; ?></select></label>
                    <div class="font-format-tools" role="group" aria-label="Font formatting"><input type="hidden" name="typography[hidden_right][format]" value="<?=e($rightTypography['format'])?>" data-format-value><button type="button" class="format-icon" data-format-toggle="bold" aria-label="Bold" aria-pressed="<?=str_contains($rightTypography['format'],'bold')?'true':'false'?>"><b>B</b></button><button type="button" class="format-icon" data-format-toggle="italic" aria-label="Italic" aria-pressed="<?=str_contains($rightTypography['format'],'italic')?'true':'false'?>"><i>I</i></button><button type="button" class="format-icon" data-format-toggle="underline" aria-label="Underline" aria-pressed="<?=str_contains($rightTypography['format'],'underline')?'true':'false'?>"><u>U</u></button></div>
                    <label>Font color<input class="field" type="color" name="typography[hidden_right][color]" value="<?=e($rightTypography['color'])?>"></label>
                  </fieldset></div></div></div>
            </div>
            <aside class="header-preview-column"><div class="header-preview-title">Header Preview</div><div class="header-preview-frame"><div class="header-preview-topbar"><span id="preview-hidden-left"><?=e((string)($headerSettings['hidden_left'] ?? ''))?></span><span id="preview-hidden-right"><?=e((string)($headerSettings['hidden_right'] ?? ''))?></span></div><div class="header-preview-main"><a href="../index.php" class="header-preview-brand" id="preview-header-brand"><img id="preview-header-logo" src="<?=e((string)($headerSettings['logo_path'] ?? ''))?>" alt="" style="<?=empty($headerSettings['logo_path'])?'display:none;':''?>"><span id="preview-header-title"><?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?></span></a><nav id="preview-header-links"><?php foreach($headerLinks as $headerLink): ?><a href="<?=e((string)($headerLink['url'] ?? '#'))?>"><?=e((string)($headerLink['label'] ?? ''))?></a><?php endforeach; ?><a href="#" class="preview-signin">Sign in</a><a href="#" class="preview-join">Join the Community</a></nav></div></div><p class="header-preview-help">Preview updates as you edit. Sign in and Join the Community stay visible on the public header.</p></aside>
          </div>
          <div class="header-settings-actions"><button type="submit" class="btn">Save Header Settings</button></div>
        </form>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-footer" role="tabpanel" aria-labelledby="pm-tab-footer" hidden>
        <h2>Footer</h2><p class="muted">Manage the public website footer and its displayed content.</p>
        <div class="admin-note">Footer management controls can be added here. No existing website content has been changed.</div>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-sections" role="tabpanel" aria-labelledby="pm-tab-sections" hidden>
        <h2>Sections</h2><p class="muted">Manage the sections and content blocks shown across the public website.</p>
        <div class="admin-note">Section management controls can be added here. No existing website content has been changed.</div>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-gallery" role="tabpanel" aria-labelledby="pm-tab-gallery" hidden>
        <h2>Gallery</h2><p class="muted">Manage photographs and image collections displayed on the public website.</p>
        <div class="admin-note">Gallery management controls can be added here. No existing website content has been changed.</div>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-video" role="tabpanel" aria-labelledby="pm-tab-video" hidden>
        <h2>Video</h2><p class="muted">Manage videos displayed on the public website.</p>
        <div class="admin-note">Video management controls can be added here. No existing website content has been changed.</div>
      </section>
    </section>
    <script>
    (() => {
      const form = document.getElementById('header-settings-form');
      if (form) {
        const links = document.getElementById('header-links-list');
        const add = document.getElementById('add-header-link');
        const makeLinkRow = (label = '', url = '') => {
          const row=document.createElement('div');row.className='header-link-row';
          const urlInput=document.createElement('input');urlInput.className='field';urlInput.name='header_link_url[]';urlInput.maxLength=500;urlInput.placeholder='Page URL';urlInput.value=url;
          const remove=document.createElement('button');remove.type='button';remove.className='btn light remove-header-link';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();updatePreview();});
          const editor=document.createElement('div');editor.className='rich-editor';editor.contentEditable='true';editor.dataset.richEditor='link';editor.textContent=label;editor.setAttribute('role','textbox');
          const richLabel=document.createElement('input');richLabel.type='hidden';richLabel.name='header_link_label[]';richLabel.dataset.plainValue='link';richLabel.value=label;
          const hidden=document.createElement('input');hidden.type='hidden';hidden.name='header_link_html[]';hidden.dataset.richValue='link';
          const tools=document.createElement('div');tools.className='rich-tools';tools.dataset.tools='link';
          const styles=document.createElement('div');styles.className='rich-style-controls';styles.dataset.styleControls='link';styles.dataset.typography='{"family":"Ubuntu","style":"normal","size":13,"weight":500,"color":"#e8dce0"}';
          row.append(urlInput,remove,editor,richLabel,hidden,tools,styles);return row;
        };
        const editorText = row => row.querySelector('[data-rich-editor]')?.innerText || '';
        const updatePreview = () => {
          const titleEditor=form.querySelector('[data-rich-editor="title"]'),leftEditor=form.querySelector('[data-rich-editor="hidden_left"]'),rightEditor=form.querySelector('[data-rich-editor="hidden_right"]');
          if(document.activeElement!==titleEditor && titleEditor.value.trim()!==form.elements.header_title.value.trim())titleEditor.value=form.elements.header_title.value;
          if(document.activeElement!==leftEditor && leftEditor.innerText.trim()!==form.elements.hidden_left.value.trim())leftEditor.textContent=form.elements.hidden_left.value;
          if(document.activeElement!==rightEditor && rightEditor.innerText.trim()!==form.elements.hidden_right.value.trim())rightEditor.textContent=form.elements.hidden_right.value;
          const title=titleEditor.value.trim() || 'Header Title';form.elements.header_title.value=titleEditor.value.trim();
          document.getElementById('preview-header-title').textContent=title;
          document.getElementById('preview-hidden-left').textContent=form.elements.hidden_left.value;
          document.getElementById('preview-hidden-right').textContent=form.elements.hidden_right.value;
          const brand=document.getElementById('preview-header-brand');
          const savedTypography=<?=json_encode(json_decode((string)($headerSettings['typography_json'] ?? '{}'),true) ?: [],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
          const styleFor=(key)=>{const group=form.querySelectorAll('[name^="typography['+key+']"]');const values={};group.forEach(el=>values[el.name.match(/\[([^\]]+)\]$/)[1]]=el.value);return values;};
          const titleStyle=styleFor('title');Object.assign(document.getElementById('preview-header-title').style,{fontFamily:titleStyle.family||'Ubuntu',fontStyle:(titleStyle.format||'').split(/[ ,]+/).includes('italic')?'italic':'normal',fontSize:(titleStyle.size||20)+'px',fontWeight:(titleStyle.format||'').split(/[ ,]+/).includes('bold')?700:400,textDecoration:(titleStyle.format||'').split(/[ ,]+/).includes('underline')?'underline':'none',color:titleStyle.color||'#f7eef0'});
          ['hidden_left','hidden_right'].forEach(key=>{const st=styleFor(key);const el=document.getElementById(key==='hidden_left'?'preview-hidden-left':'preview-hidden-right');Object.assign(el.style,{fontFamily:st.family||'Ubuntu',fontStyle:(st.format||'').split(/[ ,]+/).includes('italic')?'italic':'normal',fontSize:(st.size||10)+'px',fontWeight:(st.format||'').split(/[ ,]+/).includes('bold')?700:400,textDecoration:(st.format||'').split(/[ ,]+/).includes('underline')?'underline':'none',color:st.color||'#cdbdc2'});});
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{const target=form.querySelector('[data-rich-value="'+editor.dataset.richEditor+'"]');if(target)target.value=editor.innerHTML;});
          const titlePreview=document.getElementById('preview-header-title');renderTitlePreview();titlePreview.style.setProperty('font-family',titleStyle.family||'Ubuntu','important');
          document.getElementById('preview-hidden-left').innerHTML=form.querySelector('[data-rich-editor="hidden_left"]').innerHTML;
          document.getElementById('preview-hidden-right').innerHTML=form.querySelector('[data-rich-editor="hidden_right"]').innerHTML;
          const nav=document.getElementById('preview-header-links'); nav.querySelectorAll('.custom-preview-link').forEach(a=>a.remove());
          [...links.querySelectorAll('.header-link-row')].forEach((row,index)=>{const labelInput=row.querySelector('[name="header_link_label[]"]');const editor=row.querySelector('[data-rich-editor]');const label=editorText(row).trim();if(labelInput)labelInput.value=label;const url=row.querySelector('[name="header_link_url[]"]').value.trim();const rich=row.querySelector('[data-rich-value]');if(rich)rich.value=editor?editor.innerHTML:'';row.querySelectorAll('[name^="typography[links]"]').forEach(el=>{el.name=el.name.replace(/typography\[links\]\[\d+\]/,'typography[links]['+index+']');});if(label){const a=document.createElement('a');a.className='custom-preview-link';a.innerHTML=editor?editor.innerHTML:label;a.href=url||'#';const controls=row.querySelectorAll('[name^="typography[links]"]');const st={};controls.forEach(el=>st[el.name.match(/\[([^\]]+)\]$/)[1]]=el.value);Object.assign(a.style,{fontFamily:st.family||'Ubuntu',fontSize:(st.size||13)+'px',fontWeight:(st.format||'').split(/[ ,]+/).includes('bold')?700:500,fontStyle:(st.format||'').split(/[ ,]+/).includes('italic')?'italic':'normal',textDecoration:(st.format||'').split(/[ ,]+/).includes('underline')?'underline':'none',color:st.color||'#e8dce0'});nav.insertBefore(a,nav.querySelector('.preview-signin'));}});
          const logo=document.getElementById('preview-header-logo');logo.style.objectPosition=form.elements.logo_position_x.value+'% '+form.elements.logo_position_y.value+'%';
        };
        const attachColorPalette=(input)=>{if(!input||input.dataset.paletteReady)return;input.dataset.paletteReady='1';const button=document.createElement('button');button.type='button';button.className='font-color-palette';button.setAttribute('aria-label','Open font color palette');button.setAttribute('aria-haspopup','dialog');button.title='Choose font color';button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 0 18h1.2a2.3 2.3 0 0 0 1.6-3.9 1.2 1.2 0 0 1 .9-2.1H18a3 3 0 0 0 3-3c0-5-4-8-9-9Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="10" cy="6.8" r="1"/><circle cx="15" cy="7.5" r="1"/><circle cx="17.5" cy="11" r="1"/></svg>';const sync=()=>button.style.setProperty('--palette-color',input.value);input.classList.add('font-color-native');input.insertAdjacentElement('afterend',button);button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>{try{if(input.showPicker)input.showPicker();else input.click();}catch(e){input.click();}});input.addEventListener('input',sync);input.addEventListener('change',sync);sync();};
        form.querySelectorAll('.typography-group input[type="color"]').forEach(attachColorPalette);
        // Selected-text typography is preview-only: keep the editor itself untouched.
        const titleEditor=form.querySelector('[data-rich-editor="title"]');
        const titlePreview=document.getElementById('preview-header-title');
        // Bind the independent controls to the Header Title fieldset explicitly.
        // This reference was previously missing and caused a ReferenceError that
        // stopped the entire selected-text typography workflow from initializing.
        const titleTypographyGroup=titleEditor?.closest('.header-field')?.querySelector('fieldset.typography-group') || null;
        let savedTitleSelection=null;
        const titlePreviewStyles=[];
        const readTitleSelection=()=>{
          const start=titleEditor.selectionStart;
          const end=titleEditor.selectionEnd;
          return Number.isInteger(start)&&Number.isInteger(end)&&end>start?{start,end}:null;
        };
        const rememberTitleSelection=()=>{
          const next=readTitleSelection();
          if(next)savedTitleSelection=next;
        };
        ['select','keyup','mouseup','touchend'].forEach(eventName=>titleEditor.addEventListener(eventName,rememberTitleSelection));
        // Textarea selectionStart/selectionEnd remain reliable even after a typography
        // control receives focus, unlike document.getSelection() on contenteditable.
        const captureTitleRangeBeforeControlFocus=event=>{
          if(!titleTypographyGroup || !titleTypographyGroup.contains(event.target))return;
          rememberTitleSelection();
        };
        document.addEventListener('pointerdown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('mousedown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('touchstart',captureTitleRangeBeforeControlFocus,{capture:true,passive:true});
        const escapePreviewText=value=>String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        const renderTitlePreview=()=>{
          const text=titleEditor.value||'';
          const boundaries=new Set([0,text.length]);
          titlePreviewStyles.forEach(item=>{
            boundaries.add(Math.max(0,Math.min(text.length,item.start)));
            boundaries.add(Math.max(0,Math.min(text.length,item.end)));
          });
          const points=[...boundaries].sort((a,b)=>a-b);
          let html='';
          for(let i=0;i<points.length-1;i++){
            const start=points[i],end=points[i+1];
            if(end<=start)continue;
            const styles={};
            titlePreviewStyles.filter(item=>item.start<end&&item.end>start).forEach(item=>Object.assign(styles,item.styles));
            const content=escapePreviewText(text.slice(start,end));
            const declarations=Object.entries(styles).map(([key,value])=>{
              const cssName=key.replace(/[A-Z]/g,m=>'-'+m.toLowerCase());
              return cssName+':'+String(value)+' !important';
            }).join(';');
            html+=declarations?'<span style="'+declarations+'">'+content+'</span>':content;
          }
          titlePreview.innerHTML=html||escapePreviewText(text);
          const richField=form.querySelector('[data-rich-value="title"]');
          if(richField)richField.value=html||escapePreviewText(text);
        };
        const applyTitleSelectionStyle=(property,value)=>{
          if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start)return false;
          const textLength=titleEditor.value.length;
          const start=Math.max(0,Math.min(textLength,savedTitleSelection.start));
          const end=Math.max(start,Math.min(textLength,savedTitleSelection.end));
          if(end<=start)return false;
          let item=titlePreviewStyles.find(entry=>entry.start===start&&entry.end===end);
          if(!item){item={start,end,styles:{}};titlePreviewStyles.push(item);}
          item.styles[property]=value;
          renderTitlePreview();
          return true;
        };
        titleTypographyGroup?.querySelectorAll('[data-format-toggle]').forEach(button=>{
          button.addEventListener('mousedown',event=>event.preventDefault());
          button.addEventListener('click',()=>{
            const fmt=button.dataset.formatToggle;
            if(savedTitleSelection&&savedTitleSelection.end>savedTitleSelection.start){
              const prop=fmt==='bold'?'fontWeight':fmt==='italic'?'fontStyle':'textDecoration';
              const current=button.getAttribute('aria-pressed')==='true';
              const value=fmt==='bold'?(current?'400':'700'):fmt==='italic'?(current?'normal':'italic'):(current?'none':'underline');
              if(applyTitleSelectionStyle(prop,value)){
                button.setAttribute('aria-pressed',String(!current));
                updatePreview();
                return;
              }
            }
            const hidden=titleTypographyGroup.querySelector('[data-format-value]');
            const set=new Set((hidden?.value||'').split(/[ ,]+/).filter(Boolean));
            const active=set.has(fmt);
            active?set.delete(fmt):set.add(fmt);
            if(hidden)hidden.value=['bold','italic','underline'].filter(x=>set.has(x)).join(' ');
            button.setAttribute('aria-pressed',String(!active));
            updatePreview();
          });
        });
        // Every other Independent Typography panel must also refresh the preview live.
        form.querySelectorAll('.typography-group').forEach(group=>{
          if(group===titleTypographyGroup)return;
          group.querySelectorAll('select,input[type="color"]').forEach(input=>{
            input.addEventListener('input',updatePreview);
            input.addEventListener('change',updatePreview);
          });
          group.querySelectorAll('[data-format-toggle]').forEach(button=>{
            button.addEventListener('mousedown',event=>event.preventDefault());
            button.addEventListener('click',()=>{
              const hidden=group.querySelector('[data-format-value]');
              const format=button.dataset.formatToggle;
              const formats=new Set((hidden?.value||'').split(/[ ,]+/).filter(Boolean));
              const active=formats.has(format);
              active?formats.delete(format):formats.add(format);
              if(hidden)hidden.value=['bold','italic','underline'].filter(item=>formats.has(item)).join(' ');
              button.setAttribute('aria-pressed',String(!active));
              updatePreview();
            });
          });
        });
        titleTypographyGroup?.querySelectorAll('select[name$="[family]"],select[name$="[size]"],input[name$="[color]"]').forEach(input=>{
          const applyControlValue=()=>{
            if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start){updatePreview();return;}
            const prop=input.name.endsWith('[family]')?'fontFamily':input.name.endsWith('[size]')?'fontSize':'color';
            const value=input.name.endsWith('[size]')?input.value+'px':input.value;
            if(applyTitleSelectionStyle(prop,value)){updatePreview();return;}
            updatePreview();
          };
          input.addEventListener('input',applyControlValue);
          input.addEventListener('change',applyControlValue);
        });
        const initRichEditors=()=>{
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{
            if(editor.dataset.ready)return;editor.dataset.ready='1';
            const key=editor.dataset.richEditor;
            const hidden=form.querySelector('[data-rich-value="'+key+'"]');editor.addEventListener('input',()=>{if(hidden)hidden.value=key==='title'?(form.querySelector('[data-rich-value="title"]')?.value||escapePreviewText(editor.value)):editor.innerHTML;if(key==='title'){titlePreviewStyles.length=0;savedTitleSelection=null;}const plain=key==='title'?editor.value.trim():editor.innerText.trim();const plainField=form.querySelector('[data-plain-value="'+key+'"]');if(plainField)plainField.value=plain;updatePreview();});
          });
          form.querySelectorAll('[data-style-controls="link"]').forEach((box)=>{
            if(box.dataset.ready)return;box.dataset.ready='1';const row=box.closest('.header-link-row');const idx=[...links.querySelectorAll('.header-link-row')].indexOf(row);let saved={};try{saved=JSON.parse(box.dataset.typography||'{}')}catch(e){}
            const fields=[['family','Font family','select',['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma']],['size','Font size','select',[8,9,10,11,12,13,14,16,18,20,24,28,32,36,40]],['color','Font color','color',[]],['format','Font formatting','format-icons',[]]];
            fields.forEach(([key,label,type,options])=>{if(type==='format-icons'){const wrap=document.createElement('div');wrap.className='font-format-tools';wrap.setAttribute('role','group');wrap.setAttribute('aria-label','Font formatting');const hidden=document.createElement('input');hidden.type='hidden';hidden.name='typography[links]['+idx+'][format]';hidden.dataset.formatValue='';hidden.value=saved.format||'';['bold','italic','underline'].forEach(fmt=>{const b=document.createElement('button');b.type='button';b.className='format-icon';b.dataset.formatToggle=fmt;b.setAttribute('aria-label',fmt);b.setAttribute('aria-pressed',String(hidden.value.split(/[ ,]+/).includes(fmt)));b.innerHTML=fmt==='bold'?'<b>B</b>':fmt==='italic'?'<i>I</i>':'<u>U</u>';b.addEventListener('mousedown',e=>e.preventDefault());b.addEventListener('click',()=>{const set=new Set(hidden.value.split(/[ ,]+/).filter(Boolean));set.has(fmt)?set.delete(fmt):set.add(fmt);hidden.value=['bold','italic','underline'].filter(x=>set.has(x)).join(' ');b.setAttribute('aria-pressed',String(set.has(fmt)));updatePreview();});wrap.append(b);});wrap.append(hidden);box.append(wrap);return;}const wrap=document.createElement('label');wrap.textContent=label;const input=document.createElement(type==='color'?'input':'select');input.name='typography[links]['+idx+']['+key+']';if(type==='color'){input.type='color';input.value=saved[key]||'#e8dce0';attachColorPalette(input)}else{options.forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=key==='size'?v+' px':v;input.append(o)});input.value=saved[key]|| (key==='family'?'Ubuntu':key==='size'?13:'normal')}input.addEventListener('change',updatePreview);wrap.append(input);box.append(wrap);});
          });
        };
        initRichEditors();
        updatePreview();
        add.addEventListener('click',()=>{links.append(makeLinkRow());initRichEditors();updatePreview();});
        links.querySelectorAll('.remove-header-link').forEach(button=>button.addEventListener('click',()=>{button.closest('.header-link-row').remove();updatePreview();}));
        // Synchronize every editable Header field immediately before POST. This prevents
        // stale hidden inputs from overwriting the values currently visible in the editors.
        form.addEventListener('submit', () => {
          form.querySelectorAll('[data-rich-editor]').forEach(editor => {
            const key = editor.dataset.richEditor;
            const plain = key === 'title' ? editor.value.trim() : editor.innerText.trim();
            const rich = key === 'title' ? (form.querySelector('[data-rich-value="title"]')?.value || escapePreviewText(editor.value)) : editor.innerHTML;
            const plainField = editor.closest('.header-link-row')
              ? editor.closest('.header-link-row').querySelector('[data-plain-value="link"]')
              : form.querySelector('[data-plain-value="' + key + '"]');
            const richField = editor.closest('.header-link-row')
              ? editor.closest('.header-link-row').querySelector('[data-rich-value="link"]')
              : form.querySelector('[data-rich-value="' + key + '"]');
            if (plainField) plainField.value = plain;
            if (richField) richField.value = rich;
          });
          form.querySelectorAll('.header-link-row').forEach((row, index) => {
            row.querySelectorAll('[name^="typography[links]"]').forEach(field => {
              field.name = field.name.replace(/typography\[links\]\[\d+\]/, 'typography[links][' + index + ']');
            });
          });
        });
        form.addEventListener('input', updatePreview);
        form.addEventListener('change', updatePreview);
        const fileInput=document.getElementById('header-logo-input');fileInput.addEventListener('change',()=>{const file=fileInput.files[0];if(!file)return;if(!['image/png','image/jpeg'].includes(file.type)){alert('Choose a PNG, JPG or JPEG image.');fileInput.value='';return;}const reader=new FileReader();reader.onload=()=>{const img=document.getElementById('preview-header-logo');img.src=reader.result;img.style.display='block';updatePreview();};reader.readAsDataURL(file);});
        updatePreview();
      }
    })();
    const paletteStyle=document.createElement('style');paletteStyle.textContent=".font-color-palette{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;margin-left:7px;padding:7px;border:1px solid rgba(220,174,187,.35);border-radius:9px;background:rgba(91,20,39,.22);color:var(--palette-color,#e8dce0);cursor:pointer;vertical-align:middle;transition:background .2s,border-color .2s,transform .2s}.font-color-palette:hover{background:rgba(130,30,57,.35);border-color:rgba(220,174,187,.7);transform:translateY(-1px)}.font-color-palette:focus-visible{outline:2px solid #d99aaa;outline-offset:2px}.font-color-palette svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.font-color-native{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}";document.head.append(paletteStyle);
    (() => {
      const tabs = [...document.querySelectorAll('[data-page-tab]')];
      if (!tabs.length) return;
      const activate = (tab, moveFocus = false) => {
        tabs.forEach(item => {
          const selected = item === tab;
          item.setAttribute('aria-selected', String(selected));
          item.tabIndex = selected ? 0 : -1;
          const panel = document.getElementById(item.getAttribute('aria-controls'));
          if (panel) panel.hidden = !selected;
        });
        if (moveFocus) tab.focus();
      };
      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', event => {
          let next = index;
          if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
          else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = tabs.length - 1;
          else return;
          event.preventDefault();
          activate(tabs[next], true);
        });
      });
    })();
    </script>
  <?php else: $info=$settings[$section]; ?>
    <section class="panel admin-panel"><div class="symbol" style="font-size:30px;color:var(--gold)"><?=e($info[2])?></div><h2 style="margin-top:12px"><?=e($info[0])?></h2><p class="muted"><?=e($info[1])?></p><div class="admin-note">This Settings tab is ready for its management controls. No existing records have been changed.</div></section>
  <?php endif; ?>
<?php endif;?>
<script>
(()=> {
  const toggle = document.getElementById('settings-menu-toggle');
  const submenu = document.getElementById('settings-submenu');
  if (!toggle || !submenu) return;
  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    const shouldExpand = !expanded;

    // Settings is the only active top-level item while its submenu is open.
    toggle.closest('.admin-nav')?.querySelectorAll(':scope > a').forEach(item => {
      item.classList.remove('active');
    });
    toggle.classList.toggle('active', shouldExpand);
    toggle.setAttribute('aria-expanded', String(shouldExpand));
    submenu.classList.toggle('is-open', shouldExpand);
  });
})();

(()=>{['account-status-modal','header-settings-success-modal'].forEach(id=>{const modal=document.getElementById(id);if(!modal)return;const closeButton=modal.querySelector('button');const close=()=>modal.remove();closeButton?.addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close();});document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});closeButton?.focus();});})();
</script>
</main></div></body></html>
