<?php
/*
|--------------------------------------------------------------------------
| POS PAGE - QUOTATIONS + ORDERS (shared)
|--------------------------------------------------------------------------
| add-quotation.php -> saves into `quotations` + `quotation_items`
| add-order.php     -> saves into `orders` + `order_items`,
|                      creates an invoice and reduces product stock.
| add-order.php?quotation=ID -> converts a saved quotation into an order.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$posType = (isset($posType) && $posType === 'order') ? 'order' : 'quotation';
$isOrder = $posType === 'order';

$L = $isOrder ? [
    'title' => 'Order (POS)',
    'subtitle' => 'Create new sales order and invoice for your customer',
    'new' => 'New Order',
    'list' => 'Orders',
    'listUrl' => 'orders.php',
    'date' => 'Order Date',
    'valid' => 'Due Date',
    'details' => 'Order Details',
    'summary' => 'Order Summary',
    'save' => 'Save Order & Invoice',
] : [
    'title' => 'Quotation (POS)',
    'subtitle' => 'Create new quotation for your customer',
    'new' => 'New Quotation',
    'list' => 'Quotations',
    'listUrl' => 'quotation.php',
    'date' => 'Quotation Date',
    'valid' => 'Valid Upto',
    'details' => 'Quotation Details',
    'summary' => 'Quotation Summary',
    'save' => 'Save Quotation',
];

$customers = db_all("SELECT id, name, mobile FROM customers WHERE status = 'active' ORDER BY name");

$products = [];
foreach (db_all('SELECT id, name, thickness, size, hsn_code, price, tax_rate, stock FROM products WHERE enabled = 1 ORDER BY name') as $p) {
    $products[] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'thickness' => $p['thickness'] ?? '',
        'size' => $p['size'] ?? '',
        'hsn' => $p['hsn_code'] ?? '',
        'rate' => (float)$p['price'],
        'tax' => (float)$p['tax_rate'],
        'stock' => (float)$p['stock'],
    ];
}

$salesmen = array_column(db_all('SELECT name FROM users WHERE enabled = 1 ORDER BY name'), 'name');
$currentSalesman = $_SESSION['user_name'] ?? ($salesmen[0] ?? '');

$today = date('Y-m-d');
$validUpto = date('Y-m-d', strtotime('+30 days'));

/* Convert quotation -> order: pre-fill items */
$prefill = null;
if ($isOrder && !empty($_GET['quotation'])) {
    $q = db_one('SELECT * FROM quotations WHERE id = ?', [(int)$_GET['quotation']]);
    if ($q) {
        $prefill = [
            'quotation_id' => (int)$q['id'],
            'quotation_number' => $q['quotation_id'],
            'customer_id' => (int)$q['customer_id'],
            'reference' => $q['quotation_id'],
            'region' => $q['region'],
            'notes' => $q['notes'],
            'items' => array_map(function ($i) {
                return [
                    'product_id' => (int)$i['product_id'],
                    'name' => $i['name'],
                    'thickness' => $i['thickness'],
                    'size' => $i['size'],
                    'hsn' => $i['hsn'],
                    'quantity' => (float)$i['quantity'],
                    'rate' => (float)$i['rate'],
                    'tax' => (float)$i['tax'],
                    'discount' => (float)$i['discount'],
                    'amount' => (float)$i['amount'],
                ];
            }, db_all('SELECT * FROM quotation_items WHERE quotation_id = ?', [(int)$q['id']])),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $payload = json_decode($_POST['quotation_data'] ?? '', true);

    if (!is_array($payload)) {
        json_response(['success' => false, 'message' => 'Invalid data.'], 422);
    }

    $customerId = (int)($payload['customer_id'] ?? 0);
    $customer = db_one('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
    $date = $payload['date'] ?? '';
    $valid = $payload['valid_upto'] ?? '';
    $items = $payload['items'] ?? [];

    if (!$customer) {
        json_response(['success' => false, 'message' => 'Please select a customer. Add customers in the Customers page first.'], 422);
    }

    if (
        !is_array($items) ||
        count($items) === 0 ||
        count($items) > 100 ||
        !DateTimeImmutable::createFromFormat('!Y-m-d', $date) ||
        !DateTimeImmutable::createFromFormat('!Y-m-d', $valid) ||
        $valid < $date
    ) {
        json_response(['success' => false, 'message' => 'Check customer, dates and products.'], 422);
    }

    $productMap = array_column($products, null, 'id');
    $savedItems = [];
    $subtotal = 0;
    $discountTotal = 0;
    $taxTotal = 0;

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = $item['quantity'] ?? null;
        $rate = $item['rate'] ?? null;
        $tax = $item['tax'] ?? null;
        $discount = $item['discount'] ?? null;

        if (
            !isset($productMap[$productId]) ||
            !is_numeric($qty) || !is_numeric($rate) ||
            !is_numeric($tax) || !is_numeric($discount) ||
            (float)$qty <= 0 || (float)$qty > 1000000 ||
            (float)$rate < 0 || (float)$rate > 100000000 ||
            (float)$tax < 0 || (float)$tax > 100 ||
            (float)$discount < 0 || (float)$discount > 100
        ) {
            json_response(['success' => false, 'message' => 'Invalid product quantity, rate or tax.'], 422);
        }

        $base = (float)$qty * (float)$rate;
        $discountAmount = $base * (float)$discount / 100;
        $taxable = $base - $discountAmount;
        $taxAmount = $taxable * (float)$tax / 100;
        $lineTotal = round($taxable + $taxAmount, 2);

        $p = $productMap[$productId];
        $savedItems[] = [
            'product_id' => $productId,
            'name' => $p['name'],
            'thickness' => $p['thickness'] ?: null,
            'size' => $p['size'] ?: null,
            'hsn' => $p['hsn'] ?: null,
            'quantity' => (float)$qty,
            'rate' => (float)$rate,
            'tax' => (float)$tax,
            'discount' => (float)$discount,
            'amount' => $lineTotal,
        ];

        $subtotal += $base;
        $discountTotal += $discountAmount;
        $taxTotal += $taxAmount;
    }

    $total = round($subtotal - $discountTotal + $taxTotal, 2);
    $salesman = mb_substr(trim((string)($payload['salesman'] ?? $currentSalesman)), 0, 150);
    $reference = mb_substr(trim((string)($payload['reference'] ?? '')), 0, 150);
    $region = ($payload['region'] ?? '') === 'Outside AP' ? 'Outside AP' : 'Inside AP';
    $notes = mb_substr((string)($payload['notes'] ?? ''), 0, 5000);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($isOrder) {
            $orderNumber = next_number('orders', 'order_number', 'ORD-');
            $invoiceNumber = next_number('invoices', 'invoice_number', 'SI-');

            $orderId = db_insert('orders', [
                'order_number' => $orderNumber,
                'date' => $date,
                'delivery_date' => $valid,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'salesman' => $salesman,
                'store' => APP_STORE,
                'reference' => $reference ?: null,
                'region' => $region,
                'notes' => $notes,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($taxTotal, 2),
                'amount' => $total,
                'status' => 'Invoice Raised',
                'created_by' => (int)$_SESSION['user_id'],
            ]);

            foreach ($savedItems as $si) {
                db_insert('order_items', ['order_id' => $orderId] + $si);
                db_exec('UPDATE products SET stock = stock - ? WHERE id = ?', [$si['quantity'], $si['product_id']]);
            }

            $invoiceId = db_insert('invoices', [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'date' => $date,
                'due_date' => $valid,
                'region' => $region,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($taxTotal, 2),
                'amount' => $total,
                'status' => 'Submitted',
            ]);

            db_update('orders', ['invoice_id' => $invoiceId], $orderId);

            $fromQuotation = (int)($payload['from_quotation'] ?? 0);
            if ($fromQuotation) {
                db_exec("UPDATE quotations SET status = 'Converted' WHERE id = ?", [$fromQuotation]);
            }

            $pdo->commit();
            log_activity('Orders', "Order $orderNumber / Invoice $invoiceNumber created for {$customer['name']} (Rs " . number_format($total, 2) . ')');

            json_response([
                'success' => true,
                'quotation_id' => $orderNumber,
                'redirect' => 'orders.php?saved=' . urlencode($orderNumber)
            ]);
        }

        $quotationNumber = next_number('quotations', 'quotation_id', 'QUOT-');

        $quotationId = db_insert('quotations', [
            'quotation_id' => $quotationNumber,
            'date' => $date,
            'valid_upto' => $valid,
            'customer_id' => $customerId,
            'customer' => $customer['name'],
            'salesman' => $salesman,
            'store' => APP_STORE,
            'reference' => $reference ?: null,
            'region' => $region,
            'notes' => $notes,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountTotal, 2),
            'tax' => round($taxTotal, 2),
            'amount' => $total,
            'status' => 'Draft',
            'created_by' => (int)$_SESSION['user_id'],
        ]);

        foreach ($savedItems as $si) {
            db_insert('quotation_items', ['quotation_id' => $quotationId] + $si);
        }

        $pdo->commit();
        log_activity('Quotations', "Quotation $quotationNumber created for {$customer['name']} (Rs " . number_format($total, 2) . ')');

        json_response([
            'success' => true,
            'quotation_id' => $quotationNumber,
            'redirect' => 'quotation.php?saved=' . urlencode($quotationNumber)
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'Unable to save: ' . $e->getMessage()], 500);
    }
}

