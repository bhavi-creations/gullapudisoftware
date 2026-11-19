<?php
/* ==========================================
   PAYMENTS PAGE - data from MySQL `payments` + `invoices`
   Saving a payment updates the invoice status automatically
   (Submitted -> Partially Paid -> Paid).
========================================== */
require_once __DIR__ . '/includes/init.php';

function payment_invoices(): array
{
    return cast_rows(
        db_all("SELECT invoice_number AS number, customer, customer_id, amount, due_date
                FROM invoices WHERE status NOT IN ('Draft', 'Cancelled') ORDER BY id DESC"),
        [],
        ['amount']
    );
}

function payment_entries(): array
{
    return cast_rows(
        db_all('SELECT id, date, type, invoice, customer, narration, mode, amount, reference FROM payments ORDER BY date DESC, id DESC'),
        [],
        ['amount']
    );
}

function paymentBalance($invoiceNumber) {
    $invoiceAmount = (float)db_value('SELECT amount FROM invoices WHERE invoice_number = ?', [$invoiceNumber]);
    $received = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
    return round($invoiceAmount - $received, 2);
}

/* SAVE PAYMENT VIA AJAX */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_payment') {
        $payment = db_one('SELECT * FROM payments WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$payment) {
            json_response(['success' => false, 'message' => 'Payment not found.'], 404);
        }
        db_exec('DELETE FROM payments WHERE id = ?', [$payment['id']]);
        if ($payment['invoice']) {
            refresh_invoice_status($payment['invoice']);
        }
        log_activity('Payments', 'Deleted payment ' . $payment['narration'] . ' (Rs ' . number_format((float)$payment['amount'], 2) . ')');
        json_response(['success' => true, 'payments' => payment_entries()]);
    }

    if ($action !== 'save_payment') {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $invoiceNumber = trim($_POST['invoice'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $mode = trim($_POST['mode'] ?? '');
    $reference = trim($_POST['reference'] ?? '');

    $allowedModes = [
        'Cash', 'UPI', 'Bank Transfer',
        'Cheque', 'Card'
    ];

    $invoiceRecord = db_one(
        "SELECT id, invoice_number, customer, customer_id FROM invoices
         WHERE invoice_number = ? AND status NOT IN ('Draft', 'Cancelled')",
        [$invoiceNumber]
    );

    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (
        !$invoiceRecord ||
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $date ||
        !in_array($mode, $allowedModes, true) ||
        !is_numeric($amount) ||
        !is_finite((float)$amount) ||
        (float)$amount <= 0
    ) {
        json_response(['success' => false, 'message' => 'Please enter valid payment details.'], 422);
    }

    $balance = paymentBalance($invoiceNumber);

    if (round((float)$amount, 2) > $balance) {
        json_response(['success' => false, 'message' => 'Payment exceeds invoice balance (Rs ' . number_format($balance, 2) . ').'], 422);
    }

    $narration = next_number('payments', 'narration', 'PAY-');

    $id = db_insert('payments', [
        'date' => $date,
        'type' => 'Payment Received',
        'invoice' => $invoiceNumber,
        'invoice_id' => (int)$invoiceRecord['id'],
        'customer_id' => $invoiceRecord['customer_id'] ? (int)$invoiceRecord['customer_id'] : null,
        'customer' => $invoiceRecord['customer'],
        'narration' => $narration,
        'mode' => $mode,
        'amount' => round((float)$amount, 2),
        'reference' => mb_substr($reference, 0, 100) ?: null,
    ]);

    refresh_invoice_status($invoiceNumber);
    log_activity('Payments', "Payment $narration of Rs " . number_format((float)$amount, 2) . " received for $invoiceNumber");

    $payment = cast_rows([db_one('SELECT id, date, type, invoice, customer, narration, mode, amount, reference FROM payments WHERE id = ?', [$id])], [], ['amount'])[0];
    $payment['reference'] = $payment['reference'] ?? '';

    json_response([
        'success' => true,
        'message' => 'Payment saved successfully.',
        'payment' => $payment
    ]);
}

$payments = payment_entries();
$invoices = payment_invoices();
$pageTitle = 'Payments';
include 'header.php';
?>





<!-- ==========================================
     PAYMENTS MAIN SECTION
========================================== -->

<section class="payment-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-3">

        <div class="col-md-8">
            <h1 class="pay-title">Payments</h1>

            <p class="pay-subtitle">
                Track payment entries, reference numbers,
                and settlement status
            </p>
        </div>

        <div class="col-md-4 text-md-end">

            <button type="button"
                    class="pay-add-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#addPaymentModal">

                <i class="bi bi-plus-lg"></i>
                Add Payment

            </button>

        </div>

    </div>

    <!-- STATISTICS -->
    <div class="row g-3 mb-3">

        <!-- TOTAL INVOICED AMOUNT -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="pay-stat-card">

                <div class="pay-stat-icon pay-blue">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

                <div>
                    <div class="pay-stat-label">
                        Total Invoiced Amount
                    </div>

                    <div class="pay-stat-value"
                         id="totalInvoiced">
                        Rs 7,85,760
                    </div>

                    <div class="pay-stat-note">
                        From invoices
                    </div>
                </div>

            </div>

        </div>

        <!-- TOTAL PAYMENTS RECEIVED -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="pay-stat-card">

                <div class="pay-stat-icon pay-green">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div>
                    <div class="pay-stat-label">
                        Total Payments Received
                    </div>

                    <div class="pay-stat-value"
                         id="totalReceived">
                        Rs 80,008
                    </div>

                    <div class="pay-stat-note">
                        All received payments
                    </div>
                </div>

            </div>

        </div>

        <!-- PENDING PAYMENTS -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="pay-stat-card">

                <div class="pay-stat-icon pay-orange">
                    <i class="bi bi-clock"></i>
                </div>

                <div>
                    <div class="pay-stat-label">
                        Pending Payments
                    </div>

                    <div class="pay-stat-value"
                         id="pendingPayments">
                        Rs 7,05,752
                    </div>

                    <div class="pay-stat-note">
                        Pending invoice balance
                    </div>
                </div>

            </div>

        </div>

        <!-- PAYMENTS TODAY -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="pay-stat-card">

                <div class="pay-stat-icon pay-purple">
                    <i class="bi bi-calendar-event"></i>
                </div>

                <div>
                    <div class="pay-stat-label">
                        Total Payments Today
                    </div>

                    <div class="pay-stat-value"
                         id="paymentsToday">
                        Rs 0
                    </div>

                    <div class="pay-stat-note">
                        Received today
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- PAYMENTS LIST PANEL -->
    <div class="pay-panel">

        <!-- TABS -->
        <div class="pay-tabs">

            <button type="button"
                    class="pay-tab active"
                    data-tab="all">

                All Payments
                (<span id="allPaymentsCount">2</span>)

            </button>

            <button type="button"
                    class="pay-tab"
                    data-tab="Paid">

                Paid
                (<span id="paidCount">0</span>)

            </button>

            <button type="button"
                    class="pay-tab"
                    data-tab="Pending">

                Pending
                (<span id="pendingCount">2</span>)

            </button>

            <button type="button"
                    class="pay-tab"
                    data-tab="Overdue">

                Overdue
                (<span id="overdueCount">0</span>)

            </button>

        </div>

        <!-- SEARCH AND FILTER -->
        <div class="pay-toolbar">

            <div class="pay-search">

                <i class="bi bi-search"></i>

                <input type="search"
                       id="paymentSearch"
                       placeholder="Search by party or reference...">

            </div>

            <!-- FILTER DROPDOWN -->
            <div class="dropdown">

                <button type="button"
                        class="pay-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">

                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter

                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:255px">

                    <label class="form-label fw-bold">
                        Payment Mode
                    </label>

                    <select class="form-select mb-3"
                            id="filterMode">

                        <option value="all">
                            All Modes
                        </option>

                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank Transfer">
                            Bank Transfer
                        </option>
                        <option value="Cheque">Cheque</option>
                        <option value="Card">Card</option>

                    </select>

                    <label class="form-label fw-bold">
                        Date From
                    </label>

                    <input type="date"
                           class="form-control mb-3"
                           id="filterDateFrom">

                    <label class="form-label fw-bold">
                        Date To
                    </label>

                    <input type="date"
                           class="form-control mb-3"
                           id="filterDateTo">

                    <button type="button"
                            class="btn btn-outline-secondary w-100"
                            id="resetFilters">

                        Reset Filters

                    </button>

                </div>

            </div>

            <!-- RESET -->
            <button type="button"
                    class="pay-tool-btn"
                    id="resetView"
                    title="Reset Filters">

                <i class="bi bi-sliders2"></i>

            </button>

        </div>

        <!-- PAYMENT TABLE -->
        <div class="table-responsive">

            <table class="table pay-table">

                <thead>
                    <tr>
                        <th>S No</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Invoice Number</th>
                        <th>Customer</th>
                        <th>Narration</th>
                        <th>Payment Mode</th>
                        <th>Payment (Rs)</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody id="paymentTableBody">
                    <!-- JavaScript generated rows -->
                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="pay-footer">

            <span id="paginationInfo">
                Showing 1 to 2 of 2 entries
            </span>

            <div class="pay-pagination">

                <button type="button"
                        class="pay-page-btn"
                        id="firstPage">
                    &lt;&lt;
                </button>

                <button type="button"
                        class="pay-page-btn"
                        id="prevPage">
                    &lt;
                </button>

                <button type="button"
                        class="pay-page-btn active"
                        id="currentPage">
                    1
                </button>

                <button type="button"
                        class="pay-page-btn"
                        id="nextPage">
                    &gt;
                </button>

                <button type="button"
                        class="pay-page-btn"
                        id="lastPage">
                    &gt;&gt;
                </button>

                <select id="pageSize"
                        class="pay-page-size">

                    <option value="10">10 / page</option>
                    <option value="20" selected>20 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>

                </select>

            </div>

        </div>

    </div>

</div>
</section>

<!-- ==========================================
     ADD PAYMENT MODAL
========================================== -->

<div class="modal fade"
     id="addPaymentModal"
     tabindex="-1"
     aria-labelledby="addPaymentTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>
                    <h4 class="modal-title fw-bold"
                        id="addPaymentTitle">
                        Add Payment
                    </h4>

                    <p class="text-secondary mb-0 mt-1">
                        Record a payment received from a customer.
                    </p>
                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <form id="addPaymentForm">

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- INVOICE -->
                        <div class="col-md-6">

                            <label for="newPaymentInvoice"
                                   class="form-label">
                                Invoice Number *
                            </label>

                            <select class="form-select"
                                    id="newPaymentInvoice"
                                    required>

                                <option value="">
                                    Select Invoice
                                </option>

                                <?php foreach ($invoices as $invoice): ?>

                                <option value="<?php
                                    echo htmlspecialchars(
                                        $invoice['number'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                ?>">
                                    <?php echo htmlspecialchars(
                                        $invoice['number'] . ' - ' .
                                        $invoice['customer']
                                    ); ?>
                                </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- DATE -->
                        <div class="col-md-6">

                            <label for="newPaymentDate"
                                   class="form-label">
                                Payment Date *
                            </label>

                            <input type="date"
                                   class="form-control"
                                   id="newPaymentDate"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   required>

                        </div>

                        <!-- PAYMENT MODE -->
                        <div class="col-md-6">

                            <label for="newPaymentMode"
                                   class="form-label">
                                Payment Mode *
                            </label>

                            <select class="form-select"
                                    id="newPaymentMode"
                                    required>

                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">
                                    Bank Transfer
                                </option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>

                            </select>

                        </div>

                        <!-- AMOUNT -->
                        <div class="col-md-6">

                            <label for="newPaymentAmount"
                                   class="form-label">
                                Payment Amount *
                            </label>

                            <input type="number"
                                   class="form-control"
                                   id="newPaymentAmount"
                                   min="0.01"
                                   step="0.01"
                                   placeholder="Enter amount"
                                   required>

                        </div>

                        <!-- REFERENCE -->
                        <div class="col-12">

                            <label for="newPaymentReference"
                                   class="form-label">
                                Reference Number
                            </label>

                            <input type="text"
                                   class="form-control"
                                   id="newPaymentReference"
                                   maxlength="100"
                                   placeholder="Enter UTR / Transaction Reference">

                        </div>

                        <!-- BALANCE INFO -->
                        <div class="col-12">

                            <div class="alert alert-primary mb-0"
                                 id="invoiceBalanceInfo">

                                Select an invoice to view its pending balance.

                            </div>

                        </div>

                        <!-- ERROR -->
                        <div class="col-12">

                            <div class="alert alert-danger d-none"
                                 id="addPaymentError"
                                 role="alert"></div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light border px-4"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary px-4"
                            id="savePaymentBtn">

                        <i class="bi bi-check-lg me-1"></i>
                        Save Payment

                    </button>

                </div>

            </form>

        </div>

    </div>
</div>

<!-- ==========================================
     VIEW PAYMENT MODAL
========================================== -->

<div class="modal fade"
     id="viewPaymentModal"
     tabindex="-1"
     aria-labelledby="viewPaymentTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title"
                    id="viewPaymentTitle">
                    Payment Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body"
                 id="viewPaymentContent"></div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Close
                </button>

            </div>

        </div>

    </div>
</div>

<!-- NOTIFICATION -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">

    <div class="toast"
         id="paymentToast"
         role="status"
         aria-live="polite">

        <div class="toast-header">

            <i class="bi bi-check-circle-fill text-success me-2"></i>

            <strong class="me-auto">
                Payments
            </strong>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast">
            </button>

        </div>

        <div class="toast-body"
             id="paymentToastMessage">
        </div>

    </div>
</div>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    let payments = <?php echo json_encode(
        $payments,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const invoices = <?php echo json_encode(
        $invoices,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(
        csrf_token()
    ); ?>;

    const $ = id => document.getElementById(id);

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const addModal = bootstrap.Modal.getOrCreateInstance(
        $("addPaymentModal")
    );

    const viewModal = bootstrap.Modal.getOrCreateInstance(
        $("viewPaymentModal")
    );

    /* SAFE HTML */

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    /* CURRENCY */

    function formatMoney(amount) {
        return "Rs " + Number(amount).toLocaleString(
            "en-IN",
            {
                maximumFractionDigits: 2
            }
        );
    }

    /* DATE */

    function formatDate(value) {
        if (!value) return "-";

        const [year, month, day] =
            value.split("-").map(Number);

        return new Date(year, month - 1, day)
            .toLocaleDateString("en-GB", {
                day: "2-digit",
                month: "short",
                year: "numeric"
            });
    }

    function localToday() {
        const d = new Date();

        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");

        return `${year}-${month}-${day}`;
    }

    /* INVOICE BALANCE */

    function getInvoiceBalance(invoiceNumber) {

        const invoice = invoices.find(
            inv => inv.number === invoiceNumber
        );

        if (!invoice) return 0;

        const paid = payments
            .filter(p => p.invoice === invoiceNumber)
            .reduce(
                (sum, p) => sum + Number(p.amount),
                0
            );

        return Math.max(
            0,
            Math.round((Number(invoice.amount) - paid) * 100) / 100
        );
    }

    /*
     * Settlement status belongs to the invoice.
     * Screenshot groups payment rows under Pending
     * while their invoices still have unpaid balances.
     * Overdue is kept at zero for the demo because
     * the screenshot does not classify these as overdue.
     */

    function getSettlementStatus(payment) {

        const balance = getInvoiceBalance(payment.invoice);

        return balance <= 0.001
            ? "Paid"
            : "Pending";
    }

    /* STATISTICS */

    function updateStatistics() {

        const totalInvoiced = invoices.reduce(
            (sum, invoice) => sum + Number(invoice.amount),
            0
        );

        const totalReceived = payments.reduce(
            (sum, payment) => sum + Number(payment.amount),
            0
        );

        const pending = Math.max(
            0,
            totalInvoiced - totalReceived
        );

        const today = localToday();

        const receivedToday = payments
            .filter(payment => payment.date === today)
            .reduce(
                (sum, payment) => sum + Number(payment.amount),
                0
            );

        const paidCount = payments.filter(
            p => getSettlementStatus(p) === "Paid"
        ).length;

        const pendingCount = payments.filter(
            p => getSettlementStatus(p) === "Pending"
        ).length;

        $("totalInvoiced").textContent =
            formatMoney(totalInvoiced);

        $("totalReceived").textContent =
            formatMoney(totalReceived);

        $("pendingPayments").textContent =
            formatMoney(pending);

        $("paymentsToday").textContent =
            formatMoney(receivedToday);

        $("allPaymentsCount").textContent =
            payments.length;

        $("paidCount").textContent = paidCount;
        $("pendingCount").textContent = pendingCount;
        $("overdueCount").textContent = 0;
    }

    /* FILTER PAYMENTS */

    function getFilteredPayments() {

        const search = $("paymentSearch")
            .value.toLowerCase().trim();

        const mode = $("filterMode").value;
        const dateFrom = $("filterDateFrom").value;
        const dateTo = $("filterDateTo").value;

        return payments.filter(payment => {

            if (
                activeTab !== "all" &&
                getSettlementStatus(payment) !== activeTab
            ) {
                return false;
            }

            if (mode !== "all" && payment.mode !== mode) {
                return false;
            }

            if (dateFrom && payment.date < dateFrom) {
                return false;
            }

            if (dateTo && payment.date > dateTo) {
                return false;
            }

            const text = [
                payment.customer,
                payment.invoice,
                payment.narration,
                payment.reference,
                payment.mode,
                payment.amount
            ].join(" ").toLowerCase();

            return text.includes(search);
        });
    }

    /* RENDER PAYMENTS TABLE */

    function renderPayments() {

        updateStatistics();

        const filtered = getFilteredPayments();

        const totalPages = Math.max(
            1,
            Math.ceil(filtered.length / perPage)
        );

        currentPage = Math.max(
            1,
            Math.min(currentPage, totalPages)
        );

        const start = (currentPage - 1) * perPage;

        const pageData = filtered.slice(
            start,
            start + perPage
        );

        const tbody = $("paymentTableBody");

        tbody.innerHTML = "";

        if (!pageData.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="9"
                        class="text-center py-5 text-muted">
                        No payments found.
                    </td>
                </tr>
            `;

        } else {

            pageData.forEach((payment, index) => {

                const row = document.createElement("tr");

                row.innerHTML = `
                    <td>${start + index + 1}</td>

                    <td>
                        ${escapeHTML(formatDate(payment.date))}
                    </td>

                    <td>
                        <span class="pay-type">
                            <i class="bi bi-arrow-down-short"></i>
                            ${escapeHTML(payment.type)}
                        </span>
                    </td>

                    <td>
                        ${escapeHTML(payment.invoice)}
                    </td>

                    <td>
                        <span class="pay-customer-name">
                            ${escapeHTML(payment.customer)}
                        </span>
                    </td>

                    <td>
                        ${escapeHTML(payment.narration)}
                    </td>

                    <td>
                        <span class="pay-mode-badge">
                            ${escapeHTML(payment.mode)}
                        </span>
                    </td>

                    <td class="pay-amount">
                        ${escapeHTML(formatMoney(payment.amount))}
                    </td>

                    <td>
                        <div class="pay-actions">

                            <button type="button"
                                    class="pay-action"
                                    data-action="view"
                                    data-id="${payment.id}"
                                    title="View Payment">
                                <i class="bi bi-eye"></i>
                            </button>

                            <div class="dropdown">

                                <button type="button"
                                        class="pay-action"
                                        data-bs-toggle="dropdown"
                                        aria-label="More actions">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button type="button"
                                                class="dropdown-item"
                                                data-action="view"
                                                data-id="${payment.id}">
                                            <i class="bi bi-eye me-2"></i>
                                            View Details
                                        </button>
                                    </li>

                                    <li>
                                        <button type="button"
                                                class="dropdown-item"
                                                data-action="copy"
                                                data-id="${payment.id}">
                                            <i class="bi bi-copy me-2"></i>
                                            Copy Reference
                                        </button>
                                    </li>
                                </ul>

                            </div>

                        </div>
                    </td>
                `;

                tbody.appendChild(row);
            });
        }

        const from = filtered.length ? start + 1 : 0;
        const to = Math.min(
            start + perPage,
            filtered.length
        );

        $("paginationInfo").textContent =
            `Showing ${from} to ${to} of ${filtered.length} entries`;

        $("currentPage").textContent = currentPage;

        $("firstPage").disabled = currentPage === 1;
        $("prevPage").disabled = currentPage === 1;
        $("nextPage").disabled = currentPage === totalPages;
        $("lastPage").disabled = currentPage === totalPages;
    }

    /* VIEW PAYMENT */

    function viewPayment(id) {

        const payment = payments.find(
            item => Number(item.id) === id
        );

        if (!payment) return;

        const settlement = getSettlementStatus(payment);
        const tone = /paid|settled/i.test(settlement) && !/partial/i.test(settlement)
            ? "green" : /partial/i.test(settlement) ? "orange" : /overdue/i.test(settlement) ? "red" : "blue";

        App.details({
            title: "Payment Details",
            icon: "credit-card",
            name: formatMoney(payment.amount),
            subtitle: payment.customer || "",
            badge: { text: settlement, tone: tone },
            rows: [
                ["Payment ID", payment.narration],
                ["Date", formatDate(payment.date)],
                ["Type", payment.type],
                ["Invoice", payment.invoice],
                ["Customer", payment.customer],
                ["Payment Mode", payment.mode],
                ["Amount", formatMoney(payment.amount)],
                ["Reference", payment.reference || "-"],
                ["Invoice Balance", formatMoney(getInvoiceBalance(payment.invoice))]
            ],
            actions: [
                { label: "Copy Reference", icon: "clipboard", tone: "primary", onClick: () => rowAction("copy", id) }
            ]
        });
    }

    // Run a table row action (same confirm + logic as the row buttons).
    function rowAction(action, id) {
        const btn = document.querySelector(`[data-action="${action}"][data-id="${id}"]`);
        if (btn) btn.click();
    }

    /* NOTIFICATION */

    function showToast(message) {

        $("paymentToastMessage").textContent = message;

        bootstrap.Toast.getOrCreateInstance(
            $("paymentToast")
        ).show();
    }

    /* TABLE ACTIONS */

    $("paymentTableBody").addEventListener(
        "click",
        async function(event) {

            const button = event.target.closest("[data-action]");

            if (!button) return;

            const id = Number(button.dataset.id);

            const payment = payments.find(
                p => Number(p.id) === id
            );

            if (!payment) return;

            if (button.dataset.action === "view") {
                viewPayment(id);
            }

            if (button.dataset.action === "copy") {

                const reference =
                    payment.reference || payment.narration;

                try {

                    await navigator.clipboard.writeText(reference);

                    showToast("Reference copied successfully.");

                } catch (error) {

                    showToast(
                        "Unable to copy. Reference: " + reference
                    );
                }
            }
        }
    );

    /* TABS */

    document.querySelectorAll(".payment-section .pay-tab")
        .forEach(tab => {

            tab.addEventListener("click", function() {

                document.querySelectorAll(
                    ".payment-section .pay-tab"
                ).forEach(item => {
                    item.classList.remove("active");
                });

                this.classList.add("active");

                activeTab = this.dataset.tab;
                currentPage = 1;

                renderPayments();
            });
        });

    /* SEARCH */

    $("paymentSearch").addEventListener("input", function() {
        currentPage = 1;
        renderPayments();
    });

    /* FILTERS */

    [
        "filterMode",
        "filterDateFrom",
        "filterDateTo"
    ].forEach(id => {

        $(id).addEventListener("change", function() {
            currentPage = 1;
            renderPayments();
        });
    });

    /* RESET FILTERS */

    function resetFilters() {

        $("paymentSearch").value = "";
        $("filterMode").value = "all";
        $("filterDateFrom").value = "";
        $("filterDateTo").value = "";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(".payment-section .pay-tab")
            .forEach(tab => {
                tab.classList.toggle(
                    "active",
                    tab.dataset.tab === "all"
                );
            });

        renderPayments();
    }

    $("resetFilters").addEventListener("click", resetFilters);
    $("resetView").addEventListener("click", resetFilters);

    /* PAGINATION */

    $("pageSize").addEventListener("change", function() {
        perPage = Number(this.value);
        currentPage = 1;
        renderPayments();
    });

    $("firstPage").addEventListener("click", () => {
        currentPage = 1;
        renderPayments();
    });

    $("prevPage").addEventListener("click", () => {
        currentPage--;
        renderPayments();
    });

    $("nextPage").addEventListener("click", () => {
        currentPage++;
        renderPayments();
    });

    $("lastPage").addEventListener("click", () => {

        currentPage = Math.max(
            1,
            Math.ceil(getFilteredPayments().length / perPage)
        );

        renderPayments();
    });

    /* ADD PAYMENT MODAL */

    function updateBalanceInfo() {

        const invoiceNumber = $("newPaymentInvoice").value;

        if (!invoiceNumber) {

            $("invoiceBalanceInfo").textContent =
                "Select an invoice to view its pending balance.";

            return;
        }

        const balance = getInvoiceBalance(invoiceNumber);

        $("invoiceBalanceInfo").textContent =
            "Pending Invoice Balance: " + formatMoney(balance);

        $("newPaymentAmount").max = balance;
    }

    $("newPaymentInvoice").addEventListener(
        "change",
        updateBalanceInfo
    );

    $("addPaymentModal").addEventListener(
        "show.bs.modal",
        function() {

            $("addPaymentForm").reset();

            $("newPaymentDate").value = localToday();

            $("addPaymentError").classList.add("d-none");

            $("addPaymentError").textContent = "";

            updateBalanceInfo();
        }
    );

    /* SAVE PAYMENT */

    $("addPaymentForm").addEventListener(
        "submit",
        async function(event) {

            event.preventDefault();

            if (!this.reportValidity()) return;

            const invoiceNumber = $("newPaymentInvoice").value;
            const amount = Number($("newPaymentAmount").value);
            const balance = getInvoiceBalance(invoiceNumber);

            const errorBox = $("addPaymentError");
            const saveBtn = $("savePaymentBtn");

            errorBox.classList.add("d-none");
            errorBox.textContent = "";

            if (
                !Number.isFinite(amount) ||
                amount <= 0 ||
                amount > balance
            ) {
                errorBox.textContent =
                    "Enter a valid amount within the invoice balance.";

                errorBox.classList.remove("d-none");
                return;
            }

            const formData = new FormData();

            formData.append("action", "save_payment");
            formData.append("csrf_token", csrfToken);
            formData.append("invoice", invoiceNumber);
            formData.append("date", $("newPaymentDate").value);
            formData.append("mode", $("newPaymentMode").value);
            formData.append("amount", $("newPaymentAmount").value);
            formData.append(
                "reference",
                $("newPaymentReference").value.trim()
            );

            saveBtn.disabled = true;
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
                        result.message || "Unable to save payment."
                    );
                }

                payments.push(result.payment);

                resetFilters();

                addModal.hide();

                showToast("Payment saved successfully.");

            } catch (error) {

                errorBox.textContent = error.message;
                errorBox.classList.remove("d-none");

            } finally {

                saveBtn.disabled = false;

                saveBtn.innerHTML =
                    '<i class="bi bi-check-lg me-1"></i> Save Payment';
            }
        }
    );

    /* INITIAL LOAD */

    renderPayments();

})();
</script>







<?php include  'footer.php'; ?>