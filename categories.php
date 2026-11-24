<?php $pageTitle = 'Categories'; include 'header.php'; ?>





<!-- CATEGORIES MAIN SECTION -->
<section class="catogories-section">

<div class="container-fluid p-0">

    <!-- PAGE HEADER -->
    <div class="row align-items-center g-3 mb-4">
        <div class="col-md-8">
            <h1 class="cat-title">Categories</h1>
            <p class="cat-subtitle">
                Manage category groups for the product catalog
            </p>
        </div>

        <div class="col-md-4 text-md-end">
            <!-- <button class="cat-add-btn"
                    id="addCategoryBtn"
                    data-bs-toggle="modal"
                    data-bs-target="#categoryModal">
                <i class="bi bi-plus-lg me-2"></i>
                Add Category
            </button> -->

            <a href="add-categories.php"
   class="cat-add-btn text-decoration-none d-inline-block">
    <i class="bi bi-plus-lg me-2"></i>
    Add Category
</a>
        </div>
    </div>

    <!-- STATISTICS -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-5 g-3 mb-3">

        <!-- TOTAL CATEGORIES -->
        <div class="col">
            <div class="cat-stat-card">
                <div class="cat-stat-icon cat-purple">
                    <i class="bi bi-box"></i>
                </div>
                <div>
                    <div class="cat-stat-label">Total Categories</div>
                    <div class="cat-stat-value" id="totalCategories">2</div>
                    <div class="cat-stat-note cat-text-green">
                        All categories
                    </div>
                </div>
            </div>
        </div>

        <!-- ENABLED -->
        <div class="col">
            <div class="cat-stat-card">
                <div class="cat-stat-icon cat-green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div>
                    <div class="cat-stat-label">Enabled</div>
                    <div class="cat-stat-value" id="enabledCount">2</div>
                    <div class="cat-stat-note">
                        Visible in catalog
                    </div>
                </div>
            </div>
        </div>

        <!-- FEATURED -->
        <div class="col">
            <div class="cat-stat-card">
                <div class="cat-stat-icon cat-orange">
                    <i class="bi bi-star-fill"></i>
                </div>
                <div>
                    <div class="cat-stat-label">Featured</div>
                    <div class="cat-stat-value" id="featuredCount">0</div>
                    <div class="cat-stat-note">
                        Highlighted categories
                    </div>
                </div>
            </div>
        </div>

        <!-- WITH IMAGE -->
        <div class="col">
            <div class="cat-stat-card">
                <div class="cat-stat-icon cat-blue">
                    <i class="bi bi-image"></i>
                </div>
                <div>
                    <div class="cat-stat-label">With Image</div>
                    <div class="cat-stat-value" id="withImageCount">1</div>
                    <div class="cat-stat-note">
                        Has category image
                    </div>
                </div>
            </div>
        </div>

        <!-- NO IMAGE -->
        <div class="col">
            <div class="cat-stat-card">
                <div class="cat-stat-icon cat-red">
                    <i class="bi bi-image"></i>
                </div>
                <div>
                    <div class="cat-stat-label">No Image</div>
                    <div class="cat-stat-value" id="noImageCount">1</div>
                    <div class="cat-stat-note cat-text-red">
                        Missing category image
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- CATEGORY LIST PANEL -->
    <div class="cat-panel">

        <!-- CATEGORY TABS -->
        <div class="cat-tabs">
            <button class="cat-tab active" data-tab="all">
                All Categories (<span id="allTabCount">2</span>)
            </button>

            <button class="cat-tab" data-tab="featured">
                Featured (<span id="featuredTabCount">0</span>)
            </button>

            <button class="cat-tab" data-tab="enabled">
                Enabled (<span id="enabledTabCount">2</span>)
            </button>

            <button class="cat-tab" data-tab="disabled">
                Disabled (<span id="disabledTabCount">0</span>)
            </button>
        </div>

        <!-- SEARCH + FILTER -->
        <div class="cat-toolbar">

            <div class="cat-search">
                <i class="bi bi-search"></i>
                <input type="search"
                       id="categorySearch"
                       placeholder="Search by category name or code...">
            </div>

            <div class="dropdown">
                <button class="cat-tool-btn dropdown-toggle"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">
                    <i class="bi bi-funnel-fill me-2"></i>
                    Filter
                </button>

                <div class="dropdown-menu dropdown-menu-end p-3"
                     style="min-width:240px">

                    <label class="form-label fw-bold">Status</label>
                    <select class="form-select mb-3" id="filterStatus">
                        <option value="all">All Status</option>
                        <option value="enabled">Enabled</option>
                        <option value="disabled">Disabled</option>
                    </select>

                    <label class="form-label fw-bold">
                        Featured
                    </label>
                    <select class="form-select mb-3" id="filterFeatured">
                        <option value="all">All</option>
                        <option value="yes">Featured</option>
                        <option value="no">Regular</option>
                    </select>

                    <button class="btn btn-outline-secondary w-100"
                            id="resetFilters">
                        Reset Filters
                    </button>
                </div>
            </div>

            <button class="cat-tool-btn"
                    id="refreshCategories"
                    title="Refresh Categories">
                <i class="bi bi-sliders2"></i>
            </button>

        </div>

        <!-- CATEGORY TABLE -->
        <div class="table-responsive">

            <table class="table cat-table">
                <thead>
                    <tr>
                        <th class="text-center">S No</th>
                        <th>Category Details</th>
                        <th>Sort ID</th>
                        <th>Status</th>
                        <th>Enabled</th>
                        <th>Featured</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>

                <tbody id="categoryTableBody"></tbody>
            </table>

        </div>

        <!-- PAGINATION -->
        <div class="cat-footer">
            <span id="paginationInfo">
                Showing 1 to 2 of 2 entries
            </span>

            <div class="cat-pagination">
                <button class="cat-page-btn" id="firstPage">
                    &lt;&lt;
                </button>

                <button class="cat-page-btn" id="prevPage">
                    &lt;
                </button>

                <button class="cat-page-btn active" id="currentPage">
                    1
                </button>

                <button class="cat-page-btn" id="nextPage">
                    &gt;
                </button>

                <button class="cat-page-btn" id="lastPage">
                    &gt;&gt;
                </button>

                <select class="cat-page-select" id="pageSize">
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