$pageTitle = $L['title'];
include 'header.php';
?>

<style>
/* ADD QUOTATION MAIN SECTION */

.add-quotation-section {
    background: #f6f8fd;
    min-height: 100vh;
    padding: 22px;
    font-family: Arial, sans-serif;
    color: #142238;
}

/* HEADER */

.add-quotation-section .aq-title {
    font-size: 26px;
    font-weight: 750;
    margin: 0 0 8px;
}

.add-quotation-section .aq-subtitle {
    font-size: 15px;
    font-weight: 600;
    color: #64748b;
    margin: 0;
}

.add-quotation-section .aq-header-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 13px;
    background: white;
    border: 1px solid #e1e7f0;
    border-radius: 13px;
    padding: 14px 18px;
    color: #293b55;
    font-size: 15px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
}

.add-quotation-section .aq-header-btn:hover {
    background: #edf4ff;
    color: #1479ff;
}

/* MAIN PANELS */

.add-quotation-section .aq-card {
    background: #fff;
    border: 1px solid #e0e6ef;
    border-radius: 13px;
    overflow: hidden;
}

/* QUOTATION INFORMATION */

.add-quotation-section .aq-info-card {
    margin-top: 27px;
    margin-bottom: 18px;
    padding: 16px 0;
}

.add-quotation-section .aq-info-item {
    padding: 5px 20px;
    border-right: 1px solid #e8edf4;
    min-height: 65px;
}

