<?php
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
$cmArticles = $pdo->query("SELECT c.id,c.title,c.status,c.updated_at,cat.name AS category_name,sub.name AS subcategory_name,u.full_name FROM content c JOIN categories cat ON cat.id=c.category_id LEFT JOIN categories sub ON sub.id=c.subcategory_id LEFT JOIN users u ON u.id=c.owner_id WHERE c.content_type='article' ORDER BY c.updated_at DESC,c.id DESC LIMIT 300")->fetchAll();
$cmNotices = [
    'category_created'=>'Category created successfully.','category_updated'=>'Category updated successfully.','category_deleted'=>'Category deleted successfully.',
    'subcategory_created'=>'Subcategory created successfully.','subcategory_updated'=>'Subcategory updated successfully.','subcategory_deleted'=>'Subcategory deleted successfully.',
    'article_created'=>'Article created successfully.','article_updated'=>'Article updated successfully.','article_deleted'=>'Article deleted successfully.',
    'category_in_use'=>'This category or subcategory is assigned to existing content. Reassign or remove that content before deleting it.',
    'category_locked'=>'This category cannot be changed or deleted.','category_invalid'=>'Choose a valid parent category.','subcategory_invalid'=>'The selected subcategory does not belong to the selected category.',
    'subcategory_missing'=>'The subcategory could not be found.','article_invalid'=>'Enter an article title and choose a category.','article_missing'=>'The article could not be found.',
    'duplicate_or_database_error'=>'The change could not be saved. Check that the category or subcategory name is not already in use.',
    'unknown_action'=>'The requested action is not available.'
];
$cmNotice = $cmNotices[(string)($_GET['notice'] ?? '')] ?? '';
$cmEditUrl = 'admin.php?section=page-management&tab=contents';
?>
<style>
.content-manager{display:grid;gap:16px}
.content-manager-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
.content-manager-card{min-width:0;padding:17px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.018)}
.content-manager-card h3{margin:0 0 12px;color:#f4e9ec;font:600 14px Ubuntu,sans-serif}
.content-manager-card .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:8px;padding:9px 10px;font:12px Ubuntu,sans-serif}
.content-manager-form{display:grid;gap:10px}.content-manager-form label{display:grid;gap:6px;color:#d9cbd0;font-size:11px}
.content-manager-form textarea{resize:vertical;min-height:70px}.content-manager-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:3px}
.content-manager-table-wrap{overflow-x:auto}.content-manager-table{width:100%;border-collapse:collapse;font-size:11px}.content-manager-table th,.content-manager-table td{padding:10px 9px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.content-manager-table th{color:#d8bdc5;font-weight:600;white-space:nowrap}.content-manager-table td{color:#e9dfe2}
.content-manager-table .muted{font-size:10px}.content-manager-table a{color:#e4a2b1;text-decoration:none}.content-manager-table a:hover{text-decoration:underline}
.content-manager-subcat{padding-left:20px!important}.content-manager-subcat:before{content:"↳ ";color:#b85a70}.content-manager-notice{padding:11px 13px;border:1px solid rgba(184,50,63,.38);border-radius:9px;background:rgba(128,20,39,.13);color:#f1dce1;font-size:12px}
.content-manager-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.content-manager-section-head h3{margin:0}.content-manager-section-head>.content-manager-actions{margin-left:auto;flex-wrap:nowrap}
.content-manager-editor{grid-column:1/-1}.content-manager-article-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.content-manager-article-grid .full{grid-column:1/-1}
@media(max-width:760px){.content-manager-grid{grid-template-columns:1fr}.content-manager-article-grid{grid-template-columns:1fr}.content-manager-article-grid .full{grid-column:auto}.content-manager-section-head{align-items:flex-start;flex-direction:column}}
</style>
<div class="content-manager">
  <?php if ($cmNotice !== ''): ?><div class="content-manager-notice"><?=e($cmNotice)?></div><?php endif; ?>

  <div class="content-manager-grid">
    <section class="content-manager-card content-manager-editor">
      <div class="content-manager-section-head"><h3>Articles</h3><span class="muted"><?=count($cmArticles)?> articles shown (latest 300)</span><div class="content-manager-actions"><button type="button" class="btn" id="managed-create-category-toggle" aria-controls="managed-category-form-panel" aria-expanded="<?= $cmEditCategory ? "true" : "false" ?>">+ Create Category</button><button type="button" class="btn" id="managed-add-article-toggle" aria-controls="managed-article-form-panel" aria-expanded="<?= $cmEditArticle ? "true" : "false" ?>">+ Add Article</button></div></div>
      <div class="content-manager-table-wrap"><table class="content-manager-table"><thead><tr><th>Article</th><th>Category</th><th>Status</th><th>Author</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($cmArticles as $article): ?><tr><td><strong><?=e($article['title'])?></strong></td><td><?=e($article['category_name'])?><?php if($article['subcategory_name']): ?><div class="muted">↳ <?=e($article['subcategory_name'])?></div><?php endif; ?></td><td><?=e(str_replace('_',' ',$article['status']))?></td><td><?=e((string)$article['full_name'])?></td><td><?=e((string)$article['updated_at'])?></td><td><a href="admin.php?section=page-management&tab=contents&edit_article=<?=(int)$article['id']?>#pm-panel-contents">Edit</a> · <form method="post" action="admin-content.php" style="display:inline" onsubmit="return confirm('Delete this article permanently?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_article"><input type="hidden" name="item_id" value="<?=(int)$article['id']?>"><button type="submit" style="border:0;background:none;padding:0;color:#e4a2b1;cursor:pointer;font:inherit">Delete</button></form></td></tr><?php endforeach; ?>
      <?php if(!$cmArticles): ?><tr><td colspan="6" class="muted">No articles yet. Create the first article above.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <section class="content-manager-card" id="managed-category-form-panel"<?= $cmEditCategory ? "" : " hidden" ?>>
      <div class="content-manager-section-head"><h3><?= $cmEditCategory ? 'Edit Category' : 'Create Category' ?></h3><?php if($cmEditCategory): ?><a href="<?=e($cmEditUrl)?>">Cancel edit</a><?php endif; ?></div>
      <form class="content-manager-form" method="post" action="admin-content.php">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_category">
        <input type="hidden" name="category_id" value="<?=(int)($cmEditCategory['id'] ?? 0)?>">
        <label>Category name *<input class="field" name="name" maxlength="120" required placeholder="e.g. Community Stories" value="<?=e((string)($cmEditCategory['name'] ?? ''))?>"></label>
        <label>Description (optional)<textarea class="field" name="description" maxlength="255" placeholder="Briefly describe this category"><?=e((string)($cmEditCategory['description'] ?? ''))?></textarea></label>
        <div class="content-manager-actions"><button class="btn" type="submit"><?= $cmEditCategory ? 'Update Category' : '+ Create Category' ?></button></div>
      </form>
    </section>
    <section class="content-manager-card content-manager-editor" id="managed-article-form-panel"<?= $cmEditArticle ? "" : " hidden" ?>>
      <div class="content-manager-section-head"><h3><?= $cmEditArticle ? 'Edit Article' : 'Add Article' ?></h3><?php if($cmEditArticle): ?><a href="<?=e($cmEditUrl)?>">Cancel edit</a><?php endif; ?></div>
      <form class="content-manager-form" method="post" action="admin-content.php" id="managed-article-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_article">
        <input type="hidden" name="article_id" value="<?=(int)($cmEditArticle['id'] ?? 0)?>">
        <div class="content-manager-article-grid">
          <label class="full">Article title *<input class="field" name="title" maxlength="220" required placeholder="Enter article title" value="<?=e((string)($cmEditArticle['title'] ?? ''))?>"></label>
          <label>Category *<select class="field" name="category_id" id="managed-article-category" required><option value="">Select a category</option><?php foreach($cmCategories as $cat): ?><option value="<?=(int)$cat['id']?>" <?=isset($cmEditArticle['category_id'])&&(int)$cmEditArticle['category_id']===(int)$cat['id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select></label>
          <label>Subcategory (optional)<select class="field" name="subcategory_id" id="managed-article-subcategory"><option value="">No subcategory — directly under category</option><?php foreach($cmSubcategories as $sub): ?><option value="<?=(int)$sub['id']?>" data-parent="<?=(int)$sub['parent_id']?>" <?=isset($cmEditArticle['subcategory_id'])&&(int)$cmEditArticle['subcategory_id']===(int)$sub['id']?'selected':''?>><?=e($sub['parent_name'].' / '.$sub['name'])?></option><?php endforeach; ?></select></label>
          <label>Status<select class="field" name="status"><option value="draft" <?=($cmEditArticle['status'] ?? 'draft')==='draft'?'selected':''?>>Draft</option><option value="published" <?=($cmEditArticle['status'] ?? '')==='published'?'selected':''?>>Published</option></select></label>
          <label>Short description<input class="field" name="excerpt" maxlength="500" value="<?=e((string)($cmEditArticle['excerpt'] ?? ''))?>" placeholder="A short summary"></label>
          <label class="full">Article body<textarea class="field" name="body" rows="8" placeholder="Write the article here"><?=e((string)($cmEditArticle['body'] ?? ''))?></textarea></label>
        </div>
        <div class="content-manager-actions"><button class="btn" type="submit"><?= $cmEditArticle ? 'Update Article' : 'Save Article' ?></button></div>
      </form>
    </section>

  </div>
</div>
<script>
(() => {
  const createCategoryButton = document.getElementById('managed-create-category-toggle');
  const categoryPanel = document.getElementById('managed-category-form-panel');
  if (createCategoryButton && categoryPanel) {
    createCategoryButton.addEventListener('click', () => {
      const opening = categoryPanel.hidden;
      categoryPanel.hidden = !opening;
      createCategoryButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) categoryPanel.querySelector('input[name="name"]')?.focus();
    });
  }

  const addArticleButton = document.getElementById('managed-add-article-toggle');
  const articlePanel = document.getElementById('managed-article-form-panel');
  if (addArticleButton && articlePanel) {
    addArticleButton.addEventListener('click', () => {
      const opening = articlePanel.hidden;
      articlePanel.hidden = !opening;
      addArticleButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) articlePanel.querySelector('input[name="title"]')?.focus();
    });
  }

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

</script>
