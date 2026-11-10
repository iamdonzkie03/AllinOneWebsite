<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rich-content.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Use the same administrator-managed header and footer settings as index.php.
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
$pdo->exec("INSERT IGNORE INTO site_header_settings (id,header_title,header_links,hidden_left,hidden_right) VALUES (1,'Z.C. commonplace','[]','ZAMBOANGA CITY · STORIES, CULTURE & COMMUNITY','Local perspectives. Shared with care.')");
foreach (['logo_width'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 200','logo_height'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 70','title_html'=>'LONGTEXT NULL','hidden_left_html'=>'LONGTEXT NULL','hidden_right_html'=>'LONGTEXT NULL','typography_json'=>'LONGTEXT NULL'] as $column=>$definition) {
    $exists=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='site_header_settings' AND COLUMN_NAME=?");
    $exists->execute([$column]);
    if (!(int)$exists->fetchColumn()) $pdo->exec("ALTER TABLE site_header_settings ADD COLUMN {$column} {$definition}");
}
$publicHeader = $pdo->query("SELECT * FROM site_header_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];

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
    $formatParts=array_values(array_intersect(['bold','italic','underline'],preg_split('/[ ,]+/',$format,-1,PREG_SPLIT_NO_EMPTY)?:[]));
    $fontStyle=in_array('italic',$formatParts,true)?'italic':'normal';
    $fontWeight=in_array('bold',$formatParts,true)?700:$weight;
    $decoration=in_array('underline',$formatParts,true)?'underline':'none';
    return 'font-family:'.htmlspecialchars((string)$family,ENT_QUOTES,'UTF-8').';font-style:'.$fontStyle.';font-size:'.$size.'px;font-weight:'.$fontWeight.';text-decoration:'.$decoration.';color:'.$color;
};
$headerTypographyImportant = static function(array $t) use ($headerTextStyle): string {
    return preg_replace('/;/', ' !important;', $headerTextStyle($t)) . ' !important';
};
$publicHeaderTypographyCss = '.header-type-title{'.$headerTypographyImportant($publicTitleTypography).'}'
    . '.header-type-left{'.$headerTypographyImportant($publicLeftTypography).'}'
    . '.header-type-right{'.$headerTypographyImportant($publicRightTypography).'}';
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
                if($k==='font-style'&&in_array($v,['normal','italic'],true))$rules[]='font-style:'.$v;
                if($k==='font-family'&&in_array(trim($v),['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma'],true))$rules[]='font-family:'.trim($v);
                if($k==='text-decoration'&&in_array($v,['none','underline'],true))$rules[]='text-decoration:'.$v;
            }
            if($rules)$safe=' style="'.implode(';',$rules).'"';
        }
        return '<span'.$safe.'>';
    },$value);
    return preg_replace('/<(b|strong|i|em|u)\\b[^>]*>/i','<$1>',$value);
};
$publicHeaderLogoWidth = max(50, min(200, (int)($publicHeader['logo_width'] ?? 200)));
$publicHeaderLogoHeight = max(30, min(120, (int)($publicHeader['logo_height'] ?? 70)));

$articleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$articleId) {
    http_response_code(404);
    $article = null;
} else {
    $stmt = $pdo->prepare("SELECT c.id,c.title,c.excerpt,c.body,c.cover_photo,c.created_at,c.published_at,cat.name AS category_name,sub.name AS subcategory_name,u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id JOIN users u ON u.id=c.owner_id WHERE c.id=? AND c.content_type='article' AND c.status='published' AND cat.is_active=1 LIMIT 1");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$article) http_response_code(404);
}
$pageTitle = $article ? (string)$article['title'] : 'Story not found';
$publishedDate = $article ? ($article['published_at'] ?: $article['created_at']) : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?=e($article ? (string)($article['excerpt'] ?? '') : 'The requested story could not be found.')?>">
<title><?=e($pageTitle)?> | <?=e((string)($publicHeader['header_title'] ?? 'Z.C. commonplace'))?></title>
<link rel="stylesheet" href="public/assets/css/site.css">
<style>
body{background:radial-gradient(ellipse at 50% 0,rgba(112,22,48,.24),transparent 48%),#100b0e;color:#f7edf0}
#public-site-header{position:relative;z-index:2}
#public-site-header .header-text-bar{background:#09090b;color:#b6c0b8;border-bottom:1px solid rgba(255,255,255,.1);font:10px Ubuntu,sans-serif;letter-spacing:1.2px}
#public-site-header .header-text-inner{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:9px 22px}
#public-site-header .header-text-inner span{max-width:48%;overflow-wrap:anywhere}
#public-site-header .header-text-inner .header-type-right{text-align:right}
#public-site-header .nav .brand{display:flex;align-items:center;gap:12px;min-width:0}
#public-site-header .nav .brand img{flex:0 0 auto}
#public-site-header .nav .navlinks{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;justify-content:flex-end;gap:19px;white-space:nowrap}
#public-site-header .nav .navlinks .header-type-link-0,#public-site-header .nav .navlinks [class^="header-type-link-"],#public-site-header .nav .navlinks>a:not(.btn){font-family:inherit!important;font-size:12px!important;font-weight:500!important;font-style:normal!important;line-height:1.2!important;letter-spacing:normal!important;text-transform:none!important;white-space:nowrap!important}
#public-site-header .nav .navlinks [class^="header-type-link-"]{color:inherit!important;text-decoration:none}
#public-site-header .nav .navlinks a[class^="header-type-link-"]:hover{color:var(--gold)!important}
.page{max-width:850px;margin:0 auto;padding:54px 24px 90px}
.eyebrow{color:#e1a0b1;font-size:11px;text-transform:uppercase;letter-spacing:.17em;font-weight:700}
.article-title{font-size:clamp(32px,6vw,54px);line-height:1.12;letter-spacing:-.035em;margin:14px 0 16px;color:#fff}
.excerpt{font-size:18px;color:#d0bec5;line-height:1.65;margin:0 0 22px}
.meta{display:flex;flex-wrap:wrap;gap:8px 18px;color:#bcaab1;font-size:12px;padding-bottom:26px;border-bottom:1px solid rgba(211,157,174,.17)}
.cover{margin:30px 0 36px;border:1px solid rgba(211,157,174,.17);border-radius:15px;overflow:hidden;background:#1b1116}
.cover img{display:block;width:100%;max-height:540px;object-fit:cover}
.article-content{font-size:17px;line-height:1.85;color:#eee2e7;overflow-wrap:anywhere}
.article-content h2,.article-content h3,.article-content h4{color:#fff;line-height:1.3;margin:1.7em 0 .65em}
.article-content p{margin:0 0 1.25em}
.article-content a{color:#f0a6bb}
.article-content img{max-width:100%;height:auto;border-radius:10px}
.article-content blockquote{margin:1.5em 0;padding:12px 20px;border-left:3px solid #a51f3d;background:rgba(255,255,255,.035);color:#d8c4cc}
.article-content table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}
.article-content th,.article-content td{border:1px solid rgba(211,157,174,.17);padding:9px 12px;text-align:left}
.not-found{text-align:center;padding:70px 24px;background:#1b1116;border:1px solid rgba(211,157,174,.17);border-radius:16px}
.not-found h1{font-size:30px;color:#fff}
.not-found p{color:#bcaab1}
.story-back-link{display:inline-block;margin-top:12px;color:#f0a6bb;font-size:13px;text-decoration:none}
.story-back-link:hover{text-decoration:underline}
@media(max-width:700px){#public-site-header .nav .navlinks{flex-wrap:wrap;white-space:normal;gap:12px}#public-site-header .header-text-inner{padding:9px 14px}.page{padding:38px 18px 65px}.excerpt{font-size:16px}.article-content{font-size:16px}}
</style>
</head>
<body>
<style id="public-header-typography-rules"><?=$publicHeaderTypographyCss?></style>
<header id="public-site-header" data-header-source="page-management">
  <div class="header-text-bar"><div class="wrap header-text-inner">
    <span class="header-type-left"><?=$renderHeaderRich($publicHeader['hidden_left_html'] ?? '',(string)($publicHeader['hidden_left'] ?? ''))?></span>
    <span class="header-type-right"><?=$renderHeaderRich($publicHeader['hidden_right_html'] ?? '',(string)($publicHeader['hidden_right'] ?? ''))?></span>
  </div></div>
  <div class="wrap nav">
    <a class="brand" href="index.php" aria-label="Home"><?php if (!empty($publicHeader['logo_path'])): ?><img src="public/<?=e((string)$publicHeader['logo_path'])?>" alt="" style="width:<?=$publicHeaderLogoWidth?>px;height:<?=$publicHeaderLogoHeight?>px;max-width:100%;object-fit:contain;object-position:center;border-radius:0"><?php endif; ?></a>
    <nav class="navlinks">
      <?php foreach ($publicHeaderLinks as $headerLinkIndex => $headerLink): if (!is_array($headerLink) || trim((string)($headerLink['label'] ?? '')) === '') continue; $headerLinkLabel = $renderHeaderRich($headerLink['label_html'] ?? '',(string)$headerLink['label']); $headerLinkUrl = trim((string)($headerLink['url'] ?? '')); if ($headerLinkUrl !== '' && str_starts_with($headerLinkUrl, '?')) $headerLinkUrl = 'index.php'.$headerLinkUrl; elseif ($headerLinkUrl !== '' && str_starts_with($headerLinkUrl, '#')) $headerLinkUrl = 'index.php'.$headerLinkUrl; ?>
        <?php if ($headerLinkUrl !== ''): ?><a class="header-type-link-<?= (int)$headerLinkIndex ?>" href="<?=e($headerLinkUrl)?>"><?=$headerLinkLabel?></a><?php else: ?><span class="header-type-link-<?= (int)$headerLinkIndex ?>"><?=$headerLinkLabel?></span><?php endif; ?>
      <?php endforeach; ?>
      <a href="index.php#sign-in-modal" id="open-sign-in">Sign in</a><a class="btn" href="index.php#create-account-modal" id="open-create-account">Join the Community</a>
    </nav>
  </div>
</header>
<main class="page">
<?php if (!$article): ?>
<section class="not-found"><div class="eyebrow">404 · Story unavailable</div><h1>We couldn't find this story.</h1><p>The article may have been removed, unpublished, or the link may be incorrect.</p><a class="btn" href="index.php#latest">Return to Articles</a></section>
<?php else: ?>
<article>
<div class="eyebrow"><?=e((string)$article['category_name'])?><?php if(!empty($article['subcategory_name'])): ?> · <?=e((string)$article['subcategory_name'])?><?php endif; ?></div>
<h1 class="article-title"><?=e((string)$article['title'])?></h1>
<?php if(!empty($article['excerpt'])): ?><p class="excerpt"><?=e((string)$article['excerpt'])?></p><?php endif; ?>
<div class="meta"><span>By <?=e((string)$article['full_name'])?></span><?php if($publishedDate): ?><span><?=e(date('F j, Y', strtotime((string)$publishedDate)))?></span><?php endif; ?></div>
<?php if(!empty($article['cover_photo'])): ?><figure class="cover"><img src="public/uploads/articles/cover_photos/<?=e(rawurlencode((string)$article['cover_photo']))?>" alt="<?=e((string)$article['title'])?> cover photo"></figure><?php endif; ?>
<div class="article-content"><?=sanitize_rich_content((string)$article['body'])?></div>
</article>
<?php endif; ?>
</main>
<footer><div class="wrap footer-grid">
  <div><b><?=e($publicFooter['brand'])?></b><p><?=e($publicFooter['description'])?></p><p><?=e(str_replace('{year}',date('Y'),$publicFooter['copyright']))?></p><?php $footerItemStyle='display:block;margin:8px 0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;font-weight:400;font-style:normal;line-height:1.65;letter-spacing:.01em;color:#9eaca2;text-decoration:none;'; ?>
    <?php foreach(($publicFooter['brand_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?>
  </div>
  <div><b><?=e($publicFooter['explore_title'])?></b><?php foreach(($publicFooter['explore_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
  <div><b><?=e($publicFooter['discover_title'])?></b><?php foreach(($publicFooter['discover_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
</div></footer>
</body>
</html>
