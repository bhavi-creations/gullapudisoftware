<?php
/*
|--------------------------------------------------------------------------
| QUOTATIONS PAGE - data from MySQL `quotations` + `quotation_items`
|--------------------------------------------------------------------------
| Convert to Invoice:
|  - normal quotation: creates order + invoice once. If the quotation is
|    edited later it opens again, and converting again UPDATES that invoice.
|  - master quotation: can be converted any number of times (new invoice each time).
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

// master-quotation.php includes this page with $masterMode = true.
$masterMode = !empty($masterMode);

$allowedStatuses = [
    'Draft', 'Sent', 'Accepted', 'Expired',
    'Converted', 'Cancelled'
];

function quotations_all(bool $masterOnly = false): array
{
    // Auto-expire open quotations past their validity date (master quotations never expire).
    db_exec("UPDATE quotations SET status = 'Expired'
             WHERE status IN ('Draft','Sent') AND is_master = 0 AND valid_upto IS NOT NULL AND valid_upto < CURDATE()");

    // Customer + product details are included so the search box can find a
    // quotation by anything: name, mobile, business, GSTIN, product, amount...
    return cast_rows(
        db_all('SELECT q.id, q.quotation_id, q.date, q.valid_upto, q.customer, q.salesman, q.reference, q.region,
                       q.notes, q.subtotal, q.discount, q.tax, q.amount, q.status, q.is_master,
                       inv.id AS invoice_id, inv.invoice_number, inv.eway_bill, inv.vehicle_no, inv.dispatched_through,
                       c.mobile AS customer_mobile, c.email AS customer_email,
                       c.business_name AS customer_business, c.gstin AS customer_gstin,
                       (SELECT GROUP_CONCAT(CONCAT_WS(\' \', i.name, i.thickness, i.size, i.hsn) SEPARATOR \' | \')
                          FROM quotation_items i WHERE i.quotation_id = q.id) AS item_names
                FROM quotations q
                LEFT JOIN customers c ON c.id = q.customer_id
                LEFT JOIN invoices inv ON inv.id = q.invoice_id'
                . ($masterOnly ? ' WHERE q.is_master = 1' : '') .
                ' ORDER BY q.id DESC'),
        ['is_master'],
        ['subtotal', 'discount', 'tax', 'amount']
    );
}

/**
 * Quotation -> Order + Tax Invoice. Returns invoice id.
 * $advance = ['amount' => float, 'mode' => string, 'date' => Y-m-d] paid now (optional).
 */
