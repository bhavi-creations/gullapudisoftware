<?php
/*
|--------------------------------------------------------------------------
| TAX INVOICE - print / download PDF   invoice-print.php?id=INVOICE_ID
|--------------------------------------------------------------------------
| ?download=1 starts the PDF download automatically.
| Layout: includes/doc-print.php (shared with quotations).
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/doc-print.php';

$invoice = db_one('SELECT * FROM invoices WHERE id = ?', [(int)($_GET['id'] ?? 0)]);

if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found. <a href="invoices.php">Back to invoices</a>';
    exit;
}

$order = $invoice['order_id'] ? db_one('SELECT order_number, reference, notes FROM orders WHERE id = ?', [(int)$invoice['order_id']]) : null;
$items = $invoice['order_id']
    ? db_all('SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM order_items WHERE order_id = ? ORDER BY id', [(int)$invoice['order_id']])
    : [];

$reference = $order['reference'] ?? '';
if ($reference === 'Direct Invoice') {
    $reference = '';
}

doc_render([
    'title' => 'Tax Invoice',
    'number' => $invoice['invoice_number'],
    'file_name' => 'Invoice-' . $invoice['invoice_number'],
    'meta' => [
        ['Invoice No.', $invoice['invoice_number']],
        ['e-Way Bill No.', $invoice['eway_bill'] ?? ''],
        ['Dated', date('d-m-Y', strtotime($invoice['date']))],
        ['Vehicle No.', $invoice['vehicle_no'] ?? ''],
        ['Dispatched through', $invoice['dispatched_through'] ?? ''],
        ['Reference', $reference !== '' ? $reference : 'None'],
    ],
    'customer' => doc_customer($invoice['customer_id'] ? (int)$invoice['customer_id'] : null, (string)$invoice['customer']),
    'region' => $invoice['region'] ?? 'Inside AP',
    'items' => $items,
    'amount' => (float)$invoice['amount'],
    'notes' => $order['notes'] ?? '',
    'back_url' => 'invoices.php',
    'back_label' => 'Invoices',
    'new_url' => 'direct-invoice.php',
    'new_label' => 'New Direct Invoice',
    'saved_note' => !empty($_GET['new']) ? 'Invoice ' . $invoice['invoice_number'] . ' saved.' : '',
    'footer_note' => 'This is a Computer Generated Invoice',
]);
