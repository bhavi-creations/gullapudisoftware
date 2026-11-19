<?php
/*
|--------------------------------------------------------------------------
| QUOTATION - print / download PDF   quotation-print.php?id=QUOTATION_ID
|--------------------------------------------------------------------------
| ?download=1 starts the PDF download automatically.
| Layout: includes/doc-print.php (same format as the tax invoice).
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/doc-print.php';

$quotation = db_one('SELECT * FROM quotations WHERE id = ?', [(int)($_GET['id'] ?? 0)]);

if (!$quotation) {
    http_response_code(404);
    echo 'Quotation not found. <a href="quotation.php">Back to quotations</a>';
    exit;
}

$items = db_all(
    'SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM quotation_items WHERE quotation_id = ? ORDER BY id',
    [(int)$quotation['id']]
);

doc_render([
    'title' => 'Quotation',
    'number' => $quotation['quotation_id'],
    'file_name' => 'Quotation-' . $quotation['quotation_id'],
    'meta' => [
        ['Quotation No.', $quotation['quotation_id']],
        ['Valid Upto', $quotation['valid_upto'] ? date('d-m-Y', strtotime($quotation['valid_upto'])) : ''],
        ['Dated', date('d-m-Y', strtotime($quotation['date']))],
        ['Salesman', $quotation['salesman'] ?? ''],
        ['Status', $quotation['status']],
        ['Reference', ($quotation['reference'] ?? '') !== '' ? $quotation['reference'] : 'None'],
    ],
    'customer' => doc_customer($quotation['customer_id'] ? (int)$quotation['customer_id'] : null, (string)$quotation['customer']),
    'region' => $quotation['region'] ?? 'Inside AP',
    'items' => $items,
    'amount' => (float)$quotation['amount'],
    'notes' => $quotation['notes'] ?? '',
    'back_url' => 'quotation.php',
    'back_label' => 'Quotations',
    'new_url' => 'add-quotation.php',
    'new_label' => 'New Quotation',
    'footer_note' => 'This is a Computer Generated Quotation',
]);
