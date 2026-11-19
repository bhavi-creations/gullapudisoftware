<?php
/*
|--------------------------------------------------------------------------
| TAXES PAGE - data stored in MySQL `taxes` table
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

function taxes_all(): array
{
    return cast_rows(
        db_all('SELECT id, name, code, amount, type, platforms, enabled FROM taxes ORDER BY id'),
        ['enabled'],
        ['amount']
    );
}

/* AJAX: SAVE / TOGGLE / DELETE TAX */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_tax' || $action === 'delete_tax') {
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'toggle_tax') {
            db_exec('UPDATE taxes SET enabled = 1 - enabled WHERE id = ?', [$id]);
        } else {
            db_exec('DELETE FROM taxes WHERE id = ?', [$id]);
            log_activity('Taxes', 'Deleted tax #' . $id);
        }
        json_response(['success' => true, 'taxes' => taxes_all()]);
    }

    if ($action !== 'save_tax') {
        json_response(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $taxId = trim($_POST['tax_id'] ?? '');
    $taxName = trim($_POST['tax_name'] ?? '');
    $amount = $_POST['tax_amount'] ?? '';
    $taxType = $_POST['tax_type'] ?? 'Percentage';
    $enabled = ($_POST['enabled'] ?? '0') === '1';

    if ($taxId === '' || $taxName === '') {
        json_response(['success' => false, 'message' => 'Tax ID and Tax Name are required.'], 422);
    }

    if (!is_numeric($amount) || (float)$amount < 0) {
        json_response(['success' => false, 'message' => 'Enter a valid tax amount.'], 422);
    }

    if (!in_array($taxType, ['Percentage', 'Currency'], true)) {
        json_response(['success' => false, 'message' => 'Invalid tax type.'], 422);
    }

    if ($taxType === 'Percentage' && (float)$amount > 100) {
        json_response(['success' => false, 'message' => 'Percentage cannot exceed 100.'], 422);
    }

    if (db_value('SELECT COUNT(*) FROM taxes WHERE code = ?', [$taxId])) {
        json_response(['success' => false, 'message' => 'This Tax ID already exists.'], 409);
    }

    $id = db_insert('taxes', [
        'name' => mb_substr($taxName, 0, 150),
        'code' => mb_substr($taxId, 0, 50),
        'amount' => (float)$amount,
        'type' => $taxType,
        'platforms' => 'All platforms',
        'enabled' => $enabled ? 1 : 0,
    ]);
    log_activity('Taxes', 'Added tax ' . $taxName);

    $newTax = cast_rows([db_one('SELECT id, name, code, amount, type, platforms, enabled FROM taxes WHERE id = ?', [$id])], ['enabled'], ['amount'])[0];

    json_response([
        'success' => true,
        'message' => 'Tax added successfully.',
        'tax' => $newTax
    ]);
}

$taxes = taxes_all();
$pageTitle = 'Taxes';
include 'header.php';
?>




<!-- =================================
     TAXES MAIN PAGE
================================= -->

<section class="taxes-sectionn">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-4">

        <div class="col-md-8">

            <h1 class="tax-title">Taxes</h1>

            <p class="tax-subtitle">
                Manage tax rates used across products,
                quotations, invoices, and POS.
            </p>

        </div>

        <div class="col-md-4 text-md-end">

            <!-- OPEN ADD TAX POPUP -->
            <button type="button"
                    class="tax-add-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#addTaxModal">

                <i class="bi bi-plus-lg me-2"></i>
                Add Tax

            </button>

        </div>

    </div>

    <!-- STATISTICS -->
    <div class="row g-4 mb-4">

        <!-- TOTAL TAXES -->
        <div class="col-md-4">

            <div class="tax-stat-card">

                <div class="tax-stat-icon tax-blue">
                    <i class="bi bi-percent"></i>
                </div>

                <div>
                    <div class="tax-stat-label">
                        Total Taxes
                    </div>

                    <div class="tax-stat-value"
                         id="totalTaxes">11</div>
                </div>

            </div>

        </div>

        <!-- ENABLED -->
        <div class="col-md-4">

            <div class="tax-stat-card">

                <div class="tax-stat-icon tax-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                    <div class="tax-stat-label">
                        Enabled
                    </div>

                    <div class="tax-stat-value"
                         id="enabledTaxes">11</div>
                </div>

            </div>

        </div>

        <!-- DISABLED -->
        <div class="col-md-4">

            <div class="tax-stat-card">

                <div class="tax-stat-icon tax-red">
                    <i class="bi bi-slash-circle"></i>
                </div>

                <div>
                    <div class="tax-stat-label">
                        Disabled
                    </div>

                    <div class="tax-stat-value"
                         id="disabledTaxes">0</div>
                </div>

            </div>

        </div>

    </div>

    <!-- MAIN CONTENT -->
    <div class="row g-4">

        <!-- TAXES TABLE -->
        <div class="col-12">

            <div class="tax-panel tax-list-panel">

                <!-- TABS -->
                <div class="tax-tabs">

                    <button type="button"
                            class="tax-tab active"
                            data-tab="all">
                        All Taxes
                        (<span id="allTabCount">11</span>)
                    </button>

                    <button type="button"
                            class="tax-tab"
                            data-tab="enabled">
                        Enabled
                        (<span id="enabledTabCount">11</span>)
                    </button>

                    <button type="button"
                            class="tax-tab"
                            data-tab="disabled">
                        Disabled
                        (<span id="disabledTabCount">0</span>)
                    </button>

                </div>

                <!-- SEARCH AND FILTER -->
                <div class="tax-toolbar">

                    <div class="tax-search">

                        <i class="bi bi-search"></i>

                        <input type="search"
                               id="taxSearch"
                               placeholder="Search taxes...">

                    </div>

                    <!-- FILTER -->
                    <div class="dropdown">

                        <button type="button"
                                class="tax-tool-btn dropdown-toggle"
                                data-bs-toggle="dropdown"
                                data-bs-auto-close="outside">

                            <i class="bi bi-funnel-fill me-2"></i>
                            Filter

                        </button>

                        <div class="dropdown-menu p-3"
                             style="min-width:230px">

                            <label class="form-label fw-bold">
                                Status
                            </label>

                            <select id="taxStatusFilter"
                                    class="form-select mb-3">

                                <option value="all">All</option>
                                <option value="enabled">Enabled</option>
                                <option value="disabled">Disabled</option>

                            </select>

                            <label class="form-label fw-bold">
                                Tax Type
                            </label>

                            <select id="taxTypeFilter"
                                    class="form-select mb-3">

                                <option value="all">All Types</option>
                                <option value="Percentage">Percentage</option>
                                <option value="Currency">Currency</option>

                            </select>

                            <button type="button"
                                    id="resetTaxFilters"
                                    class="btn btn-outline-secondary w-100">

                                Reset Filters

                            </button>

                        </div>

                    </div>

                    <button type="button"
                            id="resetTaxView"
                            class="tax-tool-btn"
                            title="Reset View">

                        <i class="bi bi-sliders2"></i>

                    </button>

                </div>

                <!-- TAX TABLE -->
                <div class="table-responsive">

                    <table class="tax-table">

                        <thead>
                            <tr>
                                <th>TAX</th>
                                <th>AMOUNT</th>
                                <th>TYPE</th>
                                <th>PLATFORMS</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>

                        <tbody id="taxTableBody"></tbody>

                    </table>

                </div>

                <!-- PAGINATION -->
                <div class="tax-footer">

                    <span id="paginationInfo">
                        Showing 1 to 11 of 11 entries
                    </span>

                    <div class="d-flex align-items-center gap-2">

                        <button class="tax-page-btn"
                                id="firstPage">&lt;&lt;</button>

                        <button class="tax-page-btn"
                                id="prevPage">&lt;</button>

                        <button class="tax-page-btn active"
                                id="currentPage">1</button>

                        <button class="tax-page-btn"
                                id="nextPage">&gt;</button>

                        <button class="tax-page-btn"
                                id="lastPage">&gt;&gt;</button>

                        <select id="pageSize"
                                class="tax-page-select">

                            <option value="10">10 / page</option>
                            <option value="20" selected>20 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>

                        </select>

                    </div>

                </div>

            </div>

        </div>


    </div>

</div>

</section>

<!-- =================================
     ADD TAX POPUP MODAL
================================= -->

<div class="modal fade"
     id="addTaxModal"
     tabindex="-1"
     aria-labelledby="addTaxModalLabel"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-xl">

        <div class="modal-content">

            <!-- MODAL HEADER -->
            <div class="tax-modal-header">

                <div>

                    <h2 class="tax-modal-title"
                        id="addTaxModalLabel">
                        Add Tax
                    </h2>

                    <p class="tax-modal-subtitle">
                        Configure the tax used on products
                        and transactions.
                    </p>

                </div>

                <button type="button"
                        class="tax-close-btn"
                        data-bs-dismiss="modal"
                        aria-label="Close">

                    <i class="bi bi-x-lg"></i>

                </button>

            </div>

            <!-- ADD TAX FORM -->
            <form id="addTaxForm">

                <div class="tax-modal-body">

                    <div class="row g-4">

                        <!-- TAX ID -->
                        <div class="col-md-6">

                            <label class="tax-form-label"
                                   for="newTaxId">
                                Tax ID
                            </label>

                            <input type="text"
                                   id="newTaxId"
                                   name="tax_id"
                                   class="tax-form-input"
                                   placeholder="e.g. CGST-6"
                                   maxlength="50"
                                   required>

                        </div>

                        <!-- TAX NAME -->
                        <div class="col-md-6">

                            <label class="tax-form-label"
                                   for="newTaxName">
                                Tax Name
                            </label>

                            <input type="text"
                                   id="newTaxName"
                                   name="tax_name"
                                   class="tax-form-input"
                                   placeholder="e.g. CGST 6%"
                                   maxlength="150"
                                   required>

                        </div>

                        <!-- TAX AMOUNT -->
                        <div class="col-md-6">

                            <label class="tax-form-label"
                                   for="newTaxAmount">
                                Tax Amount
                            </label>

                            <input type="number"
                                   id="newTaxAmount"
                                   name="tax_amount"
                                   class="tax-form-input"
                                   placeholder="6"
                                   min="0"
                                   step="0.01"
                                   required>

                        </div>

                        <!-- TAX TYPE -->
                        <div class="col-md-6">

                            <label class="tax-form-label">
                                Tax Type
                            </label>

                            <div class="tax-type-control">

                                <input type="radio"
                                       class="btn-check"
                                       name="tax_type"
                                       id="taxPercentage"
                                       value="Percentage"
                                       checked>

                                <label class="tax-type-label"
                                       for="taxPercentage">
                                    Percentage
                                </label>

                                <input type="radio"
                                       class="btn-check"
                                       name="tax_type"
                                       id="taxCurrency"
                                       value="Currency">

                                <label class="tax-type-label"
                                       for="taxCurrency">
                                    Currency
                                </label>

                            </div>

                        </div>

                        <!-- ENABLED -->
                        <div class="col-12">

                            <div class="tax-enabled-row">

                                <div>

                                    <label for="newTaxEnabled"
                                           class="tax-form-label mb-1">
                                        Enabled
                                    </label>

                                    <p class="tax-enabled-description">
                                        Allow this tax to be selected in
                                        product and POS screens.
                                    </p>

                                </div>

                                <div class="form-check form-switch p-0 m-0">

                                    <input type="checkbox"
                                           class="form-check-input tax-switch m-0"
                                           id="newTaxEnabled"
                                           role="switch"
                                           checked>

                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- ERROR MESSAGE -->
                    <div id="addTaxError"
                         class="alert alert-danger d-none mt-4"
                         role="alert">
                    </div>

                </div>

                <!-- FOOTER -->
                <div class="tax-modal-footer">

                    <button type="button"
                            class="tax-cancel-btn"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="tax-save-btn"
                            id="saveTaxBtn">
                        Save Tax
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- SUCCESS TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">

    <div class="toast"
         id="taxSuccessToast"
         role="status"
         aria-live="polite">

        <div class="toast-header">

            <i class="bi bi-check-circle-fill text-success me-2"></i>

            <strong class="me-auto">
                Success
            </strong>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="toast">
            </button>

        </div>

        <div class="toast-body">
            Tax added successfully.
        </div>

    </div>

</div>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    let taxes = <?php echo json_encode(
        $taxes,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const $ = id => document.getElementById(id);

    const modalElement = $("addTaxModal");

    const taxModal = bootstrap.Modal.getOrCreateInstance(
        modalElement
    );

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    // UPDATE STATISTICS
    function updateStats() {

        const total = taxes.length;

        const enabled = taxes.filter(
            tax => tax.enabled
        ).length;

        const disabled = total - enabled;

        $("totalTaxes").textContent = total;
        $("enabledTaxes").textContent = enabled;
        $("disabledTaxes").textContent = disabled;

        $("allTabCount").textContent = total;
        $("enabledTabCount").textContent = enabled;
        $("disabledTabCount").textContent = disabled;
    }

    // FILTER TAXES
    function getFilteredTaxes() {

        const search = $("taxSearch")
            .value.toLowerCase().trim();

        const status = $("taxStatusFilter").value;

        const type = $("taxTypeFilter").value;

        return taxes.filter(tax => {

            if (activeTab === "enabled" && !tax.enabled)
                return false;

            if (activeTab === "disabled" && tax.enabled)
                return false;

            if (status === "enabled" && !tax.enabled)
                return false;

            if (status === "disabled" && tax.enabled)
                return false;

            if (type !== "all" && tax.type !== type)
                return false;

            const searchable = [
                tax.name,
                tax.code,
                tax.amount,
                tax.type,
                tax.platforms
            ].join(" ").toLowerCase();

            return searchable.includes(search);
        });
    }

    // RENDER TABLE
    function renderTaxes() {

        updateStats();

        const filtered = getFilteredTaxes();

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

        const tbody = $("taxTableBody");

        tbody.innerHTML = "";

        if (!pageData.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-5">
                        No taxes found.
                    </td>
                </tr>
            `;

        } else {

            pageData.forEach(tax => {

                const row = document.createElement("tr");

                row.innerHTML = `
                    <td>
                        <div class="tax-name">
                            ${escapeHTML(tax.name)}
                        </div>
                        <div class="tax-code">
                            ${escapeHTML(tax.code)}
                        </div>
                    </td>

                    <td>
                        ${escapeHTML(tax.amount)}
                    </td>

                    <td>
                        ${escapeHTML(tax.type)}
                    </td>

                    <td>
                        ${escapeHTML(tax.platforms)}
                    </td>

                    <td>
                        <button type="button"
                                class="tax-badge border-0 ${tax.enabled ? "" : "disabled"}"
                                data-toggle-tax="${tax.id}"
                                title="Click to ${tax.enabled ? "disable" : "enable"}">
                            ${tax.enabled ? "Enabled" : "Disabled"}
                        </button>
                    </td>
                `;

                tbody.appendChild(row);
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

    // TAX TABS
    document.querySelectorAll(".taxes-sectionn .tax-tab")
        .forEach(tab => {

            tab.addEventListener("click", function () {

                document.querySelectorAll(
                    ".taxes-sectionn .tax-tab"
                ).forEach(item => {
                    item.classList.remove("active");
                });

                this.classList.add("active");

                activeTab = this.dataset.tab;

                currentPage = 1;

                renderTaxes();
            });
        });

    // ENABLE / DISABLE TAX (saved in database)
    $("taxTableBody").addEventListener("click", async function (event) {
        const button = event.target.closest("[data-toggle-tax]");
        if (!button) return;
        button.disabled = true;
        try {
            const result = await App.post(window.location.pathname, {
                action: "toggle_tax",
                id: button.dataset.toggleTax
            });
            taxes = result.taxes;
            renderTaxes();
        } catch (error) {
            App.toast(error.message, "error");
            button.disabled = false;
        }
    });

    // SEARCH
    $("taxSearch").addEventListener("input", function () {
        currentPage = 1;
        renderTaxes();
    });

    // FILTERS
    $("taxStatusFilter").addEventListener("change", function () {
        currentPage = 1;
        renderTaxes();
    });

    $("taxTypeFilter").addEventListener("change", function () {
        currentPage = 1;
        renderTaxes();
    });

    // RESET FILTERS
    function resetFilters() {

        $("taxSearch").value = "";
        $("taxStatusFilter").value = "all";
        $("taxTypeFilter").value = "all";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(".taxes-sectionn .tax-tab")
            .forEach(tab => {

                tab.classList.toggle(
                    "active",
                    tab.dataset.tab === "all"
                );
            });

        renderTaxes();
    }

    $("resetTaxFilters").addEventListener(
        "click",
        resetFilters
    );

    $("resetTaxView").addEventListener(
        "click",
        resetFilters
    );

    // PAGINATION
    $("pageSize").addEventListener("change", function () {

        perPage = Number(this.value);
        currentPage = 1;
        renderTaxes();
    });

    $("firstPage").addEventListener("click", function () {
        currentPage = 1;
        renderTaxes();
    });

    $("prevPage").addEventListener("click", function () {
        currentPage--;
        renderTaxes();
    });

    $("nextPage").addEventListener("click", function () {
        currentPage++;
        renderTaxes();
    });

    $("lastPage").addEventListener("click", function () {

        currentPage = Math.max(
            1,
            Math.ceil(getFilteredTaxes().length / perPage)
        );

        renderTaxes();
    });

    // RESET MODAL
    modalElement.addEventListener("show.bs.modal", function () {

        $("addTaxForm").reset();

        $("taxPercentage").checked = true;
        $("newTaxEnabled").checked = true;

        $("addTaxError").classList.add("d-none");
        $("addTaxError").textContent = "";
    });

    // SAVE TAX VIA PHP AJAX
    $("addTaxForm").addEventListener("submit", async function (event) {

        event.preventDefault();

        if (!this.reportValidity()) return;

        const amount = Number($("newTaxAmount").value);

        const type = document.querySelector(
            'input[name="tax_type"]:checked'
        ).value;

        const errorBox = $("addTaxError");
        const saveBtn = $("saveTaxBtn");

        errorBox.classList.add("d-none");
        errorBox.textContent = "";

        if (type === "Percentage" && amount > 100) {

            errorBox.textContent =
                "Percentage tax cannot exceed 100.";

            errorBox.classList.remove("d-none");
            return;
        }

        const data = new FormData();

        data.append("action", "save_tax");
        data.append("csrf_token", App.csrf());
        data.append("tax_id", $("newTaxId").value.trim());
        data.append("tax_name", $("newTaxName").value.trim());
        data.append("tax_amount", $("newTaxAmount").value);
        data.append("tax_type", type);

        data.append(
            "enabled",
            $("newTaxEnabled").checked ? "1" : "0"
        );

        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";

        try {

            const response = await fetch(
                window.location.pathname,
                {
                    method: "POST",
                    body: data,
                    credentials: "same-origin"
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to save tax."
                );
            }

            // Update current table from server response.
            taxes.push(result.tax);

            resetFilters();
            taxModal.hide();

            bootstrap.Toast.getOrCreateInstance(
                $("taxSuccessToast")
            ).show();

        } catch (error) {

            errorBox.textContent = error.message;
            errorBox.classList.remove("d-none");

        } finally {

            saveBtn.disabled = false;
            saveBtn.textContent = "Save Tax";
        }
    });

    // INITIAL TABLE RENDER
    renderTaxes();

})();
</script>










<?php include 'footer.php'; ?>