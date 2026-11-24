<?php
/*
|--------------------------------------------------------------------------
| ORDERS LIST - data from MySQL `orders` + `order_items`
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$orderStatuses = ['Pending', 'Invoice Raised', 'Processing', 'Delivered', 'Cancelled'];

function orders_all(): array
{
    return cast_rows(
        db_all('SELECT o.id, o.order_number, o.date, o.delivery_date, o.customer, o.salesman, o.reference,
                       o.region, o.subtotal, o.discount, o.tax, o.amount, o.status,
                       i.invoice_number, i.status AS invoice_status, o.invoice_id,
                       i.eway_bill, i.dispatched_through, i.vehicle_no
                FROM orders o LEFT JOIN invoices i ON i.id = o.invoice_id
                ORDER BY o.id DESC'),
        [],
        ['subtotal', 'discount', 'tax', 'amount']
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $order = db_one('SELECT * FROM orders WHERE id = ?', [$id]);

    if (!$order) {
        json_response(['success' => false, 'message' => 'Order not found.'], 404);
    }

    if ($action === 'items') {
        json_response(['success' => true, 'items' => cast_rows(
            db_all('SELECT name, thickness, size, hsn, quantity, rate, tax, discount, amount FROM order_items WHERE order_id = ?', [$id]),
            [], ['quantity', 'rate', 'tax', 'discount', 'amount']
        )]);
    }

    if ($action === 'status') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $orderStatuses, true)) {
            json_response(['success' => false, 'message' => 'Invalid status.'], 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        // Cancelling returns the stock and cancels the invoice.
        if ($status === 'Cancelled' && $order['status'] !== 'Cancelled') {
            foreach (db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$id]) as $it) {
                db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
            }
            if ($order['invoice_id']) {
                db_exec("UPDATE invoices SET status = 'Cancelled' WHERE id = ?", [$order['invoice_id']]);
            }
        }
        db_update('orders', ['status' => $status], $id);
        $pdo->commit();
        log_activity('Orders', "Order {$order['order_number']} marked as $status");
        json_response(['success' => true, 'orders' => orders_all()]);
    }

    if ($action === 'delete') {
        $pdo = db();
        $pdo->beginTransaction();
        if ($order['status'] !== 'Cancelled') {
            foreach (db_all('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$id]) as $it) {
                db_exec('UPDATE products SET stock = stock + ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
            }
        }
        if ($order['invoice_id']) {
            $inv = db_value('SELECT invoice_number FROM invoices WHERE id = ?', [$order['invoice_id']]);
            if ($inv && db_value('SELECT COUNT(*) FROM payments WHERE invoice = ?', [$inv])) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'This order has payments recorded. Cancel it instead of deleting.'], 409);
            }
            db_exec('DELETE FROM invoices WHERE id = ?', [$order['invoice_id']]);
        }
        db_exec('DELETE FROM orders WHERE id = ?', [$id]);
        $pdo->commit();
        log_activity('Orders', "Order {$order['order_number']} deleted");
        json_response(['success' => true, 'orders' => orders_all()]);
    }

    /* ---- Add to Invoice: bill this order (stock was already taken when the order was saved) ---- */
    if ($action === 'to_invoice') {
        if (!user_can('invoices', 'add') && !user_can('direct_invoice', 'add')) {
            json_response(['success' => false, 'message' => 'You do not have permission to create invoices.'], 403);
        }
        if ($order['status'] === 'Cancelled') {
            json_response(['success' => false, 'message' => 'This order is cancelled. Open it again before invoicing.'], 422);
        }
        $live = $order['invoice_id']
            ? db_one("SELECT invoice_number FROM invoices WHERE id = ? AND status <> 'Cancelled'", [(int)$order['invoice_id']])
            : null;
        if ($live) {
            json_response(['success' => false, 'message' => 'This order is already in invoice ' . $live['invoice_number'] . '.'], 409);
        }
        if (!db_value('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$id])) {
            json_response(['success' => false, 'message' => 'This order has no products.'], 422);
        }
        $invDate = (string)($_POST['invoice_date'] ?? '');
        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $invDate)) {
            $invDate = date('Y-m-d');
        }
        $due = $order['delivery_date'] && $order['delivery_date'] >= $invDate
            ? $order['delivery_date'] : date('Y-m-d', strtotime($invDate . ' +30 days'));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $invoiceNumber = next_number('invoices', 'invoice_number', 'SI-');
            $invoiceId = db_insert('invoices', [
                'invoice_number' => $invoiceNumber,
                'order_id' => $id,
                'customer_id' => $order['customer_id'],
                'customer' => $order['customer'],
                'date' => $invDate,
                'due_date' => $due,
                'region' => $order['region'],
                'subtotal' => $order['subtotal'],
                'discount' => $order['discount'],
                'tax' => $order['tax'],
                'amount' => $order['amount'],
                'status' => 'Submitted',
                'eway_bill' => mb_substr(trim((string)($_POST['eway_bill'] ?? '')), 0, 50) ?: null,
                'dispatched_through' => mb_substr(trim((string)($_POST['dispatched_through'] ?? '')), 0, 150) ?: null,
                'vehicle_no' => mb_substr(trim((string)($_POST['vehicle_no'] ?? '')), 0, 50) ?: null,
            ]);
            db_update('orders', ['invoice_id' => $invoiceId, 'status' => 'Invoice Raised'], $id);
            refresh_invoice_status($invoiceNumber);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => 'Could not create the invoice. Please try again.'], 500);
        }
        log_activity('Invoices', "Order {$order['order_number']} added to Invoice $invoiceNumber");
        json_response(['success' => true, 'orders' => orders_all(), 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber]);
    }

    /* ---- Convert to Quotation: copy this order into a new Draft quotation ---- */
    if ($action === 'to_quotation') {
        if (!user_can('quotations', 'add')) {
            json_response(['success' => false, 'message' => 'You do not have permission to create quotations.'], 403);
        }
        $items = db_all('SELECT product_id, name, thickness, size, hsn, quantity, rate, tax, discount, amount
                         FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
        if (!$items) {
            json_response(['success' => false, 'message' => 'This order has no products.'], 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $quotationNumber = next_number('quotations', 'quotation_id', 'QUOT-');
        $quotationId = db_insert('quotations', [
            'quotation_id' => $quotationNumber,
            'is_master' => 0,
            'date' => date('Y-m-d'),
            'valid_upto' => date('Y-m-d', strtotime('+30 days')),
            'customer_id' => $order['customer_id'],
            'customer' => $order['customer'],
            'salesman' => $order['salesman'],
            'store' => $order['store'] ?: APP_STORE,
            'reference' => $order['order_number'],
            'region' => $order['region'],
            'notes' => $order['notes'],
            'subtotal' => $order['subtotal'],
            'discount' => $order['discount'],
            'tax' => $order['tax'],
            'amount' => $order['amount'],
            'status' => 'Draft',
            'created_by' => (int)$_SESSION['user_id'],
        ]);
        foreach ($items as $it) {
            db_insert('quotation_items', ['quotation_id' => $quotationId] + $it);
        }
        $pdo->commit();
        log_activity('Quotations', "Order {$order['order_number']} converted to Quotation $quotationNumber");
        json_response(['success' => true, 'quotation_id' => $quotationId, 'quotation_number' => $quotationNumber]);
    }

    json_response(['success' => false, 'message' => 'Invalid action.'], 400);
}

$orders = orders_all();
$pageTitle = 'Orders';
include 'header.php';
?>

<section class="app-page">
<div class="container-fluid p-0">

    <div class="row align-items-center g-3 mb-4">
        <div class="col-md-7">
            <h1 class="app-title">Orders</h1>
            <p class="app-subtitle">Sales orders - add them to an invoice or convert them to a quotation</p>
        </div>
        <div class="col-md-5 text-md-end">
            <a href="add-order.php" class="btn-app d-inline-block text-decoration-none">
                <i class="bi bi-plus-lg me-1"></i> New Order
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">
            Order <?php echo e($_GET['saved']); ?> saved. Use <strong>Add to Invoice</strong> to bill it, or <strong>Convert to Quotation</strong>.
        </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#e0edff;color:#0b5ed7"><i class="bi bi-cart-check"></i></div>
                <div><div class="app-stat-label">Total Orders</div><div class="app-stat-value" id="statTotal">0</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#dcfce7;color:#15803d"><i class="bi bi-cash-stack"></i></div>
                <div><div class="app-stat-label">Order Value</div><div class="app-stat-value" id="statValue">Rs 0</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#ffedd5;color:#c2410c"><i class="bi bi-calendar-day"></i></div>
                <div><div class="app-stat-label">Today's Orders</div><div class="app-stat-value" id="statToday">0</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-card app-stat">
                <div class="app-stat-icon" style="background:#fee2e2;color:#b91c1c"><i class="bi bi-x-circle"></i></div>
                <div><div class="app-stat-label">Cancelled</div><div class="app-stat-value" id="statCancelled">0</div></div>
            </div>
        </div>
    </div>

    <div class="app-card">
        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="search" class="form-control" id="orderSearch" placeholder="Search order, customer, invoice...">
            </div>
            <div class="col-6 col-md-3">
                <select class="form-select" id="orderStatusFilter">
                    <option value="all">All Status</option>
                    <?php foreach ($orderStatuses as $s): ?>
                        <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" class="form-control" id="orderFrom" title="From date">
            </div>
            <div class="col-12 col-md-2">
                <input type="date" class="form-control" id="orderTo" title="To date">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Order No</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Invoice</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="orderTableBody"></tbody>
            </table>
        </div>
    </div>

</div>
</section>

<!-- VIEW ORDER MODAL -->
<div class="modal fade" id="viewOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewOrderTitle">Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewOrderBody"></div>
        </div>
    </div>
</div>

<!-- ADD TO INVOICE MODAL -->
<div class="modal fade" id="orderInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="orderInvoiceForm" autocomplete="off">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="bi bi-receipt text-success me-1"></i> Add to Invoice</h5>
                    <div class="small text-muted" id="oiSummary"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="oiOrderId">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="oiDate">Invoice Date</label>
                    <input type="date" class="form-control" id="oiDate" required>
                </div>
                <div class="oi-dispatch">
                    <div class="oi-dispatch-title"><i class="bi bi-truck"></i> Dispatch Details</div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="oiEway">Eway Bill Number</label>
                        <input type="text" class="form-control" id="oiEway" maxlength="50" placeholder="Enter eway bill number">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="oiDispatch">Dispatched Through</label>
                        <input type="text" class="form-control" id="oiDispatch" maxlength="150" placeholder="Transport / courier name">
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="oiVehicle">Vehicle Number</label>
                        <input type="text" class="form-control" id="oiVehicle" maxlength="50" placeholder="e.g. AP 39 AB 1234" style="text-transform:uppercase">
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle"></i> Stock was already taken when the order was saved, so it does not change again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="oiSubmit"><i class="bi bi-receipt"></i> Create Invoice</button>
            </div>
        </form>
    </div>
</div>
<style>
    .oi-dispatch{border:1px solid #d7e6fb;background:#f6faff;border-radius:12px;padding:14px}
    .oi-dispatch-title{font-weight:700;color:#1d4f91;margin-bottom:10px;font-size:14px}
    .ord-btn{display:inline-flex;align-items:center;gap:4px;font-size:12.5px;font-weight:600;white-space:nowrap}
</style>

<script>
(function () {
    "use strict";

    let orders = <?php echo json_encode($orders, json_flags()); ?>;
    const statuses = <?php echo json_encode($orderStatuses); ?>;
    const today = <?php echo json_encode(date('Y-m-d')); ?>;
    const $ = id => document.getElementById(id);
    const viewModal = new bootstrap.Modal($("viewOrderModal"));

    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
    const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
    const fdate = v => {
        if (!v) return "-";
        const [y, m, d] = v.split("-").map(Number);
        return new Date(y, m - 1, d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
    };
    const badge = s => ({ "Delivered": "green", "Processing": "orange", "Cancelled": "red", "Pending": "orange" }[s] || "");
    // An order can be invoiced when it is not cancelled and has no live invoice.
    const canInvoice = o => o.status !== "Cancelled" && (!o.invoice_number || o.invoice_status === "Cancelled");
    const invModal = new bootstrap.Modal($("orderInvoiceModal"));

    function filtered() {
        const q = $("orderSearch").value.trim().toLowerCase();
        const st = $("orderStatusFilter").value;
        const from = $("orderFrom").value, to = $("orderTo").value;
        return orders.filter(o => {
            if (st !== "all" && o.status !== st) return false;
            if (from && o.date < from) return false;
            if (to && o.date > to) return false;
            return [o.order_number, o.customer, o.invoice_number, o.reference, o.amount].join(" ").toLowerCase().includes(q);
        });
    }

    function render() {
        const active = orders.filter(o => o.status !== "Cancelled");
        $("statTotal").textContent = orders.length;
        $("statValue").textContent = "Rs " + Math.round(active.reduce((s, o) => s + o.amount, 0)).toLocaleString("en-IN");
        $("statToday").textContent = orders.filter(o => o.date === today).length;
        $("statCancelled").textContent = orders.length - active.length;

        const rows = filtered();
        const tbody = $("orderTableBody");
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5">No orders found. Click "New Order" to create one.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((o, i) => `
            <tr>
                <td>${i + 1}</td>
                <td class="fw-bold">${esc(o.order_number)}</td>
                <td>${esc(fdate(o.date))}</td>
                <td>${esc(o.customer)}</td>
                <td>${o.invoice_number && o.invoice_status !== "Cancelled"
                        ? `<a href="invoice-print.php?id=${o.invoice_id}" target="_blank" class="fw-semibold text-decoration-none">${esc(o.invoice_number)}</a><div class="small text-muted">${esc(o.invoice_status || "")}</div>`
                        : '<span class="text-muted small">Not invoiced</span>' + (o.invoice_number ? `<div class="small text-danger">${esc(o.invoice_number)} cancelled</div>` : "")}</td>
                <td class="text-end fw-semibold">${money(o.amount)}</td>
                <td><span class="app-badge ${badge(o.status)}">${esc(o.status)}</span></td>
                <td class="text-center">
                    <div class="d-inline-flex gap-1">
                        ${canInvoice(o) ? `<button class="btn btn-sm btn-success ord-btn" data-action="to_invoice" data-id="${o.id}" title="Add to Invoice"><i class="bi bi-receipt"></i> Add to Invoice</button>` : ""}
                        <button class="btn btn-sm btn-outline-primary ord-btn" data-action="to_quotation" data-id="${o.id}" title="Convert to Quotation"><i class="bi bi-file-earmark-text"></i> To Quotation</button>
                        <button class="btn btn-sm btn-light border" data-action="view" data-id="${o.id}" title="View"><i class="bi bi-eye"></i></button>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border" data-bs-toggle="dropdown" title="More"><i class="bi bi-three-dots-vertical"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                ${statuses.map(s => `<li><button class="dropdown-item" data-action="status" data-status="${s}" data-id="${o.id}">Mark as ${s}</button></li>`).join("")}
                                <li><hr class="dropdown-divider"></li>
                                <li><button class="dropdown-item text-danger" data-action="delete" data-id="${o.id}">Delete</button></li>
                            </ul>
                        </div>
                    </div>
                </td>
            </tr>`).join("");
    }

    async function view(id) {
        const o = orders.find(x => x.id === id);
        if (!o) return;
        const tones = { Delivered: "green", Completed: "green", "Invoice Raised": "blue", Cancelled: "red", Pending: "orange" };

        const body = App.details({
            title: "Order Details",
            icon: "cart-check",
            name: o.order_number,
            subtitle: o.customer || "",
            badge: { text: o.status, tone: tones[o.status] || "blue" },
            rows: [
                ["Customer", o.customer],
                ["Order Date", fdate(o.date)],
                ["Invoice", o.invoice_number && o.invoice_status !== "Cancelled" ? o.invoice_number : "Not invoiced"],
                ...(o.invoice_number && o.invoice_status !== "Cancelled" ? [
                    ["Eway Bill Number", o.eway_bill || "-"],
                    ["Dispatched Through", o.dispatched_through || "-"],
                    ["Vehicle Number", o.vehicle_no || "-"]
                ] : []),
                ["Salesman", o.salesman || "-"],
                ["Region", o.region || "-"],
                ["Reference", o.reference || "-"],
                ["Subtotal", money(o.subtotal)],
                ["Discount", money(o.discount)],
                ["Tax", money(o.tax)],
                ["Grand Total", money(o.amount)]
            ],
            html: '<div class="details-items text-muted">Loading items...</div>',
            actions: [
                ...(canInvoice(o) ? [{ label: "Add to Invoice", icon: "receipt", tone: "primary", onClick: () => openInvoice(id) }] : []),
                ...(o.invoice_id && !canInvoice(o) ? [{ label: "Print Invoice", icon: "printer-fill", tone: "primary", href: "invoice-print.php?id=" + o.invoice_id, newTab: true }] : []),
                { label: "Convert to Quotation", icon: "file-earmark-text", onClick: () => toQuotation(id) },
                { label: "Delete Order", icon: "trash3", tone: "danger", onClick: () => rowAction("delete", id) }
            ]
        });

        try {
            const { items } = await App.post("orders.php", { action: "items", id });
            const box = body.querySelector(".details-items");
            if (!box) return;
            box.classList.remove("text-muted");
            box.innerHTML = items.length ? `
                <table>
                    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>${items.map(it => `<tr>
                        <td>${esc(it.name)}</td>
                        <td class="text-end">${it.quantity}</td>
                        <td class="text-end">${money(it.amount)}</td></tr>`).join("")}</tbody>
                </table>` : "No item lines.";
        } catch (e) {
            const box = body.querySelector(".details-items");
            if (box) box.textContent = e.message;
        }
    }

    // Run a table row action (same confirm + logic as the row buttons).
    function rowAction(action, id) {
        const btn = document.querySelector(`[data-action="${action}"][data-id="${id}"]`);
        if (btn) btn.click();
    }

    $("orderTableBody").addEventListener("click", async e => {
        const btn = e.target.closest("[data-action]");
        if (!btn) return;
        const id = Number(btn.dataset.id);
        const action = btn.dataset.action;
        if (action === "view") return view(id);
        if (action === "to_invoice") return openInvoice(id);
        if (action === "to_quotation") return toQuotation(id);
        if (action === "delete" && !confirm("Delete this order and its invoice? Stock will be returned.")) return;
        if (action === "status" && btn.dataset.status === "Cancelled" && !confirm("Cancel this order? Stock will be returned and the invoice cancelled.")) return;
        try {
            const result = await App.post("orders.php", { action, id, status: btn.dataset.status || "" });
            orders = result.orders;
            render();
            App.toast(action === "delete" ? "Order deleted." : "Order status updated.");
        } catch (err) {
            App.toast(err.message, "error");
        }
    });

    /* ---- Add to Invoice (with Eway Bill / Dispatched Through / Vehicle Number) ---- */
    function openInvoice(id) {
        const o = orders.find(x => x.id === id);
        if (!o) return;
        App.hideDetails?.();
        $("oiOrderId").value = id;
        $("oiSummary").textContent = `${o.order_number} · ${o.customer || ""} · ${money(o.amount)}`;
        $("oiDate").value = today;
        $("oiEway").value = "";
        $("oiDispatch").value = "";
        $("oiVehicle").value = "";
        invModal.show();
        setTimeout(() => $("oiEway").focus(), 300);
    }

    $("orderInvoiceForm").addEventListener("submit", async e => {
        e.preventDefault();
        const btn = $("oiSubmit");
        // Open the print tab now (inside the click) so pop-up blockers allow it.
        const printTab = window.open("about:blank", "_blank");
        btn.disabled = true;
        try {
            const r = await App.post("orders.php", {
                action: "to_invoice",
                id: $("oiOrderId").value,
                invoice_date: $("oiDate").value,
                eway_bill: $("oiEway").value.trim(),
                dispatched_through: $("oiDispatch").value.trim(),
                vehicle_no: $("oiVehicle").value.trim().toUpperCase()
            });
            orders = r.orders;
            render();
            invModal.hide();
            App.toast("Invoice " + r.invoice_number + " created.");
            if (printTab) printTab.location.href = "invoice-print.php?id=" + r.invoice_id;
        } catch (err) {
            if (printTab) printTab.close();
            App.toast(err.message, "error");
        } finally {
            btn.disabled = false;
        }
    });

    /* ---- Convert to Quotation ---- */
    async function toQuotation(id) {
        const o = orders.find(x => x.id === id);
        if (!o || !confirm(`Create a new quotation from ${o.order_number}? The order stays as it is.`)) return;
        try {
            const r = await App.post("orders.php", { action: "to_quotation", id });
            App.toast("Quotation " + r.quotation_number + " created.");
            setTimeout(() => { location.href = "add-quotation.php?edit=" + r.quotation_id; }, 700);
        } catch (err) {
            App.toast(err.message, "error");
        }
    }

    // Arrived from "Save & Add to Invoice" on the order screen.
    const openId = Number(new URLSearchParams(location.search).get("invoice") || 0);
    if (openId) {
        history.replaceState(null, "", location.pathname);
        setTimeout(() => openInvoice(openId), 200);
    }

    ["orderSearch", "orderStatusFilter", "orderFrom", "orderTo"].forEach(id => {
        $(id).addEventListener("input", render);
        $(id).addEventListener("change", render);
    });

    render();
})();
</script>

<?php include 'footer.php'; ?>
