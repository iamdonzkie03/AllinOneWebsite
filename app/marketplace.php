<?php
declare(strict_types=1);

/*
 * Marketplace administration workspace.
 * Tables are created additively so existing website content/categories remain untouched.
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_categories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 parent_id BIGINT UNSIGNED NULL,
 name VARCHAR(140) NOT NULL UNIQUE,
 description TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_marketplace_category_parent(parent_id),
 CONSTRAINT fk_marketplace_category_parent FOREIGN KEY (parent_id) REFERENCES marketplace_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$marketplaceCategoryColumns = $pdo->query("SHOW COLUMNS FROM marketplace_categories");
$marketplaceCategoryColumnNames = array_column($marketplaceCategoryColumns->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('parent_id', $marketplaceCategoryColumnNames, true)) {
    $pdo->exec("ALTER TABLE marketplace_categories ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER id");
    $pdo->exec("ALTER TABLE marketplace_categories ADD INDEX idx_marketplace_category_parent(parent_id)");
    $pdo->exec("ALTER TABLE marketplace_categories ADD CONSTRAINT fk_marketplace_category_parent FOREIGN KEY (parent_id) REFERENCES marketplace_categories(id) ON DELETE RESTRICT");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_products (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 category_id BIGINT UNSIGNED NULL,
 subcategory_id BIGINT UNSIGNED NULL,
 product_name VARCHAR(220) NOT NULL,
 product_code VARCHAR(100) NULL,
 manufacturer VARCHAR(180) NULL,
 supply_chain_entity VARCHAR(180) NULL,
 country_of_origin VARCHAR(120) NULL,
 brand VARCHAR(140) NULL,
 model VARCHAR(140) NULL,
 description TEXT NULL,
 photo_path VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_marketplace_product_category FOREIGN KEY (category_id) REFERENCES marketplace_categories(id) ON DELETE RESTRICT,
 INDEX idx_marketplace_product_category(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("ALTER TABLE marketplace_products MODIFY category_id BIGINT UNSIGNED NULL");
$marketplaceSubcategoryColumn = $pdo->query("SHOW COLUMNS FROM marketplace_products LIKE 'subcategory_id'");
if (!$marketplaceSubcategoryColumn->fetch(PDO::FETCH_ASSOC)) $pdo->exec("ALTER TABLE marketplace_products ADD COLUMN subcategory_id BIGINT UNSIGNED NULL AFTER category_id");
// Use metadata checks instead of ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
// which is not supported by several MySQL/MariaDB versions bundled with Laragon.
$marketplaceProductColumns = [
    'product_code' => "VARCHAR(100) NULL AFTER product_name",
    'manufacturer' => "VARCHAR(180) NULL AFTER model",
    'supply_chain_entity' => "VARCHAR(180) NULL AFTER manufacturer",
    'country_of_origin' => "VARCHAR(120) NULL AFTER supply_chain_entity",
];
foreach ($marketplaceProductColumns as $columnName => $columnDefinition) {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM marketplace_products LIKE " . $pdo->quote($columnName));
    if (!$columnCheck->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE marketplace_products ADD COLUMN " . $columnName . " " . $columnDefinition);
    }
}
$productCodeIndex = $pdo->query("SHOW INDEX FROM marketplace_products WHERE Key_name = 'uq_marketplace_product_code'")->fetch(PDO::FETCH_ASSOC);
if (!$productCodeIndex) $pdo->exec("ALTER TABLE marketplace_products ADD UNIQUE KEY uq_marketplace_product_code (product_code)");
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_inventory (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL UNIQUE,
 sku VARCHAR(100) NOT NULL UNIQUE,
 stock_on_hand DECIMAL(14,2) NOT NULL DEFAULT 0,
 sold INT UNSIGNED NOT NULL DEFAULT 0,
 tax_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
 unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 retail_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 latest_loaded DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_marketplace_inventory_product FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stockQuantityColumn = $pdo->query("SHOW COLUMNS FROM marketplace_inventory LIKE 'stock_on_hand'")->fetch(PDO::FETCH_ASSOC);
if ($stockQuantityColumn && stripos((string)$stockQuantityColumn['Type'], 'decimal') !== 0) {
    $pdo->exec("ALTER TABLE marketplace_inventory MODIFY stock_on_hand DECIMAL(14,2) NOT NULL DEFAULT 0");
}
$marketplaceInventoryColumns = [
    'batch_number' => "VARCHAR(100) NULL AFTER sku",
    'supply_chain_type' => "VARCHAR(40) NULL AFTER batch_number",
    'selling_price' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER retail_price",
    'vat_rate' => "DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER tax_rate",
    'percentage_tax_rate' => "DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER vat_rate",
    'income_tax_rate' => "DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER percentage_tax_rate",
    'withholding_tax_rate' => "DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER income_tax_rate",
    'other_tax_rate' => "DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER withholding_tax_rate",
    'status' => "VARCHAR(30) NOT NULL DEFAULT 'Low Stock' AFTER selling_price",
];
foreach ($marketplaceInventoryColumns as $columnName => $columnDefinition) {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM marketplace_inventory LIKE " . $pdo->quote($columnName));
    if (!$columnCheck->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE marketplace_inventory ADD COLUMN " . $columnName . " " . $columnDefinition);
    }
}
$pdo->exec("UPDATE marketplace_inventory SET batch_number=sku WHERE batch_number IS NULL OR batch_number=''");
// Convert all pre-existing percentage-point tax values to decimal fractions exactly once.
$pdo->exec("CREATE TABLE IF NOT EXISTS marketplace_schema_migrations (migration_key VARCHAR(120) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$taxFormatMigration = $pdo->prepare("SELECT migration_key FROM marketplace_schema_migrations WHERE migration_key=? LIMIT 1");
$taxFormatMigration->execute(['inventory_tax_rates_to_decimal_fractions_v1']);
if (!$taxFormatMigration->fetchColumn()) {
    $pdo->exec("UPDATE marketplace_inventory SET tax_rate=tax_rate/100,vat_rate=vat_rate/100,percentage_tax_rate=percentage_tax_rate/100,income_tax_rate=income_tax_rate/100,withholding_tax_rate=withholding_tax_rate/100,other_tax_rate=other_tax_rate/100");
    $markTaxFormatMigration = $pdo->prepare("INSERT INTO marketplace_schema_migrations(migration_key) VALUES(?)");
    $markTaxFormatMigration->execute(['inventory_tax_rates_to_decimal_fractions_v1']);
}
$pdo->exec("UPDATE marketplace_inventory SET selling_price=unit_price WHERE selling_price=0 AND unit_price<>0");
$pdo->exec("UPDATE marketplace_inventory i INNER JOIN marketplace_products p ON p.id=i.product_id SET i.supply_chain_type=p.supply_chain_entity WHERE i.supply_chain_type IS NULL OR i.supply_chain_type=''");
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
$marketplaceCreatedCategoryName = null;
$marketplaceCreatedProducts = null;
$marketplaceDuplicateProduct = null;
$marketplaceProductEditSuccess = null;
$marketplaceProductInputRows = [];
$marketplaceDuplicateCategoryName = null;
$marketplaceCategoryUpdateResult = null;
$marketplaceSubcategoryUpdateResult = null;
$marketplaceCategoryInputName = '';
$marketplaceCategoryInputDescription = '';
$marketplaceCreatedSubcategoryName = null;
$marketplaceCreatedSubcategoryParent = null;
$marketplaceDuplicateSubcategoryName = null;
$marketplaceSubcategoryInputParentId = 0;
$marketplaceSubcategoryInputName = '';
$marketplaceSubcategoryInputDescription = '';
$marketplaceDeletedCategoryCount = 0;
$marketplaceDeletedProductCount = 0;
$marketplaceProductDeleteMessage = '';
$marketplaceDeletedProductDetails = [];
$marketplaceProductDeleteHadInventory = false;
$marketplaceDeletedCategoryNames = [];
$marketplaceDeletedCategoryGroups = [];
$marketplaceDeletedSubcategoryCount = 0;
$marketplaceEditProductId = max(0, (int)($_GET['edit_product'] ?? 0));
$marketplaceViewProductId = max(0, (int)($_GET['view_product'] ?? 0));
$marketplaceProductReadOnly = $marketplaceViewProductId > 0 && $marketplaceEditProductId === 0;
$marketplaceEditInventoryId = max(0, (int)($_GET['edit_inventory'] ?? 0));
$marketplaceViewInventoryId = max(0, (int)($_GET['view_inventory'] ?? 0));
$marketplaceInventoryReadOnly = $marketplaceViewInventoryId > 0 && $marketplaceEditInventoryId === 0;
$marketplaceAddInventoryProductId = max(0, (int)($_GET['add_inventory_product'] ?? 0));
$marketplaceSupplyEntities = ['Manufacturer', 'Importer', 'Distributor', 'Retailer', 'Vendor'];
$marketplaceInventoryStatuses = ['On Hold', 'Cancelled', 'Low Stock'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'marketplace_')) {
    try {
        $action = (string)$_POST['action'];
        if ($action === 'marketplace_delete_categories') {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_categories'] ?? [])), static fn($id) => $id > 0)));
            if (!$selectedIds) throw new RuntimeException('Select at least one category to delete.');
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $selectedQuery = $pdo->prepare("SELECT id,parent_id,name FROM marketplace_categories WHERE id IN ($placeholders)");
            $selectedQuery->execute($selectedIds);
            $selectedRows = $selectedQuery->fetchAll(PDO::FETCH_ASSOC);
            if (!$selectedRows) throw new RuntimeException('The selected categories could not be found.');
            $selectedRootIds = [];
            $selectedSubcategoryIds = [];
            foreach ($selectedRows as $selectedRow) {
                if (!empty($selectedRow['parent_id'])) $selectedSubcategoryIds[] = (int)$selectedRow['id'];
                else $selectedRootIds[] = (int)$selectedRow['id'];
            }
            $selectedRootIds = array_values(array_unique($selectedRootIds));
            $groups = [];
            foreach ($selectedRows as $selectedRow) {
                if (empty($selectedRow['parent_id'])) $groups[(int)$selectedRow['id']] = ['name'=>(string)$selectedRow['name'],'subcategories'=>[]];
            }
            if ($selectedRootIds) {
                $rootPlaceholders = implode(',', array_fill(0, count($selectedRootIds), '?'));
                $childrenQuery = $pdo->prepare("SELECT id,parent_id,name FROM marketplace_categories WHERE parent_id IN ($rootPlaceholders) ORDER BY created_at DESC,id DESC");
                $childrenQuery->execute($selectedRootIds);
                foreach ($childrenQuery->fetchAll(PDO::FETCH_ASSOC) as $child) {
                    $parentId = (int)$child['parent_id'];
                    $groups[$parentId]['subcategories'][] = ['id'=>(int)$child['id'],'name'=>(string)$child['name']];
                    $selectedSubcategoryIds[] = (int)$child['id'];
                }
            }
            foreach ($selectedRows as $selectedRow) {
                if (!empty($selectedRow['parent_id']) && !isset($groups[(int)$selectedRow['parent_id']])) {
                    $groups[(int)$selectedRow['id']] = ['name'=>(string)$selectedRow['name'],'subcategories'=>[]];
                }
            }
            $selectedSubcategoryIds = array_values(array_unique($selectedSubcategoryIds));
            $allDeleteIds = array_values(array_unique(array_merge($selectedRootIds, $selectedSubcategoryIds)));
            if (!$allDeleteIds) throw new RuntimeException('Select at least one category to delete.');
            $deletePlaceholders = implode(',', array_fill(0, count($allDeleteIds), '?'));
            $usedQuery = $pdo->prepare("SELECT COUNT(*) FROM marketplace_products WHERE category_id IN ($deletePlaceholders) OR subcategory_id IN ($deletePlaceholders)");
            $usedQuery->execute(array_merge($allDeleteIds, $allDeleteIds));
            if ((int)$usedQuery->fetchColumn() > 0) {
                throw new RuntimeException('The selected category or one of its subcategories is assigned to a product and cannot be deleted. Reassign or remove those products first.');
            }
            $deletedRows = 0;
            $pdo->beginTransaction();
            try {
                if ($selectedSubcategoryIds) {
                    $childPlaceholders = implode(',', array_fill(0, count($selectedSubcategoryIds), '?'));
                    $deleteChildren = $pdo->prepare("DELETE FROM marketplace_categories WHERE id IN ($childPlaceholders)");
                    $deleteChildren->execute($selectedSubcategoryIds);
                    $deletedRows += $deleteChildren->rowCount();
                }
                if ($selectedRootIds) {
                    $deleteRoots = $pdo->prepare("DELETE FROM marketplace_categories WHERE id IN ($rootPlaceholders)");
                    $deleteRoots->execute($selectedRootIds);
                    $deletedRows += $deleteRoots->rowCount();
                }
                if ($deletedRows !== count($allDeleteIds)) throw new RuntimeException('Not all selected categories and subcategories could be deleted.');
                $pdo->commit();
            } catch (Throwable $deleteError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $deleteError;
            }
            $marketplaceDeletedCategoryCount = count($groups);
            $marketplaceDeletedSubcategoryCount = array_sum(array_map(static fn($group) => count($group['subcategories']), $groups));
            $marketplaceDeletedCategoryGroups = array_values($groups);
            $marketplaceDeletedCategoryNames = array_column($marketplaceDeletedCategoryGroups, 'name');
            $marketplaceNotice = '';
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_save_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            $description = trim((string)($_POST['category_description'] ?? ''));
            $marketplaceCategoryInputName = $name;
            $marketplaceCategoryInputDescription = $description;
            if ($name === '') throw new RuntimeException('Please enter a category name.');
            $existingCategory = $pdo->prepare('SELECT name FROM marketplace_categories WHERE name = ? LIMIT 1');
            $existingCategory->execute([$name]);
            $existingName = $existingCategory->fetchColumn();
            if ($existingName !== false) {
                $marketplaceDuplicateCategoryName = (string)$existingName;
            } else {
                $stmt = $pdo->prepare('INSERT INTO marketplace_categories(name,description) VALUES(?,?)');
                $stmt->execute([$name, $description !== '' ? $description : null]);
                $marketplaceCreatedCategoryName = $name;
            }
            $marketplaceNotice = '';
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_save_subcategory') {
            $parentId = max(0, (int)($_POST['parent_category_id'] ?? 0));
            $name = trim((string)($_POST['subcategory_name'] ?? ''));
            $description = trim((string)($_POST['subcategory_description'] ?? ''));
            $marketplaceSubcategoryInputParentId = $parentId;
            $marketplaceSubcategoryInputName = $name;
            $marketplaceSubcategoryInputDescription = $description;
            if ($parentId < 1 || $name === '') throw new RuntimeException('Please enter a subcategory name.');
            $parentQuery = $pdo->prepare('SELECT id, name FROM marketplace_categories WHERE id = ? AND parent_id IS NULL LIMIT 1');
            $parentQuery->execute([$parentId]);
            $parentCategory = $parentQuery->fetch(PDO::FETCH_ASSOC);
            if (!$parentCategory) throw new RuntimeException('The selected parent category could not be found.');
            $duplicateQuery = $pdo->prepare('SELECT id FROM marketplace_categories WHERE name = ? LIMIT 1');
            $duplicateQuery->execute([$name]);
            if ($duplicateQuery->fetchColumn()) {
                $marketplaceDuplicateSubcategoryName = $name;
            } else {
                $insertSubcategory = $pdo->prepare('INSERT INTO marketplace_categories(parent_id,name,description) VALUES(?,?,?)');
                $insertSubcategory->execute([$parentId,$name,$description !== '' ? $description : null]);
                $marketplaceCreatedSubcategoryName = $name;
                $marketplaceCreatedSubcategoryParent = (string)$parentCategory['name'];
                $marketplaceSubcategoryInputParentId = 0;
                $marketplaceSubcategoryInputName = '';
                $marketplaceSubcategoryInputDescription = '';
            }
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_update_category') {
            $categoryId = max(0, (int)($_POST['category_id'] ?? 0));
            $name = trim((string)($_POST['category_name'] ?? ''));
            $description = trim((string)($_POST['category_description'] ?? ''));
            if ($categoryId < 1 || $name === '') throw new RuntimeException('Please enter a category name.');
            $currentQuery = $pdo->prepare('SELECT id, parent_id, name, description FROM marketplace_categories WHERE id = ? LIMIT 1');
            $currentQuery->execute([$categoryId]);
            $currentCategory = $currentQuery->fetch(PDO::FETCH_ASSOC);
            if (!$currentCategory) throw new RuntimeException('The selected category could not be found.');
            $oldName = (string)$currentCategory['name'];
            $oldDescription = (string)($currentCategory['description'] ?? '');
            $duplicateQuery = $pdo->prepare('SELECT id FROM marketplace_categories WHERE name = ? AND id <> ? LIMIT 1');
            $duplicateQuery->execute([$name, $categoryId]);
            if ($duplicateQuery->fetchColumn()) throw new RuntimeException('Another category already uses that name. Please choose a different category name.');
            $nameChanged = $name !== $oldName;
            $descriptionChanged = $description !== $oldDescription;
            $parentChanged = false;
            $newParentId = null;
            if ($currentCategory['parent_id'] !== null) {
                $newParentId = max(0, (int)($_POST['parent_category_id'] ?? 0));
                if ($newParentId < 1) throw new RuntimeException('Please select a parent category.');
                $parentQuery = $pdo->prepare('SELECT id FROM marketplace_categories WHERE id = ? AND parent_id IS NULL LIMIT 1');
                $parentQuery->execute([$newParentId]);
                if (!$parentQuery->fetchColumn()) throw new RuntimeException('The selected parent category could not be found.');
                $parentChanged = $newParentId !== (int)$currentCategory['parent_id'];
            }
            if (!$nameChanged && !$descriptionChanged && !$parentChanged) {
                throw new RuntimeException('No changes were made to this category.');
            }
            if ($currentCategory['parent_id'] !== null) {
                $update = $pdo->prepare('UPDATE marketplace_categories SET parent_id = ?, name = ?, description = ? WHERE id = ?');
                $update->execute([$newParentId, $name, $description !== '' ? $description : null, $categoryId]);
            } else {
                $update = $pdo->prepare('UPDATE marketplace_categories SET name = ?, description = ? WHERE id = ?');
                $update->execute([$name, $description !== '' ? $description : null, $categoryId]);
            }
            if ($currentCategory['parent_id'] !== null) {
                $changedCount = (int)$nameChanged + (int)$descriptionChanged + (int)$parentChanged;
                if ($changedCount >= 2) {
                    $marketplaceSubcategoryUpdateResult = ['type' => 'multiple'];
                } elseif ($parentChanged) {
                    $parentNameQuery = $pdo->prepare('SELECT name FROM marketplace_categories WHERE id = ? LIMIT 1');
                    $parentNameQuery->execute([$newParentId]);
                    $marketplaceSubcategoryUpdateResult = ['type' => 'parent', 'name' => $name, 'parent_name' => (string)$parentNameQuery->fetchColumn()];
                } elseif ($nameChanged) {
                    $marketplaceSubcategoryUpdateResult = ['type' => 'name', 'old_name' => $oldName, 'name' => $name];
                } else {
                    $marketplaceSubcategoryUpdateResult = ['type' => 'description', 'name' => $name];
                }
            } else {
                $marketplaceCategoryUpdateResult = [
                    'type' => ($nameChanged && $descriptionChanged) ? 'both' : ($nameChanged ? 'name' : 'description'),
                    'old_name' => $oldName,
                    'name' => $name
                ];
            }
            $marketplaceTab = 'category';
        } elseif ($action === 'marketplace_delete_products') {
            $selectedProductIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_products'] ?? [])), static fn(int $id): bool => $id > 0)));
            if (!$selectedProductIds) throw new RuntimeException('Select at least one product to delete.');
            $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
            $productLookup = $pdo->prepare("SELECT id,product_name,product_code,photo_path FROM marketplace_products WHERE id IN ($placeholders) ORDER BY product_name,id");
            $productLookup->execute($selectedProductIds);
            $productsToDelete = $productLookup->fetchAll(PDO::FETCH_ASSOC);
            if (!$productsToDelete) throw new RuntimeException('The selected products could not be found.');
            $actualIds = array_map(static fn(array $p): int => (int)$p['id'], $productsToDelete);
            $actualPlaceholders = implode(',', array_fill(0, count($actualIds), '?'));
            $inventoryLookup = $pdo->prepare("SELECT product_id FROM marketplace_inventory WHERE product_id IN ($actualPlaceholders)");
            $inventoryLookup->execute($actualIds);
            $productsWithInventory = array_fill_keys(array_map('intval',$inventoryLookup->fetchAll(PDO::FETCH_COLUMN)),true);
            $photoPathsToDelete = array_values(array_filter(array_map(static fn(array $p): string => (string)($p['photo_path']??''),$productsToDelete)));
            $pdo->beginTransaction();
            try {
                $purchaseIdsQuery=$pdo->prepare("SELECT DISTINCT purchase_id FROM marketplace_purchase_items WHERE product_id IN ($actualPlaceholders)");
                $purchaseIdsQuery->execute($actualIds);
                $relatedPurchaseIds=array_map('intval',$purchaseIdsQuery->fetchAll(PDO::FETCH_COLUMN));
                $deleteItems=$pdo->prepare("DELETE FROM marketplace_purchase_items WHERE product_id IN ($actualPlaceholders)");
                $deleteItems->execute($actualIds);
                if($relatedPurchaseIds){
                    $purchasePlaceholders=implode(',',array_fill(0,count($relatedPurchaseIds),'?'));
                    $recalculatePurchases=$pdo->prepare("UPDATE marketplace_purchases pu SET net_income=(SELECT COALESCE(SUM(pi.total_price),0) FROM marketplace_purchase_items pi WHERE pi.purchase_id=pu.id) WHERE pu.id IN ($purchasePlaceholders)");
                    $recalculatePurchases->execute($relatedPurchaseIds);
                    $purchasePlaceholders=implode(',',array_fill(0,count($relatedPurchaseIds),'?'));
                    $emptyQuery=$pdo->prepare("SELECT pu.id FROM marketplace_purchases pu LEFT JOIN marketplace_purchase_items pi ON pi.purchase_id=pu.id WHERE pu.id IN ($purchasePlaceholders) GROUP BY pu.id HAVING COUNT(pi.id)=0");
                    $emptyQuery->execute($relatedPurchaseIds);
                    $emptyIds=array_map('intval',$emptyQuery->fetchAll(PDO::FETCH_COLUMN));
                    if($emptyIds){
                        $emptyPlaceholders=implode(',',array_fill(0,count($emptyIds),'?'));
                        $deleteInvoices=$pdo->prepare("DELETE FROM marketplace_invoices WHERE purchase_id IN ($emptyPlaceholders)");
                        $deleteInvoices->execute($emptyIds);
                        $deletePurchases=$pdo->prepare("DELETE FROM marketplace_purchases WHERE id IN ($emptyPlaceholders)");
                        $deletePurchases->execute($emptyIds);
                    }
                }
                $deleteProducts=$pdo->prepare("DELETE FROM marketplace_products WHERE id IN ($actualPlaceholders)");
                $deleteProducts->execute($actualIds);
                $pdo->commit();
            } catch(Throwable $deleteError) {
                if($pdo->inTransaction()) $pdo->rollBack();
                throw $deleteError;
            }
            $uploadDirectory=realpath(dirname(__DIR__).'/public/uploads/products');
            if($uploadDirectory!==false){
                foreach($photoPathsToDelete as $photoPath){
                    $filename=basename(str_replace('\\\\','/',$photoPath));
                    if($filename===''||$filename==='.'||$filename==='..') continue;
                    $file=$uploadDirectory.DIRECTORY_SEPARATOR.$filename;
                    if(is_file($file)) @unlink($file);
                }
            }
            $marketplaceDeletedProductDetails=$productsToDelete;
            $marketplaceDeletedProductCount=count($productsToDelete);
            $marketplaceProductDeleteHadInventory=count(array_intersect($actualIds,array_keys($productsWithInventory)))>0;
            $marketplaceTab='products';
        } elseif ($action === 'marketplace_save_product') {
            $isProductEdit = max(0, (int)($_POST['product_id'] ?? 0)) > 0;
            $productNames = (array)($_POST['product_name'] ?? []);
            $categoryInputs = (array)($_POST['category_id'] ?? []);
            $subcategoryInputs = (array)($_POST['subcategory_id'] ?? []);
            $brands = (array)($_POST['brand'] ?? []);
            $models = (array)($_POST['model'] ?? []);
            $manufacturers = (array)($_POST['manufacturer'] ?? []);
            $supplyEntities = (array)($_POST['supply_chain_entity'] ?? []);
            $countries = (array)($_POST['country_of_origin'] ?? []);
            $descriptions = (array)($_POST['description'] ?? []);
            $rows = [];
            foreach ($productNames as $index => $rawName) {
                $name = trim((string)$rawName);
                $categoryId = max(0, (int)($categoryInputs[$index] ?? ($_POST['category_id'] ?? 0)));
                $subcategoryId = max(0, (int)($subcategoryInputs[$index] ?? 0));
                $brand = trim((string)($brands[$index] ?? ''));
                $model = trim((string)($models[$index] ?? ''));
                $manufacturer = trim((string)($manufacturers[$index] ?? ''));
                $supplyEntity = trim((string)($supplyEntities[$index] ?? ''));
                $country = trim((string)($countries[$index] ?? ''));
                $description = trim((string)($descriptions[$index] ?? ''));
                if ($name === '' && $categoryId === 0 && $brand === '' && $model === '' && $manufacturer === '' && $supplyEntity === '' && $country === '' && $description === '') continue;
                if ($name === '') throw new RuntimeException('Product Name is required for every product row.');
                if (!$isProductEdit && $categoryId < 1) throw new RuntimeException('Category is required for every product row.');
                if ($brand === '') throw new RuntimeException('Brand is required for every product row.');
                if ($categoryId > 0) {
                    $categoryCheck = $pdo->prepare('SELECT 1 FROM marketplace_categories WHERE id = ? AND parent_id IS NULL LIMIT 1');
                    $categoryCheck->execute([$categoryId]);
                    if (!$categoryCheck->fetchColumn()) throw new RuntimeException('Choose a valid product category.');
                }
                if ($subcategoryId > 0) {
                    if ($categoryId < 1) throw new RuntimeException('Select a category before choosing a subcategory.');
                    $subcategoryCheck = $pdo->prepare('SELECT 1 FROM marketplace_categories WHERE id = ? AND parent_id = ? LIMIT 1');
                    $subcategoryCheck->execute([$subcategoryId, $categoryId]);
                    if (!$subcategoryCheck->fetchColumn()) throw new RuntimeException('Choose a subcategory that belongs to the selected category.');
                }
                if ($supplyEntity !== '' && !in_array($supplyEntity, $marketplaceSupplyEntities, true)) throw new RuntimeException('Choose a valid Supply Chain Entity.');
                $rows[] = [$name,'',$brand,$model,$manufacturer,$supplyEntity,$country,$description,$categoryId,$subcategoryId,(int)$index];
            }
            if (!$rows) throw new RuntimeException('Add at least one product with a Product Name.');
            $productId = max(0, (int)($_POST['product_id'] ?? 0));
            if ($productId > 0) {
                $existingProductQuery = $pdo->prepare('SELECT p.*,c.name AS category_name,sc.name AS subcategory_name FROM marketplace_products p LEFT JOIN marketplace_categories c ON c.id=p.category_id LEFT JOIN marketplace_categories sc ON sc.id=p.subcategory_id WHERE p.id=? LIMIT 1');
                $existingProductQuery->execute([$productId]);
                $existingProduct = $existingProductQuery->fetch(PDO::FETCH_ASSOC);
                if (!$existingProduct) throw new RuntimeException('The product to update could not be found.');
                $row = $rows[0];
                $newCategoryId = $row[8] > 0 ? $row[8] : null;
                $newSubcategoryId = $row[9] > 0 ? $row[9] : null;
                $newCategoryName = null;
                $newSubcategoryName = null;
                if ($newCategoryId !== null) {
                    $categoryNameQuery = $pdo->prepare('SELECT name FROM marketplace_categories WHERE id=? AND parent_id IS NULL LIMIT 1');
                    $categoryNameQuery->execute([$newCategoryId]);
                    $newCategoryName = $categoryNameQuery->fetchColumn() ?: null;
                    if ($newCategoryName === null) throw new RuntimeException('Choose a valid product category.');
                }
                if ($newSubcategoryId !== null) {
                    $subcategoryNameQuery = $pdo->prepare('SELECT name FROM marketplace_categories WHERE id=? AND parent_id=? LIMIT 1');
                    $subcategoryNameQuery->execute([$newSubcategoryId,$newCategoryId]);
                    $newSubcategoryName = $subcategoryNameQuery->fetchColumn() ?: null;
                    if ($newSubcategoryName === null) throw new RuntimeException('Choose a subcategory that belongs to the selected category.');
                }
                $photoUploads = $_FILES['product_photo'] ?? [];
                $photoError = (int)($photoUploads['error'] ?? UPLOAD_ERR_NO_FILE);
                $newPhotoPath = (string)($existingProduct['photo_path'] ?? '');
                $newPhotoFile = null;
                $oldPhotoFile = null;
                if ($photoError !== UPLOAD_ERR_NO_FILE) {
                    if ($photoError !== UPLOAD_ERR_OK) throw new RuntimeException('The product photo could not be uploaded.');
                    $temporaryPath = (string)($photoUploads['tmp_name'] ?? '');
                    $fileSize = (int)($photoUploads['size'] ?? 0);
                    if ($fileSize < 1 || $fileSize >= 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) throw new RuntimeException('The product photo must be a valid image smaller than 5 MB.');
                    $imageInfo = @getimagesize($temporaryPath);
                    $mime = strtolower((string)($imageInfo['mime'] ?? ''));
                    $extension = strtolower(pathinfo((string)($photoUploads['name'] ?? ''), PATHINFO_EXTENSION));
                    $allowedImageTypes = ['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp'],'image/avif'=>['avif']];
                    if (!isset($allowedImageTypes[$mime]) || !in_array($extension, $allowedImageTypes[$mime], true)) throw new RuntimeException('Product photos must be JPG, JPEG, WEBP, PNG, or AVIF images smaller than 5 MB.');
                    $uploadDirectory = dirname(__DIR__) . '/public/uploads/products';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory,0755,true) && !is_dir($uploadDirectory)) throw new RuntimeException('The product photo upload folder could not be created.');
                    $safeProductName = trim((string)preg_replace('/[^A-Za-z0-9]+/','_',$row[0]),'_');
                    if ($safeProductName === '') $safeProductName = 'product';
                    $photoFilename = (string)$existingProduct['product_code'] . '_' . $safeProductName . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $destinationPath = $uploadDirectory . '/' . $photoFilename;
                    if (!move_uploaded_file($temporaryPath,$destinationPath)) throw new RuntimeException('The new product photo could not be saved.');
                    $newPhotoFile = $destinationPath;
                    $newPhotoPath = 'uploads/products/' . $photoFilename;
                    $oldPhotoRelative = (string)($existingProduct['photo_path'] ?? '');
                    if ($oldPhotoRelative !== '') {
                        $oldFilename = basename(str_replace('\\','/',$oldPhotoRelative));
                        $oldPhotoFile = $uploadDirectory . DIRECTORY_SEPARATOR . $oldFilename;
                    }
                }
                try {
                    $update = $pdo->prepare('UPDATE marketplace_products SET category_id=?,subcategory_id=?,product_name=?,brand=?,model=?,description=?,photo_path=? WHERE id=?');
                    $update->execute([$newCategoryId,$newSubcategoryId,$row[0],$row[2] ?: null,$row[3] ?: null,$row[7] ?: null,$newPhotoPath !== '' ? $newPhotoPath : null,$productId]);
                } catch (Throwable $updateError) {
                    if ($newPhotoFile !== null && is_file($newPhotoFile)) @unlink($newPhotoFile);
                    throw $updateError;
                }
                if ($newPhotoFile !== null && $oldPhotoFile !== null && is_file($oldPhotoFile) && realpath($oldPhotoFile) !== realpath($newPhotoFile)) @unlink($oldPhotoFile);
                $categoryChanged = (int)($existingProduct['category_id'] ?? 0) !== (int)($newCategoryId ?? 0);
                $subcategoryChanged = (int)($existingProduct['subcategory_id'] ?? 0) !== (int)($newSubcategoryId ?? 0);
                $nameChanged = (string)$existingProduct['product_name'] !== $row[0];
                $modelChanged = (string)($existingProduct['model'] ?? '') !== $row[3];
                $descriptionChanged = (string)($existingProduct['description'] ?? '') !== $row[7];
                $photoChanged = $newPhotoFile !== null;
                $brandChanged = (string)($existingProduct['brand'] ?? '') !== $row[2];
                $changedCount = (int)$categoryChanged + (int)$subcategoryChanged + (int)$nameChanged + (int)$modelChanged + (int)$descriptionChanged + (int)$photoChanged + (int)$brandChanged;
                if (($categoryChanged || $subcategoryChanged) && $changedCount === (int)$categoryChanged + (int)$subcategoryChanged) {
                    $marketplaceProductEditSuccess = ['type'=>'category','old_category'=>(string)($existingProduct['category_name'] ?? 'Uncategorized'),'new_category'=>(string)($newCategoryName ?? 'Uncategorized'),'subcategory'=>(string)($newSubcategoryName ?? 'None')];
                } elseif ($nameChanged && $changedCount === 1) {
                    $marketplaceProductEditSuccess = ['type'=>'name','old_name'=>(string)$existingProduct['product_name'],'new_name'=>$row[0]];
                } elseif ($modelChanged && $changedCount === 1) {
                    $marketplaceProductEditSuccess = ['type'=>'model','old_model'=>(string)($existingProduct['model'] ?? ''),'new_model'=>$row[3],'product_name'=>$row[0]];
                } elseif ($descriptionChanged && $changedCount === 1) {
                    $marketplaceProductEditSuccess = ['type'=>'description','product_name'=>$row[0]];
                } elseif ($photoChanged && $changedCount === 1) {
                    $marketplaceProductEditSuccess = ['type'=>'photo','product_name'=>$row[0]];
                } else {
                    $marketplaceProductEditSuccess = ['type'=>'details','product_name'=>$row[0]];
                }
                $marketplaceNotice = '';
                $marketplaceTab = 'products';
                $marketplaceEditProductId = 0;
            } else {
                foreach ($rows as $candidateRow) {
                    $duplicateProductQuery = $pdo->prepare("SELECT id FROM marketplace_products
                        WHERE category_id <=> ? AND subcategory_id <=> ?
                          AND LOWER(TRIM(product_name)) = LOWER(?)
                          AND LOWER(TRIM(COALESCE(brand, ''))) = LOWER(?)
                          AND LOWER(TRIM(COALESCE(model, ''))) = LOWER(?)
                          AND TRIM(COALESCE(description, '')) = ?
                        LIMIT 1");
                    $duplicateProductQuery->execute([$candidateRow[8] ?: null,$candidateRow[9] ?: null,$candidateRow[0],$candidateRow[2],$candidateRow[3],$candidateRow[7]]);
                    if ($duplicateProductQuery->fetchColumn()) {
                        $marketplaceDuplicateProduct = true;
                        $marketplaceProductInputRows = array_map(static fn($row) => [
                            'category_id'=>$row[8], 'subcategory_id'=>$row[9], 'product_name'=>$row[0],
                            'brand'=>$row[2], 'model'=>$row[3], 'description'=>$row[7]
                        ], $rows);
                        throw new RuntimeException('DUPLICATE_PRODUCT_MATCH');
                    }
                }
                $reservedCodes = [];
                foreach ($rows as &$row) {
                    do {
                        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                        $suffix = '';
                        for ($i = 0; $i < 8; $i++) $suffix .= $alphabet[random_int(0, 61)];
                        $generatedCode = 'PROD-' . date('Y') . $suffix;
                        $exists = $pdo->prepare('SELECT 1 FROM marketplace_products WHERE product_code = ? LIMIT 1');
                        $exists->execute([$generatedCode]);
                    } while ($exists->fetchColumn() || in_array($generatedCode, $reservedCodes, true));
                    $row[1] = $generatedCode;
                    $reservedCodes[] = $generatedCode;
                }
                unset($row);
                $uploadedProductFiles = [];
                $productPhotoUploads = $_FILES['product_photo'] ?? [];
                $uploadDirectory = dirname(__DIR__) . '/public/uploads/products';
                $uploadUrlDirectory = 'uploads/products';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) throw new RuntimeException('The product photo upload folder could not be created.');
                $pdo->beginTransaction();
                try {
                    $insert = $pdo->prepare('INSERT INTO marketplace_products(category_id,subcategory_id,product_name,product_code,brand,model,manufacturer,supply_chain_entity,country_of_origin,description,photo_path) VALUES(?,?,?,?,?,?,?,?,?,?,NULL)');
                    $photoUpdate = $pdo->prepare('UPDATE marketplace_products SET photo_path=? WHERE id=?');
                    foreach ($rows as $index => $row) {
                        $insert->execute([$row[8] ?: null,$row[9] ?: null,$row[0],$row[1],$row[2] ?: null,$row[3] ?: null,$row[4] ?: null,$row[5] ?: null,$row[6] ?: null,$row[7] ?: null]);
                        $newProductId = (int)$pdo->lastInsertId();
                        $uploadIndex = (int)($row[10] ?? $index);
                        $uploadError = (int)($productPhotoUploads['error'][$uploadIndex] ?? UPLOAD_ERR_NO_FILE);
                        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                            if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('The photo for "' . $row[0] . '" could not be uploaded.');
                            $temporaryPath = (string)($productPhotoUploads['tmp_name'][$uploadIndex] ?? '');
                            $fileSize = (int)($productPhotoUploads['size'][$uploadIndex] ?? 0);
                            if ($fileSize < 1 || $fileSize >= 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) throw new RuntimeException('Each product photo must be a valid image smaller than 5 MB.');
                            $imageInfo = @getimagesize($temporaryPath);
                            $mime = strtolower((string)($imageInfo['mime'] ?? ''));
                            $originalExtension = strtolower(pathinfo((string)($productPhotoUploads['name'][$uploadIndex] ?? ''), PATHINFO_EXTENSION));
                            $allowedImageTypes = ['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp'],'image/avif'=>['avif']];
                            if (!isset($allowedImageTypes[$mime]) || !in_array($originalExtension, $allowedImageTypes[$mime], true)) throw new RuntimeException('Product photos must be JPG, JPEG, WEBP, PNG, or AVIF images smaller than 5 MB.');
                            $safeProductName = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $row[0]), '_');
                            if ($safeProductName === '') $safeProductName = 'product';
                            $photoFilename = $row[1] . '_' . $safeProductName . '.' . $originalExtension;
                            $destinationPath = $uploadDirectory . '/' . $photoFilename;
                            if (!move_uploaded_file($temporaryPath, $destinationPath)) throw new RuntimeException('The photo for "' . $row[0] . '" could not be saved.');
                            $uploadedProductFiles[] = $destinationPath;
                            $photoUpdate->execute([$uploadUrlDirectory . '/' . $photoFilename, $newProductId]);
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $insertError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    foreach ($uploadedProductFiles as $uploadedFile) if (is_file($uploadedFile)) @unlink($uploadedFile);
                    throw $insertError;
                }
                $marketplaceCreatedProducts = array_map(static fn($row) => ['name'=>$row[0], 'code'=>$row[1]], $rows);
                $marketplaceNotice = '';
                $marketplaceTab = 'products';
                $marketplaceEditProductId = 0;
            }
        } elseif ($action === 'marketplace_save_inventory') {
            $inventoryId = max(0, (int)($_POST['inventory_id'] ?? 0));
            $productId = max(0, (int)($_POST['inventory_product_id'] ?? 0));
            $batchNumber = trim((string)($_POST['batch_number'] ?? ''));
            $sku = $batchNumber;
            $stock = max(0, (float)str_replace(',', '', (string)($_POST['stock_on_hand'] ?? 0)));
            $sold = max(0, (int)($_POST['sold'] ?? 0));
            $vatRate = max(0, (float)str_replace(',', '', (string)($_POST['vat_rate'] ?? 0)));
            $percentageTaxRate = max(0, (float)str_replace(',', '', (string)($_POST['percentage_tax_rate'] ?? 0)));
            $incomeTaxRate = max(0, (float)str_replace(',', '', (string)($_POST['income_tax_rate'] ?? 0)));
            $withholdingTaxRate = max(0, (float)str_replace(',', '', (string)($_POST['withholding_tax_rate'] ?? 0)));
            $otherTaxRate = max(0, (float)str_replace(',', '', (string)($_POST['other_tax_rate'] ?? 0)));
            $tax = $vatRate + $percentageTaxRate + $incomeTaxRate + $withholdingTaxRate + $otherTaxRate;
            $retailPrice = max(0, (float)str_replace(',', '', (string)($_POST['retail_price'] ?? 0)));
            $sellingPrice = round($retailPrice * (1 + $tax), 2);
            $unitPrice = $sellingPrice;
            $status = trim((string)($_POST['inventory_status'] ?? 'Low Stock'));
            $productCheck = $pdo->prepare('SELECT id FROM marketplace_products WHERE id=? LIMIT 1');
            $productCheck->execute([$productId]);
            if (!$productCheck->fetchColumn()) throw new RuntimeException('Select a valid product before saving inventory.');
            if ($batchNumber === '') throw new RuntimeException('Please enter a Batch Number.');
            if (!in_array($status, $marketplaceInventoryStatuses, true)) throw new RuntimeException('Choose a valid inventory status.');
            if ($sold > $stock) throw new RuntimeException('Sold quantity cannot exceed Stock on Hand.');
            $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
            $supplyEntity = trim((string)($_POST['supply_chain_type'] ?? ''));
            $country = trim((string)($_POST['country_of_origin'] ?? ''));
            if (!in_array($supplyEntity, $marketplaceSupplyEntities, true)) throw new RuntimeException('Choose a valid Supply Chain Type.');
            $productMetaUpdate = $pdo->prepare('UPDATE marketplace_products SET manufacturer=?,supply_chain_entity=?,country_of_origin=? WHERE id=?');
            $productMetaUpdate->execute([$manufacturer ?: null,$supplyEntity ?: null,$country ?: null,$productId]);
            if ($inventoryId > 0) {
                $stmt = $pdo->prepare('UPDATE marketplace_inventory SET product_id=?,sku=?,batch_number=?,supply_chain_type=?,stock_on_hand=?,sold=?,tax_rate=?,vat_rate=?,percentage_tax_rate=?,income_tax_rate=?,withholding_tax_rate=?,other_tax_rate=?,unit_price=?,retail_price=?,selling_price=?,status=? WHERE id=?');
                $stmt->execute([$productId,$sku,$batchNumber,$supplyEntity,$stock,$sold,$tax,$vatRate,$percentageTaxRate,$incomeTaxRate,$withholdingTaxRate,$otherTaxRate,$unitPrice,$retailPrice,$sellingPrice,$status,$inventoryId]);
                $marketplaceNotice = 'Inventory record successfully updated.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO marketplace_inventory(product_id,sku,batch_number,supply_chain_type,stock_on_hand,sold,tax_rate,vat_rate,percentage_tax_rate,income_tax_rate,withholding_tax_rate,other_tax_rate,unit_price,retail_price,selling_price,status,latest_loaded) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
                $stmt->execute([$productId,$sku,$batchNumber,$supplyEntity,$stock,$sold,$tax,$vatRate,$percentageTaxRate,$incomeTaxRate,$withholdingTaxRate,$otherTaxRate,$unitPrice,$retailPrice,$sellingPrice,$status]);
                $marketplaceNotice = 'Inventory record successfully created.';
            }
            $marketplaceTab = 'inventory';
            $marketplaceEditInventoryId = 0;
        } elseif ($action === 'marketplace_add_stock') {
            $inventoryId = max(0, (int)($_POST['inventory_id'] ?? 0));
            $quantity = max(0, (float)str_replace(',', '', (string)($_POST['load_quantity'] ?? 0)));
            if ($quantity <= 0) throw new RuntimeException('Enter a loaded quantity greater than zero.');
            $stmt = $pdo->prepare('UPDATE marketplace_inventory SET stock_on_hand=stock_on_hand+?,latest_loaded=NOW() WHERE id=?');
            $stmt->execute([$quantity,$inventoryId]);
            if (!$stmt->rowCount()) throw new RuntimeException('The inventory record could not be found.');
            $marketplaceNotice = 'Stock successfully loaded.';
            $marketplaceTab = 'inventory';
        }
    } catch (Throwable $exception) {
        if (($action ?? '') === 'marketplace_save_product' && $exception instanceof RuntimeException && $exception->getMessage() === 'DUPLICATE_PRODUCT_MATCH') {
            $marketplaceDuplicateProduct = true;
            if (!$marketplaceProductInputRows) $marketplaceProductInputRows = [[
                'category_id'=>(int)($_POST['category_id'][0]??0), 'subcategory_id'=>(int)($_POST['subcategory_id'][0]??0),
                'product_name'=>trim((string)($_POST['product_name'][0]??'')), 'brand'=>trim((string)($_POST['brand'][0]??'')),
                'model'=>trim((string)($_POST['model'][0]??'')), 'description'=>trim((string)($_POST['description'][0]??''))
            ]];
            $marketplaceError = '';
        } elseif (($action ?? '') === 'marketplace_save_category' && $exception instanceof PDOException && (string)$exception->getCode() === '23000') {
            $marketplaceDuplicateCategoryName = trim((string)($_POST['category_name'] ?? ''));
            $marketplaceCategoryInputName = $marketplaceDuplicateCategoryName;
            $marketplaceCategoryInputDescription = trim((string)($_POST['category_description'] ?? ''));
        } elseif (($action ?? '') === 'marketplace_save_subcategory' && $exception instanceof PDOException && (string)$exception->getCode() === '23000') {
            $marketplaceDuplicateSubcategoryName = trim((string)($_POST['subcategory_name'] ?? ''));
            $marketplaceSubcategoryInputParentId = max(0, (int)($_POST['parent_category_id'] ?? 0));
            $marketplaceSubcategoryInputName = $marketplaceDuplicateSubcategoryName;
            $marketplaceSubcategoryInputDescription = trim((string)($_POST['subcategory_description'] ?? ''));
        } else {
            $marketplaceError = $exception instanceof PDOException && (string)$exception->getCode() === '23000'
                ? 'That category name or SKU already exists. Please use a unique value.'
                : $exception->getMessage();
        }
        $marketplaceTab = match ((string)($_POST['action'] ?? '')) {
            'marketplace_save_category','marketplace_save_subcategory','marketplace_delete_categories' => 'category',
            'marketplace_save_inventory','marketplace_add_stock' => 'inventory',
            default => 'products'
        };
    }
}

$marketplaceCategories = $pdo->query('SELECT id,parent_id,name,description,created_at FROM marketplace_categories ORDER BY COALESCE(parent_id,id) DESC, (parent_id IS NULL) DESC, created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$marketplaceProducts = $pdo->query("SELECT p.*,c.name AS category_name,sc.name AS subcategory_name,i.id AS inventory_id,i.sku,i.batch_number,i.supply_chain_type,i.stock_on_hand,i.sold,i.tax_rate,i.vat_rate,i.percentage_tax_rate,i.income_tax_rate,i.withholding_tax_rate,i.other_tax_rate,i.unit_price,i.retail_price,i.selling_price,i.status,i.latest_loaded,i.created_at AS inventory_created_at,i.updated_at AS inventory_updated_at FROM marketplace_products p LEFT JOIN marketplace_categories c ON c.id=p.category_id LEFT JOIN marketplace_categories sc ON sc.id=p.subcategory_id LEFT JOIN marketplace_inventory i ON i.product_id=p.id ORDER BY p.created_at DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$marketplaceAddInventoryProduct = null;
if ($marketplaceAddInventoryProductId > 0) {
    foreach ($marketplaceProducts as $candidateProduct) {
        if ((int)$candidateProduct['id'] === $marketplaceAddInventoryProductId && empty($candidateProduct['inventory_id'])) {
            $marketplaceAddInventoryProduct = $candidateProduct;
            break;
        }
    }
}
$marketplaceInventory = $pdo->query("SELECT p.id AS linked_product_id,p.product_name,p.product_code,p.brand,p.model,p.description,p.photo_path,p.manufacturer,p.supply_chain_entity,p.country_of_origin,c.name AS category_name,sc.name AS subcategory_name,i.id,i.product_id,i.sku,i.batch_number,i.supply_chain_type,i.stock_on_hand,i.sold,i.tax_rate,i.unit_price,i.retail_price,i.selling_price,i.status,i.latest_loaded,i.created_at,i.updated_at FROM marketplace_products p LEFT JOIN marketplace_inventory i ON i.product_id=p.id LEFT JOIN marketplace_categories c ON c.id=p.category_id LEFT JOIN marketplace_categories sc ON sc.id=p.subcategory_id ORDER BY p.created_at DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
    if ($marketplaceInventoryForm) {
        $inventoryProductMeta = $pdo->prepare('SELECT manufacturer,supply_chain_entity,country_of_origin FROM marketplace_products WHERE id=? LIMIT 1');
        $inventoryProductMeta->execute([(int)$marketplaceInventoryForm['product_id']]);
        $marketplaceInventoryForm = array_merge($marketplaceInventoryForm, $inventoryProductMeta->fetch(PDO::FETCH_ASSOC) ?: []);
    }
}
$money = static fn($value): string => '₱' . number_format((float)$value, 2);
$quantityFormat = static fn($value): string => number_format((float)$value, 2, '.', ',');
$taxFormat = static fn($value): string => number_format((float)$value, 2, '.', '');
$date = static function($value): string { $time=strtotime((string)$value); return $time ? date('M d, Y · h:i A',$time) : '—'; };
$numberToWords = static function(int $number): string {
    if ($number <= 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $convertUnderThousand = static function(int $value) use ($ones, $tens): string {
        $words = [];
        if ($value >= 100) { $words[] = $ones[intdiv($value, 100)] . ' Hundred'; $value %= 100; }
        if ($value >= 20) { $words[] = $tens[intdiv($value, 10)] . (($value % 10) ? '-' . $ones[$value % 10] : ''); }
        elseif ($value > 0) { $words[] = $ones[$value]; }
        return implode(' ', $words);
    };
    $parts = [];
    foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 1 => ''] as $scale => $label) {
        if ($number >= $scale) {
            $chunk = intdiv($number, $scale);
            $number %= $scale;
            $parts[] = $convertUnderThousand($chunk) . ($label !== '' ? ' ' . $label : '');
        }
    }
    return implode(' ', $parts);
};
?>
<div class="admin-topline">
 <div><div class="eyebrow">Community commerce</div><h1 class="admin-heading">Marketplace</h1><p class="muted">Manage products, stock, categories, purchases and invoices.</p></div>
</div>
<style>
.marketplace-inventory-table{min-width:1180px}.marketplace-inventory-table th,.marketplace-inventory-table td{vertical-align:top}.marketplace-inventory-product{min-width:245px;max-width:320px}.marketplace-inventory-product strong{display:block;color:var(--text);margin-bottom:5px}.marketplace-inventory-product small{display:block;white-space:normal;line-height:1.45;margin-top:2px}.marketplace-inventory-status{display:inline-block;padding:4px 8px;border-radius:5px;background:rgba(160,35,48,.12);font-size:11px;font-weight:700;white-space:nowrap}.marketplace-inventory-actions{display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:7px;min-width:150px;white-space:nowrap}.marketplace-add-stock-form{display:flex;gap:5px;align-items:center;margin:0}.marketplace-add-stock-form input{width:58px;min-width:0;padding:7px 5px}.marketplace-inventory-actions .btn{justify-content:center}.marketplace-product-delete-confirm-card,.marketplace-product-delete-success-card{width:min(680px,calc(100vw - 36px));max-height:min(82vh,760px);overflow:auto}
.marketplace-product-delete-confirm-card #marketplace-products-delete-confirm-title{font-size:24px;line-height:1.25;margin:0 0 14px}.marketplace-product-delete-confirm-card p,.marketplace-product-delete-success-card p{color:#e8dfe2;line-height:1.65}.marketplace-product-delete-list{margin:14px 0 18px;color:#f3e9ec}.marketplace-product-delete-list.is-single-product{display:grid;gap:9px;padding:0;text-align:center}.marketplace-product-delete-list.is-single-product .marketplace-product-delete-detail{justify-content:center;text-align:center;width:100%}.marketplace-product-delete-detail{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap;line-height:1.6}.marketplace-product-delete-list.is-multiple-products{padding:0;margin:14px 0 18px}.marketplace-product-delete-table-wrap{display:flex;justify-content:center;align-items:flex-start;width:100%;max-width:100%;text-align:left}.marketplace-product-delete-table{width:min(450px,100%);max-width:100%;margin:0;border:0;border-collapse:collapse;border-spacing:0;table-layout:fixed;color:#f3e9ec;text-align:left}.marketplace-product-delete-table tr,.marketplace-product-delete-table th,.marketplace-product-delete-table td{border:0!important;background:transparent!important}.marketplace-product-delete-table th{padding:5px 6px;text-align:left;vertical-align:middle;font-size:12px;font-weight:800;color:#f3e9ec;line-height:1.4}.marketplace-product-delete-table td{padding:5px 6px;text-align:left;vertical-align:middle;line-height:1.6;overflow-wrap:anywhere}.marketplace-product-delete-table th:first-child,.marketplace-product-delete-table .marketplace-delete-row-number{width:28px;text-align:right;white-space:nowrap;color:#f3e9ec;font-size:10px}.marketplace-product-delete-table th:nth-child(2),.marketplace-product-delete-table .marketplace-delete-product-id-cell{width:42%;text-align:left;font-size:12px}.marketplace-product-delete-table th:nth-child(3),.marketplace-product-delete-table .marketplace-delete-product-name-cell{width:58%;text-align:left;font-size:12px}.marketplace-product-delete-table .marketplace-delete-name{font-size:12px;font-weight:900}.marketplace-product-delete-table .marketplace-delete-code{font-size:12px;font-weight:900}.marketplace-product-delete-list li>span,.marketplace-product-delete-detail>span{color:#f3e9ec}.marketplace-delete-name{color:#ed526b!important;font-weight:900}.marketplace-delete-code,.marketplace-delete-count{color:#f1c94b!important;font-weight:900}.marketplace-product-delete-success-card{text-align:center}.marketplace-product-delete-success-card p{margin:12px 0 20px}.marketplace-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 20px;padding:7px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.025)}
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
.marketplace-product-add-card{width:min(1600px,96vw)!important;max-width:1600px!important;max-height:88vh;overflow-x:hidden;overflow-y:auto}
#marketplace-inventory-add-modal .marketplace-product-add-card{width:min(600px,94vw)!important;max-width:600px!important;box-sizing:border-box}.marketplace-product-entry-row{display:grid;grid-template-columns:minmax(155px,1.45fr) minmax(135px,1.25fr) minmax(100px,.9fr) minmax(90px,.8fr) minmax(150px,1.35fr) minmax(190px,1.7fr) auto;align-items:end;gap:8px;min-width:0;width:100%;max-width:100%;box-sizing:border-box;padding:12px;margin:12px 0;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.025)}.marketplace-product-entry-row label{display:grid;gap:6px;min-width:0;max-width:100%;overflow:hidden;color:#d7cbd0;font:12px Ubuntu,sans-serif}.marketplace-product-entry-row label.wide{grid-column:auto;min-width:0}.marketplace-product-entry-row input,.marketplace-product-entry-row select,.marketplace-product-entry-row textarea{box-sizing:border-box;width:100%;min-width:0;max-width:100%;height:36px;min-height:36px;padding:0 8px;border:1px solid var(--line);border-radius:8px;background:#171317;color:#fff;font:12px Ubuntu,sans-serif}.marketplace-product-entry-row input[type=file]{padding:7px 5px;font-size:10px}.marketplace-product-entry-row textarea{padding:9px 10px;resize:vertical}.marketplace-product-category-group{min-width:0;display:grid;gap:6px}.marketplace-product-category-selects{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;min-width:0;width:100%}.marketplace-product-category-selects select{min-width:0;width:100%}.marketplace-product-edit-photo-preview-wrap{display:flex;align-items:center;justify-content:center;min-height:132px;padding:10px;border:1px dashed rgba(208,139,158,.45);border-radius:10px;background:rgba(255,255,255,.025);color:#bfaeb5;font:12px Ubuntu,sans-serif}.marketplace-product-edit-photo-preview{display:block;max-width:100%;max-height:190px;object-fit:contain;border-radius:7px}.marketplace-product-edit-photo-preview[hidden]{display:none!important}.marketplace-product-edit-card{width:min(600px,94vw)!important;max-width:600px!important;max-height:88vh;overflow-x:hidden;overflow-y:auto;padding:20px!important;box-sizing:border-box}.marketplace-product-edit-card h2{margin:0 0 14px}.marketplace-product-edit-form{display:grid;grid-template-columns:minmax(0,1fr);gap:10px;padding:0;margin:0;border:0;background:transparent}.marketplace-product-edit-form>.marketplace-product-category-group{display:grid;grid-template-columns:118px minmax(0,1fr);align-items:center;gap:10px;min-width:0;width:100%;box-sizing:border-box}.marketplace-product-edit-form .marketplace-product-category-label,.marketplace-product-edit-form .marketplace-edit-row-label{display:block;margin:0;color:#d7cbd0;font:12px Ubuntu,sans-serif;line-height:1.4}.marketplace-product-edit-form .marketplace-product-category-selects{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;min-width:0;width:100%}.marketplace-product-edit-form .marketplace-product-category-selects select{width:100%;min-width:0;box-sizing:border-box}.marketplace-product-edit-form .marketplace-edit-row{display:grid;grid-template-columns:118px minmax(0,1fr);align-items:center;gap:10px;min-width:0;margin:0;color:#d7cbd0;font:12px Ubuntu,sans-serif}.marketplace-product-edit-form .marketplace-edit-row>span{display:block}.marketplace-product-edit-form .marketplace-edit-row input,.marketplace-product-edit-form .marketplace-edit-row textarea{width:100%;min-width:0;box-sizing:border-box}.marketplace-product-edit-form .marketplace-edit-row input[readonly]{background:#29252a!important;color:#999!important;cursor:not-allowed}.marketplace-product-edit-form .marketplace-edit-description-row{align-items:start}.marketplace-product-edit-form .marketplace-edit-description-row textarea{min-height:66px}.marketplace-product-edit-form>.marketplace-product-edit-photo-layout{display:block;min-width:0}.marketplace-product-edit-form .marketplace-product-edit-photo-field{display:grid;gap:8px;min-width:0}.marketplace-product-edit-form .marketplace-edit-upload-row{display:grid;grid-template-columns:118px minmax(0,1fr);align-items:start;gap:10px;min-width:0}.marketplace-product-edit-form .marketplace-edit-upload-control{display:grid;gap:6px;min-width:0}.marketplace-product-edit-form .marketplace-edit-upload-control input[type=file]{width:100%;max-width:100%;min-width:0;box-sizing:border-box}.marketplace-product-edit-form .marketplace-edit-upload-control small{color:#c7b7be;font:11px Ubuntu,sans-serif}.marketplace-product-edit-form .marketplace-product-edit-photo-previews{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;min-width:0}.marketplace-product-edit-form .marketplace-product-edit-photo-current{display:grid;gap:7px;min-width:0}.marketplace-product-edit-form>.marketplace-category-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:4px}@media(max-width:480px){.marketplace-product-edit-card{width:94vw!important;padding:16px!important}.marketplace-product-edit-form>.marketplace-product-category-group,.marketplace-product-edit-form .marketplace-edit-row,.marketplace-product-edit-form .marketplace-edit-upload-row{grid-template-columns:1fr;gap:6px}.marketplace-product-edit-form .marketplace-product-edit-photo-previews{grid-template-columns:1fr}}.marketplace-product-edit-photo-field input[type=file]{grid-column:1;width:100%;max-width:100%;min-width:0;box-sizing:border-box}.marketplace-product-edit-form .marketplace-product-edit-photo-field small{grid-column:1;min-width:0}.marketplace-product-edit-form .marketplace-product-edit-photo-previews{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;min-width:0}.marketplace-product-edit-form .marketplace-product-edit-photo-current{display:grid;gap:8px;min-width:0}.marketplace-product-edit-form>.marketplace-category-modal-actions{grid-column:1/-1}@media(max-width:700px){.marketplace-product-edit-form{grid-template-columns:1fr}.marketplace-product-edit-form>.marketplace-product-category-group,.marketplace-product-edit-form>.marketplace-product-edit-photo-layout,.marketplace-product-edit-form>.marketplace-category-modal-actions{grid-column:1}.marketplace-product-edit-form .marketplace-product-category-selects,.marketplace-product-edit-form .marketplace-product-edit-photo-previews{grid-template-columns:1fr}}.marketplace-product-edit-photo-field input[type=file]{box-sizing:border-box;width:100%;max-width:100%;padding:8px;border:1px solid var(--line);border-radius:8px;background:#171317;color:#fff;font:11px Ubuntu,sans-serif}.marketplace-product-edit-photo-field small{color:#c7b7be;font:11px Ubuntu,sans-serif}.marketplace-product-row-actions{display:flex;align-items:end;justify-content:flex-end}.marketplace-product-row-actions .btn{white-space:nowrap;padding-left:10px;padding-right:10px}@media(max-width:1100px){.marketplace-product-entry-row{grid-template-columns:repeat(3,minmax(0,1fr))}.marketplace-product-category-group{grid-column:span 2}.marketplace-product-entry-row label:nth-of-type(4){grid-column:span 1}.marketplace-product-row-actions{justify-content:flex-start}}@media(max-width:650px){.marketplace-product-edit-photo-field,.marketplace-product-edit-photo-previews{grid-template-columns:1fr}.marketplace-product-category-label{max-width:100%}.marketplace-product-entry-row{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.marketplace-product-category-group{grid-column:1/-1}.marketplace-product-category-selects{grid-template-columns:repeat(2,minmax(0,1fr))}.marketplace-product-entry-row label:nth-of-type(5),.marketplace-product-entry-row label:nth-of-type(6){grid-column:1/-1}.marketplace-product-row-actions{grid-column:1/-1}}.marketplace-product-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px}.marketplace-product-name-highlight{color:#e34b62;font-weight:800}.marketplace-product-id-highlight{color:#f2d35e;font-weight:800}.marketplace-product-created-list{width:100%;box-sizing:border-box;margin:22px 0;padding-left:24px;text-align:left;color:#e9dce1;font:14px/1.9 Ubuntu,sans-serif}.marketplace-product-created-card{max-height:85vh;overflow:auto}.marketplace-category-modal[hidden]{display:none!important}@media(max-width:560px){.marketplace-product-modal-footer{align-items:flex-start;flex-direction:column}.marketplace-product-modal-footer>div{display:flex;gap:8px;align-self:flex-end}}.marketplace-category-panel>.admin-panel-head{margin-bottom:20px}
 .marketplace-category-page-actions,.marketplace-product-page-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.marketplace-category-page-actions .btn,.marketplace-product-page-actions .btn{white-space:nowrap}

/* Match the Products Edit action to the My Account Edit control. */
/* Mirror the exact My Account Edit control values; !important prevents generic table/action rules from overriding them. */
.marketplace-inventory-table .marketplace-inventory-actions > a,
.marketplace-inventory-table .marketplace-inventory-actions > form > button{
 display:inline-flex!important;align-items:center!important;justify-content:center!important;
 min-width:0!important;min-height:28px!important;height:28px!important;box-sizing:border-box!important;
 padding:5px 13px!important;border:1px solid rgba(208,139,158,.38)!important;border-radius:999px!important;
 background:rgba(125,45,67,.16)!important;color:#f0c4cf!important;
 font:600 10px Ubuntu,sans-serif!important;letter-spacing:.02em!important;line-height:1!important;
 text-decoration:none!important;white-space:nowrap!important;cursor:pointer!important;
 transition:background .18s ease,border-color .18s ease,transform .18s ease!important;
}
.marketplace-inventory-table .marketplace-inventory-actions > a:hover,
.marketplace-inventory-table .marketplace-inventory-actions > form > button:hover{
 background:rgba(154,55,81,.28)!important;border-color:rgba(226,157,175,.7)!important;transform:translateY(-1px)!important;
}
/* Inventory Add is green; Edit retains the Products Edit button theme. */
.marketplace-inventory-table .marketplace-inventory-actions > a[href*="add_inventory_product"],
.marketplace-inventory-table .marketplace-inventory-actions > form.marketplace-add-stock-form > button{
 border:1px solid #24965b!important;background:#16834b!important;color:#fff!important;
}
.marketplace-inventory-table .marketplace-inventory-actions > a[href*="add_inventory_product"]:hover,
.marketplace-inventory-table .marketplace-inventory-actions > form.marketplace-add-stock-form > button:hover{
 border-color:#39b875!important;background:#1b9b59!important;color:#fff!important;
}
.marketplace-inventory-table .marketplace-inventory-actions > a:focus-visible,
.marketplace-inventory-table .marketplace-inventory-actions > form > button:focus-visible{outline:2px solid #e17a8d!important;outline-offset:2px!important}
table.marketplace-table .marketplace-actions > a.marketplace-product-edit-open{
 display:inline-flex!important;align-items:center!important;justify-content:center!important;
 min-height:28px!important;height:28px!important;box-sizing:border-box!important;
 padding:5px 13px!important;border:1px solid rgba(208,139,158,.38)!important;border-radius:999px!important;
 background:rgba(125,45,67,.16)!important;color:#f0c4cf!important;
 font:600 10px Ubuntu,sans-serif!important;letter-spacing:.02em!important;line-height:1!important;
 text-decoration:none!important;white-space:nowrap!important;cursor:pointer!important;
 transition:background .18s ease,border-color .18s ease,transform .18s ease!important;
}
table.marketplace-table .marketplace-actions > a.marketplace-product-edit-open:hover{
 background:rgba(154,55,81,.28)!important;border-color:rgba(226,157,175,.7)!important;transform:translateY(-1px)!important;
}
table.marketplace-table .marketplace-actions > a.marketplace-product-edit-open:focus-visible{outline:2px solid #e17a8d!important;outline-offset:2px!important}
table.marketplace-table .marketplace-actions{display:flex!important;align-items:center!important;gap:7px!important}
.marketplace-category-table .marketplace-category-actions{white-space:nowrap}.marketplace-category-table .marketplace-category-actions .content-manager-action{margin-right:6px}.marketplace-category-table .marketplace-category-add-subcategory-open{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:5px 13px;border:1px solid #fff;border-radius:999px;background:#fff;color:#571d2d;font:600 10px Ubuntu,sans-serif;line-height:1;cursor:pointer}.marketplace-category-table .marketplace-category-add-subcategory-open:hover{background:#f0f0f0;border-color:#f0f0f0;color:#421522} .marketplace-subcategory-name-highlight{color:#c62828;font-weight:800;}.marketplace-subcategory-new-name{color:#22a565;font-weight:800;}
#marketplace-subcategory-update-success-modal .marketplace-category-delete-success-card p strong.marketplace-category-old-name{color:#e34b62!important;-webkit-text-fill-color:#e34b62!important;font-weight:800!important;}
#marketplace-subcategory-update-success-modal .marketplace-category-delete-success-card p strong.marketplace-subcategory-new-name{color:#22a565!important;-webkit-text-fill-color:#22a565!important;font-weight:800!important;}
 .marketplace-subcategory-indent{display:inline-block;margin-right:7px;color:#d98a9d;font-size:16px;font-weight:700}
.marketplace-category-table td.marketplace-category-name-cell{position:relative;overflow:visible;vertical-align:middle}
.marketplace-category-table td.marketplace-category-name-cell.is-subcategory{padding-left:88px!important;vertical-align:middle}
.marketplace-category-table td.marketplace-category-name-cell.is-subcategory strong{position:absolute;left:88px;top:50%;display:block;transform:translateY(-50%);line-height:1.2;white-space:nowrap}
.marketplace-category-tree-arrow{position:absolute;left:38px;top:0;width:40px;height:50%;border-left:2px solid #d98a9d;border-bottom:2px solid #d98a9d;border-bottom-left-radius:15px;box-sizing:border-box}
.marketplace-category-tree-arrow:after{content:"";position:absolute;right:-1px;bottom:-6px;width:10px;height:10px;border-top:2px solid #d98a9d;border-right:2px solid #d98a9d;transform:rotate(45deg);box-sizing:border-box}
.marketplace-category-table{width:100%;min-width:0;table-layout:auto}.marketplace-category-table th,.marketplace-category-table td{white-space:normal;overflow-wrap:anywhere;word-break:normal}.marketplace-category-table th:nth-child(2),.marketplace-category-table td.marketplace-category-name-cell{width:1%;min-width:180px}.marketplace-category-table th:nth-child(3),.marketplace-category-table td:nth-child(3){width:auto;min-width:240px}.marketplace-category-table td.marketplace-category-name-cell>strong{display:inline-block;max-width:100%;white-space:normal;overflow-wrap:anywhere}.marketplace-category-table td.marketplace-category-name-cell.is-subcategory strong{width:calc(100% - 100px);max-width:calc(100% - 100px);white-space:normal;overflow-wrap:anywhere}.marketplace-category-table td.marketplace-category-actions{white-space:nowrap}.marketplace-category-table .marketplace-category-edit-open{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:5px 13px;border:1px solid rgba(208,139,158,.38);border-radius:999px;background:rgba(125,45,67,.16);color:#f0c4cf;font:600 10px Ubuntu,sans-serif;letter-spacing:.02em;line-height:1;text-decoration:none;cursor:pointer;transition:background .18s ease,border-color .18s ease,transform .18s ease;box-sizing:border-box}
.marketplace-category-table .marketplace-category-edit-open:hover{background:rgba(154,55,81,.28);border-color:rgba(226,157,175,.7);color:#f0c4cf;text-decoration:none;transform:translateY(-1px)}
.marketplace-category-table .marketplace-category-edit-open:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}
.marketplace-category-toolbar{display:grid;grid-template-columns:minmax(190px,350px) auto;justify-content:space-between;align-items:center;gap:16px;margin:0 0 12px}.marketplace-category-toolbar label{display:flex;align-items:center;gap:8px;color:#d9cbd0;font-size:11px}.marketplace-category-toolbar select,.marketplace-category-filter input{padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#171317;color:#fff;font:12px Ubuntu,sans-serif}.marketplace-category-toolbar select{width:76px}.marketplace-category-filter{min-width:0}.marketplace-category-filter input{width:100%;min-width:0}.marketplace-category-toolbar .marketplace-category-page-size{justify-self:end}.marketplace-category-toolbar .btn{font-size:11px;padding:8px 12px}.marketplace-category-bulk-toolbar{display:flex;justify-content:flex-end;margin:0 0 10px}.marketplace-category-bulk-toolbar .btn{font-size:11px;padding:8px 12px}.marketplace-category-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0 16px}.marketplace-category-pagination .gallery-page-controls{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.marketplace-category-pagination .gallery-page-controls .btn{padding:7px 11px;font-size:11px}.marketplace-category-pagination .gallery-page-controls .btn:disabled{opacity:.4;cursor:not-allowed}.marketplace-category-pagination .gallery-page-number{min-width:30px;padding:7px 9px!important;text-align:center}.marketplace-category-pagination .gallery-page-number.is-active{background:linear-gradient(135deg,#8f243b,#bd344b);border-color:#d45a70;color:#fff}
.marketplace-category-modal .marketplace-form{grid-template-columns:1fr;margin:16px 0 0;padding:0;border:0;background:none}
.marketplace-category-modal .marketplace-form label{grid-template-columns:130px minmax(0,1fr);align-items:center;gap:12px}.marketplace-category-modal .marketplace-form label textarea{min-height:76px}
.marketplace-category-delete-success-overlay{position:fixed;inset:0;z-index:10060;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(7,3,6,.78);backdrop-filter:blur(5px)}.marketplace-category-delete-success-card{box-sizing:border-box;width:min(100%,575px);min-height:358px;padding:35px 38px 32px;border:1px solid rgba(191,89,112,.42);border-radius:20px;background:#170f14;color:#f5e9ed;box-shadow:0 24px 80px rgba(0,0,0,.6);display:flex;flex-direction:column;align-items:center;text-align:center}.marketplace-category-delete-success-mark{width:66px;height:66px;box-sizing:border-box;border:2px solid #63d49b;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#79e3ad;font:400 48px/1 Ubuntu,sans-serif;margin:0 auto 17px}.marketplace-category-duplicate-mark{border-color:#d94a5e;color:#f06a7b;font-size:50px;line-height:1}.marketplace-category-duplicate-overlay{z-index:10070}.marketplace-category-duplicate-overlay[hidden]{display:none!important}.marketplace-category-duplicate-overlay .marketplace-category-delete-success-card p strong{color:#e34b62;font-weight:800}.marketplace-category-duplicate-overlay .marketplace-category-delete-success-ok{margin-top:30px}
.marketplace-category-delete-success-card h2{margin:0 0 15px;color:#f7e9ed;font:700 20px/1.35 Ubuntu,sans-serif}.marketplace-category-delete-success-card p{margin:0;color:#e9dce1;font:400 15px/1.55 Ubuntu,sans-serif}.marketplace-category-delete-success-list{margin:24px 0 20px;padding-left:20px;color:#e9dce1;text-align:left;font:400 14px/1.8 Ubuntu,sans-serif}.marketplace-category-delete-success-list li{padding-left:2px} .marketplace-category-delete-name{color:#e34b62!important;font-weight:800!important}.marketplace-subcategory-delete-name{color:#f0c84b!important;font-weight:800!important}.marketplace-delete-count{color:#e34b62!important;font-weight:800!important}.marketplace-category-delete-list{list-style:none;padding-left:0}.marketplace-category-delete-list>li{margin:5px 0;list-style:none}.marketplace-category-delete-list ol{list-style:none;margin:4px 0 4px 18px;padding-left:0}.marketplace-category-delete-success-list{list-style:none;padding-left:0}.marketplace-category-delete-success-list>li,.marketplace-category-delete-success-list ol,.marketplace-category-delete-success-list ol>li{list-style:none}.marketplace-category-delete-success-list ol{margin:4px 0 4px 18px;padding-left:0}.marketplace-category-delete-success-ok{margin-top:auto;min-width:75px;height:59px;padding:0 22px;border:0;border-radius:999px;background:#c52f43;color:#120b0e;font:700 14px Ubuntu,sans-serif;cursor:pointer}.marketplace-category-delete-success-ok:hover{background:#dc4055}.marketplace-category-delete-success-ok:focus-visible{outline:2px solid #f4a2ae;outline-offset:3px}@media(max-width:560px){.marketplace-category-delete-success-card{min-height:320px;padding:28px 22px}.marketplace-category-delete-success-card h2{font-size:18px}}.marketplace-category-success-message{margin:12px 0 18px;color:#e9dce1;font-size:13px;line-height:1.6;text-align:center}.marketplace-category-success-message strong{color:#e34b62;font-weight:800}.marketplace-category-modal-actions{display:flex;justify-content:flex-end;gap:8px;grid-column:1/-1}.marketplace-category-modal .account-status-modal-card{max-width:520px;width:min(92vw,520px)}.marketplace-category-modal[hidden]{display:none!important}.marketplace-category-delete-modal{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(5,2,5,.82);backdrop-filter:blur(4px)}.marketplace-category-delete-modal[hidden]{display:none!important}.marketplace-category-delete-card{box-sizing:border-box;width:min(100%,516px);max-height:calc(100vh - 40px);overflow-y:auto;padding:24px 24px 25px;border:1px solid #713044;border-radius:17px;background:#170f14;color:#eadde2;box-shadow:0 20px 55px rgba(0,0,0,.58);font-family:Ubuntu,sans-serif}.marketplace-category-delete-card h3{margin:0 0 20px;color:#f3e9ed;font:700 16px/1.3 Ubuntu,sans-serif}.marketplace-category-delete-intro{margin:0 0 16px;color:#e5d8de;font:400 14px/1.5 Ubuntu,sans-serif}.marketplace-category-delete-warning{margin:0 0 15px;padding:13px 14px;border:1px solid #713044;border-radius:12px;background:#241117;color:#e8cbd3;font:400 14px/1.5 Ubuntu,sans-serif}.marketplace-category-delete-warning strong{font-weight:800}.marketplace-category-delete-selected-label{display:block;margin:0 0 10px;color:#f0e5e9;font:700 14px/1.4 Ubuntu,sans-serif}.marketplace-category-delete-modal .marketplace-category-delete-list{margin:0;padding:0 0 0 22px;color:#d9cbd1;text-align:left;font:400 14px/1.55 Ubuntu,sans-serif;list-style:disc}.marketplace-category-delete-list>li{margin:3px 0;padding-left:1px;list-style:disc}.marketplace-category-delete-list>li>strong{color:#e9dce1;font-weight:800}.marketplace-category-delete-list ol{margin:2px 0 2px 24px;padding:0;list-style:circle}.marketplace-category-delete-list ol>li{padding-left:0;list-style:circle}.marketplace-category-delete-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:25px}.marketplace-category-delete-actions button{display:inline-flex;align-items:center;justify-content:center;min-width:93px;height:52px;padding:0 19px;border:1px solid #393039;border-radius:999px;background:#211a20;color:#f1e9ed;font:700 13px Ubuntu,sans-serif;cursor:pointer;transition:background .18s ease,border-color .18s ease,transform .18s ease}.marketplace-category-delete-actions button:hover{transform:translateY(-1px);background:#30232b;border-color:#66505b}.marketplace-category-delete-actions #marketplace-category-delete-confirm{min-width:130px;border-color:#8d2e48;background:#28131b;color:#efb5c2}.marketplace-category-delete-actions #marketplace-category-delete-confirm:hover{background:#431927;border-color:#b34260}.marketplace-category-delete-actions button:focus-visible{outline:2px solid #e17a8d;outline-offset:3px}@media(max-width:560px){.marketplace-category-delete-card{padding:22px 19px}.marketplace-category-delete-actions button{height:46px}}.marketplace-category-delete-list{margin:7px 0 16px;padding-left:20px;color:#e34b62;font-size:12px;font-weight:800;line-height:1.8}.marketplace-category-pagination button:focus-visible,.marketplace-category-toolbar input:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}@media(max-width:900px){.marketplace-category-toolbar{grid-template-columns:minmax(160px,350px) auto}}@media(max-width:560px){.marketplace-category-toolbar{grid-template-columns:1fr}.marketplace-category-toolbar .marketplace-category-page-size{justify-self:start}.marketplace-category-modal .marketplace-form label{grid-template-columns:1fr;gap:6px}}
@media(max-width:680px){.marketplace-category-toolbar{grid-template-columns:1fr}.marketplace-category-filter{grid-column:1}.marketplace-category-pagination{align-items:flex-start}}


/* Products bulk selection reuses the Registered User Accounts button and modal theme. */
.marketplace-products-bulk-toolbar{display:flex;align-items:center;width:100%}
.marketplace-product-select-cell{width:38px;text-align:center!important}.marketplace-product-select-cell input{width:15px;height:15px;accent-color:#bd344b;cursor:pointer}
.marketplace-products-table tr.is-selected{background:rgba(139,31,48,.09)}
.marketplace-products-delete-confirm-modal button:focus-visible,.marketplace-products-delete-success-modal button:focus-visible,.marketplace-product-select-cell input:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}
/* Products filter and pagination mirror Registered User Accounts controls. */
.marketplace-products-controls{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:0 0 12px}
.marketplace-products-controls .marketplace-products-filter,.marketplace-products-controls .marketplace-products-page-size{display:flex;align-items:center;gap:8px;color:#d9cbd0;font-size:11px}
.marketplace-products-controls .marketplace-products-filter{flex:1;max-width:360px}
.marketplace-products-controls .marketplace-products-filter input{box-sizing:border-box;width:100%;min-width:180px;min-height:36px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#171115;color:#f5e9ed;font:12px Ubuntu,sans-serif}
.marketplace-products-controls .marketplace-products-page-size select{width:76px;padding:7px;border:1px solid var(--line);border-radius:8px;background:#171115;color:#f5e9ed;font:12px Ubuntu,sans-serif}
.marketplace-products-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:12px;color:#bdaeb4;font-size:12px}
.marketplace-products-pagination-top{flex-wrap:wrap;margin:10px 0 18px;padding-top:0}
.marketplace-products-pagination-bottom{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;justify-content:stretch!important;gap:12px;width:100%!important;box-sizing:border-box!important;margin:10px 0 0;text-align:left!important}.marketplace-products-pagination-bottom > #marketplace-products-page-info-bottom{grid-column:1;grid-row:1;justify-self:start!important;align-self:center!important;text-align:left!important;margin:0!important}.marketplace-products-pagination-bottom > .gallery-page-controls{grid-column:2;grid-row:1;justify-self:end!important;margin-left:auto!important;width:max-content!important;max-width:100%!important}
.marketplace-products-pagination .gallery-page-controls{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}
.marketplace-products-pagination .gallery-page-controls .btn{padding:7px 11px;font-size:11px}
.marketplace-products-pagination .gallery-page-controls .btn:disabled{opacity:.4;cursor:not-allowed}
.marketplace-products-pagination .gallery-page-numbers{display:flex;align-items:center;gap:5px}
.marketplace-products-pagination .gallery-page-number{min-width:30px;padding:7px 9px!important;text-align:center}
.marketplace-products-pagination .gallery-page-number.is-active{background:linear-gradient(135deg,#8f243b,#bd344b);border-color:#d45a70;color:#fff;box-shadow:0 0 0 1px rgba(212,90,112,.18)}
.marketplace-products-pagination .gallery-page-number:focus-visible,.marketplace-products-pagination .gallery-page-controls .btn:focus-visible,.marketplace-products-controls input:focus-visible,.marketplace-products-controls select:focus-visible{outline:2px solid #e17a8d;outline-offset:2px}
/* Keep the Products table within the full panel width instead of forcing horizontal overflow. */
.marketplace-products-table{width:100%!important;min-width:0!important;max-width:100%!important;table-layout:fixed!important;box-sizing:border-box!important}
.marketplace-products-table th,.marketplace-products-table td{box-sizing:border-box;min-width:0;white-space:normal;overflow-wrap:anywhere;word-break:normal}
.marketplace-products-table th:nth-child(1),.marketplace-products-table td:nth-child(1){width:4%;padding-left:7px;padding-right:7px}
.marketplace-products-table th:nth-child(2),.marketplace-products-table td:nth-child(2){width:9%}
.marketplace-products-table th:nth-child(3),.marketplace-products-table td:nth-child(3){width:10%}
.marketplace-products-table th:nth-child(4),.marketplace-products-table td:nth-child(4){width:17%}
.marketplace-products-table th:nth-child(5),.marketplace-products-table td:nth-child(5){width:11%}
.marketplace-products-table th:nth-child(6),.marketplace-products-table td:nth-child(6){width:10%}
.marketplace-products-table th:nth-child(7),.marketplace-products-table td:nth-child(7){width:23%}
.marketplace-products-table th:nth-child(8),.marketplace-products-table td:nth-child(8){width:16%}
.marketplace-products-table .marketplace-photo{width:48px;height:48px;max-width:100%}
.marketplace-products-table .marketplace-actions{min-width:0;gap:5px!important}
.marketplace-products-table .marketplace-actions a,.marketplace-products-table .marketplace-actions button{max-width:100%;white-space:normal;overflow-wrap:anywhere}
@media(max-width:900px){.marketplace-products-table th,.marketplace-products-table td{padding:8px 6px;font-size:10px}.marketplace-products-table td strong{font-size:11px}.marketplace-products-table .marketplace-photo{width:40px;height:40px}}
@media(max-width:560px){.marketplace-products-table th,.marketplace-products-table td{padding:7px 4px;font-size:9px}.marketplace-products-table th:nth-child(7),.marketplace-products-table td:nth-child(7){width:20%}.marketplace-products-table th:nth-child(8),.marketplace-products-table td:nth-child(8){width:19%}.marketplace-products-table .marketplace-actions{flex-direction:column;align-items:stretch!important}.marketplace-products-table .marketplace-actions a,.marketplace-products-table .marketplace-actions button{justify-content:center;text-align:center}}
.marketplace-products-table tbody tr[hidden]{display:none!important}
@media(max-width:680px){.marketplace-products-controls{align-items:stretch;flex-direction:column}.marketplace-products-controls .marketplace-products-filter{max-width:none}.marketplace-products-controls .marketplace-products-page-size{align-self:flex-start}.marketplace-products-pagination{align-items:flex-start;flex-direction:column}.marketplace-products-pagination-bottom{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,auto)!important;align-items:center!important;justify-content:stretch!important;width:100%!important}.marketplace-products-pagination-bottom > #marketplace-products-page-info-bottom{grid-column:1;grid-row:1;justify-self:start!important;text-align:left!important}.marketplace-products-pagination-bottom > .gallery-page-controls{grid-column:2;grid-row:1;justify-self:end!important;justify-content:flex-end!important;width:max-content!important;max-width:100%!important;flex-wrap:wrap!important}.marketplace-products-pagination .gallery-page-controls{gap:5px}.marketplace-products-pagination .gallery-page-number{min-width:28px}}
</style>
<nav class="marketplace-tabs" aria-label="Marketplace sections">
<?php foreach (['overview'=>'Overview','products'=>'Products','inventory'=>'Inventory','purchases'=>'Purchases','invoices'=>'Invoices'] as $key=>$label): ?>
<a class="<?=($marketplaceTab===$key || ($marketplaceTab==='category' && $key==='products'))?'active':''?>" href="admin.php?section=marketplace&amp;marketplace_tab=<?=$key?>"><?=$label?></a>
<?php endforeach; ?>
</nav>
<?php if($marketplaceNotice!==''): ?><div class="marketplace-alert success"><?=e($marketplaceNotice)?></div><?php endif; ?>
<?php if($marketplaceDeletedCategoryCount > 0): ?><div class="marketplace-category-delete-success-overlay" id="marketplace-category-delete-success-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-delete-success-title"><div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div><h2 id="marketplace-category-delete-success-title">Delete Category Successful</h2><p><?php if($marketplaceDeletedCategoryCount === 1 && $marketplaceDeletedSubcategoryCount === 0): ?>The category <strong class="marketplace-category-delete-name"><?=e($marketplaceDeletedCategoryGroups[0]['name'])?></strong> has been successfully deleted.<?php elseif($marketplaceDeletedCategoryCount === 1 && $marketplaceDeletedSubcategoryCount === 1): ?>The category <strong class="marketplace-category-delete-name"><?=e($marketplaceDeletedCategoryGroups[0]['name'])?></strong> has been successfully deleted. Its subcategory <strong class="marketplace-subcategory-delete-name"><?=e($marketplaceDeletedCategoryGroups[0]['subcategories'][0]['name'])?></strong> has been also deleted.<?php elseif($marketplaceDeletedCategoryCount === 1): ?>The category <strong class="marketplace-category-delete-name"><?=e($marketplaceDeletedCategoryGroups[0]['name'])?></strong> has been successfully deleted. <strong class="marketplace-delete-count"><?=e($numberToWords($marketplaceDeletedSubcategoryCount))?> (<?=$marketplaceDeletedSubcategoryCount?>)</strong> subcategories have been also deleted.<?php elseif($marketplaceDeletedSubcategoryCount > 0): ?><strong class="marketplace-delete-count"><?=e($numberToWords($marketplaceDeletedCategoryCount))?> (<?=$marketplaceDeletedCategoryCount?>)</strong> categories have been successfully deleted. All subcategories have been also deleted.<?php else: ?><strong class="marketplace-delete-count"><?=e($numberToWords($marketplaceDeletedCategoryCount))?> (<?=$marketplaceDeletedCategoryCount?>)</strong> categories have been deleted successfully.<?php endif; ?></p><?php if($marketplaceDeletedCategoryCount > 1 && $marketplaceDeletedSubcategoryCount > 0): ?><ul class="marketplace-category-delete-success-list"><?php foreach($marketplaceDeletedCategoryGroups as $deletedGroup): ?><li><strong class="marketplace-category-delete-name"><?=e($deletedGroup['name'])?></strong><?php if($deletedGroup['subcategories']): ?><ol><?php foreach($deletedGroup['subcategories'] as $deletedSubcategory): ?><li><strong class="marketplace-subcategory-delete-name"><?=e($deletedSubcategory['name'])?></strong></li><?php endforeach; ?></ol><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-category-delete-success-close">OK</button></section></div><?php endif; ?>
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
 <div class="admin-panel-head"><h2>Category List</h2><div class="marketplace-category-page-actions"><button class="btn" type="button" id="marketplace-category-add-open">+ Add Category</button><button class="btn light" type="button" id="marketplace-category-add-subcategory-page-open">+ Add Subcategory</button><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=products">Back to Products</a></div></div>
 <form id="marketplace-category-bulk-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_delete_categories">
   <div class="marketplace-category-toolbar">
    <label class="marketplace-category-filter"><span>Filter Categories</span><input id="marketplace-category-filter" type="search" placeholder="Search category name or description..." aria-label="Filter Marketplace categories"></label>
    <label class="marketplace-category-page-size">Show <select id="marketplace-category-page-size" aria-label="Categories per page"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label>
   </div>
  <div class="marketplace-category-pagination marketplace-category-pagination-top"><span class="muted" id="marketplace-category-page-info-top" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-category-first-top">First</button><button type="button" class="btn light" id="marketplace-category-prev-top">Previous</button><span id="marketplace-category-page-numbers-top" class="gallery-page-numbers"></span><button type="button" class="btn light" id="marketplace-category-next-top">Next</button><button type="button" class="btn light" id="marketplace-category-last-top">Last</button></div></div>
  <div class="marketplace-category-bulk-toolbar"><button class="btn bulk-delete" type="button" id="marketplace-category-delete-selected" disabled aria-disabled="true">Delete Selected</button></div>
  <div class="table-wrap"><table class="marketplace-table marketplace-category-table"><thead><tr><th class="marketplace-category-select-cell"><input type="checkbox" id="marketplace-category-select-all" aria-label="Select all categories"></th><th>Category Name</th><th>Description</th><th>Date Created</th><th>Actions</th></tr></thead><tbody id="marketplace-category-table-body">
  <?php foreach($marketplaceCategories as $category): $categorySearch=mb_strtolower((string)$category['name'].' '.(string)$category['description']); ?><tr data-marketplace-category-row data-category-search="<?=e($categorySearch)?>"><td class="marketplace-category-select-cell"><input type="checkbox" class="marketplace-category-select" name="selected_categories[]" value="<?=(int)$category['id']?>" aria-label="Select <?=e($category['name'])?>"></td><td class="marketplace-category-name-cell<?=!empty($category['parent_id'])?' is-subcategory':''?>"><?php if(!empty($category['parent_id'])): ?><span class="marketplace-category-tree-arrow" aria-hidden="true"></span><?php endif; ?><strong><?=e($category['name'])?></strong></td><td><?=e((string)$category['description'])?></td><td><?=$date($category['created_at'])?></td><td class="marketplace-category-actions"><a href="#" class="content-manager-action marketplace-category-edit-open" data-category-id="<?=(int)$category['id']?>" data-category-name="<?=e($category['name'])?>" data-category-description="<?=e((string)$category['description'])?>" data-parent-category-id="<?=e((string)($category['parent_id'] ?? ''))?>">Edit</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="marketplace-category-pagination marketplace-category-pagination-bottom"><span class="muted" id="marketplace-category-page-info-bottom" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-category-first-bottom">First</button><button type="button" class="btn light" id="marketplace-category-prev-bottom">Previous</button><span id="marketplace-category-page-numbers-bottom" class="gallery-page-numbers"></span><button type="button" class="btn light" id="marketplace-category-next-bottom">Next</button><button type="button" class="btn light" id="marketplace-category-last-bottom">Last</button></div></div>
  <?php if(!$marketplaceCategories): ?><div class="marketplace-empty" id="marketplace-category-empty">No Marketplace categories have been created. Add at least one category before creating products.</div><?php else: ?><div class="marketplace-empty" id="marketplace-category-empty" hidden>No categories match your search.</div><?php endif; ?>
 </form>
</section>
<div class="account-status-modal marketplace-category-modal" id="marketplace-category-add-modal" hidden><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-add-title"><h2 id="marketplace-category-add-title">Add Category</h2><form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_category"><label>Category Name *<input name="category_name" maxlength="140" required placeholder="e.g. Electronics" value="<?=e($marketplaceCategoryInputName)?>"></label><label>Description<textarea name="category_description" placeholder="Brief category description"><?=e($marketplaceCategoryInputDescription)?></textarea></label><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-category-add-cancel">Cancel</button><button class="btn" type="submit">Save Category</button></div></form></div></div>
<div class="account-status-modal marketplace-category-modal" id="marketplace-subcategory-add-modal" <?= $marketplaceDuplicateSubcategoryName !== null ? '' : 'hidden' ?>><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-subcategory-add-title"><h2 id="marketplace-subcategory-add-title">Add Subcategory</h2><form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_subcategory"><label>Parent Category *<select name="parent_category_id" id="marketplace-subcategory-parent-id" required><option value="">Select a category</option><?php foreach($marketplaceCategories as $parentCategoryOption): if(empty($parentCategoryOption['parent_id'])): ?><option value="<?=(int)$parentCategoryOption['id']?>" <?=((int)$marketplaceSubcategoryInputParentId===(int)$parentCategoryOption['id'])?'selected':''?>><?=e($parentCategoryOption['name'])?></option><?php endif; endforeach; ?></select></label><label>Subcategory Name *<input name="subcategory_name" maxlength="140" required placeholder="Enter subcategory name" value="<?=e($marketplaceSubcategoryInputName)?>"></label><label>Description<textarea name="subcategory_description" placeholder="Brief subcategory description"><?=e($marketplaceSubcategoryInputDescription)?></textarea></label><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-subcategory-add-cancel">Cancel</button><button class="btn" type="submit">Add</button></div></form></div></div>

<div class="account-status-modal marketplace-category-modal" id="marketplace-subcategory-edit-modal" hidden><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-subcategory-edit-title"><h2 id="marketplace-subcategory-edit-title">Edit Subcategory</h2><form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_update_category"><input type="hidden" name="category_id" id="marketplace-subcategory-edit-id"><label>Parent Category *<select name="parent_category_id" id="marketplace-subcategory-edit-parent" required><option value="">Select a category</option><?php foreach($marketplaceCategories as $parentCategoryOption): if(empty($parentCategoryOption['parent_id'])): ?><option value="<?=(int)$parentCategoryOption['id']?>"><?=e($parentCategoryOption['name'])?></option><?php endif; endforeach; ?></select></label><label>Subcategory Name *<input name="category_name" id="marketplace-subcategory-edit-name" maxlength="140" required></label><label>Description<textarea name="category_description" id="marketplace-subcategory-edit-description" placeholder="Brief subcategory description"></textarea></label><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-subcategory-edit-cancel">Cancel</button><button class="btn" type="submit">Update Subcategory</button></div></form></div></div>
<div class="account-status-modal marketplace-category-modal" id="marketplace-category-edit-modal" hidden><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-edit-title"><h2 id="marketplace-category-edit-title">Edit Category</h2><form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=category"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_update_category"><input type="hidden" name="category_id" id="marketplace-category-edit-id"><label>Category Name *<input name="category_name" id="marketplace-category-edit-name" maxlength="140" required></label><label>Description<textarea name="category_description" id="marketplace-category-edit-description" placeholder="Brief category description"></textarea></label><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-category-edit-cancel">Cancel</button><button class="btn" type="submit">Update Category</button></div></form></div></div>
<?php if($marketplaceDuplicateSubcategoryName !== null): ?><div class="marketplace-category-delete-success-overlay marketplace-category-duplicate-overlay" id="marketplace-subcategory-duplicate-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-subcategory-duplicate-title"><div class="marketplace-category-delete-success-mark marketplace-category-duplicate-mark" aria-hidden="true">×</div><h2 id="marketplace-subcategory-duplicate-title">Subcategory Already Exists</h2><p>The subcategory <strong class="marketplace-subcategory-name-highlight"><?=e($marketplaceDuplicateSubcategoryName)?></strong> already exist in the database. Please create new.</p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-subcategory-duplicate-close">OK</button></section></div><?php endif; ?>
<?php if($marketplaceCreatedSubcategoryName !== null): ?><div class="marketplace-category-delete-success-overlay" id="marketplace-subcategory-success-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-subcategory-success-title"><div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div><h2 id="marketplace-subcategory-success-title">Subcategory Added Successfully</h2><p>The subcategory <strong class="marketplace-subcategory-name-highlight"><?=e($marketplaceCreatedSubcategoryName)?></strong> has been successfully added.</p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-subcategory-success-close">OK</button></section></div><?php endif; ?>
<?php if($marketplaceSubcategoryUpdateResult !== null): ?><div class="marketplace-category-delete-success-overlay" id="marketplace-subcategory-update-success-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-subcategory-update-success-title"><div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div><h2 id="marketplace-subcategory-update-success-title">Subcategory Updated Successfully</h2><p><?php if($marketplaceSubcategoryUpdateResult['type'] === 'parent'): ?>The parent category of <strong class="marketplace-category-old-name"><?=e($marketplaceSubcategoryUpdateResult['name'])?></strong> has been moved to <strong class="marketplace-subcategory-new-name"><?=e($marketplaceSubcategoryUpdateResult['parent_name'])?></strong>.<?php elseif($marketplaceSubcategoryUpdateResult['type'] === 'name'): ?>The subcategory <strong class="marketplace-category-old-name"><?=e($marketplaceSubcategoryUpdateResult['old_name'])?></strong> has been renamed to <strong class="marketplace-subcategory-new-name"><?=e($marketplaceSubcategoryUpdateResult['name'])?></strong>.<?php elseif($marketplaceSubcategoryUpdateResult['type'] === 'description'): ?>The description of <strong class="marketplace-category-old-name"><?=e($marketplaceSubcategoryUpdateResult['name'])?></strong> has been updated.<?php else: ?>The subcategory and its details have been successfully updated.<?php endif; ?></p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-subcategory-update-success-close">OK</button></section></div><?php endif; ?><?php if($marketplaceCategoryUpdateResult !== null): ?><div class="marketplace-category-delete-success-overlay" id="marketplace-category-update-success-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-update-success-title"><div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div><h2 id="marketplace-category-update-success-title">Category Updated Successfully</h2><p><?php if($marketplaceCategoryUpdateResult['type'] === 'name'): ?>The category <strong class="marketplace-category-old-name"><?=e($marketplaceCategoryUpdateResult['old_name'])?></strong> has been successfully renamed to <strong class="marketplace-category-new-name"><?=e($marketplaceCategoryUpdateResult['name'])?></strong>.<?php elseif($marketplaceCategoryUpdateResult['type'] === 'description'): ?>The description of <strong class="marketplace-category-old-name"><?=e($marketplaceCategoryUpdateResult['name'])?></strong> has been successfully updated.<?php else: ?>The category <strong class="marketplace-category-old-name"><?=e($marketplaceCategoryUpdateResult['name'])?></strong> and its description have been successfully updated.<?php endif; ?></p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-category-update-success-close">OK</button></section></div><?php endif; ?>
<?php if($marketplaceDuplicateCategoryName !== null): ?><div class="marketplace-category-delete-success-overlay marketplace-category-duplicate-overlay" id="marketplace-category-duplicate-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-duplicate-title"><div class="marketplace-category-delete-success-mark marketplace-category-duplicate-mark" aria-hidden="true">×</div><h2 id="marketplace-category-duplicate-title">Category Already Added</h2><p>The category <strong><?=e($marketplaceDuplicateCategoryName)?></strong> has been already added in the database. Please create a new one.</p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-category-duplicate-close">OK</button></section></div><?php endif; ?>
<?php if($marketplaceCreatedCategoryName !== null): ?><div class="account-status-modal marketplace-category-success-modal" id="marketplace-category-success-modal"><div class="account-status-modal-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-success-title"><h2 id="marketplace-category-success-title">Category Created</h2><p class="marketplace-category-success-message">Category <strong><?=e($marketplaceCreatedCategoryName)?></strong> successfully created.</p><div class="marketplace-category-modal-actions"><button class="btn" type="button" id="marketplace-category-success-close">OK</button></div></div></div><?php endif; ?>
<div class="marketplace-category-delete-modal" id="marketplace-category-delete-modal" hidden role="presentation"><section class="marketplace-category-delete-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-category-delete-title"><h3 id="marketplace-category-delete-title">Delete Selected Items?</h3><p class="marketplace-category-delete-intro">All categories and their subcategories will be permanently deleted.</p><div class="marketplace-category-delete-warning"><strong>Important:</strong> Deleting a category will also delete all subcategories and articles assigned to it. This action cannot be undone.</div><strong class="marketplace-category-delete-selected-label">Selected Items:</strong><ul id="marketplace-category-delete-list" class="marketplace-category-delete-list"></ul><div class="marketplace-category-delete-actions"><button type="button" id="marketplace-category-delete-cancel">Cancel</button><button type="button" id="marketplace-category-delete-confirm">Delete Selected</button></div></section></div>


<script>
(()=>{
 const root=document.querySelector('.marketplace-category-panel');if(!root)return;
 const rows=[...root.querySelectorAll('[data-marketplace-category-row]')],filter=document.getElementById('marketplace-category-filter'),size=document.getElementById('marketplace-category-page-size'),selectAll=document.getElementById('marketplace-category-select-all'),deleteButton=document.getElementById('marketplace-category-delete-selected'),bulkForm=document.getElementById('marketplace-category-bulk-form'),deleteModal=document.getElementById('marketplace-category-delete-modal'),empty=document.getElementById('marketplace-category-empty');
 const addModal=document.getElementById('marketplace-category-add-modal');const duplicateModal=document.getElementById('marketplace-category-duplicate-modal');document.getElementById('marketplace-category-duplicate-close')?.addEventListener('click',()=>{if(duplicateModal){duplicateModal.hidden=true;duplicateModal.setAttribute('aria-hidden','true');}if(addModal){addModal.hidden=false;addModal.removeAttribute('aria-hidden');const categoryNameInput=addModal.querySelector('input[name="category_name"]');if(categoryNameInput){categoryNameInput.focus();categoryNameInput.select();}}});const deleteMessage=document.getElementById('marketplace-category-delete-message');const deleteList=document.getElementById('marketplace-category-delete-list');let page=1;
 const categoryGroups=[];rows.forEach(row=>{if(row.querySelector('.marketplace-category-name-cell.is-subcategory')){if(categoryGroups.length)categoryGroups[categoryGroups.length-1].subcategories.push(row);}else{categoryGroups.push({category:row,subcategories:[]});}});
 const visibleGroups=()=>{const query=(filter?.value||'').trim().toLocaleLowerCase();if(!query)return categoryGroups;return categoryGroups.map(group=>({category:group.category,subcategories:group.subcategories.filter(row=>row.dataset.categorySearch.includes(query)),categoryMatches:group.category.dataset.categorySearch.includes(query)})).filter(group=>group.categoryMatches||group.subcategories.length);};
 const pageButtons=(container,total,current,go)=>{if(!container)return;container.replaceChildren();const maxVisible=7;let first=Math.max(1,current-Math.floor(maxVisible/2)),last=Math.min(total,first+maxVisible-1);first=Math.max(1,last-maxVisible+1);const add=n=>{const b=document.createElement('button');b.type='button';b.className='btn light gallery-page-number'+(n===current?' is-active':'');b.textContent=String(n);b.setAttribute('aria-label','Page '+n);if(n===current)b.setAttribute('aria-current','page');b.addEventListener('click',()=>go(n));container.append(b);};if(first>1){add(1);if(first>2){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}}for(let n=first;n<=last;n++)add(n);if(last<total){if(last<total-1){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}add(total);}};
 const update=()=>{const matched=visibleGroups(),perPage=Math.max(1,Number(size?.value||10)),total=Math.max(1,Math.ceil(matched.length/perPage));page=Math.min(page,total);const from=(page-1)*perPage;rows.forEach(row=>row.hidden=true);matched.forEach((group,index)=>{const onPage=index>=from&&index<from+perPage;if(onPage){group.category.hidden=false;group.subcategories.forEach(row=>{row.hidden=!(group.categoryMatches!==false||row.dataset.categorySearch.includes((filter?.value||'').trim().toLocaleLowerCase()));});}});const start=matched.length?from+1:0,end=Math.min(from+perPage,matched.length),info=matched.length?'Showing '+start+'–'+end+' of '+matched.length+' categories':'Showing 0 to 0 of 0 categories';['top','bottom'].forEach(position=>{const infoElement=document.getElementById('marketplace-category-page-info-'+position);if(infoElement)infoElement.textContent=info;pageButtons(document.getElementById('marketplace-category-page-numbers-'+position),total,page,n=>{page=n;update();});});['first','prev','next','last'].forEach(action=>{['top','bottom'].forEach(position=>{const button=document.getElementById('marketplace-category-'+action+'-'+position);if(button){button.disabled=action==='first'||action==='prev'?page===1:page===total;button.onclick=()=>{page=action==='first'?1:action==='prev'?page-1:action==='next'?page+1:total;update();};}});});if(empty){empty.hidden=matched.length>0;}syncSelection();};
 const checkboxes=()=>[...root.querySelectorAll('.marketplace-category-select')];
 const syncSelection=()=>{const boxes=checkboxes(),checked=boxes.filter(box=>box.checked);if(deleteButton){deleteButton.disabled=checked.length===0;deleteButton.setAttribute('aria-disabled',String(checked.length===0));}if(selectAll){const selected=boxes.filter(box=>box.checked).length;selectAll.checked=boxes.length>0&&selected===boxes.length;selectAll.indeterminate=selected>0&&selected<boxes.length;}};
 filter?.addEventListener('input',()=>{page=1;update();});size?.addEventListener('change',()=>{page=1;update();});checkboxes().forEach(box=>box.addEventListener('change',()=>{const row=box.closest('tr');const isSubcategory=row?.querySelector('.marketplace-category-name-cell.is-subcategory');const group=categoryGroups.find(item=>item.category===row||item.subcategories.includes(row));if(isSubcategory&&box.checked&&group){const parentBox=group.category.querySelector('.marketplace-category-select');if(parentBox)parentBox.checked=true;group.subcategories.forEach(child=>{const childBox=child.querySelector('.marketplace-category-select');if(childBox)childBox.checked=true;});}else if(!isSubcategory){group?.subcategories.forEach(child=>{const childBox=child.querySelector('.marketplace-category-select');if(childBox)childBox.checked=box.checked;});}syncSelection();}));selectAll?.addEventListener('change',()=>{checkboxes().forEach(box=>{box.checked=selectAll.checked;});syncSelection();});
 const subcategoryModal=document.getElementById('marketplace-subcategory-add-modal');const subcategoryDuplicateModal=document.getElementById('marketplace-subcategory-duplicate-modal');document.getElementById('marketplace-subcategory-duplicate-close')?.addEventListener('click',()=>{if(subcategoryDuplicateModal)subcategoryDuplicateModal.hidden=true;if(subcategoryModal){subcategoryModal.hidden=false;subcategoryModal.querySelector('input[name="subcategory_name"]')?.focus();subcategoryModal.querySelector('input[name="subcategory_name"]')?.select();}});document.getElementById('marketplace-category-add-subcategory-page-open')?.addEventListener('click',()=>{if(subcategoryModal){subcategoryModal.hidden=false;subcategoryModal.querySelector('select[name="parent_category_id"]')?.focus();}});document.getElementById('marketplace-subcategory-add-cancel')?.addEventListener('click',()=>{if(subcategoryModal){subcategoryModal.querySelector('form')?.reset();subcategoryModal.hidden=true;}});document.getElementById('marketplace-subcategory-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=category';});
 const editModal=document.getElementById('marketplace-category-edit-modal');const editId=document.getElementById('marketplace-category-edit-id');const editName=document.getElementById('marketplace-category-edit-name');const editDescription=document.getElementById('marketplace-category-edit-description');const subEditModal=document.getElementById('marketplace-subcategory-edit-modal');const subEditId=document.getElementById('marketplace-subcategory-edit-id');const subEditParent=document.getElementById('marketplace-subcategory-edit-parent');const subEditName=document.getElementById('marketplace-subcategory-edit-name');const subEditDescription=document.getElementById('marketplace-subcategory-edit-description');root.querySelectorAll('.marketplace-category-edit-open').forEach(button=>button.addEventListener('click',()=>{const isSubcategory=button.closest('tr')?.querySelector('.marketplace-category-name-cell')?.classList.contains('is-subcategory');if(isSubcategory){if(!subEditModal)return;subEditId.value=button.dataset.categoryId||'';subEditParent.value=button.dataset.parentCategoryId||'';subEditName.value=button.dataset.categoryName||'';subEditDescription.value=button.dataset.categoryDescription||'';subEditModal.hidden=false;subEditName.focus();return;}if(!editModal)return;editId.value=button.dataset.categoryId||'';editName.value=button.dataset.categoryName||'';editDescription.value=button.dataset.categoryDescription||'';editModal.hidden=false;editName.focus();}));document.getElementById('marketplace-category-edit-cancel')?.addEventListener('click',()=>{if(editModal)editModal.hidden=true;});document.getElementById('marketplace-subcategory-edit-cancel')?.addEventListener('click',()=>{if(subEditModal)subEditModal.hidden=true;});document.getElementById('marketplace-category-update-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=category';});document.getElementById('marketplace-subcategory-update-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=category';});
 document.getElementById('marketplace-category-add-open')?.addEventListener('click',()=>{addModal.hidden=false;});document.getElementById('marketplace-category-add-cancel')?.addEventListener('click',()=>{addModal.hidden=true;});const categorySuccessModal=document.getElementById('marketplace-category-success-modal');document.getElementById('marketplace-category-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=category';});
 deleteButton?.addEventListener('click',()=>{const selectedBoxes=checkboxes().filter(box=>box.checked);if(!selectedBoxes.length)return;const selectedRows=selectedBoxes.map(box=>box.closest('tr')).filter(Boolean);const selectedRoots=selectedRows.filter(row=>!row.querySelector('.marketplace-category-name-cell.is-subcategory'));const selectedSubs=selectedRows.filter(row=>row.querySelector('.marketplace-category-name-cell.is-subcategory'));const groups=selectedRoots.map(row=>({row,children:categoryGroups.find(group=>group.category===row)?.subcategories||[]}));const hasSubcategories=groups.some(group=>group.children.length>0);const single=selectedRoots.length===1&&selectedSubs.length===0;const nameClass='marketplace-category-delete-name';const subClass='marketplace-subcategory-delete-name';if(deleteList){deleteList.replaceChildren();const showList=true;deleteList.hidden=false;if(showList){const letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';groups.forEach((group,index)=>{const li=document.createElement('li');const label=document.createElement('strong');label.className=nameClass;const prefix=hasSubcategories||groups.length===1?letters[index]+'. ':' '+(index+1)+'. ';label.textContent=group.row.querySelector('.marketplace-category-name-cell strong')?.textContent||'Category';li.append(label);if(group.children.length){const nested=document.createElement('ol');group.children.forEach((child,childIndex)=>{const childLi=document.createElement('li');const childName=document.createElement('strong');childName.className=subClass;childName.textContent=child.querySelector('.marketplace-category-name-cell strong')?.textContent||'Subcategory';childLi.append(childName);nested.append(childLi);});li.append(nested);}deleteList.append(li);});if(selectedRoots.length===0){selectedRows.forEach((row,index)=>{const li=document.createElement('li');const name=document.createElement('strong');name.className=nameClass;name.textContent='Category: '+(row.querySelector('.marketplace-category-name-cell strong')?.textContent||'Category');li.append(name);deleteList.append(li);});}}}const title=document.getElementById('marketplace-category-delete-title');if(title)title.textContent='Delete Category Confirmation';deleteModal.hidden=false;document.getElementById('marketplace-category-delete-cancel')?.focus();});document.getElementById('marketplace-category-delete-cancel')?.addEventListener('click',()=>{deleteModal.hidden=true;});document.getElementById('marketplace-category-delete-confirm')?.addEventListener('click',()=>{if(checkboxes().some(box=>box.checked))bulkForm.requestSubmit();});const deleteSuccessModal=document.getElementById('marketplace-category-delete-success-modal');document.getElementById('marketplace-category-delete-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=category';});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&deleteModal&&!deleteModal.hidden)deleteModal.hidden=true;if(event.key==='Escape'&&addModal&&!addModal.hidden)addModal.hidden=true;if(event.key==='Escape'&&subcategoryModal&&!subcategoryModal.hidden)subcategoryModal.hidden=true;if(event.key==='Escape'&&deleteSuccessModal&&!deleteSuccessModal.hidden)deleteSuccessModal.hidden=true;});
 update();
})();
</script>

<?php elseif($marketplaceTab==='products'): ?>
<section class="panel admin-panel">
 <div class="admin-panel-head"><h2>Products</h2></div>
 <div class="marketplace-toolbar"><span class="muted">Manage product details and photos.</span><div class="marketplace-product-page-actions"><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=category">View Category</a><button class="btn" type="button" id="marketplace-product-add-open">+ Add Product</button></div></div>
 <?php if(!$marketplaceProducts): ?><div class="marketplace-empty">No products have been added yet.</div><?php else: ?><form id="marketplace-bulk-products" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=products"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_delete_products">
 <div class="marketplace-products-controls"><label class="marketplace-products-filter">Filter products<input type="search" id="marketplace-products-filter" placeholder="Search product details..." aria-label="Search products"></label><label class="marketplace-products-page-size">Show<select id="marketplace-products-page-size" aria-label="Products per page"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select> entries</label></div><div class="marketplace-products-pagination marketplace-products-pagination-top"><span class="muted" id="marketplace-products-page-info-top" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-products-first-top">First</button><button type="button" class="btn light" id="marketplace-products-prev-top">Previous</button><span id="marketplace-products-page-numbers-top" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="marketplace-products-next-top">Next</button><button type="button" class="btn light" id="marketplace-products-last-top">Last</button></div><div class="admin-panel-head marketplace-products-bulk-toolbar" style="margin:0 0 14px;justify-content:flex-end"><button class="btn bulk-delete" type="button" id="marketplace-products-delete-selected" disabled aria-disabled="true">Delete Selected</button></div><div class="table-wrap"><table class="marketplace-table marketplace-products-table"><thead><tr><th class="marketplace-product-select-cell"><input type="checkbox" id="marketplace-products-select-all" aria-label="Select all products"></th><th>Product Photo</th><th>Product ID</th><th>Product Name</th><th>Brand</th><th>Model</th><th>Description</th><th>Actions</th></tr></thead><tbody id="marketplace-products-tbody">
 <?php foreach($marketplaceProducts as $product): ?><tr data-product-id="<?=(int)$product['id']?>" data-product-name="<?=e($product['product_name'])?>" data-product-code="<?=e((string)($product['product_code']??''))?>" data-product-has-inventory="<?=!empty($product['inventory_id'])?'1':'0'?>"><td class="marketplace-product-select-cell"><input type="checkbox" class="marketplace-product-select" name="selected_products[]" value="<?=(int)$product['id']?>" aria-label="Select <?=e($product['product_name'])?>"></td><td><?php if($product['photo_path']): $productPhotoVersion = !empty($product['updated_at']) ? strtotime((string)$product['updated_at']) : time(); ?><img class="marketplace-photo" src="<?=e((string)$product['photo_path'])?>?v=<?=$productPhotoVersion?>" alt="<?=e($product['product_name'])?>"><?php else: ?><div class="marketplace-photo"></div><?php endif; ?></td><td><strong><?=e((string)($product['product_code']??''))?></strong></td><td><strong><?=e($product['product_name'])?></strong><?php if(!empty($product['category_name'])): ?><small><?=e($product['category_name'])?><?php if(!empty($product['subcategory_name'])): ?> / <?=e($product['subcategory_name'])?><?php endif; ?></small><?php endif; ?></td><td><?=e((string)$product['brand'])?></td><td><?=e((string)$product['model'])?></td><td><?=e((string)$product['description'])?></td><td><div class="marketplace-actions"><a href="#" class="content-manager-action marketplace-product-edit-open" data-product-id="<?=(int)$product['id']?>" data-product-name="<?=e($product['product_name'])?>" data-product-brand="<?=e((string)$product['brand'])?>" data-product-model="<?=e((string)$product['model'])?>" data-product-manufacturer="<?=e((string)($product['manufacturer']??''))?>" data-product-supply-entity="<?=e((string)($product['supply_chain_entity']??''))?>" data-product-country="<?=e((string)($product['country_of_origin']??''))?>" data-product-description="<?=e((string)$product['description'])?>" data-product-photo="<?=e((string)($product['photo_path']??''))?>" data-product-category-id="<?=(int)($product['category_id']??0)?>" data-product-subcategory-id="<?=(int)($product['subcategory_id']??0)?>">Edit</a></div></td></tr><?php endforeach; ?>
 </tbody></table></div><div class="marketplace-products-pagination marketplace-products-pagination-bottom"><span class="muted" id="marketplace-products-page-info-bottom" aria-live="polite"></span><div class="gallery-page-controls"><button type="button" class="btn light" id="marketplace-products-first-bottom">First</button><button type="button" class="btn light" id="marketplace-products-prev-bottom">Previous</button><span id="marketplace-products-page-numbers-bottom" class="gallery-page-numbers" aria-label="Page numbers"></span><button type="button" class="btn light" id="marketplace-products-next-bottom">Next</button><button type="button" class="btn light" id="marketplace-products-last-bottom">Last</button></div></div></form><?php endif; ?>
</section>
<div class="account-status-modal users-delete-confirm-modal marketplace-product-delete-confirm-overlay" id="marketplace-products-delete-confirm-modal" hidden><div class="account-status-modal-card marketplace-product-delete-confirm-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-products-delete-confirm-title"><div class="account-status-modal-icon" aria-hidden="true">!</div><h2 id="marketplace-products-delete-confirm-title">Delete Confirmation Notice</h2><div id="marketplace-products-delete-confirm-copy"><p>Are you sure you want to delete the selected product?</p></div><div id="marketplace-products-delete-confirm-list" class="marketplace-product-delete-list"></div><div class="users-delete-modal-actions"><button type="button" class="btn light" id="marketplace-products-delete-cancel">Cancel</button><button type="button" class="btn bulk-delete" id="marketplace-products-delete-confirm">Delete</button></div></div></div><?php if($marketplaceDeletedProductCount>0): ?><div class="account-status-modal users-delete-success-modal marketplace-product-delete-success-overlay" id="marketplace-products-delete-success-modal" role="dialog" aria-modal="true" aria-labelledby="marketplace-products-delete-success-title"><div class="account-status-modal-card marketplace-product-delete-success-card"><div class="account-status-modal-icon" aria-hidden="true">✓</div><h2 id="marketplace-products-delete-success-title">Delete Product Successful</h2><p><?php if($marketplaceDeletedProductCount===1): $deletedProduct=$marketplaceDeletedProductDetails[0]; ?>The product <strong class="marketplace-delete-name"><?=e((string)$deletedProduct['product_name'])?></strong> with product ID <strong class="marketplace-delete-code"><?=e((string)($deletedProduct['product_code']??''))?></strong> has been successfully deleted<?php else: ?><strong class="marketplace-delete-count"><?=e($numberToWords($marketplaceDeletedProductCount))?> (<?=number_format($marketplaceDeletedProductCount)?>)</strong> selected products have been successfully deleted<?php endif; ?>.</p><button type="button" class="btn" id="marketplace-products-delete-success-close">OK</button></div></div><?php endif; ?><div class="account-status-modal marketplace-category-modal" id="marketplace-product-edit-modal" hidden><div class="account-status-modal-card marketplace-product-add-card marketplace-product-edit-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-product-edit-title"><h2 id="marketplace-product-edit-title">Edit Product</h2><form class="marketplace-form marketplace-product-edit-form" id="marketplace-product-edit-form" method="post" enctype="multipart/form-data" action="admin.php?section=marketplace&amp;marketplace_tab=products"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_product"><input type="hidden" name="product_id" id="marketplace-product-edit-id"><div class="marketplace-product-category-group"><div class="marketplace-product-category-label">Category *</div><div class="marketplace-product-category-selects"><select name="category_id" id="marketplace-product-edit-category" class="marketplace-product-category" aria-label="Select Category" required><option value="">Select Category</option><?php foreach($marketplaceCategories as $category): if(empty($category['parent_id'])): ?><option value="<?=(int)$category['id']?>"><?=e($category['name'])?></option><?php endif; endforeach; ?></select><select name="subcategory_id" id="marketplace-product-edit-subcategory" class="marketplace-product-subcategory" aria-label="Select Subcategory" disabled><option value="">Select Subcategory</option><?php foreach($marketplaceCategories as $subcategory): if(!empty($subcategory['parent_id'])): ?><option value="<?=(int)$subcategory['id']?>" data-parent-id="<?=(int)$subcategory['parent_id']?>"><?=e($subcategory['name'])?></option><?php endif; endforeach; ?></select></div></div><label class="marketplace-edit-row"><span>Product Name *</span><input name="product_name" id="marketplace-product-edit-name" required maxlength="220"></label><label class="marketplace-edit-row"><span>Product ID</span><input id="marketplace-product-edit-code" readonly aria-readonly="true" style="background:#29252a;color:#999;cursor:not-allowed"></label><label class="marketplace-edit-row"><span>Brand *</span><input name="brand" id="marketplace-product-edit-brand" required maxlength="140"></label><label class="marketplace-edit-row"><span>Model *</span><input name="model" id="marketplace-product-edit-model" required maxlength="140"></label><label class="marketplace-edit-row marketplace-edit-description-row"><span>Description</span><textarea name="description" id="marketplace-product-edit-description"></textarea></label><div class="marketplace-product-edit-photo-layout"><div class="marketplace-product-edit-photo-field"><div class="marketplace-edit-upload-row"><span class="marketplace-edit-row-label">Upload Photo</span><div class="marketplace-edit-upload-control"><input type="file" name="product_photo" id="marketplace-product-edit-photo-input" accept=".jpg,.jpeg,.webp,.png,.avif,image/jpeg,image/png,image/webp,image/avif"><small>Upload Photo (JPG, JPEG, WEBP, PNG, AVIF · under 5 MB)</small><small id="marketplace-product-edit-current-photo"></small></div></div><div class="marketplace-product-edit-photo-previews"><div class="marketplace-product-edit-photo-current"><span class="marketplace-field-label">Current Photo Preview</span><div class="marketplace-product-edit-photo-preview-wrap"><img id="marketplace-product-edit-current-preview" class="marketplace-product-edit-photo-preview" alt="Current product photo" hidden><span id="marketplace-product-edit-current-empty">No current photo.</span></div></div><div class="marketplace-product-edit-photo-current"><span class="marketplace-field-label">New Photo Preview</span><div class="marketplace-product-edit-photo-preview-wrap"><img id="marketplace-product-edit-photo-preview" class="marketplace-product-edit-photo-preview" alt="New photo preview" hidden><span id="marketplace-product-edit-photo-empty">No new photo selected.</span></div></div></div></div></div><div class="wide marketplace-category-modal-actions"><button class="btn light" type="button" id="marketplace-product-edit-cancel">Cancel</button><button class="btn" type="submit">Update Product</button></div></form></div></div>
<?php if($marketplaceProductEditSuccess !== null): ?><div class="marketplace-category-delete-success-overlay" id="marketplace-product-edit-success-modal"><section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-product-edit-success-title"><div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div><h2 id="marketplace-product-edit-success-title">Edit Product Successful</h2><p><?php if($marketplaceProductEditSuccess['type']==='category'): ?>The category <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['old_category'])?></strong> has been successfully changed to <strong style="color:#e7c94b;font-weight:900"><?=e($marketplaceProductEditSuccess['new_category'])?></strong>. The subcategory has been reselected to <strong style="color:#22a565;font-weight:900"><?=e($marketplaceProductEditSuccess['subcategory'])?></strong>.<?php elseif($marketplaceProductEditSuccess['type']==='name'): ?>The product name <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['old_name'])?></strong> has been successfully changed to <strong style="color:#e7c94b;font-weight:900"><?=e($marketplaceProductEditSuccess['new_name'])?></strong>.<?php elseif($marketplaceProductEditSuccess['type']==='model'): ?>The model <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['old_model'])?></strong> of <strong style="color:#22a565;font-weight:900"><?=e($marketplaceProductEditSuccess['product_name'])?></strong> has been successfully changed to <strong style="color:#e7c94b;font-weight:900"><?=e($marketplaceProductEditSuccess['new_model'])?></strong>.<?php elseif($marketplaceProductEditSuccess['type']==='description'): ?>The description of <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['product_name'])?></strong> has been successfully updated.<?php elseif($marketplaceProductEditSuccess['type']==='photo'): ?>The photo of <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['product_name'])?></strong> has been successfully updated.<?php else: ?>The product <strong style="color:#e34b62;font-weight:900"><?=e($marketplaceProductEditSuccess['product_name'])?></strong> and its details have been successfully updated.<?php endif; ?></p><button class="marketplace-category-delete-success-ok" type="button" id="marketplace-product-edit-success-close">OK</button></section></div><?php endif; ?>
<div class="account-status-modal marketplace-category-modal" id="marketplace-product-add-modal" hidden>
 <div class="account-status-modal-card marketplace-product-add-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-product-add-title">
  <h2 id="marketplace-product-add-title">Add Product</h2>
  <form id="marketplace-product-add-form" method="post" enctype="multipart/form-data" action="admin.php?section=marketplace&amp;marketplace_tab=products">
   <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_product">
   <div id="marketplace-product-entry-rows">
    <div class="marketplace-product-entry-row">
     <div class="wide marketplace-product-category-group"><span class="marketplace-field-label">Category *</span><div class="marketplace-product-category-selects"><select name="category_id[]" class="marketplace-product-category" aria-label="Select Category" required><option value="">Select Category</option><?php foreach($marketplaceCategories as $category): if(empty($category['parent_id'])): ?><option value="<?=(int)$category['id']?>" <?=((int)($marketplaceProductInputRows[0]['category_id']??0)===(int)$category['id'])?'selected':''?>><?=e($category['name'])?></option><?php endif; endforeach; ?></select><select name="subcategory_id[]" class="marketplace-product-subcategory" aria-label="Select Subcategory" disabled><option value="">Select Subcategory</option><?php foreach($marketplaceCategories as $subcategory): if(!empty($subcategory['parent_id'])): ?><option value="<?=(int)$subcategory['id']?>" data-parent-id="<?=(int)$subcategory['parent_id']?>" <?=((int)($marketplaceProductInputRows[0]['subcategory_id']??0)===(int)$subcategory['id'])?'selected':''?>><?=e($subcategory['name'])?></option><?php endif; endforeach; ?></select></div></div>
      <label>Product Name *<input name="product_name[]" maxlength="220" required value="<?=e((string)($marketplaceProductInputRows[0]['product_name']??''))?>"></label>
     
     <label>Brand *<input name="brand[]" maxlength="140" required value="<?=e((string)($marketplaceProductInputRows[0]['brand']??''))?>"></label>
     <label>Model<input name="model[]" maxlength="140" value="<?=e((string)($marketplaceProductInputRows[0]['model']??''))?>"></label>
     <label class="wide">Description<textarea name="description[]"><?=e((string)($marketplaceProductInputRows[0]['description']??''))?></textarea></label><label>Upload Photo (JPG, JPEG, WEBP, PNG, AVIF · under 5 MB)<input type="file" name="product_photo[]" accept=".jpg,.jpeg,.webp,.png,.avif,image/jpeg,image/png,image/webp,image/avif"></label>
     <div class="marketplace-product-row-actions"><button type="button" class="btn light marketplace-product-remove-row">Remove</button></div>
    </div>
   </div>
   <div class="marketplace-product-modal-footer"><button type="button" class="btn light" id="marketplace-product-add-row">Add Row</button><div><button type="button" class="btn light" id="marketplace-product-add-cancel">Cancel</button> <button type="submit" class="btn">Add</button></div></div>
  </form>
 </div>
</div>
<?php if($marketplaceDuplicateProduct): ?>
<div class="marketplace-category-delete-success-overlay" id="marketplace-product-duplicate-modal">
 <section class="marketplace-category-delete-success-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-product-duplicate-title">
  <h2 id="marketplace-product-duplicate-title">Duplicate Product</h2>
  <p>The product you are about to add already exist in the database. Please create a new one.</p>
  <button class="marketplace-category-delete-success-ok" type="button" id="marketplace-product-duplicate-close">OK</button>
 </section>
</div>
<?php endif; ?>
<?php if($marketplaceCreatedProducts !== null): ?>
<div class="marketplace-category-delete-success-overlay" id="marketplace-product-created-modal">
 <section class="marketplace-category-delete-success-card marketplace-product-created-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-product-created-title">
  <div class="marketplace-category-delete-success-mark" aria-hidden="true">✓</div>
  <h2 id="marketplace-product-created-title">Products Created Successfully</h2>
  <?php if(count($marketplaceCreatedProducts)===1): $created=$marketplaceCreatedProducts[0]; ?>
   <p>The product <strong class="marketplace-product-name-highlight"><?=e($created['name'])?></strong> with product ID <strong class="marketplace-product-id-highlight"><?=e($created['code'])?></strong> has been successfully created.</p>
  <?php else: ?>
   <p>The following products have been successfully created.</p>
   <ul class="marketplace-product-created-list"><?php foreach($marketplaceCreatedProducts as $created): ?><li>Product ID: <strong class="marketplace-product-id-highlight"><?=e($created['code'])?></strong> — <strong class="marketplace-product-name-highlight"><?=e($created['name'])?></strong></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <button class="marketplace-category-delete-success-ok" type="button" id="marketplace-product-created-close">OK</button>
 </section>
</div>
<?php endif; ?>
<script>
(()=>{
 const modal=document.getElementById('marketplace-product-add-modal');
 const form=document.getElementById('marketplace-product-add-form');
 const duplicateModal=document.getElementById('marketplace-product-duplicate-modal');
 if(duplicateModal&&modal){modal.hidden=true;document.getElementById('marketplace-product-duplicate-close')?.addEventListener('click',()=>{duplicateModal.hidden=true;modal.hidden=false;form?.querySelector('input[name="product_name[]"]')?.focus();});}
 const rows=document.getElementById('marketplace-product-entry-rows');
 const add=document.getElementById('marketplace-product-add-row');
 const initialRowMarkup=rows?.querySelector('.marketplace-product-entry-row')?.outerHTML||'';
 const bindSubcategory=container=>{
  const category=container.querySelector('.marketplace-product-category'),subcategory=container.querySelector('.marketplace-product-subcategory');
  if(!category||!subcategory)return;
  const sync=()=>{const parentId=category.value;let available=0;Array.from(subcategory.options).forEach(option=>{if(!option.value){option.hidden=false;return;}const matches=option.dataset.parentId===parentId;option.hidden=!matches;if(matches)available++;});if(!parentId||available===0){subcategory.value='';subcategory.disabled=true;subcategory.options[0].textContent=parentId?'No subcategories available':'Select category first';}else{subcategory.disabled=false;subcategory.options[0].textContent='Select subcategory';if(subcategory.selectedOptions[0]?.dataset.parentId!==parentId)subcategory.value='';}};
  category.addEventListener('change',sync);sync();
 };
 const bindRemove=container=>{
  container.querySelector('.marketplace-product-remove-row')?.addEventListener('click',()=>{if(rows.children.length>1)container.remove();else{container.querySelectorAll('input,textarea').forEach(field=>{field.value='';});container.querySelectorAll('select').forEach(field=>field.selectedIndex=0);bindSubcategory(container);}});
  bindSubcategory(container);
 };
 const resetForm=()=>{
  if(!rows)return;
  form?.reset();
  rows.innerHTML=initialRowMarkup;
  rows.querySelectorAll('.marketplace-product-entry-row').forEach(bindRemove);
 };
 const close=()=>{resetForm();if(modal)modal.hidden=true;};
 document.getElementById('marketplace-product-add-open')?.addEventListener('click',()=>{resetForm();if(modal)modal.hidden=false;});
 document.getElementById('marketplace-product-add-cancel')?.addEventListener('click',close);
 rows?.querySelectorAll('.marketplace-product-entry-row').forEach(bindRemove);
 add?.addEventListener('click',()=>{const clone=rows.querySelector('.marketplace-product-entry-row').cloneNode(true);clone.querySelectorAll('input,textarea').forEach(field=>{field.value='';});clone.querySelectorAll('select').forEach(field=>{field.selectedIndex=0;});rows.append(clone);bindRemove(clone);clone.querySelector('input')?.focus();});
 document.getElementById('marketplace-product-created-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=products';});document.getElementById('marketplace-product-edit-success-close')?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=products';});
 const productEditModal=document.getElementById('marketplace-product-edit-modal');const productEditFields={id:document.getElementById('marketplace-product-edit-id'),name:document.getElementById('marketplace-product-edit-name'),code:document.getElementById('marketplace-product-edit-code'),brand:document.getElementById('marketplace-product-edit-brand'),model:document.getElementById('marketplace-product-edit-model'),description:document.getElementById('marketplace-product-edit-description'),photo:document.getElementById('marketplace-product-edit-current-photo'),photoInput:document.getElementById('marketplace-product-edit-photo-input'),photoPreview:document.getElementById('marketplace-product-edit-photo-preview'),photoEmpty:document.getElementById('marketplace-product-edit-photo-empty'),currentPreview:document.getElementById('marketplace-product-edit-current-preview'),currentEmpty:document.getElementById('marketplace-product-edit-current-empty'),category:document.getElementById('marketplace-product-edit-category'),subcategory:document.getElementById('marketplace-product-edit-subcategory')};document.querySelectorAll('.marketplace-product-edit-open').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();const d=link.dataset;productEditFields.id.value=d.productId||'';productEditFields.name.value=d.productName||'';productEditFields.code.value=link.closest('tr')?.querySelector('td:nth-child(3) strong')?.textContent?.trim()||'';productEditFields.brand.value=d.productBrand||'';productEditFields.model.value=d.productModel||'';productEditFields.description.value=d.productDescription||'';productEditFields.photo.textContent=d.productPhoto?'Current photo: '+d.productPhoto.split('/').pop():'No photo currently uploaded.';productEditFields.photoInput.value='';if(d.productPhoto){productEditFields.currentPreview.src=d.productPhoto;productEditFields.currentPreview.hidden=false;productEditFields.currentEmpty.hidden=true;}else{productEditFields.currentPreview.removeAttribute('src');productEditFields.currentPreview.hidden=true;productEditFields.currentEmpty.hidden=false;}productEditFields.photoPreview.removeAttribute('src');productEditFields.photoPreview.hidden=true;productEditFields.photoEmpty.hidden=false;productEditFields.category.value=link.dataset.productCategoryId||'';productEditFields.subcategory.value=link.dataset.productSubcategoryId||'';const syncEditSubcategory=()=>{const parentId=productEditFields.category.value;let count=0;Array.from(productEditFields.subcategory.options).forEach(option=>{if(!option.value){option.hidden=false;return;}const match=option.dataset.parentId===parentId;option.hidden=!match;if(match)count++;});productEditFields.subcategory.disabled=!parentId||count===0;if(!parentId||count===0){productEditFields.subcategory.value='';productEditFields.subcategory.options[0].textContent=parentId?'No subcategories available':'Select category first';}else{productEditFields.subcategory.options[0].textContent='Select subcategory';if(productEditFields.subcategory.selectedOptions[0]?.dataset.parentId!==parentId)productEditFields.subcategory.value='';}};syncEditSubcategory();productEditFields.category.onchange=syncEditSubcategory;productEditModal.hidden=false;productEditFields.name.focus();}));document.getElementById('marketplace-product-edit-cancel')?.addEventListener('click',()=>{if(productEditModal)productEditModal.hidden=true;});productEditFields.photoInput?.addEventListener('change',()=>{const file=productEditFields.photoInput.files?.[0];if(!file)return;const previewUrl=URL.createObjectURL(file);productEditFields.photoPreview.src=previewUrl;productEditFields.photoPreview.hidden=false;productEditFields.photoEmpty.hidden=true;});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&productEditModal&&!productEditModal.hidden)productEditModal.hidden=true;});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal&&!modal.hidden)close();});
 // Products filter and pagination: same client-side behavior as Registered User Accounts.
 const productBulkForm=document.getElementById('marketplace-bulk-products');
 const productSelectAll=document.getElementById('marketplace-products-select-all');
 const productDeleteButton=document.getElementById('marketplace-products-delete-selected');
 const productDeleteModal=document.getElementById('marketplace-products-delete-confirm-modal');
 const productDeleteCopy=document.getElementById('marketplace-products-delete-confirm-copy');
 const productDeleteList=document.getElementById('marketplace-products-delete-confirm-list');
 const productDeleteConfirm=document.getElementById('marketplace-products-delete-confirm');
 const productDeleteCancel=document.getElementById('marketplace-products-delete-cancel');
 const productDeleteSuccess=document.getElementById('marketplace-products-delete-success-modal');
 const productDeleteSuccessClose=document.getElementById('marketplace-products-delete-success-close');
 let productDeleteConfirmed=false;
 const productCheckboxes=()=>Array.from(document.querySelectorAll('#marketplace-products-tbody .marketplace-product-select'));
 const selectedProductCount=()=>productCheckboxes().filter(box=>box.checked).length;
 const syncProductSelection=()=>{const boxes=productCheckboxes(),selected=selectedProductCount();if(productDeleteButton){const disabled=selected===0;productDeleteButton.disabled=disabled;productDeleteButton.toggleAttribute('disabled',disabled);productDeleteButton.setAttribute('aria-disabled',String(disabled));}if(productSelectAll){const filtered=matchingProducts().map(row=>row.querySelector('.marketplace-product-select')).filter(Boolean),checked=filtered.filter(box=>box.checked).length;productSelectAll.checked=filtered.length>0&&checked===filtered.length;productSelectAll.indeterminate=checked>0&&checked<filtered.length;productSelectAll.disabled=filtered.length===0;}boxes.forEach(box=>box.closest('tr')?.classList.toggle('is-selected',box.checked));};
 const closeProductDeleteModal=()=>{if(productDeleteModal)productDeleteModal.hidden=true;};
 const escapeProductText=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 const prepareProductDeleteConfirmation=()=>{const selected=productCheckboxes().filter(box=>box.checked).map(box=>box.closest('tr')).filter(Boolean);if(!selected.length)return;const count=selected.length,hasInventory=selected.some(row=>row.dataset.productHasInventory==='1');if(productDeleteCopy)productDeleteCopy.innerHTML='<p>Are you sure you want to delete the selected product'+(count===1?'?':'s?')+(hasInventory?(count===1?' This product has inventory records assigned to it. Deleting this will also delete all inventory, purchases, invoices, and other transactions assigned to it. Click Delete to continue.':' The selected products have inventory records assigned to it. Deleting these will also delete all inventory, purchases, invoices, and other transactions assigned to it. Click Delete to continue.'):'')+'</p>';if(productDeleteList){productDeleteList.classList.toggle('is-single-product',count===1);productDeleteList.classList.toggle('is-multiple-products',count>1);productDeleteList.innerHTML=count===1?'<div class="marketplace-product-delete-detail"><span>Product Name:</span> <strong class="marketplace-delete-name">'+escapeProductText(selected[0].dataset.productName)+'</strong></div><div class="marketplace-product-delete-detail"><span>Product ID:</span> <strong class="marketplace-delete-code">'+escapeProductText(selected[0].dataset.productCode)+'</strong></div>':'<div class="marketplace-product-delete-table-wrap"><table class="marketplace-product-delete-table"><thead><tr><th scope="col" aria-label="Row number"></th><th scope="col">Product ID</th><th scope="col">Product Name</th></tr></thead><tbody>'+selected.map((row,index)=>'<tr><td class="marketplace-delete-row-number">'+(index+1)+'.</td><td class="marketplace-delete-product-id-cell"><strong class="marketplace-delete-code">'+escapeProductText(row.dataset.productCode)+'</strong></td><td class="marketplace-delete-product-name-cell"><strong class="marketplace-delete-name">'+escapeProductText(row.dataset.productName)+'</strong></td></tr>').join('')+'</tbody></table></div>';productDeleteList.hidden=false;}if(productDeleteModal)productDeleteModal.hidden=false;};
 productDeleteButton?.addEventListener('click',event=>{event.preventDefault();prepareProductDeleteConfirmation();});
 productDeleteCancel?.addEventListener('click',closeProductDeleteModal);
 productDeleteConfirm?.addEventListener('click',()=>{if(selectedProductCount()===0){closeProductDeleteModal();syncProductSelection();return;}productDeleteConfirmed=true;closeProductDeleteModal();productBulkForm?.requestSubmit();});
 productBulkForm?.addEventListener('submit',event=>{if(!productDeleteConfirmed){event.preventDefault();prepareProductDeleteConfirmation();return;}productDeleteConfirmed=false;});
 productDeleteSuccessClose?.addEventListener('click',()=>{window.location.href='admin.php?section=marketplace&marketplace_tab=products';});if(productDeleteSuccess)productDeleteSuccess.hidden=false;
 productSelectAll?.addEventListener('change',()=>{matchingProducts().forEach(row=>{const box=row.querySelector('.marketplace-product-select');if(box)box.checked=productSelectAll.checked;});syncProductSelection();});
 document.addEventListener('change',event=>{if(event.target instanceof HTMLInputElement&&event.target.matches('#marketplace-products-tbody .marketplace-product-select, #marketplace-products-select-all'))syncProductSelection();});
 const productTable=document.querySelector('.marketplace-products-table');
 const productRows=productTable?Array.from(productTable.querySelectorAll('tbody tr')):[];
 const productFilter=document.getElementById('marketplace-products-filter');
 const productPageSize=document.getElementById('marketplace-products-page-size');
 const productPageInfoTop=document.getElementById('marketplace-products-page-info-top');
 const productPageInfoBottom=document.getElementById('marketplace-products-page-info-bottom');
 const productPageNumbersTop=document.getElementById('marketplace-products-page-numbers-top');
 const productPageNumbersBottom=document.getElementById('marketplace-products-page-numbers-bottom');
 const productFirst=[document.getElementById('marketplace-products-first-top'),document.getElementById('marketplace-products-first-bottom')].filter(Boolean);
 const productPrevious=[document.getElementById('marketplace-products-prev-top'),document.getElementById('marketplace-products-prev-bottom')].filter(Boolean);
 const productNext=[document.getElementById('marketplace-products-next-top'),document.getElementById('marketplace-products-next-bottom')].filter(Boolean);
 const productLast=[document.getElementById('marketplace-products-last-top'),document.getElementById('marketplace-products-last-bottom')].filter(Boolean);
 let productPage=1;
 const matchingProducts=()=>{const query=(productFilter?.value||'').trim().toLocaleLowerCase();return productRows.filter(row=>row.textContent.toLocaleLowerCase().includes(query));};
 const renderProductPageNumbers=container=>{
  if(!container)return;
  container.replaceChildren();
  const found=matchingProducts(),perPage=Math.max(1,Number(productPageSize?.value)||10),pages=Math.max(1,Math.ceil(found.length/perPage)),maxVisible=7;
  let first=Math.max(1,productPage-Math.floor(maxVisible/2)),last=Math.min(pages,first+maxVisible-1);first=Math.max(1,last-maxVisible+1);
  const addNumber=number=>{const button=document.createElement('button');button.type='button';button.className='btn light gallery-page-number'+(number===productPage?' is-active':'');button.textContent=String(number);button.setAttribute('aria-label','Page '+number);if(number===productPage)button.setAttribute('aria-current','page');button.addEventListener('click',()=>{productPage=number;renderProducts();});container.append(button);};
  if(first>1){addNumber(1);if(first>2){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}}
  for(let number=first;number<=last;number++)addNumber(number);
  if(last<pages){if(last<pages-1){const dots=document.createElement('span');dots.className='muted';dots.textContent='…';container.append(dots);}addNumber(pages);}
 };
 const renderProducts=()=>{
  if(!productTable)return;
  const found=matchingProducts(),perPage=Math.max(1,Number(productPageSize?.value)||10),pages=Math.max(1,Math.ceil(found.length/perPage));
  productPage=Math.min(productPage,pages);
  const start=found.length?(productPage-1)*perPage:0,end=Math.min(start+perPage,found.length),visible=new Set(found.slice(start,end));
  productRows.forEach(row=>{row.hidden=!visible.has(row);});
  const summary=found.length?'Showing '+(start+1)+'–'+end+' of '+found.length+' entries':'Showing 0–0 of 0 entries';
  if(productPageInfoTop)productPageInfoTop.textContent=summary;if(productPageInfoBottom)productPageInfoBottom.textContent=summary;
  renderProductPageNumbers(productPageNumbersTop);renderProductPageNumbers(productPageNumbersBottom);
  syncProductSelection();
  productFirst.forEach(button=>button.disabled=productPage<=1);productPrevious.forEach(button=>button.disabled=productPage<=1);
  productNext.forEach(button=>button.disabled=productPage>=pages);productLast.forEach(button=>button.disabled=productPage>=pages);
 };
 productFilter?.addEventListener('input',()=>{productPage=1;renderProducts();});
 productPageSize?.addEventListener('change',()=>{productPage=1;renderProducts();});
 productFirst.forEach(button=>button.addEventListener('click',()=>{productPage=1;renderProducts();}));
 productPrevious.forEach(button=>button.addEventListener('click',()=>{productPage=Math.max(1,productPage-1);renderProducts();}));
 productNext.forEach(button=>button.addEventListener('click',()=>{productPage=Math.min(Math.max(1,Math.ceil(matchingProducts().length/Math.max(1,Number(productPageSize?.value)||10))),productPage+1);renderProducts();}));
 productLast.forEach(button=>button.addEventListener('click',()=>{productPage=Math.max(1,Math.ceil(matchingProducts().length/Math.max(1,Number(productPageSize?.value)||10)));renderProducts();}));
 renderProducts();
})();
</script>

<?php elseif($marketplaceTab==='inventory'): ?>
<section class="panel admin-panel">
 <div class="admin-panel-head"><h2>Inventory</h2></div>
 <div class="marketplace-toolbar"><span class="muted">Inventory records, stock quantities, pricing and status.</span></div>
 <?php if(!$marketplaceInventory): ?><div class="marketplace-empty">No inventory records have been created yet. Add an inventory record from the Products page.</div><?php else: ?>
 <div class="table-wrap"><table class="marketplace-table marketplace-inventory-table"><thead><tr><th>Product Photo</th><th>Product Name</th><th>Batch Number</th><th>Manufacturer's Name</th><th>Supply Chain Type</th><th>Quantity in Stock</th><th>All Taxes</th><th>Retail Price</th><th>Selling Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>
 <?php foreach($marketplaceInventory as $item): ?>
 <tr>
  <td><?php if($item['photo_path']): ?><img class="marketplace-photo" src="<?=e((string)$item['photo_path'])?>" alt="<?=e($item['product_name'])?>"><?php else: ?><div class="marketplace-photo"></div><?php endif; ?></td>
  <td class="marketplace-inventory-product"><strong><?=e($item['product_name'])?></strong><small>Product ID: <?=e((string)($item['product_code']??''))?></small><small>Category: <?=e((string)($item['category_name']??''))?></small><small>Subcategory: <?=e((string)($item['subcategory_name']??''))?></small><small>Brand: <?=e((string)($item['brand']??''))?></small><small>Model: <?=e((string)($item['model']??''))?></small><small>Description: <?=e((string)($item['description']??''))?></small></td>
  <td><?=e((string)($item['batch_number']??$item['sku']??'—'))?></td>
  <td><?=e((string)($item['manufacturer']??'—'))?></td>
  <td><?=e((string)($item['supply_chain_type']??$item['supply_chain_entity']??'—'))?></td>
  <td><?=isset($item['stock_on_hand'])?$quantityFormat($item['stock_on_hand']):'—'?></td>
  <td><?=isset($item['tax_rate'])?$taxFormat($item['tax_rate']):'—'?></td>
  <td><?=isset($item['retail_price'])?$money($item['retail_price']):'—'?></td>
  <td><?=isset($item['selling_price'])?$money($item['selling_price']):((isset($item['unit_price']))?$money($item['unit_price']):'—')?></td>
  <td><span class="marketplace-inventory-status"><?=e((string)($item['status']??'Not Set'))?></span></td>
  <td><div class="marketplace-actions marketplace-inventory-actions">
   <?php if(empty($item['id'])): ?><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;add_inventory_product=<?=(int)$item['linked_product_id']?>">Add</a><?php else: ?><form method="post" action="admin.php?section=marketplace&amp;marketplace_tab=inventory" class="marketplace-add-stock-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_add_stock"><input type="hidden" name="inventory_id" value="<?=(int)$item['id']?>"><input type="text" inputmode="decimal" class="marketplace-formatted-number" data-decimals="2" name="load_quantity" min="0" required placeholder="Qty" aria-label="Quantity to add"><button class="btn light" type="submit">Add</button></form><?php endif; ?>
   <?php if(!empty($item['id'])): ?><a class="btn" href="admin.php?section=marketplace&amp;marketplace_tab=inventory&amp;edit_inventory=<?=(int)$item['id']?>">Edit</a><?php endif; ?>
  </div></td>
 </tr><?php endforeach; ?>
 </tbody></table></div><?php endif; ?>
</section>
<?php if($marketplaceAddInventoryProduct): ?>
<div class="account-status-modal marketplace-category-modal" id="marketplace-inventory-add-modal">
 <div class="account-status-modal-card marketplace-product-add-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-inventory-add-title">
  <h2 id="marketplace-inventory-add-title">Add Inventory</h2>
  <form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=inventory">
   <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_inventory"><input type="hidden" name="inventory_id" value="0">
   <label>Product *<select name="inventory_product_id" required><option value="<?=(int)$marketplaceAddInventoryProduct['id']?>" selected><?=e($marketplaceAddInventoryProduct['product_name'])?></option></select></label>
   <label>Batch Number *<input name="batch_number" required maxlength="100" value=""></label>
   <label>Manufacturer's Name<input name="manufacturer" maxlength="180" value="<?=e((string)($marketplaceAddInventoryProduct['manufacturer']??''))?>"></label>
   <label>Supply Chain Type *<select name="supply_chain_type" required><option value="">Select type</option><?php foreach($marketplaceSupplyEntities as $entity): ?><option value="<?=e($entity)?>" <?=((string)($marketplaceAddInventoryProduct['supply_chain_entity']??'')===$entity)?'selected':''?>><?=e($entity)?></option><?php endforeach; ?></select></label>
   <label>Quantity in Stock<input type="text" inputmode="decimal" class="marketplace-formatted-number" data-decimals="2" name="stock_on_hand" min="0" required value=""></label>
   <label>Value-Added Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="vat_rate" min="0" value=""></label>
   <label>Percentage Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="percentage_tax_rate" min="0" value=""></label>
   <label>Income Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="income_tax_rate" min="0" value=""></label>
   <label>Withholding Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="withholding_tax_rate" min="0" value=""></label>
   <label>Other Taxes (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="other_tax_rate" min="0" value=""></label>
   <label>Retail Price (₱) — Before Taxes<input class="marketplace-retail-price marketplace-formatted-number" type="text" inputmode="decimal" data-decimals="2" name="retail_price" min="0" value=""></label>
   <label>Selling Price (₱)<input class="marketplace-selling-price" type="text" inputmode="decimal" name="selling_price" value="0.00" readonly aria-readonly="true" style="background:#29252a!important;color:#999!important;cursor:not-allowed"></label>
   <label>Status<select name="inventory_status" required><?php foreach($marketplaceInventoryStatuses as $statusOption): ?><option value="<?=e($statusOption)?>" <?=($statusOption==='Low Stock')?'selected':''?>><?=e($statusOption)?></option><?php endforeach; ?></select></label>
   <input type="hidden" name="sold" value="0">
   <div class="wide marketplace-category-modal-actions"><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory">Cancel</a><button class="btn" type="submit">Save Changes</button></div>
  </form>
 </div>
</div>
<?php endif; ?>
<?php if($marketplaceEditInventoryId > 0 && $marketplaceInventoryForm): ?>
<div class="account-status-modal marketplace-category-modal" id="marketplace-inventory-edit-modal">
 <div class="account-status-modal-card marketplace-product-add-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-inventory-edit-title">
  <h2 id="marketplace-inventory-edit-title">Edit Inventory</h2>
  <form class="marketplace-form" method="post" action="admin.php?section=marketplace&amp;marketplace_tab=inventory">
   <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="marketplace_save_inventory"><input type="hidden" name="inventory_id" value="<?=(int)$marketplaceInventoryForm['id']?>">
   <label>Product *<select name="inventory_product_id" required><?php foreach($marketplaceProducts as $product): ?><option value="<?=(int)$product['id']?>" <?=((int)$marketplaceInventoryForm['product_id']===(int)$product['id'])?'selected':''?>><?=e($product['product_name'])?></option><?php endforeach; ?></select></label>
   <label>Batch Number *<input name="batch_number" required maxlength="100" value="<?=e((string)($marketplaceInventoryForm['batch_number']??$marketplaceInventoryForm['sku']))?>"></label>
   <label>Manufacturer's Name<input name="manufacturer" maxlength="180" value="<?=e((string)($marketplaceInventoryForm['manufacturer']??''))?>"></label>
   <label>Supply Chain Type *<select name="supply_chain_type" required><option value="">Select type</option><?php foreach($marketplaceSupplyEntities as $entity): ?><option value="<?=e($entity)?>" <?=((string)($marketplaceInventoryForm['supply_chain_type']??$marketplaceInventoryForm['supply_chain_entity']??'')===$entity)?'selected':''?>><?=e($entity)?></option><?php endforeach; ?></select></label>
   <label>Quantity in Stock<input type="text" inputmode="decimal" class="marketplace-formatted-number" data-decimals="2" name="stock_on_hand" min="0" required value="<?=((float)$marketplaceInventoryForm['stock_on_hand']>0)?e($quantityFormat($marketplaceInventoryForm['stock_on_hand'])):''?>"></label>
   <label>Value-Added Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="vat_rate" min="0" value="<?=((float)($marketplaceInventoryForm['vat_rate']??0)>0)?e($taxFormat($marketplaceInventoryForm['vat_rate'])):''?>"></label>
   <label>Percentage Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="percentage_tax_rate" min="0" value="<?=((float)($marketplaceInventoryForm['percentage_tax_rate']??0)>0)?e($taxFormat($marketplaceInventoryForm['percentage_tax_rate'])):''?>"></label>
   <label>Income Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="income_tax_rate" min="0" value="<?=((float)($marketplaceInventoryForm['income_tax_rate']??0)>0)?e($taxFormat($marketplaceInventoryForm['income_tax_rate'])):''?>"></label>
   <label>Withholding Tax (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="withholding_tax_rate" min="0" value="<?=((float)($marketplaceInventoryForm['withholding_tax_rate']??0)>0)?e($taxFormat($marketplaceInventoryForm['withholding_tax_rate'])):''?>"></label>
   <label>Other Taxes (%)<input type="text" inputmode="decimal" class="marketplace-tax-formatted" data-decimals="2" name="other_tax_rate" min="0" value="<?=((float)($marketplaceInventoryForm['other_tax_rate']??0)>0)?e($taxFormat($marketplaceInventoryForm['other_tax_rate'])):''?>"></label>
   <label>Retail Price (₱) — Before Taxes<input class="marketplace-retail-price marketplace-formatted-number" type="text" inputmode="decimal" data-decimals="2" name="retail_price" min="0" value="<?=((float)$marketplaceInventoryForm['retail_price']>0)?e(number_format((float)$marketplaceInventoryForm['retail_price'], 2, '.', ',')):''?>"></label>
   <label>Selling Price (₱)<input class="marketplace-selling-price" type="text" inputmode="decimal" name="selling_price" value="<?=e(number_format((float)($marketplaceInventoryForm['selling_price']??$marketplaceInventoryForm['unit_price']), 2, '.', ','))?>" readonly aria-readonly="true" style="background:#29252a!important;color:#999!important;cursor:not-allowed"></label>
   <label>Status<select name="inventory_status" required><?php foreach($marketplaceInventoryStatuses as $statusOption): ?><option value="<?=e($statusOption)?>" <?=((string)($marketplaceInventoryForm['status']??'Low Stock')===$statusOption)?'selected':''?>><?=e($statusOption)?></option><?php endforeach; ?></select></label>
   <input type="hidden" name="sold" value="<?=e((string)$marketplaceInventoryForm['sold'])?>">
   <div class="wide marketplace-category-modal-actions"><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory">Cancel</a><button class="btn" type="submit">Save Changes</button></div>
  </form>
 </div>
</div>
<?php endif; ?>
<script>
(function(){
 const taxFields=['vat_rate','percentage_tax_rate','income_tax_rate','withholding_tax_rate','other_tax_rate'];
 document.querySelectorAll('#marketplace-inventory-add-modal form,#marketplace-inventory-edit-modal form').forEach(function(form){
  const retail=form.querySelector('[name="retail_price"]');
  const selling=form.querySelector('[name="selling_price"]');
  if(!retail||!selling)return;
  function recalculate(){
   const totalRate=taxFields.reduce(function(sum,name){
    const field=form.querySelector('[name="'+name+'"]');
    const value=field?Number(String(field.value).replace(/,/g,'')):0;
    return sum+(Number.isFinite(value)&&value>0?value:0);
   },0);
   const base=Math.max(0,Number(String(retail.value).replace(/,/g,''))||0);
   selling.value=(Math.round((base*(1+totalRate)+Number.EPSILON)*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  }
  taxFields.forEach(function(name){const field=form.querySelector('[name="'+name+'"]');if(field)field.addEventListener('input',recalculate);});
  retail.addEventListener('input',recalculate);
  recalculate();
 });
 document.querySelectorAll('.marketplace-formatted-number,.marketplace-tax-formatted').forEach(function(field){
  const decimals=Number(field.dataset.decimals||2);
  const isTax=field.classList.contains('marketplace-tax-formatted');
  const clean=value=>{
   let raw=String(value).replace(/,/g,'').replace(/[^0-9.]/g,'');
   const dot=raw.indexOf('.');
   if(dot!==-1)raw=raw.slice(0,dot+1)+raw.slice(dot+1).replace(/\./g,'');
   if(dot!==-1)raw=raw.slice(0,dot+1)+raw.slice(dot+1,dot+1+decimals);
   return raw;
  };
  const format=value=>{
   const raw=clean(value);
   if(raw==='')return '';
   const parts=raw.split('.');
   const integer=(parts[0]||'0').replace(/\B(?=(\d{3})+(?!\d))/g,',');
   return integer+(parts.length>1?'.'+parts[1].padEnd(decimals,'0').slice(0,decimals):'.'+'0'.repeat(decimals));
  };
  const formatWhileTyping=()=>{
   const start=field.selectionStart??field.value.length;
   const before=clean(field.value.slice(0,start));
   const raw=clean(field.value);
   if(raw===''){field.value='';return;}
   const parts=raw.split('.');
   const integer=(parts[0]||'0');
   const grouped=isTax?integer:integer.replace(/\B(?=(\d{3})+(?!\d))/g,',');
   const next=grouped+(parts.length>1?'.'+parts[1]:'');
   field.value=next;
   let caret=0,seen=0;
   while(caret<next.length&&seen<before.length){if(/[0-9.]/.test(next[caret]))seen++;caret++;}
   field.setSelectionRange(caret,caret);
  };
  field.addEventListener('input',formatWhileTyping);
  field.addEventListener('focus',()=>{field.value=String(field.value).replace(/,/g,'');});
  field.addEventListener('blur',()=>{field.value=format(field.value);});
  field.form?.addEventListener('submit',()=>{field.value=String(field.value).replace(/,/g,'');});
  field.value=format(field.value);
 });
})();
</script>
<?php if($marketplaceInventoryReadOnly && $marketplaceInventoryForm): ?><div class="account-status-modal marketplace-category-modal" id="marketplace-inventory-view-modal"><div class="account-status-modal-card marketplace-product-add-card" role="dialog" aria-modal="true" aria-labelledby="marketplace-inventory-view-title"><h2 id="marketplace-inventory-view-title">Inventory Details</h2><p><strong>SKU:</strong> <?=e($marketplaceInventoryForm['sku'])?></p><p>Stock on Hand: <?=$quantityFormat($marketplaceInventoryForm['stock_on_hand'])?> · Sold: <?=$quantityFormat($marketplaceInventoryForm['sold'])?></p><p>Stock Level: <?=$quantityFormat(max(0,(float)$marketplaceInventoryForm['stock_on_hand']-(float)$marketplaceInventoryForm['sold']))?></p><p>Value-Added Tax: <?=$taxFormat($marketplaceInventoryForm['vat_rate'])?></p><p>Percentage Tax: <?=$taxFormat($marketplaceInventoryForm['percentage_tax_rate'])?></p><p>Income Tax: <?=$taxFormat($marketplaceInventoryForm['income_tax_rate'])?></p><p>Withholding Tax: <?=$taxFormat($marketplaceInventoryForm['withholding_tax_rate'])?></p><p>Other Taxes: <?=$taxFormat($marketplaceInventoryForm['other_tax_rate'])?></p><p>All Taxes: <?=$taxFormat($marketplaceInventoryForm['tax_rate'])?></p><p>Unit Price: <?=$money($marketplaceInventoryForm['unit_price'])?></p><p>Retail Price: <?=$money($marketplaceInventoryForm['retail_price'])?></p><p>Selling Price: <?=$money($marketplaceInventoryForm['selling_price'])?></p><p>Manufacturer: <?=e((string)($marketplaceInventoryForm['manufacturer']??''))?></p><p>Supply Chain Entity: <?=e((string)($marketplaceInventoryForm['supply_chain_entity']??''))?></p><p>Country of Origin: <?=e((string)($marketplaceInventoryForm['country_of_origin']??''))?></p><a class="btn light" href="admin.php?section=marketplace&amp;marketplace_tab=inventory">Close</a></div></div><?php endif; ?>
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
