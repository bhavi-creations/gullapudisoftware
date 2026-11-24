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
    'subtitle' => 'Create a sales order - add it to an invoice or convert it to a quotation later',
    'new' => 'New Order',
    'list' => 'Orders',
    'listUrl' => 'orders.php',
    'date' => 'Order Date',
    'valid' => 'Due Date',
    'details' => 'Order Details',
    'summary' => 'Order Summary',
    'save' => 'Save Order',
    'saveMaster' => 'Save Order',
    'saveGenerate' => 'Save & Add to Invoice',
    'waTitle' => 'Order',
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
    'saveMaster' => 'Save Quotation',
    'saveGenerate' => 'Save & Generate Quotation',
    'waTitle' => 'Quotation',
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

// Salesman / Reference can be typed by hand; earlier values are offered as suggestions.
function pos_recent_values(string $column): array
{
    $values = [];
    foreach (['quotations', 'orders'] as $table) {
        $rows = db_all("SELECT `$column` AS v FROM `$table`
                        WHERE `$column` IS NOT NULL AND `$column` <> ''
                        GROUP BY `$column` ORDER BY MAX(id) DESC LIMIT 100");
        $values = array_merge($values, array_column($rows, 'v'));
    }
    return $values;
}
$salesmanSuggestions = array_values(array_unique(array_merge($salesmen, pos_recent_values('salesman'))));
$referenceSuggestions = array_values(array_unique(pos_recent_values('reference')));
// Store choices come from the Stores page.
$storeList = store_names();

$today = date('Y-m-d');
$validUpto = date('Y-m-d', strtotime('+30 days'));

/* Convert quotation -> order, or edit a saved quotation: pre-fill items */
$prefill = null;
$editQuotation = null;
if (!$isOrder && !empty($_GET['edit'])) {
    $editQuotation = db_one('SELECT * FROM quotations WHERE id = ?', [(int)$_GET['edit']]);
    if (!$editQuotation && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: quotation.php');
        exit;
    }
}
/* Edit a saved order: add-order.php?edit=ID (stock is corrected on save). */
$editOrder = null;
if ($isOrder && !empty($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editOrder = db_one('SELECT * FROM orders WHERE id = ?', [(int)$_GET['edit']]);
    if (!$editOrder || $editOrder['status'] === 'Cancelled') {
        header('Location: orders.php');
        exit;
    }
    $orderLines = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int)$editOrder['id']]);
    // Lines typed by hand (services / custom items) can only be edited on the Direct Invoice screen.
    if (array_filter($orderLines, fn($l) => empty($l['product_id']))) {
        header('Location: ' . ($editOrder['invoice_id'] ? 'direct-invoice.php?edit=' . (int)$editOrder['invoice_id'] : 'orders.php'));
        exit;
    }
    $prefill = [
        'quotation_id' => 0,
        'edit_id' => (int)$editOrder['id'],
        'quotation_number' => $editOrder['order_number'],
        'invoice_number' => $editOrder['invoice_id']
            ? (string)db_value("SELECT invoice_number FROM invoices WHERE id = ? AND status <> 'Cancelled'", [(int)$editOrder['invoice_id']])
            : '',
        'customer_id' => (int)$editOrder['customer_id'],
        'reference' => (string)$editOrder['reference'],
        'date' => $editOrder['date'],
        'valid_upto' => $editOrder['delivery_date'] ?: $editOrder['date'],
        'salesman' => $editOrder['salesman'],
        'store' => (string)($editOrder['store'] ?? ''),
        'is_master' => false,
        'region' => $editOrder['region'],
        'notes' => $editOrder['notes'],
        'items' => array_map(fn($i) => [
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
        ], $orderLines),
    ];
}

