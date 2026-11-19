<?php
/*
|--------------------------------------------------------------------------
| DIRECT INVOICE
|--------------------------------------------------------------------------
| A bill-style screen to raise a GST invoice directly (no quotation / POS).
| - Lines can be catalogue products (stock is reduced) or custom lines
|   such as cutting, fitting or transport charges.
| - Saves into orders + order_items + invoices, exactly like a POS order,
|   so Dashboard, Reports, Payments and Tally export include it.
| - After saving, opens the printable invoice (invoice-print.php).
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$customers = [];
foreach (db_all("SELECT id, name, mobile, gstin, addresses FROM customers WHERE status = 'active' ORDER BY name") as $c) {
    $addr = json_decode((string)$c['addresses'], true);
    $customers[] = [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'mobile' => $c['mobile'],
        'gstin' => $c['gstin'] ?? '',
        'address' => is_array($addr) && isset($addr[0]['address']) ? (string)$addr[0]['address'] : '',
    ];
}

$products = [];
foreach (db_all('SELECT id, name, thickness, size, hsn_code, price, tax_rate, stock, measurement_unit FROM products WHERE enabled = 1 ORDER BY name') as $p) {
    $products[] = [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'thickness' => $p['thickness'] ?? '',
        'size' => $p['size'] ?? '',
        'hsn' => $p['hsn_code'] ?? '',
        'rate' => (float)$p['price'],
        'tax' => (float)$p['tax_rate'],
        'stock' => (float)$p['stock'],
        'unit' => $p['measurement_unit'] ?? '',
    ];
}

/* ---------- SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $payload = json_decode($_POST['invoice_data'] ?? '', true);
    if (!is_array($payload)) {
        json_response(['success' => false, 'message' => 'Invalid data.'], 422);
    }

    $customerId = (int)($payload['customer_id'] ?? 0);
    $customer = db_one('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
    $date = (string)($payload['date'] ?? '');
    $due = (string)($payload['due_date'] ?? '');
    $items = $payload['items'] ?? [];

    $validDate = function (string $d): bool {
        $o = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $o && $o->format('Y-m-d') === $d;
    };

    if (!$customer) {
        json_response(['success' => false, 'message' => 'Please select a customer (add customers in the Customers page first).'], 422);
    }
    if (!$validDate($date) || !$validDate($due) || $due < $date) {
        json_response(['success' => false, 'message' => 'Check the invoice date and due date.'], 422);
    }
    if (!is_array($items) || count($items) === 0 || count($items) > 100) {
        json_response(['success' => false, 'message' => 'Add at least one line to the invoice.'], 422);
    }

    $productMap = array_column($products, null, 'id');
    $saved = [];
    $subtotal = 0.0;
    $discountTotal = 0.0;
    $taxTotal = 0.0;

    foreach ($items as $n => $item) {
        $line = $n + 1;
        $productId = (int)($item['product_id'] ?? 0);
        $qty = $item['quantity'] ?? null;
        $rate = $item['rate'] ?? null;
        $tax = $item['tax'] ?? null;
        $discount = $item['discount'] ?? 0;

        if (
            !is_numeric($qty) || !is_numeric($rate) || !is_numeric($tax) || !is_numeric($discount) ||
            (float)$qty <= 0 || (float)$qty > 1000000 ||
            (float)$rate < 0 || (float)$rate > 100000000 ||
            (float)$tax < 0 || (float)$tax > 100 ||
            (float)$discount < 0 || (float)$discount > 100
        ) {
            json_response(['success' => false, 'message' => "Line $line: check quantity, rate, discount and GST."], 422);
        }

        if ($productId) {
            if (!isset($productMap[$productId])) {
                json_response(['success' => false, 'message' => "Line $line: product not found or disabled."], 422);
            }
            $p = $productMap[$productId];
            $name = $p['name'];
            $thickness = $p['thickness'] ?: null;
            $size = $p['size'] ?: null;
            $hsn = trim((string)($item['hsn'] ?? '')) ?: ($p['hsn'] ?: null);
        } else {
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                json_response(['success' => false, 'message' => "Line $line: enter an item name."], 422);
            }
            $name = mb_substr($name, 0, 200);
            $thickness = null;
            $size = null;
            $hsn = trim((string)($item['hsn'] ?? '')) ?: null;
        }
        $hsn = $hsn !== null ? mb_substr($hsn, 0, 30) : null;

        $base = (float)$qty * (float)$rate;
        $discAmt = $base * (float)$discount / 100;
        $taxable = $base - $discAmt;
        $taxAmt = $taxable * (float)$tax / 100;

        $saved[] = [
            'product_id' => $productId ?: null,
            'name' => $name,
            'thickness' => $thickness,
            'size' => $size,
            'hsn' => $hsn,
            'quantity' => (float)$qty,
            'rate' => (float)$rate,
            'tax' => (float)$tax,
            'discount' => (float)$discount,
            'amount' => round($taxable + $taxAmt, 2),
        ];

        $subtotal += $base;
        $discountTotal += $discAmt;
        $taxTotal += $taxAmt;
    }

    $exact = round($subtotal - $discountTotal + $taxTotal, 2);
    $total = !empty($payload['round_off']) ? round($exact) : $exact;
    $region = ($payload['region'] ?? '') === 'Outside AP' ? 'Outside AP' : 'Inside AP';
    $reference = mb_substr(trim((string)($payload['reference'] ?? '')), 0, 150);
    $notes = mb_substr((string)($payload['notes'] ?? ''), 0, 5000);
    $dispatch = [
        'eway_bill' => mb_substr(trim((string)($payload['eway_bill'] ?? '')), 0, 50) ?: null,
        'vehicle_no' => mb_substr(trim((string)($payload['vehicle_no'] ?? '')), 0, 50) ?: null,
        'dispatched_through' => mb_substr(trim((string)($payload['dispatched_through'] ?? '')), 0, 150) ?: null,
    ];

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orderNumber = next_number('orders', 'order_number', 'ORD-');
        $invoiceNumber = next_number('invoices', 'invoice_number', 'SI-');

        $orderId = db_insert('orders', [
            'order_number' => $orderNumber,
            'date' => $date,
            'delivery_date' => $due,
            'customer_id' => $customerId,
            'customer' => $customer['name'],
            'salesman' => $_SESSION['user_name'] ?? null,
            'store' => APP_STORE,
            'reference' => $reference !== '' ? $reference : 'Direct Invoice',
            'region' => $region,
            'notes' => $notes,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountTotal, 2),
            'tax' => round($taxTotal, 2),
            'amount' => $total,
            'status' => 'Invoice Raised',
            'created_by' => (int)$_SESSION['user_id'],
        ]);

        foreach ($saved as $si) {
            db_insert('order_items', ['order_id' => $orderId] + $si);
            if ($si['product_id']) {
                db_exec('UPDATE products SET stock = stock - ? WHERE id = ?', [$si['quantity'], $si['product_id']]);
            }
        }

        $invoiceId = db_insert('invoices', [
            'invoice_number' => $invoiceNumber,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'customer' => $customer['name'],
            'date' => $date,
            'due_date' => $due,
            'region' => $region,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountTotal, 2),
            'tax' => round($taxTotal, 2),
            'amount' => $total,
            'status' => 'Submitted',
        ] + $dispatch);

        db_update('orders', ['invoice_id' => $invoiceId], $orderId);
        $pdo->commit();

        log_activity('Invoices', "Direct invoice $invoiceNumber created for {$customer['name']} (Rs " . number_format($total, 2) . ')');

        json_response([
            'success' => true,
            'invoice_number' => $invoiceNumber,
            'redirect' => !empty($payload['print'])
                ? 'invoice-print.php?id=' . $invoiceId . '&new=1'
                : 'invoices.php?saved=' . urlencode($invoiceNumber),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'Unable to save: ' . $e->getMessage()], 500);
    }
}

$nextInvoice = next_number('invoices', 'invoice_number', 'SI-');
$today = date('Y-m-d');
$dueDefault = date('Y-m-d', strtotime('+15 days'));

$pageTitle = 'Direct Invoice';
include 'header.php';
?>

<style>
.direct-invoice-section {
    background: #f6f8fd;
    min-height: 100vh;
    font-family: Arial, sans-serif;
    color: #142238;
}

.direct-invoice-section .di-title { font-size: 24px; font-weight: 750; margin: 0 0 4px; }
.direct-invoice-section .di-subtitle { color: #64748b; font-size: 14px; margin: 0; }

.direct-invoice-section .di-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 15px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 700;
    border: 1px solid #dfe6ef;
    background: #fff;
    color: #293b55;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}
.direct-invoice-section .di-btn:hover { background: #f3f7fd; color: #1479ff; }
.direct-invoice-section .di-btn-primary { background: #1479ff; border-color: #1479ff; color: #fff; }
.direct-invoice-section .di-btn-primary:hover { background: #0868e6; color: #fff; }
.direct-invoice-section .di-btn:disabled { opacity: .6; cursor: wait; }

/* The paper */
.direct-invoice-section .di-sheet {
    max-width: 1180px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #dfe6ef;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(15, 23, 42, .06);
    overflow: hidden;
}

