<?php
/*
|--------------------------------------------------------------------------
| JSON API  (POST api.php?action=...)
|--------------------------------------------------------------------------
| Used by customers, categories, products pages via App.api().
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'POST required.'], 405);
}
require_csrf_json();

$action = (string)($_GET['action'] ?? '');
$input = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

function in_str(array $in, string $key, int $max = 255): string
{
    return mb_substr(trim((string)($in[$key] ?? '')), 0, $max);
}

/* ---------------- CUSTOMERS ---------------- */
function customers_list(): array
{
    $rows = db_all('SELECT * FROM customers ORDER BY id DESC');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['addresses'] = $r['addresses'] ? (json_decode($r['addresses'], true) ?: []) : [];
        $r['platform'] = $r['platform'] ?: '-';
        $r['store'] = $r['store'] ?: '-';
        $r['code'] = $r['code'] ?? '';
        $r['email'] = $r['email'] ?? '';
    }
    return $rows;
}

function customer_data(array $in): array
{
    $name = in_str($in, 'name', 200);
    $first = in_str($in, 'first_name', 100);
    $last = in_str($in, 'last_name', 100);
    if ($name === '') {
        $name = trim($first . ' ' . $last);
    }
    $mobile = in_str($in, 'mobile', 15);
    $email = in_str($in, 'email', 254);

    if ($name === '') {
        json_response(['success' => false, 'message' => 'Customer name is required.'], 422);
    }
    if (!preg_match('/^[0-9]{10}$/', $mobile)) {
        json_response(['success' => false, 'message' => 'Enter a valid 10-digit mobile number.'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'Enter a valid email address.'], 422);
    }

    $platform = in_str($in, 'platform', 100);
    $store = in_str($in, 'store', 100);

    $data = [
        'name' => $name,
        'mobile' => $mobile,
        'email' => $email ?: null,
        'code' => in_str($in, 'code', 50) ?: null,
        'platform' => ($platform === '-' ? '' : $platform) ?: null,
        'store' => ($store === '-' ? '' : $store) ?: null,
        'status' => ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'newsletter' => ($in['newsletter'] ?? 'no') === 'yes' ? 'yes' : 'no',
    ];
    if ($first !== '' || $last !== '') {
        $data['first_name'] = $first ?: null;
        $data['last_name'] = $last ?: null;
    }
    if (isset($in['account_type'])) {
        $type = strtolower(in_str($in, 'account_type', 30));
        $data['account_type'] = $type === 'business' ? 'Business' : 'Individual';
    }
    if (isset($in['gstin'])) {
        $gstin = strtoupper(preg_replace('/\s+/', '', in_str($in, 'gstin', 20)));
        if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $gstin)) {
            json_response(['success' => false, 'message' => 'Enter a valid 15-character GST number.'], 422);
        }
        // GST number applies only to Business accounts.
        if (($data['account_type'] ?? '') === 'Individual') {
            $gstin = '';
        }
        $data['gstin'] = $gstin ?: null;
    }
    if (isset($in['business_name'])) {
        $businessName = in_str($in, 'business_name', 200);
        if (($data['account_type'] ?? '') === 'Individual') {
            $businessName = '';
        }
        $data['business_name'] = $businessName ?: null;
    }
    if (isset($in['addresses']) && is_array($in['addresses'])) {
        $addresses = [];
        foreach (array_slice($in['addresses'], 0, 20) as $a) {
            $label = mb_substr(trim((string)($a['label'] ?? '')), 0, 100);
            $address = mb_substr(trim((string)($a['address'] ?? '')), 0, 1000);
            if ($label !== '' || $address !== '') {
                $addresses[] = ['label' => $label, 'address' => $address];
            }
        }
        $data['addresses'] = json_encode($addresses);
    }
    return $data;
}