$sourceId = $isOrder ? (int)($_GET['quotation'] ?? 0) : (int)($editQuotation['id'] ?? 0);
if ($sourceId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = db_one('SELECT * FROM quotations WHERE id = ?', [$sourceId]);
    if ($q) {
        $prefill = [
            'quotation_id' => $isOrder ? (int)$q['id'] : 0,
            'edit_id' => $isOrder ? 0 : (int)$q['id'],
            'quotation_number' => $q['quotation_id'],
            'customer_id' => (int)$q['customer_id'],
            'reference' => $isOrder ? $q['quotation_id'] : (string)$q['reference'],
            'date' => $isOrder ? null : $q['date'],
            'valid_upto' => $isOrder ? null : $q['valid_upto'],
            'salesman' => $isOrder ? null : $q['salesman'],
            'store' => (string)($q['store'] ?? ''),
            'is_master' => !$isOrder && (int)$q['is_master'] === 1,
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
    // Store must be one saved on the Stores page (old disabled names are kept when editing).
    $store = trim((string)($payload['store'] ?? ''));
    if (!in_array($store, store_names(false), true)) {
        $store = $storeList[0] ?? APP_STORE;
    }
    $region = ($payload['region'] ?? '') === 'Outside AP' ? 'Outside AP' : 'Inside AP';
    $notes = mb_substr((string)($payload['notes'] ?? ''), 0, 5000);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orderEditId = $isOrder ? (int)($payload['edit_id'] ?? 0) : 0;
        if ($orderEditId) {
            /* ---- UPDATE a saved order (number stays the same; stock + invoice are corrected) ---- */
            $existing = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderEditId]);
            if (!$existing) {
                throw new RuntimeException('Order not found.');
            }
            if ($existing['status'] === 'Cancelled') {
                throw new RuntimeException('This order is cancelled and cannot be edited.');
            }
            // Old lines go back to stock, new lines are taken (blocked if more than the stock).
            order_replace_items($orderEditId, $savedItems);
            $totals = [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($taxTotal, 2),
                'amount' => $total,
            ];
            db_update('orders', [
                'date' => $date,
                'delivery_date' => $valid,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'salesman' => $salesman,
                'store' => $store,
                'reference' => $reference ?: null,
                'region' => $region,
                'notes' => $notes,
            ] + $totals, $orderEditId);

            // Already invoiced: the invoice gets the new products and amount.
            $invoice = $existing['invoice_id']
                ? db_one("SELECT * FROM invoices WHERE id = ? AND status <> 'Cancelled'", [(int)$existing['invoice_id']])
                : null;
            if ($invoice) {
                $paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoice['invoice_number']]);
                if ($paid > $total + 0.009) {
                    throw new RuntimeException('Invoice ' . $invoice['invoice_number'] . ' already has Rs ' . number_format($paid, 2)
                        . ' paid, more than the new total. Remove a payment first.');
                }
                db_update('invoices', [
                    'customer_id' => $customerId,
                    'customer' => $customer['name'],
                    'region' => $region,
                ] + $totals, (int)$invoice['id']);
                refresh_invoice_status($invoice['invoice_number']);
            }

            $pdo->commit();
            log_activity('Orders', "Order {$existing['order_number']} updated for {$customer['name']} (Rs " . number_format($total, 2) . ')'
                . ($invoice ? ", invoice {$invoice['invoice_number']} updated" : ''));

            json_response([
                'success' => true,
                'quotation_id' => $existing['order_number'],
                'redirect' => ($payload['mode'] ?? '') === 'generate'
                    ? ($invoice ? 'invoice-print.php?id=' . (int)$invoice['id'] : 'orders.php?invoice=' . $orderEditId)
                    : 'orders.php?updated=' . urlencode($existing['order_number'])
            ]);
        }

        if ($isOrder) {
            // Never sell more than what is in stock (stock must not go below 0).
            $short = stock_shortages($savedItems);
            if ($short) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'Not enough stock: ' . implode(', ', $short) . '. Reduce the quantity.'], 422);
            }

            // The order is saved on its own (stock is reserved now).
            // "Add to Invoice" / "Convert to Quotation" are on the Orders page.
            $orderNumber = next_number('orders', 'order_number', 'ORD-');

            $orderId = db_insert('orders', [
                'order_number' => $orderNumber,
                'date' => $date,
                'delivery_date' => $valid,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'salesman' => $salesman,
                'store' => $store,
                'reference' => $reference ?: null,
                'region' => $region,
                'notes' => $notes,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($taxTotal, 2),
                'amount' => $total,
                'status' => 'Pending',
                'created_by' => (int)$_SESSION['user_id'],
            ]);

            foreach ($savedItems as $si) {
                db_insert('order_items', ['order_id' => $orderId] + $si);
                db_exec('UPDATE products SET stock = stock - ? WHERE id = ?', [$si['quantity'], $si['product_id']]);
            }

            $fromQuotation = (int)($payload['from_quotation'] ?? 0);
            if ($fromQuotation) {
                db_exec("UPDATE quotations SET status = 'Converted' WHERE id = ?", [$fromQuotation]);
            }

            $pdo->commit();
            log_activity('Orders', "Order $orderNumber created for {$customer['name']} (Rs " . number_format($total, 2) . ')');

            json_response([
                'success' => true,
                'quotation_id' => $orderNumber,
                'redirect' => ($payload['mode'] ?? '') === 'generate'
                    ? 'orders.php?invoice=' . $orderId
                    : 'orders.php?saved=' . urlencode($orderNumber)
            ]);
        }

        $isMaster = !empty($payload['is_master']) ? 1 : 0;
        $editId = (int)($payload['edit_id'] ?? 0);

        if ($editId) {
            /* ---- UPDATE an existing quotation (number + status stay the same) ---- */
            $existing = db_one('SELECT id, quotation_id, status FROM quotations WHERE id = ?', [$editId]);
            if (!$existing) {
                throw new RuntimeException('Quotation not found.');
            }
            // Edited after conversion -> open again so "Convert to Invoice" shows;
            // converting again updates the invoice made earlier.
            $newStatus = $existing['status'] === 'Converted' ? 'Accepted' : $existing['status'];
            db_update('quotations', [
                'status' => $newStatus,
                'date' => $date,
                'valid_upto' => $valid,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'salesman' => $salesman,
                'store' => $store,
                'reference' => $reference ?: null,
                'region' => $region,
                'notes' => $notes,
                'subtotal' => round($subtotal, 2),
                'discount' => round($discountTotal, 2),
                'tax' => round($taxTotal, 2),
                'amount' => $total,
                'is_master' => $isMaster,
            ], $editId);
            db_exec('DELETE FROM quotation_items WHERE quotation_id = ?', [$editId]);
            foreach ($savedItems as $si) {
                db_insert('quotation_items', ['quotation_id' => $editId] + $si);
            }
            $pdo->commit();
            log_activity('Quotations', "Quotation {$existing['quotation_id']} updated for {$customer['name']} (Rs " . number_format($total, 2) . ')');

            json_response([
                'success' => true,
                'quotation_id' => $existing['quotation_id'],
                'redirect' => ($payload['mode'] ?? '') === 'generate'
                    ? 'quotation-print.php?id=' . $editId
                    : ($isMaster ? 'master-quotation.php' : 'quotation.php') . '?saved=' . urlencode($existing['quotation_id'])
            ]);
        }

        $quotationNumber = next_number('quotations', 'quotation_id', 'QUOT-');

        $quotationId = db_insert('quotations', [
            'quotation_id' => $quotationNumber,
            'is_master' => $isMaster,
            'date' => $date,
            'valid_upto' => $valid,
            'customer_id' => $customerId,
            'customer' => $customer['name'],
            'salesman' => $salesman,
            'store' => $store,
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
            'redirect' => ($payload['mode'] ?? '') === 'generate'
                ? 'quotation-print.php?id=' . $quotationId
                : ($isMaster ? 'master-quotation.php' : 'quotation.php') . '?saved=' . urlencode($quotationNumber)
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'Unable to save: ' . $e->getMessage()], 500);
    }
}

