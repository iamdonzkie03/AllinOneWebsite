<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();
$nicknameStmt = $pdo->prepare('SELECT nickname FROM users WHERE id = ? LIMIT 1');
$nicknameStmt->execute([(int)$admin['id']]);
$adminNickname = trim((string)($nicknameStmt->fetchColumn() ?: 'Administrator'));
$message = '';
$galleryDeleteSuccessNames = [];
$galleryPhotoDeleteSuccessCount = max(0, (int)($_SESSION['gallery_photo_delete_success_count'] ?? 0));
// The query flag is a one-request rendering fallback if the hosting PHP session
// handler fails to persist a flash value across the POST/redirect/GET cycle.
$galleryPhotoDeleteSuccess = $galleryPhotoDeleteSuccessCount > 0 || (($_GET['gallery_photo_deleted'] ?? '') === '1');
$galleryReturnFolder = trim((string)($_SESSION['gallery_photo_delete_success_folder'] ?? ($_POST['gallery_return_folder'] ?? $_GET['gallery_folder'] ?? '')));
unset($_SESSION['gallery_photo_delete_success_count'], $_SESSION['gallery_photo_delete_success_folder']);
// Carry the exact edit result in the redirect URL as well as the session flash.
 // The URL value keeps the success message reliable even when the PHP session
 // handler does not persist a flash value between the POST and redirect requests.
$galleryPhotoEditSuccessMessage = trim((string)($_GET['gallery_photo_edit_message'] ?? $_SESSION['gallery_photo_edit_success_message'] ?? ''));
$galleryPhotoEditOldTitle = trim((string)($_GET['gallery_old_photo_title'] ?? ''));
$galleryPhotoEditNewTitle = trim((string)($_GET['gallery_new_photo_title'] ?? ''));
$galleryPhotoReplacedTitle = trim((string)($_GET['gallery_replaced_photo_title'] ?? ''));
$galleryPhotoEditIsTitleRename = $galleryPhotoEditOldTitle !== '' && $galleryPhotoEditNewTitle !== '';
$galleryPhotoEditSuccessFolder = trim((string)($_GET['gallery_folder'] ?? $_SESSION['gallery_photo_edit_success_folder'] ?? ''));
$galleryPhotoEditSuccess = $galleryPhotoEditSuccessMessage !== '' || (($_GET['gallery_photo_edit_success'] ?? '') === '1');
unset($_SESSION['gallery_photo_edit_success_message'], $_SESSION['gallery_photo_edit_success_folder']);
$galleryEditSuccess = false;
$galleryEditSuccessName = '';
$galleryEditSuccessMessage = '';
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
  logo_width SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  logo_height SMALLINT UNSIGNED NOT NULL DEFAULT 70,
  title_html LONGTEXT NULL,
  hidden_left_html LONGTEXT NULL,
  hidden_right_html LONGTEXT NULL,
  typography_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Additive compatibility for databases created before independent header typography was introduced.
