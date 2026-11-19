<?php
/*
|--------------------------------------------------------------------------
| DASHBOARD - all figures are calculated live from MySQL
|--------------------------------------------------------------------------
| Sales  = invoices (not Draft / Cancelled)
| Pending = invoice amount - payments received
| Stock value = product stock x price
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$selected = $_GET['date'] ?? date('Y-m-d');
$selDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selected) ?: new DateTimeImmutable('today');
$selected = $selDate->format('Y-m-d');

$valid = "status NOT IN ('Draft','Cancelled')";

function salesBetween(string $from, string $to): float
{
    return (float)db_value(
        "SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status NOT IN ('Draft','Cancelled') AND date BETWEEN ? AND ?",
        [$from, $to]
    );
}

function pctChange(float $now, float $before): string
{
    if ($before == 0.0) {
        return $now > 0 ? '+100%' : '+0%';
    }
    $p = ($now - $before) / $before * 100;
    return ($p >= 0 ? '+' : '') . number_format($p, 1) . '%';
}

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
    return ($neg ? '-' : '') . 'Rs ' . $last3;
}

$yesterday = $selDate->modify('-1 day')->format('Y-m-d');
$monthStart = $selDate->format('Y-m-01');
$monthEnd = $selDate->format('Y-m-t');
$lastMonthStart = $selDate->modify('first day of last month')->format('Y-m-d');
$lastMonthEnd = $selDate->modify('last day of last month')->format('Y-m-d');

$salesToday = salesBetween($selected, $selected);
$salesYesterday = salesBetween($yesterday, $yesterday);
$salesMonth = salesBetween($monthStart, $monthEnd);
$salesLastMonth = salesBetween($lastMonthStart, $lastMonthEnd);

$totalInvoiced = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE $valid");
$totalPaid = (float)db_value("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.invoice_number = p.invoice WHERE i.$valid");
$pendingAmount = max(0, $totalInvoiced - $totalPaid);
$pendingCustomers = (int)db_value(
    "SELECT COUNT(DISTINCT i.customer) FROM invoices i
     WHERE i.$valid AND i.amount > COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice = i.invoice_number), 0)"
);

$stockValue = (float)db_value('SELECT COALESCE(SUM(stock * price),0) FROM products');

/* Sales overview: 5-day buckets of the selected month */
$overviewLabels = [];
$overviewData = [];
$daysInMonth = (int)$selDate->format('t');
$monthLabel = $selDate->format('M');
// Buckets: 1-5, 6-10, 11-15, 16-20, 21-25, 26-end of month.
foreach ([1, 6, 11, 16, 21, 26] as $start) {
    $end = $start === 26 ? $daysInMonth : $start + 4;
    $overviewLabels[] = "$start - $end $monthLabel";
    $overviewData[] = salesBetween(
        $selDate->format('Y-m-') . str_pad((string)$start, 2, '0', STR_PAD_LEFT),
        $selDate->format('Y-m-') . str_pad((string)$end, 2, '0', STR_PAD_LEFT)
    );
}

/* Top selling products */
$topProducts = db_all(
    "SELECT oi.name, SUM(oi.quantity) AS qty, SUM(oi.amount) AS sales
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status <> 'Cancelled'
     GROUP BY oi.name ORDER BY sales DESC LIMIT 5"
);

/* Recent activities */
$activities = db_all('SELECT user_name, module, action, created_at FROM activity_log WHERE module <> \'Auth\' ORDER BY id DESC LIMIT 5');

/* Sales summary: week (Mon-Sun) of the selected date */
$weekStart = $selDate->modify('monday this week');
$weekDays = [];
$weekTotal = 0;
for ($i = 0; $i < 7; $i++) {
    $d = $weekStart->modify("+$i day");
    $v = salesBetween($d->format('Y-m-d'), $d->format('Y-m-d'));
    $weekDays[] = ['label' => $d->format('D'), 'value' => $v];
    $weekTotal += $v;
}
$weekColors = ['#2476f2', '#20bd7e', '#ffb22a', '#7360df', '#16b7bf', '#ec5861', '#855ef4'];

/* Stock status */
$stockCounts = db_one(
    'SELECT COUNT(*) AS total,
            SUM(stock > 0 AND stock <= low_stock_alert) AS low,
            SUM(stock <= 0) AS out_of_stock,
            SUM(stock > low_stock_alert) AS in_stock
     FROM products'
);
$totalProducts = (int)$stockCounts['total'];
$lowStock = (int)$stockCounts['low'];
$outOfStock = (int)$stockCounts['out_of_stock'];
$inStock = (int)$stockCounts['in_stock'];

