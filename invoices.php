<?php
/*
|--------------------------------------------------------------------------
| INVOICES PAGE - data from MySQL `invoices`
|--------------------------------------------------------------------------
| Invoices are created automatically when an Order is saved.
| Payment status is updated automatically from the Payments page.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$statuses = [
    'Draft',
    'Submitted',
    'Paid',
    'Partially Paid',
    'Overdue',
    'Cancelled'
];

function invoices_all(): array
{
    // Mark unpaid invoices past due date as Overdue.
    db_exec("UPDATE invoices SET status = 'Overdue'
             WHERE status = 'Submitted' AND due_date IS NOT NULL AND due_date < CURDATE()");

    return cast_rows(
        db_all('SELECT i.id, i.invoice_number, i.customer, i.date, i.due_date, i.subtotal, i.discount,
                       i.tax, i.amount, i.status, o.order_number,
                       COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0) AS paid
                FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                ORDER BY i.id DESC'),
        [],
        ['subtotal', 'discount', 'tax', 'amount', 'paid']
    );
}

/* AJAX UPDATE / DELETE / ITEMS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $invoice = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);

    if (!$invoice) {
        json_response(['success' => false, 'message' => 'Invoice not found.'], 404);
    }

    if ($action === 'items') {
        $items = $invoice['order_id']
            ? db_all('SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM order_items WHERE order_id = ?', [$invoice['order_id']])
            : [];
        json_response(['success' => true, 'items' => cast_rows($items, [], ['quantity', 'rate', 'tax', 'discount', 'amount'])]);
    }

    if ($action === 'delete') {
        if (db_value('SELECT COUNT(*) FROM payments WHERE invoice = ?', [$invoice['invoice_number']])) {
            json_response(['success' => false, 'message' => 'This invoice has payments. Cancel it instead of deleting.'], 409);
        }
        db_exec('UPDATE orders SET invoice_id = NULL WHERE invoice_id = ?', [$id]);
        db_exec('DELETE FROM invoices WHERE id = ?', [$id]);
        log_activity('Invoices', 'Deleted invoice ' . $invoice['invoice_number']);
    } elseif ($action === 'update') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $statuses, true)) {
            json_response(['success' => false, 'message' => 'Invalid invoice status.'], 422);
        }

        $amount = $_POST['amount'] ?? null;
        $dueDate = $_POST['due_date'] ?? '';
        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $validDate = $dateObject && $dateObject->format('Y-m-d') === $dueDate;

        if (!is_numeric($amount) || (float)$amount < 0 || !is_finite((float)$amount) || !$validDate) {
            json_response(['success' => false, 'message' => 'Enter a valid amount and due date.'], 422);
        }

        db_update('invoices', [
            'amount' => round((float)$amount, 2),
            'due_date' => $dueDate,
            'status' => $status,
        ], $id);
        log_activity('Invoices', 'Updated invoice ' . $invoice['invoice_number'] . ' (' . $status . ')');
    } else {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    json_response([
        'success' => true,
        'invoices' => invoices_all()
    ]);
}

$invoices = invoices_all();
$pageTitle = 'Invoices';
include 'header.php';
?>




<!-- INVOICE MAIN SECTION -->
<section class="invioce-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row mb-3">
        <div class="col-12">
            <h1 class="inv-title">Invoices</h1>

            <p class="inv-subtitle">
                Manage sales invoices for the product catalog
            </p>
        </div>
    </div>

    <!-- FOUR STATISTICS CARDS -->
    <div class="row g-3 mb-4">

        <!-- TOTAL INVOICES -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="inv-stat-card">

                <div class="inv-stat-icon inv-blue">
                    <i class="bi bi-file-earmark-text"></i>
                </div>

                <div>
                    <div class="inv-stat-label">
                        Total Invoices
                    </div>

                    <div class="inv-stat-value"
                         id="totalInvoices">
                        2
                    </div>

                    <div class="inv-stat-note">
                        This Month
                    </div>
                </div>

            </div>
        </div>

        <!-- PAID -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="inv-stat-card">

                <div class="inv-stat-icon inv-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                    <div class="inv-stat-label">Paid</div>

                    <div class="inv-stat-value"
                         id="paidInvoices">
                        0
                    </div>

                    <div class="inv-stat-note">
                        This Month
                    </div>
                </div>

            </div>
        </div>

        <!-- PARTIALLY PAID -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="inv-stat-card">

                <div class="inv-stat-icon inv-orange">
                    <i class="bi bi-clock"></i>
                </div>

                <div>
                    <div class="inv-stat-label">
                        Partially Paid
                    </div>

                    <div class="inv-stat-value"
                         id="partiallyPaidInvoices">
                        2
                    </div>

                    <div class="inv-stat-note">
                        This Month
                    </div>
                </div>

            </div>
        </div>

        <!-- OVERDUE -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="inv-stat-card">

                <div class="inv-stat-icon inv-red">
                    <i class="bi bi-calendar-x"></i>
                </div>

                <div>
                    <div class="inv-stat-label">
                        Overdue
                    </div>

                    <div class="inv-stat-value"
                         id="overdueInvoices">
                        0
                    </div>

                    <div class="inv-stat-note">
                        This Month
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- INVOICE LIST PANEL -->
    <div class="inv-panel">

        <!-- STATUS TABS -->
        <div class="inv-tabs">

            <button type="button"
                    class="inv-tab active"
                    data-tab="all">
                All Invoices
                (<span id="allCount">2</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Draft">
                Draft
                (<span id="draftCount">0</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Submitted">
                Submitted
                (<span id="submittedCount">0</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Paid">
                Paid
                (<span id="paidCount">0</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Partially Paid">
                Partially Paid
                (<span id="partiallyCount">2</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Overdue">
                Overdue
                (<span id="overdueCount">0</span>)
            </button>

            <button type="button"
                    class="inv-tab"
                    data-tab="Cancelled">
                Cancelled
                (<span id="cancelledCount">0</span>)
            </button>

        </div>

        <!-- SEARCH AND FILTER -->
        <div class="inv-toolbar">

            <!-- SEARCH -->
            <div class="inv-search">
                <i class="bi bi-search"></i>

                <input type="search"
                       id="invoiceSearch"
                       placeholder="Search by invoice number or customer...">
            </div>

            <!-- FILTER -->
            <div class="dropdown">

                <button type="button"
                        class="inv-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">

                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter
                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:250px">

                    <label class="form-label fw-bold">
                        Invoice Status
                    </label>

                    <select id="filterStatus"
                            class="form-select mb-3">

                        <option value="all">All Status</option>

                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php
                                echo htmlspecialchars($status);
                            ?>">
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>

                    <label class="form-label fw-bold">
                        Date From
                    </label>

                    <input type="date"
                           id="filterDateFrom"
                           class="form-control mb-3">

                    <label class="form-label fw-bold">
                        Date To
                    </label>

                    <input type="date"
                           id="filterDateTo"
                           class="form-control mb-3">

                    <button type="button"
                            id="resetFilters"
                            class="btn btn-outline-secondary w-100">
                        Reset Filters
                    </button>

                </div>
            </div>

            <!-- RESET VIEW -->
            <button type="button"
                    class="inv-tool-btn"
                    id="resetView"
                    title="Reset Filters">

                <i class="bi bi-sliders2"></i>
            </button>

        </div>

        <!-- INVOICE TABLE -->
        <div class="table-responsive">

            <table class="table inv-table">

                <thead>
                    <tr>
                        <th class="text-center">S NO</th>
                        <th>INVOICE NUMBER</th>
                        <th>CUSTOMER NAME</th>
                        <th>DATE</th>
                        <th>DUE DATE</th>
                        <th>AMOUNT</th>
                        <th>STATUS</th>
                        <th class="text-center">ACTIONS</th>
                    </tr>
                </thead>

                <tbody id="invoiceTableBody">
                    <!-- JavaScript rows -->
                </tbody>

            </table>
        </div>

        <!-- PAGINATION -->
        <div class="inv-footer">

            <span id="paginationInfo">
                Showing 1 to 2 of 2 entries
            </span>

            <div class="inv-pagination">

                <button type="button"
                        class="inv-page-btn"
                        id="firstPage">&lt;&lt;</button>

                <button type="button"
                        class="inv-page-btn"
                        id="prevPage">&lt;</button>

                <button type="button"
                        class="inv-page-btn active"
                        id="currentPage">1</button>

                <button type="button"
                        class="inv-page-btn"
                        id="nextPage">&gt;</button>

                <button type="button"
                        class="inv-page-btn"
                        id="lastPage">&gt;&gt;</button>

                <select id="pageSize"
                        class="inv-page-size">

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

<!-- VIEW INVOICE MODAL -->
<div class="modal fade"
     id="viewInvoiceModal"
     tabindex="-1"
     aria-labelledby="viewInvoiceTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title"
                    id="viewInvoiceTitle">
                    Invoice Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <div class="modal-body"
                 id="viewInvoiceContent">
            </div>

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

<!-- EDIT INVOICE MODAL -->
<div class="modal fade"
     id="editInvoiceModal"
     tabindex="-1"
     aria-labelledby="editInvoiceTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title"
                    id="editInvoiceTitle">
                    Edit Invoice
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="editInvoiceForm">

                <div class="modal-body">

                    <input type="hidden" id="editInvoiceId">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Invoice Number
                        </label>
                        <input type="text"
                               class="form-control"
                               id="editInvoiceNumber"
                               readonly>
                    </div>

                    <div class="mb-3">
                        <label for="editInvoiceAmount"
                               class="form-label fw-semibold">
                            Amount
                        </label>
                        <input type="number"
                               class="form-control"
                               id="editInvoiceAmount"
                               min="0"
                               step="0.01"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="editInvoiceDueDate"
                               class="form-label fw-semibold">
                            Due Date
                        </label>
                        <input type="date"
                               class="form-control"
                               id="editInvoiceDueDate"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="editInvoiceStatus"
                               class="form-label fw-semibold">
                            Status
                        </label>

                        <select class="form-select"
                                id="editInvoiceStatus">

                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php
                                    echo htmlspecialchars($status);
                                ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div id="editInvoiceError"
                         class="alert alert-danger d-none"
                         role="alert"></div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary"
                            id="saveEditBtn">
                        Save Changes
                    </button>

                </div>

            </form>
        </div>
    </div>
</div>

<!-- NOTIFICATION TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">

    <div class="toast"
         id="invoiceToast"
         role="status"
         aria-live="polite">

        <div class="toast-header">
            <i class="bi bi-info-circle me-2"></i>
            <strong class="me-auto">Invoices</strong>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"></button>
        </div>

        <div class="toast-body"
             id="invoiceToastMessage"></div>

    </div>
</div>

<!-- BOOTSTRAP JAVASCRIPT -->

<script>
(function () {
    "use strict";

    let invoices = <?php echo json_encode(
        $invoices,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(csrf_token()); ?>;

    const allowedStatuses = <?php echo json_encode(
        $statuses
    ); ?>;

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const $ = id => document.getElementById(id);

    const viewModal =
        bootstrap.Modal.getOrCreateInstance(
            $("viewInvoiceModal")
        );

    const editModal =
        bootstrap.Modal.getOrCreateInstance(
            $("editInvoiceModal")
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

    function formatAmount(amount) {
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

        const date = new Date(year, month - 1, day);

        return date.toLocaleDateString("en-GB", {
            day: "2-digit",
            month: "short",
            year: "numeric"
        });
    }

    /* STATUS COUNTS */

    function countStatus(status) {
        return invoices.filter(
            invoice => invoice.status === status
        ).length;
    }

    function updateStatistics() {

        $("totalInvoices").textContent =
            invoices.length;

        $("paidInvoices").textContent =
            countStatus("Paid");

        $("partiallyPaidInvoices").textContent =
            countStatus("Partially Paid");

        $("overdueInvoices").textContent =
            countStatus("Overdue");

        $("allCount").textContent =
            invoices.length;

        $("draftCount").textContent =
            countStatus("Draft");

        $("submittedCount").textContent =
            countStatus("Submitted");

        $("paidCount").textContent =
            countStatus("Paid");

        $("partiallyCount").textContent =
            countStatus("Partially Paid");

        $("overdueCount").textContent =
            countStatus("Overdue");

        $("cancelledCount").textContent =
            countStatus("Cancelled");
    }

    /* FILTER INVOICES */

    function getFilteredInvoices() {

        const search = $("invoiceSearch")
            .value.trim().toLowerCase();

        const status = $("filterStatus").value;
        const dateFrom = $("filterDateFrom").value;
        const dateTo = $("filterDateTo").value;

        return invoices.filter(invoice => {

            if (
                activeTab !== "all" &&
                invoice.status !== activeTab
            ) {
                return false;
            }

            if (
                status !== "all" &&
                invoice.status !== status
            ) {
                return false;
            }

            if (dateFrom && invoice.date < dateFrom) {
                return false;
            }

            if (dateTo && invoice.date > dateTo) {
                return false;
            }

            const searchable = [
                invoice.invoice_number,
                invoice.customer,
                invoice.amount,
                invoice.status
            ].join(" ").toLowerCase();

            return searchable.includes(search);
        });
    }

    /* RENDER TABLE */

    function renderInvoices() {

        updateStatistics();

        const filtered = getFilteredInvoices();

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

        const tbody = $("invoiceTableBody");
        tbody.innerHTML = "";

        if (!pageData.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8"
                        class="text-center py-5 text-muted">
                        No invoices found.
                    </td>
                </tr>
            `;
        } else {

            pageData.forEach((invoice, index) => {

                const row = document.createElement("tr");

                const statusOptions = allowedStatuses.map(
                    status => `
                        <li>
                            <button type="button"
                                    class="dropdown-item"
                                    data-action="status"
                                    data-id="${invoice.id}"
                                    data-status="${escapeHTML(status)}">
                                Mark as ${escapeHTML(status)}
                            </button>
                        </li>
                    `
                ).join("");

                row.innerHTML = `
                    <td class="text-center">
                        ${start + index + 1}
                    </td>

                    <td>
                        <span class="inv-number">
                            ${escapeHTML(invoice.invoice_number)}
                        </span>
                    </td>

                    <td>
                        <span class="inv-customer">
                            ${escapeHTML(invoice.customer)}
                        </span>
                    </td>

                    <td>
                        ${escapeHTML(formatDate(invoice.date))}
                    </td>

                    <td>
                        ${escapeHTML(formatDate(invoice.due_date))}
                    </td>

                    <td class="inv-amount">
                        ${escapeHTML(formatAmount(invoice.amount))}
                    </td>

                    <td>
                        <span class="inv-status">
                            ${escapeHTML(invoice.status)}
                        </span>
                    </td>

                    <td>
                        <div class="inv-actions">

                            <button type="button"
                                    class="inv-action"
                                    data-action="view"
                                    data-id="${invoice.id}"
                                    title="View Invoice">
                                <i class="bi bi-eye"></i>
                            </button>

                            <button type="button"
                                    class="inv-action"
                                    data-action="edit"
                                    data-id="${invoice.id}"
                                    title="Edit Invoice">
                                <i class="bi bi-pencil-fill"></i>
                            </button>

                            <button type="button"
                                    class="inv-action delete"
                                    data-action="delete"
                                    data-id="${invoice.id}"
                                    title="Delete Invoice">
                                <i class="bi bi-trash3-fill"></i>
                            </button>

                            <div class="dropdown">

                                <button type="button"
                                        class="inv-action more"
                                        data-bs-toggle="dropdown"
                                        aria-label="More Actions">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">
                                    ${statusOptions}
                                </ul>

                            </div>

                        </div>
                    </td>
                `;

                tbody.appendChild(row);
            });
        }

        const from = filtered.length
            ? start + 1
            : 0;

        const to = Math.min(
            start + perPage,
            filtered.length
        );

        $("paginationInfo").textContent =
            `Showing ${from} to ${to} of ${filtered.length} entries`;

        $("currentPage").textContent = currentPage;

        $("firstPage").disabled =
            currentPage === 1;

        $("prevPage").disabled =
            currentPage === 1;

        $("nextPage").disabled =
            currentPage === totalPages;

        $("lastPage").disabled =
            currentPage === totalPages;
    }

    /* SHOW TOAST */

    function showMessage(message) {

        $("invoiceToastMessage").textContent = message;

        bootstrap.Toast.getOrCreateInstance(
            $("invoiceToast")
        ).show();
    }

    /* VIEW INVOICE */

    function viewInvoice(id) {

        const invoice = invoices.find(
            item => Number(item.id) === id
        );

        if (!invoice) return;

        const details = [
            ["Invoice Number", invoice.invoice_number],
            ["Customer", invoice.customer],
            ["Date", formatDate(invoice.date)],
            ["Due Date", formatDate(invoice.due_date)],
            ["Order", invoice.order_number || "-"],
            ["Taxable (after discount)", formatAmount((invoice.subtotal || 0) - (invoice.discount || 0))],
            ["GST", formatAmount(invoice.tax || 0)],
            ["Amount", formatAmount(invoice.amount)],
            ["Paid", formatAmount(invoice.paid || 0)],
            ["Balance", formatAmount(Math.max(0, invoice.amount - (invoice.paid || 0)))],
            ["Status", invoice.status]
        ];

        $("viewInvoiceContent").innerHTML =
            details.map(([label, value]) => `
                <div class="row py-3 border-bottom">
                    <div class="col-5 fw-bold">
                        ${escapeHTML(label)}
                    </div>
                    <div class="col-7">
                        ${escapeHTML(value)}
                    </div>
                </div>
            `).join("");

        // Product lines from the linked order.
        App.post(window.location.pathname, { action: "items", id: invoice.id })
            .then(result => {
                if (!result.items.length) return;
                const holder = document.createElement("div");
                holder.className = "table-responsive mt-3";
                holder.innerHTML = `
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th>Product</th><th>HSN</th><th>Qty</th><th>Rate</th><th>Tax%</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>${result.items.map(it => `<tr>
                            <td>${escapeHTML(it.name)}</td><td>${escapeHTML(it.hsn || "-")}</td>
                            <td>${it.quantity}</td><td>${formatAmount(it.rate)}</td><td>${it.tax}</td>
                            <td class="text-end">${formatAmount(it.amount)}</td></tr>`).join("")}</tbody>
                    </table>`;
                $("viewInvoiceContent").appendChild(holder);
            })
            .catch(() => {});

        viewModal.show();
    }

    /* EDIT INVOICE */

    function editInvoice(id) {

        const invoice = invoices.find(
            item => Number(item.id) === id
        );

        if (!invoice) return;

        $("editInvoiceId").value = invoice.id;

        $("editInvoiceNumber").value =
            invoice.invoice_number;

        $("editInvoiceAmount").value =
            invoice.amount;

        $("editInvoiceDueDate").value =
            invoice.due_date;

        $("editInvoiceStatus").value =
            invoice.status;

        $("editInvoiceError").classList.add("d-none");

        editModal.show();
    }

    /* SERVER REQUEST */

    async function updateInvoice(action, data) {

        const formData = new FormData();

        formData.append("action", action);
        formData.append("csrf_token", csrfToken);

        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, String(value));
        });

        const response = await fetch(
            window.location.pathname + window.location.search,
            {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            }
        );

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || "Unable to update invoice."
            );
        }

        invoices = result.invoices;
        renderInvoices();

        return result;
    }

    /* EDIT FORM SAVE */

    $("editInvoiceForm").addEventListener(
        "submit",
        async function(event) {

            event.preventDefault();

            if (!this.reportValidity()) return;

            const saveButton = $("saveEditBtn");

            saveButton.disabled = true;

            try {

                await updateInvoice("update", {
                    id: $("editInvoiceId").value,
                    amount: $("editInvoiceAmount").value,
                    due_date: $("editInvoiceDueDate").value,
                    status: $("editInvoiceStatus").value
                });

                editModal.hide();

                showMessage("Invoice updated successfully.");

            } catch (error) {

                $("editInvoiceError").textContent =
                    error.message;

                $("editInvoiceError")
                    .classList.remove("d-none");

            } finally {

                saveButton.disabled = false;
            }
        }
    );

    /* TABLE ACTIONS */

    $("invoiceTableBody").addEventListener(
        "click",
        async function(event) {

            const button =
                event.target.closest("[data-action]");

            if (!button) return;

            const id = Number(button.dataset.id);
            const action = button.dataset.action;

            if (action === "view") {
                viewInvoice(id);
                return;
            }

            if (action === "edit") {
                editInvoice(id);
                return;
            }

            if (action === "delete") {

                if (!confirm(
                    "Are you sure you want to delete this invoice?"
                )) {
                    return;
                }
            }

            if (action === "delete" || action === "status") {

                const invoice = invoices.find(
                    item => Number(item.id) === id
                );

                if (!invoice) return;

                button.disabled = true;

                try {

                    if (action === "delete") {

                        await updateInvoice("delete", {
                            id: id
                        });

                        showMessage(
                            "Invoice deleted successfully."
                        );

                    } else {

                        await updateInvoice("update", {
                            id: id,
                            amount: invoice.amount,
                            due_date: invoice.due_date,
                            status: button.dataset.status
                        });

                        showMessage(
                            "Invoice status updated successfully."
                        );
                    }

                } catch (error) {

                    showMessage(error.message);
                    button.disabled = false;
                }
            }
        }
    );

    /* STATUS TABS */

    document.querySelectorAll(
        ".invioce-section .inv-tab"
    ).forEach(tab => {

        tab.addEventListener("click", function() {

            document.querySelectorAll(
                ".invioce-section .inv-tab"
            ).forEach(item => {
                item.classList.remove("active");
            });

            this.classList.add("active");

            activeTab = this.dataset.tab;
            currentPage = 1;

            renderInvoices();
        });
    });

    /* SEARCH */

    $("invoiceSearch").addEventListener(
        "input",
        function() {
            currentPage = 1;
            renderInvoices();
        }
    );

    /* FILTERS */

    [
        "filterStatus",
        "filterDateFrom",
        "filterDateTo"
    ].forEach(id => {

        $(id).addEventListener("change", function() {
            currentPage = 1;
            renderInvoices();
        });
    });

    /* RESET FILTERS */

    function resetFilters() {

        $("invoiceSearch").value = "";
        $("filterStatus").value = "all";
        $("filterDateFrom").value = "";
        $("filterDateTo").value = "";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(
            ".invioce-section .inv-tab"
        ).forEach(tab => {

            tab.classList.toggle(
                "active",
                tab.dataset.tab === "all"
            );
        });

        renderInvoices();
    }

    $("resetFilters").addEventListener(
        "click",
        resetFilters
    );

    $("resetView").addEventListener(
        "click",
        resetFilters
    );

    /* PAGE SIZE */

    $("pageSize").addEventListener("change", function() {

        perPage = Number(this.value);
        currentPage = 1;

        renderInvoices();
    });

    /* PAGINATION */

    $("firstPage").addEventListener("click", function() {
        currentPage = 1;
        renderInvoices();
    });

    $("prevPage").addEventListener("click", function() {
        currentPage--;
        renderInvoices();
    });

    $("nextPage").addEventListener("click", function() {
        currentPage++;
        renderInvoices();
    });

    $("lastPage").addEventListener("click", function() {

        currentPage = Math.max(
            1,
            Math.ceil(getFilteredInvoices().length / perPage)
        );

        renderInvoices();
    });

    /* INITIAL RENDER */

    renderInvoices();

})();
</script>




<?php include 'footer.php'; ?>