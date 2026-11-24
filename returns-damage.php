<?php
/*
|--------------------------------------------------------------------------
| RETURNS & DAMAGE MANAGEMENT - data from MySQL `returns_damage`
|--------------------------------------------------------------------------
| - Create return requests (New Return)
| - Approve: good items go back into stock, damaged items are tagged
| - Reject: no stock change
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$returnReasons = ['damaged', 'wrong item', 'defective', 'customer return', 'other'];

function returns_all(): array
{
    $rows = db_all('SELECT * FROM returns_damage ORDER BY id DESC');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['damage_tagged'] = (bool)$r['damage_tagged'];
        $r['stock_adjusted'] = (bool)$r['stock_adjusted'];
        $r['notes'] = $r['notes'] ?? '';
        $r['order_id'] = $r['order_id'] ?? '';
        $r['products'] = json_decode($r['products'] ?? '[]', true) ?: [];
    }
    return $rows;
}

/* JSON RESPONSE */
function returnDamageResponse($data, $status = 200) {
    json_response($data, $status);
}

/* ==========================================
   AJAX ACTIONS
========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_json();

    $action = $_POST['action'] ?? '';

    if ($action === 'refresh') {
        returnDamageResponse(['success' => true, 'returns' => returns_all()]);
    }

    /* Check the form of a new / edited return. Returns [order number, date, notes, clean products, damaged?]. */
    $readReturnForm = function () use ($returnReasons): array {
        $orderNumber = trim($_POST['order_id'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 2000);
        $items = json_decode($_POST['products'] ?? '[]', true);

        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            returnDamageResponse(['success' => false, 'message' => 'Enter a valid return date.'], 422);
        }
        if (!is_array($items) || !$items) {
            returnDamageResponse(['success' => false, 'message' => 'Add at least one product.'], 422);
        }

        $clean = [];
        $damaged = false;
        foreach (array_slice($items, 0, 50) as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            $reason = in_array($item['reason'] ?? '', $returnReasons, true) ? $item['reason'] : 'other';
            $name = db_value('SELECT name FROM products WHERE id = ?', [$pid]);
            if (!$name || $qty <= 0) {
                returnDamageResponse(['success' => false, 'message' => 'Check product and quantity.'], 422);
            }
            $damaged = $damaged || $reason === 'damaged';
            $clean[] = ['product_id' => $pid, 'name' => $name, 'quantity' => $qty, 'reason' => $reason];
        }
        return [mb_substr($orderNumber, 0, 30), $date, $notes, $clean, $damaged];
    };

    /* Good (not damaged) items put back into / taken out of stock. $sign = +1 or -1. */
    $moveGoodStock = function (array $products, int $sign): void {
        foreach ($products as $p) {
            if (($p['reason'] ?? '') !== 'damaged' && !empty($p['product_id'])) {
                db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$sign * (float)$p['quantity'], (int)$p['product_id']]);
            }
        }
    };

    /* CREATE NEW RETURN REQUEST */
    if ($action === 'create_return') {
        [$orderNumber, $date, $notes, $clean, $damaged] = $readReturnForm();

        $returnId = next_number('returns_damage', 'return_id', 'RET-');
        db_insert('returns_damage', [
            'return_id' => $returnId,
            'order_id' => $orderNumber ?: null,
            'date' => $date,
            'status' => 'Return Requested',
            'notes' => $notes ?: null,
            'damage_tagged' => $damaged ? 1 : 0,
            'stock_adjusted' => 0,
            'products' => json_encode($clean),
        ]);
        log_activity('Returns', "Return $returnId requested" . ($orderNumber ? " for order $orderNumber" : ''));

        returnDamageResponse([
            'success' => true,
            'message' => "Return $returnId created successfully.",
            'returns' => returns_all()
        ]);
    }

    /* EDIT A RETURN (approved returns: stock is corrected to the new products) */
    if ($action === 'update_return') {
        $id = (int)($_POST['id'] ?? 0);
        $record = db_one('SELECT * FROM returns_damage WHERE id = ?', [$id]);
        if (!$record) {
            returnDamageResponse(['success' => false, 'message' => 'Return not found.'], 404);
        }
        [$orderNumber, $date, $notes, $clean, $damaged] = $readReturnForm();

        $pdo = db();
        $pdo->beginTransaction();
        $restock = $record['status'] === 'Return Approved' && (int)$record['stock_adjusted'] === 1;
        if ($restock) {
            // Undo what the old products put back, then put back the new ones.
            $moveGoodStock(json_decode($record['products'] ?? '[]', true) ?: [], -1);
            $moveGoodStock($clean, +1);
        }
        db_update('returns_damage', [
            'order_id' => $orderNumber ?: null,
            'date' => $date,
            'notes' => $notes ?: null,
            'damage_tagged' => $damaged ? 1 : 0,
            'products' => json_encode($clean),
        ], $id);
        $pdo->commit();
        log_activity('Returns', "Return {$record['return_id']} updated");

        returnDamageResponse([
            'success' => true,
            'message' => "Return {$record['return_id']} updated" . ($restock ? '. Stock corrected.' : '.'),
            'returns' => returns_all()
        ]);
    }

    if ($action === 'update_status') {

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if (!in_array($newStatus, ['Return Approved', 'Return Rejected'], true)) {
            returnDamageResponse(['success' => false, 'message' => 'Invalid status.'], 422);
        }

        $record = db_one('SELECT * FROM returns_damage WHERE id = ?', [$id]);

        if (!$record) {
            returnDamageResponse(['success' => false, 'message' => 'Return not found.'], 404);
        }

        /* Only pending requests can be processed. */
        if ($record['status'] !== 'Return Requested') {
            returnDamageResponse(['success' => false, 'message' => 'This return has already been processed.'], 409);
        }

        $pdo = db();
        $pdo->beginTransaction();

        $stockAdjusted = 0;
        if ($newStatus === 'Return Approved') {
            // Good items go back to stock. Damaged items are NOT re-stocked.
            foreach (json_decode($record['products'] ?? '[]', true) ?: [] as $p) {
                if (($p['reason'] ?? '') !== 'damaged') {
                    db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [(float)$p['quantity'], (int)$p['product_id']]);
                }
            }
            $stockAdjusted = 1;
        }

        db_update('returns_damage', ['status' => $newStatus, 'stock_adjusted' => $stockAdjusted], $id);
        $pdo->commit();

        log_activity('Returns', "Return {$record['return_id']} " . ($newStatus === 'Return Approved' ? 'approved' : 'rejected'));

        returnDamageResponse([
            'success' => true,
            'message' => $newStatus === 'Return Approved'
                ? 'Return approved. Stock updated (damaged items not re-stocked).'
                : 'Return rejected successfully.',
            'returns' => returns_all()
        ]);
    }

    returnDamageResponse(['success' => false, 'message' => 'Invalid action.'], 400);
}