.add-quotation-section .aq-info-item:last-child {
    border-right: none;
}

.add-quotation-section .aq-info-label {
    color: #61718a;
    font-size: 14px;
    font-weight: 650;
    margin-bottom: 9px;
}

.add-quotation-section .aq-info-value {
    font-size: 15px;
    font-weight: 750;
    color: #18283d;
}

.add-quotation-section .aq-info-value i {
    color: #7187a5;
    margin-right: 10px;
    font-size: 17px;
}

.add-quotation-section .aq-date-input {
    border: none;
    background: transparent;
    font-weight: 750;
    color: #18283d;
    font-size: 15px;
    width: 155px;
    outline: none;
}

.add-quotation-section .aq-info-select {
    border: none;
    background: transparent;
    font-size: 15px;
    font-weight: 700;
    color: #18283d;
    outline: none;
    max-width: 100%;
    cursor: pointer;
}

.add-quotation-section .aq-reference {
    width: 100%;
    height: 47px;
    border: 1px solid #e1e7f0;
    border-radius: 11px;
    background: #f9fafc;
    padding: 12px 14px;
    font-size: 15px;
    outline: none;
}

.add-quotation-section .aq-reference:focus {
    border-color: #1479ff;
}

/* PRODUCT TOOLBAR */

.add-quotation-section .aq-product-toolbar {
    padding: 14px;
    border-bottom: 1px solid #e6eaf1;
}

.add-quotation-section .aq-customer-select {
    height: 56px;
    width: 100%;
    border: 1px solid #cde0ff;
    border-radius: 12px;
    background: #f4f8ff;
    color: #1479ff;
    padding: 10px 12px;
    font-weight: 750;
    cursor: pointer;
}

.add-quotation-section .aq-customer-label {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 3px;
}

.add-quotation-section .aq-customer-select select {
    display: block;
    width: 100%;
    border: none;
    outline: none;
    color: #1479ff;
    background: transparent;
    font-weight: 750;
    cursor: pointer;
    font-size: 14px;
}

.add-quotation-section .aq-product-search {
    height: 54px;
    display: flex;
    align-items: center;
    gap: 13px;
    border: 1px solid #e1e7f0;
    border-radius: 11px;
    padding: 0 16px;
    color: #71839c;
    background: white;
}

.add-quotation-section .aq-product-search input {
    flex: 1;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    font-size: 15px;
}

.add-quotation-section .aq-region-select {
    height: 54px;
    width: 100%;
    border: 1px solid #e1e7f0;
    background: white;
    border-radius: 12px;
    padding: 0 15px;
    font-size: 15px;
    font-weight: 650;
    color: #293b55;
}

.add-quotation-section .aq-add-product-btn {
    background: #1479ff;
    border: 1px solid #1479ff;
    color: white;
    border-radius: 12px;
    padding: 16px 15px;
    font-size: 15px;
    font-weight: 750;
    width: 100%;
    min-height: 54px;
}

.add-quotation-section .aq-add-product-btn:hover {
    background: #0868e6;
}

.add-quotation-section .aq-details-btn {
    background: white;
    border: 1px solid #e1e7f0;
    border-radius: 12px;
    padding: 15px 14px;
    min-height: 54px;
    width: 100%;
    color: #293b55;
    font-size: 15px;
    font-weight: 750;
}

.add-quotation-section .aq-details-btn:hover {
    background: #f5f8fc;
}

/* PRODUCT TABLE */

.add-quotation-section .aq-table {
    width: 100%;
    min-width: 1250px;
    margin-bottom: 0;
}

.add-quotation-section .aq-table thead th {
    padding: 22px 15px;
    font-size: 14px;
    font-weight: 750;
    color: #293b55;
    border-bottom: 1px solid #e8edf4;
    white-space: nowrap;
}

.add-quotation-section .aq-table tbody td {
    padding: 14px 12px;
    vertical-align: middle;
    color: #293b55;
    font-size: 14px;
}

.add-quotation-section .aq-table tbody tr:hover {
    background: #fafcff;
}

.add-quotation-section .aq-table-input {
    width: 77px;
    max-width: 100%;
    height: 36px;
    border: 1px solid #dfe6ef;
    border-radius: 7px;
    padding: 7px;
    background: #fff;
    color: #293b55;
}

.add-quotation-section .aq-table-input:focus {
    outline: none;
    border-color: #1479ff;
}

.add-quotation-section .aq-remove-btn {
    color: #f0444c;
    font-size: 16px;
    border: none;
    background: transparent;
}

.add-quotation-section .aq-empty-row {
    padding: 22px !important;
    text-align: center;
    color: #94a3b8 !important;
}

