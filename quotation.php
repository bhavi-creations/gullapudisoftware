<?php
/*
|--------------------------------------------------------------------------
| QUOTATIONS PAGE - data from MySQL `quotations` + `quotation_items`
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$allowedStatuses = [
    'Draft', 'Sent', 'Accepted', 'Expired',
    'Converted', 'Cancelled'
];

function quotations_all(): array
{
    // Auto-expire open quotations past their validity date.
    db_exec("UPDATE quotations SET status = 'Expired'
             WHERE status IN ('Draft','Sent') AND valid_upto IS NOT NULL AND valid_upto < CURDATE()");

    return cast_rows(
        db_all('SELECT id, quotation_id, date, valid_upto, customer, salesman, reference, region,
                       subtotal, discount, tax, amount, status
                FROM quotations ORDER BY id DESC'),
        [],
        ['subtotal', 'discount', 'tax', 'amount']
    );
}

/* AJAX DELETE / STATUS UPDATE / ITEMS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $quotation = db_one('SELECT id, quotation_id FROM quotations WHERE id = ?', [$id]);

    if (!$quotation) {
        json_response(['success' => false, 'message' => 'Quotation not found.'], 404);
    }

    if ($action === 'items') {
        json_response(['success' => true, 'items' => cast_rows(
            db_all('SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM quotation_items WHERE quotation_id = ?', [$id]),
            [], ['quantity', 'rate', 'tax', 'discount', 'amount']
        )]);
    }

    if ($action === 'delete') {
        db_exec('DELETE FROM quotations WHERE id = ?', [$id]);
        log_activity('Quotations', 'Deleted quotation ' . $quotation['quotation_id']);
    } elseif ($action === 'status') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $allowedStatuses, true)) {
            json_response(['success' => false, 'message' => 'Invalid quotation status.'], 422);
        }
        db_update('quotations', ['status' => $status], $id);
        log_activity('Quotations', 'Quotation ' . $quotation['quotation_id'] . ' marked as ' . $status);
    } else {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    json_response([
        'success' => true,
        'quotations' => quotations_all()
    ]);
}

$quotations = quotations_all();
$pageTitle = 'Quotations';
include 'header.php';
?>




<!-- =================================
     QUOTATIONS MAIN SECTION
================================= -->

<section class="quotation-section">

<div class="container-fluid p-0">

    <!-- HEADER -->
    <div class="row align-items-center g-3 mb-4">

        <div class="col-md-8">
            <h1 class="qt-title">Quotations</h1>

            <p class="qt-subtitle">
                Manage quotation groups for the product catalog
            </p>
        </div>

        <div class="col-md-4 text-md-end">

            <!-- ADD QUOTATION REDIRECT -->
            <a href="add-quotation.php"
               class="qt-add-btn">

                <i class="bi bi-plus-lg"></i>
                Add Quotation

            </a>

        </div>

    </div>

    <!-- STATISTICS CARDS -->
    <div class="row g-3 mb-3">

        <!-- TOTAL QUOTATIONS -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="qt-stat-card">

                <div class="qt-stat-icon qt-blue">
                    <i class="bi bi-bag-fill"></i>
                </div>

                <div>
                    <div class="qt-stat-label">
                        Total Quotations
                    </div>

                    <div class="qt-stat-value"
                         id="totalQuotations">
                        3
                    </div>

                    <div class="qt-stat-note">
                        This Month
                    </div>
                </div>

            </div>

        </div>

        <!-- CONVERTED -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="qt-stat-card">

                <div class="qt-stat-icon qt-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                    <div class="qt-stat-label">
                        Converted
                    </div>

                    <div class="qt-stat-value"
                         id="convertedCount">
                        2
                    </div>

                    <div class="qt-stat-note">
                        This Month
                    </div>
                </div>

            </div>

        </div>

        <!-- PENDING -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="qt-stat-card">

                <div class="qt-stat-icon qt-orange">
                    <i class="bi bi-clock"></i>
                </div>

                <div>
                    <div class="qt-stat-label">
                        Pending
                    </div>

                    <div class="qt-stat-value"
                         id="pendingCount">
                        1
                    </div>

                    <div class="qt-stat-note">
                        This Month
                    </div>
                </div>

            </div>

        </div>

        <!-- EXPIRED -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="qt-stat-card">

                <div class="qt-stat-icon qt-red">
                    <i class="bi bi-calendar-x"></i>
                </div>

                <div>
                    <div class="qt-stat-label">
                        Expired
                    </div>

                    <div class="qt-stat-value"
                         id="expiredCount">
                        0
                    </div>

                    <div class="qt-stat-note">
                        This Month
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- QUOTATIONS LIST PANEL -->
    <div class="qt-panel">

        <!-- TABS -->
        <div class="qt-tabs">

            <button type="button"
                    class="qt-tab active"
                    data-tab="all">
                All Quotations
                (<span id="allCount">3</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Draft">
                Draft
                (<span id="draftCount">1</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Sent">
                Sent
                (<span id="sentCount">0</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Accepted">
                Accepted
                (<span id="acceptedCount">0</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Expired">
                Expired
                (<span id="expiredTabCount">0</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Converted">
                Converted
                (<span id="convertedTabCount">2</span>)
            </button>

            <button type="button"
                    class="qt-tab"
                    data-tab="Cancelled">
                Cancelled
                (<span id="cancelledCount">0</span>)
            </button>

        </div>

        <!-- SEARCH AND FILTER -->
        <div class="qt-toolbar">

            <div class="qt-search">

                <i class="bi bi-search"></i>

                <input type="search"
                       id="quotationSearch"
                       placeholder="Search by quotation name or code...">

            </div>

            <!-- FILTER -->
            <div class="dropdown">

                <button type="button"
                        class="qt-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">

                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter

                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:250px">

                    <label class="form-label fw-bold">
                        Status
                    </label>

                    <select class="form-select mb-3"
                            id="filterStatus">

                        <option value="all">All Status</option>
                        <option value="Draft">Draft</option>
                        <option value="Sent">Sent</option>
                        <option value="Accepted">Accepted</option>
                        <option value="Expired">Expired</option>
                        <option value="Converted">Converted</option>
                        <option value="Cancelled">Cancelled</option>

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
                            id="resetFilters"
                            class="btn btn-outline-secondary w-100">
                        Reset Filters
                    </button>

                </div>

            </div>

            <!-- RESET VIEW -->
            <button type="button"
                    class="qt-tool-btn"
                    id="resetView"
                    title="Reset Filters">

                <i class="bi bi-sliders2"></i>

            </button>

        </div>

        <!-- QUOTATION TABLE -->
        <div class="table-responsive">

            <table class="table qt-table">

                <thead>
                    <tr>
                        <th class="text-center">S No</th>
                        <th>Quotation ID</th>
                        <th>Date</th>
                        <th>Valid Upto</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>

                <tbody id="quotationTableBody"></tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="qt-footer">

            <div id="paginationInfo">
                Showing 1 to 3 of 3 entries
            </div>

            <div class="qt-pagination">

                <button type="button"
                        class="qt-page-btn"
                        id="firstPage">
                    &lt;&lt;
                </button>

                <button type="button"
                        class="qt-page-btn"
                        id="prevPage">
                    &lt;
                </button>

                <button type="button"
                        class="qt-page-btn active"
                        id="currentPage">
                    1
                </button>

                <button type="button"
                        class="qt-page-btn"
                        id="nextPage">
                    &gt;
                </button>

                <button type="button"
                        class="qt-page-btn"
                        id="lastPage">
                    &gt;&gt;
                </button>

                <select class="qt-page-size"
                        id="pageSize">
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

<!-- VIEW QUOTATION MODAL -->
<div class="modal fade"
     id="viewQuotationModal"
     tabindex="-1"
     aria-labelledby="viewQuotationTitle"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title"
                    id="viewQuotationTitle">
                    Quotation Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body"
                 id="viewQuotationContent">
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

<!-- SUCCESS / ERROR MESSAGE -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">

    <div class="toast"
         id="quotationToast"
         role="status"
         aria-live="polite">

        <div class="toast-header">

            <i class="bi bi-info-circle me-2"></i>

            <strong class="me-auto">
                Quotations
            </strong>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast">
            </button>

        </div>

        <div class="toast-body" id="quotationToastMessage">
        </div>

    </div>

</div>

<!-- BOOTSTRAP JAVASCRIPT -->

<script>
(function () {
    "use strict";

    let quotations = <?php echo json_encode(
        $quotations,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const csrfToken = <?php echo json_encode(csrf_token()); ?>;

    const allowedStatuses = [
        "Draft", "Sent", "Accepted",
        "Expired", "Converted", "Cancelled"
    ];

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const $ = id => document.getElementById(id);

    const viewModal = bootstrap.Modal.getOrCreateInstance(
        $("viewQuotationModal")
    );

    // Escape HTML for safe output.
    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    // Currency.
    function formatAmount(value) {
        return "Rs " + Number(value).toLocaleString("en-IN", {
            maximumFractionDigits: 2
        });
    }

    // Date.
    function formatDate(value) {
        if (!value) return "-";

        const [year, month, day] = value.split("-").map(Number);

        const date = new Date(year, month - 1, day);

        return date.toLocaleDateString("en-GB", {
            day: "2-digit",
            month: "short",
            year: "numeric"
        });
    }

    // Status count.
    function countStatus(status) {
        return quotations.filter(
            item => item.status === status
        ).length;
    }

    // Statistics.
    function updateStatistics() {

        const total = quotations.length;
        const converted = countStatus("Converted");
        const expired = countStatus("Expired");

        const pendingStatuses = [
            "Draft", "Sent", "Accepted"
        ];

        const pending = quotations.filter(
            item => pendingStatuses.includes(item.status)
        ).length;

        $("totalQuotations").textContent = total;
        $("convertedCount").textContent = converted;
        $("pendingCount").textContent = pending;
        $("expiredCount").textContent = expired;

        $("allCount").textContent = total;
        $("draftCount").textContent = countStatus("Draft");
        $("sentCount").textContent = countStatus("Sent");
        $("acceptedCount").textContent = countStatus("Accepted");
        $("expiredTabCount").textContent = expired;
        $("convertedTabCount").textContent = converted;
        $("cancelledCount").textContent = countStatus("Cancelled");
    }

    // Filter quotations.
    function getFilteredQuotations() {

        const search = $("quotationSearch")
            .value.trim().toLowerCase();

        const statusFilter = $("filterStatus").value;
        const dateFrom = $("filterDateFrom").value;
        const dateTo = $("filterDateTo").value;

        return quotations.filter(item => {

            if (activeTab !== "all" &&
                item.status !== activeTab) {
                return false;
            }

            if (statusFilter !== "all" &&
                item.status !== statusFilter) {
                return false;
            }

            if (dateFrom && item.date < dateFrom) {
                return false;
            }

            if (dateTo && item.date > dateTo) {
                return false;
            }

            const text = [
                item.quotation_id,
                item.customer,
                item.amount,
                item.status
            ].join(" ").toLowerCase();

            return text.includes(search);
        });
    }

    // Render quotation table.
    function renderQuotations() {

        updateStatistics();

        const filtered = getFilteredQuotations();

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

        const tbody = $("quotationTableBody");

        tbody.innerHTML = "";

        if (!pageData.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="7"
                        class="text-center py-5 text-muted">
                        No quotations found.
                    </td>
                </tr>
            `;

        } else {

            pageData.forEach((item, index) => {

                const tr = document.createElement("tr");

                const statusOptions = allowedStatuses.map(
                    status => `
                        <li>
                            <button type="button"
                                    class="dropdown-item"
                                    data-action="status"
                                    data-status="${status}"
                                    data-id="${item.id}">
                                Mark as ${status}
                            </button>
                        </li>
                    `
                ).join("");

                tr.innerHTML = `
                    <td class="text-center">
                        ${start + index + 1}
                    </td>

                    <td>
                        <div class="qt-quotation-id">
                            ${escapeHTML(item.quotation_id)}
                        </div>
                    </td>

                    <td>
                        ${escapeHTML(formatDate(item.date))}
                    </td>

                    <td>
                        ${escapeHTML(formatDate(item.valid_upto))}
                    </td>

                    <td>
                        ${escapeHTML(formatAmount(item.amount))}
                    </td>

                    <td>
                        <span class="qt-status">
                            ${escapeHTML(item.status)}
                        </span>
                    </td>

                    <td>
                        <div class="qt-actions">

                            <button type="button"
                                    class="qt-action-btn"
                                    data-action="view"
                                    data-id="${item.id}"
                                    title="View Quotation">
                                <i class="bi bi-eye"></i>
                            </button>

                            <a class="qt-action-btn"
                               href="quotation-print.php?id=${item.id}"
                               target="_blank" rel="noopener"
                               title="Print Quotation">
                                <i class="bi bi-printer-fill"></i>
                            </a>

                            <a class="qt-action-btn"
                               href="quotation-print.php?id=${item.id}&download=1"
                               target="_blank" rel="noopener"
                               title="Download PDF">
                                <i class="bi bi-download"></i>
                            </a>

                            <button type="button"
                                    class="qt-action-btn delete"
                                    data-action="delete"
                                    data-id="${item.id}"
                                    title="Delete Quotation">
                                <i class="bi bi-trash3-fill"></i>
                            </button>

                            <div class="dropdown">

                                <button type="button"
                                        class="qt-action-btn more"
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

                tbody.appendChild(tr);
            });
        }

        const from = filtered.length ? start + 1 : 0;
        const to = Math.min(start + perPage, filtered.length);

        $("paginationInfo").textContent =
            `Showing ${from} to ${to} of ${filtered.length} entries`;

        $("currentPage").textContent = currentPage;

        $("firstPage").disabled = currentPage === 1;
        $("prevPage").disabled = currentPage === 1;
        $("nextPage").disabled = currentPage === totalPages;
        $("lastPage").disabled = currentPage === totalPages;
    }

    // View quotation popup.
    function viewQuotation(id) {

        const item = quotations.find(
            quotation => Number(quotation.id) === id
        );

        if (!item) return;

        const tones = { Converted: "green", Accepted: "green", Sent: "blue", Draft: "grey", Expired: "orange", Cancelled: "red" };
        const open = item.status !== "Converted" && item.status !== "Cancelled";

        const body = App.details({
            title: "Quotation Details",
            icon: "file-earmark-text",
            name: item.quotation_id,
            subtitle: item.customer || "",
            badge: { text: item.status, tone: tones[item.status] || "blue" },
            rows: [
                ["Customer", item.customer || "-"],
                ["Quotation Date", formatDate(item.date)],
                ["Valid Upto", formatDate(item.valid_upto)],
                ["Salesman", item.salesman || "-"],
                ["Amount", formatAmount(item.amount)]
            ],
            html: '<div class="details-items text-muted">Loading items...</div>',
            actions: [
                { label: "Download PDF", icon: "download", tone: "neutral", href: "quotation-print.php?download=1&id=" + item.id, newTab: true },
                { label: "Print Quotation", icon: "printer-fill", tone: "neutral", href: "quotation-print.php?id=" + item.id, newTab: true },
                ...(open ? [{ label: "Convert to Order", icon: "cart-check", tone: "primary", href: "add-order.php?quotation=" + item.id }] : []),
                { label: "Delete Quotation", icon: "trash3", tone: "danger", onClick: () => rowAction("delete", id) }
            ]
        });

        App.post(window.location.pathname, { action: "items", id: item.id })
            .then(result => {
                const box = body.querySelector(".details-items");
                if (!box) return;
                box.classList.remove("text-muted");
                box.innerHTML = result.items.length ? `
                    <table>
                        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>${result.items.map(it => `<tr>
                            <td>${escapeHTML(it.name)}</td>
                            <td class="text-end">${it.quantity}</td>
                            <td class="text-end">${formatAmount(it.amount)}</td></tr>`).join("")}</tbody>
                    </table>` : "No item lines.";
            })
            .catch(() => {});
    }

    // Run a table row action (same confirm + logic as the row buttons).
    function rowAction(action, id) {
        const btn = document.querySelector(`[data-action="${action}"][data-id="${id}"]`);
        if (btn) btn.click();
    }

    function showMessage(message) {
        $("quotationToastMessage").textContent = message;

        bootstrap.Toast.getOrCreateInstance(
            $("quotationToast")
        ).show();
    }

    // Update quotation on PHP server.
    async function updateQuotation(action, id, status = "") {

        const formData = new FormData();

        formData.append("action", action);
        formData.append("id", String(id));
        formData.append("csrf_token", csrfToken);

        if (action === "status") {
            formData.append("status", status);
        }

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
                result.message || "Unable to update quotation."
            );
        }

        quotations = result.quotations;
        renderQuotations();

        showMessage(
            action === "delete"
                ? "Quotation deleted successfully."
                : "Quotation status updated successfully."
        );
    }

    // View / Delete / Status actions.
    $("quotationTableBody").addEventListener(
        "click",
        async function (event) {

            const button = event.target.closest("[data-action]");
            if (!button) return;

            const id = Number(button.dataset.id);
            const action = button.dataset.action;

            if (action === "view") {
                viewQuotation(id);
                return;
            }

            if (action === "delete" &&
                !confirm("Are you sure you want to delete this quotation?")) {
                return;
            }

            if (action === "delete" || action === "status") {

                button.disabled = true;

                try {
                    await updateQuotation(
                        action,
                        id,
                        button.dataset.status || ""
                    );
                } catch (error) {
                    showMessage(error.message);
                    button.disabled = false;
                }
            }
        }
    );

    // Tabs.
    document.querySelectorAll(".quotation-section .qt-tab")
        .forEach(tab => {

            tab.addEventListener("click", function () {

                document.querySelectorAll(
                    ".quotation-section .qt-tab"
                ).forEach(item => {
                    item.classList.remove("active");
                });

                this.classList.add("active");

                activeTab = this.dataset.tab;
                currentPage = 1;

                renderQuotations();
            });
        });

    // Search.
    $("quotationSearch").addEventListener("input", () => {
        currentPage = 1;
        renderQuotations();
    });

    // Filters.
    ["filterStatus", "filterDateFrom", "filterDateTo"]
        .forEach(id => {

            $(id).addEventListener("change", () => {
                currentPage = 1;
                renderQuotations();
            });
        });

    // Reset.
    function resetFilters() {

        $("quotationSearch").value = "";
        $("filterStatus").value = "all";
        $("filterDateFrom").value = "";
        $("filterDateTo").value = "";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(".quotation-section .qt-tab")
            .forEach(tab => {

                tab.classList.toggle(
                    "active",
                    tab.dataset.tab === "all"
                );
            });

        renderQuotations();
    }

    $("resetFilters").addEventListener(
        "click",
        resetFilters
    );

    $("resetView").addEventListener(
        "click",
        resetFilters
    );

    // Page size.
    $("pageSize").addEventListener("change", function () {
        perPage = Number(this.value);
        currentPage = 1;
        renderQuotations();
    });

    // Pagination.
    $("firstPage").addEventListener("click", () => {
        currentPage = 1;
        renderQuotations();
    });

    $("prevPage").addEventListener("click", () => {
        currentPage--;
        renderQuotations();
    });

    $("nextPage").addEventListener("click", () => {
        currentPage++;
        renderQuotations();
    });

    $("lastPage").addEventListener("click", () => {

        currentPage = Math.max(
            1,
            Math.ceil(getFilteredQuotations().length / perPage)
        );

        renderQuotations();
    });

    // Initial render.
    renderQuotations();

})();
</script>




<?php include 'footer.php'; ?>