$returns = returns_all();
$returnProducts = db_all('SELECT id, name, thickness, size FROM products ORDER BY name');
$returnOrders = array_column(db_all('SELECT order_number FROM orders ORDER BY id DESC LIMIT 500'), 'order_number');

$pageTitle = 'Returns & Damage';
include 'header.php';
?>


<!-- ======================================
     RETURNS & DAMAGE MAIN SECTION
====================================== -->

<section class="return-damage">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-3">

        <div class="col-md-7">

            <h1 class="rd-title">
                Returns &amp; Damage
            </h1>

            <p class="rd-subtitle">
                Track order returns and damage-related
                requests in one place
            </p>

        </div>

        <div class="col-md-5">

            <div class="rd-header-actions">

                <!-- NEW RETURN -->
                <button type="button"
                        class="rd-refresh-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#newReturnModal">

                    <i class="bi bi-plus-lg"></i>

                    New Return

                </button>

                <!-- EXPORT EXCEL -->
                <button type="button"
                        class="rd-export-btn"
                        id="exportReturnsBtn">

                    <i class="bi bi-file-earmark-excel"></i>

                    Export Excel

                </button>

                <!-- REFRESH -->
                <button type="button"
                        class="rd-refresh-btn"
                        id="refreshReturnsBtn">

                    <i class="bi bi-arrow-repeat"></i>

                    Refresh

                </button>

            </div>

        </div>

    </div>

    <!-- ==================================
         STATISTICS CARDS
    ================================== -->

    <div class="row g-3 mb-3">

        <!-- TOTAL RETURNS -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="rd-stat-card">

                <div class="rd-stat-icon rd-blue">
                    <i class="bi bi-arrow-left-right"></i>
                </div>

                <div>

                    <div class="rd-stat-label">
                        Total Returns
                    </div>

                    <div class="rd-stat-value"
                         id="totalReturns">
                        1
                    </div>

                    <div class="rd-stat-note">
                        All order return requests
                    </div>

                </div>

            </div>

        </div>

        <!-- PENDING -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="rd-stat-card">

                <div class="rd-stat-icon rd-orange">
                    <i class="bi bi-clock"></i>
                </div>

                <div>

                    <div class="rd-stat-label">
                        Pending
                    </div>

                    <div class="rd-stat-value"
                         id="pendingReturns">
                        0
                    </div>

                    <div class="rd-stat-note">
                        Waiting for review
                    </div>

                </div>

            </div>

        </div>

        <!-- APPROVED -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="rd-stat-card">

                <div class="rd-stat-icon rd-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>

                    <div class="rd-stat-label">
                        Approved
                    </div>

                    <div class="rd-stat-value"
                         id="approvedReturns">
                        1
                    </div>

                    <div class="rd-stat-note">
                        Stock already adjusted
                    </div>

                </div>

            </div>

        </div>

        <!-- DAMAGE TAGGED -->
        <div class="col-12 col-sm-6 col-xl-3">

            <div class="rd-stat-card">

                <div class="rd-stat-icon rd-red">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <div>

                    <div class="rd-stat-label">
                        Damage Tagged
                    </div>

                    <div class="rd-stat-value"
                         id="damageReturns">
                        0
                    </div>

                    <div class="rd-stat-note">
                        Detected from return reasons
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ==================================
         MAIN CONTENT
    ================================== -->

    <div class="row g-3 align-items-stretch">

        <!-- LEFT SIDE RETURNS LIST -->
        <div class="col-12 col-xl-9">

            <div class="rd-panel rd-list-panel">

                <!-- RETURN TABS -->
                <div class="rd-tabs">

                    <button type="button"
                            class="rd-tab active"
                            data-tab="all">
                        All
                    </button>

                    <button type="button"
                            class="rd-tab"
                            data-tab="Return Requested">
                        Return Requested
                    </button>

                    <button type="button"
                            class="rd-tab"
                            data-tab="Return Approved">
                        Return Approved
                    </button>

                    <button type="button"
                            class="rd-tab"
                            data-tab="Return Rejected">
                        Return Rejected
                    </button>

                    <button type="button"
                            class="rd-tab"
                            data-tab="Damage">
                        Damage
                    </button>

                </div>

                <!-- SEARCH TOOLBAR -->
                <div class="rd-toolbar">

                    <div class="rd-search">

                        <i class="bi bi-search"></i>

                        <input type="search"
                               id="returnSearch"
                               placeholder="Search return, order, notes...">

                    </div>

                    <button type="button"
                            class="rd-clear-btn"
                            id="clearReturnSearch">

                        <i class="bi bi-funnel-fill me-2"></i>

                        Clear

                    </button>

                </div>

                <!-- RETURNS TABLE -->
                <div class="table-responsive">

                    <table class="rd-table">

                        <thead>

                            <tr>

                                <th class="text-center">
                                    S No
                                </th>

                                <th>
                                    Return ID
                                </th>

                                <th>
                                    Order
                                </th>

                                <th>
                                    Date
                                </th>

                                <th class="text-center">
                                    Products
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-center">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody id="returnsTableBody">

                            <!-- JAVASCRIPT ROWS -->

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <!-- ==================================
             RIGHT SIDE DETAILS
        ================================== -->

        <div class="col-12 col-xl-3">

            <div class="rd-panel rd-details-panel">

                <h2 class="rd-details-title">
                    Return / Damage Details
                </h2>

                <div id="returnDetailsContent">

                    <!-- JAVASCRIPT DETAILS -->

                </div>

            </div>

        </div>

    </div>

    <!-- NOTIFICATION -->
    <div id="returnMessage"
         class="alert d-none mt-3"
         role="status">
    </div>