/* ---------------- CATEGORIES ---------------- */
function categories_list(): array
{
    // Tree order: each parent followed by its sub-categories.
    $all = [];
    foreach (db_all('SELECT * FROM categories') as $row) {
        $all[(int)$row['id']] = $row;
    }
    $rows = [];
    foreach (category_tree() as $node) {
        $rows[] = $all[$node['id']] + ['depth' => $node['depth'], 'label' => $node['label']];
    }
    $childCount = [];
    foreach ($rows as $row) {
        if ($row['parent_id']) {
            $childCount[(int)$row['parent_id']] = ($childCount[(int)$row['parent_id']] ?? 0) + 1;
        }
    }
    foreach ($rows as &$r) {
        $r['children'] = $childCount[(int)$r['id']] ?? 0;
        $r['id'] = (int)$r['id'];
        $r['sort'] = (int)$r['sort_order'];
        $r['enabled'] = (bool)$r['enabled'];
        $r['featured'] = (bool)$r['featured'];
        $r['hasImage'] = !empty($r['image_url']);
        $r['code'] = $r['code'] ?? '';
        $r['parentId'] = $r['parent_id'] ? (int)$r['parent_id'] : null;
        $r['colorCode'] = $r['color_code'];
        $r['imageUrl'] = $r['image_url'];
    }
    return $rows;
}

function category_data(array $in): array
{
    $name = in_str($in, 'name', 150);
    if ($name === '') {
        json_response(['success' => false, 'message' => 'Category name is required.'], 422);
    }
    $image = in_str($in, 'imageUrl', 500);
    if ($image !== '' && !preg_match('~^(https?://\S+|/?uploads/\S+|/(?!/)\S*)$~i', $image)) {
        json_response(['success' => false, 'message' => 'Enter a valid image URL.'], 422);
    }
    $data = [
        'name' => $name,
        'code' => in_str($in, 'code', 50) ?: null,
        'sort_order' => max(0, (int)($in['sort'] ?? 0)),
        'enabled' => !empty($in['enabled']) ? 1 : 0,
        'featured' => !empty($in['featured']) ? 1 : 0,
    ];
    if (array_key_exists('parentId', $in)) {
        $data['parent_id'] = $in['parentId'] ? (int)$in['parentId'] : null;
    }
    if (array_key_exists('colorCode', $in)) {
        $data['color_code'] = in_str($in, 'colorCode', 20) ?: null;
    }
    if (array_key_exists('imageUrl', $in)) {
        $data['image_url'] = $image ?: null;
    }
    return $data;
}

/* ---------------- PRODUCTS ---------------- */
function products_list(): array
{
    $rows = db_all('SELECT * FROM products ORDER BY id DESC');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['enabled'] = (bool)$r['enabled'];
        $r['featured'] = (bool)$r['featured'];
        $r['stock'] = (float)$r['stock'];
        $r['price'] = (float)$r['price'];
        $r['rate'] = (float)$r['price'];
        $r['hsn'] = $r['hsn_code'];
        $r['tax_rate'] = (float)$r['tax_rate'];
    }
    unset($r);
    return attach_stock_stats($rows);
}