/* Top customers */
$topCustomers = db_all(
    "SELECT customer, SUM(amount) AS sales FROM invoices WHERE $valid GROUP BY customer ORDER BY sales DESC LIMIT 5"
);

$monthChange = pctChange($salesMonth, $salesLastMonth);
$isDown = $salesMonth < $salesLastMonth;

$pageTitle = 'Dashboard';
include 'header.php';
?>

<!-- MAIN DASHBOARD SECTION -->
<section class="dashboard-section">
<div class="container-fluid">

  <!-- HEADER -->
  <div class="row align-items-center mb-3 g-3">
    <div class="col-lg-8">
      <h1 class="dashboard-title">Dashboard</h1>
      <p class="dashboard-subtitle">
        Welcome back, <?php echo e($_SESSION['user_name'] ?? 'Admin'); ?>! Here's what's happening
        in your business today.
      </p>
    </div>

    <div class="col-lg-4 text-lg-end">
      <button class="date-button" id="dashboardDateButton" type="button">
        <i class="bi bi-calendar3 me-2"></i>
        <span id="dashboardDateText">
          <?php echo e($selDate->format('l, j M Y')); ?>
        </span>
        <i class="bi bi-chevron-down ms-2"></i>
      </button>
      <input type="date" id="dashboardDateInput"
             value="<?php echo e($selected); ?>"
             class="visually-hidden">
    </div>
  </div>

  <!-- TOP 4 STATISTICS -->
  <div class="row g-3 mb-3">

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="dashboard-card stat-card">
        <div class="stat-icon bg-soft-blue">
          <i class="bi bi-cash-stack"></i>
        </div>
        <div>
          <div class="stat-label">Sales (<?php echo $selected === date('Y-m-d') ? 'Today' : e($selDate->format('d M')); ?>)</div>
          <div class="stat-value"><?php echo rs($salesToday); ?></div>
          <p class="stat-change<?php echo $salesToday < $salesYesterday ? ' down' : ''; ?>"><?php echo pctChange($salesToday, $salesYesterday); ?> vs Previous Day</p>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="dashboard-card stat-card">
        <div class="stat-icon bg-soft-green">
          <i class="bi bi-graph-up-arrow"></i>
        </div>
        <div>
          <div class="stat-label">Sales (This Month)</div>
          <div class="stat-value"><?php echo rs($salesMonth); ?></div>
          <p class="stat-change<?php echo $isDown ? ' down' : ''; ?>"><?php echo $monthChange; ?> vs Last Month</p>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="dashboard-card stat-card">
        <div class="stat-icon bg-soft-orange">
          <i class="bi bi-clock-history"></i>
        </div>
        <div>
          <div class="stat-label">Pending Amount</div>
          <div class="stat-value"><?php echo rs($pendingAmount); ?></div>
          <p class="stat-change">From <?php echo $pendingCustomers; ?> customer(s)</p>
        </div>
      </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <div class="dashboard-card stat-card">
        <div class="stat-icon bg-soft-purple">
          <i class="bi bi-box-seam"></i>
        </div>
        <div>
          <div class="stat-label">Stock Value</div>
          <div class="stat-value"><?php echo rs($stockValue); ?></div>
          <p class="stat-change"><?php echo $totalProducts; ?> products</p>
        </div>
      </div>
    </div>

  </div>

  <!-- SALES OVERVIEW / PRODUCTS / ACTIVITIES -->
  <div class="row g-3 mb-3">

    <!-- Sales Overview -->
    <div class="col-12 col-xl-5">
      <div class="dashboard-card panel-card">
        <div class="panel-head">
          <h2 class="panel-title">Sales Overview</h2>
          <a href="reports.php" class="panel-link">
            <?php echo e($selDate->format('M Y')); ?>
          </a>
        </div>

        <div class="sales-chart">
          <canvas id="salesOverviewChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Top Selling Products -->
    <div class="col-12 col-md-6 col-xl-4">
      <div class="dashboard-card panel-card"
           id="top-selling-products">

        <div class="panel-head">
          <h2 class="panel-title">Top Selling Products</h2>
          <a href="reports.php"
             class="panel-link">View All</a>
        </div>

        <?php if (!$topProducts): ?>
          <p class="empty-message">No sales yet.</p>
        <?php endif; ?>

        <?php foreach ($topProducts as $i => $p): ?>
        <div class="product-item">
          <div class="product-rank"><?php echo $i + 1; ?></div>
          <div>
            <div class="product-name" title="<?php echo e($p['name']); ?>"><?php echo e($p['name']); ?></div>
            <div class="product-qty">Qty: <?php echo e(rtrim(rtrim(number_format((float)$p['qty'], 2, '.', ''), '0'), '.')); ?></div>
          </div>
          <div class="product-price"><?php echo rs((float)$p['sales']); ?></div>
        </div>
        <?php endforeach; ?>

      </div>
    </div>

    <!-- Recent Activities -->
    <div class="col-12 col-md-6 col-xl-3">
      <div class="dashboard-card panel-card"
           id="recent-activities">

        <div class="panel-head">
          <h2 class="panel-title">Recent Activities</h2>
        </div>

        <?php if (!$activities): ?>
        <p class="empty-message">
          No recent activities found.
        </p>
        <?php endif; ?>

        <?php foreach ($activities as $a): ?>
          <div class="activity-item">
            <span class="activity-dot"></span>
            <div>
            <div class="activity-action"><?php echo e($a['action']); ?></div>
            <div class="activity-meta">
              <?php echo e($a['module']); ?> &middot;
              <?php echo e(date('d M, h:i a', strtotime($a['created_at']))); ?>
              <?php if ($a['user_name']): ?>&middot; <?php echo e($a['user_name']); ?><?php endif; ?>
            </div>
            </div>
          </div>
        <?php endforeach; ?>

      </div>
    </div>

  </div>

  <!-- SUMMARY ROW -->
  <div class="row g-3 mb-3">

    <!-- SALES SUMMARY -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card summary-card"
           id="sales-summary">

        <div class="panel-head">
          <h2 class="panel-title">Sales Summary</h2>
          <span class="panel-link">
            This Week
          </span>
        </div>

        <div class="donut-container">

          <div class="donut-chart">
            <canvas id="salesSummaryChart"></canvas>
            <div class="donut-center">
              <strong><?php echo rs($weekTotal); ?></strong>
              <span>Total Sales</span>
            </div>
          </div>

          <div class="sales-legend">
            <?php foreach ($weekDays as $i => $d): ?>
            <div class="legend-item">
              <span class="legend-dot"
                    style="background:<?php echo $weekColors[$i]; ?>"></span>
              <?php echo e($d['label']); ?> <strong><?php echo rs($d['value']); ?></strong>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- STOCK STATUS -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card summary-card" id="stock-status">

        <div class="panel-head">
          <h2 class="panel-title">Stock Status</h2>
          <a href="products.php" class="panel-link">
            View All
          </a>
        </div>

        <div class="stock-grid">

          <div class="stock-box stock-green">
            <div class="stock-icon">
              <i class="bi bi-boxes"></i>
            </div>
            <div>
              <div class="stock-label">Total Products</div>
              <div class="stock-value"><?php echo $totalProducts; ?></div>
            </div>
          </div>

          <div class="stock-box stock-orange">
            <div class="stock-icon">
              <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div>
              <div class="stock-label">Low Stock</div>
              <div class="stock-value"><?php echo $lowStock; ?></div>
            </div>
          </div>

          <div class="stock-box stock-red">
            <div class="stock-icon">
              <i class="bi bi-x-circle-fill"></i>
            </div>
            <div>
              <div class="stock-label">Out of Stock</div>
              <div class="stock-value"><?php echo $outOfStock; ?></div>
            </div>
          </div>

          <div class="stock-box stock-blue">
            <div class="stock-icon">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <div>
              <div class="stock-label">In Stock</div>
              <div class="stock-value"><?php echo $inStock; ?></div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- TOP CUSTOMERS -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card summary-card"
           id="top-customers">

        <div class="panel-head">
          <h2 class="panel-title">Top Customers</h2>
          <a href="customers.php" class="panel-link">
            View All
          </a>
        </div>

        <?php if (!$topCustomers): ?>
          <p class="empty-message">No sales yet.</p>
        <?php endif; ?>

        <?php foreach ($topCustomers as $c): ?>
        <div class="customer-item">
          <div class="customer-icon">
            <i class="bi bi-person-fill"></i>
          </div>

          <span class="customer-name" title="<?php echo e($c['customer']); ?>"><?php echo e($c['customer']); ?></span>

          <strong class="customer-amount">
            <?php echo rs((float)$c['sales']); ?>
          </strong>

          <span class="customer-percentage">
            <?php echo $totalInvoiced > 0 ? number_format((float)$c['sales'] / $totalInvoiced * 100, 1) : '0.0'; ?>%
          </span>
        </div>
        <?php endforeach; ?>

      </div>
    </div>

  </div>

  <!-- BOTTOM 3 INSIGHT CARDS -->
  <div class="row g-3">

    <!-- Business at a Glance -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card insight-card">

        <div class="insight-icon <?php echo $isDown ? 'bg-soft-purple' : 'bg-soft-green'; ?>">
          <i class="bi bi-graph-<?php echo $isDown ? 'down' : 'up'; ?>-arrow"></i>
        </div>

        <div>
          <h3 class="insight-title">
            Business at a Glance
          </h3>
          <p class="insight-text">
            <?php if ($isDown): ?>
              Your business is down; this month's sales changed by <?php echo $monthChange; ?> vs last month.
            <?php else: ?>
              Your business is up; this month's sales changed by <?php echo $monthChange; ?> vs last month.
            <?php endif; ?>
          </p>
        </div>

      </div>
    </div>

    <!-- Pending Collection -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card insight-card">

        <div class="insight-icon bg-soft-orange">
          <i class="bi bi-cash-stack"></i>
        </div>

        <div>
          <h3 class="insight-title">
            Pending Collection
          </h3>
          <p class="insight-text">
            You have <?php echo rs($pendingAmount); ?> pending from
            <?php echo $pendingCustomers; ?> customer(s).
          </p>
          <a href="payment.php" class="panel-link">Record Payment -&gt;</a>
        </div>

      </div>
    </div>

    <!-- Low Stock Alert -->
    <div class="col-12 col-xl-4">
      <div class="dashboard-card insight-card"
           id="low-stock-alert">

        <div class="insight-icon bg-soft-red">
          <i class="bi bi-exclamation-triangle-fill"></i>
        </div>

        <div>
          <h3 class="insight-title">
            Low Stock Alert
          </h3>
          <p class="insight-text">
            <?php echo $lowStock + $outOfStock; ?> products are low or out of stock.
          </p>
          <a href="products.php"
             class="panel-link">
            View Low Stock Products -&gt;
          </a>
        </div>

      </div>
    </div>

  </div>