function quotation_to_invoice(int $id, array $dispatch, array $advance = []): int
{
    $q = db_one('SELECT * FROM quotations WHERE id = ?', [$id]);
    $items = db_all('SELECT product_id, name, thickness, size, hsn, quantity, rate, tax, discount, amount
                     FROM quotation_items WHERE quotation_id = ? ORDER BY id', [$id]);
    if (!$items) {
        throw new RuntimeException('This quotation has no products.');
    }
    $isMaster = (int)$q['is_master'] === 1;
    $linked = (!$isMaster && $q['invoice_id'])
        ? db_one('SELECT * FROM invoices WHERE id = ?', [(int)$q['invoice_id']])
        : null;
    if ($linked && $linked['status'] === 'Cancelled') {
        $linked = null; // cancelled invoice -> make a fresh one
    }

    $totals = [
        'subtotal' => $q['subtotal'],
        'discount' => $q['discount'],
        'tax' => $q['tax'],
        'amount' => $q['amount'],
    ];
    $dispatchCols = [
        'eway_bill' => $dispatch['eway_bill'] ?: null,
        'vehicle_no' => $dispatch['vehicle_no'] ?: null,
        'dispatched_through' => $dispatch['dispatched_through'] ?: null,
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($linked) {
            /* ---- Update the invoice made from this quotation earlier ---- */
            $invoiceId = (int)$linked['id'];
            $invoiceNumber = $linked['invoice_number'];
            $orderId = (int)$linked['order_id'];
            if ($orderId && db_value('SELECT COUNT(*) FROM orders WHERE id = ?', [$orderId])) {
                order_replace_items($orderId, $items);
                db_update('orders', [
                    'customer_id' => $q['customer_id'],
                    'customer' => $q['customer'],
                    'region' => $q['region'],
                    'notes' => $q['notes'],
                ] + $totals, $orderId);
            }
            $paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
            if ($paid > (float)$q['amount'] + 0.009) {
                throw new RuntimeException('Invoice ' . $invoiceNumber . ' already has Rs ' . number_format($paid, 2)
                    . ' paid, more than the new total. Remove a payment first.');
            }
            db_update('invoices', [
                'customer_id' => $q['customer_id'],
                'customer' => $q['customer'],
                'region' => $q['region'],
            ] + $totals + $dispatchCols, $invoiceId);
            $logText = "Invoice $invoiceNumber updated from edited quotation {$q['quotation_id']}";
        } else {
            /* ---- New order + invoice ---- */
            $orderNumber = next_number('orders', 'order_number', 'ORD-');
            $invoiceNumber = next_number('invoices', 'invoice_number', 'SI-');
            $today = date('Y-m-d');
            $due = date('Y-m-d', strtotime('+30 days'));

            $orderId = db_insert('orders', [
                'order_number' => $orderNumber,
                'date' => $today,
                'delivery_date' => $due,
                'customer_id' => $q['customer_id'],
                'customer' => $q['customer'],
                'salesman' => $q['salesman'],
                'store' => $q['store'] ?: APP_STORE,
                'reference' => $q['quotation_id'],
                'region' => $q['region'],
                'notes' => $q['notes'],
                'status' => 'Invoice Raised',
                'created_by' => (int)$_SESSION['user_id'],
            ] + $totals);

            order_replace_items($orderId, $items);

            $invoiceId = db_insert('invoices', [
                'invoice_number' => $invoiceNumber,
                'order_id' => $orderId,
                'customer_id' => $q['customer_id'],
                'customer' => $q['customer'],
                'date' => $today,
                'due_date' => $due,
                'region' => $q['region'],
                'status' => 'Submitted',
            ] + $totals + $dispatchCols);

            db_update('orders', ['invoice_id' => $invoiceId], $orderId);
            $logText = "Quotation {$q['quotation_id']} converted to Invoice $invoiceNumber (Order $orderNumber)";
        }

        // Master quotations stay reusable; normal ones are marked Converted and linked.
        if (!$isMaster) {
            db_update('quotations', ['status' => 'Converted', 'invoice_id' => $invoiceId], $id);
        }

        // Advance / amount paid now.
        $advAmount = round((float)($advance['amount'] ?? 0), 2);
        if ($advAmount > 0) {
            $paid = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice = ?', [$invoiceNumber]);
            if ($advAmount > round((float)$q['amount'] - $paid, 2) + 0.009) {
                throw new RuntimeException('Amount paid is more than the invoice balance.');
            }
            db_insert('payments', [
                'date' => $advance['date'] ?? date('Y-m-d'),
                'type' => 'Advance',
                'invoice' => $invoiceNumber,
                'invoice_id' => $invoiceId,
                'customer_id' => $q['customer_id'],
                'customer' => $q['customer'],
                'narration' => next_number('payments', 'narration', 'PAY-'),
                'mode' => $advance['mode'] ?? 'Cash',
                'amount' => $advAmount,
                'notes' => 'Paid at billing',
            ]);
        }
        refresh_invoice_status($invoiceNumber);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    log_activity('Invoices', $logText);
    return $invoiceId;
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

    if ($action === 'invoice') {
        // Creating invoices needs Invoices access too.
        if (!user_can('invoices', 'add') && !user_can('direct_invoice', 'add')) {
            json_response(['success' => false, 'message' => 'You do not have access to create invoices.'], 403);
        }
        $row = db_one('SELECT status, is_master FROM quotations WHERE id = ?', [$id]);
        $status = $row['status'];
        if ($status === 'Cancelled' || ($status === 'Converted' && !(int)$row['is_master'])) {
            json_response(['success' => false, 'message' => "This quotation is already $status. Edit it to convert again."], 422);
        }
        $modes = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Card'];
        $advDate = (string)($_POST['advance_date'] ?? '');
        $advance = [
            'amount' => is_numeric($_POST['advance_amount'] ?? '') ? max(0, (float)$_POST['advance_amount']) : 0,
            'mode' => in_array($_POST['advance_mode'] ?? '', $modes, true) ? $_POST['advance_mode'] : 'Cash',
            'date' => DateTimeImmutable::createFromFormat('!Y-m-d', $advDate) ? $advDate : date('Y-m-d'),
        ];
        try {
            $invoiceId = quotation_to_invoice($id, [
                'eway_bill' => mb_substr(trim((string)($_POST['eway_bill'] ?? '')), 0, 50),
                'vehicle_no' => mb_substr(strtoupper(trim((string)($_POST['vehicle_no'] ?? ''))), 0, 50),
                'dispatched_through' => mb_substr(trim((string)($_POST['dispatched_through'] ?? '')), 0, 150),
            ], $advance);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => 'Unable to create invoice: ' . $e->getMessage()], 500);
        }
        json_response(['success' => true, 'redirect' => 'invoice-print.php?id=' . $invoiceId . '&new=1']);
    }

    if ($action === 'delete') {
        db_exec('DELETE FROM quotations WHERE id = ?', [$id]);
        log_activity('Quotations', 'Deleted quotation ' . $quotation['quotation_id']);
    } elseif ($action === 'master') {
        $isMaster = ($_POST['value'] ?? '') === '1' ? 1 : 0;
        db_update('quotations', ['is_master' => $isMaster], $id);
        log_activity('Quotations', 'Quotation ' . $quotation['quotation_id'] . ($isMaster ? ' added to' : ' removed from') . ' Master Quotations');
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
        'quotations' => quotations_all($masterMode)
    ]);
}

