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

/**
 * What can still be returned on an order, per product:
 *   bought   = quantity on the order
 *   returned = quantity on other returns of this order (rejected returns do not count)
 *   left     = bought - returned
 * Returns null when the order number does not exist.
 */
function order_return_balance(string $orderNumber, int $excludeReturnId = 0): ?array
{
    $order = db_one('SELECT id, order_number, customer, date FROM orders WHERE order_number = ?', [$orderNumber]);
    if (!$order) {
        return null;
    }
    $items = [];
    foreach (db_all('SELECT product_id, MIN(name) AS name, SUM(quantity) AS qty FROM order_items
                     WHERE order_id = ? AND product_id IS NOT NULL GROUP BY product_id ORDER BY MIN(id)', [(int)$order['id']]) as $l) {
        $items[(int)$l['product_id']] = ['product_id' => (int)$l['product_id'], 'name' => $l['name'],
            'bought' => (float)$l['qty'], 'returned' => 0.0, 'left' => 0.0];
    }
    $others = db_all("SELECT products FROM returns_damage WHERE order_id = ? AND status <> 'Return Rejected' AND id <> ?",
        [$orderNumber, $excludeReturnId]);
    foreach ($others as $r) {
        foreach (json_decode($r['products'] ?? '[]', true) ?: [] as $p) {
            $pid = (int)($p['product_id'] ?? 0);
            if (isset($items[$pid])) {
                $items[$pid]['returned'] += (float)($p['quantity'] ?? 0);
            }
        }
    }
    foreach ($items as &$it) {
        $it['left'] = max(0, round($it['bought'] - $it['returned'], 2));
    }
    unset($it);
    return ['order_number' => $order['order_number'], 'customer' => $order['customer'], 'date' => $order['date'], 'items' => $items];
}

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
    $readReturnForm = function (int $excludeReturnId = 0) use ($returnReasons): array {
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

        // Linked to an order: only its products, and never more than what is left to return.
        if ($orderNumber !== '') {
            $balance = order_return_balance($orderNumber, $excludeReturnId);
            if (!$balance) {
                returnDamageResponse(['success' => false, 'message' => "Order $orderNumber not found. Pick an order from the list or leave it empty."], 422);
            }
            $want = [];
            foreach ($clean as $c) {
                $want[$c['product_id']] = ($want[$c['product_id']] ?? 0) + $c['quantity'];
            }
            foreach ($want as $pid => $qty) {
                $line = $balance['items'][$pid] ?? null;
                if (!$line) {
                    returnDamageResponse(['success' => false, 'message' => db_value('SELECT name FROM products WHERE id = ?', [$pid]) . " is not on order $orderNumber."], 422);
                }
                if ($qty > $line['left'] + 0.0001) {
                    returnDamageResponse(['success' => false, 'message' => "{$line['name']}: bought " . (float)$line['bought']
                        . ', already returned ' . (float)$line['returned'] . ', only ' . (float)$line['left'] . ' can be returned.'], 422);
                }
            }
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
        [$orderNumber, $date, $notes, $clean, $damaged] = $readReturnForm($id);

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
// Products on each order (bought quantity) for the return form; already-returned is worked out from the returns list.
$returnOrderLines = [];
// Order details for the search box + details panel (customer, mobile, invoice, amount, status).
foreach (db_all('SELECT o.order_number, o.customer, o.date, o.amount, o.status,
                        c.mobile, c.business_name, i.invoice_number
                 FROM orders o
                 LEFT JOIN customers c ON c.id = o.customer_id
                 LEFT JOIN invoices i ON i.id = o.invoice_id
                 ORDER BY o.id DESC LIMIT 500') as $o) {
    $returnOrderLines[$o['order_number']] = [
        'customer' => (string)$o['customer'],
        'business' => (string)$o['business_name'],
        'mobile' => (string)$o['mobile'],
        'date' => $o['date'],
        'amount' => (float)$o['amount'],
        'status' => $o['status'],
        'invoice' => (string)$o['invoice_number'],
        'items' => [],
    ];
}
foreach (db_all('SELECT o.order_number, oi.product_id, MIN(oi.name) AS name, MIN(oi.thickness) AS thickness, MIN(oi.size) AS size,
                        SUM(oi.quantity) AS qty, SUM(oi.amount) AS amount
                 FROM orders o JOIN order_items oi ON oi.order_id = o.id
                 WHERE oi.product_id IS NOT NULL AND o.order_number IN (SELECT order_number FROM (SELECT order_number FROM orders ORDER BY id DESC LIMIT 500) t)
                 GROUP BY o.id, oi.product_id ORDER BY o.id DESC, MIN(oi.id)') as $l) {
    if (!isset($returnOrderLines[$l['order_number']])) {
        continue;
    }
    $returnOrderLines[$l['order_number']]['items'][] = [
        'product_id' => (int)$l['product_id'],
        'name' => $l['name'],
        'detail' => trim(implode(' · ', array_filter([(string)$l['thickness'], (string)$l['size']]))),
        'bought' => (float)$l['qty'],
        'amount' => (float)$l['amount'],
    ];
}

// New / Edit Return open as their own page: returns-damage.php?new=1 or ?edit=ID
$formMode = isset($_GET['new']) || !empty($_GET['edit']);
$formEditId = (int)($_GET['edit'] ?? 0);
if ($formEditId && !db_value('SELECT COUNT(*) FROM returns_damage WHERE id = ?', [$formEditId])) {
    header('Location: returns-damage.php');
    exit;
}

$pageTitle = $formMode ? ($formEditId ? 'Edit Return' : 'New Return') : 'Returns & Damage';
include 'header.php';
?>


<!-- ======================================
     RETURNS & DAMAGE MAIN SECTION
====================================== -->

<section class="return-damage"<?php echo $formMode ? " hidden" : ""; ?>>

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
                        data-perm="add"
                        onclick="location.href='returns-damage.php?new=1'">

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

<!-- NEW / EDIT RETURN PAGE (returns-damage.php?new=1 or ?edit=ID) -->
<style>
    .rd-form-top{display:flex;align-items:center;gap:14px;margin-bottom:16px}
    .rd-back{width:42px;height:42px;border-radius:12px;border:1px solid #dfe6ef;background:#fff;display:grid;place-items:center;color:#1d2f4a;font-size:18px;text-decoration:none;flex:0 0 42px}
    .rd-back:hover{border-color:#1479ff;color:#1479ff}
    .rd-form-card{border:1px solid #e3eaf4;border-radius:16px;box-shadow:0 6px 24px rgba(20,40,80,.06);overflow:visible}
    .rd-form-card .modal-body{padding:22px}
    .rd-form-card .modal-footer{padding:14px 22px;gap:8px}
</style>
<section id="newReturnModal" class="return-damage rd-form-page"<?php echo $formMode ? "" : " hidden"; ?>>
<div class="container-fluid p-0">
    <div class="rd-form-top">
        <a href="returns-damage.php" class="rd-back" title="Back to Returns"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="rd-title mb-0" id="newReturnTitle">New Return / Damage Request</h1>
            <p class="rd-subtitle mb-0">Find the order, then enter how many of each product are being returned.</p>
        </div>
    </div>
    <div class="rd-form-wrap">
        <form class="modal-content rd-form-card" id="newReturnForm">
            <div class="modal-body">
                <!-- FIND ORDER: by order number, customer name or mobile -->
                <div class="rt-find mb-3">
                    <label class="form-label fw-semibold" for="rtSearch"><i class="bi bi-search"></i> Find Order</label>
                    <input type="search" class="form-control" id="rtSearch" autocomplete="off"
                           placeholder="Type order number, customer name or mobile number">
                    <div class="rt-results d-none" id="rtResults"></div>
                </div>
                <style>
                    .rt-find{position:relative}
                    .rt-results{position:absolute;left:0;right:0;top:100%;z-index:20;background:#fff;border:1px solid #d7e2f2;border-radius:12px;box-shadow:0 14px 30px rgba(20,40,80,.15);max-height:320px;overflow:auto;margin-top:4px}
                    .rt-result{display:flex;align-items:center;gap:12px;width:100%;border:0;background:#fff;text-align:left;padding:10px 14px;border-bottom:1px solid #eef2f7}
                    .rt-result:hover,.rt-result.active{background:#f2f7ff}
                    .rt-result .rt-no{font-weight:800;color:#1d5fd1;min-width:84px}
                    .rt-result .rt-who{flex:1;min-width:0}
                    .rt-result .rt-who b{display:block;color:#1d2f4a}
                    .rt-result .rt-who small{color:#6b7c95}
                    .rt-result .rt-amt{text-align:right;font-weight:700;color:#1d2f4a;white-space:nowrap}
                    .rt-result .rt-amt small{display:block;font-weight:500;color:#6b7c95}
                    .rt-result mark{background:#fff3a8;padding:0}
                    .rt-noresult{padding:14px;color:#6b7c95}
                    .rt-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin:10px 0 12px}
                    .rt-info div{background:#fff;border:1px solid #e3eaf4;border-radius:9px;padding:7px 10px}
                    .rt-info span{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#6b7c95}
                    .rt-info b{font-size:14px;color:#1d2f4a;word-break:break-word}
                    .rt-totals{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
                    .rt-totals span{border-radius:20px;padding:3px 11px;font-size:12px;font-weight:700}
                </style>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="newReturnOrder">Order Number</label>
                        <div class="input-group">
                            <input class="form-control" id="newReturnOrder" list="returnOrderList" placeholder="Pick from the search above (optional)" maxlength="30">
                            <button type="button" class="btn btn-light border" id="rtClearOrder" title="Clear order"><i class="bi bi-x-lg"></i></button>
                        </div>
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

                <!-- ORDER PRODUCTS: bought / already returned / can return / return now -->
                <div id="returnOrderPanel" class="rt-order d-none mb-3">
                    <div class="rt-order-head">
                        <i class="bi bi-cart-check"></i>
                        <div>
                            <div class="fw-bold" id="rtOrderTitle"></div>
                            <div class="small text-muted" id="rtOrderSub"></div>
                        </div>
                        <div class="rt-totals" id="rtTotals"></div>
                    </div>
                    <div class="rt-info" id="rtInfo"></div>
                    <div class="table-responsive">
                        <table class="rt-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Bought</th>
                                    <th class="text-center">Already Returned</th>
                                    <th class="text-center">Can Return</th>
                                    <th style="width:120px">Return Now</th>
                                    <th style="width:180px">Reason</th>
                                </tr>
                            </thead>
                            <tbody id="rtOrderRows"></tbody>
                        </table>
                    </div>
                    <div class="rt-summary" id="rtSummary"></div>
                </div>
                <style>
                    .rt-order{border:1px solid #cfe0fb;background:#f6faff;border-radius:12px;padding:14px}
                    .rt-order-head{display:flex;gap:10px;align-items:center;margin-bottom:10px}
                    .rt-order-head>i{width:36px;height:36px;border-radius:10px;background:#e3eeff;color:#1d5fd1;display:grid;place-items:center;font-size:17px}
                    .rt-table{width:100%;border-collapse:separate;border-spacing:0 6px;font-size:14px}
                    .rt-table th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#6b7c95;font-weight:700;padding:0 8px}
                    .rt-table td{background:#fff;padding:8px;border-top:1px solid #e3eaf4;border-bottom:1px solid #e3eaf4;vertical-align:middle}
                    .rt-table td:first-child{border-left:1px solid #e3eaf4;border-radius:8px 0 0 8px;font-weight:600}
                    .rt-table td:last-child{border-right:1px solid #e3eaf4;border-radius:0 8px 8px 0}
                    .rt-pill{display:inline-block;min-width:44px;border-radius:20px;padding:2px 10px;font-weight:700;font-size:13px}
                    .rt-pill.bought{background:#eef2f7;color:#2b3b55}
                    .rt-pill.returned{background:#fff1e0;color:#b45309}
                    .rt-pill.left{background:#e5f7ec;color:#0f7a3d}
                    .rt-pill.none{background:#f1f1f1;color:#8a8a8a}
                    .rt-table tr.rt-over td{background:#fff5f5}
                    .rt-table .rt-qty.is-invalid{border-color:#e0444c;color:#c2343c;font-weight:700}
                    .rt-table tr.rt-done td{opacity:.6}
                    .rt-summary{font-size:13px;margin-top:6px;color:#2b3b55}
                    .rt-summary .bad{color:#c2343c;font-weight:700}
                </style>

                <div id="manualProductsBox">
                <label class="form-label fw-semibold">Products *</label>
                <div id="newReturnItems" class="d-grid gap-2 mb-2"></div>
                <button type="button" class="btn btn-sm btn-light border" id="addReturnItemBtn">
                    <i class="bi bi-plus-lg me-1"></i>Add Product
                </button>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea class="form-control" id="newReturnNotes" rows="3" maxlength="2000"></textarea>
                </div>

                <div class="alert alert-info d-none mt-3 mb-0 small" id="editReturnNote"><i class="bi bi-info-circle"></i> This return is already approved. Saving corrects the stock: old good items are taken back out and the new ones are put back in.</div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="newReturnError"></div>
            </div>
            <div class="modal-footer">
                <a href="returns-damage.php" class="btn btn-light border">Cancel</a>
                <button type="submit" class="btn btn-primary" id="saveReturnBtn">Save Return</button>
            </div>
        </form>
    </div>
</div>
</section>

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
                        ${(() => {
                            const ps = record.products || [];
                            const dmg = ps.filter(p => p.reason === "damaged").reduce((s, p) => s + Number(p.quantity || 0), 0);
                            const ret = ps.filter(p => p.reason !== "damaged").reduce((s, p) => s + Number(p.quantity || 0), 0);
                            return [ret ? `<span style="color:#0f766e;font-weight:600">${ret} returned</span>` : "",
                                    dmg ? `<span style="color:#dc2626;font-weight:600">${dmg} damaged</span>` : ""].filter(Boolean).join(" · ") || "Order return request";
                        })()}
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

    /* ---- Order products: bought / already returned / can return ---- */
    const orderLines = <?php echo json_encode((object)$returnOrderLines, json_flags()); ?>;
    const reasonList = <?php echo json_encode($returnReasons, json_flags()); ?>;
    const fq = v => Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });

    // Quantity of a product already on other returns of this order (rejected ones do not count).
    function returnedSoFar(orderNumber, productId) {
        return returns
            .filter(r => r.order_id === orderNumber && r.status !== "Return Rejected"
                && !(editingReturn && Number(r.id) === Number(editingReturn.id)))
            .reduce((sum, r) => sum + (r.products || [])
                .filter(p => Number(p.product_id) === Number(productId))
                .reduce((s, p) => s + Number(p.quantity || 0), 0), 0);
    }

    function orderMode() {
        return !!orderLines[$("newReturnOrder").value.trim()];
    }

    function rtRowHTML(item, left, qty, reason) {
        return `<tr data-pid="${item.product_id}" data-left="${left}">
            <td>${escapeHTML(item.name)}${item.detail ? `<div class="small text-muted fw-normal">${escapeHTML(item.detail)}</div>` : ""}</td>
            <td class="text-center"><span class="rt-pill bought">${fq(item.bought)}</span></td>
            <td class="text-center"><span class="rt-pill ${item.returned ? "returned" : "none"}">${fq(item.returned)}</span></td>
            <td class="text-center"><span class="rt-pill ${left > 0 ? "left" : "none"}">${fq(left)}</span></td>
            <td><input type="number" class="form-control form-control-sm rt-qty" min="0" step="0.01" max="${left}"
                       value="${qty || ""}" placeholder="${left > 0 ? "0" : "-"}" ${left > 0 || qty ? "" : "disabled"}></td>
            <td><select class="form-select form-select-sm rt-reason">
                ${reasonList.map(r => `<option value="${escapeHTML(r)}" ${r === (reason || "customer return") ? "selected" : ""}>${escapeHTML(r.charAt(0).toUpperCase() + r.slice(1))}</option>`).join("")}
            </select></td>
        </tr>`;
    }

    // Show the order's products when a known order number is chosen; otherwise the manual product rows.
    function renderOrderPanel() {
        const on = $("newReturnOrder").value.trim();
        const order = orderLines[on];
        $("returnOrderPanel").classList.toggle("d-none", !order);
        $("manualProductsBox").classList.toggle("d-none", !!order);
        $("manualProductsBox").querySelectorAll("select, input").forEach(el => { el.disabled = !!order; });
        if (!order) return;

        $("rtOrderTitle").textContent = "Order " + on + " · " + order.items.length + " product" + (order.items.length === 1 ? "" : "s");
        $("rtOrderSub").textContent = "Products on this order. Enter how many are being returned now.";
        const editLines = editingReturn && editingReturn.order_id === on ? (editingReturn.products || []) : [];
        // Full order details + totals (bought / returned / can return).
        const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        $("rtInfo").innerHTML = [
            ["Order No", on],
            ["Order Date", formatDate(order.date)],
            ["Customer", order.customer + (order.business ? " (" + order.business + ")" : "")],
            ["Mobile", order.mobile || "-"],
            ["Invoice", order.invoice || "Not invoiced"],
            ["Order Amount", money(order.amount)],
            ["Status", order.status || "-"],
        ].map(([k, v]) => `<div><span>${escapeHTML(k)}</span><b>${escapeHTML(v)}</b></div>`).join("");
        const tb = order.items.reduce((s, it) => s + it.bought, 0);
        const tr = order.items.reduce((s, it) => s + returnedSoFar(on, it.product_id), 0);
        $("rtTotals").innerHTML = `
            <span class="rt-pill bought">Bought ${fq(tb)}</span>
            <span class="rt-pill returned">Returned ${fq(tr)}</span>
            <span class="rt-pill left">Can return ${fq(Math.max(0, tb - tr))}</span>`;
        let html = "";
        order.items.forEach(it => {
            const item = { ...it, returned: returnedSoFar(on, it.product_id) };
            const left = Math.max(0, Math.round((item.bought - item.returned) * 100) / 100);
            const mine = editLines.filter(p => Number(p.product_id) === Number(it.product_id));
            if (mine.length) {
                mine.forEach(p => { html += rtRowHTML(item, left, p.quantity, p.reason); });
            } else {
                html += rtRowHTML(item, left, "", "");
            }
        });
        $("rtOrderRows").innerHTML = html || '<tr><td colspan="6" class="text-muted">This order has no catalogue products.</td></tr>';
        checkOrderPanel();
    }

    // Red when "Return Now" (all rows of a product together) is more than "Can Return".
    function checkOrderPanel() {
        const want = {};
        const rows = [...$("rtOrderRows").querySelectorAll("tr[data-pid]")];
        rows.forEach(tr => { want[tr.dataset.pid] = (want[tr.dataset.pid] || 0) + Number(tr.querySelector(".rt-qty").value || 0); });
        let total = 0, dmgTotal = 0, over = [];
        rows.forEach(tr => {
            const left = Number(tr.dataset.left);
            const bad = want[tr.dataset.pid] > left + 0.0001;
            tr.classList.toggle("rt-over", bad);
            tr.querySelector(".rt-qty").classList.toggle("is-invalid", bad);
            tr.classList.toggle("rt-done", left <= 0 && !Number(tr.querySelector(".rt-qty").value));
            const q = Number(tr.querySelector(".rt-qty").value || 0);
            total += q;
            if (tr.querySelector(".rt-reason").value === "damaged") dmgTotal += q;
            if (bad) over.push(tr.cells[0].textContent + " (only " + fq(left) + " can be returned)");
        });
        over = [...new Set(over)];
        $("rtSummary").innerHTML = over.length
            ? `<span class="bad"><i class="bi bi-exclamation-triangle-fill"></i> More than can be returned: ${escapeHTML(over.join(", "))}</span>`
            : (total ? `On this request: <b style="color:#0f766e">${fq(total - dmgTotal)} returned</b> (go back to stock on approval) · <b style="color:#dc2626">${fq(dmgTotal)} damaged</b> (not back to stock).` : "Enter a quantity in <b>Return Now</b> for the products being returned, and pick <b>Damaged</b> as the reason for damaged items.");
        return over;
    }

    /* ---- Find Order: search by order number, customer name or mobile ---- */
    const orderList = Object.entries(orderLines).map(([no, o]) => ({ no, ...o }));
    function hl(text, q) {
        const t = String(text || "");
        const i = q ? t.toLowerCase().indexOf(q) : -1;
        return i < 0 ? escapeHTML(t) : escapeHTML(t.slice(0, i)) + "<mark>" + escapeHTML(t.slice(i, i + q.length)) + "</mark>" + escapeHTML(t.slice(i + q.length));
    }
    function searchOrders() {
        const q = $("rtSearch").value.trim().toLowerCase();
        const box = $("rtResults");
        if (!q) { box.classList.add("d-none"); return; }
        const digits = q.replace(/\D/g, "");
        const hits = orderList.filter(o =>
            o.no.toLowerCase().includes(q) ||
            o.customer.toLowerCase().includes(q) ||
            o.business.toLowerCase().includes(q) ||
            (o.invoice || "").toLowerCase().includes(q) ||
            (digits.length >= 3 && (o.mobile || "").includes(digits))
        ).slice(0, 30);
        box.innerHTML = hits.length ? hits.map(o => `
            <button type="button" class="rt-result" data-order="${escapeHTML(o.no)}">
                <span class="rt-no">${hl(o.no, q)}</span>
                <span class="rt-who"><b>${hl(o.customer, q)}</b>
                    <small><i class="bi bi-telephone"></i> ${hl(o.mobile || "-", digits || q)} · ${escapeHTML(formatDate(o.date))}${o.invoice ? " · " + hl(o.invoice, q) : ""}</small></span>
                <span class="rt-amt">Rs ${Number(o.amount).toLocaleString("en-IN")}<small>${o.items.length} product${o.items.length === 1 ? "" : "s"}</small></span>
            </button>`).join("")
            : '<div class="rt-noresult">No order found for "' + escapeHTML(q) + '".</div>';
        box.classList.remove("d-none");
    }
    function pickOrder(no) {
        $("newReturnOrder").value = no;
        $("rtSearch").value = "";
        $("rtResults").classList.add("d-none");
        renderOrderPanel();
        const first = $("rtOrderRows").querySelector(".rt-qty:not([disabled])");
        if (first) first.focus();
    }
    $("rtSearch").addEventListener("input", searchOrders);
    $("rtSearch").addEventListener("focus", searchOrders);
    $("rtSearch").addEventListener("keydown", e => {
        if (e.key === "Enter") {
            e.preventDefault();
            const first = $("rtResults").querySelector("[data-order]");
            if (first) pickOrder(first.dataset.order);
        } else if (e.key === "Escape") {
            $("rtResults").classList.add("d-none");
        }
    });
    $("rtResults").addEventListener("mousedown", e => {
        const b = e.target.closest("[data-order]");
        if (b) { e.preventDefault(); pickOrder(b.dataset.order); }
    });
    $("rtSearch").addEventListener("blur", () => setTimeout(() => $("rtResults").classList.add("d-none"), 150));
    $("rtClearOrder").addEventListener("click", () => {
        $("newReturnOrder").value = "";
        renderOrderPanel();
        $("rtSearch").focus();
    });

    $("newReturnOrder").addEventListener("input", renderOrderPanel);
    $("newReturnOrder").addEventListener("change", renderOrderPanel);
    $("rtOrderRows").addEventListener("input", checkOrderPanel);
    $("rtOrderRows").addEventListener("change", checkOrderPanel);

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

    // Edit opens the return on its own page.
    function openEditReturn(id) {
        location.href = "returns-damage.php?edit=" + encodeURIComponent(id);
    }

    // Edit buttons (table row + details panel).
    document.addEventListener("click", event => {
        const btn = event.target.closest("[data-edit-return]");
        if (!btn) return;
        event.stopPropagation();
        openEditReturn(btn.dataset.editReturn);
    }, true);

    // Fill the New / Edit Return page.
    function initReturnForm() {
        $("newReturnForm").reset();
        itemsBox.innerHTML = "";
        $("newReturnError").classList.add("d-none");
        const r = editingReturn;
        $("rtSearch").value = "";
        $("rtResults").classList.add("d-none");
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
        renderOrderPanel();
        if (!r) $("rtSearch").focus();
    }

    $("newReturnForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        if (!this.reportValidity()) return;

        let products;
        if (orderMode()) {
            // Products from the order table: only rows with a quantity.
            const over = checkOrderPanel();
            products = [...$("rtOrderRows").querySelectorAll("tr[data-pid]")]
                .map(tr => ({
                    product_id: Number(tr.dataset.pid),
                    quantity: Number(tr.querySelector(".rt-qty").value || 0),
                    reason: tr.querySelector(".rt-reason").value
                }))
                .filter(p => p.quantity > 0);
            const err = over.length ? "More than can be returned: " + over.join(", ")
                : (!products.length ? "Enter a Return Now quantity for at least one product." : "");
            if (err) {
                $("newReturnError").textContent = err;
                $("newReturnError").classList.remove("d-none");
                return;
            }
        } else {
            products = [...itemsBox.querySelectorAll(".return-item-row")].map(row => ({
                product_id: Number(row.querySelector(".return-product").value),
                quantity: Number(row.querySelector(".return-qty").value),
                reason: row.querySelector(".return-reason").value
            }));
        }

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

            // Back to the list, with the saved return selected.
            const savedId = editingReturn ? Number(editingReturn.id) : (result.returns.length ? Number(result.returns[0].id) : "");
            location.href = "returns-damage.php?sel=" + savedId + "&msg=" + encodeURIComponent(result.message);
            return;
        } catch (error) {
            $("newReturnError").textContent = error.message;
            $("newReturnError").classList.remove("d-none");
        } finally {
            saveBtn.disabled = false;
        }
    });

    /* INITIAL PAGE LOAD */

    const formMode = <?php echo $formMode ? "true" : "false"; ?>;
    if (formMode) {
        editingReturn = returns.find(r => Number(r.id) === <?php echo (int)$formEditId; ?>) || null;
        initReturnForm();
    } else {
        // Came back after saving: show the message and select that return.
        const params = new URLSearchParams(location.search);
        if (params.get("sel")) selectedReturnId = Number(params.get("sel"));
        if (params.get("msg")) showMessage(params.get("msg"));
        if (params.get("sel") || params.get("msg")) history.replaceState(null, "", location.pathname);
    }

    renderReturns();

})();
</script>





<?php include 'footer.php'; ?>