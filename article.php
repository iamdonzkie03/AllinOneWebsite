<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rich-content.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
<title><?=e($pageTitle)?> | Z.C. commonplace</title>
<style>
:root{color-scheme:dark;--bg:#100b0e;--panel:#1b1116;--line:rgba(211,157,174,.17);--text:#f7edf0;--muted:#bcaab1;--red:#a51f3d;--accent:#e1a0b1}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 50% 0,rgba(112,22,48,.24),transparent 48%),var(--bg);color:var(--text);font-family:Ubuntu,Arial,sans-serif;line-height:1.7}.site-header{border-bottom:1px solid var(--line);background:rgba(15,9,12,.88)}.header-inner{max-width:1050px;margin:auto;padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{font-size:20px;font-weight:700;letter-spacing:.02em;color:var(--text);text-decoration:none}.brand span{color:var(--accent)}.back-link{color:#f0c5d0;text-decoration:none;font-size:13px}.back-link:hover,.story-read-link:hover{text-decoration:underline}.page{max-width:850px;margin:0 auto;padding:54px 24px 90px}.eyebrow{color:var(--accent);font-size:11px;text-transform:uppercase;letter-spacing:.17em;font-weight:700}.article-title{font-size:clamp(32px,6vw,54px);line-height:1.12;letter-spacing:-.035em;margin:14px 0 16px}.excerpt{font-size:18px;color:#d0bec5;line-height:1.65;margin:0 0 22px}.meta{display:flex;flex-wrap:wrap;gap:8px 18px;color:var(--muted);font-size:12px;padding-bottom:26px;border-bottom:1px solid var(--line)}.cover{margin:30px 0 36px;border:1px solid var(--line);border-radius:15px;overflow:hidden;background:var(--panel)}.cover img{display:block;width:100%;max-height:540px;object-fit:cover}.article-content{font-size:17px;line-height:1.85;color:#eee2e7;overflow-wrap:anywhere}.article-content h2,.article-content h3,.article-content h4{color:#fff;line-height:1.3;margin:1.7em 0 .65em}.article-content p{margin:0 0 1.25em}.article-content a{color:#f0a6bb}.article-content img{max-width:100%;height:auto;border-radius:10px}.article-content blockquote{margin:1.5em 0;padding:12px 20px;border-left:3px solid var(--red);background:rgba(255,255,255,.035);color:#d8c4cc}.article-content table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}.article-content th,.article-content td{border:1px solid var(--line);padding:9px 12px;text-align:left}.not-found{text-align:center;padding:70px 24px;background:var(--panel);border:1px solid var(--line);border-radius:16px}.not-found h1{font-size:30px}.btn{display:inline-flex;margin-top:14px;padding:10px 17px;border-radius:999px;background:linear-gradient(135deg,#8e1734,#541023);color:#fff;text-decoration:none;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,.12)}@media(max-width:600px){.header-inner{padding:16px}.page{padding:38px 18px 65px}.excerpt{font-size:16px}.article-content{font-size:16px}}
</style>
</head>
<body>
<header class="site-header"><div class="header-inner"><a class="brand" href="index.php">Z.C. <span>commonplace</span></a><a class="back-link" href="index.php#latest">← Back to Articles</a></div></header>
<main class="page">
<?php if (!$article): ?>
<section class="not-found"><div class="eyebrow">404 · Story unavailable</div><h1>We couldn't find this story.</h1><p style="color:var(--muted)">The article may have been removed, unpublished, or the link may be incorrect.</p><a class="btn" href="index.php#latest">Return to Articles</a></section>
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
</body>
</html>
