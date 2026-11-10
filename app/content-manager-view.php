<?php
// Load the rich-content sanitizer for article editing in the Admin Content Manager.
require_once __DIR__ . '/rich-content.php';

// Included by public/admin.php inside the Page Management → Contents panel.
// Keep this view self-healing for older Laragon databases, even when the
// shared bootstrap migration has not yet been applied to the local copy.
$cmHasColumn = static function(string $table, string $column) use ($pdo): bool {
    $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([$table, $column]);
    return (int)$check->fetchColumn() > 0;
};
if (!$cmHasColumn('categories', 'parent_id')) {
    $pdo->exec('ALTER TABLE categories ADD COLUMN parent_id BIGINT UNSIGNED NULL');
    $pdo->exec('ALTER TABLE categories ADD INDEX idx_categories_parent (parent_id)');
}
if (!$cmHasColumn('content', 'subcategory_id')) {
    $pdo->exec('ALTER TABLE content ADD COLUMN subcategory_id BIGINT UNSIGNED NULL');
    $pdo->exec('ALTER TABLE content ADD INDEX idx_content_subcategory (subcategory_id)');
}
$cmCategories = $pdo->query("SELECT id,name,slug,description FROM categories WHERE parent_id IS NULL AND is_active=1 ORDER BY CASE WHEN slug='uncategorized' THEN 1 ELSE 0 END, name")->fetchAll();
$cmSubcategories = $pdo->query("SELECT child.id,child.name,child.slug,child.description,child.parent_id,parent.name AS parent_name FROM categories child JOIN categories parent ON parent.id=child.parent_id ORDER BY parent.name,child.name")->fetchAll();
$cmEditCategory = null;
$cmEditSubcategory = null;
$cmEditArticle = null;
if (!empty($_GET['edit_category'])) {
    $q = $pdo->prepare("SELECT id,name,description FROM categories WHERE id=? AND parent_id IS NULL AND slug<>'uncategorized'");
    $q->execute([(int)$_GET['edit_category']]);
    $cmEditCategory = $q->fetch() ?: null;
}
if (!empty($_GET['edit_subcategory'])) {
    $q = $pdo->prepare("SELECT id,name,description,parent_id FROM categories WHERE id=? AND parent_id IS NOT NULL");
    $q->execute([(int)$_GET['edit_subcategory']]);
    $cmEditSubcategory = $q->fetch() ?: null;
}
if (!empty($_GET['edit_article'])) {
    $q = $pdo->prepare("SELECT id,title,excerpt,body,category_id,subcategory_id,status FROM content WHERE id=? AND content_type='article'");
    $q->execute([(int)$_GET['edit_article']]);
    $cmEditArticle = $q->fetch() ?: null;
}
$cmArticles = $pdo->query("SELECT c.id,c.title,c.status,c.updated_at,cat.name AS category_name,sub.name AS subcategory_name,u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id LEFT JOIN users u ON u.id=c.owner_id WHERE c.content_type='article' ORDER BY c.updated_at DESC,c.id DESC")->fetchAll();
$cmNotices = [
    'category_created'=>'Category created successfully.','category_updated'=>'Category updated successfully.','category_deleted'=>'Category deleted successfully.',
    'subcategory_created'=>'Subcategory created successfully.','subcategory_updated'=>'Subcategory updated successfully.','subcategory_deleted'=>'Subcategory deleted successfully.',
    'article_created'=>'Article created successfully.','article_updated'=>'Article updated successfully.','article_deleted'=>'Article deleted successfully.','articles_bulk_deleted'=>'Selected articles deleted successfully.','article_status_updated'=>'Article status updated successfully.',
    'category_in_use'=>'This category or subcategory is assigned to existing content.',
    'category_locked'=>'This category cannot be changed or deleted.','category_invalid'=>'Choose a valid parent category.','subcategory_invalid'=>'The selected subcategory does not belong to the selected category.',
    'subcategory_missing'=>'The subcategory could not be found.','article_invalid'=>'Enter an article title and choose a category.','article_missing'=>'The article could not be found.',
    'duplicate_or_database_error'=>'The change could not be saved. Check that the category or subcategory name is not already in use.',
    'categories_bulk_deleted'=>'Selected categories, subcategories, and their articles were deleted successfully.','nothing_selected'=>'Select at least one category or subcategory to delete.','unknown_action'=>'The requested action is not available.'
];
$cmNotice = $cmNotices[(string)($_GET['notice'] ?? '')] ?? '';
$cmEditUrl = 'admin.php?section=page-management&tab=contents';
$cmShowCategories = (string)($_GET['view'] ?? '') === 'categories';
$cmAddCategory = (string)($_GET['add_category'] ?? '') === '1';
$cmAddSubcategory = (string)($_GET['add_subcategory'] ?? '') === '1';
$cmAddArticle = (string)($_GET['add_article'] ?? '') === '1';
$cmCategoryModeUrl = 'admin.php?section=page-management&tab=contents&view=categories';
$cmArticleModeUrl = 'admin.php?section=page-management&tab=contents';
?>
<style>
.content-manager{display:grid;gap:16px}
.content-manager-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
.content-manager-card{min-width:0;padding:17px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.018)}
.content-manager-card h3{margin:0 0 12px;color:#f4e9ec;font:600 14px Ubuntu,sans-serif}
.content-manager-card .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:9px 10px;font:12px Ubuntu,sans-serif}
.content-manager-form{display:grid;gap:10px}.content-manager-form label{display:grid;gap:6px;color:#d9cbd0;font-size:11px}
.content-manager-form textarea{resize:vertical;min-height:70px}.content-manager-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:3px}
.content-manager-table-wrap{overflow-x:auto;border:1px solid rgba(190,126,145,.2);border-radius:13px;background:linear-gradient(145deg,rgba(39,22,30,.72),rgba(17,12,16,.88));box-shadow:inset 0 1px 0 rgba(255,255,255,.025),0 12px 30px rgba(0,0,0,.12)}
.content-manager-table{width:100%;border-collapse:separate;border-spacing:0;font-size:11px;min-width:620px}
.content-manager-table th,.content-manager-table td{padding:13px 14px;border-bottom:1px solid rgba(190,126,145,.13);text-align:left;vertical-align:middle}
.content-manager-table th{background:rgba(105,34,54,.18);color:#d9b9c3;font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;white-space:nowrap}
.content-manager-table th:first-child{border-top-left-radius:12px}.content-manager-table th:last-child{border-top-right-radius:12px}
.content-manager-table td{color:#eee3e7;transition:background .18s ease}
.content-manager-table tbody tr{transition:background .18s ease}
.content-manager-table tbody tr:hover td{background:rgba(133,43,68,.105)}
.content-manager-table tbody tr:last-child td{border-bottom:0}
.content-manager-table td:first-child strong{color:#fff1f4;font-size:12px;font-weight:600;letter-spacing:.01em}
.content-manager-table .muted{font-size:10px;color:#a9949c}
.content-manager-table a{color:#e4a2b1;text-decoration:none}
.content-manager-table a:hover{text-decoration:none}
.content-manager-table tbody tr.managed-subcategory-row td{background:rgba(255,255,255,.018)}
.content-manager-table tbody tr.managed-subcategory-row:hover td{background:rgba(133,43,68,.14)}
.content-manager-subcat{position:relative;padding-left:78px!important;color:#e5cbd3!important;font-weight:500}
.content-manager-subcat:before{content:"";position:absolute;left:29px;top:-1px;width:23px;height:calc(50% + 1px);border-left:2px solid rgba(205,112,139,.82);border-bottom:2px solid rgba(205,112,139,.9);border-bottom-left-radius:10px}
.content-manager-subcat:after{content:"";position:absolute;left:49px;top:calc(50% - 4px);width:9px;height:9px;border-top:2px solid #f09ab2;border-right:2px solid #f09ab2;transform:rotate(45deg)}
.content-manager-subcat .subcategory-arrow{display:none}
.content-manager-subcat .subcategory-name{color:#f1dce3;font-weight:600}
.content-manager-subcat .subcategory-name{color:#f1dce3;font-weight:600}.content-manager-notice{padding:11px 13px;border:1px solid rgba(184,50,63,.38);border-radius:9px;background:rgba(128,20,39,.13);color:#f1dce1;font-size:12px}
.content-manager-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.content-manager-section-head h3{margin:0}.content-manager-section-head>.content-manager-actions{margin-left:auto;flex-wrap:nowrap}
.content-manager-category-toolbar{justify-content:flex-end;align-items:center}
.content-manager-modal-backdrop{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(7,3,6,.78);backdrop-filter:blur(5px)}
.content-manager-modal-backdrop[hidden]{display:none!important}.content-manager-modal{width:min(100%,460px);max-height:calc(100vh - 40px);overflow-y:auto;padding:22px;border:1px solid rgba(191,89,112,.42);border-radius:16px;background:#170f14;box-shadow:0 24px 80px rgba(0,0,0,.6)}
.content-manager-modal .content-manager-section-head{margin-bottom:18px}
.content-manager-article-modal{width:min(100%,940px);max-height:92vh;overflow:auto}
.content-manager-rich-field{display:grid;gap:7px;min-width:0}
.content-manager-rich-label{color:#d9cbd0;font-size:11px;font-weight:600}
.content-manager-rich-editor{overflow:hidden;border:1px solid rgba(190,126,145,.35);border-radius:11px;background:#171115}
.content-manager-rich-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:5px;padding:9px;border-bottom:1px solid rgba(190,126,145,.22);background:linear-gradient(135deg,rgba(105,34,54,.25),rgba(22,15,19,.95))}
.content-manager-rich-toolbar button,.content-manager-rich-toolbar select,.content-manager-rich-toolbar input[type=color]{height:30px;border:1px solid rgba(190,126,145,.28);border-radius:6px;background:#24171e;color:#f4e7eb;font:11px Ubuntu,sans-serif}
.content-manager-rich-toolbar button{min-width:30px;padding:0 8px;cursor:pointer}
.content-manager-rich-toolbar button:hover{background:#542637;border-color:#a34b65}
.content-manager-rich-toolbar select{padding:0 7px;max-width:140px}
.content-manager-rich-toolbar input[type=color]{width:34px;padding:3px;cursor:pointer}
.content-manager-rich-divider{width:1px;height:22px;background:rgba(190,126,145,.3);margin:0 3px}
.content-manager-rich-canvas{min-height:340px;max-height:52vh;overflow:auto;padding:18px 20px;outline:none;color:#f4e9ed;background:#171115;font:14px/1.75 Ubuntu,sans-serif}
.content-manager-rich-canvas:focus{box-shadow:inset 0 0 0 1px rgba(199,103,133,.3)}
.content-manager-rich-canvas:empty:before{content:attr(data-placeholder);color:#8f7b83;pointer-events:none}
.content-manager-rich-canvas h2,.content-manager-rich-canvas h3,.content-manager-rich-canvas h4{color:#fff0f4;margin:1em 0 .45em}
.content-manager-rich-canvas p{margin:0 0 .85em}
.content-manager-rich-canvas img{max-width:100%;height:auto;border-radius:8px}
.content-manager-rich-canvas figure{margin:18px auto;text-align:center}
.content-manager-rich-canvas figcaption{color:#bba8b0;font-size:12px}
.content-manager-rich-canvas blockquote{margin:14px 0;padding:10px 15px;border-left:3px solid #a94c68;background:rgba(145,51,78,.13);color:#e8d4dc}
.content-manager-rich-canvas table{width:100%;border-collapse:collapse;margin:14px 0}
.content-manager-rich-canvas th,.content-manager-rich-canvas td{border:1px solid rgba(190,126,145,.35);padding:8px;text-align:left}
.content-manager-rich-canvas a{color:#f09ab2;text-decoration:underline}
.content-manager-rich-footer{display:flex;justify-content:space-between;gap:12px;padding:7px 11px;border-top:1px solid rgba(190,126,145,.2);color:#a9949c;font-size:10px}
.content-manager-rich-footer button{border:0;background:none;color:#e5a5b7;font:inherit;cursor:pointer}
@media(max-width:700px){.content-manager-rich-canvas{min-height:260px;padding:13px}.content-manager-article-modal{max-height:94vh}.content-manager-rich-toolbar{gap:4px}}
.content-manager-success-modal{text-align:center;padding:28px 24px}
.content-manager-success-icon{display:flex;align-items:center;justify-content:center;width:54px;height:54px;margin:0 auto 15px;border:2px solid #72c99a;border-radius:50%;background:rgba(56,157,103,.14);color:#8be0ae;font:700 31px Ubuntu,sans-serif;box-shadow:0 0 22px rgba(81,190,130,.13)}
.content-manager-success-modal h3{margin:0 0 11px;color:#f7e9ed;font:700 16px Ubuntu,sans-serif}
.content-manager-success-modal p{margin:0;color:#e9dce1;font:12px/1.7 Ubuntu,sans-serif;overflow-wrap:anywhere}
.content-manager-success-modal p strong{color:#fff;font-weight:700}
.content-manager-success-list{display:inline-block;max-width:100%;margin:14px auto 0;padding-left:22px;text-align:left;color:#e9dce1;font:12px/1.8 Ubuntu,sans-serif}
.content-manager-success-list ul{margin:3px 0 5px;padding-left:20px;color:#cdb8c0}
.content-manager-success-list>li{margin:4px 0}
.content-manager-success-modal .content-manager-modal-actions{justify-content:center;margin-top:22px}
.content-manager-modal-actions{justify-content:flex-end;margin-top:8px}
.content-manager-row-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.content-manager-action{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:5px 13px;border:1px solid rgba(208,139,158,.38);border-radius:999px;background:rgba(125,45,67,.16);color:#f0c4cf;font:600 10px Ubuntu,sans-serif;letter-spacing:.02em;line-height:1;text-decoration:none;cursor:pointer;transition:background .18s ease,border-color .18s ease,transform .18s ease}.content-manager-action:hover{background:rgba(154,55,81,.28);border-color:rgba(226,157,175,.7);transform:translateY(-1px)}.content-manager-action--delete{border-color:rgba(210,83,99,.32);background:rgba(139,31,48,.12);color:#eaa0aa}.content-manager-action--delete:hover{background:rgba(159,35,54,.25);border-color:rgba(226,105,119,.65)}.content-manager-action--protected{font-size:10px;color:#aa969d}.content-manager-bulk-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}.content-manager-bulk-toolbar .muted{font-size:11px}.content-manager-select-cell{width:38px;text-align:center!important}.content-manager-select-cell input{accent-color:#a83d59;width:14px;height:14px;cursor:pointer}.content-manager-delete-selected{border:1px solid rgba(210,83,99,.42);border-radius:999px;background:rgba(139,31,48,.18);color:#f0b2bb;padding:8px 15px;font:600 11px Ubuntu,sans-serif;cursor:pointer}.content-manager-delete-selected:disabled{opacity:.4;cursor:not-allowed}.content-manager-table-tools{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:0 0 12px}
.content-manager-filter,.content-manager-page-size{display:flex;align-items:center;gap:8px;color:#d9cbd0;font-size:11px}
.content-manager-filter{flex:1;max-width:360px}.content-manager-filter .field{min-width:180px}
.content-manager-page-size .field{width:76px;padding:7px}
.content-manager-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:12px}
.content-manager-status-form{display:inline-flex;margin:0}
.content-manager-status-toggle{position:relative;display:inline-flex;align-items:center;justify-content:center;min-width:112px;height:29px;padding:4px 24px;border:1px solid;border-radius:999px;font:600 10px Ubuntu,sans-serif;cursor:pointer;transition:all .18s ease}
.content-manager-status-toggle:before{content:"";position:absolute;left:5px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.35);transition:left .18s ease}
.content-manager-status-toggle.is-published{border-color:rgba(74,190,126,.65);background:rgba(39,148,88,.28);color:#a5f0c2}
.content-manager-status-toggle.is-published:before{left:calc(100% - 22px);background:#baf5ce}
.content-manager-status-toggle.is-unpublished{border-color:rgba(220,83,99,.65);background:rgba(157,37,56,.28);color:#ffb0bb}
.content-manager-status-toggle.is-unpublished:before{left:5px;background:#ffd0d5}
.content-manager-article-bulk-modal .content-manager-modal{width:min(100%,500px)}
.content-manager-page-buttons{display:flex;align-items:center;gap:8px}
.content-manager-page-buttons .btn{padding:7px 10px;font-size:11px}
@media(max-width:760px){.content-manager-section-head>.content-manager-actions{margin-left:0;flex-wrap:wrap}.content-manager-category-toolbar{justify-content:flex-start}.content-manager-table-tools{align-items:stretch;flex-direction:column}.content-manager-filter{max-width:none;align-items:stretch;flex-direction:column}.content-manager-pagination{align-items:flex-start;flex-direction:column}}
.content-manager-editor{grid-column:1/-1}.content-manager-article-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.content-manager-article-grid .full{grid-column:1/-1}
@media(max-width:760px){.content-manager-grid{grid-template-columns:1fr}.content-manager-article-grid{grid-template-columns:1fr}.content-manager-article-grid .full{grid-column:auto}.content-manager-section-head{align-items:flex-start;flex-direction:column}}
</style>
<div class="content-manager">
  <?php if ($cmNotice !== '' && !in_array((string)($_GET['notice'] ?? ''), ['category_created','category_updated','category_deleted','categories_bulk_deleted','subcategory_created','subcategory_updated','article_created','articles_bulk_deleted','article_status_updated'], true)): ?><div class="content-manager-notice"><?=e($cmNotice)?></div><?php endif; ?>
  <?php if (in_array((string)($_GET['notice'] ?? ''), ['category_created','category_updated','category_deleted','categories_bulk_deleted','subcategory_created','subcategory_updated','article_created','articles_bulk_deleted','article_status_updated'], true)): ?>
  <?php
    $cmSuccessNotice = (string)($_GET['notice'] ?? '');
    $cmIsBulkDelete = $cmSuccessNotice === 'categories_bulk_deleted';
    $cmBulkType = (string)($_GET['bulk_type'] ?? '');
    $cmBulkDetails = [];
    if ($cmIsBulkDelete && isset($_GET['bulk_details'])) {
        $decodedDetails = json_decode((string)$_GET['bulk_details'], true);
        if (is_array($decodedDetails)) $cmBulkDetails = $decodedDetails;
    }
    $cmIsSubcategorySuccess = str_starts_with($cmSuccessNotice, 'subcategory_');
    $cmIsArticleCreated = $cmSuccessNotice === 'article_created';
    $cmIsArticleBulkDelete = $cmSuccessNotice === 'articles_bulk_deleted';
    $cmIsArticleStatusUpdated = $cmSuccessNotice === 'article_status_updated';
    $cmSuccessVerb = str_ends_with($cmSuccessNotice, '_created') ? 'created' : (str_ends_with($cmSuccessNotice, '_updated') ? 'updated' : 'deleted');
    $cmBulkCategoryCount = count($cmBulkDetails);
    $cmBulkSingleName = (string)(array_key_first($cmBulkDetails) ?? '');
    $cmBulkSingleChildren = is_array($cmBulkDetails[$cmBulkSingleName] ?? null) ? $cmBulkDetails[$cmBulkSingleName] : [];
  ?>
  <div class="content-manager-modal-backdrop" id="managed-category-success-modal" role="presentation">
    <section class="content-manager-modal content-manager-success-modal" role="dialog" aria-modal="true" aria-labelledby="managed-category-success-title">
      <div class="content-manager-success-icon" aria-hidden="true">✓</div>
      <?php if ($cmIsArticleCreated): ?>
        <h3 id="managed-category-success-title">Article Successfully Created</h3>
        <p>The Article with the title <strong><?=e(trim((string)($_GET['names'] ?? '')) ?: 'Untitled')?></strong> has been successfully created.</p>
      <?php elseif ($cmIsArticleStatusUpdated): ?>
        <h3 id="managed-category-success-title">Article Status Updated</h3>
        <p>Article status updated successfully.</p>
      <?php elseif ($cmIsArticleBulkDelete): ?>
        <h3 id="managed-category-success-title">Selected Articles Successfully Deleted</h3>
        <p>The following articles have been successfully deleted:</p>
        <ul class="content-manager-success-list"><?php foreach (array_filter(array_map('trim', explode(',', (string)($_GET['names'] ?? '')))) as $articleName): ?><li><?=e($articleName)?></li><?php endforeach; ?></ul>
      <?php elseif ($cmIsBulkDelete && $cmBulkType === 'all'): ?>
        <h3 id="managed-category-success-title">All Categories and its subcategories are successfully deleted</h3>
        <p>The following categories and subcategories were deleted:</p>
      <?php elseif ($cmIsBulkDelete && $cmBulkCategoryCount === 1): ?>
        <h3 id="managed-category-success-title">Category and Subcategories Successfully Deleted</h3>
        <p>The category <strong><?=e($cmBulkSingleName)?></strong><?= $cmBulkSingleChildren ? ' and subcategory ' . e(implode(', ', $cmBulkSingleChildren)) : ' and its subcategories' ?> are successfully deleted.</p>
      <?php elseif ($cmIsBulkDelete): ?>
        <h3 id="managed-category-success-title">Categories and Subcategories Successfully Deleted</h3>
        <p>The following categories and its corresponding subcategories are successfully deleted:</p>
      <?php else: ?>
        <h3 id="managed-category-success-title"><?= $cmIsSubcategorySuccess ? 'Subcategory Successfully ' . ucfirst($cmSuccessVerb) : 'Category Successfully ' . ucfirst($cmSuccessVerb) ?></h3>
        <p>The <?= $cmIsSubcategorySuccess ? 'subcategory' : 'category' ?> <strong><?=e(trim((string)($_GET['names'] ?? '')) ?: 'selected item(s)')?></strong> has been successfully <?=e($cmSuccessVerb)?>.</p>
      <?php endif; ?>
      <?php if ($cmIsBulkDelete): ?>
        <ul class="content-manager-success-list">
          <?php foreach ($cmBulkDetails as $categoryName => $children): ?>
            <li><strong><?=e((string)$categoryName)?></strong>
              <?php if (is_array($children) && $children): ?><ul><?php foreach ($children as $childName): ?><li><?=e((string)$childName)?></li><?php endforeach; ?></ul><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="content-manager-actions content-manager-modal-actions"><a class="btn" href="<?=e(($cmIsArticleCreated || $cmIsArticleBulkDelete || $cmIsArticleStatusUpdated) ? $cmArticleModeUrl : $cmCategoryModeUrl)?>">OK</a></div>
    </section>
  </div>
  <?php endif; ?>

  <div class="content-manager-grid">
    <?php if ($cmShowCategories): ?>
    <section class="content-manager-card content-manager-editor">
      <div class="content-manager-section-head"><h3>Category List</h3>
      <div class="content-manager-actions content-manager-category-toolbar">
        <a class="btn" href="<?=e($cmCategoryModeUrl.'&add_category=1')?>">+ Add Category</a>
        <a class="btn light" href="<?=e($cmCategoryModeUrl.'&add_subcategory=1')?>">+ Add Subcategory</a>
        <a class="btn light" href="<?=e($cmArticleModeUrl)?>">← Back to Articles</a>
      </div></div>
      <?php if ($cmAddCategory || $cmEditCategory): ?>
      <div class="content-manager-modal-backdrop" id="managed-category-modal" role="presentation">
        <section class="content-manager-modal" role="dialog" aria-modal="true" aria-labelledby="managed-category-modal-title">
          <div class="content-manager-section-head"><h3 id="managed-category-modal-title"><?= $cmEditCategory ? 'Edit Category' : 'Add Category' ?></h3></div>
          <form class="content-manager-form" method="post" action="admin-content.php?session_context=admin">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_category"><input type="hidden" name="category_id" value="<?=(int)($cmEditCategory['id'] ?? 0)?>">
            <label>Category name *<input class="field" name="name" maxlength="120" required value="<?=e((string)($cmEditCategory['name'] ?? ''))?>" placeholder="Enter category name"></label>
            <label>Description (optional)<textarea class="field" name="description" maxlength="255"><?=e((string)($cmEditCategory['description'] ?? ''))?></textarea></label>
            <div class="content-manager-actions content-manager-modal-actions"><a class="btn light" href="<?=e($cmCategoryModeUrl)?>">Cancel</a><button class="btn" type="submit"><?= $cmEditCategory ? 'Update Category' : 'Save Category' ?></button></div>
          </form>
        </section>
      </div><?php endif; ?>
      <?php if ($cmAddSubcategory || $cmEditSubcategory): ?>
      <div class="content-manager-modal-backdrop" id="managed-subcategory-modal" role="presentation">
        <section class="content-manager-modal" role="dialog" aria-modal="true" aria-labelledby="managed-subcategory-modal-title">
          <div class="content-manager-section-head"><h3 id="managed-subcategory-modal-title"><?= $cmEditSubcategory ? 'Edit Subcategory' : 'Add Subcategory' ?></h3></div>
          <form class="content-manager-form" method="post" action="admin-content.php?session_context=admin">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_subcategory"><input type="hidden" name="subcategory_id" value="<?=(int)($cmEditSubcategory['id'] ?? 0)?>">
            <label>Parent category *<select class="field" name="parent_id" required><option value="">Select a category</option><?php foreach($cmCategories as $cat): if($cat['slug']==='uncategorized') continue; ?><option value="<?=(int)$cat['id']?>" <?=isset($cmEditSubcategory['parent_id'])&&(int)$cmEditSubcategory['parent_id']===(int)$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select></label>
            <label>Subcategory name *<input class="field" name="name" maxlength="120" required value="<?=e((string)($cmEditSubcategory['name'] ?? ''))?>" placeholder="Enter subcategory name"></label>
            <label>Description (optional)<textarea class="field" name="description" maxlength="255"><?=e((string)($cmEditSubcategory['description'] ?? ''))?></textarea></label>
            <div class="content-manager-actions content-manager-modal-actions"><a class="btn light" href="<?=e($cmCategoryModeUrl)?>">Cancel</a><button class="btn" type="submit"><?= $cmEditSubcategory ? 'Update Subcategory' : 'Save Subcategory' ?></button></div>
          </form>
        </section>
      </div><?php endif; ?>
      <div class="content-manager-table-tools"><label class="content-manager-filter">Filter categories<input type="search" class="field" id="managed-category-filter" placeholder="Search category or description"></label><label class="content-manager-page-size">Show<select class="field" id="managed-category-page-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label></div>
      <form id="managed-category-bulk-delete" method="post" action="admin-content.php?session_context=admin"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="bulk_delete_categories"><input type="hidden" name="select_all_categories" id="managed-category-select-all-flag" value="0"></form>
      <div class="content-manager-bulk-toolbar"><span class="muted" id="managed-category-selection" aria-live="polite">No items selected</span><button class="content-manager-delete-selected" type="button" id="managed-category-delete-selected" disabled>Delete Selected</button></div>
      <div class="content-manager-modal-backdrop" id="managed-category-delete-modal" hidden role="presentation">
        <section class="content-manager-modal" role="dialog" aria-modal="true" aria-labelledby="managed-category-delete-title" aria-describedby="managed-category-delete-description">
          <div class="content-manager-section-head"><h3 id="managed-category-delete-title">Delete Selected Items?</h3></div>
          <div style="display:grid;gap:12px;color:#e9dce1;font-size:12px;line-height:1.65">
            <p id="managed-category-delete-description" style="margin:0">You are about to permanently delete the selected category/ies.</p>
            <div style="padding:11px 13px;border:1px solid rgba(210,83,99,.35);border-radius:10px;background:rgba(139,31,48,.13);color:#f0c5cc">
              <strong>Important:</strong> Deleting a category will also delete all subcategories and articles assigned to it. This action cannot be undone.
            </div>
            <div><strong>Selected Items:</strong><ul id="managed-category-delete-list" style="margin:7px 0 0;padding-left:20px;color:#d8c2ca"></ul></div>
          </div>
          <div class="content-manager-actions content-manager-modal-actions"><button type="button" class="btn light" id="managed-category-delete-cancel">Cancel</button><button type="submit" class="content-manager-delete-selected" form="managed-category-bulk-delete" id="managed-category-delete-confirm">Delete Selected</button></div>
        </section>
      </div>
      <div class="content-manager-table-wrap"><table class="content-manager-table" id="managed-category-table"><thead><tr><th class="content-manager-select-cell"><input type="checkbox" id="managed-category-select-all" aria-label="Select all visible categories"></th><th>Category</th><th>Description</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($cmCategories as $cat): ?>
        <tr class="managed-category-row" data-search="<?=e(mb_strtolower($cat['name'].' '.(string)$cat['description']))?>"><td class="content-manager-select-cell"><?php if($cat['slug']!=='uncategorized'): ?><input type="checkbox" class="managed-category-select" name="selected_items[]" value="category:<?=(int)$cat['id']?>" form="managed-category-bulk-delete" aria-label="Select category <?=e($cat['name'])?>" data-item-name="<?=e($cat['name'])?>" data-category-id="<?=(int)$cat['id']?>"><?php endif; ?></td><td><strong><?=e($cat['name'])?></strong><?php if($cat['slug']==='uncategorized'): ?><div class="muted">Default category</div><?php endif; ?></td><td><?=e((string)$cat['description'])?></td><td><?php if($cat['slug']!=='uncategorized'): ?><div class="content-manager-row-actions"><a class="content-manager-action" href="<?=e($cmCategoryModeUrl.'&edit_category='.(int)$cat['id'])?>">Edit</a></div><?php else: ?><span class="content-manager-action--protected">Protected</span><?php endif; ?></td></tr>
        <?php foreach($cmSubcategories as $sub): if((int)$sub['parent_id']!==(int)$cat['id']) continue; ?><tr class="managed-category-row managed-subcategory-row" data-search="<?=e(mb_strtolower($sub['name'].' '.$sub['parent_name'].' '.(string)$sub['description']))?>"><td class="content-manager-select-cell"><input type="checkbox" class="managed-category-select" name="selected_items[]" value="subcategory:<?=(int)$sub['id']?>" form="managed-category-bulk-delete" aria-label="Select subcategory <?=e($sub['name'])?>" data-item-name="<?=e($sub['name'])?>" data-parent-id="<?=(int)$sub['parent_id']?>"></td><td class="content-manager-subcat"><span class="subcategory-arrow" aria-hidden="true">›</span><span class="subcategory-name"><?=e($sub['name'])?></span></td><td><?=e((string)$sub['description'])?></td><td><div class="content-manager-row-actions"><a class="content-manager-action" href="<?=e($cmCategoryModeUrl.'&edit_subcategory='.(int)$sub['id'])?>">Edit</a></div></td></tr><?php endforeach; ?>
      <?php endforeach; ?></tbody></table></div>
      <div class="content-manager-pagination"><span class="muted" id="managed-category-count" aria-live="polite"></span><div class="content-manager-page-buttons"><button type="button" class="btn light" id="managed-category-prev">Previous</button><span class="muted" id="managed-category-page"></span><button type="button" class="btn light" id="managed-category-next">Next</button></div></div>
    </section>
    <?php else: ?>
    <section class="content-manager-card content-manager-editor">
      <div class="content-manager-section-head"><h3>Articles</h3><span class="muted"><?=count($cmArticles)?> articles</span><div class="content-manager-actions"><a class="btn" href="<?=e($cmCategoryModeUrl)?>">View Categories</a><button type="button" class="btn" id="managed-add-article-toggle" aria-controls="managed-article-form-panel" aria-expanded="<?= $cmEditArticle ? "true" : "false" ?>">+ Add Article</button></div></div>
      <div class="content-manager-table-tools"><label class="content-manager-filter">Filter articles<input type="search" class="field" id="managed-article-filter" placeholder="Search title, category, author or status"></label><label class="content-manager-page-size">Show<select class="field" id="managed-article-page-size"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label></div>
      <form id="managed-article-bulk-delete" method="post" action="admin-content.php?session_context=admin"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="bulk_delete_articles"><input type="hidden" name="select_all_articles" id="managed-article-select-all-flag" value="0"></form>
      <div class="content-manager-bulk-toolbar"><span class="muted" id="managed-article-selection" aria-live="polite">No items selected</span><button class="content-manager-delete-selected" type="button" id="managed-article-delete-selected" disabled>Delete Selected</button></div>
      <div class="content-manager-modal-backdrop content-manager-article-bulk-modal" id="managed-article-delete-modal" hidden role="presentation">
        <section class="content-manager-modal" role="dialog" aria-modal="true" aria-labelledby="managed-article-delete-title">
          <div class="content-manager-section-head"><h3 id="managed-article-delete-title">Delete Selected Articles?</h3></div>
          <p style="color:#e9dce1;font-size:12px;line-height:1.65">You are about to permanently delete the selected article(s). This action cannot be undone.</p>
          <div><strong style="color:#f0dce2;font-size:12px">Selected Articles:</strong><ul id="managed-article-delete-list" style="margin:7px 0 0;padding-left:20px;color:#d8c2ca;font-size:12px;line-height:1.8"></ul></div>
          <div class="content-manager-actions content-manager-modal-actions"><button type="button" class="btn light" id="managed-article-delete-cancel">Cancel</button><button type="submit" class="content-manager-delete-selected" form="managed-article-bulk-delete">Delete Selected</button></div>
        </section>
      </div>
      <div class="content-manager-table-wrap"><table class="content-manager-table" id="managed-article-table"><thead><tr><th class="content-manager-select-cell"><input type="checkbox" id="managed-article-table-select-all" aria-label="Select all articles"></th><th>Article</th><th>Category</th><th>Status</th><th>Author</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($cmArticles as $article): ?><tr class="managed-article-row" data-search="<?=e(mb_strtolower($article['title'].' '.$article['category_name'].' '.(string)$article['subcategory_name'].' '.(string)$article['full_name'].' '.($article['status']==='published'?'published':'unpublished')))?>"><td class="content-manager-select-cell"><input type="checkbox" class="managed-article-select" name="selected_articles[]" value="<?=(int)$article['id']?>" form="managed-article-bulk-delete" aria-label="Select article <?=e($article['title'])?>" data-title="<?=e($article['title'])?>"></td><td><strong><?=e($article['title'])?></strong></td><td><?=e($article['category_name'])?><?php if($article['subcategory_name']): ?><div class="muted">↳ <?=e($article['subcategory_name'])?></div><?php endif; ?></td><td><form class="content-manager-status-form" method="post" action="admin-content.php?session_context=admin"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle_article_status"><input type="hidden" name="item_id" value="<?=(int)$article['id']?>"><button type="submit" class="content-manager-status-toggle <?=$article['status']==='published'?'is-published':'is-unpublished'?>" aria-label="Change status for <?=e($article['title'])?>"><?=e($article['status']==='published'?'Published':'Unpublished')?></button></form></td><td><?=e((string)$article['full_name'])?></td><td><?=e((string)$article['updated_at'])?></td><td><a class="content-manager-action" href="admin.php?session_context=admin&section=page-management&tab=contents&edit_article=<?=(int)$article['id']?>#pm-panel-contents">Edit</a></td></tr><?php endforeach; ?>
      <?php if(!$cmArticles): ?><tr><td colspan="7" class="muted">No articles yet. Create the first article above.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="content-manager-pagination"><span class="muted" id="managed-article-count" aria-live="polite"></span><div class="content-manager-page-buttons"><button type="button" class="btn light" id="managed-article-prev">Previous</button><span class="muted" id="managed-article-page"></span><button type="button" class="btn light" id="managed-article-next">Next</button></div></div>
    </section>
    <?php endif; ?>
    <section class="content-manager-card" id="managed-category-form-panel"<?= $cmEditCategory ? "" : " hidden" ?>>
      <div class="content-manager-section-head"><h3><?= $cmEditCategory ? 'Edit Category' : 'Create Category' ?></h3><?php if($cmEditCategory): ?><a href="<?=e($cmEditUrl)?>">Cancel edit</a><?php endif; ?></div>
      <form class="content-manager-form" method="post" action="admin-content.php?session_context=admin">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_category">
        <input type="hidden" name="category_id" value="<?=(int)($cmEditCategory['id'] ?? 0)?>">
        <label>Category name *<input class="field" name="name" maxlength="120" required placeholder="e.g. Community Stories" value="<?=e((string)($cmEditCategory['name'] ?? ''))?>"></label>
        <label>Description (optional)<textarea class="field" name="description" maxlength="255" placeholder="Briefly describe this category"><?=e((string)($cmEditCategory['description'] ?? ''))?></textarea></label>
        <div class="content-manager-actions"><button class="btn" type="submit"><?= $cmEditCategory ? 'Update Category' : '+ Create Category' ?></button></div>
      </form>
    </section>
    <?php if (!$cmShowCategories && ($cmAddArticle || $cmEditArticle)): ?>
    <div class="content-manager-modal-backdrop" id="managed-article-form-panel" role="presentation">
      <section class="content-manager-modal content-manager-article-modal" role="dialog" aria-modal="true" aria-labelledby="managed-article-modal-title">
      <div class="content-manager-section-head"><h3 id="managed-article-modal-title"><?= $cmEditArticle ? 'Edit Article' : 'Add Article' ?></h3></div>
      <form class="content-manager-form" method="post" action="admin-content.php?session_context=admin" id="managed-article-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_article">
        <input type="hidden" name="article_id" value="<?=(int)($cmEditArticle['id'] ?? 0)?>">
        <div class="content-manager-article-grid">
          <label class="full">Article title *<input class="field" name="title" maxlength="220" required placeholder="Enter article title" value="<?=e((string)($cmEditArticle['title'] ?? ''))?>"></label>
          <label>Category *<select class="field" name="category_id" id="managed-article-category" required><option value="">Select a category</option><?php foreach($cmCategories as $cat): ?><option value="<?=(int)$cat['id']?>" <?=isset($cmEditArticle['category_id'])&&(int)$cmEditArticle['category_id']===(int)$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select></label>
          <label>Subcategory (optional)<select class="field" name="subcategory_id" id="managed-article-subcategory"><option value="">No subcategory — directly under category</option><?php foreach($cmSubcategories as $sub): ?><option value="<?=(int)$sub['id']?>" data-parent="<?=(int)$sub['parent_id']?>" <?=isset($cmEditArticle['subcategory_id'])&&(int)$cmEditArticle['subcategory_id']===(int)$sub['id']?'selected':''?>><?=e($sub['parent_name'].' / '.$sub['name'])?></option><?php endforeach; ?></select></label>
          <label>Status<select class="field" name="status"><option value="published" <?=($cmEditArticle['status'] ?? '')==='published'?'selected':''?>>Published</option><option value="draft" <?=($cmEditArticle['status'] ?? 'draft')==='draft'?'selected':''?>>Unpublished</option></select></label>
          <label>Short description<input class="field" name="excerpt" maxlength="500" value="<?=e((string)($cmEditArticle['excerpt'] ?? ''))?>" placeholder="A short summary"></label>
          <div class="full content-manager-rich-field">
            <div class="content-manager-rich-label">Article body</div>
            <div class="content-manager-rich-editor" id="managed-article-rich-editor">
              <div class="content-manager-rich-toolbar" role="toolbar" aria-label="Article formatting tools">
                <select id="managed-rich-block" aria-label="Text style"><option value="P">Paragraph</option><option value="H2">Heading 2</option><option value="H3">Heading 3</option><option value="H4">Heading 4</option><option value="BLOCKQUOTE">Quote</option></select>
                <span class="content-manager-rich-divider"></span>
                <button type="button" data-command="bold" title="Bold"><strong>B</strong></button>
                <button type="button" data-command="italic" title="Italic"><em>I</em></button>
                <button type="button" data-command="underline" title="Underline"><u>U</u></button>
                <button type="button" data-command="strikeThrough" title="Strikethrough"><s>S</s></button>
                <button type="button" data-command="removeFormat" title="Clear formatting">Tx</button>
                <span class="content-manager-rich-divider"></span>
                <button type="button" data-command="justifyLeft" title="Align left">≡</button>
                <button type="button" data-command="justifyCenter" title="Center">☰</button>
                <button type="button" data-command="justifyRight" title="Align right">≡</button>
                <button type="button" data-command="justifyFull" title="Justify">▤</button>
                <span class="content-manager-rich-divider"></span>
                <button type="button" data-command="insertUnorderedList" title="Bulleted list">• List</button>
                <button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button>
                <button type="button" data-action="link" title="Insert link">Link</button>
                <button type="button" data-command="unlink" title="Remove link">Unlink</button>
                <button type="button" data-action="quote" title="Quote">❝</button>
                <button type="button" data-action="table" title="Insert table">Table</button>
                <span class="content-manager-rich-divider"></span>
                <button type="button" data-action="image" title="Upload photo">Photo</button>
                <label title="Text color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" id="managed-rich-color" value="#f4e9ed" aria-label="Text color"></label>
                <button type="button" data-command="undo" title="Undo">↶</button>
                <button type="button" data-command="redo" title="Redo">↷</button>
                <input type="file" id="managed-rich-image-input" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
              </div>
              <div id="managed-article-editor" class="content-manager-rich-canvas" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Article body editor" data-placeholder="Start writing your article. Format text, add headings, lists, links, tables and photos..."><?=sanitize_rich_content((string)($cmEditArticle['body'] ?? ''))?></div>
              <div class="content-manager-rich-footer"><span id="managed-rich-word-count">0 words</span><span>Photos: JPG, PNG, GIF or WebP · Up to 8 MB each</span></div>
            </div>
            <textarea class="field" name="body" id="managed-article-body" hidden><?=e((string)($cmEditArticle['body'] ?? ''))?></textarea>
          </div>
        </div>
        <div class="content-manager-actions content-manager-modal-actions"><a class="btn light" href="<?=e($cmArticleModeUrl)?>">Cancel</a><button class="btn" type="submit"><?= $cmEditArticle ? 'Update Article' : 'Save Article' ?></button></div>
      </form>
    </section>
    </div>
    <?php endif; ?>

  </div>
</div>
<script>
(() => {
  const addArticleButton = document.getElementById('managed-add-article-toggle');
  if (addArticleButton) addArticleButton.addEventListener('click', () => { window.location.href = 'admin.php?session_context=admin&section=page-management&tab=contents&add_article=1#pm-panel-contents'; });
  const articleTitle = document.querySelector('#managed-article-form-panel input[name="title"]');
  if (articleTitle) articleTitle.focus();

  const articleCategory = document.getElementById('managed-article-category');
  const articleSubcategory = document.getElementById('managed-article-subcategory');
  if (articleCategory && articleSubcategory) {
    const filterSubcategories = () => {
      [...articleSubcategory.options].forEach(option => {
        if (!option.value) { option.hidden = false; return; }
        option.hidden = option.dataset.parent !== articleCategory.value;
        if (option.hidden && option.selected) articleSubcategory.value = '';
      });
    };
    articleCategory.addEventListener('change', filterSubcategories);
    filterSubcategories();
  }

  const richEditor = document.getElementById('managed-article-editor');
  const richForm = document.getElementById('managed-article-form');
  if (richEditor && richForm) {
    const bodyField = document.getElementById('managed-article-body');
    const imageInput = document.getElementById('managed-rich-image-input');
    const wordCount = document.getElementById('managed-rich-word-count');
    const csrf = richForm.querySelector('input[name="csrf"]')?.value || '';
    const updateWordCount = () => {
      const words = (richEditor.innerText || '').trim().split(/\s+/).filter(Boolean).length;
      if (wordCount) wordCount.textContent = words + (words === 1 ? ' word' : ' words');
    };
    const runCommand = (command, value = null) => {
      richEditor.focus();
      document.execCommand(command, false, value);
      updateWordCount();
    };
    document.querySelectorAll('#managed-article-rich-editor [data-command]').forEach(button => {
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => runCommand(button.dataset.command));
    });
    document.getElementById('managed-rich-block')?.addEventListener('change', event => {
      runCommand('formatBlock', '<' + event.target.value + '>');
    });
    document.getElementById('managed-rich-color')?.addEventListener('input', event => runCommand('foreColor', event.target.value));
    document.querySelector('#managed-article-rich-editor [data-action="link"]')?.addEventListener('click', () => {
      const url = window.prompt('Enter the link URL (https://, http://, or mailto:):');
      if (url && /^(https?:\/\/|mailto:)/i.test(url.trim())) runCommand('createLink', url.trim());
    });
    document.querySelector('#managed-article-rich-editor [data-action="quote"]')?.addEventListener('click', () => runCommand('formatBlock', '<BLOCKQUOTE>'));
    document.querySelector('#managed-article-rich-editor [data-action="table"]')?.addEventListener('click', () => {
      const rows = Math.min(10, Math.max(1, Number(window.prompt('Number of table rows?', '3') || 0)));
      const cols = Math.min(8, Math.max(1, Number(window.prompt('Number of table columns?', '3') || 0)));
      if (!rows || !cols) return;
      let table = '<table><tbody>';
      for (let row = 0; row < rows; row++) {
        table += '<tr>';
        for (let col = 0; col < cols; col++) table += row === 0 ? '<th>Heading</th>' : '<td>Text</td>';
        table += '</tr>';
      }
      table += '</tbody></table><p><br></p>';
      richEditor.focus(); document.execCommand('insertHTML', false, table); updateWordCount();
    });
    document.querySelector('#managed-article-rich-editor [data-action="image"]')?.addEventListener('click', () => imageInput?.click());
    const insertUploadedImage = async file => {
      if (!file || !file.type.startsWith('image/')) return;
      if (file.size > 8 * 1024 * 1024) { window.alert('Please choose an image smaller than 8 MB.'); return; }
      const data = new FormData();
      data.append('csrf', csrf); data.append('action', 'upload_article_image'); data.append('image', file);
      try {
        const response = await fetch('admin-content.php?session_context=admin', {method:'POST',body:data,credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.url) throw new Error(result.message || 'The image could not be uploaded.');
        const safeAlt = (file.name || 'Article photo').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
        richEditor.focus();
        document.execCommand('insertHTML', false, '<figure><img src="' + result.url + '" alt="' + safeAlt + '"><figcaption>' + safeAlt + '</figcaption></figure><p><br></p>');
        updateWordCount();
      } catch (error) { window.alert(error.message || 'Image upload failed.'); }
    };
    imageInput?.addEventListener('change', async () => {
      if (imageInput.files?.[0]) await insertUploadedImage(imageInput.files[0]);
      imageInput.value = '';
    });
    richEditor.addEventListener('dragover', event => { if ([...event.dataTransfer.types].includes('Files')) event.preventDefault(); });
    richEditor.addEventListener('drop', async event => {
      const files = [...(event.dataTransfer.files || [])].filter(file => file.type.startsWith('image/'));
      if (files.length) { event.preventDefault(); for (const file of files) await insertUploadedImage(file); }
    });
    richEditor.addEventListener('paste', async event => {
      const files = [...(event.clipboardData?.items || [])].filter(item => item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
      if (files.length) { event.preventDefault(); for (const file of files) await insertUploadedImage(file); }
    });
    richEditor.addEventListener('input', updateWordCount);
    richForm.addEventListener('submit', () => { if (bodyField) bodyField.value = richEditor.innerHTML; });
    updateWordCount();
  }

  const articleTable = document.getElementById('managed-article-table');
  if (articleTable) {
    const rows = [...articleTable.querySelectorAll('tbody .managed-article-row')];
    const boxes = [...articleTable.querySelectorAll('.managed-article-select')];
    const filter = document.getElementById('managed-article-filter');
    const pageSize = document.getElementById('managed-article-page-size');
    const count = document.getElementById('managed-article-count');
    const pageLabel = document.getElementById('managed-article-page');
    const previous = document.getElementById('managed-article-prev');
    const next = document.getElementById('managed-article-next');
    const tableSelectAll = document.getElementById('managed-article-table-select-all');
    const deleteButton = document.getElementById('managed-article-delete-selected');
    const selectionLabel = document.getElementById('managed-article-selection');
    const selectAllFlag = document.getElementById('managed-article-select-all-flag');
    const deleteModal = document.getElementById('managed-article-delete-modal');
    const deleteList = document.getElementById('managed-article-delete-list');
    const closeModal = () => { if (deleteModal) deleteModal.hidden = true; };
    let page = 1;
    const updateSelection = () => {
      const selected = boxes.filter(box => box.checked);
      if (deleteButton) deleteButton.disabled = selected.length === 0;
      if (selectionLabel) selectionLabel.textContent = selected.length ? selected.length + ' selected' : 'No items selected';
      [tableSelectAll].forEach(control => {
        if (!control) return;
        control.checked = boxes.length > 0 && selected.length === boxes.length;
        control.indeterminate = selected.length > 0 && selected.length < boxes.length;
      });
    };
    const setAll = checked => {
      boxes.forEach(box => { box.checked = checked; });
      if (selectAllFlag) selectAllFlag.value = checked ? '1' : '0';
      updateSelection();
    };
    tableSelectAll?.addEventListener('change', () => setAll(tableSelectAll.checked));
    boxes.forEach(box => box.addEventListener('change', () => { if (selectAllFlag) selectAllFlag.value = '0'; updateSelection(); }));
    deleteButton?.addEventListener('click', () => {
      const selected = boxes.filter(box => box.checked);
      if (!selected.length || !deleteModal || !deleteList) return;
      deleteList.replaceChildren();
      selected.forEach(box => { const li = document.createElement('li'); li.textContent = box.dataset.title || 'Article'; deleteList.appendChild(li); });
      deleteModal.hidden = false;
      document.getElementById('managed-article-delete-cancel')?.focus();
    });
    document.getElementById('managed-article-delete-cancel')?.addEventListener('click', closeModal);
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && deleteModal && !deleteModal.hidden) closeModal(); });
    const renderRows = () => {
      const query = (filter?.value || '').trim().toLocaleLowerCase();
      const matching = rows.filter(row => (row.dataset.search || row.textContent).toLocaleLowerCase().includes(query));
      const size = Math.max(1, Number(pageSize?.value || 10));
      const pages = Math.max(1, Math.ceil(matching.length / size));
      page = Math.min(page, pages);
      rows.forEach(row => { row.hidden = true; });
      matching.slice((page - 1) * size, page * size).forEach(row => { row.hidden = false; });
      const start = matching.length ? (page - 1) * size + 1 : 0;
      const end = Math.min(page * size, matching.length);
      if (count) count.textContent = 'Showing ' + start + '–' + end + ' of ' + matching.length + ' entries';
      if (pageLabel) pageLabel.textContent = 'Page ' + page + ' of ' + pages;
      if (previous) previous.disabled = page <= 1;
      if (next) next.disabled = page >= pages;
    };
    filter?.addEventListener('input', () => { page = 1; renderRows(); });
    pageSize?.addEventListener('change', () => { page = 1; renderRows(); });
    previous?.addEventListener('click', () => { page = Math.max(1, page - 1); renderRows(); });
    next?.addEventListener('click', () => { page += 1; renderRows(); });
    renderRows();
    updateSelection();
  }

  const categoryTable = document.getElementById('managed-category-table');
  if (categoryTable) {
    const rows = [...categoryTable.querySelectorAll('tbody .managed-category-row')];
    const filter = document.getElementById('managed-category-filter');
    const pageSize = document.getElementById('managed-category-page-size');
    const count = document.getElementById('managed-category-count');
    const pageLabel = document.getElementById('managed-category-page');
    const previous = document.getElementById('managed-category-prev');
    const next = document.getElementById('managed-category-next');
    const selectAll = document.getElementById('managed-category-select-all');
    const deleteSelected = document.getElementById('managed-category-delete-selected');
    const selectionLabel = document.getElementById('managed-category-selection');
    const deleteModal = document.getElementById('managed-category-delete-modal');
    const deleteList = document.getElementById('managed-category-delete-list');
    const cancelDelete = document.getElementById('managed-category-delete-cancel');
    const selections = [...categoryTable.querySelectorAll('.managed-category-select')];
    const selectAllFlag = document.getElementById('managed-category-select-all-flag');
    const deleteDescription = document.getElementById('managed-category-delete-description');
    const closeDeleteModal = () => { if (deleteModal) deleteModal.hidden = true; };
    const getSelectedGroups = () => {
      const checked = selections.filter(box => box.checked);
      const selectedCategoryIds = new Set(checked.filter(box => box.value.startsWith('category:')).map(box => box.dataset.categoryId));
      const groups = new Map();
      checked.filter(box => box.value.startsWith('category:')).forEach(box => groups.set(box.dataset.itemName || 'Category', []));
      checked.filter(box => box.value.startsWith('subcategory:') && !selectedCategoryIds.has(box.dataset.parentId)).forEach(box => {
        const parentRow = rows.find(row => row.querySelector('[data-category-id="' + box.dataset.parentId + '"]'));
        const parentName = parentRow?.querySelector('td:nth-child(2) strong')?.textContent?.trim() || 'Other subcategories';
        if (!groups.has(parentName)) groups.set(parentName, []);
        groups.get(parentName).push(box.dataset.itemName || 'Subcategory');
      });
      checked.filter(box => box.value.startsWith('category:')).forEach(box => {
        const categoryId = box.dataset.categoryId;
        const categoryName = box.dataset.itemName || 'Category';
        const childBoxes = selections.filter(child => child.value.startsWith('subcategory:') && child.dataset.parentId === categoryId && child.checked);
        groups.set(categoryName, childBoxes.map(child => child.dataset.itemName || 'Subcategory'));
      });
      return {checked, groups, categoryCount: checked.filter(box => box.value.startsWith('category:')).length};
    };
    deleteSelected?.addEventListener('click', () => {
      const {checked, groups, categoryCount} = getSelectedGroups();
      if (!checked.length || !deleteModal || !deleteList) return;
      deleteList.replaceChildren();
      groups.forEach((children, categoryName) => {
        const item = document.createElement('li');
        const label = document.createElement('strong');
        label.textContent = categoryName;
        item.appendChild(label);
        if (children.length) {
          const nested = document.createElement('ul');
          children.forEach(childName => { const child = document.createElement('li'); child.textContent = childName; nested.appendChild(child); });
          item.appendChild(nested);
        }
        deleteList.appendChild(item);
      });
      if (deleteDescription) {
        deleteDescription.textContent = selectAll?.checked
          ? 'All categories and their subcategories will be permanently deleted.'
          : categoryCount === 1
            ? 'The selected category and all of its subcategories will be permanently deleted.'
            : categoryCount > 1
              ? 'The selected categories and all of their subcategories will be permanently deleted.'
              : 'You are about to permanently delete the selected subcategory/ies.';
      }
      deleteModal.hidden = false;
      document.getElementById('managed-category-delete-cancel')?.focus();
    });
    cancelDelete?.addEventListener('click', closeDeleteModal);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && deleteModal && !deleteModal.hidden) closeDeleteModal();
    });
    const updateSelection = () => {
      const checked = selections.filter(box => box.checked);
      if (deleteSelected) deleteSelected.disabled = checked.length === 0;
      if (selectionLabel) selectionLabel.textContent = checked.length + (checked.length === 1 ? ' item selected' : ' items selected');
      if (selectAll) {
        selectAll.checked = selections.length > 0 && checked.length === selections.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < selections.length;
      }
    };
    selections.forEach(box => box.addEventListener('change', updateSelection));
    selectAll?.addEventListener('change', () => {
      selections.forEach(box => { box.checked = selectAll.checked; });
      if (selectAllFlag) selectAllFlag.value = selectAll.checked ? '1' : '0';
      updateSelection();
    });
    selections.filter(box => box.value.startsWith('category:')).forEach(categoryBox => {
      categoryBox.addEventListener('change', () => {
        selections.filter(child => child.value.startsWith('subcategory:') && child.dataset.parentId === categoryBox.dataset.categoryId)
          .forEach(child => { child.checked = categoryBox.checked; });
        if (selectAllFlag) selectAllFlag.value = '0';
        updateSelection();
      });
    });
    selections.filter(box => box.value.startsWith('subcategory:')).forEach(childBox => {
      childBox.addEventListener('change', () => {
        const parentBox = selections.find(box => box.value.startsWith('category:') && box.dataset.categoryId === childBox.dataset.parentId);
        if (parentBox && !childBox.checked) parentBox.checked = false;
        if (selectAllFlag) selectAllFlag.value = '0';
        updateSelection();
      });
    });
    let page = 1;
    const renderCategoryRows = () => {
      const query = (filter?.value || '').trim().toLocaleLowerCase();
      const matching = rows.filter(row => (row.dataset.search || row.textContent).toLocaleLowerCase().includes(query));
      const size = Math.max(1, Number(pageSize?.value || 10));
      const pages = Math.max(1, Math.ceil(matching.length / size));
      page = Math.min(page, pages);
      rows.forEach(row => { row.hidden = true; });
      matching.slice((page - 1) * size, page * size).forEach(row => { row.hidden = false; });
      const start = matching.length ? (page - 1) * size + 1 : 0;
      const end = Math.min(page * size, matching.length);
      if (count) count.textContent = 'Showing ' + start + '–' + end + ' of ' + matching.length + ' entries';
      if (pageLabel) pageLabel.textContent = 'Page ' + page + ' of ' + pages;
      if (previous) previous.disabled = page <= 1;
      if (next) next.disabled = page >= pages;
      updateSelection();
    };
    filter?.addEventListener('input', () => { page = 1; renderCategoryRows(); });
    pageSize?.addEventListener('change', () => { page = 1; renderCategoryRows(); });
    previous?.addEventListener('click', () => { page = Math.max(1, page - 1); renderCategoryRows(); });
    next?.addEventListener('click', () => { page += 1; renderCategoryRows(); });
    renderCategoryRows();
  }
})();
</script>
