<?php
/*
 * ADD / EDIT PRODUCT - saved in MySQL `products` table.
 * add-product.php        -> new product
 * add-product.php?id=5   -> edit product #5
 */
require_once __DIR__ . '/includes/init.php';

$stores = [
    APP_STORE . ' Godown',
    'Main Store',
    'Warehouse'
];

$categoryRows = db_all('SELECT id, name FROM categories WHERE enabled = 1 ORDER BY sort_order, name');
$categories = array_column($categoryRows, 'name');

$productTypes = [
    'Simple Product',
    'Variable Product',
    'Service'
];

$taxes = [];
$taxRates = [];
foreach (db_all('SELECT name, code, amount FROM taxes WHERE enabled = 1 ORDER BY id') as $t) {
    $taxes[$t['code']] = $t['name'];
    $taxRates[$t['code']] = (float)$t['amount'];
}

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
    $discountValue = trim($_POST['discount_value'] ?? '');
    $stock = trim($_POST['stock'] ?? '');

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }

    if ($price !== '' &&
        (!is_numeric($price) || (float)$price < 0)) {
        $errors[] = 'Enter a valid product price.';
    }

    if ($discountValue !== '' &&
        (!is_numeric($discountValue) || (float)$discountValue < 0)) {
        $errors[] = 'Enter a valid discount value.';
    }

    if ($stock !== '' &&
        (!is_numeric($stock) || (float)$stock < 0)) {
        $errors[] = 'Enter a valid stock quantity.';
    }

    $allowedTaxKeys = array_keys($taxes);

    $selectedTaxes = array_values(array_intersect(
        $allowedTaxKeys,
        (array)($_POST['taxes'] ?? [])
    ));

    $selectedDefaultTaxes = array_values(array_intersect(
        $allowedTaxKeys,
        (array)($_POST['default_taxes'] ?? [])
    ));

    // Total GST rate used by quotations / orders.
    $taxRate = 0;
    foreach ($selectedDefaultTaxes ?: $selectedTaxes as $code) {
        $taxRate += $taxRates[$code] ?? 0;
    }

    if (empty($errors)) {

        $categoryName = trim($_POST['category'] ?? '');
        $categoryId = null;
        foreach ($categoryRows as $c) {
            if ($c['name'] === $categoryName) {
                $categoryId = (int)$c['id'];
            }
        }

        $product = [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'store' => trim($_POST['store'] ?? '') ?: null,
            'name' => mb_substr($name, 0, 200),
            'category_id' => $categoryId,
            'category' => $categoryName ?: null,
            'discount_type' => trim($_POST['discount_type'] ?? '') ?: null,
            'product_type' => trim($_POST['product_type'] ?? '') ?: null,
            'price' => $price === '' ? 0 : (float)$price,
            'discount_value' => $discountValue === '' ? 0 : (float)$discountValue,
            'stock' => $stock === '' ? 0 : (float)$stock,
            'hsn_code' => trim($_POST['hsn_code'] ?? '') ?: null,
            'thickness' => trim($_POST['thickness'] ?? '') ?: null,
            'size' => trim($_POST['size'] ?? '') ?: null,
            'product_expiry' => isset($_POST['product_expiry']) ? 1 : 0,
            'measurement_unit' => trim($_POST['measurement_unit'] ?? '') ?: null,
            'taxes' => json_encode($selectedTaxes),
            'default_taxes' => json_encode($selectedDefaultTaxes),
            'tax_rate' => $taxRate,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'additional_info' => trim($_POST['additional_info'] ?? '') ?: null
        ];

        if ($editing) {
            db_update('products', $product, $editId);
            log_activity('Products', 'Updated product ' . $product['name']);
        } else {
            db_insert('products', $product);
            log_activity('Products', 'Added product ' . $product['name']);
        }

        header('Location: products.php?saved=1');
        exit;
    }
}

