<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: admin.php?session_context=admin&section=page-management&tab=contents#pm-panel-contents');
    exit;
}
verify_csrf();

$action = (string)($_POST['action'] ?? '');
$notice = 'saved';
$successNames = [];\n$bulkDetailsQuery = '';
$slugify = static function(string $value): string {
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    return substr($slug !== '' ? $slug : 'content', 0, 120) . '-' . bin2hex(random_bytes(3));
};

try {
    if ($action === 'save_category') {
        $id = (int)($_POST['category_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('category_invalid');
        if ($id > 0) {
            $check = $pdo->prepare("SELECT slug FROM categories WHERE id=? AND parent_id IS NULL");
            $check->execute([$id]);
            $existing = $check->fetch();
            if (!$existing || $existing['slug'] === 'uncategorized') throw new RuntimeException('category_locked');
            $save = $pdo->prepare('UPDATE categories SET name=?, description=? WHERE id=? AND parent_id IS NULL');
            $save->execute([$name, mb_substr($description, 0, 255), $id]);
            $successNames[] = $name;
            $notice = 'category_updated';
        } else {
            $save = $pdo->prepare('INSERT INTO categories (name,slug,description,is_active,parent_id) VALUES (?,?,?,1,NULL)');
            $save->execute([$name, $slugify($name), mb_substr($description, 0, 255)]);
            $notice = 'category_created';
            $successNames[] = $name;
        }
    } elseif ($action === 'save_subcategory') {
        $id = (int)($_POST['subcategory_id'] ?? 0);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || $parentId < 1) throw new RuntimeException('subcategory_invalid');
        $parent = $pdo->prepare("SELECT id FROM categories WHERE id=? AND parent_id IS NULL AND slug<>'uncategorized' AND is_active=1");
        $parent->execute([$parentId]);
        if (!$parent->fetch()) throw new RuntimeException('category_invalid');
        if ($id > 0) {
            $exists = $pdo->prepare('SELECT id FROM categories WHERE id=? AND parent_id IS NOT NULL');
            $exists->execute([$id]);
            if (!$exists->fetch()) throw new RuntimeException('subcategory_missing');
            $save = $pdo->prepare('UPDATE categories SET name=?,description=?,parent_id=? WHERE id=? AND parent_id IS NOT NULL');
            $save->execute([$name, mb_substr($description, 0, 255), $parentId, $id]);
            $successNames[] = $name;
            $notice = 'subcategory_updated';
        } else {
            $save = $pdo->prepare('INSERT INTO categories (name,slug,description,is_active,parent_id) VALUES (?,?,?,1,?)');
            $save->execute([$name, $slugify($name), mb_substr($description, 0, 255), $parentId]);
            $successNames[] = $name;
            $notice = 'subcategory_created';
        }
    } elseif ($action === 'delete_category' || $action === 'delete_subcategory') {
        $id = (int)($_POST['item_id'] ?? 0);
        $isSubcategory = $action === 'delete_subcategory';
        $find = $pdo->prepare('SELECT id,name,slug,parent_id FROM categories WHERE id=?');
        $find->execute([$id]);
        $category = $find->fetch();
        if (!$category || ($isSubcategory ? $category['parent_id'] === null : $category['parent_id'] !== null) || $category['slug'] === 'uncategorized') {
            throw new RuntimeException('category_locked');
        }
        $children = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id=?');
        $children->execute([$id]);
        $usage = $pdo->prepare($isSubcategory ? 'SELECT COUNT(*) FROM content WHERE subcategory_id=?' : 'SELECT COUNT(*) FROM content WHERE category_id=?');
        $usage->execute([$id]);
        if ((int)$children->fetchColumn() > 0 || (int)$usage->fetchColumn() > 0) throw new RuntimeException('category_in_use');
        $successNames[] = (string)$category['name'];
        $delete = $pdo->prepare('DELETE FROM categories WHERE id=?');
        $delete->execute([$id]);
        $notice = $isSubcategory ? 'subcategory_deleted' : 'category_deleted';
    } elseif ($action === 'bulk_delete_categories') {
        $selected = $_POST['selected_items'] ?? [];
        if (!is_array($selected)) $selected = [];
        $items = [];
        foreach ($selected as $entry) {
            if (!is_string($entry) || !preg_match('/^(category|subcategory):(\\d+)$/', $entry, $match)) continue;
            $items[$match[1] . ':' . (int)$match[2]] = [$match[1], (int)$match[2]];
        }
        if (!$items) throw new RuntimeException('nothing_selected');

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id,name,slug,parent_id FROM categories WHERE id=?');
            $findChildren = $pdo->prepare('SELECT id,name FROM categories WHERE parent_id=? ORDER BY name');
            $deleteArticlesByCategory = $pdo->prepare("DELETE FROM content WHERE category_id=? AND content_type='article'");
            $deleteArticlesBySubcategory = $pdo->prepare("DELETE FROM content WHERE subcategory_id=? AND content_type='article'");
            $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id=?');
            $deletedGroups = [];

            $selectedCategoryIds = [];
            foreach ($items as [$kind, $id]) {
                if ($kind === 'category') $selectedCategoryIds[$id] = true;
            }

            // A selected parent always includes every child, whether or not its
            // subcategory checkboxes were individually selected.
            foreach ($items as [$kind, $id]) {
                if ($kind !== 'category') continue;
                $find->execute([$id]);
                $category = $find->fetch();
                if (!$category || $category['parent_id'] !== null || $category['slug'] === 'uncategorized') {
                    throw new RuntimeException('category_locked');
                }
                $findChildren->execute([$id]);
                $children = $findChildren->fetchAll(PDO::FETCH_ASSOC);
                $childNames = [];
                $deleteArticlesByCategory->execute([$id]);
                foreach ($children as $child) {
                    $childId = (int)$child['id'];
                    $childNames[] = (string)$child['name'];
                    $deleteArticlesBySubcategory->execute([$childId]);
                    $deleteCategory->execute([$childId]);
                }
                $deleteCategory->execute([$id]);
                $deletedGroups[(string)$category['name']] = $childNames;
                $successNames[] = (string)$category['name'];
                foreach ($childNames as $childName) $successNames[] = $childName;
            }

            // A subcategory selected without its parent is deleted on its own.
            foreach ($items as [$kind, $id]) {
                if ($kind !== 'subcategory') continue;
                $find->execute([$id]);
                $subcategory = $find->fetch();
                if (!$subcategory || $subcategory['parent_id'] === null || $subcategory['slug'] === 'uncategorized') {
                    throw new RuntimeException('category_locked');
                }
                if (isset($selectedCategoryIds[(int)$subcategory['parent_id']])) continue;
                $find->execute([(int)$subcategory['parent_id']]);
                $parent = $find->fetch();
                $parentName = $parent ? (string)$parent['name'] : 'Other subcategories';
                $deleteArticlesBySubcategory->execute([$id]);
                $deleteCategory->execute([$id]);
                $deletedGroups[$parentName] ??= [];
                $deletedGroups[$parentName][] = (string)$subcategory['name'];
                $successNames[] = (string)$subcategory['name'];
            }

            $pdo->commit();
            $notice = 'categories_bulk_deleted';
            $bulkCategoryCount = count($selectedCategoryIds);
            $bulkType = filter_var($_POST['select_all_categories'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'all' : ($bulkCategoryCount === 1 ? 'single' : ($bulkCategoryCount > 1 ? 'multiple' : 'subcategories'));
            $bulkDetailsQuery = '&bulk_type=' . rawurlencode($bulkType) . '&bulk_details=' . rawurlencode((string)json_encode($deletedGroups, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'save_article') {
        $id = (int)($_POST['article_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $excerpt = trim((string)($_POST['excerpt'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $subcategoryId = (int)($_POST['subcategory_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'draft');
        if ($title === '' || mb_strlen($title) > 220 || $categoryId < 1 || !in_array($status, ['draft','published'], true)) throw new RuntimeException('article_invalid');
        $root = $pdo->prepare('SELECT id FROM categories WHERE id=? AND parent_id IS NULL AND is_active=1');
        $root->execute([$categoryId]);
        if (!$root->fetch()) throw new RuntimeException('category_invalid');
        $subcategoryValue = null;
        if ($subcategoryId > 0) {
            $child = $pdo->prepare('SELECT id FROM categories WHERE id=? AND parent_id=? AND is_active=1');
            $child->execute([$subcategoryId, $categoryId]);
            if (!$child->fetch()) throw new RuntimeException('subcategory_invalid');
            $subcategoryValue = $subcategoryId;
        }
        $slug = $slugify($title);
        if ($id > 0) {
            $exists = $pdo->prepare("SELECT id FROM content WHERE id=? AND content_type='article'");
            $exists->execute([$id]);
            if (!$exists->fetch()) throw new RuntimeException('article_missing');
            $save = $pdo->prepare("UPDATE content SET category_id=?,subcategory_id=?,title=?,slug=?,excerpt=?,body=?,status=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END WHERE id=? AND content_type='article'");
            $save->execute([$categoryId,$subcategoryValue,$title,$slug,mb_substr($excerpt,0,500),$body,$status,$status,$id]);
            $notice = 'article_updated';
        } else {
            $save = $pdo->prepare("INSERT INTO content (owner_id,category_id,subcategory_id,content_type,title,slug,excerpt,body,status,published_at) VALUES (?,?,?,'article',?,?,?,?,?,IF(?='published',NOW(),NULL))");
            $save->execute([(int)$admin['id'],$categoryId,$subcategoryValue,$title,$slug,mb_substr($excerpt,0,500),$body,$status,$status]);
            $notice = 'article_created';
        }
    } elseif ($action === 'delete_article') {
        $id = (int)($_POST['item_id'] ?? 0);
        $delete = $pdo->prepare("DELETE FROM content WHERE id=? AND content_type='article'");
        $delete->execute([$id]);
        if (!$delete->rowCount()) throw new RuntimeException('article_missing');
        $notice = 'article_deleted';
    } else {
        throw new RuntimeException('unknown_action');
    }
} catch (PDOException $e) {
    error_log('Content manager database error: ' . $e->getMessage());
    $notice = 'duplicate_or_database_error';
} catch (RuntimeException $e) {
    $notice = $e->getMessage();
}

$returnToCategoryList = in_array($action, ['save_category','save_subcategory','delete_category','delete_subcategory','bulk_delete_categories'], true);
$redirect = $returnToCategoryList
    ? 'admin.php?session_context=admin&section=page-management&tab=contents&view=categories&notice='
    : 'admin.php?session_context=admin&section=page-management&tab=contents&notice=';
$successNames = array_values(array_unique(array_filter(array_map('trim', $successNames))));
$successNameQuery = $successNames ? '&names=' . rawurlencode(implode(', ', $successNames)) : '';
header('Location: ' . $redirect . rawurlencode($notice) . ($returnToCategoryList ? $successNameQuery . $bulkDetailsQuery : '') . '#pm-panel-contents', true, 303);
exit;
