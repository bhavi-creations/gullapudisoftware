<?php
/*
|--------------------------------------------------------------------------
| DASHBOARD - live figures for the selected period
|--------------------------------------------------------------------------
| Period: Today / 7 Days / This Month / This Year / Custom (from - to)
| Sales        = invoices in the period (not Draft / Cancelled)
| Collected    = payments received in the period
| Receivables  = unpaid invoice balances right now (+ ageing by due date)
| Every figure is compared with the previous period of the same length.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$valid = "status NOT IN ('Draft','Cancelled')";
$today = new DateTimeImmutable('today');

/* ---------- Period ---------- */
$ranges = ['today' => 'Today', 'week' => '7 Days', 'month' => 'This Month', 'year' => 'This Year', 'custom' => 'Custom'];
$range = $_GET['range'] ?? 'month';
if (!isset($ranges[$range])) {
    $range = 'month';
}
$parse = fn($v) => is_string($v) ? (DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null) : null;

switch ($range) {
    case 'today':
        $from = $to = $today;
        break;
    case 'week':
        $from = $today->modify('-6 days');
        $to = $today;
        break;
    case 'year':
        $from = $today->modify('first day of january this year');
        $to = $today->modify('last day of december this year');
        break;
    case 'custom':
        $from = $parse($_GET['from'] ?? '') ?: $today->modify('first day of this month');
        $to = $parse($_GET['to'] ?? '') ?: $today;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        break;
    default:
        $from = $today->modify('first day of this month');
        $to = $today->modify('last day of this month');
}
$days = (int)$from->diff($to)->days + 1;
$prevTo = $from->modify('-1 day');
$prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
$f = $from->format('Y-m-d');
$t = $to->format('Y-m-d');
$pf = $prevFrom->format('Y-m-d');
$pt = $prevTo->format('Y-m-d');

$periodLabel = $range === 'today' ? $from->format('l, j M Y')
    : ($from->format('Y') === $to->format('Y')
        ? $from->format('j M') . ' – ' . $to->format('j M Y')
        : $from->format('j M Y') . ' – ' . $to->format('j M Y'));
$compareLabel = ['today' => 'vs yesterday', 'week' => 'vs previous 7 days', 'month' => 'vs last month', 'year' => 'vs last year'][$range] ?? 'vs previous period';

/* ---------- Helpers ---------- */
function rs(float $v): string
{
    // Indian number format: 12,34,567
    $neg = $v < 0;
    $v = (string)round(abs($v));
    $last3 = substr($v, -3);
    $rest = substr($v, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $last3 = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . '₹' . $last3;
}

function delta(float $now, float $before): array
{
    if ($before == 0.0) {
        return ['text' => $now > 0 ? 'New' : '0%', 'dir' => $now > 0 ? 'up' : 'flat'];
    }
    $p = ($now - $before) / $before * 100;
    return ['text' => ($p >= 0 ? '+' : '') . number_format($p, 1) . '%', 'dir' => $p > 0.05 ? 'up' : ($p < -0.05 ? 'down' : 'flat')];
}

$sumInvoices = fn($a, $b) => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE $valid AND date BETWEEN ? AND ?", [$a, $b]);
$countInvoices = fn($a, $b) => (int)db_value("SELECT COUNT(*) FROM invoices WHERE $valid AND date BETWEEN ? AND ?", [$a, $b]);
$sumPayments = fn($a, $b) => (float)db_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE date BETWEEN ? AND ?', [$a, $b]);

/* ---------- KPIs ---------- */
$sales = $sumInvoices($f, $t);
$salesPrev = $sumInvoices($pf, $pt);
$collected = $sumPayments($f, $t);
$collectedPrev = $sumPayments($pf, $pt);
$invCount = $countInvoices($f, $t);
$invCountPrev = $countInvoices($pf, $pt);
$avgInvoice = $invCount ? $sales / $invCount : 0;
$avgInvoicePrev = $invCountPrev ? $salesPrev / $invCountPrev : 0;

// Receivables now, with ageing by days past the due date.
$balances = db_all(
    "SELECT i.customer, i.due_date,
            i.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0) AS balance
     FROM invoices i WHERE i.$valid HAVING balance > 0.009"
);
$aging = ['Not due' => 0.0, '1–30 days' => 0.0, '31–60 days' => 0.0, '61–90 days' => 0.0, '90+ days' => 0.0];
$receivable = 0.0;
$overdue = 0.0;
$dueCustomers = [];
foreach ($balances as $b) {
    $bal = (float)$b['balance'];
    $receivable += $bal;
    $dueCustomers[$b['customer']] = true;
    $late = $b['due_date'] ? (int)(new DateTimeImmutable($b['due_date']))->diff($today)->format('%r%a') : 0;
    if ($late <= 0) {
        $aging['Not due'] += $bal;
    } else {
        $overdue += $bal;
        $aging[$late <= 30 ? '1–30 days' : ($late <= 60 ? '31–60 days' : ($late <= 90 ? '61–90 days' : '90+ days'))] += $bal;
    }
}

