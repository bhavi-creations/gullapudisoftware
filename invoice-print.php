<?php
/*
|--------------------------------------------------------------------------
| PRINTABLE TAX INVOICE  -  invoice-print.php?id=INVOICE_ID
|--------------------------------------------------------------------------
| A4 GST tax invoice for any invoice (POS orders and Direct Invoices).
| Company details come from includes/config.php (COMPANY_*).
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$id = (int)($_GET['id'] ?? 0);
$invoice = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);

if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found. <a href="invoices.php">Back to invoices</a>';
    exit;
}

$order = $invoice['order_id'] ? db_one('SELECT * FROM orders WHERE id = ?', [(int)$invoice['order_id']]) : null;
$items = $invoice['order_id']
    ? db_all('SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM order_items WHERE order_id = ? ORDER BY id', [(int)$invoice['order_id']])
    : [];
$customer = $invoice['customer_id']
    ? db_one('SELECT name, mobile, email, gstin, addresses FROM customers WHERE id = ?', [(int)$invoice['customer_id']])
    : null;

$customerAddress = '';
if ($customer && $customer['addresses']) {
    $addr = json_decode($customer['addresses'], true);
    if (is_array($addr) && isset($addr[0]['address'])) {
        $customerAddress = (string)$addr[0]['address'];
    }
}

$paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoice['invoice_number']]);
$interState = ($invoice['region'] ?? '') === 'Outside AP';

/* Line maths + HSN / rate summary */
$lines = [];
$hsnSummary = [];
$sumTaxable = 0.0;
$sumTax = 0.0;
foreach ($items as $it) {
    $base = (float)$it['quantity'] * (float)$it['rate'];
    $taxable = $base - $base * (float)$it['discount'] / 100;
    $taxAmt = $taxable * (float)$it['tax'] / 100;
    $lines[] = $it + ['taxable' => $taxable, 'tax_amount' => $taxAmt];
    $sumTaxable += $taxable;
    $sumTax += $taxAmt;

    $key = ($it['hsn'] ?: '-') . '|' . (float)$it['tax'];
    if (!isset($hsnSummary[$key])) {
        $hsnSummary[$key] = ['hsn' => $it['hsn'] ?: '-', 'rate' => (float)$it['tax'], 'taxable' => 0.0, 'tax' => 0.0];
    }
    $hsnSummary[$key]['taxable'] += $taxable;
    $hsnSummary[$key]['tax'] += $taxAmt;
}

if (!$items) {
    // Invoice without product lines: fall back to stored totals.
    $sumTaxable = (float)$invoice['subtotal'] - (float)$invoice['discount'];
    $sumTax = (float)$invoice['tax'];
}

$amount = (float)$invoice['amount'];
$adjustment = round($amount - round($sumTaxable + $sumTax, 2), 2);
$balance = max(0, $amount - $paid);

function inr(float $v): string
{
    $neg = $v < 0;
    $v = abs($v);
    $whole = (string)floor(round($v, 2));
    $dec = str_pad((string)round((round($v, 2) - floor(round($v, 2))) * 100), 2, '0', STR_PAD_LEFT);
    $last3 = substr($whole, -3);
    $rest = substr($whole, 0, -3);
    if ($rest !== '') {
        $last3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
    }
    return ($neg ? '-' : '') . $last3 . '.' . $dec;
}

function qty_text(float $q): string
{
    return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

function words_in(int $n): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $x) use ($ones, $tens): string {
        return $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    };
    if ($n === 0) {
        return 'Zero';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $label]) {
        if ($n >= $div) {
            $chunk = intdiv($n, $div);
            $parts[] = ($chunk >= 100 ? words_in($chunk) : $two($chunk)) . ' ' . $label;
            $n %= $div;
        }
    }
    if ($n >= 100) {
        $parts[] = $ones[intdiv($n, 100)] . ' Hundred';
        $n %= 100;
    }
    if ($n) {
        $parts[] = $two($n);
    }
    return implode(' ', $parts);
}

