<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

$settingsRow = $pdo->query("SELECT content_json FROM site_sections_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$settings = json_decode((string)($settingsRow['content_json'] ?? ''), true);
$panels = is_array($settings['mega_menu']['panels'] ?? null) ? $settings['mega_menu']['panels'] : [];
$panelIndex = filter_input(INPUT_GET, 'panel', FILTER_VALIDATE_INT);
if ($panelIndex === false || $panelIndex === null || !isset($panels[$panelIndex]) || !is_array($panels[$panelIndex])) {
    http_response_code(404);
    exit('Forum panel not found.');
}
$panel = $panels[$panelIndex];
$panelTitle = trim((string)($panel['title'] ?? '')) ?: 'Untitled panel';
$panelUrl = trim((string)($panel['url'] ?? ''));
$panelSlug = '';
$queryString = (string)(parse_url($panelUrl, PHP_URL_QUERY) ?: '');
parse_str($queryString, $panelQuery);
foreach (['module', 'category'] as $key) {
    if (!empty($panelQuery[$key]) && is_string($panelQuery[$key])) {
        $panelSlug = trim($panelQuery[$key]);
        break;
    }
}
if ($panelSlug === '') {
    $panelSlug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', strtolower($panelTitle)), '-');
}
$categoryStmt = $pdo->prepare('SELECT id,name,slug FROM categories WHERE slug=? AND parent_id IS NULL LIMIT 1');
$categoryStmt->execute([$panelSlug]);
$category = $categoryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$posts = [];
if ($category) {
    $postStmt = $pdo->prepare("SELECT c.id,c.title,c.excerpt,c.content_type,c.status,c.created_at,c.updated_at,c.published_at,u.full_name,u.role,cat.name AS category_name,sub.name AS subcategory_name
        FROM content c
        INNER JOIN users u ON u.id=c.owner_id
        INNER JOIN categories cat ON cat.id=c.category_id
        LEFT JOIN categories sub ON sub.id=c.subcategory_id
        WHERE c.category_id=? AND u.role IN ('system_admin','creator')
        ORDER BY COALESCE(c.updated_at,c.created_at) DESC,c.id DESC");
    $postStmt->execute([(int)$category['id']]);
    $posts = $postStmt->fetchAll(PDO::FETCH_ASSOC);
}
function forumStatusLabel(string $status): string {
    return $status === 'published' ? 'Published' : 'Unpublished';
}
function forumDateLabel(?string $value): string {
    if (!$value) return '—';
    $time = strtotime($value);
    return $time ? date('F d, Y · g:i A', $time) : '—';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($panelTitle)?> · Forum Content</title>
<link rel="stylesheet" href="assets/css/site.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{color-scheme:dark}body{font-family:Ubuntu,sans-serif;background:#0c090c;color:#f5e9ed;margin:0}
.forum-content-wrap{width:min(1240px,calc(100% - 40px));margin:0 auto;padding:34px 0 60px}
.forum-content-top{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:26px}
.forum-content-top .eyebrow{color:#d8b477;font-size:10px;letter-spacing:2px;text-transform:uppercase}
.forum-content-top h1{font-size:clamp(27px,4vw,40px);margin:7px 0 8px;color:#fff}
.forum-content-top p{margin:0;color:#bdaeb4;font-size:13px}
.forum-content-actions{display:flex;gap:9px;flex-wrap:wrap}
.forum-content-panel{border:1px solid rgba(216,180,119,.18);border-radius:14px;background:linear-gradient(145deg,rgba(37,20,29,.9),rgba(17,12,17,.96));overflow:hidden}
.forum-content-table{width:100%;border-collapse:collapse;min-width:760px}
.forum-content-table th,.forum-content-table td{padding:13px 15px;text-align:left;border-bottom:1px solid rgba(255,255,255,.08);font-size:12px;vertical-align:top}
.forum-content-table th{color:#d8b477;font-size:11px;text-transform:uppercase;letter-spacing:.7px;background:rgba(255,255,255,.025)}
.forum-content-table td{color:#e9dfe3}
.forum-content-table td strong{display:block;color:#fff;font-size:13px;margin-bottom:4px}
.forum-muted{color:#a99aa1;font-size:11px}
.forum-status{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap}
.forum-status.published{color:#9ce0b2;background:rgba(46,160,90,.14);border:1px solid rgba(91,202,130,.25)}
.forum-status.unpublished{color:#f2c77e;background:rgba(190,129,44,.13);border:1px solid rgba(216,180,119,.25)}
.forum-empty{padding:38px 20px;text-align:center;color:#bdaeb4;font-size:13px}
.forum-table-scroll{overflow-x:auto}
@media(max-width:700px){.forum-content-wrap{width:calc(100% - 28px);padding-top:24px}.forum-content-top{align-items:flex-start}.forum-content-table th,.forum-content-table td{padding:11px}}
</style>
</head>
<body>
<main class="forum-content-wrap">
<header class="forum-content-top">
 <div><div class="eyebrow">Forum · Panel Content</div><h1><?=e($panelTitle)?></h1><p>Published and unpublished posts created by the administrator and creators under this panel.</p></div>
 <div class="forum-content-actions"><a class="btn light" href="admin.php?section=page-management&amp;tab=sections&amp;section_tab=mega_menu">← Back to Forum</a></div>
</header>
<section class="forum-content-panel">
<?php if (!$category): ?>
 <div class="forum-empty">This panel is not currently linked to an active category. Edit its panel URL to use the matching category or module slug to display its posts here.</div>
<?php elseif (!$posts): ?>
 <div class="forum-empty">No posts have been created under this panel yet.</div>
<?php else: ?>
 <div class="forum-table-scroll"><table class="forum-content-table">
 <thead><tr><th>Post Title</th><th>Author</th><th>Content Type</th><th>Publication</th><th>Workflow Status</th><th>Last Updated</th></tr></thead>
 <tbody>
 <?php foreach ($posts as $post): $isPublished=(string)$post['status']==='published'; ?>
 <tr>
  <td><strong><?=e((string)$post['title'])?></strong><?php if(trim((string)($post['excerpt']??''))!==''): ?><span class="forum-muted"><?=e((string)$post['excerpt'])?></span><?php endif; ?><?php if(!empty($post['subcategory_name'])): ?><div class="forum-muted">↳ <?=e((string)$post['subcategory_name'])?></div><?php endif; ?></td>
  <td><?=e((string)$post['full_name'])?><div class="forum-muted"><?=e(ucwords(str_replace('_',' ',(string)$post['role'])))?></div></td>
  <td><?=e(ucfirst((string)$post['content_type']))?></td>
  <td><span class="forum-status <?=$isPublished?'published':'unpublished'?>"><?=forumStatusLabel((string)$post['status'])?></span></td>
  <td><?=e(ucwords(str_replace('_',' ',(string)$post['status'])))?></td>
  <td><?=e(forumDateLabel((string)($post['updated_at']??$post['created_at']??'')))?></td>
 </tr>
 <?php endforeach; ?>
 </tbody></table></div>
<?php endif; ?>
</section>
</main>
</body>
</html>