// Quotations + customers in the period.
$quotes = db_one(
    "SELECT COUNT(*) AS total, SUM(status = 'Converted') AS converted, COALESCE(SUM(amount),0) AS value
     FROM quotations WHERE date BETWEEN ? AND ?",
    [$f, $t]
);
$quoteTotal = (int)$quotes['total'];
$quoteConverted = (int)$quotes['converted'];
$conversion = $quoteTotal ? $quoteConverted / $quoteTotal * 100 : 0;
$newCustomers = (int)db_value('SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t]);
$totalCustomers = (int)db_value('SELECT COUNT(*) FROM customers');

// Stock.
$stock = db_one(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(stock * price),0) AS value,
            COALESCE(SUM(stock > 0 AND stock <= low_stock_alert),0) AS low,
            COALESCE(SUM(stock <= 0),0) AS out_of_stock,
            COALESCE(SUM(stock > low_stock_alert),0) AS in_stock
     FROM products WHERE enabled = 1'
);

/* ---------- Trend: sales vs collections ---------- */
$byMonth = $days > 62;
$fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';
$salesRows = array_column(db_all("SELECT DATE_FORMAT(date, '$fmt') AS k, SUM(amount) AS v FROM invoices WHERE $valid AND date BETWEEN ? AND ? GROUP BY k", [$f, $t]), 'v', 'k');
$payRows = array_column(db_all("SELECT DATE_FORMAT(date, '$fmt') AS k, SUM(amount) AS v FROM payments WHERE date BETWEEN ? AND ? GROUP BY k", [$f, $t]), 'v', 'k');
$trendLabels = $trendSales = $trendPaid = [];
$cursor = $byMonth ? $from->modify('first day of this month') : $from;
while ($cursor <= $to) {
    $key = $cursor->format($byMonth ? 'Y-m' : 'Y-m-d');
    $trendLabels[] = $cursor->format($byMonth ? 'M Y' : ($days <= 7 ? 'D j' : 'j M'));
    $trendSales[] = round((float)($salesRows[$key] ?? 0), 2);
    $trendPaid[] = round((float)($payRows[$key] ?? 0), 2);
    $cursor = $cursor->modify($byMonth ? '+1 month' : '+1 day');
}

/* ---------- Payment modes ---------- */
$modes = db_all('SELECT COALESCE(NULLIF(mode, \'\'), \'Other\') AS mode, SUM(amount) AS total FROM payments WHERE date BETWEEN ? AND ? GROUP BY mode ORDER BY total DESC', [$f, $t]);
$modeColors = ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#0ea5e9', '#ef4444', '#64748b'];

/* ---------- Top lists ---------- */
$topProducts = db_all(
    "SELECT oi.name, SUM(oi.quantity) AS qty, SUM(oi.amount) AS sales
     FROM invoices i JOIN order_items oi ON oi.order_id = i.order_id
     WHERE i.$valid AND i.date BETWEEN ? AND ?
     GROUP BY oi.name ORDER BY sales DESC LIMIT 5",
    [$f, $t]
);
$topCustomers = db_all(
    "SELECT customer, COUNT(*) AS invoices, SUM(amount) AS sales FROM invoices
     WHERE $valid AND date BETWEEN ? AND ? GROUP BY customer ORDER BY sales DESC LIMIT 5",
    [$f, $t]
);
$recentInvoices = db_all('SELECT id, invoice_number, customer, date, amount, status FROM invoices ORDER BY id DESC LIMIT 6');
$activities = db_all("SELECT user_name, module, action, created_at FROM activity_log WHERE module <> 'Auth' ORDER BY id DESC LIMIT 6");

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($_SESSION['user_name'] ?? 'there'))[0];

