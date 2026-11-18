<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rich-content.php';

// Public pages must always read the latest saved header configuration, not a cached HTML response.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Load the header settings configured by the System Administrator.
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
  logo_width SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  logo_height SMALLINT UNSIGNED NOT NULL DEFAULT 70,
  title_html LONGTEXT NULL,
  hidden_left_html LONGTEXT NULL,
  hidden_right_html LONGTEXT NULL,
  typography_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT IGNORE INTO site_header_settings (id,header_title,header_links,hidden_left,hidden_right) VALUES (1,'Z.C. commonplace','[]','ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY','Local perspectives. Shared with care.')");
foreach (['logo_width'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 200','logo_height'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 70','title_html'=>'LONGTEXT NULL','hidden_left_html'=>'LONGTEXT NULL','hidden_right_html'=>'LONGTEXT NULL','typography_json'=>'LONGTEXT NULL'] as $column=>$definition) {
    $exists=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='site_header_settings' AND COLUMN_NAME=?");
    $exists->execute([$column]);
    if (!(int)$exists->fetchColumn()) $pdo->exec("ALTER TABLE site_header_settings ADD COLUMN {$column} {$definition}");
}
$databaseHeaderSettings = $pdo->query("SELECT * FROM site_header_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];

// The database row is the single source of truth shared by Page Management
// and the public homepage. This prevents stale embedded/published values in
// index.php from overriding the latest settings saved by the administrator.

$publicHeader = $databaseHeaderSettings;
$defaultSections = [
 'banner'=>['main_title'=>'One city. Many stories.','heading'=>'Zamboanga, through our eyes.','description'=>"<p>A digital home for the city's history, culture, creative talent, community news, marketplace, entertainment and public conversation.</p>",'button_label'=>'Explore our heritage','button_url'=>'#zamboanga','button_color'=>'#b8323f','button_text_color'=>'#ffffff','button_size'=>'14','button_width'=>'auto','button_height'=>'42','photo'=>''],
 'slideshow'=>['main_title'=>'A LIVING HERITAGE','heading'=>'Discover the story of Zamboanga City','subtitle'=>'From historic landmarks to maritime traditions and a vibrant, multilingual culture.','photos'=>[
 ['title'=>'HERITAGE LANDMARK · 17TH CENTURY','heading'=>'Fort Pilar','description'=>'<p>A landmark shaped by centuries of history, faith and city life.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_(NS_Valderoza,_Zamboanga_City;_10-07-2023).jpg?width=1800'],
 ['title'=>'THE CITY BY THE SEA','heading'=>'Life along the waterfront','description'=>'<p>The sea connects Zamboanga communities and neighboring islands.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_Paseo_Del_Mar_bay_view_sunrise_(NS_Valderosa,_Zamboanga_City;_10-11-2023).jpg?width=1800'],
 ['title'=>'MARITIME TRADITION','heading'=>'The colors of the vinta','description'=>'<p>The vinta is an enduring symbol of regional maritime traditions.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Vinta_at_Zamboanga_City_Tourism_Office,_Mar_2026.jpg?width=1600'],
 ['title'=>'ARTISANSHIP · LIVING CULTURE','heading'=>'Patterns that carry stories','description'=>'<p>Traditional weaving reflects generations of skill and cultural knowledge.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Yakan_Weavers.jpg?width=1800']]],
 'mega_menu'=>['main_title'=>'EXPLORE THE PLATFORM','heading'=>'Five ways to connect','description'=>'A single community space for creativity, information, commerce and cultural memory.','panels'=>[]]
];
$pdo->exec("CREATE TABLE IF NOT EXISTS site_sections_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY,content_json LONGTEXT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$sectionInsert=$pdo->prepare("INSERT IGNORE INTO site_sections_settings (id,content_json) VALUES (1,?)");
$sectionInsert->execute([json_encode($defaultSections,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
$sectionRow=$pdo->query("SELECT content_json FROM site_sections_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$sectionsSettings=json_decode((string)($sectionRow['content_json']??''),true);
if(!is_array($sectionsSettings)) $sectionsSettings=$defaultSections;
foreach($defaultSections as $key=>$defaults){$stored=$sectionsSettings[$key]??[];$sectionsSettings[$key]=is_array($stored)?array_replace($defaults,$stored):$defaults;}
$sectionImageSrc=static fn(string $path): string => preg_match('~^https?://~i',$path)?$path:'public/'.ltrim($path,'/');

// Render administrator-authored banner formatting while retaining only safe
// text-formatting tags and a narrow set of inline typography declarations.
$renderBannerRichText = static function(string $html): string {
    $allowedTags = '<span><strong><b><em><i><u><s><br><p><ul><ol><li><h2><h3><h4><blockquote><a><table><tbody><tr><th><td>';
    $html = strip_tags($html, $allowedTags);
    $safeStyle = static function(string $attributes): string {
        if (!preg_match('/style\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i', $attributes, $match)) return '';
        $rules = [];
        foreach (explode(';', $match[1]) as $declaration) {
            if (!str_contains($declaration, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $valid = false;
            if ($property === 'color') $valid = (bool)preg_match('/^(#[0-9a-f]{3,8}|rgba?\\([0-9.,% ]+\\)|[a-z]{3,20})$/i', $value);
            elseif ($property === 'font-size') $valid = (bool)preg_match('/^(?:[8-9]|[1-6][0-9]|7[0-2])(?:\\.[0-9]+)?px$/i', $value);
            elseif ($property === 'font-family') $valid = (bool)preg_match('/^[a-z0-9 ,\\\'-]{1,80}$/i', $value);
            elseif ($property === 'font-weight') $valid = in_array(strtolower($value), ['normal','bold','400','500','600','700','800','900'], true);
            elseif ($property === 'font-style') $valid = in_array(strtolower($value), ['normal','italic','oblique'], true);
            elseif ($property === 'text-align') $valid = in_array(strtolower($value), ['left','right','center','justify'], true);
            elseif ($property === 'text-decoration') $valid = (bool)preg_match('/^(none|underline|line-through|overline)(\\s+(underline|line-through|overline))*$/i', $value);
            elseif ($property === 'line-height') $valid = (bool)preg_match('/^(?:[0-9]+(?:\\.[0-9]+)?|[0-9]+(?:\\.[0-9]+)?px|[0-9]+(?:\\.[0-9]+)?%)$/i', $value);
            elseif ($property === 'letter-spacing') $valid = (bool)preg_match('/^-?[0-9]+(?:\\.[0-9]+)?px$/i', $value);
            if ($valid) $rules[] = $property . ':' . $value . ' !important';
        }
        return $rules ? ' style="' . e(implode(';', $rules)) . '"' : '';
    };
    $html = preg_replace_callback('/<([a-z0-9]+)\\b([^>]*)>/i', static function(array $match) use ($safeStyle): string {
        $tag = strtolower($match[1]);
        if ($tag === 'br') return '<br>';
        $style = $safeStyle($match[2]);
        if ($tag === 'a') {
            $href = '';
            if (preg_match('/href\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i', $match[2], $link) && preg_match('~^(https?://|/|#|\\?|mailto:)~i', $link[1])) {
                $href = ' href="' . e($link[1]) . '" target="_blank" rel="noopener noreferrer"';
            }
            return '<a' . $href . $style . '>';
        }
        return '<' . $tag . $style . '>';
    }, $html);
    return (string)$html;
};


// Load the administrator-managed footer content from the same database row.
$pdo->exec("CREATE TABLE IF NOT EXISTS site_footer_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  content_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$defaultPublicFooter = json_decode('{"brand":"Z.C. commonplace","description":"Zamboanga stories, culture, creativity and community — shared with care.","copyright":"© {year} Z.C. commonplace. Community publishing with administrator review.","explore_title":"Explore","explore_links":[{"label":"Creative Arts and Photography","url":"?category=creative-arts-photography#latest"},{"label":"News Connect","url":"?category=news-connect#latest"},{"label":"Marketplace","url":"?category=marketplace#latest"}],"discover_title":"Discover","discover_links":[{"label":"Entertainment & Politics","url":"?category=entertainment-politics#latest"},{"label":"History & Culture","url":"?category=history-culture#latest"},{"label":"Zamboanga heritage slideshow","url":"#zamboanga"}]}', true) ?: [];
$defaultFooterJson = json_encode($defaultPublicFooter, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$footerInsert = $pdo->prepare("INSERT IGNORE INTO site_footer_settings (id, content_json) VALUES (1, ?)");
$footerInsert->execute([$defaultFooterJson]);
$footerRow = $pdo->query("SELECT content_json FROM site_footer_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$publicFooter = json_decode((string)($footerRow['content_json'] ?? ''), true);
if (!is_array($publicFooter)) $publicFooter = [];
$publicFooter = array_replace($defaultPublicFooter, $publicFooter);

$publicHeaderTitle = trim((string)($publicHeader['header_title'] ?? ''));
$publicHeaderLinks = json_decode((string)($publicHeader['header_links'] ?? '[]'), true);
if (!is_array($publicHeaderLinks)) $publicHeaderLinks = [];
$publicHeaderFamily = in_array(($publicHeader['font_family'] ?? 'Ubuntu'), ['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'], true) ? $publicHeader['font_family'] : 'Ubuntu';
$publicHeaderStyle = ($publicHeader['font_style'] ?? 'normal') === 'italic' ? 'italic' : 'normal';
$publicHeaderSize = max(12, min(32, (int)($publicHeader['font_size'] ?? 20)));
$publicHeaderWeight = in_array((int)($publicHeader['font_weight'] ?? 700), [300,400,500,600,700,800], true) ? (int)$publicHeader['font_weight'] : 700;
$publicTypography = json_decode((string)($publicHeader['typography_json'] ?? '{}'),true) ?: [];
$publicTitleTypography = $publicTypography['title'] ?? ['family'=>$publicHeaderFamily,'style'=>$publicHeaderStyle,'size'=>$publicHeaderSize,'weight'=>$publicHeaderWeight,'color'=>'#f7eef0'];
$publicLeftTypography = $publicTypography['hidden_left'] ?? ['family'=>'Ubuntu','style'=>'normal','size'=>10,'weight'=>400,'color'=>'#cdbdc2'];
$publicRightTypography = $publicTypography['hidden_right'] ?? ['family'=>'Ubuntu','style'=>'normal','size'=>10,'weight'=>400,'color'=>'#cdbdc2'];
$headerTextStyle = static function(array $t): string {
    $family=in_array(($t['family']??'Ubuntu'),['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'],true)?$t['family']:'Ubuntu';
    $style=($t['style']??'normal')==='italic'?'italic':'normal';
    $size=max(8,min(40,(int)($t['size']??13)));
    $weight=in_array((int)($t['weight']??400),[300,400,500,600,700,800],true)?(int)$t['weight']:400;
    $color=preg_match('/^#[0-9a-f]{6}$/i',(string)($t['color']??''))?$t['color']:'#eadde1';
    $format=(string)($t['format']??(($weight>=700)?'bold':(($style==='italic')?'italic':'normal')));
    $formatParts=array_values(array_intersect(['bold','italic','underline'],preg_split('/[ ,]+/',$format,-1,PREG_SPLIT_NO_EMPTY)?:[]));$format=implode(' ',$formatParts);
    $formatParts=preg_split('/[ ,]+/',$format,-1,PREG_SPLIT_NO_EMPTY)?:[];$fontStyle=in_array('italic',$formatParts,true)?'italic':'normal';$fontWeight=in_array('bold',$formatParts,true)?700:$weight;$decoration=in_array('underline',$formatParts,true)?'underline':'none';
    return 'font-family:'.htmlspecialchars((string)$family,ENT_QUOTES,'UTF-8').';font-style:'.$fontStyle.';font-size:'.$size.'px;font-weight:'.$fontWeight.';text-decoration:'.$decoration.';color:'.$color;
};
$headerTypographyImportant = static function(array $t) use ($headerTextStyle): string {
    $inline = $headerTextStyle($t);
    return preg_replace('/;/', ' !important;', $inline) . ' !important';
};
$publicHeaderTypographyCss = '.header-type-title{'.$headerTypographyImportant($publicTitleTypography).'}'
    . '.header-type-left{'.$headerTypographyImportant($publicLeftTypography).'}'
    . '.header-type-right{'.$headerTypographyImportant($publicRightTypography).'}';
// Header links intentionally inherit the same typography as the Sign in link.
$renderHeaderRich = static function($html,$fallback): string {
    $value=trim((string)$html);
    if($value==='') return e((string)$fallback);
    $value=strip_tags($value,'<span><b><strong><i><em><u>');
    $value=preg_replace_callback('/<span\\b([^>]*)>/i',static function($m){
        $safe='';
        if(preg_match('/style\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i',$m[1],$sm)){
            $rules=[];
            foreach(explode(';',$sm[1]) as $rule){if(!str_contains($rule,':'))continue;[$k,$v]=array_map('trim',explode(':',$rule,2));
                if($k==='color'&&preg_match('/^#[0-9a-f]{3,8}$/i',$v))$rules[]='color:'.$v;
                if($k==='font-size'&&preg_match('/^(?:[8-9]|[1-3][0-9]|40)px$/',$v))$rules[]='font-size:'.$v;
                if($k==='font-weight'&&in_array($v,['300','400','500','600','700','800','bold','normal'],true))$rules[]='font-weight:'.$v;
                if($k==='font-style'&&in_array($v,['normal','italic'],true))$rules[]='font-style:'.$v;if($k==='font-family'&&in_array(trim($v),['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'],true))$rules[]='font-family:'.trim($v);if($k==='text-decoration'&&in_array($v,['none','underline'],true))$rules[]='text-decoration:'.$v;
            }if($rules)$safe=' style="'.implode(';',$rules).'"';
        }return '<span'.$safe.'>';
    },$value);
    return preg_replace('/<(b|strong|i|em|u)\\b[^>]*>/i','<$1>',$value);
};
$publicHeaderLogoX = max(0, min(100, (int)($publicHeader['logo_position_x'] ?? 50)));
$publicHeaderLogoY = max(0, min(100, (int)($publicHeader['logo_position_y'] ?? 50)));
$publicHeaderLogoWidth = max(50, min(200, (int)($publicHeader['logo_width'] ?? 200)));
$publicHeaderLogoHeight = max(30, min(120, (int)($publicHeader['logo_height'] ?? 70)));

// Support profile-photo fields when present in existing installations without
// requiring a database migration for sites that have not enabled photo uploads.
$creatorPhotoColumn = '';
foreach (['avatar_path','profile_photo_path','profile_photo','profile_image','avatar'] as $candidatePhotoColumn) {
    $photoColumnCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'users\' AND COLUMN_NAME=?');
    $photoColumnCheck->execute([$candidatePhotoColumn]);
    if ((int)$photoColumnCheck->fetchColumn() > 0) { $creatorPhotoColumn = $candidatePhotoColumn; break; }
}
$creatorPhotoSelect = $creatorPhotoColumn !== '' ? ', ' . $creatorPhotoColumn . ' AS creator_profile_photo' : ", NULL AS creator_profile_photo";

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
        $stmtLogin = $pdo->prepare("SELECT id,full_name,nickname,email,password_hash,role,account_status{$creatorPhotoSelect} FROM users WHERE mobile_no=? LIMIT 1");
        $stmtLogin->execute(['+63' . $digits]);
    } else {
        $stmtLogin = $pdo->prepare("SELECT id,full_name,nickname,email,password_hash,role,account_status{$creatorPhotoSelect} FROM users WHERE email=? LIMIT 1");
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
        $_SESSION['login_redirect'] = ($targetContext === 'admin' ? 'public/admin.php' : 'index.php?session_context=creator');
        header('Location: index.php?session_context=' . $targetContext);
        exit;
    }
}
$currentPublicUser = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
$isCreatorSignedIn = $currentPublicUser && (string)($currentPublicUser['role'] ?? '') === 'creator';
if ($isCreatorSignedIn && $creatorPhotoColumn !== '') {
    // Always read the latest avatar path so changes made in the Creator workspace
    // are reflected in the public-header account pill on the next page load.
    $latestCreatorPhotoStmt = $pdo->prepare("SELECT {$creatorPhotoColumn} AS creator_profile_photo FROM users WHERE id=? LIMIT 1");
    $latestCreatorPhotoStmt->execute([(int)($currentPublicUser['id'] ?? 0)]);
    $latestCreatorPhoto = $latestCreatorPhotoStmt->fetchColumn();
    $currentPublicUser['creator_profile_photo'] = is_string($latestCreatorPhoto) ? $latestCreatorPhoto : '';
    $_SESSION['user']['creator_profile_photo'] = $currentPublicUser['creator_profile_photo'];
}
$creatorHeaderNickname = $isCreatorSignedIn ? trim((string)($currentPublicUser['nickname'] ?? '')) : '';
if ($isCreatorSignedIn && $creatorHeaderNickname === '') $creatorHeaderNickname = trim((string)($currentPublicUser['full_name'] ?? 'Creator'));
$search = trim((string)($_GET['q'] ?? ''));
$requestedForumPanelId = trim((string)($_GET['forum_panel_id'] ?? ''));
$activeForumPanel = null;
if ($requestedForumPanelId !== '' && preg_match('/^[a-f0-9]{32}$/i', $requestedForumPanelId)) {
    foreach ((array)($sectionsSettings['mega_menu']['panels'] ?? []) as $configuredForumPanel) {
        if (is_array($configuredForumPanel) && hash_equals(strtolower((string)($configuredForumPanel['panel_id'] ?? '')), strtolower($requestedForumPanelId))) {
            $activeForumPanel = $configuredForumPanel;
            $requestedForumPanelId = (string)$configuredForumPanel['panel_id'];
            break;
        }
    }
}
$moduleSlug = trim((string)($_GET['module'] ?? ''));
if ($activeForumPanel && $moduleSlug === '') {
    $moduleSlug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', strtolower((string)($activeForumPanel['title'] ?? 'forum'))), '-');
}
$moduleView = $moduleSlug !== '' || $activeForumPanel !== null;
$category = trim((string)($_GET['category'] ?? ''));
// Defensive, idempotent upgrade for databases created before optional subcategories
// were introduced. Keep this immediately before the public query as well as the
// shared bootstrap migration so older local installations cannot fatal here.
$subcategoryColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='content' AND COLUMN_NAME='subcategory_id'");
$subcategoryColumnCheck->execute();
if (!(int)$subcategoryColumnCheck->fetchColumn()) {
    $pdo->exec('ALTER TABLE content ADD COLUMN subcategory_id BIGINT UNSIGNED NULL AFTER category_id');
    $pdo->exec('ALTER TABLE content ADD INDEX idx_content_subcategory (subcategory_id)');
}
$sql = "SELECT c.*, cat.name AS category_name, cat.slug AS category_slug, sub.name AS subcategory_name, u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id JOIN users u ON u.id=c.owner_id WHERE c.status='published' AND cat.is_active=1";
$params = [];
if ($search !== '') { $sql .= " AND (c.title LIKE ? OR c.excerpt LIKE ? OR c.body LIKE ?)"; $like = "%$search%"; array_push($params,$like,$like,$like); }
$categories=$pdo->query("SELECT id,name,slug,description FROM categories WHERE is_active=1 ORDER BY id")->fetchAll();
$modules = [
 ['name'=>'Creative Arts and Photography','slug'=>'creative-arts-photography','symbol'=>'✳','description'=>'Photography, visual art, design and local creative voices.'],
 ['name'=>'News Connect','slug'=>'news-connect','symbol'=>'◎','description'=>'Community updates, civic stories and current affairs.'],
 ['name'=>'Marketplace','slug'=>'marketplace','symbol'=>'◇','description'=>'Discover products, local makers, services and listings.'],
 ['name'=>'Entertainment & Politics','slug'=>'entertainment-politics','symbol'=>'♫','description'=>'Entertainment, public figures, political discussion and analysis.'],
 ['name'=>'History & Culture','slug'=>'history-culture','symbol'=>'⌂','description'=>'Zamboanga heritage, Chavacano identity and living traditions.'],
];
$activeModule = null;
$moduleCategoryFound = false;
if ($moduleView) {
    foreach ($categories as $publicCategory) {
        if ((string)$publicCategory['slug'] === $moduleSlug) {
            $activeModule = $publicCategory;
            $category = (string)$publicCategory['slug'];
            $moduleCategoryFound = true;
            break;
        }
    }
    if (!$activeModule) {
        foreach ($modules as $knownModule) {
            if ((string)$knownModule['slug'] === $moduleSlug) { $activeModule = $knownModule; break; }
        }
    }
    // Custom panel URLs may use a module slug that is not a category slug.
    // Keep the module page valid, but do not accidentally show unrelated stories.
    if (!$activeModule) {
        $activeModule = ['name'=>ucwords(str_replace(['-','_'], ' ', $moduleSlug)), 'description'=>''];
    }
}
$sql = "SELECT c.*, cat.name AS category_name, cat.slug AS category_slug, sub.name AS subcategory_name, u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id JOIN users u ON u.id=c.owner_id WHERE c.status='published' AND cat.is_active=1";
$params = [];
if ($search !== '') { $sql .= " AND (c.title LIKE ? OR c.excerpt LIKE ? OR c.body LIKE ?)"; $like = "%$search%"; array_push($params,$like,$like,$like); }
if ($moduleView) {
    if ($moduleCategoryFound) { $sql .= " AND cat.slug=?"; $params[] = $category; }
    else { $sql .= " AND 1=0"; }
} elseif ($category !== '') { $sql .= " AND cat.slug=?"; $params[] = $category; }
$showAllStories = $moduleView || (string)($_GET['show_all'] ?? '') === '1';
$sql .= " ORDER BY COALESCE(c.published_at,c.created_at) DESC" . ($showAllStories ? "" : " LIMIT 6");
$stmt=$pdo->prepare($sql); $stmt->execute($params); $items=$stmt->fetchAll();

$publicForumThreads = [];
$publicForumDetail = null;
$publicForumReplies = [];
$publicForumAttachments = [];
$publicForumReplyCount = 0;
$publicForumViewCount = 0;
$publicForumLikeCount = 0;
$publicForumLikedByCurrentUser = false;
$publicForumThreadReactionCounts = [];
$publicForumCurrentReaction = '';
$publicForumLastActivity = null;
$publicForumNotice = '';
$publicForumError = '';
$publicForumThreadId = max(0, (int)($_GET['thread_id'] ?? $_POST['thread_id'] ?? 0));
$publicForumAvatarUrl = static function(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    $path = ltrim(preg_replace('~^(?:public/)+~', '', $path), '/');
    return 'public/' . $path;
};
if ($activeForumPanel && $requestedForumPanelId !== '') {
    $forumTableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forum_threads'");
    $forumTableCheck->execute();
    if ((int)$forumTableCheck->fetchColumn() > 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_thread_replies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            parent_reply_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_forum_replies_thread_created(thread_id,created_at),
            INDEX idx_forum_replies_parent(parent_reply_id),
            INDEX idx_forum_replies_owner(owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $replyParentColumn=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forum_thread_replies' AND COLUMN_NAME='parent_reply_id'");
        $replyParentColumn->execute();
        if(!(int)$replyParentColumn->fetchColumn())$pdo->exec("ALTER TABLE forum_thread_replies ADD COLUMN parent_reply_id BIGINT UNSIGNED NULL, ADD INDEX idx_forum_replies_parent(parent_reply_id)");
        $replyBodyColumn=$pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forum_thread_replies' AND COLUMN_NAME='body'");$replyBodyColumn->execute();if(strtolower((string)$replyBodyColumn->fetchColumn())!=='mediumtext')$pdo->exec("ALTER TABLE forum_thread_replies MODIFY body MEDIUMTEXT NOT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_thread_likes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_forum_thread_like(thread_id,user_id),
            INDEX idx_forum_likes_thread(thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_reply_likes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reply_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_forum_reply_like(reply_id,user_id),
            INDEX idx_forum_reply_likes_reply(reply_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['forum_thread_likes' => 'thread_id', 'forum_reply_likes' => 'reply_id'] as $likeTable => $likeTargetColumn) {
            $reactionColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='reaction'");
            $reactionColumn->execute([$likeTable]);
            if (!(int)$reactionColumn->fetchColumn()) $pdo->exec("ALTER TABLE `{$likeTable}` ADD COLUMN reaction VARCHAR(16) NOT NULL DEFAULT '👍' AFTER user_id");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            target_type ENUM('thread','reply') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(40) NOT NULL,
            details TEXT NULL,
            status ENUM('pending','reviewed','dismissed','actioned') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_forum_report_once(target_type,target_id,reporter_id),
            INDEX idx_forum_reports_status_created(status,created_at),
            INDEX idx_forum_reports_thread(thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_thread_views (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            visitor_key CHAR(64) NOT NULL,
            viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_forum_thread_view(thread_id,visitor_key),
            INDEX idx_forum_views_thread(thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Read Creator identity from whichever user-column convention this database uses.
        // Older installations use firstname/lastname/suffix; newer profile pages use
        // first_name/last_name/name_suffix. Prefer populated values, not merely existing columns.
        $publicForumUserColumns = [];
        $publicForumColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME=?");
        foreach (['firstname','first_name','lastname','last_name','suffix','name_suffix'] as $columnName) {
            $publicForumColumnCheck->execute([$columnName]);
            $publicForumUserColumns[$columnName] = (int)$publicForumColumnCheck->fetchColumn() > 0;
        }
        $publicForumIdentitySql = static function(string $tableAlias, string $identityField) use ($publicForumUserColumns): string {
            $candidates = [
                'first_name' => ['firstname','first_name'],
                'last_name' => ['lastname','last_name'],
                'name_suffix' => ['suffix','name_suffix'],
            ][$identityField] ?? [];
            $parts = [];
            foreach ($candidates as $columnName) {
                if (!empty($publicForumUserColumns[$columnName])) {
                    $parts[] = "NULLIF(TRIM({$tableAlias}.{$columnName}), '')";
                }
            }
            return $parts ? 'COALESCE(' . implode(',', $parts) . ')' : 'NULL';
        };
        $forumFirstNameU = $publicForumIdentitySql('u', 'first_name');
        $forumLastNameU = $publicForumIdentitySql('u', 'last_name');
        $forumSuffixU = $publicForumIdentitySql('u', 'name_suffix');
        $forumFirstNameLu = $publicForumIdentitySql('lu', 'first_name');
        $forumLastNameLu = $publicForumIdentitySql('lu', 'last_name');
        $forumSuffixLu = $publicForumIdentitySql('lu', 'name_suffix');
        $creatorAvatarSelect = $creatorPhotoColumn !== '' ? ', u.' . $creatorPhotoColumn . ' AS author_avatar' : ', NULL AS author_avatar';
        $publicForumDisplayName = static function(array $user): string {
            $first = trim((string)($user['first_name'] ?? ''));
            $last = trim((string)($user['last_name'] ?? ''));
            $suffix = trim((string)($user['name_suffix'] ?? ''));
            $suffix = trim($suffix, " \t\n\r\0\x0B.,");
            if ($first !== '' || $last !== '') {
                return trim(implode(' ', array_filter([$first, $last, $suffix], static fn($part) => $part !== '')));
            }
            $legacy = trim((string)($user['full_name'] ?? ''));
            if (str_contains($legacy, ',')) {
                [$legacyLast, $legacyGiven] = array_pad(explode(',', $legacy, 2), 2, '');
                $givenParts = preg_split('/\\s+/', trim($legacyGiven)) ?: [];
                $first = (string)($givenParts[0] ?? '');
                $possibleSuffix = trim((string)end($givenParts), " \\t\\n\\r\\0\\x0B.,");
                if (in_array(strtolower($possibleSuffix), ['jr', 'sr', 'ii', 'iii', 'iv', 'v'], true)) $suffix = $possibleSuffix;
                return trim(implode(' ', array_filter([$first, trim($legacyLast, " \\t\\n\\r\\0\\x0B.,") , $suffix], static fn($part) => $part !== '')));
            }
            return trim(preg_replace('/[,.]+/', '', $legacy) ?? $legacy);
        };
        $sanitizeForumReplyHtml = static function(string $html): string {
            $allowed=['p','br','strong','b','em','i','u','s','strike','font','span','div','ul','ol','li','a','blockquote','pre','code','table','thead','tbody','tr','th','td','img'];
            if(!class_exists('DOMDocument'))return nl2br(e(strip_tags($html)));
            $old=libxml_use_internal_errors(true);$doc=new DOMDocument('1.0','UTF-8');$doc->loadHTML('<?xml encoding="UTF-8"><div id="reply-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($old);$root=$doc->getElementsByTagName('div')->item(0);if(!$root)return '';
            $clean=function(DOMNode $node)use(&$clean,$allowed,$doc):void{for($child=$node->firstChild;$child;){$next=$child->nextSibling;if($child instanceof DOMElement){$tag=strtolower($child->tagName);if(!in_array($tag,$allowed,true)){while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);$child=$next;continue;}$names=[];foreach($child->attributes as $attr)$names[]=$attr->name;foreach($names as $name){$value=trim($child->getAttribute($name));$keep=($tag==='a'&&$name==='href'&&preg_match('~^(https?://|mailto:)~i',$value))||($tag==='img'&&$name==='src'&&preg_match('~^public/uploads/forum_replies/[a-zA-Z0-9._-]+$~',$value))||($tag==='img'&&$name==='alt')||($tag==='font'&&in_array($name,['size','color'],true)&&($name!=='color'||preg_match('/^#[0-9a-f]{6}$/i',$value)))||($name==='style'&&in_array($tag,['span','p','div','th','td','img'],true));if(!$keep)$child->removeAttribute($name);}if($child->hasAttribute('style')){$safe=[];foreach(explode(';',$child->getAttribute('style')) as $decl){$pair=explode(':',$decl,2);if(count($pair)!==2)continue;$prop=strtolower(trim($pair[0]));$val=trim($pair[1]);if(in_array($prop,['color','font-size','font-weight','font-style','text-decoration','text-align'],true)&&!preg_match('/url|expression|[<>]/i',$val))$safe[]=$prop.':'.$val;elseif($tag==='div'&&in_array($prop,['width','height','max-width','min-width','min-height'],true)&&preg_match('/^(?:\\d+(?:\\.\\d+)?px|100%)$/i',$val))$safe[]=$prop.':'.$val;elseif($tag==='div'&&$prop==='resize'&&$val==='both')$safe[]='resize:both';elseif($tag==='div'&&$prop==='overflow'&&$val==='auto')$safe[]='overflow:auto';elseif($tag==='div'&&$prop==='display'&&$val==='inline-block')$safe[]='display:inline-block';elseif($tag==='div'&&$prop==='vertical-align'&&$val==='middle')$safe[]='vertical-align:middle';elseif($tag==='img'&&in_array($prop,['width','height'],true)&&preg_match('/^\\d+(?:px|%)?$/i',$val))$safe[]=$prop.':'.$val;elseif($tag==='img'&&$prop==='object-fit'&&$val==='contain')$safe[]='object-fit:contain';elseif($tag==='img'&&$prop==='display'&&$val==='block')$safe[]='display:block';}$child->setAttribute('style',implode(';',$safe));if(!$safe)$child->removeAttribute('style');}$clean($child);}elseif(!($child instanceof DOMText))$node->removeChild($child);$child=$next;}};
            $clean($root);$out='';foreach($root->childNodes as $child)$out.=$doc->saveHTML($child);return trim($out);
        };
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['forum_action'] ?? ''), ['reply','like','unlike','reply_like','reply_unlike','report_thread','report_reply'], true)) {
            try {
                verify_csrf();
                $postedThreadId=max(0,(int)($_POST['thread_id']??0));
                $threadExists=$pdo->prepare("SELECT id FROM forum_threads WHERE id=? AND panel_id=? AND status='published' LIMIT 1");
                $threadExists->execute([$postedThreadId,$requestedForumPanelId]);
                if(!$threadExists->fetchColumn()) throw new RuntimeException('This discussion is no longer available.');
                if(!$isCreatorSignedIn) throw new RuntimeException('Please sign in with your contributor account to continue.');
                $signedInUserId=(int)($currentPublicUser['id']??0);
                if($signedInUserId<1) throw new RuntimeException('Please sign in again to continue.');
                $forumAction=(string)$_POST['forum_action'];
                if($forumAction==='reply'){
                    $replyBody=$sanitizeForumReplyHtml(trim((string)($_POST['reply_body']??'')));$parentReplyId=max(0,(int)($_POST['parent_reply_id']??0));
                    if($replyBody==='')throw new RuntimeException('Write your reply before posting.');
                    if(mb_strlen(strip_tags($replyBody))>20000)throw new RuntimeException('Your reply must be 20,000 characters or fewer.');
                    $files=$_FILES['reply_photos']??null;if(is_array($files)&&isset($files['name'])&&is_array($files['name'])){$dir=__DIR__.'/public/uploads/forum_replies';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Reply photo folder could not be created.');$count=0;foreach($files['name'] as $i=>$name){$err=$files['error'][$i]??UPLOAD_ERR_NO_FILE;if($err===UPLOAD_ERR_NO_FILE)continue;if($err!==UPLOAD_ERR_OK)throw new RuntimeException('A reply photo could not be uploaded.');if(++$count>5)throw new RuntimeException('You may attach up to five photos per reply.');$tmp=$files['tmp_name'][$i]??'';$size=(int)($files['size'][$i]??0);if($size<1||$size>8*1024*1024)throw new RuntimeException('Each reply photo must be 8 MB or smaller.');$mime=@getimagesize($tmp)['mime']??'';$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/avif'=>'avif','image/webp'=>'webp'];if(!isset($ext[$mime]))throw new RuntimeException('Reply photos must be JPEG, JPG, PNG, AVIF, or WEBP images.');$file='reply_'.$signedInUserId.'_'.bin2hex(random_bytes(12)).'.'.$ext[$mime];if(!move_uploaded_file($tmp,$dir.'/'.$file))throw new RuntimeException('A reply photo could not be saved.');$photoTag='<img src="public/uploads/forum_replies/'.$file.'" alt="Reply photo" style="width:100%;height:100%;object-fit:contain;display:block">';$replyBody=str_replace('[[FORUM_REPLY_PHOTO_'.$i.']]', $photoTag, $replyBody);}}
                    if(mb_strlen($replyBody)>60000)throw new RuntimeException('Your formatted reply is too large.');
                    if($parentReplyId>0){$check=$pdo->prepare('SELECT id FROM forum_thread_replies WHERE id=? AND thread_id=? LIMIT 1');$check->execute([$parentReplyId,$postedThreadId]);if(!$check->fetchColumn())throw new RuntimeException('The reply you are responding to is no longer available.');$stmt=$pdo->prepare('INSERT INTO forum_thread_replies(thread_id,owner_id,parent_reply_id,body) VALUES(?,?,?,?)');$stmt->execute([$postedThreadId,$signedInUserId,$parentReplyId,$replyBody]);}
                    else{$stmt=$pdo->prepare('INSERT INTO forum_thread_replies(thread_id,owner_id,body) VALUES(?,?,?)');$stmt->execute([$postedThreadId,$signedInUserId,$replyBody]);}
                }elseif($forumAction==='like'){$reaction=(string)($_POST['reaction']??'👍');if(!in_array($reaction,['👍','❤️','😂','😮','😢','😡','🎉'],true))$reaction='👍';$stmt=$pdo->prepare('INSERT INTO forum_thread_likes(thread_id,user_id,reaction) VALUES(?,?,?) ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)');$stmt->execute([$postedThreadId,$signedInUserId,$reaction]);}
                elseif($forumAction==='unlike'){$stmt=$pdo->prepare('DELETE FROM forum_thread_likes WHERE thread_id=? AND user_id=?');$stmt->execute([$postedThreadId,$signedInUserId]);}
                elseif(in_array($forumAction,['reply_like','reply_unlike'],true)){
                    $targetReplyId=max(0,(int)($_POST['reply_id']??0));$check=$pdo->prepare('SELECT id FROM forum_thread_replies WHERE id=? AND thread_id=? LIMIT 1');$check->execute([$targetReplyId,$postedThreadId]);if(!$check->fetchColumn())throw new RuntimeException('This reply is no longer available.');
                    if($forumAction==='reply_like'){$reaction=(string)($_POST['reaction']??'👍');if(!in_array($reaction,['👍','❤️','😂','😮','😢','😡','🎉'],true))$reaction='👍';$stmt=$pdo->prepare('INSERT INTO forum_reply_likes(reply_id,user_id,reaction) VALUES(?,?,?) ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)');$stmt->execute([$targetReplyId,$signedInUserId,$reaction]);}
                    else{$stmt=$pdo->prepare('DELETE FROM forum_reply_likes WHERE reply_id=? AND user_id=?');$stmt->execute([$targetReplyId,$signedInUserId]);}
                }else{
                    $targetType=$forumAction==='report_thread'?'thread':'reply';$targetId=$targetType==='thread'?$postedThreadId:max(0,(int)($_POST['reply_id']??0));
                    if($targetType==='reply'){$check=$pdo->prepare('SELECT id FROM forum_thread_replies WHERE id=? AND thread_id=? LIMIT 1');$check->execute([$targetId,$postedThreadId]);if(!$check->fetchColumn())throw new RuntimeException('This reply is no longer available.');}
                    $reason=(string)($_POST['report_reason']??'');if(!in_array($reason,['malicious','unverified','community_rules','terms_of_service','other'],true))throw new RuntimeException('Choose a valid reason for this report.');
                    $details=trim((string)($_POST['report_details']??''));if(mb_strlen($details)>2000)throw new RuntimeException('Additional details must be 2,000 characters or fewer.');
                    $stmt=$pdo->prepare('INSERT IGNORE INTO forum_reports(target_type,target_id,thread_id,reporter_id,reason,details) VALUES(?,?,?,?,?,?)');$stmt->execute([$targetType,$targetId,$postedThreadId,$signedInUserId,$reason,$details!==''?$details:null]);
                    if(!$stmt->rowCount())throw new RuntimeException('You have already reported this item. Your existing report is available for moderator review.');
                    $publicForumNotice='Thank you. Your report has been submitted for moderator review.';
                }
                if(!in_array($forumAction,['report_thread','report_reply'],true)){$url='index.php?'.http_build_query(['forum_panel_id'=>$requestedForumPanelId,'thread_id'=>$postedThreadId,'session_context'=>'creator']);header('Location: '.$url.'#forum-thread-detail',true,303);exit;}
            }catch(Throwable $forumActionError){$publicForumError=$forumActionError->getMessage();}
        }
        if ($publicForumThreadId > 0) {
            $threadStmt = $pdo->prepare("SELECT t.id,t.prefix_group,t.prefix,t.title,t.description,t.created_at,t.updated_at,u.full_name,{$forumFirstNameU} AS first_name,{$forumLastNameU} AS last_name,{$forumSuffixU} AS name_suffix,u.nickname,u.role{$creatorAvatarSelect}
                FROM forum_threads t INNER JOIN users u ON u.id=t.owner_id
                WHERE t.id=? AND t.panel_id=? AND t.status='published' LIMIT 1");
            $threadStmt->execute([$publicForumThreadId,$requestedForumPanelId]);
            $publicForumDetail = $threadStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($publicForumDetail) {
                if ($isCreatorSignedIn) {
                    $attachmentStmt = $pdo->prepare('SELECT id,original_name,file_path,mime_type,file_size,attachment_type,created_at FROM forum_thread_attachments WHERE thread_id=? ORDER BY id ASC');
                    $attachmentStmt->execute([$publicForumThreadId]);
                    $publicForumAttachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $visitorKey = hash('sha256', session_id());
                $viewInsert = $pdo->prepare('INSERT IGNORE INTO forum_thread_views(thread_id,visitor_key) VALUES(?,?)');
                $viewInsert->execute([$publicForumThreadId,$visitorKey]);
                $viewCountStmt = $pdo->prepare('SELECT COUNT(*) FROM forum_thread_views WHERE thread_id=?');
                $viewCountStmt->execute([$publicForumThreadId]);
                $publicForumViewCount = (int)$viewCountStmt->fetchColumn();
                $replyCountStmt = $pdo->prepare('SELECT COUNT(*) FROM forum_thread_replies WHERE thread_id=?');
                $replyCountStmt->execute([$publicForumThreadId]);
                $publicForumReplyCount = (int)$replyCountStmt->fetchColumn();
                $likeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM forum_thread_likes WHERE thread_id=?');
                $likeCountStmt->execute([$publicForumThreadId]);
                $publicForumLikeCount = (int)$likeCountStmt->fetchColumn();
                $threadReactionStmt=$pdo->prepare('SELECT reaction,COUNT(*) AS reaction_count FROM forum_thread_likes WHERE thread_id=? GROUP BY reaction');
                $threadReactionStmt->execute([$publicForumThreadId]);
                foreach($threadReactionStmt->fetchAll(PDO::FETCH_ASSOC) as $reactionRow)$publicForumThreadReactionCounts[(string)$reactionRow['reaction']]=(int)$reactionRow['reaction_count'];
                if ($isCreatorSignedIn) {
                    $currentReactionStmt=$pdo->prepare('SELECT reaction FROM forum_thread_likes WHERE thread_id=? AND user_id=? LIMIT 1');
                    $currentReactionStmt->execute([$publicForumThreadId,(int)$currentPublicUser['id']]);
                    $publicForumCurrentReaction=(string)($currentReactionStmt->fetchColumn()?:'');
                    $publicForumLikedByCurrentUser=$publicForumCurrentReaction!=='';
                }
                $replyAvatarSelect = $creatorPhotoColumn !== '' ? ', u.' . $creatorPhotoColumn . ' AS author_avatar' : ', NULL AS author_avatar';
                $repliesStmt=$pdo->prepare("SELECT r.id,r.parent_reply_id,r.body,r.created_at,u.full_name,{$forumFirstNameU} AS first_name,{$forumLastNameU} AS last_name,{$forumSuffixU} AS name_suffix,u.nickname,u.role{$replyAvatarSelect},
                    (SELECT COUNT(*) FROM forum_reply_likes rl WHERE rl.reply_id=r.id) AS reply_like_count,
                    (SELECT reaction FROM forum_reply_likes rl WHERE rl.reply_id=r.id AND rl.user_id=? LIMIT 1) AS current_reaction
                    FROM forum_thread_replies r INNER JOIN users u ON u.id=r.owner_id WHERE r.thread_id=? ORDER BY r.created_at ASC,r.id ASC");
                $repliesStmt->execute([$isCreatorSignedIn?(int)$currentPublicUser['id']:0,$publicForumThreadId]);$publicForumReplies=$repliesStmt->fetchAll(PDO::FETCH_ASSOC);
                if($publicForumReplies){
                    $replyIds=array_map(static fn($row)=>(int)$row['id'],$publicForumReplies);
                    $replyPlaceholders=implode(',',array_fill(0,count($replyIds),'?'));
                    $reactionCountsStmt=$pdo->prepare("SELECT reply_id,reaction,COUNT(*) AS reaction_count FROM forum_reply_likes WHERE reply_id IN ({$replyPlaceholders}) GROUP BY reply_id,reaction");
                    $reactionCountsStmt->execute($replyIds);
                    $replyReactionCounts=[];
                    foreach($reactionCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $reactionRow)$replyReactionCounts[(int)$reactionRow['reply_id']][(string)$reactionRow['reaction']]=(int)$reactionRow['reaction_count'];
                    foreach($publicForumReplies as &$replyRow)$replyRow['reaction_counts']=$replyReactionCounts[(int)$replyRow['id']]??[];
                    unset($replyRow);
                }
                $replyRowsByParent=[];foreach($publicForumReplies as $row)$replyRowsByParent[(int)($row['parent_reply_id']??0)][]=$row;
                $publicForumRepliesFlat=[];$flattenForumReplies=static function(int $parentId,int $depth=0)use(&$flattenForumReplies,&$publicForumRepliesFlat,$replyRowsByParent):void{foreach($replyRowsByParent[$parentId]??[] as $row){$row['_depth']=$depth;$publicForumRepliesFlat[]=$row;$flattenForumReplies((int)$row['id'],$depth+1);}};
                $flattenForumReplies(0);if($publicForumReplies)$publicForumLastActivity=$publicForumReplies[count($publicForumReplies)-1];
            }
        }
        $publicForumSelectAvatar = $creatorPhotoColumn !== '' ? ', u.' . $creatorPhotoColumn . ' AS author_avatar' : ', NULL AS author_avatar';
        $lastReplyAvatarSql = $creatorPhotoColumn !== '' ? '(SELECT lu.' . $creatorPhotoColumn . ' FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1)' : 'NULL';
        $publicForumStmt = $pdo->prepare("SELECT t.id,t.prefix_group,t.prefix,t.title,t.created_at,t.updated_at,u.full_name,{$forumFirstNameU} AS first_name,{$forumLastNameU} AS last_name,{$forumSuffixU} AS name_suffix,u.nickname,u.role{$publicForumSelectAvatar},
            (SELECT COUNT(*) FROM forum_thread_replies r WHERE r.thread_id=t.id) AS reply_count,
            (SELECT COUNT(*) FROM forum_thread_views v WHERE v.thread_id=t.id) AS view_count,
            (SELECT COUNT(*) FROM forum_thread_likes l WHERE l.thread_id=t.id) AS like_count,
            (SELECT MAX(lr.created_at) FROM forum_thread_replies lr WHERE lr.thread_id=t.id) AS last_reply_at,
            {$lastReplyAvatarSql} AS last_reply_avatar,
            (SELECT lu.nickname FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1) AS last_reply_nickname,
            (SELECT lu.full_name FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1) AS last_reply_full_name,
            (SELECT {$forumFirstNameLu} FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1) AS last_reply_first_name,
            (SELECT {$forumLastNameLu} FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1) AS last_reply_last_name,
            (SELECT {$forumSuffixLu} FROM forum_thread_replies lr INNER JOIN users lu ON lu.id=lr.owner_id WHERE lr.thread_id=t.id ORDER BY lr.created_at DESC,lr.id DESC LIMIT 1) AS last_reply_name_suffix
            FROM forum_threads t INNER JOIN users u ON u.id=t.owner_id
            WHERE t.panel_id=? AND t.status='published'
            ORDER BY COALESCE(t.updated_at,t.created_at) DESC,t.id DESC");
        $publicForumStmt->execute([$requestedForumPanelId]);
        $publicForumThreads = $publicForumStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$publicForumPrefixPresentation = static function(string $group, string $prefix): array {
    $groupKey = strtolower(trim($group));
    $prefixKey = strtolower(trim($prefix));
    $groupStyles = [
        'movies' => ['class' => 'group-movies', 'icon' => '🎬'],
        'video' => ['class' => 'group-video', 'icon' => '🎥'],
        'genre' => ['class' => 'group-genre', 'icon' => '🏷️'],
    ];
    if (isset($groupStyles[$groupKey])) return $groupStyles[$groupKey];
    $prefixStyles = [
        'shared' => ['class' => 'prefix-shared', 'icon' => '↗'],
        'tips' => ['class' => 'prefix-tips', 'icon' => '💡'],
        'tutorial' => ['class' => 'prefix-tutorial', 'icon' => '📘'],
        'info' => ['class' => 'prefix-info', 'icon' => 'ℹ'],
        'dead link' => ['class' => 'prefix-dead-link', 'icon' => ''],
        'granted' => ['class' => 'prefix-granted', 'icon' => '✓'],
        'get' => ['class' => 'prefix-get', 'icon' => '↓'],
        'request' => ['class' => 'prefix-request', 'icon' => ''],
        'google drive' => ['class' => 'prefix-google-drive', 'icon' => '◈'],
        'mediafire' => ['class' => 'prefix-mediafire', 'icon' => '☁'],
        'mega' => ['class' => 'prefix-mega', 'icon' => 'M'],
        'terabox' => ['class' => 'prefix-terabox', 'icon' => '▣'],
        'direct link' => ['class' => 'prefix-direct-link', 'icon' => ''],
    ];
    return $prefixStyles[$prefixKey] ?? ['class' => 'prefix-default', 'icon' => ''];
};
$publicForumAttachmentUrl = static function(string $path): string {
    $path = trim($path);
    if (preg_match('~^https?://~i', $path)) return $path;
    return 'public/' . ltrim(preg_replace('~^(?:public/)+~', '', $path), '/');
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Explore Zamboanga City's history and culture, photography, community news, marketplace, entertainment and public affairs.">
<title><?=e($config['app']['name'])?> — Zamboanga stories, culture and community</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="public/assets/css/site.css?v=stories-slider-20261110">
<style>.public-forum-section{padding-top:8px}.public-forum-heading{margin:0 0 24px}.public-forum-heading h2{margin:6px 0 8px;color:#f7eef0;font-size:clamp(26px,3vw,36px)}.public-forum-count{margin:10px 0 0;color:#d79aa5;font-size:12px;font-weight:700}.public-forum-list{overflow:hidden;border:1px solid rgba(190,75,98,.28);border-radius:13px;background:rgba(18,11,15,.94);box-shadow:0 14px 36px rgba(0,0,0,.2)}.public-forum-list-head,.public-forum-thread{display:grid;grid-template-columns:minmax(0,1fr) minmax(145px,190px) minmax(130px,170px);gap:18px;align-items:center}.public-forum-list-head{padding:13px 20px;background:rgba(111,31,49,.25);border-bottom:1px solid rgba(190,75,98,.24);color:#d9aab4;font-size:10px;font-weight:800;letter-spacing:.9px;text-transform:uppercase}.public-forum-thread{min-width:0;padding:19px 20px;border-bottom:1px solid rgba(255,255,255,.09);transition:background .18s}.public-forum-thread:last-child{border-bottom:0}.public-forum-thread:hover{background:rgba(119,35,55,.12)}.public-forum-topic{min-width:0}.public-forum-thread-top{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:7px}.public-forum-prefix{display:inline-flex;padding:4px 8px;border-radius:4px;background:rgba(177,55,78,.17);color:#f0b7c1;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase}.public-forum-date,.public-forum-thread-footer{color:#b9aab0;font-size:11px}.public-forum-topic h3{margin:0 0 6px;color:#fff;font-size:15px;line-height:1.4}.public-forum-description{color:#cbbec3;font-size:11px;line-height:1.55;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.public-forum-description p{display:inline;margin:0}.public-forum-description img,.public-forum-description video{display:none}.public-forum-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.public-forum-tags span{padding:3px 7px;border:1px solid rgba(255,255,255,.12);border-radius:4px;color:#d9c5cc;font-size:9px}.public-forum-author{min-width:0;color:#eee0e5;font-size:12px;font-weight:700;overflow-wrap:anywhere}.public-forum-author small{display:block;margin-top:4px;color:#a99ba1;font-size:10px;font-weight:400}.public-forum-activity{color:#d0c1c7;font-size:11px;line-height:1.55}.public-forum-activity strong{display:block;color:#f0dce2;font-size:11px;font-weight:600}.public-forum-attachments{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}.public-forum-attachments img,.public-forum-attachments video{width:min(100%,150px);height:92px;object-fit:cover;border-radius:7px;background:#080808}.public-forum-file{display:flex;align-items:center;max-width:200px;min-height:48px;padding:8px;border:1px solid rgba(255,255,255,.13);border-radius:7px;color:#f1c4cc;font-size:10px;overflow-wrap:anywhere}.public-forum-empty{padding:25px;border:1px solid rgba(190,75,98,.22);border-radius:13px;background:rgba(30,15,22,.8)}.public-forum-empty h3{margin:0 0 8px;color:#fff}.public-forum-empty p{margin:0}@media(max-width:760px){.public-forum-list-head{display:none}.public-forum-thread{grid-template-columns:minmax(0,1fr);gap:10px;padding:16px}.public-forum-author,.public-forum-activity{padding-left:0}.public-forum-author small{display:inline;margin-left:7px}.public-forum-activity strong{display:inline;margin-right:5px}}.module-page-head{padding:54px 0 20px}.module-page-head h1{margin:8px 0 12px;color:#f7eef0;font-size:clamp(30px,4vw,46px)}.module-page-head .muted{max-width:760px;line-height:1.7}.module-page-head .btn{margin-top:12px}.header-text-bar{background:#09090b;color:#b6c0b8;border-bottom:1px solid rgba(255,255,255,.1);font:10px Ubuntu,sans-serif;letter-spacing:1.2px}.header-text-inner{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:9px 22px}.header-text-inner span{max-width:48%;overflow-wrap:anywhere}.header-text-inner .header-type-right{text-align:right}.nav .brand{display:flex;align-items:center;gap:12px;min-width:0}.nav .brand img{flex:0 0 auto}.nav .brand .public-header-title{overflow-wrap:anywhere}.nav .navlinks{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;justify-content:flex-end;gap:19px;white-space:nowrap}.nav .navlinks .header-type-link-0,.nav .navlinks [class^="header-type-link-"],.nav .navlinks>a:not(.btn){font-family:inherit!important;font-size:12px!important;font-weight:500!important;font-style:normal!important;line-height:1.2!important;letter-spacing:normal!important;text-transform:none!important;white-space:nowrap!important}.nav .navlinks [class^="header-type-link-"]{color:inherit!important;text-decoration:none}.creator-account-menu{position:relative;display:inline-flex;align-items:center}
.creator-account-trigger{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:4px 12px 4px 5px;border:1px solid rgba(224,82,96,.48);border-radius:999px;background:linear-gradient(135deg,rgba(93,24,42,.94),rgba(35,12,20,.96));color:#fff;cursor:pointer;font:600 12px Ubuntu,sans-serif;box-shadow:0 5px 18px rgba(0,0,0,.22);transition:border-color .2s,background .2s}
.creator-account-trigger:hover,.creator-account-trigger[aria-expanded="true"]{border-color:#e05260;background:linear-gradient(135deg,rgba(120,29,50,.98),rgba(45,14,25,.98))}
.creator-account-avatar{width:30px;height:30px;flex:0 0 30px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,.35);background:#5b1b2b;color:#fff}
.creator-account-initials{display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.creator-header-nickname{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#f7e8ec}
.creator-account-chevron{width:13px;height:13px;opacity:.8;transition:transform .2s}
.creator-account-trigger[aria-expanded="true"] .creator-account-chevron{transform:rotate(180deg)}
.creator-account-dropdown{position:absolute;right:0;top:calc(100% + 10px);z-index:1200;display:none;min-width:176px;padding:6px;border:1px solid rgba(224,82,96,.34);border-radius:12px;background:linear-gradient(145deg,#281019,#140a0e);box-shadow:0 16px 38px rgba(0,0,0,.48)}
.creator-account-dropdown.is-open{display:block}
.creator-account-dropdown a{display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:8px;color:#f5e9ed;text-decoration:none;font-size:12px;font-weight:500;transition:background .18s,color .18s}
.creator-account-dropdown a:hover{background:rgba(180,49,73,.2);color:#fff}
.creator-account-dropdown svg{width:16px;height:16px;flex:0 0 16px;color:#e6a1ad}
.creator-account-dropdown .creator-menu-signout{color:#ffb5c0}
.creator-account-dropdown .creator-menu-divider{height:1px;margin:5px 4px;background:rgba(255,255,255,.1)}
@media(max-width:560px){.creator-account-trigger{gap:6px;padding-right:9px}.creator-header-nickname{max-width:100px}.creator-account-dropdown{right:0}}.nav .navlinks a[class^="header-type-link-"]:hover{color:var(--gold)!important}.home-art-photo{background:#16070b!important;padding:0!important}.home-art-photo:before{display:none!important}.home-art-photo:after{display:none!important}.home-art-photo .hero-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;border-radius:inherit}.home-art-photo:before{content:""}
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
.public-forum-prefix-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid transparent;border-radius:6px;font-size:10px;font-weight:800;letter-spacing:.25px;line-height:1.25;text-transform:uppercase}.public-forum-prefix-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:15px;line-height:1;color:#f4e8ec}.public-forum-detail-type{display:flex;align-items:center;gap:8px}.prefix-shared{background:#dcfce7;color:#166534;border-color:#bbf7d0}.prefix-tips{background:#2563eb;color:#fff;border-color:#1d4ed8}.prefix-tutorial{background:#fef3c7;color:#854d0e;border-color:#fde68a}.prefix-info{background:#dff4ff;color:#075985;border-color:#bae6fd}.prefix-dead-link{background:#fee2e2;color:#991b1b;border-color:#fecaca}.prefix-granted{background:#bbf7d0;color:#14532d;border-color:#86efac}.prefix-get{background:#ffedd5;color:#9a3412;border-color:#fed7aa}.prefix-request{background:#fff;color:#252525;border-color:#d1d5db}.prefix-google-drive{background:#dcfce7;color:#166534;border-color:#86efac}.prefix-mediafire{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}.prefix-mega{background:#fef08a;color:#713f12;border-color:#fde047}.prefix-terabox{background:#ffedd5;color:#9a3412;border-color:#fdba74}.prefix-direct-link{background:#fff;color:#252525;border-color:#d1d5db}.group-movies{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}.group-video{background:#dcfce7;color:#166534;border-color:#bbf7d0}.group-genre{background:#fce7f3;color:#9d174d;border-color:#fbcfe8}.prefix-default{background:#f3e8ed;color:#6b2738;border-color:#e7c5d0}.public-forum-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:22px 0 4px;padding:0 0 12px;border-bottom:1px solid rgba(255,255,255,.12)}.public-forum-tab{display:inline-flex;align-items:center;justify-content:center;padding:9px 15px;border:1px solid rgba(190,75,98,.3);border-radius:7px;background:rgba(255,255,255,.045);color:#e8d6dc;text-decoration:none;font-size:12px;font-weight:700;transition:background .18s,border-color .18s,color .18s}.public-forum-tab:hover{background:rgba(119,35,55,.24);border-color:rgba(224,82,96,.65);color:#fff}.public-forum-tab.is-active{background:#7b263d;border-color:#b94b62;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.18)}@media(max-width:600px){.public-forum-tabs{gap:6px}.public-forum-tab{padding:8px 11px;font-size:11px}}</style>
</head>
<body>
<!-- Typography rules deliberately target rich-text descendants so independent Header typography wins over inline editor formatting. -->
<style id="public-header-typography-rules"><?=$publicHeaderTypographyCss?></style>
<header id="public-site-header" data-header-source="page-management"><div class="header-text-bar"><div class="wrap header-text-inner"><span class="header-type-left"><?=$renderHeaderRich($publicHeader['hidden_left_html'] ?? '',(string)($publicHeader['hidden_left'] ?? ''))?></span><span class="header-type-right"><?=$renderHeaderRich($publicHeader['hidden_right_html'] ?? '',(string)($publicHeader['hidden_right'] ?? ''))?></span></div></div><div class="wrap nav">
<a class="brand" href="index.php" aria-label="Home"><?php if (!empty($publicHeader['logo_path'])): ?><img src="public/<?=e((string)$publicHeader['logo_path'])?>" alt="" style="width:<?=$publicHeaderLogoWidth?>px;height:<?=$publicHeaderLogoHeight?>px;max-width:100%;object-fit:contain;object-position:center;border-radius:0"><?php endif; ?></a>
<nav class="navlinks">
<?php foreach ($publicHeaderLinks as $headerLinkIndex => $headerLink): if (!is_array($headerLink) || trim((string)($headerLink['label'] ?? '')) === '') continue; $headerLinkLabel = $renderHeaderRich($headerLink['label_html'] ?? '',(string)$headerLink['label']); $headerLinkUrl = trim((string)($headerLink['url'] ?? '')); ?><?php if ($headerLinkUrl !== ''): ?><a class="header-type-link-<?= (int)$headerLinkIndex ?>" href="<?=e($headerLinkUrl)?>"><?=$headerLinkLabel?></a><?php else: ?><span class="header-type-link-<?= (int)$headerLinkIndex ?>"><?=$headerLinkLabel?></span><?php endif; ?><?php endforeach; ?>
<?php if ($isCreatorSignedIn): ?>
<?php $creatorPhotoRaw=trim((string)($currentPublicUser['creator_profile_photo']??'')); $creatorPhotoUrl=$creatorPhotoRaw!==''?(preg_match('~^https?://~i',$creatorPhotoRaw)?$creatorPhotoRaw:'public/'.ltrim($creatorPhotoRaw,'/')):''; $creatorInitials=mb_strtoupper(mb_substr($creatorHeaderNickname,0,1,'UTF-8'),'UTF-8'); ?>
<div class="creator-account-menu" id="creator-account-menu">
<button type="button" class="creator-account-trigger" id="creator-account-trigger" aria-haspopup="true" aria-expanded="false" aria-controls="creator-account-dropdown">
<?php if($creatorPhotoUrl!==''): ?><img class="creator-account-avatar" src="<?=e($creatorPhotoUrl)?>" alt="<?=e($creatorHeaderNickname)?> profile photo"><?php else: ?><span class="creator-account-avatar creator-account-initials" aria-hidden="true"><?=e($creatorInitials)?></span><?php endif; ?>
<span class="creator-header-nickname"><?=e($creatorHeaderNickname)?></span>
<svg class="creator-account-chevron" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
</button>
<div class="creator-account-dropdown" id="creator-account-dropdown" role="menu">
<a href="public/workspace.php" role="menuitem"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M4.8 20c.7-3.4 3.2-5.2 7.2-5.2s6.5 1.8 7.2 5.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>My Workspace</a>
<div class="creator-menu-divider" role="separator"></div>
<a class="creator-menu-signout" href="public/logout.php?scope=creator" role="menuitem"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 4H5.8A1.8 1.8 0 0 0 4 5.8v12.4A1.8 1.8 0 0 0 5.8 20H10M14.5 16.5 19 12l-4.5-4.5M19 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>Sign out</a>
</div></div>
<?php else: ?><a href="#sign-in-modal" id="open-sign-in">Sign in</a><a class="btn" href="#create-account-modal" id="open-create-account">Join the Community</a><?php endif; ?>
</nav></div></header>
<main>
<?php $publicSections=$sectionsSettings ?? []; $bannerSection=$publicSections['banner']??[]; ?>
<?php if (!$moduleView): ?>
<section class="hero home-hero"><div class="wrap hero-grid"><div>
<div class="eyebrow"><?=$renderBannerRichText((string)($bannerSection['main_title']??'One city. Many stories.'))?></div>
<h1><?=$renderBannerRichText((string)($bannerSection['heading']??'Zamboanga, through our eyes.'))?></h1>
<div class="hero-description"><?=$renderBannerRichText((string)($bannerSection['description']??''))?></div>
</div>
<div class="hero-art home-art home-art-photo" role="img" aria-label="Zamboanga heritage image"><?php if(!empty($bannerSection['photo'])): ?><img class="hero-photo" src="<?=e($sectionImageSrc((string)$bannerSection['photo']))?>" alt="<?=e($bannerSection['heading']??'Zamboanga')?>"><?php else: ?><img class="hero-photo" src="public/assets/images/Sunset%20Vinta%20by%20the%20Historic%20Fort.png" alt="Sunset vinta sailing by the historic fort in Zamboanga"><?php endif; ?></div>
</div></section>

<?php $slideSection=$publicSections['slideshow']??[]; $slidePhotos=(array)($slideSection['photos']??[]); ?>
<section class="section culture-section" id="zamboanga"><div class="wrap">
<div class="section-head"><div><div class="eyebrow"><?=$renderBannerRichText((string)($slideSection['main_title']??'A living heritage'))?></div><h2><?=$renderBannerRichText((string)($slideSection['heading']??'Discover the story of Zamboanga City'))?></h2><div class="muted slideshow-section-subtitle"><?=$renderBannerRichText((string)($slideSection['subtitle']??'From historic landmarks to maritime traditions and a vibrant, multilingual culture.'))?></div></div><div class="slide-counter" aria-live="polite"><span id="slide-current">01</span> / <?=str_pad((string)max(1,count($slidePhotos)),2,'0',STR_PAD_LEFT)?></div></div>
<div class="culture-slideshow kenburns-slideshow" id="culture-slideshow" aria-label="Zamboanga City history and culture slideshow">
<?php foreach($slidePhotos as $k=>$ph): if(empty($ph['photo'])) continue; ?><article class="culture-slide<?=$k===0?' is-active':''?>" data-slide="<?=$k?>"><img src="<?=e($sectionImageSrc((string)$ph['photo']))?>" alt="<?=e($ph['title']??$ph['heading']??'Zamboanga slideshow photo')?>" loading="<?=$k===0?'eager':'lazy'?>"><div class="slide-shade"></div><div class="slide-content"><span class="slide-kicker"><?=e($ph['title']??'')?></span><h3><?=e($ph['heading']??'')?></h3><div><?=$renderBannerRichText((string)($ph['description']??''))?></div></div></article><?php endforeach; ?>
<button class="slide-arrow slide-prev" type="button" aria-label="Previous slide">‹</button><button class="slide-arrow slide-next" type="button" aria-label="Next slide">›</button><div class="slide-dots" role="group" aria-label="Choose a slide"><?php foreach($slidePhotos as $k=>$ph): if(empty($ph['photo'])) continue; ?><button class="slide-dot<?=$k===0?' is-active':''?>" type="button" aria-label="Slide <?=$k+1?>" aria-pressed="<?=$k===0?'true':'false'?>"></button><?php endforeach; ?></div>
</div></div></section>

<?php $megaSection=$publicSections['mega_menu']??[]; $megaPanels=(array)($megaSection['panels']??[]); $megaDescription=(string)($megaSection['description']??$megaSection['subtitle']??''); ?>
<section class="section mega-menu-section" id="modules"><div class="wrap">
 <div class="mega-menu-intro">
  <?php if(trim(strip_tags((string)($megaSection['main_title']??'')))!==''): ?><p class="eyebrow"><?=$renderBannerRichText((string)$megaSection['main_title'])?></p><?php endif; ?>
  <?php if(trim(strip_tags((string)($megaSection['heading']??'')))!==''): ?><h2><?=$renderBannerRichText((string)$megaSection['heading'])?></h2><?php endif; ?>
  <?php if(trim(strip_tags($megaDescription))!==''): ?><div class="mega-menu-description"><?=$renderBannerRichText($megaDescription)?></div><?php endif; ?>
 </div>
 <div class="category-row module-grid"><?php if($megaPanels): foreach($megaPanels as $pn): ?><?php
$panelHref = (string)($pn['url'] ?? '#');
if (!empty($pn['category_id'])) {
    foreach ($categories as $publicCategory) {
        if ((int)$publicCategory['id'] === (int)$pn['category_id']) {
            $panelHref = '?module=' . rawurlencode((string)$publicCategory['slug']);
            break;
        }
    }
} elseif (preg_match('/(?:^|[?&])category=([^&#]+)/', $panelHref, $panelCategoryMatch)) {
    $panelHref = '?module=' . rawurlencode(rawurldecode($panelCategoryMatch[1]));
}
if (!empty($pn['panel_id']) && preg_match('/^[a-f0-9]{32}$/i', (string)$pn['panel_id'])) {
    $panelHrefParts = explode('#', $panelHref, 2);
    $panelHrefBase = $panelHrefParts[0];
    $panelHref = $panelHrefBase . (str_contains($panelHrefBase, '?') ? '&' : '?') . 'forum_panel_id=' . rawurlencode((string)$pn['panel_id']);
    if (isset($panelHrefParts[1])) $panelHref .= '#' . $panelHrefParts[1];
}
if ($isCreatorSignedIn) {
    $panelHrefParts = explode('#', $panelHref, 2);
    $panelHrefBase = $panelHrefParts[0];
    $panelHref = $panelHrefBase . (str_contains($panelHrefBase, '?') ? '&' : '?') . 'session_context=creator';
    if (isset($panelHrefParts[1])) $panelHref .= '#' . $panelHrefParts[1];
}
?><a class="category module-card mega-public-panel" href="<?=e($panelHref)?>"<?php if(!empty($pn['description'])): ?> data-description="<?=e($pn['description'])?>" aria-label="<?=e(($pn['title']??'Menu panel').': '.($pn['description']??''))?>"<?php endif; ?>><?php if(!empty($pn['photo'])): ?><img src="<?=e($sectionImageSrc((string)$pn['photo']))?>" alt="" class="mega-panel-cover"><?php else: ?><?php $panelIconName=(string)($pn['symbol']??''); $publicIconName=preg_replace('/^tabler:/','',$panelIconName)?:'laptop'; $publicIconHash=0; foreach(str_split($publicIconName) as $publicIconChar){$publicIconHash=(($publicIconHash*31)+ord($publicIconChar)) & 0xFFFFFFFF;} $publicIconHue=$publicIconHash%360; if(preg_match('/^(?:tabler:)?[a-z0-9-]{1,60}$/', $panelIconName)): ?><span class="mega-panel-icon-background" style="--icon-hue:<?=$publicIconHue?>" aria-hidden="true"><iconify-icon icon="tabler:<?=e($publicIconName)?>"></iconify-icon></span><?php else: ?><span class="symbol"><?=e($panelIconName!==''?$panelIconName:'✳')?></span><?php endif; ?><?php endif; ?><div class="mega-panel-preview-content"><strong><?=e($pn['title']??'')?></strong><?php if(!empty($pn['description'])): ?><span class="mega-panel-description"><?=e($pn['description'])?></span><?php endif; ?><span class="module-link">Explore module ↗</span></div></a><?php endforeach; else: foreach($modules as $module): ?><a class="category module-card" href="?module=<?=e($module['slug'])?>"><span class="symbol"><?=e($module['symbol'])?></span><strong><?=e($module['name'])?></strong><span class="module-description"><?=e($module['description'])?></span><span class="module-link">Explore module ↗</span></a><?php endforeach; endif; ?></div></div></section>
<?php endif; ?>

<?php if ($moduleView && !$activeForumPanel): ?>
<section class="section module-page-head"><div class="wrap">
  <p class="eyebrow">EXPLORE THE MODULE</p>
  <h1><?=e((string)($activeModule['name'] ?? 'Module not found'))?></h1>
  <?php if ($activeModule && trim((string)$activeModule['description']) !== ''): ?><p class="muted"><?=e((string)$activeModule['description'])?></p><?php endif; ?>
  <p><a class="btn light" href="index.php#modules">← Back to Explore modules</a></p>
</div></section>
<?php endif; ?>

<?php if ($activeForumPanel): ?>
<?php $publicForumTabs = array_values(array_filter((array)($sectionsSettings['mega_menu']['panels'] ?? []), static fn($panel) => !empty($panel['panel_id']) && preg_match('/^[a-f0-9]{32}$/i', (string)$panel['panel_id']))); ?>
<section class="section module-page-head public-forum-page-head"><div class="wrap">
  <p class="eyebrow">COMMUNITY FORUM</p>
  <h1><?=e(str_ireplace(' And ', ' and ', (string)($activeForumPanel['title'] ?? $activeModule['name'] ?? 'Forum')))?></h1>
  <p class="muted">Skill, imagination, and a camera to express ideas, tell stories, and evoke emotions rather than simply recording a literal copy of reality.</p>
  <?php if(count($publicForumTabs)>1): ?><nav class="public-forum-tabs" aria-label="Other Community Forums">
   <?php foreach($publicForumTabs as $forumTab): $forumTabId=(string)$forumTab['panel_id']; $forumTabHref='index.php?'.http_build_query(array_filter(['forum_panel_id'=>$forumTabId,'session_context'=>$isCreatorSignedIn?'creator':null],static fn($value)=>$value!==null)); ?>
    <a class="public-forum-tab<?=$forumTabId===$requestedForumPanelId?' is-active':''?>" href="<?=e($forumTabHref)?>"<?=$forumTabId===$requestedForumPanelId?' aria-current="page"':''?>><?=e((string)($forumTab['title']??'Forum'))?></a>
   <?php endforeach; ?>
  </nav><?php endif; ?>
  <?php if ($publicForumDetail): ?><p><a class="btn light" href="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?><?= $isCreatorSignedIn ? '&amp;session_context=creator' : '' ?>">← Back to discussions</a></p><?php endif; ?>
</div></section>
<?php if ($publicForumDetail): ?>
<section class="section public-forum-section" id="forum-thread-detail"><div class="wrap">
 <?php if($publicForumError!==''): ?><div class="panel public-forum-alert"><?=e($publicForumError)?></div><?php endif; ?>
 <article class="panel public-forum-detail">
  <div class="public-forum-detail-author">
   <?php $detailAvatar=$publicForumAvatarUrl($publicForumDetail['author_avatar']??''); ?>
   <?php $detailNickname=trim((string)($publicForumDetail['nickname']??'')) ?: $publicForumDisplayName($publicForumDetail); ?>
    <?php if($detailAvatar!==''): ?><img class="public-forum-avatar" src="<?=e($detailAvatar)?>" alt="<?=e($detailNickname)?>" title="<?=e($detailNickname)?>"><?php else: ?><span class="public-forum-avatar public-forum-avatar-fallback" title="<?=e($detailNickname)?>" aria-label="<?=e($detailNickname)?>"><?=e(strtoupper(mb_substr((string)$publicForumDetail['full_name'],0,1)))?></span><?php endif; ?>
   <div><strong><?=e($publicForumDisplayName($publicForumDetail))?></strong><small><?=e(ucwords(str_replace('_',' ',(string)$publicForumDetail['role'])))?> · <?=e(date('F d, Y',strtotime((string)$publicForumDetail['created_at'])))?></small></div>
  </div>
  <?php $detailPrefixGroup=(string)($publicForumDetail['prefix_group']??''); $detailPrefixName=(string)($publicForumDetail['prefix']?:'No prefix'); $detailPrefixStyle=$publicForumPrefixPresentation($detailPrefixGroup,$detailPrefixName); ?>
  <div class="public-forum-detail-type"><?php if($detailPrefixStyle['icon']!==''): ?><span class="public-forum-prefix-icon" aria-hidden="true"><?=e($detailPrefixStyle['icon'])?></span><?php endif; ?><span class="public-forum-prefix-badge <?=e($detailPrefixStyle['class'])?>"><?=e($detailPrefixName)?></span></div>
  <h2><?=e((string)$publicForumDetail['title'])?></h2>
  <div class="public-forum-detail-body"><?=$renderBannerRichText((string)$publicForumDetail['description'])?></div>
  <?php if ($isCreatorSignedIn && $publicForumAttachments): ?>
  <section class="public-forum-attachments" aria-label="Thread attachments">
   <h3>Uploaded Files</h3>
   <div class="public-forum-attachment-list">
    <?php foreach ($publicForumAttachments as $forumAttachment):
     $attachmentHref = $publicForumAttachmentUrl((string)$forumAttachment['file_path']);
     $attachmentName = trim((string)$forumAttachment['original_name']) ?: basename((string)$forumAttachment['file_path']);
     $attachmentBytes = (int)$forumAttachment['file_size'];
     $attachmentSize = $attachmentBytes >= 1073741824 ? number_format($attachmentBytes / 1073741824, 2) . ' GB' : ($attachmentBytes >= 1048576 ? number_format($attachmentBytes / 1048576, 2) . ' MB' : ($attachmentBytes >= 1024 ? number_format($attachmentBytes / 1024, 1) . ' KB' : $attachmentBytes . ' B'));
    ?>
    <a class="public-forum-attachment" href="<?=e($attachmentHref)?>" target="_blank" rel="noopener noreferrer">
     <span class="public-forum-attachment-icon" aria-hidden="true">↧</span>
     <span class="public-forum-attachment-info"><strong><?=e($attachmentName)?></strong><small><?=e(ucfirst((string)$forumAttachment['attachment_type']))?> · <?=e($attachmentSize)?></small></span>
     <span class="public-forum-attachment-open">Open file ↗</span>
    </a>
    <?php endforeach; ?>
   </div>
  </section>
  <?php elseif (!$isCreatorSignedIn): ?>
  <p class="public-forum-attachments-locked"><a href="index.php#sign-in-modal">Sign in</a> with your contributor account to view this thread's uploaded files.</p>
  <?php endif; ?>
  <div class="public-forum-detail-actions">
   <span><strong><?=number_format($publicForumViewCount)?></strong> views</span>
   <span><strong><?=number_format($publicForumReplyCount)?></strong> replies (including sub-replies)</span>
   <span><strong><?=number_format($publicForumLikeCount)?></strong> likes</span>
   <?php if($isCreatorSignedIn): ?><details class="public-forum-reaction-picker"><summary class="btn light public-forum-like-button"><?=e(($publicForumCurrentReaction!==''?$publicForumCurrentReaction:'♡').' Like')?></summary><div class="public-forum-reaction-options"><?php foreach(['👍'=>'Like','❤️'=>'Love','😂'=>'Haha','😮'=>'Wow','😢'=>'Sad','😡'=>'Angry','🎉'=>'Celebrate'] as $reactionEmoji=>$reactionLabel): ?><form method="post" action="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?>&amp;thread_id=<?=$publicForumThreadId?>&amp;session_context=creator#forum-thread-detail"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="forum_action" value="like"><input type="hidden" name="thread_id" value="<?=$publicForumThreadId?>"><input type="hidden" name="reaction" value="<?=e($reactionEmoji)?>"><button type="submit" title="<?=e($reactionLabel)?>"><?=e($reactionEmoji)?></button></form><?php endforeach; ?></div></details><span class="public-forum-reaction-counts" aria-label="Reaction counts"><?php foreach(['👍'=>'Like','❤️'=>'Love','😂'=>'Haha','😮'=>'Wow','😢'=>'Sad','😡'=>'Angry','🎉'=>'Celebrate'] as $reactionEmoji=>$reactionLabel): $reactionCount=(int)($publicForumThreadReactionCounts[$reactionEmoji]??0); if($reactionCount>0): ?><span title="<?=e($reactionLabel)?>"><?=e($reactionEmoji)?> <b><?=number_format($reactionCount)?></b></span><?php endif; endforeach; ?></span><details class="public-forum-report"><summary>⚑ Report</summary><form method="post" action="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?>&amp;thread_id=<?=$publicForumThreadId?>&amp;session_context=creator#forum-thread-detail"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="forum_action" value="report_thread"><input type="hidden" name="thread_id" value="<?=$publicForumThreadId?>"><label>Reason<select name="report_reason" required><option value="">Select a reason</option><option value="malicious">Malicious or harmful content</option><option value="unverified">Unverified or misleading information</option><option value="community_rules">Violates community rules</option><option value="terms_of_service">Violates Terms of Service</option><option value="other">Other concern</option></select></label><textarea name="report_details" maxlength="2000" rows="3" placeholder="Additional details (optional)"></textarea><button class="btn light" type="submit">Submit report</button></form></details><?php else: ?><a class="btn light" href="index.php#sign-in-modal">Sign in to Like</a><a class="btn light" href="index.php#sign-in-modal">Sign in to Report</a><?php endif; ?>
  </div>
 </article>
 <div class="public-forum-replies">
  <h3>Replies (<?=number_format($publicForumReplyCount)?>)</h3>
  <?php if(!$publicForumReplies): ?><p class="muted">No replies yet. Start the conversation.</p><?php endif; ?>
  <?php foreach($publicForumRepliesFlat as $forumReply): $replyAvatar=$publicForumAvatarUrl($forumReply['author_avatar']??''); $replyDepth=(int)($forumReply['_depth']??0); ?>
  <article class="public-forum-reply" style="--reply-depth:<?=min($replyDepth,4)?>">
   <?php $replyNickname=trim((string)($forumReply['nickname']??'')) ?: $publicForumDisplayName($forumReply); ?>
    <?php if($replyAvatar!==''): ?><img class="public-forum-avatar" src="<?=e($replyAvatar)?>" alt="<?=e($replyNickname)?>" title="<?=e($replyNickname)?>"><?php else: ?><span class="public-forum-avatar public-forum-avatar-fallback" title="<?=e($replyNickname)?>" aria-label="<?=e($replyNickname)?>"><?=e(strtoupper(mb_substr(trim((string)($forumReply['first_name']??'')) ?: (string)$forumReply['full_name'],0,1)))?></span><?php endif; ?>
   <div class="public-forum-reply-content"><div class="public-forum-reply-meta"><strong><?=e($publicForumDisplayName($forumReply))?></strong><time><?=e(date('F d, Y',strtotime((string)$forumReply['created_at'])))?></time></div><div class="public-forum-reply-rich-content"><?=$sanitizeForumReplyHtml((string)$forumReply['body'])?></div><div class="public-forum-reply-actions"><div class="public-forum-reply-actions-inner"><?php if($isCreatorSignedIn): ?><details class="public-forum-reaction-picker public-forum-reply-reaction"><summary class="public-forum-action-button" title="Choose or change your reaction"><?=e(((string)($forumReply['current_reaction']??''))!==''?(string)$forumReply['current_reaction']:'♡')?> Like</summary><div class="public-forum-reaction-options"><?php foreach(['👍'=>'Like','❤️'=>'Love','😂'=>'Haha','😮'=>'Wow','😢'=>'Sad','😡'=>'Angry','🎉'=>'Celebrate'] as $reactionEmoji=>$reactionLabel): ?><form method="post" action="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?>&amp;thread_id=<?=$publicForumThreadId?>&amp;session_context=creator#forum-replies"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="forum_action" value="reply_like"><input type="hidden" name="thread_id" value="<?=$publicForumThreadId?>"><input type="hidden" name="reply_id" value="<?=(int)$forumReply['id']?>"><input type="hidden" name="reaction" value="<?=e($reactionEmoji)?>"><button type="submit" title="<?=e($reactionLabel)?>"><?=e($reactionEmoji)?></button></form><?php endforeach; ?></div></details><span class="public-forum-reaction-counts public-forum-reply-reaction-counts" aria-label="Reaction counts"><?php foreach(['👍'=>'Like','❤️'=>'Love','😂'=>'Haha','😮'=>'Wow','😢'=>'Sad','😡'=>'Angry','🎉'=>'Celebrate'] as $reactionEmoji=>$reactionLabel): $reactionCount=(int)(($forumReply['reaction_counts'][$reactionEmoji]??0)); if($reactionCount>0): ?><span title="<?=e($reactionLabel)?>"><?=e($reactionEmoji)?> <b><?=number_format($reactionCount)?></b></span><?php endif; endforeach; ?></span><button type="button" class="public-forum-action-button" data-reply-to="<?=(int)$forumReply['id']?>" data-reply-author="<?=e($publicForumDisplayName($forumReply))?>">Reply</button><button type="button" class="public-forum-action-button" data-quote-reply="<?=(int)$forumReply['id']?>" data-quote-author="<?=e($publicForumDisplayName($forumReply))?>" data-quote-body="<?=e(trim(strip_tags((string)$forumReply['body'])))?>">Quote</button><details class="public-forum-report public-forum-reply-report"><summary class="public-forum-action-button" title="Report">⚑ Report</summary><form method="post" action="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?>&amp;thread_id=<?=$publicForumThreadId?>&amp;session_context=creator#forum-replies"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="forum_action" value="report_reply"><input type="hidden" name="thread_id" value="<?=$publicForumThreadId?>"><input type="hidden" name="reply_id" value="<?=(int)$forumReply['id']?>"><label>Reason<select name="report_reason" required><option value="">Select a reason</option><option value="malicious">Malicious or harmful content</option><option value="unverified">Unverified or misleading information</option><option value="community_rules">Violates community rules</option><option value="terms_of_service">Violates Terms of Service</option><option value="other">Other concern</option></select></label><textarea name="report_details" maxlength="2000" rows="2" placeholder="Additional details (optional)"></textarea><button type="submit" class="btn light">Submit Report</button></form></details><?php else: ?><a class="public-forum-action-button" href="index.php#sign-in-modal">Sign in</a><?php endif; ?></div></div>
  </article>
  <?php endforeach; ?>
 </div>
 <div class="panel public-forum-reply-form" id="public-forum-reply-composer">
  <h3 id="public-forum-reply-heading">Post a reply</h3><div id="public-forum-reply-context" class="public-forum-reply-context" hidden><span data-reply-context-label></span><button type="button" data-reply-cancel>Cancel</button></div><?php if($publicForumNotice!==''): ?><p class="public-forum-notice"><?=e($publicForumNotice)?></p><?php endif; ?>
  <?php if($isCreatorSignedIn): ?><form id="public-forum-reply-form" method="post" enctype="multipart/form-data" action="index.php?forum_panel_id=<?=e(rawurlencode($requestedForumPanelId))?>&amp;thread_id=<?=$publicForumThreadId?>&amp;session_context=creator#forum-thread-detail"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="forum_action" value="reply"><input type="hidden" name="thread_id" value="<?=$publicForumThreadId?>"><input type="hidden" name="parent_reply_id" id="public-forum-parent-reply-id" value="0"><div class="public-forum-editor-toolbar" role="toolbar" aria-label="Reply formatting"><button type="button" data-reply-command="bold" title="Bold"><b>B</b></button><button type="button" data-reply-command="italic" title="Italic"><i>I</i></button><button type="button" data-reply-command="underline" title="Underline"><u>U</u></button><button type="button" data-reply-command="strikeThrough" title="Strike-through"><s>S</s></button><select id="reply-font-size" aria-label="Font size"><option value="">Font size</option><option value="2">Small</option><option value="3">Normal</option><option value="4">Large</option><option value="5">X-Large</option><option value="6">XX-Large</option></select><label class="reply-color-control" title="Font color">A<input type="color" id="reply-font-color" value="#f4e9ed" aria-label="Font color"></label><button type="button" data-reply-tool="photo" title="Photos">▧</button><input type="file" id="reply-photo-input" name="reply_photos[]" accept=".jpeg,.jpg,.png,.avif,.webp,image/jpeg,image/png,image/avif,image/webp" multiple hidden><button type="button" data-reply-tool="link" title="Link">🔗</button><button type="button" data-reply-command="formatBlock" data-reply-value="blockquote" title="Quotes">❝</button><button type="button" data-reply-tool="table" title="Tables">▦</button><button type="button" data-reply-tool="code" title="Codes">&lt;/&gt;</button><details class="public-forum-editor-emoji"><summary title="Emoticons">☺</summary><div class="public-forum-reaction-options"><?php foreach(['👍','❤️','😂','😮','😢','😡','🎉'] as $editorEmoji): ?><button type="button" data-editor-emoji="<?=e($editorEmoji)?>" title="<?=e($editorEmoji)?>"><?=e($editorEmoji)?></button><?php endforeach; ?></div></details><button type="button" data-reply-command="undo" title="Undo">↶</button><button type="button" data-reply-command="redo" title="Redo">↷</button></div><div id="public-forum-reply-editor" class="public-forum-reply-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write your reply…"></div><textarea id="public-forum-reply-body" name="reply_body" hidden></textarea><div class="public-forum-editor-footer"><small>Photos: JPEG, JPG, PNG, AVIF, or WEBP; up to 5 images, 8 MB each.</small><button class="btn primary" type="submit">Post Reply</button></div></form><?php else: ?><p class="muted">Please <a href="index.php#sign-in-modal">sign in</a> with your contributor account to post a reply or like this discussion.</p><?php endif; ?>
 </div>
</div></section>
<?php else: ?>
<section class="section public-forum-section" id="community-forum"><div class="wrap">
 <p class="public-forum-count"><?=count($publicForumThreads)?> published <?=count($publicForumThreads)===1?'discussion':'discussions'?></p>
 <?php if (!$publicForumThreads): ?><div class="panel public-forum-empty"><h3>No published discussions yet.</h3><p class="muted">Community discussions posted to this forum panel will appear here once published.</p></div>
 <?php else: ?><div class="public-forum-list">
  <div class="public-forum-list-head"><span>Topic</span><span>Replies / Views</span><span>Last activity</span></div>
 <?php foreach ($publicForumThreads as $forumThread):
   $createdTimestamp=strtotime((string)$forumThread['created_at']);
   $updatedTimestamp=strtotime((string)($forumThread['updated_at'] ?: $forumThread['created_at']));
   $authorAvatar=$publicForumAvatarUrl($forumThread['author_avatar']??'');
   $lastActivityAvatar=$publicForumAvatarUrl(($forumThread['last_reply_avatar']??'')?:($forumThread['author_avatar']??''));
    $authorDisplayName=$publicForumDisplayName($forumThread);
     $authorNickname=trim((string)($forumThread['nickname']??'')) ?: $authorDisplayName;
    $lastActivityDisplayName=$publicForumDisplayName(['first_name'=>$forumThread['last_reply_first_name']??'','last_name'=>$forumThread['last_reply_last_name']??'','name_suffix'=>$forumThread['last_reply_name_suffix']??'','full_name'=>$forumThread['last_reply_full_name']??'']) ?: $authorDisplayName;
     $lastActivityNickname=trim((string)($forumThread['last_reply_nickname']??'')) ?: $lastActivityDisplayName;
   $activityTimestamp=strtotime((string)(($forumThread['last_reply_at']??'')?:($forumThread['updated_at']?:$forumThread['created_at'])));
   $threadHref='index.php?'.http_build_query(array_filter(['forum_panel_id'=>$requestedForumPanelId,'thread_id'=>(int)$forumThread['id'],'session_context'=>$isCreatorSignedIn?'creator':null],static fn($value)=>$value!==null));
 ?>
 <article class="public-forum-thread">
  <div class="public-forum-topic">
   <div class="public-forum-thread-top">
    <?php if($authorAvatar!==''): ?><img class="public-forum-avatar public-forum-topic-avatar" src="<?=e($authorAvatar)?>" alt="<?=e($authorNickname)?>" title="<?=e($authorNickname)?>"><?php else: ?><span class="public-forum-avatar public-forum-avatar-fallback public-forum-topic-avatar" title="<?=e($authorNickname)?>" aria-label="<?=e($authorNickname)?>"><?=e(strtoupper(mb_substr((string)$forumThread['full_name'],0,1)))?></span><?php endif; ?>
    <?php $threadPrefixGroup=(string)($forumThread['prefix_group']??'General'); $threadPrefixName=(string)($forumThread['prefix']??'')!=='' && (string)$forumThread['prefix']!=='No prefix' ? (string)$forumThread['prefix'] : $threadPrefixGroup; $threadPrefixStyle=$publicForumPrefixPresentation($threadPrefixGroup,$threadPrefixName); ?>
    <?php if($threadPrefixStyle['icon']!==''): ?><span class="public-forum-prefix-icon" aria-hidden="true"><?=e($threadPrefixStyle['icon'])?></span><?php endif; ?><span class="public-forum-prefix-badge <?=e($threadPrefixStyle['class'])?>"><?=e($threadPrefixName)?></span>
   </div>
   <h3><a href="<?=e($threadHref)?>"><?=e((string)$forumThread['title'])?></a></h3>
   <div class="public-forum-date">Published on <?=e($createdTimestamp ? date('F d, Y',$createdTimestamp) : '')?></div>
  </div>
  <div class="public-forum-stats"><strong><?=number_format((int)$forumThread['reply_count'])?></strong><small>Replies</small><strong><?=number_format((int)$forumThread['view_count'])?></strong><small>Views</small></div>
  <div class="public-forum-activity">
   <?php if($lastActivityAvatar!==''): ?><img class="public-forum-avatar" src="<?=e($lastActivityAvatar)?>" alt="<?=e($lastActivityNickname)?>" title="<?=e($lastActivityNickname)?>"><?php else: ?><span class="public-forum-avatar public-forum-avatar-fallback" title="<?=e($lastActivityNickname)?>" aria-label="<?=e($lastActivityNickname)?>"><?=e(strtoupper(mb_substr((string)$forumThread['full_name'],0,1)))?></span><?php endif; ?>
   <div><strong><?=e($activityTimestamp ? date('F d, Y',$activityTimestamp) : '—')?></strong><small><?=!empty($forumThread['last_reply_at'])?'Last reply':(($updatedTimestamp && $createdTimestamp && $updatedTimestamp > $createdTimestamp) ? 'Last updated' : 'Published')?></small></div>
  </div>
 </article>
 <?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php endif; ?>
<?php endif; ?>
<section class="section" id="latest"><div class="wrap">
<?php if($category || $search): ?><p><a class="btn light" href="index.php#latest">Clear filters</a></p><?php endif; ?>
<?php if (!$showAllStories): ?>
<div class="stories-slider" id="stories-slider">
  <button class="stories-slider-arrow stories-slider-prev" type="button" aria-label="Previous stories" disabled>‹</button>
  <div class="stories-slider-viewport" id="stories-slider-viewport">
    <div class="stories-slider-track">
      <?php if (!$items && !$moduleView): ?><div class="panel empty-state stories-slider-empty"><span class="empty-mark">✳</span><h3>Good stories start with a community.</h3><p class="muted">There are no published posts matching this view yet. Approved creators can prepare drafts in their personal space and submit them for review.</p><a class="btn" href="#create-account-modal" data-open-create-account>Join as a contributor</a></div><?php endif; ?>
      <?php foreach($items as $i=>$item): ?><article class="story stories-slider-card"><div class="story-img <?= $i%3===0?'art':($i%3===1?'home':'news') ?>"><?php if(!empty($item['cover_photo'])): ?><img src="public/uploads/articles/cover_photos/<?=e(rawurlencode((string)$item['cover_photo']))?>" alt="<?=e($item['title'])?> cover photo"><?php else: ?><?=e(strtoupper($item['category_name']))?><?php endif; ?></div><div class="story-body"><div class="tag"><?=e($item['category_name'])?><?php if(!empty($item['subcategory_name'])): ?> · <?=e($item['subcategory_name'])?><?php endif; ?></div><h3><?=e($item['title'])?></h3><p><?=e($item['excerpt'] ?? '')?></p><div class="story-foot"><span>By <?=e($item['full_name'])?></span><span class="story-actions"><?php if($item['price']!==null): ?><span class="story-price">₱<?=number_format((float)$item['price'],2)?></span><?php endif; ?><a class="story-read-link" href="article.php?id=<?= (int)$item['id'] ?>">Read Story ↗</a></span></div></div></article><?php endforeach; ?>
      <?php if ($items): ?><a class="story stories-slider-card stories-show-all" href="all-stories.php"><span class="show-all-mark">↗</span><strong>Show all stories</strong><span>Explore every published story</span></a><?php endif; ?>
    </div>
  </div>
  <button class="stories-slider-arrow stories-slider-next" type="button" aria-label="Next stories">›</button>
</div>
<div class="stories-slider-status" id="stories-slider-status" aria-live="polite">Showing up to 3 stories at a time</div>
<?php else: ?>
<div class="cards">
<?php if (!$items && !$moduleView): ?><div class="panel empty-state" style="grid-column:1/-1"><span class="empty-mark">✳</span><h3>Good stories start with a community.</h3><p class="muted">There are no published posts matching this view yet. Approved creators can prepare drafts in their personal space and submit them for review.</p><a class="btn" href="#create-account-modal" data-open-create-account>Join as a contributor</a></div><?php endif; ?>
<?php foreach($items as $i=>$item): ?><article class="story"><div class="story-img <?= $i%3===0?'art':($i%3===1?'home':'news') ?>"><?php if(!empty($item['cover_photo'])): ?><img src="public/uploads/articles/cover_photos/<?=e(rawurlencode((string)$item['cover_photo']))?>" alt="<?=e($item['title'])?> cover photo"><?php else: ?><?=e(strtoupper($item['category_name']))?><?php endif; ?></div><div class="story-body"><div class="tag"><?=e($item['category_name'])?><?php if(!empty($item['subcategory_name'])): ?> · <?=e($item['subcategory_name'])?><?php endif; ?></div><h3><?=e($item['title'])?></h3><p><?=e($item['excerpt'] ?? '')?></p><div class="story-foot"><span>By <?=e($item['full_name'])?></span><span class="story-actions"><?php if($item['price']!==null): ?><span class="story-price">₱<?=number_format((float)$item['price'],2)?></span><?php endif; ?><a class="story-read-link" href="article.php?id=<?= (int)$item['id'] ?>">Read Story ↗</a></span></div></div></article><?php endforeach; ?>
</div>
<p class="stories-show-latest"><a class="btn light" href="index.php#latest">Back to featured stories</a></p>
<?php endif; ?></div></section>

</main>

<div class="register-modal" id="create-account-modal" aria-hidden="true"><div class="register-dialog" role="dialog" aria-modal="true" aria-label="Create an Account"><button type="button" class="register-close" id="close-create-account" aria-label="Close create account">×</button><iframe src="public/register.php" title="Create an Account" loading="lazy"></iframe></div></div>

<div class="signin-modal<?= $loginError !== '' ? ' is-open' : '' ?>" id="sign-in-modal" aria-hidden="<?= $loginError !== '' ? 'false' : 'true' ?>">
<div class="signin-dialog" role="dialog" aria-modal="true" aria-labelledby="signin-title">
<button type="button" class="signin-close" id="close-sign-in" aria-label="Close sign in">×</button>
<div class="eyebrow">Welcome back</div><h2 id="signin-title">Sign in</h2>
<?php if ((string)($_GET['email_verified'] ?? '') === '1'): ?><p class="signin-success" role="status">Your new email address has been verified. Please sign in again.</p><?php elseif ((string)($_GET['registered'] ?? '') === '1'): ?><p class="signin-success" role="status">Your email is verified and your password is set. You can now sign in.</p><?php endif; ?>
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
<footer><div class="wrap footer-grid">
<div><b><?=e($publicFooter['brand'])?></b><p><?=e($publicFooter['description'])?></p><p><?=e(str_replace('{year}',date('Y'),$publicFooter['copyright']))?></p><?php $footerItemStyle='display:block;margin:8px 0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;font-weight:400;font-style:normal;line-height:1.65;letter-spacing:.01em;color:#9eaca2;text-decoration:none;'; ?>
<?php foreach(($publicFooter['brand_links'] ?? []) as $footerLink): ?><?php if(trim((string)($footerLink['url'] ?? '')) !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerLink['url'])?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
<div><b><?=e($publicFooter['explore_title'])?></b><?php foreach($publicFooter['explore_links'] as $footerLink): ?><?php if(trim((string)($footerLink['url'] ?? '')) !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerLink['url'])?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
<div><b><?=e($publicFooter['discover_title'])?></b><?php foreach($publicFooter['discover_links'] as $footerLink): ?><?php if(trim((string)($footerLink['url'] ?? '')) !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerLink['url'])?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
</div></footer>
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
    // Keep the registration modal open when the user clicks its backdrop.
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

  // Open the same Sign In overlay from Forum prompts and any other sign-in links.
  document.addEventListener('click', event => {
    const signInLink = event.target.closest('a[href*="#sign-in-modal"]');
    if (!signInLink) return;
    event.preventDefault();
    hideRegisterModal();
    showSignInModal();
  });
  closeButton.addEventListener('click', hideSignInModal);
  // Keep the sign-in modal open when the user clicks its backdrop.
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
<script>
(() => {
  const viewport = document.getElementById('stories-slider-viewport');
  const slider = document.getElementById('stories-slider');
  if (!viewport || !slider) return;
  const previous = slider.querySelector('.stories-slider-prev');
  const next = slider.querySelector('.stories-slider-next');
  const status = document.getElementById('stories-slider-status');
  const step = () => viewport.clientWidth;
  function update() {
    const max = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
    previous.disabled = viewport.scrollLeft <= 2;
    next.disabled = viewport.scrollLeft >= max - 2;
    if (status) {
      const cards = [...viewport.querySelectorAll('.stories-slider-card')];
      const visible = cards.filter(card => card.offsetLeft + card.offsetWidth > viewport.scrollLeft + 2 && card.offsetLeft < viewport.scrollLeft + viewport.clientWidth - 2);
      const articleVisible = visible.filter(card => !card.classList.contains('stories-show-all')).length;
      status.textContent = articleVisible ? 'Showing ' + articleVisible + ' ' + (articleVisible === 1 ? 'story' : 'stories') + ' in this view' : 'Show all published stories';
    }
  }
  previous.addEventListener('click', () => viewport.scrollBy({left: -step(), behavior: 'smooth'}));
  next.addEventListener('click', () => viewport.scrollBy({left: step(), behavior: 'smooth'}));
  viewport.addEventListener('scroll', update, {passive:true});
  window.addEventListener('resize', update);
  update();
})();
</script>
<script src="https://unpkg.com/lucide@latest"></script><script src="https://code.iconify.design/iconify-icon/3.0.0/iconify-icon.min.js"></script><script>if(window.lucide?.createIcons&&window.lucide?.icons)window.lucide.createIcons({icons:window.lucide.icons});</script>

<style>
/* Colorful glass icon tiles on the public Mega Menu */
.mega-menu-section .module-grid>.module-card .panel-lucide-icon{width:76px;height:76px;margin:0 auto 14px;color:#7be7ff;background:linear-gradient(145deg,rgba(33,201,255,.28),rgba(125,93,255,.25) 52%,rgba(255,89,174,.2));border:1px solid rgba(133,224,255,.48);border-radius:22px;box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(48,150,255,.22),0 0 22px rgba(116,91,255,.14);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.mega-menu-section .module-grid>.module-card:nth-child(3n+2) .panel-lucide-icon{color:#ff9bd2;background:linear-gradient(145deg,rgba(255,85,173,.3),rgba(151,92,255,.24),rgba(255,186,93,.16));border-color:rgba(255,164,218,.48);box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(224,75,171,.2)}
.mega-menu-section .module-grid>.module-card:nth-child(3n) .panel-lucide-icon{color:#a8ffbd;background:linear-gradient(145deg,rgba(57,224,154,.28),rgba(42,185,255,.22),rgba(169,255,119,.15));border-color:rgba(160,255,194,.46);box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(49,215,157,.18)}
.mega-menu-section .module-grid>.module-card .panel-lucide-icon svg,.mega-menu-section .module-grid>.module-card .panel-lucide-icon iconify-icon{width:39px;height:39px;stroke:currentColor;stroke-width:2.15;filter:drop-shadow(0 0 7px currentColor)}

.mega-menu-section .module-grid>.mega-public-panel{position:relative;isolation:isolate;overflow:hidden}
.mega-menu-section .module-grid>.mega-public-panel .mega-panel-icon-background{
  position:absolute;inset:0;z-index:0;display:flex;align-items:center;justify-content:center;
  overflow:hidden;pointer-events:none;color:hsl(var(--icon-hue,205) 92% 70%);background:transparent
}
.mega-menu-section .module-grid>.mega-public-panel .mega-panel-icon-background iconify-icon{
  display:block;width:auto;height:auto;font-size:clamp(150px,19vw,250px);line-height:1;opacity:.25;
  color:currentColor;filter:drop-shadow(0 0 24px currentColor)
}
.mega-menu-section .module-grid>.mega-public-panel>.mega-panel-icon-background~.mega-panel-preview-content{
  position:relative;z-index:1;display:flex;flex:1;flex-direction:column;align-items:flex-start;width:100%;min-height:100%
}
.mega-menu-section .module-grid>.mega-public-panel>.mega-panel-preview-content>.module-link{margin-top:auto}
.mega-menu-section .module-grid>.mega-public-panel:has(>.mega-panel-icon-background){
  background:linear-gradient(145deg,rgba(27,17,37,.72),rgba(10,13,24,.88))
}

.mega-menu-section .module-grid>.mega-public-panel .mega-panel-description{display:block;margin:8px 0 12px;max-width:100%;color:rgba(255,255,255,.76);font-size:9px;line-height:1.55;font-weight:400;text-align:left;white-space:normal;overflow-wrap:anywhere}


/* Synced from Page Management > Sections > Mega Menu Live Preview. */
/* Keep the public Mega Menu visually identical to the Admin Live Preview. */
.mega-menu-section{display:block!important;text-align:left!important;padding:0!important;overflow:visible!important}
.mega-menu-section.section{padding:54px 0 62px!important}
.mega-menu-section>.wrap{width:min(1200px,calc(100% - 44px));max-width:none;margin-left:auto;margin-right:auto}
.mega-menu-section .category-row{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}
.mega-menu-section .category{position:relative;isolation:isolate;padding:21px 16px;background:linear-gradient(145deg,rgba(255,255,255,.045),rgba(255,255,255,.012));border:1px solid rgba(255,255,255,.12);border-radius:16px;min-height:128px;overflow:hidden;transition:transform .22s ease,border-color .22s ease,background .22s ease;color:inherit;text-decoration:none}
.mega-menu-section .category:after{content:"";position:absolute;z-index:-1;width:90px;height:90px;right:-42px;bottom:-44px;border:1px solid rgba(216,180,119,.2);border-radius:50%;box-shadow:0 0 0 14px rgba(216,180,119,.025)}
.mega-menu-section .category:hover{transform:translateY(-5px);border-color:rgba(216,180,119,.55);background:linear-gradient(145deg,rgba(105,8,24,.5),rgba(255,255,255,.02))}
.mega-menu-section .category .symbol{font-size:25px;color:#d8b477}
.mega-menu-section .module-grid{grid-template-columns:repeat(5,minmax(0,1fr))}
.mega-menu-section .module-card{min-height:230px;display:flex;flex-direction:column;align-items:flex-start;padding:22px 18px}
.mega-menu-section .module-card strong{display:block;margin-top:16px;font-size:14px;font-weight:700;line-height:1.4;min-height:40px;color:#f4e9ec}
.mega-menu-section .module-link{display:block;margin-top:auto;padding-top:17px;font-size:10px;font-weight:800;letter-spacing:.7px;color:#e05260}
.mega-menu-section .mega-panel-cover{width:100%;height:110px;object-fit:cover;border-radius:9px;margin-bottom:10px}
.mega-menu-section .module-grid>.module-card .panel-lucide-icon{width:76px;height:76px;margin:0 auto 14px;color:#7be7ff;background:linear-gradient(145deg,rgba(33,201,255,.28),rgba(125,93,255,.25) 52%,rgba(255,89,174,.2));border:1px solid rgba(133,224,255,.48);border-radius:22px;box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(48,150,255,.22),0 0 22px rgba(116,91,255,.14);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.mega-menu-section .module-grid>.module-card:nth-child(3n+2) .panel-lucide-icon{color:#ff9bd2;background:linear-gradient(145deg,rgba(255,85,173,.3),rgba(151,92,255,.24),rgba(255,186,93,.16));border-color:rgba(255,164,218,.48);box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(224,75,171,.2)}
.mega-menu-section .module-grid>.module-card:nth-child(3n) .panel-lucide-icon{color:#a8ffbd;background:linear-gradient(145deg,rgba(57,224,154,.28),rgba(42,185,255,.22),rgba(169,255,119,.15));border-color:rgba(160,255,194,.46);box-shadow:inset 0 1px 0 rgba(255,255,255,.34),0 12px 30px rgba(49,215,157,.18)}
.mega-menu-section .module-grid>.module-card .panel-lucide-icon svg,.mega-menu-section .module-grid>.module-card .panel-lucide-icon iconify-icon{width:39px;height:39px;stroke:currentColor;stroke-width:2.15;filter:drop-shadow(0 0 7px currentColor)}
.mega-menu-section .module-grid>.mega-public-panel{position:relative}
.mega-menu-section .module-grid>.mega-public-panel[data-description]:after{content:attr(data-description);position:absolute;left:50%;bottom:calc(100% + 12px);z-index:30;width:max-content;max-width:min(320px,82vw);padding:12px 15px;border:1px solid rgba(255,255,255,.22);border-radius:13px;background:rgba(18,13,24,.96);color:#fff;font-size:12px;line-height:1.55;text-align:left;white-space:normal;box-shadow:0 14px 36px rgba(0,0,0,.44),inset 0 1px 0 rgba(255,255,255,.12);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);opacity:0;visibility:hidden;transform:translate(-50%,6px);transition:opacity .18s ease,transform .18s ease,visibility .18s ease;pointer-events:none}
.mega-menu-section .module-grid>.mega-public-panel[data-description]:before{content:"";position:absolute;left:50%;bottom:calc(100% + 4px);z-index:31;border:7px solid transparent;border-top-color:rgba(18,13,24,.96);opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease;pointer-events:none}
.mega-menu-section .module-grid>.mega-public-panel[data-description]:hover:after,.mega-menu-section .module-grid>.mega-public-panel[data-description]:focus-visible:after,.mega-menu-section .module-grid>.mega-public-panel[data-description]:hover:before,.mega-menu-section .module-grid>.mega-public-panel[data-description]:focus-visible:before{opacity:1;visibility:visible;transform:translate(-50%,0)}
.mega-menu-section .module-grid>.mega-public-panel[data-description]:hover:before,.mega-menu-section .module-grid>.mega-public-panel[data-description]:focus-visible:before{transform:translateX(-50%)}
@media(max-width:900px){.mega-menu-section .module-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:560px){.mega-menu-section>.wrap{width:calc(100% - 32px)}.mega-menu-section .module-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(hover:none){.mega-menu-section .module-grid>.mega-public-panel[data-description]:after{display:none}}
 
</style>
<script>
(function(){
 const menu=document.getElementById('creator-account-menu');
 const trigger=document.getElementById('creator-account-trigger');
 const dropdown=document.getElementById('creator-account-dropdown');
 if(!menu||!trigger||!dropdown)return;
 const closeMenu=()=>{dropdown.classList.remove('is-open');trigger.setAttribute('aria-expanded','false');};
 trigger.addEventListener('click',function(event){event.stopPropagation();const open=!dropdown.classList.contains('is-open');dropdown.classList.toggle('is-open',open);trigger.setAttribute('aria-expanded',open?'true':'false');});
 document.addEventListener('click',function(event){if(!menu.contains(event.target))closeMenu();});
 document.addEventListener('keydown',function(event){if(event.key==='Escape'){closeMenu();trigger.focus();}});
})();
</script>
<style id="mega-menu-intro-styles">
.mega-menu-intro{width:100%;max-width:none;margin:0 0 30px;text-align:left}
.mega-menu-intro .eyebrow{margin:0 0 10px;text-align:left}
.mega-menu-intro h2{margin:0 0 12px;text-align:left}
.mega-menu-intro .mega-menu-description{width:100%;max-width:none;margin:0;color:var(--muted,#cbbfc4);line-height:1.75;text-align:left}
.mega-menu-intro .mega-menu-description p{margin:0 0 8px}
.mega-menu-intro-preview{margin:0 auto 26px;text-align:center;max-width:900px}
.mega-menu-intro-preview .preview-desc{width:100%;max-width:none;margin:0}
.mega-menu-rich-grid{margin:22px 0 28px}
</style>

<style>
.public-forum-list{border:1px solid rgba(255,255,255,.1);border-radius:16px;overflow:hidden;background:rgba(18,15,20,.58)}
.public-forum-list-head,.public-forum-thread{display:grid;grid-template-columns:minmax(0,1fr) 150px 190px;gap:22px;align-items:center;padding:16px 20px}
.public-forum-list-head{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c9b9c0;background:rgba(255,255,255,.045)}
.public-forum-thread{border-top:1px solid rgba(255,255,255,.08);min-height:94px}
.public-forum-thread:first-of-type{border-top:0}
.public-forum-thread-top{display:flex;align-items:center;gap:10px;margin-bottom:9px}
.public-forum-avatar{width:34px;height:34px;flex:0 0 34px;object-fit:cover;border-radius:50%;border:1px solid rgba(255,255,255,.2)}
.public-forum-topic-avatar{width:36px;height:36px;flex-basis:36px}
.public-forum-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:#512735;color:#fff;font-weight:700}
.public-forum-content-icon{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;border-radius:8px;background:rgba(188,49,67,.16);color:#f18b98;font-size:15px;font-weight:700}
.public-forum-date{font-size:11px;color:#bbaeb4}.public-forum-prefix-subcategory{font-size:11px;font-weight:600;color:#f0c3cd;padding:4px 8px;border-radius:6px;background:rgba(188,49,67,.12)}
.public-forum-topic h3{margin:0;font-size:15px;line-height:1.4}
.public-forum-topic h3 a{color:#fff;text-decoration:none}
.public-forum-topic h3 a:hover{color:#ff9ba7;text-decoration:underline}
.public-forum-stats{display:grid;grid-template-columns:auto 1fr;column-gap:8px;row-gap:3px;align-items:baseline}
.public-forum-stats strong{font-size:14px;font-variant-numeric:tabular-nums}
.public-forum-stats small{font-size:11px;color:#bbaeb4}
.public-forum-activity{display:flex;align-items:center;gap:10px;min-width:0}
.public-forum-activity strong,.public-forum-activity small{display:block}
.public-forum-activity strong{font-size:12px}
.public-forum-activity small{font-size:11px;color:#bbaeb4;margin-top:3px}
.public-forum-detail{padding:24px;max-width:900px;margin:0 auto}
.public-forum-detail-author{display:flex;align-items:center;gap:12px}
.public-forum-detail-author strong,.public-forum-detail-author small{display:block}
.public-forum-detail-author small{font-size:11px;color:#bbaeb4;margin-top:3px}
.public-forum-detail-type{display:flex;align-items:center;gap:8px;margin-top:22px;color:#cdbdc2;font-size:12px}
.public-forum-detail h2{font-size:25px;margin:12px 0 18px}
.public-forum-detail-body{font-size:15px;line-height:1.8;overflow-wrap:anywhere}.public-forum-attachments{margin:22px 0 8px;padding-top:18px;border-top:1px solid rgba(255,255,255,.1)}.public-forum-attachments h3{margin:0 0 12px;color:#f7eef0;font-size:15px}.public-forum-attachment-list{display:grid;gap:9px}.public-forum-attachment{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(255,255,255,.035);color:inherit;text-decoration:none;transition:border-color .2s,background .2s}.public-forum-attachment:hover{border-color:rgba(215,154,165,.55);background:rgba(255,255,255,.07)}.public-forum-attachment-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:10px;background:rgba(150,35,58,.22);color:#f0a6b3;font-size:20px}.public-forum-attachment-info{display:grid;gap:3px;min-width:0;flex:1}.public-forum-attachment-info strong{overflow-wrap:anywhere;color:#f7eef0;font-size:13px}.public-forum-attachment-info small,.public-forum-attachment-open{color:#cbbfc4;font-size:11px}.public-forum-attachment-open{white-space:nowrap}.public-forum-attachments-locked{margin:18px 0 8px;padding:12px 14px;border:1px solid #e5d36b;border-radius:10px;background:#fff3b0;color:#594b16;font-size:13px;box-shadow:0 2px 10px rgba(0,0,0,.08)}.public-forum-attachments-locked a{color:#6c4b00;font-weight:800;text-decoration:underline}
.public-forum-detail-body img{max-width:100%;height:auto;border-radius:10px}
.public-forum-detail-actions{display:flex;align-items:center;gap:18px;flex-wrap:wrap;border-top:1px solid rgba(255,255,255,.1);padding-top:18px;margin-top:24px;font-size:12px;color:#cdbdc2}
.public-forum-detail-actions strong{color:#fff}
.public-forum-detail-actions form{margin:0}
.public-forum-like-button{padding:8px 14px}
.public-forum-replies,.public-forum-reply-form{max-width:900px;margin:24px auto 0}
.public-forum-replies h3,.public-forum-reply-form h3{font-size:17px;margin:0 0 16px}
.public-forum-reply{display:flex;gap:12px;padding:16px 0;border-bottom:1px solid rgba(255,255,255,.08)}
.public-forum-reply-content{flex:1;min-width:0}
.public-forum-reply-meta{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px}
.public-forum-reply-meta strong{display:block;flex:1 1 220px;min-width:0;max-width:none;white-space:normal;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:normal;line-height:1.5}
.public-forum-reply-meta time{flex:0 0 auto;color:#bbaeb4;font-size:11px}
.public-forum-reply-content p{margin:8px 0 0;line-height:1.65;overflow-wrap:anywhere}
.public-forum-reply-form{padding:22px}.public-forum-reply-context{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;padding:9px 12px;border:1px solid rgba(202,102,122,.35);border-radius:8px;background:rgba(196,74,99,.12);color:#f4dce2;font-size:12px}.public-forum-reply-context[hidden]{display:none}.public-forum-reply-context button{border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:4px 9px;background:transparent;color:#fff;font-size:11px;cursor:pointer}.public-forum-reply-context button:hover{background:rgba(255,255,255,.1)}
.public-forum-reply-form label{display:block;margin-bottom:8px;font-size:12px}
.public-forum-reply-form textarea{display:block;width:100%;min-height:120px;resize:vertical;padding:12px;border:1px solid rgba(255,255,255,.18);border-radius:10px;background:rgba(0,0,0,.22);color:#fff;font:inherit;margin-bottom:14px}
.public-forum-alert{max-width:900px;margin:0 auto 16px;padding:14px;color:#ffb4bd}.public-forum-report{font-size:12px;color:#f0cbd1}.public-forum-report summary{cursor:pointer;list-style:none;padding:7px 10px;border:1px solid rgba(255,255,255,.16);border-radius:8px}.public-forum-report form{display:grid;gap:8px;min-width:230px;padding:12px;margin-top:8px;background:#24171d;border:1px solid rgba(255,255,255,.14);border-radius:10px}.public-forum-report label{display:grid;gap:5px}.public-forum-report select,.public-forum-report textarea{width:100%;padding:8px;background:#120d10;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:7px;font:inherit}.public-forum-reply{margin-left:calc(min(var(--reply-depth,0),4)*28px);padding-left:12px;border-left:2px solid rgba(202,102,122,.22)}.public-forum-reply-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}.public-forum-reply-actions form{margin:0}.public-forum-reaction-picker,.public-forum-editor-emoji{position:relative;display:inline-block}.public-forum-reaction-picker>summary,.public-forum-editor-emoji>summary{list-style:none;cursor:pointer}.public-forum-reaction-picker>summary::-webkit-details-marker,.public-forum-editor-emoji>summary::-webkit-details-marker{display:none}.public-forum-reaction-options{position:absolute;z-index:30;bottom:calc(100% + 7px);left:0;display:flex;gap:4px;padding:7px;background:#24171d;border:1px solid rgba(255,255,255,.2);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.35);white-space:nowrap}.public-forum-reaction-options form{margin:0}.public-forum-reaction-counts{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap;margin-left:2px;vertical-align:middle}.public-forum-reaction-counts>span{display:inline-flex;align-items:center;gap:3px;padding:2px 5px;border:1px solid rgba(255,255,255,.1);border-radius:999px;background:rgba(255,255,255,.045);color:#e9d7dc;font-size:11px;line-height:1.3}.public-forum-reaction-counts b{font-size:10px;font-weight:700;color:#fff}.public-forum-reply-reaction-counts{gap:3px}.public-forum-reply-reaction-counts>span{padding:1px 4px;font-size:10px}.public-forum-reaction-options button{display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:7px;background:transparent;color:#fff;font-size:20px;cursor:pointer}.public-forum-reaction-options button:hover{background:rgba(255,255,255,.13);transform:scale(1.12)}.public-forum-editor-emoji{height:30px;min-width:30px;border:1px solid rgba(255,255,255,.16);border-radius:6px;background:#2a1b22;color:#f8edf0}.public-forum-editor-emoji>summary{display:grid;place-items:center;height:28px;padding:0 8px}.public-forum-editor-emoji .public-forum-reaction-options{top:calc(100% + 7px);bottom:auto;left:auto;right:0}.public-forum-editor-emoji .public-forum-reaction-options button{border:0;background:transparent}.public-forum-reply-reaction>summary{min-width:0;width:auto;height:25px;padding:0 6px;font-size:11px}.public-forum-report>summary{display:inline-flex;align-items:center;gap:4px}.public-forum-editor-toolbar button:focus-visible,.public-forum-editor-emoji>summary:focus-visible{outline:2px solid #e9a5b1;outline-offset:2px}.public-forum-action-link{border:0;padding:0;background:none;color:#e9a5b1;font:inherit;font-size:11px;cursor:pointer}.public-forum-reply-report summary{padding:0;border:0}.public-forum-notice{color:#a9e3b8;font-size:13px}.public-forum-reply-actions{display:flex;justify-content:flex-end;align-items:center;margin-top:10px}.public-forum-reply-actions-inner{display:flex;justify-content:flex-end;align-items:center;gap:5px}.public-forum-reply-actions-inner form{display:inline-flex}.public-forum-action-button{min-width:27px;width:auto;height:25px;display:inline-flex;align-items:center;justify-content:center;gap:3px;padding:0 6px;border:1px solid rgba(255,255,255,.13);border-radius:6px;background:rgba(255,255,255,.045);color:#e9a5b1;font-size:12px;cursor:pointer;text-decoration:none}.public-forum-action-button:hover{background:rgba(196,74,99,.2);color:#fff}.public-forum-reply-rich-content{margin-top:8px;line-height:1.7;overflow-wrap:anywhere}.public-forum-reply-rich-content p{margin:7px 0}.public-forum-reply-rich-content img{max-width:100%;height:auto;border-radius:8px;display:inline-block}.public-forum-reply-editor img{display:inline-block;max-width:100%;height:auto;resize:both;overflow:auto;min-width:80px;min-height:60px;border-radius:8px;vertical-align:middle;cursor:nwse-resize}.public-forum-reply-rich-content table{border-collapse:collapse;width:100%;margin:10px 0}.public-forum-reply-rich-content th,.public-forum-reply-rich-content td{border:1px solid rgba(255,255,255,.2);padding:7px;text-align:left}.public-forum-reply-rich-content blockquote{margin:10px 0;padding:8px 12px;border-left:3px solid #a94b60;background:rgba(255,255,255,.04)}.public-forum-reply-rich-content pre{padding:12px;overflow:auto;border-radius:8px;background:#100b0e}.public-forum-editor-toolbar{display:flex;flex-wrap:wrap;gap:5px;padding:9px;border:1px solid rgba(255,255,255,.16);border-radius:10px 10px 0 0;background:#1b1217}.public-forum-editor-toolbar button,.public-forum-editor-toolbar select,.reply-color-control{height:30px;min-width:30px;padding:4px 8px;border:1px solid rgba(255,255,255,.16);border-radius:6px;background:#2a1b22;color:#f8edf0;font:inherit;font-size:12px;cursor:pointer}.public-forum-editor-toolbar select{max-width:112px}.reply-color-control{position:relative;display:inline-flex;align-items:center;justify-content:center;font-weight:700}.reply-color-control input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}#public-forum-reply-body[hidden]{display:none}.public-forum-reply-editor{min-height:170px;padding:13px;border:1px solid rgba(255,255,255,.18);border-top:0;border-radius:0 0 10px 10px;background:rgba(0,0,0,.22);color:#fff;font:inherit;line-height:1.65;outline:none;overflow-wrap:anywhere}.public-forum-reply-editor:focus{border-color:#b85a70}.public-forum-reply-editor:empty:before{content:attr(data-placeholder);color:#9c8b92;pointer-events:none}.public-forum-reply-editor img{max-width:100%;height:auto}.public-forum-editor-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.public-forum-editor-footer small{color:#bbaeb4}
@media(max-width:760px){.public-forum-list-head{display:none}.public-forum-thread{grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:15px}.public-forum-stats{grid-column:1;grid-row:2;display:flex;gap:6px;align-items:center}.public-forum-stats small{margin-right:8px}.public-forum-activity{grid-column:2;grid-row:1/3;flex-direction:column;align-items:flex-end;text-align:right}.public-forum-detail{padding:17px}.public-forum-detail h2{font-size:21px}.public-forum-reply-meta{flex-direction:column;gap:3px}}
</style>
<?php if($activeForumPanel && $publicForumThreadId>0 && $publicForumDetail && $isCreatorSignedIn): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const composer=document.getElementById('public-forum-reply-composer'),editor=document.getElementById('public-forum-reply-editor'),textarea=document.getElementById('public-forum-reply-body'),parent=document.getElementById('public-forum-parent-reply-id'),heading=document.getElementById('public-forum-reply-heading'),form=document.getElementById('public-forum-reply-form');
 if(!editor||!form)return;let savedRange=null;
 const save=()=>{const sel=window.getSelection();if(sel&&sel.rangeCount&&editor.contains(sel.anchorNode))savedRange=sel.getRangeAt(0).cloneRange();};
 const restore=()=>{editor.focus();if(savedRange){const sel=window.getSelection();sel.removeAllRanges();sel.addRange(savedRange);}};
 editor.addEventListener('keyup',save);editor.addEventListener('mouseup',save);editor.addEventListener('input',save);
 document.querySelectorAll('[data-reply-command]').forEach(button=>{button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>{restore();document.execCommand(button.dataset.replyCommand,false,button.dataset.replyValue||null);save();});});
 const fontSize=document.getElementById('reply-font-size');
 fontSize?.addEventListener('mousedown',save);fontSize?.addEventListener('focus',save);
 fontSize?.addEventListener('change',e=>{if(e.target.value){restore();document.execCommand('fontSize',false,e.target.value);save();e.target.value='';}});
 const fontColor=document.getElementById('reply-font-color');
 fontColor?.addEventListener('focus',save);fontColor?.addEventListener('input',e=>{restore();document.execCommand('foreColor',false,e.target.value);save();});
 document.querySelector('[data-reply-tool="link"]')?.addEventListener('mousedown',e=>e.preventDefault());document.querySelector('[data-reply-tool="link"]')?.addEventListener('click',()=>{const url=prompt('Enter link URL (https://, http://, or mailto:):');if(url&&/^(https?:\/\/|mailto:)/i.test(url)){restore();document.execCommand('createLink',false,url);save();}});
 const photoInput=document.getElementById('reply-photo-input');
 const photoFiles=[];let photoUrls=[];
 const syncPhotoInput=()=>{if(window.DataTransfer){const transfer=new DataTransfer();photoFiles.forEach(file=>transfer.items.add(file));photoInput.files=transfer.files;}};
 document.querySelector('[data-reply-tool="photo"]')?.addEventListener('mousedown',save);
 document.querySelector('[data-reply-tool="photo"]')?.addEventListener('click',()=>photoInput?.click());
 photoInput?.addEventListener('change',()=>{
  const allowed=new Set(['image/jpeg','image/png','image/avif','image/webp']);
  const selected=Array.from(photoInput.files||[]);
  if(photoFiles.length+selected.length>5){alert('You may attach up to five photos per reply.');syncPhotoInput();return;}
  if(selected.some(file=>!allowed.has(file.type)||file.size<1||file.size>8*1024*1024)){alert('Choose JPEG, JPG, PNG, AVIF, or WEBP photos, up to 8 MB each.');syncPhotoInput();return;}
  restore();
  selected.forEach(file=>{
   const index=photoFiles.length;photoFiles.push(file);
   const url=URL.createObjectURL(file);photoUrls.push(url);
   const frame=document.createElement('div');frame.style.cssText='display:inline-block;resize:both;overflow:auto;width:320px;height:220px;max-width:100%;min-width:80px;min-height:60px;vertical-align:middle;';frame.setAttribute('role','group');frame.setAttribute('aria-label','Resizable reply photo');
   const img=document.createElement('img');img.src=url;img.alt='Reply photo';img.dataset.replyPhotoIndex=String(index);img.style.cssText='display:block;width:100%;height:100%;object-fit:contain;';frame.appendChild(img);
   const range=savedRange||document.createRange();range.deleteContents();range.insertNode(frame);
   range.setStartAfter(frame);range.collapse(true);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);savedRange=range.cloneRange();
  });
  syncPhotoInput();
  editor.focus();
 });
 document.querySelector('[data-reply-tool="table"]')?.addEventListener('mousedown',e=>e.preventDefault());document.querySelector('[data-reply-tool="table"]')?.addEventListener('click',()=>{const rows=Math.min(10,Math.max(1,Number(prompt('Number of rows?','3')||0))),cols=Math.min(8,Math.max(1,Number(prompt('Number of columns?','3')||0)));if(!rows||!cols)return;let h='<table><tbody>';for(let r=0;r<rows;r++){h+='<tr>';for(let c=0;c<cols;c++)h+=r===0?'<th>Heading</th>':'<td>Text</td>';h+='</tr>';}h+='</tbody></table><p><br></p>';restore();document.execCommand('insertHTML',false,h);save();});
 document.querySelector('[data-reply-tool="code"]')?.addEventListener('mousedown',e=>e.preventDefault());document.querySelector('[data-reply-tool="code"]')?.addEventListener('click',()=>{restore();document.execCommand('formatBlock',false,'pre');save();});
 document.querySelectorAll('[data-editor-emoji]').forEach(button=>button.addEventListener('mousedown',e=>e.preventDefault()));document.querySelectorAll('[data-editor-emoji]').forEach(button=>button.addEventListener('click',()=>{restore();document.execCommand('insertText',false,button.dataset.editorEmoji);save();button.closest('details')?.removeAttribute('open');}));
 document.querySelectorAll('.public-forum-editor-toolbar [data-reply-tool],.public-forum-editor-emoji').forEach(control=>control.addEventListener('mousedown',save));
 form.addEventListener('submit',e=>{parent.value=form.dataset.replyTarget||parent.value||'0';const content=editor.cloneNode(true);content.querySelectorAll('img[data-reply-photo-index]').forEach(img=>{const index=Number(img.dataset.replyPhotoIndex);img.replaceWith(document.createTextNode('[[FORUM_REPLY_PHOTO_'+index+']]'));});textarea.value=content.innerHTML.trim();const hasText=editor.textContent.trim().length>0;const hasImage=!!editor.querySelector('img');if(!hasText&&!hasImage){e.preventDefault();alert('Write your reply before posting.');editor.focus();return;}const submitButton=form.querySelector('button[type="submit"]');if(submitButton){submitButton.disabled=true;submitButton.dataset.originalText=submitButton.textContent;submitButton.textContent='Posting…';}photoUrls.forEach(url=>URL.revokeObjectURL(url));});
 const replyContext=document.getElementById('public-forum-reply-context');
 const setReplyTarget=(replyId,author)=>{const id=Number.parseInt(String(replyId||'0'),10);if(!Number.isInteger(id)||id<1)return;parent.value=String(id);form.dataset.replyTarget=String(id);heading.textContent='Replying to '+(author||'this reply');if(replyContext){replyContext.hidden=false;replyContext.querySelector('[data-reply-context-label]').textContent='Your reply will be posted as a response to '+(author||'this reply')+'.';}composer.scrollIntoView({behavior:'smooth',block:'center'});editor.focus();};
 document.addEventListener('click',event=>{const button=event.target.closest('[data-reply-to]');if(!button)return;event.preventDefault();setReplyTarget(button.dataset.replyTo,button.dataset.replyAuthor);});
 document.querySelector('[data-reply-cancel]')?.addEventListener('click',()=>{parent.value='0';delete form.dataset.replyTarget;heading.textContent='Post a reply';if(replyContext)replyContext.hidden=true;});

 document.querySelectorAll('[data-quote-reply]').forEach(button=>button.addEventListener('click',()=>{parent.value=button.dataset.quoteReply;heading.textContent='Quoting '+button.dataset.quoteAuthor;const q=document.createElement('blockquote');q.textContent=button.dataset.quoteAuthor+' wrote: '+button.dataset.quoteBody;editor.prepend(q,document.createElement('p'));composer.scrollIntoView({behavior:'smooth',block:'center'});editor.focus();save();}));
});
</script>
<?php endif; ?>
</body></html>
