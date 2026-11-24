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

/* Edit an existing invoice: direct-invoice.php?edit=INVOICE_ID */
$editId = (int)($_GET['edit'] ?? 0);
$editInvoice = null;
if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$editId]);
    if (!$inv) {
        header('Location: invoices.php');
        exit;
    }
    $ord = $inv['order_id'] ? db_one('SELECT * FROM orders WHERE id = ?', [(int)$inv['order_id']]) : null;
    $lines = $ord ? db_all('SELECT product_id, name, hsn, quantity, rate, tax, discount FROM order_items WHERE order_id = ? ORDER BY id', [(int)$ord['id']]) : [];
    $editInvoice = [
        'id' => (int)$inv['id'],
        'number' => $inv['invoice_number'],
        'status' => $inv['status'],
        'customer_id' => (int)$inv['customer_id'],
        'date' => $inv['date'],
        'due_date' => $inv['due_date'] ?: $inv['date'],
        'region' => $inv['region'] ?: 'Inside AP',
        'reference' => ($ord['reference'] ?? '') === 'Direct Invoice' ? '' : (string)($ord['reference'] ?? ''),
        'notes' => (string)($ord['notes'] ?? ''),
        'eway_bill' => (string)$inv['eway_bill'],
        'vehicle_no' => (string)$inv['vehicle_no'],
        'dispatched_through' => (string)$inv['dispatched_through'],
        'round_off' => abs((float)$inv['amount'] - round((float)$inv['amount'])) < 0.001,
        'paid' => (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$inv['invoice_number']]),
        'items' => array_map(fn($l) => [
            'product_id' => $l['product_id'] ? (int)$l['product_id'] : null,
            'name' => $l['name'],
            'hsn' => (string)$l['hsn'],
            'quantity' => (float)$l['quantity'],
            'rate' => (float)$l['rate'],
            'tax' => (float)$l['tax'],
            'discount' => (float)$l['discount'],
        ], $lines),
    ];
}

$gstRateList = array_values(array_unique(array_column(gst_rates(), 'rate')));

