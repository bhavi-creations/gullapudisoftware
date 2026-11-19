<?php
/*
|--------------------------------------------------------------------------
| ORDERS LIST - data from MySQL `orders` + `order_items`
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$orderStatuses = ['Invoice Raised', 'Processing', 'Delivered', 'Cancelled'];

function orders_all(): array
{
    return cast_rows(
        db_all('SELECT o.id, o.order_number, o.date, o.delivery_date, o.customer, o.salesman, o.reference,
                       o.region, o.subtotal, o.discount, o.tax, o.amount, o.status,
                       i.invoice_number, i.status AS invoice_status
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
            <p class="app-subtitle">Sales orders with their invoices</p>
        </div>
        <div class="col-md-5 text-md-end">
            <a href="add-order.php" class="btn-app d-inline-block text-decoration-none">
                <i class="bi bi-plus-lg me-1"></i> New Order
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
        <div class="alert alert-success">
            Order <?php echo e($_GET['saved']); ?> saved and invoice created successfully.
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
    const badge = s => ({ "Delivered": "green", "Processing": "orange", "Cancelled": "red" }[s] || "");

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
                <td>${esc(o.invoice_number || "-")}<div class="small text-muted">${esc(o.invoice_status || "")}</div></td>
                <td class="text-end fw-semibold">${money(o.amount)}</td>
                <td><span class="app-badge ${badge(o.status)}">${esc(o.status)}</span></td>
                <td class="text-center">
                    <div class="d-inline-flex gap-1">
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
        $("viewOrderTitle").textContent = "Order " + o.order_number;
        $("viewOrderBody").innerHTML = '<div class="text-center py-4 text-muted">Loading...</div>';
        viewModal.show();
        try {
            const { items } = await App.post("orders.php", { action: "items", id });
            $("viewOrderBody").innerHTML = `
                <div class="row g-2 mb-3 small">
                    <div class="col-sm-6"><b>Customer:</b> ${esc(o.customer)}</div>
                    <div class="col-sm-6"><b>Date:</b> ${esc(fdate(o.date))}</div>
                    <div class="col-sm-6"><b>Invoice:</b> ${esc(o.invoice_number || "-")}</div>
                    <div class="col-sm-6"><b>Salesman:</b> ${esc(o.salesman || "-")}</div>
                    <div class="col-sm-6"><b>Region:</b> ${esc(o.region || "-")}</div>
                    <div class="col-sm-6"><b>Reference:</b> ${esc(o.reference || "-")}</div>
                </div>
                <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Product</th><th>Qty</th><th>Rate</th><th>Tax%</th><th>Disc%</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>${items.map(it => `<tr><td>${esc(it.name)} <span class="text-muted small">${esc([it.thickness, it.size].filter(Boolean).join(" | "))}</span></td>
                        <td>${it.quantity}</td><td>${money(it.rate)}</td><td>${it.tax}</td><td>${it.discount}</td><td class="text-end">${money(it.amount)}</td></tr>`).join("")}</tbody>
                </table>
                </div>
                <div class="text-end">
                    <div>Subtotal: <b>${money(o.subtotal)}</b></div>
                    <div>Discount: <b>${money(o.discount)}</b></div>
                    <div>Tax: <b>${money(o.tax)}</b></div>
                    <div class="fs-5">Grand Total: <b>${money(o.amount)}</b></div>
                </div>`;
        } catch (e) {
            $("viewOrderBody").innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    $("orderTableBody").addEventListener("click", async e => {
        const btn = e.target.closest("[data-action]");
        if (!btn) return;
        const id = Number(btn.dataset.id);
        const action = btn.dataset.action;
        if (action === "view") return view(id);
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

    ["orderSearch", "orderStatusFilter", "orderFrom", "orderTo"].forEach(id => {
        $(id).addEventListener("input", render);
        $(id).addEventListener("change", render);
    });

    render();
})();
</script>

<?php include 'footer.php'; ?>