$statusTone = fn($s) => ['Paid' => 'green', 'Partially Paid' => 'orange', 'Overdue' => 'red', 'Cancelled' => 'grey', 'Draft' => 'grey'][$s] ?? 'blue';
$moduleIcon = fn($m) => ['Invoices' => 'receipt', 'Customers' => 'people', 'Products' => 'box-seam', 'Payments' => 'cash-coin', 'Quotations' => 'file-earmark-text', 'Orders' => 'cart-check', 'Settings' => 'gear', 'Business Accounts' => 'buildings', 'Employees' => 'person-badge', 'Taxes' => 'percent', 'Categories' => 'tags'][$m] ?? 'activity';

$kpis = [
    ['Sales', rs($sales), delta($sales, $salesPrev), 'graph-up-arrow', 'blue', $invCount . ' invoice' . ($invCount === 1 ? '' : 's')],
    ['Collected', rs($collected), delta($collected, $collectedPrev), 'wallet2', 'green', $sales > 0 ? round($collected / $sales * 100) . '% of sales' : 'Payments received'],
    ['Receivables', rs($receivable), null, 'hourglass-split', 'orange', $overdue > 0 ? rs($overdue) . ' overdue' : 'Nothing overdue'],
    ['Avg. Invoice', rs($avgInvoice), delta($avgInvoice, $avgInvoicePrev), 'receipt', 'purple', 'Average value per invoice'],
];
$miniStats = [
    ['Quotations', $quoteTotal, $quoteTotal ? number_format($conversion, 0) . '% converted' : 'None in period', 'file-earmark-text', 'quotation.php'],
    ['New Customers', $newCustomers, $totalCustomers . ' total', 'person-plus', 'customers.php'],
    ['Stock Value', rs((float)$stock['value']), (int)$stock['total'] . ' products', 'box-seam', 'products.php'],
    ['Low / Out of Stock', (int)$stock['low'] + (int)$stock['out_of_stock'], (int)$stock['out_of_stock'] . ' out of stock', 'exclamation-triangle', 'products.php'],
];

$pageTitle = 'Dashboard';
include 'header.php';
?>

<style>
.dx { padding: 24px 24px 32px; }
.dx-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.dx-hello { font-size: 13px; font-weight: 600; color: var(--p-primary); margin: 0 0 4px; }
.dx-title { font-size: 26px !important; font-weight: 800 !important; letter-spacing: -.02em; margin: 0 !important; }
.dx-sub { color: var(--p-muted); font-size: 13.5px; margin: 4px 0 0; }
.dx-sub i { margin-right: 4px; }

