<?php
// products.php - products loaded from the MySQL database.
require_once __DIR__ . '/includes/init.php';

$products = cast_rows(
    db_all('SELECT id, name, thickness, size, store, category, price, stock, enabled, featured, hsn_code FROM products ORDER BY id DESC'),
    ['enabled', 'featured'],
    ['price', 'stock']
);

$pageTitle = 'Products';
include 'header.php';
?>



<!-- PRODUCTS MAIN SECTION -->
<section class="products-section">
<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-4">

        <div class="col-md-8">
            <h1 class="pr-title">Products</h1>

            <p class="pr-subtitle">
                Manage product catalog
            </p>
        </div>

        <div class="col-md-4 text-md-end">

            <!-- DIRECT REDIRECT TO ADD PRODUCT PAGE -->
            <a href="add-product.php" class="pr-add-btn">
                <i class="bi bi-plus-lg"></i>
                Add Product
            </a>

        </div>

    </div>

    <!-- 5 STATISTICS CARDS -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-5 g-3 mb-3">

        <!-- TOTAL PRODUCTS -->
        <div class="col">
            <div class="pr-stat-card">

                <div class="pr-stat-icon pr-purple">
                    <i class="bi bi-box"></i>
                </div>

                <div>
                    <div class="pr-stat-label">
                        Total Products
                    </div>

                    <div class="pr-stat-value" id="totalProducts">
                        2
                    </div>

                    <div class="pr-stat-note pr-text-green">
                        Active Products
                    </div>
                </div>

            </div>
        </div>

        <!-- TOTAL STOCK -->
        <div class="col">
            <div class="pr-stat-card">

                <div class="pr-stat-icon pr-green">
                    <i class="bi bi-box-seam"></i>
                </div>

                <div>
                    <div class="pr-stat-label">
                        Total Stock (Sheets)
                    </div>

                    <div class="pr-stat-value" id="totalStock">
                        1965
                    </div>

                    <div class="pr-stat-note">
                        Total Products in Stock
                    </div>
                </div>

            </div>
        </div>

        <!-- TOTAL STOCK VALUE -->
        <div class="col">
            <div class="pr-stat-card">

                <div class="pr-stat-icon pr-orange">
                    <i class="bi bi-database-fill"></i>
                </div>

                <div>
                    <div class="pr-stat-label">
                        Total Stock Value
                    </div>

                    <div class="pr-stat-value" id="totalStockValue">
                        Rs 35,17,200
                    </div>

                    <div class="pr-stat-note">
                        At Cost Price
                    </div>
                </div>

            </div>
        </div>

        <!-- LOW STOCK -->
        <div class="col">
            <div class="pr-stat-card">

                <div class="pr-stat-icon pr-blue">
                    <i class="bi bi-tag-fill"></i>
                </div>

                <div>
                    <div class="pr-stat-label">
                        Low Stock Products
                    </div>

                    <div class="pr-stat-value" id="lowStockCount">
                        0
                    </div>

                    <div class="pr-stat-note pr-text-red">
                        Needs Attention
                    </div>
                </div>

            </div>
        </div>

        <!-- OUT OF STOCK -->
        <div class="col">
            <div class="pr-stat-card">

                <div class="pr-stat-icon pr-red">
                    <i class="bi bi-slash-circle"></i>
                </div>

                <div>
                    <div class="pr-stat-label">
                        Out of Stock
                    </div>

                    <div class="pr-stat-value" id="outOfStockCount">
                        0
                    </div>

                    <div class="pr-stat-note pr-text-red">
                        Out of Stock Products
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- PRODUCT LIST PANEL -->
    <div class="pr-panel">

        <!-- TABS -->
        <div class="pr-tabs">

            <button class="pr-tab active" data-tab="all">
                All Products (<span id="allTabCount">2</span>)
            </button>

            <button class="pr-tab" data-tab="featured">
                Featured (<span id="featuredTabCount">0</span>)
            </button>

            <button class="pr-tab" data-tab="enabled">
                Enabled (<span id="enabledTabCount">2</span>)
            </button>

            <button class="pr-tab" data-tab="disabled">
                Disabled (<span id="disabledTabCount">0</span>)
            </button>

        </div>

        <!-- SEARCH AND FILTER -->
        <div class="pr-toolbar">

            <div class="pr-search-box">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    id="productSearch"
                    class="pr-search-input"
                    placeholder="Search by category name or code..."
                >
            </div>

            <!-- FILTER DROPDOWN -->
            <div class="dropdown">

                <button class="pr-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">

                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter

                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:245px">

                    <label class="form-label fw-bold">
                        Status
                    </label>

                    <select id="filterStatus"
                            class="form-select mb-3">

                        <option value="all">All Status</option>
                        <option value="enabled">Enabled</option>
                        <option value="disabled">Disabled</option>

                    </select>

                    <label class="form-label fw-bold">
                        Featured
                    </label>

                    <select id="filterFeatured"
                            class="form-select mb-3">

                        <option value="all">All Products</option>
                        <option value="yes">Featured</option>
                        <option value="no">Regular</option>

                    </select>

                    <button type="button"
                            id="resetFilters"
                            class="btn btn-outline-secondary w-100">
                        Reset Filters
                    </button>

                </div>

            </div>

            <button class="pr-tool-btn"
                    id="refreshProducts"
                    title="Refresh Products">

                <i class="bi bi-sliders2"></i>

            </button>

        </div>

        <!-- PRODUCT TABLE -->
        <div class="table-responsive">

            <table class="table pr-table">

                <thead>
                    <tr>
                        <th class="text-center">S No</th>
                        <th>Product Name</th>
                        <th>Thickness</th>
                        <th>Size</th>
                        <th>Store</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Enabled</th>
                        <th>Featured</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody id="productTableBody">

                    <!-- Rows rendered by JavaScript -->

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="pr-footer">

            <div id="paginationInfo">
                Showing 1 to 2 of 2 entries
            </div>

            <div class="pr-pagination">

                <button class="pr-page-btn"
                        id="firstPage">
                    &lt;&lt;
                </button>

                <button class="pr-page-btn"
                        id="prevPage">
                    &lt;
                </button>

                <button class="pr-page-btn active"
                        id="currentPage">
                    1
                </button>

                <button class="pr-page-btn"
                        id="nextPage">
                    &gt;
                </button>

                <button class="pr-page-btn"
                        id="lastPage">
                    &gt;&gt;
                </button>

                <select class="pr-page-size"
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

