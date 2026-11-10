<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();
if (($user['role'] ?? '') === 'system_admin') { header('Location: admin.php'); exit; }
if (($user['account_status'] ?? '') !== 'approved') { http_response_code(403); exit('Account approval required.'); }

$id = (int)($_GET['id'] ?? 0);
$item = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM content WHERE id=? AND owner_id=?');
    $stmt->execute([$id, (int)$user['id']]);
    $item = $stmt->fetch();
    if (!$item) { http_response_code(404); exit('Content not found.'); }
}
$categories = $pdo->query("SELECT id,name,slug FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY CASE WHEN slug='uncategorized' THEN 1 ELSE 0 END,name")->fetchAll();
$subcategories = $pdo->query("SELECT id,name,parent_id FROM categories WHERE is_active=1 AND parent_id IS NOT NULL ORDER BY name")->fetchAll();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $title = trim((string)($_POST['title'] ?? ''));
    $excerpt = trim((string)($_POST['excerpt'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $subcategoryId = (int)($_POST['subcategory_id'] ?? 0);
    $type = (string)($_POST['content_type'] ?? 'article');
    $price = trim((string)($_POST['price'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $action = (string)($_POST['action'] ?? 'draft');

    $categoryCheck = $pdo->prepare('SELECT id FROM categories WHERE id=? AND is_active=1 AND parent_id IS NULL');
    $categoryCheck->execute([$categoryId]);
    $validCategory = (bool)$categoryCheck->fetch();
    $subcategoryValue = null;
    $validSubcategory = true;
    if ($subcategoryId > 0) {
        $subcategoryCheck = $pdo->prepare('SELECT id FROM categories WHERE id=? AND parent_id=? AND is_active=1');
        $subcategoryCheck->execute([$subcategoryId, $categoryId]);
        $validSubcategory = (bool)$subcategoryCheck->fetch();
        if ($validSubcategory) $subcategoryValue = $subcategoryId;
    }
    if ($title === '' || !$validCategory || !$validSubcategory || !in_array($type, ['article','product','property','video','news'], true)) {
        $error = 'Enter a title, choose a category, and select a subcategory that belongs to that category (if applicable).';
    } else {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') . '-' . bin2hex(random_bytes(3));
        $status = $action === 'submit' ? 'pending_review' : 'draft';
        $priceValue = $price === '' ? null : (float)$price;
        if ($id > 0) {
            $save = $pdo->prepare('UPDATE content SET category_id=?,subcategory_id=?,content_type=?,title=?,slug=?,excerpt=?,body=?,price=?,location=?,status=? WHERE id=? AND owner_id=?');
            $save->execute([$categoryId,$subcategoryValue,$type,$title,$slug,$excerpt,$body,$priceValue,$location,$status,$id,(int)$user['id']]);
        } else {
            $save = $pdo->prepare('INSERT INTO content(owner_id,category_id,subcategory_id,content_type,title,slug,excerpt,body,price,location,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $save->execute([(int)$user['id'],$categoryId,$subcategoryValue,$type,$title,$slug,$excerpt,$body,$priceValue,$location,$status]);
        }
        header('Location: workspace.php');
        exit;
    }
}
$selectedCategory = (int)($_POST['category_id'] ?? ($item['category_id'] ?? 0));
$selectedSubcategory = (int)($_POST['subcategory_id'] ?? ($item['subcategory_id'] ?? 0));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $item ? 'Edit content' : 'Create content' ?></title><link rel="stylesheet" href="assets/css/site.css"></head>
<body>
<header><div class="wrap nav"><a class="brand" href="../index.php">commonplace<span>.</span></a><a href="workspace.php">← Workspace</a></div></header>
<main class="wrap" style="max-width:900px;padding:38px 0"><div class="panel">
<div class="eyebrow">Creator studio</div><h1 style="font:40px Georgia,serif"><?= $item ? 'Edit your content' : 'Create something new' ?></h1>
<p class="muted">Save a private draft or submit it for administrator review. Every item must belong to a category. A subcategory is optional.</p>
<?php if ($error !== ''): ?><p style="color:#e4778d"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<div class="form-grid">
<div class="full"><label>Title *</label><input class="field" name="title" required maxlength="220" value="<?=e((string)($_POST['title'] ?? ($item['title'] ?? '')))?>"></div>
<div><label>Category *</label><select class="field" name="category_id" id="creator-category" required><option value="">Select a category</option><?php foreach($categories as $cat): ?><option value="<?=(int)$cat['id']?>" <?=$selectedCategory===(int)$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select></div>
<div><label>Subcategory (optional)</label><select class="field" name="subcategory_id" id="creator-subcategory"><option value="">No subcategory — directly under category</option><?php foreach($subcategories as $sub): ?><option value="<?=(int)$sub['id']?>" data-parent="<?=(int)$sub['parent_id']?>" <?=$selectedSubcategory===(int)$sub['id']?'selected':''?>><?=e($sub['name'])?></option><?php endforeach; ?></select></div>
<div><label>Content type</label><select class="field" name="content_type"><?php foreach(['article','product','property','video','news'] as $type): ?><option value="<?=$type?>" <?=($_POST['content_type'] ?? ($item['content_type'] ?? 'article'))===$type?'selected':''?>><?=ucfirst($type)?></option><?php endforeach; ?></select></div>
<div class="full"><label>Short description</label><input class="field" name="excerpt" maxlength="500" value="<?=e((string)($_POST['excerpt'] ?? ($item['excerpt'] ?? '')))?>"></div>
<div class="full"><label>Body / details</label><textarea class="field" name="body" rows="9"><?=e((string)($_POST['body'] ?? ($item['body'] ?? '')))?></textarea></div>
<div><label>Price (optional, PHP)</label><input class="field" name="price" type="number" min="0" step=".01" value="<?=e((string)($_POST['price'] ?? ($item['price'] ?? '')))?>"></div>
<div><label>Location (optional)</label><input class="field" name="location" value="<?=e((string)($_POST['location'] ?? ($item['location'] ?? '')))?>"></div>
</div>
<p style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn light" name="action" value="draft">Save draft</button><button class="btn" name="action" value="submit">Submit for review</button></p>
</form></div></main>
<script>
(() => {
 const category=document.getElementById('creator-category');
 const subcategory=document.getElementById('creator-subcategory');
 if(!category||!subcategory)return;
 const filter=()=>{[...subcategory.options].forEach(option=>{if(!option.value){option.hidden=false;return;}option.hidden=option.dataset.parent!==category.value;if(option.hidden&&option.selected)subcategory.value='';});};
 category.addEventListener('change',filter);filter();
})();
</script>
</body></html>
