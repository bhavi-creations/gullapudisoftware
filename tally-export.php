<?php
/*
|--------------------------------------------------------------------------
| TALLY SALES EXPORT - invoices from MySQL
|--------------------------------------------------------------------------
| GST split: Inside AP -> CGST + SGST (half each), Outside AP -> IGST.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$today = date('Y-m-d');
$defaultStart = date('Y-m-01');
$defaultEnd = $today;

$invoices = db_all(
    "SELECT i.id, i.invoice_number, i.customer, i.date, i.amount, i.tax, i.region, i.status,
            o.order_number, o.reference
     FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
     WHERE i.status NOT IN ('Draft', 'Cancelled')
     ORDER BY i.date, i.id"
);

$exportRows = [];

foreach ($invoices as $invoice) {

    $gst = round((float)$invoice['tax'], 2);

    if ($gst > 0) {
        $taxLedgers = ($invoice['region'] ?? 'Inside AP') === 'Outside AP'
            ? 'IGST: ' . number_format($gst, 2, '.', '')
            : 'CGST: ' . number_format($gst / 2, 2, '.', '') . ', SGST: ' . number_format($gst / 2, 2, '.', '');
    } else {
        $taxLedgers = '';
    }

    $exportRows[] = [
        'id' => (int)$invoice['id'],
        'date' => (string)$invoice['date'],
        'voucher' => 'Sales',
        'invoice_number' => (string)$invoice['invoice_number'],
        'invoice_date' => (string)$invoice['date'],
        'reference' => (string)($invoice['reference'] ?: ($invoice['order_number'] ?? '')),
        'customer' => (string)$invoice['customer'],
        'total' => (float)$invoice['amount'],
        'total_gst' => $gst,
        'tax_ledgers' => $taxLedgers,
        'narration' => 'Sales ' . $invoice['invoice_number'] . ($invoice['order_number'] ? ' / ' . $invoice['order_number'] : '')
    ];
}

$pageTitle = 'Tally Export & GST';
include 'header.php';
?>




<!-- ====================================
     TALLY MAIN SECTION
==================================== -->

<section class="tally-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER AND FILTERS -->
    <div class="row align-items-end g-4 tally-header">

        <!-- TITLE -->
        <div class="col-12 col-xl-5">

            <h1 class="tally-title">
                Tally Sales Export
            </h1>

            <p class="tally-subtitle">
                Export sales invoices in the attached
                Tally Excel format.
            </p>

        </div>

        <!-- FILTERS -->
        <div class="col-12 col-xl-7">

            <div class="tally-filters">

                <!-- START DATE -->
                <div class="tally-filter-group">

                    <label for="tallyStartDate"
                           class="tally-filter-label">
                        Start Date
                    </label>

                    <input type="date"
                           id="tallyStartDate"
                           class="tally-date-input"
                           value="<?php echo htmlspecialchars($defaultStart); ?>">

                </div>

                <!-- END DATE -->
                <div class="tally-filter-group">

                    <label for="tallyEndDate"
                           class="tally-filter-label">
                        End Date
                    </label>

                    <input type="date"
                           id="tallyEndDate"
                           class="tally-date-input"
                           value="<?php echo htmlspecialchars($defaultEnd); ?>">

                </div>

                <!-- LOAD INVOICES -->
                <button type="button"
                        class="tally-load-btn"
                        id="loadInvoicesBtn">

                    Load Invoices

                </button>

                <!-- EXPORT EXCEL -->
                <button type="button"
                        class="tally-export-btn"
                        id="exportTallyBtn">

                    <i class="bi bi-file-earmark-excel"></i>
                    Export Excel

                </button>

            </div>

        </div>

    </div>

    <!-- ERROR / SUCCESS MESSAGES -->
    <div id="tallyMessage"
         class="alert tally-message d-none"
         role="status">
    </div>

    <!-- SUMMARY -->
    <div class="tally-summary">

        <div class="tally-summary-count">

            Sales invoices:
            <span id="tallyInvoiceCount">0</span>

        </div>

        <div class="tally-summary-description">

            Each invoice exports as one row with customer,
            GST, and individual tax ledgers.

        </div>

    </div>

    <!-- SALES INVOICE TABLE -->
    <div class="tally-table-panel">

        <div class="table-responsive">

            <table class="tally-table">

                <thead>

                    <tr>

                        <th>Date</th>

                        <th>Voucher</th>

                        <th>Invoice No.</th>

                        <th>Invoice Date</th>

                        <th>Reference No.</th>

                        <th>Customer</th>

                        <th>Invoice Total</th>

                        <th>Total GST</th>

                        <th>Tax Ledgers</th>

                        <th>Narration</th>

                    </tr>

                </thead>

                <tbody id="tallyInvoicesBody">

                    <tr>
                        <td colspan="10"
                            class="tally-empty">

                            No sales invoices found for this date range.

                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</div>

</section>

<!-- BOOTSTRAP JS -->

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    "use strict";

    /*
     * PHP INVOICE DATA
     */

    const invoices = <?php echo json_encode(
        $exportRows,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const $ = id => document.getElementById(id);

    let filteredInvoices = [];
    let loadedRange = null;

    /*
     * HELPERS
     */

    function escapeHTML(value) {

        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function formatDate(value) {

        if (!value) return "-";

        const parts = value.split("-").map(Number);

        if (parts.length !== 3) return value;

        const [year, month, day] = parts;

        return String(day).padStart(2, "0") + "-" +
            String(month).padStart(2, "0") + "-" +
            year;
    }

    function formatMoney(value) {

        if (value === null || value === undefined) {
            return "-";
        }

        return "Rs " + Number(value).toLocaleString(
            "en-IN",
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
    }

    function showMessage(text, type = "danger") {

        const box = $("tallyMessage");

        box.className = "alert tally-message alert-" + type;

        box.textContent = text;

        box.classList.remove("d-none");
    }

    function hideMessage() {
        $("tallyMessage").classList.add("d-none");
    }

    /*
     * DATE VALIDATION
     */

    function getSelectedRange() {

        const startDate = $("tallyStartDate").value;
        const endDate = $("tallyEndDate").value;

        if (!startDate || !endDate) {

            showMessage("Please select Start Date and End Date.");
            return null;
        }

        if (startDate > endDate) {

            showMessage(
                "End Date must be on or after Start Date."
            );

            return null;
        }

        return {
            startDate,
            endDate
        };
    }

    /*
     * LOAD INVOICES
     */

    function loadInvoices() {

        hideMessage();

        const range = getSelectedRange();

        if (!range) return;

        filteredInvoices = invoices.filter(invoice => {

            return invoice.date >= range.startDate &&
                   invoice.date <= range.endDate;
        });

        loadedRange = range;

        renderInvoices();
    }

    /*
     * RENDER INVOICE TABLE
     */

    function renderInvoices() {

        const tbody = $("tallyInvoicesBody");

        tbody.innerHTML = "";

        $("tallyInvoiceCount").textContent =
            filteredInvoices.length;

        if (!filteredInvoices.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="10" class="tally-empty">
                        No sales invoices found for this date range.
                    </td>
                </tr>
            `;

            return;
        }

        filteredInvoices.forEach(invoice => {

            const row = document.createElement("tr");

            row.innerHTML = `

                <td>
                    ${escapeHTML(formatDate(invoice.date))}
                </td>

                <td>
                    ${escapeHTML(invoice.voucher)}
                </td>

                <td>
                    <span class="tally-invoice-number">
                        ${escapeHTML(invoice.invoice_number)}
                    </span>
                </td>

                <td>
                    ${escapeHTML(formatDate(invoice.invoice_date))}
                </td>

                <td>
                    ${escapeHTML(invoice.reference || "-")}
                </td>

                <td>
                    <span class="tally-customer">
                        ${escapeHTML(invoice.customer)}
                    </span>
                </td>

                <td>
                    ${escapeHTML(formatMoney(invoice.total))}
                </td>

                <td>
                    ${invoice.total_gst === null
                        ? "-"
                        : escapeHTML(formatMoney(invoice.total_gst))}
                </td>

                <td>
                    ${escapeHTML(invoice.tax_ledgers || "-")}
                </td>

                <td>
                    ${escapeHTML(invoice.narration || "-")}
                </td>
            `;

            tbody.appendChild(row);
        });
    }

    /*
     * EXCEL EXPORT
     */

    function exportExcel() {

        hideMessage();

        if (!loadedRange) {
            showMessage(
                "Please click Load Invoices before exporting."
            );
            return;
        }

        if (!filteredInvoices.length) {

            showMessage(
                "No sales invoices available for the selected date range."
            );

            return;
        }

        if (typeof XLSX === "undefined") {

            showMessage(
                "Excel library is unavailable. Check your internet connection."
            );

            return;
        }

        /*
         * One invoice = one Excel row.
         * Missing GST and ledger details stay blank.
         */

        const excelRows = filteredInvoices.map(invoice => ({

            "Date": formatDate(invoice.date),

            "Voucher": invoice.voucher,

            "Invoice No.": invoice.invoice_number,

            "Invoice Date": formatDate(invoice.invoice_date),

            "Reference No.": invoice.reference || "",

            "Customer": invoice.customer,

            "Invoice Total": Number(invoice.total),

            "Total GST": invoice.total_gst === null
                ? ""
                : Number(invoice.total_gst),

            "Tax Ledgers": invoice.tax_ledgers || "",

            "Narration": invoice.narration || ""

        }));

        const worksheet = XLSX.utils.json_to_sheet(
            excelRows
        );

        worksheet["!cols"] = [

            { wch: 16 },
            { wch: 16 },
            { wch: 18 },
            { wch: 16 },
            { wch: 22 },
            { wch: 30 },
            { wch: 19 },
            { wch: 17 },
            { wch: 38 },
            { wch: 35 }

        ];

        /*
         * Currency columns are numeric.
         */

        for (let row = 2; row <= excelRows.length + 1; row++) {

            ["G", "H"].forEach(column => {

                const cell = worksheet[column + row];

                if (cell && cell.t === "n") {
                    cell.z = '#,##0.00';
                }
            });
        }

        const workbook = XLSX.utils.book_new();

        XLSX.utils.book_append_sheet(
            workbook,
            worksheet,
            "Sales Invoices"
        );

        const fileName =
            "Tally_Sales_Export_" +
            loadedRange.startDate +
            "_to_" +
            loadedRange.endDate +
            ".xlsx";

        XLSX.writeFile(
            workbook,
            fileName
        );
    }

    /*
     * EVENT LISTENERS
     */

    $("loadInvoicesBtn").addEventListener(
        "click",
        loadInvoices
    );

    $("exportTallyBtn").addEventListener(
        "click",
        exportExcel
    );

    /*
     * Require a reload if dates change.
     */

    ["tallyStartDate", "tallyEndDate"].forEach(id => {

        $(id).addEventListener("change", function () {

            loadedRange = null;

            filteredInvoices = [];

            $("tallyInvoiceCount").textContent = "0";

            $("tallyInvoicesBody").innerHTML = `
                <tr>
                    <td colspan="10" class="tally-empty">
                        Click Load Invoices to view results.
                    </td>
                </tr>
            `;

            hideMessage();
        });
    });

    /*
     * INITIAL PAGE LOAD
     */

    loadInvoices();

})();
</script>



<?php include 'footer.php'; ?>