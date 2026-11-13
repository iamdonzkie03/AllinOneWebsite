<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/rich-content.php';
$admin = require_admin();

$coverColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='content' AND COLUMN_NAME='cover_photo'");
$coverColumnCheck->execute();
if (!(int)$coverColumnCheck->fetchColumn()) $pdo->exec('ALTER TABLE content ADD COLUMN cover_photo VARCHAR(255) NULL AFTER excerpt');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: admin.php?session_context=admin&section=page-management&tab=contents#pm-panel-contents');
    exit;
}
verify_csrf();

$action = (string)($_POST['action'] ?? '');
if ($action === 'upload_article_image') {
    header('Content-Type: application/json; charset=utf-8');
    $respond = static function(int $status, array $payload): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) $respond(400, ['message'=>'Choose an image to upload.']);
    $upload = $_FILES['image'];
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $respond(400, ['message'=>'The image upload failed. Please try again.']);
    if ((int)($upload['size'] ?? 0) < 1 || (int)$upload['size'] > 8 * 1024 * 1024) $respond(413, ['message'=>'Images must be 8 MB or smaller.']);
    if (!is_uploaded_file((string)$upload['tmp_name'])) $respond(400, ['message'=>'Invalid image upload.']);
    $imageInfo = @getimagesize((string)$upload['tmp_name']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$upload['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!$imageInfo || !isset($extensions[$mime]) || (int)$imageInfo[0] > 8000 || (int)$imageInfo[1] > 8000) $respond(415, ['message'=>'Use a JPG, PNG, GIF or WebP image with dimensions no larger than 8000 × 8000 pixels.']);
    $directory = __DIR__ . '/uploads/articles';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) $respond(500, ['message'=>'The article image folder could not be created.']);
    $filename = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string)$upload['tmp_name'], $directory . '/' . $filename)) $respond(500, ['message'=>'The image could not be saved on the server.']);
    $appBasePath = dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/public/admin-content.php')));
    $appBasePath = $appBasePath === DIRECTORY_SEPARATOR || $appBasePath === '.' ? '' : rtrim(str_replace('\\', '/', $appBasePath), '/');
    $respond(200, ['url'=>$appBasePath . '/public/uploads/articles/' . $filename]);
}
$notice = 'saved';
$successNames = [];
$bulkDetailsQuery = '';
$newCoverFilePath = null;

$storeArticleCoverPhoto = static function(array $upload) use (&$newCoverFilePath): string {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('cover_photo_upload_failed');
    if ((int)($upload['size'] ?? 0) < 1 || (int)$upload['size'] >= 10 * 1024 * 1024) throw new RuntimeException('cover_photo_too_large');
    if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new RuntimeException('cover_photo_invalid');
    $imageInfo = @getimagesize((string)$upload['tmp_name']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$upload['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];
    if (!$imageInfo || !isset($extensions[$mime]) || (int)$imageInfo[0] > 8000 || (int)$imageInfo[1] > 8000) throw new RuntimeException('cover_photo_invalid');
    $directory = __DIR__ . '/uploads/articles/cover_photos';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('cover_photo_folder_failed');
    $filename = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) throw new RuntimeException('cover_photo_upload_failed');
    $newCoverFilePath = $destination;
    return $filename;
};

$deleteOrphanedCoverPhoto = static function(?string $filename, int $excludeArticleId = 0) use ($pdo): void {
    $filename = trim((string)$filename);
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename) || $filename === '.' || $filename === '..') return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM content WHERE id<>? AND cover_photo=?');
    $check->execute([$excludeArticleId, $filename]);
    if ((int)$check->fetchColumn() > 0) return;
    $path = __DIR__ . '/uploads/articles/cover_photos/' . $filename;
    if (is_file($path) && !is_link($path)) @unlink($path);
};

/**
 * Return only server-managed article image filenames referenced by rich HTML.
 * The editor and sanitizer restrict these to /public/uploads/articles/.
 */