function amount_words(float $v): string
{
    $rupees = (int)floor(round($v, 2));
    $paise = (int)round((round($v, 2) - $rupees) * 100);
    return words_in($rupees) . ' Rupees' . ($paise ? ' and ' . words_in($paise) . ' Paise' : '') . ' Only';
}

$fmtDate = fn($d) => $d ? date('d M Y', strtotime($d)) : '-';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?php echo e($invoice['invoice_number']); ?> | Gullapudi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    :root {
        --ink: #142238;
        --muted: #5f6f86;
        --line: #d9e1ec;
        --brand: #06283e;
        --accent: #ffb020;
        --soft: #f3f6fb;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        background: #e9eef5;
        font-family: Arial, Helvetica, sans-serif;
        color: var(--ink);
        font-size: 12.5px;
    }

    .toolbar {
        position: sticky;
        top: 0;
        z-index: 5;
        display: flex;
        justify-content: center;
        gap: 10px;
        padding: 12px;
        background: #fff;
        border-bottom: 1px solid var(--line);
    }
    .toolbar a, .toolbar button {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        font: 700 13.5px Arial, sans-serif;
        text-decoration: none;
        cursor: pointer;
    }
    .toolbar .primary { background: #1479ff; border-color: #1479ff; color: #fff; }
    .saved-note {
        max-width: 210mm;
        margin: 14px auto 0;
        padding: 10px 14px;
        border-radius: 8px;
        background: #dcfce7;
        color: #166534;
        font-weight: 700;
        font-size: 13px;
    }

    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 16px auto 30px;
        background: #fff;
        padding: 12mm 12mm 10mm;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .12);
        display: flex;
        flex-direction: column;
    }

    /* Letterhead */
    .head {
        display: flex;
        justify-content: space-between;
        align-items: stretch;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid var(--brand);
    }
    .head-left { padding: 14px 16px; flex: 1; }
    .brand { font-size: 24px; font-weight: 800; letter-spacing: 1px; color: var(--brand); margin: 0; }
    .brand-sub { font-size: 11px; font-weight: 800; letter-spacing: 2.5px; color: #b7791f; margin: 2px 0 8px; }
    .head-meta { color: var(--muted); line-height: 1.6; }
    .head-right {
        background: var(--brand);
        color: #fff;
        padding: 14px 18px;
        min-width: 62mm;
        display: flex;
        flex-direction: column;
        justify-content: center;
        text-align: right;
    }
    .doc-title { font-size: 19px; font-weight: 800; letter-spacing: 3px; margin: 0; }
    .doc-copy { font-size: 10px; letter-spacing: 1.5px; color: #c7dbea; margin-top: 2px; }
    .doc-no { margin-top: 10px; font-size: 15px; font-weight: 800; color: var(--accent); }

    /* Party + meta grid */
    .grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 10px; margin-top: 10px; }
    .box { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; }
    .box-title { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; color: var(--muted); text-transform: uppercase; margin-bottom: 6px; }
    .party-name { font-size: 15px; font-weight: 800; margin-bottom: 3px; }
    .party-line { color: #33465e; line-height: 1.55; }
    .meta { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 0; vertical-align: top; }
    .meta td:first-child { color: var(--muted); width: 45%; }
    .meta td:last-child { font-weight: 700; text-align: right; }

    /* Items */
    table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .items th {
        background: var(--brand);
        color: #fff;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 7px 6px;
        text-align: left;
    }
    .items th:first-child { border-top-left-radius: 6px; }
    .items th:last-child { border-top-right-radius: 6px; }
    .items td { padding: 7px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .items tbody tr:nth-child(even) td { background: #fafbfd; }
    .items .r { text-align: right; white-space: nowrap; }
    .items .c { text-align: center; }
    .item-name { font-weight: 700; }
    .item-sub { color: var(--muted); font-size: 11px; margin-top: 2px; }

    /* Bottom */
    .bottom { display: grid; grid-template-columns: 1.3fr 1fr; gap: 10px; margin-top: 10px; }
    .words { background: var(--soft); border-radius: 8px; padding: 9px 12px; margin-bottom: 8px; }
    .words b { display: block; font-size: 10px; letter-spacing: 1.2px; color: var(--muted); text-transform: uppercase; margin-bottom: 2px; }
    table.hsn { width: 100%; border-collapse: collapse; font-size: 11px; }
    .hsn th, .hsn td { border: 1px solid var(--line); padding: 5px 6px; }
    .hsn th { background: var(--soft); font-weight: 700; text-align: left; }
    .hsn .r { text-align: right; }

    .totals { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
    .t-row { display: flex; justify-content: space-between; padding: 6px 12px; }
    .t-row span { color: var(--muted); }
    .t-row b { white-space: nowrap; }
    .t-row.sep { border-top: 1px solid var(--line); }
    .t-grand { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: var(--brand); color: #fff; }
    .t-grand b { font-size: 17px; color: var(--accent); }
    .t-row.paid b { color: #15803d; }
    .t-row.due b { color: #b91c1c; }

    .notes { white-space: pre-line; color: #33465e; line-height: 1.55; }

    .sign-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: auto; padding-top: 14px; align-items: end; }
    .sign-left .line { border-top: 1px solid var(--ink); display: inline-block; padding-top: 4px; min-width: 55mm; text-align: center; font-weight: 700; color: var(--muted); }
    .sign { text-align: right; }
    .sign .space { height: 46px; }
    .sign .line { border-top: 1px solid var(--ink); display: inline-block; padding-top: 4px; min-width: 55mm; text-align: center; font-weight: 700; }
    .thanks { text-align: center; color: var(--muted); font-size: 11px; margin-top: 10px; border-top: 1px dashed var(--line); padding-top: 8px; }

    .stamp {
        display: inline-block;
        border: 2px solid currentColor;
        border-radius: 6px;
        padding: 2px 8px;
        font-weight: 800;
        font-size: 11px;
        letter-spacing: 1px;
        text-transform: uppercase;
    }
    .stamp.paid { color: #15803d; }
    .stamp.part { color: #b45309; }
    .stamp.due { color: #b91c1c; }

    @media screen and (max-width: 820px) {
        .page { width: auto; min-height: 0; margin: 10px; padding: 14px; }
        .grid, .bottom { grid-template-columns: 1fr; }
        .head { flex-direction: column; }
        .head-right { text-align: left; min-width: 0; }
        .items-wrap { overflow-x: auto; }
    }

    @page { size: A4; margin: 0; }
    @media print {
        body { background: #fff; }
        .toolbar, .saved-note { display: none !important; }
        .page { margin: 0; box-shadow: none; width: 210mm; min-height: 297mm; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .items tr { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="invoices.php"><i class="bi bi-arrow-left"></i> Invoices</a>
    <a href="direct-invoice.php"><i class="bi bi-plus-lg"></i> New Direct Invoice</a>
    <button type="button" class="primary" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
</div>

<?php if (!empty($_GET['new'])): ?>
    <div class="saved-note"><i class="bi bi-check-circle-fill"></i> Invoice <?php echo e($invoice['invoice_number']); ?> saved.</div>
<?php endif; ?>

<div class="page">

    <!-- LETTERHEAD -->
    <div class="head">
        <div class="head-left">
            <p class="brand">GULLAPUDI</p>
            <p class="brand-sub">GLASS &amp; PLYWOOD CENTRE</p>
            <div class="head-meta">
                <?php if (COMPANY_ADDRESS): ?><?php echo nl2br(e(COMPANY_ADDRESS)); ?><br><?php endif; ?>
                <?php echo e(implode('  |  ', array_filter([
                    COMPANY_PHONE ? 'Ph: ' . COMPANY_PHONE : '',
                    COMPANY_EMAIL,
                ]))); ?>
                <?php if (COMPANY_GSTIN): ?><br><b>GSTIN:</b> <?php echo e(COMPANY_GSTIN); ?><?php endif; ?>
                <?php if (COMPANY_STATE): ?><br><b>State:</b> <?php echo e(COMPANY_STATE); ?><?php endif; ?>
            </div>
        </div>
        <div class="head-right">
            <p class="doc-title">TAX INVOICE</p>
            <div class="doc-copy">ORIGINAL FOR RECIPIENT</div>
            <div class="doc-no"><?php echo e($invoice['invoice_number']); ?></div>
        </div>
    </div>

    <!-- BILL TO + DETAILS -->
    <div class="grid">
        <div class="box">
            <div class="box-title">Bill To</div>
            <div class="party-name"><?php echo e($customer['name'] ?? $invoice['customer'] ?? '-'); ?></div>
            <div class="party-line">
                <?php if ($customerAddress): ?><?php echo nl2br(e($customerAddress)); ?><br><?php endif; ?>
                <?php if (!empty($customer['mobile'])): ?>Mobile: <?php echo e($customer['mobile']); ?><br><?php endif; ?>
                <?php if (!empty($customer['email'])): ?>Email: <?php echo e($customer['email']); ?><br><?php endif; ?>
                <?php if (!empty($customer['gstin'])): ?><b>GSTIN:</b> <?php echo e($customer['gstin']); ?><?php endif; ?>
            </div>
        </div>
        <div class="box">
            <div class="box-title">Invoice Details</div>
            <table class="meta">
                <tr><td>Invoice Date</td><td><?php echo e($fmtDate($invoice['date'])); ?></td></tr>
                <tr><td>Due Date</td><td><?php echo e($fmtDate($invoice['due_date'])); ?></td></tr>
                <tr><td>Place of Supply</td><td><?php echo $interState ? 'Outside AP (IGST)' : 'Andhra Pradesh'; ?></td></tr>
                <?php if ($order): ?>
                    <tr><td>Order No.</td><td><?php echo e($order['order_number']); ?></td></tr>
                    <?php if (!empty($order['reference'])): ?>
                        <tr><td>Reference</td><td><?php echo e($order['reference']); ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr>
                    <td>Status</td>
                    <td>
                        <?php if ($paid >= $amount - 0.009 && $amount > 0): ?>
                            <span class="stamp paid">Paid</span>
                        <?php elseif ($paid > 0): ?>
                            <span class="stamp part">Partially Paid</span>
                        <?php else: ?>
                            <span class="stamp due"><?php echo e($invoice['status'] === 'Overdue' ? 'Overdue' : 'Unpaid'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ITEMS -->
    <div class="items-wrap">
    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:28px">#</th>
                <th>Item Description</th>
                <th style="width:62px">HSN</th>
                <th class="r" style="width:50px">Qty</th>
                <th class="r" style="width:72px">Rate</th>
                <th class="r" style="width:46px">Disc</th>
                <th class="r" style="width:80px">Taxable</th>
                <th class="r" style="width:46px">GST</th>
                <th class="r" style="width:86px">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$lines): ?>
                <tr><td colspan="9" class="c" style="padding:18px;color:#5f6f86">No item lines recorded for this invoice.</td></tr>
            <?php endif; ?>
            <?php foreach ($lines as $i => $l): ?>
                <tr>
                    <td class="c"><?php echo $i + 1; ?></td>
                    <td>
                        <div class="item-name"><?php echo e($l['name']); ?></div>
                        <?php $sub = implode(' · ', array_filter([$l['thickness'], $l['size']])); ?>
                        <?php if ($sub): ?><div class="item-sub"><?php echo e($sub); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo e($l['hsn'] ?: '-'); ?></td>
                    <td class="r"><?php echo e(qty_text((float)$l['quantity'])); ?></td>
                    <td class="r"><?php echo inr((float)$l['rate']); ?></td>
                    <td class="r"><?php echo (float)$l['discount'] ? e(qty_text((float)$l['discount'])) . '%' : '-'; ?></td>
                    <td class="r"><?php echo inr($l['taxable']); ?></td>
                    <td class="r"><?php echo e(qty_text((float)$l['tax'])); ?>%</td>
                    <td class="r"><b><?php echo inr((float)$l['amount']); ?></b></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- WORDS / TAX SUMMARY / TOTALS -->
    <div class="bottom">
        <div>
            <div class="words">
                <b>Amount in words</b>
                <?php echo e(amount_words($amount)); ?>
            </div>

            <?php if ($hsnSummary): ?>
            <table class="hsn">
                <thead>
                    <tr>
                        <th>HSN</th>
                        <th class="r">Taxable</th>
                        <?php if ($interState): ?>
                            <th class="r">IGST</th>
                        <?php else: ?>
                            <th class="r">CGST</th>
                            <th class="r">SGST</th>
                        <?php endif; ?>
                        <th class="r">Total Tax</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hsnSummary as $h): ?>
                    <tr>
                        <td><?php echo e($h['hsn']); ?></td>
                        <td class="r"><?php echo inr($h['taxable']); ?></td>
                        <?php if ($interState): ?>
                            <td class="r"><?php echo e(qty_text($h['rate'])); ?>% · <?php echo inr($h['tax']); ?></td>
                        <?php else: ?>
                            <td class="r"><?php echo e(qty_text($h['rate'] / 2)); ?>% · <?php echo inr($h['tax'] / 2); ?></td>
                            <td class="r"><?php echo e(qty_text($h['rate'] / 2)); ?>% · <?php echo inr($h['tax'] / 2); ?></td>
                        <?php endif; ?>
                        <td class="r"><?php echo inr($h['tax']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($order['notes'])): ?>
                <div class="box" style="margin-top:8px">
                    <div class="box-title">Terms &amp; Notes</div>
                    <div class="notes"><?php echo e(trim($order['notes'])); ?></div>
                </div>
            <?php endif; ?>

            <?php if (COMPANY_BANK): ?>
                <div class="box" style="margin-top:8px">
                    <div class="box-title">Bank Details</div>
                    <div class="notes"><?php echo e(COMPANY_BANK); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="totals">
                <?php if ((float)$invoice['discount'] > 0): ?>
                    <div class="t-row"><span>Gross Amount</span><b><?php echo inr((float)$invoice['subtotal']); ?></b></div>
                    <div class="t-row"><span>Discount</span><b>-<?php echo inr((float)$invoice['discount']); ?></b></div>
                <?php endif; ?>
                <div class="t-row<?php echo (float)$invoice['discount'] > 0 ? ' sep' : ''; ?>"><span>Taxable Value</span><b><?php echo inr($sumTaxable); ?></b></div>
                <?php if ($interState): ?>
                    <div class="t-row"><span>IGST</span><b><?php echo inr($sumTax); ?></b></div>
                <?php else: ?>
                    <div class="t-row"><span>CGST</span><b><?php echo inr($sumTax / 2); ?></b></div>
                    <div class="t-row"><span>SGST</span><b><?php echo inr($sumTax / 2); ?></b></div>
                <?php endif; ?>
                <?php if (abs($adjustment) >= 0.01): ?>
                    <div class="t-row"><span>Round Off</span><b><?php echo ($adjustment > 0 ? '+' : '') . inr($adjustment); ?></b></div>
                <?php endif; ?>
                <div class="t-grand"><span>Grand Total</span><b>Rs <?php echo inr($amount); ?></b></div>
                <div class="t-row paid sep"><span>Received</span><b>Rs <?php echo inr($paid); ?></b></div>
                <div class="t-row due"><span>Balance Due</span><b>Rs <?php echo inr($balance); ?></b></div>
            </div>
        </div>
    </div>

    <!-- SIGNATURES -->
    <div class="sign-row">
        <div class="sign-left">
            <span class="line">Customer Signature</span>
        </div>
        <div class="sign">
            <div style="font-weight:700">For GULLAPUDI GLASS &amp; PLYWOOD CENTRE</div>
            <div class="space"></div>
            <span class="line">Authorised Signatory</span>
        </div>
    </div>

    <div class="thanks">Thank you for your business! &nbsp;·&nbsp; This is a computer generated invoice.</div>
</div>

</body>
</html>