<!-- ADD / EDIT CATEGORY MODAL -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">
                    Add Category
                </h5>
                <button class="btn-close"
                        data-bs-dismiss="modal"></button>
            </div>

            <form id="categoryForm">
                <div class="modal-body">

                    <input type="hidden" id="editCategoryId">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Category Name *
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="categoryName"
                                   required maxlength="150">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Category Code
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="categoryCode">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="categoryParent">
                                Parent Category
                            </label>
                            <select class="form-select" id="categoryParent">
                                <option value="">None (main category)</option>
                            </select>
                            <small class="text-muted">
                                Choose a parent to make this a sub-category (e.g. Plywood).
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="categoryColor">
                                Color
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color"
                                       class="form-control form-control-color"
                                       id="categoryColor"
                                       value="#ffffff"
                                       title="Choose category color">
                                <input type="text"
                                       class="form-control"
                                       id="categoryColorText"
                                       maxlength="7"
                                       placeholder="#ffffff"
                                       style="max-width:130px">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Sort ID
                            </label>
                            <input type="number"
                                   class="form-control"
                                   id="categorySort"
                                   min="0" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Category Image
                            </label>
                            <input type="file"
                                   class="form-control"
                                   id="categoryImage"
                                   accept="image/*">
                            <small class="text-muted">
                                Demo records image presence only.
                            </small>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox"
                                       class="form-check-input"
                                       id="categoryEnabled" checked>
                                <label class="form-check-label"
                                       for="categoryEnabled">
                                    Enabled
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check mt-3">
                                <input type="checkbox"
                                       class="form-check-input"
                                       id="categoryFeatured">
                                <label class="form-check-label"
                                       for="categoryFeatured">
                                    Featured Category
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary">
                        Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW CATEGORY MODAL -->
<div class="modal fade" id="viewCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    Category Details
                </h5>
                <button class="btn-close"
                        data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="viewCategoryContent"></div>

        </div>
    </div>
</div>