</div>

</section>

<!-- NEW RETURN MODAL -->
<div class="modal fade" id="newReturnModal" tabindex="-1" aria-labelledby="newReturnTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="newReturnForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="newReturnTitle">New Return / Damage Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Order Number</label>
                        <input class="form-control" id="newReturnOrder" list="returnOrderList" placeholder="ORD-0001 (optional)" maxlength="30">
                        <datalist id="returnOrderList">
                            <?php foreach ($returnOrders as $o): ?>
                                <option value="<?php echo e($o); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Return Date *</label>
                        <input type="date" class="form-control" id="newReturnDate" required value="<?php echo e(date('Y-m-d')); ?>">
                    </div>
                </div>

                <label class="form-label fw-semibold">Products *</label>
                <div id="newReturnItems" class="d-grid gap-2 mb-2"></div>
                <button type="button" class="btn btn-sm btn-light border" id="addReturnItemBtn">
                    <i class="bi bi-plus-lg me-1"></i>Add Product
                </button>

                <div class="mt-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea class="form-control" id="newReturnNotes" rows="3" maxlength="2000"></textarea>
                </div>

                <div class="alert alert-info d-none mt-3 mb-0 small" id="editReturnNote"><i class="bi bi-info-circle"></i> This return is already approved. Saving corrects the stock: old good items are taken back out and the new ones are put back in.</div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="newReturnError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveReturnBtn">Save Return</button>
            </div>
        </form>
    </div>
