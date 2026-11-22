<?php
declare(strict_types=1);

/*
 * Marketplace administration workspace.
 * Tables are created additively so existing website content/categories remain untouched.
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_categories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(140) NOT NULL UNIQUE,
 description TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_products (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 category_id BIGINT UNSIGNED NOT NULL,
 product_name VARCHAR(220) NOT NULL,
 brand VARCHAR(140) NULL,
 model VARCHAR(140) NULL,
 description TEXT NULL,
 photo_path VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_marketplace_product_category FOREIGN KEY (category_id) REFERENCES marketplace_categories(id) ON DELETE RESTRICT,
 INDEX idx_marketplace_product_category(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_inventory (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL UNIQUE,
 sku VARCHAR(100) NOT NULL UNIQUE,
 stock_on_hand INT UNSIGNED NOT NULL DEFAULT 0,
 sold INT UNSIGNED NOT NULL DEFAULT 0,
 tax_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
 unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 retail_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 latest_loaded DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_marketplace_inventory_product FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_purchases (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 purchase_order_no VARCHAR(80) NOT NULL,
 buyer_name VARCHAR(220) NULL,
 purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 net_income DECIMAL(14,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_marketplace_po(purchase_order_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_purchase_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 purchase_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 product_name_snapshot VARCHAR(220) NOT NULL,
 category_snapshot VARCHAR(140) NULL,
 brand_snapshot VARCHAR(140) NULL,
 model_snapshot VARCHAR(140) NULL,
 description_snapshot TEXT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 tax_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
 total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 CONSTRAINT fk_marketplace_purchase_item_purchase FOREIGN KEY (purchase_id) REFERENCES marketplace_purchases(id) ON DELETE CASCADE,
 CONSTRAINT fk_marketplace_purchase_item_product FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE SET NULL,
 INDEX idx_marketplace_purchase_item_product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_invoices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 invoice_no VARCHAR(80) NOT NULL UNIQUE,
 purchase_id BIGINT UNSIGNED NOT NULL,
 buyer_name VARCHAR(220) NOT NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_marketplace_invoice_purchase FOREIGN KEY (purchase_id) REFERENCES marketplace_purchases(id) ON DELETE CASCADE,
 INDEX idx_marketplace_invoice_purchase(purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$marketplaceTab = (string)($_GET['marketplace_tab'] ?? 'overview');
$marketplaceTabs = ['overview','products','inventory','category','purchases','invoices'];
if (!in_array($marketplaceTab, $marketplaceTabs, true)) $marketplaceTab = 'overview';
$marketplaceNotice = '';
$marketplaceError = '';
$marketplaceEditProductId = max(0, (int)($_GET['edit_product'] ?? 0));
$marketplaceViewProductId = max(0, (int)($_GET['view_product'] ?? 0));
$marketplaceProductReadOnly = $marketplaceViewProductId > 0 && $marketplaceEditProductId === 0;
$marketplaceEditInventoryId = max(0, (int)($_GET['edit_inventory'] ?? 0));
$marketplaceViewInventoryId = max(0, (int)($_GET['view_inventory'] ?? 0));
$marketplaceInventoryReadOnly = $marketplaceViewInventoryId > 0 && $marketplaceEditInventoryId === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'marketplace_')) {
    try {
        $action = (string)$_POST['action'];
        if ($action === 'marketplace_delete_categories') {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_categories'] ?? [])), static fn($id) => $id > 0)));
            if (!$selectedIds) throw new RuntimeException('Select at least one category to delete.');
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $usedQuery = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM marketplace_categories c INNER JOIN marketplace_products p ON p.category_id=c.id WHERE c.id IN ($placeholders)");
            $usedQuery->execute($selectedIds);
            $usedCount = (int)$usedQuery->fetchColumn();
            $deleteQuery = $pdo->prepare("DELETE FROM marketplace_categories WHERE id IN ($placeholders) AND id NOT IN (SELECT category_id FROM marketplace_products)");
            $deleteQuery->execute($selectedIds);
            $deletedCount = $deleteQuery->rowCount();
            if ($deletedCount > 0 && $usedCount > 0) {
                $marketplaceNotice = $deletedCount . ' category/categories deleted. ' . $usedCount . ' category/categories assigned to products were retained.';
            } elseif ($deletedCount > 0) {
                $marketplaceNotice = $deletedCount . ' category/categories successfully deleted.';
            } else {
                throw new RuntimeException('The selected categories are assigned to products and cannot be deleted. Reassign or remove those products first.');
            }
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_save_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            $description = trim((string)($_POST['category_description'] ?? ''));
            if ($name === '') throw new RuntimeException('Please enter a category name.');
            $stmt = $pdo->prepare('INSERT INTO marketplace_categories(name,description) VALUES(?,?)');
            $stmt->execute([$name, $description !== '' ? $description : null]);
            $marketplaceNotice = 'Marketplace category successfully created.';
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_save_product') {
            $productId = max(0, (int)($_POST['product_id'] ?? 0));
            $categoryId = max(0, (int)($_POST['category_id'] ?? 0));
            $name = trim((string)($_POST['product_name'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $model = trim((string)($_POST['model'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $categoryCheck = $pdo->prepare('SELECT id FROM marketplace_categories WHERE id=? LIMIT 1');
            $categoryCheck->execute([$categoryId]);
            if (!$categoryCheck->fetchColumn()) throw new RuntimeException('Create at least one Marketplace category before adding a product.');
            if ($name === '') throw new RuntimeException('Please enter a product name.');
            $photoPath = null;
            if (!empty($_FILES['product_photo']['name'])) {
                $file = $_FILES['product_photo'];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The product photo could not be uploaded.');
                if ((int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Product photos must not exceed 5 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
                $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!isset($extensions[$mime])) throw new RuntimeException('Upload a JPG, PNG, or WEBP product photo.');
                $uploadDir = __DIR__ . '/../public/uploads/marketplace/products';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) throw new RuntimeException('The product photo folder could not be created.');
                $filename = 'product-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                if (!move_uploaded_file((string)$file['tmp_name'], $uploadDir . '/' . $filename)) throw new RuntimeException('The product photo could not be saved.');
                $photoPath = 'uploads/marketplace/products/' . $filename;
            }
            if ($productId > 0) {
                if ($photoPath !== null) {
                    $stmt = $pdo->prepare('UPDATE marketplace_products SET category_id=?,product_name=?,brand=?,model=?,description=?,photo_path=? WHERE id=?');
                    $stmt->execute([$categoryId,$name,$brand ?: null,$model ?: null,$description ?: null,$photoPath,$productId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE marketplace_products SET category_id=?,product_name=?,brand=?,model=?,description=? WHERE id=?');
                    $stmt->execute([$categoryId,$name,$brand ?: null,$model ?: null,$description ?: null,$productId]);
                }
                $marketplaceNotice = 'Marketplace product successfully updated.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO marketplace_products(category_id,product_name,brand,model,description,photo_path) VALUES(?,?,?,?,?,?)');
                $stmt->execute([$categoryId,$name,$brand ?: null,$model ?: null,$description ?: null,$photoPath]);
                $marketplaceNotice = 'Marketplace product successfully created.';
            }
            $marketplaceTab = 'products';
            $marketplaceEditProductId = 0;
        } elseif ($action === 'marketplace_save_inventory') {
            $inventoryId = max(0, (int)($_POST['inventory_id'] ?? 0));
            $productId = max(0, (int)($_POST['inventory_product_id'] ?? 0));
            $sku = trim((string)($_POST['sku'] ?? ''));
            $stock = max(0, (int)($_POST['stock_on_hand'] ?? 0));
            $sold = max(0, (int)($_POST['sold'] ?? 0));
            $tax = max(0, (float)($_POST['tax_rate'] ?? 0));
            $unitPrice = max(0, (float)($_POST['unit_price'] ?? 0));
            $retailPrice = max(0, (float)($_POST['retail_price'] ?? 0));
            $productCheck = $pdo->prepare('SELECT id FROM marketplace_products WHERE id=? LIMIT 1');
            $productCheck->execute([$productId]);
            if (!$productCheck->fetchColumn()) throw new RuntimeException('Select a valid product before saving inventory.');
            if ($sku === '') throw new RuntimeException('Please enter an SKU.');
            if ($sold > $stock) throw new RuntimeException('Sold quantity cannot exceed Stock on Hand.');
            if ($inventoryId > 0) {
                $stmt = $pdo->prepare('UPDATE marketplace_inventory SET product_id=?,sku=?,stock_on_hand=?,sold=?,tax_rate=?,unit_price=?,retail_price=? WHERE id=?');
                $stmt->execute([$productId,$sku,$stock,$sold,$tax,$unitPrice,$retailPrice,$inventoryId]);
                $marketplaceNotice = 'Inventory record successfully updated.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO marketplace_inventory(product_id,sku,stock_on_hand,sold,tax_rate,unit_price,retail_price,latest_loaded) VALUES(?,?,?,?,?,?,?,NOW())');
                $stmt->execute([$productId,$sku,$stock,$sold,$tax,$unitPrice,$retailPrice]);
                $marketplaceNotice = 'Inventory record successfully created.';
            }
            $marketplaceTab = 'inventory';
            $marketplaceEditInventoryId = 0;
        } elseif ($action === 'marketplace_add_stock') {
            $inventoryId = max(0, (int)($_POST['inventory_id'] ?? 0));
            $quantity = max(0, (int)($_POST['load_quantity'] ?? 0));
            if ($quantity < 1) throw new RuntimeException('Enter a loaded quantity greater than zero.');
            $stmt = $pdo->prepare('UPDATE marketplace_inventory SET stock_on_hand=stock_on_hand+?,latest_loaded=NOW() WHERE id=?');
            $stmt->execute([$quantity,$inventoryId]);
            if (!$stmt->rowCount()) throw new RuntimeException('The inventory record could not be found.');
            $marketplaceNotice = 'Stock successfully loaded.';
            $marketplaceTab = 'inventory';
        }
    } catch (Throwable $exception) {
        $marketplaceError = $exception instanceof PDOException && (string)$exception->getCode() === '23000'
            ? 'That category name or SKU already exists. Please use a unique value.'
            : $exception->getMessage();
        $marketplaceTab = match ((string)($_POST['action'] ?? '')) {
            'marketplace_save_category','marketplace_delete_categories' => 'category',
            'marketplace_save_inventory','marketplace_add_stock' => 'inventory',
            default => 'products'
        };
    }
}

$marketplaceCategories = $pdo->query('SELECT id,name,description,created_at FROM marketplace_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$marketplaceProducts = $pdo->query("SELECT p.*,c.name AS category_name,i.id AS inventory_id,i.sku,i.stock_on_hand,i.sold,i.tax_rate,i.unit_price,i.retail_price,i.latest_loaded,i.created_at AS inventory_created_at,i.updated_at AS inventory_updated_at FROM marketplace_products p INNER JOIN marketplace_categories c ON c.id=p.category_id LEFT JOIN marketplace_inventory i ON i.product_id=p.id ORDER BY p.created_at DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$marketplaceInventory = $pdo->query("SELECT i.*,p.product_name,p.brand,p.model,p.description,p.photo_path,c.name AS category_name FROM marketplace_inventory i INNER JOIN marketplace_products p ON p.id=i.product_id INNER JOIN marketplace_categories c ON c.id=p.category_id ORDER BY i.updated_at DESC,i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$marketplaceTotalIncome = (float)$pdo->query('SELECT COALESCE(SUM(net_income),0) FROM marketplace_purchases')->fetchColumn();
$marketplaceProductCount = (int)$pdo->query('SELECT COUNT(*) FROM marketplace_products')->fetchColumn();
$marketplaceInventoryCount = (int)$pdo->query('SELECT COUNT(*) FROM marketplace_inventory')->fetchColumn();
$marketplacePurchaseCount = (int)$pdo->query('SELECT COUNT(*) FROM marketplace_purchases')->fetchColumn();
$marketplaceInvoiceCount = (int)$pdo->query('SELECT COUNT(*) FROM marketplace_invoices')->fetchColumn();
$marketplacePurchaseRows = $pdo->query("SELECT pu.id,pu.purchase_order_no,pu.net_income,pu.purchased_at,pi.product_name_snapshot,pi.category_snapshot,pi.brand_snapshot,pi.model_snapshot,pi.description_snapshot,pi.quantity,pi.total_price,p.photo_path FROM marketplace_purchases pu INNER JOIN marketplace_purchase_items pi ON pi.purchase_id=pu.id LEFT JOIN marketplace_products p ON p.id=pi.product_id ORDER BY pu.purchased_at DESC,pu.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$marketplaceInvoiceRows = $pdo->query("SELECT inv.invoice_no,inv.buyer_name,inv.issued_at,pi.product_name_snapshot,pi.category_snapshot,pi.brand_snapshot,pi.model_snapshot,pi.description_snapshot,pi.quantity,pi.unit_price,pi.tax_rate,pi.total_price,pu.purchase_order_no,p.photo_path FROM marketplace_invoices inv INNER JOIN marketplace_purchases pu ON pu.id=inv.purchase_id INNER JOIN marketplace_purchase_items pi ON pi.purchase_id=pu.id LEFT JOIN marketplace_products p ON p.id=pi.product_id ORDER BY inv.issued_at DESC,inv.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$marketplaceProductForm = null;
if ($marketplaceEditProductId > 0 || $marketplaceViewProductId > 0) {
    $targetId = $marketplaceEditProductId > 0 ? $marketplaceEditProductId : $marketplaceViewProductId;
    $stmt=$pdo->prepare('SELECT * FROM marketplace_products WHERE id=? LIMIT 1');$stmt->execute([$targetId]);$marketplaceProductForm=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$marketplaceInventoryForm = null;
if ($marketplaceEditInventoryId > 0 || $marketplaceViewInventoryId > 0) {
    $targetId=$marketplaceEditInventoryId > 0 ? $marketplaceEditInventoryId : $marketplaceViewInventoryId;
    $stmt=$pdo->prepare('SELECT * FROM marketplace_inventory WHERE id=? LIMIT 1');$stmt->execute([$targetId]);$marketplaceInventoryForm=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$money = static fn($value): string => '₱' . number_format((float)$value, 2);
$date = static function($value): string { $time=strtotime((string)$value); return $time ? date('M d, Y · h:i A',$time) : '—'; };
?>
<div class="admin-topline">
 <div><div class="eyebrow">Community commerce</div><h1 class="admin-heading">Marketplace</h1><p class="muted">Manage products, stock, categories, purchases and invoices.</p></div>
</div>
<style>
.marketplace-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 20px;padding:7px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.025)}
.marketplace-tabs a{padding:10px 14px;border-radius:9px;color:#c9bdc1;font-size:12px;font-weight:700}
.marketplace-tabs a.active{background:linear-gradient(110deg,rgba(142,30,52,.55),rgba(142,30,52,.18));color:#fff;box-shadow:inset 0 0 0 1px rgba(210,92,111,.25)}
.marketplace-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:13px;margin-bottom:18px}
.marketplace-kpi{padding:18px;border-radius:15px;border:1px solid var(--line);background:linear-gradient(145deg,rgba(255,255,255,.06),rgba(255,255,255,.015))}
.marketplace-kpi span{display:block;color:var(--muted);font-size:11px}.marketplace-kpi strong{display:block;margin-top:9px;color:#fff;font:27px Georgia,serif}
.marketplace-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 16px}
.marketplace-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:16px;margin:0 0 18px;border:1px solid var(--line);border-radius:13px;background:rgba(255,255,255,.025)}
.marketplace-form label{display:grid;gap:6px;color:#d7cbd0;font-size:12px}.marketplace-form input,.marketplace-form select,.marketplace-form textarea{width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:8px;background:#171317;color:#fff;font:12px Ubuntu,sans-serif}.marketplace-form textarea{min-height:76px;resize:vertical}.marketplace-form .wide{grid-column:1/-1}
.marketplace-table{width:100%;min-width:850px;border-collapse:collapse}.marketplace-table th,.marketplace-table td{padding:11px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;font-size:11px}.marketplace-table th{color:#cbbdc2;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.marketplace-table td strong{font-size:12px;color:#f4e9ed}.marketplace-table small{display:block;color:var(--muted);line-height:1.55;margin-top:3px}.marketplace-photo{width:58px;height:58px;object-fit:cover;border-radius:9px;background:#211a20;border:1px solid var(--line)}.marketplace-actions{display:flex;gap:6px;flex-wrap:wrap}.marketplace-actions a,.marketplace-actions button{font-size:10px;padding:6px 8px}.marketplace-empty{padding:25px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:12px}.marketplace-alert{padding:12px 14px;border-radius:10px;margin:0 0 15px;font-size:12px}.marketplace-alert.success{background:rgba(45,150,99,.13);border:1px solid rgba(45,190,125,.25);color:#b5f0d0}.marketplace-alert.error{background:rgba(190,55,65,.14);border:1px solid rgba(220,75,85,.3);color:#ffc2c7}
@media(max-width:700px){.marketplace-form{grid-template-columns:1fr}.marketplace-form .wide{grid-column:auto}}
.marketplace-category-toolbar{display:grid;grid-template-columns:auto minmax(190px,1fr) auto;align-items:center;gap:16px;margin:0 0 12px}.marketplace-category-toolbar label{display:flex;align-items:center;gap:8px;color:#d9cbd0;font-size:11px}.marketplace-category-toolbar select,.marketplace-category-filter input{padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#171317;color:#fff;font:12px Ubuntu,sans-serif}.marketplace-category-toolbar select{width:76px}.marketplace-category-filter{min-width:0}.marketplace-category-filter input{width:100%;min-width:0}.marketplace-category-toolbar .btn{font-size:11px;padding:8px 12px}.marketplace-category-toolbar .marketplace-category-pagination{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin:0;white-space:nowrap}.marketplace-category-toolbar .marketplace-category-pagination .muted{display:none}.marketplace-category-toolbar .gallery-page-controls{display:flex;align-items:center;gap:5px;flex-wrap:nowrap}.marketplace-category-toolbar .gallery-page-controls .btn{padding:7px 9px;font-size:10px}.marketplace-category-bulk-toolbar{display:flex;justify-content:flex-end;margin:0 0 10px}.marketplace-category-bulk-toolbar .btn{font-size:11px;padding:8px 12px}.marketplace-category-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:10px 0 16px}.marketplace-category-pagination .gallery-page-controls{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.marketplace-category-pagination .gallery-page-controls .btn{padding:7px 11px;font-size:11px}.marketplace-category-pagination .gallery-page-controls .btn:disabled{opacity:.4;cursor:not-allowed}.marketplace-category-pagination .gallery-page-number{min-width:30px;padding:7px 9px!important;text-align:center}.marketplace-category-pagination .gallery-page-number.is-active{background:linear-gradient(135deg,#8f243b,#bd344b);border-color:#d45a70;color:#fff}.marketplace-category-select-cell{width:38px;text-align:center!important}.marketplace-category-select-cell input{accent-color:#a72c46}.marketplace-category-modal .marketplace-form{margin:16px 0 0;padding:0;border:0;background:none}.marketplace-category-modal-actions{display:flex;justify-content:flex-end;gap:8px;grid-column:1/-1}.marketplace-category-modal .account-status-modal-card{max-width:520px;width:min(92vw,520px)}.marketplace-category-modal[hidden],.marketplace-category-delete-modal[hidden]{display:none!important}.marketplace-category-delete-modal{z-index:10000}.marketplace-category-pagination button:focus-visible,.marketplace-category-toolbar input:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}@media(max-width:900px){.marketplace-category-toolbar{grid-template-columns:auto minmax(160px,1fr)}.marketplace-category-toolbar .marketplace-category-pagination{grid-column:1/-1;justify-content:flex-end}}@media(max-width:680px){.marketplace-category-toolbar{grid-template-columns:1fr}.marketplace-category-filter{grid-column:1}.marketplace-category-toolbar .marketplace-category-pagination{grid-column:1;justify-content:flex-start;overflow-x:auto}.marketplace-category-pagination{align-items:flex-start}}

</style>
<nav class="marketplace-tabs" aria-label="Marketplace sections">
<?php foreach (['overview'=>'Overview','products'=>'Products','inventory'=>'Inventory','category'=>'Category','purchases'=>'Purchases','invoices'=>'Invoices'] as $key=>$label): ?>
<a class="<?=$marketplaceTab===$key?'active':''?>" href="admin.php?section=marketplace&amp;marketplace_tab=<?=$key?>"><?=$label?></a>
<?php endforeach; ?>
</nav>
<?php if($marketplaceNotice!==''): ?><div class="marketplace-alert success"><?=e($marketplaceNotice)?></div><?php endif; ?>
<?php if($marketplaceError!==''): ?><div class="marketplace-alert error"><?=e($marketplaceError)?></div><?php endif; ?>

<?php if($marketplaceTab==='overview'): ?>
<div class="marketplace-grid">
 <article class="marketplace-kpi"><span>Total Income / Revenue</span><strong><?=$money($marketplaceTotalIncome)?></strong></article>
 <article class="marketplace-kpi"><span>Total Products</span><strong><?=number_format($marketplaceProductCount)?></strong></article>
 <article class="marketplace-kpi"><span>Inventory Records</span><strong><?=number_format($marketplaceInventoryCount)?></strong></article>
 <article class="marketplace-kpi"><span>Total Purchases</span><strong><?=number_format($marketplacePurchaseCount)?></strong></article>
 <article class="marketplace-kpi"><span>Total Invoices</span><strong><?=number_format($marketplaceInvoiceCount)?></strong></article>
</div>
<section class="panel admin-panel"><div class="admin-panel-head"><h2>Marketplace Summary</h2><span class="status">Live records</span></div><p class="muted">Revenue is calculated from recorded purchase net income. Products, inventory, purchases and invoices are counted from their respective Marketplace records.</p></section>

<?php elseif($marketplaceTab==='category'): ?>
<section class="panel admin-panel marketplace-category-panel">
 <div class="admin-panel-head"><h2>Product Categories</h2><button class="btn" type="button" id="marketplace-category-add-open">+ Add Category</button></div>
 <form id="marketplace-category-bulk-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_delete_categories">
  <div class="marketplace-category-toolbar">
   <label class="marketplace-category-page-size">Show <select id="marketplace-category-page-size" aria-label="Categories per page"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label>
   <label class="marketplace-category-filter"><span>Filter Categories</span><input id="marketplace-category-filter" type="search" placeholder="Search category name or description..." aria-label="Filter Marketplace categories"></label>
   <div class="marketplace-category-pagination"><span class="muted" id="marketplace-category-page-info-top" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-category-first">First</button><button type="button" class="btn light" id="marketplace-category-prev">Previous</button><span id="marketplace-category-page-numbers-top" class="gallery-page-numbers"></span><button type="button" class="btn light" id="marketplace-category-next">Next</button><button type="button" class="btn light" id="marketplace-category-last">Last</button></div></div>
  </div>
  <div class="marketplace-category-bulk-toolbar"><button class="btn bulk-delete" type="button" id="marketplace-category-delete-selected" disabled aria-disabled="true">Delete Selected</button></div>
  <div class="table-wrap"><table class="marketplace-table marketplace-category-table"><thead><tr><th class="marketplace-category-select-cell"><input type="checkbox" id="marketplace-category-select-all" aria-label="Select all categories"></th><th>Category Name</th><th>Description</th><th>Date Created</th></tr></thead><tbody id="marketplace-category-table-body">
  <?php foreach($marketplaceCategories as $category): $categorySearch=mb_strtolower((string)$category['name'].' '.(string)$category['description']); ?><tr data-marketplace-category-row data-category-search="<?=e($categorySearch)?>"><td class="marketplace-category-select-cell"><input type="checkbox" class="marketplace-category-select" name="selected_categories[]" value="<?=(int)$category['id']?>" aria-label="Select <?=e($category['name'])?>"></td><td><strong><?=e($category['name'])?></strong></td><td><?=e((string)$category['description'])?></td><td><?=$date($category['created_at'])?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if(!$marketplaceCategories): ?><div class="marketplace-empty" id="marketplace-category-empty">No Marketplace categories have been created. Add at least one category before creating products.</div><?php else: ?><div class="marketplace-empty" id="marketplace-category-empty" hidden>No categories match your search.</div><?php endif; ?>
  <div class="marketplace-category-pagination marketplace-category-pagination-bottom"><span class="muted" id="marketplace-category-page-info-bottom" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-category-first-bottom">First</button><button type="button" class="btn light" id="marketplace-category-prev-bottom">Previous</button><span id="marketplace-category-page-numbers-bottom" class="gallery-page-numbers"></span><button type="button" class="btn light" id="marketplace-category-next-bottom">Next</button><button type="button" class="btn light" id="marketplace-category-last-bottom">Last</button></div></div>
 </form>
</section>
<div class="account-status-modal marketplace-category-modal" id="marketplace-category-add-modal" hidden><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-add-title"><h2 id="marketplace-category-add-title">Add Category</h2><form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_category"><label>Category Name *<input name="category_name" maxlength="140" required placeholder="e.g. Electronics"></label><label>Description<textarea name="category_description" placeholder="Brief category description"></textarea></label><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-category-add-cancel">Cancel</button><button class="btn" type="submit">Save Category</button></div></form></div></div>
<div class="content-manager-modal-backdrop marketplace-category-delete-modal" id="marketplace-category-delete-modal" hidden role="presentation"><section class="content-manager-modal" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-delete-title"><div class="content-manager-section-head"><h3 id="marketplace-category-delete-title">Delete Selected Categories?</h3></div><p style="color:#e9dce1;font-size:12px;line-height:1.65">You are about to permanently delete the selected category/categories. Categories currently assigned to products will be retained.</p><div><strong style="color:#f0dce2;font-size:12px">Selected Categories:</strong><ul id="marketplace-category-delete-list" style="margin:7px 0 0;padding-left:20px;color:#d8c2ca;font-size:12px;line-height:1.8"></ul></div><div class="content-manager-actions content-manager-modal-actions"><button type="button" class="btn light" id="marketplace-category-delete-cancel">Cancel</button><button type="button" class="content-manager-delete-selected" id="marketplace-category-delete-confirm">Delete Selected</button></div></section></div>


<script>
(()=>{
 const root=document.querySelector('.marketplace-category-panel');if(!root)return;
 const rows=[...root.querySelectorAll('[data-marketplace-category-row]')],filter=document.getElementById('marketplace-category-filter'),size=document.getElementById('marketplace-category-page-size'),selectAll=document.getElementById('marketplace-category-select-all'),deleteButton=document.getElementById('marketplace-category-delete-selected'),bulkForm=document.getElementById('marketplace-category-bulk-form'),deleteModal=document.getElementById('marketplace-category-delete-modal'),empty=document.getElementById('marketplace-category-empty');
 const addModal=document.getElementById('marketplace-category-add-modal');let page=1;
 const visibleRows=()=>rows.filter(row=>row.dataset.categorySearch.includes((filter?.value||'').trim().toLocaleLowerCase()));
 const pageButtons=(container,total,current,go)=>{if(!container)return;container.replaceChildren();const maxVisible=7;let first=Math.max(1,current-Math.floor(maxVisible/2)),last=Math.min(total,first+maxVisible-1);first=Math.max(1,last-maxVisible+1);const add=n=>{const b=document.createElement('button');b.type='button';b.className='btn light gallery-page-number'+(n===current?' is-active':'');b.textContent=String(n);b.setAttribute('aria-label','Page '+n);if(n===current)b.setAttribute('aria-current','page');b.addEventListener('click',()=>go(n));container.append(b);};if(first>1){add(1);if(first>2){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}}for(let n=first;n<=last;n++)add(n);if(last<total){if(last<total-1){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}add(total);}};
 const update=()=>{const matched=visibleRows(),perPage=Math.max(1,Number(size?.value||10)),total=Math.max(1,Math.ceil(matched.length/perPage));page=Math.min(page,total);const from=(page-1)*perPage;rows.forEach(row=>row.hidden=true);matched.forEach((row,index)=>{row.hidden=index<from||index>=from+perPage;});const start=matched.length?from+1:0,end=Math.min(from+perPage,matched.length),info=matched.length?'Showing '+start+'–'+end+' of '+matched.length+' entries':'Showing 0 to 0 of 0 categories';['top','bottom'].forEach(pos=>{const el=document.getElementById('marketplace-category-page-info-'+pos);if(el)el.textContent=info;pageButtons(document.getElementById('marketplace-category-page-numbers-'+pos),total,page,n=>{page=n;update();});});['first','prev','next','last'].forEach(action=>{const buttons=[document.getElementById('marketplace-category-'+action),document.getElementById('marketplace-category-'+action+'-bottom')];buttons.forEach(button=>{if(button){button.disabled=action==='first'||action==='prev'?page===1:page===total;button.onclick=()=>{page=action==='first'?1:action==='prev'?page-1:action==='next'?page+1:total;update();};}});});if(empty){empty.hidden=matched.length>0;}syncSelection();};
 const checkboxes=()=>[...root.querySelectorAll('.marketplace-category-select')];
 const syncSelection=()=>{const boxes=checkboxes(),checked=boxes.filter(box=>box.checked);if(deleteButton){deleteButton.disabled=checked.length===0;deleteButton.setAttribute('aria-disabled',String(checked.length===0));}if(selectAll){const selected=boxes.filter(box=>box.checked).length;selectAll.checked=boxes.length>0&&selected===boxes.length;selectAll.indeterminate=selected>0&&selected<boxes.length;}};
 filter?.addEventListener('input',()=>{page=1;update();});size?.addEventListener('change',()=>{page=1;update();});checkboxes().forEach(box=>box.addEventListener('change',syncSelection));selectAll?.addEventListener('change',()=>{checkboxes().forEach(box=>{box.checked=selectAll.checked;});syncSelection();});
 document.getElementById('marketplace-category-add-open')?.addEventListener('click',()=>{addModal.hidden=false;});document.getElementById('marketplace-category-add-cancel')?.addEventListener('click',()=>{addModal.hidden=true;});
 deleteButton?.addEventListener('click',()=>{const selected=checkboxes().filter(box=>box.checked);if(!selected.length)return;const list=document.getElementById('marketplace-category-delete-list');if(list){list.replaceChildren();selected.forEach(box=>{const li=document.createElement('li');li.textContent=box.getAttribute('aria-label')?.replace(/^Select /,'')||'Category';list.append(li);});}deleteModal.hidden=false;document.getElementById('marketplace-category-delete-cancel')?.focus();});document.getElementById('marketplace-category-delete-cancel')?.addEventListener('click',()=>{deleteModal.hidden=true;});document.getElementById('marketplace-category-delete-confirm')?.addEventListener('click',()=>{if(checkboxes().some(box=>box.checked))bulkForm.requestSubmit();});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&deleteModal&&!deleteModal.hidden)deleteModal.hidden=true;if(event.key==='Escape'&&addModal&&!addModal.hidden)addModal.hidden=true;});
 update();
})();
</script>

<?php elseif($marketplaceTab==='products'): ?>
<section class="panel admin-panel">
 <div class="admin-panel-head"><h2>Products</h2><span class="status"><?=count($marketplaceProducts)?> products</span></div>
 <?php if(!$marketplaceCategories): ?><div class="marketplace-alert error">You must create at least one Marketplace category before a product can be posted. <a href="admin.php?section=marketplace&amp;marketplace_tab=category">Create a category</a>.</div><?php else: ?>
 <div class="marketplace-toolbar"><span class="muted">Manage product details and photos.</span><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=products&amp;edit_product=0#product-editor">+ Add Product</a></div>
 <?php if(!$marketplaceProductReadOnly): ?>
 <form id="product-editor" class="marketplace-form" method="post" enctype="multipart/form-data" action="admin.php?section=marketplace&amp;marketplace_tab=products">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_product"><input type="hidden" name="product_id" value="<?=(int)($marketplaceProductForm['id']??0)?>">
  <label>Product Name *<input name="product_name" required maxlength="220" value="<?=e((string)($marketplaceProductForm['product_name']??''))?>"></label>
  <label>Category *<select name="category_id" required><?php foreach($marketplaceCategories as $category): ?><option value="<?=(int)$category['id']?>" <?=((int)($marketplaceProductForm['category_id']??0)===(int)$category['id'])?'selected':''?>><?=e($category['name'])?></option><?php endforeach; ?></select></label>
  <label>Brand<input name="brand" maxlength="140" value="<?=e((string)($marketplaceProductForm['brand']??''))?>"></label>
  <label>Model<input name="model" maxlength="140" value="<?=e((string)($marketplaceProductForm['model']??''))?>"></label>
  <label class="wide">Description<textarea name="description"><?=e((string)($marketplaceProductForm['description']??''))?></textarea></label>
  <label class="wide">Product Photo (JPG, PNG, WEBP · max 5 MB)<input type="file" name="product_photo" accept="image/jpeg,image/png,image/webp"><?php if(!empty($marketplaceProductForm['photo_path'])): ?><small>Current photo: <?=e(basename((string)$marketplaceProductForm['photo_path']))?></small><?php endif; ?></label>
  <div class="wide"><button class="btn" type="submit"><?=$marketplaceProductForm?'Update Product':'Save Product'?></button> <a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=products">Cancel</a></div>
 </form>
 <?php endif; endif; ?>
 <?php if($marketplaceProductReadOnly && $marketplaceProductForm): ?><div id="product-editor" class="marketplace-form"><div><strong><?=e($marketplaceProductForm['product_name'])?></strong><p class="muted"><?=e((string)$marketplaceProductForm['brand'])?> · <?=e((string)$marketplaceProductForm['model'])?></p><p><?=e((string)$marketplaceProductForm['description'])?></p></div><div><?php if($marketplaceProductForm['photo_path']): ?><img class="marketplace-photo" src="<?=e((string)$marketplaceProductForm['photo_path'])?>" alt="<?=e($marketplaceProductForm['product_name'])?>"><?php endif; ?><p><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=products&amp;edit_product=<?=(int)$marketplaceProductForm['id']?>#product-editor">Edit Product</a> <a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=products">Close</a></p></div></div><?php endif; ?>
 <?php if(!$marketplaceProducts): ?><div class="marketplace-empty">No products have been added yet.</div><?php else: ?>
 <div class="table-wrap"><table class="marketplace-table"><thead><tr><th>Product Photo</th><th>Product Name</th><th>Brand</th><th>Model</th><th>Description</th><th>Actions</th></tr></thead><tbody>
 <?php foreach($marketplaceProducts as $product): ?><tr><td><?php if($product['photo_path']): ?><img class="marketplace-photo" src="<?=e((string)$product['photo_path'])?>" alt="<?=e($product['product_name'])?>"><?php else: ?><div class="marketplace-photo"></div><?php endif; ?></td><td><strong><?=e($product['product_name'])?></strong><small><?=e($product['category_name'])?></small></td><td><?=e((string)$product['brand'])?></td><td><?=e((string)$product['model'])?></td><td><?=e((string)$product['description'])?></td><td><div class="marketplace-actions"><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=products&amp;view_product=<?=(int)$product['id']?>#product-editor">View</a><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=products&amp;edit_product=<?=(int)$product['id']?>#product-editor">Edit</a></div></td></tr><?php endforeach; ?>
 </tbody></table></div><?php endif; ?>
</section>

<?php elseif($marketplaceTab==='inventory'): ?>
<section class="panel admin-panel">
 <div class="admin-panel-head"><h2>Inventory</h2><span class="status"><?=count($marketplaceInventory)?> records</span></div>
 <?php if(!$marketplaceProducts): ?><div class="marketplace-empty">Add a product before creating its inventory record.</div><?php else: ?>
 <div class="marketplace-toolbar"><span class="muted">Stock Level is calculated from Stock on Hand less Sold.</span><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;edit_inventory=0#inventory-editor">+ Add Inventory</a></div>
 <?php if(!$marketplaceInventoryReadOnly): ?>
 <form id="inventory-editor" class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=inventory">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_inventory"><input type="hidden" name="inventory_id" value="<?=(int)($marketplaceInventoryForm['id']??0)?>">
  <label>Product *<select name="inventory_product_id" required><?php foreach($marketplaceProducts as $product): ?><option value="<?=(int)$product['id']?>" <?=((int)($marketplaceInventoryForm['product_id']??0)===(int)$product['id'])?'selected':''?>><?=e($product['product_name'])?></option><?php endforeach; ?></select></label>
  <label>SKU *<input name="sku" required maxlength="100" value="<?=e((string)($marketplaceInventoryForm['sku']??''))?>"></label>
  <label>Stock on Hand<input type="number" name="stock_on_hand" min="0" required value="<?=e((string)($marketplaceInventoryForm['stock_on_hand']??'0'))?>"></label>
  <label>Sold<input type="number" name="sold" min="0" required value="<?=e((string)($marketplaceInventoryForm['sold']??'0'))?>"></label>
  <label>Tax (%)<input type="number" name="tax_rate" min="0" step="0.001" value="<?=e((string)($marketplaceInventoryForm['tax_rate']??'0'))?>"></label>
  <label>Unit Price (₱)<input type="number" name="unit_price" min="0" step="0.01" value="<?=e((string)($marketplaceInventoryForm['unit_price']??'0'))?>"></label>
  <label>Retail (₱)<input type="number" name="retail_price" min="0" step="0.01" value="<?=e((string)($marketplaceInventoryForm['retail_price']??'0'))?>"></label>
  <div class="wide"><button class="btn" type="submit"><?=$marketplaceInventoryForm?'Update Inventory':'Save Inventory'?></button> <a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory">Cancel</a></div>
 </form>
 <?php endif; endif; ?>
 <?php if($marketplaceInventoryReadOnly && $marketplaceInventoryForm): ?><div id="inventory-editor" class="marketplace-form"><div><strong>SKU: <?=e($marketplaceInventoryForm['sku'])?></strong><p class="muted">Stock on Hand: <?=(int)$marketplaceInventoryForm['stock_on_hand']?> · Sold: <?=(int)$marketplaceInventoryForm['sold']?></p><p>Stock Level: <?=max(0,(int)$marketplaceInventoryForm['stock_on_hand']-(int)$marketplaceInventoryForm['sold'])?></p></div><div><p>Tax: <?=number_format((float)$marketplaceInventoryForm['tax_rate'],3)?>%</p><p>Unit Price: <?=$money($marketplaceInventoryForm['unit_price'])?></p><p>Retail: <?=$money($marketplaceInventoryForm['retail_price'])?></p><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;edit_inventory=<?=(int)$marketplaceInventoryForm['id']?>#inventory-editor">Edit Inventory</a> <a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory">Close</a></div></div><?php endif; ?>
 <?php if(!$marketplaceInventory): ?><div class="marketplace-empty">No inventory records have been created yet.</div><?php else: ?>
 <div class="table-wrap"><table class="marketplace-table"><thead><tr><th>Product Photo</th><th>Product Name / Details</th><th>SKU</th><th>Stock on Hand</th><th>Sold</th><th>Stock Level</th><th>Tax</th><th>Unit Price</th><th>Retail</th><th>Latest Loaded</th><th>Date Created</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody>
 <?php foreach($marketplaceInventory as $item): $stockLevel=max(0,(int)$item['stock_on_hand']-(int)$item['sold']); ?><tr><td><?php if($item['photo_path']): ?><img class="marketplace-photo" src="<?=e((string)$item['photo_path'])?>" alt="<?=e($item['product_name'])?>"><?php else: ?><div class="marketplace-photo"></div><?php endif; ?></td><td><strong><?=e($item['product_name'])?></strong><small><?=e($item['category_name'])?> · <?=e((string)$item['brand'])?> · <?=e((string)$item['model'])?></small><small><?=e((string)$item['description'])?></small></td><td><?=e($item['sku'])?></td><td><?=(int)$item['stock_on_hand']?></td><td><?=(int)$item['sold']?></td><td><?=$stockLevel?></td><td><?=number_format((float)$item['tax_rate'],3)?>%</td><td><?=$money($item['unit_price'])?></td><td><?=$money($item['retail_price'])?></td><td><?=$date($item['latest_loaded'])?></td><td><?=$date($item['created_at'])?></td><td><?=$date($item['updated_at'])?></td><td><div class="marketplace-actions"><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;view_inventory=<?=(int)$item['id']?>#inventory-editor">View</a><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;edit_inventory=<?=(int)$item['id']?>#inventory-editor">Edit</a><form method="post" action="admin.php?section=marketplace&amp;marketplace_tab=inventory"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_add_stock"><input type="hidden" name="inventory_id" value="<?=(int)$item['id']?>"><input type="number" name="load_quantity" min="1" required placeholder="Qty" style="width:55px"><button class="btn light" type="submit">Add</button></form></div></td></tr><?php endforeach; ?>
 </tbody></table></div><?php endif; ?>
</section>

<?php elseif($marketplaceTab==='purchases'): ?>
<section class="panel admin-panel"><div class="admin-panel-head"><h2>Purchases</h2><span class="status"><?=count($marketplacePurchaseRows)?> product lines</span></div>
<?php if(!$marketplacePurchaseRows): ?><div class="marketplace-empty">No purchases have been recorded yet. Purchase records will appear here when a checkout or sales workflow is connected.</div><?php else: ?>
<div class="table-wrap"><table class="marketplace-table"><thead><tr><th>Product Photo</th><th>Purchase Order No.</th><th>Product Name / Details</th><th>Total Purchases</th><th>Net Income</th><th>Last Purchase</th><th>Actions</th></tr></thead><tbody>
<?php foreach($marketplacePurchaseRows as $purchase): ?><tr id="purchase-record-<?=(int)$purchase['id']?>"><td><?php if($purchase['photo_path']): ?><img class="marketplace-photo" src="<?=e((string)$purchase['photo_path'])?>" alt="<?=e($purchase['product_name_snapshot'])?>"><?php else: ?><div class="marketplace-photo"></div><?php endif; ?></td><td><strong><?=e($purchase['purchase_order_no'])?></strong></td><td><strong><?=e($purchase['product_name_snapshot'])?></strong><small><?=e((string)$purchase['category_snapshot'])?> · <?=e((string)$purchase['brand_snapshot'])?> · <?=e((string)$purchase['model_snapshot'])?></small><small><?=e((string)$purchase['description_snapshot'])?></small></td><td><?=(int)$purchase['quantity']?></td><td><?=$money($purchase['net_income'])?></td><td><?=$date($purchase['purchased_at'])?></td><td><div class="marketplace-actions"><a class="btn light" href="#purchase-record-<?=(int)$purchase['id']?>">View</a><button class="btn" type="button" onclick="window.print()">Print</button></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>

<?php elseif($marketplaceTab==='invoices'): ?>
<section class="panel admin-panel"><div class="admin-panel-head"><h2>Invoices</h2><span class="status"><?=count($marketplaceInvoiceRows)?> invoice lines</span></div>
<?php if(!$marketplaceInvoiceRows): ?><div class="marketplace-empty">No invoices have been issued yet. Invoice records will appear here when a checkout or sales workflow is connected.</div><?php else: ?>
<div class="table-wrap"><table class="marketplace-table"><thead><tr><th>Invoice No.</th><th>Product Name / Details</th><th>Buyer</th><th>Quantity Purchased</th><th>Unit Price</th><th>Tax</th><th>Total Price</th><th>Actions</th></tr></thead><tbody>
<?php foreach($marketplaceInvoiceRows as $invoice): ?><tr id="invoice-record-<?=e(rawurlencode((string)$invoice['invoice_no']))?>"><td><strong><?=e($invoice['invoice_no'])?></strong></td><td><strong><?=e($invoice['product_name_snapshot'])?></strong><small><?=e((string)$invoice['category_snapshot'])?> · <?=e((string)$invoice['brand_snapshot'])?> · <?=e((string)$invoice['model_snapshot'])?></small><small><?=e((string)$invoice['description_snapshot'])?></small></td><td><?=e($invoice['buyer_name'])?></td><td><?=(int)$invoice['quantity']?></td><td><?=$money($invoice['unit_price'])?></td><td><?=number_format((float)$invoice['tax_rate'],3)?>%</td><td><?=$money($invoice['total_price'])?></td><td><div class="marketplace-actions"><a class="btn light" href="#invoice-record-<?=e(rawurlencode((string)$invoice['invoice_no']))?>">View</a><button class="btn" type="button" onclick="window.print()">Print</button></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endif; ?>