</div>
</section>


<script>
(function () {
  "use strict";

  // Date Selector -> reloads dashboard for that date
  const dateButton = document.getElementById('dashboardDateButton');
  const dateInput = document.getElementById('dashboardDateInput');

  dateButton.addEventListener('click', function() {
    if (dateInput.showPicker) {
      dateInput.showPicker();
    } else {
      dateInput.click();
    }
  });

  dateInput.addEventListener('change', function() {
    if (!this.value) return;
    window.location.href = 'dashboard.php?date=' + encodeURIComponent(this.value);
  });

  const money = v => 'Rs ' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });

  // Sales Overview Chart
  const overview = <?php echo json_encode($overviewData); ?>;
  new Chart(
    document.getElementById('salesOverviewChart'),
    {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($overviewLabels); ?>,
        datasets: [{
          data: overview,
          backgroundColor: '#347ef4',
          borderRadius: 5,
          minBarLength: 5,
          barPercentage: 0.95
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => money(ctx.parsed.y) } }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false }
          },
          y: {
            beginAtZero: true,
            suggestedMax: Math.max(...overview) > 0 ? undefined : 1.5,
            ticks: { callback: value => Number(value).toLocaleString('en-IN') },
            grid: { color: '#e5eaf0' },
            border: { display: false }
          }
        }
      }
    }
  );

  // Sales Summary Donut Chart (equal segments when there are no sales)
  const week = <?php echo json_encode(array_column($weekDays, 'value')); ?>;
  const hasSales = week.some(v => v > 0);
  new Chart(
    document.getElementById('salesSummaryChart'),
    {
      type: 'doughnut',
      data: {
        labels: <?php echo json_encode(array_column($weekDays, 'label')); ?>,
        datasets: [{
          data: hasSales ? week : [1, 1, 1, 1, 1, 1, 1],
          backgroundColor: <?php echo json_encode($weekColors); ?>,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: {
            enabled: hasSales,
            callbacks: { label: ctx => ctx.label + ': ' + money(ctx.parsed) }
          }
        }
      }
    }
  );
})();
</script>


<?php include 'footer.php'; ?>