.direct-invoice-section .di-sheet-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    padding: 22px 26px;
    background: linear-gradient(120deg, #06283e 0%, #0b4a70 100%);
    color: #fff;
}
.direct-invoice-section .di-company { font-size: 20px; font-weight: 800; letter-spacing: .5px; margin: 0; }
.direct-invoice-section .di-company-sub { font-size: 12px; font-weight: 700; color: #ffc44d; letter-spacing: 1.5px; margin: 2px 0 6px; }
.direct-invoice-section .di-company-meta { font-size: 12.5px; color: #c7dbea; margin: 0; }

.direct-invoice-section .di-doc { text-align: right; }
.direct-invoice-section .di-doc-title { font-size: 22px; font-weight: 800; letter-spacing: 3px; margin: 0 0 4px; }
.direct-invoice-section .di-doc-no {
    display: inline-block;
    font-size: 13px;
    font-weight: 700;
    background: rgba(255, 255, 255, .14);
    border-radius: 20px;
    padding: 4px 12px;
}

.direct-invoice-section .di-body { padding: 22px 26px; }

.direct-invoice-section .di-block {
    border: 1px solid #e6ebf2;
    border-radius: 12px;
    padding: 14px 16px;
    height: 100%;
}
.direct-invoice-section .di-block-title {
    font-size: 11.5px;
    font-weight: 800;
    color: #64748b;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.direct-invoice-section .di-label { font-size: 12.5px; font-weight: 700; color: #4b5a72; margin-bottom: 4px; display: block; }
.direct-invoice-section .di-input,
.direct-invoice-section .di-select {
    width: 100%;
    height: 38px;
    border: 1px solid #dfe6ef;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 14px;
    color: #18283d;
    background: #fff;
    outline: none;
}
.direct-invoice-section .di-input:focus,
.direct-invoice-section .di-select:focus { border-color: #1479ff; box-shadow: 0 0 0 3px rgba(20, 121, 255, .1); }

.direct-invoice-section .di-customer-info { font-size: 13px; color: #4b5a72; line-height: 1.55; margin-top: 8px; min-height: 40px; }
.direct-invoice-section .di-customer-info strong { color: #142238; }

/* Items */
.direct-invoice-section .di-items-wrap { margin-top: 20px; border: 1px solid #e6ebf2; border-radius: 12px; overflow: auto; }
.direct-invoice-section .di-items { width: 100%; min-width: 920px; border-collapse: collapse; }
.direct-invoice-section .di-items thead th {
    background: #f3f6fb;
    font-size: 12px !important;
    font-weight: 800;
    color: #4b5a72;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 10px 8px !important;
    white-space: nowrap;
    border-bottom: 1px solid #e6ebf2;
}
.direct-invoice-section .di-items tbody td {
    padding: 8px !important;
    border-bottom: 1px dashed #edf1f6;
    vertical-align: middle;
}
.direct-invoice-section .di-items tbody tr:last-child td { border-bottom: 0; }
.direct-invoice-section .di-items .di-input { height: 34px; font-size: 13.5px !important; }
.direct-invoice-section .di-items .num { text-align: right; }
.direct-invoice-section .di-line-no { color: #94a3b8; font-weight: 700; text-align: center; width: 36px; }
.direct-invoice-section .di-line-meta { font-size: 11.5px; color: #8a97a8; margin-top: 3px; min-height: 14px; }
.direct-invoice-section .di-line-amount { font-weight: 750; text-align: right; white-space: nowrap; }
.direct-invoice-section .di-remove { border: 0; background: transparent; color: #ef4444; font-size: 16px; padding: 4px 6px; }
.direct-invoice-section .di-remove:hover { color: #b91c1c; }
.direct-invoice-section .di-add-line {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 11px;
    border: 0;
    border-top: 1px solid #e6ebf2;
    background: #fafcff;
    color: #1479ff;
    font-weight: 700;
    font-size: 14px;
}
.direct-invoice-section .di-add-line:hover { background: #eef5ff; }

/* Totals */
.direct-invoice-section .di-words {
    background: #f6f9fe;
    border: 1px dashed #c9d8ee;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
    color: #33465e;
}
.direct-invoice-section .di-words strong { display: block; font-size: 11.5px; color: #64748b; letter-spacing: .8px; text-transform: uppercase; margin-bottom: 2px; }
.direct-invoice-section textarea.di-input { height: 96px; resize: vertical; }

.direct-invoice-section .di-totals { border: 1px solid #e6ebf2; border-radius: 12px; overflow: hidden; }
.direct-invoice-section .di-total-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 14px;
    font-size: 13.5px;
    color: #4b5a72;
}
.direct-invoice-section .di-total-row strong { color: #142238; white-space: nowrap; }
.direct-invoice-section .di-total-row.sep { border-top: 1px solid #edf1f6; }
.direct-invoice-section .di-grand {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    background: #06283e;
    color: #fff;
    font-size: 15px;
    font-weight: 700;
}
.direct-invoice-section .di-grand strong { font-size: 20px; }
.direct-invoice-section .form-check-label { font-size: 13px; }

.direct-invoice-section .di-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 26px;
    border-top: 1px solid #e6ebf2;
    background: #fbfcfe;
}
.direct-invoice-section .di-hint { font-size: 12.5px; color: #64748b; }

@media (max-width: 767px) {
    .direct-invoice-section .di-sheet-head,
    .direct-invoice-section .di-body,
    .direct-invoice-section .di-footer { padding-left: 14px; padding-right: 14px; }
    .direct-invoice-section .di-doc { text-align: left; }
}
</style>

<section class="direct-invoice-section">
<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-3">
        <div class="col-md-7">
            <h1 class="di-title">Direct Invoice</h1>
            <p class="di-subtitle">Raise a GST invoice directly, the way it will be printed.</p>
        </div>
        <div class="col-md-5 text-md-end">
            <a href="invoices.php" class="di-btn"><i class="bi bi-receipt"></i> All Invoices</a>
        </div>
    </div>

    <div class="di-sheet">

        <!-- LETTERHEAD -->
        <div class="di-sheet-head">
            <div>
                <p class="di-company">GULLAPUDI</p>
                <p class="di-company-sub">GLASS &amp; PLYWOOD CENTRE</p>
                <p class="di-company-meta">
                    <?php echo e(implode('  |  ', array_filter([COMPANY_PHONE, COMPANY_GSTIN ? 'GSTIN: ' . COMPANY_GSTIN : '', COMPANY_ADDRESS]))); ?>
                </p>
            </div>
            <div class="di-doc">
                <p class="di-doc-title">TAX INVOICE</p>
                <span class="di-doc-no">No. <?php echo e($nextInvoice); ?> <span class="opacity-75">(auto)</span></span>
            </div>
        </div>

        <div class="di-body">

            <div class="row g-3">

                <!-- BILL TO -->
                <div class="col-lg-6">
                    <div class="di-block">
                        <div class="di-block-title">Bill To</div>
                        <select id="diCustomer" class="di-select">
                            <option value="">
                                <?php echo $customers ? 'Select customer' : 'No customers yet - add one in Customers'; ?>
                            </option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo e($c['name'] . ($c['mobile'] ? ' - ' . $c['mobile'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="di-customer-info" id="diCustomerInfo">
                            Choose the customer to bill. Mobile, GSTIN and address appear here.
                        </div>
                    </div>
                </div>

                <!-- INVOICE DETAILS -->
                <div class="col-lg-6">
                    <div class="di-block">
                        <div class="di-block-title">Invoice Details</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="di-label" for="diDate">Invoice Date</label>
                                <input type="date" id="diDate" class="di-input" value="<?php echo e($today); ?>">
                            </div>
                            <div class="col-6">
                                <label class="di-label" for="diDue">Due Date</label>
                                <input type="date" id="diDue" class="di-input" value="<?php echo e($dueDefault); ?>">
                            </div>
                            <div class="col-6">
                                <label class="di-label" for="diRegion">Place of Supply</label>
                                <select id="diRegion" class="di-select">
                                    <option value="Inside AP">Inside AP (CGST + SGST)</option>
                                    <option value="Outside AP">Outside AP (IGST)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="di-label" for="diReference">Reference / PO No.</label>
                                <input type="text" id="diReference" class="di-input" maxlength="150" placeholder="Optional">
                            </div>
                            <div class="col-4">
                                <label class="di-label" for="diEway">e-Way Bill No.</label>
                                <input type="text" id="diEway" class="di-input" maxlength="50" placeholder="Optional">
                            </div>
                            <div class="col-4">
                                <label class="di-label" for="diVehicle">Vehicle No.</label>
                                <input type="text" id="diVehicle" class="di-input" maxlength="50" placeholder="Optional">
                            </div>
                            <div class="col-4">
                                <label class="di-label" for="diDispatch">Dispatched through</label>
                                <input type="text" id="diDispatch" class="di-input" maxlength="150" placeholder="e.g. Transport name">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ITEMS -->
            <div class="di-items-wrap">
                <table class="di-items">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="min-width:260px">Item / Description</th>
                            <th style="width:110px">HSN</th>
                            <th style="width:90px" class="num">Qty</th>
                            <th style="width:120px" class="num">Rate</th>
                            <th style="width:85px" class="num">Disc %</th>
                            <th style="width:85px" class="num">GST %</th>
                            <th style="width:130px" class="num">Amount</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="diLines"></tbody>
                </table>
                <button type="button" class="di-add-line" id="diAddLine">
                    <i class="bi bi-plus-circle"></i> Add Line
                </button>
            </div>

            <datalist id="diProducts">
                <?php foreach ($products as $p): ?>
                    <option value="<?php echo e($p['name']); ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <!-- WORDS + TOTALS -->
            <div class="row g-3 mt-1">
                <div class="col-lg-7">
                    <div class="di-words mb-3">
                        <strong>Amount in words</strong>
                        <span id="diWords">Zero Rupees Only</span>
                    </div>
                    <label class="di-label" for="diNotes">Terms &amp; Notes</label>
                    <textarea id="diNotes" class="di-input">1. Goods once sold will not be taken back.
2. Transportation extra.
3. Payment within 15 days.</textarea>
                </div>

                <div class="col-lg-5">
                    <div class="di-totals">
                        <div class="di-total-row"><span>Sub Total</span><strong id="tSub">Rs 0.00</strong></div>
                        <div class="di-total-row"><span>Discount</span><strong id="tDisc">- Rs 0.00</strong></div>
                        <div class="di-total-row sep"><span>Taxable Value</span><strong id="tTaxable">Rs 0.00</strong></div>
                        <div class="di-total-row" data-gst="intra"><span>CGST</span><strong id="tCgst">Rs 0.00</strong></div>
                        <div class="di-total-row" data-gst="intra"><span>SGST</span><strong id="tSgst">Rs 0.00</strong></div>
                        <div class="di-total-row d-none" data-gst="inter"><span>IGST</span><strong id="tIgst">Rs 0.00</strong></div>
                        <div class="di-total-row sep">
                            <span class="form-check m-0">
                                <input class="form-check-input" type="checkbox" id="diRound" checked>
                                <label class="form-check-label" for="diRound">Round Off</label>
                            </span>
                            <strong id="tRound">Rs 0.00</strong>
                        </div>
                        <div class="di-grand"><span>Grand Total</span><strong id="tGrand">Rs 0.00</strong></div>
                    </div>
                </div>
            </div>

            <div id="diError" class="alert alert-danger d-none mt-3 mb-0" role="alert"></div>

        </div>

        <div class="di-footer">
            <span class="di-hint"><i class="bi bi-info-circle me-1"></i>Pick a product from the list to fill in rate, HSN and GST, or type any custom line.</span>
            <div class="d-flex gap-2">
                <a href="invoices.php" class="di-btn">Cancel</a>
                <button type="button" class="di-btn" id="diSave"><i class="bi bi-floppy"></i> Save</button>
                <button type="button" class="di-btn di-btn-primary" id="diSavePrint"><i class="bi bi-printer"></i> Save &amp; Print</button>
            </div>
        </div>

    </div>

</div>
</section>

<script>
(function () {
    "use strict";

    const customers = <?php echo json_encode($customers, json_flags()); ?>;
    const products = <?php echo json_encode($products, json_flags()); ?>;
    const productByName = new Map(products.map(p => [p.name.toLowerCase(), p]));

    const $ = id => document.getElementById(id);
    const lines = $("diLines");

    const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const num = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[c]));

    /* ---- Amount in words (Indian system) ---- */
    const ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve",
        "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    const tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
    function two(n) { return n < 20 ? ones[n] : tens[Math.floor(n / 10)] + (n % 10 ? " " + ones[n % 10] : ""); }
    function three(n) { return (n >= 100 ? ones[Math.floor(n / 100)] + " Hundred" + (n % 100 ? " " : "") : "") + (n % 100 ? two(n % 100) : ""); }
    function words(n) {
        n = Math.floor(n);
        if (n === 0) return "Zero";
        const parts = [];
        const crore = Math.floor(n / 10000000); n %= 10000000;
        const lakh = Math.floor(n / 100000); n %= 100000;
        const thousand = Math.floor(n / 1000); n %= 1000;
        if (crore) parts.push(words(crore) + " Crore");
        if (lakh) parts.push(two(lakh) + " Lakh");
        if (thousand) parts.push(two(thousand) + " Thousand");
        if (n) parts.push(three(n));
        return parts.join(" ");
    }
    function amountWords(v) {
        const rupees = Math.floor(v + 1e-9);
        const paise = Math.round((v - rupees) * 100);
        return words(rupees) + " Rupees" + (paise ? " and " + two(paise) + " Paise" : "") + " Only";
    }

    /* ---- Lines ---- */
    function addLine() {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="di-line-no"></td>
            <td>
                <input type="text" class="di-input" data-f="name" list="diProducts" maxlength="200" placeholder="Search product or type a custom item">
                <div class="di-line-meta" data-f="meta"></div>
            </td>
            <td><input type="text" class="di-input" data-f="hsn" maxlength="30"></td>
            <td><input type="number" class="di-input num" data-f="quantity" value="1" min="0" step="any"></td>
            <td><input type="number" class="di-input num" data-f="rate" value="0" min="0" step="any"></td>
            <td><input type="number" class="di-input num" data-f="discount" value="0" min="0" max="100" step="any"></td>
            <td><input type="number" class="di-input num" data-f="tax" value="18" min="0" max="100" step="any"></td>
            <td class="di-line-amount" data-f="amount">Rs 0.00</td>
            <td><button type="button" class="di-remove" title="Remove line"><i class="bi bi-x-circle-fill"></i></button></td>`;
        tr.dataset.productId = "";
        lines.appendChild(tr);
        renumber();
        recalc();
        return tr;
    }

    function renumber() {
        [...lines.rows].forEach((tr, i) => { tr.cells[0].textContent = i + 1; });
    }

    function field(tr, f) { return tr.querySelector(`[data-f="${f}"]`); }

    function applyProduct(tr) {
        const p = productByName.get(field(tr, "name").value.trim().toLowerCase());
        const meta = field(tr, "meta");
        if (p) {
            tr.dataset.productId = p.id;
            field(tr, "hsn").value = p.hsn || "";
            field(tr, "rate").value = p.rate;
            field(tr, "tax").value = p.tax;
            const bits = [p.thickness, p.size, p.unit].filter(Boolean).join(" · ");
            meta.innerHTML = (bits ? esc(bits) + " · " : "") +
                `<span class="${p.stock <= 0 ? "text-danger" : ""}">Stock: ${esc(p.stock)}</span>`;
        } else {
            tr.dataset.productId = "";
            meta.textContent = field(tr, "name").value.trim() ? "Custom line (no stock change)" : "";
        }
    }

    function recalc() {
        let sub = 0, disc = 0, tax = 0;
        [...lines.rows].forEach(tr => {
            const base = num(field(tr, "quantity").value) * num(field(tr, "rate").value);
            const d = base * num(field(tr, "discount").value) / 100;
            const t = (base - d) * num(field(tr, "tax").value) / 100;
            field(tr, "amount").textContent = money(base - d + t);
            sub += base; disc += d; tax += t;
        });
        const taxable = sub - disc;
        const exact = Math.round((taxable + tax) * 100) / 100;
        const grand = $("diRound").checked ? Math.round(exact) : exact;
        const inter = $("diRegion").value === "Outside AP";

        $("tSub").textContent = money(sub);
        $("tDisc").textContent = "- " + money(disc);
        $("tTaxable").textContent = money(taxable);
        $("tCgst").textContent = money(tax / 2);
        $("tSgst").textContent = money(tax / 2);
        $("tIgst").textContent = money(tax);
        document.querySelectorAll('[data-gst="intra"]').forEach(el => el.classList.toggle("d-none", inter));
        document.querySelectorAll('[data-gst="inter"]').forEach(el => el.classList.toggle("d-none", !inter));
        const r = grand - exact;
        $("tRound").textContent = (r < 0 ? "- " : "+ ") + money(Math.abs(r));
        $("tGrand").textContent = money(grand);
        $("diWords").textContent = amountWords(grand);
    }

    lines.addEventListener("input", e => {
        const tr = e.target.closest("tr");
        if (e.target.dataset.f === "name") applyProduct(tr);
        recalc();
    });
    lines.addEventListener("click", e => {
        const btn = e.target.closest(".di-remove");
        if (!btn) return;
        btn.closest("tr").remove();
        if (!lines.rows.length) addLine();
        renumber();
        recalc();
    });
    $("diAddLine").addEventListener("click", () => field(addLine(), "name").focus());
    $("diRegion").addEventListener("change", recalc);
    $("diRound").addEventListener("change", recalc);

    $("diCustomer").addEventListener("change", function () {
        const c = customers.find(x => String(x.id) === this.value);
        $("diCustomerInfo").innerHTML = c
            ? `<strong>${esc(c.name)}</strong><br>` +
              (c.mobile ? `Mobile: ${esc(c.mobile)}<br>` : "") +
              (c.gstin ? `GSTIN: ${esc(c.gstin)}<br>` : "") +
              (c.address ? esc(c.address) : "")
            : "Choose the customer to bill. Mobile, GSTIN and address appear here.";
    });

    /* ---- Save ---- */
    function showError(msg) {
        $("diError").textContent = msg;
        $("diError").classList.remove("d-none");
        $("diError").scrollIntoView({ behavior: "smooth", block: "center" });
    }

    async function save(print) {
        $("diError").classList.add("d-none");
        const items = [...lines.rows]
            .filter(tr => field(tr, "name").value.trim() !== "")
            .map(tr => ({
                product_id: tr.dataset.productId ? Number(tr.dataset.productId) : null,
                name: field(tr, "name").value.trim(),
                hsn: field(tr, "hsn").value.trim(),
                quantity: field(tr, "quantity").value,
                rate: field(tr, "rate").value,
                discount: field(tr, "discount").value || 0,
                tax: field(tr, "tax").value || 0
            }));

        if (!$("diCustomer").value) return showError("Please select a customer.");
        if (!items.length) return showError("Add at least one item line.");

        const buttons = [$("diSave"), $("diSavePrint")];
        buttons.forEach(b => b.disabled = true);
        try {
            const result = await App.post(window.location.pathname, {
                invoice_data: JSON.stringify({
                    customer_id: Number($("diCustomer").value),
                    date: $("diDate").value,
                    due_date: $("diDue").value,
                    region: $("diRegion").value,
                    reference: $("diReference").value,
                    eway_bill: $("diEway").value,
                    vehicle_no: $("diVehicle").value,
                    dispatched_through: $("diDispatch").value,
                    notes: $("diNotes").value,
                    round_off: $("diRound").checked,
                    print: print,
                    items: items
                })
            });
            window.location.href = result.redirect;
        } catch (err) {
            showError(err.message);
            buttons.forEach(b => b.disabled = false);
        }
    }

    $("diSave").addEventListener("click", () => save(false));
    $("diSavePrint").addEventListener("click", () => save(true));

    addLine();
})();
</script>

<?php include 'footer.php'; ?>
