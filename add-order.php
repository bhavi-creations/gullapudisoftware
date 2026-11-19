<?php
/*
 * ADD ORDER (POS)
 * Uses the shared POS screen in add-quotation.php in "order" mode:
 * saves the order + items, creates an invoice and reduces stock.
 */
$posType = 'order';
require __DIR__ . '/add-quotation.php';