$products = [];
foreach (db_all('SELECT id, name, thickness, size, hsn_code, price, tax_rate, stock, measurement_unit, enabled FROM products ORDER BY name') as $p) {
    $products[] = [
        'enabled' => (bool)$p['enabled'],
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

    // Customer: an existing one (customer_id) or a new one typed on the invoice (new_customer).
    $newCustomer = null;
    if (is_array($payload['new_customer'] ?? null)) {
        $nc = $payload['new_customer'];
        $newCustomer = [
            'name' => mb_substr(trim((string)($nc['name'] ?? '')), 0, 200),
            'mobile' => preg_replace('/\D/', '', (string)($nc['mobile'] ?? '')),
            'gstin' => strtoupper(mb_substr(trim((string)($nc['gstin'] ?? '')), 0, 20)),
            'address' => mb_substr(trim((string)($nc['address'] ?? '')), 0, 500),
        ];
        if ($newCustomer['name'] === '') {
            json_response(['success' => false, 'message' => 'Enter the customer name.'], 422);
        }
        if (!preg_match('/^[0-9]{10}$/', $newCustomer['mobile'])) {
            json_response(['success' => false, 'message' => 'Enter a valid 10-digit mobile number for the customer.'], 422);
        }
        // Same mobile already saved -> use that customer instead of creating a duplicate.
        $customer = db_one('SELECT id, name FROM customers WHERE mobile = ? ORDER BY id LIMIT 1', [$newCustomer['mobile']]);
        if ($customer) {
            $newCustomer = null;
        }
        $customerId = $customer ? (int)$customer['id'] : 0;
    } else {
        $customerId = (int)($payload['customer_id'] ?? 0);
        $customer = db_one('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
    }
    $date = (string)($payload['date'] ?? '');
    $due = (string)($payload['due_date'] ?? '');
    $items = $payload['items'] ?? [];

    $validDate = function (string $d): bool {
        $o = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $o && $o->format('Y-m-d') === $d;
    };

    if (!$customer && !$newCustomer) {
        json_response(['success' => false, 'message' => 'Please select a customer, or choose "New Customer" and type the details.'], 422);
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
        $isService = ($item['type'] ?? '') === 'service';
        $productId = $isService ? 0 : (int)($item['product_id'] ?? 0);
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
                json_response(['success' => false, 'message' => "Line $line: enter " . ($isService ? 'the service description.' : 'an item name.')], 422);
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
        // New customer typed on the invoice: save it to Customers (same transaction).
        if ($newCustomer) {
            $customerId = db_insert('customers', [
                'name' => $newCustomer['name'],
                'mobile' => $newCustomer['mobile'],
                'gstin' => $newCustomer['gstin'] ?: null,
                'addresses' => json_encode($newCustomer['address'] !== ''
                    ? [['label' => 'Billing', 'address' => $newCustomer['address']]]
                    : []),
                'status' => 'active',
            ]);
            $customer = ['id' => $customerId, 'name' => $newCustomer['name']];
            log_activity('Customers', 'Added customer ' . $newCustomer['name'] . ' (from Direct Invoice)');
        }

        $totals = [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountTotal, 2),
            'tax' => round($taxTotal, 2),
            'amount' => $total,
        ];
        $editInvoiceId = (int)($payload['edit_id'] ?? 0);

        if ($editInvoiceId) {
            /* ---------- UPDATE an existing invoice (number stays the same) ---------- */
            $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$editInvoiceId]);
            if (!$inv) {
                throw new RuntimeException('Invoice not found.');
            }
            $invoiceId = (int)$inv['id'];
            $invoiceNumber = $inv['invoice_number'];
            $paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
            if ($paid > $total + 0.009) {
                throw new RuntimeException('Rs ' . number_format($paid, 2) . ' is already paid on this invoice, more than the new total. Remove a payment first.');
            }
            $orderId = (int)$inv['order_id'];
            if (!$orderId || !db_value('SELECT COUNT(*) FROM orders WHERE id = ?', [$orderId])) {
                $orderId = db_insert('orders', [
                    'order_number' => next_number('orders', 'order_number', 'ORD-'),
                    'date' => $date,
                    'status' => 'Invoice Raised',
                    'store' => APP_STORE,
                    'created_by' => (int)$_SESSION['user_id'],
                    'invoice_id' => $invoiceId,
                ]);
            }
            db_update('orders', [
                'date' => $date,
                'delivery_date' => $due,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'reference' => $reference !== '' ? $reference : 'Direct Invoice',
                'region' => $region,
                'notes' => $notes,
            ] + $totals, $orderId);
            order_replace_items($orderId, $saved); // stock of old lines returned, new lines taken

            $status = in_array($inv['status'], ['Draft', 'Cancelled'], true) ? $inv['status'] : 'Submitted';
            db_update('invoices', [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'date' => $date,
                'due_date' => $due,
                'region' => $region,
                'status' => $status,
            ] + $totals + $dispatch, $invoiceId);
            refresh_invoice_status($invoiceNumber);
            $logText = "Invoice $invoiceNumber updated for {$customer['name']} (Rs " . number_format($total, 2) . ')';
        } else {
            /* ---------- NEW invoice ---------- */
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
                'status' => 'Invoice Raised',
                'created_by' => (int)$_SESSION['user_id'],
            ] + $totals);

            order_replace_items($orderId, $saved);

            $invoiceId = db_insert('invoices', [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'date' => $date,
                'due_date' => $due,
                'region' => $region,
                'status' => 'Submitted',
            ] + $totals + $dispatch);

            db_update('orders', ['invoice_id' => $invoiceId], $orderId);
            $logText = "Direct invoice $invoiceNumber created for {$customer['name']} (Rs " . number_format($total, 2) . ')';
        }

        // Advance / amount paid now (optional).
        $advAmount = is_numeric($payload['advance_amount'] ?? null) ? round(max(0, (float)$payload['advance_amount']), 2) : 0;
        if ($advAmount > 0) {
            $paidSoFar = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
            if ($advAmount > round($total - $paidSoFar, 2) + 0.009) {
                throw new RuntimeException('Amount paid now is more than the invoice balance.');
            }
            $advDate = (string)($payload['advance_date'] ?? '');
            $modes = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Card'];
            db_insert('payments', [
                'date' => $validDate($advDate) ? $advDate : date('Y-m-d'),
                'type' => 'Advance',
                'invoice' => $invoiceNumber,
                'invoice_id' => $invoiceId,
                'customer_id' => $customerId,
                'customer' => $customer['name'],
                'narration' => next_number('payments', 'narration', 'PAY-'),
                'mode' => in_array($payload['advance_mode'] ?? '', $modes, true) ? $payload['advance_mode'] : 'Cash',
                'amount' => $advAmount,
                'notes' => 'Paid at billing',
            ]);
            refresh_invoice_status($invoiceNumber);
        }

        $pdo->commit();

        log_activity('Invoices', $logText);

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