try {
    switch ($action) {

        /* ===== CUSTOMERS ===== */
        case 'customers.list':
            json_response(['success' => true, 'customers' => customers_list()]);

        case 'customers.save':
            $data = customer_data($input);
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                db_update('customers', $data, $id);
                log_activity('Customers', 'Updated customer ' . $data['name']);
            } else {
                $id = db_insert('customers', $data);
                log_activity('Customers', 'Added customer ' . $data['name']);
            }
            json_response(['success' => true, 'id' => $id, 'customers' => customers_list()]);

        case 'customers.delete':
            $id = (int)($input['id'] ?? 0);
            $name = db_value('SELECT name FROM customers WHERE id = ?', [$id]);
            db_exec('DELETE FROM customers WHERE id = ?', [$id]);
            log_activity('Customers', 'Deleted customer ' . $name);
            json_response(['success' => true, 'customers' => customers_list()]);

        case 'customers.toggle':
            $id = (int)($input['id'] ?? 0);
            db_exec("UPDATE customers SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
            json_response(['success' => true, 'customers' => customers_list()]);

        case 'customers.import':
            $rows = is_array($input['customers'] ?? null) ? $input['customers'] : [];
            $count = 0;
            db()->beginTransaction();
            foreach (array_slice($rows, 0, 5000) as $row) {
                $name = trim((string)($row['name'] ?? ''));
                $mobile = trim((string)($row['mobile'] ?? ''));
                if ($name === '' || !preg_match('/^[0-9]{10}$/', $mobile)) {
                    continue;
                }
                $email = trim((string)($row['email'] ?? ''));
                db_insert('customers', [
                    'name' => mb_substr($name, 0, 200),
                    'mobile' => $mobile,
                    'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'code' => mb_substr(trim((string)($row['code'] ?? '')), 0, 50) ?: null,
                    'platform' => mb_substr(trim((string)($row['platform'] ?? '')), 0, 100) ?: null,
                    'store' => mb_substr(trim((string)($row['store'] ?? '')), 0, 100) ?: null,
                    'status' => ($row['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
                    'newsletter' => ($row['newsletter'] ?? '') === 'yes' ? 'yes' : 'no',
                ]);
                $count++;
            }
            db()->commit();
            log_activity('Customers', "Imported $count customers");
            json_response(['success' => true, 'imported' => $count, 'customers' => customers_list()]);

        /* ===== CATEGORIES ===== */
        case 'categories.list':
            json_response(['success' => true, 'categories' => categories_list()]);

        case 'categories.save':
            $data = category_data($input);
            $id = (int)($input['id'] ?? 0);
            // A category cannot be placed under itself or under one of its own sub-categories.
            if ($id > 0 && !empty($data['parent_id'])) {
                $walk = (int)$data['parent_id'];
                for ($i = 0; $walk && $i < 50; $i++) {
                    if ($walk === $id) {
                        json_response(['success' => false, 'message' => 'A category cannot be its own parent or sub-category.'], 422);
                    }
                    $walk = (int)db_value('SELECT parent_id FROM categories WHERE id = ?', [$walk]);
                }
            }
            if ($id > 0) {
                db_update('categories', $data, $id);
                // Keep product category labels ("Plywood › 18mm") in sync after rename / move.
                foreach (category_tree() as $node) {
                    db_exec('UPDATE products SET category = ? WHERE category_id = ?', [$node['label'], $node['id']]);
                }
                log_activity('Categories', 'Updated category ' . $data['name']);
            } else {
                $id = db_insert('categories', $data);
                log_activity('Categories', 'Added category ' . $data['name']);
            }
            json_response(['success' => true, 'id' => $id, 'categories' => categories_list()]);

        /*
         * Parent + many sub-categories in one go.
         * { parentId: 5 }  or  { parentName: "Plywood", colorCode, imageUrl, sort }
         * children: ["Marine Plywood", "Commercial Plywood", ...]  (duplicates skipped)
         */
        case 'categories.save_bulk':
            $children = [];
            foreach ((array)($input['children'] ?? []) as $c) {
                $c = mb_substr(trim((string)$c), 0, 150);
                if ($c !== '' && !in_array(mb_strtolower($c), array_map('mb_strtolower', $children), true)) {
                    $children[] = $c;
                }
            }
            $children = array_slice($children, 0, 200);
            $parentId = (int)($input['parentId'] ?? 0);
            $created = 0;
            $skipped = [];

            db()->beginTransaction();
            if ($parentId) {
                $parentName = db_value('SELECT name FROM categories WHERE id = ?', [$parentId]);
                if ($parentName === false) {
                    db()->rollBack();
                    json_response(['success' => false, 'message' => 'Parent category not found.'], 404);
                }
            } else {
                $parentName = in_str($input, 'parentName', 150);
                if ($parentName === '') {
                    db()->rollBack();
                    json_response(['success' => false, 'message' => 'Enter the parent category name.'], 422);
                }
                $existing = db_value('SELECT id FROM categories WHERE parent_id IS NULL AND LOWER(name) = LOWER(?)', [$parentName]);
                if ($existing) {
                    $parentId = (int)$existing; // same parent already there -> just add the sub-categories
                } else {
                    $parentId = db_insert('categories', category_data([
                        'name' => $parentName,
                        'sort' => $input['sort'] ?? 0,
                        'colorCode' => $input['colorCode'] ?? null,
                        'imageUrl' => $input['imageUrl'] ?? '',
                        'enabled' => true,
                        'parentId' => null,
                    ]));
                    $created++;
                }
            }
            $sort = (int)db_value('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$parentId]);
            foreach ($children as $child) {
                if (db_value('SELECT COUNT(*) FROM categories WHERE parent_id = ? AND LOWER(name) = LOWER(?)', [$parentId, $child])) {
                    $skipped[] = $child;
                    continue;
                }
                db_insert('categories', [
                    'name' => $child,
                    'parent_id' => $parentId,
                    'sort_order' => ++$sort,
                    'color_code' => in_str($input, 'colorCode', 20) ?: null,
                    'enabled' => 1,
                    'featured' => 0,
                ]);
                $created++;
            }
            db()->commit();
            log_activity('Categories', "Added $parentName with " . (count($children) - count($skipped)) . ' sub-categories');
            json_response([
                'success' => true,
                'parentId' => $parentId,
                'created' => $created,
                'skipped' => $skipped,
                'message' => (count($children) - count($skipped)) . ' sub-categories saved under ' . $parentName
                    . ($skipped ? ' (' . count($skipped) . ' already existed)' : '') . '.',
                'categories' => categories_list(),
            ]);

        case 'categories.delete':
            $id = (int)($input['id'] ?? 0);
            $name = db_value('SELECT name FROM categories WHERE id = ?', [$id]);
            db_exec('UPDATE categories SET parent_id = NULL WHERE parent_id = ?', [$id]);
            db_exec('DELETE FROM categories WHERE id = ?', [$id]);
            log_activity('Categories', 'Deleted category ' . $name);
            json_response(['success' => true, 'categories' => categories_list()]);

        case 'categories.toggle':
            db_exec('UPDATE categories SET enabled = 1 - enabled WHERE id = ?', [(int)($input['id'] ?? 0)]);
            json_response(['success' => true, 'categories' => categories_list()]);

        case 'categories.feature':
            db_exec('UPDATE categories SET featured = 1 - featured WHERE id = ?', [(int)($input['id'] ?? 0)]);
            json_response(['success' => true, 'categories' => categories_list()]);

        /* ===== PRODUCTS ===== */
        case 'products.list':
            json_response(['success' => true, 'products' => products_list()]);

        case 'products.delete':
            $id = (int)($input['id'] ?? 0);
            $name = db_value('SELECT name FROM products WHERE id = ?', [$id]);
            db_exec('DELETE FROM products WHERE id = ?', [$id]);
            log_activity('Products', 'Deleted product ' . $name);
            json_response(['success' => true, 'products' => products_list()]);

        case 'products.toggle':
            db_exec('UPDATE products SET enabled = 1 - enabled WHERE id = ?', [(int)($input['id'] ?? 0)]);
            json_response(['success' => true, 'products' => products_list()]);

        case 'products.feature':
            db_exec('UPDATE products SET featured = 1 - featured WHERE id = ?', [(int)($input['id'] ?? 0)]);
            json_response(['success' => true, 'products' => products_list()]);

        case 'products.stock':
            $id = (int)($input['id'] ?? 0);
            $stock = (float)($input['stock'] ?? 0);
            if ($stock < 0) {
                json_response(['success' => false, 'message' => 'Stock cannot be negative.'], 422);
            }
            db_exec('UPDATE products SET stock = ? WHERE id = ?', [$stock, $id]);
            log_activity('Products', 'Stock updated for product #' . $id . ' to ' . $stock);
            json_response(['success' => true, 'products' => products_list()]);

        /* ===== IMAGE UPLOAD ===== */
        case 'upload':
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                json_response(['success' => false, 'message' => 'Upload failed.'], 422);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                json_response(['success' => false, 'message' => 'Image must be smaller than 5 MB.'], 422);
            }
            $info = @getimagesize($file['tmp_name']);
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($types[$info[2]])) {
                json_response(['success' => false, 'message' => 'Only JPG, PNG, GIF or WEBP images are allowed.'], 422);
            }
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0775, true);
            }
            $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $types[$info[2]];
            move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name);
            json_response(['success' => true, 'url' => 'uploads/' . $name]);

        default:
            json_response(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