<script>
(function () {
    "use strict";

    // Categories are loaded from the MySQL database (api.php).
    let categories = [];

    let activeTab = "all";
    let currentPage = 1;
    let perPage = 20;

    async function loadCategories() {
        try {
            categories = (await App.api("categories.list")).categories;
            renderCategories();
        } catch (error) {
            App.toast(error.message, "error");
        }
    }

    const $ = id => document.getElementById(id);

    const categoryModal = new bootstrap.Modal(
        $("categoryModal")
    );

    const viewModal = new bootstrap.Modal(
        $("viewCategoryModal")
    );

    function escapeHTML(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    // Update statistics
    function updateStats() {

        const total = categories.length;

        const enabled = categories.filter(
            c => c.enabled
        ).length;

        const featured = categories.filter(
            c => c.featured
        ).length;

        const withImage = categories.filter(
            c => c.hasImage
        ).length;

        $("totalCategories").textContent = total;
        $("enabledCount").textContent = enabled;
        $("featuredCount").textContent = featured;
        $("withImageCount").textContent = withImage;
        $("noImageCount").textContent = total - withImage;

        $("allTabCount").textContent = total;
        $("featuredTabCount").textContent = featured;
        $("enabledTabCount").textContent = enabled;
        $("disabledTabCount").textContent = total - enabled;
    }

    // Filter categories
    function filteredCategories() {

        const search = $("categorySearch")
            .value.toLowerCase().trim();

        const status = $("filterStatus").value;
        const featured = $("filterFeatured").value;

        return categories.filter(category => {

            if (activeTab === "featured" && !category.featured)
                return false;

            if (activeTab === "enabled" && !category.enabled)
                return false;

            if (activeTab === "disabled" && category.enabled)
                return false;

            if (status === "enabled" && !category.enabled)
                return false;

            if (status === "disabled" && category.enabled)
                return false;

            if (featured === "yes" && !category.featured)
                return false;

            if (featured === "no" && category.featured)
                return false;

            const text = (
                category.name + " " + category.code + " " + (category.label || "")
            ).toLowerCase();

            return text.includes(search);
        });
    }

    function searchActive() {
        return $("categorySearch").value.trim() !== "";
    }

    // Parent dropdown: every category except the one being edited and its own sub-categories.
    function fillParentOptions(editId, selectedParentId) {
        const blocked = new Set();
        if (editId) {
            blocked.add(editId);
            let added = true;
            while (added) {
                added = false;
                categories.forEach(c => {
                    if (c.parentId && blocked.has(c.parentId) && !blocked.has(c.id)) {
                        blocked.add(c.id);
                        added = true;
                    }
                });
            }
        }
        $("categoryParent").innerHTML =
            '<option value="">None (main category)</option>' +
            categories
                .filter(c => !blocked.has(c.id))
                .map(c => `<option value="${c.id}">${"&nbsp;&nbsp;&nbsp;".repeat(c.depth || 0)}${escapeHTML(c.name)}</option>`)
                .join("");
        $("categoryParent").value = selectedParentId ? String(selectedParentId) : "";
    }

    function setColor(value) {
        const color = /^#[0-9a-f]{6}$/i.test(value || "") ? value : "#ffffff";
        $("categoryColor").value = color;
        $("categoryColorText").value = value ? color : "";
    }

    $("categoryColor").addEventListener("input", function () {
        $("categoryColorText").value = this.value;
    });
    $("categoryColorText").addEventListener("input", function () {
        if (/^#[0-9a-f]{6}$/i.test(this.value)) $("categoryColor").value = this.value;
    });

    // Open the modal to add a sub-category under a parent.
    // Add many sub-categories under this parent at once.
    function addSubCategory(parentId) {
        window.location.href = "add-categories.php?parent=" + encodeURIComponent(parentId);
    }

    function addSubCategoryPopup(parentId) {
        const parent = categories.find(c => c.id === parentId);
        $("categoryForm").reset();
        $("editCategoryId").value = "";
        $("categoryEnabled").checked = true;
        $("categorySort").value = categories.filter(c => c.parentId === parentId).length;
        $("categoryImage").value = "";
        fillParentOptions(0, parentId);
        setColor(parent ? parent.colorCode : "");
        $("categoryModalTitle").textContent = "Add Sub-category" + (parent ? " under " + parent.name : "");
        categoryModal.show();
        setTimeout(() => $("categoryName").focus(), 300);
    }

    // Render table
    function renderCategories() {

        updateStats();

        const filtered = filteredCategories();

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

        const tbody = $("categoryTableBody");

        tbody.innerHTML = "";

        if (pageData.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7"
                        class="text-center py-5 text-muted">
                        No categories found.
                    </td>
                </tr>
            `;
        } else {

            pageData.forEach((category, index) => {

                const tr = document.createElement("tr");

                tr.innerHTML = `
                    <td class="text-center">
                        ${start + index + 1}
                    </td>

                    <td>
                        <div class="d-flex align-items-center gap-2"
                             style="padding-left:${(category.depth || 0) * 26}px">
                            ${category.depth ? '<i class="bi bi-arrow-return-right text-muted"></i>' : ""}
                            <span title="${escapeHTML(category.colorCode || "")}"
                                  style="display:inline-block;width:14px;height:14px;border-radius:4px;flex-shrink:0;border:1px solid rgba(0,0,0,.2);background:${/^#[0-9a-f]{3,8}$/i.test(category.colorCode || "") ? category.colorCode : "transparent"}"></span>
                            <div>
                                <strong>${escapeHTML(category.name)}</strong>
                                ${category.children ? `<div class="small text-muted">${category.children} sub-categor${category.children === 1 ? "y" : "ies"}</div>` : ""}
                                ${category.depth && searchActive() ? `<div class="small text-muted">${escapeHTML(category.label)}</div>` : ""}
                            </div>
                        </div>
                    </td>

                    <td>${escapeHTML(category.sort)}</td>

                    <td>
                        ${category.enabled ? "Active" : "Inactive"}
                    </td>

                    <td>
                        <span class="cat-badge ${category.enabled ? "" : "disabled"}">
                            ${category.enabled ? "Enabled" : "Disabled"}
                        </span>
                    </td>

                    <td>
                        <span class="cat-badge ${category.featured ? "featured" : ""}">
                            ${category.featured ? "Featured" : "Regular"}
                        </span>
                    </td>

                    <td>
                        <div class="cat-actions">

                            <button class="cat-action-btn"
                                    data-action="view"
                                    data-id="${category.id}"
                                    title="View">
                                <i class="bi bi-eye"></i>
                            </button>

                            <button class="cat-action-btn"
                                    data-action="addchild"
                                    data-id="${category.id}"
                                    title="Add Sub-category">
                                <i class="bi bi-node-plus-fill"></i>
                            </button>

                            <button class="cat-action-btn"
                                    data-action="edit"
                                    data-id="${category.id}"
                                    title="Edit">
                                <i class="bi bi-pencil-fill"></i>
                            </button>

                            <button class="cat-action-btn delete"
                                    data-action="delete"
                                    data-id="${category.id}"
                                    title="Delete">
                                <i class="bi bi-trash3-fill"></i>
                            </button>

                            <div class="dropdown">
                                <button class="cat-action-btn more"
                                        data-bs-toggle="dropdown"
                                        aria-label="More actions">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item"
                                                data-action="toggle"
                                                data-id="${category.id}">
                                            ${category.enabled ? "Disable" : "Enable"}
                                        </button>
                                    </li>

                                    <li>
                                        <button class="dropdown-item"
                                                data-action="feature"
                                                data-id="${category.id}">
                                            ${category.featured ? "Remove Featured" : "Make Featured"}
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

    // Add new category
    // (The modal "Add" button is commented out; the page links to add-categories.php.)
    if ($("addCategoryBtn")) {
        $("addCategoryBtn").addEventListener("click", () => {

            $("categoryForm").reset();
            $("editCategoryId").value = "";
            $("categoryEnabled").checked = true;
            $("categorySort").value = categories.length;

            $("categoryModalTitle").textContent = "Add Category";
        });
    }

    // Save category
    $("categoryForm").addEventListener("submit", async function(e) {

        e.preventDefault();

        if (!this.reportValidity()) return;

        const editId = Number($("editCategoryId").value);

        const categoryData = {
            id: editId || 0,
            name: $("categoryName").value.trim(),
            code: $("categoryCode").value.trim(),
            sort: Number($("categorySort").value),
            parentId: $("categoryParent").value ? Number($("categoryParent").value) : null,
            colorCode: $("categoryColorText").value.trim() ? $("categoryColor").value : "",
            enabled: $("categoryEnabled").checked,
            featured: $("categoryFeatured").checked
        };

        if (!categoryData.name) {
            $("categoryName").focus();
            return;
        }

        try {
            const imageFile = $("categoryImage").files[0];

            if (imageFile) {
                categoryData.imageUrl = (await App.upload(imageFile)).url;
            } else if (editId) {
                const existing = categories.find(c => c.id === editId);
                categoryData.imageUrl = existing ? (existing.imageUrl || "") : "";
            }

            categories = (await App.api("categories.save", categoryData)).categories;
            categoryModal.hide();
            renderCategories();
            App.toast(editId ? "Category updated." : "Category added.");
        } catch (error) {
            App.toast(error.message, "error");
        }
    });

    // View category
    function viewCategory(id) {

        const c = categories.find(c => c.id === id);
        if (!c) return;

        const parent = c.parentId ? categories.find(p => p.id === c.parentId) : null;

        App.details({
            title: "Category Details",
            icon: "folder2-open",
            image: c.imageUrl || "",
            name: c.name,
            subtitle: c.code || c.name,
            badge: c.enabled ? { text: "Enabled", tone: "green" } : { text: "Disabled", tone: "red" },
            rows: [
                ["Category Name", c.name],
                ["Parent Category", parent ? parent.label || parent.name : "None"],
                ["Sub-categories", c.children ? String(c.children) : "None"],
                ["Color", c.colorCode || "None"],
                ["Code", c.code || "None"],
                ["Sort ID", c.sort],
                ["Featured", c.featured ? "Yes" : "No"],
                ["Enabled", c.enabled ? "Yes" : "No"]
            ],
            actions: [
                { label: "Add Sub-category", icon: "node-plus-fill", tone: "neutral", onClick: () => addSubCategory(id) },
                { label: "Edit Category", icon: "pencil-fill", tone: "primary", onClick: () => editCategory(id) },
                { label: "Delete Category", icon: "trash3", tone: "danger", onClick: () => deleteCategory(id) }
            ]
        });
    }

    async function deleteCategory(id) {
        if (!confirm("Are you sure you want to delete this category?")) return;
        try {
            categories = (await App.api("categories.delete", { id })).categories;
            renderCategories();
            App.toast("Category deleted.");
        } catch (error) {
            App.toast(error.message, "error");
        }
    }

    // Edit category
    function editCategory(id) {

        const c = categories.find(c => c.id === id);
        if (!c) return;

        $("editCategoryId").value = c.id;
        $("categoryName").value = c.name;
        $("categoryCode").value = c.code;
        $("categorySort").value = c.sort;
        $("categoryEnabled").checked = c.enabled;
        $("categoryFeatured").checked = c.featured;
        $("categoryImage").value = "";
        fillParentOptions(c.id, c.parentId);
        setColor(c.colorCode || "");

        $("categoryModalTitle").textContent = "Edit Category";

        categoryModal.show();
    }

    // Table action handling
    $("categoryTableBody").addEventListener("click", async function(e) {

        const button = e.target.closest("[data-action]");
        if (!button) return;

        const id = Number(button.dataset.id);
        const action = button.dataset.action;

        const c = categories.find(c => c.id === id);
        if (!c) return;

        if (action === "view") viewCategory(id);

        if (action === "edit") editCategory(id);

        if (action === "addchild") addSubCategory(id);

        try {
            if (action === "delete") {
                if (confirm("Are you sure you want to delete this category?")) {
                    categories = (await App.api("categories.delete", { id })).categories;
                    renderCategories();
                    App.toast("Category deleted.");
                }
            }

            if (action === "toggle") {
                categories = (await App.api("categories.toggle", { id })).categories;
                renderCategories();
            }

            if (action === "feature") {
                categories = (await App.api("categories.feature", { id })).categories;
                renderCategories();
            }
        } catch (error) {
            App.toast(error.message, "error");
        }
    });

    // Tabs
    document.querySelectorAll(".catogories-section .cat-tab")
        .forEach(tab => {

            tab.addEventListener("click", function() {

                document.querySelectorAll(
                    ".catogories-section .cat-tab"
                ).forEach(t => t.classList.remove("active"));

                this.classList.add("active");

                activeTab = this.dataset.tab;
                currentPage = 1;

                renderCategories();
            });
        });

    // Search
    $("categorySearch").addEventListener("input", () => {
        currentPage = 1;
        renderCategories();
    });

    // Filters
    $("filterStatus").addEventListener("change", () => {
        currentPage = 1;
        renderCategories();
    });

    $("filterFeatured").addEventListener("change", () => {
        currentPage = 1;
        renderCategories();
    });

    // Reset filters
    $("resetFilters").addEventListener("click", () => {

        $("filterStatus").value = "all";
        $("filterFeatured").value = "all";
        $("categorySearch").value = "";

        activeTab = "all";
        currentPage = 1;

        document.querySelectorAll(
            ".catogories-section .cat-tab"
        ).forEach(tab => {
            tab.classList.toggle(
                "active",
                tab.dataset.tab === "all"
            );
        });

        renderCategories();
    });

    // Refresh table
    $("refreshCategories").addEventListener(
        "click",
        loadCategories
    );

    // Page size
    $("pageSize").addEventListener("change", function() {
        perPage = Number(this.value);
        currentPage = 1;
        renderCategories();
    });

    // Pagination
    $("firstPage").addEventListener("click", () => {
        currentPage = 1;
        renderCategories();
    });

    $("prevPage").addEventListener("click", () => {
        currentPage--;
        renderCategories();
    });

    $("nextPage").addEventListener("click", () => {
        currentPage++;
        renderCategories();
    });

    $("lastPage").addEventListener("click", () => {
        currentPage = Math.max(
            1,
            Math.ceil(filteredCategories().length / perPage)
        );
        renderCategories();
    });

    // Initial render
    renderCategories();
    loadCategories();

})();
</script>






<?php include 'footer.php'; ?>