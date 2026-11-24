<?php
/*
 * ADD / EDIT PRODUCT - saved in MySQL `products` table.
 * add-product.php        -> new product
 * add-product.php?id=5   -> edit product #5
 */
require_once __DIR__ . '/includes/init.php';

$stores = [
    'Gullapudi Shop',
    'Gullapudi Godown'
];

// Categories in tree order; option value = full label, e.g. "Plywood › 18mm Ply".
$categoryRows = category_tree(true);

$productTypes = [
    'Manufacture',
    'Customized'
];

// GST rates from the Taxes page (id => row). Inside state = CGST + SGST, outside = IGST.
$gstRates = array_column(gst_rates(true), null, 'id');

// Sheets already saved (for the Custom Thickness dropdown: thickness -> price + available qty).
$sheetRows = array_map(fn($p) => [
    'id' => (int)$p['id'],
    'name' => $p['name'],
    'thickness' => (string)$p['thickness'],
    'price' => (float)$p['price'],
    'stock' => (float)$p['stock'],
    'category_id' => $p['category_id'] === null ? null : (int)$p['category_id'],
    'enabled' => (bool)$p['enabled'],
], db_all('SELECT id, name, thickness, price, stock, category_id, enabled FROM products ORDER BY name'));

$errors = [];
$success = '';
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = null;

if ($editId) {
    $editing = db_one('SELECT * FROM products WHERE id = ?', [$editId]);
    if (!$editing) {
        header('Location: products.php');
        exit;
    }
    // Keep the product's current tax selectable even if it was disabled later.
    $currentTaxId = (int)$editing['tax_id'];
    if ($currentTaxId && !isset($gstRates[$currentTaxId])) {
        $t = db_one('SELECT id, name, code, amount FROM taxes WHERE id = ?', [$currentTaxId]);
        if ($t) {
            $gstRates[$currentTaxId] = ['id' => (int)$t['id'], 'name' => $t['name'] . ' (disabled)', 'code' => $t['code'], 'rate' => (float)$t['amount']];
        }
    }
}

function old($key, $default = '') {
    global $editing;
    $map = ['product_name' => 'name'];
    if ($default === '' && $editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $col = $map[$key] ?? $key;
        $default = $editing[$col] ?? '';
        if (in_array($col, ['price', 'discount_value', 'stock'], true) && $default !== '') {
            $default = rtrim(rtrim((string)$default, '0'), '.');
        }
    }
    return htmlspecialchars(
        (string)($_POST[$key] ?? $default),
        ENT_QUOTES,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_valid()) {
        $errors[] = 'Session expired. Please refresh and try again.';
    }

    $name = trim($_POST['product_name'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $stock = trim($_POST['stock'] ?? '');

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }

    if ($price !== '' &&
        (!is_numeric($price) || (float)$price < 0)) {
        $errors[] = 'Enter a valid product price.';
    }

    if ($stock !== '' &&
        (!is_numeric($stock) || (float)$stock < 0)) {
        $errors[] = 'Enter a valid stock quantity.';
    }

    // GST rate used by quotations / invoices (split CGST+SGST or IGST there).
    $taxId = (int)($_POST['tax_id'] ?? 0);
    if ($taxId && !isset($gstRates[$taxId])) {
        $errors[] = 'Select a valid GST rate.';
    }
    $taxRate = $taxId && isset($gstRates[$taxId]) ? $gstRates[$taxId]['rate'] : 0;
    $taxCode = $taxId && isset($gstRates[$taxId]) ? [$gstRates[$taxId]['code']] : [];

    if (empty($errors)) {

        $categoryName = trim($_POST['category'] ?? '');
        $categoryId = null;
        foreach ($categoryRows as $c) {
            if ($c['label'] === $categoryName) {
                $categoryId = $c['id'];
            }
        }

        $product = [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'store' => trim($_POST['store'] ?? '') ?: null,
            'name' => mb_substr($name, 0, 200),
            'category_id' => $categoryId,
            'category' => $categoryName ?: null,
            'product_type' => trim($_POST['product_type'] ?? '') ?: null,
            'price' => $price === '' ? 0 : (float)$price,
            'stock' => $stock === '' ? 0 : (float)$stock,
            'hsn_code' => trim($_POST['hsn_code'] ?? '') ?: null,
            'thickness' => trim($_POST['thickness'] ?? '') ?: null,
            'size' => trim($_POST['size'] ?? '') ?: null,
            'product_expiry' => isset($_POST['product_expiry']) ? 1 : 0,
            'measurement_unit' => trim($_POST['measurement_unit'] ?? '') ?: null,
            'taxes' => json_encode($taxCode),
            'default_taxes' => json_encode($taxCode),
            'tax_id' => $taxId ?: null,
            'tax_rate' => $taxRate,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'additional_info' => trim($_POST['additional_info'] ?? '') ?: null
        ];

        // Multiple thicknesses at once: one product per thickness row.
        // When editing, the row marked "current" updates this product; other rows are new products.
        $variantThick = (array)($_POST['variant_thickness'] ?? []);
        $useVariants = isset($_POST['use_variants']) && $variantThick;

        if ($useVariants) {
            $rows = [];
            $currentRow = null;
            $seen = [];
            foreach ($variantThick as $i => $t) {
                $t = mb_substr(trim((string)$t), 0, 50);
                if ($t === '') continue;
                $isCurrent = $editing && ($_POST['variant_current'][$i] ?? '0') === '1' && $currentRow === null;
                $vName = mb_substr(trim((string)($_POST['variant_name'][$i] ?? '')) ?: ($name . ' ' . $t), 0, 200);
                $vPrice = trim((string)($_POST['variant_price'][$i] ?? ''));
                $vStock = trim((string)($_POST['variant_stock'][$i] ?? ''));
                $vHsn = mb_substr(trim((string)($_POST['variant_hsn'][$i] ?? '')), 0, 50);
                if (($vPrice !== '' && (!is_numeric($vPrice) || (float)$vPrice < 0)) ||
                    ($vStock !== '' && (!is_numeric($vStock) || (float)$vStock < 0))) {
                    $errors[] = "Enter a valid price and stock for thickness $t.";
                    continue;
                }
                $key = mb_strtolower($vName);
                if (isset($seen[$key])) {
                    $errors[] = "\"$vName\" is listed twice.";
                    continue;
                }
                $seen[$key] = true;
                if (db_one('SELECT id FROM products WHERE LOWER(name) = ? AND id <> ?', [$key, $editing ? $editId : 0])) {
                    $errors[] = "A product named \"$vName\" already exists.";
                    continue;
                }
                $row = array_merge($product, [
                    'name' => $vName,
                    'thickness' => $t,
                    'price' => $vPrice === '' ? $product['price'] : (float)$vPrice,
                    // New thicknesses added while editing start at 0 stock unless typed.
                    'stock' => $vStock === '' ? ($editing && !$isCurrent ? 0 : $product['stock']) : (float)$vStock,
                    'hsn_code' => $vHsn !== '' ? $vHsn : $product['hsn_code'],
                ]);
                if ($isCurrent) {
                    $currentRow = $row;
                } else {
                    $rows[] = $row;
                }
            }
            if ($editing && $currentRow === null && !$errors) {
                $errors[] = 'Keep a row for the product you are editing.';
            }
            if (!$rows && !$currentRow && !$errors) {
                $errors[] = 'Add at least one thickness.';
            }
            if (empty($errors)) {
                db()->beginTransaction();
                if ($currentRow) {
                    db_update('products', $currentRow, $editId);
                }
                foreach ($rows as $r) {
                    db_insert('products', $r);
                }
                db()->commit();
                $names = array_column($rows, 'name');
                if ($currentRow) {
                    log_activity('Products', 'Updated product ' . $currentRow['name'] . ($names ? ' and added: ' . implode(', ', $names) : ''));
                } else {
                    log_activity('Products', 'Added ' . count($rows) . ' products: ' . implode(', ', $names));
                }
                header('Location: products.php?saved=' . (count($rows) + ($currentRow ? 1 : 0)) . ($currentRow ? '&edited=1' : ''));
                exit;
            }
        } elseif ($editing) {
            db_update('products', $product, $editId);
            log_activity('Products', 'Updated product ' . $product['name']);
        } else {
            db_insert('products', $product);
            log_activity('Products', 'Added product ' . $product['name']);
        }

        if (empty($errors)) {
            header('Location: products.php?saved=1');
            exit;
        }
    }
}

/* Values for JS to pre-fill selects / checkboxes. */
$formValues = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = [
        'store' => $_POST['store'] ?? '',
        'category' => $_POST['category'] ?? '',
        'product_type' => $_POST['product_type'] ?? '',
        'measurement_unit' => $_POST['measurement_unit'] ?? '',
        'tax_id' => (string)($_POST['tax_id'] ?? ''),
        'enabled' => isset($_POST['enabled']),
        'product_expiry' => isset($_POST['product_expiry']),
        'use_variants' => isset($_POST['use_variants']),
        'variants' => array_map(fn($i) => [
            'name' => (string)($_POST['variant_name'][$i] ?? ''),
            'thickness' => (string)($_POST['variant_thickness'][$i] ?? ''),
            'price' => (string)($_POST['variant_price'][$i] ?? ''),
            'stock' => (string)($_POST['variant_stock'][$i] ?? ''),
            'hsn' => (string)($_POST['variant_hsn'][$i] ?? ''),
            'current' => ($_POST['variant_current'][$i] ?? '0') === '1',
        ], array_keys((array)($_POST['variant_thickness'] ?? []))),
    ];
} elseif ($editing) {
    $formValues = [
        'store' => $editing['store'],
        'category' => (function () use ($editing, $categoryRows) {
            // Show the current tree label for the saved category id.
            foreach ($categoryRows as $c) {
                if ($c['id'] === (int)$editing['category_id']) {
                    return $c['label'];
                }
            }
            return $editing['category'];
        })(),
        'product_type' => $editing['product_type'],
        'measurement_unit' => $editing['measurement_unit'],
        'tax_id' => $editing['tax_id'] ? (string)$editing['tax_id'] : '',
        'enabled' => (bool)$editing['enabled'],
        'product_expiry' => (bool)$editing['product_expiry'],
    ];
}