</div>

<template id="returnItemTemplate">
    <div class="row g-2 align-items-center return-item-row">
        <div class="col-12 col-md-6">
            <select class="form-select return-product" required>
                <option value="">Select product</option>
                <?php foreach ($returnProducts as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>">
                        <?php echo e(trim($p['name'] . ' ' . ($p['thickness'] ?? '') . ' ' . ($p['size'] ?? ''))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-4 col-md-2">
            <input type="number" class="form-control return-qty" min="0.01" step="0.01" value="1" required>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select return-reason">
                <?php foreach ($returnReasons as $r): ?>
                    <option value="<?php echo e($r); ?>"><?php echo e(ucfirst($r)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-2 col-md-1 text-end">
            <button type="button" class="btn btn-outline-danger btn-sm return-remove" title="Remove"><i class="bi bi-trash3"></i></button>
        </div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
(function () {
    "use strict";

    /* PHP DATA */

    let returns = <?php echo json_encode(
        $returns,
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

    let selectedReturnId =
        returns.length ? Number(returns[0].id) : null;

    /* SAFE HTML */

    function escapeHTML(value) {

        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    /* FORMAT DATE */

    function formatDate(value) {

        if (!value) return "-";

        const [year, month, day] =
            value.split("-").map(Number);

        return new Date(
            year,
            month - 1,
            day
        ).toLocaleDateString("en-GB", {
            day: "2-digit",
            month: "short",
            year: "numeric"
        });
    }

    /* DAMAGE CHECK */

    function isDamageTagged(record) {

        return record.damage_tagged === true;
    }

    /* STATUS CLASS */

    function getStatusClass(status) {

        if (status === "Return Approved") {
            return "approved";
        }

        if (status === "Return Rejected") {
            return "rejected";
        }

        return "pending";
    }

    /* UPDATE STATISTICS */

    function updateStatistics() {

        const total = returns.length;

        const pending = returns.filter(
            record => record.status === "Return Requested"
        ).length;

        const approved = returns.filter(
            record => record.status === "Return Approved"
        ).length;

        const damaged = returns.filter(
            record => isDamageTagged(record)
        ).length;

        $("totalReturns").textContent = total;

        $("pendingReturns").textContent = pending;

        $("approvedReturns").textContent = approved;

        $("damageReturns").textContent = damaged;
    }

    /* FILTER RETURN RECORDS */

    function getFilteredReturns() {

        const search = $("returnSearch")
            .value.toLowerCase().trim();

        return returns.filter(record => {

            if (activeTab === "Damage") {

                if (!isDamageTagged(record)) {
                    return false;
                }

            } else if (
                activeTab !== "all" &&
                record.status !== activeTab
            ) {

                return false;
            }

            const productText = (
                record.products || []
            ).map(product => {
                return [
                    product.name,
                    product.reason,
                    product.quantity
                ].join(" ");
            }).join(" ");

            const searchable = [
                record.return_id,
                record.order_id,
                record.notes,
                record.status,
                productText
            ].join(" ").toLowerCase();

            return searchable.includes(search);
        });
    }

    /* RENDER RETURNS TABLE */

    function renderReturns() {

        updateStatistics();

        const filtered = getFilteredReturns();

        const tbody = $("returnsTableBody");

        tbody.innerHTML = "";

        if (!filtered.length) {

            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="rd-empty">
                        No return or damage records found.
                    </td>
                </tr>
            `;

            renderSelectedDetails();

            return;
        }

        filtered.forEach((record, index) => {

            const tr = document.createElement("tr");

            const isSelected =
                Number(record.id) === selectedReturnId;

            if (isSelected) {
                tr.classList.add("active");
            }

            tr.dataset.id = record.id;
            tr.tabIndex = 0;
            tr.setAttribute("role", "button");
            tr.setAttribute(
                "aria-label",
                "View " + record.return_id
            );

            tr.innerHTML = `

                <td class="text-center">
                    ${index + 1}
                </td>

                <td>

                    <div class="rd-return-id">
                        ${escapeHTML(record.return_id)}
                    </div>

                    <div class="rd-return-description">
                        Order return request
                    </div>

                </td>

                <td>
                    ${escapeHTML(record.order_id)}
                </td>

                <td>
                    ${escapeHTML(formatDate(record.date))}
                </td>

                <td class="text-center">
                    ${(record.products || []).length}
                </td>

                <td>

                    <span class="rd-status ${getStatusClass(record.status)}">
                        ${escapeHTML(record.status)}
                    </span>

                </td>

                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-light border" data-edit-return="${record.id}" title="Edit Return">
                        <i class="bi bi-pencil-square text-primary"></i>
                    </button>
                </td>
            `;

            tbody.appendChild(tr);
        });

        renderSelectedDetails();
    }

    /* RENDER SELECTED RETURN DETAILS */

    function renderSelectedDetails() {

        const filtered = getFilteredReturns();

        const record = filtered.find(
            item => Number(item.id) === selectedReturnId
        );

        const container = $("returnDetailsContent");

        if (!record) {

            container.innerHTML = `
                <div class="rd-no-selection">

                    <i class="bi bi-inbox"></i>

                    Select a return request to view details.

                </div>
            `;

            return;
        }

        const productsHTML = (record.products || [])
            .map(product => `

                <div class="rd-product-item">

                    <div class="rd-product-name">
                        ${escapeHTML(product.name)}
                    </div>

                    <div class="rd-product-meta">

                        <div>
                            Product:
                            ${escapeHTML(product.name)}
                        </div>

                        <div>
                            Qty:
                            ${escapeHTML(product.quantity)}
                        </div>

                        <div>
                            Reason:
                            ${escapeHTML(product.reason)}
                        </div>

                    </div>

                </div>
            `).join("");

        const isPending =
            record.status === "Return Requested";

        container.innerHTML = `

            <!-- RETURN HEADER -->
            <div class="rd-details-header">

                <h3 class="rd-details-id">
                    ${escapeHTML(record.return_id)}
                </h3>

                <span class="rd-status ${getStatusClass(record.status)}">
                    ${escapeHTML(record.status)}
                </span>

            </div>

            <div class="rd-details-created">
                Created on ${escapeHTML(formatDate(record.date))}
            </div>

            <!-- LINKED ORDER -->
            <div class="rd-detail-label">
                Linked Order
            </div>

            <div class="rd-detail-value">
                ${escapeHTML(record.order_id)}
            </div>

            <!-- NOTES -->
            <div class="rd-detail-label">
                Notes
            </div>

            <div class="rd-detail-value">
                ${escapeHTML(record.notes || "No notes added.")}
            </div>

            <!-- PRODUCTS -->
            <div class="rd-detail-label">
                Products
            </div>

            <div class="rd-product-list">
                ${productsHTML}
            </div>

            <button type="button" class="btn btn-outline-primary w-100 mb-2 fw-semibold" data-edit-return="${record.id}" title="Edit Return">
                <i class="bi bi-pencil-square me-1"></i> Edit Return
            </button>

            <!-- ACTION BUTTONS -->
            <button type="button"
                    class="rd-approve-btn"
                    data-return-action="approve"
                    data-id="${record.id}"
                    ${isPending ? "" : "disabled"}>

                Approve Return

            </button>

            <button type="button"
                    class="rd-reject-btn"
                    data-return-action="reject"
                    data-id="${record.id}"
                    ${isPending ? "" : "disabled"}>

                Reject Return

            </button>

            ${record.status === "Return Approved"
                ? `<p class="text-secondary small mt-3 mb-0">
                    ${record.stock_adjusted
                        ? "Stock adjustment recorded."
                        : "Stock adjustment pending."}
                   </p>`
                : ""}
        `;
    }

    /* SELECT A RETURN */

    function selectReturn(id) {

        selectedReturnId = id;

        renderReturns();
    }

    /* TABLE SELECTION EVENTS */

    $("returnsTableBody").addEventListener(
        "click",
        function(event) {

            const row = event.target.closest("tr[data-id]");

            if (!row) return;

            selectReturn(Number(row.dataset.id));
        }
    );

    $("returnsTableBody").addEventListener(
        "keydown",
        function(event) {

            if (
                event.key !== "Enter" &&
                event.key !== " "
            ) {
                return;
            }

            const row = event.target.closest("tr[data-id]");

            if (!row) return;

            event.preventDefault();

            selectReturn(Number(row.dataset.id));
        }
    );

    /* NOTIFICATION */

    function showMessage(message, type = "success") {

        const box = $("returnMessage");

        box.className =
            "alert alert-" + type + " mt-3";

        box.textContent = message;

        box.classList.remove("d-none");
    }

    /* PHP AJAX REQUEST */

    async function sendRequest(action, data = {}) {

        const formData = new FormData();

        formData.append("action", action);

        formData.append(
            "csrf_token",
            csrfToken
        );

        Object.entries(data).forEach(
            ([key, value]) => {
                formData.append(key, String(value));
            }
        );

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
                result.message || "Request failed."
            );
        }

        return result;
    }

    /* APPROVE / REJECT RETURN */

    $("returnDetailsContent").addEventListener(
        "click",
        async function(event) {

            const button = event.target.closest(
                "[data-return-action]"
            );

            if (!button || button.disabled) return;

            const id = Number(button.dataset.id);

            const action = button.dataset.returnAction;

            const newStatus = action === "approve"
                ? "Return Approved"
                : "Return Rejected";

            const confirmed = confirm(
                "Are you sure you want to " +
                action +
                " this return request?"
            );

            if (!confirmed) return;

            button.disabled = true;

            try {

                const result = await sendRequest(
                    "update_status",
                    {
                        id: id,
                        status: newStatus
                    }
                );

                returns = result.returns;

                renderReturns();

                showMessage(result.message);

            } catch(error) {

                showMessage(
                    error.message,
                    "danger"
                );

                button.disabled = false;
            }
        }
    );

    /* STATUS TABS */

    document.querySelectorAll(
        ".return-damage .rd-tab"
    ).forEach(tab => {

        tab.addEventListener(
            "click",
            function() {

                document.querySelectorAll(
                    ".return-damage .rd-tab"
                ).forEach(item => {

                    item.classList.remove("active");
                });

                this.classList.add("active");

                activeTab = this.dataset.tab;

                const filtered = getFilteredReturns();

                if (!filtered.some(
                    item => Number(item.id) === selectedReturnId
                )) {

                    selectedReturnId = filtered.length
                        ? Number(filtered[0].id)
                        : null;
                }

                renderReturns();
            }
        );
    });

    /* SEARCH */

    $("returnSearch").addEventListener(
        "input",
        function() {

            const filtered = getFilteredReturns();

            if (!filtered.some(
                item => Number(item.id) === selectedReturnId
            )) {

                selectedReturnId = filtered.length
                    ? Number(filtered[0].id)
                    : null;
            }

            renderReturns();
        }
    );

    /* CLEAR FILTER */

    $("clearReturnSearch").addEventListener(
        "click",
        function() {

            $("returnSearch").value = "";

            activeTab = "all";

            document.querySelectorAll(
                ".return-damage .rd-tab"
            ).forEach(tab => {

                tab.classList.toggle(
                    "active",
                    tab.dataset.tab === "all"
                );
            });

            selectedReturnId = returns.length
                ? Number(returns[0].id)
                : null;

            renderReturns();
        }
    );

    /* REFRESH */

    $("refreshReturnsBtn").addEventListener(
        "click",
        async function() {

            const button = this;

            button.disabled = true;

            try {

                const result = await sendRequest(
                    "refresh"
                );

                returns = result.returns;

                renderReturns();

                showMessage(
                    "Returns refreshed successfully."
                );

            } catch(error) {

                showMessage(
                    error.message,
                    "danger"
                );

            } finally {

                button.disabled = false;
            }
        }
    );

    /* EXCEL EXPORT */

    $("exportReturnsBtn").addEventListener(
        "click",
        function() {

            if (typeof XLSX === "undefined") {

                showMessage(
                    "Excel export library is unavailable.",
                    "danger"
                );

                return;
            }

            const filtered = getFilteredReturns();

            if (!filtered.length) {

                showMessage(
                    "No return records to export.",
                    "warning"
                );

                return;
            }

            const rows = [];

            filtered.forEach((record, index) => {

                (record.products || []).forEach(product => {

                    rows.push({

                        "S No": index + 1,

                        "Return ID": record.return_id,

                        "Order": record.order_id,

                        "Date": record.date,

                        "Product": product.name,

                        "Quantity": product.quantity,

                        "Reason": product.reason,

                        "Status": record.status,

                        "Damage Tagged":
                            record.damage_tagged ? "Yes" : "No",

                        "Stock Adjusted":
                            record.stock_adjusted ? "Yes" : "No",

                        "Notes": record.notes || ""

                    });
                });
            });

            const worksheet =
                XLSX.utils.json_to_sheet(rows);

            worksheet["!cols"] = [

                { wch: 9 },
                { wch: 17 },
                { wch: 17 },
                { wch: 15 },
                { wch: 32 },
                { wch: 13 },
                { wch: 24 },
                { wch: 20 },
                { wch: 17 },
                { wch: 18 },
                { wch: 35 }

            ];

            const workbook =
                XLSX.utils.book_new();

            XLSX.utils.book_append_sheet(
                workbook,
                worksheet,
                "Returns Damage"
            );

            XLSX.writeFile(
                workbook,
                "Returns_Damage_Report.xlsx"
            );
        }
    );

    /* NEW RETURN REQUEST */

    const itemsBox = $("newReturnItems");

    function addReturnItem() {
        itemsBox.appendChild(
            $("returnItemTemplate").content.firstElementChild.cloneNode(true)
        );
    }

    $("addReturnItemBtn").addEventListener("click", addReturnItem);

    itemsBox.addEventListener("click", function (event) {
        const remove = event.target.closest(".return-remove");
        if (remove && itemsBox.children.length > 1) {
            remove.closest(".return-item-row").remove();
        }
    });

    // null = new return; a record = editing that return.
    let editingReturn = null;

    function addReturnItemWith(p) {
        addReturnItem();
        const row = itemsBox.lastElementChild;
        const sel = row.querySelector(".return-product");
        // Keep a product that is no longer in the list selectable.
        if (p.product_id && ![...sel.options].some(o => Number(o.value) === Number(p.product_id))) {
            sel.add(new Option(p.name || ("Product #" + p.product_id), p.product_id));
        }
        sel.value = String(p.product_id || "");
        row.querySelector(".return-qty").value = p.quantity;
        row.querySelector(".return-reason").value = p.reason || "other";
    }

    function openEditReturn(id) {
        editingReturn = returns.find(r => Number(r.id) === Number(id)) || null;
        if (!editingReturn) return;
        bootstrap.Modal.getOrCreateInstance($("newReturnModal")).show();
    }

    // Edit buttons (table row + details panel).
    document.addEventListener("click", event => {
        const btn = event.target.closest("[data-edit-return]");
        if (!btn) return;
        event.stopPropagation();
        openEditReturn(btn.dataset.editReturn);
    }, true);

    $("newReturnModal").addEventListener("hidden.bs.modal", () => { editingReturn = null; });

    $("newReturnModal").addEventListener("show.bs.modal", function () {
        $("newReturnForm").reset();
        itemsBox.innerHTML = "";
        $("newReturnError").classList.add("d-none");
        const r = editingReturn;
        $("newReturnTitle").textContent = r ? "Edit Return " + r.return_id : "New Return / Damage Request";
        $("saveReturnBtn").textContent = r ? "Update Return" : "Save Return";
        $("editReturnNote").classList.toggle("d-none", !(r && r.status === "Return Approved" && r.stock_adjusted));
        if (r) {
            $("newReturnOrder").value = r.order_id || "";
            $("newReturnDate").value = r.date || "";
            $("newReturnNotes").value = r.notes || "";
            (r.products || []).forEach(addReturnItemWith);
        }
        if (!itemsBox.children.length) addReturnItem();
    });

    $("newReturnForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        if (!this.reportValidity()) return;

        const products = [...itemsBox.querySelectorAll(".return-item-row")].map(row => ({
            product_id: Number(row.querySelector(".return-product").value),
            quantity: Number(row.querySelector(".return-qty").value),
            reason: row.querySelector(".return-reason").value
        }));

        const saveBtn = $("saveReturnBtn");
        saveBtn.disabled = true;

        try {
            const result = await sendRequest(editingReturn ? "update_return" : "create_return", {
                id: editingReturn ? editingReturn.id : "",
                order_id: $("newReturnOrder").value.trim(),
                date: $("newReturnDate").value,
                notes: $("newReturnNotes").value.trim(),
                products: JSON.stringify(products)
            });

            returns = result.returns;
            selectedReturnId = editingReturn ? Number(editingReturn.id) : (returns.length ? Number(returns[0].id) : null);
            bootstrap.Modal.getOrCreateInstance($("newReturnModal")).hide();
            renderReturns();
            showMessage(result.message);
        } catch (error) {
            $("newReturnError").textContent = error.message;
            $("newReturnError").classList.remove("d-none");
        } finally {
            saveBtn.disabled = false;
        }
    });

    /* INITIAL PAGE LOAD */

    renderReturns();

})();
</script>





<?php include 'footer.php'; ?>