/* NOTES */

.add-quotation-section .aq-notes-card {
    padding: 16px;
    margin-top: 35px;
}

.add-quotation-section .aq-section-title {
    font-size: 16px;
    font-weight: 750;
    margin-bottom: 15px;
}

.add-quotation-section .aq-notes {
    width: 100%;
    min-height: 180px;
    resize: vertical;
    border: 1px solid #e2e8f0;
    background: #fafbfe;
    border-radius: 12px;
    padding: 14px;
    font-size: 15px;
    line-height: 1.5;
    color: #34445d;
    outline: none;
}

.add-quotation-section .aq-notes:focus {
    border-color: #1479ff;
}

/* TOTAL AND SAVE */

.add-quotation-section .aq-summary {
    padding: 18px;
    margin-top: 20px;
}

.add-quotation-section .aq-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 12px;
    font-size: 14px;
    color: #64748b;
}

.add-quotation-section .aq-summary-total {
    font-size: 20px;
    font-weight: 750;
    color: #152239;
    padding-top: 16px;
    border-top: 1px solid #e1e7f0;
}

.add-quotation-section .aq-save-btn {
    background: #1479ff;
    color: white;
    border: none;
    border-radius: 10px;
    padding: 15px 20px;
    font-size: 15px;
    font-weight: 750;
}

.add-quotation-section .aq-save-btn:disabled {
    opacity: .6;
}

/* MODAL */

.aq-modal .modal-content {
    border: none;
    border-radius: 15px;
}

.aq-modal .modal-header,
.aq-modal .modal-footer {
    padding: 16px 20px;
}

.aq-modal .modal-body {
    padding: 20px;
}


/* Compact + aligned (shared look) */
.add-quotation-section .aq-header-btn { padding: 9px 14px; gap: 8px; font-size: 14px; border-radius: 10px; }
.add-quotation-section .aq-info-card { margin-top: 16px; margin-bottom: 16px; padding: 12px 0; }
.add-quotation-section .aq-info-item { min-height: 0; height: 100%; padding: 4px 18px; display: flex; flex-direction: column; justify-content: flex-start; }
.add-quotation-section .aq-info-label { font-size: 12.5px; margin-bottom: 6px; }
.add-quotation-section .aq-date-input, .add-quotation-section .aq-info-select, .add-quotation-section .aq-info-value { font-size: 14px; }
.add-quotation-section .aq-reference { height: 36px; padding: 6px 12px; font-size: 14px; border-radius: 8px; }
.add-quotation-section .aq-product-toolbar { padding: 12px 14px; }
.add-quotation-section .aq-customer-select { height: 46px; padding: 6px 12px; border-radius: 10px; }
.add-quotation-section .aq-customer-label { font-size: 11px; margin-bottom: 1px; }
.add-quotation-section .aq-product-search,
.add-quotation-section .aq-region-select { height: 46px; border-radius: 10px; font-size: 14px; }
.add-quotation-section .aq-product-search input { font-size: 14px; }
.add-quotation-section .aq-add-product-btn,
.add-quotation-section .aq-details-btn { min-height: 46px; padding: 8px 12px; font-size: 14px; border-radius: 10px; }
.add-quotation-section .aq-table thead th { padding: 12px; font-size: 12.5px; }
.add-quotation-section .aq-table tbody td { padding: 10px 12px; }
.add-quotation-section .aq-notes-card { margin-top: 16px; }
.add-quotation-section .aq-section-title { font-size: 15px; margin-bottom: 10px; }
.add-quotation-section .aq-notes { min-height: 110px; font-size: 14px; border-radius: 10px; }
.add-quotation-section .aq-summary { margin-top: 16px; }
.add-quotation-section .aq-summary-row { font-size: 13.5px; margin-bottom: 9px; }
.add-quotation-section .aq-summary-total { font-size: 18px; padding-top: 12px; }
.add-quotation-section .aq-save-btn { padding: 10px 18px; font-size: 14px; border-radius: 9px; }
.add-quotation-section .aq-cancel-btn { padding-top: 10px; padding-bottom: 10px; font-size: 14px; }

/* RESPONSIVE */

@media (max-width: 1199px) {
    .add-quotation-section .aq-info-item {
        padding: 15px 14px;
    }

    .add-quotation-section .aq-info-item:nth-child(2n) {
        border-right: none;
    }
}

@media (max-width: 767px) {
    .add-quotation-section {
        padding: 15px 12px;
    }

    .add-quotation-section .aq-title {
        font-size: 22px;
    }

    .add-quotation-section .aq-subtitle {
        font-size: 14px;
    }

    .add-quotation-section .aq-header-btn {
        padding: 12px 14px;
        font-size: 14px;
    }

    .add-quotation-section .aq-info-item {
        border-right: none;
        border-bottom: 1px solid #e8edf4;
    }

    .add-quotation-section .aq-info-item:last-child {
        border-bottom: none;
    }

    .add-quotation-section .aq-product-toolbar {
        padding: 12px;
    }

    .add-quotation-section .aq-notes-card {
        padding: 13px;
    }

    .add-quotation-section .aq-notes {
        font-size: 14px;
    }
}
</style>