<!-- VIEW PRODUCT MODAL -->
<div class="modal fade"
     id="viewProductModal"
     tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    Product Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="viewProductContent">
            </div>

        </div>
    </div>
</div>

<!-- BOOTSTRAP JS -->

<script>
(function () {
    "use strict";

    let products = <?php
        echo json_encode(
            $products,
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        );
    ?>;

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    const $ = id => document.getElementById(id);

    const viewModal = new bootstrap.Modal(
        $("viewProductModal")
    );

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function formatNumber(number) {
        return Number(number).toLocaleString("en-IN");
    }

    // Calculate statistics.
    function updateStats() {

        const total = products.length;

        const enabled = products.filter(
            product => product.enabled
        ).length;

        const featured = products.filter(
            product => product.featured
        ).length;

        const totalStock = products.reduce(
            (sum, product) => sum + Number(product.stock),
            0
        );

        const lowStock = products.filter(
            product => product.stock > 0 && product.stock <= 10
        ).length;

        const outOfStock = products.filter(
            product => product.stock <= 0
        ).length;

        $("totalProducts").textContent = total;
        $("totalStock").textContent = formatNumber(totalStock);

        // Stock value = stock quantity x product price.
        const stockValue = products.reduce(
            (sum, product) => sum + Number(product.stock) * Number(product.price || 0),
            0
        );
        $("totalStockValue").textContent =
            "Rs " + formatNumber(Math.round(stockValue));

        $("lowStockCount").textContent = lowStock;
        $("outOfStockCount").textContent = outOfStock;

        $("allTabCount").textContent = total;
        $("featuredTabCount").textContent = featured;
        $("enabledTabCount").textContent = enabled;
        $("disabledTabCount").textContent = total - enabled;
    }

    // Filter products.
    function getFilteredProducts() {

        const search = $("productSearch")
            .value.toLowerCase().trim();

        const status = $("filterStatus").value;
        const featured = $("filterFeatured").value;

        return products.filter(product => {

            if (activeTab === "featured" && !product.featured)
                return false;

            if (activeTab === "enabled" && !product.enabled)
                return false;

            if (activeTab === "disabled" && product.enabled)
                return false;

            if (status === "enabled" && !product.enabled)
                return false;

            if (status === "disabled" && product.enabled)
                return false;

            if (featured === "yes" && !product.featured)
                return false;

            if (featured === "no" && product.featured)
                return false;

            const text = [
                product.name,
                product.thickness,
                product.size,
                product.store,
                product.id
            ].join(" ").toLowerCase();

            return text.includes(search);
        });
    }

    // Render products table.
    function renderProducts() {

        updateStats();

        const filtered = getFilteredProducts();

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

        const tbody = $("productTableBody");

        tbody.innerHTML = "";

        if (!pageData.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="10"
                        class="text-center py-5 text-muted">
                        No products found.
                    </td>
                </tr>
            `;
        } else {

            pageData.forEach((product, index) => {

                const tr = document.createElement("tr");

                tr.innerHTML = `
                    <td class="text-center">
                        ${start + index + 1}
                    </td>

                    <td>
                        <div class="pr-product-name">
                            ${escapeHTML(product.name)}
                        </div>
                    </td>

                    <td>
                        ${escapeHTML(product.thickness)}
                    </td>

                    <td>
                        ${escapeHTML(product.size)}
                    </td>

                    <td>
                        <div class="pr-store-name">
                            ${escapeHTML(product.store)}
                        </div>
                    </td>

                    <td>
                        ${formatNumber(product.stock)}
                    </td>

                    <td>
                        <span class="pr-badge ${product.enabled ? "" : "disabled"}">
                            ${product.enabled ? "Enabled" : "Disabled"}
                        </span>
                    </td>

                    <td>
                        <span class="pr-badge ${product.enabled ? "" : "disabled"}">
                            ${product.enabled ? "Enabled" : "Disabled"}
                        </span>
                    </td>

                    <td>
                        <span class="pr-badge ${product.featured ? "featured" : ""}">
                            ${product.featured ? "Featured" : "Regular"}
                        </span>
                    </td>

                    <td>
                        <div class="pr-actions">

                            <button class="pr-action"
                                    data-action="view"
                                    data-id="${product.id}"
                                    title="View">
                                <i class="bi bi-eye"></i>
                            </button>

                            <a class="pr-action"
                               href="add-product.php?id=${encodeURIComponent(product.id)}"
                               title="Edit">
                                <i class="bi bi-pencil-fill"></i>
                            </a>

                            <button class="pr-action delete"
                                    data-action="delete"
                                    data-id="${product.id}"
                                    title="Delete">
                                <i class="bi bi-trash3-fill"></i>
                            </button>

                            <div class="dropdown">
                                <button class="pr-action more"
                                        data-bs-toggle="dropdown"
                                        title="More Actions">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item"
                                                data-action="toggle"
                                                data-id="${product.id}">
                                            ${product.enabled ? "Disable" : "Enable"}
                                        </button>
                                    </li>

                                    <li>
                                        <button class="dropdown-item"
                                                data-action="feature"
                                                data-id="${product.id}">
                                            ${product.featured ? "Remove Featured" : "Make Featured"}
                                        </button>
                                    </li>
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

    // View product.
    function viewProduct(id) {

        const product = products.find(
            item => Number(item.id) === id
        );

        if (!product) return;

        const stock = Number(product.stock || 0);
        const low = Number(product.low_stock_alert || 0);
        const money = v => "Rs " + Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });

        App.details({
            title: "Product Details",
            icon: "box-seam",
            name: product.name,
            subtitle: [product.thickness, product.size].filter(Boolean).join(" · ") || product.category || "",
            badge: !product.enabled
                ? { text: "Disabled", tone: "red" }
                : stock <= 0
                    ? { text: "Out of Stock", tone: "red" }
                    : stock <= low
                        ? { text: "Low Stock", tone: "orange" }
                        : { text: "Enabled", tone: "green" },
            rows: [
                ["Product Name", product.name],
                ["Category", product.category || "None"],
                ["Store", product.store || "None"],
                ["Thickness", product.thickness],
                ["Size", product.size],
                ["HSN Code", product.hsn],
                ["Price", money(product.price)],
                ["GST", (product.tax_rate || 0) + "%"],
                ["Stock", stock + (product.measurement_unit ? " " + product.measurement_unit : "")],
                ["Featured", product.featured ? "Yes" : "No"],
                ["Enabled", product.enabled ? "Yes" : "No"]
            ],
            actions: [
                { label: "Edit Product", icon: "pencil-fill", tone: "primary", href: "add-product.php?id=" + encodeURIComponent(product.id) },
                { label: "Delete Product", icon: "trash3", tone: "danger", onClick: () => deleteProduct(id) }
            ]
        });
    }

    async function deleteProduct(id) {
        if (!confirm("Are you sure you want to delete this product?")) return;
        try {
            products = (await App.api("products.delete", { id })).products;
            renderProducts();
            App.toast("Product deleted.");
        } catch (error) {
            App.toast(error.message, "error");
        }
    }

    // Action buttons.
    $("productTableBody").addEventListener("click", async function(event) {

        const button = event.target.closest("[data-action]");
        if (!button) return;

        const id = Number(button.dataset.id);
        const action = button.dataset.action;

        const index = products.findIndex(
            product => Number(product.id) === id
        );

        if (index === -1) return;

        if (action === "view") {
            viewProduct(id);
        }

        if (action === "delete") deleteProduct(id);

        try {
            if (action === "toggle") {
                products = (await App.api("products.toggle", { id })).products;
                renderProducts();
            }

            if (action === "feature") {
                products = (await App.api("products.feature", { id })).products;
                renderProducts();
            }
        } catch (error) {
            App.toast(error.message, "error");
        }
    });

    // Product tabs.
    document.querySelectorAll(".products-section .pr-tab")
        .forEach(tab => {

            tab.addEventListener("click", function() {

                document.querySelectorAll(
                    ".products-section .pr-tab"
                ).forEach(t => t.classList.remove("active"));

                this.classList.add("active");

                activeTab = this.dataset.tab;
                currentPage = 1;

                renderProducts();
            });
        });

    // Search.
    $("productSearch").addEventListener("input", function() {
        currentPage = 1;
        renderProducts();
    });

    // Filters.
    $("filterStatus").addEventListener("change", () => {
        currentPage = 1;
        renderProducts();
    });

    $("filterFeatured").addEventListener("change", () => {
        currentPage = 1;
        renderProducts();
    });

    // Reset filters.
    $("resetFilters").addEventListener("click", () => {

        $("productSearch").value = "";
        $("filterStatus").value = "all";
        $("filterFeatured").value = "all";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(
            ".products-section .pr-tab"
        ).forEach(tab => {
            tab.classList.toggle(
                "active",
                tab.dataset.tab === "all"
            );
        });

        renderProducts();
    });

    // Refresh display.
    $("refreshProducts").addEventListener(
        "click",
        async () => {
            try {
                products = (await App.api("products.list")).products;
                renderProducts();
            } catch (error) {
                App.toast(error.message, "error");
            }
        }
    );

    // Page size.
    $("pageSize").addEventListener("change", function() {
        perPage = Number(this.value);
        currentPage = 1;
        renderProducts();
    });

    // Pagination.
    $("firstPage").addEventListener("click", () => {
        currentPage = 1;
        renderProducts();
    });

    $("prevPage").addEventListener("click", () => {
        currentPage--;
        renderProducts();
    });

    $("nextPage").addEventListener("click", () => {
        currentPage++;
        renderProducts();
    });

    $("lastPage").addEventListener("click", () => {
        currentPage = Math.max(
            1,
            Math.ceil(getFilteredProducts().length / perPage)
        );
        renderProducts();
    });

    // Initial render.
    renderProducts();

})();
</script>










<?php include 'footer.php'; ?>