if ($prefill && $prefill['edit_id'] && $isOrder) {
    $L['title'] = 'Edit Order ' . $prefill['quotation_number'];
    $L['subtitle'] = $prefill['invoice_number']
        ? 'Update this order. The order number stays the same; invoice ' . $prefill['invoice_number'] . ' and stock are updated too.'
        : 'Update this order. The order number stays the same and stock is corrected.';
    $L['save'] = 'Update Order';
    $L['saveMaster'] = 'Update Order';
    $L['saveGenerate'] = $prefill['invoice_number'] ? 'Update & Print Invoice' : 'Update & Add to Invoice';
} elseif ($prefill && $prefill['edit_id']) {
    $L['title'] = 'Edit Quotation ' . $prefill['quotation_number'];
    $L['subtitle'] = 'Update this quotation. The quotation number stays the same.';
    $L['saveMaster'] = 'Update Quotation';
    $L['saveGenerate'] = 'Update & Generate Quotation';
}

$pageTitle = $L['title'];
include 'header.php';
?>



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

                        <!-- Type any name, or pick a saved one from the list -->
                        <input type="text"
                               class="aq-reference aq-type-input"
                               id="salesman"
                               list="salesmanList"
                               maxlength="150"
                               placeholder="Type or choose salesman"
                               value="<?php echo e($currentSalesman); ?>">
                        <datalist id="salesmanList">
                            <?php foreach ($salesmanSuggestions as $sm): ?>
                                <option value="<?php echo e($sm); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

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
                           list="referenceList"
                           placeholder="Type reference"
                           maxlength="150">
                    <datalist id="referenceList">
                        <?php foreach ($referenceSuggestions as $ref): ?>
                            <option value="<?php echo e($ref); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                </div>

            </div>

            <!-- STORE (from the Stores page) -->
            <div class="col-md-6 col-xl">

                <div class="aq-info-item">

                    <label for="quotationStore" class="aq-info-label">
                        Store
                        <a href="stores.php" class="ms-1 small text-decoration-none" title="Manage stores"><i class="bi bi-gear"></i></a>
                    </label>

                    <div class="d-flex align-items-center">
                        <i class="bi bi-shop text-secondary me-2"></i>
                        <select class="aq-info-select" id="quotationStore">
                            <?php foreach ($storeList as $st): ?>
                                <option value="<?php echo e($st); ?>"><?php echo e($st); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                            Inside AP (CGST + SGST)
                        </option>

                        <option value="Outside AP">
                            Outside AP (IGST)
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

        <style>
            /* Available stock column + out-of-stock labels */
            .aq-stock{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;border-radius:20px;padding:3px 10px;white-space:nowrap}
            .aq-stock.ok{background:#e5f7ec;color:#0f7a3d}
            .aq-stock.out{background:#fde8e8;color:#c2343c}
            .aq-row-short td{background:#fff6f6 !important}
            .aq-qty-short{border-color:#e0444c !important;color:#c2343c;font-weight:700;box-shadow:0 0 0 3px rgba(224,68,76,.12)}
            .aq-pick-out{background:#fffafa}
            .aq-pick-out[aria-disabled="true"]{opacity:.65;cursor:not-allowed}
        </style>

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
                        <th>Available<br>Stock</th>
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

    <!-- TOTALS (shown once products are added) -->
    <div class="aq-totals d-none" id="aqTotals">
        <div><span>Subtotal</span><strong id="subtotalAmount">Rs 0.00</strong></div>
        <div><span>Discount</span><strong id="totalDiscount">Rs 0.00</strong></div>
        <div data-gst="intra"><span>CGST</span><strong id="totalCgst">Rs 0.00</strong></div>
        <div data-gst="intra"><span>SGST</span><strong id="totalSgst">Rs 0.00</strong></div>
        <div data-gst="inter" class="d-none"><span>IGST</span><strong id="totalIgst">Rs 0.00</strong></div>
        <div class="d-none"><span>Tax</span><strong id="totalTax">Rs 0.00</strong></div>
        <div class="grand"><span>Grand Total</span><strong id="grandTotal">Rs 0.00</strong></div>
    </div>

    <div id="quotationError"
         class="alert alert-danger d-none mt-3 mb-0"
         role="alert"></div>

</div>
</section>

<!-- BOTTOM ACTION BAR -->
<div class="aq-actionbar">
    <button type="button" class="aq-bar-btn clear" id="clearAllBtn">
        <i class="bi bi-trash3"></i> Clear All
    </button>
    <button type="button" class="aq-bar-btn whatsapp" id="whatsappBtn">
        <i class="bi bi-whatsapp"></i> WhatsApp
    </button>
    <?php if (!$isOrder): ?>
    <label class="aq-bar-btn" for="isMasterQuotation" style="cursor:pointer;user-select:none"
           title="Master quotations are listed in both Quotations and Master Quotations">
        <input type="checkbox" id="isMasterQuotation" class="form-check-input m-0"
               style="width:1.15em;height:1.15em"
               <?php echo (($prefill && !empty($prefill['is_master'])) || (!$prefill && !empty($_GET['master']))) ? 'checked' : ''; ?>>
        <i class="bi bi-star-fill text-warning"></i> Master Quotation
    </label>
    <?php endif; ?>
    <button type="button" class="aq-bar-btn primary" id="saveQuotationBtn" data-mode="master">
        <?php echo e($L["saveMaster"]); ?>
    </button>
    <button type="button" class="aq-bar-btn primary" id="saveGenerateBtn" data-mode="generate">
        <?php echo e($L["saveGenerate"]); ?>
    </button>
</div>

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

    // GST rates from the Taxes page.
    const gstRates = <?php echo json_encode(array_values(array_unique(array_column(gst_rates(), 'rate'))), json_flags()); ?>;

    const $ = id => document.getElementById(id);

    // Orders take stock, so they cannot use more than what is available.
    // Quotations only show the stock (no stock is taken).
    const isOrderMode = <?php echo $isOrder ? 'true' : 'false'; ?>;
    const fmtQty = v => Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
    // Editing an order: its own quantities go back to stock on save, so they count as available.
    const reservedQty = {};
    if (isOrderMode && prefill && prefill.edit_id) {
        prefill.items.forEach(it => {
            if (it.product_id) reservedQty[it.product_id] = (reservedQty[it.product_id] || 0) + Number(it.quantity || 0);
        });
    }
    function stockOf(productId) {
        const p = products.find(x => Number(x.id) === Number(productId));
        return p ? Number(p.stock) + (reservedQty[p.id] || 0) : null;
    }
    // Total quantity of one product across all lines.
    function qtyInCart(productId) {
        return quotationItems
            .filter(i => Number(i.product_id) === Number(productId))
            .reduce((s, i) => s + (Number(i.quantity) || 0), 0);
    }
    function stockCellHTML(item) {
        const stock = stockOf(item.product_id);
        if (stock === null) return '<span class="text-muted">-</span>';
        const want = qtyInCart(item.product_id);
        if (stock <= 0) {
            return `<span class="aq-stock out"><i class="bi bi-x-octagon-fill"></i> Out of stock</span>`;
        }
        if (want > stock) {
            return `<span class="aq-stock out"><i class="bi bi-exclamation-triangle-fill"></i> Only ${fmtQty(stock)} available</span>`;
        }
        return `<span class="aq-stock ok">${fmtQty(stock)} available</span>`;
    }
    // Lines asking for more than the stock (used to block saving an order).
    function shortItems() {
        return quotationItems.filter(i => {
            const s = stockOf(i.product_id);
            return s !== null && qtyInCart(i.product_id) > s + 0.0001;
        });
    }

    let quotationItems = prefill ? prefill.items : [];

    if (prefill) {
        $("quotationReference").value = prefill.reference || "";
        $("quotationRegion").value = prefill.region || "Inside AP";
        if (prefill.notes) $("quotationNotes").value = prefill.notes;
        if (prefill.date) $("quotationDate").value = prefill.date;
        if (prefill.valid_upto) $("validUpto").value = prefill.valid_upto;
        if (prefill.salesman) {
            $("salesman").value = prefill.salesman;
        }
        // Saved store (a store disabled later is still shown so it is not lost).
        if (prefill.store) {
            const st = $("quotationStore");
            if (![...st.options].some(o => o.value === prefill.store)) {
                st.add(new Option(prefill.store, prefill.store));
            }
            st.value = prefill.store;
        }
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
        // Inside state: CGST + SGST (half each). Outside state: IGST (full).
        const inter = $("quotationRegion").value === "Outside AP";
        $("totalCgst").textContent = formatMoney(totalTax / 2);
        $("totalSgst").textContent = formatMoney(totalTax / 2);
        $("totalIgst").textContent = formatMoney(totalTax);
        document.querySelectorAll('#aqTotals [data-gst="intra"]').forEach(el => el.classList.toggle("d-none", inter));
        document.querySelectorAll('#aqTotals [data-gst="inter"]').forEach(el => el.classList.toggle("d-none", !inter));
        $("grandTotal").textContent = formatMoney(grandTotal);
        $("aqTotals").classList.toggle("d-none", quotationItems.length === 0);

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

                <td class="aq-stock-cell">${stockCellHTML(item)}</td>

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
                        ${[...new Set([0, ...gstRates, Number(item.tax)])].sort((a,b)=>a-b).map(rate => `
                            <option value="${rate}"
                                ${Number(item.tax) === rate ? "selected" : ""}>
                                GST ${rate}%
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

                // Live stock check: red when the quantity is more than the stock.
                const item = quotationItems[index];
                const stockCell = row.querySelector(".aq-stock-cell");
                if (stockCell && item) {
                    stockCell.innerHTML = stockCellHTML(item);
                    const s = stockOf(item.product_id);
                    const short = s !== null && qtyInCart(item.product_id) > s + 0.0001;
                    row.classList.toggle("aq-row-short", short);
                    const qtyInput = row.querySelector('[data-field="quantity"]');
                    if (qtyInput) qtyInput.classList.toggle("aq-qty-short", short);
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

            const stock = stockOf(product.id) || 0;
            const out = stock <= 0;
            const blocked = out && isOrderMode;
            const stockBadge = out
                ? '<span class="aq-stock out"><i class="bi bi-x-octagon-fill"></i> Out of stock</span>'
                : `<span class="aq-stock ok">${fmtQty(stock)} in stock</span>`;

            if (out) button.classList.add("aq-pick-out");
            if (blocked) button.setAttribute("aria-disabled", "true");

            button.innerHTML = `
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <strong>${escapeHTML(product.name)}</strong>
                        <div class="text-muted small mt-1">
                            ${escapeHTML(product.thickness)}
                            | ${escapeHTML(product.size)}
                        </div>
                    </div>
                    <div class="text-end">
                        <strong>${formatMoney(product.rate)}</strong>
                        <div class="mt-1">${stockBadge}</div>
                    </div>
                </div>
            `;

            button.addEventListener("click", () => {
                if (blocked) {
                    App.toast(product.name + " is out of stock. Add stock in Products first.", "error");
                    return;
                }
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
                ["Store", $("quotationStore").value],
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

        // While editing, "New" leaves edit mode and opens a blank quotation.
        if (prefill && prefill.edit_id) {
            if (confirm("Leave without saving and start a new one?")) {
                window.location.href = <?php echo json_encode($isOrder ? "add-order.php" : "add-quotation.php"); ?>;
            }
            return;
        }

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

    // Clear All: remove every product line.
    $("clearAllBtn").addEventListener("click", () => {
        if (!quotationItems.length) return;
        if (!confirm("Remove all products from this <?php echo $isOrder ? 'order' : 'quotation'; ?>?")) return;
        quotationItems = [];
        $("quotationError").classList.add("d-none");
        renderQuotationItems();
    });

    // WhatsApp: send the item list + total to the selected customer.
    const customerMobiles = <?php echo json_encode(array_column($customers, 'mobile', 'id'), json_flags()); ?>;

    $("whatsappBtn").addEventListener("click", () => {
        if (!quotationItems.length) {
            showError("Add at least one product before sending on WhatsApp.");
            return;
        }
        const totals = calculateTotals();
        const select = $("customerSelect");
        const name = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text.trim() : "";
        const lines = quotationItems.map((item, i) =>
            `${i + 1}. ${item.name} - ${item.quantity} x ${formatMoney(item.rate)} = ${formatMoney(item.amount)}`
        );
        const text = [
            `*${<?php echo json_encode(COMPANY_NAME, json_flags()); ?>} - <?php echo e($L["waTitle"]); ?>*`,
            name ? `Customer: ${name}` : null,
            `Date: ${$("quotationDate").value}`,
            "",
            ...lines,
            "",
            `Subtotal: ${formatMoney(totals.subtotal)}`,
            totals.discount ? `Discount: ${formatMoney(totals.discount)}` : null,
            `Tax: ${formatMoney(totals.tax)}`,
            `*Grand Total: ${formatMoney(totals.grandTotal)}*`
        ].filter(line => line !== null).join("\n");

        let mobile = String(customerMobiles[select.value] || "").replace(/\D/g, "");
        if (mobile.length === 10) mobile = "91" + mobile;
        const url = "https://wa.me/" + (mobile ? mobile : "") + "?text=" + encodeURIComponent(text);
        window.open(url, "_blank", "noopener");
    });

    function showError(message) {
        const errorBox = $("quotationError");
        errorBox.textContent = message;
        errorBox.classList.remove("d-none");
        errorBox.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    // Save through PHP. mode = "master" (save) or "generate" (save + open printable copy).
    async function saveQuotation(mode, saveBtn) {

        const errorBox = $("quotationError");

        errorBox.classList.add("d-none");
        errorBox.textContent = "";

        if (quotationItems.length === 0) {
            showError("Please add at least one product.");
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

        // Orders cannot use more than the available stock.
        if (isOrderMode) {
            const short = shortItems();
            if (short.length) {
                const names = [...new Set(short.map(i => `${i.name} (only ${fmtQty(stockOf(i.product_id))} available)`))];
                showError("Not enough stock: " + names.join(", ") + ". Reduce the quantity.");
                return;
            }
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
            salesman: $("salesman").value.trim(),
            reference: $("quotationReference").value.trim(),
            store: $("quotationStore").value,
            region: $("quotationRegion").value,
            customer_id: Number($("customerSelect").value),
            from_quotation: prefill ? prefill.quotation_id : 0,
            edit_id: prefill ? (prefill.edit_id || 0) : 0,
            is_master: $("isMasterQuotation") ? $("isMasterQuotation").checked : false,
            notes: $("quotationNotes").value,
            mode: mode,
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

        const buttons = [$("saveQuotationBtn"), $("saveGenerateBtn")];
        const label = saveBtn.innerHTML;
        buttons.forEach(b => b.disabled = true);
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

            showError(error.message);
            buttons.forEach(b => b.disabled = false);
            saveBtn.innerHTML = label;
        }
    }

    $("quotationRegion").addEventListener("change", calculateTotals);

    $("saveQuotationBtn").addEventListener("click", function () { saveQuotation("master", this); });
    $("saveGenerateBtn").addEventListener("click", function () { saveQuotation("generate", this); });

    // Initial empty table.
    renderQuotationItems();

})();
</script>






<?php include 'footer.php'; ?>