.dx-range { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.dx-seg { display: inline-flex; background: #fff; border: 1px solid var(--p-border); border-radius: 11px; padding: 3px; box-shadow: var(--p-shadow-sm); }
.dx-seg a {
    padding: 6px 12px; border-radius: 8px;
    font-size: 13px; font-weight: 600; color: var(--p-text-2);
    text-decoration: none; white-space: nowrap;
}
.dx-seg a:hover { color: var(--p-text); background: #f5f7fb; }
.dx-seg a.active { background: var(--p-text); color: #fff; }
.dx-custom { display: none; align-items: center; gap: 6px; background: #fff; border: 1px solid var(--p-border); border-radius: 11px; padding: 3px 3px 3px 10px; box-shadow: var(--p-shadow-sm); }
.dx-custom.show { display: inline-flex; }
.dx-custom input { border: 0; font-size: 13px; color: var(--p-text); outline: none; background: transparent; width: 128px; }
.dx-custom span { color: var(--p-muted); font-size: 12px; }
.dx-custom button { border: 0; border-radius: 8px; background: var(--p-primary); color: #fff; font-size: 12.5px; font-weight: 600; padding: 6px 12px; }

.dx-card { background: #fff; border: 1px solid var(--p-border); border-radius: var(--p-radius); box-shadow: var(--p-shadow); height: 100%; }
.dx-card-pad { padding: 18px 20px; }
.dx-card-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 16px 20px 0; }
.dx-card-title { font-size: 15px; font-weight: 700; margin: 0; color: var(--p-text); }
.dx-card-sub { font-size: 12px; color: var(--p-muted); margin: 2px 0 0; }
.dx-link { font-size: 12.5px; font-weight: 600; color: var(--p-primary); text-decoration: none; white-space: nowrap; }
.dx-link:hover { text-decoration: underline; }

/* KPI cards */
.dx-kpi { padding: 18px 20px; position: relative; overflow: hidden; }
.dx-kpi-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.dx-kpi-label { font-size: 13px; font-weight: 600; color: var(--p-text-2); }
.dx-ico { width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; font-size: 17px; flex: 0 0 38px; }
.dx-ico.blue { background: #eef3ff; color: #2563eb; }
.dx-ico.green { background: #ecfdf3; color: #16a34a; }
.dx-ico.orange { background: #fff4e6; color: #ea7a0c; }
.dx-ico.purple { background: #f3efff; color: #7c3aed; }
.dx-kpi-value { font-size: 26px; font-weight: 800; letter-spacing: -.02em; color: var(--p-text); line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-kpi-foot { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12.5px; color: var(--p-muted); flex-wrap: wrap; }
.dx-delta { display: inline-flex; align-items: center; gap: 3px; font-weight: 700; font-size: 12px; padding: 2px 8px; border-radius: 999px; }
.dx-delta.up { background: #ecfdf3; color: #15803d; }
.dx-delta.down { background: #fef0f0; color: #b91c1c; }
.dx-delta.flat { background: #f1f5f9; color: #475569; }

/* Mini stats */
.dx-mini { display: flex; align-items: center; gap: 12px; padding: 14px 16px; text-decoration: none; color: inherit; transition: border-color .15s, transform .15s; }
.dx-mini:hover { border-color: #c9d6ec; transform: translateY(-1px); }
.dx-mini .dx-ico { width: 40px; height: 40px; background: #f5f7fb; color: var(--p-text-2); }
.dx-mini-label { font-size: 12px; color: var(--p-muted); font-weight: 600; }
.dx-mini-value { font-size: 18px; font-weight: 800; color: var(--p-text); line-height: 1.2; }
.dx-mini-note { font-size: 12px; color: var(--p-text-2); }

/* Charts */
.dx-chart { position: relative; height: 290px; padding: 8px 16px 16px; }
.dx-legend { display: flex; gap: 14px; font-size: 12.5px; color: var(--p-text-2); }
.dx-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
.dx-donut { position: relative; height: 180px; margin: 14px auto 6px; max-width: 200px; }
.dx-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.dx-donut-center strong { font-size: 17px; font-weight: 800; }
.dx-donut-center span { font-size: 11.5px; color: var(--p-muted); }
.dx-rows { padding: 4px 20px 18px; }
.dx-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; font-size: 13px; }
.dx-row + .dx-row { border-top: 1px dashed #edf1f6; }
.dx-row-name { flex: 1; min-width: 0; color: var(--p-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-row-val { font-weight: 700; color: var(--p-text); white-space: nowrap; }
.dx-swatch { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 10px; }

/* Ranked lists */
.dx-rank { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
.dx-rank + .dx-rank { border-top: 1px solid #f1f4f8; }
.dx-rank-no { width: 28px; height: 28px; border-radius: 8px; background: #f5f7fb; color: var(--p-text-2); font-size: 12.5px; font-weight: 700; display: grid; place-items: center; flex: 0 0 28px; }
.dx-rank-body { flex: 1; min-width: 0; }
.dx-rank-name { font-size: 13.5px; font-weight: 600; color: var(--p-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-bar { height: 5px; border-radius: 999px; background: #eef2f7; margin-top: 6px; overflow: hidden; }
.dx-bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2563eb, #60a5fa); }
.dx-bar.green span { background: linear-gradient(90deg, #16a34a, #4ade80); }
.dx-rank-meta { font-size: 12px; color: var(--p-muted); margin-top: 3px; }
.dx-rank-val { font-size: 13.5px; font-weight: 700; white-space: nowrap; }

/* Ageing */
.dx-aging { display: flex; height: 12px; border-radius: 999px; overflow: hidden; background: #eef2f7; margin: 16px 20px 6px; }
.dx-aging span { height: 100%; }
.dx-aging-colors span:nth-child(1) { background: #22c55e; }

/* Tables / feed */
.dx-table { width: 100%; border-collapse: collapse; }
.dx-table th { font-size: 11.5px !important; text-transform: uppercase; letter-spacing: .05em; color: var(--p-muted) !important; font-weight: 600; padding: 10px 20px !important; border-bottom: 1px solid var(--p-border); text-align: left; background: #fafbfd; }
.dx-table td { padding: 11px 20px !important; font-size: 13px !important; border-bottom: 1px solid #f1f4f8; }
.dx-table tr:last-child td { border-bottom: 0; }
.dx-table a { color: var(--p-text); font-weight: 600; text-decoration: none; }
.dx-table a:hover { color: var(--p-primary); }
.dx-pill { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.dx-pill.green { background: #ecfdf3; color: #15803d; }
.dx-pill.orange { background: #fff4e6; color: #c2410c; }
.dx-pill.red { background: #fef0f0; color: #b91c1c; }
.dx-pill.blue { background: #eef3ff; color: #1d4ed8; }
.dx-pill.grey { background: #f1f5f9; color: #475569; }
.dx-feed { padding: 6px 20px 16px; }
.dx-feed-item { display: flex; gap: 12px; padding: 10px 0; }
.dx-feed-item + .dx-feed-item { border-top: 1px solid #f1f4f8; }
.dx-feed-ico { width: 32px; height: 32px; border-radius: 9px; background: #f5f7fb; color: var(--p-text-2); display: grid; place-items: center; font-size: 14px; flex: 0 0 32px; }
.dx-feed-text { font-size: 13px; color: var(--p-text); line-height: 1.4; }
.dx-feed-meta { font-size: 11.5px; color: var(--p-muted); margin-top: 2px; }
.dx-empty { padding: 26px 20px; text-align: center; color: var(--p-muted); font-size: 13px; }
.dx-empty i { display: block; font-size: 24px; margin-bottom: 6px; opacity: .6; }

@media (max-width: 767px) {
    .dx { padding: 16px 12px 24px; }
    .dx-head { align-items: flex-start; }
    .dx-range, .dx-seg { width: 100%; }
    .dx-seg a { flex: 1; text-align: center; padding: 6px 4px; font-size: 12.5px; }
    .dx-kpi-value { font-size: 22px; }
    .dx-chart { height: 240px; }
}
</style>

<section class="dx">

    <!-- HEADER + PERIOD -->
    <div class="dx-head">
        <div>
            <p class="dx-hello"><?php echo e($greeting . ', ' . $firstName); ?> 👋</p>
            <h1 class="dx-title">Dashboard</h1>
            <p class="dx-sub"><i class="bi bi-calendar3"></i><?php echo e($periodLabel); ?></p>
        </div>
        <div class="dx-range">
            <div class="dx-seg" role="tablist" aria-label="Period">
                <?php foreach ($ranges as $key => $label): ?>
                    <a href="<?php echo $key === 'custom' ? '#' : 'dashboard.php?range=' . $key; ?>"
                       class="<?php echo $range === $key ? 'active' : ''; ?>"
                       <?php echo $key === 'custom' ? 'id="dxCustomToggle"' : ''; ?>><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </div>
            <form class="dx-custom<?php echo $range === 'custom' ? ' show' : ''; ?>" id="dxCustom" method="get">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="from" value="<?php echo e($f); ?>" aria-label="From date" required>
                <span>to</span>
                <input type="date" name="to" value="<?php echo e($t); ?>" aria-label="To date" required>
                <button type="submit">Apply</button>
            </form>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-3">
        <?php foreach ($kpis as [$label, $value, $d, $icon, $tone, $note]): ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="dx-card dx-kpi">
                <div class="dx-kpi-top">
                    <span class="dx-kpi-label"><?php echo e($label); ?></span>
                    <span class="dx-ico <?php echo $tone; ?>"><i class="bi bi-<?php echo $icon; ?>"></i></span>
                </div>
                <div class="dx-kpi-value" title="<?php echo e($value); ?>"><?php echo e($value); ?></div>
                <div class="dx-kpi-foot">
                    <?php if ($d): ?>
                        <span class="dx-delta <?php echo $d['dir']; ?>">
                            <i class="bi bi-arrow-<?php echo $d['dir'] === 'down' ? 'down' : ($d['dir'] === 'up' ? 'up' : 'right'); ?>-short"></i><?php echo e($d['text']); ?>
                        </span>
                        <span><?php echo e($compareLabel); ?></span>
                    <?php else: ?>
                        <span><?php echo e($note); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($d): ?>
                    <div class="dx-kpi-foot mt-1"><span><?php echo e($note); ?></span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- MINI STATS -->
    <div class="row g-3 mb-3">
        <?php foreach ($miniStats as [$label, $value, $note, $icon, $url]): ?>
        <div class="col-6 col-xl-3">
            <a href="<?php echo e($url); ?>" class="dx-card dx-mini">
                <span class="dx-ico"><i class="bi bi-<?php echo $icon; ?>"></i></span>
                <span class="min-w-0">
                    <span class="dx-mini-label d-block"><?php echo e($label); ?></span>
                    <span class="dx-mini-value d-block"><?php echo e((string)$value); ?></span>
                    <span class="dx-mini-note d-block"><?php echo e($note); ?></span>
                </span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- TREND + PAYMENT MODES -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h2 class="dx-card-title">Sales vs Collections</h2>
                        <p class="dx-card-sub"><?php echo $byMonth ? 'Monthly' : 'Daily'; ?> · <?php echo e($periodLabel); ?></p>
                    </div>
                    <div class="dx-legend">
                        <span><i style="background:#2563eb"></i>Sales</span>
                        <span><i style="background:#16a34a"></i>Collected</span>
                    </div>
                </div>
                <div class="dx-chart"><canvas id="dxTrend"></canvas></div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h2 class="dx-card-title">Payment Modes</h2>
                        <p class="dx-card-sub">Collections in this period</p>
                    </div>
                    <a href="payment.php" class="dx-link">Payments</a>
                </div>
                <?php if (!$modes): ?>
                    <div class="dx-empty"><i class="bi bi-wallet2"></i>No payments received in this period.</div>
                <?php else: ?>
                    <div class="dx-donut">
                        <canvas id="dxModes"></canvas>
                        <div class="dx-donut-center"><strong><?php echo e(rs($collected)); ?></strong><span>Collected</span></div>
                    </div>
                    <div class="dx-rows">
                        <?php foreach ($modes as $i => $m): ?>
                            <div class="dx-row">
                                <span class="dx-swatch" style="background:<?php echo $modeColors[$i % count($modeColors)]; ?>"></span>
                                <span class="dx-row-name"><?php echo e($m['mode']); ?></span>
                                <span class="dx-row-val"><?php echo e(rs((float)$m['total'])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TOP PRODUCTS / TOP CUSTOMERS / RECEIVABLES AGEING -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <h2 class="dx-card-title">Top Products</h2>
                    <a href="reports.php" class="dx-link">Reports</a>
                </div>
                <div class="dx-card-pad pt-2">
                    <?php if (!$topProducts): ?>
                        <div class="dx-empty"><i class="bi bi-box-seam"></i>No sales in this period.</div>
                    <?php endif; ?>
                    <?php $max = $topProducts ? max(array_map(fn($p) => (float)$p['sales'], $topProducts)) : 0; ?>
                    <?php foreach ($topProducts as $i => $p): ?>
                        <div class="dx-rank">
                            <span class="dx-rank-no"><?php echo $i + 1; ?></span>
                            <div class="dx-rank-body">
                                <div class="dx-rank-name" title="<?php echo e($p['name']); ?>"><?php echo e($p['name']); ?></div>
                                <div class="dx-bar"><span style="width:<?php echo $max > 0 ? max(4, round((float)$p['sales'] / $max * 100)) : 0; ?>%"></span></div>
                                <div class="dx-rank-meta">Qty <?php echo e(rtrim(rtrim(number_format((float)$p['qty'], 2, '.', ''), '0'), '.')); ?></div>
                            </div>
                            <span class="dx-rank-val"><?php echo e(rs((float)$p['sales'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <h2 class="dx-card-title">Top Customers</h2>
                    <a href="customers.php" class="dx-link">Customers</a>
                </div>
                <div class="dx-card-pad pt-2">
                    <?php if (!$topCustomers): ?>
                        <div class="dx-empty"><i class="bi bi-people"></i>No sales in this period.</div>
                    <?php endif; ?>
                    <?php foreach ($topCustomers as $i => $c): ?>
                        <div class="dx-rank">
                            <span class="dx-rank-no"><?php echo $i + 1; ?></span>
                            <div class="dx-rank-body">
                                <div class="dx-rank-name" title="<?php echo e($c['customer']); ?>"><?php echo e($c['customer']); ?></div>
                                <div class="dx-bar green"><span style="width:<?php echo $sales > 0 ? max(4, round((float)$c['sales'] / $sales * 100)) : 0; ?>%"></span></div>
                                <div class="dx-rank-meta"><?php echo (int)$c['invoices']; ?> invoice<?php echo (int)$c['invoices'] === 1 ? '' : 's'; ?> · <?php echo $sales > 0 ? number_format((float)$c['sales'] / $sales * 100, 1) : '0'; ?>% of sales</div>
                            </div>
                            <span class="dx-rank-val"><?php echo e(rs((float)$c['sales'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <div>
                        <h2 class="dx-card-title">Receivables Ageing</h2>
                        <p class="dx-card-sub"><?php echo e(rs($receivable)); ?> due from <?php echo count($dueCustomers); ?> customer<?php echo count($dueCustomers) === 1 ? '' : 's'; ?></p>
                    </div>
                    <a href="invoices.php" class="dx-link">Invoices</a>
                </div>
                <?php
                $agingColors = ['#22c55e', '#facc15', '#fb923c', '#f87171', '#b91c1c'];
                ?>
                <?php if ($receivable <= 0): ?>
                    <div class="dx-empty"><i class="bi bi-check2-circle text-success"></i>All invoices are fully paid.</div>
                <?php else: ?>
                    <div class="dx-aging">
                        <?php $n = 0; foreach ($aging as $label => $amt): ?>
                            <?php if ($amt > 0): ?><span style="width:<?php echo $amt / $receivable * 100; ?>%;background:<?php echo $agingColors[$n]; ?>" title="<?php echo e($label . ': ' . rs($amt)); ?>"></span><?php endif; ?>
                        <?php $n++; endforeach; ?>
                    </div>
                    <div class="dx-rows">
                        <?php $n = 0; foreach ($aging as $label => $amt): ?>
                            <div class="dx-row">
                                <span class="dx-swatch" style="background:<?php echo $agingColors[$n++]; ?>"></span>
                                <span class="dx-row-name"><?php echo e($label === 'Not due' ? 'Not yet due' : 'Overdue ' . $label); ?></span>
                                <span class="dx-row-val"><?php echo e(rs($amt)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RECENT INVOICES + ACTIVITY -->
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="dx-card overflow-hidden">
                <div class="dx-card-head pb-3">
                    <h2 class="dx-card-title">Recent Invoices</h2>
                    <a href="invoices.php" class="dx-link">View all</a>
                </div>
                <?php if (!$recentInvoices): ?>
                    <div class="dx-empty"><i class="bi bi-receipt"></i>No invoices yet. <a href="direct-invoice.php" class="dx-link">Create one</a></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="dx-table">
                        <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentInvoices as $inv): ?>
                            <tr>
                                <td><a href="invoice-print.php?id=<?php echo (int)$inv['id']; ?>"><?php echo e($inv['invoice_number']); ?></a></td>
                                <td><?php echo e($inv['customer']); ?></td>
                                <td class="text-nowrap"><?php echo e(date('d M Y', strtotime($inv['date']))); ?></td>
                                <td class="text-end fw-semibold text-nowrap"><?php echo e(rs((float)$inv['amount'])); ?></td>
                                <td><span class="dx-pill <?php echo $statusTone($inv['status']); ?>"><?php echo e($inv['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="dx-card">
                <div class="dx-card-head">
                    <h2 class="dx-card-title">Recent Activity</h2>
                </div>
                <div class="dx-feed">
                    <?php if (!$activities): ?>
                        <div class="dx-empty"><i class="bi bi-activity"></i>No activity yet.</div>
                    <?php endif; ?>
                    <?php foreach ($activities as $a): ?>
                        <div class="dx-feed-item">
                            <span class="dx-feed-ico"><i class="bi bi-<?php echo $moduleIcon($a['module']); ?>"></i></span>
                            <div class="min-w-0">
                                <div class="dx-feed-text"><?php echo e($a['action']); ?></div>
                                <div class="dx-feed-meta">
                                    <?php echo e($a['module']); ?> · <?php echo e(date('d M, h:i a', strtotime($a['created_at']))); ?>
                                    <?php if ($a['user_name']): ?> · <?php echo e($a['user_name']); ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</section>

<script>
(function () {
    "use strict";

    // Custom period picker
    const toggle = document.getElementById("dxCustomToggle");
    toggle.addEventListener("click", e => {
        e.preventDefault();
        document.getElementById("dxCustom").classList.toggle("show");
    });

    const money = v => "₹" + Number(v).toLocaleString("en-IN", { maximumFractionDigits: 0 });
    const short = v => v >= 1e7 ? (v / 1e7).toFixed(1) + "Cr" : v >= 1e5 ? (v / 1e5).toFixed(1) + "L" : v >= 1e3 ? (v / 1e3).toFixed(0) + "K" : v;
    Chart.defaults.font.family = "Inter, 'Segoe UI', sans-serif";
    Chart.defaults.color = "#8391a7";

    // Sales vs Collections
    const sales = <?php echo json_encode($trendSales); ?>;
    const paid = <?php echo json_encode($trendPaid); ?>;
    const ctx = document.getElementById("dxTrend");
    const grad = ctx.getContext("2d").createLinearGradient(0, 0, 0, 260);
    grad.addColorStop(0, "rgba(22,163,74,.18)");
    grad.addColorStop(1, "rgba(22,163,74,0)");
    new Chart(ctx, {
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [
                { type: "bar", label: "Sales", data: sales, backgroundColor: "#2563eb", borderRadius: 6, maxBarThickness: 28, order: 2 },
                { type: "line", label: "Collected", data: paid, borderColor: "#16a34a", backgroundColor: grad, fill: true, tension: .35, borderWidth: 2.5,
                  pointRadius: sales.length > 31 ? 0 : 3, pointBackgroundColor: "#fff", pointBorderWidth: 2, order: 1 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: "index", intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { padding: 10, cornerRadius: 10, callbacks: { label: c => " " + c.dataset.label + ": " + money(c.parsed.y) } }
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                y: { beginAtZero: true, suggestedMax: Math.max(...sales, ...paid) > 0 ? undefined : 1000,
                     ticks: { callback: v => "₹" + short(v) }, grid: { color: "#eef2f7" }, border: { display: false } }
            }
        }
    });

    // Payment modes
    const modesEl = document.getElementById("dxModes");
    if (modesEl) {
        new Chart(modesEl, {
            type: "doughnut",
            data: {
                labels: <?php echo json_encode(array_column($modes, 'mode')); ?>,
                datasets: [{ data: <?php echo json_encode(array_map('floatval', array_column($modes, 'total'))); ?>,
                             backgroundColor: <?php echo json_encode(array_slice($modeColors, 0, max(1, count($modes)))); ?>, borderWidth: 0, hoverOffset: 4 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: "72%",
                       plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => " " + c.label + ": " + money(c.parsed) } } } }
        });
    }
})();
</script>

<?php include 'footer.php'; ?>