foreach (['logo_width'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 200','logo_height'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 70','title_html'=>'LONGTEXT NULL','hidden_left_html'=>'LONGTEXT NULL','hidden_right_html'=>'LONGTEXT NULL','typography_json'=>'LONGTEXT NULL'] as $column=>$definition) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='site_header_settings' AND COLUMN_NAME=?");
    $exists->execute([$column]);
    if (!(int)$exists->fetchColumn()) $pdo->exec("ALTER TABLE site_header_settings ADD COLUMN {$column} {$definition}");
}
function isValidGalleryFolderName(string $name): bool {
    if ($name === '' || mb_strlen($name) > 80 || $name === '.' || $name === '..') return false;
    // Allow letters, numbers, spaces and symbols, while blocking path separators,
    // control characters and characters that Windows cannot use in folder names.
    if (preg_match('~[\\x00-\\x1F\\x7F/\\\\:*?"<>|]~u', $name)) return false;
    if (preg_match('~[ .]$~u', $name)) return false;
    $reserved = ['CON','PRN','AUX','NUL','COM1','COM2','COM3','COM4','COM5','COM6','COM7','COM8','COM9','LPT1','LPT2','LPT3','LPT4','LPT5','LPT6','LPT7','LPT8','LPT9'];
    $deviceName = strtoupper(explode('.', $name, 2)[0]);
    return !in_array($deviceName, $reserved, true);
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

// Footer content is stored separately so the admin preview and public footer
// always use the same saved configuration.
$pdo->exec("CREATE TABLE IF NOT EXISTS site_footer_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  content_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$defaultFooterContent = json_decode('{"brand":"Z.C. commonplace","description":"Zamboanga stories, culture, creativity and community — shared with care.","copyright":"© {year} Z.C. commonplace. Community publishing with administrator review.","brand_links":[],"explore_title":"Explore","explore_links":[{"label":"Creative Arts and Photography","url":"?category=creative-arts-photography#latest"},{"label":"News Connect","url":"?category=news-connect#latest"},{"label":"Marketplace","url":"?category=marketplace#latest"}],"discover_title":"Discover","discover_links":[{"label":"Entertainment & Politics","url":"?category=entertainment-politics#latest"},{"label":"History & Culture","url":"?category=history-culture#latest"},{"label":"Zamboanga heritage slideshow","url":"#zamboanga"}]}', true) ?: [];
$pdo->exec("INSERT IGNORE INTO site_footer_settings (id, content_json) VALUES (1, '".str_replace("'", "''", json_encode($defaultFooterContent, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))."')");
$footerRow = $pdo->query("SELECT content_json FROM site_footer_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$footerSettings = json_decode((string)($footerRow['content_json'] ?? ''), true);
if (!is_array($footerSettings)) $footerSettings = [];
$footerSettings = array_replace($defaultFooterContent, $footerSettings);

$pdo->exec("CREATE TABLE IF NOT EXISTS site_sections_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  content_json LONGTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$defaultSections = [
 'banner'=>['main_title'=>'ONE CITY. MANY STORIES.','heading'=>'Zamboanga, through our eyes.','description'=>'<p>A digital home for the city\'s history, culture, creative talent, community news, marketplace, entertainment and public conversation.</p>','button_label'=>'Explore our heritage','button_url'=>'#zamboanga','button_color'=>'#b8323f','button_text_color'=>'#ffffff','button_size'=>'14','button_width'=>'auto','button_height'=>'42','photo'=>''],
 'slideshow'=>['main_title'=>'A LIVING HERITAGE','heading'=>'Discover the story of Zamboanga City','subtitle'=>'From historic landmarks to maritime traditions and a vibrant, multilingual culture.','photos'=>[['title'=>'HERITAGE LANDMARK · 17TH CENTURY','heading'=>'Fort Pilar','description'=>'<p>A landmark shaped by centuries of history, faith and city life.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_(NS_Valderoza,_Zamboanga_City;_10-07-2023).jpg?width=1800'],['title'=>'THE CITY BY THE SEA','heading'=>'Life along the waterfront','description'=>'<p>The sea connects Zamboanga communities and neighboring islands.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Fort_Pilar_Paseo_Del_Mar_bay_view_sunrise_(NS_Valderosa,_Zamboanga_City;_10-11-2023).jpg?width=1800'],['title'=>'MARITIME TRADITION','heading'=>'The colors of the vinta','description'=>'<p>The vinta is an enduring symbol of regional maritime traditions.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Vinta_at_Zamboanga_City_Tourism_Office,_Mar_2026.jpg?width=1600'],['title'=>'ARTISANSHIP · LIVING CULTURE','heading'=>'Patterns that carry stories','description'=>'<p>Traditional weaving reflects generations of skill and cultural knowledge.</p>','photo'=>'https://commons.wikimedia.org/wiki/Special:FilePath/Yakan_Weavers.jpg?width=1800']]],
 'mega_menu'=>['main_title'=>'EXPLORE THE PLATFORM','heading'=>'Five ways to connect','subtitle'=>'A single community space for creativity, information, commerce and cultural memory.','panels'=>[]]
];
$pdo->exec("INSERT IGNORE INTO site_sections_settings (id,content_json) VALUES (1,'".str_replace("'","''",json_encode($defaultSections,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))."')");
$sectionsRow=$pdo->query("SELECT content_json FROM site_sections_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$sectionsSettings=json_decode((string)($sectionsRow['content_json']??''),true);
if(!is_array($sectionsSettings)) $sectionsSettings=$defaultSections;
foreach($defaultSections as $key=>$defaults){$stored=$sectionsSettings[$key]??[];$sectionsSettings[$key]=is_array($stored)?array_replace($defaults,$stored):$defaults;}


$pdo->exec("CREATE TABLE IF NOT EXISTS site_gallery_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  folder_name VARCHAR(120) NULL DEFAULT NULL,
  file_path VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gallery_created (created_at, id),
  KEY idx_gallery_folder (folder_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$galleryFolderColumn = $pdo->query("SHOW COLUMNS FROM site_gallery_photos LIKE 'folder_name'")->fetch(PDO::FETCH_ASSOC);
if ($galleryFolderColumn && strtoupper((string)$galleryFolderColumn['Null']) !== 'YES') $pdo->exec("ALTER TABLE site_gallery_photos MODIFY folder_name VARCHAR(120) NULL DEFAULT NULL");

$headerLinks = json_decode((string)($headerSettings['header_links'] ?? '[]'), true);
if (!is_array($headerLinks)) $headerLinks = [];
$section = (string)($_GET['section'] ?? 'dashboard');
$activePageTab = (string)($_GET['tab'] ?? 'header');
if (!in_array($activePageTab, ['header','footer','contents','sections'], true)) $activePageTab = 'header';
$activeMediaTab = (string)($_GET['media_tab'] ?? 'gallery');
if (!in_array($activeMediaTab, ['gallery','video'], true)) $activeMediaTab = 'gallery';
$allowedSections = ['dashboard', 'settings', 'page-management', 'category-list', 'media-management', 'plugins', 'user-accounts'];
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
    $replacement = "// BEGIN GITHUB-PUBLISHED HEADER SETTINGS
"
        . "\$githubPublishedHeaderSettings = json_decode(base64_decode('" . $encodedSettings . "'), true) ?: [];
"
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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');


    if ($action === 'delete_selected_gallery_folders') {
        $section='media-management'; $activeMediaTab='gallery';
        try {
            $names=array_values(array_unique(array_filter(array_map('strval',(array)($_POST['selected_gallery_folders']??[])),static fn($v)=>$v!=='')));
            if(!$names) throw new RuntimeException('Select at least one folder to delete.');
            $root=__DIR__.'/uploads/gallery'; $realRoot=realpath($root);
            if(!$realRoot) throw new RuntimeException('The Gallery upload folder could not be found.');
            $filesByFolder=[];
            foreach($names as $name){
                if(!isValidGalleryFolderName($name)) throw new RuntimeException('One of the selected folder names is invalid.');
                $dir=realpath($root.DIRECTORY_SEPARATOR.$name);
                if(!$dir||!is_dir($dir)||strpos($dir,$realRoot.DIRECTORY_SEPARATOR)!==0) throw new RuntimeException('The folder "'.$name.'" could not be located.');
                $q=$pdo->prepare('SELECT file_path FROM site_gallery_photos WHERE folder_name=?');$q->execute([$name]);
                foreach($q->fetchAll(PDO::FETCH_COLUMN) as $rel){$path=realpath(__DIR__.'/'.ltrim((string)$rel,'/\\'));if($path&&is_file($path)&&strpos($path,$dir.DIRECTORY_SEPARATOR)===0)$filesByFolder[$name][]=$path;}
            }
            $pdo->beginTransaction();$del=$pdo->prepare('DELETE FROM site_gallery_photos WHERE folder_name=?');foreach($names as $name)$del->execute([$name]);$pdo->commit();
            foreach($names as $name){
                foreach(array_unique($filesByFolder[$name]??[]) as $path)if(is_file($path)&&!@unlink($path))throw new RuntimeException('The photo records were deleted, but a file in "'.$name.'" could not be removed. Check folder permissions.');
                if(is_dir($root.DIRECTORY_SEPARATOR.$name)&&!@rmdir($root.DIRECTORY_SEPARATOR.$name))throw new RuntimeException('Photos were deleted, but folder "'.$name.'" could not be removed. Check for extra files and folder permissions.');
            }
            $galleryDeleteSuccessNames=$names;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message=$e->getMessage();}
    }

    if ($action === 'delete_selected_gallery_photos') {
        $section='media-management'; $activeMediaTab='gallery';
        try {
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected_gallery_photos']??[])),static fn($v)=>$v>0)));
            if(!$ids) throw new RuntimeException('Select at least one photo to delete.');
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $folderForDelete=$galleryReturnFolder!==''?$galleryReturnFolder:null;
            $q=$pdo->prepare("SELECT id,title,file_path,folder_name FROM site_gallery_photos WHERE id IN ($marks) AND folder_name <=> ?");
            $q->execute(array_merge($ids,[$folderForDelete]));$photos=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($photos)!==count($ids)) throw new RuntimeException('One or more selected photos could not be found in this folder. Reload the Gallery and try again.');
            $root=realpath(__DIR__.'/uploads/gallery');$paths=[];
            foreach($photos as $photo){$path=realpath(__DIR__.'/'.ltrim((string)$photo['file_path'],'/\\'));if($path&&is_file($path)&&$root&&strpos($path,$root.DIRECTORY_SEPARATOR)===0)$paths[]=$path;}
            $pdo->beginTransaction();$del=$pdo->prepare("DELETE FROM site_gallery_photos WHERE id IN ($marks) AND folder_name <=> ?");
            $del->execute(array_merge($ids,[$folderForDelete]));$pdo->commit();
            foreach(array_unique($paths) as $path)if(is_file($path)&&!@unlink($path))throw new RuntimeException('The photo records were deleted, but a local photo file could not be removed. Check folder permissions.');
            // Store a one-time session flash before redirecting. This avoids relying
            // on query-string state to decide whether the success modal is rendered.
            $_SESSION['gallery_photo_delete_success_count']=count($photos);
            $_SESSION['gallery_photo_delete_success_folder']=$galleryReturnFolder;
            // Persist the flash before issuing the redirect, even on PHP setups
            // whose session handler does not reliably flush on immediate exit.
            session_write_close();
            $successUrl='admin.php?section=media-management&media_tab=gallery&gallery_folder='.rawurlencode($galleryReturnFolder).'&gallery_photo_deleted=1';
            header('Location: '.$successUrl, true, 303);
            exit;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message=$e->getMessage();}
    }

    if ($action === 'edit_gallery_photo') {
        $section='media-management';$activeMediaTab='gallery';$newFile='';$oldFile='';
        try{
            $id=(int)($_POST['photo_id']??0);$title=mb_substr(trim((string)($_POST['photo_title']??'')),0,180);$desc=mb_substr(trim((string)($_POST['photo_description']??'')),0,2000);
            if($id<1||$title==='')throw new RuntimeException('Enter a valid photo title.');
            $q=$pdo->prepare('SELECT id,title,description,file_path,folder_name FROM site_gallery_photos WHERE id=? LIMIT 1');$q->execute([$id]);$photo=$q->fetch(PDO::FETCH_ASSOC);
            if(!$photo)throw new RuntimeException('The selected photo could not be found. Reload the Gallery and try again.');
            $oldTitle=(string)$photo['title'];$oldDescription=(string)($photo['description']??'');
            $titleChanged=$title!==$oldTitle;$descriptionChanged=$desc!==$oldDescription;
            $relative=(string)$photo['file_path'];$err=(int)($_FILES['replacement_photo']['error']??UPLOAD_ERR_NO_FILE);$photoChanged=$err!==UPLOAD_ERR_NO_FILE;
            if($photoChanged){
                if($err!==UPLOAD_ERR_OK)throw new RuntimeException('The replacement photo could not be uploaded (error '.$err.').');
                $tmp=(string)($_FILES['replacement_photo']['tmp_name']??'');if(!$tmp||!is_uploaded_file($tmp))throw new RuntimeException('The replacement photo upload is invalid.');
                if((int)($_FILES['replacement_photo']['size']??0)>10*1024*1024)throw new RuntimeException('The replacement photo must be 10 MB or smaller.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];
                if(!isset($exts[$mime])||@getimagesize($tmp)===false)throw new RuntimeException('Replacement photos must be valid JPG, JPEG, PNG, WebP or AVIF images.');
                $folder=(string)($photo['folder_name']??'');$dir=__DIR__.'/uploads/gallery'.($folder!==''?'/'.$folder:'');
                if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('Unable to access the Gallery photo folder.');
                $filename='photo-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.'.$exts[$mime];$newFile=$dir.'/'.$filename;
                if(!move_uploaded_file($tmp,$newFile))throw new RuntimeException('Unable to save the replacement photo.');
                $relative='uploads/gallery'.($folder!==''?'/'.$folder:'').'/'.$filename;$oldFile=__DIR__.'/'.ltrim((string)$photo['file_path'],'/\\');
            }
            $update=$pdo->prepare('UPDATE site_gallery_photos SET title=?,description=?,file_path=?,created_at=CURRENT_TIMESTAMP WHERE id=?');$update->execute([$title,$desc,$relative,$id]);
            if($oldFile!==''&&is_file($oldFile))@unlink($oldFile);
            $changedCount=(int)$titleChanged+(int)$descriptionChanged+(int)$photoChanged;
            if($changedCount===1&&$titleChanged)$successText='The Photo Title '.$oldTitle.' has been renamed to '.$title.'.';
            elseif($changedCount===1&&$descriptionChanged)$successText='The description of the photo has been successfully updated.';
            elseif($changedCount===1&&$photoChanged)$successText='The photo with the photo title '.$title.' has been successfully replaced.';
            else $successText='The photo and its details has been successfully updated.';
            $successFolder=(string)($photo['folder_name']??'');
            $_SESSION['gallery_photo_edit_success_message']=$successText;
            $_SESSION['gallery_photo_edit_success_folder']=$successFolder;
            session_write_close();
            $successUrl='admin.php?section=media-management&media_tab=gallery&gallery_folder='.rawurlencode($successFolder).'&gallery_photo_edit_success=1&gallery_photo_edit_message='.rawurlencode($successText);
            if($changedCount===1&&$titleChanged)$successUrl.='&gallery_old_photo_title='.rawurlencode($oldTitle).'&gallery_new_photo_title='.rawurlencode($title);
            if($changedCount===1&&$photoChanged)$successUrl.='&gallery_replaced_photo_title='.rawurlencode($title);
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>true,'redirect'=>$successUrl], JSON_UNESCAPED_SLASHES);
                exit;
            }
            header('Location: '.$successUrl,true,303);
            exit;
        }catch(Throwable $e){
            if($newFile!==''&&is_file($newFile))@unlink($newFile);
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
                exit;
            }
            $message=$e->getMessage();
        }
    }

    if ($action === 'edit_gallery_folder') {
        $section='media-management';$activeMediaTab='gallery';$newFiles=[];$oldFiles=[];$renamed=false;$oldDir='';$newDir='';
        try{
            $oldName=trim((string)($_POST['original_folder_name']??''));$newName=trim((string)($_POST['edited_folder_name']??''));
            if(!isValidGalleryFolderName($oldName))throw new RuntimeException('The selected folder name is invalid.');
            if($newName===''||mb_strlen($newName)>80||!isValidGalleryFolderName($newName))throw new RuntimeException('Enter a valid folder name using letters, numbers, spaces or symbols (maximum 80 characters).');
            $root=__DIR__.'/uploads/gallery';$oldDir=$root.'/'.$oldName;$newDir=$root.'/'.$newName;
            if(!is_dir($oldDir))throw new RuntimeException('The selected Gallery folder no longer exists.');
            if($newName!==$oldName&&file_exists($newDir))throw new RuntimeException('A folder with that name already exists.');
            $q=$pdo->prepare('SELECT id,title,description,file_path FROM site_gallery_photos WHERE folder_name=? ORDER BY created_at DESC,id DESC');$q->execute([$oldName]);$photos=$q->fetchAll(PDO::FETCH_ASSOC);
            // Empty folders can also be renamed; photo fields are optional when there are no records.
            $expected=array_map(static fn($p)=>(int)$p['id'],$photos);$received=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['photo_id']??[])),static fn($v)=>$v>0)));sort($expected);sort($received);
            if($expected!==$received)throw new RuntimeException('The photo list changed. Reload the Gallery and try again.');
            if($newName!==$oldName){if(!@rename($oldDir,$newDir))throw new RuntimeException('Unable to rename the local Gallery folder. Check folder permissions.');$renamed=true;}else $newDir=$oldDir;
            $folderChanged=$newName!==$oldName;$titleChanges=[];$descriptionChanges=[];$photoChanges=[];
            $pdo->beginTransaction();$update=$pdo->prepare('UPDATE site_gallery_photos SET title=?,description=?,folder_name=?,file_path=? WHERE id=? AND folder_name=?');
            foreach($photos as $photo){
                $id=(int)$photo['id'];$oldTitle=(string)$photo['title'];$oldDescription=(string)($photo['description']??'');
                $title=mb_substr(trim((string)($_POST['photo_title'][$id]??'')),0,180);if($title==='')throw new RuntimeException('Every photo must have a title.');
                $desc=mb_substr(trim((string)($_POST['photo_description'][$id]??'')),0,2000);$relative='uploads/gallery/'.$newName.'/'.basename((string)$photo['file_path']);
                if($title!==$oldTitle)$titleChanges[]=['old'=>$oldTitle,'new'=>$title];
                if($desc!==$oldDescription)$descriptionChanges[]=['title'=>$title];
                $err=(int)($_FILES['replacement_photo']['error'][$id]??UPLOAD_ERR_NO_FILE);
                if($err!==UPLOAD_ERR_NO_FILE){
                    if($err!==UPLOAD_ERR_OK)throw new RuntimeException('A replacement photo could not be uploaded (error '.$err.').');
                    $tmp=(string)($_FILES['replacement_photo']['tmp_name'][$id]??'');if(!$tmp||!is_uploaded_file($tmp))throw new RuntimeException('A replacement photo upload is invalid.');
                    if((int)($_FILES['replacement_photo']['size'][$id]??0)>10*1024*1024)throw new RuntimeException('Each replacement photo must be 10 MB or smaller.');
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];
                    if(!isset($exts[$mime])||@getimagesize($tmp)===false)throw new RuntimeException('Replacement photos must be valid JPG, JPEG, PNG, WebP or AVIF images.');
                    $filename='photo-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.'.$exts[$mime];$dest=$newDir.'/'.$filename;
                    if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Unable to save a replacement photo.');
                    $newFiles[]=$dest;$oldFiles[]=$newDir.DIRECTORY_SEPARATOR.basename((string)$photo['file_path']);$relative='uploads/gallery/'.$newName.'/'.$filename;
                    $photoChanges[]=['title'=>$title];
                }
                $update->execute([$title,$desc,$newName,$relative,$id,$oldName]);
            }
            $changeKinds=(int)$folderChanged+(bool)$titleChanges+(bool)$descriptionChanges+(bool)$photoChanges;
            if($changeKinds===0)throw new RuntimeException('No changes were made.');
            $pdo->commit();foreach($oldFiles as $path)if(is_file($path))@unlink($path);$galleryEditSuccess=true;$galleryEditSuccessName=$newName;
            if($changeKinds===1&&$folderChanged){$galleryEditSuccessMessage='Folder '.$newName.' has been successfully updated.';}
            elseif($changeKinds===1&&count($titleChanges)===1){$galleryEditSuccessMessage='Photo title '.$titleChanges[0]['old'].' has been successfully replaced with '.$titleChanges[0]['new'].'.';}
            elseif($changeKinds===1&&count($descriptionChanges)===1){$galleryEditSuccessMessage='The description of the photo '.$descriptionChanges[0]['title'].' has been successfully updated.';}
            elseif($changeKinds===1&&count($photoChanges)===1){$galleryEditSuccessMessage='The photo with title '.$photoChanges[0]['title'].' has been successfully updated.';}
            else{$galleryEditSuccessMessage='The folder '.$newName.' and its contents has been successfully updated.';}
            if(strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'){
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>true,'message'=>$galleryEditSuccessMessage,'folder'=>$newName],JSON_UNESCAPED_UNICODE);
                exit;
            }
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($newFiles as $path)if(is_file($path))@unlink($path);if($renamed&&is_dir($newDir)&&!file_exists($oldDir))@rename($newDir,$oldDir);$message=$e->getMessage();
            if(strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'){
                http_response_code(422);header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>false,'message'=>$message],JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    if ($action === 'move_gallery_photo') {
        try {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $targetFolder = trim((string)($_POST['target_folder'] ?? ''));
            if ($photoId < 1) throw new RuntimeException('Select a valid photo.');
            if ($targetFolder !== '' && !isValidGalleryFolderName($targetFolder)) throw new RuntimeException('Choose a valid destination folder.');
            $photoStmt = $pdo->prepare('SELECT id,folder_name,file_path FROM site_gallery_photos WHERE id=? LIMIT 1');
            $photoStmt->execute([$photoId]);
            $photoRow = $photoStmt->fetch(PDO::FETCH_ASSOC);
            if (!$photoRow) throw new RuntimeException('The selected photo could not be found.');
            $galleryRoot = __DIR__ . '/uploads/gallery';
            $sourcePath = __DIR__ . '/' . ltrim((string)$photoRow['file_path'], '/\\');
            $realRoot = realpath($galleryRoot);
            $realSource = realpath($sourcePath);
            if (!$realRoot || !$realSource || strpos($realSource, $realRoot . DIRECTORY_SEPARATOR) !== 0) throw new RuntimeException('The photo file could not be located inside the Gallery folder.');
            if ($targetFolder !== '' && !is_dir($galleryRoot . '/' . $targetFolder)) throw new RuntimeException('The destination folder does not exist. Create it from Add Photo first.');
            $destinationDir = $targetFolder === '' ? $galleryRoot : $galleryRoot . '/' . $targetFolder;
            $filename = basename($realSource);
            $destinationPath = $destinationDir . '/' . $filename;
            if (realpath($destinationDir) === realpath(dirname($realSource))) {
                header('Location: admin.php?section=media-management&media_tab=gallery&gallery_moved=same', true, 303);
                exit;
            }
            if (file_exists($destinationPath)) $destinationPath = $destinationDir . '/photo-' . bin2hex(random_bytes(6)) . '-' . $filename;
            if (!rename($realSource, $destinationPath)) throw new RuntimeException('Unable to move the selected photo.');
            try {
                $newRelative = 'uploads/gallery/' . ($targetFolder !== '' ? $targetFolder . '/' : '') . basename($destinationPath);
                $update = $pdo->prepare('UPDATE site_gallery_photos SET folder_name=?,file_path=? WHERE id=?');
                $update->execute([$targetFolder !== '' ? $targetFolder : null, $newRelative, $photoId]);
            } catch (Throwable $dbError) {
                @rename($destinationPath, $realSource);
                throw $dbError;
            }
            header('Location: admin.php?section=media-management&media_tab=gallery&gallery_moved=1&gallery_moved_folder=' . rawurlencode($targetFolder), true, 303);
            exit;
        } catch (Throwable $e) {
            $section = 'media-management';
            $activeMediaTab = 'gallery';
            $message = $e->getMessage();
        }
    }

    if ($action === 'create_gallery_folder') {
        $section = 'media-management';
        $activeMediaTab = 'gallery';
        try {
            $folder = trim((string)($_POST['gallery_new_folder_name'] ?? ''));
            if (!isValidGalleryFolderName($folder)) {
                throw new RuntimeException('Enter a folder name using letters, numbers, spaces or symbols (maximum 80 characters).');
            }
            $galleryRoot = __DIR__ . '/uploads/gallery';
            if (!is_dir($galleryRoot) && !mkdir($galleryRoot, 0755, true) && !is_dir($galleryRoot)) {
                throw new RuntimeException('Unable to access the Gallery upload directory.');
            }
            $folderPath = $galleryRoot . DIRECTORY_SEPARATOR . $folder;
            if (file_exists($folderPath)) throw new RuntimeException('A folder with this name already exists. Choose another name.');
            if (!mkdir($folderPath, 0755, false) && !is_dir($folderPath)) {
                throw new RuntimeException('Unable to create the Gallery folder. Check the folder permissions and try again.');
            }
            header('Location: admin.php?section=media-management&media_tab=gallery&gallery_folder=' . rawurlencode($folder) . '&gallery_upload_prompt=1', true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }
    }

    if ($action === 'add_gallery_photos') {
        $section = 'media-management';
        $activeMediaTab = 'gallery';
        $savedFiles = [];
        try {
            $mode = (string)($_POST['gallery_folder_mode'] ?? 'none');
            $folderInput = trim((string)($_POST['gallery_folder_name'] ?? ''));
            $folder = '';
            if ($mode === 'new') {
                $folder = $folderInput;
                if ($folder === '' || mb_strlen($folder) > 80 ||!isValidGalleryFolderName($folder)) throw new RuntimeException('Enter a folder name using letters, numbers, spaces or symbols (maximum 80 characters).');
                if (is_dir(__DIR__ . '/uploads/gallery/' . $folder)) throw new RuntimeException('A folder with this name already exists. Choose another name or upload to the existing folder.');
            } elseif ($mode === 'existing') {
                $folder = basename(trim((string)($_POST['gallery_existing_folder'] ?? '')));
                if ($folder === '' || $folder === '.' || $folder === '..' ||!isValidGalleryFolderName($folder)) throw new RuntimeException('Choose a valid existing photo folder.');
                $existingPath = __DIR__ . '/uploads/gallery/' . $folder;
                if (!is_dir($existingPath)) throw new RuntimeException('The selected photo folder no longer exists. Choose another folder.');
            } else {
                throw new RuntimeException('Choose where the photos should be saved.');
            }
            if (!isset($_FILES['gallery_photos']) || !is_array($_FILES['gallery_photos']['name'] ?? null)) throw new RuntimeException('Choose at least one photo to upload.');
            $files = $_FILES['gallery_photos'];
            $count = count($files['name']);
            if ($count < 1 || $count > 20) throw new RuntimeException('Choose between 1 and 20 photos per upload.');
            $uploadDir = __DIR__ . '/uploads/gallery' . ($folder !== '' ? '/' . $folder : '');
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Unable to create the selected gallery folder.');
            $titles = (array)($_POST['photo_title'] ?? []);
            $descriptions = (array)($_POST['photo_description'] ?? []);
            $insert = $pdo->prepare('INSERT INTO site_gallery_photos (title, description, folder_name, file_path) VALUES (?, ?, ?, ?)');
            $pdo->beginTransaction();
            for ($i = 0; $i < $count; $i++) {
                $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                if ($error !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'A photo exceeds the PHP upload_max_filesize limit. Increase upload_max_filesize to 10M in your PHP configuration.',
                        UPLOAD_ERR_FORM_SIZE => 'A photo exceeds the upload form size limit.',
                        UPLOAD_ERR_PARTIAL => 'A photo was only partially uploaded. Please try again.',
                        UPLOAD_ERR_NO_FILE => 'A selected photo was not received by the server.',
                        UPLOAD_ERR_NO_TMP_DIR => 'The PHP temporary upload folder is missing. Configure upload_tmp_dir in php.ini.',
                        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded photo to its temporary folder. Check the folder permissions and available disk space.',
                        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the photo upload.'
                    ];
                    throw new RuntimeException($uploadErrors[$error] ?? 'A photo upload failed (PHP upload error ' . $error . ').');
                }
                if ((int)($files['size'][$i] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Each gallery photo must be 10 MB or smaller.');
                $tmp = (string)$files['tmp_name'][$i];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];
                if (!isset($extensions[$mime]) || @getimagesize($tmp) === false) throw new RuntimeException('Upload valid JPG, JPEG, PNG, WebP or AVIF photos only.');
                $title = trim((string)($titles[$i] ?? ''));
                if ($title === '') throw new RuntimeException('Enter a title for every photo before uploading.');
                $title = mb_substr($title, 0, 180);
                $description = mb_substr(trim((string)($descriptions[$i] ?? '')), 0, 2000);
                $filename = 'photo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($tmp, $uploadDir . '/' . $filename)) throw new RuntimeException('Unable to save one of the selected photos.');
                $relativePath = 'uploads/gallery/' . ($folder !== '' ? $folder . '/' : '') . $filename;
                $savedFiles[] = $uploadDir . '/' . $filename;
                $insert->execute([$title, $description, $folder !== '' ? $folder : null, $relativePath]);
            }
            if (!$savedFiles) throw new RuntimeException('No photos were uploaded. Choose valid photo files and try again.');
            $pdo->commit();
            $uploadedCount = count($savedFiles);
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>true,'count'=>$uploadedCount,'folder'=>$folder]);
                exit;
            }
            header('Location: admin.php?section=media-management&media_tab=gallery&gallery_saved=' . $uploadedCount, true, 303);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($savedFiles as $savedFile) if (is_file($savedFile)) @unlink($savedFile);
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
                exit;
            }
            $message = $e->getMessage();
        }
    }


    if ($action === 'delete_slideshow_photos' || $action === 'edit_slideshow_photo') {
        $activePageTab='sections'; header('Content-Type: application/json; charset=utf-8');
        try {
            $row=$pdo->query('SELECT content_json FROM site_sections_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
            $settings=json_decode((string)($row['content_json']??''),true);if(!is_array($settings))throw new RuntimeException('Slideshow settings could not be loaded.');
            $photos=array_values((array)($settings['slideshow']['photos']??[]));
            $safePath=static function(string $relative):?string{$relative=ltrim(str_replace('\\\\','/',$relative),'/');if($relative===''||str_contains($relative,'..'))return null;$root=realpath(__DIR__.'/uploads/slideshow-photo');$path=realpath(__DIR__.'/'.$relative);return $root&&$path&&is_file($path)&&strpos($path,$root.DIRECTORY_SEPARATOR)===0?$path:null;};
            if($action==='delete_slideshow_photos'){
                $indices=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['photo_indices']??[])),static fn($v)=>$v>=0&&$v<count($photos))));
                if(!$indices)throw new RuntimeException('Select at least one slideshow photo to delete.');
                rsort($indices);$removed=[];foreach($indices as $index){$path=$safePath((string)($photos[$index]['photo']??''));if($path)$removed[]=$path;array_splice($photos,$index,1);}
                $settings['slideshow']['photos']=array_values($photos);$save=$pdo->prepare('UPDATE site_sections_settings SET content_json=? WHERE id=1');$save->execute([json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
                foreach($removed as $path)if(is_file($path))@unlink($path);echo json_encode(['success'=>true,'deleted'=>count($indices)]);exit;
            }
            $index=filter_var($_POST['photo_index']??null,FILTER_VALIDATE_INT);if($index===false||!isset($photos[$index]))throw new RuntimeException('The selected slideshow photo could not be found.');
            $photo=$photos[$index];$oldTitle=(string)($photo['title']??'');$oldHeading=(string)($photo['heading']??'');$oldDescription=(string)($photo['description']??'');
            $title=mb_substr(trim(strip_tags((string)($_POST['photo_title']??''))),0,180);if($title==='')throw new RuntimeException('Enter a title for this photo.');
            $heading=mb_substr(trim(strip_tags((string)($_POST['photo_heading']??''))),0,180);
            $photo['title']=$title;$photo['heading']=$heading;
            $description=strip_tags((string)($_POST['photo_description']??''),'<p><br><strong><b><em><i><u><s><ul><ol><li><h2><h3><h4><blockquote><a><span>');
            $photo['description']=trim(preg_replace('/\\s+on[a-z]+\\s*=\\s*(["\\\']).*?\\1/i','',$description));$oldPath=(string)($photo['photo']??'');
            $titleChanged=$title!==$oldTitle;$headingChanged=$heading!==$oldHeading;$descriptionChanged=$photo['description']!==$oldDescription;$photoChanged=false;
            if(isset($_FILES['replacement_photo'])&&(int)($_FILES['replacement_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $file=$_FILES['replacement_photo'];if((int)$file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('The replacement photo could not be uploaded.');
                if((int)$file['size']>10*1024*1024)throw new RuntimeException('The replacement photo must not exceed 10 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/avif'=>'avif','image/webp'=>'webp'];
                if(!isset($extensions[$mime])||@getimagesize((string)$file['tmp_name'])===false)throw new RuntimeException('Choose a valid JPG, JPEG, PNG, AVIF or WEBP photo.');
                $dir=__DIR__.'/uploads/slideshow-photo';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Unable to access the slideshow photo folder.');
                $filename='slideshow-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$filename))throw new RuntimeException('Unable to save the replacement photo.');
                $photo['photo']='uploads/slideshow-photo/'.$filename;$photoChanged=true;
            }
            $changedCount=(int)$titleChanged+(int)$headingChanged+(int)$descriptionChanged+(int)$photoChanged;
            if($changedCount>1){$successType='multiple';$successMessage='The contents has been successfully updated.';}
            elseif($titleChanged){$successType='title';$successMessage='The photo title has been successfully renamed.';}
            elseif($headingChanged){$successType='heading';$successMessage='The photo heading has been successfully updated.';}
            elseif($descriptionChanged){$successType='description';$successMessage='The description of the photo has been successfully updated.';}
            elseif($photoChanged){$successType='photo';$successMessage='The photo has been successfully changed.';}
            else{$successType='multiple';$successMessage='The contents has been successfully updated.';}
            array_splice($photos,$index,1);array_unshift($photos,$photo);$index=0;$settings['slideshow']['photos']=array_values($photos);$save=$pdo->prepare('UPDATE site_sections_settings SET content_json=? WHERE id=1');$save->execute([json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
            if($oldPath!==(string)$photo['photo']){$old=$safePath($oldPath);if($old)@unlink($old);}
            echo json_encode(['success'=>true,'index'=>(int)$index,'photo'=>$photo,'successType'=>$successType,'successMessage'=>$successMessage,'oldTitle'=>$oldTitle,'newTitle'=>$title]);exit;
        }catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);exit;}
    }

    if ($action === 'upload_slideshow_photos') {
        $activePageTab = 'sections';
        $savedFiles = [];
        try {
            if (!isset($_FILES['slideshow_photos']) || !is_array($_FILES['slideshow_photos']['name'] ?? null)) {
                throw new RuntimeException('Choose at least one slideshow photo to upload.');
            }
            $files = $_FILES['slideshow_photos'];
            $count = count($files['name']);
            if ($count < 1 || $count > 20) throw new RuntimeException('Choose between 1 and 20 photos per upload.');
            $titles = (array)($_POST['slideshow_photo_title'] ?? []);
            $headings = (array)($_POST['slideshow_photo_heading'] ?? []);
            $descriptions = (array)($_POST['slideshow_photo_description'] ?? []);
            $uploadDir = __DIR__ . '/uploads/slideshow-photo';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to create the slideshow-photo folder.');
            }
            $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/avif'=>'avif','image/webp'=>'webp'];
            $newPhotos = [];
            for ($i = 0; $i < $count; $i++) {
                $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error !== UPLOAD_ERR_OK) {
                    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Each slideshow photo must not exceed 10 MB.');
                    throw new RuntimeException('One of the selected slideshow photos could not be uploaded. Please try again.');
                }
                if ((int)($files['size'][$i] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Each slideshow photo must not exceed 10 MB.');
                $tmp = (string)$files['tmp_name'][$i];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (!isset($extensions[$mime]) || @getimagesize($tmp) === false) throw new RuntimeException('Only valid JPG, JPEG, PNG, AVIF and WEBP photos are allowed.');
                $title = trim((string)($titles[$i] ?? ''));
                if ($title === '') throw new RuntimeException('Enter a Photo Title for every selected photo.');
                $heading = trim((string)($headings[$i] ?? ''));
                $filename = 'slideshow-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                $destination = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Unable to save one of the slideshow photos.');
                $savedFiles[] = $destination;
                $newPhotos[] = [
                    'title' => mb_substr($title, 0, 180),
                    'heading' => mb_substr($heading, 0, 180),
                    'description' => nl2br(htmlspecialchars(trim((string)($descriptions[$i] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                    'photo' => 'uploads/slideshow-photo/' . $filename
                ];
            }
            $row = $pdo->query('SELECT content_json FROM site_sections_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $settings = json_decode((string)($row['content_json'] ?? ''), true);
            if (!is_array($settings)) $settings = [];
            if (!isset($settings['slideshow']) || !is_array($settings['slideshow'])) $settings['slideshow'] = [];
            $existing = (array)($settings['slideshow']['photos'] ?? []);
            $settings['slideshow']['photos'] = array_values(array_merge($existing, $newPhotos));
            $update = $pdo->prepare('UPDATE site_sections_settings SET content_json=? WHERE id=1');
            $update->execute([json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>true,'count'=>count($newPhotos),'photos'=>$newPhotos]);
            exit;
        } catch (Throwable $e) {
            foreach ($savedFiles as $savedFile) if (is_file($savedFile)) @unlink($savedFile);
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            exit;
        }
    }

    if ($action === 'save_sections_settings') {
        $activePageTab='sections';
        $sectionEditorTab=(string)($_POST['section_editor_tab']??'banner');
        if(!in_array($sectionEditorTab,['banner','slideshow','mega_menu'],true))$sectionEditorTab='banner';
        $saved=$sectionsSettings;
        $safeText=static fn($v,$max=240)=>mb_substr(trim((string)$v),0,$max);
        $cleanHtml=static function(string $html): string {
            $html=strip_tags($html,'<p><br><strong><b><em><i><u><s><ul><ol><li><h2><h3><h4><blockquote><a><span><figure><img><figcaption><table><tbody><tr><th><td>');
            $html=preg_replace_callback('/<([a-z0-9]+)\\b([^>]*)>/i',static function($m){
                $tag=strtolower($m[1]); if($tag==='br') return '<br>';
                if($tag==='a'){ $href=''; if(preg_match('/href\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i',$m[2],$x) && preg_match('~^(https?://|/|#|\\?|mailto:)~i',$x[1])) $href=' href="'.htmlspecialchars($x[1],ENT_QUOTES,'UTF-8').'"'; return '<a'.$href.' target="_blank" rel="noopener">'; }
                if($tag==='img'){ $src=''; $alt=''; if(preg_match('/src\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i',$m[2],$x) && preg_match('~^(uploads/articles/|/uploads/articles/)~i',$x[1])) $src=' src="'.htmlspecialchars($x[1],ENT_QUOTES,'UTF-8').'"'; if(preg_match('/alt\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i',$m[2],$x)) $alt=' alt="'.htmlspecialchars($x[1],ENT_QUOTES,'UTF-8').'"'; return '<img'.$src.$alt.'>'; }
                $style=''; if(preg_match('/style\\s*=\\s*["\\\']([^"\\\']*)["\\\']/i',$m[2],$x)){ foreach(explode(';',$x[1]) as $declaration){ if(!str_contains($declaration,':')) continue; [$property,$value]=array_map('trim',explode(':',$declaration,2)); $property=strtolower($property); $valid=false; if($property==='color') $valid=(bool)preg_match('/^(#[0-9a-f]{3,8}|rgba?\\([0-9.,% ]+\\)|[a-z]{3,20})$/i',$value); elseif($property==='font-size') $valid=(bool)preg_match('/^(?:[8-9]|[1-6][0-9]|7[0-2])(?:\\.[0-9]+)?px$/i',$value); elseif($property==='font-family') $valid=(bool)preg_match('/^[a-z0-9 ,\\\'-]{1,80}$/i',$value); elseif($property==='font-weight') $valid=in_array(strtolower($value),['normal','bold','400','500','600','700','800','900'],true); elseif($property==='font-style') $valid=in_array(strtolower($value),['normal','italic','oblique'],true); elseif($property==='text-align') $valid=in_array(strtolower($value),['left','right','center','justify'],true); elseif($property==='text-decoration') $valid=(bool)preg_match('/^(none|underline|line-through|overline)(\\s+(underline|line-through|overline))*$/i',$value); elseif($property==='line-height') $valid=(bool)preg_match('/^(?:[0-9]+(?:\\.[0-9]+)?|[0-9]+(?:\\.[0-9]+)?px|[0-9]+(?:\\.[0-9]+)?%)$/i',$value); elseif($property==='letter-spacing') $valid=(bool)preg_match('/^-?[0-9]+(?:\\.[0-9]+)?px$/i',$value); if($valid) $style.=$property.':'.htmlspecialchars($value,ENT_QUOTES,'UTF-8').';'; } } return '<'.$tag.($style!==''?' style="'.$style.'"':'').'>';
            },$html);
            return trim($html);
        };
        $upload=static function(array $file,string $prefix): string {
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) return '';
            if((int)($file['size']??0)>10*1024*1024) throw new RuntimeException('Each section photo must be 10 MB or smaller.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime]??'';
            if($ext==='' || @getimagesize((string)$file['tmp_name'])===false) throw new RuntimeException('Upload valid JPG, PNG, GIF or WebP photos only.');
            $dir=__DIR__.'/uploads/site-sections';
            if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create the section photo folder.');
            $name=$prefix.'-'.bin2hex(random_bytes(9)).'.'.$ext;
            if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Unable to save a section photo.');
            return 'uploads/site-sections/'.$name;
        };
        try {
            $saved['banner']['main_title']=$cleanHtml((string)($_POST['banner_main_title']??''));
            $saved['banner']['heading']=$cleanHtml((string)($_POST['banner_heading']??''));
            $saved['banner']['description']=$cleanHtml((string)($_POST['banner_description']??''));
            $oldBannerPhoto=(string)($sectionsSettings['banner']['photo']??'');
            $newBannerPhoto='';
            if(!empty($_FILES['banner_photo']['name'])) {
                $file=$_FILES['banner_photo'];
                if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Unable to upload the banner photo.');
                if((int)($file['size']??0)>10*1024*1024) throw new RuntimeException('Banner photo must be 10 MB or smaller.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime]??'';
                if($ext==='' || @getimagesize((string)$file['tmp_name'])===false) throw new RuntimeException('Upload a valid JPG, PNG, GIF or WebP banner photo.');
                $dir=__DIR__.'/uploads/banner-photos';
                if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create the banner-photos folder.');
                $fileName='banner-'.bin2hex(random_bytes(10)).'.'.$ext;
                if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$fileName)) throw new RuntimeException('Unable to save the banner photo.');
                $newBannerPhoto='uploads/banner-photos/'.$fileName;
                $saved['banner']['photo']=$newBannerPhoto;
            }
            $saved['slideshow']['main_title']=$cleanHtml((string)($_POST['slide_main_title']??''));
            $saved['slideshow']['heading']=$cleanHtml((string)($_POST['slide_heading']??''));
            $saved['slideshow']['subtitle']=$cleanHtml((string)($_POST['slide_subtitle']??''));
            // Slideshow photo records are uploaded and saved independently through the multi-photo modal.
            $saved['mega_menu']['main_title']=$safeText($_POST['mega_main_title']??'',120);
            $saved['mega_menu']['heading']=$safeText($_POST['mega_heading']??'',180);
            $saved['mega_menu']['subtitle']=$safeText($_POST['mega_subtitle']??'',300);
            $panels=[];
            $oldPanels=(array)($sectionsSettings['mega_menu']['panels']??[]);
            foreach((array)($_POST['panel_title']??[]) as $k=>$title){
                $old=$oldPanels[$k]??[];
                $panel=['title'=>$safeText($title,100),'description'=>$safeText($_POST['panel_description'][$k]??'',400),'url'=>$safeText($_POST['panel_url'][$k]??'',500),'photo'=>(string)($old['photo']??'')];
                if(isset($_FILES['panel_photo']['error'][$k]) && $_FILES['panel_photo']['error'][$k]===UPLOAD_ERR_OK){
                    $f=['name'=>$_FILES['panel_photo']['name'][$k],'type'=>$_FILES['panel_photo']['type'][$k],'tmp_name'=>$_FILES['panel_photo']['tmp_name'][$k],'error'=>$_FILES['panel_photo']['error'][$k],'size'=>$_FILES['panel_photo']['size'][$k]];
                    $panel['photo']=$upload($f,'panel');
                }
                if($panel['title']!==''||$panel['description']!==''||$panel['url']!==''||$panel['photo']!=='') $panels[]=$panel;
            }
            $saved['mega_menu']['panels']=$panels;
            $save=$pdo->prepare("INSERT INTO site_sections_settings (id,content_json) VALUES (1,?) ON DUPLICATE KEY UPDATE content_json=VALUES(content_json)");
            $save->execute([json_encode($saved,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
            if($newBannerPhoto!=='' && $oldBannerPhoto!=='') {
                $oldRelative=ltrim($oldBannerPhoto,'/');
                $oldAbsolute=__DIR__.'/'.str_replace(['\\','..'],['/',''],$oldRelative);
                $uploadsRoot=realpath(__DIR__.'/uploads');
                $oldReal=realpath($oldAbsolute);
                if($uploadsRoot && $oldReal && is_file($oldReal) && strpos($oldReal,$uploadsRoot.DIRECTORY_SEPARATOR)===0 && !is_link($oldReal)) @unlink($oldReal);
            }
            $sectionsSettings=$saved;
            $saveFlag=$sectionEditorTab==='banner'?'banner_saved=1':($sectionEditorTab==='slideshow'?'slideshow_saved=1':'sections_saved=1');
            $sectionTabParam='&section_tab='.rawurlencode(in_array($sectionEditorTab,['banner','slideshow','mega_menu'],true)?$sectionEditorTab:'banner');
            header('Location: admin.php?section=page-management&tab=sections'.$sectionTabParam.'&'.$saveFlag.'#pm-panel-sections',true,303); exit;
        } catch(Throwable $e) { $message=$e->getMessage(); }
        $section='page-management';
    }

    if ($action === 'save_footer_settings') {
        $activePageTab = 'footer';
        $content = [
            'brand' => mb_substr(trim((string)($_POST['footer_brand'] ?? '')), 0, 100),
            'description' => mb_substr(trim((string)($_POST['footer_description'] ?? '')), 0, 500),
            'copyright' => mb_substr(trim((string)($_POST['footer_copyright'] ?? '')), 0, 300),
            'brand_links' => [],
            'explore_title' => mb_substr(trim((string)($_POST['footer_explore_title'] ?? '')), 0, 60),
            'discover_title' => mb_substr(trim((string)($_POST['footer_discover_title'] ?? '')), 0, 60),
            'explore_links' => [],
            'discover_links' => [],
        ];
        foreach (['brand','explore','discover'] as $group) {
            foreach ((array)($_POST[$group.'_label'] ?? []) as $index => $label) {
                $label = trim((string)$label);
                $url = trim((string)($_POST[$group.'_url'][$index] ?? ''));
                if ($label === '' && $url === '') continue;
                if ($label === '') {
                    $columnNumber = ['brand'=>'1','explore'=>'2','discover'=>'3'][$group];
                    $message = 'Please enter a title in Column ' . $columnNumber . '.';
                    break 2;
                }
                if ($url !== '' && !preg_match('~^(https?://|/|#|\\.\\./|\\./|\\?)~i', $url)) {
                    $message = 'Enter a valid footer link URL, or use a relative page URL.';
                    break 2;
                }
                $content[$group.'_links'][] = ['label'=>mb_substr($label,0,100),'url'=>mb_substr($url,0,500)];
            }
        }
        if ($message === '') {
            $saveFooter = $pdo->prepare("INSERT INTO site_footer_settings (id, content_json) VALUES (1, ?) ON DUPLICATE KEY UPDATE content_json=VALUES(content_json)");
            $saveFooter->execute([json_encode($content, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
            $verifyFooter = $pdo->prepare('SELECT content_json FROM site_footer_settings WHERE id=1');
            $verifyFooter->execute();
            $savedFooter = json_decode((string)$verifyFooter->fetchColumn(), true);
            if (!is_array($savedFooter) || $savedFooter !== $content) {
                throw new RuntimeException('Footer settings were submitted but could not be verified in the connected database.');
            }
            $footerSettings = array_replace($defaultFooterContent, $content);
            // POST/Redirect/GET prevents browser refresh from resubmitting this save.
            header('Location: admin.php?section=page-management&tab=footer&footer_saved=1#pm-panel-footer', true, 303);
            exit;
        }
    } elseif ($action === 'save_header_settings') {
        // Header Title is no longer editable in Page Management. Preserve
        // the currently published title instead of clearing it on unrelated saves.
        $title = trim((string)($_POST['header_title'] ?? $headerSettings['header_title'] ?? 'Z.C. commonplace'));
        $left = trim((string)($_POST['hidden_left'] ?? ''));
        $right = trim((string)($_POST['hidden_right'] ?? ''));
        $family = (string)($_POST['font_family'] ?? 'Ubuntu');
        $style = (string)($_POST['font_style'] ?? 'normal');
        $size = max(12, min(32, (int)($_POST['font_size'] ?? 20)));
        $weight = (int)($_POST['font_weight'] ?? 700);
        $weight = in_array($weight, [300,400,500,600,700,800], true) ? $weight : 700;
        $positionX = max(0, min(100, (int)($_POST['logo_position_x'] ?? 50)));
        $positionY = max(0, min(100, (int)($_POST['logo_position_y'] ?? 50)));
        $logoWidth = max(50, min(200, (int)($_POST['logo_width'] ?? 200)));
        $logoHeight = max(30, min(120, (int)($_POST['logo_height'] ?? 70)));
        $allowedFamilies = ['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'];
        if (!in_array($family, $allowedFamilies, true)) $family = 'Ubuntu';
        if (!in_array($style, ['normal','italic'], true)) $style = 'normal';
        $titleHtml = array_key_exists('header_title_html', $_POST)
            ? cleanHeaderRichHtml((string)$_POST['header_title_html'])
            : (string)($headerSettings['title_html'] ?? '');
        $leftHtml = cleanHeaderRichHtml((string)($_POST['hidden_left_html'] ?? ''));
        $rightHtml = cleanHeaderRichHtml((string)($_POST['hidden_right_html'] ?? ''));
        $typography = [
            'title'=>headerTypography((array)($_POST['typography']['title'] ?? (json_decode((string)($headerSettings['typography_json'] ?? '{}'),true)['title'] ?? [])), ['family'=>$family,'style'=>$style,'size'=>$size,'weight'=>$weight,'color'=>'#f7eef0'])
        ];
        $links = [];
        foreach ((array)($_POST['header_link_label'] ?? []) as $index => $label) {
            $label = trim((string)$label);
            $url = trim((string)(($_POST['header_link_url'][$index] ?? '')));
            $labelHtml = cleanHeaderRichHtml((string)(($_POST['header_link_html'][$index] ?? '')));
            // A title may be saved before its destination is assigned. This lets
            // administrators add and edit navigation labels independently.
            if ($label === '' && $url === '') continue;
            if ($label === '') {
                $message = 'Enter a link title before saving the header link.';
                break;
            }
            if ($url !== '' && !preg_match('~^(https?://|/|#|\\.\\./|\\./)~i', $url)) {
                $message = 'Enter a valid Page URL (http://, https://, /, or #), or leave it blank.';
                break;
            }
            $links[] = ['label'=>mb_substr($label,0,60),'label_html'=>'','url'=>mb_substr($url,0,500)];
        }
        $previousLogoPath = (string)($headerSettings['logo_path'] ?? '');
        $logoPath = $previousLogoPath;
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
                (id,header_title,logo_path,header_links,hidden_left,hidden_right,font_family,font_style,font_size,font_weight,logo_position_x,logo_position_y,logo_width,logo_height,title_html,hidden_left_html,hidden_right_html,typography_json)
                VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                header_title=VALUES(header_title),logo_path=VALUES(logo_path),header_links=VALUES(header_links),
                hidden_left=VALUES(hidden_left),hidden_right=VALUES(hidden_right),font_family=VALUES(font_family),
                font_style=VALUES(font_style),font_size=VALUES(font_size),font_weight=VALUES(font_weight),
                logo_position_x=VALUES(logo_position_x),logo_position_y=VALUES(logo_position_y),
                logo_width=VALUES(logo_width),logo_height=VALUES(logo_height),
                title_html=VALUES(title_html),hidden_left_html=VALUES(hidden_left_html),
                hidden_right_html=VALUES(hidden_right_html),typography_json=VALUES(typography_json)");
            $save->execute([$title,$logoPath ?: null,json_encode($links,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$left,$right,$family,$style,$size,$weight,$positionX,$positionY,$logoWidth,$logoHeight,$titleHtml,$leftHtml,$rightHtml,json_encode($typography,JSON_UNESCAPED_SLASHES)]);
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
                'logo_width' => (string)$logoWidth,
                'logo_height' => (string)$logoHeight,
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
            // Once the new logo path is confirmed in the database, remove the
            // previously saved image from this application's local upload folder.
            // Only delete files inside public/uploads/site-header; never follow
            // arbitrary paths stored in the settings row.
            if ($logoPath !== '' && $logoPath !== $previousLogoPath
                && $previousLogoPath !== ''
                && str_starts_with(str_replace('\\', '/', $previousLogoPath), 'uploads/site-header/')) {
                $oldLogoName = basename($previousLogoPath);
                if ($oldLogoName !== '' && $oldLogoName !== '.' && $oldLogoName !== '..') {
                    $oldLogoFile = __DIR__ . '/uploads/site-header/' . $oldLogoName;
                    if (is_file($oldLogoFile)) {
                        @unlink($oldLogoFile);
                    }
                }
            }
            $headerSettings = $savedHeader;
            $headerLinks = $links;
            // The public index.php reads this verified database row directly.
            // Do not rewrite an obsolete embedded marker block here.
            $message = 'Header settings successfully saved.';
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['banner_saved'] ?? '') === '1' && $section === 'page-management' && $activePageTab === 'sections') $message='Banner Settings successfully saved.';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['slideshow_saved'] ?? '') === '1' && $section === 'page-management' && $activePageTab === 'sections') $message='Slideshow settings successfully saved.';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['sections_saved'] ?? '') === '1' && $section === 'page-management' && $activePageTab === 'sections') $message='Sections settings successfully saved.';
// A successful Footer save is shown only on the redirected GET request.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['footer_saved'] ?? '') === '1' && $section === 'page-management' && $activePageTab === 'footer') {
    $message = 'Footer settings successfully updated.';
}
// Return an unambiguous save result to the background form request.
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string)($_POST['action'] ?? '') === 'save_header_settings'
    && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
) {
    header('Content-Type: text/plain; charset=utf-8');
    if ($message === 'Header settings successfully saved.') {
        echo 'HEADER_SETTINGS_SAVED';
    } else {
        http_response_code(422);
        echo $message !== '' ? $message : 'Unable to save Header Settings.';
    }
    exit;
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
    <button class="settings-nav<?=in_array($section,['settings','page-management','category-list','media-management','plugins'],true)?' active':''?>" type="button" id="settings-menu-toggle" aria-controls="settings-submenu" aria-expanded="<?=in_array($section,['settings','page-management','media-management','plugins'],true)?'true':'false'?>"><span class="nav-icon">⚙</span><span class="settings-nav-label">Settings</span><span class="settings-chevron" aria-hidden="true">⌄</span></button>
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
<?php if($message === 'Banner Settings successfully saved.'):?>
<div class="account-status-modal" id="banner-settings-success-modal" role="dialog" aria-modal="true" aria-labelledby="banner-settings-success-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="banner-settings-success-title">Banner Settings successfully saved.</h2>
    <button type="button" class="btn" id="banner-settings-success-close" onclick="window.location.replace('admin.php?section=page-management&amp;tab=sections#pm-panel-sections')">OK</button>
  </div>
</div>
<?php elseif($message === 'Slideshow settings successfully saved.'):?>
<div class="account-status-modal" id="slideshow-settings-success-modal" role="dialog" aria-modal="true" aria-labelledby="slideshow-settings-success-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="slideshow-settings-success-title">Slideshow settings successfully saved.</h2>
    <button type="button" class="btn" id="slideshow-settings-success-close" onclick="window.location.replace('admin.php?section=page-management&amp;tab=sections&amp;section_tab=slideshow#pm-panel-sections')">OK</button>
  </div>
</div>
<?php elseif($message === 'Header settings successfully saved.'):?>
<div class="account-status-modal" id="header-settings-success-modal" role="dialog" aria-modal="true" aria-labelledby="header-settings-success-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="header-settings-success-title">Header settings successfully saved.</h2>
    <button type="button" class="btn" id="header-settings-success-close" onclick="window.location.replace('admin.php?section=page-management')">OK</button>
  </div>
</div>
<?php elseif(str_starts_with($message, 'Please enter a title in Column ')):?>
<div class="account-status-modal" id="footer-validation-modal" role="dialog" aria-modal="true" aria-labelledby="footer-validation-title"><div class="account-status-modal-card"><div class="account-status-modal-icon" aria-hidden="true">!</div><h2 id="footer-validation-title"><?=e($message)?></h2><button type="button" class="btn" id="footer-validation-close">OK</button></div></div>
<?php elseif($message === 'Footer settings successfully updated.'):?>
<div class="account-status-modal" id="footer-settings-success-modal" role="dialog" aria-modal="true" aria-labelledby="footer-settings-success-title">
  <div class="account-status-modal-card">
    <div class="account-status-modal-icon" aria-hidden="true">✓</div>
    <h2 id="footer-settings-success-title">Footer settings successfully updated.</h2>
    <button type="button" class="btn" id="footer-settings-success-close" onclick="window.location.replace('admin.php?section=page-management&amp;tab=footer#pm-panel-footer')">OK</button>
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
  <?php elseif($section==='category-list'): ?>
    <?php
      $clCategories=$pdo->query("SELECT id,name,slug,description FROM categories WHERE parent_id IS NULL AND is_active=1 ORDER BY CASE WHEN slug='uncategorized' THEN 1 ELSE 0 END,name")->fetchAll();
      $clSubcategories=$pdo->query("SELECT child.id,child.name,child.description,child.parent_id,parent.name AS parent_name FROM categories child JOIN categories parent ON parent.id=child.parent_id ORDER BY parent.name,child.name")->fetchAll();
      $clEditCategory=null; $clEditSubcategory=null;
      if(!empty($_GET['edit_category'])){$q=$pdo->prepare("SELECT id,name,description FROM categories WHERE id=? AND parent_id IS NULL AND slug<>'uncategorized'");$q->execute([(int)$_GET['edit_category']]);$clEditCategory=$q->fetch()?:null;}
      if(!empty($_GET['edit_subcategory'])){$q=$pdo->prepare("SELECT id,name,description,parent_id FROM categories WHERE id=? AND parent_id IS NOT NULL");$q->execute([(int)$_GET['edit_subcategory']]);$clEditSubcategory=$q->fetch()?:null;}
      $clMessages=['category_created'=>'Category created successfully.','category_updated'=>'Category updated successfully.','category_deleted'=>'Category deleted successfully.','subcategory_created'=>'Subcategory created successfully.','subcategory_updated'=>'Subcategory updated successfully.','subcategory_deleted'=>'Subcategory deleted successfully.','category_in_use'=>'This category or subcategory is assigned to existing content. Reassign or remove that content before deleting it.','category_locked'=>'This category cannot be changed or deleted.','category_invalid'=>'Choose a valid parent category.','subcategory_invalid'=>'The selected subcategory does not belong to the selected category.','subcategory_missing'=>'The subcategory could not be found.','duplicate_or_database_error'=>'The change could not be saved. Check that the category or subcategory name is not already in use.'];
      $clNotice=$clMessages[(string)($_GET['notice']??'')]??'';
    ?>
    <div class="admin-topline"><div><div class="eyebrow">Page Management</div><h1 class="admin-heading">Category List</h1><p class="muted">Manage categories and their subcategories.</p></div><a class="btn light" href="admin.php?section=page-management&amp;tab=contents">← Back to Contents</a></div>
    <?php if($clNotice!==''): ?><div class="panel" style="margin-bottom:16px;color:#f1dce1"><?=e($clNotice)?></div><?php endif; ?>
    <section class="panel admin-panel">
      <div style="display:flex;justify-content:flex-start;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:18px"><button class="btn" type="button" id="cl-add-category">+ Add Category</button><button class="btn light" type="button" id="cl-add-subcategory">+ Add Subcategory</button></div>
      <div id="cl-category-form" class="category-form-card" <?=($clEditCategory?'':'hidden')?>><h3><?= $clEditCategory?'Edit Category':'Add Category' ?></h3>
        <form class="category-form" method="post" action="admin-content.php"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_category"><input type="hidden" name="category_id" value="<?=(int)($clEditCategory['id']??0)?>">
          <label>Category name *<input class="field" name="name" maxlength="120" required value="<?=e((string)($clEditCategory['name']??''))?>" placeholder="Enter category name"></label><label>Description (optional)<textarea class="field" name="description" maxlength="255"><?=e((string)($clEditCategory['description']??''))?></textarea></label><button class="btn" type="submit"><?= $clEditCategory?'Update Category':'Save Category' ?></button></form>
      </div>
      <div id="cl-subcategory-form" class="category-form-card" <?=($clEditSubcategory?'':'hidden')?>><h3><?= $clEditSubcategory?'Edit Subcategory':'Add Subcategory' ?></h3>
        <form class="category-form" method="post" action="admin-content.php"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_subcategory"><input type="hidden" name="subcategory_id" value="<?=(int)($clEditSubcategory['id']??0)?>">
          <label>Parent category *<select class="field" name="parent_id" required><option value="">Select a category</option><?php foreach($clCategories as $cat): if($cat['slug']==='uncategorized') continue; ?><option value="<?=(int)$cat['id']?>" <?=isset($clEditSubcategory['parent_id'])&&(int)$clEditSubcategory['parent_id']===(int)$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select></label><label>Subcategory name *<input class="field" name="name" maxlength="120" required value="<?=e((string)($clEditSubcategory['name']??''))?>" placeholder="Enter subcategory name"></label><label>Description (optional)<textarea class="field" name="description" maxlength="255"><?=e((string)($clEditSubcategory['description']??''))?></textarea></label><button class="btn" type="submit"><?= $clEditSubcategory?'Update Subcategory':'Save Subcategory' ?></button></form>
      </div>
      <div class="admin-panel-head"><h2>Categories and Subcategories</h2><span class="muted"><?=count($clCategories)?> categories · <?=count($clSubcategories)?> subcategories</span></div>
      <div class="table-wrap"><table class="table" style="width:100%;min-width:650px"><thead><tr><th>Category / Subcategory</th><th>Description</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($clCategories as $cat): ?><tr><td><strong><?=e($cat['name'])?></strong><?php if($cat['slug']==='uncategorized'): ?><br><small class="muted">Default category</small><?php endif; ?></td><td><?=e((string)$cat['description'])?></td><td><?php if($cat['slug']!=='uncategorized'): ?><a href="admin.php?section=category-list&amp;edit_category=<?=(int)$cat['id']?>">Edit</a> · <form method="post" action="admin-content.php" style="display:inline" onsubmit="return confirm('Delete this category?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="item_id" value="<?=(int)$cat['id']?>"><button type="submit" class="category-link-button">Delete</button></form><?php else: ?><span class="muted">Protected</span><?php endif; ?></td></tr>
        <?php foreach($clSubcategories as $sub): if((int)$sub['parent_id']!==(int)$cat['id']) continue; ?><tr><td style="padding-left:28px">↳ <?=e($sub['name'])?></td><td><?=e((string)$sub['description'])?></td><td><a href="admin.php?section=category-list&amp;edit_subcategory=<?=(int)$sub['id']?>">Edit</a> · <form method="post" action="admin-content.php" style="display:inline" onsubmit="return confirm('Delete this subcategory?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_subcategory"><input type="hidden" name="item_id" value="<?=(int)$sub['id']?>"><button type="submit" class="category-link-button">Delete</button></form></td></tr><?php endforeach; ?>
      <?php endforeach; ?></tbody></table></div>
    </section>
    <style>.category-form-card{padding:17px;margin-bottom:14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.018)}.category-form-card h3{margin:0 0 12px;color:#f4e9ec;font:600 14px Ubuntu,sans-serif}.category-form{display:grid;gap:10px}.category-form label{display:grid;gap:6px;color:#d9cbd0;font-size:11px}.category-form .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:9px 10px;font:12px Ubuntu,sans-serif}.category-link-button{border:0;background:none;padding:0;color:#e4a2b1;cursor:pointer;font:inherit}</style>
    <script>(()=>{const c=document.getElementById('cl-category-form'),s=document.getElementById('cl-subcategory-form');document.getElementById('cl-add-category')?.addEventListener('click',()=>{c.hidden=!c.hidden;s.hidden=true;c.querySelector('input[name="name"]')?.focus()});document.getElementById('cl-add-subcategory')?.addEventListener('click',()=>{s.hidden=!s.hidden;c.hidden=true;s.querySelector('select[name="parent_id"]')?.focus()})})();</script>
  <?php elseif($section==='media-management'):?>
  <style>
    .page-management-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:20px 0 0;padding:0 0 12px;border-bottom:1px solid var(--line)}
    .page-management-tabs button{appearance:none;border:1px solid transparent;border-radius:10px;padding:10px 17px;background:transparent;color:#c8bec1;font:500 13px 'Ubuntu',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,color .2s,box-shadow .2s}
    .page-management-tabs button:hover{background:rgba(255,255,255,.045);color:#fff}
    .page-management-tabs button[aria-selected="true"]{color:#fff;background:linear-gradient(110deg,rgba(128,20,39,.48),rgba(30,12,18,.62));border-color:rgba(184,50,63,.4);box-shadow:inset 1px 1px 3px rgba(255,255,255,.04),0 4px 12px rgba(0,0,0,.14)}
    .page-management-tabpanel{margin-top:20px;min-height:190px}
    .page-management-tabpanel[hidden]{display:none}
  </style>
  <section class="panel admin-panel">
    <div class="admin-panel-head"><div><h2>Media Management</h2><p class="muted">Manage photographs, image collections, and videos displayed throughout the public website.</p></div></div>
  <nav class="page-management-tabs" role="tablist" aria-label="Media Management tabs" data-initial-tab="<?=e($activeMediaTab)?>">
    <button type="button" id="media-tab-gallery" role="tab" aria-controls="media-panel-gallery" aria-selected="false" tabindex="-1" data-media-tab="gallery">Gallery</button>
    <button type="button" id="media-tab-video" role="tab" aria-controls="media-panel-video" aria-selected="false" tabindex="-1" data-media-tab="video">Video</button>
  </nav>
  <?php
    $galleryPhotos = $pdo->query("SELECT id,title,description,folder_name,file_path,created_at FROM site_gallery_photos ORDER BY created_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $galleryFolders = [];
    $galleryRoot = __DIR__ . '/uploads/gallery';
    if (is_dir($galleryRoot)) {
      foreach (scandir($galleryRoot) ?: [] as $folderEntry) {
        if ($folderEntry === '.' || $folderEntry === '..') continue;
        if (is_dir($galleryRoot . '/' . $folderEntry) && isValidGalleryFolderName($folderEntry)) $galleryFolders[] = $folderEntry;
      }
    }
    sort($galleryFolders, SORT_NATURAL | SORT_FLAG_CASE);
    // Keep every physical Gallery folder in the listing, even when it has no photos.
    // Empty folders remain available for future uploads and display the empty-cover state.
    $galleryGroupedPhotos = [];
    foreach ($galleryFolders as $folderName) {
      $galleryGroupedPhotos[$folderName] = [];
    }
    foreach ($galleryPhotos as $galleryPhoto) {
      $groupKey = (string)($galleryPhoto['folder_name'] ?? '');
      if (!array_key_exists($groupKey, $galleryGroupedPhotos)) $galleryGroupedPhotos[$groupKey] = [];
      $galleryGroupedPhotos[$groupKey][] = $galleryPhoto;
    }
    // After a successful upload, place the destination folder at the front of the folder grid.
    $galleryFocusFolder = (string)($_GET['gallery_focus_folder'] ?? '');
    if ($galleryFocusFolder !== '' && array_key_exists($galleryFocusFolder, $galleryGroupedPhotos)) {
      $galleryGroupedPhotos = [$galleryFocusFolder => $galleryGroupedPhotos[$galleryFocusFolder]] + $galleryGroupedPhotos;
    }
    $gallerySavedCount = max(0, (int)($_GET['gallery_saved'] ?? 0));
    $galleryMoved = (string)($_GET['gallery_moved'] ?? '') === '1';
    $galleryMovedFolder = (string)($_GET['gallery_moved_folder'] ?? '');
  ?>
  <section class="page-management-tabpanel" id="media-panel-gallery" role="tabpanel" aria-labelledby="media-tab-gallery">
    <?php if ($gallerySavedCount > 0): ?><div class="gallery-inline-success" role="status"><?= $gallerySavedCount ?> <?= $gallerySavedCount === 1 ? 'photo was' : 'photos were' ?> successfully added to the Gallery.</div><?php endif; ?>
    <div class="gallery-panel-top">
      <div><h3>Photo Gallery</h3><p class="muted">Browse uploaded photos, newest first.</p></div>
      <button class="btn" type="button" id="gallery-add-folder">+ Add Folder</button>
    </div>
    <?php if ($galleryMoved): ?><div class="gallery-modal-backdrop" id="gallery-move-success-modal"><section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-move-success-title"><div class="gallery-upload-success-mark">✓</div><h2 id="gallery-move-success-title"><?php if ($galleryMovedFolder !== ''): ?>Photo moved successfully to <strong class="gallery-moved-folder-name"><?=e($galleryMovedFolder)?></strong>.<?php else: ?>Photo moved successfully outside the folder.<?php endif; ?></h2><div class="gallery-modal-actions"><button class="btn" type="button" id="gallery-move-success-close">OK</button></div></section></div><?php endif; ?>
    <div id="gallery-folder-view">
      <form method="post" id="gallery-folder-bulk-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_selected_gallery_folders">
        <div class="gallery-controls gallery-folder-controls">
          <label>Show <select id="gallery-folder-page-size" aria-label="Folders per page"><option value="9">9</option><option value="18">18</option><option value="36">36</option><option value="72">72</option></select> folders</label>
          <label class="gallery-filter-wrap"><span>Filter</span><input id="gallery-folder-filter" type="search" placeholder="Search folder name..." aria-label="Filter Gallery folders"></label>
          <button class="btn bulk-delete" type="button" id="gallery-delete-selected">Delete Selected</button>
        </div>
        <div class="gallery-pagination gallery-photo-pagination gallery-folder-pagination"><span class="muted" id="gallery-folder-page-info"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="gallery-folder-first" aria-label="First page">First</button><button type="button" class="btn light" id="gallery-folder-prev">Previous</button><span id="gallery-folder-page-number" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="gallery-folder-next">Next</button><button type="button" class="btn light" id="gallery-folder-last" aria-label="Last page">Last</button></div></div>
        <div class="gallery-folder-browser" id="gallery-folder-browser">
          <label class="gallery-folder-select-all"><input type="checkbox" id="gallery-folder-select-all"> Select All</label>
          <?php foreach ($galleryGroupedPhotos as $groupName => $groupPhotos): ?>
            <article class="gallery-folder-tile" data-gallery-folder-card data-gallery-folder-name="<?=e($groupName)?>" data-gallery-folder-search="<?=e(mb_strtolower($groupName!==''?$groupName:'Unfiled Photos'))?>">
              <?php if($groupName!==''): ?><label class="gallery-folder-select"><input type="checkbox" name="selected_gallery_folders[]" value="<?=e($groupName)?>"> Select</label><?php endif; ?>
              <button type="button" class="gallery-folder-open" data-gallery-open-folder="<?=e($groupName)?>">
                <span class="gallery-folder-tab" aria-hidden="true"></span><span class="gallery-folder-cover">
                  <?php if($groupPhotos): ?><img src="<?=e($groupPhotos[0]['file_path'])?>" alt="" loading="lazy"><?php if(count($groupPhotos)>1): ?><span class="gallery-folder-cover-stack" aria-hidden="true"><img src="<?=e($groupPhotos[1]['file_path'])?>" alt="" loading="lazy"><?php if(count($groupPhotos)>2): ?><img src="<?=e($groupPhotos[2]['file_path'])?>" alt="" loading="lazy"><?php endif; ?></span><?php endif; ?><?php else: ?><span class="gallery-folder-cover-empty">▧</span><?php endif; ?>
                  <span class="gallery-folder-cover-shade"></span><span class="gallery-folder-cover-count"><?=count($groupPhotos)?> <?=count($groupPhotos)===1?'photo':'photos'?></span>
                </span><span class="gallery-folder-card-info"><span class="gallery-folder-tile-name"><?=e($groupName!==''?$groupName:'Unfiled Photos')?></span><span class="gallery-folder-tile-count">Open collection <span aria-hidden="true">↗</span></span></span>
              </button>
              <?php if($groupName!==''): ?><button type="button" class="gallery-folder-edit" data-gallery-edit-folder="<?=e($groupName)?>">Edit</button><?php endif; ?>
            </article>
          <?php endforeach; ?>
          <?php if(!$galleryFolders && !$galleryPhotos): ?><div class="gallery-empty">No folders or photos yet. Select Add Folder to create your first Gallery folder.</div><?php endif; ?>
        </div>
        <div class="gallery-pagination gallery-photo-pagination gallery-folder-pagination"><span class="muted" id="gallery-folder-page-info-bottom"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="gallery-folder-first-bottom" aria-label="First page">First</button><button type="button" class="btn light" id="gallery-folder-prev-bottom">Previous</button><span id="gallery-folder-page-number-bottom" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="gallery-folder-next-bottom">Next</button><button type="button" class="btn light" id="gallery-folder-last-bottom" aria-label="Last page">Last</button></div></div>
      </form>
    </div>
    <div id="gallery-photo-view" hidden>
      <div class="gallery-photo-view-toolbar"><button type="button" class="btn light" id="gallery-back-folders">← All Folders</button><h4 id="gallery-current-folder-title">Folder</h4><button type="button" class="btn" id="gallery-add-photo-in-folder">+ Add Photo</button></div>
      <div class="gallery-controls gallery-photo-toolbar">
        <label>Show <select id="gallery-page-size" aria-label="Photos per page"><option value="12">12</option><option value="24">24</option><option value="48">48</option><option value="96">96</option></select> photos</label>
        <label class="gallery-filter-wrap"><span>Filter</span><input id="gallery-filter" type="search" placeholder="Search photo title or description..." aria-label="Filter photos in folder"></label>
        <form method="post" id="gallery-photo-bulk-form" class="gallery-photo-bulk-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_selected_gallery_photos"><input type="hidden" name="gallery_return_folder" id="gallery-return-folder" value="<?=e($galleryReturnFolder)?>">
          <button class="btn bulk-delete" type="button" id="gallery-photo-delete-selected" disabled aria-disabled="true">Delete Selected</button>
        </form>
      </div>
      <div class="gallery-pagination gallery-photo-pagination"><span class="muted" id="gallery-page-info" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="gallery-first" aria-label="First page">First</button><button type="button" class="btn light" id="gallery-prev">Previous</button><span id="gallery-page-number" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="gallery-next">Next</button><button type="button" class="btn light" id="gallery-last" aria-label="Last page">Last</button></div></div>
      <div class="gallery-photo-select-all-row"><label class="gallery-photo-select-all"><input type="checkbox" id="gallery-photo-select-all"> Select All</label></div>
      <div id="gallery-folder-groups">
      <?php foreach ($galleryGroupedPhotos as $groupName => $groupPhotos): ?>
        <section class="gallery-folder-group" data-gallery-folder-group data-gallery-folder-name="<?=e($groupName)?>">
          <div class="gallery-folder-heading"><div><h4><?=e($groupName !== '' ? $groupName : 'Unfiled Photos')?></h4><span><?=count($groupPhotos)?> <?=count($groupPhotos) === 1 ? 'photo' : 'photos'?></span></div><span class="gallery-folder-label"><?= $groupName !== '' ? 'Folder' : 'Outside folders' ?></span></div>
          <div class="gallery-grid">
            <?php foreach ($groupPhotos as $photo): ?>
              <article class="gallery-photo-card" data-photo-id="<?=(int)$photo['id']?>" data-photo-description="<?=e((string)$photo['description'])?>" data-gallery-search="<?=e(mb_strtolower($photo['title'].' '.$photo['description'].' '.($photo['folder_name'] ?? '')))?>">
                <label class="gallery-photo-select"><input type="checkbox" form="gallery-photo-bulk-form" name="selected_gallery_photos[]" value="<?=(int)$photo['id']?>"> Select</label>
                <div class="gallery-photo-image"><img src="<?=e($photo['file_path'])?>" alt="<?=e($photo['title'])?>" loading="lazy"><div class="gallery-photo-description"><?=e($photo['description'] !== '' ? $photo['description'] : 'No description added.')?></div></div>
                <div class="gallery-photo-title" data-photo-title="<?=e($photo['title'])?>" title="<?=e($photo['title'])?>"><?=e($photo['title'])?></div>
                <button type="button" class="btn light gallery-photo-edit" data-gallery-edit-photo="<?=(int)$photo['id']?>" data-photo-title="<?=e($photo['title'])?>" data-photo-description="<?=e($photo['description'])?>" data-photo-src="<?=e($photo['file_path'])?>">Edit</button>
                <div class="gallery-photo-meta"><?=e(date('M d, Y', strtotime((string)$photo['created_at'])))?></div>
                <form method="post" class="gallery-move-form">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="action" value="move_gallery_photo">
                  <input type="hidden" name="photo_id" value="<?=(int)$photo['id']?>">
                  <select name="target_folder" aria-label="Move <?=e($photo['title'])?> to folder">
                    <option value="" <?=empty($photo['folder_name'])?'selected':''?>>Outside folders</option>
                    <?php foreach ($galleryFolders as $folderOption): ?><option value="<?=e($folderOption)?>" <?=($photo['folder_name'] ?? '')===$folderOption?'selected':''?>><?=e($folderOption)?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn light">Move</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if (!$galleryPhotos): ?><div class="gallery-empty" id="gallery-empty">No photos have been uploaded yet. Select Add Photo to create your first gallery collection.</div><?php endif; ?>
      </div>
      <div class="gallery-pagination gallery-photo-pagination"><span class="muted" id="gallery-page-info-bottom" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="gallery-first-bottom" aria-label="First page">First</button><button type="button" class="btn light" id="gallery-prev-bottom">Previous</button><span id="gallery-page-number-bottom" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="gallery-next-bottom">Next</button><button type="button" class="btn light" id="gallery-last-bottom" aria-label="Last page">Last</button></div></div>
      <div class="gallery-empty" id="gallery-no-results" hidden>No photos match your filter.</div>
    </div>
  </section>
   <div class="gallery-modal-backdrop" id="gallery-photo-delete-confirm-modal" hidden><section class="gallery-modal gallery-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-photo-delete-title"><div class="gallery-modal-head"><div><div class="gallery-upload-success-mark gallery-delete-mark">!</div><h2 id="gallery-photo-delete-title">Delete Photo!</h2><p id="gallery-photo-delete-message">Are you sure you want to delete the selected photo in this folder?</p></div></div><div class="gallery-modal-actions"><button class="btn light" type="button" id="gallery-photo-delete-cancel">Cancel</button><button class="btn bulk-delete" type="button" id="gallery-photo-delete-confirm">Delete</button></div></section></div>
   <?php if($galleryPhotoDeleteSuccess): ?><div class="gallery-modal-backdrop" id="gallery-photo-delete-success-modal"><section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true"><div class="gallery-upload-success-mark">✓</div><h2>Photo deleted successfully.</h2><div class="gallery-modal-actions"><a class="btn" id="gallery-photo-delete-success-close" href="admin.php?section=media-management&amp;media_tab=gallery&amp;gallery_folder=<?=rawurlencode($galleryReturnFolder)?>">OK</a></div></section></div><?php endif; ?>
  
  <style>
    #gallery-photo-edit-success-modal .gallery-photo-title-old{color:#dc2626;}
    #gallery-photo-edit-success-modal .gallery-photo-title-new{color:#16a34a;}
  </style>
  <?php if($galleryPhotoEditSuccess): ?><div class="gallery-modal-backdrop" id="gallery-photo-edit-success-modal"><section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true"><div class="gallery-upload-success-mark">✓</div><h2><?php if($galleryPhotoEditIsTitleRename): ?>The Photo Title <span class="gallery-photo-title-old"><?=e($galleryPhotoEditOldTitle)?></span> has been renamed to <span class="gallery-photo-title-new"><?=e($galleryPhotoEditNewTitle)?></span>.<?php else: ?><?php if($galleryPhotoReplacedTitle!==''): ?>The photo with the photo title <span class="gallery-photo-title-old"><?=e($galleryPhotoReplacedTitle)?></span> has been successfully replaced.<?php else: ?><?=e($galleryPhotoEditSuccessMessage!==''?$galleryPhotoEditSuccessMessage:'Photo successfully updated.')?><?php endif; ?><?php endif; ?></h2><div class="gallery-modal-actions"><a class="btn" id="gallery-photo-edit-success-close" href="admin.php?section=media-management&amp;media_tab=gallery&amp;gallery_folder=<?=rawurlencode($galleryPhotoEditSuccessFolder)?>">OK</a></div></section></div><?php endif; ?>
  <div class="gallery-modal-backdrop" id="gallery-photo-edit-modal" hidden><section class="gallery-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-photo-edit-title"><div class="gallery-modal-head"><div><h2 id="gallery-photo-edit-title">Edit Photo</h2><p class="muted">Update the photo title, description, or image.</p></div></div><form method="post" enctype="multipart/form-data" id="gallery-photo-edit-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="edit_gallery_photo"><input type="hidden" name="photo_id" id="gallery-photo-edit-id"><div class="gallery-photo-edit-preview"><img id="gallery-photo-edit-preview" src="" alt=""></div><label class="gallery-form-field">Photo title<input type="text" name="photo_title" id="gallery-photo-edit-name" maxlength="180" required></label><label class="gallery-form-field">Description<textarea name="photo_description" id="gallery-photo-edit-description" maxlength="2000"></textarea></label><label class="gallery-form-field">Replace photo (optional)<input type="file" name="replacement_photo" id="gallery-photo-edit-replacement" accept=".jpg,.jpeg,.png,.webp,.avif,image/jpeg,image/png,image/webp,image/avif"><small>JPG, PNG, WebP or AVIF · maximum 10 MB</small><div id="gallery-photo-edit-replacement-preview-wrap" class="gallery-photo-edit-replacement-preview" hidden><img id="gallery-photo-edit-replacement-preview" src="" alt="Selected replacement photo preview"><button type="button" class="btn light" id="gallery-photo-edit-replacement-remove">Remove photo</button></div></label><div class="gallery-edit-progress" id="gallery-photo-edit-progress" hidden role="status" aria-live="polite"><div class="gallery-upload-progress-head"><span id="gallery-photo-edit-progress-label">Uploading replacement photo…</span><strong id="gallery-photo-edit-progress-value">0%</strong></div><div class="gallery-upload-progress-track"><div class="gallery-upload-progress-fill" id="gallery-photo-edit-progress-fill" style="width:0%"></div></div></div><div class="gallery-upload-error" id="gallery-photo-edit-error" role="alert" hidden></div><div class="gallery-modal-actions"><button class="btn light" type="button" id="gallery-photo-edit-cancel">Cancel</button><button class="btn" type="submit" id="gallery-photo-edit-save">Save Changes</button></div></form></section></div>
  <div class="gallery-modal-backdrop" id="gallery-delete-confirm-modal" hidden><section class="gallery-modal gallery-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-delete-confirm-title"><div class="gallery-upload-success-mark gallery-delete-mark">!</div><h2 id="gallery-delete-confirm-title">Delete Selected Folder/s?</h2><p>Are you sure you want to delete the selected folder/s? Deleting a folder containing photos will also delete them.</p><div class="gallery-modal-actions"><button class="btn light" type="button" id="gallery-delete-cancel">Cancel</button><button class="btn bulk-delete" type="button" id="gallery-delete-confirm">Delete</button></div></section></div>
  <?php if($galleryDeleteSuccessNames): ?><div class="gallery-modal-backdrop" id="gallery-delete-success-modal"><section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-delete-success-title"><div class="gallery-upload-success-mark">✓</div><?php if(count($galleryDeleteSuccessNames)===1): ?><h2 id="gallery-delete-success-title">The Folder <strong class="gallery-deleted-folder-name"><?=e($galleryDeleteSuccessNames[0])?></strong> has been successfully deleted.</h2><?php else: ?><h2 id="gallery-delete-success-title">The following folders have been successfully deleted:</h2><ul class="gallery-delete-success-list"><?php foreach($galleryDeleteSuccessNames as $deletedFolder): ?><li><strong class="gallery-deleted-folder-name"><?=e($deletedFolder)?></strong></li><?php endforeach; ?></ul><?php endif; ?><div class="gallery-modal-actions"><button class="btn" type="button" id="gallery-delete-success-close">OK</button></div></section></div><?php endif; ?>
  <?php if($galleryEditSuccess): ?><div class="gallery-modal-backdrop" id="gallery-edit-success-modal"><section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true"><div class="gallery-upload-success-mark">✓</div><h2><?=e($galleryEditSuccessMessage)?></h2><div class="gallery-modal-actions"><button class="btn" type="button" id="gallery-edit-success-close">Continue</button></div></section></div><?php endif; ?>
  <div class="gallery-modal-backdrop" id="gallery-edit-modal" hidden><section class="gallery-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-edit-modal-title"><div class="gallery-modal-head"><div><h2 id="gallery-edit-modal-title">Edit Folder</h2><p class="muted">Rename this folder, update photo details, or replace its photos.</p></div></div><form method="post" enctype="multipart/form-data" id="gallery-edit-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="edit_gallery_folder"><input type="hidden" name="original_folder_name" id="gallery-edit-original-folder"><label class="gallery-form-field">Folder name<input type="text" name="edited_folder_name" id="gallery-edit-folder-name" maxlength="80" required></label><div class="gallery-photo-details" id="gallery-edit-photo-details"></div><div class="gallery-edit-progress" id="gallery-edit-progress" hidden role="status" aria-live="polite"><div class="gallery-upload-progress-head"><span id="gallery-edit-progress-label">Saving changes…</span><strong id="gallery-edit-progress-value">0%</strong></div><div class="gallery-upload-progress-track"><div class="gallery-upload-progress-fill" id="gallery-edit-progress-fill" style="width:0%"></div></div></div><div class="gallery-upload-error" id="gallery-edit-error" role="alert" hidden></div><div class="gallery-modal-actions"><button class="btn light" type="button" id="gallery-edit-cancel">Cancel</button><button class="btn" type="submit" id="gallery-edit-save">Save Changes</button></div></form></section></div>
  <div class="gallery-modal-backdrop" id="gallery-add-folder-modal" hidden>
    <section class="gallery-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-add-folder-title">
      <div class="gallery-modal-head">
        <div><h2 id="gallery-add-folder-title">Add Folder Form</h2><p class="muted">Create a new folder in the Photo Gallery.</p></div>
        
      </div>
      <form method="post" id="gallery-add-folder-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="create_gallery_folder">
        <label class="gallery-form-field">Folder Name
          <input type="text" name="gallery_new_folder_name" id="gallery-add-folder-name" maxlength="80" placeholder="Enter folder name" required>
          <small>Letters, numbers, spaces and symbols are allowed · maximum 80 characters</small>
        </label>
        <div class="gallery-modal-actions">
          <button class="btn light" type="button" id="gallery-add-folder-cancel">Cancel</button>
          <button class="btn" type="submit" id="gallery-add-folder-submit">Create Folder</button>
        </div>
      </form>
    </section>
  </div>
  <div class="gallery-modal-backdrop" id="gallery-add-modal" hidden>
    <section class="gallery-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-modal-title">
      <div class="gallery-modal-head"><div><h2 id="gallery-modal-title">Add Photo Form</h2><p class="muted">Upload up to 20 photos in one batch.</p></div></div>
      <form method="post" enctype="multipart/form-data" id="gallery-upload-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_gallery_photos">
        <div class="gallery-form-grid">
          <label class="gallery-form-field">Save photos to
            <select name="gallery_folder_mode" id="gallery-folder-mode" required><option value="" selected disabled>Choose an option</option><option value="existing" <?=!$galleryFolders?'disabled':''?>>Upload to existing folder</option><option value="new">Create new folder</option></select>
          </label>
          <label class="gallery-form-field" id="gallery-existing-folder-field" hidden>Choose existing folder
            <select name="gallery_existing_folder" id="gallery-existing-folder"><option value="" selected disabled>Choose a folder</option><?php foreach ($galleryFolders as $folder): ?><option value="<?=e($folder)?>"><?=e($folder)?></option><?php endforeach; ?></select>
          </label>
          <label class="gallery-form-field" id="gallery-new-folder-field" hidden>Folder Name
            <input type="text" name="gallery_folder_name" id="gallery-folder-name" maxlength="80" placeholder="Enter folder name">
          </label>
        </div>
        <label class="gallery-form-field gallery-file-field">Select photos (maximum 20)
          <input type="file" name="gallery_photos[]" id="gallery-files" accept=".jpg,.jpeg,.png,.webp,.avif,image/jpeg,image/png,image/webp,image/avif" multiple required>
          <small>JPEG, JPG, PNG, AVIF or WEBP · maximum 10 MB per photo · up to 20 photos</small>
        </label>
        <div class="gallery-photo-details" id="gallery-photo-details"><p class="muted">Choose photo files to enter a title and description for each image.</p></div>
        <div class="gallery-upload-progress" id="gallery-upload-progress" hidden role="status" aria-live="polite">
          <div class="gallery-upload-progress-head"><span id="gallery-upload-progress-label">Uploading photos…</span><strong id="gallery-upload-progress-value">0%</strong></div>
          <div class="gallery-upload-progress-track"><div class="gallery-upload-progress-fill" id="gallery-upload-progress-fill" style="width:0%"></div></div>
        </div>
        <div class="gallery-upload-error" id="gallery-upload-error" role="alert" hidden></div>
        <div class="gallery-modal-actions"><button class="btn light" type="button" id="gallery-modal-cancel">Cancel</button><button class="btn" type="submit" id="gallery-upload-submit">Upload Photos</button></div>
      </form>
    </section>
  </div>
  <div class="gallery-modal-backdrop" id="gallery-upload-success-modal" hidden>
    <section class="gallery-modal gallery-upload-success-modal" role="dialog" aria-modal="true" aria-labelledby="gallery-upload-success-title">
      <div class="gallery-upload-success-mark" aria-hidden="true">✓</div>
      <h2 id="gallery-upload-success-title">Upload successful</h2>
      <p id="gallery-upload-success-message"></p>
      <div class="gallery-modal-actions gallery-upload-success-actions"><button class="btn" type="button" id="gallery-upload-success-close">OK</button></div>
    </section>
  </div>
  <style>
.gallery-folder-tile.gallery-folder-recently-updated{outline:2px solid rgba(220,100,120,.9);outline-offset:4px;box-shadow:0 0 0 5px rgba(184,50,63,.13),0 12px 28px rgba(0,0,0,.24)}
    .gallery-panel-top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:0 0 15px}.gallery-panel-top h3{margin:0 0 4px;font:600 15px Ubuntu,sans-serif;color:#f5e9ed}.gallery-panel-top p{margin:0;font-size:11px}
    .gallery-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 10px;color:var(--muted);font:12px Ubuntu,sans-serif}.gallery-controls label{display:flex;align-items:center;gap:8px}.gallery-controls select,.gallery-controls input,.gallery-form-field input,.gallery-form-field select{background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:9px 10px;font:12px Ubuntu,sans-serif}.gallery-filter-wrap input{width:min(340px,65vw)}
    .gallery-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:10px 0 18px}.gallery-pagination>div{display:flex;align-items:center;gap:9px}.gallery-pagination .btn{padding:7px 11px;font-size:11px}.gallery-pagination .btn:disabled{opacity:.4;cursor:not-allowed}.gallery-photo-pagination .gallery-page-controls{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}.gallery-photo-pagination .gallery-page-numbers{display:flex;align-items:center;gap:5px}.gallery-photo-pagination .gallery-page-number{min-width:30px;padding:7px 9px!important;text-align:center}.gallery-photo-pagination .gallery-page-number.is-active{background:linear-gradient(135deg,#8f243b,#bd344b);border-color:#d45a70;color:#fff;box-shadow:0 0 0 1px rgba(212,90,112,.18)}.gallery-photo-pagination .gallery-page-number:focus-visible,.gallery-photo-pagination .btn:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}@media(max-width:680px){.gallery-photo-pagination{align-items:flex-start}.gallery-photo-pagination .gallery-page-controls{justify-content:flex-start;gap:5px}.gallery-photo-pagination .gallery-page-number{min-width:28px}}
         .gallery-folder-controls{margin-top:8px}.gallery-folder-controls #gallery-delete-selected:disabled{opacity:.42;filter:saturate(.35);cursor:not-allowed;pointer-events:none}.gallery-folder-select-all{grid-column:1/-1;display:flex;align-items:center;gap:6px;justify-self:start;margin:0 0 2px;padding:7px 10px;border:1px solid rgba(210,103,126,.25);border-radius:7px;background:rgba(116,24,45,.16);color:#e9dce0;font:11px Ubuntu,sans-serif}.gallery-folder-select-all input{accent-color:#b8323f}.gallery-folder-select-all{display:flex;align-items:center;gap:6px;color:#e9dce0;font:11px Ubuntu,sans-serif}.gallery-folder-select-all input{accent-color:#b8323f}.gallery-folder-tile{padding:0 0 43px;cursor:default}.gallery-folder-open{display:flex;flex-direction:column;align-items:stretch;gap:0;width:100%;padding:0;border:0;background:transparent;color:inherit;text-align:left;cursor:pointer}.gallery-folder-select{position:absolute;z-index:3;top:10px;left:10px;display:flex;align-items:center;gap:5px;padding:5px 8px;border:1px solid rgba(255,255,255,.25);border-radius:7px;background:rgba(15,10,13,.8);color:#fff;font:10px Ubuntu,sans-serif;cursor:pointer}.gallery-folder-select input{accent-color:#b8323f}.gallery-folder-edit{position:absolute;right:11px;bottom:10px;padding:6px 13px;border:1px solid rgba(210,103,126,.45);border-radius:7px;background:rgba(116,24,45,.3);color:#f5e9ed;font:11px Ubuntu,sans-serif;cursor:pointer}.gallery-folder-edit:hover{background:rgba(150,35,59,.55)}.gallery-folder-tile[hidden]{display:none!important}.gallery-confirm-modal{max-width:470px;text-align:center}.gallery-confirm-modal h2{margin:0 0 10px;color:#f5e9ed;font:600 17px Ubuntu,sans-serif}.gallery-confirm-modal p{color:#cdbfc4;font:12px/1.55 Ubuntu,sans-serif}.gallery-delete-mark{background:rgba(184,50,63,.16);border-color:rgba(210,75,95,.45);color:#ffb8c2}.gallery-edit-photo-row{display:grid;grid-template-columns:76px minmax(0,1fr);gap:10px;padding:10px;border:1px solid var(--line);border-radius:9px;background:rgba(255,255,255,.018)}.gallery-edit-photo-row img{width:76px;height:66px;object-fit:cover;border-radius:6px}.gallery-edit-photo-row .gallery-photo-detail-fields small{color:var(--muted);font:10px Ubuntu,sans-serif}.gallery-edit-photo-preview-wrap{position:relative;width:76px;height:66px}.gallery-edit-photo-row .gallery-edit-photo-preview-wrap img{width:76px;height:66px;object-fit:cover;border-radius:6px}.gallery-edit-photo-remove{margin-top:5px;padding:3px 7px;border:1px solid rgba(210,103,126,.4);border-radius:5px;background:rgba(116,24,45,.25);color:#f5e9ed;font:10px Ubuntu,sans-serif;cursor:pointer}.gallery-edit-progress{margin:14px 0;padding:12px;border:1px solid var(--line);border-radius:9px;background:rgba(255,255,255,.025)}.gallery-edit-progress[hidden]{display:none!important}.gallery-edit-progress .gallery-upload-progress-track{height:9px;border-radius:20px;background:#302129;overflow:hidden}.gallery-edit-progress .gallery-upload-progress-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#8f1f36,#d34c61);transition:width .12s linear}
.gallery-folder-browser{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px;margin-top:16px}.gallery-folder-tile{position:relative;display:flex;flex-direction:column;align-items:stretch;gap:0;min-width:0;padding:0 0 13px;border:1px solid rgba(184,50,63,.28);border-radius:13px;background:linear-gradient(145deg,rgba(43,24,32,.96),rgba(15,12,15,.98));color:#f5e9ed;text-align:left;cursor:pointer;overflow:visible;box-shadow:0 8px 22px rgba(0,0,0,.2);transition:border-color .2s,transform .2s,box-shadow .2s}.gallery-folder-tile:hover,.gallery-folder-tile:focus-visible{border-color:#bd4655;transform:translateY(-4px);box-shadow:0 14px 28px rgba(0,0,0,.34),0 0 0 1px rgba(184,50,63,.12);outline:none}.gallery-folder-tab{position:absolute;top:-8px;left:15px;width:76px;height:9px;border:1px solid rgba(184,50,63,.32);border-bottom:0;border-radius:7px 7px 0 0;background:linear-gradient(90deg,#743044,#3e202b)}.gallery-folder-cover{position:relative;display:block;height:142px;margin:0 0 12px;border-radius:12px 12px 0 0;overflow:hidden;background:linear-gradient(135deg,#4b2533,#171115)}.gallery-folder-cover>img{display:block;width:100%;height:100%;object-fit:cover;opacity:.88;transition:transform .35s}.gallery-folder-tile:hover .gallery-folder-cover>img{transform:scale(1.045)}.gallery-folder-cover-stack{position:absolute;right:12px;top:12px;display:flex;gap:5px;padding:5px;border:1px solid rgba(255,255,255,.2);border-radius:9px;background:rgba(12,8,10,.55);backdrop-filter:blur(7px)}.gallery-folder-cover-stack img{width:31px;height:31px;object-fit:cover;border-radius:5px;border:1px solid rgba(255,255,255,.35)}.gallery-folder-cover-shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,7,9,.04) 25%,rgba(10,7,9,.72) 100%)}.gallery-folder-cover-count{position:absolute;left:12px;bottom:11px;padding:5px 8px;border:1px solid rgba(255,255,255,.22);border-radius:20px;background:rgba(12,8,10,.58);backdrop-filter:blur(7px);color:#fff;font:10px Ubuntu,sans-serif}.gallery-folder-cover-empty{display:grid;place-items:center;width:100%;height:100%;color:#d6a5b0;font-size:42px;background:radial-gradient(circle at 50% 30%,rgba(184,50,63,.25),transparent 65%)}.gallery-folder-card-info{display:flex;flex-direction:column;gap:6px;padding:0 13px}.gallery-folder-tile-name{font:600 13px Ubuntu,sans-serif;overflow-wrap:anywhere}.gallery-folder-tile-count{display:flex;justify-content:space-between;align-items:center;color:#bcaab1;font:10px Ubuntu,sans-serif;letter-spacing:.02em}.gallery-photo-view-toolbar{display:flex;align-items:center;gap:14px;margin:12px 0 4px}.gallery-photo-view-toolbar h4{margin:0;color:#f4e8eb;font:600 14px Ubuntu,sans-serif}.gallery-folder-group{margin:18px 0 24px;padding:14px;border:1px solid rgba(184,50,63,.22);border-radius:12px;background:rgba(18,12,15,.38)}.gallery-folder-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid rgba(184,50,63,.2)}.gallery-folder-heading h4{margin:0 0 4px;color:#f4e8eb;font:600 14px Ubuntu,sans-serif}.gallery-folder-heading span{color:#bcaab1;font:11px Ubuntu,sans-serif}.gallery-folder-label{padding:5px 9px;border:1px solid rgba(184,50,63,.3);border-radius:20px;color:#e5a4b1!important}.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:15px}.gallery-photo-card{min-width:0;overflow:visible}.gallery-photo-image{position:relative;aspect-ratio:4/3;border:1px solid rgba(184,50,63,.25);border-radius:10px;overflow:visible;background:#100c0f}.gallery-photo-image>img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px}.gallery-photo-description{position:absolute;z-index:5;left:50%;bottom:calc(100% + 8px);width:min(250px,80vw);padding:10px 12px;border:1px solid rgba(210,103,126,.42);border-radius:9px;background:rgba(22,12,17,.97);box-shadow:0 12px 28px rgba(0,0,0,.4);color:#f5e9ed;font:11px/1.5 Ubuntu,sans-serif;opacity:0;visibility:hidden;transform:translate(-50%,5px);transition:.18s;pointer-events:none;white-space:normal;overflow-wrap:anywhere}.gallery-photo-image:hover .gallery-photo-description,.gallery-photo-image:focus-within .gallery-photo-description{opacity:1;visibility:visible;transform:translate(-50%,0)}.gallery-photo-title{margin:8px 1px 3px;color:#f4e8eb;font:600 12px/1.4 Ubuntu,sans-serif;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gallery-photo-meta{margin:0 1px;color:#a99aa0;font:10px Ubuntu,sans-serif}.gallery-empty{padding:22px 14px;border:1px dashed rgba(184,50,63,.3);border-radius:10px;color:var(--muted);font:12px Ubuntu,sans-serif;text-align:center;grid-column:1/-1}.gallery-inline-success{padding:10px 12px;margin-bottom:14px;border:1px solid rgba(70,160,110,.35);border-radius:8px;background:rgba(35,110,70,.12);color:#c5f0d6;font:12px Ubuntu,sans-serif}.gallery-upload-progress{margin:14px 0 4px;padding:12px;border:1px solid rgba(184,50,63,.3);border-radius:9px;background:rgba(255,255,255,.025)}.gallery-upload-progress[hidden],.gallery-upload-error[hidden]{display:none}.gallery-upload-progress-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:9px;color:#e9dce0;font:11px Ubuntu,sans-serif}.gallery-upload-progress-head strong{color:#f0a4b3;font-variant-numeric:tabular-nums}.gallery-upload-progress-track{height:9px;overflow:hidden;border-radius:20px;background:#302128}.gallery-upload-progress-fill{height:100%;width:0;border-radius:20px;background:linear-gradient(90deg,#74182d,#d34c66);transition:width .12s ease}.gallery-upload-error{margin-top:12px;padding:10px 12px;border:1px solid rgba(210,75,95,.4);border-radius:8px;background:rgba(130,25,45,.15);color:#ffc4ce;font:11px/1.5 Ubuntu,sans-serif}.gallery-upload-success-modal{text-align:center;max-width:420px}.gallery-upload-success-mark{display:grid;place-items:center;width:48px;height:48px;margin:4px auto 14px;border-radius:50%;background:rgba(65,155,103,.16);border:1px solid rgba(95,190,130,.45);color:#9ce0b5;font:28px Ubuntu,sans-serif}.gallery-upload-success-modal h2{margin:0 0 8px;color:#f5e9ed;font:600 17px Ubuntu,sans-serif}.gallery-upload-success-modal p{margin:0;color:#cdbfc4;font:12px Ubuntu,sans-serif}
    .gallery-modal-backdrop{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.76);backdrop-filter:blur(7px)}
    .slideshow-delete-overlay[hidden]{display:none!important}.slideshow-delete-card{width:min(420px,100%)}.slideshow-delete-card h2{margin-bottom:16px}.slideshow-delete-warning{background:rgba(150,35,55,.2);border-color:rgba(220,85,100,.48);color:#ff9eaa}.slideshow-delete-message{margin:0 0 22px;color:#d7c9cc;font:12px/1.55 Ubuntu,sans-serif}.slideshow-delete-actions{display:flex;justify-content:center;gap:9px}
    .gallery-modal-backdrop[hidden]{display:none!important}.gallery-modal{width:min(720px,96vw);max-height:90vh;overflow:auto;padding:22px;border:1px solid rgba(184,50,63,.38);border-radius:15px;background:#130e12;box-shadow:0 24px 70px rgba(0,0,0,.55)}.gallery-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:18px}.gallery-modal-head h2{margin:0 0 5px;color:#f5e9ed;font:600 17px Ubuntu,sans-serif}.gallery-modal-head p{margin:0;font-size:11px}.gallery-modal-close{width:32px;height:32px;border:1px solid var(--line);border-radius:8px;background:#21151a;color:#fff;font-size:22px;cursor:pointer}.gallery-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}.gallery-form-field{display:grid;gap:7px;color:#e9dce0;font:600 11px Ubuntu,sans-serif}.gallery-form-field input,.gallery-form-field select{width:100%;box-sizing:border-box}.gallery-form-field[hidden]{display:none}.gallery-file-field{margin-bottom:14px}.gallery-file-field small{color:var(--muted);font-weight:400}.gallery-photo-details{display:grid;gap:10px;max-height:300px;overflow:auto;margin:12px 0}.gallery-photo-detail-row{display:grid;grid-template-columns:76px minmax(0,1fr);gap:10px;padding:10px;border:1px solid var(--line);border-radius:9px;background:rgba(255,255,255,.018)}.gallery-photo-detail-row img{width:76px;height:66px;object-fit:cover;border-radius:6px}.gallery-photo-detail-fields{display:grid;gap:7px;min-width:0}.gallery-photo-detail-fields input,.gallery-photo-detail-fields textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--line);border-radius:7px;background:#171115;color:#f5e9ed;font:11px Ubuntu,sans-serif}.gallery-photo-detail-fields textarea{min-height:48px;resize:vertical}.gallery-modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:17px;padding-top:14px;border-top:1px solid var(--line)}.gallery-deleted-folder-name{font-weight:800;color:#e34b5b}.gallery-confirm-modal .gallery-modal-actions{justify-content:center}.gallery-upload-success-modal .gallery-modal-actions{justify-content:center}.gallery-upload-success-actions{justify-content:center;width:100%}.gallery-photo-toolbar{display:grid;grid-template-columns:minmax(150px,1fr) minmax(220px,2fr) auto;align-items:center;gap:14px;margin:0 0 10px}.gallery-photo-toolbar>label:first-child{justify-self:start}.gallery-photo-toolbar>.gallery-filter-wrap{justify-self:center;width:min(100%,480px)}.gallery-photo-toolbar>.gallery-filter-wrap input{width:100%;box-sizing:border-box}.gallery-photo-toolbar>.gallery-photo-bulk-form{justify-self:end;margin:0}.gallery-photo-selection-controls{justify-content:space-between}@media(max-width:680px){.gallery-photo-toolbar{grid-template-columns:1fr 1fr;gap:10px}.gallery-photo-toolbar>.gallery-filter-wrap{grid-column:1/-1;grid-row:2;justify-self:stretch;width:100%}.gallery-photo-toolbar>.gallery-photo-bulk-form{grid-column:2;grid-row:1}}.gallery-photo-select-all-row{display:flex;align-items:center;justify-content:flex-start;margin:12px 0 10px;padding:0 2px}.gallery-photo-select-all-row .gallery-photo-select-all{font-weight:700}.gallery-photo-bulk-form .bulk-delete:disabled,.gallery-photo-bulk-form .bulk-delete[aria-disabled="true"]{opacity:.42;filter:saturate(.35);cursor:not-allowed;pointer-events:none}.gallery-photo-select-all,.gallery-photo-select{display:flex;align-items:center;gap:6px;color:#e9dce0;font:11px Ubuntu,sans-serif}.gallery-photo-select input,.gallery-photo-select-all input{accent-color:#b8323f}.gallery-photo-card{position:relative}.gallery-photo-select{position:absolute;z-index:6;top:8px;left:8px;padding:5px 8px;border:1px solid rgba(255,255,255,.25);border-radius:7px;background:rgba(15,10,13,.84);color:#fff;font-size:10px}.gallery-photo-edit{margin:7px 0 2px;padding:6px 12px;font-size:10px}.gallery-photo-edit-preview{display:flex;justify-content:center;margin:0 0 14px}.gallery-photo-edit-preview img{max-width:180px;max-height:130px;object-fit:contain;border-radius:8px}.gallery-photo-edit-replacement-preview{display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap}.gallery-photo-edit-replacement-preview[hidden]{display:none!important}.gallery-photo-edit-replacement-preview img{display:block;max-width:180px;max-height:130px;object-fit:contain;border:1px solid rgba(210,83,105,.4);border-radius:8px}.gallery-photo-edit-replacement-preview .btn{padding:7px 11px;font-size:11px}.gallery-photo-bulk-form{margin:0 0 10px}
    .gallery-moved-folder-name{font-weight:800;color:#e34b5b}.gallery-move-form{display:flex;gap:6px;margin-top:9px}.gallery-move-form select{min-width:0;flex:1;padding:7px 8px;border:1px solid rgba(184,50,63,.3);border-radius:7px;background:#171014;color:#f2e7eb;font:10px Ubuntu,sans-serif}.gallery-move-form .btn{padding:7px 9px;font-size:10px;white-space:nowrap}.gallery-folder-group[hidden]{display:none!important}
    @media(max-width:600px){.gallery-panel-top{align-items:flex-start}.gallery-form-grid{grid-template-columns:1fr}.gallery-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gallery-photo-detail-row{grid-template-columns:58px minmax(0,1fr)}.gallery-photo-detail-row img{width:58px;height:58px}}
  
    /* Consistent centered Gallery modal presentation for Add, Delete and Edit. */
    .gallery-modal-backdrop{z-index:12000;padding:22px;overflow-y:auto;}
    .gallery-modal-backdrop:not([hidden]){animation:galleryOverlayIn .16s ease-out;}
    .gallery-modal{width:min(620px,calc(100vw - 36px));max-height:min(88vh,820px);padding:25px;border-radius:16px;border:1px solid rgba(210,83,105,.48);background:linear-gradient(145deg,#1b1117 0%,#100c10 100%);box-shadow:0 28px 90px rgba(0,0,0,.72);animation:galleryDialogIn .18s ease-out;}
    .gallery-modal-head{align-items:flex-start;}
    .gallery-modal-head h2,.gallery-confirm-modal h2{font-size:18px;letter-spacing:.1px;}
    .gallery-modal-close{flex:0 0 34px;width:34px;height:34px;display:grid;place-items:center;border:1px solid rgba(210,103,126,.35);border-radius:9px;background:rgba(120,28,48,.18);color:#f5e9ed;font-size:23px;line-height:1;cursor:pointer;}
    .gallery-modal-close:hover{background:rgba(150,35,55,.36);border-color:rgba(220,100,120,.65);}
    .gallery-confirm-modal .gallery-modal-head{margin-bottom:12px;}
    .gallery-confirm-modal .gallery-upload-success-mark{margin-bottom:12px;}
    .gallery-modal-actions{gap:10px;margin-top:22px;}
    .gallery-modal-actions .btn{min-width:104px;}
    @keyframes galleryOverlayIn{from{opacity:0}to{opacity:1}}
    @keyframes galleryDialogIn{from{opacity:0;transform:translateY(9px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
    @media(max-width:600px){.gallery-modal{padding:18px;width:calc(100vw - 24px);}.gallery-modal-backdrop{padding:12px;}}
</style>
  <script>
  (() => {
    const folderCards=[...document.querySelectorAll('[data-gallery-folder-card]')],folderFilter=document.getElementById('gallery-folder-filter'),folderSize=document.getElementById('gallery-folder-page-size'),folderInfo=document.getElementById('gallery-folder-page-info'),folderNumber=document.getElementById('gallery-folder-page-number'),folderPrev=document.getElementById('gallery-folder-prev'),folderNext=document.getElementById('gallery-folder-next'),folderFirst=document.getElementById('gallery-folder-first'),folderLast=document.getElementById('gallery-folder-last'),folderInfoBottom=document.getElementById('gallery-folder-page-info-bottom'),folderNumberBottom=document.getElementById('gallery-folder-page-number-bottom'),folderPrevBottom=document.getElementById('gallery-folder-prev-bottom'),folderNextBottom=document.getElementById('gallery-folder-next-bottom'),folderFirstBottom=document.getElementById('gallery-folder-first-bottom'),folderLastBottom=document.getElementById('gallery-folder-last-bottom');
    let folderPage=1;
    const folderMatches=()=>{const q=(folderFilter?.value||'').trim().toLocaleLowerCase();return folderCards.filter(card=>(card.dataset.galleryFolderSearch||'').includes(q));};
    const renderFolders=()=>{if(!folderInfo||!folderSize)return;const found=folderMatches(),perPage=Number(folderSize.value)||9,pages=Math.max(1,Math.ceil(found.length/perPage));folderPage=Math.min(folderPage,pages);const start=found.length?(folderPage-1)*perPage:0,end=Math.min(start+perPage,found.length),visible=new Set(found.slice(start,end));folderCards.forEach(card=>card.hidden=!visible.has(card));const label=found.length?'Showing '+(start+1)+' to '+end+' of '+found.length+' folders':'Showing 0 folders';[folderInfo,folderInfoBottom].forEach(el=>{if(el)el.textContent=label;});const renderFolderNumbers=container=>{if(!container)return;container.replaceChildren();for(let n=1;n<=pages;n++){const button=document.createElement('button');button.type='button';button.className='btn light gallery-page-number'+(n===folderPage?' is-active':'');button.textContent=String(n);button.setAttribute('aria-label','Go to page '+n);button.setAttribute('aria-current',n===folderPage?'page':'false');button.addEventListener('click',()=>{folderPage=n;renderFolders();});container.appendChild(button);}};renderFolderNumbers(folderNumber);renderFolderNumbers(folderNumberBottom);[folderPrev,folderPrevBottom,folderFirst,folderFirstBottom].forEach(el=>{if(el)el.disabled=folderPage<=1;});[folderNext,folderNextBottom,folderLast,folderLastBottom].forEach(el=>{if(el)el.disabled=folderPage>=pages;});};
    const moveFolderPage=delta=>{const pages=Math.max(1,Math.ceil(folderMatches().length/(Number(folderSize?.value)||9)));folderPage=Math.max(1,Math.min(pages,folderPage+delta));renderFolders();};
    const focusFolder=new URLSearchParams(window.location.search).get('gallery_focus_folder');
    if(focusFolder){const recentCard=folderCards.find(card=>card.dataset.galleryFolderName===focusFolder);if(recentCard){recentCard.classList.add('gallery-folder-recently-updated');folderPage=1;}}
    folderFilter?.addEventListener('input',()=>{folderPage=1;renderFolders();});folderSize?.addEventListener('change',()=>{folderPage=1;renderFolders();});[folderFirst,folderFirstBottom].forEach(button=>button?.addEventListener('click',()=>{folderPage=1;renderFolders();}));folderPrev?.addEventListener('click',()=>moveFolderPage(-1));folderPrevBottom?.addEventListener('click',()=>moveFolderPage(-1));folderNext?.addEventListener('click',()=>moveFolderPage(1));folderNextBottom?.addEventListener('click',()=>moveFolderPage(1));[folderLast,folderLastBottom].forEach(button=>button?.addEventListener('click',()=>{folderPage=Math.max(1,Math.ceil(folderMatches().length/(Number(folderSize?.value)||9)));renderFolders();}));renderFolders();if(focusFolder){const recentCard=folderCards.find(card=>card.dataset.galleryFolderName===focusFolder);if(recentCard&&!recentCard.hidden)recentCard.scrollIntoView({behavior:'smooth',block:'center'});}
    const bulkForm=document.getElementById('gallery-folder-bulk-form'),selectAllFolders=document.getElementById('gallery-folder-select-all'),deleteModal=document.getElementById('gallery-delete-confirm-modal'),deleteSelectedButton=document.getElementById('gallery-delete-selected');
    const folderCheckboxes=()=>[...(bulkForm?.querySelectorAll('input[name="selected_gallery_folders[]"]')||[])];
    const syncDeleteSelectedState=()=>{const boxes=folderCheckboxes(),selected=boxes.filter(box=>box.checked).length;if(deleteSelectedButton){deleteSelectedButton.disabled=selected===0;deleteSelectedButton.toggleAttribute('disabled',selected===0);deleteSelectedButton.setAttribute('aria-disabled',String(selected===0));}if(selectAllFolders){selectAllFolders.checked=boxes.length>0&&selected===boxes.length;selectAllFolders.indeterminate=selected>0&&selected<boxes.length;selectAllFolders.disabled=boxes.length===0;}};
    // Delegated handling keeps selection state synchronized across filtering and pagination.
    selectAllFolders?.addEventListener('change',()=>{folderCheckboxes().forEach(box=>{box.checked=selectAllFolders.checked;});syncDeleteSelectedState();});
    document.addEventListener('change',event=>{if(event.target instanceof HTMLInputElement&&event.target.matches('#gallery-folder-select-all, input[name="selected_gallery_folders[]"]'))syncDeleteSelectedState();});
    syncDeleteSelectedState();
    // Use delegated handling so the confirmation still works if Gallery controls are refreshed.
    document.addEventListener('click',event=>{
      const trigger=event.target instanceof Element?event.target.closest('#gallery-delete-selected'):null;
      if(!trigger)return;
      event.preventDefault();
      event.stopPropagation();
      if(!folderCheckboxes().some(box=>box.checked))return;
      if(deleteModal){deleteModal.hidden=false;deleteModal.removeAttribute('hidden');deleteModal.style.display='flex';deleteModal.setAttribute('aria-hidden','false');}
    });
    const closeFolderDeleteModal=()=>{if(!deleteModal)return;deleteModal.hidden=true;deleteModal.style.removeProperty('display');deleteModal.setAttribute('aria-hidden','true');};
    document.getElementById('gallery-delete-cancel')?.addEventListener('click',closeFolderDeleteModal);
    document.getElementById('gallery-delete-confirm')?.addEventListener('click',()=>{if(folderCheckboxes().some(box=>box.checked)){closeFolderDeleteModal();if(bulkForm){if(typeof bulkForm.requestSubmit==='function')bulkForm.requestSubmit();else bulkForm.submit();}}});
    document.getElementById('gallery-delete-success-close')?.addEventListener('click',()=>window.location.replace('admin.php?section=media-management&media_tab=gallery'));
    document.getElementById('gallery-edit-success-close')?.addEventListener('click',()=>window.location.replace('admin.php?section=media-management&media_tab=gallery'));
    const photoSelectAll=document.getElementById('gallery-photo-select-all'),photoDeleteButton=document.getElementById('gallery-photo-delete-selected'),photoDeleteModal=document.getElementById('gallery-photo-delete-confirm-modal'),photoFolderInput=document.getElementById('gallery-return-folder');
    const photoChecks=()=>[...document.querySelectorAll('input[name="selected_gallery_photos[]"]')];
    // Always derive the selectable photos from the currently opened folder.
    // The former currentPhotoIds Set was never populated, so it filtered out
    // every checkbox and prevented the deletion flow from reaching PHP.
    const currentPhotoChecks=()=>{const folder=photoFolderInput?.value??'';return photoChecks().filter(box=>(box.closest('[data-gallery-folder-group]')?.dataset.galleryFolderName??'')===folder);};
    const syncPhotoDelete=()=>{const boxes=currentPhotoChecks(),selected=boxes.some(box=>box.checked);if(photoDeleteButton){photoDeleteButton.disabled=!selected;photoDeleteButton.toggleAttribute('disabled',!selected);photoDeleteButton.setAttribute('aria-disabled',String(!selected));}if(photoSelectAll){photoSelectAll.checked=boxes.length>0&&boxes.every(box=>box.checked);photoSelectAll.indeterminate=boxes.some(box=>box.checked)&&!photoSelectAll.checked;photoSelectAll.disabled=boxes.length===0;}};
    photoSelectAll?.addEventListener('change',()=>{currentPhotoChecks().forEach(box=>{box.checked=photoSelectAll.checked;});syncPhotoDelete();});
    document.addEventListener('change',event=>{if(event.target instanceof HTMLInputElement&&event.target.matches('input[name="selected_gallery_photos[]"]'))syncPhotoDelete();});syncPhotoDelete();
    document.getElementById('gallery-move-success-close')?.addEventListener('click',()=>{const moveModal=document.getElementById('gallery-move-success-modal');if(moveModal)moveModal.hidden=true;window.location.href='admin.php?section=media-management&media_tab=gallery';});
    const photoEditModal=document.getElementById('gallery-photo-edit-modal');
    document.querySelectorAll('[data-gallery-edit-photo]').forEach(button=>button.addEventListener('click',()=>{
      document.getElementById('gallery-photo-edit-id').value=button.dataset.galleryEditPhoto||'';
      document.getElementById('gallery-photo-edit-name').value=button.dataset.photoTitle||'';
      document.getElementById('gallery-photo-edit-description').value=button.dataset.photoDescription||'';
      document.getElementById('gallery-photo-edit-preview').src=button.dataset.photoSrc||'';
      photoEditModal.hidden=false;document.getElementById('gallery-photo-edit-name').focus();
    }));
    document.getElementById('gallery-photo-edit-cancel')?.addEventListener('click',()=>{if(photoEditModal)photoEditModal.hidden=true;});
    const editModal=document.getElementById('gallery-edit-modal'),editDetails=document.getElementById('gallery-edit-photo-details'),editForm=document.getElementById('gallery-edit-form');
    const editFolder=name=>{const group=groups.find(item=>item.dataset.galleryFolderName===name);if(!group||!editDetails)return;editForm.reset();editDetails.replaceChildren();document.getElementById('gallery-edit-original-folder').value=name;document.getElementById('gallery-edit-folder-name').value=name;
      [...group.querySelectorAll('.gallery-photo-card')].forEach(card=>{const id=card.dataset.photoId||'',img=card.querySelector('.gallery-photo-image img'),titleText=card.querySelector('.gallery-photo-title')?.textContent||'',description=card.dataset.photoDescription||'';const row=document.createElement('div');row.className='gallery-edit-photo-row';const previewWrap=document.createElement('div');previewWrap.className='gallery-edit-photo-preview-wrap';const preview=document.createElement('img');const originalPreview=img?.getAttribute('src')||'';preview.src=originalPreview;preview.alt='';const fields=document.createElement('div');fields.className='gallery-photo-detail-fields';const idInput=document.createElement('input');idInput.type='hidden';idInput.name='photo_id[]';idInput.value=id;const title=document.createElement('input');title.name='photo_title['+id+']';title.required=true;title.maxLength=180;title.value=titleText;title.placeholder='Photo title';const desc=document.createElement('textarea');desc.name='photo_description['+id+']';desc.maxLength=2000;desc.value=description;desc.placeholder='Photo description';const hint=document.createElement('small');hint.textContent='Replace photo (optional; JPG, PNG, WebP or AVIF, max 10 MB)';const replacement=document.createElement('input');replacement.type='file';replacement.name='replacement_photo['+id+']';replacement.accept='.jpg,.jpeg,.png,.webp,.avif,image/jpeg,image/png,image/webp,image/avif';const removeReplacement=document.createElement('button');removeReplacement.type='button';removeReplacement.className='gallery-edit-photo-remove';removeReplacement.textContent='Remove selected photo';removeReplacement.hidden=true;replacement.addEventListener('change',()=>{const file=replacement.files?.[0];if(file){preview.src=URL.createObjectURL(file);removeReplacement.hidden=false;}else{preview.src=originalPreview;removeReplacement.hidden=true;}});removeReplacement.addEventListener('click',()=>{replacement.value='';preview.src=originalPreview;removeReplacement.hidden=true;});previewWrap.append(preview,removeReplacement);fields.append(idInput,title,desc,hint,replacement);row.append(previewWrap,fields);editDetails.append(row);});
      // The Media Management entry cleanup marks every Gallery backdrop
      // display:none!important. Reparent this dialog to <body> and explicitly
      // override that stale inline rule whenever the user opens Edit Folder.
      if(editModal){
        if(editModal.parentElement!==document.body)document.body.appendChild(editModal);
        editModal.hidden=false;
        editModal.removeAttribute('hidden');
        editModal.removeAttribute('aria-hidden');
        editModal.style.setProperty('position','fixed','important');
        editModal.style.setProperty('inset','0','important');
        editModal.style.setProperty('display','flex','important');
        editModal.style.setProperty('align-items','center','important');
        editModal.style.setProperty('justify-content','center','important');
        editModal.style.setProperty('visibility','visible','important');
        editModal.style.setProperty('opacity','1','important');
        editModal.style.setProperty('z-index','2147482000','important');
        editModal.setAttribute('aria-hidden','false');
        document.body.classList.add('modal-open');
        editModal.getBoundingClientRect();
        document.getElementById('gallery-edit-folder-name')?.focus();
      }};
    // Delegated handling ensures the Edit action remains reliable if folder
    // cards are refreshed or re-rendered while Gallery is open.
    document.addEventListener('click',event=>{
      const trigger=event.target instanceof Element?event.target.closest('[data-gallery-edit-folder]'):null;
      if(!trigger)return;
      event.preventDefault();event.stopPropagation();
      editFolder(trigger.dataset.galleryEditFolder||'');
    });
    const closeEditFolderModal=()=>{
      if(!editModal)return;
      editModal.hidden=true;
      editModal.setAttribute('aria-hidden','true');
      editModal.style.setProperty('display','none','important');
      document.body.classList.remove('modal-open');
    };
    document.getElementById('gallery-edit-cancel')?.addEventListener('click',closeEditFolderModal);
    const editProgress=document.getElementById('gallery-edit-progress'),editProgressFill=document.getElementById('gallery-edit-progress-fill'),editProgressValue=document.getElementById('gallery-edit-progress-value'),editProgressLabel=document.getElementById('gallery-edit-progress-label'),editError=document.getElementById('gallery-edit-error'),editSave=document.getElementById('gallery-edit-save');
    let editSaving=false,editProgressFrame=0;
    const setEditProgress=value=>{const n=Math.max(0,Math.min(100,Math.round(value)));if(editProgressFill)editProgressFill.style.width=n+'%';if(editProgressValue)editProgressValue.textContent=n+'%';};
    const showEditSuccess=(message,folder)=>{
      const overlay=document.createElement('div');overlay.className='gallery-modal-backdrop';overlay.id='gallery-edit-ajax-success-modal';overlay.setAttribute('role','presentation');
      const panel=document.createElement('section');panel.className='gallery-modal gallery-upload-success-modal';panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');
      const mark=document.createElement('div');mark.className='gallery-upload-success-mark';mark.textContent='✓';
      const heading=document.createElement('h2');heading.textContent=message;
      const actions=document.createElement('div');actions.className='gallery-modal-actions';const ok=document.createElement('button');ok.type='button';ok.className='btn';ok.textContent='Continue';
      ok.addEventListener('click',()=>window.location.replace('admin.php?section=media-management&media_tab=gallery&gallery_focus_folder='+encodeURIComponent(folder)));
      actions.append(ok);panel.append(mark,heading,actions);overlay.append(panel);document.body.append(overlay);overlay.style.setProperty('display','flex','important');overlay.style.setProperty('position','fixed','important');overlay.style.setProperty('inset','0','important');overlay.style.setProperty('z-index','2147483000','important');overlay.getBoundingClientRect();ok.focus();
    };
    editForm?.addEventListener('submit',event=>{
      event.preventDefault();if(editSaving)return;
      if(!editForm.reportValidity())return;
      editSaving=true;if(editSave){editSave.disabled=true;editSave.textContent='Saving…';}
      if(editError){editError.hidden=true;editError.textContent='';}
      if(editProgress)editProgress.hidden=false;setEditProgress(0);if(editProgressLabel)editProgressLabel.textContent='Saving changes…';
      const started=performance.now();let serverDone=false,serverPayload=null,failed=false,completionStarted=0;
      const finishEditProgress=()=>{if(editProgressLabel)editProgressLabel.textContent='Update complete';requestAnimationFrame(()=>requestAnimationFrame(()=>setTimeout(()=>{closeEditFolderModal();showEditSuccess(serverPayload.message,serverPayload.folder);},500)));};
      const animate=now=>{
        const elapsed=now-started;
        if(failed){editSaving=false;if(editSave){editSave.disabled=false;editSave.textContent='Save Changes';}if(editProgress)editProgress.hidden=true;return;}
        if(!serverDone){setEditProgress(Math.min(95,(elapsed/4500)*95));editProgressFrame=requestAnimationFrame(animate);return;}
        if(elapsed<4500){setEditProgress(Math.min(95,(elapsed/4500)*95));editProgressFrame=requestAnimationFrame(animate);return;}
        if(!completionStarted)completionStarted=now;
        const completion=Math.min(1,(now-completionStarted)/650);
        // Animate the final 5% rather than jumping directly from 95% to 100%.
        setEditProgress(95+(5*(1-Math.pow(1-completion,3))));
        if(completion<1){editProgressFrame=requestAnimationFrame(animate);return;}
        finishEditProgress();
      };
      editProgressFrame=requestAnimationFrame(animate);
      const xhr=new XMLHttpRequest();xhr.open('POST',window.location.href,true);xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
      xhr.onload=()=>{serverDone=true;try{serverPayload=JSON.parse(xhr.responseText);if(xhr.status<200||xhr.status>=300||!serverPayload.success)throw new Error(serverPayload.message||'Unable to save the folder changes.');}
        catch(error){failed=true;if(editError){editError.textContent=error.message||'Unable to save the folder changes.';editError.hidden=false;}if(editProgressLabel)editProgressLabel.textContent='Update failed';}};
      xhr.onerror=()=>{serverDone=true;failed=true;if(editError){editError.textContent='A network error prevented the changes from being saved. Please try again.';editError.hidden=false;}if(editProgressLabel)editProgressLabel.textContent='Update failed';};
      xhr.send(new FormData(editForm));
    });
    [deleteModal,editModal,photoDeleteModal,photoEditModal].forEach(modal=>modal?.addEventListener('click',event=>{if(event.target===modal)event.preventDefault();}));
    document.addEventListener('keydown',event=>{if(event.key==='Escape'){if(deleteModal&&!deleteModal.hidden)deleteModal.hidden=true;if(editModal&&!editModal.hidden)closeEditFolderModal();if(photoDeleteModal&&!photoDeleteModal.hidden)photoDeleteModal.hidden=true;if(photoEditModal&&!photoEditModal.hidden)photoEditModal.hidden=true;}});
    const cards=[...document.querySelectorAll('.gallery-photo-card')],groups=[...document.querySelectorAll('[data-gallery-folder-group]')],folderTiles=[...document.querySelectorAll('[data-gallery-open-folder]')],folderView=document.getElementById('gallery-folder-view'),photoView=document.getElementById('gallery-photo-view'),folderTitle=document.getElementById('gallery-current-folder-title'),backFolders=document.getElementById('gallery-back-folders'),filter=document.getElementById('gallery-filter'),size=document.getElementById('gallery-page-size'),info=document.getElementById('gallery-page-info'),number=document.getElementById('gallery-page-number'),prev=document.getElementById('gallery-prev'),next=document.getElementById('gallery-next'),first=document.getElementById('gallery-first'),last=document.getElementById('gallery-last'),infoBottom=document.getElementById('gallery-page-info-bottom'),numberBottom=document.getElementById('gallery-page-number-bottom'),prevBottom=document.getElementById('gallery-prev-bottom'),nextBottom=document.getElementById('gallery-next-bottom'),firstBottom=document.getElementById('gallery-first-bottom'),lastBottom=document.getElementById('gallery-last-bottom'),empty=document.getElementById('gallery-no-results');
    let page=1,activeFolder=null,activeCards=[];
    const matches=()=>{const q=(filter?.value||'').trim().toLocaleLowerCase();return activeCards.filter(card=>(card.dataset.gallerySearch||'').includes(q));};
    const render=()=>{if(!size||!info)return;const found=matches(),perPage=Number(size.value),pages=Math.max(1,Math.ceil(found.length/perPage));page=Math.min(page,pages);const start=found.length?(page-1)*perPage:0,end=Math.min(start+perPage,found.length),visible=new Set(found.slice(start,end));activeCards.forEach(card=>card.hidden=!visible.has(card));groups.forEach(group=>{const belongs=group.dataset.galleryFolderName===activeFolder;group.hidden=!belongs;});const label=found.length?'Showing '+(start+1)+' to '+end+' of '+found.length+' photos':'Showing 0 photos';info.textContent=label;if(infoBottom)infoBottom.textContent=label;const renderNumbers=container=>{if(!container)return;container.replaceChildren();for(let n=1;n<=pages;n++){const button=document.createElement('button');button.type='button';button.className='btn light gallery-page-number'+(n===page?' is-active':'');button.textContent=String(n);button.setAttribute('aria-label','Go to page '+n);button.setAttribute('aria-current',n===page?'page':'false');button.addEventListener('click',()=>{page=n;render();});container.appendChild(button);}};renderNumbers(number);renderNumbers(numberBottom);[prev,prevBottom,first,firstBottom].forEach(button=>{if(button)button.disabled=page<=1;});[next,nextBottom,last,lastBottom].forEach(button=>{if(button)button.disabled=page>=pages;});if(empty){empty.textContent=activeCards.length===0?'This folder is empty. You can add photos using the + Add Photo button.':'No photos match your filter.';empty.hidden=found.length!==0;}};
    const openFolder=name=>{activeFolder=name;if(photoView)photoView.dataset.activeFolder=name;const returnFolder=document.getElementById('gallery-return-folder');if(returnFolder)returnFolder.value=name;const group=groups.find(item=>item.dataset.galleryFolderName===name);activeCards=group?[...group.querySelectorAll('.gallery-photo-card')]:[];currentPhotoIds=new Set(activeCards.map(card=>card.dataset.photoId||''));photoChecks().forEach(box=>{box.checked=false;});if(photoSelectAll)photoSelectAll.checked=false;syncPhotoDelete();if(folderTitle)folderTitle.textContent=name||'Unfiled Photos';if(folderView)folderView.hidden=true;if(photoView)photoView.hidden=false;if(filter)filter.value='';page=1;render();};
    folderTiles.forEach(tile=>tile.addEventListener('click',()=>openFolder(tile.dataset.galleryOpenFolder||'')));
    const requestedFolder=new URLSearchParams(window.location.search).get('gallery_folder') ?? <?=json_encode($galleryReturnFolder, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;if(requestedFolder!==null&&groups.some(group=>group.dataset.galleryFolderName===requestedFolder))openFolder(requestedFolder);
    backFolders?.addEventListener('click',()=>{if(photoView)photoView.hidden=true;if(folderView)folderView.hidden=false;activeFolder=null;activeCards=[];currentPhotoIds=new Set();photoChecks().forEach(box=>{box.checked=false;});if(photoSelectAll)photoSelectAll.checked=false;syncPhotoDelete();});
    filter?.addEventListener('input',()=>{page=1;render();});size?.addEventListener('change',()=>{page=1;render();});[first,firstBottom].forEach(button=>button?.addEventListener('click',()=>{page=1;render();}));[prev,prevBottom].forEach(button=>button?.addEventListener('click',()=>{if(page>1){page--;render();}}));[next,nextBottom].forEach(button=>button?.addEventListener('click',()=>{page++;render();}));[last,lastBottom].forEach(button=>button?.addEventListener('click',()=>{const pages=Math.max(1,Math.ceil(matches().length/Number(size?.value||12)));page=pages;render();}));
    const requestedGalleryModalIds=['gallery-add-modal','gallery-photo-delete-confirm-modal','gallery-photo-edit-modal'];
    const clearUploadPromptFlag=()=>{const cleanUrl=new URL(window.location.href);cleanUrl.searchParams.delete('gallery_upload_prompt');window.history.replaceState({},'',cleanUrl.toString());};
    const closeRequestedGalleryModal=id=>{const target=document.getElementById(id);if(!target)return; if(id==='gallery-add-modal'&&uploadInProgress)return; target.hidden=true;target.style.removeProperty('display');document.body.classList.remove('modal-open');if(id==='gallery-add-modal')clearUploadPromptFlag();};
    document.querySelectorAll('[data-gallery-modal-close]').forEach(button=>button.addEventListener('click',()=>closeRequestedGalleryModal(button.dataset.galleryModalClose)));
    document.addEventListener('keydown',event=>{if(event.key!=='Escape')return;const active=requestedGalleryModalIds.map(id=>document.getElementById(id)).find(modal=>modal&&!modal.hidden);if(active)closeRequestedGalleryModal(active.id);});
    const modal=document.getElementById('gallery-add-modal'),open=document.getElementById('gallery-add-photo'),closeButtons=[document.getElementById('gallery-modal-cancel')],mode=document.getElementById('gallery-folder-mode'),existing=document.getElementById('gallery-existing-folder-field'),fresh=document.getElementById('gallery-new-folder-field'),files=document.getElementById('gallery-files'),details=document.getElementById('gallery-photo-details'),form=document.getElementById('gallery-upload-form');
    const progress=document.getElementById('gallery-upload-progress'),progressFill=document.getElementById('gallery-upload-progress-fill'),progressValue=document.getElementById('gallery-upload-progress-value'),progressLabel=document.getElementById('gallery-upload-progress-label'),uploadError=document.getElementById('gallery-upload-error'),successModal=document.getElementById('gallery-upload-success-modal'),successMessage=document.getElementById('gallery-upload-success-message');
    let uploadInProgress=false,uploadedFolder='',uploadReturnFolder='';
    let lockedFolderInputs=[];
    const modeLabel=mode?.closest('.gallery-form-field'),existingLabel=document.getElementById('gallery-existing-folder')?.closest('.gallery-form-field');
    const resetFolderLock=()=>{
      lockedFolderInputs.forEach(input=>input.remove());lockedFolderInputs=[];
      if(mode){mode.disabled=false;modeLabel.hidden=false;modeLabel.firstChild.nodeValue='Save photos to';}
      const existingSelect=document.getElementById('gallery-existing-folder');
      if(existingSelect){existingSelect.disabled=false;existingLabel.firstChild.nodeValue='Choose existing folder';}
    };
    const lockUploadToFolder=folder=>{
      resetFolderLock();
      mode.value='existing';mode.disabled=true;modeLabel.hidden=true;
      const existingSelect=document.getElementById('gallery-existing-folder');
      existingSelect.value=folder;existingSelect.disabled=true;existing.hidden=false;fresh.hidden=true;
      existingSelect.required=false;document.getElementById('gallery-folder-name').required=false;
      existingLabel.firstChild.nodeValue='Photos will be saved in this folder';
      [['gallery_folder_mode','existing'],['gallery_existing_folder',folder]].forEach(([name,value])=>{
        const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.appendChild(input);lockedFolderInputs.push(input);
      });
    };
    const resetProgress=()=>{if(progress)progress.hidden=true;if(progressFill)progressFill.style.width='0%';if(progressValue)progressValue.textContent='0%';if(progressLabel)progressLabel.textContent='Uploading photos…';if(uploadError){uploadError.hidden=true;uploadError.textContent='';}};
    const setProgress=value=>{const percent=Math.max(0,Math.min(100,Math.round(value)));if(progressFill)progressFill.style.width=percent+'%';if(progressValue)progressValue.textContent=percent+'%';};
    const resetUploadForm=()=>{
      if(!form)return;
      resetFolderLock();
      form.reset();
      if(details)details.replaceChildren();
      if(existing&&fresh&&mode){const isNew=mode.value==='new',isExisting=mode.value==='existing';existing.hidden=!isExisting;fresh.hidden=!isNew;document.getElementById('gallery-existing-folder').required=isExisting;document.getElementById('gallery-folder-name').required=isNew;if(uploadReturnFolder)lockUploadToFolder(uploadReturnFolder);}
      const submit=document.getElementById('gallery-upload-submit');
      if(submit){submit.disabled=false;submit.textContent='Upload Photos';}
      resetProgress();uploadInProgress=false;
    };
    const close=()=>{if(uploadInProgress)return;if(modal)modal.hidden=true;resetUploadForm();clearUploadPromptFlag();};
    const showUploadModal=folder=>{uploadReturnFolder=typeof folder==='string'?folder:'';resetUploadForm();if(modal){modal.hidden=false;modal.removeAttribute('aria-hidden');modal.style.removeProperty('display');}document.body.classList.add('modal-open');if(uploadReturnFolder){lockUploadToFolder(uploadReturnFolder);}else{mode?.focus();}};
    open?.addEventListener('click',()=>showUploadModal(''));
    document.getElementById('gallery-add-photo-in-folder')?.addEventListener('click',()=>showUploadModal(document.getElementById('gallery-photo-view')?.dataset.activeFolder||''));
    // The final Media Management cleanup dispatches this event only after
    // stale overlays are cleared and the Gallery tab has been activated.
    document.addEventListener('gallery:open-upload-for-folder',event=>{
      const folder=typeof event.detail?.folder==='string'?event.detail.folder:'';
      if(folder)showUploadModal(folder);
    });
    closeButtons.forEach(button=>button?.addEventListener('click',close));
    mode?.addEventListener('change',()=>{const isNew=mode.value==='new',isExisting=mode.value==='existing';existing.hidden=!isExisting;fresh.hidden=!isNew;document.getElementById('gallery-existing-folder').required=isExisting;document.getElementById('gallery-folder-name').required=isNew;});
    files?.addEventListener('change',()=>{details.replaceChildren();const selected=[...(files.files||[])];if(selected.length>20){files.value='';details.innerHTML='<p class="muted">Please select no more than 20 photos.</p>';return;}const allowed=/\.(jpe?g|png|avif|webp)$/i;const invalid=selected.find(file=>!allowed.test(file.name));const oversized=selected.find(file=>file.size>10*1024*1024);if(invalid||oversized){files.value='';details.innerHTML='<p class="muted">'+(oversized?'Each photo must be 10 MB or smaller.':'Only JPEG, JPG, PNG, AVIF and WEBP files are allowed.')+'</p>';return;}selected.forEach(file=>{const row=document.createElement('div');row.className='gallery-photo-detail-row';const preview=document.createElement('img');preview.alt='';preview.src=URL.createObjectURL(file);const fields=document.createElement('div');fields.className='gallery-photo-detail-fields';const title=document.createElement('input');title.name='photo_title[]';title.required=true;title.maxLength=180;title.placeholder='Enter photo title';title.value=file.name.replace(/\.[^.]+$/,'');const description=document.createElement('textarea');description.name='photo_description[]';description.maxLength=2000;description.placeholder='Photo description (shown when hovering)';fields.append(title,description);row.append(preview,fields);details.append(row);});});
    form?.addEventListener('submit',event=>{
      event.preventDefault();
      if(uploadInProgress)return;
      if(!files.files.length||files.files.length>20){if(uploadError){uploadError.textContent='Choose between 1 and 20 photos.';uploadError.hidden=false;}return;}
      if(!['existing','new'].includes(mode.value)){if(uploadError){uploadError.textContent='Choose whether to upload to an existing folder or create a new folder.';uploadError.hidden=false;}return;}
      if(mode.value==='new'&&!document.getElementById('gallery-folder-name').value.trim()){if(uploadError){uploadError.textContent='Enter a Folder Name.';uploadError.hidden=false;}return;}
      if(mode.value==='existing'&&!document.getElementById('gallery-existing-folder').value){if(uploadError){uploadError.textContent='Choose an existing folder.';uploadError.hidden=false;}return;}
      const allowed=/\.(jpe?g|png|avif|webp)$/i;const selectedFiles=[...files.files];
      if(selectedFiles.some(file=>!allowed.test(file.name))){if(uploadError){uploadError.textContent='Only JPEG, JPG, PNG, AVIF and WEBP files are allowed.';uploadError.hidden=false;}return;}
      if(selectedFiles.some(file=>file.size>10*1024*1024)){if(uploadError){uploadError.textContent='Each photo must be 10 MB or smaller.';uploadError.hidden=false;}return;}
      const submit=document.getElementById('gallery-upload-submit');
      const xhr=new XMLHttpRequest();
      const data=new FormData(form);
      uploadInProgress=true;submit.disabled=true;submit.textContent='Uploading…';
      if(progress)progress.hidden=false;
      if(uploadError){uploadError.hidden=true;uploadError.textContent='';}
      setProgress(0);
      const uploadStartedAt=Date.now();
      const minimumProgressDuration=4500;
      let uploadResult=null;
      let uploadSucceeded=false;
      let completionStarted=false;
      const showUploadSuccess=()=>{
        if(completionStarted||!uploadSucceeded||Date.now()-uploadStartedAt<minimumProgressDuration)return;
        completionStarted=true;
        window.clearInterval(progressTimer);
        uploadedFolder=typeof uploadResult.folder==='string'?uploadResult.folder:'';
        // First render the completed bar. Keep the upload dialog and its 100%
        // indicator visible long enough for the user to see completion before
        // revealing the success message.
        setProgress(100);
        if(progressLabel)progressLabel.textContent='Upload complete';
        const revealSuccess=()=>{
          uploadInProgress=false;
          if(modal){modal.hidden=true;modal.setAttribute('aria-hidden','true');}
          // Do not call resetUploadForm() here: it would immediately return the
          // progress indicator to 0%, hiding the completed state.
          const count=Math.max(0,Number(uploadResult.count)||0);
          const destination=uploadedFolder||'the selected folder';
          const message=count+' '+(count===1?'photo has':'photos have')+' been successfully uploaded in folder '+destination+'.';
          if(successMessage)successMessage.textContent=message;
          if(successModal){
            // Move the confirmation directly under <body> so it can never be
            // clipped or hidden by the upload dialog or a tab-panel container.
            if(successModal.parentElement!==document.body)document.body.appendChild(successModal);
            successModal.hidden=false;
            successModal.removeAttribute('hidden');
            successModal.removeAttribute('aria-hidden');
            successModal.style.setProperty('position','fixed','important');
            successModal.style.setProperty('inset','0','important');
            successModal.style.setProperty('display','flex','important');
            successModal.style.setProperty('align-items','center','important');
            successModal.style.setProperty('justify-content','center','important');
            successModal.style.setProperty('visibility','visible','important');
            successModal.style.setProperty('opacity','1','important');
            successModal.style.setProperty('z-index','2147483000','important');
            successModal.setAttribute('aria-live','assertive');
            successModal.setAttribute('aria-hidden','false');
            document.body.classList.add('modal-open');
            // Force layout after removing the hidden state so the centered
            // success overlay is painted even after the upload dialog closes.
            successModal.getBoundingClientRect();
            requestAnimationFrame(()=>document.getElementById('gallery-upload-success-close')?.focus());
          }else{
            // Defensive fallback if the server-rendered confirmation element
            // is ever absent from the page markup.
            const fallback=document.createElement('div');
            fallback.id='gallery-upload-success-modal-fallback';
            fallback.setAttribute('role','alertdialog');
            fallback.setAttribute('aria-modal','true');
            fallback.style.cssText='position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.82);';
            const card=document.createElement('section');
            card.style.cssText='width:min(420px,calc(100vw - 40px));padding:28px;border:1px solid rgba(210,83,105,.6);border-radius:16px;background:#130e12;color:#f5e9ed;text-align:center;font-family:Ubuntu,sans-serif;box-shadow:0 28px 90px rgba(0,0,0,.72);';
            const heading=document.createElement('h2');heading.textContent='Upload successful';
            const body=document.createElement('p');body.textContent=message;
            const ok=document.createElement('button');ok.type='button';ok.textContent='OK';ok.className='btn';
            ok.addEventListener('click',()=>{fallback.remove();document.body.classList.remove('modal-open');const target=new URL('admin.php',window.location.href);target.searchParams.set('section','media-management');target.searchParams.set('media_tab','gallery');if(uploadReturnFolder){target.searchParams.set('gallery_folder',destination);target.searchParams.delete('gallery_focus_folder');}else{target.searchParams.set('gallery_focus_folder',destination);target.searchParams.delete('gallery_folder');}window.location.replace(target.toString());});
            card.append(heading,body,ok);fallback.append(card);document.body.append(fallback);ok.focus();
          }
        };
        // The progress fill has a 120ms CSS width transition. Two animation
        // frames plus this hold ensure 100% is painted before success is shown.
        window.requestAnimationFrame(()=>window.requestAnimationFrame(()=>window.setTimeout(revealSuccess,650)));
      };
    const progressTimer=window.setInterval(()=>{
        const elapsed=Date.now()-uploadStartedAt;
        // Animate steadily from 0 to 95%; reserve 100% for server-confirmed success.
        const animatedProgress=Math.min(95,(elapsed/minimumProgressDuration)*95);
        setProgress(animatedProgress);
        if(uploadSucceeded)showUploadSuccess();
      },50);
      xhr.open('POST',window.location.href,true);
      xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
      xhr.addEventListener('load',()=>{
        let result=null;try{result=JSON.parse(xhr.responseText);}catch(error){}
        if(!result){
          const bodyLimitMatch=(document.documentElement?.innerText||'').match(/The (?:uploaded file|POST content-length) exceeds the (?:upload_max_filesize|post_max_size) directive/i);
          result={success:false,message:bodyLimitMatch?'The selected photos exceed the PHP request-size limit. In Laragon php.ini, set upload_max_filesize = 10M and post_max_size = 220M, then restart Apache.':'The server did not return an upload response. This usually means the total upload exceeds PHP post_max_size or the web server rejected the request. In Laragon php.ini, set upload_max_filesize = 10M and post_max_size = 220M, then restart Apache.'};
        }
        if(xhr.status>=200&&xhr.status<300&&result?.success){
          uploadResult=result;
          uploadSucceeded=true;
          showUploadSuccess();
          return;
        }
        window.clearInterval(progressTimer);uploadInProgress=false;submit.disabled=false;submit.textContent='Upload Photos';
        if(uploadError){uploadError.textContent=result?.message||'The photos could not be uploaded. Please check the PHP upload limits and try again.';uploadError.hidden=false;}
      });
      xhr.addEventListener('error',()=>{
        window.clearInterval(progressTimer);uploadInProgress=false;submit.disabled=false;submit.textContent='Upload Photos';
        if(uploadError){uploadError.textContent='A network error interrupted the upload. Please try again.';uploadError.hidden=false;}
      });
      xhr.addEventListener('abort',()=>{
        window.clearInterval(progressTimer);uploadInProgress=false;submit.disabled=false;submit.textContent='Upload Photos';
        if(uploadError){uploadError.textContent='The upload was cancelled. Please try again.';uploadError.hidden=false;}
      });
      xhr.send(data);
    });
    document.getElementById('gallery-upload-success-close')?.addEventListener('click',()=>{
      if(successModal){successModal.hidden=true;successModal.style.setProperty('display','none','important');successModal.setAttribute('aria-hidden','true');}
      document.body.classList.remove('modal-open');
      const destinationUrl=new URL('admin.php',window.location.href);
      destinationUrl.searchParams.set('section','media-management');
      destinationUrl.searchParams.set('media_tab','gallery');
      destinationUrl.searchParams.delete('gallery_saved');
      destinationUrl.searchParams.delete('gallery_moved');
      destinationUrl.searchParams.delete('gallery_moved_folder');
      destinationUrl.searchParams.delete('gallery_upload_prompt');
      if(uploadReturnFolder){destinationUrl.searchParams.set('gallery_folder',uploadedFolder||uploadReturnFolder);destinationUrl.searchParams.delete('gallery_focus_folder');}
      else{destinationUrl.searchParams.delete('gallery_folder');if(uploadedFolder)destinationUrl.searchParams.set('gallery_focus_folder',uploadedFolder);}
      // A folder-context upload returns directly to that folder's photo grid.
      window.location.replace(destinationUrl.toString());
    });
    successModal?.addEventListener('click',event=>{if(event.target===successModal)event.preventDefault();});
    modal?.addEventListener('click',event=>{if(event.target===modal)event.preventDefault();});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal&&!modal.hidden)close();});
  })();
  </script>
  <script>
  // Keep per-photo editing independent from the Gallery folder-view handlers.
  (() => {
    const modal = document.getElementById('gallery-photo-edit-modal');
    const photoId = document.getElementById('gallery-photo-edit-id');
    const title = document.getElementById('gallery-photo-edit-name');
    const description = document.getElementById('gallery-photo-edit-description');
    const preview = document.getElementById('gallery-photo-edit-preview');
    const replacementInput = document.getElementById('gallery-photo-edit-replacement');
    const replacementWrap = document.getElementById('gallery-photo-edit-replacement-preview-wrap');
    const replacementPreview = document.getElementById('gallery-photo-edit-replacement-preview');
    const replacementRemove = document.getElementById('gallery-photo-edit-replacement-remove');
    let replacementObjectUrl = '';
    const clearReplacementPreview = () => {
      if (replacementObjectUrl) URL.revokeObjectURL(replacementObjectUrl);
      replacementObjectUrl = '';
      if (replacementInput) replacementInput.value = '';
      if (replacementPreview) replacementPreview.removeAttribute('src');
      if (replacementWrap) replacementWrap.hidden = true;
    };
    replacementInput?.addEventListener('change', () => {
      const file = replacementInput.files && replacementInput.files[0];
      if (!file) { clearReplacementPreview(); return; }
      if (replacementObjectUrl) URL.revokeObjectURL(replacementObjectUrl);
      replacementObjectUrl = URL.createObjectURL(file);
      if (replacementPreview) replacementPreview.src = replacementObjectUrl;
      if (replacementWrap) replacementWrap.hidden = false;
    });
    replacementRemove?.addEventListener('click', clearReplacementPreview);
    const close = () => {
      if (!modal) return;
      modal.hidden = true;
      modal.style.removeProperty('display');
      modal.setAttribute('aria-hidden', 'true');
      clearReplacementPreview();
      document.body.classList.remove('modal-open');
    };
    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('[data-gallery-edit-photo]') : null;
      if (!button) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!modal || !photoId || !title || !description || !preview) return;
      photoId.value = button.dataset.galleryEditPhoto || '';
      title.value = button.dataset.photoTitle || '';
      description.value = button.dataset.photoDescription || '';
      preview.src = button.dataset.photoSrc || '';
      clearReplacementPreview();
      modal.hidden = false;
      modal.removeAttribute('hidden');
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
      title.focus();
    }, true);
    document.getElementById('gallery-photo-edit-cancel')?.addEventListener('click', close);
    modal?.querySelector('[data-gallery-modal-close="gallery-photo-edit-modal"]')?.addEventListener('click', close);
    modal?.addEventListener('click', event => {
      if (event.target === modal) event.preventDefault();
    });
  })();
  </script>
  <script>
  // Animate replacement-photo progress smoothly; reserve the final 5% for server confirmation.
  (() => {
    const form = document.getElementById('gallery-photo-edit-form');
    const input = document.getElementById('gallery-photo-edit-replacement');
    const progress = document.getElementById('gallery-photo-edit-progress');
    const progressLabel = document.getElementById('gallery-photo-edit-progress-label');
    const progressValue = document.getElementById('gallery-photo-edit-progress-value');
    const progressFill = document.getElementById('gallery-photo-edit-progress-fill');
    const errorBox = document.getElementById('gallery-photo-edit-error');
    const saveButton = document.getElementById('gallery-photo-edit-save');
    if (!form || !input || !progress) return;

    let targetProgress = 0;
    let displayedProgress = 0;
    let animationFrame = 0;
    let redirectUrl = '';

    const renderProgress = value => {
      const percent = Math.max(0, Math.min(100, value));
      progressValue.textContent = Math.floor(percent) + '%';
      progressFill.style.width = percent + '%';
    };
    const animateProgress = () => {
      const difference = targetProgress - displayedProgress;
      if (Math.abs(difference) < 0.12) displayedProgress = targetProgress;
      else displayedProgress += difference * 0.12;
      renderProgress(displayedProgress);

      if (redirectUrl && displayedProgress >= 99.9) {
        displayedProgress = 100;
        renderProgress(100);
        progressLabel.textContent = 'Replacement photo saved.';
        window.location.assign(redirectUrl);
        return;
      }
      animationFrame = requestAnimationFrame(animateProgress);
    };
    const startAnimation = () => {
      if (!animationFrame) animationFrame = requestAnimationFrame(animateProgress);
    };
    const stopAnimation = () => {
      if (animationFrame) cancelAnimationFrame(animationFrame);
      animationFrame = 0;
    };
    const showFailure = message => {
      stopAnimation();
      errorBox.textContent = message;
      errorBox.hidden = false;
      progress.hidden = true;
      form.dataset.uploading = '0';
      saveButton.disabled = false;
    };

    form.addEventListener('submit', event => {
      const file = input.files && input.files[0];
      if (!file) return; // Keep the existing normal Save Changes workflow.
      event.preventDefault();
      if (form.dataset.uploading === '1') return;

      form.dataset.uploading = '1';
      errorBox.hidden = true;
      errorBox.textContent = '';
      progress.hidden = false;
      progressLabel.textContent = 'Uploading replacement photo…';
      targetProgress = 0;
      displayedProgress = 0;
      renderProgress(0);
      startAnimation();
      saveButton.disabled = true;

      const xhr = new XMLHttpRequest();
      xhr.open('POST', window.location.href, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', uploadEvent => {
        if (uploadEvent.lengthComputable) {
          // Keep the bar below 100% until PHP confirms that the file was saved.
          targetProgress = Math.min(95, (uploadEvent.loaded / uploadEvent.total) * 95);
        }
      });
      xhr.upload.addEventListener('load', () => {
        targetProgress = Math.max(targetProgress, 95);
        progressLabel.textContent = 'Saving replacement photo…';
      });
      xhr.addEventListener('load', () => {
        let response = null;
        try { response = JSON.parse(xhr.responseText); } catch (_) {}
        if (xhr.status >= 200 && xhr.status < 300 && response && response.success === true && response.redirect) {
          redirectUrl = response.redirect;
          targetProgress = 100;
          progressLabel.textContent = 'Finalizing replacement photo…';
          startAnimation();
          return;
        }
        showFailure(response && response.message
          ? response.message
          : 'The server returned an unexpected response (HTTP ' + xhr.status + '). Please check the PHP error log if the problem continues.');
      });
      xhr.addEventListener('error', () => {
        showFailure('A network error interrupted the replacement photo upload. Please try again.');
      });
      xhr.addEventListener('abort', () => {
        showFailure('The replacement photo upload was cancelled. Please try again.');
      });
      xhr.send(new FormData(form));
    });
  })();

  // Reliable Gallery photo deletion flow, delegated independently of the Gallery UI script.
  (() => {
    const folderInput = document.getElementById('gallery-return-folder');
    const bulkForm = document.getElementById('gallery-photo-bulk-form');
    const confirmModal = document.getElementById('gallery-photo-delete-confirm-modal');
    const successClose = document.getElementById('gallery-photo-delete-success-close');
    const selectedInFolder = () => {
      const folder = folderInput?.value ?? '';
      return [...document.querySelectorAll('input[name="selected_gallery_photos[]"]:checked')]
        .filter(box => (box.closest('[data-gallery-folder-group]')?.dataset.galleryFolderName ?? '') === folder);
    };
    document.addEventListener('click', event => {
      const trigger = event.target instanceof Element ? event.target.closest('#gallery-photo-delete-selected') : null;
      if (trigger) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const selected = selectedInFolder();
        if (!selected.length || !confirmModal) return;
        const titles = selected.map(box => box.closest('.gallery-photo-card')?.querySelector('.gallery-photo-title')?.dataset.photoTitle || 'Untitled photo');
        const message = document.getElementById('gallery-photo-delete-message');
        if (message) message.textContent = titles.length === 1
          ? 'Are you sure you want to delete the photo with the title (' + titles[0] + ') in this folder?'
          : 'Are you sure you want to delete the ' + titles.length + ' selected photos in this folder?';
        confirmModal.hidden = false;
        confirmModal.style.display = 'flex';
        confirmModal.setAttribute('aria-hidden', 'false');
        return;
      }
      const confirm = event.target instanceof Element ? event.target.closest('#gallery-photo-delete-confirm') : null;
      if (confirm) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!bulkForm || !selectedInFolder().length) return;
        confirm.disabled = true;
        confirm.textContent = 'Deleting…';
        // Native submit sends the checked photo IDs and active folder to PHP.
        bulkForm.submit();
        return;
      }
      const cancel = event.target instanceof Element ? event.target.closest('#gallery-photo-delete-cancel') : null;
      if (cancel && confirmModal) {
        event.preventDefault();
        event.stopImmediatePropagation();
        confirmModal.hidden = true;
        confirmModal.style.removeProperty('display');
        confirmModal.setAttribute('aria-hidden', 'true');
        return;
      }
      const ok = event.target instanceof Element ? event.target.closest('#gallery-photo-delete-success-close') : null;
      if (ok) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const folder = folderInput?.value ?? '';
        const url = new URL('admin.php', window.location.href);
        url.searchParams.set('section', 'media-management');
        url.searchParams.set('media_tab', 'gallery');
        url.searchParams.set('gallery_folder', folder);
        window.location.replace(url.toString());
      }
    }, true);
  })();
  </script>
  <script>
  // Create a Gallery folder independently of the photo-upload workflow.
  (() => {
    const modal = document.getElementById('gallery-add-folder-modal');
    const form = document.getElementById('gallery-add-folder-form');
    const name = document.getElementById('gallery-add-folder-name');
    const open = () => {
      if (!modal) return;
      form?.reset();
      modal.hidden = false;
      modal.style.removeProperty('display');
      document.body.classList.add('modal-open');
      name?.focus();
    };
    const close = () => {
      if (modal) modal.hidden = true;
      document.body.classList.remove('modal-open');
    };
    document.getElementById('gallery-add-folder')?.addEventListener('click', open);
    document.getElementById('gallery-add-folder-close')?.addEventListener('click', close);
    document.getElementById('gallery-add-folder-cancel')?.addEventListener('click', close);
    modal?.addEventListener('click', event => {
      if (event.target === modal) event.preventDefault();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && modal && !modal.hidden) close();
    });
  })();

  // The primary Gallery uploader handler owns opening, folder locking, and closing.
  // Avoid a second delegated handler that can reopen the same modal in an unlocked state.
  </script>
  <section class="page-management-tabpanel" id="media-panel-video" role="tabpanel" aria-labelledby="media-tab-video" hidden>
    <h2>Video</h2><p class="muted">Manage videos displayed on the public website.</p>
    <div class="admin-note">Video management controls can be added here. No existing website content has been changed.</div>
  </section>
  <script>
  (() => {
    const nav=document.querySelector('.page-management-tabs');
    if(!nav||nav.dataset.handlersReady==='1')return;
    nav.dataset.handlersReady='1';
    const tabs=[...nav.querySelectorAll('[data-media-tab]')];
    const activate=(tab,focus=false)=>{
      tabs.forEach(item=>{
        const selected=item===tab;
        item.setAttribute('aria-selected',String(selected));
        item.tabIndex=selected?0:-1;
        const panel=document.getElementById(item.getAttribute('aria-controls'));
        if(panel)panel.hidden=!selected;
      });
      if(focus)tab.focus();
    };
    const initial=nav.dataset.initialTab||'gallery';
    activate(tabs.find(tab=>tab.dataset.mediaTab===initial)||tabs[0]);
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>activate(tab));
      tab.addEventListener('keydown',event=>{
        let next=index;
        if(event.key==='ArrowRight')next=(index+1)%tabs.length;
        else if(event.key==='ArrowLeft')next=(index-1+tabs.length)%tabs.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=tabs.length-1;
        else return;
        event.preventDefault();activate(tabs[next],true);
      });
    });
  })();
  </script>
  </section>
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
      .header-link-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:8px;margin:8px 0}
      .rich-editor{display:block;width:100%;min-height:38px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:#120d10;color:#f4e9ec;font:13px Ubuntu,sans-serif;outline:none;box-sizing:border-box}.header-title-source{min-height:58px;resize:vertical;white-space:pre-wrap}.rich-editor:focus{border-color:#a83b52;box-shadow:0 0 0 2px rgba(168,59,82,.16)}.rich-tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.font-format-tools{display:flex;align-items:center;gap:5px}.font-format-tools .format-icon{width:32px;height:30px;border:1px solid var(--line);border-radius:6px;background:#171115;color:#f4e9ec;cursor:pointer;font:13px Ubuntu,sans-serif}.font-format-tools .format-icon[aria-pressed="true"]{background:#7f1d34;border-color:#bd536a;color:#fff;box-shadow:inset 0 0 0 1px #bd536a}.rich-style-controls{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;grid-column:1/-1}.rich-style-controls label{display:grid;gap:4px;font-size:10px;color:#cdbdc2}.rich-style-controls input,.rich-style-controls select{width:100%;min-width:0;padding:6px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px;font:11px Ubuntu,sans-serif}.rich-tools button{padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:#21151a;color:#f4e9ec;font-size:11px;cursor:pointer}.rich-tools input,.rich-tools select{max-width:110px;padding:4px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px}.typography-group{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;border:1px solid var(--line);border-radius:10px;padding:10px}.typography-group legend{font-size:12px;color:#f1c4ce;padding:0 5px}.typography-group label{display:grid;gap:5px;font-size:11px;color:#d9cbd0}.typography-group .field{min-width:0;padding:8px}.header-link-row{align-items:start}.header-hidden-fields,.header-typography-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.header-hidden-item{display:grid;gap:8px;min-width:0;color:#d9cbd0;font-size:12px}.header-hidden-fields label,.header-typography-fields label,.logo-crop-controls label{display:grid;gap:7px;font-size:12px;color:#d9cbd0}.logo-crop-controls{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}.logo-crop-controls input{width:100%;accent-color:#a52b45}
      .header-preview-column{position:relative;min-width:0;margin-bottom:24px}.header-preview-title{font-size:13px;font-weight:700;margin-bottom:10px}.header-preview-frame{overflow:hidden;border:1px solid rgba(184,50,63,.35);border-radius:13px;background:#100d10;box-shadow:0 12px 35px rgba(0,0,0,.22)}.header-preview-frame,.header-preview-frame *{pointer-events:none!important;user-select:none!important;cursor:default!important}.header-preview-topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:10px 22px;background:#09090b;color:#b6c0b8;border-bottom:1px solid rgba(255,255,255,.1);font:10px Ubuntu,sans-serif;letter-spacing:normal;text-transform:none}.header-preview-topbar span{max-width:48%;overflow-wrap:anywhere}.header-preview-topbar span:last-child{text-align:right}.header-preview-main{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:0 22px;min-height:82px;background:rgba(11,11,13,.92);border-bottom:1px solid rgba(255,255,255,.1);backdrop-filter:blur(18px)} .header-preview-brand{display:flex;align-items:center;gap:12px;color:#f7f2f3;text-decoration:none;min-width:0}.header-preview-brand img{display:block;flex:0 0 auto;width:200px;height:70px;max-width:100%;object-fit:contain;object-position:center;border-radius:0}.header-preview-brand span{display:none!important}.header-preview-main nav{display:flex;align-items:center;justify-content:flex-end;gap:19px;flex-wrap:wrap;font-size:12px;color:#c1cbc3}.header-preview-main nav a{font-size:12px;color:#c1cbc3;text-decoration:none;white-space:nowrap}.header-preview-main nav .preview-signin{color:#c1cbc3}.header-preview-main nav .preview-join{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 19px;border:1px solid #b8323f;border-radius:999px;background:#b8323f;color:#101611;font-size:12px;font-weight:750;letter-spacing:.02em;box-shadow:0 8px 24px rgba(184,50,63,.15)}.header-preview-help{margin:10px 0 0}.header-settings-actions{display:flex;justify-content:flex-end;margin-top:22px;padding-top:17px;border-top:1px solid var(--line)}
      @media(max-width:900px){.header-settings-grid{grid-template-columns:1fr}.header-preview-column{position:static}}
      @media(max-width:560px){.header-link-row{grid-template-columns:1fr}.header-hidden-fields,.header-typography-fields,.logo-crop-controls{grid-template-columns:1fr} .header-preview-main{align-items:flex-start;flex-direction:column}.header-hidden-copy{max-width:100%;overflow-wrap:anywhere}.header-preview-main nav{justify-content:flex-start}}
      .footer-settings-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:24px;align-items:start}
      .footer-preview-column{position:relative;top:auto;min-width:0;width:100%}.footer-preview-frame{overflow:hidden;border:1px solid var(--line);border-radius:12px;background:#0d090b;padding:24px 18px;color:#eee}
      .footer-preview-frame .footer-grid{display:grid;grid-template-columns:1.35fr 1fr 1fr;gap:22px;max-width:none;width:100%;padding:0}
      .footer-preview-frame .brand,.footer-preview-frame b{display:block;margin:0 0 12px;color:#f0dadd;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;text-decoration:none}
      .footer-preview-frame .brand span{color:#c33d59}.footer-preview-frame p{color:#b9aeb2;font-size:11px;line-height:1.6;margin:8px 0}
      .footer-preview-frame #preview-footer-explore-links,.footer-preview-frame #preview-footer-discover-links{display:grid;gap:8px}
      .footer-preview-frame a:not(.brand){display:block;color:#c9bbc0;text-decoration:none;font-size:11px;line-height:1.45}
      .footer-settings-fields{display:grid;gap:16px;min-width:0}.footer-column-editor{display:grid;gap:10px;padding:15px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.018)}
      .footer-column-editor h3{font:600 14px Ubuntu,sans-serif;margin:0;color:#f4e9ec}.footer-column-editor .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font:12px Ubuntu,sans-serif}
      .footer-column-editor textarea{resize:vertical}.footer-preview-frame .footer-preview-text-item{display:block;color:#c9bbc0;font-size:11px;line-height:1.45}.footer-field-invalid{border-color:#ff526b!important;box-shadow:0 0 0 2px rgba(255,82,107,.2)!important}.footer-link-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:7px;margin:7px 0}
      .footer-column-editor .header-field{font-size:12px}.footer-column-editor small{font-size:10px;color:var(--muted);font-weight:400}
      @media(max-width:1000px){.footer-settings-grid{grid-template-columns:1fr}.footer-preview-column{position:static}}
      @media(max-width:600px){.footer-preview-frame .footer-grid{grid-template-columns:1fr;gap:16px}.footer-link-row{grid-template-columns:1fr}.footer-preview-frame{padding:18px 14px}}
      @media(max-width:560px){.page-management-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.page-management-tabs button{width:100%}}
    </style>
    <section class="panel admin-panel">
      <div class="admin-panel-head"><div><h2>Page Management</h2><p class="muted">Manage the content and presentation of your public website.</p></div></div>
      <div class="page-management-tabs" role="tablist" aria-label="Page Management tabs" data-initial-tab="<?=e($activePageTab)?>">
        <button type="button" id="pm-tab-header" role="tab" aria-controls="pm-panel-header" aria-selected="true" tabindex="0" data-page-tab="header">Header</button>
        <button type="button" id="pm-tab-footer" role="tab" aria-controls="pm-panel-footer" aria-selected="false" tabindex="-1" data-page-tab="footer">Footer</button>
        <button type="button" id="pm-tab-contents" role="tab" aria-controls="pm-panel-contents" aria-selected="false" tabindex="-1" data-page-tab="contents">Contents</button>
        <button type="button" id="pm-tab-sections" role="tab" aria-controls="pm-panel-sections" aria-selected="false" tabindex="-1" data-page-tab="sections">Sections</button>
      </div>
      <script>
      (() => {
        const tabNav = document.querySelector('.page-management-tabs');
        if (!tabNav || tabNav.dataset.handlersReady === '1') return;
        tabNav.dataset.handlersReady = '1';
        const tabs = [...tabNav.querySelectorAll('[data-page-tab]')];
        const activate = (tab, moveFocus = false) => {
          tabs.forEach(item => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            const panel = document.getElementById(item.getAttribute('aria-controls'));
            if (panel) panel.hidden = !selected;
          });
          if (tab.dataset.pageTab === 'sections') {
            const sectionsPanel = document.getElementById('pm-panel-sections');
            if (sectionsPanel) {
              sectionsPanel.dispatchEvent(new CustomEvent('sectionspanel:shown'));
            }
          }
          if (moveFocus) tab.focus();
        };
        const initial = tabNav.dataset.initialTab || 'header';
        const activateInitialTab = () => activate(tabs.find(tab => tab.dataset.pageTab === initial) || tabs[0]);
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', activateInitialTab, { once: true });
        } else {
          activateInitialTab();
        }
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
      <section class="page-management-tabpanel" id="pm-panel-header" role="tabpanel" aria-labelledby="pm-tab-header">
        <form method="post" enctype="multipart/form-data" id="header-settings-form" class="header-settings-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="save_header_settings">
          <aside class="header-preview-column"><div class="header-preview-title">Header Preview</div><div class="header-preview-frame" inert aria-label="Non-interactive visual preview of the public website header"><div class="header-preview-topbar"><span id="preview-hidden-left"><?=e((string)($headerSettings['hidden_left'] ?? ''))?></span><span id="preview-hidden-right"><?=e((string)($headerSettings['hidden_right'] ?? ''))?></span></div><div class="header-preview-main"><a href="../index.php" class="header-preview-brand" id="preview-header-brand"><img id="preview-header-logo" src="<?=e((string)($headerSettings['logo_path'] ?? ''))?>" alt="" style="width:<?=(int)($headerSettings['logo_width'] ?? 200)?>px;height:<?=(int)($headerSettings['logo_height'] ?? 70)?>px;object-fit:contain;<?=empty($headerSettings['logo_path'])?'display:none;':''?>"><span id="preview-header-title" hidden></span></a><nav id="preview-header-links"><?php foreach($headerLinks as $headerLink): if (!is_array($headerLink) || trim((string)($headerLink['label'] ?? '')) === '') continue; ?><a class="custom-preview-link" href="<?=e(trim((string)($headerLink['url'] ?? '')) ?: '#')?>"><?=e((string)$headerLink['label'])?></a><?php endforeach; ?><a href="#" class="preview-signin">Sign in</a><a href="#" class="preview-join">Join the Community</a></nav></div></div><p class="header-preview-help">Preview updates as you edit. Sign in and Join the Community stay visible on the public header.</p></aside>
          <div class="header-settings-grid">
            <div class="header-settings-fields">
              <div class="header-field" hidden aria-hidden="true"><input type="hidden" data-rich-editor="title" value="<?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?>"><input type="hidden" name="header_title" maxlength="160" data-plain-value="title" value="<?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?>"><input type="hidden" name="header_title_html" data-rich-value="title"></div>
              <div class="header-field"><span>Header Logo</span><input class="field" type="file" name="header_logo" id="header-logo-input" accept="image/png,image/jpeg"><small>Allowed filetype: PNG, JPG, or JPEG. Maximum upload is 3MB.</small>
                <div class="logo-crop-controls"><label>Logo width: <output id="logo-width-value"><?=(int)($headerSettings['logo_width'] ?? 200)?> px</output><input type="range" name="logo_width" id="logo-width" min="50" max="200" step="5" value="<?=max(50,min(200,(int)($headerSettings['logo_width'] ?? 200)))?>"></label><label>Logo height: <output id="logo-height-value"><?=(int)($headerSettings['logo_height'] ?? 70)?> px</output><input type="range" name="logo_height" id="logo-height" min="30" max="120" step="5" value="<?=max(30,min(120,(int)($headerSettings['logo_height'] ?? 70)))?>"></label></div>
              </div>
              <div class="header-field"><span>Header Links</span><small>Add as many navigation links as needed. Each URL can point directly to a website page.</small>
                <div id="header-links-list">
                <?php foreach($headerLinks as $headerLink): ?><div class="header-link-row"><input class="field" type="text" name="header_link_url[]" maxlength="500" placeholder="Page URL (e.g. /about.php)" value="<?=e((string)($headerLink['url'] ?? ''))?>"><input class="field" type="text" name="header_link_label[]" maxlength="60" placeholder="Link title" aria-label="Link title" value="<?=e((string)($headerLink['label'] ?? ''))?>"><button type="button" class="btn light remove-header-link" aria-label="Remove link">Remove</button></div><?php endforeach; ?>
                </div><button type="button" class="btn light" id="add-header-link">+ Add Header Link</button>
              </div>
              <div class="header-field"><span>Hidden Texts</span><small>Left Text appears in the far-left corner and Right Text in the far-right corner of the header.</small><div class="header-hidden-fields"><div class="header-hidden-item"><span>Left text</span><div class="rich-editor" data-rich-editor="hidden_left" contenteditable="true" role="textbox" aria-label="Edit left hidden text" data-placeholder="Text on the left"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_left_html'] ?? '')) ?: e((string)($headerSettings['hidden_left'] ?? ''))?></div><input type="hidden" name="hidden_left" maxlength="180" data-plain-value="hidden_left" value="<?=e((string)($headerSettings['hidden_left'] ?? ''))?>"><input type="hidden" name="hidden_left_html" data-rich-value="hidden_left"></div><div class="header-hidden-item"><span>Right text</span><div class="rich-editor" data-rich-editor="hidden_right" contenteditable="true" role="textbox" aria-label="Edit right hidden text" data-placeholder="Text on the right"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_right_html'] ?? '')) ?: e((string)($headerSettings['hidden_right'] ?? ''))?></div><input type="hidden" name="hidden_right" maxlength="180" data-plain-value="hidden_right" value="<?=e((string)($headerSettings['hidden_right'] ?? ''))?>"><input type="hidden" name="hidden_right_html" data-rich-value="hidden_right"></div></div></div>
            </div>
            
          </div>
          <div class="header-settings-actions"><button type="submit" class="btn">Save Header Settings</button></div>
        </form>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-footer" role="tabpanel" aria-labelledby="pm-tab-footer" hidden>
        <form method="post" id="footer-settings-form" class="footer-settings-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="save_footer_settings">
          <div class="footer-settings-grid">
            <aside class="footer-preview-column">
              <div class="header-preview-title">Footer Preview</div>
              <div class="footer-preview-frame" inert aria-label="Non-interactive live preview of the public website footer">
                <div class="wrap footer-grid">
                  <div class="footer-preview-col"><b id="preview-footer-brand"><?=e($footerSettings['brand'])?></b><p id="preview-footer-description"><?=e($footerSettings['description'])?></p><p id="preview-footer-copyright"><?=e(str_replace('{year}',date('Y'),$footerSettings['copyright']))?></p><div id="preview-footer-brand-links"><?php foreach(($footerSettings['brand_links'] ?? []) as $link): ?><?php if(trim((string)($link['url'] ?? '')) !== ''): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php else: ?><span class="footer-preview-text-item"><?=e($link['label'])?></span><?php endif; ?><?php endforeach; ?></div></div>
                  <div class="footer-preview-col"><b id="preview-footer-explore-title"><?=e($footerSettings['explore_title'])?></b><div id="preview-footer-explore-links"><?php foreach($footerSettings['explore_links'] as $link): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php endforeach; ?></div></div>
                  <div class="footer-preview-col"><b id="preview-footer-discover-title"><?=e($footerSettings['discover_title'])?></b><div id="preview-footer-discover-links"><?php foreach($footerSettings['discover_links'] as $link): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php endforeach; ?></div></div>
                </div>
              </div>
              <p class="header-preview-help">Preview updates as you edit.</p>
            </aside>
            <div class="footer-settings-fields">
              <div class="footer-column-editor"><h3>Column 1 — Brand</h3>
                <label class="header-field">Brand title<input class="field" name="footer_brand" id="footer-brand" maxlength="100" value="<?=e($footerSettings['brand'])?>"></label>
                <label class="header-field">Description<textarea class="field" name="footer_description" id="footer-description" rows="3" maxlength="500"><?=e($footerSettings['description'])?></textarea></label>
                <label class="header-field">Copyright text<textarea class="field" name="footer_copyright" id="footer-copyright" rows="2" maxlength="300"><?=e($footerSettings['copyright'])?></textarea><small>Use {year} to insert the current year automatically.</small></label>
                <div class="footer-link-editor" id="footer-brand-editor"><?php foreach(($footerSettings['brand_links'] ?? []) as $link): ?><div class="footer-link-row"><input class="field" name="brand_label[]" maxlength="100" aria-label="Brand item title" placeholder="Item title" value="<?=e($link['label'])?>"><input class="field" name="brand_url[]" maxlength="500" aria-label="Brand item URL" placeholder="Page URL (optional)" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
              </div>
              <div class="footer-column-editor"><h3>Column 2 — Explore</h3>
                <label class="header-field">Column title<input class="field" name="footer_explore_title" id="footer-explore-title" maxlength="60" value="<?=e($footerSettings['explore_title'])?>"></label>
                <div class="footer-link-editor" id="footer-explore-editor"><?php foreach($footerSettings['explore_links'] as $link): ?><div class="footer-link-row"><input class="field" name="explore_label[]" maxlength="100" aria-label="Explore link title" placeholder="Link title" value="<?=e($link['label'])?>"><input class="field" name="explore_url[]" maxlength="500" aria-label="Explore link URL" placeholder="Page URL" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
                <button type="button" class="btn light add-footer-link" data-group="explore">+ Add Explore Link</button>
              </div>
              <div class="footer-column-editor"><h3>Column 3 — Discover</h3>
                <label class="header-field">Column title<input class="field" name="footer_discover_title" id="footer-discover-title" maxlength="60" value="<?=e($footerSettings['discover_title'])?>"></label>
                <div class="footer-link-editor" id="footer-discover-editor"><?php foreach($footerSettings['discover_links'] as $link): ?><div class="footer-link-row"><input class="field" name="discover_label[]" maxlength="100" aria-label="Discover link title" placeholder="Link title" value="<?=e($link['label'])?>"><input class="field" name="discover_url[]" maxlength="500" aria-label="Discover link URL" placeholder="Page URL" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
                <button type="button" class="btn light add-footer-link" data-group="discover">+ Add Discover Link</button>
              </div>
            </div>
          </div>
          <div class="header-settings-actions"><button type="submit" class="btn">Save Footer Settings</button></div>
        </form>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-contents" role="tabpanel" aria-labelledby="pm-tab-contents" hidden>
        <?php require __DIR__ . '/../app/content-manager-view.php'; ?>
      </section>
      
<?php if($message==='Sections settings successfully saved.'): ?><div class="panel" role="status" style="border-color:#3c8b65;color:#c8f2d9;margin-top:14px">✓ Sections settings successfully saved.</div><?php endif; ?>
      <section class="page-management-tabpanel" id="pm-panel-sections" role="tabpanel" aria-labelledby="pm-tab-sections" hidden>
 <style>
 .section-builder-tabs{position:relative;isolation:isolate;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;width:min(100%,650px);margin:18px 0 22px;padding:5px;border:1px solid #45313a;border-radius:999px;background:linear-gradient(135deg,#100c0f,#1a1117);box-shadow:inset 0 2px 8px #0009,0 8px 22px #0003;overflow:hidden}
 .section-tab-indicator{position:absolute;z-index:0;left:0;top:5px;bottom:5px;width:0;pointer-events:none;border:1px solid #ef3340;border-radius:999px;background:linear-gradient(135deg,#751624,#3c0b14);box-shadow:0 4px 14px #ef334033,inset 0 1px #ffffff24;transform:translate3d(var(--slider-x,5px),0,0);transition:transform .42s cubic-bezier(.2,.8,.2,1),width .42s cubic-bezier(.2,.8,.2,1),background .25s,border-color .25s,box-shadow .25s}
 .section-builder-tabs[data-active=slideshow] .section-tab-indicator{background:linear-gradient(135deg,#164d83,#102849);border-color:#20b9ff;box-shadow:0 4px 14px #20b9ff30,inset 0 1px #ffffff24}
 .section-builder-tabs[data-active=mega_menu] .section-tab-indicator{background:linear-gradient(135deg,#796800,#403700);border-color:#f5dc00;box-shadow:0 4px 14px #f5dc0030,inset 0 1px #ffffff24}
 .section-builder-tabs button{position:relative;z-index:1;min-width:0;border:1px solid transparent;background:transparent;color:#cbbdc3;border-radius:999px;padding:11px 14px;font:700 13px Ubuntu;letter-spacing:.02em;cursor:pointer;transition:color .2s,transform .2s}
 .section-builder-tabs button:hover{color:#fff}
 .section-builder-tabs button[aria-selected=true]{color:#fff;text-shadow:0 1px 8px #0008}
 .section-builder-tabs button:focus-visible{outline:2px solid #fff;outline-offset:2px}
 @media(max-width:520px){.section-builder-tabs{width:100%;gap:2px}.section-builder-tabs button{padding:10px 5px;font-size:11px}}
 .section-subpanel[hidden]{display:none}.section-preview{border:1px solid #55414a;background:#0b090b;border-radius:13px;padding:15px;margin-bottom:20px;overflow:hidden}
 .section-preview h3{font:28px Georgia,serif;margin:5px 0 9px}.section-preview .preview-kicker{font:700 9px Ubuntu;letter-spacing:.18em;color:#ff5268}
 .section-preview .preview-desc{font:12px Ubuntu;color:#d8cbd0}.section-preview-media{display:flex;gap:10px;overflow:auto;margin-top:10px}.section-preview-media img{width:180px;height:105px;object-fit:cover;border-radius:8px}
 .section-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.section-field{display:grid;gap:7px;font:600 12px Ubuntu;color:#f2e6e9}.section-field input,.section-field textarea,.section-field select{width:100%;box-sizing:border-box;background:#171115;border:1px solid var(--line);border-radius:8px;padding:10px;color:#f7edf0;font:13px Ubuntu}.section-field textarea{min-height:78px}
 .banner-editor-grid{grid-template-columns:repeat(4,minmax(0,1fr));align-items:stretch;gap:12px}
 .banner-editor-field{grid-column:span 2!important;min-width:0;align-content:start}
 .banner-editor-field:nth-child(3){grid-column:span 3!important}
 .banner-editor-field:nth-child(4){grid-column:span 1!important}
 .banner-rich-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:5px;padding:9px;border:1px solid rgba(190,126,145,.22);border-bottom:0;border-radius:8px 8px 0 0;background:linear-gradient(135deg,rgba(105,34,54,.25),rgba(22,15,19,.95))}
 .banner-rich-toolbar button,.banner-rich-toolbar select,.banner-rich-toolbar input[type=color]{height:30px;border:1px solid rgba(190,126,145,.28);border-radius:6px;background:#24171e;color:#f4e7eb;font:11px Ubuntu,sans-serif}
 .banner-rich-toolbar button{min-width:30px;padding:0 8px;cursor:pointer}.banner-rich-toolbar button:hover{background:#542637;border-color:#a34b65}
 .banner-rich-toolbar select{padding:0 7px;max-width:140px}.banner-rich-toolbar input[type=color]{width:34px;padding:3px;cursor:pointer}
 .banner-rich-editor{width:100%;min-width:0;min-height:116px;max-height:190px;overflow:auto;box-sizing:border-box;padding:10px 11px;border:1px solid var(--line);border-radius:0 0 8px 8px;background:#171115;color:#f7edf0;font:13px/1.55 Ubuntu,sans-serif;outline:none;overflow-wrap:anywhere}
 .banner-rich-editor img{max-width:100%;height:auto;border-radius:6px}.banner-rich-editor figure{margin:12px auto;text-align:center}.banner-rich-editor figcaption{color:#bba8b0;font-size:11px}
 .banner-rich-editor:focus{border-color:#b94a61;box-shadow:0 0 0 3px rgba(185,74,97,.16)}
 .banner-rich-editor:empty:before{content:attr(data-placeholder);color:#8f7c83;pointer-events:none}
 .banner-photo-field{min-height:100%;padding:0;align-content:start}
 .banner-photo-field input[type=file]{min-height:116px;padding:12px;border-style:dashed;display:flex;align-items:center}
 .banner-photo-field small{color:#bdaab1;font-size:10px;line-height:1.45;overflow-wrap:anywhere}
 @media(max-width:760px){.banner-editor-grid{grid-template-columns:1fr}.banner-editor-field,.banner-editor-field:nth-child(3),.banner-editor-field:nth-child(4){grid-column:1/-1!important}}



 .slideshow-rich-grid{grid-template-columns:repeat(2,minmax(0,1fr));align-items:start}
 .section-field-label{display:block;font:600 12px Ubuntu;color:#f2e6e9}
 .slideshow-rich-field:nth-child(1),.slideshow-rich-field:nth-child(2){grid-column:auto!important}.slideshow-rich-field:nth-child(3){grid-column:1/-1!important}
 .slideshow-photo-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:15px;margin-top:12px}.slideshow-photo-list-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:12px 0}.slideshow-photo-list-toolbar #slideshow-delete-selected:disabled,.slideshow-photo-list-toolbar #slideshow-delete-selected[aria-disabled="true"]{opacity:.42;filter:saturate(.35);cursor:not-allowed;pointer-events:none}.slideshow-saved-photo{position:relative;display:block;min-width:0;padding:0;border:0;border-radius:0;background:transparent;overflow:visible}.slideshow-saved-photo:hover,.slideshow-saved-photo:focus-within{z-index:12}.slideshow-saved-photo .gallery-photo-select{position:absolute;z-index:6;top:8px;left:8px;display:flex;align-items:center;gap:6px;padding:5px 8px;border:1px solid rgba(255,255,255,.25);border-radius:7px;background:rgba(15,10,13,.84);color:#fff;font:10px Ubuntu,sans-serif;cursor:pointer}.slideshow-saved-photo .gallery-photo-select input{width:auto;height:auto;margin:0;accent-color:#b8323f;cursor:pointer}.slideshow-saved-photo .gallery-photo-select:has(input:checked){border-color:rgba(210,103,126,.65);background:rgba(116,24,45,.92);box-shadow:0 3px 10px rgba(0,0,0,.3)}.slideshow-saved-photo .gallery-photo-image{position:relative;aspect-ratio:4/3;border:1px solid rgba(184,50,63,.25);border-radius:10px;overflow:visible;background:#100c0f}.slideshow-saved-photo .gallery-photo-image>img{display:block;width:100%;height:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px}.slideshow-saved-photo .gallery-photo-description{position:absolute;z-index:20;left:50%;bottom:calc(100% + 8px);width:min(250px,80vw);padding:10px 12px;border:1px solid rgba(210,103,126,.42);border-radius:9px;background:rgba(22,12,17,.97);box-shadow:0 12px 28px rgba(0,0,0,.4);color:#f5e9ed;font:11px/1.5 Ubuntu,sans-serif;opacity:0;visibility:hidden;transform:translate(-50%,5px);transition:.18s;pointer-events:none;white-space:normal;overflow-wrap:anywhere}.slideshow-saved-photo .gallery-photo-image:hover .gallery-photo-description,.slideshow-saved-photo .gallery-photo-image:focus-within .gallery-photo-description{opacity:1;visibility:visible;transform:translate(-50%,0)}.slideshow-saved-photo .gallery-photo-title{margin:8px 1px 3px;color:#f4e8eb;font:600 12px/1.4 Ubuntu,sans-serif;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.slideshow-saved-photo .gallery-photo-edit{align-self:flex-start;margin:7px 0 2px;padding:6px 12px;font-size:10px}.slideshow-photo-select{accent-color:#b8323f;width:14px;height:14px}.slideshow-photo-edit-modal{width:min(680px,calc(100vw - 32px));max-height:88vh;overflow:auto}.slideshow-photo-edit-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.slideshow-photo-edit-fields .wide{grid-column:1/-1}.slideshow-photo-edit-fields textarea{min-height:120px}.slideshow-photo-edit-actions{display:flex!important;justify-content:flex-end!important;align-items:center;gap:10px!important;margin-top:28px!important;padding-top:18px!important;border-top:1px solid rgba(255,255,255,.12)}
 .slideshow-saved-photo-content{display:grid;grid-template-columns:190px minmax(0,1fr);gap:15px;align-items:start}
 .slideshow-saved-photo-content>img{width:100%;height:125px;object-fit:cover;border-radius:8px}
 .slideshow-saved-photo-content h4{margin:6px 0}
 .slideshow-photo-preview-card{min-width:180px;display:grid;gap:4px}
 .slideshow-photo-preview-card img{width:180px;height:105px;object-fit:cover;border-radius:8px}
 .slideshow-upload-modal{width:60vw;max-width:60vw;max-height:92vh;overflow:auto;box-sizing:border-box}
 .slideshow-photo-details{display:grid;gap:14px;margin:14px 0}
 .slideshow-upload-photo-card{display:grid;grid-template-columns:78px minmax(0,1fr) minmax(0,1fr) minmax(0,1.2fr) auto;grid-template-rows:auto auto;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:10px;background:#130e12}
 .slideshow-upload-photo-card>img{grid-column:1;grid-row:1;width:78px!important;height:78px;max-height:78px!important;object-fit:cover;border-radius:8px;margin:0!important}
 .slideshow-upload-photo-card h4{grid-column:1;grid-row:2;margin:0;color:#fff;overflow-wrap:anywhere;font-size:9px}
 .slideshow-upload-photo-card>.slideshow-upload-delete-row{grid-column:5;grid-row:1;justify-self:end;align-self:center;white-space:nowrap;padding:8px 9px;font-size:10px}
 .slideshow-upload-photo-card>.section-field:nth-of-type(1){grid-column:2;grid-row:1}
 .slideshow-upload-photo-card>.section-field:nth-of-type(2){grid-column:3;grid-row:1}
 .slideshow-upload-photo-card>.section-field:nth-of-type(3){grid-column:4;grid-row:1}
 .slideshow-upload-photo-card>.section-field input{display:block;width:100%;min-width:0;box-sizing:border-box}
 .slideshow-upload-photo-card>.muted{grid-column:2/6;grid-row:2}
 @media(max-width:1100px){.slideshow-upload-modal{width:60vw;max-width:60vw}.slideshow-upload-photo-card{grid-template-columns:68px minmax(0,1fr) minmax(0,1fr) minmax(0,1.2fr) auto;gap:7px;padding:9px}.slideshow-upload-photo-card>img{width:68px!important;height:68px}.slideshow-upload-photo-card>.slideshow-upload-delete-row{padding:6px 7px;font-size:9px}}
 @media(max-width:700px){.slideshow-upload-photo-card{grid-template-columns:1fr}.slideshow-upload-photo-card>img,.slideshow-upload-photo-card>.section-field:nth-of-type(1),.slideshow-upload-photo-card>.section-field:nth-of-type(2),.slideshow-upload-photo-card>.section-field:nth-of-type(3),.slideshow-upload-photo-card h4,.slideshow-upload-photo-card>.slideshow-upload-delete-row,.slideshow-upload-photo-card>.muted{grid-column:1;grid-row:auto}.slideshow-upload-photo-card>img{width:100%!important;height:180px}.slideshow-rich-grid,.slideshow-photo-edit-fields{grid-template-columns:1fr}.slideshow-rich-field:nth-child(3){grid-column:auto!important}.slideshow-photo-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.slideshow-saved-photo .gallery-photo-title{font-size:11px}.slideshow-photo-edit-fields .wide{grid-column:auto}} .slideshow-description-toolbar{display:flex;gap:6px;margin-bottom:6px}
 .slideshow-description-toolbar button{border:1px solid var(--line);border-radius:5px;background:#24171e;color:#fff;padding:5px 10px;cursor:pointer}
 .slideshow-description-editor{height:38px;min-height:38px;max-height:38px;padding:8px 10px;line-height:20px;border:1px solid var(--line);border-radius:7px;background:#171115;color:#fff;font:inherit;resize:none;box-sizing:border-box}
 .slideshow-description-editor:focus{outline:2px solid #a94a61}
 @media(max-width:700px){.slideshow-rich-grid,.slideshow-upload-photo-card,.slideshow-photo-edit-fields{grid-template-columns:1fr}.slideshow-rich-field:nth-child(3){grid-column:auto!important}.slideshow-photo-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.slideshow-saved-photo .gallery-photo-title{font-size:11px}.slideshow-photo-edit-fields .wide{grid-column:auto}}

 .section-editor{min-height:110px;border:1px solid var(--line);border-radius:8px;background:#120d10;padding:10px;color:#f5e9ed;font:13px Ubuntu}.section-toolbar{display:flex;gap:5px;flex-wrap:wrap;margin:5px 0}.section-toolbar button{background:#2b1a21;border:1px solid #5b3a46;color:#fff;border-radius:5px;padding:5px 9px;cursor:pointer}
 .section-repeat-card{border:1px solid #46323a;background:#100c0f;border-radius:12px;padding:14px;margin:14px 0}.section-repeat-head{display:flex;justify-content:space-between;align-items:center;gap:8px}.section-remove{border:1px solid #9e3144;color:#ff9aaa;background:#281017;border-radius:7px;padding:6px 10px;cursor:pointer}

 .banner-preview-stage{padding:0;border-radius:13px;background:#101511;overflow:hidden}
 .banner-preview-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:clamp(18px,4vw,58px);align-items:center;position:relative;padding:clamp(24px,5vw,58px);min-height:340px;box-sizing:border-box}
 .banner-preview-copy{min-width:0}
 .banner-preview-stage .preview-kicker{font:850 10px/1.5 Ubuntu,sans-serif;letter-spacing:2.5px;text-transform:uppercase;color:#d8b477}
 .banner-preview-stage h3{font:500 clamp(30px,4.6vw,56px)/.99 Georgia,'Times New Roman',serif;letter-spacing:-1.8px;margin:19px 0 23px;max-width:720px;color:#f3eee7}
 .banner-preview-stage .preview-desc{font:13px/1.7 Ubuntu,sans-serif;color:#b0bcb4;max-width:560px}
 .banner-preview-stage .preview-desc p{margin:0 0 10px}
 .banner-preview-actions{margin-top:22px}
 .banner-preview-actions span{display:inline-flex;align-items:center;border:1px solid #d8b477;border-radius:999px;background:#d8b477;color:#101611;padding:12px 19px;font:750 12px Ubuntu,sans-serif}
 .banner-preview-note{display:flex;align-items:center;gap:8px;margin-top:18px;color:#a8b2aa;font:10px/1.5 Ubuntu,sans-serif}
 .banner-preview-note i{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:#d8b477}
 .banner-preview-art{min-height:300px;height:100%;align-self:stretch;position:relative;overflow:hidden;display:flex;align-items:flex-end;padding:22px;box-sizing:border-box;border:1px solid rgba(184,50,63,.28);border-radius:22px;background:radial-gradient(ellipse at 50% 45%,rgba(130,27,48,.36),transparent 52%),linear-gradient(145deg,#2a090f,#0d0d0f 72%);box-shadow:0 14px 36px rgba(0,0,0,.3)}
 .banner-preview-art:before{content:'';position:absolute;width:66%;height:76%;right:18%;top:9%;border-radius:48% 48% 13% 13%;transform:rotate(9deg);background:linear-gradient(145deg,#c72c3b,#6f101f 55%,#210b11);box-shadow:-100px 80px 0 -34px #4b101b,92px 115px 0 -28px #8b1728;opacity:.55;filter:saturate(.8)}
 .banner-preview-art img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}
 .banner-preview-stamp{position:absolute;z-index:1;right:18px;top:18px;padding:9px 11px;border:1px solid rgba(255,255,255,.4);border-radius:8px;color:#fff;text-align:center;font:800 10px/1.3 Ubuntu,sans-serif;letter-spacing:1.4px;background:rgba(12,8,10,.35)}
 .banner-preview-stamp span{font-size:8px;letter-spacing:2px}
 @media(max-width:760px){.banner-preview-grid{grid-template-columns:1fr;gap:22px;padding:24px}.banner-preview-art{min-height:240px}.banner-preview-stage h3{font-size:clamp(32px,9vw,48px)}}
 @media(max-width:760px){.section-form-grid{grid-template-columns:1fr}}
 </style>
 
 <nav class="section-builder-tabs" data-active="banner" aria-label="Homepage section editor" data-initial-tab="<?=e(in_array((string)($_GET['section_tab']??'banner'),['banner','slideshow','mega_menu'],true)?(string)($_GET['section_tab']??'banner'):'banner')?>">
  <span class="section-tab-indicator" aria-hidden="true"></span>
  <button type="button" data-section-tab="banner" aria-selected="true" role="tab">Banner</button><button type="button" data-section-tab="slideshow" aria-selected="false" role="tab">Slideshow</button><button type="button" data-section-tab="mega_menu" aria-selected="false" role="tab">Mega Menu</button>
 </nav>
 <form method="post" enctype="multipart/form-data" id="sections-settings-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_sections_settings"><input type="hidden" name="section_editor_tab" id="section-editor-tab" value="banner">
 <section class="section-subpanel" data-section-panel="banner">
  <div class="section-preview banner-preview-stage" id="banner-preview">
   <div class="banner-preview-grid">
    <div class="banner-preview-copy">
     <div class="preview-kicker" id="banner-preview-title"><?= (string)($sectionsSettings['banner']['main_title'] ?? '') ?></div>
     <h3 id="banner-preview-heading"><?= (string)($sectionsSettings['banner']['heading'] ?? '') ?></h3>
     <div class="preview-desc" id="banner-preview-description"><?= (string)($sectionsSettings['banner']['description'] ?? '') ?></div>
    </div>
    <div class="banner-preview-art">
     <img id="banner-preview-photo" src="<?=e(!empty($sectionsSettings['banner']['photo'])?ltrim((string)$sectionsSettings['banner']['photo'],'/'):'assets/images/Sunset%20Vinta%20by%20the%20Historic%20Fort.png')?>" alt="Banner photo preview">
    </div>
   </div>
  </div>
  <div class="section-form-grid banner-editor-grid">
   <?php foreach(['banner_main_title'=>'Main Title','banner_heading'=>'Heading','banner_description'=>'Description'] as $editorKey=>$editorLabel): $storageKey=$editorKey==='banner_main_title'?'main_title':($editorKey==='banner_heading'?'heading':'description'); $editorValue=(string)($sectionsSettings['banner'][$storageKey]??''); ?>
   <label class="section-field banner-editor-field"><?=e($editorLabel)?>
    <div class="banner-rich-toolbar content-manager-rich-toolbar" role="toolbar" aria-label="<?=e($editorLabel)?> formatting">
     <select data-banner-font-family aria-label="Font family"><option value="Ubuntu">Ubuntu</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Times New Roman">Times New Roman</option><option value="Trebuchet MS">Trebuchet MS</option></select>
     <select data-banner-font-size aria-label="Font size"><option value="">Font size</option><option value="8">8px</option><option value="12">12px</option><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option><option value="28">28px</option><option value="32">32px</option><option value="36">36px</option><option value="40">40px</option><option value="44">44px</option><option value="48">48px</option><option value="52">52px</option><option value="56">56px</option><option value="60">60px</option><option value="64">64px</option><option value="68">68px</option><option value="72">72px</option></select>
     <select data-banner-font-weight aria-label="Font weight"><option value="">Font weight</option><option value="400">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="bold" title="Bold"><strong>B</strong></button>
     <button type="button" data-banner-command="italic" title="Italic"><em>I</em></button>
     <button type="button" data-banner-command="underline" title="Underline"><u>U</u></button>
     <button type="button" data-banner-action="quotation" title="Quotation marks">“ ”</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="justifyLeft" title="Align left">≡</button>
     <button type="button" data-banner-command="justifyCenter" title="Align center">☰</button>
     <button type="button" data-banner-command="justifyRight" title="Align right">≡</button>
     <label title="Font color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" data-banner-color value="#f4e9ed" aria-label="Font color"></label>
     <button type="button" data-banner-command="undo" title="Undo">↶</button>
     <button type="button" data-banner-command="redo" title="Redo">↷</button>
    </div>
    <div class="banner-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-banner-text="<?=e($editorKey)?>" data-placeholder="Enter <?=e(strtolower($editorLabel))?>..."><?=$editorValue?></div>
    <input type="hidden" name="<?=e($editorKey)?>" data-banner-html="<?=e($editorKey)?>" value="<?=e($editorValue)?>">
   </label>
   <?php endforeach; ?>
   <label class="section-field banner-editor-field banner-photo-field">Upload / Replace Banner Photo
    <input type="file" name="banner_photo" accept="image/png,image/jpeg,image/webp,image/gif">
    <?php if(!empty($sectionsSettings['banner']['photo'])): ?><small>Current photo: <?=e(basename($sectionsSettings['banner']['photo']))?></small><?php else: ?><small>Choose a JPG, PNG, GIF or WebP image (max. 10 MB).</small><?php endif; ?>
   </label>
  </div>
 </section>
 <section class="section-subpanel" data-section-panel="slideshow" hidden>
  <div class="section-preview" id="slideshow-settings-preview">
   <div class="preview-kicker" id="slide-preview-title"><?=($sectionsSettings['slideshow']['main_title']??'')?></div>
   <h3 id="slide-preview-heading"><?=($sectionsSettings['slideshow']['heading']??'')?></h3>
   <div class="preview-desc" id="slide-preview-subtitle"><?=($sectionsSettings['slideshow']['subtitle']??'')?></div>
   <div class="section-preview-media" id="slide-preview-media"><?php foreach((array)$sectionsSettings['slideshow']['photos'] as $ph): if(!empty($ph['photo'])): ?><div class="slideshow-photo-preview-card"><img src="<?=e($ph['photo'])?>" alt="<?=e($ph['title']??'')?>"><strong><?=e($ph['title']??'Untitled photo')?></strong><span><?=e($ph['heading']??'')?></span></div><?php endif; endforeach; ?></div>
  </div>
  <div class="section-form-grid slideshow-rich-grid">
   <div class="section-field banner-editor-field slideshow-rich-field"><span class="section-field-label">Main Title</span>
    <div class="banner-rich-toolbar content-manager-rich-toolbar" role="toolbar" aria-label="Main Title formatting">
     <select data-banner-font-family aria-label="Font family"><option value="Ubuntu">Ubuntu</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Times New Roman">Times New Roman</option><option value="Trebuchet MS">Trebuchet MS</option></select>
     <select data-banner-font-size aria-label="Font size"><option value="">Font size</option><option value="8">8px</option><option value="12">12px</option><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option><option value="28">28px</option><option value="32">32px</option><option value="36">36px</option><option value="40">40px</option><option value="44">44px</option><option value="48">48px</option><option value="52">52px</option><option value="56">56px</option><option value="60">60px</option><option value="64">64px</option><option value="68">68px</option><option value="72">72px</option></select>
     <select data-banner-font-weight aria-label="Font weight"><option value="">Font weight</option><option value="400">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="bold" title="Bold"><strong>B</strong></button>
     <button type="button" data-banner-command="italic" title="Italic"><em>I</em></button>
     <button type="button" data-banner-command="underline" title="Underline"><u>U</u></button>
     <button type="button" data-banner-action="quotation" title="Quotation marks">“ ”</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="justifyLeft" title="Align left">≡</button>
     <button type="button" data-banner-command="justifyCenter" title="Align center">☰</button>
     <button type="button" data-banner-command="justifyRight" title="Align right">≡</button>
     <label title="Font color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" data-banner-color value="#f4e9ed" aria-label="Font color"></label>
     <button type="button" data-banner-command="undo" title="Undo">↶</button>
     <button type="button" data-banner-command="redo" title="Redo">↷</button>
    </div>
<div class="banner-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-banner-text="slide_main_title" data-placeholder="Enter main title..."><?=e((string)($sectionsSettings['slideshow']['main_title']??''))?></div>
<input type="hidden" name="slide_main_title" data-banner-html="slide_main_title" value="<?=e((string)($sectionsSettings['slideshow']['main_title']??''))?>">
</div>
   <div class="section-field banner-editor-field slideshow-rich-field"><span class="section-field-label">Heading</span>
    <div class="banner-rich-toolbar content-manager-rich-toolbar" role="toolbar" aria-label="Heading formatting">
     <select data-banner-font-family aria-label="Font family"><option value="Ubuntu">Ubuntu</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Times New Roman">Times New Roman</option><option value="Trebuchet MS">Trebuchet MS</option></select>
     <select data-banner-font-size aria-label="Font size"><option value="">Font size</option><option value="8">8px</option><option value="12">12px</option><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option><option value="28">28px</option><option value="32">32px</option><option value="36">36px</option><option value="40">40px</option><option value="44">44px</option><option value="48">48px</option><option value="52">52px</option><option value="56">56px</option><option value="60">60px</option><option value="64">64px</option><option value="68">68px</option><option value="72">72px</option></select>
     <select data-banner-font-weight aria-label="Font weight"><option value="">Font weight</option><option value="400">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="bold" title="Bold"><strong>B</strong></button>
     <button type="button" data-banner-command="italic" title="Italic"><em>I</em></button>
     <button type="button" data-banner-command="underline" title="Underline"><u>U</u></button>
     <button type="button" data-banner-action="quotation" title="Quotation marks">“ ”</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="justifyLeft" title="Align left">≡</button>
     <button type="button" data-banner-command="justifyCenter" title="Align center">☰</button>
     <button type="button" data-banner-command="justifyRight" title="Align right">≡</button>
     <label title="Font color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" data-banner-color value="#f4e9ed" aria-label="Font color"></label>
     <button type="button" data-banner-command="undo" title="Undo">↶</button>
     <button type="button" data-banner-command="redo" title="Redo">↷</button>
    </div>
<div class="banner-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-banner-text="slide_heading" data-placeholder="Enter heading..."><?=e((string)($sectionsSettings['slideshow']['heading']??''))?></div>
<input type="hidden" name="slide_heading" data-banner-html="slide_heading" value="<?=e((string)($sectionsSettings['slideshow']['heading']??''))?>">
</div>
   <div class="section-field banner-editor-field slideshow-rich-field"><span class="section-field-label">Subtitle</span>
    <div class="banner-rich-toolbar content-manager-rich-toolbar" role="toolbar" aria-label="Subtitle formatting">
     <select data-banner-font-family aria-label="Font family"><option value="Ubuntu">Ubuntu</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Times New Roman">Times New Roman</option><option value="Trebuchet MS">Trebuchet MS</option></select>
     <select data-banner-font-size aria-label="Font size"><option value="">Font size</option><option value="8">8px</option><option value="12">12px</option><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option><option value="28">28px</option><option value="32">32px</option><option value="36">36px</option><option value="40">40px</option><option value="44">44px</option><option value="48">48px</option><option value="52">52px</option><option value="56">56px</option><option value="60">60px</option><option value="64">64px</option><option value="68">68px</option><option value="72">72px</option></select>
     <select data-banner-font-weight aria-label="Font weight"><option value="">Font weight</option><option value="400">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="bold" title="Bold"><strong>B</strong></button>
     <button type="button" data-banner-command="italic" title="Italic"><em>I</em></button>
     <button type="button" data-banner-command="underline" title="Underline"><u>U</u></button>
     <button type="button" data-banner-action="quotation" title="Quotation marks">“ ”</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="justifyLeft" title="Align left">≡</button>
     <button type="button" data-banner-command="justifyCenter" title="Align center">☰</button>
     <button type="button" data-banner-command="justifyRight" title="Align right">≡</button>
     <label title="Font color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" data-banner-color value="#f4e9ed" aria-label="Font color"></label>
     <button type="button" data-banner-command="undo" title="Undo">↶</button>
     <button type="button" data-banner-command="redo" title="Redo">↷</button>
    </div>
<div class="banner-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-banner-text="slide_subtitle" data-placeholder="Enter subtitle..."><?=e((string)($sectionsSettings['slideshow']['subtitle']??''))?></div>
<input type="hidden" name="slide_subtitle" data-banner-html="slide_subtitle" value="<?=e((string)($sectionsSettings['slideshow']['subtitle']??''))?>">
</div>
  </div>
  <div class="section-repeat-head"><div><h3>Slideshow Photos</h3><p class="muted">Each photo appears as a thumbnail and title. Edit photos individually or select several to delete.</p></div><button type="button" class="btn light" id="slideshow-upload-photos">+ Upload Photos</button></div>
  <div class="slideshow-photo-list-toolbar gallery-photo-bulk-form"><label class="muted"><input type="checkbox" id="slideshow-select-all" class="slideshow-photo-select"> Select all photos</label><button type="button" class="btn bulk-delete" id="slideshow-delete-selected" disabled aria-disabled="true">Delete Selected</button></div>
  <div class="slideshow-photo-list" id="slideshow-photo-list">
   <?php foreach((array)$sectionsSettings['slideshow']['photos'] as $k=>$ph): ?>
    <article class="gallery-photo-card slideshow-saved-photo" data-photo-index="<?=($k)?>">
     <label class="gallery-photo-select"><input type="checkbox" class="slideshow-photo-checkbox" aria-label="Select photo <?=($k+1)?>"> Select</label>
     <div class="gallery-photo-image"><?php if(!empty($ph['photo'])): ?><img src="<?=e($ph['photo'])?>" alt="<?=e($ph['title']??'Slideshow photo')?>" loading="lazy"><?php else: ?><div class="gallery-empty">No image</div><?php endif; ?><div class="gallery-photo-description"><?=e(trim(strip_tags((string)($ph['description']??'')))!==''?trim(strip_tags((string)$ph['description'])):'No description added.')?></div></div>
     <div class="gallery-photo-title" title="<?=e($ph['title']??'Untitled photo')?>"><?=e($ph['title']??'Untitled photo')?></div>
     <button type="button" class="btn light gallery-photo-edit slideshow-edit-photo">Edit</button>
    </article>
   <?php endforeach; ?>
   <?php if(empty($sectionsSettings['slideshow']['photos'])): ?><p class="muted slideshow-empty">No slideshow photos have been uploaded yet.</p><?php endif; ?>
  </div>
 </section>
 <div class="account-status-modal slideshow-delete-overlay" id="slideshow-photo-delete-confirm-modal" hidden><div class="account-status-modal-card slideshow-delete-card" role="dialog" aria-modal="true" aria-labelledby="slideshow-photo-delete-title"><div class="account-status-modal-icon slideshow-delete-warning" aria-hidden="true">!</div><h2 id="slideshow-photo-delete-title">Delete Selected Photo/s?</h2><p class="slideshow-delete-message">Are you sure you want to delete the selected photo/s?</p><div class="slideshow-delete-actions"><button class="btn light" type="button" id="slideshow-photo-delete-cancel">Cancel</button><button class="btn bulk-delete" type="button" id="slideshow-photo-delete-confirm">Delete</button></div></div></div>
 <div class="account-status-modal slideshow-delete-overlay" id="slideshow-photo-delete-success-modal" hidden><div class="account-status-modal-card slideshow-delete-card" role="dialog" aria-modal="true" aria-labelledby="slideshow-photo-delete-success-title"><div class="account-status-modal-icon" aria-hidden="true">✓</div><h2 id="slideshow-photo-delete-success-title">Selected photo/s deleted successfully.</h2><button type="button" class="btn" id="slideshow-photo-delete-success-close">OK</button></div></div>
 <section class="section-subpanel" data-section-panel="mega_menu" hidden>
  <div class="section-preview"><div class="preview-kicker" id="mega-preview-title"><?=e($sectionsSettings['mega_menu']['main_title'])?></div><h3 id="mega-preview-heading"><?=e($sectionsSettings['mega_menu']['heading'])?></h3><p class="preview-desc" id="mega-preview-subtitle"><?=e($sectionsSettings['mega_menu']['subtitle'])?></p><div class="section-preview-media"><?php foreach($sectionsSettings['mega_menu']['panels'] as $pn): if(!empty($pn['photo'])): ?><img src="<?=e($pn['photo'])?>" alt=""><?php endif; endforeach; ?></div></div>
  <div class="section-form-grid"><label class="section-field">Main Title<input name="mega_main_title" value="<?=e($sectionsSettings['mega_menu']['main_title'])?>" data-preview="mega-preview-title"></label><label class="section-field">Heading<input name="mega_heading" value="<?=e($sectionsSettings['mega_menu']['heading'])?>" data-preview="mega-preview-heading"></label><label class="section-field" style="grid-column:1/-1">Subtitle<input name="mega_subtitle" value="<?=e($sectionsSettings['mega_menu']['subtitle'])?>" data-preview="mega-preview-subtitle"></label></div>
  <div class="section-repeat-head"><h3>Panel View Menu</h3><button type="button" class="btn light" id="add-mega-panel">+ Add Panel</button></div><div id="mega-panel-list"><?php foreach($sectionsSettings['mega_menu']['panels'] as $pn): ?><div class="section-repeat-card"><div class="section-repeat-head"><strong>Menu Panel</strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Panel Title<input name="panel_title[]" value="<?=e($pn['title']??'')?>"></label><label class="section-field">Panel Description<textarea name="panel_description[]"><?=e($pn['description']??'')?></textarea></label><label class="section-field">Panel Page URL<input name="panel_url[]" value="<?=e($pn['url']??'')?>"></label><label class="section-field">Upload Panel Cover Photo<input type="file" name="panel_photo[]" accept="image/*"><?php if(!empty($pn['photo'])): ?><small>Current photo: <?=e(basename($pn['photo']))?></small><?php endif; ?></label></div></div><?php endforeach; ?></div>
 </section>
 <div class="header-settings-actions"><button type="submit" class="btn" id="save-section-settings-button">Save Banner Settings</button></div>
 </form>
 <div class="gallery-modal-backdrop slideshow-upload-backdrop" id="slideshow-upload-modal" hidden>
  <section class="gallery-modal slideshow-upload-modal" role="dialog" aria-modal="true" aria-labelledby="slideshow-upload-modal-title">
   <div class="gallery-modal-head"><div><h2 id="slideshow-upload-modal-title">Upload Photos</h2><p class="muted">Select up to 20 photos. Add a Photo Title, Photo Heading and Description for each photo.</p></div></div>
   <form method="post" enctype="multipart/form-data" id="slideshow-upload-form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label class="gallery-form-field slideshow-multiple-file-field">Select photos
     <input type="file" name="slideshow_photos[]" id="slideshow-photo-files" accept=".jpg,.jpeg,.png,.avif,.webp,image/jpeg,image/png,image/avif,image/webp" multiple required>
     <small>JPG, JPEG, PNG, AVIF or WEBP · maximum 10 MB per photo · up to 20 photos per upload</small>
    </label>
    <div id="slideshow-photo-details" class="slideshow-photo-details"></div>
    <div class="gallery-modal-actions"><button class="btn light" type="button" id="slideshow-upload-cancel">Cancel</button><button class="btn" type="submit">Upload Photos</button></div>
   </form>
  </section>
 </div>

<div class="gallery-modal-backdrop" id="slideshow-photo-edit-modal" hidden><section class="gallery-modal slideshow-photo-edit-modal" role="dialog" aria-modal="true" aria-labelledby="slideshow-photo-edit-title"><div class="gallery-modal-head"><div><h2 id="slideshow-photo-edit-title">Edit Slideshow Photo</h2><p class="muted">Update this photo's details or replace its image.</p></div></div><form id="slideshow-photo-edit-form" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="photo_index" id="slideshow-edit-index"><div class="slideshow-photo-edit-fields"><label class="section-field">Photo Title<input name="photo_title" id="slideshow-edit-title" maxlength="180" required></label><label class="section-field">Photo Heading<input name="photo_heading" id="slideshow-edit-heading" maxlength="180"></label><label class="section-field wide">Description<textarea name="photo_description" id="slideshow-edit-description"></textarea></label><label class="section-field wide">Replace Photo (optional)<input type="file" name="replacement_photo" accept=".jpg,.jpeg,.png,.avif,.webp,image/jpeg,image/png,image/avif,image/webp"><small>JPG, JPEG, PNG, AVIF or WEBP · maximum 10 MB</small></label></div><div class="gallery-modal-actions slideshow-photo-edit-actions"><button type="button" class="btn light" id="slideshow-edit-cancel">Cancel</button><button type="submit" class="btn">Save Changes</button></div></form></section></div>
 <script>
 // Keep the upload dialog at the document root and apply its full overlay
 // presentation inline so it remains centered even if admin theme CSS is overridden.
 (()=>{
   const modal=document.getElementById('slideshow-upload-modal');
   const fileInput=document.getElementById('slideshow-photo-files');
   if(!modal)return;
   if(modal.parentElement!==document.body)document.body.appendChild(modal);
   const close=()=>{
     modal.hidden=true;
     modal.style.setProperty('display','none','important');
     document.body.classList.remove('modal-open');
   };
   const open=()=>{
     modal.hidden=false;
     Object.assign(modal.style,{
       position:'fixed',top:'0',right:'0',bottom:'0',left:'0',
       width:'100vw',height:'100vh',boxSizing:'border-box',
       zIndex:'2147483000',display:'flex',alignItems:'center',
       justifyContent:'center',padding:'20px',
       background:'rgba(0,0,0,.78)',backdropFilter:'blur(7px)',
       overflowY:'auto'
     });
     modal.style.setProperty('display','flex','important');
     const card=modal.querySelector('.slideshow-upload-modal');
     if(card)Object.assign(card.style,{
       display:'block',position:'relative',width:'60vw',
       maxWidth:'60vw',maxHeight:'92vh',overflowY:'auto',
       boxSizing:'border-box',padding:'22px',
       border:'1px solid rgba(184,50,63,.55)',borderRadius:'15px',
       background:'#130e12',color:'#f5e9ed',
       boxShadow:'0 24px 70px rgba(0,0,0,.65)'
     });
     document.body.classList.add('modal-open');
     window.requestAnimationFrame(()=>fileInput?.focus());
   };
   close();
   document.addEventListener('click',event=>{
     const target=event.target;
     if(!(target instanceof Element))return;
     if(target.closest('#slideshow-upload-photos')){event.preventDefault();open();return;}
     if(target.closest('#slideshow-upload-cancel')){event.preventDefault();close();return;}
   },true);
   document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
 })();
 </script>
 <script>
 
 (()=>{const root=document.getElementById('pm-panel-sections');if(!root)return;const tabs=[...root.querySelectorAll('[data-section-tab]')],panels=[...root.querySelectorAll('[data-section-panel]')],tabNav=root.querySelector('.section-builder-tabs');const saveTabField=document.getElementById('section-editor-tab'),saveTabButton=document.getElementById('save-section-settings-button');const moveSectionIndicator=(t,animate=true)=>{if(!tabNav||!t||!t.getClientRects().length)return;const indicator=tabNav.querySelector('.section-tab-indicator');if(!indicator)return;const tabWidth=t.offsetWidth,tabLeft=t.offsetLeft-tabNav.clientLeft;if(!tabWidth)return;if(!animate){indicator.style.transition='none';}indicator.style.width=tabWidth+'px';indicator.style.setProperty('--slider-x',tabLeft+'px');if(!animate){indicator.offsetHeight;indicator.style.transition='';}};const scheduleSectionIndicator=(animate=false)=>{requestAnimationFrame(()=>requestAnimationFrame(()=>{const active=tabs.find(x=>x.getAttribute('aria-selected')==='true')||tabs[0];if(active)moveSectionIndicator(active,animate);}));};root.addEventListener('sectionspanel:shown',()=>scheduleSectionIndicator(false));const activateSectionTab=(t)=>{if(!t)return;const index=tabs.indexOf(t);if(tabNav){tabNav.dataset.active=t.dataset.sectionTab;}moveSectionIndicator(t);if(saveTabField)saveTabField.value=t.dataset.sectionTab;if(saveTabButton)saveTabButton.textContent=t.dataset.sectionTab==='banner'?'Save Banner Settings':t.dataset.sectionTab==='slideshow'?'Save Slideshow Settings':'Save Section Settings';tabs.forEach(x=>{const on=x===t;x.setAttribute('aria-selected',String(on));x.tabIndex=on?0:-1;});panels.forEach(p=>{p.hidden=p.dataset.sectionPanel!==t.dataset.sectionTab;});};tabs.forEach((t,index)=>{t.addEventListener('click',()=>activateSectionTab(t));t.addEventListener('keydown',e=>{if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight')return;e.preventDefault();const next=tabs[(index+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];activateSectionTab(next);next.focus();});});const requestedSectionTab=tabNav?.dataset.initialTab;const initialSectionTab=tabs.find(t=>t.dataset.sectionTab===requestedSectionTab)||tabs.find(t=>t.getAttribute('aria-selected')==='true')||tabs[0];activateSectionTab(initialSectionTab);scheduleSectionIndicator(false);if(requestedSectionTab==='slideshow'&&window.location.hash==='#slideshow-photo-list'){const focusSlideshowPhotos=()=>requestAnimationFrame(()=>requestAnimationFrame(()=>{const list=document.getElementById('slideshow-photo-list');if(list)list.scrollIntoView({behavior:'auto',block:'start'});}));if(document.readyState==='complete')focusSlideshowPhotos();else window.addEventListener('load',focusSlideshowPhotos,{once:true});}window.addEventListener('resize',()=>scheduleSectionIndicator(false));
 root.querySelectorAll('[data-preview]').forEach(i=>i.addEventListener('input',()=>{const el=document.getElementById(i.dataset.preview);if(el)el.textContent=i.value}));
 const bannerPreviewIds={banner_main_title:'banner-preview-title',banner_heading:'banner-preview-heading',banner_description:'banner-preview-description',slide_main_title:'slide-preview-title',slide_heading:'slide-preview-heading',slide_subtitle:'slide-preview-subtitle'};
 const cleanBannerPreviewHtml=html=>{
   const parsed=new DOMParser().parseFromString('<div>'+html+'</div>','text/html');
   const rootNode=parsed.body.firstElementChild;
   const allowed=new Set(['P','BR','STRONG','B','EM','I','U','S','UL','OL','LI','H2','H3','H4','BLOCKQUOTE','A','SPAN','FIGURE','IMG','FIGCAPTION','TABLE','TBODY','TR','TH','TD']);
   const walk=node=>{
     [...node.childNodes].forEach(child=>{
       if(child.nodeType===Node.ELEMENT_NODE){
         if(!allowed.has(child.tagName)){child.replaceWith(...child.childNodes);return;}
         [...child.attributes].forEach(attr=>{
           if(attr.name.toLowerCase()==='style'){
             const declarations=[];
             attr.value.split(';').forEach(rule=>{
               const parts=rule.split(':');if(parts.length<2)return;
               const key=parts.shift().trim().toLowerCase(),value=parts.join(':').trim();
               if(key==='color'&&/^(#[0-9a-f]{3,8}|rgba?\([0-9.,% ]+\)|[a-z]{3,20})$/i.test(value))declarations.push('color:'+value);
               else if(key==='font-size'&&/^(?:[8-9]|[1-6][0-9]|7[0-2])(?:\.\d+)?px$/i.test(value))declarations.push('font-size:'+value);
               else if(key==='font-family'&&/^[a-z0-9 ,'-]{1,80}$/i.test(value))declarations.push('font-family:'+value);
               else if(key==='font-weight'&&/^(normal|bold|400|500|600|700|800|900)$/i.test(value))declarations.push('font-weight:'+value);
               else if(key==='font-style'&&/^(normal|italic|oblique)$/i.test(value))declarations.push('font-style:'+value);
               else if(key==='text-align'&&/^(left|right|center|justify)$/i.test(value))declarations.push('text-align:'+value);
               else if(key==='text-decoration'&&/^(none|underline|line-through|overline)(\s+(underline|line-through|overline))*$/i.test(value))declarations.push('text-decoration:'+value);
               else if(key==='line-height'&&/^(?:[0-9]+(?:\.\d+)?|[0-9]+(?:\.\d+)?px|[0-9]+(?:\.\d+)?%)$/i.test(value))declarations.push('line-height:'+value);
               else if(key==='letter-spacing'&&/^-?[0-9]+(?:\.\d+)?px$/i.test(value))declarations.push('letter-spacing:'+value);
             });
             if(declarations.length)child.setAttribute('style',declarations.join(';'));else child.removeAttribute('style');
           }else if(child.tagName==='A'&&attr.name.toLowerCase()==='href'&&/^(https?:\/\/|\/|#|\?|mailto:)/i.test(attr.value)){}
           else child.removeAttribute(attr.name);
         });
         if(child.tagName==='A'){child.setAttribute('target','_blank');child.setAttribute('rel','noopener');}
         if(child.tagName==='IMG'){
           const src=child.getAttribute('src')||'';
           if(!/^(?:\/)?uploads\/articles\//i.test(src)){child.remove();return;}
           [...child.attributes].forEach(attr=>{if(!['src','alt'].includes(attr.name.toLowerCase()))child.removeAttribute(attr.name);});
         }
         walk(child);
       }else if(child.nodeType!==Node.TEXT_NODE){child.remove();}
     });
   };
   walk(rootNode);return rootNode.innerHTML;
 };
 const refreshBannerPreview=key=>{
   const field=root.querySelector('[data-banner-text="'+key+'"]');
   const hidden=root.querySelector('[data-banner-html="'+key+'"]');
   const preview=document.getElementById(bannerPreviewIds[key]);
   if(!field||!hidden||!preview)return;
   const html=cleanBannerPreviewHtml(field.innerHTML);
   hidden.value=html;
   preview.innerHTML=html;
 };
 const bannerEditors=[...root.querySelectorAll('[data-banner-text]')];
 const bannerSelection=new Map();
 // Keep an editor-specific HTML history because Range-based formatting and DOM insertion
 // do not reliably enter the browser's native contenteditable undo stack.
 const bannerHistory=new Map();
 const bannerHistoryTimers=new Map();
 const commitBannerHistory=field=>{
   const key=field.dataset.bannerText;
   const history=bannerHistory.get(key);
   if(!history)return;
   const timer=bannerHistoryTimers.get(key);
   if(timer){clearTimeout(timer);bannerHistoryTimers.delete(key);}
   const html=field.innerHTML;
   if(history.entries[history.index]===html)return;
   history.entries=history.entries.slice(0,history.index+1);
   history.entries.push(html);
   history.index=history.entries.length-1;
 };
 const scheduleBannerHistory=field=>{
   const key=field.dataset.bannerText;
   const oldTimer=bannerHistoryTimers.get(key);
   if(oldTimer)clearTimeout(oldTimer);
   bannerHistoryTimers.set(key,setTimeout(()=>commitBannerHistory(field),350));
 };
 const moveBannerHistory=(field,direction)=>{
   const key=field.dataset.bannerText;
   commitBannerHistory(field);
   const history=bannerHistory.get(key);
   if(!history)return;
   const next=history.index+direction;
   if(next<0||next>=history.entries.length)return;
   history.index=next;
   field.innerHTML=history.entries[history.index];
   bannerSelection.delete(key);
   field.focus({preventScroll:true});
   refreshBannerPreview(key);
 };
 const rememberBannerSelection=field=>{
   const selection=window.getSelection();
   if(selection&&selection.rangeCount&&!selection.isCollapsed&&field.contains(selection.anchorNode)&&field.contains(selection.focusNode)){
     bannerSelection.set(field.dataset.bannerText,selection.getRangeAt(0).cloneRange());
   }
 };
 const restoreBannerSelection=field=>{
   if(!field)return null;
   const range=bannerSelection.get(field.dataset.bannerText);
   field.focus({preventScroll:true});
   if(range&&field.contains(range.commonAncestorContainer)){
     const selection=window.getSelection();
     selection.removeAllRanges();
     selection.addRange(range.cloneRange());
     return selection.getRangeAt(0);
   }
   return null;
 };
 document.addEventListener('selectionchange',()=>{
   const selection=window.getSelection();
   const active=bannerEditors.find(field=>selection&&field.contains(selection.anchorNode)&&field.contains(selection.focusNode));
   if(active)rememberBannerSelection(active);
 });
 root.querySelectorAll('.banner-rich-toolbar').forEach(toolbar=>{
   toolbar.addEventListener('pointerdown',event=>{
     const field=toolbar.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
     if(field)rememberBannerSelection(field);
     // Keep the browser's selection in the editable surface when toolbar buttons are pressed.
     if(event.target.closest('button'))event.preventDefault();
   },true);
   toolbar.addEventListener('mousedown',event=>{
     if(event.target.closest('button'))event.preventDefault();
   },true);
 });
 bannerEditors.forEach(field=>{
   const key=field.dataset.bannerText;
   const hidden=root.querySelector('[data-banner-html="'+key+'"]');
   if(hidden&&hidden.value)field.innerHTML=hidden.value;
   bannerHistory.set(key,{entries:[field.innerHTML],index:0});
   field.addEventListener('input',()=>{refreshBannerPreview(key);scheduleBannerHistory(field);});
   ['keyup','mouseup','touchend'].forEach(name=>field.addEventListener(name,()=>rememberBannerSelection(field)));
   field.addEventListener('blur',()=>refreshBannerPreview(key));
   refreshBannerPreview(key);
 });
 const bannerCsrf=root.querySelector('input[name="csrf"]')?.value||'';
 const placeBannerFragment=(field,fragment)=>{
   const range=restoreBannerSelection(field)||document.createRange();
   if(!bannerSelection.has(field.dataset.bannerText)){
     range.selectNodeContents(field);
     range.collapse(false);
   }
   range.deleteContents();
   const last=fragment.lastChild;
   range.insertNode(fragment);
   if(last){
     const selection=window.getSelection(),caret=document.createRange();
     caret.setStartAfter(last);caret.collapse(true);selection.removeAllRanges();selection.addRange(caret);
   }
   field.dispatchEvent(new Event('input',{bubbles:true}));
   const selection=window.getSelection();
   if(selection&&selection.rangeCount&&!selection.isCollapsed)rememberBannerSelection(field);
   else bannerSelection.delete(field.dataset.bannerText);
 };
 const wrapBannerSelection=(field,tag,styles={})=>{
   const range=restoreBannerSelection(field);
   if(!range||range.collapsed)return false;
   const wrapper=document.createElement(tag);
   Object.entries(styles).forEach(([property,value])=>wrapper.style[property]=value);
   try{range.surroundContents(wrapper);}
   catch(error){wrapper.append(range.extractContents());range.insertNode(wrapper);}
   const selection=window.getSelection(),next=document.createRange();
   next.selectNodeContents(wrapper);selection.removeAllRanges();selection.addRange(next);
   rememberBannerSelection(field);
   field.dispatchEvent(new Event('input',{bubbles:true}));
   return true;
 };
 const bannerRunCommand=(field,command,value=null)=>{
   if(!field)return;
   const range=restoreBannerSelection(field);
   if(['bold','italic','underline','strikeThrough'].includes(command)){
     const tags={bold:'strong',italic:'em',underline:'u',strikeThrough:'s'};
     if(range&&!range.collapsed)wrapBannerSelection(field,tags[command]);
     return;
   }
   if(command==='foreColor'){wrapBannerSelection(field,'span',{color:value});return;}
   if(command==='formatBlock'){
     if(!range)return;
     const tag=String(value||'P').replace(/[<>]/g,'').toLowerCase();
     if(!/^(p|h2|h3|h4|blockquote)$/.test(tag))return;
     const block=document.createElement(tag);
     if(range.collapsed){block.innerHTML='<br>';range.insertNode(block);}
     else{try{range.surroundContents(block);}catch(error){block.append(range.extractContents());range.insertNode(block);}}
     field.dispatchEvent(new Event('input',{bubbles:true}));
     const selection=window.getSelection(),next=document.createRange();next.selectNodeContents(block);selection.removeAllRanges();selection.addRange(next);rememberBannerSelection(field);return;
   }
   if(['justifyLeft','justifyCenter','justifyRight'].includes(command)){
     if(!range)return;
     let node=range.startContainer.nodeType===Node.ELEMENT_NODE?range.startContainer:range.startContainer.parentElement;
     while(node&&node!==field&&!/^(P|H2|H3|H4|BLOCKQUOTE|LI|DIV)$/.test(node.tagName))node=node.parentElement;
     if(node&&node!==field)node.style.textAlign=command==='justifyLeft'?'left':command==='justifyCenter'?'center':'right';
     else field.style.textAlign=command==='justifyLeft'?'left':command==='justifyCenter'?'center':'right';
     field.dispatchEvent(new Event('input',{bubbles:true}));return;
   }
   if(command==='insertHTML'){
     const fragment=document.createRange().createContextualFragment(String(value||''));
     placeBannerFragment(field,fragment);return;
   }
   if(command==='createLink'){
     if(range&&!range.collapsed){const anchor=document.createElement('a');anchor.href=String(value||'');try{range.surroundContents(anchor);}catch(error){anchor.append(range.extractContents());range.insertNode(anchor);}field.dispatchEvent(new Event('input',{bubbles:true}));rememberBannerSelection(field);}return;
   }
   if(command==='unlink'){
     const selection=window.getSelection(),anchor=selection?.anchorNode?.parentElement?.closest('a');
     if(anchor&&field.contains(anchor)){anchor.replaceWith(...anchor.childNodes);field.dispatchEvent(new Event('input',{bubbles:true}));}return;
   }
   if(command==='insertUnorderedList'||command==='insertOrderedList'){
     if(!range)return;
     const list=document.createElement(command==='insertUnorderedList'?'ul':'ol');
     const item=document.createElement('li');
     if(range.collapsed)item.innerHTML='<br>';else item.append(range.extractContents());
     list.append(item);range.insertNode(list);field.dispatchEvent(new Event('input',{bubbles:true}));
     const selection=window.getSelection(),next=document.createRange();next.selectNodeContents(item);selection.removeAllRanges();selection.addRange(next);rememberBannerSelection(field);return;
   }
   if(command==='removeFormat'){
     if(!range||range.collapsed)return;
     const fragment=range.extractContents();
     fragment.querySelectorAll?.('[style]').forEach(node=>node.removeAttribute('style'));
     fragment.querySelectorAll?.('strong,b,em,i,u,s,span,font').forEach(node=>node.replaceWith(...node.childNodes));
     range.insertNode(fragment);field.dispatchEvent(new Event('input',{bubbles:true}));return;
   }
   // Use the editor's own snapshot history for text and formatting changes.
   if(command==='undo'){moveBannerHistory(field,-1);return;}
   if(command==='redo'){moveBannerHistory(field,1);return;}
   field.dispatchEvent(new Event('input',{bubbles:true}));
 };
 root.querySelectorAll('[data-banner-command]').forEach(button=>{
   button.addEventListener('click',()=>{
     const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
     if(field)bannerRunCommand(field,button.dataset.bannerCommand);
   });
 });
 root.querySelectorAll('[data-banner-block]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
   if(field&&select.value)bannerRunCommand(field,'formatBlock','<'+select.value+'>');
 }));
 const applyBannerInlineStyle=(field,property,value)=>wrapBannerSelection(field,'span',{[property]:value});
 root.querySelectorAll('[data-banner-font-family]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontFamily',select.value);select.value='';
 }));
 root.querySelectorAll('[data-banner-font-size]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontSize',select.value+'px');select.value='';
 }));
 root.querySelectorAll('[data-banner-font-weight]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontWeight',select.value);select.value='';
 }));
 root.querySelectorAll('[data-banner-color]').forEach(input=>input.addEventListener('input',()=>{
   const field=input.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)bannerRunCommand(field,'foreColor',input.value);
 }));
 root.querySelectorAll('[data-banner-action="link"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');const url=prompt('Enter the link URL (https://, http://, or mailto:):');
   if(field&&url&&/^(https?:\/\/|mailto:)/i.test(url.trim()))bannerRunCommand(field,'createLink',url.trim());
 }));
 root.querySelectorAll('[data-banner-action="unlink"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)bannerRunCommand(field,'unlink');
 }));
 root.querySelectorAll('[data-banner-action="quotation"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(!field)return;
   const range=restoreBannerSelection(field);if(!range)return;
   const opening=document.createTextNode('“'),closing=document.createTextNode('”');
   if(range.collapsed){range.insertNode(closing);range.insertNode(opening);range.setStartAfter(opening);range.collapse(true);}
   else{const selected=range.extractContents();range.insertNode(closing);range.insertNode(selected);range.insertNode(opening);range.setStartAfter(closing);range.collapse(true);}
   const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);
   field.dispatchEvent(new Event('input',{bubbles:true}));rememberBannerSelection(field);
 }));
 root.querySelectorAll('[data-banner-action="table"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(!field)return;
   const rows=Math.min(10,Math.max(1,Number(prompt('Number of table rows?','3')||0))),cols=Math.min(8,Math.max(1,Number(prompt('Number of table columns?','3')||0)));if(!rows||!cols)return;
   let table='<table><tbody>';for(let row=0;row<rows;row++){table+='<tr>';for(let col=0;col<cols;col++)table+=row===0?'<th>Heading</th>':'<td>Text</td>';table+='</tr>';}table+='</tbody></table><p><br></p>';bannerRunCommand(field,'insertHTML',table);
 }));
 const uploadBannerEditorImage=async(field,file)=>{
   if(!file||!file.type.startsWith('image/'))return;if(file.size>8*1024*1024){alert('Please choose an image smaller than 8 MB.');return;}
   const data=new FormData();data.append('csrf',bannerCsrf);data.append('action','upload_article_image');data.append('image',file);
   try{const response=await fetch('admin-content.php?session_context=admin',{method:'POST',body:data,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.url)throw new Error(result.message||'The image could not be uploaded.');
     const alt=(file.name||'Banner image').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
     bannerRunCommand(field,'insertHTML','<figure><img src="'+result.url+'" alt="'+alt+'"><figcaption>'+alt+'</figcaption></figure><p><br></p>');
   }catch(error){alert(error.message||'Image upload failed.');}
 };
 root.querySelectorAll('[data-banner-action="image"]').forEach(button=>button.addEventListener('click',()=>button.closest('.banner-editor-field')?.querySelector('[data-banner-image-input]')?.click()));
 root.querySelectorAll('[data-banner-image-input]').forEach(input=>input.addEventListener('change',async()=>{const field=input.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(input.files?.[0]&&field)await uploadBannerEditorImage(field,input.files[0]);input.value='';}));
 const sectionsForm=document.getElementById('sections-settings-form');
 sectionsForm?.addEventListener('submit',()=>{
   // Commit every rich editor to its hidden form field before native form serialization.
   root.querySelectorAll('[data-banner-text]').forEach(field=>refreshBannerPreview(field.dataset.bannerText));
   if(saveTabField){const selected=tabs.find(tab=>tab.getAttribute('aria-selected')==='true');if(selected)saveTabField.value=selected.dataset.sectionTab;}
 });
 const bannerPhotoInput=root.querySelector('input[name="banner_photo"]');bannerPhotoInput?.addEventListener('change',()=>{const file=bannerPhotoInput.files?.[0],preview=document.getElementById('banner-preview-photo');if(!file||!preview)return;const reader=new FileReader();reader.onload=()=>{preview.src=String(reader.result);};reader.readAsDataURL(file);});
 
 window.slideshowPhotoRecords=<?=json_encode(array_values((array)($sectionsSettings['slideshow']['photos']??[])),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
 const slideshowPhotoList=document.getElementById('slideshow-photo-list'),slideshowEditModal=document.getElementById('slideshow-photo-edit-modal'),slideshowEditForm=document.getElementById('slideshow-photo-edit-form'),slideshowDeleteButton=document.getElementById('slideshow-delete-selected'),slideshowSelectAll=document.getElementById('slideshow-select-all');
 // Move the dialog out of Page Management containers so it overlays the viewport.
 if(slideshowEditModal&&slideshowEditModal.parentElement!==document.body)document.body.appendChild(slideshowEditModal);
 const closeSlideshowEditModal=()=>{
   if(!slideshowEditModal)return;
   slideshowEditModal.hidden=true;
   slideshowEditModal.style.setProperty('display','none','important');
   document.body.classList.remove('modal-open');
 };
 const openSlideshowEditModal=()=>{
   if(!slideshowEditModal)return;
   slideshowEditModal.hidden=false;
   Object.assign(slideshowEditModal.style,{
     position:'fixed',top:'0',right:'0',bottom:'0',left:'0',
     width:'100vw',height:'100vh',boxSizing:'border-box',
     zIndex:'2147483500',display:'flex',alignItems:'center',
     justifyContent:'center',padding:'20px',
     background:'rgba(0,0,0,.78)',backdropFilter:'blur(7px)',
     overflowY:'auto'
   });
   slideshowEditModal.style.setProperty('display','flex','important');
   const card=slideshowEditModal.querySelector('.slideshow-photo-edit-modal');
   if(card)Object.assign(card.style,{
     display:'block',position:'relative',width:'min(680px,calc(100vw - 40px))',
     maxWidth:'680px',maxHeight:'88vh',overflowY:'auto',
     boxSizing:'border-box',padding:'22px',
     border:'1px solid rgba(184,50,63,.55)',borderRadius:'15px',
     background:'#130e12',color:'#f5e9ed',
     boxShadow:'0 24px 70px rgba(0,0,0,.65)'
   });
   document.body.classList.add('modal-open');
   window.requestAnimationFrame(()=>document.getElementById('slideshow-edit-title')?.focus());
 };
 closeSlideshowEditModal();
 window.syncSlideshowPhotoSelection?.();
 const renumberSlideshowPhotos=()=>{[...(slideshowPhotoList?.querySelectorAll('.slideshow-saved-photo')||[])].forEach((card,index)=>{card.dataset.photoIndex=String(index);const label=card.querySelector('.photo-index');if(label)label.textContent='Photo '+(index+1);});};
 slideshowPhotoList?.addEventListener('click',event=>{const edit=event.target.closest('.slideshow-edit-photo');if(!edit)return;const card=edit.closest('.slideshow-saved-photo'),index=Number(card.dataset.photoIndex),photo=window.slideshowPhotoRecords[index];if(!photo){alert('Reload the page and try again.');return;}document.getElementById('slideshow-edit-index').value=String(index);document.getElementById('slideshow-edit-title').value=photo.title||'';document.getElementById('slideshow-edit-heading').value=photo.heading||'';document.getElementById('slideshow-edit-description').value=photo.description||'';openSlideshowEditModal();});
 document.getElementById('slideshow-edit-cancel')?.addEventListener('click',closeSlideshowEditModal);
 const showSlideshowPhotoEditSuccess=result=>{
   document.getElementById('slideshow-photo-edit-success-modal')?.remove();
   const overlay=document.createElement('div');overlay.id='slideshow-photo-edit-success-modal';overlay.className='account-status-modal';overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.style.zIndex='2147483600';
   const card=document.createElement('section');card.className='account-status-modal-card';
   const icon=document.createElement('div');icon.className='account-status-modal-icon';icon.textContent='✓';
   const message=document.createElement('h2');
   const titleName=result.photo?.title||'Untitled photo';
   if(result.successType==='title'){
     message.append(document.createTextNode('The photo title '));
     const oldTitle=document.createElement('span');oldTitle.textContent=result.oldTitle||'';oldTitle.style.color='#ef5968';message.append(oldTitle);
     message.append(document.createTextNode(' has been successfully renamed to '));
     const newTitle=document.createElement('span');newTitle.textContent=result.newTitle||titleName;newTitle.style.color='#55c878';message.append(newTitle);
     message.append(document.createTextNode('.'));
   }else if(result.successType==='heading'){
     message.textContent='The photo heading has been successfully updated.';
   }else if(result.successType==='description'){
     message.append(document.createTextNode('The description of the "'));
     const name=document.createElement('span');name.textContent=titleName;name.style.color='#f0dce2';message.append(name);
     message.append(document.createTextNode('" has been successfully updated.'));
   }else if(result.successType==='photo'){
     message.append(document.createTextNode('The photo of "'));
     const name=document.createElement('span');name.textContent=titleName;name.style.color='#f0dce2';message.append(name);
     message.append(document.createTextNode('" has been successfully changed.'));
   }else{
     message.textContent='The contents has been successfully updated.';
   }
   const actions=document.createElement('div');actions.className='gallery-modal-actions';
   const ok=document.createElement('button');ok.type='button';ok.className='btn';ok.textContent='OK';actions.append(ok);
   card.append(icon,message,actions);overlay.append(card);document.body.appendChild(overlay);document.body.classList.add('modal-open');
   ok.type='button';ok.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();overlay.remove();document.body.classList.remove('modal-open');const destination=new URL('admin.php?section=page-management&tab=sections&section_tab=slideshow#slideshow-photo-list',document.baseURI);window.location.href=destination.href;},{once:true});
   ok.focus();
 };
 slideshowEditForm?.addEventListener('submit',async event=>{
   event.preventDefault();const submit=slideshowEditForm.querySelector('[type=submit]');submit.disabled=true;submit.textContent='Saving…';
   const data=new FormData(slideshowEditForm);data.set('action','edit_slideshow_photo');
   try{
     const response=await fetch('admin.php',{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
     const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'The photo could not be updated.');
     const index=result.index,photo=result.photo;window.slideshowPhotoRecords[index]=photo;
     const card=slideshowPhotoList.querySelector('[data-photo-index="'+index+'"]');
     if(card){
       let img=card.querySelector('img');if(!img){img=document.createElement('img');card.insertBefore(img,card.children[1]);}
       img.src=photo.photo;img.alt=photo.title;card.querySelector('.gallery-photo-title').textContent=photo.title;card.querySelector('.gallery-photo-title').title=photo.title;
       const description=card.querySelector('.gallery-photo-description');if(description)description.textContent=(photo.description||'').replace(/<[^>]*>/g,'').trim()||'No description added.';
     }
     const preview=document.getElementById('slide-preview-media');
     if(preview){const item=preview.querySelectorAll('.slideshow-photo-preview-card')[index];if(item){item.querySelector('img').src=photo.photo;item.querySelector('img').alt=photo.title;item.querySelector('strong').textContent=photo.title;item.querySelector('span').textContent=photo.heading||'';}}
     closeSlideshowEditModal();slideshowEditForm.reset();showSlideshowPhotoEditSuccess(result);
   }catch(error){alert(error.message||'The photo could not be updated.');}
   finally{submit.disabled=false;submit.textContent='Save Changes';}
 });
 const slideshowDeleteModal=document.getElementById('slideshow-photo-delete-confirm-modal');const slideshowDeleteSuccessModal=document.getElementById('slideshow-photo-delete-success-modal');const prepareSlideshowDeleteModal=modal=>{if(!modal)return;if(modal.parentElement!==document.body)document.body.appendChild(modal);modal.hidden=true;modal.style.setProperty('display','none','important');};const showSlideshowDeleteModal=modal=>{if(!modal)return;prepareSlideshowDeleteModal(modal);modal.hidden=false;modal.style.setProperty('display','flex','important');};const hideSlideshowDeleteModal=modal=>{if(!modal)return;modal.hidden=true;modal.style.setProperty('display','none','important');};[slideshowDeleteModal,slideshowDeleteSuccessModal].forEach(prepareSlideshowDeleteModal);let slideshowPendingDelete=[];const closeSlideshowDeleteModal=()=>{hideSlideshowDeleteModal(slideshowDeleteModal);slideshowPendingDelete=[];};document.getElementById('slideshow-photo-delete-cancel')?.addEventListener('click',closeSlideshowDeleteModal);document.getElementById('slideshow-photo-delete-confirm')?.addEventListener('click',async()=>{if(!slideshowPendingDelete.length)return;const selected=[...slideshowPendingDelete],data=new FormData();data.append('csrf',slideshowUploadForm.querySelector('[name=csrf]').value);data.append('action','delete_slideshow_photos');selected.forEach(index=>data.append('photo_indices[]',String(index)));const confirmButton=document.getElementById('slideshow-photo-delete-confirm');confirmButton.disabled=true;confirmButton.textContent='Deleting…';try{const response=await fetch('admin.php',{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}),result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Selected photos could not be deleted.');closeSlideshowDeleteModal();const indices=new Set(selected);window.slideshowPhotoRecords=window.slideshowPhotoRecords.filter((photo,index)=>!indices.has(index));selected.sort((a,b)=>b-a).forEach(index=>slideshowPhotoList.querySelector('[data-photo-index="'+index+'"]')?.remove());if(!slideshowPhotoList.querySelector('.slideshow-saved-photo')){const empty=document.createElement('p');empty.className='muted slideshow-empty';empty.textContent='No slideshow photos have been uploaded yet.';slideshowPhotoList.append(empty);}const preview=document.getElementById('slide-preview-media');if(preview){[...preview.querySelectorAll('.slideshow-photo-preview-card')].forEach((card,index)=>{if(indices.has(index))card.remove();});}renumberSlideshowPhotos();window.syncSlideshowPhotoSelection?.();showSlideshowDeleteModal(slideshowDeleteSuccessModal);}catch(error){alert(error.message||'Selected photos could not be deleted.');window.syncSlideshowPhotoSelection?.();}finally{confirmButton.disabled=false;confirmButton.textContent='Delete';}});slideshowDeleteButton?.addEventListener('click',()=>{slideshowPendingDelete=[...(slideshowPhotoList?.querySelectorAll('.slideshow-photo-checkbox:checked')||[])].map(check=>Number(check.closest('.slideshow-saved-photo').dataset.photoIndex));if(slideshowPendingDelete.length)showSlideshowDeleteModal(slideshowDeleteModal);});document.getElementById('slideshow-photo-delete-success-close')?.addEventListener('click',()=>{hideSlideshowDeleteModal(slideshowDeleteSuccessModal);});
 const slideshowModal=document.getElementById('slideshow-upload-modal');
 const slideshowUploadForm=document.getElementById('slideshow-upload-form');
 const slideshowFiles=document.getElementById('slideshow-photo-files');
 const slideshowDetails=document.getElementById('slideshow-photo-details');
 // Keep the upload dialog outside the hidden Page Management tab panel and
 // any ancestor stacking/overflow contexts, just like the delete dialogs.
 if(slideshowModal&&slideshowModal.parentElement!==document.body)document.body.appendChild(slideshowModal);
 const openSlideshowModal=()=>{
   if(!slideshowModal)return;
   slideshowModal.hidden=false;
   slideshowModal.style.setProperty('display','flex','important');
   document.body.classList.add('modal-open');
   requestAnimationFrame(()=>slideshowFiles?.focus());
 };
 const closeSlideshowModal=()=>{
   if(!slideshowModal)return;
   slideshowModal.hidden=true;
   slideshowModal.style.setProperty('display','none','important');
   document.body.classList.remove('modal-open');
 };
 const showSlideshowUploadSuccess=(count)=>{
   document.getElementById('slideshow-upload-success-modal')?.remove();
   const overlay=document.createElement('div');
   overlay.id='slideshow-upload-success-modal';
   overlay.className='account-status-modal';
   overlay.setAttribute('role','dialog');
   overlay.setAttribute('aria-modal','true');
   const card=document.createElement('section');
   card.className='account-status-modal-card';
   const icon=document.createElement('div');
   icon.className='account-status-modal-icon';
   icon.textContent='✓';
   const message=document.createElement('h2');
   message.textContent=count+' slideshow '+(count===1?'photo was':'photos were')+' successfully uploaded.';
   const actions=document.createElement('div');
   actions.className='gallery-modal-actions';
   const ok=document.createElement('button');
   ok.type='button';ok.className='btn';ok.textContent='OK';
   actions.append(ok);card.append(icon,message,actions);overlay.append(card);
   document.body.appendChild(overlay);
   document.body.classList.add('modal-open');
   const close=()=>{overlay.remove();document.body.classList.remove('modal-open');};
   ok.addEventListener('click',close);
   ok.focus();
 };
 if(slideshowModal){
   slideshowModal.hidden=true;
   slideshowModal.style.setProperty('display','none','important');
 }
 document.addEventListener('click',event=>{
   const trigger=event.target instanceof Element?event.target.closest('#slideshow-upload-photos'):null;
   if(trigger){event.preventDefault();openSlideshowModal();}
 });
 document.getElementById('slideshow-upload-cancel')?.addEventListener('click',closeSlideshowModal);
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&slideshowModal&&!slideshowModal.hidden)closeSlideshowModal();});
 let slideshowPreviewUrls=[];
 slideshowFiles?.addEventListener('change',()=>{
   slideshowPreviewUrls.forEach(url=>URL.revokeObjectURL(url));
   slideshowPreviewUrls=[];
   slideshowDetails.replaceChildren();
   const selected=[...(slideshowFiles.files||[])];
   const invalid=selected.length>20
     ? 'Choose no more than 20 photos per upload.'
     : selected.find(file=>!/\.(jpe?g|png|avif|webp)$/i.test(file.name)||!['image/jpeg','image/png','image/avif','image/webp'].includes(file.type))
       ? 'Only JPG, JPEG, PNG, AVIF and WEBP photos are allowed.'
       : selected.find(file=>file.size>10*1024*1024)
         ? 'Each slideshow photo must not exceed 10 MB.' : '';
   if(invalid){alert(invalid);slideshowFiles.value='';return;}
   selected.forEach((file,index)=>{
     const card=document.createElement('article');card.className='slideshow-upload-photo-card';
     const thumbnail=document.createElement('img');
     const previewUrl=URL.createObjectURL(file);slideshowPreviewUrls.push(previewUrl);
     thumbnail.src=previewUrl;thumbnail.alt='Preview of '+file.name;
     thumbnail.style.cssText='display:block;width:100%;max-height:180px;object-fit:cover;border-radius:8px;margin-bottom:10px;';
     card.append(thumbnail);
     const heading=document.createElement('h4');heading.textContent='Photo '+(index+1)+' · '+file.name;card.append(heading);
     const remove=document.createElement('button');remove.type='button';remove.className='btn light slideshow-upload-delete-row';remove.textContent='Delete Row';remove.setAttribute('aria-label','Remove '+file.name+' from this upload');remove.dataset.fileIndex=String(index);card.append(remove);
     const titleLabel=document.createElement('label');titleLabel.className='section-field';titleLabel.textContent='Photo Title';
     const title=document.createElement('input');title.name='slideshow_photo_title[]';title.required=true;title.maxLength=180;title.value=file.name.replace(/\.[^.]+$/,'');titleLabel.append(title);card.append(titleLabel);
     const photoHeadingLabel=document.createElement('label');photoHeadingLabel.className='section-field';photoHeadingLabel.textContent='Photo Heading';
     const photoHeading=document.createElement('input');photoHeading.name='slideshow_photo_heading[]';photoHeading.maxLength=180;photoHeadingLabel.append(photoHeading);card.append(photoHeadingLabel);
     const descLabel=document.createElement('label');descLabel.className='section-field';descLabel.textContent='Description';
     const editor=document.createElement('input');editor.type='text';editor.className='slideshow-description-editor';editor.name='slideshow_photo_description[]';editor.maxLength=5000;editor.placeholder='Enter description…';
     descLabel.append(editor);card.append(descLabel);
     const preview=document.createElement('small');preview.className='muted';preview.textContent='File size: '+(file.size/(1024*1024)).toFixed(2)+' MB';card.append(preview);
     slideshowDetails.append(card);
   });
 });
 slideshowDetails?.addEventListener('click',event=>{
   const button=event.target.closest('.slideshow-upload-delete-row');if(!button)return;
   const removeIndex=Number(button.dataset.fileIndex);
   const currentFiles=[...(slideshowFiles.files||[])];
   if(!Number.isInteger(removeIndex)||removeIndex<0||removeIndex>=currentFiles.length)return;
   const transfer=new DataTransfer();
   currentFiles.forEach((file,index)=>{if(index!==removeIndex)transfer.items.add(file);});
   slideshowFiles.files=transfer.files;
   const previewUrl=slideshowPreviewUrls[removeIndex];
   if(previewUrl){URL.revokeObjectURL(previewUrl);slideshowPreviewUrls.splice(removeIndex,1);}
   button.closest('.slideshow-upload-photo-card')?.remove();
   [...slideshowDetails.querySelectorAll('.slideshow-upload-photo-card')].forEach((card,index)=>{
     const file=currentFiles.filter((_,fileIndex)=>fileIndex!==removeIndex)[index];
     const heading=card.querySelector('h4');
     if(heading&&file)heading.textContent='Photo '+(index+1)+' · '+file.name;
     const removeButton=card.querySelector('.slideshow-upload-delete-row');
     if(removeButton){removeButton.dataset.fileIndex=String(index);removeButton.setAttribute('aria-label','Remove '+file.name+' from this upload');}
   });
 });
 slideshowUploadForm?.addEventListener('submit',async event=>{
   event.preventDefault();
   if(!slideshowFiles?.files?.length){alert('Choose at least one photo to upload.');return;}
   const submit=slideshowUploadForm.querySelector('[type=submit]');submit.disabled=true;submit.textContent='Uploading…';
   const data=new FormData(slideshowUploadForm);data.set('action','upload_slideshow_photos');
   try{
     const response=await fetch('admin.php',{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
     const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'The slideshow photos could not be uploaded.');
     closeSlideshowModal();
     const list=document.getElementById('slideshow-photo-list');list.querySelector('.slideshow-empty')?.remove();
     result.photos.forEach(photo=>{window.slideshowPhotoRecords.push(photo);const index=window.slideshowPhotoRecords.length-1;const card=document.createElement('article');card.className='slideshow-saved-photo';card.dataset.photoIndex=String(index);const label=document.createElement('label');label.className='gallery-photo-select';const check=document.createElement('input');check.type='checkbox';check.className='slideshow-photo-checkbox';check.setAttribute('aria-label','Select photo '+(index+1));label.append(check,document.createTextNode(' Select'));const media=document.createElement('div');media.className='gallery-photo-image';const image=document.createElement('img');image.src=photo.photo;image.alt=photo.title||'Slideshow photo';image.loading='lazy';media.append(image);const title=document.createElement('div');title.className='gallery-photo-title';title.textContent=photo.title||'Untitled photo';title.title=title.textContent;const edit=document.createElement('button');edit.type='button';edit.className='btn light gallery-photo-edit slideshow-edit-photo';edit.textContent='Edit';card.append(label,media,title,edit);list.append(card);});window.syncSlideshowPhotoSelection?.();
     slideshowUploadForm.reset();slideshowDetails.replaceChildren();slideshowPreviewUrls.forEach(url=>URL.revokeObjectURL(url));slideshowPreviewUrls=[];
     const preview=document.getElementById('slide-preview-media');result.photos.forEach(photo=>{const card=document.createElement('div');card.className='slideshow-photo-preview-card';const img=document.createElement('img');img.src=photo.photo;img.alt=photo.title||'';const title=document.createElement('strong');title.textContent=photo.title||'Untitled photo';const heading=document.createElement('span');heading.textContent=photo.heading||'';card.append(img,title,heading);preview.append(card);});
     showSlideshowUploadSuccess(Number(result.count)||result.photos.length);
   }catch(error){alert(error.message||'Slideshow photo upload failed.');}
   finally{submit.disabled=false;submit.textContent='Upload Photos';}
 });
 document.getElementById('slideshow-upload-modal')?.addEventListener('click',event=>{if(event.target===slideshowModal)return;});
 root.querySelectorAll('[data-link]').forEach(b=>b.addEventListener('click',()=>{const url=prompt('Enter the link URL (https://, http://, or mailto:):');if(url){const ed=b.closest('.section-field')?.querySelector('[contenteditable=true]');ed?.focus();document.execCommand('createLink',false,url);ed?.dispatchEvent(new Event('input'))}}));
 const add=(target,html)=>{document.getElementById(target).insertAdjacentHTML('beforeend',html)};
 document.getElementById('add-mega-panel')?.addEventListener('click',()=>add('mega-panel-list','<div class="section-repeat-card"><div class="section-repeat-head"><strong>New Menu Panel</strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Panel Title<input name="panel_title[]"></label><label class="section-field">Panel Description<textarea name="panel_description[]"></textarea></label><label class="section-field">Panel Page URL<input name="panel_url[]"></label><label class="section-field">Upload Panel Cover Photo<input type="file" name="panel_photo[]" accept="image/*"></label></div></div>'));
 root.addEventListener('click',e=>{if(e.target.classList.contains('section-remove'))e.target.closest('.section-repeat-card')?.remove()});
 root.querySelector('#sections-settings-form')?.addEventListener('submit',()=>{root.querySelectorAll('[contenteditable=true]').forEach(ed=>{const ta=ed.parentElement.querySelector('textarea');if(ta)ta.value=ed.innerHTML})});
 })();
 </script>
 <script>
 (() => {
   const list=document.getElementById('slideshow-photo-list');
   const button=document.getElementById('slideshow-delete-selected');
   const selectAll=document.getElementById('slideshow-select-all');
   if(!list||!button||!selectAll)return;
   // Start disabled before any selection events can fire.
   button.disabled=true;
   button.setAttribute('disabled','');
   button.setAttribute('aria-disabled','true');
   button.style.pointerEvents='none';
   const checks=()=>Array.from(list.querySelectorAll('input.slideshow-photo-checkbox'));
   const sync=()=>{
     const boxes=checks();
     const any=boxes.some(box=>box.checked);
     button.textContent='Delete Selected';
     button.disabled=!any;
     button.toggleAttribute('disabled',!any);
     button.setAttribute('aria-disabled',String(!any));
     button.style.pointerEvents=any?'auto':'none';
     selectAll.checked=boxes.length>0&&boxes.every(box=>box.checked);
     selectAll.indeterminate=boxes.some(box=>box.checked)&&!selectAll.checked;
     selectAll.disabled=boxes.length===0;
   };
   window.syncSlideshowPhotoSelection=sync;
   selectAll.addEventListener('change',()=>{
     checks().forEach(box=>{box.checked=selectAll.checked;});
     sync();
   });
   list.addEventListener('change',event=>{
     if(event.target instanceof HTMLInputElement&&event.target.matches('input.slideshow-photo-checkbox'))sync();
   });
   list.addEventListener('click',event=>{
     const label=event.target instanceof Element?event.target.closest('label.gallery-photo-select'):null;
     if(label)queueMicrotask(sync);
   });
   sync();
 })();
 </script>

</section>
    </section>
    <script>
    (() => {
      const form = document.getElementById('footer-settings-form');
      if (!form) return;
      const fields = {
        brand: document.getElementById('footer-brand'),
        description: document.getElementById('footer-description'),
        copyright: document.getElementById('footer-copyright'),
        exploreTitle: document.getElementById('footer-explore-title'),
        discoverTitle: document.getElementById('footer-discover-title')
      };
      const updateLinks = group => {
        const editor = document.getElementById('footer-'+group+'-editor');
        const preview = document.getElementById(group === 'brand' ? 'preview-footer-brand-links' : 'preview-footer-'+group+'-links');
        preview.replaceChildren();
        editor.querySelectorAll('.footer-link-row').forEach(row => {
          const label = row.querySelector('[name="'+group+'_label[]"]').value.trim();
          const url = row.querySelector('[name="'+group+'_url[]"]').value.trim();
          if (!label) return;
          const item = url ? document.createElement('a') : document.createElement('span');
          item.textContent = label;
          if (url) item.href = url; else item.className = 'footer-preview-text-item';
          preview.append(item);
        });
      };
      const updatePreview = () => {
        document.getElementById('preview-footer-brand').childNodes[0].textContent = fields.brand.value;
        document.getElementById('preview-footer-description').textContent = fields.description.value;
        document.getElementById('preview-footer-copyright').textContent = fields.copyright.value.replaceAll('{year}', String(new Date().getFullYear()));
        document.getElementById('preview-footer-explore-title').textContent = fields.exploreTitle.value;
        document.getElementById('preview-footer-discover-title').textContent = fields.discoverTitle.value;
        updateLinks('brand'); updateLinks('explore'); updateLinks('discover');
      };
      const addRow = group => {
        const editor = document.getElementById('footer-'+group+'-editor');
        const row = document.createElement('div'); row.className = 'footer-link-row';
        const label = document.createElement('input'); label.className='field'; label.name=group+'_label[]'; label.maxLength=100; label.placeholder='Link title'; label.setAttribute('aria-label',group+' link title');
        const url = document.createElement('input'); url.className='field'; url.name=group+'_url[]'; url.maxLength=500; url.placeholder='Page URL'; url.setAttribute('aria-label',group+' link URL');
        const remove = document.createElement('button'); remove.type='button'; remove.className='btn light remove-footer-link'; remove.textContent='Remove';
        row.append(label,url,remove); editor.append(row); updatePreview(); label.focus();
      };
      form.addEventListener('input', event => { if (event.target.matches('.footer-link-row input')) event.target.classList.remove('footer-field-invalid'); updatePreview(); });
      form.addEventListener('change', updatePreview);
      let invalidTitleField = null;
      form.addEventListener('submit', event => {
        form.querySelectorAll('.footer-field-invalid').forEach(field => field.classList.remove('footer-field-invalid'));
        for (const group of [{name:'brand',column:'1'},{name:'explore',column:'2'},{name:'discover',column:'3'}]) {
          const rows=[...document.querySelectorAll('#footer-'+group.name+'-editor .footer-link-row')];
          const missing=rows.filter(row=>row.querySelector('[name="'+group.name+'_url[]"]').value.trim()&&!row.querySelector('[name="'+group.name+'_label[]"]').value.trim());
          if(missing.length){event.preventDefault();missing.forEach(row=>row.querySelector('[name="'+group.name+'_label[]"]').classList.add('footer-field-invalid'));invalidTitleField=missing[0].querySelector('[name="'+group.name+'_label[]"]');const popup=document.createElement('div');popup.className='account-status-modal';popup.id='footer-client-validation-modal';popup.setAttribute('role','dialog');popup.setAttribute('aria-modal','true');const card=document.createElement('div');card.className='account-status-modal-card';const icon=document.createElement('div');icon.className='account-status-modal-icon';icon.textContent='!';const heading=document.createElement('h2');heading.textContent='Please enter a title in Column '+group.column+'.';const ok=document.createElement('button');ok.type='button';ok.className='btn';ok.textContent='OK';ok.addEventListener('click',()=>{popup.remove();if(invalidTitleField){invalidTitleField.focus();invalidTitleField.scrollIntoView({behavior:'smooth',block:'center'});}});card.append(icon,heading,ok);popup.append(card);document.body.append(popup);ok.focus();return;}
        }
      });
      form.addEventListener('click', event => {
        const add = event.target.closest('.add-footer-link');
        if (add) { addRow(add.dataset.group); return; }
        const remove = event.target.closest('.remove-footer-link');
        if (remove) { remove.closest('.footer-link-row').remove(); updatePreview(); }
      });
      updatePreview();
    })();
    (() => {
      const form = document.getElementById('header-settings-form');
      if (form) {
        const links = document.getElementById('header-links-list');
        const add = document.getElementById('add-header-link');
        const makeLinkRow = (label = '', url = '') => {
          const row=document.createElement('div');row.className='header-link-row';
          const urlInput=document.createElement('input');urlInput.className='field';urlInput.type='text';urlInput.name='header_link_url[]';urlInput.maxLength=500;urlInput.placeholder='Page URL';urlInput.value=url;
          const labelInput=document.createElement('input');labelInput.className='field';labelInput.type='text';labelInput.name='header_link_label[]';labelInput.maxLength=60;labelInput.placeholder='Link title';labelInput.setAttribute('aria-label','Link title');labelInput.value=label;
          const remove=document.createElement('button');remove.type='button';remove.className='btn light remove-header-link';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();updatePreview();});
          row.append(urlInput,labelInput,remove);return row;
        };
        const editorText = row => row.querySelector('[name="header_link_label[]"]')?.value || '';
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
          const previewTypography=(key,fallback)=>{const st=styleFor(key);const saved=savedTypography[key]||{};const formats=(st.format??saved.format??'').split(/[ ,]+/).filter(Boolean);return {family:st.family||saved.family||'Ubuntu',style:formats.includes('italic')?'italic':(saved.style||'normal'),size:(st.size||saved.size||fallback.size)+'px',weight:formats.includes('bold')?700:(saved.weight||fallback.weight),decoration:formats.includes('underline')?'underline':'none',color:st.color||saved.color||fallback.color};};
          const titleStyle=previewTypography('title',{size:20,weight:700,color:'#f7eef0'});const titlePreviewElement=document.getElementById('preview-header-title');Object.assign(titlePreviewElement.style,{fontFamily:titleStyle.family,fontStyle:titleStyle.style,fontSize:titleStyle.size,fontWeight:titleStyle.weight,textDecoration:'none',color:titleStyle.color});
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{const target=form.querySelector('[data-rich-value="'+editor.dataset.richEditor+'"]');if(target)target.value=editor.innerHTML;});
          const titlePreview=document.getElementById('preview-header-title');renderTitlePreview();
          // Keep the base family on the title container as a normal inherited
          // value. Per-range spans carry their own font-family declarations.
          titlePreview.style.fontFamily=titleStyle.family||'Ubuntu';
          document.getElementById('preview-hidden-left').innerHTML=form.querySelector('[data-rich-editor="hidden_left"]').innerHTML;
          document.getElementById('preview-hidden-right').innerHTML=form.querySelector('[data-rich-editor="hidden_right"]').innerHTML;
          const nav=document.getElementById('preview-header-links'); nav.querySelectorAll('.custom-preview-link').forEach(a=>a.remove());
          [...links.querySelectorAll('.header-link-row')].forEach(row=>{const label=editorText(row).trim();const url=row.querySelector('[name="header_link_url[]"]').value.trim();if(label){const a=document.createElement('a');a.className='custom-preview-link';a.textContent=label;a.href=url||'#';nav.insertBefore(a,nav.querySelector('.preview-signin'));}});
          const logo=document.getElementById('preview-header-logo');
          const logoWidth=form.elements.logo_width.value,logoHeight=form.elements.logo_height.value;
          logo.style.width=logoWidth+'px';logo.style.height=logoHeight+'px';logo.style.objectFit='contain';
          document.getElementById('logo-width-value').textContent=logoWidth+' px';
          document.getElementById('logo-height-value').textContent=logoHeight+' px';
        };
        const attachColorPalette=(input)=>{
          if(!input||input.dataset.paletteReady)return;
          input.dataset.paletteReady='1';
          const button=document.createElement('button');
          button.type='button';button.className='font-color-palette';
          button.setAttribute('aria-label','Open font color palette');
          button.setAttribute('aria-haspopup','true');button.setAttribute('aria-expanded','false');
          button.title='Choose font color';
          button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 0 18h1.2a2.3 2.3 0 0 0 1.6-3.9 1.2 1.2 0 0 1 .9-2.1H18a3 3 0 0 0 3-3c0-5-4-8-9-9Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="10" cy="6.8" r="1"/><circle cx="15" cy="7.5" r="1"/><circle cx="17.5" cy="11" r="1"/></svg>';
          const palette=document.createElement('div');
          palette.className='font-color-palette-popover';palette.hidden=true;
          palette.setAttribute('role','group');palette.setAttribute('aria-label','Font color choices');
          const colors=['#ffffff','#f7eef0','#e8dce0','#d9a6b2','#c83e5b','#92243a','#5b1427','#171115','#000000','#f4c542','#55a66b','#4285d4','#8b5cf6','#ec6b2d'];
          colors.forEach(color=>{
            const swatch=document.createElement('button');swatch.type='button';
            swatch.className='font-color-swatch';swatch.style.setProperty('--swatch-color',color);
            swatch.setAttribute('aria-label','Use '+color);swatch.title=color;
            swatch.addEventListener('mousedown',event=>event.preventDefault());
            swatch.addEventListener('click',()=>{
              input.value=color;input.dispatchEvent(new Event('input',{bubbles:true}));
              input.dispatchEvent(new Event('change',{bubbles:true}));
              palette.hidden=true;button.setAttribute('aria-expanded','false');button.style.setProperty('--palette-color',color);
            });
            palette.append(swatch);
          });
          const custom=document.createElement('button');custom.type='button';custom.className='font-color-custom';custom.textContent='Custom…';
          custom.addEventListener('mousedown',event=>event.preventDefault());
          custom.addEventListener('click',()=>{try{if(input.showPicker)input.showPicker();else input.click();}catch(error){input.click();}});
          palette.append(custom);
          input.classList.add('font-color-native');
          input.insertAdjacentElement('afterend',button);button.insertAdjacentElement('afterend',palette);
          const sync=()=>{button.style.setProperty('--palette-color',input.value);};
          button.addEventListener('mousedown',event=>{event.preventDefault();rememberTitleSelection?.();});
          button.addEventListener('click',()=>{
            rememberTitleSelection?.();
            palette.hidden=!palette.hidden;button.setAttribute('aria-expanded',String(!palette.hidden));
          });
          input.addEventListener('input',sync);input.addEventListener('change',sync);sync();
        };

        // Selected-text typography is preview-only: keep the editor itself untouched.
        const titleEditor=form.querySelector('[data-rich-editor="title"]');
        const titlePreview=document.getElementById('preview-header-title');
        // Bind the independent controls to the Header Title fieldset explicitly.
        // This reference was previously missing and caused a ReferenceError that
        // stopped the entire selected-text typography workflow from initializing.
        const titleTypographyGroup=titleEditor?.closest('.header-field')?.querySelector('fieldset.typography-group') || null;
        let savedTitleSelection=null;
        const titlePreviewStyles=[];
        // Rebuild selected-range formatting from the last saved title HTML so the
        // preview remains identical after a save or a later visit to this tab.
        const savedTitleHtml=<?=json_encode((string)($headerSettings['title_html'] ?? ''),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
        let titlePreviewHasUserEdits=false;
        let titlePreviewFirstRender=true;
        if(savedTitleHtml){
          const savedTemplate=document.createElement('template');
          savedTemplate.innerHTML=savedTitleHtml;
          let savedOffset=0;
          const readSavedTitleNodes=(node,inherited={})=>{
            if(node.nodeType===Node.TEXT_NODE){
              const value=node.nodeValue||'';
              if(value.length&&Object.keys(inherited).length)titlePreviewStyles.push({start:savedOffset,end:savedOffset+value.length,styles:{...inherited}});
              savedOffset+=value.length;
              return;
            }
            if(node.nodeType!==Node.ELEMENT_NODE&&node.nodeType!==Node.DOCUMENT_FRAGMENT_NODE)return;
            const next={...inherited};
            if(node.nodeType===Node.ELEMENT_NODE){
              const tag=node.tagName.toLowerCase(),style=node.style;
              if(tag==='b'||tag==='strong')next.fontWeight='700';
              if(tag==='i'||tag==='em')next.fontStyle='italic';
              if(tag==='u')next.textDecoration='underline';
              if(style.color)next.color=style.color;
              if(style.fontFamily)next.fontFamily=style.fontFamily;
              if(style.fontSize)next.fontSize=style.fontSize;
              if(style.fontWeight)next.fontWeight=style.fontWeight;
              if(style.fontStyle)next.fontStyle=style.fontStyle;
              if(style.textDecoration)next.textDecoration=style.textDecoration;
            }
            [...node.childNodes].forEach(child=>readSavedTitleNodes(child,next));
          };
          readSavedTitleNodes(savedTemplate.content);
        }
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
          // Capture while the textarea still owns focus; native select controls
          // collapse its live selection as soon as they receive focus.
          rememberTitleSelection();
        };
        document.addEventListener('pointerdown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('mousedown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('touchstart',captureTitleRangeBeforeControlFocus,{capture:true,passive:true});
        titleTypographyGroup?.addEventListener('focusin',()=>{
          // Do not replace the saved range with the now-collapsed textarea range.
          if(!savedTitleSelection)rememberTitleSelection();
        });
        const escapePreviewText=value=>String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        const renderTitlePreview=()=>{
          const text=titleEditor.value||'';
          const richField=form.querySelector('[data-rich-value="title"]');
          // On first load, show the exact HTML that was saved in the database.
          // This avoids a lossy reconstruction of nested/overlapping inline styles
          // and guarantees the preview starts from the persisted configuration.
          if(titlePreviewFirstRender && !titlePreviewHasUserEdits && savedTitleHtml){
            titlePreview.innerHTML=savedTitleHtml;
            if(richField)richField.value=savedTitleHtml;
            titlePreviewFirstRender=false;
            return;
          }
          titlePreviewFirstRender=false;
          const baseFormats=new Set((titleTypographyGroup?.querySelector('[data-format-value]')?.value||'').split(/[ ,]+/).filter(Boolean));
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
            if(baseFormats.has('bold'))styles.fontWeight='700';
            if(baseFormats.has('italic'))styles.fontStyle='italic';
            if(baseFormats.has('underline'))styles.textDecoration='underline';
            titlePreviewStyles.filter(item=>item.start<end&&item.end>start).forEach(item=>Object.assign(styles,item.styles));
            const underline=styles.textDecoration==='underline';
            delete styles.textDecoration;
            const content=escapePreviewText(text.slice(start,end));
            const declarations=Object.entries(styles).map(([key,value])=>{
              const cssName=key.replace(/[A-Z]/g,m=>'-'+m.toLowerCase());
              return cssName+':'+String(value);
            }).join(';');
            const formatted=underline?'<u>'+content+'</u>':content;
            html+=declarations?'<span style="'+declarations+'">'+formatted+'</span>':formatted;
          }
          titlePreview.innerHTML=html||escapePreviewText(text);
          if(richField)richField.value=html||escapePreviewText(text);
        };
        const applyTitleSelectionStyle=(property,value)=>{
          if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start)return false;
          const textLength=titleEditor.value.length;
          const start=Math.max(0,Math.min(textLength,savedTitleSelection.start));
          const end=Math.max(start,Math.min(textLength,savedTitleSelection.end));
          if(end<=start)return false;
          // Add a range override instead of mutating a matching interval. This
          // preserves earlier formatting outside the newly selected text, even
          // when the new selection partially overlaps an existing formatted range.
          titlePreviewHasUserEdits=true;
          titlePreviewFirstRender=false;
          titlePreviewStyles.push({start,end,styles:{[property]:value}});
          renderTitlePreview();
          return true;
        };
        const isTitleRangeFullyStyled=(property,expectedValue)=>{
          if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start)return false;
          const start=Math.max(0,Math.min(titleEditor.value.length,savedTitleSelection.start));
          const end=Math.max(start,Math.min(titleEditor.value.length,savedTitleSelection.end));
          if(end<=start)return false;
          const baseFormats=new Set((titleTypographyGroup?.querySelector('[data-format-value]')?.value||'').split(/[ ,]+/).filter(Boolean));
          for(let offset=start;offset<end;offset++){
            const styles={};
            titlePreviewStyles.filter(entry=>entry.start<=offset&&entry.end>offset).forEach(entry=>Object.assign(styles,entry.styles));
            let actual=styles[property];
            if(actual===undefined){
              if(property==='fontWeight')actual=baseFormats.has('bold')?'700':'400';
              else if(property==='fontStyle')actual=baseFormats.has('italic')?'italic':'normal';
              else if(property==='textDecoration')actual=baseFormats.has('underline')?'underline':'none';
            }
            if(String(actual)!==String(expectedValue))return false;
          }
          return true;
        };
        // Attach palettes only after selection helpers exist, so opening and choosing
        // a color can preserve and format the exact highlighted textarea range.
        titleTypographyGroup?.querySelectorAll('input[type="color"]').forEach(attachColorPalette);

        titleTypographyGroup?.querySelectorAll('[data-format-toggle]').forEach(button=>{
          // Snapshot the textarea range before the browser moves focus to the
          // toolbar button. Preventing the pointer default also keeps the
          // highlighted text visibly selected while the command is applied.
          button.addEventListener('pointerdown',event=>{rememberTitleSelection();event.preventDefault();});
          button.addEventListener('mousedown',event=>{rememberTitleSelection();event.preventDefault();});
          button.addEventListener('click',()=>{
            const currentRange=readTitleSelection();
            if(currentRange)savedTitleSelection=currentRange;
            const fmt=button.dataset.formatToggle;
            if(savedTitleSelection&&savedTitleSelection.end>savedTitleSelection.start){
              const prop=fmt==='bold'?'fontWeight':fmt==='italic'?'fontStyle':'textDecoration';
              const onValue=fmt==='bold'?'700':fmt==='italic'?'italic':'underline';
              const offValue=fmt==='bold'?'400':fmt==='italic'?'normal':'none';
              // Mixed selections become uniformly formatted on the first click;
              // a second click removes that formatting from the same selection.
              const alreadyApplied=isTitleRangeFullyStyled(prop,onValue);
              const nextValue=alreadyApplied?offValue:onValue;
              if(applyTitleSelectionStyle(prop,nextValue)){
                button.setAttribute('aria-pressed',String(!alreadyApplied));
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
            const range=savedTitleSelection;
            if(!range||range.end<=range.start){updatePreview();return;}
            const prop=input.name.endsWith('[family]')?'fontFamily':input.name.endsWith('[size]')?'fontSize':'color';
            const value=input.name.endsWith('[size]')?input.value+'px':input.value;
            if(applyTitleSelectionStyle(prop,value))updatePreview();
            else updatePreview();
          };
          // Selects commit once on change. Color inputs also emit input while
          // the picker is adjusted, so they update continuously.
          if(input.matches('input[type="color"]'))input.addEventListener('input',applyControlValue);
          input.addEventListener('change',applyControlValue);
        });
        const initRichEditors=()=>{
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{
            if(editor.dataset.ready)return;editor.dataset.ready='1';
            const key=editor.dataset.richEditor;
            const hidden=form.querySelector('[data-rich-value="'+key+'"]');editor.addEventListener('input',()=>{if(hidden)hidden.value=key==='title'?(form.querySelector('[data-rich-value="title"]')?.value||escapePreviewText(editor.value)):editor.innerHTML;if(key==='title'){titlePreviewHasUserEdits=true;titlePreviewFirstRender=false;titlePreviewStyles.length=0;savedTitleSelection=null;}const plain=key==='title'?editor.value.trim():editor.innerText.trim();const plainField=form.querySelector('[data-plain-value="'+key+'"]');if(plainField)plainField.value=plain;updatePreview();});
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
    const paletteStyle=document.createElement('style');paletteStyle.textContent=".font-color-palette-popover{position:absolute;z-index:1000;display:grid;grid-template-columns:repeat(7,25px);gap:5px;padding:9px;margin-top:5px;border:1px solid rgba(220,174,187,.4);border-radius:10px;background:#171115;box-shadow:0 10px 28px rgba(0,0,0,.45)}.font-color-palette-popover[hidden]{display:none}.font-color-swatch{width:25px;height:25px;border:1px solid rgba(255,255,255,.35);border-radius:6px;background:var(--swatch-color);cursor:pointer}.font-color-swatch:focus-visible,.font-color-custom:focus-visible{outline:2px solid #d99aaa;outline-offset:2px}.font-color-custom{grid-column:1/-1;padding:6px;border:1px solid rgba(220,174,187,.35);border-radius:6px;background:#26171d;color:#fff;cursor:pointer;font:11px Ubuntu,sans-serif}.font-color-palette{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;margin-left:7px;padding:7px;border:1px solid rgba(220,174,187,.35);border-radius:9px;background:rgba(91,20,39,.22);color:var(--palette-color,#e8dce0);cursor:pointer;vertical-align:middle;transition:background .2s,border-color .2s,transform .2s}.font-color-palette:hover{background:rgba(130,30,57,.35);border-color:rgba(220,174,187,.7);transform:translateY(-1px)}.font-color-palette:focus-visible{outline:2px solid #d99aaa;outline-offset:2px}.font-color-palette svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.font-color-native{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}";document.head.append(paletteStyle);

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

(()=>{['account-status-modal','banner-settings-success-modal','slideshow-settings-success-modal','header-settings-success-modal','footer-settings-success-modal','footer-validation-modal'].forEach(id=>{const modal=document.getElementById(id);if(!modal)return;const closeButton=modal.querySelector('button');const close=()=>modal.remove();closeButton?.addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close();});document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});closeButton?.focus();});})();
</script>
</main></div><script>

</script>
<script>
// Reset every Gallery overlay when Media Management is entered.
// Several Gallery actions render their own success backdrops (for example,
// Edit Folder), so closing only the upload modals leaves stale overlays behind.
(() => {
  const isMediaManagement = () => {
    try {
      return new URL(window.location.href).searchParams.get('section') === 'media-management';
    } catch (error) {
      return false;
    }
  };

  const closeGalleryOverlays = () => {
    document.querySelectorAll('.gallery-modal-backdrop').forEach(modal => {
      // Keep server-rendered success dialogs visible after their redirect.
      // The entry cleanup must close stale interactive forms, not fresh success
      // confirmations for actions completed by the current request.
      const preserveAddPhotoPrompt = new URLSearchParams(window.location.search).get('gallery_upload_prompt') === '1';
      if (
        modal.id === 'gallery-delete-success-modal' ||
        modal.id === 'gallery-photo-delete-success-modal' ||
        modal.id === 'gallery-photo-edit-success-modal' ||
        modal.id === 'gallery-add-folder-success-modal'
      ) return;
      if (modal.id === 'gallery-add-modal' && preserveAddPhotoPrompt) return;
      modal.hidden = true;
      modal.style.setProperty('display', 'none', 'important');
    });
    document.getElementById('slideshow-upload-success-modal')?.remove();
    document.getElementById('gallery-upload-success-modal')?.remove();

    // Remove any dynamically-created slideshow upload confirmation as well.
    document.querySelectorAll('.account-status-modal').forEach(overlay => {
      const message = overlay.textContent || '';
      if (/slideshow\\s+photos?\\s+(?:was|were)\\s+successfully\\s+uploaded/i.test(message)) {
        overlay.remove();
      }
    });
    if (new URLSearchParams(window.location.search).get('gallery_upload_prompt') === '1') {
      document.body.classList.add('modal-open');
    } else {
      document.body.classList.remove('modal-open');
    }
  };

  const clearOverlaysOnEntry = () => {
    if (!isMediaManagement()) return;
    closeGalleryOverlays();
    const params = new URLSearchParams(window.location.search);
    if (params.get('gallery_upload_prompt') !== '1') return;
    const folder = params.get('gallery_folder') || '';
    if (!folder) return;
    // Dispatch after cleanup and tab activation have both completed.
    const galleryTab = document.querySelector('[data-media-tab="gallery"]');
    galleryTab?.click();
    document.dispatchEvent(new CustomEvent('gallery:open-upload-for-folder', {detail:{folder}}));
  };

  // Direct entry and browser back/forward (including BFCache restoration).
  clearOverlaysOnEntry();
  window.addEventListener('pageshow', clearOverlaysOnEntry);

  // Close stale overlays before navigating to Media Management from another tab.
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const link = target?.closest('a[href]');
    if (!link) return;

    let destination;
    try {
      destination = new URL(link.getAttribute('href'), window.location.href);
    } catch (error) {
      return;
    }

    if (destination.searchParams.get('section') === 'media-management') {
      closeGalleryOverlays();
    }
  }, true);
})();</script>
</body></html>