$pageTitle = $editInvoice ? 'Edit Invoice ' . $editInvoice['number'] : 'Direct Invoice';
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
.direct-invoice-section .di-block-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.direct-invoice-section .di-switch {
    display: inline-flex;
    background: #f1f4f9;
    border-radius: 8px;
    padding: 3px;
}
.direct-invoice-section .di-switch button {
    border: 0;
    background: transparent;
    padding: 5px 11px;
    border-radius: 6px;
    font-size: 12.5px;
    font-weight: 700;
    color: #5b6b82;
    white-space: nowrap;
}
.direct-invoice-section .di-switch button.active {
    background: #fff;
    color: #1479ff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
}
.direct-invoice-section .di-upper { text-transform: uppercase; }
.direct-invoice-section .di-upper::placeholder { text-transform: none; }
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
.direct-invoice-section .di-items { width: 100%; min-width: 1020px; border-collapse: collapse; }
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
.direct-invoice-section .di-stock-out { display:inline-flex; align-items:center; gap:4px; color:#c2343c; background:#fde8e8; font-weight:700; border-radius:20px; padding:1px 8px; }
.direct-invoice-section .di-stock-ok { color:#0f7a3d; font-weight:700; }
.direct-invoice-section .di-items tr.di-row-short td, .direct-invoice-section .di-items tr.di-row-out td { background:#fff6f6; }
.direct-invoice-section .di-items .di-input.di-qty-short { border-color:#e0444c !important; color:#c2343c; font-weight:700; box-shadow:0 0 0 3px rgba(224,68,76,.12); }
.direct-invoice-section .di-items tr.di-row-out .di-input:disabled { background:#f6eded; color:#a88; cursor:not-allowed; }
.direct-invoice-section .di-line-amount { font-weight: 750; text-align: right; white-space: nowrap; }
.direct-invoice-section .di-remove { border: 0; background: transparent; color: #ef4444; font-size: 16px; padding: 4px 6px; }
.direct-invoice-section .di-remove:hover { color: #b91c1c; }
.direct-invoice-section .di-type { height: 34px; font-size: 13px !important; font-weight: 700; padding: 4px 6px; }
.direct-invoice-section tr[data-type="product"] .di-type { color: #1d4ed8; background: #eff5ff; border-color: #cfe0ff; }
.direct-invoice-section tr[data-type="service"] .di-type { color: #7c3aed; background: #f5f0ff; border-color: #e2d6fb; }
.direct-invoice-section .di-add-row { display: flex; border-top: 1px solid #e6ebf2; }
.direct-invoice-section .di-add-row .di-add-line { border-top: 0; }
.direct-invoice-section .di-add-row .di-add-line + .di-add-line { border-left: 1px solid #e6ebf2; }
.direct-invoice-section .di-add-line.service { color: #7c3aed; }
.direct-invoice-section .di-add-line.service:hover { background: #f7f2ff; }
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
            <?php if ($editInvoice): ?>
                <h1 class="di-title">Edit Invoice <?php echo e($editInvoice['number']); ?></h1>
                <p class="di-subtitle">Change customer, products, dispatch details - the invoice number stays the same and stock is corrected.</p>
            <?php else: ?>
                <h1 class="di-title">Direct Invoice</h1>
                <p class="di-subtitle">Raise a GST invoice directly, the way it will be printed.</p>
            <?php endif; ?>
        </div>
        <div class="col-md-5 text-md-end">
            <a href="invoices.php" class="di-btn"><i class="bi bi-receipt"></i> All Invoices</a>
        </div>
    </div>

    <div class="di-sheet">

        <!-- LETTERHEAD -->
        <div class="di-sheet-head">
            <div>
                <p class="di-company"><?php echo e(COMPANY_SHORT); ?></p>
                <?php if (COMPANY_TAGLINE !== ''): ?><p class="di-company-sub"><?php echo e(COMPANY_TAGLINE); ?></p><?php endif; ?>
                <p class="di-company-meta">
                    <?php echo e(implode('  |  ', array_filter([COMPANY_PHONE, COMPANY_GSTIN ? 'GSTIN: ' . COMPANY_GSTIN : '', COMPANY_ADDRESS]))); ?>
                </p>
            </div>
            <div class="di-doc">
                <p class="di-doc-title">TAX INVOICE</p>
                <?php if ($editInvoice): ?>
                    <span class="di-doc-no">No. <?php echo e($editInvoice['number']); ?> <span class="opacity-75">(editing)</span></span>
                <?php else: ?>
                    <span class="di-doc-no">No. <?php echo e($nextInvoice); ?> <span class="opacity-75">(auto)</span></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="di-body">

            <div class="row g-3">

                <!-- BILL TO -->
                <div class="col-lg-6">
                    <div class="di-block">
                        <div class="di-block-head">
                            <div class="di-block-title mb-0">Bill To</div>
                            <div class="di-switch" role="tablist" aria-label="Customer type">
                                <button type="button" class="active" data-cust-mode="existing" role="tab" aria-selected="true">Existing Customer</button>
                                <button type="button" data-cust-mode="new" role="tab" aria-selected="false"><i class="bi bi-plus-lg"></i> New Customer</button>
                            </div>
                        </div>

                        <!-- NEW CUSTOMER (typed manually) -->
                        <div id="diNewCustomer" class="d-none">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="di-label" for="ncName">Customer Name <span class="text-danger">*</span></label>
                                    <input type="text" id="ncName" class="di-input" maxlength="200" placeholder="Customer / firm name">
                                </div>
                                <div class="col-sm-6">
                                    <label class="di-label" for="ncMobile">Mobile <span class="text-danger">*</span></label>
                                    <input type="tel" id="ncMobile" class="di-input" maxlength="10" inputmode="numeric" placeholder="10-digit mobile">
                                </div>
                                <div class="col-sm-6">
                                    <label class="di-label" for="ncGstin">GSTIN</label>
                                    <input type="text" id="ncGstin" class="di-input di-upper" maxlength="20" placeholder="Optional">
                                </div>
                                <div class="col-sm-6">
                                    <label class="di-label" for="ncAddress">Address</label>
                                    <input type="text" id="ncAddress" class="di-input" maxlength="500" placeholder="Optional">
                                </div>
                            </div>
                            <div class="di-customer-info mt-2">
                                <i class="bi bi-info-circle me-1"></i>This customer will also be saved in the Customers list.
                            </div>
                        </div>

                        <!-- EXISTING CUSTOMER -->
                        <div id="diExistingCustomer">
                        <select id="diCustomer" class="di-select">
                            <option value="">
                                <?php echo $customers ? 'Select customer' : 'No customers yet - use "New Customer"'; ?>
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
                        </div>
                    </div>
                </div>

                <!-- DISPATCH DETAILS (printed on the invoice) -->
                <div class="col-12">
                    <div class="di-block" style="background:#f8fbff;border-color:#d7e6fb">
                        <div class="di-block-title"><i class="bi bi-truck me-1"></i> Dispatch Details</div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="di-label" for="diEway">Eway Bill Number</label>
                                <input type="text" id="diEway" class="di-input" maxlength="50" placeholder="Enter eway bill number">
                            </div>
                            <div class="col-md-4">
                                <label class="di-label" for="diDispatch">Dispatched Through</label>
                                <input type="text" id="diDispatch" class="di-input" maxlength="150" placeholder="e.g. Transport name">
                            </div>
                            <div class="col-md-4">
                                <label class="di-label" for="diVehicle">Vehicle Number</label>
                                <input type="text" id="diVehicle" class="di-input di-upper" maxlength="50" placeholder="e.g. AP05AB1234">
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
                            <th style="width:112px">Type</th>
                            <th style="min-width:250px">Item / Service Description</th>
                            <th style="width:110px">HSN / SAC</th>
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
                <div class="di-add-row">
                    <button type="button" class="di-add-line" id="diAddLine">
                        <i class="bi bi-box-seam"></i> Add Product
                    </button>
                </div>
            </div>

            <datalist id="diProducts">
                <?php foreach (array_filter($products, fn($p) => $p["enabled"]) as $p): ?>
                    <option value="<?php echo e($p['name']); ?>" label="<?php echo $p['stock'] <= 0 ? 'Out of stock' : e('Stock: ' . rtrim(rtrim(number_format((float)$p['stock'], 2, '.', ''), '0'), '.')); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <datalist id="diServices">
                <option value="Fitting / Installation Charges"></option>
                <option value="Labour Charges"></option>
                <option value="Glass Cutting Charges"></option>
                <option value="Edge Polishing Charges"></option>
                <option value="Transport Charges"></option>
                <option value="Loading &amp; Unloading Charges"></option>
                <option value="Measurement / Site Visit Charges"></option>
                <option value="Repair / Service Charges"></option>
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

                    <!-- ADVANCE / AMOUNT PAID NOW -->
                    <div class="di-block mt-3" style="height:auto;background:#f3fbf5;border-color:#cdebd6">
                        <div class="di-block-title" style="color:#15803d"><i class="bi bi-cash-coin me-1"></i>
                            <?php echo $editInvoice ? 'Add Payment Now' : 'Advance / Amount Paid Now'; ?>
                            <span class="text-muted fw-normal text-lowercase">(optional)</span>
                        </div>
                        <?php if ($editInvoice && $editInvoice['paid'] > 0): ?>
                            <div class="small mb-2">Already paid on this invoice: <b>Rs <?php echo number_format($editInvoice['paid'], 2); ?></b>
                                - see <a href="payment.php">Payments</a></div>
                        <?php endif; ?>
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="di-label" for="diAdvAmount">Amount</label>
                                <input type="number" id="diAdvAmount" class="di-input" min="0" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-4">
                                <label class="di-label" for="diAdvMode">Mode</label>
                                <select id="diAdvMode" class="di-select">
                                    <option>Cash</option><option>UPI</option><option>Bank Transfer</option><option>Cheque</option><option>Card</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <label class="di-label" for="diAdvDate">Paid On</label>
                                <input type="date" id="diAdvDate" class="di-input" value="<?php echo e($today); ?>">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-2 small"><span>Balance after this payment</span><b id="tBalance">Rs 0.00</b></div>
                    </div>
                </div>
            </div>

            <div id="diError" class="alert alert-danger d-none mt-3 mb-0" role="alert"></div>

        </div>

        <div class="di-footer">
            <span class="di-hint"><i class="bi bi-info-circle me-1"></i>Products fill rate, HSN and GST from the catalogue and reduce stock. Services (fitting, labour, transport...) never change stock.</span>
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
    const editInvoice = <?php echo json_encode($editInvoice, json_flags()); ?>;

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

    /* ---- GST rates from the Taxes page ---- */
    const gstRates = <?php echo json_encode($gstRateList, json_flags()); ?>;
    const defaultGst = gstRates.includes(18) ? 18 : (gstRates[gstRates.length - 1] || 0);
    function gstOptions(selected) {
        const list = [...new Set([0, ...gstRates, Number(selected) || 0])].sort((a, b) => a - b);
        return list.map(r => `<option value="${r}" ${r === Number(selected) ? "selected" : ""}>${r}%</option>`).join("");
    }
    function setTax(tr, value) {
        const sel = field(tr, "tax");
        sel.innerHTML = gstOptions(value);
        sel.value = String(Number(value) || 0);
    }

    /* ---- Lines ---- */
    function addLine(type) {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="di-line-no"></td>
            <td>
                <select class="di-input di-type" data-f="type" aria-label="Line type">
                    <option value="product">Product</option>
                    <option value="service">Service</option>
                </select>
            </td>
            <td>
                <input type="text" class="di-input" data-f="name" maxlength="200">
                <div class="di-line-meta" data-f="meta"></div>
            </td>
            <td><input type="text" class="di-input" data-f="hsn" maxlength="30"></td>
            <td><input type="number" class="di-input num" data-f="quantity" value="1" min="0" step="any"></td>
            <td><input type="number" class="di-input num" data-f="rate" value="0" min="0" step="any"></td>
            <td><input type="number" class="di-input num" data-f="discount" value="0" min="0" max="100" step="any"></td>
            <td><select class="di-input num" data-f="tax" aria-label="GST %">${gstOptions(defaultGst)}</select></td>
            <td class="di-line-amount" data-f="amount">Rs 0.00</td>
            <td><button type="button" class="di-remove" title="Remove line"><i class="bi bi-x-circle-fill"></i></button></td>`;
        tr.dataset.productId = "";
        lines.appendChild(tr);
        setType(tr, type || "product");
        renumber();
        recalc();
        return tr;
    }

    function setType(tr, type) {
        const service = type === "service";
        tr.dataset.type = service ? "service" : "product";
        field(tr, "type").value = tr.dataset.type;
        const name = field(tr, "name");
        name.setAttribute("list", service ? "diServices" : "diProducts");
        name.placeholder = service ? "Service description (e.g. Fitting charges)" : "Search product or type a custom item";
        field(tr, "hsn").placeholder = service ? "SAC" : "HSN";
        applyProduct(tr);
    }

    function renumber() {
        [...lines.rows].forEach((tr, i) => { tr.cells[0].textContent = i + 1; });
    }

    function field(tr, f) { return tr.querySelector(`[data-f="${f}"]`); }

    function applyProduct(tr) {
        if (tr.dataset.type === "service") {
            tr.dataset.productId = "";
            field(tr, "meta").textContent = field(tr, "name").value.trim() ? "Service (no stock change)" : "";
            return;
        }
        const p = productByName.get(field(tr, "name").value.trim().toLowerCase());
        const meta = field(tr, "meta");
        if (p) {
            tr.dataset.productId = p.id;
            field(tr, "hsn").value = p.hsn || "";
            field(tr, "rate").value = p.rate;
            setTax(tr, p.tax);
            // Stock text is filled by checkStock() (it also counts other lines of the same product).
            meta.innerHTML = "";
        } else {
            tr.dataset.productId = "";
            meta.textContent = field(tr, "name").value.trim() ? "Custom line (no stock change)" : "";
        }
    }

    /* ---- Stock check: quantity can never be more than the stock ---- */
    const productById = new Map(products.map(p => [Number(p.id), p]));
    // Editing: this invoice's own quantities go back to stock first, so they count as available.
    const reservedQty = {};
    (editInvoice ? editInvoice.items : []).forEach(it => {
        if (it.product_id) reservedQty[it.product_id] = (reservedQty[it.product_id] || 0) + Number(it.quantity || 0);
    });
    const fmtQty = v => Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
    function availableFor(pid) {
        const p = productById.get(Number(pid));
        return p ? Number(p.stock) + (reservedQty[pid] || 0) : Infinity;
    }
    function productRows() {
        return [...lines.rows].filter(tr => tr.dataset.type === "product" && tr.dataset.productId);
    }
    // Marks each product line: out of stock (locked), more than stock (red) or OK. Returns problem messages.
    function checkStock() {
        const want = {};
        productRows().forEach(tr => {
            const pid = tr.dataset.productId;
            want[pid] = (want[pid] || 0) + num(field(tr, "quantity").value);
        });
        const problems = [];
        [...lines.rows].forEach(tr => {
            const pid = tr.dataset.type === "product" ? tr.dataset.productId : "";
            const p = pid ? productById.get(Number(pid)) : null;
            const qtyInput = field(tr, "quantity");
            let out = false, short = false;
            if (p) {
                const have = availableFor(pid);
                out = have <= 0;
                short = !out && want[pid] > have + 0.0001;
                const bits = [p.thickness, p.size, p.unit].filter(Boolean).join(" · ");
                const stockText = out
                    ? '<span class="di-stock-out"><i class="bi bi-x-octagon-fill"></i> Out of stock</span>'
                    : short
                        ? `<span class="di-stock-out"><i class="bi bi-exclamation-triangle-fill"></i> Only ${fmtQty(have)} available</span>`
                        : `<span class="di-stock-ok">Stock: ${fmtQty(have)}</span>`;
                field(tr, "meta").innerHTML = (bits ? esc(bits) + " · " : "") + stockText;
                if (out) problems.push(`${p.name} is out of stock`);
                else if (short) problems.push(`${p.name} (only ${fmtQty(have)} available)`);
            }
            tr.classList.toggle("di-row-out", out);
            tr.classList.toggle("di-row-short", short);
            qtyInput.classList.toggle("di-qty-short", short);
            // Out of stock: nothing on this line can be changed - remove it with the X button.
            tr.querySelectorAll(".di-input").forEach(inp => { inp.disabled = out; });
            if (out) tr.title = "Out of stock - remove this line or add stock in Products.";
            else tr.removeAttribute("title");
        });
        return [...new Set(problems)];
    }

    function recalc() {
        checkStock();
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
        $("tBalance").textContent = money(Math.max(0, grand - alreadyPaid - num($("diAdvAmount").value)));
    }
    const alreadyPaid = editInvoice ? Number(editInvoice.paid || 0) : 0;

    lines.addEventListener("input", e => {
        const tr = e.target.closest("tr");
        if (e.target.dataset.f === "name") applyProduct(tr);
        recalc();
    });
    lines.addEventListener("change", e => {
        if (e.target.dataset.f !== "type") return;
        setType(e.target.closest("tr"), e.target.value);
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
    $("diAddLine").addEventListener("click", () => field(addLine("product"), "name").focus());
    $("diRegion").addEventListener("change", recalc);
    $("diRound").addEventListener("change", recalc);
    $("diAdvAmount").addEventListener("input", recalc);

    $("diCustomer").addEventListener("change", function () {
        const c = customers.find(x => String(x.id) === this.value);
        $("diCustomerInfo").innerHTML = c
            ? `<strong>${esc(c.name)}</strong><br>` +
              (c.mobile ? `Mobile: ${esc(c.mobile)}<br>` : "") +
              (c.gstin ? `GSTIN: ${esc(c.gstin)}<br>` : "") +
              (c.address ? esc(c.address) : "")
            : "Choose the customer to bill. Mobile, GSTIN and address appear here.";
    });

    /* ---- Existing / New customer ---- */
    let customerMode = "existing";

    function setCustomerMode(mode) {
        customerMode = mode;
        document.querySelectorAll("[data-cust-mode]").forEach(btn => {
            const on = btn.dataset.custMode === mode;
            btn.classList.toggle("active", on);
            btn.setAttribute("aria-selected", on ? "true" : "false");
        });
        $("diNewCustomer").classList.toggle("d-none", mode !== "new");
        $("diExistingCustomer").classList.toggle("d-none", mode !== "existing");
        if (mode === "new") $("ncName").focus();
    }

    document.querySelectorAll("[data-cust-mode]").forEach(btn =>
        btn.addEventListener("click", () => setCustomerMode(btn.dataset.custMode)));

    $("ncMobile").addEventListener("input", function () {
        this.value = this.value.replace(/\D/g, "").slice(0, 10);
    });

    // No saved customers yet -> start on "New Customer".
    if (!customers.length) setCustomerMode("new");

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
                type: tr.dataset.type,
                product_id: tr.dataset.type === "product" && tr.dataset.productId ? Number(tr.dataset.productId) : null,
                name: field(tr, "name").value.trim(),
                hsn: field(tr, "hsn").value.trim(),
                quantity: field(tr, "quantity").value,
                rate: field(tr, "rate").value,
                discount: field(tr, "discount").value || 0,
                tax: field(tr, "tax").value || 0
            }));

        let newCustomer = null;
        if (customerMode === "new") {
            newCustomer = {
                name: $("ncName").value.trim(),
                mobile: $("ncMobile").value.trim(),
                gstin: $("ncGstin").value.trim().toUpperCase(),
                address: $("ncAddress").value.trim()
            };
            if (!newCustomer.name) return showError("Enter the customer name.");
            if (!/^\d{10}$/.test(newCustomer.mobile)) return showError("Enter a valid 10-digit mobile number.");
        } else if (!$("diCustomer").value) {
            return showError('Please select a customer, or click "New Customer" to type one.');
        }
        if (!items.length) return showError("Add at least one item line.");
        const stockProblems = checkStock();
        if (stockProblems.length) {
            return showError("Not enough stock: " + stockProblems.join(", ") + ". Reduce the quantity or remove the line.");
        }

        const buttons = [$("diSave"), $("diSavePrint")];
        buttons.forEach(b => b.disabled = true);
        try {
            const result = await App.post(window.location.pathname, {
                invoice_data: JSON.stringify({
                    customer_id: newCustomer ? 0 : Number($("diCustomer").value),
                    new_customer: newCustomer,
                    date: $("diDate").value,
                    due_date: $("diDue").value,
                    region: $("diRegion").value,
                    reference: $("diReference").value,
                    eway_bill: $("diEway").value,
                    vehicle_no: $("diVehicle").value,
                    dispatched_through: $("diDispatch").value,
                    notes: $("diNotes").value,
                    round_off: $("diRound").checked,
                    edit_id: editInvoice ? editInvoice.id : 0,
                    advance_amount: $("diAdvAmount").value || 0,
                    advance_mode: $("diAdvMode").value,
                    advance_date: $("diAdvDate").value,
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

    /* ---- Edit mode: fill everything from the saved invoice ---- */
    if (editInvoice) {
        setCustomerMode("existing");
        const sel = $("diCustomer");
        if (editInvoice.customer_id && ![...sel.options].some(o => o.value === String(editInvoice.customer_id))) {
            sel.add(new Option("Customer #" + editInvoice.customer_id, editInvoice.customer_id));
        }
        sel.value = editInvoice.customer_id ? String(editInvoice.customer_id) : "";
        sel.dispatchEvent(new Event("change"));
        $("diDate").value = editInvoice.date;
        $("diDue").value = editInvoice.due_date;
        $("diRegion").value = editInvoice.region;
        $("diReference").value = editInvoice.reference;
        $("diEway").value = editInvoice.eway_bill;
        $("diVehicle").value = editInvoice.vehicle_no;
        $("diDispatch").value = editInvoice.dispatched_through;
        $("diNotes").value = editInvoice.notes;
        $("diRound").checked = !!editInvoice.round_off;
        editInvoice.items.forEach(it => {
            const product = it.product_id ? products.find(p => p.id === it.product_id) : null;
            const tr = addLine(it.product_id || productByName.has(String(it.name).toLowerCase()) ? "product" : "service");
            field(tr, "name").value = product ? product.name : it.name;
            applyProduct(tr);
            if (!product && tr.dataset.type === "product") tr.dataset.productId = "";
            field(tr, "hsn").value = it.hsn || "";
            field(tr, "quantity").value = it.quantity;
            field(tr, "rate").value = it.rate;
            field(tr, "discount").value = it.discount;
            setTax(tr, it.tax);
        });
        $("diSave").innerHTML = '<i class="bi bi-floppy"></i> Update Invoice';
        $("diSavePrint").innerHTML = '<i class="bi bi-printer"></i> Update &amp; Print';
        if (!editInvoice.items.length) addLine();
        recalc();
    } else {
        addLine();
    }
})();
</script>

<?php include 'footer.php'; ?>
