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

$search = trim((string)($_GET['q'] ?? ''));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$where = "c.content_type='article' AND c.status='published' AND cat.is_active=1";
$params = [];
if ($search !== '') {
    $where .= " AND (c.title LIKE ? OR c.excerpt LIKE ? OR u.full_name LIKE ? OR cat.name LIKE ? OR sub.name LIKE ?)";
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term];
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id JOIN users u ON u.id=c.owner_id WHERE {$where}");
$countStmt->execute($params);
$totalArticles = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalArticles / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$stmt = $pdo->prepare("SELECT c.id,c.title,c.excerpt,c.cover_photo,c.created_at,c.published_at,cat.name AS category_name,sub.name AS subcategory_name,u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id JOIN users u ON u.id=c.owner_id WHERE {$where} ORDER BY COALESCE(c.published_at,c.created_at) DESC, c.id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'All Stories';
$pageUrl = static function(int $page) use ($search): string {
    $query = ['page' => $page];
    if ($search !== '') $query['q'] = $search;
    return 'all-stories.php?' . http_build_query($query);
};
$paginationStart = max(1, min($currentPage - 2, $totalPages - 4));
$paginationEnd = min($totalPages, $paginationStart + 4);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Browse all published stories from Z.C. commonplace, arranged from newest to oldest.">
<title>All Stories | Z.C. commonplace</title>
<link rel="stylesheet" href="public/assets/css/site.css?v=all-stories-20261110">
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

.page{max-width:1200px}
.all-stories-heading{display:flex;align-items:end;justify-content:space-between;gap:24px;margin-bottom:28px;padding-bottom:24px;border-bottom:1px solid rgba(211,157,174,.17)}
.all-stories-heading h1{font:500 clamp(38px,6vw,58px)/1.05 Georgia,serif;letter-spacing:-1.8px;margin:10px 0;color:#fff}
.all-stories-heading p{max-width:650px;color:#bcaab1;font-size:14px;line-height:1.75;margin:0}
.all-stories-count{color:#e6a0b2;font-size:12px;white-space:nowrap}
.all-stories-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}
.all-stories-grid .story{height:100%;display:flex;flex-direction:column}
.all-stories-grid .story-body{display:flex;flex:1;flex-direction:column}
.all-stories-grid .story-foot{margin-top:auto}
.all-stories-grid .story-img{height:210px}
.all-stories-empty{grid-column:1/-1;text-align:center;padding:55px 24px;background:#1b1116;border:1px solid rgba(211,157,174,.17);border-radius:16px}
.all-stories-empty h2{font:30px Georgia,serif;color:#fff}
.all-stories-empty p{color:#bcaab1}
.all-stories-back{display:inline-block;margin-top:22px;color:#f0a6bb;font-size:12px}
@media(max-width:850px){.all-stories-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page{padding:42px 20px 70px}}
@media(max-width:700px){#public-site-header .nav .navlinks{flex-wrap:wrap;white-space:normal;gap:12px}#public-site-header .header-text-inner{padding:9px 14px}}
@media(max-width:560px){.all-stories-grid{grid-template-columns:1fr}.page{padding:36px 14px 58px}.all-stories-heading{align-items:flex-start;flex-direction:column;gap:12px}.all-stories-grid .story-img{height:195px}}

.stories-search{position:relative;display:flex;align-items:center;max-width:620px;margin:0 0 18px;background:rgba(31,17,24,.92);border:1px solid rgba(211,157,174,.23);border-radius:999px;min-height:52px;transition:border-color .2s,box-shadow .2s}
.stories-search:focus-within{border-color:rgba(221,111,139,.8);box-shadow:0 0 0 4px rgba(165,31,61,.13),0 10px 28px rgba(0,0,0,.18)}
.stories-search-icon{width:48px;flex:0 0 48px;text-align:center;color:#e5a2b5;font-size:28px;line-height:1;transform:rotate(-15deg)}
.stories-search input{width:100%;min-width:0;border:0!important;outline:0!important;background:transparent!important;color:#fff!important;padding:14px 12px 14px 0!important;font:13px Ubuntu,sans-serif!important;box-shadow:none!important}
.stories-search input::placeholder{color:#9e8992}
.stories-search-clear{width:38px;flex:0 0 38px;color:#dba0b1;text-decoration:none;text-align:center;font-size:24px;line-height:1}
.stories-results-line{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 22px;color:#9e8992;font-size:11px}
.stories-results-line strong{color:#e8b0bf;font-weight:600}
.stories-pagination{display:flex;align-items:center;justify-content:center;gap:14px;margin:38px 0 12px;padding:16px 18px;border:1px solid rgba(211,157,174,.16);border-radius:15px;background:linear-gradient(135deg,rgba(43,21,31,.78),rgba(20,13,17,.88));box-shadow:0 12px 32px rgba(0,0,0,.16)}
.page-numbers{display:flex;align-items:center;justify-content:center;gap:7px}
.page-numbers a,.page-numbers .current,.page-ellipsis{display:grid;place-items:center;min-width:36px;height:36px;padding:0 9px;border-radius:10px;font-size:12px;text-decoration:none}
.page-numbers a{color:#d9c4cc;border:1px solid transparent;transition:background .18s,border-color .18s,color .18s}
.page-numbers a:hover{background:rgba(165,31,61,.17);border-color:rgba(221,111,139,.32);color:#fff}
.page-numbers .current{background:linear-gradient(145deg,#a51f3d,#641326);border:1px solid rgba(255,155,177,.34);color:#fff;font-weight:700;box-shadow:0 5px 14px rgba(120,18,43,.28)}
.page-ellipsis{min-width:18px;color:#806b74}
.page-control{display:flex;align-items:center;gap:7px;padding:0 10px;height:36px;border-radius:9px;color:#e5a2b5;text-decoration:none;font-size:11px;white-space:nowrap;transition:background .18s,color .18s}
.page-control:not(.disabled):hover{background:rgba(165,31,61,.17);color:#fff}
.page-control.disabled{color:#69545e;cursor:default}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:560px){.stories-search{min-height:48px}.stories-pagination{gap:4px;padding:10px 6px;margin-top:28px}.page-numbers{gap:3px}.page-numbers a,.page-numbers .current{min-width:31px;height:33px;padding:0 6px}.page-control{padding:0 5px;font-size:10px}.page-control span{display:none}.stories-results-line{font-size:10px}}
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
<div class="all-stories-heading">
  <div><div class="eyebrow">The latest from our community</div><h1>All Stories</h1><p>Explore every published article on Z.C. commonplace, arranged from the newest publication to the oldest.</p></div>
  <div class="all-stories-count"><?=number_format($totalArticles)?> <?= $totalArticles === 1 ? 'published story' : 'published stories'?></div>
</div>
<form class="stories-search" id="stories-search-form" method="get" action="all-stories.php" role="search">
  <label class="sr-only" for="stories-search-input">Search stories</label>
  <span class="stories-search-icon" aria-hidden="true">⌕</span>
  <input id="stories-search-input" type="search" name="q" value="<?=e($search)?>" placeholder="Search stories, authors, or categories..." autocomplete="off">
  <?php if ($search !== ''): ?><a class="stories-search-clear" href="all-stories.php" aria-label="Clear search">×</a><?php endif; ?>
</form>
<div class="stories-results-line">
  <span><?= $totalArticles ? 'Showing ' . number_format($offset + 1) . '–' . number_format(min($offset + count($articles), $totalArticles)) . ' of ' . number_format($totalArticles) . ' stories' : 'No stories found' ?></span>
  <?php if ($search !== ''): ?><span>Results for <strong><?=e($search)?></strong></span><?php endif; ?>
</div>
<div class="all-stories-grid">
<?php if (!$articles): ?>
  <div class="all-stories-empty"><div class="eyebrow"><?= $search !== '' ? 'Try another search' : 'No stories yet' ?></div><h2><?= $search !== '' ? 'No matching stories found.' : 'Good stories start with a community.' ?></h2><p><?= $search !== '' ? 'We could not find a published article matching “' . e($search) . '”. Try another keyword.' : 'There are no published articles to display at the moment.' ?></p><?php if ($search !== ''): ?><a class="btn" href="all-stories.php">Clear Search</a><?php else: ?><a class="btn" href="index.php#create-account-modal">Join the Community</a><?php endif; ?></div>
<?php else: ?>
<?php foreach ($articles as $i => $item): ?>
<article class="story">
  <a class="story-img <?= $i%3===0?'art':($i%3===1?'home':'news') ?>" href="article.php?id=<?= (int)$item['id'] ?>" aria-label="Read <?=e((string)$item['title'])?>">
  <?php if (!empty($item['cover_photo'])): ?><img src="public/uploads/articles/cover_photos/<?=e(rawurlencode((string)$item['cover_photo']))?>" alt="<?=e((string)$item['title'])?> cover photo"><?php else: ?><?=e(strtoupper((string)$item['category_name']))?><?php endif; ?>
  </a>
  <div class="story-body">
    <div class="tag"><?=e((string)$item['category_name'])?><?php if (!empty($item['subcategory_name'])): ?> · <?=e((string)$item['subcategory_name'])?><?php endif; ?></div>
    <h3><a href="article.php?id=<?= (int)$item['id'] ?>"><?=e((string)$item['title'])?></a></h3>
    <p><?=e((string)($item['excerpt'] ?? ''))?></p>
    <div class="story-foot"><span>By <?=e((string)$item['full_name'])?></span><a class="story-read-link" href="article.php?id=<?= (int)$item['id'] ?>">Read Story ↗</a></div>
  </div>
</article>
<?php endforeach; ?>
<?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<nav class="stories-pagination" aria-label="Stories pagination">
  <?php if ($currentPage > 1): ?><a class="page-control" href="<?=e($pageUrl($currentPage - 1))?>" aria-label="Previous page">‹ <span>Previous</span></a><?php else: ?><span class="page-control disabled" aria-disabled="true">‹ <span>Previous</span></span><?php endif; ?>
  <div class="page-numbers">
    <?php if ($paginationStart > 1): ?><a href="<?=e($pageUrl(1))?>">1</a><?php if ($paginationStart > 2): ?><span class="page-ellipsis">…</span><?php endif; ?><?php endif; ?>
    <?php for ($p = $paginationStart; $p <= $paginationEnd; $p++): ?>
      <?php if ($p === $currentPage): ?><span class="current" aria-current="page"><?=$p?></span><?php else: ?><a href="<?=e($pageUrl($p))?>"><?=$p?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($paginationEnd < $totalPages): ?><?php if ($paginationEnd < $totalPages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?><a href="<?=e($pageUrl($totalPages))?>"><?=$totalPages?></a><?php endif; ?>
  </div>
  <?php if ($currentPage < $totalPages): ?><a class="page-control" href="<?=e($pageUrl($currentPage + 1))?>" aria-label="Next page"><span>Next</span> ›</a><?php else: ?><span class="page-control disabled" aria-disabled="true"><span>Next</span> ›</span><?php endif; ?>
</nav>
<?php endif; ?>
<a class="all-stories-back" href="index.php#latest">← Back to featured stories</a>
</main>
<footer><div class="wrap footer-grid">
  <div><b><?=e($publicFooter['brand'])?></b><p><?=e($publicFooter['description'])?></p><p><?=e(str_replace('{year}',date('Y'),$publicFooter['copyright']))?></p><?php $footerItemStyle='display:block;margin:8px 0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;font-weight:400;font-style:normal;line-height:1.65;letter-spacing:.01em;color:#9eaca2;text-decoration:none;'; ?>
    <?php foreach(($publicFooter['brand_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?>
  </div>
  <div><b><?=e($publicFooter['explore_title'])?></b><?php foreach(($publicFooter['explore_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
  <div><b><?=e($publicFooter['discover_title'])?></b><?php foreach(($publicFooter['discover_links'] ?? []) as $footerLink): $footerUrl=trim((string)($footerLink['url'] ?? '')); if(str_starts_with($footerUrl,'?')||str_starts_with($footerUrl,'#'))$footerUrl='index.php'.$footerUrl; ?><?php if($footerUrl !== ''): ?><a class="footer-item" style="<?=e($footerItemStyle)?>" href="<?=e($footerUrl)?>"><?=e($footerLink['label'])?></a><?php else: ?><span class="footer-text-item footer-item" style="<?=e($footerItemStyle)?>"><?=e($footerLink['label'])?></span><?php endif; ?><?php endforeach; ?></div>
</div></footer>

<script>
(() => {
  const form = document.getElementById('stories-search-form');
  const input = document.getElementById('stories-search-input');
  if (!form || !input) return;
  let timer;
  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      const url = new URL(form.action, window.location.href);
      const value = input.value.trim();
      if (value) url.searchParams.set('q', value);
      else url.searchParams.delete('q');
      url.searchParams.delete('page');
      window.location.assign(url.toString());
    }, 350);
  });
})();
</script></body>
</html>