<!-- ADD QUOTATION MAIN SECTION -->
<section class="add-quotation-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3">

        <div class="col-lg-7">
            <h1 class="aq-title">
                <?php echo e($L["title"]); ?>
            </h1>

            <p class="aq-subtitle">
                <?php echo e($L["subtitle"]); ?>
            </p>
        </div>

        <div class="col-lg-5">
            <div class="d-flex gap-3 justify-content-lg-end flex-wrap">

                <button type="button"
                        id="newQuotationBtn"
                        class="aq-header-btn">
                    <i class="bi bi-plus-lg"></i>
                    <?php echo e($L["new"]); ?>
                </button>

                <a href="<?php echo e($L["listUrl"]); ?>"
                   class="aq-header-btn">
                    <i class="bi bi-file-earmark-text"></i>
                    <?php echo e($L["list"]); ?>
                </a>

            </div>
        </div>

    </div>

    <!-- QUOTATION DETAILS TOP ROW -->
    <div class="aq-card aq-info-card">

        <div class="row g-0 align-items-stretch">

            <!-- QUOTATION DATE -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">
                    <div class="aq-info-label">
                        <?php echo e($L["date"]); ?>
                    </div>

                    <div class="d-flex align-items-center">
                        <i class="bi bi-calendar3 text-secondary me-2"></i>

                        <input type="date"
                               id="quotationDate"
                               class="aq-date-input"
                               value="<?php echo htmlspecialchars($today); ?>">
                    </div>
                </div>

            </div>

            <!-- VALID UPTO -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">

                    <div class="aq-info-label">
                        <?php echo e($L["valid"]); ?>
                    </div>

                    <div class="d-flex align-items-center">

                        <i class="bi bi-calendar3 text-secondary me-2"></i>

                        <input type="date"
                               id="validUpto"
                               class="aq-date-input"
                               value="<?php echo htmlspecialchars($validUpto); ?>">
                    </div>

                </div>

            </div>

            <!-- SALESMAN -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">

                    <div class="aq-info-label">
                        Salesman
                    </div>

                    <div class="d-flex align-items-center">

                        <i class="bi bi-person text-secondary me-2"></i>

                        <select class="aq-info-select"
                                id="salesman">
                            <?php foreach ($salesmen as $sm): ?>
                            <option value="<?php echo e($sm); ?>" <?php echo $sm === $currentSalesman ? 'selected' : ''; ?>>
                                <?php echo e($sm); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                    </div>

                </div>

            </div>

            <!-- REFERENCE -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">

                    <label for="quotationReference"
                           class="aq-info-label">
                        Reference
                    </label>

                    <input type="text"
                           id="quotationReference"
                           class="aq-reference"
                           placeholder="Enter reference"
                           maxlength="150">

                </div>

            </div>

            <!-- STORE -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">

                    <div class="aq-info-label">
                        Store
                    </div>

                    <div class="aq-info-value">
                        <i class="bi bi-building"></i>
                        Gullapudi
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- PRODUCT ENTRY PANEL -->
    <div class="aq-card">

        <!-- TOOLBAR -->
        <div class="aq-product-toolbar">

            <div class="row g-3 align-items-center">

                <!-- CUSTOMER -->
                <div class="col-12 col-md-6 col-xl-2">

                    <div class="aq-customer-select">

                        <span class="aq-customer-label">
                            <i class="bi bi-person-fill me-1"></i>
                            Customer
                        </span>

                        <select id="customerSelect"
                                aria-label="Select Customer">

                            <?php if (!$customers): ?>
                            <option value="">No customers - add one first</option>
                            <?php endif; ?>

                            <?php foreach ($customers as $customer): ?>

                            <option value="<?php echo (int)$customer['id']; ?>"
                                <?php echo ($prefill && $prefill['customer_id'] === (int)$customer['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <!-- PRODUCT SEARCH -->
                <div class="col-12 col-md-6 col-xl-4">

                    <div class="aq-product-search">

                        <i class="bi bi-search fs-5"></i>

                        <input type="search"
                               id="productSearch"
                               placeholder="Search product by name / barcode / model">

                        <i class="bi bi-upc-scan fs-5"></i>

                    </div>

                </div>

                <!-- REGION -->
                <div class="col-12 col-md-4 col-xl-2">

                    <select class="aq-region-select"
                            id="quotationRegion">

                        <option value="Inside AP">
                            Inside AP
                        </option>

                        <option value="Outside AP">
                            Outside AP
                        </option>

                    </select>

                </div>

                <!-- ADD PRODUCT -->
                <div class="col-12 col-md-4 col-xl-2">

                    <button type="button"
                            class="aq-add-product-btn"
                            id="openProductModal">

                        <i class="bi bi-plus-lg me-2"></i>
                        Add Product

                    </button>

                </div>

                <!-- QUOTATION DETAILS -->
                <div class="col-12 col-md-4 col-xl-2">

                    <button type="button"
                            class="aq-details-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#quotationDetailsModal">

                        <i class="bi bi-info-circle-fill me-2"></i>
                        <?php echo e($L["details"]); ?>

                    </button>

                </div>

            </div>

        </div>

        <!-- PRODUCTS TABLE -->
        <div class="table-responsive">

            <table class="table aq-table">

                <thead>
                    <tr>
                        <th class="text-center">S No</th>
                        <th>Product<br>Details</th>
                        <th>Thickness</th>
                        <th>Size</th>
                        <th>HSN Code</th>
                        <th>Quantity</th>
                        <th>Rate</th>
                        <th>Tax</th>
                        <th>Discount</th>
                        <th>Amount</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody id="quotationProductsBody">

                    <!-- Dynamically generated rows -->

                </tbody>

            </table>

        </div>

    </div>

    <!-- TERMS & NOTES -->
    <div class="aq-card aq-notes-card">

        <h3 class="aq-section-title">
            Terms &amp; Notes
        </h3>

        <textarea class="aq-notes"
                  id="quotationNotes"
                  rows="6">1. Goods once sold will not be taken back.
2. Transportation extra.
3. Payment within 7 days.</textarea>

    </div>

    <!-- SUMMARY AND SAVE -->
    <div class="aq-card aq-summary">

        <div class="row g-4 align-items-start">

            <div class="col-md-6">
                <h3 class="aq-section-title mb-2">
                    <?php echo e($L["summary"]); ?>
                </h3>

                <p class="text-secondary mb-0">
                    Review product quantities, taxes and discounts
                    before saving.
                </p>
            </div>

            <div class="col-md-6 col-lg-4 ms-lg-auto">

                <div class="aq-summary-row">
                    <span>Subtotal</span>
                    <strong id="subtotalAmount">Rs 0.00</strong>
                </div>

                <div class="aq-summary-row">
                    <span>Discount</span>
                    <strong id="totalDiscount">Rs 0.00</strong>
                </div>

                <div class="aq-summary-row">
                    <span>Tax</span>
                    <strong id="totalTax">Rs 0.00</strong>
                </div>

                <div class="aq-summary-row aq-summary-total">
                    <span>Grand Total</span>
                    <strong id="grandTotal">Rs 0.00</strong>
                </div>

            </div>

            <div class="col-12 text-end">

                <a href="<?php echo e($L["listUrl"]); ?>"
                   class="btn btn-light border px-4 me-2 aq-cancel-btn">
                    Cancel
                </a>

                <button type="button"
                        class="aq-save-btn"
                        id="saveQuotationBtn">

                    <i class="bi bi-floppy me-2"></i>
                    <?php echo e($L["save"]); ?>

                </button>

            </div>

            <div class="col-12">
                <div id="quotationError"
                     class="alert alert-danger d-none"
                     role="alert"></div>
            </div>

        </div>

    </div>

</div>
</section>

<!-- PRODUCT SELECTION MODAL -->
<div class="modal fade aq-modal"
     id="selectProductModal"
     tabindex="-1"
     aria-labelledby="selectProductTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title"
                    id="selectProductTitle">
                    Select Product
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body">

                <div class="input-group mb-3">

                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>

                    <input type="search"
                           class="form-control"
                           id="modalProductSearch"
                           placeholder="Search product...">

                </div>

                <div id="productSelectionList"
                     class="list-group"></div>

            </div>

        </div>

    </div>
</div>

<!-- QUOTATION DETAILS MODAL -->
<div class="modal fade aq-modal"
     id="quotationDetailsModal"
     tabindex="-1"
     aria-labelledby="quotationDetailsTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title"
                    id="quotationDetailsTitle">
                    <?php echo e($L["details"]); ?>
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body"
                 id="quotationDetailsContent"></div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-primary"
                        data-bs-dismiss="modal">
                    Close
                </button>

            </div>

        </div>

    </div>
</div>


<script>
(function () {
    "use strict";

    const products = <?php echo json_encode(
        $products,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(csrf_token()); ?>;

    const prefill = <?php echo json_encode($prefill, json_flags()); ?>;

    const $ = id => document.getElementById(id);

    let quotationItems = prefill ? prefill.items : [];

    if (prefill) {
        $("quotationReference").value = prefill.reference || "";
        $("quotationRegion").value = prefill.region || "Inside AP";
        if (prefill.notes) $("quotationNotes").value = prefill.notes;
    }

    const productModal = bootstrap.Modal.getOrCreateInstance(
        $("selectProductModal")
    );

    const formatMoney = value => {
        return "Rs " + Number(value).toLocaleString("en-IN", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    // Update all quotation amounts.
    function calculateTotals() {

        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;

        quotationItems.forEach(item => {

            const base = item.quantity * item.rate;
            const discountAmount = base * item.discount / 100;
            const taxable = base - discountAmount;
            const taxAmount = taxable * item.tax / 100;

            item.amount = taxable + taxAmount;

            subtotal += base;
            totalDiscount += discountAmount;
            totalTax += taxAmount;
        });

        const grandTotal = subtotal - totalDiscount + totalTax;

        $("subtotalAmount").textContent = formatMoney(subtotal);
        $("totalDiscount").textContent = formatMoney(totalDiscount);
        $("totalTax").textContent = formatMoney(totalTax);
        $("grandTotal").textContent = formatMoney(grandTotal);

        return {
            subtotal,
            discount: totalDiscount,
            tax: totalTax,
            grandTotal
        };
    }

    // Render selected products.
    function renderQuotationItems() {

        const tbody = $("quotationProductsBody");

        tbody.innerHTML = "";

        if (quotationItems.length === 0) {
            // Match screenshot: table headings only.
            calculateTotals();
            return;
        }

        quotationItems.forEach((item, index) => {

            const tr = document.createElement("tr");

            tr.innerHTML = `
                <td class="text-center">
                    ${index + 1}
                </td>

                <td>
                    <strong>${escapeHTML(item.name)}</strong>
                </td>

                <td>${escapeHTML(item.thickness)}</td>

                <td>${escapeHTML(item.size)}</td>

                <td>${escapeHTML(item.hsn)}</td>

                <td>
                    <input type="number"
                           class="aq-table-input"
                           data-index="${index}"
                           data-field="quantity"
                           min="0.01"
                           step="0.01"
                           value="${item.quantity}">
                </td>

                <td>
                    <input type="number"
                           class="aq-table-input"
                           data-index="${index}"
                           data-field="rate"
                           min="0"
                           step="0.01"
                           value="${item.rate}">
                </td>

                <td>
                    <select class="aq-table-input"
                            data-index="${index}"
                            data-field="tax">
                        ${[...new Set([0,5,12,18,28,Number(item.tax)])].sort((a,b)=>a-b).map(rate => `
                            <option value="${rate}"
                                ${Number(item.tax) === rate ? "selected" : ""}>
                                ${rate}%
                            </option>
                        `).join("")}
                    </select>
                </td>

                <td>
                    <input type="number"
                           class="aq-table-input"
                           data-index="${index}"
                           data-field="discount"
                           min="0"
                           max="100"
                           step="0.01"
                           value="${item.discount}">
                </td>

                <td class="fw-bold aq-line-amount">
                    ${formatMoney(item.amount || 0)}
                </td>

                <td class="text-center">
                    <button type="button"
                            class="aq-remove-btn"
                            data-remove="${index}"
                            title="Remove Product">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
            `;

            tbody.appendChild(tr);
        });

        updateLineAmounts();
    }

    function updateLineAmounts() {

        calculateTotals();

        $("quotationProductsBody")
            .querySelectorAll("tr")
            .forEach((row, index) => {

                const amountCell =
                    row.querySelector(".aq-line-amount");

                if (amountCell && quotationItems[index]) {
                    amountCell.textContent = formatMoney(
                        quotationItems[index].amount
                    );
                }
            });
    }

    // Add selected product.
    function addProduct(productId) {

        const product = products.find(
            p => Number(p.id) === Number(productId)
        );

        if (!product) return;

        const existing = quotationItems.find(
            p => p.product_id === product.id
        );

        if (existing) {
            existing.quantity += 1;
        } else {
            quotationItems.push({
                product_id: product.id,
                name: product.name,
                thickness: product.thickness,
                size: product.size,
                hsn: product.hsn,
                quantity: 1,
                rate: Number(product.rate),
                tax: Number(product.tax) || 0,
                discount: 0,
                amount: 0
            });
        }

        renderQuotationItems();

        productModal.hide();
    }

    // Product search popup.
    function renderProductSelection() {

        const search = $("modalProductSearch")
            .value.toLowerCase().trim();

        const filtered = products.filter(product => {
            return [
                product.name,
                product.id,
                product.thickness,
                product.size,
                product.hsn
            ].join(" ").toLowerCase().includes(search);
        });

        const list = $("productSelectionList");

        list.innerHTML = "";

        if (!filtered.length) {
            list.innerHTML =
                '<div class="p-3 text-muted">No products found.</div>';
            return;
        }

        filtered.forEach(product => {

            const button = document.createElement("button");

            button.type = "button";
            button.className =
                "list-group-item list-group-item-action py-3";

            button.innerHTML = `
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <strong>${escapeHTML(product.name)}</strong>
                        <div class="text-muted small mt-1">
                            ${escapeHTML(product.thickness)}
                            | ${escapeHTML(product.size)}
                        </div>
                    </div>
                    <strong>${formatMoney(product.rate)}</strong>
                </div>
            `;

            button.addEventListener("click", () => {
                addProduct(product.id);
            });

            list.appendChild(button);
        });
    }

    // Open product selector.
    $("openProductModal").addEventListener("click", () => {

        $("modalProductSearch").value =
            $("productSearch").value;

        renderProductSelection();

        productModal.show();
    });

    $("modalProductSearch").addEventListener(
        "input",
        renderProductSelection
    );

    // Open selection from main search.
    $("productSearch").addEventListener("keydown", event => {

        if (event.key === "Enter") {
            event.preventDefault();
            $("openProductModal").click();
        }
    });

    // Quantity, rate, tax and discount changes.
    $("quotationProductsBody").addEventListener("input", event => {

        const input = event.target.closest("[data-field]");

        if (!input) return;

        const index = Number(input.dataset.index);
        const field = input.dataset.field;
        const item = quotationItems[index];

        if (!item) return;

        const value = Number(input.value);

        if (!Number.isFinite(value)) return;

        item[field] = value;

        updateLineAmounts();
    });

    $("quotationProductsBody").addEventListener("change", event => {

        const input = event.target.closest("[data-field]");

        if (!input) return;

        const index = Number(input.dataset.index);
        const field = input.dataset.field;
        const item = quotationItems[index];

        if (!item) return;

        let value = Number(input.value);

        if (!Number.isFinite(value)) value = 0;

        if (field === "quantity") {
            value = Math.min(1000000, Math.max(0.01, value));
        }

        if (field === "rate") {
            value = Math.min(100000000, Math.max(0, value));
        }

        if (field === "discount" || field === "tax") {
            value = Math.min(100, Math.max(0, value));
        }

        item[field] = value;

        renderQuotationItems();
    });

    // Delete a product row.
    $("quotationProductsBody").addEventListener("click", event => {

        const button = event.target.closest("[data-remove]");

        if (!button) return;

        quotationItems.splice(
            Number(button.dataset.remove),
            1
        );

        renderQuotationItems();
    });

    // Quotation Details modal.
    $("quotationDetailsModal").addEventListener(
        "show.bs.modal",
        function () {

            const totals = calculateTotals();

            const customer = $("customerSelect");

            const customerName =
                customer.selectedIndex >= 0 ? customer.options[customer.selectedIndex].text : "-";

            const rows = [
                ["Customer", customerName],
                ["Quotation Date", $("quotationDate").value],
                ["Valid Upto", $("validUpto").value],
                ["Salesman", $("salesman").value],
                ["Store", "Gullapudi"],
                ["Region", $("quotationRegion").value],
                ["Total Products", quotationItems.length],
                ["Subtotal", formatMoney(totals.subtotal)],
                ["Discount", formatMoney(totals.discount)],
                ["Tax", formatMoney(totals.tax)],
                ["Grand Total", formatMoney(totals.grandTotal)]
            ];

            $("quotationDetailsContent").innerHTML = rows.map(
                ([label, value]) => `
                    <div class="row py-2 border-bottom">
                        <div class="col-5 fw-semibold">
                            ${escapeHTML(label)}
                        </div>
                        <div class="col-7">
                            ${escapeHTML(value)}
                        </div>
                    </div>
                `
            ).join("");
        }
    );

    // Reset quotation form.
    $("newQuotationBtn").addEventListener("click", () => {

        if (
            quotationItems.length &&
            !confirm("Start a new quotation? Unsaved items will be cleared.")
        ) {
            return;
        }

        quotationItems = [];
        $("productSearch").value = "";
        $("quotationReference").value = "";
        $("quotationRegion").value = "Inside AP";

        $("quotationNotes").value =
            "1. Goods once sold will not be taken back.\n" +
            "2. Transportation extra.\n" +
            "3. Payment within 7 days.";

        $("quotationError").classList.add("d-none");

        renderQuotationItems();
    });

    // Save quotation through PHP.
    $("saveQuotationBtn").addEventListener("click", async () => {

        const errorBox = $("quotationError");
        const saveBtn = $("saveQuotationBtn");

        errorBox.classList.add("d-none");
        errorBox.textContent = "";

        if (quotationItems.length === 0) {
            errorBox.textContent =
                "Please add at least one product.";
            errorBox.classList.remove("d-none");
            return;
        }

        const date = $("quotationDate").value;
        const validUpto = $("validUpto").value;

        if (!date || !validUpto || validUpto < date) {
            errorBox.textContent =
                "Valid Upto must be on or after Quotation Date.";
            errorBox.classList.remove("d-none");
            return;
        }

        const invalidItem = quotationItems.some(item =>
            !Number.isFinite(item.quantity) ||
            item.quantity <= 0 ||
            !Number.isFinite(item.rate) ||
            item.rate < 0 ||
            !Number.isFinite(item.discount) ||
            item.discount < 0 ||
            item.discount > 100
        );

        if (invalidItem) {
            errorBox.textContent =
                "Please check product quantities and rates.";
            errorBox.classList.remove("d-none");
            return;
        }

        const payload = {
            date: date,
            valid_upto: validUpto,
            salesman: $("salesman").value,
            reference: $("quotationReference").value.trim(),
            store: "Gullapudi",
            region: $("quotationRegion").value,
            customer_id: Number($("customerSelect").value),
            from_quotation: prefill ? prefill.quotation_id : 0,
            notes: $("quotationNotes").value,
            items: quotationItems.map(item => ({
                product_id: item.product_id,
                quantity: item.quantity,
                rate: item.rate,
                tax: item.tax,
                discount: item.discount
            }))
        };

        const formData = new FormData();

        formData.append("csrf_token", csrfToken);
        formData.append(
            "quotation_data",
            JSON.stringify(payload)
        );

        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";

        try {

            const response = await fetch(
                window.location.pathname,
                {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin"
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to save quotation."
                );
            }

            window.location.href = result.redirect;

        } catch (error) {

            errorBox.textContent = error.message;
            errorBox.classList.remove("d-none");

            saveBtn.disabled = false;

            saveBtn.innerHTML =
                '<i class="bi bi-floppy me-2"></i>Save Quotation';
        }
    });

    // Initial empty table.
    renderQuotationItems();

})();
</script>






<?php include 'footer.php'; ?>