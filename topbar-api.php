<?php
/*
|--------------------------------------------------------------------------
| TOP BAR API (logged-in business only - db() is that business's database)
|--------------------------------------------------------------------------
| GET ?action=search&q=...  -> Quick Search (Ctrl+K) results
| GET ?action=alerts        -> bell notifications (low stock, dues, quotations)
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$action = $_GET['action'] ?? '';

function tb_money(float $v): string
{
    return 'Rs ' . number_format($v, 2);
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_response(['success' => true, 'groups' => []]);
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $groups = [];

    $rows = db_all('SELECT id, name, mobile, gstin FROM customers WHERE name LIKE ? OR mobile LIKE ? OR gstin LIKE ? ORDER BY name LIMIT 5', [$like, $like, $like]);
    if ($rows) {
        $groups[] = ['title' => 'Customers', 'items' => array_map(fn($r) => [
            'icon' => 'person', 'title' => $r['name'],
            'sub' => implode(' · ', array_filter([$r['mobile'], $r['gstin']])),
            'url' => 'customers.php?search=' . rawurlencode($r['name']),
        ], $rows)];
    }

    $rows = db_all('SELECT id, name, hsn_code, price, stock, measurement_unit FROM products WHERE name LIKE ? OR hsn_code LIKE ? ORDER BY name LIMIT 5', [$like, $like]);
    if ($rows) {
        $groups[] = ['title' => 'Products', 'items' => array_map(fn($r) => [
            'icon' => 'box-seam', 'title' => $r['name'],
            'sub' => tb_money((float)$r['price']) . ' · Stock ' . rtrim(rtrim((string)$r['stock'], '0'), '.') . ($r['hsn_code'] ? ' · HSN ' . $r['hsn_code'] : ''),
            'url' => 'add-product.php?id=' . (int)$r['id'],
        ], $rows)];
    }

    $rows = db_all('SELECT id, invoice_number, customer, amount, status, date FROM invoices WHERE invoice_number LIKE ? OR customer LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like]);
    if ($rows) {
        $groups[] = ['title' => 'Invoices', 'items' => array_map(fn($r) => [
            'icon' => 'receipt', 'title' => $r['invoice_number'] . ' · ' . $r['customer'],
            'sub' => tb_money((float)$r['amount']) . ' · ' . $r['status'] . ' · ' . date('d M Y', strtotime($r['date'])),
            'url' => 'invoice-print.php?id=' . (int)$r['id'],
        ], $rows)];
    }

    $rows = db_all('SELECT id, quotation_id, customer, amount, status FROM quotations WHERE quotation_id LIKE ? OR customer LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like]);
    if ($rows) {
        $groups[] = ['title' => 'Quotations', 'items' => array_map(fn($r) => [
            'icon' => 'file-earmark-text', 'title' => $r['quotation_id'] . ' · ' . $r['customer'],
            'sub' => tb_money((float)$r['amount']) . ' · ' . $r['status'],
            'url' => 'quotation-print.php?id=' . (int)$r['id'],
        ], $rows)];
    }

    $rows = db_all('SELECT id, order_number, customer, amount, status FROM orders WHERE order_number LIKE ? OR customer LIKE ? ORDER BY id DESC LIMIT 5', [$like, $like]);
    if ($rows) {
        $groups[] = ['title' => 'Orders', 'items' => array_map(fn($r) => [
            'icon' => 'cart-check', 'title' => $r['order_number'] . ' · ' . $r['customer'],
            'sub' => tb_money((float)$r['amount']) . ' · ' . $r['status'],
            'url' => 'orders.php?search=' . rawurlencode($r['order_number']),
        ], $rows)];
    }

    json_response(['success' => true, 'groups' => $groups]);
}

if ($action === 'alerts') {
    $groups = [];

    // Low / out of stock products.
    $where = 'enabled = 1 AND stock <= low_stock_alert';
    $count = (int)db_value("SELECT COUNT(*) FROM products WHERE $where");
    if ($count) {
        $rows = db_all("SELECT id, name, stock, low_stock_alert, measurement_unit FROM products WHERE $where ORDER BY stock ASC, name LIMIT 6");
        $groups[] = ['title' => 'Low stock', 'tone' => 'orange', 'count' => $count, 'more' => 'products.php', 'items' => array_map(fn($r) => [
            'icon' => (float)$r['stock'] <= 0 ? 'x-octagon' : 'exclamation-triangle',
            'title' => $r['name'],
            'sub' => ((float)$r['stock'] <= 0 ? 'Out of stock' : 'Only ' . rtrim(rtrim((string)$r['stock'], '0'), '.') . ' left')
                . ' · alert at ' . rtrim(rtrim((string)$r['low_stock_alert'], '0'), '.'),
            'url' => 'add-product.php?id=' . (int)$r['id'],
        ], $rows)];
    }

    // Unpaid invoices that are overdue or due within 3 days.
    $dueSql = "FROM invoices i
               WHERE i.status NOT IN ('Paid','Draft','Cancelled') AND i.due_date IS NOT NULL
                 AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                 AND i.amount > COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0)";
    $count = (int)db_value("SELECT COUNT(*) $dueSql");
    if ($count) {
        $rows = db_all("SELECT i.id, i.invoice_number, i.customer, i.due_date,
                               i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0) AS balance
                        $dueSql ORDER BY i.due_date LIMIT 6");
        $today = new DateTimeImmutable('today');
        $groups[] = ['title' => 'Payments due', 'tone' => 'red', 'count' => $count, 'more' => 'invoices.php', 'items' => array_map(function ($r) use ($today) {
            $days = (int)$today->diff(new DateTimeImmutable($r['due_date']))->format('%r%a');
            $when = $days < 0 ? 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') : ($days === 0 ? 'Due today' : "Due in $days day" . ($days === 1 ? '' : 's'));
            return [
                'icon' => $days < 0 ? 'alarm' : 'calendar-event',
                'title' => $r['invoice_number'] . ' · ' . $r['customer'],
                'sub' => tb_money((float)$r['balance']) . ' · ' . $when,
                'url' => 'invoice-print.php?id=' . (int)$r['id'],
            ];
        }, $rows)];
    }

    // Open quotations expiring within 3 days.
    $qSql = "FROM quotations WHERE status IN ('Draft','Sent') AND valid_upto BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
    $count = (int)db_value("SELECT COUNT(*) $qSql");
    if ($count) {
        $rows = db_all("SELECT id, quotation_id, customer, amount, valid_upto $qSql ORDER BY valid_upto LIMIT 6");
        $groups[] = ['title' => 'Quotations expiring', 'tone' => 'blue', 'count' => $count, 'more' => 'quotation.php', 'items' => array_map(fn($r) => [
            'icon' => 'hourglass-split',
            'title' => $r['quotation_id'] . ' · ' . $r['customer'],
            'sub' => tb_money((float)$r['amount']) . ' · valid till ' . date('d M', strtotime($r['valid_upto'])),
            'url' => 'quotation-print.php?id=' . (int)$r['id'],
        ], $rows)];
    }

    json_response(['success' => true, 'total' => array_sum(array_column($groups, 'count')), 'groups' => $groups]);
}

json_response(['success' => false, 'message' => 'Unknown action.'], 400);