$quotations = quotations_all($masterMode);
$pageTitle = $masterMode ? 'Master Quotations' : 'Quotations';
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
            <h1 class="qt-title">
                <?php if ($masterMode): ?><i class="bi bi-star-fill text-warning me-1"></i><?php endif; ?>
                <?php echo $masterMode ? 'Master Quotations' : 'Quotations'; ?>
            </h1>

            <p class="qt-subtitle">
                <?php echo $masterMode
                    ? 'Quotations saved with the Master Quotation tick. Search by anything - customer, mobile, product, amount, date.'
                    : 'Manage quotations for your customers'; ?>
            </p>
        </div>

        <div class="col-md-4 text-md-end">

            <!-- ADD QUOTATION REDIRECT -->
            <a href="add-quotation.php<?php echo $masterMode ? '?master=1' : ''; ?>"
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
                       placeholder="<?php echo $masterMode
                           ? 'Search anything: customer name, mobile, business, product, amount, date...'
                           : 'Search by quotation no, customer, mobile, product...'; ?>">

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

<!-- CONVERT TO INVOICE MODAL -->
<div class="modal fade" id="convertInvoiceModal" tabindex="-1" aria-labelledby="convertInvoiceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" id="convertInvoiceForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="convertInvoiceTitle">
                    <i class="bi bi-receipt me-1"></i> Convert to Invoice
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="convertQuotationId">
                <p class="text-muted mb-3" id="convertInvoiceInfo"></p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="convertEway">Eway Bill Number</label>
                        <input type="text" class="form-control" id="convertEway" maxlength="50" placeholder="Enter eway bill number">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="convertDispatch">Dispatched Through</label>
                        <input type="text" class="form-control" id="convertDispatch" maxlength="150" placeholder="Enter dispatched through">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="convertVehicle">Vehicle Number</label>
                        <input type="text" class="form-control text-uppercase" id="convertVehicle" maxlength="50" placeholder="Enter vehicle number">
                    </div>
                </div>
                <div class="border rounded-3 p-3 mt-3" style="background:#f7faff">
                    <div class="fw-bold mb-2"><i class="bi bi-cash-coin text-success me-1"></i> Advance / Amount Paid Now <small class="text-muted fw-normal">(optional)</small></div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="convertAdvAmount">Amount (Rs)</label>
                            <input type="number" class="form-control" id="convertAdvAmount" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="convertAdvMode">Payment Mode</label>
                            <select class="form-select" id="convertAdvMode">
                                <option>Cash</option><option>UPI</option><option>Bank Transfer</option><option>Cheque</option><option>Card</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="convertAdvDate">Paid On</label>
                            <input type="date" class="form-control" id="convertAdvDate">
                        </div>
                    </div>
                </div>
                <small class="text-muted d-block mt-3" id="convertInvoiceNote"></small>
                <div class="alert alert-danger d-none mt-3 mb-0" id="convertInvoiceError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="convertInvoiceSubmit">
                    <i class="bi bi-receipt"></i> Generate Invoice
                </button>
            </div>
        </form>
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

            if (!search) return true;

            // Search across every detail of the quotation; all typed words must match.
            const text = [
                item.quotation_id,
                item.customer,
                item.customer_mobile,
                item.customer_email,
                item.customer_business,
                item.customer_gstin,
                item.salesman,
                item.reference,
                item.region,
                item.notes,
                item.item_names,
                item.amount,
                formatAmount(item.amount),
                item.date,
                formatDate(item.date),
                item.valid_upto,
                formatDate(item.valid_upto),
                item.status,
                item.is_master ? "master" : ""
            ].join(" ").toLowerCase();

            return search.split(/\s+/).every(word => text.includes(word));
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
                            ${item.is_master ? '<i class="bi bi-star-fill text-warning ms-1" title="Master Quotation"></i>' : ""}
                        </div>
                        <div class="small text-muted">${escapeHTML(item.customer || "")}</div>
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
                               href="add-quotation.php?edit=${item.id}"
                               title="Edit Quotation">
                                <i class="bi bi-pencil-square"></i>
                            </a>

                            ${isOpen(item) ? `<button type="button" class="qt-action-btn" data-action="invoice" data-id="${item.id}"
                                    title="${escapeHTML(convertLabel(item))}" style="color:#16a34a">
                                <i class="bi bi-receipt"></i>
                            </button>` : ""}

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
                                    ${isOpen(item) ? `
                                    <li>
                                        <button type="button" class="dropdown-item fw-semibold"
                                                data-action="invoice" data-id="${item.id}">
                                            <i class="bi bi-receipt me-2"></i>${escapeHTML(convertLabel(item))}
                                        </button>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="add-order.php?quotation=${item.id}">
                                            <i class="bi bi-cart-check me-2"></i>Convert to Order
                                        </a>
                                    </li>` : ""}
                                    <li>
                                        <button type="button" class="dropdown-item"
                                                data-action="master" data-id="${item.id}"
                                                data-value="${item.is_master ? "0" : "1"}">
                                            <i class="bi bi-star${item.is_master ? "" : "-fill"} me-2"></i>${item.is_master ? "Remove from Master" : "Add to Master Quotations"}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
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
        const open = isOpen(item);

        const body = App.details({
            title: "Quotation Details",
            icon: "file-earmark-text",
            name: item.quotation_id,
            subtitle: item.customer || "",
            badge: { text: item.status, tone: tones[item.status] || "blue" },
            rows: [
                ["Customer", item.customer || "-"],
                ["Mobile", item.customer_mobile || "-"],
                ["Master Quotation", item.is_master ? "Yes" : "No"],
                ["Quotation Date", formatDate(item.date)],
                ["Valid Upto", formatDate(item.valid_upto)],
                ["Salesman", item.salesman || "-"],
                ["Amount", formatAmount(item.amount)],
                ["Invoice", item.invoice_number || "-"]
            ],
            html: '<div class="details-items text-muted">Loading items...</div>',
            actions: [
                { label: "Download PDF", icon: "download", tone: "neutral", href: "quotation-print.php?download=1&id=" + item.id, newTab: true },
                { label: "Print Quotation", icon: "printer-fill", tone: "neutral", href: "quotation-print.php?id=" + item.id, newTab: true },
                { label: "Edit Quotation", icon: "pencil-square", tone: "neutral", href: "add-quotation.php?edit=" + item.id },
                ...(open ? [
                    { label: convertLabel(item), icon: "receipt", tone: "primary", onClick: () => openInvoiceModal(id) },
                    { label: "Convert to Order", icon: "cart-check", tone: "neutral", href: "add-order.php?quotation=" + item.id }
                ] : []),
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

    // Can be converted: master quotations always (unless cancelled); others until converted.
    // Editing a converted quotation opens it again.
    function isOpen(item) {
        if (item.status === "Cancelled") return false;
        return item.is_master || item.status !== "Converted";
    }

    function convertLabel(item) {
        return !item.is_master && item.invoice_id ? "Update Invoice " + item.invoice_number : "Convert to Invoice";
    }

    // Convert to Invoice: ask for dispatch details, then create order + invoice.
    const invoiceModal = bootstrap.Modal.getOrCreateInstance($("convertInvoiceModal"));

    function openInvoiceModal(id) {
        const item = quotations.find(q => Number(q.id) === Number(id));
        if (!item) return;
        const updating = !item.is_master && item.invoice_id;
        $("convertQuotationId").value = item.id;
        // Edited again after conversion -> the earlier invoice is updated, keep its dispatch details.
        $("convertEway").value = updating ? (item.eway_bill || "") : "";
        $("convertDispatch").value = updating ? (item.dispatched_through || "") : "";
        $("convertVehicle").value = updating ? (item.vehicle_no || "") : "";
        $("convertAdvAmount").value = "";
        $("convertAdvMode").value = "Cash";
        $("convertAdvDate").value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        $("convertInvoiceError").classList.add("d-none");
        $("convertInvoiceTitle").innerHTML = updating
            ? '<i class="bi bi-arrow-repeat me-1"></i> Update Invoice ' + escapeHTML(item.invoice_number)
            : '<i class="bi bi-receipt me-1"></i> Convert to Invoice';
        $("convertInvoiceSubmit").innerHTML = updating
            ? '<i class="bi bi-arrow-repeat"></i> Update Invoice'
            : '<i class="bi bi-receipt"></i> Generate Invoice';
        $("convertInvoiceNote").textContent = updating
            ? `This quotation was edited after conversion. Invoice ${item.invoice_number} will be updated with the new products and amount (stock is corrected automatically).`
            : item.is_master
                ? "Master quotation: a new invoice is created each time. The master quotation stays as it is for next use."
                : "Eway bill, dispatch and vehicle are optional. An order and a tax invoice are created, product stock is reduced, and the quotation is marked Converted.";
        $("convertInvoiceInfo").textContent =
            `${item.quotation_id} - ${item.customer || ""} - ${formatAmount(item.amount)}`;
        // Close the details popup (if open) before showing this one.
        document.querySelectorAll(".modal.show").forEach(m => {
            const inst = bootstrap.Modal.getInstance(m);
            if (inst && m.id !== "convertInvoiceModal") inst.hide();
        });
        invoiceModal.show();
    }

    $("convertInvoiceForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const button = $("convertInvoiceSubmit");
        const errorBox = $("convertInvoiceError");
        errorBox.classList.add("d-none");
        button.disabled = true;

        const formData = new FormData();
        formData.append("action", "invoice");
        formData.append("id", $("convertQuotationId").value);
        formData.append("csrf_token", csrfToken);
        formData.append("eway_bill", $("convertEway").value.trim());
        formData.append("dispatched_through", $("convertDispatch").value.trim());
        formData.append("vehicle_no", $("convertVehicle").value.trim());
        formData.append("advance_amount", $("convertAdvAmount").value || "0");
        formData.append("advance_mode", $("convertAdvMode").value);
        formData.append("advance_date", $("convertAdvDate").value);

        try {
            const response = await fetch(window.location.pathname, {
                method: "POST", body: formData, credentials: "same-origin"
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || "Unable to create invoice.");
            }
            window.location.href = result.redirect;
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.classList.remove("d-none");
            button.disabled = false;
        }
    });

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

        if (action === "master") {
            formData.append("value", status);
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
                : action === "master"
                    ? (status === "1" ? "Added to Master Quotations." : "Removed from Master Quotations.")
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

            if (action === "invoice") {
                openInvoiceModal(id);
                return;
            }

            if (action === "master") {
                button.disabled = true;
                try {
                    await updateQuotation("master", id, button.dataset.value);
                } catch (error) {
                    showMessage(error.message);
                    button.disabled = false;
                }
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