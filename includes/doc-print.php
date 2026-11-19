<?php
/*
|--------------------------------------------------------------------------
| SHARED PRINT / PDF TEMPLATE  (Tally-style GST document)
|--------------------------------------------------------------------------
| Used by invoice-print.php and quotation-print.php.
| Call doc_render($doc) with:
|   title       'Tax Invoice' | 'Quotation'
|   file_name   download file name without .pdf
|   meta        [[label, value], ...]  six cells (2 rows x 3) top-right
|   customer    ['name','mobile','gstin','bill_address','ship_address']
|   region      'Inside AP' | 'Outside AP'
|   items       rows from order_items / quotation_items
|   amount      final document amount (after round off)
|   notes       remarks text
|   back_url / back_label / new_url / new_label   toolbar links
|   footer_note 'This is a Computer Generated Invoice'
|--------------------------------------------------------------------------
*/

function doc_money(float $v): string
{
    $neg = $v < 0;
    $v = round(abs($v), 2);
    $whole = (string)floor($v);
    $dec = str_pad((string)round(($v - floor($v)) * 100), 2, '0', STR_PAD_LEFT);
    $last3 = substr($whole, -3);
    $rest = substr($whole, 0, -3);
    if ($rest !== '') {
        $last3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
    }
    return ($neg ? '-' : '') . $last3 . '.' . $dec;
}

function doc_qty(float $q): string
{
    return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

/** Indian words in Tally style: "Seven Lakh, Thirty Nine Thousand And Thirty Two only." */
function doc_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens): string {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $toWords = function (int $n) use (&$toWords, $two, $ones): string {
        if ($n === 0) {
            return 'Zero';
        }
        $groups = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $label]) {
            if ($n >= $div) {
                $chunk = intdiv($n, $div);
                $groups[] = ($chunk >= 100 ? $toWords($chunk) : $two($chunk)) . ' ' . $label;
                $n %= $div;
            }
        }
        if ($n >= 100) {
            $groups[] = $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        $text = implode(', ', $groups);
        if ($n) {
            $text = $text === '' ? $two($n) : $text . ' And ' . $two($n);
        }
        return $text;
    };

    $amount = round($amount, 2);
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);
    return $toWords($rupees) . ($paise ? ' And ' . $two($paise) . ' Paise' : '') . ' only.';
}

/** "18% Integrated Goods and Services Tax" or CGST + SGST split. */
function doc_tax_label(float $rate, bool $inter): string
{
    if ($inter) {
        return doc_qty($rate) . '% Integrated Goods and Services Tax';
    }
    $half = doc_qty($rate / 2);
    return $half . '% Central Goods and Services Tax + ' . $half . '% State Goods and Services Tax';
}