$articleUploadFiles = static function(string $html): array {
    // Capture uploaded files used as image/media sources or direct file links.
    $attributePattern = '~\b(?:src|href)\s*=\s*(["\'])(.*?)\1~is';
    if (!preg_match_all($attributePattern, $html, $matches)) return [];
    $files = [];
    foreach ($matches[2] as $src) {
        $path = (string)(parse_url(html_entity_decode((string)$src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH) ?? '');
        if (preg_match('~(?:^|/)public/uploads/articles/([A-Za-z0-9._-]+)$~', $path, $match)) {
            $filename = basename($match[1]);
            if ($filename !== '' && $filename !== '.' && $filename !== '..') $files[$filename] = true;
        }
    }
    return $files;
};

/**
 * Remove old article images only after a successful edit and only when no
 * other content record still references the same uploaded file.
 */
$deleteOrphanedArticleImages = static function(array $oldFiles, array $newFiles, int $articleId) use ($pdo): void {
    $directory = __DIR__ . '/uploads/articles';
    if (!is_dir($directory)) return;
    $stillReferenced = $pdo->prepare('SELECT COUNT(*) FROM content WHERE id<>? AND body LIKE ?');
    foreach (array_keys($oldFiles) as $filename) {
        if (isset($newFiles[$filename])) continue;
        $stillReferenced->execute([$articleId, '%' . $filename . '%']);
        if ((int)$stillReferenced->fetchColumn() > 0) continue;
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path) && !is_link($path)) @unlink($path);
    }
};
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
            $findCoversByCategory = $pdo->prepare("SELECT cover_photo FROM content WHERE category_id=? AND content_type='article' AND cover_photo IS NOT NULL AND cover_photo<>''");
            $findCoversBySubcategory = $pdo->prepare("SELECT cover_photo FROM content WHERE subcategory_id=? AND content_type='article' AND cover_photo IS NOT NULL AND cover_photo<>''");
            $coverPhotosToClean = [];
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
                $findCoversByCategory->execute([$id]);
                foreach ($findCoversByCategory->fetchAll(PDO::FETCH_COLUMN) as $coverName) $coverPhotosToClean[(string)$coverName] = true;
                $deleteArticlesByCategory->execute([$id]);
                foreach ($children as $child) {
                    $childId = (int)$child['id'];
                    $childNames[] = (string)$child['name'];
                    $findCoversBySubcategory->execute([$childId]);
                    foreach ($findCoversBySubcategory->fetchAll(PDO::FETCH_COLUMN) as $coverName) $coverPhotosToClean[(string)$coverName] = true;
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
                $findCoversBySubcategory->execute([$id]);
                foreach ($findCoversBySubcategory->fetchAll(PDO::FETCH_COLUMN) as $coverName) $coverPhotosToClean[(string)$coverName] = true;
                $deleteArticlesBySubcategory->execute([$id]);
                $deleteCategory->execute([$id]);
                $deletedGroups[$parentName] ??= [];
                $deletedGroups[$parentName][] = (string)$subcategory['name'];
                $successNames[] = (string)$subcategory['name'];
            }

            $pdo->commit();
            foreach (array_keys($coverPhotosToClean) as $coverFilename) $deleteOrphanedCoverPhoto($coverFilename);
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
        $body = sanitize_rich_content(trim((string)($_POST['body'] ?? '')));
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
        $previousCoverPhoto = null;
        if ($id > 0) {
            $exists = $pdo->prepare("SELECT body,cover_photo FROM content WHERE id=? AND content_type='article'");
            $exists->execute([$id]);
            $previousArticle = $exists->fetch(PDO::FETCH_ASSOC);
            if (!$previousArticle) throw new RuntimeException('article_missing');
            $previousCoverPhoto = isset($previousArticle['cover_photo']) && $previousArticle['cover_photo'] !== '' ? (string)$previousArticle['cover_photo'] : null;
            $previousImageFiles = $articleUploadFiles((string)($previousArticle['body'] ?? ''));
            $currentImageFiles = $articleUploadFiles($body);
        } else {
            $previousImageFiles = [];
            $currentImageFiles = $articleUploadFiles($body);
        }
        $coverPhoto = $previousCoverPhoto;
        if (filter_var($_POST['remove_cover_photo'] ?? false, FILTER_VALIDATE_BOOLEAN)) $coverPhoto = null;
        if (isset($_FILES['cover_photo']) && is_array($_FILES['cover_photo']) && (int)($_FILES['cover_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $coverPhoto = $storeArticleCoverPhoto($_FILES['cover_photo']);
        }
        if ($id > 0) {
            $save = $pdo->prepare("UPDATE content SET category_id=?,subcategory_id=?,title=?,slug=?,excerpt=?,cover_photo=?,body=?,status=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END WHERE id=? AND content_type='article'");
            $save->execute([$categoryId,$subcategoryValue,$title,$slug,mb_substr($excerpt,0,500),$coverPhoto,$body,$status,$status,$id]);
            $newCoverFilePath = null;
            $deleteOrphanedArticleImages($previousImageFiles, $currentImageFiles, $id);
            if ($previousCoverPhoto !== (string)($coverPhoto ?? '')) $deleteOrphanedCoverPhoto($previousCoverPhoto, $id);
            $successNames[] = $title;
            $notice = 'article_updated';
        } else {
            $save = $pdo->prepare("INSERT INTO content (owner_id,category_id,subcategory_id,content_type,title,slug,excerpt,cover_photo,body,status,published_at) VALUES (?,?,?,'article',?,?,?,?,?,?,IF(?='published',NOW(),NULL))");
            $save->execute([(int)$admin['id'],$categoryId,$subcategoryValue,$title,$slug,mb_substr($excerpt,0,500),$coverPhoto,$body,$status,$status]);
            $newCoverFilePath = null;
            $notice = 'article_created';
            $successNames[] = $title;
        }
    } elseif ($action === 'bulk_delete_articles') {
        $selected = $_POST['selected_articles'] ?? [];
        if (!is_array($selected)) $selected = [];
        $ids = array_values(array_unique(array_filter(array_map(static function($value) {
            return is_scalar($value) && ctype_digit((string)$value) ? (int)$value : 0;
        }, $selected), static fn($id) => $id > 0)));
        if (!$ids) throw new RuntimeException('nothing_selected');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $find = $pdo->prepare("SELECT id,title,body,cover_photo FROM content WHERE content_type='article' AND id IN ($placeholders) ORDER BY title");
        $find->execute($ids);
        $found = $find->fetchAll(PDO::FETCH_ASSOC);
        if (!$found) throw new RuntimeException('article_missing');
        $filesToClean = [];
        $coverPhotosToClean = [];
        foreach ($found as $articleRow) {
            foreach (array_keys($articleUploadFiles((string)($articleRow['body'] ?? ''))) as $filename) {
                $filesToClean[$filename] = true;
            }
            if (!empty($articleRow['cover_photo'])) $coverPhotosToClean[(string)$articleRow['cover_photo']] = true;
        }
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare("DELETE FROM content WHERE content_type='article' AND id IN ($placeholders)");
            $delete->execute($ids);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Remove only uploaded article files that are no longer referenced by
        // any remaining content record. Shared media is deliberately retained.
        $stillReferenced = $pdo->prepare('SELECT COUNT(*) FROM content WHERE body LIKE ?');
        $uploadDirectory = __DIR__ . '/uploads/articles';
        foreach (array_keys($filesToClean) as $filename) {
            $stillReferenced->execute(['%' . $filename . '%']);
            if ((int)$stillReferenced->fetchColumn() > 0) continue;
            $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($filePath) && !is_link($filePath)) @unlink($filePath);
        }
        foreach (array_keys($coverPhotosToClean) as $coverFilename) $deleteOrphanedCoverPhoto($coverFilename);
        foreach ($found as $articleRow) $successNames[] = (string)$articleRow['title'];
        $notice = 'articles_bulk_deleted';
    } elseif ($action === 'toggle_article_status') {
        $id = (int)($_POST['item_id'] ?? 0);
        $find = $pdo->prepare("SELECT status FROM content WHERE id=? AND content_type='article'");
        $find->execute([$id]);
        $currentStatus = $find->fetchColumn();
        if ($currentStatus === false) throw new RuntimeException('article_missing');
        $nextStatus = $currentStatus === 'published' ? 'draft' : 'published';
        $toggle = $pdo->prepare("UPDATE content SET status=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END WHERE id=? AND content_type='article'");
        $toggle->execute([$nextStatus,$nextStatus,$id]);
        $notice = 'article_status_updated';
    } elseif ($action === 'delete_article') {
        $id = (int)($_POST['item_id'] ?? 0);
        $find = $pdo->prepare("SELECT body,cover_photo FROM content WHERE id=? AND content_type='article'");
        $find->execute([$id]);
        $articleRow = $find->fetch(PDO::FETCH_ASSOC);
        if (!$articleRow) throw new RuntimeException('article_missing');
        $articleBody = (string)($articleRow['body'] ?? '');
        $articleCoverPhoto = (string)($articleRow['cover_photo'] ?? '');
        $filesToClean = array_keys($articleUploadFiles($articleBody));
        $delete = $pdo->prepare("DELETE FROM content WHERE id=? AND content_type='article'");
        $delete->execute([$id]);
        if (!$delete->rowCount()) throw new RuntimeException('article_missing');
        $stillReferenced = $pdo->prepare('SELECT COUNT(*) FROM content WHERE body LIKE ?');
        $uploadDirectory = __DIR__ . '/uploads/articles';
        foreach ($filesToClean as $filename) {
            $stillReferenced->execute(['%' . $filename . '%']);
            if ((int)$stillReferenced->fetchColumn() > 0) continue;
            $filePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($filePath) && !is_link($filePath)) @unlink($filePath);
        }
        $deleteOrphanedCoverPhoto($articleCoverPhoto);
        $notice = 'article_deleted';
    } else {
        throw new RuntimeException('unknown_action');
    }
} catch (PDOException $e) {
    if ($newCoverFilePath && is_file($newCoverFilePath) && !is_link($newCoverFilePath)) @unlink($newCoverFilePath);
    error_log('Content manager database error: ' . $e->getMessage());
    $notice = 'duplicate_or_database_error';
} catch (RuntimeException $e) {
    if ($newCoverFilePath && is_file($newCoverFilePath) && !is_link($newCoverFilePath)) @unlink($newCoverFilePath);
    $notice = $e->getMessage();
}

$returnToCategoryList = in_array($action, ['save_category','save_subcategory','delete_category','delete_subcategory','bulk_delete_categories'], true);
$redirect = $returnToCategoryList
    ? 'admin.php?session_context=admin&section=page-management&tab=contents&view=categories&notice='
    : 'admin.php?session_context=admin&section=page-management&tab=contents&notice=';
$successNames = array_values(array_unique(array_filter(array_map('trim', $successNames))));
$successNameQuery = $successNames ? '&names=' . rawurlencode(implode(', ', $successNames)) : '';
header('Location: ' . $redirect . rawurlencode($notice) . ($returnToCategoryList ? $successNameQuery . $bulkDetailsQuery : (in_array($action, ['save_article','bulk_delete_articles'], true) ? $successNameQuery : '')) . '#pm-panel-contents', true, 303);
exit;