/* Values for JS to pre-fill selects / checkboxes. */
$formValues = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = [
        'store' => $_POST['store'] ?? '',
        'category' => $_POST['category'] ?? '',
        'product_type' => $_POST['product_type'] ?? '',
        'discount_type' => $_POST['discount_type'] ?? '',
        'measurement_unit' => $_POST['measurement_unit'] ?? '',
        'taxes' => (array)($_POST['taxes'] ?? []),
        'default_taxes' => (array)($_POST['default_taxes'] ?? []),
        'enabled' => isset($_POST['enabled']),
        'product_expiry' => isset($_POST['product_expiry']),
    ];
} elseif ($editing) {
    $formValues = [
        'store' => $editing['store'],
        'category' => $editing['category'],
        'product_type' => $editing['product_type'],
        'discount_type' => $editing['discount_type'],
        'measurement_unit' => $editing['measurement_unit'],
        'taxes' => json_decode($editing['taxes'] ?? '[]', true) ?: [],
        'default_taxes' => json_decode($editing['default_taxes'] ?? '[]', true) ?: [],
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

                        <!-- DISCOUNT TYPE -->
                        <div class="ap-field">

                            <label for="discountType"
                                   class="ap-label">
                                Discount Type
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="discountType"
                                        name="discount_type">

                                    <option value="">
                                        Select discount type
                                    </option>

                                    <option value="percentage">
                                        Percentage (%)
                                    </option>

                                    <option value="fixed">
                                        Fixed Amount
                                    </option>

                                </select>

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

                        <!-- DISCOUNT VALUE -->
                        <div class="ap-field">

                            <label for="discountValue"
                                   class="ap-label">
                                Discount Value
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-percent ap-input-icon"></i>

                                <input type="number"
                                       class="ap-input"
                                       id="discountValue"
                                       name="discount_value"
                                       min="0"
                                       step="0.01"
                                       placeholder="Enter discount value"
                                       value="<?php echo old('discount_value'); ?>">

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

                        <!-- CUSTOM THICKNESS -->
                        <div class="ap-field">

                            <label for="thickness"
                                   class="ap-label">
                                Custom Thickness
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-rulers ap-input-icon"></i>

                                <input type="text"
                                       class="ap-input"
                                       id="thickness"
                                       name="thickness"
                                       placeholder="Enter thickness"
                                       value="<?php echo old('thickness'); ?>">

                            </div>
                        </div>

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

                            <label for="productCategory"
                                   class="ap-label">
                                Category
                            </label>

                            <div class="ap-input-wrapper">

                                <i class="bi bi-chevron-down ap-input-icon"></i>

                                <select class="ap-select"
                                        id="productCategory"
                                        name="category">

                                    <option value="">
                                        Select category
                                    </option>

                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>">
                                            <?php echo htmlspecialchars($category); ?>
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

                        <!-- TAXES -->
                        <div class="ap-field">

                            <label class="ap-label">
                                Taxes
                            </label>

                            <div class="ap-tax-container">

                                <div class="ap-tax-heading">
                                    Select one or more taxes.
                                </div>

                                <?php foreach ($taxes as $taxKey => $taxName): ?>

                                <label class="ap-tax-item">

                                    <input type="checkbox"
                                           class="ap-tax-check"
                                           name="taxes[]"
                                           value="<?php echo htmlspecialchars($taxKey); ?>">

                                    <span>
                                        <span class="ap-tax-name d-block">
                                            <?php echo htmlspecialchars($taxName); ?>
                                        </span>

                                        <span class="ap-tax-type d-block">
                                            <?php echo str_contains($taxName, 'Integrated')
                                                ? 'Integrated'
                                                : (str_contains($taxName, 'Central')
                                                    ? 'Central'
                                                    : 'Tax'); ?>
                                        </span>
                                    </span>

                                </label>

                                <?php endforeach; ?>

                            </div>
                        </div>

                        <!-- DEFAULT TAXES -->
                        <div class="ap-field">

                            <label class="ap-label">
                                Default Taxes
                            </label>

                            <div class="ap-tax-container">

                                <div class="ap-tax-heading">
                                    Select one or more default taxes.
                                </div>

                                <?php foreach ($taxes as $taxKey => $taxName): ?>

                                <label class="ap-tax-item">

                                    <input type="checkbox"
                                           class="ap-tax-check"
                                           name="default_taxes[]"
                                           value="<?php echo htmlspecialchars($taxKey); ?>">

                                    <span>
                                        <span class="ap-tax-name d-block">
                                            <?php echo htmlspecialchars($taxName); ?>
                                        </span>

                                        <span class="ap-tax-type d-block">
                                            <?php echo str_contains($taxName, 'Integrated')
                                                ? 'Integrated'
                                                : (str_contains($taxName, 'Central')
                                                    ? 'Central'
                                                    : 'Tax'); ?>
                                        </span>
                                    </span>

                                </label>

                                <?php endforeach; ?>

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

    ["store", "category", "product_type", "discount_type", "measurement_unit"].forEach(field => {
        const select = productForm.elements[field];
        if (select && formValues[field]) select.value = formValues[field];
    });

    ["taxes", "default_taxes"].forEach(field => {
        if (!Array.isArray(formValues[field])) return;
        productForm.querySelectorAll(`input[name="${field}[]"]`).forEach(box => {
            box.checked = formValues[field].includes(box.value);
        });
    });

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

    // Discount validation.
    const discountType =
        document.getElementById("discountType");

    const discountValue =
        document.getElementById("discountValue");

    function validateDiscount() {

        const value = Number(discountValue.value);

        if (discountType.value === "percentage" &&
            discountValue.value !== "" && value > 100) {

            discountValue.setCustomValidity(
                "Percentage discount cannot exceed 100."
            );

        } else {

            discountValue.setCustomValidity("");
        }
    }

    discountType.addEventListener(
        "change",
        validateDiscount
    );

    discountValue.addEventListener(
        "input",
        validateDiscount
    );

    // Prevent duplicate form submissions.
    const form =
        document.getElementById("addProductForm");

    const saveBtn =
        document.getElementById("saveProductBtn");

    form.addEventListener("submit", function(event) {

        validateDiscount();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Saving...';
    });

});
</script>

<?php include 'footer.php';