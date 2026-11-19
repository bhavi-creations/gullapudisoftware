<?php
/*
|--------------------------------------------------------------------------
| REPORTS PAGE - all data from MySQL
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/includes/init.php';

$today = date('Y-m-d');

$customers = cast_rows(db_all('SELECT id, name FROM customers ORDER BY name'));

$orders = cast_rows(
    db_all("SELECT id, date, COALESCE(customer_id, 0) AS customer_id, amount, status
            FROM orders WHERE status <> 'Cancelled' ORDER BY date"),
    [],
    ['amount']
);
foreach ($orders as &$o) {
    $o['customer_id'] = (int)$o['customer_id'];
}
unset($o);

$payments = cast_rows(
    db_all('SELECT id, date, COALESCE(customer_id, 0) AS customer_id, amount FROM payments ORDER BY date'),
    [],
    ['amount']
);
foreach ($payments as &$p) {
    $p['customer_id'] = (int)$p['customer_id'];
}
unset($p);

$products = cast_rows(
    db_all("SELECT id, name, COALESCE(category, 'Uncategorized') AS category FROM products ORDER BY name")
);

$salesItems = [];
foreach (db_all("SELECT o.date, COALESCE(o.customer_id, 0) AS customer_id, COALESCE(oi.product_id, 0) AS product_id,
                        oi.quantity, oi.amount
                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE o.status <> 'Cancelled'") as $row) {
    $salesItems[] = [
        'date' => $row['date'],
        'customer_id' => (int)$row['customer_id'],
        'product_id' => (int)$row['product_id'],
        'quantity' => (float)$row['quantity'],
        'sales' => (float)$row['amount'],
    ];
}

$pageTitle = 'Reports';
include 'header.php';
?>



<!-- ======================================
     REPORT MAIN SECTION
====================================== -->

<section class="report-section">

<div class="container-fluid p-0">

    <!-- HEADER AND FILTERS -->
    <div class="row g-3 align-items-end mb-3">

        <div class="col-12 col-xl-5">

            <h1 class="rp-title">Reports</h1>

            <p class="rp-subtitle">
                Analyze your business performance with insightful reports
            </p>

        </div>

        <div class="col-12 col-xl-7">

            <div class="rp-filters">

                <!-- START DATE -->
                <div class="rp-filter-group">

                    <label for="reportStartDate"
                           class="rp-filter-label">
                        Start Date
                    </label>

                    <input type="date"
                           id="reportStartDate"
                           class="rp-filter-input"
                           value="<?php echo $today; ?>">

                </div>

                <!-- END DATE -->
                <div class="rp-filter-group">

                    <label for="reportEndDate"
                           class="rp-filter-label">
                        End Date
                    </label>

                    <input type="date"
                           id="reportEndDate"
                           class="rp-filter-input"
                           value="<?php echo $today; ?>">

                </div>

                <!-- CUSTOMER -->
                <div class="rp-filter-group rp-filter-customer">

                    <label for="reportCustomer"
                           class="rp-filter-label">
                        Customer
                    </label>

                    <select id="reportCustomer"
                            class="rp-filter-input">

                        <option value="all">
                            All Customers
                        </option>

                        <?php foreach ($customers as $customer): ?>

                        <option value="<?php echo (int)$customer['id']; ?>">
                            <?php echo htmlspecialchars(
                                $customer['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                        </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- EXCEL EXPORT -->
                <button type="button"
                        class="rp-export-btn"
                        id="exportExcelBtn">

                    <i class="bi bi-file-earmark-excel"></i>
                    Export Excel

                </button>

            </div>

        </div>

    </div>

    <!-- ====================================
         BUSINESS OVERVIEW
    ==================================== -->

    <div class="rp-panel rp-business-panel">

        <div class="rp-panel-heading">

            <h2 class="rp-panel-title">
                Business Overview
            </h2>

            <span class="rp-panel-link"
                  id="selectedCustomerLabel">
                All Customers
            </span>

        </div>

        <div class="row g-2">

            <!-- TOTAL SALES -->
            <div class="col-12 col-sm-6 col-xl-3">

                <div class="rp-stat-card">

                    <div class="rp-stat-icon rp-blue">
                        <i class="bi bi-cash-stack"></i>
                    </div>

                    <div>

                        <div class="rp-stat-label">
                            Total Sales
                        </div>

                        <div class="rp-stat-value"
                             id="reportTotalSales">
                            Rs 0
                        </div>

                        <div class="rp-stat-note"
                             id="reportDateRange">
                        </div>

                    </div>

                </div>

            </div>

            <!-- PAYMENTS RECEIVED -->
            <div class="col-12 col-sm-6 col-xl-3">

                <div class="rp-stat-card">

                    <div class="rp-stat-icon rp-green">
                        <i class="bi bi-credit-card"></i>
                    </div>

                    <div>

                        <div class="rp-stat-label">
                            Payments Received
                        </div>

                        <div class="rp-stat-value"
                             id="reportPayments">
                            Rs 0
                        </div>

                        <div class="rp-stat-note"
                             id="reportCustomerNote">
                            All customers
                        </div>

                    </div>

                </div>

            </div>

            <!-- OUTSTANDING -->
            <div class="col-12 col-sm-6 col-xl-3">

                <div class="rp-stat-card">

                    <div class="rp-stat-icon rp-orange">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <div>

                        <div class="rp-stat-label">
                            Outstanding
                        </div>

                        <div class="rp-stat-value"
                             id="reportOutstanding">
                            Rs 0
                        </div>

                        <div class="rp-stat-note">
                            Sales minus receipts
                        </div>

                    </div>

                </div>

            </div>

            <!-- ORDERS -->
            <div class="col-12 col-sm-6 col-xl-3">

                <div class="rp-stat-card">

                    <div class="rp-stat-icon rp-purple">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>

                    <div>

                        <div class="rp-stat-label">
                            Orders
                        </div>

                        <div class="rp-stat-value"
                             id="reportOrders">
                            0
                        </div>

                        <div class="rp-stat-note">
                            Matching records
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ====================================
         SALES TREND
    ==================================== -->

    <div class="rp-panel rp-trend-panel">

        <div class="rp-panel-heading">

            <h2 class="rp-panel-title">
                Sales Trend
            </h2>

            <span class="rp-panel-link">
                5-day buckets
            </span>

        </div>

        <div class="rp-chart-wrapper">

            <canvas id="salesTrendChart"
                    aria-label="Sales trend chart"
                    role="img"></canvas>

        </div>

    </div>

    <!-- ====================================
         BOTTOM REPORT CARDS
    ==================================== -->

    <div class="row g-2 rp-bottom-row">

        <!-- TOP SELLING PRODUCTS -->
        <div class="col-12 col-lg-5">

            <div class="rp-panel rp-bottom-card">

                <div class="rp-panel-heading">

                    <h2 class="rp-panel-title">
                        Top Selling Products
                    </h2>

                </div>

                <div class="table-responsive">

                    <table class="rp-table">

                        <thead>
                            <tr>
                                <th>S No</th>
                                <th>Product</th>
                                <th>Qty Sold</th>
                                <th>Sales (Rs)</th>
                            </tr>
                        </thead>

                        <tbody id="topProductsBody">

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <!-- TOP CUSTOMERS -->
        <div class="col-12 col-lg-5">

            <div class="rp-panel rp-bottom-card">

                <div class="rp-panel-heading">

                    <h2 class="rp-panel-title">
                        Top Customers
                    </h2>

                </div>

                <div class="table-responsive">

                    <table class="rp-table">

                        <thead>
                            <tr>
                                <th>S No</th>
                                <th>Customer</th>
                                <th>Sales (Rs)</th>
                            </tr>
                        </thead>

                        <tbody id="topCustomersBody">

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <!-- CATEGORY SUMMARY -->
        <div class="col-12 col-lg-2">

            <div class="rp-panel rp-bottom-card">

                <div class="rp-panel-heading">

                    <h2 class="rp-panel-title">
                        Summary by Category
                    </h2>

                </div>

                <div class="rp-category-wrapper">

                    <div class="rp-category-donut">

                        <canvas id="categorySummaryChart"
                                aria-label="Category sales summary"
                                role="img"></canvas>

                        <div class="rp-category-center">

                            <span class="rp-category-label">
                                Total Sales
                            </span>

                            <strong class="rp-category-value"
                                    id="categoryTotalSales">
                                Rs 0
                            </strong>

                        </div>

                    </div>

                </div>

                <div id="categoryLegend"
                     class="small text-secondary mt-2">
                </div>

            </div>

        </div>

    </div>

</div>

</section>

<!-- BOOTSTRAP JS -->

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    "use strict";

    /*
     * PHP DATA
     */

    const customers = <?php echo json_encode(
        $customers,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const orders = <?php echo json_encode(
        $orders,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const payments = <?php echo json_encode(
        $payments,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const products = <?php echo json_encode(
        $products,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const salesItems = <?php echo json_encode(
        $salesItems,
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    const $ = id => document.getElementById(id);

    let salesChart = null;
    let categoryChart = null;
    let currentReport = null;

    /*
     * HELPERS
     */

    function formatMoney(value) {
        return "Rs " + Number(value).toLocaleString(
            "en-IN",
            { maximumFractionDigits: 2 }
        );
    }

    function formatDate(value) {

        if (!value) return "-";

        const [year, month, day] =
            value.split("-").map(Number);

        return new Date(year, month - 1, day)
            .toLocaleDateString("en-GB", {
                day: "2-digit",
                month: "short"
            });
    }

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function getCustomerName(id) {

        const customer = customers.find(
            item => Number(item.id) === Number(id)
        );

        return customer ? customer.name : "Unknown";
    }

    function getProduct(id) {
        return products.find(
            item => Number(item.id) === Number(id)
        );
    }

    function matches(item, from, to, customerId) {

        if (item.date < from || item.date > to) {
            return false;
        }

        if (
            customerId !== "all" &&
            Number(item.customer_id) !== Number(customerId)
        ) {
            return false;
        }

        return true;
    }

    function parseDay(value) {
        const [year, month, day] = value.split("-").map(Number);
        return new Date(Date.UTC(year, month - 1, day));
    }

    function isoDay(date) {
        return date.toISOString().slice(0, 10);
    }

    /*
     * CREATE 5-DAY SALES BUCKETS
     */

    function getSalesBuckets(from, to, filteredOrders) {

        const buckets = [];

        let cursor = parseDay(from);
        const end = parseDay(to);

        while (cursor <= end && buckets.length < 1000) {

            const bucketStart = new Date(cursor);

            const bucketEnd = new Date(cursor);
            bucketEnd.setUTCDate(bucketEnd.getUTCDate() + 4);

            if (bucketEnd > end) {
                bucketEnd.setTime(end.getTime());
            }

            const startISO = isoDay(bucketStart);
            const endISO = isoDay(bucketEnd);

            const amount = filteredOrders
                .filter(order =>
                    order.date >= startISO &&
                    order.date <= endISO
                )
                .reduce(
                    (sum, order) => sum + Number(order.amount),
                    0
                );

            const label = startISO === endISO
                ? formatDate(startISO)
                : formatDate(startISO) + " - " + formatDate(endISO);

            buckets.push({
                label: label,
                start: startISO,
                end: endISO,
                amount: amount
            });

            cursor = new Date(bucketEnd);
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }

        return buckets;
    }

    /*
     * BUILD REPORT DATA
     */

    function buildReport() {

        const from = $("reportStartDate").value;
        const to = $("reportEndDate").value;
        const customerId = $("reportCustomer").value;

        if (!from || !to || from > to) {
            alert("End Date must be on or after Start Date.");
            return null;
        }

        const daySpan =
            (parseDay(to) - parseDay(from)) / 86400000;

        if (daySpan > 4999) {
            alert("Please select a date range within 5000 days.");
            return null;
        }

        const filteredOrders = orders.filter(
            item => matches(item, from, to, customerId)
        );

        const filteredPayments = payments.filter(
            item => matches(item, from, to, customerId)
        );

        const filteredItems = salesItems.filter(
            item => matches(item, from, to, customerId)
        );

        const totalSales = filteredOrders.reduce(
            (sum, item) => sum + Number(item.amount),
            0
        );

        const paymentsReceived = filteredPayments.reduce(
            (sum, item) => sum + Number(item.amount),
            0
        );

        const outstanding = totalSales - paymentsReceived;

        /*
         * Top products use sales-line sample data.
         * The example line-item values are independent
         * of sample order totals.
         */

        const productMap = new Map();

        filteredItems.forEach(item => {

            const product = getProduct(item.product_id);
            const name = product ? product.name : "Unknown Product";

            if (!productMap.has(name)) {
                productMap.set(name, {
                    name: name,
                    quantity: 0,
                    sales: 0
                });
            }

            const record = productMap.get(name);

            record.quantity += Number(item.quantity);
            record.sales += Number(item.sales);
        });

        const topProducts = [...productMap.values()]
            .sort((a, b) => b.sales - a.sales);

        /*
         * Customer totals use the same order amounts
         * as the Business Overview.
         */

        const customerMap = new Map();

        filteredOrders.forEach(item => {

            const name = getCustomerName(item.customer_id);

            customerMap.set(
                name,
                (customerMap.get(name) || 0) + Number(item.amount)
            );
        });

        const topCustomers = [...customerMap.entries()]
            .map(([name, sales]) => ({ name, sales }))
            .sort((a, b) => b.sales - a.sales);

        /*
         * Category breakdown from product line items.
         */

        const categoryMap = new Map();

        filteredItems.forEach(item => {

            const product = getProduct(item.product_id);
            const category = product
                ? product.category
                : "Uncategorized";

            categoryMap.set(
                category,
                (categoryMap.get(category) || 0) +
                Number(item.sales)
            );
        });

        const categories = [...categoryMap.entries()]
            .map(([name, sales]) => ({ name, sales }));

        const buckets = getSalesBuckets(
            from,
            to,
            filteredOrders
        );

        const selectedCustomer = customerId === "all"
            ? "All Customers"
            : getCustomerName(customerId);

        return {
            from,
            to,
            customerId,
            selectedCustomer,
            totalSales,
            paymentsReceived,
            outstanding,
            orderCount: filteredOrders.length,
            topProducts,
            topCustomers,
            categories,
            buckets
        };
    }

    /*
     * RENDER TOP PRODUCTS
     */

    function renderTopProducts(data) {

        const tbody = $("topProductsBody");

        tbody.innerHTML = "";

        if (!data.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="rp-empty">
                        No products found
                    </td>
                </tr>
            `;
            return;
        }

        data.slice(0, 10).forEach((product, index) => {

            const tr = document.createElement("tr");

            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>${escapeHTML(product.name)}</td>
                <td>${product.quantity}</td>
                <td>${escapeHTML(formatMoney(product.sales))}</td>
            `;

            tbody.appendChild(tr);
        });
    }

    /*
     * RENDER TOP CUSTOMERS
     */

    function renderTopCustomers(data) {

        const tbody = $("topCustomersBody");

        tbody.innerHTML = "";

        if (!data.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="3" class="rp-empty">
                        No customers found
                    </td>
                </tr>
            `;
            return;
        }

        data.slice(0, 10).forEach((customer, index) => {

            const tr = document.createElement("tr");

            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>${escapeHTML(customer.name)}</td>
                <td>${escapeHTML(formatMoney(customer.sales))}</td>
            `;

            tbody.appendChild(tr);
        });
    }

    /*
     * SALES TREND CHART
     */

    function renderSalesChart(buckets) {

        const canvas = $("salesTrendChart");

        if (salesChart) {
            salesChart.destroy();
        }

        salesChart = new Chart(canvas, {

            type: "line",

            data: {
                labels: buckets.map(item => item.label),

                datasets: [
                    {
                        label: "Sales",
                        data: buckets.map(item => item.amount),
                        borderColor: "#287dff",
                        backgroundColor: "#287dff",
                        borderWidth: 3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: "#287dff",
                        tension: 0.25,
                        fill: false
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                interaction: {
                    intersect: false,
                    mode: "index"
                },

                plugins: {
                    legend: {
                        display: true,
                        position: "top",
                        align: "start",

                        labels: {
                            usePointStyle: true,
                            boxWidth: 9,
                            color: "#43536a",
                            font: {
                                size: 12,
                                weight: "600"
                            }
                        }
                    },

                    tooltip: {
                        backgroundColor: "#1e293b",
                        titleColor: "#ffffff",
                        bodyColor: "#ffffff",
                        padding: 12,
                        cornerRadius: 6,

                        callbacks: {
                            label: context =>
                                "Sales: " + formatMoney(context.parsed.y)
                        }
                    }
                },

                scales: {
                    x: {
                        grid: {
                            display: false
                        },

                        ticks: {
                            color: "#718096",
                            font: { size: 11 },
                            maxRotation: 0,
                            autoSkip: true
                        },

                        border: {
                            display: false
                        }
                    },

                    y: {
                        beginAtZero: true,

                        suggestedMax: Math.max(
                            1,
                            ...buckets.map(item => item.amount)
                        ),

                        grid: {
                            color: "#e7edf5"
                        },

                        ticks: {
                            color: "#718096",
                            font: { size: 11 },
                            callback: value =>
                                Number(value).toLocaleString("en-IN")
                        },

                        border: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    /*
     * CATEGORY DONUT
     */

    function renderCategoryChart(categories) {

        if (categoryChart) {
            categoryChart.destroy();
        }

        const total = categories.reduce(
            (sum, item) => sum + item.sales,
            0
        );

        const hasData = total > 0;

        $("categoryTotalSales").textContent =
            formatMoney(total);

        const labels = hasData
            ? categories.map(item => item.name)
            : ["No Sales"];

        const values = hasData
            ? categories.map(item => item.sales)
            : [1];

        const colors = hasData
            ? [
                "#287dff",
                "#20bc84",
                "#ffb02e",
                "#825feb",
                "#17b8c9",
                "#f46b73"
            ]
            : ["#e5edf8"];

        categoryChart = new Chart(
            $("categorySummaryChart"),
            {
                type: "doughnut",

                data: {
                    labels,
                    datasets: [
                        {
                            data: values,
                            backgroundColor: colors,
                            borderWidth: 0,
                            hoverOffset: hasData ? 5 : 0
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: "72%",

                    plugins: {
                        legend: {
                            display: false
                        },

                        tooltip: {
                            enabled: hasData,

                            callbacks: {
                                label: context =>
                                    context.label + ": " +
                                    formatMoney(context.parsed)
                            }
                        }
                    }
                }
            }
        );

        const legend = $("categoryLegend");
        legend.innerHTML = "";

        if (hasData) {
            categories.forEach((item, index) => {

                const line = document.createElement("div");
                line.className = "mb-1";

                const color = document.createElement("span");

                color.style.display = "inline-block";
                color.style.background = colors[index % colors.length];
                color.style.width = "8px";
                color.style.height = "8px";
                color.style.borderRadius = "50%";
                color.style.marginRight = "6px";

                line.appendChild(color);
                line.appendChild(
                    document.createTextNode(item.name)
                );

                legend.appendChild(line);
            });
        }
    }

    /*
     * UPDATE REPORT
     */

    function updateReport() {

        const report = buildReport();

        if (!report) return;

        currentReport = report;

        $("reportTotalSales").textContent =
            formatMoney(report.totalSales);

        $("reportPayments").textContent =
            formatMoney(report.paymentsReceived);

        $("reportOutstanding").textContent =
            formatMoney(report.outstanding);

        $("reportOrders").textContent =
            report.orderCount;

        $("reportDateRange").textContent =
            report.from + " to " + report.to;

        $("selectedCustomerLabel").textContent =
            report.selectedCustomer;

        $("reportCustomerNote").textContent =
            report.selectedCustomer;

        renderTopProducts(report.topProducts);
        renderTopCustomers(report.topCustomers);
        renderSalesChart(report.buckets);
        renderCategoryChart(report.categories);
    }

    /*
     * EXPORT TO EXCEL
     */

    function exportExcel() {

        if (!currentReport) return;

        if (typeof XLSX === "undefined") {
            alert("Excel export library is unavailable.");
            return;
        }

        const r = currentReport;

        const workbook = XLSX.utils.book_new();

        // Business Overview sheet
        const overviewData = [
            ["Business Performance Report"],
            ["Start Date", r.from],
            ["End Date", r.to],
            ["Customer", r.selectedCustomer],
            [],
            ["Metric", "Value"],
            ["Total Sales", r.totalSales],
            ["Payments Received", r.paymentsReceived],
            ["Outstanding", r.outstanding],
            ["Orders", r.orderCount]
        ];

        const overviewSheet = XLSX.utils.aoa_to_sheet(
            overviewData
        );

        overviewSheet["!cols"] = [
            { wch: 25 },
            { wch: 30 }
        ];

        XLSX.utils.book_append_sheet(
            workbook,
            overviewSheet,
            "Business Overview"
        );

        // Sales Trend sheet
        const salesData = r.buckets.map(item => ({
            "Start Date": item.start,
            "End Date": item.end,
            "Sales (Rs)": item.amount
        }));

        const salesSheet = XLSX.utils.json_to_sheet(
            salesData.length
                ? salesData
                : [{ "Start Date": "", "End Date": "", "Sales (Rs)": 0 }]
        );

        XLSX.utils.book_append_sheet(
            workbook,
            salesSheet,
            "Sales Trend"
        );

        // Top Products sheet
        const productRows = r.topProducts.map(
            (item, index) => ({
                "S No": index + 1,
                "Product": item.name,
                "Qty Sold": item.quantity,
                "Sales (Rs)": item.sales
            })
        );

        XLSX.utils.book_append_sheet(
            workbook,
            XLSX.utils.json_to_sheet(
                productRows.length
                    ? productRows
                    : [{ "S No": "", "Product": "No records", "Qty Sold": 0, "Sales (Rs)": 0 }]
            ),
            "Top Products"
        );

        // Top Customers sheet
        const customerRows = r.topCustomers.map(
            (item, index) => ({
                "S No": index + 1,
                "Customer": item.name,
                "Sales (Rs)": item.sales
            })
        );

        XLSX.utils.book_append_sheet(
            workbook,
            XLSX.utils.json_to_sheet(
                customerRows.length
                    ? customerRows
                    : [{ "S No": "", "Customer": "No records", "Sales (Rs)": 0 }]
            ),
            "Top Customers"
        );

        // Category Summary sheet
        const categoryRows = r.categories.map(item => ({
            "Category": item.name,
            "Sales (Rs)": item.sales
        }));

        XLSX.utils.book_append_sheet(
            workbook,
            XLSX.utils.json_to_sheet(
                categoryRows.length
                    ? categoryRows
                    : [{ "Category": "No records", "Sales (Rs)": 0 }]
            ),
            "Category Summary"
        );

        XLSX.writeFile(
            workbook,
            "Business_Report_" + r.from + "_to_" + r.to + ".xlsx"
        );
    }

    /*
     * FILTER EVENTS
     */

    $("reportStartDate").addEventListener(
        "change",
        updateReport
    );

    $("reportEndDate").addEventListener(
        "change",
        updateReport
    );

    $("reportCustomer").addEventListener(
        "change",
        updateReport
    );

    $("exportExcelBtn").addEventListener(
        "click",
        exportExcel
    );

    /*
     * INITIAL RENDER
     */

    updateReport();

})();
</script>




<?php include 'footer.php'; ?>