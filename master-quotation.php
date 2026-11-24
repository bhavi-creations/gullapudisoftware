<?php
/*
|--------------------------------------------------------------------------
| MASTER QUOTATIONS - quotations saved with the "Master Quotation" tick.
|--------------------------------------------------------------------------
| Same screen as quotation.php, filtered to is_master = 1, with a search box
| that matches anything (customer, mobile, business, product, amount, date).
| Master quotations are also listed in the normal Quotations page.
|--------------------------------------------------------------------------
*/
$masterMode = true;
require __DIR__ . '/quotation.php';