$pageTitle = $editing ? 'Edit Product' : 'Add Product';
include 'header.php';
?>


<section class="add-product-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="ap-header">

        <a href="products.php"
           class="ap-back-btn"
           title="Back to Products">
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>
            <h1 class="ap-title"><?php echo $editing ? 'Edit Product' : 'Add Product'; ?></h1>

            <p class="ap-subtitle">
                <?php echo $editing ? 'Update product details and stock.' : 'Create a new product for the product catalog.'; ?>
            </p>
        </div>

    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <div>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success" role="status">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- ADD PRODUCT FORM -->
    <form id="addProductForm"
          action="add-product.php<?php echo $editing ? '?id=' . (int)$editId : ''; ?>"
          method="POST">

        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$editId; ?>">

        <div class="ap-card">

            <!-- CARD HEADER -->
            <div class="ap-card-header">

                <div class="ap-card-icon">
                    <i class="bi bi-tags-fill"></i>
                </div>

                <div>
                    <h2 class="ap-card-title">
                        Product Details
                    </h2>

                    <p class="ap-card-subtitle">
                        Fill the required product information
                    </p>
                </div>

                <span class="ap-new-badge"><?php echo $editing ? 'Edit' : 'New'; ?></span>

            </div>

            <!-- CARD BODY -->
            <div class="ap-card-body">

                <div class="row g-4">

                    <!-- LEFT COLUMN -->
                    <div class="col-lg-6">

                        <!-- ENABLED -->
                        <div class="ap-field">
                            <label class="ap-label">
                                Enabled
                            </label>

                            <label class="ap-check-box"
                                   id="enabledBox">

                                <input type="checkbox"
                                       class="ap-checkbox"
                                       id="productEnabled"
                                       name="enabled"
                                       value="1"
                                       <?php echo (
                                           !isset($_POST['enabled']) &&
                                           $_SERVER['REQUEST_METHOD'] !== 'POST'
                                       ) || isset($_POST['enabled'])
                                           ? 'checked' : ''; ?>>

                                <span>
                                    <span class="ap-check-title d-block"
                                          id="enabledTitle">
                                        Enabled
                                    </span>

                                    <span class="ap-check-subtitle"
                                          id="enabledDescription">
                                        Control category visibility in the catalog
                                    </span>
                                </span>

                            </label>
                        </div>

                        <!-- PRODUCT NAME -->
                        <div class="ap-field">

                            <label for="productName"
                                   class="ap-label">
                                Product Name
                                <span class="ap-required">*</span>
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-tag-fill ap-input-icon"></i>

                                <input type="text"
                                       class="ap-input"
                                       id="productName"
                                       name="product_name"
                                       placeholder="Enter product name"
                                       maxlength="150"
                                       value="<?php echo old('product_name'); ?>"
                                       required>

                            </div>
                        </div>

                        <!-- PRICE -->
                        <div class="ap-field">

                            <label for="productPrice"
                                   class="ap-label">
                                Price
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-currency-rupee ap-input-icon"></i>

                                <input type="number"
                                       class="ap-input"
                                       id="productPrice"
                                       name="price"
                                       placeholder="Enter price"
                                       min="0"
                                       step="0.01"
                                       value="<?php echo old('price'); ?>">

                            </div>
                        </div>

                        <!-- STOCK QUANTITY -->
                        <div class="ap-field">

                            <label for="productStock"
                                   class="ap-label">
                                Stock Quantity
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-box-seam ap-input-icon"></i>

                                <input type="number"
                                       class="ap-input"
                                       id="productStock"
                                       name="stock"
                                       min="0"
                                       step="0.01"
                                       placeholder="Enter available stock"
                                       value="<?php echo old('stock'); ?>">

                            </div>
                        </div>

                        <!-- HSN CODE -->
                        <div class="ap-field">

                            <label for="hsnCode"
                                   class="ap-label">
                                HSN Code
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-upc-scan ap-input-icon"></i>

                                <input type="text"
                                       class="ap-input"
                                       id="hsnCode"
                                       name="hsn_code"
                                       placeholder="Enter HSN Code"
                                       value="<?php echo old('hsn_code'); ?>">

                            </div>
                        </div>

                        <!-- CUSTOM THICKNESS: sheets already saved in the chosen (sub-)category -->
                        <div class="ap-field">

                            <label for="thicknessPick"
                                   class="ap-label">
                                Custom Thickness
                                <span class="ap-sub-count" id="thicknessCount"></span>
                            </label>

                            <div class="ap-input-wrapper">
                                <i class="bi bi-layers-fill ap-input-icon"></i>
                                <select class="ap-select" id="thicknessPick" disabled>
                                    <option value="">Select sub-category first</option>
                                </select>
                            </div>

                            <!-- Price + available quantity of the chosen sheet -->
                            <div class="thk-info" id="thicknessInfo" hidden></div>

                            <div class="ap-input-wrapper mt-2" id="thicknessTypeWrap">

                                <i class="bi bi-rulers ap-input-icon"></i>

                                <input type="text"
                                       class="ap-input"
                                       id="thickness"
                                       name="thickness"
                                       placeholder="Enter thickness"
                                       value="<?php echo old('thickness'); ?>">

                            </div>

                            <style>
                                .add-product-section .thk-info{margin-top:10px;border:1px solid #cfe0fb;background:#f5f9ff;border-radius:10px;padding:12px 14px}
                                .add-product-section .thk-info .thk-name{font-weight:700;color:#1d2f4a;font-size:14px;display:flex;align-items:center;gap:8px}
                                .add-product-section .thk-info .thk-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
                                .add-product-section .thk-info .thk-stat{background:#fff;border:1px solid #e3eaf4;border-radius:8px;padding:8px 10px}
                                .add-product-section .thk-info .thk-stat span{display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#6b7c95;letter-spacing:.03em}
                                .add-product-section .thk-info .thk-stat strong{font-size:16px;color:#1d2f4a}
                                .add-product-section .thk-info .thk-stat.ok strong{color:#0f7a3d}
                                .add-product-section .thk-info .thk-stat.low strong{color:#c2343c}
                                .add-product-section .thk-info .thk-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
                                .add-product-section .thk-info .thk-actions a,.add-product-section .thk-info .thk-actions button{font-size:12.5px;font-weight:600;border-radius:8px;padding:6px 12px;border:1px solid #1479ff;color:#1479ff;background:#fff;text-decoration:none}
                                .add-product-section .thk-info .thk-actions a{background:#1479ff;color:#fff}
                                .add-product-section .thk-info .thk-warn{margin-top:8px;font-size:12.5px;color:#a15c00}
                            </style>
                        </div>

            <!-- MULTIPLE THICKNESSES (add + edit) -->
            <div class="ap-field">
                <div class="tv-box" id="tvBox">
                    <label class="tv-toggle">
                        <input type="checkbox" id="useVariants" name="use_variants" value="1">
                        <span class="tv-switch"></span>
                        <span>
                            <?php if ($editing): ?>
                            <strong>Add more thicknesses of this product</strong>
                            <small>This product stays as the first row (updated). Every other thickness is created as a new product with the same details.</small>
                            <?php else: ?>
                            <strong>Add multiple thicknesses at once</strong>
                            <small>Example: Marine Plywood in 6, 9, 12, 18, 19 mm &mdash; one product is created for each thickness.</small>
                            <?php endif; ?>
                            <a href="#tvBody" class="tv-hint" id="tvHint" hidden><i class="bi bi-arrow-down-circle-fill"></i> Enter the thicknesses at the bottom, under Additional Information</a>
                        </span>
                    </label>

                </div>
            </div>
            <style>
                .tv-box{border:1px dashed #b9cdf0;border-radius:12px;background:#f7faff;padding:16px 18px}
                .tv-toggle{display:flex;gap:12px;align-items:center;cursor:pointer;margin:0}
                .tv-toggle input{display:none}
                .tv-toggle strong{display:block;font-size:14px;color:#1d2f4a}
                .tv-toggle small{color:#6b7c95;font-size:12.5px}
                .tv-switch{flex:0 0 42px;height:24px;border-radius:12px;background:#cdd6e3;position:relative;transition:.2s}
                .tv-switch::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
                .tv-toggle input:checked + .tv-switch{background:#1479ff}
                .tv-toggle input:checked + .tv-switch::after{left:21px}
                .tv-panel{border:1px solid #b9cdf0;border-radius:12px;background:#f7faff;padding:18px 20px}
                .tv-panel-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
                .tv-panel-head h3{font-size:15px;font-weight:700;color:#1d2f4a;margin:0}
                .tv-panel-head p{font-size:12.5px;color:#6b7c95;margin:0}
                .tv-panel-icon{width:38px;height:38px;border-radius:10px;background:#e8f1ff;color:#1479ff;display:grid;place-items:center;font-size:17px}
                .tv-panel-count{margin-left:auto;font-size:12.5px;font-weight:700;color:#0f5fcc;background:#e8f1ff;border-radius:20px;padding:4px 12px}
                .tv-panel-count:empty{display:none}
                .tv-hint{display:block;margin-top:4px;font-size:12px;color:#0f7a3d;font-weight:600}
                @media (max-width:767px){
                    .tv-table tr:not(.tv-empty){grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) minmax(0,1fr) 36px !important}
                    .tv-table td:nth-child(1){order:1}.tv-table td:nth-child(4){order:2}.tv-table td:nth-child(5){order:3}.tv-table td:nth-child(6){order:4}
                    .tv-table td:nth-child(3){order:6;grid-column:1/-1}
                    .tv-table td:nth-child(2){order:5;grid-column:1/-1}
                }
                .tv-entry{display:flex;gap:8px;flex-wrap:wrap}
                .tv-chips{flex:1 1 220px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:46px;border:1px solid #dfe6ef;border-radius:9px;background:#fff;padding:6px 10px}
                .tv-chips input{flex:1;min-width:120px;border:0;outline:0;font-size:14px;background:transparent}
                .tv-chip{background:#e8f1ff;color:#0f5fcc;border-radius:20px;padding:3px 6px 3px 11px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:4px}
                .tv-chip button{border:0;background:transparent;color:#0f5fcc;line-height:1;padding:0 3px;font-size:15px}
                .tv-unit{height:46px;border:1px solid #dfe6ef;border-radius:9px;padding:0 10px;background:#fff}
                .tv-add{height:46px;border:0;border-radius:9px;background:#1479ff;color:#fff;padding:0 16px;font-weight:600}
                .tv-quick{margin:10px 0 14px;font-size:12.5px;color:#6b7c95;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
                .tv-quick button{border:1px solid #d3deee;background:#fff;border-radius:20px;padding:3px 11px;font-size:12.5px;color:#2b4366}
                .tv-quick button:hover{border-color:#1479ff;color:#1479ff}
                .tv-quick .tv-clear{color:#c2343c;border-color:#f1c9cc}
                .tv-table{width:100%;border-collapse:separate;border-spacing:0 6px}
                .tv-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#6b7c95;padding:0 6px}
                .tv-table td{padding:0 3px}
                .tv-table th{padding:0 3px}
                .tv-table td input{padding:6px 8px}
                .tv-table td input{width:100%;height:40px;border:1px solid #dfe6ef;border-radius:8px;padding:6px 10px;font-size:14px;background:#fff}
                .tv-table td input:focus{border-color:#1479ff;outline:0;box-shadow:0 0 0 3px rgba(20,121,255,.09)}
                .tv-table .tv-thk{font-weight:700;color:#0f5fcc}
                .tv-table .tv-del{border:0;background:#fff0f0;color:#c2343c;width:36px;height:36px;border-radius:8px}
                .tv-table tr.tv-empty td{text-align:center;color:#94a3b8;padding:14px;border:1px dashed #dfe6ef;border-radius:8px}
                .tv-badge{display:inline-block;margin-top:4px;font-size:11px;font-weight:700;color:#0f7a3d;background:#e5f7ec;border-radius:10px;padding:1px 8px}
                .tv-badge.new{color:#0f5fcc;background:#e8f1ff}
                .tv-table tr.tv-current td input{border-color:#9fd9b6;background:#f6fdf9}
                .tv-note{font-size:12.5px;color:#6b7c95;margin:6px 0 0}
                /* Each thickness = a small card: Thickness / Price / Stock on top, full-width name below. */
                .tv-table thead{display:none}
                .tv-table,.tv-table tbody{display:block;width:100%}
                .tv-table tr:not(.tv-empty){display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,2fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1fr) 36px;align-items:end;gap:8px;background:#fff;border:1px solid #e3eaf4;border-radius:10px;padding:10px;margin-bottom:8px}
                .tv-table tr.tv-current{border-color:#9fd9b6}
                .tv-table tr.tv-empty{display:block}
                .tv-table tr.tv-empty td{display:block}
                .tv-table tr:not(.tv-empty) td{padding:0;min-width:0}
                .tv-table td[data-label]::before{content:attr(data-label);display:block;font-size:11px;font-weight:700;color:#6b7c95;text-transform:uppercase;letter-spacing:.03em;margin-bottom:3px}
                .tv-table td.tv-act{align-self:end;display:flex;justify-content:flex-end}
                .tv-table td.tv-act .tv-del{margin:0;flex:0 0 36px}
            </style>

                        <!-- CUSTOM SIZE -->
                        <div class="ap-field">

                            <label for="productSize"
                                   class="ap-label">
                                Custom Size
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-arrows-angle-expand ap-input-icon"></i>

                                <input type="text"
                                       class="ap-input"
                                       id="productSize"
                                       name="size"
                                       placeholder="Enter size"
                                       value="<?php echo old('size'); ?>">

                            </div>
                        </div>

                        <!-- PRODUCT EXPIRY -->
                        <div class="ap-field">

                            <label class="ap-label">
                                Product Expiry
                            </label>

                            <label class="ap-check-box off">

                                <input type="checkbox"
                                       class="ap-checkbox"
                                       name="product_expiry"
                                       id="productExpiry"
                                       value="1"
                                       <?php echo isset($_POST['product_expiry'])
                                           ? 'checked' : ''; ?>>

                                <span>
                                    <span class="ap-check-title d-block">
                                        Disabled
                                    </span>

                                    <span class="ap-check-subtitle">
                                        Control category visibility in the catalog
                                    </span>
                                </span>

                            </label>
                        </div>

                        <!-- MEASUREMENT UNIT -->
                        <div class="ap-field">

                            <label for="measurementUnit"
                                   class="ap-label">
                                Measurement Unit
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="measurementUnit"
                                        name="measurement_unit">

                                    <option value="">
                                        Select unit
                                    </option>

                                    <option value="Sheets">Sheets</option>
                                    <option value="Pieces">Pieces</option>
                                    <option value="Box">Box</option>
                                    <option value="Kg">Kg</option>
                                    <option value="Meter">Meter</option>
                                    <option value="Square Feet">Square Feet</option>

                                </select>

                            </div>
                        </div>

                    </div>

                    <!-- RIGHT COLUMN -->
                    <div class="col-lg-6">

                        <!-- STORE -->
                        <div class="ap-field">

                            <label for="productStore"
                                   class="ap-label">
                                Store
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="productStore"
                                        name="store">

                                    <option value="">
                                        Select store
                                    </option>

                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo htmlspecialchars($store); ?>">
                                            <?php echo htmlspecialchars($store); ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>

                            </div>
                        </div>

                        <!-- CATEGORY -->
                        <div class="ap-field">

                            <label for="mainCategory"
                                   class="ap-label">
                                Category
                            </label>

                            <!-- Step 1: main category, Step 2: its sub-categories -->
                            <div class="ap-input-wrapper">
                                <i class="bi bi-grid-fill ap-input-icon"></i>
                                <select class="ap-select" id="mainCategory">
                                    <option value="">Select category</option>
                                    <?php foreach ($categoryRows as $category): if ($category['depth'] !== 0) continue; ?>
                                        <option value="<?php echo (int)$category['id']; ?>"><?php echo e($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- SUB-CATEGORY -->
                        <div class="ap-field" id="subCategoryField">

                            <label for="subCategory" class="ap-label">
                                Sub-Category
                                <span class="ap-sub-count" id="subCategoryCount"></span>
                            </label>

                            <div class="ap-input-wrapper">
                                <i class="bi bi-diagram-3-fill ap-input-icon"></i>
                                <select class="ap-select" id="subCategory" disabled>
                                    <option value="">Select category first</option>
                                </select>
                            </div>
                            <small class="ap-cat-path" id="categoryPath"></small>

                            <style>
                                .add-product-section .ap-sub-count{font-weight:600;font-size:12px;color:#0f5fcc;background:#e8f1ff;border-radius:10px;padding:1px 8px;margin-left:6px}
                                .add-product-section .ap-sub-count:empty{display:none}
                                .add-product-section .ap-cat-path{display:block;margin-top:6px;font-size:12.5px;color:#0f7a3d}
                                .add-product-section .ap-cat-path:empty{display:none}
                                .add-product-section #subCategory:disabled{background:#f4f6fa;color:#94a3b8}
                            </style>

                            <div class="ap-input-wrapper d-none" id="categoryFallback">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="productCategory"
                                        name="category">

                                    <option value="">
                                        Select category
                                    </option>

                                    <?php foreach ($categoryRows as $category): ?>
                                        <option value="<?php echo e($category['label']); ?>">
                                            <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $category['depth']) . e($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>

                            </div>
                        </div>

                        <!-- PRODUCT TYPE -->
                        <div class="ap-field">

                            <label for="productType"
                                   class="ap-label">
                                Product Type
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="productType"
                                        name="product_type">

                                    <option value="">
                                        Select product type
                                    </option>

                                    <?php foreach ($productTypes as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>">
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>

                                </select>

                            </div>
                        </div>

                        <!-- TAX (GST rate from the Taxes page) -->
                        <div class="ap-field">

                            <label for="productTax" class="ap-label">
                                Tax (GST)
                            </label>

                            <div class="ap-input-wrapper">
                                <i class="bi bi-chevron-down ap-input-icon"></i>
                                <select class="ap-select" id="productTax" name="tax_id">
                                    <option value="" data-rate="0">No tax (0%)</option>
                                    <?php foreach ($gstRates as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" data-rate="<?php echo e($t['rate']); ?>">
                                            <?php echo e($t['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ap-tax-container mt-2" style="padding:12px 14px">
                                <div class="d-flex justify-content-between small py-1">
                                    <span><i class="bi bi-geo-alt-fill text-success"></i> Inside state (<?php echo e(COMPANY_STATE); ?>)</span>
                                    <b id="taxSplitIn">-</b>
                                </div>
                                <div class="d-flex justify-content-between small py-1">
                                    <span><i class="bi bi-truck text-primary"></i> Outside state</span>
                                    <b id="taxSplitOut">-</b>
                                </div>
                            </div>
                            <div class="form-text">
                                Rates come from the <a href="taxes.php">Taxes</a> page. Quotations and invoices use this rate automatically.
                            </div>
                        </div>

                    </div>

                </div>

                <!-- FULL WIDTH DESCRIPTION -->
                <div class="row mt-2">

                    <div class="col-12">

                        <div class="ap-field">

                            <label for="productDescription"
                                   class="ap-label">
                                Description
                            </label>

                            <div class="ap-textarea-wrapper">

                                <i class="bi bi-card-text ap-textarea-icon"></i>

                                <textarea
                                    class="ap-textarea"
                                    id="productDescription"
                                    name="description"
                                    placeholder="Enter description"
                                    rows="4"><?php echo old('description'); ?></textarea>

                            </div>

                        </div>

                    </div>

                    <!-- ADDITIONAL INFORMATION -->
                    <div class="col-12">

                        <div class="ap-field mb-0">

                            <label for="additionalInfo"
                                   class="ap-label">
                                Additional Information
                            </label>

                            <div class="ap-textarea-wrapper">

                                <i class="bi bi-info-circle ap-textarea-icon"></i>

                                <textarea
                                    class="ap-textarea"
                                    id="additionalInfo"
                                    name="additional_info"
                                    placeholder="Enter additional information"
                                    rows="4"><?php echo old('additional_info'); ?></textarea>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


                    <div class="ap-card-body pt-0 tv-body" id="tvBody" hidden>
                <div class="tv-panel">
                    <div class="tv-panel-head">
                        <span class="tv-panel-icon"><i class="bi bi-rulers"></i></span>
                        <div>
                            <h3>Thicknesses</h3>
                            <p>One product is saved for each thickness below.</p>
                        </div>
                        <span class="tv-panel-count" id="tvCount"></span>
                    </div>
                        <div class="tv-entry">
                            <div class="tv-chips" id="tvChips">
                                <input type="text" id="tvInput" placeholder="Type thickness and press Enter (or 6, 9, 12, 18, 19)">
                            </div>
                            <select id="tvUnit" class="tv-unit" title="Unit added to each thickness">
                                <option value="mm">mm</option>
                                <option value="inch">inch</option>
                                <option value="ft">ft</option>
                                <option value="">no unit</option>
                            </select>
                            <button type="button" class="tv-add" id="tvAddBtn"><i class="bi bi-plus-lg"></i> Add</button>
                        </div>
                        <div class="tv-quick">
                            Quick:
                            <button type="button" data-list="4,6,9,12,16,18,19,25">Plywood (4&ndash;25 mm)</button>
                            <button type="button" data-list="6,9,12,18,19">6, 9, 12, 18, 19 mm</button>
                            <button type="button" data-list="8,12,18,25">Block board</button>
                            <button type="button" id="tvClear" class="tv-clear">Clear all</button>
                        </div>

                        <div class="table-responsive">
                            <table class="tv-table">
                                <thead>
                                    <tr>
                                        <th style="width:22%">Thickness</th>
                                        <th>Product Name</th>
                                        <th style="width:20%">Price</th>
                                        <th style="width:20%">Stock</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody id="tvRows"></tbody>
                            </table>
                        </div>
                        <p class="tv-note" id="tvNote">Category, GST, HSN, size, unit and store above are copied to every thickness. <?php echo $editing ? "Empty price uses the price above; new thicknesses start with 0 stock unless you type it." : "Empty price / stock uses the values above."; ?></p>
                    </div>
                </div>

            <!-- FORM FOOTER -->
            <div class="ap-card-footer">

                <a href="products.php"
                   class="ap-cancel-btn">

                    <i class="bi bi-x-lg"></i>
                    Cancel

                </a>

                <button type="submit"
                        class="ap-save-btn"
                        id="saveProductBtn">

                    <i class="bi bi-floppy"></i>
                    Save Product

                </button>

            </div>

        </div>

    </form>

</div>

</section>

<!-- BOOTSTRAP JAVASCRIPT -->

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Pre-fill selects and checkboxes (edit mode / after validation error).
    const formValues = <?php echo json_encode((object)$formValues, json_flags()); ?>;
    const productForm = document.getElementById("addProductForm");

    ["store", "category", "product_type", "measurement_unit"].forEach(field => {
        const select = productForm.elements[field];
        if (!select || !formValues[field]) return;
        // Keep values saved earlier (e.g. old store/type names) selectable when editing.
        if (![...select.options].some(o => o.value === formValues[field])) {
            select.add(new Option(formValues[field], formValues[field]));
        }
        select.value = formValues[field];
    });

    // Custom Thickness dropdown: every sheet already saved in the chosen (sub-)category,
    // e.g. 6 mm / 8 mm / 12 mm, with its price and available quantity.
    const sheets = <?php echo json_encode($sheetRows, json_flags()); ?>;
    const editId = <?php echo (int)$editId; ?>;
    const thkPick = document.getElementById("thicknessPick");
    const thkInfo = document.getElementById("thicknessInfo");
    const thkInput = document.getElementById("thickness");
    const thkCount = document.getElementById("thicknessCount");
    const money = v => "₹" + Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = v => Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
    const thkNum = t => { const m = String(t || "").match(/\d+(\.\d+)?/); return m ? parseFloat(m[0]) : Infinity; };
    let shownSheets = [];

    function renderSheets(cat, allCats) {
        thkInfo.hidden = true;
        thkPick.innerHTML = "";
        if (!cat) {
            thkPick.add(new Option("Select sub-category first", ""));
            thkPick.disabled = true;
            thkCount.textContent = "";
            shownSheets = [];
            return;
        }
        // The chosen category and everything under it.
        const ids = new Set(allCats.filter(c => c.label === cat.label || c.label.startsWith(cat.label + " › ")).map(c => c.id));
        shownSheets = sheets
            .filter(s => s.category_id !== null && ids.has(s.category_id) && s.id !== editId)
            .sort((a, b) => thkNum(a.thickness) - thkNum(b.thickness) || a.name.localeCompare(b.name));
        thkPick.disabled = false;
        thkCount.textContent = shownSheets.length ? shownSheets.length + " sheet" + (shownSheets.length === 1 ? "" : "s") : "";
        thkPick.add(new Option(shownSheets.length
            ? "Select thickness (" + shownSheets.length + " saved in " + cat.name + ")"
            : "No sheets saved in " + cat.name + " yet", ""));
        shownSheets.forEach(s => {
            const label = (s.thickness || "No thickness") + "  —  " + money(s.price) + "  ·  " + qty(s.stock) + " available" + (s.enabled ? "" : "  (disabled)");
            thkPick.add(new Option(label, s.id));
        });
        thkPick.add(new Option("+ New thickness (type below)", "new"));
        // Editing: point at the matching thickness if another sheet has it.
        const cur = thkInput.value.trim().toLowerCase();
        const same = cur && shownSheets.find(s => s.thickness.trim().toLowerCase() === cur);
        if (same) { thkPick.value = String(same.id); showSheet(same); }
    }

    function showSheet(s) {
        const low = s.stock <= 0;
        thkInfo.innerHTML = `
            <div class="thk-name"><i class="bi bi-layers-fill text-primary"></i>${App.esc(s.name)}</div>
            <div class="thk-stats">
                <div class="thk-stat"><span>Thickness</span><strong>${App.esc(s.thickness || "-")}</strong></div>
                <div class="thk-stat"><span>Price</span><strong>${money(s.price)}</strong></div>
                <div class="thk-stat ${low ? "low" : "ok"}"><span>Available Qty</span><strong>${qty(s.stock)}</strong></div>
            </div>
            <div class="thk-actions">
                <a href="add-product.php?id=${s.id}"><i class="bi bi-pencil-square"></i> Edit this sheet</a>
                <button type="button" data-use="price"><i class="bi bi-currency-rupee"></i> Use this price</button>
            </div>
            ${editId ? "" : '<div class="thk-warn"><i class="bi bi-info-circle"></i> This thickness is already saved. To change its price or stock use “Edit this sheet”; saving here creates another product.</div>'}`;
        thkInfo.querySelector('[data-use="price"]').onclick = () => {
            const p = document.getElementById("productPrice");
            p.value = s.price;
            p.dispatchEvent(new Event("input"));
            App.toast("Price " + money(s.price) + " filled.");
        };
        thkInfo.hidden = false;
    }

    thkPick.addEventListener("change", () => {
        const s = shownSheets.find(x => String(x.id) === thkPick.value);
        thkInfo.hidden = true;
        if (s) {
            showSheet(s);
            thkInput.value = s.thickness;
        } else if (thkPick.value === "new") {
            thkInput.value = "";
            if (!thkInput.disabled) thkInput.focus();
        }
    });

    // Category -> Sub-Category: pick the main category, then one of its sub-categories.
    // The hidden "category" select keeps the full label (e.g. "Plywood › Marine Plywood") that is saved.
    (function () {
        const tree = <?php echo json_encode(array_map(fn($c) => [
            'id' => $c['id'], 'name' => $c['name'], 'depth' => $c['depth'], 'label' => $c['label'],
        ], $categoryRows), json_flags()); ?>;
        const mainSel = document.getElementById("mainCategory");
        const subSel = document.getElementById("subCategory");
        const hidden = document.getElementById("productCategory");
        const path = document.getElementById("categoryPath");
        const count = document.getElementById("subCategoryCount");
        const nameInput = document.getElementById("productName");

        // Main category of each row + its descendants (tree is in display order).
        let currentMain = null;
        tree.forEach(c => { if (c.depth === 0) currentMain = c.id; c.main = currentMain; });
        const byId = Object.fromEntries(tree.map(c => [c.id, c]));

        function fillSubs(mainId, selectedId) {
            const subs = tree.filter(c => c.main === mainId && c.depth > 0);
            subSel.innerHTML = "";
            if (!mainId) {
                subSel.add(new Option("Select category first", ""));
                subSel.disabled = true;
                count.textContent = "";
                return;
            }
            subSel.disabled = false;
            subSel.add(new Option(subs.length ? "Select sub-category" : "No sub-categories (main category will be used)", ""));
            subs.forEach(c => {
                const o = new Option("  ".repeat(c.depth - 1) + c.name, c.id);
                subSel.add(o);
            });
            if (!subs.length) subSel.disabled = true;
            count.textContent = subs.length ? subs.length + " available" : "";
            subSel.value = selectedId ? String(selectedId) : "";
        }

        function sync() {
            const chosen = byId[subSel.value] || byId[mainSel.value];
            hidden.value = chosen ? chosen.label : "";
            path.innerHTML = chosen ? '<i class="bi bi-check-circle-fill"></i> ' + App.esc(chosen.label) : "";
            renderSheets(chosen || null, tree);
        }

        mainSel.addEventListener("change", () => {
            fillSubs(Number(mainSel.value) || null, null);
            sync();
            if (!subSel.disabled) subSel.focus();
        });
        // Product name follows the chosen sub-category until the user types their own name.
        let autoFilledName = "";
        function applyName() {
            const c = byId[subSel.value] || byId[mainSel.value];
            const current = nameInput.value.trim();
            if (current !== "" && current !== autoFilledName) return; // typed by hand: keep it
            const next = c ? c.name : "";
            if (next === current) return;
            nameInput.value = next;
            autoFilledName = next;
            nameInput.dispatchEvent(new Event("input")); // thickness rows rename too
        }
        mainSel.addEventListener("change", applyName);
        subSel.addEventListener("change", () => {
            sync();
            applyName();
        });

        // Pre-fill (edit / after error) from the saved label.
        const saved = tree.find(c => c.label === hidden.value);
        if (saved) {
            mainSel.value = String(saved.main);
            fillSubs(saved.main, saved.depth > 0 ? saved.id : null);
            sync();
        } else if (hidden.value) {
            // Old category that no longer exists in the tree: keep the plain dropdown.
            document.getElementById("categoryFallback").classList.remove("d-none");
            fillSubs(null);
        } else {
            fillSubs(null);
        }
    })();

    // GST: pre-select and show how it splits inside / outside the state.
    const taxSelect = document.getElementById("productTax");
    if (formValues.tax_id) taxSelect.value = formValues.tax_id;
    function showTaxSplit() {
        const rate = Number(taxSelect.selectedOptions[0]?.dataset.rate || 0);
        const p = v => (Math.round(v * 100) / 100).toString();
        document.getElementById("taxSplitIn").textContent = `CGST ${p(rate / 2)}% + SGST ${p(rate / 2)}%`;
        document.getElementById("taxSplitOut").textContent = `IGST ${p(rate)}%`;
    }
    taxSelect.addEventListener("change", showTaxSplit);
    showTaxSplit();

    if ("enabled" in formValues) {
        document.getElementById("productEnabled").checked = !!formValues.enabled;
    }
    if ("product_expiry" in formValues) {
        document.getElementById("productExpiry").checked = !!formValues.product_expiry;
        document.getElementById("productExpiry").dispatchEvent(new Event("change"));
    }

    const productEnabled =
        document.getElementById("productEnabled");

    const enabledBox =
        document.getElementById("enabledBox");

    const enabledTitle =
        document.getElementById("enabledTitle");

    const enabledDescription =
        document.getElementById("enabledDescription");

    function updateEnabledStatus() {

        if (productEnabled.checked) {

            enabledTitle.textContent = "Enabled";
            enabledDescription.textContent =
                "Control category visibility in the catalog";

            enabledBox.classList.remove("off");

        } else {

            enabledTitle.textContent = "Disabled";
            enabledDescription.textContent =
                "Product will be hidden from the catalog";

            enabledBox.classList.add("off");
        }
    }

    productEnabled.addEventListener(
        "change",
        updateEnabledStatus
    );

    updateEnabledStatus();

    // Expiry checkbox label.
    const expiryCheckbox =
        document.getElementById("productExpiry");

    expiryCheckbox.addEventListener("change", function () {

        const container = this.closest(".ap-check-box");
        const title = container.querySelector(".ap-check-title");

        title.textContent = this.checked
            ? "Enabled"
            : "Disabled";

        container.classList.toggle("off", !this.checked);
    });

    // Multiple thicknesses: one product row per thickness.
    const useVariants = document.getElementById("useVariants");
    if (useVariants) {
        const tvBody = document.getElementById("tvBody");
        const tvRows = document.getElementById("tvRows");
        const tvInput = document.getElementById("tvInput");
        const tvChips = document.getElementById("tvChips");
        const tvUnit = document.getElementById("tvUnit");
        const nameInput = document.getElementById("productName");
        const priceInput = document.getElementById("productPrice");
        const stockInput = document.getElementById("productStock");
        const hsnInput = document.getElementById("hsnCode");
        const thicknessInput = document.getElementById("thickness");
        const saveLabel = document.getElementById("saveProductBtn");
        const esc = s => String(s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
        const isEdit = <?php echo $editing ? "true" : "false"; ?>;
        const autoName = t => ((nameInput.value.trim() || "Product") + " " + t).trim();

        function parseList(text) {
            const unit = tvUnit.value;
            return text.split(/[,;\n]+|\s+(?=\d)/)
                .map(s => s.trim())
                .filter(s => s && !/^(mm|inch|in|ft)$/i.test(s))
                .map(s => {
                    const m = s.match(/^(\d+(?:\.\d+)?)\s*(mm|inch|in|ft)?$/i);
                    if (!m) return s;
                    const u = m[2] ? (m[2].toLowerCase() === "in" ? "inch" : m[2].toLowerCase()) : unit;
                    return u ? `${m[1]} ${u}` : m[1];
                });
        }

        function existing() {
            return [...tvRows.querySelectorAll(".tv-thk")].map(i => i.value.trim().toLowerCase());
        }

        function addRow(v = {}) {
            tvRows.querySelector(".tv-empty")?.remove();
            const tr = document.createElement("tr");
            const auto = !v.name || v.name === autoName(v.thickness || "");
            const current = !!(isEdit && v.current);
            // While editing, new thicknesses start at 0 stock (server does the same).
            const stockHint = isEdit && !current ? "0" : (stockInput.value || "0");
            tr.innerHTML = `
                <td data-label="Thickness"><input type="hidden" name="variant_current[]" value="${current ? "1" : "0"}">
                    <input class="tv-thk" name="variant_thickness[]" value="${esc(v.thickness || "")}" required></td>
                <td data-label="Product name"><input class="tv-name" name="variant_name[]" value="${esc(v.name || autoName(v.thickness || ""))}" maxlength="200">
                    ${current ? '<span class="tv-badge">This product &middot; updated</span>' : (isEdit ? '<span class="tv-badge new">New product</span>' : "")}</td>
                <td data-label="HSN Code"><input class="tv-hsn" name="variant_hsn[]" maxlength="50" value="${esc(v.hsn || "")}" placeholder="${esc(hsnInput.value || "HSN")}"></td>
                <td data-label="Price"><input type="number" name="variant_price[]" min="0" step="0.01" value="${esc(v.price || "")}" placeholder="${esc(priceInput.value || "0")}"></td>
                <td data-label="Stock"><input type="number" name="variant_stock[]" min="0" step="0.01" value="${esc(v.stock || "")}" placeholder="${esc(stockHint)}"></td>
                <td class="tv-act">${current ? "" : '<button type="button" class="tv-del" title="Remove"><i class="bi bi-trash"></i></button>'}</td>`;
            tr.dataset.auto = auto ? "1" : "";
            if (current) tr.classList.add("tv-current");
            tvRows.appendChild(tr);
            refresh();
        }

        function addFromInput() {
            const have = existing();
            parseList(tvInput.value).forEach(t => {
                if (have.includes(t.toLowerCase())) return;
                have.push(t.toLowerCase());
                addRow({ thickness: t });
            });
            tvInput.value = "";
        }

        function refresh() {
            const rows = tvRows.querySelectorAll("tr:not(.tv-empty)");
            if (!rows.length) {
                tvRows.innerHTML = '<tr class="tv-empty"><td colspan="5">No thickness added yet. Type 6, 9, 12, 18, 19 above and press Enter.</td></tr>';
            }
            // Chips mirror the table rows.
            tvChips.querySelectorAll(".tv-chip").forEach(c => c.remove());
            rows.forEach((tr, i) => {
                const val = tr.querySelector(".tv-thk").value.trim();
                if (!val) return;
                const chip = document.createElement("span");
                chip.className = "tv-chip";
                if (tr.classList.contains("tv-current")) {
                    chip.innerHTML = `${esc(val)} <i class="bi bi-pencil-fill" title="This product"></i>`;
                } else {
                    chip.innerHTML = `${esc(val)}<button type="button" title="Remove">&times;</button>`;
                    chip.querySelector("button").onclick = () => { tr.remove(); refresh(); };
                }
                tvChips.insertBefore(chip, tvInput);
            });
            const n = rows.length;
            document.getElementById("tvCount").textContent = n ? n + " thickness" + (n === 1 ? "" : "es") : "";
            const added = n - tvRows.querySelectorAll("tr.tv-current").length;
            if (useVariants.checked && isEdit) {
                saveLabel.innerHTML = `<i class="bi bi-floppy"></i> Update${added ? ` + Add ${added} New` : " Product"}`;
            } else if (useVariants.checked) {
                saveLabel.innerHTML = `<i class="bi bi-floppy"></i> Save ${n} Product${n === 1 ? "" : "s"}`;
            } else {
                saveLabel.innerHTML = '<i class="bi bi-floppy"></i> Save Product';
            }
        }

        function toggle() {
            const on = useVariants.checked;
            tvBody.hidden = !on;
            document.getElementById("tvHint").hidden = !on;
            document.getElementById("tvBox").style.borderStyle = on ? "solid" : "dashed";
            thicknessInput.disabled = on;
            thicknessInput.placeholder = on ? "Set per thickness at the bottom" : "Enter thickness";
            tvRows.querySelectorAll("input").forEach(i => i.disabled = !on);
            // Editing: start with this product as row 1; a typed list like "6, 9, 12 mm" becomes extra rows.
            if (on && isEdit && !tvRows.querySelector("tr.tv-current")) {
                const list = parseList(thicknessInput.value || "");
                const first = list.shift() || "";
                const base = nameInput.value.trim();
                const hasThk = first && base.toLowerCase().includes(first.toLowerCase());
                tvRows.querySelector(".tv-empty")?.remove();
                const keep = [...tvRows.children];
                addRow({ current: true, thickness: first, name: hasThk || !first ? base : autoName(first) });
                keep.forEach(tr => tvRows.appendChild(tr));
                const have = existing();
                list.forEach(t => { if (!have.includes(t.toLowerCase())) { have.push(t.toLowerCase()); addRow({ thickness: t }); } });
                tvRows.querySelectorAll("tr.tv-current input").forEach(i => i.disabled = false);
            }
            refresh();
            if (on && !tvRows.querySelector("tr:not(.tv-empty)")) tvInput.focus({ preventScroll: true });
        }

        tvInput.addEventListener("keydown", e => {
            if (e.key === "Enter" || e.key === ",") { e.preventDefault(); addFromInput(); }
            else if (e.key === "Backspace" && !tvInput.value) {
                const last = [...tvRows.querySelectorAll("tr:not(.tv-empty):not(.tv-current)")].pop();
                if (last) { last.remove(); refresh(); }
            }
        });
        tvInput.addEventListener("paste", () => setTimeout(addFromInput, 0));
        tvInput.addEventListener("blur", () => { if (tvInput.value.trim()) addFromInput(); });
        document.getElementById("tvAddBtn").onclick = addFromInput;
        document.querySelectorAll(".tv-quick [data-list]").forEach(b => b.onclick = () => {
            tvInput.value = b.dataset.list; addFromInput();
        });
        document.getElementById("tvClear").onclick = () => {
            tvRows.querySelectorAll("tr:not(.tv-current)").forEach(tr => tr.remove());
            refresh();
        };

        tvRows.addEventListener("click", e => {
            if (e.target.closest(".tv-del")) { e.target.closest("tr").remove(); refresh(); }
        });
        tvRows.addEventListener("input", e => {
            const tr = e.target.closest("tr");
            if (e.target.classList.contains("tv-name")) tr.dataset.auto = "";
            if (e.target.classList.contains("tv-thk")) {
                if (tr.dataset.auto) tr.querySelector(".tv-name").value = autoName(e.target.value.trim());
                refresh();
            }
        });
        // Names follow the base product name until edited by hand.
        nameInput.addEventListener("input", () => {
            tvRows.querySelectorAll("tr:not(.tv-empty)").forEach(tr => {
                if (tr.dataset.auto) tr.querySelector(".tv-name").value = autoName(tr.querySelector(".tv-thk").value.trim());
            });
        });
        // Empty HSN boxes show (and use) the HSN Code above.
        hsnInput.addEventListener("input", () => {
            tvRows.querySelectorAll(".tv-hsn").forEach(i => i.placeholder = hsnInput.value || "HSN");
        });
        [priceInput, stockInput].forEach((inp, k) => inp.addEventListener("input", () => {
            tvRows.querySelectorAll("tr:not(.tv-empty)").forEach(tr => {
                tr.querySelectorAll('input[type="number"]')[k].placeholder = inp.value || "0";
            });
        }));
        useVariants.addEventListener("change", () => {
            toggle();
            if (useVariants.checked) tvBody.scrollIntoView({ behavior: "smooth", block: "start" });
        });

        // Restore after a validation error.
        (formValues.variants || []).forEach(v => addRow(v));
        useVariants.checked = !!formValues.use_variants;
        toggle();
    }

    // Prevent duplicate form submissions.
    const form =
        document.getElementById("addProductForm");

    const saveBtn =
        document.getElementById("saveProductBtn");

    form.addEventListener("submit", function(event) {

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        if (useVariants && useVariants.checked &&
            !document.querySelector("#tvRows tr:not(.tv-empty)")) {
            event.preventDefault();
            App.toast("Add at least one thickness, or switch off \"Add multiple thicknesses\".", "error");
            document.getElementById("tvInput").focus();
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Saving...';
    });

});
</script>

<?php include 'footer.php';