function doc_render(array $doc): void
{
    $inter = ($doc['region'] ?? '') === 'Outside AP';
    $customer = $doc['customer'];
    $stateName = $inter ? '' : COMPANY_STATE;
    $stateCode = $inter ? '' : COMPANY_STATE_CODE;

    /* Line maths */
    $lines = [];
    $hsnRows = [];
    $totalQty = 0.0;
    $lineTotal = 0.0;
    $taxableTotal = 0.0;
    $taxTotal = 0.0;
    foreach ($doc['items'] as $it) {
        $qty = (float)$it['quantity'];
        $rate = (float)$it['rate'];
        $taxRate = (float)$it['tax'];
        $base = $qty * $rate;
        $taxable = $base - $base * (float)$it['discount'] / 100;
        $taxAmt = $taxable * $taxRate / 100;
        $amount = (float)$it['amount'];

        $lines[] = $it + [
            'rate_incl' => $rate * (1 + $taxRate / 100),
            'taxable' => $taxable,
            'tax_amount' => $taxAmt,
        ];
        $totalQty += $qty;
        $lineTotal += $amount;
        $taxableTotal += $taxable;
        $taxTotal += $taxAmt;

        if ($taxAmt > 0) {
            $hsnRows[] = ['name' => $it['name'], 'hsn' => $it['hsn'] ?: '-', 'rate' => $taxRate, 'tax' => $taxAmt];
        }
    }

    $amount = (float)$doc['amount'];
    $roundOff = round($amount - round($lineTotal, 2), 2);
    $fileName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $doc['file_name']);
    $logo = COMPANY_LOGO;
    $autoDownload = !empty($_GET['download']);
    $billAddress = $customer['bill_address'] ?? '';
    $shipAddress = ($customer['ship_address'] ?? '') ?: $billAddress;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($doc['title'] . ' ' . $doc['number'] . ' | ' . COMPANY_SHORT); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9edf2; font-family: "Segoe UI", Arial, Helvetica, sans-serif; color: #111; }

    .toolbar {
        position: sticky; top: 0; z-index: 5;
        display: flex; justify-content: center; flex-wrap: wrap; gap: 10px;
        padding: 12px; background: #fff; border-bottom: 1px solid #d5dbe3;
    }
    .toolbar a, .toolbar button {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 16px; border-radius: 8px; border: 1px solid #d5dbe3;
        background: #fff; color: #142238; font: 700 13.5px Arial, sans-serif;
        text-decoration: none; cursor: pointer;
    }
    .toolbar .primary { background: #1479ff; border-color: #1479ff; color: #fff; }
    .toolbar .green { background: #15803d; border-color: #15803d; color: #fff; }
    .toolbar button:disabled { opacity: .6; cursor: wait; }
    .note {
        max-width: 210mm; margin: 12px auto 0; padding: 9px 14px; border-radius: 8px;
        background: #dcfce7; color: #166534; font: 700 13px Arial, sans-serif;
    }

    /* A4 sheet */
    .sheet { width: 210mm; margin: 14px auto 30px; background: #fff; box-shadow: 0 8px 26px rgba(0,0,0,.12); }
    .doc { padding: 9mm 9mm 8mm; font-size: 10.5px; line-height: 1.35; }
    .doc.pdf-mode { padding: 5mm 8mm 5mm; min-width: 0; width: 210mm; }
    .doc-title { text-align: center; font-size: 15px; font-weight: 700; margin: 0 0 6px; letter-spacing: .3px; }

    .frame { border: 1px solid #555; }
    .row { display: flex; }
    .cell { padding: 6px 7px; }
    .bt { border-top: 1px solid #555; }
    .br { border-right: 1px solid #555; }
    .lbl { color: #4a5568; font-size: 9px; }
    .blue { color: #1f3f7a; }
    .val { font-weight: 700; color: #7a1010; }
    b, .b { font-weight: 700; }

    /* Header: company + meta grid */
    .company { width: 56%; display: flex; gap: 9px; align-items: flex-start; }
    .company img { width: 58px; height: 58px; object-fit: contain; flex-shrink: 0; }
    .company .cname { font-size: 12.5px; font-weight: 700; }
    .meta { width: 44%; display: grid; grid-template-columns: repeat(3, 1fr); }
    .meta .m { padding: 5px 6px; border-left: 1px solid #555; min-height: 38px; }
    .meta .m:nth-child(n+4) { border-top: 1px solid #555; }

    /* Parties */
    .parties { width: 56%; display: flex; }
    .party { width: 50%; padding: 6px 7px; }
    .party + .party { border-left: 1px solid #555; }
    .party .ptitle { font-weight: 700; margin-bottom: 2px; }
    .party .pname { font-weight: 700; text-transform: uppercase; }

    /* Items */
    table { border-collapse: collapse; width: 100%; }
    .items th {
        font-size: 9px; font-weight: 600; color: #4a5568; text-align: center;
        padding: 5px 4px; border-bottom: 1px solid #555; border-right: 1px solid #555; vertical-align: top;
    }
    .items td { padding: 5px 4px; border-right: 1px solid #555; vertical-align: top; }
    .items th:last-child, .items td:last-child { border-right: 0; }
    .items .r { text-align: right; white-space: nowrap; }
    .items .c { text-align: center; }
    .items .name { font-weight: 700; text-transform: uppercase; }
    .items .sub { color: #555; font-size: 9px; }
    .items .taxline { font-size: 8.5px; color: #333; white-space: normal; }
    .items .spacer td { height: 150px; }
    .items .total td { border-top: 1px solid #555; font-weight: 700; padding: 6px 4px; }

    .words { padding: 6px 7px 7px; }
    .words .w { font-style: italic; font-weight: 700; font-size: 11px; margin-top: 2px; }

    .hsn { width: calc(100% - 14px); margin: 0 7px 6px; border: 1px solid #888; }
    .hsn th { font-size: 9px; color: #1f3f7a; font-weight: 600; text-align: left; padding: 5px 6px; border-bottom: 1px solid #888; }
    .hsn td { padding: 6px; border-bottom: 1px solid #ccc; font-size: 9.5px; }
    .hsn .r { text-align: right; }
    .hsn .sum td { color: #1f3f7a; font-weight: 600; border-bottom: 0; }
    .hsn tr > :first-child { width: 30%; }
    .taxwords { padding: 0 7px 6px; }

    .bottom { display: flex; }
    .bottom > div { width: 50%; padding: 6px 7px 10px; }

    .sign { display: flex; border: 1px solid #555; margin-top: 10px; min-height: 64px; }
    .sign > div { width: 50%; padding: 7px 8px; }
    .sign > div + div { border-left: 1px solid #555; text-align: right; display: flex; flex-direction: column; justify-content: center; }
    .computer { text-align: center; font-size: 9px; font-weight: 600; margin-top: 5px; }

    @media screen and (max-width: 840px) {
        .sheet { width: auto; margin: 10px; overflow-x: auto; }
        .doc { min-width: 760px; }
    }
    @page { size: A4; margin: 0; }
    @media print {
        body { background: #fff; }
        .toolbar, .note { display: none !important; }
        .sheet { margin: 0; box-shadow: none; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="<?php echo e($doc['back_url']); ?>"><i class="bi bi-arrow-left"></i> <?php echo e($doc['back_label']); ?></a>
    <?php if (!empty($doc['new_url'])): ?>
        <a href="<?php echo e($doc['new_url']); ?>"><i class="bi bi-plus-lg"></i> <?php echo e($doc['new_label']); ?></a>
    <?php endif; ?>
    <button type="button" class="green" id="downloadPdf"><i class="bi bi-download"></i> Download PDF</button>
    <button type="button" class="primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<?php if (!empty($doc['saved_note'])): ?>
    <div class="note"><i class="bi bi-check-circle-fill"></i> <?php echo e($doc['saved_note']); ?></div>
<?php endif; ?>

<div class="sheet">
<div class="doc" id="docPage">

    <h1 class="doc-title"><?php echo e($doc['title']); ?></h1>

    <div class="frame">

        <!-- COMPANY + META -->
        <div class="row">
            <div class="cell company">
                <?php if ($logo): ?><img src="<?php echo e($logo); ?>" alt=""><?php endif; ?>
                <div>
                    <div class="cname"><?php echo e(COMPANY_NAME); ?></div>
                    <?php if (COMPANY_PROP): ?><div class="b">PROP : <?php echo e(COMPANY_PROP); ?></div><?php endif; ?>
                    <?php if (COMPANY_ADDRESS): ?><div><?php echo nl2br(e(COMPANY_ADDRESS)); ?></div><?php endif; ?>
                    <?php if (COMPANY_PHONE): ?><div class="b">CELL - <?php echo e(COMPANY_PHONE); ?></div><?php endif; ?>
                    <?php if (COMPANY_EMAIL): ?><div><?php echo e(COMPANY_EMAIL); ?></div><?php endif; ?>
                    <?php if (COMPANY_GSTIN): ?><div class="b">GSTIN/UIN: <?php echo e(COMPANY_GSTIN); ?></div><?php endif; ?>
                    <div>State Name : <?php echo e(COMPANY_STATE); ?>, Code : <?php echo e(COMPANY_STATE_CODE); ?></div>
                </div>
            </div>
            <div class="meta">
                <?php foreach ($doc['meta'] as [$label, $value]): ?>
                    <div class="m">
                        <div class="lbl"><?php echo e($label); ?></div>
                        <div class="val"><?php echo e($value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- PARTIES -->
        <div class="row bt">
            <div class="parties br">
                <div class="party">
                    <div class="ptitle">Consignee (Ship to)</div>
                    <div class="pname"><?php echo e($customer['name']); ?></div>
                    <?php if ($shipAddress): ?><div class="blue">Customer Address (<?php echo nl2br(e($shipAddress)); ?>)</div><?php endif; ?>
                    <?php if (!empty($customer['mobile'])): ?><div class="blue">Mobile : <?php echo e($customer['mobile']); ?></div><?php endif; ?>
                    <?php if (!empty($customer['gstin'])): ?><div class="blue">GSTIN/UIN : <?php echo e($customer['gstin']); ?></div><?php endif; ?>
                    <?php if ($stateName): ?><div class="blue">State Name : <?php echo e($stateName); ?>, Code : <?php echo e($stateCode); ?></div><?php endif; ?>
                </div>
                <div class="party">
                    <div class="ptitle">Buyer (Bill to)</div>
                    <div class="pname"><?php echo e($customer['name']); ?></div>
                    <?php if ($billAddress): ?><div class="blue">Customer Address (<?php echo nl2br(e($billAddress)); ?>)</div><?php endif; ?>
                    <?php if (!empty($customer['mobile'])): ?><div class="blue">Mobile : <?php echo e($customer['mobile']); ?></div><?php endif; ?>
                    <?php if (!empty($customer['gstin'])): ?><div class="blue">GSTIN/UIN : <?php echo e($customer['gstin']); ?></div><?php endif; ?>
                    <?php if ($stateName): ?><div class="blue">State Name : <?php echo e($stateName); ?>, Code : <?php echo e($stateCode); ?></div><?php endif; ?>
                </div>
            </div>
            <div style="width:44%"></div>
        </div>

        <!-- ITEMS -->
        <div class="bt">
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:24px">Sl<br>No.</th>
                        <th style="text-align:left">Description of Goods / Services</th>
                        <th style="width:58px">HSN/SAC</th>
                        <th style="width:38px">Qty</th>
                        <th style="width:62px">Rate<br>(Incl. of<br>Tax)</th>
                        <th style="width:62px">Rate</th>
                        <th style="width:34px">Disc.<br>%</th>
                        <th style="width:118px">Tax Details</th>
                        <th style="width:84px">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $i => $l): ?>
                    <tr>
                        <td class="c"><?php echo $i + 1; ?></td>
                        <td>
                            <div class="name"><?php echo e($l['name']); ?></div>
                            <?php $sub = implode(' | ', array_filter([$l['thickness'] ?? '', $l['size'] ?? ''])); ?>
                            <?php if ($sub): ?><div class="sub"><?php echo e($sub); ?></div><?php endif; ?>
                        </td>
                        <td class="c"><?php echo e($l['hsn'] ?: ''); ?></td>
                        <td class="c"><?php echo e(doc_qty((float)$l['quantity'])); ?></td>
                        <td class="r"><?php echo doc_money($l['rate_incl']); ?></td>
                        <td class="r"><?php echo doc_money((float)$l['rate']); ?></td>
                        <td class="c"><?php echo (float)$l['discount'] ? e(doc_qty((float)$l['discount'])) : ''; ?></td>
                        <td class="r">
                            <b><?php echo doc_money($l['tax_amount']); ?></b>
                            <?php if ($l['tax_amount'] > 0): ?>
                                <div class="taxline"><?php echo e($inter
                                    ? doc_tax_label((float)$l['tax'], true)
                                    : doc_qty((float)$l['tax'] / 2) . '% CGST + ' . doc_qty((float)$l['tax'] / 2) . '% SGST'); ?> : <?php echo doc_money($l['tax_amount']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="r b"><?php echo doc_money((float)$l['amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (abs($roundOff) >= 0.01): ?>
                    <tr>
                        <td></td><td class="r"><i>Round Off</i></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        <td class="r b"><?php echo ($roundOff > 0 ? '' : '(-)') . doc_money(abs($roundOff)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="spacer"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    <tr class="total">
                        <td></td>
                        <td class="r">Total</td>
                        <td></td>
                        <td class="c"><?php echo e(doc_qty($totalQty)); ?></td>
                        <td></td><td></td><td></td><td></td>
                        <td class="r">&#8377; <?php echo doc_money($amount); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- AMOUNT IN WORDS -->
        <div class="words bt">
            <div>Amount Chargeable (in words)</div>
            <div class="w"><?php echo e(doc_words($amount)); ?></div>
        </div>

        <!-- HSN / TAX SUMMARY -->
        <div class="bt" style="padding-top:7px">
            <table class="hsn">
                <thead>
                    <tr><th>Taxable Item</th><th>HSN Code</th><th>Tax Applied</th><th class="r">Total Tax</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($hsnRows as $h): ?>
                    <tr>
                        <td class="b" style="text-transform:uppercase"><?php echo e($h['name']); ?></td>
                        <td><?php echo e($h['hsn']); ?></td>
                        <td><?php echo e(doc_tax_label($h['rate'], $inter)); ?></td>
                        <td class="r b"><?php echo doc_money($h['tax']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$hsnRows): ?>
                    <tr><td colspan="4" style="color:#666">No taxable items.</td></tr>
                    <?php endif; ?>
                    <tr class="sum">
                        <td class="r">Total</td>
                        <td colspan="2" class="r">&#8377; <?php echo doc_money($taxableTotal); ?></td>
                        <td class="r"><?php echo doc_money($taxTotal); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="taxwords">Tax Amount (in words) : <b><?php echo e(doc_words($taxTotal)); ?></b></div>
        </div>

        <!-- REMARKS + BANK -->
        <div class="bottom bt">
            <div class="br">
                <div class="b">Remarks:</div>
                <div class="blue"><?php echo e(trim(preg_replace('/\s*\n\s*/', ' ', (string)($doc['notes'] ?? ''))) ?: '-'); ?></div>
                <div class="b" style="margin-top:6px">Declaration</div>
                <div class="blue">We declare that this <?php echo $doc['title'] === 'Quotation' ? 'quotation' : 'invoice'; ?> shows the actual price of the goods described and that all particulars are true and correct.</div>
            </div>
            <div>
                <div class="b">Company's Bank Details</div>
                <?php if (COMPANY_BANK_HOLDER): ?><div class="b">A/c Holder's Name: <?php echo e(COMPANY_BANK_HOLDER); ?></div><?php endif; ?>
                <?php if (COMPANY_BANK_NAME): ?><div class="b">Bank Name: <?php echo e(COMPANY_BANK_NAME); ?></div><?php endif; ?>
                <?php if (COMPANY_BANK_ACCOUNT): ?><div class="b">A/c No. : <?php echo e(COMPANY_BANK_ACCOUNT); ?></div><?php endif; ?>
                <?php if (COMPANY_BANK_BRANCH_IFSC): ?><div class="b">Branch &amp; IFS Code: <?php echo e(COMPANY_BANK_BRANCH_IFSC); ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SIGNATURES -->
    <div class="sign">
        <div class="blue">Customer's Seal and Signature</div>
        <div>
            <div class="b">for <?php echo e(COMPANY_NAME); ?></div>
            <div class="blue">Authorised Signatory</div>
        </div>
    </div>
    <div class="computer"><?php echo e($doc['footer_note']); ?></div>

</div>
</div>

<script>
(function () {
    const btn = document.getElementById("downloadPdf");
    const fileName = <?php echo json_encode($fileName . '.pdf', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    async function downloadPdf() {
        if (typeof html2pdf === "undefined") {
            alert("PDF library could not load (check internet). Use Print -> Save as PDF instead.");
            return;
        }
        btn.disabled = true;
        const label = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Preparing PDF...';

        // html2canvas measures from the page's scroll position and the sheet's
        // centred position, which adds empty space on top / cuts the edges.
        // So render a copy pinned to the top-left corner of the page at exact A4 width.
        const prevScroll = window.scrollY;
        window.scrollTo(0, 0);

        const holder = document.createElement("div");
        holder.style.cssText = "position:absolute;left:0;top:0;width:210mm;background:#fff;z-index:-1;";
        const copy = document.getElementById("docPage").cloneNode(true);
        copy.removeAttribute("id");
        copy.classList.add("pdf-mode");
        holder.appendChild(copy);
        document.body.appendChild(holder);

        try {
            await html2pdf().set({
                margin: 0,
                filename: fileName,
                image: { type: "jpeg", quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: "#ffffff", scrollX: 0, scrollY: 0 },
                jsPDF: { unit: "mm", format: "a4", orientation: "portrait" },
                pagebreak: { mode: ["css", "legacy"], avoid: ["tr", ".sign", ".bottom"] }
            }).from(copy).save();
        } finally {
            holder.remove();
            window.scrollTo(0, prevScroll);
            btn.disabled = false;
            btn.innerHTML = label;
        }
    }

    btn.addEventListener("click", downloadPdf);
    <?php if ($autoDownload): ?>
    window.addEventListener("load", downloadPdf);
    <?php endif; ?>
})();
</script>
</body>
</html>
    <?php
}

/** Customer block from the customers table (addresses JSON: [{label, address}]). */
function doc_customer(?int $customerId, string $fallbackName): array
{
    $c = $customerId ? db_one('SELECT name, mobile, gstin, addresses FROM customers WHERE id = ?', [$customerId]) : null;
    $bill = '';
    $ship = '';
    if ($c && $c['addresses']) {
        $list = json_decode($c['addresses'], true);
        if (is_array($list)) {
            foreach ($list as $a) {
                $label = strtolower((string)($a['label'] ?? ''));
                $text = trim((string)($a['address'] ?? ''));
                if ($text === '') {
                    continue;
                }
                if ($ship === '' && str_contains($label, 'ship')) {
                    $ship = $text;
                } elseif ($bill === '' && !str_contains($label, 'ship')) {
                    $bill = $text;
                }
            }
            if ($bill === '' && $ship !== '') {
                $bill = $ship;
            }
        }
    }
    return [
        'name' => $c['name'] ?? $fallbackName,
        'mobile' => $c['mobile'] ?? '',
        'gstin' => $c['gstin'] ?? '',
        'bill_address' => $bill,
        'ship_address' => $ship,
    ];
}
